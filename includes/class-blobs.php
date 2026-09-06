<?php
/**
 * Image selection and blob uploads.
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WordPress attachments into AT Protocol blob references.
 *
 * Blobs are uploaded separately from records; the reference returned by the
 * PDS is what gets embedded. Uploads are the slowest part of a publish, so
 * results are cached against the file's hash.
 */
class Blobs {

	/**
	 * Hard ceiling from the lexicon. Files above this are skipped rather than
	 * uploaded and rejected.
	 */
	private const MAX_BYTES = 1000000;

	/**
	 * Option key for the upload cache.
	 */
	private const CACHE_KEY = 'controlled_atmosphere_blob_cache';

	/**
	 * Uploads a post's featured image as a cover image blob.
	 *
	 * @param ATProto_Client $client Authenticated client.
	 * @param int            $post_id Post ID.
	 * @return array|null|\WP_Error Blob reference, null when there is nothing
	 *                              suitable to upload, or an error.
	 */
	public static function cover_image( ATProto_Client $client, int $post_id ) {
		$attachment_id = get_post_thumbnail_id( $post_id );

		if ( ! $attachment_id ) {
			return null;
		}

		return self::upload_attachment( $client, (int) $attachment_id );
	}

	/**
	 * Uploads the site icon as the publication icon blob.
	 *
	 * @param ATProto_Client $client Authenticated client.
	 * @return array|null|\WP_Error
	 */
	public static function site_icon( ATProto_Client $client ) {
		$attachment_id = (int) get_option( 'site_icon' );

		if ( ! $attachment_id ) {
			return null;
		}

		return self::upload_attachment( $client, $attachment_id );
	}

	/**
	 * Uploads an attachment, choosing a size that fits the blob limit.
	 *
	 * @param ATProto_Client $client        Authenticated client.
	 * @param int            $attachment_id Attachment ID.
	 * @return array|null|\WP_Error
	 */
	private static function upload_attachment( ATProto_Client $client, int $attachment_id ) {
		$path = self::pick_file( $attachment_id );

		if ( null === $path ) {
			return null;
		}

		$hash   = md5_file( $path );
		$cached = self::cache_get( $attachment_id, (string) $hash );

		if ( null !== $cached ) {
			return $cached;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.

		if ( false === $bytes ) {
			return null;
		}

		$mime = wp_check_filetype( $path );
		$blob = $client->upload_blob( $bytes, $mime['type'] ?: 'application/octet-stream' );

		if ( is_wp_error( $blob ) ) {
			return $blob;
		}

		self::cache_set( $attachment_id, (string) $hash, $blob );

		return $blob;
	}

	/**
	 * Chooses the largest registered image size that fits under the blob limit.
	 *
	 * Prefers an intermediate size over the original, which is usually far too
	 * large. Returns null when nothing fits.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Absolute file path.
	 */
	private static function pick_file( int $attachment_id ): ?string {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$base = get_attached_file( $attachment_id );

		if ( ! $base || ! file_exists( $base ) ) {
			return null;
		}

		$dir        = dirname( $base );
		$candidates = array();

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$path = $dir . '/' . $size['file'];

				if ( file_exists( $path ) ) {
					$candidates[ $path ] = (int) ( $size['width'] ?? 0 );
				}
			}
		}

		$candidates[ $base ] = (int) ( $meta['width'] ?? PHP_INT_MAX );

		// Largest first, so the best image that fits wins.
		arsort( $candidates );

		foreach ( array_keys( $candidates ) as $path ) {
			if ( filesize( $path ) <= self::MAX_BYTES ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Reads a cached blob reference.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hash          File hash.
	 */
	private static function cache_get( int $attachment_id, string $hash ): ?array {
		$cache = get_option( self::CACHE_KEY, array() );
		$entry = $cache[ $attachment_id ] ?? null;

		if ( is_array( $entry ) && ( $entry['hash'] ?? '' ) === $hash && ! empty( $entry['blob'] ) ) {
			return $entry['blob'];
		}

		return null;
	}

	/**
	 * Stores a blob reference against a file hash.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hash          File hash.
	 * @param array  $blob          Blob reference.
	 */
	private static function cache_set( int $attachment_id, string $hash, array $blob ): void {
		$cache = get_option( self::CACHE_KEY, array() );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$cache[ $attachment_id ] = array(
			'hash' => $hash,
			'blob' => $blob,
		);

		update_option( self::CACHE_KEY, $cache, false );
	}
}
