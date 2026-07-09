<?php
function wpjam($field='', ...$args){
	$object	= WPJAM_API::get_instance();

	return $field ? $object($field, ...$args) : $object;
}

function wpjam_var($name, ...$args){
	$names	= str_contains($name, ':') ? explode(':', $name, 2) : ['vars', $name];
	$value	= wpjam(...$names);

	if($args && ($value === null || !is_closure($args[0]))){
		$value	= maybe_closure($args[0], ...array_reverse($names));

		wpjam(...[...$names, wpjam_if_error($value, null)]);
	}

	return $value;
}

function wpjam_default(...$args){
	return wpjam('defaults', ...$args);
}

function wpjam_config(...$args){
	return wpjam('config', ...$args);
}

function wpjam_is(...$args){
	return wpjam('is', ...$args);
}

if(!function_exists('current_shortcode')){
	function current_shortcode() {
		return array_last(wpjam('shortcode'));
	}
}

if(!function_exists('current_shortcode')){
	function current_query(){
		return array_last(wpjam('query'));
	}
}

if(!function_exists('doing_shortcode')){
	function doing_shortcode($tag=null){
		$tags	= wpjam('shortcode');

		return null === $tag ? !empty($tags) : in_array($tag, $tags, true);
	}
}

function wpjam_ajax($name, $args=[]){
	if(empty($args['callback'])){
		$action	= wpjam('ajax', $name, $args);

		return wpjam_attr(['action'=>$name, 'data'=>$args]+($action ? ['nonce'=>wp_create_nonce($action)] : []), 'data');
	}elseif(empty($args['admin']) || wp_doing_ajax()){
		wpjam_init(fn()=> wpjam('ajax', $name, $args));
	}
}

// Parameter
function wpjam_params($type, $fields=[]){
	$data	= wpjam('param', $type);
	$fields	= $fields && is_array($fields) ? wpjam_fields($fields) : $fields;

	return $fields ? array_merge($data, $fields->validate($data, 'parameter')) : $data;
}

function wpjam_param($name, $args=[]){
	if(is_array($name)){
		return $name ? wpjam_map(wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name, 'wpjam_param', 'kv') : [];
	}

	$args	= is_array($args) ? $args : ['method'=>$args];
	$value	= wpjam('param', wpjam_pull($args, 'method'), $name);
	$value	??= $args['default'] ?? wpjam_default($name);

	if($name && ($field = wpjam_except($args, ['default', 'send']))){
		$field	= wpjam_field(['key'=>$name]+(($field['type'] ?? '') ? [] : ['_schema'=>[]])+$field);
		$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

		($args['send'] ?? true) && wpjam_if_error($value, 'send');
	}

	return $value;
}

// Request
function wpjam_remote_request($url, $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');

	try{
		return wpjam('request', $url, $args, $err);
	}catch(Exception $e){
		$error	= wpjam_fill(['code', 'message', 'data'], fn($k)=> [$e, 'get_error_'.$k]());

		if(apply_filters('wpjam_http_response_error_debug', true, $error['code'], $error['message'])){
			trigger_error(var_export(array_filter(['url'=>$url, 'error'=>array_filter($error), 'body'=>$args['body'] ?? '']), true));
		}

		if($throw){
			throw $e;
		}

		return wpjam_catch($e);
	}
}

function wpjam_script(...$args){
	if($args && $args[0]){
		if(count($args) > 1){
			wpjam('enqueue', 'script', ...$args);
		}else{
			wpjam_admin('script', ...$args);
		}
	}
}

function wpjam_style(...$args){
	if($args && $args[0]){
		if(is_array($args[0]) || (str_contains($args[0], '{') && str_contains($args[0], '}'))){
			wpjam_admin('style', ...$args);
		}else{
			wpjam('enqueue', 'style', ...$args);
		}
	}
}

// User Agent
function wpjam_ua($key='', ...$args){
	$name	= 'user_agent';
	$ua		= wpjam($name) ?: wpjam($name, wpjam_parse_user_agent());

	return $key ? ($args ? wpjam($name, [$key=>$args[0]]+$ua) : $ua[$key]) : $ua;
}

