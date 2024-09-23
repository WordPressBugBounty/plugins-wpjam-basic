jQuery(function($){
	if(window.location.protocol == 'https:'){
		ajaxurl	= ajaxurl.replace('http://', 'https://');
	}

	$.fn.extend({
		wpjam_submit: function(callback){
			let _this	= $(this);
			let data	= new FormData(_this[0]);

			data.set('_ajax_nonce',	$(this).data('nonce'));
			data.set('action',		$(this).data('action'));
			data.set('defaults',	$(this).data('data'));
			data.set('data',		$(this).serialize());

			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: data,
				processData: false,
				contentType: false,
				success: function(data){
					callback.call(_this, data);
				}
			});
		},
		wpjam_action: function(callback){
			let _this	= $(this);

			$.post(ajaxurl, {
				_ajax_nonce:	$(this).data('nonce'),
				action:			$(this).data('action'),
				data:			$(this).data('data')
			},function(data, status){
				callback.call(_this, data);
			});
		}
	});
});