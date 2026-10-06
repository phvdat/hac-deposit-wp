<?php
if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}
if ( ! defined( 'ABSPATH' ) || class_exists( 'WC_MEcom_Gateway' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
    return;
}
require_once(plugin_dir_path(__FILE__) . 'utils.php');
class WC_MEcom_Gateway extends WC_Payment_Gateway {

    /**
     * Whether or not logging is enabled
     *
     * @var bool
     */
    public static $log_enabled = false;
    
    public static $mecom_paypal_is_inited = false;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    public static $log = false;

    public $productTitleSetting = 'last_word';

    public $userDefineProductTitle = 'ME';
    
    public $randomProductTitleList = '';

    public $invoice_prefix = '';

    public $debug = false;

    public $email = '';

    public $receiver_email = '';

    public $identity_token = '';

    public $intent = '';

    public $not_send_bill_address_to_paypal = 'no';

    public $paypal_button = '';

    public $paypal_button_position = '';

    public $sslverify = 'no';

    private static $instance = null;

    public static function load()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Class constructor, more about it in Step 3
     */
    public function __construct() {
        $this->id                 = 'mecom_paypal'; // payment gateway plugin ID
        $this->icon               = ''; // URL of the icon that will be displayed on checkout page near your gateway name
        $this->has_fields         = true;
        $this->method_title       = 'CardsShield Gateway PayPal';
        $this->order_button_text  = $this->get_option( 'checkout_button_content' ) ?: 'Continue to payment';
        $this->method_description = 'CardsShield PayPal proxy'; // will be displayed on the options page

        // gateways can support subscriptions, refunds, saved payment methods,
        // but in this tutorial we begin with simple payments
        $this->supports = [
            'products',
            'refunds',
        ];

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables.
        $this->title                  = $this->get_option( 'title' );
        $this->description            = $this->get_option( 'description' );
        $this->invoice_prefix         = $this->get_option( 'invoice_prefix' );
        $this->productTitleSetting    = $this->get_option( 'product_title_setting' );
        $this->userDefineProductTitle = $this->get_option( 'user_define_product_title' );
        $this->randomProductTitleList = $this->get_option( 'random_product_title_list' );
        $this->debug                  = 'yes' === $this->get_option( 'debug', 'no' );
        $this->email                  = $this->get_option( 'email' );
        $this->receiver_email         = $this->get_option( 'receiver_email', $this->email );
        $this->identity_token         = $this->get_option( 'identity_token' );
        $this->intent                 = $this->get_option( 'intent' );
        $this->not_send_bill_address_to_paypal = $this->get_option( 'not_send_bill_address_to_paypal' );
        $this->paypal_button = $this->get_option('paypal_button');
        $this->paypal_button_position = $this->get_option('paypal_button_position', OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT);
        $this->sslverify = $this->get_option('sslverify');

        self::$log_enabled = $this->debug;

        add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        // add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
        // add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
        // add_action( 'woocommerce_api_mecom-process-payment', array( $this, 'webhook' ) );

        if ( ! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
        }
        if ($this->enabled == 'yes') {
            add_filter('woocommerce_available_payment_gateways', [$this, 'check_cs_paypal_payment_gateways'], 10, 1);
        }
        add_filter( 'woocommerce_order_actions_start', [ $this, 'order_payment_status' ], 10, 1 );
        if(!self::$mecom_paypal_is_inited) {
            self::$mecom_paypal_is_inited = true;
            add_filter( 'woocommerce_order_button_text', [ $this, 'mecom_order_button_text' ], 20 );
            add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_pp_authorize_column' ], 10, 1 );
            add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_mecom_shield_url' ], 10, 1 );
            add_filter( 'manage_shop_order_posts_custom_column', [ $this, 'add_mecom_order_values' ], 10, 2 );
            if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
                add_filter( 'woocommerce_shop_order_list_table_columns', [ $this, 'add_pp_authorize_column' ], 10, 1 );
                add_filter( 'woocommerce_shop_order_list_table_columns', [ $this, 'add_mecom_shield_url' ], 10, 1 );
                add_action( 'woocommerce_shop_order_list_table_custom_column', function ($column, $wc_order) {
                    $this->add_mecom_order_values($column, $wc_order->get_id());
                }, 10, 2 );
            }
        }
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
      
        add_filter( 'woocommerce_order_actions', [$this, 'add_pp_order_actions'], 10, 1);

        add_action( 'woocommerce_order_action_cs_pp_capture_authorization_order', [$this, 'cs_pp_capture_authorization_order']);
        add_action( 'woocommerce_order_action_cs_pp_cancel_authorization_order', [$this, 'cs_pp_cancel_authorization_order']);
        add_action( 'woocommerce_order_action_cs_pp_reauthorize_authorization_order', [$this, 'cs_pp_reauthorize_authorization_order']);
        
    }

    /**
     * Plugin options, we deal with it in Step 3 too
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled'                   => [
                'title'       => 'Enable/Disable',
                'label'       => 'Enable CardsShield PayPal Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'paypal',
            ],
            'title'                     => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'PayPal',
                'desc_tip'    => true,
                'tab'         => 'paypal',
            ],
            'description'               => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => '',
                'css'      => 'width: 400px;resize: both;',
                'tab'         => 'paypal',
            ],
            'paypal_button' => [
                'title'       => 'Payment Button',
                'type'        => 'select',
                'description' => "<b>PayPal Standard:</b> PayPal Standard are static buttons with limited customization options.<br/> <b>Smart Button:</b> Smart Button provides different ways to customize the PayPal checkout button. Accepts alternative payment methods such as PayPal Credit, Venmo, and local funding sources.<br/> <b>PayPal Shield Hosted Checkout:</b> Redirects the customer to a secure checkout page hosted on the shield domain, then back to your store after payment.",
                'default'     => OPT_CS_PAYPAL_SETTING_CHECKOUT,
                'options'     => [
                    OPT_CS_PAYPAL_SETTING_CHECKOUT => 'Smart Button',
                    OPT_CS_PAYPAL_SETTING_STANDARD   => 'Paypal Standard',
                    OPT_CS_PAYPAL_SETTING_HOSTED   => 'PayPal Shield Hosted Checkout',
                ],
                'tab'         => 'paypal',
            ],
            'paypal_button_position' => [
                'title'       => 'Button position',
                'type'        => 'select',
                'description' => 'Default keeps the current Smart Button position. Inline renders the PayPal button inside payment fields on checkout.',
                'default'     => OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT,
                'options'     => [
                    OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT => 'Default',
                    OPT_CS_PAYPAL_BUTTON_POSITION_INLINE  => 'Inline',
                ],
                'tab'         => 'paypal',
            ],
            'checkout_button_content' => array(
                'title' => 'Checkout button text',
                'type' => 'textarea',
                'default' => 'Continue to payment',
                'tab'   => 'paypal',
            ),
            'payment_option_desc' => array(
                'title' => 'Payment option desc.',
                'type' => 'textarea',
                'default' => 'After clicking "Continue to payment", you will be redirected to next step to complete your purchase securely.',
                'tab'   => 'paypal',
            ),
            'enable_2_steps_smart_button'    => [
                'title'       => '2 Steps Smart Button',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => 'When enabled, Smart Button is shown only after customers click an intermediate PayPal-style button.',
                'default'     => 'no',
                'tab'         => 'paypal',
            ],
            'two_steps_smart_button_text' => [
                'title'       => '2 Steps Smart Button Text',
                'type'        => 'text',
                'description' => 'Text displayed inside the intermediate button before loading the Smart Button iframe.',
                'default'     => 'Pay with PayPal',
                'desc_tip'    => true,
                'tab'         => 'paypal',
            ],
            'card_icons' => array(
                'type' => 'multiselect',
                'title' => 'Icons',
                'class' => 'wc-enhanced-select',
                'default' => array('paypal'),
                'options' => array(
                    'visa' => 'Visa',
                    'paypal' => 'Paypal',
                    'mastercard' => 'MasterCard',
                    'jcb' => 'JCB',
                    'discover' => 'Discover',
                    'diners' => 'Diners Club',
                    'american_express' => 'American Express',
                ),
                'desc_tip'    => true,
                'description' => 'The selected icons will show customers which payment brands you accept.',
                'tab'         => 'paypal',
            ),
            'enabled_express_on_product_page'    => [
                'title'       => 'Express Checkout on product page',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'paypal',
            ],
            'enabled_express_on_cart_page'    => [
                'title'       => 'Express Checkout on cart page',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'paypal',
            ],
            'enabled_express_on_checkout_page'    => [
                'title'       => 'Express Checkout on checkout page',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'paypal',
            ],

            // ---------- Advanced Settings tab ----------
            'intent'                    => [
                'title'       => 'Payment Intent',
                'type'        => 'select',
                'class'       => [],
                'input_class' => [ 'wc-enhanced-select' ],
                'default'     =>  OPT_CS_PAYPAL_CAPTURE,
                'desc_tip'    => true,
                'description' => 'The intent to either capture payment immediately or authorize a payment for an order after order creation.',
                'options'     => [
                    OPT_CS_PAYPAL_CAPTURE   => 'Capture',
                    OPT_CS_PAYPAL_AUTHORIZE => 'Authorize',
                ],
                'tab'         => 'advanced',
            ],
            'soft_descriptor' => array(
                'title' => 'Soft Descriptors',
                'type' => 'text',
                'description' => 'Soft descriptors are limited to 22 characters',
                'default' => '',
                'tab'         => 'advanced',
            ),
            'invoice_prefix'            => [
                'title'       => __( 'Invoice Prefix', 'woocommerce-gateway-paypal-express-checkout' ),
                'type'        => 'text',
                'description' => __( 'Please enter a prefix for your invoice numbers. If you use your PayPal account for multiple stores ensure this prefix is unique as PayPal will not allow orders with the same invoice number.', 'woocommerce-gateway-paypal-express-checkout' ),
                'default'     => 'WC-',
                'desc_tip'    => true,
                'tab'         => 'advanced',
            ],
            'product_title_setting'     => [
                'title'       => 'Overwrite product title',
                'type'        => 'select',
                'description' => '',
                'default'     => 'last_word',
                'desc_tip'    => false,
                'options'     => [
                    'last_word'     => 'Use the last word',
                    'user_define'   => 'User define',
                    'keep_original' => 'Keep the original (Not recommended)'
                ],
                'tab'         => 'advanced',
            ],
            'user_define_product_title' => [
                'title'       => 'User define title',
                'type'        => 'text',
                'description' => 'This title will appear on the PayPal transaction as the product title when "Overwrite Product Title" is set to "User Defined".<br/><br/>You can define the title using the following shortcodes: <b>[order_id]</b>, <b>[customer_name]</b> &mdash; the customer\'s full first &amp; last name &mdash;, <b>[last_word]</b>, <b>[last_2_words]</b> &mdash; the last two words of the original product title &mdash;, <b>[rand_title_from_list]</b>, <b>[rand_title_from_shield]</b> &mdash; randomly selects a product title from this shield\'s WooCommerce catalog &mdash; and <b>[rand_N]</b> &mdash; generates a random string with <b>N</b> characters, where N is a number greater than 1.<br/><br/>Examples:<br/><b>Order #[order_id]</b><br/><b>[rand_10] product</b><br/><b>[last_word] product</b>',
                'default'     => '[order_id] [rand_12] item',
                'tab'         => 'advanced',
            ],
            'random_product_title_list' => [
                'title'       => 'Random title list',
                'type'        => 'textarea',
                'description' => 'Please enter a list of titles to randomize, separated by commas. For example: T-Shirt, Personalized Hoodie, Gift for dad',
                'default' => "Vintage Design, Birthday Gift, Personalized, New Collection, Original Design, Original Custom, Custom Design, Custom Lover Gift, Make Your Own, New Arrival, Custom Made, Attractive Color, Stand-out from crowd, Retro Style, Classic Design, Classic Style, Customized for You, Today's Pick, Vibrant Designer, Premium Color, Vacation Mood Style, Unisex Men and Women, Timeless Charm, Celebration Ready, Unique Touch, Fresh Drop, One-of-a-Kind, Tailored Creation, Bold Hue, Eye-Catching Look, Nostalgic Vibe, Elegant Craft, Modern Twist, Made for You, Daily Highlight, Bright Accent, Getaway Inspired, All-Gender Fit, Handcrafted Feel, Seasonal Favorite, Statement Piece, Chic Appeal, Everyday Essential, Artful Blend, Casual Cool, Distinctive Edge, Playful Design, Sleek Finish, Curated Style, Freshly Designed, Personal Flair, Trendy Pick, Classic Vibes, Vibrant Touch, Effortless Style, Special Edition, Creative Spin, Standout Piece, Colorful Charm, Modern Classic, Bespoke Beauty, Fun Find, Signature Look, Relaxed Elegance, Inspired Craft, Everyday Luxury, Unique Blend, Festive Flair, Simple Sophistication, Bold Creation, Retro Charm, Fresh Perspective, Custom Vibes, Lively Design, Timeless Pick, Crafted Comfort, Striking Style, Thoughtful Gift, New Wave, Subtle Shine, Original Twist, Easygoing Look, Vibrant Craft, Classic Touch, Made to Shine, Today’s Treasure, Dynamic Hue, Vacation Vibe, Universal Appeal, Handmade Charm, Seasonal Style, Eye-Popping Design, Cool Classic, Personal Pick, Fresh Craft, Sleek Design, Creative Hue, Retro Inspired, Bold Accent, Everyday Chic, Unique Craft, Festive Touch, Modern Edge, Tailored Look, Standout Hue, Playful Vibe, Timeless Craft, Vibrant Pick, Casual Charm, Artful Touch, New Spark, Subtle Elegance, Original Hue, Relaxed Style, Inspired Look, Daily Craft, Bright Style, Classic Find, Custom Edge, Fresh Charm, Bold Twist, Unique Vibe",
                'tab'         => 'advanced',
            ],
            'sync_tracking_plugin' => [
                'title'       => 'Sync tracking plugin',
                'type'        => 'select',
                'class'       => [],
                'input_class' => [ 'wc-enhanced-select' ],
                'default'     => OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING,
                'desc_tip'    => true,
                'description' => '',
                'options'     => [
                    OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING => '1. Advanced Shipment Tracking for WooCommerce',
                    OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING            => '2. Orders Tracking for WooCommerce',
                    OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI                 => '3. Dianxiaomi - WooCommerce ERP',
                ],
                'tab'         => 'advanced',
            ],
            'sync_tracking_automatic' => [
                'title'       => 'Sync tracking automatically',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'advanced',
            ],
            'transaction_logs_enable' => [
                'title'       => 'Transaction logs',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'advanced',
            ],
            'send_email_notice_to_admin' => [
                'title'       => 'Send email notification to admins',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
                'tab'         => 'advanced',
            ],
            'not_send_bill_address_to_paypal' => [
                'title'       => 'Do not send billing & shipping address to PayPal',
                'label'       => 'Check this if you are selling DIGITAL products',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'advanced',
            ],
             'disable_credit_card' => array(
                'title'             => 'Disable Credit Card for Checkout',
                'type'              => 'checkbox',
                'label'             => ' Yes',
                'default'           => 'no',
                'tab'               => 'advanced',
            ),
             'disable_credit_card_express' => array(
                'title'             => 'Disable Credit Card for Express Checkout (Product, Cart, Checkout page)',
                'type'              => 'checkbox',
                'label'             => ' Yes',
                'default'           => 'no',
                'tab'               => 'advanced',
            ),
            'disable_credit_card_express_on_product_page' => array(
                'title'             => 'Disable Credit Card for Express Checkout (Product page only)',
                'type'              => 'checkbox',
                'label'             => ' Yes',
                'default'           => 'no',
                'tab'               => 'advanced',
            ),
            'sslverify' => [
                'title'       => 'SSL verify',
                'label'       => 'Enable',
                'type'        => 'checkbox',
                'default'     => 'no',
                'tab'         => 'advanced',
            ],
            'custom_card_icon_css' => [
                'title'   => 'Custom Paypal icon css',
                'type'    => 'textarea',
                'default' => '/*
.mecom-paypal-payment-icon {
    width: 50px;
}
*/',
                'css' => 'width: 400px; min-height: 110px; resize: both;',
                'tab'     => 'advanced',
            ],

            // ---------- Credit Card Settings tab ----------
            'cc_enabled' => [
                'title'       => 'Enable/Disable',
                'label'       => 'Enable Credit / Debit Cards',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'tab'         => 'cc',
            ],
            'cc_force_button' => [
                'title'       => 'Render mode',
                'label'       => 'Always use the PayPal Card Button (skip inline Card Fields form)',
                'type'        => 'checkbox',
                'description' => 'When enabled, the Credit / Debit Cards radio renders the PayPal hosted card button instead of the inline Card Fields form, even if the account supports Card Fields.',
                'default'     => 'no',
                'desc_tip'    => true,
                'tab'         => 'cc',
            ],
            'cc_title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title which the user sees during checkout.',
                'default'     => 'Credit / Debit Cards',
                'desc_tip'    => true,
                'tab'         => 'cc',
            ],
            'cc_description' => [
                'title'       => 'Description',
                'type'        => 'text',
                'description' => 'This controls the description which the user sees during checkout.',
                'default'     => 'Pay with Credit / Debit Cards',
                'desc_tip'    => true,
                'tab'         => 'cc',
            ],
            'cc_card_first' => [
                'title'       => 'Card First',
                'label'       => 'Display the credit/debit card option above the PayPal button, making it the primary payment choice for customers.',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
                'desc_tip'    => true,
                'tab'         => 'cc',
            ],
            'cc_icons' => [
                'type'     => 'multiselect',
                'title'    => 'Icons',
                'class'    => 'wc-enhanced-select',
                'default'  => [ 'visa', 'american_express', 'discover', 'mastercard', 'jcb' ],
                'options'  => [
                    'visa'             => 'Visa',
                    'american_express' => 'Amex',
                    'discover'         => 'Discover',
                    'mastercard'       => 'MasterCard',
                    'jcb'              => 'JCB',
                    'diners'           => 'Diners Club',
                ],
                'desc_tip'    => true,
                'description' => 'The selected icons will show customers which card brands you accept.',
                'tab'         => 'cc',
            ],
        ];
    }

    /**
     * Return fields belonging to a given tab (paypal|advanced|cc).
     */
    public function get_form_fields_by_tab( $tab ) {
        $fields = $this->get_form_fields();
        $result = [];
        foreach ( $fields as $key => $field ) {
            $field_tab = isset( $field['tab'] ) ? $field['tab'] : 'paypal';
            if ( $field_tab === $tab ) {
                $result[ $key ] = $field;
            }
        }
        return $result;
    }

    /**
     * Render settings screen with 3 tabs. Each tab renders its own form table,
     * but they live inside the single WC gateway settings form so one Save
     * Changes button persists all tabs at once to the same option key.
     */
    public function admin_options() {
        $tabs = [
            'advanced' => 'General Settings',
            'paypal'   => 'PayPal Settings',
            'cc'       => 'Credit Card Settings',
        ];
        $active = isset( $_GET['cs_pp_tab'] ) && isset( $tabs[ $_GET['cs_pp_tab'] ] ) ? $_GET['cs_pp_tab'] : 'advanced';

        // Preserve the native WC back-header (the "< CardsShield Gateway PayPal" link).
        if ( function_exists( 'wc_back_header' ) ) {
            wc_back_header(
                $this->get_method_title(),
                __( 'Return to payments', 'woocommerce' ),
                admin_url( 'admin.php?page=wc-settings&tab=checkout' )
            );
        } else {
            echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
        }
        echo wp_kses_post( wpautop( $this->get_method_description() ) );

        echo '<div class="cs-pp-config-shields-wrap" style="margin:12px 0;">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=mecom-gateway-paypal' ) ) . '" class="button">' . esc_html__( 'Config Shields', 'custom_paypal' ) . '</a>';
        echo '</div>';

        echo '<div class="cs-pp-tabs">';
        echo '<ul class="cs-pp-tabs-nav" role="tablist">';
        foreach ( $tabs as $slug => $label ) {
            $class = $slug === $active ? 'cs-pp-tab-link active' : 'cs-pp-tab-link';
            echo '<li><a href="#" class="' . esc_attr( $class ) . '" data-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</a></li>';
        }
        echo '</ul>';

        foreach ( $tabs as $slug => $label ) {
            $hidden = $slug === $active ? '' : ' style="display:none;"';
            echo '<div class="cs-pp-tab-panel" data-tab="' . esc_attr( $slug ) . '"' . $hidden . '>';
            echo '<table class="form-table">';
            echo $this->generate_settings_html( $this->get_form_fields_by_tab( $slug ), false );
            echo '</table>';
            echo '</div>';
        }
        echo '</div>';

        echo '<input type="hidden" name="cs_pp_active_tab" id="cs_pp_active_tab" value="' . esc_attr( $active ) . '" />';
    }

    /**
     * Screen button Field
     */
    public function generate_config_proxies_button_html( $key, $value ) {
        ?>
        <tr valign="top">
            <td colspan="2" class="forminp forminp-<?php echo sanitize_title( $value['type'] ) ?>">
                <a href="<?php echo admin_url( 'admin.php?page=mecom-gateway-paypal' ); ?>"
                   class="button"><?php _e( 'Config Shields', 'custom_paypal' ); ?></a>
            </td>
        </tr>
        <?php
    }

    /**
     * Check if this gateway is enabled and available in the user's country.
     *
     * @return bool
     */
    public function is_valid_for_use() {
        return in_array(
            get_woocommerce_currency(),
            apply_filters(
                'woocommerce_paypal_supported_currencies',
                [
                    'AUD',
                    'BRL',
                    'CAD',
                    'MXN',
                    'NZD',
                    'HKD',
                    'SGD',
                    'USD',
                    'EUR',
                    'JPY',
                    'TRY',
                    'NOK',
                    'CZK',
                    'DKK',
                    'HUF',
                    'ILS',
                    'MYR',
                    'PHP',
                    'PLN',
                    'SEK',
                    'CHF',
                    'TWD',
                    'THB',
                    'GBP',
                    'RMB',
                    'RUB',
                    'INR'
                ]
            ),
            true
        );
    }

    /**
     * Use the configured "Checkout button text" for the place-order button when our gateway is chosen.
     * WooCommerce does not apply the gateway's order_button_text to the main place-order button by default.
     */
    public function mecom_order_button_text( $text ) {
        if ( empty( $this->order_button_text ) ) {
            return $text;
        }
        $chosen = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
        if ( empty( $chosen ) && WC()->payment_gateways ) {
            $available = WC()->payment_gateways->get_available_payment_gateways();
            $chosen    = ! empty( $available ) ? current( array_keys( $available ) ) : '';
        }
        return $chosen === $this->id ? $this->order_button_text : $text;
    }

    /**
     * Get_icon function.
     *
     * @return string
     * @version 4.0.0
     * @since 1.0.0
     */
    public function get_icon() {
         $loaderHtml = '<div id="cs-pp-loader">
                  <div class="cs-pp-spinnerWithLockIcon cs-pp-spinner" aria-busy="true">
                      <p>We\'re redirecting you to PayPal...<br/>Please <b>DO NOT</b> close this page!</p>
                  </div>
            </div>
            <div id="cs-pp-loader-credit">
                  <div class="cs-pp-spinnerWithLockIcon cs-pp-spinner" aria-busy="true">
                      <p>We\'re processing your payment...<br/>Please <b>DO NOT</b> close this page!</p>
                  </div>
            </div>
            ';
         $icons = $this->get_option('card_icons');
        $icons_str = '';
        if (is_array($icons)) {
            foreach ($icons as $index => $icon) {
                if ($index > 3) break;
                $icons_str = '<img class="mecom-paypal-payment-icon" src="' . cs_paypal_icon_src('/assets/images/icons/' . $icon . '.svg', __FILE__, OPT_MECOM_PAYPAL_VERSION) . '" style="float: right; border-radius: 2px; max-height: 25px;padding-top: 2px; margin-right: 4px"/>' . $icons_str;
            }
            if (count($icons) > 4) {
                $icons_str = '<img class="mecom-paypal-payment-icon" src="' . cs_paypal_icon_src('/assets/images/icons/' . (count($icons) - 4) . '.svg', __FILE__, OPT_MECOM_PAYPAL_VERSION) . '" style="float: right; border-radius: 2px; max-height: 25px;padding-top: 2px; margin-right: 4px"/>' . $icons_str;
            }
        }
         $icons_str .= $loaderHtml;
         $icons_str .= '<style type="text/css">' . $this->get_option('custom_card_icon_css') . '</style>';

        return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
    }

    /**
     * Load admin scripts.
     *
     * @since 3.3.0
     */
    public function admin_scripts() {
        $screen    = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
            return;
        }

        wp_enqueue_script( 'woocommerce_paypal_admin', plugins_url('assets/js/payment_settings.js', __FILE__), array(), OPT_MECOM_PAYPAL_VERSION, true );
        wp_enqueue_style( 'cs_pp_settings_tabs', plugins_url( 'assets/css/settings-tabs.css', __FILE__ ), [], OPT_MECOM_PAYPAL_VERSION );
        wp_enqueue_script( 'cs_pp_settings_tabs', plugins_url( 'assets/js/settings-tabs.js', __FILE__ ), [ 'jquery' ], OPT_MECOM_PAYPAL_VERSION, true );
    }

    public function get_paypal_button_position() {
        return $this->get_option( 'paypal_button_position', OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT );
    }

    public function is_inline_checkout_button_enabled() {
        return $this->paypal_button === OPT_CS_PAYPAL_SETTING_CHECKOUT
            && $this->get_paypal_button_position() === OPT_CS_PAYPAL_BUTTON_POSITION_INLINE;
    }

    public function render_checkout_smart_button_markup( $is_inline = false ) {
        $nextProxyUrl = WC()->session->get( 'mecom-paypal-proxy-active-url' );
        $containerClass = $is_inline ? 'mecom-paypal-credit-form-container-inline' : '';
        $enableTwoStepsSmartButton = $this->get_option( 'enable_2_steps_smart_button' ) === 'yes';
        $twoStepsSmartButtonText = $this->get_option( 'two_steps_smart_button_text' ) ?: 'Pay with PayPal';
        ?>
        <div id="mecom-paypal-button-setting" data-value="<?= esc_attr( $this->paypal_button ) ?>" style="display:none"></div>
        <div id="mecom-paypal-button-setting-context" data-value="checkout_page" style="display:none"></div>
        <div id="mecom-paypal-2-step-enabled" data-value="<?= $enableTwoStepsSmartButton ? 'yes' : 'no' ?>" style="display:none"></div>
        <div id="mecom-paypal-2-step-text" data-value="<?= esc_attr( $twoStepsSmartButtonText ) ?>" style="display:none"></div>
        <div id="mecom-paypal-inline-mode" data-value="<?= $is_inline ? 'yes' : 'no' ?>" style="display:none"></div>
        <?php
        if ( $nextProxyUrl && $this->paypal_button === OPT_CS_PAYPAL_SETTING_CHECKOUT ) {
            $intentIframe = strtolower( $this->get_option( 'intent' ) );
            $proxyFullUrl = $nextProxyUrl . '/checkout?session=' . csPpGenerateRandomString( 35 ) . '&checkout=yes&intent=' . $intentIframe . '&currency=' . get_woocommerce_currency();
            if ( $this->get_option( 'disable_credit_card' ) === 'yes' ) {
                $proxyFullUrl .= '&disable_credit_card=1';
            }
            if ( $this->get_option( 'disable_credit_card_express' ) === 'yes' ) {
                $proxyFullUrl .= '&disable_credit_card_express=1';
            }
            if ( $this->get_option( 'cc_enabled' ) === 'yes' ) {
                $proxyFullUrl .= '&cc_enabled=1';
            }
            ?>
            <?php if ( $enableTwoStepsSmartButton && $is_inline ) : ?>
                <div id="mecom-paypal-2-step-trigger-container" class="form-row place-order mecom-paypal-2-step-trigger-container" style="display:none"></div>
            <?php endif; ?>
            <div id="mecom-paypal-credit-form-container" class="<?= esc_attr( $containerClass ) ?>" style="display:none">
                <iframe id="payment-paypal-area" referrerpolicy="no-referrer"
                        src="<?= esc_url( $proxyFullUrl ) ?>"
                        height="55" frameBorder="0" style="width: 100%"></iframe>
                <div style="display: none" id="mecom-paypal-order-intent" data-value="<?= esc_attr( strtoupper( $this->get_option( 'intent' ) ) ) ?>"></div>
                <div style="display: none" id="mecom-paypal-store-invoice-prefix" data-value="<?= esc_attr( $this->invoice_prefix ) ?>"></div>
            </div>
            <?php
        }
    }

    /**
     * You will need it if you want your custom credit card form, Step 4 is about it
     */
    public function payment_fields() {
        if ($this->description) {
            // Keep the checkout description visible for both default and inline button layouts.
            echo '<div class="mecom-paypal-checkout-description">' . wpautop(wp_kses_post($this->description)) . '</div>';
        }
        if(isset($_GET['pay_for_order']) && get_query_var('order-pay')) {
            $this->mecom_pp_generate_input_order();                        
            $purchaseUnits = get_purchase_unit_from_order(wc_get_order(get_query_var('order-pay')));
        } else {
            $purchaseUnits = get_purchase_unit_from_cart(WC()->cart);
        }
        if ($this->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
            ?>
                <input style="display:none;" name="mecom-paypal-payment-order-id" />
                <input style="display:none;" name="mecom-paypal-payment-shield-url" value="<?= esc_attr( WC()->session->get('mecom-paypal-proxy-active-url') ) ?>" />
                <input style="display:none;" name="mecom-paypal-payment-shield-id" value="<?= esc_attr( WC()->session->get('mecom-paypal-proxy-active-id') ) ?>" />
                <script>
                    window.mecom_paypal_checkout_purchase_units = <?= json_encode([$purchaseUnits]) ?>;
                    window.mecom_paypal_currency_code = '<?= get_woocommerce_currency() ?>';
                    window.mecom_paypal_shield_url = '<?= esc_js( WC()->session->get('mecom-paypal-proxy-active-url') ) ?>';
                    window.mecom_paypal_shield_id = '<?= esc_js( WC()->session->get('mecom-paypal-proxy-active-id') ) ?>';
                </script>
            <?php
            if ( $this->is_inline_checkout_button_enabled() ) {
                $this->render_checkout_smart_button_markup( true );
            }
        } else {
            ?>
                <div class="cs-pp-redirect-svg-container">
                    <img src="<?= plugins_url('/assets/images/hosted-checkout-page/redirect-pp.svg', __FILE__) ?>"
                         style="width: 100%; max-height: 130px; margin-left: 20px"/>
                </div>
                <div class="cs-pp-payment-option-container">
                    <?= $this->get_option('payment_option_desc') ?>
                </div>
            <?php
        }
        if ($this->get_option('not_send_bill_address_to_paypal') === 'yes') {
            ?>
                <div id="cs_not_send_bill_address_to_paypal"></div>
            <?php
        }
    }
    function mecom_pp_generate_input_order() {
        $order = wc_get_order(get_query_var('order-pay'));
        $billingFirstName = $order->get_billing_first_name();
        $billingLastName =  $order->get_billing_last_name();
        $billingEmail =  $order->get_billing_email();
        $billingPhone =  $order->get_billing_phone();
        $billingAddress1 = $order->get_billing_address_1();
        $billingAddress2 = $order->get_billing_address_2();
        $billingCity = $order->get_billing_city();
        $billingCountry = $order->get_billing_country();
        $billingPostCode = $order->get_billing_postcode();
        $billingState = $order->get_billing_state();
        ?>
            <input id="billing_first_name" value="<?=$billingFirstName?>" style="display: none"/>
            <input id="billing_last_name" value="<?=$billingLastName?>" style="display: none"/>
            <input id="billing_email" value="<?=$billingEmail?>" style="display: none"/>
            <input id="billing_phone" value="<?=$billingPhone?>" style="display: none"/>
            <input id="billing_address_1" value="<?=$billingAddress1?>" style="display: none"/>
            <input id="billing_address_2" value="<?=$billingAddress2?>" style="display: none"/>
            <input id="billing_city" value="<?=$billingCity?>" style="display: none"/>
            <input id="billing_country" value="<?=$billingCountry?>" style="display: none"/>
            <input id="billing_postcode" value="<?=$billingPostCode?>" style="display: none"/>
            <input id="billing_state" value="<?=$billingState?>" style="display: none"/>
            <div id="cs_pay_for_order_page" data-value="<?=get_query_var('order-pay')?>"></div>
        <?php
    }

    /*
     * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
     */
    public function payment_scripts() {
        // we need JavaScript to process a token only on cart/checkout pages, right?
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }

        // if our payment gateway is disabled, we do not have to enqueue JS too
        if ( 'no' === $this->enabled ) {
            return;
        }

        wp_register_style( 'mecom_styles', plugins_url( 'assets/css/styles.css', __FILE__ ) . '?v=' . uniqid(), []);
        wp_enqueue_style( 'mecom_styles' );

                
        wp_register_script( 'mecom_js_sha1', plugins_url( '/assets/js/sha1.js', __FILE__ ) . '?v=' . uniqid(), []);
        wp_enqueue_script( 'mecom_js_sha1' );
        
        wp_register_script( 'mecom_js', plugins_url( '/assets/js/checkout_hook.js', __FILE__ ) . '?v=' . uniqid(), [ 'jquery' ] );
        wp_enqueue_script( 'mecom_js' );
        wp_localize_script( 'mecom_js', 'csPaypalSecurity', ['rotateNonce' => wp_create_nonce('mecom_paypal_rotate_shield')] );

        if ( $this->get_option( 'cc_enabled' ) === 'yes' ) {
            wp_register_script( 'mecom_cf_js', plugins_url( '/assets/js/cardfields-checkout.js', __FILE__ ) . '?v=' . OPT_MECOM_PAYPAL_VERSION, [ 'jquery' ], null, true );
            wp_enqueue_script( 'mecom_cf_js' );
        }
    }

    /*
      * Fields validation, more in Step 5
     */
    public function validate_fields() {

//        if ( empty( $_POST['billing_first_name'] ) ) {
//            wc_add_notice( 'First name is required!', 'error' );
//
//            return false;
//        }

        return true;

    }

    /*
     * We're processing the payments here, everything about it is in Step 5
     */
    public function process_payment( $order_id ) {
        global $woocommerce;
        // we need it to get any order details
        $order = wc_get_order($order_id);
        csPaypalStoreCustomerIp($order);
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        // ENTRY breadcrumb: proves process_payment ran + which gateway. wphead_context null => the checkout
        // page never executed wp_head (served from full-page cache) so no shield reached the session.
        csPaypalErrorLog([
            'order_id'       => $order_id,
            'gateway'        => $this->id,
            'endpoint_mode'  => $isEnableEndpointMode,
            'wphead_context' => WC()->session ? WC()->session->get('mecom-paypal-wphead-context') : null,
            'ua'             => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120),
            'is_ajax'        => (defined('DOING_AJAX') && DOING_AJAX) ? 1 : 0,
        ], 'PP_TRACE process_payment ENTRY (mecom_paypal)');

        // Prefer shield identifiers posted by iframe (shield that actually created pp_order_id)
        // over session active values which can be rotated between iframe render and form submit.
        $postedShieldUrl = isset($_POST['mecom-paypal-payment-shield-url'])
            ? esc_url_raw(wp_unslash($_POST['mecom-paypal-payment-shield-url']))
            : '';
        $postedShieldId = isset($_POST['mecom-paypal-payment-shield-id'])
            ? sanitize_text_field(wp_unslash($_POST['mecom-paypal-payment-shield-id']))
            : '';

        if ($isEnableEndpointMode) {
            // Endpoint mode: only URL identifies the shield (id is always null)
            $isValidPostedUrl = $postedShieldUrl !== ''
                && wp_http_validate_url($postedShieldUrl)
                && parse_url($postedShieldUrl, PHP_URL_SCHEME) === 'https';
            $shieldUrl = $isValidPostedUrl
                ? $postedShieldUrl
                : WC()->session->get('mecom-paypal-proxy-active-url');
            $activatedProxy = $shieldUrl ? ['id' => null, 'url' => $shieldUrl] : null;
        } else {
            // Non-endpoint mode: lookup by id in admin-configured proxies list
            $proxiesList = get_option(OPT_MECOM_PAYPAL_PROXIES, []);
            $proxyId = $postedShieldId !== ''
                ? $postedShieldId
                : WC()->session->get('mecom-paypal-proxy-active-id');
            $activatedProxy = findActivatedProxyDataById($proxiesList, $proxyId);
        }
        
        if ( $activatedProxy === null ) {
            // Cache-proof fallback: the checkout page can be served from full-page cache (Kinsta/Cloudflare),
            // so cs_pp_action_wp_head never runs and no shield lands in the session / hidden fields. This POST
            // is never cached, so resolve a shield here the same way wp_head would. Only runs when the posted
            // fields AND the session are empty, so it never overrides a shield already chosen at render.
            if ( $isEnableEndpointMode ) {
                $fallbackOrderKey = WC()->session ? WC()->session->get( 'mecom-paypal-processing-order-key' ) : '';
                if ( empty( $fallbackOrderKey ) ) {
                    $fallbackOrderKey = csPaypalGenerateProcessingOrderKey();
                    if ( WC()->session ) {
                        WC()->session->set( 'mecom-paypal-processing-order-key', $fallbackOrderKey );
                    }
                }
                $fallbackUrl = csEndpointGetShieldPaypalToProcess( $fallbackOrderKey, $order->get_total() );
                if ( ! empty( $fallbackUrl ) ) {
                    $activatedProxy = [ 'id' => null, 'url' => $fallbackUrl ];
                }
            } else {
                $fallbackProxy = get_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, null );
                if ( isEnabledAmountRotation() && ( empty( $fallbackProxy ) || ! isPayableProxy( $fallbackProxy, $order->get_total() ) ) ) {
                    $fallbackProxy = getNextProxyAmountRotation( $fallbackProxy, $order->get_total() );
                }
                if ( ! empty( $fallbackProxy['url'] ) ) {
                    $activatedProxy = $fallbackProxy;
                }
            }
            if ( $activatedProxy !== null && WC()->session ) {
                WC()->session->set( 'mecom-paypal-proxy-active-url', $activatedProxy['url'] );
                WC()->session->set( 'mecom-paypal-proxy-active-id', $activatedProxy['id'] );
                csPaypalErrorLog( [
                    'order_id' => $order_id,
                    'url'      => $activatedProxy['url'],
                ], 'PP_TRACE process_payment: shield RESCUED via fallback (session empty / page cached)' );
            }
        }

        if ( $activatedProxy === null ) {
            // Genuine no-shield bail (even the fallback found nothing) — full context for root-cause trace.
            csPaypalErrorLog([
                'order_id'       => $order_id,
                'endpoint_mode'  => $isEnableEndpointMode,
                'posted_url'     => $postedShieldUrl,
                'posted_id'      => $postedShieldId,
                'session_url'    => WC()->session ? WC()->session->get('mecom-paypal-proxy-active-url') : null,
                'wphead_context' => WC()->session ? WC()->session->get('mecom-paypal-wphead-context') : null,
                'proxies_count'  => $isEnableEndpointMode ? null : count(get_option(OPT_MECOM_PAYPAL_PROXIES, [])),
                'post_ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120),
            ], 'PP_TRACE process_payment BAIL: no shield even after fallback');
            wc_add_notice( 'We cannot process your payment right now, please try another payment method.[12]', 'error' );

            return [
                'result'   => 'fail',
                'redirect' => ''
            ];
        }
        $getActivateProxyUrl = $activatedProxy['url'];
        $order->update_meta_data( METAKEY_PAYPAL_PROCESSING_ORDER_KEY, WC()->session->get('mecom-paypal-processing-order-key'));
        $order->update_meta_data( METAKEY_PAYPAL_PROXY_URL, $getActivateProxyUrl);
        $order->update_meta_data('_shield_payment_method', 'paypal');
        $order->update_meta_data('_shield_payment_url', $getActivateProxyUrl);
        $order->update_meta_data( METAKEY_PAYPAL_PROXY_ID, $activatedProxy['id']);
        $order->update_meta_data( METAKEY_CS_PAYPAL_INTENT, $this->intent);
        $order->save_meta_data();
        // Create order data
        $orderData = [];
        // Shipping
        $orderData["shipping"]    = $order->get_shipping_total();
        $orderData["sub_total"]   = $order->get_subtotal();
        $orderData["total"]       = $order->get_total();
        $orderData["total_items"] = $order->get_item_count();
        $orderData["discount"]    = $order->get_discount_total();
        if ( round($orderData["total"], 2) != round($orderData["sub_total"] + floatval($orderData["shipping"]) - floatval($orderData["discount"]), 2)) {
            $orderData["sub_total"] = round($orderData["total"] - floatval($orderData["shipping"]) + floatval($orderData["discount"]), 2);
        }

        $shippingName     = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
        $shippingAddress1 = $order->get_shipping_address_1();
        $shippingAddress2 = $order->get_shipping_address_2();
        $shippingCity     = $order->get_shipping_city();
        $shippingCountry  = $order->get_shipping_country();
        $shippingPostCode = $order->get_shipping_postcode();
        $shippingState    = $order->get_shipping_state();

        // Billing
        $billingName     = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
        $billingAddress1 = $order->get_billing_address_1();
        $billingAddress2 = $order->get_billing_address_2();
        $billingCity     = $order->get_billing_city();
        $billingCountry  = $order->get_billing_country();
        $billingPostCode = $order->get_billing_postcode();
        $billingState    = $order->get_billing_state();
        $billingEmail    = $order->get_billing_email();

        $shippingName     = ( empty( $order->get_shipping_first_name() ) && empty( $order->get_shipping_last_name() ) ) ? $billingName : $shippingName;
        $shippingAddress1 = empty( $shippingAddress1 ) ? $billingAddress1 : $shippingAddress1;
        $shippingAddress2 = empty( $shippingAddress2 ) ? $billingAddress2 : $shippingAddress2;
        $shippingCity     = empty( $shippingCity ) ? $billingCity : $shippingCity;
        $shippingCountry  = empty( $shippingCountry ) ? $billingCountry : $shippingCountry;
        $shippingPostCode = empty( $shippingPostCode ) ? $billingPostCode : $shippingPostCode;
        $shippingState    = empty( $shippingState ) ? $billingState : $shippingState;


        $orderData["shipping_info"] = "shipping_address[name]=$shippingName&shipping_address[address_line1]=$shippingAddress1&shipping_address[address_line2]=$shippingAddress2&shipping_address[address_city]=$shippingCity&shipping_address[address_country]=$shippingCountry&shipping_address[address_zip]=$shippingPostCode&shipping_address[address_state]=$shippingState";
        $orderData["billing_info"]  = "billing[name]=$billingName&billing[address_line1]=$billingAddress1&billing[address_line2]=$billingAddress2&billing[address_city]=$billingCity&billing[address_country]=$billingCountry&billing[address_zip]=$billingPostCode&billing[email]=$billingEmail&billing[address_state]=$billingState";

        $currency = $order->get_currency();

        // Log processing proxyUrl
        $order->add_order_note( sprintf( __( 'Starting checkout with PayPal proxy %s', 'mecom' ), $getActivateProxyUrl ), 0, false );

        $order_items = $order->get_items();
        $ppItems = [];
        foreach ( $order_items as $it ) {
            $product = wc_get_product( $it->get_product_id() );
            $product_name = mb_substr( $this->getProductTitle( $product->get_title() , $order->get_id()), 0, 127 );
            $quantity = (int) $it->get_quantity();
            $unit_price = round( (float) $order->get_item_subtotal( $it, false ), 2 );
            $ppItems[] = [
                "name"     => $product_name,
                "quantity" => $quantity,
                "total"    => $unit_price,
            ];
        }

        $orderData["items"] = $ppItems;
        $purchaseUnits = get_purchase_unit_from_order($order);
        if ($this->get_option('not_send_bill_address_to_paypal') === 'yes') {
            unset($purchaseUnits['shipping']['address']);
        }
        $csInvoicePrefix = csPaypalResolveInvoicePrefix($this->invoice_prefix, $order);
        $orderData["invoice_id"]    = $csInvoicePrefix . $order->get_order_number();
        $orderData["store_invoice_prefix"] = $csInvoicePrefix;
        $orderData["merchant_site"] = get_home_url();
        $orderData["currency"]      = $currency;
        if (!empty($_POST['mecom-paypal-payment-order-id'])) {
            set_transient('CS_PAYPAL_ORDER_ID_REF_CS_ORDER_ID' . $_POST['mecom-paypal-payment-order-id'], $order->get_id(), 86400); // 1 days
        }
        if ($this->paypal_button === OPT_CS_PAYPAL_SETTING_HOSTED) {
            // Build the same `payer` object the Smart Button sends to PayPal.
            $hostedPayer = [];
            if ($order->get_billing_email()) {
                $hostedPayer['email_address'] = $order->get_billing_email();
                if ($order->get_billing_first_name() && $order->get_billing_last_name()) {
                    $hostedPayer['name'] = [
                        'given_name' => $order->get_billing_first_name(),
                        'surname' => $order->get_billing_last_name(),
                    ];
                }
            }
            if ($order->get_billing_country()) {
                $hostedPayer['address'] = array_filter([
                    'country_code' => $order->get_billing_country(),
                    'address_line_1' => $order->get_billing_address_1(),
                    'address_line_2' => $order->get_billing_address_2(),
                    'admin_area_1' => $order->get_billing_state(),
                    'admin_area_2' => $order->get_billing_city(),
                    'postal_code' => $order->get_billing_postcode(),
                ], function ($v) { return $v !== '' && $v !== null; });
            }
            $hostedPayerPhone = preg_replace('/[^0-9]/', '', (string) $order->get_billing_phone());
            if ($hostedPayerPhone) {
                $hostedPayer['phone'] = ['phone_type' => 'HOME', 'phone_number' => ['national_number' => $hostedPayerPhone]];
            }
            $hostedPayload = [
                'order_id' => $order_id,
                'merchant_site' => get_home_url(),
                'intent' => $this->intent,
                'bfp' => WC()->session->get('mecom-paypal-browser-fingerprint'),
                'not_send_bill_address_to_paypal' => $this->not_send_bill_address_to_paypal,
                'store_invoice_prefix' => $csInvoicePrefix,
                'purchase_units' => $purchaseUnits,
                'payer' => $hostedPayer,
                'display' => getCsPaypalHostedDisplayFromWcOrder($order),
                'whitelist' => [
                    'billing_email' => $order->get_billing_email(),
                    'billing_zip' => $order->get_billing_postcode(),
                    'billing_country' => $order->get_billing_country(),
                    'billing_state' => $order->get_billing_state(),
                    'billing_city' => $order->get_billing_city(),
                    'customer_ip' => csPaypalGetClientIP(),
                ],
                'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
            ];
            csPaypalDebugLog($hostedPayload, 'PP_HOSTED create-session request');
            $order->update_meta_data('_cs_paypal_checkout_page', 'hosted');
            $order->save_meta_data();
            $proxyProcess = wp_remote_post($getActivateProxyUrl . "?rest_route=/cs/paypal-hosted-create-session", [
                'sslverify' => csPaypalGetSSLVerifyStatus(),
                'timeout' => 60,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($hostedPayload),
            ]);
            csPaypalDebugLog(['order_id' => $order_id, 'response' => wp_remote_retrieve_body($proxyProcess)], 'PP_HOSTED create-session response');
            if (is_wp_error($proxyProcess)) {
                csPaypalErrorLog($proxyProcess, "pp hosted create session error");
                wc_add_notice(__('We cannot process your payment right now, please try another payment method.[22]', 'mecom'), 'error');
                $order->add_order_note(sprintf(__('Paypal hosted checkout ERROR by proxy %s, ERROR message: %s', 'mecom'),
                    $getActivateProxyUrl, 'Create hosted session request failed'));
                $order->update_status('failed');
                forceRotateShield();
                return false;
            }
            $data = json_decode(wp_remote_retrieve_body($proxyProcess));
            if (isset($data->status) && $data->status === 'success' && !empty($data->url)) {
                parse_str(parse_url($data->url, PHP_URL_QUERY), $hostedQuery);
                $order->update_meta_data('_cs_paypal_hosted_session', $hostedQuery['session'] ?? '');
                $order->update_meta_data('_cs_paypal_hosted_proxy_url', $getActivateProxyUrl);
                // Hosted checkout completes server-to-server (buyer-return / async webhook) where the
                // WC session is gone, so persist the selected shield on the order now — the amount
                // rotation at completion reads it from here to credit the right endpoint shield.
                if ($isEnableEndpointMode) {
                    $csHostedOrderKey = WC()->session ? WC()->session->get('mecom-paypal-processing-order-key') : '';
                    $csHostedShield = ($csHostedOrderKey && WC()->session)
                        ? WC()->session->get("csEndpointGetShieldPaypalToProcessValue_$csHostedOrderKey")
                        : '';
                    if (!empty($csHostedShield)) {
                        $order->update_meta_data('_cs_paypal_hosted_shield_processing', $csHostedShield);
                    }
                }
                $order->save_meta_data();
                $order->add_order_note(sprintf(__('Paypal process info at proxy %s, message: %s', 'mecom'),
                    $getActivateProxyUrl, 'Redirecting to PayPal Shield Hosted Checkout'));
                return [
                    'result' => 'success',
                    'type' => 'cs-paypal-hosted',
                    'redirect' => $data->url,
                ];
            }
            if (isset($data->error_detail) && in_array($data->error_detail, ['PAYEE_ACCOUNT_LOCKED_OR_CLOSED', 'PAYEE_ACCOUNT_RESTRICTED'])) {
                // PayPal account died — move this shield to the unused pool and rotate, same as the Smart Button.
                csPaypalErrorLog(wp_remote_retrieve_body($proxyProcess), 'PP_HOSTED account died');
                $order->add_order_note(sprintf(__('Paypal hosted checkout: shield %s PayPal account died (%s) — moved to unused list and switched to new shield.', 'mecom'),
                    $getActivateProxyUrl, $data->error_detail));
                $order->update_status('failed');
                if ($isEnableEndpointMode) {
                    csEndpointMoveToUnusedShield($getActivateProxyUrl, $data->error_detail);
                } else {
                    if (isEnabledAmountRotation()) {
                        performProxyAmountRotation($activatedProxy, 0);
                    } else {
                        performProxyByTimeRotation($activatedProxy);
                    }
                    moveToUnusedProxyIdsRestrictAccount([$activatedProxy['id']]);
                    cs_pp_action_wp_head();
                }
                csPaypalSendMailShieldDie($getActivateProxyUrl);
                wc_add_notice(__('We cannot process your payment right now, please try another payment method.[24]', 'mecom'), 'error');
                return false;
            }
            csPaypalErrorLog(wp_remote_retrieve_body($proxyProcess), 'Hosted checkout error![0]');
            wc_add_notice(__('We cannot process your payment right now, please try another payment method.[23]', 'mecom'), 'error');
            $order->add_order_note(sprintf(__('Paypal hosted checkout ERROR by proxy %s, ERROR message: %s', 'mecom'),
                $getActivateProxyUrl, $data->code ?? 'unknown'));
            $order->update_status('failed');
            forceRotateShield();
            return false;
        }
        if ($this->paypal_button === OPT_CS_PAYPAL_SETTING_CHECKOUT) {
            $orderData['purchase_units'] = $purchaseUnits;
            $order->add_order_note(sprintf(__('Paypal processing info at proxy %s, message: %s', 'mecom'),
                $getActivateProxyUrl,
                'Start checkout paypal credit card form'
            ));
            $orderData['order_id'] = $order_id;
            $orderData['pp_order_id'] = $_POST['mecom-paypal-payment-order-id'];
            $orderData['merchant_site'] = get_home_url();
            $orderData['customer_zipcode'] = $order->get_billing_postcode();
            $orderData['customer_email'] = $order->get_billing_email();
            $orderData['shipping_address_country'] = $shippingCountry;
            $orderData['bfp'] = WC()->session->get('mecom-paypal-browser-fingerprint');
            if ($this->intent == OPT_CS_PAYPAL_AUTHORIZE) {
                $urlCheckout = $getActivateProxyUrl . "?mecom-paypal-authorize-order=1"
                    . '&' . csPaypalBuildQuery($orderData);
            } else {
                $urlCheckout = $getActivateProxyUrl . "?mecom-paypal-capture-order=1"
                    . '&' . csPaypalBuildQuery($orderData);
            }
            $proxyProcess = wp_remote_post($urlCheckout, [
                 'sslverify' => csPaypalGetSSLVerifyStatus(),
                 'timeout' => 300 ,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
                ])
            ]);
            if (is_wp_error($proxyProcess)) {
                forceRotateShield();
                csPaypalErrorLog($proxyProcess, "pp request checkout error[10]");
            }
            $order->add_order_note(sprintf(__('Paypal handle order at proxy url %s', 'mecom'),
                $getActivateProxyUrl
            ));
            $responseBody = wp_remote_retrieve_body($proxyProcess);
            $data = json_decode($responseBody);
            if($data->status === 'success') {
                 if ($this->intent == OPT_CS_PAYPAL_AUTHORIZE) {
                    $ppPayment = $data->order->purchase_units[0]->payments->authorizations[0];
                 } else {
                    $ppPayment = $data->order->purchase_units[0]->payments->captures[0];
                 }
            }
            $order->update_meta_data('_cs_paypal_checkout_page', 'checkout');
            $order->update_meta_data('_shield_paypal_funding_source', $data->order->purchase_units[0]->custom_id ?? null);
            $order->save_meta_data();
            if ($data->status === 'success' && isset($ppPayment)) {
                 if ($this->intent == OPT_CS_PAYPAL_AUTHORIZE) {
                    $order->add_order_note(sprintf(__('PayPal authorized by proxy %s, ID: %s', 'mecom'), $getActivateProxyUrl, $ppPayment->id), 0, false);
                    $order->update_status( 'on-hold', 'Payment can be captured.');
                    $order->update_meta_data( METAKEY_CS_PAYPAL_CAPTURED, 'false');
                 } else {
                    $order->add_order_note(sprintf(__('Paypal charged by proxy %s', 'mecom'), $getActivateProxyUrl), 0, false);
                    $order->add_order_note(sprintf(__('Paypal Checkout charge complete (Payment ID: %s)', 'mecom'), $ppPayment->id));
                    
                    $sellerPayableBreakdown = $data->seller_receivable_breakdown;
                    $paypalFee              = $sellerPayableBreakdown->paypal_fee->value;
                    $paypalCurrency         = $sellerPayableBreakdown->paypal_fee->currency_code;
                    $paypalPayout           = $sellerPayableBreakdown->net_amount->value;
        
                    $order->update_meta_data( METAKEY_CS_PAYPAL_FEE, $paypalFee);
                    $order->update_meta_data( METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout);
                    $order->update_meta_data( METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency);
                    $order->payment_complete();
                 }
                 $order->reduce_order_stock();
                 if ($isEnableEndpointMode) {
                     csEndpointPerformShieldRotateByAmount($order);
                 } else {
                     if (isEnabledAmountRotation()) {
                         performProxyAmountRotation($activatedProxy, $order->get_total());
                         updateRotationAmount($activatedProxy['id'], $order->get_total());
                     }
                 }
                 csPaypalSaveTransactionId($order, $ppPayment->id);
                 $order->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED);
                 $order->save_meta_data();

                // Empty cart
                $woocommerce->cart->empty_cart();
                return [
                    'result'   => 'success',
                    'redirect' => $order->get_checkout_order_received_url()
                ];
            } else if ($data->status === 'redirect') {
                $order->add_order_note(sprintf(__('Paypal Processing Over Charge proxy %s, URL redirect: %s', 'mecom'),
                    $getActivateProxyUrl,
                    $data->url
                ));
                return [
                    'result'   => 'success',
                    'redirect' => $data->url
                ];
            } else {
                if( $data->code === 'domain_whitelist_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        'Domain whitelist is required'
                    ));
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[13]', 'error');
                }
                else if( $data->code === 'customer_zipcode_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        "Customer's zipcode is blacklisted"
                    ));
                    csPaypalSendMailOrderBlacklisted($order->get_id());
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[14]', 'error');
                }  else if( $data->code === 'customer_email_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        "Customer's email is blacklisted"
                    ));
                    csPaypalSendMailOrderBlacklisted($order->get_id());
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[15]', 'error');
                }  else if( $data->code === 'states_cities_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        "Customer's State and City is blacklisted"
                    ));
                    csPaypalSendMailOrderBlacklisted($order->get_id());
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[16]', 'error');
                } else if( $data->code === 'order_total_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        "Order value exceeds PayPal capability"
                    ));
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[17]', 'error');
                }else if( $data->code === 'order_total_not_allow') {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                        $getActivateProxyUrl,
                        "Order value exceeds PayPal capability"
                    ));
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[18]', 'error');
                } else {
                    $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                    $getActivateProxyUrl,
                    $data->code
                    )); 
                    wc_add_notice('We cannot process your payment right now, please try another payment method.[19]', 'error');
                }
                $order->update_status('failed');
                csPaypalErrorLog($responseBody, 'Checkout error![0]');
                forceRotateShield();
                return false;
            }
        } else {
            unset($purchaseUnits['items']);
            $orderData['purchase_units'] = $purchaseUnits;
            $customerIp = csPaypalGetClientIP();
            $proxyProcess = wp_remote_post($getActivateProxyUrl
                . "?mecom-process=1&request_type=get_redirect_url&not_send_bill_address_to_paypal="
                . $this->not_send_bill_address_to_paypal
                . "&intent=" . $this->intent
                . "&version=" . OPT_MECOM_PAYPAL_VERSION
                . "&bfp=" . WC()->session->get('mecom-paypal-browser-fingerprint')
                . "&customer_ip=$customerIp&"
                . "&order_id=$order_id&"
                . csPaypalBuildQuery($orderData),
                [
                    'sslverify' => csPaypalGetSSLVerifyStatus(),
                    'timeout' => 300,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode([
                        'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($order),
                    ])
                ]);
            if (is_wp_error($proxyProcess)) {
                csPaypalErrorLog($proxyProcess, "error[3]");
                wc_add_notice(__('We cannot process your payment right now, please try another payment method.[20]', 'mecom'), 'error');
                $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                    $getActivateProxyUrl,
                    'Paypal request create order fail'
                ));
                $order->update_status('failed');
                forceRotateShield();
                cs_pp_action_wp_head();
                return false;
            }

            $responseBody = wp_remote_retrieve_body($proxyProcess);
            $data = json_decode($responseBody);
            if($data->status !== 'failed' && !empty($data->redirect_link)) {
                //$order->add_order_note( sprintf( __( 'Process detail %s', 'mecom' ), $proxyProcess ) ,0,false);
                $order->add_order_note(sprintf(__('Paypal process info at proxy %s, message: %s', 'mecom'),
                    $getActivateProxyUrl,
                    'Start redirect to Paypal checkout page'
                ));
                return [
                    'result' => 'success',
                    'type' => 'cs-paypal',
                    'redirect' => $data->redirect_link
                ];
            } else {
                csPaypalErrorLog($responseBody, 'Checkout error![4]');
                wc_add_notice(__('We cannot process your payment right now, please try another payment method.[21]', 'mecom'), 'error');
                $order->add_order_note(sprintf(__('Paypal charged ERROR by proxy %s, ERROR message: %s', 'mecom'),
                    $getActivateProxyUrl,
                    $data->error_detail
                ));
                $order->update_status('failed');
                if (in_array($data->error_detail, ['PAYEE_ACCOUNT_LOCKED_OR_CLOSED', 'PAYEE_ACCOUNT_RESTRICTED'])) {
                    if ($isEnableEndpointMode) {
                        csEndpointMoveToUnusedShield($getActivateProxyUrl, $data->error_detail);
                    } else {
                        if (isEnabledAmountRotation()) {
                            performProxyAmountRotation($activatedProxy, 0);
                        } else {
                            performProxyByTimeRotation($activatedProxy);
                        }
                        moveToUnusedProxyIdsRestrictAccount([$activatedProxy['id']]);
                        cs_pp_action_wp_head();
                    }
                } else {
                    forceRotateShield();
                    cs_pp_action_wp_head();
                }
                return false;
            }
        }
    }

    /**
     * Process refund.
     *
     * @param int $order_id Order ID
     * @param float $amount Order amount
     * @param string $reason Refund reason
     *
     * @return boolean | WP_Error  True or false based on success, or a WP_Error object.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( 0 == $amount || null == $amount ) {
            return new WP_Error( 'paypal_refund_error', __( 'Refund Error: You need to specify a refund amount.', 'mecom-paypal-gateway' ) );
        }

        try {
            $refund_txn_id = $this->refund_order( $order, $order_id, $amount, $reason, $order->get_currency() );
            $order->add_order_note( sprintf( __( 'PayPal refund completed; transaction ID = %s', 'mecom-paypal-gateway' ), $refund_txn_id ) );

            return true;

        } catch ( MEcom_PayPal_API_Exception $e ) {
            if ( isset( $e->response->message ) ) {
                return new WP_Error( 'paypal_refund_error', $e->response->message );
            } else if ( isset( $e->response->error_description ) ) {
                return new WP_Error( 'paypal_refund_error', $e->response->error_description );
            }

            return new WP_Error( 'paypal_refund_error', $e->getMessage() );
        }
    }

    private function refund_order( $order, $order_id, $amount, $reason, $currency ) {
        // add refund params
        $params['TRANSACTIONID'] = csPaypalGetTransactionId($order);
        $params['AMT']           = $amount;
        $params['CURRENCYCODE']  = $currency;
        $params['NOTE']          = $reason;
        $params["merchant_site"] = get_home_url();
        $params['cs_order_detail'] = getCsPaypalOrderDetailFromWcOrder($order);
        //Get the proxy url when this order was made

        $proxyUrl = wc_get_order($order_id)->get_meta( METAKEY_PAYPAL_PROXY_URL );

        // do API call
        $url = $proxyUrl . "?mecom-pp-refund=1&order_id=$order_id&" . csPaypalBuildQuery( $params );

        $arrContextOptions = [
            "ssl" => [
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ],
        ];
        $body              = file_get_contents( $url, false, stream_context_create( $arrContextOptions ) );

        $response = json_decode( $body );

//            if (is_wp_error($request)) {
//                wc_add_notice('There is an error when process this payment, please contact us for more support or you can try to use Paypal!', 'error');
//                $order->add_order_note(sprintf(__('Failed refund by Paypal! Debug proxy %s', 'mecom-paypal-gateway'), $url));
//                throw new MEcom_PayPal_API_Exception($data);
//            }

        // Backward compatibility
        if ( isset( $response->id ) ) {
            return $response->id;
        }

        if ( isset( $response->status ) && $response->status == 'success' ) {
            $sellerPayableBreakdown = $response->data->seller_payable_breakdown;
            $paypalFee              = $sellerPayableBreakdown->paypal_fee->value;
            $paypalCurrency         = $sellerPayableBreakdown->paypal_fee->currency_code;
            $paypalPayout           = $sellerPayableBreakdown->net_amount->value;
            $order = wc_get_order($order_id);
            $order->update_meta_data( METAKEY_CS_PAYPAL_FEE, $paypalFee );
            $order->update_meta_data( METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout );
            $order->update_meta_data( METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency );
            $order->save_meta_data();
            return $response->data->id;
        } else {
            $order->add_order_note( sprintf( __( 'Failed refund by PayPal! Proxy %s', 'mecom-paypal-gateway' ), $proxyUrl ) );
            throw new MEcom_PayPal_API_Exception( $response );
        }
    }

    /**
     * Checks if currency in setting supports 0 decimal places.
     *
     * @return bool Returns true if currency supports 0 decimal places
     * @since 1.2.0
     *
     */
    public function is_currency_supports_zero_decimal() {
        return in_array( get_woocommerce_currency(), array( 'HUF', 'JPY', 'TWD' ) );
    }

    /**
     * Get number of digits after the decimal point.
     *
     * @return int Number of digits after the decimal point. Either 2 or 0
     * @since 1.2.0
     *
     */
    public function get_number_of_decimal_digits() {
        return $this->is_currency_supports_zero_decimal() ? 0 : 2;
    }

    /*
     * In case you need a webhook, like PayPal IPN etc
     */
    public function webhook() {
        // $order = wc_get_order( $_GET['id'] );
        // $order->payment_complete();
        // $order->reduce_order_stock();

        // update_option('webhook_debug', $_GET);
    }

    public function getProductTitle( $productTitle , $orderId) {
        $productTitle = trim($productTitle);
        switch ( $this->productTitleSetting ) {
            case 'user_define':
                $title = $this->userDefineProductTitle;
                $title = str_replace('[order_id]', strval($orderId), $title);
                if (strpos($title, '[customer_name]') !== false) {
                    $customerName = '';
                    $titleOrder = wc_get_order($orderId);
                    if ($titleOrder) {
                        $customerName = trim($titleOrder->get_billing_first_name() . ' ' . $titleOrder->get_billing_last_name());
                        if ($customerName === '') {
                            $customerName = trim($titleOrder->get_shipping_first_name() . ' ' . $titleOrder->get_shipping_last_name());
                        }
                    }
                    $title = str_replace('[customer_name]', $customerName, $title);
                }
                $randomTitle = '';
                if (!empty($this->randomProductTitleList)) {
                    $explodeList = explode(',', $this->randomProductTitleList);
                    if (!empty($explodeList)) {
                        $randomTitle = trim($explodeList[array_rand($explodeList)]);
                    }
                }
                $title = str_replace('[rand_title_from_list]', $randomTitle, $title);

                if (strpos($title, '[rand_title_from_shield]') !== false) {
                    $shortcodeLen = strlen('[rand_title_from_shield]');
                    while (($pos = strpos($title, '[rand_title_from_shield]')) !== false) {
                        $title = substr_replace($title, csPaypalGetRandomTitleFromShield(), $pos, $shortcodeLen);
                    }
                }

                $titleWords = preg_split('/\s+/', trim($productTitle), -1, PREG_SPLIT_NO_EMPTY);
                $title = str_replace('[last_2_words]', implode(' ', array_slice($titleWords, -2)), $title);
                $explode = explode(' ', $productTitle);
                $title = str_replace('[last_word]', array_pop($explode), $title);
                preg_match_all('/\[rand_\d+\]/', $title, $matchRandStrings);
                if (is_array($matchRandStrings) && count($matchRandStrings)) {
                    foreach ($matchRandStrings[0] as $matchRandString) {
                        $numberOfStringRand = preg_replace('/[^0-9]/', '', $matchRandString);
                        $stringRandom = $this->generateRandomString((int)$numberOfStringRand);
                        $title = str_replace($matchRandString, $stringRandom, $title);
                    }
                }
                return $title;
            case 'keep_original':
                return $productTitle;
            case 'last_word':
            default:
                return strrchr( $productTitle, ' ' );
        }
    }
    
    public function generateRandomString($length = 10) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function check_cs_paypal_payment_gateways( $gateways ) {
        if ( ! is_checkout() && ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
            return $gateways;
        }
        if (WC()->cart) {
            $carTotal = WC()->cart->get_total( false );
        } else {
            $carTotal = 0;
        }
        $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
        $activeProxy = get_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, null );
        if ($isEnableEndpointMode) {
            if (csPaypalIsEndpointConfigInvalid()) {
                unset( $gateways['mecom_paypal'], $gateways['mecom_paypal_cardfields'] );
                return $gateways;
            }
            $csOrderKey = csPaypalGenerateProcessingOrderKey();
            $shieldUrl = csEndpointGetShieldPaypalToProcess($csOrderKey, $carTotal);
            if (!$shieldUrl) {
                unset( $gateways['mecom_paypal'], $gateways['mecom_paypal_cardfields'] );
            }
        } else {
            $rotationMethod = get_option( OPT_MECOM_PAYPAL_ROTATION_METHOD, OPT_CS_PAYPAL_BY_TIME );
            if ($rotationMethod != OPT_CS_PAYPAL_BY_AMOUNT) {
                return $gateways;
            }

            if (!hasPayableProxy($carTotal)) {
                if (isPaypalShieldReachAmount($carTotal)) {
                    csPaypalSendMailShieldReachAmount();
                }
                unset($gateways['mecom_paypal'], $gateways['mecom_paypal_cardfields']);
            }
        }
        return $gateways;
    }

    public function add_pp_authorize_column( $columns ) {
        $statusColumnPos = array_search( 'order_status', array_keys( $columns ), true );
        $insertPos       = false === $statusColumnPos ? count( $columns ) : $statusColumnPos + 1;

        return array_merge(
            array_slice( $columns, 0, $insertPos ),
            [
                'cs_pp_payment_status' => 'CS-PP Captured',
            ],
            array_slice( $columns, $insertPos )
        );
    }
    
    public function add_mecom_shield_url( $columns ) {
        $statusColumnPos = array_search( 'order_status', array_keys( $columns ), true );
        $insertPos       = false === $statusColumnPos ? count( $columns ) : $statusColumnPos + 1;

        return array_merge(
            array_slice( $columns, 0, $insertPos ),
            [
                'mecom_shield_url' => 'Shield URL',
            ],
            array_slice( $columns, $insertPos )
        );
    }

    public function add_mecom_order_values($column, $wc_order_id) {
        $this->add_pp_authorize_column_value($column, $wc_order_id);
        $this->add_mecom_shield_url_value($column, $wc_order_id);
    }
    

    public function add_pp_authorize_column_value( $column, $wc_order_id ) {
        if ( ! $this->intent || $this->intent != OPT_CS_PAYPAL_AUTHORIZE ) {
            return;
        }
        if ( 'cs_pp_payment_status' != $column ) {
            return;
        }
        $wc_order = wc_get_order( $wc_order_id );

        if ( ! is_a( $wc_order, \WC_Order::class ) || ! $this->should_render_for_order( $wc_order ) ) {
            return;
        }

        if ( $this->is_captured( $wc_order ) ) {
            printf(
                '<span class="dashicons dashicons-yes">
                        <span class="screen-reader-text">%s</span>
                    </span>',
                 'Payment captured'
            );

            return;
        }
        printf(
            '<mark class="onbackorder">%s</mark>',
            'Not captured'
        );

    }
    
    public function add_mecom_shield_url_value( $column, $wc_order_id ) {
        if ( 'mecom_shield_url' != $column ) {
            return;
        }
        $wc_order = wc_get_order( $wc_order_id );

        if ( ! is_a( $wc_order, \WC_Order::class )) {
            return;
        }
        if (in_array($wc_order->get_payment_method(), ['mecom_paypal', 'mecom_paypal_cardfields'], true)) {
            echo '[PayPal] '. $wc_order->get_meta(METAKEY_PAYPAL_PROXY_URL);
        }
    }

    public function order_payment_status( $wc_order_id ) {
        if ( ! $this->intent || $this->intent != OPT_CS_PAYPAL_AUTHORIZE ) {
            return;
        }
        $wc_order = new \WC_Order( $wc_order_id );

        if ( ! $this->should_render_for_order( $wc_order ) || $this->is_captured( $wc_order ) ) {
            return;
        }

        printf(
            '<li class="wide"><p><mark class="order-status status-on-hold"><span>%1$s</span></mark></p><p>%2$s</p></li>',
            esc_html__(
                'Not captured',
                'woocommerce-paypal-payments'
            ),
            esc_html__(
                'To capture the payment select capture action from the list below.',
                'woocommerce-paypal-payments'
            )
        );
    }

    public function should_render_for_order( \WC_Order $order ) {
        $intent               = $order->get_meta( METAKEY_CS_PAYPAL_INTENT );
        $captured             = $order->get_meta( METAKEY_CS_PAYPAL_CAPTURED );
        $status               = $order->get_status();
        $not_allowed_statuses = [ 'refunded' ];

        return ! empty( $intent ) && OPT_CS_PAYPAL_AUTHORIZE === $intent &&
               ! empty( $captured ) &&
               ! in_array( $status, $not_allowed_statuses, true );
    }

    public function should_render_for_action( \WC_Order $order ) {
        $status               = $order->get_status();
        $not_allowed_statuses = array( 'refunded', 'cancelled', 'failed' );
        return $this->should_render_for_order( $order ) &&
               ! $this->is_captured( $order ) &&
               ! in_array( $status, $not_allowed_statuses, true );
    }

    public function is_captured( \WC_Order $wc_order ) {
        $captured = $wc_order->get_meta( METAKEY_CS_PAYPAL_CAPTURED );

        return wc_string_to_bool( $captured );
    }

    public function add_pp_order_actions($order_actions) {
        global $theorder;

        if ( ! is_a( $theorder, WC_Order::class ) ) {
            return $order_actions;
        }

        if ( ! $this->should_render_for_action( $theorder ) ) {
            return $order_actions;
        }

        $order_actions['cs_pp_capture_authorization_order'] = 'Capture authorized PayPal payment';
        $order_actions['cs_pp_reauthorize_authorization_order'] = 'Reauthorize authorized PayPal payment';
        $order_actions['cs_pp_cancel_authorization_order'] = 'Void authorized PayPal payment';

        return $order_actions;

    }

    public function hasAuthorizationExpired($order_id) {
        $order = wc_get_order($order_id);
        $transaction_time = $order->get_date_created()->getTimestamp();
        return floor(( time() - $transaction_time)  / 3600) >= 720;
    }

    public function cs_pp_capture_authorization_order(WC_Order $wc_order) {

        $isCaptured = wc_string_to_bool($wc_order->get_meta( METAKEY_CS_PAYPAL_CAPTURED, 'false'));
        if ( $isCaptured ) {
            return false;
        }

        if ($this->hasAuthorizationExpired($wc_order->get_id())) {
            $wc_order->add_order_note( "Authorization expired!" );
            $wc_order->update_status('failed', 'Order failed');
            return false;
        }

        $proxyUrl = $wc_order->get_meta( METAKEY_PAYPAL_PROXY_URL );

        if (empty($proxyUrl)) {
            $wc_order->add_order_note( "Can't found proxy url!" );
            $wc_order->update_status('failed', 'Order failed');
            return false;
        }
        $authId = csPaypalGetTransactionId($wc_order);

        $params = [];
        $params["merchant_site"] = get_home_url();
        $params["payment_id"] = $authId;
        $capturePaymentUrl = $proxyUrl . "?mecom-pp-capture-authorization-payment=1&" . csPaypalBuildQuery($params);

        $request = wp_remote_post($capturePaymentUrl, [
            'sslverify' => csPaypalGetSSLVerifyStatus(), 
            'timeout' => 300 ,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($wc_order),
            ])
        ] );
        if (is_wp_error($request)) {
            csPaypalErrorLog($request, "Capture request error![5]");
            $wc_order->add_order_note( "Capture request error!" );
            $wc_order->update_status('failed', 'Order failed');
            return false;
        }
        $responseBody = wp_remote_retrieve_body($request);
        $data = json_decode($responseBody);
        if (empty($data)) {
            csPaypalErrorLog($responseBody, "Capture error! Empty response[6]");
            $wc_order->add_order_note( "Capture error! Empty response" );
            $wc_order->update_status('failed', 'Order failed');
            return false;
        }

        if ( ! $data->success ) {
            csPaypalErrorLog($responseBody, "Capture error! Empty response[6.1]");
            $wc_order->add_order_note( $data->message );
            $wc_order->update_status('failed', 'Order failed');
            return false;
        }

        $sellerPayableBreakdown = $data->seller_receivable_breakdown;
        $paypalFee              = $sellerPayableBreakdown->paypal_fee->value;
        $paypalCurrency         = $sellerPayableBreakdown->paypal_fee->currency_code;
        $paypalPayout           = $sellerPayableBreakdown->net_amount->value;
        $newTransactionId       = $data->transaction_id;

        $wc_order->update_meta_data( METAKEY_CS_PAYPAL_FEE, $paypalFee);
        $wc_order->update_meta_data( METAKEY_CS_PAYPAL_PAYOUT, $paypalPayout);
        $wc_order->update_meta_data( METAKEY_CS_PAYPAL_CURRENCY, $paypalCurrency);
        csPaypalSaveTransactionId($wc_order, $newTransactionId);

        $wc_order->add_order_note( 'Payment successfully captured.' );
        $wc_order->update_meta_data( METAKEY_CS_PAYPAL_CAPTURED, 'true' );
        $wc_order->save();
        $wc_order->payment_complete();
        return true;

    }

    public function cs_pp_cancel_authorization_order(WC_Order $wc_order) {
        if ($wc_order->get_status() == 'cancelled') {
            return false;
        }
        $isCaptured = wc_string_to_bool($wc_order->get_meta( METAKEY_CS_PAYPAL_CAPTURED, 'false'));
        if ( $isCaptured ) {
            return false;
        }
        $lastCancelTime = $wc_order->get_meta( '_cs_last_cancel_authorization_order' );
        if (time() - (int)$lastCancelTime < 10) {
            return false;
        }
        $wc_order->update_meta_data( '_cs_last_cancel_authorization_order', time());
        $wc_order->save_meta_data();
        $proxyUrl = $wc_order->get_meta( METAKEY_PAYPAL_PROXY_URL );

        if (empty($proxyUrl)) {
            $wc_order->add_order_note( "Can't found proxy url!" );
            return false;
        }
        $authId = csPaypalGetTransactionId($wc_order);
        $params = [];
        $params["merchant_site"] = get_home_url();
        $params["payment_id"] = $authId;

        $cancelPaymentUrl = $proxyUrl . "?mecom-pp-cancel-authorization-payment=1&" . csPaypalBuildQuery($params);

        $request = wp_remote_post($cancelPaymentUrl, [
            'sslverify' => csPaypalGetSSLVerifyStatus(),
            'timeout' => 300 ,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder($wc_order),
            ])
        ] );
        if (is_wp_error($request)) {
            csPaypalErrorLog($request, "Cancel payment request error![6]");
            $wc_order->add_order_note( "Cancel payment request error!" );
            return false;
        }
        $responseBody = wp_remote_retrieve_body($request);
        $data = json_decode($responseBody);
        if (empty($data)) {
            csPaypalErrorLog($responseBody, "Cancel payment error! Empty response[7]");
            $wc_order->add_order_note( "Cancel payment error! Empty response" );
            return false;
        }

        if ( ! $data->success ) {
            csPaypalErrorLog($responseBody, "Cancel payment error! Empty response[7.1]");
            $wc_order->add_order_note( "Error: " . $data->message );
            return false;
        }
        $wc_order->save();
        $wc_order->update_status('cancelled', 'Order Cancelled');

        return true;
    }

    function cs_pp_reauthorize_authorization_order(WC_Order $wc_order)
    {
        if ($wc_order->get_status() == 'cancelled') {
            return false;
        }
        $isCaptured = wc_string_to_bool($wc_order->get_meta( METAKEY_CS_PAYPAL_CAPTURED, 'false'));
        if ( $isCaptured ) {
            return false;
        }

        $lastCancelTime = $wc_order->get_meta( '_cs_last_reauthorize_authorization_order' );
        if (time() - (int)$lastCancelTime < 10) {
            return false;
        }
        $wc_order->update_meta_data( '_cs_last_reauthorize_authorization_order', time());
        $wc_order->save_meta_data();
        $proxyUrl = $wc_order->get_meta( METAKEY_PAYPAL_PROXY_URL );

        if (empty($proxyUrl)) {
            $wc_order->add_order_note( "Can't found proxy url!" );
            return false;
        }
        $authId = csPaypalGetTransactionId($wc_order);
        $params = [];
        $params["merchant_site"] = get_home_url();
        $params["payment_id"] = $authId;
        $reauhtorizePaymentUrl = $proxyUrl . "?mecom-pp-reauthorize-authorization-payment=1&" . csPaypalBuildQuery($params);

        $request = wp_remote_get($reauhtorizePaymentUrl, [ 'sslverify' => csPaypalGetSSLVerifyStatus(), 'timeout' => 300 ] );
        if (is_wp_error($request)) {
            csPaypalErrorLog($request, "Reauthorize payment request error![8]");
            $wc_order->add_order_note( "Reauthorize payment request error!" );
            return false;
        }
        $responseBody = wp_remote_retrieve_body($request);
        $data = json_decode($responseBody);
        if (empty($data)) {
            csPaypalErrorLog($responseBody, "Reauthorize payment error! Empty response[9]");
            $wc_order->add_order_note( "Reauthorize payment error! Empty response" );
            return false;
        }

        if ( ! $data->success ) {
            csPaypalErrorLog($responseBody, "Reauthorize payment error! Empty response[9.1]");
            $wc_order->add_order_note( "Error: " . $data->message );
            return false;
        }
        $wc_order->save();
        return true;
    }
}
