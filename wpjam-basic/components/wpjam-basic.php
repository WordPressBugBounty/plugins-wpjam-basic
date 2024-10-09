<?php
/*
Name: 优化设置
URI: https://mp.weixin.qq.com/s/zkA0Nx4u81PCZWByQq3iiA
Description: 优化设置通过屏蔽和增强功能来加快 WordPress 的加载
Version: 2.0
*/
class WPJAM_Basic extends WPJAM_Option_Model{
	public static function get_sections(){
		$tax_options	= array_column(get_taxonomies(['public'=>true, 'hierarchical'=>true], 'objects'), 'label', 'name');
		$for_field		= count($tax_options) > 1 ? ['title'=>'分类模式：', 'options'=>$tax_options] : ['type'=>'hidden', 'value'=>'category'];
		$no_base		= ['label'=>'去掉分类目录链接中的 category。', 'fields'=>['no_category_base_for'=>$for_field]];

		return [
			'disabled'	=>['title'=>'功能屏蔽',	'fields'=>[
				'disable_revisions'			=>['title'=>'屏蔽文章修订',	'label'=>'屏蔽文章修订功能，精简文章表数据。',		'value'=>1],
				'disable_trackbacks'		=>['title'=>'屏蔽Trackback',	'label'=>'彻底关闭Trackback，防止垃圾留言。',		'value'=>1],
				'disable_emoji'				=>['title'=>'屏蔽Emoji图片',	'label'=>'屏蔽Emoji转换成图片功能，直接使用Emoji。',	'value'=>1],
				'disable_texturize'			=>['title'=>'屏蔽字符转码',	'label'=>'屏蔽字符换成格式化的HTML实体功能。', 		'value'=>1],
				'disable_feed'				=>['title'=>'屏蔽站点Feed',	'label'=>'屏蔽站点Feed，防止文章被快速被采集。'],
				'disable_admin_email_check'	=>['title'=>'屏蔽邮箱验证',	'label'=>'屏蔽站点管理员邮箱定期验证功能。'],
				'disable_auto_update'		=>['title'=>'屏蔽自动更新',	'label'=>'关闭自动更新功能，通过手动或SSH方式更新。'],
				'disable_privacy'			=>['title'=>'屏蔽后台隐私',	'label'=>'移除为欧洲通用数据保护条例而生成的页面。',	'value'=>1],
				'disable_dashboard_primary'	=>['title'=>'屏蔽后台新闻',	'label'=>'移除后台仪表盘的「WordPress 活动及新闻」'],
				'disable_xml_rpc'			=>['title'=>'屏蔽XML-RPC',	'label'=>'关闭XML-RPC功能，只在后台发布文章。'],
				'disable_embed'				=>['title'=>'屏蔽嵌入功能',	'fields'=>[
					'disable_autoembed'	=>['label'=>'禁用Auto Embeds功能，加快页面解析速度。'],
					'disable_embed'		=>['label'=>'屏蔽嵌入其他WordPress文章的Embed功能。'],
				]],
				'disable_block_editor'		=>['title'=>'屏蔽古腾堡编辑器','fields'=>[
					'disable_block_editor'			=>['label'=>'屏蔽Gutenberg编辑器，换回经典编辑器。'],
					'disable_widgets_block_editor'	=>['label'=>'屏蔽小工具区块编辑器模式，切换回经典模式。']
				]],
			]],
			'enhance'	=>['title'=>'增强优化',	'fields'=>[
				'static_cdn'				=>['title'=>'前端公共库',		'options'=>wpjam_fill(wpjam_get_items('static_cdn'), fn($url)=> parse_url($url, PHP_URL_HOST))],
				'google_fonts_set'			=>['title'=>'Google字体加速',	'fields'=>WPJAM_Google_Font::get_setting_fields(['name'=>'google_fonts'])],
				'gravatar_set'				=>['title'=>'Gravatar加速',	'fields'=>WPJAM_Gravatar::get_setting_fields()],
				'x-frame-options'			=>['title'=>'Frame嵌入',		'options'=>[''=>'所有网页', 'SAMEORIGIN'=>'只允许同域名网页', 'DENY'=>'不允许任何网页']],
				'no_category_base_set'		=>['title'=>'分类链接简化',	'group'=>true,	'fields'=>['no_category_base'=>$no_base]],
				'timestamp_file_name'		=>['title'=>'图片时间戳',		'label'=>'给上传的图片加上时间戳，防止大量的SQL查询。'],
				'remove_capital_P_dangit'	=>['title'=>'移除大小写修正',	'label'=>'移除 WordPress 大小写修正，自行决定如何书写。',	'value'=>1],
				'frontend'					=>['title'=>'前台页面优化',	'fields'=>[
					'remove_head_links'		=>['label'=>'移除页面头部版本号和服务发现标签代码。'],
					'remove_admin_bar'		=>['label'=>'移除工具栏和后台个人资料中工具栏相关选项。'],
				]],
				'backend'					=>['title'=>'后台页面优化',	'fields'=>[
					'remove_help_tabs'		=>['label'=>'移除后台界面右上角的帮助。'],
					'remove_screen_options'	=>['label'=>'移除后台界面右上角的选项。'],
				]],
				'optimized_by_wpjam'		=>['title'=>'WPJAM Basic',	'label'=>'在网站底部显示：Optimized by WPJAM Basic。']
			]],
		];
	}

