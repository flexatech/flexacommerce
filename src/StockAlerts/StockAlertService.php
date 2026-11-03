<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Contracts\Email\EmailMessage;
use Flexa\Commerce\Contracts\Logger;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Notifications\EmailTemplates;
use Flexa\Commerce\Notifications\Mailer;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-stock alerts (ADR-065). A shopper leaves an address on a product
 * that is out of stock. Stock that goes up or a product save marks the
 * product's rows due; the five-minute cron looks at due rows and sends one
 * email per row once its variant, or any variant when none was picked, can
 * be bought again. The daily run marks every waiting row due, which covers
 * stock that changed without a hook (a direct import), and drops old rows.
 *
 * @phpstan-import-type Values from StockAlertSettings
 */
final class StockAlertService {
	/** Rows looked at, and so emails sent at most, per cron run. */
	public const BATCH = 50;

	/** Sent and cancelled rows are kept this long. */
	public const KEEP_DAYS = 90;

	public const STOP_PARAM = 'fxcm_stock_stop';

	/** The query arg that picks a value on the product page (ListSwatches::PICK). */
	private const PICK = 'fxcm_pick';

	/** @param \Closure(Product,Variant):bool $purchasable */
	public function __construct(
		private readonly StockAlertRepository $alerts,
		private readonly ProductRepository $products,
		private readonly StockService $stock,
		private readonly \Closure $purchasable,
		private readonly Mailer $mailer,
		private readonly EmailTemplates $templates,
		private readonly Clock $clock,
		private readonly Logger $log,
	) {}

	public function repository(): StockAlertRepository {
		return $this->alerts;
	}

