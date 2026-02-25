<?php
// register
function wpjam_register($group, $name, $args=[]){
	return $group && $name ? WPJAM_Register::group($group.':add_object', $name, $args) : null;
}

function wpjam_unregister($group, $name){
	$group && $name && WPJAM_Register::group($group.':remove_object', $name);
}

function wpjam_get_registered($group, $name){
	return $group && $name ? WPJAM_Register::group($group.':get_object', $name) : null;
}

function wpjam_get_registereds($group, $args=[]){
	return $group ? WPJAM_Register::group($group.':get_objects', $args) : [];
}

function wpjam_args($args=[]){
	return new WPJAM_Args($args);
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

function wpjam_get_current_platform($args=[], $output='name'){
	$args	= ($output == 'bit' && $args && wp_is_numeric_array($args)) ? ['bit'=>$args] : ($args ?: ['path'=>true]);
	$object	= array_find(WPJAM_Platform::get_by($args), fn($v)=> $v && $v->verify());

	return ($output == 'object' || !$object) ? $object : $object->{$output == 'bit' ? 'bit' : 'name'};
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
		return $object ? array_Values(wpjam_map($object->get_page(), fn($page, $pk)=> ['page'=>$page, 'page_key'=>$pk])) : [];
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
	return WPJAM_Platform::call_platforms('get_fields', $platforms, $args);
}

function wpjam_parse_path_item($item, $platform=null, $suffix=''){
	return WPJAM_Platform::call_platforms('parse_item', $platform, $item, $suffix);
}

function wpjam_validate_path_item($item, $platforms, $suffix=''){
	return WPJAM_Platform::call_platforms('validate_item', $platforms, $item, $suffix);
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type($name, $args=[]){
	return WPJAM_Data_Type::get_instance($name, $args);
}

function wpjam_get_post_id_field($post_type='post', $args=[]){
	return WPJAM_Post::get_field(['post_type'=> $post_type]+$args);
}

// Option
function wpjam_option($option, ...$args){
	return WPJAM_Option_Setting::get_instance($option, ...$args);
}

function wpjam_get_option($option, $blog_id=0){
	return wpjam_option($option)->get_blog_option($blog_id);
}

function wpjam_update_option($option, $value, $blog_id=0){
	return wpjam_option($option)->update_blog_option($blog_id, $value);
}

function wpjam_get_site_option($option){
	return wpjam_option($option)->get_site_option();
}

function wpjam_update_site_option($option, $value){
	return wpjam_option($option)->update_site_option($value);
}

function wpjam_get_setting($option, $name, $blog_id=0){
	return wpjam_option($option)->get_blog_setting($blog_id, $name);
}

function wpjam_update_setting($option, $name, $value='', $blog_id=0){
	return wpjam_option($option)->update_blog_setting($blog_id, $name, $value);
}

function wpjam_delete_setting($option, $name, $blog_id=0){
	return wpjam_option($option)->delete_blog_setting($blog_id, $name);
}

function wpjam_get_site_setting($option, $name){
	return wpjam_option($option)->get_site_setting($name);
}

function wpjam_register_option($option, $args=[]){
	return WPJAM_Option_Setting::create($option, $args);
}

function wpjam_add_option_section($option, ...$args){
	return wpjam_option($option)->add_section(...$args);
}

// JSON
function wpjam_register_json($name, $args=[]){
	return WPJAM_JSON::register($name, $args);
}

function wpjam_register_api($name, $args=[]){
	return wpjam_register_json($name, $args);
}

function wpjam_get_json(...$args){
	if(!$args || in_array($args[0], ['name', 'object'])){
		$name	= WPJAM_JSON::get_current();

		if(!$args || $args[0] === 'name'){
			return $name;
		} 
	}else{
		$name	= $args[0] ?? '';
	}

	return WPJAM_JSON::get($name);
}

function wpjam_parse_json_module($module){
	return wpjam_catch(['WPJAM_JSON_Module', 'parse'], $module);
}

function wpjam_is_json_request(){
	return get_option('permalink_structure') ? (bool)preg_match("/\/api\/.*\.json/", $_SERVER['REQUEST_URI']) : (($_GET['module'] ?? '') == 'json');
}

function wpjam_json_source($name, $cb, $query_args=['source_id']){
	($name == wpjam_get_parameter('source')) && add_filter('wpjam_pre_json', fn($pre)=> is_array($result = $cb(wpjam_get_parameter($query_args))) ? $result : $pre);
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
	return ($object = wpjam_get_meta_type($type)) ? $object->call_option('register', $name, $args) : null;
}

function wpjam_unregister_meta_option($type, $name){
	return ($object = wpjam_get_meta_type($type)) ? $object->call_option('unregister', $name) : null;
}

function wpjam_get_meta_options($type, $args=[]){
	return ($object = wpjam_get_meta_type($type)) ? $object->get_options($args) : [];
}

function wpjam_get_meta_option($type, $name, $output='object'){
	$option	= ($object = wpjam_get_meta_type($type)) ? $object->call_option('get', $name) : null;

	return $output == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($type, $key, $value=null, $column=null){
	return ($object = wpjam_get_meta_type($type)) ? $object->get_by_key($key, $value, $column) : [];
}

function wpjam_get_metadata($type, $object_id, ...$args){
	return ($object = wpjam_get_meta_type($type)) ? $object->get_data_with_default($object_id, ...$args) : null;
}

function wpjam_update_metadata($type, $object_id, $key, ...$args){
	return ($object = wpjam_get_meta_type($type)) ? $object->update_data_with_default($object_id, $key, ...$args) : null;
}

function wpjam_delete_metadata($type, $object_id, $key){
	return ($object = wpjam_get_meta_type($type)) && $key && array_map(fn($k)=> $object->delete_data($object_id, $k), (array)$key) || true;
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, $args+['_jam'=>true]);
}

function wpjam_get_post_type_object($name){
	return WPJAM_Post_Type::get(is_numeric($name) ? get_post_type($name) : $name);
}

function wpjam_add_post_type_field($post_type, $key, ...$args){
	$args	= is_array($key) ? $key : (is_callable($key) ? [$key] : [$key => $args[0]]);

	return (WPJAM_Post_Type::get($post_type))->process_arg('_fields', fn($v)=> array_merge($v ?: [], $args));
}

function wpjam_remove_post_type_field($post_type, $key){
	(WPJAM_Post_Type::get($post_type))->delete_arg('_fields['.$key.']');
}

function wpjam_get_post_type_setting($post_type, $key, $default=null){
	return ($object = WPJAM_Post_Type::get($post_type)) ? ($object->$key ?? $default) : $default;
}

function wpjam_update_post_type_setting($post_type, $key, $value){
	($object = WPJAM_Post_Type::get($post_type)) && ($object->$key	= $value);
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
	return wpjam_get_meta_options('post', array_filter(['post_type'=>$post_type])+$args);
}

function wpjam_get_post_option($name, $output='object'){
	return wpjam_get_meta_option('post', $name, $output);
}

// Post Column
function wpjam_register_posts_column($name, ...$args){
	return is_admin() ? wpjam_register_list_table_column($name, ['data_type'=>'post_type']+(is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)])) : null;
}

