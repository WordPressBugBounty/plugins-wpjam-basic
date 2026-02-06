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
	public static function callback(...$args){
		$id		= $args[1]['id'];
		$items	= [self::class, $GLOBALS['current_tab']]($id);

		if($id == 'usage'){
			foreach($items as $item){
				echo '<hr />';

				$unit	= wpjam_pull($item[1], 'unit') ?: 1;
				$data	= wpjam_map(wpjam_pull($item[1], 'labels'), fn($v, $k) => ['label'=>$v, 'count'=>round($item[0][$k]/$unit, 2)]);

				echo wpjam_chart('donut', $data, $item[1]+['total'=>true, 'chart_width'=>150, 'table_width'=>320]);
			}
		}else{
			?>
			<table class="widefat striped" style="border:none;">
				<tbody><?php foreach($items as $item){ ?>
					<tr><?php foreach($item as $i){ ?>
						<td><?php echo $i; ?></td>
					<?php } ?></tr>
				<?php } ?></tbody>
			</table>
			<?php
		}
	}

	public static function server($id){
		if($id == 'server'){
			$items[]	= ['服务器',		gethostname().'（'.$_SERVER['HTTP_HOST'].'）'];
			$items[]	= ['服务器IP',	'内网：'.gethostbyname(gethostname())];
			$items[]	= ['系统',		php_uname('s')];

			if(strpos(ini_get('open_basedir'), ':/proc') !== false){
				if(@is_readable('/proc/cpuinfo')){
					$cpus	= wpjam_lines(file_get_contents('/proc/cpuinfo'), "\n\n");
					$base[]	= count($cpus).'核';
				}
				
				if(@is_readable('/proc/meminfo')){
					$mems	= wpjam_lines(file_get_contents('/proc/meminfo'));
					$mem	= (int)substr(array_find($mems, fn($m) => str_starts_with($m, 'MemTotal:')), 9);
					$base[]	= round($mem/1024/1024).'G';
				}

				if(!empty($base)){
					$items[]	= ['配置',	'<strong>'.implode('&nbsp;/&nbsp;', $base).'</strong>'];
				}
			
				if(@is_readable('/proc/meminfo')){
					$uptime		= wpjam_lines(file_get_contents('/proc/uptime'), ' ');
					$items[]	= ['运行时间',	human_time_diff(time()-$uptime[0])];
				}

				
				$items[]	= ['空闲率',		round($uptime[1]*100/($uptime[0]*count($cpus)), 2).'%'];
				$items[]	= ['系统负载',	'<strong>'.implode('&nbsp;&nbsp;',sys_getloadavg()).'</strong>'];
			}

			$items[]	= ['文档根目录',	$_SERVER['DOCUMENT_ROOT']];
			
			return $items;
		}elseif($id == 'version'){
			return [
				[wpjam_lines($_SERVER['SERVER_SOFTWARE'], '/')[0],	$_SERVER['SERVER_SOFTWARE']],
				['MySQL',		$GLOBALS['wpdb']->db_version().'（最低要求：'.$GLOBALS['required_mysql_version'].'）'],
				['PHP',			phpversion().'（最低要求：'.$GLOBALS['required_php_version'].'）'],
				['Zend',		Zend_Version()],
				['WordPress',	$GLOBALS['wp_version'].'（'.$GLOBALS['wp_db_version'].'）'],
				['TinyMCE',		$GLOBALS['tinymce_version']]
			];
		}elseif($id == 'php'){
			return [[implode('&emsp;', get_loaded_extensions())]];
		}elseif($id == 'apache'){
			return [[implode('&emsp;', apache_get_modules())]];
		}
	}

	public static function opcache($id){
		if($id == 'usage'){
			echo '<p>'.wpjam_get_page_button('reset_opcache').'</p>';

			$status	= opcache_get_status();
			$rest	= $status['opcache_statistics']['max_cached_keys']-$status['opcache_statistics']['num_cached_keys'];

			return [
				[$status['memory_usage'], [
					'title'		=> '内存使用',
					'labels'	=> ['used_memory'=>'已用内存', 'free_memory'=>'剩余内存', 'wasted_memory'=>'浪费内存'],
					'unit'		=> 1024*1024
				]],
				[$status['opcache_statistics'], [
					'title'		=> '命中率',
					'labels'	=> ['hits'=>'命中', 'misses'=>'未命中']
				]],
				[$status['opcache_statistics']+['rest_cached_keys'=>$rest], [
					'title'		=> '存储Keys',
					'labels'	=> ['num_cached_keys'=>'已用Keys', 'rest_cached_keys'=>'剩余Keys']
				]]
			];
		}elseif($id == 'status'){
			return wpjam_entries(opcache_get_status()['opcache_statistics']);
		}elseif($id == 'configuration'){
			$config = opcache_get_configuration();
		
			return array_reduce([$config['version'], wpjam_array($config['directives'], fn($k)=> str_replace('opcache.', '', $k))], fn($c, $v)=> array_merge($c, wpjam_entries($v)), []);
		}
	}

	public static function memcached($id){
		foreach($GLOBALS['wp_object_cache']->get_stats() as $key => $stats){
			if($id == 'usage'){
				echo '<p>'.wpjam_get_page_button('flush_mc').'</p>';

				return [
					[$stats, [
						'title'		=> '命中率',
						'labels'	=> ['get_hits'=>'命中次数', 'get_misses'=>'未命中次数'],
					]],
					[$stats+['rest'=>$stats['limit_maxbytes']-$stats['bytes']], [
						'title'		=> '内存使用',
						'labels'	=> ['bytes'=>'已用内存', 'rest'=>'剩余内存'],
						'unit'		=> 1024*1024
					]]
				];
			}elseif($id == 'status'){
				return [
					['Memcached地址',	$key],
					['Memcached版本',	$stats['version']],
					['进程ID',			$stats['pid']],
					['启动时间',			wpjam_date('Y-m-d H:i:s',($stats['time']-$stats['uptime']))],
					['运行时间',			human_time_diff(0,$stats['uptime'])],
					['已用/分配的内存',	size_format($stats['bytes']).' / '.size_format($stats['limit_maxbytes'])],
					['当前/启动后总数量',	$stats['curr_items'].' / '.$stats['total_items']],
					['为获取内存踢除数量',	$stats['evictions']],
					['当前/总打开连接数',	$stats['curr_connections'].' / '.$stats['total_connections']],
					['总命中次数',		$stats['get_hits']],
					['总未命中次数',		$stats['get_misses']],
					['总获求次数',		$stats['cmd_get']],
					['总请求次数',		$stats['cmd_set']],
					['Item平均大小',		size_format($stats['bytes']/$stats['curr_items'])],
				];
			}elseif($id == 'efficiency'){
				return wpjam_map(['get_hits'=>'命中', 'get_misses'=>'未命中', 'cmd_get'=>'获取', 'cmd_set'=>'设置'], fn($v, $k)=> ['每秒'.$v.'次数', round($stats[$k]/$stats['uptime'])]);
			}elseif($id == 'options'){
				return wpjam_array(wpjam_get_reflection(['Memcached'], 'Constants'), fn($k, $v)=> str_starts_with($k, 'OPT_') ? [$k, [$k, $GLOBALS['wp_object_cache']->get_mc()->getOption($v)]] : null);
			}
		}
	}

	public static function get_tabs(){
		$parse	= fn($items)=> array_map(fn($v)=> $v+['callback'=>[self::class, 'callback']], $items);
		$tabs	= ['server'=>['title'=>'服务器', 'function'=>'dashboard', 'widgets'=>$parse([
			'server'	=> ['title'=>'信息'],
			'php'		=> ['title'=>'PHP扩展'],
			'version'	=> ['title'=>'版本',			'context'=>'side'],
			'apache'	=> ['title'=>'Apache模块',	'context'=>'side']
		])]];

		if(strtoupper(substr(PHP_OS,0,3)) === 'WIN'){
			unset($tabs['server']['widgets']['server']);
		}

		if(!$GLOBALS['is_apache'] || !function_exists('apache_get_modules')){
			unset($tabs['server']['widgets']['apache']);
		}

		if(function_exists('opcache_get_status')){
			$tabs['opcache']	= ['title'=>'Opcache',	'function'=>'dashboard',	'widgets'=>$parse([
				'usage'			=> ['title'=>'使用率'],
				'status'		=> ['title'=>'状态'],
				'configuration'	=> ['title'=>'配置信息',	'context'=>'side']
			])];

			wpjam_register_page_action('reset_opcache', [
				'title'			=> '重置缓存',
				'button_text'	=> '重置缓存',
				'direct'		=> true,
				'confirm'		=> true,
				'callback'		=> fn() => opcache_reset() ? ['notice'=>'缓存重置成功'] : wp_die('缓存重置失败')
			]);
		}

		if(method_exists('WP_Object_Cache', 'get_mc')){
			$tabs['memcached']	= ['title'=>'Memcached',	'function'=>'dashboard',	'widgets'=>$parse([
				'usage'			=> ['title'=>'使用率'],
				'efficiency'	=> ['title'=>'效率'],
				'options'		=> ['title'=>'选项', 'context'=>'side'],
				'status'		=> ['title'=>'状态']
			])];

			wpjam_register_page_action('flush_mc', [
				'title'			=> '刷新缓存',
				'button_text'	=> '刷新缓存',
				'direct'		=> true,
				'confirm'		=> true,
				'callback'		=> fn() => wp_cache_flush() ? ['notice'=>'缓存刷新成功'] : wp_die('缓存刷新失败')
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
	'model'			=> 'WPJAM_Server_Status',
	'capability'	=> is_multisite() ? 'manage_site' : 'manage_options',
]);