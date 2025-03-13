<?php
trait WPJAM_Call_Trait{
	protected static $_closures	= [];

	protected function bind_if_closure($closure){
		return is_closure($closure) ? $closure->bindTo($this, get_called_class()) : $closure;
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

	public function chain($value){
		return new WPJAM_Chainable($this, $value);
	}

	protected static function get_called(){
		return strtolower(get_called_class());
	}

	public static function dynamic_method($action, $method, ...$args){
		if($method){
			$name	= self::get_called().':'.$method;

			if($action == 'add'){
				if(is_closure($args[0])){
					self::$_closures[$name]	= $args[0];
				}
			}elseif($action == 'remove'){
				unset(self::$_closures[$name]);
			}elseif($action == 'get'){
				return self::$_closures[$name] ?? (($parent = get_parent_class(self::get_called())) ? $parent::dynamic_method('get', $method) : null);
			}
		}
	}

	public static function add_dynamic_method($method, $closure){
		self::dynamic_method('add', $method, $closure);
	}

	public static function remove_dynamic_method($method){
		self::dynamic_method('remove', $method);
	}

	protected function call_dynamic_method($method, ...$args){
		$closure	= is_closure($method) ? $method : self::dynamic_method('get', $method);

		return $closure ? $this->bind_if_closure($closure)(...$args) : null;
	}
}

trait WPJAM_Items_Trait{
	public function get_items($field=''){
		return $this->{$field ?: '_items'} ?: [];
	}

	public function update_items($items, $field=''){
		$this->{$field ?: '_items'}	= $items;

		return $this;
	}

	public function item_exists($key, $field=''){
		return $this->handle_item('exists', $key, null, $field);
	}

	public function get_item($key, $field=''){
		$value	= $this->handle_item('get', $key, null, $field);

		if(is_null($value) && str_contains($key, '.')){
			$keys	= explode('.', $key);
			$key	= array_shift($keys);
			$value	= $this->get_item($key, $field);
			$value	= $value ? wpjam_get($value, $keys) : null;
		}

		return $value;
	}

	public function get_item_arg($key, $arg, $field=''){
		return $this->get_item($key.'.'.$arg, $field);
	}

	public function has_item($item, $field=''){
		return $this->handle_item('has', null, $item, $field);
	}

	public function add_item($key, ...$args){
		if(!$args || !$this->is_keyable($key)){
			$item	= $key;
			$key	= null;
		}else{
			$item	= array_shift($args);
		}

		$cb		= ($args && is_closure($args[0])) ? array_shift($args) : '';
		$field	= array_shift($args) ?: '';

		return $this->handle_item('add', $key, $item, $field, $cb);
	}

	public function is_keyable($key){
		return is_int($key) || is_string($key) || is_null($key);
	}

