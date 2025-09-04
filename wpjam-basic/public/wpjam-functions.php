<?php
// register
function wpjam_register($group, $name, $args=[]){
	if($group && $name){
		return wpjam_register_group($group)->add_object($name, $args);
	}
}

function wpjam_unregister($group, $name){
	$group && $name && wpjam_register_group($group)->remove_object($name);
}

function wpjam_get_registered($group, $name){
	if($group && $name){
		return wpjam_register_group($group)->get_object($name);
	}
}

function wpjam_get_registereds($group, $args=[]){
	return $group ? wpjam_register_group($group)->get_objects($args) : [];
}

function wpjam_register_group($group){
	return WPJAM_Register::get_group(['name'=>strtolower($group), 'config'=>[]]);
}

function wpjam_args($args=[]){
	return new WPJAM_Args($args);
}

// Instance
function wpjam_get_instance($group, $id, $cb=null){
	return wpjam('instance', $group.'.'.$id) ?? ($cb ? wpjam_add_instance($group, $id, $cb($id)) : null);
}

function wpjam_add_instance($group, $id, $object){
	!is_wp_error($object) && !is_null($object) && wpjam('instance', $group.'.'.$id, $object);

	return $object;
}

// Handler
function wpjam_get_handler($name, $args=[]){
	return WPJAM_Handler::get($name, $args);
}

function wpjam_call_handler($name, $method, ...$args){
	return WPJAM_Handler::call($name, $method, ...$args);
}

function wpjam_register_handler($name, $args=[]){
	return WPJAM_Handler::create($name, $args);
}

// Platform & path
function wpjam_register_platform($name, $args){
	return WPJAM_Platform::register($name, $args);
}

// ['weapp', 'template'], $ouput	// 从一组中（空则全部）根据顺序获取
// ['path'=>true], $ouput;			// 从已注册路径的根据优先级获取
function wpjam_get_current_platform($args=[], $output='name'){
	return WPJAM_Platform::get_current($args, $output);
}

function wpjam_get_current_platforms($output='names'){
	return WPJAM_Platform::get_by(['path'=>true], $output);
}

function wpjam_get_platform_options($output='bit'){
	return WPJAM_Platform::get_options($output);
}

function wpjam_get_path($platform, $page_key, $args=[]){
	return ($object = WPJAM_Platform::get($platform)) ? $object->get_path($page_key, $args) : '';
}

function wpjam_get_tabbar($platform, $page_key=''){
	return ($object	= WPJAM_Platform::get($platform)) ? $object->get_tabbar($page_key) : [];
}

function wpjam_get_page_keys($platform, $args=null, $operator='AND'){
	$object	= WPJAM_Platform::get($platform);

	if(is_string($args) && in_array($args, ['with_page', 'page'])){
		return $object ? wpjam_map($object->get_page(), fn($page, $pk)=> ['page'=>$page, 'page_key'=>$pk]) : [];
	}

	return $object ? array_keys(wpjam_filter($object->get_paths(), (is_array($args) ? $args : []), $operator)) : [];
}

function wpjam_register_path($name, ...$args){
	return WPJAM_Path::create($name, ...$args);
}

function wpjam_unregister_path($name, $platform=''){
	return WPJAM_Path::remove($name, $platform);
}

function wpjam_get_path_fields($platforms=null, $args=[]){
	return ($object = WPJAM_Platforms::get_instance($platforms)) ? $object->get_fields($args) : [];
}

function wpjam_parse_path_item($item, $platform=null, $suffix=''){
	return ($object = WPJAM_Platforms::get_instance($platform)) ? $object->parse_item($item, $suffix) : ['type'=>'none'];
}

function wpjam_validate_path_item($item, $platforms, $suffix='', $title=''){
	return ($object = WPJAM_Platforms::get_instance($platforms)) ? $object->validate_item($item, $suffix, $title) : true;
}

// Data Type
function wpjam_register_data_type($name, $args=[]){
	return WPJAM_Data_Type::register($name, $args);
}

function wpjam_get_data_type_object($name, $args=[]){
	return WPJAM_Data_Type::get_instance($name, $args);
}

function wpjam_get_post_id_field($post_type='post', $args=[]){
	return WPJAM_Post::get_field(['post_type'=> $post_type]+$args);
}

