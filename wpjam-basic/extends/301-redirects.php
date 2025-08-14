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
		return parent::get_actions()+['update_setting'=> [
			'title'				=> '设置',
			'overall'			=> true,
			'class'				=> 'button-primary',
			'value_callback'	=> [self::class, 'get_setting']
		]];
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

	public static function on_template_redirect(){
		$url	= wpjam_get_current_page_url();

		if(is_404()){
			$rules	= [
				['feed/atom/',	fn($url)=> str_replace('feed/atom/', '', $url)],
				['page/',		fn($url)=> wpjam_preg_replace('/page\/(.*)\//', '',  $url)]
			];

			if(!get_option('page_comments')){
				$rules[]	= ['comment-page-',	fn($url)=> wpjam_preg_replace('/comment-page-(.*)\//', '',  $url)];
			}

			$rule	= array_find($rules, fn($rule)=> str_contains($url, $rule[0]));
			$rule && wp_redirect(wpjam_call($rule[1], $url)) && exit;
		}

		if(is_404() || self::get_setting('redirect_all')){
			foreach(self::parse_items() as $redirect){
				if(!empty($redirect['request']) && !empty($redirect['destination'])){
					$request		= set_url_scheme($redirect['request']);
					$destination	= !empty($redirect['type']) ? preg_replace('#'.$request.'#', $redirect['destination'], $url) : ($request == $url ? $redirect['destination'] : '');

					$destination && $destination != $url && wp_redirect($destination, 301) && exit;
				}
			}
		}
	}

	public static function add_hooks(){
		add_action('template_redirect', [self::class, 'on_template_redirect'], 99);
	}
}

wpjam_add_menu_page('redirects', [
	'plugin_page'	=> 'wpjam-links',
	'title'			=> '链接跳转',
	'summary'		=> __FILE__,
	'function'		=> 'list',
	'model'			=> 'WPJAM_Redirect',
	'list_table'	=> ['title'=>'跳转规则',	'plural'=>'redirects',	'singular'=>'redirect']
]);