// Post
function wpjam_post($post, $wp_error=false){
	return WPJAM_Post::get_instance($post, null, $wp_error);
}

function wpjam_get_post_object($post, $post_type=null){
	return $post ? WPJAM_Post::get_instance($post, $post_type) : null;
}

function wpjam_validate_post($value, $post_type=null){
	return WPJAM_Post::validate($value, $post_type);
}

function wpjam_get_post($post, $args=[]){
	return ($object = WPJAM_Post::get_instance($post)) ? $object->parse_for_json($args) : null;
}

function wpjam_get_posts($vars, ...$args){
	[$args, $parse]	= $args && is_array($args[0]) ? [$args[0], true] : [[], $args[0] ?? false];

	if(is_scalar($vars) || wp_is_numeric_array($vars)){
		$ids	= wp_parse_id_list($vars);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? wpjam_array($ids, fn($k, $v)=> [null, wpjam_get_post($v, $args) ?: null], true) : $posts;
	}

	return WPJAM_Query::parse($vars, ['parse'=>$parse]+$args);
}

function wpjam_get_post_views($post=null){
	return ($post = get_post($post)) ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	return ($post = get_post($post)) ? wpjam_tap(wpjam_get_post_views($post)+$offset, fn($views)=> update_post_meta($post->ID, 'views', $views)) : false;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	if(!($post = get_post($post)) || $post->post_excerpt){
		return $post ? wp_strip_all_tags($post->post_excerpt, true) : '';
	}

	$excerpt	= is_serialized($post->post_content) ? '' : wpjam_call_with_suppress(fn()=> wpjam_get_post_content($post), [
		['the_content', 'wp_filter_content_tags', 12],
		['the_content', 'do_blocks', 9],
		['the_content', 'do_shortcode', 11]
	]);

	return mb_strimwidth(
		wp_strip_all_tags(excerpt_remove_footnotes(excerpt_remove_blocks(strip_shortcodes($excerpt))), true),
		0,
		($length ?: apply_filters('excerpt_length', 200)),
		($more ?? apply_filters('excerpt_more', ' &hellip;')),
		'utf-8'
	);
}

