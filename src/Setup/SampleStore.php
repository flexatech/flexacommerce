<?php

declare(strict_types=1);

namespace Flexa\Commerce\Setup;

use Flexa\Commerce\Catalog\AttributeRepository;
use Flexa\Commerce\Catalog\Csv\CsvColumns;
use Flexa\Commerce\Catalog\Csv\CsvValues;
use Flexa\Commerce\Catalog\Csv\ImportContext;
use Flexa\Commerce\Catalog\Csv\ProductCsvImporter;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\ProductInput;
use Flexa\Commerce\Catalog\ProductService;
use Flexa\Commerce\Contracts\CommerceException;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponEditor;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Reviews\Reviews;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sample products to try the shop with, as WooCommerce ships them: the
 * file sample-data/sample_products.csv goes through the product CSV
 * importer, so it also imports by hand on the import screen. The
 * installer then adds what a CSV file cannot hold: pictures drawn here
 * (no image comes from another site), colour swatches, a gift card, a
 * subscription plan, files to download, reviews and three coupons.
 * Everything it creates is listed in a non-autoloaded option, and remove()
 * deletes exactly that list.
 */
final class SampleStore {
	public const OPTION = 'flexacommerce_sample';

	/** Uploads subfolder for the files the sample album and single sell. */
	private const FOLDER = 'flexacommerce-sample';

	private const SIZE = 800;

	/** Background colour of each product's picture; the colour variations get their own. */
	private const PICTURES = [
		'sample-vneck-tee'       => '#3b6ea5',
		'sample-hoodie'          => '#3b6ea5',
		'sample-hoodie-zipper'   => '#4a5568',
		'sample-hoodie-pocket'   => '#6b4f3a',
		'sample-tshirt'          => '#2f855a',
		'sample-long-sleeve-tee' => '#975a16',
		'sample-polo'            => '#2c5282',
		'sample-beanie'          => '#9b2c2c',
		'sample-cap'             => '#2d3748',
		'sample-belt'            => '#5f370e',
		'sample-sunglasses'      => '#1a202c',
		'sample-poster'          => '#285e61',
		'sample-vase'            => '#b7791f',
		'sample-mug'             => '#702459',
		'sample-album'           => '#44337a',
		'sample-single'          => '#553c9a',
		'sample-logo-collection' => '#2a4365',
		'sample-winter-set'      => '#234e52',
		'sample-pennant'         => '#c05621',
		'sample-coffee-club'     => '#5c3d2e',
		'sample-gift-card'       => '#b83280',
		'sample-hidden-sticker'  => '#718096',
	];

	private const COLORS = [
		'Blue'  => '#3b6ea5',
		'Green' => '#2f855a',
		'Red'   => '#c53030',
	];

	/** Reviews as [author, rating, text]. */
	private const REVIEWS = [
		'sample-vneck-tee' => [
			[ 'Ana', 5, 'Soft fabric and the colour stayed the same after a few washes.' ],
			[ 'Minh', 4, 'Fits well. I would like a longer cut, but it is a good tee.' ],
		],
		'sample-hoodie'    => [
			[ 'Sam', 5, 'Warm and the hood is lined. Worth the price.' ],
			[ 'Lea', 3, 'Nice hoodie, though the sleeves run a little long.' ],
		],
		'sample-beanie'    => [
			[ 'Minh', 5, 'Warm without being itchy.' ],
		],
		'sample-album'     => [
			[ 'Ana', 4, 'The download link came by email straight after I paid.' ],
		],
	];

	public function __construct(
		private readonly ProductCsvImporter $importer,
		private readonly ProductService $products,
		private readonly AttributeRepository $attributes,
		private readonly CouponRepository $coupons,
		private readonly CouponEditor $editor,
	) {}

	public static function make(): self {
		$plugin  = Plugin::instance();
		$coupons = $plugin->coupons();
		return new self(
			$plugin->catalog()->csv_importer(),
			$plugin->catalog()->product_service(),
			$plugin->catalog()->attributes(),
			$coupons->repository(),
			$coupons->editor(),
		);
	}

	/** The file WooCommerce's sample_products.csv corresponds to. */
	public static function file(): string {
		return dirname( __DIR__, 2 ) . '/sample-data/sample_products.csv';
	}

