<?php
/*
Name: 优化设置
URI: https://mp.weixin.qq.com/s/zkA0Nx4u81PCZWByQq3iiA
Description: 优化设置通过屏蔽和增强功能来加快 WordPress 的加载
Version: 2.0
*/
class WPJAM_Basic extends WPJAM_Option_Model{
	public static function get_sections(){
		$for_field	= ['options'=>array_column(get_taxonomies(['public'=>true, 'hierarchical'=>true], 'objects'), 'label', 'name')];
		$for_field	+= (count($for_field['options']) <= 1 ? ['type'=>'hidden', 'value'=>'category'] : ['before'=>'分类模式：']);
		$no_base	= ['no_category_base'=>['label'=>'去掉分类目录链接中的 category。', 'fields'=>['no_category_base_for'=>$for_field]]];

		return [
			'disabled'	=>['title'=>'功能屏蔽',	'fields'=>[
				'basic'		=>['title'=>'常规功能',	'fields'=>[
					'disable_revisions'			=>['label'=>'屏蔽文章修订功能，精简文章表数据。',		'value'=>1],
					'disable_trackbacks'		=>['label'=>'彻底关闭Trackback，防止垃圾留言。',		'value'=>1],
					'disable_xml_rpc'			=>['label'=>'关闭XML-RPC功能，只在后台发布文章。',	'value'=>1],
					'disable_auto_update'		=>['label'=>'关闭自动更新功能，通过手动或SSH方式更新。'],
					'disable_feed'				=>['label'=>'屏蔽站点Feed，防止文章被快速被采集。'],
					'disable_admin_email_check'	=>['label'=>'屏蔽站点管理员邮箱定期验证功能。'],
				]],
				'convert'	=>['title'=>'转换功能',	'fields'=>[
					'disable_emoji'				=>['label'=>'屏蔽Emoji转换成图片功能，直接使用Emoji。',		'value'=>1],
					'disable_texturize'			=>['label'=>'屏蔽字符转换成格式化的HTML实体功能。', 			'value'=>1],
					'disable_capital_P_dangit'	=>['label'=>'屏蔽WordPress大小写修正，自行决定如何书写。',	'value'=>1],
				]],
				'backend'	=>['title'=>'后台功能',	'fields'=>[
					'disable_privacy'			=>['label'=>'移除为欧洲通用数据保护条例生成的页面。',	'value'=>1],
					'disable_dashboard_primary'	=>['label'=>'移除仪表盘的「WordPress 活动及新闻」。'],
					'disable_backend'			=>['sep'=>'&emsp;',	'before'=>'移除后台界面右上角：',	'fields'=>[
						'disable_help_tabs'			=>['label'=>'帮助'],
						'disable_screen_options'	=>['label'=>'选项。',],
					]]
				]],
				'page'		=>['title'=>'页面功能',	'fields'=>[
					'disable_head_links'	=>['label'=>'移除页面头部版本号和服务发现标签代码。'],
					'disable_admin_bar'		=>['label'=>'移除工具栏和后台个人资料中工具栏相关选项。']
				]],
				'embed'		=>['title'=>'嵌入功能',	'fields'=>[
					'disable_autoembed'	=>['label'=>'禁用Auto Embeds功能，加快页面解析速度。'],
					'disable_embed'		=>['label'=>'屏蔽嵌入其他WordPress文章的Embed功能。'],
				]],
				'gutenberg'	=>['title'=>'古腾堡编辑器',	'fields'=>[
					'disable_block_editor'			=>['label'=>'屏蔽Gutenberg编辑器，换回经典编辑器。'],
					'disable_widgets_block_editor'	=>['label'=>'屏蔽小工具区块编辑器模式，切换回经典模式。']
				]],
			]],
			'enhance'	=>['title'=>'增强优化',	'fields'=>[
				'x-frame-options'		=>['title'=>'Frame嵌入',		'options'=>[''=>'所有网页', 'SAMEORIGIN'=>'只允许同域名网页', 'DENY'=>'不允许任何网页']],
				'no_category_base'		=>['title'=>'分类链接简化',	'group'=>true,	'fields'=>$no_base],
				'timestamp_file_name'	=>['title'=>'图片时间戳',		'label'=>'给上传的图片加上时间戳，防止大量的SQL查询。'],
				'optimized_by_wpjam'	=>['title'=>'WPJAM Basic',	'label'=>'在网站底部显示：Optimized by WPJAM Basic。']
			]],
		];
	}

	public static function disabled($feature, ...$args){
		return self::get_setting('disable_'.$feature, ...$args);
	}

