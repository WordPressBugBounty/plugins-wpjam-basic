<?php
class WPJAM_API{
	private $data	= [];

	private function __construct(){
		wpjam_hooks([
			['plugins_loaded',	[$this, 'loaded'], 0],
			['parse_request',	[$this, 'dispatch'], 1],
			['wp_error_added',	[$this, 'error'], 10, 4],
			['request',			'wpjam_parse_query_vars', 11],
			['query_vars', '+', ['module', 'action'], 11],
			['gettext, gettext_with_context',	[$this, 'translate'], 10, 4]
		]);

		wpjam_is_json_request() && wpjam_hooks('-', [
			['the_title',		'convert_chars'],
			['init',			['wp_widgets_init', 'maybe_add_existing_user_to_blog']],
			['wp_loaded',		['_custom_header_background_just_in_time', '_add_template_loader_filters']],
			['plugins_loaded',	['wp_maybe_load_widgets', 'wp_maybe_load_embeds', '_wp_customize_include', '_wp_theme_json_webfonts_handler']]
		]);
	}

	public function __invoke($field, ...$args){
		if(!$field){
			return $this;
		}

		if(method_exists($this, $field)){
			return $this->$field(...$args);
		}

		if(try_remove_suffix($field, '[]')){
			$method	= $args ? (count($args) <= 2 && is_null(array_last($args)) ? 'delete' : 'add') : 'get';
		}else{
			$method	= $args && (count($args) > 1 || is_array($args[0])) ? 'set' : 'get';
		}

		return $this->$method($field, ...$args);
	}

	public function loaded(){
		wpjam_activation();

		is_admin() && wpjam_admin();

		wpjam_register_bind('phone', '', ['domain'=>'@phone.sms']);

		wpjam_load_extends(get_template_directory().'/extends');

		wpjam_load_extends(dirname(__DIR__).'/extends', [
			'option'	=> 'wpjam-extends',
			'sitewide'	=> true,
			'title'		=> '扩展管理',
			'menu_page'	=> ['parent'=>'wpjam-basic', 'order'=>3, 'function'=>'tab', 'tabs'=>['extends'=>['order'=>20, 'title'=>'扩展管理', 'function'=>'option']]]
		]);

		wpjam_style('remixicon', [
			'src'		=> wpjam_get_static_cdn().'/remixicon/4.2.0/remixicon.min.css',
			'method'	=> is_admin() ? 'enqueue' : 'register',
			'priority'	=> 1
		]);

		wpjam_style('wpjam-static', ['src'=>'', 'method'=>'register', 'priority'=>1]);

		add_action('loop_start',	fn($query)=> $this->push('query', $query), 1);
		add_action('loop_end',		fn()=> $this->pop('query'), 999);

		wpjam_hook('pre_do_shortcode_tag',	'tap', fn($tag)=> $this->push('shortcode', $tag), 1, 2);
		wpjam_hook('do_shortcode_tag',		'tap', fn($tag)=> $this->pop('shortcode'), 999, 2);

		wpjam_hooks('register_post_type_args, register_taxonomy_args', function($args, $name, $object_type=null){
			if(empty($args['_jam']) && (did_action('init') || empty($args['_builtin']))){
				return ('wpjam_'.wpjam_remove_suffix(current_filter(), '_args'))($name, ['_jam'=>false]+array_filter(compact('object_type'))+$args)->to_array();
			}

			return $args;
		}, 999, 3);
	}

	public function add($field, $key, ...$args){
		[$key, $item]	= $args ? [$key, $args[0]] : [null, $key];

		if(isset($key) && !str_ends_with($key, '[]') && $this->get($field, $key) !== null){
			return new WP_Error('invalid_key', '「'.$key.'」已存在，无法添加');
		}

		return $this->set($field, $key, $item);
	}

	public function set($field, $key, ...$args){
		$this->data[$field]	= is_array($key) ? array_merge(($args && $args[0]) ? $this->get($field) : [], $key) : wpjam_set($this->get($field), $key ?? '[]', ...$args);

		return is_array($key) ? $key : $args[0];
	}

	public function delete($field, ...$args){
		if($args){
			return $this->data[$field] = wpjam_except($this->get($field), $args[0]);
		}

		unset($this->data[$field]);
	}

	public function push($field, $item){
		$this->data[$field]	??= [];

		return array_push($this->data[$field], $item);
	}

	public function pop($field){
		return $this->get($field) ? array_pop($this->data[$field]) : null;
	}

	public function get($field, ...$args){
		return $args ? wpjam_get($this->get($field), ...$args) : ($this->data[$field] ?? []);
	}

	public function route($module, $args, $query_var=false){
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

		if($query_var && ($action = $this->param(wp_doing_ajax() ? 'data' : 'get', $module))){
			add_action((wp_doing_ajax() ? 'admin_init' : 'parse_request'), fn()=> $this->dispatch($module, $action), 0);
		}

		return $this->set('route', $module, $args+['query_var'=>$query_var]);
	}

