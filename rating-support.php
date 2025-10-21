<?php

// Plugin rating checker
function aero_rating_checker() {
	// check for admin notice dismissal
	if ( isset( $_POST['aero-already-reviewed'] ) ) {
		update_option( 'aero_review_notice', '' );
		if ( get_option( 'aero_activation_date' ) ) {
			delete_option( 'aero_activation_date' );
		}
	}

	// display admin notice after 30 days if clicked 'May be later'
	if ( isset( $_POST['aero-review-later'] ) ) {
		update_option( 'aero_review_notice', '' );
		update_option( 'aero_activation_date', strtotime( 'now' ) );
	}

	$install_date = get_option( 'aero_activation_date' );
	$past_date = strtotime( '-30 days' );

	if ( FALSE !== get_option( 'aero_activation_date' ) && $past_date >= $install_date ) {
		update_option( 'aero_review_notice', 'on' );
		delete_option( 'aero_activation_date' );
	}
}
add_action( 'aero_rating_system_action', 'aero_rating_checker' );

/* Add admin notice for requesting plugin review */
function aero_submit_review_notice() {
	global $aero_plugin_version;

	if( get_option( 'aero_review_notice') && get_option( 'aero_review_notice' ) == "on"  ) {

		$notice_contents = '<p>Thank you for using <strong>Aero by WP Stratos</strong>! </p>';
		$notice_contents .= '<p>If you find it useful, we would appreciate your feedback. It helps us improve and motivates us to keep developing!</p>';
		$notice_contents .= '<p>- WP Stratos Team </p>';
		$notice_contents .= '<p> <a href="#" id="aero_letMeReview" class="button button-primary">Sure, happy to help</a> &nbsp; <a href="#" id="aero_willReviewLater" class="button button-primary">Maybe later</a> &nbsp; <a href="#" id="aero_alredyReviewed" class="button button-primary">I already did</a> &nbsp; <a href="#" id="aero_noThanks" class="button button-primary">No, thanks</a> </p>';
		?>
		<div class="notice notice-info is-dismissible" id="aero_notice_div"> <?php _e( $notice_contents, 'aero' ); ?> </div>
		<script type="text/javascript">
			var $j = jQuery.noConflict();
			$j(document).ready( function() {
				var loc = location.href;
				$j("#aero_letMeReview").on('click', function() {
					$j('#aero_notice_div').slideUp();
					$j.ajax({
						url: loc,
						type: 'POST',
						data: {
							"aero-review-later": ''
						},
						success: function(msg) {
							window.open("https://wpstratos.com", "_blank");
						}
					});
				});
				$j("#aero_willReviewLater").on('click', function() {
					$j('#aero_notice_div').slideUp();
					$j.ajax({
						url: loc,
						type: 'POST',
						data: {
							"aero-review-later": ''
						}
					});
				});
				$j("#aero_alredyReviewed").on('click', function() {
					$j('#aero_notice_div').slideUp();
					$j.ajax({
						url: loc,
						type: 'POST',
						data: {
							"aero-already-reviewed": ''
						}
					});
				});
				$j("#aero_noThanks").on('click', function() {
					$j('#aero_notice_div').slideUp();
					$j.ajax({
						url: loc,
						type: 'POST',
						data: {
							"aero-already-reviewed": ''
						}
					});
				});
				$j(document).on('click', '#aero_notice_div .notice-dismiss', function() {
					$j('#aero_notice_div').slideUp();
					$j.ajax({
						url: loc,
						type: 'POST',
						data: {
							"aero-already-reviewed": ''
						}
					});
				});
			});
		</script>
		<?php
	}
}
add_action( 'admin_notices', 'aero_submit_review_notice' );

?>