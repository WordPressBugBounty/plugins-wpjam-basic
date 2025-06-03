<?php
class WPJAM_API{
	private $data	= [];

	public function add($field, $key, ...$args){
		[$key, $item]	= $args ? [$key, $args[0]] : [null, $key];

		if($field == 'route'){
			$item	= wpjam_is_assoc_array($item) ? $item : ['callback'=>$item];

			if(!empty($item['model'])){
				foreach(['callback'=>'redirect', 'rewrite_rule'=>'get_rewrite_rule'] as $k => $v){
					if(empty($item[$k]) && method_exists($item['model'], $v)){
						$item[$k]	= [$item['model'], $v];
					}
				}
			}

			foreach(['rewrite_rule', 'menu_page', 'admin_load'] as $k){
				if(($k == 'rewrite_rule' || is_admin()) && wpjam_get($item, $k)){
					wpjam_call('wpjam_add_'.$k,$item[$k]);
				}
			}

			if(!empty($item['query_var'])){
				[$method, $hook]	= wp_doing_ajax() ? ['DATA', 'admin_init'] : ['GET', 'parse_request'];

				if($action = wpjam_get_parameter($key, compact('method'))){
					add_action($hook, fn()=> $this->dispatch($key, $action), 0);
				}
			}
		}elseif($field == 'map_meta_cap'){
			if(!has_filter('map_meta_cap', [$this, 'map_meta_cap'])){
				add_filter('map_meta_cap', [$this, 'map_meta_cap'], 10, 4);
			}

			$field	.= ':'.$key;
			$key 	= null;
		}

		if(isset($key)){
			if($this->get($field, $key) !== null){
				return new WP_Error('invalid_key', '「'.$key.'」已存在，无法添加');
			}

			$this->set($field, $key, $item);
		}else{
			$this->data[$field][]	= $item;
		}

		return $item;
	}

	public function set($field, $key, $item){
		$this->data[$field]	= wpjam_set($this->get($field), $key, $item);

		return $item;
	}

	public function delete($field, $key){
		return wpjam_except($this->get($field), $key);
	}

	public function update($field, $data){
		return $this->data[$field] = $data;
	}

	public function get($field, ...$args){
		$items	= $this->data[$field] ?? [];

		return $args ? wpjam_get($items, $args[0]) : $items;
	}

	public function activation($method, ...$args){
		return wpjam_get_handler(['items_type'=>'transient', 'transient'=>'wpjam-actives'])->$method(...$args);
	}

	public function map_meta_cap($caps, $cap, $user_id, $args){
		if(!in_array('do_not_allow', $caps) && $user_id){
			foreach($this->get('map_meta_cap:'.$cap) as $item){
				$item	= maybe_callback($item, $user_id, $args, $cap);
				$caps	= (is_array($item) || $item) ? (array)$item : $caps;
			}
		}

		return $caps;
	}

	public function dispatch($module, $action){
		$item	= $this->get('route', $module);

		if($item){
			if(!empty($item['query_var'])){
				$GLOBALS['wp']->set_query_var($module, $action);
			}

			if(!empty($item['callback'])){
				wpjam_call($item['callback'], $action, $module);
			}
		}

		if(!is_admin()){
			if($item && !empty($item['file'])){
				$file	= $item['file'];
			}else{
				$file	= STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php';
				$file	= apply_filters('wpjam_template', $file, $module, $action);
			}

			if(is_file($file)){
				add_filter('template_include',	fn()=> $file);
			}
		}
	}

	public function request($vars){
		if(!empty($vars['module'])){
			remove_action('template_redirect',	'redirect_canonical');

			add_action('parse_request',	fn()=> $this->dispatch($vars['module'], $vars['action'] ?? ''), 1);
		}

		return $vars;
	}

	public static function on_plugins_loaded(){
		$wpjam	= self::get_instance();

		wpjam_map($wpjam->activation('empty'), fn($active)=> $active && count($active) >= 2 ? add_action(...$active) : null);

		add_filter('query_vars',	fn($vars)=> array_merge($vars, ['module', 'action', 'term_id']));
		add_filter('request',		fn($vars)=> $wpjam->request(wpjam_parse_query_vars($vars)), 11);
	}

