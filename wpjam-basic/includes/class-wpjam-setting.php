<?php
class WPJAM_Setting extends WPJAM_Args{
	public function __call($method, $args){
		if(try_remove_suffix($method, '_option')){
			$cb			= $method.'_'.$this->type;
			$cb_args	= $this->type == 'blog_option' ? [$this->blog_id] : [];
			$cb_args[]	= $this->name;

			if($method == 'get'){
				$result	= $cb(...$cb_args);

				return $result === false ? [] : $this->sanitize_option($result);
			}else{
				$cb_args[]	= $args[0] ? $this->sanitize_option($args[0]) : $args[0];

				return $cb(...$cb_args);
			}
		}elseif(try_remove_suffix($method, '_setting') || in_array($method, ['get', 'update', 'delete'])) {
			$values	= $this->get_option();
			$name	= array_shift($args);

			if($method == 'get'){
				if(!$name){
					return $values;
				}

				if(is_array($name)){
					return wpjam_fill(array_filter($name), [$this, 'get']);
				}

				$value	= is_array($values) ? wpjam_if_error(wpjam_get($values, $name), null) : null;

				return is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value;
			}

			if($method == 'update'){
				if(is_array($name)){
					$values	= wpjam_reduce($name, fn($carry, $v, $n)=> wpjam_set($carry, $n, $v), $values);
				}else{
					$values	= wpjam_set($values, $name, array_shift($args));
				}
			}else{
				$values	= wpjam_except($values, $name);
			}

			return $this->update_option($values);
		}
	}

	public static function get_instance($type='', $name='', ...$args){
		if(!in_array($type, ['option', 'site_option', '', 'site'])){
			if(!$type || $args){
				return;
			}

			[$blog_id, $name, $type]	= [$name, $type, 'option'];
		}else{
			if(!$name){
				return;
			}

			$blog_id	= (int)array_shift($args);
			$type		= ['site'=>'site_option', ''=>'option'][$type] ?? $type;
		}

		$blog_id && !is_numeric($blog_id) && trigger_error($type.':'.$name.':'.$blog_id);

		$args	= ['name'=>$name];
		$args	+= (is_multisite() && $type == 'option') ? ['type'=>'blog_option', 'blog_id'=>(int)$blog_id ?: get_current_blog_id()] : compact('type');

		return wpjam_get_instance('setting', join(':', $args), fn()=> new static($args));
	}

	public static function sanitize_option($value){
		return wpjam_if_error($value, null) ? $value : [];
	}

	public static function parse_json_module($args){
		if($option	= wpjam_get($args, 'option_name')){
			$name	= (wpjam_get($args, 'setting_name') ?? wpjam_get($args, 'setting')) ?: null;
			$output	= wpjam_get($args, 'output') ?: ($name ?: $option);
			$object	= WPJAM_Option_Setting::get($option);

			return [$output	=> $object ? wpjam_get($object->prepare(), $name) : wpjam_get_setting($option, $name)];
		}
	}
}

/**
* @config menu_page, admin_load, register_json, init, orderby
**/
#[config('menu_page', 'admin_load', 'register_json', 'init', 'orderby')]
class WPJAM_Option_Setting extends WPJAM_Register{
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

			if(($value['function'] ??= 'option') == 'option'){
				$value['option_name']	= $this->name;
			}

			if(!empty($value['tab_slug'])){
				return ($value['plugin_page'] ??= $this->plugin_page) ? $value+['title'=>$this->title] : null;
			}

