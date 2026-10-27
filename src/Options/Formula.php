<?php

declare(strict_types=1);

namespace Flexa\Commerce\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A price worked out from what the customer entered (ADR-039), such as
 * `{2} * {3} * 0.05` for a sign priced by its area. Read by a small parser
 * of its own, never eval(): numbers, `+ - * /`, brackets, `base` (the unit
 * price), `qty` (the line quantity), `{n}` (field n of the same set) and
 * round(x, digits), ceil(x), floor(x), min(a, b, ...), max(a, b, ...).
 *
 * Amounts are in the currency's main unit (dollars, not cents). Division by
 * zero gives 0. assets/frontend/cart.js has the same parser for the price
 * shown while the customer types; keep the two in step.
 *
 * @phpstan-type Inputs array{base:float,qty:float,fields:array<int,float>}
 */
final class Formula {
	public const MAX_LENGTH = 300;
	private const FUNCTIONS = [ 'round', 'ceil', 'floor', 'min', 'max' ];

	/** @var list<array{0:string,1:string}> kind, text */
	private array $tokens = [];
	private int $at       = 0;

	/** @param Inputs $inputs */
	private function __construct( private readonly array $inputs ) {}

	/**
	 * The result, or null when the formula cannot be read.
	 *
	 * @param Inputs $inputs
	 */
	public static function evaluate( string $formula, array $inputs ): ?float {
		$run = new self( $inputs );
		try {
			$value = $run->read( $formula );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		return is_finite( $value ) ? $value : null;
	}

	/**
	 * The field ids a formula uses, or null when it cannot be read.
	 *
	 * @return list<int>|null
	 */
	public static function refs( string $formula ): ?array {
		$run = new self(
			[
				'base'   => 1.0,
				'qty'    => 1.0,
				'fields' => [],
			]
		);
		try {
			$run->read( $formula );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		$refs = [];
		foreach ( $run->tokens as [ $kind, $text ] ) {
			if ( $kind === 'ref' ) {
				$refs[] = (int) $text;
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/** Whether the formula uses the unit price. */
	public static function uses_base( string $formula ): bool {
		return preg_match( '/(?<![\w{])base(?!\w)/i', $formula ) === 1;
	}

	/** @throws \UnexpectedValueException */
	private function read( string $formula ): float {
		if ( trim( $formula ) === '' || strlen( $formula ) > self::MAX_LENGTH ) {
			throw new \UnexpectedValueException();
		}
		if ( preg_match_all( '/\s*(?:(\d+(?:\.\d+)?|\.\d+)|\{\s*(\d{1,5})\s*\}|([a-z_]\w*)|([-+*\/(),]))/iA', $formula, $found, PREG_SET_ORDER ) === false ) {
			throw new \UnexpectedValueException();
		}
		$length = 0;
		foreach ( $found as $match ) {
			$length += strlen( $match[0] );
			if ( ( $match[1] ?? '' ) !== '' ) {
				$this->tokens[] = [ 'number', $match[1] ];
			} elseif ( ( $match[2] ?? '' ) !== '' ) {
				$this->tokens[] = [ 'ref', $match[2] ];
			} elseif ( ( $match[3] ?? '' ) !== '' ) {
				$this->tokens[] = [ 'name', strtolower( $match[3] ) ];
			} else {
				$this->tokens[] = [ 'op', $match[4] ?? '' ];
			}
		}
		if ( trim( substr( $formula, $length ) ) !== '' ) {
			throw new \UnexpectedValueException();
		}
		$value = $this->sum();
		if ( $this->at !== count( $this->tokens ) ) {
			throw new \UnexpectedValueException();
		}
		return $value;
	}

	private function sum(): float {
		$value = $this->product();
		while ( $this->is( '+' ) || $this->is( '-' ) ) {
			$plus  = $this->next()[1] === '+';
			$right = $this->product();
			$value = $plus ? $value + $right : $value - $right;
		}
		return $value;
	}

	private function product(): float {
		$value = $this->unary();
		while ( $this->is( '*' ) || $this->is( '/' ) ) {
			$times = $this->next()[1] === '*';
			$right = $this->unary();
			if ( $times ) {
				$value *= $right;
			} else {
				$value = $right == 0.0 ? 0.0 : $value / $right; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- -0.0 too.
			}
		}
		return $value;
	}

	private function unary(): float {
		if ( $this->is( '-' ) ) {
			++$this->at;
			return -$this->unary();
		}
		if ( $this->is( '+' ) ) {
			++$this->at;
			return $this->unary();
		}
		return $this->primary();
	}

	private function primary(): float {
		[ $kind, $text ] = $this->next();
		if ( $kind === 'number' ) {
			return (float) $text;
		}
		if ( $kind === 'ref' ) {
			return $this->inputs['fields'][ (int) $text ] ?? 0.0;
		}
		if ( $kind === 'op' && $text === '(' ) {
			$value = $this->sum();
			$this->expect( ')' );
			return $value;
		}
		if ( $kind === 'name' && in_array( $text, self::FUNCTIONS, true ) && $this->is( '(' ) ) {
			return $this->call( $text );
		}
		if ( $kind === 'name' && ( $text === 'base' || $text === 'qty' ) ) {
			return $this->inputs[ $text ];
		}
		throw new \UnexpectedValueException();
	}

	private function call( string $name ): float {
		$this->expect( '(' );
		$args = [ $this->sum() ];
		while ( $this->is( ',' ) ) {
			++$this->at;
			$args[] = $this->sum();
		}
		$this->expect( ')' );
		$count = count( $args );
		if ( ( $name === 'round' && $count > 2 ) || ( ( $name === 'ceil' || $name === 'floor' ) && $count > 1 ) ) {
			throw new \UnexpectedValueException();
		}
		return match ( $name ) {
			'round' => round( $args[0], max( -6, min( 6, (int) ( $args[1] ?? 0 ) ) ) ),
			'ceil'  => ceil( $args[0] ),
			'floor' => floor( $args[0] ),
			'min'   => min( $args ),
			default => max( $args ),
		};
	}

	private function is( string $op ): bool {
		$token = $this->tokens[ $this->at ] ?? null;
		return $token !== null && $token[0] === 'op' && $token[1] === $op;
	}

	/** @return array{0:string,1:string} */
	private function next(): array {
		$token = $this->tokens[ $this->at ] ?? null;
		if ( $token === null ) {
			throw new \UnexpectedValueException();
		}
		++$this->at;
		return $token;
	}

	private function expect( string $op ): void {
		if ( ! $this->is( $op ) ) {
			throw new \UnexpectedValueException();
		}
		++$this->at;
	}
}
