<?php
/**
 * The per-post opt-out control.
 *
 * @package ControlledAtmosphere;
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the exclusion flag and its editor UI.
 *
 * The flag is absent by default, so posts written before the plugin was
 * installed need no migration.
 */
class Post_Meta {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_panel' ) );
		add_action( 'add_meta_boxes_post', array( $this, 'add_classic_meta_box' ) );
		add_action( 'save_post_post', array( $this, 'save_classic_meta_box' ), 10, 2 );
	}

	/**
	 * Registers the exclusion flag for REST so the block editor can set it.
	 *
	 * The auth callback checks edit_post on the specific post rather than a
	 * blanket capability, so the meta is not writable by anyone who merely has
	 * an account.
	 */
	public function register_meta(): void {
		register_post_meta(
			'post',
			META_EXCLUDE,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Whether a post is excluded from record creation.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_excluded( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, META_EXCLUDE, true );
	}

	/**
	 * Loads the block editor sidebar panel.
	 */
	public function enqueue_editor_panel(): void {
		if ( 'post' !== get_post_type() ) {
			return;
		}

		$asset = PATH . 'assets/editor.js';

		if ( ! file_exists( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			'controlled-atmosphere-editor',
			plugins_url( 'assets/editor.js', PATH . 'controlled-atmosphere.php' ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			VERSION,
			true
		);

		wp_set_script_translations( 'controlled-atmosphere-editor', 'controlled-atmosphere' );
	}

	/**
	 * Adds the classic editor meta box.
	 *
	 * The panel has to exist in both editors: a site may have the block editor
	 * disabled entirely, and the setting would otherwise be unreachable.
	 */
	public function add_classic_meta_box(): void {
		// The block editor renders its own panel; adding a meta box too would
		// show the control twice.
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( get_post() ) ) {
			return;
		}

		add_meta_box(
			'controlled-atmosphere',
			__( 'Controlled Atmosphere', 'controlled-atmosphere' ),
			array( $this, 'render_classic_meta_box' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Renders the classic editor meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_classic_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'controlled_atmosphere_meta', 'controlled_atmosphere_nonce' );

		$excluded = self::is_excluded( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="controlled_atmosphere_exclude" value="1" <?php checked( $excluded ); ?> />
				<?php esc_html_e( 'Exclude from Bluesky publications', 'controlled-atmosphere' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'Removes this post\'s Standard.site record. Links to it will show a plain preview on Bluesky instead of an article card.', 'controlled-atmosphere' ); ?>
		</p>
		<?php
	}

	/**
	 * Saves the classic editor meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_classic_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['controlled_atmosphere_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['controlled_atmosphere_nonce'] ) ), 'controlled_atmosphere_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['controlled_atmosphere_exclude'] ) ) {
			update_post_meta( $post_id, META_EXCLUDE, true );
		} else {
			delete_post_meta( $post_id, META_EXCLUDE );
		}
	}
}