	public static function init(){
		wpjam_map(['trackbacks'=>'tb', 'embed'=>'embed'], fn($v, $k)=> self::disabled($k) && $GLOBALS['wp']->remove_query_var($v));

		wpjam_map(['trackbacks', 'revisions'], fn($v)=> self::disabled($v, 1) && wpjam_map(['post', 'page'], fn($pt)=> remove_post_type_support($pt, $v)));
	}

	public static function add_hooks(){
		add_action('wp_loaded',	fn()=> ob_start(fn($html)=> apply_filters('wpjam_html', $html)));

		// 修正任意文件删除漏洞
		add_filter('wp_update_attachment_metadata',	fn($data)=> (isset($data['thumb']) ? ['thumb'=>basename($data['thumb'])] : [])+$data);

		if(self::get_setting('x-frame-options')){
			add_action('send_headers', fn()=> header('X-Frame-Options: '.self::get_setting('x-frame-options')));
		}

		// 去掉URL中category，跳转到 no base 的 link
		if(self::get_setting('no_category_base')){
			$tax	= self::get_setting('no_category_base_for', 'category');

			add_filter('register_taxonomy_args', fn($args, $name)=> array_merge($args, $name == $tax ? ['permastruct'=>'%'.$tax.'%'] : []), 8, 2);

			if($tax == 'category' && str_starts_with($_SERVER['REQUEST_URI'], '/category/')){	
				add_action('template_redirect', fn()=> wp_redirect(site_url(substr($_SERVER['REQUEST_URI'], 10)), 301));
			}
		}

		// 防止重名造成大量的 SQL
		if(self::get_setting('timestamp_file_name')){
			wpjam_hooks('add', ['wp_handle_sideload_prefilter', 'wp_handle_upload_prefilter'], fn($file)=> empty($file['md5_filename']) ? array_merge($file, ['name'=> time().'-'.$file['name']]) : $file);
		}

		// 屏蔽站点 Feed
		if(self::disabled('feed')){
			add_action('template_redirect', fn()=> is_feed() && wp_die('Feed已经关闭, 请访问<a href="'.get_bloginfo('url').'">网站首页</a>！', 'Feed关闭', 200));
		}

		// 移除 WP_Head 版本号和服务发现标签代码
		if(self::disabled('head_links')){
			add_filter('the_generator', fn()=> '');

			wpjam_hooks('remove', 'wp_head', ['rsd_link', 'wlwmanifest_link', 'feed_links_extra', 'index_rel_link', 'parent_post_rel_link', 'start_post_rel_link', 'adjacent_posts_rel_link_wp_head','wp_shortlink_wp_head', 'rest_output_link_wp_head']);

			wpjam_hooks('remove', 'template_redirect', ['wp_shortlink_header', 'rest_output_link_header']);

			wpjam_hooks('add', ['style_loader_src', 'script_loader_src'], fn($src)=> $src ? preg_replace('/[\&\?]ver='.preg_quote($GLOBALS['wp_version']).'(&|$)/', '', $src) : $src);
		}

		// 屏蔽WordPress大小写修正
		if(self::disabled('capital_P_dangit', 1)){
			wpjam_hooks('remove', ['the_content', 'the_title', 'wp_title', 'document_title', 'comment_text', 'widget_text_content'], 'capital_P_dangit');
		}

		// 屏蔽字符转码
		if(self::disabled('texturize', 1)){
			add_filter('run_wptexturize', fn()=> false);
		}

		//移除 admin bar
		if(self::disabled('admin_bar')){
			add_filter('show_admin_bar', fn()=> false);
		}

		//禁用 XML-RPC 接口
		if(self::disabled('xml_rpc', 1)){
			add_filter('xmlrpc_enabled', fn()=> false);
			add_filter('xmlrpc_methods', fn()=> []);

			remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');
		}

		// 屏蔽古腾堡编辑器
		if(self::disabled('block_editor')){
			wpjam_hooks('remove', ['wp_enqueue_scripts', 'admin_enqueue_scripts'], 'wp_common_block_scripts_and_styles');
			wpjam_hook('remove', 'the_content', 'do_blocks');
		}

		// 屏蔽小工具区块编辑器模式
		if(self::disabled('widgets_block_editor')){
			add_filter('gutenberg_use_widgets_block_editor', fn()=> false);
			add_filter('use_widgets_block_editor', fn()=> false);
		}

		// 屏蔽站点管理员邮箱验证功能
		if(self::disabled('admin_email_check')){
			add_filter('admin_email_check_interval', fn()=> 0);
		}

		// 屏蔽 Emoji
		if(self::disabled('emoji', 1)){
			add_action('admin_init', fn()=> wpjam_hooks('remove', [
				['admin_print_scripts',	'print_emoji_detection_script'],
				['admin_print_styles',	'print_emoji_styles']
			]));

			wpjam_hooks('remove', [
				['wp_head',			'print_emoji_detection_script'],
				['embed_head',		'print_emoji_detection_script'],
				['wp_print_styles',	'print_emoji_styles']
			]);

			wpjam_hooks('remove', [
				['the_content_feed',	'wp_staticize_emoji'],
				['comment_text_rss',	'wp_staticize_emoji'],
				['wp_mail',				'wp_staticize_emoji_for_email']
			]);

			add_filter('emoji_svg_url',		fn()=> false);
			add_filter('tiny_mce_plugins',	fn($plugins)=> array_diff($plugins, ['wpemoji']));
		}

		//禁用文章修订功能
		if(self::disabled('revisions', 1)){
			if(!defined('WP_POST_REVISIONS')){
				define('WP_POST_REVISIONS', false);
			}

			wpjam_hook('remove', 'pre_post_update', 'wp_save_post_revision');

			add_filter('register_meta_args', fn($args, $defaults, $meta_type, $meta_key)=> ($meta_type == 'post' && !empty($args['object_subtype']) && in_array($args['object_subtype'], ['post', 'page'])) ? array_merge($args, ['revisions_enabled'=>false]) : $args, 10, 4);
		}

		// 屏蔽Trackbacks
		if(self::disabled('trackbacks', 1)){
			if(!self::disabled('xml_rpc', 1)){	//彻底关闭 pingback
				add_filter('xmlrpc_methods', fn($methods)=> wpjam_except($methods, ['pingback.ping', 'pingback.extensions.getPingbacks']));
			}

			wpjam_hooks('remove', [
				['do_pings',		'do_all_pings'],		//禁用 pingbacks, enclosures, trackbacks
				['publish_post',	'_publish_post_hook']	//去掉 _encloseme 和 do_ping 操作。
			]);
		}

		//禁用 Auto OEmbed
		if(self::disabled('autoembed')){
			wpjam_hooks('remove', ['edit_form_advanced', 'edit_page_form'], [$GLOBALS['wp_embed'], 'maybe_run_ajax_cache']);
			wpjam_hooks('remove', ['the_content', 'widget_text_content', 'widget_block_content'], [$GLOBALS['wp_embed'], 'autoembed']);
		}

		// 屏蔽文章Embed
		if(self::disabled('embed')){
			wpjam_hooks('remove', 'wp_head', ['wp_oembed_add_discovery_links', 'wp_oembed_add_host_js']);
		}

		// 屏蔽自动更新和更新检查作业
		if(self::disabled('auto_update')){
			add_filter('automatic_updater_disabled', fn()=> true);

			wpjam_hooks('remove', array_map(fn($v)=> [$v, $v], ['wp_version_check', 'wp_update_plugins', 'wp_update_themes']));
			wpjam_hook('remove', 'init', 'wp_schedule_update_checks');
		}

		// 屏蔽后台隐私
		if(self::disabled('privacy', 1)){
			wpjam_hooks('remove', 'user_request_action_confirmed', ['_wp_privacy_account_request_confirmed', '_wp_privacy_send_request_confirmation_notification']);

			wpjam_hooks('remove', 'wp_privacy_personal_data_exporters', ['wp_register_comment_personal_data_exporter', 'wp_register_media_personal_data_exporter', 'wp_register_user_personal_data_exporter']);

			wpjam_hooks('remove', [
				['wp_privacy_personal_data_erasers',	'wp_register_comment_personal_data_eraser'],
				['init',								'wp_schedule_delete_old_privacy_export_files'],
				['wp_privacy_delete_old_export_files',	'wp_privacy_delete_old_export_files']
			]);

			add_filter('option_wp_page_for_privacy_policy', fn()=> 0);
		}

		if(is_admin()){
			if(self::disabled('auto_update')){
				wpjam_hooks('remove', 'admin_init', ['_maybe_update_core', '_maybe_update_plugins', '_maybe_update_themes']);
			}

			if(self::disabled('block_editor')){
				add_filter('use_block_editor_for_post_type', fn()=> false);
			}

			if(self::disabled('help_tabs')){
				add_action('in_admin_header', fn()=> $GLOBALS['current_screen']->remove_help_tabs());
			}

			if(self::disabled('screen_options')){
				add_filter('screen_options_show_screen', fn()=> false);
				add_filter('hidden_columns', fn()=> []);
			}

			if(self::disabled('privacy', 1)){
				add_action('admin_menu', fn()=> wpjam_call_multiple('remove_submenu_page', [
					['options-general.php',	'options-privacy.php'],
					['tools.php',			'export-personal-data.php'],
					['tools.php',			'erase-personal-data.php']
				]), 11);

				add_action('admin_init', fn()=> wpjam_hooks('remove', [
					['admin_init',				['WP_Privacy_Policy_Content', 'text_change_check']],
					['edit_form_after_title',	['WP_Privacy_Policy_Content', 'notice']],
					['admin_init',				['WP_Privacy_Policy_Content', 'add_suggested_content']],
					['post_updated',			['WP_Privacy_Policy_Content', '_policy_page_updated']],
					['list_pages',				'_wp_privacy_settings_filter_draft_page_titles'],
				]), 1);
			}

			if(self::disabled('dashboard_primary')){
				add_action('do_meta_boxes', fn($screen, $context)=> str_contains($screen, 'dashboard') && remove_meta_box('dashboard_primary', $screen, $context), 10, 2);
			}
		}
	}
}

