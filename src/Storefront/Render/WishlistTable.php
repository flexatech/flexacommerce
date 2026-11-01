<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Storefront\AccountPage;
use Flexa\Commerce\Storefront\FormHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The products of a wishlist (ADR-066): image, name, price and the list
 * version of the add-to-cart form, which buys a simple product in one
 * click and links a variable one to its page. The owner gets a Remove
 * button and the share link; a shared copy is read-only.
 */
final class WishlistTable {
	public function __construct(
		private readonly ProductRepository $products,
		private readonly ProductPrice $price,
		private readonly AddToCartForm $form,
	) {}

	/** @param list<int> $ids */
	public function html( array $ids, bool $own, string $share_key, int $user_id ): string {
		return Component::wrap(
			'wishlist',
			fn (): string => $this->markup( $ids, $own, $share_key, $user_id ),
			[
				'product_ids' => $ids,
				'own'         => $own,
			]
		);
	}

	/** @param list<int> $ids */
	private function markup( array $ids, bool $own, string $share_key, int $user_id ): string {
		$html = '<div class="fxc-wishlist">' . ( $own ? FormHandler::notice_for( 0, FormHandler::SCOPE_WISHLIST ) : '' );
		if ( $ids !== [] ) {
			_prime_post_caches( $ids );
			$this->products->prime( $ids );
		}
		$rows = '';
		foreach ( $ids as $id ) {
			$product = $this->products->find( $id );
			if ( $product === null || $product->status !== 'publish' ) {
				continue;
			}
			$link   = (string) get_permalink( $product->id );
			$image  = get_the_post_thumbnail( $product->id, 'thumbnail', [ 'alt' => '' ] );
			$remove = '';
			if ( $own ) {
				$remove = sprintf(
					'<form method="post" action="%1$s">%2$s<input type="hidden" name="product_id" value="%3$d"><button type="submit" class="fxc-wishlist__remove" aria-label="%4$s">%5$s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					FormHandler::hidden_fields( 'wishlist_remove' ),
					$product->id,
					/* translators: %s: product name */
					esc_attr( sprintf( __( 'Remove %s from your wishlist', 'flexacommerce' ), $product->title ) ),
					esc_html__( 'Remove', 'flexacommerce' )
				);
			}
			$rows .= sprintf(
				'<tr><td class="fxc-wishlist__image">%1$s</td><th scope="row"><a href="%2$s">%3$s</a></th><td>%4$s</td><td>%5$s</td>%6$s</tr>',
				$image !== '' ? '<a href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">' . $image . '</a>' : '',
				esc_url( $link ),
				esc_html( $product->title ),
				$this->price->html( $product, $user_id ),
				$this->form->html( $product, AddToCartForm::LOOP, $user_id, false ),
				$own ? '<td>' . $remove . '</td>' : ''
			);
		}
		if ( $rows === '' ) {
			$shop = get_post_type_archive_link( PostTypes::PRODUCT );
			return $html . '<p class="fxc-notice">' . esc_html__( 'No products saved yet.', 'flexacommerce' ) . '</p>'
				. ( is_string( $shop ) ? '<p><a href="' . esc_url( $shop ) . '">' . esc_html__( 'Browse products', 'flexacommerce' ) . '</a></p>' : '' )
				. '</div>';
		}
		$html .= '<table class="fxc-wishlist__table"><caption>' . esc_html__( 'Wishlist', 'flexacommerce' ) . '</caption><thead><tr>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Image', 'flexacommerce' ) . '</span></th>'
			. '<th scope="col">' . esc_html__( 'Product', 'flexacommerce' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Price', 'flexacommerce' ) . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Buy', 'flexacommerce' ) . '</span></th>'
			. ( $own ? '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'flexacommerce' ) . '</span></th>' : '' )
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
		if ( $own && $share_key !== '' ) {
			$html .= sprintf(
				'<p class="fxc-wishlist__share"><label for="fxc-wishlist-share">%1$s</label> <input id="fxc-wishlist-share" type="url" readonly value="%2$s"></p><p class="fxc-wishlist__hint">%3$s</p>',
				esc_html__( 'Share link', 'flexacommerce' ),
				esc_url( AccountPage::url( 'wishlist', $share_key ) ),
				esc_html__( 'Anyone with this link can see the products in your wishlist, nothing else.', 'flexacommerce' )
			);
		}
		return $html . '</div>';
	}
}
