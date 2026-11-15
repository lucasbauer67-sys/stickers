<?php
/**
 * Administration : catalogue, membres, réglages.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Admin {

    const CAP          = 'manage_woocommerce';
    const CAP_SETTINGS = 'manage_options';
    const PER_PAGE     = 50;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'print_notice'));

        add_action('admin_post_stk_save_sticker', array($this, 'handle_save_sticker'));
        add_action('admin_post_stk_sticker_action', array($this, 'handle_sticker_action'));
        add_action('admin_post_stk_admin_pseudo', array($this, 'handle_admin_pseudo'));
    }

    public function add_menu() {
        add_menu_page(
            __('Bourse aux stickers', 'stickers'),
            __('Stickers', 'stickers'),
            self::CAP,
            'stk-catalogue',
            array($this, 'render_catalogue'),
            'dashicons-images-alt2',
            57
        );
        add_submenu_page('stk-catalogue', __('Catalogue des stickers', 'stickers'), __('Catalogue', 'stickers'), self::CAP, 'stk-catalogue', array($this, 'render_catalogue'));
        add_submenu_page('stk-catalogue', __('Membres de la bourse', 'stickers'), __('Membres', 'stickers'), self::CAP, 'stk-membres', array($this, 'render_members'));
        add_submenu_page('stk-catalogue', __('Réglages de la bourse', 'stickers'), __('Réglages', 'stickers'), self::CAP_SETTINGS, 'stk-reglages', array($this, 'render_settings'));
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'stk-') === false) {
            return;
        }
        wp_enqueue_style('stk-admin', STK_PLUGIN_URL . 'assets/css/admin.css', array(), STK_VERSION);
        if (strpos($hook, 'stk-catalogue') !== false) {
            wp_enqueue_media();
            wp_enqueue_script('stk-admin', STK_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), STK_VERSION, true);
            wp_localize_script('stk-admin', 'stkAdmin', array(
                'mediaTitle'    => __('Image du sticker', 'stickers'),
                'mediaButton'   => __('Utiliser cette image', 'stickers'),
                'confirmDelete' => __('Supprimer définitivement ce sticker ?', 'stickers'),
            ));
        }
    }

    /* ------------------------------------------------------------------
     * Notices (stockées par utilisateur pour ne jamais refléter l'URL)
     * ------------------------------------------------------------------ */

    private function set_notice($message, $type = 'success') {
        set_transient('stk_admin_notice_' . get_current_user_id(), array('message' => $message, 'type' => $type), 60);
    }

    public function print_notice() {
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, 'stk-') === false) {
            return;
        }
        $key    = 'stk_admin_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!$notice) {
            return;
        }
        delete_transient($key);
        $type = in_array($notice['type'], array('success', 'error', 'warning'), true) ? $notice['type'] : 'info';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($notice['message']));
    }

    private function redirect($page, array $args = array()) {
        wp_safe_redirect(add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php')));
        exit;
    }

    private function check_request($nonce_action, $cap = self::CAP) {
        if (!current_user_can($cap)) {
            wp_die(esc_html__('Action non autorisée.', 'stickers'), 403);
        }
        check_admin_referer($nonce_action);
    }

    /* ------------------------------------------------------------------
     * Catalogue
     * ------------------------------------------------------------------ */

    public function handle_save_sticker() {
        $this->check_request('stk_save_sticker');

        $id     = isset($_POST['sticker_id']) ? absint($_POST['sticker_id']) : 0;
        $result = STK_Catalog::save($_POST, $id); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans STK_Catalog::validate().

        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
            $this->redirect('stk-catalogue', $id ? array('edit' => $id) : array());
        }

        if ($id) {
            $this->set_notice(__('Sticker mis à jour.', 'stickers'));
            $this->redirect('stk-catalogue');
        }

        $this->set_notice(__('Sticker ajouté. Vous pouvez saisir le suivant.', 'stickers'));
        $this->redirect('stk-catalogue', array('suivant' => 1));
    }

    public function handle_sticker_action() {
        $id = isset($_POST['sticker_id']) ? absint($_POST['sticker_id']) : 0;
        $this->check_request('stk_sticker_action_' . $id);

        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        if (!STK_Catalog::get($id)) {
            $this->set_notice(__('Sticker introuvable.', 'stickers'), 'error');
            $this->redirect('stk-catalogue');
        }

        switch ($do) {
            case 'activate':
            case 'deactivate':
                STK_Catalog::set_active($id, $do === 'activate');
                $this->set_notice($do === 'activate' ? __('Sticker activé.', 'stickers') : __('Sticker désactivé : il n\'apparaît plus dans la bourse, les quantités déclarées sont conservées.', 'stickers'));
                break;
            case 'delete':
                $result = STK_Catalog::delete($id);
                if (is_wp_error($result)) {
                    $this->set_notice($result->get_error_message(), 'error');
                } else {
                    $this->set_notice(__('Sticker supprimé.', 'stickers'));
                }
                break;
        }
        $this->redirect('stk-catalogue');
    }

    public function render_catalogue() {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $edit_id  = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $editing  = $edit_id ? STK_Catalog::get($edit_id) : null;
        $stickers = STK_Catalog::get_all(false);
        $owners   = STK_Catalog::owners_count();
        $active   = STK_Catalog::count_active();

        $numero = $editing ? $editing->numero : STK_Catalog::suggest_next_numero();
        ?>
        <div class="wrap stk-admin">
            <h1><?php esc_html_e('Catalogue des stickers', 'stickers'); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: %d: nombre de stickers actifs */
                    esc_html(_n('%d sticker actif : c\'est le total de la collection affiché aux membres.', '%d stickers actifs : c\'est le total de la collection affiché aux membres.', $active, 'stickers')),
                    (int) $active
                );
                ?>
            </p>

            <div class="stk-admin-card">
                <h2><?php echo $editing ? esc_html(sprintf(
                    /* translators: %s: numéro du sticker */
                    __('Modifier le sticker nº %s', 'stickers'),
                    $editing->numero
                )) : esc_html__('Ajouter un sticker', 'stickers'); ?></h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stk-sticker-form">
                    <input type="hidden" name="action" value="stk_save_sticker">
                    <input type="hidden" name="sticker_id" value="<?php echo esc_attr($editing ? $editing->id : 0); ?>">
                    <?php wp_nonce_field('stk_save_sticker'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="stk-numero"><?php esc_html_e('Numéro', 'stickers'); ?></label></th>
                            <td>
                                <input type="text" id="stk-numero" name="numero" required maxlength="<?php echo esc_attr(STK_Catalog::NUMERO_MAX_LENGTH); ?>" class="regular-text" value="<?php echo esc_attr($numero); ?>">
                                <p class="description"><?php esc_html_e('Numéro unique dans la collection. Les numéros spéciaux sont acceptés (ex. 12a).', 'stickers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stk-nom"><?php esc_html_e('Nom', 'stickers'); ?></label></th>
                            <td><input type="text" id="stk-nom" name="nom" required maxlength="<?php echo esc_attr(STK_Catalog::NOM_MAX_LENGTH); ?>" class="regular-text" value="<?php echo esc_attr($editing ? $editing->nom : ''); ?>" <?php echo $editing ? '' : 'autofocus'; ?>></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="stk-ordre"><?php esc_html_e('Ordre d\'affichage', 'stickers'); ?></label></th>
                            <td>
                                <input type="number" id="stk-ordre" name="ordre" class="small-text" step="1" value="<?php echo esc_attr($editing ? $editing->ordre : ''); ?>">
                                <p class="description"><?php esc_html_e('Laisser vide pour un ordre automatique (numéro × 10). Exemple : 125 place un sticker entre le 12 et le 13.', 'stickers'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Image (facultative)', 'stickers'); ?></th>
                            <td class="stk-image-field">
                                <?php $image_id = $editing ? (int) $editing->image_id : 0; ?>
                                <input type="hidden" name="image_id" class="stk-image-id" value="<?php echo esc_attr($image_id); ?>">
                                <span class="stk-image-preview"><?php echo $image_id ? wp_get_attachment_image($image_id, 'thumbnail') : ''; ?></span>
                                <button type="button" class="button stk-image-choose"><?php esc_html_e('Choisir une image', 'stickers'); ?></button>
                                <button type="button" class="button-link stk-image-remove"<?php echo $image_id ? '' : ' hidden'; ?>><?php esc_html_e('Retirer l\'image', 'stickers'); ?></button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Statut', 'stickers'); ?></th>
                            <td><label><input type="checkbox" name="actif" value="1" <?php checked(!$editing || (int) $editing->actif === 1); ?>> <?php esc_html_e('Actif (visible dans la bourse)', 'stickers'); ?></label></td>
                        </tr>
                    </table>
                    <?php submit_button($editing ? __('Mettre à jour', 'stickers') : __('Ajouter le sticker', 'stickers'), 'primary', 'submit', false); ?>
                    <?php if ($editing) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=stk-catalogue')); ?>"><?php esc_html_e('Annuler', 'stickers'); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <h2><?php esc_html_e('Stickers du catalogue', 'stickers'); ?></h2>
            <?php if (!$stickers) : ?>
                <p><?php esc_html_e('Aucun sticker pour l\'instant.', 'stickers'); ?></p>
            <?php else : ?>
                <table class="widefat striped stk-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Ordre', 'stickers'); ?></th>
                            <th><?php esc_html_e('Numéro', 'stickers'); ?></th>
                            <th><?php esc_html_e('Image', 'stickers'); ?></th>
                            <th><?php esc_html_e('Nom', 'stickers'); ?></th>
                            <th><?php esc_html_e('Statut', 'stickers'); ?></th>
                            <th><?php esc_html_e('Membres qui l\'ont', 'stickers'); ?></th>
                            <th><?php esc_html_e('Actions', 'stickers'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stickers as $id => $sticker) :
                        $is_active = (int) $sticker->actif === 1;
                        $nonce     = 'stk_sticker_action_' . $id;
                        ?>
                        <tr class="<?php echo $is_active ? '' : 'stk-inactive'; ?>">
                            <td><?php echo esc_html($sticker->ordre); ?></td>
                            <td><strong><?php echo esc_html($sticker->numero); ?></strong></td>
                            <td><?php echo $sticker->image_id ? wp_get_attachment_image((int) $sticker->image_id, array(40, 40)) : '—'; ?></td>
                            <td><?php echo esc_html($sticker->nom); ?></td>
                            <td><?php echo $is_active ? esc_html__('Actif', 'stickers') : esc_html__('Désactivé', 'stickers'); ?></td>
                            <td><?php echo esc_html($owners[$id] ?? 0); ?></td>
                            <td class="stk-actions">
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'stk-catalogue', 'edit' => $id), admin_url('admin.php'))); ?>"><?php esc_html_e('Modifier', 'stickers'); ?></a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="stk_sticker_action">
                                    <input type="hidden" name="sticker_id" value="<?php echo esc_attr($id); ?>">
                                    <input type="hidden" name="do" value="<?php echo $is_active ? 'deactivate' : 'activate'; ?>">
                                    <?php wp_nonce_field($nonce); ?>
                                    <button type="submit" class="button button-small"><?php echo $is_active ? esc_html__('Désactiver', 'stickers') : esc_html__('Activer', 'stickers'); ?></button>
                                </form>
                                <?php if (!STK_Catalog::is_used($id)) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stk-delete-form">
                                        <input type="hidden" name="action" value="stk_sticker_action">
                                        <input type="hidden" name="sticker_id" value="<?php echo esc_attr($id); ?>">
                                        <input type="hidden" name="do" value="delete">
                                        <?php wp_nonce_field($nonce); ?>
                                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Supprimer', 'stickers'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Membres
     * ------------------------------------------------------------------ */

    public function handle_admin_pseudo() {
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $this->check_request('stk_admin_pseudo_' . $user_id);

        if (!STK_Members::get($user_id)) {
            $this->set_notice(__('Membre introuvable.', 'stickers'), 'error');
            $this->redirect('stk-membres');
        }

        $result = STK_Members::save_pseudo($user_id, $_POST['pseudo'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé dans validate_pseudo().
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } else {
            $this->set_notice(__('Pseudo modifié.', 'stickers'));
        }
        $this->redirect('stk-membres', array('membre' => $user_id));
    }

    public function render_members() {
        if (!current_user_can(self::CAP)) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation et recherche en lecture seule.
        $member_id = isset($_GET['membre']) ? absint($_GET['membre']) : 0;
        if ($member_id) {
            $this->render_member_detail($member_id);
            return;
        }

        $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable

        $list      = STK_Members::admin_list($search, $paged, self::PER_PAGE);
        $countries = STK_Members::allowed_countries();
        ?>
        <div class="wrap stk-admin">
            <h1><?php esc_html_e('Membres de la bourse', 'stickers'); ?></h1>
            <p class="description"><?php esc_html_e('Sont listés les clients qui ont choisi un pseudo.', 'stickers'); ?></p>

            <form method="get" class="stk-search">
                <input type="hidden" name="page" value="stk-membres">
                <label class="screen-reader-text" for="stk-search"><?php esc_html_e('Rechercher', 'stickers'); ?></label>
                <input type="search" id="stk-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Pseudo, nom ou email', 'stickers'); ?>">
                <button type="submit" class="button"><?php esc_html_e('Rechercher', 'stickers'); ?></button>
            </form>

            <table class="widefat striped stk-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Pseudo', 'stickers'); ?></th>
                        <th><?php esc_html_e('Compte client', 'stickers'); ?></th>
                        <th><?php esc_html_e('Adresse', 'stickers'); ?></th>
                        <th><?php esc_html_e('Possédés', 'stickers'); ?></th>
                        <th><?php esc_html_e('Doubles', 'stickers'); ?></th>
                        <th><?php esc_html_e('Dernière activité', 'stickers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$list['rows']) : ?>
                    <tr><td colspan="6"><?php esc_html_e('Aucun membre.', 'stickers'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($list['rows'] as $row) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg(array('page' => 'stk-membres', 'membre' => $row->user_id), admin_url('admin.php'))); ?>"><strong><?php echo esc_html($row->pseudo); ?></strong></a></td>
                        <td>
                            <?php if ($row->user_email) : ?>
                                <a href="<?php echo esc_url(get_edit_user_link((int) $row->user_id)); ?>"><?php echo esc_html($row->display_name); ?></a><br>
                                <span class="description"><?php echo esc_html($row->user_email); ?></span>
                            <?php else : ?>
                                <?php esc_html_e('Compte supprimé', 'stickers'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (STK_Members::has_address($row)) : ?>
                                <?php echo esc_html($row->ville . ' (' . ($countries[$row->pays]['label'] ?? $row->pays) . ')'); ?>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e('Incomplète', 'stickers'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->possedes); ?></td>
                        <td><?php echo esc_html($row->doubles); ?></td>
                        <td><?php echo $row->last_activity ? esc_html(get_date_from_gmt($row->last_activity, get_option('date_format') . ' H:i')) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $pages = (int) ceil($list['total'] / self::PER_PAGE);
            if ($pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(paginate_links(array(
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $pages,
                )));
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private function render_member_detail($user_id) {
        $member = STK_Members::get($user_id);
        $back   = admin_url('admin.php?page=stk-membres');
        if (!$member) {
            echo '<div class="wrap"><p>' . esc_html__('Membre introuvable.', 'stickers') . '</p><p><a href="' . esc_url($back) . '">' . esc_html__('Retour à la liste', 'stickers') . '</a></p></div>';
            return;
        }

        $user      = get_userdata($user_id);
        $stickers  = STK_Catalog::get_all(true);
        $inventory = STK_Inventory::get_user_inventory($user_id);
        $reserved  = STK_Inventory::get_reserved($user_id);
        $stats     = STK_Inventory::stats($inventory, $stickers);
        ?>
        <div class="wrap stk-admin">
            <h1><?php echo esc_html(sprintf(
                /* translators: %s: pseudo */
                __('Membre : %s', 'stickers'),
                $member->pseudo
            )); ?></h1>
            <p><a href="<?php echo esc_url($back); ?>">← <?php esc_html_e('Retour à la liste', 'stickers'); ?></a></p>

            <div class="stk-admin-columns">
                <div class="stk-admin-card">
                    <h2><?php esc_html_e('Pseudo', 'stickers'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="stk_admin_pseudo">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr($user_id); ?>">
                        <?php wp_nonce_field('stk_admin_pseudo_' . $user_id); ?>
                        <input type="text" name="pseudo" required maxlength="<?php echo esc_attr(STK_Members::PSEUDO_MAX); ?>" value="<?php echo esc_attr($member->pseudo); ?>">
                        <button type="submit" class="button"><?php esc_html_e('Modifier le pseudo', 'stickers'); ?></button>
                    </form>

                    <h2><?php esc_html_e('Compte client', 'stickers'); ?></h2>
                    <?php if ($user) : ?>
                        <p><a href="<?php echo esc_url(get_edit_user_link($user_id)); ?>"><?php echo esc_html($user->display_name); ?></a> — <?php echo esc_html($user->user_email); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e('Compte supprimé', 'stickers'); ?></p>
                    <?php endif; ?>

                    <h2><?php esc_html_e('Adresse de la bourse', 'stickers'); ?></h2>
                    <?php if (STK_Members::has_address($member)) : ?>
                        <address>
                            <?php echo esc_html($member->destinataire); ?><br>
                            <?php echo esc_html($member->adresse_1); ?><br>
                            <?php if ($member->adresse_2 !== '') : ?><?php echo esc_html($member->adresse_2); ?><br><?php endif; ?>
                            <?php echo esc_html($member->code_postal . ' ' . $member->ville); ?><br>
                            <?php echo esc_html(STK_Members::country_label($member->pays)); ?>
                        </address>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('Adresse non renseignée.', 'stickers'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="stk-admin-card">
                    <h2><?php esc_html_e('Inventaire', 'stickers'); ?></h2>
                    <p>
                        <?php
                        printf(
                            /* translators: 1: possédés, 2: total, 3: manquants, 4: doubles */
                            esc_html__('%1$d / %2$d possédés · %3$d manquants · %4$d doubles', 'stickers'),
                            (int) $stats['owned'],
                            (int) $stats['total'],
                            (int) $stats['missing'],
                            (int) $stats['doubles']
                        );
                        ?>
                    </p>
                    <ul class="stk-admin-grid">
                        <?php foreach ($stickers as $id => $sticker) :
                            $qty = (int) ($inventory[$id] ?? 0);
                            ?>
                            <li class="stk-state-<?php echo esc_attr(STK_Inventory::state($qty)); ?>" title="<?php echo esc_attr(STK_Catalog::label($sticker)); ?>">
                                <strong><?php echo esc_html($sticker->numero); ?></strong>
                                <span>×<?php echo esc_html($qty); ?></span>
                                <?php if (!empty($reserved[$id])) : ?>
                                    <em><?php echo esc_html(sprintf(
                                        /* translators: %d: nombre d'exemplaires promis */
                                        __('%d promis', 'stickers'),
                                        (int) $reserved[$id]
                                    )); ?></em>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Réglages
     * ------------------------------------------------------------------ */

    public function register_settings() {
        register_setting('stk_settings', 'stk_collection_name', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_collection_name'),
            'default'           => '',
        ));
        register_setting('stk_settings', 'stk_github_token', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_github_token'),
            'default'           => '',
        ));
        register_setting('stk_settings', 'stk_delete_data_on_uninstall', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => '0',
        ));
    }

    public function sanitize_collection_name($value) {
        return mb_substr(sanitize_text_field((string) $value), 0, 190);
    }

    /**
     * Champ vide = on garde le token existant ; case « supprimer » = on l'efface.
     */
    public function sanitize_github_token($value) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par options.php.
        if (!empty($_POST['stk_github_token_delete'])) {
            delete_transient('stk_github_release');
            return '';
        }
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '') {
            return (string) get_option('stk_github_token', '');
        }
        delete_transient('stk_github_release');
        return $value;
    }

    public function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }

    public function render_settings() {
        if (!current_user_can(self::CAP_SETTINGS)) {
            return;
        }
        $has_token = get_option('stk_github_token', '') !== '';
        ?>
        <div class="wrap stk-admin">
            <h1><?php esc_html_e('Réglages de la bourse', 'stickers'); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stk_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="stk-collection-name"><?php esc_html_e('Nom de la collection', 'stickers'); ?></label></th>
                        <td>
                            <input type="text" id="stk-collection-name" name="stk_collection_name" class="regular-text" maxlength="190" value="<?php echo esc_attr(get_option('stk_collection_name', '')); ?>">
                            <p class="description"><?php esc_html_e('Affiché en titre de l\'inventaire des membres. Laisser vide pour ne rien afficher.', 'stickers'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="stk-github-token"><?php esc_html_e('Token GitHub (mises à jour)', 'stickers'); ?></label></th>
                        <td>
                            <input type="password" id="stk-github-token" name="stk_github_token" class="regular-text" autocomplete="new-password" value=""
                                placeholder="<?php echo $has_token ? esc_attr__('Token enregistré — laisser vide pour le conserver', 'stickers') : ''; ?>">
                            <?php if ($has_token) : ?>
                                <br><label><input type="checkbox" name="stk_github_token_delete" value="1"> <?php esc_html_e('Supprimer le token enregistré', 'stickers'); ?></label>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('Nécessaire pour que WordPress détecte les nouvelles versions publiées sur le dépôt privé GitHub « stickers » (Releases).', 'stickers'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Désinstallation', 'stickers'); ?></th>
                        <td>
                            <input type="hidden" name="stk_delete_data_on_uninstall" value="0">
                            <label><input type="checkbox" name="stk_delete_data_on_uninstall" value="1" <?php checked(get_option('stk_delete_data_on_uninstall', '0'), '1'); ?>>
                                <?php esc_html_e('Supprimer toutes les données de la bourse (catalogue, inventaires, membres, échanges, réglages) quand le plugin est supprimé', 'stickers'); ?></label>
                            <p class="description"><?php esc_html_e('Décoché par défaut : la suppression du plugin conserve alors toutes les données.', 'stickers'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
