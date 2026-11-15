<?php
/**
 * Inventaire déclaratif : chaque membre indique combien d'exemplaires il possède.
 * Doubles = exemplaires − 1 ; manquants = numéros à 0.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Inventory {

    /**
     * @return int[] sticker_id => quantité (les numéros absents valent 0).
     */
    public static function get_user_inventory($user_id) {
        global $wpdb;
        $table = STK_Database::table('inventory');
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT sticker_id, quantite FROM $table WHERE user_id = %d", $user_id));
        $inv   = array();
        foreach ((array) $rows as $row) {
            $inv[(int) $row->sticker_id] = (int) $row->quantite;
        }
        return $inv;
    }

    /**
     * Exemplaires engagés dans des échanges en cours (réservations).
     *
     * @param bool $for_update Verrouiller les lignes lues (dans une transaction).
     * @return int[] sticker_id => quantité réservée.
     */
    public static function get_reserved($user_id, $for_update = false) {
        global $wpdb;
        if (!STK_Database::table_exists('exchange_items')) {
            return array();
        }
        $items = STK_Database::table('exchange_items');
        $lock  = $for_update ? ' FOR UPDATE' : '';
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT sticker_id, SUM(quantite) AS q FROM $items WHERE giver_id = %d AND reserve = 1 GROUP BY sticker_id$lock",
            $user_id
        ));
        $reserved = array();
        foreach ((array) $rows as $row) {
            $reserved[(int) $row->sticker_id] = (int) $row->q;
        }
        return $reserved;
    }

    /**
     * Quantité minimale autorisée : on garde 1 exemplaire + ceux promis dans un échange.
     */
    public static function min_quantity($reserved) {
        return $reserved > 0 ? 1 + $reserved : 0;
    }

    /**
     * Modifie une ou plusieurs quantités du membre, dans une transaction
     * (les lignes du membre sont verrouillées pendant la vérification des réservations).
     *
     * @param int   $user_id
     * @param int[] $quantities sticker_id => nouvelle quantité.
     * @return int[]|WP_Error Inventaire complet après modification.
     */
    public static function set_quantities($user_id, array $quantities) {
        global $wpdb;
        $table    = STK_Database::table('inventory');
        $stickers = STK_Catalog::get_all(true);

        $clean = array();
        foreach ($quantities as $sticker_id => $qty) {
            $sticker_id = (int) $sticker_id;
            if (!isset($stickers[$sticker_id])) {
                return new WP_Error('stk_sticker', __('Sticker inconnu ou désactivé.', 'stickers'));
            }
            if (!is_numeric($qty) || (int) $qty != $qty || $qty < 0 || $qty > STK_MAX_QTY) {
                return new WP_Error('stk_qty', sprintf(
                    /* translators: %d: quantité maximale */
                    __('La quantité doit être comprise entre 0 et %d.', 'stickers'),
                    STK_MAX_QTY
                ));
            }
            $clean[$sticker_id] = (int) $qty;
        }

        if (!$clean) {
            return self::get_user_inventory($user_id);
        }

        $result = STK_Database::transaction(function () use ($wpdb, $table, $user_id, $clean, $stickers) {
            $now = STK_Database::now();

            // Verrou sur toutes les lignes du membre (ordre stable par la clé primaire).
            $locked = $wpdb->get_results($wpdb->prepare("SELECT sticker_id, quantite FROM $table WHERE user_id = %d FOR UPDATE", $user_id));
            if ($locked === null && $wpdb->last_error) {
                return STK_Database::db_error();
            }
            $current = array();
            foreach ((array) $locked as $row) {
                $current[(int) $row->sticker_id] = (int) $row->quantite;
            }

            $reserved = self::get_reserved($user_id, true);

            foreach ($clean as $sticker_id => $qty) {
                $min = self::min_quantity($reserved[$sticker_id] ?? 0);
                if ($qty < $min) {
                    return new WP_Error('stk_reserved', sprintf(
                        /* translators: 1: libellé du sticker, 2: quantité minimale */
                        __('%1$s : des exemplaires sont promis dans un échange en cours, la quantité ne peut pas descendre sous %2$d.', 'stickers'),
                        STK_Catalog::label($stickers[$sticker_id]),
                        $min
                    ));
                }

                if (array_key_exists($sticker_id, $current)) {
                    if ($current[$sticker_id] === $qty) {
                        continue;
                    }
                    $ok = $wpdb->update($table, array('quantite' => $qty, 'updated_at' => $now), array('user_id' => $user_id, 'sticker_id' => $sticker_id), array('%d', '%s'), array('%d', '%d'));
                } else {
                    $ok = $wpdb->insert($table, array('user_id' => $user_id, 'sticker_id' => $sticker_id, 'quantite' => $qty, 'updated_at' => $now), array('%d', '%d', '%d', '%s'));
                }
                if ($ok === false) {
                    return STK_Database::db_error();
                }
            }
            return true;
        });

        if (is_wp_error($result)) {
            return $result;
        }

        STK_Members::touch_activity($user_id);
        return self::get_user_inventory($user_id);
    }

    /**
     * Résumé de l'inventaire sur les stickers actifs.
     */
    public static function stats(array $inventory, array $stickers) {
        $owned = 0;
        $doubles = 0;
        foreach ($stickers as $id => $sticker) {
            $qty = $inventory[$id] ?? 0;
            if ($qty > 0) {
                $owned++;
                $doubles += $qty - 1;
            }
        }
        $total = count($stickers);
        return array(
            'total'   => $total,
            'owned'   => $owned,
            'missing' => $total - $owned,
            'doubles' => $doubles,
        );
    }

    /**
     * État d'une case de la grille.
     */
    public static function state($qty) {
        if ($qty <= 0) {
            return 'manquant';
        }
        return $qty > 1 ? 'double' : 'possede';
    }
}
