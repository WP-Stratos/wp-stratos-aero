/**
 * Aero — media library integration
 * Per-image convert/delete actions in the list column, attachment edit box
 * and grid-mode sidebar, plus the Replace Media flow on attachment pages.
 */
/* global jQuery, aeroIoMedia */
(function ($) {
	'use strict';

	if (typeof aeroIoMedia === 'undefined') {
		return;
	}

	var pollTimer = null;
	var pending = {};

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = aeroIoMedia.nonce;
		return $.post(aeroIoMedia.ajaxurl, data);
	}

	function parse(resp) {
		if (typeof resp === 'object' && resp !== null) { return resp; }
		try { return JSON.parse(resp); } catch (e) { return null; }
	}

	function pageContext($el) {
		return $el.closest('.misc-pub-cx').length ? 'edit' : 'media';
	}

	function containerFor(id, page) {
		if (page === 'edit') { return $('.misc-pub-cx[data-id="' + id + '"]'); }
		var $item = $('.cx-media-item[data-id="' + id + '"]');
		return $item.length ? $item : $('.cx-media-attachment[data-id="' + id + '"]');
	}

	function startPolling() {
		if (pollTimer) { return; }
		pollTimer = setInterval(function () {
			var ids = Object.keys(pending);
			if (!ids.length) {
				clearInterval(pollTimer);
				pollTimer = null;
				return;
			}
			var page = pending[ids[0]];
			post('aero_io_get_opt_single_image_progress', { ids: JSON.stringify(ids), page: page }).done(function (resp) {
				var r = parse(resp);
				if (!r || r.result !== 'success') { return; }
				$.each(ids, function (_, id) {
					if (r[id] && r[id].html) {
						var $c = containerFor(id, pending[id]);
						if ($c.length) {
							if (pending[id] === 'edit') {
								$c.html(r[id].html);
							} else {
								$c.replaceWith(r[id].html);
							}
						}
					}
				});
				if (!parseInt(r.continue, 10)) {
					pending = {};
				}
			});
		}, 3000);
	}

	// Apply selected action (Convert / Delete) from list column or edit box.
	$(document).on('click', 'a.cx-media', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.hasClass('button-disabled')) { return; }
		var id = $btn.data('id');
		var page = pageContext($btn);
		var $wrap = containerFor(id, page);
		var action = $wrap.find('.cx-media-selected').val();

		if (action === 'delete') {
			deleteImage(id, page);
			return;
		}
		if (action !== 'convert' && $wrap.find('.cx-media-selected').length) {
			return; // "Select Action" placeholder
		}

		$btn.addClass('button-disabled').text('Converting…');
		pending[id] = page;
		// Fire-and-poll: the endpoint optimizes synchronously and returns no
		// body; the progress poll swaps in the finished markup.
		post('aero_io_opt_single_image', { id: id, page: page });
		startPolling();
	});

	function deleteImage(id, page) {
		post('aero_io_delete_single_image', { id: id, page: page }).done(function (resp) {
			var r = parse(resp);
			if (r && r[id] && r[id].html) {
				var $c = containerFor(id, page);
				if (page === 'edit') { $c.html(r[id].html); } else { $c.replaceWith(r[id].html); }
			}
		});
	}

	$(document).on('click', 'a.cx-media-delete', function (e) {
		e.preventDefault();
		var id = $(this).data('id');
		deleteImage(id, pageContext($(this)));
	});

	// ── Replace Media (attachment edit screen) ──────────────────────────
	$(document).on('change', '#aero-io-replace-file', function () {
		var name = this.files && this.files[0] ? this.files[0].name : '';
		$('#aero-io-replace-name').text(name);
		$('#aero-io-replace-go').prop('disabled', !name);
	});

	$(document).on('click', '#aero-io-replace-go', function () {
		var $btn = $(this);
		var fileInput = document.getElementById('aero-io-replace-file');
		var attachmentId = $btn.data('id');
		if (!fileInput || !fileInput.files || !fileInput.files[0]) { return; }

		var fd = new FormData();
		fd.append('action', 'aero_io_replace_media');
		fd.append('nonce', aeroIoMedia.nonce);
		fd.append('attachment_id', attachmentId);
		fd.append('image', fileInput.files[0]);

		$btn.prop('disabled', true).text('Replacing…');
		$('#aero-io-replace-status').text('');

		$.ajax({
			url: aeroIoMedia.ajaxurl,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (resp) {
			var r = parse(resp);
			if (r && r.result === 'success') {
				$('#aero-io-replace-status').css('color', '#00a32a').text('Replaced — reloading…');
				window.location.reload();
			} else {
				$btn.prop('disabled', false).text('Replace');
				$('#aero-io-replace-status').css('color', '#d63638').text((r && r.error) || 'Replacement failed.');
			}
		}).fail(function () {
			$btn.prop('disabled', false).text('Replace');
			$('#aero-io-replace-status').css('color', '#d63638').text('Replacement failed.');
		});
	});
})(jQuery);