	public static function __callStatic($method, $args){
		if(str_starts_with($method, 'lazyload_')){
			remove_filter(current_filter(), [self::class, $method]);

			wpjam_load_pending(wpjam_remove_prefix($method, 'lazyload_'));

			return array_shift($args);
		}

		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_JSON extends WPJAM_Register{
	public function response(){
		$attr		= ['page_title', 'share_title', 'share_image'];
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$response	= wpjam_if_error(apply_filters('wpjam_pre_json', [], $this->args, $this->name), 'throw')+[
			'errcode'		=> 0,
			'current_user'	=> wpjam_try('wpjam_get_current_user', $this->pull('auth'))
		]+($method != 'POST' ? wpjam_pick($this, $attr) : []);

		if($this->fields){
			$fields	= wpjam_try(fn()=> maybe_callback($this->fields, $this->name)) ?: [];
			$data	= wpjam_fields($fields)->get_parameter($method);
		}

		if($this->modules){
			$modules	= maybe_callback($this->modules, $this->name);
			$modules	= wp_is_numeric_array($modules) ? $modules : [$modules];
			$results	= array_map(fn($module)=> self::parse_module($module, true), $modules);
		}elseif($this->callback){
			$callback	= $this->pull('callback');
			$results[]	= wpjam_if_error(wpjam_call($callback, ($this->fields ? $data : $this->args), $this->name), 'throw');
		}elseif($this->template){
			$results[]	= is_file($this->template) ? include $this->template : '';
		}else{
			$results[]	= $this->args;
		}

		$response	= array_reduce($results, fn($c, $v)=> array_merge($c, is_array($v) ? array_diff_key($v, wpjam_pick($c, $attr)) : []), $response);
		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			foreach($attr as $k){
				if(empty($response[$k])){
					if($k != 'share_image'){
						$response[$k]	= html_entity_decode(wp_get_document_title());
					}
				}else{
					if($k == 'share_image'){
						$response[$k]	= wpjam_get_thumbnail($response[$k], '500x400');
					}
				}
			}
		}

		return $response;
	}

	public static function parse_module($module, $throw=false){
		$args	= wpjam_get($module, 'args', []);
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= wpjam_get($module, 'parser') ?: self::module_parser(wpjam_get($module, 'type'));

		return $parser ? ($throw ? wpjam_try($parser, $args) : wpjam_catch($parser, $args)) : $args;
	}

	public static function module_parser($key, ...$args){
		return wpjam_var('json_module_parser:'.$key, ...$args);
	}

	public static function redirect($action){
		header('X-Content-Type-Options: nosniff');

		rest_send_cors_headers(false); 

		if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
			status_header(403);
			exit;
		}

		$part	= array_find(['_jsonp', '_json'], fn($v)=> call_user_func('wp_is'.$v.'_request')) ?: '';

		if(!wpjam_doing_debug()){
			@header('Content-Type: application/'.($part == '_jsonp' ? 'javascript' : 'json').'; charset='.get_option('blog_charset'));
		}

		wpjam_set_die_handler($part);

		if(!str_starts_with($action, 'mag.')){
			return;
		}

		$name	= substr($action, 4);
		$name	= substr($name, str_starts_with($name, '.mag') ? 4 : 0);	// 兼容 
		$name	= str_replace('/', '.', $name);
		$name	= apply_filters('wpjam_json_name', $name);
		$result	= wpjam_var('json', $name);
		$user	= wpjam_get_current_user();

		if($user && !empty($user['user_id'])){
			wp_set_current_user($user['user_id']);
		}

		wpjam_map([
			'post_type'	=> ['WPJAM_Posts',		'parse_json_module'],
			'taxonomy'	=> ['WPJAM_Terms',		'parse_json_module'],
			'setting'	=> ['WPJAM_Setting',	'parse_json_module'],
			'media'		=> ['WPJAM_Posts',		'parse_media_json_module'],
			'data_type'	=> ['WPJAM_Data_Type',	'parse_json_module'],
			'config'	=> 'wpjam_get_config'
		], fn($v, $k)=> self::module_parser($k, $v));

		do_action('wpjam_api', $name);

		$object	= self::get($name);
		$result	= $object ? $object->catch('response') : new WP_Error('invalid_api', '接口未定义');

		self::send($result);
	}

	public static function send($data=[], $code=null){
		if($data === true || $data === []){
			$data	= ['errcode'=>0];
		}elseif($data === false || is_null($data)){
			$data	= ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
		}else{
			$data	= WPJAM_Error::parse($data);
		}

		$result	= self::encode($data);

		if(!headers_sent() && !wpjam_doing_debug()){
			if(!is_null($code)){
				status_header($code);
			}

			$jsonp	= wp_is_jsonp_request();
			$result	= $jsonp ? '/**/' . $_GET['_jsonp'] . '(' . $result . ')' : $result;

			@header('Content-Type: application/'.($jsonp ? 'javascript' : 'json').'; charset='.get_option('blog_charset'));
		}

		echo $result;

		exit;
	}

	public static function encode($data){
		return wp_json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	public static function decode($json, $assoc=true){
		$json	= wpjam_strip_control_chars($json);

		if(!$json){
			return new WP_Error('json_decode_error', 'JSON 内容不能为空！');
		}

		$result	= json_decode($json, $assoc);

		if(is_null($result)){
			$result	= json_decode(stripslashes($json), $assoc);

			if(is_null($result)){
				if(wpjam_doing_debug()){
					print_r(json_last_error());
					print_r(json_last_error_msg());
				}
				trigger_error('json_decode_error '. json_last_error_msg()."\n".var_export($json,true));
				return new WP_Error('json_decode_error', json_last_error_msg());
			}
		}

		return $result;
	}

	public static function get_current($output='name'){
		$name	= wpjam_var('json');

		return $output == 'object' ? self::get($name) : $name;
	}

	public static function get_rewrite_rule(){
		return [
			['api/([^/]+)/(.*?)\.json?$',	['module'=>'json', 'action'=>'mag.$matches[1].$matches[2]'], 'top'],
			['api/([^/]+)\.json?$', 		'index.php?module=json&action=$matches[1]', 'top'],
		];
	}

	public static function get_defaults(){
		return [
			'post.list'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.calendar'	=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'post.get'		=> ['modules'=>['WPJAM_Posts', 'json_modules_callback']],
			'media.upload'	=> ['modules'=>['type'=>'media', 'args'=>['media'=>'media']]],
			'site.config'	=> ['modules'=>['type'=>'config']],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['parse_post_list_module', 'parse_post_get_module'])){
			$args	= $args[0] ?? [];
			$action	= str_replace(['parse_post_', '_module'], '', $method);

			return self::parse_module(['type'=>'post_type', 'args'=>array_merge($args, ['action'=>$action])]);
		}
	}
}

