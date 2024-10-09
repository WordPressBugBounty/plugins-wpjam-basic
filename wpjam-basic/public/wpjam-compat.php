<?php
class_alias('WPJAM_Verify_TXT', 'WPJAM_VerifyTXT');
class_alias('WPJAM_Option_Items', 'WPJAM_Option');
class_alias('WPJAM_Items', 'WPJAM_Item');
class_alias('WPJAM_Post', 'WPJAM_PostType');
class_alias('WPJAM_Cache_Items', 'WPJAM_List_Cache');
class_alias('WPJAM_Cache_Items', 'WPJAM_ListCache');
class_alias('WPJAM_Cache', 'WPJAM_Cache_Group');
class_alias('WPJAM_Crypt', 'WPJAM_OPENSSL_Crypt');

if(!function_exists('function_alias')){
	function function_alias($original, $alias){
		if(!function_exists($original) || function_exists($alias)){
			return false;
		}

		eval('function '.$alias.'(...$args){
			return '.$original.'(...$args);
		}');

		return true;
	}
}

if(!function_exists('clamp')){
	function clamp($value, $min, $max){
		return min(max($value, $min), $max);
	}
}

if(!function_exists('array_is_list')){
	function array_is_list($arr){
		if(([] === $arr ) || (array_values($arr) === $arr)){
			return true;
		}

		$next_key	= -1;

		foreach($arr as $k => $v){
			if(++$next_key !== $k){
				return false;
			}
		}

		return true;
	}
}

if(!function_exists('wp_hash')){
	function wp_hash($data, $scheme='auth', $algo='md5') {
		return hash_hmac($algo, $data, wp_salt($scheme));
	}
}

if(!function_exists('update_usermeta_cache')){
	function update_usermeta_cache($user_ids){
		return update_meta_cache('user', $user_ids);
	}
}

if(!function_exists('str_replace_deep')){
	function str_replace_deep($s, $r, $value){
		return map_deep($value, fn($v) => str_replace($s, $r, $v));
	}
}

if(!function_exists('user_can_for_blog')){
	function user_can_for_blog($user, $blog_id, $capability, ...$args){
		return wpjam_call_for_blog($blog_id, 'user_can', $user, $capability, ...$args);
	}
}

if(!function_exists('get_metadata_by_value')){
	function get_metadata_by_value($meta_type, $meta_value, $meta_key=''){
		$data	= wpjam_get_by_meta($meta_type, $meta_key, $meta_value);

		return $data ? (object)reset($data) : false;
	}
}

if(!function_exists('wp_cache_get_with_cas')){
	function wp_cache_get_with_cas($key, $group='', &$cas_token=null){
		return wp_cache_get($key, $group);
	}
}

if(!function_exists('wp_cache_cas')){
	function wp_cache_cas($cas_token, $key, $data, $group='', $expire=0){
		return wp_cache_set($key, $data, $group, $expire);
	}
}

if(!function_exists('array_value_first')){
	function array_value_first($arr){
		return wpjam_at(0);
	}
}

if(!function_exists('array_value_last')){
	function array_value_last($arr){
		return wpjam_at(-1);
	}
}

if(!function_exists('array_first')){
	function array_first($array, $callback=null){
		return is_null($callback) ? reset($array) : wpjam_find($array, $callback);
	}
}

if(!function_exists('filter_null')){
	function filter_null($array, $deep=false){
		return wpjam_filter($array, fn($v)=> !is_null($v), $deep);
	}
}

if(!function_exists('filter_blank')){
	function filter_blank($array, $deep=false){
		return wpjam_filter($array, fn($v)=> $v || is_numeric($v), $deep);
	}
}

if(!function_exists('is_blank')){
	function is_blank($var){
		return empty($var) && !is_numeric($var);
	}
}

if(!function_exists('is_exists')){
	function is_exists($var){
		return isset($var);
	}
}

if(!function_exists('is_populated')){
	function is_populated($var){
		return !is_blank($var);
	}
}

if(!function_exists('mb_strwidth')){
	function_alias('_mb_strwidth', 'mb_strwidth');
}

if(!function_exists('mb_strimwidth')){
	function_alias('_mb_strimwidth', 'mb_strimwidth');
}

function _mb_strwidth($str){
	preg_match_all('/./us', $str, $match);

	return array_sum(wpjam_map($match[0], fn($char)=> ord($char) >=224 ? 2 : 1));
}

function _mb_strimwidth($str, $start, $width, $trimmarker=''){
	preg_match_all('/./us', $str, $match);

	$count	= count($match[0]);
	$start	= $start < 0 ? $count-1+$start : $start;
	$chars	= array_slice($match[0], $start);

	if($width >= array_sum(wpjam_map($chars, fn($char)=> ord($char) >=224 ? 2 : 1))){
		return implode('', $chars);
	}

	$length	= 0;
	$result	= '';
	$count	-= $start;
	$width	-= strlen($trimmarker);

	for($i=0; $i<$count; $i++){
		$char	= $chars[$i];
		$w		= ord($char) >= 224 ? 2 : 1;

		if($length + $w > $width){
			break;
		}

		$result	.= $char;
		$length	+= $w;
	}

	return $result.$trimmarker;
}

function wpjam_add_prefix($str, $prefix, &$added=false){
	return wpjam_fix('add', 'prev', $str, $prefix, $added);
}

function wpjam_replace_prefix($str, $prefix, $replace, &$replaced=false){
	return wpjam_fix('replace', 'prev', $str, $prefix, $replaced, $replace);
}

function wpjam_add_postfix($str, $postfix, &$added=false){
	return wpjam_fix('add', 'post', $str, $postfix, $added);
}

function wpjam_replace_postfix($str, $postfix, $replace, &$replaced=false){
	return wpjam_fix('replace', 'post', $str, $postfix, $removed);
}

function wpjam_toggle_url_scheme($url){
	return WPJAM_CDN::scheme_replace($url);
}

function wpjam_hex2rgba($color, $opacity=null){
	$color	= $color[0] == '#' ? substr($color, 1) : $color;
	$len	= strlen($color);

	if($len != 3 && $len != 6){
		return $color;
	}

	$hex	= array_map(fn($i)=> $len == 6 ? $color[$i*2].$color[$i*2+1] : $color[$i].$color[$i], [0, 1, 2]);
	$rgb	= implode(',', array_map('hexdec', $hex));
	$rgb	.= isset($opacity) ? ','.($opacity > 1 ? 1.0 : $opacity) : '';

	return 'rgb('.$rgb.')';
}

