<?php
/**
* @config menu_page, admin_load, register_json, init
**/
#[config('menu_page', 'admin_load', 'register_json', 'init')]
class WPJAM_Post_Type extends WPJAM_Register{
	public function __get($key){
		$value	= $this->builtin($key) ?? parent::__get($key);

		if($key == 'model'){
			return $value ?: 'WPJAM_Post';
		}elseif($key == 'plural'){
			return $value ?? $this->name.'s';
		}

		return $value;
	}

	public function __set($key, $value){
		$this->builtin($key, $value);

		return parent::__set($key, $value);
	}

	public function to_array(){
		$this->filter_args();

		$name	= $this->name;
		$struct	= $this->_builtin ? '' : $this->get_arg('permastruct');
		$struct	= $struct ? str_replace(['%'.$name.'_id%', '%postname%'], ['%post_id%', '%'.$name.'%'], $struct) : '';

		if(strpos($struct, '%post_id%')){
			if($this->hierarchical){
				$struct	= false;
			}else{
				$this->query_var	??= false;

				add_action('registered_post_type_'.$name, fn()=> remove_rewrite_tag('%'.$name.'%'));

				add_filter($name.'_rewrite_rules', fn($rules)=> wpjam_map($rules, fn($v)=> str_replace('?p=', '?post_type='.$name.'&p=', $v)));
			}
		}

		if($struct){
			$this->rewrite	= $this->rewrite ?: true;

			add_action('registered_post_type_'.$name, fn()=> add_permastruct($name, trim($struct, '/'), ['feed'=>$this->rewrite['feeds']]+$this->rewrite));
		}

		if($this->_jam){
			$this->public	??= true;
			$this->supports	??= ['title'];
			$this->rewrite	??= true;

			$this->hierarchical && $this->update_arg('supports[]', 'page-attributes');
			$this->rewrite		&& $this->process_arg('rewrite', fn($v)=> (is_array($v) ? $v : [])+['with_front'=>false, 'feeds'=>false]);
			$this->menu_icon	&& $this->process_arg('menu_icon', fn($v)=> (str_starts_with($v, 'dashicons-') ? '' : 'dashicons-').$v);

			is_array($this->taxonomies) || $this->delete_arg('taxonomies');
		}

		return $this->args;
	}

	public function get_menu_slug(){
		return $this->menu_slug	?? (($menu_page = $this->get_arg('menu_page')) && is_array($menu_page) ? (wp_is_numeric_array($menu_page) ? array_first($menu_page) : $menu_page)['menu_slug'] : null);
	}

	public function get_fields($id=0, $action_key=''){
		$parsed	= wpjam_fields($this->get_arg('_fields[]'), $id, $action_key);

		if(in_array($action_key, ['add', 'set'])){
			$fields	= ($action_key == 'add' ? [
				'post_type'		=> ['type'=>'hidden',	'value'=>$this->name],
				'post_status'	=> ['type'=>'hidden',	'value'=>'draft']
			] : [])+[
				'post_title'	=> ['type'=>'text',		'title'=>'标题',	'required']
			];

			if($this->supports('excerpt')){
				$fields['post_excerpt']	= ['title'=>'摘要',	'type'=>'textarea'];
			}

			if($this->supports('thumbnail')){
				$fields['_thumbnail_id']	= ['title'=>'头图', 'type'=>'img', 'size'=>'600x0',	'name'=>'meta_input[_thumbnail_id]'];
			}

			$parsed	= wpjam_map($parsed, fn($v, $k)=> (empty($v['name']) && !property_exists('WP_Post', $k) ? ['name'=>'meta_input['.$k.']'] : [])+$v);
		}

		if($this->supports('images')){
			$fields['images']	= ['title'=>'图集',	'type'=>'mu-img',	'name'=>'meta_input[images]',	'item_type'=>'url',	'size'=>($this->images_sizes ?: [''])[0],	'max_items'=>$this->images_max_items];
		}

		if($this->supports('video')){
			$fields['video']	= ['title'=>'视频',	'type'=>'url',		'name'=>'meta_input[video]'];
		}

		return array_merge($fields ?? [], $parsed);
	}

