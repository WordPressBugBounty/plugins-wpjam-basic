<?php
trait WPJAM_Instance_Trait{
	use WPJAM_Call_Trait;

	public static function instance_exists($name){
		return wpjam_get_instance(self::get_called(), $name) ?: false;
	}

	public static function add_instance($name, $object){
		return wpjam_add_instance(self::get_called(), $name, $object);
	}

	protected static function create_instance(...$args){
		return new static(...$args);
	}

	public static function instance(...$args){
		if(count($args) == 2 && is_callable($args[1])){
			return wpjam_get_instance(self::get_called(), ...$args);
		}

		$args	= wpjam_filter($args, fn($v)=> !is_null($v));
		$name	= $args ? implode(':', $args) : 'singleton';

		return self::instance_exists($name) ?: self::add_instance($name, static::create_instance(...$args));
	}

	protected static function validate_data($data, $id=0){
		return true;
	}

	protected static function sanitize_data($data, $id=0){
		return $data;
	}

	public static function prepare_data($data, $id=0){
		$result	= static::validate_data($data, $id);
		$result	= wpjam_throw_if_error($result);

		return static::sanitize_data($data, $id);
	}

	public static function before_delete($id){
		if(method_exists(get_called_class(), 'is_deletable')){
			$object	= static::get_instance($id);
			$result	= $object ? $object->is_deletable() : true;
			$result	= wpjam_throw_if_error($result);

			if(!$result){
				wpjam_throw('indelible', '不可删除');
			}
		}
	}
}

abstract class WPJAM_Model implements ArrayAccess, IteratorAggregate{
	use WPJAM_Instance_Trait;

	protected $_id;
	protected $_data	= [];

