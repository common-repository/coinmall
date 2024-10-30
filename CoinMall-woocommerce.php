<?php
class WC_Gateway_Coinmall extends WC_Payment_Gateway {
    var $ipn_url;
    var $enabled=true;
    var $seamless_api;
    var $lightning;
    private function is_enabled() {
        return $this->enabled;
    }

    public function __construct() {
        global $woocommerce;
        $this->woocommerce = &$woocommerce;
        $this->id           = 'coinmall';
        $this->icon         = apply_filters( 'woocommerce_coinmall_icon', plugin_dir_url(__FILE__).'/images/coinmall.png');
        $this->has_fields   = true;
        $this->method_title = __( 'CoinMall', 'woocommerce' );
        $this->ipn_url   =  WC()->api_request_url('WC_Gateway_Coinmall');
        $this->api_url = $this->get_option( 'api_url','https://www.coinmall.com/api');
        $this->init_form_fields();
        $this->init_settings();
        $this->pay_for_fee = $this->get_option('pay_for_fee') == 'yes';
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->api_key  = $this->get_option( 'api_key' );
        $this->api_secret   = $this->get_option( 'api_secret' );
        $this->seamless_api   = $this->get_option( 'direct_api' ) == 'yes';
        $this->lightning   = $this->get_option( 'lightning' ) == 'yes';
        $this->debug_email=$this->get_option( 'debug_email' );
        $this->allow_zero_confirm = $this->get_option( 'allow_zero_confirm' ) == 'yes';
        $this->invoice_prefix	= $this->get_option( 'invoice_prefix', 'CMLL-' );
        $this->exclude_tax_and_shipping = $this->get_option( 'exclude_tax_and_shipping' ) == 'yes' ;
        $this->log = new WC_Logger();
        if (!$this->is_enabled()) $this->enabled = false;
        $actions = array(
            'woocommerce_receipt_coinmall'=>
                array( $this, 'receipt_page' ),
            'woocommerce_thankyou_coinmall' =>
                array( $this, 'receipt_page' ),
            'woocommerce_update_options_payment_gateways_coinmall'=>
                array( $this, 'process_admin_options' ),
            'woocommerce_checkout_update_order_meta'=>
                array($this,'save_coinmall_payment_fields'),
          //  'woocommerce_order_item_meta_end'=>
          //     array($this,'show_coinmall_fields_on_order_page'),
            'woocommerce_api_wc_gateway_coinmall'=>
                array( $this, 'check_ipn_response' )
        );
        foreach ($actions as $key=> $action) {
            add_action($key,$action);
        }
        add_action('woocommerce_admin_order_data_after_billing_address',array($this,'show_coinmall_fields_on_admin_page'), 10, 1 );
        add_action('woocommerce_order_item_meta_end',array($this,'show_coinmall_fields_on_order_page'), 10, 3 );
    }

