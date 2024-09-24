<?php
/*
Name: 常用简码
URI: https://blog.wpjam.com/m/wpjam-basic-shortcode/
Description: 添加 email, list, table, bilibili, youku, qqv 等常用简码，并在后台罗列系统的所有可用的简码。
Version: 1.0
*/
class WPJAM_Shortcode{
	public static function callback($attr, $content, $tag){
		$attr		= array_map('esc_attr', (array)$attr);
		$content	= wp_kses($content, 'post');

		if($tag == 'email'){
			$attr	= shortcode_atts(['mailto'=>false], $attr);

			return antispambot($content, $attr['mailto']);
		}elseif(in_array($tag, ['bilibili', 'youku', 'tudou', 'qqv', 'sohutv'])){
			return wpjam_video($content, $attr) ?: wp_video_shortcode(array_merge($attr, ['src'=>$content]));
		}elseif($tag == 'code'){
			$attr	= shortcode_atts(['type'=>'php'], $attr);
			$type	= $attr['type'] == 'html' ? 'markup' : $attr['type'];

			$content	= str_replace("<br />\n", "\n", $content);
			$content	= str_replace("</p>\n", "\n\n", $content);
			$content	= str_replace("\n<p>", "\n", $content);
			$content	= str_replace('&amp;', '&', esc_textarea($content)); // wptexturize 会再次转化 & => &#038;

			$content	= trim($content);

			return $type ? '<pre><code class="language-'.$type.'">'.$content.'</code></pre>' : '<pre>'.$content.'</pre>';
		}elseif($tag == 'list'){
			$attr		= shortcode_atts(['type'=>'', 'class'=>''], $attr);
			$content	= str_replace(["\r\n", "<br />\n", "</p>\n", "\n<p>"], "\n", $content);
			$output		= '';

			foreach(explode("\n", $content) as $li){
				if($li = trim($li)){
					$output .= "<li>".do_shortcode($li)."</li>\n";
				}
			}

			$class	= $attr['class'] ? ' class="'.$attr['class'].'"' : '';
			$tag	= in_array($attr['type'], ['order', 'ol']) ? 'ol' : 'ul';

			return '<'.$tag.$class.">\n".$output."</".$tag.">\n";
		}elseif($tag == 'table'){
			$attr	= shortcode_atts([
				'border'		=> '0',
				'cellpading'	=> '0',
				'cellspacing'   => '0',
				'width'			=> '',
				'class'			=> '',
				'caption'		=> '',
				'th'			=> '0',  // 0-无，1-横向，2-纵向，4-横向并且有 footer 
			], $attr);

			$output		= $thead = $tbody = '';
			$content	= str_replace(["\r\n", "<br />\n", "\n<p>", "</p>\n"], ["\n", "\n", "\n", "\n\n"], $content);

			if($attr['caption']){
				$output	.= '<caption>'.$attr['caption'].'</caption>';
			}

			$th		= $attr['th'];
			$tr_i	= 0;

			foreach(explode("\n\n", $content) as $tr){
				if($tr = trim($tr)){
					$tds	= explode("\n", $tr);

					if(($th == 1 || $th == 4) && $tr_i == 0){
						foreach($tds as $td){
							if($td = trim($td)){
								$thead .= "\t\t\t".'<th>'.$td.'</th>'."\n";
							}
						}

						$thead = "\t\t".'<tr>'."\n".$thead."\t\t".'</tr>'."\n";
					}else{
						$tbody .= "\t\t".'<tr>'."\n";
						$td_i	= 0;

						foreach($tds as $td){
							if($td = trim($td)){
								if($th == 2 && $td_i ==0){
									$tbody .= "\t\t\t".'<th>'.$td.'</th>'."\n";
								}else{
									$tbody .= "\t\t\t".'<td>'.$td.'</td>'."\n";
								}

								$td_i++;
							}
						}

						$tbody .= "\t\t".'</tr>'."\n";
					}

					$tr_i++;
				}
			}

			if($th == 1 || $th == 4){ $output .=  "\t".'<thead>'."\n".$thead."\t".'</thead>'."\n"; }
			if($th == 4){ $output .=  "\t".'<tfoot>'."\n".$thead."\t".'</tfoot>'."\n"; }

			$output	.= "\t".'<tbody>'."\n".$tbody."\t".'</tbody>'."\n";
			$attr	= wpjam_slice($attr, ['border', 'cellpading', 'cellspacing', 'width', 'class']);
			
			return wpjam_tag('table', $attr, $output);
		}
	}

	public static function query_items($args){
		foreach($GLOBALS['shortcode_tags'] as $tag => $callback){
			if(is_array($callback)){
				if(is_object($callback[0])){
					$callback	= '<p>'.get_class($callback[0]).'->'.(string)$callback[1].'</p>';
				}else{
					$callback	= '<p>'.$callback[0].'->'.(string)$callback[1].'</p>';
				}
			}elseif(is_object($callback)){
				$callback	= '<pre>'.print_r($callback, true).'</pre>';
			}else{
				$callback	= wpautop($callback);
			}

			$items[]	= ['tag'=>wpautop($tag), 'callback'=>$callback];
		}

		return $items ?? [];
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

	public static function get_list_table(){
		return [
			'model'			=> self::class,
			'primary_key'	=> 'tag',
		];
	}
}

add_shortcode('hide',		'__return_empty_string');
add_shortcode('email',		['WPJAM_Shortcode', 'callback']);
add_shortcode('list',		['WPJAM_Shortcode', 'callback']);
add_shortcode('table',		['WPJAM_Shortcode', 'callback']);
add_shortcode('code',		['WPJAM_Shortcode', 'callback']);
add_shortcode('youku',		['WPJAM_Shortcode', 'callback']);
add_shortcode('qqv',		['WPJAM_Shortcode', 'callback']);
add_shortcode('bilibili',	['WPJAM_Shortcode', 'callback']);
add_shortcode('tudou',		['WPJAM_Shortcode', 'callback']);
add_shortcode('sohutv',		['WPJAM_Shortcode', 'callback']);

wpjam_add_menu_page('wpjam-shortcodes', [
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> '常用简码',
	'network'		=> false,
	'function'		=> 'list',
	'list_table'	=> 'WPJAM_Shortcode',
	'summary'		=> __FILE__,
]);