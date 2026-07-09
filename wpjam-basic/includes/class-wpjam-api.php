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

	public function is(...$args){
		$query	= ($args && is_object($args[0])) ? array_shift($args) : array_last($this->get('query'));

		if(!$query || !($query instanceof WP_Query) || !$query->is_main_query()){
			return false;
		}

		if($args){
			return array_any(wp_parse_list(array_shift($args)), fn($t)=> wpjam_call_method($query, 'is_'.$t, ...$args));
		}

		return $query->is_front_page() ? 'home' : (array_find(['feed', 'author', 'category', 'tag', 'tax', 'post_type_archive', 'search', 'date', 'archive', '404', 'page', 'single', 'attachment'], fn($t)=> [$query, 'is_'.$t]()) ?: true);
	}

	public function route($module, $args, $query_var=false){
		if(is_string($args) && class_exists($args)){
			$model	= $args;
			$args	= ['callback'=>$model.'::redirect'];
			$rr		= fn()=> wpjam_value($model, 'rewrite_rule');

			is_admin() && array_map(fn($k)=> wpjam_call('wpjam_add_'.$k, wpjam_value($model, $k)), ['menu_page', 'admin_load']);
		}else{
			$args	= wpjam_is_assoc_array($args) ? array_filter($args) : ['callback'=>$args];
			$rr		= $args['rewrite_rule'] ?? '';
		}

		if($rr){
			wpjam_init(fn()=> wpjam_add_rewrite_rule(maybe_callback($rr)));
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

		if(($code = $result['response']['code']) && !wpjam_compare($code, 'between', [200, 299])){
			wpjam_throw($code, $code.' - '.$result['response']['message'].'-'.var_export($result['body'], true));
		}

		$body	= &$result['body'];

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
		}else{
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
			return wpjam_map(array_filter($name), fn($n)=> static::batch($action, ...(is_array($n) ? $n : [$n, ...$args])));
		}

		return $name && $args ? wpjam_map(
			($cb = array_shift($args)) && wp_is_numeric_array($cb) && !is_callable($cb) ? $cb : [$cb],
			fn($cb)=> [static::class, $action]($name, $cb, ...$args)
		) : [];
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
			$args['mimes']	= $this->mimes($args['accept']);
			$args['mimes'] || wpjam_throw('upload_error', '无效的文件类型');
		}

		if($bits = wpjam_pull($args, 'bits')){
			if(!preg_match('/data:([^;]+);base64,/i', $bits, $m)){
				wpjam_throw('upload_error', '无效的 data URI 格式');
			}

			$mime	= $m[1];
			$bits	= base64_decode(trim(substr($bits, strlen($m[0]))));
			$ext	= array_search($mime, get_allowed_mime_types());

			if(!$ext || (!empty($args['mimes']) && !in_array($mime, $args['mimes']))){
				wpjam_throw('upload_error', '不允许的文件类型');
			}

			$upload	= wp_upload_bits(explode_last('.', $name)[0].'.'.(explode('|', $ext)[0]), null, $bits);
		}else{
			$args 	+= ['test_form'=>false];
			$upload	= is_array($name) ? wp_handle_sideload($name, $args) : wp_handle_upload($_FILES[$name], $args);
		}

		return empty($upload['error']) ? $upload+['path'=>$this->to_path($upload['file'])] : wpjam_throw('upload_error', $upload['error']);
	}

	public function download($url, $args=[]){
		$media	= $args['media'] ?? false;
		$field	= ($args['field'] ?? '') ?: ($media ? 'id' : 'file');
		$id		= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

		if(!$id || get_post_type($id) != 'attachment'){
			try{
				$tmp	= wpjam_try('download_url', $url);
				$upload	= ['name'=>($args['name'] ?? '') ?: md5($url).'.'.wpjam_at(wp_get_image_mime($tmp), '/', 1), 'tmp_name'=>$tmp];

				if(!$media){
					return $this->upload($upload)[$field];
				}

				$id	= wpjam_try('media_handle_sideload', $upload, ($args['post_id'] ?? 0));

				update_post_meta($id, 'source_url', $url);
			}catch(Exception $e){
				$tmp && @unlink($tmp);

				return wpjam_catch($e);
			}
		}

		return $this->from_id($id, $field);
	}

	public function import($file, $columns=[]){
		$file	= ($file && !str_starts_with($file, $this->basedir) ? $this->basedir : '').$file;

		if(!$file || !file_exists($file)){
			return new WP_Error('file_not_exists', '文件不存在');
		}

		$ext	= wpjam_at($file, '.', -1);

		if($ext == 'csv'){
			if(($handle = fopen($file, 'r')) !== false){
				while(($row = fgetcsv($handle)) !== false){
					if(!array_filter($row)){
						continue;
					}

					if(($encoding ??= mb_detect_encoding(implode('', $row), mb_list_encodings(), true)) != 'UTF-8'){
						$row	= array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'GBK'), $row);
					}

					if(isset($map)){
						$data[]	= array_map(fn($i)=> preg_replace('/="([^"]*)"/', '$1', $row[$i]), $map);
					}else{
						if($columns){
							$columns= array_flip(array_map('trim', $columns));
							$row	= array_map(fn($v)=> trim(trim($v), "\xEF\xBB\xBF"), $row);
							$map	= wpjam_array($row, fn($k, $v)=> isset($columns[$v]) ? [$columns[$v], $k] : (in_array($v, $columns) ? [$v, $k] : null));
						}else{
							$map	= array_flip(array_map(fn($v)=> trim(trim($v), "\xEF\xBB\xBF"), $row));
						}
					}
				}

				fclose($handle);
			}
		}else{
			$data	= file_get_contents($file);
			$data	= ($ext == 'txt' && is_serialized($data)) ? maybe_unserialize($data) : $data;
		}

		unlink($file);

		return $data ?? [];
	}

	public function export($file, $data, $columns=[]){
		$handle	= fopen('php://output', 'w');
		$ext	= wpjam_at($file, '.', -1);

		header('Content-Disposition: attachment;filename='.$file);
		header('Content-Type: text/'.($ext == 'txt' ? 'plain' : $ext));
		header('Pragma: no-cache');
		header('Expires: 0');

		if($ext == 'csv'){
			fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));

			$columns && fputcsv($handle, $columns);

			array_walk($data, fn($item)=> fputcsv($handle, $columns ? wpjam_map($columns, fn($k)=> $item[$k] ?? '', 'k') : $item));
		}elseif($ext == 'txt'){
			fputs($handle, is_scalar($data) ? $data : maybe_serialize($data));
		}

		fclose($handle);

		exit;
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self(wp_get_upload_dir());
	}
}

