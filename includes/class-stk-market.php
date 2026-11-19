<?php
/**
 * Bourse : qui a des doubles qui m'intéressent, à qui mes doubles servent-ils.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Market {

    /**
     * Sous-requête des exemplaires réservés par membre et par sticker.
     */
    private static function reserved_sql() {
        $items = STK_Database::table('exchange_items');
        return "(SELECT giver_id, sticker_id, SUM(quantite) AS r FROM $items WHERE reserve = 1 GROUP BY giver_id, sticker_id)";
    }

    /**
     * Membres prêts à échanger (pseudo + adresse complète + règles acceptées, compte existant), hors $viewer_id,
     * avec deux compteurs :
     *  - theirs : nombre de numéros dont il a un double disponible et qui me manquent ;
     *  - mine   : nombre de numéros dont j'ai un double disponible et qui lui manquent.
     *
     * @param int $sticker_filter Ne garder que les membres ayant ce sticker en double disponible.
     * @return array[] triés par intérêt mutuel.
     */
    public static function members_overview($viewer_id, $sticker_filter = 0) {
        global $wpdb;
        $members   = STK_Database::table('members');
        $inventory = STK_Database::table('inventory');
        $stickers  = STK_Database::table('stickers');
        $reserved  = self::reserved_sql();
        $viewer_id = (int) $viewer_id;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT m.user_id, m.pseudo FROM $members m
             JOIN {$wpdb->users} u ON u.ID = m.user_id
             JOIN {$wpdb->usermeta} um ON um.user_id = m.user_id AND um.meta_key = %s AND CAST(um.meta_value AS UNSIGNED) >= %d
             WHERE m.user_id <> %d AND m.destinataire <> '' AND m.adresse_1 <> '' AND m.code_postal <> '' AND m.ville <> '' AND m.pays <> ''",
            STK_Terms::META_VERSION,
            STK_Terms::version(),
            $viewer_id
        ));
        if (!$rows) {
            return array();
        }

        // Leurs doubles disponibles qui me manquent.
        $theirs = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT i.user_id, COUNT(*) AS n
             FROM $inventory i
             JOIN $stickers s ON s.id = i.sticker_id AND s.actif = 1
             LEFT JOIN $reserved r ON r.giver_id = i.user_id AND r.sticker_id = i.sticker_id
             LEFT JOIN $inventory v ON v.user_id = %d AND v.sticker_id = i.sticker_id
             WHERE i.user_id <> %d AND i.quantite >= 2
               AND i.quantite - 1 - COALESCE(r.r, 0) >= 1
               AND COALESCE(v.quantite, 0) = 0
             GROUP BY i.user_id",
            $viewer_id,
            $viewer_id
        )) as $row) {
            $theirs[(int) $row->user_id] = (int) $row->n;
        }

        // Mes doubles disponibles qui leur manquent : |mes doubles| − ceux qu'ils possèdent déjà.
        $my_doubles = array_keys(STK_Exchanges::available_doubles($viewer_id));
        $owned      = array();
        if ($my_doubles) {
            $placeholders = implode(',', array_fill(0, count($my_doubles), '%d'));
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, COUNT(*) AS n FROM $inventory WHERE quantite > 0 AND sticker_id IN ($placeholders) GROUP BY user_id",
                $my_doubles
            )) as $row) {
                $owned[(int) $row->user_id] = (int) $row->n;
            }
        }

        $filter_ids = null;
        if ($sticker_filter) {
            $filter_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT i.user_id FROM $inventory i
                 LEFT JOIN $reserved r ON r.giver_id = i.user_id AND r.sticker_id = i.sticker_id
                 WHERE i.sticker_id = %d AND i.quantite - 1 - COALESCE(r.r, 0) >= 1",
                $sticker_filter
            )));
            $filter_ids = array_flip($filter_ids);
        }

        $list = array();
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if ($filter_ids !== null && !isset($filter_ids[$uid])) {
                continue;
            }
            $list[] = array(
                'user_id' => $uid,
                'pseudo'  => $row->pseudo,
                'theirs'  => $theirs[$uid] ?? 0,
                'mine'    => count($my_doubles) - ($owned[$uid] ?? 0),
            );
        }

        usort($list, function ($a, $b) {
            return array(min($b['theirs'], $b['mine']), $b['theirs'], $b['mine'], strtolower($a['pseudo']))
                <=> array(min($a['theirs'], $a['mine']), $a['theirs'], $a['mine'], strtolower($b['pseudo']));
        });
        return $list;
    }

    /**
     * Données de la fiche d'un membre vue par $viewer_id (doubles, manquants, formulaire de proposition).
     */
    public static function member_detail($viewer_id, $member_id) {
        $stickers = STK_Catalog::get_all(true);
        $my_inv   = STK_Inventory::get_user_inventory($viewer_id);
        $their_inv = STK_Inventory::get_user_inventory($member_id);
        $my_free  = STK_Exchanges::available_doubles($viewer_id, $my_inv);
        $their_free = STK_Exchanges::available_doubles($member_id, $their_inv);

        $can_ask  = array();
        $can_give = array();
        $their_missing = array();

        foreach ($stickers as $sid => $sticker) {
            $mine   = $my_inv[$sid] ?? 0;
            $theirs = $their_inv[$sid] ?? 0;

            if (!empty($their_free[$sid])) {
                $max = min($their_free[$sid], STK_MAX_QTY - $mine);
                if ($max > 0) {
                    $can_ask[$sid] = array('sticker' => $sticker, 'max' => $max, 'useful' => $mine === 0);
                }
            }
            if (!empty($my_free[$sid])) {
                $max = min($my_free[$sid], STK_MAX_QTY - $theirs);
                if ($max > 0) {
                    $can_give[$sid] = array('sticker' => $sticker, 'max' => $max, 'useful' => $theirs === 0);
                }
            }
            if ($theirs === 0) {
                $their_missing[$sid] = array('sticker' => $sticker, 'i_have' => !empty($my_free[$sid]));
            }
        }

        // Les numéros utiles en premier, l'ordre du catalogue ensuite (tri stable).
        $useful_first = function ($a, $b) {
            return (int) $b['useful'] <=> (int) $a['useful'];
        };
        uasort($can_ask, $useful_first);
        uasort($can_give, $useful_first);

        return array(
            'can_ask'       => $can_ask,
            'can_give'      => $can_give,
            'their_missing' => $their_missing,
        );
    }
}
