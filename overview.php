<?php
/**
 * The Overview section: a block of our own, built from the plugin's data filters and its own component blocks.
 *
 * @package PinkCrab\Gated_Access_Restyle
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access_Restyle;

use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

/**
 * The block the Overview section names.
 */
function register_overview_block(): void {
	register_block_type(
		'gatedmedia-restyle/overview',
		array(
			'api_version'     => 3,
			'title'           => __( 'Account overview', 'gated-media-access-restyle' ),
			'style_handles'   => array( Asset_Loader::FRONT_STYLE ),
			'render_callback' => __NAMESPACE__ . '\\render_overview',
		)
	);
}

/**
 * Greeting, counts, what runs out soon, and the latest orders.
 */
function render_overview(): string {
	$user = wp_get_current_user();

	if ( 0 === $user->ID ) {
		return '';
	}

	$counts = counts();
	$name   = '' !== $user->first_name ? $user->first_name : $user->display_name;

	$body = sprintf(
		'<header class="restyle-hello"><p class="restyle-hello__title">%1$s</p><p class="gatedmedia-text gatedmedia-text--meta">%2$s</p></header>',
		/* translators: %s: the person's first name. */
		esc_html( sprintf( __( 'Hello, %s', 'gated-media-access-restyle' ), $name ) ),
		/* translators: %d: how many groups, posts and files the person holds. */
		esc_html( sprintf( __( 'You hold %d things across your groups, posts and files.', 'gated-media-access-restyle' ), $counts['groups'] + $counts['posts'] + $counts['files'] ) )
	);

	$body .= '<div class="restyle-stats">'
		. stat( __( 'Groups', 'gated-media-access' ), $counts['groups'], Account_Url::section( 'my-access' ) )
		. stat( __( 'Posts', 'gated-media-access' ), $counts['posts'], Account_Url::section( 'my-access' ) )
		. stat( __( 'Files', 'gated-media-access' ), $counts['files'], Account_Url::section( 'files' ) )
		. stat( __( 'Orders', 'gated-media-access' ), $counts['orders'], Account_Url::section( 'orders' ) )
		. '</div>';

	$body .= running_out_soon() . recent_orders();

	return sprintf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes( array( 'class' => 'gatedmedia gatedmedia-view restyle-overview' ) ),
		$body
	);
}

/**
 * One stat card.
 *
 * @param string $label What is counted.
 * @param int    $count How many.
 * @param string $href  Where it lives.
 */
function stat( string $label, int $count, string $href ): string {
	return sprintf(
		'<a class="restyle-stat" href="%1$s"><span class="restyle-stat__count">%2$d</span><span class="restyle-stat__label">%3$s</span></a>',
		esc_url( $href ),
		$count,
		esc_html( $label )
	);
}

/**
 * Everything held that expires soon, drawn with the plugin's own row and expiry blocks.
 */
function running_out_soon(): string {
	$held = held();
	$rows = '';

	foreach ( array( 'groups', 'posts', 'files' ) as $list ) {
		foreach ( (array) ( $held[ $list ] ?? array() ) as $item ) {
			if ( 'soon' !== ( $item['expiry_state'] ?? '' ) ) {
				continue;
			}

			$rows .= Block::render(
				'gated-media-access/row',
				array(
					'title' => (string) ( $item['title'] ?? '' ),
					'meta'  => 'files' === $list ? (string) ( $item['meta'] ?? '' ) : '',
					'href'  => (string) ( $item['href'] ?? '' ),
				),
				Block::render(
					'gated-media-access/expiry',
					array(
						'state' => 'soon',
						'label' => (string) ( $item['expiry_label'] ?? '' ),
					)
				)
			);
		}
	}

	if ( '' === $rows ) {
		return '';
	}

	return '<section class="gatedmedia-section">'
		. Block::render( 'gated-media-access/section-heading', array( 'text' => __( 'Running out soon', 'gated-media-access-restyle' ) ) )
		. $rows
		. '</section>';
}

/**
 * The three latest orders, from the plugin's orders filter, drawn as the Orders view draws them.
 */
function recent_orders(): string {
	$data   = (array) apply_filters(
		'gatedmedia_orders_data',
		array(
			'orders' => array(),
			'detail' => null,
		),
		''
	);
	$orders = array_slice( (array) ( $data['orders'] ?? array() ), 0, 3 );

	if ( array() === $orders ) {
		return '';
	}

	$rows = '';

	foreach ( $orders as $order ) {
		$rows .= Block::render(
			'gated-media-access/row',
			array(
				'title'   => (string) ( $order['title'] ?? '' ),
				'meta'    => (string) ( $order['date'] ?? '' ),
				'href'    => (string) ( $order['href'] ?? '' ),
				'variant' => 'order',
			),
			Block::render(
				'gated-media-access/price',
				array(
					'amount'   => (int) ( $order['amount'] ?? 0 ),
					'original' => (int) ( $order['original'] ?? 0 ),
					'currency' => (string) ( $order['currency'] ?? 'GBP' ),
				)
			) . Block::render( 'gated-media-access/status-pill', array( 'value' => (string) ( $order['status'] ?? 'complete' ) ) )
		);
	}

	return '<section class="gatedmedia-section">'
		. Block::render( 'gated-media-access/section-heading', array( 'text' => __( 'Recent orders', 'gated-media-access-restyle' ) ) )
		. $rows
		. Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'All orders', 'gated-media-access-restyle' ),
				'href'    => Account_Url::section( 'orders' ),
				'variant' => 'link',
			)
		)
		. '</section>';
}
