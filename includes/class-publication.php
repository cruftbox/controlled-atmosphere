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
		return (string) get_state( 'publication_uri', '' );
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

		$rkey = $this->existing_rkey( $client, $record['url'] );

		if ( is_wp_error( $rkey ) ) {
			return $rkey;
		}

		// A record key already in use is updated in place. Otherwise let the
		// server mint one, matching how other Standard.site tooling writes
		// these records.
		$response = '' === $rkey
			? $client->create_record( self::COLLECTION, $record )
			: $client->put_record( self::COLLECTION, $rkey, $record );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$uri = (string) ( $response['uri'] ?? '' );

		update_state(
			array(
				'publication_uri'  => $uri,
				'publication_cid'  => (string) ( $response['cid'] ?? '' ),
				'publication_rkey' => ATProto_Client::rkey_from_uri( $uri ),
			)
		);

		return $uri;
	}

	/**
	 * Finds the record key to write the publication under.
	 *
	 * Prefers the key already stored. Failing that, looks for a record in the
	 * repo whose url matches this site and adopts it -- a site may already
	 * have a publication record written by other tooling, and creating a
	 * second one would leave two records competing for a single verification
	 * endpoint.
	 *
	 * @param ATProto_Client $client Authenticated client.
	 * @param string         $url    This site's URL, without trailing slash.
	 * @return string|\WP_Error Existing key, or an empty string to create one.
	 */
	private function existing_rkey( ATProto_Client $client, string $url ) {
		$stored = (string) get_state( 'publication_rkey', '' );

		if ( '' !== $stored ) {
			return $stored;
		}

		$response = $client->list_records( self::COLLECTION );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( (array) ( $response['records'] ?? array() ) as $record ) {
			$existing = untrailingslashit( (string) ( $record['value']['url'] ?? '' ) );

			if ( $existing === $url && ! empty( $record['uri'] ) ) {
				return ATProto_Client::rkey_from_uri( (string) $record['uri'] );
			}
		}

		return '';
	}

	/**
	 * Writes the verification file to disk.
	 *
	 * The PHP handler in maybe_serve_well_known() only works when the request
	 * actually reaches WordPress. Where the web server owns /.well-known/ --
	 * commonly because a TLS client aliased that directory for ACME challenges
	 * -- it never does, and the only thing that answers is a real file. Since
	 * the server is already serving that directory from disk, putting the file
	 * there works immediately.
	 *
	 * @return string|\WP_Error Absolute path written, or an error explaining
	 *                          what to do by hand.
	 */
	public function write_verification_file() {
		$uri = $this->get_uri();

		if ( '' === $uri ) {
			return new \WP_Error(
				'controlled_atmosphere_no_publication',
				__( 'Sync the publication record first, so there is an AT-URI to write.', 'controlled-atmosphere' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		global $wp_filesystem;

		// Only the direct transport is useful here. Prompting for FTP
		// credentials to write one line of text would be a worse experience
		// than the manual instructions.
		if ( ! WP_Filesystem() || ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $this->manual_instructions( __( 'WordPress cannot write to the filesystem directly on this host.', 'controlled-atmosphere' ) );
		}

		// get_home_path() resolves to the directory serving home_url(), which
		// is what matters here -- it differs from ABSPATH when WordPress lives
		// in a subdirectory but the site is served from the domain root.
		$root = trailingslashit( get_home_path() );
		$dir  = $root . '.well-known';
		$file = $dir . '/site.standard.publication';

		if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			return $this->manual_instructions(
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create %s.', 'controlled-atmosphere' ),
					$dir
				)
			);
		}

		if ( ! $wp_filesystem->put_contents( $file, $uri, FS_CHMOD_FILE ) ) {
			return $this->manual_instructions(
				sprintf(
					/* translators: %s: file path. */
					__( 'Could not write %s.', 'controlled-atmosphere' ),
					$file
				)
			);
		}

		// Recorded so uninstall removes only a file this plugin created, never
		// one placed by hand or by another tool.
		update_state( array( 'verification_file' => $file ) );

		return $file;
	}

	/**
	 * Builds an error carrying exact by-hand instructions.
	 *
	 * @param string $reason Why the automatic write failed.
	 */
	private function manual_instructions( string $reason ): \WP_Error {
		return new \WP_Error(
			'controlled_atmosphere_write_failed',
			sprintf(
				/* translators: 1: reason, 2: file path, 3: file contents. */
				__( '%1$s Create this file by hand instead: %2$s containing exactly one line: %3$s', 'controlled-atmosphere' ),
				$reason,
				'.well-known/site.standard.publication',
				$this->get_uri()
			)
		);
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
					/* translators: 1: URL, 2: HTTP status code, 3: file path, 4: file contents. */
					__( '%1$s returned HTTP %2$d. Your web server is handling the .well-known path itself, so WordPress never sees the request and cannot answer it. Fix this by creating a file at %3$s in your site root containing exactly: %4$s', 'controlled-atmosphere' ),
					$url,
					$code,
					'.well-known/site.standard.publication',
					$expected
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