	/** @return array{installed:bool,products:int,coupons:int} */
	public function status(): array {
		$list = self::list();
		return [
			'installed' => $list !== null,
			'products'  => count( $list['products'] ?? [] ),
			'coupons'   => count( $list['coupons'] ?? [] ),
		];
	}

	/**
	 * Products whose SKU the shop already has are left alone and not
	 * counted, so a file imported by hand earlier is not touched.
	 *
	 * @return array{products:int,skipped:int,images:int,reviews:int,coupons:int,errors:list<string>}
	 * @throws \RuntimeException when the sample store is already in or the file cannot be read
	 */
	public function install(): array {
		if ( self::list() !== null ) {
			throw new \RuntimeException( __( 'The sample products are already in the shop. Remove them first to add them again.', 'flexacommerce' ) );
		}
		$taxonomies = [ PostTypes::CATEGORY, PostTypes::TAG, PostTypes::ATTRIBUTE ];
		$before     = self::term_ids( $taxonomies );

		[ $headers, $rows, $skus ] = self::read( self::file() );
		$mapping                   = array_map( static fn ( string $header ): string => CsvColumns::match( $header ) ?? '', $headers );
		$results                   = $this->importer->import( $headers, $mapping, $rows, new ImportContext(), false, false );

		$list    = [
			'products'    => [],
			'terms'       => [],
			'attachments' => [],
			'coupons'     => [],
		];
		$created = [];
		$counts  = [
			'products' => 0,
			'skipped'  => 0,
			'images'   => 0,
			'reviews'  => 0,
			'coupons'  => 0,
			'errors'   => [],
		];
		foreach ( $results as $result ) {
			$sku = $skus[ $result['line'] ] ?? null;
			if ( $result['action'] === 'error' ) {
				$counts['errors'][] = trim( (string) $result['sku'] . ': ' . $result['message'] );
			}
			if ( $sku === null ) {
				continue;
			}
			if ( $result['action'] === 'create' && $result['id'] !== null ) {
				$created[ $sku ]    = (int) $result['id'];
				$list['products'][] = (int) $result['id'];
				++$counts['products'];
			} elseif ( $result['action'] === 'skip' ) {
				++$counts['skipped'];
			}
		}
		$list['terms'] = array_values( array_diff( self::term_ids( $taxonomies ), $before ) );
		// Saved now, so a failure below still leaves a list remove() can clean up.
		update_option( self::OPTION, $list, false );

		$this->swatches( $list['terms'] );
		foreach ( $created as $sku => $id ) {
			try {
				$counts['images'] += $this->picture( $sku, $id, $list );
				$this->extras( $sku, $id );
			} catch ( CommerceException $e ) {
				$counts['errors'][] = $sku . ': ' . $e->getMessage();
			}
			$counts['reviews'] += $this->reviews( $sku, $id );
		}
		$counts['images'] += $this->category_pictures( $list );
		foreach ( $this->add_coupons() as $coupon_id ) {
			$list['coupons'][] = $coupon_id;
			++$counts['coupons'];
		}
		update_option( self::OPTION, $list, false );
		return $counts;
	}

	/** @return array{products:int,images:int,coupons:int,terms:int} */
	public function remove(): array {
		$list   = self::list() ?? [];
		$counts = [
			'products' => 0,
			'images'   => 0,
			'coupons'  => 0,
			'terms'    => 0,
		];
		// Bundles and grouped products come after their parts in the file: last in, first out.
		foreach ( array_reverse( $list['products'] ?? [] ) as $id ) {
			if ( get_post_type( $id ) === PostTypes::PRODUCT && wp_delete_post( $id, true ) ) {
				++$counts['products'];
			}
		}
		foreach ( $list['attachments'] ?? [] as $id ) {
			if ( get_post_type( $id ) === 'attachment' && wp_delete_attachment( $id, true ) ) {
				++$counts['images'];
			}
		}
		foreach ( $list['coupons'] ?? [] as $id ) {
			try {
				$this->editor->delete( $id );
				++$counts['coupons'];
			} catch ( CommerceException ) {
				continue; // Already deleted by hand.
			}
		}
		// Children first; a term the shop's own products use stays.
		foreach ( array_reverse( $list['terms'] ?? [] ) as $id ) {
			$term = get_term( $id );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$used = $term->taxonomy === PostTypes::ATTRIBUTE && $term->parent === 0
				? $this->attributes->usage( $id ) > 0
				: self::in_use( $term );
			if ( $used ) {
				continue;
			}
			if ( wp_delete_term( $id, $term->taxonomy ) === true ) {
				++$counts['terms'];
			}
		}
		self::delete_folder();
		delete_option( self::OPTION );
		return $counts;
	}

