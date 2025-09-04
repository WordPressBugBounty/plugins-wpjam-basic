<?php
/*
Name: CDN 加速
URI: https://mp.weixin.qq.com/s/bie4JkmExgULgvEgx-AjUw
Description: CDN 加速使用云存储对博客的静态资源进行 CDN 加速。
Version: 2.0
*/
class WPJAM_CDN extends WPJAM_Option_Model{
	public static function get_sections(){
		$cdn_fields	= [
			'cdn_name'	=> ['title'=>'云存储',	'type'=>'select', 'options'=>[''=>'请选择']+wpjam('cdn')+['disabled'=>['title'=>'切回本站', 'description'=>'当使用 CDN 之后想切换回使用本站图片才勾选该选项，并将原 CDN 域名填到「本地设置」的「额外域名」中。']]],
			'host'		=> ['title'=>'CDN 域名',	'type'=>'url',	'description'=>'设置为在CDN云存储绑定的域名。']+self::show_if(),
			'image'		=> ['title'=>'图片处理',	'class'=>'switch',	'value'=>1,	'label'=>'开启云存储图片处理功能，使用云存储进行裁图、添加水印等操作。<br /><strong>开启之后，文章和媒体库中的所有图片都会镜像到云存储。</strong>']+self::show_if('image'),
		];

		$local_fields	= [
			'local'		=> ['title'=>'本地域名',	'type'=>'url',	'description'=>'并将该域名填入<strong>云存储的镜像源</strong>', 'value'=>home_url()],
			'exts'		=> ['title'=>'扩展名',	'fields'=>[
				'img_exts'	=> ['label'=>'所有图片扩展名，如已开启云存储图片处理功能，则该选项自动开启！'],
				'exts'		=> ['type'=>'mu-text',	'button_text'=>'添加扩展名',	'direction'=>'row',	'sortable'=>false],
			],	'description'=>'镜像到云存储的静态文件扩展名'],
			'dirs'		=> ['title'=>'目录',		'type'=>'mu-text',	'direction'=>'row',	'sortable'=>false,	'style'=>'width:120px;', 'value'=>['wp-content','wp-includes'],	'description'=>'镜像到云存储的静态文件所在目录'],
			'locals'	=> ['title'=>'额外域名',	'type'=>'mu-text',	'item_type'=>'url'],
		];

		$sections	= [
			'cdn'	=> ['title'=>'云存储设置',	'fields'=>$cdn_fields],
			'local'	=> ['title'=>'本地设置',		'fields'=>$local_fields],
		];

		if(is_network_admin()){
			return wpjam_except($sections, 'local.fields.local');
		}

		$external	= wpjam_basic_get_setting('upload_external_images');

		if(!$external && !is_multisite() && $GLOBALS['wp_rewrite']->using_mod_rewrite_permalinks() && extension_loaded('gd')){
			$remote_summary	= '*自动将外部图片镜像到云存储需要博客支持固定链接和服务器支持GD库（不支持gif图片）';
			$remote_fields	= ['remote'=>['title'=>'外部图片',	'options'=>[''=>'关闭外部图片镜像到云存储', '1'=>'自动将外部图片镜像到云存储（不推荐）']]];
		}else{
			$remote_fields	= ['external'=>['title'=>'外部图片',	'type'=>'view',	'description'=>($external ? '已在' : '请先到').'「文章设置」中开启「支持在文章列表页上传外部图片」']];
		}

		$remote_fields	+= ['exceptions'=>['title'=>'例外',	'type'=>'textarea',	'class'=>'',	'description'=>'如果外部图片的链接中包含以上字符串或域名，就不会被保存并镜像到云存储。']];

		$image_fields	= [
			'thumb'	=> ['title'=>'缩图设置',	'fields'=>[
				'no_subsizes'	=> ['value'=>1,	'label'=>'使用云存储的缩图功能，本地不再生成各种尺寸的缩略图。'],
				'thumbnail'		=> ['value'=>1,	'label'=>'使用云存储的缩图功能对文章中的图片进行最佳尺寸显示处理。', 'fields'=>[
					'max_width'	=> ['value'=>($GLOBALS['content_width'] ?? 0), 'type'=>'number', 'class'=>'small-text', 'before'=>'文章中图片最大宽度：', 'after'=>'px。']
				]]
			]],
			'image'	=> ['title'=>'格式质量',	'fields'=>[
				'webp'		=> ['label'=>'将图片转换成 WebP 格式。']+self::show_if('webp'),
				'interlace'	=> ['label'=>'JPEG格式图片渐进显示。']+self::show_if('quality'),
				'quality'	=> ['type'=>'number',	'before'=>'图片质量：',	'class'=>'small-text',	'mim'=>0,	'max'=>100]+self::show_if('quality')
			]],
			'wm'	=> ['title'=>'水印设置',	'fields'=>[
				'view'		=> ['type'=>'view',		'title'=>'使用说明：',	'value'=>'请使用云存储域名下的图片，水印设置仅应用于文章内容中的图片'],
				'watermark'	=> ['type'=>'image',	'title'=>'水印图片：'],
				'dissolve'	=> ['type'=>'number',	'title'=>'透明度：',	'class'=>'small-text',	'value'=>100, 'min'=>1, 'max'=>100],
				'gravity'	=> ['type'=>'select',	'title'=>'水印位置：',	'options'=>['SouthEast'=>'右下角', 'SouthWest'=>'左下角', 'NorthEast'=>'右上角', 'NorthWest'=>'左上角', 'Center'=>'正中间', 'West'=>'左中间', 'East'=>'右中间', 'North'=>'上中间', 'South'=>'下中间']],
				'distance'	=> ['type'=>'size',	'title'=>'水印边距：',	'fields'=>['width'=>['value'=>10], 'height'=>['value'=>10]]],
				'wm_size'	=> ['type'=>'size',	'title'=>'最小尺寸：',	'description'=>'小于该尺寸的图片都不会加上水印']+self::show_if('wm_size')
			]]+self::show_if('wm'),

			'volc_imagex_template'	=> ['title'=>'火山引擎图片处理模板']+self::show_if('volc_imagex')
		];

		return $sections+[
			'image'		=> ['title'=>'图片设置',	'fields'=>$image_fields,	'show_if'=>['image', 1]],
			'remote'	=> ['title'=>'外部图片',	'fields'=>$remote_fields,	'summary'=>$remote_summary ?? '']+self::show_if(),
		];
	}

