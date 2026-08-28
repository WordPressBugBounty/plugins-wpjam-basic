<?php
trait WPJAM_Call_Trait{
	public function call($name, ...$args){
		if(is_string($name) && static::mod($name, '_by_model')){
			$cb	= [$this->model, $name];
		}else{
			$cb	= is_closure($name) ? $name : $this->$name;
			$cb	= is_closure($cb) ? $cb->bindTo($this, $this) : $cb;
		}

		return wpjam_call($cb, ...$args);
	}

	public function try($name, ...$args){
		return wpjam_try([$this, $name], ...$args);
	}

	public function catch($name, ...$args){
		return wpjam_catch([$this, $name], ...$args);
	}

	protected function call_dynamic_method($name, ...$args){
		return $this->call(wpjam_dynamic_method(static::class, $name), ...$args);
	}

	public static function add_dynamic_method($name, $closure){
		wpjam_dynamic_method(static::class, $name, $closure);
	}

	public static function mod(&$method, $mod){
		if(str_ends_with($mod, '_')){
			return try_prefix($method, '-', $mod);
		}elseif(str_starts_with($mod, '_')){
			return try_suffix($method, '-', $mod);
		}

		if(($action = str_replace('_'.$mod.'_', '_', $method)) != $method){
			$method	= $action;
			return true;
		}

		return static::mod($method, $mod.'_') || static::mod($method, '_'.$mod);
	}

	protected static function get_param($name='', $method='data'){
		return wpjam_param($name, $method);
	}

	public static function get_called(){
		return strtolower(static::class);
	}
}

trait WPJAM_Items_Trait{
	use WPJAM_Call_Trait;

	public function get_items($field=''){
		return $field == ':field'
		? (wpjam_get_annotation(static::class, 'items_field') ?: '_items')
		: ($this->{$field ?: $this->get_items(':field')} ?: []);
	}

	public function update_items($items, $field=''){
		$this->{$field ?: $this->get_items(':field')}	= $items;

		return $this;
	}

	public function process_items($cb, $field='', ...$args){
		$field	= $field ?: $this->get_items(':field');
		$items	= $this->get_items($field);

		try{
			if(count($args) == 3){
				[$item, $key, $action]	= $args;

				$args[]	= $field;
				$add	= $action === 'add';

				if(isset($key) ? wpjam_exists($items, $key) === $add : !$add){
					wpjam_throw('invalid_item_key', isset($key) ? '「'.$key.'」'.($add ? '已' : '不').'存在，无法'.(['add'=>'添加', 'update'=>'编辑', 'delete'=>'删除'][$action] ?? '操作') : 'key不能为空');
				}

				if(isset($item)){
					if($add && ($max = wpjam_get_annotation(static::class, 'max_items')) && count($items) >= $max){
						wpjam_throw('quota_exceeded', '最多'.$max.'个');
					}

					if(method_exists($this, 'validate_item')){
						wpjam_if_error($this->validate_item(...$args), 'throw');
					}

					if(method_exists($this, 'sanitize_item')){
						$args[0]	= $this->sanitize_item(...$args);
					}
				}
			}

			return $this->update_items(wpjam_call($cb, $items, ...$args), $field);
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public function get_item($key, $field=''){
		return wpjam_get($this->get_items($field), $key);
	}

	public function get_item_arg($key, $arg, $field=''){	// deprecated
		return $this->get_item($key.'.'.$arg, $field);
	}

	public function add_item($key, ...$args){
		[$key, $item]	= ($args && !is_bool($key) && (is_scalar($key) || is_null($key))) ? [$key, array_shift($args)] : [null, $key];

		return $this->process_items(fn($items, $item)=> wpjam_add_at($items, count($items), $key, $item), $args[0] ?? '', $item, $key, 'add');
	}

	public function add_items($items, $field=''){
		return wpjam_map($items, fn(...$args)=> $this->add_item(...[...array_pad($args, -2, null), $field]), wp_is_numeric_array($items) ? 'v' : 'kv');
	}

	public function remove_item($item, $field=''){
		return $this->process_items(fn($items)=> array_diff($items, [$item]), $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field);
	}

	public function update_item($key, $item, $field='', $action='update'){
		return $this->process_items(fn($items, $item)=> array_replace($items, [$key=>$item]), $field, $item, $key, $action);
	}

	public function set_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field, 'set');
	}

	public function delete_item($key, $field=''){
		return wpjam_tap(
			$this->process_items(fn($items)=> wpjam_except($items, $key), $field, null, $key, 'delete'),
			fn()=> wpjam_call_method($this, 'after_delete_item', $key, $field)
		);
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		return $this->process_items(fn($items)=> array_merge(wpjam_pull($items, $orders), $items), $field);
	}

	public static function get_item_actions(){
		$args	= [
			'row_action'	=> false,
			'value_callback'=> fn()=> '',
			'data_callback'	=> fn($id)=> wpjam_try([static::class, 'get_item'], $id, static::get_param('i'), static::get_param('_field')),
			'callback'		=> fn($id, $data, $action)=> wpjam_try([static::class, $action], $id, static::get_param($action == 'move_item' ? 'item' : 'i'), ...[...($action == 'del_item' ? [] : [$data]), static::get_param('_field')])
		];

		return [
			'add_item'	=>['page_title'=>'新增项目',	'title'=>'新增',	'dismiss'=>true]+array_merge($args, ['data_callback'=> fn()=> []]),
			'edit_item'	=>['page_title'=>'修改项目',	'dashicon'=>'edit']+$args,
			'del_item'	=>['page_title'=>'删除项目',	'dashicon'=>'no-alt',	'class'=>'del-icon',	'direct'=>true,	'confirm'=>true]+$args,
			'move_item'	=>['page_title'=>'移动项目',	'dashicon'=>'move',		'class'=>'move-item',	'direct'=>true]+$args,
		];
	}
}

class WPJAM_Args implements ArrayAccess, IteratorAggregate, JsonSerializable{
	use WPJAM_Call_Trait;

	protected $args;

	public function __construct($args=[]){
		$this->args	= $args;
	}