function wpjam_get_post_content($post=null, $raw=false){
	$content	= ($post = get_post($post)) ? get_the_content('', false, $post) : '';

	return (!$post || $raw) ? $content : apply_filters('the_content', str_replace(']]>', ']]&gt;', $content));
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

	if(!$images || $args === false){
		return $images ?: [];
	}

	$args	= wp_parse_args($args, ['large_size'=>'', 'thumbnail_size'=>'', 'full_size'=>true]);

	foreach(['large', 'thumbnail'] as $k){
		$v	= $args[$k.'_size'];

		if($v !== false){
			continue;
		}

		if(!$v){
			if($setting	= wpjam_get_post_type_setting($post->post_type, 'images_sizes')){
				$i	= $k == 'large' ? 0 : 1;
				$v	= $setting[$i];

				if($i && count($images) == 1){
					$image	= array_first($images);
					$query	= wpjam_parse_image_query($image);

					if(!$query){
						$query	= wpjam_get_image_size($image, 'url') ?: ['width'=>0, 'height'=>0];

						update_post_meta($post->ID, 'images', [add_query_arg($query, $image)]);
					}

					if(!empty($query['orientation'])){
						$i	= $query['orientation'] == 'landscape' ? 2 : 3;
						$v	= $setting[$i] ?? $v;
					}
				}
			}else{
				$v	= wpjam_get_post_type_setting($post->post_type, $k.'_size');
			}
		}

		$sizes[$k]	= $v ?: $k;
	}

	if(empty($sizes) || $args['full_size']){
		$sizes['full']	= 'full';
	}

	foreach($images as $image){
		$parsed	= array_map(fn($s)=> wpjam_get_thumbnail($image, $s), $sizes);
		$parsed	= array_merge($parsed, ($query	= wpjam_parse_image_query($image)) ? wpjam_pick($query, ['orientation', 'width', 'height'])+['width'=>0, 'height'=>0] : []);

		if(isset($sizes['thumbnail'])){
			$size	= wpjam_parse_size($sizes['thumbnail']);
			$parsed	= array_reduce(['width', 'height'], fn($c, $k)=> $c+['thumbnail_'.$k=>$size[$k] ?? 0], $parsed);
		}

		$results[]	= count($sizes) == 1 ? array_first($parsed) : $parsed;
	}

	return $results;
}

// Query
function wpjam_query($vars, ...$args){
	if(is_string($vars) && in_array($vars, ['parse', 'render'], true)){
		$action	= $vars;
		$vars	= array_shift($args);
	}else{
		$action	= count($args) >= 2 ? ($args[1] ? 'parse' : 'render') : '';
	}

	$args	= ($args[0] ?? [])+($action ? ['list_query'=>true] : []);

	return ($vars || !$action) ? ['WPJAM_Query', $action ?: 'query']($vars, $args) : ($action == 'parse' ? [] : '');
}

function wpjam_parse_query_vars($vars, $param=false){
	return WPJAM_Query::parse_vars($vars, $param);
}

