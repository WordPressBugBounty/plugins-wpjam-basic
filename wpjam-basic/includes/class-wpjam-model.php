<?php
abstract class WPJAM_Instance{
	use WPJAM_Call_Trait;

	protected $id;

	protected function __construct($id){
		$this->id	= $id;
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function builtin($key){
		$type	= wpjam_get_builtin_type(get_class($this));
		$object	= $type ? wpjam_call('get_'.$type, $this->id) : null;

		return (!$object || !$key || $key === $type) ? $object : ($key === 'data'
			? $object->to_array()
			: $object->{(str_starts_with($key, $type.'_') ? '' : $type.'_').$key} ?? ($object->$key ?? $this->meta_get($key))
		);
	}

	public function meta_get($key){
		return wpjam_get_metadata(static::get_meta_type(), $this->id, $key);
	}

	public function meta_exists($key){
		return metadata_exists(static::get_meta_type(), $this->id, $key);
	}

	public function meta_input(...$args){
		return $args ? wpjam_update_metadata(static::get_meta_type(), $this->id, ...$args) : null;
	}

	abstract protected static function call_method($method, ...$args);

	public static function get_meta_type(){
		return static::call_method('get_meta_type') ?: wpjam_get_builtin_type(static::class);
	}

	public static function write($data, $id=0){
		wpjam_try(fn()=> static::validate_data($data, $id));

		$input	= ($type = static::get_meta_type()) ? wpjam_pull($data, 'meta_input') : [];
		$data	= static::sanitize_data($data, $id);

		return wpjam_tap(
			static::call_method(...($id ? ['update', $id, $data] : ['insert', $data])),
			fn($v)=> $input && wpjam_update_metadata($type, $id ?: $v, $input)
		);
	}

	public static function insert($data){
		return wpjam_catch([static::class, 'write'], $data);
	}

	public static function update($id, $data){
		return wpjam_catch([static::class, 'write'], $data, $id);
	}

	public static function delete($id){
		return wpjam_catch(fn()=> static::before_delete($id) || true ? static::call_method('delete', $id) : null);
	}

	public static function before_delete($id){
		if(array_all(['is_deletable', 'get_instance'], fn($m)=> method_exists(static::class, $m))){
			wpjam_try(static::class.'->is_deletable', $id) || wpjam_throw('indelible', '不可删除');
		}
	}

	protected static function validate_data($data, $id=0){
		return true;
	}

	protected static function sanitize_data($data, $id=0){
		return $data;
	}

	public static function instance(...$args){
		static $objects  = [];

		[$key, $cb]	= count($args) == 2 && is_callable($args[1]) ? $args : [($args ? implode(':', $args) : 'singleton'), null];
		$called		= self::get_called();
		$object		= $objects[$called][$key] ?? null;

		if(!$object){
			 $object	= $cb ? $cb($key) : static::create_instance(...$args);

			 if(!is_wp_error($object) && !is_null($object)){
			 	$objects[$called][$key]	= $object;
			 }
		}

		return $object;
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}
}

abstract class WPJAM_Model extends WPJAM_Instance implements ArrayAccess, IteratorAggregate{
	protected $_data	= [];

	public function __construct($data=[], $id=null){
		if($id){
			$this->id		= $id;
			$this->_data	= $data ? array_diff_assoc($data, static::get($id)) : [];
		}else{
			$id		= $data[static::get_primary_key()] ?? null;
			$exist	= isset($id) ? static::get($id) : null;

			$exist && ($this->id	= $id);

			$this->_data	= $exist ? array_diff_assoc($data, $exist) : $data;
		}
	}

	public function __get($key){
		return wpjam_exists($this->get_data(), $key) ? $this->get_data()[$key] : $this->meta_get($key);
	}

	public function __isset($key){
		return wpjam_exists($this->get_data(), $key) || $this->meta_exists($key);
	}

	public function __set($key, $value){
		$this->set_data($key, $value);
	}

	public function __unset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetExists($key){
		return wpjam_exists($this->get_data(), $key);
	}

	#[ReturnTypeWillChange]
	public function offsetGet($key){
		return $this->get_data($key);
	}

	#[ReturnTypeWillChange]
	public function offsetSet($key, $value){
		$this->set_data($key, $value);
	}

	#[ReturnTypeWillChange]
	public function offsetUnset($key){
		$this->unset_data($key);
	}

	#[ReturnTypeWillChange]
	public function getIterator(){
		return new ArrayIterator($this->get_data());
	}

	public function get_primary_id(){
		return $this->get_data(static::get_primary_key());
	}

	public function get_data($key=''){
		$data	= is_null($this->id) ? [] : static::get($this->id);
		$data	= array_merge($data, $this->_data);

		return $key ? ($data[$key] ?? null) : $data;
	}

	public function set_data($key, $value){
		if(!is_null($this->id) && static::get_primary_key() == $key){
			trigger_error('不能修改主键的值');
		}else{
			$this->_data[$key]	= $value;
		}

		return $this;
	}

	public function unset_data($key){
		$this->_data[$key]	= null;
	}

	public function reset_data($key=''){
		$this->_data	= $key ? wpjam_except($this->_data, $key) : [];

		return $this;
	}

	public function to_array(){
		return $this->get_data();
	}

	public function save($data=[]){
		$data	= array_merge($this->_data, $data);
		$data	= $this->id ? wpjam_except($data, static::get_primary_key()) : $data;

		return wpjam_tap(
			$this->id ? static::update($this->id, $data) : static::insert($data), 
			fn($v)=> ($this->id = $this->reset_data()->id ?: $v)
		);
	}

	public static function find($id){
		return static::get_instance($id);
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'dismiss'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true, 'confirm'=>true,	'bulk'=>true,	'order'=>1],
		];
	}

	public static function get_instance($id){
		return $id ? static::instance($id, fn($id)=> static::get($id) ? new static([], $id) : null) : null;
	}

	public static function call_method($method, ...$args){
		$name	= static::get_handler();

		if($method == 'insert_multi'){
			array_walk($args[0], fn($v)=> wpjam_try(fn()=> static::validate_data($v)));
		}elseif($method == 'delete_multi'){
			array_walk($args[0], fn($v)=> static::before_delete($v));
		}

		return wpjam_call_handler($name, $method, ...$args);
	}

	public static function get_handler(){
		return wpjam_get_handler(self::get_called()) ?: (property_exists(static::class, 'handler') ? static::$handler : null);
	}

	public static function set_handler($handler){
		return wpjam_register_handler(self::get_called(), $handler);
	}

	public static function __callStatic($method, $args){
		return in_array($method, ['item_callback', 'render_item'])
		? $args[0]
		: static::call_method(wpjam_suffix($method, '-', '_by_handler'), ...$args);
	}
}

