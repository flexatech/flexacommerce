<?php
get_header();
while ( have_posts() ) {
	the_post();
	the_title( '<h2>', '</h2>' );
	the_content();
}
get_footer();