	public static function show_if($feature=null){
		return ['show_if'=>['cdn_name', isset(wpjam('cdn')[$feature]) ? [$feature] : array_keys(wpjam_filter(wpjam('cdn'), fn($item)=> $feature ? in_array($feature, $item['supports'] ?? []) : true))]];
	}

	public static function get_setting($name='', ...$args){
		$name	= ['dx'=>'distance.width', 'dy'=>'distance.height', 'wm_width'=>'wm_size.width', 'wm_height'=>'wm_size.height'][$name] ?? $name;
		$value	= parent::get_setting($name, ...$args);

		return $name == 'watermark' ? wpjam_at($value, '?', 0) : $value;
	}

	public static function is_exception($url){
		static $exceptions;
		$exceptions	??= wpjam_lines(self::get_setting('exceptions'));

		return array_any($exceptions, fn($v)=> str_contains($url, $v));
	}

	public static function downsize($size, $args){
		$attr	= ['width', 'height'];

		[$meta, $url]	= is_numeric($args) ? [wp_get_attachment_metadata($args), wp_get_attachment_url($args)] : [$args, $args['url']];

		if(is_array($meta) && array_all($attr, fn($k)=> isset($meta[$k]))){
			$size	= wpjam_parse_size($size, 2);
			$size	= $size['crop'] ? array_map(fn($k)=> min($size[$k], $meta[$k]), $attr) : wp_constrain_dimensions(...array_merge(...array_map(fn($v)=> array_values(wpjam_pick($v, $attr)), [$meta, $size])));

			if(array_any($attr, fn($k, $i)=> $size[$i] < $meta[$k])){
				return [wpjam_get_thumbnail($url, $size), (int)($size[0]/2), (int)($size[1]/2), true];
			}elseif(is_numeric($args)){
				return [$url, ...$size, false];
			}
		}
	}

	public static function replace($str, $to_cdn=true, $html=false){
		static $locals;

		if(!isset($locals)){
			$toggle	= fn($url)=> substr_replace($url, ...($url[4] === 's' ? ['', 4, 1] : ['s', 4, 0]));
			$locals	= [$toggle(LOCAL_HOST), ...array_map('untrailingslashit', self::get_setting('locals') ?: [])];
			$locals	= array_unique(apply_filters('wpjam_cdn_local_hosts', $locals));
			$locals	= [$locals, [...$locals, $toggle(CDN_HOST), LOCAL_HOST]];
		}

		[$to, $i]	= $to_cdn ? [CDN_HOST, 1] : [LOCAL_HOST, 0];

		if($html){
			return strtr($str, array_fill_keys($locals[$i], $to));
		}

		return ($local = array_find($locals[$i], fn($v)=> str_starts_with($str, $v))) ? $to.substr($str, strlen($local)) : $str;
	}

