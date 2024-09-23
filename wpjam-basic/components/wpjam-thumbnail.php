<?php
/*
Name: 缩略图设置
URI: https://mp.weixin.qq.com/s/93TRBqSdiTzissW-c0bLRQ
Description: 缩略图设置可以无需预定义就可以进行动态裁图，并且还支持文章和分类缩略图
Version: 2.0
*/
class WPJAM_Thumbnail extends WPJAM_Option_Model{
	public static function get_fields(){
		$taxonomies		= array_filter(get_object_taxonomies('post', 'objects'), fn($v)=> ($v->show_ui && $v->public));
		$tax_options	= wpjam_map($taxonomies, fn($v, $k)=> wpjam_get_taxonomy_setting($k, 'title'));
		$show_if		= ['term_thumbnail_type', '!=', ''];
		$term_fields	= [
			'type'			=> ['options'=>[''=>'关闭分类缩略图', 'img'=>'本地媒体模式', 'image'=>'输入图片链接模式']],
			'taxonomies'	=> ['type'=>'checkbox',	'before'=>'支持的分类模式：',	'show_if'=>$show_if,	'options'=>$tax_options],
			'size'			=> ['type'=>'size',		'before'=>'缩略图尺寸：',		'show_if'=>$show_if],
		];

		$tax_options	= wpjam_map($tax_options, fn($v, $k)=> ['title'=>$v, 'show_if'=>['term_thumbnail_taxonomies', 'IN', $k, ['prefix'=>'', 'postfix'=>'']]]);
		$order_options	= [
			''			=> '请选择来源',
			'first'		=> '第一张图',
			'post_meta'	=> [
				'label'		=> '自定义字段',
				'fields'	=> ['post_meta'=>['type'=>'text',	'class'=>'all-options',	'placeholder'=>'请输入自定义字段的 meta_key']]
			],
			'term'		=>[
				'label'		=> '分类缩略图',
				'show_if'	=> ['term_thumbnail_type', 'IN', ['img','image'], ['prefix'=>'',	'postfix'=>'']],
				'fields'	=> ['taxonomy'=>['options'=>[''=>'请选择分类模式']+$tax_options]]
			]
		];

		$post_fields	= [
			'view'		=> ['type'=>'view',			'value'=>'首先使用文章特色图片，如未设置，将按照下面的顺序获取：'],
			'orders'	=> ['type'=>'mu-fields',	'group'=>true,	'max_items'=>5,	'fields'=>['type'=>['options'=>$order_options]]]
		];

		return [
			'auto'		=> ['title'=>'缩略图设置',	'type'=>'radio',	'sep'=>'<br />',	'options'=>[
				0	=>'修改主题代码，手动使用 <a href="https://blog.wpjam.com/m/wpjam-basic-thumbnail-functions/" target="_blank">WPJAM 的缩略图函数</a>。',
				1	=>'无需修改主题，自动应用 WPJAM 的缩略图设置。'
			]],
			'pdf'		=> ['title'=>'PDF预览图',		'name'=>'disable_pdf_preview',	'label'=>'屏蔽生成 PDF 预览图功能。'],
			'default'	=> ['title'=>'默认缩略图',	'type'=>'mu-img',	'item_type'=>'url'],
			'term_set'	=> ['title'=>'分类缩略图',	'prefix'=>'term_thumbnail',	'fields'=>$term_fields],
			'post_set'	=> ['title'=>'文章缩略图',	'prefix'=>'post_thumbnail',	'fields'=>$post_fields]
		];
	}

	public static function get_menu_page(){
		return [
			'parent'	=> 'wpjam-basic',
			'function'	=> 'option',
			'position'	=> 3,
			'summary'	=> __FILE__,
		];
	}

	public static function get_default(){
		$default	= self::get_setting('default', []);
		$default	= ($default && is_array($default)) ? $default[array_rand($default)] : '';

		return apply_filters('wpjam_default_thumbnail_url', $default);
	}

