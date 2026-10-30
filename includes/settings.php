<?php
/**
 * Clinic Settings options page and ACF field groups.
 *  - "Clinic Suite" group (this file): module switches, Self-Scheduling and HIPAA Map settings.
 *  - acf-fields.json: clinic locations + global clinic data, and the provider / condition / procedure EEAT groups.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Options page slug. Kept from the old "Clinic Schema" page so saved values and links carry over. */
const DACS_OPTIONS_SLUG = 'clinic-schema-settings';

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_page' ) ) return;
	acf_add_options_page( array(
		'page_title' => 'Clinic Settings',
		'menu_title' => 'Clinic Settings',
		'menu_slug'  => DACS_OPTIONS_SLUG,
		'capability' => 'edit_posts',
		'redirect'   => false,
		'icon_url'   => 'dashicons-admin-multisite',
	) );
} );

/** Small builders so the field definitions below stay readable. */
function dacs_f( $key, $name, $label, $type, $extra = array() ) {
	return array_merge( array( 'key' => 'field_dacs_' . $key, 'name' => $name, 'label' => $label, 'type' => $type, 'instructions' => '', 'required' => 0, 'wrapper' => array( 'width' => '', 'class' => '', 'id' => '' ) ), $extra );
}
function dacs_toggle( $key, $label, $instructions ) {
	return dacs_f( $key, 'dacs_enable_' . $key, $label, 'true_false', array( 'instructions' => $instructions, 'ui' => 1, 'ui_on_text' => 'Enabled', 'ui_off_text' => 'Disabled', 'default_value' => 1, 'wrapper' => array( 'width' => '33', 'class' => '', 'id' => '' ) ) );
}
function dacs_tab( $key, $label ) {
	return dacs_f( 'tab_' . $key, '', $label, 'tab', array( 'placement' => 'top', 'endpoint' => 0 ) );
}