// Setting
function wpjam_setting($type, $option, ...$args){
	return WPJAM_Setting::get_instance($type, $option, ...$args);
}

function wpjam_get_setting($option, $name, $blog_id=0){
	return wpjam_setting($option, $blog_id)->get_setting($name);
}

function wpjam_update_setting($option, $name, $value='', $blog_id=0){
	return wpjam_setting($option, $blog_id)->update_setting($name, $value);
}

function wpjam_delete_setting($option, $name, $blog_id=0){
	return wpjam_setting($option, $blog_id)->delete_setting($name);
}

function wpjam_get_option($option, $blog_id=0){
	return wpjam_setting($option, $blog_id)->get_option();
}

function wpjam_update_option($option, $value, $blog_id=0){
	return wpjam_setting($option, $blog_id)->update_option($value);
}

function wpjam_get_site_setting($option, $name){
	return wpjam_setting('site', $option)->get_setting($name);
}

function wpjam_get_site_option($option){
	return wpjam_setting('site', $option)->get_option();
}

function wpjam_update_site_option($option, $value){
	return wpjam_setting('site', $option)->update_option($value);
}

// Option
function wpjam_register_option($name, $args=[]){
	return WPJAM_Option_Setting::create($name, $args);
}

function wpjam_get_option_object($name, $by=''){
	return WPJAM_Option_Setting::get($name, $by);
}

function wpjam_add_option_section($option_name, ...$args){
	return wpjam_get_option_object($option_name)->add_section(...$args);
}

// Verify TXT
function wpjam_get_verify_txt($name, $key=null){
	return WPJAM_Verify_TXT::get($name, $key);
}

function wpjam_set_verify_txt($name, $data){
	return WPJAM_Verify_TXT::set($name, $data);
}

// Meta Type
function wpjam_register_meta_type($name, $args=[]){
	return WPJAM_Meta_Type::register($name, $args);
}

function wpjam_get_meta_type_object($name){
	return WPJAM_Meta_Type::get($name);
}

function wpjam_register_meta_option($meta_type, $name, $args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->register_option($name, $args) : null;
}

function wpjam_unregister_meta_option($meta_type, $name){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->unregister_option($name) : null;
}

function wpjam_get_meta_options($meta_type, $args=[]){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_options($args) : [];
}

function wpjam_get_meta_option($meta_type, $name, $output='object'){
	$option	= ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_option($name) : null;

	return $output == 'object' ? $option : ($option ? $option->to_array() : []);
}

function wpjam_get_by_meta($meta_type, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_by_key(...$args) : [];
}

function wpjam_get_metadata($meta_type, $object_id, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->get_data_with_default($object_id, ...$args) : null;
}

function wpjam_update_metadata($meta_type, $object_id, $key, ...$args){
	return ($object = WPJAM_Meta_Type::get($meta_type)) ? $object->update_data_with_default($object_id, $key, ...$args) : null;
}

function wpjam_delete_metadata($meta_type, $object_id, $key){
	($object = WPJAM_Meta_Type::get($meta_type)) && $key && array_map(fn($k)=> $object->delete_data($object_id, $k), (array)$key);

	return true;
}

// LazyLoader
function wpjam_register_lazyloader($name, $args){
	wpjam_lazyloader($name, $args);
}

function wpjam_lazyloader($name, ...$args){
	return wpjam('lazyloader', $name, ...$args);
}

function wpjam_lazyload($name, $ids){
	if(!$name || !($ids	= array_filter($ids))){
		return;
	}

	if(is_array($name)){
		return array_walk($name, fn($n, $k)=> wpjam_lazyload($n, is_numeric($k) ? $ids : array_column($ids, $k)));
	}

	$ids	= array_unique($ids);

	if(in_array($name, ['blog', 'site'])){
		_prime_site_caches($ids);
	}elseif($name == 'post'){
		_prime_post_caches($ids, false, false);

		wpjam_lazyload('post_meta', $ids);
	}elseif($name == 'term'){
		_prime_term_caches($ids);
	}elseif($name == 'comment'){
		_prime_comment_caches($ids);
	}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
		wp_metadata_lazyloader()->queue_objects(substr($name, 0, -5), $ids);
	}else{
		$item	= wpjam_lazyloader($name) ?: [];

		if(!isset($item['data'])){
			if(!isset($item['filter']) && str_ends_with($name, '_meta')){
				$item	+= [
					'filter'	=> 'get_'.$name.'data',
					'callback'	=> fn($items)=> update_meta_cache(substr($name, 0, -5), $items)
				];
			}

			if(!empty($item['filter'])){
				$item['filter_fn']	= fn($pre)=> [wpjam_load_pending($name), $pre][1];

				add_filter($item['filter'], $item['filter_fn']);
			}
		}

		wpjam_lazyloader($name, ['data'=>array_merge($item['data'] ?? [], $ids)]+$item);
	}
}