    public function admin_options() {
        ?>
        <h3>CoinMall</h3>
        <table class='form-table'>
        <?php echo $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    public function process_admin_options() {
        parent::process_admin_options();
        $enabled = $this->get_option('enabled') == 'yes';
        if (!$enabled) {
            wp_clear_scheduled_hook('woocommerce_api_wc_gateway_coinmall_cron');
        } else {
            if (! wp_get_schedule ( 'woocommerce_api_wc_gateway_coinmall_cron' )) {
                enable_coinmall_cron_jobs();
            }
        }
    }

    public function init_form_fields() {
    	$this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Enable CoinMall.com Gateway', 'woocommerce' ),
                'default' => 'yes'
            ),
            'direct_api' => array(
                'title' => __( 'Seamless integration', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Seamless integration', 'woocommerce' ),
                'default' => 'yes'
            ),
            'lightning' => array(
                'title' => __( 'Lightning Network', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( 'Lightning Network', 'woocommerce' ),
                'default' => 'yes'
            ),
            'api_url' => array(
                'title' => __( 'API URL', 'woocommerce' ),
                'type'  => 'text',
                'description' => __( 'Please enter CoinMall.com API URL.', 'woocommerce' ),
                'default' => 'https://www.coinmall.com/api/',
            ),
            'api_key' => array(
                'title' => __( 'API Key', 'woocommerce' ),
                'type'  => 'text',
                'description' => __( 'Please enter your CoinMall.com Public Key.', 'woocommerce' ),
                'default' => '',
            ),
            'api_secret' => array(
                'title' => __( 'API Secret', 'woocommerce' ),
                'type' 			=> 'text',
                'description' => __( 'Please enter your CoinMall.com Private Key.', 'woocommerce' ),
                'default' => '',
            ),
            'title' => array(
                'title' => __( 'Title', 'woocommerce' ),
                'type' => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default' => __( 'CoinMall', 'woocommerce' ),
                'desc_tip'      => true,
            ),
            'description' => array(
                'title' => __( 'Description', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
                'default' => __( 'Pay with Bitcoin, ZCash, or other altcoins via CoinMall.com', 'woocommerce' )
            ),
            'exclude_tax_and_shipping' => array(
                'title' => __( 'Exclude tax and shipping', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( "This may be needed for compatibility with certain addons if the order total isn't correct.", 'woocommerce' ),
                'default' => ''
            ),
            'pay_for_fee' => array(
                'title' => __( 'Pay for miner fees', 'woocommerce' ),
                'type' => 'checkbox',
                'label' => __( "Decide whether you or client pays the miner fee", 'woocommerce' ),
                'description' => __( 'This controls who pays miner fees.', 'woocommerce' ),
                'default' => 'yes',
                'desc_tip' => true
            ),
            'invoice_prefix' => array(
                'title' => __( 'Invoice Prefix', 'woocommerce' ),
                'type' => 'text',
                'description' => __( 'Please enter a prefix for your invoice numbers.', 'woocommerce' ),
                'default' => 'WC-',
                'desc_tip'      => true,
            ),
            'debug_email' => array(
                'title' => __( 'Debug Email', 'woocommerce' ),
                'type' => 'email',
                'default' => '',
                'description' => __( 'Send copies of IPNs to this email address.', 'woocommerce' ),
            )
        );

    }

    public function get_api_args( $order ) {
        $api_args = array(
            'currency'     => $order->get_currency(),
	    'crypto' => get_post_meta( $order->get_id(), 'CoinMall Currency', true ),
            'invoice'      => $this->invoice_prefix . $order->get_order_number(),
            'ipn_url'      => $this->ipn_url,
            'ipn_data'     => array(),
            'item'         => sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() ),
            'key'          => $this->api_key,
            'pay_for_fee'  => $this->pay_for_fee,
            'lightning'    => $this->lightning
        );
        $api_args['ipn_data']['success_url'] = $this->get_return_url( $order );
        $api_args['ipn_data']['cancel_url'] =  $this->get_return_url( $order );
        if ($this->exclude_tax_and_shipping) {
            $api_args['amount']    = number_format( $order->get_total(), 8, '.', '' );
            $api_args['ipn_data']['shipping']  = 0.00;
            $api_args['ipn_data']['tax']       = 0.00;
        } else if ( wc_tax_enabled() && wc_prices_include_tax() ) {
            $api_args['amount']    = number_format( $order->get_total() - $order->get_total_shipping() - $order->get_shipping_tax(), 8, '.', '' );
            $api_args['ipn_data']['shipping']  = number_format( $order->get_total_shipping() + $order->get_shipping_tax() , 8, '.', '' );
            $api_args['ipn_data']['tax']       = 0.00;
        } else {
            $api_args['amount']   = number_format( $order->get_total() - $order->get_total_shipping() - $order->get_total_tax(), 8, '.', '' );
            $api_args['ipn_data']['shipping'] = number_format( $order->get_total_shipping(), 8, '.', '' );
            $api_args['ipn_data']['tax']      = $order->get_total_tax();
        }
        $api_args = apply_filters( 'woocommerce_coinmall_args', $api_args);
        return $api_args;
    }

    public function generate_form_url($order) {
        if ( $order->status != 'completed' && get_post_meta( $order->id, 'CoinMall Payment Status', true ) != 'Completed') {
            $order->update_status('pending', 'CoinMall Payment Initialised');
        }
        $url = $this->api_url."form?".http_build_query($this->get_api_args($order));
        return $url;
    }

    public function init_coinmall_payment($order) {
        $args = $this->get_api_args($order);
        $args['cmd'] = 'createPurchase';
        $hmac =  hash_hmac("sha256", http_build_query($args), $this->api_secret);
        $payment = wp_remote_post($this->api_url,array('body'=>$args,'headers'=>array(
            'CoinMall-API-hmac'=>$hmac
        )));
        $payment = json_decode($payment['body'],true);
        if ($this->check_ipn_request_is_valid($payment,false)) {
            update_post_meta($order->get_id(), 'CoinMall Address',$payment['address']);
            update_post_meta($order->get_id(), 'CoinMall Amount',$payment['amount']);
            update_post_meta($order->get_id(), 'CoinMall Expires At',$payment['expires_at']);
            update_post_meta($order->get_id(), 'CoinMall QR Code',$payment['qr']);
            if (!empty($payment['lightning'])) {
                update_post_meta($order->get_id(), 'CoinMall LN Node',$payment['lightning']['node']);
                update_post_meta($order->get_id(), 'CoinMall LN Invoice',$payment['lightning']['invoice']);
                update_post_meta($order->get_id(), 'CoinMall LN Node QR Code',$payment['lightning']['nodeQR']);
                update_post_meta($order->get_id(), 'CoinMall LN Invoice QR Code',$payment['lightning']['invoiceQR']);
            }
        }
        return $this->get_return_url($order);
    }

    public function check_ipn_response() {
        @ob_end_clean();
        if (!empty($_POST) && $this->check_ipn_request_is_valid($_POST) ) {
            $this->process_ipn_request($_POST);
        } else {
            die("CoinMall_WC_Payment_Gateway_Error");
        }
    }

    public function process_payment( $order_id ) {
        global $woocommerce;
        $order          = wc_get_order( $order_id );
        $order->update_status('on-hold', __( 'Awaiting CoinMall.com payment', 'woocommerce' ));
        if ($this->seamless_api) {
            $url = $this->init_coinmall_payment($order);
        } else {
            $url = $this->generate_form_url($order);
        }
        wc_reduce_stock_levels($order->get_id());
        $woocommerce->cart->empty_cart();
        return array(
            'result' 	=> 'success',
            'redirect'	=> $url,
        );
    }

    public function payment_fields(){
        global $woocommerce;
        $order_id = $woocommerce->session->order_awaiting_payment;
        $order = wc_get_order( $order_id );
        $currencies = get_available_coinmall_currencies();
        foreach ($currencies as $code=>$currency) {
            echo "<input type='radio' name='crypto_currency' value='{$code}'/>{$currency}&nbsp;&nbsp;";
        }
    }

    public function save_coinmall_payment_fields( $order_id){
        update_post_meta($order_id, 'CoinMall Currency',$_POST['crypto_currency']);
    }
    static function show_coinmall_fields_on_admin_page($order){
        $currency = get_post_meta( $order->get_id(), 'CoinMall Currency', true );
        echo '<p><strong>' . __('Payment Currency', 'woocommerce') . ': </strong>' . $currency . '</p>';
    }
    public function show_coinmall_fields_on_order_page($item_id,$order_item,$order){
        $currency = get_post_meta( $order->get_id(), 'CoinMall Currency', true );
        echo '<p><strong>' . __('CoinMall Currency', 'woocommerce') . ': </strong>' . $currency . '</p>';
    }

    private function generate_payment_form($order_id) {
        $wallet = get_post_meta( $order_id, 'CoinMall Address', true );
        $amount = get_post_meta( $order_id, 'CoinMall Amount', true );
        $currency = get_post_meta( $order_id, 'CoinMall Currency', true );
        $payment_status = get_post_meta($order_id, 'CoinMall Payment Status',true);
        $tx_id = get_post_meta($order_id, 'CoinMall Transaction ID',true);
        $expires_at = get_post_meta($order_id, 'CoinMall Expires At',true);
        $qr = get_post_meta($order_id, 'CoinMall QR Code',true);
        if ($wallet && $amount && $status != 'Completed') {
            $order = new WC_Order($order_id);
            $order_status = $order->get_status();
            if (time() > $expires_at && ($order_status == 'on-hold' || $order_status == 'pending')) {
                 echo '<p>'.__( 'Your invoice is expired. Please create new order to continue.', 'woocommerce' ).'</p>';
                 return;
            }
            if ($order_status == 'on-hold' || $order_status == 'pending') {
                $node_qr = get_post_meta($order_id, 'CoinMall LN Node QR Code',true);
                $invoice_qr = get_post_meta($order_id, 'CoinMall LN Invoice QR Code',true);
                if (!empty($node_qr)) { 
                    echo '<p>'.__( 'Please pay following LN Invoice:', 'woocommerce' ).'</p>';
                    echo '<p>'.$node_qr.'</p>';
                    echo '<p>'.$invoice_qr.'</p>';
                } else {
                    echo '<p>'.__( 'Please pay to following address:', 'woocommerce' ).'</p>';
                    echo '<p><strong>' . __('Pay to:', 'woocommerce') . ' </strong>' . $wallet . '</p>';
                    echo '<p>'.$qr.'</p>';
                }
                echo '<p><strong>' . __('Amount:', 'woocommerce') . ' </strong>' . sprintf("%10.8f",$amount/100000000) . ' '. $currency.'</p>';
                echo '<p><strong>' . __('Expires at:', 'woocommerce') . ' </strong>' . date("Y-m-d H:i:s",$expires_at).'</p>';
                echo '<p><a href="javascript:location.href=location.href" class="button wc-backward">Refresh</a></p>';
            }
        }
    }

    public function receipt_page($order_id) {
        $this->generate_payment_form($order_id);
    }
    private function checkHMAC() {
        $error_msg = 'invalid hmac.';
        $logged_in = false;
        if (!empty($_SERVER['HTTP_COINMALL_API_HMAC'])) {
            $request = file_get_contents('php://input');
            if (!empty($request)) {
                if (isset($_POST['key']) && $_POST['key'] == trim($this->api_key)) {
                    $hmac = hash_hmac("sha256", $request, trim($this->api_secret));
                    if ($hmac == $_SERVER['HTTP_COINMALL_API_HMAC']) {
                        $logged_in = true;
                    } else {
                        $error_msg = 'invalid hmac.';
                    }
                } else {
                    $error_msg = 'invalid key.';
                }
            } else {
                $error_msg = 'No data received.';
            }
        }
        return [$error_msg,$logged_in];
    }
    public function check_ipn_request_is_valid($data,$check_hmac=true) {
        if ($check_hmac) {
            list($error_msg,$logged_in) = $this->checkHMAC();
            if ($logged_in) {
                if (!empty($this->debug_email)) { wp_mail($this->debug_email, "CoinMall.com IPN received", str_replace("&","\n",http_build_query($data))); }
            }
        } else {
            $logged_in = true;
        }
        if ($logged_in) {
	          $order = $this->get_wc_order( $data['invoice'] );
            if (!empty($order)) {
                if ($data['from_currency'] == $order->get_currency()) {
                    if ($data['from_amount'] >= $order->get_total()) {
                        return true;
                    } else {
                        $error_msg = "Partial payment";
                    }
                } else {
                    $error_msg = "Wrong currency";
                }
            } else {
                $error_msg = "No such order: ".$data['invoice'];
            }
            if ($order) {
                $order->update_status('on-hold', sprintf( __( 'CoinMall IPN Error: %s', 'woocommerce' ), $error_msg ) );
            }
        }
        return $error_msg;
    }

    private function process_ipn_request($request) {
        global $woocommerce;
        if (!empty($request['invoice'])) {
            $order = $this->get_wc_order( $request['invoice'] );
            $this->log->add( 'coinmall', 'Order #'.$order->get_id().' payment status: ' . $request['status_text'] );
            $order->add_order_note('CoinMall Payment Status: '.$request['status_text']);
            if ( $order->status != 'completed' && get_post_meta( $order->get_id(), 'CoinMall Payment Status', true ) != 'Completed') {
                $this->update_order_info($order,$request);
                if ($request['status'] >= 0 && ($request['confirms'] > 0 || $this->allow_zero_confirm) && $request['amount_paid'] >= $request['amount']) {
                    update_post_meta( $order->get_id(), 'CoinMall Payment Status', 'Completed');
                    $order->payment_complete();
                } else if ($request['status'] < 0) {
                    $order->update_status('cancelled', 'CoinMall Payment Status: Cancelled ('.$request['status_text'].')');
                } else {
                    $order->update_status('pending', 'CoinMall Payment Status: Cancelled ('.$request['status_text'].')');
                }
            }
            die("CoinMall_Payment_Gateway_Success");
        }
    }

    private function update_order_info($order,$request) {
        if ( ! empty( $request['tx_id'] ) )
            update_post_meta( $order->id, 'CoinMall Transaction ID', $request['tx_id'] );
    }

    public function get_wc_order($order_key) {
        $order_id = str_replace($this->invoice_prefix,'',$order_key);
        $order = wc_get_order($order_id);
        if ($order) return $order;
        return false;
    }
}
