<?php
class WPJAM_Setting extends WPJAM_Args{
	private $values;

	public function __call($method, $args){
		if(str_ends_with($method, '_option')){
			$cb			= wpjam_remove_postfix($method, '_option').'_'.$this->type;
			$cb_args	= [...($this->type == 'blog_option' ? [$this->blog_id] : []), $this->name];

			if($method == 'get_option'){
				if(isset($this->values)){
					return $this->values;
				}

				$result	= $cb(...$cb_args);

				return $this->values = $result === false ? ($args ? $args[0] : []) : $this->sanitize_option($result);
			}

			$this->values	= null;

			return $cb(...[...$cb_args, ($args[0] ? $this->sanitize_option($args[0]) : $args[0])]);
		}elseif(str_ends_with($method, '_setting') || in_array($method, ['get', 'update', 'delete'])) {
			$action	= wpjam_remove_postfix($method, '_setting');
			$values	= $this->get_option();
			$name	= array_shift($args);

			if($action == 'get'){
				if(!$name){
					return $values;
				}

				if(is_array($name)){
					return wpjam_fill(array_filter($name), [$this, 'get']);
				}

				$value	= is_array($values) ? wpjam_get($values, $name) : null;

				return is_wp_error($value) ? null : (is_string($value) ? str_replace("\r\n", "\n", trim($value)) : $value);
			}

			if($action == 'update'){
				if(is_array($name)){
					foreach($name as $n => $v){
						$values	= wpjam_set($values, $n, $v);
					}
				}else{
					$values	= wpjam_set($values, $name, array_shift($args));
				}
			}else{
				$values	= wpjam_except($values, $name);
			}

			return $this->update_option($values);
		}
	}

	public static function get_instance($type='', $name='', $blog_id=0){
		if(!in_array($type, ['option', 'site_option']) || !$name){
			return null;
		}

		$args	= (is_multisite() && $type == 'option') ? ['type'=>'blog_option', 'name'=>$name, 'blog_id'=>(int)$blog_id ?: get_current_blog_id()] : compact('type', 'name');

		return wpjam_get_instance('setting', join(':', $args), fn()=> new static($args));
	}

	public static function sanitize_option($value){
		return (is_wp_error($value) || !$value) ? [] : $value;
	}