class WPJAM_JSON extends WPJAM_Register{
	public function __invoke(){
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$attr		= $method != 'POST' && !str_ends_with($this->name, '.config') ? ['page_title', 'share_title', 'share_image'] : [];
		$response	= wpjam_try('apply_filters', 'wpjam_pre_json', [], $this, $this->name);
		$response	+= ['errcode'=>0, 'current_user'=>wpjam_try('wpjam_get_current_user', $this->pull('auth'))]+$this->pick($attr);

		if($this->modules){
			$modules	= maybe_callback($this->modules, $this->name, $this->args);
			$results	= array_map(['WPJAM_JSON_Module', 'parse'], wp_is_numeric_array($modules) ? $modules : [$modules]);
		}elseif($this->callback){
			$fields		= wpjam_try('maybe_callback', $this->fields ?: [], $this->name);
			$data		= $this->fields ? ($fields ? wpjam_params($method, $fields) : []) : $this->args;
			$results[]	= wpjam_try($this->pull('callback'), $data, $this->name);
		}elseif($this->template){
			$results[]	= is_file($this->template) ? include $this->template : '';
		}else{
			$results[]	= wpjam_except($this->args, 'name');
		}

		$response	= array_reduce($results, fn($c, $v)=> array_merge($c, is_array($v) ? array_diff_key($v, wpjam_pick($c, $attr)) : []), $response);
		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		foreach($attr as $k){
			if(($v	= $response[$k] ?? '') || $k != 'share_image'){
				$response[$k]	= $k == 'share_image' ? wpjam_get_thumbnail($v, '500x400') : html_entity_decode($v ?: wp_get_document_title());
			}
		}

		return $response;
	}

