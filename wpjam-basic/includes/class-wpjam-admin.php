<?php
class WPJAM_Admin{
	public static function get_prefix(){
		return is_network_admin() ? 'network_' : (is_user_admin() ? 'user_' : '');
	}

	public static function enqueue_scripts($setting){
		$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
		$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';

		wp_enqueue_media($setting['screen_base'] == 'post' ? ['post'=>wpjam_get_admin_post_id()] : []);
		wp_enqueue_style('wpjam-style', $static.'/style.css', ['thickbox', 'remixicon', 'wp-color-picker', 'editor-buttons'], $ver);
		wp_enqueue_script('wpjam-script', $static.'/script.js', ['jquery', 'thickbox', 'wp-backbone', 'jquery-ui-sortable', 'jquery-ui-tooltip', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-slider', 'jquery-ui-autocomplete', 'wp-color-picker'], $ver);
		wp_enqueue_script('wpjam-form', $static.'/form.js', ['wpjam-script', 'mce-view'], $ver);

		wp_localize_script('wpjam-script', 'wpjam_page_setting', $setting+wpjam_map(wpjam_get_items('page_setting'), fn($v)=> is_closure($v) ? $v() : $v));
	}

	public static function on_admin_notices(){
		$render	= function($type){
			if($type == 'admin' && !current_user_can('manage_options')){
				return;
			}

			$object	= WPJAM_Notice::get_instance($type);

			foreach($object->get_items() as $key => $item){
				$item	+= ['class'=>'is-dismissible', 'title'=>'', 'modal'=>0];
				$notice	= trim($item['notice']);
				$notice	.= !empty($item['admin_url']) ? (($item['modal'] ? "\n\n" : ' ').'<a style="text-decoration:none;" href="'.add_query_arg(['notice_key'=>$key, 'notice_type'=>$type], home_url($item['admin_url'])).'">点击查看<span class="dashicons dashicons-arrow-right-alt"></span></a>') : '';

				$notice	= wpautop($notice).wpjam_get_page_button('delete_notice', ['data'=>['notice_key'=>$key, 'notice_type'=>$type]]);

				if($item['modal']){
					if(empty($modal)){	// 弹窗每次只显示一条
						$modal	= $notice;
						$title	= $item['title'] ?: '消息';

						echo '<div id="notice_modal" class="hidden" data-title="'.esc_attr($title).'">'.$modal.'</div>';
					}
				}else{
					echo '<div class="notice notice-'.$item['type'].' '.$item['class'].'">'.$notice.'</div>';
				}
			}
		};

		WPJAM_Notice::ajax_delete();

		$render('user');
		$render('admin');
	}

	public static function on_current_screen($screen){
		$fn		= self::get_prefix().'admin_url';
		$page	= $GLOBALS['plugin_page'] ?? '';
		$object = WPJAM_Plugin_Page::get_current();

		if($object){
			$object->load($screen);

			$url	= $fn($object->admin_url);
		}else{
			if(!empty($_POST['builtin_page'])){
				$url	= $fn($_POST['builtin_page']);
			}else{
				$url	= set_url_scheme('http://'.$_SERVER['HTTP_HOST'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
				$args	= $page ? ['page'=>$page] : wpjam_filter(wpjam_slice($_REQUEST, ['taxonomy', 'post_type']), fn($v, $k)=> $screen->$k);
				$url	= $args ? add_query_arg($args, $url) : $url;
			}
		}

		$GLOBALS['current_admin_url']	= $url;

		if(!$object){
			WPJAM_Builtin_Page::init($screen);
		}

		if(!wp_doing_ajax()){
			if($screen->base == 'customize'){
				return;
			}

			$setting	= [
				'screen_base'	=> $screen->base,
				'screen_id'		=> $screen->id,
				'post_type'		=> $screen->post_type,
				'taxonomy'		=> $screen->taxonomy,
				'admin_url'		=> $url
			];

			$params	= wpjam_except($_REQUEST, ['page', 'tab', '_wp_http_referer', '_wpnonce', ...wp_removable_query_args()]);

			if($page){
				$setting['plugin_page']	= $page;

				if($object && $object->query_data){
					$query_data	= wpjam_map($object->query_data, fn($v)=> is_null($v) ? $v : sanitize_textarea_field($v));
					$params		= array_diff_key($params, $query_data);

					$setting['query_data']	= $query_data;
				}
			}else{
				$setting['builtin_page']	= str_replace($fn(), '', $url);

				$params	= wpjam_except($params, array_filter(['taxonomy', 'post_type'], fn($k)=> $screen->$k));
			}

			if($params && isset($params['data']) && !is_array($params['data'])){
				$params['data']	= urldecode($params['data']);
			}

			$setting['params']	= $params ? map_deep($params, 'sanitize_textarea_field') : new stdClass();

			add_action('admin_enqueue_scripts',	fn()=> self::enqueue_scripts($setting), 9);

			add_filter('wpjam_html', fn($html)=> str_replace('dashicons-before dashicons-ri-', 'wp-menu-ri ri-', $html));
		}

		add_filter('admin_url', fn($url)=> ($pos = strpos($url, 'admin/page=')) ? substr_replace($url, 'admin.php?', $pos+6, 0) : $url);
	}

	public static function on_plugins_loaded(){
		if($GLOBALS['pagenow'] == 'admin-post.php'){
			return;
		}

		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-page-action', [
				'callback'	=> ['WPJAM_Page_Action', 'ajax_response'],
				'fields'	=> ['page_action'=>[], 'action_type'=>[]]
			]);

			wpjam_add_admin_ajax('wpjam-upload', [
				'callback'	=> ['WPJAM_Uploader', 'ajax_response'],
				'fields'	=> ['file_name'=> ['required'=>true]]
			]);

			wpjam_add_admin_ajax('wpjam-query', [
				'callback'	=> ['WPJAM_Data_Type', 'ajax_response'],
				'fields'	=> ['data_type'=> ['required'=>true]]
			]);

			add_action('admin_init', function(){
				$screen_id	= $_POST['screen_id'] ?? ($_POST['screen'] ?? null);

				if(is_null($screen_id)){
					$action	= $_REQUEST['action'] ?? '';

					if($action == 'fetch-list'){
						$screen_id	= $_GET['list_args']['screen']['id'];
					}elseif($action == 'inline-save-tax'){
						$screen_id	= 'edit-'.sanitize_key($_POST['taxonomy']);
					}else{
						$screen_id	= apply_filters('wpjam_ajax_screen_id', $screen_id, $action);
					}
				}

				if($screen_id){
					$const	= wpjam_find(['network'=>'WP_NETWORK_ADMIN', 'user'=>'WP_USER_ADMIN'], fn($v, $k)=> str_ends_with($screen_id, '-'.$k));

					if($const && !defined($const)){
						define($const, true);
					}

					if($screen_id == 'upload'){
						[$GLOBALS['hook_suffix'], $screen_id]	= [$screen_id, ''];
					}

					$GLOBALS['plugin_page']	= $_POST['plugin_page'] ?? null;

					WPJAM_Menu_Page::init(false);

					set_current_screen($screen_id);
				}
			}, 9);
		}else{
			add_action(self::get_prefix().'admin_menu',	fn()=> WPJAM_Menu_Page::init(true), 9);
		}

		wpjam_register_page_action('delete_notice', [
			'button_text'	=> '删除',
			'tag'			=> 'span',
			'class'			=> 'hidden delete-notice',
			'validate'		=> true,
			'direct'		=> true,
			'callback'		=> ['WPJAM_Notice', 'ajax_delete'],
		]);

		add_action('current_screen',	[self::class, 'on_current_screen'], 9);
		add_action('admin_notices',		[self::class, 'on_admin_notices']);
	}
}

class WPJAM_Admin_Action extends WPJAM_Register{
	public function parse_submit_button($button, $name=null, $render=null){
		$render	??= is_null($name);
		$button	= $button ? (is_array($button) ? $button : [$this->name => $button]) : [];

		foreach($button as $key => &$item){
			$item	= (is_array($item) ? $item : ['text'=>$item])+['response'=>($this->response ?? $this->name), 'class'=>'primary'];
			$item	= $render ? get_submit_button($item['text'], $item['class'], $key, false) : $item;
		}

		if($name){
			return $button[$name] ?? wp_die('无效的提交按钮');
		}

		return $render ? implode('', $button) : $button;
	}