	public static function parse_json_module($args){
		$option	= wpjam_get($args, 'option_name');

		if($option){
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

		if($value && $key == 'menu_page' && $this->name){
			if(is_network_admin() && !$this->site_default){
				return;
			}

			if(wp_is_numeric_array($value)){
				return wpjam_array($value, function($k, $v){
					if(!empty($v['tab_slug'])){
						return empty($v['plugin_page']) ? null : [$k, $v];
					}elseif(!empty($v['menu_slug'])){
						return [$k, $v+($v['menu_slug'] == $this->name ? ['menu_title'=>$this->title] : [])];
					}
				});
			}

			if(!empty($value['tab_slug'])){
				return empty($value['plugin_page']) ? null : $value+['title'=>$this->title];
			}

			return $value+['menu_slug'=>$this->name, 'menu_title'=>$this->title];
		}

		return $value;
	}

	public function get_current(){
		return $this->get_sub(self::generate_sub_name($GLOBALS)) ?: $this;
	}

	protected function get_sections($get_subs=false, $filter=true){
		$parser	= function($section, $id){
			$fields	= $section['fields'] ?? [];
			$fields	= is_callable($fields) ? $fields($id, $this->name) : $fields;

			return array_merge($section, compact('fields'));
		};

		$sections	= $this->get_arg('sections');

		if($sections && is_array($sections)){
			$sections	= array_filter($sections, 'is_array');
		}else{
			$fields		= $this->get_arg('fields', null, false);
			$sections	= is_null($fields) ? [] : [($this->sub_name ?: $this->name)=> ['title'=>$this->title, 'fields'=>$fields]];
		}

		$sections	= wpjam_map($sections, $parser);
		$sections	= $get_subs ? array_reduce($this->get_subs(), fn($carry, $sub)=> array_merge($carry, $sub->get_sections(false, false)), $sections) : $sections;

		if($filter){
			$parent	= $this->sub_name ? $this->get_parent() : $this;

			foreach($parent->get_items('section_objects') as $object){
				foreach(($object->get_arg('sections') ?: []) as $id => $section){
					$section	= $parser($section, $id);

					if(isset($sections[$id])){
						$sections[$id]	= wpjam_merge($sections[$id], $section);
					}else{
						if(isset($section['title']) && isset($section['fields'])){
							$sections[$id]	= $section;
						}
					}
				}
			}

			return apply_filters('wpjam_option_setting_sections', $sections, $this->name);
		}

		return $sections;
	}

	public function add_section(...$args){
		$args	= is_array($args[0]) ? $args[0] : [$args[0]=> isset($args[1]['fields']) ? $args[1] : ['fields'=>$args[1]]];
		$args	= isset($args['model']) || isset($args['sections']) ? $args : ['sections'=>$args];
		$name	= md5(maybe_serialize($args));
		$object = new static('', ['sub_name'=>$name]+$args);

		$this->add_item($object, 'section_objects');

		return self::register($this->name.'-'.$name, $object);
	}

	public function get_fields($get_subs=false){
		return array_merge(...array_column($this->get_sections($get_subs), 'fields'));
	}

	public function get_option($site=false){
		if($site && (!$this->site_default || !is_multisite())){
			return [];
		}

		$cb		= 'wpjam_get_'.($site ? 'site_' : '').'option';
		$data	= $cb($this->option);

		return $data ?: [];
	}

	public function get_setting($name, ...$args){
		$null	= $name ? null : [];

		foreach(['wpjam_get_setting', ...(($this->site_default && is_multisite()) ? ['wpjam_get_site_setting'] : [])] as $cb){
			$value = $cb($this->name, $name);

			if($value !== $null){
				return $value; 
			}
		}

		if($args){
			return $args[0];
		}

		if($this->field_default){
			$this->_defaults ??= $this->call_fields('get_defaults');

			return $name ? wpjam_get($this->_defaults, $name) : $this->_defaults;
		}

		return $null;
	}

	public function update_setting(...$args){
		return wpjam_update_setting($this->name, ...$args);
	}

	public function delete_setting($name){
		return wpjam_delete_setting($this->name, $name);
	}

	protected function call_fields($method, ...$args){
		$get_subs	= $method != 'validate';
		$fields		= $this->get_fields($get_subs);

		return wpjam_fields($fields)->$method(...$args);
	}

	public function prepare(){
		return $this->call_fields('prepare', ['value_callback'=>[$this, 'value_callback']]);
	}

	public function validate($value){
		return $this->call_fields('validate', $value);
	}

	public function value_callback($name=''){
		if($this->option_type == 'array'){
			return is_network_admin() ? wpjam_get_site_setting($this->name, $name) : $this->get_setting($name);
		}

		return get_option($name, null);
	}

	public function render($page){
		$sections	= $this->get_sections();
		$form		= wpjam_tag('form', ['method'=>'POST', 'id'=>'wpjam_option']);

		foreach($sections as $id => $section){
			$tab	= wpjam_tag();

			if(count($sections) > 1){
				if(!$page->tab_page){
					$tab	= wpjam_tag('div', ['id'=>'tab_'.$id]);
					$nav[]	= wpjam_tag('a', ['class'=>'nav-tab', 'href'=>'#tab_'.$id], $section['title'])->wrap('li')->data('show_if', wpjam_parse_show_if($section['show_if'] ?? null));
				}

				$title	= empty($section['title']) ? '' : [($page->tab_page ? 'h3' : 'h2'), [], $section['title']];
			}

			$form->append($tab->append([
				$title ?? '',
				empty($section['callback']) ? '' : wpjam_ob_get_contents($section['callback'], $section),
				empty($section['summary']) ? '' : wpautop($section['summary']),
				wpjam_fields($section['fields'])->render(['value_callback'=>[$this, 'value_callback']])
			]));
		}

		$form->data('nonce', wp_create_nonce($this->option_group))->append(wpjam_tag('p', ['submit'])->append([
			get_submit_button('', 'primary', 'option_submit', false, ['data-action'=>'save']),
			$this->reset ? get_submit_button('重置选项', 'secondary', 'option_reset', false, ['data-action'=>'reset']) : ''
		]));

		return isset($nav) ? $form->before(wpjam_tag('ul')->append($nav)->wrap('h2', ['nav-tab-wrapper', 'wp-clearfix']))->wrap('div', ['tabs']) : $form;
	}

	public function page_load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-option-action',	[$this, 'ajax_response']);
		}
	}

	public function ajax_response(){
		if(!check_ajax_referer($this->option_group, false, false)){
			wp_die('invalid_nonce');
		}

		if(!current_user_can($this->capability)){
			wp_die('access_denied');
		}

		$action	= wpjam_get_post_parameter('option_action');
		$values	= wpjam_get_data_parameter();
		$values	= $this->validate($values) ?: [];
		$fix	= is_network_admin() ? 'site_option' : 'option';

		if($this->option_type == 'array'){
			$callback	= $this->update_callback ?: 'wpjam_update_'.$fix;
			$callback	= is_callable($callback) ? $callback : wp_die('无效的回调函数');
			$current	= $this->value_callback();

			if($action == 'reset'){
				$values	= wpjam_diff($current, $values);
			}else{
				$values	= wpjam_filter(array_merge($current, $values), fn($v)=> !is_null($v), true);
				$result	= $this->try_method('sanitize_callback', $values, $this->name);
				$values	= $result ?? $values;
			}

			$callback($this->name, $values);
		}else{
			foreach($values as $name => $value){
				$callback	= ($action == 'reset' ? 'delete_' : 'update_').$fix;

				$callback($name, ...($action == 'reset' ? [] : [$value]));
			}
		}

		$errors	= array_filter(get_settings_errors(), fn($e)=> !in_array($e['type'], ['updated', 'success', 'info']));

		if($errors){
			wp_die(implode('&emsp;', array_column($errors, 'message')));
		}

		return [
			'type'		=> $this->response ?? ($this->ajax ? $action : 'redirect'),
			'errmsg'	=> $action == 'reset' ? '设置已重置。' : '设置已保存。'
		];
	}

	public static function generate_sub_name($args){
		return wpjam_join(':', wpjam_slice($args, ['plugin_page', 'current_tab']));
	}

	public static function create($name, $args){
		$args	= is_callable($args) ? $args($name) : $args;
		$args	+= [
			'option_group'	=> $name, 
			'option_page'	=> $name, 
			'option_type'	=> 'array',
			'capability'	=> 'manage_options',
			'ajax'			=> true,
		];

		$sub	= self::generate_sub_name($args);
		$rest	= $sub ? wpjam_except($args, ['title', 'model', 'menu_page', 'admin_load', 'plugin_page', 'current_tab']) : [];
		$object	= self::get($name);

		if($object){
			if($sub){
				return $object->update_args($rest)->register_sub($sub, $args);
			}

			if(is_null($object->primary)){
				return self::re_register($name, array_merge($object->to_array(), $args, ['primary'=>true]));
			}

			trigger_error('option_setting'.'「'.$name.'」已经注册。'.var_export($args, true));

			return $object;
		}else{
			if($args['option_type'] == 'array' && !doing_filter('sanitize_option_'.$name) && is_null(get_option($name, null))){
				add_option($name, []);
			}

			if($sub){
				return (self::register($name, $rest))->register_sub($sub, $args);
			}

			return self::register($name, array_merge($args, ['primary'=>true]));
		}
	}
}

