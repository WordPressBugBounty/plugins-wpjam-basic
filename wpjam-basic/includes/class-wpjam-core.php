<?php
class WPJAM_Core extends WPJAM_Register{
	public function __get($key){
		if($key == 'title'){
			return ($labels	= $this->builtin('labels')) ? $labels->singular_name : $this->label;
		}

		$value = $this->builtin($key) ?? parent::__get($key);

		return $key == 'plural' ? ($value ?: ($this->rest_base ?: $this->name.'s')) : $value;
	}

	public function __set($key, $value){
		$this->builtin($key, $value);

		return parent::__set($key, $value);
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	public function builtin($key='', ...$args){
		$type	= wpjam_get_builtin_type(get_class($this));

		if(!$key){
			return $type;
		}

		if($key != 'name'
			&& property_exists('WP_'.$type, $key)
			&& ($object = wpjam_call('get_'.$type.($type == 'post_type' ? '_object' : ''), $this->name))
		){
			return $args ? ($object->$key = $args[0]) : $object->$key;
		}
	}

	public function permastruct(){
		if(!($value = trim($this->get_arg('permastruct') ?: '', '/'))){
			return;
		}

		$name	= $this->name;
		$type	= $this->builtin();
		$hook	= 'registered_'.$type.'_'.$name;
		$qv		= $type == 'post_type' ? 'p' : 'term_id';
		$iv 	= $qv == 'p' ? '%post_id%' : '%term_id%';
		$value	= str_replace(
			($qv == 'p' ? ['%'.$name.'_id%', '%postname%'] : ['%'.$this->query_key.'%', '%termname%']),
			[$iv, '%'.$name.'%'],
			$value
		);

		$this->rewrite	= $this->rewrite ?: true;

		if(str_contains($value, $iv)){
			if($qv == 'p'){
				if($this->hierarchical){
					return;
				}
			}else{
				$this->remove_support('slug');
			}

			$this->query_var	??= false;

			add_action($hook, fn()=> remove_rewrite_tag('%'.$name.'%'));

			add_filter($name.'_rewrite_rules', fn($rules)=> wpjam_map($rules, fn($v)=> str_replace('?'.$qv.'=', '?'.$type.'='.$name.'&'.$qv.'=', $v)));
		}elseif($value == '%'.$name.'%'){
			if($qv != 'p'){
				$this->no_base	= true;

				return;
			}
		}

		add_action($hook, fn()=> add_permastruct($name, $value, $GLOBALS['wp_rewrite']->extra_permastructs[$name]));
	}

	public function labels($labels){
		$l	= (array)$labels;
		$n	= $l['name'];
		$h	= $this->hierarchical;

		if($this->builtin() == 'post_type'){
			$m	= ['all_items'=>'所有'.$n, 'archives'=>$n.'归档'];
			$s	= ['撰写新', '写文章', ...($h ? ['页面', 'page', 'Page'] : ['文章', 'post', 'Post'])];
			$r	= ['添加', '添加'.$n, $n, $n, ucfirst($n)];
		}else{
			$m	= [];
			$s	= $h ? ['分类', 'categories', 'Categories', 'Category'] : ['标签', 'Tag', 'tag'];
			$r	= $h ? [$n, $n.'s', ucfirst($n).'s', ucfirst($n)] : [$n, ucfirst($n), $n];
		}

		return array_merge(wpjam_map($l, fn($v, $k)=> $m[$k] ?? (($v && $v != $n) ? str_replace($s, $r, $v) : $v)), (array)$this->labels);
	}

	public function to_array(){
		$this->filter_args();
		$this->permastruct();

		if($this->_jam){
			$type	= $this->builtin();

			if(($v = $this->rewrite) !== false){
				$this->rewrite	= (is_array($v) ? $v : [])+['with_front'=>false];
			}

			if(is_admin() && $this->show_ui){
				add_filter($type.'_labels_'.$this->name, [$this, 'labels']);
			}

			wpjam_map($this->options ?:[], fn($v, $k)=> wpjam_register_meta_option($type == 'post_type' ? 'post' : 'term', $k, $v+[$type=>$this->name]));
		}

		return $this->args;
	}

	public function add_field($key, ...$args){
		return $this->process_arg('_fields', fn($v)=> array_merge($v ?: [], is_callable($key) ? [$key] : (is_array($key) ? $key : [$key => $args[0]])));
	}

	public function remove_field($key){
		return $this->delete_arg('_fields['.$key.']');
	}

	public function get_fields($id=0, $action_key=''){
		return wpjam_reduce($this->get_arg('_fields[]'), fn($c, $v, $k)=> array_merge($c, is_callable($v)
			? wpjam_pack(wpjam_try($v, $id, $action_key), is_numeric($k) ? null : $k)
			: (wpjam_is_assoc_array($v) ? [$k => $v] : [])
		), []);
	}

	public static function filter_builtin_args($args, $name, $object_type=null){
		if(!static::get($name) && !empty($args['public']) && (did_action('init') || empty($args['_builtin']))){
			return (static::register($name, ['_jam'=>false]+array_filter(compact('object_type'))+$args))->to_array();
		}

		return $args;
	}
}
/**
* @config menu_page, admin_load, register_json, init
**/
#[config('menu_page', 'admin_load', 'register_json', 'init')]
class WPJAM_Post_Type extends WPJAM_Core{
	public function to_array(){
		parent::to_array();		

		if($this->_jam){
			$this->public	??= true;
			$this->supports	??= ['title'];

			if($this->hierarchical){
				$this->update_arg('supports[]', 'page-attributes');
			}

			if(!is_array($this->taxonomies)){
				$this->delete_arg('taxonomies');
			}

			if($v = $this->menu_icon){
				$this->menu_icon	= wpjam_prefix($v, 'dashicons-');
			}
		}

		return $this->args += ['model'=>'WPJAM_Post'];
	}

	public function get_menu_slug(){
		return $this->menu_slug ?? (($v = $this->get_arg('menu_page')) && is_array($v) ? (wp_is_numeric_array($v) ? array_first($v) : $v)['menu_slug'] : null);
	}

	public function get_fields($id=0, $action_key=''){
		$fields	= ($this->supports('images') ? [
			'images'	=> ['title'=>'图集',	'type'=>'mu-img',	'name'=>'meta_input[images]',	'item_type'=>'url',	'size'=>($this->images_sizes ?: [''])[0],	'max_items'=>$this->images_max_items]
		] : [])+($this->supports('video') ? [
			'video'		=> ['title'=>'视频',	'type'=>'url',		'name'=>'meta_input[video]']
		] : [])+parent::get_fields($id, $action_key);

		if(in_array($action_key, ['add', 'set'])){
			$fields	= [
				'post_title'	=> ['type'=>'text',		'title'=>'标题',	'required']
			]+($action_key == 'add' ? [
				'post_type'		=> ['type'=>'hidden',	'value'=>$this->name],
				'post_status'	=> ['type'=>'hidden',	'value'=>'draft']
			] : [])+($this->supports('excerpt') ? [
				'post_excerpt'	=> ['title'=>'摘要',	'type'=>'textarea']
			] : [])+($this->supports('thumbnail') ? [
				'_thumbnail_id'	=> ['title'=>'头图', 'type'=>'img', 'size'=>'600x0',	'name'=>'meta_input[_thumbnail_id]']
			] : [])+wpjam_map($fields, fn($v, $k)=> (empty($v['name']) && !property_exists('WP_Post', $k) ? ['name'=>'meta_input['.$k.']'] : [])+$v);
		}

		return $fields;
	}

	public function supports($feature){
		return array_any(wp_parse_list($feature), fn($f)=> post_type_supports($this->name, $f));
	}

