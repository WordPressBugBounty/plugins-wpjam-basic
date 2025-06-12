<?php
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
		$jwt	= implode('.', array_map(fn($v)=> base64_urlencode(wpjam_json_encode($v)), [$header, $payload]));

		return $jwt.'.'.wpjam_generate_signature('hmac-sha256', $jwt);
	}

	return false;
}

function wpjam_verify_jwt($token){
	$token	= explode('.', $token);

	if(count($token) == 3 && hash_equals(wpjam_generate_signature('hmac-sha256', $token[0].'.'.$token[1]), $token[2])){
		[$header, $payload]	= array_map(fn($v)=> wpjam_json_decode(base64_urldecode($v)), array_slice($token, 0, 2));

		//iat 签发时间不能大于当前时间
		//nbf 时间之前不接收处理该Token
		//exp 过期时间不能小于当前时间
		if(wpjam_get($header, 'alg') == 'HS256' &&
			!array_any(['iat'=>'>', 'nbf'=>'>', 'exp'=>'<'], fn($v, $k)=> isset($payload[$k]) && wpjam_compare($payload[$k], $v, time()))
		){
			return $payload;
		}
	}

	return false;
}

function wpjam_get_jwt($key='access_token', $required=false){
	$header	= $_SERVER['HTTP_AUTHORIZATION'] ?? '';

	return str_starts_with($header, 'Bearer') ? trim(substr($header, 6)) : wpjam_get_parameter($key, ['required'=>$required]);
}

// Crypt
function wpjam_encrypt($text, $args, $de=false){
	$args	+= ['method'=>'', 'key'=>'', 'options'=>'', 'iv'=>''];
	$text	= $de ? openssl_decrypt($text, $args['method'], $args['key'], $args['options'], $args['iv']) : $text;
	$cb		= 'wpjam_'.($de ? 'un' : '').'pad';
	$types	= ['weixin', 'pkcs7'];
	$types	= $de ? array_reverse($types) : $types;

	foreach($types as $type){
		if($type == 'pkcs7'){
			if(wpjam_get($args, 'options') == OPENSSL_ZERO_PADDING && !empty($args['block_size'])){
				$text	= $cb($text, $type, $args['block_size']);
			}
		}elseif($type == 'weixin'){
			if(wpjam_get($args, 'pad') == 'weixin' && !empty($args['appid'])){
				$text	= $cb($text, $type, trim($args['appid']));
			}
		}
	}

	return $de ? $text : openssl_encrypt($text, $args['method'], $args['key'], $args['options'], $args['iv']);
}

function wpjam_decrypt($text, $args){
	return wpjam_encrypt($text, $args, true);
}

function wpjam_pad($text, $type, ...$args){
	if($type == 'pkcs7'){
		$pad	= $args[0] - (strlen($text) % $args[0]);
		$text	.= str_repeat(chr($pad), $pad);
	}elseif($type == 'weixin'){
		$text	= wp_generate_password(16, false).pack("N", strlen($text)).$text.$args[0];
	}

	return $text;
}

function wpjam_unpad($text, $type, ...$args){
	if($type == 'pkcs7'){
		$pad	= ord(substr($text, -1));
		$text	= ($pad > 0 && $pad < $args[0]) ? substr($text, 0, -1 * $pad) : $text;
	}elseif($type == 'weixin'){
		$text	= substr($text, 16);
		$length	= (unpack("N", substr($text, 0, 4)))[1];

		if($args && trim(substr($text, $length + 4)) != trim($args[0])){
			return new WP_Error('invalid_appid', 'Appid 校验「'.substr($text, $length + 4).'」「'.$args[0].'」错误');
		}

		$text	= substr($text, 4, $length);
	}

	return $text;
}

