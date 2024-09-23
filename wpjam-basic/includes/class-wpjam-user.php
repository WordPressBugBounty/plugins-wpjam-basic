<?php
class WPJAM_User{
	use WPJAM_Instance_Trait;

	protected $id;

	protected function __construct($id){
		$this->id	= (int)$id;
	}

	public function __get($key){
		if(in_array($key, ['id', 'user_id'])){
			return $this->id;
		}elseif(in_array($key, ['user', 'data'])){
			return get_userdata($this->id);
		}elseif($key == 'role'){
			return reset($this->data->roles);
		}else{
			return $this->user->$key ?? $this->meta_get($key);
		}
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function value_callback($field){
		if($field == 'role'){
			return reset($this->user->roles);
		}

		return $this->user->$field ?? $this->meta_get($field);
	}

	public function parse_for_json($size=96){
		return apply_filters('wpjam_user_json', [
			'id'			=> $this->id,
			'nickname'		=> $this->nickname,
			'name'			=> $this->display_name,
			'display_name'	=> $this->display_name,
			'avatar'		=> get_avatar_url($this->user, $size),
		], $this->id);
	}

	public function meta_get($key){
		return wpjam_get_metadata('user', $this->id, $key, null);
	}

	public function meta_exists($key){
		return metadata_exists('user', $this->id, $key);
	}

	public function meta_input(...$args){
		if($args){
			return wpjam_update_metadata('user', $this->id, ...$args);
		}
	}

	public function update_avatarurl($avatarurl){
		if($this->avatarurl != $avatarurl){
			$this->meta_input('avatarurl', $avatarurl);
		}

		return true;
	}

	public function update_nickname($nickname){
		if($this->nickname != $nickname){
			self::update($this->id, ['nickname'=>$nickname, 'display_name'=>$nickname]);
		}

		return true;
	}

	public function add_role($role, $blog_id=0){
		return wpjam_call_for_blog($blog_id, function($role){
			$wp_error	= false;

			if($this->roles){
				if(!in_array($role, $this->roles)){
					$wp_error	= new WP_Error('error', '你已有权限，如果需要更改权限，请联系管理员直接修改。');
				}
			}else{
				$this->user->add_role($role);
			}

			return $wp_error ?: $this->user;
		}, $role);
	}

	public function login(){
		wp_set_auth_cookie($this->id, true, is_ssl());
		wp_set_current_user($this->id);
		do_action('wp_login', $this->user_login, $this->user);
	}

	public function get_openid($name, $appid=''){
		return self::get_signup($name, $appid)->get_openid($this->id);
	}

	public function update_openid($name, $appid, $openid){
		return self::get_signup($name, $appid)->update_openid($this->id, $openid);
	}

	public function delete_openid($name, $appid=''){
		return self::get_signup($name, $appid)->delete_openid($this->id);
	}

	public function bind($name, $appid, $openid){
		return self::get_signup($name, $appid)->bind($openid, $this->id);
	}

	public function unbind($name, $appid=''){
		return self::get_signup($name, $appid)->unbind($this->id);
	}

	public static function get_instance($id, $wp_error=false){
		$user	= self::validate($id);

		if(is_wp_error($user)){
			return $wp_error ? $user : null;
		}

		return self::instance($user->ID, fn($id)=> new self($id));
	}

	public static function validate($user_id){
		$user	= $user_id ? self::get_user($user_id) : null;

		if(!$user || !($user instanceof WP_User)){
			return new WP_Error('invalid_user');
		}

		return $user;
	}

	public static function update_caches($user_ids){
		$user_ids	= array_filter(wp_parse_id_list($user_ids));

		if(!$user_ids){
			return [];
		}

		cache_users($user_ids);

		return array_map('get_userdata', $user_ids);
	}

	public static function get_by_ids($user_ids){
		return self::update_caches($user_ids);
	}

	public static function get_user($user){
		if($user && is_numeric($user)){	// 不存在情况下的缓存优化
			$user_id	= $user;
			$found		= false;
			$cache		= wp_cache_get($user_id, 'users', false, $found);

			if($found){
				return $cache ? get_userdata($user_id) : $cache;
			}else{
				$user	= get_userdata($user_id);

				if(!$user){	// 防止重复 SQL 查询。
					wp_cache_add($user_id, false, 'users', 10);
				}
			}
		}

		return $user;
	}

	public static function get_authors($args=[]){
		return get_users(array_merge($args, ['capability'=>'edit_posts']));
	}

	public static function get_path($args, $item=[]){
		$id	= is_array($args) ? (int)wpjam_pull($args, 'author') : $args;

		if(!$id){
			return new WP_Error('invalid_author', ['作者']);
		}

		return $item['platform'] == 'template' ? get_author_posts_url($id) : str_replace('%author%', $id, $item['path']);
	}

	public static function get_path_fields(){
		return ['author' => ['type'=>'select', 'options'=>fn()=> wp_list_pluck(self::get_authors(), 'display_name', 'ID')]];
	}

	public static function options_callback($field){
		return wp_list_pluck(self::get_authors(), 'display_name', 'ID');
	}

	public static function get($id){
		$user	= get_userdata($id);

		return $user ? $user->to_array() : [];
	}

	public static function insert($data){
		return wp_insert_user(wp_slash($data));
	}

	public static function update($user_id, $data){
		$data['ID'] = $user_id;

		return wp_update_user(wp_slash($data));
	}

	public static function create($args){
		return wpjam_call_for_blog(array_get($args, 'blog_id'), function($args){
			$args	+= [
				'user_pass'		=> wp_generate_password(12, false),
				'user_login'	=> '',
				'user_email'	=> '',
				'nickname'		=> '',
				// 'avatarurl'		=> '',
			];

			if(!wpjam_pull($args, 'users_can_register', get_option('users_can_register'))){
				return new WP_Error('registration_closed', '用户注册关闭，请联系管理员手动添加！');
			}

			if(empty($args['user_email'])){
				return new WP_Error('empty_user_email', '用户的邮箱不能为空。');
			}

			$args['user_login']	= preg_replace('/\s+/', '', sanitize_user($args['user_login'], true));

			if($args['user_login']){
				$lock_key	= $args['user_login'].'_register_lock';
				$result		= wp_cache_add($lock_key, true, 'users', 5);

				if($result === false){
					return new WP_Error('error', '该用户名正在注册中，请稍后再试！');
				}
			}

			$data	= wpjam_slice($args, ['user_login', 'user_pass', 'user_email', 'role']);

			if($args['nickname']){
				$data['nickname']	= $data['display_name']	= $args['nickname'];
			}

			$user_id	= self::insert($data);

			wp_cache_delete($lock_key, 'users');

			return is_wp_error($user_id) ? $user_id : self::get_instance($user_id);
		}, $args);
	}

	public static function query_items($args){
		if(wpjam_pull($args, 'data_type')){
			return get_users(array_merge($args, ['search'=> !empty($args['search']) ? '*'.$args['search'].'*' : '']));
		}
	}

	public static function filter_fields($fields, $id){
		if($id && !is_array($id)){
			$object	= self::get_instance($id);
			$fields	= array_merge(['name'=>['title'=>'用户', 'type'=>'view', 'value'=>$object->display_name]], $fields);
		}

		return $fields;
	}

	public static function signup($name, $appid, $openid, $args){
		return self::get_signup($name, $appid)->signup($openid);
	}

	protected static function get_signup($name, $appid=''){
		trigger_error('get_signup');	// delete 2024-06-30
		return wpjam_get_user_signup_object($name, $appid);
	}

	public static function get_meta($user_id, ...$args){
		_deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_get_metadata');		// delete 2024-06-30
		return wpjam_get_metadata('user', $user_id, ...$args);
	}

	public static function update_meta($user_id, ...$args){
		_deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');		// delete 2024-06-30
		return wpjam_update_metadata('user', $user_id, ...$args);
	}

	public static function update_metas($user_id, $data, $meta_keys=[]){
		_deprecated_function(__METHOD__, 'WPJAM Basic 6.0', 'wpjam_update_metadata');		// delete 2024-06-30
		return wpjam_update_metadata('user', $user_id, $data, $meta_keys);
	}
}

class WPJAM_Bind extends WPJAM_Register{
	public function __construct($type, $appid, $args=[]){
		parent::__construct($type.':'.$appid, array_merge($args, [
			'type'		=> $type,
			'appid'		=> $appid,
			'bind_key'	=> wpjam_join('_', [$type, $appid])
		]));
	}