class WPJAM_Gravatar{
	public static function __callStatic($method, $args){
		if($method == 'get_options'){
			return wpjam('gravatar')+['custom'=>[
				'title'		=> '自定义',	
				'fields'	=> ['gravatar_custom'=>['placeholder'=>'请输入 Gravatar 加速服务地址']]
			]];
		}elseif($method == 'get_replace'){
			$name	= wpjam_basic_get_setting('gravatar');
			$value	= $name == 'custom' ? wpjam_basic_get_setting('gravatar_custom') : ($name ? wpjam('gravatar', $name.'.url') : '');

			return $value ? fn($url)=> str_replace(array_map(fn($v)=>$v.'gravatar.com/avatar/', ['https://secure.', 'http://0.', 'http://1.', 'http://2.']), $value, $url) : null;
		}
	}

	public static function get_sections(){
		$fields	= ['gravatar'=>['type'=>'select', 'after'=>'加速服务', 'show_option_none'=>__('&mdash; Select &mdash;'), 'options'=>self::get_options()]];

		return wpjam_set('enhance.fields.gravatar', ['title'=>'Gravatar加速', 'label'=>true, 'type'=>'fieldset', 'fields'=>$fields]);
	}

	public static function filter_pre_data($args, $id_or_email){
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

		if($avatarurl){
			return $args+['found_avatar'=>true, 'url'=>wpjam_get_thumbnail($avatarurl, $args)];
		}

		if($cb = self::get_replace()){
			add_filter('get_avatar_url', $cb);
		}

		return $args+['user_id'=>$user_id, 'email'=>$email];
	}

