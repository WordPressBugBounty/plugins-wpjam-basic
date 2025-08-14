<?php
/*
Name: 文章数量
URI: https://mp.weixin.qq.com/s/gY0AG1vnR285bmOfKO8SCw
Description: 文章数量扩展可以设置不同页面不同的文章列表数量和文章类型，也可开启不同的分类不同文章数量。
Version: 2.0
*/
class WPJAM_Posts_Per_Page extends WPJAM_Option_Model{
	public static function sanitize_callback($value){
		wpjam_map(wpjam_pull($value, ['posts_per_page', 'posts_per_rss']), fn($v, $k)=> $v ? update_option($k, $v) : null);

		return $value;
	}

	public static function get_fields(){
		$field 	= ['type'=>'number',	'class'=>'small-text'];

		$fields	= [
			'posts_per_page'	=> $field+['title'=>'全局',	'value_callback'=>fn()=> get_option('posts_per_page'),	'description'=>'博客全局设置的文章列表数量'],
			'home'				=> $field+['title'=>'首页'],
			'posts_per_rss'		=> $field+['title'=>'Feed',	'value_callback'=>fn()=> get_option('posts_per_rss')]
		];

		if($pt_objects = WPJAM_Post_Type::get_registereds(['exclude_from_search'=>false, '_builtin'=>false], 'objects')){
			$options		= ['post'=>'文章']+wp_list_pluck($pt_objects, 'title');
			$ptype_field 	= ['before'=>'文章类型：',	'type'=>'checkbox',	'value'=>['post'],	'options'=>$options];

			$fields['home']	= ['title'=>'首页',	'fields'=>[
				'home'				=> $field+['before'=>'文章数量：'],
				'home_post_types'	=> $ptype_field,
			]];

			$fields['posts_per_rss']	= ['title'=>'Feed',	'fields'=>[
				'posts_per_rss'		=> wpjam_except($fields['posts_per_rss'], 'title')+['before'=>'文章数量：'],
				'feed_post_types'	=> $ptype_field,
			]];
		}

		foreach(wpjam_sort(get_taxonomies(['public'=>true,'show_ui'=>true], 'objects'), ['hierarchical'=>'DESC']) as $tax => $tax_object){
			if(isset($tax_object->posts_per_page)){
				continue;
			}

			$title	= wpjam_get_taxonomy_setting($tax, 'title');

			$tax_fields[$tax]	= ['title'=>$title,	'group'=>'tax'] + $field;

			if($tax_object->hierarchical){
				$individual[$tax.'_individual']	= ['label'=>'每个'.$title.'可独立设置',	'show_if'=>[$tax, '!=', '']];
			}
		}

		$other_fields		= wpjam_map(['author'=>'作者页','search'=>'搜索页','archive'=>'存档页'], fn($v)=>$field+['title'=>$v]);
		$fields['other']	= ['title'=>'其他页面',	'wrap_tag'=>'fieldset',	'group'=>true,	'fields'=>$other_fields];
		$fields['tax']		= ['title'=>'分类模式',	'wrap_tag'=>'fieldset',	'fields'=>$tax_fields + (empty($individual) ? [] : $individual)];

		foreach(get_post_types(['public'=>true, 'has_archive'=>true]) as $post_type){
			if(wpjam_get_post_type_setting($post_type, 'posts_per_page')){
				continue;
			}

			$pt_field[$post_type]	= $field+['title'=>wpjam_get_post_type_setting($post_type, 'title')];
		}

		if(!empty($pt_field)){
			$fields['post_type_set']	= ['title'=>'文章类型<br />存档页面',	'group'=>true,	'wrap_tag'=>'fieldset','fields'=>$pt_field];
		}

		return $fields;
	}

	public static function builtin_page_load($screen){
		if(is_taxonomy_hierarchical($screen->taxonomy) && self::get_setting($screen->taxonomy.'_individual')){
			$default	= self::get_setting($screen->taxonomy) ?: get_option('posts_per_page');

			wpjam_register_list_table_action('posts_per_page',[
				'title'			=> '文章数量',
				'page_title'	=> '设置文章数量',
				'submit_text'	=> '设置',
				'fields'		=> [
					'default'			=> ['title'=>'默认数量',	'type'=>'view',		'value'=>$default],
					'posts_per_page'	=> ['title'=>'文章数量',	'type'=>'number',	'class'=>'small-text']
				]
			]);

			add_filter($screen->taxonomy.'_row_actions', fn($actions, $term)=> array_merge($actions, ($v = get_term_meta($term->term_id, 'posts_per_page', true)) ? ['posts_per_page'=>str_replace('>文章数量<', '>文章数量'.'（'.$v.'）'.'<', $actions['posts_per_page'])] : []), 10, 2);	
		}
	}

	public static function on_pre_get_posts($wp_query){
		if(!$wp_query->is_main_query() || (wp_doing_ajax() && get_current_screen())){
			return;
		}

		$required	= isset($wp_query->query['post_type']) ? false : (bool)get_post_types(['exclude_from_search'=>false, '_builtin'=>false]);
		$object		= $wp_query->get_queried_object();

		if(is_front_page()){
			$number	= self::get_setting('home');
			
			if($required){
				$post_types	= self::get_setting('home_post_types');
			}
		}elseif(is_feed()){
			if($required){
				$post_types	= self::get_setting('feed_post_types');
			}
		}elseif(is_author()){
			$number	= self::get_setting('author');

			if($required){
				$post_types	= array_intersect(get_post_types_by_support('author'), get_post_types(['public'=>true]));
			}
		}elseif(is_tax() || is_category() || is_tag()){
			if($object){
				$tax	= $object->taxonomy;
				$number	= wpjam_get_taxonomy_setting($tax, 'posts_per_page') ?: self::get_setting($tax);

				if(self::get_setting($tax.'_individual')){
					$number	= get_term_meta($object->term_id, 'posts_per_page', true) ?: $number;
				}

				if($required && (is_category() || is_tag())){
					$post_types	= array_intersect(get_taxonomy($tax)->object_type, get_post_types(['public'=>true]));
				}
			}
		}elseif(is_post_type_archive()){
			if($object){
				$number	= wpjam_get_post_type_setting($object->name, 'posts_per_page') ?: self::get_setting($object->name);
			}
		}elseif(is_search()){
			$number		= self::get_setting('search');
		}elseif(is_archive()){
			$number		= self::get_setting('archive');

			if($required){
				$post_types	= 'any';
			}
		}

		!empty($number) && $wp_query->set('posts_per_page', $number);
		!empty($post_types) && $wp_query->set('post_type', (is_array($post_types) && count($post_types) == 1) ? reset($post_types) : $post_types);
	}

	public static function add_hooks(){
		(!is_admin() || wp_doing_ajax()) && add_action('pre_get_posts',  [self::class, 'on_pre_get_posts']);
	}
}

wpjam_register_option('wpjam-posts-per-page',	[
	'model'			=> 'WPJAM_Posts_Per_Page',
	'title'			=> '文章数量',
	'menu_page'		=> ['tab_slug'=>'posts-per-page', 'plugin_page'=>'wpjam-posts', 'order'=>18, 'summary'=>__FILE__],
	'admin_load'	=> ['base'=>'edit-tags']
]);
