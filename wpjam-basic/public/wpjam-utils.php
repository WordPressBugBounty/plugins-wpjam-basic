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
function wpjam_generate_jwt($payload, $header=[]){
	$header	+= ['alg'=>'HS256', 'typ'=>'JWT'];

	if(is_array($payload) && $header['alg'] == 'HS256'){
		$jwt	= implode('.', wpjam_map([$header, $payload], fn($v)=> base64_urlencode(wpjam_json_encode($v))));

		return $jwt.'.'.base64_urlencode(hash_hmac('sha256', $jwt, wp_salt(), true));
	}

	return false;
}

function wpjam_verify_jwt($token){
	$token	= explode('.', $token);

	if(count($token) == 3 && hash_equals(base64_urlencode(hash_hmac('sha256', $token[0].'.'.$token[1], wp_salt(), true)), $token[2])){
		[$header, $payload]	= wpjam_map(array_slice($token, 0, 2), fn($v)=> wpjam_json_decode(base64_urldecode($v)));

		//iat 签发时间不能大于当前时间
		//nbf 时间之前不接收处理该Token
		//exp 过期时间不能小于当前时间
		if(wpjam_get($header, 'alg') == 'HS256' && 
			!wpjam_some(['iat'=>'>', 'nbf'=>'>', 'exp'=>'<'], fn($v, $k)=> isset($payload[$k]) && wpjam_compare($payload[$k], $v, time()))
		){
			return $payload;
		}
	}

	return false;
}

function wpjam_get_jwt($key='access_token', $required=false){
	$header	= $_SERVER['HTTP_AUTHORIZATION'] ?? '';

	return ($header && str_starts_with($header, 'Bearer')) ? trim(wpjam_remove_prefix($header, 'Bearer')) : wpjam_get_parameter($key, ['required'=>$required]);
}

// Crypt
function wpjam_encrypt($text, $args){
	$args	+= [
		'method'	=> 'aes-256-cbc',
		'options'	=> OPENSSL_ZERO_PADDING,
		'key'		=> '',
		'iv'		=> '',
		'pad'		=> '',
	];

	if($args['pad'] == 'weixin' && !empty($args['appid'])){
		$text 	= wpjam_pad($text, 'weixin', $args['appid']);
	}

	if($args['options'] == OPENSSL_ZERO_PADDING && !empty($args['block_size'])){
		$text	= wpjam_pad($text, 'pkcs7', $args['block_size']);
	}

	return openssl_encrypt($text, $args['method'], $args['key'], $args['options'], $args['iv']);
}

function wpjam_decrypt($text, $args){
	$args	+= [
		'method'	=> 'aes-256-cbc',
		'options'	=> OPENSSL_ZERO_PADDING,
		'key'		=> '',
		'iv'		=> '',
		'pad'		=> '',
	];

	$text	= openssl_decrypt($text, $args['method'], $args['key'], $args['options'], $args['iv']);

	if($args['options'] == OPENSSL_ZERO_PADDING && !empty($args['block_size'])){
		$text	= wpjam_unpad($text, 'pkcs7', $args['block_size']);
	}

	if($args['pad'] == 'weixin' && !empty($args['appid'])){
		$text 	= wpjam_unpad($text, 'weixin', trim($args['appid']));
	}

	return $text;
}

function wpjam_pad($text, $type, ...$args){
	if($type == 'pkcs7'){
		$pad	= $args[0] - (strlen($text) % $args[0]);

		return $text.str_repeat(chr($pad), $pad);
	}elseif($type == 'weixin'){
		return wp_generate_password(16, false).pack("N", strlen($text)).$text.$args[0];
	}

	return $text;
}

function wpjam_unpad($text, $type, ...$args){
	if($type == 'pkcs7'){
		$pad	= ord(substr($text, -1));

		return ($pad > 0 && $pad < $args[0]) ? substr($text, 0, -1 * $pad) : $text;
	}elseif($type == 'weixin'){
		$text	= substr($text, 16);
		$length	= (unpack("N", substr($text, 0, 4)))[1];

		if($args && trim(substr($text, $length + 4)) != trim($args[0])){
			return new WP_Error('invalid_appid', 'Appid 校验「'.substr($text, $length + 4).'」「'.$args[0].'」错误');
		}

		return substr($text, 4, $length);
	}

	return $text;
}

function wpjam_generate_signature($algo='sha1', ...$args){
	if($algo == 'sha1'){
		return sha1(implode(wpjam_sort($args, SORT_STRING)));
	}
}

