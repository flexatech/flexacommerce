<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Unit\Storefront;

use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Tests\Unit\TestCase;

/** Core reads blocks/blocks-manifest.php instead of each block.json, so they must agree. */
final class BlocksManifestTest extends TestCase {
	public function test_the_manifest_mirrors_every_block_json(): void {
		$dir      = dirname( __DIR__, 3 ) . '/blocks';
		$manifest = require $dir . '/blocks-manifest.php';
		$folders  = array_map( 'basename', glob( $dir . '/*', GLOB_ONLYDIR ) ?: [] );
		sort( $folders );
		$keys = array_keys( $manifest );
		sort( $keys );
		self::assertSame( $folders, $keys );

		foreach ( $folders as $name ) {
			$json = json_decode( (string) file_get_contents( "{$dir}/{$name}/block.json" ), true );
			unset( $json['$schema'] );
			self::assertSame( $json, $manifest[ $name ], "blocks-manifest.php differs from {$name}/block.json." );
		}
	}

	public function test_every_block_is_known_to_the_storefront_and_the_editor(): void {
		$dir    = dirname( __DIR__, 3 );
		$editor = (string) file_get_contents( $dir . '/assets/frontend/blocks-editor.js' );
		$names  = array_keys( require $dir . '/blocks/blocks-manifest.php' );
		sort( $names );
		$known = Storefront::BLOCKS;
		sort( $known );
		self::assertSame( $names, $known );
		foreach ( $names as $name ) {
			self::assertStringContainsString( "'flexacommerce/{$name}'", $editor );
		}
	}
}
