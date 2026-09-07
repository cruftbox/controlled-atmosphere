<?php
/**
 * AT Protocol XRPC client: identity resolution, sessions, and repo writes.
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * Thin XRPC transport over the WordPress HTTP API.
 *
 * Deliberately narrow: this client only knows the handful of endpoints the
 * plugin needs. It never touches app.bsky.* write endpoints.
 */
class ATProto_Client {

	/**
	 * Transient key for the cached session tokens.
	 */
	private const SESSION_TRANSIENT = 'controlled_atmosphere_session';

	/**
	 * Seconds of margin applied before an access token's real expiry.
	 *
	 * Access tokens are short-lived; refreshing slightly early avoids losing a
	 * publish to a token that expires mid-request.
	 */
	private const EXPIRY_MARGIN = 300;

	/**
	 * Request timeout in seconds. A slow PDS must not hang a publish.
	 */
	private const TIMEOUT = 15;

	/**
	 * Resolved PDS base URL, without trailing slash.
	 */
	private string $pds;

	/**
	 * Account DID.
	 */
	private string $did;

	/**
	 * App password.
	 */
	private string $app_password;

	/**
	 * @param string $pds          PDS base URL.
	 * @param string $did          Account DID.
	 * @param string $app_password App password.
	 */
	public function __construct( string $pds, string $did, string $app_password ) {
		$this->pds          = untrailingslashit( $pds );
		$this->did          = $did;
		$this->app_password = $app_password;
	}

	/**
	 * Builds a client from stored settings.
	 *
	 * @return self|\WP_Error WP_Error when the plugin is not fully configured.
	 */
	public static function from_settings() {
		$did      = (string) get_setting( 'did', '' );
		$pds      = (string) get_setting( 'pds', '' );
		$password = get_app_password();

		if ( '' === $did || '' === $pds || '' === $password ) {
			return new \WP_Error(
				'controlled_atmosphere_unconfigured',
				__( 'Controlled Atmosphere is not fully configured.', 'controlled-atmosphere' )
			);
		}

		return new self( $pds, $did, $password );
	}

	/**
	 * Resolves a handle to a DID.
	 *
	 * Uses the public bsky.social resolver, which answers for any handle on the
	 * network regardless of which PDS hosts it.
	 *
	 * @param string $handle Handle without a leading @.
	 * @return string|\WP_Error
	 */
	public static function resolve_handle( string $handle ) {
		$handle = ltrim( trim( $handle ), '@' );

		$response = wp_remote_get(
			'https://bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=' . rawurlencode( $handle ),
			array( 'timeout' => self::TIMEOUT )
		);

		$body = self::decode( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['did'] ) ) {
			return new \WP_Error(
				'controlled_atmosphere_resolve_failed',
				sprintf(
					/* translators: %s: Bluesky handle. */
					__( 'Could not resolve the handle %s to a DID.', 'controlled-atmosphere' ),
					$handle
				)
			);
		}

