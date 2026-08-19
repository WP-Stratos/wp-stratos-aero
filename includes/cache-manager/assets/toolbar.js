/**
 * Aero Cache Manager — Frontend Toolbar JS
 *
 * Handles the "Flush Cache for This Page" admin bar button: fires the
 * Batcache flush for the current URL first, then the Edge Cache purge
 * (sequentially) when the Edge Cache is enabled.
 */

jQuery(document).ready(function ($) {

	// Loader overlay (created once)
	if (!$('#aero-cm-loader-toolbar').length) {
		$('body').append('<div id="aero-cm-loader-toolbar"></div>');
	}

	// Resolve AJAX URL: prefer localized value, fall back to WP global
	var ajaxUrl = (typeof aeroCmToolbarData !== 'undefined' && aeroCmToolbarData.ajaxurl)
		? aeroCmToolbarData.ajaxurl
		: (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

	// Nonce from wp_localize_script (works on both admin + frontend)
	var nonce = (typeof aeroCmToolbarData !== 'undefined') ? aeroCmToolbarData.nonce : '';

	// Whether Edge Cache flush should also fire
	var flushEdge = (typeof aeroCmToolbarData !== 'undefined' && aeroCmToolbarData.flushEdge === '1');

	function sendRequest(action) {
		return $.ajax({
			type: 'GET',
			url: ajaxUrl,
			data: { action: action, path: window.location.pathname, nonce: nonce },
			dataType: 'json',
			cache: false
		});
	}

	// Sequential flush: Batcache first, then Edge Cache if active
	function flushCurrentPage() {
		$('#aero-cm-loader-toolbar').show();

		sendRequest('aero_cm_delete_current_page_cache')
			.always(function () {
				if (flushEdge) {
					sendRequest('aero_cm_purge_current_page_edge_cache').always(done);
				} else {
					done();
				}
			});
	}

	function done() {
		$('#aero-cm-loader-toolbar').hide();
		if (typeof window.aeroCmShowModal === 'function') {
			window.aeroCmShowModal(
				flushEdge
					? 'Batcache and Edge Cache flushed for this page.'
					: 'Batcache flushed for this page.'
			);
		}
	}

	// Event delegation on body — the admin bar is injected late and the
	// submenu wrapper varies by WP version; matching the <li> id directly
	// is the most reliable approach.
	$('body').on('click', function (e) {
		var $li = $(e.target).closest('li');
		var id = $li.attr('id') || '';

		if (id === 'wp-admin-bar-aero-cm-flush-cache-of-this-page') {
			e.preventDefault();
			flushCurrentPage();
		}
	});

});