	public static function add_hooks(){
		wpjam_map([
			'geekzu'	=> ['title'=>'极客族',		'url'=>'https://sdn.geekzu.org/avatar/'],
			'loli'		=> ['title'=>'loli',		'url'=>'https://gravatar.loli.net/avatar/'],
			'sep_cc'	=> ['title'=>'sep.cc',		'url'=>'https://cdn.sep.cc/avatar/'],
			'7ed'		=> ['title'=>'7ED',			'url'=>'https://use.sevencdn.com/avatar/'],
			'cravatar'	=> ['title'=>'Cravatar',	'url'=>'https://cravatar.cn/avatar/'],
		], fn($v, $k)=> wpjam('gravatar', $k, $v));

		add_filter('pre_get_avatar_data', [self::class, 'filter_pre_data'], 10, 2);
	}
}

class WPJAM_Google_Font{
	public static function __callStatic($method, $args){
		if($method == 'get_search'){
			return [
				'googleapis_fonts'			=> '//fonts.googleapis.com',
				'googleapis_ajax'			=> '//ajax.googleapis.com',
				'googleusercontent_themes'	=> '//themes.googleusercontent.com',
				'gstatic_fonts'				=> '//fonts.gstatic.com'
			];
		}elseif($method == 'get_replace'){
			$search	= self::get_search();
			$name	= wpjam_basic_get_setting('google_fonts');

			if($name == 'custom'){
				$value	= wpjam_map($search, fn($v, $k)=> str_replace(['http://','https://'], '//', wpjam_basic_get_setting($k) ?: $v));
			}else{
				$value	= $name ? wpjam('google_font', $name.'.replace') : '';
			}

			return $value ? fn($html)=> str_replace($search, $value, $html) : null;
		}elseif($method == 'get_options'){
			return wpjam('google_font')+['custom'=>[
				'title'		=> '自定义',
				'fields'	=> wpjam_map(self::get_search(), fn($v)=> ['placeholder'=>'请输入'.str_replace('//', '', $v).'加速服务地址'])
			]];
		}
	}

