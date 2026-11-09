<?php
class WPJAM_API{
	private $data	= [];

	public function __invoke($field, ...$args){
		if(!$field){
			return $this;
		}

		$result	= try_suffix($field, '-', '[]');
		$items	= $this->data[$field] ?? [];

		if(!$args){
			return $items;
		}

		[$key, $item]	= array_pad($args, $result ? -2 : 2, null);

		if($result){
			if(is_null($item)){
				return $this->data[$field] = isset($key) ? wpjam_except($items, $key) : [];
			}

			if(isset($key) && !str_ends_with($key, '[]') && wpjam_get($items, $key) !== null){
				return new WP_Error('invalid_key', '「'.$key.'」已存在，无法添加');
			}
		}else{
			if(is_array($key)){
				return $this->data[$field]	= array_merge($item ? $items : [], $key);
			}

			if(count($args) < 2){
				return wpjam_get($items, $key);
			}
		}

		$this->data[$field]	= wpjam_set($items, $key ?? '[]', $item);

		return $item;
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

		return $args ? $this->parse('try', $cb, $args) : $this->parse('', $cb);
	}

	public function call($type, $cb, ...$args){
		$res	= $this->parse($type, $cb, $args);

		return is_array($res) ? $res[0](...$res[1]) : $res;
	}

	public function parse($type, $cb, ...$args){
		if(is_string($cb) && ($sep = array_find(['::', '->'], fn($s)=> str_contains($cb, $s)))){
			$static	= $sep == '::';
			$cb		= explode($sep, $cb, 2);
		}
		
		if(!is_array($cb) && $type == 'method'){
			$cb	= [$cb, array_shift($args[0])];
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
		}else{
			if(!is_callable($cb)){
				return $this->invalid($type, $cb);
			}
		}

		return [$cb, $args];
	}

	private function invalid($type, $msg, $code='callback'){
		if($type == 'catch'){
			return new WP_Error('invalid_'.$code, [$msg]);
		}elseif($type == 'try'){
			wpjam_invalid($code, [$msg]);
		}
	}