	public function dispatch($module, $action=''){
		if(is_object($module)){
			$vars	= $module->query_vars;
			$module	= $vars['module'] ?? '';
			$action	= $vars['action'] ?? '';

			if(!$module){
				return;
			}

			remove_action('template_redirect', 'redirect_canonical');
		}

		if($item = $this->get('route', $module)){
			$item['query_var'] && $GLOBALS['wp']->set_query_var($module, $action);

			wpjam_call($item['callback'], $action, $module);
		}

		if(!is_admin()){
			$file	= ($item ?: [])['file'] ?? '';
			$file	= $file ?: apply_filters('wpjam_template', STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php', $module, $action);

			is_file($file) && wpjam_hook('template_include', $file);
		}
	}

	// $name, $data
	// $data
	// $name, $cb
	// $cb
	public function config(...$args){
		if($args && (count($args) > 1 || is_array($args[0]) || is_callable($args[0]))){
			$group	= (count($args) >= 3 ? array_shift($args) : '') ?: 'default';
			$args	= array_values(array_filter($args, fn($v)=> isset($v)));

			return $args ? $this->set('config', $group.'['.(count($args) > 1 ? array_shift($args) : '').']', ...$args) : null;
		}

		return wpjam_reduce($this->get('config', ($args[0] ?? '') ?: 'default') ?: [], function($c, $v, $k){
			$v	= maybe_callback($v);
			$v	= is_numeric($k) ? (is_array($v) ? $v : []) : [$k=>$v];

			return array_merge($c, $v);
		}, []);
	}

	public function ajax($name, $args=null){
		if(!$args || empty($args['callback'])){
			$ajax	= $this->get('ajax', $name);

			if(is_null($args)){
				return $ajax;
			}

			$cb	= $ajax['nonce_action'] ?? '';

			return $cb ? $cb($args) : (empty($ajax['admin']) ? $name.wpjam_join(':', wpjam_pick($args, $ajax['nonce_keys'] ?? [])) : '');
		}

		$hook	= 'wp_ajax_'.(is_user_logged_in() ? '' : 'nopriv_').$name;

		if(doing_action($hook)){
			add_filter('wp_die_ajax_handler', fn()=> 'wpjam_die_handler');

			$data	= wpjam_params('post');

			if(empty($args['admin'])){
				$data	= array_merge(wpjam_params('data'), wpjam_except($data, ['action', 'defaults', 'data', '_ajax_nonce']));
			}

			if($fields = $args['fields'] ?? ''){
				$data	= array_merge($data, wpjam_fields($fields)->validate($data, 'parameter'));
			}

			if($action = ($args['verify'] ?? true) !== false ? $this->ajax($name, $data) : ''){
				check_ajax_referer($action, false, false)  || wp_die('验证失败，请刷新重试。', 'invalid_nonce');
			}

			if($allow = $args['allow'] ?? ''){
				wpjam_call($allow, $data) || wp_die('access_denied');
			}

			return wpjam_send_json(wpjam_catch($args['callback'], $data, $name));
		}

		if(!$this->get('ajax')){
			wpjam_script('wpjam-ajax', [
				'for'		=> 'wp,login',
				'src'		=> wpjam_url(dirname(__DIR__).'/static/ajax.js'),
				'deps'		=> ['jquery'],
				'data'		=> 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";',
				'position'	=> 'before',
				'priority'	=> 1
			]);

			if(!is_login()){
				wpjam_hook('script_loader_src', fn($src, $handle)=> $handle == 'wpjam-ajax' && current_theme_supports('script', $handle) ? '' : $src, 10, 2);
			}
		}

		if(wp_doing_ajax() && $this->param('request', 'action') == $name && (is_user_logged_in() || !empty($args['nopriv']))){
			add_action($hook, fn()=> $this->ajax($name, $args));
		}

		return $this->set('ajax', $name, $args);
	}

	public function param($type, $name=''){
		$type	= strtolower($type ?: 'get');

		if($type == 'data'){
			if($name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}
		}elseif($type != 'defaults'){
			$data	= ['post'=>$_POST, 'request'=>$_REQUEST][$type] ?? $_GET;

			if($name){
				if(isset($data[$name])){
					return wp_unslash($data[$name]);
				}

				if($_POST || $type == 'get'){
					return null;
				}
			}else{
				if($data || $type != 'post'){
					return wp_unslash($data);
				}
			}

			$type	= 'input';
		}

		$data	= $this->get('params', $type);

		if(is_null($data)){
			if($type == 'input'){
				$v		= file_get_contents('php://input');
				$v		= $v && is_string($v) ? @wpjam_json_decode($v) : $v;
				$data	= is_array($v) ? $v : [];
			}else{
				foreach(array_unique(['defaults', $type]) as $t){
					$v		= $this->param('request', $t) ?? [];
					$v		= $v && is_string($v) && str_starts_with($v, '{') ? wpjam_json_decode($v) : wp_parse_args($v ?: []);
					$data	= wpjam_merge($data ?? [], $v);
				}
			}

			$this->set('params', $type, $data);
		}

		return $name ? wpjam_get($data, $name) : $data;
	}

	public function error($code, $msg='', ...$args){
		if($doing = doing_action('wp_error_added')){
			[$data, $error]	= $args;

			if(!$code || ($msg && !is_array($msg)) || count($error->get_error_messages($code)) > 1){
				return;
			}
		}else{
			if(!$code){
				return $this->get('error[]');
			}

			if(($msg && !is_array($msg)) || $args){
				return $this->set('error', $code, ['errmsg'=>$msg, 'modal'=>$args[0] ?? []]);
			}
		}

		$args	= $msg ?: [];
		$err	= $code;

		if($item = $this->get('error', $err) ?: []){
			$msg	= maybe_closure($item['errmsg'], $args);
		}elseif(try_remove_prefix($err, 'invalid_')){
			$err	= $err == 'id' ? 'ID' : str_replace(['_id', '_'], [' ID', ' '], $err);
			$msg	= 'Invalid '.$err.($args ? ': %s.' : '.');

			if(did_action('init')){
				if(($trans = __($msg, 'wpjam')) != $msg){
					$msg	= $trans;
				}else{
					$msg	= __('Invalid %s'.($args ? ': %s.': '.'), 'wpjam');
					$args	= [__($err, 'wpjam'), ...$args];
				}
			}
		}elseif(try_remove_suffix($err, '_required')){
			$msg	= __('%s is empty or invalid.', 'wpjam');
			$trans	= __($args && $err == 'value' ? '%s\'s value' :  $err, 'wpjam');
			$args	= [$args ? sprintf($trans.($err == 'value' ? '' : '%s'), ...$args) : $trans];
		}elseif(try_remove_suffix($err, '_occupied')){
			$msg	= __('%s is already in use by another account.', 'wpjam');
			$args	= [__($err, 'wpjam')];
		}else{
			$msg	= str_replace('_', ' ', $err);
		}

		if(!$msg){
			return [];
		}

		if($args && str_contains($msg, '%')){
			$msg	= sprintf($msg, ...$args);
		}

		if(!$doing){
			return ['errcode'=>$code, 'errmsg'=>$msg]+$item;
		}

		$error->remove($code);
		$error->add($code, $msg, empty($item['modal']) ? $data : array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]));
	}

	public function cap($cap, $map){
		$this->get('cap') || add_filter('map_meta_cap', function($caps, $cap, $user_id, $args){
			foreach((!in_array('do_not_allow', $caps) && $user_id) ? ($this->get('cap', $cap) ?: []) : [] as $v){
				$v = maybe_callback($v, $user_id, $args, $cap);

				if($v || is_array($v)){
					$caps	= (array)$v;
				}
			}

			return $caps;
		}, 10, 4);

		$this->add('cap', $cap.'[]', $map);
	}

	public function updater($type, $url, $file, $data=[]){
		try{
			$result	= $this->get('updater', $type.'['.$url.']') ?? $this->set('updater', $type.'['.$url.']', $this->request($url));
			$result	= is_array($result) ? ($result['template']['table'] ?? $result[$type.'s']) : [];

			if(isset($result['fields']) && isset($result['content'])){
				$fields	= array_column($result['fields'], 'index', 'title');
				$item	= array_find($result['content'], fn($item)=> $item['i'.$fields[$type == 'plugin' ? '插件' : '主题']] == $file);
				$item	= $item ? array_map(fn($i)=> $item['i'.$i] ?? '', $fields) : [];
				$item	= $item ? [$type=>$file, 'icons'=>[], 'banners'=>[], 'banners_rtl'=>[]]+array_map(fn($v)=> $item[$v], ['url'=>'更新地址', 'package'=>'下载地址', 'new_version'=>'版本', 'requires_php'=>'PHP最低版本', 'requires'=>'最低要求版本', 'tested'=>'最新测试版本']) : [];
			}else{
				$item	= array_find($result ?: [], fn($item)=> $item[$type] == $file);
			}

			return $item ? $item+($data ? ['id'=>$data['UpdateURI'], 'version'=>$data['Version']] : []) : [];
		}catch(Exception $e){
			return [];
		}
	}

	public function translate($text, ...$args){
		$domain	= array_pop($args);

		if($domain == 'default'){
			return $text;
		}

		if(str_starts_with(current_filter(), 'gettext')){
			if($text !== $args[0]){
				return $text;
			}

			$text	= array_shift($args);
		}

		$cb	= 'translate'.($args ? '_with_gettext_context' : '');

		return ($domain === 'wpjam'
			&& ($low = strtolower($text)) !== $text
			&& count(explode(' ', $text)) <= 2
			&& ($trans = $cb($low, ...$args))
			&& $trans !== $low) ? $trans : $cb($text, ...$args);
	}

	public function request($url, $args=[], $err=[]){
		$args	+= ['body'=>[], 'sslverify'=>false];
		$field	= wpjam_pull($args, 'field') ?? 'body';
		$method	= strtoupper($args['method'] ?? '') ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'FILE'){
			wpjam_once('pre_http_request', fn($pre, $args, $url)=> (new WP_Http_Curl())->request($url, $args), 1, 3);

			$method = $args['body'] ? 'POST' : 'GET';
		}elseif($method != 'GET'){
			$key	= 'content-type';
			$type	= 'application/json';

			$args['headers']	= array_change_key_case($args['headers'] ?? []);

			if(array_first(wpjam_pull($args, ['json_encode', 'need_json_encode']))){
				$args['headers'][$key]	= $type;
			}

			if(str_contains($args['headers'][$key] ?? '', $type) && is_array($args['body'])){
				$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
			}
		}

		$result	= wpjam_try('wp_safe_remote_request', $url, ['method'=>$method]+$args);
		$code	= $result['response']['code'];
		$body	= &$result['body'];

		if(!wpjam_compare($code, 'between', [200, 299])){
			wpjam_throw($code, $code.' - '.$result['response']['message'].'-'.var_export($body, true));
		}

		if($body && empty($args['stream'])){
			if(str_contains(wp_remote_retrieve_header($result, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
				$res	= wpjam_json_decode($body);

				if(!is_wp_error($res)){
					$body	= $res;
					$err	+= ['success'=>'0']+wpjam_fill(['errcode', 'errmsg', 'detail'], fn($v)=> $v);

					if(($code = wpjam_pull($body, $err['errcode'])) && $code != $err['success']){
						wpjam_throw($code, wpjam_pull($body, $err['errmsg']), wpjam_pull($body, $err['detail']) ?? array_filter($body));
					}else{
						if(strtolower($body[$err['errmsg']] ?? '') == 'ok'){
							unset($body[$err['errmsg']]);
						}
					}
				}
			}
		}

		return $field ? wpjam_get($result, $field) : $result;
	}

	public function enqueue($type, $handle, $args=[]){
		$args	= is_array($args) ? $args : ['src'=>$args];
		$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
		$parts	= isset($args['for']) ? array_intersect($parts, wp_parse_list($args['for'] ?: 'wp')) : $parts;

		if(array_any($parts, fn($part)=> did_action($part.'_enqueue_scripts'))){
			$method	= ($args['method'] ?? '') ?: 'enqueue';
			$if		= $args[$method.'_if'] ?? '';

			if(!$if || $if($handle, $type)){
				$src	= maybe_closure($args['src'] ?? '', $handle);
				$data	= $args['data'] ?? '';

				($src || !$data) && wpjam_call('wp_'.$method.'_'.$type, $handle, $src, ($args['deps'] ?? []), ($args['ver'] ?? false), ($type == 'script' ? wpjam_pick($args, ['in_footer', 'strategy']) : ($args['media'] ?? 'all')));

				$data && wpjam_call('wp_add_inline_'.$type, $handle, $data, $args['position'] ?? 'after');
			}
		}else{
			wpjam_load('any', array_map(fn($p)=> $p.'_enqueue_scripts', $parts), fn()=> $this->enqueue($type, $handle, $args), ($args['priority'] ?? 10));
		}
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}

	public static function __callStatic($method, $args){
		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}
}

