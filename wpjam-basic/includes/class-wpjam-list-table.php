<?php
if(!class_exists('WP_List_Table')){
	include ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class WPJAM_List_Table extends WP_List_Table{
	use WPJAM_Call_Trait;

	public function __construct($args=[]){
		add_screen_option('list_table', ($GLOBALS['wpjam_list_table']	= $this));

		wp_doing_ajax() && wpjam_get_post_parameter('action_type') == 'query_items' && ($_REQUEST	= wpjam_get_data_parameter()+$_REQUEST);	// 兼容

		$this->screen	= $screen = get_current_screen();
		$this->_args	= $args+['screen'=>$screen];

		array_map([$this, 'component'], ['action', 'view', 'column']);

		wpjam_admin('style', $this->style);
		wpjam_admin('vars[list_table]', fn()=> $this->get_setting());
		wpjam_admin('vars[page_title_action]', fn()=> $this->get_action('add', ['class'=>'page-title-action']) ?: '');

		add_filter('views_'.$screen->id, [$this, 'filter_views']);
		add_filter('bulk_actions-'.$screen->id, [$this, 'filter_bulk_actions']);
		add_filter('manage_'.$screen->id.'_sortable_columns', [$this, 'filter_sortable_columns']);

		$this->builtin ? $this->page_load() : parent::__construct($this->_args);
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		if(in_array($name, ['year', 'month']) && $this->layout == 'calendar'){
			return clamp((int)wpjam_get_data_parameter($name) ?: wpjam_date($name == 'year' ? 'Y' : 'm'), ...($name == 'year' ? [1970, 2200] : [1, 12]));
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if(in_array($name, ['primary_key', 'actions', 'views', 'fields', 'filterable_fields', 'searchable_fields'])){
			$value	= wpjam_if_error([$this, 'get_'.$name.'_by_model'](), []) ?: [];

			if($name == 'primary_key'){
				return $this->$name	= $value ?: 'id';
			}elseif($name == 'fields'){
				return $this->$name = WPJAM_Fields::parse($value, ['flat'=>true]);
			}elseif($name == 'filterable_fields'){
				$value	= wpjam_filter($this->fields, ['filterable'=>true])+array_fill_keys($value, []);
				$value	= wpjam_map($value, fn($v)=> $v ? [(wpjam_get($v, 'type') === 'select' || wpjam_get($v, '_type') === 'mu-select' ? 'show_option_all' : 'placeholder') => wpjam_pull($v, 'title')]+wpjam_except($v, ['before', 'after', 'required', 'show_admin_column']) : $v);

				return $this->$name = $value+((array_filter($value) && !$this->builtin && $this->sortable_columns) ? [
					'orderby'	=> ['options'=>[''=>'排序']+wpjam_map(array_intersect_key($this->columns, $this->sortable_columns), 'wp_strip_all_tags')],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				] : []);
			}

			return $value ;
		}elseif(in_array($name, ['params', 'form_data'])){
			$data	= ($name == 'form_data' || wp_doing_ajax()) ? wp_parse_args(wpjam_get_post_parameter($name) ?: []) : wpjam_get_parameter();
			$value	= $data && ($fields	= array_filter($this->filterable_fields)) ? wpjam_if_error(wpjam_fields($fields)->catch('validate', $data), []) : [];

			return $this->$name	= array_filter($value, fn($v)=> is_array($v) ? $v : isset($v)) + (wpjam_admin('chart', 'get_data', ['data'=>$data]) ?: []);
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
			return wpjam_get($this->_args, ...$args);
		}elseif($method == 'add'){
			[$key, $value]	= count($args) >= 3 ? [$args[0].'['.$args[1].']', $args[2]] : [$args[0].'[]', $args[1]];
			$this->_args	= wpjam_set($this->_args, $key, $value);

			return $value;
		}elseif($method == 'exists'){
			return method_exists($this->model, $args[0]);
		}elseif(try_remove_suffix($method, '_by_builtin')){
			return $this->builtin ? [$GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin, ['screen'=>$this->screen]), $method](...$args) : null;
		}elseif(try_remove_suffix($method, '_by_model')){
			$object	= WPJAM_Invoker::create($this->model);
			$method	= $method ?: array_shift($args);

			if($object->exists($method)){
				$method == 'query_items' && $object->verify($method, fn($params)=> count($params) >= 2 && $params[0]->name != 'args') && ($args	= array_values(array_slice($args[0], 0, 2)));
			}else{
				if($method == 'get_views'){
					return $object->call('views') ?: [];
				}elseif($method == 'get_actions'){
					return $this->builtin ? [] : WPJAM_Model::get_actions();
				}elseif($method == 'render_date'){
					return is_string($args[0]) ? $args[0] : '';
				}elseif(in_array($method, ['get_fields', 'value_callback', 'get_subtitle', 'get_summary', 'extra_tablenav', 'before_single_row', 'after_single_row', 'col_left', 'views'])){
					return;
				}
			}

			return $object->call($method, ...$args);
		}elseif(try_remove_prefix($method, 'filter_')){
			if($method == 'table'){
				return wpjam_preg_replace('#<tr id="'.$this->singular.'-(\d+)"[^>]*>(.+?)</tr>#is', fn($m)=> $this->filter_single_row($m[0], $m[1]), $args[0]);
			}elseif($method == 'single_row'){
				return wpjam_do_shortcode(apply_filters('wpjam_single_row', ...$args), [
					'filter'		=> fn($attr, $title)=> $this->get_filter_link($attr, $title, wpjam_pull($attr, 'class')),
					'row_action'	=> fn($attr, $title)=> $this->get_row_action($args[1], ($title || is_numeric($title) ? compact('title') : [])+$attr)."\n"
				]);
			}elseif($method == 'custom_column'){
				return count($args) == 2 ? wpjam_echo($this->column_default('', ...$args)) : $this->column_default(...$args);
			}

			$value	= $this->$method ?: [];

			if($method == 'columns'){
				return wpjam_except(($args ? wpjam_add_at($args[0], -1, $value) : $value), wpjam_admin('removed_columns[]'));
			}elseif($method == 'row_actions'){
				$args[1]= $this->layout == 'calendar' ? $args[1] : ['id'=>$this->parse_id($args[1])];
				$value	= array_diff($value, ($this->next_actions ?: []));
				$value	= wpjam_except($args[0]+$this->get_actions($value, $args[1]), wpjam_admin('removed_actions[]'));
				$value	+= $this->builtin ? wpjam_pull($value, ['delete', 'trash', 'spam', 'remove', 'view']) : [];

				return $value+($this->primary_key == 'id' || $this->builtin ? ['id'=>'ID: '.$args[1]['id']] : []);
			}

			return array_merge($args[0], $value);
		}

		return parent::__call($method, $args);
	}

	protected function component($type, ...$args){
		if($args){
			return $this->objects[$type][$args[0]] ?? array_find($this->objects[$type], fn($v)=> $v->name == $args[0]);
		}

		$args	= WPJAM_Data_Type::prepare($this);

		if($type == 'action'){
			if($this->sortable){
				$sortable	= is_array($this->sortable) ? $this->sortable : [];
				$action		= (wpjam_pull($sortable, 'action') ?: [])+wpjam_except($sortable, ['items']);

				$this->sortable	= ['items'=> $sortable['items'] ?? ' >tr'];
				$this->actions	+= wpjam_map([
					'move'	=> ['page_title'=>'拖动',	'dashicon'=>'move'],
					'up'	=> ['page_title'=>'向上移动',	'dashicon'=>'arrow-up-alt'],
					'down'	=> ['page_title'=>'向下移动',	'dashicon'=>'arrow-down-alt'],
				], fn($v)=> $action+$v+['direct'=>true]);
			}

			$meta_type	= WPJAM_Meta_Type::get(wpjam_admin('meta_type'));
			$meta_type	&& $meta_type->register_actions(wpjam_except($args, 'data_type'));
		}elseif($type == 'column'){
			if($this->layout == 'calendar'){
				return wpjam_map(['year', 'month'], fn($v)=> $this->add('query_args', $v));
			}

			$this->bulk_actions && !$this->builtin && $this->add('columns', 'cb', true);

			$no	= $this->numberable; 
			$no && $this->add('columns', 'no', $no === true ? 'No.' : $no) && wpjam_admin('style', '.column-no{width:42px;}');
		}

		$class	= 'WPJAM_List_Table_'.$type;
		[$class, 'registers']($type == 'column' ? $this->fields : $this->{$type.'s'});

		$args	= array_map(fn($v)=> ['value'=>$v, 'if_null'=>true, 'callable'=>true], $args);
		$args	+= $type == 'action' ? ['calendar'=> $this->layout == 'calendar' ? true : ['compare'=>'!==', 'value'=>'only']] : [];

		foreach($this->add('objects', $type, [$class, 'get_registereds']($args)) as $object){
			$key	= $object->name;

			if($type == 'action'){
				if($object->overall){
					$this->add('overall_actions', $key);
				}else{
					$object->bulk && $object->is_allowed() && $this->add('bulk_actions', $key, $object);
					$object->row_action && $this->add('row_actions', $key);
				}

				$object->next && $this->add('next_actions', $key, $object->next);
			}elseif($type == 'view'){
				$view	= $object->parse();
				$view	&& $this->add('views', $key, is_array($view) ? $this->get_filter_link(...$view) : $view);
			}else{
				$data	= array_filter($object->pick(['description', 'sticky', 'nowrap', 'format', 'precision', 'conditional_styles']));

				$this->add('columns', $key, $object->title.($data ? wpjam_tag('i', ['data'=>$data]) : ''));

				$object->sortable && $this->add('sortable_columns', $key, [$key, true]);

				wpjam_admin('style', $object->style);
			}
		}
	}

	protected function get_setting(){
		$s	= wpjam_get_data_parameter('s') ?: '';

		return [
			'subtitle'	=> $this->get_subtitle_by_model().($s ? sprintf(__('Search results for: %s'), '<strong>'.esc_html($s).'</strong>') : ''),
			'summary'	=> $this->get_summary_by_model(),
			'sortable'	=> $this->sortable,

			'column_count'		=> $this->get_column_count(),
			'bulk_actions'		=> wpjam_map($this->bulk_actions ?: [], fn($object)=> array_filter($object->generate_data_attr(['bulk'=>true]))),
			'overall_actions'	=> array_values($this->get_actions(array_diff($this->overall_actions ?: [], ($this->next_actions ?: [])), ['class'=>'button overall-action']))
		];
	}

	protected function get_actions($names, $args=[]){
		return wpjam_fill($names ?: [], fn($k)=> $this->get_action($k, $args));
	}

	public function get_action($name, ...$args){
		return ($object = $this->component('action', $name)) && $args ? $object->render($args[0]) : $object;
	}

	public function get_row_action($id, $args=[]){
		return $this->get_action(...(isset($args['name']) ? [wpjam_pull($args, 'name'), $args+['id'=>$id]] : [$id, $args]));
	}

	public function get_filter_link($filter, $label, $attr=[]){
		$filter	+= wpjam_get_data_parameter($this->query_args ?: []);

		return wpjam_tag('a', $attr, $label)->add_class('list-table-filter')->data('filter', $filter ?: new stdClass());
	}

	public function single_row($item){
		if(!is_array($item)){
			if($item instanceof WPJAM_Register){
				$item	= $item->to_array();
			}else{
				$item	= wpjam_if_error($this->get_by_model($item), wp_doing_ajax() ? 'throw' : null);
				$item	= $item ? (array)$item : $item;
			}
		}

		if(!$item){
			return;
		}

		$raw	= $item;
		$id		= $this->parse_id($item);
		$attr	= $id ? ['id'=>$this->singular.'-'.str_replace('.', '-', $id), 'data'=>['id'=>$id]] : [];

		$item['row_actions']	= $id ? $this->filter_row_actions([], $item) : ($this->row_actions ? ['error'=>'Primary Key「'.$this->primary_key.'」不存在'] : []);

		$this->before_single_row_by_model($raw);

		$method	= array_find(['render_row', 'render_item', 'item_callback'], fn($v)=> $this->exists($v));
		$item	= $method ? [$this->model, $method]($item, $attr) : $item;
		$attr	+= $method && $method != 'render_row' && isset($item['class']) ? ['class'=>$item['class']] : [];

		echo $item ? $this->filter_single_row(wpjam_tag('tr', $attr, $this->ob_get('single_row_columns', $item))->add_class($this->multi_rows ? 'tr-'.$id : ''), $id)."\n" : '';

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		$parts	= explode('-', $date);
		$tag	= wpjam_tag('span', ['day', $date == wpjam_date('Y-m-d') ? 'today' : ''], (int)$parts[2])->wrap('div', ['date-meta']);

		$parts[1] == $this->month && $tag->append(wpjam_tag('div', ['row-actions', 'alignright'])->append($this->filter_row_actions([], ['id'=>$date, 'wrap'=>'<span class="%s"></span>'])));

		echo $tag->after('div', ['date-content'], $this->render_date_by_model($item, $date));
	}

	protected function parse_id($item){
		return wpjam_get($item, $this->primary_key);
	}

	protected function parse_cell($cell, $id){
		if(!is_array($cell)){
			return $cell;
		}

		$wrap	= wpjam_pull($cell, 'wrap');

		if(isset($cell['row_action'])){
			$cell	= $this->get_row_action($id, ['name'=>wpjam_pull($cell, 'row_action')]+$cell);
		}elseif(isset($cell['filter'])){
			$cell	= $this->get_filter_link(wpjam_pull($cell, 'filter'), wpjam_pull($cell, 'label'), $cell);
		}elseif(isset($cell['items'])){
			$items	= $cell['items'];
			$args	= $cell['args'] ?? [];
			$type	= $args['item_type'] ?? 'image';
			$key	= $args[$type.'_key'] ?? $type;
			$width	= $args['width'] ?? 60;
			$height	= $args['height'] ?? 60;
			$names	= $args['actions'] ?? ['add_item', 'edit_item', 'del_item'];
			$field	= $args['field'] ?? '';
			$cell	= wpjam_tag('div', ['items', $type.'-list'])->data(['field'=>$field, 'width'=>$width, 'height'=>$height, 'per_row'=>wpjam_get($args, 'per_row')])->style(wpjam_get($args, 'style'));

			if(!empty($args['sortable'])){
				$cell->add_class('sortable');

				array_unshift($names, 'move_item');
			}

			$_args	= ['id'=>$id,'data'=>['_field'=>$field]];
			$add	= in_array('add_item', $names) && (empty($args['max_items']) || count($items) <= $args['max_items']);
			$add && $cell->append($this->get_action('add_item', $_args+['class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2'] : [])));

			foreach(array_reverse($items, true) as $i => $item){
				$v	= $item[$key] ?: '';
				$v	= $type == 'image' ? wpjam_tag('img', ['src'=>wpjam_get_thumbnail($v, $width*2, $height*2), 'style'=>['width'=>$width.'px;', 'height'=>$height.'px;']])->after('span', ['item-title'], $item['title'] ?? '') : $v;

				$_args['i']	= $_args['data']['i']	= $i;

				$cell->prepend(wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>['i'=>$i], 'class'=>'item'])->append([
					$this->get_action('move_item', $_args+['title'=>$v, 'fallback'=>true])->style(wpjam_pick($item, ['color'])),
					wpjam_tag('span', ['row-actions'])->append($this->get_actions(array_diff($names, ['add_item']), $_args+['wrap'=>'<span class="%s"></span>', 'item'=>$item]))
				]));
			}
		}else{
			$cell	= $cell['text'] ?? '';
		}

		return (string)wpjam_wrap($cell, $wrap);
	}

	public function column_default($item, $name, $id=null){
		$data	= isset($id) ? [$name=>$item] : $item;
		$id		??= $this->parse_id($item);
		$object	= $this->component('column', $name);

		if(!$id || !$object){
			return $data[$name] ?? null;
		}

		$args	= ['data'=>$data, 'id'=>$id]+($this->value_callback === false ? [] : ['value_callback'=>[$this, 'value_callback_by_model']]);
		$value	= $object->_field->val(null)->value_callback($args) ?? wpjam_value_callback($args, $name) ?? $object->default;
		$value	= wpjam_is_assoc_array($value) ? $value : $object->render($value, $id, $data);

		return wp_is_numeric_array($value) ? implode(',', array_map(fn($v)=> $this->parse_cell($v, $id), $value)) : $this->parse_cell($value, $id);
	}

	public function column_cb($item){
		if(($id	= $this->parse_id($item)) && wpjam_current_user_can($this->capability, $id)){
			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>'cb-select-'.$id, 'title'=>'选择'.strip_tags($item[$this->get_primary_column_name()] ?? $id)]);
		}
	}

	public function column_no(){
		static $no	= 0;
		return $no	+= 1;
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
				$cells[]	= ['td', ['id'=>'date_'.$date, 'class'=>$class], $this->ob_get('single_date', $this->items[$date] ?? [], $date)];
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
			echo wpjam_tag('span', ['pagination-links'])->append(wpjam_map([
				['prev',	'&lsaquo;',	($this->month == 1 ? [$this->year-1, 12] : [$this->year, $this->month-1])],
				['current',	'今日',		array_map('wpjam_date', ['Y', 'm'])],
				['next',	'&rsaquo;',	($this->month == 12 ? [$this->year+1, 1] : [$this->year, $this->month+1])],
			], function($args){
				return "\n".$this->get_filter_link(array_combine(['year', 'month'], $args[2]), $args[1], [
					'class'	=> [$args[0].'-month', 'button'],
					'title'	=> sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($args[2][1]), $args[2][0])
				]);
			}))->wrap('div', ['tablenav-pages']);
		}else{
			parent::pagination($which);
		}
	}

	public function col_left(){
		$result	= $this->col_left_by_model();
		$total	= ($result && is_array($result)) ? (wpjam_get($result, 'total_pages') ?: (($per_page = wpjam_get($result, 'per_page')) ? ceil(wpjam_get($result, 'total_items')/$per_page) : 0)) : 0;

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

	public function page_load(){
		if(wp_doing_ajax()){
			return wpjam_add_admin_ajax('wpjam-list-table-action',	[
				'callback'		=> [$this, 'callback'],
				'nonce_action'	=> fn($data)=> ($object = $this->get_action($data['list_action'] ?? '')) ? $object->parse_nonce_action($data) : null
			]);
		}

		if($action	= wpjam_get_parameter('export_action')){
			return ($object	= $this->get_action($action)) ? $object->callback('export') : wp_die('无效的导出操作');
		}

		wpjam_if_error($this->catch('prepare_items'), fn($result)=> wpjam_add_admin_error($result));
	}

	public function callback($data){
		$type	= $data['action_type'];
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
			$object		= $this->get_action($data['list_action'] ?? '');
			$response	= $object ? $object->callback($type) : wp_die('无效的操作');
		}

		if(!in_array($response['type'], ['form', 'append', 'redirect', 'move', 'up', 'down'])){
			$this->prepare_items();

			$response	= $this->parse_response($response)+['params'=>$this->params, 'setting'=>$this->get_setting(), 'views'=>$this->ob_get('views'), 'search_box'=>$this->get_search_box()];

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
			if($this->layout == 'calendar'){
				if(!empty($response['data'])){
					$response['data']	= wpjam_map(($response['data']['dates'] ?? $response['data']), fn($v, $k)=> $this->ob_get('single_date', $v, $k));
				}
			}else{
				if(!empty($response['bulk'])){
					$ids	= array_filter($response['ids']);
					$data	= $this->get_by_ids_by_model($ids);

					$response['data']	= array_map(fn($id)=> ['id'=>$id, 'data'=>$this->ob_get('single_row', $id)], $ids);
				}elseif(!empty($response['id'])){
					$response['data']	= $this->ob_get('single_row', $response['id']);
				}
			}
		}

		return $response;
	}

	public function prepare_items(){
		$args	= array_filter(wpjam_get_data_parameter(['orderby', 'order', 's']), fn($v)=> isset($v));
		$_GET	= array_merge($_GET, $args);
		$args	+= $this->params+wpjam_array($this->filterable_fields, fn($k, $v)=> [$k, $v ? null : wpjam_get_data_parameter($k)], true);

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

			empty($total_items) && ($total_items	= $number	= count($this->items));

			$this->set_pagination_args(['total_items'=>$total_items, 'per_page'=>$number]);
		}
	}

	protected function get_table_classes(){
		return array_merge(array_diff(parent::get_table_classes(), ($this->fixed ? [] : ['fixed']), ($this->layout == 'calendar' ? ['striped'] : [])), $this->nowrap ? ['nowrap'] : []);
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
			$fields	= array_merge(wpjam_admin('chart', 'get_fields') ?: [], array_filter($this->filterable_fields));

			echo $fields ? wpjam_fields($fields)->render(['fields_type'=>'', 'data'=>$this->params])->after(get_submit_button('筛选', '', 'filter_action', false))->wrap('div', ['actions']) : '';

			echo $this->layout == 'calendar' ? wpjam_tag('h2', [], sprintf(__('%1$s %2$d'), $GLOBALS['wp_locale']->get_month($this->month), $this->year)) : '';
		}

		if(!$this->builtin){
			$this->extra_tablenav_by_model($which);

			do_action(wpjam_get_filter_name($this->plural, 'extra_tablenav'), $which);
		}
	}

	public function current_action(){
		return wpjam_get_request_parameter('list_action') ?? parent::current_action();
	}
}