function wpjam_get_current_priority($name=null){
	$name	= $name ?: current_filter();
	$hook	= $GLOBALS['wp_filter'][$name] ?? null;

	return $hook ? $hook->current_priority() : null;
}

function wpjam_exception($errmsg, $errcode=null){
	throw new WPJAM_Exception($errmsg, $errcode);
}

function wpjam_migrate_option($from, $to, $default=null){
	if(get_option($to, $default) === $default){
		$data	= get_option($from) ?: [];
		$data	= array_merge($data, ['migrate_form'=>$from]);

		update_option($to, $data);

		delete_option($from);
	}
}

function wpjam_pending_objects($name){
	return WPJAM_Lazyloader::get_pending($name);
}

function wpjam_generate_random_string($length){
	return wp_generate_password($length, false);
}

function wpjam_http_request($url, $args=[], $err=[], &$headers=null){
	$response	= WPJAM_Http::request($url, $args, $err);

	if(!is_wp_error($response)){
		$headers	= $response['headers'];
		$response	= $response['body'];
	}

	return $response;
}

function wpjam_generate_query_data($args, $type='data'){
	return wpjam_get_parameter($args, [], $type);
}

function wpjam_register_extend_type($option, $dir, $args=[]){
	return WPJAM_Extend::create($dir, array_merge($args, compact('option')));
}

function wpjam_is_module($module='', $action=''){
	$current	= wpjam_get_query_var('module');

	return $module ? ($module == $current && (!$action || $action == wpjam_get_query_var('action'))) : (bool)$current;
}

function wpjam_get_current_module($wp=null){
	return wpjam_get_query_var('module', $wp);
}

function wpjam_get_current_action($wp=null){
	return wpjam_get_query_var('action', $wp);
}

function wpjam_is_webp_supported(){
	return wpjam_current_supports('webp');
}

function wpjam_get_permastruct($name){
	return $GLOBALS['wp_rewrite']->get_extra_permastruct($name);
}

function wpjam_set_permastruct($name, $value){
	return $GLOBALS['wp_rewrite']->extra_permastructs[$name]['struct']	= $value;
}

function wpjam_parse_options($options){
	$parsed	= [];

	foreach($options as $opt => $item){
		if(is_array($item)){
			if(isset($item['options'])){
				$parsed	= array_replace($parsed, wpjam_parse_options($item['options']));
			}elseif(!empty($item['title'])){
				$parsed[$opt]	= $item['title'];
			}elseif(!empty($item['label'])){
				$parsed[$opt]	= $item['label'];
			}
		}else{
			$parsed[$opt]	= $item;
		}
	}

	return $parsed;
}

function wpjam_set_current_user($user){
	wpjam_var('user', $user);
}

function wpjam_get_current_commenter(){
	$commenter	= wp_get_current_commenter();

	if(empty($commenter['comment_author_email'])){
		return new WP_Error('access_denied');
	}

	return $commenter;
}

function wpjam_wrap_tag($text, $tag='', $attr=[]){
	return wpjam_tag($tag, $attr, $text);
}

function wpjam_ajax_enqueue_scripts(){
	wp_enqueue_script('wpjam-ajax');
}

function wpjam_register_verification_code_group($name, $args=[]){
	return WPJAM_Cache::get_verification(['group'=>$name]+$args);
}

function wpjam_sanitize_option_value($value){
	return WPJAM_Setting::sanitize_option($value);
}

function wpjam_slice_data_type(&$args, $strip=false){
	$result	= wpjam_parse_data_type($args);

	if($strip && $result){
		$args	= wpjam_except($args, wpjam_pull($args, 'data_type'));
	}

	return $result;
}

function wpjam_option_get_setting($option, $setting='', $default=null){
	$object = wpjam_get_option_object($option);

	return $object ? $object->get_setting($setting, $default) : $default;
}

function wpjam_option_update_setting($option, $setting, $value){
	$object = wpjam_get_option_object($option);

	return $object ? $object->update_setting($setting, $value) : null;
}

function wpjam_get_option_setting($name){
	return WPJAM_Option_Setting::get($name)->to_array();
}

function wpjam_add_option_section_fields($option_name, $section_id, $fields){
	return wpjam_add_option_section($option_name, $section_id, $fields);
}

function wpjam_get_admin_prefix(){
	return WPJAM_Admin::get_prefix();
}

function wpjam_get_page_summary($type='page'){
	return get_screen_option($type.'_summary');
}

function wpjam_set_page_summary($summary, $type='page', $append=true){
	add_screen_option($type.'_summary', ($append ? get_screen_option($type.'_summary') : '').$summary);
}

function wpjam_call_list_table_model_method($method, ...$args){
	return;
}

function wpjam_register_builtin_page_load(...$args){
	$args	= is_array($args[0]) ? $args[0] : $args[1];

	return wpjam_add_admin_load(['type'=>'builtin_page']+$args);
}

function wpjam_register_plugin_page_load(...$args){
	$args	= is_array($args[0]) ? $args[0] : $args[1];

	return wpjam_add_admin_load(['type'=>'plugin_page']+$args);
}

function wpjam_get_ajax_button($args){
	$name	= wpjam_pull($args, 'action');

	if($name){
		$object	= WPJAM_Page_Action::get($name);
		$object	= $object ?: wpjam_register_page_action($name, $args);

		return $object->get_button($args);
	}
}

function wpjam_get_ajax_form($args){
	$name	= wpjam_pull($args, 'action');

	return $name ? wpjam_register_page_action($name, $args)->get_form() : '';
}

function wpjam_ajax_button($args){
	echo wpjam_get_ajax_button($args);
}

function wpjam_ajax_form($args){
	echo wpjam_get_ajax_form($args);
}

function wpjam_get_nonce_action($key){
	return ($GLOBALS['plugin_page'] ?? $GLOBALS['current_screen']->id).'-'.$key;
}

function wpjam_get_plugin_page_query_data(){
	return wpjam_get_plugin_page_setting('query_data');
}