class WPJAM_Handler extends WPJAM_Args{
	public function __call($method, $args){
		$method	= strtolower($method);
		$name	= array_shift($args);
		$object	= is_object($name) ? $name : ($this)($name);

		if(!$object){
			return new WP_Error('undefined_handler');
		}

		if(in_array($method, ['get_primary_key', 'get_meta_type', 'get_searchable_fields', 'get_filterable_fields'])){
			return $object->{wpjam_prefix($method, '-', 'get_')};
		}

		if(!method_exists($object, $method) && $this->mod($method, '_multi')){
			return wpjam_catch(fn()=> array_walk($args[0], fn($item)=> wpjam_try([$object, $method], $item)) || true);
		}

		$cb	= [$object, ['get_ids'=>'get_by_ids', 'get_all'=>'get_results'][$method] ?? $method];

		return is_callable($cb) ? wpjam_catch($cb, ...$args) : new WP_Error('undefined_method', [$method]);
	}

	public function __invoke($name, $args=[], $force=false){
		if(!$name){
			return;
		}

		if(is_array($name)){
			$args	= $name;
			$name	= wpjam_pull($args, 'name') ?: md5(serialize($args));
		}

		$object	= $force ? null : $this->get_arg('handlers['.$name.']');

		if(!$object && $args){
			$args	= maybe_closure($args, $name);
			$object	= is_object($args) ? $args : (!empty($args['table_name']) ? WPJAM_DB::create($args) : WPJAM_Items::create($args, $name));

			$object && $this->update_arg('handlers['.$name.']', $object);
		}

		return $object;
	}
}

class WPJAM_DB extends WPJAM_Args{
	private $query_vars;

	public function __construct($table, $args=[]){
		$pk	= $args['primary_key'] ??= 'id';
		$ck	= $args['cache_key'] ?? '';
		$ck	= $args['cache_key'] = $ck == $pk ? '' : $ck;

		$this->args	= wp_parse_args($args, [
			'table'			=> $table,
			'orderby'		=> $pk,
			'cache'			=> true,
			'cache_group'	=> $table,
			'cache_time'	=> DAY_IN_SECONDS
		]);

		$this->init();
		$this->process_arg('group_cache_key', fn($v)=> array_merge((array)$v, $ck ? [$ck] : []));
	}

	public function __call($method, $args){
		if($this->mod($method, 'where_')){
			if($method == 'fragment'){
				return $this->where('', ...$args);
			}

			if(in_array($method, ['any', 'all'])){
				$args[0]	= array_filter(wpjam_map($args[0], fn($v, $k)=> is_numeric($k) ? '('.$v.')' : $this->where($k, $v, 'value')));
				$args[0]	= implode($method == 'any' ? ' OR ' : ' AND ', $args[0]);

				return $this->where('', ...$args);
			}

			return $this->operator($method) ? $this->where(array_shift($args).'__'.$method, ...$args) : $this;
		}elseif(array_key_exists($method, $this->query_vars)){
			$value	= $args ? $args[0] : ($method == 'found_rows' ? true : null);

			if(!is_null($value)){
				$this->query_vars[$method]	= $value;
			}

			return $this;
		}elseif($this->mod($method, '_by_db')){
			global $wpdb;

			if($method == 'last_error'){
				return new WP_Error($args[0].'_error', $wpdb->$method);
			}

			if(in_array($method, ['insert', 'update', 'delete', 'replace'])){
				if($this->field_types){
					$wpdb->field_types	= array_merge(($types = $wpdb->field_types), $this->field_types);
				}
			}elseif(in_array($method, ['get_results', 'get_ids', 'get_row', 'get_col', 'get_var'])){
				if($method == 'get_ids'){
					$args[0]['fields']	= $this->table.'.'.$this->primary_key;

					$method	= 'get_col';
				}

				if(is_array($args[0])){
					$args[0]	= $this->get_request($args[0]+(in_array($method, ['get_row', 'get_var']) ? ['limits'=> 'LIMIT 1'] : []));
				}

				if(in_array($method, ['get_results', 'get_row'])){
					$args[1]	= ARRAY_A;
				}
			}

			try{
				return $wpdb->$method(...$args);
			}finally{
				isset($types) && ($wpdb->field_types	= $types);
			}
		}elseif(in_array($method, ['get_col', 'get_var', 'get_row', 'get_results'])){
			$clauses	= $this->get_clauses($args[0] ?? []);
			$action		= $method == 'get_results' && in_array($clauses['fields'], ['*', $this->table.'.*']) ? 'get_ids' : $method;
			$items		= [$this, $action.'_by_db']($clauses);
			$total		= $method == 'get_results' && ($args[1] ?? null) ? $this->find_total() : null;
			$items		= $action == 'get_ids' ? array_values($this->get_by_ids($items)) : $items;

			return isset($total) ? ['items'=>$items, 'total'=>$total] : $items;
		}elseif(str_contains($method, '_meta')){
			return ($object	= wpjam_get_meta_type($this->meta_type)) ? $object->$method(...$args) : null;
		}elseif($this->mod($method, 'cache_')){
			return [WPJAM_Cache::create($this), $method](...$args);
		}elseif($this->mod($method, '_last_changed')){
			$ck	= $this->group_cache_key;
			$ck	= $ck && is_array($args[0] ?? '') ? array_find_key($args[0], fn($v, $k)=> !is_array($v) && in_array($k, $ck)) : [];
			$k	= 'last_changed'.($ck ? ':'.$ck.':'.$args[0][$ck] : '');

			return $method == 'get'
			? ($this->cache_get($k) ?: wpjam_tap(microtime(), fn($v)=> $this->cache_set($k, $v)))
			: ($method == 'delete' ? $this->cache_delete($k) : null);
		}

		return new WP_Error('undefined_method', [$method]);
	}

	protected function init(){
		$this->meta_query	= null;
		$this->query_vars	= [
			'where'		=> [],
			'limit'		=> 0,
			'offset'	=> 0,
			'orderby'	=> null,
			'order'		=> null,
			'groupby'	=> null,
			'having'	=> null,
			'found_rows'	=> false,
			'search_term'	=> null,
			'search_columns'	=> null
		];
	}

	public function find_by($field, $value, $order='ASC', $output='results'){
		return [$this, 'get_'.$output.'_by_db']([
			'where'		=> 'WHERE '.(is_array($field) ? $this->where_all($field, 'value') : $this->where($field, $value, 'value')),
			'orderby'	=> $order ? 'ORDER BY `'.$this->get_arg('orderby').'` '.$order : ''
		]);
	}

	public function find_one($value, $field='', $order=''){
		return $this->find_by($field ?: $this->primary_key, $value, $order, 'row');
	}

	public function find_total(){
		return $this->get_var_by_db("SELECT FOUND_ROWS();");
	}

	public function get($id){
		return $this->get_by($this->primary_key, $id);
	}

	public function operator($name){
		return ($this->operator ??= array_reduce(['in', 'like', 'between', 'exists', 'regexp'], fn($c, $v)=>$c+[$v=>strtoupper($v), 'not_'.$v=>'NOT '.strtoupper($v)], ['not'=>'!=', 'lt'=>'<', 'lte'=>'<=', 'gt'=>'>', 'gte'=>'>=']))[$name] ?? null;
	}

