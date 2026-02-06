<?php
class WPJAM_User extends WPJAM_Instance{
	public function __get($key){
		if(in_array($key, ['id', 'user_id'])){
			return $this->id;
		}elseif(in_array($key, ['user', 'data'])){
			return get_userdata($this->id);
		}elseif($key == 'role'){
			return array_first($this->roles);
		}

		return $this->user->$key ?? $this->meta_get($key);
	}

	public function __isset($key){
		return $this->$key !== null;
	}

	public function value_callback($field){
		return $this->$field;
	}

	public function save($data){
		return $data ? self::update($this->id, $data) : true;
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

	public function add_role($role, $blog_id=0){
		return wpjam_call_for_blog($blog_id, function($role){
			if(!$this->roles){
				$this->user->add_role($role);
			}elseif(!in_array($role, $this->roles)){
				return new WP_Error('error', '你已有权限，如果需要更改权限，请联系管理员直接修改。');
			}

			return $this->user;
		}, $role);
	}

	public function login(){
		wp_set_auth_cookie($this->id, true, is_ssl());
		wp_set_current_user($this->id);
		do_action('wp_login', $this->user_login, $this->user);
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

		return ($user && ($user instanceof WP_User)) ? $user : new WP_Error('invalid_user_id');
	}

	public static function update_caches($ids){
		if($ids	= array_filter(wp_parse_id_list($ids))){
			cache_users($ids);

			return array_map('get_userdata', $ids);
		}

		return [];
	}

	public static function get_by_ids($ids){
		return self::update_caches($ids);
	}

	public static function get_user($user){
		return $user && is_numeric($user) ? wpjam_tap(get_userdata($user), fn($v)=> !$v && do_action('wpjam_deleted_ids', 'user', $user)) : $user;
	}

	public static function get_authors($args=[]){
		return get_users(array_merge($args, ['capability'=>'edit_posts']));
	}

	public static function get_path($args, $item=[]){
		$id	= is_array($args) ? (int)wpjam_get($args, 'author') : $args;

		if($id === 'fields'){
			return ['author' => ['type'=>'select', 'options'=>fn()=> wp_list_pluck(WPJAM_User::get_authors(), 'display_name', 'ID')]];
		}

		if(!$id){
			return new WP_Error('invalid_author', ['作者']);
		}

		return $item['platform'] == 'template' ? get_author_posts_url($id) : str_replace('%author%', $id, $item['path']);
	}

	public static function options_callback($field){
		return wp_list_pluck(self::get_authors(), 'display_name', 'ID');
	}

	public static function get($id){
		return ($user	= get_userdata($id)) ? $user->to_array() : [];
	}

	protected static function call_method($method, ...$args){
		if($method == 'get_meta_type'){
			return 'user';
		}elseif($method == 'create'){
			$args	= $args[0]+[
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

			$data	= wpjam_pick($args, ['user_login', 'user_pass', 'user_email', 'role']);
			$data	+= $args['nickname'] ? ['nickname'=>$args['nickname'], 'display_name'=>$args['nickname']] : [];
			$id		= static::insert($data);

			return wpjam_tap(is_wp_error($id) ? $id : static::get_instance($id), fn()=> wp_cache_delete($lock_key, 'users'));
		}elseif($method == 'insert'){
			return wp_insert_user(wp_slash($args[0]));
		}elseif($method == 'update'){
			return wp_update_user(wp_slash(array_merge($args[1], ['ID'=>$args[0]])));
		}elseif($method == 'delete'){
			return wp_delete_user($args[0]);
		}
	}

	public static function create($args){
		return wpjam_call_for_blog(wpjam_get($args, 'blog_id'), fn()=> static::call_method('create', $args));
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
}

class WPJAM_Bind extends WPJAM_Register{
	public function __construct($type, $appid, $args=[]){
		parent::__construct($type.':'.$appid, array_merge($args, ['type'=>$type, 'appid'=>$appid, 'bind_key'=>wpjam_join('_', $type, $appid)]));
	}

	public function get_appid(){
		return $this->appid;
	}

	public function get_domain(){
		return $this->domain ?: $this->appid.'.'.$this->type;
	}

	protected function get_object($meta_type, $object_id){
		return wpjam_call('wpjam_get_'.$meta_type.'_object', $object_id);
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
		$bound_msg	= '已绑定其他账号，请先解绑再试！';
		$current	= $this->get_openid($meta_type, $object_id);

		if($current && $current != $openid){
			return new WP_Error('is_bound', $bound_msg);
		}

		$exists	= $this->get_by_openid($meta_type, $openid);

		if(is_wp_error($exists)){
			return $exists;
		}

		if($exists && $exists->id != $object_id){
			return new WP_Error('is_bound', $bound_msg);
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

		$object	= $this->get_object($meta_type, $this->get_value($openid, $meta_type.'_id'));
		$object	= $object ?: (($meta = wpjam_get_by_meta($meta_type, $this->bind_key, $openid)) ? $this->get_object($meta_type, array_first($meta)[$meta_type.'_id']) : null);

		return $object ?: (($meta_type == 'user' && ($user_id = username_exists($openid))) ? wpjam_get_user_object($user_id) : null);
	}

	public function bind_by_openid($meta_type, $openid, $object_id){
		return $this->bind_openid($meta_type, $object_id, $openid);
	}

	public function unbind_by_openid($meta_type, $openid){
		if($object_id = $this->get_value($openid, $meta_type.'_id')){
			$this->delete_openid($meta_type, $object_id);
			$this->update_value($openid, $meta_type.'_id', 0);
		}
	}

	public function get_by_user_email($meta_type, $email){
		if($email && try_remove_suffix($email, '@'.$this->get_domain())){
			return $this->get_value($email, $meta_type.'_id');
		}
	}

	protected function get_value($openid, $key){
		if(($user = $this->get_user($openid)) && !is_wp_error($user)){
			return $user[$key] ?? null;
		}
	}

	protected function update_value($openid, $key, $value){
		return ($this->get_value($openid, $key) != $value) ? $this->update_user($openid, [$key=>$value]) : true;
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
		return ($phone = $this->get_value($openid, 'phone')) ? ['phone'=>$phone, 'country_code'=>$this->get_value($openid, 'country_code') ?: 86] : [];
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

		$cb	= !empty($qrcode['id']) ? ($qrcode['bind_callback'] ?? '') : '';

		if($cb && is_callable($cb)){
			return $cb($openid, $qrcode['id']);
		}

		$this->cache_set($scene.'_scene', ['openid'=>$openid]+$qrcode, 1200);

		return $qrcode['code'];
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
			$user	= wpjam_get_user_object($user_id);

			if(($avatarurl = $this->get_avatarurl($openid)) && $avatarurl !== $user->avatarurl){
				$user->meta_input('avatarurl', $avatarurl);
			}

			if(($nickname = $this->get_nickname($openid)) && $nickname !== $user->nickname){
				$user->save(['nickname'=>$nickname, 'display_name'=>$nickname]);
			}
		}

		return $result;
	}

	public function unbind($user_id=null){
		return $this->unbind_openid($user_id ?? get_current_user_id());
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
			$attr	= array_merge($attr, wpjam_ajax($this->name.'-'.$action)->to_array());
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
	// 			return new WP_Error('invalid_user_id');
	// 		}
	// 	}else{
	// 		return $this->unbind_by_openid($openid);
	// 	}
	// }

	public function registered(){
		foreach(['login', 'bind'] as $action){
			wpjam_ajax($this->name.'-'.$action, [
				'nopriv'	=> true,
				'callback'	=> [$this, 'ajax_response']
			]);

			wpjam_ajax('get-'.$this->name.'-'.$action, [
				'nopriv'	=> true,
				'verify'	=> false,
				'callback'	=> fn()=> $this->get_attr($action)
			]);
		}
	}

	public static function create($name, $args){
		$model	= wpjam_pull($args, 'model');
		$type	= wpjam_get($args, 'type') ?: $name;
		$appid	= wpjam_get($args, 'appid');

		if(!wpjam_get_bind_object($type, $appid) || !$model){
			return null;
		}

		if(is_object($model)){	// 兼容
			$model	= get_class($model);
		}

		$args['type']	= $type;

		return self::register(new $model($name, $args));
	}

	public static function on_admin_init(){
		if($objects	= self::get_registereds()){
			$binds	= array_filter($objects, fn($v)=> $v->bind);

			$binds && wpjam_add_menu_page([
				'parent'		=> 'users',
				'menu_slug'		=> 'wpjam-bind',
				'menu_title'	=> '账号绑定',
				'order'			=> 20,
				'capability'	=> 'read',
				'function'		=> 'tab',
				'tabs'			=> fn()=> wpjam_map($binds, fn($object)=> [
					'title'			=> $object->title,
					'capability'	=> 'read',
					'function'		=> 'form',
					'form'			=> fn()=> array_merge([
						'callback'		=> [$object, 'ajax_response'],
						'capability'	=> 'read',
						'validate'		=> true,
						'response'		=> 'redirect'
					], $object->get_attr('bind', 'admin'))
				])
			]);

			wpjam_add_admin_load([
				'base'		=> 'users',
				'callback'	=> fn()=> wpjam_register_list_table_column('openid', [
					'title'		=> '绑定账号',
					'order'		=> 20,
					'callback'	=> fn($user_id)=> wpjam_join('<br /><br />', wpjam_map($objects, fn($v)=> ($openid = $v->get_openid($user_id)) ? $v->title.'：<br />'.$openid : ''))
				])
			]);
		}
	}

	public static function on_login_init(){
		wp_enqueue_script('wpjam-ajax');

		$action		= wpjam_get_request_parameter('action', ['default'=>'login']);
		$objects	= in_array($action, ['login', 'bind']) ? self::get_registereds([$action=>true]) : [];

		if($objects){
			$type	= wpjam_get_request_parameter($action.'_type');

			if($action == 'login'){
				$type	= $type ?: apply_filters('wpjam_default_login_type', 'login');
				$type	= $type ?: ($_SERVER['REQUEST_METHOD'] == 'POST' ? 'login' : array_key_first($objects));

				isset($objects[$type]) && wpjam_call($objects[$type]->login_action);

				if(empty($_COOKIE[TEST_COOKIE])){
					$_COOKIE[TEST_COOKIE]	= 'WP Cookie check';
				}

				$objects['login']	= '使用账号和密码登录';
			}else{
				is_user_logged_in() || wp_die('登录之后才能执行绑定操作！');

				add_filter('login_display_language_dropdown', '__return_false');
			}

			$type	= ($type == 'login' || ($type && isset($objects[$type]))) ? $type : array_key_first($objects);
	
			foreach($objects as $name => $object){
				if($name == 'login'){
					$data	= ['type'=>'login'];
					$title	= $object;
				}else{
					$data	= ['type'=>$name, 'action'=>'get-'.$name.'-'.$action];
					$title	= $action == 'bind' ? '绑定'.$object->title : $object->login_title;
				}

				$append[]	= ['a', ['class'=>($type == $name ? 'current' : ''), 'data'=>$data], $title];
			}

			wp_enqueue_script('wpjam-login', wpjam_url(dirname(__DIR__).'/static/login.js'), ['wpjam-ajax']);

			wpjam_hook('echo', 'login_form', fn()=> wpjam_tag('p')->add_class('types')->data('action', $action)->append($append));
		}

		wp_add_inline_style('login', join("\n", [
			'.login .message, .login #login_error{margin-bottom: 0;}',
			'.code_wrap label:last-child{display:flex;}',
			'.code_wrap input.button{margin-bottom:10px;}',
			'.login form .input, .login input[type=password], .login input[type=text]{font-size:20px; margin-bottom:10px;}',

			'p.types{line-height:2; float:left; clear:left; margin-top:10px;}',
			'p.types a{text-decoration: none; display:block;}',
			'p.types a.current{display:none;}',
			'div.fields{margin-bottom:10px;}',
		]));
	}

	public static function add_hooks(){
		if(wp_using_ext_object_cache()){
			add_action('login_init',		[self::class, 'on_login_init']);
			add_action('wpjam_admin_init',	[self::class, 'on_admin_init']);
		}
	}
}

class WPJAM_User_Qrcode_Signup extends WPJAM_User_Signup{
	public function signup($data, $args=null){
		if(is_array($data)){
			$scene	= $data['scene'] ?? '';
			$code	= $data['code'] ?? '';
			$user	= apply_filters('wpjam_user_signup', null, 'qrcode', $scene, $code);

			if(!$user){
				$args	= $args ?? (wpjam_get($data, 'args') ?: []);
				$openid	= $this->verify_qrcode($scene, $code, 'openid');
				$user	= is_wp_error($openid) ? $openid : parent::signup($openid, $args);
			}

			is_wp_error($user) && do_action('wpjam_user_signup_failed', 'qrcode', $scene, $user);

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

	public function get_fields($action='login', $for='admin'){
		if($action == 'bind'){
			$user_id	= get_current_user_id();

			if($openid = $this->get_openid($user_id)){
				$view	= ($avatar = $this->get_avatarurl($openid)) ? '<img src="'.str_replace('/132', '/0', $avatar).'" width="272" />'."<br />" : '';
				$view	.= ($nickname = $this->get_nickname($openid)) ? '<strong>'.$nickname.'</strong>' : '';

				return [
					'view'		=> ['type'=>'view',		'title'=>'绑定的微信账号',	'value'=>($view ?: $openid)],
					'action'	=> ['type'=>'hidden',	'value'=>'unbind'],
				];
			}

			$args	= [md5('bind_'.$user_id), ['id'=>$user_id]];
			$title	= '一键绑定';
		}else{
			$args	= [wp_generate_password(32, false, false)];
			$title	= '一键登录';
		}

		$qrcode	= $this->create_qrcode(...$args);

		return is_wp_error($qrcode) ? $qrcode : [
			'qrcode'	=> ['type'=>'view',		'title'=>'微信扫码，'.$title,	'value'=>'<img src="'.(wpjam_get($qrcode, 'qrcode_url') ?: wpjam_get($qrcode, 'qrcode')).'" width="272" />'],
			'code'		=> ['type'=>'number',	'title'=>'验证码',	'class'=>'input',	'required', 'size'=>20],
			'scene'		=> ['type'=>'hidden',	'value'=>$qrcode['scene']],
			'action'	=> ['type'=>'hidden',	'value'=>$action],
		];
	}
}