function wpjam_set_plugin_page_summary($summary, $append=true){
	wpjam_set_page_summary($summary, 'page', $append);
}

function wpjam_set_builtin_page_summary($summary, $append=true){
	wpjam_set_page_summary($summary, 'page', $append);
}

function wpjam_get_plugin_page_type(){
	return wpjam_get_plugin_page_setting('function');
}

function wpjam_register_plugin_page_tab($name, $args){
	return WPJAM_Menu_Page::add(array_merge($args, ['tab_slug'=>$name]));
}

function wpjam_get_list_table_setting($key){
	return isset($GLOBALS['wpjam_list_table']) ? $GLOBALS['wpjam_list_table']->$key : null;
}

function wpjam_get_list_table_filter_link($filters, $title, $class=''){
	return $GLOBALS['wpjam_list_table']->get_filter_link($filters, $title, $class);
}

function wpjam_get_list_table_row_action($name, $args=[]){
	return $GLOBALS['wpjam_list_table']->get_row_action($name, $args);
}

function wpjam_render_list_table_column_items($id, $items, $args=[]){
	return $GLOBALS['wpjam_list_table']->get_column_value($id, '', ['items'=>$items, 'args'=>$args]);
}

function wpjam_register_list_table($name, $args=[]){
	if(!wpjam_get_items('list_table')){
		add_action('wpjam_preprocess_plugin_page', 'wpjam_preprocess_plugin_page', 10, 2);
	}

	wpjam_add_item('list_table', $name, $args);
}

function wpjam_register_dashboard($name, $args){
	if(!wpjam_get_items('dashboard')){
		add_action('wpjam_preprocess_plugin_page', 'wpjam_preprocess_plugin_page', 10, 2);
	}

	wpjam_add_item('dashboard', $name, $args);
}

function wpjam_preprocess_plugin_page($page, $name){
	$type	= $page->function;
	$args	= in_array($type, ['list_table', 'dashboard']) ? wpjam_get_item($type, $name) : [];

	if($args){
		$page->$type	= $args;
	}
}

function wpjam_parse_query_vars($query_vars){
	return WPJAM_Posts::parse_query_vars($query_vars);
}

function wpjam_get_post_option_fields($post_type, $post_id=null){
	return [];
}

function wpjam_validate_term($term_id, $taxonomy=''){
	return WPJAM_Term::validate($term_id, $taxonomy);
}

function wpjam_get_term_level($term){
	$object	= wpjam_term($term);

	return $object ? $object->level : null;
}

if(!function_exists('array_pulls')){
	function array_pulls(&$array, $keys){
		return wpjam_pull($array, $keys);
	}
}

function wpjam_array_pull(&$array, $key, $default=null){
	return array_pull($array, $key, $default);
}

function wpjam_array_except($array, ...$keys){
	return array_except($array, ...$keys);
}

function wpjam_array_push(&$array, $data, $key=null){
	$index	= is_null($key) ? false : array_search($key, array_keys($array), true);
	$array	= $index !== false ? wpjam_add_at($array, $index, $data) : array_merge($array, $data);

	return true;
}

function wpjam_list_sort($list, $orderby='order', $order='DESC'){
	return wp_list_sort($list, $orderby, $order, true);
}

function wpjam_list_filter($list, $args=[], $operator='AND'){
	return wpjam_filter($list, $args, $operator);
}

function wpjam_list_flatten($list, $depth=0, $args=[]){
	$flat	= [];

	$name		= $args['name'] ?? 'name'; 
	$children	= $args['children'] ?? 'children'; 

	foreach($list as $item){
		$item[$name]	= str_repeat('&emsp;', $depth).$item[$name];
		$flat[]			= $item;

		if(!empty($item[$children])){
			$flat	= array_merge($flat, wpjam_list_flatten($item[$children], $depth+1, $args));
		}
	}

	return $flat;
}

//中文截取方式
function wpjam_mb_strimwidth($text, $start=0, $width=40, $trimmarker='...', $encoding='utf-8'){
	$text	= wpjam_get_plain_text($text);

	return $text ? mb_strimwidth($text, $start, $width, $trimmarker, $encoding) : '';
}

function wpjam_parse_fields($fields){
	return WPJAM_Fields::flatten($fields);
}

function wpjam_field_get_icon($name){
	return WPJAM_Field::get_icon($name);
}

function wpjam_form_field_tmpls(){
	return;
}

function wpjam_urlencode_img_cn_name($img_url){
	return $img_url;
}

function wpjam_image_hwstring($size){
	return image_hwstring((int)($size['width']), (int)($size['height']));
}

function wpjam_get_taxonomy_levels($taxonomy){
	return wpjam_get_taxonomy_setting($taxonomy, 'levels', 0);
}

function wpjam_get_taxonomy_fields($taxonomy){
	$object	= WPJAM_Taxonomy::get($taxonomy);

	return $object ? $object->get_fields() : [];
}

function wpjam_is_json($json=''){
	$current = wpjam_get_current_json();

	return $current ? ($json ? $current == $json : true) : false;
}

function wpjam_send_error_json($errcode, $errmsg=''){
	wpjam_send_json(compact('errcode', 'errmsg'));
}

function wpjam_is_platform($name){
	return (WPJAM_Platform::get($name))->verify();
}

function wpjam_has_path($platform, $page_key, $strict=false){
	$object	= WPJAM_Platform::get($platform);

	return $object ? $object->has_path($page_key, $strict) : false;
}

function wpjam_get_platform_object($name){
	return WPJAM_Platform::get($name);
}

function wpjam_get_tabbar_options($platform){
	return wp_list_pluck(wpjam_get_tabbar($platform), 'text');
}

function wpjam_get_path_object($page_key){
	return WPJAM_Path::get($page_key);
}

function wpjam_get_paths($platform){
	return WPJAM_Path::get_by(['platform'=>$platform]);
}

function wpjam_get_paths_by_post_type(){}
function wpjam_get_paths_by_taxonomy(){}
function wpjam_generate_path(){}
function wpjam_render_path_item(){}

function wpjam_validate_post($post_id, $post_type=null){
	return WPJAM_Post::validate($post_id, $post_type);
}

function wpjam_related_posts($args=[]){
	echo wpjam_get_related_posts(null, $args, false);
}

function wpjam_new_posts($args=[]){
	echo wpjam_get_new_posts($args);
}

