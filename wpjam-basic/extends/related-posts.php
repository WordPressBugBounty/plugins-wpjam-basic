<?php
/*
Name: 相关文章
URI: https://mp.weixin.qq.com/s/J6xYFAySlaaVw8_WyDGa1w
Description: 相关文章扩展根据文章的标签和分类自动生成相关文章列表，并显示在文章末尾。
Version: 1.0
*/
class WPJAM_Related_Posts extends WPJAM_Option_Model{
	public static function get_fields(){
		$options 	= self::get_post_types();
		$fields		= [
			'title'	=> ['title'=>'列表标题',	'type'=>'text',		'value'=>'相关文章',	'class'=>''],
			'list'	=> ['title'=>'列表设置',	'type'=>'fields',	'fields'=>[
				'number'	=> ['type'=>'number',	'value'=>5,	'class'=>'small-text',	'before'=>'显示',	'after'=>'篇相关文章，'],
				'days'		=> ['type'=>'number',	'value'=>0,	'class'=>'small-text',	'before'=>'从最近',	'after'=>'天的文章中筛选，0则不限制。'],
			]],
			'item'	=> ['title'=>'列表内容',	'type'=>'fieldset',	'fields'=>[
				'excerpt'	=> ['label'=>'显示文章摘要。',		'id'=>'_excerpt'],
				'thumb'		=> ['label'=>'显示文章缩略图。',	'group'=>'size',	'value'=>1,	'fields'=>[
					'size'		=> ['type'=>'size',	'group'=>'size',	'before'=>'缩略图尺寸：'],
					'_view'		=> ['type'=>'view',	'value'=>'如勾选之后缩略图不显示，请到「<a href="'.admin_url('page=wpjam-thumbnail').'">缩略图设置</a>」勾选「无需修改主题，自动应用 WPJAM 的缩略图设置」。']
				]]
			]],
		];

		if(!get_theme_support('related-posts')){
			$fields	+= [
				'style'	=> ['title'=>'列表样式',	'type'=>'fieldset',	'fields'=>[
					'div_id'	=> ['type'=>'text',	'class'=>'',	'value'=>'related_posts',	'before'=>'外层 DIV id： &emsp;',	'after'=>'不填则无外层 DIV。'],
					'class'		=> ['type'=>'text',	'class'=>'',	'value'=>'',	'before'=>'列表 UL class：'],
				]],
				'auto'	=> ['title'=>'自动附加',	'value'=>1,	'label'=>'自动附加到文章末尾。'],
			];
		}

		if(count($options) > 1){
			$fields['post_types']	= ['title'=>'文章类型',	'before'=>'显示相关文章的文章类型：', 'type'=>'checkbox', 'options'=>$options];
		}

		return $fields;
	}

	public static function get_menu_page(){
		return [
			'tab_slug'		=> 'related-posts',
			'plugin_page'	=> 'wpjam-posts',
			'order'			=> 19,
			'function'		=> 'option',
			'option_name'	=> 'wpjam-related-posts',
			'summary'		=> __FILE__,
		];
	}

	public static function get_post_types(){
		$ptypes	= ['post'=>__('Post')];

		foreach(get_post_types(['_builtin'=>false]) as $ptype){
			if(is_post_type_viewable($ptype) && get_object_taxonomies($ptype)){
				$ptypes[$ptype]	= wpjam_get_post_type_setting($ptype, 'title');
			}
		}

		return $ptypes;
	}

	public static function get_args($ratio=1){
		$args		= self::get_setting() ?: [];
		$support	= get_theme_support('related-posts');

		if($support){
			$support	= is_array($support) ? current($support) : [];
			$args		= wpjam_except($args, ['div_id', 'class', 'auto']);
			$args		= array_merge($support, $args);
		}

		if(!empty($args['thumb'])){
			$args['size']	= self::parse_size($ratio);
		}

		return $args;
	}

	public static function parse_size($ratio=1){
		$args	= self::get_setting() ?: [];

		if(!isset($args['size'])){
			if(isset($args['width']) || isset($args['height'])){
				$args['size']	= wpjam_slice($args, ['width', 'height']);
			}else{
				$args['size']	= [];
			}

			self::update_setting('size', $args['size']);
		}

		return $args['size'] ? wpjam_parse_size($args['size'], $ratio) : [];
	}

	public static function get_related($id, $parse=false){
		if($id != get_queried_object_id()){
			return $parse ? [] : '';
		}else{
			return wpjam_get_related_posts($id, self::get_args($parse ? 2 : 1), $parse);
		}
	}

	public static function on_the_post($post, $wp_query){
		if($wp_query->is_main_query()
			&& !$wp_query->is_page()
			&& $wp_query->is_singular($post->post_type)
			&& $post->ID == $wp_query->get_queried_object_id()
		){
			$ptypes	= self::get_post_types();

			if(count($ptypes) > 1){
				$setting	= self::get_setting('post_types');
				$ptypes		= $setting ? wpjam_slice($ptypes, $setting) : $ptypes;

				if(!isset($ptypes[$post->post_type])){
					return;
				}
			}else{
				if($post->post_type != 'post'){
					return;
				}
			}

			$args	= self::get_args();

			if(current_theme_supports('related-posts')){
				add_theme_support('related-posts', $args);
			}

			if(wpjam_is_json_request() && empty($args['rendered'])){
				add_filter('wpjam_post_json', fn($json)=> array_merge($json, ['related'=> self::get_related($json['id'], true)]), 10);
			}else{
				if(!empty($args['auto'])){
					add_filter('the_content', fn($content)=> $content.self::get_related(get_the_ID()), 11);
				}
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
		if(!is_admin()){
			add_action('the_post', [self::class, 'on_the_post'], 10, 2);
		}

		add_shortcode('related', [self::class, 'shortcode']);
	}
}

wpjam_register_option('wpjam-related-posts', [
	'model'	=> 'WPJAM_Related_Posts',
	'title'	=> '相关文章'
]);
