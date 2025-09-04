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
		if(post_type_supports($screen->post_type, 'excerpt')){
			if(!wp_doing_ajax()){
				wpjam_admin('script', <<<'JS'
				$('body').on('quick_edit', '#the-list', function(event, id){
					let $edit	= $('#edit-'+id);

					if($('textarea[name="the_excerpt"]', $edit).length == 0){
						$('.inline-edit-date', $edit).before('<label><span class="title">摘要</span><span class="input-text-wrap"><textarea cols="22" rows="2" name="the_excerpt"></textarea></span></label>');
						$('textarea[name="the_excerpt"]', $edit).val($('#inline_'+id+' div.post_excerpt').text());
					}
				});
				JS);
			}

			add_filter('wp_insert_post_data', fn($data)=> isset($_POST['the_excerpt']) ? wpjam_set($data, 'post_excerpt', $_POST['the_excerpt']) : $data);

			add_action('add_inline_data', fn($post)=> wpjam_echo('<div class="post_excerpt">'.esc_textarea(trim($post->post_excerpt)).'</div>'));
		}
	}
]);