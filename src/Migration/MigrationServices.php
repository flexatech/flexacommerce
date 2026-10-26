<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

use Flexa\Commerce\Catalog\CatalogServices;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Migration\Canonical\Validator;
use Flexa\Commerce\Migration\Sources\FxcCatalogReader;
use Flexa\Commerce\Migration\Sources\FxcEnumerationRepository;
use Flexa\Commerce\Migration\Sources\FxcOrderReader;
use Flexa\Commerce\Migration\Sources\FxcShippingReader;
use Flexa\Commerce\Migration\Sources\FxcSource;
use Flexa\Commerce\Migration\Sources\FxcStoreReader;
use Flexa\Commerce\Migration\Sources\SourceAdapter;
use Flexa\Commerce\Migration\Sources\WooCatalogReader;
use Flexa\Commerce\Migration\Sources\WooEnumerationRepository;
use Flexa\Commerce\Migration\Sources\WooOrderReader;
use Flexa\Commerce\Migration\Sources\WooShippingReader;
use Flexa\Commerce\Migration\Sources\WooSource;
use Flexa\Commerce\Migration\Sources\WooStoreReader;
use Flexa\Commerce\Migration\Targets\FxcCatalogWriter;
use Flexa\Commerce\Migration\Targets\FxcOrderWriter;
use Flexa\Commerce\Migration\Targets\FxcShippingWriter;
use Flexa\Commerce\Migration\Targets\FxcStoreWriter;
use Flexa\Commerce\Migration\Targets\FxcTarget;
use Flexa\Commerce\Migration\Targets\TargetAdapter;
use Flexa\Commerce\Migration\Targets\WooCatalogWriter;
use Flexa\Commerce\Migration\Targets\WooOrderWriter;
use Flexa\Commerce\Migration\Targets\WooShippingRepository;
use Flexa\Commerce\Migration\Targets\WooShippingWriter;
use Flexa\Commerce\Migration\Targets\WooStoreWriter;
use Flexa\Commerce\Migration\Targets\WooTarget;
use Flexa\Commerce\Migration\Transport\FileExport;
use Flexa\Commerce\Migration\Transport\FileIndex;
use Flexa\Commerce\Migration\Transport\FileSource;
use Flexa\Commerce\Shipping\ShippingServices;
use Flexa\Commerce\Support\Services;
use Flexa\Commerce\Tax\TaxServices;

defined( 'ABSPATH' ) || exit;

/** Migration part of the composition root, built only by admin, REST and WP-CLI requests that touch a job. */
final class MigrationServices {
	private ?JobRepository $jobs          = null;
	private ?IdMapRepository $ids         = null;
	private ?ImportRepository $imports    = null;
	private ?Runner $runner               = null;
	private ?MigrationService $service    = null;
	private ?WooSource $woo_source        = null;
	private ?FxcTarget $fxc_target        = null;
	private ?FxcSource $fxc_source        = null;
	private ?WooTarget $woo_target        = null;
	private ?BackgroundDriver $background = null;
	private ?Cutover $cutover             = null;

	public function __construct(
		private readonly Services $services,
		private readonly CatalogServices $catalog,
		private readonly TaxServices $tax,
		private readonly ShippingServices $shipping,
	) {}

	public function jobs(): JobRepository {
		return $this->jobs ??= new JobRepository( $this->services->wpdb() );
	}

	public function ids(): IdMapRepository {
		return $this->ids ??= new IdMapRepository( $this->services->wpdb() );
	}

	public function imports(): ImportRepository {
		return $this->imports ??= new ImportRepository( $this->services->wpdb() );
	}

	public function service(): MigrationService {
		return $this->service ??= new MigrationService(
			$this->jobs(),
			$this->ids(),
			$this->runner(),
			$this->services->clock(),
			fn ( Job $job ): array => $this->adapters( $job ),
		);
	}

	public function background(): BackgroundDriver {
		return $this->background ??= new BackgroundDriver( $this->service() );
	}

	public function cutover(): Cutover {
		return $this->cutover ??= new Cutover( $this->jobs(), $this->ids(), $this->services->clock() );
	}

	public function runner(): Runner {
		return $this->runner ??= new Runner(
			$this->jobs(),
			$this->ids(),
			new Validator(),
			$this->services->clock(),
			fn ( Job $job ): array => $this->adapters( $job ),
			fn ( Job $job ): array => $this->verifier( $job )->verify( $job, $this->adapters( $job )[0] ),
		);
	}