	private function parse_nonce_action($args=[]){
		return wpjam_join('-', ($GLOBALS['plugin_page'] ?? $GLOBALS['current_screen']->id), $this->name, $args['id'] ?? '');
	}

	public function create_nonce($args=[]){
		return wp_create_nonce($this->parse_nonce_action($args));
	}

	public function verify_nonce($args=[]){
		return check_ajax_referer($this->parse_nonce_action($args), false, false);
	}
}

class WPJAM_Page_Action extends WPJAM_Admin_Action{
	public function is_allowed($type=''){
		return wpjam_current_user_can(($this->capability ?? ($type ? 'manage_options' : '')), $this->name);
	}

	public function callback($type=''){
		if($type == 'form'){
			$title	= wpjam_get_post_parameter('page_title');

			if(!$title){
				$key	= wpjam_find(['page_title', 'button_text', 'submit_text'], fn($k)=> $this->$k && !is_array($this->$k));
				$title	= $key ? $this->$key : $title;
			}

			return [
				'form'			=> $this->get_form(),
				'width'			=> (int)$this->width,
				'modal_id'		=> $this->modal_id ?: 'tb_modal',
				'page_title'	=> $title
			];
		}

		if(!$this->verify_nonce()){
			wp_die('invalid_nonce');
		}

		if(!$this->is_allowed($type)){
			wp_die('access_denied');
		}

		if($type == 'submit'){
			$submit		= wpjam_get_post_parameter('submit_name') ?: $this->name;
			$button		= $this->get_submit_button($submit);
			$callback	= $button['callback'] ?? '';
			$response	= $button['response'];
		}else{
			$submit		= $callback	= '';
			$response	= $this->response ?? $this->name;
		}

		$response	= ['type'=>$response];
		$callback	= $callback ?: $this->callback;

		if(!$callback || !is_callable($callback)){
			wp_die('无效的回调函数');
		}

		if($this->validate){
			$data	= wpjam_get_fields_parameter($this->get_fields(), 'data');
			$result	= wpjam_try($callback, $data, $this->name, $submit);
		}else{
			$result	= wpjam_try($callback, $this->name, $submit);
		}

		if(is_array($result)){
			$response	= array_merge($response, $result);
		}elseif($result === false || is_null($result)){
			$response	= new WP_Error('invalid_callback', ['返回错误']);
		}elseif($result !== true){
			$key		= $this->response == 'redirect' ? 'url' : 'data';
			$response	= array_merge($response, [$key=>$result]);
		}

		return apply_filters('wpjam_ajax_response', $response);
	}

	public function render(){
		try{
			return $this->get_form();
		}catch(Exception $e){
			wp_die(wpjam_catch($e));
		}
	}

	public function get_submit_button($name=null, $render=null){
		if(!is_null($this->submit_text)){
			$button	= $this->submit_text;
			$button	= is_callable($button) ? wpjam_try($button, $this->name) : $button;
		}else{
			$button = wp_strip_all_tags($this->page_title);
		}

		return $this->parse_submit_button($button, $name, $render);
	}

	public function get_data(){
		$data	= is_callable($this->data_callback) ? wpjam_try($this->data_callback, $this->name, $this->get_fields()) : [];

		return array_merge(($this->data ?: []), $data);
	}

	public function get_button($args=[]){
		if(!$this->is_allowed()){
			return '';
		}

		$this->update_args(wpjam_except($args, 'data'));

		$text	= $this->button_text ?? '保存';
		$attr	= ['title'=>$this->page_title ?: $text, 'style'=>$this->style, 'class'=>$this->class ?? 'button-primary large'];
		$data	= $this->generate_data_attr(['data'=>wpjam_pull($args, 'data') ?: []]);

		return wpjam_tag(($this->tag ?: 'a'), $attr, $text)->add_class('wpjam-button')->data($data);
	}

	public function get_form(){
		if(!$this->is_allowed()){
			return '';
		}

		$args	= array_merge($this->args, ['data'=>$this->get_data()]);
		$button	= $this->get_submit_button();
		$form	= wpjam_fields($this->get_fields())->render($args, false)->wrap('form', [
			'method'	=> 'post',
			'action'	=> '#',
			'id'		=> $this->form_id ?: 'wpjam_form',
			'data'		=> $this->generate_data_attr([], 'form')
		]);

		return $button ? $form->append('p', ['submit'], $button) : $form;
	}

	protected function get_fields(){
		$fields	= $this->fields;
		$fields	= ($fields && is_callable($fields)) ? wpjam_try($fields, $this->name) : $fields;

		return $fields ?: [];
	}

	public function generate_data_attr($args=[], $type='button'){
		return [
			'action'	=> $this->name,
			'nonce'		=> $this->create_nonce()
		] + ($type == 'button' ? [
			'title'		=> $this->page_title ?: $this->button_text,
			'data'		=> wp_parse_args(($args['data'] ?? []), ($this->data ?: [])),
			'direct'	=> $this->direct,
			'confirm'	=> $this->confirm
		] : []);
	}