	public function value_callback($args, $name){
		$path	= (array)$name;
		$name	= array_shift($path);
		$id		= $args['id'] ?? null;
		$cb		= $args['value_callback'] ?? '';
		$mt 	= $id ? ($args['meta_type'] ?? '') : '';

		if(!$cb && ($model = $args['model'] ?? '')){
			if($id && $path && $name == 'meta_input' && ($mt = $mt ?: $this->call('method', $model.'::get_meta_type'))){
				$name	= array_shift($path);
			}else{
				$cb		= $this->parse('', [$model, 'value_callback']);
			}
		}

		foreach([$cb, $mt] as $i => $t){
			$value	= $t ? wpjam_if_error(($i === 0 ? $this->call('', $t, $name, $id) : wpjam_get_metadata($t, $id, $name)), null) : null;
			$value	= $path ? wpjam_get($value ?: [], $path) : $value;

			if(isset($value)){
				return $value;
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
		$this->option	= ($args['option'] ?? '') ? wpjam_register_option($args['option'], $args+[
			'ajax'				=> false,
			'site_default'		=> is_multisite() && ($args['sitewide'] ?? false),
			'fields'			=> [$this, 'get_fields'],
			'sanitize_callback'	=> [$this, 'sanitize']
		]) : null;

		$this->load();
	}

	public function __call($method, $args){
		$dir	= $this->dir;
		$option	= $this->option;
		$active	= get_option('active_plugins') ?: [];
		$data	= $option && $method == 'load'
		? array_merge(array_filter($option->get_option()), $option->site_default ? array_filter($option->get_site_option()) : [])
		: ($method == 'sanitize' ? $args[0] : wpjam_filter(scandir($dir), ['@value'=>['NOT IN'=>['.', '..', 'extends.php']]]));

		foreach($data as $k => $v){
			$n	= is_numeric($k) ? $v : $k;
			$f	= str_ends_with($n, '.php') ? $n : '';
			$n	= $f ? substr($n, 0, -4) : $n;
			$e	= $f ?: $n.(is_dir($dir.'/'.$n) ? '/'.$n : '').'.php';
			$f	= $n ? $dir.'/'.$e : '';
			$d	= is_file($f) ? wpjam_get_file_data($f) : '';

			if($method == 'load'){
				if($d && (is_admin() || empty($d['Admin'])) && !in_array($e, $active)){
					include_once $f;
				}
			}elseif($method == 'get_fields'){
				$v	= $d && $d['Name'] && (!$option->site_default || is_network_admin() || !$option->get_site_setting($n)) ? [
					'value'	=> [$option, 'get_'.(is_network_admin() ? 'site_': '').'setting']($n),
					'title'	=> wpjam_wrap($d['Name'], $d['URI'] ? 'a' : '', ['href'=>$d['URI'], 'target'=>"_blank"]),
					'label'	=> $d['Description']
				] : '';
			}

			if($d && $v){
				$result[$n]	= $v;
			}
		}

		return $result ?? [];
	}
}

class WPJAM_Param{
	private $data	= [];

	public function __invoke($type, ...$args){
		$name	= $args && is_scalar($args[0]) ? array_shift($args) : null;
		$args	= $args[0] ?? [];
		$value	= $this->get_by($type, $name);

		if(isset($name)){
			$value	??= $args['default'] ?? wpjam_default($name);

			if($name && ($field = wpjam_except($args, ['default', 'send']))){
				$field	= wpjam_field(['key'=>$name]+(($field['type'] ?? '') ? [] : ['_schema'=>[]])+$field);
				$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

				($args['send'] ?? true) && wpjam_if_error($value, 'send');
			}
		}else{
			$fields	= $args && is_array($args) ? wpjam_fields($args) : $args;
			$value	= $fields ? array_merge($value, $fields->validate($value, 'parameter')) : $value;
		}

		return $value;
	}

	private function get_by($type, $name=''){
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

		$data	= $this->data[$type] ??= $this->get_data($type);

		return $name ? wpjam_get($data, $name) : $data;
	}

	private function get_data($type){
		if($type == 'input'){
			$v		= file_get_contents('php://input');
			$v		= $v && is_string($v) ? @wpjam_json_decode($v) : $v;
			$data	= is_array($v) ? $v : [];
		}else{
			foreach(array_unique(['defaults', $type]) as $t){
				$v		= $this->get_by('request', $t) ?? [];
				$v		= $v && is_string($v) && $v[0] === '{' && $v[-1] === '}' ? wpjam_json_decode($v) : wp_parse_args($v ?: []);
				$data	= wpjam_merge($data ?? [], $v);
			}
		}

		return $data;
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		$throw	= wpjam_pull($args, 'throw');
		$field	= wpjam_pull($args, 'field');

		try{
			$result = wpjam_try('wp_safe_remote_request', $url, self::prepare($args));
			$result	= self::parse($result, $args, $err);

			return $field ? wpjam_get($result, $field) : $result;
		}catch(Exception $e){
			$error	= wpjam_fill(['code', 'message', 'data'], fn($k)=> [$e, 'get_error_'.$k]());

			if(apply_filters('wpjam_http_response_error_debug', true, $error['code'], $error['message'])){
				trigger_error(var_export(['host'=>wp_parse_url($url, PHP_URL_HOST), 'code'=>$error['code']], true));
			}

			if($throw){
				throw $e;
			}

			return wpjam_catch($e);
		}
	}

	private static function prepare($args){
		$body	= $args['body'] ?? [];
		$method	= strtoupper($args['method'] ?? '') ?: ($body ? 'POST' : 'GET');

		if($method == 'FILE'){
			if(!function_exists('curl_init')){
				wpjam_throw('http_request_failed', '文件上传需要 cURL 扩展');
			}

			wpjam_once('http_api_curl', fn($handle)=> curl_setopt($handle, CURLOPT_POSTFIELDS, $body));

			$method	= 'POST';
		}elseif($method != 'GET'){
			$key	= 'content-type';
			$value	= 'application/json';

			$args['headers']	= array_change_key_case($args['headers'] ?? []);
			$args['headers']	+= wpjam_pull($args, 'json_encode') ? [$key=>$value] : [];

			if(str_contains($args['headers'][$key] ?? '', $value) && is_array($body)){
				$args['body']	= wpjam_json_encode($body ?: new stdClass);
			}
		}

		return ['method'=>$method]+$args;
	}

	private static function parse($result, $args, $err){
		if(!($args['blocking'] ?? true)){
			return $result;
		}

		$code	= $result['response']['code'];
		$body	= &$result['body'];

		if(!wpjam_compare($code, 'between', [200, 299])){
			wpjam_throw($code, $code.' - '.$result['response']['message'].'-'.var_export($body, true));
		}

		if($body && empty($args['stream'])){
			if(str_contains(wp_remote_retrieve_header($result, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') ?? ($b = trim($body)) && $b[0] === '{' && $b[-1] === '}'){
				$body	= wpjam_then(wpjam_json_decode($body), fn($res)=> self::error($res, $err+['errmsg'=>'errmsg']), fn()=> $body);
			}
		}

		return $result;
	}

	private static function error($res, $err){
		if(($code = wpjam_pull($res, $err['errcode'] ?? 'errcode')) && $code != ($err['success'] ?? '0')){
			wpjam_throw($code, wpjam_pull($res, $err['errmsg']), wpjam_pull($res, $err['detail'] ?? 'detail') ?? array_filter($res));
		}

		if(strtolower($res[$err['errmsg']] ?? '') == 'ok'){
			unset($res[$err['errmsg']]);
		}

		return $res;
	}
}

class WPJAM_Error{
	public static function parse($code='', $msg=''){
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
			return self::can($code, $msg) ? array_merge($data, self::parse($code, $msg)) : $data;
		}

		$args	= $msg ?: [];
		$err	= $code;

		if($item = self::get($code)){
			$msg	= maybe_closure($item['errmsg'], $args);
		}elseif(try_prefix($err, '-', 'invalid_')){
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
		}elseif(try_suffix($err, '-', '_required')){
			$msg	= __('%s is empty or invalid.', 'wpjam');
			$trans	= __($args && $err == 'value' ? '%s\'s value' :  $err, 'wpjam');
			$args	= [$args ? sprintf($trans.($err == 'value' ? '' : '%s'), ...$args) : $trans];
		}elseif(try_suffix($err, '-', '_occupied')){
			$msg	= __('%s is already in use by another account.', 'wpjam');
			$args	= [__($err, 'wpjam')];
		}else{
			$msg	= str_replace('_', ' ', $err);
		}

		if(!$msg){
			return [];
		}

		return ['errcode'=>$code, 'errmsg'=>(($args && str_contains($msg, '%')) ? sprintf($msg, ...$args) : $msg)]+$item;
	}

	public static function can($code, $msg=''){
		return $code && (!$msg || is_array($msg));
	}

	public static function callback($code, $msg, $data, $error){
		if(self::can($code, $msg) && count($error->get_error_messages($code)) <= 1 && ($item = self::parse($code, $msg))){
			$error->remove($code);
			$error->add($code, $item['errmsg'], empty($item['modal']) ? $data : array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]));
		}
	}

	public static function __callStatic($method, $args){
		static $object;
		$object ??= wpjam_hook('wp_error_added', ['callback'=>[self::class, 'callback']], 10, 4);
		$key	= 'errors['.(array_shift($args) ?: '').']';

		return $method == 'get' ? ($object->get_arg($key) ?: []) : $object->update_arg($key, ['errmsg'=>$args[0], 'modal'=>$args[1] ?? []]);
	}
}

class WPJAM_Data_Type extends WPJAM_Args{
	public function get_path($args, $item){
		return wpjam_if_error(wpjam_call_method($this->model.'::get_path', $args, $item), 'throw');
	}

