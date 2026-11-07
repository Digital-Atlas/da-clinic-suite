<?php
/**
 * Module: User Privacy.
 * Keeps WordPress login names private. By default WordPress publishes them in three places:
 *
 *  1. The REST API user list (/wp-json/wp/v2/users, also reachable as ?rest_route=/wp/v2/users).
 *  2. ?author=N, which redirects to /author/<login-name>/.
 *  3. oEmbed responses, which include the post author's name and author URL.
 *
 * Logged-in users who can edit posts keep full access, so the block editor's author picker still works.
 * Application-password requests (MCP connectors, integrations) are authenticated before these checks
 * run, so they keep access too when their user can edit posts.
 *
 * Each part can be switched off on its own with the filters below.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** True when the current request should see user data. */
function dacs_privacy_can_view_users() {
	return (bool) apply_filters( 'dacs_privacy_can_view_users', current_user_can( 'edit_posts' ) );
}

// 1. REST API user list.
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( ! apply_filters( 'dacs_privacy_block_rest_users', true ) ) return $result;
	if ( 0 !== strpos( $request->get_route(), '/wp/v2/users' ) ) return $result;
	if ( dacs_privacy_can_view_users() ) return $result;

	return new WP_Error(
		'rest_user_cannot_view',
		__( 'Sorry, you are not allowed to list users.' ),
		array( 'status' => rest_authorization_required_code() )
	);
}, 10, 3 );

// 2. ?author=N lookups. Author archives at /author/<slug>/ are left alone, so sites that use them keep working.
add_action( 'template_redirect', function () {
	if ( ! apply_filters( 'dacs_privacy_block_author_scan', true ) ) return;
	if ( ! isset( $_GET['author'] ) || dacs_privacy_can_view_users() ) return; // phpcs:ignore WordPress.Security.NonceVerification
	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}, 1 );

// 3. oEmbed author name and URL.
add_filter( 'oembed_response_data', function ( $data ) {
	if ( ! apply_filters( 'dacs_privacy_strip_oembed_author', true ) ) return $data;
	unset( $data['author_name'], $data['author_url'] );
	return $data;
} );
