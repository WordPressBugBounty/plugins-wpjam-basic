<?php
if(!function_exists('get_screen_option')){
	function get_screen_option($option, $key=false){
		$screen	= get_current_screen();

		if($screen){
			if(in_array($option, ['post_type', 'taxonomy'])){
				return $screen->$option ?? null;
			}

			$value	= $screen->get_option($option);

			if($key){
				if(is_array($value)){
					return $value[$key] ?? null;
				}elseif(is_object($value)){
					return $value->$key ?? null;
				}
			}else{
				return $value;
			}
		}
	}
}

function wpjam_add_screen_item($option, ...$args){
	$screen	= get_current_screen();

	if($screen){
		$items	= $screen->get_option($option) ?: [];

		if(count($args) >= 2){
			$key	= $args[0];
			
			if(isset($items[$key])){
				return;	
			}

			$items[$key]	= $args[1];
		}else{
			$items[]		= $args[0];
		}

		$screen->add_option($option, $items);
	}
}

function wpjam_add_admin_menu($args=[]){
	if(!empty($args['menu_slug']) && !empty($args['menu_title'])){
		$object = wpjam_get_items_object('menu_page');
		$name	= array_pull($args, 'menu_slug');
		$parent	= array_pull($args, 'parent');
		$args	= $parent ? ['subs' => [$name => $args]] : wp_parse_args($args, ['subs'=>[]]);
		$key	= $parent ?: $name;
		$item	= $object->get_item($key);

		if($item){
			$subs	= array_merge($item['subs'], $args['subs']);
			$args	= array_merge($item, $args, ['subs'=>$subs]);
		}

		$object->set_item($key, $args);
	}
}

function wpjam_add_admin_load($args){
	if(wp_is_numeric_array($args)){
		array_walk($args, 'wpjam_add_admin_load');
	}else{
		$type	= array_get($args, 'type');

		if(!$type){
			if(isset($args['base'])){
				$type	= 'builtin_page';
			}elseif(isset($args['plugin_page'])){
				$type	= 'plugin_page';
			}
		}

		if($type && in_array($type, ['builtin_page', 'plugin_page'])){
			wpjam_add_item('admin_load', array_merge($args, ['type'=>$type]));
		}
	}
}

function wpjam_add_admin_ajax($name, $callback){
	wpjam_add_item('admin_ajax', $name, $callback);

	add_action('wp_ajax_'.$name, 'wpjam_admin_ajax');
}

function wpjam_add_admin_error($message='', $type='success'){
	if(is_wp_error($message)){
		$message	= $message->get_error_message();
		$type		= 'error';
	}

	if($message && $type){
		wpjam_add_screen_item('admin_errors', ['message'=>$message, 'type'=>$type]);
	}
}

function wpjam_admin_menu(){
	if(is_network_admin()){
		$builtins	= [
			'settings'	=> 'settings.php',
			'theme'		=> 'themes.php',
			'themes'	=> 'themes.php',
			'plugins'	=> 'plugins.php',
			'users'		=> 'users.php',
			'sites'		=> 'sites.php',
		];
	}elseif(is_user_admin()){
		$builtins	= [
			'dashboard'	=> 'index.php',
			'users'		=> 'profile.php',
		];
	}else{
		$builtins	= [
			'dashboard'	=> 'index.php',
			'management'=> 'tools.php',
			'options'	=> 'options-general.php',
			'theme'		=> 'themes.php',
			'themes'	=> 'themes.php',
			'plugins'	=> 'plugins.php',
			'posts'		=> 'edit.php',
			'media'		=> 'upload.php',
			'links'		=> 'link-manager.php',
			'pages'		=> 'edit.php?post_type=page',
			'comments'	=> 'edit-comments.php',
			'users'		=> current_user_can('edit_users') ? 'users.php' : 'profile.php',
		];

		foreach(get_post_types(['_builtin'=>false, 'show_in_menu'=>true]) as $ptype) {
			$builtins[$ptype.'s'] = 'edit.php?post_type='.$ptype;
		}
	}

	$rendering	= doing_action(wpjam_get_admin_prefix().'admin_menu');

	if($rendering){
		do_action('wpjam_admin_init');
	}

	$menus	= apply_filters('wpjam_'.wpjam_get_admin_prefix().'pages', wpjam_get_items('menu_page'));

	foreach($menus as $slug => $args){
		$menu	= wpjam_args($args);
		$parent	= $builtins[$slug] ?? '';

		if(!$parent){
			$parent	= $slug	= $menu->menu_slug ??= $slug;

			if($rendering || $GLOBALS['plugin_page'] == $slug){
				$menu	= wpjam_parse_admin_menu($menu, '', $rendering);

				if(!$rendering){
					if(!$menu || !$menu->subs){
						break;
					}
				}
			}
		}

		$subs	= $menu ? $menu->subs : [];

		if($subs){
			uasort($subs, fn($s1, $s2) => array_get($s1, 'position', 10) <=> array_get($s2, 'position', 10) ?: array_get($s2, 'order', 10) <=> array_get($s1, 'order', 10));

			if($parent == $slug){
				$sub	= $subs[$slug] ?? null;

				if(!$sub){
					$sub	= array_except($args, ['position', 'subs', 'page_title']);

					if(!empty($sub['sub_title'])){
						$sub['menu_title']	= $sub['sub_title']; 
					}
				}

				$subs	= array_merge([$slug=>$sub], $subs);
			}

			foreach($subs as $_slug => $sub){
				$sub['menu_slug'] ??= $_slug;

				if($rendering || $GLOBALS['plugin_page'] == $sub['menu_slug']){
					wpjam_parse_admin_menu(wpjam_args($sub), $parent, $rendering);

					if(!$rendering){
						break 2;
					}
				}
			}
		}
	}
}