	public static function get_menu_page(){
		return [
			'menu_title'	=> 'WPJAM',
			'icon'			=> 'ri-rocket-fill',
			'position'		=> '58.99',
			'sub_title'		=> '优化设置',
			'function'		=> 'option',
			'summary'		=> __FILE__,
		];
	}

	public static function get_avatar_data($id_or_email){
		if(is_numeric($id_or_email)){
			$user_id	= $id_or_email;
		}elseif(is_string($id_or_email)){
			$email		= $id_or_email;
		}elseif(is_object($id_or_email)){
			if(isset($id_or_email->comment_ID)){
				$comment	= get_comment($id_or_email);
				$user_id	= $comment->user_id;
				$email		= $comment->comment_author_email;
				$avatarurl	= get_comment_meta($comment->comment_ID, 'avatarurl', true);
			}elseif($id_or_email instanceof WP_User){
				$user_id	= $id_or_email->ID;
			}elseif($id_or_email instanceof WP_Post){
				$user_id	= $id_or_email->post_author;
			}
		}

		$user_id	??= 0;
		$email		??= '';
		$avatarurl	= !empty($avatarurl) ? $avatarurl : ($user_id ? get_user_meta($user_id, 'avatarurl', true) : '');

		return $avatarurl ? ['avatarurl'=>$avatarurl] : ['user_id'=>$user_id, 'email'=>$email];
	}

	public static function is_disabled($feature, $default=null){
		return self::get_setting('disable_'.$feature, $default);
	}

	public static function init(){
		wpjam_map(['trackbacks'=>'tb', 'embed'=>'embed'], fn($v, $k)=> self::is_disabled($k) ? $GLOBALS['wp']->remove_query_var($v) : null);

		wpjam_map(['trackbacks', 'revisions'], fn($v)=> self::is_disabled($v, 1) ? wpjam_map(['post', 'page'], fn($post_type)=> remove_post_type_support($post_type, $v)) : null);
	}