	public function get_by($field, $value=null, $order='ASC'){
		$pk		= $this->primary_key;
		$type	= is_array($field) ? '' : ($field == $pk ? 'primary' : (in_array($field, $this->group_cache_key) ? 'cache' : ''));
		$multi	= is_array($value);

		if($multi){
			$value	= wpjam_filter(array_filter($value), 'unique');

			if(!$value){
				return [];
			}

			if($type == 'primary'){
				$ids	= $value;
				$data	= array_filter($this->cache_get_multiple($ids) ?: [], 'is_array');
				$rest	= array_diff($ids, array_keys($data));

				if($rest){
					if($result = $this->find_by($pk, $rest)){
						$result	= array_column($result, null, $pk);
						$data	+= $result;
						$rest	= array_diff($rest, array_keys($result));

						$this->cache_set_multiple($result);
					}

					$rest && $this->cache_set_multiple(array_fill_keys($rest, []), 5);
				}

				if($data){
					$data	= wpjam_pick($data, $ids);

					$this->meta_type && wpjam_lazyload($this->meta_type.'_meta', array_keys($data));

					wpjam_lazyload($this->lazyload_key, $data);
				}

				return $data;
			}elseif($type == 'cache'){
				$result	= wpjam_fill($value, fn($v)=> $this->query([$field=>$v, 'order'=>$order], 'cache'));
				$data	= wpjam_map(array_filter($result, fn($v)=> is_array($v[0]) && isset($v[0]['items'])), fn($v)=> $v[0]['items']);
				$rest	= array_diff_key($result, $data);
				$ids	= array_merge($rest ? $this->query([$field.'__in'=>array_keys($rest), 'order'=>$order], 'ids') : [], ...array_values($data));
				$result	= array_values($this->get_by_ids($ids));
				$data	= array_map(fn($ids)=> array_values($this->get_by_ids($ids)), $data);

				foreach($rest as $v => $r){
					$data[$v]	= wp_list_filter($result, [$field => $v]) ?: [];

					$this->cache_set_salted($r[1], ['items'=>array_column($data[$v], $pk)], $r[2]);
				}

				return $data;
			}
		}else{
			if($type && ($queue	= $this->pending_queue)){
				$queue	= is_array($queue) ? ($queue[$field] ?? '') : ($type == 'primary' ? $queue : '');
				$queue	&& wpjam_load_pending($queue, fn($pending)=> $this->get_by($field, $pending, $order));
			}

			if($type == 'primary'){
				return ($v = ($id = $value) ? $this->cache_get($id) : []) === false
				? wpjam_tap($this->find_one($id), fn($v)=> $this->cache_set($id, $v ?: [], $v ? $this->cache_time : 60))
				: $v;
			}elseif($type == 'cache'){
				return $this->query([$field=>$value, 'order'=>$order], 'items');
			}
		}

		return $this->find_by($field, $value, $order);
	}

	public function get_by_values($field, $values, $order='ASC'){
		return $this->get_by($field, $values, $order);
	}

	public function get_by_ids($ids){
		return $this->get_by($this->primary_key, $ids);
	}

	public function get_ids($ids){
		return $this->get_by_ids($ids);
	}

	public function update_caches($values, $primary=false){
		return $this->get_by(($primary ? '' : $this->cache_key) ?: $this->primary_key, $values);
	}

	protected function get_clauses($fields=[]){
		$table		= $this->table;
		$pk			= $this->primary_key;
		$vars		= $this->query_vars;
		$meta_query = $this->meta_query;
		$clauses	= $meta_query ? $meta_query->get_sql($this->meta_type, $table, $pk, $this) : [];
		$limit		= (int)$vars['limit'];
		$offset		= (int)$vars['offset'];
		$found_rows	= $limit && $vars['found_rows'];
		$groupby	= $this->group_by() ?: ($meta_query ? $table.'.'.$pk : '');
		$orderby	= $this->order_by();
		$where 		= (($where = $this->where()) || ($clauses['where'] ?? '')) ? ($where ?: '1=1').wpjam_pull($clauses, 'where') : '';

		return $clauses	+ [
			'found_rows'=> $found_rows ? 'SQL_CALC_FOUND_ROWS' : '',
			'fields'	=> $fields	?: ($meta_query ? $table.'.*' : '*'),
			'where'		=> $where	? ' WHERE '.$where : '',
			'groupby'	=> $groupby	? ' GROUP BY '.$groupby.($vars['having'] ? ' HAVING '.$vars['having'] : '') : '',
			'orderby'	=> $orderby	? ' ORDER BY '.$orderby : '',
			'limits'	=> $limit	? ' LIMIT '.($offset ?: 0).', '.$limit : ($offset ? ' OFFSET '.$offset : '')
		];
	}

	public function get_request($clauses=null){
		$clauses	??= $this->get_clauses();
		$fields 	= wpjam_pull($clauses, 'fields');
		$clauses	+= ['fields'=>(is_array($fields) ? $this->format($fields, 'fields') : $fields) ?: '*'];

		return sprintf("SELECT %s %s %s FROM `{$this->table}` %s %s %s %s %s", ...array_map(fn($k)=> $clauses[$k] ?? '', ['found_rows', 'distinct', 'fields', 'join', 'where', 'groupby', 'orderby', 'limits']));
	}

	public function get_sql($fields=[]){
		return $this->get_request($this->get_clauses($fields));
	}

	public function search(...$args){
		if($args){
			return $this->search_term(...$args);
		}

		$term	= $this->query_vars['search_term'];
		$fields	= $this->query_vars['search_columns'] ?: $this->searchable_fields;
		$fields	= is_array($fields) ? (wp_is_numeric_array($fields) ? $fields : array_keys($fields)) : wp_parse_list($fields);

		return $term && $fields ? $this->where_any(wpjam_array($fields, fn($i, $k)=> [$k.'__like', '%'.$term.'%'])) : $this;
	}

	public function group_by(...$args){
		if($args){
			return $this->groupby(...$args);
		}

		return ($by	= $this->query_vars['groupby']) ? (array_any([',', '(',], fn($v)=> str_contains($by, $v)) ? $by : $this->prepare_by_db('%i', $by)) : '';
	}

	public function order_by(...$args){
		if(count($args) >= 2){
			[$by, $order]	= $args;

			if($by == 'rand'){
				return 'RAND()';
			}elseif(preg_match('/RAND\(([0-9]+)\)/i', $by, $matches)){
				return $by;
			}elseif(str_contains($by, ',')){
				return $by;
			}elseif(str_contains($by, '(') && str_contains($by, ')')){
				return $by;
			}elseif(str_ends_with($by, '__in')){
				return null;
				// $field	= str_replace('__in', '', $by);
			}

			if($this->meta_query && ($clauses = $this->meta_query->get_clauses())){
				if($mq = in_array($by, [array_first($clauses)['key'] ?? '', 'meta_value', 'meta_value_num'])
					? array_first($clauses)
					: ($clauses[$by] ?? null)
				){
					$num	= $by == 'meta_value_num';
					$by		= $mq['alias'].".meta_value";

					return ($num ? $by.'+0' : (empty($mq['type']) ? $by : "CAST(".$by." AS {$mq['cast']})")).' '.$order;
				}
			}

			return $this->prepare_by_db('%i', $by).' '.$order;
		}elseif($args){
			return $this->orderby(...$args);
		}

		$vars	= $this->query_vars;
		$by		= $vars['orderby'];
		$order	= $vars['order'] ?? ($this->get_arg('order') ?: 'DESC');
		$order	= is_string($order) && 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';

		return isset($by)
		? ($by ? implode(', ', wpjam_array(is_array($by) ? $by : [$by=>$order], fn($k, $v)=> [null, $this->order_by($k, $v)], true)) : '')
		: (($by = $vars['groupby'] ? '' : $this->get_arg('orderby')) ? $by.' '.$order : '');
	}

