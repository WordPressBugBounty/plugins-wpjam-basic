<?php
if(!class_exists('WP_List_Table')){
	include ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

class WPJAM_List_Table extends WP_List_Table{
	use WPJAM_Call_Trait;

	public function __construct($args=[]){
		add_screen_option('list_table', ($GLOBALS['wpjam_list_table'] = wpjam_admin('list_table', $this)));

		wp_doing_ajax() && wpjam_get_post_parameter('action_type') == 'query_items' && ($_REQUEST = $this->get_data()+$_REQUEST);

		$this->screen	= $screen = get_current_screen();
		$this->_args	= $args+['screen'=>$screen, 'shortcodes'=>['filter', 'row_action']];

		array_map([$this, 'component'], ['action', 'view', 'column']);
		array_map(fn($k)=> add_filter('manage_'.$screen->id.'_'.$k, [$this, 'filter_'.$k]), ['columns', 'sortable_columns']);
		array_map(fn($k, $s)=> add_filter($k.$s.$screen->id, [$this, 'filter_'.$k]), ['views', 'bulk_actions'], ['_', '-']);

		array_map(fn($v)=> add_shortcode($v, [$this, $v.'_shortcode']), ['filter', 'row_action']);

		wpjam_admin([
			'style'						=> $this->style,
			'vars[list_table]'			=> fn()=> $this->get_setting(),
			'vars[page_title_action]'	=> fn()=> $this->get_action('add', ['class'=>'page-title-action']) ?: ''
		]);

		if($this->builtin){
			$this->page_load();
		}else{
			add_filter('list_table_primary_column', fn()=> $this->primary_column ??= array_key_first(wpjam_except($this->columns, ['no', 'cb'])));

			parent::__construct($this->_args);
		}
	}

	public function __get($name){
		if(in_array($name, $this->compat_fields, true)){
			return $this->$name;
		}

		if(isset($this->_args[$name])){
			return $this->_args[$name];
		}

		if(in_array($name, ['actions', 'views', 'fields'])){
			$value	= [$this, 'get_'.$name.'_by_model']();

			if($name == 'fields'){
				return $this->$name = wpjam_fields($value ?: [], true);
			}

			return $value ?? ($name == 'actions' ? ($this->builtin ? [] : WPJAM_Model::get_actions()) : ($this->views_by_model() ?: []));
		}elseif(in_array($name, ['primary_key', 'filterable_fields', 'searchable_fields'])){
			$value	= wpjam_trap($this->model.'::get_'.$name, []) ?: [];

			if($name == 'primary_key'){
				return $this->$name	= $value ?: 'id';
			}elseif($name == 'filterable_fields'){
				$fields	= wpjam_filter($this->fields, ['filterable'=>true]);
				$views	= array_keys(wpjam_filter($fields, ['type'=>'view']));
				$fields	= array_map(fn($v)=> wpjam_except($v, ['title', 'before', 'after', 'required', 'show_admin_column'])+[($v['type'] === 'select' ? 'show_option_all' : 'placeholder') => $v['title'] ?? ''], wpjam_except($fields, $views));

				return $this->$name = $fields+($fields && !$this->builtin && $this->sortable_columns ? [
					'orderby'	=> ['options'=>[''=>'排序']+array_map('wp_strip_all_tags', array_intersect_key($this->columns, $this->sortable_columns))],
					'order'		=> ['options'=>['desc'=>'降序','asc'=>'升序']]
				] : [])+array_fill_keys(array_merge($value, $views), []);
			}

			return $value;
		}elseif(in_array($name, ['form_data', 'params', 'left_data'])){
			$left	= $name == 'left_data';
			$fields	= $left ? $this->left_fields : array_filter($this->filterable_fields);
			$data	= $name == 'form_data' || wp_doing_ajax() ? wpjam_get_post_parameter($left ? 'params' : $name) : wpjam_get_parameter();

			return array_filter(array_merge(...array_map(fn($v)=> $v ? wpjam_trap([$v, 'validate'], wp_parse_args($data), []) : [], [
				$data && $fields ? wpjam_fields($fields) : '',
				$left ? '' : wpjam_chart()
			])), fn($v)=> isset($v) && $v !== []);
		}elseif($name == 'offset'){
			return ((int)$this->per_page ?: 50)*($this->get_pagenum()-1);
		}
	}

	public function __set($name, $value){
		return in_array($name, $this->compat_fields, true) ? ($this->$name = $value) : ($this->_args[$name] = $value);
	}

	public function __isset($name){
		return $this->$name !== null;
	}

	public function __call($method, $args){
		if($method == 'get_arg'){
			return wpjam_get($this->_args, ...$args);
		}elseif($method == 'get_data'){
			return wpjam_get_data_parameter(...$args);
		}elseif($method == 'get_date'){
			$type	= $args[0] ?? '';

			[$year, $month]	= array_map(fn($k, $f, $r)=> clamp(($type == 'current' ? 0 : (int)$this->get_data($k)) ?: wpjam_date($f), ...$r), ['year', 'month'], ['Y', 'm'], [[1970, 2200], [1, 12]]);

			$offset	= $type == 'prev' ? -1 : ($type == 'next' ? 1 : 0);
			$month	+= $offset;

			if(in_array($month, [0, 13])){
				$year	+= $offset;
				$month	= abs($month-12);
			}

			return in_array('locale', $args) ? sprintf(__('%1$s %2$d', 'wpjam'), $GLOBALS['wp_locale']->get_month($month), $year) : compact('year', 'month');
		}elseif($method == 'add'){
			$this->_args	= wpjam_set($this->_args, array_shift($args).'['.(count($args) >= 2 ? array_shift($args) : '').']', $args[0]);

			return $args[0];
		}elseif(try_remove_suffix($method, '_by_model')){
			return ($cb = wpjam_callback([$this->model, $method])) ? wpjam_catch($cb, ...$args) : null;
		}elseif(try_remove_suffix($method, '_shortcode')){
			if($method === 'filter'){
				return $this->get_filter_link($args[0], $args[1], wpjam_pull($args[0], 'class'));
			}else{
				return $this->get_row_action($this->row_id, (is_blank($args[1]) ? [] : ['title'=>$args[1]])+$args[0])."\n";
			}
		}elseif(try_remove_suffix($method, '_row_actions')){
			[$value, $id]	= $method == 'get' ? [[], $args[0]] : [$args[0], $this->parse_id($args[1])];

			$names	= array_diff($this->row_actions ?: [], $this->next_actions ?: []);
			$value	+= $this->get_action($names, ['id'=>$id]+($this->layout == 'calendar' ? ['wrap'=>'<span class="%s"></span>'] : []));
			$value	+= $this->builtin ? wpjam_pull($value, ['view', 'delete', 'trash', 'spam', 'remove']) : [];

			return wpjam_except($value+($this->builtin || $this->primary_key == 'id' ? ['id'=>'ID: '.$id] : []), wpjam_admin('removed_actions[]'));
		}elseif(try_remove_prefix($method, 'ob_get_')){
			$result = wpjam_ob([$this, ($this->builtin && $method != 'single_row' ? 'builtin_' : '').$method], ...$args);

			return $this->builtin && in_array($method, ['single_row', 'display']) ? $this->filter_table($result) : $result;
		}elseif(try_remove_prefix($method, 'builtin_')){
			return [$GLOBALS['wp_list_table'] ??= _get_list_table($this->builtin, ['screen'=>$this->screen]), $method](...$args);
		}elseif(try_remove_prefix($method, 'filter_')){
			if($method == 'table'){
				return wpjam_preg_replace('#<tr id=".+?-(\d+)"[^>]*>.+?</tr>#is', fn($m)=> $this->filter_single_row(...$m), $args[0]);
			}elseif($method == 'single_row'){
				$this->row_id	= $args[1];

				return wpjam_do_shortcode(apply_filters('wpjam_single_row', ...$args), $this->shortcodes);
			}elseif($method == 'columns'){
				return $this->columns	= wpjam_except(wpjam_add_at($args[0], $args[0] ? -1 : 0, $this->columns ?: []), wpjam_admin('removed_columns[]'));
			}

			return array_merge($args[0], $this->$method ?: []);
		}

		return parent::__call($method, $args);
	}

	public function __invoke($data){
		$type	= $data['action_type'];
		$action	= $data['list_action'] ?? '';
		$parts	= parse_url((wp_get_original_referer() ?: wp_get_referer()) ?: wp_die('非法请求'));

		if($parts['host'] == $_SERVER['HTTP_HOST']){
			$_SERVER['REQUEST_URI']	= $parts['path'];
		}

		if($type == 'query_items'){
			if(count($data = $this->get_data()) == 1 && isset($data['id'])){
				return $this->response(['type'=>'add']+$data);
			}

			$result	= ['type'=>'list'];
		}else{
			$result	= ($this->get_action($action) ?: wp_die('无效的操作'))($type);
		}

		if(!in_array($result['type'], ['form', 'append', 'redirect', 'move', 'up', 'down'])){
			$this->prepare_items();

			$result	= $this->response($result)+['params'=>$this->params, 'setting'=>$this->get_setting(), 'views'=>$this->ob_get_views()];
			$result	+= $result['type'] == 'list' ? ['table'=>$this->ob_get_display()] : ['tablenav'=>wpjam_fill(['top', 'bottom'], [$this, 'ob_get_display_tablenav'])];
		}

		return $result;
	}

	protected function response($data){
		if($data['type'] == 'list'){
			if($this->layout == 'left' && !isset($this->get_data()[$this->left_key])){
				$data['left']	= $this->ob_get_col_left();
			}
		}elseif($data['type'] == 'items'){
			if(isset($data['items'])){
				$data['items']	= wpjam_map($data['items'], fn($item, $id)=> $this->response(['id'=>$id]+$item));
			}
		}elseif($data['type'] != 'delete'){
			if($this->layout == 'calendar'){
				if(!empty($data['data'])){
					$data['data']	= wpjam_map($data['data'], [$this, 'ob_get_single_date']);
				}
			}elseif(!empty($data['bulk'])){
				$this->get_by_ids_by_model($ids	= array_filter($data['ids']));

				$data['data']	= array_map(fn($id)=> ['id'=>$id, 'data'=>$this->ob_get_single_row($id)], $ids);
			}elseif(!empty($data['id'])){
				$data['data']	= $this->ob_get_single_row($data['id']);
			}
		}

		return $data;
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

			$name			= 'update_setting';
			$this->actions	+= $this->$name ? [$name => (is_array($this->$name) ? $this->$name : [])+['page_title'=>'全局设置', 'title'=>'设置', 'class'=>'button-primary', $name=>true]] : [];

			foreach(wpjam_get_meta_options(wpjam_admin('meta_type'), ['list_table'=>true]+wpjam_except($args, 'data_type')) as $v){
				wpjam_register_list_table_action(($v->action_name ?: 'set_'.$v->name), $v->get_args()+[
					'meta_type'		=> $v->name,
					'page_title'	=> '设置'.$v->title,
					'submit_text'	=> '设置'
				]);
			}
		}elseif($type == 'column'){
			if($this->layout == 'calendar'){
				array_map(fn($i)=> $this->add('columns', 'day'.($i%7), $GLOBALS['wp_locale']->get_weekday_abbrev($GLOBALS['wp_locale']->get_weekday($i%7))), array_slice(range(0, get_option('start_of_week')+6), -7));

				return array_map(fn($v)=> $this->add('query_args', $v), ['year', 'month']);
			}

			$this->bulk_actions && !$this->builtin && $this->add('columns', 'cb', true);

			($no = $this->numberable) && $this->add('columns', 'no', $no === true ? 'No.' : $no);
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
				$view	= $object();
				$view	&& $this->add('views', $key, is_array($view) ? $this->get_filter_link(...$view) : $view);
			}else{
				$data	= array_filter($object->pick(['description', 'sticky', 'nowrap', 'format', 'precision', 'conditional_styles']));

				$this->add('columns', $key, $object->title.($data ? wpjam_tag('i', ['data'=>$data]) : ''));

				$object->sortable && $this->add('sortable_columns', $key, [$key, true]);

				wpjam_style($object->style);
			}
		}
	}

	protected function get_setting(){
		$s		= $this->get_data('s');
		$fields = $this->searchable_fields;

		return wpjam_pick($this, ['sortable', 'layout', 'left_key'])+[
			'search'	=> $this->builtin || ($this->search ?? $fields) ? [
				'columns'	=> wpjam_is_assoc_array($fields) ? wpjam_field(['key'=>'search_columns', 'value'=>$this->get_data('search_columns'), 'show_option_all'=>'默认', 'options'=>$fields]) : ''
			]+($this->builtin ? ['term'=>$s] : ['box'=>$this->ob_get_search_box('搜索', 'wpjam')]) : false,

			'subtitle'	=> $this->get_subtitle_by_model().($s ? sprintf(__('Search results for: %s', 'wpjam'), '<strong>'.esc_html($s).'</strong>') : ''),
			'summary'	=> $this->get_summary_by_model(),

			'column_count'		=> [$this, ($this->builtin ? 'builtin_' : '').'get_column_count'](),
			'bulk_actions'		=> array_map(fn($v)=> array_filter($v->get_data_attr(['bulk'=>true])), $this->bulk_actions ?: []),
			'overall_actions'	=> array_values($this->get_action(array_diff($this->overall_actions ?: [], $this->next_actions ?: []), ['class'=>'button overall-action']))
		];
	}

	public function get_action($name, ...$args){
		if(is_array($name)){
			return wpjam_fill($name ?: [], fn($n)=> $this->get_action($n, ...$args));
		}

		return ($object = $this->component('action', $name)) && $args ? $object->render($args[0]) : $object;
	}

	public function get_row_action($id, $args){
		return $this->get_action(wpjam_pull($args, 'name'), $args+['id'=>$id]);
	}

	public function get_filter_link($filter, $label, $attr=[]){
		$filter	+= $this->get_data($this->query_args ?: []);

		return wpjam_tag('a', $attr, $label)->add_class('list-table-filter')->data('filter', $filter ?: new stdClass());
	}

	public function single_row($item){
		if($this->layout == 'calendar'){
			return wpjam_echo(wpjam_tag('tr')->append(wpjam_map($item, fn($date, $day)=> ['td', ['id'=>'date_'.$date, 'class'=>'column-day'.$day], $this->ob_get_single_date($this->calendar[$date] ?? [], $date)])));
		}

		$item	= ($item instanceof WPJAM_Register) ? $item->to_array() : (is_array($item) ? $item : wpjam_trap($this->model.'::get', $item, wp_doing_ajax() ? 'throw' : []));

		if(!$item){
			return;
		}

		$raw	= (array)$item;
		$id		= $this->parse_id($item);
		$attr	= $id ? ['id'=>$this->singular.'-'.str_replace('.', '-', $id), 'data'=>['id'=>$id]] : [];

		$item['row_actions']	= $id ? $this->get_row_actions($id) : ($this->row_actions ? ['error'=>'Primary Key「'.$this->primary_key.'」不存在'] : []);

		$this->before_single_row_by_model($raw);

		$method	= array_find(['render_row', 'render_item', 'item_callback'], fn($v)=> method_exists($this->model, $v));
		$item	= $method ? [$this->model, $method]($item, $attr) : $item;
		$attr	+= $method && isset($item['class']) ? [['class'=>$item['class']], trigger_error(var_export($item, true))][0] : [];	// del 2026-03-31

		$this->numberable && ($this->no ??= $this->offset);

		echo $item ? $this->filter_single_row(wpjam_tag('tr', $attr, $this->ob_get_single_row_columns($item+($this->numberable ? ['no'=>($this->no += 1)] : [])))->add_class($this->multi_rows ? 'tr-'.$id : ''), $id)."\n" : '';

		$this->after_single_row_by_model($item, $raw);
	}

	public function single_date($item, $date){
		$parts	= explode('-', $date);
		$append	= ($item || $parts[1] == $this->get_date()['month']) ? wpjam_tag('div', ['row-actions', 'alignright'])->append($this->get_row_actions($date)) : '';

		echo wpjam_tag('div', ['date-meta'])->append('span', ['day', $date == wpjam_date('Y-m-d') ? 'today' : ''], (int)$parts[2])->append($append)->after('div', ['date-content'], $this->render_date_by_model($item, $date) ?? (is_string($item) ? $item : ''));
	}

	protected function parse_id($item){
		return wpjam_get($item, $this->primary_key);
	}

	protected function parse_cell($cell, $id){
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
			$data	= ['field'=>'', 'max_items'=>null]+($type == 'image' ? ['width'=>60, 'height'=>60, 'per_row'=>null] : []);
			$data	= wpjam_pick($args, array_keys($data))+$data;
			$cell	= wpjam_tag('div', ['items', $type.'-list'])->data($data)->style($args['style'] ?? '');
			$names	= $args['actions'] ?? ['add_item', 'edit_item', 'del_item'];
			$names	= !empty($args['sortable']) && $cell->add_class('sortable') ? ['move_item', ...$names] : $names;
			$args	= ['id'=>$id,'data'=>['_field'=>$data['field']]];
			$add	= in_array('add_item', $names) && (!$data['max_items'] || count($items) <= $data['max_items']);

			foreach($items as $i => $item){
				$v	= $item[$key] ?: '';

				if($type == 'image'){
					$ar	= wpjam_pick($data, ['width', 'height']);
					$v	= wpjam_tag('img', ['src'=>wpjam_get_thumbnail($v, array_map(fn($s)=> $s*2, $ar))]+$ar)->after('span', ['item-title'], $item['title'] ?? '');
				}

				$args['i']	= $args['data']['i']	= $i;

				$cell->append(wpjam_tag('div', ['id'=>'item_'.$i, 'data'=>['i'=>$i], 'class'=>'item'])->append([
					$this->get_action('move_item', $args+['title'=>$v, 'fallback'=>true])->style(wpjam_pick($item, ['color'])),
					wpjam_tag('span', ['row-actions'])->append($this->get_action(array_diff($names, ['add_item']), $args+['wrap'=>'<span class="%s"></span>', 'item'=>$item]))
				]));
			}

			unset($args['i'], $args['data']['i']);

			$add && $cell->append($this->get_action('add_item', $args+['class'=>'add-item item']+($type == 'image' ? ['dashicon'=>'plus-alt2'] : [])));
		}else{
			$cell	= $cell['text'] ?? '';
		}

		return (string)wpjam_wrap($cell, $wrap);
	}

	public function column_default($item, $name, $id=null){
		$id		??= $this->parse_id($item);
		$object	= $this->component('column', $name);
		$args	= $this->value_callback === false ? [] : array_filter(['meta_type'=>wpjam_admin('meta_type'), 'model'=>$this->model]);
		$value	= $object && $id ? $object($args+['data'=>$item, 'id'=>$id]) : (is_array($item) ? ($item[$name] ?? null) : $item);

		return implode(',', array_map(fn($v)=> is_array($v) ? $this->parse_cell($v, $id) : $v, wp_is_numeric_array($value) ? $value : [$value]));
	}

	public function column_cb($item){
		if(($id	= $this->parse_id($item)) && wpjam_can($this->capability, $id)){
			return wpjam_tag('input', ['type'=>'checkbox', 'name'=>'ids[]', 'value'=>$id, 'id'=>'cb-select-'.$id, 'title'=>'选择'.strip_tags($item[$this->primary_column] ?? $id)]);
		}
	}

	public function render(){
		$form	= wpjam_tag('form', ['id'=>'list_table_form'], $this->ob_get_display())->before($this->ob_get_views());

		return $this->layout == 'left' ? wpjam_tag('div', ['id'=>'col-container', 'class'=>'wp-clearfix'])->append(array_map(fn($v, $k)=> $v->add_class('col-wrap')->wrap('div', ['id'=>'col-'.$k]), [wpjam_wrap($this->ob_get_col_left(), 'form'), $form], ['left', 'right'])) : $form;
	}

	public function col_left($action=''){
		$paged	= (int)$this->get_data('left_paged') ?: 1;

		if(($cb = $this->model.'::query_left') && wpjam_callback($cb)){
			static $pages, $items;

			if($action == 'prepare'){
				$number	= $this->left_per_page ?: 10;
				$left	= array_filter($this->get_data([$this->left_key]));
				$items	= wpjam_try($cb, ['number'=>$number, 'offset'=>($paged-1)*$number]+$this->left_data+$left);
				$pages	= $items ? ceil($items['total']/$number) : 0;
				$items	= $items ? $items['items'] : [];

				return $items && !$left && wpjam_default($this->left_key, array_first($items)['id']);
			}

			$this->left_fields && wpjam_echo(wpjam_fields($this->left_fields, ['fields_type'=>'', 'data'=>$this->left_data])->wrap('div', ['alignleft', 'actions'])->after('br', ['clear'])->wrap('div', ['class'=>'tablenav']));

			$head	= [[$this->left_title, 'th'], 'tr'];
			$items	= implode(wpjam_map($items ?: ['找不到'.$this->left_title], fn($item)=> wpjam_tag('td')->append(is_array($item) ? [
				['p', ['row-title'], $item['title']],
				['span', ['time'], $item['time']],
				...(isset($item['count']) ? array_map(fn($v)=> ['span', ['count', 'wp-ui-highlight'], $v], (array)$item['count']) : [])
			] : $item)->wrap('tr', is_array($item) ? ['class'=>'left-item', 'id'=>$item['id'], 'data-id'=>$item['id']] : ['no-items'])));

			echo wpjam_tag('table', ['widefat striped'])->append([[$head, 'thead'], [$items, 'tbody'], [$head, 'tfoot']]);
		}else{
			$result	= $this->col_left_by_model();
			$pages	= $result && is_array($result) ? ceil($result['total_items']/$result['per_page']) : 0;
		}

		$pages > 1 && wpjam_echo(wpjam_tag('span', ['left-pagination-links'])->append([
			wpjam_tag('a', ['prev-page'], '&lsaquo;')->attr('title', __('Previous page', 'wpjam')),
			wpjam_tag('span', [], $paged.' / '.$pages),
			wpjam_tag('a', ['next-page'], '&rsaquo;')->attr('title', __('Next page', 'wpjam')),
			wpjam_tag('input', ['type'=>'number', 'name'=>'left_paged', 'value'=>$paged, 'min'=>1, 'max'=>$pages, 'class'=>'current-page']),
			wpjam_tag('input', ['type'=>'submit', 'class'=>'button', 'value'=>'&#10132;'])
		])->wrap('div', ['tablenav-pages'])->wrap('div', ['tablenav', 'bottom']));
	}

	public function page_load(){
		if(wp_doing_ajax()){
			return wpjam_ajax('wpjam-list-table-action', [
				'admin'			=> true,
				'callback'		=> $this,
				'nonce_action'	=> fn($data)=> ($object = $this->get_action($data['list_action'] ?? '')) ? $object->parse_nonce_action($data) : null
			]);
		}

		if($action = wpjam_get_parameter('export_action')){
			return ($object = $this->get_action($action)) ? wpjam_trap($object, 'export', 'die') : wp_die('无效的导出操作');
		}

		$this->builtin || wpjam_trap([$this, 'prepare_items'], fn($result)=> wpjam_admin('error', $result));
	}

	public function prepare_items(){
		$args	= array_filter($this->get_data(['orderby', 'order', 's', 'search_columns']), fn($v)=> isset($v));
		$_GET	= array_merge($_GET, $args);
		$args	+= $this->params+wpjam_array($this->filterable_fields, fn($k, $v)=> [$k, $v ? null : $this->get_data($k)], true);

		if($this->layout == 'calendar'){
			$date	= $this->get_date();
			$start	= (int)get_option('start_of_week');
			$ts		= mktime(0, 0, 0, $date['month'], 1, $date['year']);
			$pad	= calendar_week_mod(date('w', $ts)-$start);
			$days	= date('t', $ts);
			$days	= $days+6-($days%7 ?: 7);
			$items	= wpjam_map(array_chunk(range(0, $days), 7), fn($item)=> wpjam_array($item, fn($k, $v)=> [($v+$start)%7, date('Y-m-d', $ts+($v-$pad)*DAY_IN_SECONDS)]));

			$this->calendar	= wpjam_try($this->model.'::query_calendar', $args+$date);
		}else{
			$this->layout == 'left' && $this->col_left('prepare');

			$number	= (int)$this->per_page ?: 50;
			$offset	= $this->offset;
			$cb		= $this->model.'::query_items';
			$params	= wpjam_get_reflection($cb, 'Parameters') ?: [];
			$items	= wpjam_try($cb, ...(count($params) > 1 && $params[0]->name != 'args' ? [$number, $offset] : [compact('number', 'offset')+$args]));

			if(wpjam_is_assoc_array($items) && isset($items['items'])){
				$total	= $items['total'] ?? null;
				$items	= $items['items'];
			}

			$this->set_pagination_args(['total_items'=>$total ?? ($number = count($items)), 'per_page'=>$number]);
		}

		$this->items	= $items;
	}

	protected function get_table_classes(){
		return array_merge(array_diff(parent::get_table_classes(), ($this->fixed ? [] : ['fixed'])), $this->nowrap ? ['nowrap'] : []);
	}

	protected function handle_row_actions($item, $column, $primary){
		return ($primary === $column && !empty($item['row_actions'])) ? $this->row_actions($item['row_actions']) : '';
	}

	public function get_columns(){
		return [];
	}

	public function extra_tablenav($which='top'){
		if($this->layout == 'calendar'){
			echo wpjam_tag('h2', [], $this->get_date('locale'));
			echo wpjam_tag('span', ['pagination-links'])->append(array_map(fn($v, $k)=> "\n".$this->get_filter_link($this->get_date($k), $v, ['class'=>$k.'-month button', 'title'=>$this->get_date($k, 'locale')]), ['&lsaquo;', '今日', '&rsaquo;'], ['prev', 'current', 'next']))->wrap('div', ['tablenav-pages']);
		}

		if($which == 'top' && ($fields = (wpjam_chart('get_fields') ?: [])+array_filter($this->filterable_fields))){
			echo wpjam_fields($fields, ['fields_type'=>'', 'data'=>$this->params])->wrap('div', ['actions'])->append(get_submit_button('筛选', '', 'filter_action', false));
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

class WPJAM_Builtin_List_Table extends WPJAM_List_Table{
	public function __construct($args){
		$data_type	= wpjam_admin(wpjam_pick($args, ['data_type', 'meta_type']))['data_type'];

		if($data_type == 'post_type'){
			$echo		= 'echo';
			$builtin	= $args['post_type'] == 'attachment' ? 'Media' : 'Posts';
			$callback	= 'get_post';
			$parts		= $builtin == 'Media' ? ['media', 'media'] : ($args['hierarchical'] ? ['pages', 'page', 'posts'] : ['posts', 'post', 'posts']);
		}elseif($data_type == 'taxonomy'){
			$builtin	= 'Terms';
			$callback	= ['get_term', 'get_term_level'];
			$parts		= [$args['taxonomy'], $args['taxonomy']];
		}elseif($data_type == 'user'){
			$builtin	= 'Users';
			$callback	= 'get_userdata';
			$parts		= ['users', 'user', 'users'];
		}elseif($data_type == 'comment'){
			$echo		= 'echo';
			$builtin	= 'Comments';
			$callback	= 'get_comment';
			$parts		= ['comments', 'comment'];
		}

		wpjam_hook(($echo ?? ''), 'manage_'.$parts[0].'_custom_column', fn(...$args)=> $this->column_default(...array_pad($args, -3, [])), 10, 3);

		add_filter($parts[1].'_row_actions', [$this, 'filter_row_actions'], 1, 2);

		isset($parts[2]) && add_action('manage_'.$parts[2].'_extra_tablenav', [$this, 'extra_tablenav']);

		wp_is_json_request() || add_filter('wpjam_html', [$this, 'filter_table']);

		in_array($data_type, ['post_type', 'taxonomy']) && add_action('parse_term_query', function($query){
			if(array_any(debug_backtrace(), fn($v)=> wpjam_get($v, 'class') == $this->builtin)){
				$vars	= &$query->query_vars;
				$by		= $vars['orderby'] ?? '';
				$object	= ($by && is_string($by)) ? $this->component('column', $by) : null;
				$type	= $object ? ($object->sortable === true ? 'meta_value' : $object->sortable) : '';
				$vars	= array_merge($vars, ['list_table_query'=>true], in_array($type, ['meta_value_num', 'meta_value']) ? ['orderby'=>$type, 'meta_key'=>$by] : []);
			}
		}, 0);

		parent::__construct($args+['builtin'=>'WP_'.$builtin.'_List_Table', 'item_callback'=>$callback]);
	}

	public function prepare_items(){
		if($this->screen->base == 'edit'){
			$_GET['post_type']	= $this->post_type;
		}

		$_GET	= array_merge($_GET, $this->get_data());
		$_POST	= array_merge($_POST, $this->get_data());

		$this->builtin_prepare_items();
	}

	public function single_row($item){
		$cb	= (array)$this->item_callback;

		if($item = is_numeric($item) ? (array_shift($cb))($item) : $item){
			if($this->data_type == 'post_type' && $item->post_type == 'attachment'){
				$GLOBALS['authordata'] = get_userdata($item->post_author);

				echo wpjam_tag('tr', ['id'=>'post-'.$item->ID], $this->ob_get_single_row_columns($item))->add_class(['author-'.(get_current_user_id() == $item->post_author ? 'self' : 'other'), 'status-'.$item->post_status]);
			}else{
				$this->builtin_single_row($item, ...array_map(fn($v)=> $v($item), $cb));
			}
		}
	}

	public static function load($args){
		return new static($args);
	}
}

class WPJAM_List_Table_Component extends WPJAM_Register{
	public static function group($method, ...$args){
		$group	= parent::group(['config'=>['orderby'=>'order']]);

		if(in_array($method, ['add_object', 'remove_object'])){
			$part		= str_replace('wpjam_list_table_', '', $group->name);
			$args[0]	= ($name = $args[0]).WPJAM_Data_Type::prepare($args[1], 'key');

			if($method == 'add_object'){
				if($part == 'action'){
					if(!empty($args[1]['update_setting'])){
						$model		= wpjam_admin('list_table', 'model');
						$args[1]	+= ['overall'=>true, 'callback'=>[$model, 'update_setting'], 'value_callback'=>[$model, 'get_setting']];
					}

					if(!empty($args[1]['overall']) && $args[1]['overall'] !== true){
						static::group($method, $name.'_all', ['overall'=>true, 'title'=>wpjam_pull($args[1], 'overall')]+$args[1]);
					}
				}elseif($part == 'column'){
					$args[1]['_field']	= wpjam_field(wpjam_pick($args[1], ['name', 'options'])+['type'=>'view', 'wrap_tag'=>'', 'key'=>$name]);
				}

				$args[1]	= new static($name, $args[1]);
			}else{
				if(!static::get($args[0])){
					return wpjam_admin('removed_'.$part.'s[]', $name);
				}
			}
		}

		return parent::group($method, ...$args);
	}
}

class WPJAM_List_Table_Action extends WPJAM_List_Table_Component{
	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'page_title'){
			return $this->title ? wp_strip_all_tags($this->title.wpjam_admin('list_table', 'title')) : '';
		}elseif($key == 'response'){
			return $this->next ? 'form' : ($this->overall && $this->name != 'add' ? 'list' : $this->name);
		}elseif($key == 'row_action'){
			return ($this->bulk !== 'only' && $this->name != 'add');
		}elseif(in_array($key, ['layout', 'model', 'builtin', 'form_data', 'primary_key', 'data_type', 'capability', 'next_actions']) || ($this->data_type && $this->data_type == $key)){
			return wpjam_admin('list_table', $key);
		}
	}

	public function __toString(){
		return $this->title;
	}

	public function __call($method, $args){
		if(str_contains($method, '_prev')){
			$cb	= [self::get($this->prev ?: array_search($this->name, $this->next_actions ?: [])), str_replace('_prev', '', $method)];

			return $cb[0] ? $cb(...$args) : ($cb[1] == 'render' ? '' : []);
		}elseif(try_remove_prefix($method, 'parse_')){
			$args	= $args[0];

			if($method == 'nonce_action'){
				return wpjam_join('-', $this->name, empty($args['bulk']) ? ($args['id'] ?? '') : '');
			}

			if($this->overall){
				return;
			}

			if($method == 'arg'){
				if(wpjam_is_assoc_array($args)){
					return (int)$args['bulk'] === 2 ? (!empty($args['id']) ? $args['id'] : $args['ids']) : ($args['bulk'] ? $args['ids'] : $args['id']);
				}

				return $args;
			}elseif($method == 'id'){
				return $args['bulk'] ? null : $args['id'];
			}
		}
	}

	public function __invoke($type){
		$cb		= 'wpjam_get_'.($type == 'export' ? '' : 'post_').'parameter';
		$data	= $type == 'export' ? ($cb('data') ?: []) : wpjam_get_data_parameter();
		$data	+= in_array($type, ['direct', 'form']) && $this->overall ? $this->form_data : [];
		$id		= $cb('id') ?? '';
		$ids	= wp_parse_args($cb('ids') ?? []);
		$bulk	= $cb('bulk');
		$bulk	= ['true'=>1, 'false'=>0][$bulk] ?? $bulk;
		$args	= $form_args = ['data'=>$data, 'bulk'=>&$bulk, 'id'=>$id, 'ids'=>$ids];
		$submit	= null;
		$button	= [];

		$response	= [
			'list_action'	=> $this->name,
			'page_title'	=> $this->page_title,
			'type'	=> $type == 'form' ? 'form' : $this->response,
			'last'	=> (bool)$this->last,
			'width'	=> (int)$this->width,
			'bulk'	=> &$bulk,
			'id'	=> &$id,
			'ids'	=> $ids
		];

		if(in_array($type, ['submit', 'export'])){
			$submit	= $cb('submit_name') ?: $this->name;
			$button	= wpjam_button($this, $submit, $this->parse_arg($args));

			if(!empty($button['response'])){
				$response['type'] = $button['response'];
			}
		}

		if(in_array($type, ['submit', 'direct'])
			&& ($this->export || ($type == 'submit' && !empty($button['export'])) || ($this->bulk === 'export' && $bulk))
		){
			$args	+= ['export_action'=>$this->name, '_wpnonce'=>wp_create_nonce($this->parse_nonce_action($args)), 'submit_name'=>$submit];

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
			$fields	= $this->get_fields($args, true);
			$data	= $fields->validate($data);

			$form_args['data']	= $response['type'] == 'form' ? $data : wpjam_get_parameter('', ['method'=>'defaults']);
		}

		if($response['type'] != 'form'){
			$args		= (in_array($type, ['submit', 'export']) ? array_filter(wpjam_pick($button, $cbs)) : [])+$args;
			$result		= $this->callback(['data'=>$data, 'fields'=>$fields, 'submit_name'=>$submit]+$args);
			$response	+= is_array($result) ? wpjam_notice($result) : ['notice'=>$type == 'submit' ? $button['text'].'成功' : ''];
		}

		if(is_array($result)){
			$response	+= wpjam_pull($result, ['args']);

			if(array_intersect(array_keys($result), ['type', 'bulk', 'ids', 'id', 'items'])){
				$response	= $result+$response;
				$result		= null;
			}
		}else{
			if(in_array($response['type'], ['add', 'duplicate']) && $this->layout != 'calendar'){
				[$id, $result]	= [$result, null];
			}
		}

		if($response['type'] == 'append'){
			return $response+($result ? ['data'=>$result] : []);
		}elseif($response['type'] == 'redirect'){
			return $response+['target'=>$this->target ?: '_self']+(is_string($result) ? ['url'=>$result] : []);
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
				$response	= ['next'=>$this->next, 'page_title'=>(self::get($this->next))->page_title]+$response;

				if($response['type'] == 'form'){
					$response['notice']	= '';
				}
			}

			if($this->dismiss || !empty($response['dismiss']) || $response['type'] == 'delete' || ($response['type'] == 'items' && array_find($response['items'], fn($item)=> $item['type'] == 'delete'))){
				$response['dismiss']	= true;
			}else{
				$response['form']		= ($type == 'submit' && $this->next ? self::get($this->next) : $this)->get_form($form_args, $type);
			}
		}

		return $response;
	}

	public function callback($args){
		$name	= $this->name;
		$bulk	= $args['bulk'];
		$data	= $args['data'];

		if(in_array($name, ['up', 'down'])){
			$data	= $data+[$name=>true];
			$name	= 'move';
		}

		$cb_args	= [$this->parse_arg($args), $data];

		if($cb = $args[($bulk ? 'bulk_' : '').'callback'] ?? ''){
			if($this->overall){
				array_shift($cb_args);
			}elseif(!$bulk && ($this->response == 'add' || $name == 'add') && !is_null($data)){
				$params	= wpjam_get_reflection($cb, 'Parameters') ?: [];

				(count($params) <= 1 || $params[0]->name == 'data') && array_shift($cb_args);
			}

			return wpjam_trap($cb, ...[...$cb_args, $args['submit_name'] ?: $this->name, 'throw']) ?? wp_die('「'.$this->title.'」的回调函数无效或没有正确返回');
		}

		if($bulk){
			$cb	= [$this->model, 'bulk_'.$name];

			if(method_exists(...$cb)){
				return wpjam_try($cb, ...$cb_args) ?? true;
			}

			return array_reduce($args['ids'], fn($c, $id)=> wpjam_merge($c, is_array($r = $this->callback(array_merge($args, ['id'=>$id, 'bulk'=>false]))) ? $r : []), []) ?: true;
		}

		$m	= $name == 'duplicate' && !$this->direct ? 'insert' : (['add'=>'insert', 'edit'=>'update'][$name] ?? $name);
		$cb	= [$this->model, &$m];

		if($m == 'insert' || $this->response == 'add' || $this->overall){
			array_shift($cb_args);
		}elseif(method_exists(...$cb)){
			$this->direct && is_null($data) && array_pop($cb_args);
		}elseif($this->meta_type || !method_exists($cb[0], '__callStatic')){
			$m	= 'update_callback';
			$cb	= method_exists(...$cb) ? $cb : (($cb_args = [wpjam_admin('meta_type'), ...$cb_args])[0] ? 'wpjam_update_metadata' : wp_die('「'.$cb[0].'->'.$name.'」未定义'));

			$cb_args[]	= $args['fields']->get_defaults();
		}

		return wpjam_try($cb, ...$cb_args) ?? true ;
	}

	public function is_allowed($args=[]){
		return $this->capability == 'read' || array_all($args && !$this->overall ? (array)$this->parse_arg($args) : [null], fn($id)=> wpjam_can($this->capability, $id, $this->name));
	}

	public function get_data($id, $type=''){
		$cb		= $type ? $this->data_callback : null;
		$data	= $cb ? (is_callable($cb) ? wpjam_try($cb, $id, $this->name) : wp_die($this->title.'的 data_callback 无效')) : null;

		if($type == 'prev'){
			return array_merge($this->get_prev_data($id, 'prev'), ($data ?: []));
		}

		if($id && !$cb){
			$data	= wpjam_try([$this->model, 'get'], $id);
			$data	= $data instanceof WPJAM_Register ? $data->to_array() : ($data ?: wp_die('无效的 ID「'.$id.'」'));
		}

		return $data;
	}

	public function get_form($args, $type=''){
		$id		= $this->parse_id($args);
		$data	= $type == 'submit' && $this->response == 'form' ? [] : $this->get_data($id, 'callback');
		$fields	= $this->get_fields(wpjam_merge($args, array_filter(['data'=>$data])));
		$args	= wpjam_merge($args, ['data'=>($id && $type == 'form' ? $this->get_prev_data($id, 'prev') : [])]);

		return $fields->wrap('form', [
			'id'		=> 'list_table_action_form',
			'data'		=> $this->get_data_attr($args, 'form'),
			'button'	=> wpjam_button($this, null, $this->parse_arg($args))->prepend($this->render_prev(['class'=>['button'], 'title'=>'上一步']+$args))
		]);
	}

	public function get_fields($args, $prev=false, $output='object'){
		$arg	= $this->parse_arg($args);
		$fields	= wpjam_try('maybe_callback', $this->fields, $arg, $this->name) ?: wpjam_try($this->model.'::get_fields', $this->name, $arg);
		$fields	= array_merge(is_array($fields) ? $fields : [], ($prev ? $this->get_prev_fields($arg, true, '') : []));
		$fields	= ($cb = $this->model.'::filter_fields') && wpjam_callback($cb) ? wpjam_try($cb, $fields, $arg, $this->name) : $fields;

		if(!in_array($this->name, ['add', 'duplicate']) && isset($fields[$this->primary_key])){
			$fields[$this->primary_key]['type']	= 'view';
		}

		if($output == 'object'){
			$id		= $this->parse_id($args);
			$args	= ['id'=>$id]+$args+($id ? array_filter(['meta_type'=>wpjam_admin('meta_type'), 'model'=>$this->model]) : []);

			return wpjam_fields($fields, array_filter(['value_callback'=>$this->value_callback])+$args);
		}

		return $fields;
	}

	public function get_data_attr($args, $type='button'){
		$data	= wp_parse_args(($args['data'] ?? []), ($this->data ?: []))+($this->layout == 'calendar' ? wpjam_pick($args, ['date']) : []);
		$attr	= ['data'=>$data, 'action'=>$this->name, 'nonce'=>wp_create_nonce($this->parse_nonce_action($args))];
		$attr	+= $this->overall ? [] : ($args['bulk'] ? wpjam_pick($args, ['ids'])+$this->pick(['bulk', 'title']) : wpjam_pick($args, ['id']));

		return $attr+$this->pick($type == 'button' ? ['direct', 'confirm'] : ['next']);
	}

	public function render($args=[]){
		$args	+= ['id'=>0, 'data'=>[], 'bulk'=>false, 'ids'=>[]];
		$id		= $args['id'];

		if(is_callable($this->show_if)){
			$show_if	= wpjam_trap($this->show_if, ...(!empty($args['item']) ? [$args['item'], null] : [$id, $this->name, null]));

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
		$tag	= wpjam_tag($args['tag'] ?? 'a', $attr)->add_class($this->class)->style($this->style);

		if($this->redirect){
			$href	= maybe_callback($this->redirect, $id, $args);
			$tag	= $href ? $tag->add_class('list-table-redirect')->attr(['href'=>str_replace('%id%', $id, $href), 'target'=>$this->target]) : '';
		}elseif($this->filter || $this->filter === []){
			$filter	= maybe_callback($this->filter, $id) ?? false;
			$tag	= $filter === false ? '' : $tag->add_class('list-table-filter')->data('filter', array_merge(($this->data ?: []), (wpjam_is_assoc_array($filter) ? $filter : ($this->overall ? [] : wpjam_pick((array)$this->get_data($id), (array)$filter))), $args['data']));
		}else{
			$tag->add_class('list-table-'.(in_array($this->response, ['move', 'move_item']) ? 'move-' : '').'action')->data($this->get_data_attr($args));
		}

		$text	= wpjam_icon($args) ?? ($args['title'] ?? null);
		$text	??= (!$tag->has_class('page-title-action') && ($this->layout == 'calendar' || !$this->title)) ? wpjam_icon($this) : null;
		$text	??= $this->title ?: $this->page_title;

		return $tag ? $tag->text($text)->wrap(wpjam_get($args, 'wrap'), $this->name) : null;
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
		}elseif(in_array($key, ['title', 'callback', 'description', 'render'])){
			$value	= $this->{'column_'.$key} ?? $value;
		}elseif(in_array($key, ['sortable', 'sticky'])){
			$value	??= $this->{$key.'_column'};
		}

		return $value;
	}

	public function __invoke($args){
		$id		= $args['id'];
		$value	= $this->_field->val(null)->value_callback($args) ?? wpjam_value($args, $this->name) ?? $this->default;

		if(wpjam_is_assoc_array($value)){
			return $value;
		}
		
		$cb		= is_callable($this->callback) ? $this->callback : null;
		$value	= $cb ? wpjam_call($cb, $id, $this->name, $args['data'], $value) : $value;

		if($render	= $this->render){
			if(is_callable($render)){
				return $render($value, $args['data'], $this->name, $id);
			}

			if($this->type == 'img'){
				$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);

				return $value ? '<img src="'.wpjam_get_thumbnail($value, $size).'" '.image_hwstring($size['width']/2,  $size['height']/2).' />' : '';
			}elseif($this->type == 'timestamp'){
				return $value ? wpjam_date('Y-m-d H:i:s', $value) : '';
			}
		}

		return $cb ? $value : $this->filterable($value);
	}

	protected function filterable($value){
		if(is_array($value)){
			return array_map([$this, 'filterable'], $value);
		}

		if($value && str_contains($value, '[filter')){
			return $value;
		}

		$filter	= isset(wpjam_admin('list_table', 'filterable_fields')[$this->name]) ? [$this->_field->name => $value] : [];
		$value	= $this->options ? $this->_field->val($value)->render() : $value;

		return $filter ? ['filter'=>$filter, 'label'=>$value] : $value;
	}

	public static function registers($fields){
		foreach($fields as $key => $field){
			$column	= wpjam_pull($field, 'column');

			if($field['show_admin_column'] ?? is_array($column)){
				self::register($key, ($column ?: [])+wpjam_except(WPJAM_Data_Type::except($field), ['style', 'description', 'render'])+['order'=>10.5]);
			}
		}
	}
}

class WPJAM_List_Table_View extends WPJAM_List_Table_Component{
	public function __invoke(){
		if($this->_view){
			return $this->_view;
		}

		$view	= $this;
		$cb		= $this->callback;

		if($cb && is_callable($cb)){
			$view	= wpjam_trap($cb, $this->name, null);

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