	public function get_appid(){
		return $this->appid;
	}

	public function get_domain(){
		return $this->domain ?: $this->appid.'.'.$this->type;
	}

	protected function get_object($meta_type, $object_id){
		$callback	= 'wpjam_get_'.$meta_type.'_object';

		if(is_callable($callback)){
			return $callback($object_id);
		}
	}

	public function get_openid($meta_type, $object_id){
		return get_metadata($meta_type, $object_id, $this->bind_key, true);
	}

	public function update_openid($meta_type, $object_id, $openid){
		return update_metadata($meta_type, $object_id, $this->bind_key, $openid);
	}

	public function delete_openid($meta_type, $object_id){
		return delete_metadata($meta_type, $object_id, $this->bind_key);
	}

	public function bind_openid($meta_type, $object_id, $openid){
		wpjam_register_error_setting('is_bound', '已绑定其他账号，请先解绑再试！');

		$current	= $this->get_openid($meta_type, $object_id);

		if($current && $current != $openid){
			return new WP_Error('is_bound');
		}

		$exists	= $this->get_by_openid($meta_type, $openid);

		if(is_wp_error($exists)){
			return $exists;
		}

		if($exists && $exists->id != $object_id){
			return new WP_Error('is_bound');
		}

		$this->update_value($openid, $meta_type.'_id', $object_id);

		return $current ? true : $this->update_openid($meta_type, $object_id, $openid);
	}

