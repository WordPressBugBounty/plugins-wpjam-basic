<?php
if(did_action('init')){
	if(empty(CDN_NAME)){
		wp_die('你没开启云存储', '你没开启云存储', ['response'=>404]);
	}

	$post	= get_post(get_query_var('p'));
	$remote	= get_query_var(CDN_NAME);

	if(empty($remote)){
		wp_die('文件名不能为空', '文件名不能为空', ['response'=>404]);
	}

	if(empty($post)){
		wp_die('文章不存在', '文章不存在', ['response'=>404]);
	}

	$filename	= pathinfo($remote, PATHINFO_FILENAME);
	$url		= '';

	if(preg_match_all('|<img.*?src=[\'"](.*?)[\'"].*?>|i', do_shortcode($post->post_content), $matches)){
		foreach($matches[1] as $image_url){
			if($filename == md5($image_url)){
				$url = $image_url;
				break;
			}
		}
	}

	if(!$url){
		wp_die('文章没有该图片', '文章没有该图片', ['response'=>404]);
	}

	if(wpjam_doing_debug()){
		echo $url;
		exit;
	}

	$response		= wp_remote_get($url);
	$content_type	= $response['headers']['content-type'] ?? '';

	if(!preg_match('|^image/|', $content_type)){
		wp_die('不是图片', '', ['response'=>403]);
	}

	header('Content-Type: '.$content_type);

	echo $response['body'];

	exit;
}

add_action('init', function(){
	$GLOBALS['wp']->add_query_var(CDN_NAME);

	add_rewrite_rule(CDN_NAME.'/([0-9]+)/image/([^/]+)?$', 'index.php?p=$matches[1]&'.CDN_NAME.'=$matches[2]', 'top');

	add_action('template_redirect', function(){
		if(get_query_var(CDN_NAME)){
			include __FILE__;
		}
	}, 5);

	add_filter('the_content', function($content){
		if(!is_singular() || get_post_status() !== 'publish'){
			return $content;
		}

		if(!preg_match_all('/<img.*?src=[\'"](.*?)[\'"].*?>/i', $content, $matches)){
			return $content;
		}

		$search	= $replace = [];

		foreach($matches[0] as $i => $img_tag){
			$img_url	= $matches[1][$i];

			if($img_url && wpjam_is_external_url($img_url, false)){
				$img_type	= $img_type == 'png' ? 'png' : 'jpg';
				$img_replace	= CDN_HOST.'/'.CDN_NAME.'/'.get_the_ID().'/image/'.md5($img_url).'.'.$img_type;
				$search[]	= $img_tag;
				$replace[]	= str_replace($img_url, $img_replace, $img_tag);
			}
		}

		return $search ? str_replace($search, $replace, $content) : $content;
	}, 4);
});
