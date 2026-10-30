<?php
/**
 * One-time migration from the three legacy plugins.
 *  - Freshpaint map settings (zoom, zoom on click, marker colour, show cards) → Clinic Settings → HIPAA Map.
 *  - Freshpaint markers → matched to clinic locations by coordinates or name. A matched location gets the
 *    marker's coordinates if it has none, and the marker's link as its Booking Page URL if it has none.
 *    Markers that match no location are listed in an admin notice.
 *  - Phreesia URL: same ACF field key / name, nothing to move.
 * Legacy options are left in place, so the old plugins still work if reactivated.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
	if ( get_option( 'dacs_migrated' ) || ! current_user_can( 'manage_options' ) ) return;
	if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) return;
	dacs_run_migration();
} );

function dacs_run_migration() {
	$log = array();

	// 1. Map settings.
	$old = get_option( 'freshpaint_map_settings' );
	if ( is_array( $old ) && get_option( 'options_dacs_map_zoom', null ) === null ) {
		$map = array(
			'field_dacs_map_zoom'          => isset( $old['zoom'] ) ? (int) $old['zoom'] : null,
			'field_dacs_map_zoom_on_click' => isset( $old['zoom_on_click'] ) ? (int) $old['zoom_on_click'] : null,
			'field_dacs_map_marker_color'  => isset( $old['marker_color'] ) ? sanitize_hex_color( $old['marker_color'] ) : null,
			'field_dacs_map_show_cards'    => isset( $old['show_cards'] ) ? ( $old['show_cards'] ? 1 : 0 ) : null,
		);
		foreach ( $map as $key => $val ) {
			if ( $val !== null && $val !== '' ) update_field( $key, $val, 'option' );
		}
		$log[] = 'map settings';
	}

	// 2. Markers → locations.
	$markers   = get_option( 'freshpaint_map_markers' );
	$rows      = get_field( 'clinic_locations', 'option' );
	$rows      = is_array( $rows ) ? array_values( $rows ) : array();
	$unmatched = array();

	foreach ( is_array( $markers ) ? $markers : array() as $m ) {
		$lng = isset( $m['LngLat'][0] ) && is_numeric( $m['LngLat'][0] ) ? (float) $m['LngLat'][0] : null;
		$lat = isset( $m['LngLat'][1] ) && is_numeric( $m['LngLat'][1] ) ? (float) $m['LngLat'][1] : null;
		$name = trim( (string) ( $m['Header'] ?? '' ) );
		$hit  = null;

		foreach ( $rows as $i => $r ) {
			$rl = is_numeric( $r['geo']['lat'] ?? '' ) ? (float) $r['geo']['lat'] : null;
			$rg = is_numeric( $r['geo']['long'] ?? '' ) ? (float) $r['geo']['long'] : null;
			if ( $lat !== null && $rl !== null && abs( $lat - $rl ) < 0.002 && abs( $lng - $rg ) < 0.002 ) { $hit = $i; break; }
		}
		if ( $hit === null && $name !== '' ) {
			foreach ( $rows as $i => $r ) {
				$ln = trim( (string) ( $r['location_name'] ?? '' ) );
				if ( $ln !== '' && ( stripos( $ln, $name ) !== false || stripos( $name, $ln ) !== false ) ) { $hit = $i; break; }
			}
		}
		if ( $hit === null ) {
			$unmatched[] = array( 'name' => $name, 'address' => (string) ( $m['Address'] ?? '' ), 'lat' => $lat, 'lng' => $lng );
			continue;
		}

		$row = $hit + 1; // ACF rows are 1-based
		if ( $lat !== null && ( trim( (string) ( $rows[ $hit ]['geo']['lat'] ?? '' ) ) === '' || trim( (string) ( $rows[ $hit ]['geo']['long'] ?? '' ) ) === '' ) ) {
			update_sub_field( array( 'clinic_locations', $row, 'geo' ), array( 'lat' => (string) $lat, 'long' => (string) $lng ), 'option' );
			$log[] = 'coordinates for ' . ( $rows[ $hit ]['location_name'] ?? 'location ' . $row );
		}
		if ( ! empty( $m['Href'] ) && empty( $rows[ $hit ]['booking_url'] ) ) {
			update_sub_field( array( 'clinic_locations', $row, 'booking_url' ), esc_url_raw( $m['Href'] ), 'option' );
			$log[] = 'booking link for ' . ( $rows[ $hit ]['location_name'] ?? 'location ' . $row );
		}
	}

	update_option( 'dacs_unmatched_markers', $unmatched, false );
	update_option( 'dacs_migration_log', $log, false );
	update_option( 'dacs_migrated', DACS_VERSION );
	delete_option( 'dacs_needs_migration' );
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;

	if ( isset( $_GET['dacs_dismiss_markers'] ) && wp_verify_nonce( $_GET['dacs_dismiss_markers'], 'dacs_dismiss_markers' ) ) {
		delete_option( 'dacs_unmatched_markers' );
		return;
	}

	$unmatched = get_option( 'dacs_unmatched_markers' );
	if ( ! is_array( $unmatched ) || ! $unmatched ) return;

	echo '<div class="notice notice-warning"><p><strong>Clinic Suite:</strong> these old Freshpaint map markers don\'t match any location in <a href="' . esc_url( admin_url( 'admin.php?page=' . DACS_OPTIONS_SLUG ) ) . '">Clinic Settings → Locations</a>. Add them as locations to keep them on the map:</p><ul style="list-style:disc;margin-left:2em">';
	foreach ( $unmatched as $m ) {
		printf( '<li>%s — %s (%s, %s)</li>', esc_html( $m['name'] ?: '(no name)' ), esc_html( $m['address'] ), esc_html( (string) $m['lat'] ), esc_html( (string) $m['lng'] ) );
	}
	echo '</ul><p><a class="button" href="' . esc_url( add_query_arg( 'dacs_dismiss_markers', wp_create_nonce( 'dacs_dismiss_markers' ) ) ) . '">Dismiss</a></p></div>';
} );
