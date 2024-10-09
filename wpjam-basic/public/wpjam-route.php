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
		array_walk($hooks, fn($hook)=> add_action($hook, fn()=> wpjam_every($hooks, 'did_action') ? $callback() : null, $priority));
	}
}

function wpjam_include($hooks, $include, $priority=10){
	wpjam_load($hooks, fn()=> array_map(fn($inc)=> include $inc, (array)$include), $priority);
}

function wpjam_loaded($action, ...$args){
	wpjam_load('wp_loaded', fn()=> do_action($action, ...$args));
}

function wpjam_hooks($hooks){
	$hooks	= is_callable($hooks) ? $hooks() : $hooks;

	if($hooks && is_array($hooks)){
		if(wp_is_numeric_array(reset($hooks))){
			array_walk($hooks, fn($args)=> add_filter(...$args));
		}else{
			add_filter(...$hooks);
		}
	}
}

function wpjam_call($callback, ...$args){
	if(is_array($callback) && !is_object($callback[0])){
		return wpjam_call_method($callback[0], $callback[1], ...$args);
	}else{
		return $callback(...$args);
	}
}

function wpjam_call_array($callback, $args){
	return wpjam_call($callback, ...$args);
}

function wpjam_try($callback, ...$args){
	try{
		return wpjam_throw_if_error(wpjam_call(wpjam_throw_if_error($callback), ...$args));
	}catch(Throwable $e){
		throw $e;
	}
}

function wpjam_try_array($callback, $args){
	return wpjam_try($callback, ...$args);
}

