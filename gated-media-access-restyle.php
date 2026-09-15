<?php
/**
 * Plugin Name:       Gated Media Access: Restyle
 * Plugin URI:        https://github.com/gin0115/gated-media-access-restyle
 * Description:       Rebuilds the Gated Media Access components with its filters, core's block filters and CSS. The theme is left alone.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            Glynn Quelch
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       gated-media-access-restyle
 *
 * @package PinkCrab\Gated_Access_Restyle
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access_Restyle;

use PinkCrab\Gated_Access\Account\Section;
use PinkCrab\Gated_Access\Account\Section_Collection;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Support\Account_Url;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/render.php';
require_once __DIR__ . '/overview.php';

add_action(
	'plugins_loaded',
	static function (): void {
		// Gated Media Access only boots beside restrict-media-file-access, so neither do we.
		if ( ! defined( 'GATEDMEDIA_VERSION' ) || ! defined( 'RESTRICT_MEDIA_FILE_ACCESS_BASENAME' ) ) {
			return;
		}

		add_action( 'init', __NAMESPACE__ . '\\add_css', 20 );
		add_action( 'init', __NAMESPACE__ . '\\register_overview_block', 20 );

		// Each component rebuilt after it renders. See render.php.
		add_filter( 'render_block_gated-media-access/row', __NAMESPACE__ . '\\render_row', 10, 2 );
		add_filter( 'render_block_gated-media-access/account-nav', __NAMESPACE__ . '\\render_nav', 10, 2 );
		add_filter( 'render_block_gated-media-access/section-heading', __NAMESPACE__ . '\\render_heading', 10, 2 );
		add_filter( 'render_block_gated-media-access/expiry', __NAMESPACE__ . '\\render_expiry', 10, 2 );
		add_filter( 'render_block_gated-media-access/status-pill', __NAMESPACE__ . '\\render_pill', 10, 2 );
		add_filter( 'render_block_gated-media-access/empty-state', __NAMESPACE__ . '\\render_empty', 10, 2 );
		add_filter( 'render_block_gated-media-access/field', __NAMESPACE__ . '\\render_field', 10, 2 );

		// Gated Media Access filters.
		add_filter( 'gatedmedia_account_sections', __NAMESPACE__ . '\\sections' );
		add_filter( 'gatedmedia_my_access_data', __NAMESPACE__ . '\\soonest_first', 20 );
		add_filter( 'gatedmedia_expiry_soon_days', static fn (): int => 14 );
	},
	20
);

/**
 * styles.css, inlined after the plugin's own so it loads wherever the blocks do.
 */
function add_css(): void {
	$css = file_get_contents( __DIR__ . '/styles.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.

	if ( false !== $css ) {
		wp_add_inline_style( Asset_Loader::FRONT_STYLE, $css );
	}
}

/**
 * Adds the Overview section. Position 5 puts it first, so the account area lands on it.
 *
 * @param Section_Collection $sections The sections so far.
 */
function sections( Section_Collection $sections ): Section_Collection {
	return $sections->add(
		new Section(
			slug:       'overview',
			title:      __( 'Overview', 'gated-media-access-restyle' ),
			menu_label: __( 'Overview', 'gated-media-access-restyle' ),
			block:      'gatedmedia-restyle/overview',
			position:   5,
			icon:       'i-groups',
		)
	);
}

/**
 * Whatever runs out soonest comes first, then by title.
 *
 * @param array<string, mixed> $data The My Access data.
 * @return array<string, mixed>
 */
function soonest_first( array $data ): array {
	$rank = array(
		'soon'     => 0,
		'dated'    => 1,
		'lifetime' => 2,
	);

	foreach ( array( 'groups', 'posts', 'files' ) as $list ) {
		if ( ! is_array( $data[ $list ] ?? null ) ) {
			continue;
		}

		usort(
			$data[ $list ],
			static fn ( array $a, array $b ): int => ( ( $rank[ $a['expiry_state'] ?? '' ] ?? 1 ) <=> ( $rank[ $b['expiry_state'] ?? '' ] ?? 1 ) )
				?: strcmp( (string) $a['title'], (string) $b['title'] )
		);
	}

	return $data;
}

/**
 * What the person holds, from the plugin's own filter. Once per request.
 *
 * @return array<string, mixed>
 */
function held(): array {
	static $held = null;

	$held ??= (array) apply_filters(
		'gatedmedia_my_access_data',
		array(
			'groups' => array(),
			'posts'  => array(),
			'files'  => array(),
			'detail' => null,
		),
		''
	);

	return $held;
}

/**
 * How many of each thing the person has, for the badges and the Overview. Once per request.
 *
 * @return array{groups: int, posts: int, files: int, available: int, past: int, orders: int}
 */
function counts(): array {
	static $counts = null;

	if ( null !== $counts ) {
		return $counts;
	}

	$held   = held();
	$files  = (array) apply_filters(
		'gatedmedia_files_data',
		array(
			'available'   => array(),
			'downloading' => array(),
			'past'        => array(),
		)
	);
	$orders = (array) apply_filters(
		'gatedmedia_orders_data',
		array(
			'orders' => array(),
			'detail' => null,
		),
		''
	);

	$counts = array(
		'groups'    => count( (array) ( $held['groups'] ?? array() ) ),
		'posts'     => count( (array) ( $held['posts'] ?? array() ) ),
		'files'     => count( (array) ( $held['files'] ?? array() ) ),
		'available' => count( (array) ( $files['available'] ?? array() ) ),
		'past'      => count( (array) ( $files['past'] ?? array() ) ),
		'orders'    => count( (array) ( $orders['orders'] ?? array() ) ),
	);

	return $counts;
}

/**
 * The count shown against each nav link, keyed by the link.
 *
 * @return array<string, int>
 */
function nav_counts(): array {
	$counts = counts();

	return array(
		Account_Url::section( 'my-access' ) => $counts['groups'] + $counts['posts'] + $counts['files'],
		Account_Url::section( 'files' )     => $counts['available'],
		Account_Url::section( 'orders' )    => $counts['orders'],
	);
}

/**
 * The count shown against a section heading, by its text, or null for none.
 *
 * @param string $text The heading text.
 */
function heading_count( string $text ): ?int {
	$counts = counts();

	$map = array(
		__( 'Groups', 'gated-media-access' )      => $counts['groups'],
		__( 'Posts', 'gated-media-access' )       => $counts['posts'],
		__( 'Files', 'gated-media-access' )       => $counts['files'],
		__( 'Available', 'gated-media-access' )   => $counts['available'],
		__( 'Past access', 'gated-media-access' ) => $counts['past'],
	);

	return $map[ $text ] ?? null;
}

/**
 * Up to two initials for a name.
 *
 * @param string $name The name.
 */
function initials( string $name ): string {
	$words = preg_split( '/\s+/', trim( $name ) );
	$first = is_array( $words ) ? array_slice( array_filter( $words ), 0, 2 ) : array();

	return mb_strtoupper( implode( '', array_map( static fn ( string $word ): string => mb_substr( $word, 0, 1 ), $first ) ) );
}
