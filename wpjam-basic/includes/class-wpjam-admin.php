<?php
class WPJAM_Admin{
	public static function get_prefix(){
		return is_network_admin() ? 'network_' : (is_user_admin() ? 'user_' : '');
	}

	public static function get_url($path=''){
		return (self::get_prefix().'admin_url')($path);
	}

	public static function get_var($key=''){
		$value	= wpjam_get_items('admin_'.($key ?: 'var'));

		if(!$value){
			return $key ? '' : [];
		}

		if($key == 'script'){
			return "jQuery(function($){".preg_replace('/^/m', "\t", "\n".implode("\n\n", $value))."\n});";
		}elseif($key == 'style'){
			return "\n".implode("\n\n", $value);
		}

		return array_map('maybe_closure', $value);
	}

	public static function add_var($key, $value){
		if(in_array($key, ['script', 'style'])){
			return wpjam_add_item('admin_'.$key, is_array($value) ? implode($key == 'script' ? "\n\n" : "\n", $value) : $value);
		}

		return wpjam_add_item('admin_var', $key, $value);
	}

	public static function add_load($args){
		$type	= wpjam_pull($args, 'type') ?: array_find(['base'=>'builtin_page', 'plugin_page'=>'plugin_page'], fn($v, $k)=> isset($args[$k]));

		if($type && in_array($type, ['builtin_page', 'plugin_page'])){
			$score	= wpjam_get($args, 'order', 10);

			wpjam_add_item($type.'_load', $args, fn($v)=> $score > wpjam_get($v, 'order', 10));
		}
	}

	public static function add_ajax($action, $args=[]){
		if(isset($_POST['action']) && $_POST['action'] == $action){
			if(wpjam_is_assoc_array($args)){
				$callback	= $args['callback'];
				$fields		= $args['fields'] ?? [];
			}else{
				$callback	= $args;
				$fields		= [];
			}

			add_filter('wp_die_ajax_handler', fn()=> ['WPJAM_Error', 'wp_die_handler']);
			add_action('wp_ajax_'.$action, fn()=> wpjam_send_json(wpjam_catch($callback, wpjam_if_error(wpjam_fields($fields)->catch('get_parameter', 'POST'), 'send'))));
		}
	}

	public static function add_error($msg='', $type='success'){
		if(is_wp_error($msg)){
			$msg	= $msg->get_error_message();
			$type	= 'error';
		}

		if($msg && $type){
			add_action('all_admin_notices',	fn()=> wpjam_echo(wpjam_tag('div', ['is-dismissible', 'notice', 'notice-'.$type], ['p', [], $msg])));
		}
	}

	public static function load($type, ...$args){
		$filter	= $type == 'plugin_page' ? function($load, $page, $tab){
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
		} : function($load, $screen){
			if(!empty($load['screen']) && is_callable($load['screen']) && !$load['screen']($screen)){
				return false;
			}

			if(array_any(['base', 'post_type', 'taxonomy'], fn($k)=> !empty($load[$k]) && !wpjam_compare($screen->$k, $load[$k]))){
				return false;
			}

			return true;
		};

		foreach(wpjam_get_items($type.'_load') as $load){
			if(!$filter($load, ...$args)){
				continue;
			}

			if(!empty($load['page_file'])){
				wpjam_map((array)$load['page_file'], fn($file)=> is_file($file) ? include $file : null);
			}

			$cb	= $load['callback'] ?? '';
			$cb	= $cb ?: (($model = $load['model'] ?? '') ? array_find([[$model, 'load'], [$model, $type.'_load']], fn($cb)=> method_exists(...$cb)) : '');

			wpjam_call($cb, ...$args);
		}
	}

