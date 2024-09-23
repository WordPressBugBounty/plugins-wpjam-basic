<?php
/*
Name: 定时作业
URI: https://mp.weixin.qq.com/s/mSqzZdslhxwkNHGRpa3WmA
Description: 定时作业让你可以可视化管理 WordPress 的定时作业
Version: 2.0
*/
class WPJAM_Cron extends WPJAM_Args{
	public function schedule(){
		$callback	??= [$this, 'callback'];

		if(is_callable($callback)){
			add_action($this->hook, $callback);

			if(!self::is_scheduled($this->hook)){
				$args	= $this->args['args'] ?? [];
				$time	= $this->time ?: time();

				if($this->recurrence){
					wp_schedule_event($time, $this->recurrence, $this->hook, $args);
				}else{
					wp_schedule_single_event($time, $this->hook, $args);
				}
			}
		}

		return $this;
	}

	public function callback(){
		if(get_site_transient($this->hook.'_lock')){
			return;
		}

		set_site_transient($this->hook.'_lock', 1, 5);

		if($jobs = $this->get_jobs()){
			$callbacks	= array_column($jobs, 'callback');
			$total		= count($callbacks);
			$index		= get_transient($this->hook.'_index') ?: 0;
			$index		= $index >= $total ? 0 : $index;
			$callback	= $callbacks[$index];

			set_transient($this->hook.'_index', $index+1, DAY_IN_SECONDS);

			$this->increment();

			if(is_callable($callback)){
				$callback();
			}else{
				trigger_error('invalid_job_callback'.var_export($callback, true));
			}
		}
	}

	public function get_jobs($jobs=null){
		if(is_null($jobs)){
			$jobs	= $this->jobs;

			if($jobs && is_callable($jobs)){
				$jobs	= $jobs();
			}
		}

		$jobs	= $jobs ?: [];

		if(!$jobs || !$this->weight){
			return array_values($jobs);
		}

		$queue	= [];
		$next	= [];

		foreach($jobs as $job){
			if(is_object($job)){
				$job->weight	= $job->weight ?? 1;

				if($job->weight){
					$queue[]	= $job;

					if($job->weight > 1){
						$job->weight --;
						$next[]	= $job;
					}
				}
			}else{
				$queue[]	= $job;
			}
		}

		if($next){
			$queue	= array_merge($queue, $this->get_jobs($next));
		}

		return $queue;
	}

	public function get_counter($increment=false){
		$today		= wpjam_date('Y-m-d');
		$counter	= get_transient($this->hook.'_counter:'.$today) ?: 0;

		if($increment){
			$counter ++;
			set_transient($this->hook.'_counter:'.$today, $counter, DAY_IN_SECONDS);
		}

		return $counter;
	}

	public function increment(){
		return $this->get_counter(true);
	}

	public static function add_hooks(){
		add_filter('cron_schedules', fn($schedules)=> array_merge($schedules, [
			'five_minutes'		=> ['interval'=>300,	'display'=>'每5分钟一次'],
			'fifteen_minutes'	=> ['interval'=>900,	'display'=>'每15分钟一次'],
		]));
	}

	public static function is_scheduled($hook) {	// 不用判断参数
		return wpjam_some(self::get_all(), fn($cron)=> isset($cron[$hook]));
	}

	public static function cleanup(){
		$invalid	= 0;

		foreach(_get_cron_array() as $timestamp => $wp_cron){
			foreach($wp_cron as $hook => $dings){
				if(!has_filter($hook)){			// 系统不存在的定时作业，清理掉
					foreach($dings as $key => $data){
						wp_unschedule_event($timestamp, $hook, $data['args']);
					}

					$invalid++;
				}
			}
		}

		return $invalid;
	}

	public static function get($id){
		list($timestamp, $hook, $key)	= explode('--', $id);

		$data	= self::get_all()[$timestamp][$hook][$key] ?? [];

		if($data){
			$data['hook']		= $hook;
			$data['timestamp']	= $timestamp;
			$data['time']		= wpjam_date('Y-m-d H:i:s', $timestamp);
			$data['cron_id']	= $id;
			$data['interval']	= $data['interval'] ?? 0;
		}

		return $data;
	}

	public static function get_all(){
		return _get_cron_array() ?: [];
	}

	public static function insert($data){
		if(!has_filter($data['hook'])){
			wp_die('无效的 Hook');
		}

		$timestamp	= wpjam_strtotime($data['time']);

		if($data['interval']){
			wp_schedule_event($timestamp, $data['interval'], $data['hook'], $data['_args']);
		}else{
			wp_schedule_single_event($timestamp, $data['hook'], $data['_args']);
		}

		return true;
	}

	public static function do($id){
		$data	= self::get($id);

		if($data){
			wpjam_throw_if_error(do_action_ref_array($data['hook'], $data['args']));
		}

		return true;
	}

	public static function delete($id){
		$data = self::get($id);

		return $data ? wp_unschedule_event($data['timestamp'], $data['hook'], $data['args']) : true;
	}

	public static function query_items($args){
		foreach(self::get_all() as $timestamp => $wp_cron){
			foreach($wp_cron as $hook => $dings){
				foreach($dings as $key => $data){
					if(!has_filter($hook)){
						wp_unschedule_event($timestamp, $hook, $data['args']);	// 系统不存在的定时作业，自动清理
						continue;
					}

					$items[] = [
						'cron_id'	=> $timestamp.'--'.$hook.'--'.$key,
						'time'		=> wpjam_date('Y-m-d H:i:s', $timestamp),
						'hook'		=> $hook,
						'interval'	=> $data['interval'] ?? 0
					];
				}
			}
		}

		return $items ?? [];
	}

