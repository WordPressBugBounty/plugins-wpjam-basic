<?php
class WPJAM_Post{
	use WPJAM_Instance_Trait;

	protected $id;

	protected function __construct($id){
		$this->id	= (int)$id;
	}

	public function __get($key){
		if(in_array($key, ['id', 'post_id'])){
			return $this->id;
		}elseif($key == 'views'){
			return (int)get_post_meta($this->id, 'views', true);
		}elseif($key == 'permalink'){
			return get_permalink($this->id);
		}elseif($key == 'ancestors'){
			return get_post_ancestors($this->id);
		}elseif($key == 'children'){
			return get_children($this->id);
		}elseif($key == 'viewable'){
			return is_post_publicly_viewable($this->id);
		}elseif($key == 'format'){
			return get_post_format($this->id) ?: '';
		}elseif($key == 'taxonomies'){
			return get_object_taxonomies($this->post);
		}elseif($key == 'type_object'){
			return wpjam_get_post_type_object($this->post_type);
		}elseif($key == 'icon'){
			return (string)$this->get_type_setting('icon');
		}elseif($key == 'thumbnail'){
			return $this->supports('thumbnail') ? get_the_post_thumbnail_url($this->id, 'full') : '';
		}elseif($key == 'images'){
			return $this->supports('images') ? array_values(get_post_meta($this->id, 'images', true) ?: []) : [];
		}elseif($key == 'post'){
			return get_post($this->id);
		}else{
			$_post	= get_post($this->id);

			if($key == 'data'){
				return $_post->to_array();
			}elseif(!str_starts_with($key, 'post_') && isset($_post->{'post_'.$key})){
				return $_post->{'post_'.$key};
			}else{
				return $_post->$key ?? $this->meta_get($key);
			}
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function __call($method, $args){
		if(in_array($method, ['get_content', 'get_excerpt', 'get_first_image_url'])){
			$function	= 'wpjam_get_post_'.wpjam_remove_prefix($method, 'get_');

			return $function($this->post, ...$args);
		}elseif(in_array($method, ['get_thumbnail_url', 'get_images'])){
			$method	= str_replace('get_', 'parse_', $method);

			return $this->$method(...$args);
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function get_type_setting($key){
		return $this->type_object ? $this->type_object->$key : null;
	}

	public function supports($feature){
		return post_type_supports($this->post_type, $feature);
	}

	public function save($data){
		if(wpjam_some(['post_status', 'status'], fn($k)=> array_get($data, $k) == 'publish')){
			$result	= $this->is_publishable();

			if(is_wp_error($result) || !$result){
				return $result ?: new WP_Error('unpublishable', '不可发布');
			}
		}

		return self::update($this->id, $data, false);
	}

	public function set_status($status){
		return $this->save(['post_status'=>$status]);
	}

	public function publish(){
		return $this->set_status('publish');
	}

	public function unpublish(){
		return $this->set_status('draft');
	}

	public function is_publishable(){
		return true;
	}

	public function get_unserialized(){
		return wpjam_unserialize($this->content, fn($fixed)=> $this->save(['content'=>$fixed])) ?: [];
	}

	public function get_terms($taxonomy='post_tag'){
		return get_the_terms($this->id, $taxonomy) ?: [];
	}

	public function set_terms($terms='', $taxonomy='post_tag', $append=false){
		return wp_set_post_terms($this->id, $terms, $taxonomy, $append);
	}

	public function in_term($taxonomy, $terms=null){
		return is_object_in_term($this->id, $taxonomy, $terms);
	}

	public function in_taxonomy($taxonomy){
		return is_object_in_taxonomy($this->post, $taxonomy);
	}

	public function parse_thumbnail_url($size='thumbnail', $crop=1){
		$thumbnail	= $this->thumbnail ?: ($this->images ? $this->images[0] : apply_filters('wpjam_post_thumbnail_url', '', $this->post));

		return $thumbnail ? wpjam_get_thumbnail($thumbnail, ($size ?: ($this->get_type_setting('thumbnail_size') ?: 'thumbnail')), $crop) : '';
	}

	public function parse_images($large_size='', $thumbnail_size='', $full_size=true){
		if(!$this->images){
			return [];
		}

		$get_size	= function($key){
			$setting	= $this->get_type_setting('images_sizes');

			if($setting){
				if($key == 'large'){
					return $setting[0];
				}

				if(count($this->images) == 1){
					$query	= wpjam_parse_image_query($this->images[0]);

					if(!$query){
						$query	= wpjam_get_image_size($this->images[0], 'url') ?: ['width'=>0, 'height'=>0];

						update_post_meta($this->id, 'images', [add_query_arg($query, $this->images[0])]);
					}

					if(!empty($query['orientation'])){
						$i	= ['landscape'=>2, 'portrait'=>3][$query['orientation']] ?? 1;

						return $setting[$i] ?? $setting[1];
					}
				}

				return $setting[1];
			}

			return $this->get_type_setting($key.'_size') ?: $key;
		};

		$sizes	= wpjam_array(['large'=>$large_size, 'thumbnail'=>$thumbnail_size], fn($k, $v)=> $v === false ? null : [$k, $v ?: $get_size($k)]);

		foreach($this->images as $image){
			if(!$sizes || $full_size){
				$sizes['full']	= 'full';
			}

			$parsed	= array_map(fn($s)=> wpjam_get_thumbnail($image, $s), $sizes);
			$query	= wpjam_parse_image_query($image);

			if($query){
				$parsed	= array_merge($parsed, ['width'=>0, 'height'=>0], wpjam_slice($query, ['orientation', 'width', 'height']));
			}

			if(isset($sizes['thumbnail'])){
				$size	= wpjam_parse_size($sizes['thumbnail']);
				$parsed	= array_merge($parsed, wpjam_array(['width', 'height'], fn($k, $v)=> ['thumbnail_'.$v, $size[$v] ?? 0]));
			}

			$images[]	= count($sizes) == 1 ? reset($parsed) : $parsed;
		}

		return $images;
	}

	public function parse_for_json($args=[]){
		$args	+= [
			'list_query'		=> false,
			'content_required'	=> false,
			'raw_content'		=> false,
			'suppress_filter'	=> false,
		];

		$json	= wpjam_fill(['id', 'type', 'post_type', 'status', 'views', 'icon'], fn($field)=> $this->$field);
		$json	+= wpjam_fill(['title', 'excerpt'],	fn($field)=> $this->supports($field) ? html_entity_decode(call_user_func('get_the_'.$field, $this->id)) : '');
		$fields	= ['title', 'excerpt', 'thumbnail', 'images', 'author', 'date', 'modified', 'password', 'name', 'menu_order', 'format'];
		$json	= array_reduce($fields, fn($carry, $field)=> array_merge($carry, $this->parse_field($field, $args)), $json);

		if($args['list_query']){
			return $json;
		}

		$json	= array_reduce(wpjam_get_post_options($this->type), fn($carry, $option)=> array_merge($carry, $option->prepare($this->id)), $json);
		$json	+= wpjam_fill(array_filter($this->taxonomies, fn($tax)=> $tax != 'post_format' && is_taxonomy_viewable($tax)), fn($tax)=> array_map('wpjam_get_term', $this->get_terms($tax)));
		$json	+= $this->parse_field('content', $args);

		return $args['suppress_filter'] ? $json : apply_filters('wpjam_post_json', $json, $this->id, $args);
	}

	protected function parse_field($field, $args=[]){
		if($field == 'name'){
			return $this->viewable ? [
				'name'		=> urldecode($this->name),
				'post_url'	=> str_replace(home_url(), '', $this->permalink)
			] : [];
		}elseif($field == 'thumbnail'){
			$size	= $args['thumbnail_size'] ?? ($args['size'] ?? null);

			return [$field=> $this->get_thumbnail_url($size)];
		}elseif($field == 'images'){
			return $this->supports($field) ? [$field=> $this->parse_images()] : [];
		}elseif($field == 'author'){
			$parsed	= ['user_id'=> (int)$this->author];
			$parsed	+= $this->supports($field) ? ['author' => wpjam_get_user($this->author)] : [];
		}elseif($field == 'content'){
			if((is_single($this->id) || is_page($this->id) || $args['content_required'])){
				if($this->supports('editor')){
					if($args['raw_content']){
						$parsed['raw_content']	= $this->content;
					}

					$parsed['content']		= wpjam_get_post_content($this->post);
					$parsed['multipage']	= (bool)$GLOBALS['multipage'];

					if($parsed['multipage']){
						$parsed['numpages']	= $GLOBALS['numpages'];
						$parsed['page']		= $GLOBALS['page'];
					}
				}else{
					if(is_serialized($this->content)){
						$parsed['content']	= $this->get_unserialized();
					}
				}
			}
		}elseif($field == 'password'){
			return $this->password ? [
				'password_protected'	=> true,
				'password_required'		=> post_password_required($this->id),
			] : [];
		}elseif(in_array($field, ['date', 'modified'])){
			$timestamp	= get_post_timestamp($this->id, $field);
			$prefix		= $field == 'modified' ? 'modified_' : '';
			$parsed		= [
				$prefix.'timestamp'	=> $timestamp,
				$prefix.'time'		=> wpjam_human_time_diff($timestamp),
				$prefix.'date'		=> wpjam_date('Y-m-d', $timestamp),
			];

			if($field == 'date' && !$args['list_query'] && is_main_query()){
				$current_posts	= $GLOBALS['wp_query']->posts;

				if($current_posts && in_array($this->id, array_column($current_posts, 'ID'))){
					if(is_new_day()){
						$GLOBALS['previousday']	= $GLOBALS['currentday'];

						$parsed['day']	= wpjam_human_date_diff($parsed['date']);
					}else{
						$parsed['day']	= '';
					}
				}
			}
		}elseif($field == 'menu_order'){
			if($this->supports('page-attributes')){
				return [$field=> (int)$this->menu_order];
			}
		}elseif($field == 'format'){
			if($this->supports('post-formats')){
				return [$field=> $this->format];
			}
		}

		return $parsed ?? [];
	}

	public function meta_get($key){
		return wpjam_get_metadata('post', $this->id, $key, null);
	}

	public function meta_exists($key){
		return metadata_exists('post', $this->id, $key);
	}

	public function meta_input(...$args){
		if($args){
			return wpjam_update_metadata('post', $this->id, ...$args);
		}
	}

	public function value_callback($field){
		if($field == 'tax_input'){
			return wpjam_fill($this->taxonomies, fn($tax)=> array_column($this->get_terms($tax), 'term_id'));
		}

		return $this->post->$field ?? $this->meta_get($field);
	}

	// update/insert 方法同时支持 title 和 post_xxx 字段写入 post 中，meta 字段只支持 meta_input
	// update_callback 方法只支持 post_xxx 字段写入 post 中，其他字段都写入 meta_input
	public function update_callback($data, $defaults){
		$current	= $this->data;
		$post_data	= wpjam_pull($data, [...array_keys($current), 'tax_input']);
		$result		= $post_data ? $this->save($post_data) : true;

		return (!is_wp_error($result) && $data) ? $this->meta_input($data, $defaults) : $result;
	}

	public static function get_instance($post=null, $post_type=null, $wp_error=false){
		$post	= $post ?: get_post();
		$post	= static::validate($post, $post_type);

		if(is_wp_error($post)){
			return $wp_error ? $post : null;
		}

		$model	= wpjam_get_post_type_setting(get_post_type($post), 'model') ?: 'WPJAM_Post';

		return self::instance($post->ID, fn($id)=> $model::create_instance($id));
	}

	public static function validate($post_id, $post_type=null){
		$post	= $post_id ? self::get_post($post_id) : null;

		if(!$post || !($post instanceof WP_Post)){
			return new WP_Error('invalid_id');
		}

		if(!post_type_exists($post->post_type)){
			return new WP_Error('invalid_post_type');
		}

		$post_type	??= static::get_current_post_type();

		if($post_type && $post_type !== 'any' && !in_array($post->post_type, (array)$post_type)){
			return new WP_Error('invalid_post_type');
		}

		return $post;
	}

	public static function validate_by_field($value, $field){
		$post_type 	= is_numeric($value) ? get_post_type($value) : null;

		if($post_type && (!$field->post_type || in_array($post_type, (array)$field->post_type))){
			return (int)$value;
		}

		return new WP_Error('invalid_post_id', [$field->_title]);
	}

	public static function get($post){
		$data	= self::get_post($post, ARRAY_A);

		if($data){
			$data['id']				= $data['ID'];
			$data['post_content']	= is_serialized($data['post_content']) ? unserialize($data['post_content']) : $data['post_content'];

			$data	+= wpjam_array($data, fn($k, $v)=> [wpjam_remove_prefix($k, 'post_'), $v]);
		}

		return $data;
	}

	public static function insert($data){
		try{
			$data	= static::prepare_data($data);
			$meta	= wpjam_pull($data, 'meta_input');

			if(isset($data['post_type'])){
				if(!post_type_exists($data['post_type'])){
					wpjam_throw('invalid_post_type');
				}
			}else{
				$data['post_type']	= static::get_current_post_type() ?: 'post';
			}

			if(empty($data['post_status'])){
				$cap	= get_post_type_object($data['post_type'])->cap->publish_posts;

				$data['post_status']	= current_user_can($cap) ? 'publish' : 'draft';
			}

			$data		+= ['post_author'=>get_current_user_id(), 'post_date'=> wpjam_date('Y-m-d H:i:s')];
			$post_id	= wp_insert_post(wp_slash($data), true, true);

			if($meta && !is_wp_error($post_id)){
				wpjam_update_metadata('post', $post_id, $meta);
			}

			return $post_id;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public static function update($post_id, $data, $validate=true){
		try{
			if($validate){
				wpjam_throw_if_error(self::validate($post_id));
			}

			$data	= static::prepare_data($data, $post_id);
			$data	= array_merge($data, ['ID'=>$post_id]);
			$meta	= wpjam_pull($data, 'meta_input');
			$result	= wp_update_post(wp_slash($data), true, true);

			if($meta && !is_wp_error($result)){
				wpjam_update_metadata('post', $post_id, $meta);
			}

			return $result;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public static function delete($post_id, $force=true){
		try{
			static::before_delete($post_id);

			return wp_delete_post($post_id, $force) ?: new WP_Error('delete_error', '删除失败');
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	protected static function sanitize_data($data, $post_id=0){
		$data	+= wpjam_array(wpjam_slice($data, ['title', 'content', 'excerpt', 'name', 'status', 'author', 'parent', 'password', 'date', 'date_gmt', 'modified', 'modified_gmt']), fn($k)=> 'post_'.$k);

		if(isset($data['post_content']) && is_array($data['post_content'])){
			$data['post_content']	= serialize($data['post_content']);
		}

		if($post_id){
			$data['ID'] = $post_id;

			if(isset($data['post_date']) && !isset($data['post_date_gmt'])){
				$current_date_gmt	= get_post($post_id)->post_date_gmt;

				if($current_date_gmt && $current_date_gmt != '0000-00-00 00:00:00'){
					$data['post_date_gmt']	= get_gmt_from_date($data['post_date']);
				}
			}
		}

		return $data;
	}

	public static function get_by_ids($post_ids){
		return array_map('get_post', self::update_caches($post_ids));
	}

	public static function update_caches($post_ids, $update_term_cache=false, $update_meta_cache=false){
		$post_ids	= array_filter(wp_parse_id_list($post_ids));

		if(!$post_ids){
			return [];
		}

		_prime_post_caches($post_ids, $update_term_cache, $update_meta_cache);

		$posts	= array_filter(wp_cache_get_multiple($post_ids, 'posts'));

		if(!$update_meta_cache){
			wpjam_lazyload('post_meta', array_keys($posts));
		}

		do_action('wpjam_update_post_caches', $posts);

		return $posts;
	}

	public static function get_post($post, $output=OBJECT, $filter='raw'){
		if($post && is_numeric($post)){	// 不存在情况下的缓存优化
			$found	= false;
			$cache	= wp_cache_get($post, 'posts', false, $found);

			if($found){
				if(is_wp_error($cache)){
					return $cache;
				}elseif(!$cache){
					return null;
				}
			}else{
				$_post	= WP_Post::get_instance($post);

				if(!$_post){	// 防止重复 SQL 查询。
					wp_cache_add($post, false, 'posts', 10);
					return null;
				}
			}
		}

		return get_post($post, $output, $filter);
	}

	public static function get_current_post_type(){
		$object	= WPJAM_Post_Type::get(get_called_class(), 'model', 'WPJAM_Post');

		return $object ? $object->name : null;
	}

	public static function get_path($args, $item=[]){
		$id	= is_array($args) ? (int)array_get($args, $item['post_type'].'_id') : $args;

		if(!$id){
			return new WP_Error('invalid_id', [wpjam_get_post_type_setting($item['post_type'], 'title')]);
		}

		return $item['platform'] == 'template' ? get_permalink($id) : str_replace('%post_id%', $id, $item['path']);
	}

	public static function get_path_fields($args){
		$post_type	= $args['post_type'];
		$object		= get_post_type_object($post_type);

		return $object ? [$post_type.'_id' => self::get_field(['post_type'=>$post_type, 'required'=>true])] : [];
	}

	public static function get_field($args){
		$post_type	= wpjam_pull($args, 'post_type');
		$title		= wpjam_pull($args, 'title');

		if(is_null($title) && $post_type && is_string($post_type)){
			$title	= wpjam_get_post_type_setting($post_type, 'title');
		}

		return $args+[
			'title'			=> $title,
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'post_type',
			'post_type'		=> $post_type,
			'placeholder'	=> '请输入'.$title.'ID或者输入关键字筛选',
			'show_in_rest'	=> ['type'=>'integer']
		];
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return wpjam_get_posts($args+[
				's'					=> $args['search'] ?? null,
				'posts_per_page'	=> $args['number'] ?? 10,
				'suppress_filters'	=> false,
			]);
		}

		$args['post_status']	= wpjam_pull($args, 'status') ?: 'any';
		$args['post_type']		??= static::get_current_post_type();
		$args['posts_per_page']	??= $args['number'] ?? 10;

		$wp_query	= $GLOBALS['wp_query'];
		$wp_query->query($args);

		return [
			'items'	=> $wp_query->posts,
			'total'	=> $wp_query->found_posts
		];
	}

	public static function query_calendar($args){
		$args['post_status']	= wpjam_pull($args, 'status') ?: 'any';
		$args['post_type']		??= static::get_current_post_type();
		$args['monthnum']		= $args['month'];
		$args['posts_per_page']	= -1;

		$wp_query	= $GLOBALS['wp_query'];
		$wp_query->query($args);

		return array_reduce($wp_query->posts, function($carry, $post){
			$carry[explode(' ', $post->post_date)[0]][]	= $post;

			return $carry;
		}, []);
	}

	public static function get_filterable_fields(){
		return ['status'];
	}

	public static function get_views(){
		if(get_current_screen()->base != 'edit'){
			$post_type	= static::get_current_post_type();
			$counts		= $post_type ? array_filter((array)wp_count_posts($post_type)) : [];

			if($counts){
				$views		= ['all'=>['filter'=>['status'=>null, 'show_sticky'=>null], 'label'=>'全部', 'count'=>array_sum($counts)]];
				$statuses	= wpjam_slice(get_post_stati(['show_in_admin_status_list'=>true], 'objects'), array_keys($counts));

				return $views+wpjam_map($statuses, fn($object, $status)=> ['filter'=>['status'=>$status], 'label'=>$object->label, 'count'=>$counts[$status]]);
			}
		}
	}

	public static function filter_fields($fields, $id){
		if($id && !is_array($id) && !isset($fields['title']) && !isset($fields['post_title'])){
			return ['title'=>['title'=>wpjam_get_post_type_setting($id, 'title').'标题', 'type'=>'view', 'value'=>get_the_title($id)]]+$fields;
		}

		return $fields;
	}

	public static function get_meta($post_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_get_metadata');
		return wpjam_get_metadata('post', $post_id, ...$args);
	}

	public static function update_meta($post_id, ...$args){
		// _deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');
		return wpjam_update_metadata('post', $post_id, ...$args);
	}

	public static function update_metas($post_id, $data, $meta_keys=[]){
		return static::update_meta($post_id, $data, $meta_keys);
	}
}

/**
* @config menu_page, admin_load, register_json, init
**/
#[config('menu_page', 'admin_load', 'register_json', 'init')]
class WPJAM_Post_Type extends WPJAM_Register{
	public function __get($key){
		if($key == 'title'){
			return $this->labels ? $this->labels->singular_name : $this->label;
		}elseif($key != 'name' && property_exists('WP_Post_Type', $key)){
			$object	= get_post_type_object($this->name);

			if($object){
				return $object->$key;
			}
		}

		$value	= parent::__get($key);

		if($key == 'model'){
			if(!$value || !class_exists($value) || !is_subclass_of($value, 'WPJAM_Post')){
				return 'WPJAM_Post';
			}
		}elseif($key == 'permastruct'){
			$value	??= $this->call_model('get_'.$key);
			$value	= $value ? trim($value, '/') : $value;
		}

		return $value;
	}

	public function __set($key, $value){
		if($key != 'name' && property_exists('WP_Post_Type', $key)){
			$object	= get_post_type_object($this->name);

			if($object){
				$object->$key = $value;
			}
		}

		parent::__set($key, $value);
	}

	protected function preprocess_args($args){
		$args	= parent::preprocess_args($args);
		$args	+= ['plural'=>$this->name.'s', '_jam'=>true];

		if($args['_jam']){
			if(!is_array(array_get($args, 'taxonomies'))){
				unset($args['taxonomies']);
			}

			return $args+[
				'public'		=> true,
				'show_ui'		=> true,
				'hierarchical'	=> false,
				'rewrite'		=> true,
				'permastruct'	=> false,
				'supports'		=> ['title'],
			];
		}

		return $args;
	}

	public function get_menu_slug(){
		if(is_null($this->menu_slug)){
			$menu_page	= $this->get_arg('menu_page');

			if($menu_page && is_array($menu_page)){
				$this->menu_slug	= wp_is_numeric_array($menu_page) ? rest($menu_page)['menu_slug'] : $menu_page['menu_slug'];
			}
		}

		return $this->menu_slug;
	}

	public function to_array(){
		$this->filter_args();

		if(doing_filter('register_post_type_args')){
			if(!$this->_builtin && $this->permastruct){
				$this->permastruct	= str_replace(
					['%post_id%', '%postname%'],
					['%'.$this->name.'_id%', '%'.$this->name.'%'],
					$this->permastruct
				);

				if(strpos($this->permastruct, '%'.$this->name.'_id%')){
					if($this->hierarchical){
						$this->permastruct	= false;
					}else{
						$this->query_var	??= false;
					}
				}

				if($this->permastruct){
					$this->rewrite	= $this->rewrite ?: true;
				}
			}

			if($this->_jam){
				if($this->hierarchical){
					$this->supports		= [...$this->supports, 'page-attributes'];
				}

				if($this->rewrite){
					$this->rewrite	= is_array($this->rewrite) ? $this->rewrite : [];
					$this->rewrite	= $this->rewrite+['with_front'=>false, 'feeds'=>false];
				}
			}

			if($this->menu_icon){
				$this->menu_icon	= wpjam_fix('add', 'prev', $this->menu_icon, 'dashicons-');
			}
		}

		return $this->args;
	}

	public function get_fields($id=0, $action_key=''){
		if(in_array($action_key, ['add', 'set'])){
			if($action_key == 'add'){
				$fields['post_type']	= ['type'=>'hidden',	'value'=>$this->name];
				$fields['post_status']	= ['type'=>'hidden',	'value'=>'draft'];
			}

			$fields['post_title']	= ['title'=>'标题',	'type'=>'text',	'required'];

			if($this->supports('excerpt')){
				$fields['post_excerpt']	= ['title'=>'摘要',	'type'=>'textarea',	'class'=>'',	'rows'=>3];
			}

			if($this->supports('thumbnail')){
				$fields['_thumbnail_id']	= ['title'=>'头图', 'type'=>'img', 'size'=>'600x0',	'name'=>'meta_input[_thumbnail_id]'];
			}
		}

		if($this->supports('images')){
			$size	= $this->images_sizes ? $this->images_sizes[0] : '';

			$fields['images']	= [
				'title'			=> '头图',
				'name'			=> 'meta_input[images]',
				'type'			=> 'mu-img',
				'item_type'		=> 'url',
				'show_in_rest'	=> false,
				'size'			=> $size,
				'max_items'		=> $this->images_max_items
			];
		}

		if($this->supports('video')){
			$fields['video']	= ['title'=>'视频',	'type'=>'url',	'name'=>'meta_input[video]'];
		}

		foreach($this->get_items('_fields') as $key => $field){
			if(in_array($action_key, ['add', 'set']) && empty($field['name']) && !property_exists('WP_Post', $key)){
				$field['name']	= 'meta_input['.$key.']';
			}

			$fields[$key]	= $field;
		}

		return $fields ?? [];
	}

	public function register_option($list_table=false){
		if(!wpjam_get_post_option($this->name.'_base')){
			$fields	= $list_table ? [$this, 'get_fields'] : $this->get_fields();

			if($fields){
				wpjam_register_post_option($this->name.'_base', [
					'post_type'		=> $this->name,
					'title'			=> '基础信息',
					'page_title'	=> '设置'.$this->title,
					'fields'		=> $fields,
					'list_table'	=> $this->show_ui,
					'action_name'	=> 'set',
					'row_action'	=> false,
					'order'			=> 99,
				]);
			}
		}
	}

	public function get_support($feature){
		$support	= get_all_post_type_supports($this->name)[$feature] ?? false;

		if($support && is_array($support) && wp_is_numeric_array($support) && count($support) == 1){
			return reset($support);
		}

		return $support;
	}

	public function supports($feature){
		return post_type_supports($this->name, $feature);
	}

	public function get_size($type='thumbnail'){
		return $this->{$type.'_size'} ?: $type;
	}

	public function get_taxonomies(...$args){
		$taxonomies	= get_object_taxonomies($this->name);
		$output		= 'objects';
		$filters	= [];

		if($args){
			if(is_array($args[0])){
				$output		= $args[1] ?? 'objects';
				$filters	= $args[0];
			}else{
				$output		= $args[0];
			}
		}

		if($filters || $output == 'objects'){
			$objects	= array_filter(wpjam_fill($taxonomies, 'wpjam_get_taxonomy_object'));

			if($filters){
				$objects	= wp_filter_object_list($objects, $filters);
			}

			return $output == 'objects' ? $objects : array_keys($objects);
		}

		return $taxonomies;
	}

	public function in_taxonomy($taxonomy){
		return is_object_in_taxonomy($this->name, $taxonomy);
	}

	public function is_viewable(){
		return is_post_type_viewable($this->name);
	}

	public function reset_invalid_parent($value=0){
		$wpdb		= $GLOBALS['wpdb'];
		$post_ids	= $wpdb->get_col($wpdb->prepare("SELECT p1.ID FROM {$wpdb->posts} p1 LEFT JOIN  {$wpdb->posts} p2 ON p1.post_parent = p2.ID WHERE p1.post_type=%s AND p1.post_parent > 0 AND p2.ID is null", $this->name)) ?: [];

		array_walk($post_ids, fn($id)=> wp_update_post(['ID'=>$id, 'post_parent'=>$value]));

		return count($post_ids);
	}

	public function filter_labels($labels){
		$labels		= (array)$labels;
		$name		= $labels['name'];
		$search		= $this->hierarchical ? ['撰写新', '写文章', '页面', 'page', 'Page'] : ['撰写新', '写文章', '文章', 'post', 'Post'];
		$replace	= ['添加', '添加'.$name, $name, $name, ucfirst($name)];
		$labels		= wpjam_map($labels, fn($v, $k)=> ['all_items'=>'所有'.$name, 'archives'=>$name.'归档'][$k] ?? (($v && $v != $name) ? str_replace($search, $replace, $v) : $v));

		return array_merge($labels, (array)($this->labels ?? []));
	}

	public function filter_link($link, $post){
		return get_post_type($post) == $this->name ? str_replace('%'.$this->name.'_id%', $post->ID, $link) : $link;
	}

	public function registered_callback($name, $object){
		if($this->name == $name){
			if($this->permastruct){
				if(strpos($this->permastruct, '%'.$name.'_id%')){
					add_rewrite_tag('%'.$name.'_id%', '([0-9]+)', 'post_type='.$name.'&p=');

					remove_rewrite_tag('%'.$name.'%');

					add_filter('post_type_link',	[$this, 'filter_link'], 1, 2);
				}

				add_permastruct($name, $this->permastruct, array_merge($object->rewrite, ['feed'=>$this->rewrite['feeds']]));
			}

			$callback	= $this->registered_callback;

			if($callback && is_callable($callback)){
				$callback($name, $object);
			}
		}
	}

	public function registered(){
		if($this->_jam){
			if(is_admin() && $this->show_ui){
				add_filter('post_type_labels_'.$this->name,	[$this, 'filter_labels']);
			}

			add_action('registered_post_type_'.$this->name,	[$this, 'registered_callback'], 10, 2);

			wpjam_load('init', fn()=> register_post_type($this->name, $this->to_array()));

			if($this->options){
				wpjam_map($this->options, fn($option, $name)=> wpjam_register_post_option($name, $option+['post_type'=>$this->name]));
			}
		}
	}

	public static function filter_register_args($args, $name){
		if(did_action('init') || empty($args['_builtin'])){
			$object	= self::get($name);
			$object	= $object ? $object->update_args($args) : self::register($name, array_merge($args, ['_jam'=>false]));

			return $object->to_array();
		}

		return $args;
	}
}

class WPJAM_Posts{
	public static function query($query, &$args=[]){
		return new WP_Query(self::parse_query_vars($query, $args)+['no_found_rows'=>true, 'ignore_sticky_posts'=>true]);
	}

	public static function parse($query, $args=[], $format=''){
		$filter	= wpjam_pull($args, 'filter');
		$query	= is_object($query) ? $query : self::query($query, $args);

		if(!$filter && $query->get('related_query')){
			$filter	= 'wpjam_related_post_json';
		}

		if($format == 'date'){
			$day	= wpjam_pull($query->query_vars, 'day');
		}

		while($query->have_posts()){
			$query->the_post();

			$id		= get_the_ID();
			$json	= wpjam_get_post($id, $args);

			if($json){
				$json	= $filter ? apply_filters($filter, $json, $id, $args) : $json;

				if($format == 'date'){
					$date	= explode(' ', $json['date'])[0];
					$number	= (int)(explode('-', $date)[2]);

					if($day && $number != $day){
						continue;
					}

					$parsed[$date]		??= [];
					$parsed[$date][]	= $json;
				}else{
					$parsed[]	= $json;
				}
			}
		}

		if(!empty($args['list_query'])){
			wp_reset_postdata();
		}

		return $parsed ?? [];
	}

	public static function render($query, $args=[]){
		$output	= '';
		$query	= is_object($query) ? $query : self::query($query, $args);
		$get_cb	= fn($name, &$args)=> (($value = wpjam_pull($args, $name)) && is_callable($value)) ? $value : [self::class, $name];

		$item_callback	= $get_cb('item_callback', $args);
		$wrap_callback	= $get_cb('wrap_callback', $args);
		$title_number	= wpjam_pull($args, 'title_number');
		$threshold		= strlen(count($query->posts));

		while($query->have_posts()){
			$query->the_post();

			$output .= $item_callback(get_the_ID(), array_merge($args, $title_number ? ['title_number'=>zeroise($query->current_post+1, $threshold)] : []));
		}

		wp_reset_postdata();

		return $wrap_callback($output, $args);
	}

	public static function item_callback($post_id, $args){
		$args	+= [
			'title_number'	=> 0,
			'excerpt'		=> false,
			'thumb'			=> true,
			'size'			=> 'thumbnail',
			'thumb_class'	=> 'wp-post-image',
			'wrap_tag'		=> 'li'
		];

		$title	= get_the_title($post_id);
		$item	= wpjam_wrap($title);

		if($args['title_number']){
			$item->before('span', ['title-number'], $args['title_number'].'. ');
		}

		if($args['thumb'] || $args['excerpt']){
			$item->wrap('h4');

			if($args['thumb']){
				$item->before(get_the_post_thumbnail($post_id, $args['size'], ['class'=>$args['thumb_class']]));
			}

			if($args['excerpt']){
				$item->after(wpautop(get_the_excerpt($post_id)));
			}
		}

		$item->wrap('a', ['href'=>get_permalink($post_id), 'title'=>strip_tags($title)]);

		if($args['wrap_tag']){
			$item->wrap($args['wrap_tag']);
		}

		return $item->render();
	}

	public static function wrap_callback($output, $args){
		if(!$output){
			return '';
		}

		$args	+= [
			'title'		=> '',
			'div_id'	=> '',
			'class'		=> [],
			'thumb'		=> true,
			'wrap_tag'	=> 'ul'
		];

		$output	= wpjam_wrap($output);

		if($args['wrap_tag']){
			$output->wrap($args['wrap_tag'])->add_class($args['class']);

			if($args['thumb']){
				$output->add_class('has-thumb');
			}
		}

		if($args['title']){
			$output->before($args['title'], 'h3');
		}

		if($args['div_id']){
			$output->wrap('div', ['id'=>$args['div_id']]);
		}

		return $output->render();
	}

	public static function parse_query_vars($vars, &$args=[]){
		$query_keys	= wpjam_fill([...array_values(get_taxonomies(['_builtin'=>false])), 'category', 'post_tag'], 'wpjam_get_taxonomy_query_key');
		$term_ids	= array_filter(wpjam_map($query_keys, fn($v)=> wpjam_get($vars, $v)));
		$vars 		= wpjam_except($vars, array_values($query_keys));

		if(!empty($vars['taxonomy']) && empty($vars['term'])){
			$term_id	= wpjam_pull($vars, 'term_id');

			if($term_id){
				if(is_numeric($term_id)){
					$term_ids[wpjam_pull($vars, 'taxonomy')]	= $term_id;
				}else{
					$vars['term']	= $term_id;
				}
			}
		}

		foreach($term_ids as $tax => $term_id){
			if($tax == 'category' && $term_id != 'none'){
				$vars['cat']	= $term_id;
			}else{
				$vars['tax_query'][]	= ['taxonomy'=>$tax, 'field'=>'term_id']+($term_id == 'none' ? ['operator'=>'NOT EXISTS'] : ['terms'=>[$term_id]]);
			}
		}

		foreach(['cursor'=>'before', 'since'=>'after'] as $key => $var){
			$value	= wpjam_pull($vars, $key);

			if($value){
				$vars['date_query'][]	= [$var=> wpjam_date('Y-m-d H:i:s', $value)];
			}
		}

		if($args){
			$vars	= array_merge($vars, wpjam_pull($args, ['post_type', 'orderby', 'posts_per_page']));
			$number	= wpjam_pull($args, 'number');
			$vars	= array_merge($vars, ($number ? ['posts_per_page'=>$number] : []));
			$days	= wpjam_pull($args, 'days');

			if($days){
				$after	= wpjam_date('Y-m-d', time() - DAY_IN_SECONDS * $days).' 00:00:00';
				$column	= wpjam_pull($args, 'column') ?: 'post_date_gmt';

				$vars['date_query'][]	= ['column'=>$column, 'after'=>$after];
			}
		}

		return $vars;
	}

	public static function get_related_query($post, $args=[]){
		$post	= get_post($post);

		if($post){
			$object		= wpjam_post($post);
			$post_type	= [get_post_type($post)];
			$tt_ids		= [];

			foreach(array_diff($object->taxonomies, ['post_format']) as $tax){
				$terms	= $object->get_terms($tax);

				if($terms){
					$post_type	= [...$post_type, ...get_taxonomy($tax)->object_type];
					$tt_ids		= [...$tt_ids, ...array_column($terms, 'term_taxonomy_id')];
				}
			}

			if($tt_ids){
				return self::query([
					'related_query'		=> true,
					'post_status'		=> 'publish',
					'post__not_in'		=> [$post->ID],
					'post_type'			=> array_unique($post_type),
					'term_taxonomy_ids'	=> array_unique(array_filter($tt_ids)),
				], $args);
			}
		}

		return false;
	}

	public static function get_related_object_ids($tt_ids, $number, $page=1){
		$tt_ids	= implode(',', array_map('intval', $tt_ids));
		$key	= 'related_object_ids:'.$tt_ids.':'.$page.':'.$number;
		$sql	= 'SELECT object_id, count(object_id) as cnt FROM '.$GLOBALS['wpdb']->term_relationships.' WHERE term_taxonomy_id IN ('.$tt_ids.') GROUP BY object_id ORDER BY cnt DESC, object_id DESC LIMIT '.(($page-1)*$number).', '.$number;

		return wpjam_transient($key, fn()=> $GLOBALS['wpdb']->get_col($sql), DAY_IN_SECONDS);
	}

	public static function parse_json_module($args){
		$action	= wpjam_pull($args, 'action');

		if(!$action){
			return;
		}

		$wp	= $GLOBALS['wp'];

		if(isset($wp->raw_query_vars)){
			$wp->query_vars		= $wp->raw_query_vars;
		}else{
			$wp->raw_query_vars	= $wp->query_vars;
		}

		if($action == 'list'){
			return self::parse_list_json_module($args);
		}elseif($action == 'calendar'){
			return self::parse_calendar_json_module($args);
		}elseif($action == 'get'){
			return self::parse_get_json_module($args);
		}elseif($action == 'upload'){
			return self::parse_media_json_module($args, 'post_type');
		}
	}

	protected static function parse_json_query_vars($vars){
		$post_type	= $vars['post_type'] ?? '';

		if(is_string($post_type) && str_contains($post_type, ',')){
			$post_type = $vars['post_type']	= wp_parse_list($post_type);
		}

		$taxonomies	= ($post_type && is_string($post_type)) ? get_object_taxonomies($post_type) : get_taxonomies(['public'=>true]);

		foreach(array_diff($taxonomies, ['post_format']) as $tax){	// taxonomy 参数处理，同时支持 $_GET 和 $query_vars 参数
			if($tax == 'category'){
				$vars['cat']	??= wpjam_find(['category_id', 'cat_id'], fn($k)=> (int)wpjam_get_parameter($k), 'result');
	
				if(!$vars['cat']){
					unset($vars['cat']);	
				}
			}else{
				$query_key	= wpjam_get_taxonomy_query_key($tax);
				$term_id	= (int)wpjam_get_parameter($query_key);

				if($term_id){
					$vars[$query_key]	= $term_id;
				}
			}
		}

		$term_id	= (int)wpjam_get_parameter('term_id');
		$taxonomy	= wpjam_get_parameter('taxonomy');

		if($term_id && $taxonomy){
			$vars['term_id']	= $term_id;
			$vars['taxonomy']	= $taxonomy;
		}

		return self::parse_query_vars($vars);
	}

	protected static function parse_json_output($vars){
		$name	= $vars['post_type'] ?? '';

		return ($name && is_string($name)) ? wpjam_get_post_type_setting($name, 'plural') ?: $name.'s' : 'posts';
	}

	/* 规则：
	** 1. 分成主的查询和子查询（$query_args['sub']=1）
	** 2. 主查询支持 $_GET 参数 和 $_GET 参数 mapping
	** 3. 子查询（sub）只支持 $query_args 参数
	** 4. 主查询返回 next_cursor 和 total_pages，current_page，子查询（sub）没有
	** 5. $_GET 参数只适用于 post.list
	** 6. term.list 只能用 $_GET 参数 mapping 来传递参数
	*/
	public static function parse_list_json_module($args){
		$output	= wpjam_pull($args, 'output');
		$sub	= wpjam_pull($args, 'sub');

		$is_main_query	= !$sub;	// 子查询不支持 $_GET 参数，置空之前要把原始的查询参数存起来

		if($is_main_query){
			$wp			= $GLOBALS['wp'];
			$wp_query	= $GLOBALS['wp_query'];
			$query_vars	= array_merge($wp->query_vars, $args);

			$number		= wpjam_find(['number', 'posts_per_page'], fn($k)=> (int)wpjam_get_parameter($k), 'result');
			$query_vars	+= ($number && $number != -1) ? ['posts_per_page'=> $number > 100 ? 100 : $number] : [];
			$query_vars	+= ['offset'=> wpjam_get_parameter('offset')];
			$post__in	= wpjam_get_parameter('post__in');
			$post__in	= $post__in ? wp_parse_id_list($post__in) : [];

			if($post__in){
				$query_vars['post__in']			= $post__in;
				$query_vars['orderby']			??= 'post__in';
				$query_vars['posts_per_page']	??= -1;	
			}

			$orderby	= $query_vars['orderby'] ?? 'date';
			$use_cursor	= empty($query_vars['paged']) && empty($query_vars['s']) && !is_array($orderby) && in_array($orderby, ['date', 'post_date']);

			if($use_cursor){
				foreach(['cursor', 'since'] as $key){
					$query_vars[$key]	= (int)wpjam_get_parameter($key);

					if($query_vars[$key]){
						$query_vars['ignore_sticky_posts']	= true;
					}
				}
			}

			$query_vars	= $wp->query_vars = self::parse_json_query_vars($query_vars);

			$wp->query_posts();
		}else{
			$wp_query	= self::query($args);
		}

		$parsed	= self::parse($wp_query, $args);

		if($is_main_query){
			$posts_json['total']	= $total = $wp_query->found_posts;

			if($wp_query->get('nopaging')){
				$posts_json['total_pages']	= $total ? 1 : 0;
				$posts_json['current_page']	= 1;
			}else{
				$posts_json['total_pages']	= $wp_query->max_num_pages;
				$posts_json['current_page']	= $wp_query->get('paged') ?: 1;
			}

			if($use_cursor){
				$posts_json['next_cursor']	= ($parsed && $wp_query->max_num_pages > 1) ? end($parsed)['timestamp'] : 0;
			}

			if(is_front_page()){
				$is	= 'home';
			}elseif(is_author()){
				$is	= 'author';

				$posts_json	+= ['current_author'=>wpjam_get_user($wp_query->get('author'))];
			}elseif(is_category() || is_tag() || is_tax()){
				$is		= is_category() ? 'category' : (is_tag() ? 'tag' : 'tax');
				$term	= $wp_query->get_queried_object();

				$posts_json	+= ['current_taxonomy'=>($term ? $term->taxonomy : null)];
				$posts_json	+= $term ? ['current_term'=>wpjam_get_term($term, $term->taxonomy)] : [];
			}elseif(is_post_type_archive()){
				$is		= 'post_type_archive';
				$object	= $wp_query->get_queried_object();
				
				$posts_json	+= ['current_post_type'=>($object ? $object->name : null)];
			}elseif(is_search()){
				$is	= 'search';
			}elseif(is_archive()){
				$is	= 'archive';
			}

			if(isset($is)){
				$posts_json['is']	= $is;
			}

			$output	= $output ?: self::parse_json_output($query_vars);
		}else{
			$output	= $output ?: 'posts';
		}

		$posts_json[$output]	= $parsed;

		return apply_filters('wpjam_posts_json', $posts_json, $wp_query, $output);
	}

	public static function parse_calendar_json_module($args){
		$output	= wpjam_pull($args, 'output');
		$wp		= $GLOBALS['wp'];
		$vars	= array_merge($wp->query_vars, $args, [
			'year'		=> (int)wpjam_get_parameter('year') ?: wpjam_date('Y'),
			'monthnum'	=> (int)wpjam_get_parameter('month') ?: wpjam_date('m'),
			'day'		=> (int)wpjam_get_parameter('day')
		]);

		$wp->query_vars	= $vars = self::parse_json_query_vars(wpjam_except($vars, 'day'));

		$wp->query_posts();

		$parsed	= self::parse($GLOBALS['wp_query'], $args, 'date');
		$output	= $output ?: self::parse_json_output($vars);

		return [$output=>$parsed];
	}

	public static function parse_get_json_module($args){
		$wp			= $GLOBALS['wp'];
		$wp_query	= $GLOBALS['wp_query'];

		$wp->set_query_var('cache_results', true);

		if(!empty($args['post_status'])){
			$wp->set_query_var('post_status', $args['post_status']);
		}

		$query_vars	= $wp->query_vars;
		$post_type	= array_get($args, 'post_type');
		$post_type	= $post_type == 'any' ? '' : $post_type;

		if(!$post_type){
			$post_types	= get_post_types(['_builtin'=>false, 'query_var'=>true], 'objects');
			$query_keys	= wp_list_pluck($post_types, 'query_var')+['post'=>'name', 'page'=>'pagename'];
			$post_type	= wpjam_find($query_keys, fn($key, $post_type)=> !empty($query_vars[$key]), 'key');

			if($post_type){
				$key	= $query_keys[$post_type];
				$name	= $query_vars[$key];
			}
		}

		if(empty($name)){
			if($post_type){
				$key		= is_post_type_hierarchical($post_type) ? 'pagename' : 'name';
				$required	= empty($query_vars[$key]);
			}else{
				$required	= true;
			}

			$post_id	= array_get($args, 'id') ?: (int)wpjam_get_parameter('id', ['required'=>$required]);
			$post_type	= $post_type ?: get_post_type($post_id);

			if(!post_type_exists($post_type)){
				wp_die('invalid_post_type');
			}

			if(!$post_type || ($post_id && get_post_type($post_id) != $post_type)){
				wp_die('无效的参数：id', 'invalid_parameter');
			}

			$wp->set_query_var('post_type', $post_type);

			if($post_id){
				$wp->set_query_var('p', $post_id);
			}
		}

		$wp->query_posts();

		if(empty($post_id) && empty($args['post_status']) && !$wp_query->have_posts()){
			$post_id	= apply_filters('old_slug_redirect_post_id', null);

			if(!$post_id){
				wp_die('无效的文章 ID', 'invalid_post');
			}

			$wp->set_query_var('post_type', 'any');
			$wp->set_query_var('p', $post_id);
			$wp->set_query_var('name', '');
			$wp->set_query_var('pagename', '');
			$wp->query_posts();
		}

		$parsed	= $wp_query->have_posts() ? self::parse($wp_query, $args) : [];

		if(!$parsed){
			wp_die('参数错误', 'invalid_parameter');
		}

		$parsed		= current($parsed);
		$output		= array_get($args, 'output') ?: $parsed['post_type'];
		$response	= wpjam_pull($parsed, ['share_title', 'share_image', 'share_data']);

		if(is_single($parsed['id']) || is_page($parsed['id'])){
			wpjam_update_post_views($parsed['id']);
		}

		return array_merge($response, [$output => $parsed]);
	}

	public static function parse_media_json_module($args, $type=''){
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$media	= array_get($args, 'media') ?: 'media';
		$output	= array_get($args, 'output') ?: 'url';

		if(!isset($_FILES[$media])){
			wp_die('无效的参数：media', 'invalid_parameter');
		}

		if($type == 'post_type'){
			$pid	= (int)wpjam_get_post_parameter('post_id',	['default'=>0]);
			$id		= wpjam_try('media_handle_upload', $media, $pid);
			$url	= wp_get_attachment_url($id);
			$query	= wpjam_get_image_size($id);
		}else{
			$upload	= wpjam_try('wpjam_upload', $media);
			$url	= $upload['url'];
			$query	= wpjam_get_image_size($upload['file'], 'file');
		}

		if($query){
			$url	= add_query_arg($query, $url);
		}

		return $output ? [$output => $url] : $url;
	}

	public static function json_modules_callback($type='list', $args=[]){
		if(strpos($type, '.')){
			$parts	= explode('.', $type);
			$type	= end($parts);
		}

		$post_type	= wpjam_get_parameter('post_type');
		$args		+= ['post_type'=>$post_type, 'action'=>$type, 'output'=>($type == 'get' ? 'post' : 'posts')];
		$modules[]	= ['type'=>'post_type',	'args'=>array_filter($args)];

		if($type == 'list' && $post_type && is_string($post_type) && !str_contains($post_type, ',')){
			foreach(get_object_taxonomies($post_type, 'objects') as $tax => $tax_object){
				if($tax_object->hierarchical && $tax_object->public){
					$modules[]	= ['type'=>'taxonomy',	'args'=>['taxonomy'=>$tax, 'hide_empty'=>0]];
				}
			}
		}

		return $modules;
	}

	public static function filter_clauses($clauses, $wp_query){
		global $wpdb;

		if($wp_query->get('related_query')){
			$tt_ids	= $wp_query->get('term_taxonomy_ids');

			if($tt_ids){
				$clauses['join']	.= "INNER JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
				$clauses['where']	.= " AND tr.term_taxonomy_id IN (".implode(",",$tt_ids).")";
				$clauses['groupby']	.= " tr.object_id";
				$clauses['orderby']	= " count(tr.object_id) DESC, {$wpdb->posts}.ID DESC";
			}
		}else{
			$orderby	= $wp_query->get('orderby');
			$order		= $wp_query->get('order') ?: 'DESC';

			if($orderby == 'comment_date'){
				$comment_type	= $wp_query->get('comment_type') ?: 'comment';
				$type_str		= $comment_type	== 'comment' ? "'comment', ''" : "'".esc_sql($comment_type)."'";
				$ct_where		= "ct.comment_type IN ({$type_str}) AND ct.comment_parent=0 AND ct.comment_approved NOT IN ('spam', 'trash', 'post-trashed')";

				$clauses['join']	= "INNER JOIN {$wpdb->comments} AS ct ON {$wpdb->posts}.ID = ct.comment_post_ID AND {$ct_where}";
				$clauses['groupby']	= "ct.comment_post_ID";
				$clauses['orderby']	= "MAX(ct.comment_ID) {$order}";
			}elseif($orderby == 'views' || $orderby == 'comment_type'){
				$meta_key			= $orderby == 'comment_type' ? $wp_query->get('comment_count') : 'views';
				$clauses['join']	.= "LEFT JOIN {$wpdb->postmeta} jam_pm ON {$wpdb->posts}.ID = jam_pm.post_id AND jam_pm.meta_key = '{$meta_key}' ";
				$clauses['orderby']	= "(COALESCE(jam_pm.meta_value, 0)+0) {$order}, " . $clauses['orderby'];
			}elseif(in_array($orderby, ['', 'date', 'post_date'])){
				$clauses['orderby']	.= ", {$wpdb->posts}.ID {$order}";
			}
		}

		return $clauses;
	}

	public static function filter_content_save_pre($content){
		if($content && is_serialized($content)){
			$name		= current_filter();
			$callback	= 'wp_filter_post_kses';

			if(has_filter($name, $callback)){
				remove_filter($name, $callback);
				add_filter($name, fn($content)=> $content && is_serialized($content) ? $content : $callback($content));
			}
		}

		return $content;
	}
}