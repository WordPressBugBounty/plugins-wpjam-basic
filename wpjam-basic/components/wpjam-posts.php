<?php
/*
Name: 文章设置
URI: https://mp.weixin.qq.com/s/XS3xk-wODdjX3ZKndzzfEg
Description: 文章设置把文章编辑的一些常用操作，提到文章列表页面，方便设置和操作
Version: 2.0
*/
class WPJAM_Basic_Posts extends WPJAM_Option_Model{
	public static function get_sections(){
		return ['posts'	=>['title'=>'文章设置',	'fields'=>[
			'excerpt'	=> ['title'=>'文章摘要',	'fields'=>['excerpt_optimization'=>['before'=>'未设文章摘要：',	'options'=>[
				0	=> 'WordPress 默认方式截取',
				1	=> [
					'label'		=> '按照中文最优方式截取',
					'fields'	=> ['excerpt_length'=>['before'=>'文章摘要长度：', 'type'=>'number', 'value'=>200, 'after'=>'<strong>中文算2个字节，英文算1个字节</strong>']]
				],
				2	=> '直接不显示摘要'
			]]]],
			'list'		=> ['title'=>'文章列表',	'fields'=>[
				'post_list_support'	=> ['type'=>'fields',	'before'=>'支持：',	'sep'=>'&emsp;',	'fields'=>[
					'post_list_ajax'			=> ['value'=>1,	'label'=>'全面 AJAX 操作'],
					'upload_external_images'	=> ['value'=>0,	'label'=>'上传外部图片操作'],
				]],
				'post_list_display'	=> ['type'=>'fields',	'before'=>'显示：',	'sep'=>'&emsp;',	'fields'=>[
					'post_list_set_thumbnail'	=> ['value'=>1,	'label'=>'文章缩略图'],
					'post_list_author_filter'	=> ['value'=>1,	'label'=>'作者下拉选择框'],
					'post_list_sort_selector'	=> ['value'=>1,	'label'=>'排序下拉选择框'],
				]]
			]],
			'other'		=> ['title'=>'功能优化',	'type'=>'fields',	'sep'=>'&emsp;',	'fields'=>[
				'remove_post_tag'		=> ['value'=>0,	'label'=>'移除文章标签功能'],
				'remove_page_thumbnail'	=> ['value'=>0,	'label'=>'移除页面特色图片'],
				'add_page_excerpt'		=> ['value'=>0,	'label'=>'增加页面摘要功能'],
				'404_optimization'		=> ['value'=>0,	'label'=>'增强404页面跳转'],
			]],
		]]];
	}

	public static function get_menu_page(){
		return [
			'parent'		=> 'wpjam-basic',
			'menu_slug'		=> 'wpjam-posts',
			'position'		=> 4,
			'function'		=> 'tab',
			'tabs'			=> ['posts'=>[
				'title'			=> '文章设置',
				'function'		=> 'option',
				'option_name'	=> 'wpjam-basic',
				'site_default'	=> true,
				'order'			=> 20,
				'summary'		=> __FILE__,
			]]
		];
	}

	public static function get_admin_load(){
		return [
			'base'	=> ['edit', 'upload', 'post', 'edit-tags', 'term'],
			'model'	=> self::class
		];
	}

	public static function is_wc_shop($post_type){
		return defined('WC_PLUGIN_FILE') && str_starts_with($post_type, 'shop_');
	}

