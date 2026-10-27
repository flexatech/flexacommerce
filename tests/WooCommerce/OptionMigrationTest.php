<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Targets\FxcOptionWriter;
use Flexa\Commerce\Options\OptionField;
use Flexa\Commerce\Options\OptionSet;
use Flexa\Commerce\Options\OptionSetRepository;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Plugin;

/**
 * WooCommerce Product Add-ons into option sets (doc 05 §5.9, ADR-029). The
 * extension is not installed in the test site; its data is written as it
 * stores it.
 */
final class OptionMigrationTest extends \WP_UnitTestCase {
	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
		update_option( ProductOptions::OPTION, [], true );
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a migration write commits, which can keep both options.
		update_option( ProductOptions::OPTION, [], true );
		delete_option( FxcOptionWriter::ORDER_OPTION );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_add_ons_become_option_sets(): void {
		$engraving = [
			self::addon(
				'multiple_choice',
				'Gift wrap',
				[
					'display'            => 'radiobutton',
					'required'           => 1,
					'description_enable' => 1,
					'description'        => '<p>Wrapped by hand.</p>',
					'options'            => [
						self::choice( 'Paper', '2', 'quantity_based' ),
						self::choice( 'Box', '5', 'flat_fee' ),
						self::choice( 'Ribbon', '10', 'percentage_based' ),
						self::choice( 'None', '' ),
					],
				]
			),
			self::addon(
				'custom_text',
				'Engraving',
				[
					'adjust_price'      => 1,
					'price'             => '3.50',
					'price_type'        => 'flat_fee',
					'restrictions'      => 1,
					'restrictions_type' => 'only_letters',
					'max'               => '12',
				]
			),
			self::addon( 'heading', 'Extras' ),
			self::addon(
				'checkbox',
				'Extras',
				[
					'options' => [ self::choice( 'Card', '1.005', 'quantity_based' ), self::choice( 'Refund', '-2', 'flat_fee' ) ],
				]
			),
			self::addon(
				'multiple_choice',
				'Pattern',
				[
					'display' => 'images',
					'options' => [ self::choice( 'Stars', '0' ), self::choice( 'Dots', '0' ) ],
				]
			),
			self::addon( 'file_upload', 'Your photo' ),
			self::addon( 'custom_price', 'Name a price' ),
			self::addon(
				'input_multiplier',
				'Letters',
				[
					'adjust_price' => 1,
					'price'        => '1',
					'restrictions' => 1,
					'min'          => '1',
					'max'          => '20',
				]
			),
			self::addon( 'datepicker', 'Delivery day', [ 'adjust_price' => 0 ] ),
			self::addon(
				'custom_textarea',
				'Message',
				[
					'conditional_logic' => 1,
					'required'          => 1,
				]
			),
		];
		$tee       = $this->store->products['tee'];
		$sale      = $this->store->products['sale'];
		update_post_meta( $tee, '_product_addons', $engraving );
		update_post_meta( $sale, '_product_addons', $engraving );
		update_post_meta( $this->store->products['unicode'], '_product_addons', [ self::addon( 'custom_text', 'Name' ) ] );

		$job = $this->run_job();
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 2, $job->counter( 'options', 'created' ), 'Products with the same add-ons share one set.' );

		$set = $this->sets()->find( $this->local_id( $tee ) );
		$this->assertNotNull( $set );
		$this->assertSame( 'Plain Tee and 1 more', $set->name );
		$this->assertSame( OptionSet::PRODUCTS, $set->applies_to );
		$this->assertSame( [ $this->product( $tee ), $this->product( $sale ) ], $set->product_ids );
		$this->assertSame(
			[
				[ OptionField::RADIO, 'Gift wrap' ],
				[ OptionField::TEXT, 'Engraving' ],
				[ OptionField::HEADING, 'Extras' ],
				[ OptionField::CHECKBOXES, 'Extras' ],
				[ OptionField::BUTTON, 'Pattern' ],
				[ OptionField::NUMBER, 'Letters' ],
				[ OptionField::DATE, 'Delivery day' ],
				[ OptionField::TEXTAREA, 'Message' ],
			],
			array_map( static fn ( OptionField $f ): array => [ $f->type, $f->label ], $set->fields )
		);

