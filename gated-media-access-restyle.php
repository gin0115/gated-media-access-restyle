<?php
/**
 * Plugin Name:       Gated Media Access: Restyle
 * Plugin URI:        https://github.com/Pink-Crab/PinkCrab-Gated-Media-Access-Plugin
 * Description:       Restyles the Gated Media Access components with its filters, core's block filters and CSS. The theme is left alone.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            Glynn Quelch
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package PinkCrab\Gated_Access_Restyle
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access_Restyle;

use WP_HTML_Tag_Processor;
use PinkCrab\Gated_Access\Account\Section;
use PinkCrab\Gated_Access\Account\Section_Collection;
use PinkCrab\Gated_Access\Assets\Asset_Loader;

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function (): void {
		// Gated Media Access only boots beside restrict-media-file-access, so neither do we.
		if ( ! defined( 'GATEDMEDIA_VERSION' ) || ! defined( 'RESTRICT_MEDIA_FILE_ACCESS_BASENAME' ) ) {
			return;
		}

		// Styles, on the handle every block declares.
		add_action( 'init', __NAMESPACE__ . '\\add_css', 20 );

		// Block attributes, before a block renders.
		add_filter( 'render_block_data', __NAMESPACE__ . '\\block_data' );

		// Block markup, after it renders.
		add_filter( 'render_block_gated-media-access/row', __NAMESPACE__ . '\\index_row', 10, 2 );
		add_filter( 'render_block_gated-media-access/status-pill', __NAMESPACE__ . '\\dot_pill', 10, 2 );
		add_filter( 'render_block_gated-media-access/button', __NAMESPACE__ . '\\arrow_button', 10, 2 );

		// Gated Media Access filters.
		add_filter( 'gatedmedia_account_sections', __NAMESPACE__ . '\\sections' );
		add_filter( 'gatedmedia_my_access_data', __NAMESPACE__ . '\\my_access', 20 );
		add_filter( 'gatedmedia_format_price', __NAMESPACE__ . '\\price' );
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
 * Changes attributes before a block renders.
 *
 * @param array<string, mixed> $parsed The parsed block.
 * @return array<string, mixed>
 */
function block_data( array $parsed ): array {
	$name = (string) ( $parsed['blockName'] ?? '' );

	// Active access reads as Live, unless the caller named its own label.
	if ( 'gated-media-access/status-pill' === $name
		&& 'active' === ( $parsed['attrs']['value'] ?? 'active' )
		&& '' === (string) ( $parsed['attrs']['label'] ?? '' ) ) {
		$parsed['attrs']['label'] = 'Live';
	}

	if ( 'gated-media-access/empty-state' === $name ) {
		$parsed['attrs']['icon'] = 'i-info';
	}

	return $parsed;
}

/**
 * Puts a numbered tab on the front of every row.
 *
 * @param string               $content The rendered row.
 * @param array<string, mixed> $block   The parsed block.
 */
function index_row( string $content, array $block ): string {
	static $index = 0;

	return (string) preg_replace(
		'/<div class="gatedmedia-row__main">/',
		sprintf( '<span class="restyle-row__index" aria-hidden="true">%02d</span>$0', ++$index ),
		$content,
		1
	);
}

/**
 * Swaps the pill's icon for a dot, and marks the pill with its value.
 *
 * @param string               $content The rendered pill.
 * @param array<string, mixed> $block   The parsed block.
 */
function dot_pill( string $content, array $block ): string {
	$content = (string) preg_replace(
		'#<svg class="gatedmedia-icon[^"]*"[^>]*>.*?</svg>#s',
		'<span class="restyle-pill__dot" aria-hidden="true"></span>',
		$content,
		1
	);

	$tags = new WP_HTML_Tag_Processor( $content );

	if ( $tags->next_tag( array( 'class_name' => 'gatedmedia-status-pill' ) ) ) {
		$tags->add_class( 'restyle-pill--' . sanitize_html_class( (string) ( $block['attrs']['value'] ?? 'active' ) ) );
	}

	return $tags->get_updated_html();
}

/**
 * An arrow after the label of every primary button.
 *
 * @param string               $content The rendered button.
 * @param array<string, mixed> $block   The parsed block.
 */
function arrow_button( string $content, array $block ): string {
	if ( 'primary' !== ( $block['attrs']['variant'] ?? 'primary' ) ) {
		return $content;
	}

	return (string) preg_replace(
		'#</span>(\s*</(?:a|button)>)#',
		'</span><span class="restyle-button__arrow" aria-hidden="true">&rarr;</span>$1',
		$content,
		1
	);
}

/**
 * Renames two sections. Reusing a slug replaces that section.
 *
 * @param Section_Collection $sections The sections so far.
 */
function sections( Section_Collection $sections ): Section_Collection {
	$names = array(
		'my-access' => 'Library',
		'files'     => 'Downloads',
	);

	foreach ( $names as $slug => $name ) {
		$section = $sections->get( $slug );

		if ( null === $section ) {
			continue;
		}

		$sections = $sections->add(
			new Section(
				slug:        $section->slug(),
				title:       $name,
				menu_label:  $name,
				block:       $section->block(),
				position:    $section->position(),
				description: $section->description(),
				icon:        $section->icon(),
			)
		);
	}

	return $sections;
}

/**
 * Every My Access list in title order.
 *
 * @param array<string, mixed> $data The My Access data.
 * @return array<string, mixed>
 */
function my_access( array $data ): array {
	foreach ( array( 'groups', 'posts', 'files' ) as $list ) {
		if ( is_array( $data[ $list ] ?? null ) ) {
			usort( $data[ $list ], static fn ( array $a, array $b ): int => strcmp( (string) $a['title'], (string) $b['title'] ) );
		}
	}

	return $data;
}

/**
 * Whole amounts without the zero pence: £15.00 becomes £15.
 *
 * @param string $formatted The plugin's own formatting.
 */
function price( string $formatted ): string {
	return (string) preg_replace( '/[.,]00(?!\d)/', '', $formatted );
}
