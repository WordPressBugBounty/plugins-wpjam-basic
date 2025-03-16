<?php
if(!class_exists('WP_List_Table')){
	include ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class WPJAM_List_Table extends WP_List_Table{
	use WPJAM_Call_Trait;

	private $objects	= [];

	public function __construct($args=[]){
		add_screen_option('list_table', $this);

		$GLOBALS['wpjam_list_table']	= $this;

		if(wp_doing_ajax() && wpjam_get_post_parameter('action_type') == 'query_items'){
			$_REQUEST	= array_merge($_REQUEST, wpjam_get_data_parameter());	// 兼容
		}

		$this->_args	= $args;
		$this->screen	= $screen = get_current_screen();

		$args	= compact('screen');
		$style	= [$this->style];

		foreach($this->get_objects('action') as $object){
			if($this->layout == 'calendar' ? !$object->calendar : $object->calendar === 'only'){
				continue;
			}

			$key	= $object->name;

			if($object->overall){
				$args['overall_actions'][]	= $key;
			}else{
				if($object->bulk && $object->is_allowed()){
					$args['bulk_actions'][$key]	= $object;
				}

				if($object->row_action){
					$args['row_actions'][$key]	= $object;
				}
			}

			if($object->next){
				$args['next_actions'][$key]	= $object->next;
			}
		}

		foreach($this->get_objects('view') as $object){
			if($view = $object->parse()){
				$args['views'][$object->name]	= is_array($view) ? $this->get_filter_link(...$view) : $view;
			}
		}

		if($this->layout == 'calendar'){
			$this->query_args	= ['year', 'month', ...wpjam_array($this->query_args)];
		}else{
			if(!$this->builtin && !empty($args['bulk_actions'])){
				$args['columns']['cb']	= true;
			}

			foreach($this->get_objects('column') as $object){
				$style[]= $object->style;
				$key	= $object->name;
				$data	= array_filter(wpjam_pick($object, ['description', 'sticky', 'nowrap', 'format', 'precision', 'conditional_styles']));

				$args['columns'][$key]	= $object->title.($data ? wpjam_tag('i', ['data'=>$data]) : '');

				if($object->sortable){
					$args['sortable_columns'][$key] = [$key, true];
				}
			}
		}

		WPJAM_Admin::add_var('style', array_filter($style));
		WPJAM_Admin::add_var('list_table', fn()=> $this->get_setting());
		WPJAM_Admin::add_var('page_title_action', fn()=> $this->get_action('add', ['class'=>'page-title-action']) ?: '');

		add_filter('views_'.$screen->id, [$this, 'filter_views']);
		add_filter('bulk_actions-'.$screen->id, [$this, 'filter_bulk_actions']);
		add_filter('manage_'.$screen->id.'_sortable_columns', [$this, 'filter_sortable_columns']);

		$this->_args	= array_merge($this->_args, $args);

		if($this->builtin){
			$this->page_load();
		}else{
			parent::__construct($this->_args);
		}
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		if(in_array($name, ['year', 'month']) && $this->layout == 'calendar'){
			$value	= (int)wpjam_get_data_parameter($name) ?: wpjam_date($name == 'year' ? 'Y' : 'm');

			return clamp($value, ...($name == 'year' ? [1970, 2200] : [1, 12]));
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if(in_array($name, ['primary_key', 'actions', 'views', 'fields', 'filterable_fields', 'searchable_fields'])){
			$value	= $this->_by_model('get_'.$name);
			$value	= wpjam_if_error($value, null);

			if($name == 'primary_key'){
				return $this->$name	= $value ?: 'id';
			}elseif($name == 'fields'){
				return $this->$name = WPJAM_Fields::parse($value ?: [], ['flat'=>true]);
			}elseif($name == 'filterable_fields'){
				$fields	= wpjam_filter($this->fields, ['filterable'=>true]);

				if(!wp_is_numeric_array($value)){
					$fields	= array_merge($value ?: [], $fields);
					$value	= [];
				}

				$this->filterable	= array_merge($value, array_keys($fields));

				foreach($fields as &$field){
					$title	= wpjam_pull($field, 'title') ?: '';
					$field	+=[(wpjam_get($field, 'type') == 'select' ? 'show_option_all' : 'placeholder') => $title];
					$field	= wpjam_except($field, ['before', 'after', 'required', 'show_admin_column']);
				}

				return $this->$name = $fields+(($fields && !$this->builtin && $this->sortable_columns) ? [
					'orderby'	=> ['options'=>[''=>'排序']+wpjam_map(array_intersect_key($this->columns, $this->sortable_columns), 'wp_strip_all_tags')],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				] : []);
			}else{
				return $value ?: [];
			}
		}
	}

	public function __set($name, $value){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name	= $value;
		}

		return $this->_args[$name]	= $value;
	}

	public function __isset($name){
		return $this->$name !== null;
	}

	public function __call($method, $args){
		if($method == 'get_arg'){
			return $this->{$args[0]};
		}elseif($method == 'exists'){
			return ($this->model && $args[0]) ? method_exists($this->model, $args[0]) : false;
		}elseif(try_remove_suffix($method, '_by_builtin')){
			if($this->builtin){
				$GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin, ['screen'=>$this->screen]);

				return [$GLOBALS['wp_list_table'], $method](...$args);
			}
		}elseif(try_remove_suffix($method, '_by_model')){
			$object	= WPJAM_Method::create($this->model);
			$method	= $method ?: array_shift($args);

			if($object->exists($method)){
				if($method == 'query_items' && $object->verify($method, fn($params)=> count($params) >= 2 && $params[0]->name != 'args')){
					$args	= array_values(array_slice($args[0], 0, 2));
				}
			}else{
				if($method == 'get_views'){
					return $object->exists('views') ? $object->call('views') : [];
				}elseif($method == 'get_actions'){
					return $this->builtin ? [] : WPJAM_Model::get_actions();
				}elseif(in_array($method, ['get_fields', 'get_subtitle', 'get_summary', 'extra_tablenav', 'before_single_row', 'after_single_row', 'col_left'])){
					return;
				}
			}

			return $object->call($method, ...$args);
		}elseif(try_remove_prefix($method, 'filter_')){
			if($method == 'table'){
				return wpjam_replace('#<tr id="'.$this->singular.'-(\d+)"[^>]*>(.+?)</tr>#is', fn($m)=> $this->filter_single_row($m[0], $m[1]), $args[0]);
			}elseif($method == 'single_row'){
				return wpjam_do_shortcode(apply_filters('wpjam_single_row', ...$args), [
					'filter'		=> fn($attr, $title)=> $this->get_filter_link($attr, $title, wpjam_pull($attr, 'class')),
					'row_action'	=> fn($attr, $title)=> $this->get_row_action($args[1], array_filter(['title'=>$title])+$attr)
				]);
			}elseif($method == 'custom_column'){
				$value	= $this->get_column_value(...array_reverse($args));

				return count($args) == 2 ? wpjam_echo($value) : $value;
			}

			$value	= $this->$method ?: [];

			if($method == 'columns'){
				return wpjam_except($args ? wpjam_add_at($args[0], -1, $value) : $value, $this->call_type('column', 'removed'));
			}elseif($method == 'row_actions'){
				if($this->layout != 'calendar'){
					$item		= $args[1];
					$args[1]	= ['id'=>$this->parse_id($item)];
				}

				$value	= wpjam_except($value, ($this->next_actions ?: []));
				$value	= wpjam_except($args[0]+$this->get_actions($value, $args[1]), $this->call_type('action', 'removed'));
				$value	+= $this->builtin ? wpjam_pull($value, ['delete', 'trash', 'spam', 'remove', 'view']) : [];

				return $value+($this->primary_key == 'id' || $this->builtin ? ['id'=>'ID: '.$args[1]['id']] : []);
			}

			return array_merge($args[0], $value);
		}

		return parent::__call($method, $args);
	}

	protected function get_objects($type='action'){
		self::call_type($type, 'registers', $type == 'column' ? $this->fields : $this->{$type.'s'});

		$args	= wpjam_parse_data_type($this);

		if($type == 'action'){
			$sortable	= $this->sortable;
			$meta_type	= get_screen_option('meta_type');

			if($sortable){
				$sortable	= is_array($sortable) ? $sortable : ['items'=>' >tr'];
				$action		= wpjam_pull($sortable, 'action') ?: [];

				wpjam_map([
					'move'	=> ['page_title'=>'拖动',	'dashicon'=>'move'],
					'up'	=> ['page_title'=>'向上移动',	'dashicon'=>'arrow-up-alt'],
					'down'	=> ['page_title'=>'向下移动',	'dashicon'=>'arrow-down-alt'],
				], fn($v, $k)=> self::call_type($type, 'register', $k, $action+$v+['direct'=>true]));

				$this->sortable	= $sortable;
			}

			if($meta_type){
				wpjam_map(wpjam_get_meta_options($meta_type, ['list_table'=>true]+wpjam_except($args, 'data_type')), fn($v)=> $v->register_list_table_action());
			}
		}

		return $this->objects[$type] = self::call_type($type, 'get_registereds', wpjam_map($args, fn($v)=> ['value'=>$v, 'if_null'=>true, 'callable'=>true]));
	}

	protected function get_object($name, $type='action'){
		$objects	= $this->objects[$type];

		return $objects[$name] ?? array_find($objects, fn($v)=> $v->name == $name);
	}

	protected function get_setting(){
		$s	= wpjam_get_data_parameter('s') ?: '';

		return [
			'subtitle'	=> $this->get_subtitle_by_model().($s ? sprintf(__('Search results for: %s'), '<strong>'.esc_html($s).'</strong>') : ''),
			'summary'	=> $this->get_summary_by_model(),
			'sortable'	=> $this->sortable,

			'column_count'		=> $this->get_column_count(),
			'bulk_actions'		=> wpjam_map($this->bulk_actions ?: [], fn($object)=> array_filter($object->generate_data_attr(['bulk'=>true]))),
			'overall_actions'	=> array_values($this->get_actions($this->overall_actions, ['class'=>'button overall-action']))
		];
	}

	protected function get_actions($names, $args=[]){
		return array_filter(array_map(fn($n)=> $this->get_action($n, $args), $names ?: []));
	}

	public function get_action($name, $args=[]){
		return ($object = is_object($name) ? $name : $this->get_object($name)) ? $object->render($args) : null;
	}

	public function get_row_action($id, $args=[]){
		return $this->get_action(...(isset($args['name']) && !isset($args['id']) ? [wpjam_pull($args, 'name'), $args+['id'=>$id]] : [$id, $args]));
	}

	public function get_filter_link($filter, $label, $attr=[]){
		$args	= array_diff(($this->query_args ?: []), array_keys($filter));
		$filter	= array_merge($filter, wpjam_get_data_parameter($args)) ?: new stdClass();

		return wpjam_tag('a', $attr, $label)->add_class('list-table-filter')->data('filter', $filter);
	}

	public function single_row($item){
		if(!is_array($item)){
			if($item instanceof WPJAM_Register){
				$item	= $item->to_array();
			}else{
				$item	= wpjam_if_error($this->get_by_model($item), wp_doing_ajax() ? 'throw' : null);
				$item 	= $item ? (array)$item : $item;
			}
		}

		if(!$item){
			return;
		}

		$raw	= $item;
		$id		= $this->parse_id($item);
		$attr	= $id ? ['id'=>$this->singular.'-'.str_replace('.', '-', $id), 'data'=>['id'=>$id]] : [];

		$item['row_actions']	= $id ? $this->filter_row_actions([], $item) : ['error'=>'Primary Key「'.$this->primary_key.'」不存在'];

		$this->before_single_row_by_model($raw);

		$method	= array_find(['render_row', 'render_item', 'item_callback'], fn($v)=> $this->exists($v));

		if($method){
			$item	= [$this->model, $method]($item, $attr);
			$attr	+= $method != 'render_row' && isset($item['class']) ? ['class'=>$item['class']] : [];
		}

		if($item){
			echo $this->filter_single_row(wpjam_tag('tr', $attr, $this->ob_get('single_row_columns', $item))->add_class($this->multi_rows ? 'tr-'.$id : ''), $id)."\n";
		}

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		$parts	= explode('-', $date);
		$tag	= wpjam_tag('span', ['day', $date == wpjam_date('Y-m-d') ? 'today' : ''], (int)$parts[2])->wrap('div', ['date-meta']);

		if($parts[1] == $this->month){
			$tag->append(wpjam_tag('div', ['row-actions', 'alignright'])->append($this->filter_row_actions([], ['id'=>$date, 'wrap'=>'<span class="%s"></span>'])));

			$item	= $this->exists('render_date') ? $this->render_date_by_model($item, $date) : (is_string($item) ? $item : '');
		}

		echo $tag->after('div', ['date-content'], $item);
	}

	protected function parse_id($item){
		return wpjam_get($item, $this->primary_key);
	}

	public function get_column_value($id, $name, $value=null){
		$object	= $this->get_object($name, 'column');

		if($object){
			$value	??= $this->value_callback !== false && $this->exists('value_callback') ? wpjam_value_callback([$this->model, 'value_callback'], $name, $id) : $object->default;

			$value	= wpjam_is_assoc_array($value) ? $value : $object->render($value, in_array($name, $this->filterable), $id);
		}

		if(wp_is_numeric_array($value)){
			return implode(',', array_map(fn($v)=> $this->parse_column_value($v, $id), $value));
		}

		return $this->parse_column_value($value, $id);
	}

	protected function parse_column_value($value, $id){
		if(!is_array($value)){
			return $value;
		}

		$wrap	= wpjam_pull($value, 'wrap');

		if(isset($value['row_action'])){
			$value	= $this->get_row_action($id, ['name'=>$value['row_action']]+array_get($value, 'args', []));
		}elseif(isset($value['filter'])){
			$value	= $this->get_filter_link(wpjam_pull($value, 'filter'), wpjam_pull($value, 'label'), $value);
		}elseif(isset($value['items'])){
			$items	= $value['items'];
			$args	= $value['args'] ?? [];
			$type	= $args['item_type'] ?? 'image';
			$key	= $args[$type.'_key'] ?? $type;
			$width	= $args['width'] ?? 60;
			$height	= $args['height'] ?? 60;
			$names	= $args['actions'] ?? ['add_item', 'edit_item', 'del_item'];
			$field	= $args['field'] ?? '';
			$value	= wpjam_tag('div', ['items', $type.'-list'])->data(['field'=>$field, 'width'=>$width, 'height'=>$height, 'per_row'=>wpjam_get($args, 'per_row')])->style(wpjam_get($args, 'style'));

			if(!empty($args['sortable'])){
				$value->add_class('sortable');

				array_unshift($names, 'move_item');
			}

			$_args	= ['id'=>$id,'data'=>['_field'=>$field]];

			if(in_array('add_item', $names) && (empty($args['max_items']) || count($items) <= $args['max_items'])){
				$value->append($this->get_action('add_item', $_args+['class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2'] : [])));
			}

			foreach(array_reverse($items, true) as $i => $item){
				$v	= $item[$key] ?: '';
				$v	= $type == 'image' ? wpjam_tag('img', ['src'=>wpjam_get_thumbnail($v, $width*2, $height*2), 'style'=>['width'=>$width.'px;', 'height'=>$height.'px;']])->after('span', ['item-title'], $item['title'] ?? '') : $v;

				$_args['i']	= $_args['data']['i']	= $i;

				$value->prepend(wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>['i'=>$i], 'class'=>'item'])->append([
					$this->get_action('move_item', $_args+['title'=>$v, 'fallback'=>true])->style(wpjam_pick($item, ['color'])),
					wpjam_tag('span', ['row-actions'])->append($this->get_actions(array_diff($names, ['add_item']), $_args+['wrap'=>'<span class="%s"></span>']))
				]));
			}
		}else{
			$value	= '';
		}

		return (string)wpjam_wrap($value, $wrap);
	}

	public function column_default($item, $name){
		$value	= $item[$name] ?? null;

		return ($id = $this->parse_id($item)) ? $this->get_column_value($id, $name, $value) : $value;
	}

	public function column_cb($item){
		$id	= $this->parse_id($item);

		if(wpjam_current_user_can($this->capability, $id)){
			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>'cb-select-'.$id, 'title'=>'选择'.strip_tags($item[$this->get_primary_column_name()] ?? $id)]);
		}
	}

	public function render(){
		$form	= wpjam_tag('form', ['id'=>'list_table_form', 'data'=>['layout'=>$this->layout]])->append([$this->get_search_box(), $this->get_table()])->before($this->ob_get('views'));

		return $this->layout == 'left' ? wpjam_tag('div', ['id'=>'col-container', 'class'=>'wp-clearfix'])->append(wpjam_map([
			'left'	=> wpjam_tag('form', ['data-left_key'=>$this->left_key], $this->ob_get('col_left')),
			'right'	=> $form
		], fn($v, $k)=> $v->add_class('col-wrap')->wrap('div', ['id'=>'col-'.$k]))) : $form;
	}

	public function get_search_box(){
		return ($this->search ?? (bool)$this->searchable_fields) ? ($this->ob_get('search_box', '搜索', 'wpjam') ?: wpjam_tag('p', ['search-box'])) : '';
	}

	public function get_table(){
		return $this->ob_get('display');
	}

	public function display_rows_or_placeholder(){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');
			$ts		= mktime(0, 0, 0, $this->month, 1, $this->year);
			$pad	= calendar_week_mod(date('w', $ts) - $start);
			$days	= date('t', $ts);
			$days	= $days+7-(($days+$pad) % 7 ?: 7);
			$cells	= [];

			for($day=(0-$pad); $day<$days; ++$day){
				$date		= date('Y-m-d', $ts+$day*DAY_IN_SECONDS);
				$class		= in_array((count($cells)+$start)%7, [0, 6]) ? 'weekend' : 'weekday';
				$cells[]	= ['td', ['id'=>'date-'.$date, 'class'=>$class], $this->ob_get('single_date', $this->items[$date] ?? [], $date)];
			}

			while($cells){
				echo wpjam_tag('tr')->append(array_splice($cells, 0, 7));
			}
		}else{
			parent::display_rows_or_placeholder();
		}
	}

	public function print_column_headers($with_id=true){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');

			for($i=0; $i<7; $i++){
				$day	= ($i + $start) % 7;
				$name	= $GLOBALS['wp_locale']->get_weekday($day);
				$text	= $GLOBALS['wp_locale']->get_weekday_abbrev($name);

				echo wpjam_tag('th', ['scope'=>'col', 'title'=>$name, 'class'=>(in_array($day, [0, 6]) ? 'weekend' : 'weekday')], $text);
			}
		}else{
			parent::print_column_headers($with_id);
		}
	}

	public function pagination($which){
		if($this->layout == 'calendar'){
			echo wpjam_tag('span', ['pagination-links'])->append(array_map(function($args){
				[$type, $text, [$year, $month]]	= $args;

				return "\n".$this->get_filter_link(['year'=>$year, 'month'=>$month], $text, [
					'class'	=> [$type.'-month', 'button'],
					'title'	=> sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($month), $year)
				]);
			}, [
				['prev',	'&lsaquo;',	($this->month == 1 ? [$this->year-1, 12] : [$this->year, $this->month-1])],
				['current',	'今日',		array_map('wpjam_date', ['Y', 'm'])],
				['next',	'&rsaquo;',	($this->month == 12 ? [$this->year+1, 1] : [$this->year, $this->month+1])],
			]))->wrap('div', ['tablenav-pages']);
		}else{
			parent::pagination($which);
		}
	}

	public function col_left(){
		$result	= $this->col_left_by_model();

		if($result && is_array($result)){
			$total	= wpjam_get($result, 'total_pages') ?: (wpjam_get($result, 'per_page') ? ceil(wpjam_get($result, 'total_items')/wpjam_get($result, 'per_page')) : 0);

			if($total > 1){
				$paged	= (int)wpjam_get_data_parameter('left_paged') ?: 1;

				echo wpjam_tag('span', ['left-pagination-links'])->append([
					wpjam_tag('a', ['prev-page'], '&lsaquo;')->attr('title', __('Previous page')),
					wpjam_tag('span', [], $paged.' / '.$total),
					wpjam_tag('a', ['next-page'], '&rsaquo;')->attr('title', __('Next page')),
					wpjam_tag('input', ['type'=>'number', 'name'=>'left_paged', 'value'=>$paged, 'min'=>1, 'max'=>$total, 'class'=>'current-page']),
					wpjam_tag('input', ['type'=>'submit', 'class'=>'button', 'value'=>'&#10132;'])
				])->wrap('div', ['tablenav-pages'])->wrap('div', ['tablenav', 'bottom']);
			}
		}
	}

	public function page_load(){
		$data	= wp_doing_ajax() ? (wp_parse_args(wpjam_get_post_parameter('form_data') ?: []) ?: wpjam_get_data_parameter()) : wpjam_get_parameter();
		$params	= ($fields = $this->filterable_fields) ? wpjam_if_error(wpjam_fields($fields)->catch('validate', $data), []) : [];

		$this->params	= array_filter($params, fn($v)=> isset($v)) + ($this->chart ? $this->chart->get_data(['data'=>$data]) : []);

		if(wp_doing_ajax()){
			return wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);
		}

		if($action	= wpjam_get_parameter('export_action')){
			return ($object	= $this->get_object($action)) ? $object->callback('export') : wp_die('无效的导出操作');
		}

		wpjam_if_error($this->catch('prepare_items'), fn($result)=> wpjam_add_admin_error($result));
	}

	public function ajax_response(){
		$type	= wpjam_get_post_parameter('action_type');
		$parts	= parse_url(wpjam_get_referer() ?: wp_die('非法请求'));

		if($parts['host'] == $_SERVER['HTTP_HOST']){
			$_SERVER['REQUEST_URI']	= $parts['path'];
		}

		if($type == 'query_items'){
			$data	= wpjam_get_data_parameter();

			if(count($data) == 1 && isset($data['id'])){
				return $this->parse_response(['type'=>'add']+$data);
			}

			$response	= ['type'=>'list']+(($this->layout == 'left' && !isset($data[$this->left_key])) ? ['left'=>$this->ob_get('col_left')] : []);
		}else{
			$object		= $this->get_object(wpjam_get_post_parameter('list_action'));
			$response	= $object ? $object->callback($type) : wp_die('无效的操作');
		}

		if($this->layout == 'calendar' && !empty($response['data'])){
			$response['data']	= wpjam_map(($response['data']['dates'] ?? $response['data']), fn($v)=> $this->ob_get('single_date', $v));
		}

		if(!in_array($response['type'], ['form', 'append', 'redirect', 'move', 'up', 'down'])){
			$this->prepare_items();

			$response	= $this->parse_response($response);
			$response	+= ['params'=>$this->params, 'setting'=>$this->get_setting(), 'views'=>$this->ob_get('views'), 'search_box'=>$this->get_search_box()];
			$response	+= $response['type'] == 'list' ? ['table'=>$this->get_table()] : ['tablenav'=>wpjam_fill(['top', 'bottom'], fn($which)=>$this->ob_get('display_tablenav', $which))];
		}

		return $response;
	}

	protected function parse_response($response){
		if($response['type'] == 'items'){
			if(isset($response['items'])){
				$response['items']	= wpjam_map($response['items'], fn($item, $id)=> $this->parse_response(array_merge($item, ['id'=>$id])));
			}
		}elseif(!in_array($response['type'], ['delete', 'list'])){
			if(!empty($response['bulk'])){
				$ids	= array_filter($response['ids']);
				$data	= $this->get_by_ids_by_model($ids);

				$response['data']	= array_map(fn($id)=> ['id'=>$id, 'data'=>$this->ob_get('single_row', $id)], $ids);
			}elseif(!empty($response['id'])){
				$response['data']	= $this->ob_get('single_row', $response['id']);
			}
		}

		return $response;
	}

	public function prepare_items(){
		$args	= array_filter(wpjam_get_data_parameter(['orderby', 'order', 's']), fn($v)=> isset($v));
		$_GET	= array_merge($_GET, $args);
		$args	+= $this->params;

		if($this->layout == 'calendar'){
			$this->items	= $this->try('query_calendar_by_model', $args+['year'=>$this->year, 'month'=>$this->month]);
		}else{
			$number	= is_numeric($this->per_page ?: 50) ? $this->per_page : 50;
			$offset	= ($this->get_pagenum()-1)*$number;
			$result	= $this->try('query_items_by_model', compact('number', 'offset')+$args);

			if(wp_is_numeric_array($result) || !isset($result['items'])){
				$this->items	= $result;
			}else{
				$this->items	= $result['items'];
				$total_items	= $result['total'] ?? 0;
			}

			if(empty($total_items)){
				$total_items	= $number	= count($this->items);
			}

			$this->set_pagination_args(['total_items'=>$total_items, 'per_page'=>$number]);
		}
	}

	protected function get_table_classes(){
		$classes	= [...parent::get_table_classes(), ($this->nowrap ? 'nowrap' : '')];
		$removed	= [($this->fixed ? '' : 'fixed'), ($this->layout == 'calendar' ? 'striped' : '')];

		return array_diff($classes, $removed);
	}

	protected function get_default_primary_column_name(){
		return $this->primary_column;
	}

	protected function handle_row_actions($item, $column, $primary){
		return ($primary === $column && !empty($item['row_actions'])) ? $this->row_actions($item['row_actions'], false) : '';
	}

	public function get_columns(){
		return $this->filter_columns();
	}

	public function extra_tablenav($which='top'){
		if($which == 'top'){
			if($fields = array_merge($this->chart ? $this->chart->get_fields() : [], $this->filterable_fields)){
				echo wpjam_fields($fields)->render(['fields_type'=>'', 'data'=>$this->params])->after(get_submit_button('筛选', '', 'filter_action', false))->wrap('div', ['alignleft', 'actions']);
			}

			if($this->layout == 'calendar'){
				echo wpjam_tag('h2', [], sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($this->month), $this->year));
			}
		}

		if(!$this->builtin){
			$this->extra_tablenav_by_model($which);

			do_action(wpjam_get_filter_name($this->plural, 'extra_tablenav'), $which);
		}
	}

	public function current_action(){
		return wpjam_get_request_parameter('list_action') ?? parent::current_action();
	}

	public static function call_type($type, $method, ...$args){
		if($method == 'removed'){
			$opt	= 'remove_'.$type.'s';
			$option	= get_screen_option($opt) ?: [];

			return $args ? add_screen_option($opt, [...$option, ...$args]) : $option;
		}

		$class	= 'WPJAM_List_Table_'.$type;

		if(in_array($method, ['register', 'unregister'])){
			$name		= $args[0];
			$args[0]	.= wpjam_parse_data_type($args[1], 'key');

			if($method == 'register'){
				if($type == 'action' && !empty($args[1]['overall']) && $args[1]['overall'] !== true){
					self::call_type($type, $method, $name.'_all', array_merge($args[1], ['overall'=>true, 'title'=>$args[1]['overall']]));

					unset($args[1]['overall']);
				}

				$args[1]	= new $class($name, $args[1]);
			}else{
				if(![$class, 'get']($args[0])){
					return self::call_type($type, 'removed', $name);
				}
			}
		}

		return [$class, $method](...$args);
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_Action extends WPJAM_Register{
	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'page_title'){
			return $this->title ? wp_strip_all_tags($this->title.get_screen_option('list_table', 'title')) : '';
		}elseif($key == 'response'){
			return ($this->overall && $this->name != 'add') ? 'list' : ($this->next ? 'form' : $this->name);
		}elseif($key == 'row_action'){
			return ($this->bulk !== 'only' && $this->name != 'add');
		}elseif($key == 'next_action'){
			return self::get($this->next) ?: '';
		}elseif($key == 'prev_action'){
			$prev	= $this->prev ?: array_search($this->name, ($this->next_actions ?: []));

			return self::get($prev) ?: '';
		}elseif(in_array($key, ['layout', 'model', 'builtin', 'params', 'primary_key', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
			return get_screen_option('list_table', $key);
		}
	}

	public function __toString(){
		return $this->title;
	}

	protected function parse_arg($args){
		if($this->overall){
			return;
		}

		if(wpjam_is_assoc_array($args)){
			if((int)$args['bulk'] === 2){
				return !empty($args['id']) ? $args['id'] : $args['ids'];
			}else{
				return $args['bulk'] ? $args['ids'] : $args['id'];
			}
		}

		return $args;
	}

	public function parse_nonce_action($args){
		return wpjam_join('-', $this->name, empty($args['bulk']) ? ($args['id'] ?? '') : '');
	}

	public function create_nonce($args=[]){
		return wp_create_nonce($this->parse_nonce_action($args));
	}

	public function verify_nonce($args=[]){
		return check_ajax_referer($this->parse_nonce_action($args), false, false);
	}

	public function callback($args){
		if(is_array($args)){
			$cb_args	= [$this->parse_arg($args), $args['data']];
			$cb			= $args[($args['bulk'] ? 'bulk_' : '').'callback'] ?? '';

			if(!$args['bulk']){
				if(in_array($this->name, ['up', 'down'])){
					$cb_args[1]	= ($cb_args[1] ?? [])+[$this->name=>true];
					$this->name	= 'move';
				}

				if(!$cb){
					$cb		= [$this->model, $this->name];
					$cb[1]	= $cb[1] == 'duplicate' && !$this->direct ? 'insert' : (['add'=>'insert', 'edit'=>'update'][$cb[1]] ?? $cb[1]);

					if($cb[1] == 'insert' || $this->response == 'add' || $this->overall){
						array_shift($cb_args);
					}elseif(method_exists(...$cb)){
						if($this->direct && is_null($args['data'])){
							array_pop($cb_args);
						}
					}elseif($this->meta_type || !method_exists($cb[0], '__callStatic')){
						$cb[1]	= 'update_callback';

						if(!method_exists(...$cb)){
							array_unshift($cb_args, get_screen_option('meta_type'));

							if(!$cb_args[0]){
								wp_die('「'.$cb[0].'->'.$this->name.'」未定义');
							}

							$cb	= 'wpjam_update_metadata';
						}

						if($args['fields']){
							$cb_args[]	= $args['fields']->get_defaults();
						}
					}

					return wpjam_try($cb, ...$cb_args) ?? true ;
				}

				if($this->overall || ($this->response == 'add' && !is_null($args['data']) && wpjam_verify_callback($cb, fn($params)=> count($params) == 1 || $params[0]->name == 'data'))){
					array_shift($cb_args);
				}
			}else{
				$cb	= $cb ?: (($cb = [$this->model, 'bulk_'.$this->name]) && method_exists(...$cb) ? $cb : null);

				if(!$cb){
					$data	= [];

					foreach($args['ids'] as $id){
						$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));
						$data	= wpjam_merge($data, is_array($result) ? $result : []);
					}

					return $data ?: true;
				}
			}

			return wpjam_if_error(wpjam_call($cb, ...[...$cb_args, $this->name, $args['submit_name']]), 'throw') ?? wp_die('「'.$this->title.'」的回调函数无效或没有正确返回');
		}

		$type	= $args;
		$data	= $type == 'export' ? (wpjam_get_parameter('data') ?: []) : wpjam_get_data_parameter();
		$data	+= ($this->overall && $type == 'direct') ? $this->params : [];
		$args	= $form_args = ['data'=>$data]+wpjam_map([
			'id'	=> ['default'=>''],
			'bulk'	=> ['sanitize_callback'=>fn($v)=> ['true'=>1, 'false'=>0][$v] ?? $v],
			'ids'	=> ['sanitize_callback'=>'wp_parse_args', 'default'=>[]]
		], fn($v, $k)=> wpjam_get_parameter($k, $v+['method'=>($type == 'export' ? 'get' : 'post')]));

		['id'=>$id, 'bulk'=>&$bulk]	= $args;

		$response	= [
			'list_action'	=> $this->name,
			'page_title'	=> $this->page_title,
			'type'	=> $type == 'form' ? 'form' : $this->response,
			'last'	=> (bool)$this->last,
			'width'	=> (int)$this->width,
			'bulk'	=> &$bulk,
			'id'	=> &$id,
			'ids'	=> $args['ids']
		];

		if(in_array($type, ['submit', 'export'])){
			$submit_name	= wpjam_get_parameter('submit_name', ['default'=>$this->name, 'method'=>($type == 'submit' ? 'POST' : 'GET')]);
			$submit_button	= $submit_name == 'next' ? [] : $this->get_submit_button($args, $submit_name);

			if(!empty($submit_button['response'])){
				$response['type']	= $submit_button['response'];
			}
		}else{
			$submit_name	= null;
			$submit_button	= [];
		}

		if(in_array($type, ['submit', 'direct']) && (
			$this->export 
			|| ($type == 'submit' && !empty($submit_button['export'])) 
			|| ($this->bulk === 'export' && $args['bulk'])
		)){
			$args	+= ['export_action'=>$this->name, '_wpnonce'=>$this->create_nonce($args)];
			$args	+= $submit_name != $this->name ? ['submit_name'=>$submit_name] : [];	

			return ['type'=>'redirect', 'url'=>add_query_arg(array_filter($args), $GLOBALS['current_admin_url'])];
		}

		if(!$this->is_allowed($args)){
			wp_die('access_denied');
		}

		if($type == 'form'){
			return $response+['form'=>$this->get_form($form_args, $type)];
		}

		if(!$this->verify_nonce($args)){
			wp_die('invalid_nonce');
		}

		$bulk	= (int)$bulk === 2 ? 0 : $bulk;
		$cbs	= ['callback', 'bulk_callback'];
		$args	+= wpjam_pick($this, $cbs);
		$fields	= $result = null;

		if($type == 'submit'){
			$fields	= $this->get_fields($args, true, $type);
			$data	= $fields->validate($data);

			$form_args['data']	= $response['type'] == 'form' ? $data : wpjam_get_post_parameter('defaults', ['sanitize_callback'=>'wp_parse_args', 'default'=>[]]);
		}

		if($response['type'] != 'form'){
			$args	= (in_array($type, ['submit', 'export']) ? array_filter(wpjam_pick($submit_button, $cbs)) : [])+$args;
			$result	= $this->callback(compact('data', 'fields', 'submit_name')+$args);

			if(is_array($result) && !empty($result['errmsg']) && $result['errmsg'] != 'ok'){ // 第三方接口可能返回 ok
				$response['errmsg'] = $result['errmsg'];
			}elseif($type == 'submit'){
				$response['errmsg'] = $submit_button['text'].'成功';
			}
		}

		if(is_array($result)){
			if(array_intersect(array_keys($result), ['type', 'bulk', 'ids', 'id', 'items'])){
				$response	= array_merge($response, $result);
				$result		= null;
			}
		}else{
			if(in_array($response['type'], ['add', 'duplicate']) && $this->layout != 'calendar'){
				[$id, $result]	= [$result, null];
			}
		}

		if($response['type'] == 'append'){
			return array_merge($response, $result ? ['data'=>$result] : []);
		}elseif($response['type'] == 'redirect'){
			return array_merge(['target'=>$this->target ?: '_self'], $response, (is_string($result) ? ['url'=>$result] : []));
		}

		if($this->layout == 'calendar'){
			if(is_array($result)){
				$response['data']	= $result;
			}
		}else{
			if(!$response['bulk'] && in_array($response['type'], ['add', 'duplicate'])){
				$form_args['id']	= $response['id'];
			}
		}

		if($result){
			$response['result']	= $result;
		}

		if($type == 'submit'){
			if($this->next){
				$response['next']		= $this->next;
				$response['page_title']	= $this->next_action->page_title;

				if($response['type'] == 'form'){
					$response['errmsg']	= '';
				}
			}

			if($this->dismiss
				|| !empty($response['dismiss'])
				|| $response['type'] == 'delete'
				|| ($response['type'] == 'items' && array_find($response['items'], fn($item)=> $item['type'] == 'delete'))
			){
				$response['dismiss']	= true;
			}else{
				$response['form']	= $this->get_form($form_args, $type);
			}
		}

		return $response;
	}

	public function is_allowed($args=[]){
		if($this->capability == 'read'){
			return true;
		}

		$ids	= ($args && !$this->overall) ? (array)$this->parse_arg($args) : [null];

		return array_all($ids, fn($id)=> wpjam_current_user_can($this->capability, $id, $this->name));
	}

	public function get_data($id, $include_prev=false, $by_callback=false){
		$data	= null;
		$cb		= $this->data_callback;

		if($cb && ($include_prev || $by_callback)){
			$data	= is_callable($cb) ? wpjam_try($cb, $id, $this->name) : wp_die($this->title.'的 data_callback 无效');
		}

		if($include_prev){
			return array_merge(($this->prev_action ? $this->prev_action->get_data($id, true) : []), ($data ?: []));
		}

		if(!$by_callback || is_null($data)){
			$cb		= [$this->model, 'get'];
			$data	= !is_callable($cb) ? wp_die(implode('::', $cb).' 未定义') : wpjam_try($cb, $id);
			$data	= (!$data && $id) ? wp_die('无效的 ID「'.$id.'」') : $data;
			$data	= $data instanceof WPJAM_Register ? $data->to_array() : $data;
		}

		return $data;
	}

	public function get_form($args=[], $type=''){
		[$prev, $object]	= ($type == 'submit' && $this->next) ? [$this, $this->next_action] : [$this->prev_action, $this];

		$id		= ($args['bulk'] || $object->overall) ? null : $args['id'];
		$fields	= $object->get_fields($args, false, $type)->render();
		$args	= ($prev && $id && $type == 'form') ? wpjam_merge($args, ['data'=>$prev->get_data($id, true)]) : $args;
		$button	= ($prev ? $prev->render(['class'=>['button'], 'title'=>'上一步']+$args) : '').$object->get_submit_button($args);
		$form	= $fields->wrap('form', ['novalidate', 'id'=>'list_table_action_form', 'data'=>$object->generate_data_attr($args, 'form')]);

		return $button ? $form->append('p', ['submit'], $button) : $form;
	}

	public function get_fields($args, $include_prev=false, $type=''){
		$arg	= $this->parse_arg($args);
		$fields	= wpjam_try(fn()=> maybe_callback($this->fields, $arg, $this->name));
		$fields	= $fields ?: wpjam_try([$this->model, 'get_fields'], $this->name, $arg);
		$fields	= is_array($fields) ? $fields : [];
		$fields	= array_merge($fields, ($include_prev && $this->prev_action) ? $this->prev_action->get_fields($arg, true) : []);
		$cb		= [$this->model, 'filter_fields'];

		if(method_exists(...$cb)){
			$fields	= wpjam_try($cb, $fields, $arg, $this->name);
		}

		if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
			$fields[$this->primary_key]['type']	= 'view';
		}

		if($type){
			$id		= ($args['bulk'] || $this->overall) ? null : $args['id'];
			$args	= ['id'=>$id, 'data'=>$args['data']];

			if($id){
				if($type != 'submit' || $this->response != 'form'){
					$data	= $this->get_data($id, false, true);

					$args['data']	= is_array($data) ? array_merge($args['data'], $data) : $data;
				}

				$cb		= [$this->model, 'value_callback'];
				$args	+= array_filter(['meta_type'=>get_screen_option('meta_type'), 'value_callback'=>method_exists(...$cb) ? $cb : '']);
			}

			return WPJAM_Fields::create($fields, array_merge($args, array_filter(['value_callback'=>$this->value_callback])));
		}

		return $fields;
	}

	public function get_submit_button($args, $name=null){
		if(!$name && $this->next){
			return get_submit_button('下一步', 'primary', 'next', false);
		}

		$button	= maybe_callback($this->submit_text, $this->parse_arg($args), $this->name);
		$button	??= wp_strip_all_tags($this->title) ?: $this->page_title;
		$button	= is_array($button) ? $button : [$this->name => $button];

		return wpjam_parse_submit_button($button, $name);
	}

	public function render($args=[]){
		$args	+= ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]];
		$id		= $args['id'];

		if($show_if	= $this->show_if){
			if(is_callable($show_if)){
				$result		= wpjam_if_error(wpjam_catch($show_if, $id, $this->name), null);
			}else{
				$show_if	= $id ? wpjam_parse_show_if($show_if) : false;
				$result		= $show_if ? wpjam_match($this->get_data($id), $show_if) : true;
			}

			if(!$result){
				return;
			}
		}

		if($this->builtin && $id){
			if($this->data_type == 'post_type'){
				if(!wpjam_compare(get_post_status($id), ...(array_filter([$this->post_status]) ?: ['!=', 'trash']))){
					return;
				}
			}elseif($this->data_type == 'user'){
				if($this->roles && !array_intersect(get_userdata($id)->roles, (array)$this->roles)){
					return;
				}
			}
		}

		if(!$this->is_allowed($args)){
			$fallback	= $args['fallback'] ?? '';

			return (string)($fallback === true ? ($args['title'] ?? '') : $fallback);
		}

		$attr	= wpjam_pick($args, ['class', 'style'])+['title'=>$this->page_title];
		$tag	= wpjam_tag(($args['tag'] ?? 'a'), $attr)->add_class($this->class)->style($this->style);

		if($this->redirect){
			$href	= str_replace('%id%', $id, maybe_callback($this->redirect, $id, $args));

			if(!$href){
				return;
			}

			$tag->add_class('list-table-redirect')->attr(['href'=>$href, 'target'=>$this->target]);
		}elseif($this->filter || is_array($this->filter)){
			$filter	= maybe_callback($this->filter, $id);

			if(is_null($filter) || $filter === false){
				return;
			}

			if(!wpjam_is_assoc_array($filter)){
				$filter	= $this->overall ? [] : wpjam_pick((array)$this->get_data($id), (array)$filter);
			}

			$tag->add_class('list-table-filter')->data('filter', array_merge(($this->data ?: []), $filter, $args['data']));
		}else{
			$tag->add_class('list-table-'.(in_array($this->response, ['move', 'move_item']) ? 'move-' : '').'action')->data($this->generate_data_attr($args));
		}

		if(!empty($args['dashicon']) || !empty($args['remixicon'])){
			$text	= wpjam_icon(wpjam_get($args, 'remixicon') ?: 'dashicons-'.$args['dashicon']);
		}elseif(isset($args['title'])){
			$text	= $args['title'];
		}elseif(($this->dashicon || $this->remixicon) && !$tag->has_class('page-title-action') && ($this->layout == 'calendar' || !$this->title)){
			$text	= wpjam_icon($this->remixicon ?: 'dashicons-'.$this->dashicon);
		}else{
			$text	= $this->title ?: $this->page_title;
		}

		return $tag->text($text)->wrap(wpjam_get($args, 'wrap'), $this->name);
	}

	public function generate_data_attr($args=[], $type='button'){
		$data	= wp_parse_args(($args['data'] ?? []), ($this->data ?: []))+($this->layout == 'calendar' ? wpjam_pick($args, ['date']) : []);
		$attr	= ['data'=>$data, 'action'=>$this->name, 'nonce'=>$this->create_nonce($args)];
		$attr	+= $this->overall ? [] : ($args['bulk'] ? wpjam_pick($args, ['ids'])+wpjam_pick($this, ['bulk', 'title']) : wpjam_pick($args, ['id']));

		return $attr+wpjam_pick($this, $type == 'button' ? ['direct', 'confirm'] : ['next']);
	}

	public static function registers($actions){
		foreach($actions as $key => $args){
			self::register($key, $args+['order'=>10.5]);
		}
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_Column extends WPJAM_Register{
	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'style'){
			$value	= $this->column_style ?: $value;
			$value	= ($value && !preg_match('/\{([^\}]*)\}/', $value)) ? 'table.wp-list-table .column-'.$this->name.'{'.$value.'}' : $value;
		}elseif($key == '_field'){
			$value	??= wpjam_field(['type'=>'view', 'wrap_tag'=>'', 'key'=>$this->name, 'options'=>$this->options]);
		}elseif(in_array($key, ['title', 'callback', 'description'])){
			$value	= $this->{'column_'.$key} ?? $value;
		}elseif(in_array($key, ['sortable', 'sticky'])){
			$value	??= $this->{$key.'_column'};
		}

		return $value;
	}

	public function render($value, $filterable, $id){
		if($id !== false && is_callable($this->callback)){
			return wpjam_call($this->callback, $id, $this->name, $value);
		}

		if(is_array($value)){
			return array_map(fn($v)=> $this->render($v, $filterable, false), $value);
		}

		if($value && str_contains($value, '[filter')){
			return $value;
		}

		$filter	= $filterable ? [$this->_name => $value] : [];
		$value	= $this->options ? $this->_field->val($value)->render() : $value;

		return $filter ? ['filter'=>$filter, 'label'=>$value] : $value;
	}

	public static function registers($fields){
		foreach($fields as $key => $field){
			$column	= wpjam_pull($field, 'column');

			if(wpjam_get($field, 'show_admin_column', is_array($column))){
				self::register($key, ($column ?: [])+wpjam_except(wpjam_strip_data_type($field), ['style', 'description'])+['order'=>10.5, '_name'=>$field['name'] ?? $key]);
			}
		}
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_View extends WPJAM_Register{
	public function parse(){
		if($this->_view){
			return $this->_view;
		}

		$cb	= $this->callback;

		if($cb && is_callable($cb)){
			$view	= wpjam_if_error(wpjam_catch($cb, $this->name), null);

			if(!is_array($view)){
				return $view;
			}
		}else{
			$view	= $this->get_args();
		}

		if(!empty($view['label'])){
			$filter	= $view['filter'] ?? [];
			$label	= $view['label'].(is_numeric(wpjam_get($view, 'count')) ? wpjam_tag('span', ['count'], '（'.$view['count'].'）') : '');
			$class	= $view['class'] ?? (array_any($filter, fn($v, $k)=> (((($c = wpjam_get_data_parameter($k)) === null) xor ($v === null)) || $c != $v)) ? '' : 'current');

			return [$filter, $label, $class];
		}
	}

	public static function registers($views){
		foreach(array_filter($views) as $name => $view){
			$name	= is_numeric($name) ? 'view_'.$name : $name;
			$view	= is_array($view) ? wpjam_strip_data_type($view) : $view;
			$view	= (is_string($view) || is_object($view)) ? ['_view'=>$view] : $view;

			self::register($name, $view);
		}
	}
}

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args){
		$screen		= get_current_screen();
		$data_type	= $args['data_type'];

		if($data_type == 'post_type'){
			$args	+= $args['post_type'] == 'attachment' ? [
				'builtin'	=> 'WP_Media_List_Table',
				'hook_part'	=> ['media', 'media']
			] : [
				'builtin'	=> 'WP_Posts_List_Table',
				'hook_part'	=> $args['hierarchical'] ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts']
			];

			add_action('parse_query', [$this, 'on_parse_query']);
		}elseif($data_type == 'taxonomy'){
			$args	+=[
				'builtin'	=> 'WP_Terms_List_Table',
				'hook_part'	=> [$args['taxonomy'], $args['taxonomy']],
			];

			add_action('parse_term_query', [$this, 'on_parse_query'], 0);
		}elseif($data_type == 'user'){
			$args	+= [
				'hook_part'	=> ['users', 'user', 'users'],
				'builtin'	=> 'WP_Users_List_Table'
			];
		}elseif($data_type == 'comment'){
			$args	+= [
				'hook_part'	=> ['comments', 'comment'],
				'builtin'	=> 'WP_Comments_List_Table'
			];
		}

		wpjam_map(wpjam_pick($args, ['data_type', 'meta_type']), fn($v, $k)=> $screen->add_option($k, $v));

		add_filter('manage_'.$screen->id.'_columns', [$this, 'filter_columns']);

		if(isset($args['hook_part'])){
			$part	= $args['hook_part'];
			$num	= in_array($part[0], ['pages', 'posts', 'media', 'comments']) ? 2 : 3;

			add_filter('manage_'.$part[0].'_custom_column', [$this, 'filter_custom_column'], 10, $num);

			add_filter($part[1].'_row_actions', [$this, 'filter_row_actions'], 1, 2);

			if(isset($part[2])){
				add_action('manage_'.$part[2].'_extra_tablenav', [$this, 'extra_tablenav']);
			}
		}

		if(!wp_is_json_request()){
			add_filter('wpjam_html', [$this, 'filter_table']);
		}

		parent::__construct($args);
	}

	public function views(){
		if($this->screen->id != 'upload'){
			$this->views_by_builtin();
		}
	}

	public function display_tablenav($which){
		$this->display_tablenav_by_builtin($which);
	}

	public function get_table(){
		return $this->filter_table($this->ob_get('display_by_builtin'));
	}

	public function prepare_items(){
		if(wp_doing_ajax()){
			if($this->screen->base == 'edit'){
				$_GET['post_type']	= $this->post_type;
			}

			$data	= wpjam_get_data_parameter();
			$_GET	= array_merge($_GET, $data);
			$_POST	= array_merge($_POST, $data);

			$this->prepare_items_by_builtin();
		}
	}

	public function single_row($item){
		if($this->data_type == 'post_type'){
			global $post, $authordata;

			$post	= is_numeric($item) ? get_post($item) : $item;

			if($post){
				$authordata	= get_userdata($post->post_author);

				if($post->post_type == 'attachment'){
					echo wpjam_tag('tr', ['id'=>'post-'.$post->ID], $this->ob_get('single_row_columns_by_builtin', $post))->add_class(['author-'.((get_current_user_id() == $post->post_author) ? 'self' : 'other'), 'status-'.$post->post_status]);
				}else{
					$args	= [$post];
				}
			}
		}elseif($this->data_type == 'taxonomy'){
			$term	= is_numeric($item) ? get_term($item) : $item;
			$args	= $term ? [$term, wpjam_term($term)->level] : [];
		}elseif($this->data_type == 'user'){
			$user	= is_numeric($item) ? get_userdata($item) : $item;
			$args	= $user ? [$user] : [];
		}elseif($this->data_type == 'comment'){
			$comment	= is_numeric($item) ? get_comment($item) : $item;
			$args		= $comment ? [$comment] : [];
		}

		if(!empty($args)){
			echo $this->filter_table($this->ob_get('single_row_by_builtin', ...$args));
		}
	}

	public function on_parse_query($query){
		if(array_any(debug_backtrace(), fn($v)=> wpjam_get($v, 'class') == $this->builtin)){
			$vars	= &$query->query_vars;
			$by		= $vars['orderby'] ?? '';
			$object	= ($by && is_string($by)) ? $this->get_object($by, 'column') : null;
			$type	= $object ? ($object->sortable === true ? 'meta_value' : $object->sortable) : '';
			$vars	= array_merge($vars, ['list_table_query'=>true], in_array($type, ['meta_value_num', 'meta_value']) ? ['orderby'=>$type, 'meta_key'=>$by] : []);
		}
	}

	public static function load($args){
		return new static($args);
	}
}