	public static function find_by_name($post_name, $post_type='', $post_status='publish'){
		$args			= $post_status && $post_status != 'any' ? ['post_status'=> $post_status] : [];
		$args_with_type	= ($post_type && $post_type != 'any') ? array_merge($args, ['post_type'=>$post_type]) : [];
		$post_types		= get_post_types(['public'=>true, 'exclude_from_search'=>false]);
		$post_types		= array_diff($post_types, ['attachment']);
		$args_for_meta	= array_merge($args, ['post_type'=>array_values($post_types)]);

		$meta	= wpjam_get_by_meta('post', '_wp_old_slug', $post_name);
		$posts	= $meta ? WPJAM_Post::get_by_ids(array_column($meta, 'post_id')) : [];

		if($args_with_type){
			$post	= wpjam_find($posts, $args_with_type);

			if($post){
				return $post;
			}
		}

		$post	= wpjam_find($posts, $args_for_meta);

		if($post){
			return $post;
		}

		$wpdb		= $GLOBALS['wpdb'];
		$post_types	= get_post_types(['public'=>true, 'hierarchical'=>false, 'exclude_from_search'=>false]);
		$post_types	= array_diff($post_types, ['attachment']);

		$where		= "post_type in ('" . implode( "', '", array_map('esc_sql', $post_types)) . "')";
		$where		.= ' AND '.$wpdb->prepare("post_name LIKE %s", $wpdb->esc_like($post_name).'%');

		$post_ids	= $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE $where");
		$posts		= $post_ids ? WPJAM_Post::get_by_ids($post_ids) : [];

		if($args_with_type){
			$post	= wpjam_find($posts, $args_with_type);

			if($post){
				return $post;
			}
		}

		return $args ? wpjam_find($posts, $args) : reset($posts);
	}

	public static function upload_external_images($id){
		$object		= wpjam_post($id);
		$content	= $object->content;

		if($content && !is_serialized($content) && preg_match_all('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			$img_urls	= array_unique($matches[1]);
			$replace	= wpjam_fetch_external_images($img_urls, $id);

			if($replace){
				$result	= $object->save(['post_content'=>str_replace($img_urls, $replace, $content)]);
			}else{
				$result	= new WP_Error('error', '文章中无外部图片');
			}
		}else{
			$result	= new WP_Error('error', '文章中无图片');
		}

		return is_wp_error($result) && (int)wpjam_get_post_parameter('bulk') == 2 ? true : $result;
	}

	public static function filter_single_row($single_row, $id){
		$object	= get_screen_option('object');

		if(get_current_screen()->base == 'edit'){
			if(self::get_setting('post_list_set_thumbnail', 1) && ($object->supports('thumbnail') || $object->supports('images'))){
				$thumbnail	= get_the_post_thumbnail($id, [50,50]) ?: '<span class="no-thumbnail">暂无图片</span>';
				$thumbnail	= '[row_action name="set" class="wpjam-thumbnail-wrap" fallback="1"]'.$thumbnail.'[/row_action]';
				$single_row	= str_replace('<a class="row-title" ', $thumbnail.'<a class="row-title" ', $single_row);
			}

			$set_action	= '[row_action name="set" class="row-action"]<span class="dashicons dashicons-edit"></span>[/row_action]';
			$single_row = preg_replace('/(<strong>.*?<a class=\"row-title\".*?<\/a>.*?)(<\/strong>)/is', '$1 '.$set_action.'$2', $single_row);

			if(self::get_setting('post_list_ajax', 1)){
				$quick_edit	= '<a title="快速编辑" href="javascript:;" class="editinline row-action"><span class="dashicons dashicons-edit"></span></a>';

				if($object->supports('author')){
					$single_row = preg_replace('/(<td class=\'author column-author\' .*?>.*?)(<\/td>)/is', '$1 '.$quick_edit.'$2', $single_row);
				}

				foreach($object->get_taxonomies(['show_in_quick_edit'=>true]) as $tax_object){
					$single_row	= preg_replace('/(<td class=\''.$tax_object->column_name.' column-'.$tax_object->column_name.'\' .*?>.*?)(<\/td>)/is', '$1 '.$quick_edit.'$3', $single_row);
				}
			}
		}else{
			if(self::get_setting('post_list_set_thumbnail', 1) && $object->supports('thumbnail')){
				$thumb_url	= wpjam_get_term_thumbnail_url($id, [100, 100]);

				if($thumb_url){
					$thumbnail	= wpjam_tag('img', ['class'=>'wp-term-image', 'src'=>$thumb_url, 'width'=>50, 'height'=>50]);
				}else{
					$thumbnail	= wpjam_tag('span', ['no-thumbnail'], '暂无图片');
				}

				$thumbnail	= '[row_action name="set" class="wpjam-thumbnail-wrap" fallback="1"]'.$thumbnail.'[/row_action]';
				$single_row	= str_replace('<a class="row-title" ', $thumbnail.'<a class="row-title" ', $single_row);
			}
		}

		return $single_row;
	}

	public static function filter_content_save_pre($content){
		if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE){
			return $content;
		}

		if(!preg_match_all('/<img.*?src=\\\\[\'"](.*?)\\\\[\'"].*?>/i', $content, $matches)){
			return $content;
		}

		$img_urls	= array_unique($matches[1]);

		if($replace	= wpjam_fetch_external_images($img_urls)){
			if(is_multisite()){
				setcookie('wp-saving-post', $_POST['post_ID'].'-saved', time()+DAY_IN_SECONDS, ADMIN_COOKIE_PATH, false, is_ssl());
			}

			$content	= str_replace($img_urls, $replace, $content);
		}

		return $content;
	}

