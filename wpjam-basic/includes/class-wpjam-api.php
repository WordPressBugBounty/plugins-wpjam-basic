<?php
class WPJAM_API{
	public static function get_parameter($name, $args=[]){
		if(!is_array($args)){
			$method	= $args;

			if($method == 'DATA'){
				if($name && isset($_GET[$name])){
					return wp_unslash($_GET[$name]);
				}

				$data	= wpjam_var('data', fn()=> array_reduce(['defaults', 'data'], function($carry, $k){
					$v	= self::get_parameter($k, 'REQUEST') ?? [];
					$v	= ($v && is_string($v) && str_starts_with($v, '{')) ? wpjam_json_decode($v) : wp_parse_args($v);

					return wpjam_merge($carry, $v);
				}, []));
			}else{
				$data	= ['POST'=>$_POST, 'REQUEST'=>$_REQUEST][$method] ?? $_GET;

				if($name){
					if(isset($data[$name])){
						return wp_unslash($data[$name]);
					}

					if($_POST || !in_array($method, ['POST', 'REQUEST'])){
						return null;
					}
				}else{
					if($data || in_array($method, ['GET', 'REQUEST'])){
						return wp_unslash($data);
					}
				}

				$data	= wpjam_var('php_input', function(){
					$input	= file_get_contents('php://input');
					$input	= is_string($input) ? @wpjam_json_decode($input) : $input;

					return is_array($input) ? $input : [];
				});
			}

			return $name ? ($data[$name] ?? null) : $data;
		}

		if(is_array($name)){
			$name	= $name && wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name;

			return $name ? wpjam_map($name, fn($v, $n)=> self::get_parameter($n, $v)) : [];
		}

		$method	= strtoupper((array_get($args, 'method') ?: 'GET'));
		$value	= self::get_parameter($name, $method);

		if($name){
			if(is_null($value) && !empty($args['fallback'])){
				$value	= self::get_parameter($args['fallback'], $method);
			}

			$value	??= $args['default'] ?? ((wpjam_var('defaults') ?: [])[$name] ?? null);
			$args	= wpjam_except($args, ['method', 'fallback', 'default']);

			if($args){
				$args['type']	??= '';
				$args['type']	= $args['type'] == 'int' ? 'number' : $args['type'];	// 兼容

				$send	= wpjam_pull($args, 'send') ?? true;
				$field	= wpjam_field(array_merge($args, ['key'=>$name]));
				$field	= $args['type'] ? $field : $field->set_schema(false);
				$value	= $field->catch('validate', $value, 'parameter');
				$value	= $send ? wpjam_if_error($value, 'send') : $value;
			}
		}

		return $value;
	}

	public static function request($url, $args=[], $err=[]){
		$args	+= ['body'=>[], 'headers'=>[], 'sslverify'=>false, 'stream'=>false];
		$method	= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'GET'){
			$response	= wp_remote_get($url, $args);
		}elseif($method == 'FILE'){
			$response	= (new WP_Http_Curl())->request($url, $args+[
				'method'			=> $args['body'] ? 'POST' : 'GET',
				'sslcertificates'	=> ABSPATH.WPINC.'/certificates/ca-bundle.crt',
				'user-agent'		=> 'WordPress',
				'decompress'		=> true,
			]);
		}else{
			$headers	= &$args['headers'];
			$headers	= wpjam_array($headers, fn($k)=> strtolower($k));

			if((!empty($headers['content-type']) && str_contains($headers['content-type'], 'application/json')) 
				|| wpjam_at(wpjam_pull($args, ['json_encode', 'json_encode_required', 'need_json_encode']), 0)
			){
				if(is_array($args['body'])){
					$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
				}

				if(empty($headers['content-type'])){
					$headers['content-type']	= 'application/json';
				}
			}

			$response	= wp_remote_request($url, $args+['method'=>$method]);
		}

		$trigger	= fn($code, $msg, $detail)=> trigger_error($url."\n".$code.' : '.$msg."\n".($detail ? var_export($detail, true)."\n" : '').var_export($args['body'], true));

