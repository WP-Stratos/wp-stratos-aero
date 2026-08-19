/**
 * Aero Cache Manager — Row-Action "Flush Cache" link (Pages/Posts list)
 */

jQuery(document).ready(function ($) {

	var ajaxUrl = (typeof aeroCmColumnData !== 'undefined' && aeroCmColumnData.ajaxurl)
		? aeroCmColumnData.ajaxurl
		: (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

	$(document).on('click', '.aero-cm-flush-cache-link', function (e) {
		e.preventDefault();

		var $link = $(this);
		var id = $link.data('id');
		var nonce = $link.data('nonce');
		var original = $link.text();

		$link.text('Flushing…');

		$.ajax({
			type: 'GET',
			url: ajaxUrl,
			data: { action: 'aero_cm_flush_cache_column', id: id, nonce: nonce },
			dataType: 'json',
			cache: false
		}).done(function (r) {
			$link.text(r && r.success ? 'Flushed ✓' : 'Failed ✗');
		}).fail(function () {
			$link.text('Failed ✗');
		}).always(function () {
			setTimeout(function () { $link.text(original); }, 2500);
		});
	});

});
