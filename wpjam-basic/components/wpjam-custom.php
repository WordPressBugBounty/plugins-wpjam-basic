<?php
/*
Name: 样式定制
URI: https://mp.weixin.qq.com/s/Hpu1vz7zPUKEeHTF3wqyWw
Description: 对网站的前后台和登录界面的样式进行个性化设置。
Version: 2.0
*/
class WPJAM_Custom extends WPJAM_Option_Model{
	public static function get_sections(){
		return [
			'custom'	=> ['title'=>'前台定制',	'fields'=>[
				'head'		=> ['title'=>'前台 Head 代码',	'type'=>'textarea'],
				'footer'	=> ['title'=>'前台 Footer 代码',	'type'=>'textarea'],
				'custom'	=> ['title'=>'文章页代码',	'type'=>'fields',	'fields'=>[
					'custom_post'	=> ['label'=>'每篇文章单独设置 head 和 Footer 代码。'],
					'list_table'	=> ['show_if'=>['custom_post', 1], 'after'=>'在文章列表页设置。', 'options'=>[0=>'不支持', 1=>'支持', 'only'=>'只允许']]
				]]
			]],
			'admin'		=> ['title'=>'后台定制',	'fields'=>[
				'admin_logo'	=> ['title'=>'工具栏左上角 Logo',	'type'=>'img',	'item_type'=>'url',	'size'=>'40x40'],
				'admin_head'	=> ['title'=>'后台 Head 代码 ',	'type'=>'textarea'],
				'admin_footer'	=> ['title'=>'后台 Footer 代码',	'type'=>'textarea'],
				'admin_info'	=> ['title'=>'后台运行信息',		'label'=>'后台右下角显示内存使用和 SQL 数量',	'value'=>1],
			]],
			'login'		=> ['title'=>'登录界面', 	'fields'=>[
				'login_head'		=> ['title'=>'登录界面 Head 代码',		'type'=>'textarea'],
				'login_footer'		=> ['title'=>'登录界面 Footer 代码',	'type'=>'textarea'],
				'login_redirect'	=> ['title'=>'登录之后跳转的页面',		'type'=>'text'],

				'disable_language_switcher'	=> ['title'=>'登录界面语言切换器',	'label'=>'屏蔽登录界面语言切换器'],
			]]
		];
	}

	public static function echo($name){
		$value	= self::get_setting($name);

		if($name == 'admin_footer'){
			echo ($value ?: '<span id="footer-thankyou">感谢使用<a href="https://wordpress.org/" target="_blank">WordPress</a>进行创作。</span> | <a href="https://wpjam.com/" title="WordPress JAM" target="_blank">WordPress JAM</a>');
		}elseif($name == 'footer'){
			echo $value.(wpjam_basic_get_setting('optimized_by_wpjam') ? '<p id="optimized_by_wpjam_basic">Optimized by <a href="https://blog.wpjam.com/project/wpjam-basic/">WPJAM Basic</a>。</p>' : '');
		}else{
			echo $value;
		}

		if(in_array($name, ['head', 'footer']) && is_singular() && self::get_setting('custom_post')){
			if($value = get_post_meta(get_the_ID(), 'custom_'.$name, true)){
				if($name == 'head'){
					add_action('wp_'.$name, fn()=> wpjam_echo($value), 99);
				}else{
					echo $value;
				}
			}
		}
	}

	public static function on_admin_bar_menu($wp_admin_bar){
		remove_action('admin_bar_menu',	'wp_admin_bar_wp_menu', 10);

		$logo	= self::get_setting('admin_logo');
		$title	= wpjam_tag(...($logo ? ['img', ['src'=>wpjam_get_thumbnail($logo, 40, 40), 'style'=>'height:20px; padding:6px 0;']] : ['span', ['ab-icon']]));

		$wp_admin_bar->add_menu([
			'id'    => 'wp-logo',
			'title' => $title,
			'href'  => is_admin() ? self_admin_url() : site_url(),
			'meta'  => ['title'=> get_bloginfo('name')]
		]);

		is_admin() && self::get_setting('admin_info', 1) && add_filter('update_footer', fn($text)=> wpjam_join(' | ', [size_format(memory_get_usage()).'内存使用', get_num_queries().'次SQL查询', $text]));
	}

	public static function init(){
		add_action('admin_bar_menu',	[self::class, 'on_admin_bar_menu'], 1);

		if(is_admin()){
			wpjam_hooks([
				['admin_title', 		fn($title)=> str_replace(' &#8212; WordPress', '', $title)],
				['admin_head',			fn()=> self::echo('admin_head')],
				['admin_footer_text',	fn()=> self::get_setting('admin_footer')]
			]);
		}elseif(is_login()){
			wpjam_hooks([
				['login_headerurl',		fn()=> home_url()],
				['login_headertext',	fn()=> get_option('blogname')],
				['login_head', 			fn()=> self::echo('login_head')],
				['login_footer',		fn()=> self::echo('login_footer')],
				['login_redirect',		fn($to, $requested)=> $requested ? $to : (self::get_setting('login_redirect') ?: $to), 10, 2],

				self::get_setting('disable_language_switcher') ? ['login_display_language_dropdown',	'__return_false'] : []
			]);
		}else{
			wpjam_hooks([
				['wp_head',	fn()=> self::echo('head'), 1],
				['wp_footer', fn()=> self::echo('footer'), 99]
			]);
		}

		self::get_setting('custom_post') && wpjam_register_post_option('custom-post', [
			'title'			=> '文章页代码',
			'post_type'		=> fn($post_type)=> is_post_type_viewable($post_type) && post_type_supports($post_type, 'editor'),
			'summary'		=> '自定义文章代码可以让你在当前文章插入独有的 JS，CSS，iFrame 等类型的代码，让你可以对具体一篇文章设置不同样式和功能，展示不同的内容。',
			'list_table'	=> self::get_setting('list_table'),
			'fields'		=> [
				'custom_head'	=>['title'=>'头部代码',	'type'=>'textarea'],
				'custom_footer'	=>['title'=>'底部代码',	'type'=>'textarea']
			]
		]);
	}
}

wpjam_register_option('wpjam-custom', [
	'title'			=> '样式定制',
	'model'			=> 'WPJAM_Custom',
	'site_default'	=> true,
	'menu_page'		=> ['parent'=>'wpjam-basic', 'position'=>1, 'function'=>'option', 'summary'=>__FILE__]
]);