		if(is_wp_error($response)){
			$code	= $response->get_error_code();
			$msg	= $response->get_error_message();

			if(apply_filters('wpjam_http_response_error_debug', true, $code, $msg)){
				$trigger($code, $msg, $response->get_error_data());
			}

			return $response;
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

						$trigger($code, $msg, $detail);

						return new WP_Error($code, $msg, $detail);
					}

					$body	= $result;
				}
			}
		}

		return $response;
	}

	public static function on_plugins_loaded(){
		self::activation();

		add_filter('query_vars',	fn($vars)=> ['module', 'action', 'term_id', ...$vars]);

		add_filter('register_post_type_args',	['WPJAM_Post_Type', 'filter_register_args'], 999, 2);
		add_filter('register_taxonomy_args',	['WPJAM_Taxonomy', 'filter_register_args'], 999, 3);

		add_filter('request',			['WPJAM_Posts', 'parse_query_vars'], 11);
		add_filter('posts_clauses',		['WPJAM_Posts', 'filter_clauses'], 1, 2);
		add_filter('content_save_pre',	['WPJAM_Posts', 'filter_content_save_pre'], 1);

		add_filter('wp_video_shortcode_override', fn($override, $attr, $content)=> wpjam_video($content, $attr) ?: $override, 10, 3);
	}

	public static function __callStatic($method, $args){
		if($method == 'activation'){
			$handler	= wpjam_get_handler(['items_type'=>'transient', 'transient'=>'wpjam-actives']);
			$action		= array_shift($args);

			return $action == 'add' ? $handler->add($args) : array_map(fn($active)=> add_action(...$active), $handler->empty());
		}

		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}
}

class WPJAM_Route{
	public static function __callStatic($method, $args){
		array_map('wpjam_'.$method, array_column(wpjam_get_items('route'), substr($method, 4)));
	}

	public static function add($name, $args){
		if(!$name){
			return;
		}

		if(!wpjam_get_items('route')){
			add_action('wp_loaded',		[self::class, 'add_rewrite_rule']);
			add_action('parse_request',	[self::class, 'request'], 1);

			if(is_admin()){
				add_action('wpjam_admin_init',	[self::class, 'add_menu_page']);
				add_action('wpjam_admin_init',	[self::class, 'add_admin_load']);

				if(wp_doing_ajax()){
					add_action('admin_init', fn()=> self::request('data'));
				}
			}
		}

		if(!is_array($args) || wp_is_numeric_array($args)){
			$args	= is_callable($args) ? ['callback'=>$args] : (array)$args;
		}elseif(!empty($args['model'])){
			$model	= wpjam_pull($args, 'model');
			$map	= ['callback'=>'redirect', 'rewrite_rule'=>'get_rewrite_rule'];
			$args	= wpjam_array($map, fn($k, $v)=> (empty($args[$k]) && method_exists($model, $v)) ? [$k, [$model, $v]] : null)+$args;
		}

		wpjam_add_item('route', $name, $args);
	}

	public static function request($wp){
		$args	= ['method'=> is_object($wp) ? 'GET' : $wp];

		foreach(wpjam_get_items('route') as $module => $item){
			if(!empty($item['query_var'])){
				$action	= wpjam_get_parameter($module, $args);

				if($action){
					self::dispatch($module, $action);
				}
			}
		}

		if(is_object($wp)){
			$module	= $wp->query_vars['module'] ?? '';

			if($module){
				remove_action('template_redirect',	'redirect_canonical');

				self::dispatch($module, $wp->query_vars['action'] ?? '');
			}
		}
	}