	public function __construct($data=[], $id=null){
		if($id){
			$this->_id		= $id;
			$this->_data	= $data ? array_diff_assoc($data, static::get($id)) : [];
		}else{
			$key	= static::get_primary_key();
			$exist	= isset($data[$key]) ? static::get($data[$key]) : null;

			if($exist){
				$this->_id		= $data[$key];
				$this->_data	= array_diff_assoc($data, $exist);
			}else{
				$this->_data	= $data;
			}
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
		$key	= static::get_primary_key();

		return $this->get_data($key);
	}

	public function get_data($key=''){
		$data	= is_null($this->_id) ? [] : static::get($this->_id);
		$data	= array_merge($data, $this->_data);

		return $key ? ($data[$key] ?? null) : $data;
	}

	public function set_data($key, $value){
		if(!is_null($this->_id) && static::get_primary_key() == $key){
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
		if($key){
			unset($this->_data[$key]);
		}else{
			$this->_data	= [];
		}
	}

	public function to_array(){
		return $this->get_data();
	}

	public function save($data=[]){
		$meta_type	= self::get_meta_type();
		$meta_input	= $meta_type ? wpjam_pull($data, 'meta_input') : null;
		$data		= array_merge($this->_data, $data);

		if($this->_id){
			$data	= wpjam_except($data, static::get_primary_key());
			$result	= $data ? static::update($this->_id, $data) : false;
		}else{
			$result	= static::insert($data);
		}

		if(!is_wp_error($result)){
			if(!$this->_id){
				$this->_id	= $result;
			}

			if($this->_id && $meta_input){
				$this->meta_input($meta_input);
			}

			$this->reset_data();
		}

		return $result;
	}

	public function meta_get($key){
		return wpjam_get_metadata(self::get_meta_type(), $this->_id, $key);
	}

	public function meta_exists($key){
		return metadata_exists(self::get_meta_type(), $this->_id, $key);
	}

	public function meta_input(...$args){
		return wpjam_update_metadata(self::get_meta_type(), $this->_id, ...$args);
	}

	public static function find($id){
		return static::get_instance($id);
	}

	public static function get_instance($id){
		if($id){
			return static::instance($id, fn($id)=> static::get($id) ? new static([], $id) : null);
		}
	}

	public static function get_handler(){
		$handler	= wpjam_get_handler(self::get_called());

		if(!$handler && property_exists(get_called_class(), 'handler')){
			return static::$handler;
		}

		return $handler;
	}

	public static function set_handler($handler){
		return wpjam_register_handler(self::get_called(), $handler);
	}

	public static function insert($data){
		return wpjam_catch([get_called_class(), 'insert_by_handler'], static::prepare_data($data));
	}

	public static function update($id, $data){
		return wpjam_catch([get_called_class(), 'update_by_handler'], $id, static::prepare_data($data, $id));
	}

	public static function delete($id){
		return wpjam_catch([get_called_class(), 'before_delete'], $id) ?: static::delete_by_handler($id);
	}

	public static function delete_multi($ids){
		try{
			array_walk($ids, fn($id)=> static::before_delete($id));

			return static::delete_multi_by_handler($ids);
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public static function insert_multi($data){
		return wpjam_catch([get_called_class(), 'insert_multi_by_handler'], array_map(fn($v)=> static::prepare_data($v), $data));
	}

	public static function validate_by_field($value, $field){
		$result	= static::get($value);

		if(!$result || is_wp_error($result)){
			return $result ?: new WP_Error('invalid_id', [$field->_title]);
		}

		return $value;
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'dismiss'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true, 'confirm'=>true,	'bulk'=>true,	'order'=>1],
		];
	}

	public static function __callStatic($method, $args){
		if(in_array($method, ['item_callback', 'render_item'])){
			return $args[0];
		}

		if(str_ends_with($method, '_by_handler')){
			$method	= wpjam_remove_postfix($method, '_by_handler');
		}

		return wpjam_call_handler(static::get_handler(), $method, ...$args);
	}
}

class WPJAM_Handler{
	public static function call($name, $method, ...$args){
		$object	= is_object($name) ? $name : self::get($name);

		if(!$object){
			return new WP_Error('undefined_handler');
		}

		if($object instanceof WPJAM_DB){
			if(strtolower($method) == 'query'){
				if(!$args){
					return $object;
				}
			}elseif($method == 'query_items'){
				if(is_array($args[0])){
					$method	= 'query';
					$args	= [$args[0], ($args[1] ?? 'array')];
				}
			}elseif(str_starts_with($method, 'cache_')){
				$method	.= '_force';
			}
		}

		if(in_array($method, [
			'get_primary_key',
			'get_meta_type',
			'get_searchable_fields',
			'get_filterable_fields'
		])){
			return $object->{substr($method, 4)};
		}elseif(in_array($method, [
			'set_searchable_fields',
			'set_filterable_fields'
		])){
			return $object->{substr($method, 4)}	= $args[0];
		}

		try{
			if(str_ends_with($method, '_multi') && !method_exists($object, $method)){
				$method	= wpjam_remove_postfix($method, '_multi');

				array_walk($args[0], fn($item)=> wpjam_try([$object, $method], $item));

				return true;
			}

			$method	= ['get_ids'=>'get_by_ids', 'get_all'=>'get_results'][$method] ?? $method;
			$cb		= [$object, $method];

			return is_callable($cb) ? $cb(...$args) : new WP_Error('undefined_method', [$method]);
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public static function get($name, $args=null){
		if($name){
			if(is_array($name)){
				$args	= $name;
				$name	= wpjam_pull($args, 'name') ?: md5(serialize($args));
			}

			return wpjam_get_item('handler', $name) ?: ($args ? self::create($name, $args) : null);
		}
	}

	public static function create($name, $args=[]){
		if(is_array($name)){
			$args	= $name;
			$name	= wpjam_pull($args, 'name');
		}

		if(is_object($args)){
			return $name ? wpjam_set_item('handler', $name, $args) : null;
		}

		if(!empty($args['table_name'])){
			$name	= $name ?: $args['table_name'];

			return self::create($name, new WPJAM_DB($args));
		}

		if(!empty($args['option_name'])){
			$name	= $name ?: $args['option_name'];

			if(!empty($args['setting_name']) || !empty($args['items_field'])){
				$args['setting_name']	??= wpjam_pull($args, 'items_field');	// 兼容

				$args['items_type']		= 'setting';
			}else{
				$args['items_type']		= 'option';
			}
		}

		if(!empty($args['items_type']) || wpjam_every(['get_items', 'update_items'], fn($method)=> !empty($args[$method]))){	// 推荐
			if(!empty($args['items_type'])){
				$args['type']	= wpjam_pull($args, 'items_type');
			}

			return self::create($name, new WPJAM_Items($args));
		}

		if(!empty($args['items_model'])){	// 不建议使用了
			$args	= wp_parse_args($args, wpjam_fill(['get_items', 'update_items'], fn($k)=> [$args['items_model'], $k]));

			return self::create($name, new WPJAM_Items($args));
		}

		if(wpjam_pull($args, 'type') == 'option_items'){	// 不建议使用
			return self::create($name, new WPJAM_Items(wp_parse_args($args, ['type'=>'option', 'option_name'=>$name])));
		}
	}
}

class WPJAM_DB extends WPJAM_Args{
	protected $meta_query	= null;
	protected $query_vars	= [];
	protected $where		= [];

	public function __construct($table, $args=[]){
		if(is_array($table)){
			$args	= $table;
			$table	= $args['table_name'];
		}

		$this->args	= wp_parse_args($args, [
			'table'			=> $table,
			'primary_key'	=> 'id',
			'cache'			=> true,
			'cache_group'	=> $table,
			'cache_time'	=> DAY_IN_SECONDS,
			'field_types'	=> [],
		]);

		foreach(['group_cache_key', 'lazyload_key', 'filterable_fields', 'searchable_fields'] as $key){
			$this->$key	= wpjam_array($this->$key);
		}

		if($this->cache_key	== $this->primary_key){
			$this->cache_key	= '';
		}elseif($this->cache_key){
			$this->group_cache_key	= [...$this->group_cache_key, $this->cache_key];
		}

		$this->clear();
	}

	public function __get($key){
		if(in_array($key, array_keys($this->query_vars))){
			return $this->query_vars[$key];
		}elseif(in_array($key, ['last_error', 'last_query', 'insert_id'])){
			return $GLOBALS['wpdb']->$key;
		}

		return parent::__get($key);
	}

	public function __call($method, $args){
		if(str_starts_with($method, 'where_')){
			$type	= wpjam_remove_prefix($method, 'where_');

			if($type == 'fragment'){
				$this->where(null, $args[0]);
			}elseif(in_array($type, ['any', 'all'])){
				$value	= '';

				if($args[0] && is_array($args[0])){
					$where	= wpjam_map($args[0], fn($v, $k)=> $this->where($k, $v, 'value'));
					$value	= $this->parse_where($where, ($type == 'any' ? 'OR' : 'AND'));
				}

				return (!isset($args[1]) || $args[1] == 'object') ? $this->where_fragment($value) : $value;
			}elseif(isset($args[1])){
				$compare	= $this->get_operator()[$type] ?? '';	

				if($compare){
					$this->where($args[0], ['value'=>$args[1], 'compare'=>$compare]);
				}
			}

			return $this;
		}elseif(in_array($method, array_keys($this->query_vars)) || in_array($method, ['search', 'order_by', 'group_by'])){
			$key	= $method;
			$value	= $args ? $args[0] : ($key == 'found_rows' ? true : null);

			if(!is_null($value)){
				if($key == 'order'){
					$value	= strcasecmp($value, 'ASC') ? 'DESC' : 'ASC';
				}elseif(in_array($key, ['limit', 'offset'])){
					$value	= (int)$value;
				}else{
					$key	= ['search'=>'search_term', 'order_by'=>'orderby', 'group_by'=>'groupby'][$key] ?? $key;
				}

				$this->query_vars[$key]	= $value;
			}

			return $this;
		}elseif(in_array($method, ['get_col', 'get_var', 'get_row'])){
			if($method != 'get_col'){
				$this->limit(1);
			}

			$field	= $args[0] ?? '';
			$args	= [$this->get_sql($field), ...($method == 'get_row' ? [ARRAY_A] : [])];

			return $GLOBALS['wpdb']->$method(...$args);
		}elseif(str_ends_with($method, '_by_db')){
			$method	= wpjam_remove_postfix($method, '_by_db');

			return $GLOBALS['wpdb']->$method(...$args);
		}elseif(str_contains($method, '_meta')){
			$object	= wpjam_get_meta_type_object($this->meta_type);

			if($object){
				return $object->$method(...$args);
			}
		}elseif(str_starts_with($method, 'cache_')){
			if(str_ends_with($method, '_force')){
				$method	= wpjam_remove_postfix($method, '_force');
			}else{
				if(!$this->cache){
					return false;
				}
			}

			if(!$this->cache_object){
				$group	= $this->cache_group;

				$this->cache_object	= WPJAM_Cache::create([
					'group'		=> is_array($group) ? $group[0] : $group,
					'global'	=> is_array($group) ? $group[1] : false,
					'prefix'	=> $this->cache_prefix,
					'time'		=> $this->cache_time
				]);
			}

			return $this->cache_object->$method(...$args);
		}elseif(str_ends_with($method, '_last_changed')){
			$key	= 'last_changed';

			if($this->group_cache_key){
				$vars	= array_shift($args);

				if($vars && is_array($vars)){
					$vars	= wpjam_slice($vars, $this->group_cache_key);

					if($vars && count($vars) == 1 && !is_array(reset($vars))){
						$key	.= ':'.array_key_first($vars).':'.reset($vars);
					}
				}
			}

			if($method == 'get_last_changed'){
				$value	= $this->cache_get($key);

				if(!$value){
					$value	= microtime();

					$this->cache_set($key, $value);
				}

				return $value;
			}elseif($method == 'delete_last_changed'){
				$this->cache_delete($key);
			}
		}

		return new WP_Error('undefined_method', [$method]);
	}

	protected function get_operator(){
		return [
			'not'		=> '!=',
			'lt'		=> '<',
			'lte'		=> '<=',
			'gt'		=> '>',
			'gte'		=> '>=',
			'in'		=> 'IN',
			'not_in'	=> 'NOT IN',
			'like'		=> 'LIKE',
			'not_like'	=> 'NOT LIKE',
		];
	}

	public function clear(){
		$this->where		= [];
		$this->meta_query	= null;
		$this->query_vars	= [
			'found_rows'	=> false,
			'limit'			=> 0,
			'offset'		=> 0,
			'orderby'		=> null,
			'order'			=> null,
			'groupby'		=> null,
			'having'		=> null,
			'search_term'	=> null,
		];
	}

	public function find_by($field, $value, $order='ASC', $method='get_results'){
		$value	= is_array($value) ? array_map(fn($v)=> $this->format($v, $field), $value) : $this->format($value, $field);
		$value	= is_array($value) ? 'IN ('.implode(',', $value).')' : '= '.$value;
		$sql	= "SELECT * FROM `{$this->table}` WHERE `{$field}` {$value}".($order ? " ORDER BY `{$this->primary_key}` {$order}" : '');

		return $GLOBALS['wpdb']->$method($sql, ARRAY_A);
	}

	public function find_one_by($field, $value, $order=''){
		return $this->find_by($field, $value, $order, 'get_row');
	}

	public function find_one($id){
		return $this->find_one_by($this->primary_key, $id);
	}

	public function get($id){
		$this->load_pending();

		if(!$id){
			return [];
		}

		$result	= $this->cache_get($id);

		if($result === false){
			$result	= $this->find_one($id);
			$time	= $result ? $this->cache_time : 60;

			$this->cache_set($id, $result, $time);
		}

		return $result;
	}

	public function get_one_by($field, $value, $order='ASC'){
		$items	= $this->get_by($field, $value, $order);

		return $items ? reset($items) : [];
	}

	public function get_by($field, $value, $order='ASC'){
		if($field == $this->primary_key){
			return $this->get($value);
		}

		if($this->group_cache_key && in_array($field, $this->group_cache_key)){
			$this->load_pending($field, $order);

			return $this->query([$field=>$value, 'order'=>$order], 'items');
		}

		return $this->find_by($field, $value, $order);
	}

	public function get_by_values($field, $values, $order='ASC'){
		$values	= array_filter(array_unique($values));

		if(!$values){
			return [];
		}

		if($field == $this->primary_key){
			return $this->get_by_ids($values);
		}

		if(!$this->group_cache_key || !in_array($field, $this->group_cache_key)){
			return $this->find_by($field, $values, $order);
		}

		$data	= $ids = $uncache = [];

		foreach($values as $v){
			$result	= $this->query([$field=>$v, 'order'=>$order], 'cache');

			if($result[0] === false || !isset($result[0]['items'])){
				$uncache[$v]	= $result;
			}else{
				$data[$v]	= $result[0]['items'];
				$ids		= array_merge($ids, $data[$v]);
			}
		}

		$ids		= array_merge($ids, ($uncache ? $this->query([$field.'__in'=>array_keys($uncache), 'order'=>$order], 'ids') : []));
		$results	= array_values($this->get_by_ids($ids));
		$data		= array_map(fn($ids)=> $ids ? array_values($this->get_by_ids($ids)) : [], $data);

		foreach($uncache as $v => $result){
			$data[$v]	= wp_list_filter($results, [$field => $v]) ?: [];

			$cache[$result[1]]	= [
				'data'			=>['items'=>array_column($data[$v], $this->primary_key)],
				'last_changed'	=>$result[2]
			];
		}

		if(!empty($cache)){
			$this->cache_set_multiple($cache);
		}

		return $data;
	}

	public function cache_delete_by($field, $value, $order='ASC'){
		trigger_error('123');
		if($this->group_cache_key && in_array($field, $this->group_cache_key)){
			foreach((array)$value as $v){
				$result	= $this->query([$field=>$v, 'order'=>$order], 'cache');
				trigger_error(var_export('cache_delete_by::'.$result[1], true));
				$this->cache_delete_force($result[1]);
			}
		}
	}

	public function update_caches($keys, $primary=false){
		if($primary || !$this->cache_key){
			return $this->get_by_ids($keys);
		}else{
			return $this->get_by_values($this->cache_key, $keys);
		}
	}

	protected function load_pending($field='', $order=''){
		if($this->pending_queue){
			$queue	= $this->pending_queue;
			$key	= $this->primary_key;
			$queue	= is_array($queue) ? $queue : [$key=>$queue];
			$field	= $field ?: $key;

			if(isset($queue[$field])){
				wpjam_load_pending($queue[$field], fn($pending)=> $this->get_by_values($field, $pending, $order));
			}
		}
	}

	public function get_ids($ids){
		return $this->get_by_ids($ids);
	}

	public function get_by_ids($ids){
		if(!$ids){
			return [];
		}

		$ids	= array_filter(array_unique($ids));
		$data	= $this->cache_get_multiple($ids) ?: [];
		$data	= array_filter($data, 'is_array');
		$ids	= array_diff($ids, array_keys($data));

		if($ids){
			$results	= $this->find_by($this->primary_key, $ids);

			if($results){
				$results	= wpjam_array($results, fn($k, $v)=> $v[$this->primary_key]);

				$this->cache_set_multiple($results);
			}

			foreach($ids as $id){
				if(isset($results[$id])){
					$data[$id]	= $results[$id];
				}else{
					$this->cache_set($id, [], 5);
				}
			}
		}

		if($data){
			$this->lazyload_meta(array_keys($data));

			if($this->lazyload_key){
				wpjam_lazyload($this->lazyload_key, $data);
			}
		}

		return $data;
	}

	public function get_clauses($fields=[]){
		$distinct	= '';
		$where		= '';
		$join		= '';
		$groupby	= $this->groupby ?: '';

		if($this->meta_query){
			$sql		= $this->meta_query->get_sql($this->meta_type, $this->table, $this->primary_key, $this);
			$where		= $sql['where'];
			$join		= $sql['join'];
			$groupby	= $groupby ?: $this->table.'.'.$this->primary_key;
			$fields		= $fields ?: $this->table.'.*';
		}

		$fields		= $fields ? (is_array($fields) ? '`'.implode('`, `', esc_sql($fields)).'`' : $fields) : '*';
		$groupby	= $groupby ? ' GROUP BY '.(wpjam_some([',', '(', '.'], fn($v)=> str_contains($groupby, $v)) ? $groupby : '`'.$groupby.'`') : '';
		$having		= $this->having ? ' HAVING '.$this->having : '';
		$orderby	= $this->orderby;
		$orderby	= (is_null($orderby) && !$groupby && !$having) ? ($this->get_arg('orderby') ?: $this->primary_key) : $orderby;

		if($orderby){
			if(is_array($orderby)){
				$parsed		= array_filter(wpjam_map($orderby, fn($v, $k)=> $this->parse_orderby($k, $v)));
				$orderby	= $parsed ? implode(', ', $parsed) : '';
			}elseif(str_contains($orderby, ',') || (str_contains($orderby, '(') && str_contains($orderby, ')'))){
				$orderby	= $orderby;
			}else{
				$order		= $this->order ?: $this->get_arg('order');
				$orderby	= $this->parse_orderby($orderby, $order);
			}

			$orderby	= $orderby ? ' ORDER BY '.$orderby : '';
		}else{
			$orderby	= '';
		}

		$limits		= $this->limit ? ' LIMIT '.$this->limit : '';
		$limits		.= $this->offset ? ' OFFSET '.$this->offset : '';
		$found_rows	= ($limits && $this->found_rows) ? 'SQL_CALC_FOUND_ROWS' : '';
		$conditions	= $this->get_conditions();
		$conditions	= (!$conditions && $where) ? '1=1' : $conditions;
		$where		= $conditions ? ' WHERE '.$conditions.$where : '';

		return compact('found_rows', 'distinct', 'fields', 'join', 'where', 'groupby', 'having', 'orderby', 'limits');
	}

	public function get_request($clauses=null){
		$clauses	= $clauses ?: $this->get_clauses();

		return sprintf("SELECT %s %s %s FROM `{$this->table}` %s %s %s %s %s %s", ...array_values($clauses));
	}

	public function get_sql($fields=[]){
		return $this->get_request($this->get_clauses($fields));
	}

	public function get_results($fields=[], $found_rows=null){
		$clauses	= $this->get_clauses($fields);
		$query_ids	= in_array($clauses['fields'], ['*', $this->table.'.*']);

		if($query_ids){
			$ids	= $this->query_ids($clauses);
		}else{
			$items	= $this->get_results_by_db($this->get_request($clauses), ARRAY_A);
		}

		if($found_rows){
			$total	= $this->find_total();
		}

		if($query_ids){
			$items	= array_values($this->get_by_ids($ids));
		}

		return $found_rows ? compact('items', 'total') : $items;
	}

	public function find($fields=[]){
		return $this->get_results($fields);
	}

	protected function query_ids($clauses){
		$clauses['fields']	= $this->table.'.'.$this->primary_key;

		return $this->get_col_by_db($this->get_request($clauses));
	}

	public function find_total(){
		return $this->get_var_by_db("SELECT FOUND_ROWS();");
	}

	protected function parse_orderby($orderby, $order){
		if($orderby == 'rand'){
			return 'RAND()';
		}elseif(preg_match('/RAND\(([0-9]+)\)/i', $orderby, $matches)){
			return sprintf('RAND(%s)', (int)$matches[1]);
		}elseif(str_ends_with($orderby, '__in')){
			return '';
			// $field	= str_replace('__in', '', $orderby);
		}

		$order	= (is_string($order) && 'ASC' === strtoupper($order)) ? 'ASC' : 'DESC';

		if($this->meta_query){
			$meta_clauses		= $this->meta_query->get_clauses();
			$primary_meta_query	= reset($meta_clauses);
			$primary_meta_key	= $primary_meta_query['key'] ?? '';

			if($orderby == $primary_meta_key || $orderby == 'meta_value'){
				if(!empty($primary_meta_query['type'])){
					return "CAST({$primary_meta_query['alias']}.meta_value AS {$primary_meta_query['cast']}) ".$order;
				}else{
					return "{$primary_meta_query['alias']}.meta_value ".$order;
				}
			}elseif($orderby == 'meta_value_num'){
				return "{$primary_meta_query['alias']}.meta_value+0 ".$order;
			}elseif(wpjam_exists($meta_clauses, $orderby)){
				$meta_clause	= $meta_clauses[$orderby];

				return "CAST({$meta_clause['alias']}.meta_value AS {$meta_clause['cast']}) ".$order;
			}
		}

		if($orderby == 'meta_value_num' || $orderby == 'meta_value'){
			return '';
		}

		return '`'.$orderby.'` '.$order;
	}

	public function insert_multi($datas){	// 使用该方法，自增的情况可能无法无法删除缓存，请注意
		if(!$datas){
			return 0;
		}

		$datas	= array_filter(array_values($datas));

		$this->cache_delete_by_conditions([], $datas);

		$data		= reset($datas);
		$fields		= '`'.implode('`, `', array_keys($data)).'`';
		$updates	= implode(', ', array_map(fn($k)=> "`$k` = VALUES(`$k`)", array_keys($data)));
		$values		= implode(', ', array_map([$this, 'format'], $datas));
		$sql		= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
		$result		= $this->query_by_db($sql);

		return (false === $result) ? new WP_Error('insert_error', $this->last_error) : $result;
	}

	public function insert($data){
		$this->cache_delete_by_conditions([], $data);

		$id	= $data[$this->primary_key] ?? null;

		if($id){
			$GLOBALS['wpdb']->check_current_query = false;

			$data		= array_filter($data, fn($v)=> !is_null($v));
			$fields		= implode(', ', array_keys($data));
			$updates	= implode(', ', array_map(fn($k)=> "`$k` = VALUES(`$k`)", array_keys($data)));
			$values		= $this->format($data);
			$sql		= "INSERT INTO `$this->table` ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
			$result		= $this->query_by_db($sql);
		}else{
			$result		= $this->insert_by_db($this->table, $data, $this->get_format($data));
		}

		if($result === false){
			return new WP_Error('insert_error', $this->last_error);
		}

		$id	= $id ?: $this->insert_id;

		$this->cache_delete($id);

		return $id;
	}

	/*
	update($id, $data);
	update($data, $where);
	update($data); // $where各种 参数通过 where() 方法事先传递
	*/
	public function update(...$args){
		if(count($args) == 2){
			if(is_array($args[0])){
				$data	= $args[0];
				$where	= $args[1];

				$conditions	= $this->where_all($where, 'fragment');
			}else{
				$id		= $args[0];
				$data	= $args[1];
				$where	= $conditions = [$this->primary_key => $id];

				$this->cache_delete($id);
			}

			$this->cache_delete_by_conditions($conditions, $data);

			$result	= $this->update_by_db($this->table, $data, $where, $this->get_format($data), $this->get_format($where));

			return $result === false ? new WP_Error('update_error', $this->last_error) : $result;
		}elseif(count($args) == 1){	// 如果为空，则需要事先通过各种 where 方法传递进去
			$data	= $args[0];
			$where	= $this->get_conditions();

			if($data && $where){
				$this->cache_delete_by_conditions($where, $data);

				$fields	= implode(', ', wpjam_map($data, fn($v, $k)=> "`$k` = ".(is_null($v) ? 'NULL' : $this->format($v, $k))));

				return $this->query_by_db("UPDATE `{$this->table}` SET {$fields} WHERE {$where}");
			}

			return 0;
		}
	}

	/*
	delete($where);
	delete($id);
	delete(); // $where 参数通过各种 where() 方法事先传递
	*/
	public function delete($where = ''){
		$id	= null;

		if($where){	// 如果传递进来字符串或者数字，认为根据主键删除，否则传递进来数组，使用 wpdb 默认方式
			if(is_array($where)){
				$this->cache_delete_by_conditions($this->where_all($where, 'fragment'));
			}else{
				$id		= $where;
				$where	= [$this->primary_key => $id];

				$this->cache_delete($id);
				$this->cache_delete_by_conditions($where);
			}

			$result	= $this->delete_by_db($this->table, $where, $this->get_format($where));
		}else{	// 如果为空，则 $where 参数通过各种 where() 方法事先传递
			$where	= $this->get_conditions();

			if(!$where){
				return 0;
			}

			$this->cache_delete_by_conditions($where);

			$result = $this->query_by_db("DELETE FROM `{$this->table}` WHERE {$where}");
		}

		if(false === $result){
			return new WP_Error('delete_error', $this->last_error);
		}

		if($id){
			$this->delete_meta_by_id($id);
		}else{
			$this->delete_orphan_meta($this->table, $this->primary_key);
		}

		return $result;
	}

	public function delete_by($field, $value){
		return $this->delete([$field => $value]);
	}

	public function delete_multi($ids){
		if(!$ids){
			return 0;
		}

		$this->cache_delete_by_conditions([$this->primary_key => $ids]);

		array_walk($ids, [$this, 'cache_delete']);

		$values	= array_map(fn($id)=> $this->format($id, $this->primary_key), $ids);
		$where	= 'WHERE `'.$this->primary_key.'` IN ('.implode(',', $values).') ';
		$sql	= "DELETE FROM `{$this->table}` {$where}";
		$result = $this->query_by_db($sql);

		if(false === $result ){
			return new WP_Error('delete_error', $this->last_error);
		}

		return $result ;
	}

	protected function cache_delete_by_conditions($conditions, $data=[]){
		$this->delete_last_changed();

		if($this->cache || $this->group_cache_key){
			if($data){
				$conditions	= $conditions ? (array)$conditions : [];
				$datas		= wp_is_numeric_array($data) ? $data : [$data];

				foreach($datas as $data){
					$key	= $this->primary_key;

					if(!empty($data[$key])){
						$this->cache_delete($data[$key]);

						$conditions[$key]	= isset($conditions[$key]) ? (array)$conditions[$key] : [];
						$conditions[$key][]	= $data[$key];
					}

					foreach($this->group_cache_key as $key){
						if(isset($data[$key])){
							$this->delete_last_changed([$key => $data[$key]]);
						}
					}
				}
			}

			if(is_array($conditions)){
				if(!$this->group_cache_key && count($conditions) == 1 && isset($conditions[$this->primary_key])){
					$conditions	= [];
				}

				$conditions	= $conditions ? $this->where_any($conditions, 'fragment') : null;
			}

			if($conditions){
				$fields		= implode(', ', [$this->primary_key, ...$this->group_cache_key]);
				$results	= $this->get_results_by_db("SELECT {$fields} FROM `{$this->table}` WHERE {$conditions}", ARRAY_A);

				if($results){
					$this->cache_delete_multiple(array_column($results, $this->primary_key));

					foreach($this->group_cache_key as $group_cache_key){
						$values	= array_unique(array_column($results, $group_cache_key));

						foreach($values as $value){
							$this->delete_last_changed([$group_cache_key => $value]);
						}
					}
				}
			}
		}
	}

	protected function get_conditions(){
		$where	= $this->parse_where($this->where, 'AND');
		$fields	= $this->searchable_fields;
		$term	= $this->search_term;

		if($fields && $term){
			$search	= array_map(fn($k)=> "`{$k}` LIKE '%".$this->esc_like_by_db($term)."%'", $fields);
			$where	.= ($where ? ' AND ' : '').'('.implode(' OR ', $search).')';
		}

		$this->clear();

		return $where;
	}

	public function get_wheres(){	// 以后放弃，目前统计在用
		return $this->get_conditions();
	}

	protected function format($value, $column=''){
		if(is_array($value)){
			$format	= '('.implode(', ', $this->get_format($value)).')';
			$value	= array_values($value);
		}else{
			$format	= str_contains($column, '%') ? $column : $this->get_format($column);
		}

		return $this->prepare_by_db($format, $value);
	}

	protected function get_format($column){
		if(is_array($column)){
			return array_map([$this, 'get_format'], array_keys($column));
		}else{
			return $this->field_types[$column] ?? '%s';
		}
	}

	protected function parse_where($qs=null, $type=''){
		$where	= [];
		$qs		??= $this->where;

		foreach($qs as $q){
			if(!$q || empty($q['compare'])){
				continue;
			}

			$compare	= strtoupper($q['compare']);

			if($compare == strtoupper('fragment')){
				$where[]	= $q['fragment'];

				continue;
			}

			$value	= $q['value'];
			$column	= $q['column'];

			if(!$column || is_null($value)){
				continue;
			}

			if(in_array($compare, ['IN', 'NOT IN'])){
				$value	= is_array($value) ? $value : explode(',', $value);
				$value	= array_values(array_unique($value));
				$value	= array_map(fn($v)=> $this->format($v, $column), $value);

				if(count($value) > 1){
					$value		= '('.implode(',', $value).')';
				}else{
					$compare	= $compare == 'IN' ? '=' : '!=';
					$value		= $value ? reset($value) : '\'\'';
				}
			}elseif(in_array($compare, ['LIKE', 'NOT LIKE'])){
				$left	= str_starts_with($value, '%');
				$right	= str_ends_with($value, '%');
				$value	= trim($value, '%');
				$value	= ($left ? '%' : '').$this->esc_like_by_db($value).($right ? '%' : '');
				$value	= $this->format($value, '%s');
			}else{
				$value	= $this->format($value, $column);
			}

			if(!str_contains($column, '(')){
				$column	= '`'.$column.'`';
			}

			$where[]	= $column.' '.$compare.' '.$value;
		}

		return $type ? implode(' '.$type.' ', $where) : $where;
	}

	public function where($column, $value, $output='object'){
		if(is_null($value)){
			$value	= [];
		}elseif(is_array($value)){
			if(wp_is_numeric_array($value)){
				$value	= ['value'=>$value];
			}

			if(!isset($value['value'])){
				$value	= [];
			}else{
				$value['column']	= $column;
				$value['compare']	??= is_array($value['value']) ? 'IN' : '=';
			}
		}else{
			if(is_numeric($column) || !$column){
				$value	= $value ? ['compare'=>'fragment', 'fragment'=>'( '.$value.' )'] : [];
			}else{
				$value	= ['compare'=>'=', 'column'=>$column, 'value'=>$value];
			}
		}

		if($output != 'object'){
			return $value;
		}

		$this->where[]	= $value;

		return $this;
	}

	public function query_items($limit, $offset){
		$this->limit($limit)->offset($offset)->found_rows();

		foreach(['orderby', 'order'] as $key){
			if(is_null($this->$key)){
				$this->$key(wpjam_get_data_parameter($key));
			}
		}

		if(is_null($this->search_term)){
			$this->search(wpjam_get_data_parameter('s'));
		}

		foreach($this->filterable_fields as $key){
			$this->where($key, wpjam_get_data_parameter($key));
		}

		return $this->get_results([], true);
	}

	public function query($query_vars, $output='object'){
		if(in_array($output, ['cache', 'items', 'ids'])){
			$query_vars	+= ['no_found_rows'=>true, 'suppress_filters'=>true];
		}else{
			$query_vars	= apply_filters('wpjam_query_vars', $query_vars, $this);

			if(isset($query_vars['groupby'])){
				$query_vars	= wpjam_except($query_vars, ['first', 'cursor']);

				$query_vars['no_found_rows']	= true;
			}else{
				if(!isset($query_vars['number']) && empty($query_vars['no_found_rows'])){
					$query_vars['number']	= 50;
				}
			}
		}

		$qv					= $query_vars;
		$orderby			= $qv['orderby'] ?? $this->primary_key;
		$fields				= wpjam_pull($qv, 'fields');
		$found_rows			= !wpjam_pull($qv, 'no_found_rows');
		$cache_results		= wpjam_pull($qv, 'cache_results', $output != 'ids');
		$suppress_filters	= wpjam_pull($qv, 'suppress_filters');

		if($this->meta_type){
			$meta_query	= wpjam_pull($qv, [
				'meta_key',
				'meta_value',
				'meta_compare',
				'meta_compare_key',
				'meta_type',
				'meta_type_key',
				'meta_query'
			]);

			if($meta_query){
				$this->meta_query	= new WP_Meta_Query();
				$this->meta_query->parse_query_vars($meta_query);
			}
		}

		foreach($qv as $key => $value){
			if(is_null($value)){
				continue;
			}

			if($key == 'number'){
				if($value == -1){
					$found_rows	= false;
				}else{
					$this->limit($value);
				}
			}elseif($key == 'offset'){
				$this->offset($value);
			}elseif($key == 'orderby'){
				$value	= is_array($value) ? wpjam_array($value, 'esc_sql') : esc_sql($value);

				$this->orderby($value);
			}elseif($key == 'order'){
				$this->order($value);
			}elseif($key == 'groupby'){
				$this->groupby(esc_sql($value));
			}elseif($key == 'cursor'){
				if($value > 0){
					$this->where_lt($orderby, $value);
				}
			}elseif($key == 'search' || $key == 's'){
				$this->search($value);
			}else{
				if(str_contains($key, '__')){
					$operator	= $this->get_operator();
					$type		= wpjam_find($operator, fn($v, $k)=> str_ends_with($key, '__'.$k), 'key');

					if($type){
						$key	= wpjam_remove_postfix($key, '__'.$type);
						$value	= ['value'=>$value, 'compare'=>$operator[$type]];
					}
				}

				$this->where($key, $value);
			}
		}

		if($found_rows){
			$this->found_rows(true);
		}

		$clauses	= $this->get_clauses($fields);
		$clauses	= $suppress_filters ? $clauses : apply_filters_ref_array('wpjam_clauses', [$clauses, &$this]);
		$request	= $this->get_request($clauses);
		$request	= $suppress_filters ? $request : apply_filters_ref_array('wpjam_request', [$this->get_request($clauses), &$this]);

		if($cache_results){
			$cache_results	= !str_contains(strtoupper($orderby), ' RAND(') && in_array($clauses['fields'], ['*', $this->table.'.*']);
		}

		$result	= $cache_key = false;

		if($cache_results){
			$query_vars		= map_deep($query_vars, 'strval');
			$last_changed	= $this->get_last_changed($query_vars);
			$cache_key		= md5(serialize($query_vars).$request);
			
			$result	= $this->cache_get_force($cache_key);
			$result	= ($result && is_array($result) && array_get($result, 'last_changed') == $last_changed) ? $result['data'] : false;
		}

		if($output == 'cache'){
			return [$result, $cache_key, $last_changed];
		}

		if($result === false || !isset($result['items'])){
			$items	= ($cache_results || $output == 'ids') ? $this->query_ids($clauses) : $this->get_results_by_db($request, ARRAY_A);
			$result	= ['items'=>$items]+($found_rows ? ['total'=>$this->find_total()] : []);

			if($cache_results){
				$this->cache_set_force($cache_key, ['data'=>$result, 'last_changed'=>$last_changed], DAY_IN_SECONDS);
			}
		}

		if($output == 'ids'){
			return $result['items'];
		}

		if($cache_results){
			$result['items']	= array_values($this->get_by_ids($result['items']));
		}

		if($output == 'items'){
			return $result['items'];
		}

		if($found_rows){
			$result['next_cursor']	= 0;

			if(!empty($qv['number']) && $qv['number'] != -1){
				$result['max_num_pages']	= ceil($result['total'] / $qv['number']);

				if($result['items'] && $result['max_num_pages'] > 1){
					$result['next_cursor']	= (int)(end($result['items'])[$orderby]);
				}
			}
		}else{
			$result['total']	= count($result['items']);
		}

		$result['datas'] 		= &$result['items'];	// 兼容
		$result['found_rows']	= &$result['total'];	// 兼容
		$result['request']		= $request;

		return $output == 'object' ? (object)$result : $result;
	}
}

class WPJAM_Items extends WPJAM_Args{
	public function __construct($args=[]){
		$this->args	= wp_parse_args($args, $this->parse_by_type($args) ?: []);
		$this->args = wp_parse_args($this->args, ['item_type'=>'array', 'primary_title'=>'ID']);

		if($this->item_type == 'array'){
			$this->lazyload_key	= wpjam_array($this->lazyload_key);
			$this->primary_key	??= 'id';
		}else{
			$this->primary_key	= null;
		}
	}

	public function __call($method, $args){
		if(str_ends_with($method, '_items')){
			if($this->$method){
				return $this->call_property($method, ...$args);
			}

			if($method == 'delete_items'){
				return $this->update_items([]);
			}

			return $method == 'get_items' ? [] : true;
		}elseif(str_contains($method, '_setting')){
			if($this->option_name){
				$cb	= 'wpjam_'.$method;

				return $cb($this->option_name, ...$args);
			}
		}elseif(in_array($method, [
			'insert',
			'add',
			'update',
			'replace',
			'set',
			'delete',
			'remove',
			'empty',
			'move',
			'increment',
			'decrement'
		])){
			$retry	= $this->retry_times ?: 1;

			try{
				do{
					$retry	-= 1;
					$result	= $this->retry($method, ...$args);
				}while($result === false && $retry > 0);

				return $result;
			}catch(Exception $e){
				return wpjam_catch($e);
			}
		}
	}

	protected function retry($method, ...$args){
		$type	= $this->item_type;
		$items	= $this->get_items();

		if($type == 'array'){
			$items	= $items ?: [];
		}

		if($method == 'move'){
			$ids	= wpjam_try('wpjam_move', array_keys($items), ...$args);
			$items	= wp_array_slice_assoc($items, $ids);

			return $this->update_items($items);
		}

		$id		= ($method == 'insert' || ($method == 'add' && count($args) <= 1)) ? null : array_shift($args);
		$item	= array_shift($args);

		if(in_array($method, ['increment', 'decrement'])){
			if($type == 'array'){
				return;
			}

			$item	= $method == 'decrement' ? 0 - ($item ?: 1) : ($item ?: 1);
			$method	= 'increment';
		}elseif($method == 'replace'){
			$method	= 'update';
		}elseif($method == 'remove'){
			$method	= 'delete';
		}

		if(isset($id)){
			if(isset($items[$id])){
				if($method == 'add'){
					wpjam_throw('duplicate_'.$this->primary_key, $this->primary_title.'-「'.$id.'」已存在');
				}
			}else{
				if(in_array($method, ['update', 'delete'])){
					wpjam_throw('invalid_'.$this->primary_key, $this->primary_title.'-「'.$id.'」不存在');
				}elseif($method == 'set'){
					$method == 'add';	// set => add
				}
			}
		}

		if(in_array($method, ['add', 'insert']) && $this->max_items && count($items) >= $this->max_items){
			wpjam_throw('over_max_items', '最大允许数量：'.$this->max_items);
		}

		if($type == 'array' && isset($item)){
			if(in_array($this->primary_key, ['option_key', 'id'])){
				if($this->unique_key){
					$title	= $this->unique_title ?: $this->unique_key;
					$value	= $item[$this->unique_key] ?? null;

					if(is_null($id) || isset($value)){
						if(!$value && !is_numeric($value)){
							wpjam_throw('empty_'.$this->unique_key, $title.'不能为空');
						}

						foreach($items as $_id => $_item){
							if(isset($id) && $id == $_id){
								continue;
							}

							if($_item[$this->unique_key] == $value){
								wpjam_throw('duplicate_'.$this->unique_key, $title.'不能重复');
							}
						}
					}
				}

				if($method == 'insert' || ($method == 'add' && is_null($id))){
					if($items){
						$ids	= array_map(fn($id)=> (int)str_replace('option_key_', '', $id), array_keys($items));
						$id		= max($ids)+1;
					}else{
						$id		= 1;
					}

					$id	= $this->primary_key == 'option_key' ? 'option_key_'.$id : $id;
				}

				if(isset($id)){
					$item[$this->primary_key] = $id;
				}
			}else{
				if(is_null($id)){
					$id	= $item[$this->primary_key] ?? null;

					if(!$id){
						wpjam_throw('empty_'.$this->primary_key, $this->primary_title.'不能为空');
					}

					if(isset($items[$id])){
						wpjam_throw('duplicate_'.$this->primary_key, $this->primary_title.'不能重复');
					}
				}
			}

			$item	= wpjam_filter($item, fn($v)=> !is_null($v));
		}

		if($method == 'insert'){
			if($type == 'array'){
				$items	= $this->last ? array_replace($items, [$id=>$item]) : ([$id=>$item]+$items);
			}else{
				$cb	= 'array_'.($this->last ? 'push' : 'unshift');

				$cb($items, $item);
			}
		}elseif($method == 'add'){
			if(isset($id)){
				$items[$id]	= $item;
			}else{
				$items[]	= $item;
			}
		}elseif($method == 'update'){
			if($type == 'array'){
				$item	= wp_parse_args($item, $items[$id]);
			}

			$items[$id]	= $item;
		}elseif($method == 'set'){
			$items[$id]	= $item;
		}elseif($method == 'empty'){
			if(!$items){
				return [];
			}

			$prev	= $items;
			$items	= [];
		}elseif($method == 'delete'){
			$items	= wpjam_except($items, $id);
		}elseif($method == 'increment'){
			if(isset($items[$id])){
				$item	= (int)$items[$id] + $item;
			}

			$items[$id] = $item;
		}

		if($type == 'array' && $items && is_array($items) && in_array($this->primary_key, ['option_key','id'])){
			foreach($items as &$item){
				$item	= wpjam_except($item, $this->primary_key);

				if($this->parent_key){
					$item	= wpjam_except($item, $this->parent_key);
				}
			}
		}

		$result	= $this->update_items($items);

		if($result){
			if($method == 'insert'){
				if($type == 'array'){
					return ['id'=>$id,	'last'=>(bool)$this->last];
				}
			}elseif($method == 'empty'){
				return $prev;
			}elseif($method == 'increment'){
				return $item;
			}
		}

		return $result;
	}

	public function query_items($args){
		$items	= $this->parse_items();

		return ['items'=>$items, 'total'=>count($items)];
	}

	public function parse_items($items=null){
		$items	??= $this->get_items();

		if($items && is_array($items)){
			foreach($items as $id => &$item){
				$item	= $this->parse_item($item, $id);
			}

			if($this->item_type == 'array' && $this->lazyload_key){
				wpjam_lazyload($this->lazyload_key, $items);
			}

			return $items;
		}

		return [];
	}

	public function parse_item($item, $id){
		return $this->item_type == 'array' ? array_merge((is_array($item) ? $item : []), [$this->primary_key => $id]) : $item;
	}

	public function get_results(){
		return $this->parse_items();
	}

	public function reset(){
		return $this->delete_items();
	}

	public function exists($value, $type='unique'){
		$items	= $this->get_items();

		if(!$items){
			return false;
		}

		if($this->item_type == 'array'){
			if($type == 'unique' && $this->unique_key){
				return in_array($value, array_column($items, $this->unique_key));
			}else{
				return isset($items[$value]);
			}
		}else{
			return in_array($value, $items);
		}
	}

	public function get($id){
		$item	= $this->get_items()[$id] ?? false;

		return $item ? $this->parse_item($item, $id) : false;
	}

	protected static function parse_by_type($args){
		$type	= wpjam_pull($args, 'type');

		if($type == 'option'){
			if(!empty($args['option_name'])){
				return [
					'primary_key'	=> 'option_key',
					'get_items'		=> fn()=> get_option($this->option_name) ?: [],
					'update_items'	=> fn($items)=> update_option($this->option_name, $items),
				];
			}
		}elseif($type == 'setting'){
			if(wpjam_every(['option_name', 'setting_name'], fn($k)=> !empty($args[$k]))){
				return [
					'get_items'		=> fn()=> wpjam_get_setting($this->option_name, $this->setting_name) ?: [],
					'update_items'	=> fn($items)=> wpjam_update_setting($this->option_name, $this->setting_name, $items),
				];
			}
		}elseif($type == 'meta'){
			if(wpjam_every(['meta_type', 'meta_key', 'object_id'], fn($k)=> !empty($args[$k]))){
				return [
					'parent_key'	=> $args['meta_type'].'_id',
					'get_items'		=> fn()=> get_metadata($this->meta_type, $this->object_id, $this->meta_key, true) ?: [],
					'delete_items'	=> fn()=> delete_metadata($this->meta_type, $this->object_id, $this->meta_key),
					'update_items'	=> fn($items)=> update_metadata($this->meta_type, $this->object_id, $this->meta_key, $items, $this->get_items()),
				];
			}
		}elseif($type == 'cache'){
			if(!empty($args['cache_key'])){
				return [
					'item_type'		=> '',
					'retry_times'	=> 10,
					'object'		=> wpjam_cache(wp_parse_args($args, ['group'=>'list_cache'])),
					'get_items'		=> fn()=> $this->object->get_with_cas($this->cache_key, $this, []) ?: [],
					'update_items'	=> fn($items)=> $this->object->cas($this->cas_token, $this->cache_key, $items),
				];
			}
		}elseif($type == 'transient'){
			if(!empty($args['transient'])){
				return [
					'item_type'		=> '',
					'get_items'		=> fn()=> get_transient($this->transient) ?: [],
					'update_items'	=> fn($items)=> set_transient($this->transient, $items, DAY_IN_SECONDS),
				];
			}
		}elseif($type == 'post_content'){
			if(!empty($args['post_id'])){
				return [
					'parent_key'	=> 'post_id',
					'object'		=> wpjam_get_post_object($args['post_id']),
					'get_items'		=> fn()=> $this->object->get_unserialized(),
					'update_items'	=> fn($items)=> $this->object->save(['content'=>$items ?: ''])
				];
			}
		}elseif($type){
			$parser	= wpjam_pull($args, 'parser');

			if($parser){
				return $parser($args, $type);
			}
		}
	}
}

class WPJAM_Lazyloader{
	public static function queue($name, $ids){
		if(is_array($name)){
			if(wp_is_numeric_array($name)){
				array_walk($name, fn($n)=> self::queue($n, $ids));
			}else{
				array_walk($name, fn($n, $k)=> self::queue($n, array_column($ids, $k)));
			}

			return;
		}

		$ids	= array_unique($ids);
		$ids	= array_filter($ids);

		if(!$ids){
			return;
		}

		if(in_array($name, ['blog', 'site'])){
			_prime_site_caches($ids);
		}elseif($name == 'post'){
			_prime_post_caches($ids, false, false);

			self::queue('post_meta', $ids);
		}elseif($name == 'term'){
			_prime_term_caches($ids);
		}elseif($name == 'comment'){
			_prime_comment_caches($ids);
		}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
			wp_metadata_lazyloader()->queue_objects(wpjam_remove_postfix($name, '_meta'), $ids);
		}else{
			self::call_pending('add', $name, $ids);
		}
	}

	public static function add($name, $args){
		wpjam_add_item('lazyloader', $name, $args);
	}

	public static function call_pending($action, $name, ...$args){
		$items	= array_unique(wpjam_get_items('lazyloader', $name));

		if($action == 'load'){
			if($items){
				if($args){
					$args[0]($items, $name);
				}else{
					self::remove_filter($name, $items);
				}
			}

			$items	= [];
		}elseif($action == 'add'){
			if(!$items){
				self::add_filter($name);
			}

			$items	= array_merge($items, $args[0]);
		}

		wpjam_update_items('lazyloader', $items, $name);
	}

	public static function __callStatic($method, $args){
		if(str_ends_with($method, 'filter')){
			$name		= $args[0];
			$setting	= wpjam_get_item('lazyloader', $name);
			$filter		= $setting ? $setting['filter'] : (str_ends_with($name, '_meta') ? 'get_'.$name.'data' : '');

			if($filter){
				if($method == 'remove_filter'){
					if($filter == 'get_'.$name.'data'){
						update_meta_cache(wpjam_remove_postfix($name, '_meta'), $args[1]);
					}else{
						$setting['callback']($args[1]);
					}
				}

				$method($filter, [self::class, 'callback_'.$name]);
			}
		}elseif(str_starts_with($method, 'callback_')){
			self::call_pending('load', wpjam_remove_prefix($method, 'callback_'));

			return array_shift($args);
		}
	}
}