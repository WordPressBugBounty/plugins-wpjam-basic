<?php
function wpjam_load($hooks, $callback, $priority=10){
	if(!$callback || !is_callable($callback)){
		return;
	}

	$hooks	= array_filter((array)$hooks, fn($hook)=> !did_action($hook));

	if(!$hooks){
		$callback();
	}elseif(count($hooks) == 1){
		add_action(reset($hooks), $callback, $priority);
	}else{
		array_walk($hooks, fn($hook)=> add_action($hook, fn()=> array_all($hooks, 'did_action') ? $callback() : null, $priority));
	}
}

function wpjam_init($callback){
	wpjam_load('init', $callback);
}

function wpjam_include($hooks, $include, $priority=10){
	wpjam_load($hooks, fn()=> array_map(fn($inc)=> include $inc, (array)$include), $priority);
}

function wpjam_hooks($name, ...$args){
	if(is_string($name) && in_array($name, ['add', 'remove'])){
		$type	= $name;
		$name	= array_shift($args);
	}else{
		$type	= 'add';
	}

	if($name && is_array($name)){
		if(wp_is_numeric_array(reset($name))){
			array_walk($name, fn($n)=> wpjam_hook($type, ...$n));
		}else{
			if($args){
				array_walk($name, fn($n)=> wpjam_hook($type, $n, ...$args));
			}else{
				wpjam_hook($type, ...$name);
			}
		}
	}elseif($name && is_string($name) && $args){
		$callback	= array_shift($args);

		if(is_array($callback) && !is_callable($callback)){
			array_walk($callback, fn($cb)=> wpjam_hook($type, $name, $cb, ...$args));
		}else{
			wpjam_hook($type, $name, $callback, ...$args);
		}
	}
}

function wpjam_hook($type, $name, $callback, ...$args){
	if($type == 'add'){
		add_filter($name, $callback, ...$args);
	}else{
		remove_filter($name, $callback, ...($args ?: [has_filter($name, $callback)]));
	}
}

function wpjam_call($callback, ...$args){
	if($callback && is_callable($callback)){
		return $callback(...$args);
	}
}

function wpjam_call_multiple($callback, $args){
	return array_map(fn($arg)=> wpjam_call($callback, ...$arg), $args);
}

function wpjam_try($callback, ...$args){
	return wpjam_if_error(wpjam_parse_callback(wpjam_if_error($callback, 'throw'), $args)(...$args), 'throw');
}

