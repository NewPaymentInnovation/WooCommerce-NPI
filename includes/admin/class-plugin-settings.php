<?php
/**
 * Plugin settings admin page.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Admin;

use PaymentNetwork\PaymentModule\Plugin_Config;

/**
 * Handles admin settings registration and rendering for the plugin.
 */
class Plugin_Settings {
	/**
	 * Plugin ID.
	 *
	 * @var string
	 */
	private string $plugin_id;

	/**
	 * Plugin title.
	 *
	 * @var string
	 */
	private string $plugin_title;

	/**
	 * Settings group key.
	 *
	 * @var string
	 */
	private string $settings_group;

	/**
	 * Settings error slug.
	 *
	 * @var string
	 */
	private string $error_slug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_id      = Plugin_Config::get_plugin_id();
		$this->plugin_title   = Plugin_Config::get_plugin_title();
		$this->settings_group = $this->plugin_id . '_settings_group';
		$this->error_slug     = $this->plugin_id . '_messages';
	}

	/**
	 * Register admin page and settings init hooks.
	 */
	public function add_options_page() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_menu_settings_init' ) );
	}

	/**
	 * Add the plugin submenu page under WooCommerce.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->plugin_id,
			array( $this, 'plugin_options_page_render' )
		);
	}

	/**
	 * Register plugin settings, section, and fields.
	 */
	public function admin_menu_settings_init() {
		register_setting(
			$this->settings_group,
			$this->plugin_id . '_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'validate_plugin_settings_options' ),
			)
		);

		$section = $this->plugin_id . '_plugin_page_settings_section';

		add_settings_section(
			$section,
			__( 'Module Settings' ),
			null,
			$this->settings_group
		);

		add_settings_field(
			'plugin_enabled',
			__( 'Enable Plugin' ),
			array( $this, 'plugin_enabled_checkbox_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'merchant_id',
			__( 'Merchant ID' ),
			array( $this, 'plugin_option_merchant_id_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'merchant_secret',
			__( 'Merchant Secret' ),
			array( $this, 'plugin_option_merchant_secret_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'country_code',
			__( 'Country Code' ),
			array( $this, 'plugin_option_country_code_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'gateway_hostname',
			__( 'Gateway Hostname' ),
			array( $this, 'plugin_option_gateway_hostname_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'debug_enabled',
			__( 'Debug Enabled' ),
			array( $this, 'debug_enabled_checkbox_field_render' ),
			$this->settings_group,
			$section
		);

		add_settings_field(
			'debug_verbose',
			__( 'Debug Verbose' ),
			array( $this, 'debug_verbose_select_field_render' ),
			$this->settings_group,
			$section
		);
	}

	/**
	 * Validate and sanitize saved plugin settings options.
	 *
	 * @param mixed $data Raw settings data.
	 * @return array
	 */
	public function validate_plugin_settings_options( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$sanitized = array();

		$sanitized['plugin_enabled']  = ! empty( $data['plugin_enabled'] ) ? 'on' : '';
		$sanitized['merchant_id']     = isset( $data['merchant_id'] ) ? sanitize_text_field( $data['merchant_id'] ) : '';
		$sanitized['merchant_secret'] = isset( $data['merchant_secret'] ) ? sanitize_text_field( $data['merchant_secret'] ) : '';
		$sanitized['country_code']    = isset( $data['country_code'] ) ? strtoupper( sanitize_text_field( $data['country_code'] ) ) : '';
		$raw_hostname                 = isset( $data['gateway_hostname'] ) ? sanitize_text_field( $data['gateway_hostname'] ) : '';

		// Strip any scheme or path - accept hostname only.
		$raw_hostname = preg_replace( '#^https?://#i', '', $raw_hostname );
		$raw_hostname = strtolower( rtrim( explode( '/', $raw_hostname )[0] ) );

		if ( ! empty( $raw_hostname ) && ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $raw_hostname ) ) {
			add_settings_error( $this->error_slug, 'gateway_hostname_invalid', __( 'Gateway Hostname must be a valid hostname (e.g. gateway.example.com).' ), 'error' );
			$raw_hostname = '';
		}

		$sanitized['gateway_hostname'] = $raw_hostname;
		$sanitized['debug_enabled']    = ! empty( $data['debug_enabled'] ) ? 'on' : '';

		$allowed_verbose            = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );
		$sanitized['debug_verbose'] = array();

		if ( ! empty( $data['debug_verbose'] ) && is_array( $data['debug_verbose'] ) ) {
			$sanitized['debug_verbose'] = array_values( array_intersect( $data['debug_verbose'], $allowed_verbose ) );
		}

		if ( empty( $sanitized['merchant_id'] ) ) {
			add_settings_error( $this->error_slug, 'merchant_id_required', __( 'Merchant ID is required.' ), 'error' );
		}

		if ( empty( $sanitized['merchant_secret'] ) ) {
			add_settings_error( $this->error_slug, 'merchant_secret_required', __( 'Merchant Secret is required.' ), 'error' );
		}

		if ( ! empty( $sanitized['country_code'] ) && ! preg_match( '/^[0-9]{1,3}$/', $sanitized['country_code'] ) ) {
			add_settings_error( $this->error_slug, 'country_code_invalid', __( 'Country Code must be a valid ISO 3166-1 numeric code.' ), 'error' );
		}

		return $sanitized;
	}

	/**
	 * Plugin enabled checkbox field render.
	 */
	public function plugin_enabled_checkbox_field_render() {
		?>
		<input
			type="checkbox"
			name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[plugin_enabled]"
			value="on"
			<?php checked( Plugin_Config::is_plugin_enabled() ); ?>
		/>

		<p class="description">
			<?php esc_html_e( 'Enable plugin.' ); ?>
		</p>
		<?php
	}

	/**
	 * Merchant ID text field render.
	 */
	public function plugin_option_merchant_id_field_render() {
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[merchant_id]"
			value="<?php echo esc_attr( Plugin_Config::get_merchant_id() ); ?>"
		/>

		<p class="description">
			<?php esc_html_e( 'Enter Merchant ID.' ); ?>
		</p>
		<?php
	}

	/**
	 * Merchant secret password field render.
	 */
	public function plugin_option_merchant_secret_field_render() {
		$input_id = esc_attr( $this->plugin_id ) . '_merchant_secret';
		?>
		<div style="display:inline-flex;align-items:center;gap:4px;">
			<input
				type="password"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[merchant_secret]"
				value="<?php echo esc_attr( Plugin_Config::get_merchant_secret() ); ?>"
			/>

			<button
				type="button"
				class="button button-secondary wp-hide-pw"
				style="align-items:center;justify-content:center;"
				aria-label="<?php esc_attr_e( 'Toggle secret visibility' ); ?>"
				onclick="var i=document.getElementById('<?php echo esc_js( $input_id ); ?>'),s=this.querySelector('.dashicons');if(i.type==='password'){i.type='text';s.classList.replace('dashicons-lock','dashicons-unlock');}else{i.type='password';s.classList.replace('dashicons-unlock','dashicons-lock');}"
			>
				<span class="dashicons dashicons-lock"></span>
			</button>
		</div>

		<p class="description">
			<?php esc_html_e( 'Enter Merchant Secret.' ); ?>
		</p>
		<?php
	}

	/**
	 * Country code text field render.
	 */
	public function plugin_option_country_code_field_render() {
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[country_code]"
			value="<?php echo esc_attr( Plugin_Config::get_country_code() ); ?>"
			maxlength="3"
			style="max-width:80px;"
		/>

		<p class="description">
			<?php esc_html_e( 'ISO 3166-1 numeric country code (e.g. 826 for UK).' ); ?>
		</p>
		<?php
	}

	/**
	 * Gateway hostname text field render.
	 */
	public function plugin_option_gateway_hostname_field_render() {
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[gateway_hostname]"
			value="<?php echo esc_attr( Plugin_Config::get_gateway_host_name() ); ?>"
			style="min-width:300px;"
		/>

		<p class="description">
			<?php esc_html_e( 'Gateway hostname (e.g. gateway.example.com).' ); ?>
		</p>
		<?php
	}

	/**
	 * Debug enabled checkbox field render.
	 */
	public function debug_enabled_checkbox_field_render() {
		?>
		<input
			type="checkbox"
			name="<?php echo esc_attr( $this->plugin_id ); ?>_settings[debug_enabled]"
			value="on"
			<?php checked( Plugin_Config::is_debug_enabled() ); ?>
		/>

		<p class="description">
			<?php esc_html_e( 'Enable debug logging.' ); ?>
		</p>
		<?php
	}

	/**
	 * Debug verbose multiselect field render.
	 */
	public function debug_verbose_select_field_render() {
		$options = array(
			'emergency' => __( 'Emergency' ),
			'alert'     => __( 'Alert' ),
			'critical'  => __( 'Critical' ),
			'error'     => __( 'Error' ),
			'warning'   => __( 'Warning' ),
			'notice'    => __( 'Notice' ),
			'info'      => __( 'Info' ),
			'debug'     => __( 'Debug' ),
		);
		$saved   = Plugin_Config::get_debug_verbose();
		$name    = esc_attr( $this->plugin_id ) . '_settings[debug_verbose][]';
		?>
		<select name="<?php echo esc_attr( $name ); ?>" multiple="multiple" class="widefat" style="max-width:200px;">
			<?php foreach ( $options as $value => $label ) : ?>
				<option
					value="<?php echo esc_attr( $value ); ?>"
					<?php selected( in_array( $value, $saved, true ) ); ?>
				>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<p class="description">
			<?php esc_html_e( 'Select which log levels to record. Hold Ctrl/Cmd to select multiple.' ); ?>
		</p>
		<?php
	}

	/**
	 * Plugin options page render.
	 */
	public function plugin_options_page_render() {
		if ( isset( $_GET['settings-updated'] ) && empty( get_settings_errors( $this->error_slug ) ) ) {
			add_settings_error( $this->error_slug, 'settings_saved', __( 'Settings Saved' ), 'updated' );
		}

		$active_tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$base_url       = admin_url( 'admin.php?page=' . rawurlencode( $this->plugin_id ) );
		$settings_url   = esc_url( $base_url . '&tab=settings' );
		$methods_url    = esc_url( $base_url . '&tab=payment_methods' );
		$plugin_enabled = Plugin_Config::is_plugin_enabled();

		$wcs_active         = class_exists( 'WC_Subscriptions' );
		$wcs_version_ok     = $wcs_active && version_compare( \WC_Subscriptions::$version, '8.0', '>=' );
		$wcs_version_notice = $wcs_active && ! $wcs_version_ok;

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->plugin_title ); ?> - Plugin</h1>

			<?php if ( ! $wcs_active ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'WooCommerce Subscriptions is not active. Subscription payments will not be available.' ); ?></p>
				</div>
			<?php elseif ( $wcs_version_notice ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: installed version number */
							esc_html__( 'WooCommerce Subscriptions 8.0 or higher is required for subscription payments. You are running version %s.' ),
							esc_html( \WC_Subscriptions::$version )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo $settings_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings' ); ?>
				</a>
				<a href="<?php echo $methods_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="nav-tab <?php echo 'payment_methods' === $active_tab ? 'nav-tab-active' : ''; ?> <?php echo ! $plugin_enabled ? 'disabled' : ''; ?>" <?php echo ! $plugin_enabled ? 'aria-disabled="true" style="opacity:.5;pointer-events:none;cursor:default;"' : ''; ?>>
					<?php esc_html_e( 'Payment Methods' ); ?>
				</a>
			</nav>

			<?php if ( 'payment_methods' === $active_tab && $plugin_enabled ) : ?>
				<table class="widefat striped" style="max-width:650px;margin-top:20px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Gateway' ); ?></th>
							<th><?php esc_html_e( 'Status' ); ?></th>
							<th><?php esc_html_e( 'Settings' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$gateways = array(
							'card'       => __( 'Card' ),
							'apple_pay'  => __( 'Apple Pay' ),
							'google_pay' => __( 'Google Pay' ),
							'hosted'     => __( 'Hosted' ),
						);

						foreach ( $gateways as $suffix => $label ) {
							$gateway_id       = $this->plugin_id . '_' . $suffix;
							$url              = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . esc_attr( $gateway_id ) );
							$gateway_settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
							$is_enabled       = is_array( $gateway_settings ) && ! empty( $gateway_settings['enabled'] ) && in_array( $gateway_settings['enabled'], array( 'yes', 'on' ), true );

							printf(
								'<tr><td>%s</td><td>%s</td><td><a class="button button-secondary" href="%s">%s</a></td></tr>',
								esc_html( $label ),
								esc_html( $is_enabled ? __( 'Enabled' ) : __( 'Disabled' ) ),
								esc_url( $url ),
								esc_html__( 'Configure' )
							);
						}
						?>
					</tbody>
				</table>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
						settings_errors( $this->error_slug );
						settings_fields( $this->settings_group );
						do_settings_sections( $this->settings_group );
						submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
