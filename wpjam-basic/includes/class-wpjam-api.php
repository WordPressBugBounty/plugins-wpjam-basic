<?php
class WPJAM_API{
	public static function activation($action='', $callback='', $hook=null){
		$transient	= 'wpjam-actives';
		$handler	= wpjam_get_handler($transient, ['items_type'=>'transient', 'transient'=>$transient]);

		if($action == 'add'){
			if($callback){
				$handler->add([($hook ?: 'wp_loaded'), $callback]);
			}
		}else{
			$items	= $handler->empty();

			if($items){
				wpjam_map($items, fn($active)=> add_action(...$active));
			}
		}
	}

	public static function on_plugins_loaded(){
		self::activation();

		add_filter('query_vars',	fn($vars)=> ['module', 'action', 'term_id', ...$vars]);

		add_filter('register_post_type_args',	['WPJAM_Post_Type', 'filter_register_args'], 999, 2);
		add_filter('register_taxonomy_args',	['WPJAM_Taxonomy', 'filter_register_args'], 999, 3);

		add_filter('request',			['WPJAM_Posts', 'parse_query_vars']);
		add_filter('posts_clauses',		['WPJAM_Posts', 'filter_clauses'], 1, 2);
		add_filter('content_save_pre',	['WPJAM_Posts', 'filter_content_save_pre'], 1);

		add_filter('wp_video_shortcode_override', fn($override, $attr, $content)=> wpjam_video($content, $attr) ?: $override, 10, 3);
	}

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
				$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

				if(is_wp_error($value) && $send){
					wpjam_send_json($value);
				}
			}
		}

		return $value;
	}

	public static function method_allow($method){
		$m	= $_SERVER['REQUEST_METHOD'];

		if($m != strtoupper($method)){
			wp_die('method_not_allow', '接口不支持 '.$m.' 方法，请使用 '.$method.' 方法！');
		}

		return true;
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

		if(is_wp_error($response)){
			return self::log_error($response, $url, $args['body']);
		}

		$body	= &$response['body'];

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($response, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}else{
				if(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
					$decoded	= wpjam_json_decode($body);

					if(!is_wp_error($decoded)){
						$body	= WPJAM_Error::if($decoded, $err);

						if(is_wp_error($body)){
							return self::log_error($body, $url, $args['body']);
						}
					}
				}
			}
		}

		$code	= $response['response']['code'] ?? 0;

		if($code && ($code < 200 || $code >= 300)){
			return new WP_Error($code, '远程服务器错误：'.$code.' - '.$response['response']['message']);
		}

		return $response;
	}

	private static function log_error($error, $url, $body){
		$code	= $error->get_error_code();
		$msg	= $error->get_error_message();

		if(apply_filters('wpjam_http_response_error_debug', true, $code, $msg)){
			$detail	= $error->get_error_data();
			$detail	= $detail ? var_export($detail, true)."\n" : '';

			trigger_error($url."\n".$code.' : '.$msg."\n".$detail.var_export($body, true));
		}

		return $error;
	}

	public static function __callStatic($method, $args){
		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}

	public static function get_apis(){	// 兼容
		return WPJAM_JSON::get_registereds();
	}
}

