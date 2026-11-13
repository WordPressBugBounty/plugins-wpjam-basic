<?php
class WPJAM_Attr extends WPJAM_Args{
	protected $_attr_type	= '';

	public function __toString(){
		return (string)$this->render();
	}

	public function jsonSerialize(){
		return $this->render();
	}

	public function attr($key, ...$args){
		return is_array($key)
		? wpjam_reduce($key, fn($c, $v, $k)=> $c->attr($k, $v), $this)
		: [$this, $args ? 'process_arg' : 'get_arg']($key, ...$args);
	}

	public function val(...$args){
		return $this->attr('value', ...$args);
	}

	public function data($key='', ...$args){
		return is_array($key)
		? $this->attr(wpjam_array($key, fn($k)=> 'data-'.$k))
		: ($key
			? ($this->attr('data-'.$key, ...$args) ?? (wpjam_array($this->data)[$key] ?? null))
			: wpjam_array($this, fn($k)=> try_prefix($k, '-', 'data-') ? $k : null)+wpjam_array($this->data)
		);
	}

	public function remove_attr($key){
		return $this->delete_arg($key);
	}

	public function remove_data($key){
		return $this->attr('data', wpjam_except(wpjam_array($this->data), wp_parse_list($key)))->remove_attr(array_map(fn($k)=> 'data-'.$k, wp_parse_list($key)));
	}

	public function class(){
		return wp_parse_list($this->class ?: []);
	}

	public function has_class($name){
		return in_array($name, $this->class());
	}

	public function add_class($name){
		return $this->attr('class', wpjam_filter(array_merge($this->class(), wp_parse_list($name ?: [])), 'unique'));
	}

	public function remove_class(...$args){
		return $this->attr('class', $args ? array_diff($this->class(), wp_parse_list($args[0] ?: [])) : []);
	}

	public function style(...$args){
		$style	= wpjam_array($this->style);

		return $args
		? $this->attr('style', array_merge($style, (array)(count($args) == 1 || is_array($args[0]) ? $args[0] : implode(':', $args))))
		: wpjam_array($style, fn($k, $v)=> is_blank($v) ? null : [null, rtrim(is_numeric($k) ? $v : $k.':'.$v, ';').';']);
	}

	public function render(){
		$r	= '';
		$t	= $this->_attr_type;

		foreach($t == 'data' ? [] : self::parse($this->add_class(array_filter(['readonly', 'disabled'], fn($k)=> $this->$k))) as $k => $v){
			if($k == 'data'
				|| array_any(['_callback', '_column'], fn($e)=> str_ends_with($k, $e))
				|| array_any(['_', 'column_', 'data-'], fn($s)=> str_starts_with($k, $s))
				|| ($k == 'value' ? is_null($v) : is_blank($v))
			){
				continue;
			}

			if(in_array($k, ['style', 'class'])){
				$v	= implode(' ', array_unique($this->$k()));
			}elseif(!is_scalar($v)){
				trigger_error($k.' '.var_export($v, true));
			}

			$r	.= ' '.$k.'="'.esc_attr($v).'"';
		}

		foreach($t == 'data' ? $this : $this->data() as $k => $v){
			$v	= $k == 'show_if' ? wpjam_parse_show_if($v) : $v;
			$r	.= $v === false || $v === null ? '' : ' data-'.$k.'=\''.(is_scalar($v) ? esc_attr($v) : ($k == 'data' ? http_build_query($v) : wpjam_json_encode($v, 'attr'))).'\'';
		}

		return $r;
	}

	public static function is_bool($key){
		return in_array($key, ['allowfullscreen', 'allowpaymentrequest', 'allowusermedia', 'async', 'autofocus', 'autoplay', 'checked', 'controls', 'defer', 'disabled', 'download', 'formnovalidate', 'hidden', 'ismap', 'itemscope', 'loop', 'multiple', 'muted', 'nomodule', 'novalidate', 'open', 'playsinline', 'readonly', 'required', 'reversed', 'selected', 'typemustmatch']);
	}

	public static function parse($attr){
		return wpjam_array($attr, fn($k, $v)=> ($k = strtolower(trim($k))) && !is_numeric($k)
			? (self::is_bool($k) ? ($v ? [$k, $k] : null) : [$k, $v])
			: (($v = strtolower(trim($v))) && self::is_bool($v) ? [$v, $v] : null));
	}

	public static function create($attr, $type=''){
		$object = new static($attr && is_string($attr) ? shortcode_parse_atts($attr) : wpjam_array($attr));
		$object->_attr_type	= $type;

		return $object;
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

			$value	= count($args) > 1 ? new self(...(is_array($args[1]) ? $args : [$args[1], ($args[2] ?? []), $args[0]])) : $args[0];

			if(is_array($value)){
				wpjam_each($value, fn($v)=> $this->$method(...(is_array($v) ? $v : [$v])));
			}elseif(in_array($key, ['_text', '_tag'])){
				$this->$key	= (string)$value;
			}elseif(!is_blank($value)){
				$this->$key	= in_array($key, ['_before', '_prepend']) ? [$value, ...$this->$key] : [...$this->$key, $value];
			}
		}elseif(in_array($method, ['insert_before', 'insert_after', 'append_to', 'prepend_to'])){
			[$args[0], str_replace(['insert_', '_to'], '', $method)]($this);
		}

