<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * How a store keeps product reviews as comments (ADR-056): the product post
 * type, the comment types of a review and of a reply, and the comment meta
 * of the star rating and the verified-buyer flag.
 */
final class ReviewKeys {
	public function __construct(
		public readonly string $post_type,
		public readonly string $type,
		public readonly string $reply_type,
		public readonly string $rating,
		public readonly string $verified,
	) {}

	public static function woo(): self {
		return new self( 'product', 'review', 'comment', 'rating', 'verified' );
	}

	public static function fxc(): self {
		return new self( PostTypes::PRODUCT, Reviews::TYPE, Reviews::TYPE, Reviews::META_RATING, Reviews::META_VERIFIED );
	}

	/** @return list<string> the comment types of reviews and replies */
	public function types(): array {
		return array_values( array_unique( [ $this->type, $this->reply_type ] ) );
	}
}