function wpjam_top_viewd_posts($args=[]){
	echo wpjam_get_top_viewd_posts($args);
}

function wpjam_get_post_type_fields($post_type){
	$object	= WPJAM_Post_Type::get($post_type);

	return $object ? $object->get_fields() : [];
}

function wpjam_attachment_url_to_postid($url){
	$id = wp_cache_get($url, 'attachment_url_to_postid');

	if($id === false){
		global $wpdb;

		$path	= str_replace(parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH).'/', '', parse_url($url, PHP_URL_PATH));
		$id		= $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s", $path));

		wp_cache_set($url, $id, 'attachment_url_to_postid', DAY_IN_SECONDS);
	}

	return (int)apply_filters('attachment_url_to_postid', $id, $url);
}

function wpjam_get_content_remote_image_url($img_url, $post_id=null){
	return $img_url;
}

function wpjam_get_content_remote_img_url($img_url, $post_id=0){
	return wpjam_get_content_remote_image_url($img_url, $post_id);
}

function wpjam_image_remote_method($img_url=''){
	return '';
}

function wpjam_is_remote_image($img_url, $strict=true){
	return $strict ? !wpjam_is_cdn_url($img_url) : wpjam_is_external_url($img_url);
}

function wpjam_get_content_width(){
	return (int)apply_filters('wpjam_content_image_width', wpjam_cdn_get_setting('width'));
}

function wpjam_cdn_content($content){
	return wpjam_content_images($content);
}

function wpjam_content_images($content){
	return WPJAM_CDN::filter_content($content);
}

function wpjam_bit($bit=0){
	return new WPJAM_Bit($bit);
}

function wpjam_get_post_image_url($image_id, $size='full'){
	$thumb	= wp_get_attachment_image_src($image_id, $size);

	return $thumb ? $thumb[0] : false;
}

function wpjam_has_post_thumbnail(){
	return wpjam_get_post_thumbnail_url() ? true : false;
}

function wpjam_post_thumbnail($size='thumbnail', $crop=1, $class='wp-post-image', $ratio=2){
	echo wpjam_get_post_thumbnail(null, $size, $crop, $class, $ratio);
}

function wpjam_get_post_thumbnail($post=null, $size='thumbnail', $crop=1, $class='wp-post-image', $ratio=2){
	$size	= wpjam_parse_size($size, $ratio);
	if($post_thumbnail_url = wpjam_get_post_thumbnail_url($post, $size, $crop)){
		$image_hwstring	= image_hwstring($size['width']/$ratio, $size['height']/$ratio);
		return '<img src="'.$post_thumbnail_url.'" alt="'.the_title_attribute(['echo'=>false]).'" class="'.$class.'"'.$image_hwstring.' />';
	}else{
		return '';
	}
}

function wpjam_has_term_thumbnail(){
	return wpjam_get_term_thumbnail_url() ? true : false;
}

function wpjam_term_thumbnail($size='thumbnail', $crop=1, $class="wp-term-image", $ratio=2){
	echo wpjam_get_term_thumbnail(null, $size, $crop, $class);
}

function wpjam_get_term_thumbnail($term=null, $size='thumbnail', $crop=1, $class="wp-term-image", $ratio=2){
	$size	= wpjam_parse_size($size, $ratio);

	if($term_thumbnail_url = wpjam_get_term_thumbnail_url($term, $size, $crop)){
		$image_hwstring	= image_hwstring($size['width']/$ratio, $size['height']/$ratio);

		return  '<img src="'.$term_thumbnail_url.'" class="'.$class.'"'.$image_hwstring.' />';
	}else{
		return '';
	}
}

function wpjam_display_errors(){}

function wpjam_parse_field_value($field, $args=[]){
	return wpjam_field($field)->value_callback($args);
}

function wpjam_get_field_value($field, $args=[]){
	return wpjam_parse_field_value($field, $args);
}

function wpjam_get_form_fields($admin_column=false){
	return [];
}

function wpjam_validate_fields_value($fields, $values=null){
	return wpjam_fields($fields)->validate($values);
}

function wpjam_validate_field_value($field, $value){
	return wpjam_field($field)->validate($value);
}

function wpjam_prepare_fields_value($fields, $args=[]){
	return wpjam_fields($fields)->prepare($args);
}

function wpjam_get_fields_defaults($fields){
	return wpjam_fields($fields)->get_defaults();
}

function wpjam_get_form_post($fields, $nonce_action='', $capability='manage_options'){
	check_admin_referer($nonce_action);

	if(!current_user_can($capability)){
		ob_clean();
		wp_die('无权限');
	}

	return wpjam_validate_fields_value($fields);
}

function wpjam_form($fields, $form_url, $nonce_action='', $submit_text=''){
	echo '<form method="post" action="'.$form_url.'" enctype="multipart/form-data" id="form">';

	echo wpjam_fields($fields);

	wp_nonce_field($nonce_action);
	wp_original_referer_field(true, 'previous');

	if($submit_text!==false){
		submit_button($submit_text);
	}

	echo '</form>';
}

function wpjam_stats_header($args=[]){
	global $wpjam_stats_labels;

	$wpjam_stats_labels	= [];

	$object	= WPJAM_Chart::get_instance($args);

	if(array_get($args, 'show_form') !== false){
		echo $object->render();
	}

	// do_action('wpjam_stats_header');

	foreach(['start_date', 'start_timestamp', 'end_date', 'end_timestamp', 'date', 'timestamp', 'start_date_2', 'start_timestamp_2', 'end_date_2', 'end_timestamp_2', 'date_type', 'date_format', 'compare'] as $key){
		$wpjam_stats_labels['wpjam_'.$key]	= $object->get_parameter($key);
	}

	$wpjam_stats_labels['compare_label']	= $object->get_parameter('start_date').' '.$object->get_parameter('end_date');
	$wpjam_stats_labels['compare_label_2']	= $object->get_parameter('start_date_2').' '.$object->get_parameter('end_date_2');
}

