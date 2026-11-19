<?php
/**
 * Plugin Name: Bourse aux stickers
 * Description: Bourse d'échange de stickers entre membres connectés : inventaire déclaratif, propositions d'échange, suggestions automatiques.
 * Version: 1.1.0
 * Author: Xylene14
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 11.2
 * WC tested up to: 11.2
 * Text Domain: stickers
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('STK_VERSION', '1.1.0');
define('STK_PLUGIN_FILE', __FILE__);
define('STK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STK_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('STK_MAX_QTY', 9);

require_once STK_PLUGIN_DIR . 'includes/class-stk-database.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-settings.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-catalog.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-members.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-inventory.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-exchanges.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-messages.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-market.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-suggestions.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-emails.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-scheduler.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-privacy.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-terms.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-watermark.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-frontend.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-admin.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-admin-exchanges.php';
require_once STK_PLUGIN_DIR . 'includes/class-stk-github-updater.php';

/**
 * Classe principale du plugin.
 */
final class STK_Plugin {

    private static $instance = null;

    public $frontend;
    public $admin;
    public $admin_exchanges;
    public $emails;
    public $scheduler;
    public $privacy;
    public $terms;
    public $watermark;
    public $updater;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(STK_PLUGIN_FILE, array(__CLASS__, 'activate'));
        register_deactivation_hook(STK_PLUGIN_FILE, array(__CLASS__, 'deactivate'));

        add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('init', array($this, 'load_textdomain'), 0);
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 99);
    }

    /**
     * La bourse ne lit ni n'écrit aucune commande : compatible HPOS.
     */
    public function declare_wc_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', STK_PLUGIN_FILE, true);
        }
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        STK_Database::maybe_upgrade();

        $this->frontend = new STK_Frontend();
        $this->admin    = new STK_Admin();
        $this->admin_exchanges = new STK_Admin_Exchanges();
        $this->emails    = new STK_Emails();
        $this->scheduler = new STK_Scheduler();
        $this->privacy   = new STK_Privacy();
        $this->terms     = new STK_Terms();
        $this->watermark = new STK_Watermark();

        if (is_admin() || wp_doing_cron()) {
            $this->updater = new STK_GitHub_Updater();
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain('stickers', false, dirname(STK_PLUGIN_BASENAME) . '/languages');
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Bourse aux stickers nécessite WooCommerce actif.', 'stickers');
        echo '</p></div>';
    }

    public static function activate() {
        STK_Database::install();
        add_option('stk_collection_name', '');
        add_option('stk_delete_data_on_uninstall', '0');
        update_option('stk_flush_rewrite', '1');
    }

    public static function deactivate() {
        STK_Scheduler::unschedule();
        flush_rewrite_rules();
    }

    /**
     * Les endpoints de Mon compte sont déclarés par WooCommerce sur « init » :
     * on vide les règles de réécriture après eux, une seule fois.
     */
    public function maybe_flush_rewrite_rules() {
        if (get_option('stk_flush_rewrite') === '1') {
            delete_option('stk_flush_rewrite');
            flush_rewrite_rules();
        }
    }
}

STK_Plugin::instance();
