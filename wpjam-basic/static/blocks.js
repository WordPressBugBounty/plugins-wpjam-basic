(function(wp, _, panels) {
	const el	= (tag, ...args)=> {
		return wp.element.createElement(typeof tag === 'string' && /^[A-Z]/.test(tag) ? wp.components[tag] : tag, ...args);
	};

	const isEqual	= (a, b)=> String(a) === String(b);

	const parseName = (name)=> {
		const parts	= name.includes('[') && !name.startsWith('[') && name.endsWith(']') && name.split(/(\[|\])/).filter(t => t !== '');

		if(!parts || parts.length % 3 !== 1){
			return [name];
		}

		const names	= [parts[0]];

		for(let i = 1; i < parts.length; i += 3){
			if(parts[i] !== '[' || !parts[i+1] || parts[i+2] !== ']'){
				return [name];
			}

			names.push(parts[i+1]);
		}

		return names;
	};

	const getValue	= (name, {data, value_callback})=> {
		const names = parseName(name);
		const val	= names.reduce((acc, n)=> acc?.[n], value_callback ? {[names[0]]: value_callback(names[0])} : data);

		return val !== undefined ? val : null;
	};

	const setValue	= (name, value, args)=> {
		const names	= parseName(name);
		const data	= { [names[0]]: getValue(names[0], args) };

		names.reduce((acc, n, i)=> {
			if(i === names.length - 1){
				if(value === null && names.length > 1){
					delete acc[n];
				}else{
					acc[n]	= value;
				}
			}else{
				acc[n]	= _.isArray(acc[n]) ? [...acc[n]] : (_.isObject(acc[n]) ? {...acc[n]} : {});
			}

			return acc[n];
		}, data);

		return data;
	};

	const sanitizeValue	= (schema, value)=> {
		if(value === ''){
			return null;
		}

		const type = schema.type;

		if(type === 'array'){
			return value.map(v => sanitizeValue(schema.items, v)).filter(v => v !== null);
		}else if(['integer', 'number'].includes(type)){
			value	= type === 'integer' ? parseInt(value, 10) : parseFloat(value);

			return isNaN(value) ? null : value;
		}else if(type === 'string'){
			return value === null ? '' : String(value);
		}

		return value;
	};

	const validateValue	= (schema, value, prevValue)=> {
		if(!schema || !_.isArray(value)){
			return null;
		}

		if(schema.maxItems && value.length > schema.maxItems && (!prevValue || value.length > prevValue.length)){
			return '最多支持'+schema.maxItems+'个';
		}

		if(schema.minItems && value.length < schema.minItems && prevValue && value.length < prevValue.length){
			return '至少需要'+schema.minItems+'个';
		}

		if(schema.uniqueItems && _.uniq(value.map(String)).length !== value.length){
			return '不允许重复';
		}

		return null;
	};

	const TruncateText	= (text)=> el('span', {className: 'truncate-text'}, text);

	const MediaButton	= ({field, render})=> {
		const isId		= field.item_type === 'id';
		const allowed	= field.item_type ? { allowedTypes: [isId ? 'image' : field.item_type] } : {};

		return el(wp.blockEditor.MediaUpload, {
			...allowed,
			multiple: !!field.mu,
			onSelect: (media)=> {
				const toVal	= (m)=> isId ? m.id : m.url;

				field.mu ? field.mu.addItem(...media.map(toVal)) : field.update(toVal(media));
			},
			render: render || (({open})=> el('Button', {
				variant: 'secondary',
				onClick: open
			}, field.button_text))
		});
	};

	const FileControl	= ({field})=> {
		const {value, update, mu}	= field;
		const [newVal, setNewVal]	= wp.element.useState('');

		const length	= value.length;

		const textInput	= (val, index)=> el('TextControl', {
			value: val,
			placeholder: field.placeholder || 'https://...',
			__next40pxDefaultSize: true,
			...(mu ? {
				onChange: (v)=> {
					if(index === length){
						setNewVal(v);
					}else{
						mu.updateItem(index, v);
					}
				},
				onBlur: ()=> {
					if(index === length){
						if(val){
							mu.addItem(val);
							setNewVal('');
						}
					}else if(!val){
						mu.removeItem(index);
					}
				}
			} : {
				onChange: (v)=> update(v || null)
			})
		});

		if(mu){
			return el('Flex', {
				direction: 'column',
				align: 'flex-start',
				gap: 2
			}, [...value, newVal].map((val, index) => el('Flex', {
				...(index < length ? {...mu.dragProps, 'data-index': index} : {}),
				className: 'mu-item',
				key: index,
				gap: 1
			}, el('FlexBlock', null, textInput(val, index)), index < length ? el('Button', {
				variant: 'secondary',
				isDestructive: true,
				onClick: ()=> mu.removeItem(index)
			}, '删除') : null, index < length ? el('Button', {
				icon: 'menu',
				size: 'small',
				className: 'move-item'
			}) : el(MediaButton, {
				field
			}))));
		}

		return el('Flex', {
			justify: 'flex-start',
			gap: 1
		}, el('FlexBlock', null, textInput(value)), el(MediaButton, {
			field
		}));
	};

	const MediaControl	= ({field})=> {
		const [urlInput, setUrlInput]	= wp.element.useState(null);

		const value	= field.value;
		const isId	= field.item_type === 'id';
		const mu	= field.mu;
		let urls	= mu ? value : (value ? [value] : []);
		const ids	= isId ? urls : [];
		const cache	= wp.element.useRef({});
		const data	= wp.data.useSelect(select => {
			const missing	= ids.filter(id => !cache.current[id]);
			return missing.length ? select('core').getEntityRecords('postType', 'attachment', {
				include:	missing,
				per_page:	missing.length
			}) || [] : [];
		}, [JSON.stringify(ids)])

		if(isId){
			data.forEach(m => cache.current[m.id] = m.source_url);
			urls = ids.map(id => cache.current[id]).filter(Boolean);
		}

		if(urlInput !== null){
			return el('Flex', {
				direction: 'column'
			}, el('TextControl', {
				value: urlInput,
				placeholder: 'https://...',
				onChange: (val)=> setUrlInput(val)
			}), el('Flex', {
				justify: 'flex-start'
			}, el('Button', {
				variant: 'primary',
				onClick: ()=> {
					urlInput && (mu ? mu.addItem(urlInput) : field.update(urlInput));

					setUrlInput(null);
				}
			}, '应用'), el('Button', {
				variant: 'secondary',
				onClick: ()=> setUrlInput(null)
			}, '取消')));
		}

		if(mu){
			return el('Flex', {
				className: 'mu-img',
				justify: 'flex-start',
				wrap: true,
				gap: 2
			}, urls.map((src, i) => {
				return el('div', {
					...mu.dragProps,
					'data-index': i,
					key: i,
					className: 'mu-item'
				}, el('img', {
					src
				}), el('Button', {
					icon: 'no-alt',
					size: 'small',
					className: 'del-img',
					onClick: ()=> mu.removeItem(i)
				}));
			}), el(MediaButton, {
				field,
				render: ({open})=> el('div', {
					className: 'new-item',
					onClick: open
				}, (isId ? '' : el('Button', {
					icon: 'admin-links',
					size: 'small',
					onClick: (e)=> {
						e.stopPropagation();
						setUrlInput('');
					}
				})))
			}));
		}

		if(urls[0]){
			return el('div', {
				className: 'wpjam-img'
			}, el(MediaButton, {
				field,
				render: ({open})=> el('img', {
					src: urls[0],
					onClick: open
				})
			}), el('Button', {
				icon: 'no-alt',
				className: 'del-img',
				onClick: ()=> field.update(null)
			}));
		}

		return el('Flex', {
			justify: 'flex-start'
		}, el(MediaButton, {
			field,
			render: ({open})=> el('Button', {
				variant: 'secondary',
				icon: 'camera',
				text: field.button_text,
				onClick: open
			})
		}), (isId ? '' : el('Button', {
			variant: 'secondary',
			text: '输入外链',
			onClick: ()=> setUrlInput(value || '')
		})));
	};

	const UploaderControl	= ({field})=> {
		const [uploading, setUploading] = wp.element.useState(false);

		return el('Flex', {
			justify: 'flex-start',
			gap: 1
		}, el('FormFileUpload', {
			accept: field.accept,
			disabled: uploading,
			variant: 'secondary',
			onChange: (e)=> {
				const file = e.target.files[0];

				if(!file) return;

				setUploading(true);

				const reader = new FileReader();

				reader.onerror = ()=> {
					setUploading(false);
					alert('文件读取失败');
				};

				reader.onload = ()=> {
					wpjam.post({
						action: 'wpjam-upload',
						name: field.name,
						filename: file.name,
						bits: reader.result,
						_ajax_nonce: field.nonce
					}).then(data => {
						setUploading(false);

						if(data.errcode === 0){
							field.update(data.url);
						}else{
							alert(data.errmsg || '上传失败');
						}
					});
				};

				reader.readAsDataURL(file);
			}
		}, field.button_text), (field.value ? el('FlexBlock', null, el('Flex', {
			justify: 'flex-start',
			wrap: false,
			gap: 0
		}, el('Button', {
			icon: 'dismiss',
			size: 'small',
			onClick: ()=> field.update(null)
		}), TruncateText(field.value.split('/').pop()))) : ''));
	};

	const ColorControl		= ({field})=> {
		let value	= field.value || '#000000';

		return el('Dropdown', {
			renderToggle: ({isOpen, onToggle})=> el('Button', {
				onClick: onToggle,
				style: {
					color: value,
					border: `1px solid ${value}`
				}
			}, el('ColorIndicator', {
				colorValue: value
			}), value || field.button_text),
			renderContent: ()=> el('ColorPicker', {
				color: value,
				enableAlpha: field.alpha || false,
				onChange: (color)=> field.update(color)
			})
		});
	};

	const MuSelectControl	= ({field})=> {
		return el('Dropdown', {
			focusOnMount: false,
			popoverProps: { className: 'components-wpjam-select-popover' },
			renderToggle: ({isOpen, onToggle})=> el('Button', {
				icon:			'arrow-down-alt2',
				iconPosition:	'right',
				iconSize:		12,
				className:		'truncate-text',
				onClick:		onToggle,
			}, field.value.length > 0 ? field.value.map(v => (field.options.find(o => isEqual(o.value, v)) || {}).label || v).join(', ') : (field.placeholder || '请选择')),
			renderContent: ()=> el(CheckboxControl, {field})
		});
	};

	const ImageRadioControl	= ({field})=> {
		return el('Flex', {
			justify: 'flex-start',
			wrap: true,
			gap: 4,
		}, field.options.map(opt => {
			return el('label', {
				key: opt.value,
				className: 'image-radio'
			}, el('input', {
				type: 'radio',
				name: field.name,
				value: opt.value,
				checked: isEqual(field.value, opt.value),
				onChange: ()=> field.update(opt.value)
			}), [].concat(opt.image).slice(0, 2).map((src, i) => el('img', {
				key: i,
				src,
				alt: opt.label
			})), opt.label);
		}));
	};

	const CheckboxControl	= ({field})=> {
		return el('Flex', {
			direction: 'column',
			gap: 3,
		}, field.options.map(opt => el('CheckboxControl', {
			key:		opt.value,
			label:		opt.label,
			checked:	field.value.some(v => isEqual(v, opt.value)),
			onChange:	(checked)=> field.update(checked ? [...field.value, opt.value] : field.value.filter(v=> !isEqual(v, opt.value)))
		})));
	};

	const ComboboxControl	= ({field})=> {
		const [options, setOptions]			= wp.element.useState([]);
		const [comboValue, setComboValue]	= wp.element.useState('');

		const labels	= wp.element.useRef({});
		const ref		= wp.element.useRef(null);

		const getLabel		= (v)=> labels.current[String(v)] || String(v);
		const queryItems	= ({search, include, exclude})=> {
			const query_args = field.query_args || {};

			if(search){
				query_args[field.data_type === 'post_type' ? 's' : 'search'] = search;
			}

			if(exclude?.length){
				query_args.exclude = exclude;
			}

			wpjam.post({
				action: 'wpjam-query',
				data_type: field.data_type,
				query_args: query_args,
				...(include ? {include} : {})
			}).then(data => {
				if(data.errcode === 0){
					data.items.forEach(o => labels.current[String(o.value)] = o.label);

					setOptions(data.items);
				}
			});
		};

		let {value, mu, update}	= field;

		if(!mu && value !== null){
			value	= String(value);
		}

		wp.element.useEffect(()=> {
			if(!value || !ref.current) return;

			const observer = new IntersectionObserver(([e])=> {
				if(e.isIntersecting){
					queryItems({include: mu ? value : [value]});
					observer.disconnect();
				}
			}, {threshold: 0.1});

			observer.observe(ref.current);

			return ()=> observer.disconnect();
		}, [value]);

		return el('Flex', {
			ref,
			direction: 'column',
			className: 'mu-text',
			gap: 2
		}, el('ComboboxControl', {
			options,
			value:	mu ? comboValue : value,
			placeholder: field.placeholder,
			__next40pxDefaultSize: true,
			onChange:	(val)=> {
				if(mu){
					setComboValue('');

					val && !mu.hasItem(val) && mu.addItem(val);

					queryItems({exclude: [...value, val]});
				}else{
					update(val);
				}
			},
			onFilterValueChange: (search)=> queryItems({search: search || '', exclude: mu ? value : null})
		}), mu && value.length > 0 && value.map((v, i) => el('Flex', {
			...mu.dragProps,
			'data-index': i,
			key: v,
			gap: 0,
			className: 'mu-item',
		}, TruncateText(getLabel(v)), el('Button', {
			icon: 'no-alt',
			size: 'small',
			onClick: ()=> mu.removeItem(i)
		}))));
	};

	const renderFieldset = (field, {index, ...args})=> {
		const mu	= field.mu;

		if(mu && _.isUndefined(index)){
			return el('Flex', {
				direction: 'column',
				gap: 2
			}, field.value.map((item, index)=> renderFieldset(field, {
				index,
				...args
			})), el('FlexItem', null, el('Button', {
				variant: 'secondary',
				onClick: ()=> mu.addItem({})
			}, field.button_text)));
		}

		const names		= parseName(field.name);
		const prefix	= names[0]+(mu ? '['+index+']' : '')+names.slice(1).map(n => '['+n+']').join('');

		return el('fieldset', {
			...(mu ? {
				...mu.dragProps,
				className: 'mu-item',
				'data-index': index
			} : {}),
			key: index
		}, el('legend', {
			className: 'screen-reader-text'
		}, field.label+(mu ? ' ' + (index + 1) : '')), el('Flex', {
			direction: 'column',
			gap: 2
		}, field.fields.map(sub => el(Field, {
			key: sub.name,
			field: {
				...sub,
				name: field.fieldset === 'object' ? prefix + parseName(sub.name).map(n => '['+n+']').join('') : sub.name
			},
			args
		})), mu ? el('Flex', {
			justify: 'flex-start',
			gap: 1
		}, el('Button', {
			variant: 'secondary',
			isDestructive: true,
			onClick: ()=> mu.removeItem(index)
		}, '删除'), el('Button', {
			icon: 'menu',
			size: 'small',
			className: 'move-item'
		})) : ''));
	};

	const Field	= ({field, args})=> {
		const { name, component, multiple, show_if } = field;
		const shouldHide = show_if && !wpjam.compare(getValue(show_if.key, args), show_if.compare, show_if.value);

		wp.element.useEffect(()=> {
			if(shouldHide){
				args.callback(name, null);
			}
		}, [shouldHide]);

		if(shouldHide){
			return null;
		}

		let value	= getValue(name, args);

		if(multiple){
			value	= _.isArray(value) ? value : [];

			field.mu	= {
				addItem:	(...items)=> field.update([...field.value, ...items]),
				updateItem:	(index, item)=> field.update(field.value.with(index, item)),
				removeItem:	(index)=> field.update(field.value.filter((_, i)=> i !== index)),
				hasItem:	(item)=> field.value.some(v => isEqual(v, item)),
				dragProps:	{
					draggable: true,
					onMouseDown: (e)=> {
						if(e.currentTarget.querySelector('.move-item')){
							e.currentTarget._canDrag	= !!e.target.closest('.move-item');
						}
					},
					onDragStart: (e)=> {
						const t = e.currentTarget;

						if(t._canDrag === false){
							e.preventDefault();
						}else{
							t.classList.add('drag-from');
							e.dataTransfer.effectAllowed = 'move';
						}

						delete t._canDrag;
					},
					onDragOver: (e)=> {
						e.preventDefault();

						e.currentTarget.parentElement?.querySelectorAll('.drag-to').forEach(el => el.classList.remove('drag-to'));

						e.currentTarget.classList.add('drag-to');
					},
					onDragEnd: (e)=> {
						const t		= e.currentTarget;
						const from	= Number(t.dataset.index);
						const toEl	= t.parentElement?.querySelector('.drag-to');
						const to 	= toEl ? Number(toEl.dataset.index) : null;

						t.parentElement?.querySelectorAll('.drag-from, .drag-to').forEach(el => el.classList.remove('drag-from', 'drag-to'));

						to != null && from !== to && field.update(field.value.toSpliced(from, 1).toSpliced(to, 0, field.value[from]));
					}
				}
			}
		}

		const className	= `components-wpjam-`+(multiple ? 'mu-' : '')+`${component.toLowerCase()}-control`;
		field.value		= value;
		field.update	= (val)=> {
			if(val !== null){
				val	= sanitizeValue(field.schema, val);

				const err = validateValue(field.schema, val, field.value);

				if(err){
					return alert(err);
				}
			}

			args.callback(name, val);
		};

		if(field.options){
			field.options	= _.reduce(field.options, (acc, opt)=> {
				const { show_if, ...props } = opt;

				if(!show_if || wpjam.compare(getValue(show_if.key, args), show_if.compare, show_if.value)){
					acc.push(props);
				}

				return acc;
			}, []);
		}

		let Control	= '';

		if(component == 'Radio'){
			if(field.options?.some(o => o.image)){
				Control	= ImageRadioControl;
			}
		}else if(component == 'Select'){
			if(multiple){
				Control	= MuSelectControl;
			}
		}else{
			Control	= {
				File:		FileControl,
				Media:		MediaControl,
				Color:		ColorControl,
				Checkbox:	CheckboxControl,
				Combobox:	ComboboxControl,
				Uploader:	UploaderControl
			}[component];
		}

		if(Control || component === 'Fieldset'){
			return el('BaseControl', {
				className,
				..._.pick(field, ['key', 'label', 'help'])
			}, component === 'Fieldset' ? renderFieldset(field, args) : el(Control, { field, ...args }));
		}

		if(multiple){
			Control	= 'FormTokenField';

			field.__experimentalShowHowTo	= false
		}else{
			Control	= (component == 'Timestamp' ? 'Text' : component)+'Control';

			if(component == 'Timestamp'){
				if(value && /^\d+$/.test(String(value))){
					const p2	= (n)=> String(n).padStart(2, '0');
					const date	= new Date(Number(value) * 1000);

					value	= date.getFullYear()+'-'+p2(date.getMonth() + 1)+'-'+p2(date.getDate())+'T'+p2(date.getHours())+':'+p2(date.getMinutes());
				}

				field.type	= 'datetime-local';
			}
		}

		const supportsSize = ['Text', 'Select', 'Textarea', 'Range', 'Timestamp'].includes(component);

		return el(Control, {
			...(supportsSize ? { __next40pxDefaultSize: true } : {}),
			className,
			..._.omit(field, ['component', 'show_if', 'schema', 'multiple', 'value', 'update', 'mu']),
			...(component === 'Toggle' ? { checked: !!value } : (component === 'Radio' ? { selected: value } : { value: value ?? '' })),
		onChange: (val)=> field.update(val)
		});
	};

	const Modal	= ({panel, data, setOpen})=> {
		const pick	= (data, init = {})=> panel.fields.reduce((acc, field)=> ({...acc, ...setValue(field.name, getValue(field.name, {data}), {data: acc})}), {...init});

		const [draft, setDraft]	= wp.element.useState(()=> pick(data));
		const { editPost: edit, savePost: save }	= wp.data.useDispatch('core/editor');

		return el('Modal', {
			title:	panel.title,
			size:	'medium',
			onRequestClose:	()=> setOpen(false)
		}, el('Flex', {
			direction:	'column',
			gap:		4,
		}, panel.fields.map(field => el(Field, {
			key: field.name,
			field,
			args: {
				data: draft,
				callback: (name, val)=> setDraft(prev => ({ ...prev, ...setValue(name, val, {data: prev})}))
			}
		})), el('Flex', {
			justify:	'flex-end'
		}, el('Button', {
			variant: 'primary',
			onClick: ()=> {
				edit({meta: pick(draft, data)});
				save();
			}
		}, '提交'))));
	};

	const Panel	= ({panel})=> {
		const data	= wp.data.useSelect(select => select('core/editor').getEditedPostAttribute('meta') || {});
		const edit	= wp.data.useDispatch('core/editor').editPost;
		const modal	= panel.modal;
		const title	= modal ? el('Flex', {
			onClick: (e)=> {
				e.stopPropagation();
				setOpen(true);
			}
		}, panel.title, el('span', { className: 'dashicons dashicons-admin-generic' })) : panel.title;

		const [isOpen, setOpen]	= wp.element.useState(false);

		return el(wp.element.Fragment, null, el(wp.editor.PluginDocumentSettingPanel, {
			name:	panel.name,
			title
		}, modal ? panel.description : el('Flex', {
			direction: 'column',
			gap: 4,
		}, panel.fields.map(field => el(Field, {
			key: field.name,
			field,
			args: {
				data,
				callback : (name, val)=> edit({ meta: setValue(name, val, {data}) })
			}
		})))), modal && isOpen && el(Modal, {panel, data, setOpen}));
	};

	if(panels?.length){
		wp.plugins.registerPlugin('wpjam-panels', {
			render:	()=> el(wp.element.Fragment, null, panels.map(panel => el(Panel, {
				key: panel.name,
				panel
			})))
		});
	}
})(window.wp, window._, window.wpjam_page_setting?.block.panels);