	public static function ajax_response($data){
		$object	= self::get($data['page_action']);

		if($object){
			return $object->callback($data['action_type']);
		}

		do_action_deprecated('wpjam_page_action', [$data['page_action'], $data['action_type']], 'WPJAM Basic 4.6');

		$callback	= wpjam_get_filter_name($GLOBALS['plugin_page'], 'ajax_response');

		if(is_callable($callback)){
			$result	= $callback($data['page_action']);
			$result	= (is_wp_error($result) || is_array($result)) ? $result : [];

			wpjam_send_json($result);
		}else{
			wp_die('invalid_callback');
		}
	}
}

class WPJAM_Dashboard extends WPJAM_Args{
	public function page_load(){
		if($this->name != 'dashboard'){
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
			// wp_dashboard_setup();

			wp_enqueue_script('dashboard');

			if(wp_is_mobile()){
				wp_enqueue_script('jquery-touch-punch');
			}
		}

		$widgets	= $this->widgets ?: [];
		$widgets	= is_callable($widgets) ? $widgets($this->name) : $widgets;
		$widgets	= array_merge($widgets, array_filter(wpjam_get_items('dashboard_widget'), fn($widget)=> isset($widget['dashboard']) ? ($widget['dashboard'] == $this->name) : ($this->name == 'dashboard')));

		foreach($widgets as $id => $widget){
			$id	= $widget['id'] ?? $id;

			add_meta_box(
				$id,
				$widget['title'],
				$widget['callback'] ?? wpjam_get_filter_name($id, 'dashboard_widget_callback'),
				get_current_screen(),			// 传递 screen_id 才能在中文的父菜单下，保证一致性。
				$widget['context'] ?? 'normal',	// 位置，normal 左侧, side 右侧
				$widget['priority'] ?? 'core',
				$widget['args'] ?? []
			);
		}
	}

	public function render(){
		$tag	= wpjam_tag('div', ['id'=>'dashboard-widgets-wrap'], wpjam_ob_get_contents('wp_dashboard'));
		$panel	= $this->welcome_panel;

		if($panel && is_callable($panel)){
			$tag->before('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], wpjam_ob_get_contents($panel, $this->name));
		}

		return $tag;
	}

	public static function add_widget($name, $args){
		wpjam_add_item('dashboard_widget', $name, $args);
	}
}

class WPJAM_Menu_Page extends WPJAM_Args{
	private function parse($args, $render=false){
		$this->args	= $args;

		if(!$this->menu_title){
			return;
		}

		$slug	= $this->menu_slug;
		$parent	= $this->parent;
		$page	= ($parent && strpos($parent, '.php')) ? $parent : 'admin.php';

		if(!$this->is_available($this->pull('network', ($page == 'admin.php')))){
			return;
		}

		$this->page_title	??= $this->menu_title;
		$this->capability	??= 'manage_options';

		if(!str_contains($slug, '.php')){
			$this->admin_url = add_query_arg(['page'=>$slug], $page);

			if(!$this->query_data($GLOBALS['plugin_page'] == $slug)){
				return;
			}
		}

		$object	= WPJAM_Plugin_Page::set_current($this);

		if($render){
			if(str_contains($slug, '.php')){
				if($GLOBALS['pagenow'] == explode('?', $slug)[0]){
					$query_vars	= wp_parse_args(parse_url($slug, PHP_URL_QUERY));

					if(!$query_vars || wpjam_every($query_vars, fn($v, $k)=> $v == wpjam_get_parameter($k))){
						add_filter('parent_file', fn()=> $parent ?: $slug);
					}
				}
			}else{
				$callback	= $object ? [$object, 'render'] : '__return_true';
			}

			$args	= [$this->page_title, $this->menu_title, $this->capability, $slug, ($callback ?? null), $this->position];
			$hook	= $parent ? add_submenu_page(...[$parent, ...$args]) : add_menu_page(...wpjam_add_at($args, -1, null, ($this->icon ? wpjam_fix('add', 'prev', $this->icon, 'dashicons-') : '')));

			if($object){
				$object->page_hook	= $hook;
			}
		}

		return true;
	}

	protected function query_data($current=false){
		if($this->query_args){
			$query_data	= wpjam_get_data_parameter($this->query_args);
			$null_data	= array_filter($query_data, fn($v)=> is_null($v));
			$admin_url	= $this->admin_url;

			if($null_data){
				return $current ? wp_die('「'.implode('」,「', array_keys($null_data)).'」参数无法获取') : false;
			}

			$this->admin_url	= $query_url = add_query_arg($query_data, $admin_url);
			$this->query_data	= $query_data;

			add_filter('wpjam_html', fn($html)=> str_replace("href='".esc_url($admin_url)."'", "href='".$query_url."'", $html));
		}

		return true;
	}

	protected function is_available($args){
		if(is_array($args)){
			if((isset($args['network']) && !$this->is_available($args['network']))
				|| (!empty($args['capability']) && !current_user_can($args['capability']))
			){
				return false;
			}

			return true;
		}

		return is_network_admin() ? (bool)$args : $args !== 'only';
	}

	public static function init($render=true){
		do_action('wpjam_admin_init');

		if($render){
			$builtins	= array_filter(array_flip($GLOBALS['admin_page_hooks']), fn($v)=> str_contains($v, '.php'));
			$builtins	= wpjam_array($builtins, fn($k, $v)=> str_starts_with($v, 'edit.php?') && $k != 'pages' ? wpjam_get_post_type_setting($k, 'plural') : $k);
			$builtins	+= ['themes'=>'themes.php', 'options'=>$builtins['settings']];
			$builtins	+= isset($builtins['profile']) ? ['users'=>'profile.php'] : [];
		}else{
			$page	= $GLOBALS['plugin_page'] ?? '';

			if(!$page){
				return;
			}
		}

		$menu	= new self();

		foreach(apply_filters('wpjam_pages', wpjam_get_items('menu_page')) as $slug => $args){
			$slug	= $args['menu_slug'] ??= $slug;
			$subs	= $args['subs'] ??= [];
			$parent	= $render ? ($builtins[$slug] ?? '') : '';

			if(!$parent){
				$parent	= $slug;

				if($render){
					if(!$menu->parse($args, $render)){
						continue;
					}
				}else{
					if(!$subs && $page == $slug){
						return $menu->parse($args);
					}
				}
			}

			if(!$subs){
				continue;
			}

			$subs	= wpjam_sort($subs, fn($v)=> array_get($v, 'order', 10) - 1000 * array_get($v, 'position', 10));

			if($parent == $slug){
				$sub	= $subs[$slug] ?? wpjam_except($args, ['position', 'subs', 'page_title']);
				$sub	= array_merge($sub, !empty($sub['sub_title']) ? ['menu_title'=>$sub['sub_title']] : []);
				$subs	= array_merge([$slug=>$sub], $subs);
			}

			foreach($subs as $s => $sub){
				$sub	+= ['menu_slug'=>$s, 'parent'=>$parent];

				if($render){
					$menu->parse($sub, $render);
				}else{
					if($page == $s){
						return $menu->parse($sub);
					}
				}
			}
		}
	}