	public function get_taxonomies(...$args){
		$filter = $args && is_array($args[0]) ? array_shift($args) : [];
		$output	= $args[0] ?? 'objects';
		$data	= get_object_taxonomies($this->name);

		if($filter || $output == 'objects'){
			$data	= wpjam_fill($data, 'wpjam_get_taxonomy');
			$data	= $filter ? wpjam_filter($data, $filter) : $data;
			$data	= $output == 'objects' ? $data : array_keys($data);
		}

		return $data;
	}

	public function parse_images($images, $args=[]){
		$ss		= $this->images_sizes;
		$sizes	= [];

		foreach(['large', 'thumbnail'] as $k){
			if(($v = $args[$k.'_size'] ?? '') !== false){
				if(!$v && $ss){
					$v	= $ss[$k == 'large' ? 0 : 1];

					if($k == 'thumbnail' && count($images) == 1){
						$i	= array_first($images);
						$q	= wpjam_image($i, 'query') ?: wpjam_tap(wpjam_image($i, 'size') ?: ['width'=>0, 'height'=>0], fn($q)=> update_post_meta($args['post_id'], 'images', [add_query_arg($q, $i)]));

						$v	= ($o = $q['orientation'] ?? '') ? ($ss[$o == 'landscape' ? 2 : 3] ?? $v) : $v;
					}
				}else{
					$v	= $v ?: $this->{$k.'_size'};
				}
			
				$sizes[$k]	= $v ?: $k;
			}
		}

		$sizes	+= (!$sizes || ($args['full_size'] ?? true)) ? ['full'=>'full'] : [];

		foreach($images as $image){
			$parsed	= array_map(fn($s)=> wpjam_get_thumbnail($image, $s), $sizes);
			$parsed	= array_merge($parsed, ($query = wpjam_image($image, 'query')) ? wpjam_pick($query, ['orientation', 'width', 'height'])+['width'=>0, 'height'=>0] : []);

			if(isset($sizes['thumbnail'])){
				$size	= wpjam_parse_size($sizes['thumbnail']);
				$parsed	= array_reduce(['width', 'height'], fn($c, $k)=> $c+['thumbnail_'.$k=>$size[$k] ?? 0], $parsed);
			}

			$results[]	= count($sizes) == 1 ? array_first($parsed) : $parsed;
		}

		return $results;
	}

	public function reset_invalid_parent($value=0){
		$wpdb	= $GLOBALS['wpdb'];
		$ids	= $wpdb->get_col($wpdb->prepare("SELECT p1.ID FROM {$wpdb->posts} p1 LEFT JOIN {$wpdb->posts} p2 ON p1.post_parent = p2.ID WHERE p1.post_type=%s AND p1.post_parent > 0 AND p2.ID is null", $this->name)) ?: [];

		return count(array_map(fn($id)=> wp_update_post(['ID'=>$id, 'post_parent'=>$value]), $ids));
	}

	public function registered(){
		$this->_jam && wpjam_init(fn()=> register_post_type($this->name, $this->to_array()));
	}

	public static function filter_clauses($clauses, $query){
		$wpdb		= $GLOBALS['wpdb'];
		$orderby	= $query->get('orderby');
		$order		= strtoupper($query->get('order')) == 'ASC' ? 'ASC' : 'DESC';;

		if($orderby == 'related'){
			if($tt_ids = array_filter(wp_parse_id_list($query->get('term_taxonomy_ids')))){
				$clauses['join']	.= "INNER JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
				$clauses['where']	.= " AND tr.term_taxonomy_id IN (".implode(",", $tt_ids).")";
				$clauses['groupby']	.= " tr.object_id";
				$clauses['orderby']	= " count(tr.object_id) DESC, {$wpdb->posts}.ID DESC";
			}
		}elseif($orderby == 'comment_date'){
			$ct		= $query->get('comment_type') ?: 'comment';
			$str	= $ct == 'comment' ? "'comment', ''" : "'".esc_sql($ct)."'";
			$where	= "ct.comment_type IN ({$str}) AND ct.comment_parent=0 AND ct.comment_approved NOT IN ('spam', 'trash', 'post-trashed')";

			$clauses['join']	= "INNER JOIN {$wpdb->comments} AS ct ON {$wpdb->posts}.ID = ct.comment_post_ID AND {$where}";
			$clauses['groupby']	= "ct.comment_post_ID";
			$clauses['orderby']	= "MAX(ct.comment_ID) {$order}";
		}elseif(in_array($orderby, ['views', 'comment_type'])){
			$clauses['join']	.= $wpdb->prepare("LEFT JOIN {$wpdb->postmeta} jam_pm ON {$wpdb->posts}.ID = jam_pm.post_id AND jam_pm.meta_key = %s ", sanitize_key($orderby == 'comment_type' ? $query->get('comment_count') : 'views'));
			$clauses['orderby']	= "(COALESCE(jam_pm.meta_value, 0)+0) {$order}, " . $clauses['orderby'];
			$clauses['groupby']	= "{$wpdb->posts}.ID";
		}elseif(in_array($orderby, ['', 'date', 'post_date'])){
			$clauses['orderby']	.= ", {$wpdb->posts}.ID {$order}";
		}

		return $clauses;
	}

	public static function filter_results($posts, $query){
		$q	= &$query->query_vars;

		if(($sticky_posts = array_diff(wp_parse_id_list(wpjam_pull($q, 'sticky_posts') ?: []), $q['post__not_in']))
			&& ($stickies = get_posts([
				'orderby'			=> 'post__in',
				'post__in'			=> $sticky_posts,
				'post_type'			=> $q['post_type'] ?: 'post',
				'post_status'		=> 'publish',
				'posts_per_page'	=> count($sticky_posts),
			]+wpjam_pick($q, ['suppress_filters', 'cache_results', 'update_post_meta_cache', 'update_post_term_cache', 'lazy_load_term_meta'])))
		){
			$q['sticky_posts']	= array_column($stickies, 'ID');

			return array_merge($stickies, array_filter($posts, fn($post)=> !in_array($post->ID, $q['sticky_posts'], true)));
		}

		return $posts;
	}

	public static function add_hooks(){
		$hook	= 'content_save_pre';
		$cb		= 'wp_filter_post_kses';

		wpjam_hook($hook, 'tap', fn($c)=> is_serialized($c) && remove_filter($hook, $cb) && wpjam_once($hook, 'tap', fn()=> add_filter($hook, $cb), 11), 1);

		add_filter('post_type_link', fn($link, $post)=> str_replace('%post_id%', $post->ID, $link), 1, 2);

		add_filter('posts_clauses', [static::class, 'filter_clauses'], 1, 2);
		add_filter('posts_results', [static::class, 'filter_results'], 1, 2);
	}
}

/**
* @config menu_page, admin_load, register_json
**/
#[config('menu_page', 'admin_load', 'register_json')]
class WPJAM_Taxonomy extends WPJAM_Core{
	public function to_array(){
		$this->filter_args();

		$this->query_key	= ['category'=>'cat', 'post_tag'=>'tag_id'][$this->name] ?? $this->name.'_id';
		$this->column_name	= ['category'=>'categories', 'post_tag'=>'tags'][$this->name] ?? 'taxonomy-'.$this->name;
		$this->supports		= wpjam_array($this->supports ?? ['slug', 'description', ...($this->levels == 1 ? [] : ['parent'])], fn($k, $v)=> is_numeric($k) ? [$v, true] : [$k, $v]);

		parent::to_array();

		return $this->args	+= ($this->_jam ? ['show_in_nav_menus'=>false, 'show_in_rest'=>true, 'show_admin_column'=>true, 'hierarchical'=>true] : [])+['model'=>'WPJAM_Term', 'show_in_posts_rest'=>$this->show_in_rest];
	}

