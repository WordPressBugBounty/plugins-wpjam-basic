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
			'baidu_tongji_id'		=>['title'=>'百度统计 ID：',		'type'=>'text', 'pattern'=>'[a-fA-F0-9]{32}'],
			'google_analytics_id'	=>['title'=>'Google分析 ID：',	'type'=>'text', 'pattern'=>'(?:G-[A-Z0-9]+|UA-[0-9]+-[0-9]+|GT-[A-Z0-9]+)'],
		]]];
	}

	public static function add_hooks(){
		wpjam_once('wp_head', function(){
			if(is_preview()){
				return;
			}

			$ids	= wpjam_map(['google_analytics_id', 'baidu_tongji_id'], fn($k)=> WPJAM_Custom::get_setting($k));
			$src	= esc_url('https://www.googletagmanager.com/gtag/js?id='.$ids[0]);

			[$google, $baidu]	= array_map(fn($id)=> $id ? wpjam_json_encode($id, 'attr') : '', $ids);

			if($google){
				wpjam_echo(<<<JS
			<script async src="$src"></script>
			<script>
				window.dataLayer = window.dataLayer || [];
				function gtag(){dataLayer.push(arguments);}
				gtag('js', new Date());

				gtag('config', $google);
			</script>
			JS);
			}

			if($baidu){
				wpjam_echo(<<<JS
			<script type="text/javascript">
				var _hmt = _hmt || [];
				(function(){
				var hm = document.createElement("script");
				hm.src = "https://hm.baidu.com/hm.js?" + $baidu;
				hm.setAttribute('async', 'true');
				document.getElementsByTagName('head')[0].appendChild(hm);
				})();
			</script>
			JS);
			}
		}, 11);
	}
}

wpjam_add_option_section('wpjam-custom', ['model'=>'WPJAM_Site_Stats']);
