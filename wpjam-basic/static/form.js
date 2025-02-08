jQuery(function($){
	$.fn.extend({
		wpjam_form_init: function(){
			$(this).find('[data-chart]').each(function(){
				let $this	= $(this).removeAttr('data-chart');
				let options	= $this.data('options');
				let type	= $this.data('type');

				if(type == 'Donut'){
					$this.height(Math.max(160, Math.min(240, $this.next('table').length ? $this.next('table').height() : 240))).width($this.height());
				}

				if(['Line', 'Bar', 'Donut'].includes(type)){
					Morris[type]({...options, element: $this.prop('id')});
				}
			});

			$(this).find('[data-description]').each(function(){
				$(this).addClass('dashicons dashicons-editor-help').attr('data-tooltip', $(this).data('description')).removeAttr('data-description');
			});

			$(this).find('.mu').each(function(){
				let $this	= $(this).removeClass('mu');
				let value	= $this.data('value');

				if(!$this.hasClass('mu-fields')){
					$this.wrapInner('<div class="mu-item"></div>');
				}

				$('<a class="new-item button">'+($this.hasClass('mu-img') ? '' : $this.data('button_text'))+'</a>').on('click', function(){
					let	max		= parseInt($this.data('max_items'));
					let rest	= max ? (max - ($this.children().length - ($this.is('.mu-img, .mu-fields, .direction-row') ? 1 : 0))) : 0;

					if(max && rest <= 0){
						alert('最多支持'+max+'个');

						return false;
					}

					if($this.is('.mu-text, .mu-fields')){
						$this.wpjam_mu_item();
					}else if($this.is('.mu-img, .mu-file, .mu-image')){
						wp.hooks.addAction('wpjam_media', 'wpjam', function(frame, args){
							if(args.rest){
								frame.state().get('selection').on('update', function(){
									if(this.length > args.rest){
										this.reset(this.first(args.rest));

										alert('最多可以选择'+args.rest+'个');
									}
								});
							}

							wp.hooks.removeAction('wpjam_media', 'wpjam');
						});

						wp.hooks.addAction('wpjam_media_selected', 'wpjam', function(value, url){
							$this.wpjam_mu_item($this.is('.mu-img') ? {value: value, url: url} : value);
						});

						$this.wpjam_media({
							id:			$this.prop('id'),
							multiple: 	true,
							rest:		rest
						});
					}

					return false;
				}).appendTo($this.find('> .mu-item').last());

				if($this.hasClass('mu-text')){
					if($this.find('select').length && !$this.find('option').toArray().some((opt) => $(opt).val() == '')){
						$this.find('select').prepend('<option disabled="disabled" hidden="hidden" value="" class="disabled">请选择</option>').val('');
					}

					if($this.hasClass('direction-row') && value.length <= 1){
						value.push(null);
					}
				}

				if(value){
					value.forEach(v => $this.wpjam_mu_item(v));
				}

				let sortable	= $this.is('.sortable:not(.disabled):not(.readonly)') && !$this.parents('.disabled,.readonly').length;
				let del_btn		= $this.hasClass('direction-row') ? '' : '删除';

				$this.find('> .mu-item').append([
					'<a href="javascript:;" class="del-item '+(del_btn ? 'button' : 'dashicons dashicons-no-alt')+'">'+del_btn+'</a>',
					sortable && !$this.hasClass('mu-img') ? '<span class="dashicons dashicons-menu"></span>' : ''
				]);

				if(sortable){
					$this.sortable({cursor: 'move', items: '.mu-item:not(:last-child)'});
				}
			});

			$(this).find('.checkable:not(.field-key)').each(function(){
				let $this	= $(this);
				let value	= $this.data('value');

				$this.addClass('field-key').find('input').on('change', function(){
					let $el		= $(this);
					let checked	= $el.is(':checked');

					if($el.is(':checkbox')){
						$this.find(':checkbox').toArray().forEach(el => el.setCustomValidity(''));

						if(!$this.wpjam_validity((checked ? 'max_items' : 'min_items'), $el[0])){
							$el[0].reportValidity();
						}
					}else{
						if(checked){
							$this.find('label').removeClass('checked');
						}
					}

					$el.parent('label').toggleClass('checked', checked);
				});

				if(value != null){
					(_.isArray(value) ? value : [value]).forEach(item => $this.find('input[value="'+item+'"]').click());
				}
			});

			$(this).find('[data-value]').each(function(){
				let $this	= $(this);
				let value	= $this.data('value');

				if(value){
					if($this.is(':checkbox')){
						$this.prop('checked', true);
					}else if($this.is('select')){
						$this.val(value);
					}else if($this.hasClass('wpjam-img')){
						$this.find('img').attr('src', value.url+$this.data('thumb_args')).end().find('input').val(value.value).end();
					}else if($this.is('span')){
						return;
					}
				}

				$this.removeAttr('data-value');
			});

			$(this).find('input[data-data_type][data-query_args]:not(.ui-autocomplete-input)').each(function(){
				let $this	= $(this);

				$this.wpjam_query_label($this.data('label')).autocomplete({
					minLength:	0,
					source: function(request, response){
						this.element.wpjam_query(items => $this.data('autocomplete_items', items) && response(items), request.term);
					},
					select: function(event, ui){
						let item	= $this.data('autocomplete_items').find(item => (_.isObject(item) ? item.value : item) == ui.item.value);

						if(_.isObject(item) && item.label){
							$this.wpjam_query_label(item.label);
						}
					},
					change: function(event, ui){
						$this.trigger('change.show_if');
					}
				}).focus(function(){
					if(!this.value){
						$this.autocomplete('search');
					}
				});
			});

			$(this).find('[data-media_button]').each(function(){
				let $this	= $(this);
				let button	= $this.data('media_button');

				$this.data('button_text', button).append([
					$this.hasClass('wpjam-img') ? '<a href="javascript:;" class="del-img dashicons dashicons-no-alt"></a>' : '',
					$('<a href="javascript:;" class="add-media button"><span class="dashicons dashicons-admin-media"></span>'+button+'</a>')
				]).removeAttr('data-media_button');
			});

			$(this).find('.plupload[data-plupload]').each(function(){
				let $this	= $(this);
				let $input	= $this.find('input');
				let up_args	= $this.data('plupload');

				if(up_args.drop_element){
					$this.addClass('drag-drop');
					$input.wrap('<p class="drag-drop-buttons"></p>');
					$this.prepend('<p class="drag-drop-info">'+up_args.drop_info[0]+'</p><p>'+up_args.drop_info[1]+'</p>');
					$this.wrapInner('<div class="plupload-drag-drop" id="'+up_args.drop_element+'"><div class="drag-drop-inside"></div></div>');
				}

				$this.attr('id', up_args.container).removeAttr('data-plupload');
				$this.append('<div class="progress hidden"><div class="percent"></div><div class="bar"></div></div>');
				$input.before('<input type="button" id="'+up_args.browse_button+'" value="'+up_args.button_text+'" class="button">').wpjam_query_label($input.val().split('/').pop());

				let uploader	= new plupload.Uploader({
					...up_args,
					url : ajaxurl,
					multipart_params : wpjam.append_page_setting(up_args.multipart_params)
				});

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
						$(up.settings.container).find('.field-key-'+up.settings.file_data_name).val(response.path).prev('span').remove().end().wpjam_query_label(response.path.split('/').pop());
					}
				});

				uploader.bind('UploadComplete', function(up, files){});

				uploader.init();
			});

			$(this).find('input[type="color"]').each(function(){
				let $this	= $(this).attr('type', 'text');
				let $label	= $this.val($this.attr('value')).parent('label');
				let button	= $this.data('button_text');
				let $picker	= $this.wpColorPicker().parents('.wp-picker-container').append($label.next('.description'));

				if($this.data('alpha-enabled')){
					$this.addClass('wp-color-picker-alpha');
				}

				if(button){
					$picker.find('.wp-color-result-text').text(button);
				}

				if($label.length && $label.text()){
					$label.prependTo($picker);
					$picker.find('button').add($picker.find('.wp-picker-input-wrap')).insertAfter($this);
					$this.prependTo($picker.find('.wp-picker-input-wrap'));
				}

				if($label.attr('data-show_if')){
					$picker.attr('data-show_if', $label.attr('data-show_if'));
					$label.removeAttr('data-show_if');
				}
			});

			$(this).find('input[type="timestamp"]').each(function(){
				let $this	= $(this);

				if($this.val()){
					let pad2	= (num)=> num.toString().padStart(2, '0');
					let date	= new Date(+$this.val()*1000);

					$this.val(date.getFullYear()+'-'+pad2(date.getMonth()+1)+'-'+pad2(date.getDate())+'T'+pad2(date.getHours())+':'+pad2(date.getMinutes()));
				}

				$this.attr('type', 'datetime-local');
			});

			$(this).find('input.tiny-text, input.small-text').addClass('expandable');
			$(this).find('input.expandable:not(.is-expanded)').each(function(){
				let $this	= $(this);

				$this.on('input.expandable', ()=> $this.width('').width(Math.min(522, $this.prop('scrollWidth')-($this.innerWidth()-$this.width()))).addClass('is-expanded')).trigger('input.expandable');
			});

			$(this).find('textarea[data-editor]').each(function(){
				let $this	= $(this);

				if(wp.editor){
					let id	= $this.attr('id');

					wp.editor.remove(id);
					wp.editor.initialize(id, $this.data('editor'));

					$this.attr({rows: 10, cols: 40}).removeAttr('data-editor');
				}else{
					console.log('请在页面加载 add_action(\'admin_footer\', \'wp_enqueue_editor\');');
				}
			});

			$(this).find('textarea:not([rows]), textarea:not([cols])').each(function(){
				let $this	= $(this);

				if(!$this.attr('rows')){
					$this.one('click', function(){
						let from	= $this.height();
						let to		= Math.min(320, $this.height('').prop('scrollHeight')+5);

						if(to > from+10){
							$this.height(from).animate({ height: to }, 300);
						}
					}).on('input', ()=> $this.height('').height(Math.min(320, $this.prop('scrollHeight')))).attr('rows', 4);
				}

				if(!$this.attr('cols')){
					$this.attr('cols', $this.parents('#TB_window').length ? 52 : 68);
				}
			});

			$(this).find('[data-show_if]:not(.show_if)').each(function(){
				let $this	= $(this);
				let data	= $this.data('show_if');
				let key		= data.key;
				let $if		= $(data.external ? '#'+key : '.field-key-'+key);
				let val		= $if.val();

				$this.addClass(['show_if', 'show_if-'+key]);

				if(!$if.hasClass('show_if_key')){
					$if.addClass('show_if_key once').on('change.show_if', function(){
						let val	= $(this).wpjam_val();

						$('body').find('.show_if-'+key).each(function(){
							$(this).wpjam_show_if(val);
						});
					});
				}

				if(data.query_arg){
					let arg	= data.query_arg;
					let $el	= $this.is(':input') ? ($this.data('data_type') ? $this : null) : $this.find('[data-data_type]').first();

					if($el){
						$this.data('query_el', $el);

						let query_args	= $el.data('query_args') || {};

						if(!query_args[arg]){
							query_args[arg]	= val;

							$el.data('query_args', query_args);
						}
					}
				}
			});

			let $once	= $(this).find('.show_if_key.once').removeClass('once');

			while($once.length){
				let $this	= $once.first().trigger('change.show_if');
				let $wrap	= $this.parents('.checkable');

				$once	= $once.not($wrap.length ? $wrap.find('input') : $this);
			}

			$(this).find('.tabs:not(.ui-tabs)').each(function(){
				$(this).tabs({
					activate: function(e, ui){
						window.history.replaceState(null, null, ui.newTab.children('a')[0].hash);
					}
				});
			});

			return this;
		},

		wpjam_query_label: function(label){
			if(label){
				let $this	= $(this);

				$this.before($('<span class="query-title '+($this.data('class') || '')+'">'+label+'</span>').prepend($('<span class="dashicons-before dashicons-dismiss"></span>').on('click', function(e){
					$(this).parent().fadeOut(300, function(){
						$(this).next('input').val('').change().end().remove();
					});
				})));
			}

			return this;
		},

		wpjam_mu_item: function(item){
			$this	= $(this);
			$tmpl	= $this.find('> .mu-item').last();

			let $new	= $tmpl.clone().find('.new-item').remove().end();

			if($this.is('.mu-img, .mu-image, .mu-file')){
				if($this.is('.mu-img')){
					$('<img src="'+item.url+$this.data('thumb_args')+'" />').on('click', ()=> wpjam.preview(item.url)).prependTo($new);

					item	= item.value;
				}

				$new.find('input').val(item).end().insertBefore($tmpl);
			}else if($this.is('.mu-text')){
				let $input	= $new.find(':input').removeClass('ui-autocomplete-input');

				if(item){
					if(typeof(item) == 'object'){
						if($input.data('data_type') && $input.is('input') && item.label){
							$input.wpjam_query_label(item.label);
						}

						item	= item.value;
					}

					$input.val(item);

					$new.insertBefore($tmpl);
				}else{
					$input.val('');

					$tmpl.find('a.new-item').insertAfter($input);
					$new.insertAfter($tmpl).find('.query-title').remove();
				}

				$new.wpjam_form_init();
			}else if($this.is('.mu-fields')){
				let i	= $this.data('i') || $this.find(' > .mu-item').length-1;
				let t	= $new.find('template').html().replace(/\$\{i\}/g, i);

				$this.data('i', i+1);
				$new.find('template').replaceWith(t).end().insertBefore($tmpl).wpjam_form_init();
			}
		},

		wpjam_show_if: function(val){
			let $el		= $(this);
			let data	= $el.data('show_if');

			if(data.compare || !data.query_arg){
				let show	= val === null ? false : wpjam.compare(val, data);

				$el.add($el.next('br')).toggleClass('hidden', !show);

				if($el.is('option')){
					$el.prop('disabled', !show).parents('select').prop('selectedIndex', (i, v) => (!show && $el.is(':selected')) ? 0 : v).trigger('change.show_if');
				}else if($el.is(':input')){
					$el.prop('disabled', !show).trigger('change.show_if');
				}else{
					$el.find(':input:not(.disabled)').prop('disabled', !show);
					$el.find('.show_if_key').trigger('change.show_if');
				}
			}

			if(!$el.hasClass('hidden') && data.query_arg && $el.data('query_el')){
				let $query_el	= $el.data('query_el');
				let query_args	= $query_el.data('query_args');
				let query_var	= data.query_arg;

				if(query_args[query_var] != val){
					$query_el.data('query_args', { ...query_args, [query_var] : val });

					if($query_el.is('input')){
						$query_el.val('').removeClass('hidden');
					}else if($query_el.is('select')){
						$query_el.find('option').filter((i, item) => item.value).remove();
						$el.addClass('hidden');

						$query_el.wpjam_query(function(items){
							if(items.length){
								items.forEach(item => $query_el.append('<option value="'+item.value+'">'+item.label+'</option>'));

								$el.removeClass('hidden');
							}

							$query_el.trigger('change.show_if');
						});
					}
				}else{
					if($query_el.is('select')){
						if($query_el.find('option').filter((i, item) => item.value).length == 0){
							$el.addClass('hidden');
						}
					}
				}
			}
		},

		wpjam_val: function(){
			let $this	= $(this);
			let val		= $this.val();

			if($this.prop('disabled')){
				val	= null;
			}else if($this.is('span')){
				val	= $this.data('value');
			}else if($this.is('.checkable')){
				val	= $this.find('input:checked').toArray().map(item => item.value);
				val	= $this.find('input').is(':radio') ? (val.length ? val[0] : null) : val;
			}else if($this.is(':checkbox, :radio')){
				let $wrap	= $this.parents('.checkable');

				val	= $wrap.length ? $wrap.wpjam_val() : ($this.is(':checked') ? val : $this.is(':checkbox') ? 0 : null);
			}

			return val;
		},

		wpjam_media: function(args){
			let $this	= $(this);
			let type	= $this.data('item_type') || ($this.is('.wpjam-img, .mu-img') ? 'id' : ($this.is('.wpjam-image, .mu-image') ? 'image' : ''));
			let action	= 'select';

			args	= {
				...args,
				id:			'uploader_'+args.id,
				title:		$this.data('button_text'),
				library:	{type: $this.is('.wpjam-img, .wpjam-image, .mu-img, .mu-image') ? 'image' : type},
				// button:		{text: title}
			};

			if(wp.media.view.settings.post.id){
				args.frame	= 'post';
				action		= 'insert';
			}

			let frame	= wp.media.frames.wpjam = wp.media(args);

			frame.on('open', function(){
				frame.$el.addClass('hide-menu');

				wp.hooks.doAction('wpjam_media', frame, args);
			}).on(action, function(){
				frame.state().get('selection').map((attachment)=> {
					let data	= attachment.toJSON();
					let val		= data.url;

					if(['image', 'url'].includes(type)){
						val	+= '?'+$.param({orientation:data.orientation, width:data.width, height:data.height});
					}else if(type == 'id'){
						val	= data.id;
					}

					wp.hooks.doAction('wpjam_media_selected', val, data.url);
				});

				wp.hooks.removeAction('wpjam_media_selected', 'wpjam');
			}).open();

			return false;
		}
	});

	Color.fn.fromHex	= function(color){
		color	= color.replace(/^#|^0x/, '');
		let l	= color.length;

		if(3 === l || 4 === l){
			color = color.split('').map(c => c + c).join('');
		}else if(8 == l){
			if(/^[0-9A-F]{8}$/i.test(color)){
				this.a(parseInt(color.substring(6), 16)/255);

				color	= color.substring(0, 6);
			}
		}

		this.error	= !/^[0-9A-F]{6}$/i.test(color);

		return this.fromInt(parseInt(color, 16));
	}

	Color.fn.toString	= function(){
		return this.error ? '' : '#'+(parseInt(this._color, 10).toString(16).padStart(6, '0'))+(this._alpha < 1 ? parseInt(255*this._alpha, 10).toString(16).padStart(2, '0') : '');
	};

	$.widget('wpjam.iris', $.a8c.iris, {
		_change: function(){
			if(!this.element.data('alpha-enabled')){
				return this._super();
			}

			let self	= this;
			let color	= self._color;
			let rgb		= color.toString().substring(0, 7) || '#000000';
			let rgba	= self.options.color = color.toString() || '#FFFFFF80';
			let bg		= 'url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAAHnlligAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAHJJREFUeNpi+P///4EDBxiAGMgCCCAGFB5AADGCRBgYDh48CCRZIJS9vT2QBAggFBkmBiSAogxFBiCAoHogAKIKAlBUYTELAiAmEtABEECk20G6BOmuIl0CIMBQ/IEMkO0myiSSraaaBhZcbkUOs0HuBwDplz5uFJ3Z4gAAAABJRU5ErkJggg==)';

			let alpha	= color.a();
			self.hue	= color.a(1).h();

			self._super();
			color.a(alpha);

			self.controls.alpha	= self.controls.alpha || self.controls.strip.width(18).clone(false, false).find('> div').slider({
				orientation:	'vertical',
				slide:	function(event, ui){
					self.active	= 'strip';
					color.a(parseFloat(ui.value/100));
					self._change();
				}
			}).end().insertAfter(self.controls.strip);

			self.controls.alpha.css({'background': 'linear-gradient(to bottom, '+rgb+', '+rgb+'00), '+bg}).find('> div').slider('value', parseInt(alpha*100));

			self.element.removeClass('iris-error').val(rgba).wpColorPicker('instance').toggler.css('background', 'linear-gradient('+rgba+', '+rgba+'),'+bg);
		}
	});

	$('body').on('list_table_action_success page_action_success option_action_success', function(){
		$(this).wpjam_form_init();
	}).on('click', '.wpjam-img, .wpjam-img .add-media, .wpjam-image .add-media, .wpjam-file .add-media', function(e){
		let $this	= $(this).is('.wpjam-img') ? $(this) : $(this).parent();

		if($this.hasClass('readonly')){
			return false;
		}

		wp.hooks.addAction('wpjam_media_selected', 'wpjam', function(value, url){
			$this.find('input').val(value).end().find('img').prop('src', url+$this.data('thumb_args')).fadeIn(300, ()=> $this.show());
		});

		return $this.wpjam_media({id: $this.find('input').prop('id')});
	}).on('click', 'a.del-img', function(){
		$(this).parent().find('input').val('').end().find('img').fadeOut(300, function(){
			$(this).removeAttr('src');
		});

		return false;
	}).on('click', 'a.del-item, a.del-icon', function(){
		$(this).parent().fadeOut(300, function(){
			$(this).remove();
		});

		return false;
	}).on('mouseenter', '[data-tooltip]', function(e){
		if(!$('#tooltip').length){
			$('body').append('<div id="tooltip"></div>');
		}

		$('#tooltip').html($(this).data('tooltip')).show().css({top: e.pageY+22, left: e.pageX-10});
	}).on('mousemove', '[data-tooltip]', function(e){
		$('#tooltip').css({top: e.pageY+22, left: e.pageX-10});
	}).on('mouseleave mouseout', '[data-tooltip]', function(){
		$('#tooltip').remove();
	}).wpjam_form_init();
});

if(self != top){
	document.getElementsByTagName('html')[0].className += ' TB_iframe';
}