function wpjam_get_device(){
	return wpjam_ua('device');
}

function wpjam_get_os(){
	return wpjam_ua('os');
}

function wpjam_get_app(){
	return wpjam_ua('app');
}

function wpjam_get_browser(){
	return wpjam_ua('browser');
}

function wpjam_get_version($key){
	return wpjam_ua($key.'_version');
}

if(!function_exists('is_iphone')){
	function is_iphone(){
		return wpjam_ua('device') == 'iPhone';
	}
}

if(!function_exists('is_ipad')){
	function is_ipad(){
		return wpjam_ua('device') == 'iPad';
	}
}

if(!function_exists('is_ios')){
	function is_ios(){
		return wpjam_ua('os') == 'iOS';
	}
}

if(!function_exists('is_macintosh')){
	function is_macintosh(){
		return wpjam_ua('os') == 'Macintosh';
	}
}

if(!function_exists('is_android')){
	function is_android(){
		return wpjam_ua('os') == 'Android';
	}
}

if(!function_exists('is_weixin')){
	function is_weixin(){
		return isset($_GET['weixin_appid']) || wpjam_ua('app') == 'weixin';
	}
}

if(!function_exists('is_weapp')){
	function is_weapp(){
		return isset($_GET['appid']) || wpjam_ua('app') == 'weapp';
	}
}

if(!function_exists('is_bytedance')){
	function is_bytedance(){
		return isset($_GET['bytedance_appid']) || wpjam_ua('app') == 'bytedance';
	}
}

// Hook
function wpjam_hooks($name, ...$args){
	if(is_string($name) && str_ends_with($name, '::add_hooks')){
		$name	= wpjam_call_method($name);
	}

	if(is_array($name)){
		return wpjam_map(array_filter($name), fn($n)=> wpjam_hooks(...(is_array($n) ? $n : [$n, ...$args])));
	}

	return WPJAM_Hook::batch(...($name === '-' ? ['remove', ...$args] : ['add', $name, ...$args]));
}

function wpjam_hook($name, ...$args){
	return $name === '-' ? WPJAM_Hook::remove(...$args) : WPJAM_Hook::add($name, ...$args);
}

function wpjam_once($name, ...$args){
	if($name == 'maybe'){
		return wpjam_hook('once', array_shift($args), $name, ...$args);
	}

	return wpjam_hook('once', $name, ...$args);
}

function wpjam_echo($value, ...$args){
	if($args && is_string($args[0])){
		return wpjam_hook(array_shift($args), fn()=> wpjam_echo($value), ...$args);
	}elseif($args && $args[0] === false){
		return $value;
	}else{
		echo $value;
	}
}

function wpjam_load($hook, $cb, ...$args){
	WPJAM_Hook::load($hook, $cb, ...$args);
}

function wpjam_init($cb){
	wpjam_load('init', is_string($cb) && str_ends_with($cb, '::init') ? wpjam_callback($cb) : $cb);
}

function wpjam_include($hook, $file, ...$args){
	wpjam_load($hook, fn()=> array_map(fn($f)=> include $f, (array)$file), ...$args);
}

// Callback
function wpjam_callback($cb=null, ...$args){
	$object = WPJAM_Callback::get_instance();

	return is_null($cb) ? $object : $object($cb, ...$args);
}

function wpjam_call($cb, ...$args){
	return wpjam_callback('', $cb, ...$args);
}

function wpjam_call_method($class, ...$args){
	return wpjam_callback('method', $class, ...$args);
}

function wpjam_call_multiple($cb, $args){
	return array_map(fn($v)=> wpjam_call($cb, ...(array)$v), $args);
}

function wpjam_bind($cb, $args){
	return is_closure($cb) ? $cb->bindTo(...array_fill(0, 2, is_object($args) ? $args : wpjam_args($args))) : $cb;
}

function wpjam_try($cb, ...$args){
	return wpjam_if_error(wpjam_callback('try', wpjam_if_error($cb, 'throw'), ...$args), 'throw');
}