	public static function get_tabs($page, $strict=true){
		return wpjam_filter(wpjam_get_items('tab_page'), fn($args)=> empty($args['plugin_page']) ? !$strict : $args['plugin_page'] == $page);
	}

	public static function add($args=[]){
		if(!empty($args['tab_slug'])){
			if(!is_numeric($args['tab_slug']) && !empty($args['title'])){
				$tab	= array_merge($args, ['name'=>$args['tab_slug'], 'tab_page'=>true]);
				$slug	= wpjam_join(':', [($args['plugin_page'] ?? ''), $args['tab_slug']]);
				$score	= wpjam_get($tab, 'order', 10);
				$items	= wpjam_add_item('tab_page', $slug, $tab, fn($v)=> $score > wpjam_get($v, 'order', 10));
			}
		}elseif(!empty($args['menu_slug'])){
			if(!is_numeric($args['menu_slug']) && !empty($args['menu_title'])){
				$slug	= wpjam_pull($args, 'menu_slug');
				$parent	= wpjam_pull($args, 'parent');
				$args	= $parent ? ['subs'=>[$slug=>$args]] : $args+['subs'=>[]];
				$slug	= $parent ?: $slug;
				$item	= wpjam_get_item('menu_page', $slug);
				$subs	= $item ? array_merge($item['subs'], $args['subs']) : [];
				$args	= $item ? array_merge($item, $args, ['subs'=>$subs]) : $args;

				wpjam_set_item('menu_page', $slug, $args);
			}
		}
	}
}

class WPJAM_Plugin_Page extends WPJAM_Menu_Page{
	public function __get($key){
		if($key == 'is_tab'){
			return $this->function == 'tab';
		}elseif($key == 'cb_args'){
			return [$GLOBALS['plugin_page'], ($this->tab_page ? $this->name : '')];
		}

		$value	= parent::__get($key);

		if($key == 'function'){
			return $value == 'list' ? 'list_table' : ($value ?: wpjam_get_filter_name($this->name, 'page'));
		}

		return $value;
	}

	private function throw($title){
		wpjam_throw('error', $title);
	}

	private function include_file(){
		$key	= ($this->tab_page ? 'tab' : 'page').'_file';
		$file	= (array)$this->$key ?: [];

		array_walk($file, fn($f)=> include $f);
	}

	public function load($screen=null, $page_hook=null){
		$this->set_defaults();

		if($screen && str_contains($screen->id, '%')){
			$parts	= explode('_', $screen->id);
			$hooks	= array_flip($GLOBALS['admin_page_hooks']);

			if(isset($hooks[$parts[0]])){
				$parts[0]	= $hooks[$parts[0]];
				$screen->id	= implode('_', $parts);
			}
		}

		do_action('wpjam_plugin_page_load', ...$this->cb_args);	// 兼容

		wpjam_admin_load('plugin_page', function($load, $page, $tab){
			if(!empty($load['plugin_page'])){
				if(is_callable($load['plugin_page'])){
					return $load['plugin_page']($page, $tab);
				}

				if(!wpjam_compare($page, $load['plugin_page'])){
					return false;
				}
			}

			if(!empty($load['current_tab'])){
				return $tab && wpjam_compare($tab, $load['current_tab']);
			}

			return !$tab;
		}, ...$this->cb_args);

		// 一般 load_callback 优先于 load_file 执行
		// 如果 load_callback 不存在，尝试优先加载 load_file

		$included	= false;
		$callback	= $this->load_callback;

		if($callback){
			if(!is_callable($callback)){
				$this->include_file();

				$included	= true;
			}

			if(is_callable($callback)){
				$callback($this->name);
			}
		}

		if(!$included){
			$this->include_file();
		}

		if(!$this->is_tab){
			$function	= $this->function;

			if(is_string($function) && in_array($function, ['option', 'list_table', 'form', 'dashboard'])){
				$name	= $this->{$function.'_name'} ?: $GLOBALS['plugin_page'];

				$this->preprocess($name, $screen);
			}
		}

		if($this->chart && !is_object($this->chart)){
			$this->chart	= WPJAM_Chart::get_instance($this->chart);
		}

		if($this->editor){
			add_action('admin_footer', 'wp_enqueue_editor');
		}

		try{
			$this->query_data	??= [];

			if($this->is_tab){
				$object	= $this->get_tab();

				$object->chart	??= $this->chart;

				$object->load($screen, $this->page_hook);

				$this->render		= [$object, 'render'];
				$this->admin_url	= $object->admin_url;
				$this->query_data	+= $object->query_data ?: [];

				wpjam_add_item('page_setting', 'current_tab', $object->name);
			}else{
				if(!empty($name)){
					$object	= $this->page_object($name);

					if(method_exists($object, 'page_load')){
						if(wp_doing_ajax()){
							$object->page_load();
						}else{
							add_action('load-'.($page_hook ?: $this->page_hook), [$object, 'page_load']);
						}
					}

					$this->render				= [$object, 'render'];
					$this->page_title			= $object->title ?: $this->page_title;
					$this->page_title_action	= $object->page_title_action;
					$this->summary				= $this->summary ?: $object->summary;
					$this->query_data			+= wpjam_get_data_parameter($object->query_args);
				}else{
					if(!is_callable($function)){
						$this->throw('页面函数'.'「'.$function.'」未定义。');
					}

					$this->render	= fn()=> ($this->chart ? $this->chart->render() : '').wpjam_ob_get_contents($function);
				}
			}
		}catch(Exception $e){
			wpjam_add_admin_error(wpjam_catch($e));
		}
	}