function wpjam_load_pending($name, $callback=null){
	$item		= wpjam_lazyloader($name);
	$callback	= $item ? ($callback ?: ($item['callback'] ?? '')) : '';

	if($callback && !empty($item['data'])){
		!empty($item['filter']) && remove_filter($item['filter'], $item['filter_fn']);

		wpjam_call($callback, array_unique($item['data']));

		wpjam_lazyloader($name.'.data', []);
	}
}

// Post Type
function wpjam_register_post_type($name, $args=[]){
	return WPJAM_Post_Type::register($name, ['_jam'=>true]+$args);
}

function wpjam_get_post_type_object($name){
	return WPJAM_Post_Type::get(is_numeric($name) ? get_post_type($name) : $name);
}

function wpjam_add_post_type_field($post_type, $key, ...$args){
	($object = WPJAM_Post_Type::get($post_type)) && $object->add_field($key, ...$args);
}

function wpjam_remove_post_type_field($post_type, $key){
	($object = WPJAM_Post_Type::get($post_type)) && $object->remove_field($key);
}

function wpjam_get_post_type_setting($post_type, $key, $default=null){
	return ($object = WPJAM_Post_Type::get($post_type)) && isset($object->$key) ? $object->$key : $default;
}

function wpjam_update_post_type_setting($post_type, $key, $value){
	($object = WPJAM_Post_Type::get($post_type)) && ($object->$key	= $value);
}

if(!function_exists('get_post_type_support')){
	function get_post_type_support($post_type, $feature){
		return ($object = WPJAM_Post_Type::get($post_type)) ? $object->get_support($feature) : false;
	}
}

// Post Option
function wpjam_register_post_option($meta_box, $args=[]){
	return wpjam_register_meta_option('post', $meta_box, $args);
}

function wpjam_unregister_post_option($meta_box){
	wpjam_unregister_meta_option('post', $meta_box);
}

function wpjam_get_post_options($post_type='', $args=[]){
	return wpjam_get_meta_options('post', array_filter(['post_type'=>$post_type])+$args);
}

function wpjam_get_post_option($name, $output='object'){
	return wpjam_get_meta_option('post', $name, $output);
}

// Post Column
function wpjam_register_posts_column($name, ...$args){
	if(is_admin()){
		return wpjam_register_list_table_column($name, ['data_type'=>'post_type']+(is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)]));
	}
}

// Post
function wpjam_post($post, $wp_error=false){
	return WPJAM_Post::get_instance($post, null, $wp_error);
}

function wpjam_get_post_object($post, $post_type=null){
	return $post ? WPJAM_Post::get_instance($post, $post_type) : null;
}

function wpjam_get_post($post, $args=[]){
	if($object = WPJAM_Post::get_instance($post, null, false)){
		return $object->parse_for_json(is_a($args, 'WPJAM_Field') ? ['thumbnail_size'=>$args->size] : $args);
	}
}

function wpjam_get_posts($vars, $parse=false){
	[$args, $parse]	= is_array($parse) ? [$parse, true] : [[], $parse];

	if(is_scalar($vars) || wp_is_numeric_array($vars)){
		$ids	= wp_parse_id_list($vars);
		$posts	= WPJAM_Post::get_by_ids($ids);

		return $parse ? wpjam_array($ids, fn($k, $v)=> ($v = wpjam_get_post($v, $args)) ? [null, $v] : null) : $posts;
	}

	return $parse ? WPJAM_Posts::parse($vars, $args) : (WPJAM_Posts::query($vars))->posts;
}

