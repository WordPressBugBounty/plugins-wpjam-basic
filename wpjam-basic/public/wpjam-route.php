<?php
function wpjam($field='', ...$args){
	$object	= WPJAM_API::get_instance();

	if(!$field){
		return $object;
	}

	if(str_ends_with($field, '[]')){
		$field	= substr($field, 0, -2);
		$method	= $args ? (count($args) <= 2 && is_null(array_last($args)) ? 'delete' : 'add') : 'get';
	}else{
		$method	= $args && (count($args) > 1 || is_array($args[0])) ? 'set' : 'get';
	}

	return $object->$method($field, ...$args);
}

function wpjam_var($name, ...$args){
	$names	= str_contains($name, ':') ? explode(':', $name, 2) : ['vars', $name];
	$value	= wpjam(...$names);

	if($args && ($value === null || !is_closure($args[0]))){
		$value	= maybe_closure($args[0], ...array_reverse($names));

		wpjam(...[...$names, is_wp_error($value) ? null : $value]);
	}

	return $value;
}

function wpjam_load($hook, $cb, ...$args){
	if(!$cb || !is_callable($cb)){
		return;
	}

	$hook	= array_filter((array)$hook, fn($h)=> !did_action($h));

	if(!$hook){
		$cb();
	}elseif(count($hook) == 1){
		add_action(array_first($hook), $cb, ...$args);
	}else{
		array_walk($hook, fn($h)=> add_action($h, fn()=> array_all($hook, 'did_action') && $cb(), ...$args));
	}
}

function wpjam_init($cb){
	wpjam_load('init', $cb);
}

function wpjam_include($hook, $file, ...$args){
	wpjam_load($hook, fn()=> array_map(fn($f)=> include $f, (array)$file), ...$args);
}

function wpjam_hooks($name, ...$args){
	$type	= $name === 'remove' ? $name : '';
	$name	= ($type || !$name) ? array_shift($args) : $name;
	$name	= is_string($name) && str_contains($name, ',') ? wp_parse_list($name) : $name;

	if(is_array($name)){
		return wpjam_map($name, fn($n)=> wpjam_hooks($type, ...(is_array($n) ? $n : [$n, ...$args])));
	}

	if($name && $args && ($cb = array_shift($args))){
		return wpjam_map(wp_is_numeric_array($cb) && !is_callable($cb) ? $cb : [$cb], fn($cb)=> wpjam_hook($type, $name, $cb, ...$args));
	}
}

function wpjam_hook($name, ...$args){
	if($name === 'remove'){
		return ($args[2] ??= has_filter(...$args)) !== false ? remove_filter(...$args) : false;
	}

	$attr	= in_array($name, ['once', 'echo', 'tap'], true) ? [$name => true] : [];
	$name	= ($attr || !$name) ? array_shift($args) : $name;
	$cb		= array_shift($args);

	if($attr += wpjam_is_assoc_array($cb) ? $cb : ($attr ? ['callback'=>$cb] : [])){
		$object	= wpjam_args($attr);
		$cb		= wpjam_bind(function(...$args){
			if($this->check && !($this->check)(...$args)){
				return $args[0];
			}

			if($this->once){
				$hook	= $GLOBALS['wp_filter'][current_filter()];

				unset($hook->callbacks[$hook->current_priority()][$this->idx]);
			}

			$result	= ($this->callback)(...$args);

			if($this->echo){
				echo $result;
			}

			return $this->tap ? $args[0] : $result;
		}, $object);

		if($object->once){
			$object->idx	= wpjam_build_callback_unique_id($cb);
		}
	}

	return add_filter($name, $cb, ...$args);
}

function wpjam_callback($cb, $parse=false, &$args=[]){
	if(is_string($cb) && ($sep = array_find(['::', '->'], fn($v)=> str_contains($cb, $v)))){
		$static	= $sep == '::';
		$cb		= explode($sep, $cb, 2);
	}

	if(is_array($cb)){
		if(!$cb[0] || (is_string($cb[0]) && !class_exists($cb[0]))){
			return $parse ? wpjam_throw('invalid_model', $cb[0]) : null;
		}

		$exists	= $cb[1] ? method_exists(...$cb) : false;
	}else{
		$exists	= $cb && is_callable($cb);
	}

	if(!$parse){
		return $exists ? $cb : null;
	}

	if(is_array($cb) && is_string($cb[0])){
		$static	??= $exists ? wpjam_get_reflection($cb, 'isStatic') : ($cb[1] ? method_exists($cb[0], '__callStatic') : wpjam_throw('invalid_callback'));
		$exists || method_exists($cb[0], '__call'.($static ? 'Static' : '')) || wpjam_throw('invalid_callback', [implode($sep ?? '::', $cb)]);

		if(!$static){
			$args	= ($cb[1] == 'value_callback' && count($args) == 2) ? array_reverse($args) : $args;
			$inst	= [$cb[0], 'get_instance'];
			$num	= wpjam_get_reflection($inst, 'NumberOfRequiredParameters');
			$num	= isset($num) && count($args) >= $num ? ($num ?: 1) : wpjam_throw('invalid_callback', [implode($sep ?? '::', $cb)]);
			$cb[0]	= $inst(...array_splice($args, 0, $num)) ?: wpjam_throw('invalid_id', [$cb[0]]);
		}

		$cb = ($public ?? true) ? $cb : wpjam_get_reflection($cb, 'Closure')($static ? null : $cb[0]);
	}

	return $cb;
}

function wpjam_call($cb, ...$args){
	try{
		$cb	= wpjam_callback($cb, true, $args);
	}catch(Exception $e){
		return;
	}

	if(is_callable($cb)){
		return $cb(...$args);
	}
}

function wpjam_call_multiple($cb, $args){
	return array_map(fn($v)=> wpjam_call($cb, ...(array)$v), $args);
}