function wpjam_get_query_var($key, $wp=null){
	return ($wp ?: $GLOBALS['wp'])->query_vars[$key] ?? null;
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query($post, $args=null){
	if(is_null($args)){
		$args	= ['number'=>$post];
		$post	= null;
	}

	return wpjam_query(['post'=>$post, 'related_query'=>true], $args);
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	return wpjam_query(['post'=>$post, 'related_query'=>true], $args, $parse);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_query(['posts_per_page'=>5, 'orderby'=>'date'], $args, $parse);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_query(['posts_per_page'=>5, 'orderby'=>'meta_value_num', 'meta_key'=>'views'], $args, $parse);
}

function wpjam_pagenavi($total=0, $display=true){
	$result	= '<div class="pagenavi">'.paginate_links(array_filter(['prev_text'=>'&laquo;', 'next_text'=>'&raquo;', 'total'=>$total])).'</div>';

	return $display ? wpjam_echo($result) : $result;
}

// Taxonomy
function wpjam_register_taxonomy($name, ...$args){
	return WPJAM_Taxonomy::register($name, (count($args) == 2 ? ['object_type'=>$args[0]]+$args[1] : $args[0])+['_jam'=>true]);
}

function wpjam_get_taxonomy($name){
	return WPJAM_Taxonomy::get(is_numeric($name) ? get_term_field('taxonomy', $id) : $name);
}

function wpjam_add_taxonomy_field($taxonomy, $key, ...$args){
	$args	= is_array($key) ? $key : (is_callable($key) ? [$key] : [$key => $args[0]]);

	return (WPJAM_Taxonomy::get($taxonomy))->process_arg('_fields', fn($v)=> array_merge($v ?: [], $args));
}

function wpjam_remove_taxonomy_field($taxonomy, $key){
	(WPJAM_Taxonomy::get($taxonomy))->delete_arg('_fields['.$key.']');
}

function wpjam_get_taxonomy_setting($taxonomy, $key, $default=null){
	return ($object = WPJAM_Taxonomy::get($taxonomy)) ? ($object->$key ?? $default) : $default;
}

function wpjam_update_taxonomy_setting($taxonomy, $key, $value){
	($object = WPJAM_Taxonomy::get($taxonomy)) && ($object->$key = $value);
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
	return wpjam_get_meta_options('term', array_filter(['taxonomy'=>$taxonomy])+$args);
}

function wpjam_get_term_option($name, $output='object'){
	return wpjam_get_meta_option('term', $name, $output);
}

// Term Column
function wpjam_register_terms_column($name, ...$args){
	return is_admin() ? wpjam_register_list_table_column($name, array_merge(is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>$args[1] ?? null], ['data_type'=>'taxonomy'])) : null;
}

// Term
function wpjam_term($term, $wp_error=false){
	return WPJAM_Term::get_instance($term, null, $wp_error);
}

function wpjam_get_term_object($term, $taxonomy=''){
	return WPJAM_Term::get_instance($term, $taxonomy);
}

function wpjam_validate_term($value, $taxonomy=null){
	return WPJAM_Term::validate($value, $taxonomy);
}

function wpjam_get_term($term, $args=[]){
	[$tax, $args]	= is_array($args) ? [wpjam_pull($args, 'taxonomy'), $args] : [$args, []];

	return ($object = WPJAM_Term::get_instance($term, $tax, false)) ? $object->parse_for_json($args) : null;
}

// $vars, $max_depth
// $term_ids, $args
function wpjam_get_terms($vars, ...$args){
	if(is_scalar($vars) || wp_is_numeric_array($vars)){
		$terms	= WPJAM_Term::get_by_ids(wp_parse_id_list($vars));

		return $args && $args[0] ? array_map('wpjam_get_term', $terms) : $terms;
	}

	$arg	= $args[0] ?? [];
	$args	= (is_numeric($arg) ? ['depth'=>$arg] : (is_bool($arg) ? ['parse'=>$arg] : (is_array($arg) ? $arg : [])))+wpjam_pull($vars, ['format', 'parse']);

	return WPJAM_Query::parse($vars, $args, 'term');
}

