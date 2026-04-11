(function(wp, panels) {
	const el	= wp.element.createElement;

	class Panel{
		constructor(panel){
			this.name   = panel.name;
			this.title  = panel.title;
			this.fields = panel.fields;
		}

		renderField(key) {
			let value	= this.meta[key] ?? '';
			const field	= this.fields[key];

			const {component, ...props}	= field;

			const control	= wp.components[component+'Control'];
			const update	= (val)=> this.edit({ meta: { [key]: val } });

			if(['Checkbox', 'Radio'].includes(component)){
				if(component === 'Checkbox'){
					value	= Array.isArray(value) ? value : [];					
				}

				return el(wp.components.BaseControl, { label: field.label, help: field.help }, el('div', {
					style: { display: 'flex', flexDirection: 'column', gap: '8px' } 
				}, (component === 'Checkbox' ? field.options.map(opt => el(control, {
					key:		opt.value,
					label:		opt.label,
					checked:	value.includes(opt.value),
					onChange:	(checked)=> update(checked ? [...value, opt.value] : value.filter(v=> v !== opt.value))
				})) : el(control, {
					options:	field.options,
					selected:	value,
					onChange:	update
				}))));
			}

			return el(control, {
				onChange: update,
				__next40pxDefaultSize: true,
				...props,
				...(component === 'Toggle' ? { checked: !!value } : { value })
			});
		}

		render(){
			this.meta	= wp.data.useSelect(select => select('core/editor').getEditedPostAttribute('meta') || {});
			this.edit	= wp.data.useDispatch('core/editor').editPost;

			return el(wp.editor.PluginDocumentSettingPanel, {
				name:	this.name,
				title:	this.title
			}, el(wp.element.Fragment, null, ...Object.keys(this.fields).map((key, index, arr)=> el('div', {
				key,
				style: { marginBottom: index === arr.length - 1 ? '0' : '12px' }
			}, this.renderField(key)))));
		}
	}

	(panels || []).forEach(panel => wp.plugins.registerPlugin(panel.name, {
		render: ()=> (new Panel(panel)).render()
	}));
})(window.wp, window.wpjam_page_setting?.block.panels);