<?php
/**
 * Updates from GitHub Releases (Plugin Update Checker v5).
 *
 * WordPress checks about every 12 hours, or right away via "Check for updates" on the Plugins screen.
 * A new version is offered when the repo has a published Release whose tag (e.g. v3.0.1) is higher than
 * the installed Version, and the update installs that release's attached da-clinic-suite.zip.
 *
 * Optional, in wp-config.php:
 *   define( 'DACS_UPDATE_REPO', 'https://github.com/owner/repo/' );  // a different repo
 *   define( 'DACS_GITHUB_TOKEN', 'github_pat_…' );                    // only if the repo is private
 *   define( 'DACS_DISABLE_UPDATES', true );                           // opt a site out
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'DACS_DISABLE_UPDATES' ) && DACS_DISABLE_UPDATES ) return;

$dacs_puc = DACS_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( ! is_readable( $dacs_puc ) ) return;
require_once $dacs_puc;

$dacs_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	defined( 'DACS_UPDATE_REPO' ) ? DACS_UPDATE_REPO : 'https://github.com/Digital-Atlas/da-clinic-suite/',
	DACS_FILE,
	'da-clinic-suite'
);

// Install the release's built zip (folder named da-clinic-suite/), not GitHub's source archive.
$dacs_update_checker->getVcsApi()->enableReleaseAssets( '/da-clinic-suite\.zip($|[?&#])/i' );

if ( defined( 'DACS_GITHUB_TOKEN' ) && DACS_GITHUB_TOKEN ) {
	$dacs_update_checker->setAuthentication( DACS_GITHUB_TOKEN );
}

unset( $dacs_puc );
