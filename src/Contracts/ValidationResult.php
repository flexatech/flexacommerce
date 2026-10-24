<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Errors collected by a validation filter. Immutable: a filter returns
 * `$result->with_error( ... )` to refuse.
 *
 * @api
 */
final class ValidationResult {
	/** @param list<array{code:string,message:string}> $errors */
	public function __construct( public readonly array $errors = [] ) {}

	public function with_error( string $code, string $message ): self {
		return new self(
			[
				...$this->errors,
				[
					'code'    => sanitize_key( $code ),
					'message' => $message,
				],
			]
		);
	}

	public function is_valid(): bool {
		return $this->errors === [];
	}

	public function first_message(): string {
		return $this->errors[0]['message'] ?? '';
	}
}
