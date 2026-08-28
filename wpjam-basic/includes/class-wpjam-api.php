<?php
class WPJAM_API{
	private $data	= [];

	public function __invoke($field, ...$args){
		if(!$field){
			return $this;
		}

		if(method_exists($this, $field)){
			return $this->$field(...$args);
		}

		if(try_suffix($field, '-', '[]')){
			$method	= $args ? (count($args) <= 2 && is_null(array_last($args)) ? 'delete' : 'add') : 'get';
		}else{
			$method	= $args && (count($args) > 1 || is_array($args[0])) ? 'set' : 'get';
		}

		return $this->$method($field, ...$args);
	}

	public function add($field, $key, ...$args){
		[$key, $item]	= $args ? [$key, $args[0]] : [null, $key];

		if(isset($key) && !str_ends_with($key, '[]') && $this->get($field, $key) !== null){
			return new WP_Error('invalid_key', '「'.$key.'」已存在，无法添加');
		}

		return $this->set($field, $key, $item);
	}

	public function set($field, $key, ...$args){
		$this->data[$field]	= is_array($key) ? array_merge(($args && $args[0]) ? $this->get($field) : [], $key) : wpjam_set($this->get($field), $key ?? '[]', ...$args);

		return is_array($key) ? $key : $args[0];
	}

	public function delete($field, ...$args){
		if($args){
			return $this->data[$field] = wpjam_except($this->get($field), $args[0]);
		}

		unset($this->data[$field]);
	}

	public function get($field, ...$args){
		return $args ? wpjam_get($this->get($field), ...$args) : ($this->data[$field] ?? []);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}

	public static function __callStatic($method, $args){
		$function	= 'wpjam_'.$method;

		if(function_exists($function)){
			return $function(...$args);
		}
	}
}

class WPJAM_Callback{
	private $reflections	= [];
	private $annotations	= [];

	public function __invoke($cb, ...$args){
		if(is_null($cb)){
			return $this;
		}

		if(is_string($cb)){
			if(in_array($cb, ['', 'try', 'catch', 'method'], true)){
				return $this->call($cb, ...$args);
			}

			if(method_exists($this, $cb)){
				return $this->$cb(...$args);
			}
		}

		return $this->parse($args ? 'try' : '', $cb, ...$args);
	}

	public function call($type, $cb, ...$args){
		$res	= $this->parse($type, $cb, $args);

		if(is_array($res)){
			[$cb, $args]	= $res;

			return $cb(...$args);
		}

		return $res;
	}

	public function parse($type, $cb, ...$args){
		if(is_string($cb) && preg_match('/::|->/', $cb, $m)){
			$sep	= $m[0];
			$static	= $sep == '::';
			$cb		= explode($sep, $cb, 2);
		}elseif(!is_array($cb)){
			if($type == 'method'){
				$cb	= [$cb, array_shift($args[0])];
			}
		}

		if(is_array($cb)){
			if(!$cb[0] || (is_string($cb[0]) && !class_exists($cb[0]))){
				return $this->invalid($type, $cb[0], 'model');
			}

			$exists	= $cb[1] && method_exists(...$cb);

			if($type == 'method' && !$exists){
				return;
			}
		}else{
			$exists	= $cb && is_callable($cb);
		}

		if(!$args){
			return $exists ? $cb : null;
		}

		$args	= $args[0];

		if(is_array($cb) && is_string($cb[0])){
			$sep	??= '::';
			$static	??= $exists ? $this->reflection($cb, 'isStatic') : ($cb[1] ? method_exists($cb[0], '__callStatic') : null);

			if(is_null($static) || (!$exists && !method_exists($cb[0], '__call'.($static ? 'Static' : '')))){
				return $this->invalid($type, implode($sep, $cb));
			}

			if(!$static){
				if($cb[1] == 'value_callback' && count($args) == 2){
					$args	= array_reverse($args);
				}

				$ins	= [$cb[0], 'get_instance'];
				$num	= $this->reflection($ins, 'NumberOfRequiredParameters');

				if(is_null($num) || count($args) < $num){
					return $this->invalid($type, implode($sep, $cb));
				}

				$cb[0]	= $ins(...array_splice($args, 0, $num ?: 1));

				if(!$cb[0]){
					return $this->invalid($type, $cb[0], 'id');
				}
			}

			$cb = ($public ?? true) ? $cb : $this->reflection($cb, 'Closure')($static ? null : $cb[0]);
		}else{
			if(!is_callable($cb)){
				return $this->invalid($type, $cb);
			}
		}

		return [$cb, $args];
	}

	private function invalid($type, $msg, $code='callback'){
		if(in_array($type, ['try', 'catch'])){
			$code	= 'invalid_'.$code;

			return $type == 'catch' ? new WP_Error($code, [$msg]) : wpjam_throw($code, [$msg]);
		}
	}

	public function value_callback($args, $name){
		$path	= (array)$name;
		$name	= array_shift($path);
		$id		= $args['id'] ?? null;
		$cb		= $args['value_callback'] ?? '';
		$mt 	= $id ? ($args['meta_type'] ?? '') : '';

		if(!$cb && ($model = $args['model'] ?? '')){
			if($id && $path && $name == 'meta_input' && ($mt = $mt ?: $this->call('method', $model.'::get_meta_type'))){
				$name	= array_shift($path);
			}else{
				$cb		= $this->parse('', [$model, 'value_callback']);
			}
		}

		foreach([$cb, $mt] as $i => $t){
			$value	= $t ? wpjam_if_error(($i === 0 ? $this->call('', $t, $name, $id) : wpjam_get_metadata($t, $id, $name)), null) : null;
			$value	= $path ? wpjam_get($value ?: [], $path) : $value;

			if(isset($value)){
				return $value;
			}
		}
	}