/**
* @config orderby=order order=ASC
**/
#[config(orderby:'order', order:'ASC')]
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		return $key == 'path' ? (bool)$this->get_items() : parent::__get($key);
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_by_page_type')){
			$item	= $this->get_item(array_shift($args));
			$object	= wpjam_get_data_type_object(wpjam_pull($item, 'page_type'), $item);

			return $object ? [$object, substr($method, 0, -13)](...$args) : null;
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function verify(){
		return call_user_func($this->verify);
	}

	public function get_tabbar($page_key=''){
		if(!$page_key){
			return wpjam_array($this->get_items(), fn($k)=> ($v = $this->get_tabbar($k)) ? [$k, $v] : null);
		}

		if($tabbar	= $this->get_item($page_key.'.tabbar')){
			return ($tabbar === true ? [] : $tabbar)+['text'=>(string)$this->get_item($page_key.'.title')];
		}		
	}

	public function get_page($page_key=''){
		if(!$page_key){
			return wpjam_array($this->get_items(), fn($k)=> ($v = $this->get_page($k)) ? [$k, $v] : null);
		}

		return ($path = $this->get_item($page_key.'.path')) ? explode('?', $path)[0] : '';
	}

	public function get_fields($page_key){
		$item	= $this->get_item($page_key);

		if(!$item){
			return [];
		}

		$fields	= $item['fields'] ?? [];
		$fields	= $fields ? maybe_callback($fields, $item, $page_key) : $this->get_path_fields_by_page_type($page_key, $item);

		return $fields ?: [];
	}

	public function has_path($page_key, $strict=false){
		$item	= $this->get_item($page_key);

		if(!$item || ($strict && isset($item['path']) && $item['path'] === false)){
			return false;
		}

		return isset($item['path']) || isset($item['callback']);
	}

	public function get_path($page_key, $args=[]){
		if(is_array($page_key)){
			[$page_key, $args]	= [wpjam_pull($page_key, 'page_key'), $page_key];
		}

		$item	= $this->get_item($page_key);

		if(!$item){
			return;
		}

		$cb		= wpjam_pull($item, 'callback');
		$args	= is_array($args) ? array_filter($args, fn($v)=> !is_null($v))+$item : $args;

		if($cb){
			if(is_callable($cb)){
				return $cb(...[$args, ...(is_array($args) ? [] : [$item]), $page_key]) ?: '';
			}
		}else{
			$path	= $this->get_path_by_page_type($page_key, $args, $item);

			if($path){
				return $path;
			}
		}

		return isset($item['path']) ? (string)$item['path'] : null;
	}

	public function get_paths($page_key, $args=[]){
		$type	= $this->get_item($page_key.'.page_type');
		$args	= array_merge($args, wpjam_pick($this->get_item($page_key), [$type]));
		$items	= $this->query_items_by_page_type($page_key, $args);

		if($items){
			$paths	= array_map(fn($item)=> $this->get_path($page_key, $item['value']), $items);
			$paths	= array_filter($paths, fn($path)=> wpjam_if_error($path, null));
		}

		return $paths ?? [];
	}

	public function parse_path($args, $suffix=''){
		$page_key	= wpjam_pull($args, 'page_key'.$suffix);

		if($page_key == 'none'){
			return ($video = $args['video'] ?? '') ? ['type'=>'video', 'video'=>wpjam_get_qqv_id($video) ?: $video] : ['type'=>'none'];
		}elseif($this->get_item($page_key)){
			$args	= $suffix ? wpjam_map($this->get_fields($page_key), fn($v, $k)=> $args[$k.$suffix] ?? null) : $args;
			$path	= $this->get_path($page_key, $args);

			return (is_wp_error($path) || is_array($path)) ? $path : (isset($path) ? ['type'=>'', 'page_key'=>$page_key, 'path'=>$path] : null);
		}

		return [];
	}

	public function registered(){
		if($this->name == 'template'){
			wpjam_register_path('home',		'template',	['title'=>'首页',		'path'=>home_url(),	'group'=>'tabbar']);
			wpjam_register_path('category',	'template',	['title'=>'分类页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('post_tag',	'template',	['title'=>'标签页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('author',	'template',	['title'=>'作者页',		'path'=>'',	'page_type'=>'author']);
			wpjam_register_path('post',		'template',	['title'=>'文章详情页',	'path'=>'',	'page_type'=>'post_type']);
			wpjam_register_path('external', 'template',	['title'=>'外部链接',		'path'=>'',	'fields'=>['url'=>['type'=>'url', 'required'=>true, 'placeholder'=>'请输入链接地址。']],	'callback'=>fn($args)=> ['type'=>'external', 'url'=>$args['url']]]);
		}
	}

	public static function get_options($output=''){
		return wp_list_pluck(self::get_registereds(), 'title', $output);
	}

	public static function get_current($args=[], $output='object'){
		if($output == 'bit' && wp_is_numeric_array($args)){
			$bits	= wp_list_pluck(self::get_by(['path'=>true]), 'bit');
			$args	= array_values(wpjam_slice(array_flip($bits), $args));
		}

		$args	= $args ?: ['path'=>true];
		$object	= array_find(self::get_by($args), fn($object)=> $object && $object->verify());

		if($object){
			if($output == 'bit'){
				return $object->bit;
			}elseif($output == 'object'){
				return $object;
			}else{
				return $object->name;
			}
		}
	}

	protected static function get_defaults(){
		return [
			'weapp'		=> ['bit'=>1,	'order'=>4,		'title'=>'小程序',	'verify'=>'is_weapp'],
			'weixin'	=> ['bit'=>2,	'order'=>4,		'title'=>'微信网页',	'verify'=>'is_weixin'],
			'mobile'	=> ['bit'=>4,	'order'=>8,		'title'=>'移动网页',	'verify'=>'wp_is_mobile'],
			'template'	=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true']
		];
	}
}

class WPJAM_Platforms{
	private $platforms	= [];
	private $cache		= [];

	protected function __construct($platforms){
		$this->platforms	= $platforms;
	}

	protected function has_path($page_key, $operator='AND', $strict=false){
		$cb	= $operator == 'AND' ? 'array_all' : 'array_any';

		return $cb($this->platforms, fn($pf)=> $pf->has_path($page_key, $strict));
	}

	public function get_fields($args=[]){
		if(is_array($args)){
			$strict	= (bool)wpjam_pull($args, 'strict');
		}else{
			$strict	= (bool)$args;
			$args	= [];
		}

		$prepend	= wpjam_pull($args, 'prepend_name');
		$prepend	= $prepend ? ['prepend_name'=>$prepend] : [];
		$suffix		= wpjam_pull($args, 'suffix');
		$title		= wpjam_pull($args, 'title') ?: '页面';
		$key		= 'page_key'.$suffix;
		$backup		= (count($this->platforms) > 1 && !$strict) ? $key.'_backup' : '';
		$paths		= WPJAM_Path::get_by($args);
		$cache_key	= md5(serialize($prepend+['suffix'=>$suffix, 'strict'=>$strict, 'page_keys'=>array_keys($paths)]));
		$cache		= $this->cache[$cache_key] ?? [];

		if(!$cache){
			$groups	= array_merge(
				['tabbar'=>['title'=>'菜单栏/常用', 'options'=>($strict ? [] : ['none'=>'只展示不跳转'])]],
				WPJAM_Path::group(),
				['others'=>['title'=>'其他页面', 'options'=>[]]]
			);

			$cache	= [$key=>$groups]+($backup ? ['show_if'=>[], $backup=>$groups] : []);
			$parser	= fn($path, $suffix)=> [$path->name=> [
				'label'		=> $path->title,
				'fields'	=> wpjam_array($path->get_fields($this->platforms), fn($k, $v)=> [$k.$suffix, wpjam_except($v, 'title')+$prepend])
			]];

			foreach($paths as $path){
				if($this->has_path($path->name, 'OR', $strict)){
					$group	= $path->group ?: ($path->tabbar ? 'tabbar' : 'others');

					$cache[$key][$group]['options']	+= $parser($path, $suffix);

					if($backup){
						if($this->has_path($path->name, 'AND')){
							$cache[$backup][$group]['options']	+= $parser($path, $suffix.'_backup');
						}else{
							$cache['show_if'][]	= $path->name;
						}
					}
				}
			}

			$this->cache[$cache_key] = $cache;
		}

		return wpjam_array([$key, $backup], fn($i, $k)=> !$k ? null : [
			$k.'_set',
			['type'=>'fieldset', 'label'=>true, 'fields'=>[$k=>['options'=>array_filter($cache[$k], fn($item)=> $item['options'])]+$prepend]]+($k == $backup ? ['title'=>'备用'.$title, 'show_if'=>[$key, 'IN', $cache['show_if']]] : ['title'=>$title])
		]);
	}

	public function get_current($output='object'){
		if(count($this->platforms) == 1){
			return $output == 'object' ? reset($this->platforms) : reset($this->platforms)->name;
		}else{
			return WPJAM_Platform::get_current(array_keys($this->platforms), $output);
		}
	}

	public function parse_item($item, $suffix=''){
		$platform	= $this->get_current();
		$parsed		= wpjam_if_error($platform->parse_path($item, $suffix), null);

		if(!$parsed && count($this->platforms) > 1){
			$parsed	= $parsed ?: (count($this->platforms) > 1 ? wpjam_if_error($platform->parse_path($item, $suffix.'_backup'), null) : null);
		}

		return $parsed ?: ['type'=>'none'];
	}

	public function validate_item($item, $suffix='', $title=''){
		foreach($this->platforms as $platform){
			$result	= $platform->parse_path($item, $suffix);

			if(wpjam_if_error($result, null)){
				if(!$result && count($this->platforms) > 1 && !str_ends_with($suffix, '_backup')){
					return $this->validate_item($item, $suffix.'_backup', '备用'.$title);
				}

				return $result ?: new WP_Error('invalid_page_key', '无效的'.$title.'页面。');
			}
		}

		return $result;
	}

	public static function get_instance($platforms=null){
		$args		= is_null($platforms) ? ['path'=>true] : (array)$platforms;
		$objects	= array_filter(WPJAM_Platform::get_by($args));

		if($objects){
			return wpjam_get_instance('platforms', implode('-', array_keys($objects)), fn()=> new self($objects));
		}
	}
}

class WPJAM_Path extends WPJAM_Register{
	public function __get($key){
		if(in_array($key, ['platform', 'path_type'])){
			return array_keys($this->get_items());
		}

		return parent::__get($key);
	}

	public function get_fields($platforms){
		return array_reduce($platforms, fn($fields, $pf)=> array_merge($fields, $pf->get_fields($this->name)), []);
	}

	public function add_platform($pf, $args){
		$platform	= WPJAM_Platform::get($pf);

		if(!$platform){
			return;
		}

		$page_type	= wpjam_get($args, 'page_type');

		if($page_type && in_array($page_type, ['post_type', 'taxonomy']) && empty($args[$page_type])){
			$args[$page_type]	= $this->name;
		}

		if(isset($args['group']) && is_array($args['group'])){
			$group	= wpjam_pull($args, 'group');

			if(isset($group['key'], $group['title'])){
				self::group($group['key'], ['title'=>$group['title'], 'options'=>[]]);

				$args['group']	= $group['key'];
			}
		}

		$args	= array_merge($args, ['platform'=>$pf, 'path_type'=>$pf]);

		$this->update_args($args, false)->add_item($pf, $args);

		$platform->add_item($this->name, $args);
	}

	public static function group(...$args){
		return wpjam(($args ? 'add' : 'get'), 'path_group', ...$args);
	}

	public static function create($name, ...$args){
		$object	= (self::get($name)) ?: self::register($name, []);
		$args	= count($args) == 2 ? ['platform'=>$args[0]]+$args[1] : $args[0];
		$args	= wp_is_numeric_array($args) ? $args : [$args];

		foreach($args as $_args){
			foreach(wpjam_pick($_args, ['platform', 'path_type']) as $value){
				wpjam_map(wpjam_array($value), fn($pf)=> $object->add_platform($pf, $_args));
			}
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($pf){
			if($object = self::get($name)){
				$object->delete_item($pf);
			}

			if($platform = WPJAM_Platform::get($pf)){
				$platform->delete_item($name);
			}
		}else{
			self::unregister($name);

			wpjam_map(WPJAM_Platform::get_registereds(), fn($pf)=> $pf->delete_item($name));
		}
	}
}

class WPJAM_Data_Type extends WPJAM_Register{
	public function __call($method, $args){
		return $this->call_method($method, ...$args);
	}

	public function prepare_value($value, $field){
		if($field->parse_required && $value){
			return $this->parse_value($value, $field) ?? $value;
		}

		return $value;
	}

	public function validate_value($value, $field){
		if($value){
			return $this->validate_by_field($value, $field) ?? $value;
		}
	}

	public function query_items($args){
		$args	= array_filter($args ?: [], fn($v)=> !is_null($v));
		$args	+= ['number'=>10, 'data_type'=>true];

		if($this->query_items){
			return wpjam_catch($this->query_items, $args);
		}

		if($this->model){
			$args	= isset($args['model']) ? wpjam_except($args, ['data_type', 'model', 'label_field', 'id_field']) : $args;
			$result	= wpjam_catch([$this->model, 'query_items'], $args, 'items');

			if(is_wp_error($result)){
				return $result;
			}

			$items	= wp_is_numeric_array($result) ? $result : $result['items'];

			if(!isset($items)){
				return new WP_Error('undefined_method', ['query_items', '回调函数']);
			}

			return $this->label_field ? array_map(fn($item)=> [
				'label'	=> is_object($item) ? $item->{$this->label_field}	: $item[$this->label_field],
				'value'	=> is_object($item) ? $item->{$this->id_field}		: $item[$this->id_field]
			], $items) : $items;
		}
	}

	public function query_label($id, $field=null){
		if($this->query_label){
			if($id){
				return call_user_func($this->query_label, $id, $field);
			}
		}elseif($this->model && $this->label_field){
			$data	= $id ? $this->model::get($id) : null;

			if($data){
				return wpjam_get($data, $this->label_field);
			}
		}
	}

	public static function parse_json_module($args){
		$name	= wpjam_pull($args, 'data_type');
		$args	= wpjam_get($args, 'query_args', $args);
		$args	= $args ? wp_parse_args($args) : [];
		$object	= self::get_instance($name, $args);

		if(!$object){
			return new WP_Error('invalid_data_type');
		}

		$args	+= ['search'=>wpjam_get_parameter('s')];
		$items	= $object->query_items($args);

		return ['items'=>wpjam_if_error($items, [])];
	}

	public static function ajax_response($data){
		$name	= $data['data_type'];
		$args	= wpjam_pull($data, 'query_args');
		$object	= self::get_instance($name, $args);
		$items	= $object ? ($object->query_items($args) ?: []) : [];
		$items	= wpjam_if_error($items, fn()=> [[$items->get_error_message()]]);

		return ['items'=>$items];
	}

	public static function get_defaults(){
		return [
			'post_type'	=> [
				'model'			=> 'WPJAM_Post',
				'label_field'	=> 'post_title',
				'id_field'		=> 'ID',
				'meta_type'		=> 'post',
				'parse_value'	=> 'wpjam_get_post'
			],
			'taxonomy'	=> [
				'model'			=> 'WPJAM_Term',
				'label_field'	=> 'name',
				'id_field'		=> 'term_id',
				'meta_type'		=> 'term',
				'parse_value'	=> 'wpjam_get_term'
			],
			'author'	=> [
				'model'			=> 'WPJAM_User',
				'label_field'	=> 'display_name',
				'id_field'		=> 'ID',
				'meta_type'		=> 'user'
			],
			'model'		=> [],
			'video'		=> ['parse_value'=>'wpjam_get_video_mp4'],
		];
	}

	public static function get_instance($name, $args=[]){
		if(is_a($name, 'WPJAM_Field')){
			$field	= $name;
			$name	= $field->data_type;
		}

		$object	= self::get($name);

		if($object){
			if(isset($field)){
				$args	= wp_parse_args($field->query_args ?: []);

				if($field->$name){
					$args[$name]	= $field->$name;
				}elseif(!empty($args[$name])){
					$field->$name	= $args[$name];
				}
			}

			if($name == 'model'){
				$model	= $args['model'];

				if(!$model || !class_exists($model)){
					return null;
				}

				$sub	= $object->get_sub($model);

				if($sub){
					return $sub;
				}

				$args['meta_type']		= wpjam_call([$model, 'get_meta_type']) ?: '';
				$args['label_field']	??= wpjam_pull($args, 'label_key') ?: 'title';
				$args['id_field']		??= wpjam_pull($args, 'id_key') ?: wpjam_call([$model, 'get_primary_key']);

				$object	= $object->register_sub($model, $args);
				$args	= wpjam_except($args, 'meta_type');
			}

			if(isset($field) && $object->model){
				$field->query_args	= $args ?: new StdClass;
			}
		}

		return $object;
	}

	public static function prepare($args, $output='args'){
		$type	= (is_array($args) || is_object($args)) ? wpjam_get($args, 'data_type') : '';
		$args	= ($type ? ['data_type' => $type] : [])+(in_array($type, ['post_type', 'taxonomy']) ? [$type => (wpjam_get($args, $type) ?: '')] : []);

		return $output == 'key' ? ($args ? '__'.md5(serialize(array_map(fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, $args))) : '') : $args;
	}

	public static function except($args){
		return array_diff_key($args, self::prepare($args));
	}
}

class WPJAM_Invoker{
	protected $class;
	protected $annotation;
	protected $reflection;
	protected $reflections	= [];

	protected function __construct($class){
		$this->class	= $class;
	}

	public function call($method, ...$args){
		try{
			return $this->parse($method, $args)(...$args);
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public function try($method, ...$args){
		return wpjam_if_error($this->parse($method, $args)(...$args), 'throw');
	}

	public function exists($method){
		return method_exists($this->class, $method);
	}

	public function undefined($method){
		wpjam_throw('undefined_method', $this->class.'::'.$method);
	}

	public function parse($method, &$args=[]){
		if($this->exists($method)){
			$ref	= $this->get_reflection($method);
			$attr	= wpjam_fill(['Public', 'Static'], fn($k)=> $ref->{'is'.$k}());
		}else{
			$attr	= ['Public'=>true, 'Static'=>$this->exists('__callStatic')];

			if(!$attr['Static'] && !$this->exists('__call')){
				$this->undefined($method);
			}
		}

		$cb	= [$this->class, $method];

		if(!$attr['Static']){
			$args	= ($method == 'value_callback' && count($args) == 2) ? array_reverse($args) : $args;
			$cb[0]	= $this->get_instance($args);
		}

		return $attr['Public'] ? $cb : $ref->getClosure($attr['Static'] ? null : $cb[0]);
	}

	public function verify($method, $verifier){
		return wpjam_verify_callback([$this->class, $method], $verifier);
	}

	public function get_reflection($method=''){
		if($method){
			return $this->reflections[$method] ??= new ReflectionMethod($this->class, $method);
		}

		return $this->reflection ??= new ReflectionClass($this->class);
	}

	public function get_annotation(){
		if(!$this->annotation){
			$data	= [];
			$ref	= $this->get_reflection();

			if(method_exists($ref, 'getAttributes')){
				foreach($ref->getAttributes() as $attr){
					$k	= $attr->getName();
					$v	= $attr->getArguments();
					$v	= ($v && wp_is_numeric_array($v) && ($k == 'config' ? is_array($v[0]) : count($v) == 1)) ? $v[0] : $v;

					$data[$k]	= $v ?: null;
				}
			}else{
				if(preg_match_all('/@([a-z0-9_]+)\s+([^\r\n]*)/i', ($ref->getDocComment() ?: ''), $matches, PREG_SET_ORDER)){
					foreach($matches as $m){
						$k	= $m[1];
						$v	= trim($m[2]) ?: null;

						$data[$k]	= ($v && $k == 'config') ? wp_parse_list($v) : $v;
					}
				}
			}

			$data['config']	= wpjam_array($data['config'] ?? [], fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v, 2) : [$v, true]) : [$k, $v]);

			$this->annotation	= $data;
		}

		return $this->annotation;
	}

	public function get_instance(&$args=[]){
		$cb	= [$this->class, 'get_instance'];

		if(!$this->exists($cb[1])){
			$this->undefined($cb[1]);
		}

		$number	= $this->get_reflection('get_instance')->getNumberOfRequiredParameters();
		$number	= $number > 1 ? $number : 1;

		if(count($args) < $number){
			wpjam_throw('instance_required', '实例方法对象才能调用');
		}

		$object	= $cb(...array_slice($args, 0, $number));
		$args	= array_slice($args, $number);

		return $object ?: wpjam_throw('invalid_id', [$cb[0]]);
	}

	public static function create($class){
		if(!class_exists($class)){
			wpjam_throw('invalid_model', [$class]);
		}

		static $objects	= [];
		return $objects[strtolower($class)] ??= new self($class);
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		$args		+= ['body'=>[], 'headers'=>[], 'sslverify'=>false, 'stream'=>false];
		$headers	= &$args['headers'];
		$headers	= wpjam_array($headers, fn($k)=> strtolower($k));
		$method		= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'GET'){
			$response	= wp_remote_get($url, $args);
		}elseif($method == 'FILE'){
			$response	= (new WP_Http_Curl())->request($url, $args+[
				'method'			=> $args['body'] ? 'POST' : 'GET',
				'sslcertificates'	=> ABSPATH.WPINC.'/certificates/ca-bundle.crt',
				'user-agent'		=> $args['headers']['user-agent'] ?? 'WordPress',
				'decompress'		=> true,
			]);
		}else{
			$content_type	= $headers['content-type'] ?? '';

			if(str_contains($content_type, 'application/json') || wpjam_at(wpjam_pull($args, ['json_encode', 'need_json_encode']), 0)){
				if(is_array($args['body'])){
					$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
				}

				if(!$content_type){
					$headers['content-type']	= 'application/json';
				}
			}

			$response	= wp_remote_request($url, $args+['method'=>$method]);
		}

		return self::parse($response, $url, $args, $err);
	}

	private static function parse($response, $url, $args, $err){
		if(is_wp_error($response)){
			return self::trigger($response, $url, $args['body']);
		}

		$code	= $response['response']['code'] ?? 0;

		if($code && ($code < 200 || $code >= 300)){
			return new WP_Error($code, '远程服务器错误：'.$code.' - '.$response['response']['message']);
		}

		$body	= &$response['body'];

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($response, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
				$result	= wpjam_json_decode($body);

				if(!is_wp_error($result)){
					$err	+= [
						'errcode'	=> 'errcode',
						'errmsg'	=> 'errmsg',
						'detail'	=> 'detail',
						'success'	=> '0',
					];

					$code	= wpjam_pull($result, $err['errcode']);

					if($code && $code != $err['success']){
						$msg	= wpjam_pull($result, $err['errmsg']);
						$detail	= wpjam_pull($result, $err['detail']);
						$detail	= is_null($detail) ? array_filter($result) : $detail;

						return self::trigger(new WP_Error($code, $msg, $detail), $url, $args['body']);
					}

					$body	= $result;
				}
			}
		}

		return $response;
	}

	private static function trigger($error, $url, $body){
		$code	= $error->get_error_code();
		$msg	= $error->get_error_message();
		$detail	= $error->get_error_data();

		if(apply_filters('wpjam_http_response_error_debug', true, $code, $msg)){
			trigger_error($url."\n".$code.' : '.$msg."\n".($detail ? var_export($detail, true)."\n" : '').var_export($body, true));
		}

		return $error;
	}
}

