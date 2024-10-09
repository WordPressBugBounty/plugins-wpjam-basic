<?php
// register
function wpjam_register($group, $name, $args=[]){
	if($group && $name){
		return WPJAM_Register::call_group('add_object_by_'.$group, $name, $args);
	}
}

function wpjam_unregister($group, $name){
	if($group && $name){
		WPJAM_Register::call_group('remove_object_by_'.$group, $name);
	}
}

function wpjam_get_registered_object($group, $name, $register=false){
	if($group && $name){
		$object	= WPJAM_Register::call_group('get_object_by_'.$group, $name);

		return (!$object && $register) ? wpjam_register($group, $name) : $object;
	}
}

function wpjam_get_registereds($group){
	return $group ? WPJAM_Register::call_group('get_objects_by_'.$group) : [];
}

function wpjam_args($args=[]){
	return new WPJAM_Args($args);
}

// Items
function wpjam_get_items($name, $field=''){
	return wpjam_get_registered_object('items', $name, true)->get_items($field);
}

function wpjam_update_items($name, $value, $field=''){
	return wpjam_get_registered_object('items', $name, true)->update_items($value, $field);
}

function wpjam_get_item($name, $key, $field=''){
	return wpjam_get_registered_object('items', $name, true)->get_item($key, $field);
}

function wpjam_add_item($name, $key, ...$args){
	$object	= wpjam_get_registered_object('items', $name, true);
	$result	= $object->add_item($key, ...$args);

	return (!$args || !$object->is_keyable($key)) ? $key : ($args[0] ?? null);
}

function wpjam_set_item($name, $key, $item, $field=''){
	wpjam_get_registered_object('items', $name, true)->set_item($key, $item, $field);

	return $item;
}

function wpjam_get_instance($name, $key, $cb=null){
	return wpjam_get_item('instance', $key, $name) ?: ($cb ? wpjam_add_instance($name, $key, $cb($key)) : null);
}

function wpjam_add_instance($name, $key, $object){
	return is_wp_error($object) ? $object : wpjam_add_item('instance', $key, $object, $name);
}

// Handler
function wpjam_get_handler($name, $args=null){
	return WPJAM_Handler::get($name, $args);
}

function wpjam_call_handler($name, $method, ...$args){
	return WPJAM_Handler::call($name, $method, ...$args);
}

function wpjam_register_handler(...$args){
	return WPJAM_Handler::create(...$args);
}

// Platform & path
function wpjam_register_platform($name, $args){
	return WPJAM_Platform::register($name, $args);
}

// wpjam_get_current_platform(['weapp', 'template'], $ouput);	// 从一组中（空则全部）根据顺序获取
// wpjam_get_current_platform(['path'=>true], $ouput);			// 从已注册路径的根据优先级获取
function wpjam_get_current_platform($args=[], $output='name'){
	return WPJAM_Platform::get_current($args, $output);
}

// 获取已经注册路径的平台
function wpjam_get_current_platforms($output='names'){
	$objects	= WPJAM_Platform::get_by(['path'=>true]);

	return $output == 'names' ? array_keys($objects) : $objects;
}

function wpjam_add_platform_dynamic_method($method, Closure $closure){
	return WPJAM_Platform::add_dynamic_method($method, $closure);
}

function wpjam_get_platform_options($output='bit'){
	return WPJAM_Platform::get_options($output);
}

function wpjam_get_path($platform, $page_key, $args=[]){
	$object	= WPJAM_Platform::get($platform);

	return $object ? $object->get_path(...(is_array($page_key) ? [wpjam_pull($page_key, 'page_key'), $page_key] : [$page_key, $args])) : '';
}

function wpjam_get_tabbar($platform, $page_key=''){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	if($page_key){
		return $object->get_tabbar($page_key);
	}

	return wpjam_array($object->get_items(), fn($k, $v)=> ($v = $object->get_tabbar($k)) ? [$k, $v] : null);
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	$items	= $object->get_items();

	if(is_string($args) && in_array($args, ['with_page', 'page'])){
		return wpjam_array($items, fn($pk)=> ($page = $object->get_page($pk)) ? [null, ['page'=>$page, 'page_key'=>$pk]] : null);
	}

	return array_keys(is_array($args) ? wp_list_filter($items, $args, $operator) : $items);
}

function wpjam_register_path($name, ...$args){
	return WPJAM_Path::create($name, ...$args);
}

function wpjam_unregister_path($name, $platform=''){
	return WPJAM_Path::remove($name, $platform);
}

function wpjam_get_path_fields($platforms=null, $args=[]){
	$object	= WPJAM_Platforms::get_instance($platforms);

	if(!$object){
		return [];
	}

	[$for, $args]	= is_array($args) ? [wpjam_pull($args, 'for'), $args] : [$args, []];

	return $object->get_fields($args, $for == 'qrcode');
}

function wpjam_parse_path_item($item, $platform=null, $postfix=''){
	$object	= WPJAM_Platforms::get_instance($platform);

	return $object ? $object->parse_item($item, $postfix) : ['type'=>'none'];
}

function wpjam_validate_path_item($item, $platforms, $postfix='', $title=''){
	$object	= WPJAM_Platforms::get_instance($platforms);

	return $object ? $object->validate_item($item, $postfix, $title) : true;
}