function wpjam_catch($callback, ...$args){
	try{
		if(is_a($callback, 'WPJAM_Exception')){
			return $callback->get_wp_error();
		}elseif(is_a($callback, 'Exception')){
			return new WP_Error($e->getCode(), $e->getMessage());
		}else{
			return wpjam_call($callback, ...$args);
		}
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_catch_array($callback, $args){
	return wpjam_catch($callback, ...$args);
}

function wpjam_throw($errcode, $errmsg=''){
	throw new WPJAM_Exception(...(is_wp_error($errcode) ? [$errcode] : [$errmsg, $errcode]));
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

function wpjam_ob_get_contents($callback, ...$args){
	ob_start();

	$callback(...$args);

	return ob_get_clean();
}

function wpjam_transient($name, $callback, $expire=86400){
	$data	= get_transient($name);

	if($data === false){
		$data	= $callback();

		if(!is_wp_error($data)){
			set_transient($name, $data, $expire);
		} 
	}

	return $data;
}

function wpjam_counts($name, $callback){
	$counts	= wp_cache_get($name, 'counts');

	if($counts === false){
		$counts	= $callback();

		if(!is_wp_error($counts)){
			wp_cache_set($name, $counts, 'counts');
		}
	}

	return $counts;
}

function wpjam_lock($name, $value=1, $expire=10){
	$locked	= get_transient($name);

	if($locked === false){
		set_transient($name, $value, $expire);
	}

	return $locked;
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

function wpjam_value_callback($callback, $name, $id){
	if(is_array($callback) && !is_object($callback[0])){
		$args	= [$id, $name];
		$parsed	= wpjam_parse_method($callback[0], $callback[1], $args);

		if(is_wp_error($parsed)){
			return $parsed;
		}elseif(is_object($parsed[0])){
			return $parsed(...$args);
		}
	}

	return $callback($name, $id);
}

function wpjam_verify_callback($callback, $verify){
	$reflection	= wpjam_get_reflection($callback);

	return $verify($reflection->getParameters(), $reflection);
}

function wpjam_get_callback_parameters($callback){
	return wpjam_get_reflection($callback)->getParameters();
}

function wpjam_build_callback_unique_id($callback){
	return _wp_filter_build_unique_id(null, $callback, null);
}

function wpjam_get_reflection($callback){
	$id	= wpjam_build_callback_unique_id($callback);

	return wpjam_get_instance('reflection', $id, fn()=> is_array($callback) ? new ReflectionMethod(...$callback) : new ReflectionFunction($callback));
}

function wpjam_parse_method($class, $method, &$args=[]){
	if(is_object($class)){
		$object	= $class;
		$class	= get_class($class);
	}else{
		if(!class_exists($class)){
			return new WP_Error('invalid_model', [$class]);
		}
	}

	$cb	= [$class, $method];

	if(!method_exists(...$cb)){
		if(method_exists($class, '__callStatic')){
			$is_public = true;
			$is_static = true;
		}elseif(method_exists($class, '__call')){
			$is_public = true;
			$is_static = false;
		}else{
			return new WP_Error('undefined_method', implode('::', $cb));
		}
	}else{
		$reflection	= wpjam_get_reflection($cb);
		$is_public	= $reflection->isPublic();
		$is_static	= $reflection->isStatic();
	}

	if($is_static){
		return $is_public ? $cb : $reflection->getClosure();
	}

	if(!isset($object)){
		$fn		= [$class, 'get_instance'];

		if(!method_exists(...$fn)){
			return new WP_Error('undefined_method', implode('::', $fn));
		}

		$number	= wpjam_get_reflection($fn)->getNumberOfRequiredParameters();
		$number	= $number > 1 ? $number : 1;

		if(count($args) < $number){
			return new WP_Error('instance_required', '实例方法对象才能调用');
		}

		$object	= $fn(...array_slice($args, 0, $number));

		if(!$object){
			return new WP_Error('invalid_id', [$class]);
		}

		$args	= array_slice($args, $number);
	}

	$cb[0]	= $object;

	return $is_public ? $cb : $reflection->getClosure($cb[0]);
}

function wpjam_call_method($class, $method, ...$args){
	$parsed	= wpjam_parse_method($class, $method, $args);

	return is_wp_error($parsed) ? $parsed : $parsed(...$args);
}

function wpjam_timer($callback, ...$args){
	try{
		$timestart	= microtime(true);

		return $callback(...$args);
	}finally{
		$log	= "Callback: ".var_export($callback, true)."\n";
		$log	.= "Time: ".number_format(microtime(true)-$timestart, 5)."\n";

		if(is_closure($callback)){
			$reflection = wpjam_get_reflection($callback);

			$log	.= "File: ".$reflection->getFileName(). "\n";
			$log	.= "Line: ".$reflection->getStartLine() . "\n";
		}

		trigger_error($log."\n\n");
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

function wpjam_die_if_error($result){
	if(is_wp_error($result)){
		wp_die($result);
	}

	return $result;
}

function wpjam_throw_if_error($result){
	if(is_wp_error($result)){
		wpjam_throw($result);
	}

	return $result;
}

// Var
function wpjam_var($name=null, ...$args){
	static $object;

	$object	= $object ?? new WPJAM_Args(wpjam_parse_user_agent());

	if(!$name){
		return $object;
	}

	if($args){
		$value	= $args[0];

		if(is_closure($value)){
			if(is_null($object->$name)){
				$value	= $value();

				if(!is_null($value) && !is_wp_error($value)){
					$object->$name	= $value;
				}
			}
		}else{
			$object->$name = $value;
		}
	}

	return $object->$name;
}

function wpjam_get_current_var($name){
	return wpjam_var($name);
}

function wpjam_set_current_var($name, $value){
	return wpjam_var($name, $value);
}

function wpjam_get_current_user($required=false){
	$value	= wpjam_var('user');

	if(!isset($value)){
		$value	= apply_filters('wpjam_current_user', null);

		if(!is_null($value) && !is_wp_error($value)){
			wpjam_var('user', $value);
		}
	}

	if($required){
		return is_null($value) ? new WP_Error('bad_authentication') : $value;
	}else{
		return is_wp_error($value) ? null : $value;
	}
}

function wpjam_current_supports($feature){
	$object	= wpjam_var();

	if($feature == 'webp'){
		return $object->browser == 'chrome' || $object->os == 'Android' || ($object->os == 'iOS' && version_compare($object->os_version, 14) >= 0);
	}
}

function wpjam_get_device(){
	return wpjam_var('device');
}

function wpjam_get_os(){
	return wpjam_var('os');
}

function wpjam_get_app(){
	return wpjam_var('app');
}

function wpjam_get_browser(){
	return wpjam_var('browser');
}

function wpjam_get_version($key){
	return wpjam_var($key.'_version');
}

function is_ipad(){
	return wpjam_get_device() == 'iPad';
}

function is_iphone(){
	return wpjam_get_device() == 'iPone';
}

function is_ios(){
	return wpjam_get_os() == 'iOS';
}

function is_macintosh(){
	return wpjam_get_os() == 'Macintosh';
}

function is_android(){
	return wpjam_get_os() == 'Android';
}

function is_weixin(){
	if(isset($_GET['weixin_appid'])){
		return true;
	}

	return wpjam_get_app() == 'weixin';
}

function is_weapp(){
	if(isset($_GET['appid'])){
		return true;
	}

	return wpjam_get_app() == 'weapp';
}

function is_bytedance(){
	if(isset($_GET['bytedance_appid'])){
		return true;
	}

	return wpjam_get_app() == 'bytedance';
}

// Parameter
function wpjam_get_parameter($name='', $args=[], $method=''){
	return WPJAM_API::get_parameter($name, array_merge($args, $method ? compact('method') : []));
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
	return WPJAM_API::method_allow($method);
}

// Request
function wpjam_remote_request($url='', $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');
	$field	= wpjam_pull($args, 'field') ?? 'body';
	$result	= WPJAM_API::request($url, $args, $err);

	if(is_wp_error($result)){
		return $throw ? wpjam_throw($result) : $result;
	}

	return $field ? wpjam_get($result, $field) : $result;
}

// Error
function wpjam_parse_error($data){
	if($data === true){
		return ['errcode'=>0];
	}

	if($data === false || is_null($data)){
		return ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
	}

	if(is_array($data)){
		if(!$data || !wp_is_numeric_array($data)){
			$data	+= ['errcode'=>0];
		}
	}elseif(is_wp_error($data)){
		$errdata	= $data->get_error_data();
		$data		= [
			'errcode'	=> $data->get_error_code(),
			'errmsg'	=> $data->get_error_message(),
		];

		if($errdata){
			$errdata	= is_array($errdata) ? $errdata : ['errdata'=>$errdata];
			$data 		= $data + $errdata;
		}
	}else{
		return $data;
	}

	return empty($data['errcode']) ? $data : WPJAM_Error::filter($data);
}

function wpjam_register_error_setting($code, $message='', $modal=[]){
	return WPJAM_Error::add_setting($code, $message, $modal);
}

// Route
function wpjam_register_route($module, $args){
	WPJAM_Route::add($module, $args);
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
	return WPJAM_JSON::add_module_parser($type, $callback);
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

function wpjam_register_activation($callback, $hook='wp_loaded'){
	WPJAM_API::activation('add', $hook, $callback);
}

function wpjam_register_source($name, $callback, $query_args=['source_id']){
	if($name && $name == wpjam_get_parameter('source')){
		add_filter('wpjam_pre_json', fn($pre)=> $callback(wpjam_get_parameter($query_args)) ?? $pre);
	}
}

// wpjam_register_config($key, $value)
// wpjam_register_config($name, $args)
// wpjam_register_config($args)
// wpjam_register_config($name, $callback])
// wpjam_register_config($callback])
function wpjam_register_config(...$args){
	$group	= count($args) >= 3 ? array_shift($args) : '';
	$args	= array_filter($args, fn($v)=> isset($v));

	if($args){
		if(is_array($args[0]) || count($args) == 1){
			$args	= is_callable($args[0]) ? ['callback'=>$args[0]] : (is_array($args[0]) ? $args[0] : [$args[0]=> null]);
		}else{
			$args	= is_callable($args[1]) ? ['name'=>$args[0], 'callback'=>$args[1]] : [$args[0]=>$args[1]];
		}

		wpjam_add_item('config', null, $args, $group);
	}
}

function wpjam_get_config($group=null){
	$group	= is_array($group) ? array_get($group, 'group') : $group;
	$items	= wpjam_get_items('config', $group) ?: [];

	return array_reduce($items, function($config, $item){
		if(!empty($item['callback'])){
			$name	= $item['name'] ?? '';
			$args	= $item['args'] ?? [];
			$args	= $args ?: ($name ? [$name] : []);
			$value	= $item['callback'](...$args);
			$item	= $name ? [$name=> $value] : (is_array($value) ? $value : []);
		}

		return array_merge($config, $item);
	}, []);
}

// Extend
function wpjam_load_extends($dir, ...$args){
	WPJAM_Extend::create($dir, ...$args);
}

function wpjam_get_file_summary($file){
	return WPJAM_Extend::get_file_summay($file);
}

function wpjam_get_extend_summary($file){
	return WPJAM_Extend::get_file_summay($file);
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

	function wpjam_add_admin_ajax($action, $args=[]){
		if(isset($_POST['action']) && $_POST['action'] == $action){
			wpjam_var('admin_ajax', $args);

			add_action('wp_ajax_'.$action, function(){
				add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_Error', 'wp_die_handler']);

				$args	= wpjam_var('admin_ajax');
				$args	= wpjam_is_assoc_array($args) ? $args : ['callback'=>$args];
				$fields	= $args['fields'] ?? [];
				$data	= wpjam_catch('wpjam_get_fields_parameter', $fields, 'POST');
				$result	= is_wp_error($data) ? $data : wpjam_catch($args['callback'], $data);

				wpjam_send_json($result);
			});
		}
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
			$type	= wpjam_pull($args, 'type');
			$type	= $type ?: wpjam_find(['base'=>'builtin_page', 'plugin_page'=>'plugin_page'], fn($v, $k)=> isset($args[$k]));

			if($type && in_array($type, ['builtin_page', 'plugin_page'])){
				$score	= wpjam_get($args, 'order', 10);

				wpjam_add_item($type.'_load', $args, fn($v)=> $score > wpjam_get($v, 'order', 10));
			}
		}
	}

	function wpjam_admin_load($type, $filter, ...$args){
		foreach(wpjam_get_items($type.'_load') as $load){
			if(!$filter($load, ...$args)){
				continue;
			}

			if(!empty($load['page_file'])){
				wpjam_map((array)$load['page_file'], fn($file)=> is_file($file) ? include $file : null);
			}

			if(!empty($load['callback'])){
				$callback	= is_callable($load['callback']) ? $load['callback'] : null;
			}elseif(!empty($load['model'])){
				$method		= wpjam_find(['load', $type.'_load'], fn($method)=> method_exists($load['model'], $method));
				$callback	= $method ? [$load['model'], $method] : null;
			}

			if(!empty($callback)){
				$callback(...$args);
			}
		}
	}

	function wpjam_admin_tooltip($text, $tooltip){
		return '<div class="wpjam-tooltip">'.$text.'<div class="wpjam-tooltip-text">'.wpautop($tooltip).'</div></div>';
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
		return WPJAM_Page_Action::register($name, $args);
	}

	function wpjam_get_page_button($name, $args=[]){
		$object	= WPJAM_Page_Action::get($name);

		return $object ? $object->get_button($args) : '';
	}

	function wpjam_register_list_table_action($name, $args){
		return WPJAM_List_Table::register($name, $args, 'action');
	}

	function wpjam_unregister_list_table_action($name, $args=[]){
		WPJAM_List_Table::unregister($name, $args, 'action');
	}

	function wpjam_register_list_table_column($name, $field){
		return WPJAM_List_Table::register($name, $field, 'column');
	}

	function wpjam_unregister_list_table_column($name, $field=[]){
		WPJAM_List_Table::unregister($name, $field, 'column');
	}

	function wpjam_register_list_table_view($name, $view=[]){
		return WPJAM_List_Table::register($name, $view, 'view');
	}

	function wpjam_register_dashboard_widget($name, $args){
		WPJAM_Dashboard::add_widget($name, $args);
	}

	function wpjam_get_plugin_page_setting($key='', $tab=false){
		$object	= WPJAM_Plugin_Page::get_current();

		if($object){
			return $object->get_setting($key, $tab);
		}
	}

	function wpjam_get_current_tab_setting($key=''){
		return wpjam_get_plugin_page_setting($key, true);
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
	'dir'			=> fn()=> get_template_directory().'/extends',
	'hook'			=> 'plugins_loaded',
	'priority'		=> 0,
	'hierarchical'	=> true,
]);

wpjam_style('remixicon', [
	'src'		=> fn()=> wpjam_get_static_cdn().'/remixicon/4.2.0/remixicon.min.css',
	'method'	=> 'register',
	'priority'	=> 1
]);

wpjam_add_pattern('key', [
	'pattern'			=> '^[a-zA-Z][a-zA-Z0-9_\-]*$',
	'custom_validity'	=> '请输入英文字母、数字和 _ -，并以字母开头！'
]);

wpjam_add_pattern('slug', [
	'pattern'			=> '[a-z0-9_\\-]+',
	'custom_validity'	=> '请输入小写英文字母、数字和 _ -！'
]);

wpjam_add_static_cdn([
	'https://cdnjs.cloudflare.com/ajax/libs',
	'https://lib.baomitu.com',
	'https://cdnjs.loli.net/ajax/libs',
]);

wpjam_register_error_setting([
	['bad_authentication',	'无权限'],
	['access_denied',		'操作受限'],
	['incorrect_password',	'密码错误'],
	['undefined_method',	fn($args)=> sprintf('「%s」'.(count($args) >= 2 ? '%s' : '').'未定义', ...$args)],
	['quota_exceeded',		fn($args)=> sprintf('%s超过上限'.(count($args) >= 2 ? '「%s」' : ''), ...$args)],
]);

wpjam_register_route('json',	['model'=>'WPJAM_JSON']);
wpjam_register_route('txt',		['model'=>'WPJAM_Verify_TXT']);

add_action('plugins_loaded',	['WPJAM_API', 'on_plugins_loaded'], 0);

if(is_admin()){
	add_action('plugins_loaded',	['WPJAM_Admin', 'on_plugins_loaded']);
}

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