	public function with_field($action, $value, $field){
		$res	= ($cb = $this->model.'::with_field') && wpjam_callback($cb) ? wpjam_try($cb, $action, $field, $value) : ($this->{$action.'_value'} ? $this->call($action.'_value', $value, $field) : $value);

		return $action == 'validate' && is_null($res) ? wpjam_invalid('field_value', $field->_title.'「'.$value.'」的值无效') : $res;
	}

	public function query_label($value){
		if($value && $this->model && $this->label_field){
			if(is_array($value)){
				wpjam_call_method($this->model.'::update_caches', $value);

				return array_map(fn($v)=> !is_null($l = $this->query_label($v)) ? ['label'=>$l, 'value'=>$v] : $v, $value);
			}

			return ($this->model::get($value) ?: [])[$this->label_field] ?? null;
		}
	}

	public function query_items($args){
		$args	= wpjam_filter($args ?: [], '!null')+['number'=>10, 'data_type'=>true];

		if($this->query_items){
			return wpjam_try($this->query_items, $args);
		}

		if($this->model){
			$args	= isset($args['model']) ? wpjam_except($args, ['data_type', 'model', 'label_field', 'id_field']) : $args;
			$res	= wpjam_try($this->model.'::query_items', $args, 'items');
			$items	= wp_is_numeric_array($res) ? $res : ($res['items'] ?? []);

			return $this->label_field ? wpjam_column($items, ['label'=>$this->label_field, 'value'=>$this->id_field]) : $items;
		}

		return [];
	}

	public function nonce_action($args){
		return $this->name.':'.wpjam_json_encode(wpjam_pick($args, ['label_field', 'id_field']));
	}

	public static function register($name, $args=[]){
		return wpjam_register(static::class, $name, $args);
	}