	public function get_support($feature){
		$support	= get_all_post_type_supports($this->name)[$feature] ?? false;

		return (wp_is_numeric_array($support) && count($support) == 1) ? array_first($support) : $support;
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

	public function in_taxonomy($taxonomy){
		return is_object_in_taxonomy($this->name, $taxonomy);
	}

	public function is_viewable(){
		return is_post_type_viewable($this->name);
	}

	public function reset_invalid_parent($value=0){
		$wpdb	= $GLOBALS['wpdb'];
		$ids	= $wpdb->get_col($wpdb->prepare("SELECT p1.ID FROM {$wpdb->posts} p1 LEFT JOIN {$wpdb->posts} p2 ON p1.post_parent = p2.ID WHERE p1.post_type=%s AND p1.post_parent > 0 AND p2.ID is null", $this->name)) ?: [];

		array_walk($ids, fn($id)=> wp_update_post(['ID'=>$id, 'post_parent'=>$value]));

		return count($ids);
	}

	public function registered(){
		$this->_jam && wpjam_init(function(){
			is_admin() && $this->show_ui && add_filter('post_type_labels_'.$this->name,	function($labels){
				$labels		= (array)$labels;
				$name		= $labels['name'];
				$search		= $this->hierarchical ? ['撰写新', '写文章', '页面', 'page', 'Page'] : ['撰写新', '写文章', '文章', 'post', 'Post'];
				$replace	= ['添加', '添加'.$name, $name, $name, ucfirst($name)];
				$labels		= wpjam_map($labels, fn($v, $k)=> ['all_items'=>'所有'.$name, 'archives'=>$name.'归档'][$k] ?? (($v && $v != $name) ? str_replace($search, $replace, $v) : $v));

				return array_merge($labels, (array)($this->labels ?? []));
			});

			register_post_type($this->name, $this->to_array());

			wpjam_map($this->options ?: [], fn($option, $key)=> wpjam_register_post_option($key, $option+['post_type'=>$this->name]));
		});
	}

	public static function add_hooks(){
		$args	= ['content_save_pre', 'wp_filter_post_kses'];

		add_filter($args[0], fn($c)=> [$c, is_serialized($c) && remove_filter(...$args) && wpjam_hook('once', $args[0], fn($c)=> [$c, add_filter(...$args)][0], 11)][0], 1);

		add_filter('post_type_link', fn($link, $post)=> str_replace('%post_id%', $post->ID, $link), 1, 2);

		add_filter('posts_clauses', function($clauses, $query){
			$wpdb		= $GLOBALS['wpdb'];
			$orderby	= $query->get('orderby');
			$order		= $query->get('order') ?: 'DESC';

			if($orderby == 'related'){
				if($tt_ids = $query->get('term_taxonomy_ids')){
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
				$meta_key			= $orderby == 'comment_type' ? $query->get('comment_count') : 'views';
				$clauses['join']	.= "LEFT JOIN {$wpdb->postmeta} jam_pm ON {$wpdb->posts}.ID = jam_pm.post_id AND jam_pm.meta_key = '{$meta_key}' ";
				$clauses['orderby']	= "(COALESCE(jam_pm.meta_value, 0)+0) {$order}, " . $clauses['orderby'];
				$clauses['groupby']	= "{$wpdb->posts}.ID";
			}elseif(in_array($orderby, ['', 'date', 'post_date'])){
				$clauses['orderby']	.= ", {$wpdb->posts}.ID {$order}";
			}

			return $clauses;
		}, 1, 2);

		add_filter('posts_results', function($posts, $query){
			$q	= &$query->query_vars;

			$sticky_posts	= array_diff(wp_parse_id_list(wpjam_pull($q, 'sticky_posts') ?: []), $q['post__not_in']);
			
			if($sticky_posts && ($stickies = get_posts([
				'orderby'			=> 'post__in',
				'post__in'			=> $sticky_posts,
				'post_type'			=> $q['post_type'] ?: 'post',
				'post_status'		=> 'publish',
				'posts_per_page'	=> count($sticky_posts),
			]+wpjam_pick($q, ['suppress_filters', 'cache_results', 'update_post_meta_cache', 'update_post_term_cache', 'lazy_load_term_meta'])))){
				$q['sticky_posts']	= array_column($stickies, 'ID');

				return array_merge($stickies, array_filter($posts, fn($post)=> !in_array($post->ID, $q['sticky_posts'], true)));
			}

			return $posts;
		}, 1, 2);
	}
}

/**
* @config menu_page, admin_load, register_json
**/
#[config('menu_page', 'admin_load', 'register_json')]
class WPJAM_Taxonomy extends WPJAM_Register{
	public function __get($key){
		$value	= $this->builtin($key) ?? parent::__get($key);

		if($key == 'model'){
			return $value ?: 'WPJAM_Term';
		}elseif($key == 'plural'){
			return $value ?: ($this->name === 'category' ? 'categories' : $this->name.'s');
		}elseif($key == 'show_in_posts_rest'){
			return $value ?? $this->show_in_rest;
		}elseif($key == 'selectable'){
			return $value ?? wp_count_terms(['taxonomy'=>$this->name, 'hide_empty'=>false]+($this->levels > 1 ? ['parent'=>0] : [])) <= 30;
		}

		return $value;
	}

	public function __set($key, $value){
		$this->builtin($key, $value);

		return parent::__set($key, $value);
	}

	public function __call($method, $args){
		return $this->call_dynamic_method($method, ...$args);
	}

	public function to_array(){
		$this->filter_args();

		$name	= $this->name;

		$this->query_key	= $name === 'category' ? 'cat' : ($name == 'post_tag' ? 'tag_id' : $this->name.'_id');
		$this->column_name	= $name === 'category' ? 'categories' : ($name == 'post_tag' ? 'tags' : 'taxonomy-'.$this->name);
		$this->supports		= wpjam_array($this->supports ?? ['slug', 'description', 'parent'], fn($k, $v)=> is_numeric($k) ? [$v, true] : [$k, $v]);

		$this->{$this->levels == 1 ? 'remove_support' : 'add_support'}('parent');

		$struct	= $this->get_arg('permastruct');
		$struct	= $struct ? str_replace('%'.$this->query_key.'%', '%term_id%', trim($struct, '/')) : '';

		if($struct == '%'.$name.'%'){
			wpjam('no_base_taxonomy[]', $name);
		}elseif($struct){
			$this->rewrite	= $this->rewrite ?: true;

			if(str_contains($struct, '%term_id%')){
				$this->remove_support('slug');

				$this->query_var	??= false;

				add_action('registered_taxonomy_'.$name, fn()=> remove_rewrite_tag('%'.$name.'%'));

				add_filter($name.'_rewrite_rules', fn($rules)=> wpjam_map($rules, fn($v)=> str_replace('?term_id=', '?taxonomy='.$name.'&term_id=', $v)));
			}

			add_action('registered_taxonomy_'.$name, fn()=> add_permastruct($name, trim($struct, '/'), $this->rewrite));
		}

		if($this->_jam){
			$this->update_args(['rewrite'=>true, 'show_in_nav_menus'=>false, 'show_in_rest'=>true, 'show_admin_column'=>true, 'hierarchical'=>true], false);

			$this->rewrite	&& $this->process_arg('rewrite', fn($v)=> (is_array($v) ? $v : [])+['with_front'=>false, 'hierarchical'=>false]);
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

	public function supports($feature, ...$args){
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
			]+array_combine(['type', 'item_type'], $this->thumbnail_type == 'image' ? ['image', 'image'] : ['img', 'url']);
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

		return array_merge($fields ?? [], wpjam_fields($this->get_arg('_fields[]'), $id, $action_key));
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

	public function registered(){
		$this->_jam && wpjam_init(function(){
			is_admin() && $this->show_ui && add_filter('taxonomy_labels_'.$this->name,	function($labels){
				$labels		= (array)$labels;
				$name		= $labels['name'];
				$search		= $this->hierarchical ? ['分类', 'categories', 'Categories', 'Category'] : ['标签', 'Tag', 'tag'];
				$replace	= $this->hierarchical ? [$name, $name.'s', ucfirst($name).'s', ucfirst($name)] : [$name, ucfirst($name), $name];
				$labels		= wpjam_map($labels, fn($label)=> ($label && $label != $name) ? str_replace($search, $replace, $label) : $label);

				return array_merge($labels, (array)($this->labels ?: []));
			});

			register_taxonomy($this->name, $this->object_type, $this->to_array());
		
			wpjam_map($this->options ?:[], fn($option, $key)=> wpjam_register_term_option($key, $option+['taxonomy'=>$this->name]));
		});
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
					$key	= !empty($vars['name']) ? 'name' : '';
				}elseif(!str_contains($request, '/')){
					$type	= array_find(['author', 'category'], fn($k)=> str_starts_with($structure, '/%'.$k.'%'));
					$key	= $type && !str_starts_with($request, $type.'/') && !empty($vars[$type.'_name']) ? [$type.'_name', 'name'] : '';
				}
			}elseif(!empty($vars['pagename']) && !isset($_GET['page_id']) && !isset($_GET['pagename'])){
				$key	= 'pagename';
			}

			if(!empty($key)){
				foreach(wpjam('no_base_taxonomy[]') as $tax){
					$name	= is_taxonomy_hierarchical($tax) ? wp_basename($request) : $request;

					if(array_find(wpjam_get_all_terms($tax), fn($term)=> $term->slug == $name)){
						$vars	= wpjam_except($vars, $key);
						$vars	= ($tax == 'category' ? ['category_name'=>$name] : ['taxonomy'=>$tax, 'term'=>$name])+$vars;
						$vars	= array_filter(['paged'=>$paged ?? 0])+$vars;

						break;
					}
				}
			}

			return $vars;
		});
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
		}else{
			return $this->builtin($key);
		}
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
		$cb		= array_find(['post_status', 'status'], fn($k)=> ($data[$k] ?? '') === 'publish') ? [$this, 'is_publishable'] : null;
		$result	= $cb && method_exists(...$cb) ? wpjam_catch($cb) : true;

