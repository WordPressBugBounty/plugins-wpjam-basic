<?php
class WPJAM_Term{
	use WPJAM_Instance_Trait;

	protected $id;

	protected function __construct($id){
		$this->id	= (int)$id;
	}

	public function __get($key){
		if(in_array($key, ['id', 'term_id'])){
			return $this->id;
		}elseif($key == 'tax_object'){
			return wpjam_get_taxonomy_object($this->taxonomy);
		}elseif($key == 'ancestors'){
			return get_ancestors($this->id, $this->taxonomy, 'taxonomy');
		}elseif($key == 'children'){
			return get_term_children($this->id, $this->taxonomy);
		}elseif($key == 'object_type'){
			return $this->get_tax_setting($key) ?: [];
		}elseif($key == 'level'){
			return $this->parent ? count($this->ancestors) : 0;
		}elseif($key == 'depth'){
			return $this->children ? array_reduce($this->children, fn($max, $child)=> max($max, count(get_ancestors($child, $this->taxonomy, 'taxonomy'))), 0) - $this->level : 0;
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

	public function value_callback($field){
		return $this->term->$field ?? $this->meta_get($field);
	}

	public function update_callback($data, $defaults){
		$term_data	= wpjam_pull($data, self::get_field_keys());
		$result		= $term_data ? $this->save($term_data) : true;

		if(!is_wp_error($result) && $data){
			return $this->meta_input($data, wpjam_except($defaults, self::get_field_keys()));
		}

		return $result;
	}

	public function get_tax_setting($key){
		return $this->tax_object ? $this->tax_object->$key : null;
	}

	public function supports($feature){
		return taxonomy_supports($this->taxonomy, $feature);
	}

	public function save($data){
		return self::update($this->id, $data, false);
	}

	public function is_object_in($object_id){
		return is_object_in_term($object_id, $this->taxonomy, $this->id);
	}

	public function set_object($object_id, $append=false){
		return wp_set_object_terms($object_id, [$this->id], $this->taxonomy, $append);
	}

	public function add_object($object_id){
		return wp_add_object_terms($object_id, [$this->id], $this->taxonomy);
	}

	public function remove_object($object_id){
		return wp_remove_object_terms($object_id, [$this->id], $this->taxonomy);
	}

	public function get_object_type(){
		return $this->object_type;
	}

	public function get_thumbnail_url($size='full', $crop=1){
		$thumbnail	= $this->thumbnail ?: apply_filters('wpjam_term_thumbnail_url', '', $this->term);

		if($thumbnail){
			$size	= $size ?: ($this->get_tax_setting('thumbnail_size') ?: 'thumbnail');

			return wpjam_get_thumbnail($thumbnail, $size, $crop);
		}

		return '';
	}

	public function parse_for_json($args=[]){
		$json['id']				= $this->id;
		$json['taxonomy']		= $this->taxonomy;
		$json['name']			= html_entity_decode($this->name);
		$json['count']			= (int)$this->count;
		$json['description']	= $this->description;

		if(is_taxonomy_viewable($this->taxonomy)){
			$json['slug']	= $this->slug;
		}

		if(is_taxonomy_hierarchical($this->taxonomy)){
			$json['parent']	= $this->parent;
		}

		foreach(wpjam_get_term_options($this->taxonomy) as $option){
			$json	= array_merge($json, $option->prepare($this->id));
		}

		return apply_filters('wpjam_term_json', $json, $this->id);
	}

	public function meta_get($key){
		return wpjam_get_metadata('term', $this->id, $key, null);
	}

	public function meta_exists($key){
		return metadata_exists('term', $this->id, $key);
	}

	public function meta_input(...$args){
		if($args){
			return wpjam_update_metadata('term', $this->id, ...$args);
		}
	}

	public static function get_instance($term, $taxonomy=null, $wp_error=false){
		$term	= self::validate($term, $taxonomy);

		if(is_wp_error($term)){
			return $wp_error ? $term : null;
		}

		$model	= wpjam_get_taxonomy_setting($term->taxonomy, 'model') ?: 'WPJAM_Term';

		return self::instance($term->term_id, fn($id)=> new $model($id));
	}

	public static function get($term){
		$data	= $term ? self::get_term($term, '', ARRAY_A) : [];

		if($data && !is_wp_error($data)){
			$data['id']	= $data['term_id'];
		}

		return $data;
	}

	protected static function get_field_keys(){
		return ['name', 'parent', 'slug', 'description', 'alias_of'];
	}

	public static function insert($data){
		$result	= static::validate_data($data);

		if(is_wp_error($result)){
			return $result;
		}

		if(isset($data['taxonomy'])){
			$taxonomy	= $data['taxonomy'];

			if(!taxonomy_exists($taxonomy)){
				return new WP_Error('invalid_taxonomy');
			}
		}else{
			$taxonomy	= self::get_current_taxonomy();
		}

		$data		= static::sanitize_data($data);
		$meta_input	= wpjam_pull($data, 'meta_input');
		$name		= wpjam_pull($data, 'name');
		$args		= wpjam_slice($data, self::get_field_keys());
		$result		= wp_insert_term(wp_slash($name), $taxonomy, wp_slash($args));

		if(!is_wp_error($result)){
			if($meta_input){
				wpjam_update_metadata('term', $result['term_id'], $meta_input);
			}

			return $result['term_id'];
		}

		return $result;
	}

	public static function update($term_id, $data, $validate=true){
		if($validate){
			$term	= self::validate($term_id);

			if(is_wp_error($term)){
				return $term;
			}
		}

		$result	= static::validate_data($data, $term_id);

		if(is_wp_error($result)){
			return $result;
		}

		$taxonomy	= $data['taxonomy'] ?? get_term_field('taxonomy', $term_id);
		$data		= static::sanitize_data($data);
		$meta_input	= wpjam_pull($data, 'meta_input');
		$args		= wpjam_slice($data, self::get_field_keys());
		$result		= $args ? wp_update_term($term_id, $taxonomy, wp_slash($args)) : true;

		if(!is_wp_error($result) && $meta_input){
			wpjam_update_metadata('term', $term_id, $meta_input);
		}

		return $result;
	}

	public static function delete($term_id){
		try{
			static::before_delete($term_id);

			return wp_delete_term($term_id, get_term_field('taxonomy', $term_id));
		}catch(Exception $e){
			return wpjam_catch($e);
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
		$term_ids	= array_filter(wp_parse_id_list($term_ids));

		if(!$term_ids){
			return [];
		}

		_prime_term_caches($term_ids);

		return array_filter(wp_cache_get_multiple($term_ids, 'terms'));
	}

	public static function get_term($term, $taxonomy='', $output=OBJECT, $filter='raw'){
		if($term && is_numeric($term)){
			$found	= false;
			$cache	= wp_cache_get($term, 'terms', false, $found);

			if($found){
				if(is_wp_error($cache)){
					return $cache;
				}elseif(!$cache){
					return null;
				}
			}else{
				$_term	= WP_Term::get_instance($term, $taxonomy);

				if(is_wp_error($_term)){
					return $_term;
				}elseif(!$_term){	// 不存在情况下的缓存优化，防止重复 SQL 查询。
					wp_cache_add($term, false, 'terms', 10);
					return null;
				}
			}
		}

		return get_term($term, $taxonomy, $output, $filter);
	}

	public static function get_current_taxonomy(){
		$object	= WPJAM_Taxonomy::get(get_called_class(), 'model', 'WPJAM_Term');

		return $object ? $object->name : null;
	}

	public static function get_path($args, $item=[]){
		$id	= is_array($args) ? (int)array_get($args, wpjam_get_taxonomy_setting($item['taxonomy'], 'query_key')) : $args;

		if(!$id){
			return new WP_Error('invalid_id', [wpjam_get_taxonomy_setting($item['taxonomy'], 'title')]);
		}

		return $item['platform'] == 'template' ? get_term_link($id) : str_replace('%term_id%', $id, $item['path']);
	}

	public static function get_path_fields($args){
		$query_key	= wpjam_get_taxonomy_setting($args['taxonomy'], 'query_key');

		return $query_key ? [$query_key=> self::get_field(['taxonomy'=>$args['taxonomy'], 'required'=>true])] : [];
	}

	public static function get_field($args=[]){
		$object	= isset($args['taxonomy']) && is_string($args['taxonomy']) ? wpjam_get_taxonomy_object($args['taxonomy']) : null;
		$type	= $args['type'] ?? '';
		$title	= $args['title'] ??= $object ? $object->title : null;
		$args	= array_merge($args, ['data_type'=>'taxonomy', 'show_in_rest'=>['type'=>'integer']]);

		if($object && ($object->hierarchical || ($type == 'select' || $type == 'mu-select'))){
			if(is_admin() && !$type && $object->levels > 1 && $object->selectable){
				return array_merge($args, [
					'type'		=> 'fields',
					'fields'	=> wpjam_array(range(0, $object->levels-1), fn($k, $v)=> ['level_'.$v, ['type'=>'number']]),
					'render'	=> function($args){
						$tax	= $this->taxonomy;
						$values	= $this->value ? array_reverse([$this->value, ...get_ancestors($this->value, $tax, 'taxonomy')]) : [];
						$terms	= get_terms(['taxonomy'=>$tax, 'hide_empty'=>0]);
						$fields	= $this->fields;
						$parent	= 0;

						$option_all	= WPJAM_Term::parse_option_all($this);

						for($level=0; $level < count($fields); $level++){
							$options	= is_null($parent) ? [] : array_column(wp_list_filter($terms, ['parent'=>$parent]), 'name', 'term_id');
							$value		= $values[$level] ?? 0;
							$parent		= $value ?: null;

							$fields['level_'.$level]	= array_merge(
								$fields['level_'.$level],
								['type'=>'select', 'data_type'=>'taxonomy', 'taxonomy'=>$tax, 'value'=>$value, 'options'=>$option_all+$options],
								($level > 0 ? ['show_if'=>['level_'.($level-1), '!=', 0, ['query_arg'=>'parent']]] : [])
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
				$option_all	= self::parse_option_all($args);

				return array_merge($args, [
					'type'		=> $type,
					'options'	=> fn()=> ($option_all+array_column(wpjam_get_terms(['taxonomy'=>$this->taxonomy, 'hide_empty'=>0, 'format'=>'flat', 'parse'=>false]), 'name', 'term_id'))
				]);
			}
		}

		return $args+['type'=>'text', 'class'=>'all-options', 'placeholder'=>'请输入'.$title.'ID或者输入关键字筛选'];
	}

	public static function parse_option_all($args){
		$option_all	= wpjam_pull($args, 'option_all', true);
		$option_all	= is_bool($option_all) ? ($option_all ? '请选择': []) : $option_all;

		return is_array($option_all) ? $option_all : [''=>$option_all];
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

	public static function validate($term_id, $taxonomy=null){
		$term	= self::get_term($term_id);

		if(is_wp_error($term)){
			return $term;
		}elseif(!$term || !($term instanceof WP_Term)){
			return new WP_Error('invalid_term');
		}

		if(!taxonomy_exists($term->taxonomy)){
			return new WP_Error('invalid_taxonomy');
		}

		$taxonomy	??= self::get_current_taxonomy();

		if($taxonomy && $taxonomy !== 'any' && !in_array($term->taxonomy, (array)$taxonomy)){
			return new WP_Error('invalid_taxonomy');
		}

		return $term;
	}

	public static function validate_by_field($value, $field){
		$taxonomy	= $field->taxonomy;

		if(is_array($value)){
			$object	= wpjam_get_taxonomy_object($taxonomy);
			$levels	= $object ? $object->levels : 0;
			$prev	= 0;

			for($level=0; $level < $levels; $level++){
				$_value	= $value['level_'.$level] ?? 0;

				if(!$_value){
					return $prev;
				}

				$prev	= $_value;
			}

			return $prev;
		}elseif(is_numeric($value)){
			if(get_term($value, $taxonomy)){
				return (int)$value;
			}
		}else{
			$result	= term_exists($value, $taxonomy);

			if($result){
				return is_array($result) ? $result['term_id'] : $result;
			}elseif($field->creatable){
				return WPJAM_Term::insert(['name'=>$value, 'taxonomy'=>$taxonomy]);
			}
		}

		return new WP_Error('invalid_term_id', [$field->_title]);
	}

	public static function filter_fields($fields, $id){
		if($id && !is_array($id)){
			$object	= self::get_instance($id);

			if($object && $object->tax_object){
				$fields	= array_merge(['name'=>[
					'title'	=> $object->tax_object->title,
					'type'	=> 'view',
					'value'	=> $object->name
				]], $fields);
			}
		}

		return $fields;
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
			$args	= ['taxonomy'=>$this->name, 'hide_empty'=>false];
			$args	+= $this->levels > 1 ? ['parent'=>0] : [];

			return wp_count_terms($args) <= 30;
		}elseif($key != 'name' && property_exists('WP_Taxonomy', $key)){
			$object	= get_taxonomy($this->name);

			if($object){
				return $object->$key;
			}
		}

		$value	= parent::__get($key);

		if($key == 'model'){
			if(!$value || !class_exists($value) || !is_subclass_of($value, 'WPJAM_Term')){
				return 'WPJAM_Term';
			}
		}elseif($key == 'filterable'){
			if(is_null($value)){
				return $this->name == 'category';
			}
		}elseif($key == 'permastruct'){
			$value	??= $this->call_model('get_'.$key);
			$value	= $value ? trim($value, '/') : $value;
		}

		return $value;
	}

	public function __set($key, $value){
		if($key != 'name' && property_exists('WP_Taxonomy', $key)){
			$object	= get_taxonomy($this->name);

			if($object){
				$object->$key = $value;
			}
		}

		parent::__set($key, $value);
	}

	protected function preprocess_args($args){
		$args	= parent::preprocess_args($args);
		$args	+= ['_jam'=>true];

		if($args['_jam']){
			$args += [
				'rewrite'			=> true,
				'show_ui'			=> true,
				'show_in_nav_menus'	=> false,
				'show_admin_column'	=> true,
				'hierarchical'		=> true,
			];
		}

		$supports			= wpjam_pull($args, 'supports', ['slug', 'description', 'parent']) ?: [];
		$args['supports']	= wpjam_array($supports, fn($k, $v)=> is_numeric($k) ? [$v, true] : [$k, $v]);

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

			if(empty($args['plural'])){
				$args['plural']	= $this->name.'s';
			}
		}

		return $args;
	}

	public function to_array(){
		$this->filter_args();

		if(doing_filter('register_taxonomy_args')){
			if($this->permastruct){
				$this->permastruct	= str_replace('%term_id%', '%'.$this->query_key.'%', $this->permastruct);

				if(strpos($this->permastruct, '%'.$this->query_key.'%')){
					$this->remove_support('slug');

					$this->query_var	??= false;
				}

				$this->rewrite	= $this->rewrite ?: true;
			}

			if($this->levels == 1){
				$this->remove_support('parent');
			}else{
				if(!$this->supports('parent')){
					$this->add_support('parent');
				}
			}

			if($this->rewrite && $this->_jam){
				$this->rewrite	= is_array($this->rewrite) ? $this->rewrite : [];
				$this->rewrite	= $this->rewrite+['with_front'=>false, 'hierarchical'=>false];
			}
		}

		return $this->args;
	}

	public function get_fields($id=0, $action_key=''){
		$fields	= [];

		if($action_key == 'set'){
			$fields['name']	= ['title'=>'名称',	'type'=>'text',	'class'=>'',	'required'];

			if($this->supports('slug')){
				$fields['slug']	= ['title'=>'别名',	'type'=>'text',	'class'=>'',	'required'];
			}

			if($this->hierarchical && $this->levels !== 1 && $this->supports('parent')){
				$args	= ['taxonomy'=>$this->name, 'hide_empty'=>0, 'format'=>'flat'];
				$depth	= null;

				if($this->levels > 1){
					$depth	= $this->levels - 1 - wpjam_term($id)->depth;

					if(!$depth){
						$args['parent']	= -1;
						$depth			= null;
					}
				}

				$terms		= wpjam_get_terms($args, $depth);
				$options	= $terms ? array_column($terms, 'name', 'id') : [];

				$fields['parent']	= ['title'=>'父级',	'type'=>'select',	'options'=> ['-1'=>'无']+$options];
			}

			if($this->supports('description')){
				$fields['description']	= ['title'=>'描述',	'type'=>'textarea'];
			}
		}

		if($this->supports('thumbnail')){
			$fields['thumbnail']	= [
				'title'		=> '缩略图',
				'type'		=> $this->thumbnail_type == 'image' ? 'image' : 'img',
				'item_type'	=> $this->thumbnail_type == 'image' ? 'image' : 'url',
				'size'		=> $this->thumbnail_size
			];
		}

		if($this->supports('banner')){
			$fields['banner']	= [
				'title'			=> '大图',
				'type'			=> 'img',
				'item_type'		=> 'url',
				'size'			=> $this->banner_size,
				'description'	=> $this->banner_size ? '尺寸：'.$this->banner_size : '',
				'show_if'		=> [
					'key'		=> 'parent', 
					'value'		=> -1,
					'external'	=> $action_key != 'set'
				],
			];
		}

		return array_merge($fields, $this->get_items('_fields'));
	}

	public function register_option($list_table=false){
		if(!wpjam_get_term_option($this->name.'_base')){
			wpjam_register_term_option($this->name.'_base', [
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
	}

	public function is_object_in($object_type){
		return is_object_in_taxonomy($object_type, $this->name);
	}

	public function is_viewable(){
		return is_taxonomy_viewable($this->name);
	}

	public function add_support($feature, $value=true){
		return $this->add_item($feature, $value, 'supports');
	}

	public function remove_support($feature){
		return $this->delete_item($feature, 'supports');
	}

	public function supports($feature){
		return (bool)$this->get_item($feature, 'supports');
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
				'placeholder'	=> '请输入'.$this->title,
				'title'			=> '',
				'class'			=> ''
			]);
		}
	}

	public function registered_callback($name, $object_type, $args){
		if($this->name == $name){
			if($this->permastruct){
				if(strpos($this->permastruct, '%'.$this->query_key.'%')){
					add_rewrite_tag('%'.$this->query_key.'%', '([^/]+)', 'taxonomy='.$name.'&term_id=');

					remove_rewrite_tag('%'.$name.'%');
				}

				if($this->permastruct == '%'.$name.'%'){
					if(!is_admin()){
						add_filter('request',	[$this, 'filter_request']);
					}
				}else{
					add_permastruct($name, $this->permastruct, $args['rewrite']);
				}

				add_filter('pre_term_link',	[$this, 'filter_link'], 1, 2);
			}

			$callback	= $this->registered_callback;

			if($callback && is_callable($callback)){
				$callback($name, $object);
			}
		}
	}

	public function filter_labels($labels){
		$labels	= (array)$labels;
		$name	= $labels['name'];

		if($this->hierarchical){
			$search		= ['分类', 'categories', 'Categories', 'Category'];
			$replace	= [$name, $name.'s', ucfirst($name).'s', ucfirst($name)];
		}else{
			$search		= ['标签', 'Tag', 'tag'];
			$replace	= [$name, ucfirst($name), $name];
		}

		foreach($labels as $key => &$label){
			if($label && $label != $name){
				$label	= str_replace($search, $replace, $label);
			}
		}

		return array_merge($labels, (array)($this->labels ?: []));
	}

	public function filter_request($query_vars){
		$structure	= get_option('permalink_structure');
		$request	= $GLOBALS['wp']->request;
		
		if($structure && $request && ($this->hierarchical || !str_contains($request, '/')) && !isset($query_vars['module'])){
			if($GLOBALS['wp_rewrite']->use_verbose_page_rules){
				if(!empty($query_vars['error']) && $query_vars['error'] == '404'){
					$key	= 'error';

					if(preg_match("#(.?.+?)/page/?([0-9]{1,})/?$#", $request, $matches)){
						$request	= $matches[1];
						$query_vars	+= ['paged'=>$matches[2]];
					}
				}elseif(str_starts_with($structure, '/%postname%')){
					if(!empty($query_vars['name'])){
						$key	= 'name';
					}
				}elseif(str_starts_with($structure, '/%author%')){
					if(!str_starts_with($request, 'author/') && !empty($query_vars['author_name'])){
						$key	= ['author_name', 'name'];
					}
				}elseif(str_starts_with($structure, '/%category%')){
					if($this->name != 'category' && !str_starts_with($request, 'category/') && !empty($query_vars['category_name'])){
						$key	= ['category_name', 'name'];
					}
				}
			}else{
				if(!empty($query_vars['pagename']) && !isset($_GET['page_id']) && !isset($_GET['pagename'])){
					$key	= 'pagename';
				}
			}

			if(!empty($key)){
				$k		= is_array($key) ? reset($key) : $key;
				$name	= $k == 'error' ? $request : ($query_vars[$k] ?? $request);
				$name	= $this->hierarchical ? wp_basename($name) : $request;
				$terms	= wpjam_get_all_terms($this->name);

				if($terms && in_array($name, array_column($terms, 'slug'))){
					$query_vars	= wpjam_except($query_vars, $key);

					if($this->name == 'category'){
						$query_vars['category_name']	= $name;
					}else{
						$query_vars['taxonomy']	= $this->name;
						$query_vars['term']		= $name;
					}
				}
			}
		}

		return $query_vars;
	}

	public function filter_link($term_link, $term){
		if($term->taxonomy == $this->name){
			if(strpos($this->permastruct, '%'.$this->query_key.'%')){
				$term_link	= str_replace('%'.$this->query_key.'%', $term->term_id, $term_link);
			}elseif($this->permastruct == '%'.$this->name.'%'){
				$term_link	= $this->permastruct;
			}
		}

		return $term_link;
	}

	public function registered(){
		add_action('registered_taxonomy_'.$this->name,	[$this, 'registered_callback'], 10, 3);

		if($this->_jam){
			if(is_admin() && $this->show_ui){
				add_filter('taxonomy_labels_'.$this->name,	[$this, 'filter_labels']);
			}

			wpjam_load('init', fn()=> register_taxonomy($this->name, $this->object_type, $this->to_array()));

			if($this->options){
				wpjam_map($this->options, fn($option, $name)=> wpjam_register_term_option($name, $option+['taxonomy'=>$this->name]));
			}
		}
	}

	public static function filter_register_args($args, $name, $object_type){
		if(did_action('init') || empty($args['_builtin'])){
			$object	= self::get($name);
			$object	= $object ? $object->update_args($args) : self::register($name, array_merge($args, ['_jam'=>false, 'object_type'=>$object_type]));

			return $object->to_array();
		}

		return $args;
	}
}

class WPJAM_Terms{
	public static function parse($args){
		$format		= wpjam_pull($args, 'format');
		$parse		= wpjam_pull($args, 'parse', true);
		$max_depth	= wpjam_pull($args, 'max_depth');

		if($max_depth != -1){
			$object	= (isset($args['taxonomy']) && is_string($args['taxonomy'])) ? wpjam_get_taxonomy_object($args['taxonomy']) : null;

			if($object && $object->hierarchical){
				$max_depth	??= (int)$object->levels;
				$parent		= (int)wpjam_pull($args, 'parent');

				if(!get_term($parent)){
					return [];
				}
			}else{
				$max_depth	= -1;
			}
		}

		$terms	= get_terms($args+['hide_empty'=>false]) ?: [];

		if(is_wp_error($terms) || empty($terms)){
			return $terms;
		}

		if($max_depth != -1){
			$terms	= wpjam_group($terms, 'parent');

			if($parent){
				$terms[0]	= [get_term($parent)];
			}

			return self::parse_hierarchical($terms, 0, 0, compact('parse', 'max_depth', 'format'));
		}else{
			return $parse ? array_map('wpjam_get_term', $terms) : $terms;
		}
	}

	protected static function parse_hierarchical(&$hierarchical, $parent, $depth, $args){
		$terms	= wpjam_pull($hierarchical, $parent) ?: [];
		$parsed	= [];

		foreach($terms as $term){
			$term	= $args['parse'] ? wpjam_get_term($term) : $term;

			if(!$args['max_depth'] || $args['max_depth'] > $depth+1){
				$parent 	= $args['parse'] ? $term['id'] : $term->term_id;
				$children	= self::parse_hierarchical($hierarchical, $parent, $depth+1, $args);
			}else{
				$children	= [];
			}

			if($args['format'] == 'flat'){
				$space	= str_repeat('&emsp;', $depth);

				if($args['parse']){
					$term['name']	= $space.$term['name'];
				}else{
					$term->name		= $space.$term->name;
				}

				$parsed		= array_merge($parsed, [$term], $children);
			}else{
				if($args['parse']){
					$term	= array_merge($term, ['children'=>$children]);
				}else{
					$term->children	= $children;
				}

				$parsed[]	= $term;
			}
		}

		return $parsed;
	}

	public static function parse_json_module($args){
		$tax_object	= wpjam_get_taxonomy_object(array_get($args, 'taxonomy'));

		if(!$tax_object){
			wp_die('invalid_taxonomy');
		}

		$mapping	= wpjam_pull($args, 'mapping');
		$mapping	= $mapping ? array_filter(array_map('wpjam_get_parameter', wp_parse_args($mapping)), fn($v)=> isset($v)) : [];
		$args		= array_merge($args, $mapping);
		$number		= (int)wpjam_pull($args, 'number');
		$output		= wpjam_pull($args, 'output');
		$output		= $output ?: $tax_object->plural;
		$terms		= self::parse($args);

		if($terms && $number){
			$paged	= wpjam_pull($args, 'paged') ?: 1;
			$offset	= $number * ($paged-1);

			$terms_json['current_page']	= (int)$paged;
			$terms_json['total_pages']	= ceil(count($terms)/$number);
			$terms = array_slice($terms, $offset, $number);
		}

		$terms	= $terms ? array_values($terms) : [];

		return [$output	=> $terms];
	}

	public static function cleanup(){
		// term_relationships 的 object_id 可能不是 post_id 
		// 如果要清理需要具体业务逻辑的时候，进行清理。

		$wpdb		= $GLOBALS['wpdb'];
		$results	= $wpdb->get_results("SELECT tr.* FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_taxonomy_id is NULL;");

		if($results){
			$wpdb->query("DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_taxonomy_id is NULL;");
		}

		return $results;
	}
}