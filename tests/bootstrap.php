<?php
/**
 * PHPUnit bootstrap: WordPress, a real REST server and a real database.
 *
 * Everything this library does is about what WordPress's REST server
 * actually does -- how a route is matched, what it refuses before any of our
 * code runs, the order filters run in, what a header ends up as -- and, for
 * /changes and the idempotency store, what MySQL does. So the suite runs
 * against both rather than stubs of them.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$rest_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! is_readable( $rest_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$rest_tests_dir}. Run 'composer install', or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $rest_tests_dir . '/includes/functions.php';
require_once $rest_tests_dir . '/includes/bootstrap.php';
