jQuery(function($){
	$.fn.extend({
		wpjam_scroll: function(){
			let top	= $(this).offset().top;
			let dis	= $(window).height() * 0.4;

			if(Math.abs(top - $(window).scrollTop()) > dis){
				$('html, body').animate({scrollTop: top - dis}, 400);
			}

			return this;
		},

		wpjam_row: function(color){
			let $row	= $(this);

			if(!$row.is('table') && color !== false){
				$row.hide().css('backgroundColor', color || ($row.prevAll().length % 2 ? '#ffffeecc' : '#ffffddcc')).fadeIn(1000);
			}

			if($row.is('td')){
				return $row;
			}

			_.each(list_table.columns, data => $row.find(data.column).each(function(){
				let $cell	= $(this);

				if(data.sticky){
					$cell.addClass('sticky-column').css('left', data.left);
				}

				if(data.nowrap){
					$cell.addClass('nowrap-text');
				}

				if(data.check){
					if($cell.find('input').length){
						// if(list_table.sortable && $cell.is('th')){
						// 	$cell.append('<br /><span class="dashicons dashicons-menu"></span>');
						// }
					}else{
						if(!$cell.find('span').length){
							$cell.append('<span class="dashicons dashicons-minus"></span>');
						}
					}
				}else{
					let value	= $cell.text();
					let number	= Number(value);

					if(!isNaN(number)){
						let rule	= data.conditional_styles ? data.conditional_styles.find(rule=> wpjam.compare(number, rule)) : '';

						if(rule){
							[
								{key: 'bold', prop: 'font-weight', value: 'bold'},
								{key: 'strikethrough', prop: 'text-decoration', value: 'line-through'},
								{key: 'color'},
								{key: 'background-color'}
							].forEach(args=> {
								if(rule[args.key]){
									$cell.css(args.prop || args.key, args.value || rule[args.key]);
								}
							});
						}

						if(data.format || data.precision){
							if(data.format == '%'){
								number	= parseFloat((number*100).toFixed(data.precision || 2))+'%';
							}else{
								number	= data.precision ? parseFloat(number.toFixed(data.precision)) : number;
								number	= data.format == ',' ? number.toLocaleString() : number;
							}

							$cell.text(number).attr('value', value)
						}
					}
				}
			}));


			$row.find('td').each(function(){
				let $cell	= $(this);

				if($cell[0].scrollWidth > $cell[0].clientWidth){
					$cell.addClass('is-truncated');
				}
			});

			$row.find('.items').each(function(){
				let $items	= $(this);

				if($items.hasClass('sortable')){
					$items.wpjam_sortable({items: '> div.item'});
				}

				let is_image	= $items.hasClass('image-list');
				let width		= $items.data('width');
				let height		= $items.data('height');
				let per_row		= $items.data('per_row');

				$items.children().each(function(i, el){
					if(is_image && width && height){
						if($(el).hasClass('add-item')){
							$(el).css({width, height});
						}else{
							$(el).css('width', width);
							$(el).find('img').css({width, height});
						}
					}

					if(per_row && (i+1) % per_row === 0){
						$(el).after('<div style="width: 100%;"></div>');
					}
				});
			});

			return $row;
		},

		wpjam_sortable: function(args){
			let $this	= $(this);

			Object.assign(args, {
				handle:	'.list-table-move-action',
				cursor:	'move',
				start:	function(e, ui){
					ui.placeholder.css({
						'background-color':	'#eeffffcc',
						visibility:			'visible',
						height:				ui.helper.height()+'px'
					});
				},

				update:	function(e, ui){
					let $handle	= ui.item.find(args.handle);
					let data	= ($handle.data('data') || '')+'&pos='+ui.item.prevAll().length+'&'+$this.sortable('serialize');

					$this.wpjam_action({
						action_type:	'direct',
						list_action:	$handle.data('action'),
						_ajax_nonce:	$handle.data('nonce'),
						id:				$handle.data('id'),
						data:			data,
					});
				}
			});

			return $this.sortable(args);
		},

		wpjam_action: function(type, args){
			let $this	= $(this);
			let spinner	= '<span class="spinner is-active"></span>';
			let $el		= $(document.activeElement);

			if(_.isObject(type)){
				args	= type;
				type	= args.page_action ? 'page' : (args.list_action ? 'list-table' : '');
			}else{
				type	= type == 'list' ? 'list-table' : type;	
			}

			if(!args){
				args	= {
					action_type:	$this.is('form') ? 'submit' : ($this.data('direct') ? 'direct' : 'form'),
					_ajax_nonce:	$this.data('nonce'),
				};

				let data	= $this.data('data');
				let action	= $this.data('action');

				if(args.action_type == 'submit'){
					if(!$this.wpjam_validity()){
						return false;
					}

					if(data){
						args.defaults	= data;
					}

					$el	= $el.is(':submit') ? $el : $this.find(':submit').first().focus();

					args.data			= $this.serialize();
					args.submit_name	= $el.attr('name');
					args.page_title		= $el.val();
				}else{
					if(data){
						args.data	= data;
					}

					if(type == 'page'){
						args.page_title	= $this.data('title');
					}

					if(args.action_type == 'direct'){
						args.form_data	= $.param(wpjam.parse_params($this.parents('form').serialize(), true));

						if($this.data('confirm')){
							if($this.data('action') == 'delete'){
								if(!showNotice.warn()){
									return false;
								}
							}else if(!confirm('确定要'+($this.attr('title') || $this.data('title'))+'吗?')){
								return false;
							}
						}
					}
				}

				if(type	== 'list-table'){
					args.list_action	= action;

					args.bulk	= $this.data('bulk');
					args.id		= $this.data('id');
					args.ids	= $this.data('ids');
				}else if(type == 'page'){
					args.page_action	= action;
				}
			}

			args.action	= 'wpjam-'+type+'-action';

			if(type	== 'list-table'){
				if(args.action_type == 'form'){
					if(!args.bulk){
						_.extend(wpjam.params, _.pick(args, ['list_action', 'id', 'data']));
					}
				}else{
					if(args.bulk && args.bulk == 2 && !args.id){
						tb_remove();

						args.id	= args.ids.shift();

						return $this.wpjam_action(args).then(()=> {
							delete args.id;

							if(args.ids.length){
								setTimeout(()=> $this.wpjam_action(args), args.list_action == 'delete' ? 400 : 100);
							}
						});
					}
				}

				if(list_table.$tbody.find('.check-column').length){
					_.reduce(((args.bulk && args.bulk != 2) ? args.ids : (args.id ? [args.id] : [])), ($r, i)=> $r.add(list_table.get_row(i)), $()).find('.check-column input').before(spinner);
				}
			}else if(type == 'page'){
				if(args.action_type == 'form'){
					_.extend(wpjam.params, _.pick(args, ['page_action', 'data']));
				}
			}else if(type == 'option'){
				if(args.submit_name == 'reset' && !confirm('确定要'+args.page_title+'吗?')){
					return false;
				}
			}

			if(args.action_type == 'submit'){
				if(!$el.is('body')){
					$el.prop('disabled', true).after(spinner);
				}
			}else if(args.action_type){
				if($('.spinner.is-active').length == 0){
					$('<div id="TB_load"><img src="'+imgLoader.src+'" width="208" /></div>').appendTo('body').show();
				}
			}

			return wpjam.post(args, (data)=> {
				$('.spinner.is-active').remove();

				if(args.action_type == 'submit'){
					$el.prop('disabled', false);
				}else if(args.action_type){
					$('#TB_load').remove();
				}

				if(data.errcode != 0){
					wpjam.add_notice((args.page_title ? args.page_title+'失败：' : '')+(data.errmsg || ''), 'error');
				}else{
					if(data.params){
						_.extend(wpjam.params, data.params);
					}

					let $modal	= $('#TB_ajaxContent');

					if(data.type == 'form'){
						if(!data.form && !data.data){
							alert('服务端未返回表单数据');
						}

						if(args.callback){
							args.callback.call(null, data);
						}else{
							wpjam.add_modal(data);
						}
					}else if(data.type == 'append'){
						if(!$modal.length && data.list_action){
							wpjam.add_modal(data);
						}else{
							let $wrap	= $modal.length ? $modal : $('div.wrap');
						
							$wrap.find('.response').remove().end().append($('<div class="response card">'+data.data+'</div>').hide().fadeIn(400));

							if($modal.length){
								$wrap.animate({scrollTop: $wrap.find('form').height()-50}, 300)
							}else{
								$wrap.find('.response').wpjam_scroll();
							}
						}
					}else{
						if(data.type == 'redirect'){
							if(data.dismiss){
								$('body').one('thickbox:removed', ()=> wpjam.redirect(data));
							}else{
								wpjam.redirect(data);	
							}
						}

						if($modal.length && data.dismiss){
							tb_remove();
						}
					}

					if(type == 'option'){
						if(data.type == 'save'){
							wpjam.add_notice(data.errmsg, 'success');
						}

						$('body').trigger('option_action_success', data);
					}else if(type == 'page'){
						if(!['form', 'append', 'redirect'].includes(data.type)){
							if(data.done == 0){
								setTimeout(()=> $this.wpjam_action(type, {...args, data: data.args}), 400);
							}

							if(args.action_type == 'submit' && $('#wpjam_form').length && data.form){
								$('#wpjam_form').html(data.form);
							}

							wpjam.add_notice(data.errmsg || args.page_title+'成功', data.notice_type || 'success');
						}

						if(data.type != 'form' || !data.modal_id || data.modal_id == 'tb_modal'){
							wpjam.state();
						}

						data.page_action	= args.page_action;
						data.action_type	= data.page_action_type	= args.action_type;

						$('body').trigger('page_action_success', data);
					}else if(type == 'list-table'){
						if(args.bulk){
							list_table.$form.find('td.check-column input').prop('checked', false);

							if(args.bulk == 2){
								list_table.get_row(args.id).wpjam_scroll();
							}	
						}else if(!args.bulk){
							list_table.$tbody.find('tr').not(args.id ? list_table.get_row(args.id) : '').css('background-color', '');
						}

						if(['up', 'down', 'move'].includes(data.type)){
							let $item	= list_table.get_row(args.id).css('background-color', '#eeffeecc');

							if(data.type == 'up'){
								$item.insertBefore($item.prev());
							}else if(data.type == 'down'){
								$item.insertAfter($item.next());
							}
						}else if(!['form', 'append', 'redirect'].includes(data.type)){
							if(data.type == 'list' && data.list_action == 'delete'){
								list_table.delete_row(args.id);
							}

							list_table.load(data);

							if(data.type == 'items' && data.items){
								_.each(data.items, item => list_table.callback(item, args));
							}else{
								list_table.callback(data, args);
							}
						}

						if(data.next){
							_.extend(wpjam.params, {list_action: data.next}, _.pick(args, ['id', 'data']));
						}

						wpjam.state();

						data.list_action	= args.list_action;
						data.action_type	= data.list_action_type	= args.action_type;

						$('body').trigger('list_table_action_success', data);
					}
				}
			});
		},

		wpjam_query: function(){
			let $this	= $(this);
			let arg		= arguments[0];

			if(_.isFunction(arg)){
				const [callback, term] = arguments;

				let args	= {
					action:		'wpjam-query',
					data_type:	$this.data('data_type'),
					query_args:	$this.data('query_args')
				}

				if(term){
					args.query_args[(args.data_type == 'post_type' ? 's' : 'search')]	= term;
				}

				return wpjam.post(args, data => {
					if(data.errcode != 0){
						if(data.errmsg){
							alert(data.errmsg);
						}
					}else{
						callback(data.items);
					}
				});
			}else{
				wpjam.params	= arg ? _.omit(arg, v => _.isNull(v)) : wpjam.parse_params($this.serialize(), true);

				return $this.wpjam_action('list', {action_type: 'query_items', data: $.param(wpjam.params)});
			}
		},

		wpjam_validity: function(type, el){
			let $this	= $(this);

			if(!type){
				if(!$this[0].checkValidity() || $this.find('.checkable[data-min_items]').toArray().some(el => !$(el).wpjam_validity('min_items', $(el).find(':checkbox')[0]))){
					let $field	= $this.find(':invalid').first();
					let custom	= $field.data('custom_validity');
					let $tabs	= $field.closest('.ui-tabs');

					if(custom){
						$field.one('input', ()=> $field[0].setCustomValidity(''))[0].setCustomValidity(custom);
					}

					if($tabs.length){
						$tabs.tabs('option', 'active', $tabs.find('.ui-tabs-panel').index($('#'+$field.closest('.ui-tabs-panel').attr('id'))));
					}

					$this[0].reportValidity()

					return false;
				}
			}else if(['max_items', 'min_items'].includes(type)){
				let value	= parseInt($this.data(type));

				if(value){
					let count	= $this.find(':checkbox:checked').length;
					let custom	= type == 'max_items' ? (count-1 >= value ? '最多选择'+value+'个' : '') : (count < value ? '至少选择'+value+'个' : '');

					if(custom){
						el.setCustomValidity(custom);

						return false;
					}
				}
			}

			return true;
		}
	});

	window.wpjam	= {
		...wpjam_page_setting,

		load: function(params){
			if(params){
				tb_remove();

				this.params	= params;
			}else{
				this.params	= this.parse_params(window.location.search, true);

				if($('#notice_modal').length){
					this.add_modal('notice_modal');
				}

				if(this.page_title_action){
					$('a.page-title-action').remove();

					$('.wp-heading-inline').last().after(this.page_title_action || '');
				}

				if(list_table){
					list_table.load();
				}

				if(this.query_url){
					_.each(this.query_url, pair => $('a[href="'+pair[0]+'"]').attr('href', pair[1]));
				}

				$('a[href*="admin/page="]').each(function(){
					$(this).attr('href', $(this).attr('href').replace('admin/page=', 'admin/admin.php?page='));
				});
			}

			let args	= {...this.params, action_type: 'form'}

			if(args.page_action){
				return $('body').wpjam_action(args);
			}

			if(list_table){
				let $form	= list_table.$form;

				if(args.list_action){
					return $form.wpjam_action(args);
				}

				if(params){
					return $form.wpjam_query(params);
				}
			}

			if(this.plugin_page){
				this.state('replace');
			}
		},

		state: function(action='push'){
			let url	= new URL(this.admin_url);

			if(Object.keys(this.params).length || this.query_data){
				params	= this.parse_params(url.search);
				params	= _.extend(params, this.query_data, _.omit(this.params, (v, k)=> v == null || (k == 'paged' && v <= 1)));

				url.search	= '?'+$.param(params);
			}

			url	= url.toString()+(window.location.hash || '');

			$('input[name="_wp_http_referer"]').val(url);

			if(action != 'push' || window.location.href != url){
				window.history[(action == 'push' ? 'pushState' : 'replaceState')]({params: this.params}, null, url);
			}
		},

		add_notice: function(notice, type){
			if(notice){
				$notice	= $('<div class="notice notice-'+type+' is-replaceable is-dismissible"><p><strong>'+notice+'</strong></p></div>');

				if($('#TB_ajaxContent').length){
					$('#TB_ajaxContent').find('.notice.is-replaceable').remove().end().animate({scrollTop: 0}, 300).prepend($notice);
				}else{
					$('div.wrap').find('.notice.is-replaceable').remove().end().find('.wp-header-end').last().wpjam_scroll().before($notice);
				}

				$(document).trigger('wp-notice-added');
			}
		},

		add_modal: function(modal, title){
			let width	= 0;
			let id		= 'tb_modal';

			if(typeof modal === 'object'){
				title	= modal.page_title;
				width	= modal.width;
				id		= modal.modal_id || id;
				content	= modal.form || modal.data;
			}else{
				modal	= modal || 'notice_modal';
				$model	= $('#'+modal);
				width	= $model.data('width');
				title	= $model.data('title') || ' ';
				content	= $model.html();

				if(modal == 'notice_modal'){
					$('body').one('thickbox:removed', ()=> $model.find('.delete-notice').trigger('click'));
				}
			}

			if(id == 'tb_modal'){
				$('body').one('thickbox:removed', ()=> {
					if(this.params.page_action || this.params.list_action){
						this.params	= _.omit(this.params, this.params.page_action ? ['page_action', 'data'] : ['list_action', 'id', 'data']);

						wpjam.state();
					}
				});

				if($('#TB_window').length){
					$('#TB_ajaxWindowTitle').html(title);
					$('#TB_ajaxContent').html(content);

					tb_position();
				}else{
					if(!$('body #tb_modal').length){
						$('body').append('<div id="tb_modal" class="hidden"></div>');

						if(window.send_to_editor && !wpjam.send_to_editor){
							[wpjam.send_to_editor, window.send_to_editor]	= [window.send_to_editor, function(html){
								[wpjam.tb_remove, window.tb_remove]	= [window.tb_remove, null];

								wpjam.send_to_editor(html);

								window.tb_remove	= wpjam.tb_remove;
							}];
						}
					}

					$('#tb_modal').html(content);

					[this.tb_position, window.tb_position]	= [window.tb_position, this.tb_position];

					$(window).on('resize.wpjam', ()=> tb_position());

					$('body').one('thickbox:removed', ()=> {
						[wpjam.tb_position, window.tb_position]	= [window.tb_position, wpjam.tb_position];

						$(window).off('resize.wpjam');
					});

					tb_show(title, '#TB_inline?inlineId=tb_modal&width='+(width || 771));
				}
			}else{
				if(!$('body .modal').length){
					$('body').append('<div class="modal"><div class="modal-title">'+title+'</div><div class="modal-content">'+content+'</div></div><div class="modal-overlay"></div>').addClass('modal-open');

					$('<div class="modal-close"></div>').on('click', function(){
						$('body').removeClass('modal-open');

						$(this).parent().fadeOut(300, function(){
							$(this).remove();
							$('.modal-overlay').remove();
						});

						return false;
					}).prependTo('div.modal');

					$(window).on('resize', this.tb_position);
				}

				this.tb_position();
			}
		},

		preview: function(url){
			$('body').find('.quick-modal').remove().end().append('<div class="quick-modal"><a class="dashicons dashicons-no-alt del-icon"></a></div>');

			let img	= new Image();

			img.onload	= function(){
				let width	= this.width/2;
				let height	= this.height/2;

				if(width>400 || height>500){
					let radio	= Math.min(400/width, 500/height);

					width	= width * radio;
					height	= height * radio;
				}

				$(this).width(width).height(height).appendTo($('.quick-modal'));
			}

			img.src	= url;
		},

		tb_position: function(){
			let style	= {maxHeight: Math.min(900, $(window).height()-120)};
			let width	= $(window).width()-20;
			let $tb		= $('#TB_window');

			if($tb.length){
				if(!$tb.hasClass('abscenter')){
					$tb.addClass('abscenter');
				}

				if(width < 761){
					style.width	= width - 50;
				}else{
					if(TB_WIDTH != 801 && TB_WIDTH < width){
						style.width	= TB_WIDTH - 50;
					}else{
						style.maxWidth	= (width <= TB_WIDTH - 31) ? width - 50 : TB_WIDTH - 51;
					}
				}

				$('#TB_ajaxContent').removeAttr('style').css(style);

				$('#TB_overlay').off('click');
			}else if($('.modal').length){
				if(width < 720){
					style.width		= width - 50;
				}else{
					style.maxWidth	= 690;
				}

				$('.modal').css(style);
			}
		},

		compare: function(a, data){
			let compare	= data.compare ? data.compare.toUpperCase() : '';

			if(compare){
				const antonyms	= {
					'!=': '=',
					'<=': '>',
					'>=': '<',
					'NOT IN': 'IN',
					'NOT BETWEEN': 'BETWEEN'
				};

				if(antonyms[compare]){
					return !wpjam.compare(a, {...data, compare: antonyms[data.compare]});
				}
			}

			let b		= data.value;
			let swap	= _.isArray(a) || data.swap;

			if(swap){
				[a, b]	= [b, a];
			}

			compare	= compare || (_.isArray(b) ? 'IN' : '=');

			if(compare === 'IN' || compare === 'BETWEEN'){
				b	= _.isArray(b) ? b : b.split(/[\s,]+/);

				if(!_.isArray(a) && b.length === 1) {
					return a == b[0];
				}

				b	= b.map(String);
			}else{
				b	= typeof b === 'string' ? b.trim() : b;
			}

			switch (compare) {
				case '=': return a == b;
				case '>': return a > b;
				case '<': return a < b;
				case 'IN': return b.includes(a);
				case 'BETWEEN': return a >= b[0] && a <= b[1];
				default: return false;
			}
		},

		post: function(args, callback){
			return $.ajax({
				url:		ajaxurl,
				method:		'POST',
				data:		args,
				dataType:	'json',
				headers:	{'Accept': 'application/json'},
				success:	callback,
				error:		function(xhr, status, error){
				}
			});
		},

		append_page_setting: function(args){
			if(this.query_data || (this.left_key && args.action_type != 'query_items')){
				let type	= args.data ? typeof args.data : 'string';
				let data	= type == 'object' ? args.data : (args.data ? this.parse_params(args.data) : {});

				if(this.query_data){
					_.each(this.query_data, (v, k)=>{
						if(_.has(data, k)){
							this.query_data[k]	= data[k];
						}else{
							data[k]	= v;
						}
					});
				}

				if(this.left_key && args.action_type != 'query_items'){
					data[this.left_key]	= wpjam.params[this.left_key];
				}

				if(type == 'string'){
					args.data	= $.param(data);
				}
			}

			return _.extend(args, _.pick(this, ['screen_id', 'plugin_page', 'current_tab', 'builtin_page', 'post_type', 'taxonomy']));
		},

		parse_params: function(params, omit){
			if(_.isString(params)){
				let obj	= {};
				params	= params ? params.replace(/^\?|\+/g, ' ').trim() : '';

				if(params){
					params.split('&').forEach(v => {
						let	param	= v.split('=');
						let key		= decodeURIComponent(param[0]);
						let val		= param.length === 2 ? decodeURIComponent(param[1]) : '';
						let keys	= key.split('][');

						if(keys[0].includes('[') && keys.at(-1).endsWith(']')){
							keys	= keys.shift().split('[').concat(keys);
							keys	= [...keys.slice(0, -1), keys.pop().slice(0, -1)];

							keys.reduce((cur, k, i)=> {
								k	= (k === '') ? cur.length : k;

								return cur[k] = keys.length - 1 > i ? (cur[k] || (isNaN(Number(keys[i + 1])) ? {} : [])) : val;
							}, obj);
						}else{
							obj[key]	= val;
						}
					});
				}

				params	= obj;
			}

			return omit ? _.omit(params, _.union(
				['_wp_http_referer', '_wpnonce', 'action', 'action2'],
				this.query_data ? Object.keys(this.query_data) : [],
				this.builtin_page ? ['post_type', 'taxonomy'] : ['page', 'tab']
			)) : params;
		},

		redirect: function(data){
			if(data.url){
				window.open(data.url, data.target);
			}else{
				window.location.reload();	
			}
		},

		delegate: function(selector, sub_selector){
			sub_selector	= sub_selector || '';
			let $selector	= $(selector);
			let events		= $selector.length ? $._data($selector.get(0), 'events') : '';

			if(events){
				_.each(events, function(list, type){
					 _.each(list, function(event){
						if(event && event.handler){
							if(event.selector){
								if(!sub_selector || event.selector == sub_selector){
									$('body').on(type, selector+' '+event.selector, event.handler);
									$selector.off(type, event.selector, event.handler);
								}
							}else{
								$('body').on(type, selector, event.handler);
								$selector.off(type, event.handler);
							}
						}
					});
				});
			}
		},

		add_extra_logic: function(obj, func, extra_logic, position){
			const back	= obj[func];
			obj[func]	= function(){
				if(position == 'before'){
					extra_logic.apply(this, arguments);
				}

				let result	= back.call(this, ...arguments);

				if(position != 'before'){
					extra_logic.apply(this, arguments);
				}

				return result;
			};
		}
	}

	wpjam.add_extra_logic($, 'ajax', function(options){
		let data	= options.data;
		let type	= typeof data;

		data	= type == 'string' ? wpjam.parse_params(data) : (type == 'object' ? data : {});
		data	= wpjam.append_page_setting(data);
		data	= type == 'string' ? $.param(data) : data;

		options.data	= data;
	}, 'before');

	window.onpopstate = event => {
		if(event.state && event.state.params){
			wpjam.load(event.state.params);
		}
	};

	let list_table	= null;

	if(wpjam.list_table){
		list_table	= Object.assign(wpjam.list_table, {
			load: function(data){
				let $views	= $('ul.subsubsub');
				let $left	= $('[data-left_key]');
				let $form	= $('form:has(.wp-list-table)');
				let update	= {
					views:		true,
					table:		true,
					tablenav:	true,
					left:		true
				};

				if(data){
					if(data.setting){
						Object.assign(list_table, data.setting);
					}

					if(data.left){
						$left.html(data.left);
					}

					if(data.views){
						$views.empty().append($(data.views).html());

						if(data.type != 'list'){
							$views.find('a').removeClass('current').end().find('li.'+this.view+' a').addClass('current');
						}
					}

					if(data.table || data.tablenav){
						$form.find('input[name="_wpnonce"], input[name="_wp_http_referer"]').remove().end();

						if(data.table){
							$form.find('table, div.tablenav').remove().end().append(data.table);
						}else{
							_.each(['top', 'bottom'], key => $form.find('div.tablenav.'+key).replaceWith(data.tablenav[key]));
						}
					}

					update	= _.mapObject(update, (v, k)=> data[k] ? v : false);

					if(data.search_box){
						$('p.search-box').empty().append($(data.search_box).html());
					}
				}else{
					$form.attr('novalidate', 'novalidate').on('submit', function(){
						let $el	= $(document.activeElement);
						let id	= $el.attr('id');

						if(['doaction', 'doaction2'].includes(id)){
							let $select	= $el.prev('select');
							let name	= $select.val();
							let ids		= $form.find('th.check-column input[type="checkbox"]:checked').toArray().map(cb => cb.value);
							let action	= list_table.bulk_actions ? list_table.bulk_actions[name] : null;

							if(action && ids.length){
								if(name == 'delete' && action.bulk === true){
									action.bulk	= 2;
								}

								$select.data(action).data('ids', ids).wpjam_action('list');

								return false;
							}
						}else if(wpjam.list_table.ajax !== false){
							if($el.is('[name=filter_action]') || id == 'search-submit'){
								if($form.wpjam_validity()){
									$form.wpjam_query();
								}

								return false;
							}
						}
					}).on('keydown', '.tablenav :input', function(e){
						if(e.key === 'Enter' && wpjam.list_table.ajax !== false){
							let $input	= $(this);

							if($input.is('#current-page-selector')){
								if($form.wpjam_validity()){
									$form.wpjam_query(_.extend(wpjam.params, {paged: parseInt($input.val())}));
								}

								return false;
							}else{
								let $el	= $input.closest(':has(:submit)').find(':submit');

								if($el.length){
									$el.first().focus().click();

									return false;
								}
							}
						}
					}).on('click', '.tablenav .prev-day, .tablenav .next-day', function(e){
						let $day	= $(this);
						let $date	= $day.siblings('[name="date"]');
						let date	= new Date($date.val());

						date.setDate(date.getDate()+($day.hasClass('prev-day') ? -1 : 1));
						$date.val(date.toISOString().split('T')[0]);
						$form.find('#filter_action').focus().click();
					}).on('change', '.tablenav [name="date"]', function(){
						$form.find('#filter_action').focus().click();
					});

					$('body').on('submit', '#list_table_action_form', function(e){
						$(this).wpjam_action('list');

						return false;
					}).on('click', '.list-table-action', function(e){
						$(this).wpjam_action('list');

						return false;
					}).on('click', '.list-table-filter, ul.subsubsub a, .wp-list-table td a, .wp-list-table th a, .tablenav .pagination-links a', function(){
						let $a	= $(this);

						if($a.hasClass('list-table-filter')){
							$form.wpjam_query($a.data('filter'));

							return false;
						}

						if(wpjam.list_table.ajax === false){
							return;
						}

						if($a.closest('td').length && (wpjam.plugin_page || !$a.attr('href').startsWith($('#adminmenu a.current').attr('href')))){
							return;
						}

						let params	= wpjam.parse_params(new URL($a.prop('href')).search);

						if(wpjam.builtin_page && params.page){
							return;
						}

						if($a.parent().is('th, .pagination-links')){
							delete params.page;

							params	= {...wpjam.params, ...params, paged: params.paged || 1};
						}

						$form.wpjam_query(params);

						return false;
					});

					if($left.length){
						let $left_paged;
						let left_key	= wpjam.left_key = $left.data('left_key');

						$left.prop('novalidate', true).on('init', function(){
							$left_paged	= $left.find('input.current-page').addClass('expandable');

							let paged	= parseInt($left_paged.val());
							let total	= parseInt($left_paged.attr('max'));

							$left.find('a.prev-page, a.next-page').each(function(){
								let $a		= $(this).addClass('button');
								let is_prev	= $a.hasClass('prev-page');

								if((is_prev && paged <= 1) || (!is_prev && paged >= total)){
									$a.addClass('disabled');
								}else{
									$a.attr('data-left_paged', paged+(is_prev ? -1 : 1));
								}
							});

							if($left.find('[data-id]').length){
								wpjam.params[left_key]	= wpjam.params[left_key] || $left.find('[data-id]').first().data('id');

								$left.find('[data-id='+wpjam.params[left_key]+']').addClass('left-current');
							}
						}).on('submit', function(){
							if($left.wpjam_validity()){
								$left.wpjam_query();
							}

							return false;
						}).on('click', '[data-left_paged]', function(){
							$left_paged.val($(this).data('left_paged')).trigger('input.expandable');

							$left.trigger('submit');
						}).on('click', '[data-id]', function(){
							$left.find('.left-current').removeClass('left-current');
							$form.wpjam_query({...wpjam.params, [left_key]: $(this).addClass('left-current').data('id')});

							return false;
						}).on('change', 'select', function(){
							if($(this).hasClass('left-filter')){
								$left_paged.val(1);
							}

							$left.trigger('submit');
						});
					}
				}

				if(update.views){
					this.view	= $views.find('li:has(a.current)').attr('class');
				}

				if(update.table){
					let columns	= [];
					let $table	= $form.find('table');
					let $tbody	= $table.find(' > tbody');
					let sticky	= false;

					$('.wp-header-end').last().siblings('span.subtitle, div.summary').remove().end()
					.before(this.subtitle ? '<span class="subtitle">'+this.subtitle+'</span>' : '')
					.after(this.summary ? '<div class="summary">'+this.summary+'</div>' : '');

					if(this.sortable){
						$tbody.wpjam_sortable({items: this.sortable.items, axis: 'y'});
					}

					$table.find('th[id]:not(.hidden) i').each(function(){
						let $i		= $(this);
						let $th		= $i.closest('th');
						let data	= $i.data();
						
						if(data.description){
							$i.appendTo($i.closest('a'));
						}

						delete data.description;

						if(data.sticky){
							sticky		= true;
							data.left	= $th.prevAll(':not(.hidden)').get().reduce((left, el) => left+$(el).outerWidth(), 0);
						}

						if(Object.keys(data).length){
							columns.push({...data, column: '.column-'+$th.attr('id')});
						}
					});

					columns.push({column:'.check-column', check: true, sticky: sticky, left: 0});

					Object.assign(this, {
						$form:		$form,
						$tbody:		$tbody,
						name:		($tbody.data('wp-lists') || ':post').split(':')[1],
						layout:		$form.data('layout'),
						columns:	columns,
						sticky:		sticky,
						nowrap:		$table.hasClass('nowrap')
					});

					if(this.sticky && $table.width() > $table.closest('form').width()){
						$table.addClass('sticky-columns');

						if($('#col-left').length && $('#col-left table').height() > $(window).height()){
							$table.css('max-height', $('#col-left table').height());
						}
					}

					$table.wpjam_row();

					if(wpjam.params.id && !wpjam.params.list_action && !wpjam.params.action){
						let id	= wpjam.params.id;

						if(this.get_row(id).length){
							this.update_row(id);
						}else{
							$form.wpjam_query({id: id});
						}

						delete wpjam.params.id;
					}
				}

				if(update.table || update.tablenav){
					if($left.length && $('a.page-title-action').length){
						this.overall_actions.unshift($('a.page-title-action').hide().clone().show().toggleClass('page-title-action button').prop('outerHTML'));
					}

					let $nav	= $form.find('.tablenav.top').find('.overall-action').remove().end();

					if($nav.find('div.actions').length){
						$nav.find('div.actions').last().append(this.overall_actions || '');
					}else{
						$nav.prepend(this.overall_actions || '');
					}

					let total	= parseInt($form.find('span.total-pages').first().text());

					if(total > 1){
						$form.find('.current-page').addClass('expandable').removeAttr('size').attr({type: 'number', 'min':1, 'max': total});
					}
				}

				if(update.left && $left.length){
					$left.trigger('init');
				}
			},

			callback: function(data, args){
				let modal	= $('#TB_window').length;

				if(modal && data.form){
					wpjam.add_modal(data);
				}

				if(modal || args.action_type != 'submit'){
					wpjam.add_notice(data.errmsg, 'success');
				}

				if(data.type == 'list'){
					$('html').scrollTop(0);

					if((data.bulk && data.ids) || data.id){
						this.update_row(data);
					}
				}else if(['add', 'duplicate'].includes(data.type)){
					let pos		= (data.after || data.before);
					let $pos	= pos ? this.get_row(pos) : this.$tbody.find('tr');
					let $item	= $(data.data);

					if(data.after || data.last){
						$pos.last().after($item);
					}else{
						$pos.first().before($item);
					}

					this.$tbody.find('tr.no-items').remove();

					this.update_row(data);
				}else if(data.type == 'delete'){
					this.delete_row(data);

					setTimeout(()=> {
						if(this.$tbody.find('tr').length == 0){
							this.$tbody.append('<tr class="no-items"><td colspan="'+this.column_count+'" class="colspanchange">'+_wpMediaViewsL10n.noItemsFound+'</td></tr>');
						}
					}, 450);
				}else if(['add_item', 'edit_item', 'del_item', 'move_item'].includes(data.type)){
					let params	= wpjam.parse_params(['add_item', 'edit_item'].includes(data.type) ? args.defaults : args.data);
					let field	= '[data-field="'+params._field+'"]';

					if(data.type == 'del_item'){
						let $items	= this.get_row(args.id).find(field);

						$items.find('[data-i="'+params.i+'"]').css('background-color', '#ff0000cc').fadeOut(400, function(){
							$(this).remove();

							list_table.update_row(data, false);
						});
					}else{
						let $items	= this.update_row(data, false).get_row(data.id).find(field);

						if(data.type == 'add_item'){
							$items.find('.item:not(.add-item)').last().css('background-color', '#ffffeecc');
						}else if(data.type == 'edit_item'){
							$items.find('[data-i="'+params.i+'"]').css('background-color', '#ffffeecc');
						}else if(data.type == 'move_item'){
							$items.find('[data-i="'+params.pos+'"]').css('background-color', '#eeffeecc');
						}
					}
				}else{
					this.update_row(data);
				}
			},

			update_row: function(data, color){
				if(this.layout == 'calendar'){
					_.each(data.data, (item, date)=> $('td#date_'+date).html(item).wpjam_row());
				}else{
					let is_object	= typeof data == 'object';

					if(is_object && data.bulk){
						_.each(data.data || data.ids, item => this.update_row(item));
					}else{
						let id	= is_object ? data.id : data;

						if(is_object && data.data){
							this.get_row(id).first().before(data.data).end().remove();
						}

						this.get_row(id).wpjam_row(color);
					}
				}

				return this;
			},

			delete_row: function(data){
				if(data.bulk){
					_.each(data.ids, id => this.delete_row(id));
				}else{
					let id		= typeof data == 'object' ? data.id : data;
					let $item	= this.get_row(id);

					$item.css('backgroundColor', '#ff0000cc').fadeOut(400, ()=> $item.remove());
				}

				return this;
			},

			get_row: function(id){
				id	= typeof id == "string" ? id.replace(/(:|\.|\[|\]|,|=|@)/g, "\\$1") : id;

				return $('.tr-'+id).length ? $('.tr-'+id) : $('#'+this.name+'-'+id);
			}
		});
	}

	$('body').on('click', '.show-modal', function(){
		wpjam.add_modal($(this).data('modal_id'));
	}).on('click', '.is-dismissible .notice-dismiss', function(){
		$(this).prev('.delete-notice').trigger('click');
	}).on('click', '.wpjam-button', function(){
		$(this).wpjam_action('page');

		return false;
	}).on('submit', '#wpjam_form', function(){
		$(this).wpjam_action('page');

		return false;
	}).on('submit', '#wpjam_option', function(){
		$(this).wpjam_action('option');

		return false;
	}).on('click', 'input[type=submit]', function(){	// On Mac, elements that aren't text input elements tend not to get focus assigned to them
		if(!$(document.activeElement).attr('id')){
			$(this).focus();
		}
	});

	wpjam.load();

	$.wpjam_list_table_action	= function(args){	// compact
		return $('body').wpjam_action('list', args);
	};
});
