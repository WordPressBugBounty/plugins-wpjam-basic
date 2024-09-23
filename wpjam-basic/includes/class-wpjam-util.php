<?php
class WPJAM_Var extends WPJAM_Args{
	private function __construct(){
		$this->args	= self::parse_user_agent();
	}

	public function supports($feature){
		if($feature == 'webp'){
			return $this->browser == 'chrome' || $this->os == 'Android' || ($this->os == 'iOS' && version_compare($this->os_version, 14) >= 0);
		}
	}

	public static function parse_user_agent($user_agent=null, $referer=null){
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
		], fn($rule) => stripos($user_agent, $rule[0]));

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

					if(strpos($matches[2],';')!==false){
						$device	= substr($matches[2], strpos($matches[2],';')+1, strlen($matches[2])-strpos($matches[2],';'));
					}else{
						$device	= $matches[2];
					}

					$device	= trim($device);
					// $build	= trim($matches[3]);
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

	public static function parse_ip($ip=null){
		$ip	= $ip ?: wpjam_get_ip();

		if($ip == 'unknown'){
			return false;
		}

		$data	= [
			'ip'		=> $ip,
			'country'	=> '',
			'region'	=> '',
			'city'		=> '',
		];

		if(file_exists(WP_CONTENT_DIR.'/uploads/17monipdb.dat')){
			$ipdata	= IP::find($ip);

			return array_merge($data, [
				'country'	=> $ipdata['0'] ?? '',
				'region'	=> $ipdata['1'] ?? '',
				'city'		=> $ipdata['2'] ?? '',
			]);
		}

		return $data;
	}

	public static function get_instance(){
		static $object;

		return $object	??= new static();
	}

	public static function get_current_user($required=false){
		$object	= self::get_instance();

		if(isset($object->user)){
			$value	= $object->user;
		}else{
			$value	= apply_filters('wpjam_current_user', null);

			if(!is_null($value) && !is_wp_error($value)){
				$object->user	= $value;
			}
		}

		if($required){
			return is_null($value) ? new WP_Error('bad_authentication') : $value;
		}else{
			return is_wp_error($value) ? null : $value;
		}
	}
}

class WPJAM_JWT{
	protected static function hash($jwt, $key=''){
		return base64_urlencode(hash_hmac('sha256', $jwt, ($key ?: wp_salt()), true));
	}

	public static function generate($payload, $key='', $header=[]){
		if(is_array($payload)){
			$header	+= ['alg'=>'HS256', 'typ'=>'JWT'];

			if($header['alg'] == 'HS256'){
				$encode	= fn($data)=> base64_urlencode(wpjam_json_encode($data));
				$jwt	= $encode($header).'.'.$encode($payload);

				return $jwt.'.'.self::hash($jwt, $key);
			}
		}

		return false;
	}

	public static function verify($token, $key=''){
		$tokens	= $token ? explode('.', $token) : [];

		if(count($tokens) != 3 || !hash_equals(self::hash($tokens[0].'.'.$tokens[1], $key), $tokens[2])){
			return false;
		}

		$decode		= fn($str)=> wpjam_json_decode(base64_urldecode($str));
		$header		= $decode($tokens[0]);
		$payload	= $decode($tokens[1]);

		if(empty($header['alg']) || $header['alg'] != 'HS256'){
			return false;
		}

		//iat 签发时间不能大于当前时间
		//nbf 时间之前不接收处理该Token
		//exp 过期时间不能小于当前时间
		if(wpjam_some(['iat'=>'>', 'nbf'=>'>', 'exp'=>'<'], fn($v, $k)=> isset($payload[$k]) && wpjam_compare($payload[$k], $v, time()))){
			return false;
		}

		return $payload;
	}

	public static function get($param='access_token', $required=false){
		$headers	= getallheaders();

		if(isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer')){
			return trim(wpjam_remove_prefix($headers['Authorization'], 'Bearer'));
		}

		return wpjam_get_parameter($param, ['required'=>$required]);
	}
}

class WPJAM_Crypt extends WPJAM_Args{
	public function __construct(...$args){
		if($args && is_string($args[0])){
			$key	= $args[0];
			$args	= $args[1] ?? [];
			$args	= array_merge($args, ['key'=>$key]);
		}else{
			$args	= $args[0] ?? [];
		}

		$this->args	= $args+[
			'method'	=> 'aes-256-cbc',
			'key'		=> '',
			'iv'		=> '',
			'options'	=> OPENSSL_ZERO_PADDING,	
		];
	}

	public function encrypt($text){
		if($this->pad == 'weixin' && $this->appid){
			$text 	= $this->pad($text, 'weixin', $this->appid);
		}

		if($this->options == OPENSSL_ZERO_PADDING && $this->block_size){
			$text	= $this->pad($text, 'pkcs7', $this->block_size);
		}

		return openssl_encrypt($text, $this->method, $this->key, $this->options, $this->iv);
	}

	public function decrypt($text){
		$text	= openssl_decrypt($text, $this->method, $this->key, $this->options, $this->iv);

		if($this->options == OPENSSL_ZERO_PADDING && $this->block_size){
			$text	= $this->unpad($text, 'pkcs7', $this->block_size);
		}

		if($this->pad == 'weixin' && $this->appid){
			$text 	= $this->unpad($text, 'weixin', $this->appid);
		}

		return $text;
	}

	public static function pad($text, $method, ...$args){
		if($method == 'pkcs7'){
			$pad	= $args[0] - (strlen($text) % $args[0]);

			return $text.str_repeat(chr($pad), $pad);
		}elseif($method == 'weixin'){
			return wp_generate_password(16, false).pack("N", strlen($text)).$text.$args[0];
		}

		return $text;
	}

	public static function unpad($text, $method, ...$args){
		if($method == 'pkcs7'){
			$pad	= ord(substr($text, -1));

			if($pad < 1 || $pad > $args[0]){
				$pad	= 0;
			}

			return substr($text, 0, -1 * $pad);
		}elseif($method == 'weixin'){
			$text	= substr($text, 16);
			$length	= (unpack("N", substr($text, 0, 4)))[1];

			if(substr($text, $length + 4) != $args[0]){
				return new WP_Error('invalid_appid', 'Appid 校验错误');
			}

			return substr($text, 4, $length);
		}

		return $text;
	}

	public static function generate_signature(...$args){
		return sha1(implode(wpjam_sort($args, SORT_STRING)));
	}

	public static function weixin_pad($text, $appid){
		return self::pad($text, 'weixin', $appid);
	}