function wpjam_bind($cb, $args){
	return is_closure($cb) ? $cb->bindTo(...array_fill(0, 2, is_object($args) ? $args : wpjam_args($args))) : $cb;
}

function wpjam_try($cb, ...$args){
	return wpjam_if_error(wpjam_callback(wpjam_if_error($cb, 'throw'), true, $args)(...$args), 'throw');
}

function wpjam_catch($cb, ...$args){
	if($cb instanceof WPJAM_Exception){
		return $cb->get_error();
	}elseif($cb instanceof Exception){
		return new WP_Error($cb->getCode(), $cb->getMessage());
	}elseif(is_wp_error($cb)){
		return $cb;
	}

	try{
		return wpjam_callback($cb, true, $args)(...$args);
	}catch(Exception $e){
		return wpjam_catch($e);
	}
}

function wpjam_ob($cb, ...$args){
	ob_start() && wpjam_call($cb, ...$args);

	return ob_get_clean();
}

function wpjam_retry($times, $cb, ...$args){
	do{
		$times	-= 1;
		$result	= wpjam_catch($cb, ...$args);
	}while($result === false && $times > 0);

	return $result;
}

function wpjam_value($model, $name, ...$args){
	if(is_string($model)){
		return wpjam_call($model.'::get_'.$name, ...$args);
	}

	$args	= $model;
	$names	= (array)$name;
	$key	= 'value_callback';
	$id		= $args['id'] ?? null;
	$value	= wpjam_get($args, ['data', ...$names]);

	if(isset($value)){
		return $value;
	}

	if(empty($args[$key])){
		$model	= $args['model'] ?? '';

		if($id && count($names) >= 2 && $names[0] == 'meta_input' && ($meta_type = ($args['meta_type'] ?? '') ?: wpjam_value($model, 'meta_type'))){
			$args['meta_type']	= $meta_type;

			array_shift($names);
		}elseif($model && method_exists($model, $key)){
			$args[$key] = [$model, $key];
		}
	}

	foreach(wpjam_pick($args, [$key, 'meta_type']) as $k => $v){
		$value	= $k == $key ? wpjam_trap($v, $names[0], $id, null) : ($id ? wpjam_get_metadata($v, $id, $names[0]) : null);
		$value	= wpjam_get([$names[0]=>$value], $names);

		if(isset($value)){
			return $value;
		}
	}
}

function wpjam_call_for_blog($blog_id, $cb, ...$args){
	try{
		$switched	= (is_multisite() && $blog_id && $blog_id != get_current_blog_id()) ? switch_to_blog($blog_id) : false;

		return $cb(...$args);
	}finally{
		$switched && restore_current_blog();
	}
}

function wpjam_call_with_suppress($cb, $filters){
	$suppressed	= array_filter($filters, fn($args)=> remove_filter(...$args));

	try{
		return $cb();
	}finally{
		wpjam_call_multiple('add_filter', $suppressed);
	}
}

function wpjam_dynamic_method($class, $name, ...$args){
	if($class && $name){
		$group	= 'dynamic_method';
		$key	= $class.'['.$name.']';

		if(!$args){
			return wpjam($group, $key) ?? wpjam_dynamic_method(get_parent_class($class), $name);
		}

		(!$args[0] || is_closure($args[0])) && wpjam($group.'[]', $key, $args[0] ?: null);
	}
}

function wpjam_build_callback_unique_id($cb){
	return _wp_filter_build_unique_id(null, $cb, null);
}

function wpjam_get_reflection($cb, $key='', ...$args){
	if(is_array($cb) && !is_string($cb[0])){
		$cb[0]	= get_class($cb[0]);
	}

	if(is_array($cb) && empty($cb[1])){
		$ref	= class_exists($cb[0]) ? wpjam_var('reflection:class['.strtolower($cb[0]).']', fn()=> new ReflectionClass($cb[0])) : null;
	}else{
		$ref	= ($cb = wpjam_callback($cb)) ? wpjam_var('reflection:cb['.wpjam_build_callback_unique_id($cb).']', fn()=> is_array($cb) ? new ReflectionMethod(...$cb) : new ReflectionFunction($cb)) : null;
	}

	return $ref && $key ? [$ref, (array_find(['get', 'is', 'has', 'in'], fn($v)=> str_starts_with($key, $v)) ? '' : 'get').$key](...$args) : $ref;
}

function wpjam_get_annotation($class, $key=''){
	return wpjam_get(wpjam_var('annotation:'.strtolower($class), function($class){
		$data	= [];
		$ref	= wpjam_get_reflection([$class]);

		if(method_exists($ref, 'getAttributes')){
			foreach($ref->getAttributes() as $attr){
				$k	= $attr->getName();
				$v	= $attr->getArguments();
				$v	= ($v && wp_is_numeric_array($v) && ($k == 'config' ? is_array($v[0]) : count($v) == 1)) ? $v[0] : $v;

				$data[$k]	= $v ?: null;
			}
		}elseif(preg_match_all('/@([a-z0-9_]+)\s+([^\r\n]*)/i', ($ref->getDocComment() ?: ''), $matches, PREG_SET_ORDER)){
			foreach($matches as $m){
				$k	= $m[1];
				$v	= trim($m[2]) ?: null;

				$data[$k]	= ($v && $k == 'config') ? wp_parse_list($v) : $v;
			}
		}

		return wpjam_set($data, 'config', wpjam_array($data['config'] ?? [], fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v, 2) : [$v, true]) : [$k, $v]));
	}), $key ?: null);
}

