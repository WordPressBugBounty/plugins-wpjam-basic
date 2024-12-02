jQuery(function($){
	$.fn.extend({
		wpjam_scroll: function(){
			let el_top	= $(this).offset().top;
			let el_btm	= el_top + $(this).height();

			if((el_top > $(window).scrollTop() + $(window).height() - 100) || (el_btm  < $(window).scrollTop() + 100)){
				$('html, body').animate({scrollTop: el_top - 100}, 400);
			}
		},

		wpjam_bg_color: function(bg_color){
			if(!bg_color){
				bg_color	= $(this).prevAll().length % 2 ? '#ffffeecc' : '#ffffddcc';
			}

			return $(this).css('background-color', bg_color);
		}
	});

	$.extend({
		wpjam_compare: function(a, data){
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
					return !$.wpjam_compare(a, {...data, compare: antonyms[data.compare]});
				}
			}

			let b		= data.value;
			let swap	= Array.isArray(a) || data.swap;

			if(swap){
				[a, b]	= [b, a];
			}

			if(!compare){
				compare	= Array.isArray(b) ? 'IN' : '=';
			}

			if(compare === 'IN' || compare === 'BETWEEN'){
				b	= Array.isArray(b) ? b : b.split(/[\s,]+/);

				if(!Array.isArray(a) && b.length === 1) {
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

		wpjam_post: function(args, callback){
			let $active_el	= $(document.activeElement);
			let spinner		= '<span class="spinner is-active"></span>';

			if(wpjam_list_table && $('tbody th.check-column').length){
				((args.bulk && args.bulk != 2) ? args.ids : (args.id ? [args.id] : [])).forEach((id)=> $.wpjam_list_table_item('get', id).find('.check-column input').before(spinner));
			}

			if(args.action_type == 'submit'){
				if($active_el.prop('tagName') != 'BODY'){
					$active_el.prop('disabled', true).after(spinner);
				}
			}else if(args.action_type){
				if($('.spinner.is-active').length == 0){
					$('<div id="TB_load"><img src="'+imgLoader.src+'" width="208" /></div>').appendTo('body').show();
				}
			}

			return $.post(ajaxurl, $.wpjam_append_page_setting(args), (data, status)=> {
				$('.spinner.is-active').remove();

				if(args.action_type == 'submit'){
					$active_el.prop('disabled', false);
				}else if(args.action_type){
					$('#TB_load').remove();
				}

				callback(data, status);
			});
		},

		wpjam_append_page_setting: function(args){
			if(wpjam_page_setting.query_data && args.data){
				args.data.split('&').forEach(function(pair){
					 let query	= pair.split('=');

					if(wpjam_page_setting.query_data.hasOwnProperty(query[0])){
						wpjam_page_setting.query_data[query[0]]	= query[1];
					}
				});
			}

			['screen_id', 'plugin_page', 'current_tab', 'builtin_page', 'post_type', 'taxonomy', 'query_data'].forEach(prop => {
				if(wpjam_page_setting[prop]){
					args[prop] = wpjam_page_setting[prop];
				}
			});

			return args;
		},

		wpjam_state: function(action='push', url=''){
			if(!url){
				url	= wpjam_page_setting.admin_url;

				if(Object.keys(wpjam_params).length || wpjam_page_setting.query_data){
					url	= new URL(url);

					if(wpjam_page_setting.query_data){
						for(const [key, value] of Object.entries(wpjam_page_setting.query_data)){
							url.searchParams.set(key, value);
						}
					}

					if(Object.keys(wpjam_params).length){
						for(const [key, value] of Object.entries(wpjam_params)){
							if(typeof value === "object" && value !== null && value.hasOwnProperty('name') && value.hasOwnProperty('value')){
								url.searchParams.append(value.name, value.value);
							}else{
								if(value == null || (key == 'paged' && value <= 1)){
									continue;
								}

								if(Array.isArray(value)){
									value.forEach(item => url.searchParams.append(key+'[]', item));
								}else{
									url.searchParams.set(key, value);
								}
							}
						}
					}

					url	= url.toString();
				}

				if(window.location.hash){
					url	+= window.location.hash;
				}
			}

			$('input[name="_wp_http_referer"]').val(url)

			if(action == 'push'){
				if(window.location.href != url){
					window.history.pushState({wpjam_params: wpjam_params}, null, url);
				}
			}else{
				window.history.replaceState({wpjam_params: wpjam_params}, null, url);
			}
		},

		wpjam_notice: function(notice, type){
			if(notice){
				notice	= $('<div id="wpjam_notice" class="notice notice-'+type+' is-dismissible inline" style="opacity:0;"><p><strong>'+notice+'</strong></p></div>');
				notice.slideDown(200, ()=> notice.fadeTo(200, 1, ()=> {
					$('<button type="button" class="notice-dismiss"></button>').on('click.wp-dismiss-notice', ()=> notice.fadeTo(200, 0, ()=> notice.slideUp(200, ()=> notice.remove()))).appendTo(notice);
				}));

				if($('#TB_ajaxContent').length){
					$('#TB_ajaxContent').find('.notice').remove().end().animate({scrollTop: 0}, 300).prepend(notice);
				}else{
					$('div.wrap').find('#wpjam_notice').remove().end().find('.wp-header-end').last().before(notice).wpjam_scroll();
				}
			}
		},

		wpjam_filter: function(data){
			for(let prop in data){
				if(data[prop] == null){
					delete data[prop];
				}
			}

			return data;
		},

		wpjam_modal: function(html, title, width, modal_id){
			if(html instanceof jQuery){
				width	= width || html.data('width');
				title	= title || html.data('title') || ' ';
				html	= html.html();
			}

			modal_id	= modal_id || 'tb_modal';

			if(modal_id == 'tb_modal'){
				if($('#TB_window').length){
					$('#TB_ajaxWindowTitle').html(title);
					$('#TB_ajaxContent').html(html);

					tb_position();
				}else{
					if(!$('body #tb_modal').length){
						$('body').append('<div id="tb_modal"></div>');

						[$.wpjam_position, window.tb_position]	= [window.tb_position, $.wpjam_position];

						if(window.send_to_editor && !$.wpjam_send_to_editor){
							[$.wpjam_send_to_editor, window.send_to_editor]	= [window.send_to_editor, function(html){
								[$.wpjam_tb_remove, window.tb_remove]	= [window.tb_remove, null];

								$.wpjam_send_to_editor(html);

								window.tb_remove	= $.wpjam_tb_remove;
							}];
						}
					}

					$('#tb_modal').html(html);

					tb_show(title, '#TB_inline?inlineId=tb_modal&width='+(width || 771));
				}
			}else{
				if(!$('body .modal').length){
					$('body').append('<div class="modal"><div class="modal-title">'+title+'</div><div class="modal-content">'+html+'</div></div><div class="modal-overlay"></div>').addClass('modal-open');

					$('<div class="modal-close"></div>').on('click', function(){
						$('body').removeClass('modal-open');

						$(this).parent().fadeOut(300, function(){
							$(this).remove();
							$('.modal-overlay').remove();
						});

						return false;
					}).prependTo('div.modal');

					$(window).on('resize', $.wpjam_position);
				}

				$.wpjam_position();
			}
		},

		wpjam_position: function(){
			let style	= {maxHeight: Math.min(900, $(window).height()-120)};
			let width	= $(window).width()-20;

			if($('#TB_window').length){
				$('#TB_window').addClass('abscenter');

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

		wpjam_list_table_action: function(args){
			if(args.bulk && args.bulk == 2 && !args.id && args.action_type != 'form'){
				if(!args.ids.length){
					return;
				}

				args.id	= args.ids.shift();

				$.wpjam_list_table_item('get', args.id).wpjam_scroll();

				return $.when($.wpjam_list_table_action(args)).then(()=> {
					delete args.id;
					$.wpjam_list_table_action(args);
				});
			}

			if($('div.list-table').hasClass('layout-left') && args.action_type != 'left'){
				args.data	+= (args.data ? '&' : '')+$('div.left').data('left_key')+'='+$('tr.left-current').data('id');
			}

			args.action	= 'wpjam-list-table-action';

			return $.wpjam_post(args, (response)=> {
				if(args.bulk){
					$('thead td.check-column input, tfoot td.check-column input').prop('checked', false);
				}else if(args.id){
					$('.wp-list-table > tbody tr').not('#'+$.wpjam_list_table_item('get', args.id).attr('id')).css('background-color', '');
				}

				if(response.errcode != 0){
					if(args.action_type == 'direct'){
						alert(response.errmsg);
					}else{
						$.wpjam_notice(response.errmsg, 'error');
					}
				}else{
					if(response.setting){
						wpjam_list_table	= wpjam_page_setting.list_table	= $.extend(wpjam_list_table, response.setting);
					}

					if(args.action_type == 'form'){
						$.wpjam_modal(response.form, response.page_title, response.width);
					}else{
						let current_view	= $('.wp-list-table').data('view');

						if(args.action_type == 'query_items' || args.action_type == 'left'){
							if(args.action_type == 'left'){
								$('div#col-left div').html(response.left);
							}

							response.type	= 'list';
							current_view	= '';

							$('html').scrollTop(0);
						}

						if(response.views){
							let $views	= $(response.views);

							if(current_view){
								$views.find('li a').removeClass('current').end().find('li.'+current_view+' a').addClass('current');
							}

							$('body .subsubsub').after($views).remove();
						}

						$.wpjam_list_table_response(response, args);

						$.wpjam_list_table_loaded();
					}

					$.wpjam_state();

					response.list_action	= args.list_action;
					response.action_type	= response.list_action_type	= args.action_type;

					$('body').trigger('list_table_action_success', response);
				}
			});
		},

		wpjam_list_table_response: function(response, args){
			let dismiss	= $.wpjam_dismiss(response);

			if(response.type == 'items' && response.items){
				response.items.forEach((item)=> $.wpjam_list_table_response(item, args));
			}else if(response.type == 'redirect'){
				if(dismiss){
					$('body').on('thickbox:removed', ()=> $.wpjam_response(response));
				}else{
					$.wpjam_response(response);
				}
			}else if(response.type == 'append'){
				if($('#TB_ajaxContent').length){
					$.wpjam_response(response);
				}else{
					$.wpjam_modal(response.data, response.page_title, response.width);
				}
			}else{
				if($('#TB_ajaxContent').length && response.form){
					$.wpjam_modal(response.form, response.page_title, response.width);
				}

				if(($('#TB_ajaxContent').length || args.action_type != 'submit')){
					$.wpjam_notice(response.errmsg, 'success');
				}

				if(response.type == 'list'){
					if(response.list_action == 'delete'){
						delete response.list_action;

						$.when($.wpjam_list_table_item('delete', response, args)).then(()=> setTimeout(()=> $.wpjam_list_table_response(response, args), 300));
					}else{
						if(response.table){
							$('body div.list-table').find('input[name="_wpnonce"], input[name="_wp_http_referer"], table, div').remove().end().find('form').first().append(response.table);
						}else{
							$('body div.list-table').html(response.data);
						}

						if(response.bulk){
							response.ids.forEach((id)=> $.wpjam_list_table_item('update', {id: id}));
						}else if(response.id){
							$.wpjam_list_table_item('update', {id: response.id});
						}
					}
				}else if(response.type == 'add' || response.type == 'duplicate'){
					$.wpjam_list_table_item('create', response);
				}else if(response.type == 'delete'){
					$.wpjam_list_table_item('delete', response);
				}else if(response.type == 'up' || response.type == 'down'){
					let $item	= $.wpjam_list_table_item('get', args.id);

					if(response.type == 'up'){
						$.wpjam_list_table_item('get', args.next).insertAfter($item);
					}else{
						$.wpjam_list_table_item('get', args.prev).after($item);
					}

					$.wpjam_list_table_item('update', response, '#eeffffcc');
				}else if(response.type == 'move'){
					$.wpjam_list_table_item('update', response, '#eeffeecc');
				}else if(response.type == 'move_item'){
					$.wpjam_list_table_item('update', response, false).find('.items [data-i="'+args.pos+'"]').css('background-color', '#eeffeecc');
				}else if(response.type == 'add_item'){
					$.wpjam_list_table_item('update', response, false).find('.items .item:not(.add-item)').last().css('background-color', '#ffffeecc');
				}else if(response.type == 'edit_item'){
					$.wpjam_list_table_item('update', response, false).find('.items [data-i="'+(new URLSearchParams(args.defaults)).get('i')+'"]').css('background-color', '#ffffeecc');
				}else if(response.type == 'del_item'){
					$.wpjam_list_table_item('get', args.id).find('.items [data-i="'+(new URLSearchParams(args.data)).get('i')+'"]').css('background-color', '#ff0000cc').fadeOut(400, ()=> $.wpjam_list_table_item('update', response, false));
				}else if(response.type != 'form'){
					$.wpjam_list_table_item('update', response);
				}

				if(response.next){
					wpjam_params.list_action	= response.next;

					if(response.next != 'add' && response.id){
						wpjam_params.id	= response.id;
					}

					if(args.data && response.type == 'form'){
						wpjam_params.data	= args.data;
					}
				}
			}
		},

		wpjam_list_table_item: function(action, response, bg_color){
			if(action == 'get'){
				id	= typeof(response) == "string" ? response.replace(/(:|\.|\[|\]|,|=|@)/g, "\\$1") : response;

				if($('.tr-'+id).length){
					return $('.tr-'+id);
				}

				let lists	= $('.wp-list-table tbody').data('wp-lists');

				return $('#'+(lists ? lists.split(':')[1] : 'post')+'-'+id);
			}

			if($('div.list-table').hasClass('layout-calendar')){
				response.data.forEach((item, date)=> $('td#date_'+date).html(item).wpjam_bg_color());
			}else if(response.bulk){
				if(action == 'delete'){
					response.ids.forEach((id)=> $.wpjam_list_table_item(action, {id: id}));
				}else{
					response.data.forEach((item)=> $.wpjam_list_table_item(action, item));
				}
			}else{
				if(action == 'create'){
					let pos		= (response.after || response.before);
					let $pos	= pos ? $.wpjam_list_table_item('get', pos) : $('.wp-list-table > tbody tr');
					let $item	= $(response.data);

					if(response.after || response.last){
						$pos.last().after($item);
					}else{
						$pos.first().before($item);
					}

					$item.hide().wpjam_bg_color().fadeIn(400).wpjam_scroll();
				}else{
					let $item	= $.wpjam_list_table_item('get', response.id);

					if(action == 'delete'){
						$item.wpjam_bg_color('#ff0000cc').fadeOut(400, ()=> $item.remove());
					}else{
						if(response.data){
							$item.first().before(response.data).end().remove();

							$item	= $.wpjam_list_table_item('get', response.id);
						}

						return bg_color === false ? $item : $item.hide().wpjam_bg_color(bg_color).fadeIn(1000);
					}
				}
			}
		},

		wpjam_list_table_query_items: function(){
			let action_type	= 'query_items';

			if($('div.list-table').hasClass('layout-left')){
				let left_key	= $('div.left').data('left_key');

				if(wpjam_params.hasOwnProperty('left_paged')){
					action_type	= 'left';
				}

				if(action_type == 'left'){
					delete wpjam_params[left_key];
				}else{
					wpjam_params[left_key]	= $('tr.left-current').data('id');
				}
			}

			if(wpjam_params.hasOwnProperty('id')){
				delete wpjam_params.id;
			}

			$.wpjam_list_table_action({
				action_type:	action_type,
				data:			$.param($.wpjam_filter(wpjam_params))
			});

			return false;
		},

		wpjam_list_table_loaded: function(){
			if($(window).width() > 782){
				if($('p.search-box').length){
					$('ul.subsubsub').css('max-width', 'calc(100% - '+($('p.search-box').width() + 5)+'px)');
				}else{
					if($('.tablenav.top').find('div.alignleft').length == 0){
						$('.tablenav.top').css({clear:'none'});
					}
				}
			}

			if($('.wrap .list-table').length == 0){
				if($('.wrap #col-right .col-wrap').length){
					$('.wrap #col-right .col-wrap').addClass('list-table');
				}else{
					$('ul.subsubsub, div.wrap form').wrapAll('<div class="list-table" />');
				}
			}

			let $list_table		= $('table.wp-list-table');
			let current_view	= $('body .subsubsub').find('li a.current').parent().attr('class');

			if(current_view){
				$list_table.addClass('views-'+current_view).data('view', current_view);
			}

			if($list_table.hasClass('nowrap')){
				$list_table.find('td, th').each(function(){
					if($.inArray($(this).css('max-width'), ['none', '0px', 'auto']) === -1){
						$(this).addClass('fixed');
					}
				});
			}

			let sticky_columns	= [];

			$('th i').each(function(){
				if($(this).data('description')){
					$(this).addClass('dashicons dashicons-editor-help');
				}

				$(this).appendTo($(this).closest('a'));

				let column	= $(this).closest('th').attr('id');

				if(column){
					if($(this).data('sticky')){
						sticky_columns.push('.column-'+column);
					}

					if($(this).data('nowrap')){
						$list_table.find('td.column-'+column).addClass('nowrap-text');
					}

					let format				= $(this).data('format');
					let precision			= $(this).data('precision');
					let conditional_styles	= $(this).data('conditional_styles');

					if(format || precision || conditional_styles){
						$list_table.find('td.column-'+column).each(function(){
							let $cell	= $(this);
							let value	= $cell.text();
							let number	= Number(value);
							let rule	= conditional_styles ? conditional_styles.find((rule)=> $.wpjam_compare(number, rule)) : false;

							if(rule){
								if(rule.bold){
									$cell.css('font-weight', 'bold');
								}

								if(rule.strikethrough){
									$cell.css('text-decoration', 'line-through');
								}

								if(rule.color){
									$cell.css('color', rule.color);
								}

								if(rule['background-color']){
									$cell.css('background-color', rule['background-color']);
								}
							}

							if(!isNaN(number) && (precision || format)){
								if(format == '%'){
									number	= parseFloat((number*100).toFixed(precision || 2))+'%';
								}else{
									if(precision){
										number	= parseFloat(number.toFixed(precision));
									}

									if(format == ','){
										number	= number.toLocaleString();
									}
								}

								$cell.text(number).attr('value', value)
							}
						});
					}
				}
			});

			if(sticky_columns.length){
				$list_table.addClass('sticky-columns');

				if($list_table.height() > $(window).height()){
					if($('#col-left').length && $('#col-left table').height() > $(window).height() && $list_table.height() > $('#col-left table').height()){
						$list_table.css('max-height', $('#col-left table').height());
					}
				}

				if($list_table.find('.check-column').length){
					sticky_columns.unshift('.check-column');
				}

				let left	= 0;

				sticky_columns.forEach((column)=> {
					if(!$list_table.find(column).hasClass('hidden')){
						left	+= $list_table.find(column).addClass('sticky-column').css('left', left).outerWidth();
					}
				});
			}

			$('span.subtitle, div.summary, p.summary').remove();

			if(wpjam_list_table.subtitle){
				$('.wp-header-end').last().before('<span class="subtitle">'+wpjam_list_table.subtitle+'</span>');
			}

			if(wpjam_list_table.summary){
				$('.list-table').before('<div class="summary">'+wpjam_list_table.summary+'</div>');
			}

			if(wpjam_list_table.overall_actions){
				$('div.tablenav.top').find('.overallactions').remove().end().find('div.bulkactions').after(wpjam_list_table.overall_actions);
			}

			wpjam_list_table.loaded	= true;

			if(wpjam_params.id && !wpjam_params.list_action && !wpjam_params.action){
				if($.wpjam_list_table_item('get', wpjam_params.id).length){
					$.wpjam_list_table_item('update', {id:wpjam_params.id});
				}else{
					$.wpjam_list_table_action({action_type:'query_item', id:wpjam_params.id });
				}

				delete wpjam_params.id;
			}

			let sortable_els	= wpjam_list_table.sortable ? $list_table.find('> tbody.ui-sortable').sortable('destroy').end().find('> tbody') : $();

			sortable_els.add($list_table.find('> tbody .items.sortable.ui-sortable').sortable('destroy').end().find('> tbody .items.sortable')).each(function(){
				let args 		= $(this).is('tbody') ? {items: wpjam_list_table.sortable.items, axis: 'y'} : {items: '> div.item'};
				let containment	= $(this).is('tbody') ? $(this).parent().parent() : $(this).parent();

				return $(this).sortable($.extend(args, {
					cursor:			'move',
					handle:			'.list-table-move-action',
					containment:	containment,

					create: function(e, ui){
						$(this).find(args.items).addClass('ui-sortable-item');
					},

					start: function(e, ui){
						ui.placeholder.css({
							'visibility'		: 'visible',
							'background-color'	: '#eeffffcc',
							'width'				: ui.item.width()+'px',
							'height'			: ui.item.height()+'px'
						});
					},

					update:	function(e, ui){
						ui.item.css('background-color', '#eeffeecc');

						let handle	= ui.item.find('.ui-sortable-handle');
						let args	= {
							action_type:	'direct',
							list_action:	handle.data('action'),
							data:			handle.data('data'),
							id:				handle.data('id'),
							_ajax_nonce: 	handle.data('nonce')
						};

						let data	= {};

						['prev', 'next'].forEach((key)=> {
							let handle	= ui.item[key]().find('.ui-sortable-handle');

							if(handle.length){
								if($(this).is('tbody')){
									data[key]	= handle.data('id');
								}else{
									data[key]	= handle.data('i');

									if(!((key == 'next') ^ (ui.item.data('i') >= data[key]))){
										args.pos	= data[key]
									}
								}
							}
						});

						args.data	+= '&type=drag&'+$.param(data)+'&'+$(this).sortable('serialize');

						$(this).sortable('disable');

						$.when($.wpjam_list_table_action(args)).then(()=> {
							$(this).sortable('enable').find(args.items).addClass('ui-sortable-item');
						});
					}
				}));
			});

			if(ajax_list_action){
				let page	= $('#adminmenu a.current').attr('href');

				$('body .subsubsub a[href^="'+page+'"], body tbody#the-list a[href^="'+page+'"]').each(function(){
					if(['list-table-no-ajax', 'list-table-filter'].some((name)=> $(this).hasClass(name))){
						return;
					}

					let params	= Object.fromEntries((new URL($(this).prop('href'))).searchParams);

					if(params.page){
						$(this).addClass('list-table-no-ajax');
					}else{
						$(this).addClass('list-table-filter').data('filter', params);
					}
				});
			}
		},

		wpjam_loaded: function(type=''){
			if(wpjam_params.page_action){
				$.wpjam_page_action($.extend({}, wpjam_params, {action_type: 'form'}));
			}else if(wpjam_params.list_action && wpjam_list_table){
				$.wpjam_list_table_action($.extend({}, wpjam_params, {action_type: 'form'}));
			}else{
				if(type == 'popstate'){
					tb_remove();

					if(wpjam_list_table){
						$.wpjam_list_table_query_items();
					}
				}else{
					if(wpjam_list_table){
						$.wpjam_list_table_loaded();
					}

					$.wpjam_state('replace');
				}
			}
		},

		wpjam_dismiss: function(response){
			if($('#TB_ajaxContent').length && response.dismiss){
				tb_remove();

				return true;
			}

			return false;
		},

		wpjam_response: function(response){
			if($.inArray(response.type, ['reset', 'redirect']) !== -1){
				if(response.url){
					window.open(response.url, response.target);
				}else{
					window.location.reload();
				}
			}else if(response.type == 'append'){
				let wrap	= $('#TB_ajaxContent').length ? '#TB_ajaxContent' : 'div.wrap';

				if(!$(wrap+' .response').length){
					$(wrap).append('<div class="card response hidden"></div>');
				}

				$(wrap+' .response').html(response.data).fadeIn(400);

				if($('#TB_ajaxContent').length){
					$('#TB_ajaxContent').scrollTop($('#TB_ajaxContent form').prop('scrollHeight'));
				}
			}
		},

		wpjam_page_action: function (args){
			let action_type	= args.action_type = args.action_type || 'form';
			let page_action	= args.page_action;

			args.action	= 'wpjam-page-action';

			$.wpjam_post(args, function(response){
				if(response.errcode != 0){
					if(action_type == 'submit'){
						$.wpjam_notice(args.page_title+'失败：'+response.errmsg, 'error');
					}else{
						alert(response.errmsg);
					}
				}else{
					if(action_type == 'form'){
						let response_form	= response.form || response.data;

						if(!response_form){
							alert('服务端未返回表单数据');
						}

						let callback	= args.callback;

						if(callback){
							callback.call(null, response);
						}else{
							$.wpjam_modal(response_form, response.page_title, response.width, response.modal_id);
						}
					}else{
						let dismiss	= $.wpjam_dismiss(response);

						if(response.type == 'redirect'){
							if(dismiss){
								$('body').on('thickbox:removed', ()=> $.wpjam_response(response));
							}else{
								$.wpjam_response(response);
							}
						}else if(response.type == 'append'){
							$.wpjam_response(response);
						}else{
							if(response.done == 0){
								setTimeout(function(){
									args.data	= response.args;
									$.wpjam_page_action(args);
								}, 400);
							}

							if(action_type == 'submit' && $('#wpjam_form').length && response.form){
								$('#wpjam_form').html(response.form);
							}

							let notice_type	= response.notice_type || 'success';
							let notice_msg	= response.errmsg || args.page_title+'成功';

							$.wpjam_notice(notice_msg, notice_type);
						}
					}

					if(action_type != 'form' || response.modal_id == 'tb_modal'){
						$.wpjam_state();
					}

					response.page_action	= page_action;
					response.action_type	= response.page_action_type	= action_type;

					$('body').trigger('page_action_success', response);
				}
			});

			return false;
		},

		wpjam_option_action: function(args){
			args.action			= 'wpjam-option-action';
			args.action_type	= 'submit';

			$.wpjam_post(args, function(response){
				if(response.errcode != 0){
					let notice_msg	= args.option_action == 'reset' ? '重置' : '保存';

					$.wpjam_notice(notice_msg+'失败：'+response.errmsg, 'error');
				}else{
					$('body').trigger('option_action_success', response);

					if($.inArray(response.type, ['reset', 'redirect']) !== -1){
						$.wpjam_response(response);
					}else{
						$.wpjam_notice(response.errmsg, 'success');
					}
				}
			});

			return false;
		},

		wpjam_delegate_events: function(selector, sub_selector){
			sub_selector	= sub_selector || '';

			$.each($._data($(selector).get(0), 'events'), function(type, events){
				$.each(events, function(i, event){
					if(event){
						if(event.selector){
							if(!sub_selector || event.selector == sub_selector){
								$('body').on(type, selector+' '+event.selector, event.handler);
								$(selector).off(type, event.selector, event.handler);
							}
						}else{
							$('body').on(type, selector, event.handler);
							$(selector).off(type, event.handler);
						}
					}
				});
			});
		}
	});

	let wpjam_params		= wpjam_page_setting.params;
	let wpjam_list_table	= wpjam_page_setting.list_table;
	let	ajax_list_action	= $('#list_table_form').length ? true : wpjam_page_setting.ajax_list_action;

	$('body .chart').each(function(){
		let options	= $(this).data('options');
		let id		= $(this).prop('id');

		if(options && id){
			options.element	= id;

			let type	= $(this).data('type');

			if(type == 'Line'){
				Morris.Line(options);
			}else if(type == 'Bar'){
				Morris.Bar(options);
			}else if(type == 'Donut'){
				let size	= 240;

				if($(this).next('table').length){
					size	= $(this).next('table').height();
				}

				if(size > 240){
					size	= 240;
				}else if(size < 180){
					size	= 160;
				}

				$(this).height(size).width(size);

				Morris.Donut(options);
			}
		}
	});

	$('body').on('click', '.show-modal', function(){
		if($(this).data('modal_id')){
			$.wpjam_modal($('#'+$(this).data('modal_id')));
		}
	});

	if($('#notice_modal').length){
		$.wpjam_modal($('#notice_modal'));
	}

	$('body').on('mouseenter', '[data-tooltip],[data-description]', function(e){
		if(!$('#tooltip').length){
			$('body').append('<div id="tooltip"></div>');
		}

		let tooltip	= $(this).data('tooltip') || $(this).data('description');

		$('#tooltip').html(tooltip).show().css({top: e.pageY+22, left: e.pageX-10});
	}).on('mousemove',  function(e){
		$('#tooltip').css({top: e.pageY+22, left: e.pageX-10});
	}).on('mouseleave', function(){
		$('#tooltip').remove();
	}).on('mouseout', function(){
		$('#tooltip').remove();
	});

	$('body').on('tb_unload', '#TB_window', function(){
		if($('#notice_modal').find('.delete-notice').length){
			$('#notice_modal').find('.delete-notice').trigger('click');
		}

		if($(this).hasClass('abscenter')){
			[$.wpjam_position, window.tb_position]	= [window.tb_position, $.wpjam_position];
		}

		$('body #tb_modal').remove();

		if(wpjam_params.page_action){
			delete wpjam_params.page_action;
			delete wpjam_params.data;

			$.wpjam_state();
		}else if(wpjam_params.list_action && wpjam_list_table){
			delete wpjam_params.list_action;
			delete wpjam_params.id;
			delete wpjam_params.data;

			$.wpjam_state();
		}
	});

	$(window).on('resize', function(){
		if($('#TB_window').hasClass('abscenter')){
			tb_position();
		}
	});

	$('body').on('click', '.is-dismissible .notice-dismiss', function(){
		if($(this).prev('.delete-notice').length){
			$(this).prev('.delete-notice').trigger('click');
		}
	});

	// From mdn: On Mac, elements that aren't text input elements tend not to get focus assigned to them.
	$('body').on('click', 'input[type=submit]', function(e){
		if(!$(document.activeElement).attr('id')){
			$(this).focus();
		}
	});

	$('body').on('submit', '#list_table_action_form', function(e){
		e.preventDefault();

		if($(this).data('next')){
			window.action_flow = window.action_flow || [];
			window.action_flow.push($(this).data('action'));
		}

		let submit_button	= $(document.activeElement);

		if($(document.activeElement).prop('type') != 'submit'){
			submit_button	= $(this).find(':submit').first();
			submit_button.focus();
		}

		if($(this).data('bulk') == 2){
			tb_remove();
		}

		$.wpjam_list_table_action({
			action_type :	'submit',
			list_action :	$(this).data('action'),
			submit_name :	submit_button.attr('name'),
			bulk : 			$(this).data('bulk'),
			id :			$(this).data('id'),
			ids :			$(this).data('ids'),
			data : 			$(this).serialize(),
			defaults :		$(this).data('data'),
			_ajax_nonce :	$(this).data('nonce')
		});
	});

	$('body').on('submit', 'div.list-table form', function(e){
		let $active_el	= $(document.activeElement);
		let active_id	= $active_el.attr('id');

		if(active_id == 'doaction' || active_id == 'doaction2'){
			let bulk_name	= $active_el.prev('select').val();

			if(bulk_name == '-1'){
				alert('请选择要进行的批量操作！');
				return false;
			}

			let ids	= $.map($('tbody .check-column input[type="checkbox"]:checked'), (cb)=> cb.value);

			if(ids.length == 0){
				alert('请至少选择一项！');
				return false;
			}

			let bulk_actions	= wpjam_list_table.bulk_actions;

			if(bulk_actions && bulk_actions[bulk_name]){
				let bulk_action	= bulk_actions[bulk_name];

				if(bulk_action.confirm && confirm('确定要'+bulk_action.title+'吗?') == false){
					return false;
				}

				$.wpjam_list_table_action({
					list_action:	bulk_name,
					action_type:	bulk_action.direct ? 'direct' : 'form',
					data:			bulk_action.data,
					_ajax_nonce: 	bulk_action.nonce,
					bulk: 			bulk_action.bulk,
					ids:			ids
				});

				return false;
			}
		}else if(ajax_list_action){
			if(active_id == 'current-page-selector'){
				let paged	= parseInt($active_el.val());
				let total	= parseInt($active_el.next('span').find('span.total-pages').text());

				if(paged < 1 || paged > total){
					alert(paged < 1 ? '页面数字不能小于为1' : '页面数字不能大于'+total);

					return false
				}

				wpjam_params.paged	= paged;

				return $.wpjam_list_table_query_items();
			}else if($.inArray(active_id, ['filter_action', 'post-query-submit', 'search-submit']) != -1 || active_id == $('div.list-table form input[type=search]').attr('id')){
				wpjam_params	= $(this).serializeArray().filter((param)=> !['page', 'tab', 'paged', '_wp_http_referer', '_wpnonce', 'action', 'action2'].includes(param.name));

				return $.wpjam_list_table_query_items();
			}
		}
	});

	$('body').on('click', 'div.list-table .prev-day, div.list-table .next-day', function(){
		let date	= new Date($('#date').val());

		date.setDate(date.getDate()+($(this).hasClass('prev-day') ? -1 : 1));

		$('#date').val(date.toISOString().split('T')[0]);

		$('#filter_action').focus().click();
	});

	$('body').on('click', '.list-table-action', function(){
		if($(this).data('confirm') && confirm('确定要'+$(this).attr('title')+'吗?') == false){
			return false;
		}

		let args	= {
			action_type :	$(this).data('direct') ? 'direct' : 'form',
			list_action :	$(this).data('action'),
			id : 			$(this).data('id'),
			data : 			$(this).data('data'),
			_ajax_nonce :	$(this).data('nonce')
		};

		let $item	= $.wpjam_list_table_item('get', args.id);

		if(args.list_action == 'up' || args.list_action == 'down'){
			let action	= args.list_action == 'up' ? 'prev' : 'next';
			let key		= action == 'next' ? 'prev' : 'next';
			args[key]	= $item[action]().find('.ui-sortable-handle').data('id');

			if(!args[key]){
				alert(action == 'next' ? '已经最后一个了，不可下移了。' : '已经是第一个了，不可上移了。');
				return false;
			}

			args.data	= args.data ? args.data + '&'+key+'='+args[key] : key+'='+args[key];
		}else if(args.action_type == 'form'){
			wpjam_params.list_action	= args.list_action;

			if(args.list_action != 'add' && args.id){
				wpjam_params.id	= args.id;
			}

			if(args.data){
				wpjam_params.data	= args.data;
			}
		}

		$.wpjam_list_table_action(args);

		$(this).blur();
	});

	$('body').on('click', '.list-table-filter:not(.disabled)', function(){
		wpjam_params	= $(this).data('filter');

		return $.wpjam_list_table_query_items();
	});

	$('body').on('click', 'div#col-left .left-item', function(){
		$(this).siblings('.left-item').removeClass('left-current').end().addClass('left-current');

		let left_paged	= wpjam_params.left_paged || 1;

		delete wpjam_params.left_paged;

		try{
			return $.wpjam_list_table_query_items();
		}finally{
			wpjam_params.left_paged = left_paged;
		}
	});
		
	$('body').on('click', 'div.list-table form .pagination-links a', function(){
		if(ajax_list_action){
			wpjam_params.paged	= (new URL($(this).prop('href'))).searchParams.get('paged');

			return $.wpjam_list_table_query_items();
		}
	});

	$('body').on('click', 'div.list-table form th.sorted a, div.list-table form th.sortable a', function(){
		if(ajax_list_action){
			let href = new URL($(this).prop('href'));

			wpjam_params.orderby	= href.searchParams.get('orderby') || $(this).parent().attr('id');
			wpjam_params.order		= href.searchParams.get('order') || ($(this).parent().hasClass('asc') ? 'desc' : 'asc');
			wpjam_params.paged		= 1;

			return $.wpjam_list_table_query_items();
		}
	});

	$('body').on('click', '#col-left .left-pagination-links a.goto', function(){
		let paged	= parseInt($(this).prev('input').val());
		let total	= $(this).parents('.left-pagination-links').data('total_pages');

		if(paged < 1 || paged > total){
			alert(paged < 1 ? '页面数字不能小于为1' : '页面数字不能大于'+total);

			return false
		}

		wpjam_params.left_paged	= paged;

		return $.wpjam_list_table_query_items();
	});

	$('body').on('keyup', '.left-pagination-links input', function(e) {
		if(e.key === 'Enter' || e.keyCode === 13){
			$(this).next('a').trigger('click');
		}
	});

	$('body').on('change', '#col-left select.left-filter', function(){
		let name = $(this).prop('name');

		wpjam_params.left_paged	= 1;
		wpjam_params[name]		= $(this).val();

		return $.wpjam_list_table_query_items();
	});

	$('body').on('click', '.wpjam-button', function(e){
		e.preventDefault();

		if($(this).data('confirm') && confirm('确定要'+$(this).data('title')+'吗?') == false){
			return false;
		}

		let args	= {
			action_type:	$(this).data('direct') ? 'direct' : 'form',
			data:			$(this).data('data'),
			form_data:		$(this).parents('form').serialize(),
			page_action:	$(this).data('action'),
			page_title:		$(this).data('title'),
			_ajax_nonce:	$(this).data('nonce')
		};

		if(args.action_type == 'form'){
			wpjam_params.page_action	= args.page_action;

			if(args.data){
				wpjam_params.data	= args.data;
			}
		}

		return $.wpjam_page_action(args);
	});

	$('body').on('submit', '#wpjam_form', function(e){
		e.preventDefault();

		let $active_el	= $(document.activeElement);
		let $submit_btn	= $active_el.prop('type') === 'submit' ? $active_el : $(this).find(':submit').first().focus();

		return $.wpjam_page_action({
			action_type:	'submit',
			data: 			$(this).serialize(),
			page_action:	$(this).data('action'),
			submit_name:	$submit_btn.attr('name'),
			page_title:		$submit_btn.attr('value'),
			_ajax_nonce:	$(this).data('nonce')
		});
	});

	$('body').on('submit', '#wpjam_option', function(e){
		e.preventDefault();

		let option_action	= $(document.activeElement).data('action');

		if(option_action == 'reset' && confirm('确定要重置吗?') == false){
			return false;
		}

		$.wpjam_option_action({
			option_action:	option_action,
			_ajax_nonce: 	$(this).data('nonce'),
			data:			$(this).serialize()
		});
	});

	$.wpjam_loaded();

	window.onpopstate = function(event){
		if(event.state && event.state.wpjam_params){
			wpjam_params	= event.state.wpjam_params;

			$.wpjam_loaded('popstate');
		}
	};
});