<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Invoices\InvoiceSettings;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice settings and an order's invoice (ADR-060). The PDFs come from
 * admin-post.php, which can stream a file to a new tab.
 */
final class InvoicesEndpoint extends Endpoint {
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin/invoices/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'settings' ],
					'permission_callback' => [ $this, 'manage_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'manage_permission' ],
					'args'                => [
						'prefix'          => [
							'type'      => 'string',
							'maxLength' => 40,
						],
						'suffix'          => [
							'type'      => 'string',
							'maxLength' => 40,
						],
						'padding'         => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 12,
						],
						'reset'           => [
							'type' => 'string',
							'enum' => InvoiceSettings::RESETS,
						],
						'attach_to'       => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'customer_access' => [
							'type' => 'string',
							'enum' => InvoiceSettings::ACCESS,
						],
						'shop_name'       => [ 'type' => 'string' ],
						'shop_address'    => [ 'type' => 'string' ],
						'shop_details'    => [ 'type' => 'string' ],
						'logo_id'         => [
							'type'    => 'integer',
							'minimum' => 0,
						],
						'footer'          => [ 'type' => 'string' ],
						'paper'           => [
							'type' => 'string',
							'enum' => InvoiceSettings::PAPERS,
						],
						'show_sku'        => [ 'type' => 'boolean' ],
						'next_number'     => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'credit_prefix'   => [
							'type'      => 'string',
							'maxLength' => 40,
						],
						'credit_suffix'   => [
							'type'      => 'string',
							'maxLength' => 40,
						],
						'credit_attach'   => [ 'type' => 'boolean' ],
						'next_credit'     => [
							'type'    => 'integer',
							'minimum' => 1,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/orders/(?P<id>\d+)/invoice',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'order_invoice' ],
					'permission_callback' => [ $this, 'view_orders_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'edit_orders_permission' ],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( $this->settings_data(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service = Plugin::instance()->invoices();
		$partial = [];
		foreach ( array_keys( InvoiceSettings::get() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$partial[ $key ] = $request->get_param( $key );
			}
		}
		if ( isset( $partial['logo_id'] ) && (int) $partial['logo_id'] > 0 && ! wp_attachment_is_image( (int) $partial['logo_id'] ) ) {
			return new WP_Error(
				'flexacommerce_invalid_logo',
				__( 'The logo must be an image from the media library.', 'flexacommerce' ),
				[
					'status' => 400,
					'field'  => 'logo_id',
				]
			);
		}
		if ( $partial !== [] ) {
			InvoiceSettings::update( $partial );
			$service->reset();
		}
		if ( $request->has_param( 'next_number' ) ) {
			$next    = (int) $request->get_param( 'next_number' );
			$current = $service->next_counter();
			if ( $next < $current ) {
				return new WP_Error(
					'flexacommerce_invoice_number_lower',
					/* translators: %d: the lowest number the next invoice can get */
					sprintf( __( 'The next invoice number cannot go back; it must be %d or higher, so no number is used twice.', 'flexacommerce' ), $current ),
					[
						'status' => 400,
						'field'  => 'next_number',
					]
				);
			}
			$service->set_next_counter( $next );
		}
		if ( $request->has_param( 'next_credit' ) ) {
			$next    = (int) $request->get_param( 'next_credit' );
			$current = $service->next_counter( true );
			if ( $next < $current ) {
				return new WP_Error(
					'flexacommerce_credit_number_lower',
					/* translators: %d: the lowest number the next credit note can get */
					sprintf( __( 'The next credit note number cannot go back; it must be %d or higher, so no number is used twice.', 'flexacommerce' ), $current ),
					[
						'status' => 400,
						'field'  => 'next_credit',
					]
				);
			}
			$service->set_next_counter( $next, true );
		}
		return new WP_REST_Response( $this->settings_data(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function order_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = Plugin::instance()->orders()->orders()->find( (int) $request->get_param( 'id' ) );
		if ( $order === null ) {
			return new WP_Error( 'flexacommerce_order_not_found', __( 'Order not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( $this->order_data( $order->id ), 200 );
	}

	/**
	 * Gives the order its invoice number now, without making the PDF.
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = Plugin::instance()->orders()->orders()->find( (int) $request->get_param( 'id' ) );
		if ( $order === null ) {
			return new WP_Error( 'flexacommerce_order_not_found', __( 'Order not found.', 'flexacommerce' ), [ 'status' => 404 ] );
		}
		try {
			Plugin::instance()->invoices()->create( $order );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'flexacommerce_invoice_busy', __( 'The invoice is being created by another request. Try again in a moment.', 'flexacommerce' ), [ 'status' => 409 ] );
		}
		return new WP_REST_Response( $this->order_data( $order->id ), 201 );
	}

	/** @return array<string,mixed> */
	private function order_data( int $order_id ): array {
		$service = Plugin::instance()->invoices();
		$order   = Plugin::instance()->orders()->orders()->find( $order_id );
		$invoice = $service->find( $order_id );
		$notes   = $service->credit_notes( $order_id );
		$credits = [];
		foreach ( $order !== null && $order->amount_refunded > 0 ? $service->refunds( $order ) : [] as $refund ) {
			$note      = $notes[ $refund->id ] ?? null;
			$credits[] = [
				'refund_id'    => $refund->id,
				'amount'       => html_entity_decode( Plugin::instance()->services()->money()->format( $order->money( $refund->amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'refunded_at'  => self::iso( (string) ( $refund->completed_at ?? $refund->created_at ) ),
				'number'       => $note?->number,
				'date'         => $note !== null ? self::iso( $note->date ) : null,
				'customer_can' => $service->customer_can_credit( $order, $refund->id ),
				'customer_url' => $service->customer_url( $order, $refund->id ),
			];
		}
		return [
			'invoice'      => $invoice === null ? null : [
				'number' => $invoice->number,
				'date'   => self::iso( $invoice->date ),
			],
			'customer_can' => $order !== null && $service->customer_can( $order ),
			'customer_url' => $order !== null ? $service->customer_url( $order ) : '',
			'credit_notes' => $credits,
		];
	}

	private static function iso( string $utc ): ?string {
		return $utc !== '' ? str_replace( ' ', 'T', $utc ) . 'Z' : null;
	}

	/** @return array<string,mixed> */
	private function settings_data(): array {
		$service  = Plugin::instance()->invoices();
		$settings = $service->settings();
		$emails   = [];
		foreach ( Plugin::instance()->notifications()->emails() as $email ) {
			$emails[] = [
				'id'          => $email->id(),
				'title'       => $email->title(),
				'to_customer' => $email->to_customer(),
			];
		}
		$logo = $settings['logo_id'] > 0 ? wp_get_attachment_image_url( $settings['logo_id'], 'medium' ) : false;
		return [
			'settings'              => $settings,
			'next_number'           => $service->next_counter(),
			'next_formatted'        => $service->next_number(),
			'next_credit'           => $service->next_counter( true ),
			'next_credit_formatted' => $service->next_number( true ),
			'paper'                 => InvoiceSettings::paper( $settings['paper'] ),
			'emails'                => $emails,
			'logo_url'              => is_string( $logo ) ? $logo : '',
		];
	}
}
