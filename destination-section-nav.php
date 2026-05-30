<?php
/**
 * Plugin Name: Destination Section Nav
 * Description: Simple contextual navigation for parent pages/posts and their children (ideal for travel destination inner pages).
 * Version:     1.0.0
 * Author:      Syed Zeeshan Ali
 * Text Domain: destination-section-nav
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DSN_Plugin {

	/**
	 * Meta key to store nav config.
	 */
	const META_KEY = '_dsn_nav_config';

	/**
	 * Bootstrap.
	 */
	public static function init() : void {
		// Meta box.
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'save_post',      [ __CLASS__, 'save_meta_box' ], 10, 2 );

		// Shortcode.
		add_shortcode( 'destination_nav', [ __CLASS__, 'shortcode_nav' ] );

		// Optional basic styles.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
	}

	/**
	 * Register meta box on pages and posts.
	 */
	public static function register_meta_box() : void {
		$post_types = apply_filters( 'dsn_nav_post_types', [ 'page', 'post' ] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'dsn_nav_meta',
				__( 'Destination Section Navigation', 'destination-section-nav' ),
				[ __CLASS__, 'render_meta_box' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box UI.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function render_meta_box( \WP_Post $post ) : void {
		wp_nonce_field( 'dsn_nav_meta_save', 'dsn_nav_meta_nonce' );

		$config = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! is_array( $config ) ) {
			$config = [
				'mode'  => 'auto', // auto|manual|disabled
				'items' => [],
			];
		}

		$mode  = $config['mode'] ?? 'auto';
		$items = array_map( 'absint', (array) ( $config['items'] ?? [] ) );

		// Get children of this post (direct children).
		$children = get_children( [
			'post_parent' => $post->ID,
			'post_type'   => $post->post_type,
			'post_status' => 'publish',
			'orderby'     => 'menu_order title',
			'order'       => 'ASC',
		] );
		?>
		<p>
			<strong><?php esc_html_e( 'Navigation Mode', 'destination-section-nav' ); ?></strong>
		</p>

		<p>
			<label>
				<input type="radio" name="dsn_nav_mode" value="auto" <?php checked( $mode, 'auto' ); ?> />
				<?php esc_html_e( 'Automatic – show all direct children', 'destination-section-nav' ); ?>
			</label><br/>
			<label>
				<input type="radio" name="dsn_nav_mode" value="manual" <?php checked( $mode, 'manual' ); ?> />
				<?php esc_html_e( 'Manual – choose specific child pages', 'destination-section-nav' ); ?>
			</label><br/>
			<label>
				<input type="radio" name="dsn_nav_mode" value="disabled" <?php checked( $mode, 'disabled' ); ?> />
				<?php esc_html_e( 'Disabled – no navigation for this root', 'destination-section-nav' ); ?>
			</label>
		</p>

		<?php if ( ! empty( $children ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Child pages to include (for Manual mode):', 'destination-section-nav' ); ?></strong>
			</p>
			<ul style="max-height: 200px; overflow:auto; margin:0; padding-left:1em;">
				<?php foreach ( $children as $child ) : ?>
					<li>
						<label>
							<input type="checkbox"
								   name="dsn_nav_items[]"
								   value="<?php echo esc_attr( (string) $child->ID ); ?>"
								<?php checked( in_array( $child->ID, $items, true ) ); ?>
							/>
							<?php echo esc_html( get_the_title( $child ) ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p>
				<em><?php esc_html_e( 'This post currently has no child pages. Automatic and manual modes will have nothing to show.', 'destination-section-nav' ); ?></em>
			</p>
		<?php
		endif;
	}

	/**
	 * Save meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function save_meta_box( int $post_id, \WP_Post $post ) : void {
		// Check nonce.
		if ( ! isset( $_POST['dsn_nav_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsn_nav_meta_nonce'] ) ), 'dsn_nav_meta_save' ) ) {
			return;
		}

		// Autosave?
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Proper capability check.
		if ( 'page' === $post->post_type ) {
			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return;
			}
		} else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
		}

		$mode = isset( $_POST['dsn_nav_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['dsn_nav_mode'] ) ) : 'auto';
		if ( ! in_array( $mode, [ 'auto', 'manual', 'disabled' ], true ) ) {
			$mode = 'auto';
		}

		$items_raw = isset( $_POST['dsn_nav_items'] ) ? (array) $_POST['dsn_nav_items'] : [];
		$items     = [];

		foreach ( $items_raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$items[] = $id;
			}
		}

		$config = [
			'mode'  => $mode,
			'items' => $items,
		];

		update_post_meta( $post_id, self::META_KEY, $config );
	}

	/**
	 * Shortcode callback: [destination_nav]
	 *
	 * @param array $atts Shortcode attributes (unused for now).
	 * @return string
	 */
	public static function shortcode_nav( array $atts ) : string {
		if ( ! is_singular() ) {
			return '';
		}

		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$root_id = self::get_root_post_id( $post->ID );
		if ( ! $root_id ) {
			return '';
		}

		$config = get_post_meta( $root_id, self::META_KEY, true );
		if ( ! is_array( $config ) ) {
			$config = [
				'mode'  => 'auto',
				'items' => [],
			];
		}

		if ( 'disabled' === ( $config['mode'] ?? '' ) ) {
			return '';
		}

		$items = [];

		if ( 'manual' === ( $config['mode'] ?? '' ) && ! empty( $config['items'] ) ) {
			// Manual selection – only chosen child IDs, in the saved order.
			foreach ( $config['items'] as $child_id ) {
				$child_id = absint( $child_id );
				$child    = get_post( $child_id );

				if ( $child instanceof \WP_Post && 'publish' === $child->post_status ) {
					$items[] = $child;
				}
			}
		} else {
			// Auto mode – all direct children of the root.
			$items = get_children( [
				'post_parent' => $root_id,
				'post_status' => 'publish',
				'orderby'     => 'menu_order title',
				'order'       => 'ASC',
			] );

			if ( $items ) {
				$items = array_values( $items );
			} else {
				$items = [];
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$current_id = $post->ID;
		$root_title = get_the_title( $root_id );

		ob_start();
		?>
		<nav class="dsn-nav" aria-label="<?php echo esc_attr( $root_title ); ?>">
			<ul class="dsn-nav__list">
				<?php foreach ( $items as $item ) : ?>
					<?php
					$is_current = ( $item->ID === $current_id );
					$classes    = [ 'dsn-nav__item' ];
					if ( $is_current ) {
						$classes[] = 'dsn-nav__item--current';
					}
					?>
					<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<a href="<?php echo esc_url( get_permalink( $item ) ); ?>" class="dsn-nav__link">
							<?php echo esc_html( get_the_title( $item ) ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Find the "root" post for a given post ID (top-most ancestor).
	 *
	 * @param int $post_id Post ID.
	 * @return int Root post ID.
	 */
	private static function get_root_post_id( int $post_id ) : int {
		$ancestors = get_post_ancestors( $post_id );
		if ( ! empty( $ancestors ) ) {
			// Ancestors are ordered from closest parent to furthest.
			return (int) end( $ancestors );
		}

		return $post_id;
	}

	/**
	 * Enqueue minimal CSS (optional).
	 */
	public static function enqueue_styles() : void {
		$css = '
		.dsn-nav {
			margin: 0 0 1.5em;
		}
		.dsn-nav__list {
			list-style: none;
			margin: 0;
			padding: 0;
		}
		.dsn-nav__item {
			margin: 0;
		}
		.dsn-nav__link {
			display: block;
			padding: 0.4em 0.6em;
			text-decoration: none;
		}
		.dsn-nav__item--current > .dsn-nav__link {
			font-weight: 600;
			text-decoration: underline;
		}';

		wp_register_style( 'destination-section-nav', false );
		wp_enqueue_style( 'destination-section-nav' );
		wp_add_inline_style( 'destination-section-nav', $css );
	}
}

DSN_Plugin::init();
