<?php
if(!function_exists('is_closure')){
	function is_closure($object){
		return $object instanceof Closure;
	}
}

if(!function_exists('base64_urlencode')){
	function base64_urlencode($str){
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}
}

if(!function_exists('base64_urldecode')){
	function base64_urldecode($str){
		return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '='));
	}
}

// JWT
function wpjam_generate_jwt($payload, $key='', $header=[]){
	return WPJAM_JWT::generate($payload, $key, $header);
}

function wpjam_verify_jwt($token, $key=''){
	return WPJAM_JWT::verify($token, $key);
}

function wpjam_get_jwt($key='access_token', $required=false){
	return WPJAM_JWT::get($key, $required);
}

// user agent
function wpjam_get_user_agent(){
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function wpjam_get_ip(){
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function wpjam_parse_user_agent($user_agent=null, $referer=null){
	return WPJAM_Var::parse_user_agent($user_agent, $referer);
}

function wpjam_parse_ip($ip=''){
	return WPJAM_Var::parse_ip($ip);
}

function wpjam_var($name, ...$args){
	$object	= WPJAM_Var::get_instance();

	return $args ? ($object->$name = $args[0]) : $object->$name;
}

function wpjam_get_current_var($name){
	return wpjam_var($name);
}

function wpjam_set_current_var($name, $value){
	return wpjam_var($name, $value);
}

function wpjam_get_current_user($required=false){
	return WPJAM_Var::get_current_user($required);
}

function wpjam_current_supports($feature){
	return (WPJAM_Var::get_instance())->supports($feature);
}

function wpjam_get_device(){
	return wpjam_var('device');
}

function wpjam_get_os(){
	return wpjam_var('os');
}

function wpjam_get_app(){
	return wpjam_var('app');
}

function wpjam_get_browser(){
	return wpjam_var('browser');
}

function wpjam_get_version($key){
	return wpjam_var($key.'_version');
}

function is_ipad(){
	return wpjam_get_device() == 'iPad';
}

function is_iphone(){
	return wpjam_get_device() == 'iPone';
}

function is_ios(){
	return wpjam_get_os() == 'iOS';
}

function is_macintosh(){
	return wpjam_get_os() == 'Macintosh';
}

function is_android(){
	return wpjam_get_os() == 'Android';
}

function is_weixin(){
	if(isset($_GET['weixin_appid'])){
		return true;
	}

	return wpjam_get_app() == 'weixin';
}

function is_weapp(){
	if(isset($_GET['appid'])){
		return true;
	}

	return wpjam_get_app() == 'weapp';
}

function is_bytedance(){
	if(isset($_GET['bytedance_appid'])){
		return true;
	}

	return wpjam_get_app() == 'bytedance';
}

// Cache
function wpjam_cache($group, $args=[]){
	return WPJAM_Cache::get_instance($group, $args);
}

function wpjam_generate_verification_code($key, $group='default'){
	return (WPJAM_Cache::get_verification($group))->generate($key);
}

function wpjam_verify_code($key, $code, $group='default'){
	return (WPJAM_Cache::get_verification($group))->verify($key, $code);
}

// Asset
function wpjam_script($handle, $args=[]){
	WPJAM_Asset::handle('script', $handle, $args);
}

function wpjam_style($handle, $args=[]){
	WPJAM_Asset::handle('style', $handle, $args);
}

function wpjam_add_static_cdn($host){
	WPJAM_Asset::add_static_cdn($host);
}

function wpjam_get_static_cdn(){
	return WPJAM_Asset::get_static_cdn();
}

// Parameter
function wpjam_get_parameter($name='', $args=[], $method=''){
	return WPJAM_Parameter::get_value($name, $args, $method);
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
	return WPJAM_Parameter::method_allow($method);
}

// Request
function wpjam_remote_request($url='', $args=[], $err=[]){
	$throw	= wpjam_pull($args, 'throw');
	$field	= wpjam_pull($args, 'field') ?? 'body';
	$result	= WPJAM_Http::request($url, $args, $err);

	if(is_wp_error($result)){
		return $throw ? wpjam_throw($result) : $result;
	}

	return $field ? wpjam_get($result, $field) : $result;
}

// File
function wpjam_url($dir, $scheme=null){
	$path	= str_replace([rtrim(ABSPATH, '/'), '\\'], ['', '/'], $dir);

	return $scheme == 'relative' ? $path : site_url($path, $scheme);
}

function wpjam_dir($url){
	return ABSPATH.str_replace(site_url('/'), '', $url);
}

function wpjam_upload($name){
	return WPJAM_File::upload($name);
}

function wpjam_upload_bits($bits, $name, $media=true, $post_id=0){
	return WPJAM_File::upload_bits($bits, $name, $media, $post_id);
}

function wpjam_download_url($url, $name='', $media=true, $post_id=0){
	return WPJAM_File::download_url($url, $name, $media, $post_id);
}

function wpjam_scandir($dir, $callback=null){
	return WPJAM_File::scandir($dir, $callback);
}

function wpjam_import($file, $columns=[]){
	return WPJAM_File::import($file, $columns);
}

function wpjam_export($file, $data, $columns=[]){
	WPJAM_File::export($file, $data, $columns);
}

function wpjam_bits($str){
	return 'data:'.finfo_buffer(finfo_open(), $str, FILEINFO_MIME_TYPE).';base64, '.base64_encode($str);
}

function wpjam_is_external_url($url, $scene=''){
	$status	= wpjam_every(['http', 'https'], fn($v)=> !str_starts_with($url, site_url('', $v)));

	return apply_filters('wpjam_is_external_url', $status, $url, $scene);
}

function wpjam_fetch_external_images(&$urls, $post_id=0){
	$args	= ['post_id'=>$post_id, 'media'=>(bool)$post_id, 'field'=>'url'];
		
	foreach($urls as $url){
		if($url && wpjam_is_external_url($url, 'fetch')){
			$download	= wpjam_download_url($url, $args);

			if(!is_wp_error($download)){
				$search[]	= $url;
				$replace[]	= $download;
			}	
		}
	}

	$urls	= $search ?? [];

	return $replace ?? [];
}

// 1. $img
// 2. $img, ['width'=>100, 'height'=>100]	// 这个为最标准版本
// 3. $img, 100x100
// 4. $img, 100
// 5. $img, [100,100]
// 6. $img, [100,100], $crop=1, $ratio=1
// 7. $img, 100, 100, $crop=1, $ratio=1
function wpjam_get_thumbnail($img, ...$args){
	$url	= ($img && is_numeric($img)) ? WPJAM_File::convert($img, 'id', 'url') : $img;

	return $url ? WPJAM_File::get_thumbnail(remove_query_arg(['orientation', 'width', 'height'], wpjam_zh_urlencode($url)), ...$args) : '';
}

function wpjam_get_thumbnail_args(...$args){
	return WPJAM_File::get_thumbnail('', ...$args);
}

function wpjam_parse_size($size, $ratio=1){
	return WPJAM_File::parse_size($size, $ratio);
}

function wpjam_get_image_size($value, $type='id'){
	$size	= WPJAM_File::convert($value, $type, 'size');
	$size	= apply_filters('wpjam_image_size', $size, $value, $type);

	return $size ? array_map('intval', $size)+['orientation'=> $size['height'] > $size['width'] ? 'portrait' : 'landscape'] : $size;
}

function wpjam_is_image($value, $type=''){
	$type	= $type ?: (is_numeric($value) ? 'id' : 'url');

	if($type == 'url'){
		$url	= wpjam_remove_postfix(explode('?', $value)[0], '#');

		return preg_match('/\.('.implode('|', wp_get_ext_types()['image']).')$/i', $url);
	}elseif($type == 'file'){
		return !empty(WPJAM_File::convert($value, $type, 'size'));
	}elseif($type == 'id'){
		return wp_attachment_is_image($value);
	}
}

function wpjam_parse_image_query($url){
	$query	= wp_parse_args(parse_url($url, PHP_URL_QUERY));

	return wpjam_map($query, fn($v, $k)=> in_array($k, ['width', 'height']) ? (int)$v : $v);
}

// Video
function wpjam_get_video_mp4($id_or_url){
	return WPJAM_Video::get_mp4($id_or_url);
}

function wpjam_get_qqv_mp4($vid){
	return WPJAM_Video::get_qqv_mp4($vid);
}

function wpjam_get_qqv_id($id_or_url){
	return WPJAM_Video::get_qqv_id($id_or_url);
}

// Attr
function wpjam_attr($attr, $type=''){
	return WPJAM_Attr::create($attr, $type);
}

function wpjam_is_bool_attr($attr){
	return WPJAM_Attr::is_bool($attr);
}

// Tag
function wpjam_tag($tag='', $attr=[], $text=''){
	return new WPJAM_Tag($tag, $attr, $text);
}

function wpjam_wrap($text, $wrap='', ...$args){
	if((is_array($wrap) || is_closure($wrap))){
		$text	= is_callable($wrap) ? $wrap($text, ...$args) : $text;
		$wrap	= '';
	}

	return (is_a($text, 'WPJAM_Tag') ? $text : wpjam_tag('', [], $text))->wrap($wrap, ...$args);
}

function wpjam_is_single_tag($tag){
	return WPJAM_Tag::is_single($tag);;
}

function wpjam_html_tag_processor($html, $query=null){
	$proc	= new WP_HTML_Tag_Processor($html);

	return $proc->next_tag($query) ? $proc : null;
}

// Field
function wpjam_fields($fields, $args=[]){
	$object	= WPJAM_Fields::create($fields);

	if($args){
		$echo	= wpjam_pull($args, 'echo', true);
		$result	= $object->render($args);

		return $echo ? wpjam_echo($result) : $result;
	}

	return $object;
}

function wpjam_get_fields_parameter($fields, $method='POST'){
	return $fields ? wpjam_fields($fields)->get_parameter($method) : wpjam_get_parameter('', [], $method);
}

function wpjam_field($field, $args=[]){
	$object	= WPJAM_Field::create($field);

	if($args){
		$tag	= wpjam_pull($args, 'wrap_tag');

		return isset($tag) ? $object->wrap($tag, $args) : $object->render($args);
	}

	return $object;
}

function wpjam_add_pattern($key, $args){
	WPJAM_Field::add_pattern($key, $args);
}

function wpjam_icon($icon){
	if(str_starts_with($icon, 'dashicons-')){
		return wpjam_tag('span', ['dashicons', $icon]);
	}elseif(str_starts_with($icon, 'ri-')){
		return wpjam_tag('i', $icon);
	}
}

// $value, $args
// $value, $value2
// $value, $compare, $value2, $strict=false
function wpjam_compare($value, $compare, ...$args){
	if(wpjam_is_assoc_array($compare)){
		return wpjam_if($value, $compare);
	}

	if(is_array($compare) || !$args){
		[$value2, $compare, $strict]	= [$compare, '', false];
	}else{
		$value2	= $args[0];
		$strict	= $args[1] ?? false;
	}

	if($compare){
		$compare	= strtoupper($compare);
		$antonym	= ['!='=>'=', '<='=>'>', '>='=>'<', 'NOT IN'=>'IN', 'NOT BETWEEN'=>'BETWEEN'][$compare] ?? '';

		if($antonym){
			return !wpjam_compare($value, $antonym, $value2, $strict);
		}
	}else{
		$compare	= is_array($value2) ? 'IN' : '=';
	}

	if(in_array($compare, ['IN', 'BETWEEN'])){
		$value2	= wp_parse_list($value2);

		if(!is_array($value) && count($value2) == 1){
			$value2		= $value2[0];
			$compare	= '=';
		}
	}else{
		if(is_string($value2)){
			$value2	= trim($value2);
		}
	}

	if($compare == '='){
		return $strict ? ($value === $value2) : ($value == $value2);
	}elseif($compare == '>'){
		return $value > $value2;
	}elseif($compare == '<'){
		return $value < $value2;
	}elseif($compare == 'IN'){
		if(is_array($value)){
			return wpjam_every($value, fn($v)=> in_array($v, $value2, $strict));
		}else{
			return in_array($value, $value2, $strict);
		}
	}elseif($compare == 'BETWEEN'){
		return $value >= $value2[0] && $value <= $value2[1];
	}

	return false;
}

function wpjam_if($item, $args){
	$compare	= wpjam_get($args, 'compare');
	$value2		= wpjam_get($args, 'value');
	$key		= wpjam_get($args, 'key');
	$value		= wpjam_get($item, $key);

	if(wpjam_get($args, 'callable') && is_callable($value)){
		return $value($value2, $item);
	}

	if(is_null($compare) && isset($args['if_null']) && is_null($value)){
		return $args['if_null'];
	}

	if(is_array($value) || wpjam_get($args, 'swap')){
		[$value, $value2]	= [$value2, $value];
	}

	return wpjam_compare($value, $compare, $value2, (bool)wpjam_get($args, 'strict'));
}

function wpjam_parse_show_if($if){
	if(wp_is_numeric_array($if) && count($if) >= 2){
		$args	= [];
		$keys 	= count($if) == 2 ? ['key', 'value'] : ['key', 'compare', 'value'];

		if(count($if) > 3){
			$args	= is_array($if[3]) ? $if[3] : [];
			$if		= array_slice($if, 0, 3);
		}

		return array_combine($keys, $if)+$args;
	}

	return $if;
}

function wpjam_match($item, $args=[], $operator='AND'){
	$op	= strtoupper($operator);
	$fn	= fn($v, $k)=> wpjam_if($item, wpjam_is_assoc_array($v) ? $v+['key'=>$k] : ['key'=>$k, 'value'=>$v]);

	if('OR' === $op){
		return wpjam_some($args, $fn);
	}elseif('AND' === $op){
		return wpjam_every($args, $fn);
	}elseif('NOT' === $op){
		return !wpjam_every($args, $fn);
	}

	return false;
}

function wpjam_calc(&$item, $formulas, $if_errors=[], $key=null){
	if(is_null($key)){
		$if_errors	= wpjam_filter($if_errors, fn($v)=> $v || is_numeric($v));
		$item		= wpjam_except($item, array_keys($formulas));

		foreach($formulas as $key => $formula){
			if(!is_array($formula)){
				$item[$key]	= $formula;
			}elseif(!isset($item[$key])){
				$item[$key]	= wpjam_calc($item, $formulas, $if_errors, $key);
			}
		}

		return $item;
	}

	$formula	= $formulas[$key] ?? null;
	$if_error	= $if_errors[$key] ?? null;

	foreach($formula as &$t){
		if(str_starts_with($t, '$')){
			$k	= wpjam_remove_prefix($t, '$');

			if(!isset($item[$k]) && isset($formulas[$k])){
				$item[$k]	= wpjam_calc($item, $formulas, $if_errors, $k);
			}

			if(isset($item[$k]) && is_numeric(trim($item[$k]))){
				$t	= $item[$k];
			}else{
				if(!isset($if_errors[$k])){
					return $if_error ?? '!无法计算';
				}

				$t	= $if_errors[$k];
			}

			if(!$t && isset($p) && $p == '/'){
				return $if_error ?? '!除零错误';
			}
		}

		$p	= $t;
	}

	return eval('return '.implode('', $formula).';');
}

function wpjam_parse_formula($formula, $vars=[], $title=''){
	$formula	= preg_replace('@\s@', '', $formula);
	$signs		= ['+', '-', '*', '/', '(', ')', ',', '\'', '.', '%'];
	$pattern	= '/([\\'.implode('\\', $signs).'])/';
	$formula	= preg_split($pattern, $formula, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
	$methods	= ['abs', 'ceil', 'pow', 'sqrt', 'pi', 'max', 'min', 'fmod', 'round'];

	foreach($formula as $token){
		if(!is_numeric($token) && !str_starts_with($token, '$') && !in_array($token, $signs) && !in_array(strtolower($token), $methods)){
			return new WP_Error('invalid_formula', $title.'的公式「'.$formula.'」错误，无效的「'.$token.'」');
		}

		if(str_starts_with($token, '$') && !in_array(wpjam_remove_prefix($token, '$'), $vars)){
			return new WP_Error('invalid_formula', $title.'的公式「'.$formula.'」错误，「'.$token.'」未定义');
		}
	}

	return $formula;
}

function wpjam_format($item, $formats){
	foreach($formats as $k => $format){
		if(isset($item[$k]) && is_numeric($item[$k])){
			if($format == '%'){
				$item[$k]	= round($item[$k] * 100, 2).'%';
			}elseif($format == ','){
				$item[$k]	= number_format(trim($item[$k]), 2);
			}
		}
	}

	return $item;
}

function wpjam_sum($total, $item){
	foreach($item as $k => $v){
		$v	= str_replace(',', '', $v);

		if(is_numeric($v)){
			$total[$k]	= isset($total[$k]) ? str_replace(',', '', $total[$k]) : 0;
			$total[$k]	= (empty($total[$k]) || !is_numeric($total[$k])) ? 0 : $total[$k];
			$total[$k]	+= $v;
		}
	}

	return $total;
}

// Array
function wpjam_is_assoc_array($arr){
	return is_array($arr) && !wp_is_numeric_array($arr);
}

function wpjam_is_array_accessible($arr){
	return is_array($arr) || $arr instanceof ArrayAccess;
}

function wpjam_array($arr=null, $callback=null){
	if(is_object($arr)){
		if(method_exists($arr, 'to_array')){
			$data	= $arr->to_array();
		}elseif($arr instanceof ArrayAccess){
			foreach($arr as $k => $v){
				$data[$k]	= $v;
			}
		}
	}else{
		$data	= is_null($arr) ? [] : (array)$arr;
	}

	if($callback && is_callable($callback)){
		foreach($data as $k => $v){
			$result		= $callback($k, $v);
			[$k, $v]	= is_array($result) ? $result : [$result, $v];

			if(!is_null($k)){
				$new[$k]	= $v;
			}
		}

		return $new ?? [];
	}

	return $data;
}

function wpjam_fill($keys, $callback){
	return wpjam_array($keys, fn($k, $v)=>[$v, $callback($v)]);
}

function wpjam_map($arr, $callback){
	foreach($arr as $k => &$v){
		$v	= $callback($v, $k);
	}

	return $arr;
}

function wpjam_reduce($arr, $callback, $initial=null){
	return array_reduce(wpjam_map($arr, fn($v, $k)=> [$v, $k]), fn($carry, $item)=> $callback($carry, ...$item), $initial);
}

function wpjam_at($arr, $index){
	$values	= array_values($arr);
	$count	= count($arr);
	$index	= $index >= 0 ? $index : $count + $index;

	return ($index >= 0 && $index < $count) ? $values[$index] : null;
}

function wpjam_add_at($arr, $index, $key, $value=''){
	if(is_null($key)){
		array_splice($arr, $index, 0, [$value]);

		return $arr;
	}else{
		$value	= is_array($key) ? $key : [$key=>$value];

		return array_replace(array_slice($arr, 0, $index, true), $value, array_slice($arr, $index, null, true));
	}
}

function wpjam_every($arr, $callback){
	foreach($arr as $k => $v){
		if(!$callback($v, $k)){
			return false;
		}
	}

	return $arr ? true : false;
}

function wpjam_some($arr, $callback){
	foreach($arr as $k => $v){
		if($callback($v, $k)){
			return true;
		}
	}

	return false;
}

function wpjam_until($arr, $callback){
	foreach($arr as $k => $v){
		if($callback($v, $k)){
			break;
		}
	}
}

function wpjam_find($arr, $callback, $return='value', &$result=null){
	$i	= 0;

	foreach($arr as $k => $v){
		$result	= wpjam_is_assoc_array($callback) ? wpjam_match($v, $callback) : $callback($v, $k);

		if($result){
			if($return == 'index'){
				return $i;
			}elseif($return == 'key'){
				return $k;
			}elseif($return == 'result'){
				return $result;
			}else{
				return $v;
			}
		}

		$i++;
	}

	return false;
}

function wpjam_group($arr, $field){
	foreach($arr as $k => $v){
		$g = wpjam_get($v, $field);

		$grouped[$g][$k] = $v;
	}

	return $grouped ?? [];
}

function wpjam_pull(&$arr, $key, ...$args){
	if(is_array($key)){
		if(wp_is_numeric_array($key)){
			$value	= wpjam_slice($arr, $key);
		}else{
			$value	= wpjam_map($key, fn($v, $k)=> $arr[$k] ?? $v);
			$key	= array_keys($key);
		}
	}else{
		$value	= wpjam_get($arr, $key, array_shift($args));
	}

	$arr	= wpjam_except($arr, $key);

	return $value;
}

function wpjam_except($arr, $key){
	if(is_object($arr)){
		unset($arr[$key]);

		return $arr;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true));
		return $arr;
	}

	if(is_array($key)){
		return array_reduce($key, 'wpjam_except', $arr);
	}

	if(wpjam_exists($arr, $key)){
		unset($arr[$key]);
	}elseif(str_contains($key, '.')){
		$key	= explode('.', $key);
		$sub	= &$arr;

		while($key){
			$k	= array_shift($key);

			if(empty($key)){
				unset($sub[$k]);
			}elseif(wpjam_exists($sub, $k)){
				$sub = &$sub[$k];
			}else{
				break;
			}
		}
	}

	return $arr;
}

function wpjam_merge($arr, $data, $deep=true){
	if($deep){
		foreach($data as $k => $v){
			$arr[$k]	= (wpjam_is_assoc_array($v) && isset($arr[$k]) && wpjam_is_assoc_array($arr[$k])) ? wpjam_merge($arr[$k], $v) : $v;
		}

		return $arr;
	}

	return array_merge($arr, $data);
}

function wpjam_diff($arr, $data, $deep=true){
	if($deep){
		foreach($data as $k => $v){
			if(isset($arr[$k])){
				if(wpjam_is_assoc_array($v) && wpjam_is_assoc_array($arr[$k])){
					$arr[$k]	= wpjam_diff($arr[$k], $v);
				}else{
					unset($arr[$k]);
				}
			}
		}

		return $arr;
	}

	return array_diff($arr, $data);
}

function wpjam_slice($arr, $keys){
	$keys	= is_array($keys) ? $keys : wp_parse_list($keys);

	return array_intersect_key($arr, array_flip($keys));
}

function wpjam_filter($arr, $callback, $deep=null){
	if(wpjam_is_assoc_array($callback)){
		$args	= $callback;
		$op		= $deep ?? 'AND';

		return array_filter($arr, fn($v)=> wpjam_match($v, $args, $op));
	}elseif(wp_is_numeric_array($callback)){
		if(!is_callable($callback)){
			return wpjam_slice($arr, $callback);
		}
	}elseif($callback == 'isset'){
		$callback	= fn($v)=> !is_null($v);
		$deep		??= true;
	}elseif($callback == 'filled'){
		$callback	= fn($v)=> $v || is_numeric($v);
		$deep		??= true;
	}

	if($deep){
		foreach($arr as &$v){
			if(is_array($v)){
				$v	= wpjam_filter($v, $callback, $deep);
			}
		}
	}

	return array_filter($arr, $callback, ARRAY_FILTER_USE_BOTH);
}

function wpjam_sort($arr, ...$args){
	if($args && wpjam_is_assoc_array($args[0])){
		return wp_list_sort($arr, $args[0], '', true);
	}

	if(!$args || is_int($args[0])){
		sort($arr, ...$args);
	}elseif(!$args[0] || in_array($args[0], ['k', 'a', 'kr', 'ar', 'r'])){
		$sort	= array_shift($args).'sort';

		$sort($arr, ...$args);
	}elseif($args[0]){
		$cb		= $args[0];
		$by		= $args[1] ?? 'a';
		$by		= ['key'=>'k', 'assoc'=>'a'][$by] ?? $by;
		$sort	= [''=>'usort', 'k'=>'uksort', 'a'=>'uasort'][$by] ?? 'uasort';
		$fn		= fn($a, $b)=> $cb($b)<=>$cb($a);

		$sort($arr, $fn);
	}

	return $arr;
}

function wpjam_exists($arr, $key){
	return isset($arr->$key) ?: (is_array($arr) ? array_key_exists($key, $arr) : false);
}

function wpjam_get($arr, $key, $default=null){
	if(is_object($arr)){
		return $arr->$key ?? $default;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true));
		return $default;
	}

	if(is_null($key)){
		return $arr;
	}

	if(!is_array($key)){
		if(wpjam_exists($arr, $key)){
			return $arr[$key];
		}

		if(!str_contains($key, '.')){
			return $default;
		}

		$key	= explode('.', $key);
	}

	return _wp_array_get($arr, $key, $default);
}

