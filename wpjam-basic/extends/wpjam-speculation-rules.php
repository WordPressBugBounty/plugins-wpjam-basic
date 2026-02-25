<?php
/*
Name: 推测加载
URI: https://mp.weixin.qq.com/s/DAqil-PxyL8rxzWBiwlA3A
Description: 将网页推测加载规则放到 Head 以及支持设置规则的触发时机
Version: 2.0
*/
class WPJAM_Speculation_Rules extends WPJAM_Option_Model{
	public static function get_fields(){
		return ['speculation_rules'	=> ['title'=>'推测加载',	'type'=>'fieldset',	'fields'=>[
			'speculation_rules_eagerness'	=> ['before'=>'触发时机：',	'options'=>[
				'conservative'	=> ['title'=>'保守', 'description'=>'接近点击时才触发：只有鼠标被按下的那一瞬间。'],
				'moderate'		=> ['title'=>'适度', 'description'=>'明确用户意向才触发：鼠标悬停/聚焦在链接一段时间，或者反复将鼠标移至链接附近。'],
				'eager'			=> ['title'=>'积极', 'description'=>'轻微用户行为即触发：鼠标移向/短暂悬停/聚焦在链接上，链接处于页面显眼位置并页面暂停滚动。'],
				'immediate'		=> ['title'=>'立即', 'description'=>'无用户行为也触发：规则解析完成后立刻开始预加载所有匹配的链接。',	'disabled'],
			]]
		]]];
	}

	public static function add_hooks(){
		remove_action('wp_footer', 'wp_print_speculation_rules');
		add_action('wp_head', 'wp_print_speculation_rules');

		add_filter('wp_speculation_rules_configuration', fn($config)=> is_array($config) ? ['eagerness'=> self::get_setting('speculation_rules_eagerness') ?: 'auto']+$config : $config);

		// add_action('send_headers', fn()=> header('Speculation-Rules: "'.home_url('api/speculation/rules.json').'"'));

		// wpjam_register_json('speculation.rules', ['callback'=>function(){
		// 	header('Content-Type: application/speculationrules+json');
		// 	echo wpjam_json_encode(wp_get_speculation_rules()); die;
		// }]);
	}
}

wpjam_add_option_section('wpjam-basic', 'enhance', ['model'=>'WPJAM_Speculation_Rules']);