	public static function dispatch($module, $action){
		$item	= wpjam_get_item('route', $module);

		if($item){
			if(!empty($item['callback'])){
				wpjam_call($item['callback'], $action, $module);
			}

			if(!empty($item['query_var'])){
				$GLOBALS['wp']->set_query_var($module, $action);
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
}

class WPJAM_JSON extends WPJAM_Register{
	public function response(){
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$response	= wpjam_if_error(apply_filters('wpjam_pre_json', [], $this->args, $this->name), 'throw')+[
			'errcode'		=> 0,
			'current_user'	=> wpjam_try('wpjam_get_current_user', $this->pull('auth'))
		];

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			$response	+= wpjam_pick($this, ['page_title', 'share_title', 'share_image']);
		}

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
			$results[]	= wpjam_if_error(wpjam_call($callback, ($this->fields ? $data : $this->args), $this->name), 'thorw');
		}elseif($this->template){
			$results[]	= is_file($this->template) ? include $this->template : '';
		}else{
			$results[]	= $this->args;
		}

		foreach($results as $result){
			if(is_array($result)){
				$keys		= wpjam_filter(['page_title', 'share_title', 'share_image'], fn($k)=> !empty($response[$k]));
				$response	= array_merge($response, wpjam_except($result, $keys));
			}
		}

		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			$response	= array_merge($response, wpjam_fill(['page_title', 'share_title'], fn($k)=> empty($response[$k]) ? html_entity_decode(wp_get_document_title()) : $response[$k]));

			if(!empty($response['share_image'])){
				$response['share_image']	= wpjam_get_thumbnail($response['share_image'], '500x400');
			}
		}

		return $response;
	}

	public static function parse_module($module, $throw=false){
		$args	= wpjam_get($module, 'args', []);
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= wpjam_get($module, 'parser') ?: wpjam_get_item('json_module_parser', wpjam_get($module, 'type'));

		return $parser ? ($throw ? wpjam_try($parser, $args) : wpjam_catch($parser, $args)) : $args;
	}

	public static function add_module_parser($type, $callback){
		wpjam_add_item('json_module_parser', $type, $callback);
	}

	public static function redirect($action){
		if(!wpjam_doing_debug()){
			header('X-Content-Type-Options: nosniff');

			rest_send_cors_headers(false); 

			if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
				status_header(403);
				exit;
			}

			@header('Content-Type: application/'.(wp_is_jsonp_request() ? 'javascript' : 'json').'; charset='.get_option('blog_charset'));
		}

		if(!str_starts_with($action, 'mag.')){
			return;
		}

		$part	= array_find(['_jsonp', '_json'], fn($v)=> call_user_func('wp_is'.$v.'_request')) ?: '';

		add_filter('wp_die'.$part.'_handler',	fn()=> ['WPJAM_Error', 'wp_die_handler']);

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
		], fn($v, $k)=> self::add_module_parser($k, $v));

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

class WPJAM_Extend extends WPJAM_Args{
	public function load(){
		$this->dir = maybe_callback($this->dir);

		if(!is_dir($this->dir)){
			return;
		}

		if($this->option){
			$this->option	= wpjam_register_option($this->option, $this->to_array()+[
				'model'			=> $this,
				'ajax'			=> false,
				'site_default'	=> $this->sitewide
			]);

			$extends	= array_keys(array_merge($this->get_option(), $this->get_option(true)));
		}else{
			$extends	= array_diff(scandir($this->dir), ['.', '..']);

			if($plugins	= get_option('active_plugins')){
				$extends	= array_filter($extends, fn($v)=> !in_array($v.(is_dir($this->dir.'/'.$v) ? '/'.$v : '').'.php', $plugins));
			}
		}

		array_walk($extends, fn($extend)=> $this->handle($extend, 'include'));
	}

	private function handle($extend, $action='include'){
		if(str_ends_with($extend, '.php')){
			$extend	= substr($extend, 0, -4);
		}

		if($action == 'parse'){
			return $extend;
		}

		if($extend == 'extends'){
			return;
		}

		$file	= $this->dir.'/'.$extend;
		$file	.= is_dir($file) ? '/'.$extend.'.php' : '.php';

		if(is_file($file)){
			if($action == 'get_data'){
				return $this->get_file_data($file);
			}

			if(is_admin() || !str_ends_with($file, '-admin.php')){
				include_once $file;
			}
		}
	}

	private function get_option($site=false){
		return $this->sanitize_callback($this->option->get_option($site));
	}

	public function get_fields(){
		return wpjam_sort(wpjam_array(array_diff(scandir($this->dir), ['.', '..']), function($k, $extend){
			$extend	= $this->handle($extend, 'parse');
			$data	= $this->handle($extend, 'get_data');

			if($data && $data['Name']){
				$option	= $this->get_option();

				if(is_multisite() && $this->sitewide){
					$sitewide	= $this->get_option(true);

					if(is_network_admin()){
						$option	= $sitewide;
					}elseif(!empty($sitewide[$extend])){
						return;
					}
				}
			
				return [$extend, [
					'value'	=> !empty($option[$extend]),
					'title'	=> $data['URI'] ? '<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>' : $data['Name'],
					'label'	=> $data['Description']
				]];
			}
		}), ['value'=>'DESC']);
	}

