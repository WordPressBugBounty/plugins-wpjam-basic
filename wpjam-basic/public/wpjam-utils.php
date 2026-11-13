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

	//iat Issued At
	//nbf Not Before
	//exp Expiration Time
	return hash_equals(wpjam_generate_signature('hmac-sha256', $header.'.'.$payload), $sign)
	&& ($data = wpjam_json_decode(base64_urldecode($payload)))
	&& wpjam_matches($data, wpjam_map(['iat'=>'<=', 'nbf'=>'<=', 'exp'=>'>='], fn($v)=> [$v=>time(), 'if_null'=>true]))
	? $data : false;
}

function wpjam_get_jwt($key='access_token', $required=false){
	return ($header = $_SERVER['HTTP_AUTHORIZATION'] ?? '') && try_prefix($header, '-', 'Bearer')
	? trim($header) : wpjam_param($key, ['required'=>$required]);
}

// Crypt
function wpjam_crypt($text, $args, $de=false){
	$params	= [$args['method'] ?? '', $args['key'] ?? '', $args['options'] ?? '', $args['iv'] ?? ''];
	$types	= array_filter([$args['pad'] ?? '', ($args['options'] ?? '') == OPENSSL_ZERO_PADDING ? 'pkcs7' : '']);
	$text	= $de ? openssl_decrypt($text, ...$params) : $text;
	$text	= array_reduce($de ? array_reverse($types) : $types, fn($c, $t)=> wpjam_pad($c, $t, $args, $de), $text);

	return $de ? $text : openssl_encrypt($text, ...$params);
}

function wpjam_encrypt($text, $args){
	return wpjam_crypt($text, $args, false);
}

function wpjam_decrypt($text, $args){
	return wpjam_crypt($text, $args, true);
}