		[ $wrap, $text, , $extras, , $letters, $day, $message ] = $set->fields;
		$this->assertTrue( $wrap->required );
		$this->assertSame( 'Wrapped by hand.', $wrap->help );
		$this->assertSame(
			[
				[ 'Paper', OptionField::FIXED, 200 ],
				[ 'Box', OptionField::ONCE, 500 ],
				[ 'Ribbon', OptionField::PERCENT, 1000 ],
				[ 'None', OptionField::NONE, 0 ],
			],
			array_map( static fn ( array $c ): array => [ $c['label'], $c['price_mode'], $c['price'] ], $wrap->choices ),
			'A flat fee is charged once per line, a quantity based price for every unit.'
		);
		$this->assertSame( [ OptionField::ONCE, 350, 12 ], [ $text->price_mode, $text->price, $text->max_length ] );
		$this->assertSame( [ [ OptionField::FIXED, 101 ], [ OptionField::NONE, 0 ] ], array_map( static fn ( array $c ): array => [ $c['price_mode'], $c['price'] ], $extras->choices ) );
		$this->assertSame( [ 1.0, 20.0, OptionField::NONE ], [ $letters->min, $letters->max, $letters->price_mode ] );
		$this->assertSame( OptionField::NONE, $day->price_mode, 'Without "Adjust price" the add-on is free.' );
		$this->assertFalse( $message->required, 'A field that showed on conditions is no longer required.' );