	public function add_support($feature, $value=true){
		return $this->update_arg('supports['.$feature.']', $value);
	}

	public function remove_support($feature){
		return $this->delete_arg('supports['.$feature.']');
	}

	public function supports($feature, ...$args){
		return is_array($feature) ? array_any($feature, [$this, $supports]) : (bool)$this->get_arg('supports['.$feature.']');
	}

	public function selectable(){
		return $this->selectable ?? (wp_count_terms(['taxonomy'=>$this->name, 'hide_empty'=>false]+($this->levels > 1 ? ['parent'=>0] : [])) <= 30);
	}

	public function get_fields($id=0, $action_key=''){
		$fields	= ($this->supports('thumbnail') ? [
			'thumbnail'	=> ['title'=>'缩略图',		'size'=>$this->thumbnail_size]+array_combine(['type', 'item_type'], $this->thumbnail_type == 'image' ? ['image', 'image'] : ['img', 'url'])
		] : [])+($this->supports('banner') ? [
			'banner'	=> ['title'=>'大图',	'type'=>'img',	'item_type'=>'url',	'size'=>$this->banner_size,	'show_if'=>['parent', -1]]
		] : [])+parent::get_fields($id, $action_key);

		if($action_key == 'set'){
			$fields	= [
				'name'			=> ['title'=>'名称',	'type'=>'text',	'class'=>'',	'required']
			]+($this->supports('slug') ? [
				'slug'			=> ['title'=>'别名',	'type'=>'text',	'class'=>'',	'required']
			] : [])+($this->hierarchical && $this->levels !== 1 && $this->supports('parent') ? [
				'parent'		=> ['title'=>'父级',	'options'=>['-1'=>'无']+$this->get_options(apply_filters('taxonomy_parent_dropdown_args', ['exclude_tree'=>$id], $this->name, 'edit'))]
			] : [])+($this->supports('slug') ? [
				'description'	=> ['title'=>'描述',	'type'=>'textarea']
			] : [])+$fields;
		}

		return $fields;
	}

	public function get_options($args=[]){
		return array_column(wpjam_get_terms($args+['taxonomy'=>$this->name, 'hide_empty'=>0, 'format'=>'flat', 'parse'=>false]), 'name', 'term_id');
	}

	public function get_mapping($post_id){
		$post	= wpjam_try('wpjam_validate_post', $post_id, $this->mapping_post_type);
		$data	= ['name'=>$post->post_title, 'slug'=>$post->post_type.'-'.$post_id, 'taxonomy'=>$this->name];

		if($term_id = get_post_meta($post_id, $this->query_key, true)){
			if($term = get_term($term_id, $this->name)){
				if($term->name != $data['name'] || $term->slug != $data['slug']){
					WPJAM_Term::update($term_id, $data);
				}

				return $term_id;
			}
		}

		return wpjam_tap(WPJAM_Term::insert($data), fn($term_id)=> update_post_meta($post_id, $this->query_key, $term_id));
	}

	public function dropdown(){
		$args	= ['taxonomy'=>$this->name, 'name'=>$this->query_key];
		$value	= $this->get_param($this->query_key);

		if(is_null($value)){
			$slug	= ($var = $this->query_var) ? $this->get_param($var) : null;
			$slug	??= $this->get_param('taxonomy') == $this->name ? $this->get_param('term') : '';
			$term 	= $slug ? get_term_by('slug', $slug, $this->name) : '';
			$value	= $term ? $term->term_id : '';
		}

		if($this->hierarchical){
			wp_dropdown_categories($args+[
				'show_option_all'	=> $this->labels->all_items,
				'show_option_none'	=> '没有设置',
				'option_none_value'	=> 'none',
				'selected'			=> $value,
				'hierarchical'		=> true
			]);
		}else{
			echo wpjam_field($args+[
				'value'			=> $value,
				'type'			=> 'text',
				'data_type'		=> 'taxonomy',
				'filterable'	=> true,
				'placeholder'	=> '请输入'.$this->title,
				'title'			=> '',
				'class'			=> ''
			]);
		}
	}

	public function registered(){
		$this->_jam && wpjam_init(fn()=> register_taxonomy($this->name, $this->object_type, $this->to_array()));
	}

	public static function filter_request($vars){
		if(($struct = get_option('permalink_structure'))
			&& ($request = $GLOBALS['wp']->request)
			&& ($no_base = static::get_by('no_base', true))
			&& !isset($vars['module'])
			&& !is_admin()
		){
			if(preg_match("#(.?.+?)/page/?([0-9]{1,})/?$#", $request, $matches)){
				[, $request, $paged]	= $matches;
			}

			if($GLOBALS['wp_rewrite']->use_verbose_page_rules){
				if(($vars['error'] ?? '') == '404'){
					$key	= 'error';
				}elseif(str_contains($struct, '/%postname%') && !empty($vars['name'])){
					$key	= 'name';
				}elseif(!str_contains($request, '/')){
					$type	= array_find(['author', 'category'], fn($k)=> str_starts_with($struct, '/%'.$k.'%'));
					$key	= $type && !empty($vars[$type.'_name']) ? [$type.'_name', 'name'] : '';
				}
			}else{
				$key	= !empty($vars['pagename']) && !isset($_GET['page_id']) && !isset($_GET['pagename']) ? 'pagename' : '';
			}

			foreach(!empty($key) ? array_keys($no_base) : [] as $tax){
				$name	= is_taxonomy_hierarchical($tax) ? wp_basename($request) : $request;

				if(array_find(wpjam_get_all_terms($tax), fn($term)=> $term->slug == $name)){
					return ($tax == 'category' ? ['category_name'=>$name] : ['taxonomy'=>$tax, 'term'=>$name])+array_filter(['paged'=>$paged ?? 0])+wpjam_except($vars, $key);
				}
			}
		}

		return $vars;
	}

