<?php
/*
Name: Rewrite 优化
URI: https://blog.wpjam.com/m/wpjam-rewrite/
Description: Rewrites 扩展让可以优化现有 Rewrites 规则和添加额外的 Rewrite 规则。
Version: 2.0
*/
class WPJAM_Rewrite{
	public static function __callStatic($method, $args){
		if(in_array($method, ['get_setting', 'update_setting'])){
			if($method != 'get_setting'){
				flush_rewrite_rules();
			}

			return WPJAM_Basic::$method(...$args);
		}
	}

	public static function get_all(){
		return get_option('rewrite_rules') ?: [];
	}

	public static function validate_data($data, $id=''){
		if(empty($data['regex']) || empty($data['query'])){
			wp_die('Rewrite 规则不能为空');
		}

		if(is_numeric($data['regex'])){
			wp_die('无效的 Rewrite 规则');
		}

		$rewrites	= self::get_all();

		if(isset($rewrites[$data['regex']])){
			wp_die('该 Rewrite 规则已存在');
		}

		return $data;
	}

	public static function query_items($args){
		return array_values(wpjam_map(self::get_all(), function($query, $regex){
			static $no = 0;

			$no++;

			return compact('no', 'regex', 'query');
		}));
	}

	public static function get_actions(){
		$args	= ['value_callback'=>[self::class, 'get_setting'], 'callback'=>[self::class, 'update_setting']];

		return [
			'optimize'	=> $args+['title'=>'优化',	'response'=>'list',	'overall'=>true,	'class'=>'button-primary'],
			'custom'	=> $args+['title'=>'自定义',	'response'=>'list',	'overall'=>true],
		];
	}

	public static function get_fields($action_key='', $id=0){
		if($action_key == 'custom'){
			return [
				'rewrites'	=> ['type'=>'mu-fields',	'fields'=>[
					'regex'	=> ['title'=>'正则',	'type'=>'text',	'show_admin_column'=>true],
					'query'	=> ['title'=>'查询',	'type'=>'text',	'show_admin_column'=>true],
				]],
			];
		}elseif($action_key == 'optimize'){
			return [
				'remove_date_rewrite'		=> ['label'=>'移除日期 Rewrite 规则'],
				'remove_comment_rewrite'	=> ['label'=>'移除留言 Rewrite 规则'],
				'remove_feed=_rewrite'		=> ['label'=>'移除分类 Feed Rewrite 规则'],
			];
		}

		return [
			'no'	=> ['title'=>'No.',	'type'=>'text',	'show_admin_column'=>true],
			'regex'	=> ['title'=>'正则',	'type'=>'text',	'show_admin_column'=>true],
			'query'	=> ['title'=>'查询',	'type'=>'text',	'show_admin_column'=>true],
		];
	}

	public static function cleanup(&$rules){
		if(self::get_setting('remove_feed=_rewrite')){
			$remove[]	= 'feed=';
		}

		if(!get_option('wp_attachment_pages_enabled')){
			$remove[]	= 'attachment';
		}

		if(!get_option('page_comments')){
			$remove[]	= 'comment-page';
		}

		if(self::get_setting('disable_post_embed')){
			$remove[]	= '&embed=true';
		}

		if(self::get_setting('disable_trackbacks')){
			$remove[]	= '&tb=1';
		}

		if(!empty($remove)){
			foreach($rules as $key => $rule){
				if($rule == 'index.php?&feed=$matches[1]'){
					continue;
				}

				foreach($remove as $r){
					if(strpos($key, $r) !== false || strpos($rule, $r) !== false){
						unset($rules[$key]);
					}
				}
			}
		}
	}

	public static function add_hooks(){
		if(self::get_setting('remove_date_rewrite')){
			add_filter('date_rewrite_rules', fn()=> []);

			add_action('init', fn()=> array_map('remove_rewrite_tag', ['%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%']));
		}

		if(self::get_setting('remove_comment_rewrite')){
			add_filter('comments_rewrite_rules', fn()=> []);
		}

		add_action('generate_rewrite_rules', function($wp_rewrite){
			self::cleanup($wp_rewrite->rules); 
			self::cleanup($wp_rewrite->extra_rules_top);

			$rewrites = self::get_setting('rewrites');

			if($rewrites){
				$wp_rewrite->rules = array_merge(array_column($rewrites, 'query', 'regex'), $wp_rewrite->rules);
			}
		});
	}
}

wpjam_add_menu_page('wpjam-rewrites', [
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> 'Rewrites',
	'network'		=> false,
	'summary'		=> __FILE__,
	'capability'	=> is_multisite() ? 'manage_sites' : 'manage_options',
	'model'			=> 'WPJAM_Rewrite',
	'function'		=> 'list',
	'list_table'	=> ['title'=>'Rewrite 规则',	'primary_key'=>'no']
]);

