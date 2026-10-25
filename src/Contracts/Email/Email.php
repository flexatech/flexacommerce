<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Email;

defined( 'ABSPATH' ) || exit;

/**
 * A transactional email (doc 04 §7). Add one through the
 * `flexacommerce.emails.register` filter. Its trigger hooks are bound when
 * the order module is built, which every order write does first, so an
 * order action is always heard; an action fired from a request that never
 * touches orders is not.
 *
 * @api
 */
interface Email {
	/** [a-z0-9_]{1,64}; also names the settings option and the filters. */
	public function id(): string;

	/** Shown in the email settings and in the order history. */
	public function title(): string;

	/** Customer emails go to the order's email; admin emails to the recipients setting. */
	public function to_customer(): bool;

	/** @return array<string,int> action name => number of arguments it passes */
	public function trigger_hooks(): array;

	/**
	 * The message for one call of a trigger hook, or null when this call
	 * does not send. Subject and heading may use {site_title},
	 * {order_number} and {order_date}.
	 *
	 * @param list<mixed> $args the action's arguments
	 */
	public function render( string $hook, array $args ): ?EmailMessage;
}
