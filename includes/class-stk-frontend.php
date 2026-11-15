<?php
/**
 * Onglet « Stickers » de Mon compte WooCommerce.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Frontend {

    const ENDPOINT = 'stickers';

    public function __construct() {
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array($this, 'endpoint_title'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render'));
        add_action('template_redirect', array($this, 'handle_post'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_stk_set_quantity', array($this, 'ajax_set_quantity'));
    }

    /**
     * Déclare l'endpoint auprès de WooCommerce (réécriture + titre + menu actif).
     */
    public function add_query_var($vars) {
        $vars[self::ENDPOINT] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Onglet placé après « Mes abonnements » (plugin Abonnements), sinon après « Commandes ».
     */
    public function add_menu_item($items) {
        $label = __('Stickers', 'stickers');
        $after = isset($items['mes-abonnements']) ? 'mes-abonnements' : (isset($items['orders']) ? 'orders' : null);

        if ($after === null) {
            $logout = $items['customer-logout'] ?? null;
            unset($items['customer-logout']);
            $items[self::ENDPOINT] = $label;
            if ($logout !== null) {
                $items['customer-logout'] = $logout;
            }
            return $items;
        }

        $new = array();
        foreach ($items as $key => $value) {
            $new[$key] = $value;
            if ($key === $after) {
                $new[self::ENDPOINT] = $label;
            }
        }
        return $new;
    }

    public function endpoint_title($title) {
        return __('Stickers', 'stickers');
    }

    public static function views() {
        return array(
            'inventaire' => __('Mon inventaire', 'stickers'),
            'profil'     => __('Pseudo et adresse', 'stickers'),
        );
    }

    public static function current_view() {
        $views = self::views();
        $view  = isset($_GET['vue']) ? sanitize_key(wp_unslash($_GET['vue'])) : 'inventaire'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple navigation.
        return isset($views[$view]) ? $view : 'inventaire';
    }

    public static function url($view = 'inventaire') {
        return add_query_arg('vue', $view, wc_get_account_endpoint_url(self::ENDPOINT));
    }

    public static function is_stickers_page() {
        return function_exists('is_account_page') && is_account_page() && is_wc_endpoint_url(self::ENDPOINT);
    }

    public function enqueue_assets() {
        if (!self::is_stickers_page() || !is_user_logged_in()) {
            return;
        }
        wp_enqueue_style('stk-frontend', STK_PLUGIN_URL . 'assets/css/frontend.css', array(), STK_VERSION);
        wp_enqueue_script('stk-frontend', STK_PLUGIN_URL . 'assets/js/frontend.js', array(), STK_VERSION, true);
        wp_localize_script('stk-frontend', 'stkFront', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('stk_frontend'),
            'max'     => STK_MAX_QTY,
            'i18n'    => array(
                'saved'   => __('Enregistré', 'stickers'),
                'error'   => __('Erreur, la modification n\'a pas été enregistrée.', 'stickers'),
            ),
        ));
    }

    /**
     * Rendu de l'onglet.
     */
    public function render() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $view   = self::current_view();
        $member = STK_Members::get($user_id);

        self::template('nav', array('view' => $view, 'views' => self::views()));

        if (!$member && $view !== 'profil') {
            wc_print_notice(
                sprintf(
                    /* translators: %s: lien vers la page Pseudo et adresse */
                    __('Choisissez un pseudo pour participer à la bourse : %s', 'stickers'),
                    '<a href="' . esc_url(self::url('profil')) . '">' . esc_html__('choisir mon pseudo', 'stickers') . '</a>'
                ),
                'notice'
            );
        }

        switch ($view) {
            case 'profil':
                self::template('profil', array(
                    'member'    => $member,
                    'countries' => STK_Members::allowed_countries(),
                ));
                break;

            case 'inventaire':
            default:
                $stickers  = STK_Catalog::get_all(true);
                $inventory = STK_Inventory::get_user_inventory($user_id);
                self::template('inventaire', array(
                    'stickers'   => $stickers,
                    'inventory'  => $inventory,
                    'reserved'   => STK_Inventory::get_reserved($user_id),
                    'stats'      => STK_Inventory::stats($inventory, $stickers),
                    'collection' => (string) get_option('stk_collection_name', ''),
                ));
                break;
        }
    }

    /**
     * Inclut un gabarit de templates/myaccount/ avec $args disponible.
     */
    public static function template($name, array $args = array()) {
        $file = STK_PLUGIN_DIR . 'templates/myaccount/' . $name . '.php';
        if (is_readable($file)) {
            include $file;
        }
    }

    /**
     * Formulaires de l'onglet (fonctionnent aussi sans JavaScript).
     * Traités sur le front pour pouvoir utiliser les notices WooCommerce.
     */
    public function handle_post() {
        if (empty($_POST['stk_action']) || !is_user_logged_in() || !self::is_stickers_page()) {
            return;
        }

        $action  = sanitize_key(wp_unslash($_POST['stk_action']));
        $user_id = get_current_user_id();
        $nonce   = isset($_POST['_stk_nonce']) ? sanitize_text_field(wp_unslash($_POST['_stk_nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'stk_' . $action)) {
            wc_add_notice(__('Session expirée, veuillez réessayer.', 'stickers'), 'error');
            wp_safe_redirect(self::url(self::current_view()));
            exit;
        }

        switch ($action) {
            case 'save_inventory':
                $quantities = array();
                foreach ((array) ($_POST['qty'] ?? array()) as $sticker_id => $qty) {
                    $quantities[absint($sticker_id)] = sanitize_text_field(wp_unslash($qty));
                }
                $result   = STK_Inventory::set_quantities($user_id, $quantities);
                $success  = __('Inventaire enregistré.', 'stickers');
                $redirect = self::url('inventaire');
                break;

            case 'save_pseudo':
                $result   = STK_Members::save_pseudo($user_id, $_POST['pseudo'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans validate_pseudo().
                $success  = __('Pseudo enregistré.', 'stickers');
                $redirect = self::url('profil');
                break;

            case 'save_address':
                $result   = STK_Members::save_address($user_id, $_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans validate_address().
                $success  = __('Adresse enregistrée.', 'stickers');
                $redirect = self::url('profil');
                break;

            default:
                return;
        }

        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            wc_add_notice($success, 'success');
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Grille : modification d'une quantité à la volée.
     */
    public function ajax_set_quantity() {
        check_ajax_referer('stk_frontend', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Vous devez être connecté.', 'stickers')), 403);
        }

        $sticker_id = isset($_POST['sticker_id']) ? absint($_POST['sticker_id']) : 0;
        $qty        = isset($_POST['qty']) ? sanitize_text_field(wp_unslash($_POST['qty'])) : '';

        $inventory = STK_Inventory::set_quantities($user_id, array($sticker_id => $qty));
        if (is_wp_error($inventory)) {
            $current = STK_Inventory::get_user_inventory($user_id);
            wp_send_json_error(array(
                'message' => $inventory->get_error_message(),
                'qty'     => $current[$sticker_id] ?? 0,
            ));
        }

        $stickers = STK_Catalog::get_all(true);
        $new_qty  = $inventory[$sticker_id] ?? 0;
        wp_send_json_success(array(
            'qty'   => $new_qty,
            'state' => STK_Inventory::state($new_qty),
            'stats' => STK_Inventory::stats($inventory, $stickers),
        ));
    }
}