// User agent
function wpjam_get_user_agent(){
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function wpjam_get_ip(){
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function wpjam_parse_user_agent($user_agent=null, $referer=null){
	$user_agent	??= $_SERVER['HTTP_USER_AGENT'] ?? '';
	$referer	??= $_SERVER['HTTP_REFERER'] ?? '';

	$os			= 'unknown';
	$device		= $browser = $app = '';
	$os_version	= $browser_version = $app_version = 0;

	$rule	= wpjam_find([
		['iPhone',			'iOS',	'iPhone'],
		['iPad',			'iOS',	'iPad'],
		['iPod',			'iOS',	'iPod'],
		['Android',			'Android'],
		['Windows NT',		'Windows'],
		['Macintosh',		'Macintosh'],
		['Windows Phone',	'Windows Phone'],
		['BlackBerry',		'BlackBerry'],
		['BB10',			'BlackBerry'],
		['Symbian',			'Symbian'],
	], fn($rule)=> stripos($user_agent, $rule[0]));

	if($rule){
		$os	= $rule[1];

		if(isset($rule[2])){
			$device	= $rule[2];
		}
	}

	if($os == 'iOS'){
		if(preg_match('/OS (.*?) like Mac OS X[\)]{1}/i', $user_agent, $matches)){
			$os_version	= (float)(trim(str_replace('_', '.', $matches[1])));
		}
	}elseif($os == 'Android'){
		if(preg_match('/Android ([0-9\.]{1,}?); (.*?) Build\/(.*?)[\)\s;]{1}/i', $user_agent, $matches)){
			if(!empty($matches[1]) && !empty($matches[2])){
				$os_version	= trim($matches[1]);
				$device		= trim($matches[2]);
				$device		= str_contains($device, ';') ? explode(';', $device)[1] : $device;
			}
		}
	}

	$rule	= wpjam_find([
		['lynx',	'lynx'],
		['safari',	'safari',	'/version\/([\d\.]+).*safari/i'],
		['edge',	'edge',		'/edge\/([\d\.]+)/i'],
		['chrome',	'chrome',	'/chrome\/([\d\.]+)/i'],
		['firefox',	'firefox',	'/firefox\/([\d\.]+)/i'],
		['opera',	'opera',	'/(?:opera).([\d\.]+)/i'],
		['opr/', 	'opera',	'/(?:opr).([\d\.]+)/i'],
		['msie',	'ie'],
		['trident',	'ie'],
		['gecko',	'gecko'],
		['nav',		'nav']
	], fn($rule)=> stripos($user_agent, $rule[0]));

	if($rule){
		$browser	= $rule[1];

		if(!empty($rule[2]) && preg_match($rule[2], $user_agent, $matches)){
			$browser_version	= (float)(trim($matches[1]));
		}
	}

	if(strpos($user_agent, 'MicroMessenger') !== false){
		$app	= str_contains($referer, 'https://servicewechat.com') ? 'weapp' : 'weixin';

		if(preg_match('/MicroMessenger\/(.*?)\s/', $user_agent, $matches)){
			$app_version = (float)$matches[1];
		}
	}

	return compact('os', 'device', 'app', 'browser', 'os_version', 'browser_version', 'app_version');
}

function wpjam_parse_ip($ip=''){
	$ip	= $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '');

	if($ip == 'unknown' || !$ip){
		return false;
	}

	$default	= [
		'ip'		=> $ip,
		'country'	=> '',
		'region'	=> '',
		'city'		=> '',
	];

	if(file_exists(WP_CONTENT_DIR.'/uploads/17monipdb.dat')){
		$object	= wpjam_get_instance('ip', 'ip', function(){
			$fp		= fopen(WP_CONTENT_DIR.'/uploads/17monipdb.dat', 'rb');
			$offset	= unpack('Nlen', fread($fp, 4));
			$index	= fread($fp, $offset['len'] - 4);

			register_shutdown_function(fn()=> fclose($fp));

			return new WPJAM_Args(['fp'=>$fp, 'offset'=>$offset, 'index'=>$index]);
		});

		$nip	= gethostbyname($ip);
		$ipdot	= explode('.', $nip);

		if($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4){
			return $default;
		}

		static $cached	= [];

		if(isset($cached[$nip])){
			return $cached[$nip];
		}

		$fp		= $object->fp;
		$offset	= $object->offset;
		$index	= $object->index;
		$nip2 	= pack('N', ip2long($nip));
		$start	= (int)$ipdot[0]*4;
		$start	= unpack('Vlen', $index[$start].$index[$start+1].$index[$start+2].$index[$start+3]);

		$index_offset	= $index_length = null;
		$max_comp_len	= $offset['len']-1024-4;

		for($start = $start['len']*8+1024; $start < $max_comp_len; $start+=8){
			if($index[$start].$index[$start+1].$index[$start+2].$index[$start+3] >= $nip2){
				$index_offset = unpack('Vlen', $index[$start+4].$index[$start+5].$index[$start+6]."\x0");
				$index_length = unpack('Clen', $index[$start+7]);

				break;
			}
		}

		if($index_offset === null){
			return $default;
		}

		fseek($fp, $offset['len']+$index_offset['len']-1024);

		$data	= explode("\t", fread($fp, $index_length['len']));

		return $cached[$nip] = [
			'ip'		=> $ip,
			'country'	=> $data['0'] ?? '',
			'region'	=> $data['1'] ?? '',
			'city'		=> $data['2'] ?? '',
		];
	}

	return $default;
}

// File
function wpjam_scandir($dir, $callback=null){
	$files	= [];

	foreach(scandir($dir) as $file){
		if($file == '.' || $file == '..'){
			continue;
		}

		$file 	= $dir.'/'.$file;
		$files	= array_merge($files, (is_dir($file) ? wpjam_scandir($file) : [$file]));
	}

	if($callback && is_callable($callback)){
		$output	= [];

		foreach($files as $file){
			$callback($file, $output);
		}

		return $output;
	}

	return $files;
}

function wpjam_import($file, $columns=[]){
	$file	= $file ? wpjam_fix('add', 'prefix', $file, wp_get_upload_dir()['basedir']) : '';

	if(!$file || !file_exists($file)){
		return new WP_Error('file_not_exists', '文件不存在');
	}

	$ext	= wpjam_at(explode('.', $file), -1);

	if($ext == 'csv'){
		if(($handle = fopen($file, 'r')) !== false){
			$i	= 0;

			while(($row = fgetcsv($handle)) !== false){
				$i ++;

				if($i == 1){
					$encoding	= mb_detect_encoding(implode('', $row), mb_list_encodings(), true);
				}

				if($encoding != 'UTF-8'){
					$row	= array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'GBK'), $row);
				}

				if($i == 1){
					[$row, $columns]	= array_map(fn($v)=> array_flip(array_map(fn($v)=> trim(trim($v), "\xEF\xBB\xBF"), $v)), [$row, $columns]);

					$indexes	= array_intersect_key($row, $columns);;
					$indexes	= array_combine(wp_array_slice_assoc($columns, array_keys($indexes)), $indexes);
				}else{
					$data[]	= wpjam_map($indexes, fn($index)=> $row[$index]);
				}
			}

			fclose($handle);
		}
	}else{
		$data	= file_get_contents($file);
		$data	= ($ext == 'txt' && is_serialized($data)) ? maybe_unserialize($data) : $data;
	}

	unlink($file);

	return $data ?? [];
}