	public static function add_hooks(){
		wpjam_init(fn()=> add_rewrite_tag('%term_id%', '([0-9]+)', 'term_id='));

		wpjam_hook('query_vars', '+', ['term_id'], 11);

		add_filter('pre_term_link',	fn($link, $term)=> wpjam_get_taxonomy_setting($term->taxonomy, 'no_base') ? '%'.$term->taxonomy.'%' : str_replace('%term_id%', $term->term_id, $link), 1, 2);

		add_filter('request', [self::class, 'filter_request']);
	}
}

class WPJAM_Post extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'post_id'])){
			return $this->id;
		}elseif($key == 'views'){
			return (int)$this->meta_get('views');
		}elseif($key == 'viewable'){
			return is_post_publicly_viewable($this->id);
		}elseif($key == 'type_object'){
			return wpjam_get_post_type_object($this->post_type);
		}elseif($key == 'thumbnail'){
			return $this->supports('thumbnail') ? get_the_post_thumbnail_url($this->id, 'full') : '';
		}elseif($key == 'images'){
			return $this->supports('images') ? array_values($this->meta_get('images') ?: []) : [];
		}

		return $this->builtin($key);
	}

	public function __call($method, $args){
		if($method == 'get_type_setting'){
			return $this->type_object->{$args[0]};
		}elseif(in_array($method, ['get_taxonomies', 'supports'])){
			return $this->type_object->$method(...$args);
		}elseif(in_array($method, ['get_content', 'get_excerpt', 'get_first_image_url', 'get_thumbnail_url', 'get_images'])){
			return ('wpjam_get_post_'.substr($method, 4))($this->post, ...$args);
		}elseif($cb	= ['get_terms'=>'get_the_terms', 'set_terms'=>'wp_set_post_terms', 'in_term'=>'is_object_in_term'][$method] ?? ''){
			return $cb($this->id, ...$args);
		}elseif($method === 'in_taxonomy'){
			return is_object_in_taxonomy($this->post, ...$args);
		}

		return $this->call_dynamic_method($method, ...$args);
	}

	public function save($data){
		if(array_find(['post_status', 'status'], fn($k)=> ($data[$k] ?? '') === 'publish')){
			if($cb = wpjam_callback([$this, 'is_publishable'])){
				$result	= wpjam_catch($cb) ?: new WP_Error('cannot_publish', '不可发布');
			}
		}

		return wpjam_then($result ?? true, fn()=> $data ? self::update($this->id, $data, false) : true);
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

	public function get_unserialized(){
		return wpjam_unserialize($this->content, fn($fixed)=> $this->save(['content'=>$fixed])) ?: [];
	}

	public function parse_for_json($args=[]){
		if($args && is_string($args) && in_array($args, ['date', 'modified'])){
			$ts		= get_post_timestamp($this->id, $args);
			$prefix	= $args == 'modified' ? $args.'_' : '';
			$result	= [$prefix.'timestamp'=>$ts, $prefix.'time'=>wpjam_human_time_diff($ts), $prefix.'date'=>wpjam_date('Y-m-d', $ts)];

			return $result+($args == 'date' ? ['day'=>wpjam_human_date_diff($result['date'])] : []);
		}

		$args	+= self::get_default_args();
		$query	= $args['query'] ?? null;
		$main	= $query && $query->is_main_query();
		$single	= $query && ($query->is_single($this->id) || $query->is_page($this->id));
		$rest	= $single ? 'show_in_rest' : 'show_in_posts_rest';
		$json	= wpjam_pick($this, ['id', 'type', 'post_type', 'status', 'views'])+['icon'=>(string)$this->type_object->icon];
		$json	+= $this->viewable ? ['name'=>urldecode($this->name), 'post_url'=>str_replace(home_url(), '', get_permalink($this->id))] : [];
		$json	+= wpjam_fill(['title', 'excerpt'], fn($k)=> $this->supports($k) ? html_entity_decode(('get_the_'.$k)($this->id)) : '');
		$json	+= ['thumbnail'=>$this->get_thumbnail_url($args['thumbnail_size'])];
		$json	+= $this->supports('images') ? ['images'=>$this->get_images()] : [];
		$json	+= ['user_id'=>(int)$this->author];
		$json	+= $this->supports('author') ? ['author'=>wpjam_get_user($this->author)] : [];
		$json	+= $this->parse_for_json('date')+$this->parse_for_json('modified');
		$json	+= $this->password ? ['password_protected'=>true, 'password_required'=>post_password_required($this->id)] : [];
		$json	+= $this->supports('page-attributes') ? ['menu_order'=>(int)$this->menu_order] : [];
		$json	+= $this->supports('post-formats') ? ['format'=>get_post_format($this->id) ?: ''] : [];

		if($main || $args['options_required']){
			$json	+= array_reduce(wpjam_get_post_options($this->type, [$rest=>true]), fn($c, $o)=> $c+$o->prepare($this->id), []);
		}

		if($main || $args['taxonomy_required']){
			$json	+= array_reduce($this->get_taxonomies([$rest=>true], 'names'), fn($c, $t)=> $c+[$t=>wpjam_get_terms(['terms'=>$this->get_terms($t), 'taxonomy'=>$t])], []);
		}

		if(($main && $single) || $args['content_required']){
			if($this->supports('editor')){
				$json	+= ['content'=>$this->get_content(), 'multipage'=>(bool)$GLOBALS['multipage']];
				$json	+= $json['multipage'] ? ['numpages'=>$GLOBALS['numpages'], 'page'=>$GLOBALS['page']] : [];
			}else{
				$json	+= is_serialized($this->content) ? ['content'=>$this->get_unserialized()] : [];
			}
		}

		$filter	= $args['suppress_filter'] ? '' : ($main ? 'wpjam_post_json' : ($args['filter'] ?? ''));

		return $filter ? apply_filters($filter, $json, $this->id, $args) : $json;
	}

	public function value_callback($field){
		if($field == 'tax_input'){
			return wpjam_fill($this->get_taxonomies('names'), fn($tax)=> array_column($this->get_terms($tax), 'term_id'));
		}

		return $this->post->$field ?? $this->meta_get($field);
	}

	public function update_callback($data, $defaults){
		return wpjam_then(
			$this->save(wpjam_pull($data, [...array_keys($this->data), 'tax_input'])),
			fn($result)=> $data ? $this->meta_input($data, $defaults) : $result
		);
	}

	public static function get_default_args(){
		return [
			'suppress_filter'	=> false,
			'options_required'	=> true,
			'taxonomy_required'	=> true,
			'content_required'	=> false,
			'thumbnail_size'	=> null
		];
	}

	public static function get_instance($post=null, $type=null, $wp_error=false){
		return wpjam_then(
			static::validate($post ?: get_post(), $type),
			fn($v)=> self::instance($v->ID, fn($id)=> [wpjam_get_post_type_setting(get_post_type($id), 'model') ?: 'WPJAM_Post', 'create_instance']($id)),
			fn($e)=> $wp_error ? $e : null
		);
	}

	public static function validate($value, $type=null){
		$type ??= static::get_current_post_type();

		return ($post = $value ? self::get_post($value) : null)
		? ((!post_type_exists($post->post_type) || ($type && $type !== 'any' && !in_array($post->post_type, (array)$type)))
			? new WP_Error('invalid_post_type')
			: $post
		)
		: new WP_Error('invalid_id');
	}

	public static function get($post){
		if($data = self::get_post($post, ARRAY_A)){
			$key	= 'post_content';
			$data	= ['id'=>$data['ID']]+(is_serialized($data[$key]) ? [$key=>wpjam_unserialize($data[$key])] : [])+$data;
			$data	+= wpjam_array($data, fn($k, $v)=> try_prefix($k, '-', 'post_') ? [$k, $v] : null);
		}

		return $data;
	}

	public static function update($post_id, $data, $validate=true){
		return wpjam_then(
			$validate ? static::validate($post_id) : null,
			fn()=> parent::update($post_id, $data)
		);
	}

	protected static function call_method($method, ...$args){
		if($method == 'insert'){
			$data	= $args[0];
			$type	= array_unique(array_filter([$data['post_type'] ?? '', static::get_current_post_type()]));
			$type	= count($type) <= 1 ? (array_first($type) ?: 'post') : '';

			$data['post_type']		= $type && post_type_exists($type) ? $type : wpjam_throw('invalid_post_type');
			$data['post_status']	??= current_user_can(get_post_type_object($type)->cap->publish_posts) ? 'publish' : 'draft';
			$data['post_author']	??= get_current_user_id();
			$data['post_date']		??= wpjam_date('Y-m-d H:i:s');

			return wp_insert_post(wp_slash($data), true, true);
		}elseif($method == 'update'){
			return wp_update_post(wp_slash(['ID'=>$args[0]]+$args[1]), true, true);
		}elseif($method == 'delete'){
			return wp_delete_post($args[0], true) ?: wpjam_throw('delete_error', '删除失败');
		}
	}

	protected static function sanitize_data($data, $post_id=0){
		$key	= 'post_content';
		$data	+= wpjam_array(get_class_vars('WP_Post'), fn($k, $v)=> try_prefix($k, '-', 'post_') && isset($data[$k]) ? ['post_'.$k, $data[$k]] : null);

		return (is_array($data[$key] ?? '') ? [$key=>serialize($data[$key])] : [])+$data
		+(!$post_id && method_exists(static::class, 'is_publishable') ? ['post_status'=>'draft'] : []);
	}

	public static function get_by_ids($post_ids){
		return array_map('get_post', array_filter(self::update_caches($post_ids)));
	}

	public static function update_caches($post_ids, $update_term_cache=false, $update_meta_cache=false){
		if($post_ids = array_filter(wp_parse_id_list($post_ids))){
			_prime_post_caches($post_ids, $update_term_cache, $update_meta_cache);

			$group	= wpjam_group(wp_cache_get_multiple($post_ids, 'posts'), fn($v)=> $v ? 1 : 0);

			do_action('wpjam_deleted_ids', 'post', array_keys($group[0] ?? []));
			do_action('wpjam_update_post_caches', ($group[1] ?? []), compact('update_term_cache', 'update_meta_cache'));

			return $group[1] ?? [];
		}

		return [];
	}

	public static function get_post($post, $output=OBJECT, $filter='raw'){
		return wpjam_tap(get_post($post, $output, $filter), fn($v)=> $post && is_numeric($post) && !$v && do_action('wpjam_deleted_ids', 'post', $post));
	}

	public static function get_current_post_type(){
		if(static::class !== self::class){
			return (WPJAM_Post_Type::get(static::class, 'model', self::class) ?: [])['name'] ?? null;
		}
	}

	public static function get_path($args, $item=[]){
		$type	= $item['post_type'];

		return $args === 'fields'
		? (get_post_type_object($type) ? [$type.'_id' => self::get_field(['post_type'=>$type, 'required'=>true])] : [])
		: (($id = is_array($args) ? (int)($args[$type.'_id'] ?? 0) : $args)
			? ($item['platform'] == 'template' ? get_permalink($id) : str_replace('%post_id%', $id, $item['path']))
			: new WP_Error('invalid_id', [wpjam_get_post_type_setting($type, 'title')])
		);
	}

	public static function get_field($args){
		$args['title'] ??= is_string($args['post_type'] ?? null) ? wpjam_get_post_type_setting($args['post_type'], 'title') : null;
		$placeholder	= '请输入'.$args['title'].'ID或者输入关键字筛选';

		return $args+compact('placeholder')+['type'=>'text', 'class'=>'all-options', 'data_type'=>'post_type'];
	}

	public static function with_field($method, $field, $value){
		$type	= $field->post_type;

		if($method == 'validate'){
			return (is_numeric($value) && wpjam_try(static::class.'::validate', $value, $type)) ? (int)$value : null;
		}elseif($method == 'parse'){
			return wpjam_get_post($value, ['post_type'=>$type, 'thumbnail_size'=>$field->size]);
		}
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

		return [
			'items'	=> $GLOBALS['wp_query']->query($args),
			'total'	=> $GLOBALS['wp_query']->found_posts
		];
	}

	public static function query_calendar($args){
		return array_reduce($GLOBALS['wp_query']->query([
			'posts_per_page'	=> -1,
			'post_status'		=> wpjam_pull($args, 'status') ?: 'any'
		]+$args+[
			'post_type'			=> static::get_current_post_type(),
			'monthnum'			=> $args['month']
		]), fn($c, $p)=> wpjam_set($c, wpjam_at($p->post_date, ' ', 0).'[]', $p), []);
	}

	public static function get_views(){
		if(!in_array(get_current_screen()->base, ['edit', 'upload'])){
			$counts	= array_filter((array)wp_count_posts(static::get_current_post_type()));
			$views	= ['all'=>['filter'=>['status'=>null, 'show_sticky'=>null], 'label'=>'全部', 'count'=>array_sum($counts)]];

			return wpjam_reduce($counts, fn($c, $v, $k)=> $c+(($object = get_post_status_object($k)) && $object->show_in_admin_status_list ? [$k=>['filter'=>['status'=>$k], 'label'=>$object->label, 'count'=>$v]] : []), $views);
		}
	}

	public static function filter_fields($fields, $id){
		return ($id && !is_array($id) && !isset($fields['title']) && !isset($fields['post_title']) ? ['title'=>['title'=>wpjam_get_post_type_setting(get_post_type($id), 'title').'标题', 'type'=>'view', 'value'=>get_the_title($id)]] : [])+$fields;
	}

	public static function get_meta($post_id, ...$args){	// deprecated
		return wpjam_get_metadata('post', $post_id, ...$args);
	}

	public static function update_meta($post_id, ...$args){	// deprecated
		return wpjam_update_metadata('post', $post_id, ...$args);
	}

	public static function update_metas($post_id, $data, $meta_keys=[]){
		return static::update_meta($post_id, $data, $meta_keys);
	}
}

