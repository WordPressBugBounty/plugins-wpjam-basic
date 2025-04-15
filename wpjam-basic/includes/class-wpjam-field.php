<?php
class WPJAM_Attr extends WPJAM_Args{
	public function __toString(){
		return (string)$this->render();
	}

	public function jsonSerialize(){
		return $this->render();
	}

	public function attr($key, ...$args){
		if(!is_array($key)){
			if(!$args){
				return $this->$key;
			}

			$this->$key	= is_closure($args[0]) ? $this->bind_if_closure($args[0])($this->$key) : $args[0];
		}else{
			array_walk($key, fn($v, $k)=> $this->$k = $v);
		}

		return $this;
	}

	public function remove_attr($key){
		return $this->delete_arg($key);
	}

	public function val(...$args){
		return $this->attr('value', ...$args);
	}

	public function data(...$args){
		$data	= wpjam_array($this->data);

		if(!$args){
			return array_merge($data, wpjam_array($this->get_args(), fn($k)=> str_starts_with($k, 'data-') ? substr($k, 5) : null));
		}

		if(is_array($args[0])){
			return $this->attr(wpjam_array($args[0], fn($k)=> 'data-'.$k));
		}

		$k	= array_shift($args);

		if($args){
			return $this->attr('data-'.$k, ...$args);
		}

		return $this->attr('data-'.$k) ?? ($data[$k] ?? null);
	}

	protected function class($action='', ...$args){
		$value	= array_filter(wp_parse_list($this->class ?: []));
		$cb		= $action ? ['add'=>'array_merge', 'remove'=>'array_diff', 'toggle'=>'wpjam_toggle'][$action] : '';

		return $cb ? $this->attr('class', $cb($value, wp_parse_list($args[0] ?: []))) : $value;
	}

	public function has_class($name){
		return in_array($name, $this->class());
	}

	public function add_class($name){
		return $this->class('add', $name);
	}

	public function remove_class(...$args){
		return $args ? $this->class('remove', $args[0]) : $this->attr('class', []);
	}

	public function toggle_class($name){
		return $this->class('toggle', $name);
	}

	public function style(...$args){
		$fn		= fn($s)=> $s ? wpjam_array((array)$s, fn($k, $v)=> ($v || is_numeric($v)) ? [null, is_numeric($k) ? $v : $k.':'.$v] : null) : [];
		$style	= $fn($this->style);

		if($args){
			$args	= (count($args) == 1 || is_array($args[0])) ? $args[0] : [$args[0]=>$args[1]];

			return $this->attr('style', [...$style, ...$fn($args)]);
		}

		return wpjam_map($style, fn($v)=> $v ? rtrim($v, ';').';' : '');
	}

	public function render(){
		if($this->pull('__data')){
			return $this->render_data($this->get_args());
		}

		$attr	= wpjam_filter(self::process($this->get_args()), fn($v, $k)=> !str_ends_with($k, '_callback') 
			&& !str_ends_with($k, '_column')
			&& !str_starts_with($k, 'column_')
			&& !str_starts_with($k, '_')
			&& !str_starts_with($k, 'data-')
			&& !in_array($k, ['class', 'style', 'data', 'value']) 
			&& ($v || is_numeric($v)));

		$value	= $this->val();
		$attr	+= isset($value) ? compact('value') : [];
		$class	= array_merge($this->class(), wpjam_pick($attr, ['readonly', 'disabled']));
		$attr	+= wpjam_map(array_filter(['class'=>$class, 'style'=>$this->style()]), fn($v)=> implode(' ', array_unique($v)));

		return implode(wpjam_map($attr, function($v, $k){
			if(!is_scalar($v)){
				trigger_error($k.' '.var_export($v, true));
			}

			return ' '.$k.'="'.esc_attr($v).'"';
		})).$this->render_data($this->data());
	}

	protected function render_data($args){
		return implode(wpjam_map($args, function($v, $k){
			if(is_null($v) || $v === false){
				return '';
			}

			if($k == 'show_if'){
				$v	= wpjam_parse_show_if($v);

				if(!$v){
					return '';
				}
			}

			return ' data-'.$k.'=\''.(is_scalar($v) ? esc_attr($v) : ($k == 'data' ? http_build_query($v) : wpjam_json_encode($v))).'\'';
		}));
	}

