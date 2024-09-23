jQuery(function($){
	$.fn.extend({
		wpjam_uploader_init: function(){
			this.each(function(){
				let hidden	= $(this).find('input[type=hidden]');

				if(hidden.val()){
					hidden.addClass('hidden');
				}

				let up_args		= $(this).data('plupload');
				let uploader	= new plupload.Uploader($.extend({}, up_args, {
					url : ajaxurl,
					multipart_params : $.wpjam_append_page_setting(up_args.multipart_params)
				}));

				$(this).removeAttr('data-plupload').removeData('plupload');

				uploader.bind('init', function(up){
					let up_container = $(up.settings.container);
					let up_drag_drop = $(up.settings.drop_element);

					if(up.features.dragdrop){
						up_drag_drop.on('dragover.wp-uploader', function(){
							up_container.addClass('drag-over');
						}).on('dragleave.wp-uploader, drop.wp-uploader', function(){
							up_container.removeClass('drag-over');
						});
					}else{
						up_drag_drop.off('.wp-uploader');
					}
				});

				uploader.bind('postinit', function(up){
					up.refresh();
				});

				uploader.bind('FilesAdded', function(up, files){
					$(up.settings.container).find('.button').hide();

					up.refresh();
					up.start();
				});

				uploader.bind('Error', function(up, error){
					alert(error.message);
				});

				uploader.bind('UploadProgress', function(up, file){
					$(up.settings.container).find('.progress').show().end().find('.bar').width((200 * file.loaded) / file.size).end().find('.percent').html(file.percent + '%');
				});

				uploader.bind('FileUploaded', function(up, file, result){
					let response	= JSON.parse(result.response);

					$(up.settings.container).find('.progress').hide().end().find('.button').show();

					if(response.errcode){
						alert(response.errmsg);
					}else{
						let query_title	= $(up.settings.container).find('.field-key-'+up.settings.file_data_name).addClass('hidden').val(response.path).end().find('.query-title');

						query_title.html(() => query_title.children().prop('outerHTML')+response.path.split('/').pop());
					}
				});

				uploader.bind('UploadComplete', function(up, files){});

				uploader.init();
			});
		},

		wpjam_show_if: function(scope=null){
			let _this;

			if(this instanceof jQuery){
				scope	= scope || $('body');
				_this	= this;
			}else{
				scope	= $('body');
				_this	= $([this]);
			}

			_this.each(function(){
				if(!$(this).hasClass('show_if_key')){
					return;
				}

				let key	= $(this).data('key');
				let val	= $(this).val();

				if($(this).is(':checkbox')){
					let wrap_id	= $(this).data('wrap_id');

					if(wrap_id){
						val	= $('#'+wrap_id+' input:checked').map((i, item) => $(item).val()).get();
					}else{
						if(!$(this).is(':checked')){
							val	= 0;
						}
					}
				}else if($(this).is(':radio')){
					if(!$(this).is(':checked')){
						return;
					}
				}else if($(this).is('span')){
					val	= $(this).data('value');
				}

				if($(this).prop('disabled')){
					val	= null;
				}

				scope.find('.show_if-'+key).each(function(){
					let data	= $(this).data('show_if');

					if(data.compare || !data.query_arg){
						let show	= val === null ? false : $.wpjam_compare(val, data);

						if(show){
							$(this).removeClass('hidden');
						}else{
							$(this).addClass('hidden');

							if($(this).is('option') && $(this).is(':selected')){
								$(this).parent().prop('selectedIndex', 0);
							}
						}

						if($(this).is('option')){
							$(this).prop('disabled', !show);
							$(this).parents('select').wpjam_show_if(scope);
						}else{
							$(this).find(':input').not('.disabled').prop('disabled', !show);
							$(this).find('.show_if_key').wpjam_show_if(scope);
						}
					}

					if(!$(this).hasClass('hidden') && data.query_arg){
						let query_var	= data.query_arg;
						let show_if_el	= $(this);
						let query_el	= $(this).find('[data-data_type]');

						if(query_el.length > 0){
							let query_args	= query_el.data('query_args');

							if(query_args[query_var] != val){
								query_el.data('query_args', $.extend(query_args, {[query_var] : val}));

								if(query_el.is('input')){
									query_el.val('').removeClass('hidden');
								}else if(query_el.is('select')){
									query_el.find('option').filter((i, item) => item.value).remove();
									show_if_el.addClass('hidden');

									query_el.wpjam_query((items) => {
										if(items.length > 0){
											$.each(items, (i, item) => query_el.append('<option value="'+item.value+'">'+item.label+'</option>'));

											show_if_el.removeClass('hidden');
										}

										query_el.wpjam_show_if(scope);
									});
								}
							}else{
								if(query_el.is('select')){
									if(query_el.find('option').filter((i, item) => item.value).length == 0){
										$(this).addClass('hidden');
									}
								}
							}
						}
					}
				});
			});
		},

		wpjam_custom_validity:function(){
			$(this).off('invalid').on('invalid', function(){ 
				this.setCustomValidity($(this).data('custom_validity'));
			}).on('input', function(){
				this.setCustomValidity('');
			});
		},

		wpjam_show_if_init:function(){
			let els	= [];

			this.each(function(){
				let data	= $(this).data('show_if');
				let key		= data.key;
				let el		= data.external ? '#'+key : '.field-key-'+key;

				$(this).addClass(['show_if', 'show_if-'+key]);

				if(data.query_arg){
					let query_el	= $(this).find('[data-data_type]');

					if(query_el.length){
						let query_var	= data.query_arg;
						let query_args	= query_el.data('query_args');

						if(!query_args[query_var]){
							if((query_el.is('input') && query_el.val()) || (query_el.is('select') && $(el).val())){
								query_el.data('query_args', $.extend(query_args, {[query_var] : $(el).val()}));
							}
						}
					}
				}

				$(el).data('key', key).addClass('show_if_key');

				if($.inArray(el, els) === -1){
					els.push(el);
				}
			});

			$.each(els, (i, el) => $(el).wpjam_show_if() );
		},

		wpjam_autocomplete: function(){
			this.each(function(){
				if($(this).data('query_args')){
					if($(this).next('.query-title').length){
						if($(this).val()){
							$(this).addClass('hidden');
						}else{
							$(this).removeClass('hidden');
						}
					}

					$(this).autocomplete({
						minLength:	0,
						source: function(request, response){
							this.element.wpjam_query(response, request.term);
						},
						select: function(event, ui){
							if($(this).next('.query-title').length){
								let query_title	= $(this).addClass('hidden').next('.query-title');

								query_title.html(() => query_title.children().prop('outerHTML')+ui.item.label);
							}
						},
						change: function(event, ui){
							$(this).wpjam_show_if();
						}
					}).focus(function(){
						if(this.value == ''){
							$(this).autocomplete('search');
						}
					});
				}
			});
		},

		wpjam_query: function(callback, term){
			let data_type	= $(this).data('data_type');
			let query_args	= $(this).data('query_args');

			if(term){
				if(data_type == 'post_type'){
					query_args.s		= term;
				}else{
					query_args.search	= term;
				}
			}

			$.wpjam_post({
				action:		'wpjam-query',
				data_type:	data_type,
				query_args:	query_args
			}, (data) => callback.call($(this), data.items));
		},

		wpjam_color: function(){
			this.each(function(){
				$(this).attr('type', 'text').val($(this).attr('value')).wpColorPicker().next('.description').appendTo($(this).parents('.wp-picker-container'));
			});
		},

		wpjam_editor: function(){
			if(this.length){
				if(wp.editor){
					this.each(function(){
						let id	= $(this).attr('id');

						wp.editor.remove(id);
						wp.editor.initialize(id, $(this).data('editor'));
					});

					$(this).removeAttr('data-editor').removeData('editor');
				}else{
					console.log('请在页面加载 add_action(\'admin_footer\', \'wp_enqueue_editor\');');
				}
			}
		},

		wpjam_sortable: function(){
			if(this.length){
				let args	= {cursor: 'move'};

				if(!$(this).hasClass('mu-img')){
					args.handle	= '.dashicons-menu';
				}

				$(this).sortable(args);
			}
		},

		wpjam_tabs: function(){
			$(this).tabs({
				activate: function(event, ui){
					$('.ui-corner-top a').removeClass('nav-tab-active');
					$('.ui-tabs-active a').addClass('nav-tab-active');

					let tab_href = window.location.origin + window.location.pathname + window.location.search +ui.newTab.find('a').attr('href');
					window.history.replaceState(null, null, tab_href);
					$('input[name="_wp_http_referer"]').val(tab_href);
				},
				create: function(event, ui){
					if(ui.tab.find('a').length){
						ui.tab.find('a').addClass('nav-tab-active');
						if(window.location.hash){
							$('input[name="_wp_http_referer"]').val($('input[name="_wp_http_referer"]').val()+window.location.hash);
						}
					}
				}
			});
		},

		wpjam_remaining: function(){
			let	max_items	= parseInt($(this).data('max_items'));

			if(max_items){
				let sub		= $(this).hasClass('mu-checkbox') ? 'input:checkbox:checked' : ' > div.mu-item';
				let count	= $(this).find(sub).length;

				if($(this).hasClass('mu-img') || $(this).hasClass('mu-fields') || $(this).hasClass('direction-row')){
					count --;
				}

				if(count >= max_items){
					alert('最多支持'+max_items+'个');

					return 0;
				}else{
					return max_items - count;
				}
			}

			return -1;
		}
	});

	$.extend({
		wpjam_select_media: function(callback){
			wp.media.frame.state().get('selection').map((attachment) => callback(attachment.toJSON()) );
		},

		wpjam_attachment_url(attachment){
			return attachment.url+'?'+$.param({orientation:attachment.orientation, width:attachment.width, height:attachment.height});
		},

		wpjam_compare: function(a, data){
			let compare	= data.compare;

			if(compare){
				compare	= compare.toUpperCase();

				let antonyms	= {
					'!=': '=',
					'<=': '>',
					'>=': '<',
					'NOT IN': 'IN',
					'NOT BETWEEN': 'BETWEEN'
				};

				if(antonyms.hasOwnProperty(compare)){
					return !$.wpjam_compare(a, $.extend({}, data, {'compare' : antonyms[compare]}));
				}
			}

			let b		= data.value;
			let swap	= data.swap;

			if(Array.isArray(a)){
				swap	= true;
			}

			if(swap){
				[a, b]	= [b, a];
			}

			if(!compare){
				compare	= Array.isArray(b) ? 'IN' : '=';
			}

			if(['IN', 'BETWEEN'].indexOf(compare) != -1){
				if(!Array.isArray(b)){
					b	= b.split(/[\s,]+/);
				}

				if(!Array.isArray(a) && b.length === 1) {
					b	= b[0];

					compare	= '=';
				}else{
					b	= b.map(String);
				}
			}else{
				if(typeof b === 'string'){
					b	= b.trim();
				}
			}

			if(compare == '='){
				return a == b;
			}else if(compare == '>'){
				return a > b;
			}else if(compare == '<'){
				return a < b;
			}else if(compare == 'IN'){
				return b.indexOf(a) != -1;
			}else if(compare == 'BETWEEN'){
				return a >= b[0] && a <= b[1];
			}else{
				return false;
			}
		},

		wpjam_form_init: function(event){
			$('.sortable[class^="mu-"]').not('.ui-sortable').wpjam_sortable();
			$('.tabs').not('.ui-tabs').wpjam_tabs();
			$('[data-show_if]').not('.show_if').wpjam_show_if_init();
			$('[data-custom_validity]').wpjam_custom_validity();
			$('.plupload[data-plupload]').wpjam_uploader_init();
			$('input[data-data_type]').not('.ui-autocomplete-input').wpjam_autocomplete();
			$('input[type="color"]').wpjam_color();
			$('textarea[data-editor]').wpjam_editor();
			$(':checked[data-wrap_id]').parent('label').addClass('checked');

			$('.wpjam-tooltip .wpjam-tooltip-text').css('margin-left', () => 0 - Math.round($(this).width()/2));
		}
	});

	$('body').on('change', '[data-wrap_id]', function(){
		let wrap_id	= $(this).data('wrap_id');

		if($(this).is(':radio')){
			if($(this).is(':checked')){
				$('#'+wrap_id+' label').removeClass('checked');
			}
		}else if($(this).is(':checked')){
			if(!$('#'+wrap_id).wpjam_remaining()){
				$(this).prop('checked', false);

				return false;
			}
		}

		if($(this).is(':checked')){
			$(this).parent('label').addClass('checked');
		}else{
			$(this).parent('label').removeClass('checked');
		}
	});

	$('body').on('change', '.show_if_key', $.fn.wpjam_show_if);

	$.wpjam_form_init();

	$('body').on('list_table_action_success', $.wpjam_form_init);
	$('body').on('page_action_success', $.wpjam_form_init);
	$('body').on('option_action_success', $.wpjam_form_init);

	$('body').on('click', '.query-title span.dashicons', function(){
		$(this).parent().fadeOut(300, function(){
			$(this).removeAttr('style').prev('input').val('').removeClass('hidden').change();
		});
	});

	$('body').on('click', '.wpjam-modal', function(e){
		e.preventDefault();

		wpjam_modal($(this).prop('href'));
	});

	$('body').on('click', '.wpjam-file a', function(e){
		let _this	= $(this);

		let item_type	= _this.data('item_type');
		let title		= item_type == 'image' ? '选择图片' : '选择文件';

		wp.media({
			id:			'uploader_'+_this.prev('input').prop('id'),
			title:		title,
			library:	{ type: item_type },
			button:		{ text: title },
			multiple:	false
		}).on('select', function(){
			$.wpjam_select_media((attachment) => _this.prev('input').val(_this.data('item_type') == 'image' ? $.wpjam_attachment_url(attachment) : attachment.url));
		}).open();

		return false;
	});

	//上传单个图片
	$('body').on('click', '.wpjam-img', function(e){
		if($(this).hasClass('readonly')){
			return false;
		}

		let _this	= $(this);
		let args	= {
			id:			'uploader_'+_this.next('input').prop('id'),
			title:		'选择图片',
			library:	{ type: 'image' },
			button:		{ text: '选择图片' },
			multiple:	false
		};

		let action	= 'select';

		if(wp.media.view.settings.post.id){
			args.frame	= 'post';
			action		= 'insert';
		}

		wp.media(args).on('open',function(){
			$('.media-frame').addClass('hide-menu');
		}).on(action, function(){
			$.wpjam_select_media((attachment) => {
				let src	= attachment.url+_this.data('thumb_args');

				if(_this.find('div.wp-media-buttons').length){
					_this.next('input').val(_this.data('item_type') == 'url' ? $.wpjam_attachment_url(attachment) : attachment.id);
					_this.find('img').removeClass('hidden').prop('src', src).fadeIn(300, function(){
						_this.show();
					});
				}else{
					_this.find('img').remove();
					_this.find('a.del-img').remove();
					_this.append('<img src="'+src+'" /><a href="javascript:;" class="del-img dashicons dashicons-no-alt"></a>');
				}
			});
		}).open();

		return false;
	});

	$('body').on('click', 'a.new-item', function(e){
		let mu	= $(this).parent().parent();

		let remaining	= mu.wpjam_remaining();
		let selected	= 0;

		if(!remaining){
			return false;
		}

		let _this	= $(this);

		if(mu.hasClass('mu-text')){	// 添加多个选项
			let item	= _this.parent().clone();

			item.insertAfter(_this.parent());
			item.find(':input').val('');
			item.find('input[data-data_type]').removeClass('hidden').wpjam_autocomplete();

			_this.remove();
		}else if(mu.hasClass('mu-fields')){
			let i		= _this.data('i');
			let render	= wp.template(_this.data('tmpl_id'));
			let item	= _this.parent().clone();

			item.insertBefore(_this.parent());
			item.find('script, .new-item').remove();
			item.prepend($(render({i:i})));

			_this.data('i', i+1);

			$.wpjam_form_init();
		}else if(mu.hasClass('mu-img')){	//上传多个图片
			let args	= {
				id:			'uploader_'+mu.prop('id'),
				title:		'选择图片',
				library:	{ type: 'image' },
				button:		{ text: '选择图片' },
				multiple:	true
			};

			let action	= 'select';

			if(wp.media.view.settings.post.id){
				args.frame	= 'post';
				action		= 'insert';
			}

			wp.media(args).on('selection:toggle', function(){
				let length	= wp.media.frame.state().get('selection').length;

				if(remaining != -1){
					if(length > remaining && length > selected){
						alert('最多还能选择'+remaining+'个');
					}

					$('.media-toolbar .media-button').prop('disabled', length > remaining);
				}

				selected	= length;
			}).on('open', function(){
				$('.media-frame').addClass('hide-menu');
			}).on(action, function(){
				$.wpjam_select_media((attachment) => {
					let item	= _this.parent().clone();

					item.find('.new-item').remove();
					item.find('input').val(_this.data('item_type') == 'url' ? $.wpjam_attachment_url(attachment) : attachment.id);
					item.prepend('<a class="wpjam-modal" href="'+attachment.url+'"><img src="'+attachment.url+_this.data('thumb_args')+'" /></a>');

					_this.parent().before(item);
				});
			}).open();
		}else if(mu.hasClass('mu-file') || mu.hasClass('mu-image')){	//上传多个图片或者文件
			wp.media({
				id:			'uploader_'+_this.parents(parent).prop('id'),
				title:		_this.data('title'),
				library:	{ type: _this.data('item_type') },
				button:		{ text: _this.data('title') },
				multiple:	true
			}).on('select', function(){
				$.wpjam_select_media((attachment) => {
					let item	= _this.parent().clone();
					let val 	= _this.data('item_type') == 'image' ? $.wpjam_attachment_url(attachment) : attachment.url;

					item.find('.new-item').remove();
					item.find('input').val(val);

					_this.parent().before(item);
				});
			}).on('selection:toggle', function(e){
				console.log(wp.media.frame.state().get('selection'));
			}).open();
		}

		return false;
	});

	// 删除图片
	$('body').on('click', 'a.del-img', function(){
		$(this).parent().next('input').val('');

		if($(this).prev('div.wp-media-buttons').length){
			$(this).parent().find('img').fadeOut(300, function(){
				$(this).prop('src', '').addClass('hidden');
			});
		}else{
			$(this).parent().find('img').fadeOut(300, function(){
				$(this).remove();
			});
		}

		return false;
	});

	// 删除选项
	$('body').on('click', 'a.del-item', function(){
		let next_input	= $(this).parent().next('input');
		if(next_input.length > 0){
			next_input.val('');
		}

		$(this).parent().fadeOut(300, function(){
			$(this).remove();
		});

		return false;
	});
});