	public function unbind_openid($meta_type, $object_id){
		$openid	= $this->get_openid($meta_type, $object_id);
		$openid	= $openid ?: $this->get_openid_by($meta_type.'_id', $object_id);

		if($openid){
			$this->delete_openid($meta_type, $object_id);
			$this->update_value($openid, $meta_type.'_id', 0);
		}

		return $openid;
	}

	public function get_by_openid($meta_type, $openid){
		if(!$this->get_user($openid)){
			return new WP_Error('invalid_openid');
		}

		$object_id	= $this->get_value($openid, $meta_type.'_id');
		$object		= $this->get_object($meta_type, $object_id);

		if(!$object){
			$meta_data	= wpjam_get_by_meta($meta_type, $this->bind_key, $openid);

			if($meta_data){
				$object_id	= current($meta_data)[$meta_type.'_id'];
				$object		= $this->get_object($meta_type, $object_id);
			}
		}

		if(!$object && $meta_type == 'user'){
			$user_id	= username_exists($openid);
			$object		= $user_id ? wpjam_get_user_object($user_id) : null;
		}

		return $object;
	}

	public function bind_by_openid($meta_type, $openid, $object_id){
		return $this->bind_openid($meta_type, $object_id, $openid);
	}

	public function unbind_by_openid($meta_type, $openid){
		$object_id	= $this->get_value($openid, $meta_type.'_id');

		if($object_id){
			$this->delete_openid($meta_type, $object_id);
			$this->update_value($openid, $meta_type.'_id', 0);
		}
	}

	public function get_by_user_email($meta_type, $email){
		if($email && str_ends_with($email, '@'.$this->get_domain())){
			$openid	= wpjam_remove_postfix($email, '@'.$this->get_domain());

			return $this->get_value($openid, $meta_type.'_id');
		}
	}

	protected function get_value($openid, $key){
		$user	= $this->get_user($openid);

		if($user && !is_wp_error($user)){
			return $user[$key] ?? null;
		}
	}

