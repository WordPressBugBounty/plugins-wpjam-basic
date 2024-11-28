<?php
class WPJAM_Attr extends WPJAM_Args{
	public function __toString(){
		return (string)$this->render();
	}

	public function jsonSerialize(){
		return $this->render();
	}

	public function attr($key, ...$args){
		if(!is_array($key) && !$args){
			return $this->$key;
		}

		if(is_array($key)){
			array_walk($key, fn($v, $k)=> $this->$k = $v);
		}else{
			$this->$key	= $args[0];
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
			return array_merge($data, wpjam_array($this->get_args(), fn($k)=> str_starts_with($k, 'data-') ? wpjam_remove_prefix($k, 'data-') : null));
		}

		$args	= wpjam_parse_args($args);

		return is_array($args) ? $this->attr(wpjam_array($args, fn($k)=> 'data-'.$k)) : $this->{'data-'.$args} ?? ($data[$args] ?? null);
	}

	protected function class($action='', ...$args){
		if(!$action){
			return array_filter(wp_parse_list($this->class ?: []));
		}

		$class	= wp_parse_list($args[0] ?: []);

		if($class){
			$cb	= ['add'=>'array_merge', 'remove'=>'array_diff', 'toggle'=>'wpjam_toggle'][$action];

			return $this->attr('class', $cb($this->class(), $class));
		}

		return $this;
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
			return $this->attr('style', [...$style, ...$fn(wpjam_parse_args($args))]);
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
		$class	= array_merge($this->class(), wpjam_slice($attr, ['readonly', 'disabled']));
		$attr	+= wpjam_map(array_filter(['class'=>$class, 'style'=>$this->style()]), fn($v)=> implode(' ', array_unique($v)));

		return implode(wpjam_map($attr, function($v, $k){
			if(is_scalar($v)){
				return ' '.$k.'="'.esc_attr($v).'"';
			}else{
				trigger_error($k.' '.var_export($v, true));
			}
		})).$this->render_data($this->data());
	}