	public function remove_item($item, $field=''){
		return $this->handle_item('remove', null, $item, $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->handle_item('edit', $key, $item, $field);
	}

	public function replace_item($key, $item, $field=''){
		return $this->handle_item('replace', $key, $item, $field);
	}

	public function set_item($key, $item, $field=''){
		return $this->handle_item('set', $key, $item, $field);
	}

	public function delete_item($key, $field=''){
		$result	= $this->handle_item('delete', $key, null, $field);

		if(!is_wp_error($result)){
			$this->after_delete_item($key, $field);
		}

		return $result;
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		if(wpjam_is_assoc_array($orders)){
			[$orders, $field]	= array_values(wpjam_pull($orders, ['item', '_field']));
		}

		$items	= $this->get_items($field);
		$items	= array_merge(wpjam_pull($items, $orders), $items);

		return $this->update_items($items, $field);
	}

	protected function handle_item($action, $key, $item, $field='', $cb=false){
		$items	= $this->get_items($field);

		if($action == 'get'){
			return $items[$key] ?? null;
		}elseif($action == 'exists'){
			return wpjam_exists($items, $key);
		}elseif($action == 'has'){
			return in_array($item, $items);
		}

		$result	= $this->validate_item($item, $key, $action, $field);

		if(is_wp_error($result)){
			return $result;
		}

		$invalid	= fn($msg)=> new WP_Error('invalid_item_key', $msg);

		if(isset($item)){
			$item	= $this->sanitize_item($item, $key, $action, $field);
			$index	= $cb ? array_find_index($items, $cb) : null;
		}

		if(isset($key)){
			if($this->item_exists($key, $field)){
				if($action == 'add'){
					return $invalid('「'.$key.'」已存在，无法添加');
				}
			}else{
				if(in_array($action, ['edit', 'replace'])){
					return $invalid('「'.$key.'」不存在，无法编辑');
				}elseif($action == 'delete'){
					return $invalid('「'.$key.'」不存在，无法删除');
				}
			}

			if(isset($item)){
				if(is_numeric($index)){
					$items	= wpjam_add_at($items, $index, $key, $item);
				}else{
					$items[$key]	= $item;
				}
			}else{
				unset($items[$key]);
			}
		}else{
			if($action == 'add'){
				if(is_numeric($index)){
					array_splice($items, $index, 0, [$item]);
				}else{
					array_push($items, $item);
				}
			}elseif($action == 'remove'){
				$items	= array_diff($items, [$item]);
			}else{
				return $invalid('key不能为空');
			}
		}

		return $this->update_items($items, $field);
	}

	protected function validate_item($item, $key, $action='', $field=''){
		return true;
	}

	protected function sanitize_item($item, $key, $action='', $field=''){
		return $item;
	}

	protected function after_delete_item($key, $field=''){
		return true;
	}

	public static function get_item_actions(){
		$args	= [
			'row_action'	=> false,
			'data_callback'	=> fn($id)=> wpjam_try([get_called_class(), 'get_item'], $id, ...array_values(wpjam_get_data_parameter(['i', '_field']))),
			'value_callback'=> fn()=> '',
			'callback'		=> function($id, $data, $action){
				$args	= array_values(wpjam_get_data_parameter(['i', '_field']));
				$args	= $action == 'del_item' ? $args : wpjam_add_at($args, 1, null, $data);

				return wpjam_try([get_called_class(), $action], $id, ...$args);
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
		if(wpjam_exists($this->get_args(), $key)){
			return true;
		}

		return $this->$key !== null;
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
		if(!$this->args && !is_array($this->args)){
			$this->args = [];
		}

		return $this->args;
	}

	public function get_args(){
		return $this->filter_args();
	}

	public function set_args($args){
		$this->args	= $args;

		return $this;
	}

	public function update_args($args, $replace=true){
		$this->args	= ($replace ? 'array_replace' : 'wp_parse_args')($this->get_args(), $args);

		return $this;
	}

	public function get_arg($key, $default=null){
		return wpjam_get($this->get_args(), $key, $default);
	}

	public function update_arg($key, $value=null){
		$this->args	= wpjam_set($this->get_args(), $key, $value);

		return $this;
	}

	public function delete_arg($key){
		$this->args	= wpjam_except($this->get_args(), $key);

		return $this;
	}

	public function pull($key, ...$args){
		$this->filter_args();

		return wpjam_pull($this->args, $key, ...$args);
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
		if(!$type || $type != 'property'){
			$model	= (!$type || $type == 'model') ? $this->model : $type;

			if($model && method_exists($model, $name)){
				return [$model, $name];
			}
		}

		if(!$type || $type == 'property'){
			if($this->$name && is_callable($this->$name)){
				return $this->bind_if_closure($this->$name);
			}
		}
	}

	public function call_method($method, ...$args){
		$called	= $this->parse_method($method);

		if($called){
			return $called(...$args);
		}

		if(str_starts_with($method, 'filter_')){
			return array_shift($args);
		}
	}

	protected function call_property($property, ...$args){
		$called	= $this->parse_method($property, 'property');

		return $called ? $called(...$args) : null;
	}

	protected function call_model($method, ...$args){
		$called	= $this->parse_method($method, 'model');

		return $called ? $called(...$args) : null;
	}

	protected function error($code, $msg){
		return new WP_Error($code, $msg);
	}
}

class WPJAM_Register extends WPJAM_Args{
	use WPJAM_Items_Trait;

	protected $name;
	protected $_group;
	protected $_filtered;

	public function __construct($name, $args=[], $group=''){
		$this->name		= $name;
		$this->args		= $args;
		$this->_group	= self::parse_group($group);

		if($this->is_active() || !empty($args['active'])){
			$this->args	= $this->preprocess_args($args);
		}

		$this->args	= array_merge($this->args, ['name'=>$name]);
	}

	protected function preprocess_args($args){
		$group	= $this->_group;
		$config	= $group->get_config('model') ?? true;
		$model	= $config ? wpjam_get($args, 'model') : null;

		if($model || !empty($args['hooks']) || !empty($args['init'])){
			$file	= wpjam_pull($args, 'file');

			if($file && is_file($file)){
				include_once $file;
			}
		}

		if($model && is_subclass_of($model, 'WPJAM_Register')){
			trigger_error('「'.(is_object($model) ? get_class($model) : $model).'」是 WPJAM_Register 子类');
		}

		if($model){
			if($config === 'object'){
				if(!is_object($model)){
					if(class_exists($model, true)){
						$model = $args['model']	= new $model(array_merge($args, ['object'=>$this]));
					}else{
						trigger_error('model 无效');
					}
				}
			}else{
				$group->handle_model('add', $model, $this);
			}

			$args	= array_merge($args, wpjam_array([
				'hooks'	=> ['add_hooks', true],
				'init'	=> ['init', $group->get_config('init')]
			], fn($k, $v)=> ($args[$k] ?? $v[1]) === true ? [$k, $this->parse_method($v[0], $model)] : null));
		}

		wpjam_hooks(wpjam_pull($args, 'hooks'));
		wpjam_init(wpjam_pull($args, 'init'));

		return $args;
	}

	protected function filter_args(){
		if(!$this->_filtered){
			$this->_filtered	= true;

			$class		= self::get_called();
			$filter		= $class == 'wpjam_register' ? 'wpjam_'.$this->_group->name : $class;
			$this->args	= apply_filters($filter.'_args', $this->args, $this->name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key);

		if(is_null($value)){
			if($this->model && $key && is_string($key) && !str_contains($key, '.')){
				$value	= $this->parse_method('get_'.$key, 'model');
			}
		}else{
			$value	= $this->bind_if_closure($value);
		}

		if($do_callback){
			$value	= maybe_callback($value, $this->name);
		}

		return $value ?? $default;
	}

	public function get_parent(){
		return $this->sub_name ? self::get($this->name) : null;
	}

	public function is_sub(){
		return (bool)$this->sub_name;
	}

	public function get_sub($name){
		return $this->get_item($name, 'subs');
	}

	public function get_subs(){
		return $this->get_items('subs');
	}

	public function register_sub($name, $args){
		$sub	= new static($this->name, array_merge($args, ['sub_name'=>$name]));

		$this->add_item($name, $sub, 'subs');

		return self::register($this->name.':'.$name, $sub);
	}

	public function unregister_sub($name){
		$this->delete_item($name, 'subs');

		return self::unregister($this->name.':'.$name);
	}

	public function is_active(){
		return true;
	}

	public static function validate_name($name){
		$prefix	= self::class.'的注册 name';

		if(empty($name)){
			trigger_error($prefix.' 为空');
			return;
		}elseif(is_numeric($name)){
			trigger_error($prefix.'「'.$name.'」'.'为纯数字');
			return;
		}elseif(!is_string($name)){
			trigger_error($prefix.'「'.var_export($name, true).'」不为字符串');
			return;
		}

		return true;
	}

	protected static function get_defaults(){
		return [];
	}

	protected static function parse_group($name=''){
		$called	= get_called_class();
		$group	= WPJAM_Register_Group::get_instance(strtolower($name ?: $called));

		if(!$group->called){
			$group->called		= $called;
			$group->defaults	= $called::get_defaults();

			if($route = $group->get_config('route')){
				wpjam_register_route($route, ['model'=>$called]);
			}
		}

		return $group;
	}

	public static function call_group($method, ...$args){
		[$method, $group]	= str_contains($method, '_by_') ? explode('_by_', $method) : [$method, ''];

		return [self::parse_group($group), $method](...$args);
	}

	public static function register($name, $args=[]){
		return self::call_group('add_object', $name, $args);
	}

	public static function re_register($name, $args, $merge=true){
		self::unregister($name);

		return self::register($name, $args);
	}

	public static function unregister($name){
		self::call_group('remove_object', $name);
	}

	public static function get_registereds($args=[], $output='objects', $operator='and'){
		$objects	= self::call_group('get_objects', $args, $operator);

		return $output == 'names' ? array_keys($objects) : $objects;
	}

	public static function get_by(...$args){
		$args	= $args ? (is_array($args[0]) ? $args[0] : [$args[0]=> $args[1]]) : [];

		return self::get_registereds($args);
	}

	public static function get($name, $by='', $top=''){
		return self::call_group('get_object', $name, $by, $top);
	}

	public static function exists($name){
		return (bool)self::get($name);
	}

	public static function get_setting_fields($args=[]){
		return self::call_group('get_fields', $args);
	}

	public static function get_active($key=null){
		return self::call_group('get_active', $key);
	}

	public static function call_active($method, ...$args){
		return self::call_group('call_active', $method, ...$args);
	}

	public static function by_active(...$args){
		$name	= current_filter();
		$method = (did_action($name) ? 'on_' : 'filter_').substr($name, str_starts_with($name, 'wpjam_') ? 6 : 0);

		return self::call_active($method, ...$args);
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function get_objects($args=[], $operator='AND'){
		wpjam_map(($this->pull('defaults') ?: []), [$this, 'add_object']);

		return $args ? wpjam_filter($this->get_items(), $args, $operator) : $this->get_items();
	}

	public function get_object($name, $by='', $top=''){
		if(!$name){
			return;
		}

		if($by == 'model'){
			if($name && strcasecmp($name, $top) !== 0){
				return $this->handle_model('get', $name) ?: $this->get_object(get_parent_class($name), $by, $top);
			}
		}else{
			$object	= $this->get_item($name);

			if(!$object && $this->defaults && isset($this->defaults[$name])){
				$object	= $this->add_object($name, $this->defaults[$name]);

				$this->defaults	= wpjam_except($this->defaults, $name);
			}

			return $object;
		}
	}

	public function add_object($name, $object){
		$class	= $this->called;
		$count	= count($this->get_items());

		if(is_object($name)){
			$object	= $name;
			$name	= $object->name ?? null;
		}elseif(is_array($name)){
			[$object, $name]	= [$name, $object];

			$name	= wpjam_pull($object, 'name') ?: ($name ?: '__'.$count);
		}

		if(!$class::validate_name($name)){
			return;
		}

		if($this->get_item($name)){
			trigger_error($this->name.'「'.$name.'」已经注册。');
		}

		if(is_array($object)){
			if(!empty($object['admin']) && !is_admin()){
				return;
			}

			$object	= new $class($name, $object);
		}

		if($orderby = $this->get_config('orderby')){
			$by		= $orderby === true ? 'order' : $orderby;
			$order	= $this->get_config('order') ?? 'DESC';
			$score	= wpjam_get($object, $by, 10);
			$comp	= ($order == 'DESC' ? '>' : '<');
			$args[]	= fn($v)=> wpjam_compare($score, $comp, wpjam_get($v, $by, 10));
		}else{
			$args	= [];
		}

		$this->add_item($name, $object, ...$args);

		if(method_exists($object, 'registered')){
			$object->registered($count+1);
		}

		return $object;
	}

	public function remove_object($name){
		$object	= $this->get_item($name);

		if($object){
			$this->handle_model('delete', $object->model);
			$this->delete_item($name);
		}
	}

	public function handle_model($action, $model, $object=null){
		$model	= ($model && is_string($model)) ? strtolower($model) : null;

		if($model){
			return $this->handle_item($action, $model, $object, 'models');
		}
	}

	public function get_config($key){
		if($this->called == 'WPJAM_Register'){
			return;
		}

		if(is_null($this->config)){
			$ref	= new ReflectionClass($this->called);

			if(method_exists($ref, 'getAttributes')){
				$args	= $ref->getAttributes('config');
				$args	= $args ? $args[0]->getArguments() : [];
				$args	= $args ? (is_array($args[0]) ? $args[0] : $args) : [];
			}else{
				$args	= preg_match_all('/@config\s+([^\r\n]*)/', $ref->getDocComment(), $matches) ? wp_parse_list($matches[1][0]) : [];
			}

			$this->config = wpjam_array($args, fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v) : [$v, true]) : [$k, $v]);
		}

		return $this->config[$key] ?? null;
	}

	public function get_active($key=null){
		$objects	= array_filter($this->get_objects(), fn($object)=> $object->active ?? $object->is_active());

		return $key ? array_filter(array_map(fn($object)=> $object->get_arg($key), $objects), fn($v)=> !is_null($v)) : $objects;
	}

	public function call_active($method, ...$args){
		$type	= array_find(['filter', 'get'], fn($type)=> str_starts_with($method, $type.'_'));

		foreach($this->get_active() as $object){
			$result	= $object->call_method($method, ...$args);	// 不能调用对象本身的方法，会死循环

			if(is_wp_error($result)){
				return $result;
			}

			if($type == 'filter'){
				$args[0]	= $result;
			}elseif($type == 'get'){
				if($result && is_array($result)){
					$return	= array_merge(($return ?? []), $result);
				}
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}

	public function get_fields($args=[]){
		$title_field	= wpjam_pull($args, 'title_field') ?: 'title';
		$name_field		= wpjam_pull($args, 'name_field') ?: 'name';
		$objects		= $this->get_objects(wpjam_pull($args, 'filter_args'));

		if(wpjam_get($args, 'type') == 'select'){
			return [wpjam_pull($args, 'name')=> $args+[
				'show_option_none'	=> __('&mdash; Select &mdash;'),
				'options'			=> wpjam_array($objects, fn($k, $v)=> [$v->$name_field, array_filter([
					'title'			=> $v->$title_field,
					'description'	=> $v->description,
					'fields'		=> $v->get_arg('fields')
				])])
			]];
		}

		return wpjam_array($objects, fn($k, $v)=> isset($v->active) ? null : [$v->$name_field, ($v->field ?: [])+['label'=>$v->$title_field]]);
	}

	protected static $_groups	= [];

	public static function __callStatic($method, $args){
		foreach(self::$_groups as $group){
			if($method == 'register_json'){
				if($group->get_config($method)){
					$group->call_active($method, $args[0]);
				}
			}elseif(in_array($method, ['add_menu_page', 'add_admin_load'])){
				$key	= substr($method, 4);

				if($group->get_config($key)){
					array_map('wpjam_'.$method, $group->get_active($key));
				}
			}
		}
	}

	public static function get_instance($name){
		if(!self::$_groups){
			add_action('wpjam_api',	[self::class, 'register_json']);

			if(is_admin()){
				add_action('wpjam_admin_init',	[self::class, 'add_menu_page']);
				add_action('wpjam_admin_init',	[self::class, 'add_admin_load']);
			}
		}

		return self::$_groups[$name]	??= new self(['name'=>$name]);
	}
}

class WPJAM_AJAX extends WPJAM_Register{
	public function registered($count){
		$this->add_action();

		if($this->nopriv){
			$this->add_action('nopriv_');
		}

		if($count == 1 && !is_admin()){
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
	}

	public function add_action($part=''){
		add_action('wp_ajax_'.$part.$this->name, [$this, 'callback']);
	}

	public function callback(){
		add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_Error', 'wp_die_handler']);

		if(!$this->callback || !is_callable($this->callback)){
			wp_die('invalid_callback');
		}

		$data	= wpjam_get_data_parameter();
		$data	= array_merge($data, wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
		$result	= wpjam_if_error(wpjam_fields($this->fields)->catch('validate', $data, 'parameter'), 'send');
		$data	= array_merge($data, $result);

		if($this->verify !== false && !check_ajax_referer($this->get_nonce_action($data), false, false)){
			wp_die('invalid_nonce');
		}

		$result	= wpjam_catch($this->callback, $data, $this->name);
		$result	= $result === true ? [] : $result;

		wpjam_send_json($result);
	}

	public function get_attr($data=[], $return=null){
		$attr	= ['action'=>$this->name, 'data'=>$data];
		$attr	= array_merge($attr, $this->verify !== false ? ['nonce'=> wp_create_nonce($this->get_nonce_action($data))] : []);

		return $return ? $attr : wpjam_attr($attr, 'data');
	}

	protected function get_nonce_action($data){
		$data	= array_filter(wp_array_slice_assoc($data, ($this->nonce_keys ?: [])));

		return $this->name.($data ? ':'.implode(':', $data) : '');
	}
}

class WPJAM_Verify_TXT extends WPJAM_Register{
	public function get_fields(){
		return [
			'name'	=>['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$this->get_data('name'),	'class'=>'all-options'],
			'value'	=>['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$this->get_data('value')]
		];
	}

	public function get_data($key=''){
		$data	= wpjam_get_setting('wpjam_verify_txts', $this->name) ?: [];

		return $key ? ($data[$key] ?? '') : $data;
	}

	public function set_data($data){
		return wpjam_update_setting('wpjam_verify_txts', $this->name, $data) || true;
	}

	public static function __callStatic($method, $args){	// 放弃
		$name	= $args[0];

		if($object = self::get($name)){
			if(in_array($method, ['get_name', 'get_value'])){
				return $object->get_data(str_replace('get_', '', $method));
			}elseif($method == 'set' || $method == 'set_value'){
				return $object->set_data(['name'=>$args[1], 'value'=>$args[2]]);
			}
		}
	}

	public static function filter_root_rewrite_rules($root_rewrite){
		if(empty($GLOBALS['wp_rewrite']->root)){
			$home_path	= parse_url(home_url());

			if(empty($home_path['path']) || '/' == $home_path['path']){
				$root_rewrite	= array_merge(['([^/]+)\.txt?$'=>'index.php?module=txt&action=$matches[1]'], $root_rewrite);
			}
		}

		return $root_rewrite;
	}

	public static function get_rewrite_rule(){
		add_filter('root_rewrite_rules',	[self::class, 'filter_root_rewrite_rules']);
	}

	public static function redirect($action){
		$txts	= wpjam_get_option('wpjam_verify_txts');
		$txt	= $txts ? array_find($txts, fn($v)=> $v['name'] == str_replace('.txt', '', $action).'.txt') : '';

		if($txt){
			header('Content-Type: text/plain');
			echo $txt['value'];

			exit;
		}
	}
}

class WPJAM_Data_Processor extends WPJAM_Args{
	private $data	= [];

	protected function __construct($args=[]){
		$this->args		= $args;
		$this->formulas	= wpjam_map($this->formulas, [$this, 'parse_formula']);
	}

	public function validate(){
		$this->sorted	= [];
		$status			= [];

		foreach($this->formulas as $key => $formula){
			wpjam_if_error($formula, 'throw');

			if(!isset($status[$key])){
				$this->sort_formular($formula, $key, $status);
			}
		}

		return true;
	}

	public function parse_formula($formula, $key){
		if(is_array($formula)){
			return array_map(fn($f)=> array_merge($f, ['formula'=>$this->parse_formula($f['formula'], $key)]), $formula);
		}

		$formula	= preg_replace('@\s@', '', $formula);
		$signs		= ['+', '-', '*', '/', '(', ')', ',', '%'];
		$pattern	= '/([\\'.implode('\\', $signs).'])/';
		$formula	= preg_split($pattern, $formula, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		$methods	= ['abs', 'ceil', 'pow', 'sqrt', 'pi', 'max', 'min', 'fmod', 'round'];
		$stack		= [];

		foreach($formula as $t){
			if(is_numeric($t)){
				if(str_ends_with($t, '.')){
					return $this->invalid_formula($key, '无效数字「'.$t.'」');
				}
			}elseif(str_starts_with($t, '$')){
				if(!in_array(substr($t, 1), array_keys($this->fields))){
					return $this->invalid_formula($key, '「'.$t.'」未定义');
				}
			}elseif($t == '('){
				array_push($stack, '(');
			}elseif($t == ')'){
				if(empty($stack)){
					return $this->invalid_formula($key, '括号不匹配');
				}

				array_pop($stack);
			}else{
				if(!in_array($t, $signs) && !in_array(strtolower($t), $methods)){
					return $this->invalid_formula($key, '无效的「'.$t.'」');
				}
			}
		}

		return $stack ? $this->invalid_formula($key, '括号不匹配') : $formula;
	}

	protected function invalid_formula($key, $msg){
		return new WP_Error('invalid_formula', '字段'.wpjam_get($this->fields[$key], 'title').'「'.$key.'」'.'公式「'.$this->formulas[$key].'」错误，'.$msg);
	}

	protected function sort_formular($formula, $key, &$status){
		if(isset($status[$key])) {
			return $status[$key] === 1 ? [$key] : null;
		}

		$status[$key]	= 1;
		$formulas		= is_array($formula[0]) ? array_column($formula, 'formula') : [$formula];

		foreach($formulas as $formula){
			foreach($formula as $t){
				if(try_remove_prefix($t, '$') && isset($this->formulas[$t])){
					$cycle	= $this->sort_formular(wpjam_if_error($this->formulas[$t], 'throw'), $t, $status);

					if($cycle){
						if(in_array($key, $cycle)){
							wpjam_throw('cycle_detected', '公式嵌套：'.implode(' → ', [$key, ...$cycle]));
						}

						return [$key, ...$cycle];
					}
				}
			}
		}

		$status[$key]	= 2;
		$this->sorted	= [...$this->sorted, $key];
	}

	public function process($items, $args=[]){
		$args	= wp_parse_args($args, ['calc'=>true, 'sum'=>true, 'format'=>false, 'orderby'=>'', 'order'=>'']);

		if($args['sum']){
			$sums	= [];
		}

		foreach($items as &$item){
			if($args['calc']){
				$item	= $this->calc($item);
			}

			if($args['orderby']){
				$item[$args['orderby']]	??= 0;
			}

			if($args['sum']){
				foreach($this->sumable as $k => $v){
					if($v == 1 && isset($item[$k]) && is_numeric($item[$k])){
						$sums[$k]	= ($sums[$k] ?? 0)+$item[$k];
					}
				}
			}
		}

		if($args['orderby']){
			$items	= wpjam_sort($items, [$args['orderby'] => $args['order']]);
		}

		if($args['sum']){
			$items	= wpjam_add_at($items, 0, null, $this->calc($sums, ['sum'=>true])+(is_array($args['sum']) ? $args['sum'] : []));
		}

		if($args['format']){
			foreach($items as &$item){
				foreach($this->formats as $k => $v){
					if(isset($item[$k]) && is_numeric($item[$k])){
						$item[$k]	= wpjam_format($item[$k], ...$v);
					}
				}
			}
		}

		return $items;
	}

	public function calc($item, $args=[]){
		if(!$item || !is_array($item)){
			return $item;
		}

		if(!isset($this->sorted)){
			$this->validate();
		}

		$args		= wp_parse_args($args, ['sum'=>false, 'key'=>'']);
		$formulas	= $this->formulas;
		$if_errors	= $this->if_errors ?: [];

		if($args['key']){
			$key		= $args['key'];
			$if_error	= $if_errors[$key] ?? null;
			$formula	= $formulas[$key];

			if(is_array($formula[0])){
				$f	= array_find($formula, fn($f)=> wpjam_match($item, $f));

				if(!$f){
					return '';
				}

				$formula	= $f['formula'];
			}

			foreach($formula as &$t){
				if(str_starts_with($t, '$')){
					$k	= substr($t, 1);

					if(isset($item[$k]) && is_numeric(trim($item[$k]))){
						$t	= (float)$item[$k];
						$t	= $t < 0 ? '('.$t.')' : $t;
					}else{
						$t	= $if_errors[$k] ?? null;

						if(!isset($t)){
							return $if_error ?? (isset($item[$k]) ? '!!无法计算' : '!无法计算');
						}
					}
				}
			}

			set_error_handler(function($errno, $errstr){
				if(str_contains($errstr , 'Division by zero')){
					throw new DivisionByZeroError($errstr); 
				}

				throw new ErrorException($errstr , $errno); 
			});

			try{
				return eval('return '.implode($formula).';');
			}catch(DivisionByZeroError $e){
				return $if_error ?? '!除零错误';
			}catch(throwable $e){
				return $if_error ?? '!计算错误：'.$e->getMessage();
			}finally{
				restore_error_handler();
			}
		}

		if($args['sum'] && $formulas){
			$formulas	= array_intersect_key($formulas, array_filter($this->sumable, fn($v)=> $v == 2));
		}

		$formulas	= $formulas && $this->sorted ? wpjam_pick($formulas, $this->sorted) : [];

		if(!$formulas){
			return $item;
		}
		
		$item	= wpjam_except($item, array_keys($formulas));

		foreach($formulas as $key => $formula){
			if(!is_array($formula)){
				$item[$key]	= is_wp_error($formula) ? '!公式错误' : $formula;
			}else{
				$item[$key]	= $this->calc($item, array_merge($args, ['key'=>$key]));
			}
		}

		return $item;
	}

	public function sum($items, $calc=false){
		if(($this->sumable || $calc) && $items){
			return $this->calc($this->process($items, ['sum'=>true, 'calc'=>$calc])[0], ['sum'=>true]);
		}
	}

	public function accumulate($item=null, $group=''){
		if($item){
			$item	= $this->calc($item);
			$value	= $group ? ($item[$group] ?? '') : '__';
			$keys	= $this->sumable ? array_keys(array_filter($this->sumable, fn($v)=> $v == 1)) : [];

			$this->data[$group][$value]	??= array_merge($item, array_fill_keys($keys, 0));

			foreach($keys as $k){
				if(isset($item[$k]) && is_numeric($item[$k])){
					$this->data[$group][$value][$k]	+= $item[$k];
				}
			}

			return $this->data[$group];
		}

		$items	= wpjam_pull($this->data, $group) ?: [];
		$items	= array_map(fn($item)=> $this->calc($item, ['sum'=>true]), $items);

		return $group ? $items : ($items['__'] ?? []);
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

		if(is_wp_error($response)){
			return false;
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

			if($type == 'plugin' && $hostname == 'blog.wpjam.com'){
				add_filter('pre_set_site_transient_update_plugins', [$object, 'filter_pre_set_site_transient']);
			}
		}
	}
}

class WPJAM_Cache extends WPJAM_Args{
	public function __call($method, $args){
		$method	= substr($method, str_starts_with($method, 'cache_') ? 6 : 0);
		$gnd	= str_contains($method, 'get') || str_contains($method, 'delete');
		$cb		= $method == 'cas' ? [array_shift($args)] : [];
		$key	= array_shift($args);

		if(str_contains($method, '_multiple')){
			$cb[]	= $gnd ? array_map([$this, 'key'], $key) : wpjam_array($key, fn($k)=> $this->key($k));
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
			$result	= wpjam_array($key, fn($i, $k) => [$k, $result[$cb[0][$i]]]);
			$result	= array_filter($result, fn($v) => $v !== false);
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

			if(!$code || (int)$code !== (int)$this->get($key.':code')){
				$this->failed_times($key, true);
			}

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
				if($times > $this->failed_times){
					wpjam_throw('failed_times_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
				}
			}
		}
	}

	protected function interval($key){
		if($this->interval){
			if($this->get($key.':time')){
				wpjam_throw('error', '验证码'.$this->interval.'分钟前已发送了。');
			}

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
				if(wpjam_pull($args, 'global')){
					wp_cache_add_global_groups($args['group']);
				}

				return new self($args);
			}
		}
	}
}