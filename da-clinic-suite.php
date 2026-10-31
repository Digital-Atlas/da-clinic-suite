<?php
/**
 * Plugin Name: Digital Atlas Clinic Suite
 * Plugin URI:  https://github.com/Digital-Atlas/da-clinic-suite
 * Description: Clinic Settings, EEAT medical schema, blog author schema, HIPAA-compliant Freshpaint map and Phreesia self-scheduling in one plugin. Each module can be switched on or off under Clinic Settings.
 * Version:     3.2.3
 * Author:      Digital Atlas
 * Requires PHP: 7.4
 * Text Domain: da-clinic-suite
 *
 * Replaces: Dermatology EEAT Master Authority Schema (v2.8), Freshpaint Map Shortcode + Admin (v2.1),
 *           Phreesia Self-Scheduling Widget (v2.4).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DACS_VERSION', '3.2.3' );
define( 'DACS_FILE', __FILE__ );
define( 'DACS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DACS_URL', plugin_dir_url( __FILE__ ) );

/** Global Freshpaint HIPAA map environment (shared across all Digital Atlas sites). */
define( 'DACS_FRESHPAINT_ENV_ID', '52f89337-f80d-4c4a-8365-0894128c1077' );

/** Asset version: plugin version + file time, so browsers and page caches pick up changed files. */
function dacs_asset_ver( $file ) {
	$path = DACS_DIR . 'assets/' . $file;
	return DACS_VERSION . ( is_readable( $path ) ? '.' . filemtime( $path ) : '' );
}

require_once DACS_DIR . 'includes/settings.php';
require_once DACS_DIR . 'includes/locations.php';
require_once DACS_DIR . 'includes/migrate.php';
require_once DACS_DIR . 'includes/updater.php';

/**
 * The legacy plugin each module replaces, and a function that only exists when that plugin is active.
 * If the legacy plugin is still active the module stays off (no duplicate schema / shortcode clash).
 */
function dacs_modules() {
	return array(
		'schema'     => array( 'label' => 'Schema',          'file' => 'modules/schema.php',     'legacy_fn' => 'gd_inject_eeat_schema',   'legacy' => 'Dermatology EEAT Master Authority Schema' ),
		'map'        => array( 'label' => 'HIPAA Map',       'file' => 'modules/map.php',        'legacy_fn' => 'freshpaint_map_shortcode', 'legacy' => 'Freshpaint Map Shortcode + Admin' ),
		'scheduling' => array( 'label' => 'Self-Scheduling', 'file' => 'modules/scheduling.php', 'legacy_fn' => '_phreesia_widget',         'legacy' => 'Phreesia Self-Scheduling Widget' ),
		'blog'       => array( 'label' => 'Blog Schema',     'file' => 'modules/blog.php',       'legacy_fn' => '',                         'legacy' => '' ),
	);
}

/** On/off state from Clinic Settings → Modules. A module that has never been saved counts as on. */
function dacs_module_enabled( $module ) {
	$v       = get_option( 'options_dacs_enable_' . $module, null );
	$enabled = ( $v === null || $v === false || $v === '' ) ? true : (bool) (int) $v;
	return (bool) apply_filters( 'dacs_module_enabled', $enabled, $module );
}

/** Modules blocked because their legacy plugin is still active. Filled on plugins_loaded. */
$GLOBALS['dacs_blocked_modules'] = array();

add_action( 'plugins_loaded', function () {
	foreach ( dacs_modules() as $key => $m ) {
		if ( ! dacs_module_enabled( $key ) ) continue;
		if ( $m['legacy_fn'] && function_exists( $m['legacy_fn'] ) ) {
			$GLOBALS['dacs_blocked_modules'][ $key ] = $m;
			continue;
		}
		require_once DACS_DIR . $m['file'];
	}
}, 20 );

/** Disabled modules still answer their shortcodes with nothing, so pages never show raw [shortcode] text. */
add_action( 'init', function () {
	foreach ( array( 'freshpaint_map' => 'map', 'phreesia_widget' => 'scheduling' ) as $tag => $module ) {
		if ( ! shortcode_exists( $tag ) ) {
			add_shortcode( $tag, function () use ( $module ) {
				return current_user_can( 'manage_options' ) ? '<!-- Clinic Suite: ' . esc_html( $module ) . ' module is off -->' : '';
			} );
		}
	}
}, 99 );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		echo '<div class="notice notice-error"><p><strong>Digital Atlas Clinic Suite</strong> needs Advanced Custom Fields PRO (options pages and repeaters).</p></div>';
	}
	foreach ( $GLOBALS['dacs_blocked_modules'] as $m ) {
		printf(
			'<div class="notice notice-warning"><p><strong>Clinic Suite:</strong> the %1$s module is paused because the old <em>%2$s</em> plugin is still active. Deactivate that plugin to switch the module on.</p></div>',
			esc_html( $m['label'] ),
			esc_html( $m['legacy'] )
		);
	}
} );

register_activation_hook( __FILE__, function () {
	update_option( 'dacs_needs_migration', 1 );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=clinic-schema-settings' ) ) . '">Settings</a>' );
	return $links;
} );
