<?php
class WPJAM_Attr extends WPJAM_Args{
	public function __toString(){
		return (string)$this->render();
	}

	public function jsonSerialize(){
		return $this->render();
	}

	public function attr($key, ...$args){
		if(is_array($key)){
			foreach($key as $k => $v){
				$this->$k = $v;
			}

			return $this;
		}elseif($args){
			$this->$key	= $args[0];

			return $this;
		}

		return $this->$key;
	}

	public function remove_attr($key){
		return $this->delete_arg($key);
	}

	public function val(...$args){
		return $args ? $this->attr('value', $args[0]) : $this->value;
	}

	public function data(...$args){
		$data	= wpjam_array($this->get_args(), fn($k)=> str_starts_with($k, 'data-') ? wpjam_remove_prefix($k, 'data-') : null);
		$data	= array_merge(wpjam_array($this->data), $data);

		if(!$args){
			return $data;
		}elseif(count($args) == 1 && !is_array($args[0])){
			return $data[$args[0]] ?? null;
		}else{
			$update	= is_array($args[0]) ? $args[0] : [$args[0]=>$args[1]];

			wpjam_map($update, fn($v, $k)=> $this->remove_attr('data-'.$k));

			return $this->attr('data', array_merge($data, $update));
		}
	}

	protected function class($action='', ...$args){
		$class	= wp_parse_list($this->class ?: []);

		if(!$action){
			return array_filter($class);
		}

		$name	= $args[0];

		if($action == 'has'){
			return in_array($name, $class);
		}

		$names	= wp_parse_list($name ?: []);

		if($action == 'add'){
			$class	= array_merge($class, $names);
		}elseif($action == 'remove'){
			$class	= array_diff($class, $names);
		}elseif($action == 'toggle'){
			$added	= array_diff($names, $class);
			$class	= array_diff($class, $names);
			$class	= array_merge($class, $added);
		}

		return $this->attr('class', $class);
	}

	public function has_class($name){
		return $this->class('has', $name);
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
		$cb	= fn($s)=> $s ? array_values(array_filter(wpjam_map((array)$s, fn($v, $k)=> $v && !is_numeric($k) ? $k.':'.$v : $v))) : [];

		if($args){
			if($args[0]){
				return $this->attr('style', [...$cb($this->style), ...$cb($args[0])]);
			}

			return $this;
		}else{
			return wpjam_map($cb($this->style), fn($v)=> $v ? rtrim($v, ';').';' : '');
		}
	}

	public function render(){
		if($this->pull('__data')){
			return $this->render_data($this->get_args());
		}

		$attr	= [];
		$class	= $this->class();
		$style	= $this->style();
		$data	= $this->data();
		$value	= $this->val();
		$args	= wpjam_except($this->get_args(), ['class', 'style', 'data', 'value']);

		foreach(self::process($args) as $k => $v){
			if(str_ends_with($k, '_callback')
				|| str_ends_with($k, '_column')
				|| str_starts_with($k, 'column_')
				|| str_starts_with($k, '_')
				|| (!$v && !is_numeric($v))
			){
				continue;
			}

			if(str_starts_with($k, 'data-')){
				$data[$k]	= $v;
			}elseif(is_scalar($v)){
				if(in_array($k, ['readonly', 'disabled'])){
					$class[]	= $k;
				}

				$attr[$k]	= $v;
			}else{
				trigger_error($k.' '.var_export($v, true));
			}
		}

		$attr	+= isset($value) ? ['value'=>$value] : [];
		$attr	+= wpjam_map(array_filter(['class'=>$class, 'style'=>$style]), fn($v)=> implode(' ', array_unique($v)));

		return ' '.implode(' ', wpjam_map($attr, fn($v, $k)=> $k.'="'.esc_attr($v).'"')).($data ? $this->render_data($data) : '');
	}

	protected function render_data($data){
		foreach($data as $k => $v){
			if(isset($v) && $v !== false){
				$v	= is_scalar($v) ? esc_attr($v) : ($k == 'data' ? http_build_query($v) : wpjam_json_encode($v));
				$k	= str_starts_with($k, 'data-') ? $k : 'data-'.$k;

				$items[]	= $k.'=\''.$v.'\'';
			}
		}

		return !empty($items) ? ' '.implode(' ', $items) : '';
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
					if(is_array($args[0])){
						foreach(array_filter($args[0]) as $item){
							if(is_array($item)){
								$this->$method(...$item);
							}else{
								$this->$method($item);
							}
						}

						return $this;
					}

					$value	= $args[0];
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

		$render	= fn($k)=> $this->{'_'.$k} ? implode('', $this->{'_'.$k}) : '';
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
		$this->args			= $args;
		$this->options		= $this->call_property('options') ?? $this->options;
		$this->_data_type	= wpjam_get_data_type_object($this);

		$this->parse_name();
	}

