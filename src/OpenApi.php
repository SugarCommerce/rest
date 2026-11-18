<?php
/**
 * The registered routes as an OpenAPI document.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

/**
 * OpenAPI 3.1, written from what WordPress was told about each route.
 *
 * Every route here declares its parameters and the schema of its answer,
 * because that is what makes `OPTIONS` truthful. This turns the same
 * declarations into the document a client generator reads, so there is one
 * description of the API and it is the code.
 *
 * OpenAPI 3.1 takes JSON Schema as it is, which is why it is 3.1 and not
 * 3.0: a type of `[ "string", "null" ]` needs no translating. What does
 * need translating is small -- WordPress writes `readonly`, marks a
 * required property on the property, and names path parameters inside a
 * regular expression.
 *
 * What it says about answers: the schema a route declares is its resource,
 * and a list route answers with an array of them. An action that answers
 * with something else declares that instead. Errors are one shape
 * everywhere and are described once.
 *
 * Only this API's routes are described: others that share the namespace
 * -- a webhook, a public endpoint -- are not, because they carry no mark.
 *
 * @since 1.0.0
 */
final class OpenApi {

	/**
	 * The keywords of a WordPress schema that JSON Schema has no word for.
	 *
	 * @since 1.0.0
	 */
	private const WORDPRESS_ONLY = [ '$schema', 'context', 'arg_options' ];

	/**
	 * The document of an API.
	 *
	 * @param Api                  $api    The API.
	 * @param array<string, mixed> $config {
	 *     What the document says about the API as a whole.
	 *
	 *     @type string|callable $title       The title. `%s` is the site's name. `%s API` by default.
	 *     @type string          $description What the API is and how to sign in.
	 *     @type array           $security    The security schemes, by name: each as OpenAPI writes one.
	 *                                        An application password over HTTP Basic by default.
	 * }
	 */
	public function __construct( private readonly Api $api, private readonly array $config = [] ) {}

