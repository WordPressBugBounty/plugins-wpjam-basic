<?php
/*
Name: Rewrite 优化
URI: https://blog.wpjam.com/m/wpjam-rewrite/
Description: Rewrites 扩展让可以优化现有 Rewrites 规则和添加额外的 Rewrite 规则。
Version: 2.0
*/
class WPJAM_Rewrite{
	public static function __callStatic($method, $args){
		if(str_ends_with($method, '_setting')){
			if($method == 'update_setting'){
				flush_rewrite_rules();

				$args	= array_slice($args, 0, 2);
			}

			return WPJAM_Basic::$method(...$args);
		}
	}

	public static function query_items($args){
		return wpjam_reduce(get_option('rewrite_rules') ?: [], fn($carry, $regex, $query)=> [...$carry, ['regex'=>wpautop($regex), 'query'=>wpautop($query)]], []);
	}

	public static function get_actions(){
		return [
			'optimize'	=> ['title'=>'优化',		'update_setting'=>true,	'class'=>'button-primary'],
			'custom'	=> ['title'=>'自定义',	'update_setting'=>true],
		];
	}

	public static function get_fields($action_key='', $id=0){
		if($action_key == 'custom'){
			return ['rewrites'=>['type'=>'mu-fields', 'fields'=>self::get_fields()]];
		}elseif($action_key == 'optimize'){
			return wpjam_array(['date'=>'日期', 'comment'=>'留言', 'feed='=>'分类 Feed'], fn($k, $v)=> ['remove_'.$k.'_rewrite', ['label'=>'移除'.$v.' Rewrite 规则']]);
		}

		return wpjam_map(['regex'=>'正则', 'query'=>'查询'], fn($v)=> ['title'=>$v, 'type'=>'text', 'show_admin_column'=>true, 'required']);
	}

	public static function cleanup(&$rules){
		$remove	= array_filter([
			self::get_setting('remove_feed=_rewrite') ? 'feed=' : '',
			get_option('wp_attachment_pages_enabled') ? '' : 'attachment',
			get_option('page_comments') ? '' : 'comment-page',
			self::get_setting('disable_post_embed') ? '&embed=true' : '',
			self::get_setting('disable_trackbacks') ? '&tb=1' : ''
		]);

		if($remove){
			foreach($rules as $key => $rule){
				if($rule == 'index.php?&feed=$matches[1]'){
					continue;
				}

				foreach($remove as $r){
					if(str_contains($key, $r) || str_contains($rule, $r)){
						unset($rules[$key]);
					}
				}
			}
		}
	}

	public static function add_hooks(){
		self::get_setting('remove_comment_rewrite') && add_filter('comments_rewrite_rules', fn()=> []);
		self::get_setting('remove_date_rewrite') && add_filter('date_rewrite_rules', fn()=> []) && add_action('init', fn()=> array_map('remove_rewrite_tag', ['%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%']));
		
		add_action('generate_rewrite_rules', fn($wp_rewrite)=> wpjam_map(['rules', 'extra_rules_top'], fn($k)=> self::cleanup($wp_rewrite->$k)));

		$custom	= wpjam_array(self::get_setting('rewrites') ?: [], fn($k, $v)=> $v['regex'] && !is_numeric($v['regex']) ? [$v['regex'], $v['query']] : null);
		$custom && add_filter('rewrite_rules_array', fn($rules)=> $custom+$rules);
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
	'list_table'	=> ['title'=>'Rewrite 规则',	'primary_key'=>'regex', 'numberable'=>true]
]);