	public function __get($key){
		$value	= parent::__get($key);

		if(!is_null($value)){
			return $value;
		}

		if($key == '_editable'){
			return $this->show_admin_column !== 'only' && !$this->disabled && !$this->readonly && !$this->is('view');
		}elseif($key == '_name'){
			return array_reverse($this->names)[0];
		}elseif($key == '_title'){
			return $this->title.'「'.$this->key.'」';
		}elseif($key == '_options'){
			return $this->parse_options();
		}elseif($key == '_fields'){
			return $this->_fields = WPJAM_Fields::create($this->fields, $this);
		}elseif($key == '_item'){
			if($this->type == 'mu-fields'){
				return $this->_fields;
			}

			$args	= wpjam_except($this->get_args(), ['required', 'show_in_rest']);
			$type	= $this->type == 'mu-text' ? $this->item_type : wpjam_remove_prefix($this->type, 'mu-');

			return $this->_item = self::create(array_merge($args, ['type'=>$type]));
		}
	}

	public function __call($method, $args){
		if(str_contains($method, '_by_')){
			[$method, $type]	= explode('_by', $method);

			return $this->$type ? wpjam_try([$this->$type, $method], ...$args) : array_shift($args);
		}

		trigger_error($method);
	}

	public function is($type, $strict=false){
		$type	= wp_parse_list($type);

		if(!$strict){
			if(in_array('mu', $type)){
				if(is_a($this, 'WPJAM_MU_Field')){
					return true;
				}
			}

			if(in_array('mu-checkbox', $type)){
				if($this->is('checkbox') && $this->options){
					return true;
				}
			}

			if(in_array('fieldset', $type)){
				$type[]	= 'fields';
			}

			if(in_array('view', $type)){
				$type	= ['hr', ...$type];
			}
		}

		return in_array($this->type, $type, $strict);
	}

	public function get_schema(){
		return $this->_schema	??= $this->parse_schema();
	}

	public function set_schema($schema){
		return $this->update_arg('_schema', $schema);
	}

	protected function call_schema($action, $value){
		$schema	= $this->get_schema();

		if($action == 'sanitize'){
			if(!$schema){
				return $value;
			}

			if($schema && $schema['type'] == 'string'){
				$value	= (string)$value;
			}
		}else{
			if(!$schema){
				return true;
			}

			if($this->pattern && $this->custom_validity){
				if(!rest_validate_json_schema_pattern($this->pattern, $value)){
					wpjam_throw('rest_invalid_pattern', $this->_title.' '.$this->custom_validity);
				}
			}
		}

		return wpjam_try('rest_'.$action.'_value_from_schema', $value, $schema, $this->_title);
	}

	protected function prepare_schema(){
		$schema	= ['type'=>'string'];

		if($this->is('email')){
			$schema['format']	= 'email';
		}elseif($this->is('color')){
			$schema['format']	= 'hex-color';
		}elseif($this->is('url')){
			$schema['format']	= 'uri';
		}elseif($this->is('number, range')){
			$step	= $this->step ?: '';

			if($step == 'any' || strpos($step, '.')){
				$schema['type']	= 'number';
			}else{
				$schema['type']	= 'integer';

				if($step > 1){
					$schema['multipleOf']	= $step;
				}
			}
		}elseif($this->is('checkbox')){
			$schema['type']	= 'boolean';
		}

		return $schema;
	}

