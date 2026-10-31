<?php
/**
 * Module: Blog Schema.
 * Gives blog posts a BlogPosting with a real medical author (a Team provider) instead of the WordPress user,
 * plus an optional medical reviewer on the WebPage (reviewedBy / lastReviewed).
 *
 * Author, in order: the post's "Written by" field → the provider named in a "by …" byline near the top
 * of the post → the clinic itself. Reviewer: only when "Medically reviewed by" is set.
 *
 * With Yoast SEO active the module edits Yoast's schema graph (one graph per page, no duplicates).
 * Without Yoast it prints its own WebPage + BlogPosting graph.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Post types treated as blog posts. Filter: dacs_blog_post_types. */
function dacs_blog_post_types() {
	return (array) apply_filters( 'dacs_blog_post_types', array( 'post' ) );
}

/** Post type that holds providers. Filter: dacs_provider_post_type. */
function dacs_provider_post_type() {
	return (string) apply_filters( 'dacs_provider_post_type', 'team' );
}

/** Reference to the primary clinic entity (same @id the Schema module uses). */
function dacs_blog_clinic_ref() {
	$fragment = '#main-clinic';
	$name     = get_bloginfo( 'name' );
	$locs     = function_exists( 'get_field' ) ? get_field( 'clinic_locations', 'option' ) : null;
	if ( is_array( $locs ) ) {
		foreach ( $locs as $loc ) {
			if ( ! empty( $loc['is_primary'] ) ) {
				$fragment = dacs_location_fragment( $loc['location_fragment'] ?? '', $fragment );
				$name     = $loc['location_name'] ?: $name;
				break;
			}
		}
	}
	$home = trailingslashit( get_home_url() );
	return array( '@type' => 'MedicalOrganization', '@id' => $home . $fragment, 'name' => $name, 'url' => $home );
}

/** Person node for a provider post, linked to the clinic. */
function dacs_blog_provider_node( $provider_id ) {
	$type  = function_exists( 'get_field' ) ? ( get_field( 'provider_type', $provider_id ) ?: 'Person' ) : 'Person';
	$stype = function_exists( 'dacs_provider_schema_type' ) ? dacs_provider_schema_type( $type ) : ( $type === 'Physician' ? 'IndividualPhysician' : $type );
	$clinic = dacs_blog_clinic_ref();
	$node  = array(
		'@type'    => array_values( array_unique( array( 'Person', $stype ) ) ),
		'@id'      => get_permalink( $provider_id ) . '#provider',
		'name'     => get_the_title( $provider_id ),
		'url'      => get_permalink( $provider_id ),
		'jobTitle' => function_exists( 'get_field' ) ? get_field( 'exact_job_title', $provider_id ) : '',
		'worksFor' => array( '@id' => $clinic['@id'] ),
	);
	if ( $type === 'Physician' ) $node['practicesAt'] = array( '@id' => $clinic['@id'] );
	$img = get_the_post_thumbnail_url( $provider_id, 'medium' );
	if ( $img ) $node['image'] = $img;
	$profiles = function_exists( 'get_field' ) ? get_field( 'provider_profiles_sameas', $provider_id ) : null;
	if ( is_array( $profiles ) ) {
		foreach ( $profiles as $pr ) {
			if ( ! empty( $pr['url'] ) ) $node['sameAs'][] = $pr['url'];
		}
	}
	return function_exists( 'dacs_schema_prune' ) ? dacs_schema_prune( $node ) : array_filter( $node );
}

/** Lower-case name words of a provider title: "Nicole “Coco” Lowenfield" → [nicole, coco, lowenfield]. */
function dacs_blog_name_words( $title ) {
	$name = strtolower( remove_accents( trim( preg_replace( '/,.*$/', '', wp_strip_all_tags( $title ) ) ) ) );
	$name = preg_replace( '/\b(dr|md|do|pa|np|faad|mpas)\b\.?/', ' ', $name );
	return array_values( array_filter( preg_split( '/[^a-z\-]+/', $name ), function ( $p ) { return strlen( $p ) > 1; } ) );
}

/** True when two name words match: equal, one a short form of the other (Becca / Rebecca), or a one-letter typo (Houk / Houck). */
function dacs_blog_word_match( $a, $b ) {
	if ( $a === $b ) return true;
	if ( strlen( $a ) >= 4 && strlen( $b ) >= 4 && ( substr( $a, -4 ) === substr( $b, -4 ) ) && ( strpos( $a, $b ) !== false || strpos( $b, $a ) !== false ) ) return true;
	return min( strlen( $a ), strlen( $b ) ) >= 4 && levenshtein( $a, $b ) <= 1;
}