		return (string) $body['did'];
	}

	/**
	 * Discovers the PDS endpoint for a DID from its DID document.
	 *
	 * Do not assume bsky.social: accounts may live on any PDS, and the DID
	 * document is the only authoritative source.
	 *
	 * @param string $did Account DID.
	 * @return string|\WP_Error PDS base URL.
	 */
	public static function resolve_pds( string $did ) {
		if ( str_starts_with( $did, 'did:plc:' ) ) {
			$url = 'https://plc.directory/' . rawurlencode( $did );
		} elseif ( str_starts_with( $did, 'did:web:' ) ) {
			$host = str_replace( ':', '/', substr( $did, strlen( 'did:web:' ) ) );
			$url  = 'https://' . $host . '/.well-known/did.json';
		} else {
			return new \WP_Error(
				'controlled_atmosphere_bad_did',
				__( 'Only did:plc: and did:web: identifiers are supported.', 'controlled-atmosphere' )
			);
		}

		$body = self::decode( wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		foreach ( (array) ( $body['service'] ?? array() ) as $service ) {
			$id = $service['id'] ?? '';
			if ( '#atproto_pds' === $id || str_ends_with( (string) $id, '#atproto_pds' ) ) {
				if ( ! empty( $service['serviceEndpoint'] ) ) {
					return untrailingslashit( (string) $service['serviceEndpoint'] );
				}
			}
		}

		return new \WP_Error(
			'controlled_atmosphere_no_pds',
			__( 'The DID document does not advertise a PDS endpoint.', 'controlled-atmosphere' )
		);
	}

	/**
	 * Returns a valid access token, refreshing or re-authenticating as needed.
	 *
	 * createSession is rate limited, so a cached session is reused until it is
	 * close to expiry, then refreshed. Full re-authentication happens only when
	 * the refresh token is also spent.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$session = get_transient( self::SESSION_TRANSIENT );

		if ( is_array( $session ) && ! empty( $session['accessJwt'] ) && ( $session['expires'] ?? 0 ) > time() ) {
			return (string) $session['accessJwt'];
		}

		if ( is_array( $session ) && ! empty( $session['refreshJwt'] ) ) {
			$refreshed = $this->refresh_session( (string) $session['refreshJwt'] );
			if ( ! is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
		}

		return $this->create_session();
	}

	/**
	 * Authenticates with the app password and caches the session.
	 *
	 * @return string|\WP_Error Access token.
	 */
	public function create_session() {
		$body = $this->request(
			'com.atproto.server.createSession',
			array(
				'identifier' => $this->did,
				'password'   => $this->app_password,
			),
			null
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return $this->store_session( $body );
	}

	/**
	 * Exchanges a refresh token for a new session.
	 *
	 * @param string $refresh_jwt Refresh token.
	 * @return string|\WP_Error Access token.
	 */
	private function refresh_session( string $refresh_jwt ) {
		$body = $this->request( 'com.atproto.server.refreshSession', array(), $refresh_jwt );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return $this->store_session( $body );
	}

	/**
	 * Caches a session response.
	 *
	 * The access token's real lifetime is not returned explicitly, so a
	 * conservative window is used and refreshed early.
	 *
	 * @param array $body Session response.
	 * @return string|\WP_Error Access token.
	 */
	private function store_session( array $body ) {
		if ( empty( $body['accessJwt'] ) ) {
			return new \WP_Error(
				'controlled_atmosphere_no_token',
				__( 'The PDS did not return an access token.', 'controlled-atmosphere' )
			);
		}

		$ttl = HOUR_IN_SECONDS;

		set_transient(
			self::SESSION_TRANSIENT,
			array(
				'accessJwt'  => (string) $body['accessJwt'],
				'refreshJwt' => (string) ( $body['refreshJwt'] ?? '' ),
				'expires'    => time() + $ttl - self::EXPIRY_MARGIN,
			),
			$ttl
		);

		return (string) $body['accessJwt'];
	}

	/**
	 * Clears the cached session. Called when credentials change.
	 */
	public static function forget_session(): void {
		delete_transient( self::SESSION_TRANSIENT );
	}

	/**
	 * Creates or replaces a record.
	 *
	 * putRecord is used rather than createRecord so that writes are idempotent:
	 * the same post always targets the same rkey, and a lost meta value cannot
	 * orphan a record in the repo.
	 *
	 * @param string $collection Lexicon NSID.
	 * @param string $rkey       Record key.
	 * @param array  $record     Record body, without $type.
	 * @return array|\WP_Error Response containing uri and cid.
	 */
	public function put_record( string $collection, string $rkey, array $record ) {
		$record['$type'] = $collection;

		return $this->authed_request(
			'com.atproto.repo.putRecord',
			array(
				'repo'       => $this->did,
				'collection' => $collection,
				'rkey'       => $rkey,
				'record'     => $record,
			)
		);
	}

	/**
	 * Creates a record, letting the server mint the record key.
	 *
	 * Used for the publication record, where observed tooling writes a
	 * server-assigned TID rather than a fixed key. The returned AT-URI carries
	 * the key, which the caller must store for later updates.
	 *
	 * @param string $collection Lexicon NSID.
	 * @param array  $record     Record body, without $type.
	 * @return array|\WP_Error Response containing uri and cid.
	 */
	public function create_record( string $collection, array $record ) {
		$record['$type'] = $collection;

		return $this->authed_request(
			'com.atproto.repo.createRecord',
			array(
				'repo'       => $this->did,
				'collection' => $collection,
				'record'     => $record,
			)
		);
	}

	/**
	 * Lists records in a collection.
	 *
	 * @param string $collection Lexicon NSID.
	 * @param int    $limit      Maximum records to return.
	 * @return array|\WP_Error Response containing a records array.
	 */
	public function list_records( string $collection, int $limit = 50 ) {
		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = add_query_arg(
			array(
				'repo'       => rawurlencode( $this->did ),
				'collection' => rawurlencode( $collection ),
				'limit'      => $limit,
			),
			$this->pds . '/xrpc/com.atproto.repo.listRecords'
		);

		return self::decode(
			wp_remote_get(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				)
			)
		);
	}

	/**
	 * Extracts the record key from an AT-URI.
	 *
	 * @param string $uri AT-URI.
	 */
	public static function rkey_from_uri( string $uri ): string {
		$parts = explode( '/', $uri );

		return (string) end( $parts );
	}

	/**
	 * Deletes a record.
	 *
	 * @param string $collection Lexicon NSID.
	 * @param string $rkey       Record key.
	 * @return array|\WP_Error
	 */
	public function delete_record( string $collection, string $rkey ) {
		return $this->authed_request(
			'com.atproto.repo.deleteRecord',
			array(
				'repo'       => $this->did,
				'collection' => $collection,
				'rkey'       => $rkey,
			)
		);
	}

	/**
	 * Uploads a blob and returns its reference.
	 *
	 * @param string $bytes     Raw file contents.
	 * @param string $mime_type Content type.
	 * @return array|\WP_Error The blob reference to embed in a record.
	 */
	public function upload_blob( string $bytes, string $mime_type ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			$this->pds . '/xrpc/com.atproto.repo.uploadBlob',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => $mime_type,
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => $bytes,
			)
		);

		$body = self::decode( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['blob'] ) ) {
			return new \WP_Error(
				'controlled_atmosphere_blob_failed',
				__( 'The PDS did not return a blob reference.', 'controlled-atmosphere' )
			);
		}

		return $body['blob'];
	}

	/**
	 * Performs an authenticated XRPC procedure call.
	 *
	 * @param string $nsid Endpoint NSID.
	 * @param array  $args Request body.
	 * @return array|\WP_Error
	 */
	private function authed_request( string $nsid, array $args ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return $this->request( $nsid, $args, $token );
	}

	/**
	 * Performs an XRPC procedure call.
	 *
	 * @param string      $nsid  Endpoint NSID.
	 * @param array       $args  Request body.
	 * @param string|null $token Bearer token, or null for unauthenticated calls.
	 * @return array|\WP_Error
	 */
	private function request( string $nsid, array $args, ?string $token ) {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( null !== $token && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			$this->pds . '/xrpc/' . $nsid,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);

		return self::decode( $response );
	}

	/**
	 * Turns an HTTP response into a decoded array or a WP_Error.
	 *
	 * Error messages from the PDS are surfaced verbatim because they are
	 * specific and actionable. Credentials never appear in a response body, so
	 * there is nothing to redact here.
	 *
	 * @param array|\WP_Error $response Result of a wp_remote_* call.
	 * @return array|\WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			$reset = wp_remote_retrieve_header( $response, 'ratelimit-reset' );

			return new \WP_Error(
				'controlled_atmosphere_rate_limited',
				__( 'Rate limited by the PDS.', 'controlled-atmosphere' ),
				array( 'reset' => $reset ? (int) $reset : 0 )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['message'] )
				? (string) $body['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The PDS returned HTTP %d.', 'controlled-atmosphere' ),
					$code
				);

			return new \WP_Error( 'controlled_atmosphere_http_error', $message, array( 'status' => $code ) );
		}

		return is_array( $body ) ? $body : array();
	}
}