function wpjam_get_path_item_link_tag($parsed, $text){
	if($parsed['type'] == 'none'){
		return $text;
	}elseif($parsed['type'] == 'external'){
		return '<a href_type="web_view" href="'.$parsed['url'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'web_view'){
		return '<a href_type="web_view" href="'.$parsed['src'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'mini_program'){
		return '<a href_type="mini_program" href="'.$parsed['path'].'" appid="'.$parsed['appid'].'">'.$text.'</a>';
	}elseif($parsed['type'] == 'contact'){
		return '<a href_type="contact" href="" tips="'.$parsed['tips'].'">'.$text.'</a>';
	}elseif($parsed['type'] == ''){
		return '<a href_type="path" page_key="'.$parsed['page_key'].'" href="'.$parsed['path'].'">'.$text.'</a>';
	}
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type_object($name, $args=[]){
	return WPJAM_Data_Type::get_instance($name, $args);
}

function wpjam_strip_data_type($args){
	return array_diff_key($args, wpjam_parse_data_type($args));
}

function wpjam_parse_data_type($args){
	$data_type	= wpjam_get($args, 'data_type');

	return $data_type ? [
		'data_type'	=> $data_type,
		$data_type	=> (wpjam_get($args, $data_type) ?: '')
	] : [];
}

function wpjam_get_post_id_field($post_type='post', $args=[]){
	return WPJAM_Post::get_field(['post_type'=> $post_type]+$args);
}

// Setting
function wpjam_setting($type, $option, $blog_id=0){
	return WPJAM_Setting::get_instance($type, $option, $blog_id);
}

function wpjam_get_setting($option, $name, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->get_setting($name);
}

function wpjam_update_setting($option, $name, $value='', $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->update_setting($name, $value);
}

function wpjam_delete_setting($option, $name, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->delete_setting($name);
}

function wpjam_get_option($option, $blog_id=0, ...$args){
	return wpjam_setting('option', $option, $blog_id)->get_option(...$args);
}

function wpjam_update_option($option, $value, $blog_id=0){
	return wpjam_setting('option', $option, $blog_id)->update_option($value);
}

function wpjam_get_site_setting($option, $name){
	return wpjam_setting('site_option', $option)->get_setting($name);
}

function wpjam_get_site_option($option, $default=[]){
	return wpjam_setting('site_option', $option)->get_option($default);
}

function wpjam_update_site_option($option, $value){
	return wpjam_setting('site_option', $option)->update_option($value);
}

// Option
function wpjam_register_option($name, $args=[]){
	return WPJAM_Option_Setting::create($name, $args);
}

function wpjam_get_option_object($name, $by=''){
	return WPJAM_Option_Setting::get($name, $by);
}

function wpjam_add_option_section($option_name, ...$args){
	return wpjam_get_option_object($option_name)->add_section(...$args);
}

// Meta Type
function wpjam_register_meta_type($name, $args=[]){
	return WPJAM_Meta_Type::register($name, $args);
}

function wpjam_get_meta_type_object($name){
	return WPJAM_Meta_Type::get($name);
}

function wpjam_register_meta_option($meta_type, $name, $args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->register_option($name, $args) : null;
}

function wpjam_unregister_meta_option($meta_type, $name){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->unregister_option($name) : null;
}

function wpjam_get_meta_options($meta_type, $args=[]){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_options($args) : [];
}

function wpjam_get_meta_option($meta_type, $name, $return='object'){
	$object	= WPJAM_Meta_Type::get($meta_type);
	$option	= $object ? $object->get_option($name) : null;

	return $return == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($meta_type, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_by_key(...$args) : [];
}

function wpjam_get_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_data_with_default($object_id, ...$args) : null;
}

function wpjam_update_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->update_data_with_default($object_id, ...$args) : null;
}

function wpjam_delete_metadata($meta_type, $object_id, $key){
	$object	= WPJAM_Meta_Type::get($meta_type);

	if($object && $key){
		array_map(fn($k)=> $object->delete_data($object_id, $k), (array)$key);
	}

	return true;
}

// LazyLoader
function wpjam_register_lazyloader($name, $args){
	return WPJAM_Lazyloader::add($name, $args);
}

function wpjam_lazyload($name, $ids){
	return $name ? WPJAM_Lazyloader::queue($name, $ids) : null;
}

function wpjam_load_pending($name, $callback){
	WPJAM_Lazyloader::call_pending('load', $name, $callback);
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, $args);
}

function wpjam_get_post_type_object($name){
	if(is_numeric($name)){
		$name	= get_post_type($name);
	}

	return WPJAM_Post_Type::get($name);
}

function wpjam_add_post_type_field($post_type, ...$args){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		$fields	= is_array($args[0]) ? $args[0] : [$args[0]=>$args[1]];

		array_walk($fields, fn($v, $k)=> $object->add_item($k, $v, '_fields'));
	}
}

function wpjam_remove_post_type_field($post_type, $key){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		$object->delete_item($key, '_fields');
	}
}

function wpjam_get_post_type_setting($post_type, $key, $default=null){
	$object	= WPJAM_Post_Type::get($post_type);

	return $object && isset($object->$key) ? $object->$key : $default;
}

function wpjam_update_post_type_setting($post_type, $key, $value){
	$object	= WPJAM_Post_Type::get($post_type);

	if($object){
		$object->$key	= $value;
	}
}