function wpjam_get_post_views($post=null){
	return ($post = get_post($post)) ? (int)get_post_meta($post->ID, 'views', true) : 0;
}

function wpjam_update_post_views($post=null, $offset=1){
	return ($post = get_post($post)) ? update_post_meta($post->ID, 'views', wpjam_get_post_views($post)+$offset) : false;
}

function wpjam_get_post_excerpt($post=null, $length=0, $more=null){
	$post	= get_post($post);

	if(!$post){
		return '';
	}

	if($post->post_excerpt){
		return wp_strip_all_tags($post->post_excerpt, true);
	}

	if(is_serialized($post->post_content)){
		$excerpt	= '';
	}else{
		$filter_image_removed	= remove_filter('the_content', 'wp_filter_content_tags', 12);
		$filter_block_removed	= remove_filter('the_content', 'do_blocks', 9);

		$excerpt	= wpjam_get_post_content($post);

		if($filter_block_removed){
			add_filter('the_content', 'do_blocks', 9);
		}

		if($filter_image_removed){
			add_filter('the_content', 'wp_filter_content_tags', 12);
		}
	}

	$excerpt	= wp_strip_all_tags(excerpt_remove_footnotes(excerpt_remove_blocks(strip_shortcodes($excerpt))), true);
	$length		= $length ?: apply_filters('excerpt_length', 200);
	$more		??= apply_filters('excerpt_more', ' &hellip;');

	return mb_strimwidth($excerpt, 0, $length, $more, 'utf-8');
}

function wpjam_get_post_content($post=null, $raw=false){
	$content	= ($post = get_post($post)) ? get_the_content('', false, $post) : '';

	return (!$post || $raw) ? $content : apply_filters('the_content', str_replace(']]>', ']]&gt;', $content));
}

