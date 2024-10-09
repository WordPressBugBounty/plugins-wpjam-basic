<?php
/*
Name: 系统信息
URI: https://mp.weixin.qq.com/s/kqlag2-RWn_n481R0QCJHw
Description: 系统信息让你在后台一个页面就能够快速实时查看当前的系统状态。
Version: 2.0
*/
if(!is_admin()){
	return;
}

class WPJAM_Server_Status{
	public static function server_widget(){
		$items[]	= ['title'=>'服务器',		'value'=>gethostname().'（'.$_SERVER['HTTP_HOST'].'）'];
		$items[]	= ['title'=>'服务器IP',	'value'=>'内网：'.gethostbyname(gethostname())];
		$items[]	= ['title'=>'系统',		'value'=>php_uname('s')];

		if(strpos(ini_get('open_basedir'), ':/proc') !== false){
			if(@is_readable('/proc/cpuinfo')){
				$cpus	= explode("\n\n", trim(file_get_contents('/proc/cpuinfo')));
				$base[]	= count($cpus).'核';
			}
			
			if(@is_readable('/proc/meminfo')){
				$mems	= explode("\n", trim(file_get_contents('/proc/meminfo')));
				$mem	= (int)wpjam_remove_prefix(wpjam_find($mems, fn($m) => str_starts_with($m, 'MemTotal:')), 'MemTotal:');
				$base[]	= round($mem/1024/1024).'G';
			}

			if(!empty($base)){
				$items[]	= ['title'=>'配置',	'value'=>'<strong>'.implode('&nbsp;/&nbsp;', $base).'</strong>'];
			}
		
			if(@is_readable('/proc/meminfo')){
				$uptime		= explode(' ', trim(file_get_contents('/proc/uptime')));
				$items[]	= ['title'=>'运行时间',	'value'=>human_time_diff(time()-$uptime[0])];
			}

			
			$items[]	= ['title'=>'空闲率',		'value'=>round($uptime[1]*100/($uptime[0]*count($cpus)), 2).'%'];
			$items[]	= ['title'=>'系统负载',	'value'=>'<strong>'.implode('&nbsp;&nbsp;',sys_getloadavg()).'</strong>'];
		}

		$items[]	= ['title'=>'文档根目录',	'value'=>$_SERVER['DOCUMENT_ROOT']];
		
		self::output($items);
	}

	public static function php_widget(){
		self::output([['value'=>implode(', ', get_loaded_extensions())]]);
	}

	public static function apache_widget(){
		self::output([['value'=>implode(', ', apache_get_modules())]]);
	}

	public static function version_widget(){
		global $wpdb, $required_mysql_version, $required_php_version, $wp_version,$wp_db_version, $tinymce_version;

		$http_server	= explode('/', $_SERVER['SERVER_SOFTWARE'])[0];

		self::output([
			['title'=>$http_server,	'value'=>$_SERVER['SERVER_SOFTWARE']],
			['title'=>'MySQL',		'value'=>$wpdb->db_version().'（最低要求：'.$required_mysql_version.'）'],
			['title'=>'PHP',		'value'=>phpversion().'（最低要求：'.$required_php_version.'）'],
			['title'=>'Zend',		'value'=>Zend_Version()],
			['title'=>'WordPress',	'value'=>$wp_version.'（'.$wp_db_version.'）'],
			['title'=>'TinyMCE',	'value'=>$tinymce_version]
		]);
	}

	public static function opcache_status_widget(){
		self::output(wpjam_map(opcache_get_status()['opcache_statistics'], fn($v, $k) => ['title'=>$k, 'value'=>$v]));
	}

