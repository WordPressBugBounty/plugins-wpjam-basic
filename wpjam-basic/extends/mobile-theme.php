<?php
/*
Name: 移动主题
URI: https://mp.weixin.qq.com/s/DAqil-PxyL8rxzWBiwlA3A
Description: 给当前站点设置移动设备设置上使用单独的主题。
Version: 2.0
*/
class WPJAM_Mobile_Theme{
	public static function get_fields(){
		$theme		= wp_get_theme();
		$themes 	= [$theme->get_stylesheet() => $theme] + wp_get_themes(['allowed' => true]);
		$options	= array_map(fn($theme)=> $theme->get('Name'), $themes);

		return ['mobile_stylesheet'=>['title'=>'选择移动主题', 'type'=>'select', 'options'=>$options]];
	}

	public static function get_menu_page(){
		return [
			'menu_slug'		=> 'mobile-theme',
			'parent'		=> 'themes',
			'function'		=> 'option',
			'option_name'	=> 'wpjam-basic',
			'summary'		=> __FILE__,
		];
	}

	public static function add_hooks(){
		$stylesheet	= wp_is_mobile() ? wpjam_basic_get_setting('mobile_stylesheet') : null;
		$stylesheet	= $stylesheet ?: ($_GET['wpjam_theme'] ?? null);
		$theme		= $stylesheet ? wp_get_theme($stylesheet) : null;

		if($theme){
			add_filter('stylesheet',	fn()=> $theme->get_stylesheet());
			add_filter('template',		fn()=> $theme->get_template());
		}
	}
}

wpjam_register_option('wpjam-basic', [
	'title'			=> '移动主题',
	'plugin_page'	=> 'mobile-theme',
	'site_default'	=> true,
	'model'			=> 'WPJAM_Mobile_Theme'
]);