	public function render($cb){
		return wpautop(is_array($cb) ? (is_object($cb[0]) ? get_class($cb[0]).'->' : $cb[0].'::').(string)$cb[1] : (is_object($cb) ? get_class($cb) : $cb));
	}

	public function uniqid($cb){
		return _wp_filter_build_unique_id(null, $cb, null);
	}

	public function reflection($cb, ...$args){
		if($cb === 'class'){
			$type	= 'class';
			$class	= array_shift($args);
			$key	= class_exists($class) ? strtolower($class) : null;
		}else{
			$type	= 'cb';
			$cb		= $this->parse('try', is_array($cb) && !is_string($cb[0]) ? [get_class($cb[0]), $cb[1]] : $cb);
			$key	= $cb ? $this->uniqid($cb) : null;
		}

		if($key){
			$param	= $args ? array_shift($args) : '';
			$ref	= $this->reflections[$type][$key] ??= $type == 'class' ? new ReflectionClass($class) : (is_array($cb) ? new ReflectionMethod(...$cb) : new ReflectionFunction($cb));

			return $param ? [$ref, (array_find(['get', 'is', 'has', 'in'], fn($v)=> str_starts_with($param, $v)) ? '' : 'get').$param](...$args) : $ref;
		}
	}

	public function annotation($class, $key=''){
		$data	= $this->annotations[strtolower($class)] ??= (function($ref){
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

			$data['config'] = wpjam_array($data['config'] ?? [], fn($k, $v)=> is_numeric($k) ? (str_contains($v, '=') ? explode('=', $v, 2) : [$v, true]) : [$k, $v]);

			return $data;
		})($this->reflection('class', $class));

		return wpjam_get($data, $key ?: null);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_Query{
	private $type;

	private function __construct($type){
		$this->type	= $type;
	}

	public function is($query, ...$args){
		if($query->is_main_query()){
			if($args){
				return array_any(wp_parse_list(array_shift($args)), fn($t)=> [$query, 'is_'.$t](...$args));
			}

			return $query->is_front_page() ? 'home' : (array_find(['feed', 'author', 'category', 'tag', 'tax', 'post_type_archive', 'search', 'date', 'archive', '404', 'page', 'single', 'attachment'], fn($t)=> [$query, 'is_'.$t]()) ?: true);
		}
	}

	public function query($vars, &$args=[]){
		if($this->type == 'term'){
			$number	= (int)wpjam_pull($vars, 'number');
			$paged	= wpjam_pull($vars, 'paged') ?: 1;
			$args	+= wpjam_pull($vars, ['format', 'parse']);
			$terms	= $this->parse($vars, $args);

			return $terms && $number ? array_slice($terms, $number * ($paged-1), $number) : $terms;
		}

		if(!empty($vars['related_query'])){
			$post	= get_post(wpjam_pull($vars, 'post') ?? get_the_ID());
			$type	= get_post_type($post);
			$tt_ids	= [];

			foreach($post ? get_object_taxonomies($type) : [] as $tax){
				if($terms = $tax == 'post_format' ? [] : get_the_terms($post, $tax)){
					$type	= array_merge((array)$type, get_taxonomy($tax)->object_type);
					$tt_ids	= array_merge($tt_ids, array_column($terms, 'term_taxonomy_id'));
				}
			}

			if(!$tt_ids){
				return false;
			}

			$vars	+= ['post_status'=>'publish', 'post__not_in'=>[$post->ID], 'post_type'=>array_unique($type), 'orderby'=>'related', 'term_taxonomy_ids'=>wpjam_filter($tt_ids, 'unique')];
		}

		$vars	= $this->parse_vars($vars);

		if($args){
			$vars	= array_filter(['posts_per_page'=>wpjam_pull($args, 'number')])+$vars;
			$vars	= wpjam_pull($args, ['post_type', 'orderby', 'posts_per_page'])+$vars;
			$vars	= ($days = wpjam_pull($args, 'days')) ? wpjam_set($vars, 'date_query[]', [
				'column'	=> wpjam_pull($args, 'column') ?: 'post_date_gmt',
				'after'		=> wpjam_date('Y-m-d', time()-DAY_IN_SECONDS*$days).' 00:00:00'
			]) : $vars;
		}

		return new WP_Query($vars+['no_found_rows'=>true, 'ignore_sticky_posts'=>true]);
	}

	public function parse($vars, $args=[]){
		$format	= wpjam_pull($args, 'format');
		$parse	= wpjam_pull($args, 'parse');

		if(is_null($parse)){
			$parse	= true;
			$args	+= $this->type == 'post' ? ['options_required'=>false, 'taxonomy_required'=>false] : [];
		}

		if($this->type == 'term'){
			$object	= ($tax = $vars['taxonomy'] ?? '') && is_string($tax) ? wpjam_get_taxonomy($tax) : null;
			$terms	= $vars['terms'] ?? null;
			$depth	= $args['depth'] ?? ($object ? $object->max_depth : null);

			if($depth != -1 && $object && $object->hierarchical){
				$nest	= ['max_depth'=>($depth ?? (int)$object->levels), 'format'=>$format];
				$nest	+= $parse ? ['item_callback'=>'wpjam_get_term'] : ['fields'=>['id'=>'term_id']];

				if(($parent = (int)wpjam_pull($vars, 'parent')) && !($nest['top'] = get_term($parent))){
					return [];
				}

				if($terms){
					$ids	= array_column($terms, 'term_id');
					$terms	= WPJAM_Term::get_by_ids(array_unique(array_reduce($ids, fn($c, $id)=> array_merge($c, get_ancestors($id, $tax, 'taxonomy')), $ids)));
				}
			}

			return wpjam_then(
				($terms ?? get_terms($vars+['hide_empty'=>false])) ?: [],
				fn($v)=> isset($nest) ? wpjam_nest($v, $nest) : ($parse ? array_values(array_map('wpjam_get_term', $v)) : $v)
			);
		}

		$query	= is_object($vars) ? $vars : $this->query($vars, $args);

		if(!$query || !$parse){
			return $query ? $query->posts : [];
		}

		$parsed	= [];
		$args	+= ['thumbnail_size'=>wpjam_pull($args, 'size')];
		$args	+= $query->get('related_query') ? ['filter'=>'wpjam_related_post_json'] : [];

		while($query->have_posts()){
			$query->the_post();

			if($item = wpjam_get_post(get_the_ID(), $args+['query'=>$query])){
				$parsed	= wpjam_set($parsed, ($format == 'date' ? wpjam_at($item['date'], ' ', 0) : '').'[]', $item);
			}
		}

		if(!$query->is_main_query()){
			wp_reset_postdata();
		}

		return $parsed;
	}

	public function parse_vars($vars, $param=false){
		if(($type = $vars['post_type'] ?? '') && is_string($type) && str_contains($type, ',')){
			$vars['post_type'] = wp_parse_list($type);
		}

		if($param === 'calendar'){
			$args	= ['year'=>['year', 'Y'], 'monthnum'=>['month', 'm'], 'day'=>['day', '']];
			$vars	+= wpjam_map($args, fn($v)=> (int)wpjam_param($v[0]) ?: ($v[1] ? wpjam_date($v[1]) : null));
		}elseif($param){
			$number	= wpjam_find(['number', 'posts_per_page'], fn($v)=> $v, fn($k)=> ($v = (int)wpjam_param($k)) > 0 ? $v : 0);
			$ids	= wpjam_param('post__in');
			$vars	+= array_filter(['offset'=>wpjam_param('offset'), 'posts_per_page'=>min($number, 100)]);
			$vars	+= $ids ? ['include'=>$ids,	'orderby'=>'post__in'] : [];
		}

		foreach(get_taxonomies([], 'objects') as $tax => $object){
			if(in_array($tax, ['category', 'post_tag']) || !$object->_builtin){
				$qk		= wpjam_get_taxonomy_query_key($tax);
				$keys	= $tax == 'category' ? ['category_id', 'cat_id'] : [$qk];

				if($value = ($param ? array_reduce($keys, fn($c, $k)=> (int)wpjam_param($k) ?: $c, 0) : 0) ?: wpjam_pull($vars, $qk)){
					$term_ids[$tax]	= $value;
				}
			}
		}

		if(!empty($vars['taxonomy']) && empty($vars['term']) && ($value = wpjam_pull($vars, 'term_id'))){
			if(is_numeric($value)){
				$term_ids[wpjam_pull($vars, 'taxonomy')]	= $value;
			}else{
				$vars['term']	= $value;
			}
		}

		foreach(array_filter($term_ids ?? []) as $tax => $value){
			if($tax == 'category' && $value != 'none'){
				$vars['cat']	= $value;
			}else{
				$vars['tax_query'][]	= ['taxonomy'=>$tax, 'field'=>'term_id']+($value == 'none' ? ['operator'=>'NOT EXISTS'] : ['terms'=>[$value]]);
			}
		}

		foreach(wpjam_pull($vars, ['include', 'exclude']) as $k => $v){
			if($ids = wp_parse_id_list($v)){
				$vars[$k == 'include' ? 'post__in' : 'post__not_in']	= $ids;

				$k == 'include' && ($vars['posts_per_page']	= count($ids));
			}
		}

		foreach(['cursor'=>'before', 'since'=>'after'] as $k => $v){
			if($value = (int)(($param ? wpjam_param($k) : 0) ?: wpjam_pull($vars, $k))){
				$vars['date_query'][]	= [$v => wpjam_date('Y-m-d H:i:s', $value)];

				$vars['ignore_sticky_posts']	= true;
			}
		}

		return $vars;
	}

	public function render($vars, $args=[]){
		$cb		= wpjam_fill(['item_callback', 'wrap_callback'], fn($k)=> $args[$k] ?? [$this, $k]);
		$query	= is_object($vars) ? $vars : $this->query($vars, $args);
		$args	+= ['query'=>$query];

		return $query ? $cb['wrap_callback'](implode(wpjam_map($query->posts, fn($p, $i)=> $cb['item_callback']($p->ID, $args+['i'=>$i]))), $args) : '';
	}

	public function item_callback($post_id, $args){
		$args	+= ['title_number'=>'', 'excerpt'=>false, 'thumb'=>true, 'size'=>'thumbnail', 'thumb_class'=>'wp-post-image', 'wrap_tag'=>'li'];
		$title	= get_the_title($post_id);
		$item	= wpjam_wrap($title);

		$args['title_number'] && $item->before('span', ['title-number'], zeroise($args['i']+1, strlen(count($args['query']->posts))).'. ');
		($args['thumb'] || $args['excerpt']) && $item->wrap('h4');
		$args['thumb'] && $item->before(get_the_post_thumbnail($post_id, $args['size'], ['class'=>$args['thumb_class']]));
		$args['excerpt'] && $item->after(wpautop(get_the_excerpt($post_id)));

		return $item->wrap('a', ['href'=>get_permalink($post_id), 'title'=>strip_tags($title)])->wrap($args['wrap_tag'])->render();
	}

	public function wrap_callback($output, $args){
		if(!$output){
			return '';
		}

		$args	+= ['title'=>'', 'div_id'=>'', 'class'=>[], 'thumb'=>true, 'wrap_tag'=>'ul'];
		$output	= wpjam_wrap($output);

		$args['wrap_tag']	&& $output->wrap($args['wrap_tag'])->add_class($args['class'])->add_class($args['thumb'] ? 'has-thumb' : '');
		$args['title']		&& $output->before($args['title'], 'h3');
		$args['div_id']		&& $output->wrap('div', ['id'=>$args['div_id']]);

		return $output->render();
	}

	public static function get_instance($type){
		static $objects	= [];
		return $objects[$type] ??= new self($type);
	}
}

class WPJAM_Extend{
	private $dir;
	private $option;

	public function __construct($dir, $args){
		$this->dir		= $dir;
		$this->option	= ($args['option'] ?? '') ? wpjam_register_option($args['option'], $args+[
			'ajax'				=> false,
			'site_default'		=> is_multisite() && ($args['sitewide'] ?? false),
			'fields'			=> [$this, 'get_fields'],
			'sanitize_callback'	=> [$this, 'sanitize']
		]) : null;
	}

	public function __call($method, $args){
		$dir	= $this->dir;
		$option	= $this->option;
		$active	= get_option('active_plugins') ?: [];
		$data	= $option && $method == 'load'
		? array_merge(array_filter($option->get_option()), $option->site_default ? array_filter($option->get_site_option()) : [])
		: ($method == 'sanitize' ? $args[0] : array_filter(scandir($dir), fn($v)=> !in_array($v, ['.', '..', 'extends.php'])));

		foreach($data as $k => $v){
			$n	= is_numeric($k) ? $v : $k;
			$f	= str_ends_with($n, '.php') ? $n : '';
			$n	= $f ? substr($n, 0, -4) : $n;
			$e	= $f ?: $n.(is_dir($dir.'/'.$n) ? '/'.$n : '').'.php';
			$f	= $n ? $dir.'/'.$e : '';
			$d	= is_file($f) ? wpjam_get_file_data($f) : '';

			if($method == 'load'){
				if($d && (is_admin() || empty($d['Admin'])) && !in_array($e, $active)){
					include_once $f;
				}
			}elseif($method == 'get_fields'){
				$v	= $d && $d['Name'] && (!$option->site_default || is_network_admin() || !$option->get_site_setting($n)) ? [
					'value'	=> [$option, 'get_'.(is_network_admin() ? 'site_': '').'setting']($n),
					'title'	=> wpjam_wrap($d['Name'], $d['URI'] ? 'a' : '', ['href'=>$d['URI'], 'target'=>"_blank"]),
					'label'	=> $d['Description']
				] : '';
			}

			if($d && $v){
				$result[$n]	= $v;
			}
		}

		return $result ?? [];
	}
}

class WPJAM_Param{
	private $data	= [];

	public function __invoke($type, ...$args){
		$name	= $args && is_scalar($args[0]) ? array_shift($args) : null;
		$args	= $args[0] ?? [];
		$value	= $this->get_by($type, $name);

		if(isset($name)){
			$value	??= $args['default'] ?? wpjam_default($name);

			if($name && ($field = wpjam_except($args, ['default', 'send']))){
				$field	= wpjam_field(['key'=>$name]+(($field['type'] ?? '') ? [] : ['_schema'=>[]])+$field);
				$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

				($args['send'] ?? true) && wpjam_if_error($value, 'send');
			}
		}else{
			$fields	= $args && is_array($args) ? wpjam_fields($args) : $args;
			$value	= $fields ? array_merge($value, $fields->validate($value, 'parameter')) : $value;
		}

		return $value;
	}

	private function get_by($type, $name=''){
		$type	= strtolower($type ?: 'get');

		if($type == 'data'){
			if($name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}
		}elseif($type != 'defaults'){
			$data	= ['post'=>$_POST, 'request'=>$_REQUEST][$type] ?? $_GET;

			if($name){
				if(isset($data[$name])){
					return wp_unslash($data[$name]);
				}

				if($_POST || $type == 'get'){
					return null;
				}
			}else{
				if($data || $type != 'post'){
					return wp_unslash($data);
				}
			}

			$type	= 'input';
		}

		$data	= $this->data[$type] ??= $this->get_data($type);

		return $name ? wpjam_get($data, $name) : $data;
	}

	private function get_data($type){
		if($type == 'input'){
			$v		= file_get_contents('php://input');
			$v		= $v && is_string($v) ? @wpjam_json_decode($v) : $v;
			$data	= is_array($v) ? $v : [];
		}else{
			foreach(array_unique(['defaults', $type]) as $t){
				$v		= $this->get_by('request', $t) ?? [];
				$v		= $v && is_string($v) && str_starts_with($v, '{') ? wpjam_json_decode($v) : wp_parse_args($v ?: []);
				$data	= wpjam_merge($data ?? [], $v);
			}
		}

		return $data;
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		$throw	= wpjam_pull($args, 'throw');
		$field	= wpjam_pull($args, 'field');

		try{
			$result = wpjam_try('wp_safe_remote_request', $url, self::prepare($args));
			$result	= self::parse($result, $args, $err);

			return $field ? wpjam_get($result, $field) : $result;
		}catch(Exception $e){
			$error	= wpjam_fill(['code', 'message', 'data'], fn($k)=> [$e, 'get_error_'.$k]());

			if(apply_filters('wpjam_http_response_error_debug', true, $error['code'], $error['message'])){
				trigger_error(var_export(array_filter(['url'=>$url, 'error'=>array_filter($error), 'body'=>$args['body'] ?? '']), true));
			}

			if($throw){
				throw $e;
			}

			return wpjam_catch($e);
		}
	}

	private static function prepare($args){
		$args	+= ['body'=>[], 'sslverify'=>false];
		$method	= strtoupper($args['method'] ?? '') ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'FILE'){
			wpjam_once('pre_http_request', fn($pre, $args, $url)=> (new WP_Http_Curl())->request($url, $args), 1, 3);

			$method = $args['body'] ? 'POST' : 'GET';
		}elseif($method != 'GET'){
			$key	= 'content-type';
			$type	= 'application/json';

			$args['headers']	= array_change_key_case($args['headers'] ?? []);

			if(array_first(wpjam_pull($args, ['json_encode', 'need_json_encode']))){
				$args['headers'][$key]	= $type;
			}

			if(str_contains($args['headers'][$key] ?? '', $type) && is_array($args['body'])){
				$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
			}
		}

		return ['method'=>$method]+$args;
	}

	private static function parse($result, $args, $err){
		$code	= $result['response']['code'];
		$body	= &$result['body'];

		if(!wpjam_compare($code, 'between', [200, 299])){
			wpjam_throw($code, $code.' - '.$result['response']['message'].'-'.var_export($body, true));
		}

		if($body && empty($args['stream'])){
			if(str_contains(wp_remote_retrieve_header($result, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}elseif(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
				$body	= wpjam_then(wpjam_json_decode($body), fn($res)=> self::error($res, $err+['errmsg'=>'errmsg']), fn()=> $body);
			}
		}

		return $result;
	}

	private static function error($res, $err){
		if(($code = wpjam_pull($res, $err['errcode'] ?? 'errcode')) && $code != ($err['success'] ?? '0')){
			wpjam_throw($code, wpjam_pull($res, $err['errmsg']), wpjam_pull($res, $err['detail'] ?? 'detail') ?? array_filter($res));
		}

		if(strtolower($res[$err['errmsg']] ?? '') == 'ok'){
			unset($res[$err['errmsg']]);
		}

		return $res;
	}
}

class WPJAM_Error{
	public static function parse($code='', $msg=''){
		if(is_wp_error($code)){
			$err	= $code;
			$code	= $err->get_error_code();
			$msg	= $err->get_error_message();
			$data	= ['errcode'=>$code, 'errmsg'=>$msg]+array_filter(is_array($data = $err->get_error_data()) ? $data : ['errdata'=>$data]);
		}elseif(is_array($code)){
			$data	= $code;
			$code	= $data['errcode'] ??= 0;
			$msg	= $data['errmsg'] ?? [];
		}

		if(isset($data)){
			return self::can($code, $msg) ? array_merge($data, self::parse($code, $msg)) : $data;
		}

		$args	= $msg ?: [];
		$err	= $code;

		if($item = self::get($code)){
			$msg	= maybe_closure($item['errmsg'], $args);
		}elseif(try_prefix($err, '-', 'invalid_')){
			$err	= $err == 'id' ? 'ID' : str_replace(['_id', '_'], [' ID', ' '], $err);
			$msg	= 'Invalid '.$err.($args ? ': %s.' : '.');

			if(did_action('init')){
				if(($trans = __($msg, 'wpjam')) != $msg){
					$msg	= $trans;
				}else{
					$msg	= __('Invalid %s'.($args ? ': %s.': '.'), 'wpjam');
					$args	= [__($err, 'wpjam'), ...$args];
				}
			}
		}elseif(try_suffix($err, '-', '_required')){
			$msg	= __('%s is empty or invalid.', 'wpjam');
			$trans	= __($args && $err == 'value' ? '%s\'s value' :  $err, 'wpjam');
			$args	= [$args ? sprintf($trans.($err == 'value' ? '' : '%s'), ...$args) : $trans];
		}elseif(try_suffix($err, '-', '_occupied')){
			$msg	= __('%s is already in use by another account.', 'wpjam');
			$args	= [__($err, 'wpjam')];
		}else{
			$msg	= str_replace('_', ' ', $err);
		}

		if(!$msg){
			return [];
		}

		return ['errcode'=>$code, 'errmsg'=>(($args && str_contains($msg, '%')) ? sprintf($msg, ...$args) : $msg)]+$item;
	}

	public static function can($code, $msg=''){
		return $code && (!$msg || is_array($msg));
	}

	public static function callback($code, $msg, $data, $error){
		if(self::can($code, $msg) && count($error->get_error_messages($code)) <= 1 && ($item = self::parse($code, $msg))){
			$error->remove($code);
			$error->add($code, $item['errmsg'], empty($item['modal']) ? $data : array_merge((is_array($data) ? $data : []), ['modal'=>$item['modal']]));
		}
	}

	public static function __callStatic($method, $args){
		static $object;
		$object ??= wpjam_hook('wp_error_added', ['callback'=>[self::class, 'callback']], 10, 4);
		$key	= 'errors['.(array_shift($args) ?: '').']';

		return $method == 'get' ? ($object->get_arg($key) ?: []) : $object->update_arg($key, ['errmsg'=>$args[0], 'modal'=>$args[1] ?? []]);
	}
}

class WPJAM_Hook extends WPJAM_Args{
	public function __invoke(...$args){
		if($this->check && !($this->check)(...$args)){	// del 2026-08-30
			trigger_error(var_export($this->check, true));
			return $args[0];
		}

		$value	= $args[0] ?? null;
		$hook	= $this->hook = current_filter();
		$args	= ($this->op || $this->maybe || $this->tap) ? wpjam_rotate($args, 1) : $args;
		$result	= $this->bind ? $this->call('callback', ...$args) : wpjam_call($this->callback, ...$args);
		$result	= $this->op ? wpjam_operate($value, $this->op, $result) : $result;

		if($this->once && !($this->maybe && (did_action($hook) ? $result === false : is_null($result)))){
			if($filter = $GLOBALS['wp_filter'][$hook] ?? null){
				unset($filter->callbacks[$filter->current_priority()][wpjam_callback('uniqid', $this)]);
			}
		}

		return $this->echo ? wpjam_echo($result) : ($this->tap ? $value : ($this->maybe ? ($result ?? $value) : $result));
	}

	public static function batch($action, $name, ...$args){
		if(is_array($name) || str_contains($name ?: '', ',')){
			return wpjam_call(
				$action === 'remove' ? 'wpjam_filter' : 'wpjam_map',
				array_filter(is_array($name) ? $name : wp_parse_list($name)),
				fn($n)=> self::batch($action, ...(is_array($n) ? $n : [$n, ...$args]))
			);
		}

		if($name && $args){
			$cb		= array_shift($args);
			$multi	= wp_is_numeric_array($cb) && !is_callable($cb);
			$result	= wpjam_map($multi ? $cb : [$cb], fn($cb)=> self::$action($name, $cb, ...$args));

			return $multi ? $result : $result[0];
		}
	}

	public static function add($name, $cb, ...$args){
		if(wpjam_is_assoc_array($cb)){
			$cb	= new static($cb);
		}else{
			if($attr = $name === 'once' ? [$name=>true] : []){
				$name	= $cb;
				$cb		= array_shift($args);
			}

			if($type = in_array($cb, ['+', '-', '.'], true) ? 'op' : (in_array($cb, ['tap', 'maybe', 'echo', 'bind'], true) ? $cb : '')){
				$attr	+= [$type=>$cb];
				$cb		= array_shift($args);
			}

			$cb	= is_callable($cb) ? $cb : fn()=> $cb;
			$cb	= $attr ? new static($attr+['callback'=>$cb]) : $cb;
		}

		add_filter($name, $cb, ...$args);

		return $cb;
	}

	public static function remove(...$args){
		return count($args) > 1 && ($args[2] ??= has_filter(...$args)) !== false && remove_filter(...$args);
	}
}

class WPJAM_AJAX extends WPJAM_Args{
	public function callback(){
		add_filter('wp_die_ajax_handler', fn()=> 'wpjam_die_handler');

		$data	= wpjam_params('post');

		if(!$this->admin){
			$data	= array_merge(wpjam_params('data'), wpjam_except($data, ['action', 'defaults', 'data', '_ajax_nonce']));
		}

		if($fields = $this->fields){
			$data	= array_merge($data, wpjam_fields($fields)->validate($data, 'parameter'));
		}

		if(($this->verify ?? true) && ($action = $this->nonce_action($data)) && !check_ajax_referer($action, false, false)){
			wp_die('验证失败，请刷新重试。', 'invalid_nonce');
		}

		if(($allow = $this->allow) && !wpjam_call($allow, $data)){
			wp_die('access_denied');
		}

		return wpjam_send_json(wpjam_catch($this->callback, $data, $this->name));
	}

	public function nonce_action($args){
		$cb	= $this->nonce_action;

		return $cb ? $cb($args) : ($this->admin ? '' : $this->name.wpjam_join(':', wpjam_pick($args, $this->nonce_keys ?: [])));
	}

	public function registered(){
		if(wp_doing_ajax() && wpjam_param('action', 'request') == $this->name){
			$part	= is_user_logged_in() ? '' : ($this->nopriv ? 'nopriv_' : false);

			$part !== false && add_action('wp_ajax_'.$part.$this->name, [$this, 'callback']);
		}
	}

	public static function register($name, $args=[]){
		return wpjam_register(static::class, $name, $args);
	}

	public static function get($name){
		return wpjam_get_registered(static::class, $name);
	}

	public static function add_hooks(){
		wpjam_script('wpjam-ajax', [
			'for'		=> 'wp,login',
			'src'		=> wpjam_url(dirname(__DIR__).'/static/ajax.js'),
			'deps'		=> ['jquery'],
			'data'		=> 'var ajaxurl	= "'.admin_url('admin-ajax.php').'";',
			'position'	=> 'before',
			'priority'	=> 1
		]);
	}
}

class WPJAM_File extends WPJAM_Args{
	public function __invoke($value, $to, $from=null){
		$from	??= is_numeric($value) ? 'id' : 'file';

		return $from == $to	? $value : ($from == 'id' ? $this->from_id($value, $to) : $this->convert($value, $to, $from));
	}

	protected function convert($value, $to, $from){
		if(in_array($to, ['size', 'ext'])){
			$file	= $from == 'file' ? $value : $this->convert($value, 'file', $from);

			if($to == 'ext'){
				return pathinfo($file, PATHINFO_EXTENSION);
			}

			return file_exists($file) && ($size = wp_getimagesize($file))
			? ['width'=>$size[0], 'height'=>$size[1]]
			: $this->from_id($this->convert($file, 'id', 'file'), 'size');
		}

		if($path = $this->to_path($value, $from)){
			return $to == 'id' ? wpjam_get_by_meta('post', '_wp_attached_file', ltrim($path, '/'), 'object_id') : (['url'=>$this->baseurl,'file'=>$this->basedir][$to] ?? '').$path;
		}
	}

	protected function to_path($value, $from='file'){
		if($from == 'file'){
			$base	= $this->basedir;
		}elseif($from == 'url'){
			$value	= parse_url($value, PHP_URL_PATH);
			$base	= parse_url($this->baseurl, PHP_URL_PATH);
		}else{
			return $value;
		}

		return try_prefix($value, '-', $base) ? $value : null;
	}

	protected function from_id($id, $to='file'){
		if($to == 'url'){
			return wp_get_attachment_url($id);
		}

		if($id && get_post_type($id) == 'attachment'){
			if(in_array($to, ['meta', 'size'])){
				$meta	= wp_get_attachment_metadata($id);
				$meta	= is_array($meta) ? $meta : [];

				return $to == 'meta' ? $meta : wpjam_pick($meta, ['width', 'height']);
			}

			$file	= get_attached_file($id, true);

			return $to == 'file' ? $file : $this->convert($file, $to, 'file');
		}
	}

	public function restore($id, $url=''){
		if(is_dir(dirname($file))){
			mkdir(dirname($file), 0777, true);
		}

		wpjam_remote_request($url ?: $this->from_id($id, 'url'), ['throw'=>true, 'stream'=>true, 'filename'=>$file]);

		return $file;
	}

	public function attach($file, $args=[]){
		require_once ABSPATH.'wp-admin/includes/image.php';

		$meta	= wp_read_image_metadata($file);
		$title	= $meta ? trim($meta['title']) : '';
		$title	= ($title && !is_numeric(sanitize_title($title))) ? $title : preg_replace('/\.[^.]+$/', '', wp_basename($file));
		$pid	= $args['post_id'] ?? 0;
		$id		= wpjam_try('wp_insert_attachment', [
			'post_title'		=> $title,
			'post_content'		=> $meta ? (trim($meta['caption']) ?: '') : '',
			'post_parent'		=> $pid,
			'post_mime_type'	=> $args['type'] ?? mime_content_type($file),
			'guid'				=> $args['url'] ?? $this->convert($file, 'url', 'file'),
		], $file, $pid, true);

		wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));

		return $id;
	}

	public function mimes($accept){
		$allowed	= get_allowed_mime_types();
		$types		= [];

		foreach(wpjam_lines($accept ?: '', ',', fn($v)=> strtolower($v)) as $v){
			if(str_ends_with($v, '/*')){
				$prefix	= substr($v, 0, -1);
				$types	+= array_filter($allowed, fn($m)=> str_starts_with($m, $prefix));
			}elseif(str_contains($v, '/')){
				$ext	= array_search($v, $allowed);
				$types	+= $ext ? [$ext => $v] : [];
			}elseif(($v = ltrim($v, '.')) && preg_match('/^[a-z0-9]+$/', $v)){
				$ext	= array_find_key($allowed, fn($m, $ext)=> str_contains($ext, '|') ? in_array($v, explode('|', $ext)) : $v == $ext);
				$types	+= $ext ? wpjam_pick($allowed, [$ext]) : [];
			}
		}

		return $types;
	}

	public function upload($name, $args=[]){
		require_once ABSPATH.'wp-admin/includes/file.php';

		if(!empty($args['accept'])){
			$args['mimes']	= $this->mimes($args['accept']) ?: wpjam_throw('upload_error', '无效的文件类型');
		}

		if($bits = wpjam_pull($args, 'bits')){
			$mime	= preg_match('/data:([^;]+);base64,/i', $bits, $m) ? $m[1] : wpjam_throw('upload_error', '无效的 data URI 格式');
			$ext	= (empty($args['mimes']) || in_array($mime, $args['mimes'])) ? array_search($mime, get_allowed_mime_types()) : '';
			$bits	= $ext ? base64_decode(trim(substr($bits, strlen($m[0])))) : wpjam_throw('upload_error', '不允许的文件类型');
			$upload	= wp_upload_bits(explode_last('.', $name)[0].'.'.(explode('|', $ext)[0]), null, $bits);
		}else{
			if(is_array($name)){
				$action	= 'sideload';
			}else{
				$action	= 'upload';
				$name	= $_FILES[$name] ?? wpjam_throw('invalid_parameter', [$name]);
			}

			if(wpjam_pull($args, 'media')){
				require_once ABSPATH.'wp-admin/includes/media.php';
				require_once ABSPATH.'wp-admin/includes/image.php';

				return wpjam_try('media_handle_'.$action, $name, ($args['post_id'] ?? 0));
			}

			$upload	= wpjam_call('wp_handle_'.$action, $name, $args+['test_form'=>false]);
		}

		return empty($upload['error']) ? $upload+['path'=>$this->to_path($upload['file'])] : wpjam_throw('upload_error', $upload['error']);
	}

	public function download($url, $args=[]){
		$media	= $args['media'] ?? false;
		$field	= ($args['field'] ?? '') ?: ($media ? 'id' : 'file');
		$result	= wpjam_get_by_meta('post', 'source_url', $url, 'object_id');

		if(!$result || get_post_type($result) != 'attachment'){
			try{
				$tmp	= wpjam_try('download_url', $url);
				$upload	= ['name'=>($args['name'] ?? '') ?: md5($url).'.'.wpjam_at(wp_get_image_mime($tmp), '/', 1), 'tmp_name'=>$tmp];
				$result	= $this->upload($upload, wpjam_pick($args, ['media', 'post_id']));

				if(!$media){
					return $result[$field];
				}

				update_post_meta($result, 'source_url', $url);
			}catch(Exception $e){
				$tmp && @unlink($tmp);

				return wpjam_catch($e);
			}
		}

		return $this->from_id($result, $field);
	}

	public function parse($file, $type='data'){
		$data	= $file ? array_reduce(['URI', 'Name'], fn($c, $k)=> wpjam_set($c, $k, ($c[$k] ?? '') ?: ($c['Plugin'.$k] ?? '')), get_file_data($file, [
			'Admin'			=> 'Admin',
			'Name'			=> 'Name',
			'URI'			=> 'URI',
			'PluginName'	=> 'Plugin Name',
			'PluginURI'		=> 'Plugin URI',
			'Version'		=> 'Version',
			'Description'	=> 'Description'
		])) : [];

		return $type == 'summary' ? ($data ? str_replace('。', '，', $data['Description']).'详细介绍请点击：<a href="'.$data['URI'].'" target="_blank">'.$data['Name'].'</a>。' : '') : $data;
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self(wp_get_upload_dir());
	}
}

class WPJAM_Qrcode extends WPJAM_Args{
	public function cache($action, ...$args){
		return [$this->handler, 'cache_'.$action](...$args);
	}

