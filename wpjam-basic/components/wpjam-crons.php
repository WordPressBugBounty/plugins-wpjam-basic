<?php
/*
Name: 定时作业
URI: https://mp.weixin.qq.com/s/mSqzZdslhxwkNHGRpa3WmA
Description: 定时作业让你可以可视化管理 WordPress 的定时作业
Version: 2.0
*/
class WPJAM_Cron extends WPJAM_Args{
	public function __invoke(){
		if(wpjam_lock($this->hook.'_lock', 10, true)){
			return;
		}

		$jobs	= $this->get_jobs();

		if($this->weight){
			$queue	= $jobs;
			$i		= 0;

			while($queue){
				$i++;

				$queue	= array_filter($queue, fn($job)=> is_array($job) && wpjam_get($job, 'weight') >= $i);
				$jobs	= array_merge($jobs, $queue);
			}
		}

		$count		= $this->get_count(true);
		$index		= $count % count($jobs);
		$callback	= $jobs[$index]['callback'];

		is_callable($callback) && $callback();
	}

	public function get_jobs(){
		return array_values(maybe_callback($this->jobs));
	}

	public function get_count($increment=false){
		$name	= $this->hook.'_counter:'.wpjam_date('Y-m-d');

		return $increment ? wpjam_increment($name) : (get_transient($name) ?: 0);
	}

	public static function cleanup(){
		$invalid	= 0;

		foreach(self::get_all() as $ts => $cron){
			foreach($cron as $hook => $dings){
				if(!has_filter($hook)){	// 系统不存在的定时作业，清理掉
					array_walk($dings, fn($data)=> wp_unschedule_event($ts, $hook, $data['args']));

					$invalid++;
				}
			}
		}

		return $invalid;
	}

	public static function get($id){
		$data	= wpjam_get(self::get_all(), $id);
		$args	= wpjam_lines($id, '[', fn($v)=> trim($v, ']'));

		return $data ? [
			'hook'		=> $args[1],
			'timestamp'	=> $args[0],
			'time'		=> wpjam_date('Y-m-d H:i:s', $args[0]),
			'cron_id'	=> $id,
			'schedule'	=> $data['schedule'] ?? '',
			'args'		=> $data['args'] ?? []
		] : [];
	}

	public static function insert($data){
		!has_filter($data['hook']) && wp_die('无效的 Hook');

		return wpjam_schedule($data['hook'], $data);
	}

	public static function do($id){
		return ($data = self::get($id)) ? (do_action_ref_array($data['hook'], $data['args']) || true) : true;
	}

	public static function delete($id){
		return ($data = self::get($id)) ? wp_unschedule_event($data['timestamp'], $data['hook'], $data['args']) : true;
	}

	public static function get_all(){
		return _get_cron_array() ?: [];
	}

	public static function query_items($args){
		if($GLOBALS['current_tab'] == 'crons'){
			foreach(self::get_all() as $ts => $cron){
				foreach($cron as $hook => $dings){
					foreach($dings as $key => $data){
						$items[] = [
							'cron_id'	=> $ts.'['.$hook.']['.$key.']',
							'time'		=> wpjam_date('Y-m-d H:i:s', $ts),
							'hook'		=> $hook,
							'schedule'	=> $data['schedule'] ?? ''
						];
					}
				}
			}

			return $items;
		}else{
			return wpjam_map(wpjam_get_cron('wpjam_scheduled')->get_jobs(), fn($item)=> $item+[
				'job_id'	=> wpjam_build_callback_unique_id($item['callback']),
				'function'	=> wpjam_render_callback($item['callback'])
			]);
		}
	}

	public static function get_actions(){
		return $GLOBALS['current_tab'] == 'crons' ? [
			'add'		=> ['title'=>'新建',		'response'=>'list'],
			'do'		=> ['title'=>'立即执行',	'direct'=>true,	'confirm'=>true,	'bulk'=>2],
			'delete'	=> ['title'=>'删除',		'direct'=>true,	'confirm'=>true,	'bulk'=>true,	'response'=>'list']
		] : [];
	}

