jQuery(function($){
	$('body').on('submit', '#loginform', function(e){
		if($(this).data('action')){
			e.preventDefault();

			$('div#login_error').remove();
			$(this).removeClass('shake');

			$(this).wpjam_submit(function(data){
				if(data.errcode){
					$('h1').after('<div id="login_error" class="notice notice-error">'+data.errmsg+'</div>');
					$(this).addClass('shake');
				}else{
					if($('p.types').data('action') == 'bind'){
						window.location.reload();
					}else{
						if($('body').hasClass('interim-login')){
							$('body').addClass('interim-login-success');
							$(window.parent.document).find('.wp-auth-check-close').click();
						}else{
							window.location.href	= $.trim($('input[name="redirect_to"]').val());
						}
					}
				}
			});
		}
	});

	$('body').on('click', 'p.types a', function(e){
		e.preventDefault();

		$('div#login_error').remove();

		$('#loginform').removeClass('shake').hide();

		let action	= $('p.types').data('action');
		let url		= new URL(window.location.href);

		if(action == 'login' && $(this).data('type') == 'login'){
			$('p#nav').show();

			$('div.fields').html(login_fields);
			$('#loginform').data('action', '').slideDown(300);

			$('.types a').removeClass('current');
			$(this).addClass('current');

			url.searchParams.delete(action+'_type');
			window.history.replaceState(null, null, url.toString());
		}else{
			$('p#nav').hide();

			$(this).wpjam_action(function(data){
				if(data.errcode != 0){
					alert(data.errmsg);
				}else{
					$('div.fields').html(data.fields);
					$('#loginform').data('action', data.action).data('data', data.data).data('nonce', data.nonce).slideDown(300);

					if(data.submit_text){
						$('input#wp-submit').val(data.submit_text);
					}

					$('.types a').removeClass('current');
					$(this).addClass('current');

					url.searchParams.set(action+'_type', $(this).data('type'));
					window.history.replaceState(null, null, url.toString());
				}
			});
		}

		return true;
	});

	$('p.types').insertBefore('p.submit');

	$('<div class="fields"></div>').prependTo('#loginform');

	$('input#user_login').parent().appendTo('div.fields');
	$('div.user-pass-wrap, p.forgetmenot').appendTo('div.fields');

	let login_fields	= $('div.fields').html();

	if($('p.types').data('action') == 'bind'){
		$('title').html('绑定');
	}

	if($('p.types a.current').data('type') != 'login'){
		$('p.types a.current').click();
	}
});