<?php
/*
Name: 文章页代码
URI: https://mp.weixin.qq.com/s/xbaOgxyGHs9ysL5-bTEbcw
Description: 在文章编辑页面可以单独设置每篇文章 head 和 Footer 代码。
Version: 1.0
*/
class WPJAM_Post_Custom_Code{
	public static function get_sections(){
		return ['posts'=>['fields'=>['custom-post'	=> ['title'=>'文章页代码', 'options'=>[
			0		=> '不在文章列表页设置文章页代码', 
			1		=> '在文章列表页设置文章页代码', 
			'only'	=> '只在文章列表页设置文章页代码'
		]]]]];
	}

	public static function add_hooks(){
		add_filter('wp_footer',	fn()=> is_singular() ? wpjam_echo(get_post_meta(get_the_ID(), 'custom_footer', true)) : null);
		add_filter('wp_head',	fn()=> is_singular() ? wpjam_echo(get_post_meta(get_the_ID(), 'custom_head', true)) : null);

		wpjam_register_post_option('custom-post', [
			'title'			=> '文章页代码',
			'post_type'		=> fn($post_type)=> is_post_type_viewable($post_type) && post_type_supports($post_type, 'editor'),
			'summary'		=> '自定义文章代码可以让你在当前文章插入独有的 JS，CSS，iFrame 等类型的代码，让你可以对具体一篇文章设置不同样式和功能，展示不同的内容。',
			'list_table'	=> wpjam_basic_get_setting('custom-post'),
			'fields'		=> [
				'custom_head'	=>['title'=>'头部代码',	'type'=>'textarea'],
				'custom_footer'	=>['title'=>'底部代码',	'type'=>'textarea']
			]
		]);
	}
}

wpjam_add_option_section('wpjam-basic', ['model'=>'WPJAM_Post_Custom_Code']);