	protected function parse_schema(...$args){
		if($args){
			$schema	= $args[0];
		}else{
			$schema	= $this->prepare_schema();
			$map	= [];

			if(in_array($schema['type'], ['number', 'integer'])){
				$map	= [
					'minimum'	=> 'min',
					'maximum'	=> 'max',
					'patter'	=> 'pattern',
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

			$schema		= $schema+array_filter(array_map(fn($v)=> $this->$v, $map), fn($v)=> !is_null($v));
			$_schema	= $this->show_in_rest('schema');
			$_type		= $this->show_in_rest('type');

			if(is_array($_schema)){
				$schema	= wpjam_merge($schema, $_schema);
			}

			if($_type){
				if($schema['type'] == 'array' && $_type != 'array'){
					$schema['items']['type']	= $_type;
				}else{
					$schema['type']	= $_type;
				}
			}

			if($this->required && !$this->show_if){	// todo 以后可能要改成 callback
				$schema['required']	= true;
			}
		}

		$type	= $schema['type'];

		if($type != 'object'){
			unset($schema['properties']);
		}elseif($type != 'array'){
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

	public function parse_options($type='label', ...$args){
		$parsed	= [];

		foreach(($args ? $args[0] : $this->options) as $opt => $item){
			if(is_array($item)){
				if(isset($item['options'])){
					$parsed	= array_replace($parsed, $this->parse_options($type, $item['options']));
				}else{
					if($type == 'label'){
						$key	= wpjam_find(['title', 'label', 'image'], fn($k)=> !empty($item[$k]));

						if($key){
							$parsed[$opt]	= $item[$key];
						}
					}elseif($type == 'fields'){
						foreach(($item['fields'] ?? []) as $key => $field){
							if(isset($field['show_if'])){
								$parsed[$key]	= $field;
							}elseif(isset($parsed[$key])){
								$parsed[$key]['show_if'][2][]	= $opt;
							}else{
								$parsed[$key]	= $field+['show_if'=>[$this->key, 'IN', [$opt]]];
							}
						}
					}
				}
			}else{
				if($type == 'label'){
					$parsed[$opt]	= $item;
				}
			}
		}

		return $parsed;
	}

	protected function parse_show_if(...$args){
		$args	= $args ? $args[0] : $this->show_if;

		if(!is_array($args)){
			return;
		}

		$args	= wpjam_parse_show_if($args);

		if(empty($args['key'])){
			return;
		}

		if(isset($args['compare']) || !isset($args['query_arg'])){
			$args	+= ['value'=>true];
		}

		foreach(['postfix', 'prefix'] as $type){
			$args['key']	= wpjam_fix('add', $type, $args['key'], wpjam_pull($args, $type, $this->{'_'.$type}));
		}

		return $args;
	}

	protected function parse_name(...$args){
		$name	= $args ? $args[0] : (string)$this->pull('prepend_name');
		$names	= $name ? ((str_contains($name, '[') && preg_match_all('/\[?([^\[\]]+)\]*/', $name, $m)) ? $m[1] : [$name]) : [];
		$arr	= (str_contains($this->name, '[') && preg_match_all('/\[?([^\[\]]+)\]*/', $this->name, $m)) ? $m[1] : [$this->name];

		$this->names	= array_merge($names, $arr);
		$this->name		= array_reduce($this->names, fn($name, $n)=> $name.($name ? '['.$n.']' : $n), '');
	}

	public function show_in_rest($key=null){
		$value	= $this->show_in_rest ?? $this->_editable;

		return $key ? (is_array($value) ? wpjam_get($value, $key) : null) : $value;
	}

	public function show_if($values){
		$args	= $this->parse_show_if();

		return (is_array($args) && !empty($args['key']) && empty($args['external'])) ? wpjam_if($values, $args) : true;
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
		return $this->validate_value_by_data_type($value, $this);
	}

	protected function before_schema($value, $schema=null){
		if(!$schema){
			$schema	= $this->get_schema();

			if(!$schema){
				return $value;
			}

			$value	??= !empty($schema['required']) ? false : $value;
		}

		$type	= $schema['type'];

		if($type == 'array'){
			if(is_array($value)){
				$value	= array_map(fn($v)=> $this->before_schema($v, $schema['items']), $value);
			}
		}elseif($type == 'object'){
			if(is_array($value)){
				$value	= wpjam_map($value, fn($v, $k)=> isset($schema['properties'][$k]) ? $this->before_schema($v, $schema['properties'][$k]) : $v);
			}
		}elseif($type == 'integer'){
			if(is_numeric($value)){
				$value	= (int)$value;
			}
		}elseif($type == 'number'){
			if(is_numeric($value)){
				$value	= (float)$value;
			}
		}elseif($type == 'string'){
			if(is_scalar($value)){
				$value	= (string)$value;
			}
		}elseif($type == 'null'){
			if(!$value && !is_numeric($value)){
				$value	= null;
			}
		}elseif($type == 'boolean'){
			if(is_scalar($value) || is_null($value)){
				$value	= rest_sanitize_boolean($value);
			}
		}

		return $value;
	}

	public function pack($value){
		return array_reduce(array_reverse($this->names), fn($v, $n)=> [$n=>$v], $value);
	}

	public function unpack($data){
		return _wp_array_get($data, $this->names);
	}

	public function value_callback($args=[]){
		$value	= null;

		if($args && (!$this->is('view') || is_null($this->value))){
			if($this->value_callback){
				$value	= wpjam_value_callback($this->value_callback, $this->_name, array_get($args, 'id'));
				$value	= is_wp_error($value) ? null : $value;
			}else{
				$name	= $this->names[0];

				if(!empty($args['data']) && isset($args['data'][$name])){
					$value	= $args['data'][$name];
				}else{
					$id		= array_get($args, 'id');

					if(!empty($args['value_callback'])){
						$value	= wpjam_value_callback($args['value_callback'], $name, $id);
					}

					if($id && !empty($args['meta_type']) && (is_wp_error($value) || is_null($value))){
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
		return $this->prepare_value_by_data_type($value, $this);
	}

	public function wrap($tag, $args=[]){
		$class	= [$this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')];
		$data 	= ['show_if'=>$this->parse_show_if()];
		$tag	= $tag ?: ($data['show_if'] ? 'span' : '');
		$field	= $this->render($args, false);
		$label	= $this->label();

		if(!empty($args['creator']) && !$args['creator']->is('fields')){
			$class[]	= 'sub-field';

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

		return $field->before($label)->wrap($tag, ['class'=>$class, 'id'=>$tag.'_'.esc_attr($this->id)])->data($data)->add_class($this->wrap_class)->add_class(wpjam_get($args, 'wrap_class'));
	}

	public function render($args=[], $to_string=true){
		if(is_null($this->class)){
			if($this->is('textarea')){
				$this->add_class('large-text');
			}elseif($this->is('text, password, url, email, image, file, mu-image, mu-file')){
				$this->add_class('regular-text');
			}
		}

		$this->class	= $this->class();
		$this->value	= $this->value_callback($args);

		if($this->render){
			$tag	= wpjam_wrap($this->call_property('render', $args));
		}else{
			$tag	= $this->is('fieldset') ? $this->render_by_fields($args) : $this->render_component();
		}

		if($args){
			$tag->before($this->before ? $this->before.' ' : '')->after($this->after  ? ' '.$this->after : '');

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
				}

				if($this->class || $this->data() || $this->style){
					$tag->wrap('div', ['data'=>$this->data(), 'class'=>$this->class, 'style'=>$this->style])->data('key', $this->key);
				}
			}
		}

		return $to_string ? (string)$tag : $tag;
	}

	protected function render_component(){
		if($this->is('editor, textarea')){
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

			return $this->tag([], 'textarea', esc_textarea(implode("\n", (array)$this->value)));
		}else{
			$query	= $this->_data_type ? $this->query_label_by_data_type($this->value, $this) : null;

			return !is_null($query) ? $this->tag()->after($this->query_label($query)) : $this->tag();
		}
	}

	protected function label($tag=null){
		$tag	= $tag ?: ($this->title ? wpjam_wrap($this->title) : null);

		return $tag ? $tag->wrap('label', ['for'=>$this->is('view, mu, fieldset, img, uploader, radio, mu-checkbox') ? null : $this->id]) : null;
	}

	protected function tag($attr=[], $name='input', $text=''){
		$tag	= wpjam_tag($name, $this->get_args(), $text)->attr($attr)->add_class('field-key-'.$this->key);

		$tag->data($tag->pull(['key', 'data_type', 'query_args', 'custom_validity']))->delete_arg(['default', 'options', 'title', 'names', 'label', 'render', 'before', 'after', 'description', 'item_type', 'max_items', 'min_items', 'unique_items', 'direction', 'group', 'buttons', 'button_text', 'custom_input', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'mime_types', 'drap_drop', 'parse_required', 'show_if', 'show_in_rest', 'column', 'wrap_class']);

		if($name == 'input'){
			if(!isset($tag['inputmode'])){
				if(in_array($tag['type'], ['url', 'tel', 'email', 'search'])){
					$tag['inputmode']	= $tag['type'];
				}elseif($tag['type'] == 'number'){
					$tag['inputmode']	= ($tag['step'] == 'any' || strpos($tag['step'] ?: '', '.')) ? 'decimal' : 'numeric';
				}
			}
		}else{
			$tag->delete_arg(['type', 'value']);
		}

		return $tag;
	}

	protected function query_label($label){
		return self::get_icon('dismiss')->after($label)->wrap('span', [...$this->class, 'query-title']);
	}

	public function affix($affix_by, $i=null, $item=null){
		$prepend	= $affix_by->name;
		$prefix		= $affix_by->key.'__';
		$postfix	= '';

		if(isset($i)){
			$prepend	.= '['.$i.']';
			$postfix	= $this->_postfix = '__'.$i;

			if(is_array($item) && isset($item[$this->name])){
				$this->value	= $item[$this->name];
			}
		}

		$this->parse_name($prepend);

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

		$field['options']	= array_get($field, 'options') ?: [];
		$field['id']		= array_get($field, 'id') ?: $field['key'];
		$field['name']		= array_get($field, 'name') ?: $field['key'];

		if(empty($field['type'])){
			$field['type']	= wpjam_find(['options'=>'select', 'label'=>'checkbox', 'fields'=>'fieldset'], fn($v, $k)=> !empty($field[$k])) ?: 'text';
		}

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
			$field		= array_merge($field, ($pattern ? $pattern : []));
		}

		if(in_array($type, ['image', 'mu-image'])){
			$field['item_type']	= 'image';
		}elseif($type == 'mu-text'){
			$field['item_type']	??= 'text';

			if(!isset($field['class']) && $field['item_type'] != 'select'){
				$field['class']	= array_get($field, 'direction') == 'row' ? 'medium-text' : 'regular-text';
			}
		}elseif($type == 'mu-select'){
			$field['type']		= 'mu-text';
			$field['item_type']	= 'select';
		}elseif($type == 'checkbox'){
			if(!$field['options']){
				if(!isset($field['label']) && !empty($field['description'])){
					$field['label']	= wpjam_pull($field, 'description');
				}

				$field['render']	= fn()=> $this->tag(['value'=>1, 'checked'=>($this->value == 1)])->after($this->label);
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
		}elseif($type == 'view'){
			$field['render']	= function($args){
				$tag	= $args['tag'] ?? 'span';
				$value	= $this->value;

				if($this->options){
					if($value){
						$value	= $this->_options[$value] ?? $value;
					}else{
						$result	= wpjam_find($this->_options, fn($v, $k)=> !$k);
						$value	= $result === false ? $value : $result;
					}
				}

				return wpjam_tag($tag, ['field-key-'.$this->key], $value)->data('value', $this->value);
			};
		}

		if(in_array($type, ['select', 'radio']) || ($type == 'checkbox' && $field['options'])){
			return new WPJAM_Options_Field($field);
		}elseif(in_array($type, ['img', 'image', 'file'])){
			return new WPJAM_Image_Field($field);
		}elseif($type == 'uploader'){
			return new WPJAM_Uploader($field);
		}elseif(str_starts_with($type, 'mu-')){
			return new WPJAM_MU_Field($field);
		}

		return new WPJAM_Field($field);
	}
}

class WPJAM_Options_Field extends WPJAM_Field{
	protected function call_custom($action, $value){
		$values	= array_map('strval', array_keys($this->_options));
		$input	= $this->custom_input;

		if($input){
			$field	= $this->_custom;

			if(is_null($field)){
				$title	= is_string($input)	? $input : '其他';
				$custom	= is_array($input)	? $input : [];
				$field	= $this->_custom = self::create($custom+[
					'title'			=> $title,
					'placeholder'	=> '请输入其他选项',
					'id'			=> $this->id.'__custom_input',
					'key'			=> $this->key.'__custom_input',
					'type'			=> 'text',
					'class'			=> '',
					'required'		=> true,
					'show_if'		=> [$this->key, '__custom'],
				]);

				if(!$this->is('select')){
					$field->data('wrap_id', $this->id.'_options');
				}
			}

			if($action == 'render'){
				$value	= $this->value;
			}elseif($action == 'checked'){
				return !is_null($field->value);
			}

			if($this->is('checkbox')){
				$value	= $value ?: [];
				$value	= array_diff($value, ['__custom']);
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
				if(!in_array($value, $values)){
					$field->val($value);

					if($action == 'validate'){
						$field->set_schema($this->get_schema())->validate($value);
					}
				}
			}

			if($action == 'render'){
				$this->options	+= ['__custom'=>$field->title];

				return $field->attr(['title'=>'', 'name'=>$this->name])->wrap('span');
			}
		}else{
			if($action == 'prepare_schema'){
				$value	+= ['enum'=>$values];
			}
		}

		return $value;
	}

	protected function prepare_schema(){
		$schema	= $this->call_custom('prepare_schema', ['type'=>'string']);

		return $this->is('checkbox') ? ['type'=>'array', 'items'=>$schema] : $schema;
	}

	public function prepare_value($value){
		return $this->call_custom('prepare', $value);
	}

	public function validate_value($value){
		return $this->call_custom('validate', $value);
	}

	protected function render_component(){
		if($this->is('checkbox')){
			$this->name	.= '[]';
		}

		$custom	= $this->call_custom('render', '');
		$items	= $this->render_options($this->options);

		if($this->is('select')){
			return $this->tag([], 'select', implode('', $items))->after($custom ? '&emsp;'.$custom : '');
		}else{
			$dir	= $this->direction ?: ($this->sep ? '' : 'row');
			$sep	= $this->sep ?? ($dir ? '' : '&emsp;');

			return wpjam_tag('span', ['id'=>$this->id.'_options'], implode($sep, $items).($custom ? $sep.$custom : ''))->data('max_items', $this->max_items)->add_class($dir ? 'direction-'.$dir : '')->add_class($this->is('checkbox') ? 'mu-checkbox' : '');
		}
	}

	protected function render_options($options, $value=null){
		$value	??= $this->value;

		foreach($options as $opt => $label){
			$attr	= $data = $class = [];

			if(is_array($label)){
				$arr	= $label;
				$label	= wpjam_pull($arr, ['label', 'title']);
				$label	= $label ? reset($label) : '';
				$image	= wpjam_pull($arr, 'image');

				if($image){
					$image	= is_array($image) ? array_slice($image, 0, 2) : [$image];
					$label	= implode('', array_map(fn($i)=> wpjam_tag('img', ['src'=>$i, 'alt'=>$label]), $image)).$label;
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
					}elseif($k == 'class'){
						$class	= [...$class, ...wp_parse_list($v)];
					}elseif($k == 'description'){
						$this->description	.= wpjam_wrap($v, 'span', ['data-show_if'=>$this->parse_show_if([$this->key, '=', $opt])]);
					}elseif($k == 'options'){
						$attr[$k]	= $v;
					}elseif(!is_array($v)){
						$data[$k]	= $v;
					}
				}
			}

			if($opt === '__custom'){
				$checked	= $this->call_custom('checked', false);
			}else{
				if($this->is('checkbox')){
					$checked	= is_array($value) && in_array($opt, $value);
				}else{
					$value 		??= $opt;
					$checked	= $value ? ($opt == $value) : !$opt;
				}
			}

			if($this->is('select')){
				$sub	= wpjam_pull($attr, 'options');

				if(isset($sub)){
					$sub	= $sub ? implode('', $this->render_options($sub, $value)) : '';
					$tag	= wpjam_tag('optgroup', $attr, $sub)->attr('label', $label);
				}else{
					$tag	= wpjam_tag('option', $attr, $label)->attr(['value'=>$opt, 'selected'=>$checked]);
				}
			}else{
				$attr	= ['required'=>false, 'checked'=>$checked, 'id'=>$this->id.'_'.$opt, 'value'=>$opt]+$attr;
				$tag 	= $this->tag($attr)->data('wrap_id', $this->id.'_options')->after($label)->wrap('label', ['for'=>$attr['id']]);
			}

			$items[]	= $tag->data($data)->add_class($class);
		}

		return $items ?? [];
	}
}

class WPJAM_Image_Field extends WPJAM_Field{
	protected function prepare_schema(){
		return ($this->is('img') && $this->item_type != 'url') ? ['type'=>'integer'] : ['type'=>'string', 'format'=>'uri'];
	}

	public function prepare_value($value){
		return wpjam_get_thumbnail($value, $this->size);
	}

	protected function render_component(){
		if(!current_user_can('upload_files')){
			$this->attr('disabled', 'disabled');
		}

		if($this->is('img')){
			$size	= $this->size ?: '600x0';
			$size	= wpjam_parse_size($size, [600, 600]);
			$attr	= array_filter(['width'=>(int)($size['width']/2), 'height'=>(int)($size['height']/2)]);
			$data	= ['item_type'=>$this->item_type, 'thumb_args'=>wpjam_get_thumbnail_args($size)];
			$img	= $this->value ? wpjam_get_thumbnail($this->value, $size) : '';
			$img	= wpjam_tag('img', $attr)->attr('src', $img)->add_class($img ? '' : 'hidden');
			$button	= wpjam_tag('span', ['wp-media-buttons-icon'])->after($this->button_text ?: '添加图片')->wrap('button', ['button', 'add_media'])->wrap('div', ['wp-media-buttons']);

			return $this->tag(['type'=>'hidden'])->before($img.$button.self::get_icon('del_img'), 'div', ['class'=>'wpjam-img', 'data'=>$data]);
		}else{
			$title	= '选择'.($this->is('image') ? '图片' : '文件');

			return $this->tag(['type'=>'url'])->after('a', ['class'=>'button', 'data'=>['item_type'=>$this->item_type]], $title)->wrap('div', ['wpjam-file']);
		}
	}
}

class WPJAM_Uploader extends WPJAM_Field{
	protected function render_component(){
		if(!current_user_can('upload_files')){
			$this->attr('disabled', 'disabled');
		}

		$component	= wpjam_tag('div', ['id'=>'plupload_container__'.$this->key, 'class'=>['hide-if-no-js', 'plupload']])->data('key', $this->key);
		$mime_types	= $this->mime_types ?: ['title'=>'图片', 'extensions'=>'jpeg,jpg,gif,png'];
		$btn_attr	= ['type'=>'button', 'class'=>'button', 'id'=>'plupload_button__'.$this->key, 'value'=>($this->button_text ?: __('Select Files'))];
		$plupload	= [
			'browse_button'		=> $btn_attr['id'],
			'container'			=> $component->attr('id'),
			'file_data_name'	=> $this->key,
			'filters'			=> [
				'mime_types'	=> wp_is_numeric_array($mime_types) ? $mime_types : [$mime_types],
				'max_file_size'	=> (wp_max_upload_size()?:0).'b'
			],
			'multipart_params'	=> [
				'_ajax_nonce'	=> wp_create_nonce('upload-'.$this->key),
				'action'		=> 'wpjam-upload',
				'file_name'		=> $this->key,
			]
		];

		$title	= $this->value ? array_slice(explode('/', $this->value), -1)[0] : '';
		$tag	= $this->tag(['type'=>'hidden'])->after($this->query_label($title))->before('input', $btn_attr);

		if($this->drap_drop && !wp_is_mobile()){
			$dd_id		= 'plupload_drag_drop__'.$this->key;
			$plupload	+= ['drop_element'=>$dd_id];

			$component->add_class('drag-drop');

			$tag->wrap('p', ['drag-drop-buttons'])->before([
				['p', [], _x('or', 'Uploader: Drop files here - or - Select Files')],
				['p', ['drag-drop-info'], __('Drop files to upload')]
			])->wrap('div', ['drag-drop-inside'])->wrap('div', ['id'=>$dd_id, 'class'=>'plupload-drag-drop']);
		}

		return $component->data('plupload', $plupload)->append([$tag, wpjam_tag('div', ['progress', 'hidden'])->append([['div', ['percent']], ['div', ['bar']]])]);
	}

	public static function ajax_response($data){
		if(check_ajax_referer('upload-'.$data['file_name'], false, false)){
			return wpjam_upload($data['file_name']);
		}

		wp_die('invalid_nonce');
	}
}

class WPJAM_MU_Field extends WPJAM_Field{
	protected function prepare_schema(){
		return ['type'=>'array', 'items'=>$this->get_schema_by_item()];
	}

	public function prepare_value($value){
		return array_map([$this, 'prepare_value_by_item'], $value);
	}

	public function validate_value($value){
		$value	= is_array($value) ? wpjam_filter($value, fn($v)=> $v || is_numeric($v), true) : ($value ? wpjam_json_decode($value) : []);
		$value	= (!$value || is_wp_error($value)) ? [] : array_values($value);

		return array_map([$this, 'validate_value_by_item'], $value);
	}

	protected function render_component(){
		if($this->is('mu-img, mu-image, mu-file') && !current_user_can('upload_files')){
			$this->disabled	= 'disabled';
		}

		$value	= $this->value ?: [];
		$value	= is_array($value) ? array_values(wpjam_filter($value, fn($v)=> $v || is_numeric($v), true)) : [$value];
		$last	= count($value);
		$value[]= null;

		if($this->is('mu-text')){
			if(count($value) <= 1 && $this->direction == 'row' && $this->item_type != 'select'){
				$last ++;

				$value[]	= null;
			}
		}elseif($this->is('mu-img')){
			$this->direction	= 'row';
		}

		if(!$this->is('mu-fields, mu-img') && $this->max_items && $last >= $this->max_items){
			unset($value[$last]);

			$last --;
		}

		$args	= ['id'=>'', 'name'=>$this->name.'[]'];
		$items	= [];

		$sortable	= $this->_editable ? ($this->sortable ?? true) : false;
		$sortable	= $sortable ? 'sortable' : '';

		foreach($value as $i => $item){
			$args['value']	= $item;

			if($this->is('mu-fields')){
				if($last === $i){
					$item	= $this->render_by_fields(['i'=>'{{ data.i }}']);
					$item	= $item->wrap('script', ['type'=>'text/html', 'id'=>'tmpl-'.md5($this->id)]);
				}else{
					$item	= $this->render_by_fields(['i'=>$i, 'item'=>$item]);
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
				$thumb	= wpjam_get_thumbnail($item, [200, 200]);
				$item	= $this->tag($args+['type'=>'hidden']);

				if($img){
					$item->before('a', ['href'=>$img, 'class'=>'wpjam-modal'], ['img', ['src'=>$thumb]]);
				}
			}else{
				$item	= $this->tag($args+['type'=>'url']);
			}

			$icon	= ($this->direction == 'row' ? 'del_icon' : 'del_btn').','.$sortable;
			$item	.= self::get_icon($icon);

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
					$title	= $this->item_type == 'image' ? '选择图片' : '选择文件';

					$tag->text($title.'[多选]')->data('title', $title);
				}

				$item	.= $tag;
			}

			$items[]	= wpjam_tag('div', ['mu-item', ($this->group ? 'field-group' : '')], $item);
		}

		return wpjam_tag('div', ['id'=>$this->id], implode("\n", $items))->data('max_items', $this->max_items)->add_class([$this->type, $sortable, 'direction-'.($this->direction ?: 'column')]);
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
				if(!$field->show_in_rest()){
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

		if($method == 'get_schema'){
			return ['type'=>'object', 'properties'=>$data];
		}

		return $data;
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
				$tag	= 'div';
				$group	= reset($this->fields)->group;
				$last	= array_key_last($this->fields);

				if($creator->is('mu-fields')){
					$i		= wpjam_pull($args, 'i');
					$item	= wpjam_pull($args, 'item');
				}
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

		foreach($this->fields as $key => $field){
			if($field->show_admin_column === 'only'){
				continue;
			}

			if($creator && !$creator->is('fields')){
				if($field->group != $group){
					[$groups[], $wrappeds[], $group, $wrapped]	= [$group, $wrapped, $field->group, []];
				}

				if($creator->is('mu-fields')){
					$wrapped[]	= $field->sandbox(fn()=> $this->affix($creator, $i, $item)->wrap($tag, $args));
				}else{
					$wrapped[]	= $field->wrap($tag, $args);
				}

				if($last == $key){
					[$groups[], $wrappeds[]]	= [$group, $wrapped];

					$fields		= array_map(fn($w, $g)=> wpjam_wrap(implode($sep, $w), ($g ? 'div' : ''), ['field-group']), $wrappeds, $groups);

					if(!$creator->group){
						$sep	= "\n";
					}
				}
			}else{
				$fields[]	= $field->wrap($tag, $args);
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
		$fields		= $fields ?: [];
		$objects	= [];
		$prefix		= $postfix = $prepend = '';
		$propertied	= false;

		if($creator){
			if($creator->is('fieldset')){
				if($creator->fieldset_type == 'array'){
					$propertied	= true;
				}else{
					$prefix		= $creator->prefix === true ? $creator->key : $creator->prefix;
					$postfix	= $creator->postfix === true ? $creator->key : $creator->postfix;
					$prepend	= $creator->prepend_name;
				}
			}elseif($creator->is('mu-fields')){
				$propertied	= true;
			}

			$sink	= wp_array_slice_assoc($creator, ['readonly', 'disabled']);
		}

		if($propertied && !$fields){
			wp_die($creator->_title.'fields不能为空');
		}

		$fields	= (array)$fields;
		$keys	= array_keys($fields);
		$length	= count($keys);

		for($i=0; $i < $length; $i++){
			$key	= $keys[$i];
			$field	= $fields[$key];

			if(array_get($field, 'type') == 'fields' && array_get($field, 'fieldset_type') != 'array'){	// 向下传递
				$field['prefix']	= $prefix;
				$field['postfix']	= $postfix;
			}else{
				$key	= wpjam_join('_', [$prefix, $key, $postfix]);
			}

			if($prepend && !isset($field['prepend_name'])){
				$field['prepend_name']	= $prepend;
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

			if($object->type == 'checkbox' && !$object->options){
				$_fields	= wpjam_map(($object->fields ?: []), fn($field)=> $field+['show_if'=>[$object->key, '=', 1]]);
			}else{
				$_fields	= $object->parse_options('fields');
			}

			if($_fields){
				$fields	= wpjam_add_at($fields, $i+1, $_fields);
				$keys	= array_keys($fields);
				$length	= count($keys);
			}
		}

		return new self($objects, $creator);
	}

	public static function flatten($fields){
		$parsed	= [];

		foreach(($fields ?: []) as $key => $field){
			if(array_get($field, 'type') == 'fieldset' && array_get($field, 'fieldset_type') != 'array'){
				$parsed	= array_merge($parsed, $field['fields']);
			}else{
				$parsed[$key]	= $field;
			}
		}

		return $parsed;
	}
}