<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

defined( 'ABSPATH' ) || exit;

/** The number an order's invoice was given and when (UTC, `Y-m-d H:i:s`). */
final class Invoice {
	public function __construct(
		public readonly string $number,
		public readonly string $date,
	) {}

	/** @return array{number:string,date:string} */
	public function to_array(): array {
		return [
			'number' => $this->number,
			'date'   => $this->date,
		];
	}
}