class WPJAM_Callback{
	private $reflections	= [];
	private $annotations	= [];

	public function __invoke($cb, ...$args){
		if(is_null($cb)){
			return $this;
		}

		if(is_string($cb)){
			if(in_array($cb, ['', 'try', 'catch', 'method'], true)){
				return $this->call($cb, ...$args);
			}

			if(method_exists($this, $cb)){
				return $this->$cb(...$args);
			}
		}

		return $this->parse($args ? 'try' : '', $cb, ...$args);
	}

	public function call($type, $cb, ...$args){
		$res	= $this->parse($type, $cb, $args);

		if(is_array($res)){
			[$cb, $args]	= $res;

			return $cb(...$args);
		}

		return $res;
	}

	public function parse($type, $cb, ...$args){
		if(is_string($cb) && preg_match('/::|->/', $cb, $m)){
			$sep	= $m[0];
			$static	= $sep == '::';
			$cb		= explode($sep, $cb, 2);
		}elseif(!is_array($cb)){
			if($type == 'method'){
				$cb	= [$cb, array_shift($args[0])];
			}
		}

		if(is_array($cb)){
			if(!$cb[0] || (is_string($cb[0]) && !class_exists($cb[0]))){
				return $this->invalid($type, $cb[0], 'model');
			}

			$exists	= $cb[1] && method_exists(...$cb);

			if($type == 'method' && !$exists){
				return;
			}
		}else{
			$exists	= $cb && is_callable($cb);
		}

		if(!$args){
			return $exists ? $cb : null;
		}

		$args	= $args[0];

		if(is_array($cb) && is_string($cb[0])){
			$sep	??= '::';
			$static	??= $exists ? $this->reflection($cb, 'isStatic') : ($cb[1] ? method_exists($cb[0], '__callStatic') : null);

			if(is_null($static) || (!$exists && !method_exists($cb[0], '__call'.($static ? 'Static' : '')))){
				return $this->invalid($type, implode($sep, $cb));
			}

			if(!$static){
				if($cb[1] == 'value_callback' && count($args) == 2){
					$args	= array_reverse($args);
				}

				$ins	= [$cb[0], 'get_instance'];
				$num	= $this->reflection($ins, 'NumberOfRequiredParameters');

				if(is_null($num) || count($args) < $num){
					return $this->invalid($type, implode($sep, $cb));
				}

				$cb[0]	= $ins(...array_splice($args, 0, $num ?: 1));

				if(!$cb[0]){
					return $this->invalid($type, $cb[0], 'id');
				}
			}

			$cb = ($public ?? true) ? $cb : $this->reflection($cb, 'Closure')($static ? null : $cb[0]);
		}else{
			if(!is_callable($cb)){
				return $this->invalid($type, $cb);
			}
		}

		return [$cb, $args];
	}