class WPJAM_File{
	public static function is_attachment($id){
		return $id && get_post_type($id) == 'attachment';
	}

	public static function get_by_id($id, $field='file'){
		if(self::is_attachment($id)){
			if($field == 'file'){
				return get_attached_file($id);
			}elseif($field == 'path'){
				return self::convert(get_attached_file($id), $field, 'file');
			}elseif($field == 'url'){
				return wp_get_attachment_url($id);
			}elseif($field == 'size'){
				return wpjam_pick((wp_get_attachment_metadata($id) ?: []), ['width', 'height']);
			}
		}
	}

	public static function add_to_media($file, $args=[]){
		require_once ABSPATH.'wp-admin/includes/image.php';

		$meta	= wp_read_image_metadata($file);
		$title	= $meta ? trim($meta['title']) : '';
		$title	= ($title && !is_numeric(sanitize_title($title))) ? $title : preg_replace('/\.[^.]+$/', '', wp_basename($file));
		$id		= wp_insert_attachment([
			'post_title'		=> $title,
			'post_content'		=> $meta ? (trim($meta['caption']) ?: '') : '',
			'post_parent'		=> $args['post_id'] ?? 0,
			'post_mime_type'	=> $args['type'] ?? mime_content_type($file),
			'guid'				=> $args['url'] ?? self::convert($file, 'url'),
		], $file, ($args['post_id'] ?? 0), true);

		if(!is_wp_error($id)){
			wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
		}

		return $id;
	}