	public static function redirect($name){
		header('X-Content-Type-Options: nosniff');

		rest_send_cors_headers(false);

		if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
			status_header(403);
			exit;
		}

		ini_set('display_errors', 0);

		wpjam_hook('wp_die_'.(array_find(['jsonp_', 'json_'], fn($v)=> wpjam_call('wp_is_'.$v.'request')) ?: '').'handler', fn()=> 'wpjam_die_handler');

		if(!try_remove_prefix($name, 'mag.')){
			return;
		}

		$name	= substr($name, str_starts_with($name, '.mag') ? 4 : 0);	// 兼容
		$name	= str_replace('/', '.', $name);

		($user = wpjam_get_current_user()) && ($user_id = $user['user_id'] ?? '') && wp_set_current_user($user_id);

		do_action('wpjam_api', wpjam_var('json', $name));

		wpjam_send_json(wpjam_catch(self::get($name) ?: wp_die('接口未定义', 'invalid_api')));
	}

	public static function get_defaults(){
		return array_fill_keys(['post.list', 'post.calendar', 'post.get'], ['modules'=>['WPJAM_JSON_Module', 'callback']])+[
			'media.upload'	=> ['modules'=>['callback'=>['WPJAM_JSON_Module', 'media']]],
			'site.config'	=> ['modules'=>['type'=>'config']],
		];
	}

	public static function get_current(){
		return wpjam_var('json');
	}

	public static function get_rewrite_rule(){
		return [
			['api/([^/]+)/(.*?)\.json?$',	['module'=>'json', 'action'=>'mag.$matches[1].$matches[2]'], 'top'],
			['api/([^/]+)\.json?$', 		'index.php?module=json&action=$matches[1]', 'top'],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['parse_post_list_module', 'parse_post_get_module'])){
			return wpjam_catch('WPJAM_JSON_Module::parse', [
				'type'	=> 'post_type',
				'args'	=> ['action'=>str_replace(['parse_post_', '_module'], '', $method)]+($args[0] ?? [])
			]);
		}
	}
}

class WPJAM_JSON_Module{
	public static function parse($module){
		$args	= $module['args'] ?? [];
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');
		$parser	= ($module['callback'] ?? '') ?: (($type = $module['type'] ?? '') ? wpjam_callback(self::class.'::'.$type) : '');

		return $parser ? wpjam_try($parser, $args) : $args;
	}

