jQuery(function($){
	$.fn.wpjam_each = function(cb){
		return this.each((i, el) => cb($(el), i));
	};

	$.fn.wpjam_on = function(name, selector, callback, options){
		let cb	= !_.isFunction(callback) && $.fn[callback] ? (e, ...args) => $(e.currentTarget)[callback](e, ...args) : callback;

		let {type, wait=300, ...data}	= options || {};

		if(type == 'throttle'){
			cb	= _.throttle(cb, wait);
		}else if(type == 'debounce' || ['submit', 'change', 'click'].includes(name)){
			cb	= _.debounce(cb, wait, true);
		}

		return this.on(name+'.wpjam', (!selector || selector === 'body') ? null : selector, data, cb);
	};

	$.fn.wpjam_init = function(){
		let filter	= this.is('body') ? ':not(form *)' : '';
		let rules	= $.fn.wpjam_init.rules = $.fn.wpjam_init.rules || [
			[null, '[data-indeterminate]',	$el => $el.prop('indeterminate', true).removeAttr('data-indeterminate')]
		];

		filter && _.each(Object.keys($.fn), handle => {
			if(handle.startsWith('wpjam_') && _.every(rules, (r) => r[0] !== handle)){
				let {selector, events}	= $.fn[handle];

				rules.push([handle, selector]);

				_.each(events, event => $('body').wpjam_on(event.name, event.selector || selector, handle, event));
			}
		});

		_.each(rules, ([handle, sel, cb])=> sel && this.find(sel.split(',').map(s => s.trim()+filter).join(',')).wpjam_each($el => cb ? cb($el) : $el[handle]()));

		filter && this.find('form').wpjam_each($el => $el.data('initialized') || $el.data('initialized', true).wpjam_init());

		return this;
	};

	$.fn.wpjam_highlight = function(type){
		if(type){
			this.attr('data-highlight', type);

			return type == 'delete' ? this : this.hide().fadeIn(1000);
		}

		return this.removeAttr('data-highlight');
	};

	$.fn.wpjam_remove = function(cb){
		return this.fadeOut(400, ()=> this.remove() && cb && cb());
	};

	$.fn.wpjam_control = function(){
		let $field = !this.data('type') && this.is(':checkbox, :radio') ? this.closest('.wpjam-choice') : [];

		return $field[0] ? $field : this;
	};

	$.fn.wpjam_field = function(){
		let {type, key, value, button_text: btn, multiple: mu, ...data}	= this.data();

		let id		= this.attr('id');
		let name	= this.attr('name')+(mu ? '[]' : '');

		if(type == 'color'){
			let $picker	= this.attr('type', 'text').val(value).wpColorPicker().closest('.wp-picker-container');
			let show_if	= $picker.append($picker.next('.description')).find('[data-show_if]').attr('data-show_if');

			$('<label>').prependTo($picker).append(['.before', '> button', '> span', '.after'].map(s => $picker.find(s)));

			show_if	&& $picker.attr('data-show_if', show_if);
			btn		&& $picker.find('.wp-color-result-text').text(btn);
		}else if(type == 'timestamp'){
			if(value){
				let pad2	= num => (num.toString().length < 2 ? '0' : '')+num;
				let date	= new Date(+value*1000);

				this.val(date.getFullYear()+'-'+pad2(date.getMonth()+1)+'-'+pad2(date.getDate())+'T'+pad2(date.getHours())+':'+pad2(date.getMinutes()));
			}

			this.attr('type', 'datetime-local');
		}else if(type == 'toggle'){
			value && this.prop('checked', true);
		}else if(['img', 'image', 'file'].includes(type)){
			if(!this.closest('.wpjam-'+type)[0]){
				this.wrap($('<div>', {class: 'wpjam-'+type}));

				if(wpjam.upload_files){
					this.after('<a class="add-media button"><span class="dashicons dashicons-admin-media"></span>'+btn+'</a>');
				
					(type == 'img' ? this.parent() : this.next('a.button')).on('click.wpjam', (e)=> {
						$(e.target).is('.del-img') ? this.data('value', '').wpjam_field() : this.wpjam_media(value => this.data('value', value).wpjam_field());
					});
				}else{
					this.prop('disabled', true).addClass('disabled');
				}
			}

			if(type == 'img'){
				this.prevAll('img, a.del-img')[value ? 'remove' : 'wpjam_remove']();
				this.val(value ? value.value : '').before(value ? [
					$('<img>', {src: value.url+data.thumb_args}),
					this.prop('disabled') ? '' : $('<a>', {class: 'del-img dashicons dashicons-no-alt'})
				] : '');
			}else{
				this.val(value).next('a.button');
			}
		}else if(type == 'uploader'){
			let $wrap	= this.add(this.prev('.before')).add(this.next('.after')).wrapAll('<div class="wpjam-uploader">').parent().addClass(this.attr('disabled'));

			this.before($('<label class="button">'+btn+'</label>').append($('<input type="file">').attr({accept: data.params.accept}).hide().on('change', e => {
				e.target.files[0] && this.wpjam_upload(e.target.files[0]);
				$(e.target).val('');
			})));

			data.drag_drop && $wrap.addClass('drag-drop').append([
				'<p class="drag-drop-info">'+wp.i18n.__('Drop files to upload', 'default')+'</p>',
				$('<p class="drag-drop-buttons"></p>').append(this)
			]).on('dragover', e => {
				e.preventDefault();
				$wrap.addClass('drag-over');
			}).on('dragleave drop', e => {
				$wrap.removeClass('drag-over');
			}).on('drop', e=> {
				e.preventDefault();
				this.wpjam_upload(e.originalEvent.dataTransfer.files[0]);
			});

			this.wpjam_label();
		}else if(type == 'textarea'){
			this.addClass('expandable');
		}else if(type == 'editor'){
			if(data.editor){
				if(wp.editor){
					wp.editor.remove(id);
					wp.editor.initialize(id, {...data.editor, mediaButtons: wpjam.upload_files});

					this.attr({rows: 10, cols: 40});
				}else{
					console.log('请在页面加载 add_action(\'admin_footer\', \'wp_enqueue_editor\');');
				}
			}
		}else if(type == 'cascade'){
			let keys	= ['data-data_type', 'data-query_args', 'data-filter_key'];
			let attr	= _.object(keys, _.map(keys, k => this.attr(k)));

			this.removeAttr(keys.join(' '));

			_.each(data.options, (options, i)=> {
				let $select	= $('<select>', {id: id+'_'+i, name: name+'[]'}).addClass('field-key-'+key+'_'+i).appendTo(this).wpjam_options(options);

				if(options.length){
					value[i] && $select.val(value[i]);
				}else{
					i > 0 && $select.addClass('hidden');
				}

				i > 0 && $select.attr(attr).wpjam_show_if(key+'_'+(i-1), '!=', '');
			});
		}else if(['select', 'radio', 'checkbox'].includes(type)){
			let options	= data.options || [];

			if(data.custom){
				let {title, by, key: ck, ...attr}	= data.custom;

				let keys	= options.flatMap(o => o.options ? o.options.map(c => String(c.value)) : [String(o.value)]);
				let diff	= [].concat(value || []).filter(v => !keys.includes(String(v)));
				let $input	= $('<input>').attr({...attr, name, required: 'required'}).addClass('field-key-'+ck).wpjam_show_if(key, by);

				this.wpjam_options([...options, {value: by, label: title}]);

				if(diff.length){
					$input.val(diff[0]);

					value	= mu ? [...[].concat(value).filter(v => keys.includes(String(v))), by] : by;
				}

				type === 'select' ? this.after(['&emsp;', $input]) : this.append($input);
			}else{
				this.wpjam_options(options);
			}

			if(this.is('select')){
				this.val(value != null && this.find(`option[value="${value}"]`)[0] ? value : this.find('option:first').val());
			}else{
				let $btn	= type === 'select' && $('<button>', {type: 'button', text: data.show_option_all, popovertarget: id+'_options'}).insertBefore(this.attr('popover', 'auto').wrap('<div class="mu-select"></div>'));

				this.addClass('wpjam-choice direction-'+(data.direction || ($btn || data.sep ? 'column' : 'row')));

				this.on('change.wpjam', 'input', (e)=> {
					let $el	= $(e.target);

					$el.is(':checkbox') ? $el.trigger('validate.wpjam') : this.find('label').removeClass('checked');

					$el.closest('label').toggleClass('checked', $el.is(':checked'));

					$btn && $btn.text(this.find('label.checked').toArray().map(el => $(el).text().trim()).join(', ') || this.data('show_option_all'));
				});

				this.find(':checkbox')[0] && this.attr('data-validation', true).on('validate.wpjam', (e)=> {
					let $el	= $(e.target);

					this.find(':checkbox').toArray().forEach(el => el.setCustomValidity(''));

					_.each($el.is('input') ? [$el.is(':checked') ? 'max' : 'min'] : ['min', 'max'], type => {
						let v		= this.wpjam_schema(type+'Items');
						let [c, m]	= type === 'max' ? ['>', '最多'] : ['<', '至少'];

						if(v && wpjam.compare(this.find(':checkbox:checked').length, c, v)){
							let el	= $el.is('input') ? $el[0] : this.find(':checkbox')[0];

							return el.setCustomValidity(m+`选择${v}个`), el.reportValidity(), false;
						}
					});
				});

				this.attr('id', id+'_options').find([].concat(value === null ? [] : value).map(v => `input[value="${v}"]`).join(',')).trigger("click");
			}
		}else{
			this.is('.tiny-text, .small-text') && this.addClass('expandable');
		}

		return this.removeAttr('data-value data-options data-custom');
	};

	$.fn.wpjam_field.selector	= 'input[data-type], textarea[data-type], [data-type][data-options]';

	$.fn.wpjam_options = function(options){
		let $field	= this.data('type') ? this : this.closest('[data-type]');

		let {type, value, key, sep, multiple: mu}	= $field.data();

		let name	= $field.attr('name')+(mu ? '[]' : '');
		let select	= $field.is('select') || type === 'cascade';

		if(type === 'cascade'){
			options	= [{value: '', label: $field.data('show_option_all')}, ...options];
		}

		return this.append(options.map((opt, i) => {
			let s	= select || i == options.length - 1 ? '' : sep;

			if(opt.options){
				let {label: gl, options: go, data: gd={}, ...ga}	= opt;

				return (select ? $('<optgroup>').attr('label', gl) : $('<label>').text(gl).append('<br />').after(s)).attr(ga).wpjam_data(gd).appendTo(this).wpjam_options(go);
			}

			let {label, alias, description, image, class: cls, data={}, ...attr}	= opt;
			let $el	= select ? $('<option>', attr) : $('<label>').addClass(cls);

			$el.wpjam_data(data);

			if(description){
				let $desc	= $field.next('p.description');

				($desc[0] ? $desc : $('<p>').addClass('description').insertAfter($field)).append($('<span>').html(description).wpjam_show_if(key, opt.value));
			}

			if(select){
				return $el.val(value !== null && (alias || []).includes(String(value)) ? value : opt.value).text(label);
			}

			let $input	= $('<input>').addClass('field-key-'+key).attr(attr).attr({
				name,
				type:	mu ? 'checkbox' : 'radio',
				id:		$field.attr('id')+'_'+attr.value
			});

			$el.attr('for', $input.attr('id')).append($input);

			image && $el.addClass('image-'+$input.attr('type')).append(([].concat(image)).slice(0, 2).map(src => $('<img>').attr({src, alt: label})));

			return $el.append(label).after(s);
		}));
	};

	$.fn.wpjam_upload	= function(file){
		let max_size	= this.data('max_size');
		let params		= this.data('params');

		if(max_size && file.size > max_size){
			return alert('文件大小不能超过 '+Math.round(max_size/1024/1024)+'MB');
		}

		let $progress	= $('<div class="media-item"><div class="progress"><div class="percent">0%</div><div class="bar"></div></div></div>');

		this.after($progress).prev('span').remove();

		let data	= new FormData();
		let xhr		= new XMLHttpRequest();

		xhr.upload.onprogress	= e => { 
			let p = Math.round(e.loaded/e.total*100);

			$progress.find('.bar').width(p*2);
			$progress.find('.percent').text(p+'%');
		};

		xhr.onload	= ()=> {
			let res = JSON.parse(xhr.responseText);
			
			res.errcode ? alert(res.errmsg) : this.val(res.path).wpjam_label();

			$progress.remove();
		};

		_.each(wpjam.with_page({...params, action: 'wpjam-upload', [params.name]: file}), (v, k)=> data.append(k, v));
		
		xhr.open('POST', ajaxurl);
		xhr.send(data);
	}

	$.fn.wpjam_label = function(){
		let $label	= this.prev('span.query-label');

		if($label[0]){
			$label.next('input').val('').change().end().wpjam_remove();
		}else{
			let tag		= this.hasClass('tag-input');
			let label	= this.data('label') || (this.data('type') === 'uploader' ? this.val().split('/').pop() : tag && this.val());

			label && this.before($('<span>', {class:'query-label'}).append([
				$('<span>', {class:'dashicons del-item'}).on('click', ()=> this.wpjam_label()),
				tag ? label : $('<span>', {class:'truncate-text', text: label}),
			]).addClass(!tag && this.data('class'))).removeData('label').removeAttr('data-label');
		}
	};

	$.fn.wpjam_show_if = function(...args){
		if(args.length > 1){
			return this.wpjam_data({show_if: _.object(['key', ...(args.length > 2 ? ['compare'] : []), 'value'], args)});
		}

		let show_if	= this.data('show_if');

		if(!show_if){
			return this;
		}

		if(!args.length){
			let key	= show_if.key;
			let dep	= this.closest('form').find('.field-key-'+key)[0] || $('.field-key-'+key)[0] || $('#'+key)[0];
			let $el	= dep ? $(dep).wpjam_control() : '';

			return dep ? this.addClass('dep-on-'+($el.data('dep-id') || $el.attr('data-dep-id', 'dep-'+$.guid++).data('dep-id'))) : this;
		}

		let val		= args[0];
		let show	= val === null ? false : wpjam.compare(val, show_if);

		this.add(this.nextUntil(':not(br, .after, p.description)')).add(this.prev('.before')).toggleClass('hidden', !show);

		if(this.is('option')){
			this.prop('disabled', !show).is(':selected') && !show && this.closest('select').prop('selectedIndex', 0).wpjam_depend();
		}else{
			(this.is(':input') ? this : this.find(':input')).wpjam_each($el => {
				$el.is('.disabled') || $el.prop('disabled', !show);

				if($el.is(this) || $el.parent().is(this)){
					let {filter_key: fk, query_args: qv}	= $el.data() || {};

					if(fk && qv && (!_.has(qv, fk) || (!$el.hasClass('hidden') && val !== null && qv[fk] !== val))){
						$el.data('query_args', {...qv, [fk] : val ?? ''});

						if(_.has(qv, fk)){
							if($el.is('select')){
								return $el.empty().wpjam_query().then(items => (items.length ? $el.wpjam_options(items) : $el.addClass('hidden')).wpjam_depend());
							}

							$el.is('input') && $el.val('').wpjam_label();
						}
					}
				}

				return $el.wpjam_depend();
			});
		}

		return this;
	};

	$.fn.wpjam_show_if.selector	= '[data-show_if]';

	$.fn.wpjam_data_type = function(){
		let $mu		= this.closest('.mu-text');
		let $hidden	= !$mu[0] && this.data('filterable') && $('<input>',{type: 'hidden', name: this.attr('name'), value: this.val()}).insertAfter(this.removeAttr('name'));

		this.data('label') && ($hidden ? this.val(this.data('label')) : this.wpjam_label());

		return this.autocomplete({
			minLength:	0,
			delay: 400,
			source: (request, response)=> {
				this.wpjam_query(request.term).then(items => response(items));
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
				}else{
					ui.item && this.val(ui.item.value).data('label', ui.item.label).wpjam_label();
				}
			},
			change: (e, ui)=> {
				this.trigger('change.wpjam');
			}
		}).on('click', (e)=> {
			this.autocomplete('search');
		}).on('keydown', (e)=> {
			!this.val() && e.key === 'Backspace' && this.autocomplete('close');
		}).on('input', (e)=>{
			$hidden && $hidden.val(this.val());
		});
	};

	$.fn.wpjam_data_type.selector	= 'input[data-data_type][data-query_args]';

	$.fn.wpjam_depend = function(){
		let $el	= this.wpjam_control();
		let val	= $el.wpjam_val();
		let id	= $el.data('dep-id');

		id && $('.dep-on-'+id).wpjam_each($el => $el.wpjam_show_if(val));
	};

	$.fn.wpjam_depend.selector	= '[data-dep-id]';
	$.fn.wpjam_depend.events	= [{name: 'change', selector: ':input[data-dep-id], [data-dep-id] :input'}];

	$.fn.wpjam_action = function(e){
		let type	= this.is('#wpjam_option') ? 'option' : (this.is('#wpjam_form, .wpjam-button') ? 'page' : 'list-table');
		let data	= this.data();
		let $form, $ae, title;

		if(this.is('form')){
			$ae		= $(document.activeElement);
			$ae		= $ae.is(':submit') ? $ae : this.find(':submit').first().focus();
			$form	= this;
			title	= $ae.val();

			if((type == 'option' && $ae.attr('name') === 'reset') ? !confirm('确定要'+title+'吗?') : !this.wpjam_validate()){
				return false;
			}

			$ae.is('body') || $ae.after(wpjam.spinner);
		}else{
			$form	= this.closest('form');
			title	= data.page_title || this.attr('title') || data.title;

			if(data.confirm && (data.action == 'delete' ? !showNotice.warn() : !confirm('确定要'+title+'吗?'))){
				return false;
			}
		}

		let indeterminate	= $form.find('input[type="checkbox"]:indeterminate').toArray().map(cb => `${encodeURIComponent(cb.name)}=${encodeURIComponent(cb.value)}`).join('&');

		return wpjam.action(type, {
			...(type === 'page' && {page_action: data.action}),
			...(type === 'list-table' && {list_action: data.action, ..._.pick(data, ['bulk', 'id', 'ids'])}),
			...($ae ? {
				action_type:	'submit',
				submit_name:	$ae.attr('name'),
				data:			$form.serialize(),
				defaults:		data.data || {},
				$ae
			} : {
				action_type:	data.direct ? 'direct' : 'form',
				data:			data.data || {},
				form_data:		$.param(wpjam.parse_params($form.serialize(), true))
			}),
			page_title:		title,
			_ajax_nonce:	data.nonce,
			indeterminate
		}) && false;
	}

	$.fn.wpjam_action.events	= [
		{name: 'click', selector: '.wpjam-button, .list-table-action'},
		{name: 'submit', selector: '#wpjam_form, #wpjam_option, #list_table_action_form'},
	];

	$.fn.wpjam_query = function(term){
		let {data_type, query_args}	= this.data();

		if(term){
			query_args[(data_type == 'post_type' ? 's' : 'search')]	= term;
		}

		let $mu	= this.closest('.mu-text');

		if($mu.wpjam_schema('uniqueItems')){
			query_args.exclude	= $mu.wpjam_val();
		}

		return wpjam.post({action: 'wpjam-query', data_type, query_args}).then(data => data.errcode ? (data.errmsg && alert(data.errmsg), Promise.reject(data)) : data.items);
	}

	$.fn.wpjam_mu = function(e, ...args){
		let is_e	= e ? _.isObject(e) : false;
		let action	= is_e ? (e.data.action || e.type) : e;
		let $mu		= this.closest('.mu');
		let type	= $mu.data('type').substring(3);

		if(action == 'new_item'){
			if($mu.wpjam_mu('rest', true) <= 0){
				is_e && e.type === 'autocompleteselect' && (args[0].item = '');
				return false;
			}

			if(['img', 'image', 'file'].includes(type)){
				$mu.wpjam_media(value => $mu.wpjam_mu('add_item', value));
			}else{
				let $items	= $mu.children();

				if($items.length >= 2 && !$items.eq(-2).wpjam_mu('validate')){
					return false;
				}

				$mu.wpjam_mu('add_item');
			}

			if($mu.wpjam_schema('uniqueItems')){
				let value	= $mu.wpjam_val();

				if(value && _.uniq(value).length !== value.length){
					alert('不允许重复');
				}
			}

			return is_e ? false : true;
		}else if(action == 'add_item'){
			let $tmpl	= $mu.children().last();
			let $new	= $tmpl.clone().find('.new-item, span.query-label').remove().end().insertBefore($tmpl);

			let {value, label, url}	= _.isObject(args[0]) ? args[0] : {value: args[0]};

			if(['img', 'image', 'file'].includes(type)){
				type == 'img' && url && $new.prepend($('<img>', {src: url+$new.find('input').data('thumb_args'), 'data-preview':url}));

				$new.find('input').val(value);
			}else if(type == 'text'){
				if(value){
					$new.find(':input').val(value).data(label ? {label} : {}).wpjam_label();
				}else{
					$tmpl.find(':input').is(':visible') && $new.insertAfter($tmpl).find(':input').val('').after($tmpl.find('.new-item'));
				}
			}else if(type == 'fields'){
				$mu.data('i', ($mu.data('i') || $mu.children().length-2) + 1);
				$new.find('template').replaceWith((i, html) => html.replace(/\$\{i\}/g, $mu.data('i')));

				$new.prevAll().wpjam_each($el => $el.wpjam_mu('tag_label'));
			}

			_.isUndefined(value) && $new.wpjam_mu('focus');

			$mu.wpjam_mu('rest');

			return $new.wpjam_init();
		}else if(action == 'del_item'){
			return this.closest('.mu-item').wpjam_remove(()=> $mu.wpjam_mu('rest')), false;
		}else if(action == 'rest'){
			let max		= $mu.wpjam_schema('maxItems');
			let rest	= max ? (max - ($mu.children().length - (['img', 'fields', 'text'].includes(type) ? 1 : 0))) : 10000;

			max && $mu.attr('data-rest', rest);
			max && args[0] && rest <= 0 && alert('最多支持'+max+'个');

			return rest;
		}else if(action == 'focus'){
			return this.find(':input:visible').first().focus().select();
		}else if(action == 'validate'){
			return this.find(':input').toArray().every(el => el.checkValidity() || (el.reportValidity(), false));
		}else if(action == 'keydown'){
			let $item	= this.closest('.mu-item');

			if(type == 'text'){
				if(this.hasClass('tag-input')){
					let del		= e.key === 'Backspace' && !this.val();
					let timer	= $mu.wpjam_timer(!del);

					del && (timer ? $item.prev().wpjam_mu('del_item') : $item.prev().fadeOut(300).fadeIn(200));
				}

				if(e.key === 'Enter'){
					if(this.val() && !this.data('data_type')){
						if($mu.wpjam_mu('new_item')){
							this.wpjam_label();

							$(document.activeElement).closest('.mu-item').insertAfter($item).wpjam_mu('focus');
						}else{
							this.hasClass('tag-input') && this.val('');
						}
					}

					return false;
				}
			}else{
				if(e.key === 'Enter'){
					let $inputs = $item.find(':input:visible');
					let $next	= $inputs.eq($inputs.index(this)+1);

					if($next[0]){
						$next.focus().select();
					}else if($mu.data('tag_label')){
						if($item.is($mu.children().eq(-2))){
							$mu.wpjam_mu('new_item');
						}else{
							$item.wpjam_mu('validate') && $item.wpjam_mu('tag_label');
						}
					}

					return false;
				}
			}
		}else if(action == 'tag_label'){
			let label	= $mu.data('tag_label');

			if(label && !this.has('template, span.tag-label')[0]){
				let prefix	= this.find(':input').first().attr('name');

				prefix	= prefix.endsWith('[]') ? prefix.slice(0, -2) : prefix;
				prefix	= prefix.substring(0, prefix.lastIndexOf('['));
				label	= label.replace(/\${(.*?)}/g, (match, name) => {
					let $field	= this.find('[name="'+prefix+'['+name+']"]');

					return $field.is('select') ? $field.find('option:selected').text().trim() : $field.val();
				});

				$('<span class="tag-label">'+label+'</span>').append(this.find('.del-item').clone()).prependTo(this).on('dblclick', (e)=>$(e.target).remove());
			}
		}

		if(e){
			return;
		}

		if(type == 'fields'){
			$mu.children().addClass('mu-item');
		}else{
			$mu.wrapInner('<div class="mu-item"></div>');

			_.each($mu.data('value'), v => $mu.wpjam_mu('add_item', v));
		}

		let sortable	= $mu.removeAttr('data-value').is('.sortable') && !$mu.closest('.disabled, .readonly')[0];

		if(type == 'text' && $mu.find('input.tag-input')[0]){
			$mu.attr('data-validation', true).on('validate.wpjam', ()=> $mu.find('input:visible').val(''));
		}else{
			let dir	= $mu.data('direction') || (type == 'img' || $mu.data('tag_label') ? 'row' : 'column');
			let row	= dir == 'row';

			$mu.addClass('direction-'+dir).find('> .mu-item').wpjam_each($el => $el.append([
				$el.is(':last-child') && '<a class="new-item button">'+(type == 'img' ? '' : $mu.data('button_text'))+'</a>',
				'<a class="del-item '+(row ? 'dashicons dashicons-no-alt' : 'button')+'"></a>',
				sortable && type != 'img' && '<span class="move-item dashicons dashicons-menu"></span>',
			]));

			['text', 'fields'].includes(type) && row && $mu.wpjam_mu('rest') > 0 && $mu.wpjam_mu('add_item', '');
		}

		sortable && $mu.sortable({cursor: 'move', items: '.mu-item:not(:last-child)'});

		return this;
	};

	$.fn.wpjam_mu.selector	= '.mu';
	$.fn.wpjam_mu.events	= [
		{name: 'autocompleteselect',	selector: '.mu-text input',	action: 'new_item'},
		{name: 'click',		selector: '.mu .new-item',	action: 'new_item'},
		{name: 'click',		selector: '.mu .del-item',	action: 'del_item'},
		{name: 'keydown',	selector: '.mu-text input, .mu-fields input, .mu-fields select'}
	];

	$.fn.wpjam_validate = function(){
		this[0].checkValidity() && this.find('[data-validation]').trigger('validate.wpjam');

		if(!this[0].checkValidity()){
			let $field	= $(this.find('input:invalid')[0] || this.find(':invalid')[0]);
			let custom	= $field.data('custom_validity');

			custom && $field.one('input', ()=> $field[0].setCustomValidity(''))[0].setCustomValidity(custom);

			if(!$field.is(':visible')){
				$field.closest('.tabs').wpjam_tabs('#'+$field.closest('.tab').attr('id'));
				$field.closest('[popover]')[0]?.showPopover();
			}

			return this[0].reportValidity();
		}

		return true;
	};

	$.fn.wpjam_schema = function(key){
		return (this.data('schema') || {})[key];
	};

	$.fn.wpjam_data = function(data){
		_.each(data, (v, k)=> this.attr('data-'+k, _.isObject(v) ? JSON.stringify(v) : v));

		return this;
	};

	$.fn.wpjam_val = function(){
		if(this.prop('disabled')){
			return null;
		}else if(this.is('span')){
			return this.data('val');
		}else if(this.is('.mu-text')){
			return this.find('input').toArray().map(el=> el.value).filter(v => v !== '');
		}else if(this.is('.wpjam-choice')){
			let val	= this.find('input:checked').toArray().map(el => el.value);

			return this.find('input').is(':radio') ? (val.length ? val[0] : null) : val;
		}else if(this.is(':checkbox, :radio')){
			let $field = this.wpjam_control();

			return $field[0] !== this[0] ? $field.wpjam_val() : (this.is(':checked') ? this.val() : (this.is(':checkbox') ? 0 : null));
		}

		return this.val();
	};

	$.fn.wpjam_timer = function(cancel){
		let timer	= this.data('timer');

		if(cancel){
			timer && timer.cancel();

			return this.removeData('timer');
		}

		if(timer){
			return timer;
		}

		this.data('timer', _.debounce(()=> this.wpjam_timer(true), 2000)).data('timer')();
	};

	$.fn.wpjam_media = function(callback){
		let {type, item_type, rest, button_text: btn}	= this.data();

		let mu	= this.is('.mu');
		type	= type.substring(mu ? 3 : 0);

		if(this.hasClass('readonly')){
			return;
		}

		let args	= {
			...(wp.media.view.settings.post.id && {frame: 'post'}),
			multiple: mu,
			id		: 'uploader_'+this.prop('id'),
			title	: btn,
			library	: {type : ['img', 'image'].includes(type) ? 'image' : item_type},
			// button	: {text: title}
		};

		let frame	= wp.media(args);

		frame.on('open', function(){
			frame.$el.addClass('hide-menu');

			mu && rest && frame.state().get('selection').on('update', function(){
				if(this.length > rest){
					this.reset(this.first(rest));

					alert('最多可以选择'+rest+'个');
				}
			});
		}).on((args.frame == 'post' ? 'insert' : 'select'), function(){
			frame.state().get('selection').map((attachment)=> {
				let data	= attachment.toJSON();
				let qs		= $.param(_.pick(data, 'orientation', 'width', 'height'));
				let value	= data.url+(qs ? '?'+qs : '');

				callback && callback(type == 'img' ? {...data, value : item_type === 'url' ? value : data.id} : value);
			});
		}).open();
	};

	$.fn.wpjam_lightbox = function(e){
		$('dialog.wpjam-lightbox').remove();

		let src		= this.data('preview') || this.attr('href');
		let rel		= this.attr('rel');
		let $btn	= $('<button type="button" class="dashicons dashicons-no-alt del-icon"></button>');
		let $modal	= $('<dialog class="wpjam-lightbox"></dialog>').append([$btn.one('click', ()=> $modal.remove()), $('<img src="'+src+'">')]).appendTo($('body'));

		$modal[0].showModal();

		let images	= rel ? $(`[rel="${rel}"]`).toArray().map(el => $(el).data('preview') || el.href) : [];
		let length	= images.length;

		if(length > 1){
			let index	= images.indexOf(src);

			$modal.wrapInner('<div>').prepend('<a rel="'+rel+'" data-preview="'+images.at(index - 1)+'" class="dashicons dashicons-arrow-left-alt2"></a>').append('<a rel="'+rel+'" data-preview="'+images.at((index + 1) % length)+'" class="dashicons dashicons-arrow-right-alt2"></a>')
		}

		return e ? false : this;
	}

	$.fn.wpjam_lightbox.events	= [{name: 'click',	selector: '[data-preview], a.lightbox'}];

	$.fn.wpjam_tooltip = function(e){
		let action	= e?.type;
		let $tip	= $('div.wpjam-tooltip');

		if(action === 'mouseenter'){
			($tip[0] ? $tip : $('<div popover="manual" class="wpjam-tooltip"></div>')).stop(true).fadeIn(100).html(this.is('.preview') ? '<img src="'+this.find('img:visible').attr('src')+'" />' : (this.data('description') || this.data('tooltip'))).appendTo(this)[0].showPopover();
		}else if(action === 'mouseleave'){
			$tip.fadeOut(300);
		}else{
			this.is('[data-description]') && this.addClass('dashicons dashicons-editor-help');
		}

		return this;
	};

	$.fn.wpjam_tooltip.selector	= '[data-tooltip], [data-description], .image-radio.preview';
	$.fn.wpjam_tooltip.events	= [{name: 'mouseenter'}, {name: 'mouseleave'}];

	$.fn.wpjam_modal = function(e){
		if(this.hasClass('notice-dismiss')){
			return this.prev('.delete-notice').trigger('click');
		}

		let $modal	= e ? $('#'+this.data('modal_id')) : this;
		let $dialog	= wpjam.dialog($modal.html(), $modal.data('title'), $modal.data('width'));

		e || $dialog.one('wpjam:dialog:closed', ()=> this.find('.delete-notice').trigger('click').end().remove());

		return e ? false : this;
	}

	$.fn.wpjam_modal.selector	= '.notice-modal:first';
	$.fn.wpjam_modal.events		= [{name: 'click',	selector: '.show-modal, .is-dismissible .notice-dismiss'}];

	$.fn.wpjam_chart = function(){
		let {type, options}	= this.data();

		if(['Line', 'Bar', 'Donut'].includes(type)){
			type == 'Donut' && this.height(Math.max(160, Math.min(240, this.next('table').height() || 240))).width(this.height());

			Morris[type]({...options, element: this.prop('id')});
		}
	}

	$.fn.wpjam_chart.selector	= '[data-chart]';

	$.fn.wpjam_tabs = function(hash){
		hash	= window.location.hash = hash || window.location.hash;

		this.find('.tab').hide().filter(hash || ':first').show();
		this.find('.nav-tab').removeClass('nav-tab-active').filter(hash ? '[href="'+hash+'"]' : ':first').addClass('nav-tab-active');
	};

	$.fn.wpjam_place = function(content, replace, placement){
		let $content	= $(content);

		this[{before: 'prevAll', after: 'nextAll'}[placement] || 'find'](replace).remove();
		this[placement || 'append']($content);

		return $content;
	};

	$.fn.wpjam_scroll = function(){
		let $modal	= this.closest('dialog .content');

		if($modal[0]){
			$modal.animate({scrollTop: this[0].offsetTop - 80}, 300);
		}else{
			let top	= this.offset().top;
			let dis	= $(window).height() * 0.4;

			(Math.abs(top - $(window).scrollTop()) > dis) && $('html, body').animate({scrollTop: top - dis}, 300);
		}

		return this;
	};

	window.wpjam = {
		...wpjam_page_setting,
		spinner: '<span class="spinner is-active"></span>',

		init: function(){
			$.ajax	= this.enhance($.ajax, (options) => {
				let type	= typeof options.data;
				let data	= type == 'string' ? this.parse_params(options.data) : (type == 'object' ? options.data : {});

				if(data.action){
					if(data.action.startsWith('wpjam-')){
						data	= this.with_page(data);
					}else if(['fetch-list', 'inline-save-tax', 'get-comments', 'replyto-comment'].includes(data.action)){
						data.screen_id	= this.screen_id;
						options.data	= type == 'string' ? $.param(data) : data;
					}
				}
			}, 'before');

			this.params	= this.parse_params(window.location.search, true);

			this.page_title_action	&& $('.wp-heading-inline').last().wpjam_place(this.page_title_action, 'a.page-title-action', 'after');

			$('a[href*="/wp-admin/page="]').attr('href', (_, v)=> v.replace('/wp-admin/page=', 'admin/admin.php?page='));

			_.each(this.query_url, pair => $('a[href="'+pair[0]+'"]').attr('href', pair[1]));

			$('body').on('click', 'input[type=submit]', e => $(document.activeElement).attr('id') || $(e.target).focus());

			$(window).on('popstate', e => e.originalEvent.state?.params && this.load(e.originalEvent.state.params));
			$(window).on('hashchange load', ()=> $('.tabs').wpjam_each($el => $el.wpjam_tabs()));

			$(document).on('heartbeat-send', (e, data)=> this.query_data && $.extend(data, this.query_data));
			$(document).on('widget-updated', (e, widget)=> widget.wpjam_init());
			$(document).on('mousemove', (e)=> $('html').css({
				'--wpjam-left':	(e.clientX+(e.clientX + 300 > window.innerWidth ? -305 : 5))+'px',
				'--wpjam-top':	(e.clientY+(e.clientY + 220 > window.innerHeight ? -225 : 5))+'px'
			}));

			this.load();

			$('body').wpjam_init();
		},

		load: function(params){
			if(params){
				this.dialog('close');
				this.params	= params;
			}

			let args	= {...this.params, action_type: 'form'};

			if(list_table){
				if(!params){
					list_table.load();
				}

				if(args.list_action){
					return list_table.action(args);
				}

				if(params){
					return list_table.query(params);
				}
			}

			if(args.page_action){
				return this.action('page', args);
			}

			this.plugin_page && this.state('replace');
		},

		state: function(action='push'){
			let url	= new URL(this.admin_url);

			if(!_.isEmpty(this.params) || this.query_data){
				url.search	= '?'+$.param(_.extend(
					this.parse_params(url.search),
					this.query_data,
					_.omit(this.params, (v, k)=> v == null || (k == 'paged' && v <= 1))
				));
			}

			url	= url.toString()+(window.location.hash || '');

			$('input[name="_wp_http_referer"]').val(url);

			if(action != 'push' || window.location.href != url){
				window.history[(action == 'push' ? 'pushState' : 'replaceState')]({params: this.params}, null, url);
			}
		},

		notice: function(notice, type){
			if(_.isObject(notice)){
				type	= notice.type;
				notice	= notice.message;
			}

			if(notice){
				let $dialog		= this.dialog();
				let [$el, act]	= $dialog[0] ? [$dialog.find('.content'), 'prepend'] : [$('.wp-header-end').last(), 'before'];

				$el.wpjam_place('<div class="notice notice-'+(type || 'success')+' is-replaceable is-dismissible"><p><strong>'+notice+'</strong></p></div>', '.notice.is-replaceable', act).wpjam_scroll();

				$(document).trigger('wp-notice-added');
			}
		},

		dialog: function(...args){
			let id	= 'wpjam_dialog';

			if(!args.length){
				return $('#'+id+'[open]');
			}

			let $dialog	= $('#'+id);

			if(args[0] === 'close'){
				return $dialog[0]?.close();
			}

			if(args[0] === 'show'){
				if($dialog[0] && !$dialog[0].open){
					$dialog[0].returnValue = '';
					$dialog[0].showModal();
				}

				return;
			}

			let [data, ...rest]	= args;

			data	= _.isObject(data) ? data : {..._.object(['page_title', 'width'], rest), data};

			if(!$dialog[0]){
				$dialog	= $('<dialog id="'+id+'" class="wpjam-dialog"><div class="title"><h2></h2><button type="button" commandfor="'+id+'" command="close"></button></div><div class="content"></div></dialog>').appendTo('body');

				this.dialog.observer	= new MutationObserver(()=> $('body').hasClass('modal-open') ? $dialog[0].close('temp') : this.dialog('show'));

				this.dialog.observe	= ()=> this.dialog.observer.observe(document.body, {attributes: true, attributeFilter: ['class']});

				$dialog.on('close', ()=> {
					if($dialog[0].returnValue != 'temp'){
						this.dialog.observer.disconnect();

						$dialog.trigger('wpjam:dialog:closed');
					}
				}).on('click', 'button[command="close"]', ()=> $dialog[0].close());
			}

			$dialog.css('width', (data.width || 700)+'px').find('h2').text(data.page_title || '').end().find('.content').html(data.form || data.data || '');

			$dialog[0].showModal();
			this.dialog.observe();

			$dialog.trigger('wpjam:dialog:opened');

			return $dialog;
		},

		post: function(args){
			return $.ajax({
				url:		ajaxurl,
				method:		'POST',
				data:		args,
				dataType:	'json',
				headers:	{'Accept': 'application/json'},
				error:		function(xhr, status, error){}
			});
		},

		action:  Object.assign(function(type, args){
			let action	= this.action[type];

			if(action?.before?.(args) === false){
				return false;
			}

			if(args.state){
				_.extend(this.params, _.pick(args, args.state));

				$('body').one('wpjam:dialog:closed', ()=> {
					this.params	= _.omit(this.params, args.state);
					this.state();
				});
			}

			args.$ae && args.$ae.prop('disabled', true);

			$('.spinner.is-active')[0] || $('<div class="wpjam-loading"><img src="'+this.loading+'" /></div>').appendTo('body').show();

			return this.post({..._.omit(args, ['$ae', 'state']), action: 'wpjam-'+type+'-action'}).then(data => {
				args.$ae && args.$ae.prop('disabled', false);

				$('.spinner.is-active, .wpjam-loading').remove();

				if(data.errcode){
					this.notice((args.page_title ? args.page_title+'失败：' : '')+(data.errmsg || ''), 'error');
				}else{
					data.params && _.extend(this.params, data.params);

					let $dialog	= this.dialog();
					let dismiss	= $dialog[0] && data.dismiss;

					if(data.type == 'form'){
						!data.form && !data.data && alert('服务端未返回表单数据');

						this.dialog(data);
					}else if(data.type == 'append'){
						if(!$dialog[0] && data.list_action){
							this.dialog(data);
						}else{
							($dialog[0] ? $dialog.find('.content') : $('div.wrap')).wpjam_place($('<div class="card wrap-text">'+data.data+'</div>'), '.response.card').hide().fadeIn(400).wpjam_scroll();
						}
					}else if(data.type == 'redirect'){
						$('body').one(dismiss ? 'wpjam:dialog:closed' : type+'_action_success', ()=> data.url ? window.open(data.url.replace('admin/page=', 'admin/admin.php?page='), data.target) : window.location.reload());
					}

					action?.response?.(data, args);

					dismiss && $dialog[0].close();

					this.state();

					$('body').wpjam_init().trigger(type+'_action_success', data);
				}
			});
		}, {
			page: {
				before: function(args){
					if(args.action_type == 'form'){
						args.state	= ['page_action', 'data'];
					}
				},

				response: function(data, args){
					if(!['form', 'append', 'redirect'].includes(data.type)){
						data.args && setTimeout(()=> wpjam.action('page', {...args, data: data.args}), 400);

						args.action_type == 'submit' && data.form && $('#wpjam_form').html(data.form);

						wpjam.notice(data.notice || args.page_title+'成功');
					}

					data.page_action	= args.page_action;
					data.action_type	= data.page_action_type	= args.action_type;
				}
			},

			option: {
				response: function(data){
					data.type == 'save' && wpjam.notice(data.notice);
				}
			}
		}),

		with_page: function(args){
			let left_key	= args.action_type != 'query_items' && this.left_key;

			if(this.query_data || left_key){
				let type	= args.data ? typeof args.data : 'string';
				let data	= type == 'object' ? args.data : (args.data ? this.parse_params(args.data) : {});

				_.each(this.query_data, (v, k)=> {
					if(_.has(data, k)){
						this.query_data[k]	= data[k];
					}else{
						data[k]	= v;
					}
				});

				if(left_key){
					data[left_key]	= wpjam.params[left_key];
				}

				if(type == 'string'){
					args.data	= $.param(data);
				}
			}

			return _.extend(args, _.pick(this, ['screen_id', 'plugin_page', 'current_tab', 'builtin_page', 'post_type', 'taxonomy']));
		},

		parse_params: function(params, clean){
			if(_.isString(params)){
				let obj	= {};
				params	= params ? params.replace(/^\?|\+/g, ' ').trim() : '';

				params && params.replace(/^\?|\+/g, ' ').trim().split('&').forEach(v => {
					let	param	= v.split('=');
					let key		= decodeURIComponent(param[0]);
					let val		= param.length === 2 ? decodeURIComponent(param[1]) : '';
					let keys	= key.split('][');

					if(keys[0].includes('[') && _.last(keys).endsWith(']')){
						keys	= keys.shift().split('[').concat(keys);
						keys	= [...keys.slice(0, -1), keys.pop().slice(0, -1)];

						keys.reduce((cur, k, i)=> {
							k		= (k === '') ? cur.length : k;
							cur[k] = keys.length - 1 > i ? (cur[k] || (isNaN(Number(keys[i + 1])) ? {} : [])) : val;

							return cur[k];
						}, obj);
					}else{
						obj[key]	= val;
					}
				});

				params	= obj;
			}

			return clean ? _.omit(params, _.union(
				['_wp_http_referer', '_wpnonce', 'action', 'action2'],
				this.query_data ? Object.keys(this.query_data) : [],
				this.builtin_page ? ['post_type', 'taxonomy'] : ['page', 'tab']
			)) : params;
		},

		compare: function(a, compare, b){
			let cmp	= compare;

			if(_.isObject(compare)){
				b	= compare.value;
				cmp	= compare.compare;
			}

			if(_.isArray(a) || (_.isObject(compare) && compare.swap)){
				[a, b]	= [b, a];
			}

			if(cmp){
				cmp	= cmp.toUpperCase();

				let antonym	= {
					'!=': '=',
					'<=': '>',
					'>=': '<',
					'NOT IN': 'IN',
					'NOT BETWEEN': 'BETWEEN'
				}[cmp];

				if(antonym){
					return !wpjam.compare(a, antonym, b);
				}
			}else{
				cmp	= _.isArray(b) ? 'IN' : '=';
			}

			if(cmp === 'IN' || cmp === 'BETWEEN'){
				b	= _.isArray(b) ? b : b.split(/[\s,]+/);

				if(!_.isArray(a) && b.length === 1) {
					return a == b[0];
				}

				b	= b.map(String);
			}else{
				b	= _.isString(b) ? b.trim() : b;
			}

			switch (cmp) {
				case '=': return a == b;
				case '>': return a > b;
				case '<': return a < b;
				case 'IN': return b.includes(a);
				case 'BETWEEN': return a >= b[0] && a <= b[1];
				default: return false;
			}
		},

		enhance: function(func, callback, when){
			return function(...args){
				when == 'before' && callback.apply(this, args);

				let result	= func.call(this, ...args);

				when != 'before' && callback.apply(this, args);

				return result;
			};
		},

		delegate: function(selector, sub){
			let $sel	= $(selector);

			_.each($._data($sel.get(0), 'events'), (list, type)=> {
				_.each(list, (event)=> {
					let sel	= event?.selector;

					if(event?.handler && (!sel || !sub || sel == sub)){
						$('body').on(type, sel ? selector+' '+sel : selector, event.handler);
						$sel.off(type, sel || event.handler, sel && event.handler);
					}
				});
			});
		}
	};

	window.list_table = wpjam.list_table && {
		...wpjam.list_table,
		$form: $('form:has(.wp-list-table)').attr('novalidate', 'novalidate'),

		load: function(data){
			data && data.setting && _.extend(list_table, data.setting);

			this.table(data);
			this.views(data);
			this.left(data);

			$('input[name="post_status"]').val(wpjam.params.post_status || 'all');

			let $end	= $('.wp-header-end').last();

			this.subtitle	&& $end.wpjam_place('<span class="subtitle">'+this.subtitle+'</span>', 'span.subtitle', 'before');
			this.summary	&& $end.wpjam_place('<div class="summary">'+this.summary+'</div>', 'div.summary', 'after');

			if(this.search){
				if(this.search.box){
					$('#list_table_form').wpjam_place(this.search.box, 'p.search-box', 'prepend');
				}else{
					$('p.search-box input[type="search"]').val(this.search.term);
				}

				this.search.columns && $('p.search-box').wpjam_place(this.search.columns, '#search_columns', 'prepend');
			}

			if(wpjam.params.id && !wpjam.params.list_action && !wpjam.params.action){
				let id	= wpjam.params.id;

				delete wpjam.params.id;

				this.get_row(id)[0] ? this.update_row(id) : this.query({id: id});
			}

			data ? $('body').trigger('list_table_load', data) : $(window).on('load', ()=> $('body').trigger('list_table_load', data));
		},

		table: function(data){
			let $form	= this.$form.attr('data-layout', this.layout).addClass('layout-'+this.layout);

			if(!data){
				_.each([
					['submit'],
					['keydown',	'.tablenav :input, .search-box :input'],
					['change',	'.tablenav [type="date"]'],
					['click',	'.list-table-filter, td a, th a, .pagination-links a, .prev-day, .next-day']
				], ([name, sel])=> $form.wpjam_on(name, sel, e => this.callback(e)));

				wpjam.action['list-table']	= this;
			}

			if(data && (data.table || data.tablenav)){
				$form.find('input[name="_wpnonce"], input[name="_wp_http_referer"]').remove();

				if(data.table){
					$form.wpjam_place(data.table, 'table, div.tablenav');
				}else{
					_.each(['top', 'bottom'], key => $form.find('div.tablenav.'+key).replaceWith(data.tablenav[key]));
				}
			}

			if(!data || data.table){
				let $table	= $form.find('table.wp-list-table');
				let columns	= {};
				this.$tbody	= this.$form.find('#the-list');
				this.name	= (this.$tbody.data('wp-lists') || ':post').split(':')[1];

				$table.find('thead th i').wpjam_each($i => {
					let $th		= $i.closest('th');
					let col		= 'column-'+$th.attr('id');
					let data	= $i.appendTo($th).data();

					data.nowrap && $('.'+col).addClass('nowrap');

					columns[col]	= {...data, left: $th.prevAll().toArray().reduce((sum, el) => sum += $(el).outerWidth(), 0)};
				});

				sticky	= _.some(columns, data => data.sticky);

				sticky && ($table.width() > $form.width()) && $table.addClass('sticky-columns');

				this.columns	= {...columns, 'check-column' : {check:true, sticky, left: 0}};

				this.render($table);
			}

			if(!data || data.table || data.tablenav){
				$form.removeData('initialized').wpjam_place($('<div class="actions overallactions"></div>').append(this.overall_actions), '.overallactions').insertBefore($('.tablenav.top').find('div.tablenav-pages, br.clear').first());

				$form.find('.current-page').addClass('expandable').removeAttr('size').attr({type: 'number', min: 1, max: parseInt($form.find('span.total-pages').first().text())});
			}
		},

		views: function(data){
			let $views	= $('ul.subsubsub');

			if(data){
				if(!data.views){
					return;
				}

				$views.empty().append($(data.views).html());

				data.type != 'list' && $views.find('a').removeClass('current').end().find('li.'+this.view+' a').addClass('current');
			}else{
				$views.wpjam_on('click', 'a', e => this.callback(e));
			}

			this.view	= $views.find('li:has(a.current)').attr('class');
		},

		left: function(data){
			let $left	= $('#col-left form').prop('novalidate', true);
			let $paged	= $left.find('input.current-page');

			if(!$left[0]){
				return;
			}

			if(data && data.currentTarget){
				let $el	= $(data.currentTarget);

				if($el.is('[data-id]')){
					$left.find('.left-current').removeClass('left-current');

					this.query({...wpjam.params, [wpjam.left_key]: $el.addClass('left-current').data('id')});
				}else{
					$paged.val($el.is('select.left-filter') ? 1 : parseInt($paged.val())+($el.is('.prev-page') ? -1 : ($el.is('.next-page') ? +1 : 0)));

					if(!$left.wpjam_validate()){
						return false;
					}

					this.query(wpjam.parse_params($left.serialize(), true));
				}

				return false;
			}

			if(data){
				if(!data.left){
					return;
				}

				$left.removeData('initialized').html(data.left);
			}else{
				_.each([
					['submit'],
					['change',	'select'],
					['click',	'.prev-page, .next-page, [data-id]'],
				], ([name, sel])=> $left.wpjam_on(name, sel, e => this.left(e)));
			}

			let $items	= $left.find('[data-id]');
			let paged	= parseInt($paged.addClass('expandable').val());
			let key		= wpjam.left_key = this.left_key;

			$left.find('a.prev-page').addClass('button').addClass(paged <= 1 && 'disabled');
			$left.find('a.next-page').addClass('button').addClass(paged >= parseInt($paged.attr('max')) && 'disabled');

			$items[0] && $left.find('[data-id='+(wpjam.params[key] = wpjam.params[key] || $items.first().data('id'))+']').addClass('left-current');

			this.$form.find('.sticky-columns').css('--left-height', $('#col-left table').height()+'px');

			$('a.page-title-action').clone().toggleClass('page-title-action button').prependTo($('div.overallactions'));
		},

		render: function($el){
			$el.is('td') || _.each(this.columns, (column, sel)=> {
				let {sticky, left, nowrap, description, ...data}	= column;
				let $col	= $('.'+sel);

				sticky && $col.addClass('sticky-column').css('left', left);
				nowrap && $col.addClass('nowrap');

				if(_.isEmpty(data)){
					return;
				}

				$col.wpjam_each($td => {
					if(data.check){
						return $td.find('input, span')[0] || $td.append('<span class="dashicons dashicons-minus"></span>');
					}

					let value	= $td.text();
					let number	= Number(value);

					if(isNaN(number)){
						return;
					}

					_.some(data.conditional_styles, rule => wpjam.compare(number, rule) && $td.css(_.reduce({
						bold: {'font-weight': 'bold'},
						strikethrough: {'text-decoration': 'line-through'},
						color: '',
						'background-color': ''
					}, (acc, prop, key)=> rule[key] ? {...acc, ...(prop || _.pick(rule, key))} : acc, {})));

					let {format, precision}	= data;

					if(format || precision){
						if(format == '%'){
							number	= parseFloat((number*100).toFixed(precision || 2))+'%';
						}else{
							number	= precision ? parseFloat(number.toFixed(precision)) : number;
							number	= format == ',' ? number.toLocaleString() : number;
						}

						$td.text(number).attr('value', value);
					}
				});
			});

			$el.find('.items.sortable').add($el.is('table') && this.sortable && this.$tbody).wpjam_each($s => $s.sortable({
				handle: '.list-table-move-action',
				cursor:	'move',
				...($s.is('tbody') ? {items: this.sortable.items, axis: 'y'} : {items: '> div.item'}),
				start:	(e, ui)=> {
					ui.placeholder.css({
						'background-color':	'#eeffffcc',
						visibility:			'visible',
						height:				ui.helper.height()+'px'
					});
				},

				update:	(e, ui)=> {
					let {action, nonce, id, data}	= ui.item.find('.ui-sortable-handle').data();

					list_table.action({
						action_type:	'direct',
						list_action:	action,
						_ajax_nonce:	nonce,
						data:	(data || '')+'&pos='+ui.item.prevAll().length+'&'+$s.sortable('serialize'),
						id
					});
				}
			}));

			return this;
		},

		update_row: function(data, highlight=true){
			let $el	= data;

			if(!(data instanceof $)){
				if(this.layout == 'calendar'){
					_.each(data.data, (item, date)=> this.update_row($('td#date_'+date).html(item)));

					return this;
				}

				if(_.isObject(data) && data.bulk){
					_.each(data.data || data.ids, item => this.update_row(item));

					return this;
				}

				let id	= data;

				if(_.isObject(data)){
					id	= data.id;

					data.data && this.get_row(id).first().before(data.data).end().remove();
				}

				$el	= this.get_row(id);
			}

			highlight && $el.wpjam_highlight('add');

			return this.render($el);
		},

		delete_row: function(id){
			this.get_row(id).wpjam_highlight('delete').wpjam_remove(()=> this.$tbody.find('> tr')[0] || this.$tbody.append('<tr class="no-items"><td colspan="'+this.$form.find('tr').children().length+'" class="colspanchange">'+_wpMediaViewsL10n.noItemsFound+'</td></tr>'));
		},

		get_row: function(id){
			id	= typeof id == "string" ? id.replace(/(:|\.|\[|\]|,|=|@)/g, "\\$1") : id;

			return $('.tr-'+id)[0] ? $('.tr-'+id) : $('#'+this.name+'-'+id);
		},

		action: function(args){
			return wpjam.action('list-table', args);
		},

		query: function(params){
			$('.notice-dismiss').trigger('click.wp-dismiss-notice');

			if(!params && !this.$form.wpjam_validate()){
				return false;
			}

			wpjam.params	= params ? _.omit(params, v => _.isNull(v)) : wpjam.parse_params(this.$form.serialize(), true);

			return this.action({action_type: 'query_items', data: $.param(wpjam.params)});
		},

		callback: function(e){
			let $el	= $(e.currentTarget);

			if(e.type == 'click'){
				if($el.is('.prev-day, .next-day')){
					let $date	= $el.siblings('[name="date"]');
					let date	= new Date($date.val());

					date.setDate(date.getDate()+($el.hasClass('prev-day') ? -1 : 1));
					$date.val(date.toISOString().split('T')[0]);

					return this.query(), false;
				}

				if($el.hasClass('list-table-filter')){
					return this.query($el.data('filter')), false;
				}

				if(this.ajax === false){
					return;
				}

				if($el.closest('td')[0] && (wpjam.plugin_page || !$el.attr('href').startsWith($('#adminmenu a.current').attr('href')))){
					return;
				}

				let params	= wpjam.parse_params(new URL($el.prop('href')).search);

				if(wpjam.builtin_page && (params.page || params.action)){
					return;
				}

				return this.query($el.is('th a, .pagination-links a') ? {
					...wpjam.params,
					..._.omit(params, 'page'),
					paged: params.paged || 1
				} : params), false;
			}else if(e.type == 'submit'){
				let $ae	= $(document.activeElement);

				if($ae.is('#doaction, #doaction2')){
					let $select	= $ae.prev('select');
					let name	= $select.val();
					let $ids	= this.$form.find('th.check-column input[type="checkbox"]:checked');
					let ba		= this.bulk_actions?.[name];

					if(ba && $ids[0]){
						return $select.data({
							...ba,
							...(name == 'delete' && ba.bulk === true && {bulk: 2}),
							ids: $ids.toArray().map(cb => cb.value)
						}).wpjam_action(), false;
					}
				}else if($ae.is('[name=filter_action], #search-submit')){
					if(this.ajax !== false){
						return this.query(), false;
					}
				}
			}else if(e.type == 'keydown'){
				if(e.key === 'Enter' && this.ajax !== false){
					if($el.is('#current-page-selector')){
						return this.query(_.extend(wpjam.params, {paged: parseInt($el.val())})), false;
					}else if(!$el.closest('.mu-text, .mu-fields')[0]){
						let $submit	= $el.closest(':has(:submit)').find(':submit');

						if($submit[0]){
							return $submit.first().focus().trigger("click"), false;
						}
					}
				}
			}else if(e.type == 'change'){
				if($el.is('[name="date"]')){
					this.query();
				}else if($el.is('[name="end_date"]')){
					let dates	= _.map(['start_date', 'end_date'], k => $('.tablenav [name="'+k+'"]').val());

					dates[0] && dates[1] && dates[0] > dates[1] && wpjam.notice('开始日期不能大于结束日期', 'error');
				}
			}
		},

		before: function(args){
			let state	= ['list_action', 'id', 'data'];

			if(args.action_type == 'form'){
				if(!args.bulk){
					args.state	= state;
				}
			}else{
				if(args.bulk && args.bulk == 2 && !args.id){
					wpjam.dialog('close');

					return this.action({...args, id: args.ids.shift()}).then(()=> args.ids.length && setTimeout(()=> this.action(args), 400)) && false;
				}

				args.params	= _.omit(wpjam.params, state);
			}

			if(this.$tbody.find('.check-column')[0]){
				_.reduce(((args.bulk && args.bulk != 2) ? args.ids : (args.id ? [args.id] : [])), ($r, i)=> $r.add(this.get_row(i)), $()).find('.check-column input').before(wpjam.spinner);
			}
		},

		response: function(data, args){
			if(args.bulk){
				this.$form.find('td.check-column input').prop('checked', false);
			}else{
				this.$tbody.find('> tr').not(args.id ? this.get_row(args.id) : '').wpjam_highlight();
			}

			let $item	= args.id && this.get_row(args.id).wpjam_scroll();
			let type	= data.type;

			if(['up', 'down', 'move'].includes(type)){
				$item.wpjam_highlight('move');

				if(type == 'up'){
					$item.insertBefore($item.prev());
				}else if(type == 'down'){
					$item.insertAfter($item.next());
				}
			}else if(!['form', 'append', 'redirect'].includes(type)){
				data.args && setTimeout(()=> this.action({...args, data: data.args}), 400);

				type == 'list' && args.list_action == 'delete' && this.delete_row(args.id);

				this.load(data);

				if(type == 'items'){
					_.each(data.items, item => this.process(item, args));
				}else{
					this.process(data, args);
				}
			}

			data.next && _.extend(wpjam.params, {list_action: data.next}, _.pick(args, ['id', 'data']));

			data.list_action	= args.list_action;
			data.action_type	= data.list_action_type	= args.action_type;

			return data;
		},

		process: function(data, args){
			let type	= data.type;
			let $dialog	= wpjam.dialog();

			if($dialog[0] || args.action_type != 'submit'){
				$dialog[0] && data.form && wpjam.dialog(data);

				wpjam.notice(data.notice);
			}

			if(type == 'list'){
				$('html').scrollTop(0);

				((data.bulk && data.ids) || data.id) && this.update_row(data);
			}else if(['add', 'duplicate'].includes(type)){
				let pos		= (data.after || data.before);
				let $pos	= pos ? this.get_row(pos) : this.$tbody.find('> tr');
				let $row	= $(data.data);

				if(data.after || data.last){
					$pos.last().after($row);
				}else{
					$pos.first().before($row);
				}

				this.update_row(data).$form.find('tr.no-items').remove();
			}else if(['add_item', 'edit_item', 'del_item', 'move_item'].includes(type)){
				let params	= wpjam.parse_params(['add_item', 'edit_item'].includes(type) ? args.defaults : args.data);
				let $items	= (type == 'del_item' ? this : this.update_row(data, false)).get_row(data.id).find('[data-field="'+params._field+'"]');

				if(type == 'add_item'){
					$items.find('[data-i]:last').wpjam_highlight('add');
				}else if(type == 'edit_item'){
					$items.find('[data-i="'+params.i+'"]').wpjam_highlight('update');
				}else if(type == 'move_item'){
					$items.find('[data-i="'+params.pos+'"]').wpjam_highlight('move');
				}else if(type == 'del_item'){
					$items.find('[data-i="'+params.i+'"]').wpjam_highlight('delete').wpjam_remove();
				}
			}else if(type == 'delete'){
				_.each(data.bulk ? data.ids : [data.id], id => this.delete_row(id));
			}else{
				this.update_row(data);
			}
		}
	};

	wpjam.init();
});