class WPJAM_Term extends WPJAM_Instance{
	public function __get($key){
		if($key == 'id'){
			return $this->id;
		}elseif($key == 'tax_object'){
			return wpjam_get_taxonomy($this->taxonomy);
		}elseif($key == 'object_type'){
			return $this->tax_object->$key ?: [];
		}elseif($key == 'level'){
			return get_term_level($this->id);
		}elseif($key == 'depth'){
			return get_term_depth($this->id);
		}elseif($key == 'link'){
			return get_term_link($this->term);
		}

		return $this->builtin($key);
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
		return wpjam_then(
			$this->save(wpjam_pull($data, ['name', 'parent', 'slug', 'description', 'alias_of'])),
			fn($result)=> $data ? $this->meta_input($data, $defaults) : $result
		);
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
		$id		= $this->id;
		$tax	= $this->taxonomy;
		$json	= ['id'=>$id, 'name'=>html_entity_decode($this->name)]+wpjam_pick($this, ['name', 'taxonomy', 'count', ...(is_taxonomy_viewable($tax) ? ['slug'] : []), ...(is_taxonomy_hierarchical($tax) ? ['parent'] : []), 'description']);

		return apply_filters('wpjam_term_json', array_reduce(wpjam_get_term_options($tax), fn($c, $v)=> array_merge($c, $v->prepare($id)), $json), $id);
	}

	public static function get_instance($term, $tax=null, $wp_error=false){
		return wpjam_then(
			self::validate($term, $tax),
			fn($v)=> self::instance($v->term_id, fn($id)=> [wpjam_get_taxonomy_setting(get_term_taxonomy($id), 'model') ?: 'WPJAM_Term', 'create_instance']($id)),
			fn($e)=> $wp_error ? $e : null
		);
	}

	public static function get($term){
		return wpjam_then(
			$term ? self::get_term($term, '', ARRAY_A) : [],
			fn($data)=> $data ? $data+['id'=>$data['term_id']] : $data
		);
	}

	public static function update($term_id, $data, $validate=true){
		return wpjam_then(
			$validate ? static::validate($term_id) : null,
			fn()=> parent::update($term_id, $data)
		);
	}

	protected static function call_method($method, ...$args){
		if($method == 'insert'){
			$data	= $args[0];
			$tax	= array_unique(array_filter([wpjam_pull($data, 'taxonomy'), static::get_current_taxonomy()]));
			$tax	= count($tax) == 1 ? array_first($tax) : null;

			return wp_insert_term(wp_slash(wpjam_pull($data, 'name')), $tax, wp_slash($data))['term_id'];
		}elseif($method == 'update'){
			$data	= $args[1];
			$tax	= wpjam_pull($data, 'taxonomy') ?: get_term_field('taxonomy', $args[0]);

			return wp_update_term($args[0], $tax, wp_slash($data));
		}elseif($method == 'delete'){
			return wp_delete_term($args[0], get_term_field('taxonomy', $args[0]));
		}
	}

	public static function get_meta($term_id, ...$args){	// deprecated
		return wpjam_get_metadata('term', $term_id, ...$args);
	}

	public static function update_meta($term_id, ...$args){	// deprecated
		return wpjam_update_metadata('term', $term_id, ...$args);
	}

	public static function update_metas($term_id, $data, $meta_keys=[]){	// deprecated
		return self::update_meta($term_id, $data, $meta_keys);
	}

