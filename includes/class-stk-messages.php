<?php
/**
 * Fil de discussion d'un échange (un fil par chaîne proposition → contre-propositions).
 * Lu par l'admin, qui peut masquer un message a posteriori.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Messages {

    /** Délai minimal entre deux messages d'un même membre (anti-flood), en secondes. */
    const FLOOD_DELAY = 5;

    public static function get_thread($thread_id) {
        global $wpdb;
        $table = STK_Database::table('messages');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE thread_id = %d ORDER BY id ASC", $thread_id));
    }

    public static function get($id) {
        global $wpdb;
        $table = STK_Database::table('messages');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    /**
     * @return int|WP_Error Identifiant du message.
     */
    public static function post($exchange_id, $user_id, $text) {
        global $wpdb;

        $exchange = STK_Exchanges::get($exchange_id);
        if (!$exchange || !STK_Exchanges::role($exchange, $user_id)) {
            return new WP_Error('stk_not_found', __('Échange introuvable.', 'stickers'));
        }
        if (!in_array($exchange->statut, STK_Exchanges::active_statuses(), true)) {
            return new WP_Error('stk_closed', __('La discussion est fermée pour cet échange.', 'stickers'));
        }

        $text = trim(sanitize_textarea_field(wp_unslash((string) $text)));
        if ($text === '') {
            return new WP_Error('stk_empty', __('Le message est vide.', 'stickers'));
        }
        if (mb_strlen($text) > STK_Exchanges::MESSAGE_MAX) {
            return new WP_Error('stk_message', sprintf(
                /* translators: %d: nombre maximal de caractères */
                __('Le message ne doit pas dépasser %d caractères.', 'stickers'),
                STK_Exchanges::MESSAGE_MAX
            ));
        }

        $flood_key = 'stk_msg_flood_' . (int) $user_id;
        if (get_transient($flood_key)) {
            return new WP_Error('stk_flood', __('Patientez quelques secondes avant d\'envoyer un nouveau message.', 'stickers'));
        }

        $ok = $wpdb->insert(STK_Database::table('messages'), array(
            'thread_id'   => (int) $exchange->thread_id,
            'exchange_id' => (int) $exchange->id,
            'user_id'     => (int) $user_id,
            'message'     => $text,
            'created_at'  => STK_Database::now(),
        ), array('%d', '%d', '%d', '%s', '%s'));
        if ($ok === false) {
            return new WP_Error('stk_db', __('Erreur de base de données, veuillez réessayer.', 'stickers'));
        }
        $message_id = (int) $wpdb->insert_id; // À lire avant set_transient(), qui écrit dans wp_options.
        set_transient($flood_key, 1, self::FLOOD_DELAY);
        STK_Members::touch_activity($user_id);
        do_action('stk_exchange_event', 'message_posted', (int) $exchange->id, (int) $user_id, array('message_id' => $message_id));
        return $message_id;
    }

    /**
     * Modération (admin) : masquer ou réafficher un message.
     */
    public static function set_hidden($id, $hidden, $admin_id) {
        global $wpdb;
        $message = self::get($id);
        if (!$message) {
            return new WP_Error('stk_not_found', __('Message introuvable.', 'stickers'));
        }
        $ok = $wpdb->update(STK_Database::table('messages'), array(
            'hidden'    => $hidden ? 1 : 0,
            'hidden_by' => $hidden ? (int) $admin_id : 0,
            'hidden_at' => $hidden ? STK_Database::now() : null,
        ), array('id' => $id), array('%d', '%d', '%s'), array('%d'));
        if ($ok === false) {
            return new WP_Error('stk_db', __('Erreur de base de données.', 'stickers'));
        }
        STK_Exchanges::log((int) $message->exchange_id, (int) $admin_id, $hidden ? 'message_hidden' : 'message_shown', (string) $id);
        return true;
    }
}