	public function verifier( Job $job ): Verifier {
		return new Verifier(
			$this->ids()->for_system( $job->id_system() ),
			$this->services->clock(),
			$job->direction === Direction::FXC_TO_WC
				? static fn ( int $order_id ): ?array => WooOrderWriter::summary( $order_id )
				: fn ( int $order_id ): ?array => $this->imports()->order_summary( $order_id ),
		);
	}

	/** @return array{0:SourceAdapter,1:TargetAdapter} */
	public function adapters( Job $job ): array {
		if ( $job->is_file() ) {
			$source = new FileSource( FileIndex::open( (string) $job->option( 'file' ) ) );
			if ( $job->direction === Direction::WC_TO_FXC ) {
				return [ $source, $this->fxc_target() ];
			}
			if ( ! WooSource::available() ) {
				throw new MigrationError( 'woocommerce_inactive', 'WooCommerce is not active.' );
			}
			return [ $source, $this->woo_target() ];
		}
		if ( ! WooSource::available() ) {
			throw new MigrationError( 'woocommerce_inactive', 'WooCommerce is not active.' );
		}
		if ( $job->direction === Direction::FXC_TO_WC ) {
			return [ $this->fxc_source(), $this->woo_target() ];
		}
		return [ $this->woo_source(), $this->fxc_target() ];
	}

	/**
	 * Writes this site's store as a JSONL file for another site (doc 05 §4.6).
	 *
	 * @param array<string,mixed>              $options stages and the order date range
	 * @param (callable(string,int):void)|null $progress
	 * @return array{counts:array<string,int>,rejected:list<array{stage:string,id:string,message:string}>,users:int,media:int}
	 */
	public function export( string $system, array $options, string $path, ?callable $progress = null ): array {
		if ( $system === 'wc' && ! WooSource::available() ) {
			throw new MigrationError( 'woocommerce_inactive', 'WooCommerce is not active.' );
		}
		$source = match ( $system ) {
			'wc'    => $this->woo_source(),
			'fxc'   => $this->fxc_source(),
			default => throw new MigrationError( 'invalid_direction', sprintf( 'Unknown store "%s"; use wc or fxc.', $system ) ),
		};
		unset( $options['file'], $options['id_system'] );
		$job = FileExport::job( FileExport::direction( $system ), $options );
		return ( new FileExport( $source ) )->export( $job, $path, $progress );
	}

	public function woo_source(): WooSource {
		if ( $this->woo_source === null ) {
			$enumeration      = new WooEnumerationRepository( $this->services->wpdb() );
			$this->woo_source = new WooSource( $enumeration, new WooStoreReader( $enumeration ), new WooCatalogReader(), new WooOrderReader(), new WooShippingReader() );
		}
		return $this->woo_source;
	}

	public function fxc_source(): FxcSource {
		if ( $this->fxc_source === null ) {
			$enumeration      = new FxcEnumerationRepository( $this->services->wpdb() );
			$this->fxc_source = new FxcSource(
				$enumeration,
				new FxcStoreReader( $this->tax->repository(), new CustomerProfile() ),
				new FxcCatalogReader( $this->catalog->attributes(), $this->catalog->product_service() ),
				new FxcOrderReader( $enumeration ),
				new FxcShippingReader( $this->shipping->service() ),
			);
		}
		return $this->fxc_source;
	}

	public function woo_target(): WooTarget {
		return $this->woo_target ??= new WooTarget( new WooStoreWriter(), new WooCatalogWriter(), new WooOrderWriter(), new WooShippingWriter( new WooShippingRepository( $this->services->wpdb() ) ) );
	}

	public function fxc_target(): FxcTarget {
		return $this->fxc_target ??= new FxcTarget(
			$this->services->transaction(),
			new FxcStoreWriter( $this->tax->repository(), new CustomerProfile() ),
			new FxcCatalogWriter( $this->catalog->attributes(), $this->catalog->product_service(), $this->catalog->variants() ),
			new FxcOrderWriter( $this->imports(), $this->catalog->product_service(), $this->services->sequence(), $this->services->clock() ),
			$this->imports(),
			new FxcShippingWriter( $this->shipping->service() ),
		);
	}
}