function wpjam_get_all_terms($taxonomy){
	return get_terms(['suppress_filter'=>true, 'taxonomy'=>$taxonomy, 'hide_empty'=>false, 'orderby'=>'none', 'get'=>'all']);
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
			$max	= array_reduce(get_term_children($id, $tax), fn($max, $child)=> max($max, count(get_ancestors($child, $tax, 'taxonomy'))), 0);

			return $max ? $max - get_term_level($id) : 0;
		}
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
		return (($user = get_userdata($user)) && isset($user->$field)) ? sanitize_user_field($field, $user->$field, $user->ID, $context) : '';
	}
}

function wpjam_get_authors($args=[]){
	return WPJAM_User::get_authors($args);
}

// Bind
function wpjam_register_bind($type, $appid, $args){
	return wpjam_get_bind($type, $appid) ?: WPJAM_Bind::create($type, $appid, $args);
}

function wpjam_get_bind($type, $appid){
	return WPJAM_Bind::get($type.':'.$appid);
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

// Shortcode
function wpjam_do_shortcode($content, $tags){
	$tags	= array_filter($tags, fn($t)=> str_contains($content, '['.$t));

	return $tags ? preg_replace_callback('/'.get_shortcode_regex($tags).'/', 'do_shortcode_tag', $content) : $content;
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

function wpjam_file($value, $to, $from=null){
	$from	??= is_numeric($value) ? 'id' : 'file';

	if($from == $to){
		return $value;
	}

	if($from == 'id'){
		return wpjam_get_attachment_value($value, $to);
	}

	$dir	= wp_get_upload_dir();

	if($from == 'file'){
		if($to == 'size'){
			if(file_exists($value) && ($size = wp_getimagesize($value))){
				return ['width'=>$size[0], 'height'=>$size[1]];
			}

			return wpjam_get_attachment_value(wpjam_file($value, 'id', $from), 'size');
		}elseif($to == 'ext'){
			return pathinfo($value, PATHINFO_EXTENSION);
		}

		$base	= $dir['basedir'];
	}elseif($from == 'url'){
		$value	= parse_url($value, PHP_URL_PATH);
		$base	= parse_url($dir['baseurl'], PHP_URL_PATH);
	}elseif($from == 'path'){
		$path	= $value;
	}

	if(!empty($base)){
		$path	= str_starts_with($value, $base) ? substr($value, strlen($base)) : null;
	}

	if($to == 'path' || empty($path)){
		return $path ?? null;
	}elseif($to == 'id'){
		return wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id');
	}elseif($to == 'url'){
		return $dir['baseurl'].$path;
	}elseif(in_array($to, ['size', 'ext', 'file'])){
		return wpjam_file($dir['basedir'].$path, $to, 'file');
	}
}

function wpjam_get_attachment_value($id, $field='file'){
	if($id && get_post_type($id) == 'attachment'){
		if($field == 'url'){
			return wp_get_attachment_url($id);
		}

		if(in_array($field, ['meta', 'size'])){
			$meta	= wp_get_attachment_metadata($id);
			$meta	= is_array($meta) ? $meta : [];

			return $field == 'meta' ? $meta : wpjam_pick($meta, ['width', 'height']);
		}

		return wpjam_file(get_attached_file($id, true), $field, 'file');
	}
}

function wpjam_restore_attachment_file($id, $url=''){
	$file = wpjam_get_attachment_value($id, 'file');

	if($file && !file_exists($file)){
		is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
		
		wpjam_remote_request($url ?: wpjam_get_attachment_value($id, 'url'), ['throw'=>true, 'stream'=>true, 'filename'=>$file]);

		return $file;
	}

	return $file;
}

function wpjam_add_to_media($file, $args=[]){
	require_once ABSPATH.'wp-admin/includes/image.php';

	$meta	= wp_read_image_metadata($file);
	$title	= $meta ? trim($meta['title']) : '';
	$title	= ($title && !is_numeric(sanitize_title($title))) ? $title : preg_replace('/\.[^.]+$/', '', wp_basename($file));
	$pid	= $args['post_id'] ?? 0;
	$id		= wpjam_try('wp_insert_attachment', [
		'post_title'		=> $title,
		'post_content'		=> $meta ? (trim($meta['caption']) ?: '') : '',
		'post_parent'		=> $pid,
		'post_mime_type'	=> $args['type'] ?? mime_content_type($file),
		'guid'				=> $args['url'] ?? wpjam_file($file, 'url'),
	], $file, $pid, true);

	return [$id, wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file))][0];
}

