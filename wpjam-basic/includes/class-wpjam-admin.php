<?php
class WPJAM_Admin extends WPJAM_Args{
	public function call($key, ...$args){
		if(method_exists($this, $key)){
			return $this->$key(...$args);
		}

		$value	= $this->get_arg($key);

		if(!$args){
			return $value ?? $this->get_arg('vars['.$key.']');
		}
	
		if(is_object($value)){
			return count($args) >= 2 ? ($value->{$args[0]} = $args[1]) : $value->{$args[0]};
		}

		$value	= $args[0];

		if(in_array($key, ['script', 'style'])){
			$key	.= '[]';
			$value	= is_array($value) ? implode("\n", $value) : $value;
		}elseif($key == 'pages[]'){
			$slug	= wpjam_pull($value, 'menu_slug');
			$parent	= wpjam_pull($value, 'parent');
			$key	= 'pages['.($parent ?: $slug).']';
			$subs	= array_merge($this->get_arg($key.'[subs]', []), $parent ? [$slug=>$value] : $value['subs'] ?? []);
			$value	= array_merge($this->get_arg($key, []), ($parent ? [] : $value), ['subs'=>$subs]);
		}elseif($key == 'query_data'){
			$value	= array_merge($this->get_arg($key, []), array_map(fn($v)=> is_null($v) ? $v : (is_array($v) ? wp_die('query_data 不能为数组') : sanitize_textarea_field($v)), $value));
		}

		is_null($value) ? $this->delete_arg($key) : $this->update_arg($key, $value);

		return $value;
	}

	public function prefix(){
		return is_network_admin() ? 'network_' : (is_user_admin() ? 'user_' : '');
	}

	public function url($path=''){
		return ($this->prefix().'admin_url')($path);
	}

	public function chart($method, ...$args){
		if(is_object($method)){
			return $this->chart	= $method;
		}

		return $this->chart ? $this->chart->$method(...$args) : null;
	}

