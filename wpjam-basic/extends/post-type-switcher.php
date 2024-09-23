<?php
/*
Name: 文章类型转换器
URI: https://mp.weixin.qq.com/s/UPelWNVrolGCzi5POKJUaw
Description: 可以将文章在多种文章类型中进行转换。
Version: 1.0
*/
if(is_admin()){
	class WPJAM_Post_Type_Switcher{
		public static function get_options(){
			foreach(get_post_types(['show_ui'=>true], 'objects') as $ptype => $pt_object){
				if($ptype != 'attachment' && !str_starts_with($ptype, 'wp_') && current_user_can($pt_object->cap->publish_posts)){
					$options[$ptype]	= wpjam_get_post_type_setting($ptype, 'title');
				}
			}

			return $options ?? [];
		}

		public static function set($post_id, $ptype){
			if($ptype && get_post_type($post_id) != $ptype){
				if(!post_type_exists($ptype) || !current_user_can(get_post_type_object($ptype)->cap->publish_posts)){
					return new WP_Error('invalid_post_type');
				}

				$result	= set_post_type($post_id, $ptype);
			}else{
				$result	= null;
			}

			if(is_wp_error($result)){
				return $result;
			}

			return $result ? ['type'=>'redirect', 'url'=>admin_url('edit.php?post_type='.$ptype.'&id='.$post_id)] : ['errmsg'=>'未修改文章类型'];
		}

		public static function on_after_insert_post($post_id, $post){
			if((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)){
				return;
			}

			self::set($post_id, wpjam_get_request_parameter('ptype', ['sanitize_callback'=>'sanitize_key']));
		}

		public static function on_post_submitbox_misc_actions(){
			$current	= get_post_type();
			$pt_object	= get_post_type_object($current);

			?>

			<div class="misc-pub-section post-type-switcher">
				<label for="ptype">文章类型：</label>
				<strong id="post_type_display"><?php echo esc_html(wpjam_get_post_type_setting($current, 'title')); ?></strong>

				<?php if(current_user_can($pt_object->cap->publish_posts)){ ?>

				<a href="javascript:;" id="edit_post_type_switcher" class="hide-if-no-js"><?php _e( 'Edit' ); ?></a>

				<div id="post_type_select">
					<?php echo wpjam_field(['key'=>'ptype', 'value'=>$current, 'options'=>self::get_options()]); ?>

					<a href="javascript:;" id="save_post_type_switcher" class="hide-if-no-js button"><?php _e( 'OK' ); ?></a>
					<a href="javascript:;" id="cancel_post_type_switcher" class="hide-if-no-js button-cancel"><?php _e( 'Cancel' ); ?></a>
				</div>

				<?php } ?>

			</div>

			<?php
		}

		public static function on_admin_head(){
			?>
			<script type="text/javascript">
			jQuery(function($){
				$('#edit_post_type_switcher').on('click', function(e) {
					$(this).hide();
					$('#post_type_select').slideDown();
				});

				$('#save_post_type_switcher').on('click',  function(e) {
					$('#post_type_select').slideUp();
					$('#edit_post_type_switcher').show();
					$('#post_type_display').text($('#ptype :selected').text());
				});

				$('#cancel_post_type_switcher').on('click',  function(e) {
					$('#post_type_select').slideUp();
					$('#edit_post_type_switcher').show();
				});
			});
			</script>
			<style type="text/css">
			#post_type_select{ margin-top: 3px; display: none; }
			#post-body .post-type-switcher::before{ content: '\f109'; font: 400 20px/1 dashicons; speak: none;  display: inline-block; padding: 0 2px 0 0; top: 0; left: -1px; position: relative; vertical-align: top; text-decoration: none !important; color: #888; }
			</style>
			<?php
		}

		public static function builtin_page_load($screen){
			if($screen->base == 'edit'){
				wpjam_register_list_table_action('set_post_type', [
					'title'			=> '修改类型',
					'page_title'	=> '修改类型',
					'submit_text'	=> '修改',
					'callback'		=> fn($post_id, $data)=> self::set($post_id, $data['post_type']),
					'fields'		=> ['post_type'=>['title'=>'文章类型', 'options'=>self::get_options()]]
				]);
			}elseif($screen->base == 'post' && $screen->post_type != 'attachment' && !$screen->is_block_editor){
				add_action('wp_after_insert_post',			[self::class, 'on_after_insert_post'], 999, 2);
				add_action('post_submitbox_misc_actions',	[self::class, 'on_post_submitbox_misc_actions']);
				add_action('admin_head',					[self::class, 'on_admin_head']);
			}
		}
	}

	wpjam_add_admin_load([
		'base'	=> ['post','edit'], 
		'model'	=> 'WPJAM_Post_Type_Switcher'
	]);
}