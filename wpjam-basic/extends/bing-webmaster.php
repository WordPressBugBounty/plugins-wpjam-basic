<?php
/*
Name: Bing 站长工具
URI: https://blog.wpjam.com/m/bing-webmaster/
Description: Bing 站长工具扩展实现提交链接到 Microsoft Bing，让博客的文章能够更快被 Bing 收录。
Version: 1.0
*/
class WPJAM_Bing_Webmaster extends WPJAM_Option_Model{
	public static function get_fields(){
		return [
			'bing_site_url'	=> ['title'=>'站点',	'type'=>'url',	'class'=>'all-options',	'value'=>untrailingslashit(site_url())],
			'bing_api_key'	=> ['title'=>'密钥',	'type'=>'password'],
		];
	}

	public static function get_set_fields(){
		$fields	= self::get_fields();

		foreach($fields as $key => &$field){
			$field['value']	= self::get_setting($key);
		}

		return $fields;
	}

	public static function get_batch_fields(){
		return ['view'	=> [
			'type'=>'view',
			'value'=>'已设置 Bing Webmaster 的站点和密钥（'.wpjam_get_page_button('set_bing_webmaster', ['button_text' => '修改', 'class'=>'']).'），可以使用 Bing 站长工具更新内容接口批量将博客中的所有内容都提交给 Bing 站长平台。'
		]];
	}

	public static function get_menu_page(){
		$tab_page	= [
			'tab_slug'		=> 'bing-webmaster',
			'plugin_page'	=> 'wpjam-seo',
			'summary'		=> __FILE__,
		];

		if(self::submittable()){
			wpjam_register_page_action('set_bing_webmaster', [
				'title' 		=> '设置',
				'submit_text'	=> '设置',
				'validate'		=> true,
				'callback'		=> [self::class, 'set'],
				'fields'		=> [self::class, 'get_set_fields'],
				'response'		=> 'redirect'
			]);

			return array_merge($tab_page, [
				'function'		=> 'form',
				'submit_text'	=> '批量提交',
				'callback'		=> [self::class, 'batch_submit'],
				'fields'		=> [self::class, 'get_batch_fields'],
			]);
		}else{
			return array_merge($tab_page, [
				'function'		=> 'option',
				'option_name'	=> 'wpjam-seo',
			]);
		}
	}

	public static function get_admin_load(){
		if(self::submittable()){
			return [
				'base'	=> ['post','edit'], 
				'model'	=> self::class
			];
		}
	}

	public static function submittable(){
		$keys	= array_keys(self::get_fields());

		if(self::get_setting($keys[0]) === null){
			foreach($keys as $key){
				$value	= wpjam_get_setting('bing-webmaster', wpjam_remove_prefix($key, 'bing_')) ?: '';

				self::update_setting($key, $value);
			}

			delete_option('bing-webmaster');
		}

		return self::get_setting('bing_site_url') && self::get_setting('bing_api_key');
	}

	public static function set($data){
		foreach(self::get_fields() as $key => $field){
			$value	= $data[$key] ?? '';

			self::update_setting($key, $value);
		}

		return self::submittable() ? true : $GLOBALS['current_admin_url'];
	}

	public static function submit($urls){
		if(!self::submittable()){
			return;
		}

		$site_url	= untrailingslashit(self::get_setting('bing_site_url'));
		$api_key	= self::get_setting('bing_api_key');
		$current	= untrailingslashit(site_url());

		if($current != $site_url){
			foreach($urls as &$url){
				$url	= str_replace($current, $site_url, $url);
			}
		}

		$api_url	= 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey='.$api_key;
		// $api_url	= 'https://www.bing.com/webmaster/api.svc/json/WPSubmitUrl?apikey='.$api_key;

		$err_args	= ['errcode'=>'ErrorCode', 'errmsg'=>'Message'];

		return wpjam_remote_request($api_url, [
			'json_encode'	=> true,
			'sslverify'		=> false,
			'blocking'		=> false,
			'body'			=> ['siteUrl'=>$site_url, 'urlList'=>$urls]
		], $err_args);
	}