	public static function accept_to_mime_types($accept){
		$allowed	= get_allowed_mime_types();

		if($accept){
			$types	= [];

			foreach(explode(',', $accept) as $v){
				if($v = strtolower(trim($v))){
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
			}
		
			return $types;
		}
	}

	public static function upload($name, $args=[]){
		require_once ABSPATH.'wp-admin/includes/file.php';

		if($bits = wpjam_pull($args, 'bits')){
			if(preg_match('/data:image\/([^;]+);base64,/i', $bits, $matches)){
				$bits	= base64_decode(trim(substr($bits, strlen($matches[0]))));
				$name	= preg_replace('/\.[^.]+$/', '', $name).'.'.$matches[1];
			}

			$upload	= wp_upload_bits($name, null, $bits);
		}else{	
			$args	+= ['test_form'=>false];
			$upload	= is_array($name) ? wp_handle_sideload($name, $args) : wp_handle_upload($_FILES[$name], $args);
		}

		if(isset($upload['error']) && $upload['error'] !== false){
			return new WP_Error('upload_error', $upload['error']);
		}

		return $upload+['path'=>self::convert($upload['file'], 'path')];
	}

	public static function download($url, $args){
		$media	= $args['media'] ?? false;
		$field	= wpjam_get($args, 'field') ?: ($media ? 'id' : 'file');
		$id		= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

		if(!self::is_attachment($id)){
			try{
				$tmp	= wpjam_try('download_url', $url);
				$upload	= ['name'=>wpjam_get($args, 'name') ?: md5($url).'.'.(explode('/', wp_get_image_mime($tmp))[1]), 'tmp_name'=>$tmp];

				if(!$media){
					return wpjam_try([self::class, 'upload'], $upload)[$field];
				}

				$id	= wpjam_try('media_handle_sideload', $upload, ($args['post_id'] ?? 0));

				update_post_meta($id, 'source_url', $url);
			}catch(Exception $e){
				@unlink($tmp);

				return wpjam_catch($e);
			}
		}

		return self::get_by_id($id, $field);
	}

	public static function restore($id, $url=''){
		$file = get_attached_file($id, true);

		if($file && !file_exists($file)){
			$dir	= dirname($file);

			if(!is_dir($dir)){
				mkdir($dir, 0777, true);
			}

			$url	= $url ?: wp_get_attachment_url($id);
			$result	= wpjam_remote_request($url, ['stream'=>true, 'filename'=>$file]);

			if(is_wp_error($result)){
				return $result;
			}
		}

		return $file;
	}

	public static function convert($value, $to, $from='file'){
		if($to == $from){
			return $value;
		}

		if($from == 'id'){
			return self::get_by_id($value, $to);
		}

		$dir	= wp_get_upload_dir();

		if($from == 'path'){
			$path	= $value;
		}elseif($from == 'url'){
			$path	= self::parse_path(...array_map(fn($v)=> parse_url($v, PHP_URL_PATH), [$value, $dir['baseurl']]));
		}elseif($from == 'file'){
			$path	= self::parse_path($value, $dir['basedir']);
		}

		if($to == 'path' || empty($path)){
			return $path ?? null;
		}elseif($to == 'id'){
			return wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id');
		}elseif($to == 'url'){
			return $dir['baseurl'].$path;
		}elseif($to == 'file'){
			return $dir['basedir'].$path;
		}elseif($to == 'size'){
			$file	= $dir['basedir'].$path;
			$size	= file_exists($file) ? wp_getimagesize($file) : [];

			return $size ? ['width'=>$size[0], 'height'=>$size[1]] : self::convert(self::convert($file, 'id'), 'size', 'id');
		}
	}

	public static function parse_path($value, $base){
		return str_starts_with($value, $base) ? substr($value, strlen($base)) : null;
	}

	public static function parse_size($size, ...$args){
		$size	= $size ?: 0;
		$ratio	= ($args && !is_array($args[0])) ? (int)array_shift($args) : 1;
		$max	= ($args && is_array($args[0])) ? array_shift($args) : [];

		if(is_array($size)){
			$size	= wp_is_numeric_array($size) ? ['width'=>($size[0] ?? 0), 'height'=>($size[1] ?? 0)] : $size;
			$size	= array_merge($size, wpjam_fill(['width', 'height'], fn($k)=>(int)($size[$k] ?? 0)*$ratio));
			$size	+= ['crop'=>($size['width'] && $size['height'])];
		}elseif(is_numeric($size)){
			$size	= ['crop'=>false, 'width'=>(int)$size*$ratio, 'height'=>0];
		}else{
			$sep	= array_find(['*', 'x', 'X'], fn($v)=> str_contains($size, $v));
			$sizes	= wp_get_additional_image_sizes();

			if($sep && !isset($sizes[$size])){
				[$width, $height]	= array_replace([0,0], explode($sep, $size));
			}else{
				$name		= $size == 'thumb' ? 'thumbnail' : $size;
				$default	= ['thumbnail'=>[100, 100], 'medium'=>[300,300], 'medium_large'=>[768,0], 'large'=>[1024,1024]][$name] ?? '';

				if($default){
					$crop	= $name == 'thumbnail' ? get_option($name.'_crop') : false;

					[$width, $height]	= wpjam_map(['w', 'h'], fn($v, $k)=> get_option($name.'_size_'.$v) ?: $default[$k]);
				}else{
					[$width, $height, $crop]	= isset($sizes[$name]) ? [$sizes[$name]['width'], $sizes[$name]['height'], $sizes[$name]['crop']] : [0, 0, false];
				}

				if($width && !empty($GLOBALS['content_width']) && !in_array($name, ['thumbnail', 'medium'])){
					$width	= min($GLOBALS['content_width'] * $ratio, $width);
				}
			}
		
			$size	= [
				'crop'		=> $crop ?? ($width && $height),
				'width'		=> (int)$width * $ratio,
				'height'	=> (int)$height * $ratio
			];
		}

		if(count($max) >= 2 && $max[0] && $max[1]){
			$max	= ($size['width'] && $size['height']) ? wp_constrain_dimensions($size['width'], $size['height'], $max[0], $max[1]) : $max;
			$size	= array_merge($size, wpjam_array(['width', 'height'], fn($k, $v)=> [$v, min($size[$v], $max[$k])]));
		}

		return $size;
	}

	public static function get_thumbnail($url, ...$args){
		$url	= $url ? remove_query_arg(['orientation', 'width', 'height'], wpjam_zh_urlencode($url)) : $url;

		if(!$args){	// 1. 无参数
			$size	= [];
		}elseif(count($args) == 1){
			// 2. ['width'=>100, 'height'=>100]	标准版
			// 3. [100,100]
			// 4. 100x100
			// 5. 100

			$size	= $args[0] ? self::parse_size($args[0]) : [];
		}elseif(is_numeric($args[0])){
			// 6. 100, 100, $crop=1

			$size	= self::parse_size([
				'width'		=> $args[0],
				'height'	=> $args[1],
				'crop'		=> $args[2] ?? 1
			]);
		}else{
			// 7.【100,100], $crop=1

			$size	= array_merge(self::parse_size($args[0]), ['crop'=>$args[1]]);
		}

		return apply_filters('wpjam_thumbnail', $url, $size);
	}
}

class WPJAM_Error{
	public static function parse($data){
		if(is_wp_error($data)){
			$err	= $data->get_error_data();
			$data	= [
				'errcode'	=> $data->get_error_code(),
				'errmsg'	=> $data->get_error_message(),
			]+($err ? (is_array($err) ? $err : ['errdata'=>$err]) : []);
		}

		if(wpjam_is_assoc_array($data)){
			$data	+= ['errcode'=>0];
			$data	= array_merge($data, $data['errcode'] ? array_filter(self::parse_setting($data['errcode'], $data['errmsg'] ?? '')) : []);
		}

		return $data;
	}

