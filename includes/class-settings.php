<?php
/**
 * Admin settings page, validation, and the setup status panel.
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

/**
 * The Settings -> Controlled Atmosphere screen.
 */
class Settings {

	private const PAGE_SLUG  = 'controlled-atmosphere';
	private const GROUP      = 'controlled_atmosphere';
	private const MASK       = '********';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_controlled_atmosphere_verify', array( $this, 'handle_verify' ) );
		add_action( 'admin_post_controlled_atmosphere_backfill', array( $this, 'handle_backfill' ) );
	}

	/**
	 * Adds the settings page.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Controlled Atmosphere', 'controlled-atmosphere' ),
			__( 'Controlled Atmosphere', 'controlled-atmosphere' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the settings group.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Validates and normalises submitted settings.
	 *
	 * Identity resolution happens here so that a save either produces a
	 * working configuration or an explicit error, never a half-configured
	 * state that fails silently later.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$existing = get_setting();
		$input    = is_array( $input ) ? $input : array();

		$clean = array(
			'publication_name'        => sanitize_text_field( $input['publication_name'] ?? '' ),
			'publication_description' => sanitize_textarea_field( $input['publication_description'] ?? '' ),
			'show_in_discover'        => ! empty( $input['show_in_discover'] ),
			// Carry forward values that are not user-editable.
			'publication_uri'         => $existing['publication_uri'] ?? '',
			'publication_cid'         => $existing['publication_cid'] ?? '',
		);

		// App password: an unchanged mask means "keep what is stored".
		$submitted_password = trim( (string) ( $input['app_password'] ?? '' ) );

		if ( app_password_is_constant() ) {
			$clean['app_password'] = '';
		} elseif ( self::MASK === $submitted_password || '' === $submitted_password ) {
			$clean['app_password'] = $existing['app_password'] ?? '';
		} else {
			// App passwords are formatted xxxx-xxxx-xxxx-xxxx. Warn rather than
			// reject, since the format is a convention and not guaranteed.
			if ( ! preg_match( '/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/i', $submitted_password ) ) {
				add_settings_error(
					OPTION_KEY,
					'app_password_format',
					__( 'That does not look like an app password. App passwords look like xxxx-xxxx-xxxx-xxxx. Do not use your account password.', 'controlled-atmosphere' ),
					'warning'
				);
			}

			$clean['app_password'] = $submitted_password;
			ATProto_Client::forget_session();
		}

		// Identity: accept a handle or a DID.
		$identity = trim( (string) ( $input['identity'] ?? '' ) );
		$identity = ltrim( $identity, '@' );

		$clean['identity'] = $identity;
		$clean['did']      = $existing['did'] ?? '';
		$clean['pds']      = $existing['pds'] ?? '';

		$pds_override = trim( (string) ( $input['pds_override'] ?? '' ) );

		if ( '' !== $pds_override ) {
			$pds_override = esc_url_raw( $pds_override, array( 'https' ) );

			if ( '' === $pds_override ) {
				add_settings_error(
					OPTION_KEY,
					'pds_override',
					__( 'The PDS override must be an https URL.', 'controlled-atmosphere' ),
					'error'
				);
			}
		}

		$clean['pds_override'] = $pds_override;

		if ( '' === $identity ) {
			return $clean;
		}

		// Only re-resolve when the identity changed, to avoid a network call on
		// every unrelated save.
		$identity_changed = ( $existing['identity'] ?? '' ) !== $identity;

		if ( $identity_changed || '' === $clean['did'] ) {
			$did = $this->resolve_identity( $identity );

			if ( is_wp_error( $did ) ) {
				add_settings_error( OPTION_KEY, 'identity', $did->get_error_message(), 'error' );
				return $clean;
			}

			$clean['did'] = $did;
			ATProto_Client::forget_session();
		}

		if ( '' !== $pds_override ) {
			$clean['pds'] = untrailingslashit( $pds_override );
		} elseif ( $identity_changed || '' === $clean['pds'] ) {
			$pds = ATProto_Client::resolve_pds( $clean['did'] );

			if ( is_wp_error( $pds ) ) {
				add_settings_error( OPTION_KEY, 'pds', $pds->get_error_message(), 'error' );
				return $clean;
			}

			$clean['pds'] = $pds;
		}

		return $clean;
	}

	/**
	 * Turns a handle or DID into a DID.
	 *
	 * @param string $identity Handle or DID.
	 * @return string|\WP_Error
	 */
	private function resolve_identity( string $identity ) {
		if ( str_starts_with( $identity, 'did:' ) ) {
			if ( ! preg_match( '/^did:(plc|web):[a-zA-Z0-9._:%-]+$/', $identity ) ) {
				return new \WP_Error(
					'controlled_atmosphere_bad_did',
					__( 'A DID must begin with did:plc: or did:web:.', 'controlled-atmosphere' )
				);
			}

			return $identity;
		}

		return ATProto_Client::resolve_handle( $identity );
	}

	/**
	 * Runs the publication sync and verification check on demand.
	 */
	public function handle_verify(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'controlled-atmosphere' ) );
		}

		check_admin_referer( 'controlled_atmosphere_verify' );

		$result = Publication::instance()->sync();
		$notice = 'synced';

		if ( is_wp_error( $result ) ) {
			$notice = 'sync_failed';
			set_transient( 'controlled_atmosphere_notice', $result->get_error_message(), 60 );
		} else {
			$check = Publication::instance()->verify_well_known();

			if ( is_wp_error( $check ) ) {
				$notice = 'well_known_failed';
				set_transient( 'controlled_atmosphere_notice', $check->get_error_message(), 60 );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'ca_state' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Creates records for the most recent published posts.
	 *
	 * Deliberately bounded. The archive on a long-running blog is large enough
	 * to hit AT Protocol write limits, and that is a separate job with its own
	 * pacing and resumability; this action exists to prove the pipeline works
	 * on a handful of posts first.
	 */
	public function handle_backfill(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'controlled-atmosphere' ) );
		}

		check_admin_referer( 'controlled_atmosphere_backfill' );

		$count = isset( $_POST['count'] ) ? absint( wp_unslash( $_POST['count'] ) ) : 10;
		$count = max( 1, min( 50, $count ) );

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => $count,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$done    = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $posts as $post ) {
			if ( Post_Meta::is_excluded( $post->ID ) ) {
				++$skipped;
				continue;
			}

			if ( Document::instance()->sync( $post ) ) {
				++$done;
			} else {
				++$failed;
			}
		}

		set_transient(
			'controlled_atmosphere_notice',
			sprintf(
				/* translators: 1: number written, 2: number failed, 3: number skipped. */
				__( '%1$d written, %2$d failed, %3$d skipped as excluded.', 'controlled-atmosphere' ),
				$done,
				$failed,
				$skipped
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'ca_state' => $failed > 0 ? 'backfill_partial' : 'backfill_done',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_setting();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Controlled Atmosphere', 'controlled-atmosphere' ); ?></h1>

			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Publishes Standard.site records for your posts so that pasting a post URL into Bluesky renders a native article card. This plugin never posts to your Bluesky feed.', 'controlled-atmosphere' ); ?>
			</p>

			<?php $this->render_state_notice(); ?>
			<?php settings_errors( OPTION_KEY ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Bluesky account', 'controlled-atmosphere' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ca-identity"><?php esc_html_e( 'Handle or DID', 'controlled-atmosphere' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( OPTION_KEY ); ?>[identity]" id="ca-identity" type="text"
								class="regular-text" value="<?php echo esc_attr( $settings['identity'] ?? '' ); ?>"
								placeholder="example.bsky.social" />
							<p class="description">
								<?php esc_html_e( 'Your handle (example.bsky.social) or your DID. The handle is resolved to a DID when you save.', 'controlled-atmosphere' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ca-password"><?php esc_html_e( 'App password', 'controlled-atmosphere' ); ?></label>
						</th>
						<td>
							<?php if ( app_password_is_constant() ) : ?>
								<p><code>CONTROLLED_ATMOSPHERE_APP_PASSWORD</code>
									<?php esc_html_e( 'is set in wp-config.php and takes precedence over this field.', 'controlled-atmosphere' ); ?>
								</p>
							<?php else : ?>
								<input name="<?php echo esc_attr( OPTION_KEY ); ?>[app_password]" id="ca-password"
									type="password" class="regular-text" autocomplete="new-password"
									value="<?php echo esc_attr( ! empty( $settings['app_password'] ) ? self::MASK : '' ); ?>" />
								<p class="description">
									<strong><?php esc_html_e( 'Use an app password, never your account password.', 'controlled-atmosphere' ); ?></strong>
									<?php esc_html_e( 'Create one in Bluesky under Settings, Privacy and Security, App Passwords.', 'controlled-atmosphere' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'This is stored in your WordPress database in a readable form, because WordPress has no secret store. Anyone with database or file access can read it. To keep it out of the database, define CONTROLLED_ATMOSPHERE_APP_PASSWORD in wp-config.php instead.', 'controlled-atmosphere' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ca-pds"><?php esc_html_e( 'PDS host', 'controlled-atmosphere' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( OPTION_KEY ); ?>[pds_override]" id="ca-pds" type="url"
								class="regular-text" value="<?php echo esc_attr( $settings['pds_override'] ?? '' ); ?>"
								placeholder="<?php echo esc_attr( $settings['pds'] ?? 'https://bsky.social' ); ?>" />
							<p class="description">
								<?php
								if ( ! empty( $settings['pds'] ) ) {
									printf(
										/* translators: %s: PDS URL. */
										esc_html__( 'Discovered from your DID document: %s. Leave blank unless you self-host and need to override it.', 'controlled-atmosphere' ),
										'<code>' . esc_html( $settings['pds'] ) . '</code>'
									);
								} else {
									esc_html_e( 'Discovered automatically from your DID document. Leave blank unless you self-host.', 'controlled-atmosphere' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Publication', 'controlled-atmosphere' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="ca-name"><?php esc_html_e( 'Name', 'controlled-atmosphere' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( OPTION_KEY ); ?>[publication_name]" id="ca-name" type="text"
								class="regular-text"
								value="<?php echo esc_attr( $settings['publication_name'] ?? get_bloginfo( 'name' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="ca-description"><?php esc_html_e( 'Description', 'controlled-atmosphere' ); ?></label>
						</th>
						<td>
							<textarea name="<?php echo esc_attr( OPTION_KEY ); ?>[publication_description]"
								id="ca-description" class="large-text" rows="3"><?php echo esc_textarea( $settings['publication_description'] ?? get_bloginfo( 'description' ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Discovery', 'controlled-atmosphere' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION_KEY ); ?>[show_in_discover]"
									value="1" <?php checked( ! empty( $settings['show_in_discover'] ) ); ?> />
								<?php esc_html_e( 'Allow this publication to appear in discovery feeds', 'controlled-atmosphere' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. This has no known effect in Bluesky today, which only surfaces Standard.site records as link cards on posts. Other apps may use it to show your publication to people who have not seen a link to it.', 'controlled-atmosphere' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_status(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the outcome of the last verify action.
	 */
	private function render_state_notice(): void {
		$state = isset( $_GET['ca_state'] ) ? sanitize_key( wp_unslash( $_GET['ca_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		if ( '' === $state ) {
			return;
		}

		$detail = get_transient( 'controlled_atmosphere_notice' );
		delete_transient( 'controlled_atmosphere_notice' );

		$map = array(
			'synced'            => array( 'success', __( 'Publication record saved and the verification endpoint responded correctly.', 'controlled-atmosphere' ) ),
			'sync_failed'       => array( 'error', __( 'Could not write the publication record.', 'controlled-atmosphere' ) ),
			'well_known_failed' => array( 'error', __( 'The publication record was written, but the verification endpoint is not reachable. Bluesky will not render article cards until this is fixed.', 'controlled-atmosphere' ) ),
			'backfill_done'     => array( 'success', __( 'Backfill complete.', 'controlled-atmosphere' ) ),
			'backfill_partial'  => array( 'warning', __( 'Backfill finished with failures. Check the Bluesky column on the Posts screen for details.', 'controlled-atmosphere' ) ),
		);

		if ( ! isset( $map[ $state ] ) ) {
			return;
		}

		[ $type, $message ] = $map[ $state ];

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p>%3$s</div>',
			esc_attr( $type ),
			esc_html( $message ),
			$detail ? '<p><code>' . esc_html( $detail ) . '</code></p>' : ''
		);
	}

	/**
	 * Renders the setup status panel.
	 *
	 * Each row reports real, checked state. The .well-known row matters most:
	 * when it fails, everything else can look correct while no card will ever
	 * render, and nothing inside WordPress would reveal that.
	 */
	private function render_status(): void {
		$settings = get_setting();
		$uri      = Publication::instance()->get_uri();
		?>
		<h2 class="title"><?php esc_html_e( 'Status', 'controlled-atmosphere' ); ?></h2>
		<table class="widefat striped" style="max-width:60em">
			<tbody>
				<?php
				$this->status_row(
					__( 'Account', 'controlled-atmosphere' ),
					! empty( $settings['did'] ),
					! empty( $settings['did'] ) ? $settings['did'] : __( 'Not configured', 'controlled-atmosphere' )
				);

				$this->status_row(
					__( 'PDS', 'controlled-atmosphere' ),
					! empty( $settings['pds'] ),
					! empty( $settings['pds'] ) ? $settings['pds'] : __( 'Not resolved', 'controlled-atmosphere' )
				);

				$this->status_row(
					__( 'App password', 'controlled-atmosphere' ),
					'' !== get_app_password(),
					'' !== get_app_password()
						? ( app_password_is_constant() ? __( 'Set via wp-config.php', 'controlled-atmosphere' ) : __( 'Stored', 'controlled-atmosphere' ) )
						: __( 'Not set', 'controlled-atmosphere' )
				);

				$this->status_row(
					__( 'Publication record', 'controlled-atmosphere' ),
					'' !== $uri,
					'' !== $uri ? $uri : __( 'Not created yet', 'controlled-atmosphere' )
				);

				$this->status_row(
					__( 'Verification endpoint', 'controlled-atmosphere' ),
					'' !== $uri,
					esc_url( home_url( Publication::WELL_KNOWN_PATH ) ),
					__( 'Use the button below to check that this is reachable from outside.', 'controlled-atmosphere' )
				);
				?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
			<input type="hidden" name="action" value="controlled_atmosphere_verify" />
			<?php wp_nonce_field( 'controlled_atmosphere_verify' ); ?>
			<?php
			submit_button(
				__( 'Sync publication and verify', 'controlled-atmosphere' ),
				'secondary',
				'submit',
				false
			);
			?>
			<p class="description">
				<?php esc_html_e( 'Writes the publication record to your repo, then fetches your own verification URL over HTTP to confirm it answers correctly.', 'controlled-atmosphere' ); ?>
			</p>
		</form>

		<h2 class="title"><?php esc_html_e( 'Existing posts', 'controlled-atmosphere' ); ?></h2>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Posts published from now on get a record automatically. This creates records for posts that already exist, most recent first. Start small and confirm a card renders before doing more.', 'controlled-atmosphere' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="controlled_atmosphere_backfill" />
			<?php wp_nonce_field( 'controlled_atmosphere_backfill' ); ?>
			<label for="ca-count"><?php esc_html_e( 'Number of recent posts:', 'controlled-atmosphere' ); ?></label>
			<input type="number" name="count" id="ca-count" value="10" min="1" max="50" step="1" style="width:6em" />
			<?php submit_button( __( 'Create records', 'controlled-atmosphere' ), 'secondary', 'submit', false ); ?>
			<p class="description">
				<?php esc_html_e( 'Limited to 50 at a time. Large archives will hit AT Protocol write limits and need the WP-CLI command instead.', 'controlled-atmosphere' ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Renders one status row.
	 *
	 * @param string $label Row label.
	 * @param bool   $ok    Whether the item is satisfied.
	 * @param string $value Current value.
	 * @param string $note  Optional trailing note.
	 */
	private function status_row( string $label, bool $ok, string $value, string $note = '' ): void {
		printf(
			'<tr><td style="width:14em"><strong>%1$s</strong></td><td>%2$s <code>%3$s</code>%4$s</td></tr>',
			esc_html( $label ),
			$ok ? '<span style="color:#00a32a">&#10003;</span>' : '<span style="color:#d63638">&#10007;</span>',
			esc_html( $value ),
			$note ? '<br /><span class="description">' . esc_html( $note ) . '</span>' : ''
		);
	}
}