class WPJAM_List_Table_Component extends WPJAM_Register{
	public static function call_group($method, ...$args){
		$group	= static::get_group(['name'=>strtolower(static::class), 'config'=>['orderby'=>'order']]);

		if(in_array($method, ['add_object', 'remove_object'])){
			$name		= $args[0];
			$args[0]	= $name.WPJAM_Data_Type::prepare($args[1], 'key');

			if($method == 'add_object'){
				if($group->name == 'wpjam_list_table_action' && !empty($args[1]['overall']) && $args[1]['overall'] !== true){
					static::call_group($method, $name.'_all', array_merge($args[1], ['overall'=>true, 'title'=>$args[1]['overall']]));

					unset($args[1]['overall']);
				}

				$args[1]	= new static($name, $args[1]);
			}else{
				if(!static::get($args[0])){
					return wpjam_admin(str_replace('wpjam_list_table', 'removed', $group->name).'s[]', $name);
				}
			}
		}

		return [$group, $method](...$args);
	}
}

class WPJAM_List_Table_Action extends WPJAM_List_Table_Component{
	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'page_title'){
			return $this->title ? wp_strip_all_tags($this->title.get_screen_option('list_table', 'title')) : '';
		}elseif($key == 'response'){
			return $this->next ? 'form' : ($this->overall && $this->name != 'add' ? 'list' : $this->name);
		}elseif($key == 'row_action'){
			return ($this->bulk !== 'only' && $this->name != 'add');
		}elseif($key == 'next_action'){
			return self::get($this->next) ?: '';
		}elseif($key == 'prev_action'){
			$prev	= $this->prev ?: array_search($this->name, ($this->next_actions ?: []));

			return self::get($prev) ?: '';
		}elseif(in_array($key, ['layout', 'model', 'builtin', 'form_data', 'primary_key', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
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
			return (int)$args['bulk'] === 2 ? (!empty($args['id']) ? $args['id'] : $args['ids']) : ($args['bulk'] ? $args['ids'] : $args['id']);
		}

		return $args;
	}

	public function parse_nonce_action($args){
		return wpjam_join('-', $this->name, empty($args['bulk']) ? ($args['id'] ?? '') : '');
	}

	public function callback($args){
		if(is_array($args)){
			$cb_args	= [$this->parse_arg($args), $args['data']];

			if(in_array($this->name, ['up', 'down'])){
				$cb_args[1]	= ($cb_args[1] ?? [])+[$this->name=>true];
				$this->name	= 'move';
			}

			$cb	= $args[($args['bulk'] ? 'bulk_' : '').'callback'] ?? '';

			if($cb && !$args['bulk']){
				if($this->overall){
					array_shift($cb_args);
				}elseif($this->response == 'add' && !is_null($args['data'])){
					$ref	= wpjam_get_reflection($cb);
					$params	= wpjam_if_error($ref, null) ? $ref->getParameters() : [];

					if(count($params) <= 1 || $params[0]->name == 'data'){
						array_shift($cb_args);
					}
				}
			}

			if($cb){
				return wpjam_if_error(wpjam_call($cb, ...[...$cb_args, $this->name, $args['submit_name']]), 'throw') ?? wp_die('「'.$this->title.'」的回调函数无效或没有正确返回');
			}

			if($args['bulk']){
				$cb	= [$this->model, 'bulk_'.$this->name];

				if(method_exists(...$cb)){
					return wpjam_try($cb, ...$cb_args) ?? true ;
				}

				$data	= [];

				foreach($args['ids'] as $id){
					$result	= $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]));
					$data	= wpjam_merge($data, is_array($result) ? $result : []);
				}

				return $data ?: true;
			}

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
					array_unshift($cb_args, wpjam_admin('meta_type'));

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

		$type	= $args;
		$data	= $type == 'export' ? (wpjam_get_parameter('data') ?: []) : wpjam_get_data_parameter();
		$data	+= $type == 'direct' && $this->overall ? $this->form_data : [];
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

			!empty($submit_button['response']) && ($response['type'] = $submit_button['response']);
		}else{
			$submit_name	= null;
			$submit_button	= [];
		}

		if(in_array($type, ['submit', 'direct']) && (
			$this->export 
			|| ($type == 'submit' && !empty($submit_button['export'])) 
			|| ($this->bulk === 'export' && $args['bulk'])
		)){
			$args	+= ['export_action'=>$this->name, '_wpnonce'=>wp_create_nonce($this->parse_nonce_action($args))];
			$args	+= $submit_name != $this->name ? ['submit_name'=>$submit_name] : [];

			return ['type'=>'redirect', 'url'=>add_query_arg(array_filter($args), $GLOBALS['current_admin_url'])];
		}

		$this->is_allowed($args) || wp_die('access_denied');

		if($type == 'form'){
			return $response+['form'=>$this->get_form($form_args, $type)];
		}

		$bulk	= (int)$bulk === 2 ? 0 : $bulk;
		$cbs	= ['callback', 'bulk_callback'];
		$args	+= $this->pick($cbs);
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
			is_array($result) && ($response['data']	= $result);
		}else{
			!$response['bulk'] && in_array($response['type'], ['add', 'duplicate']) && ($form_args['id']	= $response['id']);
		}

		$result && ($response['result']	= $result);

		if($type == 'submit'){
			if($this->next){
				$response['next']		= $this->next;
				$response['page_title']	= $this->next_action->page_title;

				$response['type'] == 'form' && ($response['errmsg']	= '');
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
		$cb		= $this->data_callback;
		$data	= ($cb && ($include_prev || $by_callback)) ? (is_callable($cb) ? wpjam_try($cb, $id, $this->name) : wp_die($this->title.'的 data_callback 无效')) : null;

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
		$form	= $fields->wrap('form', ['novalidate', 'id'=>'list_table_action_form', 'data'=>$object->generate_data_attr($args, 'form')]);

		return $form->append($object->get_submit_button($args)->prepend($prev ? $prev->render(['class'=>['button'], 'title'=>'上一步']+$args) : ''));
	}

	public function get_fields($args, $include_prev=false, $type=''){
		$arg	= $this->parse_arg($args);
		$fields	= wpjam_try(fn()=> maybe_callback($this->fields, $arg, $this->name));
		$fields	= $fields ?: wpjam_try([$this->model, 'get_fields'], $this->name, $arg);
		$fields	= is_array($fields) ? $fields : [];
		$fields	= array_merge($fields, ($include_prev && $this->prev_action) ? $this->prev_action->get_fields($arg, true) : []);
		$cb		= [$this->model, 'filter_fields'];
		$fields	= method_exists(...$cb) ? wpjam_try($cb, $fields, $arg, $this->name) : $fields;

		if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
			$fields[$this->primary_key]['type']	= 'view';
		}

		if($type){
			$id		= ($args['bulk'] || $this->overall) ? null : $args['id'];
			$args	= ['id'=>$id]+$args;

			if($id){
				if($type != 'submit' || $this->response != 'form'){
					$data	= $this->get_data($id, false, true);

					$args['data']	= is_array($data) ? array_merge($args['data'], $data) : $data;
				}

				$cb		= [$this->model, 'value_callback'];
				$args	+= array_filter(['meta_type'=>wpjam_admin('meta_type'), 'value_callback'=>method_exists(...$cb) ? $cb : '']);
			}

			return WPJAM_Fields::create($fields, array_merge($args, array_filter(['value_callback'=>$this->value_callback])));
		}

		return $fields;
	}

	public function get_submit_button($args, $name=null){
		if(!$name && $this->next){
			$button	= ['next'=>'下一步'];
		}else{
			$button	= maybe_callback($this->submit_text, $this->parse_arg($args), $this->name);
			$button	??= wp_strip_all_tags($this->title) ?: $this->page_title;
			$button	= is_array($button) ? $button : [$this->name => $button];
		}

		return WPJAM_AJAX::parse_submit_button($button, $name);
	}

	public function render($args=[]){
		$args	+= ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]];
		$id		= $args['id'];

		if(is_callable($this->show_if)){
			$show_if	= wpjam_if_error(wpjam_catch($this->show_if, ...(!empty($args['item']) ? [$args['item']] : [$id, $this->name])), null);

			if(!$show_if){
				return;
			}elseif(is_array($show_if)){
				$args	+= $show_if;
			}
		}elseif($this->show_if){
			if($id && !wpjam_match((wpjam_get($args, 'item') ?: $this->get_data($id)), wpjam_parse_show_if($this->show_if))){
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
			return wpjam_get($args, (wpjam_get($args, 'fallback') === true ? 'title' : 'fallback'));
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
		$attr	= ['data'=>$data, 'action'=>$this->name, 'nonce'=>wp_create_nonce($this->parse_nonce_action($args))];
		$attr	+= $this->overall ? [] : ($args['bulk'] ? wpjam_pick($args, ['ids'])+$this->pick(['bulk', 'title']) : wpjam_pick($args, ['id']));

		return $attr+$this->pick($type == 'button' ? ['direct', 'confirm'] : ['next']);
	}

	public static function registers($actions){
		foreach($actions as $key => $args){
			self::register($key, $args+['order'=>10.5]);
		}
	}
}