class WPJAM_Route{
	public static function __callStatic($method, $args){
		array_map('wpjam_'.$method, array_column(wpjam_get_items('route'), wpjam_remove_prefix($method, 'add_')));
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
			if(!empty($item['callback']) && is_callable($item['callback'])){
				$item['callback']($action, $module);
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
		$response	= apply_filters('wpjam_pre_json', [], $this->args, $this->name);
		$response	= wpjam_throw_if_error($response)+[
			'errcode'		=> 0,
			'current_user'	=> wpjam_try('wpjam_get_current_user', $this->pull('auth'))
		];

		if($method != 'POST' && !str_ends_with($this->name, '.config')){
			$response	+= wpjam_slice($this->get_args(), ['page_title', 'share_title', 'share_image']);
		}

		if($this->fields){
			$fields	= $this->fields;
			$fields	= is_callable($fields) ? wpjam_try($fields, $this->name) : $fields;
			$data	= wpjam_get_fields_parameter($fields, $method);
		}

		if($this->modules){
			$modules	= self::parse_modules($this->modules, $this->name);
			$results	= array_map([self::class, 'parse_module'], $modules);
		}else{
			$results	= [];
			$callback	= $this->pull('callback');

			if($callback){
				if(is_callable($callback)){
					$results[]	= wpjam_try($callback, ($this->fields ? $data : $this->args), $this->name);
				}
			}elseif($this->template){
				if(is_file($this->template)){
					$results[]	= include $this->template;
				}
			}else{
				$results[]	= $this->args;
			}
		}

		foreach($results as $result){
			wpjam_throw_if_error($result);

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

	public static function parse_modules($modules, $name){
		$modules	= is_callable($modules) ? $modules($name) : $modules;

		return wp_is_numeric_array($modules) ? $modules : [$modules];
	}

	public static function parse_module($module){
		$args	= wpjam_get($module, 'args', []);
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= wpjam_get($module, 'parser') ?: wpjam_get_item('json_module_parser', wpjam_get($module, 'type'));

		return $parser ? wpjam_catch($parser, $args) : $args;
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

		$part	= wpjam_find(['_jsonp', '_json'], fn($v)=> call_user_func('wp_is'.$v.'_request')) ?: '';

		add_filter('wp_die'.$part.'_handler',	[self::class, 'filter_die_handler']);

		$name	= wpjam_remove_prefix($action, 'mag.');
		$name	= wpjam_remove_prefix($name, 'mag.');	// 兼容
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
		$object	= $object ?: new WP_Error('invalid_api', '接口未定义');
		$result	= is_wp_error($object) ? $object : wpjam_catch([$object, 'response']);

		self::send($result);
	}

	public static function send($data=[], $code=null){
		$data	= wpjam_parse_error($data);
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

	public static function filter_die_handler(){
		return fn($message, $title='', $code=0)=> self::send(WPJAM_Error::convert($message, $title, $code));
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

class WPJAM_Var extends WPJAM_Args{
	private function __construct(){
		$this->args	= wpjam_parse_user_agent();
	}

	public function supports($feature){
		if($feature == 'webp'){
			return $this->browser == 'chrome' || $this->os == 'Android' || ($this->os == 'iOS' && version_compare($this->os_version, 14) >= 0);
		}
	}

	public static function get_instance(){
		static $object;

		return $object	??= new static();
	}
}

class WPJAM_Setting extends WPJAM_Args{
	private $values;

	public function __call($method, $args){
		if(str_ends_with($method, '_option')){
			$action		= wpjam_remove_postfix($method, '_option');
			$cb_args	= $this->type == 'blog_option' ? [$this->blog_id] : [];
			$cb_args[]	= $this->name;

			if($action == 'get'){
				if(isset($this->values)){
					return $this->values;
				}
			}elseif(in_array($action, ['add', 'update'])){
				$value		= array_shift($args);
				$cb_args[]	= $value ? $this->sanitize_option($value) : $value;
			}

			$cb		= $action.'_'.$this->type;
			$result	= $cb(...$cb_args);

			if($action == 'get'){
				$this->values	= $result = $result === false ? ($args ? $args[0] : []) : $this->sanitize_option($result);
			}else{
				$this->values	= null;
			}

			return $result;
		}elseif(str_ends_with($method, '_setting') || in_array($method, ['get', 'update', 'delete'])) {
			$action	= wpjam_remove_postfix($method, '_setting');
			$values	= $this->get_option();
			$name	= array_shift($args);

			if($action == 'get'){
				if(!$name){
					return $values;
				}

				if(is_array($name)){
					return wpjam_fill(array_filter($name), [$this, 'get']);
				}

				$value	= is_array($values) ? ($values[$name] ?? null) : null;

				if(is_null($value) || is_wp_error($value)){
					return null;
				}

				return is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value;
			}else{
				if($action == 'update'){
					$values	= array_replace($values, (is_array($name) ? $name : [$name=>array_shift($args)]));
				}else{
					$values	= wpjam_except($values, $name);
				}

				return $this->update_option($values);
			}
		}
	}

	public static function get_instance($type='', $name='', $blog_id=0){
		if(!in_array($type, ['option', 'site_option']) || !$name){
			return null;
		}

		$key	= $type.':'.$name;

		if(is_multisite() && $type == 'option'){
			$type		= 'blog_option';
			$blog_id	= (int)$blog_id ?: get_current_blog_id();
			$key		.= ':'.$blog_id;
		}

		return wpjam_get_instance('setting', $key, fn()=> new static([
			'type'		=> $type,
			'name'		=> $name,
			'blog_id'	=> $blog_id
		]));
	}

	public static function sanitize_option($value){
		return (is_wp_error($value) || !$value) ? [] : $value;
	}

	public static function parse_json_module($args){
		$option	= wpjam_get($args, 'option_name');

		if($option){
			$setting	= wpjam_get($args, 'setting_name') ?? wpjam_get($args, 'setting');
			$output		= wpjam_get($args, 'output') ?: ($setting ?: $option);
			$object 	= WPJAM_Option_Setting::get($option);
			$value		= $object ? $object->prepare() : wpjam_get_option($option);
			$value		= $setting ? ($value[$setting] ?? null) : $value;

			return [$output	=> $value];
		}
	}
}

/**
* @config menu_page, admin_load, register_json, init, loaded, orderby
**/
#[config('menu_page', 'admin_load', 'register_json', 'init', 'orderby')]
class WPJAM_Option_Setting extends WPJAM_Register{
	protected function filter_args(){
		return $this->args;
	}

	public function get_arg($key, $default=null, $should_callback=true){
		$value	= parent::get_arg($key, $default, $should_callback);

		if($value && $key == 'menu_page'){
			if(is_network_admin() && !$this->site_default){
				return;
			}

			if(wp_is_numeric_array($value)){
				foreach($value as &$v){
					if(!empty($v['tab_slug'])){
						if(empty($v['plugin_page'])){
							$v	= null;
						}
					}elseif(!empty($v['menu_slug']) && $v['menu_slug'] == $this->name){
						$v	+= ['menu_title'=>$this->title];
					}
				}

				return array_filter($value);
			}else{
				if(!empty($value['tab_slug'])){
					if(empty($value['plugin_page'])){
						return;
					}

					return $value+['title'=>$this->title];
				}else{
					return $value+['menu_slug'=>$this->name, 'menu_title'=>$this->title];
				}
			}
		}

		return $value;
	}

	public function get_current(){
		return self::get_sub(self::generate_sub_name()) ?: $this;
	}

	protected function get_sections($get_subs=false, $filter=true){
		$sections	= $this->get_arg('sections');
		$sections	= is_array($sections) ? $sections : [];

		if($sections){
			$sections	= array_filter($sections, 'is_array');
		}else{
			$fields	= $this->get_arg('fields', null, false);

			if(!is_null($fields)){
				$id			= $this->sub_name ?: $this->name;
				$sections	= [$id=> ['title'=>$this->title, 'fields'=>$fields]];
			}
		}

		foreach($sections as $id => &$section){
			$section['fields']	??= [];

			if(is_callable($section['fields'])){
				$section['fields']	= $section['fields']($id, $this->name);
			}
		}

		$subs		= $get_subs ? $this->get_subs() : [];
		$sections	= array_reduce($subs, fn($sections, $sub)=> array_merge($sections, $sub->get_sections(false, false)), $sections);

		return $filter ? WPJAM_Option_Section::filter($sections, $this->name) : $sections;
	}

	public function get_fields($get_subs=false){
		return array_merge(...array_column($this->get_sections($get_subs), 'fields'));
	}

	public function get_setting($name, ...$args){
		$null	= $name ? null : [];
		$type	= $this->option_type;
		$value	= wpjam_get_setting($this->name, $name);

		if($value !== $null){
			return $value; 
		}

		if($this->site_default && is_multisite()){
			$value	= wpjam_get_site_setting($this->name, $name);

			if($value !== $null){
				return $value; 
			}
		}

		if($args && $args[0] !== $null){
			return $args[0];
		}

		if($this->field_default){
			$this->_defaults ??= $this->call_fields('get_defaults');

			return $name ? wpjam_get($this->_defaults, $name) : $this->_defaults;
		}

		return $null;
	}

	public function update_setting(...$args){
		return wpjam_update_setting($this->name, ...$args);
	}

	public function delete_setting(...$args){
		return wpjam_delete_setting($this->name, ...$args);
	}

	protected function call_fields($method, ...$args){
		$get_subs	= $method != 'validate';
		$fields		= $this->get_fields($get_subs);

		return wpjam_fields($fields)->$method(...$args);
	}

	public function prepare(){
		return $this->call_fields('prepare', ['value_callback'=>[$this, 'value_callback']]);
	}

	public function validate($value){
		return $this->call_fields('validate', $value);
	}

	public function value_callback($name=''){
		if($this->option_type == 'array'){
			return is_network_admin() ? wpjam_get_site_setting($this->name, $name) : $this->get_setting($name);
		}else{
			return get_option($name, null);
		}
	}

	public function render($page){
		$sections	= $this->get_sections();
		$form		= wpjam_tag('form', ['action'=>'#', 'method'=>'POST', 'id'=>'wpjam_option']);

		foreach($sections as $id => $section){
			$tab	= wpjam_tag();

			if(count($sections) > 1){
				if(!$page->tab_page){
					$tab	= wpjam_tag('div', ['id'=>'tab_'.$id]);
					$nav[]	= wpjam_tag('a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id], $section['title'])->wrap('li')->data('show_if', wpjam_parse_show_if($section['show_if'] ?? null));
				}

				$title	= empty($section['title']) ? '' : [($page->tab_page ? 'h3' : 'h2'), [], $section['title']];
			}

			$form->append($tab->append([
				$title ?? '',
				empty($section['callback']) ? '' : wpjam_ob_get_contents($section['callback'], $section),
				empty($section['summary']) ? '' : wpautop($section['summary']),
				wpjam_fields($section['fields'])->render(['value_callback'=>[$this, 'value_callback']])
			]));
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append(wpjam_tag('p', ['submit'])->append([
			get_submit_button('', 'primary', 'option_submit', false, ['data-action'=>'save']),
			$this->reset ? get_submit_button('重置选项', 'secondary', 'option_reset', false, ['data-action'=>'reset']) : ''
		]));

		return isset($nav) ? $form->before(wpjam_tag('ul')->append($nav)->wrap('h2', ['nav-tab-wrapper', 'wp-clearfix']))->wrap('div', ['tabs']) : $form;
	}

	public function page_load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-option-action',	[$this, 'ajax_response']);
		}
	}

	public function ajax_response(){
		if(!check_ajax_referer($this->option_group, false, false)){
			wp_die('invalid_nonce');
		}

		if(!current_user_can($this->capability)){
			wp_die('access_denied');
		}

		$action	= wpjam_get_post_parameter('option_action');
		$values	= wpjam_get_data_parameter();
		$values	= $this->validate($values) ?: [];
		$fix	= is_network_admin() ? 'site_option' : 'option';

		if($this->option_type == 'array'){
			$args		= [$this->name, &$values];
			$callback	= $this->update_callback;

			if($callback){
				if(!is_callable($callback)){
					wp_die('无效的回调函数');
				}

				$args[]		= is_network_admin();
			}else{
				$callback	= 'wpjam_update_'.$fix;
			}

			$current	= $this->value_callback();

			if($action == 'reset'){
				$values	= wpjam_diff($current, $values);
			}else{
				$values	= wpjam_filter(array_merge($current, $values), fn($v)=> !is_null($v), true);
				$result	= $this->call_method('sanitize_callback', $values, $this->name);
				$values	= wpjam_throw_if_error($result) ?? $values;
			}

			$callback(...$args);
		}else{
			foreach($values as $name => $value){
				$args	= [$name];

				if($action == 'reset'){
					$callback	= 'delete_'.$fix;
				}else{
					$args[]		= $value;
					$callback	= 'update_'.$fix;
				}

				$callback(...$args);
			}
		}

		$errors	= array_filter(get_settings_errors(), fn($e)=> !in_array($e['type'], ['updated', 'success', 'info']));

		if($errors){
			wp_die(implode('&emsp;', array_column($errors, 'message')));
		}

		return [
			'type'		=> $this->response ?? ($this->ajax ? $action : 'redirect'),
			'errmsg'	=> $action == 'reset' ? '设置已重置。' : '设置已保存。'
		];
	}

	public static function generate_sub_name($args=null){
		$args	??= $GLOBALS;
		$args	= wpjam_slice($args, ['plugin_page', 'current_tab']);

		return $args ? wpjam_join(':', $args) : '';
	}

	public static function create($name, $args){
		$args	= is_callable($args) ? $args($name) : $args;
		$args	+= [
			'option_group'	=> $name, 
			'option_page'	=> $name, 
			'option_type'	=> 'array',
			'capability'	=> 'manage_options',
			'ajax'			=> true,
		];

		$except	= ['title', 'model', 'menu_page', 'admin_load', 'plugin_page', 'current_tab'];
		$sub	= self::generate_sub_name($args);
		$object	= self::get($name);

		if($object){
			if($sub){
				$object->update_args(wpjam_except($args, $except));

				return $object->register_sub($sub, $args);
			}else{
				if(is_null($object->primary)){
					return self::re_register($name, array_merge($object->to_array(), $args, ['primary'=>true]));
				}else{
					trigger_error('option_setting'.'「'.$name.'」已经注册。'.var_export($args, true));

					return $object;
				}
			}
		}else{
			if($args['option_type'] == 'array' && !doing_filter('sanitize_option_'.$name) && is_null(get_option($name, null))){
				add_option($name, []);
			}

			if($sub){
				$object	= self::register($name, wpjam_except($args, $except));

				return $object->register_sub($sub, $args);
			}else{
				return self::register($name, array_merge($args, ['primary'=>true]));
			}
		}
	}
}

/**
* @config menu_page, admin_load, init, loaded, orderby
**/
#[config('menu_page', 'admin_load', 'init', 'orderby')]
class WPJAM_Option_Section extends WPJAM_Register{
	public static function filter($sections, $option_name){
		foreach(self::get_by('option_name', $option_name) as $object){
			$object_sections	= $object->get_arg('sections');
			$object_sections	= is_array($object_sections) ? $object_sections : [];

			foreach($object_sections as $id => $section){
				if(!empty($section['fields']) && is_callable($section['fields'])){
					$section['fields']	= $section['fields']($id, $option_name);
				}

				if(isset($sections[$id])){
					$sections[$id]	= wpjam_merge($sections[$id], $section);
				}else{
					if(isset($section['title']) && isset($section['fields'])){
						$sections[$id]	= $section;
					}
				}
			}
		}

		return apply_filters('wpjam_option_setting_sections', $sections, $option_name);
	}

	public static function add($option_name, ...$args){
		$args	= is_array($args[0]) ? $args[0] : [$args[0]=> isset($args[1]['fields']) ? $args[1] : ['fields'=>$args[1]]];
		$args	= isset($args['model']) || isset($args['sections']) ? $args : ['sections'=>$args];

		return self::register(array_merge($args, ['option_name'=>$option_name]));
	}
}

class WPJAM_Option_Model{
	protected static function call_method($method, ...$args){
		$object	= self::get_object();

		return $object ? $object->$method(...$args) : null;
	}

	protected static function get_object(){
		return WPJAM_Option_Setting::get(get_called_class(), 'model', 'WPJAM_Option_Model');
	}

	public static function get_setting($name='', $default=null){
		return self::call_method('get_setting', $name) ?? $default;
	}

	public static function update_setting(...$args){
		return self::call_method('update_setting', ...$args);
	}

	public static function delete_setting($name){
		return self::call_method('delete_setting', $name);
	}
}

class WPJAM_Extend extends WPJAM_Args{
	public function load(){
		if(is_callable($this->dir)){
			$this->dir	= call_user_func($this->dir);
		}

		if(!is_dir($this->dir)){
			return;
		}

		if($this->option && is_admin()){
			if($this->sitewide){
				$this->site_default	= true;

				if(is_network_admin()){
					$this->summary	= wpjam_join('，', [$this->summary, '在管理网络激活将整个站点都会激活！']);
				}
			}

			wpjam_register_option($this->option, array_merge($this->to_array(), ['model'=>$this, 'ajax'=>false]));
		}

		foreach($this->get_data() as $extend => $value){
			$file	= $this->parse_file($extend);

			if($file && (is_admin() || !str_ends_with($file, '-admin.php'))){
				include $file;
			}
		}
	}

	private function parse_file($extend){
		if(!$extend || in_array($extend, ['.', '..'])){
			return;
		}

		if($this->hierarchical){
			if(is_dir($this->dir.'/'.$extend)){
				$file	= $extend.'/'.$extend.'.php';
			}
		}else{
			if($this->option){
				$file	= $extend.'.php';
			}else{
				if(pathinfo($extend, PATHINFO_EXTENSION) == 'php'){
					$file	= $extend;
				}
			}
		}

		return (!empty($file) && is_file($this->dir.'/'.$file)) ? $this->dir.'/'.$file : '';
	}

	private function get_data($type=''){
		if($this->option){
			if(!$type){
				$data	= $this->get_data('option');

				if($this->sitewide && is_multisite()){
					$data	= array_merge($data, $this->get_data('site_option'));
				}
			}else{
				$cb		= 'wpjam_get_'.$type;
				$data	= $cb($this->option);
				$data	= $data ? array_filter($data) : [];
				$data	= $this->sanitize_callback($data);
			}
		}else{
			if($handle = opendir($this->dir)){
				while(false !== ($extend = readdir($handle))){
					if(!in_array($extend, ['.', '..'])){
						$data[$extend]	= true;
					}
				}

				closedir($handle);
			}
		}

		return $data ?? [];
	}

	public function get_fields(){
		$fields	= [];
		$values	= $this->get_data('option');

		if(is_multisite() && $this->sitewide){
			$sitewide	= $this->get_data('site_option');

			if(is_network_admin()){
				$values	= $sitewide;
			}
		}

		if($handle = opendir($this->dir)){
			while(false !== ($extend = readdir($handle))){
				if(!$this->hierarchical){
					$extend	= wpjam_remove_postfix($extend, '.php');
				}

				$file	= $this->parse_file($extend);
				$data	= $this->get_file_data($file);

				if($data && ($data['Name'] || $data['PluginName'])){
					if(is_multisite() && $this->sitewide && !is_network_admin()){
						if(!empty($sitewide[$extend])){
							continue;
						}
					}

					$title	= $data['Name'] ?: $data['PluginName'];
					$title	= $data['URI'] ? '<a href="'.$data['URI'].'" target="_blank">'.$title.'</a>' : $title;
					$value	= !empty($values[$extend]);

					$fields[$extend] = ['title'=>$title, 'type'=>'checkbox', 'value'=>$value, 'description'=>$data['Description']];
				}
			}

			closedir($handle);
		}

		return wpjam_sort($fields, ['value'=>'DESC']);
	}

	public function sanitize_callback($data){
		if($data && !$this->hierarchical){
			$update	= false;
			$data	= array_filter($data);
			$keys	= array_keys($data);

			foreach($keys as &$key){
				if(str_ends_with($key, '.php')){
					$key	= wpjam_remove_postfix($key, '.php');
					$update	= true;
				}
			}

			if($update){
				$keys	= array_unique($keys);
				$data	= array_fill_keys($keys, true);
			}
		}

		return $data;
	}

	public static function get_file_data($file){
		return $file ? get_file_data($file, [
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		]) : [];
	}

	public static function get_file_summay($file){
		$data		= self::get_file_data($file);
		$summary	= str_replace('。', '，', $data['Description']);
		$data		= wpjam_fill(['URI', 'Name'], fn($k)=> !empty($data[$k]) ? $data[$k] : ($data['Plugin'.$k] ?? ''));

		return $summary.'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。';
	}

	public static function create($dir, ...$args){
		if(is_array($dir)){
			$args	= $dir;
			$dir	= wpjam_pull($args, 'dir');
		}else{
			$args	= array_shift($args) ?: [];
		}

		$hook	= wpjam_pull($args, 'hook');
		$object	= new self(array_merge($args, ['dir'=>$dir]));

		if($hook){
			wpjam_load($hook, [$object, 'load'], ($object->priority ?? 10));
		}else{
			$object->load();
		}
	}
}

class WPJAM_Notice{
	public static function filter($items){
		return array_filter(($items ?: []), fn($v)=> $v['time']>time() - MONTH_IN_SECONDS*3 && trim($v['notice']));
	}

	public static function render($type=''){
		if(!in_array($type, ['user', 'admin'])){
			self::ajax_delete();
			self::render('user');

			if(current_user_can('manage_options')){
				self::render('admin');
			}
		}else{
			$object	= self::get_instance($type);

			foreach($object->get_items() as $key => $item){
				$item	+= [
					'type'		=> 'info',
					'class'		=> 'is-dismissible',
					'admin_url'	=> '',
					'notice'	=> '',
					'title'		=> '',
					'modal'		=> 0,
				];

				$notice	= trim($item['notice']);

				if($item['admin_url']){
					$notice	.= $item['modal'] ? "\n\n" : ' ';
					$notice	.= '<a style="text-decoration:none;" href="'.add_query_arg(['notice_key'=>$key, 'notice_type'=>$type], home_url($item['admin_url'])).'">点击查看<span class="dashicons dashicons-arrow-right-alt"></span></a>';
				}

				$data	= ['notice_key'=>$key, 'notice_type'=>$type];
				$notice	= wpautop($notice).wpjam_get_page_button('delete_notice', ['data'=>$data]);

				if($item['modal']){
					if(empty($modal)){	// 弹窗每次只显示一条
						$modal	= $notice;
						$title	= $item['title'] ?: '消息';

						echo '<div id="notice_modal" class="hidden" data-title="'.esc_attr($title).'">'.$modal.'</div>';
					}
				}else{
					echo '<div class="notice notice-'.$item['type'].' '.$item['class'].'">'.$notice.'</div>';
				}
			}
		}
	}

	public static function add($item, $type='admin', $id=''){
		if($type == 'admin'){
			if(is_multisite()){
				if($id && !get_site($id)){
					return;
				}
			}else{
				$id	= 0;
			}
		}else{
			if($id && !get_userdata($user_id)){
				return;
			}
		}

		$item	= is_array($item) ? $item : ['notice'=>$item];
		$item	+= ['type'=>'error', 'notice'=>'', 'time'=>time(), 'key'=>md5(serialize($item))];

		return (self::get_instance($type, $id))->insert($item);
	}

	public static function ajax_delete(){
		$type	= wpjam_get_data_parameter('notice_type');
		$key	= wpjam_get_data_parameter('notice_key');

		if($key){
			if($type == 'admin' && !current_user_can('manage_options')){
				wpjam_send_error_json('bad_authentication');
			}

			(self::get_instance($type))->delete($key);

			wpjam_send_json();
		}
	}

	public static function get_instance($type='', $id=0){
		$type	= $type == 'user' ? 'user' : 'admin';
		$id		= (int)$id ?: ($type == 'user' ? get_current_user_id() : get_current_blog_id());
		$name	= 'notice:'.$type.':'.$id;

		if($type == 'user'){
			return wpjam_get_handler($name, [
				'meta_key'		=> 'wpjam_notices',
				'user_id'		=> $id,
				'primary_key'	=> 'key',
				'get_items'		=> fn()=> WPJAM_Notice::filter(get_user_meta($this->user_id, $this->meta_key, true)),
				'delete_items'	=> fn()=> delete_user_meta($this->user_id, $this->meta_key),
				'update_items'	=> fn($items)=> update_user_meta($this->user_id, $this->meta_key, $items),
			]);
		}else{
			return wpjam_get_handler($name, [
				'option_name'	=> 'wpjam_notices',
				'blog_id'		=> $id,
				'primary_key'	=> 'key',
				'get_items'		=> fn()=> WPJAM_Notice::filter(wpjam_call_for_blog($this->blog_id, 'get_option', $this->option_name)),
				'update_items'	=> fn($items)=> wpjam_call_for_blog($this->blog_id, 'update_option', $this->option_name, $items),
			]);
		}
	}

	public static function on_plugins_loaded(){
		wpjam_register_page_action('delete_notice', [
			'button_text'	=> '删除',
			'tag'			=> 'span',
			'class'			=> 'hidden delete-notice',
			'callback'		=> [self::class, 'ajax_delete'],
			'direct'		=> true,
		]);

		add_action('admin_notices', [self::class, 'render']);
	}
}

/**
* @config orderby=order order=ASC
**/
#[config(['orderby'=>'order', 'order'=>'ASC'])]
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		if($key == 'path'){
			return (bool)$this->get_items();
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_by_page_type')){
			$method	= wpjam_remove_postfix($method, '_by_page_type');
			$item	= $this->get_item(array_shift($args));
			$object	= wpjam_get_data_type_object(wpjam_pull($item, 'page_type'), $item);

			return $object ? $object->$method(...$args) : null;
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function verify(){
		return call_user_func($this->verify);
	}

	public function get_tabbar($page_key=''){
		if(!$page_key){
			return wpjam_array($this->get_items(), fn($k, $v)=> ($v = $this->get_tabbar($k)) ? [$k, $v] : null);
		}

		$item	= $this->get_item($page_key);

		if($item && !empty($item['tabbar'])){
			return ($item['tabbar'] === true ? [] : $item['tabbar'])+['text'=>$item['title'] ?? ''];
		}
	}

	public function get_page($page_key){
		$path	= $this->get_item_arg($page_key, 'path');

		return $path ? explode('?', $path)[0] : '';
	}

	public function get_fields($page_key){
		$item	= $this->get_item($page_key);

		if(!$item){
			return [];
		}

		$fields	= $item['fields'] ?? '';
		$fields	= $fields ? (is_callable($fields) ? $fields($item, $page_key) : $fields) : $this->get_path_fields_by_page_type($page_key, $item);

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
		$item	= $this->get_item($page_key);

		if(!$item){
			return;
		}

		$callback	= wpjam_pull($item, 'callback');
		$args		= is_array($args) ? array_filter($args, fn($v)=> !is_null($v))+$item : $args;

		if($callback){
			if(is_callable($callback)){
				return $callback(...[$args, ...(is_array($args) ? [] : [$item]), $page_key]) ?: '';
			}
		}else{
			$path	= $this->get_path_by_page_type($page_key, $args, $item);

			if($path){
				return $path;
			}
		}

		if(isset($item['path'])){
			return (string)$item['path'];
		}
	}

	public function get_paths($page_key, $args=[]){
		$type	= $this->get_item_arg($page_key, 'page_type');
		$args	= array_merge($args, wpjam_slice($this->get_item($page_key), $type));
		$items	= $this->query_items_by_page_type($page_key, $args);

		if($items){
			$paths	= array_map(fn($item)=> $this->get_path($page_key, $item['value']), $items);
			$paths	= array_filter($paths, fn($path)=> $path && !is_wp_error($path));
		}

		return $paths ?? [];
	}

	public function parse_path($args, $postfix=''){
		$page_key	= wpjam_pull($args, 'page_key'.$postfix);

		if($page_key == 'none'){
			return ($video = $args['video'] ?? '') ? ['type'=>'video', 'video'=>wpjam_get_qqv_id($video) ?: $video] : ['type'=>'none'];
		}elseif($this->get_item($page_key)){
			$args	= $postfix ? wpjam_map($this->get_fields($page_key), fn($v, $k)=> $args[$k.$postfix] ?? null) : $args;
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
		$object	= wpjam_find(self::get_by($args), fn($object)=> $object && $object->verify());

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
	use WPJAM_Instance_Trait;

	private $platforms	= [];
	private $cache		= [];

	protected function __construct($platforms){
		$this->platforms	= $platforms;
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	protected function has_path($page_key, $operator='AND', $strict=false){
		$fn	= fn($pf)=> $pf->has_path($page_key, $strict);

		if($operator == 'AND'){
			return wpjam_every($this->platforms, $fn);
		}elseif($operator == 'OR'){
			return wpjam_some($this->platforms, $fn);
		}
	}

	public function get_fields($args, $strict=false){
		$prepend	= wpjam_pull($args, 'prepend_name');
		$postfix	= wpjam_pull($args, 'postfix');
		$title		= wpjam_pull($args, 'title') ?: '页面';
		$key		= 'page_key'.$postfix;
		$backup		= (count($this->platforms) > 1 && !$strict) ? $key.'_backup' : '';
		$paths		= WPJAM_Path::get_by($args);
		$cache_key	= md5(serialize(['postfix'=>$postfix, 'strict'=>$strict, 'page_keys'=>array_keys($paths)]));
		$cache		= $this->cache[$cache_key] ?? [];

		if(!$cache){
			$groups	= WPJAM_Path::get_groups($strict);
			$cache	= ['options'=>$groups];
			$cache	+= $backup ? ['show_if'=>[], 'backup'=>$groups] : [];
			$parser	= fn($path, $postfix)=> [$path->name=> [
				'label'		=> $path->title,
				'fields'	=> wpjam_array($path->get_fields($this->platforms), fn($k, $v)=> [$k.$postfix, wpjam_except($v, 'title')])
			]];

			foreach($paths as $path){
				if($this->has_path($path->name, 'OR', $strict)){
					$group	= $path->group ?: ($path->tabbar ? 'tabbar' : 'others');

					$cache['options'][$group]['options']	+= $parser($path, $postfix);

					if($backup){
						if($this->has_path($path->name, 'AND')){
							$cache['backup'][$group]['options']	+= $parser($path, $postfix.'_backup');
						}else{
							$cache['show_if'][]	= $path->name;
						}
					}
				}
			}

			$this->cache[$cache_key] = $cache;
		}

		$fields	= [$key.'_set'=>['title'=>$title, 'type'=>'fieldset', 'fields'=>[$key=>['options'=>$cache['options']]]]];
		$fields	+= $backup ? [$backup.'_set'=>['title'=>'备用'.$title, 'type'=>'fieldset', 'fields'=>[$backup=>['options'=>$cache['backup']]], 'show_if'=>[$key, 'IN', $cache['show_if']]]] : [];

		return wpjam_map($fields, fn($field)=> $field+['prepend_name'=>$prepend]);
	}

	public function get_current($output='object'){
		if(count($this->platforms) == 1){
			return $output == 'object' ? reset($this->platforms) : reset($this->platforms)->name;
		}else{
			return WPJAM_Platform::get_current(array_keys($this->platforms), $output);
		}
	}

	public function parse_item($item, $postfix=''){
		$platform	= $this->get_current();
		$parsed		= $platform->parse_path($item, $postfix);

		if((!$parsed || is_wp_error($parsed)) && count($this->platforms) > 1){
			$parsed	= $platform->parse_path($item, $postfix.'_backup');
		}

		return ($parsed && !is_wp_error($parsed)) ? $parsed : ['type'=>'none'];
	}

	public function validate_item($item, $postfix='', $title=''){
		foreach($this->platforms as $platform){
			$result	= $platform->parse_path($item, $postfix);

			if(is_wp_error($result) || $result){
				if(!$result && count($this->platforms) > 1 && !str_ends_with($postfix, '_backup')){
					return $this->validate_item($item, $postfix.'_backup', '备用'.$title);
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
			return self::instance(implode('-', array_keys($objects)), fn()=> new self($objects));
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

	public static function get_groups($strict=false){
		$groups	= array_merge(
			['tabbar'=>['title'=>'菜单栏/常用', 'options'=>[]]],
			wpjam_get_items('path_group'),
			['others'=>['title'=>'其他页面', 'options'=>[]]]
		);

		if(!$strict){
			$groups['tabbar']['options']['none']	= '只展示不跳转';
		}

		return $groups;
	}

	public static function create($name, ...$args){
		$object	= self::get($name);
		$object	= $object ?: self::register($name, []);
		$args	= count($args) == 2 ? $args[1]+['platform'=>$args[0]] : $args[0];
		$args	= wp_is_numeric_array($args) ? $args : [$args];

		foreach($args as $_args){
			$pfs	= wpjam_slice($_args, ['platform', 'path_type']);
			$pfs	= array_merge(...array_map('wpjam_array', array_values($pfs)));

			foreach($pfs as $pf){
				$platform	= WPJAM_Platform::get($pf);

				if($platform){
					$page_type	= wpjam_get($_args, 'page_type');

					if($page_type && in_array($page_type, ['post_type', 'taxonomy']) && empty($_args[$page_type])){
						$_args[$page_type]	= $name;
					}

					if(isset($_args['group']) && is_array($_args['group'])){
						$group	= wpjam_pull($_args, 'group');

						if(isset($group['key'], $group['title'])){
							wpjam_add_item('path_group', $group['key'], ['title'=>$group['title'], 'options'=>[]]);

							$_args['group']	= $group['key'];
						}
					}

					$_args	= array_merge($_args, ['platform'=>$pf, 'path_type'=>$pf]);

					$object->update_args($_args, false)->add_item($pf, $_args);

					$platform->add_item($name, $_args);
				}
			}
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($pf){
			$object		= self::get($name);
			$platform	= WPJAM_Platform::get($pf);

			if($object){
				$object->delete_item($pf);
			}

			if($platform){
				$platform->delete_item($name);
			}
		}else{
			self::unregister($name);

			array_walk(WPJAM_Platform::get_registereds(), fn($pf)=> $pf->delete_item($name));
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
		$args	= array_filter($args, fn($v)=> !is_null($v));
		$args	+= ['number'=>10, 'data_type'=>true];

		if($this->query_items){
			return wpjam_catch($this->query_items, $args);
		}elseif($this->model){
			$args	= isset($args['model']) ? wpjam_except($args, ['data_type', 'model', 'label_field', 'id_field']) : $args;
			$result	= wpjam_catch([$this->model, 'query_items'], $args, true);
			$items	= is_wp_error($result) ? $result : (wp_is_numeric_array($result) ? $result : $result['items']);

			if(is_wp_error($items) || !isset($items)){
				return $items ?: new WP_Error('undefined_method', ['query_items', '回调函数']);
			}

			return $this->label_field ? array_map(fn($item)=> [
				'label'	=> is_object($item) ? $item->{$this->label_field}	: $item[$this->label_field],
				'value'	=> is_object($item) ? $item->{$this->id_field}		: $item[$this->id_field]
			], $items) : $items;
		}
	}

	public function query_label($id, $field=null){
		if($this->query_label){
			return $id ? call_user_func($this->query_label, $id, $field) : '';
		}elseif($this->model && $this->label_field){
			if($id){
				$data	= $this->model::get($id);
				$value	= $data ? $data[$this->label_field] : null;

				return $value ?? $id;
			}

			return '';
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

		return ['items'=>is_wp_error($items) ? [] : $items];
	}

	public static function ajax_response($data){
		$name	= $data['data_type'];
		$args	= wpjam_pull($data, 'query_args');
		$object	= self::get_instance($name, $args);
		$items	= $object ? ($object->query_items($args) ?: []) : [];
		$items	= is_wp_error($items) ? [[$items->get_error_message()]] : $items;

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
			$object	= self::get($name);

			if($object){
				$args	= $field->query_args;
				$args	= $args ? wp_parse_args($args) : [];

				if($field->$name){
					$args[$name]	= $field->$name;
				}elseif(!empty($args[$name])){
					$field->$name	= $args[$name];
				}
			}
		}else{
			$object	= self::get($name);
		}

		if($object){
			if($name == 'model'){
				$model	= $args['model'];

				if(!$model || !class_exists($model)){
					return null;
				}

				$sub	= $object->get_sub($model);

				if($sub){
					return $sub;
				}

				$args['meta_type']		= $model::get_meta_type();
				$args['label_field']	??= wpjam_pull($args, 'label_key') ?: 'title';
				$args['id_field']		??= wpjam_pull($args, 'id_key') ?: $model::get_primary_key();

				$object	= $object->register_sub($model, $args);
				$args	= wpjam_except($args, 'meta_type');
			}

			if(isset($field) && $object->model){
				$field->query_args	= $args ?: new StdClass;
			}
		}

		return $object;
	}
}

class WPJAM_Error extends WPJAM_Model{
	public static function get_handler(){
		return wpjam_get_handler('wpjam_errors', [
			'option_name'	=> 'wpjam_errors',
			'primary_key'	=> 'errcode',
			'primary_title'	=> '代码',
		]);
	}

	public static function filter($data){
		$error	= self::get($data['errcode']);

		if($error){
			$data['errmsg']	= $error['errmsg'];

			if(!empty($error['show_modal']) && !empty($error['modal']['title']) && !empty($error['modal']['content'])){
				$data['modal']	= $error['modal'];
			}
		}else{
			if(empty($data['errmsg'])){
				$item	= self::get_setting($data['errcode']);
				$data	= array_merge($data, $item ? wpjam_array(['errmsg'=>'message', 'modal'=>'modal'], fn($k, $v)=>$item[$v] ? [$k, $item[$v]] : null) : []);
			}
		}

		return $data;
	}

	public static function parse($data){
		if(is_wp_error($data)){
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
			if($data === true){
				return ['errcode'=>0];
			}elseif($data === false || is_null($data)){
				return ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
			}elseif(is_array($data)){
				if(!$data || !wp_is_numeric_array($data)){
					$data	+= ['errcode'=>0];
				}
			}
		}

		return empty($data['errcode']) ? $data : self::filter($data);
	}

	public static function if($data, $err=[]){
		$err	+= [
			'errcode'	=> 'errcode',
			'errmsg'	=> 'errmsg',
			'detail'	=> 'detail',
			'success'	=> '0',
		];

		$code	= wpjam_pull($data, $err['errcode']);

		if($code && $code != $err['success']){
			$msg	= wpjam_pull($data, $err['errmsg']);
			$detail	= wpjam_pull($data, $err['detail']);
			$detail	= is_null($detail) ? array_filter($data) : $detail;

			return new WP_Error($code, $msg, $detail);
		}

		return $data;
	}

	public static function convert($message, $title='', $args=[]){
		if(is_wp_error($message)){
			return $message;
		}

		$code	= is_scalar($args) ? $args : '';

		if($code){
			$detail	= $title ? ['modal'=>['title'=>$title, 'content'=>$message]] : [];

			return new WP_Error($code, $message, $detail);
		}

		if($title){
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

		return new WP_Error($code, $message);
	}

	public static function parse_message($code, $message=[]){
		$fn	= fn($map, $key)=> $map[$key] ?? ucwords($key);

		if(str_starts_with($code, 'invalid_')){
			$key	= wpjam_remove_prefix($code, 'invalid_');

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
		}elseif(str_starts_with($code, 'illegal_')){
			$key	= wpjam_remove_prefix($code, 'illegal_');

			return $fn([
				'access_token'	=> 'Access Token ',
				'refresh_token'	=> 'Refresh Token ',
				'verify_code'	=> '验证码',
			], $key).'无效或已过期。';
		}elseif(str_ends_with($code, '_required')){
			$key	= wpjam_remove_postfix($code, '_required');
			$format	= $key == 'parameter' ? '参数%s' : '%s的值';

			return $message ? sprintf($format.'为空或无效。', ...$message) : '参数或者值无效';
		}elseif(str_ends_with($code, '_occupied')){
			$key	= wpjam_remove_postfix($code, '_occupied');

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

	public static function add_setting($code, $message, $modal=[]){
		if(!wpjam_get_items('error')){
			add_action('wp_error_added', [self::class, 'on_wp_error_added'], 10, 4);
		}

		if($message){
			wpjam_add_item('error', $code, ['message'=>$message, 'modal'=>$modal]);
		}
	}

	public static function on_wp_error_added($code, $message, $data, $wp_error){
		if($code && (!$message || is_array($message)) && count($wp_error->get_error_messages($code)) <= 1){
			if(is_array($code)){
				trigger_error(var_export($code, true));
			}
			
			$item	= self::get_setting($code);

			if($item){
				if($item['modal']){
					$data	= is_array($data) ? $data : [];
					$data	= array_merge($data, ['modal'=>$item['modal']]);
				}

				if(is_callable($item['message'])){
					$parsed	= $item['message']($message, $code);
				}else{
					$parsed	= is_array($message) ? sprintf($item['message'], ...$message) : $item['message'];
				}
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
		}else{
			$errcode	= $errcode ?: 'error';
		}

		$this->errcode	= $errcode;

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