	private function invalid($type, $msg, $code='callback'){
		if(in_array($type, ['try', 'catch'])){
			$code	= 'invalid_'.$code;

			return $type == 'catch' ? new WP_Error($code, [$msg]) : wpjam_throw($code, [$msg]);
		}
	}

	public function value_callback($args, $name){
		$path	= (array)$name;
		$name	= array_shift($path);
		$id		= $args['id'] ?? null;
		$cb		= $args['value_callback'] ?? '';
		$mt 	= $args['meta_type'] ?? '';

		if(!$cb && ($model = $args['model'] ?? '')){
			if($id && $path && $name == 'meta_input' && ($mt = $mt ?: $this->call('method', $model.'::get_meta_type'))){
				$name	= array_shift($path);
			}else{
				$cb		= $this->parse('', [$model, 'value_callback']);
			}
		}

		foreach([$cb, $mt] as $i => $v){
			if($v){
				$value	= $i === 0 ? $this->call('', $v, $name, $id) : ($id ? wpjam_get_metadata($v, $id, $name) : null);
				$value	= wpjam_if_error($value, true);
				$value	= isset($value) && $path ? wpjam_get($value, $path) : $value;

				if(isset($value)){
					return $value;
				}
			}
		}
	}

	public function render($cb){
		return wpautop(is_array($cb) ? (is_object($cb[0]) ? get_class($cb[0]).'->' : $cb[0].'::').(string)$cb[1] : (is_object($cb) ? get_class($cb) : $cb));
	}

	public function uniqid($cb){
		return _wp_filter_build_unique_id(null, $cb, null);
	}

	public function reflection($cb, ...$args){
		if($cb === 'class'){
			$type	= 'class';
			$class	= array_shift($args);
			$key	= class_exists($class) ? strtolower($class) : null;
		}else{
			$type	= 'cb';
			$cb		= $this->parse('try', is_array($cb) && !is_string($cb[0]) ? [get_class($cb[0]), $cb[1]] : $cb);
			$key	= $cb ? $this->uniqid($cb) : null;
		}

		if($key){
			$param	= $args ? array_shift($args) : '';
			$ref	= $this->reflections[$type][$key] ??= $type == 'class' ? new ReflectionClass($class) : (is_array($cb) ? new ReflectionMethod(...$cb) : new ReflectionFunction($cb));

			return $param ? [$ref, (array_find(['get', 'is', 'has', 'in'], fn($v)=> str_starts_with($param, $v)) ? '' : 'get').$param](...$args) : $ref;
		}
	}

