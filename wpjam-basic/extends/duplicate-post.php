<?php
/*
Name: 文章快速复制
URI: https://mp.weixin.qq.com/s/0W73N71wNJv10kMEjbQMGw
Description: 在后台文章列表添加一个快速复制按钮，复制一篇草稿用于快速新建。
Version: 1.0
*/
is_admin() && wpjam_register_list_table_action('quick_duplicate', [
	'title'		=> '快速复制',
	'response'	=> 'duplicate',
	'direct'	=> true,
	'data_type'	=> 'post_type',
	'callback'	=> 'wpjam_duplicate_post',
	'post_type'	=> fn($v) => $v != 'attachment' && wpjam_get_post_type_setting($v, 'duplicatable') !== false,
]);

function wpjam_duplicate_post($post_id){
	$data	= wpjam_except(get_post($post_id, ARRAY_A), ['ID', 'post_date_gmt', 'post_modified_gmt', 'post_name']);
	$added	= wpjam_try('WPJAM_Post::insert', array_merge($data, [
		'post_status'	=> 'draft',
		'post_author'	=> get_current_user_id(),
		'post_date'		=> wpjam_date('Y-m-d H:i:s'),
		'post_modified'	=> wpjam_date('Y-m-d H:i:s'),
		'tax_input'		=> wpjam_fill(get_object_taxonomies($data['post_type']), fn($tax)=> wp_get_object_terms($post_id, $tax, ['fields'=>'ids']))
	]));

	foreach(get_post_custom_keys($post_id) ?: [] as $key){
		if($key == '_thumbnail_id' || ($key != 'views' && !is_protected_meta($key, 'post'))){
			foreach(get_post_meta($post_id, $key) as $value){
				add_post_meta($added, $key, $value, false);
			}
		}
	}

	return $added;
}