	public function verify($scene, $code){
		$qrcode	= $this->cache('get', $scene.'_scene');

		if(!$qrcode || !$code || empty($qrcode['openid']) || $code != $qrcode['code']){
			return new WP_Error('invalid_'.($qrcode ? 'code' : 'qrcode'));
		}

		$this->cache('delete', $scene.'_scene');

		return $qrcode;
	}

	public function scan($scene, $openid){
		$qrcode	= $this->cache('get', $scene.'_scene');

		if(!$qrcode || (!empty($qrcode['openid']) && $qrcode['openid'] != $openid)){
			return new WP_Error('invalid_qrcode');
		}

		$this->cache('delete', $qrcode['key'].'_qrcode');

		if(!empty($qrcode['id']) && ($cb = $qrcode['bind_callback'] ?? '') && is_callable($cb)){
			return $cb($openid, $qrcode['id']);
		}

		$this->cache('set', $scene.'_scene', ['openid'=>$openid]+$qrcode, 1200);

		return $qrcode['code'];
	}

	public function create($key, $args=[]){
		return $this->cache('get', $key.'_qrcode') ?: wpjam_tap(wpjam_call($this->creator, $key, $args), fn($v)=> wpjam_map([
			$key.'_qrcode',
			$v['scene'].'_scene'
		], fn($k)=> $this->cache('set', $k, $v, 1200)));
	}