	protected function render_data($args){
		return implode(wpjam_map($args, function($v, $k){
			if(isset($v) && $v !== false){
				if($k == 'show_if'){
					$v	= wpjam_parse_show_if($v);

					if(!$v){
						return '';
					}
				}

				return ' data-'.$k.'=\''.(is_scalar($v) ? esc_attr($v) : ($k == 'data' ? http_build_query($v) : wpjam_json_encode($v))).'\'';
			}

			return '';
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
			$method	= str_replace(['insert_', '_to'], '', $method);

			return $args[0]->$method($this);
		}

		trigger_error($method);
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
	protected function __construct($args){
		$this->args		= $args;
		$this->options	= $this->parse_options();
		$this->names	= $this->parse_names();

		$this->_data_type	= wpjam_get_data_type_object($this);
	}

	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == 'show_in_rest'){
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
			$type	= $this->is('mu-text') ? $this->item_type : wpjam_remove_prefix($this->type, 'mu-');

			return $this->$key = self::create(array_merge($args, ['type'=>$type]));
		}elseif($key == '_options'){
			return wpjam_flatten($this->options, 'options', function($item, $opt){
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
			});
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

		if(!$strict){
			if(in_array('fieldset', $type)){
				$type[]	= 'fields';
			}

			if(in_array('view', $type)){
				$type[]	= 'hr';
			}
		}

		return in_array($this->type, $type, $strict);
	}

	public function get_schema(){
		return $this->_schema	??= $this->parse_schema();
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
			if($schema && $schema['type'] == 'string'){
				$value	= (string)$value;
			}
		}else{
			if($this->pattern && $this->custom_validity){
				if(!rest_validate_json_schema_pattern($this->pattern, $value)){
					wpjam_throw('rest_invalid_pattern', $this->_title.' '.$this->custom_validity);
				}
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
				$schema	= ['type'=>'array', 'items'=>$this->get_schema_by_item()];
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

					if($this->is('checkbox')){
						$schema	= ['type'=>'array', 'items'=>$schema];
					}
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

		$type	= $schema['type'];

		if($type != 'object'){
			unset($schema['properties']);
		}

		if($type != 'array'){
			unset($schema['items']);
		}

		if(isset($schema['enum'])){
			$cb	= ['integer'=>'intval', 'number'=>'floatval'][$type] ?? 'strval';

			$schema['enum']	= array_map($cb, $schema['enum']);
		}elseif(isset($schema['properties'])){
			$schema['properties']	= array_map([$this, 'parse_schema'], $schema['properties']);
		}elseif(isset($schema['items'])){
			$schema['items']	= $this->parse_schema($schema['items']);
		}

		return $schema;
	}

	protected function parse_options(){
		$options	= $this->call_property('options') ?? $this->options;

		return $this->is('select') ? array_replace(wpjam_array(['all', 'none'], fn($k, $v)=> ($v = $this->pull('show_option_'.$v, false)) === false ? null : [$this->pull('option_'.$v.'_value', ''), $v]), $options) : $options;
	}

	protected function parse_names($prepend=null){
		$fn	= fn($v)=> $v ? ((str_contains($v, '[') && preg_match_all('/\[?([^\[\]]+)\]*/', $v, $m)) ? $m[1] : [$v]) : [];

		return array_merge($fn($prepend ?? $this->pull('prepend_name')), $fn($this->name));
	}

	protected function parse_show_if(...$args){
		$args	= wpjam_parse_show_if($args ? $args[0] : $this->show_if);

		if($args){
			if(isset($args['compare']) || !isset($args['query_arg'])){
				$args	+= ['value'=>true];
			}

			foreach(['postfix', 'prefix'] as $type){
				$args['key']	= wpjam_fix('add', $type, $args['key'], wpjam_pull($args, $type, $this->{'_'.$type}));
			}

			return $args;
		}
	}

	public function show_if($values){
		$args	= $this->parse_show_if();

		return ($args && empty($args['external'])) ? wpjam_if($values, $args) : true;
	}

	public function validate($value, $for=''){
		$code	= $for ?: 'value';
		$value	??= $this->default;

		if($for == 'parameter' && $this->required && is_null($value)){
			wpjam_throw('missing_'.$code, '缺少参数：'.$this->key);
		}

		if($this->validate_callback){
			$result	= wpjam_try($this->validate_callback, $value);

			if($result === false){
				wpjam_throw('invalid_'.$code, [$this->key]);
			}
		}

		$value	= wpjam_try([$this, 'validate_value'], $value);

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

		if($this->sanitize_callback){
			return wpjam_try($this->sanitize_callback, ($value ?? ''));
		}

		return $value;
	}

	public function validate_value($value){
		if($this->is('mu')){
			$value	= is_array($value) ? wpjam_filter($value, fn($v)=> $v || is_numeric($v), true) : ($value ? wpjam_json_decode($value) : []);
			$value	= (!$value || is_wp_error($value)) ? [] : array_values($value);

			return array_map([$this, 'validate_value_by_item'], $value);
		}elseif($this->custom_input){
			return $this->call_custom('validate', $value);
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
		$value	= null;

		if($args && (!$this->is('view') || is_null($this->value))){
			if($this->value_callback){
				$value	= wpjam_value_callback($this->value_callback, $this->_name, wpjam_get($args, 'id'));
			}else{
				$name	= $this->names[0];

				if(!empty($args['data']) && isset($args['data'][$name])){
					$value	= $args['data'][$name];
				}else{
					$id		= wpjam_get($args, 'id');

					if(!empty($args['value_callback'])){
						$value	= wpjam_value_callback($args['value_callback'], $name, $id);
					}

					if($id && !empty($args['meta_type']) && is_null($value)){
						$value	= wpjam_get_metadata($args['meta_type'], $id, $name);
					}
				}

				$value	= (is_wp_error($value) || is_null($value)) ? null : $this->unpack([$name=>$value]);
			}
		}

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
			'data-wrap_id'	=> $this->is('select') ? null : $this->id.'_options'
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

			return $field->attr(['title'=>'', 'name'=>$this->name])->wrap('span');
		}

		return $value;
	}

	public function wrap($tag, $args=[]){
		$data	= ['show_if'=>$this->parse_show_if()];
		$class	= array_filter([$this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')]);
		$class	= array_merge($class, wp_parse_list($this->pull('wrap_class') ?: []), wp_parse_list(wpjam_get($args, 'wrap_class') ?: []));
		$tag	= $tag ?: (($data['show_if'] || $class) ? 'span' : '');
		$wrap	= wpjam_tag($tag, ['class'=>$class, 'id'=>$tag.'_'.$this->id])->data($data);

		$field	= $this->render($args, false);
		$label	= $this->label();

		if(!empty($args['creator']) && !$args['creator']->is('fields')){
			$wrap->add_class('sub-field');

			if($label){
				$label->add_class('sub-field-label');
				$field->wrap('div', ['sub-field-detail']);
			}
		}

		if($tag == 'tr'){
			$field->wrap('td');

			if($label){
				$label->wrap('th', ['scope'=>'row']);
			}else{
				$field->attr('colspan', 2);
			}
		}elseif($tag == 'p'){
			if($label){
				$label	.= wpjam_tag('br');
			}
		}

		return $wrap->append([$label, $field])->after($this->is('fieldset') ? "\n" : null);
	}

	public function render($args=[], $to_string=true){
		if(is_null($this->class)){
			$this->add_class(array_find(['textarea'=>'large-text', 'text, password, url, email, image, file, mu-image, mu-file'=>'regular-text'], fn($v, $t)=> $this->is($t)));
		}

		$this->value	= $this->value_callback($args);

		if($this->render){
			$tag	= wpjam_wrap($this->call_property('render', $args));
		}elseif($this->is('fieldset')){
			$tag	= $this->render_by_fields($args);
		}elseif($this->is('mu')){
			$tag	= $this->render_mu();
		}else{
			$query	= $this->_data_type ? $this->query_label_by_data_type($this->value, $this) : null;
			$tag	= $this->tag()->after(is_null($query) ? null : $this->query_label($query));
		}

		if($args){
			$tag->before($this->before ? $this->before.'&nbsp;' : '')->after($this->after  ? '&nbsp;'.$this->after : '');

			if($this->buttons){
				$tag->after(' '.implode(' ', wpjam_map($this->buttons, [self::class, 'create'])));
			}

			if($this->before || $this->after || $this->label || $this->buttons){
				$this->label($tag);
			}

			if($this->description){
				$tag->after('p', ['description'], $this->description);
			}

			if($this->is('fieldset')){
				if($this->is('fieldset', true)){
					if($this->summary){
						$tag->before([$this->summary, 'strong'], 'summary')->wrap('details');
					}

					if($this->group){
						$this->add_class('field-group');
					}
				}else{
					$tag->wrap($this->wrap_tag);
				}

				if($this->class || $this->data() || $this->style){
					$tag->wrap('div', ['data'=>$this->data(), 'class'=>$this->class, 'style'=>$this->style])->data('key', $this->key);
				}
			}
		}

		return $to_string ? (string)$tag : $tag;
	}

	protected function render_mu(){
		if($this->is('mu-img, mu-image, mu-file') && !current_user_can('upload_files')){
			$this->disabled	= 'disabled';
		}

		$value		= $this->value ?: [];
		$value		= is_array($value) ? array_values(wpjam_filter($value, fn($v)=> $v || is_numeric($v), true)) : [$value];
		$last		= count($value);
		$value[]	= null;

		if($this->is('mu-select')){
			$this->type			= 'mu-text';
			$this->item_type	= 'select';
		}elseif($this->is('mu-text')){
			$this->item_type	??= 'text';

			if($this->item_type != 'select' && $this->direction == 'row'){
				if(count($value) <= 1){
					$last ++;

					$value[]	= null;
				}

				$this->class	??= 'medium-text';
			}
		}elseif($this->is('mu-img')){
			$this->direction	= 'row';
		}elseif($this->is('mu-image')){
			$this->item_type	= 'image';
		}

		if(!$this->is('mu-fields, mu-img') && $this->max_items && $last >= $this->max_items){
			unset($value[$last]);

			$last --;
		}

		$args		= ['id'=>'', 'name'=>$this->name.'[]'];
		$sortable	= $this->_editable && $this->sortable !== false ? 'sortable' : '';
		$icon		= self::get_icon(($this->direction == 'row' ? 'del_icon' : 'del_btn').','.$sortable);

		foreach($value as $i => $item){
			$args['value']	= $item;

			if($this->is('mu-fields')){
				if($last === $i){
					$item	= $this->render_by_item(['i'=>'{{ data.i }}'])->wrap('script', ['type'=>'text/html', 'id'=>'tmpl-'.md5($this->id)]);
				}else{
					$item	= $this->render_by_item(['i'=>$i, 'item'=>$item]);
				}
			}elseif($this->is('mu-text')){
				if($this->item_type == 'select' && $last === $i){
					$options	= $this->attr_by_item('options');

					if(!in_array('', array_keys($options))){
						$args['options']	= array_replace([''=>['title'=>'请选择', 'disabled', 'hidden']], $options);
					}
				}

				$item	= $this->sandbox_by_item(fn()=> $this->attr($args)->render());
			}elseif($this->is('mu-img')){
				$img	= $item ? wpjam_get_thumbnail($item) : '';
				$img	= $img ? wpjam_tag('img', ['src'=>wpjam_get_thumbnail($item, [200, 200]), 'data-modal'=>$img]) : '';
				$item	= $this->tag($args+['type'=>'hidden'])->before($img);
			}else{
				$item	= $this->tag($args+['type'=>'url']);
			}

			if($last === $i){
				$tag	= wpjam_tag('a', ['new-item button'])->data('item_type', $this->item_type);
				$text	= $this->button_text ?: '添加'.(($this->title && mb_strwidth($this->title) <= 8) ? $this->title : '选项');

				if($this->is('mu-text')){
					$tag->text($text);
				}elseif($this->is('mu-fields')){
					$tag->text($text)->data(['i'=>$i, 'tmpl_id'=>md5($this->id)]);
				}elseif($this->is('mu-img')){
					$tag->data('thumb_args', wpjam_get_thumbnail_args([200, 200]))->add_class(['dashicons', 'dashicons-plus-alt2']);
				}else{
					$tag->text(($this->item_type == 'image' ? '选择图片' : '选择文件').'[多选]');
				}

				$item	.= $tag;
			}

			$items[]	= wpjam_tag('div', ['mu-item', ($this->is('mu-fields') && $this->group === true ? 'field-group' : '')], $item.$icon);
		}

		return wpjam_tag('div', ['id'=>$this->id])->append($items)->data('max_items', $this->max_items)->add_class([$this->type, $sortable, 'direction-'.($this->direction ?: 'column')]);
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
						$data['show_if']	= $this->parse_show_if($v);
					}elseif($k == 'alias'){
						$data['alias']	= wp_parse_list($v);
					}elseif($k == 'class'){
						$class	= [...$class, ...wp_parse_list($v)];
					}elseif($k == 'description'){
						$this->description	.= $v ? wpjam_wrap($v, 'span', ['data-show_if'=>$this->parse_show_if([$this->key, '=', $opt])]) : '';
					}elseif($k == 'options'){
						if($this->is('select')){
							$attr	+= ['label'=>$label];
							$label	= $this->render_options($v ?: []);
						}
					}elseif(!is_array($v)){
						$data[$k]	= $v;
					}
				}
			}

			$value		= $this->value;
			$checked	= false;

			if($this->is('checkbox')){
				$checked	= is_array($value) && in_array((string)$opt, $value);
			}else{
				if(isset($value)){
					if(isset($data['alias']) && in_array($value, $data['alias'])){
						$opt		= $value;
						$checked	= true;
					}else{
						$checked	= $value ? ($opt == $value) : !$opt;
					}
				}else{
					$this->value	= $opt;
				}
			}

			if($this->is('select')){
				$args	= is_array($label) ? ['optgroup', $attr] : ['option', $attr+['value'=>$opt, 'selected'=>$checked]];
				$tag	= wpjam_tag(...$args)->append($label);
			}else{
				$attr	= ['required'=>false, 'checked'=>$checked, 'id'=>$this->id.'_'.$opt, 'value'=>$opt]+$attr;
				$tag 	= $this->tag($attr)->data('wrap_id', $this->id.'_options')->after($label)->wrap('label', ['for'=>$attr['id']]);
			}

			return $tag->data($data)->add_class($class);
		});
	}