	private function preprocess($name, $screen){
		do_action('wpjam_preprocess_plugin_page', $this, $name);	// 兼容

		$function	= $this->function;

		if($function == 'form'){
			$object	= WPJAM_Page_Action::get($name);
		}elseif($function == 'option'){
			$object	= WPJAM_Option_Setting::get($name);
		}

		if(isset($object)){
			$args	= $object->to_array();
		}else{
			$args	= $this->$function;

			if($args){
				if($function == 'list_table' && is_string($args) && class_exists($args) && method_exists($args, 'get_list_table')){
					$args	= [$args, 'get_list_table'];
				}

				if(is_callable($args)){
					$args	= $args($this);
				}

				$this->$function	= $args;
			}
		}

		$args	= ($args && is_array($args)) ? wpjam_parse_data_type($args) : [];

		if($args){
			$this->update_args($args);
		
			$data_type	= $this->data_type;

			if($data_type){
				$screen->add_option('data_type', $data_type);

				$object	= wpjam_get_data_type_object($data_type, $args);

				if($object && $object->meta_type){
					$screen->add_option('meta_type', $object->meta_type);
				}

				if(in_array($data_type, ['post_type', 'taxonomy']) && !$screen->$data_type && $this->$data_type){
					$screen->$data_type	= $this->$data_type;
				}
			}
		}
	}

	private function page_object($name){
		$function	= $this->function;

		if($function == 'form'){
			$object	= WPJAM_Page_Action::get($name);

			if(!$object){
				$args	= $this->form ?: ($this->callback ? $this->to_array() : []);
				$object	= $args ? WPJAM_Page_Action::register($name, $args) : $this->throw('Page Action'.'「'.$name.'」未定义。');
			}

			return $object;
		}elseif($function == 'option'){
			$object	= WPJAM_Option_Setting::get($name);

			if(!$object){
				if($this->model && method_exists($this->model, 'register_option')){	// 舍弃 ing
					$object	= call_user_func([$this->model, 'register_option'], $this->delete_arg('model')->to_array());
				}else{
					$args	= $this->option ?: (($this->sections || $this->fields) ? $this->to_array() : []);

					if(!$args){
						$args	= apply_filters(wpjam_get_filter_name($name, 'setting'), []); // 舍弃 ing
						$args	= $args ?: $this->throw('Option'.'「'.$name.'」未定义。');
					}

					$object	= WPJAM_Option_Setting::create($name, $args);
				}
			}

			return $object->get_current();
		}elseif($function == 'list_table'){
			$args	= $this->list_table;

			if($args){
				if(isset($args['defaults'])){
					$this->set_defaults($args['defaults']);
				}
			}else{
				$args	= $this->model ? wpjam_except($this->to_array(), 'defaults') : apply_filters(wpjam_get_filter_name($name, 'list_table'), []);
				$args	= $args ?: $this->throw('List Table'.'「'.$name.'」未定义。');
			}

			if(empty($args['model']) || (!is_object($args['model']) && !class_exists($args['model']))){
				$this->throw('List Table Model'.'「'.$args['model'].'」未定义。');
			}

			foreach(['admin_head', 'admin_footer'] as $admin_hook){
				if(method_exists($args['model'], $admin_hook)){
					add_action($admin_hook,	[$args['model'], $admin_hook]);
				}
			}

			$args	+= [
				'name'		=> $name,
				'singular'	=> $name,
				'plural'	=> $name.'s',
				'capability'=> $this->capability ?: 'manage_options',
				'data_type'	=> 'model',
				'per_page'	=> 50,
			]+($this->chart ? ['chart'=>$this->chart] : []);

			return new WPJAM_List_Table($args);
		}elseif($function == 'dashboard'){
			$args	= $this->dashboard ?: ($this->widgets ? $this->to_array() : []);
			$args	= $args ?: $this->throw('Dashboard'.'「'.$name.'」未定义。');

			return new WPJAM_Dashboard(array_merge($args, ['name'=>$name]));
		}
	}

	public function render(){
		$tag	= wpjam_tag(($this->tab_page ? 'h2' : 'h1'), ['wp-heading-inline'], ($this->page_title ?? $this->title));
		$tag	= $tag->after([
			['span', ['page-title-action-wrap'], $this->page_title_action ?: ''],
			$this->tab_page ? '' : ['hr', ['wp-header-end']]
		]);

		$summary	= $this->summary;

		if($summary){
			if(is_callable($summary)){
				$summary	= $summary(...$this->cb_args);
			}elseif(is_array($summary)){
				$summary	= $summary[0].(!empty($summary[1]) ? '，详细介绍请点击：'.wpjam_tag('a', ['href'=>$summary[1], 'target'=>'_blank'], $this->title ?: $this->menu_title) : '');
			}elseif(is_file($summary)){
				$summary	= wpjam_get_file_summary($summary);
			}

			$tag->after('p', [], $summary);
		}

		if($this->is_tab){
			$callback	= wpjam_get_filter_name($this->name, 'page');

			if(is_callable($callback)){
				$tag->after(wpjam_ob_get_contents($callback));	// 所有 Tab 页面都执行的函数
			}

			if(count($this->tabs) > 1){
				$tag->after(wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix'])->append(array_map(fn($tab)=> ['a', ['class'=>['nav-tab', $GLOBALS['current_tab'] == $tab->name ? 'nav-tab-active' : ''], 'href'=>$tab->admin_url], ($tab->tab_title ?: $tab->title)], $this->tabs)));
			}
		}

		if($this->render){
			$tag->after(call_user_func($this->render, $this));
		}

		if($this->tab_page){
			return $tag;
		}

		echo $tag->wrap('div', ['wrap']);
	}

	private function set_defaults($defaults=[]){
		if($defaults){
			$this->defaults	= array_merge(($this->defaults ?: []), $defaults);
		}

		if($this->defaults){
			wpjam_var('defaults', $this->defaults);
		}
	}

	private function get_tab(){
		$tabs	= $this->tabs ?: [];
		$tab	= $GLOBALS['current_tab'] ?? '';

		if($tab){
			return $tabs[$tab] ?? null;
		}

		$tabs	= is_callable($tabs) ? $tabs($this->name) : $tabs;
		$tabs	= apply_filters(wpjam_get_filter_name($this->name, 'tabs'), $tabs);
		$result	= wpjam_map($tabs, fn($args, $name)=> self::add(array_merge($args, ['tab_slug'=>$name])));
		$tab	= sanitize_key(wpjam_get_parameter(...(wp_doing_ajax() ? ['current_tab', [], 'POST'] : ['tab'])));
		$tabs	= [];

		foreach(self::get_tabs($this->name, false) as $args){
			if(!$this->is_available($args)){
				continue;
			}

			$object	= new self($args);
			$slug	= $object->tab_slug;
			$tab	= $tab ?: $slug;

			$object->admin_url	= $this->admin_url.'&tab='.$slug;

			if($object->query_data($tab == $slug)){
				$tabs[$slug]	= $object;
			}
		}

		$GLOBALS['current_tab']	= $tab;

		$this->tabs	= $tabs ?? [];

		if(empty($tabs)){
			$this->throw('Tabs 未设置');
		}

		$object	= $tabs[$tab] ?? null;

		if(!$object){
			$this->throw('无效的 Tab');
		}elseif(!$object->function){
			$this->throw('Tab 未设置 function');
		}elseif(!$object->function == 'tab'){
			$this->throw('Tab 不能嵌套 Tab');
		}

		return $object;
	}

	public function get_setting($key='', $tab=false){
		if(str_ends_with($key, '_name')){
			$tab		= $this->is_tab;
			$default	= $GLOBALS['plugin_page'];
		}else{
			$tab		= $tab ? $this->is_tab : false;
			$default	= null;
		}

		if($tab){
			try{
				$object	= $this->get_tab();
			}catch(Exception $e){
				return null;
			}
		}else{
			$object	= $this;
		}

		return $key ? ($object->$key ?: $default) : $object->to_array();
	}

	public static function get_current(){
		return wpjam_var('plugin_page');
	}

	public static function set_current($menu){
		if($GLOBALS['plugin_page'] == $menu->menu_slug && ($menu->parent || (!$menu->parent && !$menu->subs))){
			return wpjam_var('plugin_page', new static(array_merge($menu->get_args(), ['name'=>$menu->menu_slug])));
		}
	}
}

class WPJAM_Builtin_Page{
	protected function __construct(){}