	public static function parse_message($code, $args=null){
		$fn	= fn($map, $code)=> $map[$code] ?? ucwords($code);

		if(try_remove_prefix($code, 'invalid_')){
			if($code == 'parameter'){
				return $args ? '无效的参数：'.$args[0].'。' : '参数错误。';
			}elseif($code == 'callback'){
				return '无效的回调函数'.($args ? '：'.$args[0] : '').'。';
			}elseif($code == 'name'){
				return $args ? $args[0].'不能为纯数字。' : '无效的名称';
			}else{
				return [
					'nonce'		=> '验证失败，请刷新重试。',
					'code'		=> '验证码错误。',
					'password'	=> '两次输入的密码不一致。'
				][$code] ?? '无效的'.$fn([
					'id'			=> ' ID',
					'post_type'		=> '文章类型',
					'taxonomy'		=> '分类模式',
					'post'			=> '文章',
					'term'			=> '分类',
					'user'			=> '用户',
					'comment_type'	=> '评论类型',
					'comment_id'	=> '评论 ID',
					'comment'		=> '评论',
					'type'			=> '类型',
					'signup_type'	=> '登录方式',
					'email'			=> '邮箱地址',
					'data_type'		=> '数据类型',
					'qrcode'		=> '二维码',
				], $code);
			}
		}elseif(try_remove_prefix($code, 'illegal_')){
			return $fn([
				'access_token'	=> 'Access Token ',
				'refresh_token'	=> 'Refresh Token ',
				'verify_code'	=> '验证码',
			], $code).'无效或已过期。';
		}elseif(try_remove_suffix($code, '_required')){
			return $args ? sprintf(($code == 'parameter' ? '参数%s' : '%s的值').'为空或无效。', ...$args) : '参数或者值无效';
		}elseif(try_remove_suffix($code, '_occupied')){
			return $fn([
				'phone'		=> '手机号码',
				'email'		=> '邮箱地址',
				'nickname'	=> '昵称',
			], $code).'已被其他账号使用。';
		}

		return '';
	}