if(!function_exists('get_post_type_support')){
	function get_post_type_support($post_type, $feature){
		$object	= wpjam_get_post_type_object($post_type);

		return $object ? $object->get_support($feature) : false;
	}
}

// Post Option
function wpjam_register_post_option($meta_box, $args=[]){
	return wpjam_register_meta_option('post', $meta_box, $args);
}

function wpjam_unregister_post_option($meta_box){
	wpjam_unregister_meta_option('post', $meta_box);
}

function wpjam_get_post_options($post_type='', $args=[]){
	return wpjam_get_meta_options('post', array_merge($args, ['post_type'=>$post_type]));
}

function wpjam_get_post_option($name, $return='object'){
	return wpjam_get_meta_option('post', $name, $return);
}

// Post Column
function wpjam_register_posts_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['data_type'=>'post_type']));
	}
}

// Post
function wpjam_post($post, $wp_error=false){
	return WPJAM_Post::get_instance($post, null, $wp_error);
}

function wpjam_get_post_object($post, $post_type=null){
	return WPJAM_Post::get_instance($post, $post_type);
}

function wpjam_get_post($post, $args=[]){
	$object	= wpjam_post($post);
	$args	= is_a($args, 'WPJAM_Field') ? ($args->size ? ['thumbnal_size'=>$args->size] : []) : $args;

	if($object){
		return $object->parse_for_json($args);
	}
}

function wpjam_get_posts($query, $parse=false){
	if($parse !== false){
		$args	= is_array($parse) ? $parse : [];
		$parse	= true;
	}

	if(is_string($query) || wp_is_numeric_array($query)){
		$ids	= wp_parse_id_list($query);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? array_values(array_filter(array_map(fn($p)=> wpjam_get_post($p, $args), $ids))) : $posts;
	}else{
		return $parse ? wpjam_parse_query($query, $args) : (WPJAM_Posts::query($query))->posts;
	}
}

function wpjam_get_post_views($post=null){
	$post	= get_post($post);

	return $post ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	$post	= get_post($post);

	if($post){
		$views	= wpjam_get_post_views($post);

		if(is_single() && $post->ID == get_queried_object_id()){
			static $viewd = false;

			if($viewd){	// 确保只加一次
				return $views;
			}

			$viewd	= true;
		}

		$views	+= $offset;

		update_post_meta($post->ID, 'views', $views);

		return $views;
	}

	return null;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	$post	= get_post($post);

	if($post){
		if($post->post_excerpt){
			return wp_strip_all_tags($post->post_excerpt, true);
		}elseif(!is_serialized($post->post_content)){
			$excerpt	= get_the_content('', false, $post);
			$excerpt	= strip_shortcodes($excerpt);
			$excerpt	= excerpt_remove_blocks($excerpt);
			$excerpt	= wp_strip_all_tags($excerpt, true);
			$length		= $length ?: apply_filters('excerpt_length', 200);
			$more		= $more ?? apply_filters('excerpt_more', ' &hellip;');

			return mb_strimwidth($excerpt, 0, $length, $more, 'utf-8');
		}
	}

	return '';
}

function wpjam_get_post_content($post=null, $raw=false){
	$content	= get_the_content('', false, $post);

	return $raw ? $content : str_replace(']]>', ']]&gt;', apply_filters('the_content', $content));
}

function wpjam_get_post_first_image_url($post=null, $size='full'){
	$post		= get_post($post);
	$content	= $post ? $post->post_content : '';

	if($content){
		if(preg_match('/class=[\'"].*?wp-image-([\d]*)[\'"]/i', $content, $matches)){
			return wp_get_attachment_image_url($matches[1], $size);
		}

		if(preg_match('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			return wpjam_get_thumbnail($matches[1], $size);
		}
	}

	return '';
}

function wpjam_get_post_images($post=null, $large='', $thumbnail='', $full=true){
	$object	= wpjam_post($post);

	return $object ? $object->parse_images($large, $thumbnail, $full) : [];
}

function wpjam_get_post_thumbnail_url($post=null, $size='full', $crop=1){
	$object	= wpjam_post($post);

	return $object ? $object->get_thumbnail_url($size, $crop) : '';
}

// Post Query
function wpjam_query($args=[]){
	return new WP_Query(wp_parse_args($args, ['no_found_rows'=>true, 'ignore_sticky_posts'=>true]));
}

function wpjam_parse_query($wp_query, $args=[], $parse=true){
	if(!$wp_query && !is_array($wp_query)){
		return $parse ? [] : '';
	}

	$args	= array_merge($args, ['list_query'=>true]);
	$method	= $parse ? 'parse' : 'render';

	return WPJAM_Posts::$method($wp_query, $args);
}

function wpjam_render_query($wp_query, $args=[]){
	return WPJAM_Posts::render($wp_query, $args);
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query(...$args){
	return WPJAM_Posts::get_related_query(...(count($args) <= 1 ? [get_the_ID(), ['number'=>$args[0] ?? 5]] : $args));
}

function wpjam_get_related_object_ids($tt_ids, $number, $page=1){
	return WPJAM_Posts::get_related_object_ids($tt_ids, $number, $page);
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	return wpjam_parse_query(wpjam_get_related_posts_query($post, $args), $args, $parse);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_parse_query(['posts_per_page'=>5, 'orderby'=>'date'], $args, $parse);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_parse_query(['posts_per_page'=>5, 'orderby'=>'meta_value_num', 'meta_key'=>'views'], $args, $parse);
}

// Taxonomy
function wpjam_register_taxonomy($name, ...$args){
	$args	= count($args) == 2 ? array_merge($args[1], ['object_type'=>$args[0]]) : $args[0];

	return WPJAM_Taxonomy::register($name, $args);
}

function wpjam_get_taxonomy_object($name){
	$name	= is_numeric($name) ? get_term_field('taxonomy', $id) : $name;

	return WPJAM_Taxonomy::get($name);
}

function wpjam_add_taxonomy_field($taxonomy, ...$args){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		$fields	= is_array($args[0]) ? $args[0] : [$args[0]=>$args[1]];

		array_walk($fields, fn($v, $k)=> $object->add_item($k, $v, '_fields'));
	}
}

function wpjam_remove_taxonomy_field($taxonomy, $key){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		$object->delete_item($key, '_fields');
	}
}

