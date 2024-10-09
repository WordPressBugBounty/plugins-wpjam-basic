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

		foreach($this->get_objects('action', true) as $object){
			if($this->layout == 'calendar' ? (bool)$object->calendar : $object->calendar !== 'only'){
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
		}

		foreach($this->get_objects('view', true) as $object){
			if($view = $object->get_link()){
				$args['views'][$object->name]	= is_array($view) ? $this->get_filter_link(...$view) : $view;
			}
		}

		if($this->layout == 'calendar'){
			$this->query_args	= ['year', 'month', ...wpjam_array($this->query_args)];
		}else{
			if(!$this->builtin_class && !empty($args['bulk_actions'])){
				$args['columns']['cb']	= true;
			}

			foreach($this->get_objects('column', true) as $object){
				$key		= $object->name;
				$style[]	= $object->style;

				$args['columns'][$key]	= $object->title;

				if($object->sortable){
					$args['sortable_columns'][$key] = [$key, true];
				}

				if($object->sticky){
					$args['sticky_columns'][]	= $key;
				}

				if($object->nowrap){
					$args['nowrap_columns'][]	= $key;
				}
			}
		}

		$style[]	= $this->style;

		wp_add_inline_style('list-tables', implode("\n", array_filter($style)));

		wpjam_add_item('page_setting', 'list_table', fn()=> $this->get_setting());

		add_shortcode('filter',		fn($attrs, $title)=> $this->get_filter_link($attrs, $title, wpjam_pull($attrs, 'class')));
		add_shortcode('row_action',	fn($attrs, $title)=> $this->get_row_action(wpjam_pull($attrs, 'name'), $attrs, $title));

		add_filter('views_'.$screen->id, fn($views)=> array_merge($views, $this->views ?: []));

		add_filter('bulk_actions-'.$screen->id, fn($actions)=> array_merge($actions, wp_list_pluck($this->bulk_actions ?: [], 'title')));

		add_filter('manage_'.$screen->id.'_sortable_columns', fn($columns)=> array_merge($columns, ($this->sortable_columns ?: [])));

		$this->_args	= array_merge($this->_args, $args);

		if(!$this->builtin_class){
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
		}elseif($name == '_builtin'){
			if($this->builtin_class){
				return $GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin_class, ['screen'=>$this->screen]);
			}
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if($name == 'page_title_action'){
			return $this->layout == 'left' ? null : $this->get_row_action('add', ['class'=>'page-title-action']);
		}elseif($name == 'primary_key'){
			return $this->$name	= $this->get_primary_key_by_model() ?: 'id';
		}elseif($name == 'search'){
			return (bool)$this->get_searchable_fields_by_model();
		}elseif($name == 'filterable_fields'){
			$filter	= $this->get_filterable_fields_by_model() ?: [];

			return $this->$name	= wp_is_numeric_array($filter) ? array_fill_keys($filter, '') : $filter;
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
		if(str_starts_with($method, 'ob_get_')){
			return wpjam_ob_get_contents([$this, wpjam_remove_prefix($method, 'ob_get_')], ...$args);
		}elseif(str_ends_with($method, '_by_model')){
			$method	= wpjam_remove_postfix($method, '_by_model');
			$cb		= [$this->model, $method];

			if(method_exists(...$cb)){
				if($method == 'value_callback'){
					$result	= wpjam_value_callback($cb, ...$args);

					return is_wp_error($result) ? $args[2] : $result;
				}

				if($method == 'query_items' && wpjam_verify_callback($cb, fn($params)=> count($params) >= 2 && $params[0]->name != 'args')){
					$args	= [$args[0]['number'], $args[0]['offset']];
				}

				return wpjam_catch($cb, ...$args);
			}

			if($method == 'get_views'){
				$cb[1]	= 'views';

				return method_exists(...$cb) ? wpjam_catch($cb, ...$args) : null;
			}elseif($method == 'get_actions'){
				return $this->builtin_class ? [] : WPJAM_Model::get_actions();
			}elseif($method == 'value_callback'){
				return $args[2];
			}elseif($method == 'render_date'){
				return is_string($args[0]) ? $args[0] : '';
			}elseif(in_array($method, [
				'get_fields',
				'get_subtitle',
				'extra_tablenav',
				'before_single_row',
				'after_single_row',
				'col_left'
			])){
				return;
			}

			if(method_exists($this->model, '__callStatic')){
				$result	= wpjam_catch($cb, ...$args);
			}else{
				$result	= new WP_Error('undefined_method', [$this->model.'->'.$method.'()']);
			}

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
				wpjam_map(WPJAM_Fields::flatten($this->get_fields_by_model()), ['WPJAM_List_Table_Column', 'from_field']);
			}elseif($type == 'view'){
				wpjam_map(($this->get_views_by_model() ?: []), ['WPJAM_List_Table_View', 'from_model']);
			}elseif($type == 'action'){
				wpjam_map(($this->actions ?? ($this->get_actions_by_model() ?: [])), fn($v, $k)=> $this->register($k, $v+['order'=>10.5]));

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

				if(get_screen_option('meta_type')){
					$dtype	= $this->data_type;
					$args	= ['list_table'=>true];
					$args	+= ($dtype && in_array($dtype, ['post_type', 'taxonomy'])) ? [$dtype=> $this->$dtype ?? ''] : [];

					wpjam_map(wpjam_get_meta_options(get_screen_option('meta_type'), $args), fn($option)=> $option->register_list_table_action());
				}
			}
		}

		if($force || !isset($this->objects[$type])){
			$dtype	= $this->data_type;
			$args	= $dtype ? ['data_type'=>$dtype]+(in_array($dtype, ['post_type', 'taxonomy']) ? [$dtype=>$this->$dtype] : []) : [];
			$args	= wpjam_map($args, fn($v)=>['value'=>$v, 'if_null'=>true, 'callable'=>true]);

			$this->objects[$type] = self::call_type($type, 'get_registereds', $args);
		}

		return $this->objects[$type];
	}

	protected function get_object($name, $type='action'){
		$objects	= $this->get_objects($type);

		return $objects[$name] ?? wpjam_find($objects, fn($object)=> $object->name == $name);
	}

	public function get_setting(){
		$search	= wpjam_get_data_parameter('s') ?: '';
		$search	= strlen($search) ? sprintf(__('Search results for: %s'),'<strong>'.esc_html($search).'</strong>') : '';

		return [
			'layout'	=> (string)$this->layout,
			'left_key'	=> (string)$this->left_key,
			'sortable'	=> $this->sortable ?: false,
			'subtitle'	=> $this->get_subtitle_by_model().$search,
			'bulk_actions'		=> $this->bulk_actions,
			'sticky_columns'	=> $this->sticky_columns ?: [],
			'nowrap_columns'	=> $this->nowrap_columns ?: []
		];
	}

	protected function get_row_actions($id){
		return array_filter(array_map(fn($v)=> $this->get_row_action($v, ['id'=>$id]), array_diff(($this->row_actions ?: []), ($this->next_actions ?: []))));
	}

	protected function overall_actions(){
		$actions	= array_filter(array_map(fn($v)=> $this->get_row_action($v, ['class'=>'button']), ($this->overall_actions ?: [])));

		return $actions ? wpjam_tag('div', ['alignleft', 'actions', 'overallactions'], implode('', $actions)) : '';
	}

	public function get_row_action($action, $args=[], $title=''){
		$object = $this->get_object($action);

		return $object ? $object->get_row_action(array_merge($args, $title ? ['title'=>$title] : [])) : null;
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

		$item['row_actions']	= $id ? $this->get_row_actions($id)+($this->primary_key == 'id' ? ['id'=>'ID：'.$id] : []):['error'=>'通过 primary_key「'.$this->primary_key.'」未获取到 ID'];

		$this->before_single_row_by_model($raw);

		if(method_exists($this->model, 'render_row')){
			$item	= $this->model::render_row($item, $attr);
		}else{
			$method	= wpjam_find(['render_item', 'item_callback'], fn($v)=> method_exists($this->model, $v));
			$item	= $method ? $this->model::$method($item) : $item;

			if(isset($item['class'])){
				$attr['class']	= $item['class'];
			}
		}

		$row	= wpjam_tag('tr', $attr, $this->ob_get_single_row_columns($item))->add_class(($id && $this->multi_rows) ? 'tr-'.$id : '');

		if($item){
			echo $row;
		}

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		if(explode('-', $date)[1] == $this->month){
			$class	= $date == wpjam_date('Y-m-d') ? ['day', 'today'] : ['day'];
			$item	= $this->render_date_by_model($item, $date);
			$links	= wpjam_tag('div', ['row-actions', 'alignright'])->append(wpjam_map($this->get_row_actions($date), fn($v, $k)=> ['span', [$k], $v]));
		}else{
			$class	= ['day'];
			$links	= '';
		}

		echo wpjam_tag('span', $class, (int)(explode('-', $date)[2]))->after($links)->wrap('div', ['date-meta'])->after('div', ['date-content'], $item);
	}

	protected function parse_id($item){
		return $item[$this->primary_key] ?? null;
	}

	public function get_column_value($id, $name, $value=null){
		$object	= $this->get_object($name, 'column');

		if($object){
			$value	??= $this->value_callback_by_model($name, $id, $object->default);
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
			$field	= $args['field'] ?? '';
			$width	= $args['width'] ?? 60;
			$height	= $args['height'] ?? 60;
			$style	= ['width:'.$width.'px; height:'.$height.'px;'];

			$sortable	= !empty($args['sortable']) ? 'sortable' : '';
			$actions	= [...($sortable ? ['move_item'] : []), ...($args['actions'] ?? ['add_item', 'edit_item', 'del_item'])];
			$has_add	= in_array('add_item', $actions);
			$actions	= array_diff($actions, ['add_item']);

			foreach($items as $i => $item){
				$value	= $item[$key] ?: '';
				$data	= ['_field'=>$field, 'i'=>$i];
				$_args	= ['id'=>$id, 'i'=>$i, 'data'=>$data];
				$tag	= wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>$data, 'class'=>'item']);

				if($type == 'image'){
					$tag->style('width:'.$width.'px;');

					$value	= ($value ? wpjam_tag('img', ['src'=>wpjam_get_thumbnail($value, $width*2, $height*2), 'style'=>$style]) : ' ').(!empty($item['title']) ? wpjam_tag('span', ['item-title'], $item['title']) : '');
				}

				$append[]	= $tag->append([
					$this->get_row_action('move_item', $_args+['style'=>['color'=>$item['color'] ?? null], 'title'=>$value, 'fallback'=>true]),
					['span', ['row-actions'], implode('', array_map(fn($k)=> $this->get_row_action($k, $_args+['wrap'=>wpjam_tag('span', [$k])]), $actions))]
				]);
			}

			if($has_add && (empty($args['max_items']) || count($items) <= $args['max_items'])){
				$append[]	= $this->get_row_action('add_item', ['id'=>$id, 'class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2', 'style'=>$style] : []));
			}

			$value	= wpjam_tag('div', ['class'=>['items', $type.'-list', $sortable], 'style'=>wpjam_get($args, 'style')])->style(empty($args['per_row']) ? '' : 'width:'.($args['per_row']*($width+30)).'px')->append($append);
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
		$form	= ($this->search ? $this->ob_get_search_box('搜索', 'wpjam') : '').$this->get_table();
		$form	= wpjam_tag('form', ['action'=>'#', 'id'=>'list_table_form', 'method'=>'POST'], $form)->before($this->ob_get_views());

		if($this->layout == 'left'){
			$form	= $form->wrap('div', ['list-table', 'col-wrap'])->wrap('div', ['id'=>'col-right']);
			$left	= wpjam_tag('div', ['left', 'col-wrap'], $this->ob_get_col_left())->wrap('div', ['id'=>'col-left']);

			return $form->before($left)->wrap('div', ['id'=>'col-container', 'class'=>'wp-clearfix']);
		}else{
			return wpjam_tag('div', ['list-table', ($this->layout ? 'layout-'.$this->layout : '')], $form);
		}
	}

	public function get_table(){
		if(wp_doing_ajax()){
			$this->prepare_items();
		}

		return $this->filter_display($this->ob_get_display());
	}

	public function display_rows_or_placeholder(){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');
			$ts		= mktime(0, 0, 0, $this->month, 1, $this->year);
			$pad	= calendar_week_mod(date('w', $ts) - $start);
			$days	= date('t', $ts);
			$days	= $days+(($days+$pad) % 7 ? (7 - (($days+$pad) % 7)) : 0);

			for($day=(0-$pad); $day<=$days; ++$day){
				$date	= date('Y-m-d', $ts+$day*DAY_IN_SECONDS);
				$item	= $this->ob_get_single_date($this->items[$date] ?? [], $date);

				$cells[]= ['td', ['id'=>'date-'.$date, 'class'=>in_array($pad+$start, [0, 6, 7]) ? 'weekend' : 'weekday'], $item];

				if($day >= 0){
					$pad++;
				}

				if($pad == 7){
					echo wpjam_tag('tr')->append($cells);

					$cells	= [];
					$pad	= 0;
				}
			}
		}else{
			if($this->has_items()){
				$this->display_rows();
			}

			echo wpjam_tag('td', ['class'=>'colspanchange', 'colspan'=>$this->get_column_count()], $this->ob_get_no_items())->wrap('tr', ['no-items']);
		}
	}

	public function print_column_headers($with_id=true){
		if($this->layout == 'calendar'){
			$start	= (int)get_option('start_of_week');

			for($i = 0; $i <= 6; $i++){
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
		$links	= wpjam_map([
			'prev'	=>['&lsaquo;',	__('Previous page'),	max(1, $paged-1),		$paged == 1],
			'next'	=>['&rsaquo;',	__('Next page'),		min($total, $paged+1),	$paged == $total],
		], function($args, $type){
			[$text, $title, $left_paged, $is]	= $args;

			return $this->get_filter_link(['left_paged'=>$left_paged], $text, ['title'=>$title, 'class'=>['button', $type.'-page', ($is ? 'disabled' : '')]]);
		});

		echo wpjam_tag('span', ['left-pagination-links'])->data('total_pages', $total)->append([
			$links['prev'],
			['span', [], $paged.' / '.number_format_i18n($total)],
			$links['next'],
			wpjam_tag('input', ['type'=>'text', 'value'=>$paged, 'size'=>strlen($total), 'class'=>'current-page'])->after('a', ['button', 'goto'], '&#10132;')->wrap('span')
		])->wrap('div', ['tablenav-pages'])->wrap('div', ['tablenav', 'bottom']);
	}

	public function page_load(){
		if(wp_doing_ajax()){
			wpjam_add_admin_ajax('wpjam-list-table-action',	[$this, 'ajax_response']);
		}else{
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
			$response	= ['type'=>'left', 'left'=>$this->ob_get_col_left()];
		}elseif($type == 'query_item'){
			$response	= ['type'=>'add', 'id'=>wpjam_get_post_parameter('id', ['default'=>''])];
		}else{
			$object		= $this->get_object(wpjam_get_post_parameter('list_action'));
			$response	= $object ? $object->callback($type) : wp_die('无效的操作');
		}

		if($this->layout == 'calendar' && !empty($response['data'])){
			$data	= &$response['data'];
			$data	= wpjam_map(($data['dates'] ?? $data), [$this, 'ob_get_single_date']);
		}

		if(in_array($response['type'], ['list', 'left'])){
			$response['table']	= $this->get_table();
		}else{
			$parser	= function($response){
				$filter	= fn($id)=> $this->filter_display($this->ob_get_single_row($id), $id);

				if(!in_array($response['type'], ['append', 'redirect', 'delete', 'move', 'up', 'down', 'form'])){
					if(!empty($response['bulk'])){
						$ids	= array_filter($response['ids']);
						$data	= $this->get_by_ids_by_model($ids);

						$response['data']	= wpjam_fill($ids, $filter);
					}elseif(!empty($response['id'])){
						$response['data']	= $filter($response['id']);
					}
				}

				return $response;
			};

			if($response['type'] == 'items'){
				if(isset($response['items'])){
					$response['items']	= wpjam_map($response['items'], fn($item, $id)=> $parser(array_merge($item, ['id'=>$id])));
				}
			}else{
				$response	= $parser($response);
			}
		}

		return $response+['views'=>$this->ob_get_views(), 'setting'=>$this->get_setting()];
	}

	public function prepare_items(){
		$keys	= ['orderby', 'order', 's', ...array_keys($this->filterable_fields)];
		$args	= array_filter(wpjam_get_data_parameter($keys), fn($v)=> isset($v));

		if($this->layout == 'calendar'){
			$this->items	= wpjam_try([$this, 'query_calendar_by_model'], $args+['year'=>$this->year, 'month'=>$this->month]);
		}else{
			$_GET	= array_merge($_GET, array_filter(wpjam_get_data_parameter(['orderby', 'order'])));
			$number	= $this->per_page;
			$number	= (!$number || !is_numeric($number)) ? 50 : $number;
			$offset	= ($this->get_pagenum()-1) * $number;
			$result	= wpjam_throw_if_error($this->query_items_by_model($args+['number'=>$number, 'offset'=>$offset]));

			if(wp_is_numeric_array($result)){
				$this->items	= $result;
			}else{
				$this->items	= $result['items'] ?? [];
				$total_items	= $result['total'] ?? 0;
			}

			if(empty($total_items)){
				$total_items	= $number	= count($this->items);
			}

			$this->set_pagination_args(['total_items'=>$total_items, 'per_page'=>$number]);
		}
	}

	protected function get_table_classes(){
		$classes	= parent::get_table_classes();

		if($this->layout == 'calendar'){
			return array_diff($classes, ['striped']);
		}

		return [...array_diff($classes, ($this->fixed ? [] : ['fixed'])), ...($this->sticky_columns ? ['sticky-columns'] : [])];
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
			$filter	= array_filter($this->filterable_fields);
			$filter	= $filter ? wpjam_map($filter, fn($v, $k)=> array_merge($v, ['value'=> wpjam_get_data_parameter($k)])) : '';
			$fields	= ($this->chart ? $this->chart->render(false) : '').($filter ? wpjam_fields($filter)->render(['fields_type'=>'']) : '');

			echo $fields ? wpjam_tag('div', ['alignleft', 'actions'], $fields.get_submit_button('筛选', '', 'filter_action', false)) : '';
			echo $this->layout == 'calendar' ? wpjam_tag('h2')->text(sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($this->month), $this->year)) : '';
		}

		if(!$this->builtin_class){
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
			$d_args	= wpjam_parse_data_type($args);
			$key	= $name.($d_args ? '__'.md5(serialize(array_map(fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, $d_args))) : '');

			if($method == 'register'){
				$args	= [$key, new $class($name, $args)];
			}else{
				$args	= [$key];

				if(!$class::get($key)){
					return did_action('current_screen') ? add_screen_option('remove_'.$type.'s', array_merge((self::get_removed($type)), [$name])) : null;
				}
			}
		}

		return $class::$method(...$args);
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
class WPJAM_List_Table_Action extends WPJAM_Admin_Action{
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
		}elseif($key == 'method'){
			return ($this->name == 'duplicate' && !$this->direct) ? 'insert' : (['add'=>'insert', 'edit'=>'update', 'up'=>'move', 'down'=>'move'][$this->name] ?? $this->name);
		}elseif(in_array($key, ['primary_key', 'layout', 'model', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
			return get_screen_option('list_table', $key);
		}
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
				$cb	= $args['bulk_callback'] ?: (method_exists($this->model, 'bulk_'.$this->name) ? [$this->model, 'bulk_'.$this->name] : null);

				if(!$cb){
					$data	= [];

					foreach($args['ids'] as $id){
						$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));
						$data	= is_array($result) ? wpjam_merge($data, $result) : $data;
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
			'layout'		=> $this->layout,
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
				$response['type']	= $submit_button['response'];

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
				|| ($response['type'] == 'items' && wpjam_find($response['items'], fn($item)=> $item['type'] == 'delete'))
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

		return wpjam_every($ids, fn($id)=> wpjam_current_user_can($this->capability, $id, $this->name));
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
			$data	= !is_callable($cb) ? wp_die(implode('->', $cb).' 未定义') : wpjam_try($cb, $id);
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

		if(method_exists($this->model, 'filter_fields')){
			$fields	= wpjam_try([$this->model, 'filter_fields'], $fields, $arg, $this->name);
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

		return $this->parse_submit_button($button, $name, $render);
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
			}elseif(is_array($show_if) && $args['id']){
				$show_if	= wpjam_parse_show_if($show_if);

				if(!empty($show_if['key']) && !wpjam_if($this->get_data($args['id']), $show_if)){
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

		if(in_array($key, ['title', 'style', 'callback'])){
			$value	= $this->{'column_'.$key} ?: $value;

			if($key == 'style' && $value && !preg_match('/\{([^\}]*)\}/', $value)){
				$value	= '.manage-column.column-'.$this->name.'{ '.$value.' }';
			}
		}elseif(in_array($key, ['sortable', 'sticky', 'nowrap'])){
			$value	??= $this->{$key.'_column'};
		}elseif($key == '_field'){
			$value	??= $this->$key = wpjam_field(['type'=>'view', 'key'=>$this->name, 'options'=>$this->options]);
		}

		return $value;
	}

	public function callback($id, $value){
		if($this->callback && is_callable($this->callback)){
			return wpjam_catch($this->callback, $id, $this->name, $value);
		}

		$parser	= function($value){
			if(!has_shortcode($value, 'filter')){
				$parsed	= $this->options ? $this->_field->val($value)->render(['tag'=>'']) : $value;
				$value	= $this->filterable ? '[filter '.$this->name.'="'.$value.'"]'.$parsed.'[/filter]' : $parsed;
			}

			return $value;
		};

		return is_array($value) ? (wp_is_numeric_array($value) ? implode(',', array_map($parser, $value)) : $value) : $parser($value);
	}

	public static function from_field($field, $key){
		$filter	= get_screen_option('list_table', 'filterable_fields');
		$field	= wpjam_strip_data_type($field);
		$field	= wpjam_except($field, 'style');
		$column	= wpjam_pull($field, 'column');

		if(is_array($column)){
			$field	= array_merge($field, $column);
		}else{
			if(empty($field['show_admin_column'])){
				return;
			}
		}

		return self::register($key, $field+['order'=>10.5, 'filterable'=>isset($filter[$key])]);
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
			$class	= $view['class'] ?? (wpjam_some($filter, fn($v, $k)=> (((wpjam_get_data_parameter($k) === null) xor ($v === null)) || wpjam_get_data_parameter($k) != $v)) ? '' : 'current');

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

		$screen	= get_current_screen();

		wpjam_map(wpjam_slice($args, ['data_type', 'meta_type']), fn($v, $k)=> $screen->add_option($k, $v));

		if(!wp_doing_ajax() && !wp_is_json_request()){
			add_filter('wpjam_html', [$this, 'filter_display']);
		}

		add_filter('manage_'.$screen->id.'_columns', [$this, 'filter_columns']);

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
			$this->_builtin->views();
		}
	}

	public function display(){
		$this->_builtin->display();
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

			$this->_builtin->prepare_items();
		}
	}

	public function filter_columns($columns){
		return wpjam_add_at($columns, -1, $this->get_columns());
	}

	public function filter_custom_column(...$args){
		$value	= $this->get_column_value(...array_reverse($args));

		return count($args) == 2 ? wpjam_echo($value) : $value;
	}

	public function on_parse_query($query){
		if(!in_array($this->builtin_class, array_column(debug_backtrace(), 'class'))){
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
		return true;
	}

	public static function load($screen){
		new static($screen);
	}
}

class WPJAM_Posts_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct($object){
		parent::__construct([
			'title'			=> $object->title,
			'model'			=> $object->model,
			'primary_key'	=> 'ID',
			'singular'		=> 'post',
			'capability'	=> fn($id)=> $id ? 'edit_post' : $object->cap->edit_posts,
			'data_type'		=> 'post_type',
			'meta_type'		=> 'post',
			'post_type'		=> $object->name,
			'builtin_class'	=> $object->name == 'attachment' ? 'WP_Media_List_Table' : 'WP_Posts_List_Table',
			'hook_part'		=> $object->name == 'attachment' ? ['media', 'media'] : ($object->hierarchical ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts'])
		]);

		add_action('parse_query',	[$this, 'on_parse_query']);

		if(!wp_doing_ajax() && $this->page_title_action){
			add_filter('wpjam_html', fn($html)=> preg_replace('/<a href=".*?" class="page-title-action">.*?<\/a>/i', $this->page_title_action, $html));
		}
	}

	public function single_row($item){
		global $post, $authordata;

		$post	= is_numeric($item) ? get_post($item) : $item;

		if($post){
			$authordata	= get_userdata($post->post_author);

			if($post->post_type == 'attachment'){
				echo wpjam_tag('tr', ['id'=>'post-'.$post->ID], wpjam_ob_get_contents([$this->_builtin, 'single_row_columns'], $post))->add_class(['author-'.((get_current_user_id() == $post->post_author) ? 'self' : 'other'), 'status-'.$post->post_status]);
			}else{
				$this->_builtin->single_row($post);
			}
		}
	}

	protected function filter_row_action($object, $post){
		return wpjam_compare(get_post_status($post), ...($object->post_status ? [$object->post_status] : ['!=', 'trash']));
	}
}

class WPJAM_Terms_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct($object){
		parent::__construct([
			'title'			=> $object->title,
			'capability'	=> $object->cap->edit_terms,
			'levels'		=> $object->levels,
			'hierarchical'	=> $object->hierarchical,
			'model'			=> $object->model,
			'sortable'		=> $object->sortable,
			'primary_key'	=> 'term_id',
			'singular'		=> 'tag',
			'data_type'		=> 'taxonomy',
			'meta_type'		=> 'term',
			'taxonomy'		=> $object->name,
			'post_type'		=> $GLOBALS['typenow'],
			'hook_part'		=> [$object->name, $object->name],
			'builtin_class'	=> 'WP_Terms_List_Table'
		]);

		add_action('parse_term_query', [$this, 'on_parse_query'], 0);
	}

	public function get_setting(){
		return parent::get_setting()+['overall_actions'=>$this->overall_actions()];
	}

	public function single_row($item){
		$term	= is_numeric($item) ? get_term($item) : $item;

		if($term){
			$object = wpjam_term($term);
			$level	= $object ? $object->level : 0;

			$this->_builtin->single_row($term, $level);
		}
	}
}

class WPJAM_Users_List_Table extends WPJAM_Builtin_List_Table{
	public function __construct(){
		parent::__construct([
			'title'			=> '用户',
			'singular'		=> 'user',
			'capability'	=> 'edit_user',
			'data_type'		=> 'user',
			'meta_type'		=> 'user',
			'model'			=> 'WPJAM_User',
			'primary_key'	=> 'ID',
			'hook_part'		=> ['users', 'user', 'users'],
			'builtin_class'	=> 'WP_Users_List_Table'
		]);
	}

	public function single_row($item){
		$user	= is_numeric($item) ? get_userdata($item) : $item;

		if($user){
			$this->_builtin->single_row($user);
		}
	}

	protected function filter_row_action($object, $user){
		return (is_null($object->roles) || array_intersect($user->roles, (array)$object->roles));
	}
}