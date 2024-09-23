<?php
/*
Name: 百度站长
URI: https://mp.weixin.qq.com/s/_nPXcLPS2pFZZVhCH9SNaQ
Description: 百度站长扩展实现主动，被动，自动以及批量方式提交链接到百度站长，让博客的文章能够更快被百度收录。
Version: 1.0
*/
class WPJAM_Baidu_ZZ extends WPJAM_Option_Model{
	public static function submittable(){
		return self::get_setting('baidu_zz_site') && self::get_setting('baidu_zz_token');
	}

	public static function get_fields(){
		return [
			'baidu_zz_site'		=> ['title'=>'站点 (site)',	'type'=>'url',	'required',	'class'=>'all-options'],
			'baidu_zz_token'	=> ['title'=>'密钥 (token)',	'type'=>'text',	'required'],
		];
	}

	public static function get_menu_page(){
		$tab_page	= [
			'tab_slug'		=> 'baidu-zz',
			'plugin_page'	=> 'wpjam-seo',
			'summary'		=> __FILE__,
		];

		if(self::submittable()){
			wpjam_register_page_action('set_baidu_zz', [
				'title' 			=> '设置',
				'submit_text'		=> '设置',
				'validate'			=> true,
				'value_callback'	=> [self::class, 'get_setting'],
				'callback'			=> [self::class, 'update_setting'],
				'fields'			=> [self::class, 'get_fields'],
			]);

			return array_merge($tab_page, [
				'function'		=> 'form',
				'submit_text'	=> '批量提交',
				'callback'		=> [self::class, 'batch_submit'],
				'fields'		=> fn()=> ['view'=> [
					'type'	=> 'view',
					'value'	=> '已设置百度站长的站点和密钥（'.wpjam_get_page_button('set_baidu_zz', ['button_text'=>'修改', 'class'=>'']).'），可以使用百度站长更新内容接口批量将博客中的所有内容都提交给百度搜索资源平台。'
				]],
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

	public static function submit($urls, $type=''){
		if(!$urls || !self::submittable()){
			return;
		}

		$site	= self::get_setting('baidu_zz_site');
		$token	= self::get_setting('baidu_zz_token');
		$args	= array_merge(compact('site', 'token'), $type ? ['type'=>$type] : []);

		if(is_array($urls)){
			$current	= parse_url(site_url(), PHP_URL_HOST);
			$urls		= $current != $site ? wpjam_map($urls, fn($url)=> str_replace($current, $site, $url)) : $urls;
			$urls		= implode("\n", $urls);
		}

		return wpjam_remote_request(add_query_arg($args, 'http://data.zz.baidu.com/urls'), [
			'headers'	=> ['Accept-Encoding'=>'', 'Content-Type'=>'text/plain'],
			'sslverify'	=> false,
			'body'		=> $urls
		]);
	}

	public static function submit_post($post_id, $type=''){
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

		foreach($post_ids as $post_id){
			if(get_post_status($post_id) == 'publish'){
				if(wpjam_lock('baidu_zz_notified:'.$post_id)){
					if($wp_error){
						wp_die('一小时内已经提交过了');
					}
				}

				$urls[]	= get_permalink($post_id);
			}else{
				if($wp_error){
					wp_die('未发布的文章不能同步到百度站长');
				}
			}
		}

		return $urls ? self::submit($urls, $type) : ($wp_error ? wp_die('没有需要提交到百度站长的链接') : true);
	}

	public static function batch_submit(){
		$submited	= (int)self::get_setting('baidu_zz_submited');

		if(time() - (int)self::get_setting('baidu_zz_last') < DAY_IN_SECONDS){
			return $submited == -1 ? ['notice_type'=>'info', 'errmsg'=>'所有页面都已提交'] : wp_die('批量提交的配额已用完，请稍后重试');
		}

		if($submited == -1){
			$submited	= 0;
		}

		$per_page	= 500;
		$offset		= (int)wpjam_get_data_parameter('offset',	['default'=>0]);
		$query		= new WP_Query([
			'post_type'			=> 'any',
			'post_status'		=> 'publish',
			'order'				=> 'ASC',
			'fields'			=> 'ids',
			'posts_per_page'	=> $per_page,
			'offset'			=> $offset
		]);

		if($query->have_posts()){
			$result	= self::submit_post($query->posts);
			$count	= count($query->posts);
			$number	= $offset+$count;

			if(is_array($result) && $result['remain'] <= 500){
				self::update_setting('baidu_zz_last', time());
				self::update_setting('baidu_zz_submited', $submited+$offset-$per_page);

				wp_die('今日提交了'.$number.'个页面，批量提交的配额已用完，请明日接着提交');
			}
		}else{
			$count	= 0;
			$number	= $offset+$count;
		}

		if($count < $per_page){
			self::update_setting('baidu_zz_last', time());
			self::update_setting('baidu_zz_submited', -1);

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

	public static function on_after_insert_post($post_id, $post){
		if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || get_post_status($post) != 'publish' || !current_user_can('edit_post', $post_id)){
			return;
		}

		self::submit_post($post_id, (wpjam_get_post_parameter('baidu_zz_daily') ? 'daily' : ''));
	}

	public static function on_post_submitbox_misc_actions(){ ?>
		<div class="misc-pub-section" id="baidu_zz_section">
			<input type="checkbox" name="baidu_zz_daily" id="baidu_zz" value="1">
			<label for="baidu_zz_daily">提交给百度站长快速收录</label>
		</div>
	<?php }

	public static function builtin_page_load($screen){
		if($screen->base == 'edit'){
			if(is_post_type_viewable($screen->post_type)){
				wpjam_register_list_table_action('notify_baidu_zz', [
					'title'			=> '提交到百度',
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
				add_action('wp_after_insert_post',			[self::class, 'on_after_insert_post'], 10, 2);
				add_action('post_submitbox_misc_actions',	[self::class, 'on_post_submitbox_misc_actions'],11);

				wp_add_inline_style('list-tables', '#post-body #baidu_zz_section:before{content: "\f103"; color:#82878c; font: normal 20px/1 dashicons; speak: none; display: inline-block; margin-left: -1px; padding-right: 3px; vertical-align: top; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }');
			}
		}
	}

	public static function add_hooks(){
		add_action('publish_future_post',	fn($post_id)=> self::submit_post($post_id), 11);
	}
}

wpjam_register_option('wpjam-seo',	[
	'plugin_page'	=> 'wpjam-seo',
	'current_tab'	=> 'baidu-zz',
	'model'			=> 'WPJAM_Baidu_ZZ',
	'title'			=> '百度站长',
	'ajax'			=> false
]);	