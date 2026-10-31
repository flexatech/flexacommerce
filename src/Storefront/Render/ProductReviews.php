<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * The stars of a product and its reviews section (ADR-056). The stars read
 * the summary in product meta, so a product list runs no comment query.
 * The section lists approved reviews newest first, with the visitor's own
 * review while it waits for approval, and core's comment form with a star
 * field made of radio buttons, which works without JavaScript.
 */
final class ProductReviews {
	public const ANCHOR = 'reviews';

	/** Query var of the reviews page. */
	public const PAGE_VAR = 'reviews-page';

	public function __construct( private readonly Reviews $reviews ) {}

	/** The stars and the number of reviews, or nothing for a product without reviews. */
	public function rating( Product $product, bool $single ): string {
		return Component::wrap(
			'product_rating',
			static function () use ( $product, $single ): string {
				$summary = Reviews::summary( $product->id );
				if ( $summary['count'] === 0 ) {
					return '';
				}
				/* translators: %s: number of reviews. */
				$count = sprintf( _n( '%s review', '%s reviews', $summary['count'], 'flexacommerce' ), number_format_i18n( $summary['count'] ) );
				$link  = ( $single ? '' : (string) get_permalink( $product->id ) ) . '#' . self::ANCHOR;
				return sprintf(
					'<div class="fxc-rating">%1$s <a class="fxc-rating__count" href="%2$s">%3$s</a></div>',
					self::stars( $summary['average'] ),
					esc_url( $link ),
					esc_html( $count )
				);
			},
			[ 'product_id' => $product->id ]
		);
	}

