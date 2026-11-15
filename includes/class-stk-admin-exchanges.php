<?php
/**
 * Administration des échanges : liste, détail, historique, modération des messages.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Admin_Exchanges {

    const PER_PAGE = 50;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 20);
        add_action('admin_post_stk_message_visibility', array($this, 'handle_message_visibility'));
    }

    public function add_menu() {
        add_submenu_page('stk-catalogue', __('Échanges de stickers', 'stickers'), __('Échanges', 'stickers'), STK_Admin::CAP, 'stk-echanges', array($this, 'render'));
    }

    public static function log_labels() {
        return array(
            'propose'         => __('Proposition envoyée', 'stickers'),
            'counter_created' => __('Contre-proposition envoyée', 'stickers'),
            'counter'         => __('Remplacée par une contre-proposition', 'stickers'),
            'accept'          => __('Acceptée', 'stickers'),
            'refuse'          => __('Refusée', 'stickers'),
            'cancel'          => __('Annulée par le proposeur', 'stickers'),
            'ship'            => __('Expédition indiquée', 'stickers'),
            'receive'         => __('Réception confirmée', 'stickers'),
            'close'           => __('Clôturé, inventaires mis à jour', 'stickers'),
            'cap'             => __('Quantité plafonnée à 9 lors de la clôture', 'stickers'),
            'cancel_request'  => __('Annulation demandée', 'stickers'),
            'cancel_withdraw' => __('Demande d\'annulation retirée', 'stickers'),
            'cancel_decline'  => __('Demande d\'annulation refusée', 'stickers'),
            'cancel_confirm'  => __('Annulation confirmée (accord des deux)', 'stickers'),
            'cancel_void'     => __('Demande d\'annulation caduque (expédition)', 'stickers'),
            'message_hidden'  => __('Message masqué', 'stickers'),
            'message_shown'   => __('Message réaffiché', 'stickers'),
        );
    }

    public function handle_message_visibility() {
        $message_id = isset($_POST['message_id']) ? absint($_POST['message_id']) : 0;
        if (!current_user_can(STK_Admin::CAP)) {
            wp_die(esc_html__('Action non autorisée.', 'stickers'), 403);
        }
        check_admin_referer('stk_message_visibility_' . $message_id);

        $hide    = !empty($_POST['hide']);
        $message = STK_Messages::get($message_id);
        $result  = STK_Messages::set_hidden($message_id, $hide, get_current_user_id());

        set_transient('stk_admin_notice_' . get_current_user_id(), array(
            'message' => is_wp_error($result) ? $result->get_error_message() : ($hide ? __('Message masqué.', 'stickers') : __('Message réaffiché.', 'stickers')),
            'type'    => is_wp_error($result) ? 'error' : 'success',
        ), 60);

        wp_safe_redirect(add_query_arg(array('page' => 'stk-echanges', 'echange' => $message ? (int) $message->exchange_id : 0), admin_url('admin.php')));
        exit;
    }

    public function render() {
        if (!current_user_can(STK_Admin::CAP)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation en lecture seule.
        $id = isset($_GET['echange']) ? absint($_GET['echange']) : 0;
        if ($id) {
            $this->render_detail($id);
        } else {
            $this->render_list();
        }
    }

    private function render_list() {
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $status = isset($_GET['statut']) ? sanitize_key(wp_unslash($_GET['statut'])) : '';
        $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable

        $labels    = STK_Exchanges::labels();
        $exchanges = STK_Database::table('exchanges');
        $members   = STK_Database::table('members');

        $where  = array('1=1');
        $params = array();
        if ($status !== '' && isset($labels[$status])) {
            $where[]  = 'e.statut = %s';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[]  = '(mp.pseudo LIKE %s OR mr.pseudo LIKE %s OR e.id = %d)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = absint($search);
        }
        $where_sql = implode(' AND ', $where);
        $from      = "FROM $exchanges e LEFT JOIN $members mp ON mp.user_id = e.proposer_id LEFT JOIN $members mr ON mr.user_id = e.recipient_id WHERE $where_sql";

        $count_sql = "SELECT COUNT(*) $from";
        $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $list_sql  = "SELECT e.*, mp.pseudo AS proposer_pseudo, mr.pseudo AS recipient_pseudo $from ORDER BY e.updated_at DESC, e.id DESC LIMIT %d OFFSET %d";
        $rows      = (array) $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, array(self::PER_PAGE, ($paged - 1) * self::PER_PAGE))));

        $counts = array();
        foreach ((array) $wpdb->get_results("SELECT statut, COUNT(*) AS n FROM $exchanges GROUP BY statut") as $row) {
            $counts[$row->statut] = (int) $row->n;
        }
        $items = STK_Exchanges::get_items_map(wp_list_pluck($rows, 'id'));
        $base  = admin_url('admin.php?page=stk-echanges');
        ?>
        <div class="wrap stk-admin">
            <h1><?php esc_html_e('Échanges de stickers', 'stickers'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url($base); ?>" class="<?php echo $status === '' ? 'current' : ''; ?>"><?php esc_html_e('Tous', 'stickers'); ?> <span class="count">(<?php echo esc_html(array_sum($counts)); ?>)</span></a></li>
                <?php foreach ($labels as $key => $label) : ?>
                    <?php if (!empty($counts[$key])) : ?>
                        <li>| <a href="<?php echo esc_url(add_query_arg('statut', $key, $base)); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html($label); ?> <span class="count">(<?php echo esc_html($counts[$key]); ?>)</span></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <form method="get" class="stk-search" style="clear:both">
                <input type="hidden" name="page" value="stk-echanges">
                <?php if ($status !== '') : ?><input type="hidden" name="statut" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
                <label class="screen-reader-text" for="stk-search"><?php esc_html_e('Rechercher', 'stickers'); ?></label>
                <input type="search" id="stk-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Pseudo ou numéro d\'échange', 'stickers'); ?>">
                <button type="submit" class="button"><?php esc_html_e('Rechercher', 'stickers'); ?></button>
            </form>

            <table class="widefat striped stk-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nº', 'stickers'); ?></th>
                        <th><?php esc_html_e('Proposeur → destinataire', 'stickers'); ?></th>
                        <th><?php esc_html_e('Statut', 'stickers'); ?></th>
                        <th><?php esc_html_e('Stickers', 'stickers'); ?></th>
                        <th><?php esc_html_e('Dernière mise à jour', 'stickers'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="5"><?php esc_html_e('Aucun échange.', 'stickers'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) :
                    $count = 0;
                    foreach ($items[(int) $row->id] ?? array() as $item) {
                        $count += (int) $item->quantite;
                    }
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg('echange', (int) $row->id, $base)); ?>"><strong><?php echo esc_html($row->id); ?></strong></a></td>
                        <td><?php echo esc_html(($row->proposer_pseudo ?: __('Membre supprimé', 'stickers')) . ' → ' . ($row->recipient_pseudo ?: __('Membre supprimé', 'stickers'))); ?></td>
                        <td><?php echo esc_html(STK_Exchanges::label($row->statut)); ?></td>
                        <td><?php echo esc_html($count); ?></td>
                        <td><?php echo esc_html(get_date_from_gmt($row->updated_at, get_option('date_format') . ' H:i')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $pages = (int) ceil($total / self::PER_PAGE);
            if ($pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages)));
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private function render_detail($id) {
        $exchange = STK_Exchanges::get($id);
        $back     = admin_url('admin.php?page=stk-echanges');
        if (!$exchange) {
            echo '<div class="wrap"><p>' . esc_html__('Échange introuvable.', 'stickers') . '</p><p><a href="' . esc_url($back) . '">' . esc_html__('Retour à la liste', 'stickers') . '</a></p></div>';
            return;
        }

        $items    = STK_Exchanges::get_items($id);
        $stickers = STK_Catalog::get_all(false);
        $pseudos  = STK_Members::pseudos(array((int) $exchange->proposer_id, (int) $exchange->recipient_id));
        $log      = STK_Exchanges::get_log($id);
        $messages = STK_Messages::get_thread((int) $exchange->thread_id);
        $labels   = self::log_labels();
        $fmt      = get_option('date_format') . ' H:i';
        $date     = static function ($gmt) use ($fmt) {
            return $gmt ? get_date_from_gmt($gmt, $fmt) : '—';
        };
        $member_link = static function ($user_id) use ($pseudos) {
            return '<a href="' . esc_url(add_query_arg(array('page' => 'stk-membres', 'membre' => (int) $user_id), admin_url('admin.php'))) . '">' . esc_html($pseudos[(int) $user_id] ?? '') . '</a>';
        };
        ?>
        <div class="wrap stk-admin">
            <h1><?php echo esc_html(sprintf(
                /* translators: %d: numéro */
                __('Échange nº %d', 'stickers'),
                (int) $exchange->id
            )); ?> — <?php echo esc_html(STK_Exchanges::label($exchange->statut)); ?></h1>
            <p><a href="<?php echo esc_url($back); ?>">← <?php esc_html_e('Retour à la liste', 'stickers'); ?></a></p>

            <div class="stk-admin-columns">
                <div class="stk-admin-card">
                    <h2><?php esc_html_e('Déroulement', 'stickers'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr><th><?php esc_html_e('Proposeur', 'stickers'); ?></th><td><?php echo wp_kses_post($member_link($exchange->proposer_id)); ?></td></tr>
                        <tr><th><?php esc_html_e('Destinataire', 'stickers'); ?></th><td><?php echo wp_kses_post($member_link($exchange->recipient_id)); ?></td></tr>
                        <?php if ((int) $exchange->parent_id) : ?>
                            <tr><th><?php esc_html_e('Contre-proposition de', 'stickers'); ?></th><td><a href="<?php echo esc_url(add_query_arg('echange', (int) $exchange->parent_id, $back)); ?>">nº <?php echo esc_html($exchange->parent_id); ?></a></td></tr>
                        <?php endif; ?>
                        <tr><th><?php esc_html_e('Proposé le', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->created_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Accepté le', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->accepted_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Expédié par le proposeur', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->proposer_shipped_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Expédié par le destinataire', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->recipient_shipped_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Reçu par le proposeur', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->proposer_received_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Reçu par le destinataire', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->recipient_received_at)); ?></td></tr>
                        <tr><th><?php esc_html_e('Terminé le', 'stickers'); ?></th><td><?php echo esc_html($date($exchange->closed_at)); ?></td></tr>
                        <?php if ((int) $exchange->cancel_requested_by) : ?>
                            <tr><th><?php esc_html_e('Annulation demandée par', 'stickers'); ?></th><td><?php echo wp_kses_post($member_link($exchange->cancel_requested_by)); ?></td></tr>
                        <?php endif; ?>
                    </table>

                    <h2><?php esc_html_e('Contenu', 'stickers'); ?></h2>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Sticker', 'stickers'); ?></th><th><?php esc_html_e('Quantité', 'stickers'); ?></th><th><?php esc_html_e('De → à', 'stickers'); ?></th><th><?php esc_html_e('Réservé', 'stickers'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item) :
                            $sticker = $stickers[(int) $item->sticker_id] ?? null;
                            ?>
                            <tr>
                                <td><?php echo $sticker ? esc_html(STK_Catalog::label($sticker)) : '#' . esc_html($item->sticker_id); ?></td>
                                <td><?php echo esc_html($item->quantite); ?></td>
                                <td><?php echo esc_html(($pseudos[(int) $item->giver_id] ?? '') . ' → ' . ($pseudos[(int) $item->receiver_id] ?? '')); ?></td>
                                <td><?php echo (int) $item->reserve ? esc_html__('oui', 'stickers') : esc_html__('non', 'stickers'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h2><?php esc_html_e('Historique', 'stickers'); ?></h2>
                    <ul class="stk-log">
                        <?php foreach ($log as $entry) : ?>
                            <li>
                                <?php echo esc_html($date($entry->created_at)); ?> —
                                <?php echo esc_html($labels[$entry->action] ?? $entry->action); ?>
                                <?php if ((int) $entry->user_id) :
                                    $who = get_userdata((int) $entry->user_id);
                                    ?>
                                    (<?php echo esc_html($pseudos[(int) $entry->user_id] ?? ($who ? $who->display_name : '#' . $entry->user_id)); ?>)
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="stk-admin-card">
                    <h2><?php esc_html_e('Discussion', 'stickers'); ?></h2>
                    <?php if (!$messages) : ?>
                        <p class="description"><?php esc_html_e('Aucun message.', 'stickers'); ?></p>
                    <?php endif; ?>
                    <?php foreach ($messages as $message) :
                        $hidden = (int) $message->hidden === 1;
                        ?>
                        <div class="stk-admin-message<?php echo $hidden ? ' is-hidden' : ''; ?>">
                            <p class="stk-admin-message-meta">
                                <strong><?php echo esc_html($pseudos[(int) $message->user_id] ?? ''); ?></strong>
                                — <?php echo esc_html($date($message->created_at)); ?>
                                <?php if ((int) $message->exchange_id !== (int) $exchange->id) : ?>
                                    — <a href="<?php echo esc_url(add_query_arg('echange', (int) $message->exchange_id, $back)); ?>">nº <?php echo esc_html($message->exchange_id); ?></a>
                                <?php endif; ?>
                                <?php if ($hidden) : ?> — <em><?php esc_html_e('masqué pour les membres', 'stickers'); ?></em><?php endif; ?>
                            </p>
                            <p><?php echo nl2br(esc_html($message->message)); ?></p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="stk_message_visibility">
                                <input type="hidden" name="message_id" value="<?php echo esc_attr($message->id); ?>">
                                <input type="hidden" name="hide" value="<?php echo $hidden ? '0' : '1'; ?>">
                                <?php wp_nonce_field('stk_message_visibility_' . $message->id); ?>
                                <button type="submit" class="button button-small"><?php echo $hidden ? esc_html__('Réafficher', 'stickers') : esc_html__('Masquer', 'stickers'); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
