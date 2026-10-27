<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Checkout;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckoutLayout;
use Flexa\Commerce\Tests\Unit\TestCase;

/** A checkout in steps: Information, Shipping, Payment (doc 11 §12). */
final class CheckoutStepsTest extends TestCase {
	public function test_one_page_is_the_default_and_a_damaged_flow_falls_back_to_it(): void {
		self::assertSame( CheckoutLayout::FLOW_PAGE, CheckoutLayout::default()->flow() );
		self::assertSame( 'page', CheckoutLayout::default()->to_array()['page']['flow'] );
		self::assertSame( CheckoutLayout::FLOW_STEPS, CheckoutLayout::from_array( [ 'page' => [ 'flow' => 'steps' ] ] )->flow() );
		self::assertSame( CheckoutLayout::FLOW_PAGE, CheckoutLayout::from_array( [ 'page' => [ 'flow' => 'wizard' ] ] )->flow() );
	}

	public function test_the_builder_gets_a_flow_it_does_not_know(): void {
		try {
			CheckoutLayout::save( [ 'page' => [ 'flow' => 'wizard' ] ] );
			self::fail( 'saved' );
		} catch ( InvalidCheckoutLayout $e ) {
			self::assertSame( 'page.flow', $e->field );
		}
	}

	public function test_each_block_has_its_step(): void {
		$layout = CheckoutLayout::from_array(
			[
				'page' => [
					'flow' => 'steps',
					'main' => [
						$this->text( 'text_a1' ),
						[ 'id' => 'billing' ],
						[ 'id' => 'shipping' ],
						[ 'id' => 'additional' ],
						$this->text( 'text_b2' ),
						[ 'id' => 'shipping_method' ],
						$this->text( 'text_c3' ),
					],
					'side' => [
						$this->text( 'text_d4' ),
						[ 'id' => 'summary' ],
						[ 'id' => 'payment' ],
					],
				],
			]
		);
		$steps  = static fn ( string $column ): array => array_map(
			static fn ( int $i ): string => $layout->step( $column, $i ),
			array_keys( $layout->blocks( $column ) )
		);
		self::assertSame(
			[ 'information', 'information', 'information', 'information', 'shipping', 'shipping', 'shipping' ],
			$steps( CheckoutLayout::MAIN ),
			'content goes with the section under it, at the end with the one above'
		);
		self::assertSame( [ '', '', 'payment' ], $steps( CheckoutLayout::SIDE ), 'the summary and its heading show in every step' );
	}

	/** @return array{id:string,type:string,text:string} */
	private function text( string $id ): array {
		return [
			'id'   => $id,
			'type' => 'heading',
			'text' => 'Note',
		];
	}
}
