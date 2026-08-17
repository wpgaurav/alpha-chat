<?php
declare(strict_types=1);

namespace AlphaChat\Licensing;

use stdClass;
use WP_Error;

final class LicenseManager {

	private const SERVER_URL   = 'https://gauravtiwari.org/';
	private const ITEM_ID      = 1172914;
	private const OPTION_KEY   = 'alpha_chat_license';
	private const UPDATE_CACHE = 'alpha_chat_update_info';
	private const PAGE_SLUG    = 'alpha-chat-license';
	private const FAILURE_TTL  = HOUR_IN_SECONDS;

	private string $plugin_basename;

	public function __construct() {
		$this->plugin_basename = plugin_basename( ALPHA_CHAT_FILE );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ], 99 );
		add_action( 'admin_init', [ $this, 'handle_action' ] );
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
		add_action( 'delete_site_transient_update_plugins', [ $this, 'clear_update_cache' ] );
		add_filter( 'plugin_action_links_' . $this->plugin_basename, [ $this, 'action_links' ] );
	}

	public function register_page(): void {
		add_submenu_page(
			'alpha-chat',
			__( 'Alpha Chat License', 'alpha-chat' ),
			__( 'License', 'alpha-chat' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function handle_action(): void {
		if ( ! isset( $_POST['alpha_chat_license_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'alpha_chat_license', 'alpha_chat_license_nonce' );
		$action = sanitize_key( wp_unslash( $_POST['alpha_chat_license_action'] ) );

		if ( 'activate' === $action ) {
			$key    = isset( $_POST['license_key'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['license_key'] ) )
				: '';
			$key    = trim( $key );
			$result = $this->activate( $key );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'alpha_chat_license', 'activation_failed', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'alpha_chat_license', 'activated', __( 'License activated. Protected updates are enabled.', 'alpha-chat' ), 'success' );
			}
		}

		if ( 'deactivate' === $action ) {
			$result = $this->deactivate();
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'alpha_chat_license', 'deactivation_failed', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'alpha_chat_license', 'deactivated', __( 'License deactivated on this site.', 'alpha-chat' ), 'success' );
			}
		}
	}

	/** @return array<string, mixed>|WP_Error */
	public function activate( string $key ): array|WP_Error {
		if ( '' === $key ) {
			return new WP_Error( 'alpha_chat_license_key', __( 'Enter the license key from your FluentCart account.', 'alpha-chat' ) );
		}

		$response = $this->request(
			'activate_license',
			[
				'license_key' => $key,
				'item_id'     => self::ITEM_ID,
				'site_url'    => home_url(),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! in_array( $status, [ 'valid', 'active' ], true ) ) {
			return new WP_Error(
				'alpha_chat_license_rejected',
				sanitize_text_field( (string) ( $response['message'] ?? __( 'FluentCart did not accept this license.', 'alpha-chat' ) ) )
			);
		}

		$license = [
			'license_key'     => $key,
			'status'          => 'valid',
			'activation_hash' => sanitize_text_field( (string) ( $response['activation_hash'] ?? '' ) ),
			'expiration_date' => sanitize_text_field( (string) ( $response['expiration_date'] ?? 'lifetime' ) ),
			'activated_at'    => current_time( 'mysql' ),
		];
		update_option( self::OPTION_KEY, $license, false );
		$this->clear_update_cache();
		return $license;
	}

	/** @return array<string, mixed>|WP_Error */
	public function deactivate(): array|WP_Error {
		$license = $this->license();
		if ( '' === $license['license_key'] ) {
			return new WP_Error( 'alpha_chat_no_license', __( 'No saved license key is available.', 'alpha-chat' ) );
		}

		$response = $this->request(
			'deactivate_license',
			[
				'license_key' => $license['license_key'],
				'item_id'     => self::ITEM_ID,
				'site_url'    => home_url(),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! in_array( $status, [ 'deactivated', 'inactive' ], true ) && empty( $response['success'] ) ) {
			return new WP_Error( 'alpha_chat_deactivation_rejected', __( 'FluentCart did not confirm deactivation.', 'alpha-chat' ) );
		}

		$empty = $this->defaults();
		update_option( self::OPTION_KEY, $empty, false );
		$this->clear_update_cache();
		return $empty;
	}

	public function check_for_update( mixed $transient ): stdClass {
		if ( ! $transient instanceof stdClass ) {
			$transient = new stdClass();
		}
		$license = $this->license();
		if ( 'valid' !== $license['status'] || '' === $license['license_key'] ) {
			return $transient;
		}

		$update = get_transient( self::UPDATE_CACHE );
		if ( false === $update ) {
			$params = [
				'item_id'  => self::ITEM_ID,
				'site_url' => home_url(),
			];
			if ( '' !== $license['activation_hash'] ) {
				$params['activation_hash'] = $license['activation_hash'];
			} else {
				$params['license_key'] = $license['license_key'];
			}
			$update = $this->request( 'get_license_version', $params );

			if ( is_wp_error( $update ) ) {
				// Cache the failure too. This filter runs several times per admin
				// page load, and without a negative cache an unreachable licence
				// server means a fresh 20-second blocking request every time.
				set_transient( self::UPDATE_CACHE, [ 'failed' => true ], self::FAILURE_TTL );
			} else {
				set_transient( self::UPDATE_CACHE, $update, 12 * HOUR_IN_SECONDS );
			}
		}

		if ( is_wp_error( $update ) || ! is_array( $update ) || ! empty( $update['failed'] ) || empty( $update['new_version'] ) ) {
			return $transient;
		}
		if ( ! version_compare( (string) $update['new_version'], ALPHA_CHAT_VERSION, '>' ) ) {
			// Declaring "no update" is what puts the plugin in the auto-update UI.
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'id'          => $this->plugin_basename,
				'slug'        => 'alpha-chat',
				'plugin'      => $this->plugin_basename,
				'new_version' => ALPHA_CHAT_VERSION,
				'url'         => 'https://gauravtiwari.org/product/alpha-chat/',
				'package'     => '',
			];

			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$transient->response[ $this->plugin_basename ] = (object) [
			'id'           => $this->plugin_basename,
			'slug'         => 'alpha-chat',
			'plugin'       => $this->plugin_basename,
			'new_version'  => sanitize_text_field( (string) $update['new_version'] ),
			'url'          => esc_url_raw( (string) ( $update['url'] ?? 'https://gauravtiwari.org/product/alpha-chat/' ) ),
			'package'      => esc_url_raw( (string) ( $update['package'] ?? '' ) ),
			'icons'        => is_array( $update['icons'] ?? null ) ? $update['icons'] : [],
			'banners'      => is_array( $update['banners'] ?? null ) ? $update['banners'] : [],
			'tested'       => sanitize_text_field( (string) ( $update['tested'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $update['requires_php'] ?? ALPHA_CHAT_MIN_PHP ) ),
		];
		return $transient;
	}

	public function plugin_information( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || 'alpha-chat' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$update = get_transient( self::UPDATE_CACHE );
		if ( ! is_array( $update ) || ! empty( $update['failed'] ) ) {
			return $result;
		}
		return (object) [
			'name'          => 'Alpha Chat',
			'slug'          => 'alpha-chat',
			'version'       => sanitize_text_field( (string) ( $update['new_version'] ?? '' ) ),
			'author'        => '<a href="https://gauravtiwari.org">Gaurav Tiwari</a>',
			'homepage'      => 'https://gauravtiwari.org/product/alpha-chat/',
			'download_link' => esc_url_raw( (string) ( $update['package'] ?? '' ) ),
			'sections'      => is_array( $update['sections'] ?? null ) ? $update['sections'] : [],
			'banners'       => is_array( $update['banners'] ?? null ) ? $update['banners'] : [],
			'icons'         => is_array( $update['icons'] ?? null ) ? $update['icons'] : [],
			'requires'      => ALPHA_CHAT_MIN_WP,
			'requires_php'  => ALPHA_CHAT_MIN_PHP,
			'tested'        => sanitize_text_field( (string) ( $update['tested'] ?? '' ) ),
		];
	}

	public function clear_update_cache(): void {
		delete_transient( self::UPDATE_CACHE );
		delete_site_transient( 'update_plugins' );
	}

	/** @param array<int|string, string> $links
	 *  @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'License', 'alpha-chat' )
			)
		);
		return $links;
	}

	public function render_page(): void {
		$license = $this->license();
		settings_errors( 'alpha_chat_license' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Alpha Chat License', 'alpha-chat' ); ?></h1>
			<div class="card" style="max-width:620px">
				<?php if ( 'valid' === $license['status'] ) : ?>
					<h2><?php esc_html_e( 'License active', 'alpha-chat' ); ?></h2>
					<p><?php esc_html_e( 'Protected WordPress updates are enabled for this site.', 'alpha-chat' ); ?></p>
					<p><code><?php echo esc_html( $this->mask( $license['license_key'] ) ); ?></code></p>
					<form method="post">
						<?php wp_nonce_field( 'alpha_chat_license', 'alpha_chat_license_nonce' ); ?>
						<input type="hidden" name="alpha_chat_license_action" value="deactivate">
						<?php submit_button( __( 'Deactivate License', 'alpha-chat' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<h2><?php esc_html_e( 'Enable protected updates', 'alpha-chat' ); ?></h2>
					<p><?php esc_html_e( 'Use the free lifetime key from your FluentCart receipt or account.', 'alpha-chat' ); ?></p>
					<form method="post">
						<?php wp_nonce_field( 'alpha_chat_license', 'alpha_chat_license_nonce' ); ?>
						<input type="hidden" name="alpha_chat_license_action" value="activate">
						<p><label for="alpha-chat-license-key"><strong><?php esc_html_e( 'License key', 'alpha-chat' ); ?></strong></label></p>
						<p><input id="alpha-chat-license-key" class="regular-text" type="password" name="license_key" autocomplete="off" required></p>
						<?php submit_button( __( 'Activate License', 'alpha-chat' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** @return array{license_key:string,status:string,activation_hash:string,expiration_date:string,activated_at:string} */
	private function license(): array {
		$value = get_option( self::OPTION_KEY, [] );
		$value = is_array( $value ) ? $value : [];
		return [
			'license_key'     => sanitize_text_field( (string) ( $value['license_key'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $value['status'] ?? 'inactive' ) ),
			'activation_hash' => sanitize_text_field( (string) ( $value['activation_hash'] ?? '' ) ),
			'expiration_date' => sanitize_text_field( (string) ( $value['expiration_date'] ?? '' ) ),
			'activated_at'    => sanitize_text_field( (string) ( $value['activated_at'] ?? '' ) ),
		];
	}

	/** @return array{license_key:string,status:string,activation_hash:string,expiration_date:string,activated_at:string} */
	private function defaults(): array {
		return [
			'license_key'     => '',
			'status'          => 'inactive',
			'activation_hash' => '',
			'expiration_date' => '',
			'activated_at'    => '',
		];
	}

	/** @param array<string, int|string> $parameters
	 *  @return array<string, mixed>|WP_Error
	 */
	private function request( string $action, array $parameters ): array|WP_Error {
		$parameters['current_version']  = ALPHA_CHAT_VERSION;
		$parameters['platform_version'] = get_bloginfo( 'version' );
		$parameters['server_version']   = PHP_VERSION;
		$response                       = wp_remote_post(
			add_query_arg( 'fluent-cart', sanitize_key( $action ), self::SERVER_URL ),
			[
				'timeout'     => 20,
				'redirection' => 2,
				'sslverify'   => true,
				'body'        => $parameters,
				'headers'     => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'alpha_chat_license_connection', __( 'Alpha Chat could not reach the license server.', 'alpha-chat' ) );
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'alpha_chat_license_response', __( 'The license server returned an invalid response.', 'alpha-chat' ) );
		}
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = array_merge( $body, $body['data'] );
		}
		if ( isset( $body['success'] ) && false === $body['success'] ) {
			$message = sanitize_text_field( (string) ( $body['message'] ?? '' ) );
			return new WP_Error(
				'alpha_chat_license_rejected',
				'' !== $message ? $message : __( 'The license server rejected the request.', 'alpha-chat' )
			);
		}
		return $body;
	}

	private function mask( string $key ): string {
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
	}
}
