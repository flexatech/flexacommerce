<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Checkout\CheckoutLayout;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Migration\Sources\WooCheckoutReader;
use Flexa\Commerce\Plugin;

/**
 * The Checkout Field Editor's fields into the checkout layout (doc 05
 * §5.10, ADR-036). The extension is not installed in the test site; its
 * options are written as it stores them.
 */
final class CheckoutFieldMigrationTest extends \WP_UnitTestCase {
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = Plugin::instance()->migration()->service();
		$this->clean();
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback: a migration write commits.
		$this->clean();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
	}

	public function test_the_extension_fields_become_the_checkout_layout(): void {
		CheckoutLayout::save(
			[
				'fields' => [
					'billing' => [
						[
							'id'     => 'loyalty',
							'label'  => 'Loyalty number',
							'type'   => 'text',
							'answer' => 'optional',
						],
					],
				],
				'page'   => [ 'flow' => 'steps' ],
			]
		);
		update_option(
			'wc_fields_billing',
			[
				'billing_last_name'  => self::field( 'Surname', 20, [ 'class' => [ 'form-row-last' ] ] ),
				'billing_first_name' => self::field( 'First name', 10, [ 'class' => [ 'form-row-first' ] ] ),
				'billing_email'      => self::field( 'Email address', 30, [ 'enabled' => 0 ] ),
				'billing_company'    => self::field( 'Company name', 40, [ 'enabled' => 0 ] ),
				'billing_country'    => self::field( 'Country / Region', 50 ),
				'billing_address_1'  => self::field( 'Street address', 60, [ 'placeholder' => 'House number and street name' ] ),
				'billing_city'       => self::field( 'Town / City', 70 ),
				'billing_state'      => self::field( 'State / County', 80, [ 'required' => 0 ] ),
				'billing_postcode'   => self::field( 'Postcode / ZIP', 90 ),
				'billing_phone'      => self::field( 'Mobile', 100, [ 'required' => 0 ] ),
				'billing_vat'        => self::field(
					'<strong>VAT</strong> number',
					35,
					[
						'custom'        => 1,
						'type'          => 'text',
						'class'         => [ 'form-row-last' ],
						'placeholder'   => 'GB123',
						'show_in_email' => 0,
					]
				),
				'billing_source'     => self::field(
					'How did you hear about us?',
					110,
					[
						'custom'   => 1,
						'type'     => 'select',
						'required' => 0,
						'options'  => [
							''              => 'Choose one',
							'Google Search' => 'Google',
							'friend'        => 'A friend &amp; family',
						],
					]
				),
				'billing_extras'     => self::field(
					'Extras',
					120,
					[
						'custom'  => 1,
						'type'    => 'checkboxgroup',
						'options' => [ 'a' => 'A' ],
					]
				),
				'billing_title'      => self::field(
					'More about you',
					105,
					[
						'custom' => 1,
						'type'   => 'heading',
					]
				),
				'billing_slot'       => self::field(
					'Delivery slot',
					130,
					[
						'custom'   => 1,
						'type'     => 'datetime_local',
						'required' => 0,
					]
				),
			]
		);
		update_option(
			'wc_fields_additional',
			[
				'order_comments' => self::field(
					'Order notes',
					10,
					[
						'enabled' => 0,
						'type'    => 'textarea',
					]
				),
				'gift_message'   => self::field(
					'Gift message',
					20,
					[
						'custom'                 => 1,
						'type'                   => 'textarea',
						'conditional_rules_json' => '%5B%7B%22a%22%3A1%7D%5D',
					]
				),
			]
		);

		$job = $this->run_job();
		$this->assertContains( $job->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $job->id ) ) );
		$this->assertSame( 1, $job->counter( 'checkout', 'created' ) );

		$layout  = CheckoutLayout::current();
		$billing = $layout->section( 'billing' );
		$this->assertSame(
			[ 'first_name', 'last_name', 'email', 'vat', 'company', 'country', 'address_1', 'city', 'state', 'postcode', 'phone', 'source', 'slot', 'address_2', 'loyalty' ],
			array_column( $billing, 'id' ),
			'The extension order, then the core fields it removed, then the fields made in FlexaCommerce.'
		);
		$by = array_column( $billing, null, 'id' );
		$this->assertSame( [ '', 'half', 'required' ], [ $by['first_name']['label'], $by['first_name']['width'], $by['first_name']['answer'] ], 'WooCommerce\'s own label leaves the FlexaCommerce one.' );
		$this->assertSame( [ 'Surname', 'half' ], [ $by['last_name']['label'], $by['last_name']['width'] ] );
		$this->assertSame( 'required', $by['email']['answer'], 'Email stays required.' );
		$this->assertSame( 'hidden', $by['company']['answer'] );
		$this->assertSame( '', $by['address_1']['placeholder'] );
		$this->assertSame( 'required', $by['state']['answer'], 'The state follows the country.' );
		$this->assertSame( [ 'Mobile', 'optional' ], [ $by['phone']['label'], $by['phone']['answer'] ] );
		$this->assertSame( 'hidden', $by['address_2']['answer'], 'A core field removed in the extension is hidden.' );
		$this->assertSame(
			[ 'VAT number', 'text', 'half', 'required', 'GB123', false ],
			[ $by['vat']['label'], $by['vat']['type'], $by['vat']['width'], $by['vat']['answer'], $by['vat']['placeholder'], $by['vat']['email'] ]
		);
		$this->assertSame(
			[
				[
					'value' => 'google-search',
					'label' => 'Google',
				],
				[
					'value' => 'friend',
					'label' => 'A friend & family',
				],
			],
			$by['source']['options'],
			'The "choose" line of a select is left out.'
		);
		$this->assertSame( [ 'text', 'optional' ], [ $by['slot']['type'], $by['slot']['answer'] ] );

		$additional = array_column( $layout->section( 'additional' ), null, 'id' );
		$this->assertSame( 'hidden', $additional['customer_note']['answer'] );
		$this->assertSame( [ 'textarea', 'optional' ], [ $additional['gift_message']['type'], $additional['gift_message']['answer'] ], 'A field that showed on conditions is no longer required.' );
		$this->assertSame( CheckoutLayout::default()->section( 'shipping' ), $layout->section( 'shipping' ), 'A section the extension never saved stays.' );
		$this->assertSame( CheckoutLayout::FLOW_STEPS, $layout->flow(), 'The page stays.' );

		$codes = array_count_values( array_column( $this->service->log( $job->id )['rows'], 'code' ) );
		foreach ( [ 'checkout_field_locked', 'checkout_field_multiple', 'checkout_field_logic_dropped' ] as $code ) {
			$this->assertSame( 1, $codes[ $code ] ?? 0, $code );
		}
		$this->assertArrayNotHasKey( 'checkout_field_text', $codes, 'Notes stay out of the log unless it is verbose.' );
		$messages = implode( "\n", array_column( $this->service->log( $job->id )['rows'], 'message' ) );
		$this->assertStringContainsString( 'The billing field "Extras" (billing_extras) was left out', $messages, 'The report names the field.' );
	}

	public function test_a_second_run_updates_the_layout(): void {
		update_option( 'wc_fields_additional', [ 'gift' => self::field( 'Gift note', 10, [ 'custom' => 1 ] ) ] );
		$this->assertSame( 1, $this->run_job()->counter( 'checkout', 'created' ) );
		$this->assertSame( 1, $this->run_job()->counter( 'checkout', 'unchanged' ) );

		update_option( 'wc_fields_additional', [ 'gift' => self::field( 'Gift card text', 10, [ 'custom' => 1 ] ) ] );
		$this->assertSame( 1, $this->run_job()->counter( 'checkout', 'updated' ) );
		$gift = CheckoutLayout::current()->custom( 'additional', 'gift' );
		$this->assertSame( 'Gift card text', $gift['label'] ?? null );
		$this->assertCount( 1, array_filter( CheckoutLayout::current()->section( 'additional' ), static fn ( array $e ): bool => $e['custom'] ) );
	}

	public function test_a_store_without_the_extension_has_nothing_to_import(): void {
		$job = $this->run_job();
		$this->assertSame( 0, $job->counter( 'checkout', 'created' ) );
		$this->assertFalse( get_option( CheckoutLayout::OPTION, false ) );
	}

	/**
	 * @param array<string,mixed> $more
	 * @return array<string,mixed>
	 */
	private static function field( string $label, int $priority, array $more = [] ): array {
		return $more + [
			'type'          => 'text',
			'label'         => $label,
			'placeholder'   => '',
			'class'         => [ 'form-row-wide' ],
			'required'      => 1,
			'enabled'       => 1,
			'priority'      => $priority,
			'custom'        => 0,
			'show_in_email' => 1,
			'show_in_order' => 1,
		];
	}

	private function clean(): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'id_map' ), [ 'entity' => Record::CHECKOUT_FIELDS ] ); // phpcs:ignore WordPress.DB
		foreach ( WooCheckoutReader::OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( CheckoutLayout::OPTION );
	}

	private function run_job(): Job {
		$job = $this->service->create(
			Direction::WC_TO_FXC,
			[
				'backup_confirmed' => true,
				'stages'           => [ 'checkout' ],
			],
			false,
			1
		);
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}
}
