<?php
/*
Name: 文章目录
URI: https://mp.weixin.qq.com/s/vgNtvc1RcWyVCmnQdxAV0A
Description: 自动根据文章内容里的子标题提取出文章目录，并显示在内容前。
Version: 3.0
*/
class WPJAM_Toc extends WPJAM_Option_Model{
	public static function get_fields(){
		return [
			'individual'=> ['title'=>'单独设置',	'type'=>'checkbox',	'value'=>1,	'label'=>'文章列表和编辑页面可以单独设置是否显示文章目录以及显示到第几级。'],
			'depth'		=> ['title'=>'显示层级',	'type'=>'select',	'value'=>6,	'options'=> wpjam_fill(range(2, 6), fn($v)=> 'H'.$v)],
			'position'	=> ['title'=>'显示位置',	'type'=>'select',	'value'=>'content',	'options'=>['content'=>'显示在文章内容前面', 'shortcode'=>'使用[toc]插入内容中', 'function'=>'显示在侧边栏/调用函数<code>wpjam_get_toc()</code>显示']],
		]+(current_theme_supports('style', 'toc') ? [] : [
			'css'		=> ['title'=>'显示样式',	'type'=>'textarea',	'description'=>'也可以将相关的样式代码复制主题的对应文件中，点击这里获取<a href="https://blog.wpjam.com/m/toc-js-css-code/" target="_blank">文章目录的默认的 CSS</a>']
		]);
	}

	public static function filter_content($content){
		$depth	= self::get_setting('individual', 1) ? (int)get_post_meta(get_the_ID(), 'toc_depth', true) : 0;
		$depth	= $depth ?: self::get_setting('depth', 6);

		if($depth && $depth != -1){
			$i		= 0;
			$stack	= [];
			$output	= $toc = '';
			$index	= str_contains($content, '[toc]');
			$tag	= $index ? 'ol' : 'ul';
			$parts	= preg_split('#(<h([1-'.$depth.'])\b([^>]*)>(.*?)</h\2>)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

			while($i < count($parts)){
				$part	= $parts[$i];

				if(str_starts_with($part, '<h') && $part[2] >= 1 && $part[2] <= $depth){
					$level	= (int)$parts[$i+1];
					$attr	= $parts[$i+2] ? shortcode_parse_atts($parts[$i+2]) : [];

					if(!str_contains($attr['class'] ?? '', 'toc-noindex')){
						if(empty($attr['id'])){
							$attr['id']	= 'toc_'.($i+1);
						}

						if($stack){
							if($level > array_last($stack)){
								$toc	.= "\n<".$tag.">\n";
								$output	.= $index ? '<section class="section">'."\n" : '';
							}else{
								while($level <= array_last($stack)){
									$toc	.= "</li>\n";

									if($level < array_pop($stack)){
										$toc	.= "</".$tag.">\n";
										$output	.= $index ? "</section>\n" : '';
									}
								}
							}
						}

						$stack[]	= $level;
						$toc		.= '<li><a href="#'.$attr['id'].'">'.trim(strip_tags($parts[$i+3])).'</a>';
					}
					
					$output	.= wpjam_tag('h'.$level, $attr, $parts[$i+3]);
					$i 		+= 4;
				}else{
					$output	.= $part;
					$i 		+= 1;
				}
			}

			if($stack){
				$content	= $output.str_repeat("</section>\n", count($stack)-1);
				$toc		= self::wrap(wpjam_var('toc', "<".$tag.">\n".$toc.str_repeat("</li>\n</".$tag.">\n", count($stack))));
			}

			if($index){
				return str_replace('[toc]', $toc, $content);
			}elseif(self::get_setting('position', 'content') == 'content'){
				return $toc.$content;
			}
		}

		return $content;
	}

	public static function wrap($toc){
		return '<details id="toc" open>'."\n".'<summary>文章目录</summary>'."\n".$toc.'</details>'."\n";
	}

	public static function add_hooks(){
		wpjam_register_widget('wpjam-toc', 'WPJAM - 文章目录', [
			'classname'	=> 'widget_toc',
			'fields'	=> ['title'	=> ['type'=>'text', 'before'=>'列表标题：', 'class'=>'medium-text']],
			'widget'	=> fn()=> is_singular() ? (string)wpjam_var('toc') : ''
		]);

		wpjam_hook('the_content', [
			'callback'	=> [self::class, 'filter_content'],
			'check'		=> fn()=> !doing_filter('get_the_excerpt') && wpjam_is('single', get_the_ID())
		], 11);

		self::get_setting('css') && wpjam_hook('wp_head', [
			'callback'	=> fn()=> wpjam_echo('<style type="text/css">'."\n".self::get_setting('css')."\n".'</style>'),
			'check'		=> fn()=> is_singular() && !current_theme_supports('style', 'toc')
		]);

		self::get_setting('individual', 1) && wpjam_register_post_option('wpjam-toc', [
			'title'			=> '文章目录',
			'context'		=> 'side',
			'list_table'	=> true,
			'post_type'		=> fn($type)=> is_post_type_viewable($type) && post_type_supports($type, 'editor'),
			'fields'		=> ['toc_depth'	=> [
				'title'			=> '层级：',
				'type'			=> 'select',
				'show_in_rest'	=> ['type'=>'integer'],
				'default'		=> 0,
				'options'		=> [0=>'默认']+wpjam_fill(range(2, 6), fn($v)=> 'H'.$v)+[-1=>'不显示']
			]]
		]);
	}
}

wpjam_register_option('wpjam-toc', [
	'model'		=> 'WPJAM_Toc',
	'title'		=> '文章目录',
	'menu_page'	=> ['tab_slug'=>'toc', 'plugin_page'=>'wpjam-posts', 'summary'=> __FILE__]
]);

function wpjam_get_toc(){
	return ($toc = is_singular() ? (string)wpjam_var('toc') : '') ? WPJAM_Toc::wrap($toc) : '';
}