	public function insert($data){
		$wpdb	= $GLOBALS['wpdb'];
		$multi	= wp_is_numeric_array($data);

		[$data,$id]	= $multi ? [array_filter($data), null] : [array_filter($data, fn($v)=> !is_null($v)), $data[$this->primary_key] ?? null];

		if($multi && !$data){
			return 0;
		}

		$this->clear([], $data);

		if($id || $multi){
			$wpdb->check_current_query = false;

			$data	= $multi ? $data : [$data];
			$fields	= $this->format(array_keys(array_first($data)), 'fields');
			$update	= implode(', ', array_map(fn($v)=> $v.' = VALUES('.$v.')', explode(', ', $fields)));
			$values	= implode(', ', array_map(fn($v)=> '('.$this->format($v, 'values').')', $data));
			$result	= $this->query_by_db("INSERT INTO `{$this->table}` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$update}");
		}else{
			$result	= $this->insert_by_db($this->table, $data);
		}

		return $result === false
		? $this->last_error_by_db('insert')
		: ($multi ? $result : wpjam_tap($id ?: $wpdb->insert_id, fn($id)=> $this->cache_delete($id)));
	}

	public function insert_multi($data){
		return $this->insert(array_values(array_filter($data)));	// 自增的情况可能无法无法删除缓存，请注意
	}

	/*
	update($id, $data);
	update($data, $where);
	update($data);
	*/
	public function update(...$args){
		if(!$args){
			return 0;
		}

		[$id, $data, $where]	= count($args) == 1 ? [null, ...$args, $this->where()] : (is_array($args[0]) ? [null, ...$args] : [$args[0], $args[1], null]);

		if(!$data || (isset($where) && !$where)){
			return 0;
		}

		$this->clear($id, $data, $where);

		if(count($args) >= 2){
			$result	= $this->update_by_db($this->table, $data, $where ?? [$this->primary_key => $id]);
		}else{
			$data	= $this->format($data, 'data');
			$result	= $this->query_by_db("UPDATE `{$this->table}` SET {$data} WHERE {$where}");
		}

		return $result === false ? $this->last_error_by_db('update') : $result;
	}

	/*
	delete($where);
	delete($id);
	delete();
	*/
	public function delete(...$args){
		[$id, $where]	= $args ? (wpjam_is_assoc_array($args[0]) ? [null, $args[0]] : [$args[0], null]) : [null, $this->where()];

		if(isset($where) && !$where){
			return 0;
		}

		$this->clear($id, [], $where);

		$key	= $this->primary_key;
		$where	??= is_array($id) ? $this->where($key, $id, 'value') : [$key => $id];

		if(is_array($where)){
			$result	= $this->delete_by_db($this->table, $where);
		}else{
			$result = $this->query_by_db("DELETE FROM `{$this->table}` WHERE {$where}");
		}

		if($result === false){
			return $this->last_error_by_db('delete');
		}

		if($id){
			wpjam_map((array)$id, [$this, 'delete_meta_by_id']);
		}else{
			$this->delete_orphan_meta($this->table, $key);
		}

		return $result;
	}

	public function delete_by($field, $value){
		return $this->delete([$field => $value]);
	}

	public function delete_multi($ids){
		return $ids ? $this->delete(array_values(array_filter($ids))) : 0;
	}

	protected function clear($ids=[], $data=[], $where=[]){
		$this->delete_last_changed();

		if(($ck = $this->group_cache_key) || $this->cache){
			$ids	= (array)$ids;
			$pk		= $this->primary_key;
			$where	= $where ? [is_array($where) ? $this->where_all($where, 'value') : $where] : [];
			$data	= wp_is_numeric_array($data) ? $data : [$data];
			$ids	= array_merge($ids, array_column($data, $pk));

			$ids && $this->cache_delete_multiple($ids);
			$ids && $ck && ($where[] = $this->where($pk, $ids, 'value'));

			if($where){
				if($result	= $this->get_results_by_db([
					'fields'	=> [$pk, ...$ck],
					'where'		=> 'WHERE '.$this->where_any($where, 'value')
				])){
					$this->cache_delete_multiple(array_column($result, $pk));

					$ck && ($data	= array_merge($data, $result));
				}
			}

			$ck && wpjam_map($ck, fn($k)=> wpjam_map(array_unique(array_column($data, $k)), fn($v)=> $this->delete_last_changed([$k => $v])));
		}
	}