function wpjam_get_taxonomy_setting($taxonomy, $key, $default=null){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	return ($object && isset($object->$key)) ? $object->$key : $default;
}

function wpjam_update_taxonomy_setting($taxonomy, $key, $value){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	if($object){
		$object->$key	= $value;
	}
}

if(!function_exists('taxonomy_supports')){
	function taxonomy_supports($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->supports($feature) : false;
	}
}

if(!function_exists('add_taxonomy_support')){
	function add_taxonomy_support($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->add_support($feature) : null;
	}
}

if(!function_exists('remove_taxonomy_support')){
	function remove_taxonomy_support($taxonomy, $feature){
		$object	= WPJAM_Taxonomy::get($taxonomy);

		return $object ? $object->remove_support($feature) : null;
	}
}	

function wpjam_get_taxonomy_query_key($taxonomy){
	return ['category'=>'cat', 'post_tag'=>'tag_id'][$taxonomy] ?? $taxonomy.'_id';
}

function wpjam_get_term_id_field($taxonomy='category', $args=[]){
	return WPJAM_Term::get_field(['taxonomy'=>$taxonomy]+$args);
}

// Term Option
function wpjam_register_term_option($name, $args=[]){
	return wpjam_register_meta_option('term', $name, $args);
}

function wpjam_unregister_term_option($name){
	wpjam_unregister_meta_option('term', $name);
}

function wpjam_get_term_options($taxonomy='', $args=[]){
	return wpjam_get_meta_options('term', array_merge($args, ['taxonomy'=>$taxonomy]));
}

function wpjam_get_term_option($name, $return='object'){
	return wpjam_get_meta_option('term', $name, $return);
}

// Term Column
function wpjam_register_terms_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['data_type'=>'taxonomy']));
	}
}

// Term
function wpjam_term($term, $wp_error=false){
	return WPJAM_Term::get_instance($term, null, $wp_error);
}

function wpjam_get_term_object($term, $taxonomy=''){
	return WPJAM_Term::get_instance($term, $taxonomy);
}

function wpjam_get_term($term, $args=[]){
	[$taxonomy, $args]	= is_a($args, 'WPJAM_Field') ? [$args->taxonomy, []] : (is_array($args) ? [wpjam_pull($args, 'taxonomy'), $args] : [$args, []]);

	$object		= WPJAM_Term::get_instance($term, $taxonomy, false);

	return $object ? $object->parse_for_json($args) : null;
}

// $args, $max_depth
// $term_ids, $args
function wpjam_get_terms(...$args){
	if(is_string($args[0]) || wp_is_numeric_array($args[0])){
		$ids	= wp_parse_id_list(array_shift($args));
		$terms	= WPJAM_Term::get_by_ids($ids);
		$args	= array_shift($args) ?: [];

		[$parse, $args]	= is_bool($args) ? [$args, []] : [wpjam_pull($args, 'parse'), $args];

		return $parse ? array_map(fn($term)=> wpjam_get_term($term, $args), $terms) : $terms;
	}else{
		$args	= isset($args[1]) ? array_merge($args[0], ['max_depth'=>$args[1]]) : $args[0];
		
		return WPJAM_Terms::parse($args);
	}
}

function wpjam_get_all_terms($taxonomy){
	return get_terms([
		'suppress_filter'	=> true,
		'taxonomy'			=> $taxonomy,
		'hide_empty'		=> false,
		'orderby'			=> 'none',
		'get'				=> 'all'
	]);
}

function wpjam_get_term_thumbnail_url($term=null, $size='full', $crop=1){
	$object	= wpjam_term($term);

	return $object ? $object->get_thumbnail_url($size, $crop) : '';
}

if(!function_exists('get_term_taxonomy')){
	function get_term_taxonomy($id){
		return get_term_field('taxonomy', $id);
	}
}

// User
function wpjam_user($user, $wp_error=false){
	return WPJAM_User::get_instance($user, $wp_error);
}

function wpjam_get_user_object($user){
	return wpjam_user($user);
}

function wpjam_get_user($user, $size=96){
	$object	= wpjam_user($user);

	return $object ? $object->parse_for_json($size) : null;
}

if(!function_exists('get_user_field')){
	function get_user_field($field, $user=null, $context='display'){
		$user	= get_userdata($user);

		return ($user && isset($user->$field)) ? sanitize_user_field($field, $user->$field, $user->ID, $context) : '';
	}
}

function wpjam_get_authors($args=[]){
	return WPJAM_User::get_authors($args);
}

