<?php
/**
 * Who may do what through an API.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use WP_Error;
use WP_REST_Request;

/**
 * The check every route makes.
 *
 * Two questions: is anybody signed in, and may they do this. The second is
 * asked of the plugin's capabilities as a Capabilities set keeps them
 * -- a kind (`read`, `write`, `refund`) and an area (`orders`, `reports`) --
 * so the API, the admin screens and the abilities all ask the same thing.
 * A `can` callable in the API's configuration decides instead; with
 * neither, it takes `manage_options`.
 *
 * How often they have asked is a third question, and it is not asked here:
 * WordPress runs a route's permission checks a second time after the
 * request, for every method the route has, to write the `Allow` header --
 * so anything counted here is counted twice, and a GET would be counted
 * against the allowance for refunds. See Controller::handle().
 *
 * @since 1.0.0
 */
final class Permissions {

	/**
	 * The permissions of an API.
	 *
	 * @param Api $api The API.
	 */
	public function __construct( private readonly Api $api ) {}

	/**
	 * The check a route makes before it runs.
	 *
	 * @param string          $kind    `read`, `write` or `refund`.
	 * @param string          $area    What it is about.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function check( string $kind, string $area, WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				$this->api->code( 'unauthorized' ),
				$this->signed_out_message(),
				[ 'status' => 401 ]
			);
		}

		if ( ! $this->can( $kind, $area, $request ) ) {
			return new WP_Error(
				$this->api->code( 'forbidden' ),
				'read' === $kind ? $this->api->text( 'forbidden_read' ) : $this->api->text( 'forbidden_write' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Whether the current user may.
	 *
	 * @param string               $kind    `read`, `write` or `refund`.
	 * @param string               $area    What it is about.
	 * @param WP_REST_Request|null $request The request, when there is one.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function can( string $kind, string $area, ?WP_REST_Request $request = null ): bool {
		$can = $this->api->config( 'can' );

		if ( is_callable( $can ) ) {
			return (bool) $can( $kind, $area, $request );
		}

		$set = $this->capabilities();

		return null !== $set ? $set->can( $kind, $area, $request ) : current_user_can( 'manage_options' );
	}

	/**
	 * The capability set the API asks, if it has one.
	 *
	 * @return Capabilities|null
	 * @since 1.0.0
	 */
	public function capabilities(): ?Capabilities {
		$set = $this->api->config( 'capabilities' );

		if ( is_string( $set ) && '' !== $set ) {
			$set = Capabilities::get( $set );
		}

		return $set instanceof Capabilities ? $set : null;
	}

	/**
	 * Why application passwords cannot be used here, if they cannot.
	 *
	 * An app signs in with one, and a site where they are switched off
	 * answers every request with a refusal that says nothing about why.
	 * This says why, in words a connect screen and a 401 can both use.
	 *
	 * @return string Empty when they are available.
	 * @since 1.0.0
	 */
	public static function application_passwords_problem(): string {
		if ( wp_is_application_passwords_available() ) {
			return '';
		}

		if ( ! wp_is_application_passwords_supported() ) {
			return __( 'This site is not served over HTTPS, and WordPress only allows application passwords over a secure connection. Move the site to HTTPS and the app can connect.', 'sugarcommerce' );
		}

		return __( 'Application passwords are switched off on this site, usually by a security plugin or a line in the theme. Switch them back on and the app can connect.', 'sugarcommerce' );
	}

	/**
	 * What a request with nobody signed in is told.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function signed_out_message(): string {
		$problem = self::application_passwords_problem();

		return '' !== $problem ? $problem : $this->api->text( 'signed_out' );
	}
}