	public function __get($key){
		$args	= $this->get_args();

		return wpjam_exists($args, $key) ? $args[$key] : ($key == 'args' ? $args : null);
	}

	public function __set($key, $value){
		$this->filter_args();

		$this->args[$key]	= $value;
	}

	public function __isset($key){
		return wpjam_exists($this->get_args(), $key) ?: ($this->$key !== null);
	}

	public function __unset($key){
		$this->filter_args();

		unset($this->args[$key]);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->__get($key);
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->__set($key, $value);
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return $this->__isset($key);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->__unset($key);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_args());
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize(){
		return $this->get_args();
	}

	protected function filter_args(){
		return $this->args	= $this->args ?: [];
	}

	public function get_args(){
		return $this->filter_args();
	}

	public function update_args($args, $replace=null){
		$this->args	= $replace === true ? $args : ($replace === false ? 'wp_parse_args' : 'array_replace')($this->get_args(), $args);

		return $this;
	}

	public function process_arg($key, $cb){
		$value	= wpjam_call($cb, $this->get_arg($key));

		return is_null($value) ? $this->delete_arg($key) : $this->update_arg($key, $value);
	}

	public function get_arg($key, $default=null, $callback=false){
		$value	= wpjam_get($this->get_args(), $key);

		if($callback){
			$value	??= is_string($key) ? wpjam_callback([$this->model, 'get_'.$key]) : null;
			$value	= is_closure($value) ? $value->bindTo($this, $this) : $value;
			$value	= $callback === 'callback' ? maybe_callback($value, $this->name) : $value;
		}

		return $value ?? $default;
	}

	public function update_arg($key, $value){
		return $this->update_args(wpjam_set($this->get_args(), $key, $value), true);
	}

	public function delete_arg($key, ...$args){
		return $this->update_args(wpjam_except($this->get_args(), $key, ...$args), true);
	}

	public function pull($key, ...$args){
		$this->filter_args();

		return wpjam_pull($this->args, $key, ...$args);
	}

	public function pick($keys){
		return wpjam_pick($this, $keys);
	}

	public function to_array(){
		return $this->get_args();
	}

	public function sandbox($cb, ...$args){
		try{
			$archive	= $this->get_args();

			return wpjam_call($cb, ...$args);
		}finally{
			$this->args	= $archive;
		}
	}

	public function is_active(){
		return true;
	}

	protected function parse_method($name){
		$cb	= array_find([[$this->model, $name], $this->$name], fn($v)=> wpjam_callback($v));

		return is_closure($cb) ? $cb->bindTo($this, $this) : $cb;
	}

