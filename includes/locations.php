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

/*
 * Records what Yoast actually put in its schema graph for this request. Yoast prints the graph at wp_head
 * priority 1, so the fallbacks below (priority 5 and later) can rely on it. Nothing is recorded when Yoast's
 * Schema Framework is off or schema is disabled for the page. Yoast also leaves its Organization out of the
 * graph when the site representation has no logo, even with a company name set, so the Organization is
 * checked separately.
 */
add_filter( 'wpseo_schema_graph', function ( $graph ) {
	$GLOBALS['dacs_yoast_graph_printed'] = true;
	$org_id = dacs_organization_id();
	foreach ( (array) $graph as $piece ) {
		if ( is_array( $piece ) && ( $piece['@id'] ?? '' ) === $org_id ) {
			$GLOBALS['dacs_yoast_org_printed'] = true;
			break;
		}
	}
	return $graph;
}, PHP_INT_MAX );

/** Whether Yoast printed its schema graph on this page. Filter: dacs_yoast_graph_printed. */
function dacs_yoast_graph_printed() {
	return (bool) apply_filters( 'dacs_yoast_graph_printed', ! empty( $GLOBALS['dacs_yoast_graph_printed'] ) );
}

/** Whether Yoast's graph on this page contains the practice Organization. Filter: dacs_yoast_org_printed. */
function dacs_yoast_org_printed() {
	return (bool) apply_filters( 'dacs_yoast_org_printed', ! empty( $GLOBALS['dacs_yoast_org_printed'] ) );
}

/**
 * schema.org type for location entities. MedicalClinic is both a MedicalOrganization and a LocalBusiness, so
 * address, geo, opening hours, price range and payment details count for local search. Filter: dacs_schema_clinic_type.
 * ($is_primary is kept for filters written before 3.2.7; every location is now the same type.)
 */
function dacs_clinic_schema_type( $is_primary = false ) {
	return (string) apply_filters( 'dacs_schema_clinic_type', 'MedicalClinic', (bool) $is_primary );
}

/** schema.org type for the practice as a whole (not a single office). Filter: dacs_schema_organization_type. */
function dacs_organization_schema_type() {
	return (string) apply_filters( 'dacs_schema_organization_type', 'MedicalOrganization' );
}

/**
 * @id of the practice. Yoast's Organization when Yoast outputs one (the Schema module enriches that node),
 * otherwise the plugin's own home#organization node. Filter: dacs_organization_id.
 */
function dacs_organization_id() {
	$id = dacs_yoast_organization_id() ?: trailingslashit( get_home_url() ) . '#organization';
	return (string) apply_filters( 'dacs_organization_id', $id );
}

/** Practice name: Yoast's Organization name, else the site title. */
function dacs_organization_name() {
	$titles = get_option( 'wpseo_titles' );
	$name   = ( dacs_yoast_organization_id() && ! empty( $titles['company_name'] ) ) ? $titles['company_name'] : get_bloginfo( 'name' );
	return (string) apply_filters( 'dacs_organization_name', $name );
}

/** Reference to the practice for worksFor, publisher, provider and parentOrganization. */
function dacs_organization_ref( $with_name = false ) {
	$ref = array( '@type' => dacs_organization_schema_type(), '@id' => dacs_organization_id() );
	if ( $with_name ) { $ref['name'] = dacs_organization_name(); $ref['url'] = trailingslashit( get_home_url() ); }
	return $ref;
}

/**
 * Before 3.2.7 this was the primary clinic's @id. Providers, treatments and blog posts now point at the practice,
 * so it returns the organization @id. Kept (with its filter) for themes and snippets that call it.
 */
function dacs_primary_clinic_id( $primary = null ) {
	return (string) apply_filters( 'dacs_primary_clinic_id', dacs_organization_id(), $primary );
}

/** Raw ACF location rows (Clinic Settings → Locations). */
function dacs_location_rows() {
	$rows = function_exists( 'get_field' ) ? get_field( 'clinic_locations', 'option' ) : null;
	return is_array( $rows ) ? array_values( $rows ) : array();
}

/** @id of a location row: home URL + its fragment ("#dover-nh"). */
function dacs_location_id( $row ) {
	return trailingslashit( get_home_url() ) . dacs_location_fragment( $row['location_fragment'] ?? '', '#clinic' );
}

/** A location row's own page: its first "Display on Pages" entry, else its Booking Page URL. */
function dacs_location_url( $row ) {
	$pages = is_array( $row['location_pages'] ?? null ) ? $row['location_pages'] : array();
	if ( $pages ) {
		$first = is_object( $pages[0] ) ? $pages[0]->ID : (int) $pages[0];
		if ( $first ) return get_permalink( $first );
	}
	return (string) ( $row['booking_url'] ?? '' );
}

/** Short reference to a location, for department / practicesAt / workLocation. */
function dacs_location_ref( $row ) {
	return array( '@type' => dacs_clinic_schema_type(), '@id' => dacs_location_id( $row ), 'name' => (string) ( $row['location_name'] ?? '' ), 'url' => dacs_location_url( $row ) );
}

/** The row marked "Is Primary Location?" (its phone is the practice's main number), or null. */
function dacs_primary_location_row() {
	foreach ( dacs_location_rows() as $r ) if ( ! empty( $r['is_primary'] ) ) return $r;
	return null;
}

/**
 * Locations a provider practises at, from the Team post's "Practices at" field (location fragments).
 * Empty when the field is blank: the provider is then linked to the practice only, never to a guessed office.
 * Filter: dacs_provider_locations.
 */