/**
 * Provider credited at the top of a post, or 0. Recognises
 *   "By Shadi Damanpour, MD, FAAD …", "by Dr. Mary Hurley …", "by Becca Marcus, MD …", "by Coco Lowenfield …",
 *   "Acne Q&A with Rebecca Marcus, MD …", and posts that simply open with "Rebecca Marcus, MD, FAAD …".
 * A provider matches when the surname matches and so does a given name / nickname (or the byline starts with "Dr").
 */
function dacs_blog_detect_provider( $post ) {
	$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', html_entity_decode( wp_strip_all_tags( mb_substr( (string) $post->post_content, 0, 12000 ) ), ENT_QUOTES, 'UTF-8' ) ) );
	$head = mb_substr( $text, 0, 160 );
	if ( preg_match( '/^.{0,60}?\b(?:by|with)\s+(dr\.?\s+)?([^,|;:\n]{3,60})/iu', $head, $m ) ) {
		$is_dr  = $m[1] !== '';
		$byline = $m[2];
	} else {
		$is_dr  = false;
		$byline = mb_substr( $head, 0, 60 ); // post opens with the provider's name
	}
	$words = dacs_blog_name_words( $byline );
	if ( ! $words ) return 0;
	$words = array_slice( $words, 0, 5 );

	static $providers = null;
	if ( null === $providers ) {
		$providers = get_posts( array( 'post_type' => dacs_provider_post_type(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	}
	foreach ( $providers as $pid ) {
		$parts = dacs_blog_name_words( get_the_title( $pid ) );
		if ( count( $parts ) < 2 ) continue;
		$last   = end( $parts );
		$givens = array_slice( $parts, 0, -1 );
		$has_last = false; $has_given = false;
		foreach ( $words as $w ) {
			if ( dacs_blog_word_match( $w, $last ) ) { $has_last = true; continue; }
			foreach ( $givens as $g ) { if ( strlen( $g ) > 1 && dacs_blog_word_match( $w, $g ) ) $has_given = true; }
		}
		if ( $has_last && ( $has_given || $is_dr ) ) return (int) $pid;
	}
	return 0;
}

/** Author provider id (0 = the clinic) and reviewer id for a post. */
function dacs_blog_people( $post_id ) {
	$post     = get_post( $post_id );
	$author   = function_exists( 'get_field' ) ? (int) get_field( 'dacs_post_author', $post_id ) : 0;
	$reviewer = function_exists( 'get_field' ) ? (int) get_field( 'dacs_post_reviewer', $post_id ) : 0;
	if ( ! $author && $post ) $author = dacs_blog_detect_provider( $post );
	return array(
		'author'   => (int) apply_filters( 'dacs_blog_author', $author, $post_id ),
		'reviewer' => (int) apply_filters( 'dacs_blog_reviewer', $reviewer, $post_id ),
		'reviewed' => function_exists( 'get_field' ) ? (string) get_field( 'dacs_post_reviewed_date', $post_id ) : '',
	);
}

/** True when a Yoast piece has the given type (types may be a string or a list). */
function dacs_blog_piece_is( $piece, $types ) {
	$t = (array) ( $piece['@type'] ?? array() );
	return (bool) array_intersect( $t, (array) $types );
}

// --- Yoast SEO: edit its graph ---
add_filter( 'wpseo_schema_graph', function ( $graph, $context = null ) {
	if ( ! is_singular( dacs_blog_post_types() ) || ! is_array( $graph ) ) return $graph;
	$post_id = get_queried_object_id();
	$people  = dacs_blog_people( $post_id );
	$clinic  = dacs_blog_clinic_ref();

	$author_ref = $people['author'] ? array( '@id' => get_permalink( $people['author'] ) . '#provider' ) : array( '@id' => $clinic['@id'] );
	$old_author = null;

	foreach ( $graph as &$piece ) {
		if ( dacs_blog_piece_is( $piece, array( 'Article', 'BlogPosting' ) ) ) {
			$old_author        = $piece['author']['@id'] ?? null;
			$piece['@type']    = 'BlogPosting';
			$piece['author']   = $author_ref;
			if ( empty( $piece['publisher'] ) ) $piece['publisher'] = array( '@id' => $clinic['@id'] );
		}
		if ( dacs_blog_piece_is( $piece, 'WebPage' ) && $people['reviewer'] ) {
			$piece['reviewedBy']   = array( '@id' => get_permalink( $people['reviewer'] ) . '#provider' );
			$piece['lastReviewed'] = $people['reviewed'] ? gmdate( 'Y-m-d', strtotime( $people['reviewed'] ) ) : get_the_modified_date( 'Y-m-d', $post_id );
		}
	}
	unset( $piece );

	// Drop the WordPress user's Person piece that Yoast added for the old author.
	if ( $old_author && $old_author !== $author_ref['@id'] ) {
		$graph = array_values( array_filter( $graph, function ( $p ) use ( $old_author ) {
			return ! ( ( $p['@id'] ?? '' ) === $old_author && dacs_blog_piece_is( $p, 'Person' ) );
		} ) );
	}

	// Add the provider / clinic nodes the references point to.
	$ids = wp_list_pluck( $graph, '@id' );
	foreach ( array_unique( array_filter( array( $people['author'], $people['reviewer'] ) ) ) as $pid ) {
		$node = dacs_blog_provider_node( $pid );
		if ( ! in_array( $node['@id'], $ids, true ) ) $graph[] = $node;
	}
	if ( ! in_array( $clinic['@id'], $ids, true ) ) $graph[] = $clinic;

	return $graph;
}, 20, 2 );

// "Written by" in Yoast's Slack / X preview data.
add_filter( 'wpseo_enhanced_slack_data', function ( $data ) {
	if ( ! is_singular( dacs_blog_post_types() ) ) return $data;
	$people = dacs_blog_people( get_queried_object_id() );
	if ( $people['author'] ) {
		$data[ __( 'Written by', 'wordpress-seo' ) ] = get_the_title( $people['author'] );
	} else {
		$data[ __( 'Written by', 'wordpress-seo' ) ] = dacs_blog_clinic_ref()['name'];
	}
	return $data;
} );

// --- No Yoast: print our own graph ---
add_action( 'wp_head', function () {
	if ( defined( 'WPSEO_VERSION' ) || ! is_singular( dacs_blog_post_types() ) ) return;
	$post_id = get_queried_object_id();
	$people  = dacs_blog_people( $post_id );
	$clinic  = dacs_blog_clinic_ref();
	$url     = get_permalink( $post_id );

	$page = array( '@type' => 'WebPage', '@id' => $url, 'url' => $url, 'name' => get_the_title( $post_id ) );
	if ( $people['reviewer'] ) {
		$page['reviewedBy']   = array( '@id' => get_permalink( $people['reviewer'] ) . '#provider' );
		$page['lastReviewed'] = $people['reviewed'] ? gmdate( 'Y-m-d', strtotime( $people['reviewed'] ) ) : get_the_modified_date( 'Y-m-d', $post_id );
	}
	$article = array(
		'@type'            => 'BlogPosting',
		'@id'              => $url . '#article',
		'headline'         => wp_strip_all_tags( get_the_title( $post_id ) ),
		'description'      => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
		'datePublished'    => get_the_date( 'c', $post_id ),
		'dateModified'     => get_the_modified_date( 'c', $post_id ),
		'image'            => get_the_post_thumbnail_url( $post_id, 'full' ),
		'mainEntityOfPage' => array( '@id' => $url ),
		'isPartOf'         => array( '@id' => $url ),
		'author'           => $people['author'] ? array( '@id' => get_permalink( $people['author'] ) . '#provider' ) : array( '@id' => $clinic['@id'] ),
		'publisher'        => array( '@id' => $clinic['@id'] ),
		'articleSection'   => wp_list_pluck( get_the_category( $post_id ), 'name' ),
	);
	$graph = array( $page, $article, $clinic );
	foreach ( array_unique( array_filter( array( $people['author'], $people['reviewer'] ) ) ) as $pid ) {
		$graph[] = dacs_blog_provider_node( $pid );
	}
	if ( function_exists( 'dacs_print_schema' ) ) {
		dacs_print_schema( array( '@context' => 'https://schema.org', '@graph' => $graph ) );
	} else {
		echo "\n<script type='application/ld+json'>" . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES ) . "</script>\n";
	}
}, 5 );
