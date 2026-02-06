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

	public static function get_fields($action_key='', $id=0){
		if($action_key == 'update_setting'){
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

	public static function add_hooks(){
		add_action('template_redirect', function(){
			$url	= wpjam_get_current_page_url();

			if(is_404()){
				str_contains($url, ($k	= 'feed/atom/')) && wp_redirect(str_replace($k, '', $url)) && exit;

				$k	= array_find(['page/', 'comment-page-'], fn($k)=> str_contains($url, $k));
				$k && wp_redirect(wpjam_preg_replace('/'.preg_quote($k, '/').'(.*)\//', '',  $url)) && exit;
			}

			if(is_404() || self::get_setting('redirect_all')){
				foreach(self::parse_items() as $redirect){
					$r	= $redirect['request'] ?? '';
					$d	= $redirect['destination'] ?? '';

					if($r && $d){
						$r = set_url_scheme($r);
						$d	= !empty($redirect['type']) ? preg_replace('#'.$r.'#', $d, $url) : ($r == $url ? $d : '');

						$d && $d != $url && wp_redirect($d, 301) && exit;
					}
				}
			}
		}, 99);
	}
}

wpjam_add_menu_page('redirects', [
	'plugin_page'	=> 'wpjam-links',
	'title'			=> '链接跳转',
	'summary'		=> __FILE__,
	'function'		=> 'list',
	'model'			=> 'WPJAM_Redirect',
	'list_table'	=> ['title'=>'跳转规则',	'plural'=>'redirects',	'singular'=>'redirect', 'update_setting'=>true]
]);