function wpjam_get_post_first_image_url($post=null, $size='full'){
	if(($post = get_post($post)) && $post->post_content){
		if(preg_match('/class=[\'"].*?wp-image-([\d]*)[\'"]/i', $post->post_content, $matches)){
			return wp_get_attachment_image_url($matches[1], $size);
		}

		if(preg_match('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $post->post_content, $matches)){
			return wpjam_get_thumbnail($matches[1], $size);
		}
	}

	return '';
}

function wpjam_get_post_thumbnail_url($post=null, $size='full', $crop=1){
	if($post = get_post($post)){
		foreach([
			'thumbnail'	=> fn()=> get_the_post_thumbnail_url($post->ID, 'full'),
			'images'	=> fn()=> wpjam_at(wpjam_get_post_images($post, false), 0),
			'filter'	=> fn()=> apply_filters('wpjam_post_thumbnail_url', '', $post)
		] as $k => $cb){
			if($k == 'filter' || post_type_supports($post->post_type, $k)){
				if($v	= $cb()){
					return wpjam_get_thumbnail($v, ($size ?: (wpjam_get_post_type_setting($post->post_type, 'thumbnail_size') ?: 'thumbnail')), $crop);
				}
			}
		}
	}

	return '';
}

function wpjam_get_post_images($post=null, $args=[]){
	$images	= ($post = get_post($post)) && post_type_supports($post->post_type, 'images') ? get_post_meta($post->ID, 'images', true) : [];

	if(!$images || $args === false){
		return $images ?: [];
	}

	$args	= wp_parse_args($args, ['large_size'=>'', 'thumbnail_size'=>'', 'full_size'=>true]);

	foreach(['large', 'thumbnail'] as $k){
		$v	= $args[$k.'_size'];

		if($v !== false){
			continue;
		}

		if(!$v){
			if($setting	= wpjam_get_post_type_setting($post->post_type, 'images_sizes')){
				$i	= $k == 'large' ? 0 : 1;
				$v	= $setting[$i];

				if($i && count($images) == 1){
					$image	= wpjam_at($images, 0);
					$query	= wpjam_parse_image_query($image);

					if(!$query){
						$query	= wpjam_get_image_size($image, 'url') ?: ['width'=>0, 'height'=>0];

						update_post_meta($post->ID, 'images', [add_query_arg($query, $image)]);
					}

					if(!empty($query['orientation'])){
						$i	= $query['orientation'] == 'landscape' ? 2 : 3;
						$v	= $setting[$i] ?? $v;
					}
				}
			}else{
				$v	= wpjam_get_post_type_setting($post->post_type, $k.'_size');
			}
		}

		$sizes[$k]	= $v ?: $k;
	}

	if(empty($sizes) || $args['full_size']){
		$sizes['full']	= 'full';
	}

	foreach($images as $image){
		$parsed	= array_map(fn($s)=> wpjam_get_thumbnail($image, $s), $sizes);

		if($query	= wpjam_parse_image_query($image)){
			$parsed	= array_merge($parsed, wpjam_pick($query, ['orientation', 'width', 'height'])+['width'=>0, 'height'=>0]);
		}

		if(isset($sizes['thumbnail'])){
			$size	= wpjam_parse_size($sizes['thumbnail']);
			$parsed	= array_reduce(['width', 'height'], fn($c, $k)=> $c+['thumbnail_'.$k=>$size[$k] ?? 0], $parsed);
		}

		$results[]	= count($sizes) == 1 ? reset($parsed) : $parsed;
	}

	return $results;
}

// Post Query
function wpjam_query($args=[]){
	return new WP_Query(wp_parse_args($args, ['no_found_rows'=>true, 'ignore_sticky_posts'=>true]));
}

function wpjam_parse_query($query, $args=[], $parse=true){
	return $query ? ['WPJAM_Posts', ($parse ? 'parse' : 'render')]($query, $args+['list_query'=>true]) : ($parse ? [] : '');
}

function wpjam_parse_query_vars($vars){
	return WPJAM_Posts::parse_query_vars($vars);
}

function wpjam_render_query($query, $args=[]){
	return WPJAM_Posts::render($query, $args);
}

function wpjam_pagenavi($total=0, $echo=true){
	$result	= '<div class="pagenavi">'.paginate_links(array_filter([
		'prev_text'	=> '&laquo;',
		'next_text'	=> '&raquo;',
		'total'		=> $total
	])).'</div>';

	return $echo ? wpjam_echo($result) : $result;
}

// $number
// $post_id, $args
function wpjam_get_related_posts_query(...$args){
	return WPJAM_Posts::get_related_query(...(count($args) <= 1 ? [get_the_ID(), ['number'=>$args[0] ?? 5]] : $args));
}

function wpjam_get_related_posts($post=null, $args=[], $parse=false){
	return wpjam_parse_query(wpjam_get_related_posts_query($post, $args), $args, $parse);
}

function wpjam_get_new_posts($args=[], $parse=false){
	return wpjam_parse_query(['posts_per_page'=>5, 'orderby'=>'date'], $args, $parse);
}

function wpjam_get_top_viewd_posts($args=[], $parse=false){
	return wpjam_parse_query(['posts_per_page'=>5, 'orderby'=>'meta_value_num', 'meta_key'=>'views'], $args, $parse);
}

// Taxonomy
function wpjam_register_taxonomy($name, ...$args){
	return WPJAM_Taxonomy::register($name, ['_jam'=>true]+(count($args) == 2 ? ['object_type'=>$args[0]]+$args[1] : $args[0]));
}

function wpjam_get_taxonomy_object($name){
	return WPJAM_Taxonomy::get(is_numeric($name) ? get_term_field('taxonomy', $id) : $name);
}

function wpjam_add_taxonomy_field($taxonomy, $key, ...$args){
	($object = WPJAM_Taxonomy::get($taxonomy)) && $object->add_field($key, ...$args);
}

function wpjam_remove_taxonomy_field($taxonomy, $key){
	($object = WPJAM_Taxonomy::get($taxonomy)) && $object->remove_fields($key);
}

function wpjam_get_taxonomy_setting($taxonomy, $key, $default=null){
	return (($object = WPJAM_Taxonomy::get($taxonomy)) && isset($object->$key)) ? $object->$key : $default;
}

function wpjam_update_taxonomy_setting($taxonomy, $key, $value){
	($object = WPJAM_Taxonomy::get($taxonomy)) && ($object->$key	= $value);
}

if(!function_exists('taxonomy_supports')){
	function taxonomy_supports($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->supports($feature) : false;
	}
}

if(!function_exists('add_taxonomy_support')){
	function add_taxonomy_support($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->add_support($feature) : null;
	}
}

if(!function_exists('remove_taxonomy_support')){
	function remove_taxonomy_support($taxonomy, $feature){
		return ($object = WPJAM_Taxonomy::get($taxonomy)) ? $object->remove_support($feature) : null;
	}
}	

function wpjam_get_taxonomy_query_key($taxonomy){
	return ['category'=>'cat', 'post_tag'=>'tag_id'][$taxonomy] ?? $taxonomy.'_id';
}

function wpjam_get_term_id_field($taxonomy='category', $args=[]){
	return WPJAM_Term::get_field(['taxonomy'=>$taxonomy]+$args);
}

// Term Option
function wpjam_register_term_option($name, $args=[]){
	return wpjam_register_meta_option('term', $name, $args);
}

function wpjam_unregister_term_option($name){
	wpjam_unregister_meta_option('term', $name);
}

function wpjam_get_term_options($taxonomy='', $args=[]){
	return wpjam_get_meta_options('term', array_filter(['taxonomy'=>$taxonomy])+$args);
}

function wpjam_get_term_option($name, $output='object'){
	return wpjam_get_meta_option('term', $name, $output);
}

// Term Column
function wpjam_register_terms_column($name, ...$args){
	if(is_admin()){
		$field	= is_array($args[0]) ? $args[0] : ['title'=>$args[0], 'callback'=>($args[1] ?? null)];

		return wpjam_register_list_table_column($name, array_merge($field, ['data_type'=>'taxonomy']));
	}
}

// Term
function wpjam_term($term, $wp_error=false){
	return WPJAM_Term::get_instance($term, null, $wp_error);
}

function wpjam_get_term_object($term, $taxonomy=''){
	return WPJAM_Term::get_instance($term, $taxonomy);
}

function wpjam_get_term($term, $args=[]){
	[$tax, $args]	= is_a($args, 'WPJAM_Field') ? [$args->taxonomy, []] : (is_array($args) ? [wpjam_pull($args, 'taxonomy'), $args] : [$args, []]);

	return ($object = WPJAM_Term::get_instance($term, $tax, false)) ? $object->parse_for_json($args) : null;
}

// $args, $max_depth
// $term_ids, $args
function wpjam_get_terms(...$args){
	if(is_string($args[0]) || wp_is_numeric_array($args[0])){
		$ids	= wp_parse_id_list(array_shift($args));
		$terms	= WPJAM_Term::get_by_ids($ids);
		$args	= array_shift($args) ?: [];
		$args	= is_array($args) ? $args : ['parse'=>$args];

		return wpjam_pull($args, 'parse') ? array_map(fn($term)=> wpjam_get_term($term, $args), $terms) : $terms;
	}

	return WPJAM_Terms::parse(array_merge($args[0], isset($args[1]) ? ['depth'=>$args[1]] : []));
}

function wpjam_get_all_terms($taxonomy){
	return get_terms([
		'suppress_filter'	=> true,
		'taxonomy'			=> $taxonomy,
		'hide_empty'		=> false,
		'orderby'			=> 'none',
		'get'				=> 'all'
	]);
}

function wpjam_get_term_thumbnail_url($term=null, $size='full', $crop=1){
	$term	??= get_queried_object();
	$thumb	= ($term = get_term($term)) ? (get_term_meta($term->term_id, 'thumbnail', true) ?: apply_filters('wpjam_term_thumbnail_url', '', $term)) : '';

	return $thumb ? wpjam_get_thumbnail($thumb, ($size ?: (wpjam_get_taxonomy_setting($term->taxonomy, 'thumbnail_size') ?: 'thumbnail')), $crop) : '';
}

if(!function_exists('get_term_taxonomy')){
	function get_term_taxonomy($id){
		return get_term_field('taxonomy', $id);
	}
}

if(!function_exists('get_term_level')){
	function get_term_level($id){
		return ($term	= get_term($id)) ? ($term->parent ? count(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) : 0) : null;
	}
}

if(!function_exists('get_term_depth')){
	function get_term_depth($id){
		if($tax	= get_term_taxonomy($id)){
			$id		= get_term($id)->term_id;
			$max	= array_reduce(get_term_children($id, $tax), fn($max, $child)=> max($max, count(get_ancestors($child, $tax, 'taxonomy'))), 0);

			return $max ? $max - get_term_level($id) : 0;
		}
	}
}

// User
function wpjam_user($user, $wp_error=false){
	return WPJAM_User::get_instance($user, $wp_error);
}

function wpjam_get_user_object($user){
	return wpjam_user($user);
}

function wpjam_get_user($user, $size=96){
	return ($object	= wpjam_user($user)) ? $object->parse_for_json($size) : null;
}

if(!function_exists('get_user_field')){
	function get_user_field($field, $user=null, $context='display'){
		$user	= get_userdata($user);

		return ($user && isset($user->$field)) ? sanitize_user_field($field, $user->$field, $user->ID, $context) : '';
	}
}

function wpjam_get_authors($args=[]){
	return WPJAM_User::get_authors($args);
}

// Bind
function wpjam_register_bind($type, $appid, $args){
	return wpjam_get_bind_object($type, $appid) ?: WPJAM_Bind::create($type, $appid, $args);
}

function wpjam_get_bind_object($type, $appid){
	return WPJAM_Bind::get($type.':'.$appid);
}

// User Signup
function wpjam_register_user_signup($name, $args){
	return WPJAM_User_Signup::create($name, $args);
}

function wpjam_get_user_signups($args=[], $output='objects', $operator='and'){
	return WPJAM_User_Signup::get_registereds($args, $output, $operator);
}

function wpjam_get_user_signup_object($name){
	return WPJAM_User_Signup::get($name);
}

// Comment
if(!function_exists('get_comment_parent')){
	function get_comment_parent($comment_id){
		return ($comment = get_comment($comment_id)) ? $comment->comment_parent : null;
	}
}

// File
function wpjam_url($dir, $scheme=null){
	$path	= str_replace([rtrim(ABSPATH, '/'), '\\'], ['', '/'], $dir);

	return $scheme == 'relative' ? $path : site_url($path, $scheme);
}

function wpjam_dir($url){
	return ABSPATH.str_replace(site_url('/'), '', $url);
}

function wpjam_file($value, $to, $from=null){
	return WPJAM_File::convert($value, $to, $from);
}

function wpjam_get_attachment_value($id, $field='file'){
	return WPJAM_File::get_by_id($id, $field);
}

function wpjam_restore_attachment_file($id, $url=''){
	return WPJAM_File::restore($id, $url);
}

function wpjam_upload($name, $args=[]){
	return WPJAM_File::upload($name, $args);
}

function wpjam_upload_bits($bits, $name, $media=true){
	$upload	= WPJAM_File::upload($name, ['bits'=>$bits]);

	return (is_wp_error($upload) || !$media) ? $upload : WPJAM_File::add_to_media($upload['file'], $upload+['post_id'=>is_numeric($media) ? $media : 0]);
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	return WPJAM_File::download($url, is_array($name) ? $name : compact('name', 'media', 'post_id'));
}

// 1. $img
// 2. $img, ['width'=>100, 'height'=>100]	// 这个为最标准版本
// 3. $img, 100x100
// 4. $img, 100
// 5. $img, [100,100]
// 6. $img, [100,100], $crop=1, $ratio=1
// 7. $img, 100, 100, $crop=1, $ratio=1
function wpjam_get_thumbnail($img, ...$args){
	$url	= ($img && is_numeric($img)) ? wp_get_attachment_url($img) : $img;

	return $url ? WPJAM_File::get_thumbnail($url, ...$args) : '';
}

function wpjam_get_thumbnail_args($size){
	return WPJAM_File::get_thumbnail('', $size);
}

// $size, $ratio
// $size, $ratio, [$max_width, $max_height]
// $size, [$max_width, $max_height]
function wpjam_parse_size($size, ...$args){
	return WPJAM_File::parse_size($size, ...$args);
}

function wpjam_bits($str){
	return 'data:'.finfo_buffer(finfo_open(), $str, FILEINFO_MIME_TYPE).';base64, '.base64_encode($str);
}

function wpjam_get_image_size($value, $type='id'){
	$size	= wpjam_file($value, 'size', $type);
	$size	= apply_filters('wpjam_image_size', $size, $value, $type);

	return $size ? array_map('intval', $size)+['orientation'=> $size['height'] > $size['width'] ? 'portrait' : 'landscape'] : $size;
}

function wpjam_is_image($value, $type=''){
	$type	= $type ?: (is_numeric($value) ? 'id' : 'url');

	if($type == 'url'){
		$url	= explode('?', $value)[0];
		$url	= str_ends_with($url, '#') ? substr($url, 0, -1) : $url;

		return preg_match('/\.('.implode('|', wp_get_ext_types()['image']).')$/i', $url);
	}elseif($type == 'file'){
		return !empty(wpjam_file($value, 'size'));
	}elseif($type == 'id'){
		return wp_attachment_is_image($value);
	}
}

function wpjam_parse_image_query($url){
	$query	= wp_parse_args(parse_url($url, PHP_URL_QUERY));

	return wpjam_map($query, fn($v, $k)=> in_array($k, ['width', 'height']) ? (int)$v : $v);
}

function wpjam_is_external_url($url, $scene=''){
	$host	= '//'.explode('//', site_url(), 2)[1];

	return apply_filters('wpjam_is_external_url', array_all(['http:', 'https:', ''], fn($v)=> !str_starts_with($url, $v.$host)), $url, $scene);
}

function wpjam_fetch_external_images(&$urls, $post_id=0){
	$args	= ['post_id'=>$post_id, 'media'=>(bool)$post_id, 'field'=>'url'];
		
	foreach($urls as $url){
		if($url && wpjam_is_external_url($url, 'fetch')){
			$download	= wpjam_download_url($url, $args);

			if(!is_wp_error($download)){
				$search[]	= $url;
				$replace[]	= $download;
			}	
		}
	}

	$urls	= $search ?? [];

	return $replace ?? [];
}

// Code
function wpjam_generate_verification_code($key, $group='default'){
	return (WPJAM_Cache::get_verification($group))->generate($key);
}

function wpjam_verify_code($key, $code, $group='default'){
	return (WPJAM_Cache::get_verification($group))->verify($key, $code);
}

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

function wpjam_accept_to_mime_types($accept){
	return WPJAM_Attr::accept_to_mime_types($accept);
}

// Tag
function wpjam_tag($tag='', $attr=[], $text=''){
	return new WPJAM_Tag($tag, $attr, $text);
}

function wpjam_wrap($text, $wrap='', ...$args){
	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? $wrap($text, ...$args) : $text;
		$wrap	= '';
	}

	return (is_a($text, 'WPJAM_Tag') ? $text : wpjam_tag('', [], $text))->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, $args=[]){
	$object	= WPJAM_Fields::create($fields);

	if($args){
		$echo	= wpjam_pull($args, 'echo', true);
		$result	= $object->render($args);

		return $echo ? wpjam_echo($result) : $result;
	}

	return $object;
}

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	return $args ? (isset($args['wrap_tag']) ? $object->wrap(wpjam_pull($args, 'wrap_tag'), $args) : $object->render($args)) : $object;
}

function wpjam_icon($icon){
	if(str_starts_with($icon, 'ri-')){
		return wpjam_tag('i', $icon);
	}

	return wpjam_tag('span', ['dashicons', (str_starts_with($icon, 'dashicons-') ? '' : 'dashicons-').$icon]);
}

// AJAX
function wpjam_register_ajax($name, $args){
	return WPJAM_AJAX::create($name, $args);
}

function wpjam_get_ajax_data_attr($name, $data=[], $output=null){
	$attr	= WPJAM_AJAX::get_attr($name, $data);

	return $output ? ($attr ?: []) : ($attr ? wpjam_attr($attr, 'data') : null);
}

// Upgrader
function wpjam_register_plugin_updater($hostname, $update_url){
	return WPJAM_Updater::create('plugin', $hostname, $update_url);
}

function wpjam_register_theme_updater($hostname, $update_url){
	return WPJAM_Updater::create('theme', $hostname, $update_url);
}

// Notice
function wpjam_add_admin_notice($notice, $blog_id=0){
	return WPJAM_Notice::add($notice, 'admin', $blog_id);
}

function wpjam_add_user_notice($user_id, $notice){
	return WPJAM_Notice::add($notice, 'user', $user_id);
}