	protected function update_value($openid, $key, $value){
		$prev	= $this->get_value($openid, $key);

		return ($prev != $value) ? $this->update_user($openid, [$key=>$value]) : true;
	}

	public function get_user_email($openid){
		return $openid.'@'.$this->get_domain();
	}

	public function get_avatarurl($openid){
		return $this->get_value($openid, 'avatarurl');
	}

	public function get_nickname($openid){
		return $this->get_value($openid, 'nickname');
	}

	public function get_unionid($openid){
		return $this->get_value($openid, 'unionid');
	}

	public function get_phone_data($openid){
		$phone			= $this->get_value($openid, 'phone') ?: 0;
		$country_code	= $this->get_value($openid, 'country_code') ?: 86;

		return $phone ? ['phone'=>$phone, 'country_code'=>$country_code] : [];
	}

	public function get_openid_by($key, $value){
		return null;
	}

	public function get_user($openid){
		return ['openid'=>$openid];
	}

	public function update_user($openid, $user){
		return true;
	}

	public static function create($type, $appid, $args){
		if(is_array($args)){
			$object	= new static($type, $appid, $args);
		}else{
			$model	= $args;
			$object	= new $model($appid, []);
		}

		return self::register($object);
	}

	// compact
	protected function get_bind($openid, $bind, $unionid=false){
		return $this->get_value($openid, $bind);
	}

	public function get_email($openid){
		return $this->get_user_email($openid);
	}
}

class WPJAM_Qrcode_Bind extends WPJAM_Bind{
	public function verify_qrcode($scene, $code, $output=''){
		$qrcode	= $scene ? $this->cache_get($scene.'_scene') : null;

		if(!$qrcode){
			return new WP_Error('invalid_qrcode');
		}

		if(!$code || empty($qrcode['openid']) || $code != $qrcode['code']){
			return new WP_Error('invalid_code');
		}

		$this->cache_delete($scene.'_scene');

		return $output == 'openid' ? $qrcode['openid'] : $qrcode;
	}

	public function scan_qrcode($openid, $scene){
		$qrcode	= $scene ? $this->cache_get($scene.'_scene') : null;

		if(!$qrcode || (!empty($qrcode['openid']) && $qrcode['openid'] != $openid)){
			return new WP_Error('invalid_qrcode');
		}

		$this->cache_delete($qrcode['key'].'_qrcode');

		if(!empty($qrcode['id']) && !empty($qrcode['bind_callback']) && is_callable($qrcode['bind_callback'])){
			return $qrcode['bind_callback']($openid, $qrcode['id']);
		}else{
			$this->cache_set($scene.'_scene', array_merge($qrcode, ['openid'=>$openid]), 1200);

			return $qrcode['code'];
		}
	}

	public function create_qrcode($key, $args=[]){
		return [];
	}
}

class WPJAM_User_Signup extends WPJAM_Register{
	public function __construct($name, $args=[]){
		if(is_array($args)){
			if(empty($args['type'])){
				$args['type']	= $name;
			}

			parent::__construct($name, $args);
		}
	}

	public function __call($method, $args){
		$object	= wpjam_get_bind_object($this->type, $this->appid);
		$args	= (str_ends_with($method, '_openid') || $method == 'get_by_user_email') ? ['user', ...$args] : $args;

		return $object->$method(...$args);
	}

	public function _compact($openid){	// 兼容代码
		if($this->name == 'weixin'){
			return $this->verify_code($openid['code']);
		}elseif($this->name == 'phone'){
			$result	= wpjam_verify_sms($openid['phone'], $openid['code']);

			return is_wp_error($result) ? $result : $openid['phone'];
		}
	}

