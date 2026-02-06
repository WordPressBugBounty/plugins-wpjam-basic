<?php
/*
Name: 常用简码
URI: https://blog.wpjam.com/m/wpjam-basic-shortcode/
Description: 添加 email, list, table, bilibili, youku, qqv 等常用简码，并在后台罗列系统的所有可用的简码。
Version: 1.0
*/
class WPJAM_Shortcode{
	public static function callback($attr, $text, $tag){
		$attr	= array_map('esc_attr', (array)$attr);
		$text	= wp_kses($text, 'post');

		if($tag == 'hide'){
			return '';
		}elseif($tag == 'email'){
			return antispambot($text, shortcode_atts(['mailto'=>false], $attr)['mailto']);
		}elseif(in_array($tag, ['bilibili', 'youku', 'tudou', 'qqv', 'sohutv'])){
			return wp_video_shortcode(array_merge($attr, ['src'=>$text]));
		}elseif($tag == 'code'){
			$attr	= shortcode_atts(['type'=>'php'], $attr);
			$type	= $attr['type'] == 'html' ? 'markup' : $attr['type'];
			$text	= str_replace(["<br />\n", "</p>\n", "\n<p>"], ["\n", "\n\n", "\n"], $text);
			$text	= trim(str_replace('&amp;', '&', esc_textarea($text))); // wptexturize 会再次转化 & => &#038;

			return $type ? '<pre><code class="language-'.$type.'">'.$text.'</code></pre>' : '<pre>'.$text.'</pre>';
		}elseif($tag == 'list'){
			$attr	= shortcode_atts(['type'=>'', 'class'=>''], $attr);
			$tag	= in_array($attr['type'], ['order', 'ol']) ? 'ol' : 'ul';
			$items	= wpjam_lines(str_replace(["\r\n", "<br />\n", "</p>\n", "\n<p>"], "\n", $text), fn($v)=> "<li>".do_shortcode($v)."</li>\n");

			return '<'.$tag.($attr['class'] ? ' class="'.$attr['class'].'"' : '').">\n".implode($items)."</".$tag.">\n";
		}elseif($tag == 'table'){
			$attr	= shortcode_atts(['cellpading'=>0, 'cellspacing'=>0, 'class'=>'', 'caption'=>'', 'th'=>0], $attr);
			$render	= $attr['caption'] ? '<caption>'.$attr['caption'].'</caption>' : '';
			$rows	= wpjam_lines(str_replace(["\r\n", "<br />\n", "\n<p>", "</p>\n"], ["\n", "\n", "\n", "\n\n"], $text), "\n\n");		

			if($attr['th'] == 1 || $attr['th'] == 4){	// 1-横向，4-横向并且有 footer 
				$thead	= '<tr>'."\n".implode(wpjam_lines(array_shift($rows), fn($v)=> '<th>'.$v.'</th>'."\n")).'</tr>'."\n";
				$render .= '<thead>'."\n".$thead.'</thead>'."\n";
				$render .= $attr['th'] == 4 ? '<tfoot>'."\n".$thead.'</tfoot>'."\n" : '';
			}

			$tag	= $attr['th'] == 2 && $i == 0 ? 'th' : 'td';	// 2-纵向
			$rows	= array_map(fn($tr)=> '<tr>'."\n".implode(wpjam_lines($tr, fn($v)=> '<'.$tag.'>'.$v.'</'.$tag.'>'."\n")), $rows);
			$render	.= '<tbody>'."\n".implode($rows).'</tbody>'."\n";
			
			return wpjam_tag('table', wpjam_pick($attr, ['border', 'cellpading', 'cellspacing', 'width', 'class']), $render);
		}
	}

	public static function query_items($args){
		return wpjam_reduce($GLOBALS['shortcode_tags'], fn($carry, $callback, $tag)=> [...$carry, ['tag'=>wpautop($tag), 'callback'=>wpjam_render_callback($callback)]], []);
	}

	public static function get_actions(){
		return [];
	}

	public static function get_fields($action_key='', $id=0){
		return [
			'tag'		=> ['title'=>'简码',		'type'=>'view',	'show_admin_column'=>true],
			'callback'	=> ['title'=>'回调函数',	'type'=>'view',	'show_admin_column'=>true]
		];
	}

	public static function filter_video_override($override, $attr, $content){
		$src	= $attr['src'] ?? $content;
		$src	= $src ? wpjam_find(wpjam('video_parser'), fn($v)=> $v, fn($v)=> preg_match('#'.$v[0].'#i', $src, $matches) ? $v[1]($matches) : '') : '';

		if($src){
			$attr	= shortcode_atts(['width'=>0, 'height'=>0], $attr);
			$attr	= ($attr['width'] || $attr['height']) ? image_hwstring($attr['width'], $attr['height']).' style="aspect-ratio:4/3;"' : 'style="width:100%; aspect-ratio:4/3;"';

			return '<iframe class="wpjam_video" '.$attr.' src="'.$src.'" scrolling="no" border="0" frameborder="no" framespacing="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
		}

		return $override;
	}

	public static function add_hooks(){
		add_filter('wp_video_shortcode_override', [self::class, 'filter_video_override'], 10, 3);

		wpjam_map(['hide', 'email', 'list', 'table', 'code', 'youku', 'qqv', 'bilibili', 'tudou', 'sohutv'], fn($tag)=> add_shortcode($tag,	[self::class, 'callback']));

		wpjam_map([
			['//www.bilibili.com/video/(BV[a-zA-Z0-9]+)',				fn($m)=> 'https://player.bilibili.com/player.html?bvid='.esc_attr($m[1])],
			['//v.qq.com/(.*)iframe/(player|preview).html\?vid=(.+)',	fn($m)=> 'https://v.qq.com/'.esc_attr($m[1]).'iframe/player.html?vid='.esc_attr($m[3])],
			['//v.youku.com/v_show/id_(.*?).html',						fn($m)=> 'https://player.youku.com/embed/'.esc_attr($m[1])],
			['//www.tudou.com/programs/view/(.*?)',						fn($m)=> 'https://www.tudou.com/programs/view/html5embed.action?code='.esc_attr($m[1])],
			['//tv.sohu.com/upload/static/share/share_play.html\#(.+)',	fn($m)=> 'https://tv.sohu.com/upload/static/share/share_play.html#'.esc_attr($m[1])],
			['//www.youtube.com/watch\?v=([a-zA-Z0-9\_]+)',				fn($m)=> 'https://www.youtube.com/embed/'.esc_attr($m[1])],
		], fn($args)=> wpjam_add_video_parser(...$args));
	}
}

wpjam_add_menu_page('wpjam-shortcodes', [
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> '常用简码',
	'model'			=> 'WPJAM_Shortcode',
	'network'		=> false,
	'summary'		=> __FILE__,
	'function'		=> 'list',
	'list_table'	=> ['primary_key'=>'tag', 'numberable'=>'No.']
]);