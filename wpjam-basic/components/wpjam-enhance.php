<?php
/*
Name: 功能增强
Version: 2.0
*/
class WPJAM_Enhance{
	public static function get_section(){
		$options	= array_column(get_taxonomies(['public'=>true, 'hierarchical'=>true], 'objects'), 'label', 'name');
		$for_field	= count($options) <= 1 ? ['type'=>'hidden', 'value'=>'category'] : ['before'=>'分类模式：', 'options'=>$options];

		return ['title'=>'增强优化',	'fields'=>[
			'x-frame-options'		=>['title'=>'Frame嵌入',		'options'=>[''=>'所有网页', 'SAMEORIGIN'=>'只允许同域名网页', 'DENY'=>'不允许任何网页']],
			'no_category_base'		=>['title'=>'分类链接简化',	'fields'=>[
				'no_category_base'=>['label'=>'去掉分类目录链接中的 category。', 'fields'=>['no_category_base_for'=>$for_field]
			]]],
			'timestamp_file_name'	=>['title'=>'图片时间戳',		'label'=>'给上传的图片加上时间戳，防止大量的SQL查询。'],
			'optimized_by_wpjam'	=>['title'=>'WPJAM Basic',	'label'=>'在网站底部显示：Optimized by WPJAM Basic。']
		]];
	}

	public static function add_hooks(){
		// 修正任意文件删除漏洞
		add_filter('wp_update_attachment_metadata',	fn($data)=> (isset($data['thumb']) ? ['thumb'=>basename($data['thumb'])] : [])+$data);

		$options	= wpjam_basic_get_setting('x-frame-options');
		$options	&& add_action('send_headers', fn()=> header('X-Frame-Options: '.$options));

		// 防止重名造成大量的 SQL
		if(wpjam_basic_get_setting('timestamp_file_name')){
			wpjam_hooks('wp_handle_sideload_prefilter, wp_handle_upload_prefilter', fn($file)=> array_merge($file, empty($file['md5_filename']) ? ['name'=> time().'-'.$file['name']] : []));
		}

		if(wpjam_basic_get_setting('no_category_base')){
			$tax	= wpjam_basic_get_setting('no_category_base_for', 'category');

			$tax == 'category' && str_starts_with($_SERVER['REQUEST_URI'], '/category/') && add_action('template_redirect', fn()=> wp_redirect(site_url(substr($_SERVER['REQUEST_URI'], 10)), 301));

			add_filter('register_taxonomy_args', fn($args, $name)=> array_merge($args, $name == $tax ? ['permastruct'=>'%'.$tax.'%'] : []), 8, 2);
		}
	}
}

class WPJAM_Gravatar{
	public static function get_fields(){
		return ['gravatar'=>['title'=>'Gravatar加速', 'label'=>true, 'type'=>'fieldset', 'fields'=>[
			'gravatar'=>['after'=>'加速服务', 'options'=>wpjam_parse_options('gravatar')+['custom'=>[
				'title'		=> '自定义',	
				'fields'	=> ['gravatar_custom'=>['placeholder'=>'请输入 Gravatar 加速服务地址']]
			]]]
		]]];
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

		$name	= wpjam_basic_get_setting('gravatar');
		$value	= $name == 'custom' ? wpjam_basic_get_setting('gravatar_custom') : ($name ? wpjam('gravatar', $name.'.url') : '');

		$value && add_filter('get_avatar_url', fn($url)=> str_replace(array_map(fn($v)=>$v.'gravatar.com/avatar/', ['https://secure.', 'http://0.', 'http://1.', 'http://2.']), $value, $url));

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
	public static function get_search(){
		return [
			'googleapis_fonts'			=> '//fonts.googleapis.com',
			'googleapis_ajax'			=> '//ajax.googleapis.com',
			'googleusercontent_themes'	=> '//themes.googleusercontent.com',
			'gstatic_fonts'				=> '//fonts.gstatic.com'
		];
	}

	public static function get_fields(){
		return ['google_fonts'=>['title'=>'Google字体加速', 'type'=>'fieldset', 'label'=>true, 'fields'=>[
			'google_fonts'=>['type'=>'select', 'after'=>'加速服务', 'options'=>wpjam_parse_options('google_font')+['custom'=>[
				'title'		=> '自定义',
				'fields'	=> wpjam_map(self::get_search(), fn($v)=> ['placeholder'=>'请输入'.str_replace('//', '', $v).'加速服务地址'])
			]]]
		]]];
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

		$search	= self::get_search();
		$name	= wpjam_basic_get_setting('google_fonts');
		$value	= $name == 'custom' ? wpjam_map($search, fn($v, $k)=> str_replace(['http://','https://'], '//', wpjam_basic_get_setting($k) ?: $v)) : ($name ? wpjam('google_font', $name.'.replace') : '');

		$value && add_filter('wpjam_html', fn($html)=> str_replace($search, $value, $html));
	}
}

class WPJAM_Static_CDN{
	public static function get_setting(){
		$hosts	= wpjam('static_cdn');
		$host	= wpjam_basic_get_setting('static_cdn');

		return $host && in_array($host, $hosts) ? $host : $hosts[0];
	}

	public static function get_fields(){
		return ['static_cdn'=>['title'=>'前端公共库', 'options'=>wpjam_fill(wpjam('static_cdn'), fn($v)=> parse_url($v, PHP_URL_HOST))]];
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

		$host	= self::get_setting();
		$hosts	= array_diff(wpjam('static_cdn'), [$host]);

		foreach(['style', 'script'] as $type){
			add_filter($type.'_loader_src', fn($src)=> $src && !str_starts_with($src, $host) ? str_replace($hosts, $host, $src) : $src);

			add_filter('current_theme_supports-'.$type, fn($check, $args, $value)=> !array_diff($args, (is_array($value[0]) ? $value[0] : $value)), 10, 3);
		}
	}
}

wpjam_map([
	['model'=>'WPJAM_Enhance'],
	['model'=>'WPJAM_Static_CDN',	'order'=>20],
	['model'=>'WPJAM_Gravatar',		'order'=>19],
	['model'=>'WPJAM_Google_Font',	'order'=>18]
], fn($v)=> wpjam_add_option_section('wpjam-basic', 'enhance', $v+['plugin_page'=>'wpjam-basic']));

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