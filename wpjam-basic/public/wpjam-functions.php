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
	$object	= wpjam_get_item('instance', $key, $name);

	return $object ?: ($cb ? wpjam_add_instance($name, $key, $cb($key)) : null);
}

function wpjam_add_instance($name, $key, $object){
	return wpjam_add_item('instance', $key, $object, $name);
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

	if(!$object){
		return '';
	}

	if(is_array($page_key)){
		$args		= $page_key;
		$page_key	= wpjam_pull($args, 'page_key');
	}

	return $object->get_path($page_key, $args);
}

function wpjam_get_tabbar($platform, $page_key=''){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	if($page_key){
		return $object->get_tabbar($page_key);
	}

	return array_filter(wpjam_fill(array_keys($object->get_items()), [$object, 'get_tabbar']));
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	if(!$object){
		return [];
	}

	$items	= $object->get_items();

	if(is_string($args) && in_array($args, ['with_page', 'page'])){
		$items	= array_map(fn($pk)=>['page'=>$object->get_page($pk), 'page_key'=>$pk], array_keys($items));

		return array_values(array_filter($items, fn($item)=> !empty($item['page'])));
	}else{
		$items	= is_array($args) ? wp_list_filter($items, $args, $operator) : $items;

		return array_keys($items);
	}
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

	$args	= is_array($args) ? $args : ['for'=>$args];
	$strict	= wpjam_pull($args, 'for') == 'qrcode';

	return $object->get_fields($args, $strict);
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
	$value	= wpjam_pull($args, 'data_type');

	return $value ? wpjam_except($args, $value) : $args;
}

function wpjam_parse_data_type($args){
	$data_type	= array_get($args, 'data_type');

	return $data_type ? [
		'data_type'	=> $data_type,
		$data_type	=> (array_get($args, $data_type) ?: '')
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
	return WPJAM_Option_Section::add($option_name, ...$args);
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

// wpjam_get_metadata($meta_type, $object_id, $meta_keys)
// wpjam_get_metadata($meta_type, $object_id, $meta_key, $default)
function wpjam_get_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->get_data_with_default($object_id, ...$args) : null;
}

// wpjam_update_metadata($meta_type, $object_id, $data, $defaults=[])
// wpjam_update_metadata($meta_type, $object_id, $meta_key, $meta_value, $default=null)
function wpjam_update_metadata($meta_type, $object_id, ...$args){
	$object	= WPJAM_Meta_Type::get($meta_type);

	return $object ? $object->update_data_with_default($object_id, ...$args) : null;
}

function wpjam_delete_metadata($meta_type, $object_id, $key){
	$object	= WPJAM_Meta_Type::get($meta_type);

	if($object && $key){
		wpjam_map((array)$key, fn($k)=> $object->delete_data($object_id, $k));
	}

	return true;
}

// LazyLoader
function wpjam_register_lazyloader($name, $args){
	return WPJAM_Lazyloader::add($name, $args);
}

function wpjam_lazyload($name, $ids){
	if(!$name){
		return;
	}

	if(is_array($name)){
		if(wp_is_numeric_array($name)){
			array_walk($name, fn($n)=> wpjam_lazyload($n, $ids));
		}else{
			array_walk($name, fn($n, $k)=> wpjam_lazyload($n, array_column($ids, $k)));
		}
	}else{
		$ids	= array_unique($ids);
		$ids	= array_filter($ids);

		if(!$ids){
			return;
		}

		if(in_array($name, ['blog', 'site'])){
			_prime_site_caches($ids);
		}elseif($name == 'post'){
			_prime_post_caches($ids, false, false);

			wpjam_lazyload('post_meta', $ids);
		}elseif($name == 'term'){
			_prime_term_caches($ids);
		}elseif($name == 'comment'){
			_prime_comment_caches($ids);
		}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
			wp_metadata_lazyloader()->queue_objects(wpjam_remove_postfix($name, '_meta'), $ids);
		}else{
			WPJAM_Lazyloader::queue($name, $ids);
		}
	}
}

function wpjam_load_pending($name, $callback){
	$items	= WPJAM_Lazyloader::get_pending($name);

	if($items){
		$callback($items);

		WPJAM_Lazyloader::update_pending($name, []);
	}
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
		if(is_array($args[0])){
			array_walk($args[0], fn($field, $key)=> $object->add_item($key, $field, '_fields'));
		}else{
			$object->add_item($args[0], $args[1], '_fields');
		}
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

	return $object ? ($object->$key ?? $default) : $default;
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

	if($object){
		if(is_a($args, 'WPJAM_Field')){
			$args	= $args->size ? ['thumbnal_size'=>$args->size] : [];
		}

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
	return new WP_Query(wp_parse_args($args, [
		'no_found_rows'			=> true,
		'ignore_sticky_posts'	=> true,
	]));
}

function wpjam_parse_query($wp_query, $args=[], $parse=true){
	if($parse){
		$args	= array_merge($args, ['list_query'=>true]);
		$method	= 'parse';
	}else{
		$method	= 'render';
	}

	return WPJAM_Posts::$method($wp_query, $args);
}

function wpjam_render_query($wp_query, $args=[]){
	return WPJAM_Posts::render($wp_query, $args);
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query(...$args){
	if(count($args) <= 1){
		$post	= get_the_ID();
		$args	= ['number'=>$args[0] ?? 5];
	}else{
		$post	= $args[0];
		$args	= $args[1];
	}

	return WPJAM_Posts::get_related_query($post, $args);
}

function wpjam_get_related_object_ids($tt_ids, $number, $page=1){
	return WPJAM_Posts::get_related_object_ids($tt_ids, $number, $page);
}

function wpjam_related_posts($args=[]){
	echo wpjam_get_related_posts(null, $args, false);
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	$wp_query	= wpjam_get_related_posts_query($post, $args);

	return wpjam_parse_query($wp_query, $args, $parse);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_parse_query([
		'posts_per_page'	=> 5,
		'orderby'			=> 'date',
	], $args, $parse);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_parse_query([
		'posts_per_page'	=> 5,
		'orderby'			=> 'meta_value_num',
		'meta_key'			=> 'views',
	], $args, $parse);
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
		if(is_array($args[0])){
			wpjam_map($args[0], fn($field, $k)=> $object->add_item($k, $field, '_fields'));
		}else{
			$object->add_item($args[0], $args[1], '_fields');
		}
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

	if($object && isset($object->$key)){
		return $object->$key;
	}

	return $default;
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
	$args		= is_a($args, 'WPJAM_Field') ? ['taxonomy'=>$args->taxonomy] : (is_array($args) ? $args : ['taxonomy'=>$args]);
	$taxonomy	= wpjam_pull($args, 'taxonomy');
	$object		= WPJAM_Term::get_instance($term, $taxonomy, false);

	return $object ? $object->parse_for_json($args) : null;
}

// $args, $max_depth
// $term_ids, $args
function wpjam_get_terms(...$args){
	if(is_string($args[0]) || wp_is_numeric_array($args[0])){
		$ids	= wp_parse_id_list(array_shift($args));
		$terms	= WPJAM_Term::get_by_ids($ids);
		$args	= array_shift($args);

		if(is_bool($args)){
			$parse	= $args;
			$args	= [];
		}else{
			$args	= is_array($args) ? $args : [];
			$parse	= wpjam_pull($args, 'parse');
		}

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

	return current_user_can($capability, ...$args);
}

// Verify TXT
function wpjam_register_verify_txt($name, $args){
	return WPJAM_Verify_TXT::register($name, $args);
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


