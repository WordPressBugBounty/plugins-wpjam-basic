<?php
/*
Name: 文章设置
URI: https://mp.weixin.qq.com/s/XS3xk-wODdjX3ZKndzzfEg
Description: 文章设置把文章编辑的一些常用操作，提到文章列表页面，方便设置和操作
Version: 2.0
*/
class WPJAM_Basic_Posts extends WPJAM_Option_Model{
	public static function get_fields(){
		return [
			'excerpt'	=> ['title'=>'文章摘要',	'sep'=>'&emsp;',	'fields'=>['excerpt_optimization'	=> ['before'=>'未设时：', 'options'=>[
				0	=> 'WordPress 默认方式截取',
				1	=> ['label'=>'按照中文最优方式截取', 'fields'=>['excerpt_length'=>['before'=>'长度：', 'type'=>'number', 'class'=>'small-text', 'value'=>200, 'after'=>'中文算2个字节，英文算1个字节']]],
				2	=> '直接不显示摘要'
			]]]],
			'list'		=> ['title'=>'文章列表',	'fields'=>[
				'support'	=> ['before'=>'支持：',	'sep'=>'&emsp;',	'type'=>'fields',	'fields'=>[
					'post_list_ajax'			=> ['label'=>'全面 AJAX 操作',	'value'=>1],
					'upload_external_images'	=> ['label'=>'上传外部图片操作'],
				]],	
				'display'	=> ['before'=>'显示：',	'sep'=>'&emsp;',	'type'=>'fields',	'fields'=>[
					'post_list_set_thumbnail'	=> ['label'=>'文章缩略图',	'value'=>1],
					'post_list_author_filter'	=> ['label'=>'作者下拉选择框',	'value'=>1],
					'post_list_sort_selector'	=> ['label'=>'排序下拉选择框',	'value'=>1]
				]]
			]],
			'other'		=> ['title'=>'功能优化',	'fields'=>[
				'remove'	=> ['before'=>'移除：',	'sep'=>'&emsp;',	'type'=>'fields',	'fields'=>[
					'remove_post_tag'		=> ['label'=>'移除文章标签功能'],
					'remove_page_thumbnail'	=> ['label'=>'移除页面特色图片'],
				]],
				'add'		=> ['before'=>'增强：',	'sep'=>'&emsp;',	'type'=>'fields',	'fields'=>[
					'add_page_excerpt'	=> ['label'=>'增加页面摘要功能'],
					'404_optimization'	=> ['label'=>'增强404页面跳转'],
				]]
			]],
		];
	}

	public static function get_the_thumbnail($id, $base){
		if($base == 'edit'){
			$thumb	= get_the_post_thumbnail($id, [50,50]) ?: '';
		}else{
			$thumb	= wpjam_get_term_thumbnail_url($id, [100, 100]);
			$thumb	= $thumb ? wpjam_tag('img', ['class'=>'wp-term-image', 'src'=>$thumb, 'width'=>50, 'height'=>50]) : '';
		}

		return $thumb ?: '<span class="no-thumbnail">暂无图片</span>';
	}

	// 解决文章类型改变之后跳转错误的问题，原始函数：'wp_old_slug_redirect' 和 'redirect_canonical'
	public static function find_by_name($post_name, $post_type='', $post_status='publish'){
		$args		= array_filter(['post_status'=> $post_status]);
		$with_type	= $post_type ? $args+['post_type'=>$post_type] : [];
		$meta		= wpjam_get_by_meta('post', '_wp_old_slug', $post_name);
		$posts		= $meta ? WPJAM_Post::get_by_ids(array_column($meta, 'post_id')) : [];
		$for_meta	= $args+['post_type'=>array_values(array_diff(get_post_types(['public'=>true, 'exclude_from_search'=>false]), ['attachment']))];
		$post		= $posts ? wpjam_find([$with_type, $for_meta], fn($v)=>$v, fn($v)=> $v ? wpjam_find($posts, $v) : '') : null;

		if(!$post){
			$wpdb	= $GLOBALS['wpdb'];
			$types	= array_map('esc_sql', array_diff(get_post_types(['public'=>true, 'hierarchical'=>false, 'exclude_from_search'=>false]), ['attachment']));
			$where	= "post_type in ('".implode( "', '", $types)."') AND ".$wpdb->prepare("post_name LIKE %s", $wpdb->esc_like($post_name).'%');
			$ids	= $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE $where");
			$posts	= $ids ? WPJAM_Post::get_by_ids($ids) : [];
			$post	= $posts ? wpjam_find([$with_type, $args], fn($v)=>$v, fn($v)=> $v ? wpjam_find($posts, $v) : '') ?: array_first($posts) : null;
		}

		return $post ? $post->ID : null;
	}

