<?php
/**
 * Catalogue des stickers (saisie manuelle dans l'admin).
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Catalog {

    const NUMERO_MAX_LENGTH = 20;
    const NOM_MAX_LENGTH    = 190;

    /**
     * @param bool $only_active Ne renvoyer que les stickers actifs.
     * @return object[] Indexés par id, triés par ordre puis numéro.
     */
    public static function get_all($only_active = true) {
        global $wpdb;
        $table = STK_Database::table('stickers');
        $where = $only_active ? 'WHERE actif = 1' : '';
        $rows  = $wpdb->get_results("SELECT * FROM $table $where ORDER BY ordre ASC, numero ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aucune donnée externe.

        $stickers = array();
        foreach ((array) $rows as $row) {
            $stickers[(int) $row->id] = $row;
        }
        return $stickers;
    }

    public static function get($id) {
        global $wpdb;
        $table = STK_Database::table('stickers');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    public static function count_active() {
        global $wpdb;
        $table = STK_Database::table('stickers');
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE actif = 1");
    }

    /**
     * Ordre proposé par défaut : numéro × 10 (« 12a » se place après « 12 »).
     */
    public static function default_order($numero) {
        return ((int) $numero) * 10;
    }

    /**
     * Numéro suivant proposé dans le formulaire d'ajout (saisie à la chaîne).
     */
    public static function suggest_next_numero() {
        global $wpdb;
        $table = STK_Database::table('stickers');
        $max   = (int) $wpdb->get_var("SELECT MAX(CAST(numero AS UNSIGNED)) FROM $table");
        return (string) ($max + 1);
    }

    /**
     * Valide et nettoie les données d'un sticker.
     *
     * @return array|WP_Error
     */
    public static function validate($data, $id = 0) {
        global $wpdb;
        $table = STK_Database::table('stickers');

        $numero = trim(sanitize_text_field(wp_unslash($data['numero'] ?? '')));
        $nom    = trim(sanitize_text_field(wp_unslash($data['nom'] ?? '')));
        $image  = absint($data['image_id'] ?? 0);
        $ordre  = trim((string) ($data['ordre'] ?? ''));
        $actif  = empty($data['actif']) ? 0 : 1;

        if ($numero === '' || mb_strlen($numero) > self::NUMERO_MAX_LENGTH || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\-]*$/u', $numero)) {
            return new WP_Error('stk_numero', sprintf(
                /* translators: %d: longueur maximale */
                __('Numéro invalide : %d caractères maximum (lettres, chiffres, espace, point, tiret, tiret bas).', 'stickers'),
                self::NUMERO_MAX_LENGTH
            ));
        }

        if ($nom === '' || mb_strlen($nom) > self::NOM_MAX_LENGTH) {
            return new WP_Error('stk_nom', __('Le nom est obligatoire (190 caractères maximum).', 'stickers'));
        }

        if ($image && !wp_attachment_is_image($image)) {
            return new WP_Error('stk_image', __('Le fichier choisi n\'est pas une image.', 'stickers'));
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE numero = %s AND id <> %d", $numero, $id));
        if ($exists) {
            return new WP_Error('stk_numero_exists', __('Ce numéro existe déjà dans le catalogue.', 'stickers'));
        }

        $ordre = ($ordre === '' || !is_numeric($ordre)) ? self::default_order($numero) : (int) $ordre;

        return array(
            'numero'   => $numero,
            'nom'      => $nom,
            'image_id' => $image,
            'ordre'    => $ordre,
            'actif'    => $actif,
        );
    }

    /**
     * Crée ou met à jour un sticker.
     *
     * @return int|WP_Error Identifiant du sticker.
     */
    public static function save($data, $id = 0) {
        global $wpdb;
        $table = STK_Database::table('stickers');

        $clean = self::validate($data, $id);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $now = STK_Database::now();
        $clean['updated_at'] = $now;

        if ($id) {
            if (!self::get($id)) {
                return new WP_Error('stk_not_found', __('Sticker introuvable.', 'stickers'));
            }
            $ok = $wpdb->update($table, $clean, array('id' => $id), array('%s', '%s', '%d', '%d', '%d', '%s'), array('%d'));
            return ($ok === false) ? new WP_Error('stk_db', __('Erreur de base de données.', 'stickers')) : (int) $id;
        }

        $clean['created_at'] = $now;
        $ok = $wpdb->insert($table, $clean, array('%s', '%s', '%d', '%d', '%d', '%s', '%s'));
        return ($ok === false) ? new WP_Error('stk_db', __('Erreur de base de données.', 'stickers')) : (int) $wpdb->insert_id;
    }

    public static function set_active($id, $active) {
        global $wpdb;
        return false !== $wpdb->update(
            STK_Database::table('stickers'),
            array('actif' => $active ? 1 : 0, 'updated_at' => STK_Database::now()),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );
    }

    /**
     * Un sticker est « utilisé » s'il est déclaré dans un inventaire
     * ou présent dans un échange : il ne peut alors qu'être désactivé.
     */
    public static function is_used($id) {
        global $wpdb;
        $inventory = STK_Database::table('inventory');
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $inventory WHERE sticker_id = %d AND quantite > 0", $id)) > 0) {
            return true;
        }
        if (STK_Database::table_exists('exchange_items')) {
            $items = STK_Database::table('exchange_items');
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $items WHERE sticker_id = %d", $id)) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return true|WP_Error
     */
    public static function delete($id) {
        global $wpdb;
        if (self::is_used($id)) {
            return new WP_Error('stk_used', __('Ce sticker est utilisé par des membres : il peut seulement être désactivé.', 'stickers'));
        }
        $wpdb->delete(STK_Database::table('inventory'), array('sticker_id' => $id), array('%d'));
        $ok = $wpdb->delete(STK_Database::table('stickers'), array('id' => $id), array('%d'));
        return $ok ? true : new WP_Error('stk_db', __('Erreur de base de données.', 'stickers'));
    }

    /**
     * Nombre de membres possédant chaque sticker (colonne admin).
     *
     * @return int[] sticker_id => nombre de membres.
     */
    public static function owners_count() {
        global $wpdb;
        $inventory = STK_Database::table('inventory');
        $rows      = $wpdb->get_results("SELECT sticker_id, COUNT(*) AS n FROM $inventory WHERE quantite > 0 GROUP BY sticker_id");
        $counts    = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row->sticker_id] = (int) $row->n;
        }
        return $counts;
    }

    /**
     * Libellé « Nº 12 — Nom ».
     */
    public static function label($sticker) {
        return sprintf(
            /* translators: 1: numéro du sticker, 2: nom du sticker */
            __('Nº %1$s — %2$s', 'stickers'),
            $sticker->numero,
            $sticker->nom
        );
    }
}
