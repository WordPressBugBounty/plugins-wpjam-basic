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
						if($(this).parent('label').siblings('label.checked').length || $(this).parent('label').nextAll('label').length ){
							return;
						}

						val	= null;
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

			return this;
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
				let $input	= $(this).attr('type', 'text').val($(this).attr('value')).wpColorPicker();

				$input.next('.description').appendTo($(this).parents('.wp-picker-container'));

				if($input.data('button_text')){
					$input.closest('.wp-picker-container').find('.wp-color-result-text').text($input.data('button_text'));
				}
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
					ui.oldTab.find('a').removeClass('nav-tab-active');

					$.wpjam_state('replace', window.location.href.split('#')[0]+ui.newTab.find('a').addClass('nav-tab-active').attr('href'));
				},
				create: function(event, ui){
					ui.tab.find('a').addClass('nav-tab-active');
				}
			});
		},

		wpjam_remaining: function(sub){
			let	max_items	= parseInt($(this).data('max_items'));

			if(max_items){
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

		wpjam_form_init: function(event){
			$(':checked[data-wrap_id]').parent('label').addClass('checked');
			$('.sortable[class^="mu-"]').not('.ui-sortable').wpjam_sortable();
			$('.tabs').not('.ui-tabs').wpjam_tabs();
			$('[data-show_if]').not('.show_if').wpjam_show_if_init();
			$('[data-custom_validity]').wpjam_custom_validity();
			$('.plupload[data-plupload]').wpjam_uploader_init();
			$('input[data-data_type]').not('.ui-autocomplete-input').wpjam_autocomplete();
			$('input[type="color"]').wpjam_color();
			$('textarea[data-editor]').wpjam_editor();
		}
	});

	$('body').on('change', '[data-wrap_id]', function(){
		let wrap_id	= $(this).data('wrap_id');

		if($(this).is(':radio')){
			if($(this).is(':checked')){
				$('#'+wrap_id+' label').removeClass('checked');
			}
		}else if($(this).is(':checked')){
			if(!$('#'+wrap_id).wpjam_remaining('input:checkbox:checked')){
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
			$(this).prev('input').val('').removeClass('hidden').change();
		});
	});

	$('body').on('click', '[data-modal]', function(e){
		wpjam_modal($(this).data('modal'));
	});

	$('body').on('click', '.wpjam-file a, .wpjam-image a', function(e){
		let _this	= $(this);

		let item_type	= _this.parent().data('item_type');
		let title		= _this.text();

		wp.media({
			id:			'uploader_'+_this.prev('input').prop('id'),
			title:		title,
			library:	{ type: item_type },
			// button:		{ text: title },
			multiple:	false
		}).on('select', function(){
			$.wpjam_select_media((attachment) => _this.prev('input').val(item_type == 'image' ? $.wpjam_attachment_url(attachment) : attachment.url));
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
			id:			'uploader_'+_this.find('input').prop('id'),
			title:		'选择图片',
			library:	{ type: 'image' },
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

				if(_this.find('a.add-media').length){
					_this.find('input').val(_this.data('item_type') == 'url' ? $.wpjam_attachment_url(attachment) : attachment.id);
					_this.find('img').prop('src', src).fadeIn(300, function(){
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

		let remaining	= mu.wpjam_remaining(' > div.mu-item');
		let selected	= 0;

		if(!remaining){
			return false;
		}

		let _this		= $(this);
		let _parent		= _this.parent();
		let new_item	= _parent.clone(); 
		let item_type	= _this.data('item_type');

		if(mu.hasClass('mu-text')){	// 添加多个选项
			new_item.find(':input').val('').end().find('input[data-data_type]').removeClass('hidden').wpjam_autocomplete().end().insertAfter(_parent);

			_this.remove();
		}else if(mu.hasClass('mu-fields')){
			let i		= _this.data('i');
			let render	= wp.template(_this.data('tmpl_id'));

			new_item.find('script, .new-item').remove().end().prepend($(render({i:i}))).insertBefore(_parent);

			_this.data('i', i+1);

			$.wpjam_form_init();
		}else if(mu.hasClass('mu-img')){	//上传多个图片
			let args	= {
				id:			'uploader_'+mu.prop('id'),
				title:		'选择图片',
				library:	{ type: 'image' },
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
					let val	= item_type == 'url' ? $.wpjam_attachment_url(attachment) : attachment.id;
					let img	= '<img src="'+attachment.url+_this.data('thumb_args')+'" data-modal="'+attachment.url+'" />';

					new_item.find('.new-item').remove().end().find('input').val(val).end().prepend(img).insertBefore(_parent);
				});
			}).open();
		}else if(mu.hasClass('mu-file') || mu.hasClass('mu-image')){	//上传多个图片或者文件
			wp.media({
				id:			'uploader_'+_this.parents(parent).prop('id'),
				title:		_this.text(),
				library:	{ type: item_type },
				multiple:	true
			}).on('select', function(){
				$.wpjam_select_media((attachment) => {
					let val	= item_type == 'image' ? $.wpjam_attachment_url(attachment) : attachment.url;

					new_item.find('.new-item').remove().end().find('input').val(val).end().insertBefore(_parent);
				});
			}).on('selection:toggle', function(e){
				console.log(wp.media.frame.state().get('selection'));
			}).open();
		}

		return false;
	});

	// 删除图片
	$('body').on('click', 'a.del-img', function(){
		let $parent	= $(this).parent();

		$parent.find('input').val('');

		$(this).parent().find('img').fadeOut(300, function(){
			if($parent.find('a.add-media').length){
				$(this).removeAttr('src');
			}else{
				$(this).remove();
			}
		});

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


( function ( $, undef ) {

	var wpColorPickerAlpha = {
		'version': 304
	};

	// Always try to use the last version of this script.
	if ( 'wpColorPickerAlpha' in window && 'version' in window.wpColorPickerAlpha ) {
		var version = parseInt( window.wpColorPickerAlpha.version, 10 );
		if ( !isNaN( version ) && version >= wpColorPickerAlpha.version ) {
			return;
		}
	}

	// Prevent multiple initiations
	if ( Color.fn.hasOwnProperty( 'to_s' ) ) {
		return;
	}

	// Create new method to replace the `Color.toString()` inside the scripts.
	Color.fn.to_s = function ( type ) {
		if ( this.error ) {
			return '';
		}
		type = ( type || 'hex' );
		// Change hex to rgba to return the correct color.
		if ( 'hex' === type && this._alpha < 1 ) {
			type = 'rgba';
		}

		var color = '';
		if ( 'hex' === type ) {
			color = this.toString();
		} else if ( 'octohex' === type ) {
			color = this.toString();
			var alpha = parseInt( 255 * this._alpha, 10 ).toString( 16 );
			if ( alpha.length === 1 ) {
				alpha = `0${alpha}`;
			}
			color += alpha;
		} else {
			color = this.toCSS( type ).replace( /\(\s+/, '(' ).replace( /\s+\)/, ')' );
		}

		return color;
	}

	Color.fn.fromHex = function ( color ) {
		color = color.replace( /^#/, '' ).replace( /^0x/, '' );
		if ( 3 === color.length || 4 === color.length ) {
			var extendedColor = '';
			for ( var index = 0; index < color.length; index++ ) {
				extendedColor += '' + color[ index ];
				extendedColor += '' + color[ index ];
			}
			color = extendedColor;
		}

		if ( color.length === 8 ) {
			if ( /^[0-9A-F]{8}$/i.test( color ) ) {
				var alpha = parseInt( color.substring( 6 ), 16 );
				if ( !isNaN( alpha ) ) {
					this.a( alpha / 255 );
				} else {
					this._error();
				}
			} else {
				this._error();
			}
			color = color.substring( 0, 6 );
		}

		if ( !this.error ) {
			this.error = ! /^[0-9A-F]{6}$/i.test( color );
		}

		// console.log(color + ': ' + this.a())
		return this.fromInt( parseInt( color, 16 ) );
	}

	// Register the global variable.
	window.wpColorPickerAlpha = wpColorPickerAlpha;

	// Background image encoded
	var backgroundImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAAHnlligAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAHJJREFUeNpi+P///4EDBxiAGMgCCCAGFB5AADGCRBgYDh48CCRZIJS9vT2QBAggFBkmBiSAogxFBiCAoHogAKIKAlBUYTELAiAmEtABEECk20G6BOmuIl0CIMBQ/IEMkO0myiSSraaaBhZcbkUOs0HuBwDplz5uFJ3Z4gAAAABJRU5ErkJggg==';

	/**
	 * Iris
	 */
	$.widget( 'a8c.iris', $.a8c.iris, {
		/**
		 * Alpha options
		 *
		 * @since 3.0.0
		 *
		 * @type {Object}
		 */
		alphaOptions: {
			alphaEnabled: false,
		},
		/**
		 * Get the current color or the new color.
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @param {Object|*} The color instance if not defined return the current color.
		 *
		 * @return {string} The element's color.
		 */
		_getColor: function ( color ) {
			if ( color === undef ) {
				color = this._color;
			}

			if ( this.alphaOptions.alphaEnabled ) {
				color = color.to_s( this.alphaOptions.alphaColorType );
				if ( !this.alphaOptions.alphaColorWithSpace ) {
					color = color.replace( /\s+/g, '' );
				}
				return color;
			}
			return color.toString();
		},
		/**
		 * Create widget
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_create: function () {
			try {
				// Try to get the wpColorPicker alpha options.
				this.alphaOptions = this.element.wpColorPicker( 'instance' ).alphaOptions;
			} catch ( e ) { }

			// We make sure there are all options
			$.extend( {}, this.alphaOptions, {
				alphaEnabled: false,
				alphaCustomWidth: 130,
				alphaReset: false,
				alphaColorType: 'hex',
				alphaColorWithSpace: false,
				alphaSkipDebounce: false,
				alphaDebounceTimeout: 100,
			} );

			this._super();
		},
		/**
		 * Binds event listeners to the Iris.
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_addInputListeners: function ( input ) {
			var self = this,
				callback = function ( event ) {
					var val = input.val(),
						color = new Color( val ),
						val = val.replace( /^(#|(rgb|hsl)a?)/, '' ),
						type = self.alphaOptions.alphaColorType;

					input.removeClass( 'iris-error' );

					if ( !color.error ) {
						// let's not do this on keyup for hex shortcodes
						if ( 'hex' !== type || !( event.type === 'keyup' && val.match( /^[0-9a-fA-F]{3}$/ ) ) ) {
							// Compare color ( #AARRGGBB )
							if ( color.toIEOctoHex() !== self._color.toIEOctoHex() ) {
								self._setOption( 'color', self._getColor( color ) );
							}
						}
					} else if ( val !== '' ) {
						input.addClass( 'iris-error' );
					}
				};

			input.on( 'change', callback );

			if ( !self.alphaOptions.alphaSkipDebounce ) {
				input.on( 'keyup', self._debounce( callback, self.alphaOptions.alphaDebounceTimeout ) );
			}

			// If we initialized hidden, show on first focus. The rest is up to you.
			if ( self.options.hide ) {
				input.one( 'focus', function () {
					self.show();
				} );
			}
		},
		/**
		 * Init Controls
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_initControls: function () {
			this._super();

			if ( this.alphaOptions.alphaEnabled ) {
				// Create Alpha controls
				var self = this,
					stripAlpha = self.controls.strip.clone( false, false ),
					stripAlphaSlider = stripAlpha.find( '.iris-slider-offset' ),
					controls = {
						stripAlpha: stripAlpha,
						stripAlphaSlider: stripAlphaSlider
					};

				stripAlpha.addClass( 'iris-strip-alpha' );
				stripAlphaSlider.addClass( 'iris-slider-offset-alpha' );
				stripAlpha.appendTo( self.picker.find( '.iris-picker-inner' ) );

				// Push new controls
				$.each( controls, function ( k, v ) {
					self.controls[ k ] = v;
				} );

				// Create slider
				self.controls.stripAlphaSlider.slider( {
					orientation: 'vertical',
					min: 0,
					max: 100,
					step: 1,
					value: parseInt( self._color._alpha * 100 ),
					slide: function ( event, ui ) {
						self.active = 'strip';
						// Update alpha value
						self._color._alpha = parseFloat( ui.value / 100 );
						self._change.apply( self, arguments );
					}
				} );
			}
		},
		/**
		 * Create the controls sizes
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @param {bool} reset Set to True for recreate the controls sizes.
		 *
		 * @return {void}
		 */
		_dimensions: function ( reset ) {
			this._super( reset );

			if ( this.alphaOptions.alphaEnabled ) {
				var self = this,
					opts = self.options,
					controls = self.controls,
					square = controls.square,
					strip = self.picker.find( '.iris-strip' ),
					innerWidth, squareWidth, stripWidth, stripMargin, totalWidth;

				/**
				 * I use Math.round() to avoid possible size errors,
				 * this function returns the value of a number rounded
				 * to the nearest integer.
				 *
				 * The width to append all widgets,
				 * if border is enabled, 22 is subtracted.
				 * 20 for css left and right property
				 * 2 for css border
				 */
				innerWidth = Math.round( self.picker.outerWidth( true ) - ( opts.border ? 22 : 0 ) );
				// The width of the draggable, aka square.
				squareWidth = Math.round( square.outerWidth() );
				// The width for the sliders
				stripWidth = Math.round( ( innerWidth - squareWidth ) / 2 );
				// The margin for the sliders
				stripMargin = Math.round( stripWidth / 2 );
				// The total width of the elements.
				totalWidth = Math.round( squareWidth + ( stripWidth * 2 ) + ( stripMargin * 2 ) );

				// Check and change if necessary.
				while ( totalWidth > innerWidth ) {
					stripWidth = Math.round( stripWidth - 2 );
					stripMargin = Math.round( stripMargin - 1 );
					totalWidth = Math.round( squareWidth + ( stripWidth * 2 ) + ( stripMargin * 2 ) );
				}

				square.css( 'margin', '0' );
				strip.width( stripWidth ).css( 'margin-left', stripMargin + 'px' );
			}
		},
		/**
		 * Callback to update the controls and the current color.
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_change: function () {
			var self = this,
				active = self.active;

			self._super();

			if ( self.alphaOptions.alphaEnabled ) {
				var controls = self.controls,
					alpha = parseInt( self._color._alpha * 100 ),
					color = self._color.toRgb(),
					gradient = [
						'rgb(' + color.r + ',' + color.g + ',' + color.b + ') 0%',
						'rgba(' + color.r + ',' + color.g + ',' + color.b + ', 0) 100%'
					],
					target = self.picker.closest( '.wp-picker-container' ).find( '.wp-color-result' );

				self.options.color = self._getColor();
				// Generate background slider alpha, only for CSS3.
				controls.stripAlpha.css( { 'background': 'linear-gradient(to bottom, ' + gradient.join( ', ' ) + '), url(' + backgroundImage + ')' } );
				// Update alpha value
				if ( active ) {
					controls.stripAlphaSlider.slider( 'value', alpha );
				}

				if ( !self._color.error ) {
					self.element.removeClass( 'iris-error' ).val( self.options.color );
				}

				self.picker.find( '.iris-palette-container' ).on( 'click.palette', '.iris-palette', function () {
					var color = $( this ).data( 'color' );
					if ( self.alphaOptions.alphaReset ) {
						self._color._alpha = 1;
						color = self._getColor();
					}
					self._setOption( 'color', color );
				} );
			}
		},
		/**
		 * Paint dimensions.
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @param {string} origin  Origin (position).
		 * @param {string} control Type of the control,
		 *
		 * @return {void}
		 */
		_paintDimension: function ( origin, control ) {
			var self = this,
				color = false;

			// Fix for slider hue opacity.
			if ( self.alphaOptions.alphaEnabled && 'strip' === control ) {
				color = self._color;
				self._color = new Color( color.toString() );
				self.hue = self._color.h();
			}

			self._super( origin, control );

			// Restore the color after paint.
			if ( color ) {
				self._color = color;
			}
		},
		/**
		 * To update the options, see original source to view the available options.
		 *
		 * @since 3.0.0
		 *
		 * @param {string} key   The Option name.
		 * @param {mixed} value  The Option value to update.
		 *
		 * @return {void}
		 */
		_setOption: function ( key, value ) {
			var self = this;
			if ( 'color' === key && self.alphaOptions.alphaEnabled ) {
				// cast to string in case we have a number
				value = '' + value;
				newColor = new Color( value ).setHSpace( self.options.mode );
				// Check if error && Check the color to prevent callbacks with the same color.
				if ( !newColor.error && self._getColor( newColor ) !== self._getColor() ) {
					self._color = newColor;
					self.options.color = self._getColor();
					self.active = 'external';
					self._change();
				}
			} else {
				return self._super( key, value );
			}
		},
		/**
		 * Returns the iris object if no new color is provided. If a new color is provided, it sets the new color.
		 *
		 * @param newColor {string|*} The new color to use. Can be undefined.
		 *
		 * @since 3.0.0
		 *
		 * @return {string} The element's color.
		 */
		color: function ( newColor ) {
			if ( newColor === true ) {
				return this._color.clone();
			}
			if ( newColor === undef ) {
				return this._getColor();
			}
			this.option( 'color', newColor );
		},
	} );

	/**
	 * wpColorPicker
	 */
	$.widget( 'wp.wpColorPicker', $.wp.wpColorPicker, {
		/**
		 * Alpha options
		 *
		 * @since 3.0.0
		 *
		 * @type {Object}
		 */
		alphaOptions: {
			alphaEnabled: false,
		},
		/**
		 * Get the alpha options.
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {object} The current alpha options.
		 */
		_getAlphaOptions: function () {
			var el = this.element,
				type = ( el.data( 'type' ) || this.options.type ),
				color = ( el.data( 'defaultColor' ) || el.val() ),
				options = {
					alphaEnabled: ( el.data( 'alphaEnabled' ) || false ),
					alphaCustomWidth: 130,
					alphaReset: false,
					alphaColorType: 'rgb',
					alphaColorWithSpace: false,
					alphaSkipDebounce: ( !!el.data( 'alphaSkipDebounce' ) || false ),
				};

			if ( options.alphaEnabled ) {
				options.alphaEnabled = ( el.is( 'input' ) && 'full' === type );
			}

			if ( !options.alphaEnabled ) {
				return options;
			}

			options.alphaColorWithSpace = ( color && color.match( /\s/ ) );

			$.each( options, function ( name, defaultValue ) {
				var value = ( el.data( name ) || defaultValue );
				switch ( name ) {
					case 'alphaCustomWidth':
						value = ( value ? parseInt( value, 10 ) : 0 );
						value = ( isNaN( value ) ? defaultValue : value );
						break;
					case 'alphaColorType':
						if ( !value.match( /^((octo)?hex|(rgb|hsl)a?)$/ ) ) {
							if ( color && color.match( /^#/ ) ) {
								value = 'hex';
							} else if ( color && color.match( /^hsla?/ ) ) {
								value = 'hsl';
							} else {
								value = defaultValue;
							}
						}
						break;
					default:
						value = !!value;
						break;
				}
				options[ name ] = value;
			} );

			return options;
		},
		/**
		 * Create widget
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_create: function () {
			// Return early if Iris support is missing.
			if ( !$.support.iris ) {
				return;
			}

			// Set the alpha options for the current instance.
			this.alphaOptions = this._getAlphaOptions();

			// Create widget.
			this._super();
		},
		/**
		 * Binds event listeners to the color picker and create options, etc...
		 *
		 * @since 3.0.0
		 * @access private
		 *
		 * @return {void}
		 */
		_addListeners: function () {
			if ( !this.alphaOptions.alphaEnabled ) {
				return this._super();
			}

			var self = this,
				el = self.element,
				isDeprecated = self.toggler.is( 'a' );

			this.alphaOptions.defaultWidth = el.width();
			if ( this.alphaOptions.alphaCustomWidth ) {
				el.width( parseInt( this.alphaOptions.defaultWidth + this.alphaOptions.alphaCustomWidth, 10 ) );
			}

			self.toggler.css( {
				'position': 'relative',
				'background-image': 'url(' + backgroundImage + ')'
			} );

			if ( isDeprecated ) {
				self.toggler.html( '<span class="color-alpha" />' );
			} else {
				self.toggler.append( '<span class="color-alpha" />' );
			}

			self.colorAlpha = self.toggler.find( 'span.color-alpha' ).css( {
				'width': '30px',
				'height': '100%',
				'position': 'absolute',
				'top': 0,
				'background-color': el.val(),
			} );

			// Define the correct position for ltr or rtl direction.
			if ( 'ltr' === self.colorAlpha.css( 'direction' ) ) {
				self.colorAlpha.css( {
					'border-bottom-left-radius': '2px',
					'border-top-left-radius': '2px',
					'left': 0
				} );
			} else {
				self.colorAlpha.css( {
					'border-bottom-right-radius': '2px',
					'border-top-right-radius': '2px',
					'right': 0
				} );
			}


			el.iris( {
				/**
				 * @summary Handles the onChange event if one has been defined in the options.
				 *
				 * Handles the onChange event if one has been defined in the options and additionally
				 * sets the background color for the toggler element.
				 *
				 * @since 3.0.0
				 *
				 * @param {Event} event    The event that's being called.
				 * @param {HTMLElement} ui The HTMLElement containing the color picker.
				 *
				 * @returns {void}
				 */
				change: function ( event, ui ) {
					self.colorAlpha.css( { 'background-color': ui.color.to_s( self.alphaOptions.alphaColorType ) } );

					// fire change callback if we have one
					if ( typeof self.options.change === 'function' ) {
						self.options.change.call( this, event, ui );
					}
				}
			} );


			/**
			 * Prevent any clicks inside this widget from leaking to the top and closing it.
			 *
			 * @since 3.0.0
			 *
			 * @param {Event} event The event that's being called.
			 *
			 * @return {void}
			 */
			self.wrap.on( 'click.wpcolorpicker', function ( event ) {
				event.stopPropagation();
			} );

			/**
			 * Open or close the color picker depending on the class.
			 *
			 * @since 3.0.0
			 */
			self.toggler.on( 'click', function () {
				if ( self.toggler.hasClass( 'wp-picker-open' ) ) {
					self.close();
				} else {
					self.open();
				}
			} );

			/**
			 * Checks if value is empty when changing the color in the color picker.
			 * If so, the background color is cleared.
			 *
			 * @since 3.0.0
			 *
			 * @param {Event} event The event that's being called.
			 *
			 * @return {void}
			 */
			el.on( 'change', function ( event ) {
				var val = $( this ).val();

				if ( el.hasClass( 'iris-error' ) || val === '' || val.match( /^(#|(rgb|hsl)a?)$/ ) ) {
					if ( isDeprecated ) {
						self.toggler.removeAttr( 'style' );
					}

					self.colorAlpha.css( 'background-color', '' );

					// fire clear callback if we have one
					if ( typeof self.options.clear === 'function' ) {
						self.options.clear.call( this, event );
					}
				}
			} );

			/**
			 * Enables the user to either clear the color in the color picker or revert back to the default color.
			 *
			 * @since 3.0.0
			 *
			 * @param {Event} event The event that's being called.
			 *
			 * @return {void}
			 */
			self.button.on( 'click', function ( event ) {
				if ( $( this ).hasClass( 'wp-picker-default' ) ) {
					el.val( self.options.defaultColor ).change();
				} else if ( $( this ).hasClass( 'wp-picker-clear' ) ) {
					el.val( '' );
					if ( isDeprecated ) {
						self.toggler.removeAttr( 'style' );
					}

					self.colorAlpha.css( 'background-color', '' );

					// fire clear callback if we have one
					if ( typeof self.options.clear === 'function' ) {
						self.options.clear.call( this, event );
					}

					el.trigger( 'change' );
				}
			} );
		},
	} );
} )( jQuery );