function wpjam_export($file, $data, $columns=[]){
	header('Content-Disposition: attachment;filename='.$file);
	header('Pragma: no-cache');
	header('Expires: 0');

	$handle	= fopen('php://output', 'w');
	$ext	= wpjam_at(explode('.', $file), -1);

	if($ext == 'csv'){
		header('Content-Type: text/csv');

		fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));

		if($columns){
			fputcsv($handle, $columns);
			array_walk($data, fn($item)=> fputcsv($handle, wpjam_map($columns, fn($v, $k)=> $item[$k] ?? '')));
		}else{
			array_walk($data, fn($item)=> fputcsv($handle, $item));
		}
	}elseif($ext == 'txt'){
		header('Content-Type: text/plain');

		fputs($handle, is_scalar($data) ? $data : maybe_serialize($data));
	}

	fclose($handle);

	exit;
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

	if(!empty($args['callable']) && is_callable($value)){
		return $value($value2, $item);
	}

	if(isset($args['if_null']) && is_null($compare) && is_null($value)){
		return $args['if_null'];
	}

	if(is_array($value) || wpjam_get($args, 'swap')){
		[$value, $value2]	= [$value2, $value];
	}

	return wpjam_compare($value, $compare, $value2, (bool)wpjam_get($args, 'strict'));
}

function wpjam_parse_show_if($if){
	if(wp_is_numeric_array($if) && count($if) >= 2){
		$keys			= count($if) == 2 ? ['key', 'value'] : ['key', 'compare', 'value'];
		[$if, $args]	= count($if) > 3 ? [array_slice($if, 0, 3), $if[3]] : [$if, []];

		return array_combine($keys, $if)+(is_array($args) ? $args : []);
	}

	return $if;
}