	protected function format($field, ...$args){
		$types	= $this->field_types ?: [];

		if(is_array($field)){
			$type	= $args[0];

			if($type == 'fields'){
				$query	= array_fill(0, count($field), '%i');
				$args	= $field;
			}else{
				$query	= $args = [];

				foreach($field as $k => $v){
					$query[]	= ($type == 'data' ? "%i = " : '').(is_null($v) ? 'NULL' : ($types[$k] ?? '%s'));
					$args		= array_merge($args, ($type == 'data' ? [$k] : []), (is_null($v) ? [] : [$v]));
				}
			}

			$query	= implode(', ', $query);
		}else{
			$op	= $args[0];
			$v	= $args[1];
			$f	= $types[$field] ?? '%s';
			
			if(in_array($op, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'])){
				$v	= is_array($v) ? $v : array_map('trim', explode(',', $v));
				$c	= count($v);
			
				if(in_array($op, ['IN', 'NOT IN'])){
					if($c <= 1){
						$v	= array_first($v ?: ['']);
						$op	= $op == 'IN' ? '=' : '!=';
					}else{
						$f	= '('.implode(', ', array_fill(0, $c, $f)).')';
					}
				}else{
					$f	= $f.' AND '.$f;
				}
			}elseif(in_array($op, ['LIKE', 'NOT LIKE'])){
				$f	= '%s';
				$v	= (str_starts_with($v, '%') ? '%' : '').$this->esc_like_by_db(trim($v, '%')).(str_ends_with($v, '%') ? '%' : '');
			}elseif(in_array($op, ['REGEXP', 'NOT REGEXP', 'RLIKE'])){
				$f	= '%s';
			}elseif(in_array($op, ['EXISTS', 'NOT EXISTS'])){
				$query	= '%i IS '.($op == 'EXISTS' ? 'NOT NULL' : 'NULL');

				return $this->prepare_by_db($query, [$field]);
			}

			$args	= [$field, ...(array)$v];
			$query	= '%i '.$op.' '.$f;
		}

		return $args ? $this->prepare_by_db($query, $args) : $query;
	}

	public function where(...$args){
		if(!$args){
			return wpjam_tap(implode(' AND ', $this->search()->query_vars['where']), fn()=> $this->init());
		}

		[$field, $value, $output]	= $args+['', null, 'object'];

		if(!$field){
			$value	= $value ? '('.$value.')' : '';
		}elseif(isset($value)){
			$op		= ($pos = strrpos($field, '__')) ? $this->operator(substr($field, $pos + 2)) : '';
			$field	= $op ? substr($field, 0, $pos) : $field;
			$value	= $this->format($field, $op ?: (is_array($value) ? 'IN' : '='), $value);
		}

		if($output == 'object'){
			if($value){
				$this->query_vars['where'][]	= $value;
			}

			return $this;
		}

		return $value;
	}

	public function query(...$args){
		if(!$args){
			return $this;
		}

		$vars	= $args[0];
		$output	= $args[1] ?? 'object';

		if(in_array($output, ['cache', 'items', 'ids'])){
			$vars	+= ['no_found_rows'=>true, 'suppress_filters'=>true];
		}else{
			$vars	= apply_filters('wpjam_query_vars', $vars, $this);
			$vars	= isset($vars['groupby']) ? ['no_found_rows'=>true]+wpjam_except($vars, ['since', 'cursor']) : $vars;
			$vars	+= empty($vars['no_found_rows']) ? ['number'=>50] : [];
		}

		$pk			= $this->primary_key;
		$qv			= $vars;
		$orderby	= $qv['orderby'] ?? $this->get_arg('orderby');
		$fields		= wpjam_pull($qv, 'fields');
		$cache		= wpjam_pull($qv, 'cache_results', $output != 'ids') || $output == 'cache';
		$suppress	= wpjam_pull($qv, 'suppress_filters');
		$found_rows	= !wpjam_pull($qv, 'no_found_rows');

		if($this->meta_type && ($meta_query = wpjam_pull($qv, ['meta_key', 'meta_value', 'meta_compare', 'meta_compare_key', 'meta_type', 'meta_type_key', 'meta_query']))){
			$this->meta_query	= new WP_Meta_Query();
			$this->meta_query->parse_query_vars($meta_query);
		}

		foreach($qv as $k => $v){
			if(is_null($v)){
				continue;
			}

			if(array_key_exists($k, $this->query_vars)){
				$this->query_vars[$k]	= $v;
			}elseif($k == 'number'){
				if($v == -1){
					$found_rows	= false;
				}else{
					$this->limit($v);
				}
			}elseif(in_array($k, ['s', 'search'])){
				$this->search($v);
			}elseif(in_array($k, ['cursor', 'since'])){
				$v > 0 && $this->where($orderby.'__'.($k == 'cursor' ? 'lt' : 'gt'), $v);
			}elseif(in_array($k, ['exclude', 'include'])){
				$v && is_array($v) && $this->where($pk.'__'.($k == 'include' ? 'in' : 'not_in'), $v);
			}else{
				$this->where($k, $v);
			}
		}

		$found_rows	&& $this->found_rows();

		$clauses	= $this->get_clauses($fields);
		$clauses	= $suppress ? $clauses : apply_filters('wpjam_clauses', $clauses, $this);
		$request	= $this->get_request($clauses);
		$cache		= $cache && !str_contains(strtoupper($orderby), ' RAND(') && in_array($clauses['fields'], ['*', $this->table.'.*']);
		$data		= false;

		if($cache){
			$vars	= map_deep($vars, 'strval');
			$lc		= $this->get_last_changed($vars);
			$ck		= md5(serialize($vars).$this->remove_placeholder_escape_by_db($request));
			$data	= $this->cache_get_salted($ck, $lc);

			if($output == 'cache'){
				return [$data, $ck, $lc];
			}
		}

		if($data === false || !isset($data['items'])){
			$items	= ($cache || $output == 'ids') ? $this->get_ids_by_db($clauses) : $this->get_results_by_db($request);
			$data	= ['items'=>$items]+($found_rows ? ['total'=>$this->find_total()] : []);

			$cache && $this->cache_set_salted($ck, $data, $lc);
		}

		if($output == 'ids'){
			return $data['items'];
		}

		if($cache){
			$data['items']	= array_values($this->get_by_ids($data['items']));
		}

		if($output == 'items'){
			return $data['items'];
		}

		if($found_rows){
			$data['next_cursor']	= 0;

			if(!empty($qv['number'])){
				$data['max_num_pages']	= ceil($data['total'] / $qv['number']);
				$data['next_cursor']	= $data['items'] && $data['max_num_pages'] > 1 ? (int)(end($data['items'])[$orderby]) : 0;
			}
		}else{
			$data['total']	= count($data['items']);
		}

		$data['datas'] 		= &$data['items'];	// 兼容
		$data['found_rows']	= &$data['total'];	// 兼容
		$data['request']	= $request;

		return $output == 'object' ? (object)$data : $data;
	}

	public function query_items(...$args){
		if(is_array($args[0])){
			return $this->query(...($args+['', 'array']));
		}

		wpjam_map(['orderby'=>'orderby', 'order'=>'order', 's'=>'search_term'], fn($v, $k)=> is_null($this->$v) && $this->$v($this->get_param($k)));

		wpjam_map($this->filterable_fields ?: [], fn($k)=> $this->where($k, $this->get_param($k)));

		return $this->limit($args[0])->offset($args[1])->found_rows()->get_results([], true);
	}

	public function get_one_by($field, $value, $order='ASC'){
		return ($items = $this->get_by($field, $value, $order)) ? array_first($items) : [];
	}

	public function find($fields=[]){
		trigger_error('find');
		return $this->get_results($fields);
	}

	public function cache_delete_by($field, $value, $order='ASC'){	// del 2026-06-30
		trigger_error('cache_delete_by');

		in_array($field, $this->group_cache_key) && wpjam_map((array)$value, fn($v)=> $this->cache_delete($this->query([$field=>$v, 'order'=>$order], 'cache')[1]));
	}

	public function get_wheres(){
		return $this->where();
	}

	public static function create($args, $name=null){
		return ($name ??= $args['table_name'] ?? '') ? new static($name, $args) : null;
	}
}

class WPJAM_Items extends WPJAM_Args{
	public function __construct($args=[]){
		$args	+= ['items_type'=> wpjam_pull($args, 'type')];
		$type	= $args['items_type'];

		$this->args	= $args+($type ? ([
			'option'		=> ['primary_key'=>'option_key'],
			'transient'		=> ['item_type'=>''],
			'meta'			=> ['parent_key'=>($args['meta_type'] ?? '').'_id'],
			'post_content'	=> ['parent_key'=>'post_id'],
			'cache'			=> ['item_type'=>'', 'retry_times'=> 10, 'cache_group'=>'list_cache'],
		][$type] ?? []) : [])+[
			'item_type'		=> 'array',
			'primary_key'	=> 'id'
		];
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_items')){
			if($method == 'get_items'){
				$res	= $this->cached_items;

				if(is_array($res)){
					return $res;
				}
			}

			if($method == 'update_items'){
				if($this->compressed && is_array($args[0])){
					$args[0]	= wpjam_compress($args[0]);
				}
			}

			if($this->$method){
				$res	= $this->call($method, ...$args);
			}else{
				$type	= $this->items_type;

				if($type == 'option'){
					$keys	= ['option_name'];
					$map	= ['get_items'=>'get_option', 'update_items'=>'update_option'];
				}elseif($type == 'setting'){
					$keys	= ['option_name', 'setting_name'];
					$map	= ['get_items'=>'wpjam_get_setting', 'update_items'=>'wpjam_update_setting'];
				}elseif($type == 'transient'){
					$keys	= ['transient'];
					$map	= ['get_items'=>'get_transient', 'update_items'=>'set_transient'];
					$args	= [...$args, ...($method == 'update_items' ? [DAY_IN_SECONDS] : [])];
				}elseif($type == 'meta'){
					$keys	= ['meta_type', 'object_id', 'meta_key'];
					$map	= ['get_items'=>'get_metadata', 'update_items'=>'update_metadata', 'delete_items'=>'delete_metadata'];
					$args	= [...$args, ...($method == 'get_items' ? [true] : ($method == 'update_items' ? [$this->pre_value] : []))];
				}elseif($type == 'post_content'){
					if($post_id = $this->post_id){
						$object	= wpjam_get_post($this->post_id, 'object');

						if($method == 'get_items'){
							$res	= $object->content;
							$res	= (!$this->compressed || is_serialized($res)) ? $object->get_unserialized() : $res;
						}elseif($method == 'update_items'){
							$res	= $object->save(['content'=>$args[0] ?: '']);
						}
					}
				}elseif($type == 'cache'){
					if($key = $this->cache_key){
						$object	= WPJAM_Cache::create($this);

						if($method == 'get_items'){
							$res	= [$object, 'get_with_cas']($key);

							[$res, $this->cas_token]	= $res === false ? [false, null] : [$res['value'], $res['cas']];
						}elseif($method == 'update_items'){
							$cas	= $this->cas_token;
							$res	= [$object, $cas ? 'cas' : 'add'](...[...($cas ? [$cas] : []), $key, ...$args]);
						}
					}
				}

				if(!isset($res)){
					if(isset($map[$method]) && array_all($keys, fn($k)=> $this->$k)){
						$res	= ($map[$method])(...[...array_values(wpjam_pick($this, $keys)), ...$args]);

						if($type == 'meta' && $method == 'get_items'){
							$this->pre_value	= $res;
						}
					}else{
						$res	= $method == 'delete_items' ? $this->update_items([]) : true;
					}
				}
			}

			if($method == 'get_items'){
				$res	= $this->compressed && $res && !is_array($res) ? wpjam_uncompress($res) : ($res ?: []);
				$res	= $this->cached_items = $this->filter_items ? $this->filter_items($res) : $res;
			}else{
				unset($this->cached_items);
			}

			return $res;
		}elseif(str_contains($method, '_setting')){
			if($this->option_name){
				$i		= str_starts_with($method, 'update_') ? 2 : 1;
				$args	= (isset($args[$i]) && !is_numeric($args[$i])) ? array_slice($args, 0, $i) : $args;

				return ('wpjam_'.$method)($this->option_name, ...$args);
			}
		}elseif(in_array($method, ['increment', 'decrement'])){
			if($this->item_type == 'array'){
				[$k, $v]	= array_pad($args, 2, 1);

				return wpjam_tap(
					(int)$this->get($k)+($method == 'increment' ? $v : (0-$v)),
					fn($v)=> $this->process(fn($items)=> array_merge($items, [$k=>$v]))
				);	
			}
		}elseif(in_array($method, ['insert', 'add', 'update', 'replace', 'set', 'delete', 'remove'])){
			return wpjam_retry($this->retry_times ?: 1, fn()=> $this->retry($method, $args));
		}
	}