function wpjam_set($arr, $key, $value){
	if(is_object($arr)){
		$arr->$key = $value;

		return $arr;
	}

	if(!is_array($arr)){
		return $arr;
	}

	if(is_null($key)){
		$arr[]	= $key;

		return $arr;
	}

	if(!is_array($key)){
		if(wpjam_exists($arr, $key) || !str_contains($key, '.')){
			$arr[$key] = $value;

			return $arr;
		}
		
		$key	= explode('.', $key);
	}

	_wp_array_set($arr, $key, $value);

	return $arr;
}

if(!function_exists('array_pull')){
	function array_pull(&$arr, $key, $default=null){
		return wpjam_pull($arr, $key, $default);
	}
}

if(!function_exists('array_except')){
	function array_except($array, ...$keys){
		$keys	= ($keys && is_array($keys[0])) ? $keys[0] : $keys;

		return wpjam_except($array, $keys);
	}
}

if(!function_exists('filter_deep')){
	function filter_deep($arr, $data){
		return wpjam_filter($arr, $callback, true);
	}
}

if(!function_exists('merge_deep')){
	function merge_deep($arr, $data){
		return wpjam_merge($arr, $data, true);
	}
}

if(!function_exists('diff_deep')){
	function diff_deep($arr, $data){
		return wpjam_diff($arr, $data, true);
	}
}

