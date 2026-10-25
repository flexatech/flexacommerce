<?php
/**
 * Shop, product category and product tag lists for classic themes.
 *
 * Override by copying to yourtheme/flexacommerce/archive-product.php.
 * The main query already holds the shop's sorting, filters and page size
 * (ShopQuery).
 *
 * @version 1.0.0
 */

use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Storefront\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$fxc         = Storefront::instance();
$fxc_wrapper = TemplateLoader::wrapper();

get_header();
?>
<?php echo $fxc_wrapper[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup. ?>
<?php echo $fxc->part( 'breadcrumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
<header class="fxc-shop__header page-header">
	<?php if ( is_post_type_archive() ) : ?>
		<h1 class="page-title"><?php post_type_archive_title(); ?></h1>
	<?php else : ?>
		<?php the_archive_title( '<h1 class="page-title">', '</h1>' ); ?>
		<?php the_archive_description( '<div class="fxc-shop__description archive-description">', '</div>' ); ?>
	<?php endif; ?>
</header>
<?php if ( have_posts() ) : ?>
	<?php echo $fxc->part( 'catalog-sorting' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
	<ul class="fxc-products">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<li <?php post_class( 'fxc-products__item' ); ?>>
				<?php if ( has_post_thumbnail() ) : ?>
					<a class="fxc-products__image" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php the_post_thumbnail( 'medium', [ 'loading' => 'lazy' ] ); ?></a>
				<?php endif; ?>
				<h2 class="fxc-products__name"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<?php echo $fxc->part( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
			</li>
		<?php endwhile; ?>
	</ul>
	<?php the_posts_pagination(); ?>
<?php else : ?>
	<p class="fxc-shop__empty"><?php esc_html_e( 'No products found.', 'flexacommerce' ); ?></p>
<?php endif; ?>
<?php
echo $fxc_wrapper[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
get_sidebar();
get_footer();