	protected function label($tag=null){
		$tag	= $tag ?: ($this->title ? wpjam_wrap($this->title) : null);

		return $tag ? $tag->wrap('label')->attr('for', $this->is('view, mu, fieldset, img, uploader, radio') || ($this->is('checkbox') && $this->options) ? null : $this->id) : null;
	}

	protected function tag($attr=[], $name='input'){
		$tag	= wpjam_tag($name, $this->get_args())->attr($attr)->add_class('field-key-'.$this->key);
		$data	= $tag->pull(['key', 'data_type', 'query_args', 'custom_validity']);

		$tag->data($data)->remove_attr(['default', 'options', 'title', 'names', 'label', 'render', 'before', 'after', 'description', 'item_type', 'max_items', 'min_items', 'unique_items', 'direction', 'group', 'buttons', 'button_text', 'size', 'filterable', 'post_type', 'taxonomy', 'sep', 'fields', 'parse_required', 'show_if', 'show_in_rest', 'column', 'custom_input']);

		if($name == 'input'){
			if(!$tag->inputmode){
				if(in_array($tag->type, ['url', 'tel', 'email', 'search'])){
					$tag->inputmode	= $tag->type;
				}elseif($tag->type == 'number'){
					$tag->inputmode	= $this->is('decimal', $tag->step) ? 'decimal' : 'numeric';
				}
			}
		}else{
			$tag->remove_attr(['type', 'value']);
		}

		return $tag;
	}