	public function sanitize_callback($data){
		return wpjam_array(array_filter($data), fn($k)=> $this->handle($k, 'parse'));
	}

	public static function get_file_data($file){
		$data	= $file ? get_file_data($file, [
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		]) : [];

		return $data ? wpjam_fill(['URI', 'Name'], fn($k)=> !empty($data[$k]) ? $data[$k] : ($data['Plugin'.$k] ?? ''))+$data : [];
	}

	public static function get_file_summay($file){
		$data	= self::get_file_data($file);

		return str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。';
	}

	public static function create($dir, ...$args){
		$args	= is_array($dir) ? $dir : array_merge(($args[0] ?? []), compact('dir'));
		$hook	= wpjam_pull($args, 'hook');
		$object	= new self($args);

		if($hook){
			wpjam_load($hook, [$object, 'load'], ($object->priority ?? 10));
		}else{
			$object->load();
		}
	}
}

/**
* @config orderby=order order=ASC
**/
#[config(['orderby'=>'order', 'order'=>'ASC'])]
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
			$groups	= WPJAM_Path::get_groups($strict);
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
				wpjam_add_item('path_group', $group['key'], ['title'=>$group['title'], 'options'=>[]]);

				$args['group']	= $group['key'];
			}
		}

		$args	= array_merge($args, ['platform'=>$pf, 'path_type'=>$pf]);

		$this->update_args($args, false)->add_item($pf, $args);

		$platform->add_item($this->name, $args);
	}

	public static function get_groups($strict=false){
		return array_merge(
			['tabbar'=>['title'=>'菜单栏/常用', 'options'=>($strict ? [] : ['none'=>'只展示不跳转'])]],
			wpjam_get_items('path_group'),
			['others'=>['title'=>'其他页面', 'options'=>[]]]
		);
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

class WPJAM_Method{
	protected $class;

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
		$cb	= [$this->class, $method];

		if($this->exists($cb[1])){
			$reflection	= wpjam_get_reflection($cb);
			$is_public	= $reflection->isPublic();
			$is_static	= $reflection->isStatic();
		}else{
			$is_public = true;
			$is_static = $this->exists('__callStatic');

			if(!$is_static && !$this->exists('__call')){
				$this->undefined($cb[1]);
			}
		}

		if($is_static){
			return $is_public ? $cb : $reflection->getClosure();
		}

		if($method == 'value_callback' && count($args) == 2){
			$args	= array_reverse($args);
			$cb[0]	= $this->get_instance($args);
		}else{
			$cb[0]	= $this->get_instance($args);
		}

		return $is_public ? $cb : $reflection->getClosure($cb[0]);
	}

	public function verify($method, $verify){
		$reflection	= wpjam_get_reflection([$this->class, $method]);

		return $verify($reflection->getParameters(), $reflection);
	}

	public function get_instance(&$args=[]){
		$cb	= [$this->class, 'get_instance'];

		if(!$this->exists($cb[1])){
			$this->undefined($cb[1]);
		}

		$number	= wpjam_get_reflection($cb)->getNumberOfRequiredParameters();
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

		return wpjam_get_instance(get_called_class(), strtolower($class), fn()=> new self($class));
	}
}

class WPJAM_Chainable{
	private $object;
	private $value;

	public function __construct($object, $value=null){
		$this->object	= $object;
		$this->value	= $value;
	}

	public function __call($method, $args){
		if($method == 'value'){
			if(!$args){
				return $this->value;
			}

			$this->value	= $args[0];
		}else{
			$this->value	= wpjam_try([$this->object, $method], $this->value, ...$args);
		}

		return $this;
	}
}

class WPJAM_Error{
	public static function parse($data){
		if(is_wp_error($data)){
			$err	= $data->get_error_data();
			$data	= self::filter([
				'errcode'	=> $data->get_error_code(),
				'errmsg'	=> $data->get_error_message(),
			]+($err ? (is_array($err) ? $err : ['errdata'=>$err]) : []));
		}elseif(wpjam_is_assoc_array($data)){
			$data	= self::filter($data+['errcode'=>0]);
		}

		return $data;
	}