function wpjam_parse_admin_menu($menu, $parent, $rendering){
	if(is_numeric($menu->menu_slug) || !$menu->menu_title){
		return;
	}

	$admin_page	= ($parent && strpos($parent, '.php')) ? $parent : 'admin.php';
	$network	= $menu->pull('network', ($admin_page == 'admin.php'));
	$slug		= $menu->menu_slug;

	if(($network === 'only' && !is_network_admin()) || (!$network && is_network_admin())){
		return;
	}

	$menu->page_title	??= $menu->menu_title;
	$menu->capability	??= 'manage_options';

	if($menu->map_meta_cap && is_callable($menu->map_meta_cap)){
		wpjam_register_capability($menu->capability, $menu->map_meta_cap);
	}

	if(!str_contains($slug, '.php')){
		$menu->admin_url	= $admin_url = add_query_arg(['page'=>$slug], $admin_page);

		if($menu->query_args){
			$query_data		= wpjam_get_data_parameter($menu->query_args);
			$null_queries	= array_filter($query_data, 'is_null');

			if($null_queries){
				if($GLOBALS['plugin_page'] == $slug){
					wp_die('「'.implode('」,「', array_keys($null_queries)).'」参数无法获取');
				}

				return;
			}

			$menu->query_data	= $query_data;
			$menu->admin_url	= $queried_url	= add_query_arg($query_data, $admin_url);

			wpjam_add_item('queried_menu', ['search'=>"href='".esc_url($admin_url)."'",	'replace'=>"href='".$queried_url."'"]);
		}
	}

	$object	= null;

	if($GLOBALS['plugin_page'] == $slug && ($parent || (!$parent && !$menu->subs))){
		$GLOBALS['current_admin_url']	= wpjam_admin_url($menu->admin_url);

		$object	= wpjam_add_plugin_page($menu->get_args());
	}

	if($rendering){
		if(str_contains($slug, '.php')){
			if($GLOBALS['pagenow'] == explode('?', $slug)[0]){
				wpjam_add_item('parent_files', $slug, ($parent ?: $slug));
			}

			$callback	= null;
		}else{
			$callback	= $object ? [$object, 'render'] : '__return_true';
		}

		$args	= [$menu->page_title, $menu->menu_title, $menu->capability, $slug, $callback];

		if($parent){
			$hook	= add_submenu_page(...[$parent, ...$args, $menu->position]);
		}else{
			$icon	= str_starts_with($menu->icon, 'ri-') ? 'dashicons-'.$menu->icon : (string)$menu->icon;
			$hook	= add_menu_page(...[...$args, $icon, $menu->position]);
		}

		if($object){
			$object->page_hook	= $hook;
		}
	}

	return $menu;
}

function wpjam_admin_init($plugin_page=null){
	if(!$plugin_page){
		$plugin_page	= $_POST['plugin_page'] ?? null;
	}

	$GLOBALS['plugin_page']	= $plugin_page;

	do_action('wpjam_admin_init');

	if($plugin_page){
		wpjam_admin_menu();
	}

	$screen_id	= wpjam_get_current_screen_id();

	if($screen_id == 'upload'){
		$GLOBALS['hook_suffix']	= $screen_id;

		$screen_id	= '';
	}

	set_current_screen($screen_id);
}