function wpjam_sub_summary($tabs){
	?>
	<h2 class="nav-tab-wrapper nav-tab-small">
	<?php foreach($tabs as $key => $tab){ ?>
		<a class="nav-tab" href="javascript:;" id="tab-title-<?php echo $key;?>"><?php echo $tab['name'];?></a>  	<?php }?>
	</h2>

	<?php foreach($tabs as $key => $tab){ ?>
	<div id="tab-<?php echo $key;?>" class="div-tab" style="margin-top:1em;">
	<?php
	global $wpdb;

	$counts = $wpdb->get_results($tab['counts_sql']);
	$total  = $wpdb->get_var($tab['total_sql']);
	$labels = isset($tab['labels'])?$tab['labels']:'';
	$base   = isset($tab['link'])?$tab['link']:'';

	$new_counts = $new_types = array();
	foreach($counts as $count){
		$link   = $base?($base.'&'.$key.'='.$count->label):'';

		if(is_super_admin() && $tab['name'] == '手机型号'){
			$label  = ($labels && isset($labels[$count->label]))?$labels[$count->label]:'<span style="color:red;">'.$count->label.'</span>';
		}else{
			$label  = ($labels && isset($labels[$count->label]))?$labels[$count->label]:$count->label;
		}

		$new_counts[] = array(
			'label' => $label,
			'count' => $count->count,
			'link'  => $link
		);
	}

	wpjam_donut_chart($new_counts, array('total'=>$total,'show_line_num'=>1,'table_width'=>'420'));

	?>
	</div>
	<?php }
}

function wpjam_send_user_message(...$args){
	if(count($args) == 2){
		$receiver	= $args[0];
		$message	= $args[1];
	}else{
		$message	= $args[0];
		$receiver	= $message['receiver'];
	}

	return WPJAM_User_Message::get_instance($receiver)->add($message);
}

function wpjam_register_theme_upgrader(){}

function wpjam_data_attribute_string($attr){
	return wpjam_attr($attr, 'data');
}

function wpjam_parse_attr($attr){
	return WPJAM_Attr::process($attr);
}

function wpjam_get_ajax_attribute_string($name, $data=[]){
	return wpjam_get_ajax_data_attr($name, $data);
}

function wpjam_get_ajax_attributes($name, $data=[]){
	return wpjam_get_ajax_data_attr($name, $data, '[]');
}

function wpjam_register_route_module($name, $args){
	return wpjam_register_route($name, $args);
}

function_alias('is_login', 'wpjam_is_login');
function_alias('wp_cache_delete_multiple', 'wp_cache_delete_multi');
function_alias('wp_cache_get_multiple', 'wp_cache_get_multi');

add_action('wpjam_loaded', function(){
	function_alias('wpjam_if', 'wpjam_show_if');
	function_alias('wpjam_strip_control_chars', 'wpjam_strip_control_characters');

	function_alias('wpjam_merge', 'wpjam_array_merge');
	function_alias('wpjam_filter', 'wpjam_array_filter');
	function_alias('wpjam_is_assoc_array', 'is_assoc_array');

	function_alias('wpjam_add_admin_error', 'wpjam_admin_add_error');

	function_alias('wpjam_map_meta_cap', 'wpjam_register_capability');
	function_alias('wpjam_setting', 'wpjam_get_setting_object');
	function_alias('wpjam_get_post_excerpt', 'get_post_excerpt');
	function_alias('wpjam_attr', 'wpjam_attribute_string');
	function_alias('wpjam_download_url', 'wpjam_download_image');
	function_alias('wpjam_is_external_url', 'wpjam_is_external_image');

	function_alias('get_post_type_support', 'get_post_type_support_value');

	function_alias('wpjam_get_post_option_fields', 'wpjam_get_post_fields');

	function_alias('wpjam_get_items', 'wpjam_get_current_items');
	function_alias('wpjam_add_item', 'wpjam_add_current_item');

	function_alias('wpjam_parse_ip', 'wpjam_get_ipdata');
	function_alias('wpjam_get_user_agent', 'wpjam_get_ua');
	function_alias('is_macintosh', 'is_mac');
	function_alias('wp_is_mobile', 'wpjam_is_mobile');

	function_alias('wpjam_list_flatten', 'wpjam_flatten_terms');
	function_alias('wpjam_list_sort', 'wpjam_sort_items');

	function_alias('wpjam_is_module', 'is_module');

	function_alias('wpjam_is_json', 'is_wpjam_json');
	function_alias('wpjam_get_json_object', 'wpjam_get_api_setting');
	function_alias('wpjam_get_json_object', 'wpjam_get_api');
	function_alias('wpjam_get_current_json', 'wpjam_get_json');

	function_alias('wpjam_get_path_object', 'wpjam_get_path_obj');
	function_alias('wpjam_get_paths', 'wpjam_get_path_objs');

	function_alias('wpjam_render_query', 'wpjam_get_post_list');
	function_alias('wpjam_get_post_first_image_url', 'wpjam_get_post_first_image');
	function_alias('wpjam_get_post_first_image_url', 'get_post_first_image');

	function_alias('wpjam_cdn_host_replace', 'wpjam_cdn_replace_local_hosts');
	function_alias('wpjam_field', 'wpjam_get_field_html');
	function_alias('wpjam_field', 'wpjam_render_field');

	function_alias('wpjam_get_qqv_id', 'wpjam_get_qqv_vid');
	function_alias('wpjam_get_qqv_id', 'wpjam_get_qq_vid');

	function_alias('wpjam_has_term_thumbnail', 'wpjam_has_category_thumbnail');
	function_alias('wpjam_has_term_thumbnail', 'wpjam_has_tag_thumbnail');

	function_alias('wpjam_get_term_thumbnail', 'wpjam_get_category_thumbnail');
	function_alias('wpjam_get_term_thumbnail', 'wpjam_get_tag_thumbnail');
	function_alias('wpjam_term_thumbnail', 'wpjam_category_thumbnail');
	function_alias('wpjam_term_thumbnail', 'wpjam_tag_thumbnail');

	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_category_thumbnail_url');
	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_tag_thumbnail_url');

	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_term_thumbnail_src');
	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_category_thumbnail_src');
	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_tag_thumbnail_src');

	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_term_thumbnail_uri');
	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_category_thumbnail_uri');
	function_alias('wpjam_get_term_thumbnail_url', 'wpjam_get_tag_thumbnail_uri');

	function_alias('wpjam_get_post_thumbnail_url', 'wpjam_get_post_thumbnail_src');
	function_alias('wpjam_get_post_thumbnail_url', 'wpjam_get_post_thumbnail_uri');

	function_alias('wpjam_get_default_thumbnail_url', 'wpjam_get_default_thumbnail_src');
	function_alias('wpjam_get_default_thumbnail_url', 'wpjam_get_default_thumbnail_uri');
});

