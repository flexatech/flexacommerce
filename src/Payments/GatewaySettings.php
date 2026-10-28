<?php

declare(strict_types=1);

namespace Flexa\Commerce\Payments;

use Flexa\Commerce\Contracts\Payments\PaymentGateway;
use Flexa\Commerce\Contracts\SettingField;
use Flexa\Commerce\Support\FieldValues;
use Flexa\Commerce\Support\WalletPlaces;

defined( 'ABSPATH' ) || exit;

/**
 * One option per gateway, `flexacommerce_gateway_{id}` (doc 04 §3.4). Core
 * adds `enabled`, `title` and `description` in front of the gateway's own
 * fields. Options are not autoloaded: catalog pages never read them (O7).
 */
final class GatewaySettings {
	public const PREFIX = 'flexacommerce_gateway_';

	/** @var array<string,array<string,mixed>> */
	private array $cache = [];

	/** @return list<SettingField> */
	public function fields( PaymentGateway $gateway ): array {
		$base = [
			new SettingField( 'enabled', 'checkbox', __( 'Enabled', 'flexacommerce' ), $gateway instanceof OfflineGateway ? $gateway->enabled_by_default() : false ),
			new SettingField( 'title', 'text', __( 'Title shown at checkout', 'flexacommerce' ), $gateway->title() ),
			new SettingField( 'description', 'textarea', __( 'Description shown at checkout', 'flexacommerce' ), '' ),
		];
		$own  = array_values( array_filter( $gateway->settings_fields(), static fn ( SettingField $f ): bool => ! in_array( $f->id, [ 'enabled', 'title', 'description' ], true ) ) );
		return array_merge( $base, $own );
	}

	/** @return array<string,mixed> */
	public function values( PaymentGateway $gateway ): array {
		return FieldValues::with_defaults( $this->fields( $gateway ), $this->stored( $gateway->id() ) );
	}

	/**
	 * A connected gateway checks the values with its provider first and
	 * may add to them (a webhook secret); when it throws, nothing is saved.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed> the saved values
	 * @throws \InvalidArgumentException from ConnectedGateway::connect()
	 */
	public function save( PaymentGateway $gateway, array $input ): array {
		$stored = $this->stored( $gateway->id() );
		$values = FieldValues::sanitize( $this->fields( $gateway ), $input, $stored );
		if ( $gateway instanceof ConnectedGateway ) {
			$values = $gateway->connect( $values, FieldValues::with_defaults( $this->fields( $gateway ), $stored ) );
		}
		update_option( self::PREFIX . $gateway->id(), $values, false );
		$this->cache[ $gateway->id() ] = $values;
		$gateway->configure( $values );
		if ( $gateway instanceof ExpressGateway ) {
			$places = (bool) ( $values['enabled'] ?? false )
				? array_values( array_filter( ExpressGateway::PLACES, static fn ( string $place ): bool => $gateway->express_config( $place ) !== null ) )
				: [];
			WalletPlaces::put( $gateway->id(), $places );
		}
		return $values;
	}

	/**
	 * Load the options of these gateways with one query.
	 *
	 * @param list<string> $ids
	 */
	public function prime( array $ids ): void {
		$missing = array_values( array_filter( $ids, fn ( string $id ): bool => ! isset( $this->cache[ $id ] ) ) );
		if ( count( $missing ) > 1 ) {
			wp_prime_option_caches( array_map( static fn ( string $id ): string => self::PREFIX . $id, $missing ) );
		}
	}

	public function enabled( PaymentGateway $gateway ): bool {
		return (bool) ( $this->values( $gateway )['enabled'] ?? false );
	}

	/** @return array<string,mixed> */
	private function stored( string $id ): array {
		if ( ! isset( $this->cache[ $id ] ) ) {
			$stored             = get_option( self::PREFIX . $id, [] );
			$this->cache[ $id ] = is_array( $stored ) ? $stored : [];
		}
		return $this->cache[ $id ];
	}
}
