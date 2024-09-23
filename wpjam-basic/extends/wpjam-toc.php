<?php
/*
Name: 文章目录
URI: https://mp.weixin.qq.com/s/vgNtvc1RcWyVCmnQdxAV0A
Description: 自动根据文章内容里的子标题提取出文章目录，并显示在内容前。
Version: 1.0
*/
class WPJAM_Toc_Setting extends WPJAM_Option_Model{
	public static function filter_content($content){
		if(self::get_setting('position') == 'shortcode' && strpos($content, '[toc]') === false){
			return $content;
		}

		$post_id	= get_the_ID();

		if(doing_filter('get_the_excerpt') || !is_singular() || $post_id != get_queried_object_id()){
			return $content;
		}

		$depth	= self::get_setting('depth', 6);

		if(self::get_setting('individual', 1)){
			if(get_post_meta($post_id, 'toc_hidden', true)){
				return $content;
			}

			if(metadata_exists('post', $post_id, 'toc_depth')){
				$depth = get_post_meta($post_id, 'toc_depth', true);
			}
		}

		$object	= wpjam_add_instance('toc', $post_id, new WPJAM_Toc($content, $depth));
		$toc	= $object->get_toc();

		if($toc){
			if(strpos($content, '[toc]') !== false){
				$content	= str_replace('[toc]', $toc, $content);
			}elseif(self::get_setting('position', 'content') == 'content'){
				$content	= $toc.$content;
			}
		}

		return $content;
	}

	public static function on_head(){
		if(is_singular()){
			if(!current_theme_supports('script', 'toc')){
				echo '<script type="text/javascript">'."\n".self::get_setting('script')."\n".'</script>'."\n";	
			}

			if(!current_theme_supports('style', 'toc')){
				echo '<style type="text/css">'."\n".self::get_setting('css')."\n".'</style>'."\n";
			}
		}
	}

	public static function get_fields(){
		$fields	= [
			'script'	=> ['title'=>'JS代码',	'type'=>'textarea',	'class'=>''],
			'css'		=> ['title'=>'CSS代码',	'type'=>'textarea',	'class'=>'']
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
		]+($fields ? ['auto'=> ['title'=>'自动插入',	'type'=>'checkbox', 'value'=>1,	'fields'=>$fields,	'label'=>'自动插入文章目录的 JavaScript 和 CSS 代码。<br /><br />如不自动插入也可以将相关的代码复制主题的对应文件中。<br />请点击这里获取<a href="https://blog.wpjam.com/m/toc-js-css-code/" target="_blank">文章目录的默认 JS 和 CSS</a>。']] : []);
	}

	public static function get_menu_page(){
		return [
			'tab_slug'		=> 'toc',
			'plugin_page'	=> 'wpjam-posts', 
			'function'		=> 'option',
			'option_name'	=> 'wpjam-toc',
			'summary'		=> __FILE__,
		];
	}

	public static function add_hooks(){
		add_filter('the_content',	[self::class, 'filter_content'], 11);

		if(self::get_setting('auto', 1)){
			add_action('wp_head',	[self::class, 'on_head']);
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

class WPJAM_Toc{
	protected $items	= [];

	public function __construct(&$content, $depth=6){
		$content	= preg_replace_callback('#<h([1-'.$depth.'])(.*?)>(.*?)</h\1>#', [$this, 'add_item'], $content);
	}

	public function get_toc(){
		if(empty($this->items)){
			return '';
		}

		$index		= '<ul>'."\n";
		$prev_depth	= 0;
		$to_depth	= 0;

		foreach($this->items as $i => $item){
			$depth	= $item['depth'];

			if($prev_depth){
				if($depth == $prev_depth){
					$index .= '</li>'."\n";
				}elseif($depth > $prev_depth){
					$to_depth++;
					$index .= "\n".'<ul>'."\n";
				}else{
					$to_depth2 = ($to_depth > ($prev_depth - $depth))? ($prev_depth - $depth) : $to_depth;

					if($to_depth2){
						for($i=0; $i<$to_depth2; $i++){
							$index .= '</li>'."\n".'</ul>'."\n";
							$to_depth--;
						}
					}

					$index .= '</li>'."\n";
				}
			}

			$prev_depth	= $depth;

			$index .= '<li class="toc-level'.$depth.'"><a href="#'.$item['id'].'">'.$item['text'].'</a>';
		}

		for($i=0; $i<=$to_depth; $i++){
			$index .= '</li>'."\n".'</ul>'."\n";
		}

		return '<div id="toc">'."\n".'<p class="toc-title"><strong>文章目录</strong><span class="toc-controller toc-controller-show">[隐藏]</span></p>'."\n".$index.'</div>'."\n";
	}

	public function add_item($matches){
		$attr	= $matches[2] ? shortcode_parse_atts($matches[2]) : [];

		$attr['class']	= $attr['class'] ?? '';
		$attr['class']	= wp_parse_list($attr['class']);

		if(!$attr['class'] || !in_array('toc-noindex', $attr['class'])){
			$attr['class'][]= 'toc-index';
			$attr['id']		= !empty($attr['id']) ? $attr['id'] : 'toc_'.(count($this->items)+1);

			$this->items[]	= ['text'=>trim(strip_tags($matches[3])), 'depth'=>$matches[1],	'id'=>$attr['id']];
		}

		return wpjam_tag('h'.$matches[1], $attr, $matches[3]);
	}
}

wpjam_register_option('wpjam-toc',	[
	'model'	=> 'WPJAM_Toc_Setting',
	'title'	=> '文章目录'
]);

function wpjam_get_toc(){
	$post_id	= get_the_ID();
	$object		= $post_id ? wpjam_get_instance('toc', $post_id) : null;

	return $object ? $object->get_toc() : '';
}

