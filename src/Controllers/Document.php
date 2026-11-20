<?php
/**
 * The API, described.
 *
 * @package     SugarCommerce\Rest\Controllers
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Controllers;

use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Controller;
use SugarCommerce\Rest\Schema;
use WP_REST_Response;

/**
 * `GET /openapi.json`: every route, as an OpenAPI 3.1 document.
 *
 * For generating a client. It is built from the routes as they are
 * registered on this site at the moment it is asked for, so it describes
 * this site's API -- an add-on's resources and fields included -- and
 * cannot fall behind the code.
 *
 * @since 1.0.0
 */
final class Document extends Controller {

	/**
	 * The route, for an API.
	 *
	 * @param Api $api The API.
	 */
	public function __construct( Api $api ) {
		parent::__construct( $api );

		$this->area = $api->area();
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function register_routes(): void {
		$type       = $this->kit()->object_type( 'openapi' );
		$properties = [ 'openapi' => Schema::string( __( 'The OpenAPI version: the rest of the document is as that specification lays out.', 'sugarcommerce' ) ) ];

		$this->route(
			'/openapi.json',
			[ $this->handle( 'GET', 'get_document', 'read' ) ],
			static fn(): array => [
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => $type,
				'type'       => 'object',
				'properties' => $properties,
			]
		);
	}

	/**
	 * The document.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function get_document(): WP_REST_Response {
		return self::done( $this->kit()->openapi()->document() );
	}
}