	/** The reviews section: summary, list, pages and the form. */
	public function html( Product $product ): string {
		return Component::wrap(
			'product_reviews',
			function () use ( $product ): string {
				$summary          = Reviews::summary( $product->id );
				$refusal          = $this->reviews->refusal( $product->id );
				$page             = isset( $_GET[ self::PAGE_VAR ] ) ? max( 1, absint( wp_unslash( $_GET[ self::PAGE_VAR ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page link.
				[ $items, $more ] = $this->items( $product->id, $page );
				if ( $refusal === Reviews::OFF && $items === [] ) {
					return '';
				}

				$title = $summary['count'] > 0
					/* translators: %s: number of reviews. */
					? sprintf( __( 'Reviews (%s)', 'flexacommerce' ), number_format_i18n( $summary['count'] ) )
					: __( 'Reviews', 'flexacommerce' );
				$html  = '<section id="' . self::ANCHOR . '" class="fxc-reviews" aria-labelledby="fxc-reviews-title">';
				$html .= '<h2 id="fxc-reviews-title" class="fxc-reviews__title">' . esc_html( $title ) . '</h2>';
				$html .= $summary['count'] > 0 ? self::summary_html( $summary ) : '';
				if ( $items === [] ) {
					$html .= '<p class="fxc-reviews__empty">' . esc_html__( 'There are no reviews yet.', 'flexacommerce' ) . '</p>';
				} else {
					$html .= '<ol class="fxc-reviews__list">' . implode( '', array_map( [ $this, 'item' ], $items ) ) . '</ol>';
					$html .= self::pages( $page, $more );
				}
				$html .= $this->form( $product, $refusal );
				return $html . '</section>';
			},
			[ 'product_id' => $product->id ]
		);
	}

	/** Stars as an image with the average as its name; the width of the filled row is the average. */
	public static function stars( float $average ): string {
		$average = max( 0.0, min( 5.0, $average ) );
		/* translators: %s: average rating, for example 4.5. */
		$label = sprintf( __( 'Rated %s out of 5', 'flexacommerce' ), number_format_i18n( $average, $average === floor( $average ) ? 0 : 1 ) );
		return sprintf(
			'<span class="fxc-stars" role="img" aria-label="%1$s" style="--fxc-stars:%2$s%%"></span>',
			esc_attr( $label ),
			esc_attr( (string) round( $average * 20, 1 ) )
		);
	}

	/**
	 * Top-level reviews of the page with their replies, and whether an older
	 * page exists. A product nobody commented on runs no query.
	 *
	 * @return array{0:list<\WP_Comment>,1:bool}
	 */
	private function items( int $product_id, int $page ): array {
		$user    = get_current_user_id();
		$pending = $user > 0 ? $user : wp_get_unapproved_comment_author_email();
		if ( (int) get_comments_number( $product_id ) === 0 && ! $pending ) {
			return [ [], false ];
		}
		/**
		 * Reviews per page on the product page.
		 *
		 * @param int $per_page
		 */
		$per_page = max( 1, (int) apply_filters( 'flexacommerce.reviews.per_page', 10 ) );
		$args     = [
			'post_id'      => $product_id,
			'type'         => Reviews::TYPE,
			'status'       => 'approve',
			'hierarchical' => 'threaded',
			'number'       => $per_page + 1,
			'offset'       => ( $page - 1 ) * $per_page,
			'orderby'      => 'comment_date_gmt',
			'order'        => 'DESC',
		];
		if ( $pending ) {
			$args['include_unapproved'] = [ $pending ];
		}
		$found = get_comments( $args );
		$found = is_array( $found ) ? array_values( array_filter( $found, static fn ( mixed $c ): bool => $c instanceof \WP_Comment ) ) : [];
		return [ array_slice( $found, 0, $per_page ), count( $found ) > $per_page ];
	}

	private function item( \WP_Comment $review ): string {
		$id      = (int) $review->comment_ID;
		$rating  = (int) get_comment_meta( $id, Reviews::META_RATING, true );
		$avatar  = get_option( 'show_avatars' ) ? (string) get_avatar( $review, 48, '', '', [ 'class' => 'fxc-review__avatar' ] ) : '';
		$replies = '';
		foreach ( $review->get_children() as $reply ) {
			if ( $reply->comment_approved === '1' ) {
				$replies .= sprintf(
					'<li id="comment-%1$d" class="fxc-review fxc-review--reply"><p class="fxc-review__meta"><strong class="fxc-review__author">%2$s</strong> %3$s</p><div class="fxc-review__text">%4$s</div></li>',
					(int) $reply->comment_ID,
					esc_html( get_comment_author( $reply ) ),
					self::date( $reply ),
					self::text( $reply )
				);
			}
		}
		return sprintf(
			'<li id="comment-%1$d" class="fxc-review">%2$s<div class="fxc-review__body">%3$s<p class="fxc-review__meta"><strong class="fxc-review__author">%4$s</strong>%5$s %6$s</p>%7$s<div class="fxc-review__text">%8$s</div>%9$s</div></li>',
			$id,
			$avatar,
			$rating >= 1 && $rating <= 5 ? self::stars( (float) $rating ) : '',
			esc_html( get_comment_author( $review ) ),
			get_comment_meta( $id, Reviews::META_VERIFIED, true ) ? ' <span class="fxc-review__verified">' . esc_html__( 'Verified buyer', 'flexacommerce' ) . '</span>' : '',
			self::date( $review ),
			$review->comment_approved === '1' ? '' : '<p class="fxc-review__pending">' . esc_html__( 'Your review is awaiting approval.', 'flexacommerce' ) . '</p>',
			self::text( $review ),
			$replies !== '' ? '<ol class="fxc-review__replies">' . $replies . '</ol>' : ''
		);
	}

	private static function date( \WP_Comment $comment ): string {
		return sprintf( '<time class="fxc-review__date" datetime="%1$s">%2$s</time>', esc_attr( (string) get_comment_date( 'c', $comment ) ), esc_html( (string) get_comment_date( '', $comment ) ) );
	}

	/** The text as themes show comments: through core's comment_text filters (kses, paragraphs, links). */
	private static function text( \WP_Comment $comment ): string {
		return (string) apply_filters( 'comment_text', get_comment_text( $comment ), $comment, [] );
	}

	/** @param array{average:float,count:int,counts:array<int,int>} $summary */
	private static function summary_html( array $summary ): string {
		$bars = '';
		foreach ( $summary['counts'] as $star => $reviews ) {
			/* translators: %d: star rating, 1 to 5. */
			$name  = sprintf( _n( '%d star', '%d stars', $star, 'flexacommerce' ), $star );
			$share = $summary['count'] > 0 ? round( $reviews * 100 / $summary['count'] ) : 0;
			$bars .= sprintf(
				'<li class="fxc-reviews__bar"><span class="fxc-reviews__bar-name">%1$s</span><meter min="0" max="%2$d" value="%3$d">%4$s%%</meter><span class="fxc-reviews__bar-count">%5$s</span></li>',
				esc_html( $name ),
				$summary['count'],
				$reviews,
				esc_html( (string) $share ),
				esc_html( number_format_i18n( $reviews ) )
			);
		}
		/* translators: %s: number of reviews. */
		$based = sprintf( _n( 'Based on %s review', 'Based on %s reviews', $summary['count'], 'flexacommerce' ), number_format_i18n( $summary['count'] ) );
		return sprintf(
			'<div class="fxc-reviews__summary"><p class="fxc-reviews__average"><span class="fxc-reviews__score">%1$s</span> %2$s <span class="fxc-reviews__based">%3$s</span></p><ul class="fxc-reviews__bars">%4$s</ul></div>',
			esc_html( number_format_i18n( $summary['average'], 1 ) ),
			self::stars( $summary['average'] ),
			esc_html( $based ),
			$bars
		);
	}

	private static function pages( int $page, bool $more ): string {
		if ( $page === 1 && ! $more ) {
			return '';
		}
		$link = static fn ( int $to ): string => esc_url( ( $to > 1 ? add_query_arg( self::PAGE_VAR, $to ) : remove_query_arg( self::PAGE_VAR ) ) . '#' . self::ANCHOR );
		$out  = '<nav class="fxc-reviews__pages" aria-label="' . esc_attr__( 'Review pages', 'flexacommerce' ) . '">';
		if ( $page > 1 ) {
			$out .= '<a class="fxc-reviews__newer" href="' . $link( $page - 1 ) . '">' . esc_html__( 'Newer reviews', 'flexacommerce' ) . '</a>';
		}
		if ( $more ) {
			$out .= '<a class="fxc-reviews__older" href="' . $link( $page + 1 ) . '">' . esc_html__( 'Older reviews', 'flexacommerce' ) . '</a>';
		}
		return $out . '</nav>';
	}

	/** Core's comment form with the star field, or why the visitor cannot review. */
	private function form( Product $product, string $refusal ): string {
		if ( $refusal === Reviews::OFF ) {
			return '';
		}
		if ( $refusal === 'login' ) {
			return sprintf(
				'<p class="fxc-reviews__notice">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Only logged-in customers who bought this product can review it.', 'flexacommerce' ),
				esc_url( wp_login_url( (string) get_permalink( $product->id ) . '#' . self::ANCHOR ) ),
				esc_html__( 'Log in', 'flexacommerce' )
			);
		}
		if ( $refusal === Reviews::BUYERS ) {
			return '<p class="fxc-reviews__notice">' . esc_html__( 'Only customers who bought this product can review it.', 'flexacommerce' ) . '</p>';
		}
		// A review needs no website field; back on the product page after posting.
		$no_url = static function ( array $fields ): array {
			unset( $fields['url'] );
			return $fields;
		};
		add_filter( 'comment_form_default_fields', $no_url );
		ob_start();
		comment_form(
			[
				'title_reply'         => __( 'Write a review', 'flexacommerce' ),
				'title_reply_before'  => '<h3 id="reply-title" class="comment-reply-title fxc-reviews__form-title">',
				'title_reply_after'   => '</h3>',
				'label_submit'        => __( 'Submit review', 'flexacommerce' ),
				'class_container'     => 'comment-respond fxc-review-form',
				'comment_notes_after' => '',
				'comment_field'       => self::rating_field() . sprintf(
					'<p class="comment-form-comment"><label for="comment">%1$s <span class="required">*</span></label><textarea id="comment" name="comment" cols="45" rows="6" maxlength="65525" required></textarea></p>',
					esc_html__( 'Your review', 'flexacommerce' )
				) . '<input type="hidden" name="redirect_to" value="' . esc_url( (string) get_permalink( $product->id ) ) . '">',
			],
			$product->id
		);
		remove_filter( 'comment_form_default_fields', $no_url );
		return (string) ob_get_clean();
	}

	/** Five radio buttons drawn as stars; DOM order 5 to 1 so CSS can fill the stars before the picked one. */
	private static function rating_field(): string {
		$stars = '';
		for ( $star = 5; $star >= 1; --$star ) {
			/* translators: %d: star rating, 1 to 5. */
			$name   = sprintf( _n( '%d star', '%d stars', $star, 'flexacommerce' ), $star );
			$stars .= sprintf(
				'<input type="radio" id="fxc-rating-%1$d" name="rating" value="%1$d" required><label for="fxc-rating-%1$d" title="%2$s"><span class="screen-reader-text">%2$s</span></label>',
				$star,
				esc_attr( $name )
			);
		}
		return sprintf(
			'<fieldset class="fxc-rating-input"><legend>%1$s <span class="required">*</span></legend><span class="fxc-rating-input__stars">%2$s</span></fieldset>',
			esc_html__( 'Your rating', 'flexacommerce' ),
			$stars
		);
	}
}
