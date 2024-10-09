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

	public static function item_list_callback($id, $data, $action){
		$i 		= wpjam_get_data_parameter('i');
		$args	= $action == 'del_item' ? [$i] : [$i, $data];
		$args[]	= wpjam_get_data_parameter('_field');

		return wpjam_try([get_called_class(), $action], $id, ...$args);
	}

	public static function item_data_callback($id){
		return wpjam_try([get_called_class(), 'get_item'], $id, ...array_values(wpjam_get_data_parameter(['i', '_field'])));
	}

	public static function get_item_actions(){
		$args	= [
			'callback'		=> [static::class, 'item_list_callback'],
			'data_callback'	=> [static::class, 'item_data_callback'],
			'value_callback'=> fn()=> '',
			'row_action'	=> false,
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

	public function try_method($method, ...$args){
		return wpjam_throw_if_error($this->call_method($method, ...$args));
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
			] as [$key, $method, $default]){
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
		add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_Error', 'wp_die_handler']);

		if(!$this->callback || !is_callable($this->callback)){
			wp_die('invalid_callback');
		}

		$data	= wpjam_get_data_parameter();
		$data	= array_merge($data, wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
		$result	= wpjam_catch([wpjam_fields($this->fields), 'validate'], $data, 'parameter');

		if(is_wp_error($result)){
			wpjam_send_json($result);
		}

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

class WPJAM_Data_Processor extends WPJAM_Args{
	public function get_fields($type=''){
		if($type){
			return $this->$type ? array_intersect_key($this->fields, $this->$type) : [];
		}

		return $this->fields;
	}

	public function validate(){
		$error	= wpjam_find($this->formula, fn($v)=> is_wp_error($v));

		return $error ?: true;
	}

	public function process($item, $sum=false){
		return $this->format($this->calc($item, $sum));
	}

	public function calc($item, $sum=false){
		$formulas	= $this->formula ?: [];
		$formulas	= $sum ? array_intersect_key($formulas, array_filter($this->sumable, fn($v)=> $v == 2)) : $formulas;

		if(!$item || !is_array($item) || !$formulas){
			return $item;
		}
 
		$item	= wpjam_except($item, array_keys($formulas));
		$calc	= function(&$item, $key) use(&$calc){
			$formula	= $this->formula[$key];

			foreach($formula as &$t){
				if(str_starts_with($t, '$')){
					$k	= wpjam_remove_prefix($t, '$');

					if(!isset($item[$k]) && isset($this->formula[$k])){
						$item[$k]	= $calc($item, $k);
					}

					if(isset($item[$k]) && is_numeric(trim($item[$k]))){
						$t	= $item[$k];
					}else{
						if(!isset($this->if_errors[$k])){
							return $this->if_errors[$key] ?? '!无法计算';
						}

						$t	= $this->if_errors[$k];
					}

					if(!$t && isset($p) && $p == '/'){
						return $this->if_errors[$key] ?? '!除零错误';
					}
				}

				$p	= $t;
			}

			return eval('return '.implode('', $formula).';');
		};

		foreach($formulas as $key => $formula){
			if(!is_array($formula)){
				$item[$key]	= is_wp_error($formula) ? '!公式错误' : $formula;
			}elseif(!isset($item[$key])){
				$item[$key]	= $calc($item, $key);
			}
		}

		return $item;
	}

	public function format($item){
		foreach($this->format ?: [] as $key => $format){
			if(isset($item[$key])){
				$item[$key]	= wpjam_format($item[$key], $format);
			}
		}

		return $item;
	}

	public function sum($items, $calc=false){
		if($this->sumable && $items){
			$items	= $calc ? array_map([$this, 'calc'], $items) : $items;
			$sum	= wpjam_sum($items, array_keys(array_filter($this->sumable, fn($v)=> $v == 1)));

			return $this->process($sum, true);
		}
	}

	protected static function parse_formula($formula, $vars=[], $title=''){
		$raw		= $formula;
		$formula	= preg_replace('@\s@', '', $formula);
		$signs		= ['+', '-', '*', '/', '(', ')', ',', '\'', '.', '%'];
		$pattern	= '/([\\'.implode('\\', $signs).'])/';
		$formula	= preg_split($pattern, $formula, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		$methods	= ['abs', 'ceil', 'pow', 'sqrt', 'pi', 'max', 'min', 'fmod', 'round'];

		foreach($formula as $token){
			if(!is_numeric($token) && !str_starts_with($token, '$') && !in_array($token, $signs) && !in_array(strtolower($token), $methods)){
				return new WP_Error('invalid_formula', $title.'公式「'.$raw.'」错误，无效的「'.$token.'」');
			}

			if(str_starts_with($token, '$') && !in_array(wpjam_remove_prefix($token, '$'), $vars)){
				return new WP_Error('invalid_formula', $title.'公式「'.$raw.'」错误，「'.$token.'」未定义');
			}
		}

		return $formula;
	}

	public static function create($fields, $by='fields'){
		$args	= ['fields'=>$fields];
		$keys	= array_keys($fields);

		foreach($fields as $key => $field){
			if(!empty($field['sumable'])){
				$args['sumable'][$key]	= $field['sumable'];
			}

			if(!empty($field['format'])){
				$args['format'][$key]	= $field['format'];
			}

			if(!empty($field['formula'])){
				$args['formula'][$key]	= self::parse_formula($field['formula'], $keys, '字段'.wpjam_get($field, 'title').'「'.$key.'」');
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
			$item	= wpjam_find($response['content'], fn($item)=> $item['i'.$fields[$label]] == $file);
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
		$method	= wpjam_remove_prefix($method, 'cache_');
		$gnd	= str_contains($method, 'get') || str_contains($method, 'delete');
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
			$cb[]	= $this->time(array_shift($args));
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

	protected function time($time){
		return (int)($time) ?: ($this->time ?: DAY_IN_SECONDS);
	}

	public function cas($token, $key, $value, $time=0){
		return wp_cache_cas($token, $this->key($key), $value, $this->group, $this->time($time));
	}

	public function get_with_cas($key, &$token, $default=null){
		[$object, $token]	= is_object($token) ? [$token, null] : [null, $token];

		$key	= $this->key($key);
		$result	= wp_cache_get_with_cas($key, $this->group, $token);

		if($result === false && isset($default)){
			$this->set($key, $default);

			$result	= wp_cache_get_with_cas($key, $this->group, $token);
		}

		if($object){
			$object->cas_token	= $token;
		}

		return $result;
	}

	public function generate($key){
		try{
			$this->is_exceeded($key);

			if($this->interval && $this->get($key.':time') !== false){
				wpjam_throw('error', '验证码'.((int)($this->interval/60)).'分钟前已发送了。');
			}

			$code = rand(100000, 999999);

			$this->set($key.':code', $code, $this->cache_time);

			if($this->interval){
				$this->set($key.':time', time(), MINUTE_IN_SECONDS);
			}

			return $code;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public function verify($key, $code){
		try{
			$this->is_exceeded($key);

			$cached	= $code ? $this->get($key.':code') : false;

			if($cached === false){
				wpjam_throw('invalid_code');
			}elseif($cached != $code){
				if($this->failed_times){
					$this->set($key.':failed_times', ($this->get($key.':failed_times') ?: 0)+1, $this->cache_time/2);
				}

				wpjam_throw('invalid_code');
			}
		
			return true;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	protected function is_exceeded($key){
		if($this->failed_times && (int)$this->get($key.':failed_times') > $this->failed_times){
			wpjam_throw('failed_times_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
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
			'cache_time'	=> MINUTE_IN_SECONDS*30,
			'interval'		=> MINUTE_IN_SECONDS
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
		if(!empty($args['group'])){
			if(wpjam_pull($args, 'global')){
				wp_cache_add_global_groups($args['group']);
			}

			return new self($args);
		}
	}
}