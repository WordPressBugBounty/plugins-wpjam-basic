<?php
/*
Name: 文章目录
URI: https://mp.weixin.qq.com/s/vgNtvc1RcWyVCmnQdxAV0A
Description: 自动根据文章内容里的子标题提取出文章目录，并显示在内容前。
Version: 1.0
*/
class WPJAM_Toc extends WPJAM_Option_Model{
	public static function get_fields(){
		$fields	= array_filter([
			'script'	=> current_theme_supports('script', 'toc')	? '' : ['title'=>'JS代码',	'type'=>'textarea'],
			'css'		=> current_theme_supports('style', 'toc')	? '' : ['title'=>'CSS代码',	'type'=>'textarea']
		]);

		return [
			'individual'=> ['title'=>'单独设置',	'type'=>'checkbox',	'value'=>1,	'label'=>'文章列表和编辑页面可以单独设置是否显示文章目录以及显示到第几级。'],
			'depth'		=> ['title'=>'显示层级',	'type'=>'select',	'value'=>6,	'options'=>['2'=>'h2','3'=>'h3','4'=>'h4','5'=>'h5','6'=>'h6']],
			'position'	=> ['title'=>'显示位置',	'type'=>'select',	'value'=>'content',	'options'=>['content'=>'显示在文章内容前面', 'shortcode'=>'使用[toc]插入内容中', 'function'=>'调用函数<code>wpjam_get_toc()</code>显示']],
		]+($fields ? [
			'auto'		=> ['title'=>'自动插入',	'type'=>'checkbox',	'value'=>1,	'label'=>'自动插入文章目录的 JavaScript 和 CSS 代码。。', 'fields'=>$fields,	'description'=>'如不自动插入也可以将相关的代码复制主题的对应文件中。<br />请点击这里获取<a href="https://blog.wpjam.com/m/toc-js-css-code/" target="_blank">文章目录的默认 JS 和 CSS</a>']
		] : []);
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

		return '';
	}

	public static function add_item($m, $index=false){
		$attr	= $m[2] ? shortcode_parse_atts($m[2]) : [];
		$attr	= wp_parse_args($attr, ['class'=>'', 'id'=>'']);

		if(!$attr['class'] || !str_contains($attr['class'], 'toc-noindex')){
			$attr['class']	= wpjam_join(' ', $attr['class'] ,($index ? 'toc-index' : ''));
			$attr['id']		= $attr['id'] ?: 'toc_'.(count(wpjam('toc'))+1);

			wpjam('toc[]', ['text'=>trim(strip_tags($m[3])), 'depth'=>$m[1],	'id'=>$attr['id']]);
		}

		return wpjam_tag('h'.$m[1], $attr, $m[3]);
	}

	public static function filter_content($content){
		$post_id	= get_the_ID();
		$depth		= self::get_setting('depth', 6);

		if(self::get_setting('individual', 1)){
			if(get_post_meta($post_id, 'toc_hidden', true)){
				$depth	= 0;
			}elseif(metadata_exists('post', $post_id, 'toc_depth')){
				$depth	= get_post_meta($post_id, 'toc_depth', true);
			}
		}

		if($depth){
			$index		= str_contains($content, '[toc]');
			$position	= self::get_setting('position', 'content');
			$content	= wpjam_preg_replace('#<h([1-'.$depth.'])\b([^>]*)>(.*?)</h\1>#', fn($m)=> self::add_item($m, $index), $content);

			if($index){
				return str_replace('[toc]', self::render(), $content);
			}elseif($position == 'content'){
				return self::render().$content;
			}
		}

		return $content;
	}

	public static function on_head(){
		if(is_singular()){
			echo current_theme_supports('script', 'toc') ? '' : '<script type="text/javascript">'."\n".self::get_setting('script')."\n".'</script>'."\n";
			echo current_theme_supports('style', 'toc')  ? '' : '<style type="text/css">'."\n".self::get_setting('css')."\n".'</style>'."\n";
		}
	}

	public static function add_hooks(){
		wpjam_add_filter('the_content', [
			'callback'	=> [self::class, 'filter_content'],
			'check'		=> fn()=> !doing_filter('get_the_excerpt') && wpjam_is('single', get_the_ID()),
			'once'		=> true
		], 11);

		self::get_setting('auto', 1) && add_action('wp_head', [self::class, 'on_head']);

		self::get_setting('individual', 1) && is_admin() && wpjam_register_post_option('wpjam-toc', [
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

wpjam_register_option('wpjam-toc', [
	'model'		=> 'WPJAM_Toc',
	'title'		=> '文章目录',
	'menu_page'	=> ['tab_slug'=>'toc', 'plugin_page'=>'wpjam-posts', 'summary'=> __FILE__]
]);

function wpjam_get_toc(){
	return is_singular() ? WPJAM_Toc::render() : '';
}