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
    const BOURSE_PER_PAGE = 20;

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
        if (!STK_Status::can_access()) {
            return $items; // Bourse fermée : onglet masqué aux clients.
        }
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

    /**
     * Vues affichées dans la navigation de l'onglet.
     */
    public static function views() {
        return array(
            'inventaire'   => __('Inventaire', 'stickers'),
            'bourse'       => __('Bourse', 'stickers'),
            'propositions' => __('Propositions', 'stickers'),
            'echanges'     => __('Échanges', 'stickers'),
            'profil'       => __('Profil', 'stickers'),
        );
    }

    /** Vues accessibles par lien mais absentes de la navigation. */
    private static function hidden_views() {
        return array('membre' => 'bourse', 'echange' => 'echanges');
    }

    public static function current_view() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple navigation.
        $view = isset($_GET['vue']) ? sanitize_key(wp_unslash($_GET['vue'])) : 'inventaire';
        if (isset(self::views()[$view]) || isset(self::hidden_views()[$view])) {
            return $view;
        }
        return 'inventaire';
    }

    public static function url($view = 'inventaire', array $args = array()) {
        return add_query_arg(array_merge(array('vue' => $view), $args), wc_get_account_endpoint_url(self::ENDPOINT));
    }

    public static function exchange_url($id) {
        return self::url('echange', array('id' => (int) $id));
    }

    public static function member_url($pseudo, array $args = array()) {
        return self::url('membre', array_merge(array('membre' => $pseudo), $args));
    }

    public static function is_stickers_page() {
        return function_exists('is_account_page') && is_account_page() && is_wc_endpoint_url(self::ENDPOINT);
    }

    public function enqueue_assets() {
        if (!self::is_stickers_page() || !is_user_logged_in() || !STK_Status::can_access()) {
            return;
        }
        wp_enqueue_style('stk-frontend', STK_PLUGIN_URL . 'assets/css/frontend.css', array(), STK_VERSION);
        wp_enqueue_script('stk-frontend', STK_PLUGIN_URL . 'assets/js/frontend.js', array(), STK_VERSION, true);
        wp_localize_script('stk-frontend', 'stkFront', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('stk_frontend'),
            'max'     => STK_MAX_QTY,
            'i18n'    => array(
                'saved'  => __('Enregistré', 'stickers'),
                'error'  => __('Erreur, la modification n\'a pas été enregistrée.', 'stickers'),
                'viewer' => __('Visuel agrandi', 'stickers'),
                'prev'   => __('Sticker précédent', 'stickers'),
                'next'   => __('Sticker suivant', 'stickers'),
                'close'  => __('Fermer', 'stickers'),
            ),
        ));
    }

    /* ------------------------------------------------------------------
     * Rendu
     * ------------------------------------------------------------------ */

    public function render() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        if (!STK_Status::can_access()) {
            wc_print_notice(__('La bourse aux stickers n\'est pas encore ouverte.', 'stickers'), 'notice');
            return;
        }
        if (!STK_Status::is_open()) {
            wc_print_notice(__('Aperçu administrateur : la bourse est fermée aux clients, eux ne voient pas cet onglet.', 'stickers'), 'notice');
        }

        $view   = self::current_view();
        $member = STK_Members::get($user_id);
        $counts = STK_Exchanges::counts_for_user($user_id);

        self::template('nav', array(
            'view'   => self::hidden_views()[$view] ?? $view,
            'views'  => self::views(),
            'badges' => array('propositions' => $counts['received'], 'echanges' => $counts['action']),
        ));

        if (!$member && $view !== 'profil') {
            wc_print_notice(
                sprintf(
                    /* translators: %s: lien vers la page Pseudo et adresse */
                    __('Choisissez un pseudo pour participer à la bourse : %s', 'stickers'),
                    '<a href="' . esc_url(self::url('profil')) . '">' . esc_html__('choisir mon pseudo', 'stickers') . '</a>'
                ),
                'notice'
            );
        } elseif ($member && !STK_Terms::has_accepted($user_id) && $view !== 'profil') {
            wc_print_notice(
                sprintf(
                    /* translators: %s: lien vers le Profil */
                    __('Avant de participer à la bourse, lisez son fonctionnement et acceptez les conditions d\'utilisation : %s', 'stickers'),
                    '<a href="' . esc_url(self::url('profil')) . '">' . esc_html__('lire et accepter', 'stickers') . '</a>'
                ),
                'notice'
            );
        } elseif ($member && !STK_Members::has_address($member) && in_array($view, array('bourse', 'membre', 'propositions'), true)) {
            wc_print_notice(
                sprintf(
                    /* translators: %s: lien vers la page Pseudo et adresse */
                    __('Renseignez votre adresse pour pouvoir proposer ou accepter un échange : %s', 'stickers'),
                    '<a href="' . esc_url(self::url('profil')) . '">' . esc_html__('mon adresse', 'stickers') . '</a>'
                ),
                'notice'
            );
        }

        switch ($view) {
            case 'profil':
                self::template('profil', array(
                    'member'      => $member,
                    'countries'   => STK_Members::allowed_countries(),
                    'accepted'    => STK_Terms::has_accepted($user_id),
                    'accepted_at' => STK_Terms::accepted_at($user_id),
                    'terms_url'   => STK_Terms::page_url(),
                ));
                break;

            case 'bourse':
                $this->render_bourse($user_id, $member);
                break;

            case 'membre':
                $this->render_member($user_id, $member);
                break;

            case 'propositions':
                $this->render_list($user_id, 'propositions');
                break;

            case 'echanges':
                $this->render_list($user_id, 'echanges');
                break;

            case 'echange':
                $this->render_exchange($user_id);
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

    private function render_bourse($user_id, $member) {
        if (!$member || !STK_Terms::has_accepted($user_id)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtres de navigation.
        $filter = isset($_GET['numero']) ? trim(sanitize_text_field(wp_unslash($_GET['numero']))) : '';
        $page   = isset($_GET['page_bourse']) ? max(1, absint($_GET['page_bourse'])) : 1;
        // phpcs:enable

        $stickers   = STK_Catalog::get_all(true);
        $sticker_id = 0;
        if ($filter !== '') {
            foreach ($stickers as $sid => $sticker) {
                if (strcasecmp($sticker->numero, $filter) === 0) {
                    $sticker_id = $sid;
                    break;
                }
            }
        }

        $list  = ($filter !== '' && !$sticker_id) ? array() : STK_Market::members_overview($user_id, $sticker_id);
        $total = count($list);
        $pages = max(1, (int) ceil($total / self::BOURSE_PER_PAGE));
        $page  = min($page, $pages);

        self::template('bourse', array(
            'rows'     => array_slice($list, ($page - 1) * self::BOURSE_PER_PAGE, self::BOURSE_PER_PAGE),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'filter'   => $filter,
            'unknown'  => $filter !== '' && !$sticker_id,
            'stickers' => $stickers,
        ));
    }

    private function render_member($user_id, $member) {
        if (!$member || !STK_Terms::has_accepted($user_id)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $pseudo     = isset($_GET['membre']) ? sanitize_text_field(wp_unslash($_GET['membre'])) : '';
        $reply_id   = isset($_GET['reponse']) ? absint($_GET['reponse']) : 0;
        $suggestion = !$reply_id && !empty($_GET['suggestion']);
        // phpcs:enable

        $other = $pseudo !== '' ? STK_Members::get_by_pseudo($pseudo) : null;
        if (!$other || (int) $other->user_id === $user_id || !STK_Members::has_address($other) || !get_userdata((int) $other->user_id)) {
            wc_print_notice(__('Ce membre n\'est pas disponible dans la bourse.', 'stickers'), 'notice');
            return;
        }

        // Contre-proposition : pré-remplissage à partir de la proposition reçue.
        $parent  = null;
        $prefill = array('give' => array(), 'ask' => array());
        if ($reply_id) {
            $parent = STK_Exchanges::get($reply_id);
            if (!$parent || $parent->statut !== STK_Exchanges::PROPOSE || (int) $parent->recipient_id !== $user_id || (int) $parent->proposer_id !== (int) $other->user_id) {
                wc_print_notice(__('Cette proposition n\'est plus en attente : la contre-proposition n\'est pas possible.', 'stickers'), 'notice');
                $parent = null;
            } else {
                foreach (STK_Exchanges::get_items($reply_id) as $item) {
                    if ((int) $item->giver_id === $user_id) {
                        $prefill['give'][(int) $item->sticker_id] = (int) $item->quantite;
                    } else {
                        $prefill['ask'][(int) $item->sticker_id] = (int) $item->quantite;
                    }
                }
            }
        }

        $detail = STK_Market::member_detail($user_id, (int) $other->user_id);

        // Suggestion automatique : échange équilibré pré-rempli (modifiable avant envoi).
        if ($suggestion) {
            $prefill = STK_Suggestions::balanced_prefill($detail);
            if (!$prefill['give']) {
                wc_print_notice(__('Cette suggestion n\'est plus d\'actualité : les inventaires ont changé.', 'stickers'), 'notice');
                $suggestion = false;
            }
        }

        self::template('membre', array(
            'other'      => $other,
            'detail'     => $detail,
            'parent'     => $parent,
            'prefill'    => $prefill,
            'suggestion' => $suggestion,
            'ready'      => STK_Members::has_address($member),
        ));
    }

    private function render_list($user_id, $type) {
        if ($type === 'propositions') {
            $exchanges = STK_Exchanges::list_for_user($user_id, array(STK_Exchanges::PROPOSE, STK_Exchanges::REFUSE, STK_Exchanges::ANNULE, STK_Exchanges::EXPIRE, STK_Exchanges::REMPLACE), 100);
            // Les annulations d'échanges déjà acceptés sont rangées dans « Échanges ».
            $exchanges = array_values(array_filter($exchanges, function ($e) {
                return !($e->statut === STK_Exchanges::ANNULE && $e->accepted_at);
            }));
        } else {
            $exchanges = STK_Exchanges::list_for_user($user_id, array(STK_Exchanges::ACCEPTE, STK_Exchanges::LITIGE, STK_Exchanges::CLOTURE, STK_Exchanges::ANNULE), 100);
            $exchanges = array_values(array_filter($exchanges, function ($e) {
                return $e->statut !== STK_Exchanges::ANNULE || $e->accepted_at;
            }));
        }

        $ids     = wp_list_pluck($exchanges, 'id');
        $parties = array();
        foreach ($exchanges as $exchange) {
            $parties[] = STK_Exchanges::other_party($exchange, $user_id);
        }

        self::template($type, array(
            'suggestions' => $type === 'propositions' ? STK_Suggestions::for_user($user_id) : array(),
            'user_id'   => $user_id,
            'exchanges' => $exchanges,
            'items'     => STK_Exchanges::get_items_map($ids),
            'pseudos'   => STK_Members::pseudos($parties),
            'stickers'  => STK_Catalog::get_all(false),
        ));
    }

    private function render_exchange($user_id) {
        $id       = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $exchange = $id ? STK_Exchanges::get($id) : null;
        if (!$exchange || !STK_Exchanges::role($exchange, $user_id)) {
            wc_print_notice(__('Échange introuvable.', 'stickers'), 'error');
            return;
        }

        $other_id = STK_Exchanges::other_party($exchange, $user_id);
        $items    = STK_Exchanges::get_items($id);
        $other    = STK_Members::get($other_id);

        // Adresse de l'autre : seulement pendant un échange accepté, et seulement si je dois lui envoyer quelque chose.
        $show_address = $exchange->statut === STK_Exchanges::ACCEPTE && STK_Exchanges::gives($items, $user_id) && $other && STK_Members::has_address($other);

        self::template('echange', array(
            'user_id'      => $user_id,
            'exchange'     => $exchange,
            'items'        => $items,
            'other_pseudo' => STK_Members::pseudos(array($other_id))[$other_id],
            'other'        => $show_address ? $other : null,
            'stickers'     => STK_Catalog::get_all(false),
            'messages'     => STK_Messages::get_thread((int) $exchange->thread_id),
            'pseudos'      => STK_Members::pseudos(array((int) $exchange->proposer_id, (int) $exchange->recipient_id)),
            'actions'      => STK_Exchanges::pending_actions($exchange, $items, $user_id),
        ));
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
     * Libellés « ce que je donne / ce que je reçois » d'un échange.
     *
     * @return array( 'give' => string[], 'receive' => string[] )
     */
    public static function items_lines(array $items, $user_id, array $stickers) {
        $lines = array('give' => array(), 'receive' => array());
        foreach ($items as $item) {
            $sticker = $stickers[(int) $item->sticker_id] ?? null;
            $label   = $sticker ? sprintf(
                /* translators: 1: numéro, 2: nom */
                __('nº %1$s %2$s', 'stickers'),
                $sticker->numero,
                $sticker->nom
            ) : '#' . (int) $item->sticker_id;
            if ((int) $item->quantite > 1) {
                $label .= ' ×' . (int) $item->quantite;
            }
            $lines[(int) $item->giver_id === (int) $user_id ? 'give' : 'receive'][] = $label;
        }
        return $lines;
    }

    public static function date($gmt) {
        return $gmt ? get_date_from_gmt($gmt, get_option('date_format') . ' H:i') : '';
    }

    /* ------------------------------------------------------------------
     * Formulaires (fonctionnent sans JavaScript), traités sur le front
     * pour pouvoir utiliser les notices WooCommerce.
     * ------------------------------------------------------------------ */

    public function handle_post() {
        if (empty($_POST['stk_action']) || !is_user_logged_in() || !self::is_stickers_page()) {
            return;
        }

        if (!STK_Status::can_access()) {
            wc_add_notice(__('La bourse aux stickers n\'est pas encore ouverte.', 'stickers'), 'error');
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $action      = sanitize_key(wp_unslash($_POST['stk_action']));
        $user_id     = get_current_user_id();
        $exchange_id = isset($_POST['exchange_id']) ? absint($_POST['exchange_id']) : 0;
        $nonce       = isset($_POST['_stk_nonce']) ? sanitize_text_field(wp_unslash($_POST['_stk_nonce'])) : '';
        $nonce_action = 'stk_' . $action . ($exchange_id ? '_' . $exchange_id : '');

        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wc_add_notice(__('Session expirée, veuillez réessayer.', 'stickers'), 'error');
            $back = wp_get_referer();
            wp_safe_redirect($exchange_id ? self::exchange_url($exchange_id) : ($back ? $back : self::url(self::current_view())));
            exit;
        }

        $redirect = $exchange_id ? self::exchange_url($exchange_id) : self::url(self::current_view());
        $success  = '';

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

            case 'accept_rules':
                $result   = STK_Terms::accept($user_id, !empty($_POST['accept_rules']));
                $success  = __('Merci ! Vous avez accepté le fonctionnement et les conditions d\'utilisation de la bourse.', 'stickers');
                $redirect = self::url('profil');
                break;

            case 'save_pseudo':
                $result   = STK_Terms::has_accepted($user_id)
                    ? STK_Members::save_pseudo($user_id, $_POST['pseudo'] ?? '') // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans validate_pseudo().
                    : new WP_Error('stk_rules', __('Acceptez d\'abord le fonctionnement et les conditions d\'utilisation de la bourse.', 'stickers'));
                $success  = __('Pseudo enregistré.', 'stickers');
                $redirect = self::url('profil');
                break;

            case 'save_address':
                $result   = STK_Terms::has_accepted($user_id)
                    ? STK_Members::save_address($user_id, $_POST) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans validate_address().
                    : new WP_Error('stk_rules', __('Acceptez d\'abord le fonctionnement et les conditions d\'utilisation de la bourse.', 'stickers'));
                $success  = __('Adresse enregistrée.', 'stickers');
                $redirect = self::url('profil');
                break;

            case 'propose':
                $pseudo    = isset($_POST['recipient']) ? sanitize_text_field(wp_unslash($_POST['recipient'])) : '';
                $recipient = $pseudo !== '' ? STK_Members::get_by_pseudo($pseudo) : null;
                $give      = self::posted_quantities('give');
                $ask       = self::posted_quantities('ask');
                $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
                $source    = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : 'manuel';
                $result    = $recipient
                    ? STK_Exchanges::create($user_id, (int) $recipient->user_id, $give, $ask, $_POST['message'] ?? '', $parent_id, $source) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans create().
                    : new WP_Error('stk_recipient', __('Destinataire invalide.', 'stickers'));
                if (is_wp_error($result)) {
                    $redirect = $recipient ? self::member_url($recipient->pseudo, $parent_id ? array('reponse' => $parent_id) : ($source === 'suggestion' ? array('suggestion' => 1) : array())) : self::url('bourse');
                } else {
                    $success  = $parent_id ? __('Contre-proposition envoyée.', 'stickers') : __('Proposition envoyée.', 'stickers');
                    $redirect = self::exchange_url($result);
                }
                break;

            case 'accept':
                $result  = STK_Exchanges::accept($exchange_id, $user_id);
                $success = __('Échange accepté : l\'adresse d\'envoi est maintenant visible par celui qui doit expédier.', 'stickers');
                break;

            case 'refuse':
                $result  = STK_Exchanges::refuse($exchange_id, $user_id);
                $success = __('Proposition refusée.', 'stickers');
                break;

            case 'cancel':
                $result  = STK_Exchanges::cancel($exchange_id, $user_id);
                $success = __('Proposition annulée.', 'stickers');
                break;

            case 'ship':
                $result  = STK_Exchanges::ship($exchange_id, $user_id);
                $success = __('Expédition enregistrée.', 'stickers');
                break;

            case 'receive':
                $result  = STK_Exchanges::receive($exchange_id, $user_id);
                $success = $result === 'closed'
                    ? __('Réception confirmée : l\'échange est clôturé et les inventaires sont à jour.', 'stickers')
                    : __('Réception confirmée.', 'stickers');
                break;

            case 'request_cancel':
                $result  = STK_Exchanges::request_cancel($exchange_id, $user_id);
                $success = __('Demande d\'annulation envoyée : l\'autre membre doit la confirmer.', 'stickers');
                break;

            case 'withdraw_cancel':
            case 'decline_cancel':
            case 'confirm_cancel':
                $decision = substr($action, 0, -7);
                $result   = STK_Exchanges::answer_cancel($exchange_id, $user_id, $decision);
                $success  = $decision === 'confirm' ? __('Échange annulé.', 'stickers') : __('Demande d\'annulation retirée.', 'stickers');
                if ($decision === 'decline') {
                    $success = __('Demande d\'annulation refusée : l\'échange continue.', 'stickers');
                }
                break;

            case 'dispute':
                $result  = STK_Exchanges::dispute($exchange_id, $user_id, $_POST['reason'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans dispute().
                $success = __('Problème signalé : l\'équipe du site va examiner la situation. L\'échange est suspendu en attendant.', 'stickers');
                break;

            case 'post_message':
                $result  = STK_Messages::post($exchange_id, $user_id, $_POST['message'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans post().
                $success = __('Message envoyé.', 'stickers');
                $redirect .= '#stk-discussion';
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
     * @return array sticker_id => quantité saisie (brute, validée par STK_Exchanges::create()).
     */
    private static function posted_quantities($key) {
        $list = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans handle_post().
        foreach ((array) ($_POST[$key] ?? array()) as $sticker_id => $qty) {
            $list[absint($sticker_id)] = sanitize_text_field(wp_unslash($qty));
        }
        return $list;
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
        if (!STK_Status::can_access()) {
            wp_send_json_error(array('message' => __('La bourse aux stickers n\'est pas encore ouverte.', 'stickers')), 403);
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
