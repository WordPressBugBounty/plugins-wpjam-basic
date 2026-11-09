<?php
/*
Name: 摘要快速编辑
URI: https://mp.weixin.qq.com/s/0W73N71wNJv10kMEjbQMGw
Description: 后台文章列表的快速编辑支持编辑摘要。
Version: 1.0
Admin: true
*/
wpjam_add_admin_load([
	'base'		=> 'edit', 
	'callback'	=> function($screen){
		if(post_type_supports($screen->post_type, 'excerpt')){
			if(!wp_doing_ajax()){
				wpjam_script(<<<'JS'
				$('body').on('quick_edit', '#the-list', function(event, id){
					let $edit	= $('#edit-'+id);

					if($('textarea[name="the_excerpt"]', $edit).length == 0){
						$('.inline-edit-date', $edit).before('<label><span class="title">摘要</span><span class="input-text-wrap"><textarea cols="22" rows="2" name="the_excerpt"></textarea></span></label>');
						$('textarea[name="the_excerpt"]', $edit).val($('#inline_'+id+' div.post_excerpt').text());
					}
				});
				JS);
			}

			if(($_POST['action'] ?? '') === 'inline-save' && isset($_POST['the_excerpt'], $_POST['post_ID'])){
				wpjam_hook('wp_insert_post_data', '+', fn($postarr)=> ($postarr['ID'] ?? 0) == $_POST['post_ID'] ? ['post_excerpt'=>sanitize_post_field('post_excerpt', $_POST['the_excerpt'], $postarr['ID'], 'db')] : [], 10, 2);
			}

			wpjam_hook('add_inline_data', 'echo', fn($post)=> '<div class="post_excerpt">'.esc_textarea(trim($post->post_excerpt)).'</div>');
		}
	}
]);