	public function __get($key){
		$screen	= get_current_screen();
		$object	= $screen->get_option('object');

		return $screen->$key ?? ($object ? $object->$key : null);
	}

	public function __call($method, $args){
		$object	= get_screen_option('object');

		if($object){
			return call_user_func([$object, $method], ...$args);
		}
	}

	public static function on_edit_form($post){	// 下面代码 copy 自 do_meta_boxes
		$meta_boxes	= $GLOBALS['wp_meta_boxes'][$post->post_type]['wpjam'] ?? [];
		$count		= 0;

		foreach(wp_array_slice_assoc($meta_boxes, ['high', 'core', 'default', 'low']) as $_meta_boxes){
			foreach((array)$_meta_boxes as $meta_box){
				if(empty($meta_box['id']) || empty($meta_box['title'])){
					continue;
				}

				$count++;

				$title[]	= ['a', ['class'=>'nav-tab', 'href'=>'#tab_'.$meta_box['id']], $meta_box['title']];
				$content[]	= ['div', ['id'=>'tab_'.$meta_box['id']], wpjam_ob_get_contents($meta_box['callback'], $post, $meta_box)];
			}
		}

		if(!$count){
			return;
		}

		if($count == 1){
			$title	= wpjam_tag('h2', ['hndle'], $title[0][2])->wrap('div', ['postbox-header']);
		}else{
			$title	= wpjam_tag('ul')->append(array_map(fn($v)=> wpjam_tag(...$v)->wrap('li'), $title))->wrap('h2', ['nav-tab-wrapper']);
		}

		echo wpjam_tag('div', ['inside'])->append($content)->before($title)->wrap('div', ['id'=>'wpjam', 'class'=>['postbox','tabs']])->wrap('div', ['id'=>'wpjam-sortables']);
	}

	public static function call_post_options($method, ...$args){
		$post_type	= get_screen_option('post_type');
		$options	= wpjam_get_post_options($post_type, ['list_table'=>false]);

		if($method == 'callback'){	// 只有 POST 方法提交才处理，自动草稿、自动保存和预览情况下不处理
			if($_SERVER['REQUEST_METHOD'] != 'POST'
				|| get_post_status($args[0]) == 'auto-draft'
				|| get_post_type($args[0]) != $post_type
				|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
				|| (!empty($_POST['wp-preview']) && $_POST['wp-preview'] == 'dopreview')
			){
				return;
			}

			wpjam_map($options, fn($object)=> wpjam_die_if_error($object->callback($args[0])));
		}else{
			if($args[0] != $post_type){
				return;
			}

			$context	= use_block_editor_for_post_type($post_type) ? 'normal' : 'wpjam';

			wpjam_map($options, fn($object)=> add_meta_box($object->name, $object->title, [$object, 'render'], $post_type, ($object->context ?: $context), $object->priority));
		}
	}

	public static function call_term_options($method, ...$args){
		$taxonomy	= get_screen_option('taxonomy');

		if($method == 'render'){
			$term	= array_shift($args);
			$action	= $term ? 'edit' : 'add';
			$args	= [($term ? $term->term_id : false), ['fields_type'=>($term ? 'tr' : 'div'), 'wrap_class'=>'form-field']];
		}elseif($method == 'validate'){
			$action	= 'add';
			$term	= array_shift($args);

			if(array_shift($args) != $taxonomy){
				return;
			}
		}elseif($method == 'callback'){
			$action	= $_POST['action'] == 'add-tag' ? 'add' : 'edit';
			
			if(get_term_taxonomy($args[0]) != $taxonomy){
				return;
			}
		}

		wpjam_map(wpjam_get_term_options($taxonomy, ['action'=>$action, 'list_table'=>false]), fn($object)=> wpjam_die_if_error(wpjam_catch([$object, $method], ...$args)));

		if($method == 'validate'){
			return $term;
		}
	}