add_action('wpjam_api', fn($json)=> do_action('wpjam_api_template_redirect', $json));
add_filter('rewrite_rules_array', fn($rules)=> array_merge(apply_filters('wpjam_rewrite_rules', []), $rules));

// add_action('wpjam_admin_init', function(){
// 	$user_id	= get_current_user_id();
// 	$instance	= WPJAM_User_Message::get_instance($user_id);

// 	wpjam_add_menu_page('wpjam-messages', [
// 		'menu_title'	=>'站内消息',
// 		'capability'	=>'read',
// 		'parent'		=>'users',
// 		'function'		=>[$instance, 'plugin_page'],
// 		'load_callback'	=>[$instance, 'load_plugin_page']
// 	]);
// });

class WPJAM_Crypt extends WPJAM_Args{
	public function __construct(...$args){
		if($args && is_string($args[0])){
			$key	= $args[0];
			$args	= $args[1] ?? [];
			$args	= array_merge($args, ['key'=>$key]);
		}else{
			$args	= $args[0] ?? [];
		}

		$this->args	= $args+[
			'method'	=> 'aes-256-cbc',
			'key'		=> '',
			'iv'		=> '',
			'options'	=> OPENSSL_ZERO_PADDING,	
		];
	}

	public function encrypt($text){
		return wpjam_encrypt($text, $this->args);
	}

	public function decrypt($text){
		return wpjam_decrypt($text, $this->args);
	}

	public static function pad($text, $type, ...$args){
		return wpjam_pad($text, $type, ...$args);
	}

	public static function unpad($text, $type, ...$args){
		return wpjam_unpad($text, $type, ...$args);
	}

	public static function generate_signature(...$args){
		return wpjam_generate_signature('sha1', ...$args);
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		return WPJAM_API::request($url, $args, $err);
	}
}

class WPJAM_Option_Items extends WPJAM_Items{
	public function __construct($option_name, $args=[]){
		if(is_array($args)){
			if(empty($args['items_field'])){
				$args	+= ['type'=>'option'];
			}else{
				$args	= ['type'=>'setting'];
			}
		}else{
			$args	= ['primary_key'=>$args, 'type'=>'option'];
		}

		parent::__construct(array_merge($args, ['option_name'=>$option_name]));
	}
}

class WPJAM_Meta_Items extends WPJAM_Items{
	public function __construct($meta_type, $object_id=0, $meta_key='', $args=[]){
		parent::__construct(array_merge($args, compact('meta_type', 'object_id', 'meta_key'), ['type'=>'meta']));
	}
}

class WPJAM_Content_Items extends WPJAM_Items{
	public function __construct($post_id, $args=[]){
		parent::__construct(array_merge($args, ['type'=>'post_content', 'post_id'=>$post_id]));
	}
}

class WPJAM_Cache_Items extends WPJAM_Items{
	public function __construct($key, $args=[]){
		parent::__construct(array_merge($args, ['type'=>'cache', 'cache_key'=> $key]));
	}
}

class WPJAM_Singleton extends WPJAM_Args{	// delete 2024-12-31
	protected function __construct(){}

	public static function get_instance(){
		$name	= self::get_called();

		return $GLOBALS[$name] ??= new static();
	}
}

class WPJAM_DBTransaction{
	public static function beginTransaction(){
		return $GLOBALS['wpdb']->query("START TRANSACTION;");
	}

	public static function queryException(){
		$error = $GLOBALS['wpdb']->last_error;

		if($error){
			throw new Exception($error);
		}
	}

	public static function commit(){
		self::queryException();
		return $GLOBALS['wpdb']->query("COMMIT;");
	}

	public static function rollBack(){
		return $GLOBALS['wpdb']->query("ROLLBACK;");
	}
}

class WPJAM_Post_Option{
	public static function get($name){
		return wpjam_get_post_option($name);
	}

	public static function get_registereds(){
		return wpjam_get_post_options();
	}
}

class WPJAM_Term_Option{
	public static function get($name){
		return wpjam_get_term_option($name);
	}

	public static function get_registereds(){
		return wpjam_get_term_options();
	}
}

class WPJAM_Bit{
	protected $value	= 0;

	public function __construct($value=0){
		$this->value	= (int)$value;
	}

	public function __get($name){
		return $name == 'value' ? $this->value : null;
	}

	public function has($bit){
		$bit	= (int)$bit;

		return ($this->value & $bit) == $bit;
	}

	public function add($bit){
		$this->value = $this->value | (int)$bit;

		return $this;
	}

	public function remove($bit){
		$this->value = $this->value & (~(int)$bit);

		return $this;
	}
}

class WPJAM_Items_Model extends WPJAM_Model{
	public static function get_handler(){
		trigger_error('WPJAM_Items_Model');

		$handler	= parent::get_handler();

		if($handler){
			return $handler;
		}

		$args	= method_exists(get_called_class(), 'get_items_args') ? static::get_items_args() : [];
		$args	= array_merge($args, ['items_model'=>get_called_class()]);

		return self::set_handler(new WPJAM_Items($args));
	}
}

class WPJAM_User_Message{
	private $user_id	= 0;
	private $messages	= [];

	private static $instances	= [];

	public static function get_instance($user_id){
		if(!isset(self::$instances[$user_id])){
			self::$instances[$user_id] = new self($user_id);
		}

		return self::$instances[$user_id];
	}

	private function __construct($user_id){
		$this->user_id	= $user_id;

		if($user_id && ($messages = get_user_meta($user_id, 'wpjam_messages', true))){
			$this->messages	= array_filter($messages, function($message){ return $message['time'] > time() - MONTH_IN_SECONDS * 3; });
		}
	}

	public function get_messages(){
		return $this->messages;
	}

	public function get_unread_count(){
		$messages	= array_filter($this->messages, function($message){ return $message['status'] == 0; });

		return count($messages);
	}

	public function set_all_read(){
		array_walk($this->messages, function(&$message){ $message['status'] == 1; });

		return $this->save();
	}

