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

		return $args ? [$this, is_closure($args[0]) ? 'process_arg' : 'update_arg']($key, ...$args) : $this->get_arg($key);
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

		$args[0]	= is_array($args[0]) ? wpjam_array($args[0], fn($k)=> 'data-'.$k) : 'data-'.$args[0];

		return $this->attr(...$args) ?? (wpjam_array($this->data)[$args[0]] ?? null);
	}

	public function remove_data($key){
		$keys	= wp_parse_list($key);

		return array_reduce($keys, fn($c, $k)=> $c->remove_attr('data-'.$k), $this->attr('data', wpjam_except(wpjam_array($this->data), $keys)));
	}

	public function class($action='', ...$args){
		$args	= array_map('wp_parse_list', [$this->class ?: [], ...wpjam_filter($args)]);
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
			$args	= count($args) <= 1 || is_array($args[0]) ? (array)$args[0] : [$args[0].':'.$args[1]];

			return $this->attr('style', array_merge(wpjam_array($this->style), $args));
		}

		return wpjam_reduce($this->style, fn($c, $v, $k)=> is_blank($v) ? $c : [...$c, rtrim(is_numeric($k) ? $v : $k.':'.$v, ';').';'], []);
	}

	public function render(){
		[$data, $attr]	= $this->pull('__data') ? [$this, []] : [$this->data(), self::parse($this->add_class($this->pick(['readonly', 'disabled'])))];

		return wpjam_reduce($attr, function($c, $v, $k){
			if($k == 'data'
				|| array_any(['_callback', '_column'], fn($e)=> str_ends_with($k, $e))
				|| array_any(['_', 'column_', 'data-'], fn($s)=> str_starts_with($k, $s))
				|| ($k == 'value' ? is_null($v) : is_blank($v))
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
		return in_array($key, ['allowfullscreen', 'allowpaymentrequest', 'allowusermedia', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'defer', 'disabled', 'download', 'formnovalidate', 'hidden', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected', 'typemustmatch']);
	}

	public static function parse($attr){
		return wpjam_array($attr, function($k, $v){
			$k	= strtolower(trim($k));

			if(is_numeric($k)){
				$v = strtolower(trim($v));

				return self::is_bool($v) ? [$v, $v] : null;
			}

			return self::is_bool($k) ? ($v ? [$k, $k] : null) : [$k, $v];
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
		}elseif(in_array($method, ['insert_before', 'insert_after', 'append_to', 'prepend_to'])){
			$args[0]->{str_replace(['insert_', '_to'], '', $method)}($this);
		}else{
			trigger_error($method);
		}

		return $this;
	}

	public function is($tag){
		return array_intersect([$this->_tag, $this->_tag === 'input' ? ':'.$this->type : null], wp_parse_list($tag));
	}

	public function init($tag, $attr, $text){
		$attr		= $attr ? (wp_is_numeric_array((array)$attr) ? ['class'=>$attr] : $attr) : [];
		$this->args	= array_fill_keys(['_before', '_after', '_prepend', '_append'], [])+['_tag'=>$tag]+$attr;

		return $text && is_array($text) ? $this->text(...$text) : $this->text(is_blank($text) ? '' : $text);
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
		$this->args		= $args;
		$this->_title	??= $this->title.'「'.$this->key.'」';

		$this->init($this->pull('prepend_name'))->attr([
			'_data_type'	=> wpjam_get_data_type($this),
			'options'		=> fn($v)=> is_callable($v) ? $v() : $v,
		]);

		if($this->is('mu')){
			if($this->is('mu-fields')){
				$attr	= ['type'=>'fieldset', 'fieldset'=>'object']+($this->tag_label ? ['group'=>true] : []);
			}else{
				$attr	= ['type'=>$this->is('mu-text') ? ($this->item_type ?? 'text') : substr($this->type, 3)];
				$attr	+= $attr['type'] == 'text' && $this->direction == 'row' ? ['class'=>$this->class ?? 'medium-text'] : [];
			}

			$this->_item	= self::create($attr+['_mu'=>$this]+wpjam_except($this->get_args(), ['required', 'filterable', 'multiple']));
		}elseif($this->is('fieldset')){
			$this->_fields	= WPJAM_Fields::create($this->fields, $this->_fields_args ?: [], $this);
			$this->fields	= $this->_fields->fields;
		}else{
			$this->attr(wpjam_pattern($this->pattern) ?: []);
		}
	}

	public function __call($method, $args){
		[$action, $type]	= explode_last('_by', $method)+['', ''];

		if($type == '_data_type' && in_array($action, ['render', 'parse', 'validate'])){
			if(!$this->$type){
				return $args[0];
			}

			if($this->multiple && is_array($args[0])){
				return array_map([$this, $method], $args[0]);
			}

			$args	= [$action, ...$args, $this];
			$action	= 'with_field';
		}

		if($this->$type){
			return wpjam_try([$this->$type, $action], ...$args);
		}
	}

	public function init($pn){
		$this->_names	= array_merge(...wpjam_map([$pn, $this->name], fn($v)=> $v ? wpjam_names($v) : []));
		$this->name		= wpjam_names($this->_names);

		return $this;
	}

	public function is($type, $strict=false){
		$type	= wp_parse_list($type);

		return (in_array('mu', $type) && str_starts_with($this->type, 'mu-')) || in_array($this->type, $strict ? $type : array_merge($type, wpjam_pick(['fieldset'=>'fields', 'view'=>'hr'], $type)));
	}

	protected function el($name, $attr=[]){
		$tag	= wpjam_tag($name, $this->get_args())->attr($attr);
		$data	= $tag->pull(['key', 'data_type', 'query_args', 'custom_validity', 'show_option_all']);

		if($name == 'input'){
			$tag->class	??= $this->class = in_array($tag->type, ['text', 'url', 'email']) ? 'regular-text' : '';
		}else{
			$tag->class	??= $name == 'textarea' ? 'wide-text' : '';

			$tag->remove_attr(['type', 'value']);
		}

		return $tag->data($data)->add_class(in_array($name, ['select', 'textarea', 'input']) ? 'field-key-'.$this->key : '')->remove_attr(['default', 'options', 'multiple', 'title', 'label', 'render', 'before', 'after', 'description', 'wrap_class', 'wrap_tag', 'show_option_none', 'option_all_value', 'option_none_value', 'direction', 'group', 'buttons', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'parse_required', 'show_if', 'show_in_rest', 'column', 'custom_input', 'max_items', 'min_items', 'unique_items', 'filterable', 'summarization']);
	}

	public function schema(...$args){
		if($args){
			$value	= $args[0];

			foreach(wpjam_pull($value, ['enum', 'items', 'properties']) as $k => $v){
				if($k == 'enum'){
					$value[$k]	= array_map(fn($i)=> $this->sanitize($i, $value), $v);
				}elseif($value['type'] == ($k == 'items' ? 'array' : 'object')){
					$value[$k]	= $k == 'items' ? $this->schema($v) : array_map([$this, 'schema'], $v);
				}
			}

			return $value;
		}

		if(isset($this->_schema)){
			return $this->_schema;
		}

		$value	= array_filter(['type'=>$this->get_arg('show_in_rest.type')])+($this->get_arg_by_data_type('schema') ?: []);

		if($this->is('mu')){
			$value	= ['type'=>'array', 'items'=>($value+$this->schema_by_item())];
		}elseif($this->is('fieldset')){
			$value	+= ['type'=>'object', 'properties'=>array_filter($this->schema_by_fields())];
		}elseif($this->is('email')){
			$value	+= ['format'=>'email'];
		}elseif($this->is('color')){
			$value	+= $this->data('alpha-enabled') ? [] : ['format'=>'hex-color'];
		}elseif($this->is('url, image, file, img')){
			$value	+= ($this->is('img') && $this->item_type != 'url') ? ['type'=>'integer'] : ['format'=>'uri'];
		}elseif($this->is('number, range')){
			$step	= $this->step ?: '';
			$value	+= ['type'=>($step == 'any' || strpos($step, '.')) ? 'number' : 'integer'];
			$value	+= $value['type'] == 'integer' && $step > 1 ? ['multipleOf'=>$step] : [];
		}elseif($this->is('toggle')){
			$value	+= ['type'=>'boolean'];
		}elseif($this->is('radio, select, checkbox')){
			$value	+= ['type'=>'string']+($this->custom_input ? [] : ['enum'=>array_keys($this->options())]);
			$value	= $this->multiple ? ['type'=>'array', 'items'=>$value] : $value;
		}

		$value	+= ['type'=>'string'];
		$value	+= wpjam_array((array_fill_keys(['integer', 'number'], ['minimum'=>'min', 'maximum'=>'max'])+[
			'array'		=> ['maxItems'=>'max_items', 'minItems'=>'min_items', 'uniqueItems'=>'unique_items'],
			'string'	=> ['minLength'=>'minlength', 'maxLength'=>'maxlength',	'pattern'=>'pattern'],
		])[$value['type']] ?? [], fn($k, $v)=> is_blank($this->$v) ? null : [$k, $this->$v]);

		$value	+= $value['type'] === 'array' && $this->required ? ['minItems'=>1] : [];

		return $this->_schema = $this->schema($value);
	}

	public function button_text(){
		if($text = $this->pull('button_text')){
			return $text;
		}

		if($this->is('uploader')){
			return  __('Select Files', 'wpjam');
		}elseif($this->is('img, image, file, mu-img, mu-image, mu-file')){
			return  __('Select '.($this->is('file, mu-file') ? 'file' : 'image'), 'wpjam').($this->is('mu') ? '[多选]' : '');
		}elseif($this->is('mu-text, mu-fields')){
			return '添加'.(wpjam_between(mb_strwidth($this->title ?: ''), 4, 8) ? $this->title : '选项');
		}
	}

	public function item_type(){
		return $this->pull('item_type') ?? ($this->is('img, mu-img') ? 'id' : ($this->is('image, mu-image') ? 'image' : ''));
	}

	public function show_if(...$args){
		if($args = wpjam_parse_show_if($args ? $args[0] : $this->show_if)){
			return $args+['value'=>true]+($this->_prop ? ['current'=>$this->key] : []);
		}
	}

	public function options($flat=true, ...$args){
		$res		= [];
		$options	= $args[0] ?? array_replace($this->is('select') && !$this->multiple ? array_reduce(['all', 'none'], fn($c, $k)=> $c+array_filter([($this->{'option_'.$k.'_value'} ?? '') => $this->{'show_option_'.$k}]), []) : [], $this->options);

		foreach(wpjam_array($options) as $key => $item){
			if(is_array($item)){
				$label	= array_first(wpjam_pull($item, ['label', 'title']));
				$subs	= isset($item['options']) ? $this->options($flat, wpjam_pull($item, 'options')) : null;

				if($flat){
					$res	= array_replace($res, $subs ?? array_fill_keys([$key, ...wp_parse_list(wpjam_pull($item, 'alias', []))], $label));
				}else{
					$opt	= ['label'=>$label]+(isset($subs) ? ['options'=>$subs] : ['value'=>(string)$key]);

					foreach($item as $k => $v){
						if(is_numeric($k)){
							self::is_bool($v) && ($opt[$v] = $v);
						}elseif(self::is_bool($k)){
							$v && ($opt[$k] = $k);
						}elseif(in_array($k, ['class', 'image', 'description'])){
							$opt[$k]	= $v;
						}elseif($k == 'alias'){
							$opt[$k]	= wp_parse_list($v);
						}elseif($k != 'fields'){
							$opt['data'][$k]	= $k == 'show_if' ? $this->show_if($v) : $v;
						}
					}

					$res[]	= $opt;
				}
			}else{
				if($flat){
					$res[$key]	= $item;
				}else{
					$res[]	= ['value'=>(string)$key, 'label'=>$item];
				}
			}
		}

		return $res;
	}

	public function custom_input(){
		if($input = $this->custom_input){
			$cv		= '__custom';
			$by		= (is_array($input) ? $input : [])+['key'=>$this->key.$cv, 'type'=>'text', 'required'=>true];
			$title	= ($by['title'] ?? '') ?: (is_string($input) ? $input : __('Other', 'wpjam'));

			return $by+['by'=>$cv, 'title'=>$title, 'placeholder'=>'请输入'.$title.'选项'];
		}
	}

	public function validate($value, $type=''){
		$mu	= $this->is('mu');
		$cb	= $mu ? $this->validate_callback : '';
		$cb && wpjam_try($cb, $value) === false && wpjam_throw('invalid_'.($type ?: 'value'), [$this->key]);
		$cb	= $mu ? $this->sanitize_callback : '';

		if($this->is('fieldset')){
			$value	= $this->validate_by_fields($value, $type);

			return $cb ? wpjam_try($cb, $value) : $value;
		}

		if($type == 'parameter'){
			if(is_null($value ??= $this->default) && $this->required){
				wpjam_throw('missing_parameter', sprintf(__('Missing parameter: %s.', 'wpjam'), $this->key));
			}

			if($mu && !is_array($value)){
				$value	= wpjam_trap('wpjam_json_decode', $value, []);
			}
		}

		if($mu){
			$value	= array_values(wpjam_filter($value ?: [], fn($v)=> !is_blank($v), true));
			$value	= $type == 'if_value' ? $value : array_map(fn($v)=> $this->validate_by_item($v, $type), $value);
		}else{
			if($this->multiple){
				$value	= $value ?: [];
			}

			if($custom = $this->is('radio, select, checkbox') ? $this->custom_input() : []){
				if($this->multiple){
					$value	= wpjam_diff($value, [$custom['by']]);
				}

				if($diff = array_diff((array)$value, array_map('strval', array_keys($this->options())))){
					$custom	= self::create($custom+['_title'=>$this->_title.'的「'.$custom['title'].'」', '_schema'=>[]]);
					$custom->validate(array_first($diff));
				}
			}

			if($value){
				$value	= $this->validate_by_data_type($value);
			}
		}

		if($type == 'parameter'){
			$value	= is_null($value) ? null : $this->sanitize($value);
		}else{
			if($this->required && is_blank($value)){	// 空值只需 required 验证
				wpjam_throw(($type ?: 'value').'_required', [$this->_title]);
			}

			if($schema = $this->schema()){
				$value	= [$this, $this->_prop ? 'sanitize' : 'prepare']($value, $schema);

				if(is_array($value) || !is_blank($value)){
					wpjam_try('rest_validate_value_from_schema', $value, $schema, $this->_title);
				}
			}
		}

		return $cb ? wpjam_try($cb, $value ?? '') : $value;
	}

	public function pack($value){
		return wpjam_set([], $this->_names, $value);
	}

	public function unpack($data){
		return wpjam_get($data, $this->_names);
	}

	public function value_callback($args=[]){
		if(!$args || ($this->is('view') && $this->value)){
			return $this->value;
		}

		$k		= 'value_callback';
		$args	= $this->$k ? [[$k=>$this->$k]+wpjam_pick($args, ['id']), array_last($this->_names)] : [$args, $this->_names];

		return wpjam_value(...$args) ?? $this->value;
	}

	public function prepare(...$args){
		if(is_array($args[1] ?? '')){
			$rule	= [
				'array'		=> ['is_array', fn($val)=> wpjam_map($val, fn($v)=> $this->prepare($v, $args[1]['items']))],
				'object'	=> ['is_array', fn($val)=> wpjam_map($val, fn($v, $k)=> $this->prepare($v, ($args[1]['properties'][$k] ?? [])))],
				'null'		=> ['is_blank', fn()=> null],
				'integer'	=> ['is_numeric', 'intval'],
				'number'	=> ['is_numeric', 'floatval'],
				'string'	=> ['is_scalar', 'strval'],
				'boolean'	=> [fn($v)=> is_scalar($v) || is_null($v), 'rest_sanitize_boolean']
			][$args[1]['type']] ?? '';

			return $rule && $rule[0]($args[0]) ? $rule[1]($args[0]) : $args[0];
		}

		$value	= !empty($args[1]) ? $args[0] : $this->value_callback($args[0]);
		$value	= $this->sanitize($value);

		if($this->is('fieldset')){
			return array_filter($this->prepare_by_fields($value ?: [], 'value'), fn($v)=> !is_null($v));
		}elseif($this->is('mu')){
			return array_map(fn($v)=> $this->prepare_by_item($v, 'value'), $value ?: []);
		}elseif($this->is('img, image, file')){
			return wpjam_get_thumbnail($value, $this->size);
		}

		return $value && $this->parse_required ? $this->parse_by_data_type($value) : $value;
	}

	public function sanitize($value, $schema=null){
		$schema	??= $this->schema();

		return $schema ? wpjam_try('rest_sanitize_value_from_schema', ($schema['type'] == 'string' ? (string)$value : $value), $schema, $this->_title) : $value;
	}

	public function wrap($tag='', $args=[]){
		$field	= $this->render($args);
		$sep	= $args['sep'] ?? '';
		$for	= $this->is('view, mu, fieldset, img, uploader, radio, editor') || $this->multiple ? [] : ['for'=>$this->id];

		$this->after = wpjam_join(' ', $this->after, ...array_values(wpjam_map($this->buttons ?: [], [self::class, 'create'])));

		foreach($this->pick(['before', 'after']) as $k => $v){
			$v && $field->$k($this->is('textarea, editor, img, mu, fieldset', true) || (strip_tags($v) !== $v) ? 'p' : 'span', [$k], $v);
		}

		$for && ($this->label || $this->before || $this->after) && $field->wrap('label', $for);

		$title	= $this->title ? wpjam_tag('label', $for, $this->title) : '';
		$desc	= (array)$this->description+['', []];
		$desc[0] && $field->after('p', ['class'=>'description', 'data-show_if'=>$this->show_if(wpjam_pull($desc[1], 'show_if'))]+$desc[1], $desc[0]);

		if($tag == 'inline'){
			[$tag, $class]	= ($title || $this->is('fields') || !is_null($field->data('label')) || trim($sep)) ? ['div', $tag] : ['', ''];
		}elseif($tag == 'sub-field'){
			$title && $title->add_class('sub-field-label') && $field->wrap('div', ['sub-field-detail']);

			[$tag, $class]	= ['div', $tag];
		}elseif($tag == 'tr'){
			$field->wrap('td', $title && $title->wrap('th', ['scope'=>'row']) ? [] : ['colspan'=>2]);
		}

		$title	&& $field->before($title->after($sep));
		$tag	&& $field->wrap($tag, ['id'=>$tag.'_'.$this->id, 'class'=>$class ?? '']);

		return $field->data('show_if', $this->show_if())->add_class([$args['wrap_class'] ?? '', $this->wrap_class, $this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')]);
	}

	public function render($args=[], $type=''){
		if($type == 'value'){
			$value	= $args;
			$data	= [];

			if($this->is('fieldset, mu-fields')){
				return [];
			}elseif($this->is('mu')){
				if($this->is('mu-text')){
					$value	= $this->query_label_by_data_type($value) ?: $value;
				}elseif($this->is('mu-img')){
					$value	= wpjam_map($value, fn($v)=> $this->render_by_item($v, 'value')['value']);
				}
			}elseif($this->is('cascade')){
				$data	= $this->render_by_data_type($value)+$this->pull(['filter_key']);
				$data	= ['options'=>array_map(fn($v)=> $this->options(false, $v), $data['options'])]+$data;
			}elseif($this->is('img')){
				$value	= $value ? ['value'=>$value, 'url'=>wpjam_at(wpjam_get_thumbnail($value), '?', 0)] : '';
			}else{
				$data	= array_filter(['label'=>$this->query_label_by_data_type($value)]);
			}

			return $data+['value'=>$value];
		}

		$key	= $this->key;
		$value	= $this->value = $this->value_callback($args);
		$value	= $this->is('mu') ? array_values(wpjam_filter((array)$value, fn($v)=> !is_blank($v), true)) : $value;
		$data	= $this->pick(['key', 'type', 'direction', 'filterable', 'summarization'])+$this->render($value, 'value');
		$data	+= array_filter(wpjam_fill(['schema', 'item_type', 'button_text'], fn($k)=> [$this, $k]()));

		if($this->render){
			return wpjam_wrap($this->call('render_by_prop', $args));
		}elseif($this->is('hr')){
			return wpjam_tag('hr');
		}elseif($this->is('view')){
			$value	= (string)$value;
			$view	= array_find(($this->options && $value == strip_tags($value) ? $this->options() : []), fn($v, $k)=> $value ? $k == $value : !$k);
			$tag	= $this->wrap_tag ?? ($this->show_if || isset($view) ? 'span' : '');

			return wpjam_wrap($view ?? $value, $tag, $tag ? ['class'=>'field-key-'.$key, 'data'=>['value'=>$value, 'name'=>$this->name]] : []);
		}elseif($this->is('fieldset')){
			$tag	= $this->wrap_tag;
			$attr	= $this->_mu ? [] : array_filter(['class'=>$this->class, 'data'=>$this->data()]);
			$field	= $this->render_by_fields([
				'sep'		=> $this->sep ?? ($tag === 'fieldset' && !$this->group ? '<br />' : ''),
				'wrap_tag'	=> $tag === 'fieldset' ? 'inline' : ($tag ? 'sub-field' : '')
			]+$args)->wrap($this->_mu ? 'template' : '');

			$this->title	&& $tag === 'fieldset' && $field->prepend('legend', ['screen-reader-text'], $this->title);
			$this->summary	&& $field->before($this->summary, 'summary')->wrap('details');

			return $field->wrap($tag ?: ($attr ? 'div' : ''), $attr)->add_class($this->group ? 'field-group' : '');
		}elseif($this->is('mu')){
			$class	= ['mu', $this->type, ($this->sortable !== false ? 'sortable' : '')];

			if($this->is('mu-fields')){
				$data	+= $this->pick(['tag_label']);
				$text	= wpjam_map([...$value, []], fn($v)=> $this->attr_by_item(['value'=>$v])->render());
			}else{
				$text	= $this->attr_by_item(['id'=>'', 'value'=>null, 'name'=>$this->name.'[]'])->render()->wrap('div');
			}

			$field	= wpjam_tag('div', $this->pick(['id', 'name'])+['class'=>$class])->append($text);
		}elseif($this->is('toggle')){
			$field	= $this->el('input', ['value'=>1, 'type'=>'checkbox'])->after($this->label ?? $this->pull('description'));
		}elseif($this->is('radio, select, checkbox, cascade')){
			$field	= $this->el($this->is('select') && !$this->multiple ? 'select' : 'fieldset');
			$data	= ($this->is('cascade') ? [] : ['options'=>$this->options(false), 'custom'=>$this->custom_input()])+$this->pick(['multiple', 'sep'])+$data;
		}elseif($this->is('editor, textarea')){
			if($this->is('editor')){
				$this->id	= 'editor_'.$this->id;

				if(!wp_doing_ajax()){
					return wpjam_wrap(wpjam_ob('wp_editor', $value ?: '', $this->id, ['textarea_name'=>$this->name]));
				}

				$data	+= ['editor'=>['tinymce'=>true, 'quicktags'=>true]];
			}

			$field	= $this->el('textarea')->append(esc_textarea($value ?: ''));
		}elseif($this->is('img, image, file')){
			$field	= $this->el('input', ['type'=>$this->is('img') ? 'hidden' : 'url']);

			if($this->is('img')){
				if(!$this->_mu){
					$size	= wpjam_parse_size($this->size);

					$size['width'] && $size['height'] && ($this->description ??= '建议尺寸：'.$size['width'].'x'.$size['height']);

					$size	= wpjam_parse_size($this->size ?: '480x0', [480, 480]);
				}

				$field->data('thumb_args', wpjam_get_thumbnail_args($size ?? [200, 200]));
			}

			if($this->_mu){
				return $field;
			}
		}elseif($this->is('uploader')){
			$accept	= $this->accept ??= 'image/*';
			$data	+= [
				'drag_drop'	=> $this->pull('drag_drop') && !wp_is_mobile() && !$field->disabled,
				'max_size'	=> wp_max_upload_size(),
				'nonce'		=> wp_create_nonce('upload-'.$key.'-'.$accept)
			];

			$field	= $this->el('input', ['type'=>'hidden', 'disabled'=>!wpjam_accept_to_mime_types($accept)]);
		}else{
			$field	= $this->el('input');
			$data	+= array_filter(['class'=>$this->class]);
		}

		return $field->data($data);
	}

	public static function parse($field, ...$args){
		$field	= is_string($field) ? ['type'=>'view', 'value'=>$field, 'wrap_tag'=>''] : parent::parse($field);
		$field	= ['options'=>(($field['options'] ?? []) ?: [])]+$field;
		$type	= $field['type'] = ($field['type'] ?? '') ?: (array_find(['options'=>'select', 'label'=>'toggle', 'fields'=>'fieldset'], fn($v, $k)=> !empty($field[$k])) ?: 'text');

		if(($field['filterable'] ?? '') === 'multiple' && in_array($type, ['text', 'number', 'select'])){
			$field	+= ['multiple'=>true]+($type == 'select' ? [] : ['unique_items'=>true, 'sortable'=>false]);
		}

		if(in_array($type, ['fieldset', 'fields', 'mu-fields'])){
			if($type !== 'mu-fields'){
				$field['fieldset']	??= in_array(wpjam_pull($field, 'fieldset_type'), ['array', 'object']) ? 'object' : 'flat';
			}

			if($type !== 'fields'){
				$field['wrap_tag']	??= array_all($field['fields'] ?? [], fn($v)=> empty($v['title'])) ? 'fieldset' : 'div';
			}
		}elseif($type == 'size'){
			$field['fieldset']	??= 'object';
			$field['type']		= 'fields';
			$field['fields']	= wpjam_fill(['width', 'x', 'height'], fn($k)=> $k == 'x' ? '✖️' : (($v = $field['fields'][$k] ?? []) ? (is_string($v) ? self::parse($v) : $v) : [])+['type'=>'number', 'class'=>'small-text']);
		}elseif(in_array($type, ['number', 'url', 'tel', 'email', 'search'])){
			$field['inputmode']	??= $type == 'number' ? ((($step = $field['step'] ?? '') == 'any' || strpos($step, '.')) ? 'decimal': 'numeric') : $type;
		}elseif($type == 'checkbox'){
			$field	= (empty($field['options']) ? ['type'=>'toggle'] : ['multiple'=>true])+$field;
		}elseif($type == 'mu-select'){
			$field['multiple']	= true;
			$field['type']		= 'select';
		}elseif($type == 'tag-input' || (!empty($field['multiple']) && in_array($type, ['text', 'number']))){
			$field['item_type']	??= $type == 'tag-input' ? 'text' : $type;
			$field['class']		= 'tag-input';
			$field['type']		= 'mu-text';
		}

		return $field;
	}

	public static function create($field, $key=''){
		$field	= self::parse($field);
		$field	= ($key && !is_numeric($key) ? ['key'=>$key] : [])+$field;
		$key	= $field['key'] ?? '';

		if($key && !is_numeric($key)){
			return new WPJAM_Field(wpjam_fill(['id', 'name'], fn($k)=> ($field[$k] ?? '') ?: $key)+$field+([
				'color'		=> ['data-alpha-enabled'=>wpjam_pull($field, 'alpha')],
				'timestamp' => ['sanitize_callback'=> fn($v)=> $v ? (is_numeric($v) ? $v : wpjam_strtotime($v)) : '']
			][$field['type']] ?? []));
		}

		trigger_error('Field 的 key 不能为'.(!$key ? '空' : '纯数字「'.$key.'」'));
	}
}

class WPJAM_Fields extends WPJAM_Attr{
	public function __invoke($args=[]){
		return $this->render($args);
	}

	public function __call($method, $args){
		$data	= [];
		$fields	= try_remove_suffix($method, '_parts') ? wpjam_filter($this->fields, array_shift($args)) : $this->fields;

		if($method == 'validate'){
			$parent	= $this->parent;
			$prop	= $this->prop;
			$values	= $args[0] ?? wpjam_params('post');
			$type	= $args[1] ?? '';
			$if		= $parent ? (($parent->_mu ?: $parent)->_if ?: []) : [];

			if($type == 'if_value'){
				$pk	= $prop && !$if ? $parent->key.'__' : '';
			}else{
				$if	= ((!$if || $prop) ? ['values'=>$this->validate($values, 'if_value')+($if['values'] ?? [])] : [])+$if+['show'=>true];
			}
		}

		foreach($fields as $field){
			$set	= $field->is('fieldset');
			$flat	= $field->fieldset == 'flat';
			$pack	= !$flat;

			if($method == 'validate'){
				$can	= !$field->disabled && !$field->readonly && !$field->is('view, button');
				$value	= $flat ? $values : $field->unpack($values);

				if($type == 'if_value'){
					if($set || $can){
						$value	= wpjam_trap([$field, 'validate'], $value, $type, null);
					}else{
						$value	= $field->disabled ? null : $field->value_callback($this->_args);
					}

					$value	= $set ? $value : [$pk.$field->key => $value];	// show_if 基于key
					$pack	= false;
				}else{
					if(!$can){
						continue;
					}

					$show	= $if['show'] && (!($show_if = $field->show_if()) || wpjam_match($if['values'], $show_if));
					$value	= $flat || $show ? $field->attr('_if', ['show'=>$show]+$if)->validate($value, $type) : null;

					if(!$show && $prop){
						continue;
					}
				}
			}elseif(method_exists($field, $method)){
				$_args	= $args;

				if($method == 'prepare'){
					if($field->show_in_rest === false){
						continue;
					}
				
					$_args	= [!empty($args[1]) ? $field->unpack($args[0]) : ($args[0] ?? [])+$this->_args, $args[1] ?? ''];
				}

				$value	= [$field, $method.($flat ? '_by_fields' : '')](...$_args);
			}elseif($method == 'get_defaults'){
				$value	= $set ? [$field, $method.'_by_fields']() : ($field->disabled ? null : $field->value);
			}else{
				trigger_error($method); // del 2026-09-01
			}

			$data	= wpjam_merge($data, $pack ? $field->pack($value) : ($value ?? []));
		}

		return $data;
	}

	public function render($args=[]){
		$data	= [];
		$args	+= $this->_args;
		$parent	= $this->parent;
		$type	= $parent ? '' : (wpjam_pull($args, 'fields_type') ?? 'table');
		$tag	= wpjam_pull($args, 'wrap_tag') ?? ['table'=>'tr', 'list'=>'li'][$type] ?? $type;
		$args	+= ['sep'=>($tag == 'p' ? '<br />' : '')];
		$pf		= $this->prop ? wpjam_fill(['id', 'key', 'name'], fn($k)=> $parent->$k.($k == 'name' ? ($parent->_mu ? '[i${i}]' : '') : '__'.($parent->_mu ? 'i${i}__' : ''))) : [];

		foreach($this->fields as $field){
			if(!$parent || !$data || !$field->group || $field->group != $group){
				$i		= ($i ?? -1)+1;
				$group	= $field->group;
			}

			$data[$i][] = $field->sandbox(fn()=> ($pf ? $this->val(($parent->value ?: [])[$this->name] ?? null)->attr(wpjam_fill(['id', 'key'], fn($k)=> $pf[$k].$this->$k))->init($pf['name']) : $this)->wrap($tag, $args));
		}

		$data	= array_filter(array_map(fn($g)=> count($g) > 1 ? wpjam_tag('div', ['field-group'], implode("\n", $g)) : $g[0], $data));
		$wrap	= wpjam_wrap(implode($args['sep']."\n", $data));

		return $data && $type == 'table' ? $wrap->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']) : ($data && $type == 'list' ? $wrap->wrap('ul') : $wrap);
	}

	public function wrap($tag='', $args=[]){
		return $this->render(wpjam_pull($args, 'args', []))->after(wpjam_pull($args, 'button'))->wrap($tag, $args+($tag == 'form' ? ['novalidate', 'method'=>'post', 'action'=>'#'] : []));
	}

	public function register_meta($type, $args=[]){
		$nested	= [];
		$args	= (is_array($args) ? $args : ['object_subtype'=>$args])+['single'=>true];

		foreach($this->fields as $field){
			if($field->fieldset == 'flat'){
				$field->register_meta_by_fields($type, $args);
			}elseif($schema = $field->schema()){
				$names	= $field->_names;

				if(count($names) > 1){
					$temp	= &$nested;

					while($n = array_shift($names)){
						if(empty($names)){
							$temp[$n]	= $schema;
						}else{
							$temp[$n]	??= ['type'=>'object', 'properties'=>[]];
							$temp		= &$temp[$n]['properties'];
						}
					}
				}else{
					$default	= $field->get_arg('show_in_rest.default') ?? $field->default;
					$check		= rest_validate_value_from_schema($default, $schema );

					register_meta($type, $names[0], $args+[
						'type'				=> $schema['type'],
						'show_in_rest'		=> ['schema'=>$schema],
						'sanitize_callback'	=> fn($value)=> $field->validate($value)
					]+(is_wp_error($check) ? [] : ['default'=>$default]));
				}
			}
		}

		wpjam_map($nested, fn($v, $k)=> register_meta($type, $k, $args+[
			'type'				=> 'object',
			'show_in_rest'		=> ['schema'=>$v],
			'sanitize_callback'	=> fn($value)=> wpjam_filter($this->validate_parts(fn($v)=> $v->_names[0] === $k, [$k => $value])[$k], fn($v)=> !is_null($v), true)
		]));
	}

	public function to_block(){
		$data	= [];

		foreach($this->fields as $field){
			$schema	= $field->schema();
			$set	= $field->is('fieldset');

			if(!$schema || ($set && !$schema['properties'])){
				continue;
			}

			$block	= wpjam_pick($field, ['name', 'min', 'max', 'step', 'rows', 'placeholder'])+array_filter([
				'options'	=> $field->options ? $field->options(false) : [],
				'multiple'	=> $field->is('mu') || $field->multiple,
				'label'		=> $field->title ?? $field->label ?? null,
				'help'		=> $field->description ?? null
			]+($set ? [] : ['schema'=>$schema])+wpjam_fill(['show_if', 'item_type', 'button_text'], fn($k)=> [$field, $k]()));

			$field	= $field->is('mu') ? $field->_item : $field;
			$comp	= $field->type;

			if($field->is('fieldset')){
				$block	+= wpjam_pick($field, ['fieldset', 'direction'])+['fields'=>$field->to_block_by_fields()];
			}elseif($field->is('image, img')){
				$comp	= 'Media';
			}elseif($field->is('uploader')){
				$block	+= ['accept'=>$field->accept ?: 'image/*', 'nonce'=>wp_create_nonce('upload-'.$field->name.'-'.$accept)];
			}elseif(!$field->is('file, toggle, checkbox, select, radio, range, color, search, textarea, timestamp')){
				$comp	= $field->data_type ? 'Combobox' : 'Text';
				$block	+= $comp === 'Text' ? wpjam_pick($field, ['data_type', 'query_args']) : ['type'=>$field->type];
			}

			$data[]	= $block+['component'=>ucfirst($comp)];
		}

		return $data;
	}

	public function process($items, $args=[]){
		$sumable	= $this->sumable;

		if(!$sumable){
			$sumable	= [1=>[], 2=>[]];

			foreach($this->fields as $k => $v){
				if($s = $v['sumable'] ?? ''){
					$sumable[$s][$k]	= 0;
				}

				if(array_filter($f = [$v['format'] ?? '', $v['precision'] ?? null])){
					$formats[$k]	= $f;
				}

				if(($e = $v['if_error'] ?? '') || is_numeric($e)){
					$if_errors[$k]	= $e;
				}
			}

			$formulas	= wpjam_formula($this->fields);
			$sumable[2]	= array_intersect_key($formulas, $sumable[2]);

			$this->update_args([
				'formulas'	=> $formulas,
				'sumable'	=> $sumable,
				'formats'	=> $formats ?? [],
				'if_errors'	=> $if_errors ?? [],
			]);
		}

		$sum	= $args['sum'] ?? true;
		$calc	= $args['calc'] ?? null;

		if($sum === 'accumulate'){
			$calc	??= false;
			$field	= $args['field'] ?? '';
			$to		= $args['to'] ?? [];
			$to		= $field ? $to : ($to ?: $sumable[1]);
		}else{
			$calc	??= true;
			$sums	= $sum ? $sumable[1] : null;
		}

		foreach($items as $i => &$item){
			if($calc && $item && is_array($item)){
				$item	= wpjam_calc($item, ($calc === 'sum' ? $sumable[2] : $this->formulas), $this->if_errors);
			}

			if(!empty($args['filter']) && !wpjam_matches($item, $args['filter'])){
				unset($items[$i]); continue;
			}

			if($sum === 'accumulate'){
				if($field){
					$g		= $item[$field] ?? '';
					$to[$g]	??= $sumable[1]+$item;
					$target	= &$to[$g];
				}else{
					$target	= &$to;
				}
			}elseif($sum){
				$target	= &$sums;
			}

			if($sum){
				foreach($sumable[1] as $k => $null){
					$target[$k] += wpjam_format($item[$k] ?? 0, '-,', 0);
				}
			}

			if(!empty($args['format'])){
				$item	= wpjam_format($item, $this->formats);
			}
		}

		if($sum === 'accumulate'){
			return $to;
		}

		if(!empty($args['orderby'])){
			$items	= wpjam_sort($items, $args['orderby'], $args['order']);
		}

		if($sums){
			$sums	= wpjam_calc($sums, $sumable[2], $this->if_errors)+(is_array($sum) ? $sum : []);
			$items	= wpjam_add_at($items, 0, '__sum__', (!empty($args['format']) ? wpjam_format($sums, $this->formats) : $sums));
		}

		return $items;
	}

	public static function create($fields, $args=[], $parent=null){
		if($args === 'processor'){
			return new self(['fields'=>$fields]);
		}

		$prop	= $parent && $parent->fieldset == 'object';
		$attr	= ['_fields_args'=>$args, '_prop'=>$prop]+wpjam_pick($parent ?: [], ['readonly', 'disabled']);

		foreach(self::parse($fields) as $key => $field){
			if(($field['show_admin_column'] ?? '') !== 'only' && ($field = WPJAM_Field::create($attr+$field, $key))){
				if($prop && (count($field->_names) > 1 || $field->is('fieldset'))){
					trigger_error($parent->_title.'子字段不允许'.($field->is('fieldset') ? $field->type : '[]模式').':'.$field->name); continue;
				}

				$objects[$key]	= $field;
			}
		}

		$objects ??= $prop ? wp_die($parent->_title.'fields不能为空') : [];

		return new self(['_args'=>$args, 'parent'=>$parent, 'prop'=>$prop, 'fields'=>$objects]);
	}

	public static function parse($fields, ...$args){
		[$flat, $prefix]	= $args+[false, ''];

		foreach($fields as $key => $field){
			$field	= WPJAM_Field::parse($field);
			$nkey	= ($prefix ? $prefix.'_' : '').$key;
			$subs	= [];
			$prop	= false;

			if(in_array($field['type'], ['fieldset', 'fields']) && $field['fieldset'] == 'flat'){
				$prop	= !$flat;
				$subs	= static::parse($field['fields'], $flat, ($p = wpjam_pull($field, 'prefix')) === true ? $key : (string)$p);
			}elseif($field['type'] == 'toggle'){
				$subs	= wpjam_map((wpjam_pull($field, 'fields') ?: []), fn($v)=> $v+['show_if'=>[$nkey, '=', 1]]);
			}elseif(is_array($field['options'])){
				$subs	= wpjam_reduce($field['options'], fn($carry, $item, $opt)=> array_merge($carry, wpjam_map(
					is_array($item) ? ($item['fields'] ?? []) : [],
					fn($v, $k)=> $v+['show_if'=>[$nkey, 'IN', [...($carry[$k]['show_if'][2] ?? []), $opt]]]
				)), [], 'options');
			}

			$subs	= static::parse($subs, ...$args);
			$parsed	= array_merge($parsed ?? [], [$nkey=>($prop ? ['fields'=>$subs] : [])+$field], $prop ? [] : $subs);
		}

		return $parsed ?? [];
	}
}

/**
* @config orderby=order order=ASC
* @items_field paths
**/
#[config(orderby:'order', order:'ASC')]
#[items_field('paths')]
class WPJAM_Platform extends WPJAM_Register{
	public function __get($key){
		return $key == 'path' ? (bool)$this->get_paths() : parent::__get($key);
	}

	public function __call($method, $args){
		if(try_remove_suffix($method, '_path')){
			$method	= ($method == 'add' ? 'update' : $method).'_arg';

			return $this->$method('paths['.array_shift($args).']', ...$args);
		}elseif(try_remove_suffix($method, '_item')){
			$item	= $args[0];
			$suffix	= $args[1] ?? '';
			$multi	= $args[2] ?? false;

			$page_key	= wpjam_pull($item, 'page_key'.$suffix);

			if($page_key == 'none'){
				return ($video = $item['video'] ?? '') ? ['type'=>'video', 'video'=>wpjam_get_qqv_id($video) ?: $video] : ['type'=>'none'];
			}elseif(!$this->get_path($page_key.'[]')){
				return [];
			}

			$item	= $suffix ? wpjam_map($this->get_fields($page_key), fn($v, $k)=> $item[$k.$suffix] ?? null) : $item;
			$path	= $this->get_path($page_key, $item);
			$path	= wpjam_if_error($path, $method == 'validate' ? 'throw' : null);

			if(is_null($path)){
				$backup	= str_ends_with($suffix, '_backup');

				if($multi && !$backup){
					return [$this, $method.'_item']($item, $suffix.'_backup');
				}

				return $method == 'validate' ? wpjam_throw('invalid_page_key', '无效的'.($backup ? '备用' : '').'页面。') : ['type'=>'none'];
			}

			return is_array($path) ? $path : ['type'=>'', 'page_key'=>$page_key, 'path'=>$path];
		}elseif(try_remove_suffix($method, '_by_page_type')){
			$item	= array_last($args);
			$object	= wpjam_get_data_type(wpjam_pull($item, 'page_type'), $item);

			return $object ? [$object, $method](...$args) : null;
		}
	}

	public function verify(){
		return wpjam_call($this->verify);
	}

	public function get_tabbar($page_key=''){
		if(!$page_key){
			return wpjam_array($this->get_paths(), fn($k)=> [$k, $this->get_tabbar($k)], true);
		}

		if($tabbar	= $this->get_path($page_key.'[tabbar]')){
			return ($tabbar === true ? [] : $tabbar)+['text'=>(string)$this->get_path($page_key.'[title]')];
		}
	}

	public function get_page($page_key=''){
		return $page_key ? wpjam_at($this->get_path($page_key.'[path]'), '?', 0) : wpjam_array($this->get_paths(), fn($k)=> [$k, $this->get_page($k)], true);
	}

	public function get_fields($page_key){
		$item	= $this->get_path($page_key.'[]');
		$fields	= $item ? (!empty($item['fields']) ? maybe_callback($item['fields'], $item, $page_key) : $this->get_path_by_page_type('fields', $item)) : [];

		return $fields ?: [];
	}

	public function has_path($page_key, $strict=false){
		$item	= $this->get_path($page_key.'[]');

		return (!$item || ($strict && ($item['path'] ?? '') === false)) ? false : (isset($item['path']) || isset($item['callback']));
	}

	public function get_path($page_key, $args=[]){
		if(is_array($page_key)){
			[$page_key, $args]	= [wpjam_pull($page_key, 'page_key'), $page_key];
		}

		if(str_contains($page_key, '[')){
			return wpjam_get($this->get_paths(), str_ends_with($page_key, '[]') ? substr($page_key, 0, -2) : $page_key);
		}

		if($item	= $this->get_path($page_key.'[]')){
			$cb		= wpjam_pull($item, 'callback');
			$args	= is_array($args) ? array_filter($args, fn($v)=> !is_null($v))+$item : $args;
			$path	= $cb ? (is_callable($cb) ? ($cb($args, $item) ?: '') : null) : $this->get_path_by_page_type($args, $item);

			return isset($path) ? $path : (isset($item['path']) ? (string)$item['path'] : null);
		}
	}

	public function get_paths($page_key=null, $args=[]){
		if($page_key){
			$item	= $this->get_path($page_key.'[]');
			$type	= $item ? ($item['page_type'] ?? '') : '';
			$items	= $type ? $this->query_items_by_page_type(array_merge($args, wpjam_pick($item, [$type])), $item) : [];

			return $items ? wpjam_array($items, fn($k, $v)=> [$k, wpjam_trap([$this, 'get_path'], $page_key, $v['value'], null)], true) : [];
		}

		return $this->get_arg('paths[]');
	}

	public function registered(){
		if($this->name == 'template'){
			wpjam_register_path('home',		'template',	['title'=>'首页',		'path'=>home_url(),	'group'=>'tabbar']);
			wpjam_register_path('category',	'template',	['title'=>'分类页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('post_tag',	'template',	['title'=>'标签页',		'path'=>'',	'page_type'=>'taxonomy']);
			wpjam_register_path('author',	'template',	['title'=>'作者页',		'path'=>'',	'page_type'=>'author']);
			wpjam_register_path('post',		'template',	['title'=>'文章详情页',	'path'=>'',	'page_type'=>'post_type']);
			wpjam_register_path('external', 'template',	['title'=>'外部链接',		'path'=>'',	'fields'=>['url'=>['type'=>'url', 'required'=>true, 'placeholder'=>'请输入链接地址。']],	'callback'=>fn($args)=> ['type'=>'external', 'url'=>$args['url']]]);
		}
	}

	public static function get_options($output=''){
		return wp_list_pluck(self::get_registereds(), 'title', $output);
	}

	protected static function get_defaults(){
		return [
			'weapp'		=> ['bit'=>1,	'order'=>4,		'title'=>'小程序',	'verify'=>'is_weapp'],
			'weixin'	=> ['bit'=>2,	'order'=>4,		'title'=>'微信网页',	'verify'=>'is_weixin'],
			'mobile'	=> ['bit'=>4,	'order'=>8,		'title'=>'移动网页',	'verify'=>'wp_is_mobile'],
			'template'	=> ['bit'=>8,	'order'=>10,	'title'=>'网页',		'verify'=>'__return_true']
		];
	}

	public static function call_platforms($action, $platforms=null, ...$args){
		$platforms	= array_filter(self::get_by((array)($platforms ?? ['path'=>true])));
		$multi		= count($platforms) > 1;

		if($action == 'get_fields'){
			if(!$platforms){
				return [];
			}

			$args		= $args ? (is_array($args[0]) ? $args[0] : ['strict'=>$args[0]]) : [];
			$strict		= (bool)wpjam_pull($args, 'strict');
			$prepend	= array_filter(wpjam_pull($args, ['prepend_name']));
			$suffix		= wpjam_pull($args, 'suffix');
			$title		= wpjam_pull($args, 'title') ?: '页面';
			$key		= 'page_key'.$suffix;
			$paths		= WPJAM_Path::get_by($args);
			$fields_key	= 'fields['.md5(serialize($prepend+['suffix'=>$suffix, 'strict'=>$strict, 'platforms'=>array_keys($platforms), 'page_keys'=>array_keys($paths)])).']';

			static $caches	= [];

			if(!empty($caches[$fields_key])){
				[$fields, $show_if]	= $caches[$fields_key];
			}else{
				$pks	= [$key=>['OR', $suffix]]+($multi && !$strict ? [$key.'_backup'=>['AND', $suffix.'_backup']] : []);
				$fields	= wpjam_map($pks, fn()=> ['tabbar'=>['title'=>'菜单栏/常用', 'options'=>($strict ? [] : ['none'=>'只展示不跳转'])]]+wpjam('path_group')+['others'=>['title'=>'其他页面']]);

				foreach($paths as $path){
					$name	= $path->name;
					$group	= $path->group ?: ($path->tabbar ? 'tabbar' : 'others');
					$i		= 0;

					foreach($pks as $pk => [$op, $fix]){
						if(wpjam_matches($platforms, fn($pf)=> $pf->has_path($name, $strict && $op == 'OR'), $op)){
							$i++;

							$fields	= wpjam_set($fields, $pk.'['.$group.'][options]['.$name.']', [
								'label'		=> $path->title,
								'fields'	=> wpjam_array(array_reduce($platforms, fn($c, $pf)=> array_merge($c, $pf->get_fields($name)), []), fn($k, $v)=> [$k.$fix, wpjam_except($v, 'title')+$prepend])
							]);
						}
					}

					if($multi && !$strict && $i == 1){
						$show_if[]	= $name;
					}
				}

				$caches[$fields_key] = [$fields, $show_if ?? []];
			}

			return wpjam_array($fields, fn($k, $v)=> [$k.'_set', ['type'=>'fieldset', 'fields'=>[$k=>['options'=>array_filter($v, fn($item)=> !empty($item['options']))]+$prepend]]+($k != $key ? ['title'=>'备用'.$title, 'show_if'=>[$key, 'IN', $show_if]] : ['title'=>$title])]);
		}

		$args[]	= $multi;

		if($action == 'parse_item'){
			return $platforms ? [$multi ? array_find($platforms, fn($v)=> $v->verify()) : array_first($platforms), $action](...$args) : ['type'=>'none'];
		}

		return array_reduce($platforms, fn($c, $v)=> $c && $v->$action(...$args), true);
	}
}

class WPJAM_Path extends WPJAM_Args{
	public static function create($name, ...$args){
		$object	= self::get_instance($name) ?: wpjam('path', $name, new static(['name'=>$name]));

		if($args){
			[$pf, $args]	= count($args) >= 2 ? $args : [array_find(wpjam_pull($args[0], ['platform', 'path_type']), fn($v)=> $v), $args[0]];

			$args	+= in_array(($args['page_type'] ?? ''), ['post_type', 'taxonomy']) ? [$args['page_type']=>$name] : [];
			$group	= $args['group'] ?? '';

			if(is_array($group)){
				isset($group['key'], $group['title']) && wpjam('path_group', $group['key'], ['title'=>$group['title']]);

				$args['group']	= $group['key'] ?? null;
			}

			foreach((array)$pf as $pf){
				($platform = WPJAM_Platform::get($pf)) && $platform->add_path($name, array_merge($args, ['platform'=>$pf, 'path_type'=>$pf]));

				$object->update_arg('platform[]', $pf)->update_args($args, false);
			}
		}

		return $object;
	}

	public static function remove($name, $pf=''){
		if($object = self::get_instance($name)){
			foreach($pf ? (array)$pf : $object->get_arg('platform[]') as $pf){
				($platform = WPJAM_Platform::get($pf)) && $platform->delete_path($name);

				$object->delete_arg('platform[]', $pf);
			}

			return $pf ? $object : wpjam('path', $name, null);
		}
	}

	public static function get_by($args=[]){
		$type	= wpjam_pull($args, 'path_type');
		$args	+= $type ? ['platform'=>$type] : [];

		return wpjam_filter(wpjam('path'), $args, 'AND');
	}

	public static function get_instance($name){
		return wpjam('path', $name);
	}
}

/**
* @config model=0
**/
#[config(model:false)]
class WPJAM_Data_Type extends WPJAM_Register{
	public function get_path($args, $item){
		return wpjam_if_error(wpjam_call($this->model.'::get_path', $args, $item), 'throw');
	}

	public function with_field($action, $value, $field){
		$method	= $action.'_value';
		$res	= $this->$method ? $this->call($method.'_by_prop', $value, $field) : (($cb = $this->model.'::with_field') && wpjam_callback($cb) ? wpjam_try($cb, $action, $field, $value) : $value);

		return $action == 'validate' && is_null($res) ? wpjam_throw('invalid_field_value', $field->_title.'「'.$value.'」的值无效') : $res;
	}

	public function query_label($value){
		if($value && $this->model && $this->label_field){
			if(is_array($value)){
				wpjam_call($this->model.'::update_caches', $value);

				return array_map(fn($v)=> ($l = $this->query_label($v)) ? ['label'=>$l, 'value'=>$v] : $v, $value);
			}

			return ($this->model::get($value) ?: [])[$this->label_field] ?? null;
		}
	}

	public function query_items($args){
		$args	= array_filter($args ?: [], fn($v)=> !is_null($v))+['number'=>10, 'data_type'=>true];

		if($this->query_items){
			return wpjam_try($this->query_items, $args);
		}

		if($this->model){
			$args	= isset($args['model']) ? wpjam_except($args, ['data_type', 'model', 'label_field', 'id_field']) : $args;
			$res	= wpjam_try($this->model.'::query_items', $args, 'items');
			$items	= wp_is_numeric_array($res) ? $res : ($res['items'] ?? []);

			return $this->label_field ? wpjam_column($items, ['label'=>$this->label_field, 'value'=>$this->id_field]) : $items;
		}

		return [];
	}

	public static function get_defaults(){
		$schema	= ['type'=>'integer'];

		return [
			'post_type'	=> ['model'=>'WPJAM_Post',	'meta_type'=>'post',	'schema'=>$schema,	'label_field'=>'post_title',	'id_field'=>'ID'],
			'taxonomy'	=> ['model'=>'WPJAM_Term',	'meta_type'=>'term',	'schema'=>$schema,	'label_field'=>'name',			'id_field'=>'term_id'],
			'author'	=> ['model'=>'WPJAM_User',	'meta_type'=>'user',	'schema'=>$schema,	'label_field'=>'display_name',	'id_field'=>'ID'],
			'model'		=> [],
			'video'		=> ['parse_value'=>'wpjam_get_video_mp4'],
		];
	}

	public static function get_instance($name, $args=[]){
		$field	= $name instanceof WPJAM_Field ? $name : null;
		$name	= $field ? $field->data_type : $name;

		if($object	= self::get($name)){
			if($field){
				$args	= wp_parse_args($field->query_args ?: []);

				if($field->$name){
					$args[$name]	= $field->$name;
				}elseif(!empty($args[$name])){
					$field->$name	= $args[$name];
				}
			}

			if($name == 'model'){
				$model	= $args['model'];

				if(!$model || !class_exists($model)){
					return null;
				}

				$args['label_field']	??= wpjam_pull($args, 'label_key') ?: 'title';
				$args['id_field']		??= wpjam_pull($args, 'id_key') ?: wpjam_value($model, 'primary_key');

				$object	= $object->get_sub($model) ?: $object->register_sub($model, $args+[
					'meta_type'			=> wpjam_value($model, 'meta_type') ?: '',
					'validate_value'	=> fn($v)=> wpjam_try([$model, 'get'], $v) ? $v : null
				]);
			}

			if($field){
				$field->query_args	= $args ?: new StdClass;
				$field->_data_type	= $object;
			}
		}

		return $object;
	}

	public static function prepare($args, $output='args'){
		$type	= (is_array($args) || is_object($args)) ? wpjam_get($args, 'data_type') : '';
		$args	= $type ? (['data_type'=>$type]+(in_array($type, ['post_type', 'taxonomy']) ? [$type => wpjam_get($args, $type, '')] : [])) : [];

		return $output == 'key' ? ($args ? '__'.md5(wpjam_serialize($args)) : '') : $args;
	}

	public static function except($args){
		return array_diff_key($args, self::prepare($args));
	}
}