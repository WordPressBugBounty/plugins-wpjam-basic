<?php
trait WPJAM_Call_Trait{
	protected static $_closures	= [];

	protected static function get_called(){
		return strtolower(get_called_class());
	}

	protected function bind_if_closure($closure){
		return is_closure($closure) ? $closure->bindTo($this, get_called_class()) : $closure;
	}

	public static function dynamic_method($action, $method, ...$args){
		if(!$method){
			return;
		}

		$name	= self::get_called();

		if($action == 'add'){
			if(is_closure($args[0])){
				self::$_closures[$name][$method]	= $args[0];
			}
		}elseif($action == 'remove'){
			unset(self::$_closures[$name][$method]);
		}elseif($action == 'get'){
			$closure	= self::$_closures[$name][$method] ?? null;

			return $closure ?: (($parent = get_parent_class($name)) ? $parent::dynamic_method('get', $method) : null);
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
		return $this->handle_item('get', $key, null, $field);
	}

	public function get_item_arg($key, $arg, $field=''){
		return ($item = $this->get_item($key, $field)) ? wpjam_get($item, $arg) : null;
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

		$invalid	= fn($title)=> new WP_Error('invalid_item_key', $title);

		if(isset($item)){
			$item	= $this->sanitize_item($item, $key, $action, $field);
			$index	= $cb ? wpjam_find($items, $cb, 'index') : false;
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
				if($index !== false){
					$items	= wpjam_add_at($items, $index, $key, $item);
				}else{
					$items[$key]	= $item;
				}
			}else{
				unset($items[$key]);
			}
		}else{
			if($action == 'add'){
				if($index !== false){
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

	protected static function get_instance_for_item($id){
		if(!method_exists(get_called_class(), 'get_instance')){
			wp_die($model.'->get_instance() 未定义');
		}

		$object	= static::get_instance($id);

		return $object ?: wp_die('invaid_id');
	}

	public static function item_list_action($id, $data, $action=''){
		$object	= self::get_instance_for_item($id);
		$i		= wpjam_get_data_parameter('i');
		$field	= wpjam_get_data_parameter('_field');

		if($action == 'del_item'){
			return $object->del_item($i, $field);
		}elseif($action == 'move_item'){
			return $object->move_item(wpjam_get_data_parameter('item') ?: [], $field);
		}elseif(method_exists($object, $action)){	// 'add_item' 'edit_item'
			return $object->$action($i, $data, $field);
		}
	}

	public static function item_data_action($id){
		$object	= self::get_instance_for_item($id);
		$i		= wpjam_get_data_parameter('i');

		return isset($i) ? $object->get_item($i) : [];
	}

	public static function get_item_actions(){
		$item_action	= [
			'callback'		=> [self::class, 'item_list_action'],
			'data_callback'	=> [self::class, 'item_data_action'],
			'value_callback'=> fn()=> '',
			'row_action'	=> false,
		];

		return [
			'add_item'	=>['page_title'=>'新增项目',	'title'=>'新增',	'dismiss'=>true]+$item_action,
			'edit_item'	=>['page_title'=>'修改项目',	'dashicon'=>'edit']+$item_action,
			'del_item'	=>['page_title'=>'删除项目',	'dashicon'=>'no-alt',	'class'=>'del-icon',	'direct'=>true,	'confirm'=>true]+$item_action,
			'move_item'	=>['page_title'=>'移动项目',	'dashicon'=>'move',		'class'=>'move-item',	'direct'=>true]+$item_action,
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
		$this->args	= ($replace ? 'array_merge' : 'wp_parse_args')($this->get_args(), $args);

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

	public function pull($key, $default=null){
		$this->filter_args();

		return wpjam_pull($this->args, $key, $default);
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
			$model	= ($type == 'model' || !$type) ? $this->model : $type;

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

			foreach([
				['hooks', 'add_hooks', true],
				['init', 'init', $group->get_config('init')],
			] as list($key, $method, $default)){
				if(($args[$key] ?? $default) === true){
					$args[$key]	= $this->parse_method($method, $model);	
				} 
			}
		}

		wpjam_hooks(wpjam_pull($args, 'hooks'));
		wpjam_load('init', wpjam_pull($args, 'init'));

		return $args;
	}

	protected function filter_args(){
		if(!$this->_filtered){
			$this->_filtered	= true;

			$class		= self::get_called();
			$filter		= $class == 'wpjam_register' ? 'wpjam_'.$this->_group->name.'_args' : $class.'_args';
			$this->args	= apply_filters($filter, $this->args, $this->name);
		}

		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key);

		if(is_null($value)){
			if($this->model && $key && is_string($key) && !str_contains($key, '.')){
				$value	= $this->parse_method('get_'.$key, 'model');
			}
		}elseif(is_callable($value)){
			$value	= $this->bind_if_closure($value);
		}

		if($do_callback && is_callable($value)){
			return $value($this->name);
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
		$args	= array_merge($args, ['sub_name'=>$name]);
		$sub	= new static($this->name, $args);

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
			$group->init($called, $called::get_defaults());
		}

		return $group;
	}

	public static function call_group($method, ...$args){
		[$method, $group]	= str_contains($method, '_by_') ? explode('_by_', $method) : [$method, ''];

		return (self::parse_group($group))->$method(...$args);
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
		$args	= $args ? (is_array($args[0]) ? $args[0] : [$args[0] => $args[1]]) : [];

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
		$method = (did_action($name) ? 'on_' : 'filter_').(wpjam_remove_prefix($name, 'wpjam_'));

		return self::call_active($method, ...$args);
	}
}

class WPJAM_Register_Group extends WPJAM_Args{
	use WPJAM_Items_Trait;

	public function init($called, $defaults){
		$this->called	= $called;
		$this->defaults	= $defaults;

		wpjam_register_route($this->get_config('route'), ['model'=>$called]);
	}

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

			if(!$object){
				$defaults	= $this->defaults;

				if($defaults && isset($defaults[$name])){
					$args	= wpjam_pull($defaults, $name);
					$object	= $this->add_object($name, $args);

					$this->defaults	= $defaults;
				}
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

		$orderby	= $this->get_config('orderby');

		if($orderby){
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
		$type	= wpjam_find(['filter', 'get'], fn($type)=> str_starts_with($method, $type.'_'));

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
		if($this->get_config('single')){
			$args	+= [
				'name'				=> wpjam_remove_prefix(strtolower($this->called), 'wpjam_'),
				'title'				=> '',
				'title_field'		=> 'title',
				'show_option_none'	=> __('&mdash; Select &mdash;'),
				'option_none_value'	=> ''
			];

			$options	= $args['show_option_none'] ? [$args['option_none_value']=> $args['show_option_none']] : [];
			$options	+= wpjam_map($this->get_objects(), fn($object)=>[
				'title'			=> $object->{$args['title_field']},
				'description'	=> $object->description,
				'fields'		=> $object->get_arg('fields') ?: []
			]);

			return [$args['name']=> ['title'=>$args['title'], 'type'=>'select', 'options'=>$options]];
		}

		return wpjam_array($this->get_objects(), fn($name, $object)=> isset($object->active) ? null : [$name, ($object->field ?: [])+['type'=>'checkbox', 'label'=>$object->title]]);
	}

	protected static $_groups	= [];

	public static function __callStatic($method, $args){
		foreach(self::$_groups as $group){
			if($method == 'register_json'){
				if($group->get_config($method)){
					$group->call_active($method, $args[0]);
				}
			}elseif(in_array($method, ['add_menu_page', 'add_admin_load'])){
				$key	= wpjam_remove_prefix($method, 'add_');

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

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_option')){	// get_option register_option unregister_option
			$name	= array_shift($args);

			if($method == 'register_option'){
				$args	= array_merge(array_shift($args), ['meta_type'=>$this->name]);

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

				$args	= [new WPJAM_Meta_Option($name, $args)];
			}

			$args		= [$this->name.':'.$name, ...$args];
			$callback	= ['WPJAM_Meta_Option', wpjam_remove_postfix($method, '_option')];
		}elseif(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			$args		= [$this->name, ...$args];
			$callback	= str_replace('data', 'metadata', $method);
		}elseif(str_ends_with($method, '_by_mid')){
			$args		= [$this->name, ...$args];
			$callback	= str_replace('_by_mid', '_metadata_by_mid', $method);
		}elseif(str_ends_with($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '_data', $method)];
		}elseif(str_contains($method, '_meta')){
			$callback	= [$this, str_replace('_meta', '', $method)];
		}

		if(empty($callback)){
			trigger_error('无效的方法'.$method);
			return;
		}

		return $callback(...$args);
	}

	protected function preprocess_args($args){
		$wpdb	= $GLOBALS['wpdb'];
		$table	= $args['table_name'] ?? $this->name.'meta';

		$wpdb->$table ??= $args['table'] ?? $wpdb->prefix.$this->name.'meta';

		return parent::preprocess_args($args);
	}

	public function lazyload_data($ids){
		wpjam_lazyload($this->name.'_meta', $ids);
	}

	public function get_options($args=[]){
		return wpjam_array(WPJAM_Meta_Option::get_by(array_merge($args, ['meta_type'=>$this->name])), fn($k, $v)=> $v->name);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object'){
		if($name == 'object'){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}
	}

	protected function parse_value($value){
		if(wp_is_numeric_array($value)){
			return maybe_unserialize($value[0]);
		}else{
			return array_merge($value, ['meta_value'=>maybe_unserialize($value['meta_value'])]);
		}
	}

	public function get_data_with_default($id, ...$args){
		if(!$args){
			return $this->get_data($id);
		}

		if(is_array($args[0])){
			$keys	= wpjam_array($args[0], fn($k, $v)=> is_numeric($k) ? [$v, null] : [$k, $v]);

			return ($id && $args[0]) ? wpjam_map($keys, fn($v, $k)=> $this->get_data_with_default($id, $k, $v)) : [];
		}else{
			if($id && $args[0]){
				if($args[0] == 'meta_input'){
					return array_map([$this, 'parse_value'], $this->get_data($id));
				}

				if($this->data_exists($id, $args[0])){
					return $this->get_data($id, $args[0], true);
				}
			}

			return $args[1] ?? null;
		}
	}

	public function get_by_key(...$args){
		global $wpdb;

		if(empty($args)){
			return [];
		}

		if(is_array($args[0])){
			$key	= $args[0]['meta_key'] ?? ($args[0]['key'] ?? '');
			$value	= $args[0]['meta_value'] ?? ($args[0]['value'] ?? '');
		}else{
			$key	= $args[0];
			$value	= $args[1] ?? null;
		}

		if($key){
			$where[]	= $wpdb->prepare('meta_key=%s', $key);
		}

		if(!is_null($value)){
			$where[]	= $wpdb->prepare('meta_value=%s', maybe_serialize($value));
		}

		if(empty($where)){
			return [];
		}

		$where	= implode(' AND ', $where);
		$table	= $this->get_table();
		$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

		return array_map([$this, 'parse_value'], $data);
	}

	public function update_data_with_default($id, ...$args){
		if(is_array($args[0])){
			$data	= $args[0];

			if(wpjam_is_assoc_array($data)){
				$defaults	= (isset($args[1]) && is_array($args[1])) ? $args[1] : [];

				if(isset($data['meta_input']) && wpjam_is_assoc_array($data['meta_input'])){
					$this->update_data_with_default($id, wpjam_pull($data, 'meta_input'), wpjam_pull($defaults, 'meta_input'));
				}

				wpjam_map($data, fn($v, $k)=> $this->update_data_with_default($id, $k, $v, wpjam_pull($defaults, $k)));
			}

			return true;
		}else{
			$key		= $args[0];
			$value		= $args[1];
			$default	= $args[2] ?? null;

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
	}

	public function cleanup(){
		if($this->object_key){
			$object_key		= $this->object_key;
			$object_table	= $GLOBALS['wpdb']->{$this->name.'s'};
		}else{
			$object_model	= $this->object_model;

			if($object_model && is_callable([$object_model, 'get_table'])){
				$object_table	= $object_model::get_table();
				$object_key		= $object_model::get_primary_key();
			}else{
				$object_table	= '';
				$object_key		= '';
			}
		}

		$this->delete_orphan_data($object_table, $object_key);
	}

	public function delete_orphan_data($object_table=null, $object_key=null){
		if($object_table && $object_key){
			$wpdb	= $GLOBALS['wpdb'];
			$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$object_table." t ON t.".$object_key." = m.".$this->get_column('object')." WHERE t.".$object_key." IS NULL") ?: [];

			array_walk($mids, [$this, 'delete_by_mid']);
		}
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

	public function create_table(){
		$table	= $this->get_table();

		if($GLOBALS['wpdb']->get_var("show tables like '{$table}'") != $table){
			$column	= $this->name.'_id';

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

	public static function get_defaults(){
		return array_merge([
			'post'	=> ['order'=>50,	'object_model'=>'WPJAM_Post',	'object_column'=>'title',	'object_key'=>'ID'],
			'term'	=> ['order'=>40,	'object_model'=>'WPJAM_Term',	'object_column'=>'name',	'object_key'=>'term_id'],
			'user'	=> ['order'=>30,	'object_model'=>'WPJAM_User',	'object_column'=>'display_name','object_key'=>'ID'],
		], (is_multisite() ? [
			'blog'	=> ['order'=>5,	'object_key'=>'blog_id'],
			'site'	=> ['order'=>5],
		] : []));
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_Meta_Option extends WPJAM_Register{
	public function __call($method, $args){
		if(str_ends_with($method, '_by_fields')){
			$id		= array_shift($args);
			$fields	= $this->get_fields($id);
			$object	= wpjam_fields($fields);
			$method	= wpjam_remove_postfix($method, '_by_fields');

			return $object->$method(...$args);
		}
	}

	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'list_table'){
			if(is_null($value) && did_action('current_screen') && !empty($GLOBALS['plugin_page'])){
				return true;
			}
		}elseif($key == 'callback'){
			if(!$value){
				return $this->update_callback;
			}
		}

		return $value;
	}

	public function get_fields($id=null){
		$fields	= $this->fields;

		return is_callable($fields) ? $fields($id, $this->name) : $fields;
	}

	public function register_list_table_action(){
		return wpjam_register_list_table_action($this->action_name ?: 'set_'.$this->name, $this->get_args()+[
			'page_title'	=> '设置'.$this->title,
			'submit_text'	=> '设置',
			'meta_type'		=> $this->name,
			'fields'		=> [$this, 'get_fields']
		]);
	}

	public function prepare($id=null){
		if($this->callback){
			return [];
		}

		return $this->prepare_by_fields($id, array_merge($this->get_args(), ['id'=>$id]));
	}

	public function validate($id=null, $data=null){
		return $this->validate_by_fields($id, $data);
	}

	public function render($id, $args=[]){
		if($this->meta_type == 'post' && isset($GLOBALS['current_screen'])){
			if($this->meta_box_cb){
				return call_user_func($this->meta_box_cb, $id, $args);
			}

			$args	= ['fields_type'=>$this->context == 'side' ? 'list' : 'table'];
			$id		= $GLOBALS['current_screen']->action == 'add' ? false : $id->ID;

			echo $this->summary ? wpautop($this->summary) : '';
		}

		echo $this->render_by_fields($id, array_merge($this->get_args(), ['id'=>$id], $args));
	}

	public function callback($id, $data=null){
		$fields	= $this->get_fields($id);
		$object	= wpjam_fields($fields);
		$data	= $object->validate($data);

		if(is_wp_error($data)){
			return $data;
		}elseif(!$data){
			return true;
		}

		if($this->callback){
			$result	= is_callable($this->callback) ? call_user_func($this->callback, $id, $data, $fields) : false;

			return $result === false ? new WP_Error('invalid_callback') : $result;
		}else{
			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}
	}

	public static function create($name, $args){
		$meta_type	= wpjam_get($args, 'meta_type');

		if($meta_type){
			$object	= new self($name, $args);

			return self::register($meta_type.':'.$name, $object);
		}
	}

	public static function get_by(...$args){
		$args		= is_array($args[0]) ? $args[0] : [$args[0] => $args[1]];
		$list_table	= wpjam_pull($args, 'list_table');
		$meta_type	= wpjam_get($args, 'meta_type');

		if(!$meta_type){
			return [];
		}

		if(isset($list_table)){
			$args['title']		= true;
			$args['list_table']	= $list_table ? true : ['compare'=>'!=', 'strict'=>true, 'value'=>'only'];
		}

		if($meta_type == 'post'){
			$post_type	= wpjam_pull($args, 'post_type');

			if($post_type){
				$object	= wpjam_get_post_type_object($post_type);

				if($object){
					$object->register_option($list_table);
				}

				$args['post_type']	= ['value'=>$post_type, 'if_null'=>true, 'callable'=>true];
			}
		}elseif($meta_type == 'term'){
			$taxonomy	= wpjam_pull($args, 'taxonomy');
			$action		= wpjam_pull($args, 'action');

			if($taxonomy){
				$object	= wpjam_get_taxonomy_object($taxonomy);

				if($object){
					$object->register_option($list_table);
				}

				$args['taxonomy']	= ['value'=>$taxonomy, 'if_null'=>true, 'callable'=>true];
			}

			if($action){
				$args['action']		= ['value'=>$action, 'if_null'=>true, 'callable'=>true];
			}
		}

		return static::get_registereds($args);
	}
}

class WPJAM_AJAX extends WPJAM_Register{
	public function registered($count){
		wpjam_map($this->nopriv ? ['', 'nopriv_'] : [''], fn($part)=> add_action('wp_ajax_'.$part.$this->name, [$this, 'callback']));

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

	public function callback(){
		if(!$this->callback || !is_callable($this->callback)){
			wpjam_send_error_json('invalid_callback');
		}

		$data	= wpjam_get_data_parameter();
		$data	= array_merge($data, wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
		$result	= wpjam_catch([wpjam_fields($this->fields), 'validate'], $data, 'parameter');

		if(is_wp_error($result)){
			wpjam_send_json($result);
		}

		$data	= array_merge($data, $result);

		if($this->verify !== false && !check_ajax_referer($this->get_nonce_action($data), false, false)){
			wpjam_send_error_json('invalid_nonce');
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
		$keys	= $this->nonce_keys ?: [];
		$data	= array_filter(wp_array_slice_assoc($data, $keys));

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
		$txt	= $txts ? wpjam_find($txts, fn($v)=> $v['name'] == str_replace('.txt', '', $action).'.txt') : '';

		if($txt){
			header('Content-Type: text/plain');
			echo $txt['value'];

			exit;
		}
	}
}