<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Masks secrets and personal data before anything is logged (doc 06 §2.8).
 * Emails become a salted hash so entries about one customer can still be
 * correlated without storing the address.
 */
final class Redactor {
	public const MASK = '[redacted]';

	private const SECRET_KEYS = '/(pass(word|wd)?$|secret|token|api_?key|private_?key|authori[sz]ation|cookie|nonce|signature|card_?number|^card$|^pan$|cvc|cvv|iban|account_?number|routing_?number|^key$)/i';

	private const PERSONAL_KEYS = '/^(first_name|last_name|full_name|customer_name|company|address(_[12])?|street|city|postcode|zip|phone|ip|customer_ip|remote_addr)$/i';

	private const MAX_DEPTH = 6;

	public function __construct( private readonly string $salt ) {}

	/**
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	public function context( array $context, int $depth = 0 ): array {
		$out = [];
		foreach ( $context as $key => $value ) {
			$name = (string) $key;
			if ( preg_match( self::SECRET_KEYS, $name ) === 1 || preg_match( self::PERSONAL_KEYS, $name ) === 1 ) {
				$out[ $key ] = self::MASK;
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = $depth >= self::MAX_DEPTH ? '[nested]' : $this->context( $value, $depth + 1 );
				continue;
			}
			if ( is_string( $value ) ) {
				$out[ $key ] = stripos( $name, 'email' ) !== false ? $this->email( $value ) : $this->text( $value );
				continue;
			}
			if ( is_object( $value ) ) {
				$out[ $key ] = $value instanceof \Throwable ? $this->exception( $value ) : '[object ' . $value::class . ']';
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** Mask emails and long digit runs (card numbers) inside free text. */
	public function text( string $text ): string {
		$text = (string) preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			fn ( array $m ): string => $this->email( $m[0] ),
			$text
		);
		return (string) preg_replace( '/\b(?:\d[ \-]?){12,18}\d\b/', '[card]', $text );
	}

	public function email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return '';
		}
		return 'email#' . substr( hash_hmac( 'sha256', $email, $this->salt ), 0, 12 );
	}

	/** @return array{class:string,message:string,code:int|string,at:string} */
	private function exception( \Throwable $e ): array {
		return [
			'class'   => $e::class,
			'message' => $this->text( $e->getMessage() ),
			'code'    => $e->getCode(),
			'at'      => basename( $e->getFile() ) . ':' . $e->getLine(),
		];
	}
}