	public function get_fields($action='login'){
		if($action == 'bind'){
			$args	= ['id'=>get_current_user_id()];	
			$args	= [md5('bind_'.$args['id']), $args];
		}else{
			$args	= [wp_generate_password(32, false, false)];
		}

		return wpjam_then($this->create(...$args), fn($v)=> [
			'qrcode'	=> ['type'=>'view',		'title'=>$this->title,	'value'=>'<img src="'.(($v['qrcode_url'] ?? '') ?: ($v[ 'qrcode'] ?? '')).'" width="272" />'],
			'code'		=> ['type'=>'number',	'title'=>'验证码',	'class'=>'input',	'required', 'size'=>20],
			'scene'		=> ['type'=>'hidden',	'value'=>$v['scene']]
		]);
	}
}

class WPJAM_Widget extends WP_Widget{
	public function __construct($id, $name, $options){
		parent::__construct($id, $name, $options+['show_instance_in_rest'=>false]);
	}

	public function widget($args, $instance){
		$widget	= $this->widget_options['widget'];

		if($output = $widget($instance)){
			$title	= wpjam_pull($instance, 'title');
			$output	= ($title ? $args['before_title'].$title.$args['after_title'] : '').$output;

			echo $args['before_widget'].$output.$args['after_widget'];
		}
	}

	public function form($instance){
		echo str_replace('fieldset', 'span', wpjam_fields(wpjam_map(maybe_callback($this->widget_options['fields']), fn($v, $k)=> $v+[
			'id'	=> $this->get_field_id($k),
			'name'	=> $this->get_field_name($k),
			'value'	=> $instance[$k] ?? null
		]))->render(['wrap_tag'=>'p']));
	}
}

class WPJAM_Exception extends Exception{
	private $error;

	public function __construct($msg, $code=null, ?Throwable $previous=null){
		$error	= $this->error = is_wp_error($msg) ? $msg : new WP_Error($code ?: 'error', $msg);
		$code	= $error->get_error_code();

		parent::__construct($error->get_error_message(), (is_numeric($code) ? (int)$code : 1), $previous);
	}

	public function __call($method, $args){
		return in_array($method, ['get_wp_error', 'get_error']) ? $this->error : [$this->error, $method](...$args);
	}
}