	/* 规则：
	** 1. 分成主查询和子查询（sub=>1）
	** 2. 主查询支持 $_GET 参数，返回 next_cursor 和 total_pages，current_page
	** 3. $_GET 参数只适用于 post.list / term.list 只能用 $_GET 参数 mapping 来传递参数
	*/
	public static function post_type($args){
		$action	= wpjam_pull($args, 'action');

		if($action == 'upload'){
			return self::media($args, 'media');
		}

		static $query_vars;

		$wp		= $GLOBALS['wp'];
		$query	= $GLOBALS['wp_query'];
		$output	= wpjam_pull($args, 'output');
		$vars	= ($query_vars ??= $wp->query_vars);

		if(in_array($action, ['list', 'calendar'])){
			$sub	= $action == 'calendar' ? false : wpjam_pull($args, 'sub');
			$args	= array_diff_key($args, WPJAM_Post::get_default_args());

			if($sub){
				$query	= wpjam_query($args);
				$parsed	= wpjam_get_posts($query, $args);
			}else{
				$vars	= array_merge(wpjam_except($vars, ['module', 'action']), $args);

				if($action == 'calendar'){
					$vars	+= [
						'year'		=> (int)wpjam_param('year') ?: wpjam_date('Y'),
						'monthnum'	=> (int)wpjam_param('month') ?: wpjam_date('m'),
						'day'		=> (int)wpjam_param('day')
					];

					$args	+= ['format'=>'date'];
				}else{
					$number	= wpjam_find(['number', 'posts_per_page'], fn($v)=> $v, fn($k)=> (int)wpjam_param($k));
					$vars	+= ($number && $number != -1) ? ['posts_per_page'=> min($number, 100)] : [];
					$vars	+= array_filter(['offset'=>wpjam_param('offset')]);

					if($post__in = wpjam_param('post__in')){
						$vars['post__in']		= wp_parse_id_list($post__in);
						$vars['orderby']		??= 'post__in';
						$vars['posts_per_page']	??= -1;
					}
				}

				$wp->query_vars	= $vars = wpjam_parse_query_vars($vars, true);
				$wp->query_posts();

				$parsed	= wpjam_get_posts($query, $args);

				if($action != 'calendar'){
					$nopaging	= $query->get('nopaging');
					$json		= [
						'total'			=> $query->found_posts,
						'total_pages'	=> $nopaging ? ($query->found_posts ? 1 : 0) : $query->max_num_pages,
						'current_page'	=> $nopaging ? 1 : ($query->get('paged') ?: 1),
					];

					if(empty($vars['paged']) && empty($vars['s']) && in_array($vars['orderby'] ?? 'date', ['date', 'post_date'], true)){
						$json['next_cursor']	= ($parsed && $query->max_num_pages > 1) ? end($parsed)['timestamp'] : 0;
					}

					$is			= wpjam_is($query);
					$queried	= $query->get_queried_object();

					if(in_array($is, ['category', 'tag', 'tax'], true)){
						$json	+= ['current_taxonomy'=>($queried ? $queried->taxonomy : null)];
						$json	+= $queried ? ['current_term'=>wpjam_get_term($queried, $queried->taxonomy)] : [];
					}elseif($is === 'author'){
						$json	+= ['current_author'=>wpjam_get_user($query->get('author'))];
					}elseif($is === 'post_type_archive'){
						$json	+= ['current_post_type'=>($queried ? $queried->name : null)];
					}

					$json	+= is_string($is) ? ['is'=>$is] : [];
				}

				if(!$output && !empty($vars['post_type']) && is_string($vars['post_type'])){
					$output	= wpjam_get_post_type_setting($vars['post_type'], 'plural') ?: $vars['post_type'].'s';
				}
			}

			$output	= $output ?: 'posts';

			$json[$output]	= $parsed;

			return apply_filters('wpjam_posts_json', $json, $query, $output);
		}elseif($action == 'get'){
			$type	= ($args['post_type'] ??= '') === 'any' ? '' : $args['post_type'];
			$status	= $args['post_status'] ?? '';
			$vars	= ['cache_results'=>true]+($status ? ['status'=>$status] : [])+$vars;

			if($type){
				$var	= post_type_exists($type) ? (is_post_type_hierarchical($type) ? 'pagename' : 'name') : wp_die('invalid_post_type');
				$name	= $vars[$var] ?? '';
			}else{
				$map	= wp_list_pluck(get_post_types(['_builtin'=>false, 'query_var'=>true], 'objects'), 'query_var')+['post'=>'name', 'page'=>'pagename'];
				$type	= array_find_key($map, fn($v)=> !empty($vars[$v]));
				$name	= $type ? $vars[$map[$type]] : null;
			}

			if(!$name){
				$id		= ($args['id'] ?? 0) ?: (int)wpjam_param('id', ['required'=>true]);
				$type	??= get_post_type($id);
				$vars	= ['p'=>($type && (get_post_type($id) == $type)) ? $id : wp_die('invalid_post_id')]+$vars;
			}

			$vars['post_type']	= $type;
			$wp->query_vars		= $vars;
			$wp->query_posts();

			if(empty($id) && !$status && !$query->have_posts()){
				$id	= apply_filters('old_slug_redirect_post_id', null) ?: wp_die('invalid_post_id');

				$wp->query_vars	= ['post_type'=>'any', 'p'=>$id]+wpjam_except($vars, ['name', 'pagename']);
				$wp->query_posts();
			}

			$parsed	= $query->have_posts() ? array_first(wpjam_get_posts($query, $args)) : wp_die('invalid_parameter');
			$output	= $output ?: $parsed['post_type'];

			return wpjam_pull($parsed, ['share_title', 'share_image', 'share_data'])+[$output => $parsed];
		}
	}

