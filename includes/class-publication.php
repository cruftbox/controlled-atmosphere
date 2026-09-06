<?php
/**
 * The publication record, its verification endpoint, and the front page link tag.
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the site's single site.standard.publication record.
 */
class Publication {

	/**
	 * Lexicon NSID.
	 */
	public const COLLECTION = 'site.standard.publication';

	/**
	 * Record key for the publication.
	 *
	 * INFERENCE, NOT DOCUMENTED. Standard.site does not state an rkey
	 * convention; "self" is the AT Protocol convention for singleton records.
	 * Confirm against a working implementation before relying on it. Changing
	 * this constant orphans any record already written under the old key.
	 */
	public const RKEY = 'self';

	/**
	 * The path Bluesky fetches to verify domain ownership of the publication.
	 */
	public const WELL_KNOWN_PATH = '/.well-known/site.standard.publication';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers hooks.
	 */
	public function init(): void {
		// Priority 0: the verification response must be sent before anything
		// else can turn the request into a 404 or redirect it.
		add_action( 'init', array( $this, 'maybe_serve_well_known' ), 0 );
		add_action( 'wp_head', array( $this, 'render_link_tag' ) );
	}

	/**
	 * Serves the verification endpoint.
	 *
	 * Implemented by inspecting the request rather than via a rewrite rule:
	 * .well-known paths are commonly intercepted or rewritten by the web
	 * server, and a rewrite rule would not survive that. This still cannot
	 * help when the server never passes the request to PHP at all, which is
	 * why setup runs an external self-check.
	 */
	public function maybe_serve_well_known(): void {
		$request = isset( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, not stored or echoed.
			: '';

		$path = wp_parse_url( $request, PHP_URL_PATH );

		if ( self::WELL_KNOWN_PATH !== untrailingslashit( (string) $path ) ) {
			return;
		}

		$uri = $this->get_uri();

		if ( '' === $uri ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'No publication record configured.';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $uri; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text body, not HTML.
		exit;
	}

	/**
	 * Emits the publication discovery hint on the front page.
	 *
	 * This tag is a hint only. Verification depends on the .well-known
	 * endpoint, not on this markup.
	 */
	public function render_link_tag(): void {
		if ( ! is_front_page() ) {
			return;
		}

		$uri = $this->get_uri();

		if ( '' === $uri ) {
			return;
		}

		printf(
			'<link rel="site.standard.publication" href="%s" />' . "\n",
			esc_attr( $uri )
		);
	}

	/**
	 * The stored AT-URI of the publication record.
	 */
	public function get_uri(): string {
		return (string) get_setting( 'publication_uri', '' );
	}

	/**
	 * Creates or updates the publication record from current settings.
	 *
	 * @return string|\WP_Error The record AT-URI.
	 */
	public function sync() {
		$client = ATProto_Client::from_settings();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$record = array(
			'url'  => untrailingslashit( home_url() ),
			'name' => $this->clamp( (string) get_setting( 'publication_name', get_bloginfo( 'name' ) ), 500 ),
		);

		$description = trim( (string) get_setting( 'publication_description', get_bloginfo( 'description' ) ) );

		if ( '' !== $description ) {
			$record['description'] = $this->clamp( $description, 3000 );
		}

		// Defaults to off: the user decides when their publication becomes
		// discoverable to people who have not seen a link to it.
		$record['preferences'] = array(
			'showInDiscover' => (bool) get_setting( 'show_in_discover', false ),
		);

		$icon = Blobs::site_icon( $client );

		if ( ! is_wp_error( $icon ) && null !== $icon ) {
			$record['icon'] = $icon;
		}

		$response = $client->put_record( self::COLLECTION, self::RKEY, $record );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$settings                    = get_setting();
		$settings['publication_uri'] = (string) ( $response['uri'] ?? '' );
		$settings['publication_cid'] = (string) ( $response['cid'] ?? '' );
		update_option( OPTION_KEY, $settings );

		return $settings['publication_uri'];
	}

	/**
	 * Confirms the verification endpoint is reachable from outside.
	 *
	 * Many hosts intercept .well-known before WordPress sees the request. That
	 * failure is invisible from inside the admin, so setup fetches the site's
	 * own URL over HTTP and compares what actually comes back.
	 *
	 * @return true|\WP_Error
	 */
	public function verify_well_known() {
		$expected = $this->get_uri();

		if ( '' === $expected ) {
			return new \WP_Error(
				'controlled_atmosphere_no_publication',
				__( 'No publication record exists yet.', 'controlled-atmosphere' )
			);
		}

		$url = home_url( self::WELL_KNOWN_PATH );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 15,
				// Some hosts serve a different vhost to loopback requests; a
				// redirect here is still a legitimate path to the endpoint.
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = trim( wp_remote_retrieve_body( $response ) );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'controlled_atmosphere_well_known_status',
				sprintf(
					/* translators: 1: URL, 2: HTTP status code. */
					__( '%1$s returned HTTP %2$d. Your web server is likely intercepting the .well-known path before WordPress sees it.', 'controlled-atmosphere' ),
					$url,
					$code
				)
			);
		}

		if ( $body !== $expected ) {
			return new \WP_Error(
				'controlled_atmosphere_well_known_mismatch',
				sprintf(
					/* translators: 1: expected value, 2: returned value. */
					__( 'The verification endpoint returned the wrong value. Expected %1$s but got %2$s. Something else on your server is answering this path.', 'controlled-atmosphere' ),
					$expected,
					'' === $body ? __( 'an empty response', 'controlled-atmosphere' ) : $body
				)
			);
		}

		return true;
	}

	/**
	 * Truncates to a grapheme budget.
	 *
	 * Lexicon limits count graphemes, and the PDS rejects over-length values,
	 * so this errs on the side of cutting early.
	 *
	 * @param string $text     Input.
	 * @param int    $graphemes Maximum graphemes.
	 */
	private function clamp( string $text, int $graphemes ): string {
		$text = trim( wp_strip_all_tags( $text ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $graphemes ) {
			return mb_substr( $text, 0, $graphemes );
		}

		return $text;
	}
}