	/**
	 * The header and the rows as the importer takes them, with the SKU of
	 * each product row (variations are left out) by line.
	 *
	 * @return array{0:list<string>,1:list<array{line:int,cells:list<string>}>,2:array<int,string>}
	 */
	private static function read( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a file shipped with the plugin.
		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( $handle === false ) {
			throw new \RuntimeException( __( 'The sample products file is missing.', 'flexacommerce' ) );
		}
		$headers = array_map( [ CsvValues::class, 'strip_bom' ], array_map( 'strval', (array) fgetcsv( $handle, null, ',', '"', '' ) ) );
		$sku     = array_search( 'SKU', $headers, true );
		$parent  = array_search( 'Parent', $headers, true );
		$rows    = [];
		$skus    = [];
		$line    = 1;
		for ( $cells = fgetcsv( $handle, null, ',', '"', '' ); $cells !== false; $cells = fgetcsv( $handle, null, ',', '"', '' ) ) {
			++$line;
			$cells  = array_map( 'strval', $cells );
			$rows[] = [
				'line'  => $line,
				'cells' => $cells,
			];
			if ( $sku !== false && ( $parent === false || ( $cells[ $parent ] ?? '' ) === '' ) ) {
				$skus[ $line ] = $cells[ $sku ] ?? '';
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		return [ $headers, $rows, $skus ];
	}

	/**
	 * @param list<string> $taxonomies
	 * @return list<int>
	 */
	private static function term_ids( array $taxonomies ): array {
		$ids = get_terms(
			[
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	private static function in_use( \WP_Term $term ): bool {
		$ids = get_objects_in_term( $term->term_id, $term->taxonomy );
		return is_array( $ids ) && $ids !== [];
	}

	/**
	 * The Color attribute shows swatches when the sample file created it.
	 *
	 * @param list<int> $terms
	 */
	private function swatches( array $terms ): void {
		foreach ( $terms as $id ) {
			$term = get_term( $id );
			if ( ! $term instanceof \WP_Term || $term->taxonomy !== PostTypes::ATTRIBUTE || $term->parent !== 0 || $term->name !== 'Color' ) {
				continue;
			}
			$this->attributes->set_swatch( $id, AttributeRepository::COLOR );
			foreach ( $this->attributes->values( $id ) as $value ) {
				if ( isset( self::COLORS[ $value->name ] ) ) {
					$this->attributes->set_look( $value->term_id, self::COLORS[ $value->name ], null );
				}
			}
		}
	}

	/**
	 * The product's picture, and one per colour for its variations.
	 *
	 * @param array{products:list<int>,terms:list<int>,attachments:list<int>,coupons:list<int>} $list
	 */
	private function picture( string $sku, int $id, array &$list ): int {
		$color = self::PICTURES[ $sku ] ?? null;
		if ( $color === null ) {
			return 0;
		}
		$product = $this->products->get( $id );
		$made    = 0;
		$rows    = [];
		$gallery = [];
		foreach ( $product->variations as $variation ) {
			$name = '';
			foreach ( array_keys( self::COLORS ) as $candidate ) {
				if ( str_contains( (string) $variation->sku, '-' . strtolower( $candidate ) ) ) {
					$name = $candidate;
				}
			}
			if ( $name === '' ) {
				continue;
			}
			$image = $gallery[ $name ] ??= $this->image( $product->title, $name, self::COLORS[ $name ], $id, $list );
			if ( $image > 0 ) {
				$rows[] = [
					'id'       => $variation->id,
					'image_id' => $image,
				];
			}
		}
		$made     += count( array_filter( $gallery ) );
		$thumbnail = $gallery !== [] ? (int) reset( $gallery ) : $this->image( $product->title, '', $color, $id, $list );
		if ( $gallery === [] && $thumbnail > 0 ) {
			++$made;
		}
		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $id, $thumbnail );
		}
		$input = [];
		if ( $rows !== [] ) {
			$input['variations'] = $rows;
		}
		if ( $sku === 'sample-sunglasses' ) {
			$case = $this->image( $product->title, __( 'Hard case', 'flexacommerce' ), '#4a5568', $id, $list );
			if ( $case > 0 ) {
				$input['gallery_ids'] = [ $case ];
				++$made;
			}
		}
		if ( $input !== [] ) {
			$this->products->save( $id, ProductInput::from_array( $input ) );
		}
		return $made;
	}

	/** What the CSV file cannot say: a gift card, a subscription plan, files to download. */
	private function extras( string $sku, int $id ): void {
		$base = match ( $sku ) {
			'sample-gift-card'   => [ 'gift_card' => true ],
			'sample-coffee-club' => [
				'subscription' => [
					'period'     => 'month',
					'interval'   => 1,
					'signup_fee' => self::minor( '5' ),
				],
			],
			'sample-album'       => [
				'downloadable' => true,
				'downloads'    => [
					'files' => array_filter(
						[
							self::download( 'album-track-list.txt', "1. Morning\n2. Harbour\n3. Lights\n4. Long road\n5. Rain\n6. Window\n7. Night bus\n8. Hills\n9. Letters\n10. Home\n" ),
							self::download( 'album-liner-notes.txt', "Sample liner notes.\nThis file stands in for the music a real store would sell.\n" ),
						]
					),
				],
			],
			'sample-single'      => [
				'downloadable' => true,
				'downloads'    => [
					'files' => array_filter( [ self::download( 'single.txt', "Sample single.\nThis file stands in for the track a real store would sell.\n" ) ] ),
				],
			],
			default              => null,
		};
		if ( $base !== null ) {
			$this->products->save( $id, ProductInput::from_array( [ 'base' => $base ] ) );
		}
	}

	private function reviews( string $sku, int $id ): int {
		$added = 0;
		foreach ( self::REVIEWS[ $sku ] ?? [] as $i => [ $author, $rating, $text ] ) {
			$comment = wp_insert_comment(
				[
					'comment_post_ID'      => $id,
					'comment_author'       => $author,
					'comment_author_email' => strtolower( $author ) . '@example.com',
					'comment_content'      => $text,
					'comment_type'         => Reviews::TYPE,
					'comment_approved'     => 1,
					'comment_date'         => gmdate( 'Y-m-d H:i:s', time() - ( $i + 1 ) * DAY_IN_SECONDS ),
					'comment_meta'         => [ Reviews::META_RATING => $rating ],
				]
			);
			if ( $comment !== false ) {
				++$added;
			}
		}
		if ( $added > 0 ) {
			Reviews::instance()->refresh( $id );
		}
		return $added;
	}

	/** @param array{products:list<int>,terms:list<int>,attachments:list<int>,coupons:list<int>} $list */
	private function category_pictures( array &$list ): int {
		$made = 0;
		foreach ( $list['terms'] as $id ) {
			$term = get_term( $id );
			if ( ! $term instanceof \WP_Term || $term->taxonomy !== PostTypes::CATEGORY ) {
				continue;
			}
			$image = $this->image( $term->name, '', '#2d3748', 0, $list );
			if ( $image > 0 ) {
				update_term_meta( $id, PostTypes::CATEGORY_THUMBNAIL, $image );
				++$made;
			}
		}
		return $made;
	}

	/** @return list<int> the coupons added; a code the shop already uses is skipped */
	private function add_coupons(): array {
		$coupons = [
			[
				'code'        => 'SAMPLE10',
				'type'        => Coupon::PERCENT,
				'amount'      => 1000,
				'description' => __( 'Sample coupon: 10% off the cart.', 'flexacommerce' ),
			],
			[
				'code'         => 'SAMPLE5',
				'type'         => Coupon::FIXED_CART,
				'amount'       => self::minor( '5' ),
				'min_subtotal' => self::minor( '30' ),
				'description'  => __( 'Sample coupon: 5.00 off a cart of 30.00 or more.', 'flexacommerce' ),
			],
			[
				'code'          => 'FREESHIP',
				'type'          => Coupon::FIXED_CART,
				'amount'        => 0,
				'free_shipping' => true,
				'description'   => __( 'Sample coupon: free shipping, for methods that accept a coupon.', 'flexacommerce' ),
			],
		];
		$ids     = [];
		foreach ( $coupons as $input ) {
			if ( $this->coupons->code_taken( $input['code'] ) ) {
				continue;
			}
			try {
				$ids[] = $this->editor->save( $input )->id;
			} catch ( CommerceException ) {
				continue;
			}
		}
		return $ids;
	}

	private static function minor( string $amount ): int {
		$currency = Settings::string( 'currency' );
		return Money::from_decimal_string( $amount, $currency, Currency::exponent( $currency ) )->amount;
	}

	/**
	 * A file in the sample uploads folder, as a download entry.
	 *
	 * @return array{name:string,file:string}|null
	 */
	private static function download( string $name, string $contents ): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small text file in our own uploads folder.
		if ( file_put_contents( $dir . '/' . $name, $contents ) === false ) {
			return null;
		}
		return [
			'name' => $name,
			'file' => trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/' . $name,
		];
	}

	/**
	 * Uninstall (Maintenance\Eraser): the products and terms go with the rest
	 * of the catalog, so only the pictures and the download folder are left.
	 */
	public static function erase(): void {
		foreach ( self::list()['attachments'] ?? [] as $id ) {
			if ( get_post_type( $id ) === 'attachment' ) {
				wp_delete_attachment( $id, true );
			}
		}
		self::delete_folder();
	}

	private static function delete_folder(): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort, the folder may hold files from elsewhere.
	}

	/**
	 * A square PNG with the title on a plain colour, saved to the media
	 * library. 0 when the GD extension is missing.
	 *
	 * @param array{products:list<int>,terms:list<int>,attachments:list<int>,coupons:list<int>} $list
	 */
	private function image( string $title, string $caption, string $color, int $parent, array &$list ): int {
		$png = self::draw( $title, $caption, $color );
		if ( $png === null ) {
			return 0;
		}
		$name   = sanitize_file_name( 'sample-' . sanitize_title( $title . ( $caption !== '' ? '-' . $caption : '' ) ) . '.png' );
		$upload = wp_upload_bits( $name, null, $png );
		if ( $upload['error'] !== false ) {
			return 0;
		}
		$id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title'     => trim( $title . ' ' . $caption ),
				'post_status'    => 'inherit',
			],
			$upload['file'],
			$parent
		);
		if ( $id === 0 ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', trim( $title . ' ' . $caption ) );
		$list['attachments'][] = $id;
		return $id;
	}