	public static function opcache_usage_widget(){
		echo '<p>'.wpjam_get_page_button('reset_opcache').'</p>';

		echo '<hr />';

		$status	= opcache_get_status();
		$args 	= ['chart_width'=>150, 'table_width'=>320];

		$labels	= ['used_memory'=>'已用内存', 'free_memory'=>'剩余内存', 'wasted_memory'=>'浪费内存'];
		$counts	= wpjam_map($labels, fn($v, $k) => ['label'=>$v,	'count'=>round($status['memory_usage'][$k]/(1024*1024),2)]);
		$total	= round(array_reduce(array_keys($labels), fn($total, $k) => $total+$status['memory_usage'][$k], 0)/(1024*1024),2);

		wpjam_donut_chart($counts, ['title'=>'内存使用', 'total'=>$total]+$args);

		echo '<hr />';

		$labels	= ['hits'=>'命中', 'misses'=>'未命中'];
		$counts	= wpjam_map($labels, fn($v, $k) => ['label'=>$v,	'count'=>$status['opcache_statistics'][$k]]);
		$total	= array_reduce(array_keys($labels), fn($total, $k) => $total+$status['opcache_statistics'][$k], 0);

		wpjam_donut_chart($counts, ['title'=>'命中率', 'total'=>$total]+$args);

		echo '<hr />';

		$counts	= [
			['label'=>'已用Keys',	'count'=>$status['opcache_statistics']['num_cached_keys']],
			['label'=>'剩余Keys',	'count'=>$status['opcache_statistics']['max_cached_keys']-$status['opcache_statistics']['num_cached_keys']]
		];

		$total	= $status['opcache_statistics']['max_cached_keys'];

		wpjam_donut_chart($counts, ['title'=>'存储Keys','total'=>$total]+$args);

		// echo '<hr />';

		// $labels	= ['used_memory'=>'已用内存', 'free_memory'=>'剩余内存'];
		// $counts	= wpjam_map($labels, fn($v, $k) => ['label'=>$v,	'count'=>round($status['interned_strings_usage'][$k]/(1024*1024),2)]);
		// $total	= round(array_reduce(array_keys($labels), fn($total, $k) => $total+$status['interned_strings_usage'][$k], 0)/(1024*1024),2);

		// wpjam_donut_chart($counts, ['title'=>'临时字符串存储内存','total'=>$total]+$args);
	}

	public static function opcache_configuration_widget(){
		$config = opcache_get_configuration();
		$items	= wpjam_map($config['version'], fn($v, $k) => ['title'=>$k, 'value'=>$v]);
		$items	= array_merge($items,  wpjam_map($config['directives'], fn($v, $k) => ['title'=>str_replace('opcache.', '', $k), 'value'=>$v]));
	
		self::output($items);
	}

	public static function memcached_usage_widget(){
		global $wp_object_cache;

		echo '<p>'.wpjam_get_page_button('flush_mc').'</p>';

		foreach($wp_object_cache->get_stats() as $key => $details){
			echo '<hr />';

			$args	= ['chart_width'=>150,'table_width'=>320];
			$labels	= ['get_hits'=>'命中次数', 'get_misses'=>'未命中次数'];
			$counts	= wpjam_map($labels, fn($v, $k) => ['label'=>$v,	'count'=>$details[$k]]);

			wpjam_donut_chart($counts, ['title'=>'命中率','total'=>$details['cmd_get']]+$args);

			echo '<hr />';

			$counts	= [
				['label'=>'已用内存',	'count'=>round($details['bytes']/(1024*1024),2)],
				['label'=>'剩余内存',	'count'=>round(($details['limit_maxbytes']-$details['bytes'])/(1024*1024),2)]
			];

			$total	= round($details['limit_maxbytes']/(1024*1024),2);

			wpjam_donut_chart($counts, ['title'=>'内存使用','total'=>$total]+$args);
		}
	}

	public static function memcached_status_widget(){
		global $wp_object_cache;

		foreach($wp_object_cache->get_stats() as $key => $details){
			self::output([
				// ['title'=>'Memcached进程ID',	'value'=>$details['pid']],
				['title'=>'Memcached地址',	'value'=>$key],
				['title'=>'Memcached版本',	'value'=>$details['version']],
				['title'=>'启动时间',			'value'=>wpjam_date('Y-m-d H:i:s',($details['time']-$details['uptime']))],
				['title'=>'运行时间',			'value'=>human_time_diff(0,$details['uptime'])],
				['title'=>'已用/分配的内存',	'value'=>size_format($details['bytes']).' / '.size_format($details['limit_maxbytes'])],
				['title'=>'启动后总数量',		'value'=>$details['curr_items'].' / '.$details['total_items']],
				['title'=>'为获取内存踢除数量',	'value'=>$details['evictions']],
				['title'=>'当前/总打开连接数',	'value'=>$details['curr_connections'].' / '.$details['total_connections']],
				['title'=>'命中次数',			'value'=>$details['get_hits']],
				['title'=>'未命中次数',		'value'=>$details['get_misses']],
				['title'=>'总获取请求次数',	'value'=>$details['cmd_get']],
				['title'=>'总设置请求次数',	'value'=>$details['cmd_set']],
				['title'=>'Item平均大小',		'value'=>size_format($details['bytes']/$details['curr_items'])],
			]);
		}
	}

