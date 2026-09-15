<?php
/**
 * The block rebuilds. Each runs on core's render_block_{name} filter, after the block has rendered.
 *
 * @package PinkCrab\Gated_Access_Restyle
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access_Restyle;

use WP_HTML_Tag_Processor;
use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

/**
 * A row, rebuilt as a card with a coloured initial tile.
 *
 * Keeps `gatedmedia-row` and `data-gatedmedia-type`, which the Files filter script reads.
 *
 * @param string               $content The rendered row.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_row( string $content, array $block ): string {
	$attrs = (array) ( $block['attrs'] ?? array() );
	$title = (string) ( $attrs['title'] ?? '' );

	// Loading and unavailable rows keep their own markup.
	if ( 'normal' !== (string) ( $attrs['state'] ?? 'normal' ) || '' === $title ) {
		return $content;
	}

	$href  = (string) ( $attrs['href'] ?? '' );
	$meta  = (string) ( $attrs['meta'] ?? '' );
	$type  = (string) ( $attrs['filterType'] ?? '' );
	$aside = trim( (string) ( $block['innerHTML'] ?? '' ) );

	$classes = array( 'gatedmedia-row', 'restyle-card' );

	if ( 'order' === ( $attrs['variant'] ?? '' ) ) {
		$classes[] = 'gatedmedia-row--order';
	}

	$action = '';

	if ( '' !== (string) ( $attrs['actionLabel'] ?? '' ) ) {
		$action = '<div class="gatedmedia-row__action">' . Block::render(
			'gated-media-access/button',
			array(
				'label'   => (string) $attrs['actionLabel'],
				'href'    => (string) ( $attrs['actionHref'] ?? '' ),
				'icon'    => (string) ( $attrs['actionIcon'] ?? '' ),
				'variant' => 'primary',
				'full'    => true,
			)
		) . '</div>';
	}

	return sprintf(
		'<div class="%1$s"%2$s style="--restyle-hue: %3$d"><span class="restyle-card__tile" aria-hidden="true">%4$s</span><div class="gatedmedia-row__main"><p class="gatedmedia-row__title">%5$s</p>%6$s</div>%7$s%8$s</div>',
		esc_attr( implode( ' ', $classes ) ),
		'' !== $type ? sprintf( ' data-gatedmedia-type="%s"', esc_attr( $type ) ) : '',
		crc32( $title ) % 360,
		esc_html( initials( $title ) ),
		'' !== $href ? sprintf( '<a href="%s">%s</a>', esc_url( $href ), esc_html( $title ) ) : esc_html( $title ),
		'' !== $meta ? '<p class="gatedmedia-row__meta">' . esc_html( $meta ) . '</p>' : '',
		'' !== $aside ? '<div class="gatedmedia-row__aside">' . $aside . '</div>' : '',
		$action
	);
}

/**
 * The nav, rebuilt with a count against each link, and a user card above the sidebar form.
 *
 * @param string               $content The rendered nav.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_nav( string $content, array $block ): string {
	$items = $block['attrs']['items'] ?? array();

	// The original opening tag carries the wrapper attributes and the aria-label.
	if ( ! is_array( $items ) || array() === $items || ! preg_match( '#^\s*(<nav\b[^>]*>)#s', $content, $open ) ) {
		return $content;
	}

	$tabs   = 'tabs' === ( $block['attrs']['variant'] ?? 'sidebar' );
	$base   = $tabs ? 'gatedmedia-tab-strip' : 'gatedmedia-account-nav';
	$counts = nav_counts();
	$links  = '';

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || '' === (string) ( $item['label'] ?? '' ) || '' === (string) ( $item['href'] ?? '' ) ) {
			continue;
		}

		$href   = (string) $item['href'];
		$active = true === ( $item['active'] ?? false );
		$icon   = (string) ( $item['icon'] ?? '' );

		$links .= sprintf(
			'<a class="%1$s"%2$s href="%3$s">%4$s<span class="restyle-nav__label">%5$s</span>%6$s</a>',
			esc_attr( $base . '__item' . ( $active ? ' is-active' : '' ) ),
			$active ? ' aria-current="page"' : '',
			esc_url( $href ),
			'' !== $icon ? sprintf( '<svg class="gatedmedia-icon%s" aria-hidden="true" focusable="false"><use href="#%s"></use></svg>', $tabs ? ' gatedmedia-icon--small' : '', esc_attr( $icon ) ) : '',
			esc_html( (string) $item['label'] ),
			isset( $counts[ $href ] ) ? sprintf( '<span class="restyle-nav__count">%d</span>', $counts[ $href ] ) : ''
		);
	}

	return ( $tabs ? '' : user_card() ) . $open[1] . $links . '</nav>';
}

/**
 * Initials, name and email of the person signed in.
 */
function user_card(): string {
	$user = wp_get_current_user();

	if ( 0 === $user->ID ) {
		return '';
	}

	return sprintf(
		'<div class="restyle-user"><span class="restyle-user__avatar" aria-hidden="true">%1$s</span><div class="restyle-user__text"><p class="restyle-user__name">%2$s</p><p class="restyle-user__email">%3$s</p></div></div>',
		esc_html( initials( $user->display_name ) ),
		esc_html( $user->display_name ),
		esc_html( $user->user_email )
	);
}