	public function annotation($class, $key=''){
		$data	= $this->annotations[strtolower($class)] ??= (function($ref){
			if(method_exists($ref, 'getAttributes')){
				foreach($ref->getAttributes() as $attr){
					$k	= $attr->getName();
					$v	= $attr->getArguments();
					$v	= ($v && wp_is_numeric_array($v) && ($k == 'config' ? is_array($v[0]) : count($v) == 1)) ? $v[0] : $v;

					$data[$k]	= $v ?: null;
				}
			}elseif(preg_match_all('/@([a-z0-9_]+)\s+([^\r\n]*)/i', ($ref->getDocComment() ?: ''), $matches, PREG_SET_ORDER)){
				foreach($matches as $m){
					$k	= $m[1];
					$v	= trim($m[2]) ?: null;

					$data[$k]	= ($v && $k == 'config') ? wp_parse_list($v) : $v;
				}
			}

			$data['config'] = wpjam_array($data['config'] ?? [], fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v, 2) : [$v, true]) : [$k, $v]);

			return $data;
		})($this->reflection('class', $class));

		return wpjam_get($data, $key ?: null);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_Query{
	private $type;

	private function __construct($type){
		$this->type	= $type;
	}

	public function is($query, ...$args){
		if($query->is_main_query()){
			if($args){
				return array_any(wp_parse_list(array_shift($args)), fn($t)=> [$query, 'is_'.$t](...$args));
			}

			return $query->is_front_page() ? 'home' : (array_find(['feed', 'author', 'category', 'tag', 'tax', 'post_type_archive', 'search', 'date', 'archive', '404', 'page', 'single', 'attachment'], fn($t)=> [$query, 'is_'.$t]()) ?: true);
		}
	}

	public function query($vars, &$args=[]){
		if(!empty($vars['related_query'])){
			$post	= get_post(wpjam_pull($vars, 'post') ?? get_the_ID());
			$type	= get_post_type($post);
			$tt_ids	= [];

			foreach($post ? get_object_taxonomies($type) : [] as $tax){
				if($terms = $tax == 'post_format' ? [] : get_the_terms($post, $tax)){
					$type	= array_merge((array)$type, get_taxonomy($tax)->object_type);
					$tt_ids	= array_merge($tt_ids, array_column($terms, 'term_taxonomy_id'));
				}
			}

			if(!$tt_ids){
				return false;
			}

			$vars	+= ['post_status'=>'publish', 'post__not_in'=>[$post->ID], 'post_type'=>array_unique($type), 'orderby'=>'related', 'term_taxonomy_ids'=>wpjam_filter($tt_ids, 'unique')];
		}

		$vars	= $this->parse_vars($vars);

		if($args){
			$vars	= array_filter(['posts_per_page'=>wpjam_pull($args, 'number')])+$vars;
			$vars	= wpjam_pull($args, ['post_type', 'orderby', 'posts_per_page'])+$vars;
			$vars	= ($days = wpjam_pull($args, 'days')) ? wpjam_set($vars, 'date_query[]', [
				'column'	=> wpjam_pull($args, 'column') ?: 'post_date_gmt',
				'after'		=> wpjam_date('Y-m-d', time()-DAY_IN_SECONDS*$days).' 00:00:00'
			]) : $vars;
		}

		return new WP_Query($vars+['no_found_rows'=>true, 'ignore_sticky_posts'=>true]);
	}

	public function parse($vars, $args=[]){
		$format	= wpjam_pull($args, 'format');
		$parse	= wpjam_pull($args, 'parse');

		if(is_null($parse)){
			$parse	= true;
			$args	+= ['options_required'=>false, 'taxonomy_required'=>false];
		}

		if($this->type == 'post'){
			$query	= is_object($vars) ? $vars : $this->query($vars, $args);

			if(!$query || !$parse){
				return $query ? $query->posts : [];
			}

			$parsed	= [];
			$args	+= ['thumbnail_size'=>wpjam_pull($args, 'size')];
			$args	+= $query->get('related_query') ? ['filter'=>'wpjam_related_post_json'] : [];

			while($query->have_posts()){
				$query->the_post();

				if($item = wpjam_get_post(get_the_ID(), $args+['query'=>$query])){
					$parsed	= wpjam_set($parsed, ($format == 'date' ? wpjam_at($item['date'], ' ', 0) : '').'[]', $item);
				}
			}

			if(!$query->is_main_query()){
				wp_reset_postdata();
			}

			return $parsed;
		}elseif($this->type == 'term'){
			$object	= ($tax = $vars['taxonomy'] ?? '') && is_string($tax) ? wpjam_get_taxonomy($tax) : null;
			$terms	= $vars['terms'] ?? null;
			$depth	= $args['depth'] ?? ($object ? $object->max_depth : null);

			if($depth != -1 && $object && $object->hierarchical){
				$nest	= ['max_depth'=>($depth ?? (int)$object->levels), 'format'=>$format];
				$nest	+= $parse ? ['item_callback'=>'wpjam_get_term'] : ['fields'=>['id'=>'term_id']];
				$parent	= (int)wpjam_pull($vars, 'parent');

				if($parent){
					$nest['top']	= get_term($parent);

					if(!$nest['top']){
						return [];
					}
				}

				if($terms){
					$ids	= array_column($terms, 'term_id');
					$terms	= WPJAM_Term::get_by_ids(array_unique(array_reduce($ids, fn($c, $id)=> array_merge($c, get_ancestors($id, $tax, 'taxonomy')), $ids)));
				}
			}

			$terms	??= get_terms($vars+['hide_empty'=>false]);

			if(!$terms || is_wp_error($terms)){
				return $terms ?: [];
			}

			return isset($nest) ? wpjam_nest($terms, $nest) : ($parse ? array_values(array_map('wpjam_get_term', $terms)) : $terms);
		}
	}

	public function parse_vars($vars, $param=false){
		if(($type = $vars['post_type'] ?? '') && is_string($type) && str_contains($type, ',')){
			$vars['post_type'] = wp_parse_list($type);
		}

		if($param == 'calendar'){
			$args	= ['year'=>['year', 'Y'], 'monthnum'=>['month', 'm'], 'day'=>['day', '']];
			$vars	+= wpjam_map($args, fn($v)=> (int)wpjam_param($v[0]) ?: ($v[1] ? wpjam_date($v[1]) : null));
		}elseif($param){
			$number	= wpjam_find(['number', 'posts_per_page'], fn($v)=> $v, fn($k)=> ($v = (int)wpjam_param($k)) > 0 ? $v : 0);
			$ids	= wpjam_param('post__in');
			$vars	+= array_filter(['offset'=>wpjam_param('offset'), 'posts_per_page'=>min($number, 100)]);
			$vars	+= $ids ? ['include'=>$ids,	'orderby'=>'post__in'] : [];
		}

		foreach(get_taxonomies([], 'objects') as $tax => $object){
			if(in_array($tax, ['category', 'post_tag']) || !$object->_builtin){
				$qk		= wpjam_get_taxonomy_query_key($tax);
				$keys	= $tax == 'category' ? ['category_id', 'cat_id'] : [$qk];

				if($value = ($param ? array_reduce($keys, fn($c, $k)=> (int)wpjam_param($k) ?: $c, 0) : 0) ?: wpjam_pull($vars, $qk)){
					$term_ids[$tax]	= $value;
				}
			}
		}

		if(!empty($vars['taxonomy']) && empty($vars['term']) && ($value = wpjam_pull($vars, 'term_id'))){
			if(is_numeric($value)){
				$term_ids[wpjam_pull($vars, 'taxonomy')]	= $value;
			}else{
				$vars['term']	= $value;
			}
		}

		foreach(array_filter($term_ids ?? []) as $tax => $value){
			if($tax == 'category' && $value != 'none'){
				$vars['cat']	= $value;
			}else{
				$vars['tax_query'][]	= ['taxonomy'=>$tax, 'field'=>'term_id']+($value == 'none' ? ['operator'=>'NOT EXISTS'] : ['terms'=>[$value]]);
			}
		}

		foreach(wpjam_pull($vars, ['include', 'exclude']) as $k => $v){
			if($ids = wp_parse_id_list($v)){
				$vars[$k == 'include' ? 'post__in' : 'post__not_in']	= $ids;

				$k == 'include' && ($vars['posts_per_page']	= count($ids));
			}
		}

		foreach(['cursor'=>'before', 'since'=>'after'] as $k => $v){
			if($value = (int)(($param ? wpjam_param($k) : 0) ?: wpjam_pull($vars, $k))){
				$vars['date_query'][]	= [$v => wpjam_date('Y-m-d H:i:s', $value)];

				$vars['ignore_sticky_posts']	= true;
			}
		}

		return $vars;
	}

	public function render($vars, $args=[]){
		$cb		= wpjam_fill(['item_callback', 'wrap_callback'], fn($k)=> $args[$k] ?? [$this, $k]);
		$query	= is_object($vars) ? $vars : $this->query($vars, $args);
		$args	+= ['query'=>$query];

		return $query ? $cb['wrap_callback'](implode(wpjam_map($query->posts, fn($p, $i)=> $cb['item_callback']($p->ID, $args+['i'=>$i]))), $args) : '';
	}

	public function filter_clauses($clauses, $query){
		$wpdb		= $GLOBALS['wpdb'];
		$orderby	= $query->get('orderby');
		$order		= strtoupper($query->get('order')) == 'ASC' ? 'ASC' : 'DESC';;

		if($orderby == 'related'){
			if($tt_ids = array_filter(wp_parse_id_list($query->get('term_taxonomy_ids')))){
				$clauses['join']	.= "INNER JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
				$clauses['where']	.= " AND tr.term_taxonomy_id IN (".implode(",", $tt_ids).")";
				$clauses['groupby']	.= " tr.object_id";
				$clauses['orderby']	= " count(tr.object_id) DESC, {$wpdb->posts}.ID DESC";
			}
		}elseif($orderby == 'comment_date'){
			$ct		= $query->get('comment_type') ?: 'comment';
			$str	= $ct == 'comment' ? "'comment', ''" : "'".esc_sql($ct)."'";
			$where	= "ct.comment_type IN ({$str}) AND ct.comment_parent=0 AND ct.comment_approved NOT IN ('spam', 'trash', 'post-trashed')";

			$clauses['join']	= "INNER JOIN {$wpdb->comments} AS ct ON {$wpdb->posts}.ID = ct.comment_post_ID AND {$where}";
			$clauses['groupby']	= "ct.comment_post_ID";
			$clauses['orderby']	= "MAX(ct.comment_ID) {$order}";
		}elseif(in_array($orderby, ['views', 'comment_type'])){
			$clauses['join']	.= $wpdb->prepare("LEFT JOIN {$wpdb->postmeta} jam_pm ON {$wpdb->posts}.ID = jam_pm.post_id AND jam_pm.meta_key = %s ", sanitize_key($orderby == 'comment_type' ? $query->get('comment_count') : 'views'));
			$clauses['orderby']	= "(COALESCE(jam_pm.meta_value, 0)+0) {$order}, " . $clauses['orderby'];
			$clauses['groupby']	= "{$wpdb->posts}.ID";
		}elseif(in_array($orderby, ['', 'date', 'post_date'])){
			$clauses['orderby']	.= ", {$wpdb->posts}.ID {$order}";
		}

		return $clauses;	
	}

	public function filter_results($posts, $query){
		$q	= &$query->query_vars;

		$sticky_posts	= array_diff(wp_parse_id_list(wpjam_pull($q, 'sticky_posts') ?: []), $q['post__not_in']);
		
		if($sticky_posts && ($stickies = get_posts([
			'orderby'			=> 'post__in',
			'post__in'			=> $sticky_posts,
			'post_type'			=> $q['post_type'] ?: 'post',
			'post_status'		=> 'publish',
			'posts_per_page'	=> count($sticky_posts),
		]+wpjam_pick($q, ['suppress_filters', 'cache_results', 'update_post_meta_cache', 'update_post_term_cache', 'lazy_load_term_meta'])))){
			$q['sticky_posts']	= array_column($stickies, 'ID');

			return array_merge($stickies, array_filter($posts, fn($post)=> !in_array($post->ID, $q['sticky_posts'], true)));
		}

		return $posts;
	}

	public function item_callback($post_id, $args){
		$args	+= ['title_number'=>'', 'excerpt'=>false, 'thumb'=>true, 'size'=>'thumbnail', 'thumb_class'=>'wp-post-image', 'wrap_tag'=>'li'];
		$title	= get_the_title($post_id);
		$item	= wpjam_wrap($title);	

		$args['title_number'] && $item->before('span', ['title-number'], zeroise($args['i']+1, strlen(count($args['query']->posts))).'. ');
		($args['thumb'] || $args['excerpt']) && $item->wrap('h4');
		$args['thumb'] && $item->before(get_the_post_thumbnail($post_id, $args['size'], ['class'=>$args['thumb_class']]));
		$args['excerpt'] && $item->after(wpautop(get_the_excerpt($post_id)));

		return $item->wrap('a', ['href'=>get_permalink($post_id), 'title'=>strip_tags($title)])->wrap($args['wrap_tag'])->render();
	}

	public function wrap_callback($output, $args){
		if(!$output){
			return '';
		}

		$args	+= ['title'=>'', 'div_id'=>'', 'class'=>[], 'thumb'=>true, 'wrap_tag'=>'ul'];
		$output	= wpjam_wrap($output);

		$args['wrap_tag']	&& $output->wrap($args['wrap_tag'])->add_class($args['class'])->add_class($args['thumb'] ? 'has-thumb' : '');
		$args['title']		&& $output->before($args['title'], 'h3');
		$args['div_id']		&& $output->wrap('div', ['id'=>$args['div_id']]);

		return $output->render();
	}

	public static function get_instance($type){
		static $objects	= [];
		return $objects[$type] ??= new self($type);
	}
}