	public static function filter_get_the_excerpt($text='', $post=null){
		$optimization	= self::get_setting('excerpt_optimization');

		if(empty($text) && $optimization){
			remove_filter('get_the_excerpt', 'wp_trim_excerpt');

			if($optimization != 2){
				remove_filter('the_excerpt', 'wp_filter_content_tags');
				remove_filter('the_excerpt', 'shortcode_unautop');

				$length	= self::get_setting('excerpt_length') ?: 200;
				$text	= wpjam_get_post_excerpt($post, $length);
			}
		}

		return $text;
	}

	public static function filter_old_slug_redirect_post_id($post_id){
		// 解决文章类型改变之后跳转错误的问题
		// WP 原始解决函数 'wp_old_slug_redirect' 和 'redirect_canonical'
		if(empty($post_id) && self::get_setting('404_optimization')){
			$post	= self::find_by_name(get_query_var('name'), get_query_var('post_type'));

			return $post ? $post->ID : $post_id;
		}

		return $post_id;
	}

	public static function load($screen){
		$base		= $screen->base;
		$object		= $screen->get_option('object');
		$style		= [];
		$scripts	= '';

		if($base == 'post'){
			if(self::get_setting('disable_trackbacks')){
				$style[]	= 'label[for="ping_status"]{display:none !important;}';
			}

			if(self::get_setting('disable_autoembed') && $screen->is_block_editor){
				$scripts	= wpjam_remove_pre_tab("
				wp.domReady(function(){
					wp.blocks.unregisterBlockType('core/embed');
				});
				", 4);
			}
		}elseif(in_array($base, ['edit', 'upload'])){
			$style	= ['.fixed .column-date{width:8%;}'];
			$ptype	= $screen->post_type;

			add_action('restrict_manage_posts',	fn($ptype)=> wpjam_map($object->get_taxonomies(['show_admin_column'=>true]), fn($tax_object)=> $tax_object->filterable ? $tax_object->dropdown() : null), 1);

			if(self::get_setting('post_list_author_filter', 1) && $object->supports('author')){
				add_action('restrict_manage_posts',	fn($ptype)=> wp_dropdown_users([
					'name'						=> 'author',
					'capability'				=> 'edit_posts',
					'orderby'					=> 'post_count',
					'order'						=> 'DESC',
					'hide_if_only_one_author'	=> true,
					'show_option_all'			=> $ptype == 'attachment' ? '所有上传者' : '所有作者',
					'selected'					=> (int)wpjam_get_data_parameter('author')
				]), 1);
			}

			if(self::get_setting('post_list_sort_selector', 1) && !self::is_wc_shop($ptype)){
				add_action('restrict_manage_posts',	function($ptype){
					list($columns, $hidden, $sortables)	= $GLOBALS['wp_list_table']->get_column_info();

					$options	= wpjam_array($sortables, fn($k, $v)=> isset($columns[$k]) ? [$v[0], $columns[$k]] : null);
					$options	= [''=>'排序','ID'=>'ID']+$options+($ptype != 'attachment' ? ['modified'=>'修改时间'] : []);
					$orderby	= wpjam_get_data_parameter('orderby', ['sanitize_callback'=>'sanitize_key']);
					$order		= wpjam_get_data_parameter('order', ['sanitize_callback'=>'sanitize_key', 'default'=>'DESC']);

					echo "\n".wpjam_field(['key'=>'orderby',	'value'=>$orderby,	'options'=>$options]);
					echo "\n".wpjam_field(['key'=>'order',		'value'	=>$order,	'options'=>['desc'=>'降序','asc'=>'升序']])."\n";
				}, 99);
			}

			if($ptype != 'attachment'){
				add_filter('wpjam_single_row',	[self::class, 'filter_single_row'], 10, 2);

				if($object->in_taxonomy('category')){
					add_filter('disable_categories_dropdown', '__return_true');
				}

				if(self::get_setting('upload_external_images')){
					wpjam_register_list_table_action('upload_external_images', [
						'title'			=> '上传外部图片',
						'page_title'	=> '上传外部图片',
						'direct'		=> true,
						'confirm'		=> true,
						'bulk'			=> 2,
						'order'			=> 9,
						'callback'		=> [self::class, 'upload_external_images']
					]);
				}

				$style[]	= '#bulk-titles, ul.cat-checklist{height:auto; max-height: 14em;}';

				if($ptype == 'page'){
					$style[]	= '.fixed .column-template{width:15%;}';

					wpjam_register_posts_column('template', '模板', 'get_page_template_slug');
				}elseif($ptype == 'product'){
					if(self::get_setting('post_list_set_thumbnail', 1) && defined('WC_PLUGIN_FILE')){
						wpjam_unregister_list_table_column('thumb');
					}
				}
			}

			$width_columns	= wpjam_map($object->get_taxonomies(['show_admin_column'=>true]), fn($v)=> '.fixed .column-'.$v->column_name);
			$width_columns	= array_merge($width_columns, $object->supports('author') ? ['.fixed .column-author'] : []);

			$count = count($width_columns);

			if($count){
				$width		= ['14%', '12%', '10%', '8%', '7%'][$count-1] ?? '6%';
				$style[]	= implode(',', $width_columns).'{width:'.$width.'}';
			}
		}elseif(in_array($base, ['edit-tags', 'term'])){
			if($base == 'edit-tags'){
				add_filter('wpjam_single_row',	[self::class, 'filter_single_row'], 10, 2);

				$style	= [
					'.fixed th.column-slug{ width:16%; }',
					'.fixed th.column-description{width:22%;}',
					'.form-field.term-parent-wrap p{display: none;}',
					'.form-field span.description{color:#666;}'
				];
			}

			$style	= array_merge($style, wpjam_map(['slug', 'description', 'parent'], fn($v)=> $object->supports($v) ? '' : '.form-field.term-'.$v.'-wrap{display: none;}'));	
		}

		if($base == 'edit-tags' || ($base == 'edit' && !self::is_wc_shop($ptype))){
			if(self::get_setting('post_list_ajax', 1)){
				wpjam_add_item('page_setting', 'ajax_list_action', true);

				$scripts	.= wpjam_remove_pre_tab("
				$(window).load(function(){
					if($('#the-list').length){
						$.wpjam_delegate_events('#the-list', '.editinline');
					}

					if($('#doaction').length){
						$.wpjam_delegate_events('#doaction');
					}
				});
				", 4);
			}

			$scripts	.= wpjam_remove_pre_tab("
			let observer = new MutationObserver(function(mutations){
				if($('#the-list .inline-editor').length > 0){
					let tr_id	= $('#the-list .inline-editor').attr('id');

					if(tr_id == 'bulk-edit'){
						$('#the-list').trigger('bulk_edit');
					}else{
						let id	= tr_id.split('-')[1];

						if(id > 0){
							$('#the-list').trigger('quick_edit', id);
						}
					}
				}
			});

			observer.observe(document.querySelector('body'), {childList: true, subtree: true});
			", 3);
		}

		if($scripts){
			wp_add_inline_script('jquery', "jQuery(function($){".$scripts."\n});");
		}

		if($style){
			wp_add_inline_style('list-tables', "\n".implode("\n", $style));
		}
	}

	public static function init(){
		if(self::get_setting('remove_post_tag')){
			unregister_taxonomy_for_object_type('post_tag', 'post');
		}

		if(self::get_setting('remove_page_thumbnail')){
			remove_post_type_support('page', 'thumbnail');
		}

		if(self::get_setting('add_page_excerpt')){
			add_post_type_support('page', 'excerpt');
		}
	}

	public static function add_hooks(){
		add_filter('get_the_excerpt',			[self::class, 'filter_get_the_excerpt'], 9, 2);
		add_filter('old_slug_redirect_post_id',	[self::class, 'filter_old_slug_redirect_post_id']);
	}
}

class WPJAM_Posts_Widget extends WP_Widget{
	public function __construct() {
		parent::__construct('wpjam-posts', 'WPJAM - 文章列表', [
			'classname'						=> 'widget_posts',
			'customize_selective_refresh'	=> true,
			'show_instance_in_rest'			=> false,
		]);

		$this->alt_option_name = 'widget_wpjam_posts';
	}

	public function widget($args, $instance){
		$args['widget_id']	??= $this->id;

		echo $args['before_widget'];

		if(!empty($instance['title'])){
			echo $args['before_title'].wpjam_pull($instance, 'title').$args['after_title'];
		}

		$instance['posts_per_page']	= wpjam_pull($instance, 'number') ?: 5;

		$type	= wpjam_pull($instance, 'type') ?: 'new';

		if($type == 'new'){
			echo wpjam_get_new_posts($instance);
		}elseif($type == 'top_viewd'){
			echo wpjam_get_top_viewd_posts($instance);
		}

		echo $args['after_widget'];
	}

	public function form($instance){
		$types	= ['new'=>'最新', 'top_viewd'=>'最高浏览'];
		$ptypes	= ['post'=>__('Post')];

		foreach(get_post_types(['_builtin'=>false]) as $ptype){
			if(is_post_type_viewable($ptype) && get_object_taxonomies($ptype)){
				$ptypes[$ptype]	= wpjam_get_post_type_setting($ptype, 'title');
			}
		}

		$fields		= [
			'title'		=> ['type'=>'text',		'title'=>'标题：',		'class'=>'widefat'],
			'type'		=> ['type'=>'select',	'title'=>'列表类型：',	'class'=>'widefat',	'options'=>$types],
			'post_type'	=> ['type'=>'checkbox',	'title'=>'文章类型：',	'options'=>$ptypes],
			'number'	=> ['type'=>'number',	'before'=>'文章数量：	',	'class'=>'medium-text',	'step'=>1,	'min'=>1],
			'class'		=> ['type'=>'text',		'before'=>'列表Class：',	'class'=>'medium-text'],
			'thumb'		=> ['type'=>'checkbox',	'class'=>'checkbox',	'label'=>'显示缩略图'],
		];

		if(count($ptypes) <= 1){
			unset($fields['post_type']);
		}

		foreach($fields as $key => &$field){
			$field['id']	= $this->get_field_id($key);
			$field['name']	= $this->get_field_name($key);

			if(isset($instance[$key])){
				$field['value']	= $instance[$key];
			}
		}

		wpjam_fields($fields, ['wrap_tag'=>'p']);
	}
}

wpjam_register_option('wpjam-basic', [
	'title'			=> '文章设置',
	'plugin_page'	=> 'wpjam-posts',
	'current_tab'	=> 'posts',
	'site_default'	=> true,
	'model'			=> 'WPJAM_Basic_Posts',
]);