class WPJAM_Option_Model{
	protected static function call_method($method, ...$args){
		$object	= self::get_object();

		return $object ? $object->$method(...$args) : null;
	}

	protected static function get_object(){
		return WPJAM_Option_Setting::get(get_called_class(), 'model', 'WPJAM_Option_Model');
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

class WPJAM_Notice{
	public static function add($item, $type='admin', $id=''){
		if($type == 'admin'){
			if(is_multisite() && $id && !get_site($id)){
				return;
			}
		}else{
			if($id && !get_userdata($id)){
				return;
			}
		}

		$item	= is_array($item) ? $item : ['notice'=>$item];
		$item	+= ['type'=>'error', 'notice'=>'', 'time'=>time(), 'key'=>md5(serialize($item))];

		return (self::get_instance($type, $id))->insert($item);
	}

	public static function ajax_delete(){
		$type	= wpjam_get_data_parameter('notice_type');
		$key	= wpjam_get_data_parameter('notice_key');

		if($key){
			if($type == 'admin' && !current_user_can('manage_options')){
				wp_die('bad_authentication');
			}

			return (self::get_instance($type))->delete($key);
		}
	}

	public static function get_instance($type='admin', $id=0){
		$filter	= fn($items)=> array_filter(($items ?: []), fn($v)=> $v['time']>(time()-MONTH_IN_SECONDS*3) && trim($v['notice']));

		if($type == 'user'){
			$id	= (int)$id ?: get_current_user_id();

			return wpjam_get_handler('notice:user:'.$id, [
				'meta_key'		=> 'wpjam_notices',
				'user_id'		=> $id,
				'primary_key'	=> 'key',
				'get_items'		=> fn()=> $filter(get_user_meta($this->user_id, $this->meta_key, true)),
				'delete_items'	=> fn()=> delete_user_meta($this->user_id, $this->meta_key),
				'update_items'	=> fn($items)=> update_user_meta($this->user_id, $this->meta_key, $items),
			]);
		}else{
			$id	= (int)$id ?: get_current_blog_id();

			return wpjam_get_handler('notice:admin:'.$id, [
				'option_name'	=> 'wpjam_notices',
				'blog_id'		=> $id,
				'primary_key'	=> 'key',
				'get_items'		=> fn()=> $filter(wpjam_call_for_blog($this->blog_id, 'get_option', $this->option_name)),
				'update_items'	=> fn($items)=> wpjam_call_for_blog($this->blog_id, 'update_option', $this->option_name, $items),
			]);
		}
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
		if(in_array($name, ['object', 'object_id'])){
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

	public function delete_orphan_data($object_table='', $object_key=''){
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