	public static function get_instance($name, $args=[]){
		if(!$name){
			return;
		}

		$model	= '';

		if($name == 'model'){
			$model	= $args[$name] ?? '';
			$vars	= $model && class_exists($model) ? wpjam_map(['label_field', 'id_field'], fn($k)=> $args[$k] ?? '') : [];
			$name	= $vars && $vars === array_filter($vars) ? wpjam_join(':', [$model, ...$vars]) : '';
			$model	= $name ? $model : '';
		}

		if($object = wpjam_get_registered(static::class, $name) ?? self::register($name, $model ? $args : ([
			'post_type'	=> ['model'=>'WPJAM_Post',	'schema'=>['type'=>'integer'],	'label_field'=>'post_title',	'id_field'=>'ID'],
			'taxonomy'	=> ['model'=>'WPJAM_Term',	'schema'=>['type'=>'integer'],	'label_field'=>'name',		'id_field'=>'term_id'],
			'author'	=> ['model'=>'WPJAM_User',	'schema'=>['type'=>'integer'],	'label_field'=>'display_name',	'id_field'=>'ID'],
			'video'		=> ['parse_value'=>'wpjam_get_video_mp4'],
		][$name] ?? null))){
			if($model){
				$object->validate_value	??= fn($v)=> wpjam_try($args['model'].'::get', $v) ? $v : null;
			}

			if($object->model){
				$object->meta_type	??= wpjam_call_method($object->model.'::get_meta_type') ?: '';
			}
		}

		return $object;
	}
}

/**
* @config orderby=order order=ASC
* @items_field paths
**/
#[config(orderby:'order', order:'ASC')]
#[items_field('paths')]
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		return $key == 'path' ? (bool)$this->get_paths() : parent::__get($key);
	}

	public function __call($method, $args){
		if($this->mod($method, '_path')){
			$method	= ($method == 'add' ? 'update' : $method).'_arg';

			return $this->$method('paths['.array_shift($args).']', ...$args);
		}elseif($this->mod($method, '_item')){
			$item	= $args[0];
			$suffix	= $args[1] ?? '';
			$multi	= $args[2] ?? false;

			$page_key	= wpjam_pull($item, 'page_key'.$suffix);

			if($page_key == 'none'){
				return ($video = $item['video'] ?? '') ? ['type'=>'video', 'video'=>wpjam_get_qqv_id($video) ?: $video] : ['type'=>'none'];
			}elseif(!$this->get_path($page_key.'[]')){
				return [];
			}

			$item	= $suffix ? wpjam_map($this->get_fields($page_key), fn($v, $k)=> $item[$k.$suffix] ?? null) : $item;
			$path	= $this->get_path($page_key, $item);
			$path	= wpjam_if_error($path, $method == 'validate' ? 'throw' : null);

			if(is_null($path)){
				$backup	= str_ends_with($suffix, '_backup');

				if($multi && !$backup){
					return [$this, $method.'_item']($item, $suffix.'_backup');
				}

				return $method == 'validate' ? wpjam_invalid('page_key', '无效的'.($backup ? '备用' : '').'页面。') : ['type'=>'none'];
			}

			return is_array($path) ? $path : ['type'=>'', 'page_key'=>$page_key, 'path'=>$path];
		}elseif($this->mod($method, '_by_page_type')){
			$item	= array_last($args);
			$object	= wpjam_get_data_type(wpjam_pull($item, 'page_type'), $item);

			return $object ? [$object, $method](...$args) : null;
		}
	}

	public function verify(){
		return wpjam_call($this->verify);
	}

	public function get_tabbar($page_key=''){
		if(!$page_key){
			return wpjam_array($this->get_paths(), fn($k)=> [$k, $this->get_tabbar($k)], true);
		}

		if($tabbar	= $this->get_path($page_key.'[tabbar]')){
			return ($tabbar === true ? [] : $tabbar)+['text'=>(string)$this->get_path($page_key.'[title]')];
		}
	}

	public function get_page($page_key=''){
		return $page_key ? wpjam_at($this->get_path($page_key.'[path]'), '?', 0) : wpjam_array($this->get_paths(), fn($k)=> [$k, $this->get_page($k)], true);
	}

	public function get_fields($page_key){
		$item	= $this->get_path($page_key.'[]');
		$fields	= $item ? (!empty($item['fields']) ? maybe_callback($item['fields'], $item, $page_key) : $this->get_path_by_page_type('fields', $item)) : [];

		return $fields ?: [];
	}

	public function has_path($page_key, $strict=false){
		$item	= $this->get_path($page_key.'[]');

		return (!$item || ($strict && ($item['path'] ?? '') === false)) ? false : (isset($item['path']) || isset($item['callback']));
	}

	public function get_path($page_key, $args=[]){
		if(is_array($page_key)){
			[$page_key, $args]	= [wpjam_pull($page_key, 'page_key'), $page_key];
		}

		if(str_contains($page_key, '[')){
			return wpjam_get($this->get_paths(), wpjam_suffix($page_key, '-', '[]'));
		}

		if($item = $this->get_path($page_key.'[]')){
			$cb		= wpjam_pull($item, 'callback');
			$args	= is_array($args) ? wpjam_filter($args, '!null')+$item : $args;
			$path	= $cb ? (is_callable($cb) ? ($cb($args, $item) ?: '') : null) : $this->get_path_by_page_type($args, $item);

			return isset($path) ? $path : (isset($item['path']) ? (string)$item['path'] : null);
		}
	}

	public function get_paths($page_key=null, $args=[]){
		if($page_key){
			$item	= $this->get_path($page_key.'[]');
			$type	= $item ? ($item['page_type'] ?? '') : '';
			$items	= $type ? $this->query_items_by_page_type(array_merge($args, wpjam_pick($item, [$type])), $item) : [];

			return $items ? wpjam_array($items, fn($k, $v)=> [$k, wpjam_trap([$this, 'get_path'], $page_key, $v['value'], null)], true) : [];
		}

		return $this->get_arg('paths[]');
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

	protected static function get_defaults(){
		return [
			'weapp'		=> ['bit'=>1,	'order'=>4,		'title'=>'小程序',	'verify'=>'is_weapp'],
			'weixin'	=> ['bit'=>2,	'order'=>4,		'title'=>'微信网页',	'verify'=>'is_weixin'],
			'mobile'	=> ['bit'=>4,	'order'=>8,		'title'=>'移动网页',	'verify'=>'wp_is_mobile'],
			'template'	=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true']
		];
	}
}