	public static function filter($data){
		$error	= wpjam_get_setting('wpjam_errors', $data['errcode']);

		if($error){
			$data['errmsg']	= $error['errmsg'];

			if(array_all(['show_modal', 'modal.title', 'modal.content'], fn($key)=> wpjam_get($error, $key))){
				$data['modal']	= $error['modal'];
			}
		}else{
			$item	= empty($data['errmsg']) ? self::get_setting($data['errcode']) : '';
			$data	= array_merge($data, $item ? wpjam_array(['errmsg'=>'message', 'modal'=>'modal'], fn($k, $v)=>$item[$v] ? [$k, $item[$v]] : null) : []);
		}

		return $data;
	}

	public static function parse_message($key, $message=[]){
		$fn	= fn($map, $key)=> $map[$key] ?? ucwords($key);

		if(try_remove_prefix($key, 'invalid_')){
			if($key == 'parameter'){
				return $message ? '无效的参数：'.$message[0].'。' : '参数错误。';
			}elseif($key == 'callback'){
				return '无效的回调函数'.($message ? '：'.$message[0] : '').'。';
			}elseif($key == 'name'){
				return $message ? $message[0].'不能为纯数字。' : '无效的名称';
			}else{
				return [
					'nonce'		=> '验证失败，请刷新重试。',
					'code'		=> '验证码错误。',
					'password'	=> '两次输入的密码不一致。'
				][$key] ?? '无效的'.$fn([
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
				], $key);
			}
		}elseif(try_remove_prefix($key, 'illegal_')){
			return $fn([
				'access_token'	=> 'Access Token ',
				'refresh_token'	=> 'Refresh Token ',
				'verify_code'	=> '验证码',
			], $key).'无效或已过期。';
		}elseif(try_remove_suffix($key, '_required')){
			$format	= $key == 'parameter' ? '参数%s' : '%s的值';

			return $message ? sprintf($format.'为空或无效。', ...$message) : '参数或者值无效';
		}elseif(try_remove_suffix($key, '_occupied')){
			return $fn([
				'phone'		=> '手机号码',
				'email'		=> '邮箱地址',
				'nickname'	=> '昵称',
			], $key).'已被其他账号使用。';
		}

		return '';
	}

	public static function get_setting($code){
		return wpjam_get_item('error', $code);
	}

	public static function add_setting($code, $message='', $modal=[]){
		if(!wpjam_get_items('error')){
			add_action('wp_error_added', [self::class, 'on_wp_error_added'], 10, 4);
		}

		if(is_array($code)){
			array_map(fn($args)=> self::add_setting(...$args), $code);
		}else{
			if($message){
				wpjam_add_item('error', $code, ['message'=>$message, 'modal'=>$modal]);
			}
		}
	}
	
	public static function wp_die_handler($message, $title='', $args=[]){
		$message	= wpjam_if_error($message, 'send');
		$code		= $args['code'] ?? '';

		if($code){
			$detail	= $title ? ['modal'=>['title'=>$title, 'content'=>$message]] : [];
		}elseif($title){
			$code	= $title;
		}else{
			$code	= 'error';

			if(is_scalar($message)){
				if(self::get_setting($message)){
					[$code, $message]	= [$message, ''];
				}else{
					$parsed	= self::parse_message($message);

					if($parsed){
						[$code, $message]	= [$message, $parsed];
					}
				}
			}
		}

		wpjam_send_json(['errcode'=>$code, 'errmsg'=>$message]+($detail ?? []));
	}

	public static function on_wp_error_added($code, $message, $data, $wp_error){
		if($code && (!$message || is_array($message)) && count($wp_error->get_error_messages($code)) <= 1){
			$item	= self::get_setting($code);

			if($item){
				$data	= $item['modal'] ? array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]) : $data;
				$msg	= $item['message'];
				$parsed	= is_callable($msg) ? $msg($message, $code) : (is_array($message) ? sprintf($msg, ...$message) : $msg);
			}else{
				$parsed	= self::parse_message($code, $message);
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