		if(is_wp_error($result) || !$result){
			return $result ?: new WP_Error('cannot_publish', '不可发布');
		}

		return $data ? self::update($this->id, $data, false) : true;
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
		$single	= $query && $query->is_main_query() && ($query->is_single($this->id) || $query->is_page($this->id));
		$filter	= $args['suppress_filter'] ? '' : ($args['list_query'] ? ($args['filter'] ?? '') : 'wpjam_post_json');

		$json	= wpjam_pick($this, ['id', 'type', 'post_type', 'status', 'views']);
		$json	+= ['icon'=>(string)$this->type_object->icon];
		$json	+= $this->viewable ? ['name'=> urldecode($this->name), 'post_url'=>str_replace(home_url(), '', get_permalink($this->id))] : [];
		$json	+= wpjam_fill(['title', 'excerpt'], fn($field)=> $this->supports($field) ? html_entity_decode(('get_the_'.$field)($this->id)) : '');
		$json	+= ['thumbnail'=>$this->get_thumbnail_url($args['thumbnail_size'])];
		$json	+= $this->supports('images') ? ['images'=>$this->get_images()] : [];
		$json	+= ['user_id'=>(int)$this->author];
		$json	+= $this->supports('author') ? ['author'=>wpjam_get_user($this->author)] : [];
		$json	+= $this->parse_for_json('date')+$this->parse_for_json('modified');
		$json	+= $this->password ? ['password_protected'=>true, 'password_required'=>post_password_required($this->id)] : [];
		$json	+= $this->supports('page-attributes') ? ['menu_order'=>(int)$this->menu_order] : [];
		$json	+= $this->supports('post-formats') ? ['format'=>get_post_format($this->id) ?: ''] : [];

		if(!$args['list_query']){
			$rest	= $single ? 'show_in_rest' : 'show_in_posts_rest';
			$json	+= array_reduce(wpjam_get_post_options($this->type, [$rest=>true]), fn($carry, $option)=> $carry+$option->prepare($this->id), []);
			$json	+= array_reduce($this->get_taxonomies([$rest=>true], 'names'), fn($carry, $tax)=> $carry+[$tax=>wpjam_get_terms(['terms'=>$this->get_terms($tax), 'taxonomy'=>$tax])], []);
		}

		if($single || $args['content_required']){
			if($this->supports('editor')){
				$json	+= ['content'=>$this->get_content(), 'multipage'=>(bool)$GLOBALS['multipage']];
				$json	+= $json['multipage'] ? ['numpages'=>$GLOBALS['numpages'], 'page'=>$GLOBALS['page']] : [];
			}else{
				$json	+= is_serialized($this->content) ? ['content'=>$this->get_unserialized()] : [];
			}
		}