	public static function get_fields($action_key='', $id=0){
		return $GLOBALS['current_tab'] == 'crons' ? [
			'hook'		=> ['title'=>'Hook',	'type'=>'text',		'show_admin_column'=>true],
			'time'		=> ['title'=>'运行时间',	'type'=>'timestamp','show_admin_column'=>true,	'value'=>time()],
			'schedule'	=> ['title'=>'频率',		'type'=>'select',	'show_admin_column'=>true,	'options'=>[''=>'只执行一次']+wp_list_pluck(wp_get_schedules(), 'display')],
		] : [
			'function'	=> ['title'=>'回调函数',	'type'=>'view',	'show_admin_column'=>true],
			'weight'	=> ['title'=>'作业权重',	'type'=>'view',	'show_admin_column'=>true],
		];
	}

	public static function get_tabs(){
		return ['crons'	=> [
			'title'			=> '定时作业',
			'order'			=> 20,
			'function'		=> 'list',
			'list_table'	=> [
				'plural'		=> 'crons',
				'singular'		=> 'cron',
				'model'			=> self::class,
				'primary_key'	=> 'cron_id',
			]
		]]+(wpjam_get_cron('wpjam_scheduled') ? ['jobs' => [
			'title'			=> '作业列表',
			'function'		=> 'list',
			'list_table'	=> [
				'model'			=> self::class,
				'plural'		=> 'jobs',
				'singular'		=> 'job',
				'primary_key'	=> 'job_id',
				'per_page'		=> 1000,
				'summary'		=> fn()=> '今天已经运行 <strong>'.wpjam_get_cron('wpjam_scheduled')->get_count().'</strong> 次',
			]
		]] : []);
	}

	public static function add_hooks(){
		add_filter('cron_schedules', fn($schedules)=> $schedules+[
			'five_minutes'		=> ['interval'=>300,	'display'=>'每5分钟一次'],
			'fifteen_minutes'	=> ['interval'=>900,	'display'=>'每15分钟一次']
		]);
	}
}

function wpjam_register_cron($hook, $args=[]){
	if(is_callable($hook)){
		return wpjam_register_job($hook, $args);
	}

	$cb	= ($args['callback'] ?? '') ?: wpjam('cron', $hook, new WPJAM_Cron($args+['hook'=>$hook]));

	add_action($hook, $cb);

	wpjam_is_scheduled_event($hook) || wpjam_schedule_event($hook, $args);

	return is_object($cb) ? $cb : null;
}

function wpjam_register_job($job, $args=[]){
	$args	= is_array($args) ? $args : (is_numeric($args) ? ['weight'=>$args] : []);
	$args	= array_merge($args, is_callable($job) ? ['callback'=>$job] : []);

	if(!empty($args['callback']) && is_callable($args['callback'])){
		$cron	= 'wpjam_scheduled';
		$cron	= wpjam_get_cron($cron) ?: wpjam_register_cron($cron, ['recurrence'=>'five_minutes', 'weight'=>true]);

		return $cron->update_arg('jobs[]', wp_parse_args($args, ['weight'=>1, 'day'=>-1]));
	}
}

function wpjam_get_cron($hook){
	return wpjam('cron', $hook);
}

function wpjam_schedule_event($hook, $data){
	$data	+= ['timestamp'=>time(), 'args'=>[], 'recurrence'=>false];
	$args	= $data['recurrence'] ? [$data['recurrence']] : [];
	$cb		= $args ? 'wp_schedule_event' : 'wp_schedule_single_event';

	return $cb($data['timestamp'], ...[...$args, $hook, $data['args']]);
}

function wpjam_is_scheduled_event($hook){	// 不用判断参数
	return array_any(WPJAM_Cron::get_all(), fn($cron)=> isset($cron[$hook]));
}

wpjam_add_menu_page('wpjam-crons',	[
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> '定时作业',
	'order'			=> 9,
	'summary'		=> __FILE__,
	'network'		=> false,
	'function'		=> 'tab',
	'model'			=> 'WPJAM_Cron'
]);