function_alias('wpjam_is_array_accessible',	'array_accessible');	
function_alias('wpjam_array',	'array_wrap');
function_alias('wpjam_get',		'array_get');
function_alias('wpjam_set',		'array_set');
function_alias('wpjam_find',	'array_find');
function_alias('wpjam_until',	'array_until');
function_alias('wpjam_every',	'array_every');
function_alias('wpjam_some',	'array_some');
function_alias('wpjam_group',	'array_group');
function_alias('wpjam_sort',	'array_sort');
function_alias('wpjam_at',		'array_at');
function_alias('wpjam_add_at',	'array_add_at');

function wpjam_move($arr, $id, $data){
	if(!in_array($id, $arr)){
		return new WP_Error('invalid_id', '无效的 ID');
	}

	$k	= wpjam_find(['next', 'prev'], fn($k)=> isset($data[$k]));
	$to	= $k ? $data[$k] : null;

	if(is_null($to) || !in_array($to, $arr)){
		return new WP_Error('invalid_position', '无效的移动位置');
	}

	$arr	= array_values(array_diff($arr, [$id]));
	$index	= array_search($to, $arr)+($k == 'prev' ? 1 : 0);

	return wpjam_add_at($arr, $index, null, $id);
}

function wpjam_has_bit($value, $bit){
	return ((int)$value & (int)$bit) == $bit;
}