class WPJAM_Extend{
	private $dir;
	private $option;

	public function __construct($dir, $args){
		$this->dir		= $dir;
		$this->option	= ($option = $args['option'] ?? '') ? wpjam_register_option($option, $args+[
			'ajax'				=> false,
			'site_default'		=> is_multisite() && ($args['sitewide'] ?? false),
			'fields'			=> [$this, 'get_fields'],
			'sanitize_callback'	=> [$this, 'sanitize']
		]) : null;
	}

	public function __call($method, $args){
		$dir	= $this->dir;
		$option	= $this->option;

		if($method == 'sanitize'){
			$data	= $args[0];
		}elseif($method == 'load'){
			if($option){
				$data	= array_merge(array_filter($option->get_option()), $option->site_default ? array_filter($option->get_site_option()) : []);
			}else{
				$active	= get_option('active_plugins') ?: [];
			}
		}

		foreach($data ?? scandir($dir) as $k => $v){
			$n	= is_numeric($k) ? $v : $k;

			if(in_array($n, ['.', '..', 'extends.php'])){
				continue;
			}

			$f	= str_ends_with($n, '.php') ? $n : '';
			$n	= $f ? substr($n, 0, -4) : $n;
			$e	= $f ?: $n.(is_dir($dir.'/'.$n) ? '/'.$n : '').'.php';
			$f	= $n ? $dir.'/'.$e : '';

			if(!is_file($f)){
				continue;
			}

			if($method == 'load'){
				if((is_admin() || !str_ends_with($f, '-admin.php')) && ($option || !in_array($e, $active))){
					include_once $f;
				}
			}elseif($method == 'get_fields'){
				$v	= ($d = $this->parse_file($f)) && $d['Name'] && (!$option->site_default || is_network_admin() || !$option->get_site_setting($n)) ? [
					'value'	=> [$option, 'get_'.(is_network_admin() ? 'site_': '').'setting']($n),
					'title'	=> wpjam_wrap($d['Name'], $d['URI'] ? 'a' : '', ['href'=>$d['URI'], 'target'=>"_blank"]),
					'label'	=> $d['Description']
				] : '';
			}

			if($v){
				$result[$n]	= $v;
			}
		}

		return $result ?? [];
	}

