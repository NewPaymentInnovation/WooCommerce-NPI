<?php

define('ALLOW_UNFILTERED_UPLOADS', true);

use P3\SDK\Gateway;

/**
 * Gateway class
 */
class WC_Payment_Network_ApplePay extends WC_Payment_Gateway
{
	/**
	 * @var string
	 */
	public $lang;

	/**
	 * @var Gateway
	 */
	protected $gateway;

	/**
	 * The default merchant ID that will be used
	 * when processing a request on the gateway.
	 *
	 * @var string
	 */
	public $defaultMerchantID;

	/**
	 * The default mercant signature.
	 *
	 * @var string
	 */
	public $defaultMerchantSignature;

	/**
	 * The gateway URL
	 *
	 * @var string
	 */
	public $defaultGatewayURL;

	/**
	 * Key used to generate the nonce for AJAX calls.
	 * @var string
	 */
	protected $nonce_key;

	/**
	 * Url of plugin
	 * @var string
	 */
	protected $pluginURL;

	public function __construct()
	{
		// Include the module config file.
		$configs = include dirname(__FILE__) . '/../config.php';
		$this->pluginURL = plugins_url('/', dirname(__FILE__));

		$title = strtolower($configs['default']['gateway_title']);

		$this->has_fields = true;
		$this->id = preg_replace("/[^A-Za-z0-9_.\/]/", "", $title) . '_applepay';
		$this->lang = 'woocommerce_' . $this->id;
		// $this->icon                       = plugins_url('/', dirname(__FILE__)) . 'assets/img/logo.png';
		$this->method_title = __($configs['default']['gateway_title'], $this->lang);
		$this->method_description = __($configs['applepay']['method_description'], $this->lang);

		// Get main modules settings to use in this sub module.
		$mainModuleID = str_replace("_applepay", "", $this->id);
		$mainModuleSettings = get_option('woocommerce_' . $mainModuleID . '_settings');

		$this->defaultGatewayURL = $mainModuleSettings['gatewayURL'];
		$this->defaultMerchantID = $mainModuleSettings['merchantID'];
		$this->defaultMerchantSignature = $mainModuleSettings['signature'];

		$this->supports = array(
			'subscriptions',
			'products',
			'refunds',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_admin',
		);
		$this->nonce_key = '12d4c8031f852b9c';

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->settings['title'];

		// Register hooks.
		add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'process_scheduled_subscription_payment_callback'), 10, 3);
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		// Enqueue Apple Pay script when main site.
		if (is_checkout() || is_cart()) {
			add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
		}
		// Enqueue Admin scripts when in plugin settings.
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

		if ($mainModuleSettings['enabled'] == "no") {
			$this->enabled = "no";
		}
	}

	/**
	 * Generate CSR and private key
	 * ----------------------------
	 *
	 * This will generate a CSR and privat key
	 * then return it as a JSON response. It
	 * is used by the certificate setup help
	 * window to aid generating required files.
	 *
	 * @return JSON
	 */
	public function generate_csr_and_key()
	{
		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		$keyPassword = $_POST['keypassword'];

		$csrSettings = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'encrypt_key' => true);
		// Generate a new private (and public) key pair
		$privkey = openssl_pkey_new($csrSettings);

		// Generate a certificate signing request
		$csr = openssl_csr_new(array(), $privkey, $csrSettings);

		// Export key
		openssl_csr_export($csr, $csrout);
		openssl_pkey_export($privkey, $pkeyout, $keyPassword);

		$JSONResponse = array(
			'status' => true,
			'csr_file' => $csrout,
			'key_file' => $pkeyout,
		);

		wp_send_json_success($JSONResponse);

		wp_die(); // Ensure nothing further is processed.
	}

	/**
	 * Enqueue Admin Scripts
	 *
	 * Enqueues the Javascript needed for the
	 * plugin settings page
	 */
	public function enqueue_admin_scripts()
	{
		$optionPrefix = "woocommerce_{$this->id}_";
		$certificateData = get_option($optionPrefix . 'merchantCert');
		$certificatekeyData = get_option($optionPrefix . 'merchantCertKey');

		wp_register_style('custom_wp_admin_css', $this->pluginURL . '/assets/css/pluginadmin.css');
		wp_enqueue_style('custom_wp_admin_css');
		wp_register_script('applepay_admin_script', $this->pluginURL . '/assets/js/applepayadmin.js');
		wp_enqueue_script('applepay_admin_script');
		wp_localize_script('applepay_admin_script', 'localizeVars', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'securitycode' => wp_create_nonce($this->nonce_key),
			'certificateAndKeyExist' => (!empty($certificateData) || !empty($certificatekeyData)),
		));
	}

	/**
	 * Initialise Form fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', $this->lang),
				'label' => __('Enable Apple Pay', $this->lang),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no',
			),
			'title' => array(
				'title' => __('Title', $this->lang),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', $this->lang),
				'default' => __('Apple pay', $this->lang),
			),
			'merchant_identifier' => array(
				'title' => __('Merchant identifier', $this->lang),
				'type' => 'text',
				'description' => __('The Apple Pay merchant identifier.', $this->lang),
				'custom_attributes' => array(
					'required' => true,
				),
			),
			'merchant_domain_name' => array(
				'title' => __('Merchant domain', $this->lang),
				'type' => 'text',
				'description' => __('The Apple Pay merchant domain.', $this->lang),
				'custom_attributes' => array(
					'required' => true,
				),
			),
			'merchant_display_name' => array(
				'title' => __('Merchant display name.', $this->lang),
				'type' => 'text',
				'description' => __('The Apple Pay merchant display name.', $this->lang),
				'custom_attributes' => array(
					'required' => true,
				),
			),
			'merchant_cert_key_password' => array(
				'title' => __('Merchant certificate key password', $this->lang),
				'type' => 'password',
				'description' => __('The Apple Pay merchant identifier.', $this->lang),
				'custom_attributes' => array(
					'required' => true,
				),
			),
		);
	}

	/**
	 * Admin Options
	 * -------------
	 *
	 * Initialise admin options for plugin settings which
	 * includes checking setups is valid.
	 * Outputs the admin UI
	 */
	public function admin_options()
	{
		$optionPrefix = "woocommerce_{$this->id}_";

		// For each setting with an empty value output it's required.
		// This is incase a theme stops a required field.
		foreach ($_POST as $key => $value) {
			if (empty($value) && strpos($key, $optionPrefix) === 0) {
				echo "<label id=\"save-field-error\">Field {$key} required is empty</label>";
			}
		}

		// The key password is stored in settings.
		$currentSavedKeyPassword = $this->settings['merchant_cert_key_password'];

		$certificateSaveResultHTML = '';
		$certificateSetupStatus = '';

		// Check for files to store. If no files to store then check current saved files.
		if (!empty($_FILES['merchantCertFile']['tmp_name']) || !empty($_FILES['merchantCertKey']['tmp_name'])) {

			$certificateSaveResult = $this->store_merchant_certificates($_FILES, $currentSavedKeyPassword);
			$certificateSaveResultHTML = ($certificateSaveResult['saved'] ?
				"<div id=\"certs-saved-container\" class=\"cert-saved\"><label id=\"certificate-saved-label\">Certificates saved</label></div>" :
				"<div id=\"certs-saved-container\" class=\"cert-saved-error\"><label id=\"certificate-saved-error-label\">Certificates save error: {$certificateSaveResult['error']}</label></div>");
		}

		// Check if Apple pay certificates have been saved and valid.
		$currentSavedCertData = get_option($optionPrefix . 'merchantCert');
		$currentSavedCertKey = get_option($optionPrefix . 'merchantCertKey');
		$certificateSetupStatus = (openssl_x509_check_private_key($currentSavedCertData, array($currentSavedCertKey, $currentSavedKeyPassword)) ?
		'<label class="cert-message cert-message-valid">Certificate, key and password saved are all valid</label>' :
		'<label class="cert-message cert-validation-error">Certificate, key and password are not valid or saved</label>');

		// Plugin settings field HTML.
		$pluginSettingFieldsHTML = '<table class="form-table">' . $this->generate_settings_html(null, false) . '</table>';

		$adminPageHTML = <<<HTML
		{$certificateSaveResultHTML}
		<h1>{$this->method_title} - Apple Pay settings</h1>
		{$pluginSettingFieldsHTML}
		<hr>
		<h1 id="apple-pay-merchant-cert-setup-header">Apple Pay merchant certificate setup</h1>
		<p><label>Current certificate setup status: </label>{$certificateSetupStatus}</p>
		<div>
		<div id="upload-cert-message">Upload new certificate and key  <img id="upload-cert-help-icon" src="{$this->pluginURL}/assets/img/help-icon.png" alt="CSR file download"></div>
		<div id="apple-pay-cert-key-upload-container">
		<div id ="merchant-cert-upload-label">Merchant certificate file upload</div>
		<input type="file" id="merchantCertUpload" name="merchantCertFile"/>
		<div id ="merchant-cert-upload-label">Merchant certificate key</div>
		<input type="file" id="merchantCertKeyUpload" name="merchantCertKey"/>
		</div>
		<div id="certificate-help-window">
		<img id="close-help-window-icon" class="close-help-window-icon" src="{$this->pluginURL}/assets/img/close-window-icon.png" alt="Close help window">
		<h2 style="text-decoration: underline;">Apple Pay merchant identity certificate</h2>
		<p>To obtain an Apple Pay <em>merchant identity</em> you must have enrolled in the
		<a href="https://developer.apple.com/programs/" target="_blank" rel=" noopener noreferrer nofollow" data-disabled="">Apple Developer Program</a>
		 and <a href="https://help.apple.com/developer-account/#/devb2e62b839?sub=dev103e030bb" target="_blank" rel=" noopener noreferrer nofollow">
		created a unique Apple Pay merchant identifier</a>.</p>
		<p>The merchant identity is associated with your merchant identifier and used to identify the merchant in SSL communications.
		The certificate expires every 25 months. If the certificate is revoked, you can recreate it. You will also need to setup a payment processing certificates
		with the payment gateway before the Apple Pay button is fully functional.</p>
		<p><b>You must generate your own CSR when creating a <em>merchant identity certificate</em> for the payment module.
		<a href="https://help.apple.com/developer-account/#/devbfa00fef7" target="_blank" rel=" noopener noreferrer nofollow"></a>.</b></p>
		<ol>
			<li><p>Open the <a href="https://developer.apple.com/account/resources" target="_blank" rel=" noopener noreferrer nofollow" data-disabled="">Apple Developer Certificates, Identifiers &amp; Profiles</a> webpage and select 'Identifiers' from the sidebar.</p></li>
			<li><p>Under 'Identifiers', select 'Merchant IDs' using the filter in the top-right.</p></li>
			<li><p>On the right, select your merchant identifier.</p></li>
			<li><p>Under 'Apple Pay Merchant Identity Certificate', click 'Create Certificate'.</p></li>
			<li><p>Use a CSR you have generated to upload. If you do not have a CSR then click the button below to generate one.</p></li>
			<li><p>Click 'Choose File' and select the CSR you just downloaded.</p></li>
			<li><p>Click 'Continue'.</p></li>
			<li><p>Click 'Download' to download the <em>merchant identity certificate</em> and save to a file.</p></li>
			<li><p>Along with the key file generated with the CSR, upload the CER file download from Apple Pay</p></li>
			<li><p>Update the password in the settings</p></li>
			<li><p>Click the save button.</p></li>
		</ol>
		<button class="merchant-cert-gen-button" type="button" id="merchant-cert-gen-button">Generate CSR and key</button>
		<br>
		<div id="generated-certs-container">
		<label>Files ready to download.</label>
			<div id="downloadable-cert-and-key-container">
				<div id="csrdownloadicon">
						<a id="csrdownloadhref" href="link">
						<img src="{$this->pluginURL}/assets/img/certification-icon.png" alt="CSR file download">
						<br>
						<label>CSR Certificate file</label>
						</a>
					</div>
					<div id="keydownloadicon">
						<a id="keydownloadhref" href="link">
						<img src="{$this->pluginURL}/assets/img/certification-icon.png" alt="Key file download">
						<br>
						<label>Certificate key file</label>
						</a>
					</div>
				</div>
			</div>
		</div>
HTML;

		echo $adminPageHTML;
	}

	/**
	 * Stores the merchant certificates ass options in the settings database.
	 */
	public function store_merchant_certificates($files, $keyPassword)
	{
		// Check if admin
		if (!is_admin()) {
			wp_die();
		}

		$optionPrefix = "woocommerce_{$this->id}_";

		// Check files are present. Return file missing
		if ($files['merchantCertKey']['size'] == 0 || $files['merchantCertFile']['size'] == 0) {
			$fileMissing = (($files['merchantCertFile']['size'] > 0) ? 'private key' : 'certificate');
			$response['saved'] = false;
			$response['error'] = "Missing {$fileMissing}";
			return $response;
		}

		// Get the file contents.
		$merchantCert = file_get_contents($files['merchantCertFile']['tmp_name']);
		$merchantCertKey = file_get_contents($files['merchantCertKey']['tmp_name']);

		// If merchantCertFile is .cer convert it to pem.
		if ($files['merchantCertFile']['type'] === 'application/pkix-cert' || $files['merchantCertFile']['type'] === 'application/x-x509-ca-cert') {
			$merchantCert = '-----BEGIN CERTIFICATE-----' . PHP_EOL
				. chunk_split(base64_encode($merchantCert), 64, PHP_EOL)
				. '-----END CERTIFICATE-----' . PHP_EOL;
		}

		// Check the files are valid.
		$certRexEx = '/-{3,}BEGIN CERTIFICATE-{3,}.*?^-{3,}END CERTIFICATE-{3,}/ms';
		$keyRegEx = '/-{3,}BEGIN ENCRYPTED PRIVATE KEY-{3,}.*?^-{3,}END ENCRYPTED PRIVATE KEY-{3,}/ms';

		if (!preg_match($certRexEx, $merchantCert) || !preg_match($keyRegEx, $merchantCertKey)) {
			$response['saved'] = false;
			$response['error'] = "Certificate and/or key are invalid. No files saved.";
			return $response;
		}

		// Check private key matches certificate.
		if (!openssl_x509_check_private_key($merchantCert, array($merchantCertKey, $keyPassword))) {
			$response['saved'] = false;
			$response['error'] = 'Certificate, key and password do not match. Try retyping the password along with saving the files';
			return $response;
		} else {

			// If the certificates are already stored then update them. Else add them.
			if (get_option($optionPrefix . 'merchantCert') || get_option($optionPrefix . 'merchantCertKey')) {
				update_option($optionPrefix . 'merchantCert', $merchantCert);
				update_option($optionPrefix . 'merchantCertKey', $merchantCertKey);
				$response['saved'] = true;
				$response['message'] = 'Previous certificate and key overwritten';
			} else {
				add_option($optionPrefix . 'merchantCert', $merchantCert);
				add_option($optionPrefix . 'merchantCertKey', $merchantCertKey);
				$response['saved'] = true;
				$response['message'] = 'Certificate and key have been saved';
			}

			return $response;
		}
	}

	/**
	 * Validate ApplePay merchant
	 * --------------------------
	 *
	 * This function is called by the actions wp_ajax_nopriv_process_applepay
	 * and wp_ajax_validate_process_applepay. It will validate the merchant
	 */
	public function validate_applepay_merchant()
	{
		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		$validation_url = $_POST['validationURL'];

		// if no validation URL
		if (!$validation_url) {
			wp_send_json_error();
			wp_die();
		}

		$optionPrefix = "woocommerce_{$this->id}_";
		$certificateData = get_option($optionPrefix . 'merchantCert');
		$certificatekeyData = get_option($optionPrefix . 'merchantCertKey');
		$certficiateKeyPassword = $this->settings['merchant_cert_key_password'];
		$apwMerchantIdentifier = $this->settings['merchant_identifier'];
		$apwDisplayName = $this->settings['merchant_display_name'];
		$apwDomainName = $this->settings['merchant_domain_name'];

		// First check all settings required are present as well as the certificate and key.
		if (
			!isset($apwMerchantIdentifier) &&
			!isset($apwDomainName) &&
			!isset($apwDisplayName) &&
			!isset($certificateData) &&
			!isset($certificatekeyData) &&
			!isset($certficiateKeyPassword)
		) {
			wp_send_json_error();
			wp_die();
		}

		// Prepare merchant certificate and key file for CURL

		// Merchant certificate.
		$tempCertFile = tmpfile();
		fwrite($tempCertFile, $certificateData);
		$tempCertFilePath = stream_get_meta_data($tempCertFile);
		$tempCertFilePath = $tempCertFilePath['uri'];

		// Merchant certificate key.
		$tempCertKeyFile = tmpfile();
		fwrite($tempCertKeyFile, $certificatekeyData);
		$tempCertFileKeyPath = stream_get_meta_data($tempCertKeyFile);
		$tempCertFileKeyPath = $tempCertFileKeyPath['uri'];

		if (($tmp = parse_url($validation_url)) && $tmp['scheme'] === 'https' && substr($tmp['host'], -10) === '.apple.com') {

			$data = '{"merchantIdentifier":"' . $apwMerchantIdentifier . '", "domainName":"' . $apwDomainName . '", "displayName":"' . $apwDisplayName . '"}';

			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $validation_url);
			curl_setopt($ch, CURLOPT_SSLCERT, $tempCertFilePath);
			curl_setopt($ch, CURLOPT_SSLKEY, $tempCertFileKeyPath);
			curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $certficiateKeyPassword);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$response = curl_exec($ch);
			//Clean up
			curl_close($ch);
			fclose($tempCertFile);
			fclose($tempCertKeyFile);

			if ($response === false) {
				error_log('[ApplePay - Merchant validate][' . time() . '] - Curl failed ' . print_r(curl_error($ch), true));
				wp_send_json_error();
			} else {
				wp_send_json_success($response);
			}
		} else {
			error_log('URL should be SSL or contain apple.com. URL was: ' . $validation_url);
			wp_send_json_error();
		}
	}

	/**
	 * Process Apple Pay Payment
	 * -------------------------
	 *
	 * This function will process the token retrieved from the checkout
	 * when using Apple Pay. It is called by the ApplePay javascript.
	 *
	 * It will use the contact/address information from the token to create
	 * an order then process the token with the gateway and finally process
	 * the order.
	 *
	 */
	public function process_applepay_payment()
	{
		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}
		// Strip slashes added by WP from the JSON data.
		$paymentData = stripslashes_deep($_POST['payment']);
		// Decode the JSON payment token data.
		$paymentData = json_decode($paymentData);

		if (isset($_POST['orderID']) && $_POST['orderID'] != "false") {

			$order = wc_get_order(wc_get_order_id_by_order_key($_POST['orderID']));

			$order->set_billing_first_name($paymentData->billingContact->givenName);
			$order->set_billing_last_name($paymentData->billingContact->familyName);
			$order->set_billing_address_1($paymentData->billingContact->addressLines[0]);
			$order->set_billing_address_1($paymentData->billingContact->addressLines[1]);
			$order->set_billing_city($paymentData->billingContact->locality);
			$order->set_billing_state($paymentData->billingContact->administrativeArea);
			$order->set_billing_postcode($paymentData->billingContact->postalCode);
		} else {

			$woocommerceOrderRequest = array(
				'paymentMethod' => $this->id,
				'billingEmail' => $paymentData->shippingContact->emailAddress,
				// Billing address details
				'billingAddress' => array(
					'first_name' => $paymentData->billingContact->givenName,
					'last_name' => $paymentData->billingContact->familyName,
					'email' => $paymentData->billingContact->emailAddress,
					'phone' => $paymentData->billingContact->phoneNumber,
					'address_1' => $paymentData->billingContact->addressLines[0],
					'address_2' => $paymentData->billingContact->addressLines[1],
					'city' => $paymentData->billingContact->locality,
					'state' => $paymentData->billingContact->administrativeArea,
					'postcode' => $paymentData->billingContact->postalCode,
					'country' => $paymentData->billingContact->country,
				),
				// Shipping address details
				'shippingAddress' => array(
					'first_name' => $paymentData->shippingContact->givenName,
					'last_name' => $paymentData->shippingContact->familyName,
					'email' => $paymentData->shippingContact->emailAddress,
					'phone' => $paymentData->shippingContact->phoneNumber,
					'address_1' => $paymentData->shippingContact->addressLines[0],
					'address_2' => $paymentData->shippingContact->addressLines[1],
					'city' => $paymentData->shippingContact->locality,
					'state' => $paymentData->shippingContact->administrativeArea,
					'postcode' => $paymentData->shippingContact->postalCode,
					'country' => $paymentData->shippingContact->country,
					'country_code' => $paymentData->shippingContact->countryCode,
				),
			);

			// Try and create the order
			try {
				$order = $this->create_order($woocommerceOrderRequest);
			} catch (\Exception $exception) {
				return false;
			}
		}

		$gatewayTransactionRequest = array(
			'merchantID' => $this->defaultMerchantID,
			'action' => 'SALE',
			'amount' => $order->calculate_totals(),
			'countryCode' => wc_get_base_location()['country'],
			'currencyCode' => $order->get_currency(),
			'transactionUnique' => uniqid($order->get_order_key() . "-"),
			'type' => '1',
			'paymentMethod' => 'applepay',
			'merchantData' => 'WC_APPLEPAY',
			'paymentToken' => $paymentData->token->paymentData,
			'customerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customerAddress' => $order->get_billing_address_1() . '\n' . $order->get_billing_address_2(),
			'customerCounty' => $order->get_billing_state(),
			'customerTown' => $order->get_billing_city(),
			'customerPostCode' => $order->get_billing_postcode(),
			'customerEmail' => $order->get_billing_email(),
		);

		$gatewayRequestResult = $this->sendGatewayRequest($gatewayTransactionRequest);

		// Add gateway respone to order meta data
		$order->update_meta_data('gatewayResponse', $gatewayRequestResult);
		$order->save();

		$JSONResponse['paymentComplete'] = false;

		// Clear shipping selection for backward compatibility.
		WC()->session->__unset('chosen_shipping_methods');

		if (!isset($gatewayRequestResult['responseCode']) || (int)$gatewayRequestResult['responseCode'] !== 0) {

			$this->on_order_fail($order);
		} else if ((int)$gatewayRequestResult['responseCode'] === 0) {

			$this->on_order_success($order);
			$JSONResponse['paymentComplete'] = true;
		} else {
			$this->on_order_fail($order);
		}

		$JSONResponse['redirect'] = $this->get_return_url($order);
		$JSONResponse['message'] = ($JSONResponse['paymentComplete'] ? 'Approved' : 'Declined');

		wp_send_json_success($JSONResponse);
	}

	/**
	 * Create Order
	 * ------------
	 *
	 * Creates a woocommerce order from the $data passed.
	 *
	 * Example WC Order
	 * ----------------
	 *
	 * [
	 *  'paymentMethod' => '',
	 *  'billingEmail' => '',
	 *  'billingAddress => array(
	 *           'first_name' => 'John',
	 *           'last_name'  => 'Doe',
	 *           'company'    => 'JDLTD',
	 *           'email'      => 'example@domainnamehere.com',
	 *           'phone'      => '01899 999888',
	 *           'address_1'  => '16 Test street',
	 *           'address_2'  => '',
	 *           'city'       => 'TCity',
	 *           'state'      => 'London',
	 *           'postcode'   => 'E12 LTD',
	 *           'country'    => 'UK'
	 *  ),
	 *  'shippingAddress => array(
	 *           'first_name' => 'John',
	 *           'last_name'  => 'Doe',
	 *           'company'    => 'JDLTD',
	 *           'email'      => 'example@domainnamehere.com',
	 *           'phone'      => '01899 999888',
	 *           'address_1'  => '16 Test street',
	 *           'address_2'  => '',
	 *           'city'       => 'TCity',
	 *           'state'      => 'London',
	 *           'postcode'   => 'E12 LTD',
	 *           'country'    => 'UK'
	 *  )
	 * )
	 *
	 * @param  Array            $data
	 * @return Array|bool	    $order
	 */
	private function create_order($data)
	{
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		$checkout = WC()->checkout();

		$order_id = $checkout->create_order(array(
			'payment_method' => $data['paymentMethod'],
			'billing_email' => $data['billingEmail'],
		));

		// Get the chosen shipping method from the session data.
		$shippingMethodSelected = WC()->session->get('chosen_shipping_methods')[0];

		$order = wc_get_order($order_id);

		// Retrieve the customer shipping zone
		$shippingZones = WC_Shipping_Zones::get_zones();
		$shippingMethodID = explode(':', $shippingMethodSelected);
		$shippingMethodIndentifier = $shippingMethodID[0];
		$shippingMethodInstanceID = $shippingMethodID[1];

		// For each shipping method in zone locations in shipping zones, find the one
		// selected on the Apple pay window by the user.
		foreach ($shippingZones as $zone) {
			foreach ($zone['zone_locations'] as $zonelocation) {
				if ($zonelocation->code === $data['shippingAddress']['country_code']) {
					foreach ($zone['shipping_methods'] as $shippingMethod) {
						if (
							$shippingMethod->id == $shippingMethodIndentifier &&
							$shippingMethod->instance_id == $shippingMethodInstanceID
						) {
							$item = new WC_Order_Item_Shipping();
							$item->set_method_title($shippingMethod->title);
							$item->set_method_id($shippingMethod->id);
							$item->set_instance_id($shippingMethod->instance_id);
							$item->set_total($shippingMethod->cost ? $shippingMethod->cost : 0);
							$order->add_item($item);
							// Shipping method found and set. Break out of all loops.
							break 3;
						}
					}
				}
			}
		}

		$order->calculate_totals();
		$order->set_address($data['billingAddress'], 'billing');
		$order->set_address((isset($data['shippingAddress']) ? $data['shippingAddress'] : $data['billingAddress']), 'shipping');

		// Return the created order or false
		if ($order) {
			return $order;
		}
		return false;
	}

	/**
	 * Send Gateway Request
	 * --------------------
	 *
	 * This method will send a gateway request to the gateway
	 * and return the fields needed to process the order.
	 *
	 *  Example
	 *  -------
	 *  $gatewayTransactionRequest = [
	 *       'action' => 'SALE',
	 *       'amount' => '299',
	 *       'countryCode' => '826',
	 *       'currencyCode' => '826',
	 *       'transactionUnique' => 'APPLEPAYTESTING' . uniqid(),
	 *       'type' => '1',
	 *       'paymentMethod' => 'applepay',
	 *       'paymentToken' =>  json_encode($paymentData->token->paymentData)
	 *   ];
	 *
	 *  @param Array        $gatewayTransactionRequest
	 *  @return Array       $gatewayResponse | false
	 */
	private function sendGatewayRequest($gatewayTransactionRequest)
	{
		$gateway = new Gateway(
			$this->defaultMerchantID,
			$this->defaultMerchantSignature,
			$this->defaultGatewayURL
		);

		$response = $gateway->directRequest($gatewayTransactionRequest);

		$gatewayResponse = array(
			'responseCode' => $response['responseCode'],
			'responseMessage' => $response['responseMessage'],
			'xref' => $response['xref'],
			'amount' => $response['amount'],
			'transactionUnique' => $response['transactionUnique'],
		);

		return ($gatewayResponse ? $gatewayResponse : false);
	}

	/**
	 * Get ApplePay request
	 * --------------------
	 *
	 * This function builds the ApplePay request and returns it as a JSON
	 * array to the ApplePay.JS
	 *
	 * paymentRequest = {
	 *      currencyCode: 'GBP',
	 *      countryCode: 'GB',
	 *      requiredBillingContactFields: ['email', 'name', 'phone', 'postalAddress'],
	 *      requiredShippingContactFields: ['email', 'name', 'phone', 'postalAddress'],
	 *      lineItems: [{
	 *          label: 'test item',
	 *          amount: '2.99'
	 *      }],
	 *      total: {
	 *          label: 'Total label',
	 *          amount: '2.99'
	 *      },
	 *      supportedNetworks: [
	 *          "amex",
	 *          "visa",
	 *          "discover",
	 *          "masterCard"
	 *      ],
	 *      merchantCapabilities: ['supports3DS']
	 *  }
	 */
	public function get_applepay_request()
	{

		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		$cartContents = array();
		$shippingAmountTotal = 0;
		$cartTotal = 0;

		$failedOrderPaymnet = (isset($_POST['orderID']) && $_POST['orderID'] != "false");

		// If failed order cookie get cart items from the order as the cart will be empty.
		// Other wise get the items from the cart as it's not become an order yet.
		if ($failedOrderPaymnet) {

			$order = wc_get_order(wc_get_order_id_by_order_key($_POST['orderID']));

			foreach ($order->get_items() as $item_id => $item) {
				array_push(
					$cartContents,
					array(
						'title' => $item->get_name(),
						'quantity' => $item->get_quantity(),
						'price' => $item->get_total() /  $item->get_quantity(),
						'product_id' => $item->get_product_id(),
					)
				);
			}

			$shippingAmountTotal = $order->get_shipping_total();
			$cartTotal = $order->get_total();
		} else {

			$cart = WC()->cart;

			foreach ($cart->cart_contents as $item) {
				array_push(
					$cartContents,
					array(
						'title' => $item['data']->get_title(),
						'quantity' => $item['quantity'],
						'price' => $item['data']->get_price(),
						'product_id' => $item['product_id'],
						'virtual_product' => $item['data']->is_virtual(),
					)
				);
			}

			$shippingAmountTotal = $cart->get_shipping_total();
			$cartTotal = $cart->total;
		}

		// Apple Pay request line items.
		$lineItems = array();

		// Add the shipping amount to the request.
		array_push($lineItems, array('label' => 'Shipping', 'amount' => $shippingAmountTotal));

		// For each item in the cart add to line items.
		foreach ($cartContents as $item) {

			$itemTitle = $item['title'];
			$itemPrice = $item['price'];
			$itemQuantity = $item['quantity'];

			$productID = wc_get_product($item['product_id']);
			if (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($productID)) {

				$firstPaymentDate = (WC_Subscriptions_Product::get_trial_expiration_date($productID)
					? WC_Subscriptions_Product::get_trial_expiration_date($productID) : date('Y-m-d'));

				$subscriptionItem = array(
					'label' => "{$itemTitle}",
					'amount' => $itemPrice,
					'recurringPaymentStartDate' => $firstPaymentDate,
					'recurringPaymentIntervalUnit' => WC_Subscriptions_Product::get_period($productID),
					'paymentTiming' => 'recurring',
				);

				// Add recurring cost if first payment is today,
				if (WC_Subscriptions_Product::get_trial_expiration_date($productID)) {
					$amountToPay = ($amountToPay + $itemPrice);
				}

				if ($signUpFee = WC_Subscriptions_Product::get_sign_up_fee($productID)) {
					array_push($lineItems, array('label' => "{$itemTitle} Sign up fee ", 'amount' => $signUpFee));
				}

				// Add sub
				array_push($lineItems, $subscriptionItem);
			} else {
				array_push($lineItems, array('label' => "{$itemQuantity} x {$itemTitle}", 'amount' => ($itemPrice * $itemQuantity)));
			}
		}

		$applePayRequest = array(
			'currencyCode' => get_woocommerce_currency(),
			'countryCode' => wc_get_base_location()['country'],
			'requiredBillingContactFields' => array('email', 'name', 'phone', 'postalAddress'),
			'lineItems' => $lineItems,
			'total' => array(
				'label' => 'Total',
				'amount' => $cartTotal,
			),
			'supportedNetworks' => array(
				'amex',
				'visa',
				'discover',
				'masterCard',
			),
			'merchantCapabilities' => array('supports3DS'),
		);

		// Check if any coupons are available (therfore enabled)
		// If so add support for them to Apple Pay request.
		if (empty(WC()->cart->get_applied_coupons())) {
			$applePayRequest['supportsCouponCode'] = true;
		}

		// If shipping methods are available and one product in the
		// cart is not a virtual product, then add shipping requirmenets
		// to the Apple Pay request.
		if (!empty(WC()->session->get('chosen_shipping_methods')[0])) {
			foreach ($cartContents as $item) {
				if ($item['virtual_product'] === false) {
					$applePayRequest['requiredShippingContactFields'] = array('email', 'name', 'phone', 'postalAddress');
				}
			}
		}

		// If this is a failed order payment remove the shipping requirment.
		if ($failedOrderPaymnet) {
			unset($applePayRequest['requiredShippingContactFields']);
		}

		wp_send_json_success($applePayRequest);
		wp_die();
	}

	/**
	 * Update Shipping method.
	 * 
	 * This function will update the shipping
	 * method selected on the Apple Pay screen.
	 */
	public function update_shipping_method()
	{
		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		// Check there is a shipping method selected being posted.
		if (!empty($_POST['shippingMethodSelected'])) {

			// If the selected method is not a string then it's the Apple Pay UI updating. 
			// New cart data will be needed in a response.
			$shippingMethodSelected = json_decode(stripslashes_deep($_POST['shippingMethodSelected']));
			WC()->session->set('chosen_shipping_methods', array($shippingMethodSelected->identifier));

			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();

			$cartData = $this->get_cart_data();

			// Return the response
			$JSONResponse = array(
				'status' => true,
				'lineItems' => $cartData['cartItems'],
				'total' => $cartData['cartTotal'],
			);
		} else {
			$JSONResponse = array(
				'status' => false,
			);
		}

		wp_send_json_success($JSONResponse);
	}

	/**
	 * Get Shipping Methods
	 *
	 * This function will get shipping methods 
	 * available for selection on the Apple Pay screen.
	 */
	public function get_shipping_methods()
	{
		// Check nonce sent in request that called the function is correct.
		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		$shippingContactSelectDetails = json_decode(stripslashes_deep($_POST['shippingContactSelected']));
		$zones = WC_Shipping_Zones::get_zones();
		$countryCode = $shippingContactSelectDetails->countryCode;
		$newShippingMethods = array();
		// Get the chosen shipping method from the session data.
		$shippingMethodSelected =  WC()->session->get('chosen_shipping_methods')[0];

		foreach ($zones as $zone) {
			foreach ($zone['zone_locations'] as $zonelocation) {
				if ($zonelocation->code === $countryCode) {
					foreach ($zone['shipping_methods'] as $shippingMethod) {
						array_push($newShippingMethods, array(
							'label' => strip_tags($shippingMethod->method_title),
							'detail' => strip_tags($shippingMethod->method_description),
							'amount' => (isset($shippingMethod->cost) ? $shippingMethod->cost : 0),
							'identifier' => $shippingMethod->id . ':' . $shippingMethod->instance_id,
							'selected' => ($shippingMethodSelected === ($shippingMethod->id . ':' . $shippingMethod->instance_id)),
						));
					}
				}
			}
		}
		WC()->customer->set_shipping_country($countryCode);
		// Set selected shipping method or top one as default
		WC()->session->set('chosen_shipping_methods', array($shippingMethodSelected));

		$cartData = $this->get_cart_data();

		// Return the response
		$JSONResponse = array(
			'status' => (count($newShippingMethods) === 0 ? false : true),
			'shippingMethods' => $newShippingMethods,
			'lineItems' => $cartData['cartItems'],
			'total' => $cartData['cartTotal']
		);

		wp_send_json_success($JSONResponse);
	}

	/**
	 * Apple a coupon code from ApplePay 
	 */
	public function apply_coupon_code()
	{

		if (!wp_verify_nonce($_POST['securitycode'], $this->nonce_key)) {
			wp_die();
		}

		if (!empty($couponCode = $_POST['couponCode'])) {

			if (WC()->cart->has_discount($couponCode)) {
				return;
			}

			WC()->cart->apply_coupon($couponCode);

			$cartData = $this->get_cart_data();

			// Return the response
			$JSONResponse = array(
				'lineItems' => $cartData['cartItems'],
				'total' => $cartData['cartTotal']
			);

			wp_send_json_success($JSONResponse);
		}

		wp_send_json_success(['error' => 'Missing shipping contact']);
	}

	/**
	 * Get shopping cart items and totals
	 *
	 * This function will recalculate the shopping 
	 * carts totals including shipping cost and return
	 * the data as an array. It is assumed at this 
	 * point a shipping method has been selected, 
	 * otherwise no shipping costs will returned 
	 * which can result in mismatched amounts 
	 * between the order and ApplePay token
	 * 
	 * returns Array
	 */
	protected function get_cart_data()
	{

		// Recalculate cart totals.
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();

		$cartContents = array();
		$shippingAmountTotal = 0;
		$cartTotal = 0;

		$cart = WC()->cart;

		foreach ($cart->cart_contents as $item) {
			array_push(
				$cartContents,
				array(
					'title' => $item['data']->get_title(),
					'quantity' => $item['quantity'],
					'price' => $item['data']->get_price(),
					'product_id' => $item['product_id'],
				)
			);
		}

		$shippingAmountTotal = $cart->get_shipping_total();
		$cartTotal = $cart->total;


		// Apple Pay request line items.
		$lineItems = array();

		// Add the shipping amount to the request.
		array_push($lineItems, array('label' => 'Shipping', 'amount' => $shippingAmountTotal));

		// For each item in the cart add to line items.
		foreach ($cartContents as $item) {

			$itemTitle = $item['title'];
			$itemPrice = $item['price'];
			$itemQuantity = $item['quantity'];

			$productID = wc_get_product($item['product_id']);
			if (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($productID)) {

				$firstPaymentDate = (WC_Subscriptions_Product::get_trial_expiration_date($productID)
					? WC_Subscriptions_Product::get_trial_expiration_date($productID) : date('Y-m-d'));

				$subscriptionItem = array(
					'label' => "{$itemTitle}",
					'amount' => $itemPrice,
					'recurringPaymentStartDate' => $firstPaymentDate,
					'recurringPaymentIntervalUnit' => WC_Subscriptions_Product::get_period($productID),
					'paymentTiming' => 'recurring',
				);

				// Add recurring cost if first payment is today,
				if (WC_Subscriptions_Product::get_trial_expiration_date($productID)) {
					$amountToPay = ($amountToPay + $itemPrice);
				}

				if ($signUpFee = WC_Subscriptions_Product::get_sign_up_fee($productID)) {
					array_push($lineItems, array('label' => "{$itemTitle} Sign up fee ", 'amount' => $signUpFee));
				}

				// Add sub
				array_push($lineItems, $subscriptionItem);
			} else {
				array_push($lineItems, array('label' => "{$itemQuantity} x {$itemTitle}", 'amount' => ($itemPrice * $itemQuantity)));
			}
		}


		return array('cartItems' => $lineItems, 'cartTotal' => $cartTotal, 'shippingAmountTotal' => $shippingAmountTotal);
	}

	/**
	 * On Order Success
	 *
	 * Called when the payment is successful.
	 * This will complete the order.
	 *
	 * @param Array     $data
	 */
	private function on_order_success($data)
	{
		// Get an instance of the WC_Order object
		$order = wc_get_order($data);

		$gatewayResponse = $order->get_meta('gatewayResponse');

		$orderNotes = "\r\nResponse Code : {$gatewayResponse['responseCode']}\r\n";
		$orderNotes .= "Message : {$gatewayResponse['responseMessage']}\r\n";
		$orderNotes .= "Amount Received : " . number_format($gatewayResponse['amount'] / 100, 2) . "\r\n";
		$orderNotes .= "Unique Transaction Code : {$gatewayResponse['transactionUnique']}";

		$order->set_transaction_id($gatewayResponse['xref']);
		$order->add_order_note(__(ucwords($this->method_title) . ' payment completed.' . $orderNotes, $this->lang));
		$order->payment_complete();

		$redirectURL = $this->get_return_url($order);
		// Return the redirect URL
		return $redirectURL;
	}

	/**
	 * On Order Fail
	 *
	 * Called when the payment is successful.
	 * This will complete the order.
	 *
	 * @param Array     $data
	 */
	private function on_order_fail($data)
	{
		// Get an instance of the WC_Order object
		$order = wc_get_order($data);

		$gatewayResponse = $order->get_meta('gatewayResponse');

		$orderNotes = "\r\nResponse Code : {$gatewayResponse['responseCode']}\r\n";
		$orderNotes .= "Message : {$gatewayResponse['responseMessage']}\r\n";
		$orderNotes .= "Amount Received : " . number_format($gatewayResponse['amount'] / 100, 2) . "\r\n";
		$orderNotes .= "Unique Transaction Code : {$gatewayResponse['transactionUnique']}";

		$order->update_status('failed');
		$order->add_order_note(__(ucwords($this->method_title) . ' payment failed.' . $orderNotes, $this->lang));

		return $this->get_return_url($order);
	}

	/**
	 * Payment fields
	 *
	 * Uses the payment field method to display the Apple Pay
	 * button on the checkout page.
	 */
	public function payment_fields()
	{
		echo <<<EOS
		<style>
		#applepay-button {
			width: auto;
			height: 60px;
			border-radius: 5px;
			background-repeat: no-repeat;
			background-size: 80%;
			background-image: -webkit-named-image(apple-pay-logo-white);
			background-position: 50% 50%;
			background-color: black;
			margin: auto;
			cursor: pointer;
		}
		</style>
		<div id="applepay-button-container" style="display: none;" >
			<div id="applepay-button" onclick="applePayButtonClicked()"> </div>
		</div>
		<div id="applepay-not-available-message" style="display: none;">
			<label>Apple Pay is not available on this device.</label>
		</div>
		<div id="applepay-not-setup" style="display: none;">
			<label>Apple pay is not setup on this device.</label>
		</div>
		<a href="https://www.apple.com/apple-pay/" target="_blank" style="padding-top: 10px;">What is Apple Pay?</a>
		EOS;
	}

	/**
	 * Payments scripts
	 *
	 * Enqueues the Apple Pay javascript
	 */
	public function payment_scripts()
	{
		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ($this->enabled === 'no') {
			return;
		}

		wp_register_script('applepay_script', $this->pluginURL . '/assets/js/applepay.js');
		wp_enqueue_script('applepay_script');
		wp_localize_script('applepay_script', 'localizeVars', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'securitycode' => wp_create_nonce($this->nonce_key),
		));

		add_action('wp_ajax_nopriv_get_data', array($this, 'get_data'), 10, 2);
		add_action('wp_ajax_get_data', array($this, 'get_data'), 10, 2);
	}

	/**
	 * Create signature
	 *
	 * @param Array		$data
	 * @param String	$key
	 * @return String
	 */
	public function createSignature(array $data, $key)
	{
		// Sort by field name
		ksort($data);
		// Create the URL encoded signature string
		$ret = http_build_query($data, '', '&');
		// Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
		$ret = str_replace(array('%0D%0A', '%0A%0D', '%0D'), '%0A', $ret);
		// Hash the signature string and the key together
		return hash('SHA512', $ret . $key);
	}

	/**
	 * Process Refund
	 *
	 * Refunds a settled transactions or cancels
	 * one not yet settled.
	 *
	 * @param Interger        $amount
	 * @param Float         $amount
	 */
	public function process_refund($orderID, $amount = null, $reason = '')
	{

		// Get the transaction XREF from the order ID and the amount.
		$order = wc_get_order($orderID);
		$transactionXref = $order->get_transaction_id();
		$amountToRefund = \P3\SDK\AmountHelper::calculateAmountByCurrency($amount, $order->get_currency());

		// Check the order can be refunded.
		if (!$this->can_refund_order($order)) {
			return new WP_Error('error', __('Refund failed.', 'woocommerce'));
		}

		$gateway = new Gateway(
			$this->defaultMerchantID,
			$this->defaultMerchantSignature,
			$this->defaultGatewayURL
		);

		// Query the transaction state.
		$queryPayload = [
			'merchantID' => $this->defaultMerchantID,
			'xref' => $transactionXref,
			'action' => 'QUERY',
		];

		// Sign the request and send to gateway.
		$transaction = $gateway->directRequest($queryPayload);

		if (empty($transaction['state'])) {
			return new WP_Error('error', "Could not get the transaction state for {$transactionXref}");
		}

		if ($transaction['responseCode'] == 65558) {
			return new WP_Error('error', "IP blocked primary");
		}

		// Build the refund request
		$refundRequest = [
			'merchantID' => $this->defaultMerchantID,
			'xref' => $transactionXref,
		];

		switch ($transaction['state']) {
			case 'approved':
			case 'captured':
				// If amount to refund is equal to the total amount captured/approved then action is cancel.
				if ($transaction['amountReceived'] === $amountToRefund || ($transaction['amountReceived'] - $amountToRefund <= 0)) {
					$refundRequest['action'] = 'CANCEL';
				} else {
					$refundRequest['action'] = 'CAPTURE';
					$refundRequest['amount'] = ($transaction['amountReceived'] - $amountToRefund);
				}
				break;

			case 'accepted':
				$refundRequest = array_merge($refundRequest, [
					'action' => 'REFUND_SALE',
					'amount' => $amountToRefund,
				]);
				break;

			default:
				return new WP_Error('error', "Transaction {$transactionXref} it not in a refundable state.");
		}

		// Sign the refund request and sign it.
		$refundResponse = $gateway->directRequest($refundRequest);

		// Handle the refund response
		if (empty($refundResponse) && empty($refundResponse['responseCode'])) {

			return new WP_Error('error', "Could not refund {$transactionXref}.");
		} else {

			$orderMessage = ($refundResponse['responseCode'] == "0" ? "Refund Successful" : "Refund Unsuccessful") . "<br/><br/>";

			$state = $refundResponse['state'] ?? null;

			if ($state != 'canceled') {
				$orderMessage .= "Amount Refunded: " . number_format($amountToRefund / pow(10, $refundResponse['currencyExponent']), $refundResponse['currencyExponent']) . "<br/><br/>";
			}

			$order->add_order_note($orderMessage);
			return true;
		}

		return new WP_Error('error', "Could not refund {$transactionXref}.");
	}

	/**
	 * Hook to process a subscription payment
	 *
	 * @param Float     $amount_to_charge
	 * @param Object     $renewal_order
	 */
	public function process_scheduled_subscription_payment_callback($amount_to_charge, $renewal_order)
	{
		// Create a new Gateway instance
		$gateway = new Gateway(
			$this->defaultMerchantID,
			$this->defaultMerchantSignature,
			$this->defaultGatewayURL
		);

		// Gets all subscriptions (hopefully just one) linked to this order
		$subs = wcs_get_subscriptions_for_renewal_order($renewal_order);

		// Get all orders on this subscription and remove any that haven't been paid
		$orders = array_filter(current($subs)->get_related_orders('all'), function ($ord) {
			return $ord->is_paid();
		});

		// Replace every order with orderId=>xref kvps
		$xrefs = array_map(function ($ord) {
			return $ord->get_transaction_id();
		}, $orders);

		// Return the xref corresponding to the most recent order (assuming order number increase with time)
		$xref = $xrefs[max(array_keys($xrefs))];

		$req = array(
			'merchantID' => $this->defaultMerchantID,
			'xref' => $xref,
			'amount' => \P3\SDK\AmountHelper::calculateAmountByCurrency($amount_to_charge, $renewal_order->get_currency()),
			'action' => "SALE",
			'type' => '9',
			'rtAgreementType' => 'recurring',
			'avscv2CheckRequired' => 'N',
		);

		$response = $gateway->directRequest($req);

		try {

			$result = $gateway->verifyResponse($response, array($this, 'on_threeds_required'), function ($res) use ($renewal_order) {

				$orderNotes = "\r\nResponse Code : {$res['responseCode']}\r\n";
				$orderNotes .= "Message : {$res['responseMessage']}\r\n";
				$orderNotes .= "Amount Received : " . number_format($res['amount'] / 100, 2) . "\r\n";
				$orderNotes .= "Unique Transaction Code : {$res['transactionUnique']}";

				$renewal_order->set_transaction_id($res['xref']);
				$renewal_order->add_order_note(__(ucwords($this->method_title) . ' payment completed.' . $orderNotes, $this->lang));
				$renewal_order->payment_complete();
				$renewal_order->save();

				return true;
			});
		} catch (Exception $exception) {
			$result = new WP_Error('payment_failed_error', $exception->getMessage());
			$renewal_order->add_order_note(
				__(ucwords($this->method_title) . ' payment failed. Could not communicate with direct API. Curl data: ' . json_encode($req), $this->lang)
			);
			$renewal_order->save();
		}

		if (is_wp_error($result)) {
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
		} else {
			WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
		}
	}
}