	public static function parse_setting($code, $args=null){
		if($item = wpjam_get_setting('wpjam_errors', $code)){
			$item	= [
				'errmsg'	=> $item['errmsg'],
				'modal'		=> array_all(['show_modal', 'modal.title', 'modal.content'], fn($k)=> wpjam_get($item, $k)) ? $item['modal'] : null
			];
		}elseif($item = (!$args || is_array($args)) ? self::get_setting($code) : []){
			$item['errmsg']	= maybe_closure($item['errmsg'], $args ?: []);
		}

		if($item && $args && is_array($args)){
			$item['errmsg']	= sprintf($item['errmsg'], ...$args);
		}

		return $item ?: [];
	}

	public static function get_setting(...$args){
		return wpjam('get', 'error', ...$args);
	}

	public static function add_setting($code, $errmsg, $modal=[]){
		if(!self::get_setting()){
			add_action('wp_error_added', [self::class, 'on_wp_error_added'], 10, 4);
		}

		return wpjam('add', 'error', $code, compact('errmsg', 'modal'));
	}

	public static function wp_die_handler($message, $title='', $args=[]){
		$errmsg		= wpjam_if_error($message, 'send');
		$errcode	= $args['code'] ?? '';

		if($errcode){
			$detail		= $title ? ['modal'=>['title'=>$title, 'content'=>$errmsg]] : [];
		}elseif($title){
			$errcode	= $title;
		}elseif(is_string($errmsg)){
			$item	= self::parse_setting($errmsg);
			$parsed	= $item ? wpjam_send_json($item) : self::parse_message($errmsg);

			if($parsed){
				[$errcode, $errmsg]	= [$errmsg, $parsed];
			}
		}

		wpjam_send_json(['errcode'=>($errcode ?: 'error'), 'errmsg'=>$errmsg]+($detail ?? []));
	}