	public static function get_by_ids($term_ids){
		return self::update_caches($term_ids);
	}

	public static function update_caches($term_ids){
		if($term_ids = array_filter(wp_parse_id_list($term_ids))){
			_prime_term_caches($term_ids);

			$group	= wpjam_group(wp_cache_get_multiple($term_ids, 'terms'), fn($v)=> $v ? 1 : 0);

			do_action('wpjam_deleted_ids', 'term', array_keys($group[0] ?? []));

			return $group[1] ?? [];
		}

		return [];
	}

	public static function get_term($term, $tax='', $output=OBJECT, $filter='raw'){
		return wpjam_tap(get_term($term, $tax, $output, $filter), fn($v)=> $term && is_numeric($term) && !$v && do_action('wpjam_deleted_ids', 'term', $term));
	}

	public static function get_current_taxonomy(){
		if(static::class !== self::class){
			return (WPJAM_Taxonomy::get(static::class, 'model', self::class) ?: [])['name'] ?? null;
		}
	}

	public static function get_path($args, $item=[]){
		$tax	= $item['taxonomy'];
		$key	= wpjam_get_taxonomy_setting($tax, 'query_key');

		return $args === 'fields'
		? ($key ? [$key=> self::get_field(['taxonomy'=>$tax, 'required'=>true])] : [])
		: (($id = is_array($args) ? (int)wpjam_get($args, $key) : $args)
			? ($item['platform'] == 'template' ? get_term_link($id) : str_replace('%term_id%', $id, $item['path']))
			: new WP_Error('invalid_id', [wpjam_get_taxonomy_setting($tax, 'title')])
		);
	}

	public static function get_field($args=[]){
		$object	= isset($args['taxonomy']) && is_string($args['taxonomy']) ? wpjam_get_taxonomy($args['taxonomy']) : null;
		$type	= $args['type'] ?? '';
		$title	= $args['title'] ??= $object ? $object->title : null;
		$args	+= ['data_type'=>'taxonomy'];

		if($object && $object->hierarchical){
			if(!$type && is_admin() && $object->levels > 1 && $object->selectable()){
				$type	= 'cascade';
			}elseif(!$type || ($type == 'mu-text' && empty($args['item_type']))){
				$type	= (!is_admin() || $object->selectable()) ? ($type ? 'mu-' : '').'select' : $type;
			}elseif($type == 'mu-text' && $args['item_type'] == 'select'){
				$type	= 'mu-select';
			}
		}

		if(in_array($type, ['select', 'mu-select', 'cascade'])){
			if(($k = 'show_option_all') && ($v = array_first(wpjam_pull($args, [$k, 'option_all']))) !== false){
				$args[$k]	= $v === true || is_null($v) ? '请选择' : $v;
			}

			return ['type'=>$type]+($type == 'cascade' ? ['filter_key'=>'parent'] : ['options'=> fn()=> $object->get_options()])+$args;
		}

		return $args+['type'=>'text', 'class'=>'all-options', 'placeholder'=>'请输入'.$title.'ID或者输入关键字筛选'];
	}

	public static function with_field($method, $field, $value){
		$tax	= $field->taxonomy;

		if($method == 'render'){
			$terms	= get_terms(['taxonomy'=>$tax, 'hide_empty'=>0]);
			$values	= $value ? array_reverse([$value, ...get_ancestors($value, $tax, 'taxonomy')]) : [];

			for($i = 0; $i < wpjam_get_taxonomy_setting($tax, 'levels'); $i++){
				$parent		= $i ? ($values[$i-1] ?? null) : 0;
				$options[]	= is_null($parent) ? [] : array_column(wp_list_filter($terms, ['parent'=>$parent]), 'name', 'term_id');
			}

			return ['value'=>$values, 'options'=>$options];
		}elseif($method == 'validate'){
			if(is_array($value)){
				$value	= array_first(wpjam_sort(array_filter($value), 'kr')) ?: 0;
			}

			if(is_numeric($value)){
				return !$value || wpjam_try('get_term', $value, $tax) ? (int)$value : null;
			}

			$result	= term_exists($value, $tax);

			return $result ? (is_array($result) ? $result['term_id'] : $result) : ($field->creatable ? wpjam_try('WPJAM_Term::insert', ['name'=>$value, 'taxonomy'=>$tax]) : null);
		}elseif($method == 'parse'){
			return wpjam_get_term($value, $tax);
		}
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return array_values(get_terms($args+['number'=>(isset($args['parent']) ? 0 : 10), 'hide_empty'=>false]));
		}

		$defaults	= ['hide_empty'=>false, 'taxonomy'=>static::get_current_taxonomy()];

		return ['items'=>get_terms($args+$defaults), 'total'=>wp_count_terms($defaults)];
	}

	public static function validate($id, $tax=null){
		$tax	??= self::get_current_taxonomy();

		return wpjam_then(self::get_term($id), fn($v)=> (!$v || !($v instanceof WP_Term))
			? new WP_Error('invalid_term_id')
			: (($tax && $tax !== 'any' && !in_array($v->taxonomy, (array)$tax)) ? new WP_Error('invalid_taxonomy') : $v)
		);
	}

	public static function filter_fields($fields, $id){
		return ($id && !is_array($id) && !isset($fields['name']) ? ['name'=>['title'=>wpjam_get_taxonomy_setting(get_term_field('taxonomy', $id), 'title'), 'type'=>'view', 'value'=>get_term_field('name', $id)]] : [])+$fields;
	}
}

