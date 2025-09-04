<?php
/*
Name: 相关文章
URI: https://mp.weixin.qq.com/s/J6xYFAySlaaVw8_WyDGa1w
Description: 相关文章扩展根据文章的标签和分类自动生成相关文章列表，并显示在文章末尾。
Version: 1.0
*/
class WPJAM_Related_Posts extends WPJAM_Option_Model{
	public static function get_fields(){
		$options 	= self::get_options();

		return [
			'title'	=> ['title'=>'列表标题',	'type'=>'text',		'value'=>'相关文章',	'class'=>''],
			'list'	=> ['title'=>'列表设置',	'sep'=>'',	'fields'=>[
				'number'	=> ['type'=>'number',	'value'=>5,	'class'=>'small-text',	'before'=>'显示',	'after'=>'篇相关文章，'],
				'days'		=> ['type'=>'number',	'value'=>0,	'class'=>'small-text',	'before'=>'从最近',	'after'=>'天的文章中筛选，0则不限制。'],
			]],
			'item'	=> ['title'=>'列表内容',	'fields'=>[
				'excerpt'	=> ['label'=>'显示文章摘要。',		'id'=>'_excerpt'],
				'thumb'		=> ['label'=>'显示文章缩略图。',	'group'=>'size',	'value'=>1,	'fields'=>[
					'size'	=> ['type'=>'size',	'group'=>'size',	'before'=>'缩略图尺寸：'],
				]]
			],	'description'=>['如勾选之后缩略图不显示，请到「<a href="'.admin_url('page=wpjam-thumbnail').'">缩略图设置</a>」勾选「无需修改主题，自动应用 WPJAM 的缩略图设置」。', ['show_if'=>['thumb', 1]]]],
		]+(get_theme_support('related-posts') ? [] : [
			'style'	=> ['title'=>'列表样式',	'type'=>'fieldset',	'fields'=>[
				'div_id'	=> ['type'=>'text',	'class'=>'',	'value'=>'related_posts',	'before'=>'外层 DIV id： &emsp;',	'after'=>'不填则无外层 DIV。'],
				'class'		=> ['type'=>'text',	'class'=>'',	'value'=>'',	'before'=>'列表 UL class：'],
			]],
			'auto'	=> ['title'=>'自动附加',	'value'=>1,	'label'=>'自动附加到文章末尾。'],
		])+(count($options) <= 1 ? [] : [
			'post_types'	=> ['title'=>'文章类型',	'before'=>'显示相关文章的文章类型：', 'type'=>'checkbox', 'options'=>$options]
		]);
	}

	public static function get_options(){
		return ['post'=>__('Post')]+wpjam_reduce(get_post_types(['_builtin'=>false]), fn($c, $v, $k)=> is_post_type_viewable($v) && get_object_taxonomies($v) ? wpjam_set($c, $v, wpjam_get_post_type_setting($v, 'title')) : $c, []);
	}

	public static function on_the_post($post, $query){
		$options	= self::get_options();
		$options	= count($options) > 1 && ($setting	= self::get_setting('post_types')) ? wpjam_pick($options, $setting) : $options;

		if(!isset($options[$post->post_type])){
			return;
		}

		$args	= self::get_setting() ?: [];

		if(current_theme_supports('related-posts')){
			$support	= get_theme_support('related-posts');
			$args		= array_merge(is_array($support) ? current($support) : [], wpjam_except($args, ['div_id', 'class', 'auto']));

			add_theme_support('related-posts', $args);
		}

		if(wpjam_is_json_request()){
			if(!empty($args['thumb']) && !empty($args['size'])){
				$args['size']	= wpjam_parse_size($args['size'], 2);
			}

			empty($args['rendered']) && wpjam_add_filter('wpjam_post_json', [
				'callback'	=> fn($json, $id)=> $json+['related'=>wpjam_get_related_posts($id, $args, true)],
				'check'		=> fn($json, $id, $args)=> wpjam_is(wpjam_get($args, 'query'), 'single', $id),
				'once'		=> true
			], 10, 3);
		}else{
			!empty($args['auto']) && wpjam_add_filter('the_content', [
				'callback'	=> fn($content)=> $content.wpjam_get_related_posts(get_the_ID(), $args, false),
				'check'		=> fn()=> wpjam_is('single', get_the_ID()),
				'once'		=> true
			], 11);
		}
	}

	public static function shortcode($atts){
		return !empty($atts['tag']) ? wpjam_render_query([
			'post_type'		=> 'any',
			'no_found_rows'	=> true,
			'post_status'	=> 'publish',
			'post__not_in'	=> [get_the_ID()],
			'tax_query'		=> [[
				'taxonomy'	=> 'post_tag',
				'terms'		=> wp_parse_list($atts['tag']),
				'operator'	=> 'AND',
				'field'		=> 'name'
			]]
		], ['thumb'=>false, 'class'=>'related-posts']) : '';
	}

	public static function add_hooks(){
		is_admin() || wpjam_add_action('the_post', [
			'check'		=> fn($post, $query)=> wpjam_is($query, 'single', $post->ID),
			'callback'	=> [self::class, 'on_the_post'],
			'once'		=> true
		], 10, 2);

		add_shortcode('related', [self::class, 'shortcode']);
	}
}

wpjam_register_option('wpjam-related-posts', [
	'model'		=> 'WPJAM_Related_Posts',
	'title'		=> '相关文章',
	'menu_page'	=> ['tab_slug'=>'related', 'plugin_page'=>'wpjam-posts', 'order'=>19, 'summary'=>__FILE__]
]);