class WPJAM_List_Table_Column extends WPJAM_List_Table_Component{
	public function __get($key){
		$value	= parent::__get($key);

		if($key == 'style'){
			$value	= $this->column_style ?: $value;
			$value	= ($value && !preg_match('/\{([^\}]*)\}/', $value)) ? 'table.wp-list-table .column-'.$this->name.'{'.$value.'}' : $value;
		}elseif($key == '_field'){
			return $value ?: ($this->$key = wpjam_field(['type'=>'view', 'wrap_tag'=>'', 'key'=>$this->name, 'name'=>$this->_name, 'options'=>$this->options]));
		}elseif(in_array($key, ['title', 'callback', 'description', 'render'])){
			$value	= $this->{'column_'.$key} ?? $value;
		}elseif(in_array($key, ['sortable', 'sticky'])){
			$value	??= $this->{$key.'_column'};
		}

		return $value;
	}

	public function render($value, $id, $item=[]){
		$cb		= $id !== false && is_callable($this->callback) ? $this->callback : null;
		$value	= $cb ? wpjam_call($cb, $id, $this->name, $value) : $value;

		if($render	= $this->render){
			if(is_callable($render)){
				return $render($value, $item, $this->name, $id);
			}

			if($this->type == 'img'){
				$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);

				return $value ? '<img src="'.wpjam_get_thumbnail($value, $size).'" '.image_hwstring($size['width']/2,  $size['height']/2).' />' : '';
			}elseif($this->type == 'timestamp'){
				return $value ? wpjam_date('Y-m-d H:i:s', $value) : '';
			}

			return $value;
		}

