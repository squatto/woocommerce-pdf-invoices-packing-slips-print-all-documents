<?php
/**
 * Plugin Name:     WooCommerce PDF Invoices & Packing Slips - Print All Documents
 * Plugin URI:      https://github.com/squatto/woocommerce-pdf-invoices-packing-slips-print-all-documents
 * Description:     Create PDF invoices and packing slips at the same time as a single PDF file, for one or more WooCommerce orders.
 * Version:         1.0.0
 * Author:          Scott Carpenter
 * Author URI:      https://github.com/squatto
 * License:         GPLv2 or later
 * License URI:     http://www.opensource.org/licenses/gpl-license.php
 * Text Domain:     woocommerce-pdf-invoices-packing-slips-print-all-documents
 *
 * Requires the "WooCommerce PDF Invoices & Packing Slips" plugin:
 * https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/
 */

use iio\libmergepdf\Merger;

class WPO_WCPDF_PrintAll
{
    public $version = '1.0.0';
    public $plugin_basename;

    protected static $_instance = null;

    /**
     * @var string
     */
    public $pdf;

    /**
     * @var array
     */
    public $order_ids;

    /**
     * Main Plugin Instance
     *
     * Ensures only one instance of plugin is loaded or can be loaded.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->plugin_basename = plugin_basename(__FILE__);

        add_action('plugins_loaded', [$this, 'check_for_wcpdf']);
    }

    /**
     * Verify that the "WooCommerce PDF Invoices & Packing Slips" plugin is installed and active
     */
    public function check_for_wcpdf()
    {
        if (is_admin() && ! is_plugin_active('woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php')) {
            // the base plugin isn't activated
            // show an error
            add_action('admin_notices', [$this, 'wcpdf_notice']);

            return;
        }

        // initialize the plugin
        $this->init();
    }

    /**
     * Output a notice about requiring the "WooCommerce PDF Invoices & Packing Slips" plugin
     */
    public function wcpdf_notice()
    {
        ?>
        <div class="error">
            <p>
                ERROR: The <strong>WooCommerce PDF Invoices & Packing Slips - Print All Documents</strong> plugin requires the
                <a href="https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/" target="_blank"><strong>WooCommerce PDF Invoices & Packing Slips</strong></a>
                plugin to be installed and activated.
            </p>
        </div>
        <?php

    }

    /**
     * Initialize the plugin
     */
    private function init()
    {
        // add the button in action column
        add_filter('wpo_wcpdf_listing_actions', [
            $this,
            'sv_add_my_account_order_actions'
        ], 10, 2);

        // add the meta box button
        add_filter('wpo_wcpdf_meta_box_actions', [$this, 'add_meta_box_action'], 10, 2);

        // add the bulk order printing action
        add_action('admin_footer', [$this, 'add_bulk_action']);

        // listen for the ajax request
        add_action('wp_ajax_generate_wpo_wcpdf_all', [$this, 'generate_all_pdfs_ajax']);

        // enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // add "Settings" link to the plugin admin row
        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_settings_link']);
    }

    public function add_all_pdf_order_actions($actions, $order)
    {
        unset($actions);
        $actions['all'] = [
            'url'  => wp_nonce_url(admin_url("admin-ajax.php?action=generate_wpo_wcpdf_all&order_ids=$order->id"), 'generate_wpo_wcpdf_all'),
            'alt'   => 'Print All PDF Documents',
            'img' => WPO_WCPDF()->plugin_url() . "/assets/images/packing-slip.png",
        ];
        return $actions;
    }

    public function enqueue_admin_scripts()
    {
        // add scripts
        wp_enqueue_script(
            'wpo-wcpdf-all',
            $this->plugin_url() . '/assets/js/order-script.js',
            ['jquery'],
            $this->get_version()
        );

        // add ajax handling for the bulk order printing
        wp_localize_script(
            'wpo-wcpdf-all',
            'wpo_wcpdf_all_ajax',
            [
                'ajaxurl'     => admin_url('admin-ajax.php'), // URL to WordPress ajax handling page
                'nonce'       => wp_create_nonce('generate_wpo_wcpdf_all'),
                'bulk_action' => 'print-all-documents',
            ]
        );
    }

    /**
     * Add settings link to plugins page
     * The "Settings" link goes to the settings for the "WooCommerce PDF Invoices & Packing Slips" plugin, NOT this plugin!
     * This plugin does not have any settings
     *
     * @param array $links
     *
     * @return array
     */
    public function add_settings_link($links)
    {
        $action_links = [
            'settings' => '<a href="admin.php?page=wpo_wcpdf_options_page">' . __('Settings', 'woocommerce') . '</a>',
        ];

        return array_merge($action_links, $links);
    }

    /**
     * Return the version identifier to append to scripts/styles
     * @return bool|int|string
     */
    public function get_version()
    {
        if (WP_DEBUG) {
            return filemtime(__FILE__);
        }

        return $this->version;
    }

    /**
     * Add the button to the meta box
     *
     * @param array $actions
     * @param int $post_id
     *
     * @return array
     */
    public function add_meta_box_action($actions, $post_id)
    {
        if (! $this->has_enabled_types()) {
            return $actions;
        }

        $actions['all'] = [
            'url'   => wp_nonce_url(admin_url("admin-ajax.php?action=generate_wpo_wcpdf_all&order_ids=$post_id"), 'generate_wpo_wcpdf_all'),
            'alt'   => 'Print All PDF Documents',
            'title' => 'Print All PDF Documents',
        ];

        return $actions;
    }