	/**
	 * The title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function title(): string {
		$title = $this->config['title'] ?? '%s API';
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return is_callable( $title ) ? (string) $title( $site ) : sprintf( (string) $title, $site );
	}

	/**
	 * The security schemes.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	private function security(): array {
		$schemes = (array) ( $this->config['security'] ?? [] );

		return [] !== $schemes ? $schemes : [
			'applicationPassword' => [
				'type'        => 'http',
				'scheme'      => 'basic',
				'description' => 'A WordPress username and an application password.',
			],
		];
	}

	/**
	 * The whole document.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function document(): array {
		$server = rest_get_server();
		$api    = $this->api;
		$paths  = [];
		$shapes = [];

		foreach ( $server->get_routes( $api->namespace() ) as $route => $handlers ) {
			$path    = self::path( substr( $route, strlen( '/' . $api->namespace() ) ) );
			$options = $server->get_route_options( $route );
			$schema  = is_callable( $options['schema'] ?? null ) ? (array) call_user_func( $options['schema'] ) : [];
			$named   = self::path_params( $route );

			foreach ( $handlers as $handler ) {
				// Routes that share the namespace and are not this API.
				if ( empty( $handler[ $api->mark() ] ) ) {
					continue;
				}

				$title = (string) ( $schema['title'] ?? '' );

				if ( '' !== $title && ! isset( $shapes[ $title ] ) ) {
					$shapes[ $title ] = self::schema( $schema );
				}

				foreach ( array_keys( array_filter( (array) $handler['methods'] ) ) as $method ) {
					$paths[ $path ][ strtolower( (string) $method ) ] = $this->operation( $path, (string) $method, $handler, $title, $named );
				}
			}
		}

		ksort( $paths );
		ksort( $shapes );

		$security = $this->security();
		$codes    = array_map( [ $api, 'code' ], Errors::CODES );
		$last     = array_pop( $codes );

		return [
			'openapi'    => '3.1.0',
			'info'       => [
				'title'       => $this->title(),
				'version'     => $api->version(),
				'description' => (string) ( $this->config['description'] ?? 'Sign in with a WordPress application password (HTTP Basic). Dates are ISO 8601 in UTC.' ),
			],
			'servers'    => [ [ 'url' => untrailingslashit( rest_url( $api->namespace() ) ) ] ],
			'security'   => array_map( static fn( string $name ): array => [ $name => [] ], array_keys( $security ) ),
			'paths'      => $paths,
			'components' => [
				'securitySchemes' => $security,
				'schemas'         => $shapes + [
					'error' => [
						'type'       => 'object',
						'properties' => [
							'code'    => [
								'type'        => 'string',
								'description' => implode( ', ', $codes ) . ' or ' . $last . '.',
							],
							'message' => [
								'type'        => 'string',
								'description' => 'What went wrong, for a person.',
							],
							'data'    => [
								'type'       => 'object',
								'properties' => [
									'status' => [ 'type' => 'integer' ],
									'param'  => [ 'type' => 'string' ],
									'reason' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * One method on one path.
	 *
	 * @param string               $path    The path, in OpenAPI's form.
	 * @param string               $method  The HTTP method.
	 * @param array<string, mixed> $handler The handler, as registered.
	 * @param string               $title   The title of the route's schema.
	 * @param string[]             $named   The parameters that are in the path.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function operation( string $path, string $method, array $handler, string $title, array $named ): array {
		$api        = $this->api;
		$in_body    = in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true );
		$parameters = [];
		$body       = [];
		$required   = [];

		foreach ( (array) ( $handler['args'] ?? [] ) as $name => $arg ) {
			$arg  = (array) $arg;
			$must = ! empty( $arg['required'] );
			$is   = self::schema(
				array_diff_key(
					$arg,
					[
						'required'          => 1,
						'sanitize_callback' => 1,
						'validate_callback' => 1,
					]
				)
			);

			if ( in_array( $name, $named, true ) ) {
				$parameters[] = [
					'name'     => $name,
					'in'       => 'path',
					'required' => true,
					'schema'   => array_diff_key( $is, [ 'description' => 1 ] ),
				] + array_intersect_key( $is, [ 'description' => 1 ] );
			} elseif ( $in_body ) {
				$body[ $name ] = $is;

				if ( $must ) {
					$required[] = $name;
				}
			} else {
				$parameters[] = [
					'name'     => $name,
					'in'       => 'query',
					'required' => $must,
					'schema'   => array_diff_key( $is, [ 'description' => 1 ] ),
				] + array_intersect_key( $is, [ 'description' => 1 ] ) + ( 'array' === ( $is['type'] ?? '' ) ? [
					'style'   => 'form',
					'explode' => false,
				] : [] );
			}
		}

		if ( ! empty( $handler[ $api->key( 'once' ) ] ) ) {
			$parameters[] = [
				'name'        => 'Idempotency-Key',
				'in'          => 'header',
				'required'    => true,
				'description' => 'Any value you make up, such as a UUID. Send it again unchanged to retry safely.',
				'schema'      => [
					'type'      => 'string',
					'maxLength' => Idempotency::MAX_LENGTH,
				],
			];
		}

		$ref  = '' !== $title ? [ '$ref' => '#/components/schemas/' . $title ] : [ 'type' => 'object' ];
		$list = 'GET' === $method && isset( $handler['args']['per_page'] );

		$operation = [
			'operationId' => self::operation_id( $method, $path ),
			'tags'        => [ explode( '/', trim( $path, '/' ) )[0] ],
			'x-' . $api->prefix() => [
				'kind'       => (string) ( $handler[ $api->key( 'kind' ) ] ?? 'read' ),
				'idempotent' => ! empty( $handler[ $api->key( 'once' ) ] ),
			],
			'parameters'  => $parameters,
			'responses'   => [
				'200'     => [
					'description' => $list ? 'A page of records. X-WP-Total and X-WP-TotalPages say how many there are.' : 'The answer.',
					'content'     => [
						'application/json' => [
							'schema' => $list ? [
								'type'  => 'array',
								'items' => $ref,
							] : $ref,
						],
					],
				],
				'default' => [
					'description' => 'An error.',
					'content'     => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/error' ] ] ],
				],
			],
		];

		if ( [] !== $body ) {
			$operation['requestBody'] = [
				'required' => [] !== $required,
				'content'  => [
					'application/json' => [
						'schema' => array_filter(
							[
								'type'       => 'object',
								'properties' => $body,
								'required'   => $required,
							]
						),
					],
				],
			];
		}

		return $operation;
	}

	/**
	 * A WordPress route as an OpenAPI path: `/orders/(?P<id>[\d]+)` is `/orders/{id}`.
	 *
	 * @param string $route The route below the namespace.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function path( string $route ): string {
		return (string) preg_replace( '/\(\?P<([a-zA-Z0-9_]+)>[^)]*\)/', '{$1}', '' === $route ? '/' : $route );
	}

	/**
	 * The parameters a route names in its path.
	 *
	 * @param string $route The route.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	private static function path_params( string $route ): array {
		preg_match_all( '/\(\?P<([a-zA-Z0-9_]+)>/', $route, $found );

		return $found[1];
	}

	/**
	 * A name for an operation: `getOrdersIdRefunds`.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function operation_id( string $method, string $path ): string {
		$words = preg_split( '/[^a-zA-Z0-9]+/', $path, -1, PREG_SPLIT_NO_EMPTY );

		return strtolower( $method ) . implode( '', array_map( 'ucfirst', (array) $words ) );
	}

	/**
	 * A WordPress schema as JSON Schema proper.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function schema( array $schema ): array {
		$out      = [];
		$required = [];

		foreach ( $schema as $key => $value ) {
			if ( self::is_left_out( (string) $key, $value ) ) {
				continue;
			}

			if ( 'readonly' === $key ) {
				$out['readOnly'] = (bool) $value;
			} elseif ( 'properties' === $key && is_array( $value ) ) {
				foreach ( $value as $name => $property ) {
					$property = (array) $property;

					if ( true === ( $property['required'] ?? null ) ) {
						$required[] = (string) $name;
					}

					// WordPress says `required` on the property, as a boolean;
					// JSON Schema says it on the object, as a list of names.
					if ( is_bool( $property['required'] ?? null ) ) {
						unset( $property['required'] );
					}

					$out['properties'][ $name ] = self::schema( $property );
				}
			} elseif ( in_array( $key, [ 'items', 'additionalProperties' ], true ) && is_array( $value ) ) {
				$out[ $key ] = self::schema( $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		if ( [] !== $required ) {
			$out['required'] = $required;
		}

		return $out;
	}

	/**
	 * Whether a keyword of a WordPress schema has no place in the document.
	 *
	 * @param string $key   The keyword.
	 * @param mixed  $value Its value.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private static function is_left_out( string $key, $value ): bool {
		if ( in_array( $key, self::WORDPRESS_ONLY, true ) ) {
			return true;
		}

		return 'default' === $key && [] === $value;
	}
}
