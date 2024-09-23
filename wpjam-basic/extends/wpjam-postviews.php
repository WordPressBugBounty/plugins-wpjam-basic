<?php
/*
Name: 文章浏览
URI: https://blog.wpjam.com/m/wpjam-postviews/
Description: 统计文章阅读数，激活该扩展，请不要再激活 WP-Postviews 插件。
Version: 1.0
*/
class WPJAM_Postviews{
	public static function get_sections(){
		return ['posts'=>['fields'=>[
			'postviews'	=> ['title'=>'初始浏览量', 'type'=>'fields', 'fields'=>[
				'views_begin'	=> ['type'=>'number',	'class'=>'small-text'], 
				'views_end'		=> ['type'=>'number',	'class'=>'small-text',	'before'=>'和',	'after'=>'之间随机数'],
			]]
		]]];
	}

	public static function redirect(){
		if(isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
			ob_start('ob_gzhandler'); 
		}

		header("Content-Type: image/png");

		$pid	= $GLOBALS['wp']->query_vars['p'];
		$views	= wpjam_get_post_views($pid);
		$im		= @imagecreate(120, 32) or die("Cannot Initialize new GD image stream");
		$bg		= imagecolorallocate($im, 191, 191, 191);
		$color	= imagecolorallocate($im, 127, 31, 31);
		$font	= 5;
		$y		= 8;
		$x		= 18 - (strlen($views) - 3)*4;

		imagestring($im, $font, $x, $y,  $views.' views', $color);
		imagepng($im);
		imagedestroy($im);

		wpjam_update_post_views($pid);

		exit;
	}

	public static function match_callback($post_type, $object){
		$pt_object	= ($post_type && $post_type != 'attachment') ? get_post_type_object($post_type) : null;

		if(!$pt_object || (empty($pt_object->viewable) && !is_post_type_viewable($post_type))){
			return false;
		}

		$object->capability	= $pt_object->cap->edit_others_posts;

		return true;
	}

	public static function update_views($post_id, $data){
		if(!empty($data['views'])){
			update_post_meta($post_id, 'views', $data['views']);
		}

		return true;
	}

	public static function add_views($post_id, $data){
		if(!empty($data['addon'])){
			wpjam_update_post_views($post_id, $data['addon']);
		}

		return true;
	}

	public static function api_callback($data, $grant){
		$post_id	= (int)$data['post_id'];
		$views		= wpjam_get_post_views($post_id);

		if($grant){
			$views	= $views + (int)$data['views'];

			update_post_meta($post_id, 'views', $views);
		}

		return ['views'=>$views];
	}

	public static function api_fields($grant){
		$fields	= ['post_id'=>['required'=>true, 'validate_callback'=>['WPJAM_Post','validate']]];

		return $fields + ($grant ? ['views'=>['required'=>true]] : []);
	}

	public static function get_rewrite_rule(){
		return ['postviews/([0-9]+)\.png?$',	'index.php?module=postviews&p=$matches[1]', 'top'];
	}

	public static function init(){
		if(is_admin()){
			wpjam_register_list_table_action('update_views', [
				'data_type'		=> 'post_type',
				'post_type'		=> [self::class, 'match_callback'],
				'callback'		=> [self::class, 'update_views'],
				'title'			=> '修改浏览数',
				'page_title'	=> '修改浏览数',
				'submit_text'	=> '修改',
				'row_action'	=> false,
				'fields'		=> ['views'=>['title'=>'浏览数', 'type'=>'number']]
			]);

			wpjam_register_list_table_action('add_views', [
				'data_type'		=> 'post_type',
				'post_type'		=> [self::class, 'match_callback'],
				'callback'		=> [self::class, 'add_views'],
				'title'			=> '增加浏览数',
				'page_title'	=> '增加浏览数',
				'submit_text'	=> '增加',
				'row_action'	=> false,
				'bulk'			=> true,
				'fields'		=> [
					'view'	=>['title'=>'使用说明',	'type'=>'view',	'value'=>'批量处理是在原有的浏览量上增加'],
					'addon'	=>['title'=>'浏览数增量',	'type'=>'number']
				]
			]);

			wpjam_register_list_table_column('views', [
				'title'		=> '浏览',
				'data_type'	=> 'post_type',
				'post_type'	=> [self::class, 'match_callback'],
				'sortable'	=> 'views',
				'style'		=> 'width:7%;',
				'callback'	=> fn($id) => ['row_action'=>'update_views', 'args'=>['title'=>wpjam_get_post_views($id) ?: 0, 'fallback'=>true]],
			]);
		}

		$grant	= $_SERVER['REQUEST_METHOD'] == 'POST';

		wpjam_register_json('post.views',	[
			'callback'	=> fn($data)=> self::api_callback($data, $grant),
			'fields'	=> fn()=> self::api_fields($grant),
			'grant'		=> $grant
		]);
	}

	public static function add_hooks(){
		add_filter('the_content', fn($content)=> $content.(is_feed() ? "\n".'<p><img src="'.home_url('postviews/'.get_the_ID().'.png').'" /></p>'."\n" : ''), 999);

		// 不指定 post_type ，默认查询 post，这样custom post type 的文章页面就会显示 404
		add_action('pre_get_posts',	fn($query)=> $query->get('module') == 'postviews' ? $query->set('post_type', 'any') : null);

		$begin	= (int)wpjam_basic_get_setting('views_begin');
		$end	= (int)wpjam_basic_get_setting('views_end');

		if($begin && $end){
			$views	= rand(min($begin, $end), max($begin, $end));
		
			add_action('wp_after_insert_post', fn($post_id, $post, $update)=> (!$update && is_post_type_viewable($post->post_type)) ? update_post_meta($post_id, 'views', $views) : null, 999, 3);
		}	
	}
}

wpjam_add_option_section('wpjam-basic',	['model'=>'WPJAM_Postviews']);
wpjam_register_route('postviews', 		['model'=>'WPJAM_Postviews']);

function wpjam_get_post_total_views($post_id){
	return wpjam_get_post_views($post_id);
}

if(!function_exists('the_views')){
	function the_views(){
		echo '<span class="view">浏览：'.(wpjam_get_post_views(get_the_ID()) ?: 0).'</span>';
	}

	add_action('wp_head', fn()=> is_single() ? wpjam_update_post_views(get_queried_object_id()) : null);
}