class WPJAM_User extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'user_id'])){
			return $this->id;
		}elseif($key == 'role'){
			return array_first($this->roles);
		}elseif($key === 'data'){
			return get_userdata($this->id);
		}

		return $this->builtin($key);
	}

	public function value_callback($field){
		return $this->$field;
	}

	public function save($data){
		return $data ? self::update($this->id, $data) : true;
	}

	public function parse_for_json($size=96){
		return apply_filters('wpjam_user_json', [
			'id'			=> $this->id,
			'nickname'		=> $this->nickname,
			'name'			=> $this->display_name,
			'display_name'	=> $this->display_name,
			'avatar'		=> get_avatar_url($this->user, $size),
		], $this->id);
	}

	public function add_role($role, $blog_id=0){
		return $role ? wpjam_call_for_blog($blog_id, fn()=> $this->roles && !in_array($role, $this->roles)
		? new WP_Error('error', '你已有权限，如果需要更改权限，请联系管理员直接修改。')
		: wpjam_tap($this, fn()=> $this->user->add_role($role))) : $this;
	}

	public function login(){
		wp_set_auth_cookie($this->id, true, is_ssl());
		wp_set_current_user($this->id);
		do_action('wp_login', $this->user_login, $this->user);

		return $this;
	}

	public static function get_instance($id, $wp_error=false){
		return wpjam_then(
			self::validate($id),
			fn($v)=> self::instance($v->ID, fn($id)=> new self($id)),
			fn($e)=> $wp_error ? $e : null
		);
	}

	public static function validate($user_id){
		return ($user_id && ($user = self::get_user($user_id)) && ($user instanceof WP_User)) ? $user : new WP_Error('invalid_user_id');
	}

	public static function update_caches($ids){
		$ids	= array_filter(wp_parse_id_list($ids));

		return $ids && (cache_users($ids) || true) ? array_map('get_userdata', $ids) : [];
	}

	public static function get_by_ids($ids){
		return self::update_caches($ids);
	}

	public static function get_user($user){
		return $user && is_numeric($user) ? wpjam_tap(get_userdata($user), fn($v)=> !$v && do_action('wpjam_deleted_ids', 'user', $user)) : $user;
	}

	public static function get_authors($args=[]){
		return get_users(array_merge($args, ['capability'=>'edit_posts']));
	}

	public static function get_path($args, $item=[]){
		return $args === 'fields'
		? ['author' => ['type'=>'select', 'options'=>fn()=> wp_list_pluck(WPJAM_User::get_authors(), 'display_name', 'ID')]]
		: (($id = is_array($args) ? (int)wpjam_get($args, 'author') : $args)
			? ($item['platform'] == 'template' ? get_author_posts_url($id) : str_replace('%author%', $id, $item['path']))
			: new WP_Error('invalid_author', ['作者'])
		);
	}

	public static function options_callback($field){
		return wp_list_pluck(self::get_authors(), 'display_name', 'ID');
	}

	public static function get($id){
		return ($user = get_userdata($id)) ? $user->to_array() : [];
	}

	protected static function call_method($method, ...$args){
		if($method == 'create'){
			$args	= $args[0]+[
				'user_pass'		=> wp_generate_password(12, false),
				'user_login'	=> '',
				'user_email'	=> '',
				'nickname'		=> '',
				// 'avatarurl'		=> '',
			];

			if(!wpjam_pull($args, 'users_can_register', get_option('users_can_register'))){
				return new WP_Error('registration_closed', '用户注册关闭，请联系管理员手动添加！');
			}

			if(empty($args['user_email'])){
				return new WP_Error('empty_user_email', '用户的邮箱不能为空。');
			}

			$args['user_login']	= preg_replace('/\s+/', '', sanitize_user($args['user_login'], true));

			$lock_key	= $args['user_login'] ? $args['user_login'].'_register_lock' : '';

			if($lock_key && wpjam_lock($lock_key, 5, 'users')){
				return new WP_Error('error', '该用户名正在注册中，请稍后再试！');
			}

			$data	= wpjam_pick($args, ['user_login', 'user_pass', 'user_email', 'role']);
			$data	+= $args['nickname'] ? ['nickname'=>$args['nickname'], 'display_name'=>$args['nickname']] : [];
			$id		= static::insert($data);

			if($lock_key){
				wpjam_lock($lock_key, -1, 'users');
			}

			return wpjam_then($id, fn()=> static::get_instance($id));
		}elseif($method == 'insert'){
			return wp_insert_user(wp_slash($args[0]));
		}elseif($method == 'update'){
			return wp_update_user(wp_slash(array_merge($args[1], ['ID'=>$args[0]])));
		}elseif($method == 'delete'){
			return wp_delete_user($args[0]);
		}
	}

	public static function create($args){
		return wpjam_call_for_blog(wpjam_get($args, 'blog_id'), fn()=> static::call_method('create', $args));
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return get_users(array_merge($args, ['search'=> !empty($args['search']) ? '*'.$args['search'].'*' : '']));
		}
	}

	public static function filter_fields($fields, $id){
		if($id && !is_array($id)){
			$object	= self::get_instance($id);
			$fields	= array_merge(['name'=>['title'=>'用户', 'type'=>'view', 'value'=>$object->display_name]], $fields);
		}

		return $fields;
	}
}

class WPJAM_Bind extends WPJAM_Register{
	public function __construct($type, $appid){
		parent::__construct($type.':'.$appid, [
			'type'		=> $type,
			'appid'		=> $appid,
			'bind_key'	=> wpjam_join('_', $type, $appid),
			'domain'	=> $type == 'phone' ? 'phone.sms' : $appid.'.'.$type
		]);
	}

	public function get_appid(){
		return $this->appid;
	}

	public function get_openid($meta_type, $object_id){
		return get_metadata($meta_type, $object_id, $this->bind_key, true);
	}

	public function update_openid($meta_type, $object_id, $openid){
		if(!$openid){
			return delete_metadata($meta_type, $object_id, $this->bind_key);
		}

		return update_metadata($meta_type, $object_id, $this->bind_key, $openid);
	}

	public function bind_openid($meta_type, $object_id, $openid){
		$bound	= ($current = $this->get_openid($meta_type, $object_id)) && $current != $openid;

		if($bound = $bound ?: wpjam_then($this->get_by_openid($meta_type, $openid), fn($v)=> $v && $v->id != $object_id)){
			return wpjam_then($bound, new WP_Error('is_bound', '已绑定其他账号，请先解绑再试！'));
		}

		$this->update_value($openid, $meta_type.'_id', $object_id);
		$this->update_openid($meta_type, $object_id, $openid);

		return $openid;
	}

	public function unbind_openid($meta_type, $object_id, $openid=null){
		$openid	= $openid ?: $this->get_openid($meta_type, $object_id);

		if($openid	= $openid ?: wpjam_call_method($this, 'get_openid_by', $meta_type.'_id', $object_id)){
			$this->update_openid($meta_type, $object_id, '');
			$this->update_value($openid, $meta_type.'_id', 0);
		}

		return $openid;
	}

	public function get_by_openid($meta_type, $openid){
		if(!$this->get_value($openid)){
			return new WP_Error('invalid_openid');
		}

		$cb		= 'wpjam_get_'.$meta_type.'_object';
		$object	= wpjam_call($cb, $this->get_value($openid, $meta_type.'_id'));
		$object	??= ($meta = wpjam_get_by_meta($meta_type, $this->bind_key, $openid)) ? wpjam_call($cb, array_first($meta)[$meta_type.'_id']) : null;

		return $object ?: (($meta_type == 'user' && ($user_id = username_exists($openid))) ? wpjam_get_user($user_id, 'object') : null);
	}

	public function get_by_user_email($meta_type, $email){
		if($email && try_suffix($email, '-', '@'.$this->domain)){
			return $this->get_value($email, $meta_type.'_id');
		}
	}

	protected function get_value($openid, $key=null){
		return wpjam_get(method_exists($this, 'get_user') ? $this->get_user($openid) : ['openid'=>$openid], $key ?: null);
	}

	protected function update_value($openid, $key, $value){
		return method_exists($this, 'update_user') ? $this->update_user($openid, [$key=>$value]) : true;
	}

	public function get_user_email($openid){
		return $openid.'@'.$this->domain;
	}

	public function get_email($openid){
		return $this->get_user_email($openid);
	}

	public function get_avatarurl($openid){
		return $this->get_value($openid, 'avatarurl');
	}

	public function get_nickname($openid){
		return $this->get_value($openid, 'nickname');
	}

	public function get_unionid($openid){
		return $this->get_value($openid, 'unionid');
	}

	public function get_phone_data($openid){
		return ($phone = $this->get_value($openid, 'phone')) ? ['phone'=>$phone, 'country_code'=>$this->get_value($openid, 'country_code') ?: 86] : [];
	}
}

class WPJAM_User_Signup extends WPJAM_Register{
	public function __call($method, $args){
		return [wpjam_get_bind($this->type, $this->appid), $method](...(str_ends_with($method, '_openid') ? ['user', ...$args] : $args));
	}

	public function signup($data, $args=null){
		if(is_array($data)){
			$by		= $this->by;
			$args	??= ($data['args'] ?? '') ?: [];
			$key	= $by == 'code' ? '' : ($data[$by] ?? '');
			$user	= apply_filters('wpjam_user_signup', null, $by, $key, $data['code'] ?? '') ?: $this->signup($this->verify($data), $args);

			return wpjam_tap($user, null, fn()=> do_action('wpjam_user_signup_failed', $by, $key, $user));
		}

		$openid	= $data;
		$args	= apply_filters('wpjam_user_signup_args', $args ?? [], $this->type, $this->appid, $openid);
		$user 	= wpjam_then($openid, fn()=> wpjam_then(
			$this->get_by_openid($openid),
			fn($user)=> $user ? $user->add_role($args['role'] ?? '', $args['blog_id'] ?? 0) : WPJAM_User::create([
				'user_login'	=> $openid,
				'user_email'	=> $this->get_user_email($openid),
				'nickname'		=> $this->get_nickname($openid)
			]+$args)
		));

		return wpjam_tap($user, fn()=> $this->bind($openid, $user->id) && do_action('wpjam_user_signuped', $user->login()->data, $args));
	}