function wpjam_add_bit($value, $bit){
	return $value = (int)$value | (int)$bit;
}

function wpjam_remove_bit($value, $bit){
	return $value = (int)$value & (~(int)$bit);
}

function wpjam_create_uuid(){
	$chars	= md5(uniqid(mt_rand(), true));

	return implode('-', array_map(fn($args)=> substr($chars, ...$args), [[0, 8], [8, 4], [12, 4], [16, 4], [20, 12]]));
}

// String
function wpjam_echo($str){
	echo $str;
}

function wpjam_join($sep, ...$args){
	$arr	= ($args && is_array($args[0])) ? $args[0] : $args;

	return join($sep, array_filter($arr));
}

function wpjam_fix($action, $type, $str, $fix, &$acted=false, $replace=''){
	if($fix){
		$prev	= in_array($type, ['prefix', 'prev']);
		$has	= $prev ? str_starts_with($str, $fix) : str_ends_with($str, $fix);

		if(($action == 'add') XOR $has){
			$acted	= true;

			if($has){
				$len	= strlen($fix);

				return $prev ? $replace.substr($str, $len) : substr($str, 0, strlen($str) - $len).$replace;
			}else{
				return $prev ? $fix.$str : $str.$fix;
			}
		}
	}

	return $str;
}

function wpjam_remove_prefix($str, $prefix, &$removed=false){
	return wpjam_fix('remove', 'prev', $str, $prefix, $removed);
}

