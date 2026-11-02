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

/**
 * Normalise a location's @id fragment to "#name" so home URL + fragment never doubles the slash
 * ("/#orlando-clinic" or "orlando-clinic" both become "#orlando-clinic"). Empty → $default.
 */
function dacs_location_fragment( $fragment, $default = '#main-clinic' ) {
	$fragment = ltrim( trim( (string) $fragment ), '/#' );
	return $fragment === '' ? $default : '#' . $fragment;
}

/**
 * Yoast's Organization @id when Yoast outputs one, so the clinic can point at it. Filter: dacs_yoast_organization_id.
 * @return string|null
 */
function dacs_yoast_organization_id() {
	$id = null;
	if ( defined( 'WPSEO_VERSION' ) ) {
		$titles = get_option( 'wpseo_titles' );
		if ( ( $titles['company_or_person'] ?? 'company' ) === 'company' && ! empty( $titles['company_name'] ) ) {
			$id = trailingslashit( get_home_url() ) . '#organization';
		}
	}
	return apply_filters( 'dacs_yoast_organization_id', $id );
}

/**
 * schema.org type for clinic entities. MedicalClinic is both a MedicalOrganization and a LocalBusiness, so
 * address, geo, opening hours, price range and payment details count for local search. Filter: dacs_schema_clinic_type.
 */
function dacs_clinic_schema_type( $is_primary = true ) {
	return (string) apply_filters( 'dacs_schema_clinic_type', 'MedicalClinic', (bool) $is_primary );
}

/** True when two business names match, ignoring case, spaces and punctuation. */
function dacs_same_business_name( $a, $b ) {
	$n = function ( $s ) { return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $s ) ); };
	return $n( $a ) !== '' && $n( $a ) === $n( $b );
}

/**
 * @id of the primary clinic. When Yoast's Organization is the same business (same name), the clinic uses Yoast's
 * @id so the two merge into one entity instead of one being the other's parent. Filter: dacs_primary_clinic_id.
 */
function dacs_primary_clinic_id( $primary = null ) {
	$id    = trailingslashit( get_home_url() ) . dacs_location_fragment( $primary['location_fragment'] ?? '', '#main-clinic' );
	$yoast = dacs_yoast_organization_id();
	if ( $yoast && $primary ) {
		$titles = get_option( 'wpseo_titles' );
		if ( dacs_same_business_name( $titles['company_name'] ?? '', $primary['location_name'] ?? '' ) ) $id = $yoast;
	}
	return (string) apply_filters( 'dacs_primary_clinic_id', $id, $primary );
}

/** Phone for schema: US numbers become +1-502-339-2922 (the format Google asks for); anything else is kept as entered. */
function dacs_schema_phone( $phone ) {
	$d = preg_replace( '/\D/', '', (string) $phone );
	if ( strlen( $d ) === 11 && $d[0] === '1' ) $d = substr( $d, 1 );
	if ( strlen( $d ) === 10 ) return '+1-' . substr( $d, 0, 3 ) . '-' . substr( $d, 3, 3 ) . '-' . substr( $d, 6 );
	return trim( (string) $phone );
}

/** Provider display name for schema: the Provider name field, falling back to the post title. */
function dacs_provider_display_name( $provider_id ) {
	$name = function_exists( 'get_field' ) ? trim( (string) get_field( 'provider_name', $provider_id ) ) : '';
	return $name !== '' ? $name : get_the_title( $provider_id );
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