    /**
     * Add the action to the bulk order action menu
     */
    public function add_bulk_action()
    {
        if (! $this->is_order_page() || ! $this->has_enabled_types()) {
            return;
        }

        $action = 'print-all-documents';
        $title = 'Print All PDF Documents';

        ?>
        <script type="text/javascript">
            jQuery(document).ready(function () {
                jQuery('<option>')
                    .val('<?php echo $action; ?>')
                    .html('<?php echo esc_attr($title); ?>')
                    .appendTo('select[name=\'action\'], select[name=\'action2\']');
            });
        </script>
        <?php

    }

    /**
     * Is this a shop_order page (edit or list)?
     */
    public function is_order_page()
    {
        global $post_type;

        return ($post_type == 'shop_order');
    }

    /**
     * Generate a merged PDF with all enabled document types
     */
    public function generate_all_pdfs_ajax()
    {
        $this->set_order_ids();
        $this->check_permissions();

        try {
            $this->generate_and_merge_pdfs();
            $this->output_pdf();
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        exit;
    }

    /**
     * Retrieve the order_ids from the GET param
     */
    public function set_order_ids()
    {
        $order_ids = array_map('absint', array_filter(explode('x', $_GET['order_ids'])));
        $this->order_ids = array_reverse($order_ids);
    }

    /**
     * Check that the user has permission to print the orders
     */
    public function check_permissions()
    {
        // admin is required
        if (! is_admin()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips-print-all-documents'));
        }

        // validate the nonce
        if (empty($_GET['action']) || ! check_admin_referer($_GET['action'])) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips-print-all-documents'));
        }

        // check if order_ids were provided
        if (empty($_GET['order_ids'])) {
            wp_die(__("You haven't selected any orders", 'woocommerce-pdf-invoices-packing-slips-print-all-documents'));
        }

        // check the user's privileges
        $allowed = current_user_can('manage_woocommerce_orders') || current_user_can('edit_shop_orders');
        $allowed = apply_filters('wpo_wcpdf_check_privs', $allowed, $this->order_ids);

        if (! $allowed) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woocommerce-pdf-invoices-packing-slips-print-all-documents'));
        }
    }

    /**
     * Generate PDFs for each order and enabled document type, merge them, and then output the merged PDF
     * @throws \iio\libmergepdf\Exception
     */
    public function generate_and_merge_pdfs()
    {
        // require the composer autoloader
        require $this->plugin_path() . '/vendor/autoload.php';

        // generate PDFs and add them to the PDF merger
        $merger = new Merger;
        $generated = false;
        $types = $this->get_enabled_types();

        // each requested order_id is generated individually
        // this is done to ensure that the documents are collated (all documents for the first order, then all for the second order, etc...)
        // otherwise you get all invoices first, then all packing slips, etc. and the user would have to collate manually
        foreach ($this->order_ids as $order_id) {
            foreach ($types as $type) {
                /* @var \WPO\WC\PDF_Invoices\Documents\Order_Document $document */
                $document = wcpdf_get_document($type, [$order_id]);

                if ($document) {
                    $pdf_data = $document->get_pdf();
                    $merger->addRaw($pdf_data);
                    $generated = true;
                }
            }
        }

        if (! $generated) {
            wp_die(__('Unable to generate PDF documents for all of the selected orders.', 'woocommerce-pdf-invoices-packing-slips-print-all-documents'));
        }

        // merge PDFs into a single string of raw PDF data
        $this->pdf = $merger->merge();
    }

    /**
     * Output the merged PDF
     */
    public function output_pdf()
    {
        $pdf = $this->get_pdf();
        wcpdf_pdf_headers($this->get_filename(), $this->get_output_mode(), $pdf);
        echo $pdf;
        die();
    }

    /**
     * Get the output mode for the merged PDF
     * @return string
     */
    function get_output_mode()
    {
        $output_modes = array_map(function($type) {
            return WPO_WCPDF()->settings->get_output_mode($type);
        }, $this->get_enabled_types());
        $output_modes = array_values(array_unique($output_modes));

        if (count($output_modes) >= 1) {
            // return the first output mode
            return $output_modes[0];
        }

        // default to inline
        return 'inline';
    }

    /**
     * Get enabled document types
     * @return array
     */
    function get_enabled_types()
    {
        return array_map(function($document) {
            return $document->get_type();
        }, WPO_WCPDF()->documents->get_documents());
    }

    /**
     * Are any document types enabled?
     * @return bool
     */
    function has_enabled_types()
    {
        return (bool) count($this->get_enabled_types());
    }

    /**
     * Get the filename for merging
     * @return string
     */
    public function get_filename()
    {
        return 'print-all-' . time() . '.pdf';
    }

    /**
     * Get the merged PDF contents
     * @return string
     */
    public function get_pdf()
    {
        return $this->pdf;
    }

    /**
     * Get the plugin url
     * @return string
     */
    public function plugin_url()
    {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get the plugin path
     * @return string
     */
    public function plugin_path()
    {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }
}

/**
 * Returns the main instance of WooCommerce PDF Invoices & Packing Slips - Print All to prevent the need to use globals.
 *
 * @since  1.6
 * @return WPO_WCPDF
 */
function WPO_WCPDF_PrintAll()
{
    return WPO_WCPDF_PrintAll::instance();
}

// load the plugin
WPO_WCPDF_PrintAll();
