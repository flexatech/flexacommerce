<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\MoneyFactory;
use Flexa\Commerce\Database\AfterCommit;
use Flexa\Commerce\Database\Integrity;
use Flexa\Commerce\Database\Sequence;
use Flexa\Commerce\Database\TableCharset;
use Flexa\Commerce\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root. Each service is built on first use, once per request.
 * Tests pass their own wpdb or clock through the constructor.
 */
final class Services {
	private ?AfterCommit $after_commit = null;
	private ?Transaction $transaction  = null;
	private ?Sequence $sequence        = null;
	private ?Redactor $redactor        = null;
	private ?LogRepository $logs       = null;
	private ?MoneyFactory $money       = null;
	private ?Integrity $integrity      = null;
	private ?RateLimiter $limiter      = null;

	/** @var array<string,Logger> */
	private array $loggers = [];

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Clock $clock = new Clock(),
	) {
		TableCharset::register();
	}

	public function wpdb(): \wpdb {
		return $this->wpdb;
	}

	public function clock(): Clock {
		return $this->clock;
	}

	public function after_commit(): AfterCommit {
		return $this->after_commit ??= new AfterCommit(
			function ( \Throwable $e ): void {
				$this->logger( 'core' )->error(
					'After-commit callback failed: {message}',
					[
						'message'   => $e->getMessage(),
						'exception' => $e,
					]
				);
			}
		);
	}

	public function transaction(): Transaction {
		return $this->transaction ??= new Transaction( $this->wpdb, $this->after_commit() );
	}

	public function sequence(): Sequence {
		return $this->sequence ??= new Sequence( $this->wpdb, $this->transaction() );
	}

	public function integrity(): Integrity {
		return $this->integrity ??= new Integrity( $this->wpdb );
	}

	public function redactor(): Redactor {
		return $this->redactor ??= new Redactor( wp_salt( 'auth' ) );
	}

	public function log_repository(): LogRepository {
		return $this->logs ??= new LogRepository( $this->wpdb );
	}

	public function logger( string $channel ): Logger {
		$channel                            = sanitize_key( $channel );
		return $this->loggers[ $channel ] ??= new Logger(
			$channel === '' ? 'core' : $channel,
			$this->log_repository(),
			$this->redactor(),
			$this->transaction(),
			$this->clock
		);
	}

	public function limiter(): RateLimiter {
		return $this->limiter ??= new RateLimiter( $this->clock );
	}

	public function money(): MoneyFactory {
		return $this->money ??= new StoreMoneyFactory();
	}
}
