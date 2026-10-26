<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Setup\Pages;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The cutover wizard of doc 05 §9.2, WC→FXC only. Each step is confirmed
 * on its own and stored in one option, so the wizard survives a reload and
 * a rollback knows what to put back. Deactivating WooCommerce (step 5) is
 * left to the admin; the wizard only sees whether it happened.
 */
final class Cutover {
	public const OPTION = 'flexacommerce_cutover';

	public const STEPS = [ 'freeze', 'verify', 'bases', 'pages', 'deactivate', 'urls', 'redirects', 'reopen' ];

	public const PAGE_MODES = [ 'flexacommerce', 'woocommerce' ];

	/** Setting key => WooCommerce page option, for pages the woocommerce mode reuses. */
	private const WOO_PAGES = [
		'cart_page_id'     => 'woocommerce_cart_page_id',
		'checkout_page_id' => 'woocommerce_checkout_page_id',
		'account_page_id'  => 'woocommerce_myaccount_page_id',
		'terms_page_id'    => 'woocommerce_terms_page_id',
	];

	/** Block a reused WooCommerce page gets in place of its content. */
	private const PAGE_BLOCKS = [
		'cart_page_id'     => '<!-- wp:flexacommerce/cart /-->',
		'checkout_page_id' => '<!-- wp:flexacommerce/checkout /-->',
		'account_page_id'  => '<!-- wp:flexacommerce/account /-->',
	];

	private const BASE_SETTINGS = [
		'product'  => 'product_base',
		'shop'     => 'shop_base',
		'category' => 'category_base',
		'tag'      => 'tag_base',
	];

	private const URL_SAMPLE = 20;

	/** @var \Closure():bool */
	private readonly \Closure $woo_active;

	/** @var \Closure(string):array{status:int,body:string} */
	private readonly \Closure $fetch;

	/**
	 * @param (callable():bool)|null                               $woo_active
	 * @param (callable(string):array{status:int,body:string})|null $fetch
	 */
	public function __construct(
		private readonly JobRepository $jobs,
		private readonly IdMapRepository $ids,
		private readonly Clock $clock,
		?callable $woo_active = null,
		?callable $fetch = null,
	) {
		$this->woo_active = \Closure::fromCallable( $woo_active ?? static fn (): bool => function_exists( 'WC' ) );
		$this->fetch      = \Closure::fromCallable( $fetch ?? [ self::class, 'fetch' ] );
	}

	/** @return array<string,mixed> */
	public function status(): array {
		$state = self::state();
		$steps = [];
		foreach ( self::STEPS as $step ) {
			$at      = $step === 'deactivate' ? null : ( $state['steps'][ $step ] ?? null );
			$steps[] = [
				'key'  => $step,
				'done' => $step === 'deactivate' ? ! ( $this->woo_active )() : is_string( $at ),
				'at'   => $at,
			];
		}
		return [
			'woocommerce' => ( $this->woo_active )(),
			'steps'       => $steps,
			'job_id'      => $state['job_id'] ?? null,
			'bases'       => $state['bases'] ?? $this->plan_bases(),
			'pages'       => $state['pages'] ?? null,
			'urls'        => $state['urls'] ?? null,
			'finished'    => isset( $state['steps']['reopen'] ),
		];
	}