		return $this;
	}

	public function is($tag){
		return array_intersect([$this->_tag, $this->_tag === 'input' ? ':'.$this->type : null], wp_parse_list($tag));
	}

	public function init($tag, $attr, $text){
		$this->args	= ['_tag'=>$tag]+array_fill_keys(['_before', '_after', '_prepend', '_append'], []);
		$this->args	+= wpjam_is_assoc_array($attr) ? $attr : array_filter(['class'=>$attr]);

		return $this->text(...($text && is_array($text) ? $text : [is_blank($text) ? '' : $text]));
	}

	public function render(){
		$tag	= $this->update_args(['a'=>['href'=>'javascript:;'], 'img'=>['title'=>$this->alt]][$this->_tag] ?? [], false)->_tag;
		$arr	= $this->is_single($tag) ? [] : [...$this->_prepend, (string)$this->_text, ...$this->_append];
		$arr	= $tag ? ['<'.$tag.parent::render(), ...($arr ? ['>', ...$arr, '</'.$tag.'>'] : [' />'])] : $arr;

		return implode([...$this->_before, ...$arr, ...$this->_after]);
	}

	public function wrap($tag, ...$args){
		$wrap	= $tag && str_contains($tag, '></');
		$tag	= $wrap ? (preg_match('/<(\w+)([^>]*)>/', ($args ? sprintf($tag, ...$args) : $tag), $matches) ? $matches[1] : '') : $tag;

		return $tag ? $this->init($tag, $wrap ? shortcode_parse_atts($matches[2]) : ($args[0] ?? []), clone($this)) : $this;
	}

	public static function is_single($tag){
		return $tag && in_array($tag, ['area', 'base', 'basefont', 'br', 'col', 'command', 'embed', 'frame', 'hr', 'img', 'input', 'isindex', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
	}
}

class WPJAM_Field extends WPJAM_Attr{
	protected function __construct($args){
		$this->args		= $args;
		$this->options	= maybe_callback($this->options);
		$this->_title	??= $this->init($this->pull('prepend_name'))->title.'「'.$this->key.'」';

		if($type = $this->data_type){
			$args	= wp_parse_args($this->query_args ?: []);

			if($this->$type){
				$args[$type]	= $this->$type;
			}elseif(!empty($args[$type])){
				$this->$type	= $args[$type];
			}

			if($type == 'model'){
				$args['label_field']	??= wpjam_pull($args, 'label_key') ?: 'title';
				$args['id_field']		??= wpjam_pull($args, 'id_key') ?: wpjam_call($args[$type].'::get_primary_key');
			}

			if($this->_data_type = wpjam_get_data_type($type, $args)){
				$this->query_args	= $args ?: new StdClass;
				$this->query_nonce	= wp_create_nonce($this->_data_type->nonce_action($args));
			}
		}

		if($this->is('mu')){
			$type	= $this->is('mu-text') ? ($this->item_type ?? 'text') : wpjam_prefix($this->type, '-', 'mu-');

			$this->_item	= self::create(($type == 'fields'
				? ['type'=>'fieldset', 'fieldset'=>'object']+($this->tag_label ? ['group'=>true] : [])
				: ($type == 'text' && $this->direction == 'row' ? ['class'=>$this->class ?? 'medium-text'] : [])
			)+['type'=>$type, '_mu'=>$this]+wpjam_except($this->get_args(), ['required', 'filterable', 'multiple']));
		}elseif($this->is('fieldset')){
			$this->_fields	= WPJAM_Fields::create($this->fields, $this);
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

		return $this->$type ? wpjam_try([$this->$type, $action], ...$args) : null;
	}

	public function init($pn){
		return $this->attr('_names', [...wpjam_names($pn), ...wpjam_names($this->name)])->attr('name', wpjam_names($this->_names));
	}

	public function runtime($key, ...$args){
		if(try_prefix($key, '-', '_fields')){
			if(!($set = $this->is('mu') ? $this->_item : $this)->is('fieldset')){
				return $args ? $this : null;
			}

			$object	= $set->_fields;
		}else{
			$object	= $this;
		}

		if(!$args){
			return $object->$key;
		}

		$object->$key = $args[0];

		return $this;
	}

	public function is($type, $strict=false){
		$type	= wp_parse_list($type);

		return (in_array('mu', $type) && str_starts_with($this->type, 'mu-'))
		|| in_array($this->type, $strict ? $type : array_merge($type, wpjam_pick(['fieldset'=>'fields', 'view'=>'hr'], $type)));
	}

	protected function el($name, $attr=[]){
		$tag	= wpjam_tag($name, $this->get_args())->attr($attr)->remove_attr($name == 'input' ? [] : ['type', 'value']);
		$data	= $tag->pull(['key', 'data_type', 'query_args', 'query_nonce', 'filter_key', 'custom_validity', 'show_option_all']);

		$this->class = $tag->class ??= in_array($tag->type, ['text', 'url', 'email']) ? 'regular-text' : ($name == 'textarea' ? 'wide-text' : '');

		return $tag->data($data)->add_class($name == 'fieldset' ? '' : 'field-key-'.$this->key)->remove_attr(['default', 'options', 'multiple', 'title', 'label', 'render', 'before', 'after', 'description', 'wrap_class', 'wrap_tag', 'show_option_none', 'option_all_value', 'option_none_value', 'direction', 'group', 'buttons', 'size', 'post_type', 'taxonomy', 'sep', 'fields', 'parse_required', 'show_if', 'show_in_rest', 'column', 'custom_input', 'max_items', 'min_items', 'unique_items', 'filterable', 'summarization']);
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
			$value	+= ['type'=>($step == 'any' || str_contains($step, '.')) ? 'number' : 'integer'];
			$value	+= $value['type'] == 'integer' && $step > 1 ? ['multipleOf'=>$step] : [];
		}elseif($this->is('toggle')){
			$value	+= ['type'=>'boolean'];
		}elseif($this->is('radio, select, checkbox')){
			$value	+= ['type'=>'string']+($this->custom_input ? [] : array_filter(['enum'=>array_keys($this->options())]));
			$value	= $this->multiple ? ['type'=>'array', 'items'=>$value] : $value;
		}

		$value	+= ['type'=>'string'];
		$value	+= wpjam_array((array_fill_keys(['integer', 'number'], ['minimum'=>'min', 'maximum'=>'max'])+[
			'array'		=> ['maxItems'=>'max_items', 'minItems'=>'min_items', 'uniqueItems'=>'unique_items'],
			'string'	=> ['minLength'=>'minlength', 'maxLength'=>'maxlength',	'pattern'=>'pattern'],
		])[$value['type']] ?? [], fn($k, $v)=> is_blank($this->$v) ? null : [$k, $this->$v]);

		return $this->_schema = $this->schema($value+($value['type'] === 'array' && $this->required ? ['minItems'=>1] : []));
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
			return '添加'.(wpjam_compare(mb_strwidth($this->title ?: ''), 'between', [4, 8]) ? $this->title : '选项');
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

		if(!$mu && ($cb = $this->validate_callback) && wpjam_try($cb, $value) === false){
			wpjam_invalid($type ?: 'value', [$this->key]);
		}

		if($type == 'parameter' && $this->fieldset != 'flat'){
			if(is_null($value ??= $this->default) && $this->required){
				wpjam_throw('missing_parameter', sprintf(__('Missing parameter: %s.', 'wpjam'), $this->key));
			}

			if($mu && !is_array($value)){
				$value	= wpjam_trap('wpjam_json_decode', $value, []);
			}
		}

		if($this->is('fieldset')){
			$value	= $this->validate_by_fields($value, $type);

			goto st;
		}

		if($mu){
			$value	= array_values(wpjam_filter((array)$value, '!blank', true));
			$value	= $type == 'if_value' ? $value : array_map(fn($v)=> $this->validate_by_item($v, $type), $value);
		}else{
			$custom	= $this->is('radio, select, checkbox') ? $this->custom_input() : [];
			$value	= $this->multiple ? wpjam_diff((array)$value, $custom ? [$custom['by']] : []) : $value;

			if($custom && ($diff = array_diff((array)$value, array_map('strval', array_keys($this->options()))))){
				(self::create($custom+['_title'=>$this->_title.'的「'.$custom['title'].'」', '_schema'=>[]]))->validate(array_first($diff));
			}

			if($value){
				$value	= $this->validate_by_data_type($value);
			}
		}

		if($type == 'parameter'){
			$value	= isset($value) ? $this->sanitize($value) : null;
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

		st:

		return (!$mu && ($cb = $this->sanitize_callback)) ? wpjam_try($cb, $value ?? '') : $value;
	}

	public function value_callback($args=[]){
		if(!$args || ($this->is('view') && $this->value)){
			return $this->value;
		}

		$cb	= $this->value_callback;

		return wpjam_value_callback(
			$cb ? ['value_callback'=>$cb]+wpjam_pick($args, ['id']) : $args,
			$cb ? array_last($this->_names) : $this->_names
		) ?? $this->value;
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
			return wpjam_filter($this->prepare_by_fields($value ?: [], 'value'), '!null');
		}elseif($this->is('mu')){
			return array_map(fn($v)=> $this->prepare_by_item($v, 'value'), $value ?: []);
		}elseif($this->is('img, image, file')){
			return wpjam_get_thumbnail($value, $this->size);
		}

		return $value && $this->parse_required ? $this->parse_by_data_type($value) : $value;
	}

	public function sanitize($value, $schema=null){
		return ($schema ??= $this->schema()) ? wpjam_try('rest_sanitize_value_from_schema', ($schema['type'] == 'string' ? (string)$value : $value), $schema, $this->_title) : $value;
	}

	public function wrap($tag='', $args=[]){
		$field	= $this->render($args);
		$sep	= $args['sep'] ?? '';
		$for	= $this->is('view, mu, fieldset, img, uploader, radio, editor') || $this->multiple ? [] : ['for'=>$this->id];
		$inner	= !$tag && !$for;
		$before	= $this->before;
		$after	= $this->after;
		$buttons= wpjam_join(' ', array_values(wpjam_map($this->buttons ?: [], [self::class, 'create'])));

		foreach(compact('before', 'after') as $k => $v){
			$t	= ($this->is('textarea, editor, img, mu, fieldset', true) && $tag) || ($v && strip_tags($v) !== $v) ? 'p' : 'span';

			if($v = wpjam_filter([$v, $k == 'after' ? $buttons : ''], '!blank')){
				[$field, $inner ? ($k == 'before' ? 'prepend' : 'append') : $k]($t, [$k], implode(' ', $v));
			}
		}

		if($for && ($this->label || $before || $after || $buttons)){
			$field->wrap('label', $for);
		}

		$title	= $this->title ? wpjam_tag('label', $for, $this->title) : '';
		$desc	= (array)$this->description+['', []];

		if(!is_blank($desc[0])){
			$field->after('p', ['class'=>'description', 'data-show_if'=>$this->show_if(wpjam_pull($desc[1], 'show_if'))]+$desc[1], $desc[0]);
		}

		if($tag == 'inline'){
			[$tag, $class]	= ($title || $this->is('fields') || !is_null($field->data('label')) || trim($sep)) ? ['div', $tag] : ['', ''];
		}elseif($tag == 'sub-field'){
			$title && $title->add_class('sub-field-label') && $field->wrap('div', ['sub-field-detail']);

			[$tag, $class]	= ['div', $tag];
		}elseif($tag == 'tr'){
			$field->wrap('td', $title && $title->wrap('th', ['scope'=>'row']) ? [] : ['colspan'=>2]);
		}

		if($title){
			[$field, $inner ? 'prepend' : 'before']($title->after($sep));
		}

		if($tag){
			$field->wrap($tag, ['id'=>$tag.'_'.$this->id, 'class'=>$class ?? '']);
		}

		return $field->data('show_if', $this->show_if())->add_class([$args['wrap_class'] ?? '', $this->wrap_class, $this->disabled, $this->readonly, ($this->is('hidden') ? 'hidden' : '')]);
	}

	public function query_label($value){
		if($this->has_class('mix-tag')){
			$sep	= implode('|', array_map(fn($v)=> preg_quote($v, '/'), [' ', ...($this->data('sep') ?: [])]));
			$value	= preg_split('/('.$sep.')/', trim(str_replace("\xc2\xa0", ' ', $value)), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
			$value	= wpjam_filter($value, fn($v)=> trim($v));
			$value[]	= "\xc2\xa0";
		}

		if($items = $this->data('items')){
			$this->data('items', wpjam_entries($items, ['value', 'label']));

			return is_array($value) ? wpjam_map($value, fn($v)=> empty($items[$v]) ? $v : ['label'=>$items[$v], 'value'=>$v]) : ($items[$value] ?? null);
		}

		return $this->query_label_by_data_type($value);
	}

	public function render($args=[]){
		$value	= $this->value = $this->value_callback($args);
		$value	= $this->is('mu') ? array_values(wpjam_filter((array)$value, '!blank', true)) : $value;

		if($this->is('fieldset, mu-fields, view')){
			$data	= [];
		}elseif($this->is('mu-img, img')){
			$values	= wpjam_map((array)$value, fn($v)=> $v ? ['value'=>$v, 'url'=>wpjam_at(wpjam_get_thumbnail($v), '?', 0)] : '');
			$data	= ['value'=>$this->is('mu') ? $values : array_first($values)];
		}elseif($this->is('cascade')){
			$data	= $this->render_by_data_type($value);
		}else{
			$label	= $this->query_label($value);
			$data	= is_array($label) ? ['value'=>$label ?: $value] : ['value'=>$value]+array_filter(['label'=>$label]);
		}

		$data	+= $this->pick(['key', 'type', 'direction', 'filterable', 'summarization', 'tag_label']);
		$data	+= array_filter(wpjam_fill(['schema', 'item_type', 'button_text'], fn($k)=> [$this, $k]()));

		if($this->render){
			return wpjam_wrap($this->call('render', $args));
		}elseif($this->is('hr')){
			return wpjam_tag('hr');
		}elseif($this->is('view')){
			$value	= (string)$value;
			$view	= array_find(($this->options && $value == strip_tags($value) ? $this->options() : []), fn($v, $k)=> $value ? $k == $value : !$k);
			$tag	= $this->wrap_tag ?? ($this->show_if || isset($view) ? 'span' : '');

			return wpjam_wrap($view ?? $value, $tag, $tag ? ['class'=>'field-key-'.$this->key, 'data'=>['value'=>$value, 'name'=>$this->name]] : []);
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
				$text	= wpjam_map([...$value, []], fn($v)=> $this->attr_by_item(['value'=>$v])->render());
			}else{
				$text	= $this->attr_by_item(['id'=>'', 'value'=>null, 'name'=>$this->name.'[]'])->render()->wrap('div');
			}

			$field	= wpjam_tag('div', $this->pick(['id', 'name'])+['class'=>$class])->append($text);
		}elseif($this->is('toggle')){
			$field	= $this->el('input', ['value'=>1, 'type'=>'checkbox'])->after($this->label ?? $this->pull('description'));
		}elseif($this->is('radio, select, checkbox, cascade')){
			$data	= $this->pick(['multiple', 'sep'])+($this->is('cascade')
				? ['options'=>array_map(fn($v)=> $this->options(false, $v), $data['options'])]
				: ['options'=>$this->options(false), 'custom'=>$this->custom_input()]
			)+$data;

			$field	= $this->el($this->is('select') && !$this->multiple ? 'select' : 'fieldset');
		}elseif($this->is('editor, textarea')){
			if($this->is('editor')){
				$this->id	= 'editor_'.$this->id;

				if(!wp_doing_ajax()){
					return wpjam_wrap(wpjam_ob('wp_editor', (string)$value, $this->id, ['textarea_name'=>$this->name]));
				}

				$data	+= ['editor'=>['tinymce'=>true, 'quicktags'=>true]];
			}

			$field	= $this->el('textarea')->append(esc_textarea((string)$value));
		}elseif($this->is('img, image, file')){
			$field	= $this->el('input', ['type'=>$this->is('img') ? 'hidden' : 'url']);

			if($this->is('img')){
				$this->description ??= ($size = wpjam_parse_size($this->size)) && $size['width'] && $size['height'] ? '建议尺寸：'.$size['width'].'x'.$size['height'] : null;

				$field->data('thumb_args', wpjam_get_thumbnail('args', $this->_mu ? [200, 200] : wpjam_constrain_dimensions($this->size ?: '480x0', [480, 480])));
			}

			if($this->_mu){
				return $field;
			}
		}elseif($this->is('uploader')){
			$accept	= $this->accept ??= 'image/*';
			$field	= $this->el('input', ['type'=>'hidden', 'disabled'=>!wpjam_mimes($accept)]);
			$data	+= [
				'drag_drop'	=> $this->pull('drag_drop') && !wp_is_mobile() && !$field->disabled,
				'max_size'	=> wp_max_upload_size(),
				'nonce'		=> wp_create_nonce('upload-'.$this->key.'-'.$accept)
			];
		}else{
			$field	= $this->el('input');
			$data	+= array_filter(['class'=>$this->class]);
		}

		return $field->data($data);
	}

	public static function parse($field){
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
			$field['inputmode']	??= $type == 'number' ? ((($step = $field['step'] ?? '') == 'any' || str_contains($step, '.')) ? 'decimal': 'numeric') : $type;
		}elseif($type == 'checkbox'){
			$field	= (empty($field['options']) ? ['type'=>'toggle'] : ['multiple'=>true])+$field;
		}elseif($type == 'mu-select'){
			$field['multiple']	= true;
			$field['type']		= 'select';
		}elseif($type == 'tag-input' || (!empty($field['multiple']) && in_array($type, ['text', 'number']))){
			$field['item_type']	??= $type == 'tag-input' ? 'text' : $type;
			$field['class']		= wpjam_join(' ', 'tag-input', ...(array)($field['class'] ?? []));
			$field['type']		= 'mu-text';
		}

		return $field;
	}

	public static function create($field, $key=''){
		$field	= self::parse($field);
		$key	= $key && !is_numeric($key) ? $key : (($field['key'] ?? '') ?: ($field['name'] ?? ''));

		if($key && !is_numeric($key)){
			return new WPJAM_Field(wpjam_fill(['id', 'name', 'key'], fn($k)=> ($field[$k] ?? '') ?: $key)+$field+([
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
		$fields	= $this->mod($method, '_parts') ? wpjam_filter($this->fields, array_shift($args)) : $this->fields;

		if(in_array($method, ['validate', 'prepare'])){
			$values	= $args[0] ?? null;

			[$args, $type]	= is_array($args[1] ??= []) ? [$args[1], $args[2] ?? ''] : [[], $args[1] ?? ''];

			$args	+= $this->_args;

			if($method == 'validate'){
				$values	??= wpjam_params('post');
				$parent	= $this->parent;
				$prop	= $this->prop;

				if($type == 'if_value'){
					$pk	= $prop && !$parent->_mu ? $parent->key.'__' : '';
				}else{
					$if	= $parent ? (($parent->_mu ?: $parent)->runtime('_if') ?: []) : [];
					$if	= ((!$if || $prop) ? ['values'=>$this->validate($values, $args, 'if_value')+($if['values'] ?? [])] : [])+$if+['show'=>true];
				}
			}
		}

		foreach($fields as $field){
			$set	= $field->runtime('_fields_args', $args)->is('fieldset');
			$flat	= $field->fieldset == 'flat';
			$pack	= !$flat;

			if($method == 'validate'){
				$can	= !$field->disabled && !$field->readonly && !$field->is('view, button');
				$value	= $flat ? $values : wpjam_get($values, $field->_names);

				if($type == 'if_value'){
					$value	= ($set || $can)
					? wpjam_trap([$field, 'validate'], $value, $type, null)
					: ($field->disabled ? null : $field->value_callback($args));

					$value	= $set ? $value : [$pk.$field->key => $value];	// show_if 基于key
					$pack	= false;
				}else{
					$show	= $can && $if['show'] && wpjam_match($if['values'], $field->show_if());
					$value	= $can && ($flat || $show) ? $field->runtime('_if', ['show'=>$show]+$if)->validate($value, $type) : null;

					if(!$can || (!$show && $prop)){
						continue;
					}
				}
			}elseif(method_exists($field, $method)){
				if($method == 'prepare' && $field->show_in_rest === false){
					continue;
				}

				$value	= [$field, $method.($flat ? '_by_fields' : '')](...($method == 'prepare'
					? [$type ? wpjam_get($values, $field->_names) : ($values ?? [])+$args, $type]
					: $args
				));
			}elseif($method == 'get_defaults'){
				$value	= $set ? [$field, $method.'_by_fields']() : ($field->disabled ? null : $field->value);
			}

			$data	= $pack ? wpjam_set($data, $field->_names, $value) : wpjam_merge($data, ($value ?? []));
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

			$data[$i][] = $field->runtime('_fields_args', $this->_args)->sandbox(fn()=> ($pf ? $field->val(($parent->value ?: [])[$field->name] ?? null)->attr(wpjam_fill(['id', 'key'], fn($k)=> $pf[$k].$field->$k))->init($pf['name']) : $field)->wrap($tag, $args));
		}

		$data	= array_map(fn($g)=> count($g) > 1 ? wpjam_tag('div', ['field-group'], implode("\n", $g)) : $g[0], $data);
		$wrap	= wpjam_wrap(wpjam_join($args['sep']."\n", $data));

		return $data && $type == 'table' ? $wrap->wrap('tbody')->wrap('table', ['cellspacing'=>0, 'class'=>'form-table']) : ($data && $type == 'list' ? $wrap->wrap('ul') : $wrap);
	}

	public function wrap($tag='', $args=[]){
		return $this->render(wpjam_pull($args, 'args', []))->after(wpjam_pull($args, 'button'))->wrap($tag, $args+($tag == 'form' ? ['novalidate', 'method'=>'post', 'action'=>'#'] : []));
	}

	public function form(...$args){
		return $args && is_array($args[0])
		? $this->wrap('form', ...$args)
		: $this->render()->after([
			wp_nonce_field($args[1] ?? '', '_wpnonce', true, false),
			wp_original_referer_field(false, 'previous'),
			($args[2] ??= '') !== false ? get_submit_button($args[2]) : ''
		])->wrap('form', ['enctype'=>'multipart/form-data', 'method'=>'post', 'action'=>$args[0]]);
	}

	public function table($items, $args=[]){
		$cb	= wpjam_pull($args, 'item_callback');

		foreach($items as $id => &$item){
			$item	= $cb ? $cb($item, $id) : $item;
			$item	= wpjam_tag('tr', wpjam_pick($args, ['class'])+['data'=>wpjam_pick($item, ['id'])])->append(wpjam_map($this->fields, fn($k)=> wpjam_tag('td', ['class'=>'column-'.$k], $item[$k] ?? ''), 'k'));
		}

		return wpjam_tag('table', ['class'=>array_merge(['widefat', 'striped'], isset($this->fields['no']) ? ['numberable'] : []), 'data'=>array_filter(wpjam_pick($args, ['foot']))])->append([
			wpjam_tag('thead')->append(wpjam_tag('tr')->append(wpjam_map($this->fields, fn($v)=> wpjam_tag('th', ['data'=>$v->pick(['format', 'precision', 'conditional_styles'])], $v->title)))),
			wpjam_tag('tbody')->append($items ?: wpjam_tag('tr', ['no-items'], '找不到'.array_first($this->fields)->title))
		]);
	}

	public function process($data, $args=[]){
		return ($this->processor ??= WPJAM_Processor::create($this->fields))->process($data, $args);
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
					$default	= wpjam_then(rest_validate_value_from_schema($default, $schema), ['default'=>$default], []);

					register_meta($type, $names[0], $args+$default+[
						'type'				=> $schema['type'],
						'show_in_rest'		=> ['schema'=>$schema],
						'sanitize_callback'	=> fn($value)=> $field->validate($value)
					]);
				}
			}
		}

		wpjam_each($nested, fn($v, $k)=> register_meta($type, $k, $args+[
			'type'				=> 'object',
			'show_in_rest'		=> ['schema'=>$v],
			'sanitize_callback'	=> fn($value)=> wpjam_filter($this->validate_parts(fn($v)=> $v->_names[0] === $k, [$k => $value])[$k], '!null', true)
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

			$block	= $field->pick(['name', 'min', 'max', 'step', 'rows', 'placeholder'])+array_filter([
				'options'	=> $field->options ? $field->options(false) : [],
				'multiple'	=> $field->is('mu') || $field->multiple,
				'label'		=> $field->title ?? $field->label ?? null,
				'help'		=> $field->description ?? null
			]+($set ? [] : ['schema'=>$schema])+wpjam_fill(['show_if', 'item_type', 'button_text'], fn($k)=> [$field, $k]()));

			$field	= $field->is('mu') ? $field->_item : $field;
			$comp	= $field->type;

			if($field->is('fieldset')){
				$block	+= $field->pick(['fieldset', 'direction'])+['fields'=>$field->to_block_by_fields()];
			}elseif($field->is('image, img')){
				$comp	= 'Media';
			}elseif($field->is('uploader')){
				$accept	= $field->accept ??= 'image/*';
				$block	+= ['accept'=>$accept, 'nonce'=>wp_create_nonce('upload-'.$field->name.'-'.$accept)];
			}elseif(!$field->is('file, toggle, checkbox, select, radio, range, color, search, textarea, timestamp')){
				$comp	= $field->data_type ? 'Combobox' : 'Text';
				$block	+= $comp === 'Combobox' ? $field->pick(['data_type', 'query_args', 'query_nonce']) : ['type'=>$field->type];
			}

			$data[]	= $block+['component'=>ucfirst($comp)];
		}

		return $data;
	}

	public function add($field){
		return $this->update_arg('fields['.$field->key.']', $field);
	}

	public function context($args){
		return $this->update_arg('_args', $args);
	}

	public static function create($fields, $parent=null){
		$prop	= $parent && $parent->fieldset == 'object';
		$attr	= ['_prop'=>$prop]+wpjam_pick($parent ?: [], ['readonly', 'disabled']);
		$object	= new self(['parent'=>$parent, 'prop'=>$prop, 'fields'=>[], '_args'=>[]]);

		foreach(self::parse($fields) as $key => $field){
			if($field = WPJAM_Field::create($attr+$field, $key)){
				if($prop && (count($field->_names) > 1 || $field->is('fieldset'))){
					trigger_error($parent->_title.'子字段不允许'.($field->is('fieldset') ? $field->type : '[]模式').':'.$field->name); continue;
				}

				$object->add($field);
			}
		}

		return $object->fields || !$prop ? $object : wp_die($parent->_title.'fields不能为空');
	}

	public static function parse($fields, $parent=null, $expand=false){
		$prefix	= ($p = wpjam_get($parent ?: [], 'prefix')) === true ? wpjam_get($parent, 'key') : (string)$p;

		foreach($fields as $key => $field){
			$field	= WPJAM_Field::parse($field);
			$nkey	= wpjam_join('_', [$prefix, $key]);
			$subs	= [];

			if(in_array($field['type'], ['fieldset', 'fields']) && $field['fieldset'] == 'flat'){
				$subs	= static::parse($field['fields'], ['key'=>$key]+wpjam_pull($field, ['prefix']), $expand);

				if(!$expand){
					[$field['fields'], $subs]	= [$subs, []];
				}
			}else{
				if($field['type'] == 'toggle'){
					$subs	= wpjam_map((wpjam_pull($field, 'fields') ?: []), fn($v)=> $v+['show_if'=>[$nkey, '=', 1]]);
				}elseif(is_array($field['options'])){
					$subs	= wpjam_reduce($field['options'], fn($carry, $item, $opt)=> array_merge($carry, wpjam_map(
						is_array($item) ? ($item['fields'] ?? []) : [],
						fn($v, $k)=> $v+['show_if'=>[$nkey, 'IN', [...($carry[$k]['show_if'][2] ?? []), $opt]]]
					)), [], 'options');
				}

				$subs	= static::parse($subs, $parent, $expand);
			}

			$parsed	= array_merge($parsed ?? [], [$nkey=>$field], $subs);
		}

		return $parsed ?? [];
	}
}

class WPJAM_Processor extends WPJAM_Args{
	public function init(){
		if($this->formulas){
			return $this;
		}

		$formulas	= ['row'=>[], 'group'=>[], 'agg'=>[], 'post'=>[], 'post_agg'=>[]];

		foreach($this->fields as $k => $v){
			if(str_starts_with($k, 'var__')){
				$this->parse('var', $k);
			}else{
				if($m = $v['mapping'] ?? ''){
					if(str_starts_with($m[1], '$')){
						$f		= wpjam_prefix(substr($m[1], 1), 'var__');
						$m[1]	= isset($this->fields[$f]) ? ['ref'=>$f] : wpjam_invalid('mapping', '字段「'.$k.'」引用的变量「'.$m[1].'」未定义');
					}

					$mapping[$m[0]][$k]	= $m[1];
				}

				if($s = $v['summable'] ?? ($v['sumable'] ?? 0)){
					$summable[$s][$k]	= 0;
				}

				if(($e = $v['if_error'] ?? '') || is_numeric($e)){
					$if_errors[$k]	= $e;
				}

				$this->parse('formula', $k);
			}
		}

		foreach($this->formulas ?: [] as $k => $v){
			$s2	= isset($summable[2][$k]);
			$s3	= isset($summable[3][$k]);
			$p	= array_any($v, fn($t)=> str_starts_with($t, '@avg') || str_starts_with($t, '@sum') || (str_starts_with($t, '$') && isset($formulas['post'][substr($t, 1)])));

			$formulas[$p ? 'post' : 'row'][$k]	= $v;

			if(!$p && ($s2 || $s3)){
				$formulas['group'][$k]	= $v;
			}

			if(!$p && $s2){
				$formulas['agg'][$k]	= $v;
			}

			if($s2){
				$formulas['post_agg'][$k]	= $v;
			}
		}

		$summable	= wpjam_group($summable[1] ?? [], fn($v, $k)=> isset($formulas['post'][$k]) ? 'post' : 'row')+['row'=>[], 'post'=>[]];

		return $this->update_args(['formulas'=>$formulas, 'summable'=>$summable, 'mapping'=>($mapping ?? []), 'if_errors'=>($if_errors ?? [])]);
	}

	public function get_fields($type='', $args=[]){
		if($type == 'formula'){
			return [$type=>$args+['type'=>'text', 'class'=>'mix-tag', 'data-sep'=>['+', '-', '*', '/', '(', ')'], 'data-items'=>wpjam_array($this->fields, fn($k, $v)=> ['$'.$k, $v['title']])]];
		}

		return $this->fields;
	}

	public function get_field($key, $attr=''){
		if(str_starts_with($attr, 'title.')){
			return $this->get_field($key, 'title').'「'.$key.'」'.(($type = explode('.', $attr)[1]) === 'formula' ? '公式「'.$this->get_field($key, $type).'」' : '');
		}

		$field	= $this->get_arg('fields['.$key.']');

		return $field && $attr ? wpjam_get($field, $attr === 'var' ? 'rules' : $attr) : $field;
	}

	protected function parse($type, $key, $path=[]){
		if($this->get_arg($type.'s['.$key.']') || !($value = $this->get_field($key, $type))){
			return;
		}

		$title	= $type === 'formula' ? '公式' : '变量';

		if(($pos = array_search($key, $path)) !== false){
			wpjam_invalid($type, $title.'引用循环：'.implode(' → ', wpjam_map(array_slice($path, $pos), fn($k)=> $this->get_field($k, 'title.'.$type))));
		}

		$value	= $type === 'formula' ? wpjam_formula($value, $this->fields, $this->get_field($key, 'title.'.$type)) : $value;

		foreach($value as $i => $v){
			if($ref = $type === 'formula'
				? (str_starts_with($v, '$') ? substr($v, 1) : '')
				: (isset($v['ref']) ? ($value[$i]['ref']	= wpjam_prefix($v['ref'], 'var__')) : '')
			){
				$this->parse($type, isset($this->fields[$ref]) ? $ref : wpjam_invalid($type, $title.$this->get_field($key, 'title.'.$type).'引用的「'.$ref.'」未定义'), [...$path, $key]);
			}
		}

		$this->update_arg($type.'s['.$key.']', $value);
	}

	protected function prepare($item){
		foreach($this->vars as $var => $rules){
			if($r = wpjam_rule($rules, $item, 'when')){
				$item[$var]	= isset($r['ref']) ? ($item[$r['ref']] ?? '') : $r['value'];
			}
		}

		foreach(wpjam_pull($item, array_keys($this->mapping)) as $m => $v){
			foreach($this->mapping[$m] as $to => $sk){
				$sk	= is_array($sk) ? ($item[$sk['ref']] ?? '') : $sk;

				if(!is_blank($sk) && isset($v[$sk])){
					$item[$to]	= $v[$sk];
				}
			}
		}

		return $item;
	}

	public function process($data, $args=[]){
		$this->init();

		$stage	= $args['stage'] ?? 'row';
		$calc	= $args['calc'] ?? 'row';
		$filter = $args['filter'] ?? [];
		$vars	= wpjam_entry($args['vars'] ?? []);

		if($groupby	= isset($args['groupby']) && $args['groupby'] !== false ? (array)$args['groupby'] : ''){
			$columns	= wpjam_pull($groupby, 'columns') ?: [];
			$groupby	= $groupby['fields'] ?? $groupby;
			$columns	= array_fill_keys($columns, true)+array_fill_keys($groupby, true);
		}

		if(!$groupby){
			$avg	= $args['avg'] ?? false;
			$sum	= $args['sum'] ?? [];
			$acc	= $stage === 'post' ? ($args['acc'] ?? null) : (is_array($sum) || is_array($avg) ? [] : false);
		}

		$i		= 0;
		$items	= [];
		$packed	= is_array($data) && isset($data['items'], $data['columns']);

		foreach($packed ? $data['items'] : $data as $k => $item){
			if($packed){
				$dict	??= $data['dict'] ?? [];
				$item	= wpjam_record($item, $data['columns'], $dict);
			}

			if($stage === 'row' && $calc === 'row'){
				$cb 	??= $args['item_callback'] ?? '';
				$item	= $cb ? $cb($item, $k) : $item;
				$item	= $this->prepare($item);
			}

			if($calc){
				$item	= $this->calc($item, ['@key'=>$k, '@index'=>$i]+$vars, $calc);
				$i++;
			}

			if($filter && !wpjam_matches($item, $filter)){
				continue;
			}

			if($groupby){
				$g	= array_map(fn($gf)=> $item[$gf] ??= '', $groupby);
				$g	= count($g) > 1 ? serialize($g) : $g[0];

				$items[$g]	??= array_intersect_key($item, $columns);
				$acc		= &$items[$g];
			}else{
				$items[$k]	= $item;
			}

			if(is_array($acc) && $this->summable[$stage]){
				foreach(array_intersect_key($item, $this->summable[$stage]) as $sk => $sv){
					if($sv = wpjam_format($sv ?? 0, '-', 0)){
						$acc[$sk]	= ($acc[$sk] ?? 0)+$sv;
					}
				}
			}
		}

		if($groupby){
			return $this->process($items, ['stage'=>'row', 'calc'=>'group', 'vars'=>$vars, 'groupby'=>false, 'filter'=>($args['having'] ?? [])]+$args);
		}

		$total	= count($items);
		$vars	+= ['@total'=>$total];

		if(is_array($acc) && $total){
			$vars['@sum']	= $acc = $this->calc($acc, ['@key'=>'@sum']+$vars, $stage);

			if(is_array($avg)){
				$vars['@avg']	= $avg = $this->calc(array_map(fn($v)=> $v/$total, wpjam_filter($acc, 'is_numeric')), ['@key'=>'@avg']+$vars, $stage)+$avg;
			}

			if($stage !== 'post' && $this->formulas['post']){
				return $this->process($items, ['stage'=>'post', 'calc'=>'post', 'acc'=>$acc, 'vars'=>$vars, 'filter'=>false]+$args);
			}
		}

		$sum	= is_array($sum) ? $acc+$sum : false;
		$items	= ($args['orderby'] ?? '') ? wpjam_sort($items, $args['orderby'], $args['order'] ?? '') : $items;
		$number	= (int)($args['number'] ?? 0);
		$offset	= (int)($args['offset'] ?? 0);
		$res	= ['sum'=>$sum, 'avg'=>$avg, 'items'=>$items];

		if($chart = $args['chart'] ?? false){
			$chart	= isset($chart['type']) ? ['chart'=>$chart] : $chart;

			foreach($chart as $ck => &$cv){
				$c_type	= $cv['type'];
				$c_args	= $cv['args'] ?? [];
				$c_data	= $res[($cv['source'] ?? 'items')] ?? $items;

				if($c_type === 'donut'){
					$c_args['total']	??= is_null($k = wpjam_at($c_args['fields'] ?? [], 1, 'key')) ? 0 : ($acc[$k] ?? 0);

					$c_data	= wpjam_sort($c_data, 'ar');
					$c_num	= 10;
				}else{
					$c_num	= $c_type === 'bar' ? 30 : null;
				}

				$cv	= ['title'=>$cv['title'] ?? '', 'args'=>[$c_type, array_slice($c_data, 0, $c_num), $c_args]];
			}
		}

		$items	= $number > 0 && ($offset || $total > $number) ? array_slice($items, $offset, $number) : $items;

		return ['total'=>$total, 'items'=>$items]+array_filter($res+['chart'=>$chart], 'is_array');
	}

	public function calc($item, $vars=[], $type='row'){
		$entry	= ['@value'=>&$item];
		$key	= $vars['@key'] ?? '';

		if(in_array($key, ['@avg', '@sum'], true)){
			if($type == 'post'){
				$entry	+= [$key=>&$item];
				$type	.= '_agg';
			}else{
				$type	= 'agg';
			}
		}

		foreach($this->formulas[$type] as $k => $formula){
			if($v = wpjam_calc($formula, $entry+$vars, [$k=>$this->if_errors[$k] ?? null]+$this->if_errors)){
				$item[$k]	= $v;
			}else{
				unset($item[$k]);
			}
		}

		return $item;
	}

	public function sum($items, $args=[]){
		return $items ? $this->process($items, ['sum'=>[]]+$args)['sum'] : [];
	}

	public static function create($fields, $vars=[]){
		return new static([
			'fields'	=> $fields+wpjam_array($vars, fn($k)=> wpjam_prefix($k, 'var__')),
			'vars'		=> [],
			'formulas'	=> []
		]);
	}
}
