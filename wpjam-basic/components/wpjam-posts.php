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
				1	=> ['label'=>'按照中文最优方式截取', 'fields'=> ['excerpt_length'=>['before'=>'文章摘要长度：', 'type'=>'number', 'class'=>'small-text', 'value'=>200, 'after'=>'<strong>中文算2个字节，英文算1个字节</strong>']]],
				2	=> '直接不显示摘要'
			]]]],
			'list'		=> ['title'=>'文章列表',	'sep'=>'&emsp;',	'fields'=>[
				'post_list_support'			=> '支持：',
				'post_list_ajax'			=> ['value'=>1,	'label'=>'全面 AJAX 操作'],
				'upload_external_images'	=> ['value'=>0,	'label'=>'上传外部图片操作'],
				'post_list_display'			=> '<br />显示：',
				'post_list_set_thumbnail'	=> ['value'=>1,	'label'=>'文章缩略图'],
				'post_list_author_filter'	=> ['value'=>1,	'label'=>'作者下拉选择框'],
				'post_list_sort_selector'	=> ['value'=>1,	'label'=>'排序下拉选择框'],
			]],
			'other'		=> ['title'=>'功能优化',	'sep'=>'&emsp;',	'fields'=>[
				'other_remove_display'	=> '移除：',
				'remove_post_tag'		=> ['value'=>0,	'label'=>'移除文章标签功能'],
				'remove_page_thumbnail'	=> ['value'=>0,	'label'=>'移除页面特色图片'],
				'other_add_display'		=> '<br />增强：',
				'add_page_excerpt'		=> ['value'=>0,	'label'=>'增加页面摘要功能'],
				'404_optimization'		=> ['value'=>0,	'label'=>'增强404页面跳转'],
			]],
		]]];
	}

	public static function is_wc_shop($post_type){
		return defined('WC_PLUGIN_FILE') && str_starts_with($post_type, 'shop_');
	}

	public static function find_by_name($post_name, $post_type='', $post_status='publish'){
		$args		= $post_status && $post_status != 'any' ? ['post_status'=> $post_status] : [];
		$with_type	= $post_type && $post_type != 'any' ? $args+['post_type'=>$post_type] : $args;
		$for_meta	= $args+['post_type'=>array_values(array_diff(get_post_types(['public'=>true, 'exclude_from_search'=>false]), ['attachment']))];

		$meta	= wpjam_get_by_meta('post', '_wp_old_slug', $post_name);
		$posts	= $meta ? WPJAM_Post::get_by_ids(array_column($meta, 'post_id')) : [];

		if($with_type){
			if($post = wpjam_find($posts, $with_type)){
				return $post;
			}
		}

		if($post = wpjam_find($posts, $for_meta)){
			return $post;
		}

		$wpdb	= $GLOBALS['wpdb'];
		$types	= array_diff(get_post_types(['public'=>true, 'hierarchical'=>false, 'exclude_from_search'=>false]), ['attachment']);
		$where	= "post_type in ('" . implode( "', '", array_map('esc_sql', $types)) . "')";
		$where	.= ' AND '.$wpdb->prepare("post_name LIKE %s", $wpdb->esc_like($post_name).'%');

		$ids	= $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE $where");
		$posts	= $ids ? WPJAM_Post::get_by_ids($ids) : [];

		if($with_type){
			if($post = wpjam_find($posts, $with_type)){
				return $post;
			}
		}

		return $args ? wpjam_find($posts, $args) : reset($posts);
	}

	public static function upload_external_images($id){
		$bulk		= (int)wpjam_get_post_parameter('bulk') == 2;
		$content	= get_post($id)->post_content;

		if($content && !is_serialized($content) && preg_match_all('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			$img_urls	= array_unique($matches[1]);
			$replace	= wpjam_fetch_external_images($img_urls, $id);

			if($replace){
				return WPJAM_Post::update($id, ['post_content'=>str_replace($img_urls, $replace, $content)]);
			}

			return $bulk ? true : new WP_Error('error', '文章中无外部图片');
		}

		return $bulk ? true : new WP_Error('error', '文章中无图片');
	}

	public static function filter_single_row($row, $id){
		if(get_current_screen()->base == 'edit'){
			$object	= wpjam_admin('type_object');
			$row	= wpjam_preg_replace('/(<strong><a class="row-title"[^>]*>.*?<\/a>.*?)(<\/strong>$)/is', '$1 [row_action name="set" class="row-action" dashicon="edit"]$2', $row);

			if(self::get_setting('post_list_ajax', 1)){
				$columns	= array_map(fn($tax)=> 'column-'.preg_quote($tax->column_name, '/'), $object->get_taxonomies(['show_in_quick_edit'=>true]));
				$row		= wpjam_preg_replace('/(<td class=\'[^\']*('.implode('|', array_merge($columns, ['column-author'])).')[^\']*\'.*?>.*?)(<\/td>)/is', '$1 <a title="快速编辑" href="javascript:;" class="editinline row-action dashicons dashicons-edit"></a>$3', $row);
			}

			if(self::get_setting('post_list_set_thumbnail', 1) && array_any(['thumbnail', 'images'], fn($v)=> $object->supports($v))){
				$thumb	= get_the_post_thumbnail($id, [50,50]) ?: '';
			}
		}else{
			if(self::get_setting('post_list_set_thumbnail', 1) && wpjam_admin('tax_object')->supports('thumbnail')){
				$thumb	= wpjam_get_term_thumbnail_url($id, [100, 100]);
				$thumb	= $thumb ? wpjam_tag('img', ['class'=>'wp-term-image', 'src'=>$thumb, 'width'=>50, 'height'=>50]) : '';
			}
		}

		return isset($thumb) ? str_replace('<a class="row-title" ', '[row_action name="set" class="wpjam-thumbnail-wrap" fallback="1"]'.($thumb ?: '<span class="no-thumbnail">暂无图片</span>').'[/row_action]<a class="row-title" ', $row) : $row;
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
			is_multisite() && setcookie('wp-saving-post', $_POST['post_ID'].'-saved', time()+DAY_IN_SECONDS, ADMIN_COOKIE_PATH, false, is_ssl());

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

				return wpjam_get_post_excerpt($post, (self::get_setting('excerpt_length') ?: 200));
			}
		}

		return $text;
	}

	public static function filter_old_slug_redirect_post_id($post_id){
		// 解决文章类型改变之后跳转错误的问题
		// WP 原始解决函数 'wp_old_slug_redirect' 和 'redirect_canonical'
		if(!$post_id && self::get_setting('404_optimization')){
			if($post = self::find_by_name(get_query_var('name'), get_query_var('post_type'))){
				return $post->ID;
			}
		}

		return $post_id;
	}

	public static function load($screen){
		$base	= $screen->base;

		if($base == 'post'){
			self::get_setting('disable_trackbacks') && wpjam_admin('style', 'label[for="ping_status"]{display:none !important;}');
			self::get_setting('disable_autoembed') && $screen->is_block_editor && wpjam_admin('script', "wp.domReady(()=> wp.blocks.unregisterBlockType('core/embed'));\n");
		}elseif(in_array($base, ['edit', 'upload'])){
			wpjam_admin('style', '.fixed .column-date{width:8%;}');

			$ptype	= $screen->post_type;
			$object	= wpjam_admin('type_object');

			self::get_setting('post_list_author_filter', 1) && $object->supports('author') && add_action('restrict_manage_posts', fn($ptype)=> wp_dropdown_users([
				'name'						=> 'author',
				'capability'				=> 'edit_posts',
				'orderby'					=> 'post_count',
				'order'						=> 'DESC',
				'hide_if_only_one_author'	=> true,
				'show_option_all'			=> $ptype == 'attachment' ? '所有上传者' : '所有作者',
				'selected'					=> (int)wpjam_get_data_parameter('author')
			]), 1);

			self::get_setting('post_list_sort_selector', 1) && !self::is_wc_shop($ptype) && add_action('restrict_manage_posts', function($ptype){
				[$columns, , $sortable]	= $GLOBALS['wp_list_table']->get_column_info();

				$orderby	= wpjam_array($sortable, fn($k, $v)=> isset($columns[$k]) ? [$v[0], wp_strip_all_tags($columns[$k])] : null);

				echo wpjam_fields([
					'orderby'	=> ['options'=>[''=>'排序','ID'=>'ID']+$orderby+($ptype != 'attachment' ? ['modified'=>'修改时间'] : [])],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				], [
					'fields_type'		=> '',
					'value_callback'	=> fn($k)=> wpjam_get_data_parameter($k, ['sanitize_callback'=>'sanitize_key'])
				])."\n";
			}, 99);

			if($ptype != 'attachment'){
				add_filter('wpjam_single_row',	[self::class, 'filter_single_row'], 10, 2);

				self::get_setting('upload_external_images') && wpjam_register_list_table_action('upload_external_images', [
					'title'			=> '上传外部图片',
					'page_title'	=> '上传外部图片',
					'direct'		=> true,
					'confirm'		=> true,
					'bulk'			=> 2,
					'order'			=> 9,
					'callback'		=> [self::class, 'upload_external_images']
				]);

				wpjam_admin('style', '#bulk-titles, ul.cat-checklist{height:auto; max-height: 14em;}');

				if($ptype == 'page'){
					wpjam_admin('style', '.fixed .column-template{width:15%;}');

					wpjam_register_posts_column('template', '模板', 'get_page_template_slug');
				}elseif($ptype == 'product'){
					self::get_setting('post_list_set_thumbnail', 1) && defined('WC_PLUGIN_FILE') && wpjam_admin('removed_columns[]', 'thumb');
				}
			}

			$width_columns	= wpjam_map($object->get_taxonomies(['show_admin_column'=>true]), fn($v)=> '.fixed .column-'.$v->column_name);
			$width_columns	= array_merge($width_columns, $object->supports('author') ? ['.fixed .column-author'] : []);

			$width_columns && wpjam_admin('style', implode(',', $width_columns).'{width:'.(['14%', '12%', '10%', '8%', '7%'][count($width_columns)-1] ?? '6%').'}');
		}elseif(in_array($base, ['edit-tags', 'term'])){
			if($base == 'edit-tags'){
				add_filter('wpjam_single_row',	[self::class, 'filter_single_row'], 10, 2);

				wpjam_admin('style', [
					'.fixed th.column-slug{width:16%;}',
					'.fixed th.column-description{width:22%;}',
					'.form-field.term-parent-wrap p{display: none;}',
					'.form-field span.description{color:#666;}'
				]);
			}

			array_map(fn($v)=> wpjam_admin('tax_object')->supports($v) ? '' : wpjam_admin('style', '.form-field.term-'.$v.'-wrap{display: none;}'), ['slug', 'description', 'parent']);	
		}

		if($base == 'edit-tags' || ($base == 'edit' && !self::is_wc_shop($ptype))){
			wpjam_admin('script', self::get_setting('post_list_ajax', 1) ? <<<'EOD'
			$(window).load(function(){
				wpjam.delegate('#the-list', '.editinline');
				wpjam.delegate('#doaction');
			});
			EOD : "wpjam.list_table.ajax 	= false;\n");

			$base == 'edit' && wpjam_admin('script', <<<'EOD'
			wpjam.add_extra_logic(inlineEditPost, 'setBulk', ()=> $('#the-list').trigger('bulk_edit'));

			wpjam.add_extra_logic(inlineEditPost, 'edit', function(id){
				return ($('#the-list').trigger('quick_edit', typeof(id) === 'object' ? this.getId(id) : id), false);
			});
			EOD);
		}
	}

	public static function init(){
		self::get_setting('remove_post_tag')		&& unregister_taxonomy_for_object_type('post_tag', 'post');
		self::get_setting('remove_page_thumbnail')	&& remove_post_type_support('page', 'thumbnail');
		self::get_setting('add_page_excerpt')		&& add_post_type_support('page', 'excerpt');
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

		echo empty($instance['title']) ? '' : $args['before_title'].wpjam_pull($instance, 'title').$args['after_title'];

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

		wpjam_fields(wpjam_map($fields, function($field, $key){
			$field['id']	= $this->get_field_id($key);
			$field['name']	= $this->get_field_name($key);

			if(isset($instance[$key])){
				$field['value']	= $instance[$key];
			}

			return $field;
		}), ['wrap_tag'=>'p']);
	}
}

wpjam_register_option('wpjam-basic', [
	'title'			=> '文章设置',
	'plugin_page'	=> 'wpjam-posts',
	'current_tab'	=> 'posts',
	'site_default'	=> true,
	'model'			=> 'WPJAM_Basic_Posts',
	'admin_load'	=> ['base'=>['edit', 'upload', 'post', 'edit-tags', 'term']],
	'menu_page'		=> [
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
	],
]);
