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

function wpjam_get_registered($group, $name){
	if($group && $name){
		return WPJAM_Register::call_group('get_object_by_'.$group, $name);
	}
}

function wpjam_get_registereds($group){
	return $group ? WPJAM_Register::call_group('get_objects_by_'.$group) : [];
}

function wpjam_args($args=[]){
	return new WPJAM_Args($args);
}

// Items
function wpjam_get_items($group){
	return wpjam('get', $group);
}

function wpjam_get_item($group, $key){
	return wpjam('get', $group, $key);
}

function wpjam_add_item($group, $key, ...$args){
	return wpjam('add', $group, $key, ...$args);
}

function wpjam_get_instance($group, $id, $cb=null){
	return wpjam('get', 'instance', $group.'.'.$id) ?? ($cb ? wpjam_add_instance($group, $id, $cb($id)) : null);
}

function wpjam_add_instance($group, $id, $object){
	if(!is_wp_error($object) && !is_null($object)){
		wpjam('add', 'instance', $group.'.'.$id, $object);	
	}

	return $object;
}

// Handler
function wpjam_get_handler($name, $args=[]){
	return WPJAM_Handler::get($name, $args);
}

function wpjam_call_handler($name, $method, ...$args){
	return WPJAM_Handler::call($name, $method, ...$args);
}

function wpjam_register_handler($name, $args=[]){
	return WPJAM_Handler::create($name, $args);
}

// Platform & path
function wpjam_register_platform($name, $args){
	return WPJAM_Platform::register($name, $args);
}

// ['weapp', 'template'], $ouput	// 从一组中（空则全部）根据顺序获取
// ['path'=>true], $ouput;			// 从已注册路径的根据优先级获取
function wpjam_get_current_platform($args=[], $output='name'){
	return WPJAM_Platform::get_current($args, $output);
}

function wpjam_get_current_platforms($output='names'){
	$objects	= WPJAM_Platform::get_by(['path'=>true]);

	return $output == 'names' ? array_keys($objects) : $objects;
}

function wpjam_get_platform_options($output='bit'){
	return WPJAM_Platform::get_options($output);
}

function wpjam_get_path($platform, $page_key, $args=[]){
	return ($object = WPJAM_Platform::get($platform)) ? $object->get_path($page_key, $args) : '';
}

function wpjam_get_tabbar($platform, $page_key=''){
	return ($object	= WPJAM_Platform::get($platform)) ? $object->get_tabbar($page_key) : [];
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	if(is_string($args) && in_array($args, ['with_page', 'page'])){
		return $object ? wpjam_map($object->get_page(), fn($page, $pk)=> ['page'=>$page, 'page_key'=>$pk]) : [];
	}

	return $object ? array_keys(wpjam_filter($object->get_paths(), (is_array($args) ? $args : []), $operator)) : [];
}

function wpjam_register_path($name, ...$args){
	return WPJAM_Path::create($name, ...$args);
}

function wpjam_unregister_path($name, $platform=''){
	return WPJAM_Path::remove($name, $platform);
}

function wpjam_get_path_fields($platforms=null, $args=[]){
	return ($object = WPJAM_Platforms::get_instance($platforms)) ? $object->get_fields($args) : [];
}

function wpjam_parse_path_item($item, $platform=null, $suffix=''){
	return ($object = WPJAM_Platforms::get_instance($platform)) ? $object->parse_item($item, $suffix) : ['type'=>'none'];
}