	/**
	 * Step 1: hold FlexaCommerce checkout too, until step 8 opens it.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function freeze(): array {
		$state                                  = self::state();
		$state['previous']['checkout_paused'] ??= Settings::bool( 'checkout_paused' );
		Settings::update( [ 'checkout_paused' => true ] );
		return $this->mark( $state, 'freeze' );
	}

	/**
	 * Step 2: a final WC→FXC run, verified after the freeze, with no errors
	 * and no mismatches. Warnings pass only when acknowledged.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function verify( int $job_id, bool $acknowledge_warnings = false ): array {
		$state = $this->require( 'verify' );
		$job   = $this->jobs->find( $job_id );
		if ( $job === null ) {
			throw new MigrationError( 'not_found', sprintf( 'Migration job %d does not exist.', $job_id ) );
		}
		if ( $job->direction !== Direction::WC_TO_FXC || $job->dry_run || $job->is_file() || ! in_array( $job->status, JobStatus::FINISHED, true ) ) {
			throw new MigrationError( 'cutover_job_invalid', 'Pick a finished WooCommerce to FlexaCommerce job on this site that was not a dry run.' );
		}
		$verified = is_string( $job->report['verified_at'] ?? null ) ? $job->report['verified_at'] : '';
		if ( $verified === '' || $verified < (string) $state['steps']['freeze'] ) {
			throw new MigrationError( 'cutover_stale', 'Run the job and verify it again after checkout was put on hold.' );
		}
		// Counters belong to the last run; the log keeps every run of the job.
		$rejected = 0;
		$warnings = 0;
		foreach ( $job->counters as $counters ) {
			$rejected += (int) ( $counters['rejected'] ?? 0 );
			$warnings += (int) ( $counters['warnings'] ?? 0 );
		}
		if ( $rejected > 0 ) {
			throw new MigrationError( 'cutover_errors', sprintf( 'The last run of job %1$d rejected %2$d records. Fix them and run it again.', $job->id, $rejected ) );
		}
		if ( ! empty( $job->report['mismatches'] ) ) {
			throw new MigrationError( 'cutover_mismatches', sprintf( 'The verification of job %d found mismatches.', $job->id ) );
		}
		if ( $warnings > 0 && ! $acknowledge_warnings ) {
			throw new MigrationError( 'cutover_warnings', sprintf( 'The last run of job %1$d logged %2$d warnings. Review them and acknowledge to continue.', $job->id, $warnings ) );
		}
		$state['job_id'] = $job->id;
		return $this->mark( $state, 'verify' );
	}

	/**
	 * Step 3: take over the WooCommerce URL bases (slugs are identical, doc 05 §5.2).
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function adopt_bases(): array {
		$state = $this->require( 'bases' );
		$plan  = $this->plan_bases();
		$new   = [];
		foreach ( self::BASE_SETTINGS as $key => $setting ) {
			$state['previous'][ $setting ] ??= Settings::string( $setting );
			$new[ $setting ]                 = $plan[ $key ]['fxc'];
		}
		Settings::update( $new );
		$state['bases'] = $plan;
		return $this->mark( $state, 'bases' );
	}

	/**
	 * Step 4: keep the FlexaCommerce pages, or reuse the WooCommerce page
	 * ids with their content replaced by our blocks.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function assign_pages( string $mode ): array {
		if ( ! in_array( $mode, self::PAGE_MODES, true ) ) {
			throw new MigrationError( 'cutover_page_mode', sprintf( 'Unknown page mode "%s".', $mode ) );
		}
		$state = $this->require( 'pages' );
		if ( $mode === 'flexacommerce' ) {
			Pages::install();
		} else {
			$changes = [];
			foreach ( self::WOO_PAGES as $setting => $option ) {
				$page = (int) get_option( $option, 0 );
				if ( $page <= 0 || ! in_array( get_post_status( $page ), [ 'publish', 'private' ], true ) ) {
					continue;
				}
				$state['previous'][ $setting ] ??= Settings::int( $setting );
				$changes[ $setting ]             = $page;
				if ( isset( self::PAGE_BLOCKS[ $setting ] ) ) {
					$state['previous']['content'][ $page ] ??= (string) get_post_field( 'post_content', $page, 'raw' );
					wp_update_post(
						[
							'ID'           => $page,
							'post_content' => self::PAGE_BLOCKS[ $setting ],
						]
					);
				}
			}
			if ( $changes !== [] ) {
				Settings::update( $changes );
			}
		}
		$pages = [ 'mode' => $mode ];
		foreach ( array_keys( self::WOO_PAGES ) as $setting ) {
			$pages[ $setting ] = Settings::int( $setting );
		}
		$state['pages'] = $pages;
		return $this->mark( $state, 'pages' );
	}

	/**
	 * Step 6, after the admin deactivated WooCommerce: flush the rules and
	 * fetch sample product and category URLs. A failed URL blocks the next
	 * step unless the admin accepts it.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function check_urls( bool $accept_failures = false ): array {
		$state = $this->require( 'urls' );
		delete_option( PostTypes::REWRITE_GUARD_OPTION );
		flush_rewrite_rules( false );
		$results = [];
		foreach ( $this->samples() as [ $type, $id, $url ] ) {
			$response  = ( $this->fetch )( $url );
			$marker    = $type === 'product' ? 'postid-' . $id : 'term-' . $id;
			$ok        = $response['status'] === 200 && preg_match( '/[\s"]' . preg_quote( $marker, '/' ) . '[\s"]/', $response['body'] ) === 1;
			$results[] = [
				'type'   => $type,
				'id'     => $id,
				'url'    => $url,
				'status' => $response['status'],
				'ok'     => $ok,
			];
		}
		$failed        = count( array_filter( $results, static fn ( array $row ): bool => ! $row['ok'] ) );
		$state['urls'] = [
			'checked'    => count( $results ),
			'failed'     => $failed,
			'results'    => $results,
			'checked_at' => $this->clock->db_now(),
		];
		if ( $failed > 0 && ! $accept_failures ) {
			self::save( $state );
			throw new MigrationError( 'cutover_urls', sprintf( '%1$d of %2$d sample URLs did not open the right page.', $failed, count( $results ) ) );
		}
		return $this->mark( $state, 'urls' );
	}

	/**
	 * Step 7: serve 301s from old WooCommerce URLs while WooCommerce stays inactive.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function enable_redirects(): array {
		$state = $this->require( 'redirects' );
		Settings::update( [ 'cutover_redirects' => true ], true );
		return $this->mark( $state, 'redirects' );
	}

	/**
	 * Step 8: open FlexaCommerce checkout.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function reopen(): array {
		$state = $this->require( 'reopen' );
		Settings::update( [ 'checkout_paused' => false ] );
		return $this->mark( $state, 'reopen' );
	}

	/**
	 * Put back the settings and page content the wizard changed. With
	 * WooCommerce active again the empty bases mean `shop-item`/`shop`.
	 *
	 * @return array<string,mixed> the wizard status
	 */
	public function rollback(): array {
		$state    = self::state();
		$previous = is_array( $state['previous'] ?? null ) ? $state['previous'] : [];
		foreach ( (array) ( $previous['content'] ?? [] ) as $page => $content ) {
			wp_update_post(
				[
					'ID'           => (int) $page,
					'post_content' => (string) $content,
				]
			);
		}
		unset( $previous['content'] );
		Settings::update( [ 'cutover_redirects' => false ] + $previous, true );
		delete_option( self::OPTION );
		delete_option( PostTypes::REWRITE_GUARD_OPTION );
		return $this->status();
	}