			return $value+['menu_slug'=>$this->plugin_page ?: $this->name, 'menu_title'=>$this->title];
		}elseif($key == 'admin_load'){
			if($value){
				$fn		= fn($v) => ($this->model && !isset($v['callback']) && !isset($v['model'])) ? $v+['model'=>$this->model] : $v;
				$value	= wp_is_numeric_array($value) ? array_map($fn, $value) : $fn($value);
			}
		}

		return $value;
	}

	public function get_current(){
		return $this->get_sub(wpjam_join(':', self::pick($GLOBALS))) ?: $this;
	}

	protected function parse_section($section, $id){
		return array_merge($section, ['fields'=> maybe_callback($section['fields'] ?? [], $id, $this->name)]);
	}

	protected function get_sections($all=false, $filter=true){
		$sections	= $this->get_arg('sections');

		if($sections && is_array($sections)){
			$sections	= array_filter($sections, 'is_array');
		}else{
			$fields		= $this->get_arg('fields', null, false);
			$sections	= is_null($fields) ? [] : [($this->sub_name ?: $this->name)=> ['title'=>$this->title ?: '', 'fields'=>$fields]];
		}

		$sections	= wpjam_map($sections, fn($v, $k)=> $this->parse_section($v, $k));
		$sections	= array_reduce($all ? $this->get_subs() : [], fn($carry, $v)=> array_merge($carry, $v->get_sections(false, false)), $sections);

		if($filter){
			$args		= ['type'=>'section', 'name'=>$this->name]+($all ? [] : wpjam_map(self::pick($GLOBALS), fn($v)=> ['value'=>$v, 'if_null'=>true]));
			$objects	= wpjam_sort(self::get_by($args), 'order', 'desc', 10);

			foreach(array_reverse(array_filter($objects, fn($v)=> $v->order > 10))+$objects as $object){
				foreach(($object->get_arg('sections') ?: []) as $id => $section){
					$section	= $this->parse_section($section, $id);

					if(isset($sections[$id])){
						$sections[$id]	= $object->order > 10 ? wpjam_merge($section, $sections[$id]) : $sections[$id];	// 字段靠前
						$sections[$id]	= wpjam_merge($sections[$id], $section);
					}else{
						$sections[$id]	= $section;
					}
				}
			}

			return apply_filters('wpjam_option_setting_sections', array_filter($sections, fn($v)=> isset($v['title'], $v['fields'])), $this->name);
		}

		return $sections;
	}

	public function add_section(...$args){
		if(wp_is_numeric_array($args[0])){
			return array_map([$this, 'add_section'], $args[0]);
		}

		$args	= is_array($args[0]) ? $args[0] : [$args[0]=> isset($args[1]['fields']) ? $args[1] : ['fields'=>$args[1]]];
		$args	= isset($args['model']) || isset($args['sections']) ? $args : ['sections'=>$args];
		$name	= md5(maybe_serialize(wpjam_map($args, fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, true)));

		return self::register($name, new static($this->name, $args+['type'=>'section']));
	}

	public function get_fields(...$args){
		if($args && is_array($args[0])){
			$fields	= $args[0];
			$output	= 'object';
		}else{
			$fields	= array_merge(...array_column($this->get_sections($args[0] ?? false), 'fields'));
			$output	= $args[1] ?? '';
		}

		return $output == 'object' ? WPJAM_Fields::create($fields, ['value_callback'=>[$this, 'value_callback']]) : $fields;
	}

	public function get_option($site=false){
		if($site && (!$this->site_default || !is_multisite())){
			return [];
		}

		return ('wpjam_get_'.($site ? 'site_' : '').'option')($this->name) ?: [];
	}

	public function get_setting($name, ...$args){
		$null	= $name ? null : [];

		foreach(['', ...(($this->site_default && is_multisite()) ? ['site_'] : [])] as $type){
			$value	= ('wpjam_get_'.$type.'setting')($this->name, $name);

			if($value !== $null){
				return $value;
			}
		}

		if($args && $args[0] !== $null){
			return $args[0];
		}

		if($this->field_default){
			return wpjam_get(($this->_defaults ??= $this->get_fields(true, 'object')->get_defaults()), $name ?: null);
		}

		return $null;
	}

	public function update_setting(...$args){
		return wpjam_update_setting($this->name, ...$args);
	}

	public function delete_setting($name){
		return wpjam_delete_setting($this->name, $name);
	}

	public function prepare(){
		return wpjam_get($this->get_fields(true, 'object')->prepare(), $this->option_type == 'array' ? null : $this->name);
	}

	public function validate($value){
		return $this->get_fields(false, 'object')->validate($value);
	}

	public function value_callback($name=''){
		if($this->option_type == 'array'){
			return is_network_admin() ? wpjam_get_site_setting($this->name, $name) : $this->get_setting($name);
		}

		return get_option($name, null);
	}

	public function render($page){
		$sections	= $this->get_sections();
		$form		= wpjam_tag('form', ['method'=>'POST', 'id'=>'wpjam_option', 'novalidate']);

		foreach($sections as $id => $section){
			$tab	= wpjam_tag();

			if(count($sections) > 1){
				if(!$page->tab_page){
					$tab	= wpjam_tag('div', ['id'=>'tab_'.$id]);
					$nav[]	= wpjam_tag('a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id], $section['title'])->wrap('li')->data(wpjam_pick($section, ['show_if']));
				}

				$title	= empty($section['title']) ? '' : [($page->tab_page ? 'h3' : 'h2'), [], $section['title']];
			}

			$form->append($tab->append([
				$title ?? '',
				empty($section['callback']) ? '' : wpjam_ob_get_contents($section['callback'], $section),
				empty($section['summary']) ? '' : wpautop($section['summary']),
				$this->get_fields($section['fields'])->render()
			]));
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append(wpjam_tag('p', ['submit'])->append([
			get_submit_button('', 'primary', 'save', false),
			$this->reset ? get_submit_button('重置选项', 'secondary', 'reset', false) : ''
		]));

		return isset($nav) ? $form->before(wpjam_tag('ul')->append($nav)->wrap('h2', ['nav-tab-wrapper', 'wp-clearfix']))->wrap('div', ['tabs']) : $form;
	}

	public function page_load(){
		wpjam_add_admin_ajax('wpjam-option-action',	[
			'callback'		=> [$this, 'ajax_response'],
			'nonce_action'	=> fn()=> $this->option_group,
			'allow'			=> fn()=> current_user_can($this->capability)
		]);
	}

	public function ajax_response(){
		flush_rewrite_rules();

		$submit	= wpjam_get_post_parameter('submit_name');
		$values	= $this->validate(wpjam_get_data_parameter()) ?: [];
		$fix	= is_network_admin() ? 'site_option' : 'option';

		if($this->option_type == 'array'){
			$cb	= $this->update_callback ?: 'wpjam_update_'.$fix;
			$cb	= is_callable($cb) ? $cb : wp_die('无效的回调函数');

			if($submit == 'reset'){
				$values	= wpjam_diff($this->value_callback(), $values, 'key');
			}else{
				$values	= wpjam_filter(array_merge($this->value_callback(), $values), fn($v)=> !is_null($v), true);
				$values	= wpjam_try(fn()=> $this->call_method('sanitize_callback', $values, $this->name)) ?? $values;
			}

			$cb($this->name, $values);
		}else{
			wpjam_map($values, fn($v, $k)=> $submit == 'reset' ? ('delete_'.$fix)($k) : ('update_'.$fix)($k, $v));
		}

		$errors	= array_filter(get_settings_errors(), fn($e)=> !in_array($e['type'], ['updated', 'success', 'info']));
		$errors	&& wp_die(implode('&emsp;', array_column($errors, 'message')));

		return [
			'type'		=> (!$this->ajax || $submit == 'reset') ? 'redirect' : $submit,
			'errmsg'	=> $submit == 'reset' ? '设置已重置。' : '设置已保存。'
		];
	}

	public static function pick($args){
		return wpjam_pick($args, ['plugin_page', 'current_tab']);
	}

	public static function create($name, $args){
		$args	= maybe_callback($args, $name);
		$args	+= [
			'option_group'	=> $name, 
			'option_page'	=> $name, 
			'option_type'	=> 'array',
			'capability'	=> 'manage_options',
			'ajax'			=> true,
		];

		if($sub	= self::pick($args)){
			$rest	= wpjam_except($args, ['model', 'menu_page', 'admin_load', 'plugin_page', 'current_tab']);
		}else{
			$args	= ['primary'=>true]+$args;
		}

		if($object	= self::get($name)){
			if($sub || is_null($object->primary)){
				$object->update_args($sub ? wpjam_except($rest, 'title') : $args);
			}else{
				trigger_error('option_setting'.'「'.$name.'」已经注册。'.var_export($args, true));
			}
		}else{
			$args['option_type'] == 'array' && !doing_filter('sanitize_option_'.$name) && is_null(get_option($name, null)) && add_option($name, []);

			$object	= self::register($name, $sub ? $rest : $args);
		}

		return $sub ? $object->register_sub(wpjam_join(':', $sub), $args) : $object;
	}
}

class WPJAM_Option_Model{
	protected static function call_method($method, ...$args){
		return ($object	= self::get_object()) ? $object->$method(...$args) : null;
	}

	protected static function get_object(){
		$option	= wpjam_get_annotation(static::class, 'option');
		$args	= $option ? [$option] : [static::class, 'model', self::class];

		return WPJAM_Option_Setting::get(...$args);
	}

	public static function get_setting($name='', ...$args){
		return self::call_method('get_setting', $name, ...$args);
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
		$this->dir = maybe_callback($this->dir);

		if(!is_dir($this->dir)){
			return;
		}

		if($this->option){
			$this->option	= wpjam_register_option($this->option, $this->to_array()+[
				'model'			=> $this,
				'ajax'			=> false,
				'site_default'	=> $this->sitewide
			]);

			$extends	= array_keys(array_merge($this->get_option(), $this->get_option(true)));
		}else{
			$extends	= array_diff(scandir($this->dir), ['.', '..']);

			if($plugins	= get_option('active_plugins')){
				$extends	= array_filter($extends, fn($v)=> !in_array($v.(is_dir($this->dir.'/'.$v) ? '/'.$v : '').'.php', $plugins));
			}
		}

		array_walk($extends, fn($extend)=> $this->handle($extend, 'include'));
	}

	private function handle($extend, $action='include'){
		if(str_ends_with($extend, '.php')){
			$extend	= substr($extend, 0, -4);
		}

		if($action == 'parse'){
			return $extend;
		}

		if($extend == 'extends'){
			return;
		}

		$file	= $this->dir.'/'.$extend;
		$file	.= is_dir($file) ? '/'.$extend.'.php' : '.php';

		if(is_file($file)){
			if($action == 'get_data'){
				return $this->get_file_data($file);
			}

			if(is_admin() || !str_ends_with($file, '-admin.php')){
				include_once $file;
			}
		}
	}

	private function get_option($site=false){
		return $this->sanitize_callback($this->option->get_option($site));
	}

	public function get_fields(){
		return wpjam_sort(wpjam_array(array_diff(scandir($this->dir), ['.', '..']), function($k, $extend){
			$extend	= $this->handle($extend, 'parse');
			$data	= $this->handle($extend, 'get_data');

			if($data && $data['Name']){
				$option	= $this->get_option();

				if(is_multisite() && $this->sitewide){
					$sitewide	= $this->get_option(true);

					if(is_network_admin()){
						$option	= $sitewide;
					}elseif(!empty($sitewide[$extend])){
						return;
					}
				}
			
				return [$extend, [
					'value'	=> !empty($option[$extend]),
					'title'	=> $data['URI'] ? '<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>' : $data['Name'],
					'label'	=> $data['Description']
				]];
			}
		}), ['value'=>'DESC']);
	}

	public function sanitize_callback($data){
		return wpjam_array($data, fn($k, $v)=> $v ? $this->handle($k, 'parse') : null);
	}

	public static function get_file_data($file){
		$data	= $file ? get_file_data($file, [
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		]) : [];

		return $data ? wpjam_fill(['URI', 'Name'], fn($k)=> !empty($data[$k]) ? $data[$k] : ($data['Plugin'.$k] ?? ''))+$data : [];
	}

	public static function get_file_summay($file){
		$data	= self::get_file_data($file);

		return str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。';
	}

	public static function create($dir, ...$args){
		$args	= is_array($dir) ? $dir : array_merge(($args[0] ?? []), compact('dir'));
		$hook	= wpjam_pull($args, 'hook');
		$object	= new self($args);

		if($hook){
			wpjam_load($hook, [$object, 'load'], ($object->priority ?? 10));
		}else{
			$object->load();
		}
	}
}

class WPJAM_Verify_TXT{
	public static function get_rewrite_rule(){
		add_filter('root_rewrite_rules', fn($rewrite)=> $GLOBALS['wp_rewrite']->root ? $rewrite : array_merge(['([^/]+)\.txt?$'=>'index.php?module=txt&action=$matches[1]'], $rewrite));
	}

	public static function redirect($action){
		$data	= wpjam_get_option('wpjam_verify_txts') ?: [];
		$name	= str_replace('.txt', '', $action).'.txt';
		$txt	= array_find($data, fn($v)=> $v['name'] == $name);

		if($txt){
			header('Content-Type: text/plain');
			echo $txt['value'];

			exit;
		}
	}

	public static function get($name, $key=null){
		$data	= wpjam_get_setting('wpjam_verify_txts', $name);

		if($key == 'fields'){
			return [
				'name'	=>['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$data['name'] ?? '',	'class'=>'all-options'],
				'value'	=>['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$data['value'] ?? '']
			];
		}

		return $key ? ($data[$key] ?? '') : $data;
	}

	public static function set($name, $data){
		return wpjam_update_setting('wpjam_verify_txts', $name, $data);
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_Meta_Type extends WPJAM_Register{
	public function __call($method, $args){
		if(try_remove_suffix($method, '_option')){	// get_option register_option unregister_option
			$name	= array_shift($args);

			if($method == 'register'){
				$args	= ['meta_type'=>$this->name]+array_shift($args);

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

			$args	= [$this->name.':'.$name, ...$args];
			$cb		= ['WPJAM_Meta_Option', $method];
		}elseif(in_array($method, ['get_data', 'add_data', 'update_data', 'delete_data', 'data_exists'])){
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

		if(empty($cb)){
			trigger_error('无效的方法'.$method);
			return;
		}

		return $cb(...$args);
	}

	protected function preprocess_args($args){
		$wpdb	= $GLOBALS['wpdb'];
		$global	= $args['global'] ?? false;
		$table	= $args['table_name'] ?? $this->name.'meta';

		$wpdb->$table ??= $args['table'] ?? ($global ? $wpdb->base_prefix : $wpdb->prefix).$this->name.'meta';

		$global && wp_cache_add_global_groups($this->name.'_meta');

		return parent::preprocess_args($args);
	}

	public function lazyload_data($ids){
		wpjam_lazyload($this->name.'_meta', $ids);
	}

	public function get_table(){
		return _get_meta_table($this->name);
	}

	public function get_column($name='object'){
		if(in_array($name, ['object', 'object_id'])){
			return $this->name.'_id';
		}elseif($name == 'id'){
			return 'user' == $this->name ? 'umeta_id' : 'meta_id';
		}
	}

	public function get_options($args=[]){
		if($this->name == 'post'){
			if(isset($args['post_type'])){
				$object = wpjam_get_post_type_object($args['post_type']);
				$object	&& $object->register_option();

				$args['post_type']	= ['value'=>$args['post_type'], 'if_null'=>true, 'callable'=>true];
			}
		}elseif($this->name == 'term'){
			if(isset($args['taxonomy'])){
				$object = wpjam_get_taxonomy_object($args['taxonomy']);
				$object	&& $object->register_option();

				$args['taxonomy']	= ['value'=>$args['taxonomy'], 'if_null'=>true, 'callable'=>true];
			}

			if(isset($args['action'])){
				$args['action']		= ['value'=>$args['action'], 'if_null'=>true, 'callable'=>true];
			}
		}

		if(isset($args['list_table'])){
			$args['title']		= true;
			$args['list_table']	= $args['list_table'] ? true : ['compare'=>'!==', 'value'=>'only'];
		}

		return wpjam_array(WPJAM_Meta_Option::get_by(['meta_type'=>$this->name]+$args), fn($k, $v)=> $v->name);
	}

	public function register_actions($args=[]){
		foreach($this->get_options(['list_table'=>true]+$args) as $v){
			wpjam_register_list_table_action($v->action_name, $v->get_args()+[
				'meta_type'		=> $v->name,
				'page_title'	=> '设置'.$v->title,
				'submit_text'	=> '设置'
			]);
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

		if($id && $args[0]){
			if(is_array($args[0])){
				return wpjam_array($args[0], fn($k, $v)=> [is_numeric($k) ? $v : $k, $this->get_data_with_default($id, ...(is_numeric($k) ? [$v, null] : [$k, $v]))]);
			}

			if($args[0] == 'meta_input'){
				return array_map([$this, 'parse_value'], $this->get_data($id));
			}

			if($this->data_exists($id, $args[0])){
				return $this->get_data($id, $args[0], true);
			}
		}

		return is_array($args[0]) ? [] : ($args[1] ?? null);
	}

	public function get_by_key(...$args){
		global $wpdb;

		if(!$args){
			return [];
		}

		if(is_array($args[0])){
			$key	= $args[0]['meta_key'] ?? ($args[0]['key'] ?? '');
			$value	= $args[0]['meta_value'] ?? ($args[0]['value'] ?? '');
			$column	= $args[1] ?? '';
		}else{
			$key	= $args[0];
			$value	= $args[1] ?? null;
			$column	= $args[2] ?? '';
		}

		$where	= array_filter([
			$key ? $wpdb->prepare('meta_key=%s', $key) : '',
			!is_null($value) ? $wpdb->prepare('meta_value=%s', maybe_serialize($value)) : ''
		]);

		if($where){
			$where	= implode(' AND ', $where);
			$table	= $this->get_table();
			$data	= $wpdb->get_results("SELECT * FROM {$table} WHERE {$where}", ARRAY_A) ?: [];

			if($data){
				$data	= array_map([$this, 'parse_value'], $data);

				return $column ? reset($data)[$this->get_column($column)] : $data;
			}
		}

		return $column ? null : [];
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

		if($this->object_key){
			$object_key		= $this->object_key;
			$object_table	= $wpdb->{$this->name.'s'};
		}else{
			$object_model	= $this->object_model;

			if(!$object_model || !is_callable([$object_model, 'get_table'])){
				return;
			}

			$object_table	= $object_model::get_table();
			$object_key		= $object_model::get_primary_key();
		}

		if(is_multisite() && !str_starts_with($this->get_table(), $wpdb->prefix) && wpjam_lock($this->name.':meta_type:cleanup', DAY_IN_SECONDS, true)){
			return;
		}

		$mids	= $wpdb->get_col("SELECT m.".$this->get_column('id')." FROM ".$this->get_table()." m LEFT JOIN ".$object_table." t ON t.".$object_key." = m.".$this->get_column('object')." WHERE t.".$object_key." IS NULL") ?: [];

		array_walk($mids, [$this, 'delete_by_mid']);
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
	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'list_table'){
			return $value ?? (did_action('current_screen') && !empty($GLOBALS['plugin_page']));
		}elseif($key == 'callback'){
			return $value ?: $this->update_callback;
		}elseif($key == 'action_name'){
			return $value ?: 'set_'.$this->name;
		}

		return $value;
	}

	public function get_fields($id=null, $output='object'){
		$fields	= maybe_callback($this->fields, $id, $this->name);

		return $output == 'object' ? WPJAM_Fields::create($fields, array_merge($this->get_args(), ['id'=>$id])) : $fields;
	}

	public function prepare($id=null){
		return $this->callback ? [] : $this->get_fields($id)->prepare();
	}

	public function validate($id=null, $data=null){
		return $this->get_fields($id)->validate($data);
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

		echo $this->get_fields($id)->render($args);
	}

	public function callback($id, $data=null){
		$fields	= $this->get_fields($id);
		$data	= $fields->catch('validate', $data);

		if(is_wp_error($data) || !$data){
			return $data ?: true;
		}

		if($this->callback){
			$result	= is_callable($this->callback) ? call_user_func($this->callback, $id, $data, $this->get_fields($id, '')) : false;

			return $result === false ? new WP_Error('invalid_callback') : $result;
		}

		return wpjam_update_metadata($this->meta_type, $id, $data, $fields->get_defaults());
	}
}