	public static function memcached_options_widget(){
		global $wp_object_cache;

		$reflector	= new ReflectionClass('Memcached');
		$constants	= wpjam_filter($reflector->getConstants(), fn($v, $k) => str_starts_with($k, 'OPT_'));
		$mc			= $wp_object_cache->get_mc();

		self::output(wpjam_map($constants, fn($v, $k) => ['title'=>$k, 'value'=>$mc->getOption($v)]));
	}

	public static function memcached_efficiency_widget(){
		global $wp_object_cache;

		foreach($wp_object_cache->get_stats() as $key => $details){
			self::output([
				['title'=>'每秒命中次数',		'value'=>round($details['get_hits']/$details['uptime'],2)],
				['title'=>'每秒未命中次数',	'value'=>round($details['get_misses']/$details['uptime'],2)],
				['title'=>'每秒获取请求次数',	'value'=>round($details['cmd_get']/$details['uptime'],2)],
				['title'=>'每秒设置请求次数',	'value'=>round($details['cmd_set']/$details['uptime'],2)],
			]);
		}
	}

	public static function output($items){
		?>
		<table class="widefat striped" style="border:none;">
			<tbody><?php foreach($items as $item){ ?>
				<tr><?php if(!empty($item['title'])){ ?>
					<td><?php echo $item['title'] ?></td>
					<td><?php echo $item['value'] ?></td>
				<?php }else{ ?>
					<td colspan="2"><?php echo $item['value'] ?></td>
				<?php } ?></tr>
			<?php } ?></tbody>
		</table>
		<?php
	}

	public static function get_tabs(){
		$tabs['server']	= ['title'=>'服务器', 'function'=>'dashboard', 'widgets'=>[
			'server'	=> ['title'=>'信息',			'callback'=>[self::class, 'server_widget']],
			'php'		=> ['title'=>'PHP扩展',		'callback'=>[self::class, 'php_widget']],
			'version'	=> ['title'=>'版本',			'callback'=>[self::class, 'version_widget'],	'context'=>'side'],
			'apache'	=> ['title'=>'Apache模块',	'callback'=>[self::class, 'apache_widget'],		'context'=>'side']
		]];

		if(strtoupper(substr(PHP_OS,0,3)) === 'WIN'){
			unset($tabs['server']['widgets']['server']);
		}

		if(!$GLOBALS['is_apache'] || !function_exists('apache_get_modules')){
			unset($tabs['server']['widgets']['apache']);
		}

		if(function_exists('opcache_get_status')){
			$tabs['opcache']	= ['title'=>'Opcache',	'function'=>'dashboard',	'widgets'=>[
				'usage'			=> ['title'=>'使用率',	'callback'=>[self::class, 'opcache_usage_widget']],
				'status'		=> ['title'=>'状态',		'callback'=>[self::class, 'opcache_status_widget']],
				'configuration'	=> ['title'=>'配置信息',	'callback'=>[self::class, 'opcache_configuration_widget'],	'context'=>'side']
			]];

			wpjam_register_page_action('reset_opcache', [
				'title'			=> '重置缓存',
				'button_text'	=> '重置缓存',
				'direct'		=> true,
				'confirm'		=> true,
				'callback'		=> fn() => opcache_reset() ? ['errmsg'=>'缓存重置成功'] : wp_die('缓存重置失败')
			]);
		}

		if(method_exists('WP_Object_Cache', 'get_mc')){
			$tabs['memcached']	= ['title'=>'Memcached',	'function'=>'dashboard',	'widgets'=>[
				'usage'			=> ['title'=>'使用率',	'callback'=>[self::class, 'memcached_usage_widget']],
				'efficiency'	=> ['title'=>'效率',		'callback'=>[self::class, 'memcached_efficiency_widget']],
				'options'		=> ['title'=>'选项',		'callback'=>[self::class, 'memcached_options_widget'], 'context'=>'side'],
				'status'		=> ['title'=>'状态',		'callback'=>[self::class, 'memcached_status_widget']]
			]];

			wpjam_register_page_action('flush_mc', [
				'title'			=> '刷新缓存',
				'button_text'	=> '刷新缓存',
				'direct'		=> true,
				'confirm'		=> true,
				'callback'		=> fn() => wp_cache_flush() ? ['errmsg'=>'缓存刷新成功'] : wp_die('缓冲刷新失败')
			]);
		}

		return $tabs;
	}
}

wpjam_add_menu_page('server-status', [
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> '系统信息',
	'summary'		=> __FILE__,
	'chart'			=> true,
	'order'			=> 9,
	'function'		=> 'tab',
	'capability'	=> is_multisite() ? 'manage_site':'manage_options',
	'tabs'			=> ['WPJAM_Server_Status', 'get_tabs']
]);