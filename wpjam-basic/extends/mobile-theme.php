<?php
/*
Name: 移动主题
URI: https://mp.weixin.qq.com/s/DAqil-PxyL8rxzWBiwlA3A
Description: 给当前站点设置移动设备设置上使用单独的主题。
Version: 2.0
*/
class WPJAM_Mobile_Stylesheet{
	public static function get_fields(){
		$themes	= array_map(fn($v)=> $v->get('Name'), wp_get_themes(['allowed'=>true]));
		$themes	= wpjam_pick($themes, [get_stylesheet()])+$themes;

		return ['mobile_stylesheet'=>['title'=>'移动主题', 'options'=>$themes]];
	}

	public static function builtin_page_load(){
		$mobile	= wpjam_basic_get_setting('mobile_stylesheet');
		$button	= wpjam_register_page_action('set_mobile_stylesheet', [
			'button_text'	=> '移动主题',
			'class'			=> 'mobile-theme button',
			'direct'		=> true,
			'confirm'		=> true,
			'response'		=> 'redirect',
			'callback'		=> fn()=> WPJAM_Basic::update_setting('mobile_stylesheet', wpjam_get_data_parameter('stylesheet'))
		])->get_button(['data'=>['stylesheet'=>'slug']]);

		wpjam_admin('script', sprintf(<<<'JS'
		if(wp && wp.Backbone && wp.themes && wp.themes.view.Theme){
			let render	= wp.themes.view.Theme.prototype.render;

			wp.themes.view.Theme.prototype.render = function(){
				render.apply(this, arguments);

				this.$el.find('.theme-actions').append(this.$el.data('slug') == %s ? '<span class="mobile-theme button button-primary">移动主题</span>' : (%s).replace('slug', this.$el.data('slug')));
			};
		}
		JS, wpjam_json_encode($mobile), wpjam_json_encode($button)));

		// wpjam_admin('style', '.mobile-theme{position: absolute; top: 45px; right: 18px;}');
	}

	public static function add_hooks(){
		$name	= wp_is_mobile() ? wpjam_basic_get_setting('mobile_stylesheet') : null;
		$name	= $name ?: ($_GET['wpjam_theme'] ?? null);
		$theme	= $name ? wp_get_theme($name) : null;

		$theme && wpjam_map(['stylesheet', 'template'], fn($k)=> add_filter($k, fn()=> $theme->{'get_'.$k}()));
	}
}

wpjam_add_option_section('wpjam-basic', 'enhance', ['model'=>'WPJAM_Mobile_Stylesheet', 'admin_load'=>['base'=>'themes']]);

