<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * One canonical record (doc 05 §3). `depends` lists references that must
 * exist in the target before this record is written; the Runner imports a
 * missing one first.
 */
final class Record {
	public const FORMAT_VERSION = '1.0';

	public const SETTINGS   = 'settings';
	public const TAX_RATE   = 'tax_rate';
	public const SHIPPING   = 'shipping';
	public const ATTRIBUTE  = 'attribute';
	public const TERM       = 'term';
	public const PRODUCT    = 'product';
	public const CUSTOMER   = 'customer';
	public const ORDER      = 'order';
	public const COUPON     = 'coupon';
	public const OPTION_SET = 'option_set';

	public readonly string $checksum;

	/**
	 * @param array<string,mixed> $data
	 * @param list<string>        $depends
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
		public readonly array $data,
		public readonly array $depends = [],
	) {
		$this->checksum = Checksum::of( $data );
	}

	public function ref(): Ref {
		return Ref::parse( $this->id ) ?? new Ref( '', $this->type, '' );
	}

	/** Source id as stored in the id map. */
	public function source_id(): string {
		return $this->ref()->id;
	}

	/** Date the source entity last changed, `Y-m-d H:i:s` UTC, or null. */
	public function modified_at(): ?string {
		$value = $this->data['modified_at'] ?? null;
		return is_string( $value ) && $value !== '' ? Dates::to_db( $value ) : null;
	}

	/** @return array{type:string,id:string,checksum:string,data:array<string,mixed>} */
	public function to_array(): array {
		return [
			'type'     => $this->type,
			'id'       => $this->id,
			'checksum' => $this->checksum,
			'data'     => $this->data,
		];
	}
}
