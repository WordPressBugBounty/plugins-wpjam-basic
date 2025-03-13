<?php
/*
Name: 定时作业
URI: https://mp.weixin.qq.com/s/mSqzZdslhxwkNHGRpa3WmA
Description: 定时作业让你可以可视化管理 WordPress 的定时作业
Version: 2.0
*/
class WPJAM_Cron extends WPJAM_Args{
	public function callback(){
		if(wpjam_lock($this->hook.'_lock', 5, true)){
			return;
		}

		$queue = $this->queue();

		if($queue){
			$count		= wpjam_increment($this->get_count('name'));
			$index		= wpjam_increment($this->hook.'_index', count($queue));
			$callback	= array_column($queue, 'callback')[$index];

			if(is_callable($callback)){
				$callback();
			}else{
				trigger_error('invalid_job_callback'.var_export($callback, true));
			}
		}
	}

	public function queue($jobs=null){
		$jobs	??= $this->jobs;
		$jobs	= array_values(maybe_callback($jobs));
		$queue	= [];

		if($this->weight){
			foreach($jobs as $job){
				if(is_array($job) && wpjam_get($job, 'weight') > 1){
					$job['weight'] --;

					$queue[]	= $job;	
				}
			}
		}

		return array_merge($jobs, ($queue ? $this->queue($queue) : []));
	}

	public function get_count($output=''){
		$name	= $this->hook.'_counter:'.wpjam_date('Y-m-d');

		return $output == 'name' ? $name : (get_transient($name) ?: 0);
	}

	public static function is_scheduled($hook) {	// 不用判断参数
		return array_any(self::get_all(), fn($cron)=> isset($cron[$hook]));
	}

	public static function schedule($data){
		$args	= [$data['time'], $data['hook'], $data['args'] ?? []];

		if($data['recurrence']){
			wp_schedule_event(...wpjam_add_at($args, 1, $data['recurrence']));
		}else{
			wp_schedule_single_event(...$args);
		}

		return true;
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
		[$ts, $hook, $key]	= explode('--', $id);

		$data	= self::get_all()[$ts][$hook][$key] ?? [];

		return $data ? [
			'hook'		=> $hook,
			'timestamp'	=> $ts,
			'time'		=> wpjam_date('Y-m-d H:i:s', $ts),
			'cron_id'	=> $id,
			'schedule'	=> $data['schedule'] ?? '',
			'args'		=> $data['args'] ?? []
		] : [];
	}

	public static function insert($data){
		if(!has_filter($data['hook'])){
			wp_die('无效的 Hook');
		}

		return self::schedule($data);
	}

	public static function do($id){
		$data	= self::get($id);

		return $data ? (do_action_ref_array($data['hook'], $data['args']) || true) : true;
	}

	public static function delete($id){
		$data = self::get($id);

		return $data ? wp_unschedule_event($data['timestamp'], $data['hook'], $data['args']) : true;
	}

	public static function get_all(){
		return _get_cron_array() ?: [];
	}

	public static function get_jobs(){
		$day	= (wpjam_date('H') > 2 && wpjam_date('H') < 6) ? 0 : 1;

		return array_filter(wpjam_get_items('cron_job'), fn($job)=> $job['day'] == -1 || $job['day'] == $day);
	}

	public static function add_job($name, $args=[]){
		$args	= is_array($args) ? $args : (is_numeric($args) ? ['weight'=>$args] : []);

		if(is_callable($name)){
			$args['callback']	= $name;

			if(is_object($name)){
				$name	= get_class($name);
			}elseif(is_array($name)){
				$name[0]= is_object($name[0]) ? get_class($name[0]) : $name[0];
				$name	= implode(':', $name);
			}
		}else{
			if(empty($args['callback']) || !is_callable($args['callback'])){
				return;
			}
		}

		if(!wpjam_get_items('cron_job')){
			self::add('wpjam_scheduled', [
				'recurrence'	=> 'five_minutes',
				'jobs'			=> [self::class, 'get_jobs'],
				'weight'		=> true
			]);
		}

		return wpjam_add_item('cron_job', $name, wp_parse_args($args, ['weight'=>1, 'day'=>-1]));
	}

	public static function query_items($args){
		if($GLOBALS['current_tab'] == 'crons'){
			foreach(self::get_all() as $ts => $cron){
				foreach($cron as $hook => $dings){
					foreach($dings as $key => $data){
						$items[] = [
							'cron_id'	=> $ts.'--'.$hook.'--'.$key,
							'time'		=> wpjam_date('Y-m-d H:i:s', $ts),
							'hook'		=> $hook,
							'schedule'	=> $data['schedule'] ?? ''
						];
					}
				}
			}

			return $items ?? [];
		}else{
			return array_values(wpjam_map(wpjam_get_items('cron_job'), fn($item, $name)=> $item+[
				'job_id'	=> $name,
				'function'	=> wpjam_render_callback($item['callback'])
			]));
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
			'day'		=> ['title'=>'运行时间',	'type'=>'view',	'show_admin_column'=>true,	'options'=>['-1'=>'全天','1'=>'白天','0'=>'晚上']],
		];
	}

	public static function get_tabs(){
		$tabs['crons']	= [
			'title'			=> '定时作业',
			'order'			=> 20,
			'function'		=> 'list',
			'list_table'	=> [
				'plural'		=> 'crons',
				'singular'		=> 'cron',
				'model'			=> self::class,
				'primary_key'	=> 'cron_id',
			]
		];

		$cron	= wpjam_get_cron('wpjam_scheduled');

		if($cron){
			$tabs['jobs']	= [
				'title'			=> '作业列表',
				'summary'		=> '今天已经运行 <strong>'.$cron->get_count().'</strong> 次',
				'function'		=> 'list',
				'list_table'	=> [
					'plural'		=> 'jobs',
					'singular'		=> 'job',
					'primary_key'	=> 'job_id',
					'model'			=> 'WPJAM_Cron',
				],
			];
		}

		return $tabs;
	}

	public static function add($hook, $args){
		$args	+= ['hook'=>$hook, 'time'=>time(), 'recurrence'=>false, 'callback'=>''];
		$object	= $args['callback'] ? null : wpjam_add_instance('cron', $hook, new self($args));

		add_action($hook, $args['callback'] ?: [$object, 'callback']);

		if(!self::is_scheduled($hook)){
			self::schedule($args);
		}

		return $object;
	}

	public static function add_hooks(){
		add_filter('cron_schedules', fn($schedules)=> array_merge($schedules, [
			'five_minutes'		=> ['interval'=>300,	'display'=>'每5分钟一次'],
			'fifteen_minutes'	=> ['interval'=>900,	'display'=>'每15分钟一次'],
		]));

		if(is_admin()){
			add_action('wpjam_admin_init', fn()=> wpjam_add_menu_page('wpjam-crons',	[
				'parent'		=> 'wpjam-basic',
				'menu_title'	=> '定时作业',
				'order'			=> 9,
				'summary'		=> __FILE__,
				'function'		=> 'tab',
				'network'		=> false,
				'tabs'			=> [self::class, 'get_tabs']
			]));
		}
	}
}

function wpjam_register_cron($name, $args=[]){
	if(is_callable($name)){
		return wpjam_register_job($name, $args);
	}

	return WPJAM_Cron::add($name, $args);
}

function wpjam_get_cron($name){
	return wpjam_get_instance('cron', $name);
}

function wpjam_register_job($name, $args=[]){
	return WPJAM_Cron::add_job($name, $args);
}

function wpjam_is_scheduled_event($hook){
	return WPJAM_Cron::is_scheduled($hook);
}

WPJAM_Cron::add_hooks();
