<?php

declare(strict_types=1);

namespace Flexa\Commerce\Maintenance;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Setup\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The single destructive path: drops every table, option, role and cron
 * event of the current site. Uninstall and WP-CLI both call this.
 */
final class Eraser {
	public static function erase_site(): void {
		Cron::unschedule();
		Schema::drop_all();
		Roles::uninstall();

		do_action( 'flexacommerce.data_erased', get_current_blog_id() );
	}
}
