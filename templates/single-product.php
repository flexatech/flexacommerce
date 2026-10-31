<?php
/**
 * Product page for classic themes.
 *
 * Override by copying to yourtheme/flexacommerce/single-product.php.
 * Every piece comes from Storefront::part(), which renders the block of
 * the same name for the current product.
 *
 * @version 1.1.0
 */

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Storefront\Storefront;
use Flexa\Commerce\Storefront\TemplateLoader;

defined( 'ABSPATH' ) || exit;

$fxc         = Storefront::instance();
$fxc_wrapper = TemplateLoader::wrapper();

get_header();
?>
<?php echo $fxc_wrapper[0]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup. ?>
<?php echo $fxc->part( 'breadcrumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
<?php
while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'fxc-product' ); ?>>
		<div class="fxc-product__columns">
			<div class="fxc-product__images">
				<?php echo $fxc->part( 'product-gallery' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
			</div>
			<div class="fxc-product__summary">
				<?php the_title( '<h1 class="fxc-product__title entry-title">', '</h1>' ); ?>
				<?php echo $fxc->part( 'product-rating' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
				<?php echo $fxc->part( 'product-price' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
				<?php if ( has_excerpt() ) : ?>
					<div class="fxc-product__excerpt"><?php the_excerpt(); ?></div>
				<?php endif; ?>
				<?php echo $fxc->part( 'product-add-to-cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
				<?php echo $fxc->part( 'product-sku' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
				<?php the_terms( get_the_ID(), PostTypes::CATEGORY, '<p class="fxc-product__categories">' . esc_html__( 'Categories:', 'flexacommerce' ) . ' ', ', ', '</p>' ); ?>
			</div>
		</div>
		<div class="fxc-product__description entry-content">
			<?php the_content(); ?>
		</div>
		<?php echo $fxc->part( 'product-attributes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
		<?php echo $fxc->part( 'product-reviews' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
		<?php echo $fxc->part( 'related-products' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup. ?>
	</article>
<?php endwhile; ?>
<?php
echo $fxc_wrapper[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
// Without a sidebar.php, get_sidebar() prints core's deprecated theme-compat
// sidebar (Twenty Twenty-One has none). locate_template() would find that one too.
if ( file_exists( get_stylesheet_directory() . '/sidebar.php' ) || file_exists( get_template_directory() . '/sidebar.php' ) ) {
	get_sidebar();
}
get_footer();