function dacs_provider_locations( $provider_id ) {
	$picked = function_exists( 'get_field' ) ? get_field( 'practices_at', $provider_id ) : null;
	$picked = array_map( function ( $f ) { return ltrim( trim( (string) $f ), '/#' ); }, is_array( $picked ) ? $picked : ( $picked ? array( $picked ) : array() ) );
	$refs   = array();
	foreach ( dacs_location_rows() as $r ) {
		if ( in_array( ltrim( trim( (string) ( $r['location_fragment'] ?? '' ) ), '/#' ), $picked, true ) ) $refs[] = dacs_location_ref( $r );
	}
	return (array) apply_filters( 'dacs_provider_locations', $refs, $provider_id );
}

/**
 * How a provider links to the practice and its offices. worksFor is always the practice. Offices come from
 * "Practices at": practicesAt for IndividualPhysician (its schema.org property), workLocation for everyone else.
 */
function dacs_provider_links( $provider_id, $schema_type ) {
	$links = array( 'worksFor' => array( '@id' => dacs_organization_id() ) );
	$locs  = dacs_provider_locations( $provider_id );
	if ( $locs ) {
		$refs = array_map( function ( $l ) { return array( '@type' => $l['@type'], '@id' => $l['@id'] ); }, $locs );
		$links[ $schema_type === 'IndividualPhysician' ? 'practicesAt' : 'workLocation' ] = $refs;
	}
	return $links;
}

/** Non-empty trimmed lines of a textarea value. */
function dacs_lines( $raw ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ), 'strlen' ) );
}

/** Practice Logo (Clinic Settings → Global Schema Defaults) as a URL, whatever the field returns. */
function dacs_global_logo_url() {
	$logo = function_exists( 'get_field' ) ? get_field( 'global_clinic_logo', 'option' ) : '';
	if ( is_array( $logo ) ) $logo = $logo['url'] ?? '';
	elseif ( is_numeric( $logo ) ) $logo = wp_get_attachment_image_url( (int) $logo, 'full' );
	return (string) $logo;
}

/**
 * The practice as one MedicalOrganization: logo, profiles, main phone, area served, parent group, and every office as
 * a department. $base is Yoast's Organization piece when Yoast outputs one (its name, logo and profiles are kept).
 * Providers are listed as employees only when $with_employees (the homepage), to keep other pages light.
 * Filter: dacs_schema_organization.
 */
function dacs_schema_organization_node( $base = array(), $with_employees = false ) {
	$node = is_array( $base ) ? $base : array();
	$type = dacs_organization_schema_type();
	$cur  = isset( $node['@type'] ) ? (array) $node['@type'] : array();
	$cur  = array_values( array_diff( $cur, array( 'Organization' ) ) );
	$node['@type'] = $cur ? array_values( array_unique( array_merge( array( $type ), $cur ) ) ) : $type;
	$node['@id']   = dacs_organization_id();
	if ( empty( $node['name'] ) ) $node['name'] = dacs_organization_name();
	if ( empty( $node['url'] ) ) $node['url'] = trailingslashit( get_home_url() );

	$logo = dacs_global_logo_url();
	if ( $logo && empty( $node['logo'] ) ) $node['logo'] = $logo;
	if ( $logo && empty( $node['image'] ) ) $node['image'] = $logo;

	$same = array_merge( isset( $node['sameAs'] ) ? (array) $node['sameAs'] : array(), dacs_lines( function_exists( 'get_field' ) ? get_field( 'global_clinic_sameas', 'option' ) : '' ) );
	if ( $same ) $node['sameAs'] = array_values( array_unique( $same ) );

	$primary = dacs_primary_location_row();
	if ( $primary && empty( $node['telephone'] ) ) $node['telephone'] = dacs_schema_phone( $primary['phone'] ?? '' );
	$node['medicalSpecialty'] = 'https://schema.org/Dermatology';

	$rows  = dacs_location_rows();
	$areas = array();
	foreach ( $rows as $r ) $areas = array_merge( $areas, dacs_lines( $r['area_served'] ?? '' ) );
	if ( $areas ) $node['areaServed'] = array_values( array_unique( $areas ) );

	$parent_name = trim( (string) ( function_exists( 'get_field' ) ? get_field( 'global_clinic_parent_name', 'option' ) : '' ) );
	$parent_url  = trim( (string) ( function_exists( 'get_field' ) ? get_field( 'global_clinic_parent_url', 'option' ) : '' ) );
	if ( $parent_name !== '' ) $node['parentOrganization'] = array( '@type' => 'MedicalOrganization', 'name' => $parent_name, 'url' => $parent_url );

	foreach ( $rows as $r ) $node['department'][] = dacs_location_ref( $r );

	if ( $with_employees && function_exists( 'get_posts' ) ) {
		$type_pt = (string) apply_filters( 'dacs_provider_post_type', 'team' );
		if ( apply_filters( 'dacs_schema_clinic_employees', true ) ) {
			$ids = get_posts( array( 'post_type' => $type_pt, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order date', 'order' => 'ASC' ) );
			foreach ( $ids as $t_id ) {
				$t_type = function_exists( 'dacs_provider_schema_type' ) ? dacs_provider_schema_type( get_field( 'provider_type', $t_id ) ?: 'Person' ) : 'Person';
				$node['employee'][] = array_merge( array( '@type' => array_values( array_unique( array( 'Person', $t_type ) ) ), '@id' => get_permalink( $t_id ) . '#provider', 'name' => dacs_provider_display_name( $t_id ), 'jobTitle' => get_field( 'exact_job_title', $t_id ) ?: null, 'url' => get_permalink( $t_id ) ), dacs_provider_links( $t_id, $t_type ) );
			}
		}
	}
	$node = (array) apply_filters( 'dacs_schema_organization', $node, $with_employees );
	return function_exists( 'dacs_schema_prune' ) ? dacs_schema_prune( $node ) : $node;
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