function wpjam_pad($text, $type, $args=[], $un=false){
	if($type == 'pkcs7' && ($size = $args['block_size'] ?? '')){
		$pad	= $un ? ($text ? ord($text[-1]) : 0) : ($size - (strlen($text) % $size));
		$valid	= $pad && $pad <= $size && substr($text, -$pad) === str_repeat(chr($pad), $pad);
		$text	= $un ? ($valid ? substr($text, 0, -$pad) : $text) : $text.str_repeat(chr($pad), $pad);
	}elseif($type == 'weixin' && ($appid = trim($args['appid'] ?? ''))){
		if($un){
			$pad	= unpack("N", substr($text, 16, 4))[1];
			$id		= trim(substr($text, $pad + 20));
			$text	= $id != $appid ? new WP_Error('invalid_appid', 'Appid 校验「'.$id.'」「'.$appid.'」错误') : substr($text, 20, $pad);
		}else{
			$text	= wp_generate_password(16, false).pack("N", strlen($text)).$text.$appid;
		}
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
function wpjam_json_encode($data, $type=0){
	return wp_json_encode($data, JSON_UNESCAPED_UNICODE | ($type === 'attr' ? (JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : (int)$type));
}

function wpjam_json_decode($json, $assoc=true){
	$result	= ($json = wpjam_strip_control_chars($json)) !== '' ? json_decode($json, $assoc) : new WP_Error('empty_json', 'JSON 内容不能为空！');
	$result	??= str_contains($json, '\\') ? json_decode(stripslashes($json), $assoc) : $result;

	if(is_null($result) && ($error = json_last_error())){
		trigger_error('json_decode_error['.$error.']:'.json_last_error_msg()."\n".var_export($json, true));

		return new WP_Error('json_decode_error', json_last_error_msg());
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
		if(isset($code)){
			status_header($code);
		}

		@header('Content-Type: application/'.($jsonp ? 'javascript' : 'json').'; charset='.get_option('blog_charset'));
	}

	echo $jsonp ? '/**/'.$_GET['_jsonp'].'('.$data.')' : $data; exit;
}

function wpjam_encoding($text, $encoding, $type=''){
	if($type == 'check'){
		$encoding	= array_find(['UTF-8', ...(array)($encoding ?: ['GBK', 'BIG5'])], fn($v)=> mb_check_encoding($text, $v)) ?: '';

		return $encoding == 'UTF-8' ? '' : $encoding;
	}

	if($encoding){
		$text	= iconv($encoding, 'UTF-8//IGNORE', (string)$text);

		if($text === false){
			return '';
		}
	}

	return trim($text);
}

function wpjam_import($file, $columns=[], $encodings=[]){
	$dir	= realpath(wp_get_upload_dir()['basedir']);
	$dir	= $dir ? rtrim($dir, '/\\').DIRECTORY_SEPARATOR : '';
	$file	= $dir && is_string($file) && $file ? realpath((str_starts_with(realpath($file) ?: '', $dir) ? '' : $dir).$file) : '';

	if(!$file || !is_file($file) || !str_starts_with($file, $dir)){
		wpjam_throw('file_not_exists', '文件不存在');
	}

	$ext	= strtolower(pathinfo($file, PATHINFO_EXTENSION));

	if($ext == 'csv'){
		if(($handle = fopen($file, 'r')) !== false){
			while(($row = fgetcsv($handle)) !== false){
				if(array_all($row, 'is_blank')){
					continue;
				}

				if(!isset($map)){
					$row[0]	= ltrim($row[0], "\xEF\xBB\xBF");
					$enc	= wpjam_encoding(implode($row), $encodings, 'check');

					foreach($row as $i => $v){
						$v	= wpjam_encoding($v, $enc);
						$v	= $columns ? array_find_key($columns, fn($c, $k)=> $k === $v || trim($c) === $v) : $v;

						if(isset($v)){
							$map[$i]	= $v;
						}
					}

					if(isset($map)){
						continue;
					}

					break;
				}

				$item	= [];

				foreach($map as $i => $k){
					if(isset($row[$i])){
						$item[$k]	= preg_replace('/="([^"]*)"/', '$1', wpjam_encoding($row[$i], $enc));
					}
				}

				if($item){
					$data[]	= $item;
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

function wpjam_export($file, $data, $fields=[]){
	$handle	= fopen('php://output', 'w');
	$ext	= wpjam_at($file, '.', -1);

	header('Content-Disposition: attachment;filename='.$file);
	header('Content-Type: text/'.($ext == 'txt' ? 'plain' : $ext));
	header('Pragma: no-cache');
	header('Expires: 0');

	if($ext == 'csv'){
		fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));

		if($fields){
			fputcsv($handle, wpjam_map($fields, fn($v)=> is_array($v) ? $v['title'] : $v));
		}

		foreach($data as $item){
			fputcsv($handle, $fields ? wpjam_map($fields, fn($v, $k)=> wpjam_format($item[$k] ?? '', is_array($v) ? ($v['format'] ?? '') : '')) : $item);
		}
	}elseif($ext == 'txt'){
		fputs($handle, is_scalar($data) ? $data : maybe_serialize($data));
	}

	fclose($handle);

	exit;
}

function wpjam_columnar($items, $dict=[]){
	if(!$items){
		return $items;
	}

	$dict		= array_fill_keys($dict, []);
	$columns	= [];

	foreach($items as &$item){
		foreach($dict as $k => &$v){
			if(isset($item[$k])){
				$item[$k]	= $v[$item[$k]]	??= count($v);
			}
		}

		unset($v);

		$temp	= [];

		foreach($columns as $k){
			$temp[]	= $item[$k] ?? null;

			unset($item[$k]);
	
			if(!$item){
				break;
			}
		}

		foreach($item as $k => $v){
			if(isset($v)){
				$columns[]	= $k;
				$temp[]		= $v;
			}
		}

		while($temp && array_last($temp) === null){
			array_pop($temp);
		}

		$item	= $temp;
	}

	return $columns ? ['columns'=>$columns, 'items'=>$items, 'dict'=>array_map('array_flip', $dict)] : [];
}

function wpjam_records($data){
	if(!empty($data['items']) && !empty($data['columns'])){
		foreach($data['items'] as &$item){
			$item	= wpjam_record($item, $data['columns'], $data['dict'] ?? []);
		}

		return $data['items'];
	}
}

function wpjam_record($item, $columns, $dict=[]){
	$item	= array_combine(array_slice($columns, 0, count($item)), $item);

	foreach($dict as $k => $v){
		if(isset($item[$k])){
			$item[$k]	= $v[$item[$k]] ?? $item[$k];
		}
	}

	return $item;
}

function wpjam_compress($data, $base64=true, $level=6){
	$text	= $data ? gzcompress(wpjam_json_encode($data), $level) : '';

	return $text && $base64 ? base64_encode($text) : $text;
}

function wpjam_uncompress($text, $base64=true){
	$text	= $text ? @gzuncompress($base64 ? base64_decode($text) : $text) : $text;

	return $text ? wpjam_json_decode($text) : null;
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
			$ua	= [(float)str_replace('_', '.', $m[1]), $rule[0]];
		}
	}elseif($os == 'Android'){
		if(preg_match('/Android ([0-9\.]{1,}?); (.*?) Build\/(.*?)[\)\s;]{1}/i', $agent, $m) && !empty($m[1]) && !empty($m[2])){
			$ua	= [trim($m[1]), str_contains($m[2], ';') ? wpjam_at(trim($m[2]), ';', 1) : trim($m[2])];
		}
	}

	foreach([
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
	] as $rule){
		if(stripos($agent, $rule[0]) && (empty($rule[1]) || preg_match($rule[1], $agent, $m))){
			$browser	= [($rule[2] ?? '') ?: $rule[0], !empty($rule[1]) ? (float)$m[1] : 0];
			break;
		}
	}

	return ['os'=>$os, 'os_version'=>$ua[0] ?? 0, 'device'=>$ua[1] ?? '']+array_combine(
		['browser', 'browser_version'],
		$browser ?? ['', 0]
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
		$cache	= [$fp, $offset['len'], fread($fp, $offset['len'] - 4)];

		register_shutdown_function(fn()=> fclose($fp));
	}

	[$fp, $len, $idx] = $cache;

	$nip2	= pack('N', ip2long($nip));
	$start	= unpack('Vlen', substr($idx, (int)$ipdot[0]*4, 4))['len']*8+1024;

	for(; $start < $len-1024-4; $start+=8){
		if(substr($idx, $start, 4) >= $nip2){
			$offset = unpack('Vlen', substr($idx, $start+4, 3)."\x0")['len'];
			$length = unpack('Clen', $idx[$start+7])['len'];

			fseek($fp, $len+$offset-1024);

			return ['ip'=>$ip]+array_combine(['country', 'region', 'city'], array_slice(array_pad(explode("\t", fread($fp, $length)), 3, ''), 0, 3));
		}
	}

	return $default;
}

// DATA
function wpjam_operate($a, $op, $b){
	if(is_array($a)){
		switch($op){
			case '+': return array_merge($a, $b);
			case '-': return wpjam_is_assoc_array($a) && wp_is_numeric_array($b) ? wpjam_except($a, $b) : array_diff($a, $b);
		}
	}

	if(in_array($op, ['/', '%']) && in_array((string)$b, ['0', '0.0', ''])){
		throw new DivisionByZeroError('Division by zero');
	}

	if(in_array($op, ['+', '-', '*', '/']) && !(is_int($a) && is_int($b))){
		$r	= ['a'=>$a, 'b'=>$b];
		$ds	= array_map(fn($v)=> strlen(explode('.', (string)$v)[1] ?? ''), $r);

		if(($dp = max($ds)) && $dp <= 4){
			$r	= wpjam_map($r, fn($v, $k)=> (int)round($v * (10 ** ($op == '*' ? $ds[$k] : $dp))));

			return wpjam_operate($r['a'], $op, $r['b']) / ($op == '/' ? 1 : 10 ** ($op == '*' ? $ds['a']+$ds['b'] : $dp));
		}
	}

	switch($op){
		case '+':	return $a + $b;
		case '-':	return $a - $b;
		case '*':	return $a * $b;
		case '/':	return $a / $b;
		case '%':	return $a % $b;
		case '**':	return $a ** $b;
		case '.':	return $a . $b;
		case '<=>':	return $a <=> $b;
		default:	return wpjam_compare($a, $op, $b);
	}
}

function wpjam_entry($item, ...$args){
	$i	= $args ? (is_array($args[0]) ? null : $item) : (array_is_list($item) && count($item) == 2 ? $item[1] : null);
	$is	= isset($i) ? is_array($i) && array_key_exists('@value', $i) && array_all($i, fn($v, $k)=> str_starts_with($k, '@')) : null;

	if(!$args){
		return isset($is) ? ['@key'=>$item[0]]+($is ? $item[1] : ['@value'=>$item[1]]) : wpjam_array($item, fn($k)=> wpjam_prefix($k, '+', '@'));
	}elseif(is_array($args[0])){
		return [
			($args[0][0] ?? '@key')		=> $item[0],
			($args[0][1] ?? '@value')	=> $item[1]
		];
	}

	$key	= wpjam_prefix(array_shift($args), '-', '$');
	$data	= $is && !str_starts_with($key, '@') ? $item['@value'] : $item;

	return ($key === '@value' && !$is) ? $item : (is_array($data) || is_object($data) ? wpjam_get($data, $key) : null);
}

function wpjam_entries($items, ...$args){
	return wpjam_array($items, fn($k, $v)=> [null, $args ? wpjam_entry([$k, $v], ...$args) : [$k, $v]]);
}

function wpjam_transformer($key, ...$args){
	static $object;
	$object	??= wpjam_args([
		'replace'	=> 'str_replace',
		'upper'		=> 'strtoupper',
		'lower'		=> 'strtolower',
		'concat'	=> fn(...$args)=> implode($args),
	]);

	return [$object, $args ? 'update_arg' : 'get_arg']($key, ...$args);
}

function wpjam_transform($item, $rule, $set=true){
	if($set){
		$merge = wpjam_pull($rule, '@merge');

		foreach($rule as $k => $v){
			$v = wpjam_transform($item, $v, false);
			$v = $k === '@value' && $merge && is_array($item['@value']) && is_array($v) ? array_merge($item['@value'], $v) : $v;
			$k  = wpjam_prefix($k, '-', '$');

			if($k === '@value'){
				$item	= [$k=>$v]+$item;
			}else{
				$deep	= !str_starts_with($k, '@');
				$item	= is_array($deep ? $item['@value'] : $item) ? wpjam_set($item, $deep ? ['@value', $k] : [$k], $v) : wpjam_invalid('entry', '无法给标量设置字段「'.$k.'」');
			}
		}

		return $item;
	}

	if(is_array($rule) && count($rule) === 1 && ($op = array_key_first($rule)) && is_string($op) && $op[0] === '$'){
		$value	= $rule[$op];
		$op		= substr($op, 1);

		if($op === 'calc'){
			return wpjam_calc($value, $item, ['']);
		}

		if($cb = wpjam_transformer($op)){
			return $cb(...array_values(wpjam_map(is_array($value) ? $value : [$value], fn($v)=> wpjam_transform($item, $v, false))));
		}
	}

	return wpjam_is_assoc_array($rule)
	? wpjam_map($rule, fn($v)=> wpjam_transform($item, $v, false))
	: (is_string($rule) && $rule && in_array($rule[0], ['$', '@'], true) ? wpjam_entry($item, $rule) : $rule);
}

function wpjam_calc($formula, $item=[], $errors=[]){
	try{
		static $cached	= [];

		if(!is_array($formula)){
			$formula	= $cached[$formula] ??= wpjam_formula($formula);
		}

		$funs	= wpjam_formula('functions');
		$stack	= [];

		foreach($formula as $t){
			if(is_numeric($t)){
				$t	= $t+0;
			}elseif(in_array($t[0], ['$', '@'], true)){
				$v	= wpjam_entry($item, $t) ?: 0;
				$t	= ($t1 = $t[0] === '@') ? $t : substr($t, 1);
				$t	= is_numeric($v) ? $v : (wpjam_format($v, '-', $errors[$t] ?? null) ?? wpjam_invalid('calc', '无法计算'));
			}else{
				$c	= ($f = isset($funs[$t])) ? array_pop($stack) : 2;
				$v	= $c ? array_splice($stack, 0-$c) : [];
				$t	= $f ? $t(...array_map('wpjam_format', $v)) : wpjam_operate($v[0], $t, $v[1]);
			}

			$stack[]	= $t;
		}

		return count($stack) > 1 ? wpjam_invalid('calc', '计算错误') : wpjam_format($stack[0]);
	}catch(Throwable $e){
		return array_first($errors) ?? ($e instanceof DivisionByZeroError ? '!除零错误' : '!'.$e->getMessage());
	}
}

function wpjam_formula($formula, $fields=null, $prefix=''){
	if($formula === 'functions'){
		return ['sin'=>1, 'cos'=>1, 'abs'=>1, 'ceil'=>1, 'pow'=>2, 'sqrt'=>1, 'pi'=>0, 'max'=>-2, 'min'=>-2, 'fmod'=>2, 'round'=>1, 'floor'=>1];
	}

	$functions	= wpjam_formula('functions');
	$operators	= ['**'=>4, '*'=>3, '/'=>3, '%'=>3, '+'=>2, '-'=>2, '>='=>1, '<='=>1, '!='=>1, '=='=>1, '>'=>1, '<'=>1];

	$signs	= implode('|', array_map(fn($v)=> preg_quote($v, '/'), [...array_keys($operators), '(', ')', ',']));
	$tokens	= preg_split('/('.$signs.')/', str_replace(["\xc2\xa0", ' '], '', $formula), -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
	$output	= $stack = $counts = [];
	$pt		= '';

	foreach($tokens as $i => $t){
		$nt	= $tokens[$i+1] ?? '';

		if(is_numeric($t) || in_array($t[0], ['$', '@'], true)){
			if($pt === ')' || $nt === '('){
				$error	= '语法错误'; break;
			}

			if($t[0] === '$' && isset($fields) && !isset($fields[substr($t, 1)])){
				$error	= '「'.$t.'」未定义'; break;
			}

			$output[]	= $t;
		}elseif(isset($functions[$t])){
			if($pt === ')' || $nt !== '('){
				$error	= '语法错误'; break;
			}

			$stack[]	= $t;
			$counts[]	= 0;
		}elseif($t === '('){
			$stack[]	= $t;
		}elseif($t === ')' || $t === ','){
			while($stack && array_last($stack) !== '('){
				$output[] = array_pop($stack);
			}

			if(!$stack || ($t === ')' && $nt === '(') || ($t === ',' && ($pt === '(' || $nt === '' || $nt === ',' || $nt === ')'))){
				$error	= $t === ')' ? ($stack ? '语法错误' : '()不匹配') : '无效的「,」'; break;
			}

			if($t === ')'){
				array_pop($stack);

				if(isset($functions[array_last($stack)])){
					$output[]	= $count = array_pop($counts)+($pt === '(' ? 0 : 1);
					$output[]	= $func = array_pop($stack);

					if($count < abs($functions[$func])){
						$error	= '函数「'.$func.'」参数不足'; break;
					}
				}elseif($pt === '('){
					$error	= '空()'; break;
				}
			}else{
				$counts[count($counts)-1]++;
			}
		}elseif(isset($operators[$t])){
			$r	= (($pt === '' || $pt === '(' || $pt === ',' || isset($operators[$pt])) ? 1 : 0)
				+ (($nt === '' || $nt === ')' || $nt === ',') ? 2 : 0);
			
			if(($t === '+' || $t === '-') && $pt !== $t && $r === 1){
				$output[]	= 0;
			}else{
				if($r){
					$error	= '语法错误'; break;
				}

				while($stack && ($v = array_last($stack)) !== '('){
					if(isset($functions[$v]) || $operators[$t] < $operators[$v] || ($operators[$t] === $operators[$v] && $t !== '**')){
						$output[]	= array_pop($stack);
					}else{
						break;
					}
				}
			}

			$stack[] = $t;
		}else{
			$error	= '无效的「'.$t.'」'; break;
		}

		$pt	= $t;
	}

	$error ??= in_array('(', $stack, true) ? '()不匹配' : '';

	return $error ? wpjam_invalid('formula', ($prefix ?: $formula.'：').$error) : array_merge($output, array_reverse($stack));
}

function wpjam_format($value, $format='', ...$args){
	if(is_numeric($value)){
		if($format == ','){
			return number_format($value, (int)($args[0] ?? 2));
		}elseif($format == '%'){
			return round($value*100, ($args[0] ?? 2) ?: 2).'%';
		}else{
			return !$format && is_numeric($args[0] ?? '') ? round($value, $args[0]) : $value+0;
		}
	}

	if($format == '-'){
		if(is_string($value)){
			$v	= trim($value);
			$v	= ($p = $v && $v[-1] === '%') ? substr($v, 0, -1) : $v;
			$v	= str_replace(',', '', $v);

			if(is_numeric($v)){
				return $p ? $v/100 : $v+0;
			}
		}

		return $args ? $args[0] : $value;
	}	

	return $value;
}

// $a, $args
// $a, $b
// $a, $op, $b, $strict=false
function wpjam_compare($a, $op, ...$args){
	if(wpjam_is_assoc_array($op)){
		return wpjam_match($a, $op);
	}

	[$op, $b]	= is_array($op) || !$args ? ['', $op] : [strtoupper($op), array_shift($args)];

	$s	= (bool)($args[0] ?? false);

	if(($iv = ['!='=>'=', '!=='=>'===', '<='=>'>', '>='=>'<'][$op] ?? '') || try_prefix($op, '-', 'NOT ')){
		$r	= wpjam_compare($a, $iv ?: $op, $b, $s);

		return is_null($r) ? null : !$r;
	}

	if(in_array($op, ['LIKE', 'RLIKE', 'REGEXP'], true)){
		$a	= (string)$a;
		$b	= trim($b);

		if($op === 'LIKE'){
			if(strpbrk(($t = trim($b, '%')), '%_') === false){
				$a	= $s ? $a : strtolower($a);
				$t	= $s ? $t : strtolower($t);
				$n	= $b ? ($b[0] === '%' ? 2 : 0)+($b[-1] === '%' ? 1 : 0) : 0;

				return $n ? [1=>'str_starts_with', 2=>'str_ends_with', 3=>'str_contains'][$n]($a, $t) : $a === $t;
			}

			$pattern	= '^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($b, '#')).'$';
		}else{
			$pattern	= str_replace('#', '\#', $b);
		}

		return is_int($r = preg_match('#'.$pattern.'#'.($s ? '' : 'i'), $a)) ? (bool)$r : null;
	}elseif($op === 'IN' || $op === 'BETWEEN' || (!$op && is_array($b))){
		$b	= wp_parse_list($b);

		return $op === 'BETWEEN' ? ($a >= $b[0] && $a <= $b[1]) : array_all(is_array($a) ? $a : [$a], fn($v)=> in_array($v, $b, $s));
	}elseif($op === '=' || $op === '==' || !$op){
		return $a == $b;
	}elseif($op === '==='){
		return $a === $b;
	}elseif($op === '>'){
		return $a > $b;
	}elseif($op === '<'){
		return $a < $b;
	}
}

function wpjam_match($item, $args=[]){
	if(is_string($args)){
		$not	= str_starts_with($args, '!');
		$cb		= 'is_'.substr($args, (int)$not);

		return is_callable($cb) ? (bool)wpjam_call($cb, $item) !== $not : false;
	}

	if(!$args){
		return true;
	}

	$key	= wpjam_pull($args, 'key');
	$data	= wpjam_entry($item, '@value');
	$top	= $data !== $item && str_starts_with((string)$key, '@');

	if(!$top && (wpjam_is_assoc_array($data) || $data === [] || is_object($data)) !== isset($key)){
		return false;
	}
	
	$a	= isset($key) ? wpjam_entry($item, $key) : $item;

	if(($agg = wpjam_pull($args, 'aggregate')) && is_array($a)){
		$a	= $a ? (in_array($agg, ['avg', 'sum']) ? array_sum($a)/($agg == 'avg' ? count($a) : 1) : wpjam_call($agg, $a)) : 0;
	}

	$op	= wpjam_pull($args, 'compare');
	$b	= wpjam_pull($args, 'value');
	$b	??= is_null($op) && ($op = array_key_first(wpjam_except($args, ['if_null', 'is', 'strict']))) ? $args[$op] : $b;
	$b	= is_array($b) && count($b) == 1 && isset($b['$calc']) ? wpjam_calc($b['$calc'], $item, ['']) : $b;

	if(is_null($a)){
		$a	= $args['if_null'] ?? $a;

		if(is_bool($a)){
			return $a;
		}
	}

	if(isset($args['is'])){
		if(!wpjam_match($a, $args['is'])){
			return false;
		}elseif(is_null($op) && is_null($b)){
			return true;
		}
	}

	if(!$top){
		if(!is_scalar($a) && is_callable($a) && is_null($op)){
			return $a($b, $data);
		}

		if(isset($key) && is_array($a)){
			[$a, $b]	= [$b, $a];
		}
	}

	return wpjam_compare($a, $op ?: '', $b, $args['strict'] ?? false);
}

function wpjam_matches($item, $args, $op='all'){
	$op	= strtolower($op);

	if($t = in_array($op, ['any', 'or', 'none'], true) ? 'any' : (in_array($op, ['all', 'and', 'not'], true) ? 'all' : '')){
		$s	= is_array($s = wpjam_pull($args, '@search')) ? [$s['term'] ?? '', $s['columns'] ?? []] : [$s];
		$r	= ('array_'.$t)($args, fn($v, $k)=> wpjam_match($item, (wpjam_is_assoc_array($v) ? $v : ['value'=>$v])+['key'=>$k]));
		$r	= ($r === ($t === 'all') && !is_blank($s[0])) ? wpjam_contains($item, ...$s) : $r;

		return in_array($op, ['not', 'none']) ? !$r : $r;
	}
}

function wpjam_rule($rules, $item, $field=''){
	return array_find($rules, fn($r)=> is_array($r = $field ? ($r[$field] ?? null) : $r) && wpjam_matches($item, $r));
}

function wpjam_contains($item, $term, $columns=[]){
	$terms	= trim($term) === '' ? [] : wp_parse_list($term);
	$item	= wpjam_entry($item, '@value');

	return !$terms || array_any(
		!is_array($item) && !is_object($item) ? [$item] : ($columns ? wpjam_pick($item, $columns) : array_filter((array)$item, 'is_scalar')),
		fn($v) => array_any($terms, fn($t)=> stripos((string)$v, $t) !== false)
	);
}

// Array
function wpjam_is_assoc_array($arr){
	return is_array($arr) && !wp_is_numeric_array($arr);
}

function wpjam_array($arr=null, $cb=null, $args=[]){
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

	if($assoc = wpjam_is_assoc_array($cb)){
		$filter	= wpjam_pull($cb, '@filter');
		$vars	= wpjam_entry($args);
	}

	$data	= [];

	foreach($arr as $k => $v){
		if($assoc){
			$r	= wpjam_entry([$k, $v])+$vars;

			if($filter && !wpjam_matches($r, $filter)){
				continue;
			}

			['@key'=>$k, '@value'=>$v]	= wpjam_transform($r, $cb);
		}else{
			$r	= $cb($k, $v);

			if(is_scalar($r)){
				$k	= $r;
			}elseif(is_array($r) && count($r) >= 2 && !(is_null($r[1]) && (bool)$args)){
				[$k, $v]	= $r;
			}else{
				continue;
			}
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

function wpjam_column($items, $key=null, $index=null){
	return wpjam_array($items, fn($k, $v)=> [
		is_null($index) || $index === false ? null : ($index === true ? $k : $v[$index]),
		is_array($key) ? wpjam_array($key, fn($i, $j)=> [wpjam_is_assoc_array($key) ? $i : $j, wpjam_get($v, $j)], true) : wpjam_get($v, $key)
	]);
}

function wpjam_map($arr, $cb, $args=[]){
	if(!$arr || $cb === []){
		return $arr;
	}

	$args	= is_bool($args) ? ['deep'=>$args] : (is_string($args) ?  ['mode'=>$args] : $args);
	$mode	= $args['mode'] ?? '';
	$deep	= $args['deep'] ?? false;

	foreach($arr as $k => &$v){
		$a	= is_array($v);

		if($a && is_string($deep) && is_array($v[$deep] ?? '')){
			$v	= wpjam_set($v, $deep, wpjam_map($v[$deep], $cb, $args));
		}elseif($a && $deep === true){
			$v	= wpjam_map($v, $cb, $args);
		}else{
			$v	= $cb(...(['k'=>[$k], 'v'=>[$v], 'kv'=>[$k, $v]][$mode] ?? [$v, $k]));
		}
	}

	unset($v);

	return $arr;
}

function wpjam_each($arr, $cb, $mode=''){
	foreach($arr ?: [] as $k => $v){
		$cb(...(['k'=>[$k], 'v'=>[$v], 'kv'=>[$k, $v]][$mode] ?? [$v, $k]));
	}
}

function wpjam_reduce($arr, $cb, $init=null, $deep=false, $args=[]){
	$depth	= $args['depth'] ??= 0;
	$max	= $args['max_depth'] ?? 0;

	foreach(wpjam_array($arr) as $k => $v){
		$init	= $cb($init, $v, $k, $args);

		if(($deep === true || is_string($deep)) && (!$max || $max > $depth+1) && is_array($v)){
			$sub	= $deep === true ? $v : wpjam_get($v, $deep);
			$init	= is_array($sub) ? wpjam_reduce($sub, $cb, $init, $deep, ['depth'=>$depth+1]+$args) : $init;
		}
	}

	return $init;
}

function wpjam_nest($items, $options=[], $node=null, $depth=0){
	if(!$items){
		return [];
	}

	$fields	= array_filter($options['fields'] ?? [])+['id'=>'id', 'name'=>'name', 'parent'=>'parent', 'children'=>'children'];
	$items	= $node === null ? wpjam_group($items, $fields['parent']) : $items;
	$cb		= $options['item_callback'] ?? '';
	$max	= $options['max_depth'] ?? 0;
	$format	= $options['format'] ?? '';
	$parsed	= [];

	foreach($node ? ($items[$node] ?? []) : (array_filter([$options['top'] ?? '']) ?: ($items[0] ?? array_first($items))) as $item){
		if($item = $cb ? $cb($item) : $item){
			$children	= (!$max || $max > $depth+1) && ($id = wpjam_get($item, $fields['id'])) ? wpjam_nest($items, $options, $id, $depth+1) : [];
			$parsed[]	= wpjam_set($item, ...($format == 'flat'
				? [$fields['name'], str_repeat('&emsp;', $depth).wpjam_get($item, $fields['name'])]
				: [$fields['children'], $children]
			));

			if($format == 'flat' && $children){
				$parsed	= array_merge($parsed, $children);
			}
		}
	}

	return $parsed;
}

function wpjam_at($arr, ...$args){
	$arr	= is_string($arr) && $args ? explode(array_shift($args), $arr) : $arr;
	$arr	= is_array($arr) ? array_slice($arr, $args[0] ?? 0, 1, true) : [];

	return ($args[1] ?? '') === 'key' ? array_key_first($arr) : array_first($arr);
}

function wpjam_add_at($arr, $at, $data, ...$args){
	if($args && !is_array($data)){
		$data = is_null($data) ? $args : [$data=>$args[0]];
	}

	if(wpjam_is_assoc_array($data)){
		return array_replace(array_slice($arr, 0, $at, true), $data)+array_slice($arr, $at, null, true);
	}

	array_splice($arr, $at, 0, $data);

	return $arr;
}

function wpjam_rotate($arr, $step=1){
	if(($count = count($arr)) <= 1 || ($step %= $count) === 0){
		return $arr;
	}

	return [...array_slice($arr, $step), ...array_slice($arr, 0, $step)];
}

function wpjam_group($arr, $field, $preserve_keys=true){
	$cb	= is_closure($field);

	foreach($arr as $k => $v){
		if(!is_null($g = $cb ? $field($v, $k) : wpjam_get($v, $field))){
			if($preserve_keys){
				$res[$g][$k]	= $v;
			}else{
				$res[$g][]	= $v;
			}
		}
	}

	return $res ?? [];
}

function wpjam_pull(&$arr, $key, ...$args){
	$value	= (is_array($key) ? 'wpjam_pick' : 'wpjam_get')($arr, $key, ...$args);
	$arr	= wpjam_except($arr, $key);

	return $value;
}

function wpjam_assoc($arr){
	return (!wp_is_numeric_array($arr) && (!($sub = wpjam_filter($arr, fn($v, $k) => is_int($k))) || !array_is_list($sub)))
	? $arr
	: wpjam_array($arr, fn($k, $v)=> is_int($k) ? $v : $k);
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

	if(is_array($key)){
		return array_reduce($key, fn($c, $k)=> wpjam_except($c, $k), $arr);
	}

	if(array_key_exists($key, $arr)){
		unset($arr[$key]);
		return $arr;
	}

	if(str_ends_with($key, '[]') && $args){
		return wpjam_set($arr, substr($key, 0, -2), array_diff(wpjam_get($arr, $key, [], '[]'), $args), '[]');
	}

	$key	= wpjam_keys($key, ...$args);
	$sub	= &$arr;

	while($key){
		$k	= array_shift($key);

		if(!wpjam_exists($sub, $k)){
			break;
		}

		if($key){
			$sub = &$sub[$k];
		}else{
			unset($sub[$k]);
		}
	}

	return $arr;
}

function wpjam_union(...$arrays){
	return array_values(array_unique(array_merge(...$arrays)));
}

function wpjam_intersect($arr, $data){
	return array_values(array_intersect($arr, $data));
}

function wpjam_toggle($arr, $data){
	return array_merge(array_diff($arr, $data), array_diff($data, $arr));
}

function wpjam_diff($arr, $data){
	return array_values(array_diff($arr, $data));
}

function wpjam_merge($arr, $data){
	foreach($data as $k => $v){
		$arr[$k]	= wpjam_is_assoc_array($arr[$k] ?? null) && (wpjam_is_assoc_array($v) || $v === []) ? wpjam_merge($arr[$k], $v) : $v;
	}

	return $arr;
}

function wpjam_filter($arr, $cb=null, ...$args){
	if($arr === [] || $cb === []){
		return $arr;
	}

	if($cb && wp_is_numeric_array($cb) && !is_callable($cb)){
		return array_intersect_key($arr, array_flip($cb));
	}

	$list	= array_is_list($arr);

	if($cb === 'unique'){
		$arr	= array_unique(array_filter($arr));
	}elseif(is_bool($cb) || !$cb){
		$arr	= array_filter($cb ? array_map(fn($v)=> is_array($v) ? wpjam_filter($v, true) : $v, $arr) : $arr);
	}elseif(wpjam_is_assoc_array($cb)){
		foreach($arr as $k => $v){
			if(!wpjam_matches(wpjam_entry([$k, $v]), $cb, ...$args)){
				unset($arr[$k]);
			}
		}
	}else{
		$cb		= is_string($cb) && $cb[0] === '!' ? fn($v)=> wpjam_match($v, $cb) : $cb;
		$mode	= is_string($cb) && str_starts_with($cb, 'is_') ? 0 : 1;
	
		if($args[0] ?? false){
			foreach($arr as $k => $v){
				$as	= wpjam_is_assoc_array($v);

				if(is_array($v)){
					$arr[$k]	= $v = wpjam_filter($v, $cb, true);
				}

				if($as ? !$v : !$cb($v, ...($mode ? [$k] : []))){
					unset($arr[$k]);
				}
			}
		}else{
			$arr	= array_filter($arr, $cb, $mode);
		}
	}

	return $list ? array_values($arr) : $arr;
}

function wpjam_find($arr, $cb, ...$args){
	$output = in_array($args[0] ?? '', ['value', 'key', 'index'], true) ? array_shift($args) : null;
	$assoc	= wpjam_is_assoc_array($cb);

	if(!$assoc && !$output){
		[$filter, $cb]	= $args ? [$cb, $args[0]] : ['', $cb];
	}

	$i	= 0;

	foreach($arr as $k => $v){
		if($assoc || $output){
			if($assoc ? wpjam_matches(wpjam_entry([$k, $v]), $cb, ...$args) : $cb(...($output === 'key' ? [$k] : [$v, $k]))){
				return ['key'=>$k, 'index'=>$i][$output] ?? $v;
			}
		}else{
			$result	= $cb($v, $k);

			if($filter ? $filter($result) : !is_null($result)){
				return $result;
			}
		}

		$i++;
	}
}

function wpjam_sort($arr, ...$args){
	if(count($arr) <= 1){
		return $arr;
	}

	$t	= $args && !is_int($args[0]) ? array_shift($args) : '';

	if(in_array($t, ['', 'k', 'a', 'kr', 'ar', 'r'], true)){
		($t.'sort')($arr, ...$args);

		return $arr;
	}

	if((($args[0] ??= '') === 'key' || !is_scalar($t)) && is_callable($t)){
		$by		= $args[0] === 'key' ? array_shift($args) : '';
		$args	= [array_map($t, $by === 'key' ? array_keys($arr) : $arr), ...($args+['', null])];
	}else{
		$t		= is_string($t) ? [$t=>$args] : $t;

		if(!wpjam_is_assoc_array($t)){
			return $arr;
		}

		$t		= array_map(fn($v)=> (array)$v, $t);
		$args	= wpjam_reduce($t, fn($c, $v, $k)=>[
			...$c,
			array_values(array_map(fn($item)=> wpjam_get($item, $k, $v[1] ?? 0), $arr)),
			$v[0] ?? '',
			is_numeric($v[1] ?? '') ? SORT_NUMERIC : null
		], []);
	}

	array_push($args, range(count($arr), 1), '', null);

	foreach($args as $i => $v){
		if($i % 3 === 1){
			$args[$i]	= (is_int($v) ? $v === SORT_ASC : strtolower($v) === 'asc') ? SORT_ASC : SORT_DESC;
		}elseif($i % 3 === 2){
			$args[$i]	??= is_numeric(array_first($args[$i-2])) ? SORT_NUMERIC : SORT_REGULAR;
		}
	}

	$keys	= array_keys($arr);
	$args[]	= &$keys;
	$args[]	= &$arr;

	array_multisort(...$args);

	return array_combine($keys, $arr);
}

function wpjam_exists($arr, $key){
	if(!isset($key)){
		return false;
	}

	if(is_object($arr)){
		if(method_exists($arr, '__isset')){
			return isset($arr->$key);
		}

		$arr	= get_object_vars($arr);
	}

	if(is_array($arr)){
		return array_key_exists($key, $arr);
	}

	return false;
}

function wpjam_keys($key, $type=null){
	if(!$type){
		return wpjam_keys($key, '[]') ?: wpjam_keys($key, '.');
	}

	if($type == '.'){
		return $key && str_contains($key, '.') ? explode('.', $key) : [];
	}

	if($type == '[]'){
		$keys	= ($p = strpos($key ?? '', '[')) && $key[-1] == ']' ? explode('][', substr($key, 0, $p).']'.substr($key, $p, -1)) : [];

		return array_any($keys, fn($k)=> !$k || str_contains($k, '[') || str_contains($k, ']')) ? [] : $keys;
	}
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

	$type	= $args[0] ?? '';

	if(is_null($key) || ($type !== '.' && $key === '[]')){
		return $arr;
	}

	if(!is_array($key)){
		if($type !== '.' && str_ends_with($key, '[]')){
			$value	= wpjam_get($arr, substr($key, 0, -2), $default, '[]');

			return is_object($value) ? [$value] : (array)$value;
		}

		if(wpjam_exists($arr, $key)){
			return $arr[$key];
		}

		$key	= wpjam_keys($key, $type);
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

	$type	= $args[0] ?? '';

	if(is_null($key) || ($type !== '.' && $key === '[]')){
		$arr[] = $value;

		return $arr;
	}

	if(!is_array($key)){
		if($type !== '.' && str_ends_with($key, '[]')){
			$items		= wpjam_get($arr, $key, [], '[]');
			$items[]	= $value;

			return wpjam_set($arr, substr($key, 0, -2), $items, '[]');
		}

		if(wpjam_exists($arr, $key)){
			$arr[$key] = $value;

			return $arr;
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

function wpjam_move($arr, $id, $data){
	$arr	= array_values($arr);
	$index	= array_search($id, $arr);
	$arr	= wpjam_diff($arr, [$id]);

	$index === false && wpjam_invalid('id', '无效的 ID');

	if(isset($data['pos'])){
		$index	= $data['pos'];
	}elseif(!empty($data['up'])){
		$index	-= $index == 0 ? wpjam_invalid('position', '已经是第一个了，不可上移了！') : 1;
	}elseif(!empty($data['down'])){
		$index	+= $index == count($arr) ? wpjam_invalid('position', '已经最后一个了，不可下移了！') : 1;
	}else{
		$index	= ($k = array_find(['next', 'prev'], fn($k)=> isset($data[$k]))) ? array_search($data[$k], $arr) : false;
		$index	+= $index === false ? wpjam_invalid('position', '无效的移动位置') : ($k == 'prev' ? 1 : 0);
	}

	return wpjam_add_at($arr, $index, $id);
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
	return join($sep, array_filter($args && is_array($args[0]) ? $args[0] : $args));
}

function wpjam_remove_pre_tab($str, $times=1){
	return preg_replace('/^\t{'.$times.'}/m', '', $str);
}

function wpjam_preg_replace($pattern, $replace, $subject, $limit=-1, &$count=null, $flags=0){
	$result	= is_closure($replace)
	? preg_replace_callback($pattern, $replace, $subject, $limit, $count, $flags)
	: preg_replace($pattern, $replace, $subject, $limit, $count);

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
		$args	= ['allowed_classes'=>false];
		$result	= @unserialize($serialized, $args);

		if(!$result){
			$fixed	= preg_replace_callback('!s:(\d+):"(.*?)";!', fn($m)=> 's:'.strlen($m[2]).':"'.$m[2].'";', $serialized);
			$result	= @unserialize($fixed, $args);

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
	return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/u', '', (string)$text) ?? '';
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

// Cookie
function wpjam_cookie($key, ...$args){
	if(!$args){
		$value	= $_COOKIE[$key] ?? null;

		if($value && is_string($value) && (($value[0] == '{' && $value[-1] == '}') || ($value[0] == '[' && $value[-1] == ']'))){
			$value	= wpjam_if_error(wpjam_json_decode($value), '') ?: $value;
		}

		return $value;
	}

	$_COOKIE[$key]	= $args[0];

	$value	= is_array($args[0]) ? wpjam_json_encode($args[0]) : $args[0];
	$ttl	= is_null($args[0]) ? (time()-3600) : ($ttl = $args[1] ?? 86400)+($ttl && $ttl < time() ? time() : 0);

	wpjam_each(array_unique([COOKIEPATH, SITECOOKIEPATH]), fn($path)=> setcookie($key, $value, $ttl, $path, COOKIE_DOMAIN, is_ssl(), true));
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
	$from	= $from ? date_create($from, $zone) : '';

	if(!$from || !$to){
		return '';
	}

	$day	= [
		0	=> __('Today', 'wpjam'),
		-1	=> __('Yesterday', 'wpjam'),
		-2	=> __('The day before yesterday', 'wpjam'),
		1	=> __('Tomorrow', 'wpjam'),
		2	=> __('The day after tomorrow', 'wpjam')
	][(int)$to->diff($from)->format('%R%a')] ?? '';

	return $day ?: ($from->format('o-W') == $to->format('o-W') ? __($from->format('l'), 'wpjam') : $from->format(__('F, Y', 'wpjam')));
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
		return wpjam_transient('qqv_mp4:'.$vid, fn()=> wpjam_get_qqv_mp4($vid, false), 3600*6);
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
		return wpjam_find(['page', 'cover/.*'], fn($v)=> preg_match('#https://v.qq.com/x/'.$v.'/(.*?).html#i', $id_or_url, $m) ? $m[1] : null);
	}

	return $id_or_url;
}

function wpjam_is_mobile_number($number){
	return preg_match('/^0{0,1}(1[3,5,8][0-9]|14[5,7]|166|17[0,1,3,6,7,8]|19[8,9])[0-9]{8}$/', $number);
}
