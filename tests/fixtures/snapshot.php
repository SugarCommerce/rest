<?php
/**
 * Snapshot a site's API: its OpenAPI document and the route table it was written from.
 *
 * How tests/fixtures/sugarcart-openapi.json and sugarcart-routes.json were
 * made, and how a plugin switching onto the kit proves nothing moved: snapshot
 * before, snapshot after, and diff.
 *
 * Read-only. No request is dispatched -- so no rate-limit counter is written
 * and no log line -- and WordPress's cron is off for the load. Run it from
 * anywhere but the site:
 *
 *     php -d mysqli.default_socket=<socket> snapshot.php \
 *         --wp=/path/to/wordpress --namespace=sugarcart/v1 --prefix=sugarcart \
 *         --document='SugarCart\Rest\V1\OpenApi::document' --out=/tmp/before
 *
 * After the switch, the document comes from the kit (Strauss-prefixed in the
 * plugin's vendor folder):
 *
 *         --api-class='SugarCart\Vendor\SugarCommerce\Rest\Api' --api-id=sugarcart
 *
 * @package SugarCommerce\Rest
 */

// phpcs:ignoreFile -- A command-line tool, not shipped.

$rk_args = getopt( '', [ 'wp:', 'namespace:', 'prefix:', 'document::', 'api-class::', 'api-id::', 'out:', 'host::' ] );

foreach ( [ 'wp', 'namespace', 'prefix', 'out' ] as $rk_required ) {
	if ( empty( $rk_args[ $rk_required ] ) ) {
		fwrite( STDERR, "Missing --{$rk_required}.\n" );
		exit( 1 );
	}
}

define( 'DISABLE_WP_CRON', true );
define( 'WP_USE_THEMES', false );

$_SERVER['HTTP_HOST']   = $rk_args['host'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS']       = 'on';

require rtrim( $rk_args['wp'], '/' ) . '/wp-load.php';

// Whoever can read everything: some schemas ask who is looking.
$rk_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $rk_admins[0] ?? 0 ) );

$rk_server    = rest_get_server(); // Fires rest_api_init: the routes are registered.
$rk_namespace = trim( $rk_args['namespace'], '/' );
$rk_prefix    = $rk_args['prefix'];

if ( ! empty( $rk_args['api-class'] ) ) {
	$rk_document = $rk_args['api-class']::get( $rk_args['api-id'] ?? $rk_prefix )->openapi()->document();
} elseif ( ! empty( $rk_args['document'] ) ) {
	$rk_document = call_user_func( $rk_args['document'] );
} else {
	fwrite( STDERR, "Say where the document comes from: --document or --api-class.\n" );
	exit( 1 );
}

// What json_encode() cannot carry -- callbacks -- is left out: the document
// never says them either.
$rk_clean = static function ( $value ) use ( &$rk_clean ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$out = [];

	foreach ( $value as $key => $inner ) {
		if ( ! is_object( $inner ) ) {
			$out[ $key ] = $rk_clean( $inner );
		}
	}

	return $out;
};

$rk_routes = [];

foreach ( $rk_server->get_routes( $rk_namespace ) as $rk_route => $rk_handlers ) {
	$rk_options = $rk_server->get_route_options( $rk_route );
	$rk_kept    = [];

	foreach ( $rk_handlers as $rk_handler ) {
		$rk_one = [
			'methods' => $rk_handler['methods'],
			'args'    => $rk_clean( (array) ( $rk_handler['args'] ?? [] ) ),
		];

		foreach ( [ 'api', 'kind', 'once' ] as $rk_mark ) {
			if ( array_key_exists( $rk_prefix . '_' . $rk_mark, $rk_handler ) ) {
				$rk_one[ $rk_prefix . '_' . $rk_mark ] = $rk_handler[ $rk_prefix . '_' . $rk_mark ];
			}
		}

		$rk_kept[] = $rk_one;
	}

	$rk_routes[ $rk_route ] = [
		'schema'   => is_callable( $rk_options['schema'] ?? null ) ? (array) call_user_func( $rk_options['schema'] ) : null,
		'handlers' => $rk_kept,
	];
}

$rk_out = rtrim( $rk_args['out'], '/' );

if ( ! is_dir( $rk_out ) ) {
	mkdir( $rk_out, 0755, true );
}

file_put_contents( $rk_out . '/' . $rk_prefix . '-openapi.json', wp_json_encode( $rk_document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
file_put_contents(
	$rk_out . '/' . $rk_prefix . '-routes.json',
	wp_json_encode(
		[
			'blogname' => get_bloginfo( 'name' ),
			'rest_url' => rest_url(),
			'routes'   => $rk_routes,
		],
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n"
);

fwrite( STDOUT, count( $rk_document['paths'] ) . ' paths, ' . count( $rk_routes ) . " routes\n" );