	public static function get_sections(){
		$fields	= ['google_fonts'=>['type'=>'select', 'after'=>'加速服务', 'show_option_none'=>__('&mdash; Select &mdash;'), 'options'=>self::get_options()]];

		return wpjam_set('enhance.fields.google_fonts', ['title'=>'Google字体加速', 'type'=>'fieldset', 'label'=>true, 'fields'=>$fields]);
	}

	public static function add_hooks(){
		wpjam_map([
			'geekzu'	=> [
				'title'		=> '极客族',
				'replace'	=> ['//fonts.geekzu.org', '//gapis.geekzu.org/ajax', '//gapis.geekzu.org/g-themes', '//gapis.geekzu.org/g-fonts']
			],
			'loli'		=> [
				'title'		=> 'loli',
				'replace'	=> ['//fonts.loli.net', '//ajax.loli.net', '//themes.loli.net', '//gstatic.loli.net']
			],
			'ustc'		=> [
				'title'		=> '中科大',
				'replace'	=> ['//fonts.lug.ustc.edu.cn', '//ajax.lug.ustc.edu.cn', '//google-themes.lug.ustc.edu.cn', '//fonts-gstatic.lug.ustc.edu.cn']
			]
		], fn($v, $k)=> wpjam('google_font', $k, $v));

		if($cb = self::get_replace()){
			add_filter('wpjam_html', $cb);
		}
	}
}

class WPJAM_Static_CDN{
	public static function __callStatic($method, $args){
		$hosts	= wpjam('static_cdn');

		if($method == 'get_options'){
			return wpjam_fill($hosts, fn($v)=> parse_url($v, PHP_URL_HOST));
		}elseif(in_array($method, ['get_setting', 'replace'])){
			$host	= wpjam_basic_get_setting('static_cdn');
			$host	= $host && in_array($host, $hosts) ? $host : $hosts[0];

			return $method == 'get_setting' ? $host : (($args[0] && !str_starts_with($args[0], $host)) ? str_replace($hosts, $host, $args[0]) : $args[0]);
		}
	}

	public static function get_sections(){
		return wpjam_set('enhance.fields.static_cdn', ['title'=>'前端公共库', 'options'=>self::get_options()]);
	}

	public static function add_hooks(){
		wpjam_map([
			'https://cdnjs.cloudflare.com/ajax/libs',
			'https://s4.zstatic.net/ajax/libs',
			'https://cdnjs.snrat.com/ajax/libs',
			'https://lib.baomitu.com',
			'https://cdnjs.loli.net/ajax/libs',
			'https://use.sevencdn.com/ajax/libs',
		], fn($v)=> wpjam('static_cdn[]', $v));

		foreach(['style', 'script'] as $asset){
			add_filter($asset.'_loader_src', [self::class, 'replace']);

			add_filter('current_theme_supports-'.$asset, fn($check, $args, $value)=> !array_diff($args, (is_array($value[0]) ? $value[0] : $value)), 10, 3);
		}
	}
}

wpjam_register_option('wpjam-basic', [
	'title'			=> '优化设置',
	'model'			=> 'WPJAM_Basic',
	'summary'		=> __FILE__,
	'site_default'	=> true,
	'menu_page'		=> ['menu_title'=>'WPJAM', 'sub_title'=>'优化设置', 'icon'=>'ri-rocket-fill', 'position'=>'58.99']
]);

wpjam_add_option_section('wpjam-basic',	['order'=>20, 'model'=>'WPJAM_Static_CDN']);
wpjam_add_option_section('wpjam-basic',	['order'=>19, 'model'=>'WPJAM_Gravatar']);
wpjam_add_option_section('wpjam-basic',	['order'=>18, 'model'=>'WPJAM_Google_Font']);

function wpjam_register_gravatar($name, $args){
	return wpjam('gravatar', $name, $args);
}

function wpjam_register_google_font($name, $args){
	return wpjam('google_font', $name, $args);
}

function wpjam_add_static_cdn($host){
	return wpjam('static_cdn[]', $host);
}

function wpjam_get_static_cdn(){
	return WPJAM_Static_CDN::get_setting();
}

function wpjam_basic_get_setting($name, ...$args){
	return WPJAM_Basic::get_setting($name, ...$args);
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
