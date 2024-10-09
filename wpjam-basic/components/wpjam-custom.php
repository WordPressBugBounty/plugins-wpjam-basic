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
				'head'		=> ['title'=>'前台 Head 代码',	'type'=>'textarea',	'class'=>''],
				'footer'	=> ['title'=>'前台 Footer 代码',	'type'=>'textarea',	'class'=>''],
			]],
			'admin'		=> ['title'=>'后台定制',	'fields'=>[
				'admin_logo'	=> ['title'=>'工具栏左上角 Logo',	'type'=>'img',	'item_type'=>'url',	'description'=>'建议大小：20x20，如前台显示工具栏也会同时被修改。'],
				'admin_head'	=> ['title'=>'后台 Head 代码 ',	'type'=>'textarea',	'class'=>''],
				'admin_footer'	=> ['title'=>'后台 Footer 代码',	'type'=>'textarea',	'class'=>'']
			]],
			'login'		=> ['title'=>'登录界面', 	'fields'=>[
				'login_head'		=> ['title'=>'登录界面 Head 代码',		'type'=>'textarea',	'class'=>''],
				'login_footer'		=> ['title'=>'登录界面 Footer 代码',	'type'=>'textarea',	'class'=>''],
				'login_redirect'	=> ['title'=>'登录之后跳转的页面',		'type'=>'text'],
				'disable_language_switcher'	=> ['title'=>'登录界面语言切换器',	'label'=>'屏蔽登录界面语言切换器'],
			]]
		];
	}

	public static function get_setting($name='', ...$args){
		$value	= parent::get_setting($name, ...$args);

		if($name == 'admin_footer'){
			return $value ?: '<span id="footer-thankyou">感谢使用<a href="https://wordpress.org/" target="_blank">WordPress</a>进行创作。</span> | <a href="https://wpjam.com/" title="WordPress JAM" target="_blank">WordPress JAM</a>';
		}elseif($name == 'footer'){
			return $value.(wpjam_basic_get_setting('optimized_by_wpjam') ? '<p id="optimized_by_wpjam_basic">Optimized by <a href="https://blog.wpjam.com/project/wpjam-basic/">WPJAM Basic</a>。</p>' : '');
		}

		return $value;
	}

	public static function get_menu_page(){
		$menu_page	= [
			'parent'	=> 'wpjam-basic',
			'menu_slug'	=> 'wpjam-custom',
			'function'	=> 'option',
			'position'	=> 1,
			'summary'	=> __FILE__,
		];

		$objects	= wpjam_get_user_signups(['bind'=>true]);

		return [$menu_page, ...($objects ? [[
			'parent'		=> 'users',
			'menu_slug'		=> 'wpjam-bind',
			'menu_title'	=> '账号绑定',
			'order'			=> 20,
			'capability'	=> 'read',
			'function'		=> 'tab',
			'tabs'			=> fn()=> wpjam_map($objects, fn($object)=> [
				'title'			=> $object->title,
				'capability'	=> 'read',
				'function'		=> 'form',
				'form'			=> fn()=> array_merge([
					'callback'		=> [$object, 'ajax_response'],
					'capability'	=> 'read',
					'validate'		=> true,
					'response'		=> 'redirect'
				], $object->get_attr('bind', 'admin'))
			])
		]] : [])];
	}

	public static function get_admin_load(){
		$objects	= wpjam_get_user_signups();

		if($objects){
			return [
				'base'	=> 'users',
				'callback'	=> fn()=> wpjam_register_list_table_column('openid', [
					'title'		=> '绑定账号',
					'order'		=> 20,
					'callback'	=> fn($user_id)=> implode('<br /><br />', array_filter(wpjam_map($objects, fn($object)=> ($openid = $object->get_openid($user_id)) ? $object->title.'：<br />'.$openid : '')))
				])
			];
		}
	}

	public static function on_admin_bar_menu($wp_admin_bar){
		remove_action('admin_bar_menu',	'wp_admin_bar_wp_menu', 10);

		$logo	= self::get_setting('admin_logo');
		$logo	= $logo ? wpjam_get_thumbnail($logo, 40, 40) : '';
		$title	= $logo ? wpjam_tag('img', ['src'=>$logo, 'style'=>'height:20px; padding:6px 0;']) : wpjam_tag('span', ['ab-icon']);

		$wp_admin_bar->add_menu([
			'id'    => 'wp-logo',
			'title' => $title,
			'href'  => is_admin() ? self_admin_url() : site_url(),
			'meta'  => ['title'=> get_bloginfo('name')]
		]);
	}

	public static function on_login_init(){
		wp_enqueue_script('wpjam-ajax');

		$action		= wpjam_get_request_parameter('action', ['default'=>'login']);
		$objects	= in_array($action, ['login', 'bind']) ? wpjam_get_user_signups([$action=>true]) : [];

		if($objects){
			$type	= wpjam_get_request_parameter($action.'_type');

			if($action == 'login'){
				$type	= $type ?: apply_filters('wpjam_default_login_type', 'login');

				if(isset($objects[$type])){
					$login_action	= $objects[$type]->login_action;

					if($login_action && is_callable($login_action)){
						$login_action();
					}
				}

				if(empty($_COOKIE[TEST_COOKIE])){
					$_COOKIE[TEST_COOKIE]	= 'WP Cookie check';
				}

				if(!$type && $_SERVER['REQUEST_METHOD'] == 'POST'){
					$type = 'login';
				}

				$objects['login']	= '使用账号和密码登录';
			}else{
				if(!is_user_logged_in()){
					wp_die('登录之后才能执行绑定操作！');
				}

				add_filter('login_display_language_dropdown', '__return_false');
			}

			$type	= ($type == 'login' || ($type && isset($objects[$type]))) ? $type : array_key_first($objects);
	
			foreach($objects as $name => $object){
				if($name == 'login'){
					$data	= ['type'=>'login'];
					$title	= $object;
				}else{
					$data	= ['type'=>$name, 'action'=>'get-'.$name.'-'.$action];
					$title	= $action == 'bind' ? '绑定'.$object->title : $object->login_title;

					if(method_exists($object, $action.'_script')){
						add_action('login_footer',	[$object, $action.'_script'], 1000);
					}
				}

				$append[]	= ['a', ['class'=>($type == $name ? 'current' : ''), 'data'=>$data], $title];
			}

			wp_enqueue_script('wpjam-login', wpjam_url(dirname(__DIR__).'/static/login.js'), ['wpjam-ajax']);

			add_action('login_form', fn()=> wpjam_echo(wpjam_tag('p')->add_class('types')->data('action', $action)->append($append)));
		}

		wp_add_inline_style('login', join("\n", [
			'.login .message, .login #login_error{margin-bottom: 0;}',
			'.code_wrap label:last-child{display:flex;}',
			'.code_wrap input.button{margin-bottom:10px;}',
			'.login form .input, .login input[type=password], .login input[type=text]{font-size:20px; margin-bottom:10px;}',

			'p.types{line-height:2; float:left; clear:left; margin-top:10px;}',
			'p.types a{text-decoration: none; display:block;}',
			'p.types a.current{display:none;}',
			'div.fields{margin-bottom:10px;}',
		]));
	}

	public static function init(){
		wpjam_register_bind('phone', '', ['domain'=>'@phone.sms']);

		add_action('admin_bar_menu',	[self::class, 'on_admin_bar_menu'], 1);

		if(is_admin()){
			add_filter('admin_title', 		fn($title)=> str_replace(' &#8212; WordPress', '', $title));
			add_action('admin_head',		fn()=> wpjam_echo(self::get_setting('admin_head')));
			add_filter('admin_footer_text',	fn()=> self::get_setting('admin_footer'));
		}elseif(is_login()){
			add_filter('login_headerurl',	fn()=> home_url());
			add_filter('login_headertext',	fn()=> get_option('blogname'));

			add_action('login_head', 		fn()=> wpjam_echo(self::get_setting('login_head')));
			add_action('login_footer',		fn()=> wpjam_echo(self::get_setting('login_footer')));
			add_filter('login_redirect',	fn($redirect_to, $requested)=> $requested ? $redirect_to : (self::get_setting('login_redirect') ?: $redirect_to), 10, 2);

			if(self::get_setting('disable_language_switcher')){
				add_filter('login_display_language_dropdown',	'__return_false');
			}

			if(wp_using_ext_object_cache()){
				add_action('login_init',	[self::class, 'on_login_init']);
			}
		}else{
			add_action('wp_head',	fn()=> wpjam_echo(self::get_setting('head')), 1);
			add_action('wp_footer', fn()=> wpjam_echo(self::get_setting('footer')), 99);
		}
	}
}

wpjam_register_option('wpjam-custom', [
	'title'			=> '样式定制',
	'model'			=> 'WPJAM_Custom',
	'site_default'	=> true,
]);

