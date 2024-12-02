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

		foreach($this->get_objects('action', true) as $object){
			if(!$object->available){
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
					$args['row_actions'][$key]	= $key;
				}
			}

			if($object->next && $object->response == 'form'){
				$args['next_actions'][$key]	= $object->next;
			}
		}

		foreach($this->get_objects('view', true) as $object){
			if($view = $object->get_link()){
				$args['views'][$object->name]	= is_array($view) ? $this->get_filter_link(...$view) : $view;
			}
		}

		if($this->layout == 'calendar'){
			$this->query_args	= ['year', 'month', ...wpjam_array($this->query_args)];
		}else{
			if(!$this->builtin && !empty($args['bulk_actions'])){
				$args['columns']['cb']	= true;
			}

			foreach($this->get_objects('column', true) as $object){
				$style[]	= $object->style;
				$key		= $object->name;
				$data		= wpjam_fill(['description', 'sticky', 'nowrap', 'format', 'precision', 'conditional_styles'], fn($k)=> $object->$k ?: null);

				$args['columns'][$key]	= $object->title.($data ? wpjam_tag('i', ['data'=>$data]) : '');

				if($object->sortable){
					$args['sortable_columns'][$key] = [$key, true];
				}
			}
		}

		wp_add_inline_style('list-tables', implode("\n", array_filter($style)));

		wpjam_add_item('page_setting', 'list_table', fn()=> $this->get_setting());

		add_shortcode('filter',		fn($attr, $title)=> $this->get_filter_link($attr, $title, wpjam_pull($attr, 'class')));
		add_shortcode('row_action',	fn($attr, $title)=> $this->get_row_action(wpjam_pull($attr, 'name'), ($title ? compact('title') : [])+$attr));

		add_filter('views_'.$screen->id, 						fn($views)=> array_merge($views, $this->views ?: []));
		add_filter('bulk_actions-'.$screen->id,					fn($actions)=> array_merge($actions, $this->bulk_actions ?: []));
		add_filter('manage_'.$screen->id.'_sortable_columns',	fn($columns)=> array_merge($columns, $this->sortable_columns ?: []));

		if(!wp_doing_ajax()){
			$page_title_action	= $this->layout == 'left' ? '' : $this->get_row_action('add', ['class'=>'page-title-action']);

			if($page_title_action || !$this->builtin){
				add_filter('wpjam_html', fn($html)=> preg_replace('/<a[^>]*class="page-title-action">[^>]*<\/a>/i', ($page_title_action ?: ''), $html));
			}
		}

		$this->_args	= array_merge($this->_args, $args);

		if(!$this->builtin){
			parent::__construct($this->_args);
		}
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		if(in_array($name, ['year', 'month'])){
			if($this->layout == 'calendar'){
				$value	= (int)wpjam_get_data_parameter($name) ?: wpjam_date($name == 'year' ? 'Y' : 'm');

				return clamp($value, ...($name == 'year' ? [1970, 2200] : [1, 12]));
			}
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if($name == 'primary_key'){
			return $this->$name	= $this->get_primary_key_by_model() ?: 'id';
		}elseif($name == 'search'){
			return (bool)$this->get_searchable_fields_by_model();
		}elseif($name == 'actions'){
			return $this->get_actions_by_model() ?: [];
		}elseif($name == 'views'){
			return $this->get_views_by_model() ?: [];
		}elseif($name == 'fields'){
			return $this->$name = WPJAM_Fields::parse($this->get_fields_by_model(), ['flat'=>true]);
		}elseif($name == 'filterable_fields'){
			$fields	= $this->get_filterable_fields_by_model() ?: [];
			$fields	= wp_is_numeric_array($fields) ? array_fill_keys($fields, '') : $fields;

			return $this->$name = array_merge($fields, wpjam_filter($this->fields, ['filterable'=>true]));
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
		$type	= array_find(['builtin', 'model'], fn($v)=> str_ends_with($method, '_by_'.$v));
		$method	= $type ? explode('_by', $method)[0] : $method;

		if($type == 'builtin'){
			if($this->builtin){
				$GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin, ['screen'=>$this->screen]);

				return [$GLOBALS['wp_list_table'], $method](...$args);
			}	
		}elseif($type == 'model'){
			$exists	= fn($method)=> method_exists($this->model, $method);
			$cb		= [$this->model, $method];

			if($exists($method)){
				if($method == 'query_items' && wpjam_verify_callback($cb, fn($params)=> count($params) >= 2 && $params[0]->name != 'args')){
					$args	= array_values(array_slice($args[0], 0, 2));
				}

				return wpjam_catch($cb, ...$args);
			}

			if($method == 'get_views'){
				return $exists('views') ? $this->views_by_model() : [];
			}elseif($method == 'get_actions'){
				return $this->builtin ? [] : WPJAM_Model::get_actions();
			}elseif(in_array($method, [
				'get_fields',
				'get_subtitle',
				'get_summary',
				'extra_tablenav',
				'before_single_row',
				'after_single_row',
				'col_left'
			])){
				return;
			}

			$result	= $exists('__callStatic') ? wpjam_catch($cb, ...$args) : new WP_Error('undefined_method', $cb);

			if(!is_wp_error($result) || !in_array($method, [
				'get_filterable_fields',
				'get_searchable_fields',
				'get_primary_key',
			])){
				return $result;
			}
		}

		return parent::__call($method, $args);
	}

	protected function get_objects($type='action', $force=false){
		if(!isset($this->objects[$type])){
			if($type == 'column'){
				wpjam_map($this->fields, ['WPJAM_List_Table_Column', 'from_field']);
			}elseif($type == 'view'){
				wpjam_map($this->views, ['WPJAM_List_Table_View', 'from_model']);
			}elseif($type == 'action'){
				wpjam_map($this->actions, fn($v, $k)=> $this->register($k, $v+['order'=>10.5]));

				if($this->sortable){
					$sortable	= is_array($this->sortable) ? $this->sortable : ['items'=>' >tr'];
					$sortable	= array_merge($sortable, (get_screen_option('sortable') ?: []));
					$action		= wpjam_pull($sortable, 'action') ?: [];

					wpjam_map([
						'move'	=> ['page_title'=>'拖动',	'dashicon'=>'move'],
						'up'	=> ['page_title'=>'向上移动',	'dashicon'=>'arrow-up-alt'],
						'down'	=> ['page_title'=>'向下移动',	'dashicon'=>'arrow-down-alt'],
					], fn($v, $k)=> $this->register($k, $action+$v+['direct'=>true]));

					$this->sortable	= $sortable;
				}

				if($meta_type = get_screen_option('meta_type')){
					wpjam_map(wpjam_get_meta_options($meta_type, ['list_table'=>true]+wpjam_except(wpjam_parse_data_type($this), 'data_type')), fn($v)=> $v->register_list_table_action());
				}
			}
		}

		if($force || !isset($this->objects[$type])){
			$this->objects[$type] = self::call_type($type, 'get_registereds', wpjam_map(wpjam_parse_data_type($this), fn($v)=>['value'=>$v, 'if_null'=>true, 'callable'=>true]));
		}

		return $this->objects[$type];
	}

	protected function get_object($name, $type='action'){
		$objects	= $this->get_objects($type);

		return $objects[$name] ?? array_find($objects, fn($v)=> $v->name == $name);
	}

	public function get_filterable_fields(){
		$fields	= $this->chart ? $this->chart->get_fields(['show_title'=>true]) : [];

		return $fields+wpjam_array($this->filterable_fields, function($k, $v){
			if($v && wpjam_get($v, 'type') != 'view'){
				if(wpjam_get($v, 'type') == 'select'){
					$v['show_option_all']	??= '全部'.$v['title'];
				}

				$v['value']	= wpjam_get_data_parameter($k);

				return [$k, wpjam_except($v, 'required')];
			}
		});
	}

	public function get_filter(){
		return array_filter(wpjam_get_data_parameter(array_keys($this->filterable_fields)), fn($v)=> isset($v));
	}

	public function get_setting(){
		$s	= wpjam_get_data_parameter('s') ?: '';

		return [
			'subtitle'		=> $this->get_subtitle_by_model().($s ? sprintf(__('Search results for: %s'), '<strong>'.esc_html($s).'</strong>') : ''),
			'summary'		=> $this->get_summary_by_model(),
			'sortable'		=> $this->sortable,
			'bulk_actions'	=> $this->bulk_actions
		];
	}

	protected function get_row_actions($id, $args=[]){
		[$names, $args]	= is_array($id) ? [$id, $args] : [array_diff(($this->row_actions ?: []), ($this->next_actions ?: [])), ['id'=>$id]+$args];

		return array_filter(array_map(fn($name)=> $this->get_row_action($name, $args), $names));
	}

	protected function overall_actions(){
		$actions	= $this->get_row_actions(($this->overall_actions ?: []), ['class'=>'button']);

		return $actions ? wpjam_tag('div', ['alignleft', 'actions', 'overallactions'])->append($actions) : '';
	}

	public function get_row_action($name, $args=[]){
		if($object = $this->get_object($name)){
			return $object->get_row_action($args);
		}
	}

	public function get_filter_link($filter, $label, $attr=[]){
		$args	= array_diff(($this->query_args ?: []), array_keys($filter));
		$filter	= array_merge($filter, wpjam_get_data_parameter($args));

		return wpjam_tag('a', $attr, $label)->add_class('list-table-filter')->data('filter', ($filter ?: new stdClass()));
	}

	public function single_row($item){
		if(!is_array($item)){
			if($item instanceof WPJAM_Register){
				$item	= $item->to_array();
			}else{
				$item	= $this->get_by_model($item);
				$item 	= is_wp_error($item) ? null : ($item ? (array)$item : $item);
			}
		}

		if(!$item){
			return;
		}

		$raw	= $item;
		$id		= $this->parse_id($item);
		$attr	= $id ? ['id'=>$this->singular.'-'.str_replace('.', '-', $id), 'data'=>['id'=>$id]] : [];

		$item['row_actions']	= $id ? $this->get_row_actions($id)+($this->primary_key == 'id' ? ['id'=>'ID：'.$id] : []) : ['error'=>'Primary Key「'.$this->primary_key.'」不存在'];

		$this->before_single_row_by_model($raw);

		$method	= array_find(['render_row', 'render_item', 'item_callback'], fn($v)=> method_exists($this->model, $v));

		if($method){
			$item	= $this->model::$method($item, $attr);
			$attr	+= $method != 'render_row' && isset($item['class']) ? ['class'=>$item['class']] : [];
		}

		if($item){
			echo wpjam_tag('tr', $attr, $this->ob_get('single_row_columns', $item))->add_class($this->multi_rows ? 'tr-'.$id : '')."\n";
		}

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		$tag	= wpjam_tag('span', ['day'], (int)explode('-', $date)[2]);

		if(explode('-', $date)[1] == $this->month){
			$links	= wpjam_tag('div', ['row-actions', 'alignright'])->append($this->get_row_actions($date, ['wrap'=>'<span class="%s"></span>']));
			$tag	= $tag->add_class($date == wpjam_date('Y-m-d') ? 'today' : '')->after($links);
			$cb		= [$this->model, 'render_date'];
			$item	= method_exists(...$cb) ? $cb($item, $date) : (is_string($item) ? $item : '');
		}

		echo $tag->wrap('div', ['date-meta'])->after('div', ['date-content'], $item);
	}

	protected function parse_id($item){
		return $item[$this->primary_key] ?? null;
	}

	public function get_column_value($id, $name, $value=null){
		$object	= $this->get_object($name, 'column');

		if($object){
			$cb		= $this->value_callback === false ? false : [$this->model, 'value_callback'];
			$value	??= ($cb && method_exists(...$cb)) ? wpjam_value_callback($cb, $name, $id) : $object->default;
			$value	= $object->callback($id, $value);
		}

		if(!is_array($value)){
			return $value;
		}

		$wrap	= wpjam_pull($value, 'wrap');

		if(isset($value['row_action'])){
			$value	= $this->get_row_action($value['row_action'], array_merge(array_get($value, 'args', []), ['id'=>$id]));
		}elseif(isset($value['filter'])){
			$value	= $this->get_filter_link(wpjam_pull($value, 'filter'), wpjam_pull($value, 'label'), $value);
		}elseif(isset($value['items'])){
			$items	= $value['items'];
			$args	= $value['args'] ?? [];
			$type	= $args['item_type'] ?? 'image';
			$key	= $args[$type.'_key'] ?? $type;
			$width	= $args['width'] ?? 60;
			$height	= $args['height'] ?? 60;
			$style	= ['width:'.$width.'px; height:'.$height.'px;'];
			$names	= $args['actions'] ?? ['add_item', 'edit_item', 'del_item'];
			$value	= wpjam_tag('div', ['class'=>'items '.$type.'-list'])->style(wpjam_get($args, 'style'))->style('width', empty($args['per_row']) ? '' : ($args['per_row']*($width+30)).'px');

			if(!empty($args['sortable'])){
				$value->add_class('sortable');

				array_unshift($names, 'move_item');
			}

			foreach($items as $i => $item){
				$v		= $item[$key] ?: '';
				$data	= ['_field'=>($args['field'] ?? ''), 'i'=>$i];
				$_args	= ['id'=>$id, 'i'=>$i, 'data'=>$data];
				$tag	= wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>$data, 'class'=>'item']);

				if($type == 'image'){
					$tag->style('width', $width.'px');

					$v	= ($v ? wpjam_tag('img', ['src'=>wpjam_get_thumbnail($v, $width*2, $height*2), 'style'=>$style]) : ' ').(!empty($item['title']) ? wpjam_tag('span', ['item-title'], $item['title']) : '');
				}

				$value->append($tag->append([
					$this->get_row_action('move_item', $_args+['style'=>wpjam_slice($item, 'color'), 'title'=>$v, 'fallback'=>true]),
					wpjam_tag('span', ['row-actions'])->append($this->get_row_actions(array_diff($names, ['add_item']), $_args+['wrap'=>'<span class="%s"></span>']))
				]));
			}

			if(in_array('add_item', $names) && (empty($args['max_items']) || count($items) <= $args['max_items'])){
				$value->append($this->get_row_action('add_item', ['id'=>$id, 'class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2', 'style'=>$style] : [])));
			}
		}else{
			$value	= '';
		}

		return (string)wpjam_wrap($value, $wrap);
	}

	public function column_default($item, $name){
		$value	= $item[$name] ?? null;
		$id		= $this->parse_id($item);

		return $id ? $this->get_column_value($id, $name, $value) : $value;
	}

	public function column_cb($item){
		$id	= $this->parse_id($item);

		if(wpjam_current_user_can($this->capability, $id)){
			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>'cb-select-'.$id, 'title'=>'选择'.strip_tags($item[$this->get_primary_column_name()] ?? $id)]);
		}

		return wpjam_icon('dashicons-minus');
	}

	public function render(){
		$table	= ($this->search ? $this->ob_get('search_box', '搜索', 'wpjam') : '').$this->get_table();
		$form	= wpjam_tag('form', ['id'=>'list_table_form'], $table)->before($this->ob_get('views'))->wrap('div', ['list-table', 'layout-'.$this->layout]);

		return $this->layout == 'left' ? wpjam_tag('div', ['id'=>'col-container', 'class'=>'wp-clearfix'])->append([
			['div', ['id'=>'col-left'], wpjam_tag('div', ['left', 'col-wrap'], $this->ob_get('col_left'))->data('left_key', $this->left_key)],
			['div', ['id'=>'col-right'], $form->add_class('col-wrap')]
		]) : $form;
	}

	public function get_table(){
		if(wp_doing_ajax()){
			$this->prepare_items();
		}

		return $this->filter_display($this->ob_get('display'));
	}

	public function display_rows_or_placeholder(){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');
			$ts		= mktime(0, 0, 0, $this->month, 1, $this->year);
			$pad	= calendar_week_mod(date('w', $ts) - $start);
			$days	= date('t', $ts);
			$days	= $days+7-(($days+$pad) % 7 ?: 7);
			$cells	= [];

			for($day=(0-$pad); $day<=$days; ++$day){
				$date	= date('Y-m-d', $ts+$day*DAY_IN_SECONDS);
				$class	= in_array(count($cells)+$start, [0, 6, 7]) ? 'weekend' : 'weekday';

				$cells[]	= ['td', ['id'=>'date-'.$date, 'class'=>$class], $this->ob_get('single_date', $this->items[$date] ?? [], $date)];

				if(count($cells) == 7){
					echo wpjam_tag('tr')->append($cells);

					$cells	= [];
				}
			}
		}else{
			if($this->has_items()){
				$this->display_rows();
			}

			echo wpjam_tag('td', ['class'=>'colspanchange', 'colspan'=>$this->get_column_count()], $this->ob_get('no_items'))->wrap('tr', ['no-items']);
		}
	}

	public function print_column_headers($with_id=true){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');

			for($i=0; $i<=6; $i++){
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

		if(!$result || !is_array($result)){
			return;
		}

		$total	= wpjam_get($result, 'total_pages') ?: (wpjam_get($result, 'per_page') ? ceil(wpjam_get($result, 'total_items')/wpjam_get($result, 'per_page')) : 0);

		if($total <= 1){
			return;
		}

		$paged	= (int)wpjam_get_data_parameter('left_paged') ?: 1;
		$link	= fn($type, $text, $left_paged, $disabled)=> $this->get_filter_link(compact('left_paged'), $text, ['button', $type.'-page', ($disabled ? 'disabled' : '')]);

		echo wpjam_tag('span', ['left-pagination-links'])->data('total_pages', $total)->append([
			$link('prev', '&lsaquo;', max(1, $paged-1), $paged == 1)->attr('title', __('Previous page')),
			['span', [], $paged.' / '.number_format_i18n($total)],
			$link('next', '&rsaquo;', min($total, $paged+1), $paged == $total)->attr('title', __('Next page')),
			wpjam_tag('input', ['type'=>'text', 'value'=>$paged, 'size'=>strlen($total), 'class'=>'current-page'])->after('a', ['button', 'goto'], '&#10132;')->wrap('span')
		])->wrap('div', ['tablenav-pages'])->wrap('div', ['tablenav', 'bottom']);
	}

	public function page_load(){
		if(wp_doing_ajax()){
			return wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);
		}

		$export_action	= wpjam_get_parameter('export_action');

		if($export_action){
			$object	= $this->get_object($export_action);

			return $object ? $object->callback('export') : wp_die('无效的导出操作');
		}

		$result = wpjam_catch([$this, 'prepare_items']);

		if(is_wp_error($result)){
			wpjam_add_admin_error($result);
		}
	}

	public function ajax_response(){
		$type	= wpjam_get_post_parameter('action_type');
		$parts	= parse_url(wpjam_get_referer() ?: wp_die('非法请求'));

		if($parts['host'] == $_SERVER['HTTP_HOST']){
			$_SERVER['REQUEST_URI']	= $parts['path'];
		}

		if($type == 'query_items'){
			$response	= ['type'=>'list'];
		}elseif($type == 'left'){
			$response	= ['type'=>'left', 'left'=>$this->ob_get('col_left')];
		}elseif($type == 'query_item'){
			$response	= ['type'=>'add', 'id'=>wpjam_get_post_parameter('id', ['default'=>''])];
		}else{
			$object		= $this->get_object(wpjam_get_post_parameter('list_action'));
			$response	= $object ? $object->callback($type) : wp_die('无效的操作');
		}

		return $this->parse_response($response)+['views'=>$this->ob_get('views'), 'setting'=>$this->get_setting()];
	}

	protected function parse_response($response){
		if($this->layout == 'calendar' && !empty($response['data'])){
			$response['data']	= wpjam_map(($response['data']['dates'] ?? $response['data']), fn($v)=> $this->ob_get('single_date', $v));
		}

		if(in_array($response['type'], ['list', 'left'])){
			$response['table']	= $this->get_table();
		}elseif($response['type'] == 'items'){
			if(isset($response['items'])){
				$response['items']	= wpjam_map($response['items'], fn($item, $id)=> $this->parse_response(array_merge($item, ['id'=>$id])));
			}
		}else{
			$render	= fn($id)=> $this->filter_display($this->ob_get('single_row', $id), $id);

			if(!in_array($response['type'], ['append', 'redirect', 'delete', 'move', 'up', 'down', 'form'])){
				if(!empty($response['bulk'])){
					$ids	= array_filter($response['ids']);
					$data	= $this->get_by_ids_by_model($ids);

					$response['data']	= array_map(fn($id)=> ['id'=>$id, 'data'=>$render($id)], $ids);
				}elseif(!empty($response['id'])){
					$response['data']	= $render($response['id']);
				}
			}
		}

		return $response;
	}

	public function prepare_items(){
		$args	= array_filter(wpjam_get_data_parameter(['orderby', 'order', 's']), fn($v)=> isset($v));
		$_GET	= array_merge($_GET, $args);
		$args	+= $this->get_filter();

		if($this->layout == 'calendar'){
			return $this->items	= wpjam_try([$this, 'query_calendar_by_model'], $args+['year'=>$this->year, 'month'=>$this->month]);
		}

		$number	= $this->per_page;
		$number	= (!$number || !is_numeric($number)) ? 50 : $number;
		$offset	= ($this->get_pagenum()-1)*$number;
		$args	= compact('number', 'offset')+$args;

		if($chart = $this->chart){
			$keys	= $chart->show_start_date ? ['start_date', 'end_date'] : ($chart->show_date ? ['date'] : []);
			$args	= array_merge($args, wpjam_fill($keys, fn($k)=> $chart->get_parameter($k)));
		}

		$result	= wpjam_throw_if_error($this->query_items_by_model($args));

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

	protected function get_table_classes(){
		$classes	= parent::get_table_classes();
		$removed	= ['', ($this->fixed ? '' : 'fixed'), ($this->layout == 'calendar' ? 'striped' : '')];

		return array_diff([...$classes, ($this->nowrap ? 'nowrap' : '')], $removed);
	}

	protected function get_default_primary_column_name(){
		return $this->primary_column;
	}

	protected function handle_row_actions($item, $column_name, $primary){
		return ($primary === $column_name && !empty($item['row_actions'])) ? $this->row_actions($item['row_actions'], false) : '';
	}

	public function get_columns(){
		return wpjam_except($this->columns ?: [], $this->get_removed('column'));
	}

	public function extra_tablenav($which='top'){
		if($which == 'top'){
			$fields	= wpjam_map($this->get_filterable_fields(), fn($v)=> wpjam_except($v+['placeholder'=>wpjam_get($v, 'title')], ['title', 'before', 'after']));

			echo $fields ? wpjam_tag('div', ['alignleft', 'actions'], wpjam_fields($fields)->render(['fields_type'=>'']).get_submit_button('筛选', '', 'filter_action', false)) : '';

			echo $this->layout == 'calendar' ? wpjam_tag('h2', [], sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($this->month), $this->year)) : '';
		}

		if(!$this->builtin){
			$this->extra_tablenav_by_model($which);

			do_action(wpjam_get_filter_name($this->plural, 'extra_tablenav'), $which);
		}

		if($which == 'top'){
			echo $this->overall_actions();
		}
	}

	public function current_action(){
		return wpjam_get_request_parameter('list_action') ?? parent::current_action();
	}

	public function filter_display($html, $id=null){
		if($id){
			$row	= apply_filters('wpjam_single_'.($this->layout == 'calendar' ? 'date' : 'row'), $html, $id);
			$row	= str_replace('[row_action ', '[row_action '.($this->layout == 'calendar' ? 'date' : 'id').'="'.$id.'" ', $row);

			return wpjam_do_shortcode($row, ['filter', 'row_action']);
		}

		$pattern	= $this->layout == 'calendar' ? '/<td id="date-(.*?)".*?>.*?<\/td>/is' : '/<tr id="'.$this->singular.'-(.*?)".*?>.*?<\/tr>/is';

		return preg_replace_callback($pattern, fn($m)=> $this->filter_display($m[0], $m[1]), $html);
	}

	public static function call_type($type, $method, ...$args){
		$class	= 'WPJAM_List_Table_'.$type;

		if(in_array($method, ['register', 'unregister'])){
			$name	= $args[0];
			$args	= $args[1];
			$_args	= wpjam_parse_data_type($args);
			$key	= $name.($_args ? '__'.md5(serialize(array_map(fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, $_args))) : '');

			if($method == 'register'){
				$args	= [$key, new $class($name, $args)];
			}else{
				$args	= [$key];

				if(!$class::get($key)){
					return did_action('current_screen') ? add_screen_option('remove_'.$type.'s', array_merge((self::get_removed($type)), [$name])) : null;
				}
			}
		}

		return [$class, $method](...$args);
	}

	public static function register($name, $args, $type='action'){
		if($type == 'action' && !empty($args['overall']) && $args['overall'] !== true){
			self::register($name.'_all', array_merge($args, ['overall'=>true, 'title'=>$args['overall']]));

			unset($args['overall']);
		}

		return self::call_type($type, 'register', $name, $args);
	}

	public static function unregister($name, $args=[], $type='action'){
		return self::call_type($type, 'unregister',$name, $args);
	}

	protected static function get_removed($type){
		return get_screen_option('remove_'.$type.'s') ?: [];
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
			return ($this->overall && $this->name != 'add') ? 'list' : $this->name;
		}elseif($key == 'overall'){
			return $this->name == 'add' && $this->layout == 'left';
		}elseif($key == 'row_action'){
			return ($this->bulk !== 'only' && $this->name != 'add');
		}elseif($key == 'next_action'){
			return self::get($this->next) ?: '';
		}elseif($key == 'prev_action'){
			$prev	= $this->prev ?: array_search($this->name, ($this->next_actions ?: []));

			return self::get($prev) ?: '';
		}elseif($key == 'available'){
			return $this->layout == 'calendar' ? $this->calendar : $this->calendar !== 'only';
		}elseif($key == 'method'){
			return ($this->name == 'duplicate' && !$this->direct) ? 'insert' : (['add'=>'insert', 'edit'=>'update', 'up'=>'move', 'down'=>'move'][$this->name] ?? $this->name);
		}elseif(in_array($key, ['layout', 'model', 'primary_key', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
			return get_screen_option('list_table', $key);
		}
	}

	public function __toString(){
		return $this->title;
	}

	public function jsonSerialize(){
		return array_filter($this->generate_data_attr(['bulk'=>true]));
	}

	protected function parse_arg($args){
		if($this->overall){
			return;
		}elseif(wpjam_is_assoc_array($args)){
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

			if(!$args['bulk'] && !$args['callback']){
				$cb	= [$this->model, $this->method];

				if($cb[1] == 'insert' || $this->overall || $this->response == 'add'){
					array_shift($cb_args);
				}else{
					if(method_exists(...$cb)){
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

						if($cb && $args['fields']){
							$cb_args[]	= $args['fields']->get_defaults();
						}
					}
				}

				return wpjam_try($cb, ...$cb_args) ?? true ;
			}

			if(!$args['bulk']){
				$cb	= $args['callback'];

				if($this->overall || ($this->response == 'add' && !is_null($args['data']) && wpjam_verify_callback($cb, fn($params)=> count($params) == 1 || $params[0]->name == 'data'))){
					array_shift($cb_args);
				}
			}else{
				$cb	= $args['bulk_callback'];

				if(!$cb){
					$cb	= [$this->model, 'bulk_'.$this->name];
					$cb	= method_exists(...$cb) ? $cb : null;
				}

				if(!$cb){
					$data	= [];

					foreach($args['ids'] as $id){
						$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));
						$data	= wpjam_merge($data, is_array($result) ? $result : []);
					}

					return $data ?: true;
				}
			}

			$errmsg	= '「'.$this->title.'」的回调函数';
			$result	= is_callable($cb) ? wpjam_try($cb, ...[...$cb_args, $this->name, $args['submit_name']]) : wp_die($errmsg.'无效');

			return !is_null($result) ? $result : wp_die($errmsg.'没有正确返回');
		}

		$type	= $args;
		$data	= $type == 'export' ? (wpjam_get_parameter('data') ?: []) : wpjam_get_data_parameter();
		$args	= $form_args = ['data'=>$data]+wpjam_map([
			'id'	=> ['default'=>''],
			'bulk'	=> ['sanitize_callback'=>fn($v)=> ['true'=>1, 'false'=>0][$v] ?? $v],
			'ids'	=> ['sanitize_callback'=>'wp_parse_args', 'default'=>[]]
		], fn($v, $k)=> wpjam_get_parameter($k, $v+['method'=>($type == 'export' ? 'get' : 'post')]));

		if(in_array($type, ['submit', 'direct']) && ($this->export || ($this->bulk === 'export' && $args['bulk']))){
			return ['type'=>'redirect', 'url'=>add_query_arg(array_filter($args)+['export_action'=>$this->name, '_wpnonce'=>$this->create_nonce($args)], $GLOBALS['current_admin_url'])];
		}

		if(!$this->is_allowed($args)){
			wp_die('access_denied');
		}

		['id'=>$id, 'bulk'=>$bulk]	= $args;

		$response	= [
			'list_action'	=> $this->name,
			'page_title'	=> $this->page_title,
			'type'	=> $type == 'form' ? $type : $this->response,
			'last'	=> (bool)$this->last,
			'width'	=> (int)$this->width,
			'bulk'	=> &$bulk,
			'id'	=> &$id,
			'ids'	=> $args['ids']
		];

		if($type == 'form'){
			return $response+['form'=>$this->get_form($form_args, $type)];
		}

		if(!$this->verify_nonce($args)){
			wp_die('invalid_nonce');
		}

		$bulk	= (int)$bulk === 2 ? 0 : $bulk;
		$cbs	= ['callback', 'bulk_callback'];
		$args	+= wpjam_fill($cbs, fn($k)=> $this->$k);
		$fields	= $submit_name = $result = null;;

		if($type == 'submit'){
			$fields	= $this->get_fields($args, true, 'object');
			$data	= $fields->validate($data);

			if($this->response == 'form'){
				$form_args['data']	= $data;
			}else{
				$form_args['data']	= wpjam_get_post_parameter('defaults', ['sanitize_callback'=>'wp_parse_args', 'default'=>[]]);
				$submit_name		= wpjam_get_post_parameter('submit_name', ['default'=>$this->name]);
				$submit_button		= $this->get_submit_button($args, $submit_name);
				$response['type']	= $submit_button['response'] ?? $response['type'];

				$args	= array_merge($args, array_filter(wpjam_slice($submit_button, $cbs)));
			}
		}

		if($this->response != 'form'){
			$result	= $this->callback(array_merge($args, compact('data', 'fields', 'submit_name')));

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
		[$prev, $object]	= ($type == 'submit' && $this->next) ? [$this->response == 'form' ? $this : null, $this->next_action] : [null, $this];

		$id		= ($args['bulk'] || $object->overall) ? null : $args['id'];
		$fields	= ['id'=>$id, 'data'=>$args['data']];

		if($id){
			if($type != 'submit' || $this->response != 'form'){
				$data	= $object->get_data($id, false, true);
				$data	= is_array($data) ? array_merge($args['data'], $data) : $data;
				$fields	= array_merge($fields, ['data'=>$data]);
			}

			$cb		= [$this->model, 'value_callback'];
			$fields	+= ['meta_type'=>get_screen_option('meta_type')]+(method_exists(...$cb) ? ['value_callback'=>$cb] : []);
		}

		$fields	= array_merge($fields, $object->value_callback ? ['value_callback'=>$object->value_callback] : []);
		$fields	= $object->get_fields($args, false, 'object')->render($fields, false);
		$prev	= $prev ?: $object->prev_action;

		if($prev && $id && $type == 'form'){
			$args['data']	= array_merge($args['data'], $prev->get_data($id, true));
		}

		$form	= $fields->wrap('form', ['id'=>'list_table_action_form', 'data'=>$object->generate_data_attr($args, 'form')]);
		$button	= ($prev ? $prev->get_row_action(['class'=>['button'], 'title'=>'上一步']+$args) : '').$object->get_submit_button($args);

		return $button ? $form->append('p', ['submit'], $button) : $form;
	}

	public function get_fields($args, $include_prev=false, $output=''){
		if($this->direct){
			return [];
		}

		$fields	= wpjam_throw_if_error($this->fields);
		$arg	= $this->parse_arg($args);
		$fields	= is_callable($fields) ? wpjam_try($fields, $arg, $this->name) : $fields;
		$fields	= $fields ?: wpjam_try([$this->model, 'get_fields'], $this->name, $arg);
		$fields	= is_array($fields) ? $fields : [];
		$fields	= array_merge($fields, ($include_prev && $this->prev_action) ? $this->prev_action->get_fields($arg, true, '') : []);
		$cb		= [$this->model, 'filter_fields'];

		if(method_exists(...$cb)){
			$fields	= wpjam_try($cb, $fields, $arg, $this->name);
		}else{
			if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
				$fields[$this->primary_key]['type']	= 'view';
			}
		}

		return $output == 'object' ? wpjam_fields($fields) : $fields;
	}

	public function get_submit_button($args, $name=null, $render=null){
		if(!$name && $this->next && $this->response == 'form'){
			return get_submit_button('下一步', 'primary', 'next', false);
		}

		if(!is_null($this->submit_text)){
			$button	= $this->submit_text;
			$button	= is_callable($button) ? wpjam_try($button, $this->parse_arg($args), $this->name) : $button;
		}else{
			$button = wp_strip_all_tags($this->title) ?: $this->page_title;
		}

		$button	= (!$button || is_array($button) ? $button : [$this->name => $button]);

		return WPJAM_Admin::parse_submit_button($button, $name, $render);
	}

	public function get_row_action($args=[]){
		$args		+= ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]];
		$show_if	= $this->show_if;

		if($show_if){
			if(is_callable($show_if)){
				$result	= wpjam_catch($show_if, $args['id'], $this->name);

				if(is_wp_error($result) || !$result){
					return '';
				}
			}elseif($args['id']){
				$show_if	= wpjam_parse_show_if($show_if);

				if($show_if && !wpjam_if($this->get_data($args['id']), $show_if)){
					return '';
				}
			}
		}

		if(!$this->is_allowed($args)){
			return isset($args['fallback']) ? ($args['fallback'] === true ? wpjam_get($args, 'title') : (string)$args['fallback']) : '';
		}

		$attr	= wpjam_slice($args, ['class', 'style'])+['title'=>$this->page_title];
		$tag	= wpjam_tag(($args['tag'] ?? 'a'), $attr)->add_class($this->class)->style($this->style);

		if($this->redirect){
			$href	= $this->redirect;
			$href	= is_callable($href) ? $href($args['id'], $args) : str_replace('%id%', $args['id'], $href);

			if(!$href){
				return '';
			}

			$tag->add_class('list-table-redirect')->attr(['href'=>$href, 'target'=>$this->target]);
		}elseif($this->filter || is_array($this->filter)){
			if(is_callable($this->filter)){
				$cb		= $this->filter;
				$item	= $cb($args['id']);

				if(is_null($item) || $item === false){
					return '';
				}
			}elseif(wpjam_is_assoc_array($this->filter)){
				$item	= $this->filter;
			}elseif(!$this->overall){
				$item	= wpjam_slice((array)$this->get_data($args['id']), $this->filter);
			}

			$tag->add_class('list-table-filter')->data('filter', array_merge(($this->data ?: []), ($item ?? []), $args['data']));
		}else{
			$tag->add_class('list-table-'.(in_array($this->response, ['move', 'move_item']) ? 'move-' : '').'action')->data($this->generate_data_attr($args));
		}

		if(!empty($args['dashicon']) || !empty($args['remixicon'])){
			$text	= wpjam_icon(!empty($args['dashicon']) ? 'dashicons-'.$args['dashicon'] : $args['remixicon']);
		}elseif(isset($args['title'])){
			$text	= $args['title'];
		}elseif(($this->dashicon || $this->remixicon) && !$tag->has_class('page-title-action') && ($this->layout == 'calendar' || !$this->title)){
			$text	= wpjam_icon($this->remixicon ?: 'dashicons-'.$this->dashicon);
		}else{
			$text	= $this->title ?: $this->page_title;
		}

		return (string)$tag->text($text)->wrap(array_get($args, 'wrap'), $this->name);
	}

	public function generate_data_attr($args=[], $type='button'){
		$data	= wp_parse_args(($args['data'] ?? []), ($this->data ?: []))+($this->layout == 'calendar' ? wpjam_slice($args, 'date') : []);
		$attr	= ['data'=>$data, 'action'=>$this->name, 'nonce'=>$this->create_nonce($args)];
		$attr	+= $this->overall ? [] : (empty($args['bulk']) ? wpjam_slice($args, 'id') : wpjam_slice($args, 'ids')+['bulk'=>$this->bulk, 'title'=>$this->title]);

		return $attr+($type == 'button' ? ['direct'=>$this->direct, 'confirm'=>$this->confirm] : ['next'=>$this->next]);
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
		}elseif(in_array($key, ['title', 'callback', 'description'])){
			$value	= $this->{'column_'.$key} ?? $value;
		}elseif(in_array($key, ['sortable', 'sticky'])){
			$value	??= $this->{$key.'_column'};
		}elseif($key == '_field'){
			$value	??= $this->$key = wpjam_field(['type'=>'view', 'tag'=>'', 'key'=>$this->name, 'options'=>$this->options]);
		}

		return $value;
	}

	public function callback($id, $value){
		if($this->callback && is_callable($this->callback)){
			return wpjam_catch($this->callback, $id, $this->name, $value);
		}

		$value	= is_array($value) ? $value : [$value];

		return wp_is_numeric_array($value) ? implode(',', array_map(function($v){
			if(!$v || !has_shortcode($v, 'filter')){
				$parsed	= $this->options ? $this->_field->val($v)->render() : $v;
				$v		= $this->filterable ? '[filter '.$this->name.'="'.$v.'"]'.$parsed.'[/filter]' : $parsed;
			}

			return $v;
		}, $value)) : $value;
	}

	public static function from_field($field, $key){
		$filter	= get_screen_option('list_table', 'filterable_fields');
		$field	= wpjam_strip_data_type($field);
		$field	= wpjam_except($field, ['style', 'description']);
		$column	= wpjam_pull($field, 'column');
		$show	= $field['show_admin_column'] ?? null;
		$show	= is_null($show) ? is_array($column) : $show;

		if($show){
			return self::register($key, array_merge($field, $column ?: [])+['order'=>10.5, 'filterable'=>isset($filter[$key])]);
		}
	}
}

