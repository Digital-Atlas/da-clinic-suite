<?php
/**
 * Module: Self-Scheduling (formerly the Phreesia Self-Scheduling Widget plugin v2.4).
 * [phreesia_widget]              → global Phreesia URL (Clinic Settings → Self-Scheduling)
 * [phreesia_widget location="2"] → that location's Phreesia URL, falling back to the global one
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', function () {
	wp_register_script( 'dacs-scheduling', DACS_URL . 'assets/scheduling.js', array(), dacs_asset_ver( 'scheduling.js' ), true );
	wp_register_style( 'dacs-scheduling', DACS_URL . 'assets/scheduling.css', array(), dacs_asset_ver( 'scheduling.css' ) );
} );

function dacs_scheduling_shortcode( $atts ) {
	static $instance = 0;
	$instance++;

	$atts = shortcode_atts( array( 'location' => '', 'height' => '' ), $atts, 'phreesia_widget' );

	$url = '';
	if ( $atts['location'] !== '' ) {
		$loc = dacs_find_location( $atts['location'] );
		if ( $loc && $loc['phreesia_url'] !== '' ) $url = $loc['phreesia_url'];
	}
	if ( $url === '' ) $url = (string) dacs_opt( 'phreesia_url', '' );
	$url = esc_url( $url );
	if ( $url === '' ) {
		return current_user_can( 'manage_options' ) ? '<p><em>Self-Scheduling: add the Phreesia URL under Clinic Settings → Self-Scheduling.</em></p>' : '';
	}

	$height = (int) ( $atts['height'] !== '' ? $atts['height'] : dacs_opt( 'dacs_sched_height', 300 ) );
	$height = max( 100, $height );

	wp_enqueue_style( 'dacs-scheduling' );
	wp_enqueue_script( 'dacs-scheduling' );

	// The first widget keeps the old #embeddedAppointments id for any theme CSS / tracking that targets it.
	$iframe_id = $instance === 1 ? 'embeddedAppointments' : 'embeddedAppointments-' . $instance;

	ob_start(); ?>
	<div class="iframe-wrapper dacs-scheduler">
		<section class="schedule-appointment iframe">
			<div class="loading-icon dacs-scheduler-loading" aria-hidden="true">
				<img src="<?php echo esc_url( DACS_URL . 'assets/img/loader.svg' ); ?>" alt="" width="150" />
			</div>
			<iframe id="<?php echo esc_attr( $iframe_id ); ?>" class="dacs-scheduler-frame" src="<?php echo $url; ?>"
				style="height:<?php echo (int) $height; ?>px" title="Schedule an Appointment" loading="eager"></iframe>
		</section>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'init', function () {
	add_shortcode( 'phreesia_widget', 'dacs_scheduling_shortcode' );
} );
