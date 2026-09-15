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
 * The stylesheet, inlined after the plugin's own.
 */
function add_css(): void {
	wp_add_inline_style( Asset_Loader::FRONT_STYLE, css() );
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

/**
 * The stylesheet. Tokens first, then the components.
 */
function css(): string {
	return <<<'CSS'
:root {
	--gatedmedia-font: "Helvetica Neue", Helvetica, Arial, sans-serif;
	--gatedmedia-background: #fffbea;
	--gatedmedia-surface: #fffbea;
	--gatedmedia-surface-lowest: #fff;
	--gatedmedia-surface-low: #fff3b0;
	--gatedmedia-surface-variant: #ffe766;
	--gatedmedia-on-surface: #111;
	--gatedmedia-on-surface-variant: #333;
	--gatedmedia-outline: #111;
	--gatedmedia-outline-variant: #111;
	--gatedmedia-primary: #2d5bff;
	--gatedmedia-primary-dim: #1a3fd1;
	--gatedmedia-primary-container: #dfe6ff;
	--gatedmedia-on-primary: #fff;
	--gatedmedia-error: #e0004d;
	--gatedmedia-error-container: #ff5c8a;
	--gatedmedia-error-dim: #5a0020;
	--gatedmedia-radius: 0;
	--gatedmedia-radius-lg: 0;
	--gatedmedia-radius-xl: 0;
	--gatedmedia-radius-full: 0;
	--gatedmedia-hairline: 3px solid #111;
	--restyle-yellow: #ffd400;
	--restyle-pink: #ff5c8a;
	--restyle-green: #00c170;
	--restyle-border: 3px solid #111;
	--restyle-shadow: 4px 4px 0 #111;
	--restyle-shadow-lift: 6px 6px 0 #111;
}

/* Account shell */
.gatedmedia-account {
	background: var(--gatedmedia-background);
	border: var(--restyle-border);
	box-shadow: 8px 8px 0 #111;
}

.gatedmedia-account__sidebar {
	position: static;
	align-self: stretch;
	background: var(--restyle-yellow);
	border-right: var(--restyle-border);
	padding-inline: var(--gatedmedia-md);
}

.gatedmedia-account__brand {
	padding: 0;
}

.gatedmedia-account__brand .gatedmedia-heading--page {
	font-size: 28px;
	font-weight: 900;
	letter-spacing: -0.02em;
	text-transform: uppercase;
}

.gatedmedia-account__brand .gatedmedia-text--meta {
	font-weight: 700;
	color: #111;
}

.gatedmedia-page-intro .gatedmedia-text--meta {
	display: inline-block;
	padding: 4px 10px;
	background: #111;
	color: #fff;
	font-weight: 700;
}

/* Account nav and tab strip */
.gatedmedia-account-nav {
	gap: 10px;
}

.gatedmedia-account-nav__item,
.gatedmedia-tab-strip__item {
	opacity: 1;
	background: #fff;
	color: #111;
	border: var(--restyle-border);
	box-shadow: 3px 3px 0 #111;
	font-size: 13px;
	font-weight: 800;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	transition: transform 0.1s, box-shadow 0.1s;
}

.gatedmedia-account-nav__item:hover,
.gatedmedia-tab-strip__item:hover {
	background: #fff;
	color: #111;
	transform: translate(-2px, -2px);
	box-shadow: 5px 5px 0 #111;
}

.gatedmedia-account-nav__item.is-active,
.gatedmedia-tab-strip__item.is-active {
	font-size: 13px;
	background: var(--gatedmedia-primary);
	color: #fff;
	border-color: #111;
}

.gatedmedia-tab-strip {
	gap: var(--gatedmedia-sm);
	padding: var(--gatedmedia-inset);
	background: var(--restyle-yellow);
}

.gatedmedia-tab-strip__item {
	padding: 6px 10px;
}

/* Section heading */
.gatedmedia-section-heading {
	display: inline-block;
	margin-bottom: var(--gatedmedia-md);
	padding: 6px 12px;
	background: #111;
	color: var(--restyle-yellow);
	font-size: 13px;
	font-weight: 900;
	letter-spacing: 0.1em;
}

/* Row, as a card with a numbered tab */
.gatedmedia-row {
	margin-bottom: var(--gatedmedia-md);
	padding: var(--gatedmedia-md);
	background: #fff;
	border: var(--restyle-border);
	box-shadow: var(--restyle-shadow);
	transition: transform 0.1s, box-shadow 0.1s;
}

.gatedmedia-row:hover {
	transform: translate(-2px, -2px);
	box-shadow: var(--restyle-shadow-lift);
}

.gatedmedia-row.is-unavailable:hover {
	transform: none;
	box-shadow: var(--restyle-shadow);
}

.gatedmedia-row .gatedmedia-row__main {
	flex: 1;
}

.restyle-row__index {
	display: flex;
	align-items: center;
	justify-content: center;
	align-self: stretch;
	flex-shrink: 0;
	min-width: 48px;
	margin: calc(var(--gatedmedia-md) * -1) 0 calc(var(--gatedmedia-md) * -1) calc(var(--gatedmedia-md) * -1);
	background: var(--restyle-yellow);
	border-right: var(--restyle-border);
	font-size: 18px;
	font-weight: 900;
}

.gatedmedia-row__title {
	font-size: 18px;
	font-weight: 800;
}

.gatedmedia-row__title a {
	color: #111;
	text-decoration: underline;
	text-decoration-color: var(--restyle-yellow);
	text-decoration-thickness: 3px;
	text-underline-offset: 4px;
}

.gatedmedia-row__title a:hover {
	background: var(--restyle-yellow);
}

.gatedmedia-row__meta {
	font-weight: 600;
	color: #333;
}

/* Buttons and links */
.gatedmedia-button {
	border: var(--restyle-border);
	box-shadow: var(--restyle-shadow);
	font-weight: 900;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	transition: transform 0.1s, box-shadow 0.1s;
}

.gatedmedia-button:hover {
	transform: translate(-2px, -2px);
	box-shadow: var(--restyle-shadow-lift);
}

.gatedmedia-button:active {
	transform: translate(2px, 2px);
	box-shadow: 1px 1px 0 #111;
}

.gatedmedia-button--primary,
.gatedmedia-button--primary:hover {
	background: var(--gatedmedia-primary);
	color: #fff;
	border-color: #111;
}

.gatedmedia-button--secondary {
	background: #fff;
	color: #111;
	border-color: #111;
}

.gatedmedia-button--secondary:hover {
	background: var(--restyle-yellow);
	color: #111;
}

.restyle-button__arrow {
	font-weight: 900;
}

.gatedmedia-text-link {
	color: #111;
	font-weight: 800;
	text-decoration-color: var(--gatedmedia-primary);
	text-decoration-thickness: 3px;
}

.gatedmedia-text-link:hover {
	background: var(--restyle-yellow);
	color: #111;
}

/* Status pill, a dot and a value colour */
.gatedmedia-status-pill {
	padding: 4px 10px;
	background: var(--restyle-green);
	color: #111;
	border: 2px solid #111;
	font-size: 12px;
	font-weight: 800;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}

.restyle-pill__dot {
	width: 8px;
	height: 8px;
	background: #111;
	border-radius: 50%;
}

.restyle-pill--pending {
	background: var(--restyle-yellow);
}

.restyle-pill--failed,
.restyle-pill--expired,
.restyle-pill--revoked {
	background: var(--restyle-pink);
}

.restyle-pill--refunded {
	background: #ddd;
}

/* Expiry */
.gatedmedia-expiry {
	font-size: 13px;
	font-weight: 700;
}

.gatedmedia-expiry--warning {
	padding: 2px 8px;
	background: var(--restyle-pink);
	color: #111;
	border: 2px solid #111;
}

.gatedmedia-expiry--expired {
	text-decoration: line-through;
}

.gatedmedia-expiry--chip {
	background: #fff;
	border: 2px solid #111;
}

/* Price */
.gatedmedia-price {
	font-size: 18px;
	font-weight: 900;
}

.gatedmedia-price-block__amount {
	display: inline-block;
	padding: 0 12px;
	background: var(--restyle-yellow);
	border: var(--restyle-border);
	box-shadow: var(--restyle-shadow);
	font-weight: 900;
}

/* Notice and empty state */
.gatedmedia-notice {
	background: #fff;
	border: var(--restyle-border);
	box-shadow: var(--restyle-shadow);
	font-weight: 600;
}

.gatedmedia-notice--error {
	background: var(--restyle-pink);
	color: #111;
	border-color: #111;
}

.gatedmedia-notice--success {
	background: var(--restyle-green);
	border-color: #111;
}

.gatedmedia-notice--error .gatedmedia-notice__icon,
.gatedmedia-notice--error .gatedmedia-notice__dismiss {
	color: #111;
}

.gatedmedia-empty-state {
	background: #fff;
	border: 3px dashed #111;
	box-shadow: var(--restyle-shadow);
}

.gatedmedia-empty-state__icon {
	width: 48px;
	height: 48px;
	color: #111;
}

.gatedmedia-empty-state__title {
	font-size: 22px;
	font-weight: 900;
	text-transform: uppercase;
}

/* Fields and filter */
.gatedmedia-field__label {
	font-size: 12px;
	font-weight: 900;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.gatedmedia-field__input,
.gatedmedia-filter__type,
.gatedmedia-type-chips__chip {
	background: #fff;
	border: var(--restyle-border);
	box-shadow: 3px 3px 0 #111;
	font-weight: 600;
}

.gatedmedia-field__input:focus {
	outline: none;
	background: var(--gatedmedia-background);
	box-shadow: 3px 3px 0 var(--gatedmedia-primary);
}

.gatedmedia-field__input:disabled {
	background: #eee;
	box-shadow: none;
}

.gatedmedia-field.is-invalid .gatedmedia-field__input {
	box-shadow: 3px 3px 0 var(--gatedmedia-error);
}

.gatedmedia-type-chips__chip.is-active {
	background: var(--gatedmedia-primary);
	color: #fff;
	border-color: #111;
}

.gatedmedia :is(a, button, input, select, textarea, [tabindex]):focus-visible {
	outline: 3px solid var(--gatedmedia-primary);
	outline-offset: 3px;
}

/* Cards, panels and the rest */
.gatedmedia-card,
.gatedmedia-payment-status {
	background: #fff;
	border: var(--restyle-border);
	box-shadow: 8px 8px 0 #111;
}

.gatedmedia-coupon__applied {
	background: var(--restyle-yellow);
	border: var(--restyle-border);
}

.gatedmedia-order-header {
	border-bottom: var(--restyle-border);
}

.gatedmedia-order-access__row {
	border-bottom: 3px dashed #111;
}

.gatedmedia-contents__icon {
	color: #111;
}

.gatedmedia-summary {
	font-weight: 700;
}

.gatedmedia-spinner {
	border: 4px solid #111;
	border-top-color: var(--gatedmedia-primary);
	border-radius: 0;
}

.gatedmedia-skeleton {
	background: #111;
	opacity: 0.2;
}

@media (max-width: 781px) {

	.restyle-row__index {
		justify-content: flex-start;
		min-width: 0;
		margin: calc(var(--gatedmedia-md) * -1) calc(var(--gatedmedia-md) * -1) 0;
		padding: 4px var(--gatedmedia-md);
		border-right: 0;
		border-bottom: var(--restyle-border);
	}

	.gatedmedia-action-bar {
		background: var(--restyle-yellow);
		border-top: var(--restyle-border);
	}
}

@media (prefers-reduced-motion: reduce) {

	.gatedmedia-row,
	.gatedmedia-button,
	.gatedmedia-account-nav__item,
	.gatedmedia-tab-strip__item {
		transition: none;
	}
}
CSS;
}