		$codes = array_count_values( array_column( $this->service->log( $job->id )['rows'], 'code' ) );
		foreach ( [ 'option_file_upload', 'option_custom_price', 'option_multiplier_price', 'option_logic_dropped', 'option_price_negative', 'option_price_rounded' ] as $code ) {
			$this->assertSame( 1, $codes[ $code ] ?? 0, $code );
		}
		$messages = implode( "\n", array_column( $this->service->log( $job->id )['rows'], 'message' ) );
		$this->assertStringContainsString( 'The add-on "Your photo" in "Plain Tee" is a file upload', $messages, 'The report names the set and the field.' );
	}

	public function test_global_groups_keep_their_targets_and_order(): void {
		$tee     = $this->store->products['tee'];
		$late    = $this->global_group( 'Late group', 20, [ self::addon( 'custom_text', 'Late' ) ], [ '_all_products' => '1' ] );
		$shirts  = $this->global_group( 'Shirt group', 10, [ self::addon( 'custom_text', 'Size note' ) ], [ '_all_products' => '0' ], [ $this->store->terms['shirts'] ] );
		$nowhere = $this->global_group( 'Nowhere', 5, [ self::addon( 'custom_text', 'Nothing' ) ], [ '_all_products' => '0' ] );
		update_post_meta( $tee, '_product_addons', [ self::addon( 'custom_text', 'Own' ) ] );
		update_post_meta( $this->store->products['unicode'], '_product_addons_exclude_global', '1' );
		$this->sets()->save(
			[
				'name'   => 'Made here',
				'fields' => [
					[
						'type'  => OptionField::TEXT,
						'label' => 'Kept',
					],
				],
			]
		);

		$job = $this->run_job();
		$this->assertSame( 4, $job->counter( 'options', 'created' ) );

		$all = $this->sets()->find( $this->local_id( $late ) );
		$this->assertSame( [ OptionSet::ALL_PRODUCTS, true ], [ $all?->applies_to, $all?->enabled ] );
		$this->assertSame( [ $this->product( $this->store->products['unicode'] ) ], $all?->exclude_product_ids, 'A product that left out global add-ons is excluded.' );

		$cats = $this->sets()->find( $this->local_id( $shirts ) );
		$this->assertSame( OptionSet::CATEGORIES, $cats?->applies_to );
		$this->assertSame( [ $this->term( $this->store->terms['shirts'] ) ], $cats?->category_ids );

		$off = $this->sets()->find( $this->local_id( $nowhere ) );
		$this->assertSame( [ OptionSet::ALL_PRODUCTS, false ], [ $off?->applies_to, $off?->enabled ], 'A group for no products is imported turned off.' );

		$this->assertSame(
			[ 'Nowhere', 'Plain Tee', 'Shirt group', 'Late group', 'Made here' ],
			array_map( static fn ( OptionSet $s ): string => $s->name, $this->sets()->all() ),
			'By priority, a product\'s own add-ons first; the set made here stays after them.'
		);
		$this->assertFalse( get_option( FxcOptionWriter::ORDER_OPTION ) );
	}

	public function test_a_second_run_updates_the_same_set(): void {
		$tee = $this->store->products['tee'];
		update_post_meta( $tee, '_product_addons', [ self::addon( 'custom_text', 'First' ) ] );
		$job = $this->run_job();
		$id  = $this->local_id( $tee );

		$this->service->start( $job->id );
		$this->assertSame( 0, $this->service->run( $job->id )->counter( 'options', 'updated' ), 'Unchanged add-ons are skipped.' );

		update_post_meta( $tee, '_product_addons', [ self::addon( 'custom_text', 'Second' ) ] );
		$this->service->start( $job->id );
		$this->assertSame( 1, $this->service->run( $job->id )->counter( 'options', 'updated' ) );
		$this->assertCount( 1, $this->sets()->all() );
		$this->assertSame( 'Second', $this->sets()->find( $id )?->fields[0]->label );
	}

	public function test_add_ons_with_no_field_fxc_has_are_rejected(): void {
		update_post_meta( $this->store->products['tee'], '_product_addons', [ self::addon( 'file_upload', 'Photo' ) ] );
		$job = $this->run_job();
		$this->assertSame( 1, $job->counter( 'options', 'rejected' ) );
		$this->assertSame( [], $this->sets()->all() );
	}

	/**
	 * @param list<array<string,mixed>> $addons
	 * @param array<string,string>      $meta
	 * @param list<int>                 $categories
	 */
	private function global_group( string $name, int $priority, array $addons, array $meta, array $categories = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_type'   => 'global_product_addon',
				'post_title'  => $name,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $id, '_priority', (string) $priority );
		update_post_meta( $id, '_product_addons', $addons );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		if ( $categories !== [] ) {
			wp_set_object_terms( $id, $categories, 'product_cat' );
		}
		return $id;
	}

	/**
	 * @param array<string,mixed> $more
	 * @return array<string,mixed>
	 */
	private static function addon( string $type, string $name, array $more = [] ): array {
		return $more + [
			'name'               => $name,
			'title_format'       => 'label',
			'description_enable' => 0,
			'description'        => '',
			'type'               => $type,
			'display'            => 'select',
			'position'           => 0,
			'required'           => 0,
			'restrictions'       => 0,
			'restrictions_type'  => 'any_text',
			'adjust_price'       => 0,
			'price_type'         => 'flat_fee',
			'price'              => '',
			'min'                => 0,
			'max'                => 0,
			'options'            => [],
		];
	}

	/** @return array<string,string> */
	private static function choice( string $label, string $price, string $type = 'flat_fee' ): array {
		return [
			'label'      => $label,
			'price'      => $price,
			'image'      => '',
			'price_type' => $type,
		];
	}

	private function sets(): OptionSetRepository {
		return new OptionSetRepository();
	}

	private function local_id( int $wc_id ): int {
		return $this->mapped( 'option_set', $wc_id );
	}

	private function product( int $wc_id ): int {
		return $this->mapped( 'product', $wc_id );
	}

	private function term( int $wc_id ): int {
		return $this->mapped( 'term', $wc_id );
	}

	private function mapped( string $entity, int $wc_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE entity = %s AND remote_id = %s', Schema::table( 'id_map' ), $entity, (string) $wc_id ) );
	}

	private function run_job(): Job {
		$job = $this->service->create(
			Direction::WC_TO_FXC,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'terms', 'attributes', 'products', 'options' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}
}
