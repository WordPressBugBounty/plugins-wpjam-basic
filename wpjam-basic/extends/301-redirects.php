<?php
/*
Name: 链接跳转
URI: https://mp.weixin.qq.com/s/e9jU49ASszsY95TrmT34TA
Description: 链接跳转扩展支持设置跳转规则来实现链接跳转。
Version: 2.0
*/
class WPJAM_Redirect extends WPJAM_Model{
	public static function get_handler(){
		return wpjam_get_handler([
			'primary_key'	=> 'id',
			'option_name'	=> 'wpjam-links',
			'items_field'	=> 'redirects',
			'max_items'		=> 50
		]);
	}

	public static function get_actions(){
		return parent::get_actions()+['set'=> [
			'title'				=> '设置',
			'overall'			=> true,
			'class'				=> 'button-primary',
			'value_callback'	=> [self::class, 'get_setting'],
			'callback'			=> [self::class, 'update_setting']
		]];
	}

	public static function get_fields($action_key='', $id=0){
		if($action_key == 'set'){
			return [
				'redirect_view'	=> ['type'=>'view',		'value'=>'默认只在404页面支持跳转，开启下面开关后，所有页面都支持跳转'],
				'redirect_all'	=> ['class'=>'switch',	'label'=>'所有页面都支持跳转'],
			];
		}

		return [
			'type'			=> ['title'=>'匹配设置',	'class'=>'switch',	'label'=>'使用正则匹配'],
			'request'		=> ['title'=>'原地址',	'type'=>'url',	'required',	'show_admin_column'=>true],
			'destination'	=> ['title'=>'目标地址',	'type'=>'url',	'required',	'show_admin_column'=>true],
		];
	}

	public static function get_list_table(){
		return [
			'title'		=> '跳转规则',
			'plural'	=> 'redirects',
			'singular'	=> 'redirect',
			'model'		=> self::class,
		];
	}

	public static function on_template_redirect(){
		$url	= wpjam_get_current_page_url();

		if(is_404()){
			if(str_contains($url, 'feed/atom/')){
				wp_redirect(str_replace('feed/atom/', '', $url), 301);
				exit;
			}

			if(!get_option('page_comments') && str_contains($url, 'comment-page-')){
				wp_redirect(preg_replace('/comment-page-(.*)\//', '',  $url), 301);
				exit;
			}

			if(str_contains($url, 'page/')){
				wp_redirect(preg_replace('/page\/(.*)\//', '',  $url), 301);
				exit;
			}
		}

		if(is_404() || self::get_setting('redirect_all')){
			foreach(self::parse_items() as $redirect){
				if(!empty($redirect['request']) && !empty($redirect['destination'])){
					$request	= set_url_scheme($redirect['request']);

					if(!empty($redirect['type'])){
						$replaced	= preg_replace('#'.$request.'#', $redirect['destination'], $url);

						if($replaced && $replaced != $url){
							wp_redirect($replaced, 301);
							exit;
						}
					}else{
						if($request == $url){
							wp_redirect($redirect['destination'], 301);
							exit;
						}
					}
				}
			}
		}
	}
}

wpjam_add_menu_page('redirects', [
	'plugin_page'	=> 'wpjam-links',
	'title'			=> '链接跳转',
	'function'		=> 'list',
	'summary'		=> __FILE__,
	'list_table'	=> 'WPJAM_Redirect',
	'hooks'			=> ['template_redirect', ['WPJAM_Redirect', 'on_template_redirect'], 99]
]);