	public static function init($screen){
		$base	= $screen->base;

		if(in_array($base, ['edit', 'upload', 'post', 'term', 'edit-tags'])){
			$typenow	= $GLOBALS['typenow'];
			$taxnow		= $GLOBALS['taxnow'];

			if(in_array($base, ['edit', 'upload', 'post'])){
				$object	= wpjam_get_post_type_object($typenow);
			}elseif(in_array($base, ['term', 'edit-tags'])){
				$object	= wpjam_get_taxonomy_object($taxnow);
			}

			if(!$object){
				return;
			}

			$screen->add_option('object', $object);
		}

		wpjam_admin_load('builtin_page', function($load, $screen){
			if(!empty($load['screen']) && is_callable($load['screen']) && !$load['screen']($screen)){
				return false;
			}

			if(wpjam_some(['base', 'post_type', 'taxonomy'], fn($k)=> !empty($load[$k]) && !wpjam_compare($screen->$k, $load[$k]))){
				return false;
			}

			return true;
		}, $screen);

		if(in_array($base, ['edit', 'upload'])){
			if($base == 'upload'){
				$mode	= get_user_option('media_library_mode', get_current_user_id()) ?: 'grid';

				if(isset($_GET['mode']) && in_array($_GET['mode'], ['grid', 'list'], true)){
					$mode	= $_GET['mode'];
				}

				if($mode == 'grid'){
					return;
				}
			}

			new WPJAM_Posts_List_Table($object);
		}elseif($base == 'post'){
			$fragment	= parse_url(wp_get_referer(), PHP_URL_FRAGMENT);
			$label		= $object->labels->name;

			if(!in_array($typenow, ['post', 'page', 'attachment'])){
				add_filter('post_updated_messages',	fn($ms)=> $ms+[$typenow=> wpjam_map($ms['post'], fn($m)=> str_replace('文章', $label, $m))]);
			}

			if($fragment){
				add_filter('redirect_post_location', fn($location)=> $location.(parse_url($location, PHP_URL_FRAGMENT) ? '' : '#'.$fragment));
			}

			if($object->thumbnail_size){
				add_filter('admin_post_thumbnail_html', fn($content)=> $content.wpautop('尺寸：'.$object->thumbnail_size));
			}

			add_action(($typenow == 'page' ? 'edit_page_form' : 'edit_form_advanced'),	[self::class, 'on_edit_form'], 99);

			add_action('add_meta_boxes',		fn($post_type)=> self::call_post_options('render', $post_type));
			add_action('wp_after_insert_post',	fn($post_id)=> self::call_post_options('callback', $post_id), 999, 2);
		}elseif(in_array($base, ['term', 'edit-tags'])){
			$label	= $object->labels->name;

			if(!in_array($taxnow, ['post_tag', 'category'])){
				add_filter('term_updated_messages',	fn($ms)=> $ms+[$taxnow=> array_map(fn($m)=> str_replace(['项目', 'Item'], [$label, ucfirst($label)], $m), $ms['_item'])]);
			}

			if($base == 'edit-tags'){
				wpjam_map(['slug', 'description'], fn($k)=> $object->supports($k) ? null : wpjam_unregister_list_table_column($k));

				wpjam_unregister_list_table_action('inline hide-if-no-js');

				if(wp_doing_ajax()){
					if($_POST['action'] == 'add-tag'){
						add_filter('pre_insert_term',	fn($term, $taxonomy)=> self::call_term_options('validate', $term, $taxonomy), 10, 2);
						add_action('created_term',		fn($term_id)=> self::call_term_options('callback', $term_id));
					}
				}elseif(isset($_POST['action'])){
					if($_POST['action'] == 'editedtag'){
						add_action('edited_term',		fn($term_id)=> self::call_term_options('callback', $term_id));
					}
				}else{
					add_action($taxnow.'_add_form_fields',	fn()=> self::call_term_options('render'));
				}

				new WPJAM_Terms_List_Table($object);
			}else{
				add_action($taxnow.'_edit_form_fields',	fn($term)=> self::call_term_options('render', $term));
			}
		}elseif($base == 'users'){
			new WPJAM_Users_List_Table();
		}
	}

	public static function load($screen){
		return new static($screen);
	}
}

class WPJAM_Chart extends WPJAM_Args{
	public function get_parameter($key){
		if(str_contains($key, 'timestamp')){
			return wpjam_strtotime($this->get_parameter(str_replace('timestamp', 'date', $key)).' '.(str_starts_with($key, 'end_') ? '23:59:59' : '00:00:00'));
		}

		$value	= wpjam_get_parameter($key, ['method'=>$this->method]);

		if($value){
			wpjam_set_cookie($key, $value, HOUR_IN_SECONDS);
		}else{
			$value	= $_COOKIE[$key] ?? null;
			$value	= $value ?: $this->get_default($key);
		}

		return $value;
	}

	protected function get_default($key){
		if($key == 'date_format' || $key == 'date_type'){
			return '%Y-%m-%d';
		}elseif($key == 'compare'){
			return 0;
		}elseif(str_contains($key, 'date')){
			if($key == 'start_date'){
				$ts	= time() - DAY_IN_SECONDS*30;
			}elseif($key == 'end_date'){
				$ts	= time();
			}elseif($key == 'date'){
				$ts	= time() - DAY_IN_SECONDS;
			}elseif($key == 'start_date_2'){
				$ts	= $this->get_parameter('end_timestamp_2') - ($this->get_parameter('end_timestamp') - $this->get_parameter('start_timestamp'));
			}elseif($key == 'end_date_2'){
				$ts	= $this->get_parameter('start_timestamp') - DAY_IN_SECONDS;
			}

			return wpjam_date('Y-m-d', $ts);
		}
	}

	public function render($wrap=true){
		if(!$this->show_form){
			return;
		}

		if($this->show_start_date){
			$fields['date']	= ['type'=>'fields',	'title'=>'日期：',	'fields'=>[
				'start_date'	=> ['type'=>'date',	'value'=>$this->get_parameter('start_date')],
				'sep_view'		=> ['type'=>'view',	'value'=>'-'],
				'end_date'		=> ['type'=>'date',	'value'=>$this->get_parameter('end_date')]
			]];
				
		}elseif($this->show_date){
			$fields['date']			= ['type'=>'date',	'value'=>$this->get_parameter('date')];
		}

		if($this->show_date_type){
			$fields['date_format']	= ['type'=>'select','value'=>$this->get_parameter('date_format'), 'options'=>[
				'%Y-%m'				=> '按月',
				'%Y-%m-%d'			=> '按天',
				// '%Y%U'			=> '按周',
				'%Y-%m-%d %H:00'	=> '按小时',
				'%Y-%m-%d %H:%i'	=> '按分钟',
			]];
		}elseif($this->show_date){
			$fields['date_format']	= ['type'=>'hidden','value'=>'%Y-%m-%d'];
		}

		$current	= wpjam_get_parameter('type', ['default'=>-1]);
		$current	= $current == 'all' ? '-1' : $current;

		if($this->show_compare){
			if($current !=-1 && $this->show_start_date){
				$fields['compare_date']	= ['type'=>'fields',	'title'=>'对比：',	'fields'=>[
					'start_date_2'	=> ['type'=>'date',	'value'=>$this->get_parameter('start_date_2')],
					'sep_view_2'	=> ['type'=>'view',	'value'=>'-'],
					'end_date_2'	=> ['type'=>'date',	'value'=>$this->get_parameter('end_date_2')],
					'compare'		=> ['type'=>'checkbox',	'value'=>$this->get_parameter('compare')],
				]];
			}
		}

		if(!empty($fields)){
			$fields	= apply_filters('wpjam_chart_fields', $fields);
			$fields	+= $wrap ? ['chart_button'=>['type'=>'submit', 'value'=>'显示', 'class'=>'button button-secondary']] : [];
			$fields	= wpjam_fields($fields)->render(['fields_type'=>'span']);

			if($wrap){
				$action	= $GLOBALS['current_admin_url'];
				$action	= $current == -1 ? $action : $action.'&type='.$current;

				$fields->wrap('form', ['method'=>'POST', 'action'=>$action, 'id'=>'chart_form', 'class'=>'chart-form']);
			}

			return $fields;
		}
	}

