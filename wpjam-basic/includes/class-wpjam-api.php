<?php
trait WPJAM_Call_Trait{
	public function call($name, ...$args){
		if(is_closure($name)){
			$cb	= $name;
		}else{
			$cb = [$this, $name];

			if($by = array_find(['model', 'prop'], fn($k)=> str_ends_with($name, '_by_'.$k))){
				$name	= explode_last('_by_', $name)[0];
				$cb 	= $by == 'prop' ? $this->$name : [$this->model, $name];
			}
		}

		return wpjam_call(wpjam_bind($cb, $this), ...$args);
	}

	protected function call_dynamic_method($name, ...$args){
		return $this->call(wpjam_dynamic_method(static::class, $name), ...$args);
	}

	public function try($name, ...$args){
		return wpjam_try([$this, 'call'], $name, ...$args);
	}

	public function catch($name, ...$args){
		return wpjam_catch([$this, 'call'], $name, ...$args);
	}

	public static function add_dynamic_method($name, $closure){
		wpjam_dynamic_method(static::class, $name, $closure);
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

	public function process_items($cb, $field=''){
		try{
			return $this->update_items($this->call($cb, $this->get_items($field)), $field);
		}catch(Exception $e){
			return wpjam_catch($e);
		}
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
		[$item, $key]	= (!$args || is_bool($key) || (!is_scalar($key) && !is_null($key))) ? [$key, null] : [array_shift($args), $key];

		return $this->process_items(fn($items)=> wpjam_add_at($items, count($items), $key, $this->prepare_item($item, $key, 'add', ...$args)), ...$args);
	}

	public function add_items($items){
		return wpjam_map($items, [$this, 'add_item'], wp_is_numeric_array($items) ? 'v' : 'kv');
	}

	public function remove_item($item, $field=''){
		return $this->process_items(fn($items)=> array_diff($items, [$item]), $field);
	}

	public function edit_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field);
	}

	public function update_item($key, $item, $field='', $action='update'){
		return $this->process_items(fn($items)=> array_replace($items, [$key=> $this->prepare_item($item, $key, $action, $field)]), $field);
	}

	public function set_item($key, $item, $field=''){
		return $this->update_item($key, $item, $field, 'set');
	}

	public function delete_item($key, $field=''){
		$res	= $this->process_items(fn($items)=> wpjam_except($items, $this->prepare_item(null, $key, 'delete', $field) ?? $key), $field);

		is_wp_error($res) || wpjam_call([$this, 'after_delete_item'], $key, $field);

		return $res;
	}

	public function del_item($key, $field=''){
		return $this->delete_item($key, $field);
	}

	public function move_item($orders, $field=''){
		if(wpjam_is_assoc_array($orders)){
			[$orders, $field]	= array_values(wpjam_pull($orders, ['item', '_field']));
		}

		return $this->process_items(fn($items)=> array_merge(wpjam_pull($items, $orders), $items), $field);
	}

	protected function prepare_item($item, $key, $action, $field=''){
		$field	= $field ?: $this->get_items_field();
		$items	= $this->get_items($field);
		$add	= $action == 'add';

		if(isset($item)){
			method_exists($this, 'validate_item') && wpjam_if_error($this->validate_item($item, $key, $action, $field), 'throw');

			$add	&& ($max = wpjam_get_annotation(static::class, 'max_items')) && count($items) >= $max && wpjam_throw('quota_exceeded', '最多'.$max.'个');
			$item	= method_exists($this, 'sanitize_item') ? $this->sanitize_item($item, $key, $action, $field) : $item;
		}

		if(isset($key)){
			$label	= ['add'=>'添加', 'update'=>'编辑', 'delete'=>'删除'][$action] ?? '';
			$label	&& (wpjam_exists($items, $key) === $add) && wpjam_throw('invalid_item_key', '「'.$key.'」'.($add ? '已' : '不').'存在，无法'.$label);
		}else{
			$add || wpjam_throw('invalid_item_key', 'key不能为空');
		}

		return $item;
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

class WPJAM_API{
	private $data	= [];

	private function __construct(){
		add_action('plugins_loaded', [$this, 'loaded'], 0);

		wpjam_hooks(['gettext', 'gettext_with_context'], fn($get, ...$args)=> $get === $args[0] ? wpjam_translate(...$args) : $get, 10, 4);

		get_locale() == 'zh_CN' && load_textdomain('wpjam', dirname(__DIR__).'/template/wpjam-zh_CN.l10n.php');

		wpjam_is_json_request() && wpjam_hooks('remove', [
			['the_title',		'convert_chars'],
			['init',			['wp_widgets_init', 'maybe_add_existing_user_to_blog']],
			['plugins_loaded',	['wp_maybe_load_widgets', 'wp_maybe_load_embeds', '_wp_customize_include', '_wp_theme_json_webfonts_handler']],
			['wp_loaded',		['_custom_header_background_just_in_time', '_add_template_loader_filters']]
		]);
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

		wpjam_hook('tap', 'pre_do_shortcode_tag',	fn($pre, $tag)=> $this->push('shortcode', $tag), 1, 2);
		wpjam_hook('tap', 'do_shortcode_tag',		fn($res, $tag)=> $this->pop('shortcode'), 999, 2);

		add_action('loop_start',	fn($query)=> $this->push('query', $query), 1);
		add_action('loop_end',		fn()=> $this->pop('query'), 999);

		add_filter('register_post_type_args',	['WPJAM_Post_Type', 'filter_register_args'], 999, 3);
		add_filter('register_taxonomy_args',	['WPJAM_Taxonomy', 'filter_register_args'], 999, 4);

		add_filter('root_rewrite_rules', fn($rewrite)=> $GLOBALS['wp_rewrite']->root ? $rewrite : array_merge(['([^/]+\.txt)?$'=>'index.php?module=txt&action=$matches[1]'], $rewrite));
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

	public function process_arg($key, $cb){
		if(!is_closure($cb)){
			return $this;
		}

		$value	= $this->call($cb, $this->get_arg($key));

		return is_null($value) ? $this->delete_arg($key) : $this->update_arg($key, $value);
	}

	public function get_arg($key, $default=null, $action=false){
		$value	= wpjam_get($this->get_args(), $key);

		if($action){
			$value	= is_closure($value) ? wpjam_bind($value, $this) : ($value ?? (is_string($key) ? wpjam_callback([$this->model, 'get_'.$key]) : null));
			$value	= $action === 'callback' ? maybe_callback($value, $this->name) : $value;
		}

		return $value ?? $default;
	}

	public function update_arg($key, $value=null){
		$this->args	= wpjam_set($this->get_args(), $key, $value);

		return $this;
	}

	public function delete_arg($key, ...$args){
		if($args && is_string($key) && str_ends_with($key, '[]')){
			return $this->process_arg(substr($key, 0, -2), fn($value)=> is_array($value) ? array_diff($value, $args) : $value);
		}

		$this->args	= wpjam_except($this->get_args(), $key);

		return $this;
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

			return is_closure($cb) ? $this->call($cb, ...$args) : null;
		}finally{
			$this->args	= $archive;
		}
	}

	protected function parse_method($name){
		$cb = array_find([[$this->model, $name], $this->$name], fn($v)=> wpjam_callback($v));

		return is_closure($cb) ? wpjam_bind($cb, $this) : $cb;
	}

	public function call_method($name, ...$args){
		return ($cb = $this->parse_method($name)) ? wpjam_try($cb, ...$args) : (str_starts_with($name, 'filter_') ? array_shift($args) : null);
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
		$model	= empty($config['model']) ? null : ($args['model'] ?? '');

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
				($args[$k] ?? ($k == 'hooks' || ($config[$k] ?? false))) === true && method_exists($model, $m) && ($args[$k] = [$model, $m]);
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

	public static function get_group($args){
		return WPJAM_Register_Group::instance($args);
	}

	public static function call_group($method, ...$args){
		if(static::class != self::class){
			$group	= static::get_group(['called'=>static::class, 'name'=>strtolower(static::class)]);

			$group->defaults	??= method_exists(static::class, 'get_defaults') ? static::get_defaults() : [];

			return wpjam_catch([$group, $method], ...$args);
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
		return self::get_registereds(...((!$args || is_array($args[0])) ? $args : [[$args[0]=> $args[1]]]));
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
		$this->defaults && wpjam_map($this->defaults, [$this, 'by_default'], 'k');

		$objects	= wpjam_filter($this->get_arg('objects[]'), $args, $operator);
		$orderby	= $this->get_config('orderby');

		return $orderby ? wpjam_sort($objects, ($orderby === true ? 'order' : $orderby), ($this->get_config('order') ?? 'DESC'), 10) : $objects;
	}

	public function get_object($name, $by='', $top=''){
		if($name && !$by){
			return $this->get_arg('objects['.$name.']') ?: $this->by_default($name);
		}

		if($name && $by == 'model' && strcasecmp($name, $top) !== 0){
			return array_find($this->get_objects(), fn($v)=> is_string($v->model) && strcasecmp($name, $v->model) === 0) ?: $this->get_object(get_parent_class($name), $by, $top);
		}
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

		if(empty($name)){
			$e	= '为空';
		}elseif(is_numeric($name)){
			$e	= '「'.$name.'」'.'为纯数字';
		}elseif(!is_string($name)){
			$e	= '「'.var_export($name, true).'」不为字符串';
		}

		if(!empty($e)){
			return trigger_error(self::class.'的注册 name'.$e) && false;
		}

		$this->get_arg('objects['.$name.']') && trigger_error($this->name.'「'.$name.'」已经注册。');

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

			$count == 0 && wpjam_hooks(wpjam_call($called.'::add_hooks'));
		}

		return $object;
	}

	public function remove_object($name){
		return $this->delete_arg('objects['.$name.']');
	}

	public function by_default($name){
		$args = $this->pull('defaults['.$name.']');

		return is_null($args) ? null : $this->add_object($name, $args);
	}

	public function get_config($key=''){
		$this->config	??= $this->called ? wpjam_get_annotation($this->called, 'config')+['model'=>true] : [];

		return $this->get_arg('config['.($key ?: '').']');
	}

	public function get_active($key=''){
		return wpjam_array($this->get_objects(), fn($k, $v)=> ($v->active ?? $v->is_active()) ? [$k, $key ? $v->get_arg($key) : $v] : null, true);
	}

	public function call_active($method, ...$args){
		$type	= array_find(['filter', 'get'], fn($t)=> str_starts_with($method, $t.'_'));

		foreach($this->get_active() as $object){
			$result	= $object->call_method($method, ...$args);

			if($type == 'filter'){
				$args[0]	= $result;
			}elseif($type == 'get'){
				$return		= array_merge($return ?? [], is_array($result) ? $result : []);
			}
		}

		if($type == 'filter'){
			return $args[0];
		}elseif($type == 'get'){
			return $return ?? [];
		}
	}

	public function get_fields($args=[]){
		$objects	= array_filter($this->get_objects(wpjam_pull($args, 'filter_args')), fn($v)=> !isset($v->active));
		$options	= wpjam_options($objects, $args);

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

		return $args ? (!empty($args['name']) ? ($groups[$args['name']] ??= new self($args)) : null) : $groups;
	}
}

/**
* @config menu_page, admin_load, register_json, init, orderby
**/
#[config('menu_page', 'admin_load', 'register_json', 'init', 'orderby')]
class WPJAM_Option_Setting extends WPJAM_Register{
	public function __invoke(){
		flush_rewrite_rules();

		$submit	= wpjam_get_post_parameter('submit_name');
		$values	= $this->validate_by_fields(wpjam_get_data_parameter()) ?: [];
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
		if(try_remove_suffix($method, '_fields')){
			if($method == 'render'){
				$fields	= array_shift($args);
			}elseif($method == 'get' || try_remove_suffix($method, '_by')){
				$fields	= array_merge(...array_column($this->get_sections($method !== 'validate'), 'fields'));

				if($method == 'get'){
					return $fields;
				}
			}

			return isset($fields) ? wpjam_fields($fields, ['value_callback'=>[$this, 'value_callback']])->$method(...$args) : null;
		}elseif(($option = try_remove_suffix($method, '_option')) || try_remove_suffix($method, '_setting')){
			if(try_remove_suffix($method, '_site')){
				$type		= '_site';
			}else{
				$blog_id	= try_remove_suffix($method, '_blog') ? array_shift($args) : $this->blog_id;
				$type		= $blog_id && is_multisite() ? '_blog' : '';
			}

			$cb_args	= $type == '_blog' ? [$blog_id] : [];

			if($option){
				$result	= ($method.$type.'_option')(...[...$cb_args, $this->name, ...($method == 'update' ? [wpjam_if_error($args[0], [])] : [])]);

				return $method == 'get' ? (wpjam_if_error($result, []) ?: []) : $result;
			}

			$data	= [$this, 'get'.$type.'_option'](...$cb_args) ?: [];
			$name	= $args[0] ?? null;

			if($method == 'get'){
				$site_default	= $type != '_site' && is_multisite() && $this->site_default;

				if(!$name){
					return array_merge($site_default ? $this->get_site_option() : [], $data);
				}

				if(is_array($name)){
					return wpjam_fill(array_filter($name), fn($n)=> [$this, 'get'.$type.'_setting'](...[...$cb_args, $n]));
				}

				$value	= is_array($data) ? wpjam_if_error(wpjam_get($data, $name), null) : null;

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

			return [$this, 'update'.$type.'_option'](...[...$cb_args, $method == 'update' ? wpjam_reduce(is_array($name) ? $name : [$name=>$args[1]], fn($c, $v, $n)=> wpjam_set($c, $n, $v), $data) : wpjam_except($data, $name)]);
		}
	}

	protected function filter_args(){
		return $this->args;
	}

	public function get_arg($key, $default=null, $do_callback=true){
		$value	= parent::get_arg($key, $default, $do_callback);

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
				$id		= $this->type == 'section' ? $this->section_id : ($this->current_tab ?: $this->sub_name ?: $this->name);
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
		$sections	= $this->get_sections();
		$multi		= count($sections) > 1;
		$nav		= $multi && !$page->tab_slug ? wpjam_tag('ul') : '';
		$form		= wpjam_tag('form', ['method'=>'POST', 'id'=>'wpjam_option', 'novalidate']);

		foreach($sections as $id => $section){
			$tab	= wpjam_tag(...($nav ? ['div', ['id'=>'tab_'.$id]] : []));
			$multi	&& $tab->append($page->tab_slug ? 'h3' : 'h2', [], $section['title']);
			$nav	&& $nav->append('li', ['data'=>wpjam_pick($section, ['show_if'])], ['a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id], $section['title']]);

			$form->append($tab->append([
				wpjam_ob($section['callback'] ?? '', $section),
				wpautop($section['summary'] ?? ''),
				$this->render_fields($section['fields'])
			]));
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append(wpjam_tag('p', ['submit'])->append([
			get_submit_button('', 'primary', 'save', false),
			$this->reset ? get_submit_button('重置选项', 'secondary', 'reset', false) : ''
		]));

		return $nav ? $form->before($nav->wrap('h2', ['nav-tab-wrapper', 'wp-clearfix']))->wrap('div', ['tabs']) : $form;
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
		if($name == 'option'){
			$name	= array_shift($args);	// 兼容
		}

		if($args && in_array($args[0], ['model', ''], true)){
			return self::get($name, ...$args);
		}

		$blog_id	= is_multisite() ? ($args[0] ?? 0) : 0;
		$object		= $blog_id ? (!is_numeric($blog_id) && trigger_error($name.':'.$blog_id) && false) : self::get($name);

		return $object ?: wpjam_var('option:'.wpjam_join('-', $name, $blog_id), fn()=> new static($name, ['blog_id'=>$blog_id]));
	}
}

class WPJAM_Option_Model{
	protected static function get_object(){
		return wpjam_option(static::class, 'model', self::class);
	}

	public static function __callStatic($method, $args){
		return ($object	= self::get_object()) ? [$object, $method](...$args) : null;
	}
}

class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
			$args	= [$this->name, ...$args];
			$cb		= str_replace('data', 'metadata', $method);
		}elseif(try_remove_suffix($method, '_by_mid')){
			$args	= [$this->name, ...$args];
			$cb		= $method.'_metadata_by_mid';
		}elseif(try_remove_suffix($method, '_meta')){
			$cb		= [$this, $method.'_data'];
		}elseif(str_contains($method, '_meta')){
			$cb		= [$this, str_replace('_meta', '', $method)];
		}

		return $cb(...$args);
	}

	public function get_options($args=[]){
		if($this->name == 'post'){
			if(isset($args['post_type'])){
				$object = wpjam_get_post_type_object($args['post_type']);
				$object	&& $object->register_option();

				$keys[]	= 'post_type';
			}
		}elseif($this->name == 'term'){
			if(isset($args['taxonomy'])){
				$object = wpjam_get_taxonomy_object($args['taxonomy']);
				$object	&& $object->register_option();

				$keys[]	= 'taxonomy';
			}

			if(isset($args['action'])){
				$keys[]	= 'action';
			}
		}

		foreach($keys ?? [] as $k){
			$args[$k]	= ['value'=>$args[$k], 'if_null'=>true, 'callable'=>true];
		}

		if(isset($args['list_table'])){
			$args['title']		= true;
			$args['list_table']	= $args['list_table'] ? true : ['compare'=>'!==', 'value'=>'only'];
		}

		return wpjam_sort(wpjam_filter($this->get_arg('options[]'), $args), 'order', 'DESC', 10);
	}

	public function call_option($action, ...$args){
		$name	= $args[0];
		$key	= 'options['.$name.']';

		if($action == 'register'){
			$args	= $args[1];

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

			return $this->update_arg($key, new WPJAM_Meta_Option(['name'=>$name, 'meta_type'=>$this->name]+$args));
		}elseif($action == 'unregister'){
			return $this->delete_arg($key);
		}else{
			return $this->get_arg($key);
		}
	}

	protected function preprocess_args($args){
		$wpdb	= $GLOBALS['wpdb'];
		$global	= $args['global'] ?? false;
		$table	= $args['table_name'] ?? $this->name.'meta';

		$wpdb->$table ??= $args['table'] ?? ($global ? $wpdb->base_prefix : $wpdb->prefix).$this->name.'meta';

		$global && wp_cache_add_global_groups($this->name.'_meta');

		return parent::preprocess_args($args);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object_id'){
		if($name == 'object_id'){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}

		return $name;
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

		$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$table." t ON t.".$key." = m.".$this->get_column('object_id')." WHERE t.".$key." IS NULL") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
	}

	public function create_table(){
		if(($table	= $this->get_table()) != $GLOBALS['wpdb']->get_var("show tables like '{$table}'")){
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

			if(is_wp_error($data) || !$data){
				return $data ?: true;
			}

			if($callback = $this->callback ?: $this->update_callback){
				$result	= is_callable($callback) ? call_user_func($callback, $id, $data, $fields) : false;

				return $result === false ? new WP_Error('invalid_callback') : $result;
			}

			return wpjam_update_metadata($this->meta_type, $id, $data, $object->get_defaults());
		}elseif($method == 'render'){
			echo wpautop($this->summary ?: '').$object->render(...$args);
		}else{
			return $object->$method(...$args);
		}
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
			$data		= $this->fields ? ($fields ? wpjam_fields($fields)->get_parameter($method) : []) : $this->args;
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

	public static function die_handler($msg, $title='', $args=[]){
		wpjam_if_error($msg, 'send');

		$code	= $args['code'] ?? '';
		$data	= $code && $title ? ['modal'=>['title'=>$title, 'content'=>$msg]] : [];
		$code	= $code ?: $title;
		$item	= !$code && is_string($msg) ? wpjam_error($msg) : [];
		$item	= $item ?: ['errcode'=>($code ?: 'error'), 'errmsg'=>$msg]+$data;

		wpjam_send_json($item);
	}

	public static function redirect($name){
		header('X-Content-Type-Options: nosniff');

		rest_send_cors_headers(false);

		if('OPTIONS' === $_SERVER['REQUEST_METHOD']){
			status_header(403);
			exit;
		}

		ini_set('display_errors', 0);

		add_filter('wp_die_'.(array_find(['jsonp_', 'json_'], fn($v)=> call_user_func('wp_is_'.$v.'request')) ?: '').'handler', fn()=> [self::class, 'die_handler']);

		if(!try_remove_prefix($name, 'mag.')){
			return;
		}

		$name	= substr($name, str_starts_with($name, '.mag') ? 4 : 0);	// 兼容
		$name	= str_replace('/', '.', $name);
		$name	= wpjam_var('json', $name);
		$user	= wpjam_get_current_user();

		$user && !empty($user['user_id']) && wp_set_current_user($user['user_id']);

		do_action('wpjam_api', $name);

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
	** 1. 分成主的查询和子查询（$query_args['sub']=1）
	** 2. 主查询支持 $_GET 参数 和 $_GET 参数 mapping
	** 3. 子查询（sub）只支持 $query_args 参数
	** 4. 主查询返回 next_cursor 和 total_pages，current_page，子查询（sub）没有
	** 5. $_GET 参数只适用于 post.list
	** 6. term.list 只能用 $_GET 参数 mapping 来传递参数
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
						'year'		=> (int)wpjam_get_parameter('year') ?: wpjam_date('Y'),
						'monthnum'	=> (int)wpjam_get_parameter('month') ?: wpjam_date('m'),
						'day'		=> (int)wpjam_get_parameter('day')
					];

					$args	+= ['format'=>'date'];
				}else{
					$number	= wpjam_find(['number', 'posts_per_page'], fn($v)=> $v, fn($k)=> (int)wpjam_get_parameter($k));
					$vars	+= ($number && $number != -1) ? ['posts_per_page'=> min($number, 100)] : [];
					$vars	+= array_filter(['offset'=>wpjam_get_parameter('offset')]);

					if($post__in = wpjam_get_parameter('post__in')){
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
				$id		= ($args['id'] ?? 0) ?: (int)wpjam_get_parameter('id', ['required'=>true]);
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
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media	= ($args['media'] ?? '') ?: 'media';
		$output	= ($args['output'] ?? '') ?: 'url';

		isset($_FILES[$media]) || wpjam_throw('invalid_parameter', [$media]);

		if($format == 'media'){
			$id		= wpjam_try('media_handle_upload', $media, (int)wpjam_get_post_parameter('post_id',	['default'=>0]));
			$url	= wp_get_attachment_url($id);
			$query	= wpjam_get_image_size($id);
		}else{
			$upload	= wpjam_upload($media);
			$url	= $upload['url'];
			$query	= wpjam_get_image_size($upload['file'], 'file');
		}

		return [$output => $query ? add_query_arg($query, $url) : $url];
	}

	public static function taxonomy($args){
		$object		= wpjam_get_taxonomy_object(wpjam_get($args, 'taxonomy')) ?: wpjam_throw('invalid_taxonomy');
		$mapping	= wpjam_array(wp_parse_args(wpjam_pull($args, 'mapping') ?: []), fn($k, $v)=> [$k, wpjam_get_parameter($v)], true);
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
			$object	= wpjam_option($option);
			$names	= $object && $object->option_type != 'array' ? [$option, $name] : [$name];

			return [$output => wpjam_get($object->option_type ? $object->prepare_by_fields() : $object->get_option(), array_filter($names) ?: null)];
		}
	}

	public static function data_type($args){
		$name	= wpjam_pull($args, 'data_type');
		$args	= wp_parse_args(($args['query_args'] ?? $args) ?: []);
		$object	= wpjam_get_data_type($name, $args) ?: wpjam_throw('invalid_data_type');

		return ['items'=>$object->query_items($args+['search'=>wpjam_get_parameter('s')])];
	}

	public static function config($args){
		return wpjam_get_config($args['group'] ?? '');
	}

	public static function callback($name, $args=[]){
		$output	= $args['output'] ?? null;

		[$type, $action]	= explode('.', $name);

		if($type == 'post'){
			$type	= wpjam_get_parameter('post_type');
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

class WPJAM_AJAX extends WPJAM_Args{
	public function __invoke(){
		add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_JSON', 'die_handler']);

		$cb	= $this->callback;

		(!$cb || !is_callable($cb)) && wp_die('invalid_callback');

		if($this->admin){
			$data	= $this->fields ? wpjam_fields($this->fields)->get_parameter('POST') : wpjam_get_post_parameter();
			$verify	= wpjam_get($data, 'action_type') !== 'form';
		}else{
			$data	= array_merge(wpjam_get_data_parameter(), wpjam_except(wpjam_get_post_parameter(), ['action', 'defaults', 'data', '_ajax_nonce']));
			$data	= array_merge($data, $this->fields ? wpjam_fields($this->fields)->validate($data, 'parameter') : []);
			$verify	= $this->verify !== false;
		}

		$action	= $verify ? $this->get_attr($this->name, $data, 'nonce_action') : '';
		$action && !check_ajax_referer($action, false, false) && wpjam_send_json(['errcode'=>'invalid_nonce', 'errmsg'=>'验证失败，请刷新重试。']);

		$this->allow && !wpjam_call($this->allow, $data) && wp_die('access_denied');

		return $cb($data, $this->name);
	}

	public static function get_attr($name, $data=[], $output=''){
		if($ajax = wpjam('ajax', $name)){
			$cb		= $ajax['nonce_action'] ?? '';
			$action	= $cb ? $cb($data) : (empty($ajax['admin']) ? $name.wpjam_join(':', wpjam_pick($data, $ajax['nonce_keys'] ?? [])) : '');

			return $output == 'nonce_action' ? $action : ['action'=>$name, 'data'=>$data]+($action ? ['nonce'=>wp_create_nonce($action)] : []);
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
			add_action('wp_ajax_'.(is_user_logged_in() ? '' : 'nopriv_').$name, fn()=> wpjam_send_json(wpjam_catch(new static(['name'=>$name]+$args))));
		}

		return wpjam('ajax', $name, $args);
	}
}

class WPJAM_Notice{
	public static function add($item, $type='admin', $id=''){
		if(!$id || ($type == 'admin' ? (!is_multisite() || get_site($id)) : get_userdata($id))){
			$item	= is_array($item) ? $item : ['notice'=>$item];
			$item	+= ['type'=>'error', 'notice'=>'', 'time'=>time(), 'key'=>md5(serialize($item))];

			return (self::get_instance($type, $id))->insert($item);
		}
	}

	public static function render($type){
		foreach((self::get_instance($type))->get_items() as $key => $item){
			$data	= ['notice_key'=>$key, 'notice_type'=>$type];
			$item	+= ['class'=>'is-dismissible', 'title'=>'', 'modal'=>0];
			$notice	= trim($item['notice']);
			$notice	.= !empty($item['admin_url']) ? (($item['modal'] ? "\n\n" : ' ').'<a style="text-decoration:none;" href="'.add_query_arg($data, home_url($item['admin_url'])).'">点击查看<span class="dashicons dashicons-arrow-right-alt"></span></a>') : '';

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

	public static function callback(){
		if($key	= wpjam_get_data_parameter('notice_key')){
			return ($type = wpjam_get_data_parameter('notice_type')) == 'admin' && !current_user_can('manage_options') ? wp_die('bad_authentication') : (self::get_instance($type))->delete($key);
		}
	}

	public static function init(){
		add_action('all_admin_notices', function(){
			self::callback();
			self::render('user');

			current_user_can('manage_options') && self::render('admin');
		}, 9);

		wpjam_register_page_action('delete_notice', [
			'button_text'	=> '删除',
			'tag'			=> 'span',
			'class'			=> 'hidden delete-notice',
			'validate'		=> true,
			'direct'		=> true,
			'callback'		=> [self::class, 'callback'],
		]);
	}

	public static function get_instance($type='admin', $id=0){
		$filter	= fn($items)=> array_filter(($items ?: []), fn($v)=> $v['time']>(time()-MONTH_IN_SECONDS*3) && trim($v['notice']));
		$name	= 'wpjam_notices';

		if($type == 'user'){
			$id		= (int)$id ?: get_current_user_id();
			$args	= [
				'get_items'		=> fn()=> $filter(get_user_meta($id, $name, true)),
				'delete_items'	=> fn()=> delete_user_meta($id, $name),
				'update_items'	=> fn($items)=> update_user_meta($id, $name, $items),
			];
		}else{
			$id		= (int)$id ?: get_current_blog_id();
			$args	= [
				'get_items'		=> fn()=> $filter(wpjam_get_option($name, $id)),
				'update_items'	=> fn($items)=> wpjam_update_option($name, $items, $id),
			];
		}

		return wpjam_get_handler('notice:'.$type.':'.$id, $args+['primary_key'=>'key']);
	}
}

class WPJAM_Exception extends Exception{
	private $error;

	public function __construct($msg, $code=null, ?Throwable $previous=null){
		$error	= $this->error	= is_wp_error($msg) ? $msg : new WP_Error($code ?: 'error', $msg);
		$code	= $error->get_error_code();

		parent::__construct($error->get_error_message(), (is_numeric($code) ? (int)$code : 1), $previous);
	}

	public function __call($method, $args){
		if(in_array($method, ['get_wp_error', 'get_error'])){
			return $this->error;
		}

		return [$this->error, $method](...$args);
	}
}