function wpjam_admin_action_update(){
	// 为了实现多个页面使用通过 option 存储。这个可以放弃了，使用 AJAX + Redirect
	// 注册设置选项，选用的是：'admin_action_' . $_REQUEST['action'] hook，
	// 因为在这之前的 admin_init 检测 $plugin_page 的合法性

	$referer_origin	= parse_url(wpjam_get_referer());

	if(!empty($referer_origin['query'])){
		$referer_args	= wp_parse_args($referer_origin['query']);

		if(!empty($referer_args['page'])){
			wpjam_admin_init($referer_args['page']);	// 实现多个页面使用同个 option 存储。
		}
	}
}

function wpjam_admin_load(...$args){
	$loads	= [];
	$object	= WPJAM_Plugin_Page::get_current();
	$type 	= $object ? 'plugin_page' : 'builtin_page';

	foreach(wpjam_get_items('admin_load') as $load){
		if($load['type'] != $type){
			continue;
		}

		if($type == 'plugin_page'){
			$by_tab	= true;

			if(!empty($load['plugin_page'])){
				if(is_callable($load['plugin_page'])){
					if(!call_user_func($load['plugin_page'], $args[0], $args[1])){
						continue;
					}else{
						$by_tab	= false;
					}
				}else{
					if(!wpjam_compare($args[0], (array)$load['plugin_page'])){
						continue;
					}
				}
			}

			if($by_tab){
				if(!empty($load['current_tab'])){
					if(!$args[1] || !wpjam_compare($args[1], (array)$load['current_tab'])){
						continue;
					}
				}else{
					if($args[1]){
						continue;
					}
				}
			}
		}elseif($type == 'builtin_page'){
			if(!empty($load['screen']) && is_callable($load['screen'])){
				if(!call_user_func($load['screen'], $args[0])){
					continue;
				}
			}

			if(array_some(['base', 'post_type', 'taxonomy'], fn($k) => !empty($load[$k]) && !wpjam_compare($args[0]->$k, $load[$k]))){
				continue;
			}
		}

		$load['order']	??= 10;
		$loads[]		= $load;
	}

	usort($loads, fn($load1, $load2) => ($load2['order'] ?? 10) <=> ($load1['order'] ?? 10));

	foreach($loads as $load){
		if(!empty($load['page_file'])){
			array_map(fn($file) => is_file($file) ? include $file : null, (array)$load['page_file']);
		}

		if(!empty($load['callback'])){
			if(is_callable($load['callback'])){
				call_user_func_array($load['callback'], $args);
			}
		}elseif(!empty($load['model'])){
			array_map(fn($method) => method_exists($load['model'], $method) ? call_user_func_array([$load['model'], $method], $args) : null, ['load', $load['type'].'_load']);
		}
	}
}

function wpjam_admin_data_type($args, $screen=null){
	$data_type	= is_string($args) ? $args : $args['data_type'];

	if($data_type){
		$screen	??= get_current_screen();
		$screen->add_option('data_type', $data_type);

		$object		= wpjam_get_data_type_object($data_type);
		$meta_type	= $object ? $object->get_meta_type($args) : '';

		if($meta_type){
			$screen->add_option('meta_type', $meta_type);
		}

		if(in_array($data_type, ['post_type', 'taxonomy']) && !$screen->$data_type && !is_string($args) && !empty($args[$data_type])){
			$screen->$data_type	= $args[$data_type];
		}
	}
}

function wpjam_admin_ajax(){
	$callback	= wpjam_get_item('admin_ajax', $_REQUEST['action']);

	if(!$callback || !is_callable($callback)){
		wp_die('0', 400);
	}

	add_filter('wp_die_ajax_handler', ['WPJAM_Error', 'filter_wp_die_handler']);

	wpjam_send_json(wpjam_catch($callback));
}

function wpjam_admin_errors(){
	echo array_reduce((get_screen_option('admin_errors') ?: []), fn($tag, $e) => $tag->after('p', [], $e['message'])->wrap('div', ['is-dismissible', 'notice', 'notice-'.$e['type']]), wpjam_tag());
}

function wpjam_admin_notices(){
	$object	= WPJAM_Notice::get_instance();
	$object->render();
}