// Bind
function wpjam_register_bind($type, $appid, $args){
	$object	= wpjam_get_bind_object($type, $appid);

	return $object ?: WPJAM_Bind::create($type, $appid, $args);
}

function wpjam_get_bind_object($type, $appid){
	return WPJAM_Bind::get($type.':'.$appid);
}

// User Signup
function wpjam_register_user_signup($name, $args){
	return WPJAM_User_Signup::create($name, $args);
}

function wpjam_get_user_signups($args=[], $output='objects', $operator='and'){
	return WPJAM_User_Signup::get_registereds($args, $output, $operator);
}

function wpjam_get_user_signup_object($name){
	return WPJAM_User_Signup::get($name);
}

// Comment
if(!function_exists('get_comment_parent')){
	function get_comment_parent($comment_id){
		$comment	= get_comment($comment_id);

		return $comment ? $comment->comment_parent : null;
	}
}

// File
function wpjam_url($dir, $scheme=null){
	$path	= str_replace([rtrim(ABSPATH, '/'), '\\'], ['', '/'], $dir);

	return $scheme == 'relative' ? $path : site_url($path, $scheme);
}

function wpjam_dir($url){
	return ABSPATH.str_replace(site_url('/'), '', $url);
}

function wpjam_file($value, $to, $from='file'){
	if($from == 'id'){
		return wpjam_get_attachment_value($value, $to);
	}

	$dir	= wp_get_upload_dir();

	if($from == 'path'){
		$path	= $value;
	}else{
		if($from == 'url'){
			$value	= parse_url($value, PHP_URL_PATH);
			$base	= parse_url($dir['baseurl'], PHP_URL_PATH);
		}elseif($from == 'file'){
			$base	= $dir['basedir'];
		}

		$path	= wpjam_remove_prefix($value, $base, $removed);

		if(!$removed){
			return null;
		}
	}

	if($to == 'path'){
		return $path;
	}elseif($to == 'url'){
		return $dir['baseurl'].$path;
	}elseif($to == 'file'){
		return $dir['basedir'].$path;
	}elseif($to == 'size'){
		$file	= $dir['basedir'].$path;
		$size	= file_exists($file) ? wp_getimagesize($file) : [];

		if($size){
			return ['width'=>$size[0], 'height'=>$size[1]];
		}
	}

	$id	= wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id');

	return ($id && get_post_type($id) == 'attachment') ? wpjam_get_attachment_value($id, $to) : null;
}

function wpjam_get_attachment_value($id, $field='file'){
	if($id && get_post_type($id) == 'attachment'){
		if($field == 'id'){
			return $id;
		}elseif($field == 'file'){
			return get_attached_file($id);
		}elseif($field == 'url'){
			return wp_get_attachment_url($id);
		}elseif($field == 'size'){
			return wpjam_slice((wp_get_attachment_metadata($id) ?: []), ['width', 'height']);
		}
	}

	return null;
}

function wpjam_upload($name){
	require_once ABSPATH.'wp-admin/includes/file.php';

	if(is_array($name)){
		if(isset($name['bits'])){
			$bits	= $name['bits'];
			$name	= $name['name'] ?? '';

			if(preg_match('/data:image\/([^;]+);base64,(.*)/i', $bits, $matches)){
				$bits	= base64_decode(trim($matches[2]));
				$pos	= strrpos($name, '.');
				$name	= ($pos ? substr($name, 0, $pos) : $name).'.'.$matches[1];
			}

			$upload	= wp_upload_bits($name, null, $bits);
		}else{
			$upload	= wp_handle_sideload($name, ['test_form'=>false]);
		}
	}else{
		$upload	= wp_handle_upload($_FILES[$name], ['test_form'=>false]);
	}

	if(isset($upload['error']) && $upload['error'] !== false){
		return new WP_Error('upload_error', $upload['error']);
	}

	return $upload+['path'=>wpjam_file($upload['file'], 'path')];
}

function wpjam_upload_bits($bits, $name, $media=true){
	$upload	= wpjam_upload(['name'=>$name, 'bits'=>$bits]);

	return (!is_wp_error($upload) && $media) ? wpjam_add_to_media($upload, is_numeric($media) ? $media : 0) : $upload;
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	try{
		$args	= is_array($name) ? $name : compact('name', 'media', 'post_id');
		$name	= $args['name'] ?? '';
		$media	= $args['media'] ?? false;
		$field	= wpjam_get($args, 'field') ?: ($media ? 'id' : 'file');
		$id		= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

		if(!$id || get_post_type($id) != 'attachment'){
			$tmp	= wpjam_try('download_url', $url);
			$name	= $name ?: md5($url).'.'.(explode('/', wp_get_image_mime($tmp))[1]);
			$file	= ['name'=>$name, 'tmp_name'=>$tmp];

			if(!$media){
				return wpjam_try('wpjam_upload', $file)[$field];
			}

			$id	= wpjam_try('media_handle_sideload', $file, ($args['post_id'] ?? 0));

			update_post_meta($id, 'source_url', $url);
		}

		return wpjam_get_attachment_value($id, $field);
	}catch(Exception $e){
		if(isset($tmp)){
			@unlink($tmp);
		}

		return wpjam_catch($e);
	}
}