function wpjam_remove_postfix($str, $postfix, &$removed=false){
	return wpjam_fix('remove', 'post', $str, $postfix, $removed);
}

function wpjam_remove_pre_tab($str, $times=1){
	return preg_replace('/^\t{'.$times.'}/m', '', $str);
}

function wpjam_unserialize($serialized, $callback=null){
	if($serialized){
		$result	= @unserialize($serialized);

		if(!$result){
			$fixed	= preg_replace_callback('!s:(\d+):"(.*?)";!', fn($m)=> 's:'.strlen($m[2]).':"'.$m[2].'";', $serialized);
			$result	= @unserialize($fixed);

			if($result && $callback){
				$callback($fixed);
			}
		}

		return $result;
	}
}

// 去掉非 utf8mb4 字符
function wpjam_strip_invalid_text($text, $charset='utf8mb4'){
	if(!$text){
		return '';
	}

	$regex	= '/
		(
			(?:	[\x00-\x7F]					# single-byte sequences   0xxxxxxx
			|	[\xC2-\xDF][\x80-\xBF]		# double-byte sequences   110xxxxx 10xxxxxx';

	if($charset === 'utf8mb3' || $charset === 'utf8mb4'){
		$regex	.= '
			|	\xE0[\xA0-\xBF][\x80-\xBF]	# triple-byte sequences   1110xxxx 10xxxxxx * 2
			|	[\xE1-\xEC][\x80-\xBF]{2}
			|	\xED[\x80-\x9F][\x80-\xBF]
			|	[\xEE-\xEF][\x80-\xBF]{2}';
	}

	if($charset === 'utf8mb4'){
		$regex	.= '
			|	\xF0[\x90-\xBF][\x80-\xBF]{2}	# four-byte sequences   11110xxx 10xxxxxx * 3
			|	[\xF1-\xF3][\x80-\xBF]{3}
			|	\xF4[\x80-\x8F][\x80-\xBF]{2}';
	}

	$regex		.= '
		){1,40}					# ...one or more times
		)
		| .						# anything else
		/x';

	return preg_replace($regex, '$1', $text);
}