	public function add($message){
		$message	+= [
			'sender'	=> '',
			'receiver'	=> '',
			'type'		=> '',
			'content'	=> '',
			'status'	=> 0,
			'time'		=> time()
		];

		$message['content'] = wp_strip_all_tags($message['content']);

		$this->messages[]	= $message;

		return $this->save();
	}

	public function delete($i){
		if(isset($this->messages[$i])){
			unset($this->messages[$i]);
			return $this->save();
		}

		return true;
	}

	public function save(){
		if(empty($this->messages)){
			return delete_user_meta($this->user_id, 'wpjam_messages');
		}else{
			return update_user_meta($this->user_id, 'wpjam_messages', $this->messages);
		}
	}

	public function load_plugin_page(){
		wpjam_register_page_action('delete_message', [
			'button_text'	=> '删除',
			'class'			=> 'message-delete',
			'callback'		=> [$this, 'ajax_delete'],
			'direct'		=> true,
			'confirm'		=> true
		]);
	}

	public function ajax_delete(){
		$message_id	= (int)wpjam_get_data_parameter('message_id');
		$messages	= $this->get_messages();

		if($messages && isset($messages[$message_id])){
			$result	= $this->delete($message_id);

			if(is_wp_error($result)){
				wpjam_send_json($result);
			}else{
				wpjam_send_json(['message_id'=>$message_id]);
			}
		}

		wp_die('invalid_id');
	}

