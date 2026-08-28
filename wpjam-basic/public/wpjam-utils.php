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
	if(is_array($payload)){
		$jwt	= implode('.', array_map(fn($v)=> base64_urlencode(wpjam_json_encode($v)), [$header+['typ'=>'JWT'], $payload]));

		return $jwt.'.'.wpjam_generate_signature('hmac-sha256', $jwt);	// 'alg'=>'HS256'
	}
}

function wpjam_verify_jwt($token){
	[$header, $payload, $sign]	= explode('.', $token)+['', '', ''];

	//iat 签发时间不能大于当前时间
	//nbf 时间之前不接收处理该Token
	//exp 过期时间不能小于当前时间
	return hash_equals(wpjam_generate_signature('hmac-sha256', $header.'.'.$payload), $sign)
	&& ($data = wpjam_json_decode(base64_urldecode($payload)))
	&& !array_any(['iat'=>'>', 'nbf'=>'>', 'exp'=>'<'], fn($v, $k)=> isset($data[$k]) && wpjam_compare($data[$k], $v, time()))
	? $data
	: false;
}

function wpjam_get_jwt($key='access_token', $required=false){
	return ($header = $_SERVER['HTTP_AUTHORIZATION'] ?? '') && try_prefix($header, '-', 'Bearer')
	? trim($header)
	: wpjam_param($key, ['required'=>$required]);
}

// Crypt
function wpjam_encrypt($text, $args){
	$de		= $args['de'] ?? false;
	$params	= [$args['method'] ?? '', $args['key'] ?? '', $args['options'] ?? '', $args['iv'] ?? ''];
	$text	= $de ? openssl_decrypt($text, ...$params) : $text;

	foreach($de ? ['pkcs7', 'weixin'] : ['weixin', 'pkcs7'] as $pad){
		if($arg = $pad == 'pkcs7'
			? (($args['options'] ?? '') == OPENSSL_ZERO_PADDING ? ($args['block_size'] ?? '') : '')
			: (($args['pad'] ?? '') == 'weixin' ? trim($args['appid'] ?? '') : '')
		){
			$text	= wpjam_pad($text, ($de ? '-' : '').$pad, $arg);
		}
	}

	return $de ? $text : openssl_encrypt($text, ...$params);
}

function wpjam_decrypt($text, $args){
	return wpjam_encrypt($text, $args+['de'=>true]);
}