	public static function on_enqueue_scripts(){
		$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
		$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';
		$screen	= get_current_screen();

		wp_enqueue_media($screen->base == 'post' ? ['post'=>wpjam_get_admin_post_id()] : []);
		wp_enqueue_style('wpjam-style', $static.'/style.css', ['thickbox', 'remixicon', 'wp-color-picker', 'editor-buttons'], $ver);
		wp_enqueue_script('wpjam-script', $static.'/script.js', ['jquery', 'thickbox', 'wp-color-picker', 'jquery-ui-sortable', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-autocomplete'], $ver);
		wp_enqueue_script('wpjam-form', $static.'/form.js', ['wpjam-script'], $ver);

		wp_localize_script('wpjam-script', 'wpjam_page_setting', ['screen_id'=>$screen->id, 'screen_base'=>$screen->base]+array_filter(wpjam_pick($screen, ['post_type', 'taxonomy']))+self::get_var());

		wp_add_inline_script('jquery', self::get_var('script'));
		wp_add_inline_style('common', self::get_var('style'));
	}

	public static function on_admin_notices(){
		WPJAM_Notice::ajax_delete();

		foreach((current_user_can('manage_options') ? ['user', 'admin'] : ['user']) as $type){
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
		}
	}

	public static function on_current_screen($screen){
		$page	= $GLOBALS['plugin_page'] ?? '';
		$object = WPJAM_Plugin_Page::get_current();

		$GLOBALS['current_admin_url']	= $base_url = self::get_url();

		if($page){
			if(!$object){
				return;
			}

			wpjam_if_error(wpjam_catch([$object, 'load'], $screen), fn($e)=> self::add_error($e));

			$url = self::get_url($object->admin_url);
		}else{
			if($screen->base == 'customize'){
				return;
			}

			if(!empty($_POST['builtin_page'])){
				$url	= self::get_url($_POST['builtin_page']);
			}else{
				$url	= set_url_scheme('http://'.$_SERVER['HTTP_HOST'].parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
				$url	= add_query_arg(array_intersect_key($_REQUEST, array_filter(wpjam_pick($screen, ['taxonomy', 'post_type']))), $url);
			}

			$GLOBALS['current_admin_url']	= $url;

			WPJAM_Builtin_Page::init($screen);
		}

		if(!wp_doing_ajax()){
			if($page){
				self::add_var('plugin_page', $page);

				if($object && $object->query_data){
					self::add_var('query_url', wpjam_get_items('query_url'));
					self::add_var('query_data', wpjam_map($object->query_data, fn($v)=> is_null($v) ? $v : (is_array($v) ? wp_die('query_data 不能为数组') : sanitize_textarea_field($v))));
				}
			}else{
				self::add_var('builtin_page', str_replace($base_url, '', $url));
			}

			self::add_var('admin_url', $url);

			add_action('admin_enqueue_scripts',	[self::class, 'on_enqueue_scripts'], 9);
		}
	}

	public static function on_admin_init(){
		$screen_id	= $_POST['screen_id'] ?? ($_POST['screen'] ?? null);

		if($screen_id){
			$page	= null;
			$const	= array_find([['network', 'WP_NETWORK_ADMIN'], ['user', 'WP_USER_ADMIN']], fn($v)=> str_ends_with($screen_id, '-'.$v[0]));

			if($const && !defined($const[1])){
				define($const[1], true);
			}

			if(str_contains($screen_id, '_page_')){
				$page	= explode('_page_', $screen_id)[1];

				if($const){
					try_remove_suffix($page, '-'.$const[0]);
				}
			}elseif($screen_id == 'upload'){
				[$GLOBALS['hook_suffix'], $screen_id]	= [$screen_id, ''];
			}

			$GLOBALS['plugin_page']	= $page;

			WPJAM_Menu_Page::init(false);

			set_current_screen($screen_id);
		}
	}

	public static function on_plugins_loaded(){
		if($GLOBALS['pagenow'] == 'admin-post.php'){
			return;
		}

		if(wp_doing_ajax()){
			self::add_ajax('wpjam-page-action', [
				'callback'	=> ['WPJAM_Page_Action', 'ajax_response'],
				'fields'	=> ['page_action'=>[], 'action_type'=>[]]
			]);

			self::add_ajax('wpjam-upload', [
				'callback'	=> ['WPJAM_Field', 'ajax_upload'],
				'fields'	=> ['file_name'=> ['required'=>true]]
			]);

			self::add_ajax('wpjam-query', [
				'callback'	=> ['WPJAM_Data_Type', 'ajax_response'],
				'fields'	=> ['data_type'=> ['required'=>true]]
			]);

			add_action('admin_init', [self::class, 'on_admin_init'], 9);
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

class WPJAM_Page_Action extends WPJAM_Register{
	public function is_allowed($type=''){
		return wpjam_current_user_can(($this->capability ?? ($type ? 'manage_options' : '')), $this->name);
	}

	public function create_nonce(){
		return wp_create_nonce($this->name);
	}

	public function verify_nonce(){
		return check_ajax_referer($this->name, false, false);
	}

	public function callback($type=''){
		if($type == 'form'){
			$title	= wpjam_get_post_parameter('page_title');

			if(!$title){
				$key	= array_find(['page_title', 'button_text', 'submit_text'], fn($k)=> $this->$k && !is_array($this->$k));
				$title	= $key ? $this->$key : $title;
			}

			return [
				'type'	=> 'form',
				'form'	=> $this->get_form(),
				'width'	=> (int)$this->width,

				'modal_id'		=> $this->modal_id,
				'page_title'	=> $title
			];
		}

		if(!$this->verify_nonce()){
			wp_die('invalid_nonce');
		}

		if(!$this->is_allowed($type)){
			wp_die('access_denied');
		}

		$response	= $this->response ?? $this->name;
		$callback	= $this->callback;
		$args		= [$this->name];

		if($type == 'submit'){
			$submit		= $args[] = wpjam_get_post_parameter('submit_name') ?: $this->name;
			$button		= $this->get_submit_button($submit);
			$callback	= $button['callback'] ?? $callback;
			$response	= $button['response'] ?? $response;
		}

		if(!$callback || !is_callable($callback)){
			wp_die('无效的回调函数');
		}

		if($this->validate){
			array_unshift($args, $this->get_fields()->get_parameter('data'));
		}

		$result	= wpjam_try($callback, ...$args);

		if(is_null($result)){
			wp_die('回调函数没有正确返回');
		}

		$response	= ['type'=>$response];

		if(is_array($result)){
			$response	= array_merge($response, $result);
		}elseif(is_string($result)){
			$key		= $response['type'] == 'redirect' ? 'url' : 'data';
			$response	= array_merge($response, [$key=>$result]);
		}

		if($response['type'] == 'redirect'){
			$response['target']	??= $this->target ?: '_self';
		}

		if($this->dismiss || !empty($response['dismiss'])){
			$response['dismiss']	= true;
		}

		return apply_filters('wpjam_ajax_response', $response);
	}

	public function render(){
		return wpjam_if_error(wpjam_catch([$this, 'get_form']), 'die');
	}

	public function get_submit_button($name=null){
		$button = maybe_callback($this->submit_text, $this->name) ?? wp_strip_all_tags($this->page_title);
		$button	= is_array($button) ? $button : [$this->name => $button];

		return wpjam_parse_submit_button($button, $name);
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

		$button	= $this->get_submit_button();
		$form	= $this->get_fields()->render()->wrap('form', [
			'novalidate',
			'method'	=> 'post',
			'action'	=> '#',
			'id'		=> $this->form_id ?: 'wpjam_form',
			'data'		=> $this->generate_data_attr([], 'form')
		]);

		return $button ? $form->append('p', ['submit'], $button) : $form;
	}

	protected function get_fields(){
		$fields	= wpjam_try(fn()=> maybe_callback($this->fields, $this->name)) ?: [];

		return WPJAM_Fields::create($fields, wpjam_merge($this->args, ['data'=>wpjam_if_error(wpjam_call($this->data_callback, $this->name, $fields), 'throw') ?: []]));
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
			$result	= wpjam_if_error($result, 'send');

			wpjam_send_json(is_array($result) ? $result : []);
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

		$widgets	= maybe_callback($this->widgets, $this->name) ?: [];
		$widgets	= array_merge($widgets, array_filter(wpjam_get_items('dashboard_widget'), fn($v)=> isset($v['dashboard']) ? ($v['dashboard'] == $this->name) : ($this->name == 'dashboard')));

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
		$panel	= wpjam_ob_get_contents($this->welcome_panel, $this->name);

		return $panel ? $tag->before('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], $panel) : $tag;
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

					if(!$query_vars || array_all($query_vars, fn($v, $k)=> $v == wpjam_get_parameter($k))){
						add_filter('parent_file', fn()=> $parent ?: $slug);
					}
				}
			}else{
				$callback	= $object ? [$object, 'render'] : '__return_true';
			}

			$args	= [$this->page_title, $this->menu_title, $this->capability, $slug, ($callback ?? null), $this->position];
			$hook	= $parent ? add_submenu_page(...[$parent, ...$args]) : add_menu_page(...wpjam_add_at($args, -1, ($this->icon ? (str_starts_with($this->icon, 'dashicons-') ? '' : 'dashicons-').$this->icon : '')));

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

			$this->admin_url	= add_query_arg($query_data, $admin_url);
			$this->query_data	= ($this->query_data ?? [])+$query_data;

			wpjam_add_item('query_url', [$admin_url, $this->admin_url]);
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
			$builtins	+= ['themes'=>'themes.php', 'options'=>$builtins['settings'] ?? ''];
			$builtins	+= isset($builtins['profile']) ? ['users'=>'profile.php'] : [];
		}else{
			$page	= $GLOBALS['plugin_page'];

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
				$slug	= wpjam_join(':', wpjam_pick($args, ['plugin_page', 'tab_slug']));
				$score	= wpjam_get($tab, 'order', 10);

				wpjam_add_item('tab_page', $slug, $tab, fn($v)=> $score > wpjam_get($v, 'order', 10));
			}
		}elseif(!empty($args['menu_slug'])){
			if(!is_numeric($args['menu_slug']) && !empty($args['menu_title'])){
				$slug	= wpjam_pull($args, 'menu_slug');
				$parent	= wpjam_pull($args, 'parent');
				$args	= $parent ? ['subs'=>[$slug=>$args]] : $args+['subs'=>[]];
				$slug	= $parent ?: $slug;

				if($item = wpjam_get_item('menu_page', $slug)){
					$subs	= array_merge($item['subs'], $args['subs']);
					$args	= array_merge($item, $args, ['subs'=>$subs]);
				}

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
		wpjam_map((array)$this->pull(($this->tab_page ? 'tab' : 'page').'_file') ?: [], fn($f)=> include $f);
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

		WPJAM_Admin::load('plugin_page', ...$this->cb_args);

		// 一般 load_callback 优先于 load_file 执行
		// 如果 load_callback 不存在，尝试优先加载 load_file
		if($this->load_callback){
			if(!is_callable($this->load_callback)){
				$this->include_file();
			}

			wpjam_call($this->load_callback, $this->name);
		}

		$this->include_file();

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

		$this->query_data	??= [];

		if($this->is_tab){
			$object	= $this->get_tab();

			$object->chart	??= $this->chart;

			$object->load($screen, $this->page_hook);

			$this->render		= [$object, 'render'];
			$this->admin_url	= $object->admin_url;
			$this->query_data	+= $object->query_data ?: [];

			WPJAM_Admin::add_var('current_tab', $object->name);
		}else{
			$GLOBALS['current_admin_url']	.= $this->admin_url;

			if(!empty($name)){
				$object	= $this->page_object($name);
				$load	= [$object, 'page_load'];

				if(wp_doing_ajax()){
					wpjam_call($load);
				}else{
					add_action('load-'.($page_hook ?: $this->page_hook), fn()=> wpjam_call($load));
				}

				$this->render		= [$object, 'render'];
				$this->page_title	= $object->title ?: $this->page_title;
				$this->summary		= $this->summary ?: $object->get_arg('summary');
				$this->query_data	+= $object->query_args ? wpjam_get_data_parameter($object->query_args) : [];
			}else{
				if(!is_callable($function)){
					$this->throw('页面函数'.'「'.$function.'」未定义。');
				}

				$this->render	= fn()=> ($this->chart ? $this->chart->render() : '').wpjam_ob_get_contents($function);
			}
		}
	}

	private function preprocess($name, $screen){
		do_action('wpjam_preprocess_plugin_page', $this, $name);	// 兼容

		$function	= $this->function;
		$class		= ['form'=>'WPJAM_Page_Action', 'option'=>'WPJAM_Option_Setting'][$function] ?? '';
		$object		= $class ? $class::get($name) : '';

		if($object){
			$args	= $object->to_array();
		}else{
			$args	= $this->$function;

			if($args){
				if($function == 'list_table' && is_string($args) && class_exists($args)){
					$cb		= [$args, 'get_list_table'];
					$args	= method_exists(...$cb) ? $cb : $args;
				}

				$this->$function	= $args = maybe_callback($args, $this);
			}
		}

		if(!empty($args['meta_type'])){
			$screen->add_option('meta_type', $args['meta_type']);
		}

		$args	= WPJAM_Data_Type::prepare($args);

		if($args){
			$this->update_args($args);

			$data_type	= $this->data_type;

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
				add_action($admin_hook,	fn()=> wpjam_call([$args['model'], $admin_hook]));
			}

			$args	+= [
				'layout'	=> 'table',
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
		$tag		= wpjam_tag('h1', ['wp-heading-inline'], ($this->page_title ?? $this->title))->after('hr', ['wp-header-end']);
		$summary	= maybe_callback($this->summary, ...$this->cb_args);
		$summary	= !$summary || is_array($summary) ? '' : (is_file($summary) ? wpjam_get_file_summary($summary) : $summary);

		if($summary){
			$tag->after('p', ['summary'], $summary);
		}

		if($this->is_tab){
			$tag->after(wpjam_ob_get_contents(wpjam_get_filter_name($this->name, 'page')) ?: '');	// 所有 Tab 页面都执行的函数

			if(count($this->tabs) > 1){
				$tag->after(wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix'])->append(array_map(fn($tab)=> ['a', ['class'=>['nav-tab', $GLOBALS['current_tab'] == $tab->name ? 'nav-tab-active' : ''], 'href'=>$tab->admin_url], ($tab->tab_title ?: $tab->title)], $this->tabs)));
			}
		}

		if($this->render){
			$tag->after(call_user_func($this->render, $this));
		}

		if($this->tab_page){
			return $tag->tag('h2');
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

		$tabs	= apply_filters(wpjam_get_filter_name($this->name, 'tabs'), maybe_callback($tabs, $this->name));
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

			$object->capability	??= $this->capability;
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
		}

		if($tab && $this->is_tab){
			$object	= wpjam_catch(fn()=> $this->get_tab());

			if(is_wp_error($object)){
				return null;
			}
		}else{
			$object	= $this;
		}

		return $key ? ($object->$key ?: ($default ?? null)) : $object->to_array();
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

			wpjam_map($options, fn($object)=> wpjam_if_error(wpjam_catch([$object, 'callback'], $args[0]), 'die'));
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

		wpjam_map(wpjam_get_term_options($taxonomy, ['action'=>$action, 'list_table'=>false]), fn($object)=> wpjam_if_error(wpjam_catch([$object, $method], ...$args), 'die'));

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

		WPJAM_Admin::load('builtin_page', $screen);

		if(in_array($base, ['edit', 'upload'])){
			if($base == 'upload'){
				$mode	= get_user_option('media_library_mode', get_current_user_id()) ?: 'grid';
				$mode	= (isset($_GET['mode']) && in_array($_GET['mode'], ['grid', 'list'], true)) ? $_GET['mode'] : $mode;

				if($mode == 'grid'){
					return;
				}
			}

			WPJAM_Builtin_List_Table::load([
				'title'			=> $object->title,
				'model'			=> $object->model,
				'hierarchical'	=> $object->hierarchical,
				'capability'	=> fn($id)=> $id ? 'edit_post' : $object->cap->edit_posts,
				'primary_key'	=> 'ID',
				'singular'		=> 'post',
				'data_type'		=> 'post_type',
				'meta_type'		=> 'post',
				'post_type'		=> $typenow,
			]);
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

				WPJAM_Builtin_List_Table::load([
					'title'			=> $object->title,
					'model'			=> $object->model,
					'hierarchical'	=> $object->hierarchical,
					'levels'		=> $object->levels,
					'sortable'		=> $object->sortable,
					'capability'	=> $object->cap->edit_terms,
					'primary_key'	=> 'term_id',
					'singular'		=> 'tag',
					'data_type'		=> 'taxonomy',
					'meta_type'		=> 'term',
					'taxonomy'		=> $taxnow,
					'post_type'		=> $typenow,
				]);
			}else{
				add_action($taxnow.'_edit_form_fields',	fn($term)=> self::call_term_options('render', $term));
			}
		}elseif($base == 'users'){
			WPJAM_Builtin_List_Table::load([
				'title'			=> '用户',
				'model'			=> 'WPJAM_User',
				'capability'	=> 'edit_user',
				'primary_key'	=> 'ID',
				'singular'		=> 'user',
				'data_type'		=> 'user',
				'meta_type'		=> 'user',
			]);
		}
	}

	public static function load($screen){
		return new static($screen);
	}
}

class WPJAM_Chart extends WPJAM_Args{
	public function get_parameter($key, $args=[]){
		if(str_contains($key, 'timestamp')){
			return wpjam_strtotime($this->get_parameter(str_replace('timestamp', 'date', $key), $args).' '.(str_starts_with($key, 'end_') ? '23:59:59' : '00:00:00'));
		}

		$data	= $args['data'] ?? null;
		$method	= $args['method'] ?? $this->method;
		$value	= (is_array($data) && !empty($data[$key])) ? $data[$key] : wpjam_get_parameter($key, ['method'=>$method]);

		if($value){
			wpjam_set_cookie($key, $value, HOUR_IN_SECONDS);

			return $value;
		}

		if(!empty($_COOKIE[$key])){
			return $_COOKIE[$key];
		}

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

	public function get_fields($args=[]){
		if($this->show_start_date){
			$fields['date']	= ['sep'=>' ',	'fields'=>[
				'start_date'	=> ['type'=>'date',	'value'=>$this->get_parameter('start_date', $args)],
				'date_view'		=> ['type'=>'view',	'value'=>'-'],
				'end_date'		=> ['type'=>'date',	'value'=>$this->get_parameter('end_date', $args)]
			]];
		}elseif($this->show_date){
			$fields['date']	= ['sep'=>' ',	'fields'=>[
				'prev_day'	=> ['type'=>'button',	'value'=>'‹',	'class'=>'button prev-day'],
				'date'		=> ['type'=>'date',		'value'=>$this->get_parameter('date', $args)],
				'next_day'	=> ['type'=>'button',	'value'=>'›',	'class'=>'button next-day']
			]];
		}

		if(isset($fields['date']) && !empty($args['show_title'])){
			$fields['date']['title']	= '日期';
		}

		if($this->show_date_type){
			$fields['date_format']	= ['type'=>'select','value'=>$this->get_parameter('date_format', $args), 'options'=>[
				'%Y-%m'				=> '按月',
				'%Y-%m-%d'			=> '按天',
				// '%Y%U'			=> '按周',
				'%Y-%m-%d %H:00'	=> '按小时',
				'%Y-%m-%d %H:%i'	=> '按分钟',
			]];
		}

		return $fields;
	}

	public function get_data($args=[]){
		$keys	= $this->show_start_date ? ['start_date', 'end_date'] : ($this->show_date ? ['date'] : []);

		return wpjam_fill($keys, fn($k)=> $this->get_parameter($k, $args));
	}

	public function render($wrap=true){
		if(!$this->show_form){
			return;
		}

		$fields	= $this->get_fields(['show_title'=>$this->show_compare]);

		if($this->show_compare){
			$current	= wpjam_get_parameter('type', ['default'=>-1]);
			$current	= $current == 'all' ? '-1' : $current;

			if($current !=-1 && $this->show_start_date){
				$fields['compare_date']	= ['before'=>'对比：',	'sep'=>' ',	'fields'=>[
					'start_date_2'	=> ['type'=>'date',	'value'=>$this->get_parameter('start_date_2')],
					'sep_view_2'	=> ['type'=>'view',	'value'=>'-'],
					'end_date_2'	=> ['type'=>'date',	'value'=>$this->get_parameter('end_date_2')],
					'compare'		=> ['type'=>'checkbox',	'value'=>$this->get_parameter('compare')],
				]];
			}
		}

		if($fields){
			$fields	= apply_filters('wpjam_chart_fields', $fields);
			$fields	+= $wrap ? ['chart_button'=>['type'=>'submit', 'value'=>'显示', 'class'=>'button button-secondary']] : [];
			$fields	= wpjam_fields($fields)->render(['fields_type'=>'']);

			if($wrap){
				$action	= $GLOBALS['current_admin_url'];
				$action	.= ($this->show_compare && $current != -1) ? '&type='.$current : '';

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
			wpjam_tag('div', ['id'=>$args['chart_id']])->data(['chart'=>true, 'type'=>$type, 'options'=>['data'=>$data, 'xkey'=>$args['day_key'], 'ykeys'=>array_keys($labels), 'labels'=>array_values($labels)]])->append_to($tag);
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
			$tag->append('div', ['id'=>$args['chart_id'], 'data'=>['chart'=>true, 'type'=>'Donut', 'options'=>['data'=>$data ?? []]]]);
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