	public static function media($args, $format=''){
		require_once ABSPATH.'wp-admin/includes/file.php';
		require_once ABSPATH.'wp-admin/includes/media.php';
		require_once ABSPATH.'wp-admin/includes/image.php';

		$media	= ($args['media'] ?? '') ?: 'media';
		$output	= ($args['output'] ?? '') ?: 'url';

		isset($_FILES[$media]) || wpjam_throw('invalid_parameter', [$media]);

		if($format == 'media'){
			$id		= wpjam_try('media_handle_upload', $media, (int)wpjam_param('post_id', 'post'));
			$url	= wp_get_attachment_url($id);
			$query	= wpjam_image($id, 'size');
		}else{
			$upload	= wpjam_upload($media);
			$url	= $upload['url'];
			$query	= wpjam_image($upload['file'], 'size');
		}

		return [$output => $query ? add_query_arg($query, $url) : $url];
	}

	public static function taxonomy($args){
		$object		= wpjam_get_taxonomy(wpjam_get($args, 'taxonomy')) ?: wpjam_throw('invalid_taxonomy');
		$mapping	= wpjam_array(wp_parse_args(wpjam_pull($args, 'mapping') ?: []), fn($k, $v)=> [$k, wpjam_param($v)], true);
		$args		= array_merge($args, $mapping);
		$number		= (int)wpjam_pull($args, 'number');
		$paged		= wpjam_pull($args, 'paged') ?: 1;
		$output		= wpjam_pull($args, 'output') ?: $object->plural;
		$terms		= wpjam_get_terms($args);

		if($terms && $number){
			$terms = array_slice($terms, $number * ($paged-1), $number);
		}

		return [$output	=> $terms ? array_values($terms) : []];
	}

	public static function setting($args){
		if($option	= $args['option_name'] ?? ''){
			$name	= $args['setting_name'] ?? ($args['setting'] ?? null);
			$output	= ($args['output'] ?? '') ?: ($name ?: $option);
			$object	= wpjam_get_option($option, 'object');
			$names	= $object && $object->option_type != 'array' ? [$option, $name] : [$name];

			return [$output => wpjam_get($object->option_type ? $object->prepare_by_fields() : $object->get_option(), array_filter($names) ?: null)];
		}
	}

	public static function data_type($args){
		$name	= wpjam_pull($args, 'data_type');
		$args	= wp_parse_args(($args['query_args'] ?? $args) ?: []);
		$object	= wpjam_get_data_type($name, $args) ?: wpjam_throw('invalid_data_type');

		return ['items'=>$object->query_items($args+['search'=>wpjam_param('s')])];
	}

	public static function config($args){
		return wpjam_get_config($args['group'] ?? '');
	}

	public static function callback($name, $args=[]){
		$output	= $args['output'] ?? null;

		[$type, $action]	= explode('.', $name);

		if($type == 'post'){
			$type	= wpjam_param('post_type');
			$output	??= $action == 'get' ? 'post' : 'posts';
		}

		$args		= ['post_type'=>$type, 'action'=>$action, 'output'=>$output]+array_intersect_key($args, WPJAM_Post::get_default_args());
		$modules[]	= ['type'=>'post_type',	'args'=>array_filter($args, fn($v)=> !is_null($v))];

		if($action == 'list' && $type && is_string($type) && !str_contains($type, ',')){
			foreach(get_object_taxonomies($type) as $tax){
				if(is_taxonomy_hierarchical($tax) && wpjam_get_taxonomy_setting($tax, 'show_in_posts_rest')){
					$modules[]	= ['type'=>'taxonomy',	'args'=>['taxonomy'=>$tax, 'hide_empty'=>0]];
				}
			}
		}

		return $modules;
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