	public static function get_actions(){
		return [
			'add'		=> ['title'=>'新建',		'response'=>'list'],
			'do'		=> ['title'=>'立即执行',	'direct'=>true,	'confirm'=>true,	'bulk'=>2],
			'delete'	=> ['title'=>'删除',		'direct'=>true,	'confirm'=>true,	'bulk'=>true,	'response'=>'list']
		];
	}

	public static function get_fields($action_key='', $id=0){
		return [
			'hook'		=> ['title'=>'Hook',	'type'=>'text',		'show_admin_column'=>true],
			'time'		=> ['title'=>'运行时间',	'type'=>'text',		'show_admin_column'=>true,	'value'=>wpjam_date('Y-m-d H:i:s')],
			'interval'	=> ['title'=>'频率',		'type'=>'select',	'show_admin_column'=>true,	'options'=>[0=>'只执行一次']+wp_list_pluck(wp_get_schedules(), 'display', 'interval')],
		];
	}

	public static function get_list_table(){
		return [
			'plural'		=> 'crons',
			'singular'		=> 'cron',
			'model'			=> self::class,
			'primary_key'	=> 'cron_id',
		];
	}

	public static function get_tabs(){
		$tabs['crons']	= [
			'title'			=> '定时作业',
			'function'		=> 'list',
			'list_table'	=> self::class,
			'order'			=> 20,
		];

		$cron	= wpjam_get_cron('wpjam_scheduled');

		if($cron){
			$tabs['jobs'] = [
				'title'			=> '作业列表',
				'function'		=> 'list',
				'list_table'	=> 'WPJAM_Cron_Job',
				'summary'		=> '今天已经运行 <strong>'.$cron->get_counter().'</strong> 次'
			];
		}

		return $tabs;
	}
}

class WPJAM_Cron_Job extends WPJAM_Register{
	public function registered($count){
		if($count == 1){
			return wpjam_register_cron('wpjam_scheduled', [
				'recurrence'	=> 'five_minutes',
				'jobs'			=> [self::class, 'get_objects'],
				'weight'		=> true
			]);
		}
	}

	public static function get_objects(){
		$day	= (wpjam_date('H') > 2 && wpjam_date('H') < 6) ? 0 : 1;

		return array_filter(self::get_registereds(), fn($object)=> $object->day == -1 || $object->day == $day);
	}

	public static function create($name, $args=[]){
		$args	= is_array($args) ? $args : (is_numeric($args) ? ['weight'	=> $args] : []);

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
				return null;
			}
		}

		return self::register($name, wp_parse_args($args, ['weight'=>1, 'day'=>-1]));
	}

	public static function get_actions(){
		return [];
	}

	public static function get_fields($action_key='', $id=0){
		return [
			'function'	=> ['title'=>'回调函数',	'type'=>'view',	'show_admin_column'=>true],
			'weight'	=> ['title'=>'作业权重',	'type'=>'view',	'show_admin_column'=>true],
			'day'		=> ['title'=>'运行时间',	'type'=>'view',	'show_admin_column'=>true,	'options'=>['-1'=>'全天','1'=>'白天','0'=>'晚上']],
		];
	}

	public static function query_items($args){
		foreach(self::get_registereds() as $name => $object){
			$item	= $object->to_array();

			if(is_array($item['callback'])){
				if(is_object($item['callback'][0])){
					$item['function']	= '<p>'.get_class($item['callback'][0]).'->'.(string)$item['callback'][1].'</p>';
				}else{
					$item['function']	= '<p>'.$item['callback'][0].'->'.(string)$item['callback'][1].'</p>';
				}
			}elseif(is_object($item['callback'])){
				$item['function']	= '<pre>'.print_r($item['callback'], true).'</pre>';
			}else{
				$item['function']	= wpautop($item['callback']);
			}

			$item['job_id']	= $name;
			$items[]		= $item;
		}
		
		return $items ?? [];
	}

	public static function get_list_table(){
		return [
			'plural'		=> 'jobs',
			'singular'		=> 'job',
			'primary_key'	=> 'job_id',
			'model'			=> 'WPJAM_Cron_Job',
		];
	}
}

function wpjam_register_cron($name, $args=[]){
	if(is_callable($name)){
		return wpjam_register_job($name, $args);
	}

	return wpjam_get_instance('cron', $name, fn()=> (new WPJAM_Cron(wp_parse_args($args, ['hook'=>$name, 'args'=>[]])))->schedule());
}

function wpjam_get_cron($name){
	return wpjam_get_instance('cron', $name);
}

function wpjam_register_job($name, $args=[]){
	return WPJAM_Cron_Job::create($name, $args);
}

function wpjam_is_scheduled_event($hook) {	// 不用判断参数
	return WPJAM_Cron::is_scheduled($hook);
}

wpjam_add_menu_page('wpjam-crons',	[
	'parent'		=> 'wpjam-basic',
	'menu_title'	=> '定时作业',
	'order'			=> 9,
	'summary'		=> __FILE__,
	'function'		=> 'tab',
	'network'		=> false,
	'tabs'			=> ['WPJAM_Cron', 'get_tabs'],
	'hooks'			=> ['WPJAM_Cron', 'add_hooks'],
]);