	public static function on_wp_error_added($code, $message, $data, $wp_error){
		if($code && (!$message || is_array($message)) && count($wp_error->get_error_messages($code)) <= 1){
			$args	= $message ?: [];
			$item	= self::parse_setting($code, $args);
			$parsed	= $item ? $item['errmsg'] : self::parse_message($code, $args);

			if($item && !empty($item['modal'])){	
				$data	= array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]);
			}

			$wp_error->remove($code);
			$wp_error->add($code, ($parsed ?: $code), $data);
		}
	}
}

class WPJAM_Exception extends Exception{
	private $errcode	= '';

	public function __construct($errmsg, $errcode=null, Throwable $previous=null){
		if(is_array($errmsg)){
			$errmsg	= new WP_Error($errcode, $errmsg);
		}

		if(is_wp_error($errmsg)){
			$errcode	= $errmsg->get_error_code();
			$errmsg		= $errmsg->get_error_message();
		}

		$this->errcode	= $errcode ?: 'error';

		parent::__construct($errmsg, (is_numeric($errcode) ? (int)$errcode : 1), $previous);
	}

	public function get_error_code(){
		return $this->errcode;
	}

	public function get_error_message(){
		return $this->getMessage();
	}

	public function get_wp_error(){
		return new WP_Error($this->errcode, $this->getMessage());
	}
}