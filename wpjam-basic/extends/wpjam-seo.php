<?php
/*
Name: 简单 SEO
URI: https://mp.weixin.qq.com/s/LzGWzKCEl5SdJCQdBvFipg
Description: 简单 SEO 扩展实现最简单快捷的方式设置 WordPress 站点的 SEO。
Version: 2.0
*/
class WPJAM_SEO extends WPJAM_Option_Model{
	public static function get_fields(){
		if(file_exists(ABSPATH.'robots.txt')){
			$robots_field	= ['type'=>'view',	'value'=>'博客的根目录下已经有 robots.txt 文件。<br />请直接编辑或者删除之后在后台自定义。'];
		}else{
			$site_url	= parse_url(site_url());
			$path		= !empty($site_url['path'])  ? $site_url['path'] : '';
			$robots 	= wpjam_remove_pre_tab("User-agent: *
			Disallow: /wp-admin/
			Disallow: /wp-includes/
			Disallow: /cgi-bin/
			Disallow: $path/wp-content/plugins/
			Disallow: $path/wp-content/themes/
			Disallow: $path/wp-content/cache/
			Disallow: $path/author/
			Disallow: $path/trackback/
			Disallow: $path/feed/
			Disallow: $path/comments/
			Disallow: $path/search/", 3);

			$robots_field	= ['type'=>'textarea', 'value'=>$robots];
		}

		if(file_exists(ABSPATH.'sitemap.xml')){
			$wpjam_sitemap	= '博客的根目录下已经有 sitemap.xml 文件。<br />删除之后才能使用插件自动生成的 sitemap.xml。';
		}else{
			$wpjam_sitemap	= '首页/分类/标签：<a href="'.home_url('/sitemap.xml').'" target="_blank">'.home_url('/sitemap.xml').'</a>
			<br />前1000篇文章：<a href="'.home_url('/sitemap-1.xml').'" target="_blank">'.home_url('/sitemap-1.xml').'</a>
			<br />1000-2000篇文章：<a href="'.home_url('/sitemap-2.xml').'" target="_blank">'.home_url('/sitemap-2.xml').'</a>
			<br />以此类推...';
		}

		$wp_sitemap	= 'sitemap 地址：<a href="'.home_url('/wp-sitemap.xml').'" target="_blank">'.home_url('/wp-sitemap.xml').'</a>';

		return [
			'home_set'	=> ['title'=>'首页设置',	'type'=>'fieldset',	'wrap_tag'=>'fieldset',	'fields'=>[
				'home_title'		=> ['title'=>'标题：',	'class'=>'regular-text, expandable',	'placeholder'=>'不填则使用标题'],
				'home_description'	=> ['title'=>'描述：',	'type'=>'textarea'],
				'home_keywords'		=> ['title'=>'关键字：',	'class'=>'regular-text, expandable'],
			]],
			'post_set'	=> ['title'=>'文章和分类页',	'type'=>'fieldset',	'group'=>true,	'fields'=>[
				'individual'	=> ['type'=>'select', 	'options'=>['0'=>'自动获取摘要和关键字',	'1'=>[
					'label'		=> '单独设置 SEO TDK',
					'fields'	=> ['list_table'=>['value'=>1, 'options'=>['1'=>'编辑和列表页都可设置', '0'=>'仅可在编辑页设置', 'only'=>'仅可在列表页设置']]]
				]]],
			],	'description'	=> ['文章摘要作为页面的 Meta Description，文章的标签作为页面的 Meta Keywords。<br />分类和标签的描述作为页面的 Meta Description，页面没有 Meta Keywords。',	['show_if'=>['individual', 0]]]],
			'unique'	=> ['title'=>'确保生成并唯一',	'label'=>'如果当前主题或其他插件也会生成摘要和关键字，或当前主题不标准，可以通过勾选该选项「确保生成并唯一」。'],
			'robots'	=> ['title'=>'robots.txt']+$robots_field,
			'sitemap'	=> ['title'=>'Sitemap',		'options'=>[0=>['label'=>'使用 WPJAM 生成的','description'=>$wpjam_sitemap], 'wp'=>['label'=>'使用 WordPress 内置的','description'=>$wp_sitemap]]]
		];
	}

	public static function get_value($type='title'){
		if($type == 'meta'){
			return wpjam_fill(['description', 'keywords'], fn($k)=> self::get_value($k));
		}

		if(is_front_page()){
			if(get_query_var('paged') < 2){
				$value	= self::get_setting('home_'.$type);
			}
		}elseif(is_tag() || is_category() || is_tax()){
			if(get_query_var('paged') < 2){
				if(self::get_setting('individual')){
					$value	= get_term_meta(get_queried_object_id(), 'seo_'.$type, true);
				}

				if(empty($value) && $type == 'description'){
					$value	= term_description();
				}
			}
		}elseif(is_singular()){
			if(self::get_setting('individual')){				
				$value	= get_post_meta(get_the_ID(), 'seo_'.$type, true);
			}

			if(empty($value)){
				if($type == 'description'){
					$value	= get_the_excerpt();
				}elseif($type == 'keywords'){
					$value	= ($tags = get_the_tags()) ? implode(',', wp_list_pluck($tags, 'name')) : '';
				}
			}
		}

		if(!empty($value)){
			$value	= wp_strip_all_tags($value, true);

			return $type == 'title' ? esc_textarea($value) : "<meta name='{$type}' content='".esc_attr($value)."' />\n";
		}
	}

	public static function get_rewrite_rule(){
		return [
			['sitemap\.xml?$',  'index.php?module=sitemap', 'top'],
			['sitemap-(.*?)\.xml?$',  'index.php?module=sitemap&action=$matches[1]', 'top'],
		];
	}

	public static function redirect($action){
		$sitemap	= '';

		if(!$action){
			$last_mod	= str_replace(' ', 'T', get_lastpostmodified('GMT')).'+00:00';
			$sitemap	.= "\t<url>\n";
			$sitemap	.="\t\t<loc>".home_url()."</loc>\n";
			$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
			$sitemap	.="\t\t<changefreq>daily</changefreq>\n";
			$sitemap	.="\t\t<priority>1.0</priority>\n";
			$sitemap	.="\t</url>\n";

			$taxonomies = array_diff(array_keys(get_taxonomies(['public' => true])), ['post_format']);
			$terms		= get_terms(['taxonomy'=>$taxonomies]);

			foreach($terms as $term){
				$priority	= $term->taxonomy == 'category' ? 0.6 : 0.4;
				$sitemap	.="\t<url>\n";
				$sitemap	.="\t\t<loc>".get_term_link($term)."</loc>\n";
				$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
				$sitemap	.="\t\t<changefreq>daily</changefreq>\n";
				$sitemap	.="\t\t<priority>".$priority."</priority>\n";
				$sitemap	.="\t</url>\n";
			}
		}elseif(is_numeric($action)){
			$ptypes = array_diff(array_keys(get_post_types(['public' => true])), ['page', 'attachment']);
			$posts	= wpjam_get_posts(['posts_per_page'=>1000, 'paged'=>$action, 'post_type'=>$ptypes]);

			foreach($posts as $post){
				$permalink	= get_permalink($post->ID); //$siteurl.$post->post_name.'/';
				$last_mod	= str_replace(' ', 'T', $post->post_modified_gmt).'+00:00';
				$sitemap	.="\t<url>\n";
				$sitemap	.="\t\t<loc>".$permalink."</loc>\n";
				$sitemap	.="\t\t<lastmod>".$last_mod."</lastmod>\n";
				$sitemap	.="\t\t<changefreq>weekly</changefreq>\n";
				$sitemap	.="\t\t<priority>0.8</priority>\n";
				$sitemap	.="\t</url>\n";
			}
		}else{
			$sitemap = apply_filters('wpjam_'.$action.'_sitemap', '');
		}

		header("Content-Type:text/xml");

		echo '<?xml version="1.0" encoding="UTF-8"?>
		<?xml-stylesheet type="text/xsl" href="'.(new WP_Sitemaps_Renderer())->get_sitemap_stylesheet_url().'"?>
		<!-- generated-on="'.date('d. F Y').'" -->
		<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".$sitemap."\n".'</urlset>'."\n";

		exit;
	}

	public static function filter_html($html){
		$title	= self::get_value('title');
		$title	= $title ? '<title>'.$title.'</title>' : '';

		if($meta	= self::get_value('meta')){
			$title	= ($title ?: '\1')."\n".implode($meta);
			$html	= wpjam_preg_replace('#<meta\s+name=([\'"])('.implode('|', array_keys($meta)).')\1(.*?)\/>#is', '', $html);
		}

		return $title ? wpjam_preg_replace('#(<title>[^<]*<\/title>)#is', $title, $html) : $html;
	}

	public static function add_hooks(){
		if(self::get_setting('unique')){
			add_filter('wpjam_html',	[self::class, 'filter_html']);
		}else{
			add_action('wp_head',		fn()=> wpjam_echo(implode(self::get_value('meta'))));
		}

		add_filter('robots_txt',		fn($output, $public)=> $output.($public ? self::get_setting('robots') : ''), 10, 2);
		add_filter('document_title',	fn($title)=> self::get_value('title') ?: $title);

		if(self::get_setting('sitemap') == 0){
			wpjam_route('sitemap', self::class);
		}

		if(is_admin() && self::get_setting('individual')){
			$args	= [
				'title'			=> 'SEO设置',
				'page_title'	=> 'SEO设置',
				'submit_text'	=> '设置',
				'list_table'	=> self::get_setting('list_table', 1),
				'fields'		=> [
					'seo_title'			=> ['title'=>'SEO标题',	'class'=>'large-text',	'placeholder'=>'不填则使用标题'],
					'seo_description'	=> ['title'=>'SEO描述',	'type'=>'textarea'],
					'seo_keywords'		=> ['title'=>'SEO关键字','class'=>'large-text']
				]
			];

			wpjam_register_post_option('seo', $args+['context'=>'side',	'post_type'=>fn($v)=> $v != 'attachment' && is_post_type_viewable($v) ]);
			wpjam_register_term_option('seo', $args+['action'=>'edit',	'taxonomy'=>fn($v)=> is_taxonomy_viewable($v)]);
		}
	}
}

wpjam_register_option('wpjam-seo', [
	'title'			=> 'SEO设置',
	'model'			=> 'WPJAM_SEO',
	'plugin_page'	=> 'wpjam-seo',
	'current_tab'	=> 'seo',
	'menu_page'		=> ['tab_slug'=>'seo', 'order'=>20, 'summary'=>__FILE__]
]);
