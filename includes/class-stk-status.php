<?php
/**
 * Ouverture / fermeture de la bourse aux clients.
 *
 * Fermée par défaut : on peut installer le plugin et saisir le catalogue sans que les clients
 * ne voient rien. Pendant la fermeture :
 *  - l'onglet « Stickers » n'apparaît pas dans Mon compte des clients, l'adresse directe affiche
 *    « pas encore ouverte », formulaires et enregistrements à la volée sont refusés ;
 *  - les administrateurs de la boutique gardent un aperçu complet (bandeau « fermée aux clients ») ;
 *  - la page des CGU passe en « privée » (visible des seuls administrateurs) ;
 *  - la tâche horaire (expirations, rappels, purge) est suspendue.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Status {

    const OPTION = 'stk_enabled';

    public function __construct() {
        add_action('admin_post_stk_toggle_bourse', array($this, 'handle_toggle'));
        add_action('admin_notices', array($this, 'closed_notice'));
        add_action('init', array(__CLASS__, 'maybe_init_option'), 31);
    }

    /**
     * Première exécution (installation ou mise à jour depuis une version sans interrupteur) :
     * la bourse démarre fermée et la page des CGU est alignée sur cet état.
     */
    public static function maybe_init_option() {
        if (get_option(self::OPTION, false) === false) {
            add_option(self::OPTION, '0');
            self::sync_terms_page();
        }
    }

    public static function is_open() {
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * Le visiteur courant peut-il utiliser la bourse ? (ouverte, ou aperçu administrateur)
     */
    public static function can_access() {
        return self::is_open() || current_user_can(STK_Admin::CAP);
    }

    public static function set_open($open) {
        update_option(self::OPTION, $open ? '1' : '0');
        self::sync_terms_page();
    }

    /**
     * La page des CGU suit l'état de la bourse : publiée si ouverte, privée si fermée.
     */
    public static function sync_terms_page() {
        $page_id = (int) get_option(STK_Terms::OPTION_PAGE, 0);
        if (!$page_id) {
            return;
        }
        $status = get_post_status($page_id);
        $target = self::is_open() ? 'publish' : 'private';
        if (in_array($status, array('publish', 'private'), true) && $status !== $target) {
            wp_update_post(array('ID' => $page_id, 'post_status' => $target));
        }
    }

    /**
     * Bouton d'ouverture / fermeture (Catalogue et Réglages).
     */
    public static function render_box() {
        $open = self::is_open();
        ?>
        <div class="stk-admin-card stk-status-box <?php echo $open ? 'is-open' : 'is-closed'; ?>">
            <h2>
                <?php echo $open ? esc_html__('Bourse ouverte aux clients', 'stickers') : esc_html__('Bourse fermée aux clients', 'stickers'); ?>
            </h2>
            <p>
                <?php
                echo $open
                    ? esc_html__('Les clients voient l\'onglet « Stickers » dans Mon compte et peuvent échanger.', 'stickers')
                    : esc_html__('Les clients ne voient rien : ni l\'onglet « Stickers » dans Mon compte, ni la page des conditions. Vous pouvez préparer le catalogue et tester la bourse depuis votre compte administrateur. Les rappels et expirations automatiques sont suspendus.', 'stickers');
                ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stk-resolve-form"
                data-confirm="<?php echo $open ? esc_attr__('Fermer la bourse ? Les clients ne la verront plus ; les échanges en cours seront suspendus jusqu\'à la réouverture.', 'stickers') : esc_attr__('Ouvrir la bourse à tous les clients ?', 'stickers'); ?>">
                <input type="hidden" name="action" value="stk_toggle_bourse">
                <input type="hidden" name="open" value="<?php echo $open ? '0' : '1'; ?>">
                <?php wp_nonce_field('stk_toggle_bourse'); ?>
                <button type="submit" class="button <?php echo $open ? '' : 'button-primary'; ?>">
                    <?php echo $open ? esc_html__('Fermer la bourse', 'stickers') : esc_html__('Ouvrir la bourse aux clients', 'stickers'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    public function handle_toggle() {
        if (!current_user_can(STK_Admin::CAP)) {
            wp_die(esc_html__('Action non autorisée.', 'stickers'), 403);
        }
        check_admin_referer('stk_toggle_bourse');
        $open = !empty($_POST['open']) && $_POST['open'] === '1';
        self::set_open($open);
        set_transient('stk_admin_notice_' . get_current_user_id(), array(
            'message' => $open ? __('La bourse est maintenant ouverte aux clients.', 'stickers') : __('La bourse est fermée : seuls les administrateurs la voient.', 'stickers'),
            'type'    => 'success',
        ), 60);
        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url('admin.php?page=stk-catalogue'));
        exit;
    }

    /**
     * Rappel discret sur les pages du plugin tant que la bourse est fermée.
     */
    public function closed_notice() {
        $screen = get_current_screen();
        if (self::is_open() || !$screen || strpos((string) $screen->id, 'stk-') === false || strpos((string) $screen->id, 'stk-catalogue') !== false) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('La bourse est fermée aux clients (aperçu administrateur uniquement). Pour l\'ouvrir : Stickers → Catalogue.', 'stickers') . '</p></div>';
    }
}
