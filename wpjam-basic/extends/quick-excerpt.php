<?php
/*
Name: 摘要快速编辑
URI: https://mp.weixin.qq.com/s/0W73N71wNJv10kMEjbQMGw
Description: 后台文章列表的快速编辑支持编辑摘要。
Version: 1.0
*/
if(!is_admin()){
	return;
}

wpjam_add_admin_load([
	'base'		=> 'edit', 
	'callback'	=> function($screen){
		if(!post_type_supports($screen->post_type, 'excerpt')){
			return;
		}

		if(!wp_doing_ajax()){
			wpjam_admin('script', <<<'EOD'
			$('body').on('quick_edit', '#the-list', function(event, id){
				let edit_row	= $('#edit-'+id);

				if($('textarea[name="the_excerpt"]', edit_row).length == 0){
					$('.inline-edit-date', edit_row).before('<label><span class="title">摘要</span><span class="input-text-wrap"><textarea cols="22" rows="2" name="the_excerpt"></textarea></span></label>');
					$('textarea[name="the_excerpt"]', edit_row).val($('#inline_'+id+' div.post_excerpt').text());
				}
			});
			EOD);
		}
		
		add_filter('wp_insert_post_data', fn($data)=> array_merge($data, isset($_POST['the_excerpt']) ? ['post_excerpt'=>$_POST['the_excerpt']] : []));
		
		add_action('add_inline_data', fn($post)=> wpjam_echo('<div class="post_excerpt">'.esc_textarea(trim($post->post_excerpt)).'</div>'));
	}
]);