	public function signup($openid, $args=null){
		if(is_array($openid)){
			$openid	= $this->_compact($openid);

			if(is_wp_error($openid)){
				return $openid;
			}
		}

		$user	= $this->get_by_openid($openid);

		if(is_wp_error($user)){
			return $user;
		}

		$args	= $args ?? [];
		$args	= apply_filters('wpjam_user_signup_args', $args, $this->type, $this->appid, $openid);

		if(is_wp_error($args)){
			return $args;
		}

		if(!$user){
			$is_create	= true;

			$args['user_login']	= $openid;
			$args['user_email']	= $this->get_user_email($openid);
			$args['nickname']	= $this->get_nickname($openid);

			$user	= WPJAM_User::create($args);

			if(is_wp_error($user)){
				return $user;
			}
		}else{
			$is_create	= false;
		}

		if(!$is_create && !empty($args['role'])){
			$blog_id	= $args['blog_id'] ?? 0;
			$result		= $user->add_role($args['role'], $blog_id);

			if(is_wp_error($result)){
				return $result;
			}
		}

		$this->bind($openid, $user->id);

		$user->login();

		do_action('wpjam_user_signuped', $user->data, $args);

		return $user;
	}

	public function bind($openid, $user_id=null){
		if(is_array($openid)){
			$openid	= $this->_compact($openid);

			if(is_wp_error($openid)){
				return $openid;
			}
		}

		$user_id	= $user_id ?? get_current_user_id();
		$result		= $this->bind_openid($user_id, $openid);

		if($result && !is_wp_error($result)){
			$avatarurl	= $this->get_avatarurl($openid);
			$nickname	= $this->get_nickname($openid);
			$user		= wpjam_get_user_object($user_id);

			if($avatarurl){
				$user->update_avatarurl($avatarurl);
			}

			if($nickname && (!$user->nickname || $user->nickname == $openid)){
				$user->update_nickname($nickname);
			}
		}

		return $result;
	}

	public function unbind($user_id=null){
		$user_id	= $user_id ?? get_current_user_id();

		return $this->unbind_openid($user_id);
	}

	public function get_fields($action='login', $for=''){
		return [];
	}

	public function get_attr($action='login', $for=''){
		$fields	= $this->get_fields($action, $for);

		if(is_wp_error($fields)){
			return $fields;
		}

		$attr	= [];

		if($action == 'bind'){
			if($this->get_openid(get_current_user_id())){
				$fields['action']['value']	= 'unbind';
				$attr['submit_text']		= '解除绑定';
			}else{
				$attr['submit_text']		= '立刻绑定';
			}
		}

		if($for != 'admin'){
			$attr	= array_merge($attr, wpjam_get_ajax_data_attr($this->name.'-'.$action)->to_array());
			$fields	= wpjam_fields($fields)->render(['wrap_tag'=>'p']);
		}

		return $attr+['fields'=>$fields];
	}

	public function ajax_response($data){
		$action	= wpjam_pull($data, 'action');
		$method	= $action == 'login' ? 'signup' : $action;
		$args	= $method == 'unbind' ? [] : [$data];
		$result = wpjam_catch([$this, $method], ...$args);

		return is_wp_error($result) ? $result : true;
	}

	// public function register_bind_user_action(){
	// 	wpjam_register_list_table_action('bind_user', [
	// 		'title'			=> '绑定用户',
	// 		'capability'	=> is_multisite() ? 'manage_sites' : 'manage_options',
	// 		'callback'		=> [$this, 'bind_user_callback'],
	// 		'fields'		=> [
	// 			'nickname'	=> ['title'=>'用户',		'type'=>'view'],
	// 			'user_id'	=> ['title'=>'用户ID',	'type'=>'text',	'class'=>'all-options',	'description'=>'请输入 WordPress 的用户']
	// 		]
	// 	]);
	// }

	// public function bind_user_callback($openid, $data){
	// 	$user_id	= $data['user_id'] ?? 0;

	// 	if($user_id){
	// 		if(get_userdata($user_id)){
	// 			return $this->bind($openid, $user_id);
	// 		}else{
	// 			return new WP_Error('invalid_user');
	// 		}
	// 	}else{
	// 		return $this->unbind_by_openid($openid);
	// 	}
	// }