if(!function_exists('maybe_callback')){
	function maybe_callback($value, ...$args){
		return $value && is_callable($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('maybe_closure')){
	function maybe_closure($value, ...$args){
		return $value && is_closure($value) ? $value(...$args) : $value;
	}
}

if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

function wpjam_if_error($value, ...$args){
	if($args && is_wp_error($value)){
		if(is_closure($args[0])){
			return array_shift($args)($value, ...$args);
		}elseif(in_array($args[0], [null, false, [], ''], true)){
			return $args[0];
		}elseif($args[0] === 'die'){
			wp_die($value);
		}elseif($args[0] === 'throw'){
			wpjam_throw($value);
		}elseif($args[0] === 'send'){
			wpjam_send_json($value);
		}
	}

	return $value;
}

function wpjam_trap($cb, ...$args){
	$if	= array_pop($args);

	return wpjam_if_error(wpjam_catch($cb, ...$args), $if);
}

function wpjam_cache($key, ...$args){
	if(!$args || is_array($args[0])){
		return WPJAM_Cache::get_instance($key, ...$args);
	}

	[$group, $cb, $arg]	= array_pad($args, 3, null);

	$fix	= is_bool($group) ? ($group ? 'site_' : '').'transient' : '';
	$args	= is_numeric($arg) ? ['expire'=>$arg] : (array)$arg;
	$expire	= ($args['expire'] ?? '') ?: 86400;

	if($cb === false){
		return $fix ? ('delete_'.$fix)($key) : wp_cache_delete($key, $group);
	}

	$force	= $args['force'] ?? false;
	$value	= $fix ? ('get_'.$fix)($key) : wp_cache_get($key, $group, ($force === 'get' || $force === true));

	if($cb && ($value === false || $force === 'set' || $force === true)){
		$value	= $cb($value, $key, ...($fix ? [] : [$group ?: 'default']));

		if(!is_wp_error($value) && $value !== false){
			$result	= $fix ? ('set_'.$fix)($key, $value, $expire) : wp_cache_set($key, $value, $group, $expire);
		}
	}

	return $value;
}

function wpjam_counts($name, $cb){
	return wpjam_cache($name, 'counts', $cb);
}

function wpjam_transient($name, $cb, $args=[], $global=false){
	return wpjam_cache($name, (bool)$global, $cb, $args);
}

function wpjam_increment($name, $max=0, $expire=86400, $global=false){
	return wpjam_transient($name, fn($v)=> ($max && (int)$v >= $max) ? 1 : (int)$v+1, ['expire'=>$expire, 'force'=>'set'], $global);
}

function wpjam_lock($name, $expire=10, $group=false){
	$group	= is_bool($group) ? ($group ? 'site-' : '').'transient' : ($group ?: 'default');

	return $expire == -1 ? wp_cache_delete($name, $group) : (wp_cache_get($name, $group, true) || !wp_cache_add($name, 1, $group, $expire));
}

function wpjam_is_over($name, $max, $time, $group=false, $action='increment'){
	$times	= wp_cache_get($name, $group) ?: 0;

	return ($times > $max) || ($action == 'increment' && wp_cache_set($name, $times+1, $group, ($max == $times && $time > 60) ? $time : 60) && false);
}

// Code
function wpjam_verification($group, $key, ...$args){
	$group	= (is_array($group) ? $group : ['group'=>$group])+['max_attempts'=>5, 'interval'=>1, 'expire'=>30];
	$object	= wpjam_cache(['group'=>'verification_code', 'global'=>true, 'prefix'=>(wpjam_pull($group, 'group') ?: 'default')]+$group);
	$max	= $object->max_attempts;

	if($max){
		$times	= (int)$object->get($key.':failed_times');
		($times > $max) && wpjam_throw('too_many_attempts', sprintf(__('Failed attempts exceeded, Please try again in %d minutes.', 'wpjam'), $object->expire/2));
	}

	if($args){
		if(!$args[0] || (int)$args[0] !== (int)$object->get($key.':code')){
			$max && $object->set($key.':failed_times', $times+1, $object->expire*30);

			wpjam_throw('invalid_code');
		}

		return true;
	}

	if($interval = $object->interval){
		$object->get($key.':time') ? wpjam_throw('error', sprintf(__('A verification code was sent %d minutes ago.', 'wpjam'), $interval)) : $object->set($key.':time', time(), $interval*60);
	}

	$code	= rand(100000, 999999);

	return [$code, $object->set($key.':code', $code, $object->expire*60)][0];
}

function wpjam_generate_verification_code($key, $group='default'){
	return wpjam_catch('wpjam_verification', $group, $key);
}

function wpjam_verify_code($key, $code, $group='default'){
	return wpjam_catch('wpjam_verification', $group, $key, $code);
}

function wpjam_db_transaction($cb, ...$args){
	$GLOBALS['wpdb']->query("START TRANSACTION;");

	try{
		$result	= $cb(...$args);
		$error	= $GLOBALS['wpdb']->last_error;
		$error && wpjam_throw('error', $error);

		$GLOBALS['wpdb']->query("COMMIT;");

		return $result;
	}catch(Exception $e){
		$GLOBALS['wpdb']->query("ROLLBACK;");

		return false;
	}
}

function wpjam_options($field, $args=[]){
	$type	= $args['type'] ??= (is_array($field) ? '' : 'select');
	$items	= wpjam_filter(is_array($field) ? $field : (wpjam($field) ?: []), $args['filter'] ?? []);

	return wpjam_reduce($items, wpjam_bind(function($carry, $item, $opt){
		if(!is_array($item) && !is_object($item)){
			$carry[$opt]	= $item;
		}elseif(!isset($item['options'])){
			$title	= $this->title_field ?: 'title';
			$name	= $this->name_field ?: 'name';
			$opt	= ($item[$name] ?? '') ?: $opt;
			$carry	= wpjam_set($carry, $opt, wpjam_pick($item, ['label', 'image', 'description', 'alias', 'fields', 'show_if'])+($this->type == 'select' ? wpjam_pick($item, [$title]) : (($item['field'] ?? '') ?: [])+['label'=>($item[$title] ?? '')]));
		}

		return $carry;
	}, $args), ($type == 'select' ? [''=>__('&mdash; Select &mdash;', 'wpjam')] : []), 'options');
}

function wpjam_is(...$args){
	$query	= ($args && is_object($args[0])) ? array_shift($args) : array_last(wpjam('query'));

	if(!$query || !($query instanceof WP_Query) || !$query->is_main_query()){
		return false;
	}

	if($args){
		return array_any(wp_parse_list(array_shift($args)), fn($t)=> method_exists($query, 'is_'.$t) && [$query, 'is_'.$t](...$args));
	}

	return $query->is_front_page() ? 'home' : (array_find(['feed', 'author', 'category', 'tag', 'tax', 'post_type_archive', 'search', 'date', 'archive', '404', 'page', 'single', 'attachment'], fn($t)=> [$query, 'is_'.$t]()) ?: true);
}

// $name, $value
// $name, $data
// $args
// $name, $cb
// $cb
function wpjam_register_config(...$args){
	$group	= count($args) >= 3 ? array_shift($args) : '';
	$args	= array_values(array_filter($args, fn($v)=> isset($v)));

	return $args ? wpjam('config', ($group ?: 'default').'['.(count($args) == 1 ? '' : array_shift($args)).']', array_shift($args)) : null;
}

function wpjam_get_config($group=''){
	return wpjam_reduce(wpjam('config', $group ?: 'default') ?: [], function($c, $v, $k){
		$v	= maybe_callback($v);
		$v	= is_numeric($k) ? (is_array($v) ? $v : []) : [$k=>$v];

		return array_merge($c, $v);
	}, []);
}

// LazyLoader
function wpjam_lazyloader($name, ...$args){
	return wpjam('lazyloader', $name, ...$args);
}

function wpjam_lazyload($name, $ids){
	if(!$name || !($ids	= array_filter($ids))){
		return;
	}

	if(is_array($name)){
		return array_walk($name, fn($n, $k)=> wpjam_lazyload($n, is_numeric($k) ? $ids : array_column($ids, $k)));
	}

	$ids	= array_unique($ids);

	if($name == 'post'){
		_prime_post_caches($ids, false, false);

		return wpjam_lazyload('post_meta', $ids);
	}elseif(in_array($name, ['blog', 'site', 'term', 'comment'])){
		return ('_prime_'.($name == 'blog' ? 'site' : $name).'_caches')($ids);
	}elseif(in_array($name, ['term_meta', 'comment_meta', 'blog_meta'])){
		return wp_metadata_lazyloader()->queue_objects(substr($name, 0, -5), $ids);
	}

	$pending	= wpjam('pending', $name) ?: [];

	if(!$pending){
		$loader	= wpjam_lazyloader($name) ?: (str_ends_with($name, '_meta') ? [
			'filter'	=> 'get_'.$name.'data',
			'callback'	=> fn($pending)=> update_meta_cache(substr($name, 0, -5), $pending)
		] : []);

		$loader && wpjam_hook('once', $loader['filter'], fn($pre)=> [$pre, wpjam_load_pending($name, $loader['callback'])][0]);
	}

	wpjam('pending', $name, array_merge($pending, $ids));
}

function wpjam_load_pending($name, $cb){
	if($pending	= wpjam('pending', $name)){
		wpjam_call($cb, array_unique($pending));

		wpjam('pending', $name, []);
	}
}

function wpjam_pattern($key, ...$args){
	return wpjam('pattern', $key, ...($args ? [array_combine(['pattern', 'custom_validity'], $args)] : []));
}

function wpjam_default(...$args){
	return wpjam('defaults', ...$args);
}

function wpjam_get_current_user($required=false){
	$value	= wpjam_var('user', fn()=> apply_filters('wpjam_current_user', null));

	return $required ? (is_null($value) ? new WP_Error('bad_authentication') : $value) : wpjam_if_error($value, null);
}

// Parameter
function wpjam_parameter($name, $method='GET'){
	if(in_array($method, ['DATA', 'DEFAULTS'])){
		if($method == 'DATA' && $name && isset($_GET[$name])){
			return wp_unslash($_GET[$name]);
		}

		$types	= ['defaults', ...($method == 'DATA' ? ['data'] : [])];
		$data	= wpjam_var('parameter:'.$method, fn()=> array_reduce($types, fn($c, $t)=> wpjam_merge($c, ($v = wpjam_parameter($t, 'REQUEST')) && is_string($v) && str_starts_with($v, '{') ? wpjam_json_decode($v) : wp_parse_args($v ?: [])), []));
	}else{
		$data	= ['POST'=>$_POST, 'REQUEST'=>$_REQUEST][$method] ?? $_GET;

		if($name){
			if(isset($data[$name])){
				return wp_unslash($data[$name]);
			}

			if($_POST || !in_array($method, ['POST', 'REQUEST'])){
				return null;
			}
		}else{
			if($data || in_array($method, ['GET', 'REQUEST'])){
				return wp_unslash($data);
			}
		}

		$data	= wpjam_var('parameter:input', function(){
			$v	= file_get_contents('php://input');
			$v	= $v && is_string($v) ? @wpjam_json_decode($v) : $v;

			return is_array($v) ? $v : [];
		});
	}

	return wpjam_get($data, $name ?: null);
}

function wpjam_get_parameter($name='', $args=[], $method=''){
	$args	= array_merge($args, $method ? compact('method') : []);

	if(is_array($name)){
		return $name ? wpjam_map(wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name, 'wpjam_get_parameter', 'kv') : [];
	}

	$method	= strtoupper(wpjam_pull($args, 'method') ?: 'GET');
	$value	= wpjam_parameter($name, $method);

	if($name){
		$fallback	= wpjam_pull($args, 'fallback');
		$default	= wpjam_pull($args, 'default', wpjam_default($name));
		$send		= wpjam_pull($args, 'send', true);
		$value		??= ($fallback ? wpjam_parameter($fallback, $method) : null) ?? $default;

		if($args){
			$type	= $args['type'] ??= '';
			$args	= ['type'=>$type == 'int' ? 'number' : $type]+$args;	// 兼容
			$field	= wpjam_field(['key'=>$name]+$args);
			$value	= wpjam_catch([($type ? $field : $field->schema(false)), 'validate'], $value, 'parameter');

			$send && wpjam_if_error($value, 'send');
		}
	}

	return $value;
}

function wpjam_get_post_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'POST');
}