	protected function retry($method, $args){
		$items	= $this->get_items();
		$type	= $this->item_type;
		$key	= $this->primary_key;
		$title	= $this->primary_title ?: 'ID';
		$add	= $method == 'insert' || ($method == 'add' && count($args) <= 1);
		$id		= $add ? null : array_shift($args);

		if(!$add){
			$id || wpjam_throw('empty_'.($key ?: 'id'), ($key ? $title : 'ID').'不能为空');

			if($method == 'set'){
				$method	= isset($items[$id]) ? 'set' : 'add';
			}elseif(($exist	= isset($items[$id])) === ($method == 'add')){
				wpjam_throw(($exist ? 'duplicate_' : 'invalid_').($key ?: 'id'), ($key ? $title : 'ID').'-「'.$id.'」'.($exist ? '已存在' : '不存在'));
			}
		}

		if(in_array($method, ['delete', 'remove'])){
			unset($items[$id]);
		}else{
			if(!$args || ($type == 'array' && !is_array($args[0]))){
				wpjam_throw(...($args ? ['invalid_item', '不是数组'] : ['empty_item', '不能为空']));
			}

			$item	= $args[0];

			if($type == 'array'){
				if($add){
					if(in_array($key, ['option_key', 'id'])){
						$id	= $items ? max(array_map(fn($id)=> (int)str_replace('option_key_', '', $id), array_keys($items)))+1 : 1;
						$id	= $key == 'option_key' ? 'option_key_'.$id : $id;
					}else{
						$id	= $item[$key] ?? '';

						(!$id || isset($items[$id])) && wpjam_throw(($id ? 'duplicate_' : 'empty_').$key, $title.($id ? '「'.$id.'」已被使用' : '不能为空'));
					}
				}

				if(($uk = $this->unique_key) && ($add || isset($item[$uk]))){
					$uv		= $item[$uk] ?? '';
					$blank	= !$uv && !is_numeric($uv);

					if($blank || array_find($items, fn($v, $k)=> ($add || $id != $k) && $v[$uk] == $uv)){
						wpjam_throw(($blank ? 'empty_' : 'duplicate_').$uk, ($this->unique_title ?: $uk).($blank ? '不能为空' : '「'.$uv.'」已被使用'));
					}
				}

				$item	= [$key=>$id]+$item;
			}else{
				$add && in_array($item, $items) && wpjam_throw('duplicate_item', '不能重复');
			}

			$item	= $this->prepare_item ? $this->call('prepare_item', $item, $id, $method, $items) : $item;
		}

		if(in_array($method, ['add', 'insert'])){
			($max	= $this->max_items) && count($items) >= $max && wpjam_throw('over_max_items', '最大允许数量：'.$max);
			$last	= $this->last ?? ($method == 'add');

			if($type == 'array' || $id){
				$item	= $type == 'array' ? array_filter($item, fn($v)=> !is_null($v)) : $item;
				$items	= $last ? array_replace($items, [$id=>$item]) : [$id=>$item]+$items;
			}else{
				$last ? array_push($items, $item) : array_unshift($items, $item);
			}
		}elseif(in_array($method, ['set', 'update', 'replace'])){
			$items[$id]	= $method == 'update' && $type == 'array' ? wp_parse_args($item, $items[$id]) : $item;
		}

		if($type == 'array' && $items && in_array($key, ['option_key','id'])){
			$except	= array_filter([$key, $this->parent_key]);
			$items	= wpjam_map($items, fn($item)=> wpjam_except($item, $except));
		}

		$result	= $this->update_items($items);

		return $result && $method == 'insert' && $type == 'array' ? ['id'=>$id, 'last'=>(bool)$last] : $result;
	}