		if($cb){
			return $value;
		}

		if(is_array($value)){
			return array_map(fn($v)=> $this->render($v, false), $value);
		}

		if($value && str_contains($value, '[filter')){
			return $value;
		}

		$filter	= isset(get_screen_option('list_table', 'filterable_fields')[$this->name]) ? [$this->_name => $value] : [];
		$value	= $this->options ? $this->_field->val($value)->render() : $value;

		return $filter ? ['filter'=>$filter, 'label'=>$value] : $value;
	}

	public static function registers($fields){
		foreach($fields as $key => $field){
			$column	= wpjam_pull($field, 'column');

			wpjam_get($field, 'show_admin_column', is_array($column)) && self::register($key, ($column ?: [])+wpjam_except(WPJAM_Data_Type::except($field), ['style', 'description', 'render'])+['order'=>10.5, '_name'=>$field['name'] ?? $key]);
		}
	}
}

class WPJAM_List_Table_View extends WPJAM_List_Table_Component{
	public function parse(){
		if($this->_view){
			return $this->_view;
		}

		$view	= $this;
		$cb		= $this->callback;

		if($cb && is_callable($cb)){
			$view	= wpjam_if_error(wpjam_catch($cb, $this->name), null);

			if(!is_array($view)){
				return $view;
			}
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
			$view	= is_array($view) ? WPJAM_Data_Type::except($view) : $view;
			$view	= (is_string($view) || is_object($view)) ? ['_view'=>$view] : $view;

			self::register($name, $view);
		}
	}
}

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args){
		$screen		= get_current_screen();
		$data_type	= wpjam_admin('data_type', $args['data_type']);

		if($data_type == 'post_type'){
			$parts	= $screen->id == 'upload' ? ['media', 'media'] : ($args['hierarchical'] ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts']);
			$args	+= ['builtin'=> $parts[0] == 'media' ? 'WP_Media_List_Table' : 'WP_Posts_List_Table'];
		}elseif($data_type == 'taxonomy'){
			$args	+= ['builtin'=>'WP_Terms_List_Table'];
			$parts	= [$args['taxonomy'], $args['taxonomy']];
		}elseif($data_type == 'user'){
			$args	+= ['builtin'=>'WP_Users_List_Table'];
			$parts	= ['users', 'user', 'users'];
		}elseif($data_type == 'comment'){
			$args	+= ['builtin'=>'WP_Comments_List_Table'];
			$parts	= ['comments', 'comment'];
		}

		wpjam_admin('meta_type', $args['meta_type'] ?? '');

		add_filter('manage_'.$screen->id.'_columns',	[$this, 'filter_columns']);
		add_filter('manage_'.$parts[0].'_custom_column',[$this, 'filter_custom_column'], 10, in_array($data_type, ['post_type', 'comment']) ? 2 : 3);

		add_filter($parts[1].'_row_actions',	[$this, 'filter_row_actions'], 1, 2);

		isset($parts[2]) && add_action('manage_'.$parts[2].'_extra_tablenav', [$this, 'extra_tablenav']);
		in_array($data_type, ['post_type', 'taxonomy']) && add_action('parse_term_query', [$this, 'on_parse_query'], 0);
		wp_is_json_request() || add_filter('wpjam_html', [$this, 'filter_table']);

		parent::__construct($args);
	}

	public function views(){
		$this->screen->id != 'upload' && $this->views_by_builtin();
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
			$args	= $term ? [$term, get_term_level($term)] : [];
		}elseif($this->data_type == 'user'){
			$user	= is_numeric($item) ? get_userdata($item) : $item;
			$args	= $user ? [$user] : [];
		}elseif($this->data_type == 'comment'){
			$comment	= is_numeric($item) ? get_comment($item) : $item;
			$args		= $comment ? [$comment] : [];
		}

		echo empty($args) ? '' : $this->filter_table($this->ob_get('single_row_by_builtin', ...$args));
	}

	public function on_parse_query($query){
		if(array_any(debug_backtrace(), fn($v)=> wpjam_get($v, 'class') == $this->builtin)){
			$vars	= &$query->query_vars;
			$by		= $vars['orderby'] ?? '';
			$object	= ($by && is_string($by)) ? $this->component('column', $by) : null;
			$type	= $object ? ($object->sortable === true ? 'meta_value' : $object->sortable) : '';
			$vars	= array_merge($vars, ['list_table_query'=>true], in_array($type, ['meta_value_num', 'meta_value']) ? ['orderby'=>$type, 'meta_key'=>$by] : []);
		}
	}

	public static function load($args){
		return new static($args);
	}
}