		return $filter ? apply_filters($filter, $json, $this->id, $args) : $json;
	}

	public function value_callback($field){
		if($field == 'tax_input'){
			return wpjam_fill($this->get_taxonomies('names'), fn($tax)=> array_column($this->get_terms($tax), 'term_id'));
		}

		return $this->post->$field ?? $this->meta_get($field);
	}

	// update/insert 方法同时支持 title 和 post_xxx 字段写入 post 中，meta 字段只支持 meta_input
	// update_callback 方法只支持 post_xxx 字段写入 post 中，其他字段都写入 meta_input
	public function update_callback($data, $defaults){
		$result	= $this->save(wpjam_pull($data, [...array_keys($this->data), 'tax_input']));

		return (!is_wp_error($result) && $data) ? $this->meta_input($data, $defaults) : $result;
	}

	public static function get_default_args(){
		return [
			'suppress_filter'	=> false,
			'list_query'		=> false,
			'content_required'	=> false,
			'thumbnail_size'	=> null
		];
	}

	public static function get_instance($post=null, $type=null, $wp_error=false){
		$post	= $post ?: get_post();
		$post	= static::validate($post, $type);

		if(is_wp_error($post)){
			return $wp_error ? $post : null;
		}

		return self::instance($post->ID, fn($id)=> [wpjam_get_post_type_setting(get_post_type($id), 'model') ?: 'WPJAM_Post', 'create_instance']($id));
	}

	public static function validate($value, $type=null){
		$post	= $value ? self::get_post($value) : null;
		$type	??= static::get_current_post_type();

		if(!$post){
			return new WP_Error('invalid_id');
		}

		if(!post_type_exists($post->post_type) || ($type && $type !== 'any' && !in_array($post->post_type, (array)$type))){
			return new WP_Error('invalid_post_type');
		}

		return $post;
	}

	public static function get($post){
		if($data	= self::get_post($post, ARRAY_A)){
			$key	= 'post_content';
			$data	= [$key=>maybe_unserialize($data[$key]), 'id'=>$data['ID']]+$data;
			$data	+= wpjam_array($data, fn($k, $v)=> try_remove_prefix($k, 'post_') ? [$k, $v] : null);
		}

		return $data;
	}

	public static function update($post_id, $data, $validate=true){
		$result	= $validate ? wpjam_catch(fn()=> static::validate($post_id)) : null;

		return is_wp_error($result) ? $result : parent::update($post_id, $data);
	}

	public static function delete($post_id, $force=true){
		return wpjam_catch(fn()=> static::before_delete($post_id) || true ? (wp_delete_post($post_id, $force) ?: wpjam_throw('delete_error', '删除失败')) : null);
	}

	protected static function call_method($method, ...$args){
		if($method == 'get_meta_type'){
			return 'post';
		}elseif($method == 'insert'){
			$data	= $args[0];
			$type	= array_unique(array_filter([$data['post_type'] ?? '', static::get_current_post_type()]));
			$type	= count($type) <= 1 ? (array_first($type) ?: 'post') : '';

			$data['post_type']		= $type && post_type_exists($type) ? $type : wpjam_throw('invalid_post_type');
			$data['post_status']	??= current_user_can(get_post_type_object($type)->cap->publish_posts) ? 'publish' : 'draft';
			$data['post_author']	??= get_current_user_id();
			$data['post_date']		??= wpjam_date('Y-m-d H:i:s');

			return wpjam_try('wp_insert_post', wp_slash($data), true, true);
		}elseif($method == 'update'){
			return wpjam_try('wp_update_post', wp_slash(array_merge($args[1], ['ID'=>$args[0]])), true, true);
		}
	}

	protected static function sanitize_data($data, $post_id=0){
		$data	+= wpjam_array(get_class_vars('WP_Post'), fn($k, $v)=> try_remove_prefix($k, 'post_') && isset($data[$k]) ? ['post_'.$k, $data[$k]] : null);
		$key	= 'post_content';
		$data	= (is_array($data[$key] ?? '') ? [$key=>serialize($data[$key])] : [])+$data;

		return $data+(!$post_id && method_exists(static::class, 'is_publishable') ? ['post_status'=>'draft'] : []);
	}

	public static function get_by_ids($post_ids){
		return array_map('get_post', array_filter(self::update_caches($post_ids)));
	}

	public static function update_caches($post_ids, $update_term_cache=false, $update_meta_cache=false){
		if($post_ids = array_filter(wp_parse_id_list($post_ids))){
			_prime_post_caches($post_ids, $update_term_cache, $update_meta_cache);

			$posts	= wp_cache_get_multiple($post_ids, 'posts');
			$args	= compact('update_term_cache', 'update_meta_cache');

			do_action('wpjam_deleted_ids', 'post', array_keys(array_filter($posts, fn($v)=> !$v)));

			return wpjam_tap(array_filter($posts), fn($posts)=> do_action('wpjam_update_post_caches', $posts, $args));
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
		$id		= is_array($args) ? (int)($args[$type.'_id'] ?? 0) : $args;

		if($id === 'fields'){
			return get_post_type_object($type) ? [$type.'_id' => self::get_field(['post_type'=>$type, 'required'=>true])] : [];
		}

		if(!$id){
			return new WP_Error('invalid_id', [wpjam_get_post_type_setting($type, 'title')]);
		}

		return $item['platform'] == 'template' ? get_permalink($id) : str_replace('%post_id%', $id, $item['path']);
	}

	public static function get_field($args){
		$args['title'] ??= is_string($args['post_type'] ?? null) ? wpjam_get_post_type_setting($args['post_type'], 'title') : null;

		return $args+[
			'type'			=> 'text',
			'class'			=> 'all-options',
			'data_type'		=> 'post_type',
			'placeholder'	=> '请输入'.$args['title'].'ID或者输入关键字筛选'
		];
	}

	public static function with_field($method, $field, $value){
		$type	= $field->post_type;

		if($method == 'validate'){
			return (is_numeric($value) && wpjam_try(static::class.'::validate', $value, $type)) ? (int)$value : null;
		}elseif($method == 'parse'){
			return ($object = self::get_instance($value, $type)) ? $object->parse_for_json(['thumbnail_size'=>$field->size]) : null;
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
		$args['posts_per_page']	= -1;
		$args['post_status']	= wpjam_pull($args, 'status') ?: 'any';
		$args['post_type']		??= static::get_current_post_type();
		$args['monthnum']		= $args['month'];

		return array_reduce($GLOBALS['wp_query']->query($args), fn($carry, $post)=> wpjam_set($carry, wpjam_at($post->post_date, ' ', 0).'[]', $post), []);
	}

	public static function get_views(){
		if(get_current_screen()->base != 'edit'){
			$counts	= array_filter((array)wp_count_posts(static::get_current_post_type()));
			$views	= ['all'=>['filter'=>['status'=>null, 'show_sticky'=>null], 'label'=>'全部', 'count'=>array_sum($counts)]];

			return wpjam_reduce($counts, fn($c, $v, $k)=> $c+(($object	= get_post_status_object($k)) && $object->show_in_admin_status_list ? [$k=>['filter'=>['status'=>$k], 'label'=>$object->label, 'count'=>$v]] : []), $views);
		}
	}

	public static function filter_fields($fields, $id){
		return ($id && !is_array($id) && !isset($fields['title']) && !isset($fields['post_title']) ? ['title'=>['title'=>wpjam_get_post_type_setting(get_post_type($id), 'title').'标题', 'type'=>'view', 'value'=>get_the_title($id)]] : [])+$fields;
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
		}else{
			return $this->builtin($key);
		}
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
		$id		= $this->id;
		$tax	= $this->taxonomy;
		$json	= ['id'=>$id, 'name'=>html_entity_decode($this->name)]+wpjam_pick($this, ['name', 'taxonomy', 'count', ...(is_taxonomy_viewable($tax) ? ['slug'] : []), ...(is_taxonomy_hierarchical($tax) ? ['parent'] : []), 'description']);

		return apply_filters('wpjam_term_json', array_reduce(wpjam_get_term_options($tax), fn($c, $v)=> array_merge($c, $v->prepare($id)), $json), $id);
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
		if($term_ids = array_filter(wp_parse_id_list($term_ids))){
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
		$object	= isset($args['taxonomy']) && is_string($args['taxonomy']) ? wpjam_get_taxonomy($args['taxonomy']) : null;
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

class WPJAM_User extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'user_id'])){
			return $this->id;
		}elseif($key == 'role'){
			return array_first($this->roles);
		}elseif($key === 'data'){
			return get_userdata($this->id);
		}else{
			return $this->builtin($key);
		}
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
		return wpjam_call_for_blog($blog_id, function($role){
			if(!$this->roles){
				$this->user->add_role($role);
			}elseif(!in_array($role, $this->roles)){
				return new WP_Error('error', '你已有权限，如果需要更改权限，请联系管理员直接修改。');
			}

			return $this->user;
		}, $role);
	}

	public function login(){
		wp_set_auth_cookie($this->id, true, is_ssl());
		wp_set_current_user($this->id);
		do_action('wp_login', $this->user_login, $this->user);
	}

	public static function get_instance($id, $wp_error=false){
		$user	= self::validate($id);

		if(is_wp_error($user)){
			return $wp_error ? $user : null;
		}

		return self::instance($user->ID, fn($id)=> new self($id));
	}

	public static function validate($user_id){
		$user	= $user_id ? self::get_user($user_id) : null;

		return ($user && ($user instanceof WP_User)) ? $user : new WP_Error('invalid_user_id');
	}

	public static function update_caches($ids){
		if($ids	= array_filter(wp_parse_id_list($ids))){
			cache_users($ids);

			return array_map('get_userdata', $ids);
		}

		return [];
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
		$id	= is_array($args) ? (int)wpjam_get($args, 'author') : $args;

		if($id === 'fields'){
			return ['author' => ['type'=>'select', 'options'=>fn()=> wp_list_pluck(WPJAM_User::get_authors(), 'display_name', 'ID')]];
		}

		if(!$id){
			return new WP_Error('invalid_author', ['作者']);
		}

		return $item['platform'] == 'template' ? get_author_posts_url($id) : str_replace('%author%', $id, $item['path']);
	}

	public static function options_callback($field){
		return wp_list_pluck(self::get_authors(), 'display_name', 'ID');
	}

	public static function get($id){
		return ($user	= get_userdata($id)) ? $user->to_array() : [];
	}

	protected static function call_method($method, ...$args){
		if($method == 'get_meta_type'){
			return 'user';
		}elseif($method == 'create'){
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

			if($args['user_login']){
				$lock_key	= $args['user_login'].'_register_lock';
				$result		= wp_cache_add($lock_key, true, 'users', 5);

				if($result === false){
					return new WP_Error('error', '该用户名正在注册中，请稍后再试！');
				}
			}

			$data	= wpjam_pick($args, ['user_login', 'user_pass', 'user_email', 'role']);
			$data	+= $args['nickname'] ? ['nickname'=>$args['nickname'], 'display_name'=>$args['nickname']] : [];
			$id		= static::insert($data);

			return wpjam_tap(is_wp_error($id) ? $id : static::get_instance($id), fn()=> wp_cache_delete($lock_key, 'users'));
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
	public function __construct($type, $appid, $args=[]){
		parent::__construct($type.':'.$appid, array_merge($args, ['type'=>$type, 'appid'=>$appid, 'bind_key'=>wpjam_join('_', $type, $appid)]));
	}

	public function get_appid(){
		return $this->appid;
	}

	public function get_domain(){
		return $this->domain ?: $this->appid.'.'.$this->type;
	}

	protected function get_object($meta_type, $object_id){
		return wpjam_call('wpjam_get_'.$meta_type.'_object', $object_id);
	}

	public function get_openid($meta_type, $object_id){
		return get_metadata($meta_type, $object_id, $this->bind_key, true);
	}

	public function update_openid($meta_type, $object_id, $openid){
		return update_metadata($meta_type, $object_id, $this->bind_key, $openid);
	}

	public function delete_openid($meta_type, $object_id){
		return delete_metadata($meta_type, $object_id, $this->bind_key);
	}

	public function bind_openid($meta_type, $object_id, $openid){
		$bound_msg	= '已绑定其他账号，请先解绑再试！';
		$current	= $this->get_openid($meta_type, $object_id);

		if($current && $current != $openid){
			return new WP_Error('is_bound', $bound_msg);
		}

		$exists	= $this->get_by_openid($meta_type, $openid);

		if(is_wp_error($exists)){
			return $exists;
		}

		if($exists && $exists->id != $object_id){
			return new WP_Error('is_bound', $bound_msg);
		}

		$this->update_value($openid, $meta_type.'_id', $object_id);

		return $current ? true : $this->update_openid($meta_type, $object_id, $openid);
	}

	public function unbind_openid($meta_type, $object_id){
		$openid	= $this->get_openid($meta_type, $object_id);
		$openid	= $openid ?: $this->get_openid_by($meta_type.'_id', $object_id);

		if($openid){
			$this->delete_openid($meta_type, $object_id);
			$this->update_value($openid, $meta_type.'_id', 0);
		}

		return $openid;
	}

	public function get_by_openid($meta_type, $openid){
		if(!$this->get_user($openid)){
			return new WP_Error('invalid_openid');
		}

		$object	= $this->get_object($meta_type, $this->get_value($openid, $meta_type.'_id'));
		$object	= $object ?: (($meta = wpjam_get_by_meta($meta_type, $this->bind_key, $openid)) ? $this->get_object($meta_type, array_first($meta)[$meta_type.'_id']) : null);

		return $object ?: (($meta_type == 'user' && ($user_id = username_exists($openid))) ? wpjam_get_user_object($user_id) : null);
	}

	public function bind_by_openid($meta_type, $openid, $object_id){
		return $this->bind_openid($meta_type, $object_id, $openid);
	}

	public function unbind_by_openid($meta_type, $openid){
		if($object_id = $this->get_value($openid, $meta_type.'_id')){
			$this->delete_openid($meta_type, $object_id);
			$this->update_value($openid, $meta_type.'_id', 0);
		}
	}

	public function get_by_user_email($meta_type, $email){
		if($email && try_remove_suffix($email, '@'.$this->get_domain())){
			return $this->get_value($email, $meta_type.'_id');
		}
	}

	protected function get_value($openid, $key){
		if(($user = $this->get_user($openid)) && !is_wp_error($user)){
			return $user[$key] ?? null;
		}
	}

	protected function update_value($openid, $key, $value){
		return ($this->get_value($openid, $key) != $value) ? $this->update_user($openid, [$key=>$value]) : true;
	}

	public function get_user_email($openid){
		return $openid.'@'.$this->get_domain();
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

	public function get_openid_by($key, $value){
		return null;
	}

	public function get_user($openid){
		return ['openid'=>$openid];
	}

	public function update_user($openid, $user){
		return true;
	}

	public static function create($type, $appid, $args){
		if(is_array($args)){
			$object	= new static($type, $appid, $args);
		}else{
			$model	= $args;
			$object	= new $model($appid, []);
		}

		return self::register($object);
	}

	// compact
	protected function get_bind($openid, $bind, $unionid=false){
		return $this->get_value($openid, $bind);
	}

	public function get_email($openid){
		return $this->get_user_email($openid);
	}
}

class WPJAM_Qrcode_Bind extends WPJAM_Bind{
	public function verify_qrcode($scene, $code, $output=''){
		$qrcode	= $scene ? $this->cache_get($scene.'_scene') : null;

		if(!$qrcode){
			return new WP_Error('invalid_qrcode');
		}

		if(!$code || empty($qrcode['openid']) || $code != $qrcode['code']){
			return new WP_Error('invalid_code');
		}

		$this->cache_delete($scene.'_scene');

		return $output == 'openid' ? $qrcode['openid'] : $qrcode;
	}

	public function scan_qrcode($openid, $scene){
		$qrcode	= $scene ? $this->cache_get($scene.'_scene') : null;

		if(!$qrcode || (!empty($qrcode['openid']) && $qrcode['openid'] != $openid)){
			return new WP_Error('invalid_qrcode');
		}

		$this->cache_delete($qrcode['key'].'_qrcode');

		$cb	= !empty($qrcode['id']) ? ($qrcode['bind_callback'] ?? '') : '';

		if($cb && is_callable($cb)){
			return $cb($openid, $qrcode['id']);
		}

		$this->cache_set($scene.'_scene', ['openid'=>$openid]+$qrcode, 1200);

		return $qrcode['code'];
	}

	public function create_qrcode($key, $args=[]){
		return [];
	}
}

class WPJAM_User_Signup extends WPJAM_Register{
	public function __construct($name, $args=[]){
		if(is_array($args)){
			if(empty($args['type'])){
				$args['type']	= $name;
			}

			parent::__construct($name, $args);
		}
	}

	public function __call($method, $args){
		$object	= wpjam_get_bind($this->type, $this->appid);
		$args	= (str_ends_with($method, '_openid') || $method == 'get_by_user_email') ? ['user', ...$args] : $args;

		return $object->$method(...$args);
	}

	public function _compact($openid){	// 兼容代码
		if($this->name == 'weixin'){
			return $this->verify_code($openid['code']);
		}elseif($this->name == 'phone'){
			$result	= wpjam_verify_sms($openid['phone'], $openid['code']);

			return is_wp_error($result) ? $result : $openid['phone'];
		}
	}

	public function signup($openid, $args=null){
		if(is_array($openid)){
			$openid	= $this->_compact($openid);

			if(is_wp_error($openid)){
				return $openid;
			}
		}

		$user	= $this->get_by_openid($openid);

		if(is_wp_error($user)){
			return $user;
		}

		$args	= $args ?? [];
		$args	= apply_filters('wpjam_user_signup_args', $args, $this->type, $this->appid, $openid);

		if(is_wp_error($args)){
			return $args;
		}

		if(!$user){
			$is_create	= true;

			$args['user_login']	= $openid;
			$args['user_email']	= $this->get_user_email($openid);
			$args['nickname']	= $this->get_nickname($openid);

			$user	= WPJAM_User::create($args);

			if(is_wp_error($user)){
				return $user;
			}
		}else{
			$is_create	= false;
		}

		if(!$is_create && !empty($args['role'])){
			$blog_id	= $args['blog_id'] ?? 0;
			$result		= $user->add_role($args['role'], $blog_id);

			if(is_wp_error($result)){
				return $result;
			}
		}

		$this->bind($openid, $user->id);

		$user->login();

		do_action('wpjam_user_signuped', $user->data, $args);

		return $user;
	}

	public function bind($openid, $user_id=null){
		if(is_array($openid)){
			$openid	= $this->_compact($openid);

			if(is_wp_error($openid)){
				return $openid;
			}
		}

		$user_id	= $user_id ?? get_current_user_id();
		$result		= $this->bind_openid($user_id, $openid);

		if($result && !is_wp_error($result)){
			$user	= wpjam_get_user_object($user_id);

			if(($avatarurl = $this->get_avatarurl($openid)) && $avatarurl !== $user->avatarurl){
				$user->meta_input('avatarurl', $avatarurl);
			}

			if(($nickname = $this->get_nickname($openid)) && $nickname !== $user->nickname){
				$user->save(['nickname'=>$nickname, 'display_name'=>$nickname]);
			}
		}

		return $result;
	}

	public function unbind($user_id=null){
		return $this->unbind_openid($user_id ?? get_current_user_id());
	}

	public function get_fields($action='login', $for=''){
		return [];
	}

	public function get_attr($action='login', $for=''){
		$fields	= $this->get_fields($action, $for);

		if(is_wp_error($fields)){
			return $fields;
		}

		$attr	= [];

		if($action == 'bind'){
			if($this->get_openid(get_current_user_id())){
				$fields['action']['value']	= 'unbind';
				$attr['submit_text']		= '解除绑定';
			}else{
				$attr['submit_text']		= '立刻绑定';
			}
		}

		if($for != 'admin'){
			$attr	= array_merge($attr, wpjam_ajax($this->name.'-'.$action)->to_array());
			$fields	= wpjam_fields($fields)->render(['wrap_tag'=>'p']);
		}

		return $attr+['fields'=>$fields];
	}

	public function ajax_response($data){
		$action	= wpjam_pull($data, 'action');
		$method	= $action == 'login' ? 'signup' : $action;
		$args	= $method == 'unbind' ? [] : [$data];
		$result = wpjam_catch([$this, $method], ...$args);

		return is_wp_error($result) ? $result : true;
	}

	// public function register_bind_user_action(){
	// 	wpjam_register_list_table_action('bind_user', [
	// 		'title'			=> '绑定用户',
	// 		'capability'	=> is_multisite() ? 'manage_sites' : 'manage_options',
	// 		'callback'		=> [$this, 'bind_user_callback'],
	// 		'fields'		=> [
	// 			'nickname'	=> ['title'=>'用户',		'type'=>'view'],
	// 			'user_id'	=> ['title'=>'用户ID',	'type'=>'text',	'class'=>'all-options',	'description'=>'请输入 WordPress 的用户']
	// 		]
	// 	]);
	// }

	// public function bind_user_callback($openid, $data){
	// 	$user_id	= $data['user_id'] ?? 0;

	// 	if($user_id){
	// 		if(get_userdata($user_id)){
	// 			return $this->bind($openid, $user_id);
	// 		}else{
	// 			return new WP_Error('invalid_user_id');
	// 		}
	// 	}else{
	// 		return $this->unbind_by_openid($openid);
	// 	}
	// }

	public function registered(){
		foreach(['login', 'bind'] as $action){
			wpjam_ajax($this->name.'-'.$action, [
				'nopriv'	=> true,
				'callback'	=> [$this, 'ajax_response']
			]);

			wpjam_ajax('get-'.$this->name.'-'.$action, [
				'nopriv'	=> true,
				'verify'	=> false,
				'callback'	=> fn()=> $this->get_attr($action)
			]);
		}
	}

	public static function create($name, $args){
		$model	= wpjam_pull($args, 'model');
		$type	= wpjam_get($args, 'type') ?: $name;
		$appid	= wpjam_get($args, 'appid');

		if(!wpjam_get_bind($type, $appid) || !$model){
			return null;
		}

		if(is_object($model)){	// 兼容
			$model	= get_class($model);
		}

		$args['type']	= $type;

		return self::register(new $model($name, $args));
	}

	public static function on_admin_init(){
		if($objects	= self::get_registereds()){
			$binds	= array_filter($objects, fn($v)=> $v->bind);

			$binds && wpjam_add_menu_page([
				'parent'		=> 'users',
				'menu_slug'		=> 'wpjam-bind',
				'menu_title'	=> '账号绑定',
				'order'			=> 20,
				'capability'	=> 'read',
				'function'		=> 'tab',
				'tabs'			=> fn()=> wpjam_map($binds, fn($object)=> [
					'title'			=> $object->title,
					'capability'	=> 'read',
					'function'		=> 'form',
					'form'			=> fn()=> array_merge([
						'callback'		=> [$object, 'ajax_response'],
						'capability'	=> 'read',
						'validate'		=> true,
						'response'		=> 'redirect'
					], $object->get_attr('bind', 'admin'))
				])
			]);

			wpjam_add_admin_load([
				'base'		=> 'users',
				'callback'	=> fn()=> wpjam_register_list_table_column('openid', [
					'title'		=> '绑定账号',
					'order'		=> 20,
					'callback'	=> fn($user_id)=> wpjam_join('<br /><br />', wpjam_map($objects, fn($v)=> ($openid = $v->get_openid($user_id)) ? $v->title.'：<br />'.$openid : ''))
				])
			]);
		}
	}

	public static function on_login_init(){
		wp_enqueue_script('wpjam-ajax');

		$action		= wpjam_get_request_parameter('action', ['default'=>'login']);
		$objects	= in_array($action, ['login', 'bind']) ? self::get_registereds([$action=>true]) : [];

		if($objects){
			$type	= wpjam_get_request_parameter($action.'_type');

			if($action == 'login'){
				$type	= $type ?: apply_filters('wpjam_default_login_type', 'login');
				$type	= $type ?: ($_SERVER['REQUEST_METHOD'] == 'POST' ? 'login' : array_key_first($objects));

				isset($objects[$type]) && wpjam_call($objects[$type]->login_action);

				if(empty($_COOKIE[TEST_COOKIE])){
					$_COOKIE[TEST_COOKIE]	= 'WP Cookie check';
				}

				$objects['login']	= '使用账号和密码登录';
			}else{
				is_user_logged_in() || wp_die('登录之后才能执行绑定操作！');

				add_filter('login_display_language_dropdown', '__return_false');
			}

			$type	= ($type == 'login' || ($type && isset($objects[$type]))) ? $type : array_key_first($objects);
	
			foreach($objects as $name => $object){
				if($name == 'login'){
					$data	= ['type'=>'login'];
					$title	= $object;
				}else{
					$data	= ['type'=>$name, 'action'=>'get-'.$name.'-'.$action];
					$title	= $action == 'bind' ? '绑定'.$object->title : $object->login_title;
				}

				$append[]	= ['a', ['class'=>($type == $name ? 'current' : ''), 'data'=>$data], $title];
			}

			wp_enqueue_script('wpjam-login', wpjam_url(dirname(__DIR__).'/static/login.js'), ['wpjam-ajax']);

			wpjam_hook('echo', 'login_form', fn()=> wpjam_tag('p')->add_class('types')->data('action', $action)->append($append));
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

class WPJAM_User_Qrcode_Signup extends WPJAM_User_Signup{
	public function signup($data, $args=null){
		if(is_array($data)){
			$scene	= $data['scene'] ?? '';
			$code	= $data['code'] ?? '';
			$user	= apply_filters('wpjam_user_signup', null, 'qrcode', $scene, $code);

			if(!$user){
				$args	= $args ?? (wpjam_get($data, 'args') ?: []);
				$openid	= $this->verify_qrcode($scene, $code, 'openid');
				$user	= is_wp_error($openid) ? $openid : parent::signup($openid, $args);
			}

			is_wp_error($user) && do_action('wpjam_user_signup_failed', 'qrcode', $scene, $user);

			return $user;
		}

		return parent::signup($data, $args);
	}

	public function bind($data, $user_id=null){
		if(is_array($data)){
			$scene	= $data['scene'] ?? '';
			$code	= $data['code'] ?? '';
			$openid	= $this->verify_qrcode($scene, $code, 'openid');

			if(is_wp_error($openid)){
				return $openid;
			}
		}else{
			$openid	= $data;
		}

		return parent::bind($openid, $user_id);
	}

	public function get_fields($action='login', $for='admin'){
		if($action == 'bind'){
			$user_id	= get_current_user_id();

			if($openid = $this->get_openid($user_id)){
				$view	= ($avatar = $this->get_avatarurl($openid)) ? '<img src="'.str_replace('/132', '/0', $avatar).'" width="272" />'."<br />" : '';
				$view	.= ($nickname = $this->get_nickname($openid)) ? '<strong>'.$nickname.'</strong>' : '';

				return [
					'view'		=> ['type'=>'view',		'title'=>'绑定的微信账号',	'value'=>($view ?: $openid)],
					'action'	=> ['type'=>'hidden',	'value'=>'unbind'],
				];
			}

			$args	= [md5('bind_'.$user_id), ['id'=>$user_id]];
			$title	= '一键绑定';
		}else{
			$args	= [wp_generate_password(32, false, false)];
			$title	= '一键登录';
		}

		$qrcode	= $this->create_qrcode(...$args);

		return is_wp_error($qrcode) ? $qrcode : [
			'qrcode'	=> ['type'=>'view',		'title'=>'微信扫码，'.$title,	'value'=>'<img src="'.(wpjam_get($qrcode, 'qrcode_url') ?: wpjam_get($qrcode, 'qrcode')).'" width="272" />'],
			'code'		=> ['type'=>'number',	'title'=>'验证码',	'class'=>'input',	'required', 'size'=>20],
			'scene'		=> ['type'=>'hidden',	'value'=>$qrcode['scene']],
			'action'	=> ['type'=>'hidden',	'value'=>$action],
		];
	}
}