	public function plugin_page(){
		$messages	= $this->data;

		if(empty($messages)){
			echo '<p>暂无站内消息</p>';
			return;
		}

		if($this->get_unread_count()){
			$this->set_all_read();
		}

		$sender_ids			= [];
		$post_ids_list		= [];
		$comment_ids_list	= [];

		foreach($messages as $message){
			$sender_ids[]	= $message['sender'];
			$blog_id		= $message['blog_id'];
			$post_id		= $message['post_id'];
			$comment_id		= $message['comment_id'];
			if($blog_id){
				if($post_id){
					$post_ids_list[$blog_id][]		= $post_id;
				}

				if($comment_id){
					$comment_ids_list[$blog_id][]	= $comment_id;
				}
			}
		}

		$senders	= get_users(['blog_id'=>0, 'include'=>$sender_ids]);

		foreach($post_ids_list as $blog_id => $post_ids){
			wpjam_call_for_blog($blog_id, ['WPJAM_Post', 'update_caches'], $post_ids);
		}

		foreach($comment_ids_list as $blog_id => $comment_ids){
			wpjam_call_for_blog($blog_id, 'get_comments', ['include'=>$comment_ids]);
		}
		?>

		<ul class="messages">
		<?php foreach($messages as $i => $message){
			$alternate	= empty($alternate)?'alternate':'';
			$sender		= get_userdata($message['sender']);

			$type		= $message['type'];
			$content	= $message['content'];
			$blog_id	= $message['blog_id'];
			$post_id	= $message['post_id'];
			$comment_id	= $message['comment_id'];


			if(empty($sender)){
				continue;
			}

			if($blog_id && $post_id){
				$post	= wpjam_call_for_blog($blog_id, 'get_post', $post_id);

				if($post){
					$topic_title	= $post->post_title;
				}
			}else{
				$topic_title		= '';
			}
		?>
			<li id="message_<?php echo $i; ?>" class="<?php echo $alternate; echo empty($message['status'])?' unread':'' ?>">
				<div class="sender-avatar"><?php echo get_avatar($message['sender'], 60);?></div>
				<div class="message-time"><?php echo wpjam_human_time_diff($message['time']);?><p><?php echo wpjam_get_page_button('delete_message',['data'=>['message_id'=>$i]]);?></p></div>
				<div class="message-content">

				<?php

				if($type == 'topic_comment'){
					$prompt	= '<span class="message-sender">'.$sender->display_name.'</span>在你的帖子「<a href="'.admin_url('admin.php?page=wpjam-topics&action=comment&id='.$post_id.'#comment_'.$comment_id).'">'.$topic_title.'</a>」给你留言了：'."\n\n";
				}elseif($type == 'comment_reply' || $type == 'topic_reply'){
					$prompt	= '<span class="message-sender">'.$sender->display_name.'</span>在帖子「<a href="'.admin_url('admin.php?page=wpjam-topics&action=comment&id='.$post_id.'#comment_'.$comment_id).'">'.$topic_title.'</a>」回复了你的留言：'."\n\n";
				}else{
					$prompt	= '<span class="message-sender">'.$sender->display_name.'：'."\n\n";
				}

				echo wpautop($prompt.$content);

				?>
				</div>
			</li>
			<?php } ?>
		</ul>

		<style type="text/css">
			ul.messages{ max-width:640px; }
			ul.messages li {margin: 10px 0; padding:10px; margin:10px 0; background: #fff; min-height: 60px;}
			ul.messages li.alternate{background: #f9f9f9;}
			ul.messages li.unread{font-weight: bold;}
			ul.messages li a {text-decoration:none;}
			ul.messages li div.sender-avatar {float:left; margin:0px 10px 0px 0;}
			ul.messages li div.message-time{float: right; width: 60px;}
			ul.messages li .message-delete{color: #a00;}
			ul.messages li div.message-content p {margin: 0 70px 10px 70px; }
		</style>

		<script type="text/javascript">
		jQuery(function($){
			$('body').on('page_action_success', function(e, response){
				var action		= response.page_action;
				var action_type	= response.page_action_type;

				if(action == 'delete_message'){
					var message_id	= response.message_id;
					$('#message_'+message_id).animate({opacity: 0.1}, 500, function(){ $(this).remove();});
				}
			});
		});
		</script>

		<?php
	}
}

trait WPJAM_Setting_Trait{
	private $settings		= [];
	private $option_name	= '';
	private $site_default	= false;

	private function init($option_name, $site_default=false){
		$this->option_name	= $option_name;
		$this->site_default	= $site_default;

		$this->reset_settings();
	}

	public function __get($name){
		if(in_array($name, ['option_name', 'site_default'])){
			return $this->$name;
		}

		if(is_null(get_option($option_name, null))){
			add_option($option_name, []);
		}

		return $this->get_setting($name);
	}

	public function __set($name, $value){
		return $this->update_setting($name, $value);
	}

	public function __isset($name){
		return isset($this->settings[$name]);
	}

	public function __unset($name){
		$this->delete_setting($name);
	}

	public function get_settings(){
		return $this->settings;
	}

	public function reset_settings(){
		$value	= wpjam_get_option($this->option_name);

		$this->settings	= is_array($value) ? $value : [];

		if($this->site_default){
			$site_value	= wpjam_get_site_option($this->option_name);
			$site_value	= is_array($site_value) ? $site_value : [];

			$this->settings	+= $site_value;
		}
	}

	public function get_setting($name='', $default=null){
		return $name ? ($this->settings[$name] ?? $default) : $this->settings;
	}

	public function update_setting($name, $value){
		$this->settings[$name]	= $value;

		return $this->save();
	}

	public function delete_setting($name){
		$this->settings	= wpjam_except($this->settings, $name);

		return $this->save();
	}

	private function save($settings=[]){
		if($settings){
			$this->settings	= array_merge($this->settings, $settings);
		}

		return update_option($this->option_name, $this->settings);
	}

	private static $instances	= [];

	public static function get_instance(){
		$blog_id = get_current_blog_id();	//多站点情况下，switch_to_blog 之后还能从正确的站点获取设置

		if(!isset(self::$instances[$blog_id])){
			self::$instances[$blog_id] = new self();
		}

		return self::$instances[$blog_id];
	}

	public static function register_option($args=[]){
		$instance	= self::get_instance();
		$defaults	= [];

		$defaults['site_default']	= $instance->site_default;

		if(method_exists($instance, 'sanitize_callback')){
			$defaults['sanitize_callback']	= [$instance, 'sanitize_callback'];
		}

		if(method_exists($instance, 'get_summary')){
			$defaults['summary']	= [$instance, 'get_summary'];
		}

		if(method_exists($instance, 'get_sections')){
			$defaults['sections']	= [$instance, 'get_sections'];
		}elseif(method_exists($instance, 'get_fields')){
			$defaults['fields']		= [$instance, 'get_fields'];
		}

		if(current_user_can('manage_options') && isset($_GET['reset'])){
			delete_option($instance->option_name);
		}

		return wpjam_register_option($instance->option_name, $args+$defaults);
	}
}

trait WPJAM_Register_Trait{
	protected $name;
	protected $args;
	protected $filtered	= false;

	public function __construct($name, $args=[]){
		$this->name	= $name;
		$this->args	= $args;
	}

	protected function get_args(){
		if(!$this->filtered){
			$filter	= strtolower(get_called_class()).'_args';

			$this->args		= apply_filters($filter, $this->args, $this->name);
			$this->filtered	= true;
		}

		return $this->args;
	}

	public function __get($key){
		if($key == 'name'){
			return $this->name;
		}else{
			$args	= $this->get_args();
			return $args[$key] ?? null;
		}
	}

	public function __set($key, $value){
		if($key != 'name'){
			$this->args	= $this->get_args();
			$this->args[$key]	= $value;
		}
	}

	public function __isset($key){
		$args	= $this->get_args();
		return isset($args[$key]);
	}

	public function __unset($key){
		$this->args	= $this->get_args();
		unset($this->args[$key]);
	}

	public function to_array(){
		return $this->get_args();
	}

	protected static $_registereds	= [];

	public static function parse_name($name){
		if(empty($name)){
			trigger_error(self::class.'的注册 name 为空');
			return null;
		}elseif(is_numeric($name)){
			trigger_error(self::class.'的注册 name「'.$name.'」'.'为纯数字');
			return null;
		}elseif(!is_string($name)){
			trigger_error(self::class.'的注册 name「'.var_export($name, true).'」不为字符串');
			return null;
		}

		return $name;
	}

	public static function register(...$args){
		if(count($args) == 1){
			$object	= $args[0];
			$name	= $object->name;
		}else{
			$name	= self::parse_name($args[0]);

			if(is_null($name)){
				return null;
			}

			$object	= new static($name, $args[1]);
		}

		self::$_registereds[$name]	= $object;

		return $object;
	}

	protected static function register_instance($name, $object){
		self::$_registereds[$name]	= $object;

		return $object;
	}

	public static function unregister($name){
		unset(self::$_registereds[$name]);
	}

	public static function get_by($args=[], $output='objects'){
		return self::get_registereds($args, $output);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		$registereds	= $args ? wp_filter_object_list(self::$_registereds, $args, $operator, false) : self::$_registereds;

		if($output == 'names'){
			return array_keys($registereds);
		}elseif(in_array($output, ['args', 'settings'])){
			return array_map(function($registered){
				return $registered->to_array();
			}, $registereds);
		}else{
			return $registereds;
		}
	}

	public static function get($name){
		return self::$_registereds[$name] ?? null;
	}

	public static function exists($name){
		return self::get($name) ? true : false;
	}
}

trait WPJAM_Type_Trait{
	use WPJAM_Register_Trait;
}

// 直接在 handler 里面定义即可。
// 需要在使用的 CLASS 中设置 public static $meta_type
trait WPJAM_Meta_Trait{
	public static function get_meta_type_object(){
		return wpjam_get_meta_type_object(self::$meta_type);
	}

	public static function add_meta($id, $meta_key, $meta_value, $unique=false){
		return self::get_meta_type_object()->add_data($id, $meta_key, $meta_value, $unique);
	}

	public static function delete_meta($id, $meta_key, $meta_value=''){
		return self::get_meta_type_object()->delete_data($id, $meta_key, $meta_value);
	}

	public static function get_meta($id, $key = '', $single = false){
		return self::get_meta_type_object()->get_data($id, $key, $single);
	}

	public static function update_meta($id, $meta_key, $meta_value, $prev_value=''){
		return self::get_meta_type_object()->update_data($id, $meta_key, wp_slash($meta_value), $prev_value);
	}

	public static function delete_meta_by_key($meta_key){
		return self::get_meta_type_object()->delete_by_key($meta_key);
	}

	public static function update_meta_cache($object_ids){
		self::get_meta_type_object()->update_cache($object_ids);
	}

	public static function create_meta_table(){
		self::get_meta_type_object()->create_table();
	}

	public static function get_meta_table(){
		return self::get_meta_type_object()->get_table();
	}
}
