<?php
/*
Name: 文章快速复制
URI: https://mp.weixin.qq.com/s/0W73N71wNJv10kMEjbQMGw
Description: 在后台文章列表添加一个快速复制按钮，复制一篇草稿用于快速新建。
Version: 1.0
*/
if(is_admin()){
	wpjam_register_list_table_action('quick_duplicate', [
		'title'		=> '快速复制',
		'response'	=> 'duplicate',
		'direct'	=> true,
		'data_type'	=> 'post_type',
		'callback'	=> 'wpjam_duplicate_post',
		'post_type'	=> fn($ptype) => apply_filters('wpjam_post_type_duplicatable', $ptype != 'attachment', $ptype),
	]);
}

function wpjam_duplicate_post($post_id){
	$post_arr	= get_post($post_id, ARRAY_A);
	$post_arr	= wpjam_except($post_arr, ['ID', 'post_date_gmt', 'post_modified_gmt', 'post_name']);

	$post_arr['post_status']	= 'draft';
	$post_arr['post_author']	= get_current_user_id();
	$post_arr['post_date']		= $post_arr['post_modified']	= wpjam_date('Y-m-d H:i:s');

	$post_arr['tax_input']		= [];

	foreach(get_object_taxonomies($post_arr['post_type']) as $taxonomy){
		$post_arr['tax_input'][$taxonomy]	= wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
	}

	$new_post_id	= WPJAM_Post::insert($post_arr);

	if(!is_wp_error($new_post_id)){
		$meta_keys	= get_post_custom_keys($post_id) ?: [];

		foreach($meta_keys as $meta_key){
			if($meta_key == '_thumbnail_id' || ($meta_key != 'views' && !is_protected_meta($meta_key, 'post'))){
				foreach(get_post_meta($post_id, $meta_key) as $meta_value){
					add_post_meta($new_post_id, $meta_key, $meta_value, false);
				}
			}
		}
	}

	return $new_post_id;
}