/**
* @config orderby
**/
#[config('orderby')]
class WPJAM_List_Table_View extends WPJAM_Register{
	public function get_link(){
		if($this->_view){
			return $this->_view;
		}

		$cb	= $this->callback;

		if($cb && is_callable($cb)){
			$view	= wpjam_catch($cb, $this->name);

			if(is_wp_error($view)){
				return;
			}elseif(!is_array($view)){
				return $view;
			}
		}else{
			$view	= $this->get_args();
		}

		if(!empty($view['label'])){
			$filter	= $view['filter'] ?? [];
			$count	= $view['count'] ?? '';
			$label	= $view['label'].(is_numeric($count) ? wpjam_tag('span', ['count'], '（'.$count.'）') : '');
			$class	= $view['class'] ?? (array_any($filter, fn($v, $k)=> (((wpjam_get_data_parameter($k) === null) xor ($v === null)) || wpjam_get_data_parameter($k) != $v)) ? '' : 'current');

			return [$filter, $label, $class];
		}
	}

	public static function from_model($args, $name){
		if(!$args){
			return;
		}

		$name	= is_numeric($name) ? 'view_'.$name : $name;
		$args	= is_array($args) ? wpjam_strip_data_type($args) : $args;
		$args	= (is_string($args) || is_object($args)) ? ['_view'=>$args] : $args;

		return self::register($name, $args);
	}
}

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args){
		$this->page_load();

		$screen		= get_current_screen();
		$data_type	= $args['data_type'];

		if($data_type == 'post_type'){
			$args	+= [
				'builtin'	=> $args['post_type'] == 'attachment' ? 'WP_Media_List_Table' : 'WP_Posts_List_Table',
				'hook_part'	=> $args['post_type'] == 'attachment' ? ['media', 'media'] : ($args['hierarchical'] ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts']),
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

		wpjam_map(wpjam_slice($args, ['data_type', 'meta_type']), fn($v, $k)=> $screen->add_option($k, $v));

		if(!wp_doing_ajax() && !wp_is_json_request()){
			add_filter('wpjam_html', [$this, 'filter_display']);
		}

		add_filter('manage_'.$screen->id.'_columns', fn($columns)=> wpjam_add_at($columns, -1, $this->get_columns()));

		if(isset($args['hook_part'])){
			$part	= $args['hook_part'];
			$num	= in_array($part[0], ['pages', 'posts', 'media', 'comments']) ? 2 : 3;

			add_filter('manage_'.$part[0].'_custom_column',	[$this, 'filter_custom_column'], 10, $num);
			add_filter($part[1].'_row_actions',				[$this, 'filter_row_actions'], 1, 2);

			if(isset($part[2])){
				add_action('manage_'.$part[2].'_extra_tablenav',	[$this, 'extra_tablenav']);
			}
		}

		parent::__construct($args);
	}

	public function views(){
		if($this->screen->id != 'upload'){
			$this->prepare_items();
			$this->views_by_builtin();
		}
	}

	public function display(){
		$this->display_by_builtin();
	}

	public function prepare_items(){
		if(wp_doing_ajax() && !$this->_prepared){
			$this->_prepared	= true;

			if($this->screen->base == 'edit'){
				$_GET['post_type']	= $this->post_type;
			}

			$data	= wpjam_get_data_parameter();
			$_GET	= array_merge($_GET, $data);
			$_POST	= array_merge($_POST, $data);

			$this->prepare_items_by_builtin();
		}
	}

	public function filter_custom_column(...$args){
		$value	= $this->get_column_value(...array_reverse($args));

		return count($args) == 2 ? wpjam_echo($value) : $value;
	}

	public function on_parse_query($query){
		if(!in_array($this->builtin, array_column(debug_backtrace(), 'class'))){
			return;
		}

		$vars		= &$query->query_vars;
		$vars		+=['list_table_query'=>true];
		$orderby	= $vars['orderby'] ?? '';
		$object		= ($orderby && is_string($orderby)) ? $this->get_object($orderby, 'column') : null;

		if($object){
			$type	= $object->sortable ?? 'meta_value';
			$vars	= array_merge($vars, in_array($type, ['meta_value_num', 'meta_value']) ? ['orderby'=>$type, 'meta_key'=>$orderby] : ['orderby'=>$orderby]);
		}
	}

	public function filter_row_actions($actions, $item){
		$id			= $item->{$this->primary_key};
		$actions	= wpjam_except($actions, $this->get_removed('action'));
		$actions	+= wpjam_filter($this->get_row_actions($id), fn($v, $k)=> $v && $this->filter_row_action($this->get_object($k), $item));
		$actions	+= wpjam_pull($actions, ['delete', 'trash', 'spam', 'remove', 'view'])+['id'=>'ID: '.$id];

		return $actions;
	}

	protected function filter_row_action($object, $item){
		if($this->data_type == 'post_type'){
			return wpjam_compare(get_post_status($item), ...($object->post_status ? [$object->post_status] : ['!=', 'trash']));
		}elseif($this->data_type == 'user'){
			return (is_null($object->roles) || array_intersect($user->roles, (array)$object->roles));
		}

		return true;
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
			$this->single_row_by_builtin(...$args);
		}
	}

	public function get_setting(){
		return parent::get_setting()+($this->data_type == 'taxonomy' ? ['overall_actions'=>$this->overall_actions()] : []);
	}

	public static function load($screen){
		return new static($screen);
	}
}