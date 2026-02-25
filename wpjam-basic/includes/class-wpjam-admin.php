<?php
class WPJAM_Admin extends WPJAM_Args{
	public function prefix(){
		return is_network_admin() ? 'network_' : (is_user_admin() ? 'user_' : '');
	}

	public function url($path=''){
		return ($this->prefix().'admin_url')($path);
	}

	public function error($msg='', $type='success'){
		if($msg === ''){
			return wpjam_map($this->error ?: [], fn($e)=> wpjam_echo(wpjam_tag('div', ['is-dismissible', 'notice', 'notice-'.$e['type']], ['p', [], $e['msg']])));	
		}

		if(is_wp_error($msg)){
			$msg	= $msg->get_error_message();
			$type	= 'error';
		}

		$msg && $type && $this->update_arg('error[]', compact('msg', 'type'));
	}

	public function enqueue(){
		$ver	= get_plugin_data(WPJAM_BASIC_PLUGIN_FILE)['Version'];
		$static	= wpjam_url(dirname(__DIR__), 'relative').'/static';

		wp_enqueue_media(wpjam_pick($this, ['post_id']));
		wp_enqueue_style('wpjam-style', $static.'/style.css', ['thickbox', 'wp-color-picker', 'editor-buttons'], $ver);
		wp_enqueue_script('wpjam-script', $static.'/script.js', ['jquery', 'thickbox', 'wp-color-picker', 'jquery-ui-sortable', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-autocomplete'], $ver);
		wp_enqueue_script('wpjam-form', $static.'/form.js', ['wpjam-script'], $ver);
		wp_localize_script('wpjam-script', 'wpjam_page_setting', array_map('maybe_closure', $this->vars)+['admin_url'=>$GLOBALS['current_admin_url']]+$this->pick(['query_data', 'query_url']));

		$this->style	&& wp_add_inline_style('wpjam-style', "\n".implode("\n\n", array_filter($this->style)));
		$this->script	&& wp_add_inline_script('wpjam-script', "jQuery(function($){".preg_replace('/^/m', "\t", "\n".implode("\n\n", $this->script))."\n});");
	}

	public function load(...$args){
		if(count($args) >= 2){
			$map	= ['builtin_page'=>'base', 'plugin_page'=>'plugin_page'];
			$type	= $args[0] ?? array_find_key($map, fn($v)=> isset($args[1][$v]));

			return isset($map[$type]) && $this->update_arg($type.'_load[]', $args[1]);
		}

		if($screen = array_shift($args)){
			$this->screen	= $screen;
			$this->vars		= ['screen_id'=>$screen->id]+array_filter(wpjam_pick($screen, ['post_type', 'taxonomy']));

			$screen->base == 'post' && ($this->post_id = (int)($_GET['post'] ?? ($_POST['post_ID'] ?? 0)));
		}

		if($this->plugin_page){
			$type	= 'plugin_page';
			$object	= $this->current_tab ?: $this->plugin_page;
			$args	= [$object->menu_slug, $this->current_tab ? $object->tab_slug : ''];

			$this->vars	+= ['plugin_page'=>$args[0]]+($args[1] ? ['current_tab'=>$args[1]] : []);

			if(!$args[1] && $screen && str_contains($screen->id, '%')){
				$screen->id	= preg_replace_callback('/^(.*?)(_page_.*)/', fn($m)=> (array_search($m[1], $GLOBALS['admin_page_hooks']) ?: $m[1]).$m[2], $screen->id);
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
				if(!($this->tax_object = wpjam_get_taxonomy($GLOBALS['taxnow']))){
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
			wpjam_map((array)($load['page_file'] ?? []), fn($file)=> is_file($file) && include $file);

			wpjam_call(wpjam_get($load, 'callback') ?: [($model = $load['model'] ?? ''), array_find(['load', $type.'_load'], fn($m)=> method_exists($model, $m))], ...$args);
		}

		wpjam_trap($this->plugin_page ? [$object, 'load'] : 'WPJAM_Builtin_Page::load', $screen, fn($e)=> $this->error($e));

		add_action('admin_enqueue_scripts',	[$this, 'enqueue'], 9);
		add_action('all_admin_notices',		[$this, 'error'], 9);
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

			wpjam_ajax('wpjam-page-action', [
				'admin'			=> true,
				'nonce_action'	=> fn($data)=> $data['page_action'] ?? '',
				'callback'		=> fn($data)=> ($object	= wpjam_get_page_action($data['page_action'] ?? '')) ? $object($data['action_type']) : wpjam_page_action_compat($data),
			]);

			wpjam_ajax('wpjam-query', [
				'admin'		=> true,
				'fields'	=> ['data_type'=> ['required'=>true]],
				'callback'	=> fn($data)=> ['items'=>($cb = [wpjam_get_data_type($data['data_type'], $data['query_args']), 'query_items'])[0] ? wpjam_try($cb, $data['query_args']) : []]
			]);

			wpjam_ajax('wpjam-upload', [
				'admin'			=> true,
				'nonce_action'	=> fn($data)=> 'upload-'.$data['name'],
				'callback'		=> fn($data)=> wpjam_upload($data['name'], ['accept'=>$data['accept']])
			]);

			$page	= $GLOBALS['plugin_page'] = $_POST['plugin_page'] ?? '';
			$part	= $page ? wpjam_at($screen, '_page_'.$page, 1) : $screen;
			$type	= array_find(['network', 'user'], fn($v)=> str_ends_with($part, '-'.$v));
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
	public function __invoke($type=''){
		if($type == 'form'){
			$title	= wpjam_get_post_parameter('page_title');
			$key	= $title ? '' : array_find(['page_title', 'button_text', 'submit_text'], fn($k)=> $this->$k && !is_array($this->$k));

			return [
				'type'	=> 'form',
				'form'	=> $this->get_form(),
				'width'	=> (int)$this->width,

				'modal_id'		=> $this->modal_id,
				'page_title'	=> $key ? $this->$key : $title
			];
		}

		$this->is_allowed($type) || wp_die('access_denied');

		$args	= $this->validate ? [$this->get_fields()->get_parameter('data')] : [];
		$submit	= $type == 'submit' ? (wpjam_get_post_parameter('submit_name') ?: $this->name) : '';
		$button	= $submit ? wpjam_button($this, $submit) : [];
		$type	= $button['response'] ?? ($this->response ?? $this->name);
		$cb		= $button['callback'] ?? $this->callback;
		$result	= ($cb ? wpjam_try($cb, ...[...$args, $submit ?: $this->name]) : null) ?? wpjam_throw('invalid_callback');
		$result	= is_array($result) ? wpjam_notice($result)+$result : (is_string($result) ? [($type == 'redirect' ? 'url' : 'data')=>$result] : []);
		$result	+= ['type'=>$type]+($this->dismiss ? ['dismiss'=>true] : []);
		$result	+= $result['type'] == 'redirect' ? ['target'=>$this->target ?: '_self'] : [];

		return apply_filters('wpjam_ajax_response', $result);
	}

	public function is_allowed($type=''){
		return wpjam_can(($this->capability ?? ($type ? 'manage_options' : '')), $this->name);
	}

	public function render(){
		return wpjam_trap([$this, 'get_form'], 'die');
	}

	public function get_button($args=[]){
		if($this->is_allowed()){
			$text	= $this->update_args(wpjam_except($args, 'data'))->button_text ?? '保存';

			return wpjam_tag(($this->tag ?: 'a'), [
				'title'	=> $this->page_title ?: $text,
				'style'	=> $this->style,
				'class'	=> $this->class ?? 'button-primary large',
				'data'	=> ['action'=>$this->name, 'nonce'=>wp_create_nonce($this->name)]+$this->pick(['direct', 'confirm'])+[
					'title'	=> $this->page_title ?: $this->button_text,
					'data'	=> wp_parse_args($args['data'] ?? [], ($this->data ?: [])),
				]
			], $text)->add_class('wpjam-button');
		}
	}

	public function get_form(){
		if($this->is_allowed()){
			return $this->get_fields()->wrap('form', [
				'id'		=> $this->form_id ?: 'wpjam_form',
				'button'	=> wpjam_button($this),
				'data'		=> ['action'=>$this->name, 'nonce'=>wp_create_nonce($this->name)]
			]);
		}
	}

	public function get_fields(){
		$fields	= wpjam_try('maybe_callback', $this->fields, $this->name) ?: [];
		$data	= is_callable($this->data_callback) ? wpjam_try($this->data_callback, $this->name, $fields) : [];

		return wpjam_fields($fields, wpjam_merge($this->args, ['data'=>$data ?: []]));
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
			// wp_dashboard_setup();

			require_once ABSPATH.'wp-admin/includes/dashboard.php';

			wp_enqueue_script('dashboard');

			wp_is_mobile() && wp_enqueue_script('jquery-touch-punch');
		}

		foreach(array_merge(maybe_callback($this->widgets, $this->name) ?: [], array_filter(wpjam_admin('widgets[]'), [$this, 'is_available'])) as $id => $w){
			add_meta_box(
				$w['id'] ?? $id,
				$w['title'],
				$w['callback'] ?? wpjam_get_filter_name($w['id'] ?? $id, 'dashboard_widget_callback'),
				get_current_screen(),
				$w['context'] ?? 'normal',
				$w['priority'] ?? 'core',
				$w['args'] ?? []
			);
		}
	}

	public function render(){
		$panel	= wpjam_ob($this->welcome_panel, $this->name);
		$panel	= $panel ? wpjam_tag('div', ['id'=>'welcome-panel', 'class'=>'welcome-panel wpjam-welcome-panel'], $panel) : '';

		return wpjam_tag('div', ['id'=>'dashboard-widgets-wrap'], wpjam_ob('wp_dashboard'))->before($panel);
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

	public function parse($args, $slug, $parent=''){
		if(wp_doing_ajax()){
			$this->args			= $args;
			[$args, $parent]	= $this->subs ? [$this->get_sub($slug), $parent] : [$args, ''];
		}elseif(!$this->builtins){
			$map	= ['appearance'=>'themes', 'settings'=>'options', 'profile'=>'users'];

			$this->builtins	= wpjam_reduce($GLOBALS['admin_page_hooks'], fn($c, $v, $k)=> $c+[(str_starts_with($k, 'edit.php?') && $v != 'pages') ? wpjam_get_post_type_setting($v, 'plural') : $v => $k]+(isset($map[$v]) ? [$map[$v]=>$k] : []), []);
		}

		$this->args	= $args+['menu_slug'=>$slug];
		$slug		= $this->menu_slug;
		$builtin	= $parent ? '' : ($this->builtins[$slug] ?? '');

		if(!$builtin){
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

				$cb	= '__return_true';
			}else{
				str_starts_with($slug, $GLOBALS['pagenow']) && array_all(wp_parse_args(parse_url($slug, PHP_URL_QUERY)), fn($v, $k)=> $v == wpjam_get_parameter($k)) && add_filter('parent_file', fn()=> $parent ?: $slug);
			}

			$object	= ($this->is_current() && ($parent || (!$parent && !$this->subs))) ? wpjam_admin('plugin_page', wp_clone($this)) : null;
			$args	= [$this->page_title, $this->menu_title, $this->capability, $slug, ($object ? [$object, 'render'] : ($cb ?? null)), $this->position];
			$icon	= $parent ? '' : ($this->icon ? (str_starts_with($this->icon, 'dashicons-') ? '' : 'dashicons-').$this->icon : '');
			$hook	= $parent ? add_submenu_page($parent, ...$args) : add_menu_page(...wpjam_add_at($args, -1, $icon));

			$object && wpjam_admin('page_hook', $hook);
		}

		if(!$parent && ($builtin || $this->subs)){
			$subs	= ($builtin ? [] : [$slug=>$this->get_sub($slug)])+wpjam_sort($this->subs, fn($v)=> ($v['order'] ?? 10) - ($v['position'] ?? 10)*1000);

			wpjam_map($subs, fn($sub, $s)=> $this->parse($sub, $s, $builtin ?: $slug));
		}
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
		$cb	= $this->pull('load_callback');
		$cb && !is_callable($cb) && $this->include();	// load_callback 优先文件加载，如不存在，尝试先加载文件
		$cb && wpjam_call($cb, $this->name);

		wpjam_map((array)$this->pull(($this->tab_slug ? 'tab' : 'page').'_file'), fn($f)=> include($f));
	}

	private function defaults($defaults=null){
		($defaults	??= $this->defaults) && is_array($defaults) && wpjam_default($defaults);
	}

	public function load(){
		$this->defaults();
		$this->include();

		do_action('wpjam_plugin_page', $this, ($type = $this->type));

		if($type && $type != 'tab'){
			$name	= $this->{$type.'_name'} ?: $this->menu_slug;
			$object	= $this->page_object($type, $name, 'preprocess');
		}

		if($this->data_type){
			$data_type	= wpjam_admin('data_type', $this->data_type);
			$dt_object	= wpjam_get_data_type($data_type, $this->args);

			$dt_object && $dt_object->meta_type && wpjam_admin('meta_type', $dt_object->meta_type);

			in_array($data_type, ['post_type', 'taxonomy']) && $this->$data_type && !wpjam_admin('screen', $data_type) && wpjam_admin('screen', $data_type, $this->$data_type);
		}

		$this->actions	&& wpjam_map($this->actions, 'wpjam_register_page_action', 'kv');	
		$this->chart	&& wpjam_chart(is_array($this->chart) ? $this->chart : []);
		$this->editor	&& add_action('admin_footer', 'wp_enqueue_editor');

		$GLOBALS['current_admin_url']	= wpjam_admin('url', $this->admin_url);

		if($type == 'tab'){
			$GLOBALS['current_tab']	= wpjam_get_parameter(...(wp_doing_ajax() ? ['current_tab', [], 'POST'] : ['tab'])) ?: null;

			$tabs	= $this->get_arg('tabs', [], 'callback');
			$tabs	= wpjam_reduce(wpjam_admin('tabs[]'), fn($c, $v, $k)=> $this->is_available($v) ? $c+[$v['tab_slug']=>$v] : $c, $tabs);
			$tabs	= wpjam_array(wpjam_sort($tabs, 'order', 'desc', 10), fn($s, $tab)=> ($tab = new self(['tab_slug'=>$s, 'admin_url'=>$this->admin_url.'&tab='.$s]+$tab+['capability'=>$this->capability]))->query_data() ? [$s, $tab, $GLOBALS['current_tab'] ??= $s] : null);

			$object	= $tabs ? ($tabs[$GLOBALS['current_tab']] ?? $this->throw('无效的 Tab')) : $this->throw('Tabs 未设置');

			$object->type || $object->function || $this->throw('Tab 未设置 function');
			$object->type == 'tab' && $this->throw('Tab 不能嵌套 Tab');

			$object->menu_slug	= $this->menu_slug;

			if(!wp_doing_ajax()){
				$this->tabs		= $tabs;
				$this->render	= [$object, 'render'];
			}

			wpjam_admin('current_tab', $object);
			wpjam_admin('load');
		}elseif($type){
			$object	??= $this->page_object($type, $name);
			$load	= fn()=> wpjam_call([$object, 'page_load']);

			wp_doing_ajax() ? $load() : add_action('load-'.wpjam_admin('page_hook'), $load);

			$this->render		= [$object, 'render'];
			$this->page_title	= $object->title ?: $this->page_title;
			$this->summary		= $this->summary ?: $object->get_arg('summary');

			$object->query_args && wpjam_admin('query_data', wpjam_get_data_parameter($object->query_args));
		}else{
			$function		= $this->function ?: wpjam_get_filter_name($this->name, 'page');
			$this->render	= is_callable($function) ? fn()=> wpjam_chart('render').wpjam_ob($function) : $this->throw('页面函数'.'「'.$function.'」未定义。');
		}
	}

	private function page_object($type, $name, $step=''){
		$args	= $this->$type;
		$class	= ['form'=>'WPJAM_Page_Action', 'option'=>'WPJAM_Option_Setting'][$type] ?? '';

		if($step == 'preprocess'){
			$object	= $class ? $class::get($name) : null;

			if($object){
				$object	= $type == 'option' ? $object->get_current() : $object;
				$args	= $object->to_array();
			}else{
				$model	= in_array($type, ['list_table', 'form']) ? ($args ? (is_string($args) ? $args : '') : $this->model) : '';
				$cb		= $model && class_exists($model) ? [$model, 'get_'.$type] : '';
				$args	= $cb && method_exists(...$cb) ? $cb($this) : $args;
				$args	= wpjam_is_assoc_array($args) ? $args+$this->pick(['model']) : $args;

				$args && ($args	= $this->$type = maybe_callback($args, $this));
			}

			if(is_array($args)){
				!empty($args['meta_type']) && wpjam_admin('meta_type', $args['meta_type']);

				$this->update_args(WPJAM_Data_Type::prepare($args));
			}

			return $object ?? null;
		}

		if(!$args){
			array_any(['form'=>['callback'], 'option'=>['sections', 'fields'], 'dashboard'=>['widgets'], 'list_table'=>['model']][$type], fn($k)=> $this->$k) || $this->throw($type.'「'.$name.'」未定义。');

			$args	= $type == 'list_table' ? wpjam_except($this->to_array(), 'defaults') : $this->to_array();
		}

		if($class){
			$object	= $class::create($name, $args);

			return $type == 'option' ? $object->get_current() : $object;
		}

		if($type == 'dashboard'){
			return new WPJAM_Dashboard(['name'=>$name]+$args);
		}

		$model	= $args['model'] ?? '';

		(!$model || !class_exists($model)) && $this->throw('List Table Model'.'「'.$model.'」未定义。');

		$this->defaults($args['defaults'] ?? []);

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

	public function render(){
		$tag		= wpjam_tag('h1', ['wp-heading-inline'], ($this->page_title ?? $this->title))->after('hr', ['wp-header-end']);
		$summary	= maybe_callback($this->summary, $this->menu_slug, $this->tab_slug ?: '');
		$summary	= !$summary || is_array($summary) ? '' : (is_file($summary) ? wpjam_get_file_data($summary, 'summary') : $summary);

		$summary && $tag->after('p', ['summary'], $summary);

		if($this->type == 'tab'){
			$tag->after(wpjam_ob(wpjam_get_filter_name($this->menu_slug, 'page')) ?: '');	// 所有 Tab 页面都执行的函数

			count($this->tabs) > 1 && $tag->after(wpjam_tag('nav', ['nav-tab-wrapper', 'wp-clearfix'])->append(array_map(fn($tab)=> ['a', ['class'=>['nav-tab', $tab->is_current() ? 'nav-tab-active' : ''], 'href'=>$tab->admin_url], ($tab->tab_title ?: $tab->title)], $this->tabs)));
		}

		$tag->after(call_user_func($this->render, $this));

		return $this->tab_slug ? $tag->tag('h2') : wpjam_echo($tag->wrap('div', ['wrap']));
	}
}

class WPJAM_Builtin_Page{
	public static function on_edit_form($post){
		$meta_boxes	= $GLOBALS['wp_meta_boxes'][$post->post_type]['wpjam'] ?? [];

		foreach(wpjam_pick($meta_boxes, ['high', 'core', 'default', 'low']) as $boxes){
			foreach((array)$boxes as $box){
				if(!empty($box['id']) && !empty($box['title'])){
					$title[]	= ['a', ['class'=>'nav-tab', 'href'=>'#tab_'.$box['id']], $box['title']];
					$content[]	= ['div', ['id'=>'tab_'.$box['id']], wpjam_ob($box['callback'], $post, $box)];
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
			if(($cb = $object->meta_box_cb) || ($object->fields	= $object->get_fields($post_id, ''))){
				if($method == 'callback'){
					wpjam_trap([$object, 'callback'], $post_id, 'die');
				}else{
					$id		= $GLOBALS['current_screen']->action == 'add' ? false : $post_id;
					$args	= ['fields_type'=>$object->context === 'side' ? 'list' : 'table'];

					add_meta_box($object->name, $object->title, $cb ?: fn()=> $object->render($id, $args), $post_type, ($object->context ?: $context), $object->priority);
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
			wpjam_trap([$object, $method], ...[...$args, 'die']);
		}

		if($method == 'validate'){
			return $term;
		}
	}

	public static function load($screen){
		$base		= $screen->base;
		$typenow	= $GLOBALS['typenow'];
		$taxnow		= $GLOBALS['taxnow'];

		if($base == 'upload' && (wpjam_get_parameter('mode') ?: get_user_option('media_library_mode', get_current_user_id())) != 'list'){
			return;
		}

		if(in_array($base, ['edit', 'upload'])){
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
			$object	= wpjam_admin('type_object');
			$label	= in_array($typenow, ['post', 'page', 'attachment']) ? '' : $object->labels->name;
			$size	= $object->thumbnail_size;
			$frag	= parse_url(wp_get_referer(), PHP_URL_FRAGMENT);

			$label	&& add_filter('post_updated_messages', fn($ms)=> $ms+[$typenow=> array_map(fn($m)=> str_replace('文章', $label, $m), $ms['post'])]);
			$frag	&& add_filter('redirect_post_location', fn($location)=> $location.(parse_url($location, PHP_URL_FRAGMENT) ? '' : '#'.$frag));
			$size	&& add_filter('admin_post_thumbnail_html', fn($content)=> $content.wpautop('尺寸：'.$size));

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
	public function validate($data){
		$fields	= wpjam_fields($this->get_fields(['data'=>$data]));

		return $fields->validate($data+$fields->get_defaults());
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

	public function get_parameter($key, $args=[]){
		if(is_array($key)){
			return wpjam_fill($key, fn($k)=> $this->get_parameter($k, $args));	
		}

		if(str_contains($key, 'timestamp')){
			return wpjam_strtotime($this->get_parameter(str_replace('timestamp', 'date', $key), $args).' '.(str_starts_with($key, 'end_') ? '23:59:59' : '00:00:00'));
		}

		$value	= ($args['data'][$key] ?? '') ?: wpjam_get_parameter($key, ['method'=>($args['method'] ?? $this->method)]);
		$value && wpjam_set_cookie($key, $value, HOUR_IN_SECONDS);

		if(!empty($_COOKIE[$key])){
			return $_COOKIE[$key];
		}

		if($key == 'date_format' || $key == 'date_type'){
			return '%Y-%m-%d';
		}elseif($key == 'compare'){
			return 0;
		}

		if($key == 'date'){
			$ts	= time() - DAY_IN_SECONDS;
		}elseif($key == 'start_date'){
			$ts	= time() - DAY_IN_SECONDS*30;
		}elseif($key == 'end_date'){
			$ts	= time();
		}elseif($key == 'start_date_2'){
			$ts	= $this->get_parameter('end_timestamp_2') - ($this->get_parameter('end_timestamp') - $this->get_parameter('start_timestamp'));
		}elseif($key == 'end_date_2'){
			$ts	= $this->get_parameter('start_timestamp') - DAY_IN_SECONDS;
		}

		return isset($ts) ? wpjam_date('Y-m-d', $ts) : null;
	}

	public function get_fields($args=[]){
		$fields	= $this->show_start_date ? [
			'start_date'=> ['type'=>'date'],
			'date_view'	=> ['type'=>'view',		'value'=>'-'],
			'end_date'	=> ['type'=>'date']
		] : ($this->show_date ? [
			'prev_day'	=> ['type'=>'button',	'value'=>'‹',	'class'=>'button prev-day'],
			'date'		=> ['type'=>'date'],
			'next_day'	=> ['type'=>'button',	'value'=>'›',	'class'=>'button next-day']
		] : []);

		$fields	= $fields ? ['date'=>['sep'=>' ', 'fields'=>wpjam_map($fields, fn($v, $k)=> ['value'=>($v['value'] ?? $this->get_parameter($k, $args))]+$v)]+(!empty($args['show_title']) ? ['title'=>'日期'] : [])] : [];

		return $fields+($this->show_date_type ? ['date_format'=>['type'=>'select','value'=>$this->get_parameter('date_format', $args), 'options'=>[
			'%Y-%m'				=> '按月',
			'%Y-%m-%d'			=> '按天',
			// '%Y%U'			=> '按周',
			'%Y-%m-%d %H:00'	=> '按小时',
			'%Y-%m-%d %H:%i'	=> '按分钟',
		]]] : []);
	}

	public static function line($data, $args=[], $type='Line'){
		foreach(($args['labels'] ?? []) as $k => $v){
			if(is_array($v)){
				$columns[$k]	= $v['label'];

				if($v['show_in_chart'] ?? true){
					$labels[$k]	= $v['label'];
				}

				if(!empty($v['callback'])){
					$cbs[$k]	= $v['callback'];
				}
			}else{
				$columns[$k]	= $labels[$k] = $v;
			}
		}

		$xkey	= $args['day_key'] ?? 'day';
		$ykeys	= array_keys($labels);
		$data	+= ($args['show_table'] ?? true) && $data ? ['total' => array_fill_keys($ykeys, 0)] : [];

		foreach($data as $day => &$item){
			$item	= (array)$item;
			$item	= array_merge($item, isset($cbs) ? array_map(fn($cb)=> $cb($item), $cbs) : []);

			if($day !== 'total'){
				$item[$xkey] ??= $day;

				if(isset($data['total'])){
					$data['total']		= wpjam_map($data['total'], fn($v, $k)=> $v+(is_numeric($item[$k] ?? null) ? $item[$k] : 0));
					$rows[$item[$xkey]]	= $item;
				}

				$item	= wpjam_pull($item, [...$ykeys, $xkey]);
			}
		}

		$total	= wpjam_pull($data, 'total');
		$tag	= wpjam_tag();

		if($args['show_chart'] ?? true){
			$tag->append(wpjam_tag('div', [
				'id'	=> $args['chart_id'] ?? 'daily-chart',
				'data'	=> ['type'=>$type, 'chart'=>true, 'options'=>['data'=>$data, 'xkey'=>$xkey, 'ykeys'=>$ykeys, 'labels'=>array_values($labels)]]
			]));
		}

		if($total && $data){
			$labels		= ($args['day_labels'] ?? [])+['sum'=>'累加', 'avg'=>'平均'];
			$columns	= [$xkey => $args['day_label'] ?? '时间']+$columns;
			$th			= wpjam_tag('tr')->append(wpjam_map($columns, fn($col, $id)=> wpjam_tag('th', ['scope'=>'col', 'id'=>$id], $col)));
			$rows		+= ($args['show_sum'] ?? true) ? ['sum'=>$total] : [];
			$rows		+= ($args['show_avg'] ?? true) ? ['avg'=>array_map(fn($v)=> is_numeric($v) ? round($v/count($data)) : '', $total)] : [];
			$rows		= wpjam_map($rows, fn($row, $k)=> wpjam_tag('tr')->append(wpjam_map($columns, fn($col, $id)=> wpjam_tag('td', [
				'data'	=> ['colname'=>$col],
				'class'	=> ['column-'.$id, ($id == $xkey ? 'column-primary' : '')]
			])->append($id == $xkey ? [($labels[$k] ?? $k), wpjam_tag('button', ['class'=>'toggle-row'])] : ($row[$id] ?? '')))));

			$tag->append(wpjam_tag('table', ['class'=>'wp-list-table widefat striped'])->append([
				wpjam_tag('thead')->append($th),
				wpjam_tag('tbody')->append($rows),
				wpjam_tag('tfoot')->append($th)
			]));
		}

		return $tag;
	}

	public static function bar($data, $args=[]){
		return self::line($data, $args, 'Bar');
	}

	public static function donut($data, $args=[]){
		$tag	= wpjam_tag('div', ['class'=>'donut-chart-wrap']);
		$data	= wpjam_array($data, fn($k, $v)=> [null, [
			'label'	=> !empty($v['label']) ? ($args['labels'][$v['label']] ?? $v['label']) : '/',
			'value'	=> $v['count']
		]]);

		if($args['show_chart'] ?? true){
			$tag->append('div', [
				'id'	=> $args['chart_id'] ?? 'chart_'.wp_generate_password(6, false, false),
				'data'	=> ['type'=>'Donut', 'chart'=>true, 'options'=>['data'=>$data]]
			]);
		}

		if(($args['show_table'] ?? true) && $data){
			$columns	= (!empty($args['show_line_num']) ? ['no'=>'排名'] : [])+['label'=>$args['title'] ?? '名称', 'value'=>'数量'];

			if($total = $args['total'] ?? false){
				$total		= $total === true ? array_sum(array_column($data, 'value')) : $total;
				$data		+= ['total'=>['label'=>'所有', 'value'=>$total]];
				$columns	+= ['rate'=>'比例'];
			}

			$tag->append(wpjam_tag('table', ['wp-list-table', 'widefat', 'striped'])->append([
				wpjam_tag('thead')->append(wpjam_tag('tr')->append(wpjam_map($columns, fn($col, $id)=> wpjam_tag('th', ['scope'=>'col', 'id'=>$id], $col)))),
				wpjam_tag('tbody')->append(wpjam_map($data, fn($item, $k)=> wpjam_tag('tr')->append(wpjam_map($columns, fn($col, $id)=> wpjam_tag('td', ['data'=>['colname'=>$col]], $item[$id] ?? ($id == 'no' ? ($k === 'total' ? '' : $k+1) : round($item['value']/$total*100, 2).'%'))))))
			]));
		}

		return $tag;
	}

	public static function create($args=[]){
		$offset	= (int)get_option('gmt_offset');
		$offset	= $offset >= 0 ? '+'.$offset.':00' : $offset.':00';

		$GLOBALS['wpdb']->query("SET time_zone = '{$offset}';");

		wpjam_style('morris',	wpjam_get_static_cdn().'/morris.js/0.5.1/morris.css');
		wpjam_script('raphael',	wpjam_get_static_cdn().'/raphael/2.3.0/raphael.min.js');
		wpjam_script('morris',	wpjam_get_static_cdn().'/morris.js/0.5.1/morris.min.js');

		return new self($args+[
			'method'			=> 'POST',
			'show_form'			=> true,
			'show_start_date'	=> true,
			'show_date'			=> true,
			'show_date_type'	=> false,
			'show_compare'		=> false
		]);
	}
}