class WPJAM_Path extends WPJAM_Args{
	public static function create($name, ...$args){
		$object	= static::get_instance($name) ?: new static(['name'=>$name]);

		[$pf, $args]	= count($args) > 1 ? $args : [array_find(wpjam_pull($args[0], ['platform', 'path_type']), fn($v)=> $v), $args[0]];

		$args	+= in_array(($args['page_type'] ?? ''), ['post_type', 'taxonomy']) ? [$args['page_type']=>$name] : [];

		foreach((array)$pf as $pf){
			if($platform = WPJAM_Platform::get($pf)){
				$platform->add_path($name, array_merge($args, ['platform'=>$pf, 'path_type'=>$pf, 'object'=>$object]));
			}

			$object->update_arg('platform[]', $pf)->update_args($args, false);
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($object = self::get_instance($name)){
			foreach($pf ? (array)$pf : $object->get_arg('platform[]') as $pf){
				if($platform = WPJAM_Platform::get($pf)){
					$platform->delete_path($name);
				}

				$object->delete_arg('platform[]', $pf);
			}

			return $pf ? $object : null;
		}
	}

	public static function get_by($args=[]){
		$type	= wpjam_pull($args, 'path_type');
		$args	+= $type ? ['platform'=>$type] : [];

		return wpjam_filter(array_reduce(WPJAM_Platform::get_by(), fn($c, $v)=> array_merge($c, array_column($v->get_paths(), 'object')), []), $args);
	}

	public static function get_instance($name){
		foreach(WPJAM_Platform::get_by() as $v){
			if($object = $v->get_path($name.'[object]')){
				return $object;
			}
		}
	}

	public static function __callStatic($method, $args){
		$platforms	= array_filter(WPJAM_Platform::get_by((array)(array_shift($args) ?? ['path'=>true])));
		$multi		= count($platforms) > 1;

		if(str_ends_with($method, '_item')){
			$args[]	= $multi;

			if($method == 'parse_item'){
				$platform	= $multi ? array_find($platforms, fn($v)=> $v->verify()) : array_first($platforms);

				return $platform ? [$platform, $method](...$args) : ['type'=>'none'];
			}

			return array_reduce($platforms, fn($c, $v)=> $c && $v->$method(...$args), true);
		}

		if(!$platforms){
			return [];
		}

		$args		= $args ? (is_array($args[0]) ? $args[0] : ['strict'=>$args[0]]) : [];
		$strict		= (bool)wpjam_pull($args, 'strict');
		$prepend	= array_filter(wpjam_pull($args, ['prepend_name']));
		$suffix		= wpjam_pull($args, 'suffix');
		$title		= wpjam_pull($args, 'title') ?: '页面';
		$key		= 'page_key'.$suffix;
		$paths		= static::get_by($args);
		$fields_key	= 'fields['.md5(serialize($prepend+['suffix'=>$suffix, 'strict'=>$strict, 'platforms'=>array_keys($platforms), 'page_keys'=>array_keys($paths)])).']';

		static $caches	= [];

		if(!empty($caches[$fields_key])){
			[$fields, $show_if]	= $caches[$fields_key];
		}else{
			$pks	= [$key=>['any', $suffix]]+($multi && !$strict ? [$key.'_backup'=>['all', $suffix.'_backup']] : []);
			$fields	= wpjam_map($pks, fn()=> ['tabbar'=>['title'=>'菜单栏/常用', 'options'=>($strict ? [] : ['none'=>'只展示不跳转'])]]);

			foreach($paths as $path){
				$i		= 0;
				$name	= $path->name;
				$group	= $path->group;

				[$group, $label]	= is_array($group) ? [$group['key'] ?? '', $group['title'] ?? ''] : [$group, ''];

				if(!$group){
					$group	= $path->tabbar ? 'tabbar' : 'others';
				}

				foreach($pks as $pk => [$cb, $fix]){
					if(('array_'.$cb)($platforms, fn($pf)=> $pf->has_path($name, $strict && $cb == 'any'))){
						$i++;

						if($label){
							$fields	= wpjam_set($fields, $pk.'['.$group.'][label]', $label);
						}

						$fields	= wpjam_set($fields, $pk.'['.$group.'][options]['.$name.']', [
							'label'		=> $path->title,
							'fields'	=> wpjam_array(array_reduce($platforms, fn($c, $pf)=> array_merge($c, $pf->get_fields($name)), []), fn($k, $v)=> [$k.$fix, wpjam_except($v, 'title')+$prepend])
						]);
					}
				}

				if($multi && !$strict && $i == 1){
					$show_if[]	= $name;
				}
			}

			$caches[$fields_key] = [
				wpjam_map($fields, fn($v)=> ($o = wpjam_pull($v, 'others')) ? $v+['others'=>$o+['label'=>'其他页面']] : $v),
				$show_if ?? []
			];
		}

		return wpjam_array($fields, fn($k, $v)=> [$k.'_set', ['type'=>'fieldset', 'fields'=>[$k=>['options'=>$v]+$prepend]]+($k != $key ? ['title'=>'备用'.$title, 'show_if'=>[$key, 'IN', $show_if]] : ['title'=>$title])]);
	}
}

class WPJAM_Hook extends WPJAM_Args{
	public function __invoke(...$args){
		if($this->check && !($this->check)(...$args)){	// del 2026-08-30
			trigger_error(var_export($this->check, true));
			return $args[0];
		}

		$value	= $args[0] ?? null;
		$hook	= $this->hook = current_filter();
		$args	= ($this->op || $this->maybe || $this->tap) ? wpjam_rotate($args, 1) : $args;
		$result	= $this->bind ? $this->call('callback', ...$args) : wpjam_call($this->callback, ...$args);
		$result	= $this->op ? wpjam_operate($value, $this->op, $result) : $result;

		if($this->once && !($this->maybe && (did_action($hook) ? $result === false : is_null($result)))){
			if($filter = $GLOBALS['wp_filter'][$hook] ?? null){
				unset($filter->callbacks[$filter->current_priority()][wpjam_callback('uniqid', $this)]);
			}
		}

		return $this->echo ? wpjam_echo($result) : ($this->tap ? $value : ($this->maybe ? ($result ?? $value) : $result));
	}