	public static function filter_metadata($meta, $id){
		if(is_array($meta) && empty($meta['sizes']) && wp_attachment_is_image($id)){
			$args	= ['url'=>wp_get_attachment_url($id)]+$meta;
			$orient	= $meta['width'] > $meta['height'] ? 'portrait' : 'landscape';

			foreach(wp_get_registered_image_subsizes() as $name => $size){
				if($size	= self::downsize($size, $args)){
					$arr	= explode('?', $size[0]);
					$file	= implode('?', [wp_basename(array_shift($arr)), ...$arr]);

					$meta['size'][$name]	= ['file'=>$file, 'url'=>$size[0], 'width'=>$size[1], 'height'=>$size[2], 'orientation'=>$orient];
				}
			}
		}

		return $meta;
	}

	public static function filter_image_block($content, $parsed){
		return str_replace('<img', '<img'.array_reduce(['sizeSlug', 'width', 'height'], fn($c, $k)=> $c.(($v = $parsed['attrs'][$k] ?? '') ? ' data-'.$k.'="'.$v.'"' : ''), ''), $content);
	}

	public static function filter_img_tag($tag, $context, $id){
		$proc	= $context == 'the_content' ? new WP_HTML_Tag_Processor($tag) : '';
		$src	= $proc && $proc->next_tag('img') ? $proc->get_attribute('src') : '';
		$src	= self::replace($src, true, false);
	
		if(!$src || wpjam_is_external_url($src)){
			return $tag;
		}

		$attr	= ['width', 'height'];
		$size	= wpjam_fill($attr, fn($k)=> $proc->get_attribute($k) ?: (float)$proc->get_attribute('data-'.$k));
		$max	= (int)self::get_setting('max_width', ($GLOBALS['content_width'] ?? 0));
		$meta	= $id ? wp_get_attachment_metadata($id) : [];
		$meta	= is_array($meta) ? $meta : [];
		$size	= array_filter($size) ? $size : ($meta ? wpjam_pick((wpjam_get($meta, 'sizes.'.($proc->get_attribute('data-sizeSlug') ?: 'full')) ?: $meta), $attr) : ['width'=>$max]+$size);

		if(array_all($size, fn($v)=> is_numeric($v))){
			if($max && $size['width'] > $max){
				$size	= ['width'=>$max, 'height'=>(int)($max/$size['width']*$size['height'])];

				array_walk($size, fn($v, $k)=> $v ? $proc->set_attribute($k, $v) : null);
			}

			$size	= $meta ? wpjam_except($size, array_filter($attr, fn($k)=> $size[$k]*2 >= $meta[$k])) : $size;
		}

		$proc->set_attribute('src', wpjam_get_thumbnail($src, wpjam_parse_size($size+['content'=>true], 2)));

		return $proc->get_updated_html();
	}

	public static function on_plugins_loaded(){
		define('CDN_NAME',		self::get_setting('cdn_name'));
		define('CDN_HOST',		untrailingslashit(self::get_setting('host') ?: site_url()));
		define('LOCAL_HOST',	untrailingslashit(set_url_scheme(self::get_setting('local') ?: site_url())));

		if(CDN_NAME === 'disabled' || !CDN_NAME){
			return CDN_NAME ? wpjam_hooks('the_content, wpjam_thumbnail'.((is_admin() || wpjam_is_json_request()) ? '' : ', wpjam_html'), fn($html)=> self::replace($html, false, true), 5) : null;
		}

		do_action('wpjam_cdn_loaded');

		$exts	= array_diff(array_map('trim', self::get_setting('exts') ?: []), is_login() ? ['js', 'css', ''] : ['']);
		$exts	= array_unique(array_merge($exts, self::get_setting('img_exts') ? wp_get_ext_types()['image'] : []));

		wpjam_hooks([
			['wp_resource_hints',		fn($urls, $type)=> array_merge($urls, $type == 'dns-prefetch' ? [CDN_HOST] : []), 10, 2],
			['wpjam_is_external_url',	fn($status, $url, $scene)=> $status && !wpjam_is_cdn_url($url) && ($scene != 'fetch' || !self::is_exception($url)), 10, 3],
		]);

		if(self::get_setting('image')){
			$file	= wpjam('cdn', CDN_NAME.'.file');

			if($file && file_exists($file)){
				$cb	= include $file;
				$cb !== 1 && is_callable($cb) && add_filter('wpjam_thumbnail', $cb, 10, 2);
			}

			if(self::get_setting('no_subsizes', 1)){
				wpjam_hooks([
					['wp_calculate_image_srcset_meta',		fn()=> []],
					['embed_thumbnail_image_size',			fn()=> '160x120'],
					['intermediate_image_sizes_advanced',	fn($sizes)=> wpjam_pick($sizes, ['full'])],
					['wp_get_attachment_metadata',			[self::class, 'filter_metadata'], 10, 2],

					['wp_img_tag_add_srcset_and_sizes_attr, wp_img_tag_add_width_and_height_attr',	fn()=> false]
				]);
			}

			if(self::get_setting('thumbnail', 1)){
				wpjam_hooks([
					['render_block_core/image',	[self::class, 'filter_image_block'], 5, 2],
					['wp_content_img_tag',		[self::class, 'filter_img_tag'], 1, 3]
				]);
			}

			wpjam_hooks([
				['wpjam_thumbnail, wp_mime_type_icon',	[self::class, 'replace'], 1],

				['wp_get_attachment_url',	fn($url, $id)=> in_array(wpjam_file($id, 'ext'), $exts) ? self::replace($url) : $url, 10, 2],
				['image_downsize',			fn($downsize, $id, $size)=> wp_attachment_is_image($id) ? self::downsize($size, $id) : $downsize, 10, 3]
			]);
		}

		if($exts && !is_admin() && !wpjam_is_json_request()){
			$local	= '(?:https?\://|//)'.preg_quote(explode('//', LOCAL_HOST, 2)[1]);
			$dirs	= wpjam_join('|', wpjam_map(self::get_setting('dirs') ?: [], fn($v)=> preg_quote(trim($v))));
			$regex	= '#(?:'.$local.')/('.($dirs ? '(?:'.$dirs.')/' : '').'[^;"\'\s\?\>\<]+\.(?:'.wpjam_join('|', $exts).')["\'\)\s\]\?])#';

			add_filter('wpjam_html', fn($html)=> wpjam_preg_replace($regex, CDN_HOST.'/$1', self::replace($html, false, true)), 5);
		}

		if(!wpjam_basic_get_setting('upload_external_images') && self::get_setting('remote') && !is_multisite()){
			include dirname(__DIR__).'/cdn/remote.php';
		}
	}