	public static function is_bool($attr){
		return in_array($attr, ['allowfullscreen', 'allowpaymentrequest', 'allowusermedia', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'default', 'defer', 'disabled', 'download', 'formnovalidate', 'hidden', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected', 'typemustmatch']);
	}

	public static function process($attr){
		return wpjam_array($attr, function($k, $v){
			$k	= strtolower(trim($k));

			if(is_numeric($k)){
				$v = strtolower(trim($v));

				return self::is_bool($v) ? [$v, $v] : null;
			}else{
				return self::is_bool($k) ? ($v ? [$k, $k] : null) : [$k, $v];
			}
		});
	}

	public static function create($attr, $type=''){
		$attr	= ($attr && is_string($attr)) ? shortcode_parse_atts($attr) : wpjam_array($attr);
		$attr	+= $type == 'data' ? ['__data'=>true] : [];

		return new WPJAM_Attr($attr);
	}
}

class WPJAM_Tag extends WPJAM_Attr{
	protected $tag		= '';
	protected $text		= '';
	protected $_before	= [];
	protected $_after	= [];
	protected $_prepend	= [];
	protected $_append	= [];

	public function __construct($tag='', $attr=[], $text=''){
		$this->init($tag, $attr, $text);
	}

	public function __call($method, $args){
		if(in_array($method, ['text', 'tag', 'before', 'after', 'prepend', 'append'])){
			if($args){
				if(count($args) > 1){
					$value	= is_array($args[1])? new self(...$args) : new self($args[1], ($args[2] ?? []), $args[0]);
				}else{
					$value	= $args[0];

					if(is_array($value)){
						array_map(fn($v)=> $this->$method(...(is_array($v) ? $v : [$v])), $value);

						return $this;
					}
				}

				if($value || in_array($method, ['text', 'tag'])){
					if($method == 'text'){
						$this->text	= (string)$value;
					}elseif($method == 'tag'){
						$this->tag	= $value;
					}else{
						$cb	= 'array_'.(in_array($method, ['before', 'prepend']) ? 'unshift' : 'push');

						$cb($this->{'_'.$method}, $value);
					}
				}

				return $this;
			}

			return in_array($method, ['text', 'tag']) ? $this->$method : $this->{'_'.$method};
		}elseif(in_array($method, ['insert_before', 'insert_after', 'append_to', 'prepend_to'])){
			$args[0]->{str_replace(['insert_', '_to'], '', $method)}($this);

			return $this;
		}

		trigger_error($method);
	}

	public function is($tag){
		return $this->tag == $tag;
	}

	public function init($tag, $attr, $text){
		$this->empty();

		$this->tag	= $tag;
		$this->args	= ($attr && (wp_is_numeric_array($attr) || !is_array($attr))) ? ['class'=>$attr] : $attr;

		if($text && is_array($text)){
			$this->text(...$text);
		}elseif($text || is_numeric($text)){
			$this->text	= $text;
		}

		return $this;
	}

	public function render(){
		if($this->tag == 'a'){
			$this->href		??= 'javascript:;';
		}elseif($this->tag == 'img'){
			$this->title	??= $this->alt;
		}

		$render	= fn($k)=> $this->{'_'.$k} ? implode($this->{'_'.$k}) : '';
		$single	= $this->is_single($this->tag);
		$result	= $this->tag ? '<'.$this->tag.parent::render().($single ? ' />' : '>') : '';
		$result	.= !$single ? $render('prepend').(string)$this->text.$render('append') : '';
		$result	.= (!$single && $this->tag) ? '</'.$this->tag.'>' : '';

		return $render('before').$result.$render('after');
	}

	public function wrap($tag, ...$args){
		if(!$tag){
			return $this;
		}

		if(str_contains($tag, '></')){
			if(!preg_match('/<(\w+)([^>]+)>/', ($args ? sprintf($tag, ...$args) : $tag), $matches)){
				return $this;
			}

			$tag	= $matches[1];
			$attr	= shortcode_parse_atts($matches[2]);
		}else{
			$attr	= $args[0] ?? [];
		}

		return $this->init($tag, $attr, clone($this));
	}

	public function empty(){
		$this->_before	= $this->_after = $this->_prepend = $this->_append = [];
		$this->text		= '';

		return $this;
	}

	public static function is_single($tag){
		return $tag && in_array($tag, ['area', 'base', 'basefont', 'br', 'col', 'command', 'embed', 'frame', 'hr', 'img', 'input', 'isindex', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
	}
}

class WPJAM_Field extends WPJAM_Attr{
	const DATA_ATTRS	= ['filterable', 'summarization', 'show_option_all', 'show_option_none', 'option_all_value', 'option_none_value', 'max_items', 'min_items', 'unique_items'];

	protected function __construct($args){
		$this->args		= $args;
		$this->names	= $this->parse_names();
		$this->options	= maybe_callback($this->bind_if_closure($this->options));

		$this->_data_type	= wpjam_get_data_type_object($this);

		if($this->pattern){
			$this->attr(wpjam_get_item('pattern', $this->pattern) ?: []);
		}
	}

	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'wrap_tag'){
			if(($this->is('mu-fields') || $this->is('fieldset', true)) && $this->fields && array_all($this->fields, fn($v)=> empty($v['title']))){
				return $this->$key = 'fieldset';
			}
		}elseif($key == 'show_in_rest'){
			return $this->_editable;
		}elseif($key == '_editable'){
			return !$this->disabled && !$this->readonly && !$this->is('view');
		}elseif($key == '_title'){
			return $this->title.'「'.$this->key.'」';
		}elseif($key == '_fields'){
			return $this->$key = WPJAM_Fields::create($this->fields, $this);
		}elseif($key == '_item'){
			if($this->is('mu-fields')){
				return $this->_fields;
			}

			$args	= wpjam_except($this->get_args(), ['required', 'show_in_rest']);
			$type	= $this->is('mu-text') ? $this->item_type : substr($this->type, 3);

			return $this->$key = self::create(array_merge($args, ['type'=>$type]));
		}elseif($key == '_options'){
			$value	= $this->is('select') ? wpjam_array(['all', 'none'], function($i, $k){
				$v	= $this->{'show_option_'.$k} ?? false;

				if($v !== false){
					return [$this->{'option_'.$k.'_value'} ?? '', $v];
				}
			}) : [];

			return array_replace($value, wpjam_flatten($this->options, 'options', function($item, $opt){
				if(!is_array($item)){
					return $item;
				}

				if(isset($item['options'])){
					return;
				}

				if($k = array_find(['title', 'label', 'image'], fn($k)=> isset($item[$k]))){
					$v	= $item[$k];

					return empty($item['alias']) ? $v : array_replace([$opt=>$v], array_fill_keys(wp_parse_list($item['alias']), $v));
				}
			}));
		}
	}

	public function __set($key, $value){
		parent::__set($key, $value);

		if($key == 'names'){
			$this->_name	= wpjam_at($value, -1);
			$this->name		= array_shift($value).($value ? '['.implode('][', $value).']' : '');
		}
	}

	public function __call($method, $args){
		if(str_contains($method, '_by_')){
			[$method, $type]	= explode('_by', $method);

			return $this->$type ? wpjam_try([$this->$type, $method], ...$args) : array_shift($args);
		}

		trigger_error($method);
	}

	public function is($type, ...$args){
		if($type === 'decimal'){
			$step	= ($args ? $args[0] : $this->step) ?: '';

			return $step == 'any' || strpos($step, '.');
		}

		$type	= wp_parse_list($type);
		$strict	= $args ? $args[0] : false;

		if(in_array('mu', $type) && str_starts_with($this->type, 'mu-')){
			return true;
		}

		return in_array($this->type, $type, $strict) || (!$strict && in_array($this->type, wpjam_pick(['fieldset'=>'fields', 'view'=>'hr'], $type)));
	}

	public function get_schema(){
		return $this->_schema ??= $this->parse_schema();
	}

	public function set_schema($schema){
		return $this->attr('_schema', $schema);
	}

	protected function call_schema($action, $value){
		$schema	= $this->get_schema();

		if(!$schema){
			return $value;
		}

		if($action == 'sanitize'){
			if($schema['type'] == 'string'){
				$value	= (string)$value;
			}
		}else{
			if($this->pattern && !rest_validate_json_schema_pattern($this->pattern, $value)){
				wpjam_throw('rest_invalid_pattern', wpjam_join(' ', [$this->_title, $this->custom_validity]));
			}
		}

		return wpjam_try('rest_'.$action.'_value_from_schema', $value, $schema, $this->_title);
	}

	protected function parse_schema(...$args){
		if($args){
			$schema	= $args[0];
		}else{
			$schema	= ['type'=>'string'];

			if($this->is('mu')){
				$schema	= $this->get_schema_by_item();
				$schema	= ['type'=>'array', 'items'=>($this->is('mu-fields') ? ['type'=>'object', 'properties'=>$schema] : $schema)];
			}elseif($this->is('email')){
				$schema['format']	= 'email';
			}elseif($this->is('color')){
				if(!$this->data('alpha-enabled')){
					$schema['format']	= 'hex-color';
				}
			}elseif($this->is('url, image, file, img')){
				if($this->is('img') && $this->item_type != 'url'){
					$schema['type']	= 'integer';
				}else{
					$schema['format']	= 'uri';
				}
			}elseif($this->is('number, range')){
				$schema['type']	= $this->is('decimal') ? 'number' : 'integer';

				if($schema['type'] == 'integer' && $this->step > 1){
					$schema['multipleOf']	= $this->step;
				}
			}elseif($this->is('radio, select, checkbox')){
				if($this->is('checkbox') && !$this->options){
					$schema['type']	= 'boolean';
				}else{
					$schema	+= $this->custom_input ? [] : ['enum'=>array_keys($this->_options)];
					$schema	= $this->is('checkbox') ? ['type'=>'array', 'items'=>$schema] : $schema;
				}
			}

			if(in_array($schema['type'], ['number', 'integer'])){
				$map	= [
					'minimum'	=> 'min',
					'maximum'	=> 'max',
					'pattern'	=> 'pattern',
				];
			}elseif($schema['type'] == 'string'){
				$map	= [
					'minLength'	=> 'minlength',
					'maxLength'	=> 'maxlength',
					'pattern'	=> 'pattern'
				];
			}elseif($schema['type'] == 'array'){
				$map	= [
					'maxItems'		=> 'max_items',
					'minItems'		=> 'min_items',
					'uniqueItems'	=> 'unique_items',
				];
			}

			$schema	+= wpjam_array($map ?? [], fn($k, $v)=> isset($this->$v) ? [$k, $this->$v] : null);
			$rest	= $this->show_in_rest;

			if($rest && is_array($rest)){
				if(isset($rest['schema']) && is_array($rest['schema'])){
					$schema	= wpjam_merge($schema, $rest['schema']);
				}

				if(!empty($rest['type'])){
					$key	= $schema['type'] == 'array' && $rest['type'] != 'array' ? 'items.type' : 'type';
					$schema	= wpjam_set($schema, $key, $rest['type']);
				}
			}

			if($this->required && !$this->show_if){	// todo 以后可能要改成 callback
				$schema['required']	= true;
			}
		}

		$schema	= wpjam_except($schema, wpjam_filter(['object'=>'properties', 'array'=>'items'], fn($v, $k)=> isset($schema[$v]) && $k != $schema['type']));
		$parse	= [
			'enum'			=> fn($value)=> array_map(fn($v)=> rest_sanitize_value_from_schema($v, $schema), $value),
			'properties'	=> fn($value)=> array_map([$this, 'parse_schema'], $value),
			'items'			=> fn($value)=> $this->parse_schema($value),
		];

		if($k = array_find_key($parse, fn($v, $k)=> isset($schema[$k]))){
			$schema[$k]	= $parse[$k]($schema[$k]);
		}

		return $schema;
	}

	protected function parse_names($prepend=null){
		$fn	= fn($v)=> $v ? ((str_contains($v, '[') && preg_match_all('/\[?([^\[\]]+)\]*/', $v, $m)) ? $m[1] : [$v]) : [];

		return array_merge($fn($prepend ?? $this->pull('prepend_name')), $fn($this->name));
	}

	protected function parse_show_if(...$args){
		if($args = wpjam_parse_show_if($args ? $args[0] : $this->show_if)){
			$args['value']	??= true;
			$args['key']	= ($this->_creator && $this->_creator->get_by_fields($args['key'])) ? $this->_prefix.$args['key'].$this->_suffix : $args['key'];

			return $args;
		}
	}

	public function show_if($values){
		$args	= $this->parse_show_if();

		return ($args && empty($args['external'])) ? wpjam_match($values, $args) : true;
	}

	public function validate($value, $for=''){
		$code	= $for ?: 'value';

		if($for == 'parameter'){
			$value	??= $this->default;

			if($this->required && is_null($value)){
				wpjam_throw('missing_'.$code, '缺少参数：'.$this->key);
			}
		}

		if(($cb	= $this->validate_callback) && wpjam_try($cb, $value) === false){
			wpjam_throw('invalid_'.$code, [$this->key]);
		}

		$value	= $this->try('validate_value', $value);

		if(!$this->is('fieldset') || $this->_data_type){
			if($for == 'parameter'){
				if(!is_null($value)){
					$value	= $this->call_schema('sanitize', $value);
				}
			}else{
				if($this->required && !$value && !is_numeric($value)){
					wpjam_throw($code.'_required', [$this->_title]);
				}

				$value	= $this->before_schema($value);

				if($value || is_array($value) || is_numeric($value)){	// 空值只需 required 验证
					$this->call_schema('validate', $value);
				}
			}
		}

		return ($cb	= $this->sanitize_callback) ? wpjam_try($cb, ($value ?? '')) : $value;
	}

	public function validate_value($value){
		if($this->is('mu')){
			$value	= is_array($value) ? wpjam_filter($value, fn($v)=> $v || is_numeric($v), true) : ($value ? wpjam_json_decode($value) : []);
			$value	= array_values(wpjam_if_error($value, []));

			return array_map([$this, 'validate_value_by_item'], $value);
		}elseif($this->custom_input){
			return $this->call_custom('validate', $value);
		}elseif($this->is('checkbox') && $this->options){
			return $value ?: [];
		}

		return $this->validate_value_by_data_type($value, $this);
	}

	protected function before_schema($value, $schema=null){
		if(is_null($schema)){
			$schema	= $this->get_schema();

			if(is_null($value) && $schema && !empty($schema['required'])){
				$value	= false;
			}
		}

		if($schema){
			$cb	= [
				'array'		=> ['is_array', fn($value)=> wpjam_map($value, fn($v)=> $this->before_schema($v, $schema['items']))],
				'object'	=> ['is_array', fn($value)=> wpjam_map($value, fn($v, $k)=> $this->before_schema($v, ($schema['properties'][$k] ?? '')))],
				'integer'	=> ['is_numeric', 'intval'],
				'number'	=> ['is_numeric', 'floatval'],
				'string'	=> ['is_scalar', 'strval'],
				'null'		=> [fn($v)=> !$v && !is_numeric($v), fn()=>null],
				'boolean'	=> [fn($v)=> is_scalar($v) || is_null($v), 'rest_sanitize_boolean']
			][$schema['type']] ?? '';

			if($cb && $cb[0]($value)){
				return $cb[1]($value);
			}
		}

		return $value;
	}

	public function pack($value){
		return wpjam_set([], $this->names, $value);
	}

	public function unpack($data){
		return wpjam_get($data, $this->names);
	}

	public function value_callback($args=[]){
		if(!$args || ($this->is('view') && $this->value)){
			return $this->value;
		}

		$cb		= $this->value_callback;
		$value	= wpjam_value_callback(...($cb ? [$cb, $this->_name, wpjam_get($args, 'id')] : [$args, $this->names[0]]));
		$value	= $cb ? $value : $this->unpack([$this->names[0] => $value]);

		return $value ?? $this->value;
	}

	public function prepare($args){
		return $this->prepare_value($this->call_schema('sanitize', $this->value_callback($args)));
	}

	public function prepare_value($value){
		if($this->is('mu')){
			return array_map([$this, 'prepare_value_by_item'], $value);
		}elseif($this->is('img, image, file')){
			return wpjam_get_thumbnail($value, $this->size);
		}elseif($this->custom_input){
			return $this->call_custom('prepare', $value);
		}

		return $this->prepare_value_by_data_type($value, $this);
	}

	protected function call_custom($action, $value){
		$values	= array_map('strval', array_keys($this->_options));
		$input	= $this->custom_input;
		$field	= $this->_custom ??= self::create((is_array($input) ? $input : [])+[
			'title'			=> is_string($input) ? $input : '其他',
			'placeholder'	=> '请输入其他选项',
			'id'			=> $this->id.'__custom_input',
			'key'			=> $this->key.'__custom_input',
			'type'			=> 'text',
			'class'			=> '',
			'required'		=> true,
			'show_if'		=> [$this->key, '__custom'],
		]);

		if($this->is('checkbox')){
			$value	= array_diff(($value ?: []), ['__custom']);
			$diff	= array_diff($value, $values);

			if($diff){
				$field->val(reset($diff));

				if($action == 'validate'){
					if(count($diff) > 1){
						wpjam_throw('too_many_custom_value', $field->_title.'只能传递一个其他选项值');
					}

					$field->set_schema($this->get_schema()['items'])->validate(reset($diff));
				}
			}
		}else{
			if(isset($value) && !in_array($value, $values)){
				$field->val($value);

				if($action == 'validate'){
					$field->set_schema($this->get_schema())->validate($value);
				}
			}
		}

		if($action == 'render'){
			$this->value	= isset($field->value) ? ($this->is('checkbox') ? [...$value, '__custom'] : '__custom') : $value;
			$this->options	+= ['__custom'=>$field->title];

			return $field->attr(['title'=>'', 'name'=>$this->name])->wrap();
		}

		return $value;
	}

	public function affix($args=[]){
		$creator	= $this->_creator;
		$prepend	= $creator->name;
		$prefix		= $this->_prefix = $creator->key.'__';
		$suffix		= '';

		if($args){
			$prepend	.= '[i'.$args['i'].']';
			$suffix		= $this->_suffix = '__'.$args['i'];

			if(isset($args['v'][$this->name])){
				$this->value	= $args['v'][$this->name];
			}
		}

		$this->names	= $this->parse_names($prepend);
		$this->id		= $prefix.$this->id.$suffix;
		$this->key		= $prefix.$this->key.$suffix;

		$this->data('dep', fn($v)=> $v && $creator->get_by_fields($v) ? $prefix.$v.$suffix : null);
	}

	public function wrap($tag='', $args=[]){
		if(is_object($tag)){
			$wrap	= $this->wrap_tag ?: (($this->is('fieldset') && !$this->group && !$args) ? '' : 'div');

			$tag->wrap($wrap, $args)->add_class($this->group ? 'field-group' : '');

			if($wrap == 'fieldset' && $this->title){
				$tag->prepend('legend', ['screen-reader-text'], $this->title);
			}

			if($this->is('fieldset') && $this->summary){
				$tag->before([$this->summary, 'strong'], 'summary')->wrap('details');
			}

			return $tag;
		}

		$creator	= $this->_creator;

		if($creator){
			if($creator->is('mu-fields')){
				$this->affix($args);
			}

			if($creator->label === true){
				$args	+= ['label'=>true];
			}
		}

		$field	= $this->render($args);
		$wrap	= $tag ? wpjam_tag($tag, ['id'=>$tag.'_'.$this->id])->append($field) : $field;
		$label	= $this->is('view, mu, fieldset, img, uploader, radio') || ($this->is('checkbox') && $this->options) ? [] : ['for'=> $this->id];

		if($this->buttons){
			$this->after	= wpjam_join(' ', [$this->after, implode(' ', wpjam_map($this->buttons, [self::class, 'create']))]);
		}

		foreach(array_filter(wpjam_pick($this, ['before', 'after'])) as $k => $v){
			$action	= $field->is('div') ? ($k == 'before' ? 'prepend' : 'append') : $k;

			$field->$action($k == 'before' ? $v.'&nbsp;' : '&nbsp;'.$v);
		}

		if($this->is('fieldset')){
			$_args	= array_filter(wpjam_pick($this, ['class', 'style'])+['data'=>$this->data()]);
			$_args	= $_args ? wpjam_set($_args, 'data.key', $this->key) : [];

			$this->wrap($field, $_args);

			$wrap->after("\n");
		}else{
			if(($this->label || $this->before || $this->after || !empty($args['label'])) && !$field->is('div')){
				$field->wrap('label', $label);
			}
		}

		$class	= [$this->wrap_class, wpjam_get($args, 'wrap_class'), $this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')];
		$title	= $this->title ? wpjam_tag('label', $label, $this->title) : '';
		$desc	= $this->description ?: '';

		if($desc){
			if(is_array($desc)){
				$attr	= $desc[1] ?? [];
				$attr	= (isset($attr['show_if']) ? ['data-show_if'=>$this->parse_show_if(wpjam_pull($attr, 'show_if'))] : []) + $attr;
				$desc	= $desc[0];
			}

			$desc	= wpjam_tag('p', ($attr ?? [])+['class'=>'description'], $desc);
		}

		$field->after($desc);

		if($creator && !$creator->is('fields')){
			if($creator->wrap_tag == 'fieldset'){
				if($title || $desc || ($wrap->data('show_if') && ($this->is('fields') || !is_null($field->data('query_title'))))){
					$field->before($title ? ['<br />', $title] : null)->wrap('div', ['inline']);
				}

				$title	= null;
			}else{
				if($title){
					$title->add_class('sub-field-label');
					$field->wrap('div', ['sub-field-detail']);
				}

				$class[]	= 'sub-field';
			}
		}

		if($tag == 'tr'){
			if($title){
				$title->wrap('th', ['scope'=>'row']);
			}

			$field->wrap('td', $title ? [] : ['colspan'=>2]);
		}elseif($tag == 'p'){
			if($title){
				$title->after('<br />');
			}
		}

		$field->before($title);

		return $wrap->add_class($class)->data('show_if', $this->parse_show_if())->data('for', $wrap === $field ? null : $this->key);
	}

	public function render($args=[]){
		$this->value	= $this->value_callback($args);
		$this->class	??= $this->is('text, password, url, email, image, file, mu-image, mu-file') ? 'regular-text' : null;

		if($this->render){
			return wpjam_wrap($this->bind_if_closure($this->render)($args));
		}elseif($this->is('fieldset')){
			return $this->render_by_fields($args);
		}elseif($this->is('mu')){
			$value	= $this->value ?: [];
			$value	= is_array($value) ? array_values(wpjam_filter($value, fn($v)=> $v || is_numeric($v), true)) : [$value];
			$wrap	= wpjam_tag('div', ['id'=>$this->id])->data($this->pull(self::DATA_ATTRS));
			$class	= ['mu', $this->type, $this->_type, ($this->sortable !== false ? 'sortable' : '')];
		
			if($this->is('mu-img, mu-image, mu-file')){
				if(!current_user_can('upload_files')){
					$this->disabled	= 'disabled';
				}

				$data['button_text']	= '选择'.($this->is('mu-file') ? '文件' : '图片').'[多选]';
			}else{
				$data['button_text']	= $this->button_text ?: '添加'.(wpjam_between(mb_strwidth($this->title ?: ''), 4, 8) ? $this->title : '选项');
			}

			if($this->is('mu-fields')){
				$append	= wpjam_map($value+['${i}'=>[]], fn($v, $i)=> $this->wrap($this->render_by_fields(['i'=>$i, 'v'=>$v])->wrap($v ? '' : 'template'), ['mu-item']));
			}else{
				$args	= ['id'=>'', 'name'=>$this->name.'[]', 'value'=>null];
				$data	+= ['value'=>&$value];

				if($this->is('mu-img')){
					$this->direction	= 'row';

					$value	= array_map(fn($v)=> ['value'=>$v, 'url'=> wpjam_at(explode('?', wpjam_get_thumbnail($v)), 0)] , $value);
					$data	+= ['thumb_args'=> wpjam_get_thumbnail_args([200, 200]), 'item_type'=>$this->item_type];
					$append	= $this->input($args+['type'=>'hidden']);
				}elseif($this->is('mu-image, mu-file')){
					$data	+= ['item_type'=> $this->is('mu-image') ? 'image' : $this->item_type];
					$append	= $this->input($args+['type'=>'url']);
				}elseif($this->is('mu-text')){
					$this->item_type	??= 'text';

					if($this->item_type == 'text'){
						if($this->direction == 'row'){
							$this->class	??= 'medium-text';
						}

						if($this->_data_type){
							$value	= array_map(fn($v)=> ($l = $this->query_label_by_data_type($v, $this)) ? ['value'=>$v, 'label'=>$l] : $v, $value);
						}
					}

					$append	= $this->attr_by_item($args)->render();
				}
			}

			return $wrap->append($append)->data($data)->add_class($class)->add_class($this->_type ? '' : 'direction-'.($this->direction ?: 'column'));
		}elseif($this->is('radio, select, checkbox')){
			if($this->is('checkbox')){
				if(!$this->options){
					if($this->_type){
						return wpjam_tag();
					}

					$this->label	??= $this->pull('description');

					return $this->input(['value'=>1])->data('value', $this->value)->after($this->label);
				}

				$this->name	.= '[]';

				if($this->pull('required')){
					$this->min_items	??= 1;
				}
			}

			$data	= $this->pull(self::DATA_ATTRS);
			$custom	= $this->custom_input ? $this->call_custom('render', $this->value) : '';
			$field	= $this->is('select') ? $this->tag('select') : wpjam_tag('fieldset', ['id'=>$this->id.'_options', 'class'=>['checkable', 'direction-'.($this->direction ?: (($this->_type || $this->sep) ? 'column' : 'row'))]]);

			$field->data($data)->data('value', $this->value)->append($this->render_options($this->options));

			if($custom){
				$this->is('select') || $this->_type ? $field->after('&emsp;'.$custom) : $field->append($custom);
			}

			if($this->_type){
				$field->add_class([$this->_type, 'hidden'])->data('show_option_all', fn($v)=> $v ?: '请选择')->wrap('div', [$this->_type.'-wrap']);
			}

			return $field;
		}elseif($this->is('textarea')){
			return $this->textarea();
		}else{
			return $this->input()->data(['class'=>$this->class]+($this->_data_type ? ['label'=>$this->query_label_by_data_type($this->value, $this)] : []));
		}
	}

	protected function render_options($options){
		return wpjam_map($options, function($label, $opt){
			$attr	= $data = $class = [];

			if(is_array($label)){
				$arr	= $label;
				$label	= wpjam_pull($arr, ['label', 'title']);
				$label	= $label ? reset($label) : '';
				$image	= wpjam_pull($arr, 'image');

				if($image){
					$image	= is_array($image) ? array_slice($image, 0, 2) : [$image];
					$label	= implode(array_map(fn($i)=> wpjam_tag('img', ['src'=>$i, 'alt'=>$label]), $image)).$label;
					$class	= ['image-'.$this->type];
				}

				foreach($arr as $k => $v){
					if(is_numeric($k)){
						if(self::is_bool($v)){
							$attr[$v]	= $v;
						}
					}elseif(self::is_bool($k)){
						if($v){
							$attr[$k]	= $k;
						}
					}elseif($k == 'show_if'){
						$data[$k]	= $this->parse_show_if($v);
					}elseif($k == 'alias'){
						$data[$k]	= wp_parse_list($v);
					}elseif($k == 'class'){
						$class	= [...$class, ...wp_parse_list($v)];
					}elseif($k == 'description'){
						$this->description	.= $v ? wpjam_wrap($v, 'span', ['data-show_if'=>$this->parse_show_if([$this->key, '=', $opt])]) : '';
					}elseif($k == 'options'){
						$attr	+= ['label'=>$label];
						$label	= $this->render_options($v ?: []);
					}elseif(!is_array($v)){
						$data[$k]	= $v;
					}
				}
			}

			if($this->is('select')){
				if(is_array($label)){
					$args	= ['optgroup', $attr];
				}else{
					$opt	= (isset($data['alias']) && isset($this->value) && in_array($this->value, $data['alias'])) ? $this->value : $opt;
					$args	= ['option', $attr+['value'=>$opt]];
				}
			}else{
				if(is_array($label)){
					$args	= ['label', $attr, $attr['label'].'<br />'];
				}else{
					$id		= $this->id.'_'.$opt;
					$args	= ['label', ['for'=>$id], $this->input(['id'=>$id, 'value'=>$opt]+$attr)];
				}
			}

			return wpjam_tag(...$args)->append($label)->data($data)->add_class($class);
		});
	}

	protected function tag($tag='input', $attr=[]){
		$tag	= wpjam_tag($tag, $this->get_args())->attr($attr)->add_class('field-key field-key-'.$this->key);

		$data	= $tag->pull(['key', 'data_type', 'query_args', 'custom_validity'])+$tag->pull(self::DATA_ATTRS);
		$tag	= $tag->data($data)->remove_attr(['default', 'options', 'title', 'names', 'label', 'render', 'before', 'after', 'description', 'wrap_class', 'wrap_tag', 'item_type', 'direction', 'group', 'buttons', 'button_text', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'parse_required', 'show_if', 'show_in_rest', 'column', 'custom_input']);

		return $tag->is('input') ? $tag : $tag->remove_attr(['type', 'value']);
	}

	protected function input($attr=[]){
		$tag	= $this->tag('input', $attr);

		if(in_array($tag->type, ['number', 'url', 'tel', 'email', 'search'])){
			$tag->inputmode	??= $tag->type == 'number' ? ($this->is('decimal', $tag->step) ? 'decimal' : 'numeric') : $tag->type;
		}

		return $tag;
	}

	protected function textarea(){
		return $this->tag('textarea')->append(esc_textarea($this->value ?: ''));
	}

	public static function add_pattern($key, $args){
		wpjam_add_item('pattern', $key, $args);
	}

	public static function parse($field){
		if(is_string($field)){
			return ['type'=>'view', 'value'=>$field, 'options'=>[], 'wrap_tag'=>''];
		}

		$field	= self::process($field);

		$field['options']	= wpjam_get($field, 'options') ?: [];
		$field['type']		= $type = wpjam_get($field, 'type') ?: array_find(['options'=>'select', 'label'=>'checkbox', 'fields'=>'fieldset'], fn($v, $k)=> !empty($field[$k])) ?: 'text';

		if($type == 'fields' && wpjam_pull($field, 'fields_type') == 'size'){
			$type	= 'size';

			$field['propertied']	= false;
		}

		if(wpjam_get($field, 'filterable') === 'multiple'){
			if($type == 'select'){
				$field['multiple']	= true;	
			}elseif(in_array($type, ['text', 'number'])){
				$field['item_type']		= $type;
				$field['type']			= 'tag-input';
				$field['unique_items']	= true;
				$field['sortable']		= false;
			}
		}

		if($type == 'size'){
			$field['type']			= 'fields';
			$field['propertied']	??= true;
			$field['fields']		= wpjam_array(wpjam_merge([
				'width'		=> ['type'=>'number',	'class'=>'small-text'],
				'x'			=> '✖️',
				'height'	=> ['type'=>'number',	'class'=>'small-text']
			], ($field['fields'] ?? [])), fn($k, $v)=> is_array($v) && !empty($v['key']) ? $v['key'] : $k);
		}elseif(in_array($type, ['fieldset', 'fields'])){
			$field['propertied']	??= !empty($field['data_type']) ? true : wpjam_pull($field, 'fieldset_type') == 'array';	
		}elseif($type == 'mu-select' || ($type == 'select' && !empty($field['multiple']))){
			unset($field['multiple'], $field['data_type']);

			$field['type']		= 'checkbox';
			$field['_type']		= 'mu-select';
		}elseif($type == 'tag-input'){
			$field['type']		= 'mu-text';
			$field['_type']		= $type;
			$field['direction']	??= 'row';
		}

		return $field;
	}

	public static function create($field, $key=''){
		$field	= self::parse($field);
		$field	= ($key && !is_numeric($key) ? ['key'=>$key] : [])+$field;

		if(empty($field['key']) || is_numeric($field['key'])){
			trigger_error('Field 的 key 不能为'.(empty($field['key']) ? '空' : '纯数字「'.$field['key'].'」'));
			return;
		}

		$field	= wpjam_fill(['id', 'name'], fn($k)=> wpjam_get($field, $k) ?: $field['key'])+$field;
		$field	+= array_filter(['max_items'=> wpjam_pull($field, 'total')]);
		$type	= $field['type'];

		if($type == 'color'){	
			$field	+= ['label'=>true, 'data-button_text'=> wpjam_pull($field, 'button_text'), 'data-alpha-enabled'=>wpjam_pull($field, 'alpha')];
		}elseif($type == 'timestamp'){
			$field['sanitize_callback']	= fn($value)=> $value ? wpjam_strtotime($value) : 0;
		}elseif($type == 'editor'){
			$field['render']	??= function(){
				$this->id	= 'editor_'.$this->id;

				if(user_can_richedit()){
					if(!wp_doing_ajax()){
						return wpjam_ob_get_contents('wp_editor', ($this->value ?: ''), $this->id, ['textarea_name'=>$this->name]);
					}

					$this->data('editor', ['tinymce'=>true, 'quicktags'=>true, 'mediaButtons'=>current_user_can('upload_files')]);
				}

				return $this->textarea();
			};
		}elseif(in_array($type, ['img', 'image', 'file'])){
			$field['render']	??= function(){
				if(!current_user_can('upload_files')){
					$this->disabled	= 'disabled';
				}

				$size	= array_filter(wpjam_pick(wpjam_parse_size($this->size), ['width', 'height']));

				if(count($size) == 2){
					$this->description	??= '建议尺寸：'.implode('x', $size);
				}

				$type	= 'url';

				if($this->is('img')){
					$type	= 'hidden';
					$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);
					$data	= ['thumb_args'=> wpjam_get_thumbnail_args($size), 'size'=>array_filter(wpjam_map($size, fn($v)=> (int)($v/2)))];
				}

				return $this->input(['type'=>$type])->wrap('div', ['wpjam-'.$this->type])->data(($data ?? [])+[
					'value'			=> $this->value ? ['url'=>wpjam_get_thumbnail($this->value), 'value'=>$this->value] : '',
					'item_type'		=> $this->is('image') ? 'image' : $this->item_type,
					'media_button'	=> $this->button_text ?: '选择'.($this->is('file') ? '文件' : '图片')
				]);	
			};
		}elseif($type == 'uploader'){
			$field['render']	??= function(){
				$mime_types	= $this->pull('mime_types') ?: ['title'=>'图片', 'extensions'=>'jpeg,jpg,gif,png'];
				$plupload	= [
					'browse_button'		=> 'plupload_button__'.$this->key,
					'button_text'		=> $this->button_text ?: __('Select Files'),
					'container'			=> 'plupload_container__'.$this->key,
					'file_data_name'	=> $this->key,
					'filters'			=> [
						'mime_types'	=> wp_is_numeric_array($mime_types) ? $mime_types : [$mime_types],
						'max_file_size'	=> (wp_max_upload_size() ?: 0).'b'
					],
					'multipart_params'	=> [
						'_ajax_nonce'	=> wp_create_nonce('upload-'.$this->key),
						'action'		=> 'wpjam-upload',
						'file_name'		=> $this->key,
					]
				]+(($this->pull('drap_drop') && !wp_is_mobile()) ? [
					'drop_element'	=> 'plupload_drag_drop__'.$this->key,
					'drop_info'		=> [__('Drop files to upload'), _x('or', 'Uploader: Drop files here - or - Select Files')]
				] : []);

				return $this->input(['type'=>'hidden'])->wrap('div', ['plupload'])->data(['key'=>$this->key, 'plupload'=>$plupload]);
			};
		}elseif($type == 'view'){
			$field['render']	??= function(){
				$value	= (string)$this->value;
				$wrap	= $value != strip_tags($value);
				$tag	= $this->wrap_tag ?? (!$this->show_if && $wrap ? '' : 'span');
				$value	= $this->options && !$wrap ? (array_find($this->_options, fn($v, $k)=> $value ? $k == $value : !$k) ?? $value) : $value;

				return $tag ? wpjam_tag($tag, ['field-key field-key-'.$this->key], $value)->data(['val'=>$this->value, 'name'=>$this->name]) : $value;
			};
		}elseif($type == 'hr'){
			$field['render']	= fn()=> wpjam_tag('hr');
		}

		return new WPJAM_Field($field);
	}

	public static function ajax_upload($data){
		if(!check_ajax_referer('upload-'.$data['file_name'], false, false)){
			wp_die('invalid_nonce');
		}

		return wpjam_upload($data['file_name']);
	}
}

class WPJAM_Fields extends WPJAM_Attr{
	private $fields		= [];
	private $creator	= null;

	private function __construct($fields, ...$args){
		$this->args		= [];
		$this->fields	= $fields ?: [];

		if($args){
			if(is_array($args[0])){
				$this->args	= $args[0];
			}elseif(is_object($args[0])){
				$this->creator	= $args[0];
			}
		}
	}

	public function	__call($method, $args){
		$data	= [];

		foreach($this->fields as $field){
			if($method == 'prepare' && !$field->show_in_rest){
				continue;
			}

			if($field->is('fieldset') && !$field->_data_type){
				$value	= $field->try($method.'_by_fields', ...$args);
			}else{
				$name	= $field->_name;

				if($method == 'get_defaults'){
					$value	= $field->pack($field->disabled ? null : $field->value);
				}elseif($method == 'get_if_values'){ // show_if 基于key，且propertied的fieldset的key是 {$key}__{$sub_key}
					$value	= $field->_editable ? $field->catch('validate', $field->unpack($args[0])) : ($field->disabled ? null : $field->value_callback($this->args));
					$value	= [$field->key => wpjam_if_error($value, null)];
				}elseif($method == 'get_schema'){
					$value	= array_filter([$name => $field->get_schema()]);
				}elseif($method == 'prepare'){
					$value	= $field->pack($field->prepare($args ? $args[0] : $this->args));
				}elseif(in_array($method, ['prepare_value', 'validate_value'])){
					$value	= isset($args[0][$name]) ? [$name => $field->try($method, $args[0][$name])] : [];
				}else{
					$value	= $field->try($method, ...$args);
				}
			}

			$data	= wpjam_merge($data, $value);
		}

		return $data;
	}

	public function	__invoke($args=[]){
		return $this->render($args);
	}

	public function get($key){
		return $this->fields[$key] ?? null;
	}

	public function validate($values=null, $for=''){
		$data	= [];
		$values	??= wpjam_get_post_parameter();

		[$if_values, $if_show]	= ($this->creator && $this->creator->_if) ? $this->creator->_if : [$this->get_if_values($values), true];

		foreach($this->fields as $field){
			if(!$field->_editable){
				continue;
			}

			$value	= $values;
			$show	= $if_show ? $field->show_if($if_values) : false;

			if($field->is('fieldset')){
				$field->_if	= [$if_values, $show];
				$value		= $field->validate_by_fields($value, $for);
			}

			if(!$field->is('fieldset') || ($show && $field->propertied)){
				if(!$show){
					$if_values[$field->key] = null;	// 第一次获取的值都是经过 json schema validate 的，可能存在 show_if 的字段在后面
				}

				$value	= $field->pack($show ? $field->validate($field->unpack($value), $for) : null);
			}

			$data	= wpjam_merge($data, $value);
		}

		return $data;
	}

	public function render($args=[]){
		$args		+= $this->args;
		$fields		= $groups = [];
		$creator	= $this->creator;

		if($creator){
			$type	= '';
			$wrap	= $creator->is('fields') ? '' : $creator->wrap_tag;
			$tag	= is_null($wrap) ? 'div' : '';
			$sep	= $creator->sep ??= ($wrap == 'fieldset' ? ($creator->group ? '' : '<br />') : '')."\n";
			$group	= reset($this->fields)->group;

			if($creator->is('fieldset') && $creator->propertied && is_array($creator->value)){
				$args['data']	= $creator->pack($creator->value);
			}
		}else{
			$type	= wpjam_pull($args, 'fields_type', 'table');
			$tag	= wpjam_pull($args, 'wrap_tag', (['table'=>'tr', 'list'=>'li'][$type] ?? $type));
			$sep	= "\n";
		}

		foreach($this->fields as $field){
			if($creator && $field->group != $group){
				[$groups[], $fields, $group]	= [[$group, $fields], [], $field->group];
			}

			$fields[]	= $field->sandbox(fn()=> $this->wrap($tag, $args));
		}

		if($groups){
			$fields	= array_merge(...array_map(fn($g)=> ($g[0] && count($g[1]) > 1) ? [wpjam_tag('div', ['field-group'], implode("\n", $g[1]))] : $g[1], [...$groups, [$group, $fields]]));
		}

		$fields	= wpjam_wrap(implode($sep, array_filter($fields)));

		if($type == 'table'){
			$fields->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']);
		}elseif($type == 'list'){
			$fields->wrap('ul');
		}

		return $fields;
	}

	public function get_parameter($method='POST', $merge=true){
		$data		= wpjam_get_parameter('', [], $method);
		$validated	= $this->validate($data, 'parameter');

		return $merge ? array_merge($data, $validated) : $validated;
	}

	public static function create($fields, $args=[]){
		$creator	= is_object($args) ? $args : null;
		$prefix		= '';

		if($creator){
			$sink	= wpjam_pick($creator, ['readonly', 'disabled']);

			if($creator->is('mu-fields') || $creator->propertied){
				if(!$fields){
					wp_die($creator->_title.'fields不能为空');
				}
			}else{
				$prefix	= $creator->prefix === true ? $creator->key : $creator->prefix;
			}
		}

		foreach(self::parse($fields, compact('prefix')) as $key => $field){
			if(wpjam_get($field, 'show_admin_column') === 'only'){
				continue;
			}

			$object	= WPJAM_Field::create($field, $key);

			if(!$object){
				continue;
			}

			if($creator){
				$object->attr($sink+['_creator'=>$creator]);

				if($creator->is('mu-fields') || $creator->propertied){
					if(count($object->names) > 1 || ($object->is('fieldset', true) && !$object->data_type) || $object->is('mu-fields')){
						trigger_error($creator->_title.'子字段不允许'.(count($object->names) > 1 ? '[]模式' : $object->type).':'.$object->name);

						continue;
					}

					if($creator->propertied){
						$object->affix();
					}
				}else{
					$object->show_in_rest	??= $creator->show_in_rest;
				}
			}

			$objects[$key]	= $object;
		}

		return new self($objects ?? [], $creator ?: $args);
	}

	public static function parse($fields, $args=[]){
		foreach((array)($fields ?: []) as $key => $field){
			$field	= WPJAM_Field::parse($field);

			if(!empty($args['prefix'])){
				if($field['type'] == 'fields' && !$field['propertied']){	// 向下传递
					$field	= array_merge($field, ['prefix'=>$args['prefix']]);
				}else{
					$key	= wpjam_join('_', [$args['prefix'], $key]);
				}
			}

			$parsed[$key]	= $field;

			if(in_array($field['type'], ['fieldset', 'fields'])){
				$subs	= (!empty($args['flat']) && !$field['propertied']) ? $field['fields'] : [];
			}elseif($field['type'] == 'checkbox' && !$field['options']){
				$subs	= wpjam_map((wpjam_pull($field, 'fields') ?: []), fn($v)=> $v+(isset($v['show_if']) ? [] : ['show_if'=>[$key, '=', 1]]));
			}else{
				$subs	= wpjam_flatten($field['options'], 'options', fn($item, $opt)=> (is_array($item) && isset($item['fields'])) ? wpjam_map($item['fields'], fn($v)=> $v+(isset($v['show_if']) ? [] : ['show_if'=>[$key, '=', $opt]])) : null);
			}

			$parsed	= array_merge($parsed, $subs ? self::parse($subs, $args) : []);
		}

		return $parsed ?? [];
	}
}