	public static function weixin_unpad($text, &$appid){
		$text		= substr($text, 16, strlen($text));
		$len_list	= unpack("N", substr($text, 0, 4));
		$text_len	= $len_list[1];
		$appid		= substr($text, $text_len + 4);

		return substr($text, 4, $text_len);
	}

	public static function generate_weixin_signature($token, &$ts='', &$nonce='', $encrypt_msg=''){
		$ts		= $ts ?: time();
		$nonce	= $nonce ?: wp_generate_password(8, false);

		return self::generate_signature($token, $ts, $nonce, $encrypt_msg);
	}
}

class WPJAM_Updater extends WPJAM_Args{
	public function get_data($file){	// https://api.wordpress.org/plugins/update-check/1.1/
		$response	= wpjam_transient('update_'.$this->plural.':'.$this->hostname, fn()=> wpjam_remote_request($this->url), MINUTE_IN_SECONDS);

		if(is_wp_error($response)){
			return false;
		}

		$response	= $response['template']['table'] ?? $response[$this->plural];

		if(isset($response['fields']) && isset($response['content'])){
			$fields	= array_column($response['fields'], 'index', 'title');
			$index	= $fields[$this->label];

			foreach($response['content'] as $item){
				if($item['i'.$index] == $file){
					$data	= [];

					foreach($fields as $name => $index){
						$data[$name]	= $item['i'.$index] ?? '';
					}

					return [
						$this->type		=> $file,
						'url'			=> $data['更新地址'],
						'package'		=> $data['下载地址'],
						'icons'			=> [],
						'banners'		=> [],
						'banners_rtl'	=> [],
						'new_version'	=> $data['版本'],
						'requires_php'	=> $data['PHP最低版本'],
						'requires'		=> $data['最低要求版本'],
						'tested'		=> $data['最新测试版本'],
					];
				}
			}
		}else{
			return $response[$file] ?? [];
		}
	}

	public function filter_update($update, $data, $file, $locales){
		$new_data	= $this->get_data($file);

		return $new_data ? $new_data+['id'=>$data['UpdateURI'], 'version'=>$data['Version']] : $update;
	}

	public function filter_pre_set_site_transient($updates){
		if(isset($updates->no_update) || isset($updates->response)){
			$file	= 'wpjam-basic/wpjam-basic.php';
			$update	= $this->get_data($file);

			if($update){
				$plugin	= get_plugin_data(WP_PLUGIN_DIR.'/'.$file);
				$key 	= version_compare($update['new_version'], $plugin['Version'], '>') ? 'response' : 'no_update';

				$updates->$key[$file]	= (object)(isset($updates->$key[$file]) ? array_merge((array)$updates->$key[$file], $update) : $update);
			}
		}

		return $updates;
	}

	public static function create($type, $hostname, $url){
		if(in_array($type, ['plugin', 'theme'])){
			$object	= new self([
				'type'		=> $type,
				'plural'	=> $type.'s',
				'label'		=> $type == 'plugin' ? '插件' : '主题',
				'hostname'	=> $hostname,
				'url'		=> $url
			]);

			add_filter('update_'.$type.'s_'.$hostname, [$object, 'filter_update'], 10, 4);

			if($type == 'plugin' && $hostname == 'blog.wpjam.com'){
				add_filter('pre_set_site_transient_update_plugins', [$object, 'filter_pre_set_site_transient']);
			}
		}
	}
}

class WPJAM_Cache extends WPJAM_Args{
	public function __call($method, $args){
		$method	= wpjam_remove_prefix($method, 'cache_');
		$gnd	= str_contains($method, 'get') || str_contains($method, 'delete');
		$key	= array_shift($args);

		if(str_contains($method, '_multiple')){
			if($gnd){
				$cb[]	= $keys = array_map([$this, 'key'], $key);
			}else{
				$cb[]	= wpjam_array($key, fn($k)=> $this->key($k));
			}
		}else{
			$cb[]	= $this->key($key);

			if(!$gnd){
				$cb[]	= array_shift($args);
			}
		}

		$cb[]	= $this->group;

		if(!$gnd){
			$cb[]	= $this->time(array_shift($args));
		}

		$callback	= 'wp_cache_'.$method;
		$result		= $callback(...$cb);

		if($result && $method == 'get_multiple'){
			$result	= wpjam_array($key, fn($i, $k) => [$k, $result[$keys[$i]]]);
			$result	= array_filter($result, fn($v) => $v !== false);
		}

		return $result;
	}

	protected function key($key){
		return wpjam_join(':', $this->prefix, $key);
	}

	protected function time($time){
		return (int)($time) ?: ($this->time ?: DAY_IN_SECONDS);
	}

	public function cas($token, $key, $value, $time=0){
		return wp_cache_cas($token, $this->key($key), $value, $this->group, $this->time($time));
	}

	public function get_with_cas($key, &$token, $default=null){
		[$object, $token]	= is_object($token) ? [$token, null] : [null, $token];

		$key	= $this->key($key);
		$result	= wp_cache_get_with_cas($key, $this->group, $token);

		if($result === false && isset($default)){
			$this->set($key, $default);

			$result	= wp_cache_get_with_cas($key, $this->group, $token);
		}

		if($object){
			$object->cas_token	= $token;
		}

		return $result;
	}