function wpjam_add_to_media($upload, $post_id=0){
	if(is_array($upload)){
		$file	= $upload['file'];
		$url	= $upload['url'];
		$type	= $upload['type'];
	}else{
		$file	= $upload;
		$url	= wpjam_file($file, 'url');
		$type	= mime_content_type($file);
	}

	if(!$file || !$url){
		return;
	}

	$id	= wpjam_file($file, 'id');

	if($id){
		return $id;
	}

	require_once ABSPATH.'wp-admin/includes/image.php';

	$title	= preg_replace('/\.[^.]+$/', '', wp_basename($file));
	$meta	= wp_read_image_metadata($file);

	if($meta){
		$title		= (trim($meta['title']) && !is_numeric(sanitize_title($meta['title']))) ? $meta['title'] : $title;
		$content	= trim($meta['caption']) ?: '';
	}

	$id	= wp_insert_attachment([
		'post_title'		=> $title,
		'post_content'		=> $content ?? '',
		'post_parent'		=> $post_id,
		'post_mime_type'	=> $type,
		'guid'				=> $url,
	], $file, $post_id, true);

	if(!is_wp_error($id)){
		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
	}

	return $id;
}

// 1. $img
// 2. $img, ['width'=>100, 'height'=>100]	// 这个为最标准版本
// 3. $img, 100x100
// 4. $img, 100
// 5. $img, [100,100]
// 6. $img, [100,100], $crop=1, $ratio=1
// 7. $img, 100, 100, $crop=1, $ratio=1
function wpjam_get_thumbnail($img, ...$args){
	$url	= ($img && is_numeric($img)) ? wp_get_attachment_url($img) : $img;

	if(!$url){
		return '';
	}

	$url	= remove_query_arg(['orientation', 'width', 'height'], wpjam_zh_urlencode($url));

	if(!$args){// 1. 无参数
		$size	= [];
	}elseif(count($args) == 1){
		// 2. ['width'=>100, 'height'=>100]	标准版
		// 3. [100,100]
		// 4. 100x100
		// 5. 100

		$size	= $args[0] ? wpjam_parse_size($args[0]) : [];
	}elseif(is_numeric($args[0])){
		// 6. 100, 100, $crop=1

		$size	= wpjam_parse_size([
			'width'		=> $args[0],
			'height'	=> $args[1],
			'crop'		=> $args[2] ?? 1
		]);
	}else{
		// 7.【100,100], $crop=1

		$size	= array_merge(wpjam_parse_size($args[0]), ['crop'=>$args[1]]);
	}

	return apply_filters('wpjam_thumbnail', $url, $size);
}

function wpjam_get_thumbnail_args($size){
	return apply_filters('wpjam_thumbnail', '', wpjam_parse_size($size));
}

// $size, $ratio
// $size, $ratio, [$max_width, $max_height]
// $size, [$max_width, $max_height]
function wpjam_parse_size($size, ...$args){
	$ratio	= ($args && !is_array($args[0])) ? (int)array_shift($args) : 1;
	$max	= ($args && is_array($args[0])) ? array_shift($args) : [];

	if(is_array($size)){
		$size	= wp_is_numeric_array($size) ? ['width'=>($size[0] ?? 0), 'height'=>($size[1] ?? 0)] : $size;
		$size	= array_merge($size, wpjam_fill(['width', 'height'], fn($k)=>(int)($size[$k] ?? 0)*$ratio));
		$size	+= ['crop'=>($size['width'] && $size['height'])];
	}elseif(is_numeric($size)){
		$size	= ['crop'=>false, 'width'=>(int)$size*$ratio, 'height'=>0];
	}else{
		$sep	= wpjam_find(['*', 'x', 'X'], fn($v)=> str_contains($size, $v));
		$sizes	= wp_get_additional_image_sizes();

		if($sep && !isset($sizes[$size])){
			[$width, $height]	= array_replace([0,0], explode($sep, $size));
		}else{
			$name		= $size == 'thumb' ? 'thumbnail' : $size;
			$default	= ['thumbnail'=>[100, 100], 'medium'=>[300,300], 'medium_large'=>[768,0], 'large'=>[1024,1024]][$name] ?? '';

			if($default){
				$crop	= $name == 'thumbnail' ? get_option($name.'_crop') : false;

				[$width, $height]	= wpjam_map(['w', 'h'], fn($v, $k)=> get_option($name.'_size_'.$v) ?: $default[$k]);
			}else{
				[$width, $height, $crop]	= isset($sizes[$name]) ? [$sizes[$name]['width'], $sizes[$name]['height'], $sizes[$name]['crop']] : [0, 0, false];
			}

			if($width && !empty($GLOBALS['content_width']) && !in_array($name, ['thumbnail', 'medium'])){
				$width	= min($GLOBALS['content_width'] * $ratio, $width);
			}
		}
	
		$size	= [
			'crop'		=> $crop ?? ($width && $height),
			'width'		=> (int)$width * $ratio,
			'height'	=> (int)$height * $ratio
		];
	}

	if(count($max) >= 2 && $max[0] && $max[1]){
		$max	= ($size['width'] && $size['height']) ? wp_constrain_dimensions($size['width'], $size['height'], $max[0], $max[1]) : $max;
		$size	= array_merge($size, wpjam_map(['width', 'height'], fn($v, $k)=> min($size[$v], $max[$k])));
	}

	return $size;
}

function wpjam_bits($str){
	return 'data:'.finfo_buffer(finfo_open(), $str, FILEINFO_MIME_TYPE).';base64, '.base64_encode($str);
}