	public static function add_hooks(){
		foreach([
			'disable_revision'		=> 'disable_revisions',
			'disable_post_embed'	=> 'disable_embed',
		] as $from => $to){
			if(self::get_setting($to) === null && ($value = self::get_setting($from)) !== null){
				self::update_setting($to, $value);
				self::delete_setting($from);
			}
		}

		$remove_filter		= $remove_action = fn($hook, $callback)=> remove_filter($hook, $callback, has_filter($hook, $callback));
		$static_cdn			= self::get_setting('static_cdn');
		$x_frame_options	= self::get_setting('x-frame-options');

		if($static_cdn){
			add_filter('wpjam_static_cdn_host', fn($host, $hosts)=> in_array($static_cdn, $hosts) ? $static_cdn : $host, 10, 2);
		}

		if($x_frame_options){
			add_action('send_headers', fn()=> header('X-Frame-Options: '.$x_frame_options));
		}

		add_filter('pre_get_avatar_data', fn($args, $id_or_email)=> WPJAM_Gravatar::filter_pre_data(self::get_avatar_data($id_or_email)+$args), 10, 2);

		add_action('wp_loaded',	fn()=> ob_start(fn($html)=> apply_filters('wpjam_html', WPJAM_Google_Font::filter_html($html))));

		// 修正任意文件删除漏洞
		add_filter('wp_update_attachment_metadata',	fn($data)=> (isset($data['thumb']) ? ['thumb'=>basename($data['thumb'])] : [])+$data);

		// 开启去掉URL中category，跳转到 no base 的 link
		if(self::get_setting('no_category_base')){
			$tax	= self::get_setting('no_category_base_for', 'category');

			add_filter('register_taxonomy_args', fn($args, $name)=> array_merge($args, $name == $tax ? ['permastruct'=>'%'.$tax.'%'] : []), 8, 2);

			if($tax == 'category' && str_starts_with($_SERVER['REQUEST_URI'], '/category/')){	
				add_action('template_redirect', fn()=> wp_redirect(site_url(wpjam_remove_prefix($_SERVER['REQUEST_URI'], '/category/')), 301));
			}
		}

		// 屏蔽站点 Feed
		if(self::is_disabled('feed')){
			add_action('template_redirect', fn()=> is_feed() ? wp_die('Feed已经关闭, 请访问<a href="'.get_bloginfo('url').'">网站首页</a>！', 'Feed关闭', 200) : null);
		}

		// 防止重名造成大量的 SQL 请求
		if(self::get_setting('timestamp_file_name')){
			array_map(fn($k)=> add_filter('wp_handle_'.$k.'_prefilter', fn($file)=> empty($file['md5_filename']) ? array_merge($file, ['name'=> time().'-'.$file['name']]) : $file), ['sideload', 'upload']);
		}

		//移除 WP_Head 无关紧要的代码
		if(self::get_setting('remove_head_links')){
			add_filter('the_generator', fn()=> '');

			array_map(fn($v)=> $remove_action('wp_head', $v), ['rsd_link', 'wlwmanifest_link', 'feed_links_extra', 'index_rel_link', 'parent_post_rel_link', 'start_post_rel_link', 'adjacent_posts_rel_link_wp_head', 'wp_shortlink_wp_head', 'rest_output_link_wp_head']);

			array_map(fn($v)=> $remove_action('template_redirect', $v), ['wp_shortlink_header', 'rest_output_link_header']);

			array_map(fn($v)=> add_filter($v.'_loader_src', fn($src)=> $src ? preg_replace('/[\&\?]ver='.preg_quote($GLOBALS['wp_version']).'(&|$)/', '', $src) : $src), ['style', 'script']);
		}

		//让用户自己决定是否书写正确的 WordPress
		if(self::get_setting('remove_capital_P_dangit', 1)){
			array_map(fn($v)=> $remove_filter($v, 'capital_P_dangit'), ['the_content', 'the_title', 'wp_title', 'document_title', 'comment_text', 'widget_text_content']);
		}

		// 屏蔽字符转码
		if(self::is_disabled('texturize', 1)){
			add_filter('run_wptexturize', fn()=> false);
		}

		//移除 admin bar
		if(self::get_setting('remove_admin_bar')){
			add_filter('show_admin_bar', fn()=> false);
		}

		//禁用 XML-RPC 接口
		if(self::is_disabled('xml_rpc')){
			add_filter('xmlrpc_enabled', fn()=> false);
			add_filter('xmlrpc_methods', fn()=> []);

			remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
		}

		// 屏蔽古腾堡编辑器
		if(self::is_disabled('block_editor')){
			array_map(fn($v)=> $remove_action($v, 'wp_common_block_scripts_and_styles'), ['wp_enqueue_scripts', 'admin_enqueue_scripts']);

			remove_filter('the_content', 'do_blocks', 9);
		}

		// 屏蔽小工具区块编辑器模式
		if(self::is_disabled('widgets_block_editor')){
			add_filter('gutenberg_use_widgets_block_editor', fn()=> false);
			add_filter('use_widgets_block_editor', fn()=> false);
		}

		// 屏蔽站点管理员邮箱验证功能
		if(self::is_disabled('admin_email_check')){
			add_filter('admin_email_check_interval', fn()=> 0);
		}

		// 屏蔽 Emoji
		if(self::is_disabled('emoji', 1)){
			add_action('admin_init', fn()=> array_map(fn($args)=> remove_filter(...$args), [
				['admin_print_scripts',	'print_emoji_detection_script'],
				['admin_print_styles',	'print_emoji_styles']
			]));

			remove_action('wp_head',			'print_emoji_detection_script',	7);
			remove_action('wp_print_styles',	'print_emoji_styles');

			remove_action('embed_head',			'print_emoji_detection_script');

			remove_filter('the_content_feed',	'wp_staticize_emoji');
			remove_filter('comment_text_rss',	'wp_staticize_emoji');
			remove_filter('wp_mail',			'wp_staticize_emoji_for_email');

			add_filter('emoji_svg_url',		fn()=> false);
			add_filter('tiny_mce_plugins',	fn($plugins)=> array_diff($plugins, ['wpemoji']));
		}

		//禁用文章修订功能
		if(self::is_disabled('revisions', 1)){
			if(!defined('WP_POST_REVISIONS')){
				define('WP_POST_REVISIONS', false);
			}

			remove_action('pre_post_update', 'wp_save_post_revision');

			add_filter('register_meta_args', fn($args, $defaults, $meta_type, $meta_key)=> ($meta_type == 'post' && !empty($args['object_subtype']) && in_array($args['object_subtype'], ['post', 'page'])) ? array_merge($args, ['revisions_enabled'=>false]) : $args, 10, 4);
		}

		// 屏蔽Trackbacks
		if(self::is_disabled('trackbacks', 1)){
			if(!self::is_disabled('xml_rpc')){	//彻底关闭 pingback
				add_filter('xmlrpc_methods', fn($methods)=> wpjam_except($methods, ['pingback.ping', 'pingback.extensions.getPingbacks']));
			}

			remove_action('do_pings',		'do_all_pings', 10);		//禁用 pingbacks, enclosures, trackbacks
			remove_action('publish_post',	'_publish_post_hook',5);	//去掉 _encloseme 和 do_ping 操作。
		}

		//禁用 Auto OEmbed
		if(self::is_disabled('autoembed')){
			array_map(fn($v)=> $remove_action($v, [$GLOBALS['wp_embed'], 'maybe_run_ajax_cache']), ['edit_form_advanced', 'edit_page_form']);
			array_map(fn($v)=> $remove_filter($v, [$GLOBALS['wp_embed'], 'autoembed']), ['the_content', 'widget_text_content', 'widget_block_content']);
		}

		// 屏蔽文章Embed
		if(self::is_disabled('embed')){
			array_map(fn($v)=> remove_action('wp_head', $v), ['wp_oembed_add_discovery_links', 'wp_oembed_add_host_js']);
		}

		// 屏蔽自动更新和更新检查作业
		if(self::is_disabled('auto_update')){
			add_filter('automatic_updater_disabled', fn()=> true);

			remove_action('init', 'wp_schedule_update_checks');

			array_map(fn($v)=> $remove_action($v, $v), ['wp_version_check', 'wp_update_plugins', 'wp_update_themes']);
		}

		// 屏蔽后台隐私
		if(self::is_disabled('privacy', 1)){
			array_map(fn($v)=> $remove_action('user_request_action_confirmed', $v), ['_wp_privacy_account_request_confirmed', '_wp_privacy_send_request_confirmation_notification']);

			array_map(fn($v)=> $remove_action('wp_privacy_personal_data_exporters', $v), ['wp_register_comment_personal_data_exporter', 'wp_register_media_personal_data_exporter', 'wp_register_user_personal_data_exporter']);

			remove_action('wp_privacy_personal_data_erasers', 'wp_register_comment_personal_data_eraser');
			remove_action('init', 'wp_schedule_delete_old_privacy_export_files');
			remove_action('wp_privacy_delete_old_export_files', 'wp_privacy_delete_old_export_files');

			add_filter('option_wp_page_for_privacy_policy', fn()=> 0);
		}

		if(is_admin()){
			if(self::is_disabled('auto_update')){
				array_map(fn($v)=> remove_action('admin_init', $v), ['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes']);
			}

			if(self::is_disabled('block_editor')){
				add_filter('use_block_editor_for_post_type', fn()=> false);
			}

			if(self::get_setting('remove_help_tabs')){
				add_action('in_admin_header', fn()=> $GLOBALS['current_screen']->remove_help_tabs());
			}

			if(self::get_setting('remove_screen_options')){
				add_filter('screen_options_show_screen', fn()=> false);
				add_filter('hidden_columns', fn()=> []);
			}

			if(self::is_disabled('privacy', 1)){
				add_action('admin_menu', fn()=> array_map(fn($args)=> remove_submenu_page(...$args), [
					['options-general.php',	'options-privacy.php'],
					['tools.php',			'export-personal-data.php'],
					['tools.php',			'erase-personal-data.php']
				]), 11);

				add_action('admin_init', fn()=> array_map(fn($args)=> $remove_filter(...$args), [
					['admin_init',				['WP_Privacy_Policy_Content', 'text_change_check']],
					['edit_form_after_title',	['WP_Privacy_Policy_Content', 'notice']],
					['admin_init',				['WP_Privacy_Policy_Content', 'add_suggested_content']],
					['post_updated',			['WP_Privacy_Policy_Content', '_policy_page_updated']],
					['list_pages',				'_wp_privacy_settings_filter_draft_page_titles'],
				]), 1);
			}

			if(self::is_disabled('dashboard_primary')){
				add_action('do_meta_boxes', fn($screen, $context)=> str_contains($screen, 'dashboard') ? remove_meta_box('dashboard_primary', $screen, $context) : null, 10, 2);
			}
		}
	}
}

