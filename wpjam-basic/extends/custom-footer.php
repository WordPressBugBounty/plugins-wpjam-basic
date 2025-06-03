<?php
add_action('wp_loaded', function(){
	if(wpjam_get_setting('wpjam-extends', 'custom-footer')){
		WPJAM_Custom::update_setting('custom_post', 1);
		WPJAM_Custom::update_setting('list_table', wpjam_basic_get_setting('custom-post'));

		wpjam_delete_setting('wpjam-extends', 'custom-footer');
		wpjam_basic_delete_setting('custom-post');
	}
});