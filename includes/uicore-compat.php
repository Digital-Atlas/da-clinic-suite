<?php
/**
 * UiCore Blocks: let non-admins save posts in the block editor.
 *
 * TEMPORARY — remove once UiCore Blocks fixes the permission check upstream.
 *
 * UiCore Blocks (checked against 1.0.15) sends each post's generated CSS to POST /uicore-blocks/v1/save-styles
 * just before the block editor saves the post. That route uses the plugin's shared check,
 * current_user_can( 'manage_options' ), so for editors and authors it returns 403 and UiCore cancels the save:
 * "Failed to update UiCore Blocks Assets." and "Publishing failed. Sorry, you are not allowed to do that."
 * Every save fails, including drafts and posts with no UiCore blocks.
 *
 * This file leaves UiCore's own check in place and adds a fallback for that one route only:
 * - a post ID: allowed if the user can edit that post (edit_post);
 * - a widget area (non-numeric ID): allowed if the user can edit_theme_options.
 * UiCore prints the saved CSS unescaped inside <style> tags, so for users without unfiltered_html the
 * request is cleaned first: "<" is removed from the CSS and font data and preload image URLs are
 * sanitised, so the route can't be used to add markup to a page.
 *
 * Turn off on a site with: add_filter( 'dacs_uicore_save_styles_fix', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const DACS_UICORE_SAVE_STYLES_ROUTE = '/uicore-blocks/v1/save-styles';

/** Can the current user save UiCore styles for this target (post ID or widget area)? */
function dacs_uicore_can_save_styles( $target_id ) {
	if ( is_numeric( $target_id ) && (int) $target_id > 0 ) {
		return current_user_can( 'edit_post', (int) $target_id );
	}
	return current_user_can( 'edit_theme_options' );
}

/** Remove "<" from every string in a nested array (CSS never needs it; it is how </style> breakouts start). */
function dacs_uicore_strip_markup( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $k => $v ) {
			$clean[ is_string( $k ) ? str_replace( '<', '', $k ) : $k ] = dacs_uicore_strip_markup( $v );
		}
		return $clean;
	}
	return is_string( $value ) ? str_replace( '<', '', $value ) : $value;
}

add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( empty( $endpoints[ DACS_UICORE_SAVE_STYLES_ROUTE ] ) || ! apply_filters( 'dacs_uicore_save_styles_fix', true ) ) {
		return $endpoints;
	}
	foreach ( $endpoints[ DACS_UICORE_SAVE_STYLES_ROUTE ] as &$handler ) {
		if ( ! is_array( $handler ) || empty( $handler['permission_callback'] ) ) continue;
		$original = $handler['permission_callback'];
		$handler['permission_callback'] = function ( $request ) use ( $original ) {
			$allowed = is_callable( $original ) ? call_user_func( $original, $request ) : false;
			if ( true === $allowed ) return true;
			return dacs_uicore_can_save_styles( $request->get_param( 'post_id' ) ) ? true : $allowed;
		};
	}
	unset( $handler );
	return $endpoints;
}, 99 );

/** Runs after the permission check and before UiCore's callback: clean the payload for users without unfiltered_html. */
add_filter( 'rest_request_before_callbacks', function ( $response, $handler, $request ) {
	if ( is_wp_error( $response ) || DACS_UICORE_SAVE_STYLES_ROUTE !== $request->get_route() ) return $response;
	if ( ! apply_filters( 'dacs_uicore_save_styles_fix', true ) || current_user_can( 'unfiltered_html' ) ) return $response;

	foreach ( array( 'styles', 'fonts' ) as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value ) $request->set_param( $key, dacs_uicore_strip_markup( $value ) );
	}
	$images = $request->get_param( 'preloadImages' );
	if ( is_array( $images ) ) {
		$clean = array();
		foreach ( $images as $img ) {
			if ( ! is_array( $img ) || empty( $img['src'] ) ) continue;
			$src = esc_url_raw( (string) $img['src'] );
			if ( '' === $src ) continue;
			$entry = array( 'src' => $src );
			if ( isset( $img['id'] ) ) $entry['id'] = (int) $img['id'];
			if ( ! empty( $img['isBg'] ) ) $entry['isBg'] = true;
			$clean[] = $entry;
		}
		$request->set_param( 'preloadImages', $clean );
	}
	return $response;
}, 10, 3 );
