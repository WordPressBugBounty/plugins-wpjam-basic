<?php
function wpjam_get_aliyun_oss_thumbnail($img_url, $args=[]){
	if($img_url && (!wpjam_is_image($img_url) || !wpjam_is_cdn_url($img_url))){
		return $img_url;
	}
	
	$args	= wp_parse_args($args, [
		'mode'		=> null,
		'crop'		=> 1,
		'width'		=> 0,
		'height'	=> 0,
		'webp'		=> wpjam_cdn_get_setting('webp'),
		'interlace'	=> wpjam_cdn_get_setting('interlace'),
		'quality'	=> wpjam_cdn_get_setting('quality'),
		'watermark'	=> ''
	]);

	$width	= $args['width'];
	$height	= $args['height'];
	$width	= $width > 4096 ? '' : $width;
	$height	= $height > 4096 ? '' : $height;

	$thumb_arg	= '';

	if($width || $height){
		$thumb_arg	.= '/resize'.($args['mode'] ?? ($args['crop'] && ($width && $height) ? ',m_fill' : ''));	// 只有都设置了宽度和高度才裁剪
		$thumb_arg	.= ($width ? ',w_'.$width : '').($height ? ',h_'.$height : '');
	}

	$thumb_arg	.= ($args['webp'] && wpjam_is_webp_supported()) ? '/format,webp' : ($args['interlace'] ? '/interlace,1' : '');
	$thumb_arg	.= $args['quality'] ? '/quality,Q_'.$args['quality'] : '';

	if((!empty($args['content']) || $args['watermark']) && !str_contains($img_url, 'watermark/') && !str_contains($img_url, '.gif') && !isset($_GET['preview'])){
		$watermark	= $args['watermark'] ?: wpjam_cdn_get_setting('watermark');
		$watermark	= $watermark && str_contains($watermark, CDN_HOST.'/') ? str_replace(CDN_HOST.'/', '', $watermark) : '';

		if($watermark && array_all(['width', 'height'], fn($k)=> $args[$k] >= (int)wpjam_cdn_get_setting('wm_'.$k))){
			$thumb_arg	.= '/watermark,image_'.base64_urlencode($watermark);
			$dissolve	= wpjam_cdn_get_setting('dissolve') ?: '100';
			$thumb_arg	.= $dissolve && $dissolve != 100 ? ',t_'.$dissolve : '';

			if($gravity = wpjam_cdn_get_setting('gravity', 'SouthEast')){
				$gravity_options = [
					'SouthEast'	=> 'se',
					'SouthWest'	=> 'sw',
					'NorthEast'	=> 'ne',
					'NorthWest'	=> 'nw',
					'Center'	=> 'center',
					'West'		=> 'west',
					'East'		=> 'east',
					'North'		=> 'north',
					'South'		=> 'south',
				];

				$gravity	= $gravity_options[$gravity] ?? (in_array($gravity, $gravity_options) ? $gravity : '');
				$thumb_arg	.= $gravity ? ',g_'.$gravity : '';
				[$dx,$dy]	= wpjam_map(['dx', 'dy'], fn($k)=> wpjam_cdn_get_setting($k, 10));
				$thumb_arg	.= ($dx ? ',x_'.$dx : '').($dy ? ',y_'.$dy : '');
			}
		}
	}

	if($thumb_arg){
		$query		= parse_url($img_url, PHP_URL_QUERY);
		$img_url	= $query ? str_replace('?'.$query, '', $img_url) : $img_url;
		$query_args	= $query ? wp_parse_args($query) : [];
		$img_url	= add_query_arg(['x-oss-process'=>'image'.$thumb_arg]+$query_args, $img_url);
	}

	return $img_url;
}

return 'wpjam_get_aliyun_oss_thumbnail';