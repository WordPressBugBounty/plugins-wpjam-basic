jQuery(function($){
	$.fn.wpjam_init	= function(rules){
		_.each($.fn.wpjam_component.rules, rule => this.wpjam_component(rule));

		_.each(Object.entries($.fn), ([n, f])=> {
			if(n.startsWith('wpjam_') && _.isFunction(f) && f.rule){
				this.wpjam_component(f.rule, n);
			}
		});

		_.each(rules, rule => this.wpjam_component(rule));

		return this;
	};

	$.fn.wpjam_component	= function(rule, name){
		let selector	= rule.selector;
		let callback	= rule.callback;
		let	handle		= callback ? null : (name || 'wpjam_'+rule.name);

		if(_.isFunction(callback) || _.isFunction($.fn[handle])){
			if(this.is('body') && handle){
				_.each(rule.events, event => {
					let n	= event;
					let s	= selector;
					let a	= n;
					let t	= '';

					if(_.isObject(event)){
						n	= event.name;
						s	= event.selector || s;
						a	= event.action || n;
						t	= event.type;
					}

					if(s && n && a){
						let callback	= function(...args){
							return $(this)[handle](a, ...args);
						};

						if(t == 'throttle'){
							callback	= _.throttle(callback, 500);
						}else if(t == 'debounce'){
							callback	= _.debounce(callback, 500);
						}

						this.on(n+'.wpjam', s === 'body' ? null : s, callback);
					}
				});
			}

			if(selector){
				this.wpjam_each(selector, $el => handle ? $el[handle]() : callback($el));
			}
		}
	}

	$.fn.wpjam_component.rules	= [
		{name: 'file',		selector: '[data-media_button]',	events: [
			{name: 'click', 	selector: '.wpjam-img, .wpjam-image .button, .wpjam-file .button'}
		]},
		{name: 'checkable',	selector: '.checkable',	events: [
			'validate',
			{name: 'change',	selector: '.checkable input'},
			{name: 'click',		selector: '.mu-select-wrap button'},
			{name: 'click',		selector: 'body'},
		]},
		{name: 'input',		selector: 'input',	events: [
			{name: 'query_label',	selector: 'input'},
		]},
		{name: 'textarea',	selector: 'textarea'},
		{name: 'select',	selector: 'select'},
		{name: 'plupload',	selector: '.plupload'},
		{name: 'show_if',	selector: '[data-show_if]'},
		{name: 'data_type',	selector: '[data-data_type][data-query_args]'},
		{name: 'mu',		selector: '.mu',	events: [
			{name: 'keydown',	selector: '.mu-text :input, .mu-fields :input'},
			{name: 'click',		selector: '.mu .new-item',	action: 'new_item'},
			{name: 'click',		selector: '.mu .del-item',	action: 'del_item'}
		]},
		{name: 'depend',	selector: '.has-dependents',	events: ['change']},
		{name: 'expend',	selector: '.expandable',	events: [
			{name: 'input',		type: 'throttle'},
			{name: 'change',	type: 'throttle'}
		]}
	];

	$.fn.wpjam_file	= function(action, e){
		if(action == 'click'){
			let $field	= this.is('.wpjam-img') ? this : this.parent();

			if($(e.target).is('.del-img')){
				this.data('value', '').wpjam_file();
			}else if(!$field.hasClass('readonly')){
				$field.wpjam_media({
					id: 		$field.find('input').prop('id'),
					selected:	(data)=> $field.data('value', data).wpjam_file()
				});
			}

			return false;
		}else{
			let data	= this.data('value');

			if(!this.find('.add-media').length){
				this.append('<a class="add-media button"><span class="dashicons dashicons-admin-media"></span>'+this.data('media_button')+'</a>');
			}

			this.find('input').val(data ? data.value : '');

			if(this.is('.wpjam-img')){
				if(data){
					this.find('img, .del-img').remove();
					this.prepend([$('<img>', _.extend({src: data.url.split('?')[0]+this.data('thumb_args')}, this.data('size'))), '<a class="del-img dashicons dashicons-no-alt"></a>']);
				}else{
					this.find('img').fadeOut(300, ()=> this.find('img').remove()).next('.del-img').remove();
				}
			}
		}
	};

	$.fn.wpjam_checkable	= function(action, e){
		if(action == 'validate'){
			this.find(':checkbox').toArray().forEach(el => el.setCustomValidity(''));

			let $el		= $(e.target);
			let types	= $el.is('.checkable') ? ['min', 'max'] : [$el.is(':checked') ? 'max' : 'min'];
			let el		= $el.is('.checkable') ? this.find(':checkbox')[0] : $el[0];
			let count	= this.find(':checkbox:checked').length;

			for(let type of types){
				let value	= parseInt(this.data(type+'_items'));
				let custom	= value ? (type == 'max' ? (count > value ? '最多选择'+value+'个' : '') : (count < value ? '至少选择'+value+'个' : '')) : '';

				if(custom){
					el.setCustomValidity(custom);
					el.reportValidity();

					return false;
				}
			}
		}else if(action == 'change'){
			let $field	= this.closest('.checkable');
			let $el		= $(e.target);

			if($el.is(':checkbox')){
				$el.trigger('validate.wpjam');
			}else{
				$field.find('label').removeClass('checked');
			}

			$el.closest('label').toggleClass('checked', $el.is(':checked'));

			if($field.hasClass('mu-select')){
				$field.prev('button').text($field.find('label.checked').map((i, el) => $(el).text().trim()).get().join(', ') || $field.data('show_option_all'));
			}
		}else if(action == 'click'){
			if(this.is('button')){
				let $field	= this.next('.mu-select').removeAttr('style').toggleClass('hidden').css('max-height', Math.min($(window).height()-100, 300));

				if(!$field.hasClass('hidden')){
					let rest	= $(window).height()+$(window).scrollTop() - $field.offset().top;

					if($field.outerHeight() > rest){
						$field.css({top: 'auto', bottom: 50-rest});
					}
				}

				return false;
			}

			if(!$(e.target).closest('.mu-select-wrap').length){
				$('.mu-select').addClass('hidden');
			}
		}else{
			if(this.hasClass('mu-select')){
				this.before($('<button>', {type:'button', class:'selectable', text: this.data('show_option_all')})).find('label').html((i, v)=> v.replace(/(<input[^>]*type="checkbox"[^>]*>)([\u2003]+)(.*)$/, (match, p1, p2, p3) => p2+p1+p3));
			}

			if(this.find(':checkbox').length){
				this.addClass('has-validator');
			}

			this.find([].concat(this.data('value') || []).map(v => `input[value="${v}"]`).join(',')).click();
		}
	};

	$.fn.wpjam_input	= function(action){
		if(action == 'query_label'){
			let $label	= this.prev('span.query-label');

			if($label.length){
				if($label.closest('.mu-text').length){
					$label.wpjam_mu('del_item');
				}else{
					$label.next('input').val('').change().end().fadeOut(300, ()=> $label.remove());
				}
			}else{
				let label	= this.data('label') || (this.hasClass('plupload-input') ? this.val().split('/').pop() : this.val());

				if(label){
					$('<span class="query-label">'+label+'</span>').prepend($('<span class="dashicons"></span>').on('click', ()=> this.trigger('query_label'))).addClass(this.closest('.tag-input').length ? '' : this.data('class')).insertBefore(this);
				}
			}
		}else{
			let type	= this.attr('type');

			if(type == 'color'){
				let $label	= this.attr('type', 'text').val(this.attr('value')).parent('label');
				let $picker	= this.wpColorPicker().closest('.wp-picker-container').append($label.next('.description')).attr('data-show_if', $label.attr('data-show_if')).find('.wp-color-result-text').text((i, text)=> this.data('button_text') || text).end();

				if($label.removeAttr('data-show_if').text()){
					$label.prependTo($picker);
					$picker.find('button').add($picker.find('.wp-picker-input-wrap')).insertAfter(this);
					this.prependTo($picker.find('.wp-picker-input-wrap'));
				}
			}else if(type == 'timestamp'){
				let val	= this.val();

				if(val){
					let pad2	= num => (num.toString().length < 2 ? '0' : '')+num;
					let date	= new Date(+val*1000);

					this.val(date.getFullYear()+'-'+pad2(date.getMonth()+1)+'-'+pad2(date.getDate())+'T'+pad2(date.getHours())+':'+pad2(date.getMinutes()));
				}

				this.attr('type', 'datetime-local');
			}else if(type == 'checkbox'){
				if(this.data('value')){
					this.prop('checked', true);
				}
			}else{
				if(this.is('.tiny-text, .small-text')){
					this.addClass('expandable');
				}
			}
		}
	};

	$.fn.wpjam_textarea	= function(){
		if(this.data('editor')){
			if(wp.editor){
				let id	= this.attr('id');

				wp.editor.remove(id);
				wp.editor.initialize(id, this.data('editor'));

				this.attr({rows: 10, cols: 40});
			}else{
				console.log('请在页面加载 add_action(\'admin_footer\', \'wp_enqueue_editor\');');
			}
		}else if(!this.hasClass('wp-editor-area')){
			if(!this.attr('rows')){
				this.addClass('expandable').attr('rows', 4);
			}

			if(!this.attr('cols')){
				this.css('max-width', '100%').attr('cols', (this.closest('#TB_window')[0] ? 52 : 68));
			}
		}
	};

	$.fn.wpjam_select	= function(){
		_.each(['all', 'none'], k => {
			let label	= this.data('show_option_'+k);

			if(label != null){
				this.prepend('<option value="'+(this.data('option_'+k+'_value') || '')+'">'+label+'</option>');

				if(this.find('option').is(':selected')){
					this.find('option').first().prop('selected', true);
				}
			}
		});

		let value	= this.data('value');

		if(value != null && this.find(`option[value="${value}"]`).length){
			this.val(value);
		}

		return this;
	};

	$.fn.wpjam_plupload	= function(){
		let $input	= this.find('input').addClass('plupload-input');
		let up_args	= this.data('plupload');

		if(up_args.drop_element){
			$input.wrap('<p class="drag-drop-buttons"></p>');
			this.addClass('drag-drop').prepend('<p class="drag-drop-info">'+up_args.drop_info[0]+'</p><p>'+up_args.drop_info[1]+'</p>');
			this.wrapInner('<div class="plupload-drag-drop" id="'+up_args.drop_element+'"><div class="drag-drop-inside"></div></div>');
		}

		this.attr('id', up_args.container);
		$input.before('<input type="button" id="'+up_args.browse_button+'" value="'+up_args.button_text+'" class="button">').trigger('query_label');

		let uploader	= new plupload.Uploader(_.extend(up_args, {
			url : ajaxurl,
			multipart_params : wpjam.append_page_setting(up_args.multipart_params),
			init: {
				Init: (up)=> {
					if(up.features.dragdrop){
						$(up.settings.drop_element).on('dragover.wp-uploader', ()=> this.addClass('drag-over')).on('dragleave.wp-uploader, drop.wp-uploader', ()=> this.removeClass('drag-over'));
					}else{
						$(up.settings.drop_element).off('.wp-uploader');
					}
				},
				PostInit: (up)=> {
					up.refresh();
				},
				FilesAdded: (up, files)=> {
					up.refresh();
					up.start();
					$input.prev('span').remove();
					this.append('<div class="progress"><div class="percent"></div><div class="bar"></div></div>');
				},
				Error: (up, error)=> {
					alert(error.message);
				},
				UploadProgress: (up, file)=> {
					this.find('.bar').width((200 * file.loaded) / file.size).end().find('.percent').html(file.percent + '%');
				},
				FileUploaded: (up, file, result)=> {
					this.find('.progress').remove();

					let response	= JSON.parse(result.response);

					if(response.errcode){
						alert(response.errmsg);
					}else{
						$input.val(response.path).trigger('query_label');
					}
				}
			}
		}));

		uploader.init();
	};

	$.fn.wpjam_show_if	= function(...args){
		let show_if	= this.data('show_if');

		if(args.length){
			let show	= args[0] === null ? false : wpjam.compare(args[0], show_if);

			this.add(this.nextAll('br')).add(this.next('p.after')).add(this.nextAll('p.description')).add(this.prev('p.before')).toggleClass('hidden', !show);

			(this.is('option, :input') ? this : this.find(':input:not(.disabled)')).prop('disabled', !show);

			if(this.is('option')){
				if(this.is(':selected')){
					this.closest('select').prop('selectedIndex', (i, v) => show ? v : 0).trigger('change.wpjam');
				}
			}else{
				(this.is(':input') ? this : this.find('.has-dependents')).trigger('change.wpjam');
			}
		}else{
			this.wpjam_depend('add_to', show_if.key);

			let $field	= this.data('key') ? this : this.find('.field-key-'+this.data('for'));

			if(!$field.attr('data-dep')){
				$field.attr('data-dep', show_if.key);
			}
		}
	};

	$.fn.wpjam_data_type	= function(action, ...args){
		let query_args	= this.data('query_args');
		let data_type	= this.data('data_type');
		let filter_key	= this.data('filter_key');
		let $mu			= this.closest('.mu-text');

		if(action == 'query'){
			if(args[1]){
				query_args[(data_type == 'post_type' ? 's' : 'search')]	= args[1];
			}

			if($mu.data('unique_items')){
				query_args.exclude	= $mu.wpjam_val();
			}

			return wpjam.post({action: 'wpjam-query', data_type, query_args}, data => {
				if(data.errcode != 0){
					if(data.errmsg){
						alert(data.errmsg);
					}
				}else{
					args[0](data.items);
				}
			});
		}else if(action == 'filter'){
			if(this.hasClass('hidden')){
				return;
			}

			if(query_args[filter_key] != args[0]){
				this.data('query_args', _.extend({}, query_args, {[filter_key] : args[0]}));

				if(!args[1]){
					if(this.is('input')){
						this.trigger('query_label');
					}else if(this.is('select')){
						this.addClass('hidden').empty().wpjam_select().wpjam_data_type('query', items => (items.length ? this.append(items.map(item => '<option value="'+item.value+'">'+item.label+'</option>')).removeClass('hidden') : this).trigger('change.wpjam'));
					}
				}
			}else{
				if(!args[1] && this.is('select') && this.find('option').filter((i, opt) => opt.value).length == 0){
					this.addClass('hidden');
				}
			}
		}else{
			if(filter_key && this.data('dep')){
				this.wpjam_data_type('filter', this.wpjam_depend('add_to', this.data('dep')).wpjam_val(), true);
			}

			if(!this.is('input')){
				return this;
			}
		
			let $hidden	= ($mu[0] || !this.data('filterable')) ? null : $('<input>', {type: 'hidden', 'name': this.attr('name'), 'value': this.val()}).insertAfter(this);

			if($hidden){
				this.removeAttr('name').val(this.data('label') || this.val());
			}else{
				this.trigger('query_label');
			}

			return this.autocomplete({
				minLength:	0,
				delay: 400,
				source: (request, response)=> {
					this.wpjam_data_type('query', response, request.term);
				},
				search: (e, ui)=> {
					if(!this.val() && _.isMatch(e.originalEvent, {type: 'keydown', key: 'Backspace'})){
						return false;
					}
				},
				select: (e, ui)=> {
					if($hidden){
						this.val(ui.item.label);
						$hidden.val(ui.item.value);

						return false;
					}else{
						if($mu[0] && $mu.wpjam_mu('new_item') === -1){
							ui.item.value	= null;
						}

						if(!_.isNull(ui.item.value)){
							this.data('label', ui.item.label).trigger('query_label');
						}
					}
				},
				change: (e, ui)=> {
					this.trigger('change.wpjam');
				}
			}).on('click', (e)=> {
				this.autocomplete('search');
			}).on('keydown', (e)=> {
				if(!this.val() && e.key === 'Backspace'){
					this.autocomplete('close');
				}
			}).on('input', (e)=>{
				$hidden.val(this.val());
			});
		}
	};

	$.fn.wpjam_mu	= function(action, args){
		let $mu		= this.closest('.mu');
		let type	= $mu.attr('class').split(' ').find(v => v.startsWith('mu-')).substring(3);
		let is_tag	= $mu.hasClass('tag-input');
		let is_row	= $mu.hasClass('direction-row');
		let	max		= parseInt($mu.data('max_items'));
		let count	= $mu.children().length - (['img', 'fields', 'text'].includes(type) ? 1 : 0);
		let rest	= max ? (max - count) : 0;

		if(action == 'new_item'){
			if(max && rest <= 0){
				if(args !== false){
					alert('最多支持'+max+'个');
				}

				return args ? false : -1;
			}

			if($mu.data('unique_items')){
				let value	= $mu.wpjam_val();

				if(value && _.uniq(value).length !== value.length){
					if(args !== false){
						alert('不允许重复');
					}

					return args ? false : -1;
				}
			}

			if(['img', 'image', 'file'].includes(type)){
				$mu.wpjam_media({
					id:			$mu.prop('id'),
					rest:		rest,
					multiple: 	true,
					selected:	(data)=> $mu.wpjam_mu('add_item', (type == 'img' ? data : data.value))
				});
			}else{
				let $items	= $mu.find('> .mu-item');
				let $item	= $items.length >= 2 ? $items.eq(-2) : null;

				if($item){
					if(!$item.wpjam_mu('validate')){
						return false;
					}

					if(type == 'fields'){
						$item.wpjam_mu('tag_label');
					}
				}

				$mu.wpjam_mu('add_item');
			}

			return false;
		}else if(action == 'add_item'){
			let $tmpl	= $mu.find('> .mu-item').last();
			let $new	= $tmpl.clone().find('.new-item').remove().end();

			if(['img', 'image', 'file'].includes(type)){
				if(type == 'img'){
					$new.prepend('<img src="'+args.url+$mu.data('thumb_args')+'" data-preview="'+args.url+'" />');

					args	= args.value;
				}

				$new.find('input').val(args).end().insertBefore($tmpl);
			}else if(type == 'text'){
				let $input	= $new.find(':input').val('');

				if(args){
					if(_.isObject(args)){
						$input.val(args.value);

						if(args.label){
							$input.data('label', args.label);
						}
					}else{
						$input.val(args);

						if(is_tag && !$input.is('[data-data_type]')){
							$input.trigger('query_label');
						}
					}

					$new.insertBefore($tmpl).wpjam_init();
				}else{
					if(is_row && $tmpl.prev().length){
						$tmpl	= $tmpl.prev();
					}

					$tmpl.find('.new-item').insertAfter($input);
					$new.insertAfter($tmpl).wpjam_init();

					$input.focus();
				}

				if(!is_tag && max && rest <= 1){
					$mu.addClass('max-reached');
				}
			}else if(type == 'fields'){
				let i	= $mu.data('i') || $mu.find(' > .mu-item').length-1;
				let $t	= $new.find('template');

				$mu.data('i', i+1);
				$t.replaceWith($t.html().replace(/\$\{i\}/g, i)).end().insertBefore($tmpl).wpjam_init();
			}
		}else if(action == 'del_item'){
			this.closest('.mu-item').fadeOut(300, function(){
				$(this).remove();

				if(type == 'text' && !is_tag && max && rest <= 0){
					$mu.removeClass('max-reached');
				}
			});

			return false;
		}else if(action == 'validate'){
			for(let input of this.find(':input').toArray()){
				if(!input.checkValidity()){
					input.reportValidity();

					return false;
				}
			}

			return true;
		}else if(action == 'keydown'){
			if(is_tag){
				if(args.key === 'Backspace' && !this.val()){
					this.closest('.mu-item').prev().fadeOut(300, function(){
						if($mu.wpjam_timer()){
							$(this).remove();
						}else{
							$mu.wpjam_timer('start');

							$(this).fadeIn(200);
						}
					});
				}else{
					$mu.wpjam_timer('cancel');
				}
			}

			if(args.key === 'Enter'){
				if($mu.is('.mu-text')){
					if(this.val() && !this.data('data_type')){
						let rest	= $mu.wpjam_mu('new_item');

						if(rest !== -1){
							let $items	= this.closest('.mu-text').find('.mu-item:has(input:visible)');

							if($items.length > 1){
								$items.last().insertAfter(this.closest('.mu-item')).find('input').focus();
							}
						}

						if(is_tag){
							if(rest === -1){
								this.val('');
							}else{
								this.trigger('query_label');
							}
						}
					}
				}else if($mu.data('tag_label')){
					let $inputs = this.closest('.mu-item').find(':input:visible');
					let $next	= $inputs.eq($inputs.index(this)+1);

					if($next.length){
						$next.focus().select();
					}else{
						let $items	= $mu.find('> .mu-item');
						let $item	= this.closest('.mu-item');

						if($item.is($items.eq(-2))){
							let result	= $mu.wpjam_mu('new_item');

							if(result && result !== -1){
								$mu.find('.mu-item:has(:input)').last().find(':input').first().focus();
							}
						}else{
							if(!$item.wpjam_mu('validate')){
								return false;
							}

							$item.wpjam_mu('tag_label');
						}
					}
				}

				return false;
			}
		}else if(action == 'tag_label'){
			let tag_label	= $mu.data('tag_label');

			if(tag_label && !this.has('template').length && !this.has('span.tag-label').length){
				tag_label	= tag_label.replace(/\${(.*?)}/g, (match, name) => {
					let $field	= this.find('[data-name="'+name+'"]');

					return $field.is('select') ? $field.find('option:selected').text().trim() : $field.val();
				});

				$('<span class="tag-label">'+tag_label+'</span>').prependTo(this).append(this.find('.del-item')).on('dblclick', (e)=>$(e.target).remove());
			}
		}else{
			if(type != 'fields'){
				$mu.wrapInner('<div class="mu-item"></div>');
			}

			let value		= $mu.data('value') || [];
			let sortable	= $mu.is('.sortable') && !$mu.closest('.disabled, .readonly').length;

			if(value){
				value.forEach(v => $mu.wpjam_mu('add_item', v));
			}

			if(is_tag){
				$mu.addClass('has-validator').on('validate.wpjam', ()=> $mu.find('input:visible').val(''));
			}else{
				let btn	= type == 'img' ? '' : ($mu.data('button_text') || null);

				if(btn !== null){
					$mu.find('> .mu-item:last').append($('<a class="new-item button">'+btn+'</a>'));
				}

				if(type == 'fields' && $mu.data('tag_label')){
					$mu.addClass('taggable');
				}

				$mu.wpjam_each('> .mu-item', $el => {
					$el.append([
						'<a class="del-item '+(is_row ? 'dashicons dashicons-no-alt' : 'button')+'">'+(is_row ? '' : '删除')+'</a>',
						sortable && type != 'img' ? '<span class="move-item dashicons dashicons-menu"></span>' : ''
					]);

					if(type == 'fields'){
						$el.wpjam_mu('tag_label');	
					}
				});
			}

			if(['text', 'fields'].includes(type) && is_row){
				$mu.wpjam_mu('new_item', false);
			}

			if(sortable){
				$mu.sortable({cursor: 'move', items: '.mu-item:not(:last-child)'});
			}
		}

		return this;
	};

	$.fn.wpjam_depend	= function(action, key){
		if(action == 'add_to'){
			let $dep	= this.closest('form').find('.field-key-'+key);

			if(!$dep.length){
				$dep	= $('.field-key-'+key);
			}

			if($dep.length){
				$dep	= $($dep.addClass('has-dependents').closest('.checkable')[0] || $dep[0]);
				let id	= $dep.data('dep-id') || $dep.attr('data-dep-id', key+'-'+Math.random().toString(36).substr(2, 9)).data('dep-id');

				this.addClass('dep-on-'+id);
			}

			return $dep;
		}else{
			let $dep	= $(this.closest('.checkable')[0] || this[0]);
			let val		= $dep.wpjam_val();

			$('.dep-on-'+$dep.data('dep-id')).each(function(){
				let $field = $(this);

				if($field.data('show_if')){
					$field.wpjam_show_if(val);
				}

				if($field.data('filter_key')){
					$field.wpjam_data_type('filter', val);
				}
			});
		}
	};

	$.fn.wpjam_expend	= function(action){
		if(this.is('input')){
			if(this.is(':visible')){
				this.width('').width(Math.min(522, this.prop('scrollWidth')-(this.innerWidth()-this.width())));
			}
		}else if(this.is('textarea')){
			if(action){
				this.animate({height: Math.min(320, this.height('').prop('scrollHeight')+5)}, action == 'click' ? 300 : 0);
			}else{
				this.one('click', ()=> this.width(this.width()).wpjam_expend('click'));
			}
		}
	};

	$.fn.wpjam_chart	= function(){
		let type	= this.data('type');

		if(['Line', 'Bar', 'Donut'].includes(type)){
			if(type == 'Donut'){
				this.height(Math.max(160, Math.min(240, this.next('table').height() || 240))).width(this.height());
			}

			Morris[type](_.extend({}, this.data('options'), {element: this.prop('id')}));
		}
	};

	$.fn.wpjam_chart.rule	= {selector: '[data-chart]'};

	$.fn.wpjam_preview	= function(action, e){
		let $modal	= $('.quick-modal');

		if(action == 'preview'){
			$modal	= $modal[0] ? $modal.empty() : $('<div class="quick-modal"></div>').appendTo($('body'));

			$('<img>').on('load', function(){
				let width	= this.width/2;
				let height	= this.height/2;

				if(width>400 || height>500){
					let radio	= Math.min(400/width, 500/height);

					width	= width * radio;
					height	= height * radio;
				}

				$modal.append(['<a class="dashicons dashicons-no-alt del-icon"></a>', $(this).width(width).height(height)]);
			}).attr('src', this.data('preview'));
		}else{
			$modal.fadeOut(300, ()=> $modal.remove());
		}	
	};

	$.fn.wpjam_preview.rule	= {events: [
		{name: 'click', action: 'preview',	selector: '[data-preview]'},
		{name: 'click', action: 'remove',	selector: '.quick-modal .del-icon'}
	]};

	$.fn.wpjam_tooltip	= function(action, e){
		if(['mouseenter', 'mousemove'].includes(action)){
			let $tooltip	= $($('#tooltip')[0] || $('<div id="tooltip"></div>').html($(this).data('tooltip') || $(this).data('description')).appendTo('body')[0]);

			$tooltip.css({
				top: e.pageY + 22,
				left: Math.min(e.pageX - 10, window.innerWidth - $tooltip.outerWidth() - 20),
				'--arrow-left': (e.pageX - $tooltip.offset().left)+'px'
			});
		}else if(['mouseleave', 'mouseout'].includes(action)){
			$('#tooltip').remove();
		}else{
			if(this.is('[data-description]')){
				this.addClass('dashicons dashicons-editor-help');
			}
		}
	};

	$.fn.wpjam_tooltip.rule	= {
		selector:	'[data-tooltip], [data-description]', 
		events:		['mouseenter', 'mousemove', 'mouseleave', 'mouseout']
	};

	$.fn.wpjam_link = function(){
		this.attr('href', this.attr('href').replace('admin/page=', 'admin/admin.php?page='));
	};

	$.fn.wpjam_link.rule	= {
		selector:	'a[href*="admin/page="]'
	};

	$.fn.wpjam_form	= function(action){
		let init	= this.is('body');

		(this.is('body') ? this.find('form') : this).each(function(){
			if(!$(this).data('initialized')){
				$(this).data('initialized', true);

				if(init){
					$(this).wpjam_init();
				}
			}
		});

		return this;
	};

	$.fn.wpjam_validate	= function(){
		if(this[0].checkValidity()){
			this.find('.has-validator').trigger('validate.wpjam');
		}

		if(!this[0].checkValidity()){
			let $field	= this.find(':invalid').first();
			let custom	= $field.data('custom_validity');

			if(custom){
				$field.one('input', ()=> $field[0].setCustomValidity(''))[0].setCustomValidity(custom);
			}

			if(!$field.is(':visible')){
				$field.wpjam_each('.ui-tabs',	$el => $el.tabs('option', 'active', $el.find('.ui-tabs-panel').index($($field.closest('.ui-tabs-panel')))), 'closest');
				$field.wpjam_each('.mu-select',	$el => $el.removeClass('hidden'), 'closest');
			}

			return this[0].reportValidity();
		}

		return true;
	};

	$.fn.wpjam_val	= function(){
		if(this.prop('disabled')){
			return null;
		}else if(this.is('span')){
			return this.data('val');
		}else if(this.is('.mu-text')){
			return this.find('input').toArray().map(el=> el.value).filter(v => v !== '');
		}else if(this.is('.checkable')){
			let val	= this.find('input:checked').toArray().map(el => el.value);

			return this.find('input').is(':radio') ? (val.length ? val[0] : null) : val;
		}else if(this.is(':checkbox, :radio')){
			let val	= this.closest('.checkable').wpjam_val();

			return val !== undefined ? val : (this.is(':checked') ? this.val() : (this.is(':checkbox') ? 0 : null));
		}else{
			return this.val();
		}
	};

	$.fn.wpjam_timer	= function(action){
		if(action == 'start'){
			this.data('timer', _.debounce(()=> this.wpjam_timer('cancel'), 2000)).data('timer')();
		}else{
			let timer	= this.data('timer');

			if(timer){
				timer.cancel();

				this.removeData('timer');
			}

			return timer;
		}
	};

	$.fn.wpjam_media	= function(args){
		let type	= this.data('item_type') || (this.is('.wpjam-img, .mu-img') ? 'id' : (this.is('.wpjam-image, .mu-image') ? 'image' : ''));
		args.id		= 'uploader_'+args.id;
		args.title	= this.data('button_text') || this.find('.add-media').text();
		args.library= {type: this.is('.wpjam-img, .wpjam-image, .mu-img, .mu-image') ? 'image' : type};
		// args.button	= {text: title}

		if(wp.media.view.settings.post.id){
			args.frame	= 'post';
		}

		let frame	= wp.media.frames.wpjam = wp.media(args);

		frame.on('open', function(){
			frame.$el.addClass('hide-menu');

			if(args.multiple && args.rest){
				frame.state().get('selection').on('update', function(){
					if(this.length > args.rest){
						this.reset(this.first(args.rest));

						alert('最多可以选择'+args.rest+'个');
					}
				});
			}
		}).on((wp.media.view.settings.post.id ? 'insert' : 'select'), function(){
			frame.state().get('selection').map((attachment)=> {
				let data	= attachment.toJSON();
				data.value	= ['image', 'url'].includes(type) ? data.url+'?'+$.param(_.pick(data, 'orientation', 'width', 'height')) : (type == 'id' ? data.id : data.url);

				args.selected(data);
			});
		}).open();
	};

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
	};

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

	$('body').wpjam_init([
		{name: 'tabs',	selector: '.tabs', callback: $el => $el.tabs({activate: (e, ui)=> window.history.replaceState(null, null, ui.newTab.children('a')[0].hash)})},
		{name: 'form',	selector: 'form'}
	]);
});

if(self != top){
	document.getElementsByTagName('html')[0].className += ' TB_iframe';
}