function wpjam_upload($name, $args=[]){
	require_once ABSPATH.'wp-admin/includes/file.php';

	if($bits = wpjam_pull($args, 'bits')){
		if(preg_match('/data:image\/([^;]+);base64,/i', $bits, $m)){
			$bits	= base64_decode(trim(substr($bits, strlen($m[0]))));
			$name	= explode_last('.', $name)[0].'.'.$m[1];
		}

		$upload	= wp_upload_bits($name, null, $bits);
	}else{
		if(!empty($args['accept'])){
			$args['mimes']	= wpjam_accept_to_mime_types($args['accept']);
			$args['mimes'] || wpjam_throw('upload_error', '无效的文件类型');
		}

		$args 	+= ['test_form'=>false];
		$upload	= is_array($name) ? wp_handle_sideload($name, $args) : wp_handle_upload($_FILES[$name], $args);
	}

	return empty($upload['error']) ? $upload+['path'=>wpjam_file($upload['file'], 'path')] : wpjam_throw('upload_error', $upload['error']);
}

function wpjam_upload_bits($bits, $name, $media=true){
	$upload	= wpjam_upload($name, ['bits'=>$bits]);

	return $media ? wpjam_add_to_media($upload['file'], $upload+['post_id'=>is_numeric($media) ? $media : 0]) : $upload;
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	$args	= is_array($name) ? $name : compact('name', 'media', 'post_id');
	$media	= $args['media'] ?? false;
	$field	= ($args['field'] ?? '') ?: ($media ? 'id' : 'file');
	$id		= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

	if(!$id || get_post_type($id) != 'attachment'){
		try{
			$tmp	= wpjam_try('download_url', $url);
			$upload	= ['name'=>($args['name'] ?? '') ?: md5($url).'.'.wpjam_at(wp_get_image_mime($tmp), '/', 1), 'tmp_name'=>$tmp];

			if(!$media){
				return wpjam_upload($upload)[$field];
			}

			$id	= wpjam_try('media_handle_sideload', $upload, ($args['post_id'] ?? 0));

			update_post_meta($id, 'source_url', $url);
		}catch(Exception $e){
			@unlink($tmp);

			return wpjam_catch($e);
		}
	}

	return wpjam_get_attachment_value($id, $field);
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

function wpjam_get_thumbnail_args($size){
	return wpjam_get_thumbnail('args', $size);
}

// $size, $ratio
// $size, $ratio, [$max_width, $max_height]
// $size, [$max_width, $max_height]
function wpjam_parse_size($size, ...$args){
	[$ratio, $max]	= $args ? (is_array($args[0]) ? [1, $args[0]] : [(int)$args[0], []]) : [1, []];

	if(is_array($size)){
		$size	= wp_is_numeric_array($size) ? ['width'=>($size[0] ?? 0), 'height'=>($size[1] ?? 0)] : $size;
	}elseif(is_numeric($size ?: 0)){
		$size	= ['width'=>$size, 'height'=>0];
	}else{
		$name	= $size == 'thumb' ? 'thumbnail' : $size;
		$set	= wp_get_additional_image_sizes()[$name] ?? [];

		if(!$set && ($sep = array_find(['*', 'x', 'X'], fn($v)=> str_contains($name, $v)))){
			[$width, $height]	= explode($sep, $name)+[0,0];
		}else{
			if($default	= ['thumbnail'=>[100, 100], 'medium'=>[300, 300], 'medium_large'=>[768, 0], 'large'=>[1024, 1024]][$name] ?? ''){
				$crop	= $name == 'thumbnail' ? get_option($name.'_crop') : false;

				[$width, $height]	= wpjam_map(['w', 'h'], fn($v, $k)=> get_option($name.'_size_'.$v) ?: $default[$k]);
			}else{
				[$width, $height, $crop]	= $set ? [$set['width'], $set['height'], $set['crop']] : [0, 0, false];
			}

			if($width && !empty($GLOBALS['content_width']) && !in_array($name, ['thumbnail', 'medium'])){
				$width	= min($GLOBALS['content_width']*$ratio, $width);
			}
		}

		$size	= ['width'=>$width, 'height'=>$height];
	}

	$size	= array_merge($size, wpjam_fill(['width', 'height'], fn($k)=>(int)($size[$k] ?? 0)*$ratio));
	$size	+= ['crop'=>$crop ?? ($size['width'] && $size['height'])];

	if(count($max) >= 2 && $max[0] && $max[1]){
		$max	= ($size['width'] && $size['height']) ? wp_constrain_dimensions($size['width'], $size['height'], $max[0], $max[1]) : $max;
		$size	= array_merge($size, wpjam_fill(['width', 'height'], fn($k, $i)=> min($size[$k], $max[$i])));
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
	$host	= '//'.explode('//', site_url(), 2)[1];

	return apply_filters('wpjam_is_external_url', array_all(['http:', 'https:', ''], fn($v)=> !str_starts_with($url, $v.$host)), $url, $scene);
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

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

function wpjam_accept_to_mime_types($accept){
	$allowed	= get_allowed_mime_types();
	$types		= [];

	foreach(wpjam_lines($accept ?: '', ',', fn($v)=> strtolower($v)) as $v){
		if(str_ends_with($v, '/*')){
			$prefix	= substr($v, 0, -1);
			$types	+= array_filter($allowed, fn($m)=> str_starts_with($m, $prefix));
		}elseif(str_contains($v, '/')){
			$ext	= array_search($v, $allowed);
			$types	+= $ext ? [$ext => $v] : [];
		}elseif(($v = ltrim($v, '.')) && preg_match('/^[a-z0-9]+$/', $v)){
			$ext	= array_find_key($allowed, fn($m, $ext)=> str_contains($ext, '|') ? in_array($v, explode('|', $ext)) : $v == $ext);
			$types	+= $ext ? wpjam_pick($allowed, [$ext]) : [];
		}
	}

	return $types;
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
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, ...$args){
	if($args && !is_array($args[0])){
		if(is_bool($args[0])){
			return WPJAM_Fields::parse($fields, ...$args);
		}

		$result	= [];

		foreach($fields as $key => $field){
			if(is_callable($field)){
				$field	= wpjam_try($field, ...$args);
				$field	= is_numeric($key) ? $field : [$key => $field];
			}else{
				$field	= wpjam_is_assoc_array($field) ? [$key => $field] : [];
			}

			$result	= array_merge($result, $field);
		}

		return $result;
	}

	$args	= $args[0] ?? [];
	$echo	= $args ? wpjam_pull($args, 'echo', array_all(['data', 'value_callback', 'model', 'id'], fn($k)=> !array_key_exists($k, $args))) : false;	
	$object	= WPJAM_Fields::create($fields, $args);

	return $echo ? wpjam_echo($object) : $object;	
}

function wpjam_field($field, ...$args){
	if(is_array($field)){
		$args	= $args[0] ?? [];
		$echo	= wpjam_pull($field, 'echo') ?? wpjam_pull($args, 'echo');
		$object	= WPJAM_Field::create($field);
		$wrap	= wpjam_pull($args, 'wrap_tag'); 
		$result	= isset($wrap) ? $object->wrap($wrap, $args) : ($args ? $object->render($args) : $object);

		return $echo ? wpjam_echo($result) : $result;
	}
}

function wpjam_parse_show_if($if){
	if(wp_is_numeric_array($if) && count($if) >= 2){
		$keys	= count($if) == 2 ? ['key', 'value'] : ['key', 'compare', 'value'];

		if(count($if) > 3){
			if(is_array($if[3])){
				$args	= $if[3];

				trigger_error(var_export($args, true));	// del 2025-12-30
			}

			$if	= array_slice($if, 0, 3);
		}

		return array_combine($keys, $if)+($args ?? []);
	}elseif(is_array($if) && !empty($if['key'])){
		return $if;
	}
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

	if($key){
		return $button[$key] ?? wp_die('无效的提交按钮');
	}

	return $button ? wpjam_tag('p', ['submit'], implode(wpjam_map($button, fn($v, $k)=> get_submit_button($v['text'], $v['class'] ?? 'primary', $k, false)))) : wpjam_tag();
}

function wpjam_form($fields, ...$args){
	if(is_array($args[0])){
		return (is_object($fields) ? $fields : wpjam_fields($fields))->wrap('form', ...$args);
	}

	echo '<form method="post" action="'.$args[0].'" enctype="multipart/form-data" id="form">';
	echo wpjam_fields($fields);
	wp_nonce_field($args[1] ?? '');
	wp_original_referer_field(true, 'previous');

	($args[2] ??= '') !== false && submit_button($args[2]);

	echo '</form>';
}

function wpjam_icon($icon){
	if(is_array($icon) || is_object($icon)){
		return ($k	= wpjam_find(['dashicon', 'remixicon'], fn($k)=> wpjam_get($icon, $k))) ? wpjam_icon(wpjam_get($icon, $k)) : null;
	}

	return str_starts_with($icon, 'ri-') ? wpjam_tag('i', $icon) : wpjam_tag('span', ['dashicons', (str_starts_with($icon, 'dashicons-') ? '' : 'dashicons-').$icon]);
}

// AJAX
function wpjam_ajax($name, $args=[]){
	if(wpjam('ajax', $name)){
		return ($attr = WPJAM_AJAX::get_attr($name, $args)) ? wpjam_attr($attr, 'data') : null;
	}elseif(empty($args['admin']) || wp_doing_ajax()){
		return WPJAM_AJAX::create($name, $args);
	}
}

// Notice
function wpjam_add_admin_notice($notice, $blog_id=0){
	return WPJAM_Notice::add($notice, 'admin', $blog_id);
}

function wpjam_add_user_notice($user_id, $notice){
	return WPJAM_Notice::add($notice, 'user', $user_id);
}

function wpjam_notice(&$data){
	if(is_array($data)){
		if(!empty($data['errcode']) && !empty($data['errmsg'])){
			return wpjam_pull($data, ['errcode', 'errmsg']);
		}

		return ['notice'=>wpjam_pull($data, 'notice') ?: (($errmsg = wpjam_pull($data, 'errmsg')) && $errmsg != 'ok' ? $errmsg : '')];	// 第三方接口可能返回 ok
	}
}

// translate
function wpjam_translate($text, ...$args){
	if(($domain = wpjam_at($args, -1)) != 'default'){
		$cb	= 'translate'.(count($args) >= 2 ? '_with_gettext_context' : '');

		if($domain === 'wpjam' && ($low = strtolower($text)) !== $text && count(explode(' ', $text)) <= 2 && ($trans = $cb($low, ...$args)) != $low){
			return $trans;
		}

		return $cb($text, ...array_slice($args, 0, -1));
	}

	return $text;
}

// deleted ids
function wpjam_deleted_ids($name, ...$args){
	$key	= 'deleted_ids:'.$name;
	$items	= get_transient($key) ?: [];

	if($args && $args[0]){
		if(is_string($args[0]) && str_starts_with($args[0], '-')){
			wpjam_max_id($name, 'clean');

			$id		= (int)substr($args[0], 1);
			$update	= in_array($id, $items) ? array_diff($items, [$id]) : null;
		}else{
			$ids	= array_diff((array)$args[0], $items);
			$ids	= $ids && ($max = wpjam_max_id($name)) ? array_filter($ids, fn($id)=> $id < $max) : $ids;
			$update	= $ids ? array_merge($items, $ids) : null;
		}

		isset($update) && ($items = array_values($update)) && set_transient($key, $items, 30);
	}

	return $items;
}

function wpjam_max_id($name, $clean=false){
	$table	= in_array($name, ['post', 'term', 'user']) ? $GLOBALS['wpdb']->{$name.'s'} : $name;

	return $clean ? wpjam_table_status($table, true) : (($status = wpjam_table_status($table)) ? $status->Auto_increment : null);
}

function wpjam_table_status($table, $clean=false){
	return wpjam_transient('table_status:'.$table, $clean ? false : fn()=> $GLOBALS['wpdb']->get_row("SHOW TABLE STATUS LIKE '{$table}'"), 60);
}