function wpjam_admin_enqueue_scripts(){
	$screen	= get_current_screen();

	if($screen->base == 'customize'){
		return;
	}elseif($screen->base == 'post'){
		wp_enqueue_media(['post'=>wpjam_get_admin_post_id()]);
	}else{
		wp_enqueue_media();
	}

	$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
	$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';

	wpjam_register_remixincon_style();

	wp_enqueue_style('wpjam-style',		$static.'/style.css', ['thickbox', 'remixicon', 'wp-color-picker', 'editor-buttons'], $ver);
	wp_enqueue_script('wpjam-script',	$static.'/script.js', ['jquery', 'thickbox', 'wp-backbone', 'jquery-ui-sortable', 'jquery-ui-tooltip', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-ui-autocomplete', 'wp-color-picker'], $ver);
	wp_enqueue_script('wpjam-form',		$static.'/form.js',		['wpjam-script', 'mce-view'], $ver);

	$setting	= [
		'screen_base'	=> $screen->base,
		'screen_id'		=> $screen->id,
		'post_type'		=> $screen->post_type,
		'taxonomy'		=> $screen->taxonomy,
		'admin_url'		=> $GLOBALS['current_admin_url'] ?? '',
	];

	$params	= array_except($_REQUEST, wp_removable_query_args());
	$params	= array_except($params, ['page', 'tab', '_wp_http_referer', '_wpnonce']);

	if($GLOBALS['plugin_page']){
		$setting['plugin_page']	= $GLOBALS['plugin_page'];
		$setting['current_tab']	= $GLOBALS['current_tab'] ?? null;

		$query_data		= wpjam_get_plugin_page_setting('query_data') ?: [];
		$_query_data	= wpjam_get_current_tab_setting('query_data') ?: [];
		$query_data		= array_merge($query_data, $_query_data);

		if($query_data){
			$params		= array_except($params, array_keys($query_data));

			$setting['query_data']	= array_map(fn($item) => is_null($item) ? null : sanitize_textarea_field($item), $query_data);
		}
	}else{
		$params	= array_except($params, array_filter(['taxonomy', 'post_type'], fn($k) => $screen->$k));
	}

	if($params){
		if(isset($params['data'])){
			$params['data']	= urldecode($params['data']);
		}

		$params	= map_deep($params, 'sanitize_textarea_field');
	}

	$setting['params']	= $params ?: new stdClass();

	$list_table	= $screen->get_option('list_table');

	if($list_table){
		$setting['list_table']	= $list_table->get_setting()+['ajax'=>(get_screen_option('list_table_ajax') ?? true)];
	}

	wp_localize_script('wpjam-script', 'wpjam_page_setting', $setting);
}

function wpjam_admin_url($path='', $scheme='admin'){
	$prefix	= wpjam_get_admin_prefix();

	return call_user_func($prefix.'admin_url', $path, $scheme);
}

function wpjam_ajax_upload(){
	$name	= wpjam_get_post_parameter('file_name');

	if(!check_ajax_referer('upload-'.$name, false, false)){
		wp_die('invalid_nonce');
	}

	return wpjam_upload($name, $relative=true);
}

function wpjam_ajax_page_action(){
	$action	= wpjam_get_post_parameter('page_action');
	$type	= wpjam_get_post_parameter('action_type');
	$object	= WPJAM_Page_Action::get($action);

	if($object){
		return $object->callback($type);
	}

	do_action_deprecated('wpjam_page_action', [$action, $type], 'WPJAM Basic 4.6');

	$callback	= wpjam_get_filter_name($GLOBALS['plugin_page'], 'ajax_response');

	if(is_callable($callback)){
		$result	= call_user_func($callback, $action);
		$result	= (is_wp_error($result) || is_array($result)) ? $result : [];

		wpjam_send_json($result);
	}else{
		wp_dir('invalid_callback');
	}
}

function wpjam_ajax_query(){
	$items	= [];
	$name	= wpjam_get_post_parameter('data_type');
	$object	= wpjam_get_data_type_object($name);

	if($object){
		$args	= wpjam_get_post_parameter('query_args', ['default'=>[]]);
		$items	= $object->query_items($args) ?: [];

		if(is_wp_error($items)){
			return [['label'=>$items->get_error_message(), 'value'=>$items->get_error_code()]];
		}
	}

	return ['items'=>$items];
}

function wpjam_get_current_screen_id(){
	if(did_action('current_screen')){
		return get_current_screen()->id;
	}

	if(isset($_POST['screen_id'])){
		$screen_id	= $_POST['screen_id'];
	}elseif(isset($_POST['screen'])){
		$screen_id	= $_POST['screen'];
	}else{
		$ajax_action	= $_REQUEST['action'] ?? '';

		if($ajax_action == 'fetch-list'){
			$screen_id	= $_GET['list_args']['screen']['id'];
		}elseif($ajax_action == 'inline-save-tax'){
			$screen_id	= 'edit-'.sanitize_key($_POST['taxonomy']);
		}elseif(in_array($ajax_action, ['get-comments', 'replyto-comment'])){
			$screen_id	= 'edit-comments';
		}else{
			$screen_id	= false;
		}
	}

	if($screen_id){
		if('-network' === substr($screen_id, -8)){
			if(!defined('WP_NETWORK_ADMIN')){
				define('WP_NETWORK_ADMIN', true);
			}
		}elseif('-user' === substr($screen_id, -5)){
			if(!defined('WP_USER_ADMIN')){
				define('WP_USER_ADMIN', true);
			}
		}
	}

	return $screen_id;
}

function wpjam_get_admin_prefix(){
	if(is_network_admin()){
		return 'network_';
	}elseif(is_user_admin()){
		return 'user_';
	}else{
		return '';
	}
}

function wpjam_get_page_summary($type='page'){
	return get_screen_option($type.'_summary');
}

function wpjam_set_page_summary($summary, $type='page', $append=true){
	add_screen_option($type.'_summary', ($append ? get_screen_option($type.'_summary') : '').$summary);
}

function wpjam_admin_tooltip($text, $tooltip){
	return '<div class="wpjam-tooltip">'.$text.'<div class="wpjam-tooltip-text">'.wpautop($tooltip).'</div></div>';
}

function wpjam_get_referer(){
	$referer	= wp_get_original_referer() ?: wp_get_referer();
	$removable	= [...wp_removable_query_args(), '_wp_http_referer', 'action', 'action2', '_wpnonce'];

	return remove_query_arg($removable, $referer);
}

function wpjam_get_admin_post_id(){
	if(isset($_GET['post'])){
		return (int)$_GET['post'];
	}elseif(isset($_POST['post_ID'])){
		return (int)$_POST['post_ID'];
	}else{
		return 0;
	}
}

function wpjam_add_plugin_page($args, $name=null){
	$name	= $name ?: array_pull($args, 'menu_slug');

	if($name){
		return WPJAM_Plugin_Page::register($name, $args);
	}
}

function wpjam_add_tab_page($args, $name=null){
	$name	= $name ?: array_pull($args, 'tab_slug');

	if($name && !empty($args['title'])){
		$tab	= new WPJAM_Tab_Page($name, $args);
		$page	= $args['plugin_page'] ?? '';
		$name	= wpjam_join(':', [$page, $name]);

		return WPJAM_Tab_Page::register($name, $tab);
	}
}

function wpjam_register_plugin_page_tab($name, $args){
	return WPJAM_Tab_Page::register($name, $args);
}

function wpjam_register_page_action($name, $args){
	return WPJAM_Page_Action::register($name, $args);
}

function wpjam_get_page_button($name, $args=[]){
	$object	= WPJAM_Page_Action::get($name);

	return $object ? $object->get_button($args) : '';
}

function wpjam_get_builtin_list_table($class_name, $screen=null){
	global $wp_list_table;

	if(!$wp_list_table){
		$screen			= $screen ?: get_current_screen();
		$wp_list_table	= _get_list_table($class_name, ['screen'=>$screen]);
	}

	return $wp_list_table;
}

function wpjam_register_list_table($name, $args=[]){
	return wpjam_add_item('list_table', $name, $args);
}

function wpjam_register_list_table_action($name, $args){
	return WPJAM_List_Table::register($name, $args, 'action');
}

function wpjam_unregister_list_table_action($name, $args=[]){
	WPJAM_List_Table::unregister($name, $args, 'action');
}

function wpjam_register_list_table_column($name, $field){
	return WPJAM_List_Table::register($name, $field, 'column');
}

function wpjam_unregister_list_table_column($name, $field=[]){
	WPJAM_List_Table::unregister($name, $field, 'column');
}

function wpjam_register_list_table_view($name, $view=[]){
	return WPJAM_List_Table::register($name, $view, 'view');
}

function wpjam_register_dashboard($name, $args){
	return wpjam_add_item('dashboard', $name, $args);
}

function wpjam_register_dashboard_widget($name, $args){
	return wpjam_add_item('dashboard_widget', $name, $args);
}

function wpjam_dashboard_widget($dashboard, $widgets=[]){
	$widgets	= array_map(fn($widget) => array_merge($widget, ['dashboard'=>$dashboard]), $widgets);
	$widgets	= array_merge($widgets, wpjam_get_items('dashboard_widget'));
	$widgets	= $dashboard == 'dashboard' ? apply_filters('wpjam_dashboard_widgets', $widgets) : $widgets;

	foreach($widgets as $widget_id => $widget){
		$widget['dashboard']	??= 'dashboard';

		if($widget['dashboard'] == $dashboard){
			$widget_id	= $widget['id'] ?? $widget_id;
			$title		= $widget['title'];
			$callback	= $widget['callback'] ?? wpjam_get_filter_name($widget_id, 'dashboard_widget_callback');
			$context	= $widget['context'] ?? 'normal';	// 位置，normal 左侧, side 右侧
			$priority	= $widget['priority'] ?? 'core';
			$args		= $widget['args'] ?? [];

			// 传递 screen_id 才能在中文的父菜单下，保证一致性。
			add_meta_box($widget_id, $title, $callback, get_current_screen(), $context, $priority, $args);
		}
	}	
}

function wpjam_get_plugin_page_setting($key='', $using_tab=false){
	$object	= WPJAM_Plugin_Page::get_current();

	if($object){
		$is_tab	= $object->function == 'tab';

		if(str_ends_with($key, '_name')){
			$using_tab	= $is_tab;
			$default	= $GLOBALS['plugin_page'];
		}else{
			$using_tab	= $using_tab ? $is_tab : false;
			$default	= null;
		}

		if($using_tab){
			$object	= $object->tab_object;
		}
	}

	if(!$object){
		return null;
	}

	return $key ? ($object->$key ?: $default) : $object->to_array();
}

function wpjam_get_current_tab_setting($key=''){
	return wpjam_get_plugin_page_setting($key, true);
}

function wpjam_chart($type, $data, $args){
}

function wpjam_line_chart($data, $labels, $args=[], $type='Line'){
	$args	= array_merge($args, ['labels'=>$labels, 'data'=>$data]);
	$object	= new WPJAM_Chart();
	$object->line($args, $type);
}

function wpjam_bar_chart($data, $labels, $args=[]){
	wpjam_line_chart($data, $labels, $args, 'Bar');
}

function wpjam_donut_chart($data, $args=[]){
	$args	= array_merge($args, ['data'=>$data]);
	$object	= new WPJAM_Chart();
	$object->donut($args);
}

function wpjam_get_chart_parameter($key){
	return (WPJAM_Chart_Form::get_instance())->get_parameter($key);
}

add_action('plugins_loaded', function(){
	wpjam_register_page_action('delete_notice', [
		'button_text'	=> '删除',
		'tag'			=> 'span',
		'class'			=> 'hidden delete-notice',
		'callback'		=> ['WPJAM_Notice', 'ajax_delete'],
		'direct'		=> true,
	]);

	if($GLOBALS['pagenow'] == 'options.php'){
		add_action('admin_action_update',	'wpjam_admin_action_update', 9);
	}elseif(wp_doing_ajax()){
		if(wpjam_get_current_screen_id()){
			add_action('admin_init',	'wpjam_admin_init', 9);

			wpjam_add_admin_ajax('wpjam-page-action',	'wpjam_ajax_page_action');
			wpjam_add_admin_ajax('wpjam-upload',		'wpjam_ajax_upload');
			wpjam_add_admin_ajax('wpjam-query',			'wpjam_ajax_query');
		}
	}else{
		$menu_action	= wpjam_get_admin_prefix().'admin_menu';
		$notices_action	= wpjam_get_admin_prefix().'admin_notices';

		add_action($menu_action,	'wpjam_admin_menu', 9);
		add_action($notices_action,	'wpjam_admin_errors');
		add_action($notices_action,	'wpjam_admin_notices');

		add_filter('wpjam_html',	fn($html) => str_replace('dashicons-before dashicons-ri-', 'wp-menu-ri ri-', $html));

		add_action('admin_enqueue_scripts', 'wpjam_admin_enqueue_scripts', 9);
	}

	add_action('current_screen',	['WPJAM_Admin', 'on_current_screen'], 9);
	add_filter('admin_url',			['WPJAM_Admin', 'filter_admin_url'], 9, 4);
});
