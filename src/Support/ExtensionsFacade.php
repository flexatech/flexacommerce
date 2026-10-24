<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Exceptions\IncompatibleApi;
use Flexa\Commerce\Contracts\Extensions;
use Flexa\Commerce\Contracts\ExtensionSettings;
use Flexa\Commerce\Contracts\Logger as LoggerContract;
use Flexa\Commerce\Contracts\MoneyFactory;

defined( 'ABSPATH' ) || exit;

final class ExtensionsFacade implements Extensions {
	/** @var array<string,ExtensionSettings> */
	private array $settings = [];

	public function __construct( private readonly Services $services ) {}

	public function api_version(): string {
		return self::API_VERSION;
	}

	public function require_api( string $min ): void {
		if ( version_compare( self::API_VERSION, $min, '<' ) ) {
			throw new IncompatibleApi( $min, self::API_VERSION );
		}
	}

	public function money(): MoneyFactory {
		return $this->services->money();
	}

	public function settings( string $extension_id ): ExtensionSettings {
		return $this->settings[ $extension_id ] ??= new ExtensionOptionStore( $extension_id );
	}

	public function logger( string $channel ): LoggerContract {
		return $this->services->logger( $channel );
	}
}