	public static function batch($action, $name, ...$args){
		if(is_array($name) || str_contains($name ?: '', ',')){
			return wpjam_call(
				$action === 'remove' ? 'wpjam_filter' : 'wpjam_map',
				array_filter(is_array($name) ? $name : wp_parse_list($name)),
				fn($n)=> self::batch($action, ...(is_array($n) ? $n : [$n, ...$args]))
			);
		}

		if($name && $args){
			$cb		= array_shift($args);
			$multi	= wp_is_numeric_array($cb) && !is_callable($cb);
			$result	= wpjam_map($multi ? $cb : [$cb], fn($cb)=> self::$action($name, $cb, ...$args));

			return $multi ? $result : $result[0];
		}
	}

	public static function add($name, $cb, ...$args){
		if(wpjam_is_assoc_array($cb)){
			$cb	= new static($cb);
		}else{
			if($attr = $name === 'once' ? [$name=>true] : []){
				$name	= $cb;
				$cb		= array_shift($args);
			}

			if($type = in_array($cb, ['+', '-', '.'], true) ? 'op' : (in_array($cb, ['tap', 'maybe', 'echo', 'bind'], true) ? $cb : '')){
				$attr	+= [$type=>$cb];
				$cb		= array_shift($args);
			}

			$cb	= is_callable($cb) ? $cb : fn()=> $cb;
			$cb	= $attr ? new static($attr+['callback'=>$cb]) : $cb;
		}

		add_filter($name, $cb, ...$args);

		return $cb;
	}

