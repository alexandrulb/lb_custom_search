<?php
/**
 * Plugin Name: Woo Live Product Search (AJAX) — Shortcode
 * Description: Shortcode [wc_live_search] renders a product search box with instant AJAX results for WooCommerce (with Watches/Jewelry tabs).
 * Version: 1.1.0
 * Author: Your Name
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class WCLS_Live_Search {
	private static $instance = null;
	const NONCE_ACTION = 'wcls_nonce';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',               [ $this, 'register_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_wcls_search',        [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_wcls_search', [ $this, 'ajax_search' ] );

		add_action( 'admin_notices', function () {
			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-warning"><p><strong>Woo Live Product Search:</strong> WooCommerce must be active for this plugin to work.</p></div>';
			}
		} );
	}

	public function register_assets() {
		$ver = '1.1.0';
		wp_register_style( 'wcls-css', plugins_url( 'assets/wcls.css', __FILE__ ), [], $ver );
		wp_register_script('wcls-js',  plugins_url( 'assets/wcls.js',  __FILE__ ), [], $ver, true);
		wp_localize_script( 'wcls-js', 'WCLS', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		] );
	}

	public function register_shortcode() {
		add_shortcode( 'wc_live_search', [ $this, 'render_shortcode' ] );
	}

	public function render_shortcode( $atts = [] ) {
		$atts = shortcode_atts( [
			'placeholder'       => 'Search products…',
			'width'             => '100%',
			'min_chars'         => 2,
			// limits
			'term_limit'        => 6,
			'product_limit'     => 8,
			// product category (slug) for the Watches tab
			'watch_cat'         => 'watches',
			// attribute taxonomies (without or with "pa_" prefix; we’ll resolve)
			'collections_attr'  => 'brand_collection',
			'brands_attr'       => 'lux_g_brand',
			'refs_attr'         => 'lux_g_referencenumber',
			// toggles
			'show_price'        => 'yes',
			'show_image'        => 'yes',
		], $atts, 'wc_live_search' );

		wp_enqueue_style( 'wcls-css' );
		wp_enqueue_script( 'wcls-js' );

		$uid = esc_attr( uniqid( 'wcls_', false ) );
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="wcls-wrap"><em>WooCommerce is not active.</em></div>';
		}

		ob_start(); ?>
		<div class="wcls-wrap"
		     id="<?php echo $uid; ?>"
		     style="max-width:<?php echo esc_attr( $atts['width'] ); ?>"
		     data-min-chars="<?php echo (int) $atts['min_chars']; ?>"
		     data-term-limit="<?php echo (int) $atts['term_limit']; ?>"
		     data-product-limit="<?php echo (int) $atts['product_limit']; ?>"
		     data-watch-cat="<?php echo esc_attr( $atts['watch_cat'] ); ?>"
		     data-collections-attr="<?php echo esc_attr( $atts['collections_attr'] ); ?>"
		     data-brands-attr="<?php echo esc_attr( $atts['brands_attr'] ); ?>"
		     data-refs-attr="<?php echo esc_attr( $atts['refs_attr'] ); ?>"
		     data-show-price="<?php echo $atts['show_price'] === 'yes' ? '1' : '0'; ?>"
		     data-show-image="<?php echo $atts['show_image'] === 'yes' ? '1' : '0'; ?>">

			<input type="search"
			       class="wcls-input"
			       placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
			       aria-label="<?php echo esc_attr( $atts['placeholder'] ); ?>"
			       autocomplete="off" />

			<div class="wcls-results" role="region" aria-live="polite" aria-expanded="false">
				<div class="wcls-tabs" role="tablist" aria-label="Search result categories">
					<button type="button" class="wcls-tab is-active" role="tab" aria-selected="true" data-tab="watches">Watches</button>
					<button type="button" class="wcls-tab" role="tab" aria-selected="false" data-tab="jewelry">Jewelry</button>
				</div>

				<div class="wcls-panels">
					<!-- Watches panel -->
					<div class="wcls-panel is-active" role="tabpanel" data-panel="watches">
						<div class="wcls-section" data-section="collections">
							<div class="wcls-section-title">Collections</div>
							<div class="wcls-section-list" data-target="collections"></div>
						</div>
						<div class="wcls-section" data-section="brands">
							<div class="wcls-section-title">Brands</div>
							<div class="wcls-section-list" data-target="brands"></div>
						</div>
						<div class="wcls-section" data-section="references">
							<div class="wcls-section-title">Reference numbers</div>
							<div class="wcls-section-list" data-target="references"></div>
						</div>
						<div class="wcls-section" data-section="products">
							<div class="wcls-section-title">Products</div>
							<div class="wcls-section-list" data-target="products"></div>
						</div>
						<div class="wcls-empty-all" style="display:none;">No results found</div>
					</div>

					<!-- Jewelry panel -->
					<div class="wcls-panel" role="tabpanel" data-panel="jewelry">
						<div class="wcls-empty">Jewelry tab is ready — results wiring will be added later.</div>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Resolve taxonomy slug, tolerating missing 'pa_' prefix for product attributes */
	private function resolve_taxonomy( $maybe ) {
		if ( taxonomy_exists( $maybe ) ) return $maybe;
		$prefixed = 'pa_' . ltrim( $maybe, '_' );
		if ( taxonomy_exists( $prefixed ) ) return $prefixed;
		return null;
	}

	public function ajax_search() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( [ 'message' => 'WooCommerce not active' ], 400 );
		}

		$q           = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
		$min_len     = 2;
		if ( mb_strlen( $q ) < $min_len ) {
			wp_send_json_success( [ 'watches' => [ 'collections'=>[], 'brands'=>[], 'references'=>[], 'products'=>[] ], 'jewelry' => [] ] );
		}

		$term_limit     = isset( $_REQUEST['term_limit'] ) ? max(1, min(50, (int) $_REQUEST['term_limit'])) : 6;
		$product_limit  = isset( $_REQUEST['product_limit'] ) ? max(1, min(50, (int) $_REQUEST['product_limit'])) : 8;
		$watch_cat_slug = isset( $_REQUEST['watch_cat'] ) ? sanitize_title( wp_unslash( $_REQUEST['watch_cat'] ) ) : 'watches';

		$collections_attr = isset( $_REQUEST['collections_attr'] ) ? sanitize_title( wp_unslash( $_REQUEST['collections_attr'] ) ) : 'brand_collection';
		$brands_attr      = isset( $_REQUEST['brands_attr'] ) ? sanitize_title( wp_unslash( $_REQUEST['brands_attr'] ) ) : 'lux_g_brand';
		$refs_attr        = isset( $_REQUEST['refs_attr'] ) ? sanitize_title( wp_unslash( $_REQUEST['refs_attr'] ) ) : 'lux_g_referencenumber';

		$vis = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : [];
		$exclude_from_search = ! empty( $vis['exclude-from-search'] ) ? (int) $vis['exclude-from-search'] : 0;

		$results = [
			'watches' => [
				'collections' => [],
				'brands'      => [],
				'references'  => [],
				'products'    => [],
			],
			'jewelry' => (object)[],
		];

		// ---- Term helpers
		$collect_terms = function( $taxonomy, $limit ) use ( $q ) {
			if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) return [];
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'search'     => $q,            // substring match
				'hide_empty' => true,
				'number'     => $limit,
			] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) return [];
			$out = [];
			foreach ( $terms as $t ) {
				$link = get_term_link( $t, $taxonomy );
				if ( is_wp_error( $link ) ) continue;
				$out[] = [
					'id'    => (int) $t->term_id,
					'name'  => $t->name,
					'slug'  => $t->slug,
					'url'   => esc_url( $link ),
					'count' => (int) $t->count,
				];
			}
			return $out;
		};

		$collections_tax = $this->resolve_taxonomy( $collections_attr );
		$brands_tax      = $this->resolve_taxonomy( $brands_attr );
		$refs_tax        = $this->resolve_taxonomy( $refs_attr );

		$results['watches']['collections'] = $collect_terms( $collections_tax, $term_limit );
		$results['watches']['brands']      = $collect_terms( $brands_tax, $term_limit );
		$results['watches']['references']  = $collect_terms( $refs_tax, $term_limit );

		// ---- Products (only in "watches" category)
		$tax_query = [];
		if ( $exclude_from_search ) {
			$tax_query[] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => [ $exclude_from_search ],
				'operator' => 'NOT IN',
			];
		}
		if ( $watch_cat_slug ) {
			$tax_query[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => [ $watch_cat_slug ],
				'operator' => 'IN',
			];
		}

		$q_args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $product_limit,
			's'              => $q,
			'fields'         => 'ids',
			'tax_query'      => $tax_query,
		];

		$wpq = new WP_Query( $q_args );
		if ( ! empty( $wpq->posts ) ) {
			foreach ( $wpq->posts as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) continue;

				$thumb = get_the_post_thumbnail_url( $pid, 'woocommerce_thumbnail' );
				if ( ! $thumb && function_exists( 'wc_placeholder_img_src' ) ) {
					$thumb = wc_placeholder_img_src( 'woocommerce_thumbnail' );
				}

				$results['watches']['products'][] = [
					'id'         => (int) $pid,
					'title'      => $product->get_name(),
					'url'        => get_permalink( $pid ),
					'price_html' => wc_price( $product->get_price() ) ?: $product->get_price_html(),
					'thumbnail'  => esc_url( $thumb ),
				];
			}
		}

		wp_send_json_success( $results );
	}
}

WCLS_Live_Search::instance();
