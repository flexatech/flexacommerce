<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

defined( 'ABSPATH' ) || exit;

/** The number a refund's credit note was given and when (UTC, `Y-m-d H:i:s`), ADR-064. */
final class CreditNote {
	public function __construct(
		public readonly int $refund_id,
		public readonly string $number,
		public readonly string $date,
	) {}

	/** @return array{refund_id:int,number:string,date:string} */
	public function to_array(): array {
		return [
			'refund_id' => $this->refund_id,
			'number'    => $this->number,
			'date'      => $this->date,
		];
	}
}