	protected function query_label($label){
		return self::get_icon('dismiss')->after($label)->wrap('span')->add_class($this->class)->add_class('query-title');
	}

	public function affix($affix_by, $args=[]){
		$prepend	= $affix_by->name;
		$prefix		= $affix_by->key.'__';
		$postfix	= '';

		if($affix_by->is('mu-fields')){
			$prepend	.= '['.$args['i'].']';
			$postfix	= $this->_postfix = '__'.$args['i'];

			if(isset($args['item'][$this->name])){
				$this->value	= $args['item'][$this->name];
			}
		}

		$this->names	= $this->parse_names($prepend);
		$this->_prefix	= $prefix.$this->_prefix ;
		$this->id		= $prefix.$this->id.$postfix;
		$this->key		= $prefix.$this->key.$postfix;

		return $this;
	}

	public static function get_icon($name){
		return array_reduce(wp_parse_list($name), fn($i, $n)=> wpjam_tag(...([
			'sortable'	=> ['span', ['dashicons', 'dashicons-menu']],
			'multiply'	=> ['span', ['dashicons', 'dashicons-no-alt']],
			'dismiss'	=> ['span', ['dashicons', 'dashicons-dismiss']],
			'del_btn'	=> ['a', ['button', 'del-item'], '删除'],
			'del_icon'	=> ['a', ['dashicons', 'dashicons-no-alt', 'del-item']],
			'del_img'	=> ['a', ['dashicons', 'dashicons-no-alt', 'del-img']],
		][$n]))->before($i), '');
	}