function wpjam_get_image_size($value, $type='id'){
	$size	= wpjam_file($value, 'size', $type);
	$size	= apply_filters('wpjam_image_size', $size, $value, $type);

	return $size ? array_map('intval', $size)+['orientation'=> $size['height'] > $size['width'] ? 'portrait' : 'landscape'] : $size;
}

function wpjam_is_image($value, $type=''){
	$type	= $type ?: (is_numeric($value) ? 'id' : 'url');

	if($type == 'url'){
		$url	= wpjam_remove_postfix(explode('?', $value)[0], '#');

		return preg_match('/\.('.implode('|', wp_get_ext_types()['image']).')$/i', $url);
	}elseif($type == 'file'){
		return !empty(wpjam_file($value, 'size'));
	}elseif($type == 'id'){
		return wp_attachment_is_image($value);
	}
}

function wpjam_parse_image_query($url){
	$query	= wp_parse_args(parse_url($url, PHP_URL_QUERY));

	return wpjam_map($query, fn($v, $k)=> in_array($k, ['width', 'height']) ? (int)$v : $v);
}

function wpjam_is_external_url($url, $scene=''){
	$status	= wpjam_every(['http', 'https'], fn($v)=> !str_starts_with($url, site_url('', $v)));

	return apply_filters('wpjam_is_external_url', $status, $url, $scene);
}

function wpjam_fetch_external_images(&$urls, $post_id=0){
	$args	= ['post_id'=>$post_id, 'media'=>(bool)$post_id, 'field'=>'url'];
		
	foreach($urls as $url){
		if($url && wpjam_is_external_url($url, 'fetch')){
			$download	= wpjam_download_url($url, $args);

			if(!is_wp_error($download)){
				$search[]	= $url;
				$replace[]	= $download;
			}	
		}
	}

	$urls	= $search ?? [];

	return $replace ?? [];
}

// Cache
function wpjam_cache($group, $args=[]){
	return WPJAM_Cache::get_instance($group, $args);
}

function wpjam_generate_verification_code($key, $group='default'){
	return (WPJAM_Cache::get_verification($group))->generate($key);
}

function wpjam_verify_code($key, $code, $group='default'){
	return (WPJAM_Cache::get_verification($group))->verify($key, $code);
}

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

// Tag
function wpjam_tag($tag='', $attr=[], $text=''){
	return new WPJAM_Tag($tag, $attr, $text);
}

function wpjam_wrap($text, $wrap='', ...$args){
	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? $wrap($text, ...$args) : $text;
		$wrap	= '';
	}

	return (is_a($text, 'WPJAM_Tag') ? $text : wpjam_tag('', [], $text))->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, $args=[]){
	$object	= WPJAM_Fields::create($fields);

	if($args){
		$echo	= wpjam_pull($args, 'echo', true);
		$result	= $object->render($args);

		return $echo ? wpjam_echo($result) : $result;
	}

	return $object;
}

function wpjam_get_fields_parameter($fields, $method='POST'){
	return $fields ? wpjam_fields($fields)->get_parameter($method) : wpjam_get_parameter('', [], $method);
}

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	return $args ? (isset($args['wrap_tag']) ? $object->wrap(wpjam_pull($args, 'wrap_tag'), $args) : $object->render($args)) : $object;
}

function wpjam_add_pattern($key, $args){
	WPJAM_Field::add_pattern($key, $args);
}

function wpjam_icon($icon){
	if(str_starts_with($icon, 'dashicons-')){
		return wpjam_tag('span', ['dashicons', $icon]);
	}elseif(str_starts_with($icon, 'ri-')){
		return wpjam_tag('i', $icon);
	}
}

// AJAX
function wpjam_register_ajax($name, $args){
	return WPJAM_AJAX::register($name, $args);
}

function wpjam_get_ajax_data_attr($name, $data=[], $return=null){
	$object	= WPJAM_AJAX::get($name);

	return $object ? $object->get_attr($data, $return) : ($return ? null : []);
}

// Capability
function wpjam_map_meta_cap(...$args){
	if(count($args) >=4){	// $caps, $cap, $user_id, $args
		if(!in_array('do_not_allow', $args[0]) && $args[2]){
			$map	= array_filter(wpjam_get_items('map_meta_cap'), fn($item)=> $item['cap'] == $args[1]);

			foreach($map as $item){
				$result	= isset($item['callback']) ? $item['callback']($args[2], $args[3], $args[1]) : $item['caps'];
				$args[0]= is_array($result) || $result ? (array)$result : $args[0];
			}
		}

		return $args[0];
	}elseif(count($args) >= 2){	// $cap, $map_meta_cap
		if($args[0] && $args[1] && (is_callable($args[1]) || wp_is_numeric_array($args[1]))){
			if(!wpjam_get_items('map_meta_cap')){
				add_filter('map_meta_cap', 'wpjam_map_meta_cap', 10, 4);
			}
			
			$key	= is_callable($args[1]) ? 'callback' : 'caps';

			wpjam_add_item('map_meta_cap', ['cap'=>$args[0], $key=>$args[1]]);
		}
	}
}

function wpjam_current_user_can($capability, ...$args){
	$capability	= is_closure($capability) ? $capability(...$args) : $capability;

	return $capability ? current_user_can($capability, ...$args) : true;
}

// Verify TXT
function wpjam_register_verify_txt($name, $args){
	return WPJAM_Verify_TXT::register($name, $args);
}