function wpjam_get_request_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'REQUEST');
}

function wpjam_get_data_parameter($name='', $args=[]){
	return wpjam_get_parameter($name, $args, 'data');
}

function wpjam_method_allow($method){
	return ($m = $_SERVER['REQUEST_METHOD']) == strtoupper($method) ? true : wp_die('method_not_allow', '接口不支持 '.$m.' 方法，请使用 '.$method.' 方法！');
}

// Request
function wpjam_remote_request($url, $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');
	$field	= wpjam_pull($args, 'field') ?? 'body';
	$args	+= ['body'=>[], 'headers'=>[], 'sslverify'=>false, 'stream'=>false];
	$method	= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

	if($method == 'FILE'){
		wpjam_hook('once', 'pre_http_request', fn($pre, $args, $url)=> (new WP_Http_Curl())->request($url, $args), 1, 3);

		$method = $args['body'] ? 'POST' : 'GET';
	}elseif($method != 'GET'){
		$key	= 'content-type';
		$type	= 'application/json';

		$args['headers']	= array_change_key_case($args['headers']);

		if(array_first(wpjam_pull($args, ['json_encode', 'need_json_encode']))){
			$args['headers'][$key]	= $type;
		}

		if(str_contains($args['headers'][$key] ?? '', $type) && is_array($args['body'])){
			$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
		}
	}

	try{
		$result	= wpjam_try('wp_remote_request', $url, $args+compact('method'));
		$res	= $result['response'];
		$code	= $res['code'];
		$body	= &$result['body'];

		$code && !wpjam_between($code, 200, 299) && wpjam_throw($code, $code.' - '.$res['message'].'-'.var_export($body, true));

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($result, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
				$decode	= wpjam_json_decode($body);

				if(!is_wp_error($decode)){
					$body	= $decode;
					$err	+= ['success'=>'0']+wpjam_fill(['errcode', 'errmsg', 'detail'], fn($v)=> $v);

					($code	= wpjam_pull($body, $err['errcode'])) && $code != $err['success'] && wpjam_throw($code, wpjam_pull($body, $err['errmsg']), wpjam_pull($body, $err['detail']) ?? array_filter($body));
				}
			}
		}

		return $field ? wpjam_get($result, $field) : $result;
	}catch(Exception $e){
		$error	= wpjam_fill(['code', 'message', 'data'], fn($k)=> [$e, 'get_error_'.$k]());

		if(apply_filters('wpjam_http_response_error_debug', true, $error['code'], $error['message'])){
			trigger_error(var_export(array_filter(['url'=>$url, 'error'=>array_filter($error), 'body'=>$args['body']]), true));
		}

		if($throw){
			throw $e;
		}

		return wpjam_catch($e);
	}
}