	public function process($cb){
		return is_closure($cb) ? wpjam_retry($this->retry_times ?: 1, fn()=> $this->update_items(wpjam_call($cb, $this->get_items()))) : null;
	}

	public function query_items($args){
		$s		= trim(wpjam_pull($args, 's') ?: '');
		$number	= wpjam_pull($args, 'number') ?: 50;
		$offset	= wpjam_pull($args, 'offset') ?: 0;
		$items	= $this->parse_items();
		$items	= array_values(($args || $s) ? array_filter($items, fn($item)=> (!$args || wpjam_matches($item, $args)) && (!$s || array_any($item, fn($v)=> str_contains($v, $s))) ) : $items);

		return ['total'=>count($items), 'items'=>array_slice($items, $offset, $number)];
	}

	public function parse_items($items=null){
		$items	??= $this->get_items();

		if($this->item_type == 'array'){
			$items	= $items && is_array($items) ? wpjam_map($items, fn($v, $k)=> [$this->primary_key => $k]+(is_array($v) ? $v : [])) : [];
			$items && wpjam_lazyload($this->lazyload_key, $items);
		}

		return $items;
	}

	public function get_results(){
		return $this->parse_items();
	}

	public function reset(){
		return $this->delete_items();
	}

	public function empty(){
		return wpjam_tap($this->get_items(), fn($v)=> $v && $this->delete_items());
	}

	public function move($id, $data){
		return $this->process(fn($items)=> wpjam_pick($items, wpjam_move(array_keys($items), $id, $data)));
	}

	public function get($id){
		return ($items = wpjam_pick($this->get_items(), [$id])) ? $this->parse_items($items)[$id] : null;
	}

	public static function create($args, $name=null){
		if(!empty($args['option_name'])){
			$args	+= array_filter(['setting_name'=>wpjam_pull($args, 'items_field')]);
			$args	+= ['type'=>(!empty($args['setting_name']) ? 'setting' : 'option')];
		}elseif(!empty($args['items_model'])){	// 不建议
			$args	+= wpjam_fill(['get_items', 'update_items'], fn($k)=> [$args['items_model'], $k]);
		}elseif(wpjam_pull($args, 'type') == 'option_items'){	// 不建议
			$args	+= ['type'=>'option', 'option_name'=>$name];
		}else{
			$args	= (!empty($args['items_type']) || (!empty($args['get_items']) && !empty($args['update_items']))) ? $args : [];
		}

		return $args ? new static($args) : null;
	}
}

class WPJAM_Notice extends WPJAM_Items{
	public static function render($type){
		foreach((self::get_instance($type))->get_items() as $key => $item){
			$data	= ['notice_key'=>$key, 'notice_type'=>$type];
			$item	+= ['class'=>'is-dismissible', 'title'=>'', 'modal'=>0];
			$notice	= trim($item['notice'] ?? '');
			$notice	.= !empty($item['admin_url']) ? (($item['modal'] ? "\n\n" : ' ').'<a href="'.add_query_arg($data, home_url($item['admin_url'])).'">点击查看<span class="dashicons dashicons-arrow-right-alt"></span></a>') : '';

			echo wpjam_tag('div', [
				'class' => $item['modal'] ? 'hidden notice-modal' : 'notice notice-'.$item['type'].' '.$item['class'],
				'data'	=> $item['modal'] ? ['title'=>$item['title'] ?: '消息'] : [],
			], wpautop($notice).wpjam_get_page_button('delete_notice', ['data'=>$data]))."\n";
		}
	}

	public static function add($item, $type='admin', $id=0){
		if(!$id || ($type == 'admin' ? (!is_multisite() || get_site($id)) : get_userdata($id))){
			$item	= is_array($item) ? $item : ['notice'=>$item];

			return (self::get_instance($type, $id))->insert($item+['type'=>'error', 'time'=>time(), 'key'=>md5(serialize($item))]);
		}
	}

	public static function callback($data=[], $wp_die=true){
		$type	= $data['notice_type'] ?? '';
		$key	= $data['notice_key'] ?? '';

		return ($type == 'user' || ($type == 'admin' && current_user_can('manage_options')))
		? ($key ? (self::get_instance($type))->delete($key) : true)
		: ($wp_die ? wp_die('无效的类型') : true);
	}

	public static function get_instance($type='admin', $id=0){
		$name	= 'wpjam_notices';
		$id		= (int)$id ?: ($type == 'user' ? get_current_user_id() : get_current_blog_id());

		return wpjam_get_handler('notice:'.$type.':'.$id, static::create(($type == 'user' ? [
			'items_type'	=> 'meta',
			'meta_type'		=> 'user',
			'meta_key'		=> $name,
			'object_id'		=> $id
		] : [
			'get_items'		=> fn()=> wpjam_get_option($name, $id),
			'update_items'	=> fn($items)=> wpjam_update_option($name, $items, $id),
		])+[
			'primary_key'	=> 'key',
			'filter_items'	=> fn($items)=> array_filter(($items ?: []), fn($v)=> $v['time']>(time()-MONTH_IN_SECONDS*3) && trim($v['notice']))
		]));
	}

	public static function on_admin_init(){
		static::callback($_GET, false);

		add_action('all_admin_notices', fn()=> [current_user_can('manage_options') && static::render('admin'), static::render('user')]);

		wpjam_register_page_action('delete_notice', [
			'button_text'	=> '删除',
			'tag'			=> 'span',
			'class'			=> 'hidden delete-notice',
			'validate'		=> true,
			'direct'		=> true,
			'callback'		=> [static::class, 'callback']
		]);
	}
}

class WPJAM_Lazyloader extends WPJAM_Args{
	public function __call($method, $args){
		$name	= $method.'['.array_shift($args).']';

		return $args ? $this->update_arg($name, ...$args) : $this->get_arg($name);
	}

