<?php
/*
Name: 移动主题
URI: https://mp.weixin.qq.com/s/DAqil-PxyL8rxzWBiwlA3A
Description: 给当前站点设置移动设备设置上使用单独的主题。
Version: 2.0
*/
class WPJAM_Mobile_Stylesheet{
	public static function get_sections(){
		$options	= array_map(fn($v)=> $v->get('Name'), wp_get_themes(['allowed'=>true]));
		$options	= wp_array_slice_assoc($options, [get_stylesheet()])+$options;

		return ['enhance'=>['fields'=>['mobile_stylesheet'=>['title'=>'移动主题', 'options'=>$options]]]];
	}

	public static function builtin_page_load(){
		$object	= wpjam_register_page_action('set_mobile_stylesheet', [
			'button_text'	=> '移动主题',
			'class'			=> 'mobile-theme button',
			'direct'		=> true,
			'confirm'		=> true,
			'response'		=> 'redirect',
			'callback'		=> fn()=> WPJAM_Basic::update_setting('mobile_stylesheet', wpjam_get_data_parameter('stylesheet'))
		]);

		wpjam_admin('script', <<<'EOD'
		if(wp && wp.Backbone && wp.themes && wp.themes.view.Theme){
			let original_render	= wp.themes.view.Theme.prototype.render;
			let mobile	= ".wpjam_json_encode(wpjam_basic_get_setting('mobile_stylesheet')).";
			let action	= ".wpjam_json_encode($object->get_button()).";
			wp.themes.view.Theme.prototype.render = function(){
				original_render.apply(this, arguments);

				let stylesheet	= this.$el.data('slug');

				if(stylesheet == mobile){
					this.$el.find('.theme-actions').append('<span class="mobile-theme button button-primary">移动主题</span>');
				}else{
					this.$el.find('.theme-actions').append(action.replace('data-nonce=', 'data-data="stylesheet='+stylesheet+'" data-nonce='));
				}
			};
		}
		EOD);

		// wpjam_admin('style', '.mobile-theme{position: absolute; top: 45px; right: 18px;}');
	}

	public static function add_hooks(){
		$name	= wp_is_mobile() ? wpjam_basic_get_setting('mobile_stylesheet') : null;
		$name	= $name ?: ($_GET['wpjam_theme'] ?? null);
		$theme	= $name ? wp_get_theme($name) : null;

		if($theme){
			add_filter('stylesheet',	fn()=> $theme->get_stylesheet());
			add_filter('template',		fn()=> $theme->get_template());
		}
	}
}

wpjam_add_option_section('wpjam-basic', [
	'title'			=> '移动主题',
	'model'			=> 'WPJAM_Mobile_Stylesheet',
	'order'			=> 16,
	'admin_load'	=> ['base'=>'themes'],
]);