	public static function add_pattern($key, $args){
		wpjam_add_item('pattern', $key, $args);
	}

	public static function parse($field){
		$field['options']	= wpjam_get($field, 'options') ?: [];
		$field['type']		= wpjam_get($field, 'type') ?: array_find(['options'=>'select', 'label'=>'checkbox', 'fields'=>'fieldset'], fn($v, $k)=> !empty($field[$k])) ?: 'text';

		return $field;
	}

	public static function create($args, $key=''){
		if($key && !is_numeric($key)){
			$args['key']	= $key;
		}

		if(empty($args['key'])){
			trigger_error('Field 的 key 不能为空');
			return;
		}elseif(is_numeric($args['key'])){
			trigger_error('Field 的 key「'.$args['key'].'」'.'不能为纯数字');
			return;
		}

		$total	= wpjam_pull($args, 'total');

		if($total){
			$args['max_items']	??= $total;
		}

		$field	= self::process($args);

		if(!empty($field['size'])){
			$size	= $field['size'] = wpjam_parse_size($field['size']);

			if(!isset($field['description']) && !empty($size['width']) && !empty($size['height'])){
				$field['description']	= '建议尺寸：'.$size['width'].'x'.$size['height'];
			}
		}

		if(empty($field['buttons']) && !empty($field['button'])){
			$field['buttons']	= [$field['button']];
		}

		$field['id']	= wpjam_get($field, 'id') ?: $field['key'];
		$field['name']	= wpjam_get($field, 'name') ?: $field['key'];

		$field	= self::parse($field);
		$type	= $field['type'];

		if(in_array($type, ['fieldset', 'fields'])){
			if(!empty($field['data_type'])){
				$field['fieldset_type']	= 'array';
			}

			if(wpjam_pull($field, 'fields_type') == 'size'){	// compat
				$type	= 'size';

				$field['fieldset_type']	??= '';
			}
		}

		if(!empty($field['pattern'])){
			$pattern	= wpjam_get_item('pattern', $field['pattern']);
			$field		= array_merge($field, ($pattern ?: []));
		}

		if($type == 'color'){
			$field['data-button_text']	= wpjam_pull($field, 'button_text');

			if(wpjam_get($field, 'data-alpha-enabled')){
				$field	+= ['data-alpha-color-type'=>'octohex',	'data-alpha-custom-width'=>20];
			}
		}elseif($type == 'timestamp'){
			$field['sanitize_callback']	= fn($value)=> $value ? wpjam_strtotime($value) : 0;

			$field['render']	= fn()=> $this->tag(['type'=>'datetime-local', 'value'=>wpjam_date('Y-m-d\TH:i', ($this->value ?: ''))]);
		}elseif($type == 'size'){
			$field['type']			= 'fields';
			$field['fieldset_type']	??= 'array';
			$field['fields']		= wpjam_array(wpjam_merge([
				'width'		=> ['type'=>'number',	'class'=>'small-text'],
				'x'			=> ['type'=>'view',		'value'=>self::get_icon('multiply')],
				'height'	=> ['type'=>'number',	'class'=>'small-text']
			], ($field['fields'] ?? [])), fn($k, $v)=> !empty($v['key']) ? $v['key'] : $k);
		}elseif($type == 'hr'){
			$field['render']	= fn()=> wpjam_tag('hr');
		}elseif(in_array($type, ['radio', 'select', 'checkbox'])){
			$field['render']	??= function(){
				if($this->is('checkbox')){
					if(!$this->options){
						return $this->tag(['value'=>1, 'checked'=>($this->value == 1)])->after($this->label ?? $this->pull('description'));
					}

					$this->name	.= '[]';
				}

				$custom	= $this->custom_input ? $this->call_custom('render', $this->value) : '';
				$items	= $this->render_options($this->options);

				if($this->is('select')){
					return $this->tag([], 'select')->append($items)->after($custom ? '&emsp;'.$custom : '');
				}

				$sep	= $this->sep ?: '';
				$dir	= $this->direction ?: ($sep ? '' : 'row');

				return wpjam_tag('span', ['id'=>$this->id.'_options'], implode($sep, array_filter([...array_values($items), $custom])))->data('max_items', $this->max_items)->add_class($dir ? 'direction-'.$dir : '');
			};
		}elseif(in_array($type, ['textarea', 'editor'])){
			$field['render']	??= function(){
				$this->cols	??= 50;

				if($this->is('editor') && user_can_richedit()){
					$this->rows	??= 12;
					$this->id	= 'editor_'.$this->id;

					if(!wp_doing_ajax()){
						return wpjam_tag('div', ['style'=>$this->style], wpjam_ob_get_contents('wp_editor', ($this->value ?: ''), $this->id, [
							'textarea_name'	=> $this->name,
							'textarea_rows'	=> $this->rows
						]));
					}

					$this->data('editor', ['tinymce'=>true, 'quicktags'=>true, 'mediaButtons'=>current_user_can('upload_files')]);
				}else{
					$this->rows	??= 6;
				}

				return $this->tag([], 'textarea')->append(esc_textarea(implode("\n", (array)$this->value)));
			};
		}elseif(in_array($type, ['img', 'image', 'file', 'uploader'])){
			$field['render']	??= function(){
				if(!current_user_can('upload_files')){
					$this->disabled	='disabled';
				}

				if($this->is('img, image, file')){
					$data	= ['item_type'=>($this->is('image') ? 'image' : $this->item_type)];
					$button	= wpjam_tag('a', ['button add-media'], $this->button_text ?: '选择'.($this->is('file') ? '文件' : '图片'));
					$tag	= $this->tag(['type'=>($this->is('img') ? 'hidden' : 'url')])->after($button)->wrap('div', ['wpjam-'.$this->type])->data($data);

					if($this->is('img')){
						$button	= $button->prepend('span', ['dashicons', 'dashicons-admin-media']);
						$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);
						$src	= $this->value ? wpjam_get_thumbnail($this->value, $size) : '';
						$img	= wpjam_tag('img', array_filter(['src'=>$src]+wpjam_fill(['width', 'height'], fn($k)=> (int)($size[$k]/2))));

						return $tag->data('thumb_args', wpjam_get_thumbnail_args($size))->prepend([self::get_icon('del_img'), $img]);
					}

					return $tag;
				}

				$drap_drop	= $this->pull('drap_drop');
				$mime_types	= $this->pull('mime_types') ?: ['title'=>'图片', 'extensions'=>'jpeg,jpg,gif,png'];
				$component	= wpjam_tag('div', ['id'=>'plupload_container__'.$this->key, 'class'=>['hide-if-no-js', 'plupload']])->data('key', $this->key);
				$btn_attr	= ['type'=>'button', 'class'=>'button', 'id'=>'plupload_button__'.$this->key, 'value'=>($this->button_text ?: __('Select Files'))];
				$plupload	= [
					'browse_button'		=> $btn_attr['id'],
					'container'			=> $component->attr('id'),
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
				];

				$title	= $this->value ? array_slice(explode('/', $this->value), -1)[0] : '';
				$tag	= $this->tag(['type'=>'hidden'])->after($this->query_label($title))->before('input', $btn_attr);

				if($drap_drop && !wp_is_mobile()){
					$dd_id		= 'plupload_drag_drop__'.$this->key;
					$plupload	+= ['drop_element'=>$dd_id];

					$component->add_class('drag-drop');

					$tag->wrap('p', ['drag-drop-buttons'])->before([
						['p', [], _x('or', 'Uploader: Drop files here - or - Select Files')],
						['p', ['drag-drop-info'], __('Drop files to upload')]
					])->wrap('div', ['drag-drop-inside'])->wrap('div', ['id'=>$dd_id, 'class'=>'plupload-drag-drop']);
				}

				return $component->data('plupload', $plupload)->append([$tag, wpjam_tag('div', ['progress', 'hidden'])->append([['div', ['percent']], ['div', ['bar']]])]);
			};
		}elseif($type == 'view'){
			$field['render']	??= function($args){
				$value	= $this->value;
				$tag	= $this->tag ?? ($value != strip_tags($value) ? '' : 'span');

				if($this->options){
					$result	= array_find($this->_options, fn($v, $k)=> $value ? $k == $value : !$k);
					$value	= $result === false ? $value : $result;
				}

				return $tag ? wpjam_tag($tag, ['field-key-'.$this->key], $value)->data('value', $this->value) : $value;
			};
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

	private function __construct($fields, $creator=null){
		$this->fields	= $fields ?: [];
		$this->creator	= $creator;
	}

	public function	__call($method, $args){
		$data	= [];

		foreach($this->fields as $field){
			if(in_array($method, ['get_schema', 'get_defaults', 'get_show_if_values'])){
				if(!$field->_editable){
					continue;
				}
			}elseif($method == 'prepare'){
				if(!$field->show_in_rest){
					continue;
				}
			}

			if($field->is('fieldset') && !$field->_data_type){
				$value	= wpjam_try([$field, $method.'_by_fields'], ...$args);
			}else{
				if($method == 'prepare'){
					$value	= $field->pack($field->prepare(...$args));
				}elseif($method == 'get_defaults'){
					$value	= $field->pack($field->value);
				}elseif($method == 'get_show_if_values'){ // show_if 判断基于key，并且array类型的fieldset的key是 ${key}__{$sub_key}
					$item	= wpjam_catch([$field, 'validate'], $field->unpack($args[0]));
					$value	= [$field->key => is_wp_error($item) ? null : $item];
				}elseif($method == 'get_schema'){
					$value	= [$field->_name => $field->get_schema()];
				}elseif(in_array($method, ['prepare_value', 'validate_value'])){
					$item	= $args[0][$field->_name] ?? null;
					$value	= is_null($item) ? [] : [$field->_name => wpjam_try([$field, $method], $item)];
				}else{
					$value	= wpjam_try([$field, $method], ...$args);
				}
			}

			$data	= wpjam_merge($data, $value);
		}

		return $method == 'get_schema' ? ['type'=>'object', 'properties'=>$data] : $data;
	}

	public function	__invoke($args=[]){
		return $this->render($args);
	}

	public function validate($values=null, $for=''){
		$data	= [];
		$values	??= wpjam_get_post_parameter();

		[$if_values, $if_show]	= ($this->creator && $this->creator->_if) ? $this->creator->_if : [$this->get_show_if_values($values), true];

		foreach($this->fields as $field){
			if(!$field->_editable){
				continue;
			}

			$show	= $if_show ? $field->show_if($if_values) : false;

			if($field->is('fieldset')){
				$field->_if	= [$if_values, $show];
				$value		= $field->validate_by_fields($values, $for);
				$validate	= $show && $field->fieldset_type == 'array';
			}else{
				$value		= $values;
				$validate	= true;
			}

			if($validate){
				if($show){
					$value	= $field->unpack($value);
					$value	= $field->validate($value, $for);
				}else{	// 第一次获取的值都是经过 json schema validate 的，可能存在 show_if 的字段在后面
					$value	= $if_values[$field->key] = null;
				}

				$value	= $field->pack($value);
			}

			$data	= wpjam_merge($data, $value);
		}

		return $data;
	}

	public function render($args=[], $to_string=false){
		$creator	= $args['creator'] = $this->creator;

		if($creator){
			$type	= $tag	= '';
			$sep	= $creator->sep ?? "\n";

			if(!$creator->is('fields')){
				$group	= reset($this->fields)->group;
				$tag	= 'div';
			}

			if($creator->is('fieldset') && $creator->fieldset_type == 'array' && is_array($creator->value)){
				$args['data']	= $creator->pack($creator->value);
			}
		}else{
			$sep	= "\n";
			$type	= wpjam_pull($args, 'fields_type', 'table');
			$tag	= wpjam_pull($args, 'wrap_tag', (['table'=>'tr', 'list'=>'li'][$type] ?? $type));
		}

		$fields	= [];

		foreach($this->fields as $field){
			if($creator && !$creator->is('fields') && $field->group != $group){
				[$groups[], $fields, $group]	= [[$group, $fields], [], $field->group];
			}

			if($creator && $creator->is('mu-fields')){
				$fields[]	= $field->sandbox(fn()=> $this->affix($creator, $args)->wrap($tag, ['creator'=>$creator]));
			}else{
				$fields[]	= $field->wrap($tag, $args);
			}
		}

		if($creator && !$creator->is('fields')){
			$fields	= empty($groups) ? $fields : array_map(fn($g)=> wpjam_wrap(implode($sep, $g[1]), (($g[0] && count($g[1]) > 1) ? 'div' : ''), ['field-group']), [...$groups, [$group, $fields]]);

			if(!$creator->group){
				$sep	= "\n";
			}
		}

		$fields	= wpjam_wrap(implode($sep, array_filter($fields)));

		if($type == 'table'){
			$fields->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']);
		}elseif($type == 'list'){
			$fields->wrap('ul');
		}

		return $to_string ? (string)$fields : $fields;
	}

	public function get_parameter($method='POST', $merge=true){
		$data		= wpjam_get_parameter('', [], $method);
		$validated	= $this->validate($data, 'parameter');

		return $merge ? array_merge($data, $validated) : $validated;
	}

	public static function create($fields, $creator=null){
		$args		= [];
		$propertied	= false;

		if($creator){
			if($creator->is('fieldset') && $creator->fieldset_type != 'array'){
				$args	= [
					'prefix'	=> $creator->prefix === true ? $creator->key : $creator->prefix,
					'postfix'	=> $creator->postfix === true ? $creator->key : $creator->postfix,
				];
			}else{
				$propertied	= true;

				if(!$fields){
					wp_die($creator->_title.'fields不能为空');
				}
			}

			$sink	= wp_array_slice_assoc($creator, ['readonly', 'disabled']);
		}

		foreach(self::parse($fields, $args) as $key => $field){
			if(wpjam_get($field, 'show_admin_column') === 'only'){
				continue;
			}

			$object	= WPJAM_Field::create($field, $key);

			if(!$object){
				continue;
			}

			if($propertied){
				if(count($object->names) > 1){
					trigger_error($creator->_title.'子字段不允许[]模式:'.$object->name);

					continue;
				}

				if($object->is('fieldset', true) || $object->is('mu-fields')){
					trigger_error($creator->_title.'子字段不允许'.$object->type.':'.$object->name);

					continue;
				}
			}

			$objects[$key]	= $object;

			if($creator){
				if($creator->is('fieldset')){
					if($creator->fieldset_type == 'array'){
						$object->affix($creator);
					}else{
						$object->show_in_rest	??= $creator->show_in_rest;
					}
				}

				$object->attr($sink);
			}
		}

		return new self($objects ?? [], $creator);
	}

	public static function parse($fields, $args=[]){
		$args	= wp_parse_args($args, ['prefix'=>'', 'postfix'=>'', 'flat'=>false]);
		$parsed	= [];
		$fields	= (array)($fields ?: []);
		$length	= count($fields);

		for($i=0; $i<$length; $i++){
			$key	= array_keys($fields)[$i];
			$field	= WPJAM_Field::parse($fields[$key]);

			if($field['type'] == 'fields' && wpjam_get($field, 'fieldset_type') != 'array'){	// 向下传递
				$field['prefix']	= $args['prefix'];
				$field['postfix']	= $args['postfix'];
			}else{
				$key	= wpjam_join('_', [$args['prefix'], $key, $args['postfix']]);
			}

			if(in_array($field['type'], ['fieldset', 'fields'])){
				$_fields	= $args['flat'] && wpjam_get($field, 'fieldset_type') != 'array' ? $field['fields'] : [];
			}elseif($field['type'] == 'checkbox' && !$field['options']){
				$_fields	= wpjam_map((wpjam_pull($field, 'fields') ?: []), fn($v)=> $v+(isset($v['show_if']) ? [] : ['show_if'=>[$key, '=', 1]]));
			}else{
				$_fields	= wpjam_flatten($field['options'], 'options', fn($item, $opt)=> (is_array($item) && isset($item['fields'])) ? wpjam_map($item['fields'], fn($v)=> $v+(isset($v['show_if']) ? [] : ['show_if'=>[$key, '=', $opt]])) : null);
			}

			$parsed[$key]	= $field;

			if($_fields){
				$fields	= wpjam_add_at($fields, $i+1, $_fields);
				$length	= count($fields);
			}
		}

		return $parsed;
	}
}