	public static function parse_file($file, $type='data'){
		$data	= $file ? array_reduce(['URI', 'Name'], fn($c, $k)=> wpjam_set($c, $k, ($c[$k] ?? '') ?: ($c['Plugin'.$k] ?? '')), get_file_data($file, [
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		])) : [];

		return $type == 'summary' ? ($data ? str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。' : '') : $data;
	}
}

class WPJAM_Hook extends WPJAM_Args{
	public function __invoke(...$args){
		$value	= $args[0];
		$check	= $this->check;

		if(isset($check)){
			if(!$check(...$args)){	// del 2026-08-30
				return $value;
			}
		}

		$result	= maybe_callback($this->callback, ...($this->rotate ? wpjam_rotate($args, 1) : $args));
		$result	= $this->op ? wpjam_operate($value, $this->op, $result) : $result;

		if($this->once && !($this->maybe && (did_action($this->hook) ? $result === false : is_null($result)))){
			foreach((array)$this->hook as $h){
				if($filter = $GLOBALS['wp_filter'][$h] ?? null){
					unset($filter->callbacks[$filter->current_priority()][wpjam_callback('uniqid', $this)]);
				}
			}
		}

		return $this->echo ? wpjam_echo($result) : ($this->tap ? $value : ($this->maybe ? ($result ?? $value) : $result));
	}

	public static function batch($action, $name, ...$args){
		if(is_string($name) && str_contains($name, ',')){
			$name	= wp_parse_list($name);
		}

		if(is_array($name)){
			$name	= array_filter($name);
			$cb		= $action === 'remove' ? 'wpjam_filter' : 'wpjam_map';

			return $cb($name, fn($n)=> static::batch($action, ...(is_array($n) ? $n : [$n, ...$args])));
		}

		if($name && $args){
			$cb		= array_shift($args);
			$multi	= wp_is_numeric_array($cb) && !is_callable($cb);
			$result	= wpjam_map($multi ? $cb : [$cb], fn($cb)=> static::$action($name, $cb, ...$args));

			return $multi ? $result : $result[0];
		}
	}

	public static function add($name, ...$args){
		$attr	= $name === 'once' ? [$name=>true] : [];
		$name	= $attr ? array_shift($args) : $name;

		if(!$name || !$args){
			return;
		}

		$cb	= array_shift($args);

		if(wpjam_is_assoc_array($cb)){
			$attr	+= $cb;
		}else{
			$type	= in_array($cb, ['+', '-', '.'], true) ? 'op' : (in_array($cb, ['tap', 'maybe', 'echo'], true) ? $cb : null);

			if($type){
				$attr	+= [$type=>$cb, 'callback'=>array_shift($args), 'rotate'=>($type !== 'echo')];
			}elseif($args && !is_int($args[0])){
				$attr	+= ['check'=>$cb, 'callback'=>array_shift($args)];	// del 2026-08-30

				trigger_error($name);	
			}elseif($attr){
				$attr	+= ['callback'=> $cb];
			}else{
				$cb		= is_callable($cb) ? $cb : fn()=> $cb;
			}
		}

		$cb	= $attr ? new static($attr+['hook'=>$name]) : $cb;

		add_filter($name, $cb, ...$args);

		return $cb;
	}

	public static function remove(...$args){
		return count($args) > 1 && ($args[2] ??= has_filter(...$args)) !== false && remove_filter(...$args);
	}

	public static function load($hook, $cb, ...$args){
		[$mode, $hook, $cb]	= in_array($hook, ['all', 'any'], true) ? [$hook, $cb, array_shift($args)] : ['all', $hook, $cb];

		if(!$cb || !is_callable($cb)){
			return;
		}

		$hook	= (array)$hook;

		if($mode === 'any'){
			if(!array_any($hook, fn($h)=> did_action($h))){
				return array_walk($hook, fn($h)=> add_action($h, new static(['callback'=>$cb, 'once'=>true, 'hook'=>$hook]), ...$args));
			}
		}else{
			if($hook = array_filter($hook, fn($h)=> !did_action($h))){
				return add_action(array_shift($hook), $hook ? fn()=> static::load($hook, $cb, ...$args) : $cb, ...$args);
			}
		}

		$cb();
	}
}

class WPJAM_File extends WPJAM_Args{
	public function __invoke($value, $to, $from=null){
		$from	??= is_numeric($value) ? 'id' : 'file';

		return $from == $to ? $value : ($from == 'id' ? $this->from_id($value, $to) : $this->convert($value, $to, $from));
	}