	public function enqueue(){
		$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
		$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';

		wp_enqueue_media($this->screen->base == 'post' ? ['post'=>wpjam_get_admin_post_id()] : []);
		wp_enqueue_style('wpjam-style', $static.'/style.css', ['thickbox', 'wp-color-picker', 'editor-buttons'], $ver);
		wp_enqueue_script('wpjam-script', $static.'/script.js', ['jquery', 'thickbox', 'wp-color-picker', 'jquery-ui-sortable', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-autocomplete'], $ver);
		wp_enqueue_script('wpjam-form', $static.'/form.js', ['wpjam-script'], $ver);
		wp_localize_script('wpjam-script', 'wpjam_page_setting', array_map('maybe_closure', $this->vars)+['admin_url'=>$GLOBALS['current_admin_url']]+wpjam_pick($this, ['query_data', 'query_url']));

		$this->style	&& wp_add_inline_style('wpjam-style', "\n".implode("\n\n", array_filter($this->style)));
		$this->script	&& wp_add_inline_script('wpjam-script', "jQuery(function($){".preg_replace('/^/m', "\t", "\n".implode("\n\n", $this->script))."\n});");
	}

	public function notices(){
		WPJAM_Notice::render();

		wpjam_map($this->get_arg('error[]'), fn($e)=> wpjam_echo(wpjam_tag('div', ['is-dismissible', 'notice', 'notice-'.$e['type']], ['p', [], $e['msg']])));
	}

	public function load($screen=''){
		if($screen){
			$this->screen	= $screen;
			$this->vars		= ['screen_id'=>$screen->id]+array_filter(wpjam_pick($screen, ['post_type', 'taxonomy']));
		}

		if($this->plugin_page){
			$type	= 'plugin_page';
			$object	= $this->current_tab ?: $this->plugin_page;
			$args	= [$object->menu_slug, ''];

			$this->vars	+= ['plugin_page'=>$args[0]];

			if($this->current_tab){
				$args[1]	= $object->tab_slug;
				$this->vars	+= ['current_tab'=>$args[1]];
			}else{
				if($screen && str_contains($screen->id, '%')){
					$parts		= explode('_page_', $screen->id);
					$screen->id	= implode('_page_', wpjam_set($parts, 0, array_search($parts[0], $GLOBALS['admin_page_hooks']) ?: $parts[0]));
				}
			}
		}else{
			if($screen->base == 'customize' || !empty($GLOBALS['plugin_page'])){
				return;
			}

			$type	= 'builtin_page';
			$args	= [$screen];
			$page	= $this->$type = wpjam_get_post_parameter($type) ?: $GLOBALS['pagenow'];
			$url	= add_query_arg(array_intersect_key($_REQUEST, wpjam_pick($this->vars, ['taxonomy', 'post_type'])), $this->url($page));

			$GLOBALS['current_admin_url']	= $url;

			$this->vars	+= [$type=>$page];

			if(in_array($screen->base, ['edit', 'upload', 'post'])){
				if(!($this->type_object = wpjam_get_post_type_object($GLOBALS['typenow']))){
					return;
				}
			}elseif(in_array($screen->base, ['term', 'edit-tags'])){
				if(!($this->tax_object = wpjam_get_taxonomy_object($GLOBALS['taxnow']))){
					return;
				}
			}
		}

		do_action('wpjam_'.$type.'_load', ...$args);	// 兼容

		foreach(wpjam_sort(array_filter($this->get_arg($type.'_load[]'), function($load){
			if($this->plugin_page){
				$page	= $this->plugin_page->name;
				$tab	= ($tab = $this->current_tab) ? $tab->name : '';

				if(!empty($load['plugin_page'])){
					if(is_callable($load['plugin_page'])){
						return $load['plugin_page']($page, $tab);
					}

					if(!wpjam_compare($page, $load['plugin_page'])){
						return false;
					}
				}

				return empty($load['current_tab']) ? !$tab : ($tab && wpjam_compare($tab, $load['current_tab']));
			}else{
				if(!empty($load['screen']) && is_callable($load['screen']) && !$load['screen']($this->screen)){
					return false;
				}

				if(array_any(['base', 'post_type', 'taxonomy'], fn($k)=> !empty($load[$k]) && !wpjam_compare($this->screen->$k, $load[$k]))){
					return false;
				}

				return true;
			}
		}), 'order', 'desc', 10) as $load){
			!empty($load['page_file']) && wpjam_map((array)$load['page_file'], fn($file)=> is_file($file) && include $file);

			$cb	= $load['callback'] ?? '';
			$cb	= $cb ?: (($model = $load['model'] ?? '') ? array_find([[$model, 'load'], [$model, $type.'_load']], fn($cb)=> method_exists(...$cb)) : '');

			wpjam_call($cb, ...$args);
		}

		$cb	= $this->plugin_page ? [$object, 'load'] : ['WPJAM_Builtin_Page', 'load'];

		wpjam_if_error(wpjam_catch($cb, $screen), fn($e)=> wpjam_add_admin_error($e));

		add_action('admin_enqueue_scripts',	[$this, 'enqueue'], 9);
		add_action('all_admin_notices',		[$this, 'notices'], 9);
	}

	public function init(){
		if($GLOBALS['pagenow'] == 'admin-post.php'){
			return;
		}

		if(wp_doing_ajax()){
			$screen	= $_POST['screen_id'] ?? ($_POST['screen'] ?? '');

			if(!$screen){
				return;
			}

			wpjam_add_admin_ajax('wpjam-page-action', [
				'nonce_action'	=> fn($data)=> $data['page_action'] ?? '',
				'callback'		=> fn($data)=> ($object	= WPJAM_Page_Action::get($data['page_action'] ?? '')) ? $object->callback($data['action_type']) : wpjam_page_action_compat($data),
			]);

			wpjam_add_admin_ajax('wpjam-query', [
				'fields'	=> ['data_type'=> ['required'=>true]],
				'callback'	=> fn($data)=> ['items'=>($object = WPJAM_Data_Type::get_instance($data['data_type'], $data['query_args'])) ? wpjam_if_error($object->query_items($data['query_args']), 'throw') : []]
			]);

			wpjam_add_admin_ajax('wpjam-upload', [
				'nonce_action'	=> fn($data)=> 'upload-'.$data['name'],
				'callback'		=> fn($data)=> wpjam_upload($data['name'], ['mimes'=>$data['mimes']])
			]);

			$page	= $GLOBALS['plugin_page'] = $_POST['plugin_page'] ?? '';
			$type	= $page ? trim(explode('_page_'.$page, $screen)[1], '-') : array_find(['network', 'user'], fn($v)=> str_ends_with($screen, '-'.$v));
			$const	= $type ? 'WP_'.strtoupper($type).'_ADMIN' : '';

			$const && !defined($const) && define($const, true);

			$screen == 'upload' && ([$GLOBALS['hook_suffix'], $screen]	= [$screen, '']);
		}

		do_action('wpjam_admin_init');

		add_action('current_screen', [$this, 'load'], 9);

		$menu	= new WPJAM_Plugin_Page();
		$pages	= apply_filters('wpjam_pages', $this->pages ?: []);

		if(wp_doing_ajax()){
			$page && ($slug = array_find_key($pages, fn($v, $k)=> $page == $k || isset($v['subs'][$page]))) && $menu->parse($pages[$slug], $page, $slug);

			set_current_screen($screen);
		}else{
			wpjam_map($pages, [$menu, 'parse']);
		}
	}

	public static function get_instance(){
		static $object;

		if(!isset($object)){
			$object	= new self();

			WPJAM_Notice::init();

			if(wp_doing_ajax()){
				add_action('admin_init', [$object, 'init'], 9);
			}else{
				add_action($object->prefix().'admin_menu',	[$object, 'init'], 9);

				add_filter('wpjam_html', fn($html)=> str_replace('dashicons-before dashicons-ri-', 'ri-', $html));
			}
		}

		return $object;
	}
}

class WPJAM_Page_Action extends WPJAM_Args{
	public function is_allowed($type=''){
		return wpjam_current_user_can(($this->capability ?? ($type ? 'manage_options' : '')), $this->name);
	}

	public function callback($type=''){
		if($type == 'form'){
			$title	= wpjam_get_post_parameter('page_title');
			$key	= $title ? '' : array_find(['page_title', 'button_text', 'submit_text'], fn($k)=> $this->$k && !is_array($this->$k));
			$title	= $key ? $this->$key : $title;

			return [
				'type'	=> 'form',
				'form'	=> $this->get_form(),
				'width'	=> (int)$this->width,

				'modal_id'		=> $this->modal_id,
				'page_title'	=> $title
			];
		}

		$this->is_allowed($type) || wp_die('access_denied');

		$response	= $this->response ?? $this->name;
		$callback	= $this->callback;
		$args		= [$this->name];

		if($type == 'submit'){
			$submit		= $args[] = wpjam_get_post_parameter('submit_name') ?: $this->name;
			$button		= $this->get_submit_button($submit);
			$callback	= $button['callback'] ?? $callback;
			$response	= $button['response'] ?? $response;
		}

		(!$callback || !is_callable($callback)) && wp_die('无效的回调函数');

		$result	= wpjam_try($callback, ...($this->validate ? [$this->get_fields()->get_parameter('data'), ...$args] : $args));
		$result	= is_null($result) ? wp_die('回调函数没有正确返回') : (is_array($result) ? $result : (is_string($result) ? [($response == 'redirect' ? 'url' : 'data') => $result] : []));

		$response	= array_merge(['type'=>$response], $result)+($this->dismiss ? ['dismiss'=>true] : []);
		$response	+= $response['type'] == 'redirect' ? ['target'=>$this->target ?: '_self'] : [];

		return apply_filters('wpjam_ajax_response', $response);
	}

	public function render(){
		return wpjam_if_error(wpjam_catch([$this, 'get_form']), 'die');
	}

	public function get_submit_button($name=null){
		$button = maybe_callback($this->submit_text, $this->name) ?? wp_strip_all_tags($this->page_title);
		$button	= is_array($button) ? $button : [$this->name => $button];

		return WPJAM_AJAX::parse_submit_button($button, $name);
	}

	public function get_button($args=[]){
		if($this->is_allowed()){
			$data	= wpjam_pull($args, 'data') ?: [];
			$text	= $this->update_args($args)->button_text ?? '保存';

			return wpjam_tag(($this->tag ?: 'a'), [
				'title'	=> $this->page_title ?: $text,
				'style'	=> $this->style,
				'class'	=> $this->class ?? 'button-primary large',
				'data'	=> $this->generate_data_attr(['data'=>$data])
			], $text)->add_class('wpjam-button');
		}
	}

	public function get_form(){
		if($this->is_allowed()){
			return $this->get_fields()->render()->wrap('form', [
				'novalidate',
				'method'	=> 'post',
				'action'	=> '#',
				'id'		=> $this->form_id ?: 'wpjam_form',
				'data'		=> $this->generate_data_attr([], 'form')
			])->append($this->get_submit_button());
		}
	}

	protected function get_fields(){
		$fields	= wpjam_try(fn()=> maybe_callback($this->fields, $this->name)) ?: [];
		$data	= wpjam_if_error(wpjam_call($this->data_callback, $this->name, $fields), 'throw') ?: [];

		return WPJAM_Fields::create($fields, wpjam_merge($this->args, ['data'=>$data]));
	}

	public function generate_data_attr($args=[], $type='button'){
		return [
			'action'	=> $this->name,
			'nonce'		=> wp_create_nonce($this->name)
		] + ($type == 'button' ? wpjam_pick($this, ['direct', 'confirm'])+[
			'title'		=> $this->page_title ?: $this->button_text,
			'data'		=> wp_parse_args(($args['data'] ?? []), ($this->data ?: [])),
		] : []);
	}

	public static function get($name){
		return $name ? wpjam_admin('page_actions['.$name.']') : null;
	}

	public static function create($name, $args){
		return wpjam_admin('page_actions['.$name.']', new static(['name'=>$name]+$args));
	}
}

class WPJAM_Dashboard extends WPJAM_Args{
	public function page_load(){
		if($this->name != 'dashboard'){
			require_once ABSPATH.'wp-admin/includes/dashboard.php';
			// wp_dashboard_setup();

			wp_enqueue_script('dashboard');

			wp_is_mobile() && wp_enqueue_script('jquery-touch-punch');
		}

		$widgets	= maybe_callback($this->widgets, $this->name) ?: [];
		$widgets	= array_merge($widgets, array_filter(wpjam_admin('widgets[]'), [$this, 'is_available']));

		foreach($widgets as $id => $widget){
			$id	= $widget['id'] ?? $id;

			add_meta_box(
				$id,
				$widget['title'],
				$widget['callback'] ?? wpjam_get_filter_name($id, 'dashboard_widget_callback'),
				get_current_screen(),
				$widget['context'] ?? 'normal',	// 位置，normal：左  side：右
				$widget['priority'] ?? 'core',
				$widget['args'] ?? []
			);
		}
	}

	public function render(){
		$panel	= wpjam_ob_get_contents($this->welcome_panel, $this->name);
		$panel	= $panel ? wpjam_tag('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], $panel) : '';

		return wpjam_tag('div', ['id'=>'dashboard-widgets-wrap'], wpjam_ob_get_contents('wp_dashboard'))->before($panel);
	}

	private function is_available($widget){
		return ($widget['dashboard'] ?? 'dashboard') == $this->name;
	}

	public static function add_widget($name, $args){
		wpjam_admin('widgets['.$name.']', $args);
	}
}

class WPJAM_Plugin_Page extends WPJAM_Args{
	private $builtins = [];

	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'name'){
			return $this->tab_slug ?: $this->menu_slug;
		}elseif($key == 'type'){
			return ($value	??= $this->function) == 'list' ? 'list_table' : (in_array($value, ['option', 'list_table', 'form', 'dashboard', 'tab']) ? $value : '');
		}

		return $value;
	}

	public function get_sub($slug){
		return $this->pull('subs['.$slug.']') ?: array_filter(['menu_title'=>$this->sub_title])+wpjam_except($this->args, ['position', 'subs', 'page_title']);
	}

	public function parse_subs($parent=null){
		$slug	= $this->menu_slug;
		$subs	= ($parent ? [] : [$slug=>$this->get_sub($slug)])+wpjam_sort($this->subs, fn($v)=> ($v['order'] ?? 10) - ($v['position'] ?? 10)*1000);

		wpjam_map($subs, fn($sub, $s)=> $this->parse($sub, $s, $parent ?: $slug));
	}

	public function parse($args, $slug, $parent=''){
		if(wp_doing_ajax()){
			$this->args	= $args;

			if($this->subs){
				$args	= $this->get_sub($slug);
			}else{
				$parent	= '';
			}
		}elseif(!$this->builtins){
			$builtins	= wpjam_array($GLOBALS['admin_page_hooks'], fn($k, $v)=> [(str_starts_with($k, 'edit.php?') && $v != 'pages') ? wpjam_get_post_type_setting($v, 'plural') : $v, $k]);

			$builtins	+= wpjam_array(['themes'=>'appearance', 'options'=>'settings', 'users'=>'profile'], fn($k, $v)=> [$k, $builtins[$v] ?? null], true);

			$this->builtins	= $builtins;
		}

		$this->args	= $args+['menu_slug'=>$slug];

		if(!$parent && ($v	= $this->builtins[$slug] ?? '')){
			return $this->parse_subs($v);
		}

		$path	= str_contains($parent, '.php') ? $parent : 'admin.php';

		if(!$this->menu_title || !$this->is_available(['network'=>$this->pull('network', ($path == 'admin.php'))])){
			return;
		}

		$this->page_title	??= $this->menu_title;
		$this->capability	??= 'manage_options';

		if(!str_contains($slug, '.php')){
			$this->admin_url = add_query_arg(['page'=>$slug], $path);

			if(!$this->query_data()){
				return;
			}
		}

		$object	= ($this->is_current() && ($parent || (!$parent && !$this->subs))) ? wpjam_admin('plugin_page', wp_clone($this)) : null;

		if(str_contains($slug, '.php')){
			if($GLOBALS['pagenow'] == explode('?', $slug)[0]){
				$query	= wp_parse_args(parse_url($slug, PHP_URL_QUERY));

				(!$query || array_all($query, fn($v, $k)=> $v == wpjam_get_parameter($k))) && add_filter('parent_file', fn()=> $parent ?: $slug);
			}
		}else{
			$callback	= $object ? [$object, 'render'] : '__return_true';
		}

		$args	= [$this->page_title, $this->menu_title, $this->capability, $slug, ($callback ?? null), $this->position];
		$icon	= $parent ? '' : ($this->icon ? (str_starts_with($this->icon, 'dashicons-') ? '' : 'dashicons-').$this->icon : '');
		$hook	= $parent ? add_submenu_page($parent, ...$args) : add_menu_page(...wpjam_add_at($args, -1, $icon));

		$object && wpjam_admin('page_hook', $hook);

		$this->subs && !$parent && $this->parse_subs();
	}

	private function is_available($args){
		return array_all([
			'network'		=> fn($v)=> is_network_admin() ? (bool)$v : $v !== 'only',
			'capability'	=> fn($v)=> current_user_can($v),
			'plugin_page'	=> fn($v)=> $v == $this->menu_slug
		], fn($cb, $k)=> isset($args[$k]) ? $cb($args[$k]) : true);
	}

	public function is_current(){
		return ($this->tab_slug ? $GLOBALS['current_tab'] : $GLOBALS['plugin_page']) == $this->name;
	}

	public function query_data(){
		if($this->query_args){
			$query_data	= wpjam_get_data_parameter($this->query_args);
			$null_data	= array_filter($query_data, fn($v)=> is_null($v));

			if($null_data){
				return $this->is_current() ? wp_die('「'.implode('」,「', array_keys($null_data)).'」参数无法获取') : false;
			}

			wpjam_admin('query_url[]', [$this->admin_url, ($this->admin_url = add_query_arg($query_data, $this->admin_url))]);

			$this->is_current() && wpjam_admin('query_data', $query_data);
		}

		return true;
	}

	private function throw($title){
		wpjam_throw('error', $title);
	}

	private function include(){
		if($cb = $this->pull('load_callback')){	// load_callback 优先于文件加载执行，如果不存在，尝试先加载文件
			!is_callable($cb) && $this->include();

			wpjam_call($cb, $this->name);
		}

		wpjam_map((array)$this->pull(($this->tab_slug ? 'tab' : 'page').'_file'), fn($f)=> include($f));
	}

	private function defaults($defaults=null){
		($defaults	??= $this->defaults) && is_array($defaults) && wpjam_default($defaults);
	}

	public function load(){
		$this->defaults();
		$this->include();

		$type	= $this->type;

		do_action('wpjam_plugin_page', $this, $type);

		if($type && $type != 'tab'){
			$name	= $this->{$type.'_name'} ?: $this->menu_slug;
			$object	= $this->preprocess($type, $name);
		}

		if($this->data_type){
			$data_type	= wpjam_admin('data_type', $this->data_type);
			$dt_object	= wpjam_get_data_type_object($data_type, $this->args);

			$dt_object && $dt_object->meta_type && wpjam_admin('meta_type', $dt_object->meta_type);

			in_array($data_type, ['post_type', 'taxonomy']) && $this->$data_type && !wpjam_admin('screen', $data_type) && wpjam_admin('screen', $data_type, $this->$data_type);
		}

		$this->chart	&& wpjam_admin('chart', WPJAM_Chart::get_instance($this->chart));
		$this->editor	&& add_action('admin_footer', 'wp_enqueue_editor');

		$GLOBALS['current_admin_url']	= wpjam_admin('url', $this->admin_url);

		if($type == 'tab'){
			$GLOBALS['current_tab']	= wpjam_get_parameter(...(wp_doing_ajax() ? ['current_tab', [], 'POST'] : ['tab'])) ?: null;

			$tabs	= wpjam_array(wpjam_admin('tabs[]'), fn($k, $v)=> $this->is_available($v) ? [$v['tab_slug'], $v] : null);
			$tabs	= wpjam_array(wpjam_sort($this->get_arg('tabs', [], 'callback')+$tabs, 'order', 'desc', 10), function($slug, $tab){
				$tab	= new self(['tab_slug'=>$slug, 'admin_url'=>$this->admin_url.'&tab='.$slug]+$tab+['capability'=>$this->capability]);

				if($tab->query_data()){
					$GLOBALS['current_tab'] ??= $slug;

					return [$slug, $tab];
				}
			});

			$object	= $tabs ? ($tabs[$GLOBALS['current_tab']] ?? null) : $this->throw('Tabs 未设置');

			$object || $this->throw('无效的 Tab');
			$object->function || $this->throw('Tab 未设置 function');
			$object->function == 'tab' && $this->throw('Tab 不能嵌套 Tab');

			$object->menu_slug	= $this->menu_slug;

			if(!wp_doing_ajax()){
				$this->tabs		= $tabs;
				$this->render	= [$object, 'render'];
			}

			wpjam_admin('current_tab', $object);
			wpjam_admin('load');
		}elseif($type){
			$object	??= $this->page_object($type, $name);
			$load	= [$object, 'page_load'];

			wp_doing_ajax() ? wpjam_call($load) : add_action('load-'.wpjam_admin('page_hook'), fn()=> wpjam_call($load));

			$this->render		= [$object, 'render'];
			$this->page_title	= $object->title ?: $this->page_title;
			$this->summary		= $this->summary ?: $object->get_arg('summary');

			$object->query_args && wpjam_admin('query_data', wpjam_get_data_parameter($object->query_args));
		}else{
			$function		= $this->function ?: wpjam_get_filter_name($this->name, 'page');
			$this->render	= is_callable($function) ? fn()=> wpjam_admin('chart', 'render').wpjam_ob_get_contents($function) : $this->throw('页面函数'.'「'.$function.'」未定义。');
		}
	}

	private function preprocess($type, $name){
		$class	= ['form'=>'WPJAM_Page_Action', 'option'=>'WPJAM_Option_Setting'][$type] ?? '';
		$object	= $class ? $class::get($name) : null;

		if($object){
			$object	= $type == 'option' ? $object->get_current() : $object;
			$args	= $object->to_array();
		}else{
			$args	= $this->$type;
			$model	= in_array($type, ['list_table', 'form']) ? ($args ? (is_string($args) ? $args : '') : $this->model) : '';
			$cb		= $model && class_exists($model) ? [$model, 'get_'.$type] : '';
			$args	= $cb && method_exists(...$cb) ? $cb($this) : $args;
			$args	= wpjam_is_assoc_array($args) ? $args+wpjam_pick($this, ['model']) : $args;

			$args && ($args	= $this->$type = maybe_callback($args, $this));
		}

		if(is_array($args)){
			!empty($args['meta_type']) && wpjam_admin('meta_type', $args['meta_type']);

			$this->update_args(WPJAM_Data_Type::prepare($args));
		}

		return $object;
	}

	private function page_object($type, $name){
		$args	= $this->$type;

		if($type == 'form'){
			$args	= $args ?: ($this->callback ? $this->to_array() : []);
			$args	= $args ?: $this->throw('Page Action'.'「'.$name.'」未定义。');

			return WPJAM_Page_Action::create($name, $args);
		}elseif($type == 'option'){
			$args	= $args ?: (($this->sections || $this->fields) ? $this->to_array() : []);
			$args	= $args ?: $this->throw('Option'.'「'.$name.'」未定义。');

			return (WPJAM_Option_Setting::create($name, $args))->get_current();
		}elseif($type == 'dashboard'){
			$args	= $args ?: ($this->widgets ? $this->to_array() : []);
			$args	= $args ?: $this->throw('Dashboard'.'「'.$name.'」未定义。');

			return new WPJAM_Dashboard(array_merge($args, ['name'=>$name]));
		}elseif($type == 'list_table'){
			$args	= $args ?: ($this->model ? wpjam_except($this->to_array(), 'defaults') : []);
			$args	= $args ?: $this->throw('List Table'.'「'.$name.'」未定义。');
			$model	= $args['model'] ?? '';

			isset($args['defaults']) && $this->defaults($args['defaults']);

			(empty($model) || (!is_object($model) && !class_exists($model))) && $this->throw('List Table Model'.'「'.$model.'」未定义。');

			wpjam_map(['admin_head', 'admin_footer'], fn($v)=> method_exists($model, $v) && add_action($v, [$model, $v]));

			return new WPJAM_List_Table($args+array_filter([
				'layout'	=> 'table',
				'name'		=> $name,
				'singular'	=> $name,
				'plural'	=> $name.'s',
				'capability'=> $this->capability ?: 'manage_options',
				'sortable'	=> $this->sortable,
				'data_type'	=> 'model',
				'per_page'	=> 50
			]));
		}
	}

	public function render(){
		$tag		= wpjam_tag('h1', ['wp-heading-inline'], ($this->page_title ?? $this->title))->after('hr', ['wp-header-end']);
		$summary	= maybe_callback($this->summary, $this->menu_slug, $this->tab_slug ?: '');
		$summary	= !$summary || is_array($summary) ? '' : (is_file($summary) ? wpjam_get_file_summary($summary) : $summary);

		$summary && $tag->after('p', ['summary'], $summary);

		if($this->type == 'tab'){
			$tag->after(wpjam_ob_get_contents(wpjam_get_filter_name($this->menu_slug, 'page')) ?: '');	// 所有 Tab 页面都执行的函数

			count($this->tabs) > 1 && $tag->after(wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix'])->append(array_map(fn($tab)=> ['a', ['class'=>['nav-tab', $tab->is_current() ? 'nav-tab-active' : ''], 'href'=>$tab->admin_url], ($tab->tab_title ?: $tab->title)], $this->tabs)));
		}

		$tag->after(call_user_func($this->render, $this));

		return $this->tab_slug ? $tag->tag('h2') : wpjam_echo($tag->wrap('div', ['wrap']));
	}
}

class WPJAM_Builtin_Page{
	public static function on_edit_form($post){
		$meta_boxes	= $GLOBALS['wp_meta_boxes'][$post->post_type]['wpjam'] ?? [];

		foreach(wp_array_slice_assoc($meta_boxes, ['high', 'core', 'default', 'low']) as $boxes){
			foreach((array)$boxes as $box){
				if(!empty($box['id']) && !empty($box['title'])){
					$title[]	= ['a', ['class'=>'nav-tab', 'href'=>'#tab_'.$box['id']], $box['title']];
					$content[]	= ['div', ['id'=>'tab_'.$box['id']], wpjam_ob_get_contents($box['callback'], $post, $box)];
				}
			}
		}

		if(isset($title)){
			if(count($title) == 1){
				$title	= wpjam_tag('h2', ['hndle'], $title[0][2])->wrap('div', ['postbox-header']);
			}else{
				$title	= wpjam_tag('ul')->append(array_map(fn($v)=> wpjam_tag(...$v)->wrap('li'), $title))->wrap('h2', ['nav-tab-wrapper']);
			}

			echo wpjam_tag('div', ['inside'])->append($content)->before($title)->wrap('div', ['id'=>'wpjam', 'class'=>['postbox', 'tabs']])->wrap('div', ['id'=>'wpjam-sortables']);
		}
	}

	public static function call_post_options($method, $post_id, $post_type){
		if($method == 'callback'){	// 只有 POST 方法提交才处理，自动草稿、自动保存和预览情况下不处理
			if($_SERVER['REQUEST_METHOD'] != 'POST'
				|| get_post_status($post_id) == 'auto-draft'
				|| (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
				|| (!empty($_POST['wp-preview']) && $_POST['wp-preview'] == 'dopreview')
			){
				return;
			}
		}else{
			$context	= use_block_editor_for_post_type($post_type) ? 'normal' : 'wpjam';

			$context == 'wpjam' && add_action(($post_type == 'page' ? 'edit_page_form' : 'edit_form_advanced'), [self::class, 'on_edit_form'], 99);
		}

		foreach(wpjam_get_post_options($post_type, ['list_table'=>false]) as $object){
			if($object->meta_box_cb || ($object->fields	= $object->get_fields($post_id, ''))){
				if($method == 'callback'){
					wpjam_if_error(wpjam_catch([$object, 'callback'], $post_id), 'die');
				}else{
					add_meta_box($object->name, $object->title, [$object, 'render'], $post_type, ($object->context ?: $context), $object->priority);
				}
			}
		}
	}

	public static function call_term_options($method, $term, $tax='', $action='add'){
		if($method == 'render'){
			$args	= [($term ?: false), ['fields_type'=>($term ? 'tr' : 'div'), 'wrap_class'=>'form-field']];
		}elseif($method == 'callback'){
			$tax	= get_term_field('taxonomy', $term);
			$args	= [$term];
		}elseif($method == 'validate'){
			$args	= [];
		}

		foreach(wpjam_get_term_options($tax, ['action'=>$action, 'list_table'=>false]) as $object){
			wpjam_if_error(wpjam_catch([$object, $method], ...$args), 'die');
		}

		if($method == 'validate'){
			return $term;
		}
	}

	public static function load($screen){
		$base		= $screen->base;
		$typenow	= $GLOBALS['typenow'];
		$taxnow		= $GLOBALS['taxnow'];

		if(in_array($base, ['edit', 'upload'])){
			if($base == 'upload' && (wpjam_get_parameter('mode') ?: get_user_option('media_library_mode', get_current_user_id())) != 'list'){
				return;
			}

			$object	= wpjam_admin('type_object');

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
			$object		= wpjam_admin('type_object');
			$label		= in_array($typenow, ['post', 'page', 'attachment']) ? '' : $object->labels->name;
			$size		= $object->thumbnail_size;
			$fragment	= parse_url(wp_get_referer(), PHP_URL_FRAGMENT);

			$label		&& add_filter('post_updated_messages', fn($ms)=> $ms+[$typenow=> array_map(fn($m)=> str_replace('文章', $label, $m), $ms['post'])]);
			$fragment	&& add_filter('redirect_post_location', fn($location)=> $location.(parse_url($location, PHP_URL_FRAGMENT) ? '' : '#'.$fragment));
			$size		&& add_filter('admin_post_thumbnail_html', fn($content)=> $content.wpautop('尺寸：'.$size));

			add_action('add_meta_boxes', fn($post_type, $post)=> self::call_post_options('render', $post->ID, $post_type), 10, 2);
			add_action('wp_after_insert_post', fn($post_id)=> self::call_post_options('callback', $post_id, get_post_type($post_id)), 999, 2);
		}elseif(in_array($base, ['term', 'edit-tags'])){
			$object	= wpjam_admin('tax_object');
			$label	= in_array($taxnow, ['post_tag', 'category']) ? '' : $object->labels->name;

			$label && add_filter('term_updated_messages', fn($ms)=> $ms+[$taxnow=> array_map(fn($m)=> str_replace(['项目', 'Item'], [$label, ucfirst($label)], $m), $ms['_item'])]);

			if($base == 'edit-tags'){
				wpjam_map(['slug', 'description'], fn($sup)=> $object->supports($sup) || wpjam_admin('removed_columns[]', $sup));

				wpjam_admin('removed_actions[]', 'inline hide-if-no-js');

				if(wp_doing_ajax()){
					if($_POST['action'] == 'add-tag'){
						add_filter('pre_insert_term',	fn($term, $tax)=> self::call_term_options('validate', $term, $tax), 10, 2);
						add_action('created_term',		fn($term_id)=> self::call_term_options('callback', $term_id));
					}
				}elseif(isset($_POST['action'])){
					if($_POST['action'] == 'editedtag'){
						add_action('edited_term',		fn($term_id)=> self::call_term_options('callback', $term_id, '', 'edit'));
					}
				}else{
					add_action($taxnow.'_add_form_fields',	fn($tax)=> self::call_term_options('render', null, $tax));
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
				add_action($taxnow.'_edit_form_fields',	fn($term, $tax)=> self::call_term_options('render', $term->term_id, $tax, 'edit'), 10, 2);
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

		$parser	= fn($item)=> empty($cbs) ? $item : array_merge($item, array_map(fn($cb)=> $cb($item), $cbs));
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

			$args['show_table'] && $tbody->append(self::row($day, $item, $args));
		}

		$tag	= wpjam_tag();

		$args['show_chart'] && $data && wpjam_tag('div', ['id'=>$args['chart_id']])->data(['chart'=>true, 'type'=>$type, 'options'=>['data'=>$data, 'xkey'=>$args['day_key'], 'ykeys'=>array_keys($labels), 'labels'=>array_values($labels)]])->append_to($tag);

		if($args['show_table'] && $args['data']){
			$total	= $parser($total);

			$args['show_sum'] && $tbody->append(self::row('sum', $total, $args));
			$args['show_avg'] && $tbody->append(self::row('avg', array_map(fn($v)=> is_numeric($v) ? round($v/count($args['data'])) : '', $total), $args));

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

			$args['show_table'] && $tbody->append(self::row($i+1, $value, ['label'=>$label]+$args));
		}

		$tag	= wpjam_tag();

		$args['show_chart'] && $tag->append('div', ['id'=>$args['chart_id'], 'data'=>['chart'=>true, 'type'=>'Donut', 'options'=>['data'=>$data ?? []]]]);

		if($args['show_table']){
			$args['total'] && $tbody->append(self::row('total', $args['total'], $args+['label'=>'所有']));

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
				$cell	= wpjam_tag(...($key == 'head' ? ['th', ['scope'=>'col', 'id'=>$col], $column] : ['td', ['data'=>['colname'=>$column]], $data[$col] ?? '']));

				$col == $day_key && $cell->add_class('column-primary')->append('button', ['class'=>'toggle-row']);
				$cell->add_class('column-'.$col)->append_to($row);
			}
		}else{
			$row->append($key == 'head' ? [
				$args['show_line_num'] ? ['th', ['style'=>'width:40px;'], '排名'] : '',
				['th', [], $args['title']],
				['th', [], '数量'],
				$args['total'] ? ['th', [], '比例'] : ''
			] : [
				$args['show_line_num'] ? ['td', [], $key == 'total' ? '' : $key] : '',
				['td', [], $args['label']],
				['td', [], $data],
				$args['total'] ? ['td', [], round($data / $args['total'] * 100, 2).'%'] : ''
			]);
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
		return wpjam_get_instance('chart_form', 'object', fn()=> self::create_instance())->update_args(is_array($args) ? $args : []);
	}
}