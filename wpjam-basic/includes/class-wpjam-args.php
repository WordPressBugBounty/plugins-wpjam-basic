<?php
trait WPJAM_Call_Trait{
	protected function bind_if_closure($closure){
		return is_closure($closure) ? $closure->bindTo($this, static::class) : $closure;
	}

	public function ob_get($method, ...$args){
		return wpjam_ob_get_contents([$this, $method], ...$args);
	}

	public function try($method, ...$args){
		return wpjam_try([$this, $method], ...$args);
	}

	public function catch($method, ...$args){
		return wpjam_catch([$this, $method], ...$args);
	}

	protected function call_dynamic_method($method, ...$args){
		return ($closure	= self::dynamic_method('get', $method)) ? $this->bind_if_closure($closure)(...$args) : null;
	}

	public static function dynamic_method($action, $method, ...$args){
		if(!$method){
			return;
		}

		static $methods	= [];

		if($action == 'add'){
			if(is_closure($args[0])){
				$methods[$method]	= $args[0];
			}
		}elseif($action == 'remove'){
			unset($methods[$method]);
		}elseif($action == 'get'){
			return $methods[$method] ?? (($parent = get_parent_class(static::class)) ? $parent::dynamic_method('get', $method) : null);
		}
	}

	public static function add_dynamic_method($method, $closure){
		self::dynamic_method('add', $method, $closure);
	}

	public static function remove_dynamic_method($method){
		self::dynamic_method('remove', $method);
	}

	public static function get_called(){
		return strtolower(static::class);
	}
}

trait WPJAM_Items_Trait{
	use WPJAM_Call_Trait;

	public function get_items($field=''){
		$field	= $field ?: $this->get_items_field();

		return $this->$field ?: [];
	}

	public function update_items($items, $field=''){
		$field	= $field ?: $this->get_items_field();

		$this->$field	= $items;

		return $this;
	}

	protected function get_items_field(){
		return wpjam_get_annotation(static::class, 'items_field') ?: '_items';
	}

	public function item_exists($key, $field=''){
		return wpjam_exists($this->get_items($field), $key);
	}

	public function has_item($item, $field=''){
		return in_array($item, $this->get_items($field));
	}

	public function get_item($key, $field=''){
		return wpjam_get($this->get_items($field), $key);
	}

	public function get_item_arg($key, $arg, $field=''){
		return $this->get_item($key.'.'.$arg, $field);
	}

	public function add_item($key, ...$args){
		if(!$args || is_bool($key) || (!is_scalar($key) && !is_null($key))){
			$args	= [$key, ...$args];
			$key	= null;
		}

		$item	= array_shift($args);
		$field	= array_shift($args) ?: '';

		return $this->process_items($field, fn($items, $key, $item)=> wpjam_add_at($items, count($items), $key, $item), 'add', $key, $item);
	}

	public function remove_item($item, $field=''){
		return $this->process_items($field, fn($items)=> array_diff($items, [$item]));
	}

