<?php
/*
Name: SMTP 发信
URI: https://mp.weixin.qq.com/s/SbuvSL01hT3Jxp9doWZ8zg
Description: SMTP 发信可以让你使用第三方邮箱的 SMTP 服务来发送邮件。
Version: 2.0
*/
class WPJAM_SMTP extends WPJAM_Option_Model{
	public static function get_fields(){
		return [
			'smtp_setting'		=> ['title'=>'SMTP 设置',	'type'=>'fieldset','fields'=>[
				'host'	=> ['title'=>'地址',		'type'=>'text',		'class'=>'all-options',	'value'=>'smtp.qq.com'],
				'ssl'	=> ['title'=>'发送协议',	'type'=>'text',		'class'=>'',			'value'=>'ssl'],
				'port'	=> ['title'=>'SSL端口',	'type'=>'number',	'class'=>'',			'value'=>'465'],
				'user'	=> ['title'=>'邮箱账号',	'type'=>'email',	'class'=>'all-options'],
				'pass'	=> ['title'=>'邮箱密码',	'type'=>'password',	'class'=>'all-options'],
			]],
			'mail_from_name'	=> ['title'=>'发送者姓名',	'type'=>'text',	'class'=>''],
			'reply_to_mail'		=> ['title'=>'回复地址',		'type'=>'email','class'=>'all-options',	'description'=>'不填则用户回复使用SMTP设置中的邮箱账号']
		];
	}

	public static function get_menu_page(){
		return [
			'parent'		=> 'wpjam-basic',
			'page_title'	=> 'SMTP 发信',
			'network'		=> false,
			'summary'		=> __FILE__,
			'function'		=> 'tab',
			'tabs'			=> [self::class, 'get_tabs']
		];
	}

	public static function get_form(){
		return [
			'submit_text'	=> '发送',
			'callback'		=> fn($data)=> wp_mail($data['to'], $data['subject'], $data['message']),
			'validate'		=> true,
			'fields'		=> [
				'to'		=> ['title'=>'收件人',	'type'=>'email',	'required'],
				'subject'	=> ['title'=>'主题',		'type'=>'text',		'required'],
				'message'	=> ['title'=>'内容',		'type'=>'textarea',	'class'=>'',	'rows'=>8,	'required'],
			]
		];
	}

	public static function get_tabs(){
		return [
			'smtp'	=> ['title'=>'发信设置',	'function'=>'option'],
			'send'	=> ['title'=>'发送测试',	'function'=>'form',	'form'=>[self::class, 'get_form']]
		];
	}

	public static function on_phpmailer_init($phpmailer){
		if(!wpjam_every(['host', 'user', 'pass'], fn($k)=> self::get_setting($k))){
			return;
		}

		$phpmailer->Mailer		= 'smtp';
		$phpmailer->SMTPAuth	= true;
		$phpmailer->SMTPSecure	= self::get_setting('ssl', 'ssl');
		$phpmailer->Host		= self::get_setting('host'); 
		$phpmailer->Port		= self::get_setting('port', '465');
		$phpmailer->Username	= self::get_setting('user');
		$phpmailer->Password	= self::get_setting('pass');

		$from_name	= self::get_setting('mail_from_name', '');
		$reply_to	= self::get_setting('reply_to_mail');

		$phpmailer->setFrom(self::get_setting('user'), $from_name, false);

		if($reply_to){
			$phpmailer->AddReplyTo($reply_to, $from_name);
		}
	}

	public static function add_hooks(){
		add_action('phpmailer_init',	[self::class, 'on_phpmailer_init']);

		if(wp_doing_ajax() || wpjam_is_json_request()){
			add_action('wp_mail_failed', 'wpjam_send_json');
		}
	}
}

wpjam_register_option('wpjam-smtp',	[
	'model'	=> 'WPJAM_SMTP',
	'title'	=> '发信设置'
]);