	public static function line($args=[], $type='Line'){
		$args	+= [
			'data'			=> [],
			'labels'		=> [],
			'day_labels'	=> [],
			'day_label'		=> '时间',
			'day_key'		=> 'day',
			'chart_id'		=> 'daily-chart',
			'show_table'	=> true,
			'show_chart'	=> true,
			'show_sum'		=> true,
			'show_avg'		=> true,
		];

		foreach($args['labels'] as $k => $v){
			if(is_array($v)){
				$args['columns'][$k]	= $v['label'];

				if(!isset($v['show_in_chart']) || $v['show_in_chart']){
					$labels[$k]	= $v['label'];
				}

				if(!empty($v['callback'])){
					$cbs[$k]	= $v['callback'];
				}
			}else{
				$args['columns'][$k]	= $labels[$k] = $v;
			}
		}

		$parser	= fn($item)=> empty($cbs) ? $item : array_merge($item, wpjam_map($cbs, fn($cb)=> $cb($item)));
		$data	= $total = [];

		if($args['show_table']){
			$args['day_labels']	+= ['sum'=>'累加', 'avg'=>'平均'];

			$row	= self::row('head', [], $args);
			$thead	= wpjam_tag('thead')->append($row);
			$tfoot	= wpjam_tag('tfoot')->append($row);
			$tbody	= wpjam_tag('tbody');
		}

		foreach($args['data'] as $day => $item){
			$item	= $parser((array)$item);
			$day	= $item[$args['day_key']] ?? $day;
			$total	= wpjam_map($args['columns'], fn($v, $k)=> ($total[$k] ?? 0)+((isset($item[$k]) && is_numeric($item[$k])) ? $item[$k] : 0));
			$data[]	= array_merge([$args['day_key']=> $day], array_intersect_key($item, $labels));

			if($args['show_table']){
				$tbody->append(self::row($day, $item, $args));
			}
		}

		$tag	= wpjam_tag();

		if($args['show_chart'] && $data){
			wpjam_tag('div', ['class'=>'chart', 'id'=>$args['chart_id']])->data('type', $type)->data('options', ['data'=>$data, 'xkey'=>$args['day_key'], 'ykeys'=>array_keys($labels), 'labels'=>array_values($labels)])->append_to($tag);
		}

		if($args['show_table'] && $args['data']){
			$total	= $parser($total);

			if($args['show_sum']){
				$tbody->append(self::row('sum', $total, $args));
			}

			if($args['show_avg']){
				$num	= count($args['data']);
				$avg	= array_map(fn($v)=> is_numeric($v) ? round($v/$num) : '', $total);

				$tbody->append(self::row('avg', $avg, $args));
			}

			$thead->after([$tbody, $tfoot])->wrap('table', ['class'=>'wp-list-table widefat striped'])->append_to($tag);
		}

		return $tag;
	}

	public static function donut($args=[]){
		$args	+= [
			'data'			=> [],
			'total'			=> 0,
			'title'			=> '名称',
			'key'			=> 'type',
			'chart_id'		=> 'chart_'.wp_generate_password(6, false, false),
			'show_table'	=> true,
			'show_chart'	=> true,
			'show_line_num'	=> false,
			'labels'		=> []
		];

		if($args['show_table']){
			$thead	= wpjam_tag('thead')->append(self::row('head', '', $args));
			$tbody	= wpjam_tag('tbody');
		}

		foreach(array_values($args['data']) as $i => $item){
			$label 	= $item['label'] ?? '/';
			$label 	= $args['labels'][$label] ?? $label;
			$value	= $item['count'];
			$data[]	= ['label'=>$label, 'value'=>$value];

			if($args['show_table']){
				$tbody->append(self::row($i+1, $value, ['label'=>$label]+$args));
			}
		}

		$tag	= wpjam_tag();

		if($args['show_chart']){
			$tag->append('div', ['class'=>'chart', 'id'=>$args['chart_id'], 'data'=>['options'=>['data'=>$data], 'type'=>'Donut']]);
		}

		if($args['show_table']){
			if($args['total']){
				$tbody->append(self::row('total', $args['total'], $args+['label'=>'所有']));
			}

			$tag->append('table', ['wp-list-table', 'widefat', 'striped'], implode('', [$thead, $tbody]));
		}

		return $tag->wrap('div', ['class'=>'donut-chart-wrap']);
	}

	protected static function row($key, $data=[], $args=[]){
		$row	= wpjam_tag('tr');

		if(is_array($data)){
			$day_key	= $args['day_key'];
			$columns	= [$day_key=>$args['day_label']]+$args['columns'];
			$data		= [$day_key=>$args['day_labels'][$key] ?? $key]+$data;

			foreach($columns as $col => $column){
				if($key == 'head'){
					$cell	= wpjam_tag('th', ['scope'=>'col', 'id'=>$col], $column);
				}else{
					$cell	= wpjam_tag('td', ['data'=>['colname'=>$column]], $data[$col] ?? '');
				}

				if($col == $day_key){
					$cell->add_class('column-primary')->append('button', ['class'=>'toggle-row']);
				}

				$cell->add_class('column-'.$col)->append_to($row);
			}
		}else{
			if($key == 'head'){
				$row->append([
					$args['show_line_num'] ? ['th', ['style'=>'width:40px;'], '排名'] : '',
					['th', [], $args['title']],
					['th', [], '数量'],
					$args['total'] ? ['th', [], '比例'] : ''
				]);
			}else{
				$row->append([
					$args['show_line_num'] ? ['td', [], $key == 'total' ? '' : $key] : '',
					['td', [], $args['label']],
					['td', [], $data],
					$args['total'] ? ['td', [], round($data / $args['total'] * 100, 2).'%'] : ''
				]);
			}
		}

		return $row;
	}

	public static function create_instance(){
		$offset	= (int)get_option('gmt_offset');
		$offset	= $offset >= 0 ? '+'.$offset.':00' : $offset.':00';

		$GLOBALS['wpdb']->query("SET time_zone = '{$offset}';");

		wpjam_style('morris',	wpjam_get_static_cdn().'/morris.js/0.5.1/morris.css');
		wpjam_script('raphael',	wpjam_get_static_cdn().'/raphael/2.3.0/raphael.min.js');
		wpjam_script('morris',	wpjam_get_static_cdn().'/morris.js/0.5.1/morris.min.js');

		return new self([
			'method'			=> 'POST',
			'show_form'			=> true,
			'show_start_date'	=> true,
			'show_date'			=> true,
			'show_date_type'	=> false,
			'show_compare'		=> false
		]);
	}

	public static function get_instance($args=[]){
		$object = wpjam_get_instance('chart_form', 'object', fn()=> self::create_instance());
		$args	= is_array($args) ? $args : [];

		return $object->update_args($args);
	}
}