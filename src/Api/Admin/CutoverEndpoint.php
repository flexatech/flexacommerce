<?php

declare(strict_types=1);

namespace Flexa\Commerce\Api\Admin;

use Flexa\Commerce\Api\Endpoint;
use Flexa\Commerce\Migration\Cutover;
use Flexa\Commerce\Migration\MigrationError;
use Flexa\Commerce\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/** The cutover wizard of the Migration screen (doc 05 §9.2). */
final class CutoverEndpoint extends Endpoint {
	private const BASE = '/admin/migrations/cutover';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::BASE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'show' ],
				'permission_callback' => [ $this, 'manage_permission' ],
			]
		);
		// One route for every step: this class loads on each admin REST
		// request, so it keeps registration small and leaves Cutover, which
		// checks the step's own input, unloaded (doc 10 F6).
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/(?P<step>freeze|verify|bases|pages|urls|redirects|reopen|rollback)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'step' ],
				'permission_callback' => [ $this, 'manage_permission' ],
				'args'                => [
					'job_id'               => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'acknowledge_warnings' => [
						'type'    => 'boolean',
						'default' => false,
					],
					'mode'                 => [
						'type' => 'string',
						'enum' => [ 'flexacommerce', 'woocommerce' ],
					],
					'accept_failures'      => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( self::cutover()->status(), 200 );
	}

	/** @param WP_REST_Request<array<string,mixed>> $request */
	public function step( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$step    = (string) $request->get_param( 'step' );
		$cutover = self::cutover();
		try {
			$status = match ( $step ) {
				'freeze'    => $cutover->freeze(),
				'verify'    => $cutover->verify( (int) $request->get_param( 'job_id' ), (bool) $request->get_param( 'acknowledge_warnings' ) ),
				'bases'     => $cutover->adopt_bases(),
				'pages'     => $cutover->assign_pages( (string) $request->get_param( 'mode' ) ),
				'urls'      => $cutover->check_urls( (bool) $request->get_param( 'accept_failures' ) ),
				'redirects' => $cutover->enable_redirects(),
				'reopen'    => $cutover->reopen(),
				default     => $cutover->rollback(),
			};
		} catch ( MigrationError $e ) {
			return new WP_Error(
				'flexacommerce_cutover_' . $e->reason,
				$e->getMessage(),
				[
					'status'  => $e->reason === 'not_found' ? 404 : 409,
					'cutover' => $cutover->status(),
				]
			);
		}
		return new WP_REST_Response( $status, 200 );
	}

	private static function cutover(): Cutover {
		return Plugin::instance()->migration()->cutover();
	}
}