// 去掉 4字节 字符
function wpjam_strip_4_byte_chars($text){
	return $text ? preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text) : '';
	// return preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $text);
}

// 去掉控制字符
function wpjam_strip_control_chars($text){
	// 移除 除了 line feeds 和 carriage returns 所有控制字符
	return $text ? preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', $text) : '';
	// return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $text);
}

function wpjam_strip_control_characters($text){
	return wpjam_strip_control_chars($text);
}

function wpjam_strip_tags($text){
	return $text ? trim(wp_strip_all_tags($text)) : $text;
}

//获取纯文本
function wpjam_get_plain_text($text){
	$text	= wpjam_strip_tags($text);

	if($text){
		$text	= str_replace(['"', '\''], '', $text);
		$text	= str_replace(["\r\n", "\n", "  "], ' ', $text);
		$text	= trim($text);
	}

	return $text;
}

//获取第一段
function wpjam_get_first_p($text){
	$text	= wpjam_strip_tags($text);

	return $text ? trim((explode("\n", $text))[0]) : '';
}

function wpjam_unicode_decode($text){
	// [U+D800 - U+DBFF][U+DC00 - U+DFFF]|[U+0000 - U+FFFF]
	// return mb_convert_encoding(pack("H*", $matches[1]), 'UTF-8', 'UCS-2BE');

	return preg_replace_callback('/(\\\\u[0-9a-fA-F]{4})+/i', fn($m)=> json_decode('"'.$m[0].'"') ?: $m[0], $text);
}

