<?php
/**
 * Per-post document records and their lifecycle.
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * Owns one site.standard.document record per published post.
 *
 * All state changes funnel through reconcile(), which compares what the repo
 * should contain against what it does contain. That keeps the many ways a post
 * can change -- published, edited, scheduled, excluded, trashed -- from each
 * needing their own bespoke handling, and makes double-firing harmless.
 */
class Document {

	/**
	 * Lexicon NSID.
	 */
	public const COLLECTION = 'site.standard.document';

	/**
	 * Post meta holding the last write error, for surfacing in the admin.
	 */
	private const META_ERROR = '_controlled_atmosphere_error';

	/**
	 * Post IDs already reconciled in this request.
	 *
	 * Both wp_after_insert_post and transition_post_status can fire for the
	 * same save. Reconciliation is idempotent, but there is no reason to pay
	 * for the network round trip twice.
	 *
	 * @var array<int,bool>
	 */
	private array $seen = array();

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers hooks.
	 */
	public function init(): void {
		// Editor saves, including meta changes made in the same request.
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 20, 2 );

		// Scheduled publishes and programmatic status changes do not route
		// through wp_insert_post(), so wp_after_insert_post never fires for
		// them.
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );

		add_action( 'before_delete_post', array( $this, 'on_delete' ) );

		add_action( 'wp_head', array( $this, 'render_link_tag' ) );

		add_filter( 'manage_post_posts_columns', array( $this, 'add_admin_column' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Handles an editor save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save( int $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->reconcile( $post );
	}

	/**
	 * Handles a status transition.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Previous status.
	 * @param \WP_Post $post       Post object.
	 */
	public function on_transition( string $new_status, string $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
			return;
		}

		$this->reconcile( $post );
	}

	/**
	 * Removes the record when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( int $post_id ): void {
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}

		$this->remove( $post_id );
	}

	/**
	 * Brings the repo in line with what this post should have.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function reconcile( \WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $this->seen[ $post->ID ] ) ) {
			return;
		}

		$this->seen[ $post->ID ] = true;

		if ( $this->should_have_record( $post ) ) {
			$this->sync( $post );
		} else {
			$this->remove( $post->ID );
		}
	}

	/**
	 * Whether a post should have a record in the repo.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function should_have_record( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( Post_Meta::is_excluded( $post->ID ) ) {
			return false;
		}

		// Password-protected posts are not publicly readable, so a public
		// record pointing at one would advertise something no one can read.
		if ( '' !== $post->post_password ) {
			return false;
		}

		return true;
	}

	/**
	 * Creates or updates the record for a post.
	 *
	 * Failures never bubble up. A post must publish successfully even when the
	 * PDS is unreachable; the error is stored and surfaced in the admin
	 * instead.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the write succeeded.
	 */
	public function sync( \WP_Post $post ): bool {
		$client = ATProto_Client::from_settings();

		if ( is_wp_error( $client ) ) {
			// Unconfigured is not an error worth nagging about on every save.
			return false;
		}

		$site = Publication::instance()->get_uri();

		if ( '' === $site ) {
			$this->record_error( $post->ID, __( 'No publication record exists yet. Sync the publication in Settings first.', 'controlled-atmosphere' ) );
			return false;
		}

		$record = $this->build_record( $post, $site, $client );
		$rkey   = (string) get_post_meta( $post->ID, META_RKEY, true );

		// The lexicon requires a TID record key, so a key cannot be derived
		// from the post ID -- the PDS rejects anything else. The server mints
		// one on first write and it is stored; later writes update in place.
		$response = '' === $rkey
			? $client->create_record( self::COLLECTION, $record )
			: $client->put_record( self::COLLECTION, $rkey, $record );

		if ( is_wp_error( $response ) ) {
			$this->record_error( $post->ID, $response->get_error_message() );
			return false;
		}

		$uri = (string) ( $response['uri'] ?? '' );

		update_post_meta( $post->ID, META_URI, $uri );
		update_post_meta( $post->ID, META_CID, (string) ( $response['cid'] ?? '' ) );
		update_post_meta( $post->ID, META_RKEY, ATProto_Client::rkey_from_uri( $uri ) );
		delete_post_meta( $post->ID, self::META_ERROR );

		return true;
	}

	/**
	 * Deletes the record for a post, if one exists.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the delete succeeded or was unnecessary.
	 */
	public function remove( int $post_id ): bool {
		$uri = (string) get_post_meta( $post_id, META_URI, true );

		if ( '' === $uri ) {
			return true;
		}

		$client = ATProto_Client::from_settings();

		if ( is_wp_error( $client ) ) {
			return false;
		}

		// The record key is server-assigned, so the stored value is the only
		// way to address the record. Without it there is nothing safe to
		// delete: guessing a key would target someone else's record or none at
		// all. Clear the local pointers and leave the repo alone.
		$rkey = (string) get_post_meta( $post_id, META_RKEY, true );

		if ( '' === $rkey ) {
			delete_post_meta( $post_id, META_URI );
			delete_post_meta( $post_id, META_CID );

			return false;
		}

		$response = $client->delete_record( self::COLLECTION, $rkey );

		if ( is_wp_error( $response ) ) {
			$this->record_error( $post_id, $response->get_error_message() );
			return false;
		}

		delete_post_meta( $post_id, META_URI );
		delete_post_meta( $post_id, META_CID );
		delete_post_meta( $post_id, META_RKEY );
		delete_post_meta( $post_id, self::META_ERROR );

		return true;
	}

	/**
	 * Assembles the record body.
	 *
	 * @param \WP_Post       $post   Post object.
	 * @param string         $site   Publication AT-URI.
	 * @param ATProto_Client $client Authenticated client, for blob uploads.
	 * @return array
	 */
	private function build_record( \WP_Post $post, string $site, ATProto_Client $client ): array {
		$record = array(
			'site'        => $site,
			'title'       => $this->clamp( $this->plain_title( $post ), 500 ),
			'publishedAt' => (string) get_post_time( 'c', true, $post ),
		);

		$path = $this->path( $post );

		if ( '' !== $path ) {
			$record['path'] = $path;
		}

		$description = $this->description( $post );

		if ( '' !== $description ) {
			$record['description'] = $description;
		}

		$tags = $this->tags( $post );

		if ( array() !== $tags ) {
			$record['tags'] = $tags;
		}

		// Only meaningful once a post has actually been edited after publishing.
		$published = get_post_time( 'U', true, $post );
		$modified  = get_post_modified_time( 'U', true, $post );

		if ( $modified && $published && $modified > $published ) {
			$record['updatedAt'] = (string) get_post_modified_time( 'c', true, $post );
		}

		$cover = Blobs::cover_image( $client, $post->ID );

		if ( ! is_wp_error( $cover ) && null !== $cover ) {
			$record['coverImage'] = $cover;
		}

		/**
		 * Filters the document record before it is written.
		 *
		 * @param array    $record The record body, without $type.
		 * @param \WP_Post $post   The post it was built from.
		 */
		return apply_filters( 'controlled_atmosphere_document_record', $record, $post );
	}

	/**
	 * The post title as plain text.
	 *
	 * get_the_title() applies filters and leaves HTML entities in place; the
	 * record wants a literal string.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function plain_title( \WP_Post $post ): string {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		return trim( $title );
	}

	/**
	 * The permalink path, with a leading slash.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function path( \WP_Post $post ): string {
		$path = wp_parse_url( get_permalink( $post ), PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * A plain-text summary of the post.
	 *
	 * Uses the manual excerpt when there is one. Otherwise derives one from the
	 * content, stripped of shortcodes and markup, truncated on a word boundary.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function description( \WP_Post $post ): string {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $this->clamp( $this->to_plain_text( $post->post_excerpt ), 3000 );
		}

		$text = $this->to_plain_text( $post->post_content );

		if ( '' === $text ) {
			return '';
		}

		return $this->truncate_words( $text, 300 );
	}

	/**
	 * Reduces post content to plain text.
	 *
	 * Block comments are removed before tag stripping, since they survive
	 * wp_strip_all_tags() as visible text.
	 *
	 * @param string $content Raw content.
	 */
	private function to_plain_text( string $content ): string {
		$content = preg_replace( '/<!--\s*\/?wp:.*?-->/s', ' ', $content ) ?? $content;
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( '/\s+/u', ' ', $content ) ?? $content;

		return trim( $content );
	}

	/**
	 * Truncates on a word boundary.
	 *
	 * @param string $text  Input.
	 * @param int    $limit Maximum characters.
	 */
	private function truncate_words( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );

		// Only honour the word boundary if it is not absurdly early, which can
		// happen with CJK text that contains no spaces at all.
		if ( false !== $space && $space > (int) ( $limit * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t\n\r\0\x0B.,;:" ) . '…';
	}

	/**
	 * The post's tag names.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string[]
	 */
	private function tags( \WP_Post $post ): array {
		$terms = get_the_terms( $post, 'post_tag' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$tags = array();

		foreach ( $terms as $term ) {
			$name = $this->clamp( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), 128 );

			if ( '' !== $name ) {
				$tags[] = $name;
			}
		}

		return array_values( array_slice( $tags, 0, 25 ) );
	}

	/**
	 * Truncates to a grapheme budget.
	 *
	 * Lexicon limits count graphemes and the PDS rejects over-length values.
	 *
	 * @param string $text      Input.
	 * @param int    $graphemes Maximum graphemes.
	 */
	private function clamp( string $text, int $graphemes ): string {
		$text = trim( $text );

		return mb_strlen( $text ) > $graphemes ? mb_substr( $text, 0, $graphemes ) : $text;
	}

	/**
	 * Stores a write failure for display in the admin.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 */
	private function record_error( int $post_id, string $message ): void {
		update_post_meta( $post_id, self::META_ERROR, $message );
	}

	/**
	 * Emits the document link tag on single posts.
	 *
	 * This is what Bluesky's crawler reads to find the record.
	 */
	public function render_link_tag(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$uri = (string) get_post_meta( get_queried_object_id(), META_URI, true );

		if ( '' === $uri ) {
			return;
		}

		printf(
			'<link rel="site.standard.document" href="%s" />' . "\n",
			esc_attr( $uri )
		);
	}

	/**
	 * Adds the record status column to the posts list.
	 *
	 * Writes fail soft, so without a visible indicator a failure would be
	 * silent -- the post publishes normally and nothing says the record is
	 * missing.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_admin_column( array $columns ): array {
		$columns['controlled_atmosphere'] = __( 'Bluesky', 'controlled-atmosphere' );

		return $columns;
	}

	/**
	 * Renders the record status column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_column( string $column, int $post_id ): void {
		if ( 'controlled_atmosphere' !== $column ) {
			return;
		}

		$error = (string) get_post_meta( $post_id, self::META_ERROR, true );

		if ( '' !== $error ) {
			printf(
				'<span style="color:#d63638" title="%s">%s</span>',
				esc_attr( $error ),
				esc_html__( 'Failed', 'controlled-atmosphere' )
			);
			return;
		}

		if ( Post_Meta::is_excluded( $post_id ) ) {
			printf( '<span style="color:#787c82">%s</span>', esc_html__( 'Excluded', 'controlled-atmosphere' ) );
			return;
		}

		$uri = (string) get_post_meta( $post_id, META_URI, true );

		if ( '' !== $uri ) {
			printf(
				'<span style="color:#00a32a" title="%s">%s</span>',
				esc_attr( $uri ),
				esc_html__( 'Published', 'controlled-atmosphere' )
			);
			return;
		}

		if ( 'publish' === get_post_status( $post_id ) ) {
			printf( '<span style="color:#787c82">%s</span>', esc_html__( 'No record', 'controlled-atmosphere' ) );
			return;
		}

		echo '<span style="color:#787c82">&mdash;</span>';
	}
}