	protected function convert($value, $to, $from){
		if(in_array($to, ['size', 'ext'])){
			$file	= $from == 'file' ? $value : $this->convert($value, 'file', $from);

			if($to == 'ext'){
				return pathinfo($file, PATHINFO_EXTENSION);
			}

			if($size = file_exists($file) ? wp_getimagesize($file) : []){
				return ['width'=>$size[0], 'height'=>$size[1]];
			}

			return $this->from_id($this->convert($file, 'id', 'file'), 'size');
		}

		if($path = $this->to_path($value, $from)){
			return $to == 'id' ? wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id') : (['url'=>$this->baseurl,'file'=>$this->basedir][$to] ?? '').$path;
		}
	}

	protected function to_path($value, $from='file'){
		if($from == 'file'){
			$base	= $this->basedir;
		}elseif($from == 'url'){
			$value	= parse_url($value, PHP_URL_PATH);
			$base	= parse_url($this->baseurl, PHP_URL_PATH);
		}else{
			return $value;
		}

		return str_starts_with($value, $base) ? substr($value, strlen($base)) : null;
	}

	protected function from_id($id, $to='file'){
		if($to == 'url'){
			return wp_get_attachment_url($id);
		}

		if($id && get_post_type($id) == 'attachment'){
			if(in_array($to, ['meta', 'size'])){
				$meta	= wp_get_attachment_metadata($id);
				$meta	= is_array($meta) ? $meta : [];

				return $to == 'meta' ? $meta : wpjam_pick($meta, ['width', 'height']);
			}

			$file	= get_attached_file($id, true);

			return $to == 'file' ? $file : $this->convert($file, $to, 'file');
		}
	}

	public function restore($id, $url=''){
		if(is_dir(dirname($file))){
			mkdir(dirname($file), 0777, true);
		}
		
		wpjam_remote_request($url ?: $this->from_id($id, 'url'), ['throw'=>true, 'stream'=>true, 'filename'=>$file]);

		return $file;
	}

	public function attach($file, $args=[]){
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
			'guid'				=> $args['url'] ?? $this->convert($file, 'url', 'file'),
		], $file, $pid, true);

		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));

		return $id;
	}

	public function mimes($accept){
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

	public function upload($name, $args=[]){
		require_once ABSPATH.'wp-admin/includes/file.php';

		if(!empty($args['accept'])){
			$args['mimes']	= $this->mimes($args['accept']) ?: wpjam_throw('upload_error', '无效的文件类型');
		}

		if($bits = wpjam_pull($args, 'bits')){
			$mime	= preg_match('/data:([^;]+);base64,/i', $bits, $m) ? $m[1] : wpjam_throw('upload_error', '无效的 data URI 格式');
			$ext	= (empty($args['mimes']) || in_array($mime, $args['mimes'])) ? array_search($mime, get_allowed_mime_types()) : '';
			$bits	= $ext ? base64_decode(trim(substr($bits, strlen($m[0])))) : wpjam_throw('upload_error', '不允许的文件类型');
			$upload	= wp_upload_bits(explode_last('.', $name)[0].'.'.(explode('|', $ext)[0]), null, $bits);
		}else{
			if(is_array($name)){
				$action	= 'sideload';
			}else{
				$action	= 'upload';
				$name	= $_FILES[$name] ?? wpjam_throw('invalid_parameter', [$name]);
			}

			if(wpjam_pull($args, 'media')){
				require_once ABSPATH.'wp-admin/includes/media.php';
				require_once ABSPATH.'wp-admin/includes/image.php';

				return wpjam_try('media_handle_'.$action, $name, ($args['post_id'] ?? 0));
			}

			$upload	= wpjam_call('wp_handle_'.$action, $name, $args+['test_form'=>false]);
		}

		return empty($upload['error']) ? $upload+['path'=>$this->to_path($upload['file'])] : wpjam_throw('upload_error', $upload['error']);
	}

	public function download($url, $args=[]){
		$media	= $args['media'] ?? false;
		$field	= ($args['field'] ?? '') ?: ($media ? 'id' : 'file');
		$result	= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

		if(!$result || get_post_type($result) != 'attachment'){
			try{
				$tmp	= wpjam_try('download_url', $url);
				$upload	= ['name'=>($args['name'] ?? '') ?: md5($url).'.'.wpjam_at(wp_get_image_mime($tmp), '/', 1), 'tmp_name'=>$tmp];
				$result	= $this->upload($upload, wpjam_pick($args, ['media', 'post_id']));

				if(!$media){
					return $result[$field];
				}

				update_post_meta($result, 'source_url', $url);
			}catch(Exception $e){
				$tmp && @unlink($tmp);

				return wpjam_catch($e);
			}
		}

		return $this->from_id($result, $field);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self(wp_get_upload_dir());
	}
}

class WPJAM_Widget extends WP_Widget{
	public function __construct($id, $name, $options){
		parent::__construct($id, $name, $options+['show_instance_in_rest'=>false]);
	}

	public function widget($args, $instance){
		$widget	= $this->widget_options['widget'];

		if($output = $widget($instance)){
			$title	= wpjam_pull($instance, 'title');
			$output	= ($title ? $args['before_title'].$title.$args['after_title'] : '').$output;

			echo $args['before_widget'].$output.$args['after_widget'];
		}
	}

	public function form($instance){
		echo str_replace('fieldset', 'span', wpjam_fields(wpjam_map(maybe_callback($this->widget_options['fields']), fn($v, $k)=> $v+[
			'id'	=> $this->get_field_id($k),
			'name'	=> $this->get_field_name($k),
			'value'	=> $instance[$k] ?? null
		]))->render(['wrap_tag'=>'p']));
	}
}

class WPJAM_Exception extends Exception{
	private $error;

	public function __construct($msg, $code=null, ?Throwable $previous=null){
		$error	= $this->error = is_wp_error($msg) ? $msg : new WP_Error($code ?: 'error', $msg);
		$code	= $error->get_error_code();

		parent::__construct($error->get_error_message(), (is_numeric($code) ? (int)$code : 1), $previous);
	}

	public function __call($method, $args){
		return in_array($method, ['get_wp_error', 'get_error']) ? $this->error : [$this->error, $method](...$args);
	}
}