	/**
	 * Keeps the address until the product, or the variant, is back. Asking
	 * twice keeps the first row.
	 *
	 * @throws SignupRefused
	 */
	public function subscribe( int $product_id, int $variant_id, string $email, int $user_id ): StockAlert {
		$settings = StockAlertSettings::get();
		if ( ! $settings['enabled'] ) {
			throw new SignupRefused( 'off' );
		}
		if ( $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user instanceof \WP_User ? (string) $user->user_email : '';
		} elseif ( ! $settings['guests'] ) {
			throw new SignupRefused( 'login' );
		}
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			throw new SignupRefused( 'email' );
		}
		$product = $this->products->find( $product_id );
		if ( $product === null || $product->status !== 'publish' || ! $product->is_sold() || $product->is_external() ) {
			throw new SignupRefused( 'product' );
		}
		if ( ! $product->is_variable() ) {
			$variant_id = $product->base->id;
		}
		if ( $variant_id > 0 && $product->sellable( $variant_id ) === null ) {
			throw new SignupRefused( 'product' );
		}
		if ( $this->available( $product, $variant_id ) ) {
			throw new SignupRefused( 'in_stock' );
		}
		$found = $this->alerts->waiting_for( $email, $product->id, $variant_id );
		if ( $found !== null ) {
			return $found;
		}
		$locale = $user_id > 0 ? get_user_locale( $user_id ) : determine_locale();
		$id     = $this->alerts->insert( $product->id, $variant_id, $user_id > 0 ? $user_id : null, $email, $locale, $this->clock->db_now() );
		$alert  = $this->alerts->find( $id );
		if ( $alert === null ) {
			throw new SignupRefused( 'product' );
		}
		/**
		 * A shopper asked to hear when a product is back in stock.
		 *
		 * @param StockAlert $alert
		 */
		do_action( 'flexacommerce.stock_alert.created', $alert );
		return $alert;
	}

	/** Stock went up: the product's rows are looked at on the next run. */
	public function restocked( int $variant_id ): void {
		$this->alerts->mark_variant( $variant_id );
	}

	public function product_saved( int $product_id ): void {
		$this->alerts->mark_product( $product_id );
	}

	/** The five-minute cron. */
	public function run(): int {
		if ( ! StockAlertSettings::enabled() || ! $this->alerts->has_due() ) {
			return 0;
		}
		$sent     = 0;
		$products = [];
		$now      = $this->clock->db_now();
		foreach ( $this->alerts->due( self::BATCH ) as $alert ) {
			if ( ! array_key_exists( $alert->product_id, $products ) ) {
				$products[ $alert->product_id ] = $this->products->find( $alert->product_id );
			}
			$product = $products[ $alert->product_id ];
			if ( $product === null || ! $this->available( $product, $alert->variant_id ) ) {
				$this->alerts->checked( $alert->id );
				continue;
			}
			if ( $this->email( $alert, $product ) ) {
				$this->alerts->sent( $alert->id, $now );
				++$sent;
			} else {
				// Tried again on the daily run.
				$this->alerts->checked( $alert->id );
				$this->log->warning( 'Back-in-stock email {id} was not sent.', [ 'id' => $alert->id ] );
			}
		}
		return $sent;
	}

	/** The daily cron: every waiting row is looked at again, old rows go. */
	public function daily(): void {
		if ( StockAlertSettings::enabled() ) {
			$this->alerts->mark_all();
		}
		$this->alerts->purge( $this->clock->db_ago( self::KEEP_DAYS * DAY_IN_SECONDS ) );
	}

	/** The row of a stop link, when the key is right. */
	public function verify( string $key ): ?StockAlert {
		if ( preg_match( '/^(\d{1,20})\.([a-f0-9]{32})$/', $key, $m ) !== 1 ) {
			return null;
		}
		$alert = $this->alerts->find( (int) $m[1] );
		return $alert !== null && hash_equals( $alert->token, $m[2] ) ? $alert : null;
	}

	/** No more back-in-stock emails for the address of the link. */
	public function unsubscribe( string $key ): bool {
		$alert = $this->verify( $key );
		if ( $alert === null ) {
			return false;
		}
		$this->alerts->cancel_email( $alert->email, $this->clock->db_now() );
		return true;
	}

	/** A sample of the email for a product, sent to `$to`. */
	public function test( int $product_id, string $to ): bool {
		$product = $this->products->find( $product_id );
		if ( $product === null ) {
			return false;
		}
		$alert = new StockAlert( 0, $product->id, 0, null, $to, '', StockAlert::WAITING, str_repeat( '0', 32 ), $this->clock->db_now(), null );
		return $this->email( $alert, $product );
	}

	/** Whether the variant, or any variant when `$variant_id` is 0, can be bought now. */
	public function available( Product $product, int $variant_id ): bool {
		foreach ( $product->sellable_rows() as $row ) {
			if ( $variant_id > 0 && $row->id !== $variant_id ) {
				continue;
			}
			// One variant gets a fresh read; for any variant the loaded product is enough.
			$stock = $variant_id > 0 ? $this->stock->available( $product, $row ) : $this->stock->availability( $product, $row );
			if ( ( $this->purchasable )( $product, $row ) && $stock->is_in_stock() ) {
				return true;
			}
		}
		return false;
	}

	/** The product name with the variant's values, as the email and the list show it. */
	public function label( Product $product, int $variant_id ): string {
		$variant = $variant_id > 0 && $product->is_variable() ? $product->sellable( $variant_id ) : null;
		if ( $variant === null ) {
			return $product->title;
		}
		$values = [];
		foreach ( $product->variation_attributes() as $definition ) {
			$value = $variant->combination->to_array()[ $definition->key ] ?? null;
			if ( $value === null || $value === Combination::WILDCARD ) {
				continue;
			}
			if ( $definition->is_global() ) {
				$term     = get_term( (int) $value );
				$values[] = $term instanceof \WP_Term ? $term->name : $value;
			} else {
				$values[] = $definition->local_label( $value ) ?? $value;
			}
		}
		return $values !== [] ? $product->title . ' – ' . implode( ', ', $values ) : $product->title;
	}

	/** The product page, with the variant's values picked. */
	public function url( Product $product, int $variant_id ): string {
		$url     = (string) get_permalink( $product->id );
		$variant = $variant_id > 0 && $product->is_variable() ? $product->sellable( $variant_id ) : null;
		foreach ( $variant !== null ? $variant->combination->to_array() : [] as $key => $value ) {
			if ( $value !== Combination::WILDCARD ) {
				$url = add_query_arg( self::PICK . '[' . rawurlencode( (string) $key ) . ']', rawurlencode( (string) $value ), $url );
			}
		}
		return $url;
	}

	private function email( StockAlert $alert, Product $product ): bool {
		$switched = false;
		if ( $alert->user_id !== null ) {
			$switched = switch_to_user_locale( $alert->user_id );
		} elseif ( $alert->locale !== '' ) {
			$switched = switch_to_locale( $alert->locale );
		}
		try {
			$settings = StockAlertSettings::get();
			$texts    = StockAlertSettings::default_texts();
			$name     = $this->label( $product, $alert->variant_id );
			$fill     = static fn ( string $text ): string => strtr(
				$text,
				[
					'{product}'    => $name,
					'{site_title}' => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				]
			);
			$variant  = $alert->variant_id > 0 ? $product->sellable( $alert->variant_id ) : null;
			$image_id = $variant->image_id ?? (int) get_post_thumbnail_id( $product->id );
			$image    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'medium' ) : false;
			$html     = $this->templates->render(
				'back-in-stock',
				[
					'email_id'        => BackInStockEmail::ID,
					'message'         => $fill( $settings['message'] !== '' ? $settings['message'] : $texts['message'] ),
					'product'         => $name,
					'image'           => is_string( $image ) ? $image : '',
					'product_url'     => $this->url( $product, $alert->variant_id ),
					'unsubscribe_url' => add_query_arg( self::STOP_PARAM, $alert->key(), home_url( '/' ) ),
				]
			);
			return $this->mailer->send(
				new BackInStockEmail(),
				new EmailMessage(
					$fill( $settings['subject'] !== '' ? $settings['subject'] : $texts['subject'] ),
					$fill( $settings['heading'] !== '' ? $settings['heading'] : $texts['heading'] ),
					$html,
					null,
					[ $alert->email ]
				),
				[
					'enabled'    => true,
					'recipients' => '',
					'subject'    => '',
					'heading'    => '',
				]
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
