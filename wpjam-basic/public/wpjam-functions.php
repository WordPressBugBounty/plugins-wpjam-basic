<?php
// args
function wpjam_args($args=[]){
	return new WPJAM_Args($args);
}

// register
function wpjam_register($group, $name, $args=[]){
	return $group && $name ? wpjam_registry($group)->add_object($name, $args) : null;
}

function wpjam_unregister($group, $name){
	$group && $name && wpjam_registry($group)->remove_object($name);
}

function wpjam_get_registered($group, $name){
	return $group && $name ? wpjam_registry($group)->get_object($name) : null;
}

function wpjam_get_registereds($group, $args=[]){
	return $group ? wpjam_registry($group)->get_objects($args) : [];
}

function wpjam_registry($name, $args=[]){
	return WPJAM_Registry::get_instance(strtolower($name), $args);
}

// Handler
function wpjam_get_handler(...$args){
	static $object;
	$object ??= new WPJAM_Handler();

	return $args ? $object(...$args) : $object;
}

function wpjam_register_handler($name, $args=[]){
	return wpjam_get_handler()($name, $args, true);
}

function wpjam_call_handler($name, $method, ...$args){
	return wpjam_get_handler()->$method($name, ...$args);
}

// Builtin
function wpjam_get_builtin_type($class){
	while($parent = get_parent_class($class)){
		if(in_array($parent, ['WPJAM_Instance', 'WPJAM_Core'])){
			return try_prefix($class, '-', 'WPJAM_') && class_exists('WP_'.$class) ? strtolower($class) : null;
		}

		$class = $parent;
	}
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type($name, $args=[]){
	return WPJAM_Data_Type::get_instance($name, $args);
}

// Platform & Path
function wpjam_register_platform($name, $args){
	return WPJAM_Platform::register($name, $args);
}

function wpjam_get_current_platform($args=[], $output='name'){
	$args	= ($output == 'bit' && $args && wp_is_numeric_array($args)) ? ['bit'=>$args] : ($args ?: ['path'=>true]);
	$object	= array_find(WPJAM_Platform::get_by($args), fn($v)=> $v && $v->verify());

	return ($output === 'object' || !$object) ? $object : $object->$output;
}

function wpjam_get_path($platform, $page_key, $args=[]){
	return wpjam_call_method(WPJAM_Platform::get($platform), 'get_path', $page_key, $args);
}

function wpjam_get_tabbar($platform, $page_key=''){
	return wpjam_call_method(WPJAM_Platform::get($platform), 'get_tabbar', $page_key) ?: [];
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	return is_string($args) && in_array($args, ['with_page', 'page'])
	? wpjam_array(wpjam_call_method($object, 'get_page') ?: [], fn($pk, $page)=> [null, ['page'=>$page, 'page_key'=>$pk]])
	: array_keys(wpjam_filter(wpjam_call_method($object, 'get_paths') ?: [], (is_array($args) ? $args : []), $operator));
}

function wpjam_register_path($name, ...$args){
	return WPJAM_Path::create($name, ...$args);
}

function wpjam_unregister_path($name, $platform=''){
	return WPJAM_Path::remove($name, $platform);
}

function wpjam_get_path_fields($platforms=null, $args=[]){
	return WPJAM_Path::get_fields($platforms, $args);
}

function wpjam_parse_path_item($item, $platform=null, $suffix=''){
	return WPJAM_Path::parse_item($platform, $item, $suffix);
}

function wpjam_validate_path_item($item, $platforms, $suffix=''){
	return WPJAM_Path::validate_item($platforms, $item, $suffix);
}

// Option
function wpjam_get_option($name, ...$args){
	$output	= $args && in_array($args[0], ['object', 'model', 'registered'], true) ? 'object' : '';
	$object	= WPJAM_Option_Setting::get_instance($name, ...($output === 'object' ? $args : []));

	return $output === 'object' ? $object : $object->get_blog_option(...$args);
}

function wpjam_update_option($name, $value, $blog_id=0){
	return wpjam_get_option($name, 'object')->update_blog_option($blog_id, $value);
}

function wpjam_get_site_option($name){
	return wpjam_get_option($name, 'object')->get_site_option();
}

function wpjam_update_site_option($name, $value){
	return wpjam_get_option($name, 'object')->update_site_option($value);
}

function wpjam_get_setting($name, $setting, $blog_id=0){
	return wpjam_get_option($name, 'object')->get_blog_setting($blog_id, $setting);
}

function wpjam_update_setting($name, $setting, $value='', $blog_id=0){
	return wpjam_get_option($name, 'object')->update_blog_setting($blog_id, $setting, $value);
}

function wpjam_delete_setting($name, $setting, $blog_id=0){
	return wpjam_get_option($name, 'object')->delete_blog_setting($blog_id, $setting);
}

function wpjam_get_site_setting($name, $setting){
	return wpjam_get_option($name, 'object')->get_site_setting($setting);
}

function wpjam_register_option($name, $args=[]){
	return WPJAM_Option_Setting::create($name, $args);
}

function wpjam_add_option_section($name, ...$args){
	return wpjam_get_option($name, 'object')->add_section(...$args);
}

function wpjam_call_option($name, $method, ...$args){
	$object	= wpjam_get_option($name, class_exists($name) ? 'model' : 'object');

	return $method == 'get_object' ? $object : [$object, $method](...$args);
}

function wpjam_setting(...$args){
	if($args && is_array($args[0]) && ($args = $args[0])){
		if($option	= $args['option_name'] ?? ''){
			$object	= wpjam_get_option($option, 'object');
			$name	= $args['setting_name'] ?? ($args['setting'] ?? null);
			$value	= ($object->option_type === 'array' && $object->get_fields()) ? $object->prepare_by_fields() : $object->get_option();

			return [(($args['output'] ?? '') ?: ($name ?: $option))=> ($name ? wpjam_get($value, $name) : $value)];
		}
	}else{
		$args[0] == 'option' && array_shift($args);

		return WPJAM_Option_Setting::get_instance(...$args);	// 兼容
	}
}

// JSON
function wpjam_register_json($name, $args=[]){
	return WPJAM_JSON::register($name, $args);
}

function wpjam_register_api($name, $args=[]){
	return wpjam_register_json($name, $args);
}

function wpjam_get_json(...$args){
	$output	= $args ? (in_array($args[0], ['name', 'object'], true) ? array_shift($args) : 'object') : 'name';
	$name	= $args ? $args[0] : WPJAM_JSON::get_current();

	return $output === 'name' ? $name : WPJAM_JSON::get($name);
}

function wpjam_parse_json_module($module){
	return wpjam_catch('WPJAM_JSON::parse_module', $module);
}

function wpjam_is_json_request(){
	return get_option('permalink_structure')
	? (bool)preg_match("/\/api\/.*\.json/", $_SERVER['REQUEST_URI'])
	: (($_GET['module'] ?? '') == 'json');
}

function wpjam_json_source($name, $cb, $query_args=['source_id']){
	($name == wpjam_param('source')) && add_filter('wpjam_pre_json', fn($pre)=> is_array($result = $cb(wpjam_param($query_args))) ? $result : $pre);
}

// Meta Type
function wpjam_register_meta_type($name, $args=[]){
	$global	= $args['global'] ?? false;
	$table	= $args['table_name'] ?? $name.'meta';
	$global && wp_cache_add_global_groups($name.'_meta');

	$GLOBALS['wpdb']->$table ??= $args['table'] ?? $GLOBALS['wpdb']->{$global ? 'base_prefix' : 'prefix'}.$name.'meta';

	return WPJAM_Meta_Type::register($name, $args);
}

function wpjam_get_meta_type($name){
	return WPJAM_Meta_Type::get($name);
}

function wpjam_register_meta_option($type, $name, $args){
	return wpjam_call_method(wpjam_get_meta_type($type), 'call_option', $name, $args);
}

function wpjam_unregister_meta_option($type, $name){
	return wpjam_call_method(wpjam_get_meta_type($type), 'call_option', '-'.$name);
}

function wpjam_get_meta_options($type, $args=[]){
	return wpjam_call_method(wpjam_get_meta_type($type), 'get_options', $args) ?: [];
}

function wpjam_get_meta_option($type, $name, $output='object'){
	$option	= wpjam_call_method(wpjam_get_meta_type($type), 'call_option', $name);

	return $output == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($type, $key, $value=null, $column=null){
	return wpjam_call_method(wpjam_get_meta_type($type), 'get_by_key', $key, $value, $column) ?: [];
}

function wpjam_get_metadata($type, $object_id, ...$args){
	return wpjam_call_method(wpjam_get_meta_type($type), 'get_data_with_default', $object_id, ...$args);
}

function wpjam_update_metadata($type, $object_id, $key, ...$args){
	return wpjam_call_method(wpjam_get_meta_type($type), 'update_data_with_default', $object_id, $key, ...$args);
}

function wpjam_delete_metadata($type, $object_id, $key){
	return $key && array_map(fn($k)=> wpjam_call_method(wpjam_get_meta_type($type), 'delete_data', $object_id, $k), (array)$key) || true;
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, $args+['_jam'=>true]);
}

function wpjam_get_post_type_object($name){
	return WPJAM_Post_Type::get(is_numeric($name) ? get_post_type($name) : $name);
}

function wpjam_add_post_type_field($type, $key, ...$args){
	return wpjam_get_post_type_object($type)->add_field($key, ...$args);
}

function wpjam_remove_post_type_field($type, $key){
	wpjam_get_post_type_object($type)->remove_field($key);
}

function wpjam_get_post_type_setting($type, $key, $default=null){
	return ($object = wpjam_get_post_type_object($type)) ? ($object->$key ?? $default) : $default;
}

function wpjam_update_post_type_setting($type, $key, $value){
	if($object = wpjam_get_post_type_object($type)){
		return $object->$key = $value;
	}
}

// Post Option
function wpjam_register_post_option($meta_box, $args=[]){
	return wpjam_register_meta_option('post', $meta_box, $args);
}

function wpjam_unregister_post_option($meta_box){
	wpjam_unregister_meta_option('post', $meta_box);
}

function wpjam_get_post_options($type='', $args=[]){
	return wpjam_get_meta_options('post', array_filter(['post_type'=>$type])+$args);
}

function wpjam_get_post_option($name, $output='object'){
	return wpjam_get_meta_option('post', $name, $output);
}

// Post Column
function wpjam_register_posts_column($name, ...$args){
	return is_admin() ? wpjam_register_list_table_column($name, ['data_type'=>'post_type']+(is_array($args[0]) ? $args[0] : array_combine(['title', 'callback'], $args))) : null;
}

// Post
function wpjam_get_post($post, $args=[], $output=null){
	$object	= WPJAM_Post::get_instance($post, is_array($args) || $args === 'object' ? null : $args);

	return ($output ?? $args) === 'object' ? $object : wpjam_call_method($object, 'parse_for_json', is_array($args) ? $args : []);
}

function wpjam_get_post_object($post, $type=null){
	return $post ? wpjam_get_post($post, $type, 'object') : null;
}

function wpjam_validate_post($value, $type=null){
	return WPJAM_Post::validate($value, $type);
}

function wpjam_get_posts($vars, ...$args){
	[$args, $parse]	= $args && is_array($args[0]) ? array_pad($args, 2, true) : [[], $args[0] ?? false];

	if(is_scalar($vars) || wp_is_numeric_array($vars)){
		$ids	= wp_parse_id_list($vars);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? wpjam_array($ids, fn($k, $v)=> [null, wpjam_get_post($v, $args) ?: null], true) : $posts;
	}

	return wpjam_query('parse', $vars, ['parse'=>$parse]+$args);
}

function wpjam_get_post_views($post=null){
	return ($post = get_post($post)) ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	return ($post = get_post($post)) ? wpjam_tap(wpjam_get_post_views($post)+$offset, fn($views)=> update_post_meta($post->ID, 'views', $views)) : false;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	if(!($post = get_post($post)) || $post->post_excerpt || is_serialized($post->post_content)){
		return $post ? wp_strip_all_tags($post->post_excerpt, true) : '';
	}

	$excerpt	= wpjam_call_with_suppress([
		['the_content', 'wp_filter_content_tags', 12],
		['the_content', 'do_blocks', 9],
		['the_content', 'do_shortcode', 11]
	], 'wpjam_get_post_content', $post);

	return mb_strimwidth(
		wp_strip_all_tags(excerpt_remove_footnotes(excerpt_remove_blocks(strip_shortcodes($excerpt))), true),
		0,
		($length ?: apply_filters('excerpt_length', 200)),
		($more ?? apply_filters('excerpt_more', ' &hellip;')),
		'utf-8'
	);
}

function wpjam_get_post_content($post=null, $raw=false){
	return ($post = get_post($post)) ? ($raw
		? get_the_content('', false, $post)
		: apply_filters('the_content', str_replace(']]>', ']]&gt;', get_the_content('', false, $post)))
	) : '';
}

function wpjam_get_post_first_image_url($post=null, $size='full'){
	foreach(($post = get_post($post)) && $post->post_content ? [
		['/class=[\'"].*?wp-image-([\d]*)[\'"]/i',	'wp_get_attachment_image_url'],
		['/<img.*?src=[\'"](.*?)[\'"].*?>/i',		'wpjam_get_thumbnail'],
	] : [] as [$regex, $cb]){
		if(preg_match($regex, $post->post_content, $m)){
			return $cb($m[1], $size);
		}
	}

	return '';
}

function wpjam_get_post_thumbnail_url($post=null, $size='full', $crop=1){
	foreach(($post = get_post($post)) ? ['thumbnail', 'images', 'filter'] : [] as $k){
		if($k == 'thumbnail'){
			$v	= post_type_supports($post->post_type, $k) ? get_the_post_thumbnail_url($post->ID, 'full') : '';
		}else{
			$v	= $k == 'images' ? array_first(wpjam_get_post_images($post, false)) : apply_filters('wpjam_post_thumbnail_url', '', $post);
		}

		if($v){
			return wpjam_get_thumbnail($v, ($size ?: (wpjam_get_post_type_setting($post->post_type, 'thumbnail_size') ?: 'thumbnail')), $crop);
		}
	}

	return '';
}

function wpjam_get_post_images($post=null, $args=[]){
	$images	= ($post = get_post($post)) && post_type_supports($post->post_type, 'images') ? get_post_meta($post->ID, 'images', true) : [];

	return (!$images || $args === false)
	? ($images ?: [])
	: wpjam_get_post_type_object($post->post_type)->parse_images($images, $args+['post_id'=>$post->ID]);
}

function wpjam_get_post_id_field($type='post', $args=[]){
	return WPJAM_Post::get_field(['post_type'=> $type]+$args);
}

// Query
function wpjam_query($vars, ...$args){
	if($vars === 'data_type'){
		$name	= wpjam_pull($args[0], 'data_type');
		$args	= wp_parse_args(($args[0]['query_args'] ?? $args[0]) ?: [])+wpjam_pull($args[0], ['search']);

		return ['items'=>[wpjam_get_data_type($name, $args) ?: wpjam_throw('invalid_data_type'), 'query_items']($args)];
	}

	$type	= is_string($vars) && !method_exists('WPJAM_Query', $vars) ? $vars : '';
	$vars	= $type ? array_shift($args) : (is_bool($vars) ? ($vars ? 'parse' : 'render') : $vars);
	$object	= WPJAM_Query::get_instance($type ?: 'post');

	return is_string($vars) ? $object->$vars(...$args) : $object->query($vars, ...$args);
}

function wpjam_parse_query_vars($vars, $param=false){
	return ($param || !wpjam_is_json_request()) ? wpjam_query('parse_vars', $vars, $param) : $vars;
}

function wpjam_get_query_var($key, $wp=null){
	return ($wp ?: $GLOBALS['wp'])->query_vars[$key] ?? null;
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query($post, ...$args){
	return wpjam_query(['related_query'=>true, 'post'=>($args ? $post : null)], ($args ? $args[0] : ['number'=>$post]));
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	return wpjam_query($parse, ['post'=>$post, 'related_query'=>true], $args);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_query($parse, ['posts_per_page'=>5, 'orderby'=>'date'], $args);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_query($parse, ['posts_per_page'=>5, 'orderby'=>'meta_value_num', 'meta_key'=>'views'], $args);
}

function wpjam_pagenavi($total=0, $display=true){
	return wpjam_echo('<div class="pagenavi">'.paginate_links(array_filter(['prev_text'=>'&laquo;', 'next_text'=>'&raquo;', 'total'=>$total])).'</div>', $display);
}

// Taxonomy
function wpjam_register_taxonomy($name, ...$args){
	return WPJAM_Taxonomy::register($name, (count($args) == 2 ? ['object_type'=>$args[0]]+$args[1] : $args[0])+['_jam'=>true]);
}

function wpjam_get_taxonomy($name){
	return WPJAM_Taxonomy::get(is_numeric($name) ? get_term_field('taxonomy', $name) : $name);
}

function wpjam_add_taxonomy_field($tax, $key, ...$args){
	return wpjam_get_taxonomy($tax)->add_field($key, ...$args);
}

function wpjam_remove_taxonomy_field($tax, $key){
	wpjam_get_taxonomy($tax)->remove_field($key);
}

function wpjam_get_taxonomy_setting($tax, $key, $default=null){
	return ($object = wpjam_get_taxonomy($tax)) ? ($object->$key ?? $default) : $default;
}

function wpjam_update_taxonomy_setting($tax, $key, $value){
	if($object = wpjam_get_taxonomy($tax)){
		return $object->$key	= $value;
	}
}

if(!function_exists('taxonomy_supports')){
	function taxonomy_supports($tax, $feature){
		return (bool)wpjam_call_method(wpjam_get_taxonomy($tax), 'supports', $feature);
	}
}

if(!function_exists('add_taxonomy_support')){
	function add_taxonomy_support($tax, $feature){
		return wpjam_call_method(wpjam_get_taxonomy($tax), 'add_support', $feature);
	}
}

if(!function_exists('remove_taxonomy_support')){
	function remove_taxonomy_support($tax, $feature){
		return wpjam_call_method(wpjam_get_taxonomy($tax), 'remove_support', $feature);
	}
}	

function wpjam_get_taxonomy_query_key($tax){
	return ['category'=>'cat', 'post_tag'=>'tag_id'][$tax] ?? $tax.'_id';
}

function wpjam_get_term_id_field($tax='category', $args=[]){
	return WPJAM_Term::get_field(['taxonomy'=>$tax]+$args);
}

// Term Option
function wpjam_register_term_option($name, $args=[]){
	return wpjam_register_meta_option('term', $name, $args);
}

function wpjam_unregister_term_option($name){
	wpjam_unregister_meta_option('term', $name);
}

function wpjam_get_term_options($tax='', $args=[]){
	return wpjam_get_meta_options('term', array_filter(['taxonomy'=>$tax])+$args);
}

function wpjam_get_term_option($name, $output='object'){
	return wpjam_get_meta_option('term', $name, $output);
}

// Term Column
function wpjam_register_terms_column($name, ...$args){
	return is_admin() ? wpjam_register_list_table_column($name, ['data_type'=>'taxonomy']+(is_array($args[0]) ? $args[0] : array_combine(['title', 'callback'], $args))) : null;
}

// Term
function wpjam_get_term($term, $args=[], $output=null){
	$object	= WPJAM_Term::get_instance($term, is_array($args) ? wpjam_pull($args, 'taxonomy') : ($args === 'object' ? null : $args));

	return ($output ?? $args) === 'object' ? $object : wpjam_call_method($object, 'parse_for_json', is_array($args) ? $args : []);
}

function wpjam_get_term_object($term, $tax=''){
	return wpjam_get_term($term, $tax, 'object');
}

function wpjam_validate_term($value, $tax=null){
	return WPJAM_Term::validate($value, $tax);
}

function wpjam_get_terms($vars, ...$args){
	if(is_scalar($vars) || wp_is_numeric_array($vars)){
		$terms	= WPJAM_Term::get_by_ids(wp_parse_id_list($vars));

		return $args && $args[0] ? array_map('wpjam_get_term', $terms) : $terms;
	}

	$args	= $args[0] ?? [];

	return wpjam_query('term', $vars, is_numeric($args)
		? ['depth'=>$args]
		: (is_bool($args) ? ['parse'=>$args] : (is_array($args) ? $args : []))
	);
}

function wpjam_get_all_terms($tax){
	return get_terms(['suppress_filter'=>true, 'taxonomy'=>$tax, 'hide_empty'=>false, 'orderby'=>'none', 'get'=>'all']);
}

function wpjam_get_term_thumbnail_url($term=null, $size='full', $crop=1){
	$term	= get_term($term ?? get_queried_object());
	$thumb	= $term ? (get_term_meta($term->term_id, 'thumbnail', true) ?: apply_filters('wpjam_term_thumbnail_url', '', $term)) : '';

	return $thumb ? wpjam_get_thumbnail($thumb, ($size ?: (wpjam_get_taxonomy_setting($term->taxonomy, 'thumbnail_size') ?: 'thumbnail')), $crop) : '';
}

if(!function_exists('get_term_taxonomy')){
	function get_term_taxonomy($id){
		return get_term_field('taxonomy', $id);
	}
}

if(!function_exists('get_term_level')){
	function get_term_level($id){
		return ($term = wpjam_trap('get_term', $id, null)) ? ($term->parent ? count(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) : 0) : null;
	}
}

if(!function_exists('get_term_depth')){
	function get_term_depth($id){
		if($tax	= wpjam_trap('get_term_taxonomy', $id, null)){
			$id		= get_term($id)->term_id;
			$max	= array_reduce(get_term_children($id, $tax), fn($m, $c)=> max($m, count(get_ancestors($c, $tax, 'taxonomy'))), 0);

			return $max ? $max - get_term_level($id) : 0;
		}
	}
}

// User
function wpjam_get_user($user, $size=96, $output=null){
	$object	= WPJAM_User::get_instance($user);

	return ($output ?? $size) === 'object' ? $object : wpjam_call_method($object, 'parse_for_json', $size);
}

function wpjam_get_user_object($user){
	return wpjam_get_user($user, 'object');
}

if(!function_exists('get_user_field')){
	function get_user_field($field, $user=null, $context='display'){
		return (($user = get_userdata($user)) && isset($user->$field)) ? sanitize_user_field($field, $user->$field, $user->ID, $context) : '';
	}
}

function wpjam_get_authors($args=[]){
	return WPJAM_User::get_authors($args);
}

function wpjam_get_current_user($required=false){
	$value	= wpjam_var('user', fn()=> apply_filters('wpjam_current_user', null));

	return $required ? (is_null($value) ? new WP_Error('bad_authentication') : $value) : wpjam_if_error($value, null);
}

// Bind
function wpjam_get_bind($type, $appid){
	return WPJAM_Bind::get($type.':'.$appid) ?: WPJAM_Bind::register((($model = $type.'_bind') && class_exists($model)
		? new $model($appid)
		: new WPJAM_Bind($type, $appid)
	));
}

// User Signup
function wpjam_register_user_signup($name, $args){
	return WPJAM_User_Signup::create($name, $args);
}

function wpjam_get_user_signups($args=[], $output='objects', $operator='and'){
	return WPJAM_User_Signup::get_registereds($args, $output, $operator);
}

function wpjam_get_user_signup($name){
	return WPJAM_User_Signup::get($name);
}

// Comment
if(!function_exists('get_comment_parent')){
	function get_comment_parent($comment_id){
		return ($comment = get_comment($comment_id)) ? $comment->comment_parent : null;
	}
}

// Widget
function wpjam_register_widget($id, $name, $options){
	wpjam_load('widgets_init',	fn()=> register_widget(new WPJAM_Widget($id, $name, $options)));
}

// Shortcode
function wpjam_do_shortcode($content, $tags){
	return ($tags = array_filter($tags, fn($t)=> str_contains($content, '['.$t)))
	? preg_replace_callback('/'.get_shortcode_regex($tags).'/', 'do_shortcode_tag', $content)
	: $content;
}

function wpjam_parse_shortcode_attr($str, $tag){
	return preg_match('/'.get_shortcode_regex((array)$tag).'/', $str, $m) ? shortcode_parse_atts($m[3]) : [];
}

// File
function wpjam_url($dir, $scheme=null){
	$path	= str_replace([rtrim(ABSPATH, '/'), '\\'], ['', '/'], $dir);

	return $scheme == 'relative' ? $path : site_url($path, $scheme);
}

function wpjam_dir($url){
	return ABSPATH.str_replace(site_url('/'), '', $url);
}

function wpjam_file(...$args){
	$object	= WPJAM_File::get_instance();

	return $args ? (method_exists($object, $args[0]) ? [$object, array_shift($args)] : $object)(...$args) : $object;
}

function wpjam_mimes($accept){
	return wpjam_file('mimes', $accept);
}

function wpjam_upload($name, $args=[]){
	return wpjam_file('upload', $name, $args);
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	return wpjam_file('download', $url, is_array($name) ? $name : compact('name', 'media', 'post_id'));
}

function wpjam_get_file_data($file, $type='data'){
	return wpjam_file('parse', $file, $type);
}

function wpjam_is_external_url($url, $scene=''){
	$host	= '//'.explode('//', site_url(), 2)[1];

	return apply_filters('wpjam_is_external_url', array_all(['http:', 'https:', ''], fn($v)=> !str_starts_with($url, $v.$host)), $url, $scene);
}

function wpjam_media($name, $args=[]){
	$result	= wpjam_upload($name ?: 'media', $args);
	$media	= $args['media'] ?? false;

	return [(($args['output'] ?? '') ?: 'url') => add_query_arg(
		wpjam_image($media ? $result : $result['file'], 'size') ?: [],
		$media ? wp_get_attachment_url($result) : $result['url']
	)];
}

function wpjam_image($img, $type=''){
	if($type == 'query'){
		return wpjam_map(wp_parse_args(parse_url($img, PHP_URL_QUERY)), fn($v, $k)=> in_array($k, ['width', 'height']) ? (int)$v : $v);
	}elseif($type == 'size'){
		$size	= wpjam_file($img, 'size', is_numeric($img) ? 'id' : (str_starts_with($img, 'http') ? 'url' : 'file'));

		return $size ? array_map('intval', $size)+['orientation'=> $size['height'] > $size['width'] ? 'portrait' : 'landscape'] : $size;
	}
}

function wpjam_is_image($img, $type=''){
	return ($type == 'id' || (!$type && is_numeric($img)))
	? wp_attachment_is_image($img)
	: preg_match('/\.('.implode('|', wp_get_ext_types()['image']).')$/i', wpjam_suffix(explode('?', $img)[0], '-', '#'));
}

function wpjam_fetch_external_images(&$urls, $post_id=0){
	$args	= ['post_id'=>$post_id, 'media'=>(bool)$post_id, 'field'=>'url'];
	$result	= wpjam_fill($urls, fn($v)=> $v && wpjam_is_external_url($v, 'fetch') ? wpjam_if_error(wpjam_download_url($v, $args), '') : '');
	$result	= array_filter($result);
	$urls	= array_keys($result);

	return array_values($result);
}

// 1. $img
// 2. $img, ['width'=>100, 'height'=>100]	// 这个为最标准版本
// 3. $img, 100x100
// 4. $img, 100
// 5. $img, [100,100]
// 6. $img, 100, 100, $crop=1
// 7. $img, [100,100], $crop=1
function wpjam_get_thumbnail($img, ...$args){
	$url	= ($img && is_numeric($img)) ? wp_get_attachment_url($img) : $img;
	$url	= $url ? remove_query_arg(['orientation', 'width', 'height'], wpjam_zh_urlencode($url)) : $url;
	$args	= count($args) > 1 && is_numeric($args[0]) ? [array_slice($args, 0, 2), ...array_slice($args, 2)] : $args;
	$size	= (!$args || !$args[0]) ? [] : (isset($args[1]) ? ['crop'=>$args[1]] : [])+wpjam_parse_size($args[0], $args[2] ?? 1);

	return $url ? apply_filters('wpjam_thumbnail', $url === 'args' ? '' : $url, $size) : '';
}

function wpjam_parse_size($size, $ratio=1){
	$keys	= ['width', 'height'];

	if(is_numeric($size)){
		$size	= [$size];
	}elseif(is_string($size)){
		$set	= wp_get_additional_image_sizes()[$size] ?? [];
		$size	= ($sep = $set ? '' : array_find(['*', 'x', 'X'], fn($v)=> str_contains($size, $v))) ? explode($sep, $size) : $size;
	}

	if(is_array($size)){
		$size	= wp_is_numeric_array($size) ? wpjam_array($keys, fn($i, $k)=>[$k, $size[$i] ?? 0]) : $size+array_fill_keys($keys, 0);
		$size	+= ['crop'=>array_all($keys, fn($k)=> $size[$k])];
	}else{
		$name	= $size == 'thumb' ? 'thumbnail' : (string)$size;
		$args	= ['thumbnail'=>[100, 100], 'medium'=>[300, 300], 'medium_large'=>[768, 0], 'large'=>[1024, 1024]][$name] ?? [];
		$size	= wpjam_map(
			[...($args ? ['size_w', 'size_h'] : $keys), 'crop'],
			fn($v, $k)=> $args ? (get_option($name.'_'.$v, null) ?? ($args[$k] ?? false)) : ($set[$v] ?? 0)
		);

		if($size[0] && !in_array($name, ['thumbnail', 'medium']) && ($cw = $GLOBALS['content_width'] ?? 0)){
			$size[0]	= min($cw*$ratio, $size[0]);
		}

		$size	= array_combine([...$keys, 'crop'], $size);
	}

	return wpjam_fill($keys, fn($k)=> (int)$size[$k]*$ratio)+$size;
}

function wpjam_constrain_dimensions($size, $max){
	$size	= wpjam_parse_size($size);

	if(($max[0] ?? '') && ($max[1] ?? '')){
		$max	= $size['width'] && $size['height'] ? wp_constrain_dimensions($size['width'], $size['height'], $max[0], $max[1]) : $max;
		$size	= array_merge($size, wpjam_fill(['width', 'height'], fn($k, $i)=> min($size[$k], $max[$i])));
	}

	return $size;
}

function wpjam_bits($str){
	return 'data:'.finfo_buffer(finfo_open(), $str, FILEINFO_MIME_TYPE).';base64, '.base64_encode($str);
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
	if($wrap === 'expandable'){
		return wpjam_expandable($text, ...$args);
	}

	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? $wrap($text, ...$args) : $text;
		$wrap	= '';
	}

	return ($text instanceof WPJAM_Tag ? $text : wpjam_tag('', [], $text))->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, ...$args){
	if($args && is_bool($args[0])){
		return WPJAM_Fields::parse($fields, ...$args);
	}

	$echo	= $args && $args[0] && wpjam_pull($args[0], 'echo', array_all(['data', 'value_callback', 'model', 'id'], fn($k)=> !array_key_exists($k, $args[0])));

	return wpjam_echo(WPJAM_Fields::create($fields, ...$args), $echo);
}

function wpjam_field($field, ...$args){
	if(is_array($field)){
		$args	= $args[0] ?? [];
		$echo	= wpjam_pull($field, 'echo') ?? wpjam_pull($args, 'echo');
		$wrap	= wpjam_pull($args, 'wrap_tag');
		$object	= WPJAM_Field::create($field);

		return wpjam_echo(isset($wrap) ? $object->wrap($wrap, $args) : ($args ? $object->render($args) : $object), (bool)$echo);
	}
}

function wpjam_parse_show_if($if){
	return wp_is_numeric_array($if) && count($if) >= 2
	? array_combine(
		count($if) == 2 ? ['key', 'value'] : ['key', 'compare', 'value'],
		count($if) > 3 ? array_slice($if, 0, 3) : $if
	)
	: (is_array($if) && !empty($if['key']) ? $if : null);
}

function wpjam_form($fields, ...$args){
	return wpjam_echo((is_object($fields) ? $fields : wpjam_fields($fields))->form(...$args), !is_array($args[0]));
}

function wpjam_button($object, $key=null, ...$args){
	if($key === 'next'){
		return [];
	}

	if(!$key && $object->next){
		$button	= ['next'=>'下一步'];
	}else{
		$button	= maybe_callback($object->submit_text, ...[...$args, $object->name]) ?? (wp_strip_all_tags($object->title) ?: wp_strip_all_tags($object->page_title));

		$button	= is_array($button) ? $button : [$object->name=>$button];
	}

	$button	= wpjam_map(array_filter($button), fn($v)=> is_array($v) ? $v : ['text'=>$v]);

	return $key
	? ($button[$key] ?? wp_die('无效的提交按钮'))
	: ($button ? wpjam_tag('p', ['submit'], implode(wpjam_map($button, fn($v, $k)=> get_submit_button($v['text'], $v['class'] ?? 'primary', $k, false)))) : wpjam_tag());
}

function wpjam_options($field, $args=[]){
	$type	= $args['type'] ??= (is_array($field) ? '' : 'select');
	$items	= wpjam_filter(is_array($field) ? $field : (wpjam($field) ?: []), $args['filter'] ?? []);

	return wpjam_reduce($items, function($carry, $item, $opt, $args){
		if(!is_array($item) && !is_object($item)){
			$carry[$opt]	= $item;
		}elseif(!isset($item['options'])){
			$title	= ($args['title_field'] ?? '') ?: 'title';
			$opt	= wpjam_get($item, ($args['name_field'] ?? '') ?: 'name') ?: $opt;

			$carry[$opt]	= (($args['type'] ?? '') == 'select'
				? wpjam_pick($item, [$title])
				: (($item['field'] ?? '') ?: [])+['label'=>($item[$title] ?? '')]
			)+wpjam_pick($item, ['label', 'image', 'description', 'alias', 'fields', 'show_if']);
		}

		return $carry;
	}, ($type == 'select' ? [''=>__('&mdash; Select &mdash;', 'wpjam')] : []), 'options', $args);
}

function wpjam_icon($icon){
	return (is_array($icon) || is_object($icon))
	? (($k	= wpjam_find(['dashicon', 'remixicon'], fn($k)=> wpjam_get($icon, $k))) ? wpjam_icon(wpjam_get($icon, $k)) : null)
	: (str_starts_with($icon, 'ri-') ? wpjam_tag('i', $icon) : wpjam_tag('span', ['dashicons', wpjam_prefix($icon, 'dashicons-')]));
}

function wpjam_pattern($key, ...$args){
	return $key ? wpjam('pattern', $key, ...($args ? [array_combine(['pattern', 'custom_validity'], $args)] : [])) : [];
}

// cache
function wpjam_cache($name, ...$args){
	return (!$args || is_array($args[0])) ? WPJAM_Cache::create($name, ...$args) : WPJAM_Cache::remember($name, ...$args);
}

function wpjam_counts($name, $cb){
	return WPJAM_Cache::remember($name, 'counts', $cb);
}

function wpjam_transient($name, $cb, $args=[], $global=false){
	return WPJAM_Cache::remember($name, (bool)$global, $cb, $args);
}

function wpjam_increment($name, $max=0, $expire=86400, $global=false){
	return wpjam_transient($name, fn($v)=> ($max && (int)$v >= $max) ? 1 : (int)$v+1, ['expire'=>$expire, 'force'=>'set'], $global);
}

function wpjam_lock($name, $expire=10, $group=false){
	$group	= is_bool($group) ? ($group ? 'site-' : '').'transient' : ($group ?: 'default');

	return $expire == -1
	? wp_cache_delete($name, $group)
	: (wp_cache_get($name, $group, true) || !wp_cache_add($name, 1, $group, $expire));
}

function wpjam_remaining($group, $max, $time, $count=true){
	$group	= explode(':', $group, 2);
	$name	= $group[1] ?? wpjam_visitor($group[0]);
	$group	= $group[0].'_limit';
	$times	= wp_cache_get($name, $group) ?: 0;

	if(($rest = $max - $times) > 0){
		$count && wp_cache_set($name, $times+1, $group, ($times+1 == $max && $time > 60) ? $time : 60);

		return $rest;
	}

	return 0;
}

function wpjam_visitor($context=''){
	return apply_filters('wpjam_visitor', '', $context)
	?: (function_exists('is_user_logged_in') && is_user_logged_in() ? 'user:'.get_current_user_id() : 'ip:'.wpjam_get_ip());
}

// Code
function wpjam_generate_verification_code($key, $group='default'){
	return WPJAM_Cache::verification($group, $key);
}

function wpjam_verify_code($key, $code, $group='default'){
	return WPJAM_Cache::verification($group, $key, $code);
}

// Deleted ids
function wpjam_deleted_ids($name, ...$args){
	return WPJAM_Cache::deleted_ids($name, ...$args);
}

function wpjam_max_id($name, $clean=false){
	return WPJAM_Cache::table($name, 'max_id', $clean);
}

function wpjam_table_status($name, $clean=false){
	return WPJAM_Cache::table($name, 'status', $clean);
}

// LazyLoader
function wpjam_lazyloader($name, ...$args){
	$object	= WPJAM_Lazyloader::get_instance();

	return in_array($name, ['queue', 'load']) ? $object->$name(...$args) : $object->loader($name, ...$args);
}

function wpjam_lazyload($name, $ids){
	return wpjam_lazyloader('queue', $name, $ids);
}

function wpjam_load_pending($name, $cb){
	wpjam_lazyloader('load', $name, $cb);
}

// Notice
function wpjam_add_admin_notice($notice, $blog_id=0){
	return WPJAM_Notice::add($notice, 'admin', $blog_id);
}

function wpjam_add_user_notice($user_id, $notice){
	return WPJAM_Notice::add($notice, 'user', $user_id);
}

