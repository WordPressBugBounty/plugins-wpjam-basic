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
		return ['post'=>__('Post')]+wpjam_array(get_post_types(['_builtin'=>false]), fn($k, $v)=> is_post_type_viewable($v) && get_object_taxonomies($v) ? [$v, wpjam_get_post_type_setting($v, 'title')] : null);
	}

	public static function get_args($ratio=1){
		$support	= get_theme_support('related-posts');
		$args		= self::get_setting() ?: [];
		$args		= $support ? array_merge(is_array($support) ? current($support) : [], wpjam_except($args, ['div_id', 'class', 'auto'])) : $args;

		if(!empty($args['thumb'])){
			if(!isset($args['size'])){
				$args['size']	= wpjam_pick($args, ['width', 'height']);

				self::update_setting('size', $args['size']);
			}

			if($args['size']){
				$args	= wpjam_set($args, 'size', wpjam_parse_size($args['size'], $ratio));
			}
		}

		return $args;;
	}

	public static function get_related($id, $parse=false){
		return wpjam_get_related_posts($id, self::get_args($parse ? 2 : 1), $parse);
	}

	public static function on_the_post($post, $wp_query){
		if($wp_query->is_main_query()
			&& !$wp_query->is_page()
			&& $wp_query->is_singular($post->post_type)
			&& $post->ID == $wp_query->get_queried_object_id()
		){
			$options	= self::get_options();

			if(count($options) > 1){
				$setting	= self::get_setting('post_types');
				$options	= $setting ? wpjam_pick($options, $setting) : $options;
			}

			if(!isset($options[$post->post_type])){
				return;
			}

			$id		= $post->ID;
			$args	= self::get_args();

			current_theme_supports('related-posts') && add_theme_support('related-posts', $args);

			if(wpjam_is_json_request()){
				empty($args['rendered']) && add_filter('wpjam_post_json', fn($json)=> array_merge($json, $id == $json['id'] ? ['related'=> self::get_related($id, true)] : []), 10);
			}else{
				!empty($args['auto']) && add_filter('the_content', fn($content)=> $id == get_the_ID() ? $content.self::get_related($id) : '', 11);
			}
		}
	}

	public static function shortcode($atts, $content=''){
		return !empty($atts['tag']) ? wpjam_render_query([
			'post_type'		=> 'any',
			'no_found_rows'	=> true,
			'post_status'	=> 'publish',
			'post__not_in'	=> [get_the_ID()],
			'tax_query'		=> [[
				'taxonomy'	=> 'post_tag',
				'terms'		=> explode(",", $atts['tag']),
				'operator'	=> 'AND',
				'field'		=> 'name'
			]]
		], ['thumb'=>false, 'class'=>'related-posts']) : '';
	}

	public static function add_hooks(){
		is_admin() || add_action('the_post', [self::class, 'on_the_post'], 10, 2);

		add_shortcode('related', [self::class, 'shortcode']);
	}
}

wpjam_register_option('wpjam-related-posts', [
	'model'		=> 'WPJAM_Related_Posts',
	'title'		=> '相关文章',
	'menu_page'	=> ['tab_slug'=>'related', 'plugin_page'=>'wpjam-posts', 'order'=>19, 'summary'=>__FILE__]
]);
