<?php
/*
Name: 统计代码
URI: https://mp.weixin.qq.com/s/C_Dsjy8ahr_Ijmcidk61_Q
Description: 统计代码扩展最简化插入 Google 分析和百度统计的代码。
Version: 1.0
*/
class WPJAM_Site_Stats{
	public static function get_sections(){
		return ['custom'=>['fields'=>['stats'	=> ['title'=>'统计代码',	'type'=>'fieldset',	'fields'=>[
			'baidu_tongji_id'		=>['title'=>'百度统计',		'type'=>'text'],
			'google_analytics_id'	=>['title'=>'Google分析',	'type'=>'text'],
		]]]]];
	}

	public static function on_head(){
		if(is_preview()){
			return;
		}

		$id	= WPJAM_Custom::get_setting('google_analytics_id');

		if($id){ ?>
		
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $id; ?>"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());

	gtag('config', '<?php echo $id; ?>');
</script>

		<?php }
		
		$id	= WPJAM_Custom::get_setting('baidu_tongji_id');

		if($id){ ?>

<script type="text/javascript">
	var _hmt = _hmt || [];
	(function(){
	var hm = document.createElement("script");
	hm.src = "https://hm.baidu.com/hm.js?<?php echo $id;?>";
	hm.setAttribute('async', 'true');
	document.getElementsByTagName('head')[0].appendChild(hm);
	})();
</script>

		<?php }
	}

	public static function add_hooks(){
		foreach(['baidu_tongji_id', 'google_analytics_id'] as $key){
			if(WPJAM_Custom::get_setting($key) === null){
				$value	= WPJAM_Basic::get_setting($key) ?: '';

				WPJAM_Custom::update_setting($key, $value);
				WPJAM_Basic::delete_setting($key);
			}
		}

		add_action('wp_head', [self::class, 'on_head'], 11);
	}
}

wpjam_add_option_section('wpjam-custom', ['model'=>'WPJAM_Site_Stats']);