/**
* @config single
**/
#[config('single')]
class WPJAM_Gravatar extends WPJAM_Register{
	public static function get_defaults(){
		return [
			'geekzu'	=> ['title'=>'极客族加速服务',		'url'=>'https://sdn.geekzu.org/avatar/'],
			'loli'		=> ['title'=>'loli加速服务',		'url'=>'https://gravatar.loli.net/avatar/'],
			'sep_cc'	=> ['title'=>'sep.cc加速服务',	'url'=>'https://cdn.sep.cc/avatar/'],
			'cravatar'	=> ['title'=>'Cravatar加速服务',	'url'=>'https://cravatar.cn/avatar/'],
			'custom'	=> ['title'=>'自定义加速服务',		'url'=>fn()=> wpjam_basic_get_setting('gravatar_custom'),	'fields'=>['gravatar_custom'=>['placeholder'=>'请输入 Gravatar 加速服务地址']]]
		];
	}

	public static function filter_pre_data($args){
		if(!empty($args['avatarurl'])){
			return array_merge($args, ['found_avatar'=>true, 'url'=>wpjam_get_thumbnail($args['avatarurl'], $args)]);
		}

		$object 	= self::get(wpjam_basic_get_setting('gravatar'));
		$replace	= $object ? $object->get_arg('url') : '';

		if($replace){
			add_filter('get_avatar_url', fn($url)=> str_replace(array_map(fn($v)=>$v.'gravatar.com/avatar/', ['https://secure.', 'http://0.', 'http://1.', 'http://2.']), $replace, $url));
		}

		return $args;
	}
}