function wpjam_zh_urlencode($url){
	return $url ? preg_replace_callback('/[\x{4e00}-\x{9fa5}]+/u', fn($m)=> urlencode($m[0]), $url) : '';
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	if(!$text){
		return false;
	}

	$pre	= apply_filters('wpjam_pre_blacklist_check', null, $text, $name);

	if(!is_null($pre)){
		return $pre;
	}

	$words	= (array)explode("\n", get_option('disallowed_keys'));

	return wpjam_some($words, fn($w)=> (trim($w) && preg_match("#".preg_quote(trim($w), '#')."#i", $text)));
}

function wpjam_doing_debug(){
	if(isset($_GET['debug'])){
		return $_GET['debug'] ? sanitize_key($_GET['debug']) : true;
	}else{
		return false;
	}
}

function wpjam_do_shortcode($content, $tagnames, $ignore_html=false){
	if(str_contains($content, '[') && preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches)){
		$tagnames	= array_intersect((array)$tagnames, $matches[1]);
		$content	= do_shortcodes_in_html_tags($content, $ignore_html, $tagnames);
		$pattern	= get_shortcode_regex($tagnames);
		$content	= preg_replace_callback("/$pattern/", 'do_shortcode_tag', $content);
		$content	= unescape_invalid_shortcodes($content);
	}

	return $content;
}