// Error
function wpjam_throw($code, $msg='', $data=[]){
	throw new WPJAM_Exception(is_wp_error($code) ? $code : new WP_Error($code, $msg, $data));
}

function wpjam_error($code, $msg='', ...$args){
	if(is_wp_error($code)){
		$data	= $code->get_error_data();
		$data	= ['errcode'=>$code->get_error_code(), 'errmsg'=>$code->get_error_message()]+array_filter(is_array($data) ? $data : ['errdata'=>$data]);

		return array_merge($data, (!$data['errmsg'] || is_array($data['errmsg'])) ? wpjam_error($data['errcode'], $data['errmsg']) : []);
	}

	if(($msg && !is_array($msg)) || $args){
		wpjam('error') || add_action('wp_error_added', function($code, $msg, $data, $error){
			if($code && (!$msg || is_array($msg)) && count($error->get_error_messages($code)) <= 1 && ($item = wpjam_error($code, $msg))){
				$error->remove($code);
				$error->add($code, $item['errmsg'], !empty($item['modal']) ? array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]) : $data);
			}
		}, 10, 4);

		return wpjam('error', $code, ['errmsg'=>$msg, 'modal'=>$args[0] ?? []]);
	}

	if($item = wpjam('error', $code)){
		$msg	= maybe_closure($item['errmsg'], $msg ?: []);
	}else{
		$args	= $msg ?: [];
		$error	= $code;

		if(try_remove_prefix($code, 'invalid_')){
			$code	= $code == 'id' ? 'ID' : str_replace(['_id', '_'], [' ID', ' '], $code);
			$msg	= 'Invalid '.$code.($args ? ': %s.' : '.');

			if(($trans = __($msg, 'wpjam')) != $msg){
				$msg	= $trans;
			}else{
				$msg	= __('Invalid %s'.($args ? ': %s.': '.'), 'wpjam');
				$args	= [__($code, 'wpjam'), ...$args];
			}
		}elseif(try_remove_suffix($code, '_required')){
			$msg	= __('%s is empty or invalid.', 'wpjam');
			$trans	= __($args && $code == 'value' ? '%s\'s value' :  $code, 'wpjam');
			$args	= [$args ? sprintf($trans.($code == 'value' ? '' : '%s'), ...$args) : $trans];
		}elseif(try_remove_suffix($code, '_occupied')){
			$msg	= __('%s is already in use by another account.', 'wpjam');
			$args	= [__($code, 'wpjam')];
		}else{
			$msg	= str_replace('_', ' ', $code);
		}

		$code	= $error;
	}

	return $msg ? ['errcode'=>$code, 'errmsg'=>($args && str_contains($msg, '%') ? sprintf($msg, ...$args) : $msg)]+($item ?: []) : [];
}

