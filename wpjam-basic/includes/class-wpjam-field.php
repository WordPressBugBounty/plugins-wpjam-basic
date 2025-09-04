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
			return wpjam_reduce($key, fn($c, $v, $k)=> $c->attr($k, $v), $this);
		}

		return $args ? $this->update_arg($key, is_closure($args[0]) ? $this->bind_if_closure($args[0])($this->get_arg($key)) : $args[0]) : $this->get_arg($key);
	}

	public function remove_attr($key){
		return $this->delete_arg($key);
	}

	public function val(...$args){
		return $this->attr('value', ...$args);
	}

	public function data(...$args){
		if(!$args){
			return array_merge(wpjam_array($this->data), wpjam_array($this, fn($k)=> try_remove_prefix($k, 'data-') ? $k : null));
		}

		$key	= $args[0];
		$args[0]= is_array($key) ? wpjam_array($key, fn($k)=> 'data-'.$k) : 'data-'.$key;

		return $this->attr(...$args) ?? (wpjam_array($this->data)[$key] ?? null);
	}

	public function remove_data($key){
		$keys	= wp_parse_list($key);

		return array_reduce($keys, fn($c, $k)=> $c->remove_attr('data-'.$k), $this->attr('data', wpjam_except(wpjam_array($this->data), $keys)));
	}

	public function class($action='', ...$args){
		$args	= array_map(fn($v)=> wp_parse_list($v ?: []), [$this->class, ...$args]);
		$cb		= $action ? ['add'=>'array_merge', 'remove'=>'array_diff', 'toggle'=>'wpjam_toggle'][$action] : '';

		return $cb ? $this->attr('class', $cb(...$args)) : $args[0];
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

	public function style(...$args){
		if($args){
			$args	= count($args) <= 1 || is_array($args[0]) ? (array)$args[0] : [[$args[0]=>$args[1]]];

			return $this->attr('style', array_merge(wpjam_array($this->style), $args));
		}

		return wpjam_reduce($this->style, fn($c, $v, $k)=> ($v || is_numeric($v)) ? [...$c, rtrim(is_numeric($k) ? $v : $k.':'.$v, ';').';'] : $c, []);
	}

	public function render(){
		[$data, $attr]	= $this->pull('__data') ? [$this, []] : [$this->data(), self::process($this->add_class($this->pick(['readonly', 'disabled'])))];

		return wpjam_reduce($attr, function($c, $v, $k){
			if($k == 'data'
				|| array_any(['_callback', '_column'], fn($e)=> str_ends_with($k, $e))
				|| array_any(['_', 'column_', 'data-'], fn($s)=> str_starts_with($k, $s))
				|| ($k == 'value' ? is_null($v) : (!$v && !is_numeric($v)))
			){
				return $c;
			}

			if(in_array($k, ['style', 'class'])){
				$v	= implode(' ', array_unique($this->$k()));
			}elseif(!is_scalar($v)){
				trigger_error($k.' '.var_export($v, true));
			}

			return $c.' '.$k.'="'.esc_attr($v).'"';
		}).wpjam_reduce($data, function($c, $v, $k){
			$v	= ($k == 'show_if' ? wpjam_parse_show_if($v) : $v) ?? false;

			return $c.($v === false ? '' : ' data-'.$k.'=\''.(is_scalar($v) ? esc_attr($v) : ($k == 'data' ? http_build_query($v) : wpjam_json_encode($v))).'\'');
		});
	}

	public static function is_bool($key){
		return in_array($key, ['allowfullscreen', 'allowpaymentrequest', 'allowusermedia', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'default', 'defer', 'disabled', 'download', 'formnovalidate', 'hidden', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected', 'typemustmatch']);
	}

	public static function accept_to_mime_types($accept){
		if($accept){
			$allowed	= get_allowed_mime_types();
			$types		= [];

			foreach(wpjam_lines($accept, ',', fn($v)=> strtolower($v)) as $v){
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
		return new static(($attr && is_string($attr) ? shortcode_parse_atts($attr) : wpjam_array($attr))+($type == 'data' ? ['__data'=>true] : []));
	}
}

class WPJAM_Tag extends WPJAM_Attr{
	public function __construct($tag='', $attr=[], $text=''){
		$this->init($tag, $attr, $text);
	}

	public function __call($method, $args){
		if(in_array($method, ['text', 'tag', 'before', 'after', 'prepend', 'append'])){
			$key	= '_'.$method;

			if(!$args){
				return $this->$key;
			}

			if($key == '_tag'){
				return $this->update_arg($key, $args[0]);
			}

			$value	= count($args) > 1 ? new self(...(is_array($args[1]) ? $args : [$args[1], ($args[2] ?? []), $args[0]])) : $args[0];

			if(is_array($value)){
				return array_reduce($value, fn($c, $v)=> $c->$method(...(is_array($v) ? $v : [$v])), $this);
			}

			if($key == '_text'){
				$this->$key	= (string)$value;
			}elseif($value){
				$this->$key	= in_array($key, ['_before', '_prepend']) ? [$value, ...$this->$key] : [...$this->$key, $value];
			}

			return $this;
		}elseif(in_array($method, ['insert_before', 'insert_after', 'append_to', 'prepend_to'])){
			$args[0]->{str_replace(['insert_', '_to'], '', $method)}($this);

			return $this;
		}

		trigger_error($method);
	}

	public function is($tag){
		return array_intersect([$this->_tag, $this->_tag === 'input' ? ':'.$this->type : null], wp_parse_list($tag));
	}

	public function init($tag, $attr, $text){
		$attr		= $attr ? (wp_is_numeric_array((array)$attr) ? ['class'=>$attr] : $attr) : [];
		$this->args	= array_fill_keys(['_before', '_after', '_prepend', '_append'], [])+['_tag'=>$tag]+$attr;

		return $text && is_array($text) ? $this->text(...$text) : $this->text($text || is_numeric($text) ? $text : '');
	}

	public function render(){
		$tag	= $this->update_args(['a'=>['href'=>'javascript:;'], 'img'=>['title'=>$this->alt]][$this->_tag] ?? [], false)->_tag;
		$text	= $this->is_single($tag) ? [] : [...$this->_prepend, (string)$this->_text, ...$this->_append];
		$tag	= $tag ? ['<'.$tag.parent::render(), ...($text ? ['>', ...$text, '</'.$tag.'>'] : [' />'])] : $text;

		return implode([...$this->_before, ...$tag, ...$this->_after]);
	}

	public function wrap($tag, ...$args){
		$wrap	= $tag && str_contains($tag, '></');
		$tag	= $wrap ? (preg_match('/<(\w+)([^>]+)>/', ($args ? sprintf($tag, ...$args) : $tag), $matches) ? $matches[1] : '') : $tag;

		return $tag ? $this->init($tag, $wrap ? shortcode_parse_atts($matches[2]) : ($args[0] ?? []), clone($this)) : $this;
	}

	public static function is_single($tag){
		return $tag && in_array($tag, ['area', 'base', 'basefont', 'br', 'col', 'command', 'embed', 'frame', 'hr', 'img', 'input', 'isindex', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
	}
}

class WPJAM_Field extends WPJAM_Attr{
	protected function __construct($args){
		$this->args	= $args;
		$this->init();

		$this->options		= maybe_callback($this->bind_if_closure($this->options));
		$this->_data_type	= wpjam_get_data_type_object($this);

		$this->pattern && $this->attr(wpjam_pattern($this->pattern) ?: []);
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

			$args	= wpjam_except($this->get_args(), 'required');
			$type	= $this->is('mu-text') ? $this->item_type : substr($this->type, 3);

			return $this->$key = self::create(array_merge($args, ['type'=>$type]));
		}elseif($key == '_options'){
			$value	= $this->is('select') ? array_reduce(['all', 'none'], fn($c, $k)=> $c+array_filter([($this->{'option_'.$k.'_value'} ?? '') => $this->{'show_option_'.$k}]), []) : [];

			return wpjam_reduce($this->options, function($carry, $item, $opt){
				if(!is_array($item)){
					$carry[$opt]	= $item;
				}elseif(!isset($item['options'])){
					$k		= array_find(['title', 'label', 'image'], fn($k)=> isset($item[$k]));
					$carry	= $k ? array_replace($carry, [$opt => $item[$k]], empty($item['alias']) ? [] : array_fill_keys(wp_parse_list($item['alias']), $item[$k])) : $carry;
				}

				return $carry;
			}, $value, 'options');
		}elseif($key == '_schema'){
			if($this->is('mu')){
				$schema	= $this->schema_by_item();
				$schema	= ['type'=>'array', 'items'=>($this->is('mu-fields') ? ['type'=>'object', 'properties'=>$schema] : $schema)];
			}else{
				$schema	= array_filter(['type'=>$this->get_arg('show_in_rest.type')])+($this->get_schema_by_data_type() ?: []);

				if($this->is('email')){
					$schema	+= ['format'=>'email'];
				}elseif($this->is('color')){
					$schema	+= $this->data('alpha-enabled') ? [] : ['format'=>'hex-color'];
				}elseif($this->is('url, image, file, img')){
					$schema	+= (($this->is('img') && $this->item_type != 'url') ? ['type'=>'integer'] : ['format'=>'uri']);
				}elseif($this->is('number, range')){
					$step	= $this->step ?: '';
					$type	= ($step == 'any' || strpos($step, '.')) ? 'number' : 'integer';
					$schema	+= ['type'=>$type]+(($type == 'integer' && $step > 1) ? ['multipleOf'=>$step] : []);
				}elseif($this->is('radio, select, checkbox')){
					if($this->is('checkbox') && !$this->options){
						$schema	+= ['type'=>'boolean'];
					}else{
						$schema	+= ['type'=>'string']+($this->_custom ? [] : ['enum'=>array_keys($this->_options)]);
						$schema	= $this->is('checkbox') ? ['type'=>'array', 'items'=>$schema] : $schema;
					}
				}
			}

			$schema	+= ['type'=>'string', 'required'=>($this->required && !$this->show_if)];
			$schema	+= wpjam_array((array_fill_keys(['integer', 'number'], ['pattern'=>'pattern', 'minimum'=>'min', 'maximum'=>'max'])+[
				'array'		=> ['maxItems'=>'max_items', 'minItems'=>'min_items', 'uniqueItems'=>'unique_items'],
				'string'	=> ['pattern'=>'pattern', 'minLength'=>'minlength', 'maxLength'=>'maxlength'],
			])[$schema['type']] ?? [] , fn($k, $v)=> isset($this->$v) ? [$k, $this->$v]: null);

			return $this->$key = $this->schema('parse', $schema);
		}elseif($key == '_custom'){
			return ($input	= $this->custom_input) ? ($this->$key = self::create((is_array($input) ? $input : [])+[
				'title'			=> is_string($input) ? $input : '其他',
				'placeholder'	=> '请输入其他选项',
				'id'			=> $this->id.'__custom_input',
				'key'			=> $this->key.'__custom_input',
				'type'			=> 'text',
				'class'			=> '',
				'required'		=> true,
				'show_if'		=> [$this->key, '__custom'],
			])) : null;
		}
	}

	public function __call($method, $args){
		[$method, $type]	= explode('_by', $method)+['', ''];

		if(!$type){
			return;
		}

		$by		= $this->$type;
		$value	= $args ? $args[0] : null;

		if($type == '_schema'){
			if(!$by){
				return $method == 'validate' ? true : $value;
			}

			if($method == 'prepare'){
				return $this->schema($method, $by, $value);
			}

			$value	= $method == 'sanitize' && $by['type'] == 'string' ? (string)$value : $value;

			return wpjam_try('rest_'.$method.'_value_from_schema', $value, $by, $this->_title);
		}elseif($type == '_custom'){
			if(!$by){
				return $method == 'render' ? '' : $value;
			}

			$options	= array_map('strval', array_keys($this->_options));

			if($this->is('checkbox')){
				$value	= array_diff($value ?: [], ['__custom']);
				$diff	= array_diff($value, $options);
				$custom = $diff ? reset($diff) : null;
			}else{
				$custom = isset($value) && !in_array($value, $options) ? $value : null;
			}

			$is	= isset($custom);
			$is && $by->val($custom);

			if($method == 'render'){
				$this->value	= $is ? ($this->is('checkbox') ? [...$value, '__custom'] : '__custom') : $value;
				$this->options	+= ['__custom'=>$by->pull('title')];

				return $by->attr('name', $this->name)->wrap();
			}elseif($method == 'validate'){
				isset($diff) && count($diff) > 1 && wpjam_throw('too_many_custom_value', $by->_title.'只能传递一个其他选项值');

				$is && $by->schema(wpjam_get($this->schema(), isset($diff) ? 'items' : null))->validate($custom);
			}

			return $value;
		}elseif($type == '_data_type'){
			if(!$by){
				return $method == 'query_label' ? null : $value;
			}

			if(is_array($value) && $this->multiple && str_ends_with($method, '_value')){
				return array_map(fn($v)=> wpjam_try([$by, $method], $v, $this), $value);
			}

			array_push($args, $this);
		}

		return $by ? wpjam_try([$by, $method], ...$args) : $value;
	}

	protected function init($prepend=null){
		$names	= array_filter([$prepend ?? $this->pull('prepend_name'), $this->name]);
		$names	= array_reduce($names, fn($c, $v)=> [...$c, ...(str_contains($v, '[') && preg_match_all('/\[?([^\[\]]+)\]*/', $v, $m) ? $m[1] : [$v])], []);

		$this->names	= $names;
		$this->_name	= wpjam_at($names, -1);
		$this->name		= array_shift($names).($names ? '['.implode('][', $names).']' : '');

		return $this;
	}

	public function is($type, $strict=false){
		$type	= wp_parse_list($type);

		return (in_array('mu', $type) && str_starts_with($this->type, 'mu-')) || in_array($this->type, $type, $strict) || (!$strict && in_array($this->type, wpjam_pick(['fieldset'=>'fields', 'view'=>'hr'], $type)));
	}

	public function schema(...$args){
		if(count($args) <= 1){
			return $args ? $this->attr('_schema', ...$args) : $this->_schema;
		}

		$action	= $args[0];
		$schema	= $args[1];

		if($action == 'parse'){
			foreach([
				'enum'			=> [fn($value)=> array_map(fn($v)=> rest_sanitize_value_from_schema($v, $schema), $value)],
				'properties'	=> [fn($value)=> array_map(fn($v)=> $this->schema('parse', $v), $value), 'object'],
				'items'			=> [fn($value)=> $this->schema('parse', $value), 'array'],
			] as $k => $v){
				if(isset($schema[$k])){
					if(isset($v[1]) && $schema['type'] != $v[1]){
						unset($schema[$k]);
					}else{
						$schema[$k]	= $v[0]($schema[$k]);
					}
				}
			}

			return $schema;
		}elseif($action == 'prepare'){
			$value	= is_null($args[2]) && !empty($schema['required']) ? false : $args[2];
			$cb		= [
				'array'		=> ['is_array', fn($value)=> wpjam_map($value, fn($v)=> $this->schema('prepare', $schema['items'], $v))],
				'object'	=> ['is_array', fn($value)=> wpjam_map($value, fn($v, $k)=> $this->schema('prepare', ($schema['properties'][$k] ?? ''), $v))],
				'integer'	=> ['is_numeric', 'intval'],
				'number'	=> ['is_numeric', 'floatval'],
				'string'	=> ['is_scalar', 'strval'],
				'null'		=> [fn($v)=> !$v && !is_numeric($v), fn()=> null],
				'boolean'	=> [fn($v)=> is_scalar($v) || is_null($v), 'rest_sanitize_boolean']
			][$schema['type']] ?? '';

			return $cb && $cb[0]($value) ? $cb[1]($value) : $value;
		}
	}

	public function show_if(...$args){
		if($args = wpjam_parse_show_if($args[0] ?? $this->show_if)){
			return ($this->_creator && $this->_creator->_fields->get($args['key']) ? ['key'=>$this->_prefix.$args['key'].$this->_suffix] : [])+$args+['value'=>true];
		}
	}

	public function validate($value, $for=''){
		if($for == 'value'){
			if($this->is('mu')){
				$value	= wpjam_filter(is_array($value) ? $value : wpjam_if_error(wpjam_json_decode($value), []), fn($v)=> $v || is_numeric($v), true);

				return array_map(fn($v)=> $this->validate_by_item($v, 'value'), array_values($value));
			}

			$value	= $this->validate_by_custom($value);
			$value	= $this->is('checkbox') && $this->options ? ($value ?: []) : $value;

			return $this->try('validate_value_by_data_type', $value);
		}

		if($for == 'parameter'){
			$value	??= $this->default;

			$this->required && is_null($value) && wpjam_throw('missing_parameter', '缺少参数：'.$this->key);
		}

		$cb	= $this->validate_callback;
		$cb	&& wpjam_try($cb, $value) === false && wpjam_throw('invalid_'.($for ?: 'value'), [$this->key]);

		$value	= $this->validate($value, 'value');

		if(!$this->is('fieldset') || $this->_data_type){
			if($for == 'parameter'){
				$value	= is_null($value) ? $value : $this->sanitize_by_schema($value);
			}else{
				$this->required && !$value && !is_numeric($value) && wpjam_throw(($for ?: 'value').'_required', [$this->_title]);

				$value	= $this->prepare_by_schema($value);

				if($value || is_array($value) || is_numeric($value)){	// 空值只需 required 验证
					$this->pattern && !rest_validate_json_schema_pattern($this->pattern, $value) && wpjam_throw('rest_invalid_pattern', wpjam_join(' ', [$this->_title, $this->custom_validity]));

					$this->validate_by_schema($value);
				}
			}
		}

		return ($cb	= $this->sanitize_callback) ? wpjam_try($cb, ($value ?? '')) : $value;
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

	public function prepare($args, $for=''){
		if($for == 'value'){
			if($this->is('mu')){
				return array_map(fn($v)=> $this->prepare_by_item($v, $for), $args);
			}elseif($this->is('img, image, file')){
				return wpjam_get_thumbnail($args, $this->size);
			}

			return $this->prepare_value_by_data_type($args);
		}

		return $this->prepare($this->sanitize_by_schema($this->value_callback($args)), 'value');
	}

	public function affix($args=[]){
		$creator	= $this->_creator;
		$prefix		= $this->_prefix = $creator->key.'__';
		$suffix		= $this->_suffix = $args ? '__'.$args['i'] : '';
		$prepend	= $creator->name.($args ? '[i'.$args['i'].']' : '');

		$args && isset($args['v'][$this->name]) && $this->val($args['v'][$this->name]);

		$this->init($prepend)->data('dep', fn($v)=> $v && $creator->_fields->get($v) ? $prefix.$v.$suffix : null);

		$this->id	= $prefix.$this->id.$suffix;
		$this->key	= $prefix.$this->key.$suffix;
	}

	public function wrap($tag='', $args=[]){
		if(is_object($tag)){
			$group	= $this->group && !$this->is('fields');
			$wrap	= $this->wrap_tag ?: (($this->is('fieldset') && !$group && !$args) ? '' : 'div');

			$group	&& $tag->add_class('field-group');
			$wrap 	&& $tag->wrap($wrap, $args);

			$this->title	&& $wrap == 'fieldset'		&& $tag->prepend('legend', ['screen-reader-text'], $this->title);
			$this->summary 	&& $this->is('fieldset')	&& $tag->before([$this->summary, 'strong'], 'summary')->wrap('details');

			return $tag;
		}

		($creator = $this->_creator) && $creator->is('mu-fields') && $this->affix($args);

		$field	= $this->render($args);
		$wrap	= $tag ? wpjam_tag($tag, ['id'=>$tag.'_'.$this->id])->append($field) : $field;
		$label	= $this->is('view, mu, fieldset, img, uploader, radio') || ($this->is('checkbox') && $this->options) ? [] : ['for'=> $this->id];

		$this->buttons && $this->attr('after', wpjam_join(' ', [$this->after, implode(' ', wpjam_map($this->buttons, [self::class, 'create']))]));

		foreach(['before', 'after'] as $k){
			$this->$k && $field->$k(($field->is('div') || $this->is('textarea,editor') ? 'p' : 'span'), [$k], $this->$k);
		}

		if($this->is('fieldset')){
			$attr	= array_filter($this->pick(['class', 'style'])+['data'=>$this->data()]);
			$attr	= $attr ? wpjam_set($attr, 'data.key', $this->key) : [];

			$this->wrap($field, $attr) && $wrap->after("\n");
		}elseif(!$field->is('div') && ($this->label || $this->before || $this->after || ($creator && $creator->label === true))){
			$field->wrap('label', $label);
		}

		$title	= $this->title ? wpjam_tag('label', $label, $this->title) : '';
		$desc	= (array)$this->description+['', []];
		$desc[0] && $field->after('p', ['class'=>'description', 'data-show_if'=>$this->show_if(wpjam_pull($desc[1], 'show_if'))]+$desc[1], $desc[0]);

		$show_if	= $this->show_if();

		if($creator && !$creator->is('fields')){
			if($creator->wrap_tag == 'fieldset'){
				($title || ($show_if && ($this->is('fields') || !is_null($field->data('query_title'))))) && $field->before($title ? $title.'<br />' : null)->wrap('div', ['inline']);

				$title	= null;
			}else{
				$title && $title->add_class('sub-field-label') && $field->wrap('div', ['sub-field-detail']);

				$wrap->add_class('sub-field');
			}
		}

		if($tag == 'tr'){
			$title && $title->wrap('th', ['scope'=>'row']);

			$field->wrap('td', $title ? [] : ['colspan'=>2]);
		}elseif($tag == 'p'){
			$title && $title->after('<br />');
		}

		$field->before($title);

		return $wrap->add_class([$this->wrap_class, wpjam_get($args, 'wrap_class'), $this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')])->data('show_if', $show_if)->data('for', $wrap === $field ? null : $this->key);
	}

	public function render($args=[]){
		$this->value	= $this->value_callback($args);
		$this->class	??= $this->is('text, password, url, email, image, file, mu-image, mu-file') ? 'regular-text' : null;
		$this->_data	= $this->pull(['filterable', 'summarization', 'show_option_all', 'show_option_none', 'option_all_value', 'option_none_value', 'max_items', 'min_items', 'unique_items']);

		if($this->render){
			return wpjam_wrap($this->bind_if_closure($this->render)($args));
		}elseif($this->is('fieldset')){
			return $this->render_by_fields($args);
		}elseif($this->is('mu')){
			$value	= $this->value ?: [];
			$value	= is_array($value) ? array_values(wpjam_filter($value, fn($v)=> $v || is_numeric($v), true)) : [$value];
			$wrap	= wpjam_tag('div', ['id'=>$this->id])->data($this->_data);
			$class	= ['mu', $this->type, $this->_type, ($this->sortable !== false ? 'sortable' : '')];

			if($this->is('mu-img, mu-image, mu-file')){
				current_user_can('upload_files') || $this->attr('disabled', 'disabled');

				$data['button_text']	= '选择'.($this->is('mu-file') ? '文件' : '图片').'[多选]';
			}else{
				$data['button_text']	= $this->button_text ?: '添加'.(wpjam_between(mb_strwidth($this->title ?: ''), 4, 8) ? $this->title : '选项');
			}

			if($this->is('mu-fields')){
				$data	+= $this->tag_label ? ['tag_label'=>$this->attr(['group'=>true, 'direction'=>'row'])->tag_label] : [];
				$append	= wpjam_map($value+['${i}'=>[]], fn($v, $i)=> $this->wrap($this->render_by_fields(['i'=>$i, 'v'=>$v])->wrap($v ? '' : 'template'), ['mu-item']));
			}else{
				$args	= ['id'=>'', 'name'=>$this->name.'[]', 'value'=>null];
				$data	+= ['value'=>&$value];

				if($this->is('mu-img')){
					$value	= array_map(fn($v)=> ['value'=>$v, 'url'=> wpjam_at(wpjam_get_thumbnail($v), '?', 0)] , $value);
					$data	+= ['thumb_args'=> wpjam_get_thumbnail_args([200, 200]), 'item_type'=>$this->item_type];
					$append	= $this->attr('direction', 'row')->input($args+['type'=>'hidden']);
				}elseif($this->is('mu-image, mu-file')){
					$data	+= ['item_type'=> $this->is('mu-image') ? 'image' : $this->item_type];
					$append	= $this->input($args+['type'=>'url']);
				}elseif($this->is('mu-text')){
					if(($this->item_type ??= 'text') == 'text'){
						$this->direction == 'row' && ($this->class	??= 'medium-text');

						array_walk($value, fn(&$v)=> ($l = $this->query_label_by_data_type($v)) && ($v = ['value'=>$v, 'label'=>$l]));
					}

					$append	= $this->attr_by_item($args)->render();
				}
			}

			return $wrap->append($append)->data($data)->add_class($class)->add_class($this->_type ? '' : 'direction-'.($this->direction ?: 'column'));
		}elseif($this->is('radio, select, checkbox')){
			if($this->is('checkbox')){
				if(!$this->options){
					return $this->_type ? wpjam_tag() : $this->attr('label', $this->label ?? $this->pull('description'))->input(['value'=>1])->data('value', $this->value)->after($this->label);
				}

				$this->name		.= '[]';
				$this->_data	+= $this->pull('required') ? ['min_items'=>1] : [];
			}

			$custom	= $this->render_by_custom($this->value);
			$field	= $this->is('select') ? $this->tag('select') : wpjam_tag('fieldset', ['id'=>$this->id.'_options', 'class'=>['checkable', 'direction-'.($this->direction ?: (($this->_type || $this->sep) ? 'column' : 'row'))]]);

			$field	= wpjam_reduce($this->options, function($carry, $label, $opt, $depth){
				$attr	= [];

				if(is_array($label)){
					$arr	= $label;
					$label	= ($label = wpjam_pull($arr, ['label', 'title'])) ? reset($label) : '';
					$attr	= wpjam_pull($arr, ['class']);

					foreach($arr as $k => $v){
						if(is_numeric($k)){
							self::is_bool($v) && ($attr[$v]	= $v);
						}elseif(self::is_bool($k)){
							$v && ($attr[$k]	= $k);
						}elseif($k == 'description'){
							$v && ($this->description	.= wpjam_tag('span', ['data-show_if'=>$this->show_if([$this->key, '=', $opt])], $v));
						}else{
							$attr['data'][$k]	= $k == 'show_if' ? $this->show_if($v) : $v;
						}
					}

					if(isset($arr['options'])){
						return $carry->append(...($this->is('select') ? ['optgroup', $attr+['label'=>$label]] : ['label', $attr, $label.'<br />']));
					}
				}

				if($this->is('select')){
					$value	= isset($this->value) && in_array($this->value, wp_parse_list($attr['data']['alias'] ?? [])) ? $this->value : $opt;
					$args	= ['option', $attr+['value'=>$value], $label];
				}else{
					if($image	= wpjam_pull($attr, 'data[image]')){
						$attr	= wpjam_set($attr, 'class[]', 'image-'.$this->type);
						$label	= array_reduce(array_slice((array)$image, 0, 2), fn($c, $i)=> $c.wpjam_tag('img', ['src'=>$i, 'alt'=>$label]), '').$label;
					}

					$args	= wpjam_pull($attr, ['data', 'class']);
					$input	= $this->input(['id'=>$this->id.'_'.$opt, 'value'=>$opt]+$attr);
					$args	= ['label', ['for'=>$input->id]+$args, $input.$label];
				}

				($depth >= 1 ? wpjam_at($carry->append(), -1) : $carry)->append(...$args);

				return $carry;
			}, $field->data($this->_data+$this->pull(['data_type', 'query_args'])+$this->pick(['value'])), 'options');

			$custom && ($this->is('select') || $this->_type ? $field->after('&emsp;'.$custom) : $field->append($custom));

			return $this->_type ? $field->add_class([$this->_type, 'hidden'])->data('show_option_all', fn($v)=> $v ?: '请选择')->wrap('div', [$this->_type.'-wrap']) : $field;
		}elseif($this->is('editor, textarea')){
			if($this->is('editor')){
				$this->id	= 'editor_'.$this->id;

				if(user_can_richedit()){
					if(!wp_doing_ajax()){
						return wpjam_wrap(wpjam_ob_get_contents('wp_editor', ($this->value ?: ''), $this->id, ['textarea_name'=>$this->name]));
					}

					$this->data('editor', ['tinymce'=>true, 'quicktags'=>true, 'mediaButtons'=>current_user_can('upload_files')]);
				}
			}

			return $this->tag('textarea')->append(esc_textarea($this->value ?: ''));
		}elseif($this->is('img, image, file')){
			current_user_can('upload_files') || $this->attr('disabled', 'disabled');

			$size	= array_filter(wpjam_pick(wpjam_parse_size($this->size), ['width', 'height']));

			(count($size) == 2) && ($this->description	??= '建议尺寸：'.implode('x', $size));

			if($this->is('img')){
				$type	= 'hidden';
				$size	= wpjam_parse_size($this->size ?: '600x0', [600, 600]);
				$data	= ['thumb_args'=> wpjam_get_thumbnail_args($size), 'size'=>wpjam_array($size, fn($k, $v)=> [$k, (int)($v/2) ?: null], true)];
			}

			return $this->input(['type'=>$type ?? 'url'])->wrap('div', ['wpjam-'.$this->type])->data(($data ?? [])+[
				'value'			=> $this->value ? ['url'=>wpjam_get_thumbnail($this->value), 'value'=>$this->value] : '',
				'item_type'		=> $this->is('image') ? 'image' : $this->item_type,
				'media_button'	=> $this->button_text ?: '选择'.($this->is('file') ? '文件' : '图片')
			]);
		}elseif($this->is('uploader')){
			$mimes	= self::accept_to_mime_types($this->accept ?: 'image/*');
			$exts	= implode(',', array_map(fn($v)=> str_replace('|', ',', $v), array_keys($mimes)));

			$mimes === [] && $this->attr('disabled', 'disabled');

			$plupload	= [
				'browse_button'		=> 'plupload_button__'.$this->key,
				'button_text'		=> $this->button_text ?: __('Select Files'),
				'container'			=> 'plupload_container__'.$this->key,
				'filters'			=> ['max_file_size'=> (wp_max_upload_size() ?: 0).'b']+($exts ? ['mime_types'=> [['extensions'=>$exts]]] : []),
				'file_data_name'	=> $this->key,
				'multipart_params'	=> [
					'_ajax_nonce'	=> wp_create_nonce('upload-'.$this->key),
					'action'		=> 'wpjam-upload',
					'name'			=> $this->key,
					'mimes'			=> $mimes
				]
			]+(($this->pull('drap_drop') && !wp_is_mobile()) ? [
				'drop_element'	=> 'plupload_drag_drop__'.$this->key,
				'drop_info'		=> [__('Drop files to upload'), _x('or', 'Uploader: Drop files here - or - Select Files')]
			] : []);

			return $this->input(['type'=>'hidden'])->wrap('div', ['plupload', $this->disabled])->data(['key'=>$this->key, 'plupload'=>$plupload]);
		}elseif($this->is('view')){
			$value	= (string)$this->value;
			$wrap	= $value != strip_tags($value);
			$tag	= $this->wrap_tag ?? (!$this->show_if && $wrap ? '' : 'span');
			$value	= $this->options && !$wrap ? (array_find($this->_options, fn($v, $k)=> $value ? $k == $value : !$k) ?? $value) : $value;

			return wpjam_wrap($value, $tag, $tag ? ['class'=>'field-key field-key-'.$this->key, 'data'=>['val'=>$this->value, 'name'=>$this->name]] : []);
		}elseif($this->is('hr')){
			return wpjam_tag('hr');
		}else{
			return $this->input()->data(['class'=>$this->class]+array_filter(['label'=>$this->query_label_by_data_type($this->value)]));
		}
	}

	protected function tag($tag='input', $attr=[]){
		$tag	= wpjam_tag($tag, $this->get_args())->attr($attr)->add_class('field-key field-key-'.$this->key);
		$data	= $this->_data+['name'=>$this->_name]+$tag->pull(['key', 'data_type', 'query_args', 'custom_validity']);

		return $tag->data($data)->remove_attr(['default', 'options', 'title', 'names', 'label', 'render', 'before', 'after', 'description', 'wrap_class', 'wrap_tag', 'item_type', 'direction', 'group', 'buttons', 'button_text', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'parse_required', 'show_if', 'show_in_rest', 'column', 'custom_input', ...($tag->is('input') ? [] : ['type', 'value'])]);
	}

	protected function input($attr=[]){
		$tag	= $this->tag('input', $attr);

		if($tag->is(':number, :url, :tel, :email, :search')){
			$tag->inputmode	??= $tag->is(':number') ? (($tag->step == 'any' || strpos($tag->step ?: '', '.')) ? 'decimal': 'numeric') : $tag->type;
		}

		return $tag;
	}

	public static function parse($field){
		$field	= is_string($field) ? ['type'=>'view', 'value'=>$field, 'wrap_tag'=>''] : $field;
		$field	= self::process($field);

		$field['options']	= wpjam_get($field, 'options') ?: [];
		$field['type']		= $type = wpjam_get($field, 'type') ?: (array_find(['options'=>'select', 'label'=>'checkbox', 'fields'=>'fieldset'], fn($v, $k)=> !empty($field[$k])) ?: 'text');

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
			$field['multiple']	= true;
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
		$field	+= array_filter(['max_items'=> wpjam_pull($field, 'total')])+([
			'color'		=> ['label'=>true, 'data-button_text'=>wpjam_pull($field, 'button_text'), 'data-alpha-enabled'=>wpjam_pull($field, 'alpha')],
			'timestamp' => ['sanitize_callback'=> fn($v)=> $v ? wpjam_strtotime($v) : 0]
		][$field['type']] ?? []);

		return new WPJAM_Field($field);
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

	public function __call($method, $args){
		$data	= [];

		foreach($this->fields as $field){
			if($method == 'prepare'){
				if(!$field->show_in_rest){
					continue;
				}

				$args	= $args ?: [$this->args];
			}

			if($field->is('fieldset') && !$field->_data_type){
				$value	= $field->try($method.'_by_fields', ...$args);
			}else{
				if($method == 'schema'){
					$value	= array_filter([$field->_name => $field->schema()]);
				}elseif($method == 'get_defaults'){
					$value	= $field->pack($field->disabled ? null : $field->value);
				}elseif($method == 'get_if_values'){ // show_if 基于key，且propertied的fieldset的key是 {$key}__{$sub_key}
					$value	= $field->_editable ? $field->catch('validate', $field->unpack($args[0])) : ($field->disabled ? null : $field->value_callback($args[1]));
					$value	= [$field->key => wpjam_if_error($value, null)];
				}elseif($method == 'prepare'){
					$_args	= count($args) == 2 && $args[1] == 'value' ? [$args[0][$field->_name] ?? null, $args[1]] : $args;
					$value	= is_null($_args[0]) ? [] : $field->pack($field->prepare(...$_args));
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
		if($for == 'value'){
			[$if_values, $if_show]	= [$values, true];	// todo type=view ?? 
		}else{
			$values	??= wpjam_get_post_parameter();

			[$if_values, $if_show]	= ($this->creator->_if ?? []) ?: [$this->get_if_values($values, $this->args)+$values, true];
		}

		$data	= [];

		foreach(wpjam_filter($this->fields, ['_editable'=>true]) as $field){
			$show	= $if_show && (($show_if = $field->show_if()) ? wpjam_match($if_values, $show_if) : true);
			$value	= $field->is('fieldset') ? $field->attr('_if', [$if_values, $show])->validate_by_fields($values, $for) : $values;

			if(!$field->is('fieldset') || ($show && $field->propertied)){
				$value	= $show ? $field->unpack($value) : null;
				$value	= is_null($value) && $for == 'value' ? [] : $field->pack($show ? $field->validate($value, $for) : null);

				$show || $for == 'value' || ($if_values[$field->key] = null); // 第一次获取的值都是经过 json schema validate 的，可能存在 show_if 的字段在后面
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
			$tag	= $creator->is('fields') ? '' : (is_null($creator->wrap_tag) ? 'div' : '');
			$sep	= $creator->sep ??= ($creator->wrap_tag == 'fieldset' ? ($creator->group ? '' : '<br />') : '')."\n";
			$group	= reset($this->fields)->group;
			$args	+= $creator->is('fieldset') && $creator->propertied && is_array($creator->value) ? ['data'=>$creator->pack($creator->value)] : [];
		}else{
			$type	= wpjam_pull($args, 'fields_type', 'table');
			$tag	= wpjam_pull($args, 'wrap_tag', (['table'=>'tr', 'list'=>'li'][$type] ?? $type));
			$sep	= "\n";
		}

		foreach($this->fields as $field){
			$creator && $field->group != $group && ([$groups[], $fields, $group]	= [[$group, $fields], [], $field->group]);

			$fields[]	= $field->sandbox(fn()=> $this->wrap($tag, $args));
		}

		if($groups){
			$fields	= array_merge(...array_map(fn($g)=> ($g[0] && count($g[1]) > 1) ? [wpjam_tag('div', ['field-group'], implode("\n", $g[1]))] : $g[1], [...$groups, [$group, $fields]]));
		}

		$fields	= array_filter($fields);
		$wrap	= wpjam_wrap(implode($sep, $fields));

		if($fields){
			if($type == 'table'){
				$wrap->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']);
			}elseif($type == 'list'){
				$wrap->wrap('ul');
			}
		}

		return $wrap;
	}

	public function get_parameter($method='POST', $merge=true){
		$data	= wpjam_get_parameter('', [], $method);

		return array_merge($merge ? $data : [], $this->validate($data, 'parameter'));
	}

	public static function create($fields, $args=[]){
		$creator	= is_object($args) ? $args : null;
		$prefix		= '';

		if($creator){
			if($creator->is('mu-fields') || $creator->propertied){
				$fields || wp_die($creator->_title.'fields不能为空');
			}else{
				$prefix	= $creator->prefix === true ? $creator->key : $creator->prefix;
			}
		}

		foreach(self::parse($fields, compact('prefix')) as $key => $field){
			$object	= wpjam_get($field, 'show_admin_column') === 'only' ? '' : WPJAM_Field::create($field, $key);

			if(!$object){
				continue;
			}

			if($creator){
				$object->attr($creator->pick(['readonly', 'disabled'])+['_creator'=>$creator]);

				if($creator->is('mu-fields') || $creator->propertied){
					if(count($object->names) > 1 || ($object->is('fieldset', true) && !$object->data_type) || $object->is('mu-fields')){
						trigger_error($creator->_title.'子字段不允许'.(count($object->names) > 1 ? '[]模式' : $object->type).':'.$object->name);

						continue;
					}

					$creator->propertied && $object->affix();
				}else{
					$creator->show_in_rest || $object->attr('show_in_rest', fn($v)=> $v ?? false);
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
			}elseif(is_array($field['options'])){
				$subs	= wpjam_reduce($field['options'], fn($carry, $item, $opt)=> array_merge($carry, is_array($item) ? array_map(fn($v)=> $v+['show_if'=>[$key, '=', $opt]], $item['fields'] ?? []) : []), [], 'options');
			}

			$parsed	= array_merge($parsed, !empty($subs) ? self::parse($subs, $args) : []);
		}

		return $parsed ?? [];
	}
}

class WPJAM_Parameter{
	private $data;
	private $input;

	public function get($name, $args=[]){
		if(is_array($name)){
			return $name ? wpjam_map((wp_is_numeric_array($name) ? array_fill_keys($name, $args) : $name), fn($v, $n)=> self::get($n, $v)) : [];
		}

		$method	= strtoupper((wpjam_pull($args, 'method') ?: 'GET'));
		$value	= $this->get_by($name, $method);

		if($name){
			$value	= (is_null($value) && !empty($args['fallback'])) ? $this->get_by($args['fallback'], $method) : $value;
			$value	??= $args['default'] ?? wpjam_default($name);
			$args	= wpjam_except($args, ['fallback', 'default']);

			if($args){
				$args['type']	??= '';
				$args['type']	= $args['type'] == 'int' ? 'number' : $args['type'];	// 兼容

				$send	= wpjam_pull($args, 'send') ?? true;
				$field	= wpjam_field(['key'=>$name]+$args);
				$value	= ($args['type'] ? $field : $field->schema(false))->catch('validate', $value, 'parameter');
				$value	= $send ? wpjam_if_error($value, 'send') : $value;
			}
		}

		return $value;
	}

	private function get_by($name, $method){
		if($method == 'DATA'){
			if($name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}

			$data	= $this->data ??= array_reduce(['defaults', 'data'], function($c, $k){
				$v	= $this->get_by($k, 'REQUEST') ?? [];
				$v	= ($v && is_string($v) && str_starts_with($v, '{')) ? wpjam_json_decode($v) : wp_parse_args($v);

				return wpjam_merge($c, $v);
			}, []);
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

			$data	= $this->input ??= (function(){
				$input	= file_get_contents('php://input');
				$input	= is_string($input) ? @wpjam_json_decode($input) : $input;

				return is_array($input) ? $input : [];
			})();
		}

		return wpjam_get($data, $name ?: null);
	}

	public static function get_instance(){
		static $object;
		return $object ??= new self();
	}
}