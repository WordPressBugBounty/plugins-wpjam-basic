<?php
function wpjam_get_qcloud_cos_thumbnail($img_url, $args=[]){
	if($img_url && (!wpjam_is_image($img_url) || !wpjam_is_cdn_url($img_url))){
		return $img_url;
	}

	$args	= wp_parse_args($args, [
		'crop'		=> 1,
		'width'		=> 0,
		'height'	=> 0,
		'webp'		=> wpjam_cdn_get_setting('webp') && wpjam_is_webp_supported(),
		'interlace'	=> wpjam_cdn_get_setting('interlace'),
		'quality'	=> wpjam_cdn_get_setting('quality'),
		'watermark'	=> '',
		'dissolve'	=> wpjam_cdn_get_setting('dissolve') ?: 100,
		'gravity'	=> wpjam_cdn_get_setting('gravity') ?: 'SouthEast',
		'dx'		=> wpjam_cdn_get_setting('dx') ?: 10,
		'dy'		=> wpjam_cdn_get_setting('dy') ?: 10,
		'spcent'	=> 10
	]);

	$width	= $args['width'];
	$height	= $args['height'];
	$width	= $width > 10000 ? '' : $width;
	$height	= $height > 10000 ? '' : $height;

	$thumb_arg	= '';

	if($width || $height){
		$thumb_arg	.= '/thumbnail/';

		if($width && $height){
			$thumb_arg	.= '!'.$width.'x'.$height.'r';
			$thumb_arg	.= $args['crop'] ? '|imageMogr2/gravity/Center/crop/'.$width.'x'.$height.'' : '';	// 只有都设置了宽度和高度才裁剪
		}else{
			$thumb_arg	.= $width.'x'.$height;
		}
	}

	$thumb_arg	.= $args['webp'] ? '/format/webp' : ($args['interlace'] ? '/interlace/'.$args['interlace'] : '');
	$thumb_arg	.= $args['quality'] ? '/quality/'.$args['quality'] : '';
	$thumb_arg	= $thumb_arg ? 'imageMogr2'.$thumb_arg : '';

	if((!empty($args['content']) || $args['watermark']) && strpos($img_url, '.gif') === false){
		$watermark	= $args['watermark'] ?: wpjam_cdn_get_setting('watermark');

		if(array_all(['width', 'height'], fn($k)=> $args[$k] >= (int)wpjam_cdn_get_setting('wm_'.$k)) && $watermark){
			$thumb_arg	.= ($thumb_arg ? '|' : '').'watermark/1/image/'.base64_urlencode($watermark);
			$thumb_arg	= array_reduce(['dissolve', 'gravity', 'dx', 'dy', 'spcent'], fn($c, $k)=> $c.'/'.$k.'/'.$args[$k], $thumb_arg);
		}
	}

	if($thumb_arg){
		$query_args	= [];

		if($query = parse_url($img_url, PHP_URL_QUERY)){
			$img_url	= str_replace('?'.$query, '', $img_url);
			$query_args	= wpjam_filter(wp_parse_args($query), fn($v, $k)=> strpos($k, 'imageMogr2/') === false && strpos($k, 'watermark/') === false);
		}

		$query_args[$thumb_arg]	= '';

		return add_query_arg($query_args, $img_url);
	}

	return $img_url;
}

return 'wpjam_get_qcloud_cos_thumbnail';