// Route
function wpjam_route($module, $args, $query_var=false){
	if(is_string($args) && class_exists($args)){
		$model	= $args;
		$args	= ['callback'=>$model.'::redirect'];

		wpjam_init(fn()=> ($rules = wpjam_value($model, 'rewrite_rule')) && is_array($rules) && wpjam_call_multiple('wpjam_add_rewrite_rule', is_array($rules[0]) ? $rules : [$rules]));

		is_admin() && array_map(fn($k)=> wpjam_call('wpjam_add_'.$k, wpjam_value($model, $k)), ['menu_page', 'admin_load']);
	}else{
		$args	= wpjam_is_assoc_array($args) ? array_filter($args) : ['callback'=>$args];
	}

	if($query_var){
		$action	= wpjam_get_parameter($module, ['method'=> wp_doing_ajax() ? 'DATA' : 'GET']);
		$action	&& add_action((wp_doing_ajax() ? 'admin_init' : 'parse_request'), fn()=> wpjam_dispatch($module, $action), 0);
	}

	if(!wpjam('route')){
		add_filter('query_vars',	fn($vars)=> array_merge($vars, ['module', 'action', 'term_id']), 11);
		add_filter('request',		'wpjam_parse_query_vars', 11);
		add_action('parse_request', 'wpjam_dispatch', 1);
	}

	wpjam('route[]', $module, $args+['query_var'=>$query_var]);
}

function wpjam_add_rewrite_rule(...$args){
	return add_rewrite_rule($GLOBALS['wp_rewrite']->root.array_shift($args), ...$args);
}

function wpjam_dispatch($module, $action=''){
	if(is_object($module)){
		$vars	= $module->query_vars;
		$module	= $vars['module'] ?? '';
		$action	= $vars['action'] ?? '';

		if(!$module){
			return;
		}

		remove_action('template_redirect', 'redirect_canonical');
	}

	if($item = wpjam('route', $module)){
		$item['query_var'] && $GLOBALS['wp']->set_query_var($module, $action);

		wpjam_call($item['callback'], $action, $module);
	}

	if(!is_admin()){
		$file	= $item ? ($item['file'] ?? '') : '';
		$file	= $file ?: apply_filters('wpjam_template', STYLESHEETPATH.'/template/'.$module.'/'.($action ?: 'index').'.php', $module, $action);

		is_file($file) && add_filter('template_include', fn()=> $file);
	}
}

// txt
function wpjam_txt($name, ...$args){
	$object	= wpjam_option('wpjam_verify_txts');

	if($args && is_array($args[0])){
		return $object->update_setting($name, ...$args);
	}

	if(!str_ends_with($name, '.txt')){
		$data	= $object->get_setting($name) ?: [];
		$key	= $args[0] ?? '';

		return $key == 'fields' ? [
			'name'	=> ['title'=>'文件名称',	'type'=>'text',	'required', 'value'=>$data['name'] ?? '',	'class'=>'all-options'],
			'value'	=> ['title'=>'文件内容',	'type'=>'text',	'required', 'value'=>$data['value'] ?? '']
		] : ($key ? ($data[$key] ?? '') : $data);
	}

	if($data = array_find($object->get_option(), fn($v)=> $v['name'] == $name)){
		header('Content-Type: text/plain');
		echo $data['value']; exit;
	}
}

function wpjam_activation(...$args){
	$args	= $args ? array_reverse(array_slice($args+['', 'wp_loaded'], 0, 2)) : [];
	$result = [wpjam_get_handler(['items_type'=>'transient', 'transient'=>'wpjam-actives']), ($args ? 'add' : 'empty')](...$args);

	return $args ? $result : wpjam_call_multiple('add_action', $result);
}

function wpjam_updater($type, $hostname, ...$args){
	if(!in_array($type, ['plugin', 'theme']) || !$args){
		return;
	}

	$name	= 'updater:'.$type.'['.$hostname.']';
	$url	= wpjam_var($name);

	if(!$url){
		wpjam_var($name, ...$args);

		return add_filter('update_'.$type.'s_'.$hostname, fn($update, $data, $file, $locales)=> ($item = wpjam_updater($type, $hostname, $file)) ? $item+['id'=>$data['UpdateURI'], 'version'=>$data['Version']] : $update, 10, 4);
	}

	$result	= wpjam_var('updater:'.$type.'['.$url.']', fn()=> wpjam_remote_request($url));
	$data	= is_array($result) ? ($result['template']['table'] ?? $result[$type.'s']) : [];
	$file	= $args[0];

	if(isset($data['fields']) && isset($data['content'])){
		$fields	= array_column($data['fields'], 'index', 'title');
		$item	= array_find($data['content'], fn($item)=> $item['i'.$fields[$type == 'plugin' ? '插件' : '主题']] == $file);
		$item	= $item ? array_map(fn($i)=> $item['i'.$i] ?? '', $fields) : [];

		return $item ? [$type=>$file, 'icons'=>[], 'banners'=>[], 'banners_rtl'=>[]]+array_map(fn($v)=> $item[$v], ['url'=>'更新地址', 'package'=>'下载地址', 'new_version'=>'版本', 'requires_php'=>'PHP最低版本', 'requires'=>'最低要求版本', 'tested'=>'最新测试版本']) : [];
	}

	return array_find($data, fn($item)=> $item[$type] == $file) ?: [];
}