	public static function remove(...$args){
		return count($args) > 1 && ($args[2] ??= has_filter(...$args)) !== false && remove_filter(...$args);
	}

	public static function parse($name, $type){
		return (str_starts_with($name, 'wpjam') ? '' : 'wpjam_').str_replace('-', '_', $name).'_'.$type;
	}
}

class WPJAM_AJAX extends WPJAM_Args{
	public function callback(){
		add_filter('wp_die_ajax_handler', fn()=> 'wpjam_die_handler');

		$data	= wpjam_params('post');

		if(!$this->admin){
			$data	= array_merge(wpjam_params('data'), wpjam_except($data, ['action', 'defaults', 'data', '_ajax_nonce']));
		}

		if($fields = $this->fields){
			$data	= array_merge($data, wpjam_fields($fields)->validate($data, 'parameter'));
		}

		if(($this->verify ?? true) && ($action = $this->nonce_action($data)) && !check_ajax_referer($action, false, false)){
			wpjam_invalid('nonce', '验证失败，请刷新重试。');
		}

		if($this->allow && !wpjam_call($this->allow, $data)){
			wp_die('access_denied');
		}

		return wpjam_try($this->callback, $data, $this->name);
	}

	public function nonce_action($args){
		return ($cb	= $this->nonce_action)
		? $cb($args)
		: ($this->admin ? '' : $this->name.wpjam_join(':', wpjam_pick($args, $this->nonce_keys ?: [])));
	}

	public static function register($name, $args=[]){
		$object = wpjam_register(static::class, $name, $args);

		if(wp_doing_ajax()
			&& wpjam_param('action', 'request') === $name
			&& ($part = is_user_logged_in() ? '' : ($object->nopriv ? 'nopriv_' : false)) !== false
		){
			add_action('wp_ajax_'.$part.$name, fn()=> wpjam_send_json(wpjam_catch([$object, 'callback'])));
		}

		return $object;
	}

	public static function get($name){
		return wpjam_get_registered(static::class, $name);
	}

	public static function add_hooks(){
		wpjam_script('wpjam-ajax', [
			'for'		=> 'wp,login',
			'src'		=> wpjam_url(dirname(__DIR__).'/static/ajax.js'),
			'deps'		=> ['jquery'],
			'data'		=> 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";',
			'position'	=> 'before',
			'priority'	=> 1
		]);
	}
}

class WPJAM_File extends WPJAM_Args{
	public function __invoke($value, $to, $from=null){
		$from	??= is_numeric($value) ? 'id' : 'file';

		return $from == $to	? $value : ($from == 'id' ? $this->from_id($value, $to) : $this->convert($value, $to, $from));
	}

	protected function convert($value, $to, $from){
		if(!$value){
			return;
		}

		if(in_array($to, ['size', 'ext'])){
			$file	= $from == 'file' ? $value : $this->convert($value, 'file', $from);

			if(!$file){
				return;
			}

			if($to == 'ext'){
				return pathinfo($file, PATHINFO_EXTENSION);
			}

			return file_exists($file) && ($size = wp_getimagesize($file))
			? ['width'=>$size[0], 'height'=>$size[1]]
			: $this->from_id($this->convert($file, 'id', 'file'), 'size');
		}

		if($path = $this->to_path($value, $from)){
			return $to == 'id' ? wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id') : (['url'=>$this->baseurl,'file'=>$this->basedir][$to] ?? '').$path;
		}
	}

	protected function to_path($value, $from='file'){
		if($from == 'url'){
			if(!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')){
				return;
			}

			$value	= parse_url($value, PHP_URL_PATH) ?: '';
			$base	= parse_url($this->baseurl, PHP_URL_PATH) ?: '';
		}else{
			$base	= $from == 'file' ? $this->basedir : null;
		}

		return isset($base) && !try_prefix($value, '-', $base) ? null : $value;
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
		$file	= $this->from_id($id, 'file') ?: wpjam_invalid('attachment_id');

		if(!is_dir(dirname($file))){
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
		$types	= get_allowed_mime_types();
		$mimes	= [];

		foreach(wpjam_lines($accept ?: '', ',', fn($v)=> strtolower($v)) as $v){
			if(str_contains($v, '/')){
				$mimes	+= wpjam_filter($types, ['@value'=>['LIKE'=>str_ends_with($v, '/*') ? substr($v, 0, -1).'%' : $v]]);
			}elseif(($v = ltrim($v, '.')) && !str_contains($v, '|')){
				$mimes	+= wpjam_pick($types, (array)array_find_key($types, fn($a, $e)=> str_contains('|'.$e.'|', '|'.$v.'|')));
			}
		}

		return $mimes;
	}

	public function check($file, $mimes){
		$res	= wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);

		return (!empty($res['type']) && !empty($res['ext'])) || current_user_can('unfiltered_upload');
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
			$arr	= is_array($name) ? $name : ($_FILES[$name] ?? wpjam_invalid('parameter', [$name]));
			$act	= is_array($name) ? 'sideload' : 'upload';

			if(wpjam_pull($args, 'media')){
				require_once ABSPATH.'wp-admin/includes/media.php';
				require_once ABSPATH.'wp-admin/includes/image.php';

				if(!empty($args['mimes']) && !$this->check($arr, $args['mimes'])){
					wpjam_throw('upload_error', '不允许的文件类型');
				}

				return wpjam_try('media_handle_'.$act, $name, $args['post_id'] ?? 0);
			}

			$upload	= wpjam_call('wp_handle_'.$act, $arr, $args+['test_form'=>false]);
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
				if(isset($tmp)){
					@unlink($tmp);
				}

				return wpjam_catch($e);
			}
		}

