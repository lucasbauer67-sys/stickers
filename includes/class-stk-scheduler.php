<?php
/**
 * Tâche planifiée horaire (Action Scheduler de WooCommerce) : expiration des propositions,
 * rappels (proposition, expédition, réception), purge facultative des membres inactifs.
 *
 * Visible dans WooCommerce → État → Actions planifiées (groupe « stickers », action « stk_hourly »).
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Scheduler {

    const HOOK  = 'stk_hourly';
    const GROUP = 'stickers';
    const BATCH = 500;

    public function __construct() {
        add_action('init', array($this, 'ensure_scheduled'), 20);
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public function ensure_scheduled() {
        if (!function_exists('as_has_scheduled_action') || get_transient('stk_scheduled')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK, array(), self::GROUP)) {
            as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), self::GROUP);
        }
        set_transient('stk_scheduled', 1, DAY_IN_SECONDS);
    }

    public static function unschedule() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, array(), self::GROUP);
        }
        delete_transient('stk_scheduled');
    }

    /**
     * Passage horaire. $now permet les tests (timestamp UTC).
     *
     * @return array Compteurs de ce qui a été fait.
     */
    public static function run($now = null) {
        $now   = $now ? (int) $now : time();
        $stats = array('expired' => 0, 'reminder_proposal' => 0, 'reminder_ship' => 0, 'reminder_receive' => 0, 'purged' => 0);

        // Bourse fermée aux clients : ni expiration, ni rappel, ni purge (les membres ne peuvent pas agir).
        if (!STK_Status::is_open()) {
            return $stats;
        }

        self::process_proposals($now, $stats);
        self::process_accepted($now, $stats);

        if (STK_Settings::get('purge_enabled') && (int) get_option('stk_last_purge', 0) < $now - DAY_IN_SECONDS) {
            update_option('stk_last_purge', $now, false);
            $stats['purged'] = STK_Privacy::purge_inactive($now);
        }
        return $stats;
    }

    private static function days_since($gmt, $now) {
        return ($now - strtotime($gmt . ' UTC')) / DAY_IN_SECONDS;
    }

    private static function process_proposals($now, array &$stats) {
        global $wpdb;
        $table  = STK_Database::table('exchanges');
        $rows   = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE statut = %s ORDER BY id ASC LIMIT %d", STK_Exchanges::PROPOSE, self::BATCH));
        $expiry = STK_Settings::get('expiry_days');

        foreach ($rows as $exchange) {
            $age = self::days_since($exchange->created_at, $now);
            if ($age >= $expiry) {
                if (STK_Exchanges::expire((int) $exchange->id) === true) {
                    $stats['expired']++;
                }
                continue;
            }
            $count = (int) $exchange->rem_proposal;
            $day   = STK_Settings::reminder_day('proposal', $count);
            if ($day !== null && $day < $expiry && $age >= $day
                && STK_Exchanges::bump_reminder((int) $exchange->id, 'rem_proposal', STK_Exchanges::PROPOSE, $count)) {
                STK_Emails::send_to_member('reminder_proposal', $exchange, (int) $exchange->recipient_id);
                STK_Exchanges::log((int) $exchange->id, 0, 'reminder', 'rem_proposal');
                $stats['reminder_proposal']++;
            }
        }
    }

    private static function process_accepted($now, array &$stats) {
        global $wpdb;
        $table = STK_Database::table('exchanges');
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE statut = %s ORDER BY id ASC LIMIT %d", STK_Exchanges::ACCEPTE, self::BATCH));
        $items = STK_Exchanges::get_items_map(wp_list_pluck($rows, 'id'));

        foreach ($rows as $exchange) {
            $list = $items[(int) $exchange->id] ?? array();
            foreach (array('proposer' => 'recipient', 'recipient' => 'proposer') as $role => $other) {
                $user_id = (int) $exchange->{$role . '_id'};

                // Expédition : depuis l'acceptation, pour celui qui donne et n'a pas encore expédié.
                if (STK_Exchanges::gives($list, $user_id) && empty($exchange->{$role . '_shipped_at'}) && $exchange->accepted_at) {
                    self::maybe_remind($exchange, 'ship', $role, $user_id, $exchange->accepted_at, $now, $stats);
                }
                // Réception : depuis l'expédition par l'autre, pour celui qui reçoit et n'a pas confirmé.
                if (STK_Exchanges::receives($list, $user_id) && !empty($exchange->{$other . '_shipped_at'}) && empty($exchange->{$role . '_received_at'})) {
                    self::maybe_remind($exchange, 'receive', $role, $user_id, $exchange->{$other . '_shipped_at'}, $now, $stats);
                }
            }
        }
    }

    private static function maybe_remind($exchange, $type, $role, $user_id, $since, $now, array &$stats) {
        $column = 'rem_' . $type . '_' . $role;
        $count  = (int) $exchange->$column;
        $day    = STK_Settings::reminder_day($type, $count);
        if ($day === null || self::days_since($since, $now) < $day) {
            return;
        }
        if (STK_Exchanges::bump_reminder((int) $exchange->id, $column, STK_Exchanges::ACCEPTE, $count)) {
            STK_Emails::send_to_member('reminder_' . $type, $exchange, $user_id);
            STK_Exchanges::log((int) $exchange->id, 0, 'reminder', $column);
            $stats['reminder_' . $type]++;
        }
    }
}