function wpjam_validate_path_item($item, $platforms, $suffix='', $title=''){
	return ($object = WPJAM_Platforms::get_instance($platforms)) ? $object->validate_item($item, $suffix, $title) : true;
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type_object($name, $args=[]){
	return WPJAM_Data_Type::get_instance($name, $args);
}

function wpjam_get_post_id_field($post_type='post', $args=[]){
	return WPJAM_Post::get_field(['post_type'=> $post_type]+$args);
}

// Setting
function wpjam_setting($type, $option, ...$args){
	return WPJAM_Setting::get_instance($type, $option, ...$args);
}

function wpjam_get_setting($option, $name, $blog_id=0){
	return wpjam_setting($option, $blog_id)->get_setting($name);
}

function wpjam_update_setting($option, $name, $value='', $blog_id=0){
	return wpjam_setting($option, $blog_id)->update_setting($name, $value);
}

function wpjam_delete_setting($option, $name, $blog_id=0){
	return wpjam_setting($option, $blog_id)->delete_setting($name);
}

function wpjam_get_option($option, $blog_id=0){
	return wpjam_setting($option, $blog_id)->get_option();
}

function wpjam_update_option($option, $value, $blog_id=0){
	return wpjam_setting($option, $blog_id)->update_option($value);
}

function wpjam_get_site_setting($option, $name){
	return wpjam_setting('site', $option)->get_setting($name);
}

function wpjam_get_site_option($option){
	return wpjam_setting('site', $option)->get_option();
}

function wpjam_update_site_option($option, $value){
	return wpjam_setting('site', $option)->update_option($value);
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

// Verify TXT
function wpjam_get_verify_txt($name, $key=null){
	return WPJAM_Verify_TXT::get($name, $key);
}

function wpjam_set_verify_txt($name, $data){
	return WPJAM_Verify_TXT::set($name, $data);
}

// Meta Type
function wpjam_register_meta_type($name, $args=[]){
	return WPJAM_Meta_Type::register($name, $args);
}

function wpjam_get_meta_type_object($name){
	return WPJAM_Meta_Type::get($name);
}

function wpjam_register_meta_option($meta_type, $name, $args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->register_option($name, $args) : null;
}

function wpjam_unregister_meta_option($meta_type, $name){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->unregister_option($name) : null;
}

function wpjam_get_meta_options($meta_type, $args=[]){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_options($args) : [];
}

function wpjam_get_meta_option($meta_type, $name, $output='object'){
	$option	= ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_option($name) : null;

	return $output == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($meta_type, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_by_key(...$args) : [];
}

function wpjam_get_metadata($meta_type, $object_id, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_data_with_default($object_id, ...$args) : null;
}

function wpjam_update_metadata($meta_type, $object_id, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->update_data_with_default($object_id, ...$args) : null;
}

function wpjam_delete_metadata($meta_type, $object_id, $key){
	if(($object = WPJAM_Meta_Type::get($meta_type)) && $key){
		array_map(fn($k)=> $object->delete_data($object_id, $k), (array)$key);
	}

	return true;
}

// LazyLoader
function wpjam_register_lazyloader($name, $args){
	wpjam_lazyloader($name, $args);
}

function wpjam_lazyloader($name, ...$args){
	return wpjam($args ? 'set' : 'get', 'lazyloader', $name, ...$args);
}

function wpjam_lazyload($name, $ids){
	$ids	= array_filter($ids);

	if(!$name || !$ids){
		return;
	}

	if(is_array($name)){
		return array_walk($name, fn($n, $k)=> wpjam_lazyload($n, is_numeric($k) ? $ids : array_column($ids, $k)));
	}

	$ids	= array_unique($ids);

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
		wp_metadata_lazyloader()->queue_objects(substr($name, 0, -5), $ids);
	}else{
		$item	= wpjam_lazyloader($name) ?: [];

		if(!isset($item['data'])){
			if(!isset($item['filter']) && str_ends_with($name, '_meta')){
				$item	+= [
					'filter'	=> 'get_'.$name.'data',
					'callback'	=> fn($items)=> update_meta_cache(substr($name, 0, -5), $items)
				];
			}

			if(!empty($item['filter'])){
				$item['filter_fn']	= fn($pre)=> [wpjam_load_pending($name), $pre][1];

				add_filter($item['filter'], $item['filter_fn']);
			}
		}

		wpjam_lazyloader($name, ['data'=>array_merge($item['data'] ?? [], $ids)]+$item);
	}
}

function wpjam_load_pending($name, $callback=null){
	$item	= wpjam_lazyloader($name);

	if($item){
		$callback	= $callback ?: $item['callback'] ?? '';

		if($callback && !empty($item['data'])){
			if(!empty($item['filter'])){
				remove_filter($item['filter'], $item['filter_fn']);
			}

			wpjam_call($callback, array_unique($item['data']));

			wpjam_lazyloader($name.'.data', []);
		}
	}
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, ['_jam'=>true]+$args);
}

function wpjam_get_post_type_object($name){
	return WPJAM_Post_Type::get(is_numeric($name) ? get_post_type($name) : $name);
}

function wpjam_add_post_type_field($post_type, $key, ...$args){
	if($object = WPJAM_Post_Type::get($post_type)){
		foreach((is_array($key) ? $key : [$key => $args[0]]) as $k => $v){
			$object->add_field($k, $v);
		}
	}
}

function wpjam_remove_post_type_field($post_type, $key){
	if($object = WPJAM_Post_Type::get($post_type)){
		$object->remove_fields($key);
	}
}

function wpjam_get_post_type_setting($post_type, $key, $default=null){
	return ($object = WPJAM_Post_Type::get($post_type)) && isset($object->$key) ? $object->$key : $default;
}

function wpjam_update_post_type_setting($post_type, $key, $value){
	if(($object = WPJAM_Post_Type::get($post_type))){
		$object->$key	= $value;
	}
}

if(!function_exists('get_post_type_support')){
	function get_post_type_support($post_type, $feature){
		return ($object = WPJAM_Post_Type::get($post_type)) ? $object->get_support($feature) : false;
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

function wpjam_get_post_option($name, $output='object'){
	return wpjam_get_meta_option('post', $name, $output);
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
	return ($object = wpjam_post($post)) ? $object->parse_for_json((is_a($args, 'WPJAM_Field') ? ['thumbnail_size'=>$args->size] : $args)) : null;
}

function wpjam_get_posts($query, $parse=false){
	if($parse){
		$args	= [];
	}elseif(is_array($parse)){
		$args	= $parse;
		$parse	= true;
	}

	if(is_string($query) || wp_is_numeric_array($query)){
		$ids	= wp_parse_id_list($query);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? array_values(array_filter(array_map(fn($p)=> wpjam_get_post($p, $args), $ids))) : $posts;
	}

	return $parse ? wpjam_parse_query($query, $args) : (WPJAM_Posts::query($query))->posts;
}

function wpjam_get_post_views($post=null){
	return ($post = get_post($post)) ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	return ($post = get_post($post)) ? update_post_meta($post->ID, 'views', wpjam_get_post_views($post)+$offset) : false;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	return ($object = wpjam_post($post)) ? $object->get_excerpt($length, $more) : '';
}

function wpjam_get_post_content($post=null, $raw=false){
	return ($object = wpjam_post($post)) ? $object->get_content($raw) : '';
}

function wpjam_get_post_first_image_url($post=null, $size='full'){
	return ($object = wpjam_post($post)) ? $object->get_first_image_url($size) : '';
}

function wpjam_get_post_images($post=null, $large='', $thumbnail='', $full=true){
	return ($object = wpjam_post($post)) ? $object->get_images($large, $thumbnail, $full) : [];
}

function wpjam_get_post_thumbnail_url($post=null, $size='full', $crop=1){
	return ($object = wpjam_post($post)) ? $object->get_thumbnail_url($size, $crop) : '';
}

// Post Query
function wpjam_query($args=[]){
	return new WP_Query(wp_parse_args($args, ['no_found_rows'=>true, 'ignore_sticky_posts'=>true]));
}

function wpjam_parse_query($wp_query, $args=[], $parse=true){
	if(!$wp_query && !is_array($wp_query)){
		return $parse ? [] : '';
	}

	return ['WPJAM_Posts', ($parse ? 'parse' : 'render')]($wp_query, array_merge($args, ['list_query'=>true]));
}

function wpjam_parse_query_vars($vars){
	return WPJAM_Posts::parse_query_vars($vars);
}

function wpjam_render_query($wp_query, $args=[]){
	return WPJAM_Posts::render($wp_query, $args);
}

function wpjam_pagenavi($total=0, $echo=true){
	$result	= '<div class="pagenavi">'.paginate_links(array_filter([
		'prev_text'	=> '&laquo;',
		'next_text'	=> '&raquo;',
		'total'		=> $total
	])).'</div>';

	return $echo ? wpjam_echo($result) : $result;
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query(...$args){
	return WPJAM_Posts::get_related_query(...(count($args) <= 1 ? [get_the_ID(), ['number'=>$args[0] ?? 5]] : $args));
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
	return WPJAM_Taxonomy::register($name, ['_jam'=>true]+(count($args) == 2 ? ['object_type'=>$args[0]]+$args[1] : $args[0]));
}

function wpjam_get_taxonomy_object($name){
	return WPJAM_Taxonomy::get(is_numeric($name) ? get_term_field('taxonomy', $id) : $name);
}

function wpjam_add_taxonomy_field($taxonomy, $key, ...$args){
	if($object = WPJAM_Taxonomy::get($taxonomy)){
		foreach((is_array($key) ? $key : [$key => $args[0]]) as $k => $v){
			$object->add_field($k, $v);
		}
	}
}

function wpjam_remove_taxonomy_field($taxonomy, $key){
	if($object = WPJAM_Taxonomy::get($taxonomy)){
		$object->remove_fields($key);
	}
}

function wpjam_get_taxonomy_setting($taxonomy, $key, $default=null){
	return (($object = WPJAM_Taxonomy::get($taxonomy)) && isset($object->$key)) ? $object->$key : $default;
}

function wpjam_update_taxonomy_setting($taxonomy, $key, $value){
	if($object = WPJAM_Taxonomy::get($taxonomy)){
		$object->$key	= $value;
	}
}

if(!function_exists('taxonomy_supports')){
	function taxonomy_supports($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->supports($feature) : false;
	}
}

if(!function_exists('add_taxonomy_support')){
	function add_taxonomy_support($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->add_support($feature) : null;
	}
}

if(!function_exists('remove_taxonomy_support')){
	function remove_taxonomy_support($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->remove_support($feature) : null;
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

function wpjam_get_term_option($name, $output='object'){
	return wpjam_get_meta_option('term', $name, $output);
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
	[$tax, $args]	= is_a($args, 'WPJAM_Field') ? [$args->taxonomy, []] : (is_array($args) ? [wpjam_pull($args, 'taxonomy'), $args] : [$args, []]);

	return ($object = WPJAM_Term::get_instance($term, $tax, false)) ? $object->parse_for_json($args) : null;
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
	}

	return WPJAM_Terms::parse(array_merge($args[0], isset($args[1]) ? ['max_depth'=>$args[1]] : []));
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
	return ($object	= wpjam_term($term)) ? $object->get_thumbnail_url($size, $crop) : '';
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
	return ($object	= wpjam_user($user)) ? $object->parse_for_json($size) : null;
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
	return wpjam_get_bind_object($type, $appid) ?: WPJAM_Bind::create($type, $appid, $args);
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
		return ($comment = get_comment($comment_id)) ? $comment->comment_parent : null;
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
	return WPJAM_File::convert($value, $to, $from);
}

function wpjam_get_attachment_value($id, $field='file'){
	return WPJAM_File::get_by_id($id, $field);
}

function wpjam_restore_attachment_file($id, $url=''){
	return WPJAM_File::restore($id, $url);
}

function wpjam_accept_to_mime_types($accept){
	return WPJAM_File::accept_to_mime_types($accept);
}

function wpjam_upload($name, $args=[]){
	return WPJAM_File::upload($name, $args);
}

function wpjam_upload_bits($bits, $name, $media=true){
	$upload	= WPJAM_File::upload($name, ['bits'=>$bits]);

	return (is_wp_error($upload) || !$media) ? $upload : WPJAM_File::add_to_media($upload['file'], $upload+['post_id'=>is_numeric($media) ? $media : 0]);
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	return WPJAM_File::download($url, is_array($name) ? $name : compact('name', 'media', 'post_id'));
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

	return $url ? WPJAM_File::get_thumbnail($url, ...$args) : '';
}

function wpjam_get_thumbnail_args($size){
	return WPJAM_File::get_thumbnail('', $size);
}

// $size, $ratio
// $size, $ratio, [$max_width, $max_height]
// $size, [$max_width, $max_height]
function wpjam_parse_size($size, ...$args){
	return WPJAM_File::parse_size($size, ...$args);
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
		$url	= explode('?', $value)[0];
		$url	= str_ends_with($url, '#') ? substr($url, 0, -1) : $url;

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
	$status	= array_all(['http', 'https'], fn($v)=> !str_starts_with($url, site_url('', $v)));

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

// Code
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

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	return $args ? (isset($args['wrap_tag']) ? $object->wrap(wpjam_pull($args, 'wrap_tag'), $args) : $object->render($args)) : $object;
}

function wpjam_icon($icon){
	if(str_starts_with($icon, 'ri-')){
		return wpjam_tag('i', $icon);
	}

	return wpjam_tag('span', ['dashicons', (str_starts_with($icon, 'dashicons-') ? '' : 'dashicons-').$icon]);
}

// AJAX
function wpjam_register_ajax($name, $args){
	return WPJAM_AJAX::create($name, $args);
}

function wpjam_get_ajax_data_attr($name, $data=[], $output=null){
	if(WPJAM_AJAX::get($name)){
		$action	= WPJAM_AJAX::parse_nonce_action($name, $data);
		$attr	= ['action'=>$name, 'data'=>$data, 'nonce'=>($action ? wp_create_nonce($action) : null)];

		return $output ? $attr : wpjam_attr($attr, 'data');
	}

	return $output ? null : [];
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