function wpjam_parse_shortcode_attr($str, $tagnames=null){
	$pattern = get_shortcode_regex([$tagnames]);

	if(preg_match("/$pattern/", $str, $m)){
		return shortcode_parse_atts($m[3]);
	}

	return [];
}

function wpjam_get_current_page_url(){
	return set_url_scheme('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
}

function wpjam_date($format, $timestamp=null){
	$timestamp	??= time();

	return is_numeric($timestamp) ? date_create('@'.$timestamp)->setTimezone(wp_timezone())->format($format) : false;
}

function wpjam_strtotime($string){
	return $string ? date_create($string, wp_timezone())->getTimestamp() : 0;
}

function wpjam_human_time_diff($from, $to=0){
	return sprintf(__('%s '.(($to ?: time()) > $from ? 'ago' : 'from now')), human_time_diff($from, $to));
}

function wpjam_human_date_diff($from, $to=0){
	$zone	= wp_timezone();
	$to		= $to ? date_create($to, $zone) : current_datetime();
	$from	= date_create($from, $zone);
	$diff	= $to->diff($from);
	$days	= (int)$diff->format('%R%a');
	$day	= [0=>'今天', -1=>'昨天', -2=>'前天', 1=>'明天', 2=>'后天'][$days] ?? '';

	if($day){
		return $day;
	}

	if($from->format('W') - $to->format('W') == 0){
		return __($from->format('l'));
	}else{
		return $from->format('m月d日');
	}
}

// 打印
function wpjam_print_r($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';

	if(current_user_can($capability)){
		echo '<pre>';
		print_r($value);
		echo '</pre>'."\n";
	}
}

function wpjam_var_dump($value){
	$capability	= is_multisite() ? 'manage_site' : 'manage_options';
	if(current_user_can($capability)){
		echo '<pre>';
		var_dump($value);
		echo '</pre>'."\n";
	}
}

function wpjam_pagenavi($total=0, $echo=true){
	$result	= '<div class="pagenavi">'.paginate_links(array_filter([
		'prev_text'	=> '&laquo;',
		'next_text'	=> '&raquo;',
		'total'		=> $total
	])).'</div>';

	return $echo ? wpjam_echo($result) : $result;
}

function wpjam_localize_script($handle, $name, $l10n ){
	wp_localize_script($handle, $name, ['l10n_print_after' => $name.' = '.wpjam_json_encode($l10n)]);
}

function wpjam_is_mobile_number($number){
	return preg_match('/^0{0,1}(1[3,5,8][0-9]|14[5,7]|166|17[0,1,3,6,7,8]|19[8,9])[0-9]{8}$/', $number);
}

function wpjam_set_cookie($key, $value, $expire=DAY_IN_SECONDS){
	if(is_null($value)){
		unset($_COOKIE[$key]);

		$value	= ' ';
		$expire	= time() - YEAR_IN_SECONDS;
	}else{
		$_COOKIE[$key]	= $value;

		$expire	= $expire < time() ? $expire+time() : $expire;
	}

	setcookie($key, $value, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

	if(COOKIEPATH != SITECOOKIEPATH){
		setcookie($key, $value, $expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
	}
}

function wpjam_clear_cookie($key){
	wpjam_set_cookie($key, null);
}

function wpjam_get_filter_name($name='', $type=''){
	return wpjam_fix('add', 'prev', str_replace('-', '_', $name).'_'.$type, 'wpjam_');
}

function wpjam_get_filesystem(){
	if(empty($GLOBALS['wp_filesystem'])){
		if(!function_exists('WP_Filesystem')){
			require_once(ABSPATH.'wp-admin/includes/file.php');
		}

		WP_Filesystem();
	}

	return $GLOBALS['wp_filesystem'];
}
