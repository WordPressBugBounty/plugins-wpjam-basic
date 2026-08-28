<?php
function wpjam($field='', ...$args){
	return (WPJAM_API::get_instance())($field, ...$args);
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

// $name, $data
// $data
// $name, $cb
// $cb
function wpjam_config(...$args){
	if($args && (count($args) > 1 || is_array($args[0]) || is_callable($args[0]))){
		$group	= (count($args) >= 3 ? array_shift($args) : '') ?: 'default';
		$args	= wpjam_filter($args, fn($v)=> isset($v));

		return $args ? wpjam('config', $group.'['.(count($args) > 1 ? array_shift($args) : '').']', ...$args) : null;
	}

	return wpjam_reduce(wpjam('config', ($args[0] ?? '') ?: 'default') ?: [], function($c, $v, $k){
		$v	= maybe_callback($v);
		$v	= is_numeric($k) ? (is_array($v) ? $v : []) : [$k=>$v];

		return array_merge($c, $v);
	}, []);
}

// Parameter
function wpjam_params($type, $fields=[]){
	$object	= WPJAM_Param::get_instance();

	return $object($type, wp_parse_args($fields));
}

function wpjam_param($name, $args=[]){
	if(is_array($name)){
		return $name ? wpjam_map(wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name, 'wpjam_param', 'kv') : [];
	}

	$object	= WPJAM_Param::get_instance();
	$type	= is_array($args) ? wpjam_pull($args, 'method') : $args;

	return $object($type, $name, is_array($args) ? $args : []);
}

// Request
function wpjam_remote_request($url, $args=[], $err=[]){
	return WPJAM_Http::request($url, $args+['field'=>'body'], $err);
}

function wpjam_translate($text, ...$args){
	if(is_array($text)){
		[$trans, $text]	= $text;

		return (array_pop($args) !== 'default' && $trans === $text) ? wpjam_translate($text, ...$args) : $trans;
	}

	$trans	= wpjam_call('translate'.($args && $args[0] ? '_with_gettext_context' : ''), $text, ...$args);

	if($trans === $text && substr_count($text, ' ') <= 1 && ($low = strtolower($text)) !== $text){
		if(($result = wpjam_translate($low, ...$args)) !== $low){
			$trans	= $result;
		}
	}

	return $trans;
}

// AJAX
function wpjam_ajax($name, $args=[]){
	if(empty($args['callback'])){
		$action	= wpjam_call_method(WPJAM_AJAX::get($name), 'nonce_action', $args);

		return wpjam_attr(['action'=>$name, 'data'=>$args]+($action ? ['nonce'=>wp_create_nonce($action)] : []), 'data');
	}elseif(empty($args['admin']) || wp_doing_ajax()){
		return WPJAM_AJAX::register($name, $args);
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

// Add 
function wpjam_add($key, $args){
	if($args = is_string($args) ? wpjam_call_method($args.'::get_'.$key) : $args){
		return wpjam_call('wpjam_add_'.$key, $args);
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
	return is_array($name) ? new WPJAM_Hook($name) : ($name === '-' ? WPJAM_Hook::remove(...$args) : WPJAM_Hook::add($name, ...$args));
}

function wpjam_once($name, ...$args){
	return wpjam_hook('once', ...wpjam_add_at($args, $name == 'maybe' ? 1 : 0, $name));
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
	if($cb && is_callable($cb)){
		if($hook = array_filter((array)$hook, fn($h)=> !did_action($h))){
			add_action(array_shift($hook), $hook ? fn()=> wpjam_load($hook, $cb, ...$args) : $cb, ...$args);
		}else{
			$cb();
		}
	}
}

function wpjam_init($cb){
	wpjam_load('init', is_string($cb) && str_ends_with($cb, '::init') ? wpjam_callback($cb) : $cb);
}

function wpjam_include($hook, $file, ...$args){
	wpjam_load($hook, fn()=> array_map(fn($f)=> include $f, (array)$file), ...$args);
}

function wpjam_updater($type, $hostname, $url){
	wpjam_hook('update_'.$type.'s_'.$hostname, ['type'=>$type, 'url'=>$url, 'bind'=>true, 'function'=>function($check, $data, $file){
		$type	= $this->type;
		$result	= $this->result ??= wpjam_remote_request($this->url);
		$result	= is_array($result) ? ($result['template']['table'] ?? $result[$type.'s']) : [];

		if(isset($result['fields']) && isset($result['content'])){
			$fields	= array_column($result['fields'], 'index', 'title');
			$item	= array_find($result['content'], fn($item)=> $item['i'.$fields[$type == 'plugin' ? '插件' : '主题']] == $file);
			$item	= $item ? array_map(fn($i)=> $item['i'.$i] ?? '', $fields) : [];
			$item	= $item ? [$type=>$file, 'icons'=>[], 'banners'=>[], 'banners_rtl'=>[]]+array_map(fn($v)=> $item[$v], ['url'=>'更新地址', 'package'=>'下载地址', 'new_version'=>'版本', 'requires_php'=>'PHP最低版本', 'requires'=>'最低要求版本', 'tested'=>'最新测试版本']) : [];
		}else{
			$item	= array_find($result ?: [], fn($item)=> $item[$type] == $file);
		}

		return $item ? $item+['slug'=>'']+($data ? ['id'=>$data['UpdateURI'], 'version'=>$data['Version'], 'requires_plugins'=>(array)($data['RequiresPlugins'] ?: [])] : []) : $check;
	}], 10, 4);
}

function wpjam_assets($type, $handle, $args=[]){
	if(!has_filter($hook = 'current_theme_supports-'.$type)){
		add_filter($hook, fn($check, $args, $value)=> !array_diff($args, is_array($value[0]) ? $value[0] : $value), 10, 3);
	}

	$args	= is_array($args) ? $args : ['src'=>$args];
	$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
	$parts	= isset($args['for']) ? array_intersect($parts, wp_parse_list($args['for'] ?: 'wp')) : $parts;
	$object	= wpjam_hook($args+['type'=>$type, 'handle'=>$handle, 'bind'=>true, 'callback'=>function(){
		if(($src = maybe_closure($this->src, $this->handle)) || !$this->data){
			$src	= ($src && $this->cdn ? wpjam_get_static_cdn() : '').$src;
			$src	= is_login() || is_admin() || !current_theme_supports($this->type, $this->handle) ? $src : '';
			$args	= $this->type == 'script' ? $this->pick(['in_footer', 'strategy']) : ($this->media ?? 'all');
		
			wpjam_call('wp_'.($this->method ?: 'enqueue').'_'.$this->type, $this->handle, $src, $this->deps, $this->ver, $args);
		}

		$this->data && wpjam_call('wp_add_inline_'.$this->type, $this->handle, $this->data, $this->position ?: 'after');
	}]);

	array_walk($parts, fn($p)=> wpjam_load($p.'_enqueue_scripts', $object, ($object->priority ?? 10)));
}

function wpjam_script(...$args){
	$args && $args[0] && wpjam_call(count($args) > 1 ? 'wpjam_assets' : 'wpjam_admin', 'script', ...$args);
}

function wpjam_style(...$args){
	$args && $args[0] && wpjam_call((is_array($args[0]) || (str_contains($args[0], '{') && str_contains($args[0], '}'))) ? 'wpjam_admin' : 'wpjam_assets', 'style', ...$args);
}

// Callback
function wpjam_callback($cb=null, ...$args){
	return (WPJAM_Callback::get_instance())($cb, ...$args);
}

function wpjam_call($cb, ...$args){
	return wpjam_callback('', $cb, ...$args);
}

function wpjam_call_method($class, ...$args){
	return $class ? wpjam_callback('method', $class, ...$args) : null;
}

function wpjam_call_multiple($cb, $args){
	return array_map(fn($v)=> wpjam_call($cb, ...(array)$v), $args);
}

function wpjam_try($cb, ...$args){
	return wpjam_if_error(wpjam_callback('try', wpjam_if_error($cb, 'throw'), ...$args), 'throw');
}

function wpjam_catch($cb, ...$args){
	try{
		if($cb instanceof Exception){
			return $cb instanceof WPJAM_Exception ? $cb->get_error() : new WP_Error($cb->getCode(), $cb->getMessage());
		}

		return is_wp_error($cb) ? $cb : wpjam_callback('catch', $cb, ...$args);
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_ob($cb, ...$args){
	ob_start() && wpjam_call($cb, ...$args);

	return ob_get_clean();
}

function wpjam_tap($value, $cb, ...$args){
	if(is_wp_error($value)){
		$args && wpjam_if_error($value, ...$args);
	}else{
		$cb && $cb($value);
	}

	return $value;
}

function wpjam_retry($times, $cb, ...$args){
	do{
		$times	-= 1;
		$result	= wpjam_catch($cb, ...$args);
	}while($result === false && $times > 0);

	return $result;
}

function wpjam_value_callback($args, $name){
	return wpjam_get($args, ['data', ...(array)$name]) ?? wpjam_callback('value_callback', $args, $name);
}

function wpjam_call_for_blog($blog_id, $cb, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return $cb(...$args);
	}finally{
		$switched && restore_current_blog();
	}
}

function wpjam_call_with_suppress($hooks, $cb, ...$args){
	$hooks	= wpjam_hooks('-', $hooks);

	try{
		return $cb(...$args);
	}finally{
		wpjam_hooks($hooks);
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
		}elseif($cb = ['die'=>'wp_die', 'throw'=>'wpjam_throw', 'send'=>'wpjam_send_json'][$args[0]] ?? ''){
			$cb($value);
		}
	}

	return $value;
}

function wpjam_then($value, $cb, ...$args){
	return is_wp_error($value) ? wpjam_if_error($value, ...$args) : maybe_closure($cb, $value);
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
	throw new WPJAM_Exception(is_wp_error($code) ? $code : new WP_Error($code, $msg, $data));
}

function wpjam_error(...$args){
	if(!$args || !$args[0]){
		$method	= 'get';
	}else{
		$method	= (is_wp_error($args[0]) || is_array($args[0]) || WPJAM_Error::can(...$args)) ? 'parse' : 'update';
	}

	return ['WPJAM_Error', $method](...$args);
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
function wpjam_route($module, $args=[], $query_var=false){
	if(is_string($args) && class_exists($args)){
		if(is_admin()){
			wpjam_add('menu_page', $args);
			wpjam_add('admin_load', $args);
		}

		$rr		= $args;
		$args	= ['callback'=>$args.'::redirect'];
	}else{
		$rr		= $args['rewrite_rule'] ?? '';
		$args	= wpjam_is_assoc_array($args) ? array_filter($args) : ['callback'=>$args];
	}

	if($rr){
		wpjam_init(fn()=> wpjam_add('rewrite_rule', maybe_closure($rr)));
	}

	$object	= wpjam_dispatch();

	if($query_var && ($action = wpjam_param($module, wp_doing_ajax() ? 'data' : 'get'))){
		$object->update_arg('query_vars['.$module.']', $action);
	}

	return $object->update_arg($module, $args+['query_var'=>$query_var]);
}

function wpjam_dispatch(){
	static $object;

	if(!isset($object)){
		wpjam_hook('query_vars', '+', ['module', 'action'], 11);
	}

	return $object ??= wpjam_hook(wp_doing_ajax() ? 'admin_init' : 'parse_request', 'bind', function($wp=null){
		$query_vars	= $this->query_vars ?: [];

		if($wp && ($module = $wp->query_vars['module'] ?? '')){
			remove_action('template_redirect', 'redirect_canonical');

			$query_vars[$module]	= $wp->query_vars['action'] ?? '';
		}

		foreach($query_vars as $module => $action){
			$args	= $this->$module ?: [];

			if($args['query_var'] ?? false){
				$GLOBALS['wp']->set_query_var($module, $action);
			}

			if($args['callback'] ?? ''){
				wpjam_call($args['callback'], $action, $module);
			}

			if(!is_admin()
				&& ($file = ($args['file'] ?? '') ?: apply_filters('wpjam_template', STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php', $module, $action))
				&& is_file($file)
			){
				wpjam_hook('template_include', $file);
			}
		}
	}, 1);
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

function wpjam_loaded($action='', ...$args){
	if($action){
		return wpjam_load('wp_loaded', fn()=> do_action($action, ...$args));
	}

	if(!did_action('plugins_loaded')){
		return add_action('plugins_loaded', 'wpjam_loaded', 0);
	}

	wpjam_activation();

	wpjam_load_extends(get_template_directory().'/extends');

	wpjam_load_extends(dirname(__DIR__).'/extends', [
		'option'	=> 'wpjam-extends',
		'sitewide'	=> true,
		'title'		=> '扩展管理',
		'menu_page'	=> ['parent'=>'wpjam-basic', 'order'=>3, 'function'=>'tab', 'tabs'=>['extends'=>['order'=>20, 'title'=>'扩展管理', 'function'=>'option']]]
	]);

	add_filter('request',	'wpjam_parse_query_vars', 11);

	add_action('loop_start',	'wpjam_loop', 1);
	add_action('loop_end',		'wpjam_loop', 999);

	wpjam_hook('pre_do_shortcode_tag',	'wpjam_shortcode', 1, 2);
	wpjam_hook('do_shortcode_tag',		'wpjam_shortcode', 999, 2);

	wpjam_hook('register_post_type_args',	['WPJAM_Post_Type', 'filter_builtin_args'], 999, 2);
	wpjam_hook('register_taxonomy_args',	['WPJAM_Taxonomy', 'filter_builtin_args'], 999, 3);

	wpjam_hooks('gettext, gettext_with_context', fn($trans, $text, ...$args)=> wpjam_translate([$trans, $text], ...$args), 10, 4);
}

function wpjam_loop(...$args){
	static $object;
	$object ??= wpjam_hook([
		'bind'		=> true,
		'queries'	=> [],
		'callback'	=> fn($query)=> $this->update_arg('queries', $this->hook === 'loop_start' ? [...$this->queries, $query] : array_slice($this->queries, 0, -1))
	]);

	return $args ? $object(...$args) : $object->queries;
}

function wpjam_is(...$args){
	$query	= ($args && is_object($args[0])) ? array_shift($args) : array_last(wpjam_loop());

	return $query && ($query instanceof WP_Query) ? wpjam_query('is', $query, ...$args) : false;
}

function wpjam_shortcode(...$args){
	static $object;
	$object ??= wpjam_hook([
		'tap'		=> true,
		'bind'		=> true,
		'tags'		=> [],
		'callback'	=> fn($tag)=> $this->update_arg('tags', $this->hook === 'pre_do_shortcode_tag' ? [...$this->tags, $tag] : array_slice($this->tags, 0, -1))
	]);

	return $args ? $object(...$args) : $object->tags;
}

if(!function_exists('doing_shortcode')){
	function doing_shortcode($tag=null){
		$tags = wpjam_shortcode();

		return null === $tag ? !empty($tags) : in_array($tag, $tags, true);
	}
}

// Capability
function wpjam_map_meta_cap($cap, $map){
	static $object;
	$object	??= wpjam_hook('map_meta_cap', 'bind', function($caps, $cap, $user_id, $args){
		foreach((!in_array('do_not_allow', $caps) && $user_id) ? ($this->$cap ?: []) : [] as $v){
			if(($v = maybe_callback($v, $user_id, $args, $cap)) || is_array($v)){
				$caps	= (array)$v;
			}
		}

		return $caps;
	}, 10, 4);

	if($cap && $map && (is_callable($map) || wp_is_numeric_array($map))){
		wpjam_map(wp_parse_list($cap), fn($c)=> $c && $object->update_arg($c.'[]', $map));
	}
}

function wpjam_can($cap, ...$args){
	return ($cap = maybe_closure($cap, ...$args)) ? current_user_can($cap, ...$args) : true;
}

// Extend
function wpjam_load_extends($dir, $args=[]){
	is_dir($dir) && (new WPJAM_Extend($dir, $args))->load();
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
		return is_array($key) ? wpjam_map($key, 'wpjam_admin', 'kv') : (WPJAM_Admin::get_instance())($key, ...$args);
	}

	function wpjam_chart($type='', ...$args){
		if(wpjam_is_assoc_array($type) || $type === []){
			return wpjam_admin('chart', WPJAM_Chart::create($type));
		}

		if(in_array($type, ['line', 'bar', 'donut'], true)){
			return ['WPJAM_Chart', $type](...$args);
		}

		if(($object = wpjam_admin('chart')) && $type){
			if(is_string($type)){
				$type	= ['get_parameter'=>'get_value', 'fields'=>'get_fields'][$type] ?? $type;
			}

			return in_array($type, ['validate', 'render', 'get_value', 'get_fields'], true)
			? $object->$type(...$args)
			: $object->get_value($type, ...$args);
		}

		return $object;
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
		return wpjam_call_method(wpjam_get_page_action($name), 'get_button', $args);
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

wpjam_loaded();

is_admin() && wpjam_admin();

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

wpjam_style('wpjam-static',	['src'=>'', 'method'=>'register', 'priority'=>1]);
wpjam_style('remixicon',	['src'=>'/remixicon/4.2.0/remixicon.min.css', 'method'=>(is_admin() ? 'enqueue' : 'register'), 'cdn'=>true, 'priority'=>1]);

wpjam_load_extends(dirname(__DIR__).'/components');