	public static function submit_post($post_id){
		if(!self::submittable()){
			return;
		}

		if(is_array($post_id)){
			$wp_error	= false;
			$post_ids	= $post_id;
		}else{
			$wp_error	= wp_doing_ajax();
			$post_ids	= [$post_id];
		}
		
		$urls	= [];

		foreach($post_ids as $post_id){
			if(get_post_status($post_id) == 'publish'){
				if(wp_cache_get($post_id, 'bing_webmaster_notified') === false){
					wp_cache_set($post_id, true, 'bing_webmaster_notified', HOUR_IN_SECONDS);

					$urls[]	= get_permalink($post_id);
				}else{
					if($wp_error){
						wp_die('一小时内已经提交过了');
					}
				}
			}else{
				if($wp_error){
					wp_die('未发布的文章不能同步到 Bing');
				}
			}
		}

		if(!$urls){
			if($wp_error){
				wp_die('没有需要提交到 Bing 的链接');
			}else{
				return true;
			}
		}

		return self::submit($urls);
	}

	public static function batch_submit(){
		$submited	= (int)self::get_setting('submited');

		if(time() - (int)self::get_setting('last') < DAY_IN_SECONDS){
			if($submited == -1){
				return ['notice_type'=>'info', 'errmsg'=>'所有页面都已提交'];
			}else{
				wp_die('批量提交的配额已用完，请稍后重试');
			}
		}

		if($submited == -1){
			$submited	= 0;
		}

		$per_page	= 500;
		$per_day	= 9000;
		$offset		= (int)wpjam_get_data_parameter('offset', ['default'=>0]);

		if($offset >= $per_day){
			self::update_setting('last', time());
			self::update_setting('submited', $submited+$offset-$per_page);

			wp_die('今日批量提交的配额已用完，请明日接着提交');
		}

		$query		= new WP_Query([
			'post_type'			=> 'any',
			'post_status'		=> 'publish',
			'order'				=> 'ASC',
			'posts_per_page'	=> 500,
			'fields'			=> 'ids',
			'offset'			=> $offset+$submited
		]);

		if($query->have_posts()){
			self::submit_post($query->posts);

			$count	= count($query->posts);
		}else{
			$count	= 0;
		}

		$number	= $offset+$count;

		if($count < $per_page){
			self::update_setting('last', time());
			self::update_setting('submited', -1);

			return [
				'notice_type'	=> 'success',
				'errmsg'		=> '提交成功，本次提交了'.$number.'个页面。',
			];
		}else{
			return [
				'done'			=> 0,
				'errmsg'		=> '批量提交中，请勿关闭浏览器，本次提交了'.$number.'个页面。',
				'notice_type'	=> 'info',
				'args'			=> http_build_query(['offset'=>$number])
			];
		}
	}

	public static function on_after_insert_post($post_id, $post, $update, $post_before){
		if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) 
			|| $post->post_status != 'publish' 
			|| !current_user_can('edit_post', $post_id)
		){
			return;
		}

		self::submit_post($post_id);
	}

	public static function on_publish_future_post($post_id){
		self::submit_post($post_id);
	}

	public static function builtin_page_load($screen){
		if($screen->base == 'edit'){
			if(is_post_type_viewable($screen->post_type)){
				wpjam_register_list_table_action('submit_bing', [
					'title'			=> '提交到 Bing',
					'post_status'	=> ['publish'],
					'callback'		=> [self::class, 'submit_post'],
					'bulk_callback'	=> [self::class, 'submit_post'],
					'row_action'	=> false,
					'bulk'			=> true,
					'direct'		=> true
				]);
			}
		}elseif($screen->base == 'post'){
			if(is_post_type_viewable($screen->post_type)){
				add_action('wp_after_insert_post',	[self::class, 'on_after_insert_post'], 10, 4);
			}
		}
	}

	public static function add_hooks(){
		add_action('publish_future_post',	[self::class, 'on_publish_future_post'], 11);
	}
}

wpjam_register_option('wpjam-seo',	[
	'plugin_page'	=> 'wpjam-seo',
	'current_tab'	=> 'bing-webmaster',
	'model'			=> 'WPJAM_Bing_Webmaster',
	'title'			=> 'Bing Webmaster',
	'ajax'			=> false
]);