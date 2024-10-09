<?php
/*
Plugin Name: WPJAM BASIC
Plugin URI: https://blog.wpjam.com/project/wpjam-basic/
Description: WPJAM 常用的函数和接口，屏蔽所有 WordPress 不常用的功能。
Version: 6.6.2
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 7.4
Author: Denis
Author URI: http://blog.wpjam.com/
*/
define('WPJAM_BASIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPJAM_BASIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPJAM_BASIC_PLUGIN_FILE', __FILE__);

include __DIR__.'/includes/class-wpjam-args.php';
include __DIR__.'/includes/class-wpjam-model.php';
include __DIR__.'/includes/class-wpjam-field.php';
include __DIR__.'/includes/class-wpjam-setting.php';
include __DIR__.'/includes/class-wpjam-api.php';
include __DIR__.'/includes/class-wpjam-post.php';
include __DIR__.'/includes/class-wpjam-term.php';
include __DIR__.'/includes/class-wpjam-user.php';

if(is_admin()){
	include __DIR__.'/includes/class-wpjam-admin.php';
	include __DIR__.'/includes/class-wpjam-list-table.php';
}

include __DIR__.'/public/wpjam-compat.php';
include __DIR__.'/public/wpjam-functions.php';
include __DIR__.'/public/wpjam-utils.php';
include __DIR__.'/public/wpjam-route.php';

do_action('wpjam_loaded');