	public function registered($count){
		foreach(['login', 'bind'] as $action){
			wpjam_register_ajax($this->name.'-'.$action, [
				'nopriv'	=> true,
				'callback'	=> [$this, 'ajax_response']
			]);

			wpjam_register_ajax('get-'.$this->name.'-'.$action, [
				'nopriv'	=> true,
				'verify'	=> false,
				'callback'	=> fn()=> $this->get_attr($action)
			]);
		}
	}

	public static function create($name, $args){
		$model	= wpjam_pull($args, 'model');
		$type	= array_get($args, 'type') ?: $name;
		$appid	= array_get($args, 'appid');

		if(!wpjam_get_bind_object($type, $appid) || !$model){
			return null;
		}

		if(is_object($model)){	// 兼容
			$model	= get_class($model);
		}

		$args['type']	= $type;

		return self::register(new $model($name, $args));
	}
}

class WPJAM_User_Qrcode_Signup extends WPJAM_User_Signup{
	public function signup($data, $args=null){
		if(is_array($data)){
			$scene	= $data['scene'] ?? '';
			$code	= $data['code'] ?? '';
			$user	= apply_filters('wpjam_user_signup', null, 'qrcode', $scene, $code);

			if(!$user){
				$args	= $args ?? (array_get($data, 'args') ?: []);
				$openid	= $this->verify_qrcode($scene, $code, 'openid');
				$user	= is_wp_error($openid) ? $openid : parent::signup($openid, $args);
			}

			if(is_wp_error($user)){
				do_action('wpjam_user_signup_failed', 'qrcode', $scene, $user);
			}

			return $user;
		}

		return parent::signup($data, $args);
	}

	public function bind($data, $user_id=null){
		if(is_array($data)){
			$scene	= $data['scene'] ?? '';
			$code	= $data['code'] ?? '';
			$openid	= $this->verify_qrcode($scene, $code, 'openid');

			if(is_wp_error($openid)){
				return $openid;
			}
		}else{
			$openid	= $data;
		}

		return parent::bind($openid, $user_id);
	}

	public function qrcode_signup($scene, $code, $args=[]){
		return $this->signup(compact('scene', 'code'), $args);
	}

	public function get_fields($action='login', $for='admin'){
		if($action == 'bind'){
			$user_id	= get_current_user_id();
			$openid		= $this->get_openid($user_id);
		}else{
			$openid		= null;
		}

		if($openid){
			$view	= '';

			if($avatar = $this->get_avatarurl($openid)){
				$view	.= '<img src="'.str_replace('/132', '/0', $avatar).'" width="272" />'."<br />";
			}

			if($nickname = $this->get_nickname($openid)){
				$view	.= '<strong>'.$nickname.'</strong>';
			}

			$view	= $view ?: $openid;

			return [
				'view'		=> ['type'=>'view',		'title'=>'绑定的微信账号',	'value'=>$view],
				'action'	=> ['type'=>'hidden',	'value'=>'unbind'],
			];
		}else{
			if($action == 'bind'){
				$qrcode	= $this->create_qrcode(md5('bind_'.$user_id), ['id'=>$user_id]);
				$title	= '微信扫码，一键绑定';
			}else{
				$qrcode	= $this->create_qrcode(wp_generate_password(32, false, false));
				$title	= '微信扫码，一键登录';
			}

			if(is_wp_error($qrcode)){
				return $qrcode;
			}

			$img	= array_get($qrcode, 'qrcode_url') ?: array_get($qrcode, 'qrcode');

			return [
				'qrcode'	=> ['type'=>'view',		'title'=>$title,	'value'=>'<img src="'.$img.'" width="272" />'],
				'code'		=> ['type'=>'number',	'title'=>'验证码',	'class'=>'input',	'required', 'size'=>20],
				'scene'		=> ['type'=>'hidden',	'value'=>$qrcode['scene']],
				'action'	=> ['type'=>'hidden',	'value'=>$action],
			];
		}
	}
}