add_action( 'acf/include_fields', function () {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

	acf_add_local_field_group( array(
		'key'        => 'group_dacs_suite',
		'title'      => 'Clinic Suite',
		'menu_order' => -10,
		'position'   => 'normal',
		'style'      => 'default',
		'location'   => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => DACS_OPTIONS_SLUG ) ) ),
		'fields'     => array(
			dacs_tab( 'modules', 'Modules' ),
			dacs_toggle( 'schema', 'Enable / Disable: Schema', 'JSON-LD for the clinic, locations, providers, conditions and procedures.' ),
			dacs_toggle( 'map', 'Enable / Disable: HIPAA Map', 'Freshpaint map for [freshpaint_map], built from the clinic locations below.' ),
			dacs_toggle( 'scheduling', 'Enable / Disable: Self-Scheduling', 'Phreesia booking widget for [phreesia_widget].' ),
			dacs_toggle( 'blog', 'Enable / Disable: Blog Schema', 'BlogPosting schema for blog posts, with a provider as author and an optional medical reviewer.' ),

			dacs_tab( 'scheduling', 'Self-Scheduling' ),
			// Same key and name as the old "Phreesia Connections" field, so the saved URL carries over untouched.
			array( 'key' => 'field_6806c96ce4273', 'name' => 'phreesia_url', 'label' => 'Phreesia URL', 'type' => 'url', 'instructions' => 'Embedded self-scheduling URL from Phreesia. A location can override this with its own Phreesia URL. Shortcode: [phreesia_widget] or [phreesia_widget location="2"].', 'required' => 0, 'wrapper' => array( 'width' => '', 'class' => '', 'id' => '' ) ),
			dacs_f( 'sched_height', 'dacs_sched_height', 'Starting Height (px)', 'number', array( 'instructions' => 'Height before Phreesia reports its own size.', 'default_value' => 300, 'min' => 100, 'wrapper' => array( 'width' => '33', 'class' => '', 'id' => '' ) ) ),

			dacs_tab( 'map', 'HIPAA Map' ),
			dacs_f( 'map_msg', '', 'Locations', 'message', array( 'message' => 'Map markers come from <strong>Locations</strong> below: name, address, phone, hours, coordinates (Latitude / Longitude) and Booking Page URL. Shortcode: <code>[freshpaint_map]</code>. Options: <code>height="600px" show_cards="true" focus="2" color="#307a99"</code>.' ) ),
			dacs_f( 'map_zoom', 'dacs_map_zoom', 'Zoom Level', 'number', array( 'default_value' => 10, 'min' => 1, 'max' => 20, 'wrapper' => array( 'width' => '25', 'class' => '', 'id' => '' ) ) ),
			dacs_f( 'map_zoom_on_click', 'dacs_map_zoom_on_click', 'Zoom on Click', 'number', array( 'instructions' => 'When a location card is clicked or focused.', 'default_value' => 14, 'min' => 1, 'max' => 22, 'wrapper' => array( 'width' => '25', 'class' => '', 'id' => '' ) ) ),
			dacs_f( 'map_marker_color', 'dacs_map_marker_color', 'Marker Colour', 'color_picker', array( 'default_value' => '#307a99', 'wrapper' => array( 'width' => '25', 'class' => '', 'id' => '' ) ) ),
			dacs_f( 'map_height', 'dacs_map_height', 'Map Height', 'text', array( 'default_value' => '500px', 'wrapper' => array( 'width' => '25', 'class' => '', 'id' => '' ) ) ),
			dacs_f( 'map_show_cards', 'dacs_map_show_cards', 'Show Location List', 'true_false', array( 'instructions' => 'List of locations beside the map. Clicking one flies to it.', 'ui' => 1, 'default_value' => 1 ) ),
			dacs_f( 'map_link_text', 'dacs_map_link_text', 'Booking Link Text', 'text', array( 'default_value' => 'Book Appointment' ) ),
		),
	) );

	// Blog author / reviewer fields (Blog Schema module).
	if ( dacs_module_enabled( 'blog' ) ) {
		$provider_pt = (string) apply_filters( 'dacs_provider_post_type', 'team' );
		$locations   = array();
		foreach ( (array) apply_filters( 'dacs_blog_post_types', array( 'post' ) ) as $pt ) {
			$locations[] = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $pt ) );
		}
		$who = array( 'post_type' => array( $provider_pt ), 'post_status' => array( 'publish' ), 'return_format' => 'id', 'allow_null' => 1, 'multiple' => 0, 'ui' => 1 );
		acf_add_local_field_group( array(
			'key'          => 'group_dacs_blog',
			'title'        => 'Author & Medical Review',
			'position'     => 'side',
			'style'        => 'default',
			'location'     => $locations,
			'show_in_rest' => 1,
			'fields'       => array(
				dacs_f( 'post_author', 'dacs_post_author', 'Written by', 'post_object', array_merge( $who, array( 'instructions' => 'Provider shown as the author in search results. Leave empty to use the "by …" byline in the post, or the clinic.' ) ) ),
				dacs_f( 'post_reviewer', 'dacs_post_reviewer', 'Medically reviewed by', 'post_object', array_merge( $who, array( 'instructions' => 'Only set this when the provider has actually reviewed the post.' ) ) ),
				dacs_f( 'post_reviewed_date', 'dacs_post_reviewed_date', 'Review date', 'date_picker', array( 'instructions' => 'Defaults to the last modified date.', 'display_format' => 'F j, Y', 'return_format' => 'Y-m-d', 'first_day' => 0 ) ),
			),
		) );
	}

	// Clinic locations + EEAT groups, shipped with the plugin so every site gets the same fields on update.
	if ( ! apply_filters( 'dacs_load_acf_json', true ) ) return;
	$file = DACS_DIR . 'acf-fields.json';
	if ( ! is_readable( $file ) ) return;
	$groups = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $groups ) ) return;

	// Sites whose condition / procedure pages are not the `treatment` post type (e.g. NDDA uses `services`).
	$treatment_pt = (string) apply_filters( 'dacs_treatment_post_type', 'treatment' );
	$retarget     = function ( array $fields ) use ( &$retarget, $treatment_pt ) {
		foreach ( $fields as &$f ) {
			if ( ! empty( $f['post_type'] ) && is_array( $f['post_type'] ) ) {
				$f['post_type'] = array_values( array_unique( array_map( function ( $t ) use ( $treatment_pt ) { return $t === 'treatment' ? $treatment_pt : $t; }, $f['post_type'] ) ) );
			}
			if ( ! empty( $f['sub_fields'] ) ) $f['sub_fields'] = $retarget( $f['sub_fields'] );
		}
		return $fields;
	};

	$schema_on = dacs_module_enabled( 'schema' );
	foreach ( $groups as $g ) {
		$is_clinic = ( $g['key'] ?? '' ) === 'group_clinic_multi_location_settings';
		if ( ! $is_clinic && ! $schema_on ) continue; // provider / condition / procedure fields only matter for schema
		foreach ( $g['location'] ?? array() as $i => $or ) {
			foreach ( $or as $j => $rule ) {
				if ( $rule['param'] === 'post_type' && $rule['value'] === 'treatment' ) $g['location'][ $i ][ $j ]['value'] = $treatment_pt;
			}
		}
		$g['fields']       = $retarget( $g['fields'] ?? array() );
		$g['show_in_rest'] = 1;
		acf_add_local_field_group( $g );
	}
} );

/** Read a Clinic Suite option with a default when it has never been saved. */
function dacs_opt( $name, $default ) {
	$v = function_exists( 'get_field' ) ? get_field( $name, 'option' ) : get_option( 'options_' . $name, null );
	return ( $v === null || $v === '' ) ? $default : $v; // false / 0 are real answers (e.g. a switch turned off)
}