	public function call_method($name, ...$args){
		return ($cb = $this->parse_method($name)) ? wpjam_try($cb, ...$args) : (str_starts_with($name, 'filter_') ? array_shift($args) : null);
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function __construct($name, $args=[]){
		$this->args	= $args	= ['name'=>$name]+$args;

		if($this->is_active() || !empty($args['active'])){
			$config	= static::registry('get_arg', 'config') ?: [];
			$model	= empty($config['model']) ? '' : ($args['model'] ?? '');
			$file	= ($model || !empty($args['hooks']) || !empty($args['init'])) ? wpjam_pull($args, 'file') : '';

			$file && is_file($file) && include_once $file;

			if($model){
				if(is_subclass_of($model, self::class)){
					trigger_error('「'.(is_object($model) ? get_class($model) : $model).'」是 '.self::class.' 子类');
				}

				if($config['model'] === 'object' && !is_object($model)){
					if(class_exists($model, true)){
						$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
					}else{
						trigger_error('model 无效');
					}
				}

				foreach(['hooks'=>'add_hooks', 'init'=>'init'] as $k => $m){
					if(($args[$k] ?? ($k == 'hooks' || ($config[$k] ?? false))) === true){
						$args[$k] = wpjam_callback([$model, $m]);
					}
				}
			}
		}

		$this->args	= $args;
	}

	protected function filter_args(){
		$name	= $this->args['name'];

		return in_array($name, static::registry('get_arg', 'filtered[]'))
		? $this->args
		: ($this->args = apply_filters(static::registry('update_arg', 'filtered[]', $name)->name.'_args', $this->args, $name));
	}

	public function get_arg($key, $default=null, $callback=true){
		return parent::get_arg($key, $default, $callback ? 'callback' : 'parse');
	}

	public function get_parent(){
		return $this->sub_name ? self::get($this->name) : null;
	}

	public function get_sub($name){
		return self::get($this->name.':'.$name);
	}

	public function get_subs(){
		return wpjam_array(self::get_by(['name'=>$this->name]), fn($k, $v)=> $v->sub_name);
	}

	public function register_sub($name, $args){
		return self::register($this->name.':'.$name, new static($this->name, array_merge($args, ['sub_name'=>$name])));
	}

	public function unregister_sub($name){
		return self::unregister($this->name.':'.$name);
	}

	public static function registry($method, ...$args){
		$called		= static::class;
		$registry	= wpjam_registry($called);

		if(!$registry->config){
			$registry->config	= wpjam_get_annotation($called, 'config')+['model'=>true];
			$registry->defaults	= method_exists($called, 'get_defaults') ? static::get_defaults() : [];
		}

		return wpjam_catch([$registry, $method], ...$args);
	}

	public static function register($name, $args=[]){
		return static::registry('add_object', $name, $args);
	}

	public static function unregister($name, $args=[]){
		static::registry('remove_object', $name, $args);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		wpjam_map(static::registry('pull', 'defaults'), [static::class, 'register'], 'kv');

		$objects	= static::registry('get_objects', $args, $operator);

		return $output == 'names' ? array_keys($objects) : $objects;
	}

	public static function get_by(...$args){
		return self::get_registereds(...((!$args || is_array($args[0])) ? $args : [[$args[0]=> $args[1]]]));
	}

	public static function get($name, $by='registered', $top=''){
		if($name && $by == 'registered'){
			return static::registry('get_object', $name) ?: static::register($name, static::registry('pull', 'defaults['.$name.']'));
		}

		if($name && $by == 'model' && strcasecmp($name, $top) !== 0){
			return array_find(static::get_registereds(), fn($v)=> is_string($v->model) && strcasecmp($name, $v->model) === 0) ?: static::get(get_parent_class($name), $by, $top);
		}
	}

	public static function get_setting_fields($args=[]){
		$objects	= array_filter(static::get_registereds(wpjam_pull($args, 'filter_args')), fn($v)=> !isset($v->active));
		$options	= wpjam_options($objects, $args);

		if(($args['type'] ?? '') == 'select'){
			$name	= wpjam_pull($args, 'name');
			$args	+= ['options'=>$options];

			return $name ? [$name => $args] : $args;
		}

		return $options;
	}

	public static function get_active($key=null){
		return wpjam_array(static::get_registereds(), fn($k, $v)=> ($v->active ?? $v->is_active()) ? [$k, $key ? $v->get_arg($key) : $v] : null, true);
	}

	public static function by_active(...$args){
		return self::call_active((did_action(current_filter()) ? 'on_' : 'filter_').wpjam_suffix(current_filter(), '-', 'wpjam_'), ...$args);
	}

	public static function call_active($method, ...$args){
		$type	= array_find(['filter', 'get'], fn($t)=> str_starts_with($method, $t.'_'));

		foreach(static::get_active() as $object){
			$res	= $object->call_method($method, ...$args);

			if($type == 'filter'){
				$args[0]	= $res;
			}elseif($type == 'get'){
				$return		= array_merge($return ?? [], is_array($res) ? $res : []);
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}
}

class WPJAM_Registry extends WPJAM_Args{
	public function add_object(...$args){
		$count	= count($this->objects ?: []);
		$called	= $this->called ?: 'WPJAM_Args';

		if(is_object($args[0]) || is_array($args[0])){
			$v	= $args[0];
			$k	= is_object($v) ? $v->name : (wpjam_pull($v, 'name') ?: ($args[1] ?: '__'.$count));
		}else{
			[$k, $v]	= $args;
		}

		if(is_null($v)){
			return;
		}

		if($e = $k ? (is_numeric($k) ? '「'.$k.'」为纯数字' : (is_string($k) ? '' : '「'.var_export($k, true).'」不为字符串')) : '为空'){
			return trigger_error($this->name.'的注册 name'.$e) && false;
		}

		if(is_array($v)){
			if(!empty($v['admin']) && !is_admin()){
				return;
			}

			$v	= new $called(...(is_subclass_of($called, 'WPJAM_Register') ? [$k, $v] : [$v+['name'=>$k]]));
		}

		$this->get_object($k) && trigger_error($this->name.'「'.$k.'」已经注册。');

		$this->update_arg('objects['.$k.']', $v);

		if(wpjam_call_method($v, 'is_active') || $v->active){
			wpjam_hooks(maybe_callback($v->pull('hooks')));

			wpjam_init($v->pull('init'));

			wpjam_call_method($v, 'registered');
		}

		$count == 0 && wpjam_hooks($called.'::add_hooks');

		return $v;
	}

	public function remove_object($k){
		$this->delete_arg('objects['.$k.']');
	}

	public function get_object($k){
		return $this->get_arg('objects['.$k.']');
	}

	public function get_objects(...$args){
		$objects	= wpjam_filter($this->objects ?: [], ...$args);
		$by			= $this->get_arg('config[orderby]');

		return $by ? wpjam_sort($objects, ($by === true ? 'order' : $by), ($this->get_arg('config[order]') ?? 'DESC'), 10) : $objects;
	}

	public function on(...$args){
		foreach($this as $name => $registry){
			foreach(current_action() == 'wpjam_api' ? ['register_json'] : ['menu_page', 'admin_load'] as $key){
				if($registry->get_arg('config['.$key.']')){
					if($key == 'register_json'){
						[$registry->called, 'call_active']('register_json', $args[0]);
					}else{
						wpjam_add($key, array_values([$registry->called, 'get_active']($key)));
					}
				}
			}
		}
	}

	public static function get_instance($name, $args=[]){
		static $object;
		$object	??= new static();

		if(!$object->get_args()){
			wpjam_hooks('wpjam_api, wpjam_admin_init', [$object, 'on']);
		}

		return $object->$name ??= new static(['name'=>$name, 'called'=> is_subclass_of($name, 'WPJAM_Args') ? $name : null]+$args);
	}
}

class WPJAM_Data_Type extends WPJAM_Args{
	public function get_path($args, $item){
		return wpjam_if_error(wpjam_call_method($this->model.'::get_path', $args, $item), 'throw');
	}

	public function with_field($action, $value, $field){
		$res	= ($cb = $this->model.'::with_field') && wpjam_callback($cb) ? wpjam_try($cb, $action, $field, $value) : ($this->{$action.'_value'} ? $this->call($action.'_value', $value, $field) : $value);

		return $action == 'validate' && is_null($res) ? wpjam_throw('invalid_field_value', $field->_title.'「'.$value.'」的值无效') : $res;
	}

	public function query_label($value){
		if($value && $this->model && $this->label_field){
			if(is_array($value)){
				wpjam_call_method($this->model.'::update_caches', $value);

				return array_map(fn($v)=> ($l = $this->query_label($v)) ? ['label'=>$l, 'value'=>$v] : $v, $value);
			}

			return ($this->model::get($value) ?: [])[$this->label_field] ?? null;
		}
	}

	public function query_items($args){
		$args	= array_filter($args ?: [], fn($v)=> !is_null($v))+['number'=>10, 'data_type'=>true];

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
		return $this->name.':'.wpjam_json_encode(wpjam_sort(wpjam_except($args, ['search', 'exclude', 'parent']), 'k'));
	}

	public static function register($name, $args=[]){
		return wpjam_register(static::class, $name, $args);
	}

	public static function get_instance($name, $args=[]){
		$model	= '';

		if($name == 'model'){
			$model	= $args[$name] ?? '';
			$name	= $model && class_exists($model) ? wpjam_join(':', $model, $args['label_field'], $args['id_field']) : '';
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

class WPJAM_JSON extends WPJAM_Register{
	public function __invoke(){
		$method		= $this->method ?: $_SERVER['REQUEST_METHOD'];
		$attr		= $method != 'POST' && !str_ends_with($this->name, '.config') ? ['page_title', 'share_title', 'share_image'] : [];
		$response	= wpjam_try('apply_filters', 'wpjam_pre_json', [], $this, $this->name);
		$response	+= ['errcode'=>0, 'current_user'=>wpjam_try('wpjam_get_current_user', $this->pull('auth'))]+$this->pick($attr);

		if($this->modules){
			$modules	= maybe_callback($this->modules, $this->name, $this->args);
			$results	= array_map([static::class, 'parse_module'], wp_is_numeric_array($modules) ? $modules : [$modules]);
		}elseif($this->callback){
			$fields		= wpjam_try('maybe_callback', $this->fields ?: [], $this->name);
			$data		= $this->fields ? ($fields ? wpjam_params($method, $fields) : []) : $this->args;
			$results[]	= wpjam_try($this->pull('callback'), $data, $this->name);
		}elseif($this->template){
			$results[]	= is_file($this->template) ? include $this->template : '';
		}else{
			$results[]	= wpjam_except($this->args, 'name');
		}

		foreach($results as $v){
			if(is_array($v)){
				$response	+= wpjam_pull($v, $attr);
				$response	= array_merge($response, $v);
			}
		}

		$response	= apply_filters('wpjam_json', $response, $this->args, $this->name);

		foreach($attr as $k){
			if(($v = $response[$k] ?? '') || $k != 'share_image'){
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

		if(!try_prefix($name, '-', 'mag.')){
			return;
		}

		$name	= wpjam_prefix($name, '-', 'mag.');	// 兼容
		$name	= str_replace('/', '.', $name);

		($user = wpjam_get_current_user()) && ($user_id = $user['user_id'] ?? '') && wp_set_current_user($user_id);

		do_action('wpjam_api', wpjam_var('json', $name));

		wpjam_send_json(wpjam_catch(self::get($name) ?: wp_die('接口未定义', 'invalid_api')));
	}

	public static function get_defaults(){
		return array_fill_keys(['post.list', 'post.calendar', 'post.get'], ['modules'=>[static::class, 'callback']])+[
			'media.upload'	=> ['modules'=>['callback'=>[static::class, 'media']]],
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

	public static function parse_module($module){
		$type	= wpjam_pull($module, 'type');
		$cb		= wpjam_pull($module, 'callback');
		$args	= $module['args'] ?? $module;
		$args	= is_array($args) ? $args : wpjam_parse_shortcode_attr(stripslashes_deep($args), 'module');

		if($cb){
			return wpjam_try($cb, $args);
		}

		if($type == 'data_type'){
			return wpjam_query('data_type', $args+['search'=>wpjam_param('s')]);
		}elseif($type == 'setting'){
			return wpjam_setting($args);
		}elseif($type == 'config'){
			return wpjam_config($args['group'] ?? '');
		}elseif(in_array($type, ['post_type', 'taxonomy', 'media'])){
			return wpjam_try(static::class.'::'.$type, $args);
		}
	}

	public static function post_type($args){
		$action	= wpjam_pull($args, 'action');
		$output	= wpjam_pull($args, 'output');

		if($action == 'upload'){
			return wpjam_media($args['media'] ?? '', ['media'=>true, 'output'=>$output, 'post_id'=>(int)wpjam_param('post_id', 'post')]);
		}

		$wp		= $GLOBALS['wp'];
		$query	= $GLOBALS['wp_query'];
		$object	= wpjam_get_json('object');
		$vars	= $object->query_vars ??= $wp->query_vars;

		if(in_array($action, ['list', 'calendar'])){
			$sub	= $action == 'calendar' ? false : wpjam_pull($args, 'sub');
			$args	= array_diff_key($args, WPJAM_Post::get_default_args());

			if($sub){
				$query	= wpjam_query($args);
			}else{
				$vars	= array_merge(wpjam_except($vars, ['module', 'action']), $args);
				$args	+= ['format'=>($action == 'calendar' ? 'date' : $action)];

				$wp->query_vars	= $vars = wpjam_parse_query_vars($vars, $action);
				$wp->query_posts();
			}

			$parsed	= wpjam_get_posts($query, $args);
			$result	= [];

			if(!$sub && $action != 'calendar'){
				$is		= wpjam_is($query);
				$paged	= $query->get('nopaging') ? 0 : ($query->get('paged') ?: 1);
				$result	= (is_string($is) ? ['is'=>$is] : [])+['total'=>$query->found_posts];
				$result	+= ['total_pages'=>$paged ? $query->max_num_pages : ($result['total'] ? 1 : 0), 'current_page'=>$paged ?: 1];

				if($paged == 1 && !$query->get('s') && in_array($query->get('orderby') ?: 'date', ['date', 'post_date'], true)){
					$result	+= ['next_cursor'=>($parsed && $result['total_pages'] > 1) ? array_last($parsed)['timestamp'] : 0];
				}

				if($queried	= $query->get_queried_object()){
					if(in_array($is, ['category', 'tag', 'tax'], true)){
						$result	+= ['current_taxonomy'=>$queried->taxonomy, 'current_term'=>wpjam_get_term($queried, $queried->taxonomy)];
					}elseif($is === 'author'){
						$result	+= ['current_author'=>wpjam_get_user($query->get('author'))];
					}elseif($is === 'post_type_archive'){
						$result	+= ['current_post_type'=>$queried->name];
					}
				}
			}

			if(!$output && !$sub && ($type = $query->get('post_type')) && is_string($type)){
				$output	= wpjam_get_post_type_setting($type, 'plural');
			}

			$output	= $output ?: 'posts';

			return apply_filters('wpjam_posts_json', $result+[$output=>$parsed], $query, $output);
		}elseif($action == 'get'){
			$type	= $args['post_type'] ??= '';
			$type	= $type === 'any' ? '' : $type;
			$status	= $args['post_status'] ?? '';
			$vars	= ['cache_results'=>true]+($status ? ['status'=>$status] : [])+$vars;

			if($type){
				!post_type_exists($type) && wp_die('invalid_post_type');

				$name	= $vars[(is_post_type_hierarchical($type) ? 'pagename' : 'name')] ?? '';
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

	public static function taxonomy($args){
		$object		= wpjam_get_taxonomy(wpjam_get($args, 'taxonomy')) ?: wpjam_throw('invalid_taxonomy');
		$mapping	= wpjam_array(wp_parse_args(wpjam_pull($args, 'mapping') ?: []), fn($k, $v)=> [$k, wpjam_param($v)], true);

		return [(wpjam_pull($args, 'output') ?: $object->plural) => array_values(wpjam_query('term', array_merge($args, $mapping)))];
	}

	public static function media($args){
		return wpjam_media($args['media'] ?? '', $args);
	}

	public static function callback($name, $args=[]){
		$output	= $args['output'] ?? null;

		[$type, $action]	= explode('.', $name);

		if($type == 'post'){
			$type	= wpjam_param('post_type');
			$output	??= $action == 'get' ? 'post' : 'posts';
		}

		$modules[]	= array_filter(['type'=>'post_type', 'post_type'=>$type, 'action'=>$action, 'output'=>$output]+array_intersect_key($args, WPJAM_Post::get_default_args()), fn($v)=> !is_null($v));

		foreach($action == 'list' && $type && is_string($type) && !str_contains($type, ',') ? get_object_taxonomies($type) : [] as $tax){
			if(is_taxonomy_hierarchical($tax) && wpjam_get_taxonomy_setting($tax, 'show_in_posts_rest')){
				$modules[]	= ['type'=>'taxonomy', 'taxonomy'=>$tax, 'hide_empty'=>0];
			}
		}

		return $modules;
	}

	public static function add_hooks(){
		wpjam_is_json_request() && wpjam_hooks('-', [
			['the_title',		'convert_chars'],
			['init',			['wp_widgets_init', 'maybe_add_existing_user_to_blog']],
			['wp_loaded',		['_custom_header_background_just_in_time', '_add_template_loader_filters']],
			['plugins_loaded',	['wp_maybe_load_widgets', 'wp_maybe_load_embeds', '_wp_customize_include', '_wp_theme_json_webfonts_handler']]
		]);
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['parse_post_list_module', 'parse_post_get_module'])){
			return wpjam_catch('WPJAM_JSON::parse_module', [
				'type'		=> 'post_type',
				'action'	=> str_replace(['parse_post_', '_module'], '', $method)
			]+($args[0] ?? []));
		}
	}
}

/**
* @config menu_page, admin_load, register_json, init, orderby
**/
#[config('menu_page', 'admin_load', 'register_json', 'init', 'orderby')]
class WPJAM_Option_Setting extends WPJAM_Register{
	public function __invoke(){
		flush_rewrite_rules();

		$submit	= $_POST['submit'] ?? '';
		$values	= $this->validate_by_fields(wpjam_params('data')) ?: [];
		$fix	= is_network_admin() ? 'site_option' : 'option';

		if($this->option_type == 'array'){
			if($submit == 'reset'){
				$values	= wpjam_diff([$this, 'get_'.$fix](), $values, 'key');
			}else{
				$values	= wpjam_filter(array_merge([$this, 'get_'.$fix](), $values), fn($v)=> !is_null($v), true);
				$values	= $this->call_method('sanitize_callback', $values, $this->name) ?? $values;
			}

			$cb	= $this->update_callback;
			$cb ? $cb($this->name, $values) : [$this, 'update_'.$fix]($values);
		}else{
			wpjam_map($values, ...($submit == 'reset' ? ['delete_'.$fix, 'k'] : ['update_'.$fix, 'kv']));
		}

		$errors	= array_filter(get_settings_errors(), fn($e)=> !in_array($e['type'], ['updated', 'success', 'info']));
		$errors	&& wp_die(implode('&emsp;', array_column($errors, 'message')));

		return [
			'type'		=> (!$this->ajax || $submit == 'reset') ? 'redirect' : $submit,
			'notice'	=> $submit == 'reset' ? '设置已重置。' : '设置已保存。'
		];
	}

	public function __call($method, $args){
		$parts	= explode('_', $method);
		$action	= array_shift($parts);
		$type	= array_pop($parts);

		if($type == 'fields'){
			$fields	= $action == 'render' ? array_shift($args) : array_merge(...array_column($this->get_sections($action !== 'validate'), 'fields'));

			return $action == 'get' ? $fields : wpjam_fields($fields, ['value_callback'=>[$this, 'value_callback']])->$action(...$args);
		}elseif($type == 'option' || $type == 'setting'){
			$part	= $parts ? '_'.$parts[0] : '';

			if($part != '_site'){
				$blog_id	= $part == '_blog' ? array_shift($args) : $this->blog_id;
				$part		= $blog_id && is_multisite() ? '_blog' : '';
			}

			$params	= $part == '_blog' ? [$blog_id] : [];

			if($type == 'option'){
				$args	= $action == 'update' ? [wpjam_if_error($args[0], [])] : [];
				$res	= wpjam_call($action.$part.'_option', ...[...$params, $this->name, ...$args]);

				return $action == 'get' ? (wpjam_if_error($res, []) ?: []) : $res;
			}

			$name	= $args[0] ?? null;
			$data	= [$this, 'get'.$part.'_option'](...$params) ?: [];

			if($action == 'get'){
				if(is_array($name)){
					return wpjam_fill(array_filter($name), fn($n)=> [$this, 'get'.$part.'_setting'](...[...$params, $n]));
				}

				$site_default	= $part != '_site' && is_multisite() && $this->site_default;

				if(!$name){
					return $data+($site_default ? $this->get_site_option() : []);
				}

				$value	= wpjam_if_error(wpjam_get(is_array($data) ? $data : [], $name), null);

				if(is_null($value)){
					if($site_default){
						return $this->get_site_setting(...$args);
					}

					if(count($args) >= 2){
						return $args[1];
					}

					if($this->field_default){
						return wpjam_get(($this->_defaults ??= $this->get_defaults_by_fields()), $name);
					}
				}

				return is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value;
			}

			return [$this, 'update'.$part.'_option'](...[...$params, ($action == 'update'
				? wpjam_reduce(is_array($name) ? $name : [$name=>$args[1]], fn($c, $v, $n)=> wpjam_set($c, $n, $v), $data)
				: wpjam_except($data, $name)
			)]);
		}
	}

	public function get_arg($key, $default=null, $callback=true){
		$value	= parent::get_arg($key, $default, $callback);

		if($key == 'menu_page'){
			if(!$this->name || (is_network_admin() && !$this->site_default)){
				return;
			}

			if(!$value){
				if(!$this->post_type || !$this->title){
					return $value;
				}

				$value	= ['parent'=>wpjam_get_post_type_setting($this->post_type, 'plural'), 'order'=>1];
			}

			if(wp_is_numeric_array($value)){
				return wpjam_array($value, function($k, $v){
					if(!empty($v['tab_slug']) && !empty($v['plugin_page'])){
						return [$k, $v];
					}elseif(!empty($v['menu_slug'])){
						return [$k, $v+($v['menu_slug'] == $this->name ? ['menu_title'=>$this->title] : [])];
					}
				});
			}

			$value	+= ($value['function'] ??= 'option') == 'option' ? ['option_name'=>$this->name] : [];

			if(!empty($value['tab_slug'])){
				return ($value['plugin_page'] ??= $this->plugin_page) ? $value+['title'=>$this->title] : null;
			}

			$value	+= ['menu_slug'=>$this->plugin_page ?: $this->name, 'menu_title'=>$this->title];
		}elseif($key == 'admin_load'){
			$value	= wp_is_numeric_array($value) ? $value : ($value ? [$value] : []);
			$value	= array_map(fn($v)=> ($this->model && !isset($v['callback']) && !isset($v['model'])) ? $v+['model'=>$this->model] : $v, $value);
		}elseif($key == 'sections'){
			if(!$value || !is_array($value)){
				$id		= $this->type == 'section' ? (string)$this->section_id : ($this->current_tab ?: $this->sub_name ?: $this->name);
				$value	= [$id=>array_filter(['fields'=>$this->get_arg('fields', null, false)]) ?: $this->get_arg('section') ?: []];
			}

			$value	= wpjam_array($value, fn($k, $v)=> is_array($v) && isset($v['fields']) ? [$k, ['fields'=>maybe_callback($v['fields'] ?? [], $k, $this->name)]+$v] : null);
		}

		return $value;
	}

	public function get_current($output=''){
		$args	= wpjam_pick(wpjam_admin('vars'), ['plugin_page', 'current_tab']);

		return $output === 'args' ? $args : ($this->get_sub(wpjam_join(':', $args)) ?: $this);
	}

	protected function get_sections($all=false, $filter=true){
		$sections	= $this->get_arg('sections');
		$sections	= count($sections) == 1 ? array_map(fn($s)=> $s+['title'=>$this->title ?: ''], $sections) : $sections;
		$sections	= array_reduce($all ? $this->get_subs() : [], fn($c, $v)=> array_merge($c, $v->get_sections(false, false)), $sections);

		if(!$filter){
			return $sections;
		}

		$args		= $all ? [] : wpjam_map(self::get_current('args'), fn($v)=> ['value'=>$v, 'if_null'=>true]);
		$objects	= wpjam_sort(self::get_by(['type'=>'section', 'name'=>$this->name]+$args), 'order', 'desc', 10);

		foreach(array_reverse(array_filter($objects, fn($v)=> $v->order > 10))+$objects as $object){
			foreach(($object->get_arg('sections') ?: []) as $id => $section){
				$id			= $id ?: array_key_first($sections);
				$exist		= isset($sections[$id]) ? ($object->order > 10 ? wpjam_merge($section, $sections[$id]) : $sections[$id]) : [];	// 字段靠前
				$sections	= wpjam_set($sections, $id, wpjam_merge($exist, $section));
			}
		}

		return apply_filters('wpjam_option_setting_sections', array_filter($sections, fn($v)=> isset($v['title'], $v['fields'])), $this->name);
	}

	public function add_section(...$args){
		$keys	= ['model', 'fields', 'section'];
		$args	= is_array($args[0]) ? $args[0] : ['section_id'=>$args[0]]+(array_any($keys, fn($k)=> isset($args[1][$k])) ? $args[1] : ['fields'=>$args[1]]);
		$args	= array_any([...$keys, 'sections'], fn($k)=>isset($args[$k])) ? $args : ['sections'=>$args];

		return self::register(md5(wpjam_serialize($args)), new static($this->name, $args+['type'=>'section']));
	}

	public function value_callback($name=''){
		return $this->option_type == 'array' ? (is_network_admin() ? $this->get_site_setting($name) : $this->get_setting($name)) : get_option($name, null);
	}

	public function render($page){
		$nav	= $page->tab_slug ? '' : wpjam_tag('h2', ['nav-tab-wrapper', 'wp-clearfix']);
		$form	= wpjam_tag('form', ['method'=>'POST', 'id'=>'wpjam_option', 'novalidate']);
		$tabs	= [];

		foreach($this->get_sections() as $id => $sec){
			$nav && $nav->append('a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id, 'data'=>wpjam_pick($sec, ['show_if'])], $sec['title']);

			$tabs[]	= wpjam_tag($nav ? 'div' : '', ['id'=>'tab_'.$id, 'class'=>'tab'])->append([
				[$nav ? 'h2' : 'h3', [], $sec['title']],
				wpjam_ob($sec['callback'] ?? '', $sec),
				wpautop($sec['summary'] ?? ''),
				$this->render_fields($sec['fields'])
			]);
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append([
			...(count($tabs) == 1 ? [$tabs[0]->tag('')] : $tabs),
			wpjam_tag('p', ['submit'])->append([
				get_submit_button('', 'primary', 'save', false),
				$this->reset ? get_submit_button('重置选项', 'secondary', 'reset', false) : ''
			])
		]);

		return count($tabs) > 1 && $nav ? $form->before($nav)->wrap('div', ['tabs']) : $form;
	}

	public function page_load(){
		wpjam_ajax('wpjam-option-action',	[
			'admin'			=> true,
			'callback'		=> $this,
			'nonce_action'	=> fn()=> $this->option_group,
			'allow'			=> fn()=> current_user_can($this->capability)
		]);
	}

	public static function create($name, $args){
		$args	= maybe_callback($args, $name)+[
			'option_group'	=> $name,
			'option_page'	=> $name,
			'option_type'	=> 'array',
			'capability'	=> 'manage_options',
			'ajax'			=> true
		];

		if($sub = wpjam_pick($args, ['plugin_page', 'current_tab'])){
			$rest	= wpjam_except($args, ['model', 'menu_page', 'admin_load', 'plugin_page', 'current_tab']);
		}else{
			$args	= ['primary'=>true]+$args;
		}

		if($object = self::get($name)){
			if(!$sub && $object->primary && trigger_error('option_setting'.'「'.$name.'」已经注册。'.var_export($args, true))){
				return $object;
			}

			$object->update_args($sub ? wpjam_except($rest, 'title') : $args);
		}else{
			$args['option_type'] == 'array' && !doing_filter('sanitize_option_'.$name) && is_null(get_option($name, null)) && add_option($name, []);

			$object	= self::register($name, $sub ? $rest : $args);
		}

		return $sub ? $object->register_sub(wpjam_join(':', $sub), $args) : $object;
	}

	public static function get_instance($name, ...$args){
		if($args && in_array($args[0], ['model', 'registered'], true)){
			return self::get($name, ...$args);
		}elseif($args && $args[0] === 'object'){
			array_shift($args);
		}

		$blog_id	= is_multisite() ? ($args[0] ?? 0) : 0;
		$object		= $blog_id ? (!is_numeric($blog_id) && trigger_error($name.':'.$blog_id) && false) : self::get($name);

		return $object ?: wpjam_var('option:'.wpjam_join('-', $name, $blog_id), fn()=> new static($name, ['blog_id'=>$blog_id]));
	}
}

class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			$args	= [$this->name, ...$args];
			$cb		= str_replace('data', 'metadata', $method);
		}elseif($this->mod($method, '_by_mid')){
			$args	= [$this->name, ...$args];
			$cb		= $method.'_metadata_by_mid';
		}elseif($this->mod($method, '_meta')){
			$cb		= [$this, $method.'_data'];
		}elseif($this->mod($method, 'meta')){
			$cb		= [$this, $method];
		}

		return $cb(...$args);
	}

	public function get_options($args=[]){
		if($this->name == 'post'){
			$key	= 'post_type';
			$cb 	= 'wpjam_get_post_type_object';
		}elseif($this->name == 'term'){
			$key	= 'taxonomy';
			$cb 	= 'wpjam_get_taxonomy';
		}

		if(isset($key) && isset($args[$key])){
			$args[$key]	= ['value'=>($value = $args[$key]), 'if_null'=>true, 'callable'=>true];
			$object		= $cb($value);

			$object && ($this->call_option($value.'_base') || $this->call_option($value.'_base', [
				$key			=> $value,
				'title'			=> $this->name == 'post' ? '基础信息' : '快速编辑',
				'row_action'	=> $this->name == 'term',
				'action_name'	=> 'set',
				'page_title'	=> '设置'.$object->title,
				'fields'		=> [$object, 'get_fields'],
				'list_table'	=> $object->show_ui,
				'order'			=> 99,
			]));
		}

		if(isset($args['list_table'])){
			$args['title']		= true;
			$args['list_table']	= $args['list_table'] ? true : ['compare'=>'!==', 'value'=>'only'];
		}

		return wpjam_sort(wpjam_filter($this->get_arg('options[]'), $args), 'order', 'DESC', 10);
	}

	public function call_option($name, ...$args){
		$del	= try_prefix($name, '-', '-');
		$key	= 'options['.$name.']';

		if($del){
			return $this->delete_arg($key) && true;
		}

		if($args){
			$args	= $args[0];

			if($this->name == 'post'){
				$args	+= ['fields'=>[], 'priority'=>'default'];

				$args['post_type']	??= wpjam_pull($args, 'post_types') ?: null;
			}elseif($this->name == 'term'){
				$args['taxonomy']	??= wpjam_pull($args, 'taxonomies') ?: null;

				if(!isset($args['fields'])){
					$args['fields']		= [$name => wpjam_except($args, 'taxonomy')];
					$args['from_field']	= true;
				}
			}

			$this->update_arg($key, new WPJAM_Meta_Option(['name'=>$name, 'meta_type'=>$this->name]+$args));
		}

		return $this->get_arg($key);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object_id'){
		return $name == 'id'
		? ('user' == $this->name ? 'umeta_id' : 'meta_id')
		: ($name == 'object_id' ? $this->name.'_id' : $name);
	}

	public function get_data_with_default($id, ...$args){
		if(!$args){
			return $this->get_data($id);
		}

		if($id && $args[0]){
			if(is_array($args[0])){
				return wpjam_array($args[0], fn($k, $v)=> [is_numeric($k) ? $v : $k, $this->get_data_with_default($id, ...(is_numeric($k) ? [$v, null] : [$k, $v]))]);
			}

			if($args[0] == 'meta_input'){
				trigger_error('meta_input');
				return wpjam_map($this->get_data($id), fn($v, $k)=> $this->get_data($id, $k, true));
			}

			if($this->data_exists($id, $args[0])){
				return $this->get_data($id, $args[0], true);
			}
		}

		return is_array($args[0]) ? [] : ($args[1] ?? null);
	}

	public function get_by_key($key, $value=null, $column=null){
		global $wpdb;

		$where	= array_filter([
			$key ? $wpdb->prepare('meta_key=%s', $key) : '',
			is_null($value) ? '' : $wpdb->prepare('meta_value=%s', maybe_serialize($value))
		]);

		if($where){
			$table	= $this->get_table();
			$where	= implode(' AND ', $where);
			$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

			return $data && $column ? array_first($data)[$this->get_column($column)] : array_map(fn($v)=> ['meta_value'=>maybe_unserialize($v['meta_value'])]+$v, $data);
		}

		return $column ? null : [];
	}

	public function update_data_with_default($id, $key, ...$args){
		if(is_array($key)){
			if(wpjam_is_assoc_array($key)){
				$defaults	= $args && is_array($args[0]) ? $args[0] : [];

				if(isset($key['meta_input']) && wpjam_is_assoc_array($key['meta_input'])){
					$this->update_data_with_default($id, wpjam_pull($key, 'meta_input'), wpjam_pull($defaults, 'meta_input'));
				}

				wpjam_map($key, fn($v, $k)=> $this->update_data_with_default($id, $k, $v, wpjam_pull($defaults, $k)));
			}

			return true;
		}

		[$value, $default]	= array_pad($args, 2, null);

		if(is_closure($value)){
			$value	= $value($this->get_data_with_default($id, $key, $default), $key, $id);
		}

		if(is_array($value)){
			if($value && (!is_array($default) || array_diff_assoc($default, $value))){
				return $this->update_data($id, $key, $value);
			}
		}else{
			if(isset($value) && ((is_null($default) && ($value || is_numeric($value))) || (!is_null($default) && $value != $default))){
				return $this->update_data($id, $key, $value);
			}
		}

		return $this->delete_data($id, $key);
	}

	public function delete_empty_data(){
		$wpdb	= $GLOBALS['wpdb'];
		$mids	= $wpdb->get_col("SELECT ".$this->get_column('id')." FROM ".$this->get_table()." WHERE meta_value = ''") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function delete_by_key($key, $value=''){
		return delete_metadata($this->name, null, $key, $value, true);
	}

	public function delete_by_id($id){
		$wpdb	= $GLOBALS['wpdb'];
		$table	= $this->get_table();
		$column	= $this->get_column();
		$mids	= $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$table} WHERE {$column} = %d ", $id)) ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function update_cache($ids){
		update_meta_cache($this->name, $ids);
	}

	public function cleanup(){
		$wpdb	= $GLOBALS['wpdb'];
		$key	= $this->object_key;
		$table	= $key ? $wpdb->{$this->name.'s'} : '';

		if(!$key){
			$model	= $this->object_model;

			if(!$model || !is_callable([$model, 'get_table'])){
				return;
			}

			$table	= $model::get_table();
			$key	= $model::get_primary_key();
		}

		if(is_multisite() && !str_starts_with($this->get_table(), $wpdb->prefix) && wpjam_lock($this->name.':meta_type:cleanup', DAY_IN_SECONDS, true)){
			return;
		}

		$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$table." t ON t.".$key." = m.".$this->get_column()." WHERE t.".$key." IS NULL") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function create_table(){
		$table	= $this->get_table();
		$column	= $this->name.'_id';

		if($table != $GLOBALS['wpdb']->get_var("show tables like '{$table}'")){
			$GLOBALS['wpdb']->query("CREATE TABLE {$table} (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				{$column} bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY {$column} ({$column}),
				KEY meta_key (meta_key(191))
			)");
		}
	}

	public function register_keys(){
		if($this->name == 'post'){
			foreach(get_post_types(['show_in_rest'=>true]) as $post_type){
				foreach($this->get_options(['post_type'=>$post_type]) as $option){
					if(wpjam_is_assoc_array($option->fields)){
						wpjam_fields($option->fields)->register_meta($this->name, $post_type);
					}
				}
			}
		}
	}

	public function delete_if_default($check, $id, $key, $value){
		$type		= $this->name;
		$subtype	= $type == 'post' ? get_post_type($id) : ($type == 'term' ? get_term_taxonomy($id) : '');
		$registered	= $subtype ? (get_registered_meta_keys($type, $subtype)[$key] ?? null) : null;
		$registered	??= get_registered_meta_keys($type)[$key] ?? null;

		return $registered && array_key_exists('default', $registered) && $value === $registered['default']
		? (delete_metadata($type, $id, $key) || true)
		: $check;
	}

	public function registered(){
		add_action('init', [$this, 'register_keys'], 99);

		wpjam_hooks('add_'.$this->name.'_metadata, update_'.$this->name.'_metadata', [$this, 'delete_if_default'], 10, 4);
	}

	public static function get_defaults(){
		return array_merge([
			'post'	=> ['object_model'=>'WPJAM_Post',	'object_column'=>'title',	'object_key'=>'ID'],
			'term'	=> ['object_model'=>'WPJAM_Term',	'object_column'=>'name',	'object_key'=>'term_id'],
			'user'	=> ['object_model'=>'WPJAM_User',	'object_column'=>'display_name','object_key'=>'ID'],
		], (is_multisite() ? [
			'blog'	=> ['object_key'=>'blog_id'],
			'site'	=> [],
		] : []));
	}
}

class WPJAM_Meta_Option extends WPJAM_Args{
	public function __get($key){
		$value	= parent::__get($key);

		if(isset($value)){
			return $value;
		}

		if($key == 'list_table'){
			return did_action('current_screen') && !empty($GLOBALS['plugin_page']);
		}elseif($key == 'show_in_rest'){
			return true;
		}elseif($key == 'show_in_posts_rest'){
			return $this->show_in_rest;
		}
	}

	public function __call($method, $args){
		if($method == 'prepare' && ($this->callback || $this->update_callback)){
			return [];
		}

		$id		= array_shift($args);
		$fields	= maybe_callback($this->fields, $id, $this->name);

		if($method == 'get_fields'){
			return $fields;
		}

		$object	= wpjam_fields($fields, array_merge($this->get_args(), ['id'=>$id]));

		if($method == 'callback'){
			$data	= wpjam_catch([$object, 'validate'], ...$args);

			if(!wpjam_if_error($data, '')){
				return $data ?: true;
			}

			if($callback = $this->callback ?: $this->update_callback){
				$res	= is_callable($callback) ? call_user_func($callback, $id, $data, $fields) : false;

				return $res === false ? new WP_Error('invalid_callback') : $res;
			}

			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}

		$res	= $object->$method(...$args);

		return $method == 'render' ? wpjam_echo(wpautop($this->summary ?: '').$res) : $res;
	}
}