function wpjam_pad($text, $type, ...$args){
	if($type == 'pkcs7'){
		$pad	= $args[0] - (strlen($text) % $args[0]);
		$text	.= str_repeat(chr($pad), $pad);
	}elseif($type == '-pkcs7'){
		$pad	= ord(substr($text, -1));
		$text	= ($pad > 0 && $pad < $args[0]) ? substr($text, 0, -1 * $pad) : $text;
	}elseif($type == 'weixin'){
		$text	= wp_generate_password(16, false).pack("N", strlen($text)).$text.$args[0];
	}elseif($type == '-weixin'){
		$length	= (unpack("N", substr($text, 16, 4)))[1];

		return (($appid = substr($text, $length + 20)) != $args[0])
		? new WP_Error('invalid_appid', 'Appid 校验「'.$appid.'」「'.$args[0].'」错误')
		: substr($text, 20, $length);
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

// JSON 
function wpjam_json_encode($data){
	return wp_json_encode($data, JSON_UNESCAPED_UNICODE);
}

function wpjam_json_decode($json, $assoc=true){
	$result	= ($json = wpjam_strip_control_chars($json)) ? json_decode($json, $assoc) : new WP_Error('empty_json', 'JSON 内容不能为空！');
	$result	??= str_contains($json, '\\') ? json_decode(stripslashes($json), $assoc) : $result;

	if(is_null($result)){
		trigger_error('json_decode_error['.json_last_error().']:'.($msg = json_last_error_msg())."\n".var_export($json, true));

		return new WP_Error('json_decode_error', $msg);
	}

	return $result;
}

function wpjam_send_json($data=[], $code=null){
	if($data === true || $data === []){
		$data	= ['errcode'=>0];
	}elseif($data === false || is_null($data)){
		$data	= ['errcode'=>'-1', 'errmsg'=>'error'];
	}elseif(is_wp_error($data) || wpjam_is_assoc_array($data)){
		$data	= wpjam_error($data);
	}

	$data	= wpjam_json_encode($data);
	$jsonp	= wp_is_jsonp_request();

	if(!headers_sent()){
		isset($code) && status_header($code);

		wpjam_doing_debug() || @header('Content-Type: application/'.($jsonp ? 'javascript' : 'json').'; charset='.get_option('blog_charset'));
	}

	echo $jsonp ? '/**/'.$_GET['_jsonp'].'('.$data.')' : $data; exit;
}

function wpjam_import($file, $columns=[]){
	$dir	= wp_get_upload_dir()['basedir']; 
	$file	= ($file && !str_starts_with($file, $dir) ? $dir : '').$file;

	if(!$file || !file_exists($file)){
		return new WP_Error('file_not_exists', '文件不存在');
	}

	$ext	= wpjam_at($file, '.', -1);

	if($ext == 'csv'){
		$columns	= wpjam_reduce($columns, fn($c, $v, $k)=> $c+[trim($v)=>$k, $k=>$k], []);

		if(($handle = fopen($file, 'r')) !== false){
			while(($row = fgetcsv($handle)) !== false){
				if(!array_filter($row)){
					continue;
				}

				if(($encoding ??= mb_detect_encoding(implode('', $row), mb_list_encodings(), true)) != 'UTF-8'){
					$row	= array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'GBK'), $row);
				}

				if(isset($map)){
					$data[]	= array_map(fn($i)=> preg_replace('/="([^"]*)"/', '$1', $row[$i]), $map);
				}else{
					$row	= array_map(fn($v)=> trim(trim($v), "\xEF\xBB\xBF"), $row);
					$map	= $columns ? wpjam_array($row, fn($i, $k)=> isset($columns[$k]) ? [$columns[$k], $i] : null) : array_flip($row);
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
	$handle	= fopen('php://output', 'w');
	$ext	= wpjam_at($file, '.', -1);

	header('Content-Disposition: attachment;filename='.$file);
	header('Content-Type: text/'.($ext == 'txt' ? 'plain' : $ext));
	header('Pragma: no-cache');
	header('Expires: 0');

	if($ext == 'csv'){
		fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));

		$columns && fputcsv($handle, $columns);

		array_walk($data, fn($item)=> fputcsv($handle, $columns ? wpjam_map($columns, fn($k)=> $item[$k] ?? '', 'k') : $item));
	}elseif($ext == 'txt'){
		fputs($handle, is_scalar($data) ? $data : maybe_serialize($data));
	}

	fclose($handle);

	exit;
}

function wpjam_columnar($items, $columns=[], $dict=[]){
	if(!$items){
		return [];
	}

	$columns= $columns ?: array_keys(array_first($items));
	$dict	= $dict ? wpjam_fill(array_intersect($dict, $columns), fn($k)=> array_values(array_unique(array_column($items, $k)))) : [];
	$rows	= [];

	foreach($items as $id => $item){
		if($dict){
			foreach(array_intersect_key($item, $dict) as $k => $v){
				$item[$k]	= array_search($v, $dict[$k]);
			}
		}

		$rows[$id]	= array_map(fn($k)=> $item[$k] ?? null, $columns);
	}

	return ['columns'=>$columns, 'rows'=>$rows]+($dict ? ['dict'=>$dict] : []);
}

function wpjam_records($data){
	if(!$data || empty($data['columns']) || empty($data['rows'])){
		return [];
	}

	$dict	= $data['dict'] ?? [];
	$items	= [];

	foreach($data['rows'] as $id => $row){
		$row	= array_combine($data['columns'], $row);

		if($dict){
			foreach(array_intersect_key($row, $dict) as $k => $v){
				$row[$k]	= $dict[$k][$v];
			}
		}

		$items[$id]	= $row;
	}

	return $items;
}

function wpjam_compress($data, $base64=true, $level=6){
	$text	= gzcompress(wpjam_json_encode($data), $level);

	return $base64 ? base64_encode($text) : $text;
}

function wpjam_uncompress($text, $base64=true){
	return wpjam_json_decode(gzuncompress($base64 ? base64_decode($text) : $text));
}

// User agent
function wpjam_get_user_agent(){
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function wpjam_get_ip(){
	return $_SERVER['REMOTE_ADDR'] ?? '';
}

function wpjam_parse_user_agent($agent=null, $referer=null){
	$agent	??= wpjam_get_user_agent();
	$rule	= array_find([
		['iPhone', 'iOS'],
		['iPad', 'iOS'],
		['iPod', 'iOS'],
		['Android'],
		['Windows NT', 'Windows'],
		['Macintosh'],
		['Windows Phone'],
		['BlackBerry'],
		['BB10', 'BlackBerry'],
		['Symbian'],
	], fn($v)=> stripos($agent, $v[0]));

	$os	= $rule ? ($rule[1] ?? $rule[0]) : 'unknown';

	if($os == 'iOS'){
		if(preg_match('/OS (.*?) like Mac OS X[\)]{1}/i', $agent, $m)){
			$ua	= [(float)trim(str_replace('_', '.', $m[1])), $rule[0]];
		}
	}elseif($os == 'Android'){
		if(preg_match('/Android ([0-9\.]{1,}?); (.*?) Build\/(.*?)[\)\s;]{1}/i', $agent, $m) && !empty($m[1]) && !empty($m[2])){
			$ua	= [trim($m[1]), str_contains($m[2], ';') ? wpjam_at(trim($m[2]), ';', 1) : trim($m[2])];
		}
	}

	$rule	= array_find([
		['lynx'],
		['safari',	'/version\/([\d\.]+).*safari/i'],
		['edge',	'/edge\/([\d\.]+)/i'],
		['chrome',	'/chrome\/([\d\.]+)/i'],
		['firefox',	'/firefox\/([\d\.]+)/i'],
		['opera',	'/(?:opera).([\d\.]+)/i'],
		['opr/',	'/(?:opr).([\d\.]+)/i',	'opera'],
		['msie',	'',	'ie'],
		['trident',	'',	'ie'],
		['gecko'],
		['nav']
	], fn($v)=> stripos($agent, $v[0]));

	return ['os'=>$os, 'os_version'=>$ua[0] ?? 0, 'device'=>$ua[1] ?? '']+array_combine(
		['browser', 'browser_version'],
		$rule ? [($rule[2] ?? '') ?: $rule[0], !empty($rule[1]) && preg_match($rule[1], $agent, $m) ? (float)(trim($m[1])) : 0] : ['', 0]
	)+array_combine(
		['app', 'app_version'],
		preg_match('/MicroMessenger\/(.*?)\s/', $agent, $m) ? [str_contains($referer ?? ($_SERVER['HTTP_REFERER'] ?? ''), 'https://servicewechat.com') ? 'weapp' : 'weixin',  (float)$m[1]] : ['', 0]
	);
}

function wpjam_parse_ip($ip=''){
	$ip	= $ip ?: ($_SERVER['REMOTE_ADDR'] ?? '');

	if($ip == 'unknown' || !$ip){
		return false;
	}

	$default	= ['ip'=>$ip]+array_fill_keys(['country', 'region', 'city'], '');

	if(!file_exists(WP_CONTENT_DIR.'/uploads/17monipdb.dat')){
		return $default;
	}

	$nip	= gethostbyname($ip);
	$ipdot	= explode('.', $nip);

	if($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4){
		return $default;
	}

	static $cache	= [];

	if(!$cache){
		$fp		= fopen(WP_CONTENT_DIR.'/uploads/17monipdb.dat', 'rb');
		$offset	= unpack('Nlen', fread($fp, 4));
		$index	= fread($fp, $offset['len'] - 4);
		$cache	= ['fp'=>$fp, 'offset'=>$offset, 'index'=>$index];

		register_shutdown_function(fn()=> fclose($fp));
	}

	$fp		= $cache['fp'];
	$offset	= $cache['offset'];
	$index	= $cache['index'];
	$nip2	= pack('N', ip2long($nip));
	$start	= (int)$ipdot[0]*4;
	$start	= unpack('Vlen', $index[$start].$index[$start+1].$index[$start+2].$index[$start+3]);

	for($start = $start['len']*8+1024; $start < $offset['len']-1024-4; $start+=8){
		if($index[$start].$index[$start+1].$index[$start+2].$index[$start+3] >= $nip2){
			$index_offset = unpack('Vlen', $index[$start+4].$index[$start+5].$index[$start+6]."\x0");
			$index_length = unpack('Clen', $index[$start+7]);

			fseek($fp, $offset['len']+$index_offset['len']-1024);

			$data	= explode("\t", fread($fp, $index_length['len']));
			$data	= array_slice(array_pad($data, 3, ''), 0, 3);

			return ['ip'=>$ip]+array_combine(['country', 'region', 'city'], $data);
		}
	}

	return $default;
}

// $a, $args
// $a, $b
// $a, $op, $b, $strict=false
function wpjam_compare($a, $op, ...$args){
	if(wpjam_is_assoc_array($op)){
		return wpjam_is_assoc_array($a) && isset($op['key'])
		? wpjam_match($a, $op)
		: wpjam_compare($a, $op['compare'] ?? '', $op['value'] ?? null, (bool)($args['strict'] ?? false));
	}

	$is		= is_array($op) || !$args;
	$b		= $is ? $op : array_shift($args);
	$op		= $is ? '' : $op;
	$strict	= in_array($op, ['!==', '===']) ? true : (bool)array_shift($args);
	$op		= $op ? strtoupper(['!=='=>'!=', '==='=>'=', '=='=>'='][$op] ?? $op) : (is_array($b) ? 'IN' : '=');
	$inv	= ['!='=>'=', '<='=>'>', '>='=>'<', 'NOT IN'=>'IN', 'NOT BETWEEN'=>'BETWEEN', 'NOT LIKE'=>'LIKE', 'NOT REGEXP'=>'REGEXP'][$op] ?? '';

	if($inv){
		return !wpjam_compare($a, $inv, $b, $strict);
	}

	$b	= in_array($op, ['IN', 'BETWEEN']) ? wp_parse_list($b) : (is_string($b) ? trim($b) : $b);

	switch($op){
		case '=':		return $strict ? $a === $b : $a == $b;
		case '>':		return $a > $b;
		case '<':		return $a < $b;
		case '<=>':		return $a <=> $b;
		case 'IN':		return is_array($a) ? array_all($a , fn($v)=> in_array($v, $b, $strict)) : in_array($a, $b, $strict);
		case 'LIKE':	return str_contains((string)$a, str_replace('%', '', (string)$b));
		case 'BETWEEN':	return $a >= $b[0] && $a <= ($b[1] ?? $b[0]);
		case 'REGEXP':	return (bool)preg_match('/'.str_replace('/', '\/', (string)$b).'/', (string)$a);
	}
}

function wpjam_operate($a, $op, $b){
	if(is_array($a)){
		switch($op){
			case '+': return array_merge($a, $b);
			case '-': return wpjam_is_assoc_array($a) && wp_is_numeric_array($b) ? wpjam_except($a, $b) : array_diff($a, $b);
		}
	}else{
		switch($op){
			case '+':	return $a + $b;
			case '-':	return $a - $b;
			case '*':	return $a * $b;
			case '/':	return $a / $b;
			case '%':	return $a % $b;
			case '**':	return $a ** $b;
			case '.':	return $a.$b;
			default:	return wpjam_compare($a, $op, $b);
		}
	}
}

function wpjam_calc(...$args){
	if(wpjam_is_assoc_array($args[0])){
		$item		= $args[0];
		$formulas	= $args[1];
		$if_errors	= $args[2] ?? [];
		$item		= array_diff_key($item, $formulas);

		foreach($formulas as $key => $formula){
			foreach($formula as &$t){
				if(str_starts_with($t, '$')){
					$k	= substr($t, 1);
					$v	= $item[$k] ?? null;
					$t	= $v === null || $v === '' ? 0 : (is_numeric($v) ? $v : wpjam_format($v, '-,', false));
					$t	= $t === false ? ($if_errors[$k] ?? false) : $t;

					if($t === false){
						$item[$key]	= $if_errors[$key] ?? '!无法计算';
						goto calced;
					}
				}
			}

			try{
				$item[$key]	= wpjam_calc($formula);
			}catch(Throwable $e){
				$item[$key]	= $if_errors[$key] ?? ($e instanceof DivisionByZeroError ? '!除零错误' : '!'.$e->getMessage());
			}

			calced:;

			if(!$item[$key]){
				unset($item[$key]);
			}
		}

		return $item;
	}

	$exp	= $args[0];
	$item	= $args[1] ?? [];
	$calc	= [];

	foreach(is_array($exp) ? $exp : wpjam_formula(...$args) as $t){
		if(is_numeric($t) || $t === '|'){
			$calc[]	= $t;
		}elseif(try_prefix($t, '-', '$')){
			$calc[]	= $item[$t] ?? 0;
		}elseif(in_array($t, ['sin', 'cos', 'abs', 'max', 'min', 'sqrt', 'pow', 'round', 'floor', 'ceil', 'fmod'])){
			$calc[]	= $t(...(array_slice(array_splice($calc, array_last(array_keys($calc, '|'))), 1) ?: wpjam_throw('invalid_calc', '函数「'.$t.'」无有效参数')));
		}else{
			$v	= array_pop($calc);

			if(in_array($t, ['/', '%']) && in_array((string)$v, ['0', '0.0', ''])){
				throw new DivisionByZeroError('Division by zero');
			}
			
			$calc[]	= wpjam_operate(array_pop($calc), $t, $v);
		}
	}

	return count($calc) === 1 ? $calc[0] : wpjam_throw('invalid_calc', '计算栈剩余元素数还有'.count($calc).'个');
}

function wpjam_formula(...$args){
	if(is_array($args[0])){
		$fields	= array_shift($args);

		if($args){
			$render	= fn($key)=> '字段'.($fields[$key]['title'] ?? '').'「'.$key.'」'.'公式「'.$fields[$key]['formula'].'」';
			$key	= $args[0];
			$parsed	= $args[1];
			$path	= $args[2] ?? [];

			if(isset($parsed[$key])){
				return $parsed;
			}

			if(in_array($key, $path)){
				wpjam_throw('invalid_formula', '公式嵌套：'.implode(' → ', wpjam_map(array_slice($path, array_search($key, $path)), fn($k)=> $render($k))));
			}

			$path[]		= $key;
			$formula	= wpjam_formula($fields[$key]['formula'], $fields, $render($key).'错误');
			$parsed 	= array_reduce($formula, fn($c, $t)=> try_prefix($t, '-', '$') && !empty($fields[$t]['formula']) ? wpjam_formula($fields, $t, $c, $path) : $c, $parsed);

			return $parsed+[$key => $formula];
		}

		return wpjam_reduce($fields, fn($c, $v, $k)=> empty($v['formula']) ? $c : wpjam_formula($fields, $k, $c), []);
	}

	$formula	= trim(str_replace("\xc2\xa0", ' ', $args[0]));
	$fields		= $args[1] ?? [];
	$error		= $args[2] ?? '';
	$throw		= fn($msg)=> wpjam_throw('invalid_formula', $error.'：'.$msg);
	$functions	= ['sin', 'cos', 'abs', 'ceil', 'pow', 'sqrt', 'pi', 'max', 'min', 'fmod', 'round'];
	$precedence	= ['+'=>1, '-'=>1, '**'=>2, '*'=>2, '/'=>2, '%'=>2, '>='=>3, '<='=>3, '!='=>3, '=='=>3, '>'=>3, '<'=>3,];
	$signs		= implode('|', array_map(fn($v)=> preg_quote($v, '/'), [...array_keys($precedence), '(', ')', ',']));
	$formula	= preg_split('/\s*('.$signs.')\s*/', $formula, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
	$output		= $stack = [];
	$pt			= null;

	foreach($formula as $i => $t){
		$nt	= $formula[$i+1] ?? null;

		if(is_numeric($t)){
			$output[]	= str_ends_with($t, '.') ? $throw('无效的数字「'.$t.'」') : (float)$t;
		}elseif($t[0] === '$'){
			$output[]	= isset($fields[substr($t, 1)]) ? $t : $throw('「'.$t.'」未定义');
		}elseif(in_array($t, $functions, true)){
			$stack[]	= $t;
			$output[]	= '|';
		}elseif($t === '('){
			$stack[]	= $t;
		}elseif($t === ')' || $t === ','){
			if(!in_array('(', $stack, true) || ($t === ',' && ($pt === '(' || !$nt || $nt === ','))){
				$throw(($t === ')' ? '未匹配' : '无效').'的「'.$t.'」');
			}

			while(array_last($stack) !== '('){
				$output[] = array_pop($stack);
			}

			if($t === ')'){
				array_pop($stack);

				in_array(array_last($stack), $functions) && array_push($output, array_pop($stack));
			}
		}elseif(isset($precedence[$t])){
			$r	= ((!$pt || in_array($pt, ['(', ','], true)) ? 1 : 0)+((!$nt || in_array($nt, [')', ','], true) || isset($precedence[$nt])) ? 2 : 0);

			if(in_array($t, ['+', '-'], true) && $r == 1){
				$output[]	= 0;
			}elseif($r){
				$throw('操作符「'.$t.'」缺少操作数');
			}

			while($stack && ($v = array_last($stack)) !== '('){
				if(in_array($v, $functions) || $precedence[$t] <= $precedence[$v]){
					$output[]	= array_pop($stack);
				}else{
					break;
				}
			}

			$stack[] = $t;
		}else{
			$throw('无效的符号「'.$t.'」');
		}

		$pt	= $t;
	}
	
	return array_merge($output, array_reverse(in_array('(', $stack, true) ? $throw('未匹配的「(」') : $stack));
}

function wpjam_format($value, $format, ...$args){
	if(is_array($value) && is_array($format)){
		return wpjam_reduce($format ?: [], fn($c, $v, $k)=> isset($c[$k]) ? wpjam_set($c, $k, wpjam_format($c[$k], ...$v)) : $c, $value);
	}

	if(is_numeric($value)){
		if($format == ','){
			return number_format(trim($value), (int)($args[0] ?? 2));
		}elseif($format == '%'){
			return round($value * 100, ($args[0] ?? 2) ?: 2).'%';
		}elseif(!$format && $args && is_numeric($args[0])){
			return round($value, $args[0]);
		}

		return $value / 1;
	}elseif(in_array($format, ['-,', '-%'])){
		if(is_string($value)){
			$value	= str_replace(',', '', trim($value));

			if($format == '-%' && try_suffix($value, '-', '%')){
				$value	= is_numeric($value) ? $value / 100 : $value;
			}
		}

		return is_numeric($value) ? $value / 1 : ($args ? $args[0] : $value);
	}

	return $value;
}

function wpjam_match($item, ...$args){
	if(!$args || is_null($args[0])){
		return true;
	}

	$args	= wpjam_parse_show_if(is_string($args[0]) ? $args : $args[0]);
	$value	= wpjam_get($item, $args['key']);
	$value2	= $args['value'] ?? null;

	if(!isset($args['compare'])){
		if(!empty($args['callable']) && (is_closure($value) || (is_callable($value) && is_array($value)))){
			return $value($value2, $item);
		}

		if(isset($args['if_null']) && is_null($value)){
			return $args['if_null'];
		}
	}

	if(is_array($value) || !empty($args['swap'])){
		[$value, $value2]	= [$value2, $value];
	}

	return wpjam_compare($value, $args['compare'] ?? null, $value2, (bool)($args['strict'] ?? false));
}

function wpjam_matches($arr, $args, $op='AND'){
	$op	= strtoupper($op);

	if(!in_array($op, ['AND', 'ALL', 'OR', 'ANY', 'NOT'])){
		return false;
	}

	if(!is_callable($args)){
		return wpjam_matches($args, fn($v, $k)=> wpjam_match($arr, ...(wpjam_is_assoc_array($v) ? [$v+['key'=>$k]] : [$k, $v])), $op);
	}

	if(in_array($op, ['AND', 'ALL'], true)){
		return array_all($arr, $args);
	}elseif(in_array($op, ['OR', 'ANY'], true)){
		return array_any($arr, $args);
	}else{
		return !array_all($arr, $args);
	}
}

// Array
function wpjam_is_assoc_array($arr){
	return is_array($arr) && !wp_is_numeric_array($arr);
}

function wpjam_array($arr=null, $cb=null, $skip_null=false){
	if(!$cb){
		if(is_object($arr)){
			if(method_exists($arr, 'to_array')){
				return $arr->to_array();
			}elseif($arr instanceof Traversable){
				return iterator_to_array($arr);
			}else{
				return ($arr instanceof JsonSerializable) && is_array($data = $arr->jsonSerialize()) ? $data : [];
			}
		}

		return (array)$arr;	
	}

	$data	= [];

	foreach($arr as $k => $v){
		$r	= $cb($k, $v);

		if(is_scalar($r)){
			$k	= $r;
		}elseif(is_array($r) && count($r) >= 2 && !($skip_null && is_null($r[1]))){
			[$k, $v]	= $r;
		}else{
			continue;
		}

		$data	= wpjam_set($data, $k, $v, '[]');
	}

	return $data;
}

function wpjam_fill($keys, $cb){
	return wpjam_array($keys, fn($i, $k)=> [$k, $cb($k, $i)], true);
}

function wpjam_pick($arr, $args){
	return wpjam_array($args, fn($i, $k)=> [$k, wpjam_get($arr, $k)], true);
}

function wpjam_entries($items, $key=null, $value=null){
	$key	??= 0;
	$value	??= (int)($key === 0);

	return wpjam_array($items, fn($k, $v)=> [null, [$key=>$k, $value=>$v]]);
}

function wpjam_column($items, $key=null, $index=null){
	return wpjam_array($items, fn($k, $v)=> [
		is_null($index) || $index === false ? null : ($index === true ? $k : $v[$index]),
		is_array($key) ? wpjam_array($key, fn($i, $j)=> [wpjam_is_assoc_array($key) ? $i : $j, wpjam_get($v, $j)], true) : wpjam_get($v, $key)
	]);
}

function wpjam_map($arr, $cb, $args=[]){
	if($arr){
		$args	= is_bool($args) || $args === 'deep' ? ['deep'=>(bool)$args] : (is_string($args) ?  ['mode'=>$args] : $args);
		$mode	= str_split(in_array($args['mode'] ?? '', ['vk', 'kv', 'k', 'v']) ? $args['mode'] : 'vk');
		$deep	= $args['deep'] ?? false;
		$all	= in_array($deep, [true, '*'], true);

		foreach($arr as $k => &$v){
			$d	= is_array($v) && ($all || (is_string($deep) && is_array($v[$deep] ?? '')));
			$v	= (!$d || $deep === '*') ? $cb(...array_map(fn($c) => $c === 'k' ? $k : $v, $mode)) : $v;
			$v	= $d ? ($all ? wpjam_map($v, $cb, $args) : [$deep => wpjam_map($v[$deep], $cb, $args)]+$v) : $v;
		}

		unset($v);	
	}

	return $arr;
}

function wpjam_reduce($arr, $cb, $carry=null, $key='', $args=[]){
	$depth	= $args['depth'] ??= 0;

	foreach(wpjam_array($arr) as $k => $v){
		$carry	= $cb($carry, $v, $k, $args);

		if($key && (empty($args['max_depth']) || $args['max_depth'] > $depth+1) && is_array($v)){
			$sub	= $key === true ? $v : wpjam_get($v, $key);
			$carry	= is_array($sub) ? wpjam_reduce($sub, $cb, $carry, $key, ['depth'=>$depth+1]+$args) : $carry;
		}
	}

	return $carry;
}

function wpjam_nest($items, $options=[], $parent=null, $depth=0){
	if(!$items){
		return [];
	}

	$fields	= array_filter($options['fields'] ?? [])+['id'=>'id', 'name'=>'name', 'parent'=>'parent', 'children'=>'children'];

	if($parent === null){
		$group		= wpjam_group($items, $fields['parent']);
		$group[0]	= array_filter([$options['top'] ?? '']) ?: ($group[0] ?? array_first($group));

		return wpjam_nest($group, $options, 0, 0);
	}

	$cb		= $options['item_callback'] ?? '';
	$max	= $options['max_depth'] ?? 0;
	$format	= $options['format'] ?? '';
	$parsed	= [];

	foreach(wpjam_pull($items, $parent) ?: [] as $item){
		$item		= $cb ? $cb($item) : $item;
		$children	= (!$max || $max > $depth+1) ? wpjam_nest($items, $options, wpjam_get($item, $fields['id']), $depth+1) : [];
		$parsed[]	= wpjam_set($item, ...($format == 'flat'
			? [$fields['name'], str_repeat('&emsp;', $depth).wpjam_get($item, $fields['name'])]
			: [$fields['children'], $children]
		));

		if($format == 'flat'){
			$parsed	= array_merge($parsed, $children);
		}
	}

	return $parsed;
}

function wpjam_at($arr, $index, ...$args){
	if(is_string($arr)){
		[$sep, $index]	= is_int($index) ? [$args[0] ?? '', $index] : [$index, $args[0] ?? 0];

		$sep && ($arr = explode($sep, $arr));
	}

	if(is_array($arr) || is_string($arr)){
		$count	= is_array($arr) ? count($arr) : strlen($arr);
		$index	= $index >= 0 ? $index : $count + $index;

		if($index >= 0 && $index < $count){
			return is_string($arr) ? $arr[$index] : $arr[array_keys($arr)[$index]];
		}
	}
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

function wpjam_rotate($arr, $step=1){
	if(($count = count($arr)) <= 1 || ($step %= $count) === 0){
		return $arr;
	}

	$step	= $step < 0 ? $count + $step : $step;

	return [...array_slice($arr, $step), ...array_slice($arr, 0, $step)];
}

function wpjam_find($arr, $cb, ...$args){
	$output	= 'value';

	if($args){
		if(is_callable($args[0])){
			$output	= 'result';
			$mapper	= $args[0];
		}else{
			$output	= $args[0];
		}
	}

	$cb	= wpjam_is_assoc_array($cb) ? fn($v)=> wpjam_matches($v, $cb) : ($cb ?: fn()=> true);
	$cb	= $cb === true ? fn($v)=> $v : $cb;

	if(!$cb){
		return;
	}

	if($output == 'value'){
		return array_find($arr, $cb);
	}

	if($output == 'key'){
		return array_find_key($arr, $cb);
	}

	if($output == 'index'){
		return array_search(array_find_key($arr, $cb), array_keys($arr));
	}

	if($output == 'result'){
		foreach($arr as $k => $v){
			$v	= $mapper($v, $k);

			if($cb($v)){
				return $v;
			}
		}
	}
}

function wpjam_group($arr, $field){
	$cb	= is_closure($field) ? $field : fn($v) => wpjam_get($v, $field);

	return wpjam_reduce($arr, fn($c, $v, $k)=> wpjam_set($c, [$cb($v, $k), $k], $v), []);
}

function wpjam_pull(&$arr, $key, ...$args){
	$value	= (is_array($key) ? 'wpjam_pick' : 'wpjam_get')($arr, $key, ...$args);
	$arr	= wpjam_except($arr, $key);

	return $value;
}

function wpjam_assoc($arr){
	if(!wp_is_numeric_array($arr) && (!($sub = wpjam_filter($arr, fn($v, $k) => is_int($k))) || !array_is_list($sub))){
		return $arr;
	}

	return wpjam_array($arr, fn($k, $v)=> is_int($k) ? $v : $k);
}

function wpjam_except($arr, $key, ...$args){
	if(is_object($arr)){
		foreach((array)$key as $k){
			unset($arr->$k);
		}

		return $arr;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true));
		return $arr;
	}

	if(is_array($key) || wpjam_exists($arr, $key)){
		return array_diff_key($arr, is_array($key) ? array_flip($key) : [$key=>'']);
	}

	if(str_ends_with($key, '[]') && $args){
		return wpjam_set($arr, substr($key, 0, -2), array_diff(wpjam_get($arr, $key, [], '[]'), $args), '[]');
	}

	$key	= wpjam_keys($key, ...$args);
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

	return $arr;
}

function wpjam_merge($arr, $data){
	foreach($data as $k => $v){
		$arr[$k]	= ((wpjam_is_assoc_array($v) || $v === []) && isset($arr[$k]) && wpjam_is_assoc_array($arr[$k])) ? wpjam_merge($arr[$k], $v) : $v;
	}

	return $arr;
}

function wpjam_diff($arr, $data, $compare='value'){
	if($compare == 'value' && array_is_list($arr) && array_is_list($data)){
		return array_values(array_diff($arr, $data));
	}

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

function wpjam_filter($arr, $cb=null, ...$args){
	$list	= array_is_list($arr);

	if($cb === 'unique'){
		$arr	= array_unique($arr);
	}elseif($cb && is_array($cb) && !is_callable($cb)){
		$arr	= wpjam_is_assoc_array($cb) ? array_filter($arr, fn($v)=> wpjam_matches($v, $cb, ...$args)) : array_intersect_key($arr, array_flip($cb));
	}elseif($cb){
		$arr	= ($args[0] ?? ($cb == 'isset')) ? array_map(fn($v)=> is_array($v) ? wpjam_filter($v, $cb, true) : $v, $arr) : $arr;
		$arr	= array_filter($arr, $cb === 'isset' ? fn($v)=> !is_null($v) : $cb, ARRAY_FILTER_USE_BOTH);
	}else{
		$arr	= array_filter($arr);
	}

	return $list ? array_values($arr) : $arr;
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
		$args	= wpjam_reduce($args[0], fn($carry, $order, $field)=>[
			...$carry,
			($column = array_column($arr, $field)),
			$is_asc($order) ? SORT_ASC : SORT_DESC,
			is_numeric(array_first($column)) ? SORT_NUMERIC : SORT_REGULAR
		], []);
	}elseif(is_callable($args[0]) || is_string($args[0])){
		$field	= $args[0];
		$order	= $args[1] ?? '';

		if(is_callable($field)){
			$column	= array_map($field, ($order === 'key' ? array_keys($arr) : $arr));
			$flag	= $args[2] ?? SORT_NUMERIC;
		}else{
			$default= $args[2] ?? 0;
			$column	= array_map(fn($item)=> wpjam_get($item, $field, $default), $arr);
			$flag	= is_numeric($default) ? SORT_NUMERIC : SORT_REGULAR;
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

function wpjam_keys($key, ...$args){
	if(!$args){
		return wpjam_keys($key, '[]') ?: (wpjam_keys($key, '.') ?: []);
	}

	$keys	= [];

	if($args[0] == '.'){
		if(str_contains($key, '.')){
			return explode('.', $key);
		}
	}elseif($args[0] == '[]'){
		$total	= strlen($key);
		$cursor	= 0;

		while($cursor < $total){
			$offset	= $keys ? 1 : 0;
			$len	= $offset && $key[$cursor] !== '[' ? 0 : strcspn($key, $offset ? ']' : '[', $cursor);
			$sub	= ($len > $offset && $len < $total-$cursor) ? substr($key, $cursor+$offset, $len-$offset) : '';

			if($sub === '' || str_contains($sub, $offset ? '[' : ']')){
				return [];
			}

			$keys[]	= $sub;
			$cursor	+= $len + $offset;
		}
	}

	return $keys;
}

function wpjam_names($name){
	return is_array($name)
	? array_shift($name).($name ? '['.implode('][', $name).']' : '')
	: ($name ? (wpjam_keys($name, '[]') ?: [$name]) : []);
}

function wpjam_get($arr, $key, $default=null, ...$args){
	if(is_object($arr)){
		return $arr->$key ?? $default;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true));
		return $default;
	}

	if(!is_array($key)){
		if(isset($key) && wpjam_exists($arr, $key)){
			return $arr[$key];
		}

		if(is_null($key)){
			return $arr;
		}

		if(!$args || $args[0] === '[]'){
			if($key === '[]'){
				return $arr;
			}

			if(str_ends_with($key, '[]')){
				$value	= wpjam_get($arr, substr($key, 0, -2), $default, '[]');

				return is_object($value) ? [$value] : (array)$value;
			}
		}

		$key	= wpjam_keys($key, ...$args);
	}

	return _wp_array_get($arr, $key, $default);
}

function wpjam_set($arr, $key, ...$args){
	if(wpjam_is_assoc_array($key)){
		if(!$args){	// del 2026-12-31
			return wpjam_reduce($key, fn($c, $v, $k)=> wpjam_set($c, $k, $v, ...$args), $arr);
		}

		trigger_error(var_export($key, true));
	}

	$value	= array_shift($args);

	if(is_object($arr)){
		$arr->$key = $value;

		return $arr;
	}

	if(!is_array($arr)){
		trigger_error(var_export($arr, true));
		return $arr;
	}

	if(!is_array($key)){
		if(isset($key) && wpjam_exists($arr, $key)){
			$arr[$key] = $value;

			return $arr;
		}

		if(is_null($key)){
			$arr[] = $value;

			return $arr;
		}

		if(!$args || $args[0] === '[]'){
			if($key === '[]'){
				$arr[] = $value;

				return $arr;
			}

			if(str_ends_with($key, '[]')){
				$items		= wpjam_get($arr, $key, [], '[]');
				$items[]	= $value;

				return wpjam_set($arr, substr($key, 0, -2), $items, '[]');
			}
		}

		$key	= wpjam_keys($key, ...$args) ?: [$key];
	}

	_wp_array_set($arr, $key, $value);

	return $arr;
}

function wpjam_pack($value, $key){
	return is_null($key) ? $value : wpjam_set([], $key, $value);
}

function wpjam_some($arr, $cb){
	foreach($arr as $k => $v){
		if($cb($v, $k)){
			return true;
		}
	}

	return false;
}

function wpjam_every($arr, $cb){
	foreach($arr as $k => $v){
		if(!$cb($v, $k)){
			return false;
		}
	}

	return true;
}

function wpjam_lines($str, ...$args){
	$sep	= ($args && is_closure($args[0]) ? '' : array_shift($args)) ?: "\n";
	$cb		= array_shift($args);
	$lines	= [];

	foreach(explode($sep, (string)$str) as $v){
		$v	= trim($v);
		$v	= $cb ? $cb($v) : $v;

		if(!is_blank($v)){
			$lines[]	= $v;
		}
	}

	return $lines;
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

if(!function_exists('array_first')){
	function array_first($array){
		return $array === [] ? null : $array[array_key_first($array)];  
	}
}

if(!function_exists('array_last')){
	function array_last($array){
		return $array === [] ? null : $array[array_key_last($array)];  
	}
}

function wpjam_move($arr, $id, $data){
	$arr	= array_values($arr);
	$index	= array_search($id, $arr);
	$arr	= wpjam_diff($arr, [$id]);

	$index === false && wpjam_throw('invalid_id', '无效的 ID');

	if(isset($data['pos'])){
		$index	= $data['pos'];
	}elseif(!empty($data['up'])){
		$index == 0 && wpjam_throw('invalid_position', '已经是第一个了，不可上移了！');

		$index--;
	}elseif(!empty($data['down'])){
		$index == count($arr) && wpjam_throw('invalid_position', '已经最后一个了，不可下移了！');

		$index++;
	}else{
		$k		= array_find(['next', 'prev'], fn($k)=> isset($data[$k]));
		$index	= ($k && isset($data[$k])) ? array_search($data[$k], $arr) : false;

		$index === false && wpjam_throw('invalid_position', '无效的移动位置');

		$index	+= $k == 'prev' ? 1 : 0;
	}

	return wpjam_add_at($arr, $index, null, $id);
}

// Bit
function wpjam_has_bit($value, $bit){
	return ((int)$value & (int)$bit) == $bit;
}

function wpjam_add_bit($value, $bit){
	return (int)$value | (int)$bit;
}

function wpjam_remove_bit($value, $bit){
	return (int)$value & (~(int)$bit);
}

// UUID
function wpjam_create_uuid(){
	$chars	= md5(uniqid(mt_rand(), true));

	return implode('-', array_map(fn($v)=> substr($chars, ...$v), [[0, 8], [8, 4], [12, 4], [16, 4], [20, 12]]));
}

// Str
if(!function_exists('explode_last')){
	function explode_last($sep, $str, $limit=2){
		$parts	= explode($sep, $str);
		$count	= count($parts);

		return $limit >= $count ? $parts : [implode($sep, array_splice($parts, 0, $count - $limit + 1)), ...$parts];
	}
}

if(!function_exists('try_fix')){
	function try_fix($type, &$str, ...$args){
		$action	= count($args) > 1 && in_array($args[0], ['+', '-']) ? array_shift($args) : '+';
		$fix	= array_shift($args);
		$has	= $type == 'prefix' ? str_starts_with($str, $fix) : str_ends_with($str, $fix);
		$res	= $has !== ($action == '+');

		if($res){
			$str	= $type == 'prefix'
			? ($has ? substr($str, strlen($fix)) : $fix.$str)
			: ($has ? substr($str, 0, -strlen($fix)) : $str.$fix);
		}

		return $res;
	}
}

if(!function_exists('try_prefix')){
	function try_prefix(&$str, ...$args){
		return try_fix('prefix', $str, ...$args);
	}
}

if(!function_exists('try_suffix')){
	function try_suffix(&$str, ...$args){
		return try_fix('suffix', $str, ...$args);
	}
}

function wpjam_prefix($str, ...$args){
	try_prefix($str, ...$args);

	return $str;
}

function wpjam_suffix($str, ...$args){
	try_suffix($str, ...$args);

	return $str;
}

function wpjam_join($sep, ...$args){
	return join($sep, array_filter(($args && is_array($args[0])) ? $args[0] : $args));
}

function wpjam_remove_pre_tab($str, $times=1){
	return preg_replace('/^\t{'.$times.'}/m', '', $str);
}

function wpjam_preg_replace($pattern, $replace, $subject, $limit=-1, &$count=null, $flags=0){
	$result	= is_closure($replace) ? preg_replace_callback($pattern, $replace, $subject, $limit, $count, $flags) : preg_replace($pattern, $replace, $subject, $limit, $count);

	if(is_null($result)){
		trigger_error(preg_last_error_msg());
		return $subject;
	}

	return $result;
}

function wpjam_serialize($data){
	return maybe_serialize(wpjam_sort(wpjam_map($data, fn($v)=> is_closure($v) ? spl_object_hash($v) : $v, true), 'k'));
}

function wpjam_unserialize($serialized, $cb=null){
	if($serialized){
		$result	= @unserialize($serialized, ['allowed_classes'=>false]);

		if(!$result){
			$fixed	= preg_replace_callback('!s:(\d+):"(.*?)";!', fn($m)=> 's:'.strlen($m[2]).':"'.$m[2].'";', $serialized);
			$result	= @unserialize($fixed, ['allowed_classes'=>false]);

			$result && $cb && $cb($fixed);
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
	// \xEF\xBF\xBD 常用来表示未知、未识别或不可表示的字符
}

// 移除 除了 line feeds 和 carriage returns 所有控制字符
function wpjam_strip_control_chars($text){
	return $text ? preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', $text) : '';
	// /[\x00-\x09\x0B\x0C\x0E-\x1F\x80-\x9F]/u
}

//获取第一段
function wpjam_get_first_p($text){
	$text	= wp_strip_all_tags($text);
	$line	= strtok($text, "\n");

	while($line !== false){
		if($line = trim($line)){
			return $line;
		}

		$line	= strtok("\n");
	}

	return '';
}

function wpjam_unicode_decode($text){
	return wpjam_preg_replace('/\\\\u(?!00[0-7][0-9A-F])[0-9a-fA-F]{4}/i', fn($m)=> json_decode('"'.$m[0].'"') ?: $m[0], $text);
}

function wpjam_zh_urlencode($url){
	return $url ? wpjam_preg_replace('/[\x{4e00}-\x{9fa5}]+/u', fn($m)=> urlencode($m[0]), $url) : '';
}

// 检查非法字符
function wpjam_blacklist_check($text, $name='内容'){
	$pre	= $text ? apply_filters('wpjam_pre_blacklist_check', null, $text, $name) : false;

	if(isset($pre)){
		return $pre;
	}

	$key	= strtok(get_option('disallowed_keys'), "\n");

	while($key !== false){
		if(($key = trim($key)) && stripos($text, $key) !== false){
			return true;
		}

		$key	= strtok("\n");
	}

	return false;
}

function wpjam_expandable($str, $num=10, $name=null){
	if(is_a($str, 'WPJAM_Tag') || count(explode("\n", $str)) > $num){
		static $index = 0;

		$name	= 'expandable_'.($name ?? (++$index));

		return '<div class="expandable-container"><input type="checkbox" class="button" id="'.esc_attr($name).'" /><div class="inner">'.$str.'</div></div>';
	}

	return $str;
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
	return sprintf(__('%s '.(($to ?: time()) > $from ? 'ago' : 'from now'), 'wpjam'), human_time_diff($from, $to));
}

function wpjam_human_date_diff($from, $to=0){
	$zone	= wp_timezone();
	$to		= $to ? date_create($to, $zone) : current_datetime();
	$from	= date_create($from, $zone);
	$day	= [
		0	=> __('Today', 'wpjam'),
		-1	=> __('Yesterday', 'wpjam'),
		-2	=> __('The day before yesterday', 'wpjam'),
		1	=> __('Tomorrow', 'wpjam'),
		2	=> __('The day after tomorrow', 'wpjam')
	][(int)$to->diff($from)->format('%R%a')] ?? '';

	return $day ?: ($from->format('W') == $to->format('W') ? __($from->format('l'), 'wpjam') : $from->format(__('F, Y', 'wpjam')));
}

// Video
function wpjam_get_video_mp4($id_or_url){
	if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
		if(preg_match('#http://www.miaopai.com/show/(.*?).htm#i',$id_or_url, $matches)){
			return 'http://gslb.miaopai.com/stream/'.esc_attr($matches[1]).'.mp4';
		}

		return ($id	= wpjam_get_qqv_id($id_or_url)) ? wpjam_get_qqv_mp4($id) : wpjam_zh_urlencode($id_or_url);
	}

	return wpjam_get_qqv_mp4($id_or_url);
}

function wpjam_get_qqv_mp4($vid, $cache=true){
	strlen($vid) > 20 && wpjam_throw('error', '无效的腾讯视频');

	if($cache){
		return wpjam_transient('qqv_mp4:'.$vid, fn()=> wpjam_get_qqv_mp4($vid, false), HOUR_IN_SECONDS*6);
	}

	$resp	= wpjam_remote_request('http://vv.video.qq.com/getinfo?otype=json&platform=11001&vid='.$vid, ['timeout'=>4, 'throw'=>true]);
	$resp	= trim(substr($resp, strpos($resp, '{')),';');
	$resp	= wpjam_try('wpjam_json_decode', $resp);

	empty($resp['vl']) && wpjam_throw('error', '腾讯视频不存在或者为收费视频！');

	$u	= $resp['vl']['vi'][0];

	return $u['ul']['ui'][0]['url'].$u['fn'].'?vkey='.$u['fvkey'];
}

function wpjam_get_qqv_id($id_or_url){
	if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
		return wpjam_find(['page', 'cover/.*'], true, fn($v)=> preg_match('#https://v.qq.com/x/'.$v.'/(.*?).html#i', $id_or_url, $m) ? $m[1] : '') ?: '';
	}

	return $id_or_url;
}

function wpjam_is_mobile_number($number){
	return preg_match('/^0{0,1}(1[3,5,8][0-9]|14[5,7]|166|17[0,1,3,6,7,8]|19[8,9])[0-9]{8}$/', $number);
}

function wpjam_set_cookie($key, $value, $expire=DAY_IN_SECONDS){
	if(is_null($value)){
		unset($_COOKIE[$key]);

		$expire	= time()-YEAR_IN_SECONDS;
	}else{
		$_COOKIE[$key]	= $value;

		$expire	+= $expire < time() ? time() : 0;
	}

	setcookie($key, $value ?? '', $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

	COOKIEPATH != SITECOOKIEPATH && setcookie($key, $value ?? '', $expire, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
}

function wpjam_clear_cookie($key){
	wpjam_set_cookie($key, null);
}

function wpjam_get_filter_name($name, $type){
	return (str_starts_with($name, 'wpjam') ? '' : 'wpjam_').str_replace('-', '_', $name).'_'.$type;
}
