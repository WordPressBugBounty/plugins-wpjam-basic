<?php
/*
Name: 缩略图设置
URI: https://mp.weixin.qq.com/s/93TRBqSdiTzissW-c0bLRQ
Description: 缩略图设置可以无需预定义就可以进行动态裁图，并且还支持文章和分类缩略图
Version: 2.0
*/
class WPJAM_Thumbnail extends WPJAM_Option_Model{
	public static function get_fields(){
		$tax_options	= wpjam_reduce(get_object_taxonomies('post', 'objects'), fn($c, $v, $k)=> $v->show_ui && $v->public ? wpjam_set($c, $k, wpjam_get_taxonomy_setting($k, 'title')) : $c, []);

		$show_if		= ['term_thumbnail_type', '!=', ''];
		$term_fields	= [
			'type'			=> ['options'=>[''=>'关闭分类缩略图', 'img'=>'本地媒体模式', 'image'=>'输入图片链接模式']],
			'taxonomies'	=> ['type'=>'checkbox',	'before'=>'支持的分类模式：',	'show_if'=>$show_if,	'options'=>$tax_options],
			'size'			=> ['type'=>'size',		'before'=>'缩略图尺寸：',		'show_if'=>$show_if],
		];

		$tax_options	= wpjam_map($tax_options, fn($v, $k)=> ['title'=>$v, 'show_if'=>['term_thumbnail_taxonomies', 'IN', $k]]);
		$order_options	= [
			''			=> '请选择来源',
			'first'		=> '第一张图',
			'post_meta'	=> [
				'label'		=> '自定义字段',
				'fields'	=> ['post_meta'=>['type'=>'text',	'class'=>'all-options',	'placeholder'=>'请输入自定义字段的 meta_key']]
			],
			'term'		=>[
				'label'		=> '分类缩略图',
				'show_if'	=> ['term_thumbnail_type', 'IN', ['img','image']],
				'fields'	=> ['taxonomy'=>['options'=>[''=>'请选择分类模式']+$tax_options]]
			]
		];

		return [
			'auto'		=> ['title'=>'缩略图设置',	'type'=>'radio',	'direction'=>'column',	'options'=>[
				0	=>'修改主题代码，手动使用 <a href="https://blog.wpjam.com/m/wpjam-basic-thumbnail-functions/" target="_blank">WPJAM 的缩略图函数</a>。',
				1	=>'无需修改主题，自动应用 WPJAM 的缩略图设置。'
			]],
			'pdf'		=> ['title'=>'PDF预览图',		'name'=>'disable_pdf_preview',	'label'=>'屏蔽 PDF 生成预览图功能。'],
			'default'	=> ['title'=>'默认缩略图',	'type'=>'mu-img',	'item_type'=>'url'],
			'term_set'	=> ['title'=>'分类缩略图',	'prefix'=>'term_thumbnail',	'fields'=>$term_fields],

			'post_thumbnail_orders'	=> ['title'=>'文章缩略图',	'type'=>'mu-fields',	'group'=>true,	'max_items'=>5,	'before'=>'首先使用文章特色图片，如未设置，将按照下面的顺序获取：<br />',	'fields'=>['type'=>['options'=>$order_options]]]
		];
	}

	public static function get_default(){
		$default	= self::get_setting('default', []);
		$default	= $default && is_array($default) ? $default[array_rand($default)] : '';

		return apply_filters('wpjam_default_thumbnail_url', $default);
	}

	public static function get_post_thumbnail_url($post, $order=null){
		if($order){
			if($order['type'] == 'first'){
				return wpjam_get_post_first_image_url($post);
			}elseif($order['type'] == 'post_meta'){
				if(!empty($order['post_meta'])){
					return get_post_meta($post->ID, $order['post_meta'], true);
				}
			}elseif($order['type'] == 'term'){
				if($order['taxonomy'] && is_object_in_taxonomy($post, $order['taxonomy'])){
					return wpjam_find(get_the_terms($post, $order['taxonomy']) ?: [], fn($v)=> $v, fn($term)=> wpjam_get_term_thumbnail_url($term));
				}
			}
		}else{
			foreach(self::get_setting('post_thumbnail_orders') ?: [] as $order){
				if($order && ($url	= self::get_post_thumbnail_url($post, $order))){
					return $url;
				}
			}
		}
	}

	public static function get_post_thumbnail_html($post_id, $size, $attr){
		if(self::get_setting('auto')){
			$src	= wpjam_get_post_thumbnail_url($post_id, wpjam_parse_size($size, 2));
		}else{
			$images	= wpjam_get_post_images($post_id, false);
			$src	= $images ? wpjam_get_thumbnail(array_first($images), wpjam_parse_size($size, 2)) : ''; 
		}

		if($src){
			$name	= is_array($size) ? join('x', $size) : $size;
			$attr	= wp_parse_args($attr, ['src'=>$src, 'class'=>"attachment-$name size-$name wp-post-image", 'decoding'=>'async', 'loading'=>'lazy']);

			return (string)wpjam_tag('img', $attr)->attr(wpjam_pick(wpjam_parse_size($size), ['width', 'height']));
		}
	}

	public static function init(){
		foreach(self::get_setting('term_thumbnail_taxonomies') ?: [] as $tax){
			if(is_object_in_taxonomy('post', $tax) && ($object = wpjam_get_taxonomy($tax))){
				$object->add_support('thumbnail')->update_args(wpjam_fill(['thumbnail_type', 'thumbnail_size'], fn($k)=> self::get_setting('term_'.$k)));
			}
		}
	}

	public static function add_hooks(){
		self::get_setting('disable_pdf_preview') && add_filter('fallback_intermediate_image_sizes', fn() => []);

		self::get_setting('auto') && add_filter('has_post_thumbnail', fn($has, $post)=> $has ?: (bool)wpjam_get_post_thumbnail_url($post), 10, 2);

		wpjam_hook('wpjam_post_thumbnail_url', [
			'check'		=> fn($url, $post)=> is_object_in_taxonomy($post, 'category'),
			'callback'	=> fn($url, $post)=> self::get_post_thumbnail_url($post) ?: ($url ?: self::get_default())
		], 1, 2);

		wpjam_hook('post_thumbnail_html',		[
			'check'		=> fn($html, $post_id)=> !post_type_supports(get_post_type($post_id), 'thumbnail') || empty($html),
			'callback'	=> fn($html, $post_id, $post_thumbnail_id, $size, $attr)=> self::get_post_thumbnail_html($post_id, $size, $attr) ?: ''
		], 10, 5);
	}
}

function wpjam_get_default_thumbnail_url($size='full', $crop=1){
	return wpjam_get_thumbnail(WPJAM_Thumbnail::get_default(), $size, $crop);
}

wpjam_register_option('wpjam-thumbnail', [
	'title'			=> '缩略图设置',
	'model'			=> 'WPJAM_Thumbnail',
	'site_default'	=> true,
	'menu_page'		=> ['parent'=>'wpjam-basic', 'position'=>3, 'summary'=>__FILE__]
]);
