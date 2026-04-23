<?php


/**
 * Plugin Name: Maib Checkout Payment Gateway for WooCommerce
 * Description: Accept Visa / Mastercard / Apple Pay / Google Pay / MIA Instant Payments on your store with the Maib Checkout Payment Gateway for WooCommerce.
 * Plugin URI: https://github.com/maib-ecomm/maib-checkout-payment-gateway-for-woocommerce
 * Version: 1.0.0
 * Author: maib
 * Author URI: https://github.com/maib-ecomm
 * Developer: maib
 * Developer URI: https://github.com/maib-ecomm
 * Text Domain: maib-checkout-payment-gateway-for-woocommerce
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 4.8
 * Tested up to: 6.5.4
 * WC requires at least: 3.3
 * WC tested up to: 7.8.0
 * Requires Plugins: woocommerce
 */

use MaibEcomm\MaibCheckoutSdk\MaibCheckoutApiRequest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Define plugin constants.
 */
if (!defined('MAIB_GATEWAY_PLUGIN_FILE')) {
    define('MAIB_GATEWAY_PLUGIN_FILE', __FILE__);
}
if (!defined('MAIB_GATEWAY_PLUGIN_DIR')) {
    define('MAIB_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('MAIB_GATEWAY_PLUGIN_URL')) {
    define('MAIB_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
}

add_action('plugins_loaded', function () {
    load_plugin_textdomain(
        'maib-checkout-payment-gateway-for-woocommerce',
        false,
        dirname(plugin_basename(MAIB_GATEWAY_PLUGIN_FILE)) . '/languages'
    );
});


/**
 * Load Composer autoloader.
 */
$maib_autoload = MAIB_GATEWAY_PLUGIN_DIR . 'vendor/autoload.php';

if (file_exists($maib_autoload)) {
    require_once $maib_autoload;
} else {
    // Optional: add admin notice here
    return;
}

class_alias("MaibEcomm\MaibCheckoutSdk\MaibCheckoutAuthRequest", "MaibCheckoutAuthRequest");
class_alias("MaibEcomm\MaibCheckoutSdk\MaibCheckoutApiRequest", "MaibCheckoutApiRequest");

/**
 * Init plugin.
 */
add_action('plugins_loaded', 'maib_payment_gateway_init', 0);
/**
 * Initialize the MAIB payment gateway.
 */
function maib_payment_gateway_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    // Hook the custom function to the 'before_woocommerce_init' action
    add_action('before_woocommerce_init', function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        }
    });

    // Hook the custom function to the 'woocommerce_blocks_loaded' action
    add_action( 'woocommerce_blocks_loaded', 'maib_register_order_approval_payment_method_type' );

    class MaibPaymentGateway extends WC_Payment_Gateway
    {
        #region Constants
        const MAIB_MOD_ID = 'maib';
        const MAIB_MOD_TITLE = 'Maib Checkout Payment Gateway';
        const MAIB_MOD_DESC = 'Visa / Mastercard / Apple Pay / Google Pay / MIA Instant Payments';
        const MAIB_MOD_PREFIX = 'maib_';
        const MAIB_SUPPORTED_CURRENCIES = ['MDL', 'EUR', 'USD'];
        const MAIB_ORDER_TEMPLATE = 'Order #%1$s';
        const DEFAULT_BASE_URL = 'https://api.maibmerchants.md/v2/';
        const SANDBOX_BASE_URL = 'https://sandbox.maibmerchants.md/v2/';
        #endregion
        
        public static $log_enabled = false;
        public static $log = false;

        protected $base_url, $debug, $order_template;
        protected $maib_client_id, $maib_client_secret, $maib_signature_key;
        protected $completed_order_status, $failed_order_status;

        public function __construct()
        {
            $this->id = self::MAIB_MOD_ID;
            $this->method_title = self::MAIB_MOD_TITLE;
            $this->method_description = self::MAIB_MOD_DESC;
            $this->has_fields = false;
            $this->supports = array(
                'products',
                'refunds',
                'custom_order_tables',
            );

            #region Initialize user set variables
            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->testmode = wc_string_to_bool($this->get_option('testmode', 'no'));
            $this->base_url = $this->testmode ? self::SANDBOX_BASE_URL : self::DEFAULT_BASE_URL;

            $this->icon = MAIB_GATEWAY_PLUGIN_URL . 'assets/img/maib.png';

            $this->debug = 'yes' === $this->get_option('debug', 'no');
            self::$log_enabled = $this->debug;

            $this->order_template = $this->get_option('order_template', self::MAIB_ORDER_TEMPLATE);

            $this->maib_client_id = $this->get_option('maib_client_id');
            $this->maib_client_secret = $this->get_option('maib_client_secret');
            $this->maib_signature_key = $this->get_option('maib_signature_key');

            $this->completed_order_status = $this->get_option('completed_order_status');
            $this->failed_order_status = $this->get_option('failed_order_status');

            $this->route_callback = 'maib/callback';

            $this->init_form_fields();
            $this->init_settings();
            #endregion
            
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            add_action('woocommerce_api_' . $this->route_callback, [$this, 'route_callback']);
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'checkbox',
                    'label' => __('Enable Maib Checkout Payment Gateway', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => 'yes'
                ) ,
                
                'title' => array(
                    'title' => __('Title', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('Payment method title that the customer will see during checkout.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'desc_tip' => true,
                    'default' => __('Maib Checkout Payment Gateway', 'maib-checkout-payment-gateway-for-woocommerce')
                ) ,

                'description' => array(
                    'title' => __('Description', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see during checkout.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'desc_tip' => true,
                    'default' => __('Visa / Mastercard / Apple Pay / Google Pay / MIA Instant Payments', 'maib-checkout-payment-gateway-for-woocommerce')
                ) ,

                'testmode' => array(
                    'title'       => __('Test mode', 'maib-checkout-payment-gateway-for-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Enabled', 'maib-checkout-payment-gateway-for-woocommerce'),
                    'desc_tip'    => true,
                    'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'maib-checkout-payment-gateway-for-woocommerce'),
                    'default'     => 'no',
                ),

                'debug' => array(
                    'title' => __('Debug mode', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => 'yes',
                    'description' => sprintf('<a href="%2$s&source=maib_gateway&paged=1">%1$s</a>', __('View logs', 'maib-checkout-payment-gateway-for-woocommerce') , self::get_logs_url()) ,
                    'desc_tip' => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'maib-checkout-payment-gateway-for-woocommerce')
                ),

                'order_template' => array(
                    'title' => __('Order description', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'text',
                    // translators: %1$s - order ID, %2$ - order items summary
                    'description' => __('Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'desc_tip' => __('Order description that the customer will see on the bank payment page.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => self::MAIB_ORDER_TEMPLATE
                ) ,

                'maib_client_id' => array(
                    'title' => __('Client ID <span class="required">*</span>', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('It is available after payment profile setup.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => ''
                ) ,

                'maib_client_secret' => array(
                    'title' => __('Client Secret <span class="required">*</span>', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'password',
                    'description' => __('It is available after payment profile setup.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => ''
                ) ,

                'maib_signature_key' => array(
                    'title' => __('Signature Key <span class="required">*</span>', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'password',
                    'description' => __('It is available after payment profile setup.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'default' => ''
                ) ,

                'status_settings' => array(
                    'title' => __('Order status', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'title'
                ) ,

                'completed_order_status' => array(
                    'title' => __('Payment completed', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'select',
                    'options' => $this->getPaymentOrderStatuses() ,
                    'default' => 'none',
                    'description' => __('The completed order status after successful payment. By default: Processing.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'desc_tip' => true
                ) ,

                'failed_order_status' => array(
                    'title' => __('Payment failed', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'type' => 'select',
                    'options' => $this->getPaymentOrderStatuses() ,
                    'default' => 'none',
                    'description' => __('Order status when payment failed. By default: Failed.', 'maib-checkout-payment-gateway-for-woocommerce') ,
                    'desc_tip' => true
                ),
            );
        }

        #region Payment
        
        /**
         * Process the payment and redirect client.
         *
         * @since 1.0.0
         * @param  int $order_id Order ID.
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            if (empty($this->maib_client_id) || empty($this->maib_client_secret) || empty($this->maib_signature_key))
            {
                $this->log('One or more of the required fields is empty in Maib Checkout Payment Gateway settings.');
                wc_add_notice(__('One or more of the required fields (Client ID, Client Secret, Signature Key) is empty in Maib Checkout Payment Gateway settings.', 'maib-checkout-payment-gateway-for-woocommerce') , 'error');
                return array();
            }

            try
            {
                $this->log('Initiate create checkout session', 'info');
                $response = $this->create_checkout($this->get_access_token(), $order, $order_id);

                if (!isset($response->ok) || !$response->ok)
                {
                    $this->log(json_encode($response->errors), 'error');
                    wc_add_notice(__('Payment initiation failed via maib gateway, please try again later.', 'maib-checkout-payment-gateway-for-woocommerce') , 'error');
                    return array();
                }

                $checkoutResult = $response->result;

                $this->log(sprintf('Response from create_checkout endpoint: %s, order_id: %d', wp_json_encode($response, JSON_PRETTY_PRINT) , $order_id) , 'info');
            }
            catch(\Throwable $ex)
            {
                $this->log($ex, 'error');
            }

            if (!$checkoutResult || !isset($checkoutResult->checkoutUrl))
            {
                $this->log(sprintf('No valid response from maib, order_id: %d', $order_id) , 'error');
                wc_add_notice(__('Payment initiation failed via maib gateway, please try again later.', 'maib-checkout-payment-gateway-for-woocommerce') , 'error');
                return array();
            }

            $order->update_meta_data('_checkout_id', $checkoutResult->checkoutId);
            $order->add_order_note('maib Checkout ID: <br>' . $checkoutResult->checkoutId);
            $order->save();

            $redirect_to = $checkoutResult->checkoutUrl;

            $this->log(sprintf('Order id: %d, redirecting user to maib gateway: %s', $order_id, $redirect_to) , 'notice');

            return ['result' => 'success', 'redirect' => $redirect_to, ];
        }

        /**
         * Process a refund
         *
         * @param  int    $order_id Order ID.
         * @param  float  $amount Refund amount.
         * @param  string $reason Refund reason.
         * @return bool|WP_Error
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);

            if (!$order->get_transaction_id())
            {
                $this->log('Refund not possible, payment ID missing in order data.', 'error');
                return new \WP_Error('error', __('Refund not possible, payment ID missing in order data.', 'maib-checkout-payment-gateway-for-woocommerce'));
            }

            $this->log(sprintf('Start refund, Order id: %s / Refund amount: %s', $order->get_id() , $amount) , 'info');

            try
            {
                $this->log('Initiate Refund', 'info');
                $accessToken = $this->get_access_token();
                $paymentId = strval($order->get_transaction_id());

                $response = $this->refund_payment($accessToken, $paymentId, $amount, $reason);

                if (!isset($response->ok) || !$response->ok)
                {
                    $this->log(json_encode($response->errors), 'error');
                    return new \WP_Error('error', __($response->errors[0]->errorMessage, 'maib-checkout-payment-gateway-for-woocommerce'));
                }

                $this->log(sprintf('Response from refund endpoint: %s, order_id: %d', wp_json_encode($response->result, JSON_PRETTY_PRINT) , $order_id) , 'info');

                $isCompleted = $this->wait_refund_completion($accessToken, $response->result->refundId);
            }
            catch(\Throwable $ex)
            {
                $this->log($ex, 'error');
                return new \WP_Error('error', __('An error occurred in the system. Call 1313.', 'maib-checkout-payment-gateway-for-woocommerce'));
            }

            if($isCompleted === false)
            {
                $this->log("Refund was not completed", 'error');
                return new \WP_Error('error', __('Refund was not completed due to technical error or timeout. Please contact to support.', 'maib-checkout-payment-gateway-for-woocommerce'));
            }

            $this->log('Success ~ Refund is done!', 'info');
            $order_note = sprintf('Refunded! Refund details: %s', wp_json_encode($response, JSON_PRETTY_PRINT));
            $order->add_order_note($order_note);

            return true;
        }

        #region Utility

        /**
         * Logging method.
         *
         * @since 1.0.0
         * @param string $message Log message.
         * @param string $level Optional. Default 'info'. Possible values:
         * emergency|alert|critical|error|warning|notice|info|debug.
         */
        public static function log($message, $level = 'info')
        {
            if (self::$log_enabled)
            {
                if (empty(self::$log))
                {
                    self::$log = wc_get_logger();
                }
                self::$log->log($level, $message, ['source' => 'maib_gateway']);
            }
        }

        /**
         * Get access token.
         */
        private function get_access_token()
        {
            $auth = MaibCheckoutAuthRequest::create($this->base_url);
            $result = $auth->generateToken($this->maib_client_id, $this->maib_client_secret);

            if (!isset($result->accessToken) || $result->accessToken === '') {
                throw new \Exception('Token not returned');
            }

            return (string) $result->accessToken;
        }

        /**
         * Creates new checkout session.
         */
        private function create_checkout($token, \WC_Order $order, $order_id)
        {
            $items = array();
            foreach ($order->get_items() as $item) {
                $product = ($item instanceof \WC_Order_Item_Product) ? $item->get_product() : null;
                $sku = $product ? (string) $product->get_sku() : '';

                $items[] = array(
                    'externalId' => $sku,
                    'title'      => (string) $item->get_name(),
                    'amount'     => $order->get_item_total($item, true, true),
                    'currency'   => (string) $order->get_currency(),
                    'quantity'   => $item->get_quantity(),
                );
            }

            $created = $order->get_date_created() ? $order->get_date_created() : new \WC_DateTime();

            $payload = array(
                'amount'   => (float) $order->get_total(),
                'currency' => (string) $order->get_currency(),
                'orderInfo' => array(
                    'id'          => (string) $order->get_id(),
                    'description' => $this->get_order_description($order),
                    'date'        => $created->format('c'),
                    'items'       => $items,
                ),
                'payerInfo' => array(
                    'name'      => $order->get_formatted_billing_full_name(),
                    'email'     => $order->get_billing_email(),
                    'phone'     => $order->get_billing_phone(),
                    'ip'        => \WC_Geolocation::get_ip_address(),
                    'userAgent' => $order->get_customer_user_agent(),
                ),
                'language'    => substr(get_user_locale(), 0, 2),
                'callbackUrl' => $this->get_callback_url(),
                'successUrl'  => $this->get_return_url($order),
                'failUrl'     => $order->get_checkout_payment_url()
            );

            $this->log(sprintf('Order params for send to maib API: %s, order_id: %d', wp_json_encode($payload, JSON_PRETTY_PRINT), $order_id) , 'info');

            $api = MaibCheckoutApiRequest::create($this->base_url);
            return $api->createCheckout($payload, $token);
        }

        /**
         * Initiates payment refund.
         */
        private function refund_payment($token, $payment_id, $amount, $reason)
        {
            $api = MaibCheckoutApiRequest::create($this->base_url);

            $payload = array(
                'payId'  => (string) $payment_id,
                'amount' => (float) $amount,
                'reason' => (string) $reason,
            );

            return $api->refund($payload, $token);
        }

        /**
         * Get payment status.
         */
        private function get_refund($token, $refund_id)
        {
            $api = MaibCheckoutApiRequest::create($this->base_url);

            return $api->getRefund($refund_id, $token);
        }


        private function wait_refund_completion($token, $refund_id)
        {
            $maxAttempts = 15;
            $delaySeconds = 1;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $refundResponse = $this->get_refund($token, $refund_id);

                if (!isset($refundResponse->ok) || !$refundResponse->ok)
                {
                    $this->log(json_encode($refundResponse->errors), 'error');
                    return false;
                }

                $status = $refundResponse->result->status;
                if ($status === 'Accepted') {
                    break;
                }

                if ($attempt < $maxAttempts) {
                    sleep($delaySeconds);
                }
            }

            if (($status ?? null) !== 'Accepted') {
                $this->log('Refund status did not become Accepted within specific time range.', 'error');
                return false;
            }

            return true;
        }

        /**
         * Getting all available woocommerce order statuses
         *
         * @return array
         */
        public function getPaymentOrderStatuses()
        {
            $order_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
            $statuses = ['default' => __('Default status', 'maib-checkout-payment-gateway-for-woocommerce') ];
            if ($order_statuses)
            {
                foreach ($order_statuses as $k => $v)
                {
                    $statuses[str_replace('wc-', '', $k) ] = $v;
                }
            }

            return $statuses;
        }

        /**
         * Notification on Callback URL
         */
        public function route_callback()
        {
            if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) {
                $rawBody = file_get_contents('php://input');
                $data = json_decode($rawBody, true);

                if (!$data) {
                    http_response_code(400);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo esc_html('ERROR');;
                    $this->log('Callback URL - Payment data not found in notification.', 'error');
                    exit();
                }
            } else {
                $message = sprintf(__('This Callback URL works and should not be called directly.', 'maib-checkout-payment-gateway-for-woocommerce'), $this->method_title);
                wc_add_notice($message, 'notice');
                wp_safe_redirect(wc_get_cart_url());
                exit();
            }

            $this->log(sprintf('Notification on Callback URL: %s', wp_json_encode($data, JSON_PRETTY_PRINT)), 'info');

            // New signature verification algorithm (rawBody + "." + timestamp)
            $headers = getallheaders();
            $xSignature = isset($headers['X-Signature']) ? $headers['X-Signature'] : (isset($headers['x-signature']) ? $headers['x-signature'] : '');
            $xTimestamp = isset($headers['X-Signature-Timestamp']) ? $headers['X-Signature-Timestamp'] : (isset($headers['x-signature-timestamp']) ? $headers['x-signature-timestamp'] : '');

            $receivedSig = (strpos($xSignature, 'sha256=') === 0) ? substr($xSignature, 7) : '';
            $messageToSign = $rawBody . '.' . $xTimestamp;
            $expectedSig = base64_encode(hash_hmac('sha256', $messageToSign, $this->maib_signature_key, true));
            $isValid = hash_equals($expectedSig, $receivedSig);

            $pay_id = isset($data['paymentId']) ? $data['paymentId'] : false;
            $order_id = isset($data['orderId']) ? (int)$data['orderId'] : false;
            $payment_status = isset($data['paymentStatus']) ? $data['paymentStatus'] : false;
            $order = wc_get_order($order_id);

           if (!$isValid)
           {
               echo esc_html('Invalid signature');
               $this->log(sprintf('Signature is invalid: %s', $expectedSig), 'info');
               if ($order) {
                   $order->add_order_note('Callback Signature is invalid!');
               }
               exit();
           }

            $this->log(sprintf('Signature is valid: %s', $expectedSig), 'info');

            if (!$payment_status)
            {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo esc_html('Payment Status not found in notification.');
                $this->log('Callback URL - Payment Status not found in notification.', 'error');
                exit();
            }

            if (!$pay_id)
            {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo esc_html('Payment ID not found in notification.');
                $this->log('Callback URL - Payment ID not found in notification.', 'error');
                exit();
            }

            if (!$order)
            {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo esc_html('Order is not found.');
                $this->log('Callback URL - Order ID not found in woocommerce Orders.', 'error');
                exit();
            }

            if (in_array($order->get_status(), array('pending', 'failed')))
            {
                if ($payment_status === 'Executed')
                {
                    $this->payment_complete($order, $pay_id);
                }
                else
                {
                    $this->payment_failed($order, $pay_id);
                }
            }

            $order_note = sprintf('maib transaction details: %s', wp_json_encode($data, JSON_PRETTY_PRINT));
            $order->add_order_note($order_note);
            $order->set_transaction_id($pay_id);
            $order->save();

            echo esc_html('OK');
            exit();
        }

        public function get_callback_url()
        {
            return WC()->api_request_url($this->route_callback);
        }

        /**
         * Payment complete.
         * @param WC_Order $order Order object.
         * @param string   $pay_id Payment ID.
         * @return bool
         */
        public function payment_complete($order, $pay_id)
        {
            if ($order->payment_complete())
            {
                // translators: %1$s - payment ID
                $order_note = sprintf(__('Payment (%1$s) successful.', 'maib-checkout-payment-gateway-for-woocommerce') , $pay_id);
                if ($this->completed_order_status != 'default')
                {
                    WC()
                        ->cart
                        ->empty_cart();
                    $order->update_status($this->completed_order_status, $order_note);
                }
                else
                {
                    $order->add_order_note($order_note);
                }

                $this->log($order_note, 'notice');

                return true;
            }
            return false;
        }

        /**
         * Payment failed.
         * @param WC_Order $order Order object.
         * @param string   $pay_id Payment ID.
         * @return bool
         */
        public function payment_failed($order, $pay_id)
        {
            // translators: %1$s - payment ID
            $order_note = sprintf(__('Payment (%1$s) failed.', 'maib-checkout-payment-gateway-for-woocommerce') , $pay_id);
            $newOrderStatus = $this->failed_order_status != 'default' ? $this->failed_order_status : 'failed';
            if ($order->has_status('failed'))
            {
                $order->add_order_note($order_note);
                $this->log($order_note, 'notice');
                return true;
            }
            else
            {
                $this->log($order_note, 'notice');
                return $order->update_status($newOrderStatus, $order_note);
            }
        }

        /**
         * Get the URL to view logs in WooCommerce.
         *
         * @return string The URL to view logs.
         */
        protected static function get_logs_url()
        {
            return add_query_arg(array(
                'page' => 'wc-status',
                'tab' => 'logs',
            ) , admin_url('admin.php'));
        }

        /**
         * Get the description of an order.
         *
         * @param object $order The order object.
         * @return string The order description.
         */
        protected function get_order_description($order)
        {
            $description = sprintf($this->order_template, $order->get_id(), self::get_order_items_summary($order));
            return apply_filters(self::MAIB_MOD_ID . '_order_description', $description, $order);
        }

        /**
         * Get the summary of items in an order.
         *
         * @param object $order The order object.
         * @return string The summary of order items.
         */
        protected static function get_order_items_summary($order)
        {
            $items = $order->get_items();
            $items_names = array_map(function ($item)
            {
                return $item->get_name();
            }
            , $items);

            return join(', ', $items_names);
        }
        #endregion

        #region WooCommerce

        /**
         * Add the gateway to WooCommerce payment methods.
         *
         * @param array $methods The existing payment methods.
         * @return array The modified payment methods.
         */
        public static function add_gateway($methods)
        {
            $methods[] = self::class;
            return $methods;
        }

        /**
         * Check if WooCommerce is active.
         *
         * @return bool True if WooCommerce is active, false otherwise.
         */
        public static function is_wc_active()
        {
            return class_exists('WooCommerce');
        }

        #endregion
    }

    if (!MaibPaymentGateway::is_wc_active())
        return;

    // Add gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', array(
        MaibPaymentGateway::class ,
        'add_gateway'
    ));

    add_action('wp_enqueue_scripts', 'enqueue_payment_gateway_styles');
    
    function enqueue_payment_gateway_styles()
    {
        // Get the version of your plugin from the plugin header
        $plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
        $plugin_version = $plugin_data['Version'];
    
        // Enqueue the custom CSS file with the plugin version
        wp_enqueue_style('payment-gateway-styles', MAIB_GATEWAY_PLUGIN_URL . 'assets/css/style.css', array(), $plugin_version);
    }
}

#region Register activation hooks
function maib_payment_gateway_activation()
{
    maib_payment_gateway_init();

    if (!class_exists('MaibPaymentGateway')) die('WooCommerce is required for this plugin to work!');
}

register_activation_hook(__FILE__, 'maib_payment_gateway_activation');
#endregion

/**
 * Custom function to register a payment method type
 */
function maib_register_order_approval_payment_method_type() {
    // Check if the required class exists
    if (!class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Include the MAIB Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'class-block.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            // Register an instance of MaibPaymentGateway_Blocks
            $payment_method_registry->register( new MaibPaymentGateway_Blocks );
        }
    );
}