// Asset
function wpjam_asset($type, $handle, $args){
	$args	= is_array($args) ? $args : ['src'=>$args];
	$fn		= function($type, $handle, $args){
		$method	= wpjam_pull($args, 'method') ?: 'enqueue';
		$if		= wpjam_pull($args, $method.'_if');

		if($if && !$if($handle, $type)){
			return;
		}

		$src	= wpjam_pull($args, 'src');
		$src	= is_closure($src) ? $src($handle) : $src;
		$deps	= wpjam_pull($args, 'deps');
		$ver	= wpjam_pull($args, 'ver');
		$data	= wpjam_pull($args, 'data');

		if($type == 'script'){
			$pos	= wpjam_pull($args, 'position') ?: 'after';
		}else{
			$pos	= null;
			$args	= wpjam_pull($args, 'media') ?: 'all';
		}

		if($src || !$data){
			call_user_func('wp_'.$method.'_'.$type, $handle, $src, $deps, $ver, $args);
		}

		if($data){
			call_user_func('wp_add_inline_'.$type, $handle, $data, $pos);
		}
	};

	if(wpjam_some(['wp', 'admin', 'login'], fn($part)=> doing_action($part.'_enqueue_scripts'))){
		$fn($type, $handle, $args);
	}else{
		$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
		$for	= wpjam_pull($args, 'for');
		$parts	= is_null($for) ? $parts : array_intersect($parts, wp_parse_list($for ?: 'wp'));

		$priority	= wpjam_pull($args, 'priority') ?? 10;

		array_walk($parts, fn($part)=> wpjam_load($part.'_enqueue_scripts', fn()=> $fn($type, $handle, $args), $priority));
	}
}

function wpjam_script($handle, $args=[]){
	wpjam_asset('script', $handle, $args);
}

function wpjam_style($handle, $args=[]){
	wpjam_asset('style', $handle, $args);
}

// Static CDN
function wpjam_add_static_cdn($host){
	if(!wpjam_get_items('static_cdn')){
		add_filter('wp_resource_hints',	fn($urls, $type)=> $type == 'dns-prefetch' ? [...array_diff($urls, wpjam_map(wpjam_get_items('static_cdn'), fn($v)=> parse_url($v, PHP_URL_HOST))), wpjam_get_static_cdn()] : $urls, 10, 2);

		foreach(['style', 'script'] as $asset){
			add_filter($asset.'_loader_src', fn($src)=> ($src && !str_starts_with($src, wpjam_get_static_cdn())) ? str_replace(wpjam_get_items('static_cdn'), wpjam_get_static_cdn(), $src) : $src);

			add_filter('current_theme_supports-'.$asset, fn($check, $args, $value)=> !array_diff($args, (is_array($value[0]) ? $value[0] : $value)), 10, 3);
		}
	}

	if(is_array($host)){
		array_map(fn($h)=> wpjam_add_item('static_cdn', $h), $host);
	}else{
		wpjam_add_item('static_cdn', $host);
	}
}

function wpjam_get_static_cdn(){
	$hosts	= wpjam_get_items('static_cdn');

	return apply_filters('wpjam_static_cdn_host', $hosts[0], $hosts);
}

// Upgrader
function wpjam_register_plugin_updater($hostname, $update_url){
	return WPJAM_Updater::create('plugin', $hostname, $update_url);
}

function wpjam_register_theme_updater($hostname, $update_url){
	return WPJAM_Updater::create('theme', $hostname, $update_url);
}

// Notice
function wpjam_add_admin_notice($notice, $blog_id=0){
	return WPJAM_Notice::add($notice, 'admin', $blog_id);
}

function wpjam_add_user_notice($user_id, $notice){
	return WPJAM_Notice::add($notice, 'user', $user_id);
}

// Rewrite Rule
function wpjam_add_rewrite_rule($args){
	$args	= ($args && is_callable($args)) ? $args() : $args;

	if($args && is_array($args)){
		if(is_array($args[0])){
			array_walk($args, 'wpjam_add_rewrite_rule');
		}else{
			add_rewrite_rule(...[$GLOBALS['wp_rewrite']->root.array_shift($args), ...$args]);
		}
	}
}

// Menu Page
function wpjam_add_menu_page(...$args){
	if(is_array($args[0])){
		$menu_page	= $args[0];
	}else{
		$page_type	= !empty($args[1]['plugin_page']) ? 'tab_slug' : 'menu_slug';
		$menu_page	= array_merge($args[1], [$page_type => $args[0]]);

		if(!is_admin() 
			&& isset($menu_page['function']) && $menu_page['function'] == 'option'
			&& (!empty($menu_page['sections']) || !empty($menu_page['fields']))
		){
			wpjam_register_option(($menu_page['option_name'] ?? $menu_slug), $menu_page);
		}
	}

	if(wp_is_numeric_array($menu_page)){
		array_walk($menu_page, 'wpjam_add_menu_page');
	}else{
		wpjam_hooks(wpjam_pull($menu_page, 'hooks'));
		wpjam_load('init', wpjam_pull($menu_page, 'init'));
		wpjam_map_meta_cap(wpjam_get($menu_page, 'capability'), wpjam_pull($menu_page, 'map_meta_cap'));

		if(is_admin()){
			WPJAM_Menu_Page::add($menu_page);
		}
	}
}


