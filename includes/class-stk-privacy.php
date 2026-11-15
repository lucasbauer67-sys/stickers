<?php
/**
 * RGPD : export et effacement via les outils natifs de WordPress (Outils → Exporter / Effacer
 * les données personnelles), suppression d'un compte, purge facultative des membres inactifs.
 *
 * Effacement d'un membre :
 *  - propositions en attente : annulées (s'il est proposeur) ou refusées (s'il est destinataire) ;
 *  - échanges acceptés : passés en litige pour arbitrage par l'admin ;
 *  - pseudo, adresse et inventaire : supprimés ; texte de ses messages : effacé ;
 *  - l'historique des échanges est conservé pour l'autre membre, qui voit « Membre supprimé ».
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Privacy {

    public function __construct() {
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array($this, 'register_eraser'));
        add_action('delete_user', array(__CLASS__, 'on_delete_user'));
        add_action('admin_init', array($this, 'privacy_policy_content'));
    }

    public function register_exporter($exporters) {
        $exporters['stickers'] = array(
            'exporter_friendly_name' => __('Bourse aux stickers', 'stickers'),
            'callback'               => array(__CLASS__, 'export'),
        );
        return $exporters;
    }

    public function register_eraser($erasers) {
        $erasers['stickers'] = array(
            'eraser_friendly_name' => __('Bourse aux stickers', 'stickers'),
            'callback'             => array(__CLASS__, 'erase'),
        );
        return $erasers;
    }

    public function privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Bourse aux stickers', 'stickers'),
            wpautop(__('Si vous participez à la bourse d\'échange de stickers, nous enregistrons le pseudo que vous choisissez, l\'adresse postale que vous saisissez pour les échanges, votre inventaire de stickers, vos propositions et échanges, et les messages échangés avec les autres membres. Votre pseudo et votre inventaire (doubles et numéros manquants) sont visibles des autres membres connectés. Votre nom et votre adresse ne sont communiqués qu\'au membre avec qui vous avez accepté un échange, et seulement jusqu\'à la clôture de cet échange. Les messages sont visibles par l\'autre membre de l\'échange et par l\'équipe du site. Vous pouvez demander l\'export ou l\'effacement de ces données.', 'stickers'))
        );
    }

    public static function on_delete_user($user_id) {
        self::erase_user((int) $user_id);
    }

    /* ------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------ */

    public static function export($email, $page = 1) {
        $user = get_user_by('email', $email);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }
        $uid    = (int) $user->ID;
        $data   = array();
        $member = STK_Members::get($uid);

        if ($member) {
            $data[] = array(
                'group_id'    => 'stk-profile',
                'group_label' => __('Bourse aux stickers : profil', 'stickers'),
                'item_id'     => 'stk-profile-' . $uid,
                'data'        => array(
                    array('name' => __('Pseudo', 'stickers'), 'value' => $member->pseudo),
                    array('name' => __('Destinataire', 'stickers'), 'value' => $member->destinataire),
                    array('name' => __('Adresse', 'stickers'), 'value' => trim($member->adresse_1 . ' ' . $member->adresse_2)),
                    array('name' => __('Code postal', 'stickers'), 'value' => $member->code_postal),
                    array('name' => __('Ville', 'stickers'), 'value' => $member->ville),
                    array('name' => __('Pays', 'stickers'), 'value' => STK_Members::country_label($member->pays)),
                ),
            );
        }

        $stickers  = STK_Catalog::get_all(false);
        $inventory = array();
        foreach (STK_Inventory::get_user_inventory($uid) as $sid => $qty) {
            if ($qty > 0) {
                $inventory[] = array('name' => isset($stickers[$sid]) ? STK_Catalog::label($stickers[$sid]) : '#' . $sid, 'value' => (string) $qty);
            }
        }
        if ($inventory) {
            $data[] = array('group_id' => 'stk-inventory', 'group_label' => __('Bourse aux stickers : inventaire', 'stickers'), 'item_id' => 'stk-inventory-' . $uid, 'data' => $inventory);
        }

        $all = array(STK_Exchanges::PROPOSE, STK_Exchanges::ACCEPTE, STK_Exchanges::CLOTURE, STK_Exchanges::REFUSE, STK_Exchanges::ANNULE, STK_Exchanges::EXPIRE, STK_Exchanges::REMPLACE, STK_Exchanges::LITIGE);
        $exchanges = STK_Exchanges::list_for_user($uid, $all, 10000);
        $items     = STK_Exchanges::get_items_map(wp_list_pluck($exchanges, 'id'));
        $threads   = array();
        foreach ($exchanges as $exchange) {
            $lines  = STK_Frontend::items_lines($items[(int) $exchange->id] ?? array(), $uid, $stickers);
            $data[] = array(
                'group_id'    => 'stk-exchanges',
                'group_label' => __('Bourse aux stickers : échanges', 'stickers'),
                'item_id'     => 'stk-exchange-' . $exchange->id,
                'data'        => array(
                    array('name' => __('Numéro', 'stickers'), 'value' => (string) $exchange->id),
                    array('name' => __('Statut', 'stickers'), 'value' => STK_Exchanges::label($exchange->statut)),
                    array('name' => __('Date', 'stickers'), 'value' => (string) $exchange->created_at),
                    array('name' => __('Vous donnez', 'stickers'), 'value' => implode(', ', $lines['give'])),
                    array('name' => __('Vous recevez', 'stickers'), 'value' => implode(', ', $lines['receive'])),
                ),
            );
            $threads[(int) $exchange->thread_id] = true;
        }

        foreach (array_keys($threads) as $thread_id) {
            foreach (STK_Messages::get_thread($thread_id) as $message) {
                if ((int) $message->user_id !== $uid || $message->message === '') {
                    continue;
                }
                $data[] = array(
                    'group_id'    => 'stk-messages',
                    'group_label' => __('Bourse aux stickers : messages', 'stickers'),
                    'item_id'     => 'stk-message-' . $message->id,
                    'data'        => array(
                        array('name' => __('Date', 'stickers'), 'value' => (string) $message->created_at),
                        array('name' => __('Échange', 'stickers'), 'value' => (string) $message->exchange_id),
                        array('name' => __('Message', 'stickers'), 'value' => $message->message),
                    ),
                );
            }
        }

        return array('data' => $data, 'done' => true);
    }

    /* ------------------------------------------------------------------
     * Effacement
     * ------------------------------------------------------------------ */

    public static function erase($email, $page = 1) {
        $user = get_user_by('email', $email);
        if (!$user) {
            return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
        }
        $result   = self::erase_user((int) $user->ID);
        $messages = array();
        if ($result['disputed']) {
            $messages[] = sprintf(
                /* translators: %d: nombre d'échanges */
                _n('%d échange en cours a été transmis à l\'administrateur pour arbitrage.', '%d échanges en cours ont été transmis à l\'administrateur pour arbitrage.', $result['disputed'], 'stickers'),
                $result['disputed']
            );
        }
        if ($result['exchanges_kept']) {
            $messages[] = __('L\'historique des échanges est conservé pour l\'autre membre, sans pseudo, adresse ni contenu des messages.', 'stickers');
        }
        return array(
            'items_removed'  => $result['removed'],
            'items_retained' => $result['exchanges_kept'] > 0,
            'messages'       => $messages,
            'done'           => true,
        );
    }

    /**
     * @return array( removed => bool, disputed => int, exchanges_kept => int )
     */
    public static function erase_user($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        $out     = array('removed' => false, 'disputed' => 0, 'exchanges_kept' => 0);
        if (!$user_id) {
            return $out;
        }

        // Propositions en attente : annulées ou refusées (réservations libérées, l'autre membre est prévenu).
        foreach (STK_Exchanges::list_for_user($user_id, array(STK_Exchanges::PROPOSE), 10000) as $exchange) {
            if ((int) $exchange->proposer_id === $user_id) {
                STK_Exchanges::cancel((int) $exchange->id, $user_id);
            } else {
                STK_Exchanges::refuse((int) $exchange->id, $user_id);
            }
            STK_Exchanges::log((int) $exchange->id, 0, 'erased');
        }

        // Échanges acceptés : transmis à l'admin (litige), réservations maintenues.
        foreach (STK_Exchanges::list_for_user($user_id, array(STK_Exchanges::ACCEPTE), 10000) as $exchange) {
            if (STK_Exchanges::dispute((int) $exchange->id, $user_id, __('Compte du membre supprimé ou effacé (RGPD) pendant l\'échange.', 'stickers')) === true) {
                $out['disputed']++;
            }
        }

        $kept = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . STK_Database::table('exchanges') . ' WHERE proposer_id = %d OR recipient_id = %d',
            $user_id,
            $user_id
        ));

        $removed  = (int) $wpdb->delete(STK_Database::table('inventory'), array('user_id' => $user_id), array('%d'));
        $removed += (int) $wpdb->delete(STK_Database::table('members'), array('user_id' => $user_id), array('%d'));
        $removed += (int) $wpdb->update(STK_Database::table('messages'), array('message' => ''), array('user_id' => $user_id), array('%s'), array('%d'));
        delete_transient('stk_msg_flood_' . $user_id);

        $out['removed']        = $removed > 0;
        $out['exchanges_kept'] = $kept;
        return $out;
    }

    /**
     * Purge (option désactivée par défaut) : membres sans activité depuis N mois et sans échange en cours.
     * L'activité = inventaire, profil, messages, clôture d'échange.
     *
     * @return int Nombre de membres purgés.
     */
    public static function purge_inactive($now = null) {
        global $wpdb;
        $now     = $now ? (int) $now : time();
        $cutoff  = gmdate('Y-m-d H:i:s', strtotime('-' . STK_Settings::get('purge_months') . ' months', $now));
        $members = STK_Database::table('members');
        $ex      = STK_Database::table('exchanges');
        $inv     = STK_Database::table('inventory');

        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT m.user_id FROM $members m
             WHERE COALESCE(m.last_activity, m.created_at) < %s
               AND NOT EXISTS (SELECT 1 FROM $ex e WHERE (e.proposer_id = m.user_id OR e.recipient_id = m.user_id) AND e.statut IN (%s, %s, %s))
             LIMIT 100",
            $cutoff,
            STK_Exchanges::PROPOSE,
            STK_Exchanges::ACCEPTE,
            STK_Exchanges::LITIGE
        )));
        foreach ($ids as $uid) {
            self::erase_user($uid);
        }

        // Inventaires sans profil (jamais de pseudo) inactifs depuis N mois.
        $orphans = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT i.user_id FROM $inv i LEFT JOIN $members m ON m.user_id = i.user_id
             WHERE m.user_id IS NULL GROUP BY i.user_id HAVING MAX(i.updated_at) < %s LIMIT 500",
            $cutoff
        )));
        foreach ($orphans as $uid) {
            $wpdb->delete($inv, array('user_id' => $uid), array('%d'));
        }

        return count($ids);
    }
}
