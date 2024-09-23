<?php
/*
Name: 用户角色
URI: https://mp.weixin.qq.com/s/NOOjbhtg6l4YhXGYZ9lBWg
Description: 用户角色管理，以及用户额外权限设置。
Version: 1.0
*/
class WPJAM_Role{
	public static function get_all(){
		return $GLOBALS['wp_roles']->roles;
	}

	public static function get($role){
		$data	= self::get_all()[$role] ?? [];

		if($data){
			$counts	= count_users()['avail_roles'];

			$data['role']			= $role;
			$data['capabilities']	= array_keys($data['capabilities']);
			$data['cap_count']		= count($data['capabilities']);
			$data['user_count']		= isset($counts[$role]) ? '<a href="'.admin_url('users.php?role='.$role).'">'.$counts[$role].'</a>' : 0;
		}

		return $data;
	}

	public static function set($data, $role=''){
		$data['capabilities']	= array_filter($data['capabilities']);
		$data['capabilities']	= array_fill_keys($data['capabilities'], 1);

		if($role){
			remove_role($role);

			$label	= '修改';
		}else{
			$role	= $data['role'];
			$label	= '新建';
		}
		
		$result	= add_role($role, $data['name'], $data['capabilities']);

		return is_null($result) ? new WP_Error('error', $label.'失败，可能重名或者其他原因。') : $role;
	}

	public static function insert($data){
		return self::set($data);
	}

	public static function update($role, $data){
		return self::set($data, $role);
	}

	public static function delete($role){
		if($role == 'administrator'){
			wp_die('不能删除超级管理员角色。');
		}

		return remove_role($role);
	}

	public static function reset(){
		require_once ABSPATH . 'wp-admin/includes/schema.php';

		wpjam_map(self::get_all(), fn($v, $k)=> remove_role($k));

		populate_roles();
	}

	public static function query_items($args){
		$counts	= count_users()['avail_roles'];

		return array_values(wpjam_map(self::get_all(), fn($v, $k)=> array_merge($v, [
			'role'			=> $k,
			'name'			=> translate_user_role($v['name']),
			'user_count'	=> isset($counts[$k]) ? '<a href="'.admin_url('users.php?role='.$k).'">'.$counts[$k].'</a>' : 0,
			'cap_count'		=> count($v['capabilities']),
		])));
	}

	public static function map_meta_cap($user_id, $args){
		if(isset($args[0]) && $args[0] === 'administrator' && (empty($args[1]) || $args[1] == 'delete')){
			return ['do_not_allow'];
		}
		
		return is_multisite() ? ['manage_site'] : ['manage_options'];
	}

	public static function get_fields($action_key='', $id=0){
		return [
			'role'			=> ['title'=>'角色',		'type'=>'text',		'show_admin_column'=>true],
			'name'			=> ['title'=>'名称',		'type'=>'text',		'show_admin_column'=>true],
			'capabilities'	=> ['title'=>'权限',		'type'=>'mu-text'],
			'user_count'	=> ['title'=>'用户数',	'type'=>'view',		'show_admin_column'=>'only'],
			'cap_count'		=> ['title'=>'权限',		'type'=>'view',		'show_admin_column'=>'only'],
		];
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',	'last'=>true],
			'edit'		=> ['title'=>'编辑'],
			'delete'	=> ['title'=>'删除',	'direct'=>true,	'confirm'=>true,	'bulk'=>true],
			'reset'		=> ['title'=>'重置',	'direct'=>true,	'confirm'=>true,	'overall'=>true]
		];
	}

	public static function get_additional($user, $output=''){
		$user		= is_object($user) ? $user : get_userdata($user);

		foreach($user->caps as $cap => $value){
			if($value && !$GLOBALS['wp_roles']->is_role($cap)){
				$caps[]	= $cap;
			}
		}

		$caps	??= [];

		if($output == 'fields'){
			return wpjam_fields(['capabilities'=> [
				'title'	=> '权限',
				'type'	=> 'mu-text',
				'value'	=> $caps
			]]);
		}

		return $caps;
	}

	public static function set_additional($user, $caps){
		$user		= is_object($user) ? $user : get_userdata($user);
		$current	= self::get_additional($user);
		$caps		= array_diff($caps, ['manage_sites', 'manage_options']);

		wpjam_map(array_diff($current, $caps), fn($cap)=> $user->remove_cap($cap));
		wpjam_map(array_diff($caps, $current), fn($cap)=> $user->add_cap($cap));
	}

	public static function get_list_table(){
		return [
			'singular'		=> 'wpjam-role',
			'plural'		=> 'wpjam-roles',
			'primary_key'	=> 'role',
			'capability'	=> 'edit_roles',
			'model'			=> self::class,
		];
	}

	public static function builtin_page_load($screen_base){
		$capability	= is_multisite() ? 'manage_sites' : 'manage_options';

		if(current_user_can($capability)){
			add_filter('additional_capabilities_display', '__return_false' );

			wpjam_map(['show_user_profile', 'edit_user_profile'], fn($v)=> add_action($v, fn($user)=> wpjam_echo('<h3>额外权限</h3>'.self::get_additional($user, 'fields'))));

			wpjam_map(['personal_options_update', 'edit_user_profile_update'], fn($v)=> add_action($v, fn($id)=> self::set_additional($id, wpjam_get_post_parameter('capabilities') ?: [])));
		}
	}
}

function wpjam_get_additional_capabilities($user){
	return WPJAM_Role::get_additional($user);
}

function wpjam_set_additional_capabilities($user, $caps){
	return WPJAM_Role::set_additional($user, $caps);
}

if(is_admin()){
	wpjam_add_menu_page('roles', [
		'plugin_page'	=> 'wpjam-user',
		'tab_slug'		=> 'roles',
		'title'			=> '角色管理',
		'order'			=> 8,
		'function'		=> 'list',
		'list_table'	=> 'WPJAM_Role',
		'capability'	=> 'edit_roles',
		'map_meta_cap'	=> ['WPJAM_Role', 'map_meta_cap']
	]);

	wpjam_add_admin_load([
		'base'	=> ['user-edit', 'profile'], 
		'model'	=> 'WPJAM_Role' 
	]);
}