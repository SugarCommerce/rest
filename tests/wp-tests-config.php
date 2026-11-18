<?php
/**
 * Database and constants for the suite.
 *
 * @package SugarCommerce\Rest
 */

// The library lives at <wordpress>/wp-content/plugins/libraries/sugarcommerce/<library>
// when it is worked on beside a site, so six levels up from here is the
// WordPress root. Anywhere else, say where with WP_CORE_DIR.
$rest_tests_core = getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 6 ) . '/';

define( 'ABSPATH', rtrim( $rest_tests_core, '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'sugarcommerce_rest_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'root' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$rest_tests_host = getenv( 'WP_TESTS_DB_HOST' );

if ( ! $rest_tests_host ) {
	$rest_tests_sockets = glob( getenv( 'HOME' ) . '/Library/Application Support/Local/run/*/mysql/mysqld.sock' ) ?: [];
	$rest_tests_host    = $rest_tests_sockets ? 'localhost:' . $rest_tests_sockets[0] : '127.0.0.1';
}

define( 'DB_HOST', $rest_tests_host );

// The suite drops every table it finds. A production database is not called
// something with "test" in it, so this refuses anything that is not obviously
// disposable.
if ( ! str_contains( DB_NAME, 'test' ) ) {
	fwrite( STDERR, "Refusing to run: DB_NAME '" . DB_NAME . "' does not look like a test database.\n" );
	exit( 1 );
}

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