	public function bind($data, $user_id=null){
		$user	= wpjam_get_user($user_id ?? get_current_user_id(), 'object');
		$openid	= is_array($data) ? $this->verify($data) : $data;
		$openid	= wpjam_then($openid, fn()=> $this->bind_openid($user->id, $openid));

		if(!wpjam_if_error($openid, '')){
			if(($avatarurl = $this->get_avatarurl($openid)) && $avatarurl !== $user->avatarurl){
				$user->meta_input('avatarurl', $avatarurl);
			}

			if(($nickname = $this->get_nickname($openid)) && $nickname !== $user->nickname){
				$user->save(['nickname'=>$nickname, 'display_name'=>$nickname]);
			}
		}

		return $openid;
	}

	public function unbind($user_id=null){
		return $this->unbind_openid($user_id ?? get_current_user_id());
	}

	public function verify($data){
		return $this->qrcode
		? wpjam_then($this->qrcode->verify($data['scene'], $data['code']), fn($qrcode)=> $qrcode['openid'])
		: $this->call('verify', $data);
	}

	public function get_fields($action='login', $for='admin'){
		if($action == 'bind' && ($openid = $this->get_openid(get_current_user_id()))){
			$view	= wpjam_join("<br />", [
				($avatar = $this->get_avatarurl($openid)) ? '<img src="'.str_replace('/132', '/0', $avatar).'" width="272" />' : '',
				($nickname = $this->get_nickname($openid)) ? '<strong>'.$nickname.'</strong>' : ''
			]);

			return [
				'view'		=> ['type'=>'view',		'title'=>'已绑定',	'value'=>($view ?: $openid)],
				'action'	=> ['type'=>'hidden',	'value'=>'unbind'],
			];
		}

		$fields	= $this->qrcode
		? wpjam_then($this->qrcode->get_fields($action), fn($v)=> wpjam_set($v, 'qrcode[title]', $this->title))
		: ((is_callable($this->fields) ? $this->call('fields', $action, $for) : $this->fields) ?: []);

		return wpjam_then($fields, $fields+['action' => ['type'=>'hidden',	'value'=>$action]]);
	}

	public function get_attr($action='login', $for=''){
		return wpjam_then($this->get_fields($action, $for), fn($fields)=> array_merge(
			$action == 'bind' ? ['submit_text'=>$fields['action']['value'] == 'unbind' ? '解除绑定' : '立刻绑定'] : [],
			$for != 'admin' ? wpjam_ajax($this->name.'-signup')->to_array() : [],
			['fields'=>$for != 'admin' ? wpjam_fields($fields)->render(['wrap_tag'=>'p']) : $fields]
		));
	}

	public function get_form(){
		return $this->get_attr('bind', 'admin')+[
			'callback'		=> [$this, 'callback'],
			'capability'	=> 'read',
			'validate'		=> true,
			'response'		=> 'redirect'
		];
	}

	public function callback($data){
		$action	= wpjam_pull($data, 'action');

		return try_prefix($action, '-', 'get-')
		? $this->get_attr($action)
		: wpjam_then(wpjam_catch([$this, $action == 'login' ? 'signup' : $action], ...($action == 'unbind' ? [] : [$data])), true);
	}

	public function registered(){
		wpjam_ajax($this->name.'-signup', [
			'nopriv'		=> true,
			'callback'		=> [$this, 'callback'],
			'nonce_action'	=> fn($data)=> str_starts_with($data['action'] ?? '', 'get-') ? '' : $this->name.'-signup'
		]);
	}

	public static function create($type, $args){
		if(wpjam_get_bind($type, $args['appid'] ?? '')){
			$model	= wpjam_pull($args, 'model') ?: 'WPJAM_User_Signup';
			$model	= is_object($model) ? get_class($model) : $model;	// 兼容

			return self::register(new $model($type, $args+['type'=>$type]));
		}
	}

	public static function on_admin_init(){
		$objects	= self::get_registereds();
		$tabs		= wpjam_array($objects, fn($k, $v)=> $v->bind ? [$k, [
			'title'			=> $v->title,
			'capability'	=> 'read',
			'function'		=> 'form',
			'form'			=> [$v, 'get_form']
		]] : null);

		$tabs && wpjam_add_menu_page([
			'parent'		=> 'users',
			'menu_slug'		=> 'wpjam-bind',
			'menu_title'	=> '账号绑定',
			'order'			=> 20,
			'capability'	=> 'read',
			'function'		=> 'tab',
			'tabs'			=> $tabs
		]);

		$objects && wpjam_add_admin_load([
			'base'		=> 'users',
			'callback'	=> fn()=> wpjam_register_list_table_column('openid', [
				'title'		=> '绑定账号',
				'order'		=> 20,
				'callback'	=> fn($user_id)=> wpjam_join('<br /><br />', wpjam_map($objects, fn($v)=> ($openid = $v->get_openid($user_id)) ? $v->title.'：<br />'.$openid : ''))
			])
		]);
	}

	public static function on_login_init(){
		$action	= ($_REQUEST['action'] ?? '') ?: 'login';
		$type	= $_REQUEST[$action.'_type'] ?? '';

		if(in_array($action, ['login', 'bind']) && ($objects = self::get_registereds([$action=>true]))){
			if($action == 'login'){
				$type	= $type ?: apply_filters('wpjam_default_login_type', $action);
				$type	= $type ?: ($_SERVER['REQUEST_METHOD'] == 'POST' ? 'login' : array_key_first($objects));

				if(isset($objects[$type])){
					wpjam_call($objects[$type]->login_action);
				}

				if(empty($_COOKIE[TEST_COOKIE])){
					$_COOKIE[TEST_COOKIE]	= 'WP Cookie check';
				}

				$objects['login']	= '使用账号和密码登录';
			}else{
				is_user_logged_in() ? wpjam_hook('login_display_language_dropdown', false) : wp_die('登录之后才能执行绑定操作！');
			}

			$type	= $type && isset($objects[$type]) ? $type : array_key_first($objects);
			$tag	= wpjam_tag('p', ['class'=>'types', 'data'=>['action'=>$action]])->append(wpjam_map($objects, fn($v, $k)=>['a', [
				'class'	=> $type == $k ? 'current' : '',
				'data'	=> ['type'=>$k]+($k == 'login' ? [] : ['action'=>$k.'-signup', 'data'=>['action'=>'get-'.$action]])
			], $k == 'login' ? $v : ($action == 'bind' ? '绑定'.$v->title : $v->login_title)]));

			wp_enqueue_script('wpjam-login', wpjam_url(dirname(__DIR__).'/static/login.js'), ['wpjam-ajax']);

			wpjam_echo($tag, 'login_form');
		}

		wp_add_inline_style('login', join("\n", [
			'.login .message, .login #login_error{margin-bottom: 0;}',
			'.code_wrap label:last-child{display:flex;}',
			'.code_wrap input.button{margin-bottom:10px;}',
			'.login form .input, .login input[type=password], .login input[type=text]{font-size:20px; margin-bottom:10px;}',

			'p.types{line-height:2; float:left; clear:left; margin-top:10px;}',
			'p.types a{text-decoration: none; display:block;}',
			'p.types a.current{display:none;}',
			'div.fields{margin-bottom:10px;}',
		]));
	}

	public static function add_hooks(){
		if(wp_using_ext_object_cache()){
			add_action('login_init',		[self::class, 'on_login_init']);
			add_action('wpjam_admin_init',	[self::class, 'on_admin_init']);
		}
	}
}