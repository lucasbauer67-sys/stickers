<?php
/**
 * Suggestions automatiques : correspondances mutuelles
 * (j'ai un double dont l'autre a besoin ET il a un double dont j'ai besoin).
 *
 * Calcul à la demande, à l'affichage. Symétrique : si A voit B, B voit A.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Suggestions {

    const LIMIT = 20;

    /**
     * @return array[] Chaque suggestion : user_id, pseudo, pairs (échanges 1 pour 1 possibles),
     *                 give / ask (sticker_id proposés pour un échange équilibré), more_give / more_ask.
     */
    public static function for_user($viewer_id, $limit = self::LIMIT) {
        global $wpdb;
        $viewer_id = (int) $viewer_id;

        if (!STK_Members::is_ready($viewer_id)) {
            return array();
        }

        // Membres avec qui une proposition est déjà en attente : la règle « une seule à la fois » s'applique.
        $exchanges = STK_Database::table('exchanges');
        $busy      = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT proposer_id, recipient_id FROM $exchanges WHERE statut = %s AND (proposer_id = %d OR recipient_id = %d)",
            STK_Exchanges::PROPOSE,
            $viewer_id,
            $viewer_id
        )) as $row) {
            $busy[(int) $row->proposer_id === $viewer_id ? (int) $row->recipient_id : (int) $row->proposer_id] = true;
        }

        $candidates = array();
        foreach (STK_Market::members_overview($viewer_id) as $row) {
            if ($row['theirs'] > 0 && $row['mine'] > 0 && !isset($busy[$row['user_id']])) {
                $candidates[] = $row;
                if (count($candidates) >= $limit) {
                    break; // members_overview est déjà trié par min(theirs, mine) décroissant.
                }
            }
        }
        if (!$candidates) {
            return array();
        }

        // Inventaires et réservations des candidats en deux requêtes.
        $ids          = wp_list_pluck($candidates, 'user_id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $inventory    = STK_Database::table('inventory');
        $items        = STK_Database::table('exchange_items');

        $their_inv = array_fill_keys($ids, array());
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, sticker_id, quantite FROM $inventory WHERE user_id IN ($placeholders)", $ids)) as $row) {
            $their_inv[(int) $row->user_id][(int) $row->sticker_id] = (int) $row->quantite;
        }
        $their_res = array_fill_keys($ids, array());
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT giver_id, sticker_id, SUM(quantite) AS q FROM $items WHERE reserve = 1 AND giver_id IN ($placeholders) GROUP BY giver_id, sticker_id", $ids)) as $row) {
            $their_res[(int) $row->giver_id][(int) $row->sticker_id] = (int) $row->q;
        }

        $stickers = STK_Catalog::get_all(true);
        $my_inv   = STK_Inventory::get_user_inventory($viewer_id);
        $my_free  = STK_Exchanges::available_doubles($viewer_id, $my_inv);

        $suggestions = array();
        foreach ($candidates as $row) {
            $uid        = (int) $row['user_id'];
            $their_free = STK_Exchanges::available_doubles($uid, $their_inv[$uid], $their_res[$uid]);
            $give       = array();
            $ask        = array();
            foreach ($stickers as $sid => $sticker) { // Ordre du catalogue.
                if (!empty($my_free[$sid]) && ($their_inv[$uid][$sid] ?? 0) === 0) {
                    $give[] = $sid;
                }
                if (!empty($their_free[$sid]) && ($my_inv[$sid] ?? 0) === 0) {
                    $ask[] = $sid;
                }
            }
            $pairs = min(count($give), count($ask));
            if ($pairs === 0) {
                continue;
            }
            $suggestions[] = array(
                'user_id'   => $uid,
                'pseudo'    => $row['pseudo'],
                'pairs'     => $pairs,
                'give'      => array_slice($give, 0, $pairs),
                'ask'       => array_slice($ask, 0, $pairs),
                'more_give' => count($give) - $pairs,
                'more_ask'  => count($ask) - $pairs,
            );
        }
        return $suggestions;
    }

    /**
     * Pré-remplissage équilibré du formulaire de proposition à partir de la fiche d'un membre
     * (même règle que for_user() : numéros utiles des deux côtés, ordre du catalogue, 1 exemplaire chacun).
     *
     * @param array $detail Résultat de STK_Market::member_detail().
     * @return array( 'give' => sticker_id => 1, 'ask' => sticker_id => 1 )
     */
    public static function balanced_prefill(array $detail) {
        $give = array();
        $ask  = array();
        foreach (array_keys(STK_Catalog::get_all(true)) as $sid) { // Ordre du catalogue.
            if (!empty($detail['can_give'][$sid]['useful'])) {
                $give[] = $sid;
            }
            if (!empty($detail['can_ask'][$sid]['useful'])) {
                $ask[] = $sid;
            }
        }
        $pairs = min(count($give), count($ask));

        return array(
            'give' => array_fill_keys(array_slice($give, 0, $pairs), 1),
            'ask'  => array_fill_keys(array_slice($ask, 0, $pairs), 1),
        );
    }
}