/**
 * A section heading, rebuilt with a count beside it.
 *
 * @param string               $content The rendered heading.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_heading( string $content, array $block ): string {
	if ( ! preg_match( '#^\s*<(h[2-6])\b[^>]*>(.*?)</\1>\s*$#s', $content, $parts ) ) {
		return $content;
	}

	$text  = html_entity_decode( trim( wp_strip_all_tags( $parts[2] ) ), ENT_QUOTES );
	$count = heading_count( $text );

	return sprintf(
		'<%1$s class="gatedmedia-section-heading restyle-heading">%2$s%3$s</%1$s>',
		$parts[1],
		esc_html( $text ),
		null === $count ? '' : sprintf( '<span class="restyle-heading__count">%d</span>', $count )
	);
}

/**
 * Expiry, rebuilt as a dot badge.
 *
 * @param string               $content The rendered expiry.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_expiry( string $content, array $block ): string {
	$label = (string) ( $block['attrs']['label'] ?? '' );

	if ( '' === $label ) {
		return $content;
	}

	$state = (string) ( $block['attrs']['state'] ?? 'lifetime' );
	$state = in_array( $state, array( 'lifetime', 'dated', 'soon', 'expired' ), true ) ? $state : 'dated';

	return badge( 'gatedmedia-expiry restyle-badge--' . $state, esc_html( $label ) );
}

/**
 * Status pill, rebuilt as a dot badge. The label is taken from the plugin's own output, so its wording stands.
 *
 * @param string               $content The rendered pill.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_pill( string $content, array $block ): string {
	if ( ! preg_match( '#<span>([^<]*)</span>\s*</span>#', $content, $label ) ) {
		return $content;
	}

	// Already escaped by the pill block.
	return badge( 'gatedmedia-status-pill restyle-badge--' . sanitize_html_class( (string) ( $block['attrs']['value'] ?? 'active' ) ), $label[1] );
}

/**
 * A dot badge in the block-level host the plugin's inline components use.
 *
 * @param string $classes Extra classes.
 * @param string $label   Escaped label.
 */
function badge( string $classes, string $label ): string {
	return sprintf(
		'<div class="gatedmedia-inline-host"><span class="restyle-badge %1$s"><span class="restyle-badge__dot" aria-hidden="true"></span><span>%2$s</span></span></div>',
		esc_attr( $classes ),
		$label
	);
}

/**
 * The empty state, rebuilt with an illustration and a way back to the site.
 *
 * @param string               $content The rendered empty state.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_empty( string $content, array $block ): string {
	$title   = (string) ( $block['attrs']['title'] ?? '' );
	$message = (string) ( $block['attrs']['message'] ?? '' );

	if ( '' === $title || '' === $message ) {
		return $content;
	}

	return sprintf(
		'<div class="gatedmedia-empty-state restyle-empty">%1$s<p class="gatedmedia-empty-state__title">%2$s</p><p class="gatedmedia-text gatedmedia-text--meta">%3$s</p>%4$s</div>',
		'<svg class="restyle-empty__art" viewBox="0 0 120 80" aria-hidden="true" focusable="false"><rect class="restyle-empty__card" x="14" y="16" width="86" height="56" rx="10"/><rect class="restyle-empty__line restyle-empty__line--strong" x="26" y="30" width="44" height="6" rx="3"/><rect class="restyle-empty__line" x="26" y="44" width="62" height="6" rx="3"/><rect class="restyle-empty__line" x="26" y="56" width="36" height="6" rx="3"/><circle class="restyle-empty__dot" cx="100" cy="18" r="10"/></svg>',
		esc_html( $title ),
		esc_html( $message ),
		Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Back to the site', 'gated-media-access-restyle' ),
				'href'    => home_url( '/' ),
				'variant' => 'secondary',
			)
		)
	);
}

/**
 * A field, rebuilt with a floating label: the label follows its input, and a blank placeholder lets the CSS tell empty from filled.
 *
 * Fields with a hidden label or a placeholder of their own are left alone.
 *
 * @param string               $content The rendered field.
 * @param array<string, mixed> $block   The parsed block.
 */
function render_field( string $content, array $block ): string {
	$attrs = (array) ( $block['attrs'] ?? array() );

	if ( true === ( $attrs['labelHidden'] ?? false ) || '' === (string) ( $attrs['label'] ?? '' ) || '' !== (string) ( $attrs['placeholder'] ?? '' ) ) {
		return $content;
	}

	$moved = preg_replace( '#(<label\b[^>]*>.*?</label>)\s*(<input\b[^>]*>|<textarea\b.*?</textarea>)#s', '$2$1', $content, 1, $swapped );

	if ( 1 !== $swapped || ! is_string( $moved ) ) {
		return $content;
	}

	$tags = new WP_HTML_Tag_Processor( $moved );

	if ( $tags->next_tag() ) {
		$tags->add_class( 'restyle-float' );
	}

	if ( $tags->next_tag( array( 'class_name' => 'gatedmedia-field__input' ) ) ) {
		$tags->set_attribute( 'placeholder', ' ' );
	}

	return $tags->get_updated_html();
}