function wpjam_catch($callback, ...$args){
	if(is_a($callback, 'Exception')){
		return method_exists($callback, 'get_wp_error') ? $callback->get_wp_error() : new WP_Error($callback->getCode(), $callback->getMessage());
	}

	try{
		return wpjam_parse_callback($callback, $args)(...$args);
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_retry($times, $callback, ...$args){
	do{
		$times	-= 1;
		$result	= wpjam_catch($callback, ...$args);
	}while($result === false && $times > 0);

	return $result;
}

function wpjam_value_callback($args, $name, $id=null){
	$args	= is_callable($args) ? ['value_callback'=>$args] : $args;
	$id		??= $args['id'] ?? null;

	foreach([
		'data'				=> fn($v)=> wpjam_get($v, $name),
		'value_callback'	=> fn($v)=> wpjam_if_error(wpjam_catch($v, $name, $id), null),
		'meta_type'			=> fn($v)=> wpjam_get_metadata($v, $id, $name),
	] as $k => $cb){
		$value	= isset($args[$k]) ? $cb($args[$k]) : null;

		if(isset($value)){
			return $value;
		}
	}
}

function wpjam_ob_get_contents($callback, ...$args){
	if($callback && is_callable($callback)){
		ob_start();

		$callback(...$args);

		return ob_get_clean();
	}
}

function wpjam_call_for_blog($blog_id, $callback, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return $callback(...$args);
	}finally{
		if($switched){
			restore_current_blog();
		}
	}
}

function wpjam_build_callback_unique_id($callback){
	return _wp_filter_build_unique_id(null, $callback, null);
}

function wpjam_get_reflection($callback, $type=''){
	$class	= (is_array($callback) && !is_object($callback[0])) ? $callback[0] : '';

	return (WPJAM_Invoker::create($class))->get_reflection($class ? $callback[1] : $callback);
}

function wpjam_get_annotation($class, $key=''){
	return (WPJAM_Invoker::create($class))->get_annotation($key);
}

function wpjam_parse_callback($callback, &$args=[]){
	return (is_array($callback) && !is_object($callback[0])) ? wpjam_parse_method($callback[0], $callback[1], $args) : $callback;
}

function wpjam_parse_method($class, $method, &$args=[]){
	return (WPJAM_Invoker::create($class))->parse($method, $args);
}

function wpjam_call_method($class, $method, ...$args){
	return (WPJAM_Invoker::create($class))->call($method, ...$args);
}

if(!function_exists('maybe_callback')){
	function maybe_callback($value, ...$args){
		return $value && is_callable($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('maybe_closure')){
	function maybe_closure($value, ...$args){
		return $value && is_closure($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

function wpjam_if_error($value, ...$args){
	if($args && is_wp_error($value)){
		if(is_closure($args[0])){
			return array_shift($args)($value, ...$args);
		}elseif(in_array($args[0], [null, false, []], true)){
			return $args[0];
		}elseif($args[0] === 'die'){
			wp_die($value);
		}elseif($args[0] === 'throw'){
			wpjam_throw($value);
		}elseif($args[0] === 'send'){
			wpjam_send_json($value);
		}
	}

	return $value;
}

function wpjam_throw($errcode, $errmsg=''){
	throw new WPJAM_Exception(...(is_wp_error($errcode) ? [$errcode] : [$errmsg, $errcode]));
}

function wpjam_timer($callback, ...$args){
	try{
		$timestart	= microtime(true);

		return $callback(...$args);
	}finally{
		$log[]	= "Callback: ".var_export($callback, true);
		$log[]	= "Time: ".number_format(microtime(true)-$timestart, 5);

		if(is_closure($callback)){
			$reflection = wpjam_get_reflection($callback);

			$log[]	= "File: ".$reflection->getFileName();
			$log[]	= "Line: ".$reflection->getStartLine();
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

function wpjam_cache($key, ...$args){
	if(count($args) > 1 || ($args && (is_string($args[0]) || is_bool($args[0])))){
		$group	= array_shift($args);
		$cb		= array_shift($args);
		$arg	= array_shift($args);
		$fix	= is_bool($group) ? ($group ? 'site_' : '').'transient' : '';
		$group	= $fix ? '' : ($group ?: 'default');
		$args	= is_numeric($arg) ? ['expire'=>$arg] : (array)$arg;
		$expire	= wpjam_get($args, 'expire') ?: 86400;

		if($expire === -1){
			return $fix ? ('delete_'.$fix)($key) : wp_cache_delete($key, $group);
		}
		
		$force	= wpjam_get($args, 'force');
		$value	= $fix ? ('get_'.$fix)($key) : wp_cache_get($key, $group, ($force === 'get' || $force === true));

		if($cb && ($value === false || $force === 'set' || $force === true)){
			$value	= $cb($value, $key, $group);

			if(!is_wp_error($value) && $value !== false){
				$result	= $fix ? ('set_'.$fix)($key, $value, $expire) : wp_cache_set($key, $value, $group, $expire);
			}
		}

		return $value;
	}

	return WPJAM_Cache::get_instance($key, ...$args);
}

function wpjam_counts($name, $callback){
	return wpjam_cache($name, 'counts', $callback);
}

function wpjam_transient($name, $callback, $args=[], $global=false){
	return wpjam_cache($name, (bool)$global, $callback, $args);
}

function wpjam_increment($name, $max=0, $expire=86400, $global=false){
	return wpjam_transient($name, fn($v)=> ($max && (int)$v >= $max) ? 1 : (int)$v+1, ['expire'=>$expire, 'force'=>'set'], $global);
}

function wpjam_lock($name, $expire=10, $group=false){
	$group	= is_bool($group) ? ($group ? 'site-' : '').'transient' : ($group ?: 'default');

	return $expire == -1 ? wp_cache_delete($name, $group) : (wp_cache_get($name, $group, true) || !wp_cache_add($name, 1, $group, $expire));
}

function wpjam_is_over($name, $max, $time, $group=false, $action='increment'){
	$times	= wp_cache_get($name, $group) ?: 0;

	return ($times > $max) || ($action == 'increment' && wp_cache_set($name, $times+1, $group, ($max == $times && $time > 60) ? $time : 60) && false);
}

function wpjam_db_transaction($callback, ...$args){
	$GLOBALS['wpdb']->query("START TRANSACTION;");

	try{
		$result	= $callback(...$args);

		if($GLOBALS['wpdb']->last_error){
			wpjam_throw('error', $GLOBALS['wpdb']->last_error);
		}

		$GLOBALS['wpdb']->query("COMMIT;");

		return $result;
	}catch(Exception $e){
		$GLOBALS['wpdb']->query("ROLLBACK;");

		return false;
	}
}

// WPJAM
function wpjam($method='', ...$args){
	$object	= WPJAM_API::get_instance();

	if($method){
		if(!in_array($method, ['add', 'set', 'get', 'delete'])){
			$field	= $method;

			if(str_ends_with($field, '[]')){
				$field	= substr($field, 0, -2);
				$method	= $args ? (is_null(wpjam_at($args, -1)) ? 'delete' : 'add') : 'get';
			}else{
				$method	= $args && (count($args) > 1 || is_array($args[0])) ? 'set' : 'get';
			}

			array_unshift($args, $field);
		}

		return $object->$method(...$args);
	}

	return $object;
}

// Var
function wpjam_var($name, ...$args){
	[$group, $name]	= str_contains($name, ':') ? explode(':', $name, 2) : ['vars', $name];

	$value	= wpjam($group, $name);

	if($args && ($value === null || !is_closure($args[0]))){
		$value	= maybe_closure($args[0], $name, $group);
		$result	= is_wp_error($value) ? null : $value;

		wpjam($group, $name, $result);
	}

	return $value;
}

function wpjam_pattern($key, ...$args){
	return wpjam('pattern', $key, ...$args);
}

function wpjam_default(...$args){
	return wpjam('defaults', ...$args);
}

function wpjam_get_current_user($required=false){
	$value	= wpjam_var('user', fn()=> apply_filters('wpjam_current_user', null));

	if($required){
		return is_null($value) ? new WP_Error('bad_authentication') : $value;
	}else{
		return wpjam_if_error($value, null);
	}
}

// Parameter
function wpjam_get_parameter($name='', $args=[], $method=''){
	$object	= WPJAM_Parameter::get_instance();

	return $object->get($name, array_merge($args, $method ? compact('method') : []));
}

function wpjam_get_post_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'POST');
}

function wpjam_get_request_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'REQUEST');
}

function wpjam_get_data_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'data');
}

function wpjam_method_allow($method){
	$m	= $_SERVER['REQUEST_METHOD'];

	if($m != strtoupper($method)){
		wp_die('method_not_allow', '接口不支持 '.$m.' 方法，请使用 '.$method.' 方法！');
	}

	return true;
}

// Request
function wpjam_remote_request($url='', $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');
	$field	= wpjam_pull($args, 'field') ?? 'body';
	$result	= WPJAM_HTTP::request($url, $args, $err);

	if(is_wp_error($result)){
		return $throw ? wpjam_throw($result) : $result;
	}

	return $field ? wpjam_get($result, $field) : $result;
}

// Error
function wpjam_parse_error($data){
	return WPJAM_Error::parse($data);
}

function wpjam_add_error_setting($code, $message, $modal=[]){
	return WPJAM_Error::add_setting($code, $message, $modal);
}

// Route
function wpjam_register_route($module, $args){
	if($module){
		wpjam('route[]', $module, $args);
	}
}

function wpjam_get_query_var($key, $wp=null){
	$wp	= $wp ?: $GLOBALS['wp'];

	return $wp->query_vars[$key] ?? null;
}

// JSON
function wpjam_json_encode($data){
	return WPJAM_JSON::encode($data, JSON_UNESCAPED_UNICODE);
}

function wpjam_json_decode($json, $assoc=true){
	return WPJAM_JSON::decode($json, $assoc);
}

function wpjam_send_json($data=[], $status_code=null){
	WPJAM_JSON::send($data, $status_code);
}

function wpjam_register_json($name, $args=[]){
	return WPJAM_JSON::register($name, $args);
}

function wpjam_register_api($name, $args=[]){
	return wpjam_register_json($name, $args);
}

function wpjam_get_json_object($name){
	return WPJAM_JSON::get($name);
}

function wpjam_add_json_module_parser($type, $callback){
	return WPJAM_JSON::module_parser($type, $callback);
}

function wpjam_parse_json_module($module){
	return WPJAM_JSON::parse_module($module);
}

function wpjam_get_current_json($output='name'){
	return WPJAM_JSON::get_current($output);
}

function wpjam_is_json_request(){
	if(get_option('permalink_structure')){
		return (bool)preg_match("/\/api\/.*\.json/", $_SERVER['REQUEST_URI']);
	}else{
		return isset($_GET['module']) && $_GET['module'] == 'json';
	}
}

function wpjam_register_source($name, $callback, $query_args=['source_id']){
	WPJAM_JSON::source($name, $callback, $query_args);
}

function wpjam_register_activation($callback, $hook='wp_loaded'){
	WPJAM::activation([$hook, $callback]);
}

// wpjam_register_config($name, $value)
// wpjam_register_config($name, $args)
// wpjam_register_config($args)
// wpjam_register_config($name, $callback])
// wpjam_register_config($callback])
function wpjam_register_config(...$args){
	$group	= count($args) >= 3 ? array_shift($args) : '';
	$args	= array_filter($args, fn($v)=> isset($v));

	return $args ? WPJAM_Config::add($group, ...$args) : null;
}

function wpjam_get_config($group=''){
	return WPJAM_Config::get($group);
}

// Extend
function wpjam_load_extends($dir, ...$args){
	WPJAM_Extend::create($dir, ...$args);
}

function wpjam_get_file_summary($file){
	return WPJAM_Extend::get_file_summay($file);
}

// Video
function wpjam_add_video_parser($pattern, $callback){
	wpjam('video_parser[]', [$pattern, $callback]);
}

// Asset
function wpjam_asset($type, $handle, $args, $load=false){
	$args	= is_array($args) ? $args : ['src'=>$args];

	if($load || array_any(['wp', 'admin', 'login'], fn($part)=> doing_action($part.'_enqueue_scripts'))){
		$method	= wpjam_pull($args, 'method') ?: 'enqueue';

		if(empty($args[$method.'_if']) || $args[$method.'_if']($handle, $type)){
			$args	= wp_parse_args($args, ['src'=>'', 'deps'=>[], 'ver'=>false, 'media'=>'all', 'position'=>'after']);
			$src	= maybe_closure($args['src'], $handle);
			$data	= $args['data'] ?? '';

			if($src || !$data){
				wpjam_call('wp_'.$method.'_'.$type, $handle, $src, $args['deps'], $args['ver'], ($type == 'script' ? wpjam_pick($args, ['in_footer', 'strategy']) : $args['media']));
			}

			if($data){
				wpjam_call('wp_add_inline_'.$type, $handle, $data, $args['position']);
			}	
		}
	}else{
		$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
		$parts	= isset($args['for']) ? array_intersect($parts, wp_parse_list($args['for'] ?: 'wp')) : $parts;

		array_walk($parts, fn($part)=> wpjam_load($part.'_enqueue_scripts', fn()=> wpjam_asset($type, $handle, $args, true), ($args['priority'] ?? 10)));
	}
}

function wpjam_script($handle, $args=[]){
	wpjam_asset('script', $handle, $args);
}

function wpjam_style($handle, $args=[]){
	wpjam_asset('style', $handle, $args);
}

// Capability
function wpjam_map_meta_cap($cap, $map){
	if($cap && $map && (is_callable($map) || wp_is_numeric_array($map))){
		wpjam('map_meta_cap[]', $cap, $map);
	}
}

function wpjam_current_user_can($capability, ...$args){
	return ($capability = maybe_closure($capability, ...$args)) ? current_user_can($capability, ...$args) : true;
}

// Rewrite Rule
function wpjam_add_rewrite_rule($args){
	if(did_action('init')){
		$args	= maybe_callback($args);

		if($args && is_array($args)){
			if(is_array($args[0])){
				array_walk($args, 'wpjam_add_rewrite_rule');
			}else{
				add_rewrite_rule(...[$GLOBALS['wp_rewrite']->root.array_shift($args), ...$args]);
			}
		}
	}else{
		add_action('init', fn()=> wpjam_add_rewrite_rule($args));
	}
}

// Menu Page
function wpjam_add_menu_page(...$args){
	if(is_array($args[0])){
		if(wp_is_numeric_array($args[0])){
			return array_walk($args[0], 'wpjam_add_menu_page');
		}

		$args	= $args[0];
		$type	= array_find(['tab_slug'=>'tabs', 'menu_slug'=>'pages'], fn($v, $k)=> !empty($args[$k]) && !is_numeric($args[$k]));

		if(!$type){
			return;
		}
	}else{
		$slug	= $args[0];
		$key	= !empty($args[1]['plugin_page']) ? 'tab_slug' : 'menu_slug';
		$type	= $key == 'tab_slug' ? 'tabs' : 'pages';
		$args	= array_merge($args[1], [$key => $slug]);

		if(!is_admin() && wpjam_get($args, 'function') == 'option' && (!empty($args['sections']) || !empty($args['fields']))){
			wpjam_register_option(($args['option_name'] ?? $slug), $args);
		}
	}

	$model	= $args['model'] ?? '';
	$cap	= $args['capability'] ?? '';

	if($model){
		wpjam_hooks(wpjam_call([$model, 'add_hooks']));
		wpjam_init([$model, 'init']);

		if($cap && method_exists($model, 'map_meta_cap')){
			$args['map_meta_cap']	= [$model, 'map_meta_cap'];
		}
	}

	if($cap && !empty($args['map_meta_cap'])){
		wpjam_map_meta_cap($cap, wpjam_pull($args, 'map_meta_cap'));
	}

	if(is_admin()){
		wpjam_admin($type.'[]', $args);
	}
}

if(is_admin()){
	if(!function_exists('get_screen_option')){
		function get_screen_option($option, $key=false){
			$screen	= did_action('current_screen') ? get_current_screen() : null;

			if($screen){
				if(in_array($option, ['post_type', 'taxonomy'])){
					return $screen->$option;
				}

				$value	= $screen->get_option($option);

				return $key ? ($value ? wpjam_get($value, $key) : null) : $value;
			}
		}
	}

	function wpjam_admin(...$args){
		$object	= WPJAM_Admin::get_instance();

		return $args ? $object->call(...$args) : $object;
	}

	function wpjam_add_admin_ajax($action, $args=[]){
		wpjam_register_ajax($action, ['admin'=>true]+$args);
	}

	function wpjam_add_admin_error($msg='', $type='success'){
		if(is_wp_error($msg)){
			$msg	= $msg->get_error_message();
			$type	= 'error';
		}

		if($msg && $type){
			add_action('all_admin_notices',	fn()=> wpjam_echo(wpjam_tag('div', ['is-dismissible', 'notice', 'notice-'.$type], ['p', [], $msg])));
		}
	}

	function wpjam_add_admin_load($args){
		if(wp_is_numeric_array($args)){
			array_walk($args, 'wpjam_add_admin_load');
		}else{
			$type	= wpjam_pull($args, 'type') ?: array_find(['base'=>'builtin_page', 'plugin_page'=>'plugin_page'], fn($v, $k)=> isset($args[$k])) ?: '';

			if(!in_array($type, ['builtin_page', 'plugin_page'])){
				return;
			}

			wpjam_admin($type.'_load[]', $args);
		}
	}

	function wpjam_admin_tooltip($text, $tooltip){
		return $text ? '<span class="tooltip" data-tooltip="'.esc_attr($tooltip).'">'.$text.'</span>' : '<span class="dashicons dashicons-editor-help tooltip" data-tooltip="'.esc_attr($tooltip).'"></span>';
	}

	function wpjam_get_referer(){
		$referer	= wp_get_original_referer() ?: wp_get_referer();
		$removable	= [...wp_removable_query_args(), '_wp_http_referer', 'action', 'action2', '_wpnonce'];

		return remove_query_arg($removable, $referer);
	}

	function wpjam_get_admin_post_id(){
		return (int)($_GET['post'] ?? ($_POST['post_ID'] ?? 0));
	}

	function wpjam_register_page_action($name, $args){
		return WPJAM_Page_Action::create($name, $args);
	}

	function wpjam_get_page_button($name, $args=[]){
		return ($object = WPJAM_Page_Action::get($name)) ? $object->get_button($args) : '';
	}

	function wpjam_register_list_table_action($name, $args){
		return WPJAM_List_Table_Action::register($name, $args);
	}

	function wpjam_unregister_list_table_action($name, $args=[]){
		return WPJAM_List_Table_Action::unregister($name, $args);
	}

	function wpjam_register_list_table_column($name, $field){
		return WPJAM_List_Table_Column::register($name, $field);
	}

	function wpjam_unregister_list_table_column($name, $field=[]){
		return WPJAM_List_Table_Column::unregister($name, $field);
	}

	function wpjam_register_list_table_view($name, $view=[]){
		return WPJAM_List_Table_View::register($name, $view);
	}

	function wpjam_register_dashboard_widget($name, $args){
		WPJAM_Dashboard::add_widget($name, $args);
	}

	function wpjam_chart($type, $data, $args){
	}

	function wpjam_line_chart($data, $labels, $args=[]){
		echo WPJAM_Chart::line(array_merge($args, ['labels'=>$labels, 'data'=>$data]));
	}

	function wpjam_bar_chart($data, $labels, $args=[]){
		echo WPJAM_Chart::line(array_merge($args, ['labels'=>$labels, 'data'=>$data]), 'Bar');
	}

	function wpjam_donut_chart($data, ...$args){
		$args	= count($args) >= 2 ? array_merge($args[1], ['labels'=> $args[0]]) : ($args[0] ?? []);

		echo WPJAM_Chart::donut(array_merge($args, ['data'=>$data]));
	}

	function wpjam_get_chart_parameter($key){
		return (WPJAM_Chart::get_instance())->get_parameter($key);
	}

	function wpjam_render_callback($cb){
		if(is_array($cb)){
			$cb	= (is_object($cb[0]) ? get_class($cb[0]).'->' : $cb[0].'::').(string)$cb[1];
		}elseif(is_object($cb)){
			$cb	= get_class($cb);
		}

		return wpautop($cb);
	}
}

wpjam_load_extends(dirname(__DIR__).'/components');
wpjam_load_extends(dirname(__DIR__).'/extends', [
	'option'	=> 'wpjam-extends',
	'sitewide'	=> true,
	'title'		=> '扩展管理',
	'hook'		=> 'plugins_loaded',
	'priority'	=> 1,
	'menu_page'	=> [
		'parent'	=> 'wpjam-basic',
		'order'		=> 3,
		'function'	=> 'tab',
		'tabs'		=> ['extends'=>['order'=>20, 'title'=>'扩展管理', 'function'=>'option', 'option_name'=>'wpjam-extends']]
	]
]);

wpjam_load_extends([
	'dir'		=> fn()=> get_template_directory().'/extends',
	'hook'		=> 'plugins_loaded',
	'priority'	=> 0,
]);

wpjam_style('remixicon', [
	'src'		=> fn()=> wpjam_get_static_cdn().'/remixicon/4.2.0/remixicon.min.css',
	'method'	=> is_admin() ? 'enqueue' : 'register',
	'data'		=> is_admin() ? "\n".'.wp-menu-image[class*=" ri-"]:before{display:inline-block; line-height:1; font-size:20px;}' : '',
	'priority'	=> 1
]);

wpjam_pattern('key', [
	'pattern'			=> '^[a-zA-Z][a-zA-Z0-9_\-]*$',
	'custom_validity'	=> '请输入英文字母、数字和 _ -，并以字母开头！'
]);

wpjam_pattern('slug', [
	'pattern'			=> '[a-z0-9_\\-]+',
	'custom_validity'	=> '请输入小写英文字母、数字和 _ -！'
]);

wpjam_map([
	['bad_authentication',	'无权限'],
	['access_denied',		'操作受限'],
	['incorrect_password',	'密码错误'],
	['undefined_method',	fn($args)=> '「%s」'.(count($args) >= 2 ? '%s' : '').'未定义'],
	['quota_exceeded',		fn($args)=> '%s超过上限'.(count($args) >= 2 ? '「%s」' : '')],
], fn($args)=> wpjam_add_error_setting(...$args));

wpjam_register_bind('phone', '', ['domain'=>'@phone.sms']);
wpjam_register_route('json',	['model'=>'WPJAM_JSON']);
wpjam_register_route('txt',		['model'=>'WPJAM_Verify_TXT']);

add_action('plugins_loaded',	['WPJAM_API', 'on_plugins_loaded'], 0);

if(wpjam_is_json_request()){
	ini_set('display_errors', 0);

	remove_filter('the_title', 'convert_chars');

	remove_action('init', 'wp_widgets_init', 1);
	remove_action('init', 'maybe_add_existing_user_to_blog');
	// remove_action('init', 'check_theme_switched', 99);

	remove_action('plugins_loaded', 'wp_maybe_load_widgets', 0);
	remove_action('plugins_loaded', 'wp_maybe_load_embeds', 0);
	remove_action('plugins_loaded', '_wp_customize_include');
	remove_action('plugins_loaded', '_wp_theme_json_webfonts_handler');

	remove_action('wp_loaded', '_custom_header_background_just_in_time');
	remove_action('wp_loaded', '_add_template_loader_filters');
}