	public static function add_hooks(){
		wpjam_map([
			'aliyun_oss'	=> [
				'title'			=> '阿里云OSS',
				'supports'		=> ['image', 'webp', 'wm', 'wm_size', 'quality'],
				'description'	=> '请点击这里注册和申请<strong><a href="http://wpjam.com/go/aliyun/" target="_blank">阿里云</a></strong>可获得代金券，点击这里查看<strong><a href="https://blog.wpjam.com/m/aliyun-oss-cdn/" target="_blank">阿里云OSS详细使用指南</a></strong>。'
			],
			'qcloud_cos'	=> [
				'title'			=> '腾讯云COS',
				'supports'		=> ['image', 'webp', 'wm', 'wm_size', 'quality'],
				'description'	=> '请点击这里注册和申请<strong><a href="http://wpjam.com/go/qcloud/" target="_blank">腾讯云</a></strong>可获得优惠券，点击这里查看<strong><a href="https://blog.wpjam.com/m/qcloud-cos-cdn/" target="_blank">腾讯云COS详细使用指南</a></strong>。'
			],
			'volc_imagex'	=> [
				'title'			=> '火山引擎veImageX',
				'supports'		=> ['image', 'webp'],
				'description'	=> '点击这里查看<strong><a href="http://blog.wpjam.com/m/volc-veimagex/" target="_blank">火山引擎 veImageX 详细使用指南</a></strong>。'
			],
			'qiniu'			=> [
				'title'			=> '七牛云存储',
				'supports'		=> ['image', 'wm', 'quality'],
			],
			'ucloud'		=> ['title'=>'UCloud']
		], fn($v, $k)=> wpjam('cdn', $k, $v+['file'=>dirname(__DIR__).'/cdn'.'/'.$k.'.php']));

		if(self::get_setting('disabled')){
			self::update_setting('cdn_name', 'disabled');
			self::delete_setting('disabled');
		}

		if(self::get_setting('image') && !self::get_setting('img_exts')){
			self::update_setting('img_exts', 1);
		}

		add_action('plugins_loaded', [self::class, 'on_plugins_loaded'], 99);
	}
}

function wpjam_register_cdn($name, $args){
	return wpjam('cdn', $name, $args);
}

function wpjam_unregister_cdn($name){
	return wpjam('cdn[]', $name, null);
}

function wpjam_cdn_get_setting($name, ...$args){
	return WPJAM_CDN::get_setting($name, ...$args);
}

function wpjam_cdn_host_replace($html, $to_cdn=true){
	return WPJAM_CDN::replace($html, $to_cdn, true);
}

function wpjam_local_host_replace($html){
	return str_replace(CDN_HOST, LOCAL_HOST, $html);
}

function wpjam_is_cdn_url($url){
	$host	= '//'.explode('//', CDN_HOST, 2)[1];

	return apply_filters('wpjam_is_cdn_url', array_any(['http:', 'https:', ''], fn($v)=> str_starts_with($url, $v.$host)), $url);
}

wpjam_register_option('wpjam-cdn',	[
	'title'			=> 'CDN加速',
	'model'			=> 'WPJAM_CDN',
	'site_default'	=> true,
	'menu_page'		=> ['parent'=>'wpjam-basic', 'position'=>2, 'summary'=>__FILE__],
]);