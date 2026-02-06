<?php
class_alias('WPJAM_Query', 'WPJAM_Posts');
class_alias('WPJAM_Option_Items', 'WPJAM_Option');
class_alias('WPJAM_Items', 'WPJAM_Item');
class_alias('WPJAM_Post', 'WPJAM_PostType');
class_alias('WPJAM_Cache_Items', 'WPJAM_List_Cache');
class_alias('WPJAM_Cache_Items', 'WPJAM_ListCache');
class_alias('WPJAM_Cache', 'WPJAM_Cache_Group');
class_alias('WPJAM_Crypt', 'WPJAM_OPENSSL_Crypt');
class_alias('WPJAM_Option_Setting', 'WPJAM_Setting');

if(!function_exists('function_alias')){
	function function_alias($original, $alias=null){
		if(function_exists($alias)){
			return false;
		}

		eval('function '.$alias.'(...$args){
			return function_exists(\''.$original.'\') ? '.$original.'(...$args) : null;
		}');

		return true;
	}
}

if(!function_exists('clamp')){
	function clamp($value, $min, $max){
		return min(max($value, $min), $max);
	}
}

if(!function_exists('wp_hash')){
	function wp_hash($data, $scheme='auth', $algo='md5') {
		return hash_hmac($algo, $data, wp_salt($scheme));
	}
}

if(!function_exists('current_shortcode')){
	function current_shortcode() {
		return array_last(wpjam('shortcode'));
	}
}

if(!function_exists('doing_shortcode')){
	function doing_shortcode($tag=null){
		$tags	= wpjam('shortcode');

		return null === $tag ? !empty($tags) : in_array($tag, $tags, true);
	}
}

if(!function_exists('wp_cache_get_salted')){
	function wp_cache_get_salted($key, $group, $salt){
		$salt	= is_array($salt) ? implode(':', $salt) : $salt;
		$cache	= wp_cache_get($key, $group);

		return (!is_array($cache) || !isset($cache['salt'], $cache['data']) || $salt !== $cache['salt']) ? false : $cache['data'];
	}
}

if(!function_exists('wp_cache_set_salted')){
	function wp_cache_set_salted($key, $data, $group, $salt, $expire=0){
		$salt	= is_array($salt) ? implode(':', $salt) : $salt;

		return wp_cache_set($key, ['data'=>$data, 'salt'=>$salt], $group, $expire);
	}
}

if(!function_exists('wp_cache_get_with_cas')){
	function wp_cache_get_with_cas($key, $group='', &$token=null){
		return wp_cache_get($key, $group);
	}
}

if(!function_exists('wp_cache_cas')){
	function wp_cache_cas($cas_token, $key, $data, $group='', $expire=0){
		return wp_cache_set($key, $data, $group, $expire);
	}
}

if(!function_exists('array_find_index')){
	function array_find_index($arr, $callback){
		return wpjam_find($arr, $callback, 'index');
	}
}

if(!function_exists('mb_strwidth')){
	function mb_strwidth($str){
		preg_match_all('/./us', $str, $match);

		return array_sum(wpjam_map($match[0], fn($char)=> ord($char) >=224 ? 2 : 1));
	}
}

if(!function_exists('mb_strimwidth')){
	function mb_strimwidth($str, $start, $width, $trimmarker=''){
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
}

if(!function_exists('user_can_for_blog')){
	function user_can_for_blog($user, $blog_id, $capability, ...$args){
		return wpjam_call_for_blog($blog_id, 'user_can', $user, $capability, ...$args);
	}
}

if(!function_exists('get_metadata_by_value')){
	function get_metadata_by_value($type, $value, $key=''){
		return ($data = wpjam_get_by_meta($type, $key, $value)) ? (object)array_first($data) : false;
	}
}

if(!function_exists('update_usermeta_cache')){
	function update_usermeta_cache($ids){
		return update_meta_cache('user', $ids);
	}
}

if(!function_exists('filter_deep')){
	function filter_deep($arr, $cb){
		return wpjam_filter($arr, $cb, true);
	}
}

if(!function_exists('filter_null')){
	function filter_null($arr, $deep=false){
		return wpjam_filter($arr, fn($v)=> !is_null($v), $deep);
	}
}

if(!function_exists('filter_blank')){
	function filter_blank($arr, $deep=false){
		return wpjam_filter($arr, fn($v)=> $v || is_numeric($v), $deep);
	}
}

if(!function_exists('is_blank')){
	function is_blank($v){
		return !$v && !is_numeric($v);
	}
}

if(!function_exists('is_populated')){
	function is_populated($v){
		return !is_blank($v);
	}
}

if(!function_exists('is_exists')){
	function is_exists($v){
		return isset($v);
	}
}

function wpjam_timer($cb, ...$args){
	try{
		$timestart	= microtime(true);

		return $cb(...$args);
	}finally{
		$log[]	= "Callback: ".var_export($cb, true);
		$log[]	= "Time: ".number_format(microtime(true)-$timestart, 5);

		if(is_closure($cb)){
			$log[]	= "File: ".wpjam_get_reflection($cb, 'FileName');
			$log[]	= "Line: ".wpjam_get_reflection($cb, 'StartLine');
		}

		trigger_error(implode("\n", $log)."\n\n");
	}
}

function wpjam_timer_hook($value){
	$name	= current_filter();
	$object	= $GLOBALS['wp_filter'][$name] ?? null;

	if($object){
		foreach($object->callbacks as &$hooks){
			foreach($hooks as &$hook){
				$hook['function']	= fn(...$args)=> wpjam_timer($hook['function'], ...$args);
			}
		}
	}

	return $value;
}

function wpjam_parse_query($query, $args=[], $parse=true){
	return wpjam_query($query, $args, $parse);
}

function wpjam_render_query($query, $args=[]){
	return wpjam_query($query, $args, false);
}

function wpjam_get_current_query(){
	return array_last(wpjam('query'));
}

function wpjam_loaded($action, ...$args){
	wpjam_load('wp_loaded', fn()=> do_action($action, ...$args));
}

function wpjam_parse_method($model, $method, &$args=[]){
	$cb	= [$model, $method];

	try{
		return is_object($model) ? $cb : wpjam_callback($cb, true, $args);
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_call_method($class, $method, ...$args){
	return wpjam_call([$class, $method], ...$args);
}

function wpjam_die_if_error($result){
	return wpjam_if_error($result, 'die');
}

function wpjam_throw_if_error($result){
	return wpjam_if_error($result, 'throw');
}

function wpjam_remove_postfix($str, $postfix){
	return wpjam_remove_suffix($str, $postfix);
}

function wpjam_get_plain_text($text){
	return $text ? trim(preg_replace('/\s+/', ' ', str_replace(['"', '\'', "\r\n", "\n"], ['', '', ' ', ' '], wp_strip_all_tags($text)))) : $text;
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

function wpjam_sum($items, $keys){
	return array_reduce($items, fn($sum, $item)=> array_map(fn($k)=> $sum[$k]+(is_numeric($v = str_replace(',', '', ($item[$k] ?? 0))) ? $v : 0), $keys), array_fill_keys($keys, 0));
}

function wpjam_migrate_option($from, $to, $default=null){
	if(get_option($to, $default) === $default){
		$data	= get_option($from) ?: [];
		$data	= array_merge($data, ['migrate_form'=>$from]);

		update_option($to, $data);

		delete_option($from);
	}
}

function wpjam_get_verify_txt($name, $key=''){
	return wpjam_txt($name, $key);
}

function wpjam_set_verify_txt($name, $data){
	return wpjam_txt($name, $data);
}

function wpjam_generate_random_string($length){
	return wp_generate_password($length, false);
}

function wpjam_http_request($url, $args=[], $err=[], &$headers=null){
	$result	= WPJAM_Http::request($url, $args, $err);

	if(!is_wp_error($result)){
		$headers	= $result['headers'];
		$result		= $result['body'];
	}

	return $result;
}

function wpjam_generate_query_data($args, $type='data'){
	return wpjam_get_parameter($args, [], $type);
}

function wpjam_register_extend_type($option, $dir, $args=[]){
	return wpjam_load_extends($dir, array_merge($args, compact('option')));
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

function wpjam_get_items($group){
	return wpjam($group);
}

function wpjam_get_item($group, $key){
	return wpjam($group, $key);
}

function wpjam_add_item($group, $key, ...$args){
	return wpjam($group.'[]', $key, ...$args);
}

function wpjam_get_current_var($name){
	return wpjam_var($name);
}

function wpjam_set_current_var($name, $value){
	return wpjam_var($name, $value);
}

function wpjam_set_current_user($user){
	wpjam_var('user', $user);
}

function wpjam_get_current_commenter(){
	$commenter	= wp_get_current_commenter();

	return empty($commenter['comment_author_email']) ? new WP_Error('access_denied') : $commenter;
}

function wpjam_get_instance($group, $id, $cb=null){
	return wpjam('instance', $group.'.'.$id) ?? ($cb ? wpjam_add_instance($group, $id, $cb($id)) : null);
}

function wpjam_add_instance($group, $id, $object){
	!is_wp_error($object) && !is_null($object) && wpjam('instance', $group.'.'.$id, $object);

	return $object;
}

function wpjam_localize_script($handle, $name, $l10n ){
	wp_localize_script($handle, $name, ['l10n_print_after' => $name.' = '.wpjam_json_encode($l10n)]);
}

function wpjam_wrap_tag($text, $tag='', $attr=[]){
	return wpjam_tag($tag, $attr, $text);
}

function wpjam_ajax_enqueue_scripts(){
	wp_enqueue_script('wpjam-ajax');
}

function wpjam_sanitize_option_value($value){
	return WPJAM_Option_Setting::sanitize_option($value);
}

function wpjam_strip_data_type($args){
	return WPJAM_Data_Type::excerpt($args);
}

function wpjam_parse_data_type($args, $output='args'){
	return WPJAM_Data_Type::prepare($args, $output);
}

function wpjam_slice_data_type(&$args, $strip=false){
	$result	= WPJAM_Data_Type::prepare($args);
	$args	= ($strip && $result) ? wpjam_except($args, wpjam_pull($args, 'data_type')) : $args;

	return $result;
}

function wpjam_get_option_object($option, $by=''){
	return wpjam_option($option, $by);
}

function wpjam_get_option_setting($option){
	return wpjam_option($option)->to_array();
}

function wpjam_option_get_setting($option, $setting='', ...$args){
	return wpjam_get_setting($option, $setting, ...$args);
}

function wpjam_option_update_setting($option, $setting, $value){
	return wpjam_update_setting($option, $setting, $value);
}

function wpjam_add_option_section_fields($option, $id, $fields){
	return wpjam_add_option_section($option, $id, $fields);
}

function wpjam_get_admin_prefix(){
	return wpjam_admin()->prefix();
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

function wpjam_get_referer(){
	return remove_query_arg([...wp_removable_query_args(), '_wp_http_referer', 'action', 'action2', '_wpnonce'], wp_get_original_referer() ?: wp_get_referer());
}

function wpjam_admin_tooltip($text, $tooltip){
	return $text ? '<span class="tooltip" data-tooltip="'.esc_attr($tooltip).'">'.$text.'</span>' : '<span class="dashicons dashicons-editor-help tooltip" data-tooltip="'.esc_attr($tooltip).'"></span>';
}

function wpjam_add_admin_inline_script($data){
	wpjam_admin('script', $data);
}

function wpjam_add_admin_inline_style($data){
	wpjam_admin('style', $data);
}

function wpjam_register_builtin_page_load(...$args){
	$args	= is_array($args[0]) ? $args[0] : $args[1];

	return wpjam_add_admin_load(['type'=>'builtin_page']+$args);
}

function wpjam_register_plugin_page_load(...$args){
	$args	= is_array($args[0]) ? $args[0] : $args[1];

	return wpjam_add_admin_load(['type'=>'plugin_page']+$args);
}

function wpjam_page_action_compat($data){
	$cb		= wpjam_get_filter_name($GLOBALS['plugin_page'], 'ajax_response');
	$cb		= is_callable($cb) ? $cb : wp_die('invalid_callback');
	$result	= wpjam_if_error($cb($data['page_action']), 'send');

	wpjam_send_json(is_array($result) ? $result : []);
}

function wpjam_get_ajax_button($args, $type='button'){
	if($name = wpjam_pull($args, 'action')){
		$object	= WPJAM_Page_Action::get($name) ?: wpjam_register_page_action($name, $args);

		return $type == 'form' ? $object->get_form() : $object->get_button($args);
	}
}

function wpjam_get_ajax_form($args){
	return wpjam_get_ajax_button($args, 'form');
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

function wpjam_get_plugin_page_setting($key='', $tab=false){
	if($key == 'query_data'){
		return wpjam_admin('query_data');
	}

	if($object = wpjam_admin('plugin_page')){
		[$tab, $default]	= str_ends_with($key, '_name') ? [true, $object->menu_slug] : [$tab, null]; 

		if($tab && $object->type == 'tab'){
			$object = wpjam_admin('current_tab');

			if(!$object){
				return null;
			}
		}
	
		return $key ? ($object->$key ?: $default) : $object->to_array();
	}
}

function wpjam_get_current_tab_setting($key=''){
	return wpjam_get_plugin_page_setting($key, true);
}

function wpjam_get_plugin_page_query_data(){
	return wpjam_admin('query_data');
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
	return wpjam_add_menu_page(['tab_slug'=>$name]+$args);
}

function wpjam_get_list_table_setting($key){
	return isset($GLOBALS['wpjam_list_table']) ? $GLOBALS['wpjam_list_table']->$key : null;
}

function wpjam_get_list_table_filter_link($filters, $title, $class=''){
	return $GLOBALS['wpjam_list_table']->get_filter_link($filters, $title, $class);
}

function wpjam_get_list_table_row_action($name, $args=[]){
	return $GLOBALS['wpjam_list_table']->get_action($name, $args);
}

function wpjam_render_list_table_column_items($id, $items, $args=[]){
	return $GLOBALS['wpjam_list_table']->get_column_value($id, '', ['items'=>$items, 'args'=>$args]);
}

function wpjam_register_list_table($name, $args=[]){
	add_filter(wpjam_get_filter_name($name, 'list_table'), fn()=> $args);
}

function wpjam_register_dashboard($name, $args){
	add_action('wpjam_plugin_page', fn($object, $type)=> $type == 'dashboard' ? ($object->$type = $args) : null, 10, 2);
}

function wpjam_get_post_option_fields($post_type, $post_id=null){
	return [];
}

function wpjam_get_term_level($term){
	return get_term_level($term->term_id);
}

if(!function_exists('array_pulls')){
	function array_pulls(&$array, $keys){
		return wpjam_pull($array, $keys);
	}
}

function wpjam_found($arr, $cb){
	return wpjam_find($arr, true, $cb);
}

function wpjam_slice($arr, $keys){
	return wpjam_filter($arr, $keys);
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

function wpjam_list_flatten($list, $args=[]){
	$name	= $args['name'] ?? 'name';
	$key	= $args['children'] ?? 'children';

	return wpjam_reduce($list, fn($carry, $v, $k, $d)=> array_merge($carry, [array_merge($v, [$name=>str_repeat('&emsp;', $d).$v[$name]])]), [], $key);
}

//中文截取方式
function wpjam_mb_strimwidth($text, $start=0, $width=40, $trimmarker='...', $encoding='utf-8'){
	$text	= wpjam_get_plain_text($text);

	return $text ? mb_strimwidth($text, $start, $width, $trimmarker, $encoding) : '';
}

function wpjam_parse_fields($fields, $flat=false){
	return wpjam_fields($fields, $flat);
}

function wpjam_field_get_icon($name){
	if($name == 'multiply'){
		return '✖️';
	}
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
	return ($name = wpjam_get_current_json('name')) ? ($json ? $name == $json : true) : false;
}

function wpjam_get_json($output='name'){
	return wpjam_get_current_json($output);
}

function wpjam_send_error_json($errcode, $errmsg=''){
	wpjam_send_json(compact('errcode', 'errmsg'));
}

function wpjam_is_platform($name){
	return (WPJAM_Platform::get($name))->verify();
}

function wpjam_get_current_platforms($output='names'){
	return WPJAM_Platform::get_by(['path'=>true], $output);
}

function wpjam_get_platform_options($output='bit'){
	return WPJAM_Platform::get_options($output);
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
	return WPJAM_Path::get_instance($page_key);
}

function wpjam_get_paths($platform){
	return WPJAM_Path::get_by(['platform'=>$platform]);
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

function wpjam_get_paths_by_post_type(){}
function wpjam_get_paths_by_taxonomy(){}
function wpjam_generate_path(){}
function wpjam_render_path_item(){}

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
	return (int)wpjam_cdn_get_setting('width');
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

function wpjam_line_chart($data, $labels, $args=[]){
	echo wpjam_chart('line', $data, ['labels'=>$labels]+$args);
}

function wpjam_bar_chart($data, $labels, $args=[]){
	echo wpjam_chart('bar', $data, ['labels'=>$labels]+$args);
}

function wpjam_donut_chart($data, ...$args){
	echo wpjam_chart('donut', $data, (count($args) >= 2 ? ['labels'=>array_shift($args)] : [])+($args[0] ?? []));
}

function wpjam_get_chart_parameter(...$args){
	return wpjam_chart('get_parameter', ...$args);
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



function wpjam_register_theme_upgrader(){}

function wpjam_register_plugin_updater($hostname, $url){
	return wpjam_updater('plugin', $hostname, $url);
}

function wpjam_register_theme_updater($hostname, $url){
	return wpjam_updater('theme', $hostname, $url);
}

function wpjam_data_attribute_string($attr){
	return wpjam_attr($attr, 'data');
}

function wpjam_parse_attr($attr){
	return WPJAM_Attr::parse($attr);
}

function wpjam_get_ajax_data_attr($name, $data=[], $output=null){
	$attr	= WPJAM_AJAX::get_attr($name, $data);

	return $output ? ($attr ?: []) : ($attr ? wpjam_attr($attr, 'data') : null);
}

function wpjam_get_ajax_attribute_string($name, $data=[]){
	return wpjam_get_ajax_data_attr($name, $data);
}

function wpjam_get_ajax_attributes($name, $data=[]){
	return wpjam_get_ajax_data_attr($name, $data, '[]');
}

function wpjam_add_admin_error($msg='', $type='success'){
	wpjam_admin('error', $msg, $type);
}

function wpjam_get_admin_post_id(){
	return wpjam_admin('post_id');
}

if(!function_exists('wpjam_add_once_filter')){
	function wpjam_add_once_filter($name, ...$args){
		return wpjam_hook('once', $name, ...$args);
	}
}

function_alias('wpjam_every',	'array_all');
function_alias('wpjam_some',	'array_any');
function_alias('wpjam_array',	'array_wrap');
function_alias('wpjam_get',		'array_get');
function_alias('wpjam_set',		'array_set');
function_alias('wpjam_merge',	'merge_deep');

function_alias('wpjam_hook',	'wpjam_add_filter');

function_alias('wpjam_option',	'wpjam_setting');

function_alias('wpjam_ob', 'wpjam_ob_get_contents');
function_alias('wpjam_get_data_type', 'wpjam_get_data_type_object');

function_alias('is_login', 'wpjam_is_login');
function_alias('wpjam_ajax', 'wpjam_register_ajax');
function_alias('wpjam_route', 'wpjam_register_route');
function_alias('wpjam_route', 'wpjam_register_route_module');
function_alias('wpjam_options', 'wpjam_parse_options');
function_alias('wpjam_error', 'wpjam_parse_error');
function_alias('wpjam_error', 'wpjam_register_error_setting');

function_alias('array_first',	'array_value_first');
function_alias('array_last',	'array_value_last');

function_alias('wpjam_lazyloader', 'wpjam_register_lazyloader');
function_alias('wpjam_activation', 'wpjam_register_activation');
function_alias('wpjam_json_source', 'wpjam_register_source');

function_alias('wpjam_strip_control_chars', 'wpjam_strip_control_characters');
function_alias('wpjam_get_registered', 'wpjam_get_registered_object');

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

function_alias('wpjam_get_json_object', 'wpjam_get_api_setting');
function_alias('wpjam_get_json_object', 'wpjam_get_api');

function_alias('wpjam_get_path_object', 'wpjam_get_path_obj');
function_alias('wpjam_get_paths', 'wpjam_get_path_objs');

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

add_action('wpjam_api', fn($json)=> do_action('wpjam_api_template_redirect', $json));
add_filter('rewrite_rules_array', fn($rules)=> array_merge(apply_filters('wpjam_rewrite_rules', []), $rules));

if(is_admin()){
	add_action('wpjam_plugin_page', function($object, $type){
		if($type == 'tab'){
			$filter	= wpjam_get_filter_name($object->menu_slug, 'tabs');

			if(has_filter($filter)){
				$object->tabs	= apply_filters($filter, $object->get_arg('tabs', [], 'callback'));
			}
		}elseif(in_array($type, ['option', 'list_table']) && !$object->$type){
			$name	= $object->{$type.'_name'} ?: $object->menu_slug;
			$filter	= wpjam_get_filter_name($name, $type == 'option' ? 'setting' : $type);

			if(has_filter($filter)){
				$object->$type	= apply_filters($filter, []);
			}
		}
	}, 10, 2);
}

class WPJAM_Error{
	public static function parse($data){
		return wpjam_error($data);
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		return wpjam_remote_request($url, $args+['field'=>''], $err);
	}
}

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

class WPJAM_Platforms extends WPJAM_Args{
	public function parse_item($item, $suffix=''){
		return wpjam_parse_path_item($item, $this->names, $suffix);
	}

	public function validate_item($item, $suffix=''){
		return wpjam_validate_path_item($item, $this->names, $suffix);
	}

	public function get_fields($args=[]){
		return wpjam_get_path_fields($this->names, $args);
	}

	public function get_current(){
		$platforms	= WPJAM_Platform::get_by($this->names);

		return count($platforms) > 1 ? array_find($platforms, fn($v)=> $v->verify()) : array_first($platforms);
	}

	public static function get_instance($names=null){
		if($platforms	= array_filter(WPJAM_Platform::get_by((array)($names ?? ['path'=>true])))){
			return wpjam_var('platforms:'.implode('-', array_keys($platforms)), fn()=> new self(['names'=>array_keys($platforms)]));
		}
	}
}

trait WPJAM_Instance_Trait{
	use WPJAM_Call_Trait;

	public static function instance_exists($name){
		return wpjam_get_instance(self::get_called(), $name) ?: false;
	}

	public static function add_instance($name, $object){
		return wpjam_add_instance(self::get_called(), $name, $object);
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}

	public static function instance(...$args){
		if(count($args) == 2 && is_callable($args[1])){
			$name	= $args[0];
			$cb		= $args[1];
		}else{
			$name	= $args ? implode(':', $args) : 'singleton';
			$cb		= fn()=> static::create_instance(...$args);
		}

		return wpjam_get_instance(self::get_called(), $name, $cb);
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
			$filter	= strtolower(static::class).'_args';

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

	public static function register(...$args){
		if(count($args) == 1){
			$object	= $args[0];
			$name	= $object->name;
		}else{
			$name	= $args[0];
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
		return WPJAM_Meta_Type::get(self::$meta_type);
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