if(self != top){
	document.getElementsByTagName('html')[0].className += ' TB_iframe';
}

function isset(obj){
	if(typeof(obj) != 'undefined' && obj !== null){
		return true;
	}else{
		return false;
	}
}

function wpjam_modal(src, type, css){
	type	= type || 'img';

	if(jQuery('#wpjam_modal_wrap').length == 0){
		jQuery('body').append('<div id="wpjam_modal_wrap" class="hidden"><div id="wpjam_modal"></div></div>');
		jQuery("<a id='wpjam_modal_close' class='dashicons dashicons-no-alt del-icon'></a>")
		.on('click', function(e){
			e.preventDefault();
			jQuery('#wpjam_modal_wrap').remove();
		})
		.prependTo('#wpjam_modal_wrap');
	}

	if(type == 'iframe'){
		css	= css || {};
		css = jQuery.extend({}, {width:'300px', height:'500px'}, css);

		jQuery('#wpjam_modal').html('<iframe style="width:100%; height: 100%;" src='+src+'>你的浏览器不支持 iframe。</iframe>');
		jQuery('#wpjam_modal_wrap').css(css).removeClass('hidden');
	}else if(type == 'img'){
		let img_preloader	= new Image();
		let img_tag			= '';

		img_preloader.onload	= function(){
			img_preloader.onload	= null;

			let width	= img_preloader.width/2;
			let height	= img_preloader.height/2;

			if(width > 400 || height > 500){
				let radio	= (width / height >= 400 / 500) ? (400 / width) : (500 / height);

				width	= width * radio;
				height	= height * radio;
			}

			jQuery('#wpjam_modal').html('<img src="'+src+'" width="'+width+'" height="'+height+'" />');
			jQuery('#wpjam_modal_wrap').css({width:width+'px', height:height+'px'}).removeClass('hidden');
		}

		img_preloader.src	= src;
	}
}

function wpjam_iframe(src, css){
	wpjam_modal(src, 'iframe', css);
}