	public function generate($key){
		try{
			$this->is_exceeded($key);

			if($this->interval && $this->get($key.':time') !== false){
				wpjam_throw('error', '验证码'.((int)($this->interval/60)).'分钟前已发送了。');
			}

			$code = rand(100000, 999999);

			$this->set($key.':code', $code, $this->cache_time);

			if($this->interval){
				$this->set($key.':time', time(), MINUTE_IN_SECONDS);
			}

			return $code;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	public function verify($key, $code){
		try{
			$this->is_exceeded($key);

			$cached	= $code ? $this->get($key.':code') : false;

			if($cached === false){
				wpjam_throw('invalid_code');
			}elseif($cached != $code){
				if($this->failed_times){
					$this->set($key.':failed_times', ($this->get($key.':failed_times') ?: 0)+1, $this->cache_time/2);
				}

				wpjam_throw('invalid_code');
			}
		
			return true;
		}catch(Exception $e){
			return wpjam_catch($e);
		}
	}

	protected function is_exceeded($key){
		if($this->failed_times && (int)$this->get($key.':failed_times') > $this->failed_times){
			wpjam_throw('failed_times_exceeded', ['尝试的失败次数', '请15分钟后重试。']);
		}
	}

	public static function get_verification($args){
		[$name, $args]	= is_array($args) ? [wpjam_pull($args, 'group'), $args] : [$args, []];

		return self::get_instance([
			'group'		=> 'verification_code',
			'prefix'	=> $name ?: 'default',
			'global'	=> true,
		]+$args+[
			'failed_times'	=> 5,
			'cache_time'	=> MINUTE_IN_SECONDS*30,
			'interval'		=> MINUTE_IN_SECONDS
		]);
	}

	public static function get_instance($group, $args=[]){
		if(is_array($group)){
			$args	= $group;
			$group	= $args['group'] ?? '';
		}

		if($group){
			$args	= array_merge($args, ['group'=>$group]);
			$name	= wpjam_join(':', $group, ($args['prefix'] ?? ''));

			return wpjam_get_instance('cache', $name, fn()=> self::create($args));
		}
	}

	public static function create($args=[]){
		if(!empty($args['group'])){
			if(wpjam_pull($args, 'global')){
				wp_cache_add_global_groups($args['group']);
			}

			return new self($args);
		}
	}
}

class WPJAM_Error extends WPJAM_Model{
	public static function get_handler(){
		return wpjam_get_handler('wpjam_errors', [
			'option_name'	=> 'wpjam_errors',
			'primary_key'	=> 'errcode',
			'primary_title'	=> '代码',
		]);
	}

	public static function filter($data){
		$error	= self::get($data['errcode']);

		if($error){
			$data['errmsg']	= $error['errmsg'];

			if(!empty($error['show_modal'])){
				if(!empty($error['modal']['title']) && !empty($error['modal']['content'])){
					$data['modal']	= $error['modal'];
				}
			}
		}else{
			if(empty($data['errmsg'])){
				$item	= self::get_setting($data['errcode']);

				if($item){
					if($item['message']){
						$data['errmsg']	= $item['message'];
					}

					if($item['modal']){
						$data['modal']	= $item['modal'];
					}
				}
			}
		}

		return $data;
	}

	public static function parse($data){
		if(is_wp_error($data)){
			$errdata	= $data->get_error_data();
			$data		= [
				'errcode'	=> $data->get_error_code(),
				'errmsg'	=> $data->get_error_message(),
			];

			if($errdata){
				$errdata	= is_array($errdata) ? $errdata : ['errdata'=>$errdata];
				$data 		= $data + $errdata;
			}
		}else{
			if($data === true){
				return ['errcode'=>0];
			}elseif($data === false || is_null($data)){
				return ['errcode'=>'-1', 'errmsg'=>'系统数据错误或者回调函数返回错误'];
			}elseif(is_array($data)){
				if(!$data || !wp_is_numeric_array($data)){
					$data	+= ['errcode'=>0];
				}
			}
		}

		return empty($data['errcode']) ? $data : self::filter($data);
	}

	public static function if($data, $err=[]){
		$err	+= [
			'errcode'	=> 'errcode',
			'errmsg'	=> 'errmsg',
			'detail'	=> 'detail',
			'success'	=> '0',
		];

		$code	= wpjam_pull($data, $err['errcode']);

		if($code && $code != $err['success']){
			$msg	= wpjam_pull($data, $err['errmsg']);
			$detail	= wpjam_pull($data, $err['detail']);
			$detail	= is_null($detail) ? array_filter($data) : $detail;

			return new WP_Error($code, $msg, $detail);
		}

		return $data;
	}

	public static function convert($message, $title='', $args=[]){
		if(is_wp_error($message)){
			return $message;
		}

		$code	= is_scalar($args) ? $args : '';

		if($code){
			$detail	= $title ? ['modal'=>['title'=>$title, 'content'=>$message]] : [];

			return new WP_Error($code, $message, $detail);
		}

		if($title){
			$code	= $title;
		}else{
			$code	= 'error';

			if(is_scalar($message)){
				if(self::get_setting($message)){
					[$code, $message]	= [$message, ''];
				}else{
					$parsed	= self::parse_message($message);

					if($parsed){
						[$code, $message]	= [$message, $parsed];
					}
				}
			}
		}

		return new WP_Error($code, $message);
	}

	public static function parse_message($code, $message=[]){
		$fn	= fn($map, $key)=> $map[$key] ?? ucwords($key);

		if(str_starts_with($code, 'invalid_')){
			$key	= wpjam_remove_prefix($code, 'invalid_');

			if($key == 'parameter'){
				return $message ? '无效的参数：'.$message[0].'。' : '参数错误。';
			}elseif($key == 'callback'){
				return '无效的回调函数'.($message ? '：'.$message[0] : '').'。';
			}elseif($key == 'name'){
				return $message ? $message[0].'不能为纯数字。' : '无效的名称';
			}else{
				return [
					'nonce'		=> '验证失败，请刷新重试。',
					'code'		=> '验证码错误。',
					'password'	=> '两次输入的密码不一致。'
				][$key] ?? '无效的'.$fn([
					'id'			=> ' ID',
					'post_type'		=> '文章类型',
					'taxonomy'		=> '分类模式',
					'post'			=> '文章',
					'term'			=> '分类',
					'user'			=> '用户',
					'comment_type'	=> '评论类型',
					'comment_id'	=> '评论 ID',
					'comment'		=> '评论',
					'type'			=> '类型',
					'signup_type'	=> '登录方式',
					'email'			=> '邮箱地址',
					'data_type'		=> '数据类型',
					'qrcode'		=> '二维码',
				], $key);
			}
		}elseif(str_starts_with($code, 'illegal_')){
			$key	= wpjam_remove_prefix($code, 'illegal_');

			return $fn([
				'access_token'	=> 'Access Token ',
				'refresh_token'	=> 'Refresh Token ',
				'verify_code'	=> '验证码',
			], $key).'无效或已过期。';
		}elseif(str_ends_with($code, '_required')){
			$key	= wpjam_remove_postfix($code, '_required');
			$format	= $key == 'parameter' ? '参数%s' : '%s的值';

			return $message ? sprintf($format.'为空或无效。', ...$message) : '参数或者值无效';
		}elseif(str_ends_with($code, '_occupied')){
			$key	= wpjam_remove_postfix($code, '_occupied');

			return $fn([
				'phone'		=> '手机号码',
				'email'		=> '邮箱地址',
				'nickname'	=> '昵称',
			], $key).'已被其他账号使用。';
		}

		return '';
	}

	public static function get_setting($code){
		return wpjam_get_item('error', $code);
	}

	public static function add_setting($code, $message, $modal=[]){
		if(!wpjam_get_items('error')){
			add_action('wp_error_added', [self::class, 'on_wp_error_added'], 10, 4);
		}

		if($message){
			wpjam_add_item('error', $code, ['message'=>$message, 'modal'=>$modal]);
		}
	}

	public static function on_wp_error_added($code, $message, $data, $wp_error){
		if($code && (!$message || is_array($message)) && count($wp_error->get_error_messages($code)) <= 1){
			if(is_array($code)){
				trigger_error(var_export($code, true));
			}
			
			$item	= self::get_setting($code);

			if($item){
				if($item['modal']){
					$data	= is_array($data) ? $data : [];
					$data	= array_merge($data, ['modal'=>$item['modal']]);
				}

				if(is_callable($item['message'])){
					$parsed	= $item['message']($message, $code);
				}else{
					$parsed	= is_array($message) ? sprintf($item['message'], ...$message) : $item['message'];
				}
			}else{
				$parsed	= self::parse_message($code, $message);
			}

			$wp_error->remove($code);
			$wp_error->add($code, ($parsed ?: $code), $data);
		}
	}
}

class WPJAM_Exception extends Exception{
	private $errcode	= '';

	public function __construct($errmsg, $errcode=null, Throwable $previous=null){
		if(is_array($errmsg)){
			$errmsg	= new WP_Error($errcode, $errmsg);
		}

		if(is_wp_error($errmsg)){
			$errcode	= $errmsg->get_error_code();
			$errmsg		= $errmsg->get_error_message();
		}else{
			$errcode	= $errcode ?: 'error';
		}

		$this->errcode	= $errcode;

		parent::__construct($errmsg, (is_numeric($errcode) ? (int)$errcode : 1), $previous);
	}

	public function get_error_code(){
		return $this->errcode;
	}

	public function get_error_message(){
		return $this->getMessage();
	}

	public function get_wp_error(){
		return new WP_Error($this->errcode, $this->getMessage());
	}
}

class WPJAM_Asset{
	public static function do($type, $handle, $args){
		$method	= wpjam_pull($args, 'method') ?: 'enqueue';
		$if		= wpjam_pull($args, $method.'_if');

		if($if && !$if($handle, $type)){
			return;
		}

		$src	= wpjam_pull($args, 'src');
		$src	= is_closure($src) ? $src($handle) : $src;
		$deps	= wpjam_pull($args, 'deps');
		$ver	= wpjam_pull($args, 'ver');
		$data	= wpjam_pull($args, 'data');

		if($type == 'script'){
			$pos	= wpjam_pull($args, 'position') ?: 'after';
		}else{
			$pos	= null;
			$args	= wpjam_pull($args, 'media') ?: 'all';
		}

		if($src || !$data){
			call_user_func('wp_'.$method.'_'.$type, $handle, $src, $deps, $ver, $args);
		}

		if($data){
			call_user_func('wp_add_inline_'.$type, $handle, $data, $pos);
		}
	}

	public static function handle($type, $handle, $args){
		$args		= is_array($args) ? $args : ['src'=>$args];
		$postfix	= '_enqueue_scripts';

		if(wpjam_some(['wp', 'admin', 'login'], fn($part)=> doing_action($part.$postfix))){
			self::do($type, $handle, $args);
		}else{
			$for		= wpjam_pull($args, 'for');
			$for		= is_null($for) ? ['admin', 'login', 'wp'] : ($for ? wp_parse_list($for) : ['wp']);
			$parts		= is_admin() ? ['admin', 'wp'] : (is_login() ? ['login'] : ['wp']);
			$parts		= array_intersect($parts, $for);
			$priority	= wpjam_pull($args, 'priority') ?? 10;

			array_walk($parts, fn($part)=> wpjam_load($part.$postfix, fn()=> self::do($type, $handle, $args), $priority));
		}
	}

	public static function add_static_cdn($host){
		if(!wpjam_get_items('static_cdn')){
			add_filter('wp_resource_hints',	[self::class, 'filter_resource_hints'], 10, 2);

			add_filter('style_loader_src',	[self::class, 'filter_loader_src']);
			add_filter('script_loader_src',	[self::class, 'filter_loader_src']);

			add_filter('current_theme_supports-style',	[self::class, 'filter_current_theme_supports'], 10, 3);
			add_filter('current_theme_supports-script',	[self::class, 'filter_current_theme_supports'], 10, 3);
		}

		wpjam_add_item('static_cdn', $host);
	}

	public static function get_static_cdn(){
		$hosts	= wpjam_get_items('static_cdn');

		return apply_filters('wpjam_static_cdn_host', $hosts[0], $hosts);
	}

	protected static function get_static_search(){
		$search	= array_diff(wpjam_get_items('static_cdn'), [self::get_static_cdn()]);

		return [...$search, 'https://cdn.staticfile.net', 'https://cdn.staticfile.org', 'https://cdn.bootcdn.net/ajax/libs'];
	}

	public static function filter_loader_src($src){
		if($src){
			$cdn	= self::get_static_cdn();

			if(!str_starts_with($src, $cdn)){
				return str_replace(self::get_static_search(), $cdn, $src);	
			}
		}

		return $src;
	}

	public static function filter_resource_hints($urls, $type){
		if($type == 'dns-prefetch'){
			$search	= self::get_static_search();
			$urls	= array_diff($urls, wpjam_map($search, fn($host)=> parse_url($host, PHP_URL_HOST)));
			$urls[]	= self::get_static_cdn();
		}

		return $urls;
	}

	public static function filter_current_theme_supports($check, $args, $value){
		return !array_diff($args, (is_array($value[0]) ? $value[0] : $value));
	}
}

class WPJAM_Callback{
	public static function call_method($class, $method, ...$args){
		$parsed	= self::parse_method($class, $method, $args);

		return is_wp_error($parsed) ? $parsed : $parsed(...$args);
	}

	public static function parse_method($class, $method, &$args=[], $number=1){
		if(is_object($class)){
			$object	= $class;
			$class	= get_class($class);
		}else{
			if(!class_exists($class)){
				return new WP_Error('invalid_model', [$class]);
			}
		}

		if(!method_exists($class, $method)){
			if(method_exists($class, '__callStatic')){
				$is_public = true;
				$is_static = true;
			}elseif(method_exists($class, '__call')){
				$is_public = true;
				$is_static = false;
			}else{
				return new WP_Error('undefined_method', [$class.'->'.$method.'()']);
			}
		}else{
			$reflection	= self::get_reflection([$class, $method]);
			$is_public	= $reflection->isPublic();
			$is_static	= $reflection->isStatic();
		}

		if($is_static){
			return $is_public ? [$class, $method] : $reflection->getClosure();
		}else{
			$object	??= self::get_instance($class, $args, $number);

			if(is_wp_error($object)){
				return $object;
			}

			return $is_public ? [$object, $method] : $reflection->getClosure($object);
		}
	}

	public static function get_instance($class, &$args, $number=1){
		if(!method_exists($class, 'get_instance')){
			return new WP_Error('undefined_method', [$class.'->get_instance()']);
		}

		for($i=0; $i < $number; $i++){ 
			$params[]	= $param = array_shift($args);

			if(is_null($param)){
				return new WP_Error('instance_required', '实例方法对象才能调用');
			}
		}

		return $class::get_instance(...$params) ?: new WP_Error('invalid_id', [$class]);
	}

	public static function get_parameters($callback){
		return (self::get_reflection($callback))->getParameters();
	}

	public static function verify($callback, $verify){
		$reflection	= self::get_reflection($callback);

		return $verify($reflection->getParameters(), $reflection);
	}

	public static function get_reflection($callback){
		$id	= self::build_unique_id($callback);

		return wpjam_get_instance('reflection', $id, fn()=> is_array($callback) ? new ReflectionMethod(...$callback) : new ReflectionFunction($callback));
	}

	public static function build_unique_id($callback){
		return _wp_filter_build_unique_id(null, $callback, null);
	}
}

class WPJAM_Parameter{
	public static function get_value($name, $args=[], $method=''){
		if(is_array($name)){
			if(!$name){
				return [];
			}

			if(wp_is_numeric_array($name)){
				return wpjam_fill($name, fn($n)=> self::get_value($n, $args, $method));
			}else{
				return wpjam_map($name, fn($v, $n)=> self::get_value($n, $v, $method));
			}
		}

		$method	= $method ?: (array_get($args, 'method') ?: 'GET');
		$method	= strtoupper($method);
		$value	= self::get_by_name($name, $method);

		if($name){
			if(is_null($value) && !empty($args['fallback'])){
				$value	= self::get_by_name($args['fallback'], $method);
			}

			if(is_null($value)){
				if(isset($args['default'])){
					$value		= $args['default'];
				}else{
					$defaults	= wpjam_var('defaults') ?: [];
					$value		= $defaults[$name] ?? null;
				}
			}

			$args	= wpjam_except($args, ['method', 'fallback', 'default']);

			if($args){
				$args['key']	= $name;
				$args['type']	??= '';

				if($args['type'] == 'int'){	// 兼容
					$args['type']	= 'number';
				}

				$field	= wpjam_field($args);

				if(!$args['type']){
					$field->set_schema(false);
				}

				$value	= wpjam_catch([$field, 'validate'], $value, 'parameter');

				if(is_wp_error($value) && array_get($args, 'send') !== false){
					wpjam_send_json($value);
				}
			}
		}

		return $value;
	}

	private static function get_by_name($name, $method){
		if($method == 'DATA'){
			if($name && isset($_GET[$name])){
				return wp_unslash($_GET[$name]);
			}

			$data	= self::get_data();
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

			$data	= self::get_input();
		}

		return $name ? ($data[$name] ?? null) : $data;
	}

	private static function get_input(){
		static $_input;

		if(!isset($_input)){
			$input	= file_get_contents('php://input');
			$input	= is_string($input) ? @wpjam_json_decode($input) : $input;
			$_input	= is_array($input) ? $input : [];
		}

		return $_input;
	}

	private static function get_data(){
		static $_data;

		if(!isset($_data)){
			$_data	= [];

			foreach(['defaults', 'data'] as $key){
				$data	= self::get_by_name($key, 'REQUEST') ?? [];
				$data	= ($data && is_string($data) && str_starts_with($data, '{')) ? wpjam_json_decode($data) : wp_parse_args($data);

				$_data	= wpjam_merge($_data, $data);
			}
		}

		return $_data;
	}

	public static function method_allow($method){
		$m	= $_SERVER['REQUEST_METHOD'];

		return $m != strtoupper($method) ? wp_die('method_not_allow', '接口不支持 '.$m.' 方法，请使用 '.$method.' 方法！') : true;
	}
}

class WPJAM_Http{
	public static function request($url, $args=[], $err=[]){
		$args	+= ['body'=>[], 'headers'=>[], 'sslverify'=>false, 'stream'=>false];
		$method	= strtoupper(wpjam_pull($args, 'method', '')) ?: ($args['body'] ? 'POST' : 'GET');

		if($method == 'GET'){
			$response	= wp_remote_get($url, $args);
		}elseif($method == 'FILE'){
			$response	= (new WP_Http_Curl())->request($url, $args+[
				'method'			=> $args['body'] ? 'POST' : 'GET',
				'sslcertificates'	=> ABSPATH.WPINC.'/certificates/ca-bundle.crt',
				'user-agent'		=> 'WordPress',
				'decompress'		=> true,
			]);
		}else{
			$response	= wp_remote_request($url, self::encode($args)+['method'=>$method]);
		}

		$args['url']	= $url;

		if(is_wp_error($response)){
			return self::log($response, $args);
		}

		$body	= &$response['body'];

		if($body && !$args['stream']){
			if(str_contains(wp_remote_retrieve_header($response, 'content-disposition'), 'attachment;')){
				$body	= wpjam_bits($body);
			}else{
				$body	= self::decode($body, $args, $err);

				if(is_wp_error($body)){
					return $body;
				}
			}
		}

		$code	= $response['response']['code'] ?? 0;

		if($code && ($code < 200 || $code >= 300)){
			return new WP_Error($code, '远程服务器错误：'.$code.' - '.$response['response']['message']);
		}

		return $response;
	}

	private static function encode($args){
		$content_type	= $args['headers']['Content-Type'] ?? ($args['headers']['content-type'] ?? '');

		if(str_contains($content_type, 'application/json')){
			$encode = true;
		}else{
			$encode	= wpjam_pull($args, 'json_encode_required', wpjam_pull($args, 'need_json_encode'));
		}

		if($encode){
			if(is_array($args['body'])){
				$args['body']	= wpjam_json_encode($args['body'] ?: new stdClass);
			}

			if(empty($args['headers']['Content-Type']) && empty($args['headers']['content-type'])){
				$args['headers']['Content-Type']	= 'application/json';
			}
		}

		return $args;
	}

	private static function decode($body, $args=[], $err=[]){
		if(wpjam_pull($args, 'json_decode') !== false && str_starts_with($body, '{') && str_ends_with($body, '}')){
			$decoded	= wpjam_json_decode($body);

			if(!is_wp_error($decoded)){
				$body	= WPJAM_Error::if($decoded, $err);

				if(is_wp_error($body)){
					self::log($body, $args);
				}
			}
		}

		return $body;
	}

	private static function log($error, $args=[]){
		$code	= $error->get_error_code();
		$msg	= $error->get_error_message();

		if(apply_filters('wpjam_http_response_error_debug', true, $code, $msg)){
			$detail	= $error->get_error_data();
			$detail	= $detail ? var_export($detail, true)."\n" : '';

			trigger_error($args['url']."\n".$code.' : '.$msg."\n".$detail.var_export($args['body'], true));
		}

		return $error;
	}
}

class WPJAM_File{
	public static function upload($name){
		require_once ABSPATH.'wp-admin/includes/file.php';

		if(is_array($name)){
			if(isset($name['bits'])){
				$upload	= wp_upload_bits(($name['name'] ?? ''), null, $name['bits']);
			}else{
				$upload	= wp_handle_sideload($name, ['test_form'=>false]);
			}
		}else{
			$upload	= wp_handle_upload($_FILES[$name], ['test_form'=>false]);
		}

		return self::is_error($upload) ?: $upload+['path'=>self::convert($upload['file'], 'file', 'path')];
	}

	public static function upload_bits($bits, $name, $media=true, $post_id=0){
		$args	= self::parse_args($name, $media, $post_id);
		$name	= $args['name'];
		$field	= $args['field'];
		$pos	= strrpos($name, '.');
		$part	= $pos ? substr($name, 0, $pos) : $name;

		if(preg_match('/data:image\/([^;]+);base64,(.*)/i', $bits, $matches)){
			$bits	= base64_decode(trim($matches[2]));
			$name	= $part.'.'.$matches[1];
		}

		$upload	= wp_upload_bits($name, null, $bits);
		$error	= self::is_error($upload);

		if($error){
			return $error;
		}

		if($args['media']){
			$id	= self::add_to_media($upload, $part, $args['post_id']);

			if(is_wp_error($id) || $field == 'id'){
				return $id;
			}
		}

		return $upload[$field];
	}

	public static function download_url($url, $name='', $media=true, $post_id=0){
		$id		= self::get_id_by_meta($url, 'source_url');
		$args	= self::parse_args($name, $media, $post_id);
		$name	= $args['name'];
		$field	= $args['field'];

		if($id){
			return self::convert($id, 'id', $field);
		}

		$tmp_file	= download_url($url);

		if(is_wp_error($tmp_file)){
			return $tmp_file;
		}

		if(!$name){
			$type	= wp_get_image_mime($tmp_file);
			$name	= md5($url).'.'.(explode('/', $type)[1]);
		}

		$file	= ['name'=>$name, 'tmp_name'=>$tmp_file];

		if($args['media']){
			$id	= media_handle_sideload($file, $args['post_id']);

			if(is_wp_error($id)){
				@unlink($tmp_file);

				return $id;
			}

			update_post_meta($id, 'source_url', $url);

			return self::convert($id, 'id', $field);
		}else{
			$upload	= self::upload($file);

			return is_wp_error($upload) ? $upload : $upload[$field];
		}
	}

	public static function scandir($dir, $callback=null){
		$files	= [];

		foreach(scandir($dir) as $file){
			if($file == '.' || $file == '..'){
				continue;
			}

			$file 	= $dir.'/'.$file;
			$files	= array_merge($files, (is_dir($file) ? self::scandir($file) : [$file]));
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

	public static function convert($value, $from='path', $to='file'){
		if($from == 'id'){
			if($value && get_post_type($value) == 'attachment'){
				if($to == 'id'){
					return $value;
				}elseif($to == 'file'){
					return get_attached_file($value);
				}elseif($to == 'url'){
					return wp_get_attachment_url($value);
				}elseif($to == 'size'){
					$data	= wp_get_attachment_metadata($value);

					return $data ? wpjam_slice($data, ['width', 'height']) : [];
				}
			}

			return null;
		}

		$dir	= wp_get_upload_dir();

		if($from == 'path'){
			$path	= $value;
		}else{
			if($from == 'url'){
				$value	= parse_url($value, PHP_URL_PATH);
				$base	= parse_url($dir['baseurl'], PHP_URL_PATH);
			}elseif($from == 'file'){
				$base	= $dir['basedir'];
			}

			if(!str_starts_with($value, $base)){
				return null;
			}

			$path	= wpjam_remove_prefix($value, $base);
		}

		if($to == 'path'){
			return $path;
		}elseif($to == 'file'){
			return $dir['basedir'].$path;
		}elseif($to == 'url'){
			return $dir['baseurl'].$path;
		}elseif($to == 'size'){
			$file	= $dir['basedir'].$path;
			$size	= file_exists($file) ? wp_getimagesize($file) : [];

			if($size){
				return ['width'=>$size[0], 'height'=>$size[1]];
			}
		}

		$id		= self::get_id_by_meta($path);

		return $id ? self::convert($id, 'id', $to) : null;
	}

	public static function get_id_by_meta($value, $key='_wp_attached_file'){
		if($key == '_wp_attached_file'){
			$value	= ltrim($value, '/');
		}

		$meta	= wpjam_get_by_meta('post', $key, $value);

		if($meta){
			$id	= current($meta)['post_id'];

			if(get_post_type($id) == 'attachment'){
				return $id;
			}
		}

		return '';
	}

	private static function add_to_media($file, $post_id=0){
		if(is_array($file)){
			$upload	= $file;
			$file	= $upload['file'] ?? '';
			$url	= $upload['url'] ?? '';
			$type	= $upload['type'] ?? '';
		}else{
			$url	= $type = '';
		}

		if(!$file){
			return;
		}

		$id	= self::convert($file, 'file', 'id');

		if($id){
			return $id;
		}

		$url	= $url ?: self::convert($file, 'file', 'url');
		$type	= $type ?: mime_content_type($file);

		if(!$url){
			return;
		}

		require_once ABSPATH.'wp-admin/includes/image.php';

		$title		= preg_replace('/\.[^.]+$/', '', wp_basename($file));
		$content	= '';
		$image_meta	= wp_read_image_metadata($file);

		if($image_meta ) {
			if(trim($image_meta['title']) && !is_numeric(sanitize_title($image_meta['title']))){
				$title	= $image_meta['title'];
			}

			if(trim($image_meta['caption'])){
				$content	= $image_meta['caption'];
			}
		}

		$id	= wp_insert_attachment([
			'post_title'		=> $title,
			'post_content'		=> $content,
			'post_parent'		=> $post_id,
			'post_mime_type'	=> $type,
			'guid'				=> $url,
		], $file, $post_id, true);

		if(!is_wp_error($id)){
			wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
		}

		return $id;
	}

	private static function is_error($upload){
		if(isset($upload['error']) && $upload['error'] !== false){
			return new WP_Error('upload_error', $upload['error']);
		}
	}

	private static function parse_args($name, $media=true, $post_id=0){
		$args	= is_array($name) ? $name+[
			'name'		=> '',
			'media'		=> false,
			'post_id'	=> 0,
		] : [
			'name'		=> $name,
			'media'		=> $media,
			'post_id'	=> $post_id,
		];

		if(empty($args['field'])){
			$args['field']	= $args['media'] ? 'id' : 'file';
		}

		return $args;
	}

	// $size, $ratio
	// $size, $ratio, [$max_width, $max_height]
	// $size, [$max_width, $max_height]
	public static function parse_size($size, ...$args){
		$ratio	= 1;
		$max	= [0, 0];

		if($args){
			if(is_array($args[0])){
				$max	= array_replace($max, $args[0]);
			}else{
				$ratio	= $args[0];

				if(isset($args[1]) && is_array($args[1])){
					$max	= array_replace($max, $args[1]);
				}
			}
		}

		if(is_array($size)){
			if(wp_is_numeric_array($size)){
				$size	= ['width'=>($size[0] ?? 0), 'height'=>($size[1] ?? 0)];
			}

			$size['width']	= !empty($size['width']) ? ((int)$size['width'])*$ratio : 0;
			$size['height']	= !empty($size['height']) ? ((int)$size['height'])*$ratio : 0;
			$size['crop']	??= $size['width'] && $size['height'];
		}else{
			$size	= $size ? str_replace(['*','X'], 'x', $size) : '';

			if(strpos($size, 'x') !== false){
				$size	= explode('x', $size);
				$width	= $size[0];
				$height	= $size[1];
				$crop	= true;
			}elseif(is_numeric($size)){
				$width	= $size;
				$height	= 0;
			}elseif($size == 'thumb' || $size == 'thumbnail'){
				$width	= get_option('thumbnail_size_w') ?: 100;
				$height = get_option('thumbnail_size_h') ?: 100;
				$crop	= get_option('thumbnail_crop');
			}elseif($size == 'medium'){
				$width	= get_option('medium_size_w') ?: 300;
				$height	= get_option('medium_size_h') ?: 300;
				$crop	= false;
			}else{
				if($size == 'medium_large'){
					$width	= get_option('medium_large_size_w');
					$height	= get_option('medium_large_size_h');
					$crop	= false;
				}elseif($size == 'large'){
					$width	= get_option('large_size_w') ?: 1024;
					$height	= get_option('large_size_h') ?: 1024;
					$crop	= false;
				}else{
					$sizes = wp_get_additional_image_sizes();

					if(isset($sizes[$size])){
						$width	= $sizes[$size]['width'];
						$height	= $sizes[$size]['height'];
						$crop	= $sizes[$size]['crop'];
					}else{
						$width	= $height = 0;
					}
				}

				if($width && !empty($GLOBALS['content_width'])){
					$width	= min($GLOBALS['content_width'] * $ratio, $width);
				}
			}

			$size	= [
				'crop'		=> $crop ?? ($width && $height),
				'width'		=> (int)$width * $ratio,
				'height'	=> (int)$height * $ratio
			];
		}

		if($max[0] && $max[1]){
			if($size['width'] && $size['height']){
				list($size['width'], $size['height'])	= wp_constrain_dimensions($size['width'], $size['height'], $max[0], $max[1]);
			}elseif($size['width']){
				$size['width']	= $size['width'] < $max[0] ? $size['width'] : $max[0];
			}else{
				$size['height']	= $size['height'] < $max[1] ? $size['height'] : $max[1];
			}
		}

		return $size;
	}

	public static function get_thumbnail($url, ...$args){
		if(!$args){	// 1. 无参数
			$args	= [];
		}elseif(count($args) == 1){
			// 2. ['width'=>100, 'height'=>100]	标准版
			// 3. [100,100]
			// 4. 100x100
			// 5. 100

			$args	= self::parse_size($args[0]);
		}elseif(is_numeric($args[0])){
			// 6. 100, 100, $crop=1

			$args	= [
				'width'		=> $args[0] ?? 0,
				'height'	=> $args[1] ?? 0,
				'crop'		=> $args[2] ?? 1
			];
		}else{
			// 7.【100,100], $crop=1

			$args	= array_merge(self::parse_size($args[0]), ['crop'=>$args[1] ?? 1]);
		}

		return apply_filters('wpjam_thumbnail', $url, $args);
	}

	public static function import($file, $columns=[]){
		$parts	= explode('.', $file);
		$ext	= end($parts);
		$file	= wp_get_upload_dir()['basedir'].$file;

		if(!$file || !file_exists($file)){
			return new WP_Error('file_not_exists', '文件不存在');
		}

		if($ext == 'csv'){
			if(($handle = fopen($file, 'r')) !== false){
				$indexes	= [];
				$i			= 0;

				while(($row = fgetcsv($handle)) !== false){
					$i ++;

					if($i == 1){
						$encoding	= mb_detect_encoding(implode('', $row), mb_list_encodings(), true);
					}

					if($encoding != 'UTF-8'){
						$row	= array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'GBK'), $row);
					}

					if($i == 1){
						$fn		= fn($v)=> trim(trim($v), "\xEF\xBB\xBF");
						$row	= array_flip(array_map($fn, $row));

						foreach(array_map($fn, $columns) as $key => $title){
							if(isset($row[$title])){
								$indexes[$key]	= $row[$title];
							}
						}
					}else{
						$data[]	= wpjam_map($indexes, fn($index)=> $row[$index]);
					}
				}

				fclose($handle);
			}
		}else{
			$data	= file_get_contents($file);

			if($ext == 'txt' && is_serialized($data)){
				$data	= maybe_unserialize($data);
			}
		}

		unlink($file);

		return $data ?? [];
	}

	public static function export($file, $data, $columns=[]){
		$parts	= explode('.', $file);
		$ext	= end($parts);

		$handle	= fopen('php://output', 'w');

		header('Content-Disposition: attachment;filename='.$file);
		header('Pragma: no-cache');
		header('Expires: 0');

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
}

class WPJAM_Video{
	public static function get_mp4($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			if(preg_match('#http://www.miaopai.com/show/(.*?).htm#i',$id_or_url, $matches)){
				return 'http://gslb.miaopai.com/stream/'.esc_attr($matches[1]).'.mp4';
			}elseif(preg_match('#https://v.qq.com/x/page/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}elseif(preg_match('#https://v.qq.com/x/cover/.*/(.*?).html#i',$id_or_url, $matches)){
				return self::get_qqv_mp4($matches[1]);
			}else{
				return wpjam_zh_urlencode($id_or_url);
			}
		}else{
			return self::get_qqv_mp4($id_or_url);
		}
	}

	public static function get_qqv_id($id_or_url){
		if(filter_var($id_or_url, FILTER_VALIDATE_URL)){
			foreach([
				'#https://v.qq.com/x/page/(.*?).html#i',
				'#https://v.qq.com/x/cover/.*/(.*?).html#i'
			] as $pattern){
				if(preg_match($pattern,$id_or_url, $matches)){
					return $matches[1];
				}
			}

			return '';
		}

		return $id_or_url;
	}

	public static function get_qqv_mp4($vid, $cache=true){
		if(strlen($vid) > 20){
			return new WP_Error('error', '无效的腾讯视频');
		}

		if($cache){
			return wpjam_transient('qqv_mp4:'.$vid, fn()=> self::get_qqv_mp4($vid, false), HOUR_IN_SECONDS*6);
		}

		$response	= wpjam_remote_request('http://vv.video.qq.com/getinfo?otype=json&platform=11001&vid='.$vid, ['timeout'=>4]);

		if(is_wp_error($response)){
			return $response;
		}

		$response	= trim(substr($response, strpos($response, '{')),';');
		$response	= wpjam_try('wpjam_json_decode', $response);

		if(empty($response['vl'])){
			return new WP_Error('error', '腾讯视频不存在或者为收费视频！');
		}

		$u	= $response['vl']['vi'][0];
		$p0	= $u['ul']['ui'][0]['url'];
		$p1	= $u['fn'];
		$p2	= $u['fvkey'];

		return $p0.$p1.'?vkey='.$p2;
	}

	public static function render($content, $attr){
		if(preg_match('#//www.bilibili.com/video/(BV[a-zA-Z0-9]+)#i',$content, $matches)){
			$src	= 'https://player.bilibili.com/player.html?bvid='.esc_attr($matches[1]);
		}elseif(preg_match('#//v.qq.com/(.*)iframe/(player|preview).html\?vid=(.+)#i',$content, $matches)){
			$src	= 'https://v.qq.com/'.esc_attr($matches[1]).'iframe/player.html?vid='.esc_attr($matches[3]);
		}elseif(preg_match('#//v.youku.com/v_show/id_(.*?).html#i',$content, $matches)){
			$src	= 'https://player.youku.com/embed/'.esc_attr($matches[1]);
		}elseif(preg_match('#//www.tudou.com/programs/view/(.*?)#i',$content, $matches)){
			$src	= 'https://www.tudou.com/programs/view/html5embed.action?code='.esc_attr($matches[1]);
		}elseif(preg_match('#//tv.sohu.com/upload/static/share/share_play.html\#(.+)#i',$content, $matches)){
			$src	= 'https://tv.sohu.com/upload/static/share/share_play.html#'.esc_attr($matches[1]);
		}elseif(preg_match('#//www.youtube.com/watch\?v=([a-zA-Z0-9\_]+)#i',$content, $matches)){
			$src	= 'https://www.youtube.com/embed/'.esc_attr($matches[1]);
		}

		if(!empty($src)){
			$attr	= shortcode_atts(['width'=>0, 'height'=>0], $attr);
			$attr	= ($attr['width'] || $attr['height']) ? image_hwstring($attr['width'], $attr['height']).' style="aspect-ratio:4/3;"' : 'style="width:100%; aspect-ratio:4/3;"';

			return '<iframe class="wpjam_video" '.$attr.' src="'.$src.'" scrolling="no" border="0" frameborder="no" framespacing="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
		}
	}
}

class IP{
	private static $ip = null;
	private static $fp = null;
	private static $offset = null;
	private static $index = null;
	private static $cached = [];

	public static function find($ip){
		if (empty( $ip ) === true) {
			return 'N/A';
		}

		$nip	= gethostbyname($ip);
		$ipdot	= explode('.', $nip);

		if ($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4) {
			return 'N/A';
		}

		if (isset( self::$cached[$nip] ) === true) {
			return self::$cached[$nip];
		}

		if (self::$fp === null) {
			self::init();
		}

		$nip2 = pack('N', ip2long($nip));

		$tmp_offset	= (int) $ipdot[0] * 4;
		$start		= unpack('Vlen',
			self::$index[$tmp_offset].self::$index[$tmp_offset + 1].self::$index[$tmp_offset + 2].self::$index[$tmp_offset + 3]);

		$index_offset = $index_length = null;
		$max_comp_len = self::$offset['len'] - 1024 - 4;
		for ($start = $start['len'] * 8 + 1024; $start < $max_comp_len; $start += 8) {
			if (self::$index[$start].self::$index[$start+1].self::$index[$start+2].self::$index[$start+3] >= $nip2) {
				$index_offset = unpack('Vlen',
					self::$index[$start+4].self::$index[$start+5].self::$index[$start+6]."\x0");
				$index_length = unpack('Clen', self::$index[$start+7]);

				break;
			}
		}

		if ($index_offset === null) {
			return 'N/A';
		}

		fseek(self::$fp, self::$offset['len'] + $index_offset['len'] - 1024);

		self::$cached[$nip] = explode("\t", fread(self::$fp, $index_length['len']));

		return self::$cached[$nip];
	}

	private static function init(){
		if(self::$fp === null){
			self::$ip = new self();

			self::$fp = fopen(WP_CONTENT_DIR.'/uploads/17monipdb.dat', 'rb');
			if (self::$fp === false) {
				throw new Exception('Invalid 17monipdb.dat file!');
			}

			self::$offset = unpack('Nlen', fread(self::$fp, 4));
			if (self::$offset['len'] < 4) {
				throw new Exception('Invalid 17monipdb.dat file!');
			}

			self::$index = fread(self::$fp, self::$offset['len'] - 4);
		}
	}

	public function __destruct(){
		if(self::$fp !== null){
			fclose(self::$fp);
		}
	}
}