	/**
	 * What step 3 will set: each WooCommerce base that is a single segment is
	 * kept. A base with more segments or a `%product_cat%` placeholder cannot
	 * be a FlexaCommerce base, so its first segment is used and the old URLs
	 * are redirected.
	 *
	 * @return array<string,array{wc:string,fxc:string,identical:bool}>
	 */
	public function plan_bases(): array {
		$permalinks = get_option( 'woocommerce_permalinks', [] );
		$permalinks = is_array( $permalinks ) ? $permalinks : [];
		$shop       = (int) get_option( 'woocommerce_shop_page_id', 0 );
		$wc         = [
			'product'  => self::trim_base( $permalinks['product_base'] ?? '' ),
			'shop'     => $shop > 0 && get_post_type( $shop ) === 'page' ? get_page_uri( $shop ) : '',
			'category' => self::trim_base( $permalinks['category_base'] ?? '' ),
			'tag'      => self::trim_base( $permalinks['tag_base'] ?? '' ),
		];
		$defaults   = [
			'product'  => 'product',
			'shop'     => 'shop',
			'category' => 'product-category',
			'tag'      => 'product-tag',
		];
		$plan       = [];
		foreach ( $defaults as $key => $default ) {
			$base         = $wc[ $key ] !== '' ? $wc[ $key ] : $default;
			$first        = sanitize_title( explode( '/', $base )[0] );
			$fxc          = str_contains( $first, '%' ) || $first === '' ? $default : $first;
			$plan[ $key ] = [
				'wc'        => $base,
				'fxc'       => $fxc,
				'identical' => $base === $fxc,
			];
		}
		return $plan;
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	/** True once step 7 ran; Redirects::handle() still stands down while WooCommerce is active. */
	public static function redirecting(): bool {
		return Settings::bool( 'cutover_redirects' );
	}

	/**
	 * State for a step, after checking the steps before it are done.
	 *
	 * @return array<string,mixed>
	 */
	private function require( string $step ): array {
		$state = self::state();
		foreach ( self::STEPS as $before ) {
			if ( $before === $step ) {
				break;
			}
			$done = $before === 'deactivate' ? ! ( $this->woo_active )() : isset( $state['steps'][ $before ] );
			if ( ! $done ) {
				throw new MigrationError(
					$before === 'deactivate' ? 'cutover_woocommerce_active' : 'cutover_order',
					$before === 'deactivate' ? 'Deactivate WooCommerce first.' : sprintf( 'Finish the "%s" step first.', $before )
				);
			}
		}
		return $state;
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function mark( array $state, string $step ): array {
		$state['steps'][ $step ] = $this->clock->db_now();
		self::save( $state );
		return $this->status();
	}

	/** @param array<string,mixed> $state */
	private static function save( array $state ): void {
		// Not autoloaded: the front end reads it only for a 404 while redirects are on.
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Imported products first, then categories, published ones only.
	 *
	 * @return list<array{0:string,1:int,2:string}>
	 */
	private function samples(): array {
		$out = [];
		foreach ( $this->ids->all( Record::PRODUCT, 0, 200 ) as $row ) {
			if ( count( $out ) >= self::URL_SAMPLE - 5 ) {
				break;
			}
			if ( get_post_type( $row->local_id ) === PostTypes::PRODUCT && get_post_status( $row->local_id ) === 'publish' ) {
				$out[] = [ 'product', $row->local_id, (string) get_permalink( $row->local_id ) ];
			}
		}
		foreach ( $this->ids->all( Record::TERM, 0, 200 ) as $row ) {
			if ( count( $out ) >= self::URL_SAMPLE ) {
				break;
			}
			$term = get_term( $row->local_id );
			if ( $term instanceof \WP_Term && $term->taxonomy === PostTypes::CATEGORY ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$out[] = [ 'category', $row->local_id, $link ];
				}
			}
		}
		return $out;
	}

	/** @return array{status:int,body:string} */
	private static function fetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return [
				'status' => 0,
				'body'   => '',
			];
		}
		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => wp_remote_retrieve_body( $response ),
		];
	}

	private static function trim_base( mixed $base ): string {
		return is_string( $base ) ? trim( $base, '/ ' ) : '';
	}
}
