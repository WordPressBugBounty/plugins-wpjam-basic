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
		$other_fields	= [];
		$number_field 	= ['type'=>'number',	'class'=>'small-text'];

		$fields['posts_per_page']	= $number_field+[
			'title'			=> '全局',
			'value'			=> get_option('posts_per_page'),
			'description'	=> '博客全局设置的文章列表数量，空或者0则使用全局设置'
		];

		$other_fields	= wpjam_map(['author'=>'作者页','search'=>'搜索页','archive'=>'存档页'], fn($v)=>$number_field+['title'=>$v]);
		$tax_objects	= get_taxonomies(['public'=>true,'show_ui'=>true], 'objects');

		if($tax_objects){
			$tax_fields = [];

			foreach(wpjam_sort($tax_objects, ['hierarchical'=>'DESC']) as $taxonomy => $tax_object){
				if(wpjam_get_taxonomy_setting($taxonomy, 'posts_per_page')){
					continue;
				}

				$title	= wpjam_get_taxonomy_setting($taxonomy, 'title');

				if($tax_object->hierarchical){
					$tax_fields[$taxonomy.'_set']	= ['title'=>$title,	'type'=>'fields',	'fields'=>[
						$taxonomy				=> $number_field,
						$taxonomy.'_individual'	=> ['type'=>'checkbox',	'description'=>'每个'.$title.'可独立设置数量',	'show_if'=>[$taxonomy, '!=', '']]
					]];
				}else{
					$tax_fields[$taxonomy] = $number_field+['title'=>$title];
				}
			}
		}

		$pt_objects	= WPJAM_Post_Type::get_registereds(['exclude_from_search'=>false, '_builtin'=>false], 'objects');

		if($pt_objects){
			$options		= ['post'=>'文章']+wp_list_pluck($pt_objects, 'title');
			$ptype_field 	= ['title'=>'文章类型',	'type'=>'checkbox',	'value'=>['post'],	'options'=>$options];

			$fields['home_set']	= ['title'=>'首页',	'type'=>'fieldset',	'fields'=>[
				'home'				=> $number_field+['title'=>'文章数量'],
				'home_post_types'	=> $ptype_field,
			]];

			$fields['posts_per_rss_set']	= ['title'=>'Feed页',	'type'=>'fieldset',	'fields'=>[
				'posts_per_rss'		=> $number_field+['title'=>'文章数量',	'value'=>get_option('posts_per_rss')],
				'feed_post_types'	=> $ptype_field,
			]];

			$fields['other']	= ['title'=>'其他页面',	'type'=>'fieldset',	'fields'=>$other_fields];

			if($tax_fields){
				$fields['tax_set']	= ['title'=>'分类模式',	'type'=>'fieldset',	'fields'=>$tax_fields];
			}
		}else{
			$fields['home']				= $number_field+['title'=>'首页'];
			$fields['posts_per_rss']	= $number_field+['title'=>'Feed页',	'value'=>get_option('posts_per_rss'),	'description'=>'Feed中最近文章列表数量'];

			$fields	+= $other_fields+$tax_fields;
		}

		$post_types	= get_post_types(['public'=>true, 'has_archive'=>true]);

		if($post_types){
			$pt_field = [];

			foreach($post_types as $post_type){
				if(wpjam_get_post_type_setting($post_type, 'posts_per_page')){
					continue;
				}

				$pt_field[$post_type]	= $number_field+['title'=>wpjam_get_post_type_setting($post_type, 'title')];
			}

			if($pt_field){
				$fields['post_type_set']	= ['title'=>'文章类型<br />存档页面',	'type'=>'fieldset',	'fields'=>$pt_field];
			}
		}

		return $fields;
	}

	public static function get_menu_page(){
		return [
			'tab_slug'		=> 'posts-per-page',
			'function'		=> 'option',	
			'option_name'	=> 'wpjam-posts-per-page',
			'plugin_page'	=> 'wpjam-posts',
			'order'			=> 18,
			'summary'		=> __FILE__
		];
	}

	public static function get_admin_load(){
		return [
			'base'	=> 'edit-tags',
			'model'	=> self::class
		];
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
		if(!$wp_query->is_main_query()){
			return;
		}

		if(isset($wp_query->query['post_type'])){
			$required	= false;
		}else{
			$required	= (bool)get_post_types(['exclude_from_search'=>false, '_builtin'=>false]);
		}

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
				$post_types	= get_post_types_by_support('author');
				$post_types	= array_intersect($post_types, get_post_types(['public'=>true]));
			}
		}elseif(is_tax() || is_category() || is_tag()){
			$term		= $wp_query->get_queried_object();
			$taxonomy	= $term ? $term->taxonomy : null;

			if($taxonomy){
				$number		= wpjam_get_taxonomy_setting($taxonomy, 'posts_per_page');
				$number		= $number ?: self::get_setting($taxonomy);
				$individual	= self::get_setting($taxonomy.'_individual');

				if($individual && metadata_exists('term', $term->term_id, 'posts_per_page')){
					$number	= get_term_meta($term->term_id, 'posts_per_page', true);
				}

				if(is_category() || is_tag()){
					$post_types	= get_taxonomy($taxonomy)->object_type;
					$post_types	= array_intersect($post_types, get_post_types(['public'=>true]));
				}
			}
		}elseif(is_post_type_archive()){
			$pt_object	= $wp_query->get_queried_object();
			$post_type	= $pt_object ? $pt_object->name : null;

			if($post_type){
				$number		= wpjam_get_post_type_setting($post_type, 'posts_per_page');
				$number		= $number ?: self::get_setting($pt_object->name);
			}
		}elseif(is_search()){
			$number		= self::get_setting('search');
		}elseif(is_archive()){
			$number		= self::get_setting('archive');
			$post_types	= 'any';
		}

		if(isset($number)){
			$wp_query->set('posts_per_page', $number);
		}

		if($required && !empty($post_types)){
			if(is_array($post_types) && count($post_types) == 1) {
				$post_types	= $post_types[0];
			}

			$wp_query->set('post_type', $post_types);
		}
	}

	public static function add_hooks(){
		if(!is_admin() || wp_doing_ajax()){
			add_action('pre_get_posts',  [self::class, 'on_pre_get_posts']);
		}
	}
}

wpjam_register_option('wpjam-posts-per-page',	[
	'model'	=> 'WPJAM_Posts_Per_Page',
	'title'	=> '文章数量'
]);