function wpjam_generate_signature($algo='sha1', ...$args){
	if($algo == 'sha1'){
		return sha1(implode(wpjam_sort($args, SORT_STRING)));
	}elseif($algo == 'hmac-sha256'){
		return base64_urlencode(hash_hmac('sha256', $args[0], wp_salt(), true));
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

	$rule	= array_find([
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
		$os		= $rule[1];
		$device	= $rule[2] ?? '';
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

	$rule	= array_find([
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
function wpjam_import($file, $columns=[]){
	if($file){
		$basedir	= wp_get_upload_dir()['basedir'];
		$file		= str_starts_with($file, $basedir) ? $file : $basedir.$file;
	}

	if(!$file || !file_exists($file)){
		return new WP_Error('file_not_exists', '文件不存在');
	}

	$ext	= wpjam_at(explode('.', $file), -1);

	if($ext == 'csv'){
		if(($handle = fopen($file, 'r')) !== false){
			while(($row = fgetcsv($handle)) !== false){
				$encoding	??= mb_detect_encoding(implode('', $row), mb_list_encodings(), true);

				if($encoding != 'UTF-8'){
					$row	= array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'GBK'), $row);
				}

				if(isset($map)){
					$data[]		= array_map(fn($i)=> preg_replace('/="([^"]*)"/', '$1', $row[$i]), $map);
				}else{
					$row		= array_map(fn($v)=> trim(trim($v), "\xEF\xBB\xBF"), $row);
					$columns	= array_flip(array_map('trim', $columns));
					$map		= wpjam_array($row, fn($k, $v)=> isset($columns[$v]) ? [$columns[$v], $k] : (in_array($v, $columns) ? [$v, $k] : null));
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
		return wpjam_match($value, $compare);
	}

	if(is_array($compare) || !$args){
		[$value2, $compare, $strict]	= [$compare, '', false];
	}else{
		$value2	= $args[0];
		$strict	= $args[1] ?? false;
	}

	$antonyms	= ['!='=>'=', '<='=>'>', '>='=>'<', 'NOT IN'=>'IN', 'NOT BETWEEN'=>'BETWEEN'];
	$compare	= $compare ? strtoupper($compare) : (is_array($value2) ? 'IN' : '=');

	if(isset($antonyms[$compare])){
		return !wpjam_compare($value, $antonyms[$compare], $value2, $strict);
	}

	if(!in_array($compare, $antonyms)){
		return false;
	}

	if(in_array($compare, ['IN', 'BETWEEN'])){
		$value2	= wp_parse_list($value2);

		if(!is_array($value) && count($value2) == 1){
			$value2		= $value2[0];
			$compare	= '=';
		}
	}else{
		$value2	= is_string($value2) ? trim($value2) : $value2;
	}

	return [
		'='			=> fn($a, $b)=> $strict ? $a === $b : $a == $b,
		'>'			=> fn($a, $b)=> $a > $b,
		'<'			=> fn($a, $b)=> $a < $b,
		'IN'		=> fn($a, $b)=> is_array($a) ? array_all($a , fn($v)=> in_array($v, $b, $strict)) : in_array($a, $b, $strict),
		'BETWEEN'	=> fn($a, $b)=> wpjam_between($a, ... $b)
	][$compare]($value, $value2);
}

function wpjam_between($value, $min, $max){
	return $value >= $min && $value <= $max;
}

function wpjam_match($item, ...$args){
	if(!$args || is_null($args[0])){
		return true;
	}

	if(is_string($args[0])){
		$op		= '';
		$args	= wpjam_parse_show_if($args);
	}else{
		$op		= isset($args[1]) ? strtoupper($args[1]) : ((wp_is_numeric_array($args[0]) || !isset($args[0]['key'])) ? 'AND' : '');
		$args	= $args[0];
	}

	if($op){
		if($op == 'NOT'){
			return !wpjam_match($item, $args, 'AND');
		}

		$cb	= ['OR'=>'array_any', 'AND'=>'array_all'][$op] ?? '';

		return $cb ? $cb($args, fn($v, $k)=> wpjam_match($item, ...(wpjam_is_assoc_array($v) ? [$v+['key'=>$k]] : [$k, $v]))) : false;
	}

	$value	= wpjam_get($item, wpjam_get($args, 'key'));
	$value2	= wpjam_get($args, 'value');

	if(!isset($args['compare'])){
		if(!empty($args['callable']) && is_callable($value)){
			if(!is_closure($value) && !is_array($value)){
				trigger_error(var_export($value, true));
			}

			return $value($value2, $item);
		}

		if(isset($args['if_null']) && is_null($value)){
			return $args['if_null'];
		}
	}

	if(is_array($value) || wpjam_get($args, 'swap')){
		[$value, $value2]	= [$value2, $value];
	}

	return wpjam_compare($value, wpjam_get($args, 'compare'), $value2, (bool)wpjam_get($args, 'strict'));
}

function wpjam_parse_show_if($if){
	if(wp_is_numeric_array($if) && count($if) >= 2){
		$keys	= count($if) == 2 ? ['key', 'value'] : ['key', 'compare', 'value'];

		if(count($if) > 3){
			if(is_array($if[3])){
				$args	= $if[3];

				trigger_error(var_export($args, true));	// del 2025-12-30
			}

			$if	= array_slice($if, 0, 3);
		}

		return array_combine($keys, $if)+($args ?? []);
	}elseif(is_array($if) && !empty($if['key'])){
		return $if;
	}
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
		}elseif($arr instanceof Traversable){
			$data	= iterator_to_array($arr);
		}elseif($arr instanceof JsonSerializable){
			$data	= $arr->jsonSerialize();
			$data	= is_array($data) ? $data : [];
		}else{
			$data	= [];
		}
	}else{
		$data	= (array)$arr;
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
	return wpjam_array($keys, fn($i, $k)=> [$k, $callback($k)]);
}

function wpjam_pick($arr, $keys){
	return is_object($arr) ? wpjam_array($keys, fn($i, $k)=> isset($arr->$k) ? [$k, $arr->$k] : null) : wp_array_slice_assoc($arr, $keys);
}

function wpjam_reduce($arr, $callback, $initial=null, $options=[], $depth=0){
	$carry	= $initial;

	if($options){
		$options	= is_array($options) ? $options+['key'=>true] : ['key'=> $options];
		$key		= $options['key'];
		$recursive	= empty($options['max_depth']) || $options['max_depth'] > $depth+1;
	}

	foreach($arr as $k => $v){
		$carry	= $callback($carry, $v, $k, $depth);

		if($options && $recursive && is_array($v)){
			$sub	= $key === true ? $v : (is_array(wpjam_get($v, $key)) ? $v[$key] : []);
			$carry	= wpjam_reduce($sub, $callback, $carry, $key, $depth+1);
		}
	}

	return $carry;
}

function wpjam_map($arr, $callback, $deep=false){
	return wpjam_array($arr, fn($k, $v)=>[$k, ($deep && is_array($v)) ? wpjam_map($v, $callback, true) : $callback($v, $k)]);
}

function wpjam_sum($items, $keys){
	return wpjam_fill($keys, fn($k)=> array_reduce($items, fn($sum, $item)=> $sum+(is_numeric($v = str_replace(',', '', ($item[$k] ?? 0))) ? $v : 0), 0));
}

function wpjam_at($arr, $index){
	$count	= count($arr);
	$index	= $index >= 0 ? $index : $count + $index;

	return ($index >= 0 && $index < $count) ? $arr[array_keys($arr)[$index]] : null;
}

function wpjam_add_at($arr, $index, $key, ...$args){
	if(!$args && !is_array($key)){
		$args	= [$key];
		$key	= null;
	}

	if(is_null($key)){
		array_splice($arr, $index, 0, $args);

		return $arr;
	}

	return array_replace(array_slice($arr, 0, $index, true), (is_array($key) ? $key : [$key=>$args[0] ?? '']))+array_slice($arr, $index, null, true);
}

function wpjam_find($arr, $callback, $output='value'){
	$cb	= wpjam_is_assoc_array($callback) ? fn($v)=> wpjam_match($v, $callback, 'AND') : $callback;

	if($output == 'value'){
		return array_find($arr, $cb);
	}elseif($output == 'key'){
		return array_find_key($arr, $cb);
	}elseif($output == 'index'){
		return array_search(array_find_key($arr, $cb), array_keys($arr));
	}elseif($output == 'result'){
		foreach($arr as $k => $v){
			if($result	= $cb($v, $k)){
				return $result;
			}
		}
	}
}

function wpjam_found($arr, $callback){
	return wpjam_find($arr, $callback, 'result');
}

function wpjam_group($arr, $field){
	foreach($arr as $k => $v){
		$g = wpjam_get($v, $field);

		$grouped[$g][$k] = $v;
	}

	return $grouped ?? [];
}

function wpjam_pull(&$arr, $key, ...$args){
	$value	= is_array($key) ? wp_array_slice_assoc($arr, $key) : wpjam_get($arr, $key, array_shift($args));
	$arr	= wpjam_except($arr, $key);

	return $value;
}

function wpjam_except($arr, $key){
	if(is_object($arr)){
		unset($arr->$key);

		return $arr;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true).':'.var_export($key, true));
		return $arr;
	}

	if(is_array($key)){
		return array_reduce($key, 'wpjam_except', $arr);
	}

	if(wpjam_exists($arr, $key)){
		unset($arr[$key]);
	}elseif($key	= wpjam_parse_keys($key)){
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

function wpjam_merge($arr, $data){
	foreach($data as $k => $v){
		$arr[$k]	= (wpjam_is_assoc_array($v) && isset($arr[$k]) && wpjam_is_assoc_array($arr[$k])) ? wpjam_merge($arr[$k], $v) : $v;
	}

	return $arr;
}

function wpjam_diff($arr, $data, $compare='value'){
	foreach($data as $k => $v){
		if(isset($arr[$k])){
			if(wpjam_is_assoc_array($v) && wpjam_is_assoc_array($arr[$k])){
				$arr[$k]	= wpjam_diff($arr[$k], $v, $compare);

				if(!$arr[$k]){
					unset($arr[$k]);
				}
			}else{
				if($compare == 'key' || $arr[$k] == $v){
					unset($arr[$k]);
				}
			}
		}
	}

	return $arr;
}

function wpjam_toggle($arr, $data){
	return array_merge(array_diff($arr, $data), array_diff($data, $arr));
}

function wpjam_slice($arr, $keys){
	return array_intersect_key($arr, array_flip(wp_parse_list($keys)));
}

function wpjam_filter($arr, ...$args){
	if(!$args || !$args[0]){
		return $arr;
	}

	if(wpjam_is_assoc_array($args[0])){
		return array_filter($arr, fn($v)=> wpjam_match($v, $args[0], $args[1] ?? 'AND')); 
	}

	if(wp_is_numeric_array($args[0]) && !is_callable($args[0])){
		return wpjam_slice($arr, $args[0]);
	}

	$cb		= $args[0] === 'isset' ? fn($v)=> !is_null($v) : $args[0];
	$deep	= $args[1] ?? ($args[0] == 'isset');
	$arr	= $deep ? array_map(fn($v)=> is_array($v) ? wpjam_filter($v, $cb, $deep) : $v, $arr) : $arr;

	return array_filter($arr, $cb, ARRAY_FILTER_USE_BOTH);
}

function wpjam_sort($arr, ...$args){
	if(count($arr) <= 1){
		return $arr;
	}

	if(!$args || is_int($args[0])){
		sort($arr, ...$args);

		return $arr;
	}

	if(in_array($args[0], ['', 'k', 'a', 'kr', 'ar', 'r'], true)){
		(array_shift($args).'sort')($arr, ...$args);

		return $arr;
	}

	$is_asc	= fn($v)=> is_int($v) ? $v === SORT_ASC : strtolower($v) === 'asc';

	if(wpjam_is_assoc_array($args[0])){
		$args	= wpjam_reduce($args[0], fn($carry, $v, $k)=>[...$carry, ($column = array_column($arr, $k)), $is_asc($v) ? SORT_ASC : SORT_DESC, is_numeric(current($column)) ? SORT_NUMERIC : SORT_REGULAR], []);
	}elseif(is_callable($args[0]) || is_string($args[0])){
		$order	= $args[1] ?? '';

		if(is_callable($args[0])){
			$column	= array_map($args[0], ($order === 'key' ? array_keys($arr) : $arr));
			$flag	= $args[2] ?? SORT_NUMERIC;
		}else{
			$k	= $args[0];
			$d	= $args[2] ?? 0;

			$column	= array_map(fn($v)=> wpjam_get($v, $k, $d), $arr);
			$flag	= is_numeric($d) ? SORT_NUMERIC : SORT_REGULAR;
		}

		$args	= [$column, ($is_asc($order) ? SORT_ASC : SORT_DESC), $flag];
	}

	array_push($args, range(1, count($arr)), SORT_ASC, SORT_NUMERIC);

	if(wp_is_numeric_array($arr)){
		$keys	= array_keys($arr);
		$args[]	= &$keys;
	}

	$args[] = &$arr;

	array_multisort(...$args);

	return isset($keys) ? array_combine($keys, $arr) : $arr;
}

function wpjam_exists($arr, $key){
	return is_array($arr) ? array_key_exists($key, $arr) : (is_object($arr) ? isset($arr->$key) : false);
}

function wpjam_parse_keys($key, $type=''){
	if($type == '[]'){
		$keys	= [];

		if(str_contains($key, '[') && !str_starts_with($key, '[') && str_ends_with($key, ']')){
			$parts	= preg_split('/(['.preg_quote('[]', '/').'])/', $key, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

			if(count($parts) % 3 != 1) {
				return [];
			}

			$keys[]	= array_shift($parts);

			for($i = 0; $i < count($parts); $i += 3){
				if(in_array($parts[$i+1], ['[', ']'], true) || $parts[$i] !== '[' || $parts[$i+2] !== ']'){
					return [];
				}

				$keys[] = $parts[$i+1];
			}
		}

		return $keys;
	}elseif($type == '.'){
		return str_contains($key, '.') ? explode('.', $key) : [];
	}else{
		foreach(['[]', '.'] as $type){
			if($keys = wpjam_parse_keys($key, $type)){
				return $keys;
			}
		}

		return [];
	}
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

		if(str_ends_with($key, '[]')){
			$value	= wpjam_get($arr, substr($key, 0, -2), $default);

			return is_object($value) ? [$value] : (array)$value;
		}

		$key	= wpjam_parse_keys($key);

		if(!$key){
			return $default;
		}
	}

	return _wp_array_get($arr, $key, $default);
}

//$arr, $key, $value
//$key, $value
function wpjam_set(...$args){
	if(count($args) < 2){
		return;
	}

	$arr	= count($args) >= 3 ? array_shift($args) : [];
	$key	= $args[0];
	$value	= $args[1];

	if(is_object($arr)){
		$arr->$key = $value;

		return $arr;
	}

	if(!is_array($arr)){
		return $arr;
	}

	if(is_null($key)){
		$arr[]	= $value;

		return $arr;
	}

	if(!is_array($key)){
		if(wpjam_exists($arr, $key)){
			$arr[$key] = $value;

			return $arr;
		}

		if(str_ends_with($key, '[]')){
			$current	= wpjam_get($arr, $key);
			$current[]	= $value;

			return wpjam_set($arr, substr($key, 0, -2), $current);
		}

		$key	= wpjam_parse_keys($key) ?: $key;

		if(!is_array($key)){
			$arr[$key] = $value;

			return $arr;
		}
	}

	_wp_array_set($arr, $key, $value);

	return $arr;
}

function wpjam_some($arr, $callback){
	foreach($arr as $k => $v){
		if($callback($v, $k)){
			return true;
		}
	}

	return false;
}

function wpjam_every($arr, $callback){
	foreach($arr as $k => $v){
		if(!$callback($v, $k)){
			return false;
		}
	}

	return true;
}

if(!function_exists('array_pull')){
	function array_pull(&$arr, $key, ...$args){
		return wpjam_pull($arr, $key, ...$args);
	}
}

if(!function_exists('array_except')){
	function array_except($array, ...$keys){
		return wpjam_except($array, (($keys && is_array($keys[0])) ? $keys[0] : $keys));
	}
}

if(!function_exists('array_find')){
	function array_find($arr, $callback){
		foreach($arr as $k => $v){
			if($callback($v, $k)){
				return $v;
			}
		}
	}
}

if(!function_exists('array_find_key')){
	function array_find_key($arr, $callback){
		foreach($arr as $k => $v){
			if($callback($v, $k)){
				return $k;
			}
		}
	}
}

function_alias('wpjam_is_array_accessible',	'array_accessible');
function_alias('wpjam_every',	'array_all');
function_alias('wpjam_some',	'array_any');
function_alias('wpjam_array',	'array_wrap');
function_alias('wpjam_get',		'array_get');
function_alias('wpjam_set',		'array_set');
function_alias('wpjam_toggle',	'array_toggle');
function_alias('wpjam_group',	'array_group');
function_alias('wpjam_at',		'array_at');
function_alias('wpjam_add_at',	'array_add_at');
function_alias('wpjam_merge',	'merge_deep');

function wpjam_move($arr, $id, $data){
	$arr	= array_values($arr);
	$index	= array_search($id, $arr);
	$arr	= array_values(array_diff($arr, [$id]));

	if($index === false){
		return new WP_Error('invalid_id', '无效的 ID');
	}

	if(isset($data['pos'])){
		$index	= $data['pos'];
	}elseif(!empty($data['up'])){
		if($index == 0){
			return new WP_Error('invalid_position', '已经是第一个了，不可上移了！');
		}

		$index--;
	}elseif(!empty($data['down'])){
		if($index == count($arr)){
			return new WP_Error('invalid_position', '已经最后一个了，不可下移了！');
		}

		$index++;
	}else{
		$k		= array_find(['next', 'prev'], fn($k)=> isset($data[$k]));
		$index	= ($k && isset($data[$k])) ? array_search($data[$k], $arr) : false;

		if($index === false){
			return new WP_Error('invalid_position', '无效的移动位置');
		}

		$index	+= $k == 'prev' ? 1 : 0;
	}

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
if(!function_exists('try_remove_prefix')){
	function try_remove_prefix(&$str, $prefix){
		$res	= str_starts_with($str, $prefix);
		$str	= $res ? substr($str, strlen($prefix)) : $str;

		return $res;
	}
}

if(!function_exists('try_remove_suffix')){
	function try_remove_suffix(&$str, $suffix){
		$res	= str_ends_with($str, $suffix);
		$str	= $res ? substr($str, 0, -strlen($suffix)) : $str;

		return $res;
	}
}

function wpjam_remove_prefix($str, $prefix, &$removed=false){
	$removed	= try_remove_prefix($str, $prefix);

	return $str;
}

function wpjam_remove_suffix($str, $suffix, &$removed=false){
	$removed	= try_remove_suffix($str, $suffix);

	return $str;
}

function wpjam_echo($str){
	echo $str;
}

function wpjam_join($sep, ...$args){
	$arr	= ($args && is_array($args[0])) ? $args[0] : $args;

	return join($sep, array_filter($arr));
}

function wpjam_remove_pre_tab($str, $times=1){
	return preg_replace('/^\t{'.$times.'}/m', '', $str);
}

function wpjam_preg_replace($pattern, $replace, $subject, $limit=-1, &$count=null, $flags=0){
	if(is_closure($replace)){
		$result	= preg_replace_callback($pattern, $replace, $subject, $limit, $count, $flags);
	}else{
		$result	= preg_replace($pattern, $replace, $subject, $limit, $count);
	}

	if(is_null($result)){
		trigger_error(preg_last_error_msg());
		return $subject;
	}

	return $result;
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
	return wpjam_preg_replace('/(\\\\u[0-9a-fA-F]{4})+/i', fn($m)=> json_decode('"'.$m[0].'"') ?: $m[0], $text);
}

function wpjam_zh_urlencode($url){
	return $url ? wpjam_preg_replace('/[\x{4e00}-\x{9fa5}]+/u', fn($m)=> urlencode($m[0]), $url) : '';
}

function wpjam_format($value, $format, $precision=null){
	if(is_numeric($value)){
		if($format == '%'){
			return round($value * 100, $precision ?: 2).'%';
		}elseif($format == ','){
			return number_format(trim($value), (int)($precision ?? 2));
		}elseif(is_numeric($precision)){
			return round($value, $precision);
		}
	}

	return $value;
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	$pre	= $text ? apply_filters('wpjam_pre_blacklist_check', null, $text, $name) : false;
	$pre	= $pre ?? array_any((array)explode("\n", get_option('disallowed_keys')), fn($w)=> (trim($w) && preg_match("#".preg_quote(trim($w), '#')."#i", $text)));

	return $pre;
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
function wpjam_do_shortcode($content, $tags, $ignore_html=false){
	if($tags){
		if(wpjam_is_assoc_array($tags)){
			array_walk($tags, fn($callback, $tag)=> add_shortcode($tag, $callback));

			$tags	= array_keys($tags);
		}

		if(array_any($tags, fn($tag)=> str_contains($content, '['.$tag))){
			$content	= do_shortcodes_in_html_tags($content, $ignore_html, $tags);
			$content	= preg_replace_callback('/'.get_shortcode_regex($tags).'/', 'do_shortcode_tag', $content);
			$content	= unescape_invalid_shortcodes($content);
		}
	}

	return $content;
}

function wpjam_parse_shortcode_attr($str, $tag){
	return preg_match('/'.get_shortcode_regex((array)$tag).'/', $str, $m) ? shortcode_parse_atts($m[3]) : [];
}

function wpjam_get_current_page_url(){
	return set_url_scheme('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
}

// Date
function wpjam_date($format, $ts=null){
	$ts	??= time();
	$dt	= $ts ? date_create('@'.$ts) : null;

	return $dt ? $dt->setTimezone(wp_timezone())->format($format) : '';
}

function wpjam_strtotime($str){
	$dt	= $str ? date_create($str, wp_timezone()) : null;

	return $dt ? $dt->getTimestamp() : 0;
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
		return wpjam_found([
			'#https://v.qq.com/x/page/(.*?).html#i',
			'#https://v.qq.com/x/cover/.*/(.*?).html#i'
		], fn($v)=> preg_match($v, $id_or_url, $matches) ? $matches[1] : '') ?: '';
	}

	return $id_or_url;
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

function wpjam_get_filter_name($name, $type){
	$name	= str_replace('-', '_', $name).'_'.$type;

	return str_starts_with($name, 'wpjam_') ? $name : 'wpjam_'.$name;
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