	public static function upload_external_images($id){
		$bulk		= (int)wpjam_get_post_parameter('bulk') == 2;
		$content	= get_post($id)->post_content;

		if($content && !is_serialized($content) && preg_match_all('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			$urls		= array_unique($matches[1]);
			$replace	= wpjam_fetch_external_images($urls, $id);

			return $replace ? WPJAM_Post::update($id, ['post_content'=>str_replace($urls, $replace, $content)]) : ($bulk ? true : wp_die('文章中无外部图片'));
		}

		return $bulk ? true : wp_die('error', '文章中无图片');
	}

	public static function load($screen){
		$base	= $screen->base;
		$object	= wpjam_admin(in_array($base, ['post', 'edit', 'upload']) ? 'type_object' : 'tax_object');

		if($base == 'post'){
			self::get_setting('disable_trackbacks') && wpjam_admin('style', 'label[for="ping_status"]{display:none !important;}');
			self::get_setting('disable_autoembed') && $screen->is_block_editor && wpjam_admin('script', "wp.domReady(()=> wp.blocks.unregisterBlockType('core/embed'));\n");
		}elseif(in_array($base, ['edit', 'upload'])){
			$ptype		= $screen->post_type;
			$is_wc_shop	= defined('WC_PLUGIN_FILE') && str_starts_with($ptype, 'shop_');

			self::get_setting('post_list_author_filter', 1) && $object->supports('author') && add_action('restrict_manage_posts', function($ptype){ wp_dropdown_users([
					'name'				=> 'author',
					'capability'		=> 'edit_posts',
					'orderby'			=> 'post_count',
					'order'				=> 'DESC',
					'show_option_all'	=> $ptype == 'attachment' ? '所有上传者' : '所有作者',
					'selected'			=> (int)wpjam_get_data_parameter('author'),
					'hide_if_only_one_author'	=> true,
				]);
			}, 1);

			self::get_setting('post_list_sort_selector', 1) && !$is_wc_shop && add_action('restrict_manage_posts', function($ptype){
				[$columns, , $sortable]	= $GLOBALS['wp_list_table']->get_column_info();

				$orderby	= wpjam_reduce($sortable, fn($c, $v, $k)=> isset($columns[$k]) ? wpjam_set($c, $k, wp_strip_all_tags($columns[$k])) : $c, []);

				echo wpjam_fields([
					'orderby'	=> ['options'=>[''=>'排序','ID'=>'ID']+$orderby+($ptype != 'attachment' ? ['modified'=>'修改时间'] : [])],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				], [
					'fields_type'		=> '',
					'value_callback'	=> fn($k)=> wpjam_get_data_parameter($k, ['sanitize_callback'=>'sanitize_key'])
				])."\n";
			}, 99);

			if($ptype != 'attachment'){
				($action = 'upload_external_images') && self::get_setting($action) && wpjam_register_list_table_action($action, [
					'title'			=> '上传外部图片',
					'page_title'	=> '上传外部图片',
					'direct'		=> true,
					'confirm'		=> true,
					'bulk'			=> 2,
					'order'			=> 9,
					'callback'		=> [self::class, $action]
				]);

				wpjam_admin('style', '#bulk-titles, ul.cat-checklist{height:auto; max-height: 14em;}');

				if($ptype == 'page'){
					wpjam_admin('style', '.fixed .column-template{width:15%;}');

					wpjam_register_posts_column('template', '模板', 'get_page_template_slug');
				}
			}

			$width_columns	= wpjam_map($object->get_taxonomies(['show_admin_column'=>true]), fn($v)=> '.fixed .column-'.$v->column_name);
			$width_columns	= array_merge($width_columns, $object->supports('author') ? ['.fixed .column-author'] : []);

			$width_columns && wpjam_admin('style', implode(',', $width_columns).'{width:'.(['14', '12', '10', '8', '7'][count($width_columns)-1] ?? '6').'%}');

			wpjam_admin('style', '.fixed .column-date{width:100px;}');
		}elseif(in_array($base, ['edit-tags', 'term'])){
			$base == 'edit-tags' && wpjam_admin('style', ['.fixed th.column-slug{width:16%;}', '.fixed th.column-description{width:22%;}']);

			array_map(fn($v)=> $object->supports($v) ? '' : wpjam_admin('style', '.form-field.term-'.$v.'-wrap{display: none;}'), ['slug', 'description', 'parent']);	
		}

		if($base == 'edit-tags' || ($base == 'edit' && !$is_wc_shop)){
			wpjam_admin('script', self::get_setting('post_list_ajax', 1) ? <<<'JS'
			setTimeout(()=> {
				wpjam.delegate('#the-list', '.editinline');
				wpjam.delegate('#doaction');
			}, 300);
			JS : "wpjam.list_table.ajax 	= false;\n");

			$base == 'edit' && wpjam_admin('script', <<<'JS'
			wpjam.add_extra_logic(inlineEditPost, 'setBulk', ()=> $('#the-list').trigger('bulk_edit'));

			wpjam.add_extra_logic(inlineEditPost, 'edit', function(id){
				return ($('#the-list').trigger('quick_edit', typeof(id) === 'object' ? this.getId(id) : id), false);
			});
			JS);

			if(self::get_setting('post_list_ajax', 1)){
				$pairs[]	= ['/(<strong><a class="row-title"[^>]*>.*?<\/a>.*?)(<\/strong>)/is', '$1 [row_action name="set" class="row-action" dashicon="edit"]$2'];

				if($base == 'edit'){
					$pairs[]	= ['/(<td class=\'[^\']*('.array_reduce($object->get_taxonomies(['show_in_quick_edit'=>true]), fn($c, $t)=> $c.'|'.preg_quote($t->column_name), 'column-author').')[^\']*\'.*?>.*?)(<\/td>)/is', '$1 <a title="快速编辑" href="javascript:;" class="editinline row-action dashicons dashicons-edit"></a>$3'];
				}
			}

			if(self::get_setting('post_list_set_thumbnail', 1) 
				&& $object->supports($base == 'edit' ? 'thumbnail,images' : 'thumbnail')
				&& ($base != 'edit'|| $ptype != 'product' || !defined('WC_PLUGIN_FILE'))
			){
				$pairs[]	= ['<a class="row-title" ', fn($id)=> '[row_action name="set" class="wpjam-thumbnail-wrap" fallback="1"]'.self::get_the_thumbnail($id, $base).'[/row_action]'];
			}

			isset($pairs) && add_filter('wpjam_single_row', fn($row, $id)=> array_reduce($pairs, fn($c, $p)=> is_callable($p[1]) ? str_replace($p[0], $p[1]($id).$p[0], $c) : wpjam_preg_replace($p[0], $p[1], $c), $row), 10, 2);
		}
	}

	public static function init(){
		self::get_setting('remove_post_tag')		&& unregister_taxonomy_for_object_type('post_tag', 'post');
		self::get_setting('remove_page_thumbnail')	&& remove_post_type_support('page', 'thumbnail');
		self::get_setting('add_page_excerpt')		&& add_post_type_support('page', 'excerpt');

		self::get_setting('404_optimization') && add_filter('old_slug_redirect_post_id', fn($id)=> $id ?: self::find_by_name(get_query_var('name'), get_query_var('post_type')));

		if(self::get_setting('excerpt_optimization')){
			remove_filter('get_the_excerpt', 'wp_trim_excerpt');

			if(self::get_setting('excerpt_optimization') != 2){
				remove_filter('the_excerpt', 'wp_filter_content_tags');
				remove_filter('the_excerpt', 'shortcode_unautop');

				add_filter('get_the_excerpt', fn($text='', $post=null)=> $text ?: wpjam_get_post_excerpt($post, (self::get_setting('excerpt_length') ?: 200)), 9, 2);
			}
		}
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
		$args	= ['widget_id'=>$this->id]+$args;
		$type	= wpjam_pull($instance, 'type') ?: 'new';
		$title	= wpjam_pull($instance, 'title');
		$output	= $title ? $args['before_title'].$title.$args['after_title'] : '';

		echo $args['before_widget'].$output.('wpjam_get_'.$type.'_posts')($instance).$args['after_widget'];
	}

	public function form($instance){
		$ptypes	= ['post'=>__('Post')]+array_reduce(get_post_types(['_builtin'=>false]), fn($c, $k)=> is_post_type_viewable($k) && get_object_taxonomies($k) ? $c+[$k=>wpjam_get_post_type_setting($k, 'title')] : $c, []);

		$fields	= [
			'title'		=> ['type'=>'text',		'before'=>'列表标题：',	'class'=>'medium-text'],
			'type'		=> ['type'=>'select',	'before'=>'列表类型：',	'options'=>['new'=>'最新', 'top_viewd'=>'最高浏览']],
			'number'	=> ['type'=>'number',	'before'=>'文章数量：',	'class'=>'tiny-text',	'step'=>1,	'min'=>1],
			'class'		=> ['type'=>'text',		'before'=>'列表样式：',	'class'=>'',	'after'=>'请输入 ul 的 class'],
			'post_type'	=> ['type'=>'checkbox',	'before'=>'文章类型：',	'options'=>$ptypes],
			'thumb'		=> ['type'=>'checkbox',	'class'=>'checkbox',	'label'=>'显示缩略图'],
		];

		$fields	= count($ptypes) <= 1 ? wpjam_except($fields, 'post_type') : $fields;
		$fields	= wpjam_map($fields, fn($field, $key)=> $field+['id'=>$this->get_field_id($key), 'name'=>$this->get_field_name($key)]+(isset($instance[$key]) ? ['value'=>$instance[$key]] : []));

		echo str_replace('fieldset', 'span', wpjam_fields($fields)->render(['wrap_tag'=>'p']));
	}
}

wpjam_register_option('wpjam-basic', [
	'title'			=> '文章设置',
	'plugin_page'	=> 'wpjam-posts',
	'current_tab'	=> 'posts',
	'site_default'	=> true,
	'model'			=> 'WPJAM_Basic_Posts',
	'admin_load'	=> ['base'=>['edit', 'upload', 'post', 'edit-tags', 'term']],
	'menu_page'		=> ['parent'=>'wpjam-basic', 'position'=>4, 'function'=>'tab', 'tabs'=>[
		'posts'	=> ['title'=>'文章设置', 'order'=>20, 'summary'=>__FILE__, 'function'=>'option', 'option_name'=>'wpjam-basic']
	]],
]);
