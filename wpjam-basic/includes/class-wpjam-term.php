<?php
class WPJAM_Term extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'term_id'])){
			return $this->id;
		}elseif($key == 'tax_object'){
			return wpjam_get_taxonomy_object($this->taxonomy);
		}elseif($key == 'object_type'){
			return $this->get_tax_setting($key) ?: [];
		}elseif($key == 'level'){
			return get_term_level($this->id);
		}elseif($key == 'depth'){
			return get_term_depth($this->id);
		}elseif($key == 'link'){
			return get_term_link($this->term);
		}elseif($key == 'term'){
			return get_term($this->id);
		}else{
			return $this->term->$key ?? $this->meta_get($key);
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function __call($method, $args){
		if($method == 'get_tax_setting'){
			return $this->tax_object->{$args[0]};
		}elseif(in_array($method, ['get_taxonomies', 'supports'])){
			return $this->tax_object->$method(...$args);
		}elseif(in_array($method, ['set_object', 'add_object', 'remove_object'])){
			$cb	= 'wp_'.$method.'_terms';

			return $cb(array_shift($args), [$this->id], $this->taxonomy, ...$args);
		}elseif($method == 'is_object_in'){
			return is_object_in_term($args[0], $this->taxonomy, $this->id);
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function value_callback($field){
		return $this->term->$field ?? $this->meta_get($field);
	}

	public function update_callback($data, $defaults){
		$keys	= ['name', 'parent', 'slug', 'description', 'alias_of'];
		$result	= $this->save(wpjam_pull($data, $keys));

		return (!is_wp_error($result) && $data) ? $this->meta_input($data, $defaults) : $result;
	}

	public function save($data){
		return $data ? self::update($this->id, $data, false) : true;
	}

	public function get_object_type(){
		return $this->object_type;
	}

	public function get_thumbnail_url($size='full', $crop=1){
		return wpjam_get_term_thumbnail_url($this->term, $size, $crop);
	}

	public function parse_for_json($args=[]){
		$json['id']				= $this->id;
		$json['taxonomy']		= $this->taxonomy;
		$json['name']			= html_entity_decode($this->name);
		$json['count']			= (int)$this->count;
		$json['description']	= $this->description;

		$json	+= is_taxonomy_viewable($this->taxonomy) ? ['slug'=>$this->slug] : [];
		$json	+= is_taxonomy_hierarchical($this->taxonomy) ? ['parent'=>$this->parent] : [];
		$json	= array_reduce(wpjam_get_term_options($this->taxonomy), fn($carry, $option)=> array_merge($json, $option->prepare($this->id)), $json);

		return apply_filters('wpjam_term_json', $json, $this->id);
	}

	public static function get_instance($term, $taxonomy=null, $wp_error=false){
		$term	= self::validate($term, $taxonomy);

		if(is_wp_error($term)){
			return $wp_error ? $term : null;
		}

		return self::instance($term->term_id, fn($id)=> [wpjam_get_taxonomy_setting(get_term_taxonomy($id), 'model') ?: 'WPJAM_Term', 'create_instance']($id));
	}

	public static function get($term){
		$data	= $term ? self::get_term($term, '', ARRAY_A) : [];

		return $data && !is_wp_error($data) ? $data+['id'=>$data['term_id']] : $data;
	}

	public static function update($term_id, $data, $validate=true){
		$result	= $validate ? wpjam_catch(fn()=> static::validate($term_id)) : null;

		return is_wp_error($result) ? $result : parent::update($term_id, $data);
	}

	protected static function call_method($method, ...$args){
		if($method == 'get_meta_type'){
			return 'term';
		}elseif($method == 'insert'){
			$data	= $args[0];
			$tax	= array_unique(array_filter([wpjam_pull($data, 'taxonomy'), static::get_current_taxonomy()]));
			$tax	= count($tax) == 1 ? array_first($tax) : null;

			return wpjam_try('wp_insert_term', wp_slash(wpjam_pull($data, 'name')), $tax, wp_slash($data))['term_id'];
		}elseif($method == 'update'){
			$data	= $args[1];
			$tax	= wpjam_pull($data, 'taxonomy') ?: get_term_field('taxonomy', $args[0]);

			return wpjam_try('wp_update_term', $args[0], $tax, wp_slash($data));
		}elseif($method == 'delete'){
			return wpjam_try('wp_delete_term', $args[0], get_term_field('taxonomy', $args[0]));
		}
	}

	public static function get_meta($term_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_get_metadata');
		return wpjam_get_metadata('term', $term_id, ...$args);
	}

	public static function update_meta($term_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');
		return wpjam_update_metadata('term', $term_id, ...$args);
	}

	public static function update_metas($term_id, $data, $meta_keys=[]){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');
		return self::update_meta($term_id, $data, $meta_keys);
	}

	public static function get_by_ids($term_ids){
		return self::update_caches($term_ids);
	}

	public static function update_caches($term_ids){
		if($term_ids	= array_filter(wp_parse_id_list($term_ids))){
			_prime_term_caches($term_ids);

			$terms	= wp_cache_get_multiple($term_ids, 'terms');

			do_action('wpjam_deleted_ids', 'term', array_keys(array_filter($terms, fn($v)=> !$v)));

			return array_filter($terms);
		}

		return [];
	}

	public static function get_term($term, $taxonomy='', $output=OBJECT, $filter='raw'){
		return wpjam_tap(get_term($term, $taxonomy, $output, $filter), fn($v)=> $term && is_numeric($term) && !$v && do_action('wpjam_deleted_ids', 'term', $term));
	}

	public static function get_current_taxonomy(){
		if(static::class !== self::class){
			return (WPJAM_Taxonomy::get(static::class, 'model', self::class) ?: [])['name'] ?? null;
		}
	}

	public static function get_path($args, $item=[]){
		$tax	= $item['taxonomy'];
		$key	= wpjam_get_taxonomy_setting($tax, 'query_key');
		$id		= is_array($args) ? (int)wpjam_get($args, $key) : $args;

		if($id === 'fields'){
			return $key ? [$key=> self::get_field(['taxonomy'=>$tax, 'required'=>true])] : [];
		}

		if(!$id){
			return new WP_Error('invalid_id', [wpjam_get_taxonomy_setting($tax, 'title')]);
		}

		return $item['platform'] == 'template' ? get_term_link($id) : str_replace('%term_id%', $id, $item['path']);
	}

	public static function get_field($args=[]){
		$object	= isset($args['taxonomy']) && is_string($args['taxonomy']) ? wpjam_get_taxonomy_object($args['taxonomy']) : null;
		$type	= $args['type'] ?? '';
		$title	= $args['title'] ??= $object ? $object->title : null;
		$args	+= ['data_type'=>'taxonomy'];

		if($object && ($object->hierarchical || ($type == 'select' || $type == 'mu-select'))){
			if(is_admin() && !$type && $object->levels > 1 && $object->selectable){
				$field	= ['type'=>'number']+self::parse_option_args($args);

				return array_merge($args, [
					'sep'		=> ' ',
					'fields'	=> wpjam_array(range(0, $object->levels-1), fn($k, $v)=> ['level_'.$v, $field]),
					'render'	=> function($args){
						$tax	= $this->taxonomy;
						$values	= $this->value ? array_reverse([$this->value, ...get_ancestors($this->value, $tax, 'taxonomy')]) : [];
						$terms	= get_terms(['taxonomy'=>$tax, 'hide_empty'=>0]);
						$fields	= $this->fields;
						$parent	= 0;

						for($level=0; $level < count($fields); $level++){
							$options	= is_null($parent) ? [] : array_column(wp_list_filter($terms, ['parent'=>$parent]), 'name', 'term_id');
							$value		= $values[$level] ?? 0;
							$parent		= $value ?: null;

							$fields['level_'.$level]	= array_merge(
								$fields['level_'.$level],
								['type'=>'select', 'data_type'=>'taxonomy', 'taxonomy'=>$tax, 'value'=>$value, 'options'=>$options],
								($level > 0 ? ['show_if'=>['level_'.($level-1), '!=', 0], 'data-filter_key'=>'parent'] : [])
							);
						}

						return $this->update_arg('fields', $fields)->render_by_fields($args);
					}
				]);
			}

			if(!$type || ($type == 'mu-text' && empty($args['item_type']))){
				if(!is_admin() || $object->selectable){
					$type	= $type ? 'mu-select' : 'select';
				}
			}elseif($type == 'mu-text' && $args['item_type'] == 'select'){
				$type	= 'mu-select';
			}

			if($type == 'select' || $type == 'mu-select'){
				return array_merge($args, self::parse_option_args($args), [
					'type'		=> $type,
					'options'	=> fn()=> $object->get_options()
				]);
			}
		}

		return $args+['type'=>'text', 'class'=>'all-options', 'placeholder'=>'请输入'.$title.'ID或者输入关键字筛选'];
	}

	public static function with_field($method, $field, $value){
		$tax	= $field->taxonomy;

		if($method == 'validate'){
			if(is_array($value)){
				$level	= array_find(range((wpjam_get_taxonomy_setting($tax, 'levels') ?: 1)-1, 0), fn($v)=> $value['level_'.$v] ?? 0);
				$value	= is_null($level) ? 0 : $value['level_'.$level];
			}

			if(is_numeric($value)){
				return !$value || wpjam_try('get_term', $value, $tax) ? (int)$value : null;
			}

			$result	= term_exists($value, $tax);

			return $result ? (is_array($result) ? $result['term_id'] : $result) : ($field->creatable ? wpjam_try('WPJAM_Term::insert', ['name'=>$value, 'taxonomy'=>$tax]) : null);
		}elseif($method == 'parse'){
			return ($object = self::get_instance($value, $tax)) ? $object->parse_for_json() : null;
		}
	}

	public static function parse_option_args($args){
		$parsed	= ['show_option_all'=>'请选择'];

		if(isset($args['option_all'])){	// 兼容
			$v	= $args['option_all'];

			return $v === false ? [] : ($v === true ? [] : ['show_option_all'=>$v])+$parsed;
		}

		return wpjam_map($parsed, fn($v, $k)=> $args[$k] ?? $v);
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return array_values(get_terms($args+[
				'number'		=> (isset($args['parent']) ? 0 : 10),
				'hide_empty'	=> false
			]));
		}

		$defaults	= [
			'hide_empty'	=> false,
			'taxonomy'		=> static::get_current_taxonomy()
		];

		return [
			'items'	=> get_terms($args+$defaults),
			'total'	=> wp_count_terms($defaults)
		];
	}

	public static function validate($id, $taxonomy=null){
		$term		= self::get_term($id);
		$taxonomy	??= self::get_current_taxonomy();

		if(is_wp_error($term)){
			return $term;
		}elseif(!$term || !($term instanceof WP_Term)){
			return new WP_Error('invalid_term_id');
		}elseif(!taxonomy_exists($term->taxonomy) || ($taxonomy && $taxonomy !== 'any' && !in_array($term->taxonomy, (array)$taxonomy))){
			return new WP_Error('invalid_taxonomy');
		}

		return $term;
	}

	public static function filter_fields($fields, $id){
		return ($id && !is_array($id) && !isset($fields['name']) ? ['name'=>['title'=>wpjam_get_taxonomy_setting(get_term_field('taxonomy', $id), 'title'), 'type'=>'view', 'value'=>get_term_field('name', $id)]] : [])+$fields;
	}
}

/**
* @config menu_page, admin_load, register_json
**/
#[config('menu_page', 'admin_load', 'register_json')]
class WPJAM_Taxonomy extends WPJAM_Register{
	public function __get($key){
		if($key == 'title'){
			return $this->labels ? $this->labels->singular_name : $this->label;
		}elseif($key == 'selectable'){
			return wp_count_terms(['taxonomy'=>$this->name, 'hide_empty'=>false]+($this->levels > 1 ? ['parent'=>0] : [])) <= 30;
		}elseif($key != 'name' && property_exists('WP_Taxonomy', $key)){
			if($object	= get_taxonomy($this->name)){
				return $object->$key;
			}
		}

		$value	= parent::__get($key);

		if($key == 'model'){
			return $value && class_exists($value) ? $value : 'WPJAM_Term';
		}elseif($key == 'permastruct'){
			return ($value	??= $this->call('get_'.$key.'_by_model')) ? trim($value, '/') : $value;
		}elseif($key == 'show_in_posts_rest'){
			return $value ?? $this->show_in_rest;
		}else{
			return $value;
		}
	}

	public function __set($key, $value){
		if($key != 'name' && property_exists('WP_Taxonomy', $key)){
			if($object	= get_taxonomy($this->name)){
				$object->$key = $value;
			}
		}

		parent::__set($key, $value);
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	protected function preprocess_args($args){
		$args	= parent::preprocess_args($args);
		$args	+= ['supports'=>['slug', 'description', 'parent']]+(empty($args['_jam']) ? [] : [
			'rewrite'			=> true,
			'show_ui'			=> true,
			'show_in_nav_menus'	=> false,
			'show_in_rest'		=> true,
			'show_admin_column'	=> true,
			'hierarchical'		=> true,
		]);

		$args['supports']	= wpjam_array($args['supports'], fn($k, $v)=> is_numeric($k) ? [$v, true] : [$k, $v]);

		if($this->name == 'category'){
			$args['query_key']		= 'cat';
			$args['column_name']	= 'categories';
			$args['plural']			= 'categories';
		}elseif($this->name == 'post_tag'){
			$args['query_key']		= 'tag_id';
			$args['column_name']	= 'tags';
			$args['plural']			= 'post_tags';
		}else{
			$args['query_key']		= $this->name.'_id';
			$args['column_name']	= 'taxonomy-'.$this->name;
			$args['plural']			??= $this->name.'s';
		}

		return $args;
	}

	public function to_array(){
		$this->filter_args();

		if($this->permastruct){
			$this->rewrite		= $this->rewrite ?: true;
			$this->permastruct	= str_replace('%'.$this->query_key.'%', '%term_id%', $this->permastruct);

			if(strpos($this->permastruct, '%term_id%')){
				$this->remove_support('slug');

				$this->query_var	??= false;
			}
		}

		if($this->levels == 1){
			$this->remove_support('parent');
		}else{
			$this->add_support('parent');
		}

		if($this->rewrite && $this->_jam){
			$this->rewrite	= (is_array($this->rewrite) ? $this->rewrite : [])+['with_front'=>false, 'hierarchical'=>false];
		}

		return $this->args;
	}

	public function is_object_in($object_type){
		return is_object_in_taxonomy($object_type, $this->name);
	}

	public function is_viewable(){
		return is_taxonomy_viewable($this->name);
	}

	public function add_support($feature, $value=true){
		return $this->update_arg('supports['.$feature.']', $value);
	}

	public function remove_support($feature){
		return $this->delete_arg('supports['.$feature.']');
	}

	public function supports($feature){
		return is_array($feature) ? array_any($feature, [$this, $supports]) : (bool)$this->get_arg('supports['.$feature.']');
	}

	public function get_fields($id=0, $action_key=''){
		if($action_key == 'set'){
			$fields['name']	= ['title'=>'名称',	'type'=>'text',	'class'=>'',	'required'];

			if($this->supports('slug')){
				$fields['slug']	= ['title'=>'别名',	'type'=>'text',	'class'=>'',	'required'];
			}

			if($this->hierarchical && $this->levels !== 1 && $this->supports('parent')){
				$fields['parent']	= ['title'=>'父级',	'options'=>['-1'=>'无']+$this->get_options(apply_filters('taxonomy_parent_dropdown_args', ['exclude_tree'=>$id], $this->name, 'edit'))];
			}

			if($this->supports('description')){
				$fields['description']	= ['title'=>'描述',	'type'=>'textarea'];
			}
		}

		if($this->supports('thumbnail')){
			$fields['thumbnail']	= [
				'title'		=> '缩略图',
				'size'		=> $this->thumbnail_size,
				'type'		=> $this->thumbnail_type == 'image' ? 'image' : 'img',
				'item_type'	=> $this->thumbnail_type == 'image' ? 'image' : 'url',
			];
		}

		if($this->supports('banner')){
			$fields['banner']	= [
				'title'		=> '大图',
				'size'		=> $this->banner_size,
				'type'		=> 'img',
				'item_type'	=> 'url',
				'show_if'	=> ['parent', -1],
			];
		}

		return array_merge($fields ?? [], wpjam_fields($this, $id, $action_key));
	}

	public function get_options($args=[]){
		return array_column(wpjam_get_terms($args+['taxonomy'=>$this->name, 'hide_empty'=>0, 'format'=>'flat', 'parse'=>false]), 'name', 'term_id');
	}

	public function get_mapping($post_id){
		$post	= wpjam_validate_post($post_id, $this->mapping_post_type);

		if(is_wp_error($post)){
			return $post;
		}

		$post_type	= $post->post_type;
		$meta_key	= $this->query_key.'';
		$term_id	= get_post_meta($post_id, $meta_key, true);
		$data		= ['name'=>$post->post_title, 'slug'=>$post_type.'-'.$post_id, 'taxonomy'=>$this->name];

		if($term_id){
			$term	= get_term($term_id, $this->name);

			if($term){
				if($term->name != $data['name'] || $term->slug != $data['slug']){
					WPJAM_Term::update($term_id, $data);
				}

				return $term_id;
			}
		}

		$term_id	= WPJAM_Term::insert($data);

		if(!is_wp_error($term_id)){
			update_post_meta($post_id, $meta_key, $term_id);
		}

		return $term_id;
	}

	public function dropdown(){
		$selected	= wpjam_get_data_parameter($this->query_key);

		if(is_null($selected)){
			if($this->query_var){
				$term_slug	= wpjam_get_data_parameter($this->query_var);
			}elseif(wpjam_get_data_parameter('taxonomy') == $this->name){
				$term_slug	= wpjam_get_data_parameter('term');
			}else{
				$term_slug	= '';
			}

			$term 		= $term_slug ? get_term_by('slug', $term_slug, $this->name) : null;
			$selected	= $term ? $term->term_id : '';
		}

		if($this->hierarchical){
			wp_dropdown_categories([
				'taxonomy'			=> $this->name,
				'show_option_all'	=> $this->labels->all_items,
				'show_option_none'	=> '没有设置',
				'option_none_value'	=> 'none',
				'name'				=> $this->query_key,
				'selected'			=> $selected,
				'hierarchical'		=> true
			]);
		}else{
			echo wpjam_field([
				'key'			=> $this->query_key,
				'value'			=> $selected,
				'type'			=> 'text',
				'data_type'		=> 'taxonomy',
				'taxonomy'		=> $this->name,
				'filterable'	=> true,
				'placeholder'	=> '请输入'.$this->title,
				'title'			=> '',
				'class'			=> ''
			]);
		}
	}

	public function register_option(){
		return wpjam_get_term_option($this->name.'_base') ?: wpjam_register_term_option($this->name.'_base', [
			'taxonomy'		=> $this->name,
			'title'			=> '快速编辑',
			'submit_text'	=> '编辑',
			'page_title'	=> '编辑'.$this->title,
			'fields'		=> [$this, 'get_fields'],
			'list_table'	=> $this->show_ui,
			'action_name'	=> 'set',
			'order'			=> 99,
		]);
	}

	public function registered(){
		add_action('registered_taxonomy_'.$this->name, function($name, $object_type, $args){
			$struct	= $this->permastruct;

			if($struct == '%'.$name.'%'){
				wpjam('no_base_taxonomy[]', $name);
			}elseif($struct){
				if(str_contains($struct, '%term_id%')){
					remove_rewrite_tag('%'.$name.'%');

					add_filter($name.'_rewrite_rules', fn($rules)=> wpjam_map($rules, fn($v)=> str_replace('?term_id=', '?taxonomy='.$name.'&term_id=', $v)));
				}

				add_permastruct($name, $struct, $args['rewrite']);
			}

			wpjam_call($this->registered_callback, $name, $object_type, $args);
		}, 10, 3);

		$this->_jam && wpjam_init(function(){
			is_admin() && $this->show_ui && add_filter('taxonomy_labels_'.$this->name,	function($labels){
				$labels		= (array)$labels;
				$name		= $labels['name'];
				$search		= $this->hierarchical ? ['分类', 'categories', 'Categories', 'Category'] : ['标签', 'Tag', 'tag'];
				$replace	= $this->hierarchical ? [$name, $name.'s', ucfirst($name).'s', ucfirst($name)] : [$name, ucfirst($name), $name];
				$labels		= wpjam_map($labels, fn($label)=> ($label && $label != $name) ? str_replace($search, $replace, $label) : $label);

				return array_merge($labels, (array)($this->labels ?: []));
			});

			register_taxonomy($this->name, $this->object_type, $this->get_args());

			wpjam_map($this->options ?:[], fn($option, $name)=> wpjam_register_term_option($name, $option+['taxonomy'=>$this->name]));
		});
	}

	public static function filter_register_args($args, $name, $object_type){
		if(!did_action('init') && !empty($args['_builtin'])){
			return $args;
		}

		$args	= compact('object_type')+$args;
		$object	= self::get($name);
		$object	= $object ? $object->update_args($args) : self::register($name, $args);

		return $object->to_array();
	}

	public static function add_hooks(){
		wpjam_init(fn()=> add_rewrite_tag('%term_id%', '([0-9]+)', 'term_id='));

		add_filter('pre_term_link',	fn($link, $term)=> in_array($term->taxonomy, wpjam('no_base_taxonomy[]')) ? '%'.$term->taxonomy.'%' : str_replace('%term_id%', $term->term_id, $link), 1, 2);

		!is_admin() && add_filter('request', function($vars){
			$structure	= get_option('permalink_structure');
			$request	= $GLOBALS['wp']->request;

			if(!$structure || !$request || isset($vars['module']) || !wpjam('no_base_taxonomy[]')){
				return $vars;
			}

			if(preg_match("#(.?.+?)/page/?([0-9]{1,})/?$#", $request, $matches)){
				$request	= $matches[1];
				$paged		= $matches[2];
			}

			if($GLOBALS['wp_rewrite']->use_verbose_page_rules){
				if(!empty($vars['error']) && $vars['error'] == '404'){
					$key	= 'error';
				}elseif(str_starts_with($structure, '/%postname%')){
					if(!empty($vars['name'])){
						$key	= 'name';
					}
				}elseif(!str_contains($request, '/')){
					$k	= array_find(['author', 'category'], fn($k)=> str_starts_with($structure, '/%'.$k.'%'));

					if($k && !str_starts_with($request, $k.'/') && !empty($vars[$k.'_name'])){
						$key	= [$k.'_name', 'name'];
					}
				}
			}elseif(!empty($vars['pagename']) && !isset($_GET['page_id']) && !isset($_GET['pagename'])){
				$key	= 'pagename';
			}

			if(!empty($key)){
				foreach(wpjam('no_base_taxonomy[]') as $tax){
					$name	= is_taxonomy_hierarchical($tax) ? wp_basename($request) : $request;

					if(array_find(wpjam_get_all_terms($tax), fn($term)=> $term->slug == $name)){
						$vars	= wpjam_except($vars, $key);

						if($tax == 'category'){
							$vars['category_name']	= $name;
						}else{
							$vars['taxonomy']	= $tax;
							$vars['term']		= $name;
						}

						if(!empty($paged)){
							$vars['paged']	= $paged;
						}

						break;
					}
				}
			}

			return $vars;
		});
	}
}