	public static function get_by_order($order, $object){
		if($order['type'] == 'first'){
			return $object->get_first_image_url();
		}elseif($order['type'] == 'post_meta'){
			if(!empty($order['post_meta'])){
				return $object->{$order['post_meta']};
			}
		}elseif($order['type'] == 'term'){
			if($order['taxonomy'] && $object->in_taxonomy($order['taxonomy'])){
				return wpjam_find($object->get_terms($order['taxonomy']), fn($term)=> wpjam_get_term_thumbnail_url($term), 'result');
			}
		}
	}

	public static function filter_post_thumbnail_url($url, $post){
		if(is_object_in_taxonomy($post, 'category')){
			$object	= wpjam_get_post_object($post);
			$value	= wpjam_find(self::get_setting('post_thumbnail_orders', []), fn($order)=> self::get_by_order($order, $object), 'result');

			return $value ?: ($url ?: self::get_default());
		}

		return $url;
	}

	public static function filter_has_post_thumbnail($has, $post){
		return $has ?: (self::get_setting('auto') && (bool)wpjam_get_post_thumbnail_url($post));
	}

	public static function filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr){
		$object	= wpjam_get_post_object($post_id);

		if($object && (!$object->supports('thumbnail') || empty($html))){
			if(self::get_setting('auto')){
				$src	= $object->get_thumbnail_url(wpjam_parse_size($size, 2));
			}elseif($object->supports('images')){
				$images	= $object->images;
				$src	= $images ? wpjam_get_thumbnail(reset($images), wpjam_parse_size($size, 2)) : ''; 
			}

			if(!empty($src)){
				$class	= is_array($size) ? join('x', $size) : $size;
				$attr	= wp_parse_args($attr, [
					'src'		=> $src,
					'class'		=> "attachment-$class size-$class wp-post-image",
					'decoding'	=> 'async',
					'loading'	=> 'lazy'
				]);

				return (string)wpjam_tag('img', $attr)->attr(wpjam_slice(wpjam_parse_size($size), ['width', 'height']));
			}
		}

		return $html;
	}

	public static function init(){
		$taxonomies	= self::get_setting('term_thumbnail_taxonomies', []);

		if($taxonomies){
			$size	= self::get_setting('term_thumbnail_size');

			if(!$size){
				$size	= [
					'width'		=> self::get_setting('term_thumbnail_width', 200),
					'height'	=> self::get_setting('term_thumbnail_height', 200)
				];

				self::update_setting('term_thumbnail_size', $size);
			}

			$args	=  ['thumbnail_type'=>self::get_setting('term_thumbnail_type'), 'thumbnail_size'=>$size];

			foreach($taxonomies as $taxonomy){
				$tax_object	= wpjam_get_taxonomy_object($taxonomy);

				if($tax_object && $tax_object->is_object_in('post')){
					$tax_object->add_support('thumbnail');
					$tax_object->update_args($args);
				}
			}
		}
	}

	public static function add_hooks(){
		add_filter('has_post_thumbnail',		[self::class, 'filter_has_post_thumbnail'], 10, 2);
		add_filter('wpjam_post_thumbnail_url',	[self::class, 'filter_post_thumbnail_url'], 1, 2);
		add_filter('post_thumbnail_html',		[self::class, 'filter_post_thumbnail_html'], 10, 5);

		add_filter('fallback_intermediate_image_sizes', fn($sizes) => self::get_setting('disable_pdf_preview') ? [] : $sizes);
	}
}

function wpjam_get_default_thumbnail_url($size='full', $crop=1){
	return wpjam_get_thumbnail(WPJAM_Thumbnail::get_default(), $size, $crop);
}

wpjam_register_option('wpjam-thumbnail', [
	'title'			=> '缩略图设置',
	'model'			=> 'WPJAM_Thumbnail',
	'site_default'	=> true,
]);