jQuery.widget('wpjam.iris', jQuery.a8c.iris, {
	_create: function(){
		if(!Color.fn.fromHex6){
			Color.fn.fromHex6	= Color.fn.fromHex;
			Color.fn.fromHex	= function(color){
				color	= color.replace(/^#|^0x/, '');

				if(color.length === 8 && /^[0-9A-F]{8}$/i.test(color)){
					this.a(parseInt(color.substring(6), 16)/255);

					color	= color.substring(0, 6);
				}

				return this.fromHex6(color);
			};

			Color.fn.toString	= function(){
				return this.error ? '' : '#'+(parseInt(this._color, 10).toString(16).padStart(6, '0'))+(this._alpha < 1 ? parseInt(255*this._alpha, 10).toString(16).padStart(2, '0') : '');
			};
		}

		this._super();
	},
	_change: function(){
		if(this.element.data('alpha-enabled')){
			this._inited || this.controls.strip.width(18).clone(false, false).find('> div').slider({
				orientation: 'vertical',
				value: parseInt(this._color.a()*100),
				slide: (e, ui)=> {
					this._color.a(parseFloat(ui.value/100));
					this.active	= 'strip';
					this._change();
				}
			}).end().insertAfter(this.controls.strip);

			this.element.closest('.wp-picker-container').css('--color', this._color.toString() || '#FFFFFF80');
		}

		this._super();
	}
});