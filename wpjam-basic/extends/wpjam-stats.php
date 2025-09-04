<?php
/*
Name: 统计代码
URI: https://mp.weixin.qq.com/s/C_Dsjy8ahr_Ijmcidk61_Q
Description: 统计代码扩展最简化插入 Google 分析和百度统计的代码。
Version: 1.0
*/
class WPJAM_Site_Stats{
	public static function get_fields(){
		return ['stats'	=> ['title'=>'统计代码',	'type'=>'fieldset',	'wrap_tag'=>'fieldset',	'fields'=>[
			'baidu_tongji_id'		=>['title'=>'百度统计 ID：',		'type'=>'text'],
			'google_analytics_id'	=>['title'=>'Google分析 ID：',	'type'=>'text'],
		]]];
	}

	public static function add_hooks(){
		wpjam_add_action('wp_head', ['check'=>fn()=> !is_preview(), 'once'=>true, 'callback'=> function(){
			$id	= WPJAM_Custom::get_setting('google_analytics_id');

			$id && wpjam_echo(<<<JS
			<script async src="https://www.googletagmanager.com/gtag/js?id=$id"></script>
			<script>
				window.dataLayer = window.dataLayer || [];
				function gtag(){dataLayer.push(arguments);}
				gtag('js', new Date());

				gtag('config', '$id');
			</script>
			JS);

			$id	= WPJAM_Custom::get_setting('baidu_tongji_id');

			$id && wpjam_echo(<<<JS
			<script type="text/javascript">
				var _hmt = _hmt || [];
				(function(){
				var hm = document.createElement("script");
				hm.src = "https://hm.baidu.com/hm.js?$id";
				hm.setAttribute('async', 'true');
				document.getElementsByTagName('head')[0].appendChild(hm);
				})();
			</script>
			JS);
		}], 11);
	}
}

wpjam_add_option_section('wpjam-custom', ['model'=>'WPJAM_Site_Stats']);