function wpjam_match($item, $args=[], $operator='AND'){
	$op	= strtoupper($operator);

	if($op == 'NOT'){
		return !wpjam_match($item, $args, 'AND');
	}

	$cb	= ['OR'=>'wpjam_some', 'AND'=>'wpjam_every'][$op] ?? '';

	return $cb ? $cb($args, fn($v, $k)=> wpjam_if($item, wpjam_is_assoc_array($v) ? $v+['key'=>$k] : ['key'=>$k, 'value'=>$v])) : false;
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
			$result	= $callback($k, $v);

			if(!is_null($result)){
				[$k, $v]	= is_array($result) ? $result : [$result, $v];

				if(is_null($k)){
					$new[]		= $v;
				}else{
					$new[$k]	= $v;
				}
			}
		}

		return $new ?? [];
	}

	return $data;
}

function wpjam_fill($keys, $callback){
	return wpjam_array($keys, fn($i, $k)=>[$k, $callback($k)]);
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

function wpjam_sum($items, $keys){
	return wpjam_fill($keys, fn($k)=> array_reduce($items, fn($sum, $item)=> $sum+(is_numeric($v = str_replace(',', '', ($item[$k] ?? 0))) ? $v : 0), 0));
}

function wpjam_at($arr, $index){
	$count	= count($arr);
	$index	= $index >= 0 ? $index : $count + $index;

	return ($index >= 0 && $index < $count) ? array_values($arr)[$index] : null;
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

function wpjam_find($arr, $callback, $return='value'){
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
			$value	= wp_array_slice_assoc($arr, $key);
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
	if(!$deep){
		return array_merge($arr, $data);
	}

	foreach($data as $k => $v){
		$arr[$k]	= (wpjam_is_assoc_array($v) && isset($arr[$k]) && wpjam_is_assoc_array($arr[$k])) ? wpjam_merge($arr[$k], $v) : $v;
	}

	return $arr;
}

function wpjam_diff($arr, $data, $deep=true){
	if(!$deep){
		return array_diff($arr, $data);
	}

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
			$v	= is_array($v) ? wpjam_filter($v, $callback, $deep) : $v;
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

// Bit
function wpjam_has_bit($value, $bit){
	return ((int)$value & (int)$bit) == $bit;
}

function wpjam_add_bit($value, $bit){
	return $value = (int)$value | (int)$bit;
}

function wpjam_remove_bit($value, $bit){
	return $value = (int)$value & (~(int)$bit);
}

// UUID
function wpjam_create_uuid(){
	$chars	= md5(uniqid(mt_rand(), true));

	return implode('-', array_map(fn($v)=> substr($chars, ...$v), [[0, 8], [8, 4], [12, 4], [16, 4], [20, 12]]));
}

// Str
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
function wpjam_strip_invalid_text($text){
	return $text ? iconv('UTF-8', 'UTF-8//IGNORE', $text) : '';
}

// 去掉 4字节 字符
function wpjam_strip_4_byte_chars($text){
	return $text ? preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text) : '';
	// return preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $text);	// \xEF\xBF\xBD 常用来表示未知、未识别或不可表示的字符
}

// 移除 除了 line feeds 和 carriage returns 所有控制字符
function wpjam_strip_control_chars($text){
	return $text ? preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', $text) : '';
	// return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $text);
}

//获取纯文本
function wpjam_get_plain_text($text){
	return $text ? trim(preg_replace('/\s+/', ' ', str_replace(['"', '\'', "\r\n", "\n"], ['', '', ' ', ' '], wp_strip_all_tags($text)))) : $text;
}

//获取第一段
function wpjam_get_first_p($text){
	return $text ? trim((explode("\n", trim(wp_strip_all_tags($text))))[0]) : '';
}

function wpjam_unicode_decode($text){
	return preg_replace_callback('/(\\\\u[0-9a-fA-F]{4})+/i', fn($m)=> json_decode('"'.$m[0].'"') ?: $m[0], $text);
}

function wpjam_zh_urlencode($url){
	return $url ? preg_replace_callback('/[\x{4e00}-\x{9fa5}]+/u', fn($m)=> urlencode($m[0]), $url) : '';
}

function wpjam_format($value, $format){
	if(is_numeric($value)){
		if($format == '%'){
			return round($value * 100, 2).'%';
		}elseif($format == ','){
			return number_format(trim($value), 2);
		}
	}

	return $value;
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	$pre	= $text ? apply_filters('wpjam_pre_blacklist_check', null, $text, $name) : false;

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

function wpjam_expandable($str, $num=10, $name=null){
	if(count(explode("\n", $str)) > $num){
		static $index = 0;

		$name	= 'expandable_'.($name ?? (++$index));

		return '<div class="expandable-container"><input type="checkbox" id="'.esc_attr($name).'" /><label for="'.esc_attr($name).'" class="button"></label><div class="inner">'.$str.'</div></div>';
	}else{
		return $str;
	}
}

// Shortcode
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

// Date
function wpjam_date($format, $timestamp=null){
	return date_create('@'.($timestamp ?? time()))->setTimezone(wp_timezone())->format($format);
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
	$day	= [0=>'今天', -1=>'昨天', -2=>'前天', 1=>'明天', 2=>'后天'][(int)$to->diff($from)->format('%R%a')] ?? '';

	return $day ?: ($from->format('W') == $to->format('W') ? __($from->format('l')) : $from->format('m月d日'));
}

// Video
function wpjam_get_video_mp4($id_or_url){
	if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
		if(preg_match('#http://www.miaopai.com/show/(.*?).htm#i',$id_or_url, $matches)){
			return 'http://gslb.miaopai.com/stream/'.esc_attr($matches[1]).'.mp4';
		}

		$vid	= wpjam_get_qqv_id($id_or_url);

		return $vid ? wpjam_get_qqv_mp4($vid) : wpjam_zh_urlencode($id_or_url);
	}

	return wpjam_get_qqv_mp4($id_or_url);
}

function wpjam_get_qqv_mp4($vid, $cache=true){
	if(strlen($vid) > 20){
		wpjam_throw('error', '无效的腾讯视频');
	}

	if($cache){
		return wpjam_transient('qqv_mp4:'.$vid, fn()=> wpjam_get_qqv_mp4($vid, false), HOUR_IN_SECONDS*6);
	}

	$response	= wpjam_remote_request('http://vv.video.qq.com/getinfo?otype=json&platform=11001&vid='.$vid, ['timeout'=>4, 'throw'=>true]);
	$response	= trim(substr($response, strpos($response, '{')),';');
	$response	= wpjam_try('wpjam_json_decode', $response);

	if(empty($response['vl'])){
		wpjam_throw('error', '腾讯视频不存在或者为收费视频！');
	}

	$u	= $response['vl']['vi'][0];

	return $u['ul']['ui'][0]['url'].$u['fn'].'?vkey='.$u['fvkey'];
}

function wpjam_get_qqv_id($id_or_url){
	if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
		return wpjam_find([
			'#https://v.qq.com/x/page/(.*?).html#i',
			'#https://v.qq.com/x/cover/.*/(.*?).html#i'
		], fn($v)=> preg_match($v, $id_or_url, $matches) ? $matches[1] : '', 'result') ?: '';
	}

	return $id_or_url;
}

function wpjam_video($content, $attr){
	$src	= wpjam_find([
		[
			'//www.bilibili.com/video/(BV[a-zA-Z0-9]+)',
			fn($m)=> 'https://player.bilibili.com/player.html?bvid='.esc_attr($m[1])
		],
		[
			'//v.qq.com/(.*)iframe/(player|preview).html\?vid=(.+)',
			fn($m)=> 'https://v.qq.com/'.esc_attr($m[1]).'iframe/player.html?vid='.esc_attr($m[3])
		],
		[
			'//v.youku.com/v_show/id_(.*?).html',
			fn($m)=> 'https://player.youku.com/embed/'.esc_attr($m[1])
		],
		[
			'//www.tudou.com/programs/view/(.*?)',
			fn($m)=> 'https://www.tudou.com/programs/view/html5embed.action?code='.esc_attr($m[1])
		],
		[
			'//tv.sohu.com/upload/static/share/share_play.html\#(.+)',
			fn($m)=> 'https://tv.sohu.com/upload/static/share/share_play.html#'.esc_attr($m[1])
		],
		[
			'//www.youtube.com/watch\?v=([a-zA-Z0-9\_]+)',
			fn($m)=> 'https://www.youtube.com/embed/'.esc_attr($m[1])
		],
	], fn($v)=> preg_match('#'.$v[0].'#i', $content, $matches) ? $v[1]($matches) : '', 'result');

	if($src){
		$attr	= shortcode_atts(['width'=>0, 'height'=>0], $attr);
		$attr	= ($attr['width'] || $attr['height']) ? image_hwstring($attr['width'], $attr['height']).' style="aspect-ratio:4/3;"' : 'style="width:100%; aspect-ratio:4/3;"';

		return '<iframe class="wpjam_video" '.$attr.' src="'.$src.'" scrolling="no" border="0" frameborder="no" framespacing="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
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
	wpjam_set_cookie($key, null, time()-YEAR_IN_SECONDS);
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