// Extend
function wpjam_load_extends($dir, $args=[]){
	if(!is_dir($dir)){
		return;
	}

	$parse	= function($dir, $name, ...$args){
		if(in_array($name, ['.', '..', 'extends.php'])){
			return;
		}

		$file	= str_ends_with($name, '.php') ? $name : '';
		$name	= $file ? substr($name, 0, -4) : $name;
		$file	= $dir.'/'.($file ?: $name.(is_dir($dir.'/'.$name) ? '/'.$name : '').'.php');

		if(!is_file($file)){
			return;
		}

		if(!$args){
			return $name;
		}

		if($args[0] == 'include'){
			if(is_admin() || !str_ends_with($file, '-admin.php')){
				include_once $file;
			}
		}elseif($args[0] == 'field'){
			$values	= $args[1];
			$data	= wpjam_get_file_data($file);

			return $data && $data['Name'] && (!isset($values['site']) || is_network_admin() || empty($values['site'][$name])) ? [
				'key'	=> $name,
				'value'	=> !empty($values['data'][$name]),
				'title'	=> $data['URI'] ? '<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>' : $data['Name'],
				'label'	=> $data['Description']
			] : null;
		}
	};

	if($option	= wpjam_pull($args, 'option')){
		$object	= wpjam_register_option($option, $args+[
			'ajax'				=> false,
			'site_default'		=> $args['sitewide'] ?? false,
			'sanitize_callback'	=> fn($data)=> wpjam_array($data, fn($k, $v)=> $v ? $parse($dir, $k) : null),
			'fields'			=> fn()=> wpjam_sort(wpjam_array(scandir($dir), fn($k, $v)=> [null, $parse($dir, $v, 'field', $this->values)], true), ['value'=>'DESC'])
		]);

		$keys		= $object->site_default && is_multisite() ? ['data', 'site'] : ['data'];
		$values		= $object->values = wpjam_fill($keys, fn($k)=> ($object->sanitize_callback)([$object, 'get_'.($k == 'site' ? 'site_' : '').'option']()));
		$extends	= array_keys(array_merge(...array_values($values)));
	}else{
		$plugins	= get_option('active_plugins') ?: [];
		$extends	= array_filter(scandir($dir), fn($v)=> !in_array($v.(is_dir($dir.'/'.$v) ? '/'.$v : '').'.php', $plugins));
	}

	array_walk($extends, fn($v)=> $parse($dir, $v, 'include'));
}

