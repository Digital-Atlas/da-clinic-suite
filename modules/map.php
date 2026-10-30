<?php
/**
 * Module: HIPAA Map (formerly the Freshpaint Map Shortcode + Admin plugin v2.1).
 * [freshpaint_map height="600px" show_cards="true" focus="2|name" color="#307a99" zoom="12"]
 * Markers come from Clinic Settings → Locations. If no location has coordinates yet, the old
 * Freshpaint marker list (freshpaint_map_markers) is used so the map keeps working during migration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const DACS_MAPLIBRE_BASE = 'https://freshpaint-hipaa-maps.com/maplibre-gl@3.x/dist/';

add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'dacs-maplibre', DACS_MAPLIBRE_BASE . 'maplibre-gl.css', array(), null );
	wp_register_style( 'dacs-map', DACS_URL . 'assets/map.css', array( 'dacs-maplibre' ), dacs_asset_ver( 'map.css' ) );
	wp_register_script( 'dacs-maplibre', DACS_MAPLIBRE_BASE . 'maplibre-gl.js', array(), null, true );
	wp_register_script( 'dacs-map', DACS_URL . 'assets/map.js', array( 'dacs-maplibre' ), dacs_asset_ver( 'map.js' ), true );
} );

/** Map markers: clinic locations with coordinates, else the legacy Freshpaint list. */
function dacs_map_markers() {
	$link_text = (string) dacs_opt( 'dacs_map_link_text', 'Book Appointment' );
	$markers   = array();
	foreach ( dacs_get_locations() as $l ) {
		if ( $l['lat'] === null || $l['lng'] === null ) continue;
		$markers[] = array(
			'name'     => $l['name'],
			'address'  => $l['address'],
			'phone'    => $l['phone'],
			'hours'    => implode( "\n", $l['hours'] ),
			'link'     => $l['booking_url'],
			'linkText' => $link_text,
			'lngLat'   => array( $l['lng'], $l['lat'] ),
		);
	}
	if ( $markers ) return $markers;

	$legacy = get_option( 'freshpaint_map_markers' );
	foreach ( is_array( $legacy ) ? $legacy : array() as $m ) {
		if ( ! isset( $m['LngLat'][0], $m['LngLat'][1] ) || ! is_numeric( $m['LngLat'][0] ) || ! is_numeric( $m['LngLat'][1] ) ) continue;
		$markers[] = array(
			'name'     => (string) ( $m['Header'] ?? '' ),
			'address'  => (string) ( $m['Address'] ?? '' ),
			'phone'    => (string) ( $m['Phone'] ?? '' ),
			'hours'    => (string) ( $m['Hours'] ?? '' ),
			'link'     => (string) ( $m['Href'] ?? '' ),
			'linkText' => $link_text,
			'lngLat'   => array( (float) $m['LngLat'][0], (float) $m['LngLat'][1] ),
		);
	}
	return $markers;
}

function dacs_bool( $v ) {
	return in_array( strtolower( trim( (string) $v ) ), array( '1', 'true', 'yes', 'y', 'on' ), true );
}

function dacs_map_shortcode( $atts ) {
	static $instance = 0;
	$instance++;

	$atts = shortcode_atts( array(
		'env_id'     => DACS_FRESHPAINT_ENV_ID,
		'color'      => '',
		'height'     => '',
		'focus'      => '',
		'show_cards' => '',
		'zoom'       => '',
	), $atts, 'freshpaint_map' );

	$markers = dacs_map_markers();
	if ( ! $markers ) {
		return current_user_can( 'manage_options' ) ? '<p><em>Map: add Latitude / Longitude to a location under Clinic Settings → Locations.</em></p>' : '';
	}

	$color = sanitize_hex_color( $atts['color'] ) ?: ( sanitize_hex_color( (string) dacs_opt( 'dacs_map_marker_color', '#307a99' ) ) ?: '#307a99' );
	$height = trim( (string) ( $atts['height'] !== '' ? $atts['height'] : dacs_opt( 'dacs_map_height', '500px' ) ) );
	if ( ! preg_match( '/^\d+(\.\d+)?(px|vh|em|rem|%)$/', $height ) ) $height = ( is_numeric( $height ) ? $height . 'px' : '500px' );
	$show_cards = $atts['show_cards'] !== '' ? dacs_bool( $atts['show_cards'] ) : (bool) dacs_opt( 'dacs_map_show_cards', true );

	$config = array(
		'envId'       => preg_replace( '/[^a-zA-Z0-9-]/', '', $atts['env_id'] ),
		'style'       => 'standard-light',
		'color'       => $color,
		'zoom'        => (int) ( $atts['zoom'] !== '' ? $atts['zoom'] : dacs_opt( 'dacs_map_zoom', 10 ) ),
		'zoomOnClick' => (int) dacs_opt( 'dacs_map_zoom_on_click', 14 ),
		'showCards'   => $show_cards,
		'focus'       => trim( (string) $atts['focus'] ),
		'markers'     => $markers,
	);

	wp_enqueue_style( 'dacs-map' );
	wp_enqueue_script( 'dacs-map' );

	$id = 'dacs-map-' . $instance;
	ob_start();
	if ( $show_cards ) : ?>
		<div class="dacs-map flex-map" id="<?php echo esc_attr( $id ); ?>" data-dacs-map="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="column left-column dacs-map-cards" style="max-height:<?php echo esc_attr( $height ); ?>;"></div>
			<div class="column right-column dacs-map-canvas" style="height:<?php echo esc_attr( $height ); ?>;"></div>
		</div>
	<?php else : ?>
		<div class="dacs-map" id="<?php echo esc_attr( $id ); ?>" data-dacs-map="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="dacs-map-canvas" style="width:100%;height:<?php echo esc_attr( $height ); ?>;"></div>
		</div>
	<?php endif;
	return ob_get_clean();
}

add_action( 'init', function () {
	add_shortcode( 'freshpaint_map', 'dacs_map_shortcode' );
} );
