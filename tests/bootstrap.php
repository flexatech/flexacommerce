<?php
/**
 * Picks the unit or integration bootstrap from the --testsuite argument, so
 * one phpunit.xml.dist serves both suites. They cannot share a process: the
 * unit suite stubs WordPress functions that the integration suite loads for real.
 */

declare(strict_types=1);

$argv_string = implode( ' ', $_SERVER['argv'] ?? [] );
if ( str_contains( $argv_string, 'integration' ) ) {
	require __DIR__ . '/bootstrap-integration.php';
} else {
	require __DIR__ . '/bootstrap-unit.php';
}