	public function queue($name, $ids){
		if(!$name || !($ids = array_filter($ids))){
			return;
		}

		if(is_array($name)){
			return array_walk($name, fn($n, $k)=> $this->queue($n, is_numeric($k) ? $ids : array_column($ids, $k)));
		}

		$ids	= array_unique($ids);

		if($name == 'post'){
			_prime_post_caches($ids, false, false);

			wpjam_lazyload('post_meta', $ids);
		}elseif(in_array($name, ['blog', 'site', 'term', 'comment'])){
			('_prime_'.($name == 'blog' ? 'site' : $name).'_caches')($ids);
		}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
			wp_metadata_lazyloader()->queue_objects(wpjam_suffix($name, '-', '_meta'), $ids);
		}else{
			$pending	= $this->pending($name);

			if(!$pending){
				$loader	= $this->loader($name) ?: (str_ends_with($name, '_meta') ? [
					'filter'	=> 'get_'.$name.'data',
					'callback'	=> fn($v)=> update_meta_cache(wpjam_suffix($name, '-', '_meta'), $v)
				] : []);

				$loader && wpjam_once($loader['filter'], 'tap', fn()=> $this->load($name, $loader['callback']));
			}

			$this->pending($name, array_merge($pending ?: [], $ids));
		}
	}

	public function load($name, $cb){
		if($name && ($pending = $this->pending($name))){
			wpjam_call($cb, array_unique($pending));

			$this->pending($name, []);
		}
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_Cache extends WPJAM_Args{
	public function __call($method, $args){
		$method	= wpjam_prefix($method, '-', 'cache_');
		$multi	= str_contains($method, '_multiple');
		$gnd	= array_any(['get', 'delete'], fn($k)=> str_contains($method, $k));

		$ik	= $method == 'cas' ? 1 : 0;
		$ig	= $ik+(($gnd || $multi) ? 1 : 2);
		$ie	= $ig+(str_contains($method, '_salted') ? 1 : 0);

		if(count($args) >= $ig){
			$cb		= 'wp_cache_'.$method;
			$key	= $args[$ik];

			if($prefix = $this->prefix){
				$args[$ik]	= $multi ? ($gnd ? 'wpjam_map' : 'wpjam_array')($key, fn($k)=> $prefix.':'.$k) : $prefix.':'.$key;
			}

			if(!$gnd){
				$args[$ie]	??= $this->time ?: DAY_IN_SECONDS;
			}

			if($method == 'get_with_cas'){
				$value	= $cb($args[$ik], $this->group, $cas);

				return $value === false ? false : ['value'=>$value, 'cas'=>$cas];
			}

			$result	= $cb(...wpjam_add_at($args, $ig, $this->group));

			if($result && $method == 'get_multiple'){
				return wpjam_array($key, fn($i, $k) => (($v = $result[$args[0][$i]]) !== false) ? [$k, $v] : null);
			}

			return $result;
		}
	}

	public function is_over($key, $max, $time){
		$times	= $this->get($key) ?: 0;

		return $times > $max || ($this->set($key, $times+1, ($max == $times && $time > 60) ? $time : 60) && false);
	}

	public static function create($group, $args=[]){
		if(is_object($group)){
			[$object, $group, $global]	= [$group, ...((array)$group->cache_group+['', ''])];

			$args	= compact('group', 'global')+['prefix'=>$object->cache_prefix, 'time'=>$object->cache_time];
		}else{
			$args	= is_array($group) ? $group : ['group'=>$group]+$args;
		}

		if(!empty($args['group'])){
			if(wpjam_pull($args, 'global')){
				wp_cache_add_global_groups($args['group']);
			}

			if(empty($args['time']) && !empty($args['cache_time'])){
				$args['time']	= $args['cache_time'];
			}

			$name = wpjam_join(':', $args['group'], $args['prefix'] ?? '');

			return wpjam_get_registered(static::class, $name) ?: wpjam_register(static::class, $name, $args);
		}
	}

	public static function remember($key, $group, $cb=null, $args=[]){
		$fix	= is_bool($group) ? ($group ? 'site_' : '').'transient' : '';

		if($cb === false){
			return $fix ? ('delete_'.$fix)($key) : wp_cache_delete($key, $group);
		}

		$args	= is_numeric($args) ? ['expire'=>$args] : $args;
		$expire	= ($args['expire'] ?? '') ?: 86400;
		$force	= $args['force'] ?? false;
		$value	= $fix ? ('get_'.$fix)($key) : wp_cache_get($key, $group, ($force === 'get' || $force === true));

		return ($cb && ($value === false || $force === 'set' || $force === true)) ? wpjam_tap(
			$cb($value, $key, ...($fix ? [] : [$group ?: 'default'])),
			fn($value)=> $value !== false && ($fix ? ('set_'.$fix)($key, $value, $expire) : wp_cache_set($key, $value, $group, $expire))
		) : $value;
	}

	public static function table($name, $key, ...$args){
		$clean	= $args && $args[0];
		$table	= in_array($name, ['post', 'term', 'user']) ? $GLOBALS['wpdb']->{$name.'s'} : $name;
		$status	= static::remember($table.':status', false, $clean ? false : fn()=> $GLOBALS['wpdb']->get_row("SHOW TABLE STATUS LIKE '{$table}'"));

		return !$clean && $key == 'max_id' ? ($status ? $status->Auto_increment : null) : $status;
	}

	public static function deleted_ids($name, ...$args){
		$key	= 'deleted_ids:'.$name;
		$items	= get_transient($key) ?: [];

		if($args && $args[0]){
			if(is_string($args[0]) && try_suffix($args[0], '-', '-')){
				static::table($name, 'max_id', true);

				$id		= (int)$args[0];
				$update	= in_array($id, $items) ? array_diff($items, [$id]) : null;
			}else{
				$ids	= array_diff((array)$args[0], $items);
				$max	= static::table($name, 'max_id');
				$ids	= $ids && $max ? array_filter($ids, fn($id)=> $id < $max) : $ids;
				$update	= $ids ? array_merge($items, $ids) : null;
			}

			if(isset($update)){
				$items	= array_values($update);

				set_transient($key, $items, 30);
			}
		}

		return $items;
	}

	public static function verification($group, $key, ...$args){
		$group	= (is_array($group) ? $group : ['group'=>$group])+['max_attempts'=>5, 'interval'=>1, 'expire'=>30, 'global'=>true];
		$cache	= self::create(['group'=>'verification_code', 'prefix'=>$group['group'] ?: 'default']+$group);

		if($max = $group['max_attempts']){
			$times	= (int)$cache->get($key.':failed_times');
			($times > $max) && wpjam_throw('too_many_attempts', sprintf(__('Failed attempts exceeded, Please try again in %d minutes.', 'wpjam'), $group['expire']/2));
		}

		if($args){
			if(!$args[0] || (int)$args[0] !== (int)$cache->get($key.':code')){
				$max && $cache->set($key.':failed_times', $times+1, $group['expire']*30);

				wpjam_throw('invalid_code');
			}

			return true;
		}

		if($group['interval']){
			$cache->get($key.':time') && wpjam_throw('error', sprintf(__('A verification code was sent %d minutes ago.', 'wpjam'), $group['interval']));

			$cache->set($key.':time', time(), $group['interval']*60);
		}

		$code	= rand(100000, 999999);

		$cache->set($key.':code', $code, $group['expire']*60);

		return $code;
	}
}