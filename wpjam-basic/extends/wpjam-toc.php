<?php
/*
Name: 文章目录
URI: https://mp.weixin.qq.com/s/vgNtvc1RcWyVCmnQdxAV0A
Description: 自动根据文章内容里的子标题提取出文章目录，并显示在内容前。
Version: 1.0
*/
class WPJAM_Toc extends WPJAM_Option_Model{
	public static function get_fields(){
		$fields	= [
			'script'	=> ['title'=>'JS代码：',	'type'=>'textarea',	'class'=>''],
			'css'		=> ['title'=>'CSS代码：',	'type'=>'textarea',	'class'=>'']
		];

		if(current_theme_supports('style', 'toc')){
			unset($fields['css']);
		}

		if(current_theme_supports('script', 'toc')){
			unset($fields['script']);
		}

		return [
			'individual'=> ['title'=>'单独设置',	'type'=>'checkbox',	'value'=>1,	'label'=>'文章列表和编辑页面可以单独设置是否显示文章目录以及显示到第几级。'],
			'depth'		=> ['title'=>'显示到：',	'type'=>'select',	'value'=>6,	'options'=>['2'=>'h2','3'=>'h3','4'=>'h4','5'=>'h5','6'=>'h6']],
			'position'	=> ['title'=>'显示位置',	'type'=>'select',	'value'=>'content',	'options'=>['content'=>'显示在文章内容前面','shortcode'=>'使用[toc]插入内容中','function'=>'调用函数<code>wpjam_get_toc()</code>显示']],
		]+($fields ? ['auto'=>['title'=>'自动插入',	'fields'=>['auto'=> ['type'=>'checkbox', 'value'=>1,	'fields'=>$fields,	'label'=>'自动插入文章目录的 JavaScript 和 CSS 代码。。', 'description'=>'如不自动插入也可以将相关的代码复制主题的对应文件中。<br />请点击这里获取<a href="https://blog.wpjam.com/m/toc-js-css-code/" target="_blank">文章目录的默认 JS 和 CSS</a>']] ]] : []);
	}

	public static function get_depth($post_id){
		if(self::get_setting('individual', 1)){
			if(get_post_meta($post_id, 'toc_hidden', true)){
				return;
			}

			if(metadata_exists('post', $post_id, 'toc_depth')){
				return get_post_meta($post_id, 'toc_depth', true);
			}
		}

		return self::get_setting('depth', 6);
	}

	public static function render(){
		$index	= '';
		$path	= [];

		foreach(wpjam('toc') as $item){
			if($path){
				if(end($path) < $item['depth']){
					$index	.= "\n<ul>\n";
				}elseif(end($path) == $item['depth']){
					$index	.= "</li>\n";

					array_pop($path);
				}else{
					while(end($path) > $item['depth']){
						$index	.= "</li>\n</ul>\n";

						array_pop($path);
					}
				}
			}

			$index	.= '<li class="toc-level'.$item['depth'].'"><a href="#'.$item['id'].'">'.$item['text'].'</a>';
			$path[]	= $item['depth'];
		}

		if($path){
			$index	.= "</li>\n".str_repeat("</li>\n</ul>\n", count($path)-1);
			$index	= "<ul>\n".$index."</ul>\n";

			return '<div id="toc">'."\n".'<p class="toc-title"><strong>文章目录</strong><span class="toc-controller toc-controller-show">[隐藏]</span></p>'."\n".$index.'</div>'."\n";
		}
	}

	public static function add_item($m){
		$attr	= $m[2] ? shortcode_parse_atts($m[2]) : [];
		$attr	= wp_parse_args($attr, ['class'=>'', 'id'=>'']);

		if(!$attr['class'] || !str_contains($attr['class'], 'toc-noindex')){
			$attr['class']	.= ($attr['class'] ? ' ' : '').'toc-index';
			$attr['id']		= $attr['id'] ?: 'toc_'.(count(wpjam('toc'))+1);

			wpjam('toc[]', ['text'=>trim(strip_tags($m[3])), 'depth'=>$m[1],	'id'=>$attr['id']]);
		}

		return wpjam_tag('h'.$m[1], $attr, $m[3]);
	}

	public static function add_hooks(){
		add_filter('the_content', function($content){
			if(!is_singular() 
				|| get_the_ID() != get_queried_object_id() 
				|| doing_filter('get_the_excerpt') 
				|| (self::get_setting('position') == 'shortcode' && !str_contains($content, '[toc]'))
			){
				return $content;
			}

			$depth		= self::get_depth(get_the_ID());
			$content	= wpjam_preg_replace('#<h([1-'.$depth.'])\b([^>]*)>(.*?)</h\1>#', fn($m)=> self::add_item($m), $content);

			if($toc	= self::render()){
				if(str_contains($content, '[toc]')){
					return str_replace('[toc]', $toc, $content);
				}elseif(self::get_setting('position', 'content') == 'content'){
					return $toc.$content;
				}
			}

			return $content;
		}, 11);

		if(self::get_setting('auto', 1)){
			add_action('wp_head', function(){
				if(is_singular()){
					if(!current_theme_supports('script', 'toc')){
						echo '<script type="text/javascript">'."\n".self::get_setting('script')."\n".'</script>'."\n";	
					}

					if(!current_theme_supports('style', 'toc')){
						echo '<style type="text/css">'."\n".self::get_setting('css')."\n".'</style>'."\n";
					}
				}
			});
		}

		if(is_admin() && self::get_setting('individual', 1)){
			wpjam_register_post_option('wpjam-toc', [
				'title'			=> '文章目录',
				'context'		=> 'side',
				'list_table'	=> true,
				'post_type'		=> fn($post_type)=> is_post_type_viewable($post_type) && post_type_supports($post_type, 'editor'),
				'fields'		=> [
					'toc_hidden'	=> ['title'=>'不显示：',	'type'=>'checkbox',	'description'=>'不显示文章目录'],
					'toc_depth'		=> ['title'=>'显示到：',	'type'=>'select',	'options'=>[''=>'默认','2'=>'h2','3'=>'h3','4'=>'h4','5'=>'h5','6'=>'h6'],	'show_if'=>['toc_hidden', '=', 0]]
				]
			]);
		}
	}
}

wpjam_register_option('wpjam-toc', [
	'model'		=> 'WPJAM_Toc',
	'title'		=> '文章目录',
	'menu_page'	=> ['tab_slug'=>'toc', 'plugin_page'=>'wpjam-posts', 'summary'=> __FILE__]
]);

function wpjam_get_toc(){
	return is_singular() ? WPJAM_Toc::render() : '';
}