	private static function draw( string $title, string $caption, string $color ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			return null;
		}
		$font  = dirname( __DIR__, 2 ) . '/assets/fonts/NotoSans-Bold.ttf';
		$image = imagecreatetruecolor( self::SIZE, self::SIZE );
		if ( $image === false ) {
			return null;
		}
		[ $r, $g, $b ] = sscanf( $color, '#%02x%02x%02x' ) ?? [ 45, 55, 72 ];
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, (int) $r, (int) $g, (int) $b ) );
		$panel = (int) imagecolorallocatealpha( $image, 255, 255, 255, 100 );
		imagefilledrectangle( $image, 80, 80, self::SIZE - 80, self::SIZE - 80, $panel );
		$white = (int) imagecolorallocate( $image, 255, 255, 255 );
		self::centered( $image, $title, $font, 52, $caption !== '' ? 390 : 425, $white );
		if ( $caption !== '' ) {
			self::centered( $image, $caption, $font, 34, 470, $white );
		}
		ob_start();
		imagepng( $image, null, 9 );
		$png = (string) ob_get_clean();
		return $png !== '' ? $png : null;
	}

	private static function centered( \GdImage $image, string $text, string $font, int $size, int $baseline, int $color ): void {
		do {
			$box   = imagettfbbox( $size, 0, $font, $text );
			$width = $box === false ? 0 : $box[2] - $box[0];
			$size -= 2;
		} while ( $width > self::SIZE - 200 && $size > 12 );
		imagettftext( $image, $size + 2, 0, (int) ( ( self::SIZE - $width ) / 2 ), $baseline, $color, $font, $text );
	}

	/** @return array{products:list<int>,terms:list<int>,attachments:list<int>,coupons:list<int>}|null */
	private static function list(): ?array {
		$list = get_option( self::OPTION, null );
		if ( ! is_array( $list ) ) {
			return null;
		}
		$ids = static fn ( mixed $value ): array => is_array( $value ) ? array_values( array_map( 'intval', $value ) ) : [];
		return [
			'products'    => $ids( $list['products'] ?? null ),
			'terms'       => $ids( $list['terms'] ?? null ),
			'attachments' => $ids( $list['attachments'] ?? null ),
			'coupons'     => $ids( $list['coupons'] ?? null ),
		];
	}
}
