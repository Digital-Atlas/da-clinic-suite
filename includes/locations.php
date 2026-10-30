<?php
/**
 * Shared clinic locations (Clinic Settings → Locations), normalised for the map and scheduler.
 * The schema module reads the raw ACF rows itself.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * @return array[] Each: index (1-based), name, primary, phone, address (multi-line), lat, lng (float|null),
 *                 hours (string[]), booking_url, phreesia_url, fragment.
 */
function dacs_get_locations() {
	static $cache = null;
	if ( $cache !== null ) return $cache;
	$cache = array();
	if ( ! function_exists( 'get_field' ) ) return $cache;

	$rows = get_field( 'clinic_locations', 'option' );
	if ( ! is_array( $rows ) ) return $cache;

	foreach ( array_values( $rows ) as $i => $r ) {
		$a      = is_array( $r['address'] ?? null ) ? $r['address'] : array();
		$line2  = trim( implode( ' ', array_filter( array( trim( ( $a['city'] ?? '' ) . ( ! empty( $a['state'] ) ? ',' : '' ) ), $a['state'] ?? '', $a['zip'] ?? '' ) ) ) );
		$geo    = is_array( $r['geo'] ?? null ) ? $r['geo'] : array();
		$lat    = is_numeric( trim( (string) ( $geo['lat'] ?? '' ) ) ) ? (float) $geo['lat'] : null;
		$lng    = is_numeric( trim( (string) ( $geo['long'] ?? '' ) ) ) ? (float) $geo['long'] : null;
		$cache[] = array(
			'index'        => $i + 1,
			'name'         => (string) ( $r['location_name'] ?? '' ),
			'primary'      => ! empty( $r['is_primary'] ),
			'phone'        => (string) ( $r['phone'] ?? '' ),
			'address'      => trim( implode( "\n", array_filter( array( trim( (string) ( $a['street'] ?? '' ) ), $line2 ) ) ) ),
			'lat'          => $lat,
			'lng'          => $lng,
			'hours'        => dacs_format_hours( $r['opening_hours'] ?? array() ),
			'booking_url'  => (string) ( $r['booking_url'] ?? '' ),
			'phreesia_url' => (string) ( $r['phreesia_url'] ?? '' ),
			'fragment'     => (string) ( $r['location_fragment'] ?? '' ),
		);
	}
	return $cache;
}

/** Find a location by 1-based index or (partial, case-insensitive) name. */
function dacs_find_location( $needle ) {
	$needle = trim( (string) $needle );
	if ( $needle === '' ) return null;
	$locs = dacs_get_locations();
	if ( ctype_digit( $needle ) ) {
		foreach ( $locs as $l ) if ( $l['index'] === (int) $needle ) return $l;
	}
	foreach ( $locs as $l ) {
		if ( $l['name'] !== '' && stripos( $l['name'], $needle ) !== false ) return $l;
	}
	return null;
}

/**
 * Turn opening_hours rows into readable lines, grouping days with the same times:
 * "Mon, Tue, Thu: 7:30 AM – 4:30 PM", "Sat, Sun: Closed".
 */
function dacs_format_hours( $rows ) {
	if ( ! is_array( $rows ) ) return array();
	$order  = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
	$groups = array();
	foreach ( $rows as $h ) {
		$days = $h['days'] ?? array();
		$days = is_array( $days ) ? $days : array( $days );
		$days = array_values( array_filter( $days ) );
		if ( ! $days ) continue;
		$o    = trim( (string) ( $h['opens'] ?? '' ) );
		$c    = trim( (string) ( $h['closes'] ?? '' ) );
		$time = ( $o === '' || $c === '' ) ? 'Closed' : date( 'g:i A', strtotime( $o ) ) . ' – ' . date( 'g:i A', strtotime( $c ) );
		foreach ( $days as $d ) $groups[ $time ][] = $d;
	}
	$lines = array();
	foreach ( $groups as $time => $days ) {
		$days = array_unique( $days );
		usort( $days, function ( $a, $b ) use ( $order ) { return array_search( $a, $order, true ) <=> array_search( $b, $order, true ); } );
		$first = array_search( $days[0], $order, true );
		$lines[ $first === false ? 99 : $first ] = implode( ', ', array_map( function ( $d ) { return substr( $d, 0, 3 ); }, $days ) ) . ': ' . $time;
	}
	ksort( $lines );
	return array_values( $lines );
}