function wpjam_get_file_data($file, $type='data'){
	$data	= $file ? array_reduce(['URI', 'Name'], fn($c, $k)=> wpjam_set($c, $k, ($c[$k] ?? '') ?: ($c['Plugin'.$k] ?? '')), get_file_data($file, [
		'Name'			=> 'Name',
		'URI'			=> 'URI',
		'PluginName'	=> 'Plugin Name',
		'PluginURI'		=> 'Plugin URI',
		'Version'		=> 'Version',
		'Description'	=> 'Description'
	])) : [];

	return $type == 'summary' ? ($data ? str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。' : '') : $data;
}

// Asset
function wpjam_asset($type, $handle, $args, $load=false){
	$args	= is_array($args) ? $args : ['src'=>$args];

	if($load || array_any(['wp', 'admin', 'login'], fn($part)=> doing_action($part.'_enqueue_scripts'))){
		$method	= wpjam_pull($args, 'method') ?: 'enqueue';

		if(empty($args[$method.'_if']) || $args[$method.'_if']($handle, $type)){
			$args	= wp_parse_args($args, ['src'=>'', 'deps'=>[], 'ver'=>false, 'media'=>'all', 'position'=>'after']);
			$src	= maybe_closure($args['src'], $handle);
			$data	= $args['data'] ?? '';

			($src || !$data) && wpjam_call('wp_'.$method.'_'.$type, $handle, $src, $args['deps'], $args['ver'], ($type == 'script' ? wpjam_pick($args, ['in_footer', 'strategy']) : $args['media']));

			$data && wpjam_call('wp_add_inline_'.$type, $handle, $data, $args['position']);
		}
	}else{
		$parts	= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
		$parts	= isset($args['for']) ? array_intersect($parts, wp_parse_list($args['for'] ?: 'wp')) : $parts;

		array_walk($parts, fn($part)=> wpjam_load($part.'_enqueue_scripts', fn()=> wpjam_asset($type, $handle, $args, true), ($args['priority'] ?? 10)));
	}
}

function wpjam_script($handle, $args=[]){
	wpjam_asset('script', $handle, $args);
}

function wpjam_style($handle, $args=[]){
	wpjam_asset('style', $handle, $args);
}

// Video
function wpjam_add_video_parser($pattern, $cb){
	wpjam('video_parser[]', [$pattern, $cb]);
}

// Capability
function wpjam_map_meta_cap($cap, $map){
	if($cap && $map && (is_callable($map) || wp_is_numeric_array($map))){
		$field	= 'map_meta_cap';

		wpjam($field) || add_filter($field, fn($caps, $cap, $user_id, $args)=> array_reduce((!in_array('do_not_allow', $caps) && $user_id) ? (wpjam($field, $cap) ?: []) : [], fn($c, $v)=> (($v = maybe_callback($v, $user_id, $args, $cap)) || is_array($v)) ? (array)$v : $c, $caps), 10, 4);

		wpjam_map(wp_parse_list($cap), fn($c)=> $c && wpjam($field.'[]', $c.'[]', $map));
	}
}

function wpjam_can($cap, ...$args){
	return ($cap = maybe_closure($cap, ...$args)) ? current_user_can($cap, ...$args) : true;
}

// Menu Page
function wpjam_add_menu_page(...$args){
	if(!$args[0]){
		return;
	}

	if(wp_is_numeric_array($args[0])){
		return array_walk($args[0], 'wpjam_add_menu_page');
	}

	if(is_array($args[0])){
		$args	= $args[0];
	}else{
		$key	= empty($args[1]['plugin_page']) ? 'menu_slug' : 'tab_slug';
		$args	= wpjam_set($args[1], $key, $args[0]);

		if(!is_admin() && ($args['function'] ?? '') == 'option' && (!empty($args['sections']) || !empty($args['fields']))){
			wpjam_register_option(($args['option_name'] ?? $args[$key]), $args);
		}
	}

	$type	= array_find(['tab_slug'=>'tabs', 'menu_slug'=>'pages'], fn($v, $k)=> !empty($args[$k]) && !is_numeric($args[$k]));

	if(!$type){
		return;
	}

	$model	= $args['model'] ?? '';
	$cap	= $args['capability'] ?? '';

	$cap && wpjam_map_meta_cap($cap, wpjam_pull($args, 'map_meta_cap'));

	if($model){
		wpjam_hooks(wpjam_call($model.'::add_hooks'));
		wpjam_init([$model, 'init']);

		$cap && method_exists($model, 'map_meta_cap') && wpjam_map_meta_cap($cap, [$model, 'map_meta_cap']);
	}

	if(is_admin()){
		if($type == 'pages'){
			$parent	= wpjam_pull($args, 'parent');
			$key	= $type.($parent ? '['.$parent.'][subs]' : '').'['.wpjam_pull($args, 'menu_slug').']';
			$args	= $parent ? $args : array_merge(wpjam_admin($key.'[]'), $args, ['subs'=>array_merge(wpjam_admin($key.'[subs][]'), $args['subs'] ?? [])]);
		}else{
			$key	= $type.'[]';
		}

		wpjam_admin($key, $args);
	}
}

if(is_admin()){
	if(!function_exists('get_screen_option')){
		function get_screen_option($option, $key=false){
			if(did_action('current_screen')){
				$screen	= get_current_screen();
				$value	= in_array($option, ['post_type', 'taxonomy']) ? $screen->$option : $screen->get_option($option);

				return $key ? ($value ? wpjam_get($value, $key) : null) : $value;
			}
		}
	}

	function wpjam_admin($key='', ...$args){
		$object	= WPJAM_Admin::get_instance();

		if(!$key){
			return $object;
		}

		if(is_array($key)){
			return wpjam_map($key, 'wpjam_admin', 'kv');
		}

		if(method_exists($object, $key)){
			return $object->$key(...$args);
		}

		$value	= $object->get_arg($key);

		if(!$args){
			return $value ?? $object->get_arg('vars['.$key.']');
		}

		if(is_object($value) && !is_object($args[0])){
			return count($args) >= 2 ? ($value->{$args[0]} = $args[1]) : $value->{$args[0]};
		}

		$value	= $args[0];

		if($key == 'query_data'){
			return wpjam_map($value, fn($v, $k)=> is_array($v) ? wp_die('query_data 不能为数组') : wpjam_admin($key.'['.$k.']', (is_null($v) ? $v : sanitize_textarea_field($v))));
		}

		if(in_array($key, ['script', 'style'])){
			$key	.= '[]';
			$value	= implode("\n", (array)$value);
		}

		$object->process_arg($key, fn()=> $value);

		return $value;
	}

	function wpjam_chart($type='', ...$args){
		if(in_array($type, ['line', 'bar', 'donut'], true)){
			return ['WPJAM_Chart', $type](...$args);
		}

		$object	= wpjam_admin('chart', ...(is_array($type) ? [WPJAM_Chart::create($type)] : []));

		return $object && $type && !is_array($type) ? $object->$type(...$args) : $object;
	}

	function wpjam_add_admin_load($args){
		wp_is_numeric_array($args) ? array_walk($args, 'wpjam_add_admin_load') : $args && wpjam_admin('load', wpjam_pull($args, 'type'), $args);
	}

	function wpjam_register_page_action($name, $args){
		return WPJAM_Page_Action::create($name, $args);
	}

	function wpjam_get_page_action($name){
		return WPJAM_Page_Action::get($name);
	}

	function wpjam_get_page_button($name, $args=[]){
		return ($object = wpjam_get_page_action($name)) ? $object->get_button($args) : '';
	}

	function wpjam_register_list_table_action($name, $args){
		return WPJAM_List_Table_Action::register($name, $args);
	}

	function wpjam_unregister_list_table_action($name, $args=[]){
		return WPJAM_List_Table_Action::unregister($name, $args);
	}

	function wpjam_register_list_table_column($name, $field){
		return WPJAM_List_Table_Column::register($name, $field);
	}

	function wpjam_unregister_list_table_column($name, $field=[]){
		return WPJAM_List_Table_Column::unregister($name, $field);
	}

	function wpjam_register_list_table_view($name, $view=[]){
		return WPJAM_List_Table_View::register($name, $view);
	}

	function wpjam_register_dashboard_widget($name, $args){
		WPJAM_Dashboard::add_widget($name, $args);
	}

	function wpjam_render_callback($cb){
		return wpautop($is_array($cb) ? (is_object($cb[0]) ? get_class($cb[0]).'->' : $cb[0].'::').(string)$cb[1] : (is_object($cb) ? get_class($cb) : $cb));
	}
}

wpjam();

wpjam_route('json', 'WPJAM_JSON');
wpjam_route('txt', 'wpjam_txt');

wpjam_error('bad_authentication', '无权限');
wpjam_error('access_denied', '操作受限');
wpjam_error('undefined_method', fn($args)=> '「%s」'.(count($args) >= 2 ? '%s' : '').'未定义');

wpjam_pattern('key', '^[a-zA-Z][a-zA-Z0-9_\-]*$', '请输入英文字母、数字和 _ -，并以字母开头！');
wpjam_pattern('slug', '[a-z0-9_\\-]+', '请输入小写英文字母、数字和 _ -！');

wpjam_load_extends(dirname(__DIR__).'/components');