function wpjam_catch($cb, ...$args){
	if($cb instanceof WPJAM_Exception){
		return $cb->get_error();
	}elseif($cb instanceof Exception){
		return new WP_Error($cb->getCode(), $cb->getMessage());
	}elseif(is_wp_error($cb)){
		return $cb;
	}

	try{
		return wpjam_callback('catch', $cb, ...$args);
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_ob($cb, ...$args){
	ob_start() && wpjam_call($cb, ...$args);

	return ob_get_clean();
}

function wpjam_tap($value, $cb){
	$cb($value);

	return $value;
}

function wpjam_retry($times, $cb, ...$args){
	do{
		$times	-= 1;
		$result	= wpjam_catch($cb, ...$args);
	}while($result === false && $times > 0);

	return $result;
}

function wpjam_value($model, $name, ...$args){
	if(is_string($model)){
		return wpjam_call_method($model.'::get_'.$name, ...$args);
	}

	$args	= $model;
	$value	= wpjam_get($args, ['data', ...(array)$name]);

	return $value ?? wpjam_callback('value_callback', $args, $name);
}

function wpjam_call_for_blog($blog_id, $cb, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return $cb(...$args);
	}finally{
		$switched && restore_current_blog();
	}
}

function wpjam_call_with_suppress($cb, $filters){
	$suppressed	= array_filter($filters, fn($args)=> remove_filter(...$args));

	try{
		return $cb();
	}finally{
		wpjam_call_multiple('add_filter', $suppressed);
	}
}

function wpjam_get_reflection($cb, ...$args){
	return wpjam_callback('reflection', $cb, ...$args);
}

function wpjam_get_annotation($class, $key=''){
	return wpjam_callback('annotation', $class, $key);
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
		}elseif(in_array($args[0], [null, false, [], ''], true)){
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

function wpjam_trap($cb, ...$args){
	$if	= array_pop($args);

	return wpjam_if_error(wpjam_catch($cb, ...$args), $if);
}

function wpjam_db_transaction($cb, ...$args){
	$GLOBALS['wpdb']->query("START TRANSACTION;");

	try{
		$result	= $cb(...$args);
		$error	= $GLOBALS['wpdb']->last_error;
		$error && wpjam_throw('error', $error);

		$GLOBALS['wpdb']->query("COMMIT;");

		return $result;
	}catch(Exception $e){
		$GLOBALS['wpdb']->query("ROLLBACK;");

		return false;
	}
}

// debug
function wpjam_doing_debug(){
	$v	= $_GET['debug'] ?? null;

	return $v ? sanitize_key($v) : !is_null($v);
}

function wpjam_print_r($value){
	if(current_user_can(is_multisite() ? 'manage_site' : 'manage_options')){
		echo '<pre>';
		print_r($value);
		echo '</pre>'."\n";
	}
}

function wpjam_var_dump($value){
	if(current_user_can(is_multisite() ? 'manage_site' : 'manage_options')){
		echo '<pre>';
		var_dump($value);
		echo '</pre>'."\n";
	}
}

// Error
function wpjam_throw($code, $msg='', $data=[]){
	// trigger_error('wpjam_throw: '.(is_wp_error($code) ? $code->get_error_code() : $code));

	throw new WPJAM_Exception(is_wp_error($code) ? $code : new WP_Error($code, $msg, $data));
}

function wpjam_error($code='', ...$args){
	if(is_wp_error($code)){
		$err	= $code;
		$code	= $err->get_error_code();
		$msg	= $err->get_error_message();
		$data	= ['errcode'=>$code, 'errmsg'=>$msg]+array_filter(is_array($data = $err->get_error_data()) ? $data : ['errdata'=>$data]);
	}elseif(is_array($code)){
		$data	= $code;
		$code	= $data['errcode'] ??= 0;
		$msg	= $data['errmsg'] ?? [];
	}

	if(isset($data)){
		return $code && (!$msg || is_array($msg)) ? array_merge($data, wpjam_error($code, $msg)) : $data;
	}

	return wpjam('error', $code, ...$args);
}

function wpjam_die_handler($msg, $title='', $args=[]){
	if(is_wp_error($msg)){
		$item	= $msg;
	}else{
		$code	= $args['code'] ?? '';
		$data	= $code && $title ? ['modal'=>['title'=>$title, 'content'=>$msg]] : [];
		$item	= !$code && !$title && is_string($msg) ? wpjam_error($msg) : [];
		$item	= $item ?: ['errcode'=>(($code ?: $title) ?: 'error'), 'errmsg'=>$msg]+$data;
	}

	wpjam_send_json($item);
}

// Route
function wpjam_route($module, $args, $query_var=false){
	return wpjam('route', $module, $args, $query_var);
}

function wpjam_add_rewrite_rule(...$args){
	if($args && is_array($args[0])){
		$args	= $args[0];

		if($args && is_array($args[0])){
			return wpjam_call_multiple('wpjam_add_rewrite_rule', $args);
		}
	}

	return count($args) > 2 && $args[0] ? add_rewrite_rule($GLOBALS['wp_rewrite']->root.array_shift($args), ...$args) : null;
}

// txt
function wpjam_txt($name, ...$args){
	$object	= wpjam_get_option('wpjam_verify_txts', 'object');

	if($args && is_array($args[0])){
		return $object->update_setting($name, ...$args);
	}

	if(!str_ends_with($name, '.txt')){
		$data	= $object->get_setting($name) ?: [];
		$key	= $args[0] ?? '';

		return $key == 'fields' ? [
			'name'	=> ['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$data['name'] ?? '',	'class'=>'all-options'],
			'value'	=> ['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$data['value'] ?? '']
		] : ($key ? ($data[$key] ?? '') : $data);
	}

	if($data = array_find($object->get_option(), fn($v)=> $v['name'] == $name)){
		header('Content-Type: text/plain');
		echo $data['value']; exit;
	}
}

// Lifecycle
function wpjam_activation(...$args){
	$args	= $args ? array_reverse(array_slice($args+['', 'wp_loaded'], 0, 2)) : [];
	$result = [wpjam_get_handler(['items_type'=>'transient', 'transient'=>'wpjam-actives']), ($args ? 'add' : 'empty')](...$args);

	return $args ? $result : wpjam_call_multiple('add_action', $result);
}

function wpjam_updater($type, $hostname, $url){
	if(in_array($type, ['plugin', 'theme']) && $url){
		return add_filter('update_'.$type.'s_'.$hostname, fn($update, $data, $file, $locales)=> wpjam('updater', $type, $url, $file, $data) ?: $update, 10, 4);
	}
}

// Capability
function wpjam_map_meta_cap($cap, $map){
	if($cap && $map && (is_callable($map) || wp_is_numeric_array($map))){
		wpjam_map(wp_parse_list($cap), fn($c)=> $c && wpjam('cap', $c, $map));
	}
}

function wpjam_can($cap, ...$args){
	return ($cap = maybe_closure($cap, ...$args)) ? current_user_can($cap, ...$args) : true;
}

// Extend
function wpjam_load_extends($dir, $args=[]){
	is_dir($dir) && (new WPJAM_Extend($dir, $args))->load();
}

function wpjam_get_file_data($file, $type='data'){
	return WPJAM_Extend::parse_file($file, $type);
}

// Admin
function wpjam_add_menu_page(...$args){
	if(!$args[0] || wp_is_numeric_array($args[0])){
		return $args[0] ? array_walk($args[0], 'wpjam_add_menu_page') : null;
	}

	if(is_array($args[0])){
		$args	= $args[0];
	}else{
		$key	= empty($args[1]['plugin_page']) ? 'menu_slug' : 'tab_slug';
		$args	= wpjam_set($args[1], $key, $args[0]);

		if(!is_admin() && ($args['function'] ?? '') == 'option' && (!empty($args['sections']) || !empty($args['fields']))){
			wpjam_register_option(($args['option_name'] ?? $args[$key]), $args);
		}
	}

	if($type = array_find(['tab_slug'=>'tabs', 'menu_slug'=>'pages'], fn($v, $k)=> !empty($args[$k]) && !is_numeric($args[$k]))){
		if($cap = $args['capability'] ?? ''){
			wpjam_map_meta_cap($cap, wpjam_pull($args, 'map_meta_cap'));
		}

		if($model = $args['model'] ?? ''){
			wpjam_hooks($model.'::add_hooks');
			wpjam_init($model.'::init');

			$cap && wpjam_map_meta_cap($cap, wpjam_callback($model.'::map_meta_cap'));
		}

		if(is_admin()){
			if($type == 'pages'){
				$parent	= wpjam_pull($args, 'parent');
				$key	= $type.($parent ? '['.$parent.'][subs]' : '').'['.wpjam_pull($args, 'menu_slug').']';
				$args	= $parent ? $args : array_merge(wpjam_admin($key.'[]'), $args, ['subs'=>array_merge(wpjam_admin($key.'[subs][]'), $args['subs'] ?? [])]);
			}else{
				$key	= $type.'[]';
			}

			wpjam_admin($key, $args);
		}
	}
}

if(is_admin()){
	if(!function_exists('get_screen_option')){
		function get_screen_option($option, $key=false){
			if(did_action('current_screen')){
				$screen	= get_current_screen();
				$value	= in_array($option, ['post_type', 'taxonomy']) ? $screen->$option : $screen->get_option($option);

				return $key ? ($value ? wpjam_get($value, $key) : null) : $value;
			}
		}
	}

	function wpjam_admin($key='', ...$args){
		$object	= WPJAM_Admin::get_instance();

		return is_array($key) ? wpjam_map($key, 'wpjam_admin', 'kv') : ($key ? $object($key, ...$args) : $object);
	}

	function wpjam_chart($type='', ...$args){
		if(in_array($type, ['line', 'bar', 'donut'], true)){
			return ['WPJAM_Chart', $type](...$args);
		}

		$object	= wpjam_admin('chart', ...(is_array($type) ? [WPJAM_Chart::create($type)] : []));

		return $object && $type && !is_array($type) ? $object->$type(...$args) : $object;
	}

	function wpjam_add_admin_load($args){
		wp_is_numeric_array($args) ? array_walk($args, 'wpjam_add_admin_load') : $args && wpjam_admin('load', wpjam_pull($args, 'type'), $args);
	}

	function wpjam_register_page_action($name, $args){
		return wpjam_admin('page_actions['.$name.']', new WPJAM_Page_Action(['name'=>$name]+$args));
	}

	function wpjam_get_page_action($name){
		return $name ? wpjam_admin('page_actions['.$name.']') : null;
	}

	function wpjam_get_page_button($name, $args=[]){
		return ($object = wpjam_get_page_action($name)) ? $object->get_button($args) : '';
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

	function wpjam_dashboard($action, ...$args){
		return wpjam_admin('dashboard', $action, ...$args);
	}
}

wpjam();

load_textdomain('wpjam', dirname(__DIR__).'/template/wpjam-'.get_locale().'.l10n.php');

wpjam_route('json', 'WPJAM_JSON');
wpjam_route('txt',	[
	'callback'		=> 'wpjam_txt',
	'rewrite_rule'	=> fn()=> wpjam_hook('root_rewrite_rules', '+', fn()=> $GLOBALS['wp_rewrite']->root ? [] : ['([^/]+\.txt)?$'=>'index.php?module=txt&action=$matches[1]'])
]);

wpjam_error('bad_authentication', '无权限');
wpjam_error('access_denied', '操作受限');
wpjam_error('undefined_method', fn($args)=> '「%s」'.(count($args) >= 2 ? '%s' : '').'未定义');

wpjam_pattern('key', '^[a-zA-Z][a-zA-Z0-9_\-]*$', '请输入英文字母、数字和 _ -，并以字母开头！');
wpjam_pattern('slug', '[a-z0-9_\\-]+', '请输入小写英文字母、数字和 _ -！');

wpjam_load_extends(dirname(__DIR__).'/components');