/**
* @config single
**/
#[config('single')]
class WPJAM_Google_Font extends WPJAM_Register{
	public static function get_search(){
		return [
			'googleapis_fonts'			=> '//fonts.googleapis.com',
			'googleapis_ajax'			=> '//ajax.googleapis.com',
			'googleusercontent_themes'	=> '//themes.googleusercontent.com',
			'gstatic_fonts'				=> '//fonts.gstatic.com'
		];
	}

	public static function get_defaults(){
		return [
			'geekzu'	=> [
				'title'		=> '极客族加速服务',
				'replace'	=> ['//fonts.geekzu.org', '//gapis.geekzu.org/ajax', '//gapis.geekzu.org/g-themes', '//gapis.geekzu.org/g-fonts']
			],
			'loli'		=> [
				'title'		=> 'loli加速服务',
				'replace'	=> ['//fonts.loli.net', '//ajax.loli.net', '//themes.loli.net', '//gstatic.loli.net']
			],
			'ustc'		=> [
				'title'		=> '中科大加速服务',
				'replace'	=> ['//fonts.lug.ustc.edu.cn', '//ajax.lug.ustc.edu.cn', '//google-themes.lug.ustc.edu.cn', '//fonts-gstatic.lug.ustc.edu.cn']
			],
			'custom'	=> [
				'title'		=> '自定义加速服务',
				'fields'	=> fn()=> wpjam_map(self::get_search(), fn($v)=> ['placeholder'=>'请输入'.str_replace('//', '', $v).'加速服务地址']),
				'replace'	=> fn()=> wpjam_map(self::get_search(), fn($v, $k)=> str_replace(['http://','https://'], '//', wpjam_basic_get_setting($k) ?: $v))
			]
		];
	}

	public static function filter_html($html){
		$object 	= self::get(wpjam_basic_get_setting('google_fonts'));
		$replace	= $object ? $object->get_arg('replace') : '';

		return ($replace && count($replace) == 4) ? str_replace(self::get_search(), $replace, $html) : $html;
	}
}

wpjam_register_option('wpjam-basic', [
	'title'				=> '优化设置',
	'model'				=> 'WPJAM_Basic',
	'order'				=> 20,
	'site_default'		=> true,
	'sanitize_callback'	=> 'flush_rewrite_rules'
]);

// Gravatar
function wpjam_register_gravatar($name, $args){
	return WPJAM_Gravatar::register($name, $args);
}

// Google Font
function wpjam_register_google_font($name, $args){
	return WPJAM_Google_Font::register($name, $args);
}

function wpjam_basic_get_setting($name, $default=null){
	return WPJAM_Basic::get_setting($name, $default);
}

function wpjam_basic_update_setting($name, $value){
	return WPJAM_Basic::update_setting($name, $value);
}

function wpjam_basic_delete_setting($name){
	return WPJAM_Basic::delete_setting($name);
}

function wpjam_add_basic_sub_page($sub_slug, $args=[]){
	wpjam_add_menu_page($sub_slug, ['parent'=>'wpjam-basic']+$args);
}