		return $this->from_id($result, $field);
	}

	public function parse($file, $type='data'){
		$data	= $file ? array_reduce(['URI', 'Name'], fn($c, $k)=> wpjam_set($c, $k, ($c[$k] ?? '') ?: ($c['Plugin'.$k] ?? '')), get_file_data($file, [
			'Admin'			=> 'Admin',
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		])) : [];

		return $type == 'summary' ? ($data ? str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。' : '') : $data;
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self(wp_get_upload_dir());
	}
}

class WPJAM_Qrcode extends WPJAM_Args{
	public function cache($action, ...$args){
		return [$this->handler, 'cache_'.$action](...$args);
	}

	public function verify($scene, $code){
		$qrcode	= $this->cache('get', $scene.'_scene');

		if(!$qrcode || !$code || empty($qrcode['openid']) || $code != $qrcode['code']){
			wpjam_invalid($qrcode ? 'code' : 'qrcode');
		}

		$this->cache('delete', $scene.'_scene');

		return $qrcode;
	}

	public function scan($scene, $openid){
		$qrcode	= $this->cache('get', $scene.'_scene');

		if(!$qrcode || (!empty($qrcode['openid']) && $qrcode['openid'] != $openid)){
			wpjam_invalid('qrcode');
		}

		$this->cache('delete', $qrcode['key'].'_qrcode');

		if(!empty($qrcode['id']) && ($cb = $qrcode['bind_callback'] ?? '') && is_callable($cb)){
			return $cb($openid, $qrcode['id']);
		}

		$this->cache('set', $scene.'_scene', ['openid'=>$openid]+$qrcode, 1200);

		return $qrcode['code'];
	}

	public function create($key, $args=[]){
		return $this->cache('get', $key.'_qrcode') ?: wpjam_tap(wpjam_call($this->creator, $key, $args), fn($v)=> wpjam_each([
			$key.'_qrcode',
			$v['scene'].'_scene'
		], fn($k)=> $this->cache('set', $k, $v, 1200)));
	}

	public function get_fields($action='login'){
		if($action == 'bind'){
			$args	= ['id'=>get_current_user_id()];	
			$args	= [md5('bind_'.$args['id']), $args];
		}else{
			$args	= [wp_generate_password(32, false, false)];
		}

		return wpjam_then($this->create(...$args), fn($v)=> [
			'qrcode'	=> ['type'=>'view',		'title'=>$this->title,	'value'=>'<img src="'.(($v['qrcode_url'] ?? '') ?: ($v[ 'qrcode'] ?? '')).'" width="272" />'],
			'code'		=> ['type'=>'number',	'title'=>'验证码',	'class'=>'input',	'required', 'size'=>20],
			'scene'		=> ['type'=>'hidden',	'value'=>$v['scene']]
		]);
	}
}

class WPJAM_Widget extends WP_Widget{
	public function __construct($id, $name, $options){
		parent::__construct($id, $name, $options+['show_instance_in_rest'=>false]);
	}

	public function widget($args, $instance){
		$widget	= $this->widget_options['widget'];

		if(!is_blank($output = $widget($instance))){
			$title	= wpjam_pull($instance, 'title');
			$output	= (!is_blank($title) ? $args['before_title'].$title.$args['after_title'] : '').$output;

			echo $args['before_widget'].$output.$args['after_widget'];
		}
	}

	public function form($instance){
		echo str_replace(['<fieldset ', '<fieldset>', '</fieldset>'], ['<span ', '<span>', '</span>'], wpjam_fields(wpjam_map(maybe_callback($this->widget_options['fields']), fn($v, $k)=> $v+[
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