	public function edit_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field);
	}

	public function update_item($key, $item, $field=''){
		return $this->process_items($field, fn($items, $key, $item)=> array_replace($items, [$key=> $item]), 'update', $key, $item);
	}

	public function set_item($key, $item, $field=''){
		return $this->process_items($field, fn($items, $key, $item)=> array_replace($items, [$key=> $item]), 'set', $key, $item);
	}

	public function delete_item($key, $field=''){
		$result	= $this->process_items($field, fn($items, $key)=> wpjam_except($items, $key), 'delete', $key);

		!is_wp_error($result) && method_exists($this, 'after_delete_item') && $this->after_delete_item($key, $field);

		return $result;
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		if(wpjam_is_assoc_array($orders)){
			[$orders, $field]	= array_values(wpjam_pull($orders, ['item', '_field']));
		}

		return $this->process_items($field, fn($items)=> array_merge(wpjam_pull($items, $orders), $items));
	}

	public function process_items($field, $cb, $action='', $key=null, $item=null, ...$args){
		$items	= $this->get_items($field);

		if($action){
			if(isset($item)){
				if($action == 'add'){
					$max	= wpjam_get_annotation(static::class, 'max_items');

					if($max && count($items) >= $max){
						return new WP_Error('quota_exceeded', '最多'.$max.'个');
					}
				}

				$result	= method_exists($this, 'validate_item') ? $this->validate_item($item, $key, $action, $field) : true;

				if(is_wp_error($result)){
					return $result;
				}

				$item	= method_exists($this, 'sanitize_item') ? $this->sanitize_item($item, $key, $action, $field) : $item;
			}

			if(isset($key)){
				if(wpjam_exists($items, $key)){
					$invalid	= $action == 'add' ? '「'.$key.'」已存在，无法添加' : '';
				}else{
					$invalid	= ['update'=>'编辑', 'delete'=>'删除'][$action] ?? '';
					$invalid	= $invalid ? '「'.$key.'」不存在，无法'.$invalid : '';
				}
			}else{
				$invalid	= $action != 'add' ? 'key不能为空' : '';
			}

			if($invalid){
				return new WP_Error('invalid_item_key', $invalid);
			}
		}

		return $this->update_items($cb($items, $key, $item, ...$args), $field);
	}

	public static function get_item_actions(){
		$args	= [
			'row_action'	=> false,
			'data_callback'	=> fn($id)=> wpjam_try([static::class, 'get_item'], $id, ...array_values(wpjam_get_data_parameter(['i', '_field']))),
			'value_callback'=> fn()=> '',
			'callback'		=> function($id, $data, $action){
				$args	= array_values(wpjam_get_data_parameter(['i', '_field']));
				$args	= $action == 'del_item' ? $args : wpjam_add_at($args, 1, null, $data);

				return wpjam_try([static::class, $action], $id, ...$args);
			}
		];

		return [
			'add_item'	=>['page_title'=>'新增项目',	'title'=>'新增',	'dismiss'=>true]+array_merge($args, ['data_callback'=> fn()=> []]),
			'edit_item'	=>['page_title'=>'修改项目',	'dashicon'=>'edit']+$args,
			'del_item'	=>['page_title'=>'删除项目',	'dashicon'=>'no-alt',	'class'=>'del-icon',	'direct'=>true,	'confirm'=>true]+$args,
			'move_item'	=>['page_title'=>'移动项目',	'dashicon'=>'move',		'class'=>'move-item',	'direct'=>true]+wpjam_except($args, 'callback'),
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
		return $this->get_args()[$key] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->filter_args();

		if(is_null($key)){
			$this->args[]		= $value;
		}else{
			$this->args[$key]	= $value;
		}
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return wpjam_exists($this->get_args(), $key);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->filter_args();

		unset($this->args[$key]);
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

	public function update_args($args, $replace=true){
		$this->args	= ($replace ? 'array_replace' : 'wp_parse_args')($this->get_args(), $args);

		return $this;
	}

	public function get_arg($key, $default=null, $action=false){
		$value	= wpjam_get($this->get_args(), $key);

		if(in_array($action, ['parse', 'callback'], true)){
			$value	= isset($value) ? $this->bind_if_closure($value) : (is_string($key) ? $this->parse_method('get_'.$key, 'model') : null);
			$value	= $action == 'callback' ? maybe_callback($value, $this->name) : $value;
		}

		return $value ?? $default;
	}

	public function update_arg($key, $value=null){
		$this->args	= wpjam_set($this->get_args(), $key, $value);

		return $this;
	}

	public function delete_arg($key, ...$args){
		if($args && is_string($key) && str_ends_with($key, '[]')){
			$key	= substr($key, 0, -2);
			$items	= $this->get_arg($key);

			is_array($items) && $this->update_arg($key, array_diff($items, $args));
		}else{
			$this->args	= wpjam_except($this->get_args(), $key);
		}

		return $this;
	}

	public function pull($key, ...$args){
		$this->filter_args();

		return wpjam_pull($this->args, $key, ...$args);
	}

	public function pick($keys){
		return wpjam_pick($this->get_args(), $keys);
	}

	public function add_field($key, ...$args){
		if(is_array($key)){
			return wpjam_reduce($key, fn($c, $v, $k)=> $c->add_field($k, $v), $this);
		}

		if(is_callable($key) && !$args){
			[$args, $key]	= [[$key], ''];
		}

		return $args ? $this->update_arg('_fields['.$key.']', ...$args) : $this;
	}

	public function remove_fields($key){
		return $this->delete_arg('_fields['.$key.']');
	}

	public function parse_fields(...$args){
		$fields	= [];

		foreach($this->get_arg('_fields[]') as $key => $field){
			if(is_callable($field)){
				$result	= wpjam_call($field, ...$args);

				if(is_wp_error($result)){
					return $result;
				}elseif(is_numeric($key)){
					$fields	= array_merge($fields, $result);
				}else{
					$fields[$key]	= $result;
				}
			}elseif(wpjam_is_assoc_array($field)){
				$fields[$key]	= $field;
			}
		}

		return $fields;
	}

	public function to_array(){
		return $this->get_args();
	}

	public function sandbox($callback, ...$args){
		try{
			$archive	= $this->get_args();

			return $this->bind_if_closure($callback)(...$args);
		}finally{
			$this->args	= $archive;
		}
	}

	protected function parse_method($name, $type=null){
		if(!$type || $type == 'model'){
			if($this->model && method_exists($this->model, $name)){
				return [$this->model, $name];
			}
		}

		if(!$type || $type == 'property'){
			if(is_callable($this->$name)){
				return $this->bind_if_closure($this->$name);
			}
		}
	}

	public function call_method($method, ...$args){
		$method	= is_array($method) ? $method : [$method, ''];
		$called	= $this->parse_method(...$method);

		return $called ? $called(...$args) : (str_starts_with($method[0], 'filter_') ? array_shift($args) : null);
	}

	protected function call_property($property, ...$args){
		return $this->call_method([$property, 'property'], ...$args);
	}

	protected function call_model($method, ...$args){
		return $this->call_method([$method, 'model'], ...$args);
	}

	protected function error($code, $msg){
		return new WP_Error($code, $msg);
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function __construct($name, $args=[]){
		$this->args	= array_merge($args, ['name'=>$name]);
		$this->args	= $this->preprocess_args($this->args);
	}

	protected function preprocess_args($args){
		if(!$this->is_active() && empty($args['active'])){
			return $args;
		}

		$config	= get_class($this) == self::class ? [] : static::call_group('get_config');
		$model	= empty($config['model']) ? null : wpjam_get($args, 'model');

		if($model || !empty($args['hooks']) || !empty($args['init'])){
			$file	= wpjam_pull($args, 'file');

			$file && is_file($file) && include_once $file;
		}

		if($model){
			is_subclass_of($model, self::class) && trigger_error('「'.(is_object($model) ? get_class($model) : $model).'」是 WPJAM_Register 子类');

			if($config['model'] === 'object' && !is_object($model)){
				if(class_exists($model, true)){
					$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
				}else{
					trigger_error('model 无效');
				}
			}

			foreach(['hooks'=>'add_hooks', 'init'=>'init'] as $k => $m){
				$v	= $args[$k] ?? ($k == 'hooks' ? true : ($config[$k] ?? false));

				if($v === true && method_exists($model, $m)){
					$args[$k]	= [$model, $m];
				}
			}
		}

		return $args;
	}

	protected function filter_args(){
		if(get_class($this) != self::class && !in_array(($name	= $this->args['name']), static::call_group('get_arg', 'filtered[]'))){
			static::call_group('update_arg', 'filtered[]', $name);

			$this->args	= apply_filters(static::call_group('get_arg', 'name').'_args', $this->args, $name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $should_callback=true){
		return parent::get_arg($key, $default, $should_callback ? 'callback' : 'parse');
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

	public function is_active(){
		return true;
	}

	public static function validate_name($name){
		$fn	= fn($msg)=> trigger_error(self::class.'的注册 name'.$msg) && false;

		if(empty($name)){
			return $fn(' 为空');
		}elseif(is_numeric($name)){
			return $fn('「'.$name.'」'.'为纯数字');
		}elseif(!is_string($name)){
			return $fn('「'.var_export($name, true).'」不为字符串');
		}

		return true;
	}

	public static function get_group($args){
		return WPJAM_Register_Group::instance($args);
	}

	public static function call_group($method, ...$args){
		if(static::class != self::class){
			$group	= static::get_group(['called'=>static::class, 'name'=>strtolower(static::class)]);

			$group->defaults	??= method_exists(static::class, 'get_defaults') ? static::get_defaults() : [];

			return [$group, $method](...$args);
		}
	}

	public static function register($name, $args=[]){
		return static::call_group('add_object', $name, $args);
	}

	public static function unregister($name, $args=[]){
		static::call_group('remove_object', $name, $args);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		$objects	= static::call_group('get_objects', $args, $operator);

		return $output == 'names' ? array_keys($objects) : $objects;
	}

	public static function get_by(...$args){
		return self::get_registereds(...((!$args || is_array($args[0])) ? $args : [$args[0]=> $args[1]]));
	}

	public static function get($name, $by='', $top=''){
		return static::call_group('get_object', $name, $by, $top);
	}

	public static function exists($name){
		return (bool)self::get($name);
	}

	public static function get_setting_fields($args=[]){
		return static::call_group('get_fields', $args);
	}

	public static function get_active($key=null){
		return static::call_group('get_active', $key);
	}

	public static function call_active($method, ...$args){
		return static::call_group('call_active', $method, ...$args);
	}

	public static function by_active(...$args){
		$name	= current_filter();
		$method = (did_action($name) ? 'on_' : 'filter_').substr($name, str_starts_with($name, 'wpjam_') ? 6 : 0);

		return self::call_active($method, ...$args);
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	public function get_objects($args=[], $operator='AND'){
		$this->defaults && array_map(fn($name)=> $this->by_default($name), array_keys($this->defaults));

		$objects	= wpjam_filter($this->get_arg('objects[]'), $args, $operator);
		$orderby	= $this->get_config('orderby');

		return $orderby ? wpjam_sort($objects, ($orderby === true ? 'order' : $orderby), ($this->get_config('order') ?? 'DESC'), 10) : $objects;
	}

	public function get_object($name, $by='', $top=''){
		if($name){
			if(!$by){
				return $this->get_arg('objects['.$name.']') ?: $this->by_default($name);
			}

			if($by == 'model' && strcasecmp($name, $top) !== 0){
				return array_find($this->get_objects(), fn($v)=> is_string($v->model) && strcasecmp($name, $v->model) === 0) ?: $this->get_object(get_parent_class($name), $by, $top);
			}
		}
	}

	public function by_default($name){
		$args = $this->pull('defaults['.$name.']');

		return is_null($args) ? null : $this->add_object($name, $args);
	}

	public function add_object($name, $object){
		$called	= $this->called ?: 'WPJAM_Register';
		$count	= count($this->get_arg('objects[]'));

		if(is_object($name)){
			$object	= $name;
			$name	= $object->name ?? null;
		}elseif(is_array($name)){
			[$object, $name]	= [$name, $object];

			$name	= wpjam_pull($object, 'name') ?: ($name ?: '__'.$count);
		}

		if(!$called::validate_name($name)){
			return;
		}

		if($this->get_arg('objects['.$name.']')){
			trigger_error($this->name.'「'.$name.'」已经注册。');
		}

		if(is_array($object)){
			if(!empty($object['admin']) && !is_admin()){
				return;
			}

			$object	= new $called($name, $object);
		}

		$this->update_arg('objects['.$name.']', $object);

		if($object->is_active() || $object->active){
			wpjam_hooks(maybe_callback($object->pull('hooks')));
			wpjam_init($object->pull('init'));

			method_exists($object, 'registered') && $object->registered();

			$count == 0 && method_exists($called, 'add_hooks') && wpjam_hooks(wpjam_call([$called, 'add_hooks']));
		}

		return $object;
	}

	public function remove_object($name){
		return $this->delete_arg('objects['.$name.']');
	}

	public function get_config($key=null){
		$this->config	??= $this->called ? wpjam_get_annotation($this->called, 'config')+['model'=>true] : [];

		return $this->get_arg('config['.($key ?: '').']');
	}

	public function get_active($key=null){
		return wpjam_array($this->get_objects(), fn($k, $v)=> ($v->active ?? $v->is_active()) ? [$k, $key ? $v->get_arg($key) : $v] : null, true);
	}

	public function call_active($method, ...$args){
		$type	= array_find(['filter', 'get'], fn($type)=> str_starts_with($method, $type.'_'));

		try{
			foreach($this->get_active() as $object){
				$result	= $object->try('call_method', $method, ...$args);

				if($type == 'filter'){
					$args[0]	= $result;
				}elseif($type == 'get'){
					if($result && is_array($result)){
						$return	= array_merge(($return ?? []), $result);
					}
				}
			}
		}catch(Exception $e){
			return wpjam_catch($e);
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}

	public function get_fields($args=[]){
		$objects	= array_filter($this->get_objects(wpjam_pull($args, 'filter_args')), fn($v)=> !isset($v->active));
		$options	= wpjam_parse_options($objects, $args);

		if(wpjam_get($args, 'type') == 'select'){
			$name	= wpjam_pull($args, 'name');
			$args	+= ['options'=>$options];

			return $name ? [$name => $args] : $args;
		}

		return $options;
	}

	public static function __callStatic($method, $args){
		foreach(self::instance() as $group){
			if($method == 'register_json'){
				$group->get_config($method) && $group->call_active($method, $args[0]);
			}elseif($method == 'on_admin_init'){
				foreach(['menu_page', 'admin_load'] as $key){
					$group->get_config($key) && array_map('wpjam_add_'.$key, $group->get_active($key));
				}
			}
		}
	}

	public static function instance($args=[]){
		static $groups	= [];

		if(!$groups){
			add_action('wpjam_api',			[self::class, 'register_json']);
			add_action('wpjam_admin_init',	[self::class, 'on_admin_init']);
		}

		if($args){
			return !empty($args['name']) ? ($groups[$args['name']] ??= new self($args)) : null;
		}

		return $groups;
	}
}

class WPJAM_AJAX extends WPJAM_Args{
	public function callback(){
		add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_Error', 'wp_die_handler']);

		(!$this->callback || !is_callable($this->callback)) && wp_die('invalid_callback');

		if($this->admin){
			$data	= $this->fields ? wpjam_if_error(wpjam_fields($this->fields)->catch('get_parameter', 'POST'), 'send') : wpjam_get_post_parameter();
			$verify	= wpjam_get($data, 'action_type') !== 'form';
		}else{
			$data	= array_merge(wpjam_get_data_parameter(), wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
			$data	= array_merge($data, wpjam_if_error(wpjam_fields($this->fields)->catch('validate', $data, 'parameter'), 'send'));
			$verify	= $this->verify !== false;
		}

		$action	= $verify ? $this->parse_nonce_action($this->name, $data) : '';
		$action && !check_ajax_referer($action, false, false) && wpjam_send_json(['errcode'=>'invalid_nonce', 'errmsg'=>'验证失败，请刷新重试。']);

		$this->allow && !wpjam_call($this->allow, $data) && wp_die('access_denied');

		return wpjam_send_json(wpjam_catch($this->callback, $data, $this->name));
	}

	public static function parse_nonce_action($name, $data=[]){
		return ($nonce	= wpjam('ajax', $name.'[nonce_action]')) ? $nonce($data) : (wpjam('ajax', $name.'[admin]') ? '' : $name.wpjam_join(':', wpjam_pick($data, wpjam('ajax', $name.'[nonce_keys]') ?: [])));
	}

	public static function parse_submit_button($button, $submit=null){
		$cb	= fn($item)=> (is_array($item) ? $item : ['text'=>$item])+['class'=>'primary'];

		if($submit){
			return isset($button[$submit]) ? $cb($button[$submit]) : wp_die('无效的提交按钮');
		}

		foreach(array_filter($button) as $key => $item){
			$item		= $cb($item);
			$parsed[]	= get_submit_button($item['text'], $item['class'], $key, false);
		}

		return isset($parsed) ? wpjam_tag('p', ['submit'], implode($parsed)) : wpjam_tag();
	}

	public static function get_attr($name, $data=[]){
		if(wpjam('ajax', $name)){
			return ['action'=>$name, 'data'=>$data]+(($action = self::parse_nonce_action($name, $data)) ? ['nonce'=>wp_create_nonce($action)] : []);
		}
	}

	public static function create($name, $args){
		if(!is_admin() && !wpjam('ajax')){
			wpjam_script('wpjam-ajax', [
				'for'		=> 'wp, login',
				'src'		=> wpjam_url(dirname(__DIR__).'/static/ajax.js'),
				'deps'		=> ['jquery'],
				'data'		=> 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";',
				'position'	=> 'before',
				'priority'	=> 1
			]);

			if(!is_login()){
				add_filter('script_loader_tag', fn($tag, $handle)=> $handle == 'wpjam-ajax' && current_theme_supports('script', $handle) ? '' : $tag, 10, 2);
			}
		}

		if(wp_doing_ajax() && wpjam_get($_REQUEST, 'action') == $name && (is_user_logged_in() || !empty($args['nopriv']))){
			add_action('wp_ajax_'.(is_user_logged_in() ? '' : 'nopriv_').$name, [new static(['name'=>$name]+$args), 'callback']);
		}

		return wpjam('ajax', $name, $args);
	}
}

class WPJAM_Data_Processor extends WPJAM_Args{
	public function get_fields($type=''){
		return $type ? array_intersect_key($this->fields, $this->$type ?: []) : $this->fields;
	}

	public function validate(){
		$this->formulas	= wpjam_map($this->formulas ?: [], [$this, 'parse_formula']);

		foreach($this->formulas as $key => $formula){
			is_array($formula) && is_array($formula[0]) && array_walk($formula, fn($f)=> wpjam_if_error($f['formula'], 'throw'));

			$this->sort_formular(wpjam_if_error($formula, 'throw'), $key);
		}

		$this->formulas	= wpjam_pick($this->formulas, $this->sorted ?: []);

		return true;
	}

	public function parse_formula($formula, $key){
		if(is_array($formula)){
			return array_map(fn($f)=> array_merge($f, ['formula'=>$this->parse_formula($f['formula'], $key)]), $formula);
		}

		$depth		= 0;
		$methods	= ['abs', 'ceil', 'pow', 'sqrt', 'pi', 'max', 'min', 'fmod', 'round'];
		$signs		= ['+', '-', '*', '/', '(', ')', ',', '%'];
		$formula	= preg_split('/\s*(['.preg_quote(implode($signs), '/').'])\s*/', $formula, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		$invalid	= fn($msg)=> new WP_Error('invalid_formula', '字段'.$this->render_formular($formula, $key).'错误，'.$msg);

		foreach($formula as $t){
			if(is_numeric($t)){
				if(str_ends_with($t, '.')){
					return $invalid('无效数字「'.$t.'」');
				}
			}elseif(str_starts_with($t, '$')){
				if(!in_array(substr($t, 1), array_keys($this->fields))){
					return $invalid('「'.$t.'」未定义');
				}
			}elseif($t == '('){
				$depth++;
			}elseif($t == ')'){
				if(!$depth){
					return $invalid('括号不匹配');
				}

				$depth--;
			}else{
				if(!in_array($t, $signs) && !in_array(strtolower($t), $methods)){
					return $invalid('无效的「'.$t.'」');
				}
			}
		}

		return $depth ? $invalid('括号不匹配') : $formula;
	}

	protected function render_formular($formula, $key){
		return wpjam_get($this->fields[$key], 'title').'「'.$key.'」'.'公式「'.(is_array($formula) ? implode($formula) : $formula).'」';
	}

	protected function sort_formular($formula, $key){
		if(in_array($key, $this->sorted ?: [])){
			return;
		}

		if(in_array($key, $this->path ?: [])){
			wpjam_throw('invalid_formula', '公式嵌套：'.implode(' → ', wpjam_map(array_slice($this->path, array_search($key, $this->path)), fn($k)=> $this->render_formular($formula, $k))));
		}

		$this->update_arg('path[]', $key);

		foreach((is_array($formula[0]) ? array_column($formula, 'formula') : [$formula]) as $formula){
			foreach($formula as $t){
				if(try_remove_prefix($t, '$') && isset($this->formulas[$t])){
					$this->sort_formular(wpjam_if_error($this->formulas[$t], 'throw'), $t);
				}
			}
		}

		$this->update_arg('sorted[]', $key)->delete_arg('path[]', $key);
	}

	private	function parse_number($v){
		if(is_numeric($v)){
			return $v;
		}

		if(is_string($v)){
			$v	= str_replace(',', '', trim($v));

			if(is_numeric($v)){
				return $v;
			}
		}

		return false;
	}

	public function process($items, $args=[]){
		$args	= wp_parse_args($args, ['calc'=>true, 'sum'=>true, 'format'=>false, 'orderby'=>'', 'order'=>'', 'filter'=>'']);
		$sums	= $args['sum'] ? wpjam_array($this->sumable, fn($k, $v)=> $v == 1 ? [$k, 0] : null) : [];

		foreach($items as $i => &$item){
			if($args['calc']){
				$item	= $this->calc($item);
			}

			if($args['filter'] && !wpjam_match($item, $args['filter'], 'AND')){
				unset($items[$i]);
				continue;
			}

			if($args['sum']){
				$sums	= wpjam_map($sums, fn($v, $k)=> $v+$this->parse_number($item[$k] ?? 0));
			}

			if($args['format']){
				$item	= $this->format($item);
			}
		}

		unset($item);

		if($args['orderby']){
			$items	= wpjam_sort($items, $args['orderby'], $args['order']);
		}

		if($args['sum']){
			$sums	= $this->calc($sums, ['sum'=>true])+(is_array($args['sum']) ? $args['sum'] : []);
			$items	= wpjam_add_at($items, 0, '__sum__', ($args['format'] ? $this->format($sums) : $sums));

			unset($sums);
		}

		return $items;
	}

	public function calc($item, $args=[]){
		if(!$item || !is_array($item)){
			return $item;
		}

		!is_array($this->sorted) && $this->validate();

		$args		= wp_parse_args($args, ['sum'=>false, 'key'=>'']);
		$formulas	= $this->formulas;
		$if_errors	= $this->if_errors ?: [];

		if($args['key']){
			$key		= $args['key'];
			$if_error	= $if_errors[$key] ?? null;
			$formula	= $formulas[$key];
			$formula	= is_array($formula[0]) ? (($f = array_find($formula, fn($f)=> wpjam_match($item, $f))) ? $f['formula'] : []) : $formula;

			if(!$formula){
				return '';
			}

			foreach($formula as &$t){
				if(str_starts_with($t, '$')){
					$k	= substr($t, 1);
					$r	= isset($item[$k]) ? $this->parse_number($item[$k]) : false;

					if($r !== false){
						$t	= (float)$r;
						$t	= $t < 0 ? '('.$t.')' : $t;
					}else{
						$t	= $if_errors[$k] ?? null;

						if(!isset($t)){
							return $if_error ?? (isset($item[$k]) ? '!!无法计算' : '!无法计算');
						}
					}
				}
			}

			unset($t);

			try{
				return eval('return '.implode($formula).';');
			}catch(DivisionByZeroError $e){
				return $if_error ?? '!除零错误';
			}catch(throwable $e){
				return $if_error ?? '!计算错误：'.$e->getMessage();
			}
		}

		if($args['sum'] && $formulas){
			$formulas	= array_intersect_key($formulas, array_filter($this->sumable, fn($v)=> $v == 2));
		}

		if($formulas){
			$handler	= set_error_handler(function($no, $str){
				if(str_contains($str , 'Division by zero')){
					throw new DivisionByZeroError($str); 
				}

				throw new ErrorException($str , $no);

				return true;
			});

			$item	= array_diff_key($item, $formulas);

			foreach($formulas as $key => $formula){
				if(!is_array($formula)){
					$item[$key]	= is_wp_error($formula) ? '!公式错误' : $formula;
				}else{
					$item[$key]	= $this->calc($item, array_merge($args, ['key'=>$key]));
				}
			}

			if($handler){
				set_error_handler($handler);
			}else{
				restore_error_handler();
			}
		}

		return $item;
	}

	public function sum($items, $args=[]){
		return ($this->sumable && $items) ? $this->calc(wpjam_at($this->process($items, $args+['sum'=>true]), 0), ['sum'=>true]) : [];
	}

	public function accumulate($to, $items, $args=[]){
		$args	= wp_parse_args($args, ['calc'=>true, 'field'=>'', 'filter'=>'']);
		$keys	= array_keys(array_filter($this->sumable ?: [], fn($v)=> $v == 1));

		if(!$args['field']){
			$group	= '____';
			$to		= [$group=>($to ?: array_fill_keys($keys, 0))];
		}

		foreach($items as $item){
			if($args['calc']){
				$item	= $this->calc($item);
			}

			if($args['filter'] && !wpjam_match($item, $args['filter'], 'AND')){
				continue;
			}

			if($args['field']){
				$group	= $item[$args['field']] ?? '';
			}

			$exists	= isset($to[$group]);

			if(!$exists){
				$to[$group]	= $item;
			}

			foreach($keys as $k){
				$to[$group][$k]	= ($exists ? $to[$group][$k] : 0)+($this->parse_number($item[$k] ?? 0) ?: 0);
			}
		}

		return $args['field'] ? $to : $to[$group];
	}

	public function format($item){
		foreach($this->formats ?: [] as $k => $v){
			if(isset($item[$k]) && is_numeric($item[$k])){
				$item[$k]	= wpjam_format($item[$k], ...$v);
			}
		}

		return $item;
	}

	public static function create($fields, $by='fields'){
		$args	= ['fields'=>$fields];

		foreach($fields as $key => $field){
			if(!empty($field['sumable'])){
				$args['sumable'][$key]	= $field['sumable'];
			}

			if(!empty($field['format']) || !empty($field['precision'])){
				$args['formats'][$key]	= [$field['format'] ?? '', $field['precision'] ?? null];
			}

			if(!empty($field['formula'])){
				$args['formulas'][$key]	= $field['formula'];
			}

			if(isset($field['if_error']) && ($field['if_error'] || is_numeric($field['if_error']))){
				$args['if_errors'][$key]	= $field['if_error'];
			}
		}

		return new self($args);
	}
}

class WPJAM_Updater extends WPJAM_Args{
	public function get_data($file){	// https://api.wordpress.org/plugins/update-check/1.1/
		$type		= $this->type;
		$plural		= $type.'s';
		$response	= wpjam_transient('update_'.$plural.':'.$this->hostname, fn()=> wpjam_remote_request($this->url), MINUTE_IN_SECONDS);

		if(!is_array($response)){
			return [];
		}

		$response	= $response['template']['table'] ?? $response[$plural];

		if(isset($response['fields']) && isset($response['content'])){
			$fields	= array_column($response['fields'], 'index', 'title');
			$label	= $type == 'plugin' ? '插件' : '主题';
			$item	= array_find($response['content'], fn($item)=> $item['i'.$fields[$label]] == $file);
			$data	= $item ? array_map(fn($index)=> $item['i'.$index] ?? '', $fields) : [];

			return $data ? [
				$type			=> $file,
				'url'			=> $data['更新地址'],
				'package'		=> $data['下载地址'],
				'icons'			=> [],
				'banners'		=> [],
				'banners_rtl'	=> [],
				'new_version'	=> $data['版本'],
				'requires_php'	=> $data['PHP最低版本'],
				'requires'		=> $data['最低要求版本'],
				'tested'		=> $data['最新测试版本'],
			] : [];
		}

		return $response[$file] ?? [];
	}

	public function filter_update($update, $data, $file, $locales){
		$new_data	= $this->get_data($file);

		return $new_data ? $new_data+['id'=>$data['UpdateURI'], 'version'=>$data['Version']] : $update;
	}

	public function filter_pre_set_site_transient($updates){
		if(isset($updates->no_update) || isset($updates->response)){
			$file	= 'wpjam-basic/wpjam-basic.php';
			$update	= $this->get_data($file);

			if($update){
				$plugin	= get_plugin_data(WP_PLUGIN_DIR.'/'.$file);
				$key 	= version_compare($update['new_version'], $plugin['Version'], '>') ? 'response' : 'no_update';

				$updates->$key[$file]	= (object)(isset($updates->$key[$file]) ? array_merge((array)$updates->$key[$file], $update) : $update);
			}
		}

		return $updates;
	}

	public static function create($type, $hostname, $url){
		if(in_array($type, ['plugin', 'theme'])){
			$object	= new self([
				'type'		=> $type,
				'hostname'	=> $hostname,
				'url'		=> $url
			]);

			add_filter('update_'.$type.'s_'.$hostname, [$object, 'filter_update'], 10, 4);

			$type == 'plugin' && $hostname == 'blog.wpjam.com' && add_filter('pre_set_site_transient_update_plugins', [$object, 'filter_pre_set_site_transient']);
		}
	}
}

class WPJAM_Cache extends WPJAM_Args{
	public function __call($method, $args){
		$method	= substr($method, str_starts_with($method, 'cache_') ? 6 : 0);
		$gnd	= array_any(['get', 'delete'], fn($k)=> str_contains($method, $k));
		$cb		= $method == 'cas' ? [array_shift($args)] : [];
		$key	= array_shift($args);

		if(str_contains($method, '_multiple')){
			$cb[]	= ($gnd ? 'wpjam_map' : 'wpjam_array')($key, fn($k)=> $this->key($k));
		}else{
			$cb[]	= $this->key($key);

			if(!$gnd){
				$cb[]	= array_shift($args);
			}
		}

		$cb[]	= $this->group;

		if(!$gnd){
			$cb[]	= (int)(array_shift($args)) ?: ($this->time ?: DAY_IN_SECONDS);
		}else{
			if($args){
				$cb[]	= array_shift($args);
			}
		}

		$callback	= 'wp_cache_'.$method;
		$result		= $callback(...$cb);

		if($result && $method == 'get_multiple'){
			$result	= wpjam_array($key, fn($i, $k) => (($v = $result[$cb[0][$i]]) !== false) ? [$k, $v] : null);
		}

		return $result;
	}

	protected function key($key){
		return wpjam_join(':', $this->prefix, $key);
	}

	public function get_with_cas($key, &$token, ...$args){
		[$object, $token]	= is_object($token) ? [$token, null] : [null, $token];

		$key	= $this->key($key);
		$result	= wp_cache_get_with_cas($key, $this->group, $token);

		if($result === false && $args){
			$this->set($key, $args[0]);

			$result	= wp_cache_get_with_cas($key, $this->group, $token);
		}

		if($object){
			$object->cas_token	= $token;
		}

		return $result;
	}

	public function is_over($key, $max, $time){
		$times	= $this->get($key) ?: 0;

		if($times > $max){
			return true;
		}

		$this->set($key, $times+1, ($max == $times && $time > 60) ? $time : 60);

		return false;
	}

	public function generate($key){
		return wpjam_catch(function($key){
			$this->failed_times($key);
			$this->interval($key);

			$code = rand(100000, 999999);

			$this->set($key.':code', $code, $this->cache_time);

			return $code;
		}, $key);
	}

	public function verify($key, $code){
		return wpjam_catch(function($key, $code){
			$this->failed_times($key);

			(!$code || (int)$code !== (int)$this->get($key.':code')) && $this->failed_times($key, true);

			return true;
		}, $key, $code);
	}

	protected function failed_times($key, $increment=false){
		if($this->failed_times){
			$times	= (int)$this->get($key.':failed_times');

			if($increment){
				$this->set($key.':failed_times', $times+1, $this->cache_time/2);

				wpjam_throw('invalid_code');
			}else{
				($times > $this->failed_times) && wpjam_throw('failed_times_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
			}
		}
	}

	protected function interval($key){
		if($this->interval){
			$this->get($key.':time') && wpjam_throw('error', '验证码'.$this->interval.'分钟前已发送了。');
			$this->set($key.':time', time(), $this->interval*60);
		}
	}

	public static function get_verification($args){
		[$name, $args]	= is_array($args) ? [wpjam_pull($args, 'group'), $args] : [$args, []];

		return self::get_instance([
			'group'		=> 'verification_code',
			'prefix'	=> $name ?: 'default',
			'global'	=> true,
		]+$args+[
			'failed_times'	=> 5,
			'interval'		=> 1,
			'cache_time'	=> MINUTE_IN_SECONDS*30
		]);
	}

	public static function get_instance($group, $args=[]){
		$args	= is_array($group) ? $group : ['group'=>$group]+$args;

		if(!empty($args['group'])){
			$name	= wpjam_join(':', $args['group'], ($args['prefix'] ?? ''));

			return wpjam_get_instance('cache', $name, fn()=> self::create($args));
		}
	}

	public static function create($args=[]){
		if(is_object($args)){
			if(!$args->cache_object){
				$group	= $args->cache_group;

				if($group){
					$group	= is_array($group) ? ['group'=>$group[0], 'global'=>$group[1] ?? false] : ['group'=>$group];

					$args->cache_object	= self::create($group+['prefix'=>$args->cache_prefix, 'time'=>$args->cache_time]);
				}
			}

			return $args->cache_object;
		}else{
			if(!empty($args['group'])){
				wpjam_pull($args, 'global') && wp_cache_add_global_groups($args['group']);

				return new self($args);
			}
		}
	}
}

