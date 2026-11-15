<?php
/**
 * Profil de bourse d'un membre : pseudo et adresse postale propre à la bourse.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Members {

    const PSEUDO_MIN = 3;
    const PSEUDO_MAX = 20;

    public static function get($user_id) {
        global $wpdb;
        $table = STK_Database::table('members');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $user_id));
    }

    /**
     * Pseudos de plusieurs membres en une requête (« Membre supprimé » si absent).
     *
     * @return string[] user_id => pseudo
     */
    public static function pseudos(array $user_ids) {
        global $wpdb;
        $user_ids = array_values(array_unique(array_filter(array_map('absint', $user_ids))));
        $map      = array();
        if ($user_ids) {
            $table        = STK_Database::table('members');
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, pseudo FROM $table WHERE user_id IN ($placeholders)", $user_ids)) as $row) {
                $map[(int) $row->user_id] = $row->pseudo;
            }
        }
        foreach ($user_ids as $id) {
            if (!isset($map[$id])) {
                $map[$id] = __('Membre supprimé', 'stickers');
            }
        }
        return $map;
    }

    public static function get_by_pseudo($pseudo) {
        global $wpdb;
        $table = STK_Database::table('members');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE pseudo = %s", $pseudo));
    }

    /**
     * Pays acceptés pour l'adresse et format de leur code postal.
     * Modifiable lors d'une mise à jour ou via le filtre « stk_allowed_countries ».
     *
     * @return array code ISO => array( 'label' => ..., 'postcode' => regex )
     */
    public static function allowed_countries() {
        return apply_filters('stk_allowed_countries', array(
            'FR' => array('label' => __('France', 'stickers'), 'postcode' => '/^\d{5}$/'),
            'BE' => array('label' => __('Belgique', 'stickers'), 'postcode' => '/^\d{4}$/'),
            'CH' => array('label' => __('Suisse', 'stickers'), 'postcode' => '/^\d{4}$/'),
        ));
    }

    public static function country_label($code) {
        $countries = self::allowed_countries();
        return isset($countries[$code]) ? $countries[$code]['label'] : $code;
    }

    /**
     * @return string|WP_Error Pseudo nettoyé.
     */
    public static function validate_pseudo($pseudo, $user_id) {
        $pseudo = trim(sanitize_text_field(wp_unslash((string) $pseudo)));
        $length = strlen($pseudo);

        if ($length < self::PSEUDO_MIN || $length > self::PSEUDO_MAX || !preg_match('/^[A-Za-z0-9._-]+$/', $pseudo)) {
            return new WP_Error('stk_pseudo_format', sprintf(
                /* translators: 1: longueur minimale, 2: longueur maximale */
                __('Le pseudo doit contenir de %1$d à %2$d caractères : lettres sans accent, chiffres, point, tiret ou tiret bas.', 'stickers'),
                self::PSEUDO_MIN,
                self::PSEUDO_MAX
            ));
        }

        // La collation de la table ignore la casse : « Lucas » et « lucas » sont le même pseudo.
        $existing = self::get_by_pseudo($pseudo);
        if ($existing && (int) $existing->user_id !== (int) $user_id) {
            return new WP_Error('stk_pseudo_taken', __('Ce pseudo est déjà utilisé par un autre membre.', 'stickers'));
        }

        return $pseudo;
    }

    /**
     * Crée le profil ou modifie le pseudo (par le membre ou par l'admin).
     *
     * @return true|WP_Error
     */
    public static function save_pseudo($user_id, $pseudo) {
        global $wpdb;
        $table = STK_Database::table('members');

        $pseudo = self::validate_pseudo($pseudo, $user_id);
        if (is_wp_error($pseudo)) {
            return $pseudo;
        }

        $now = STK_Database::now();
        if (self::get($user_id)) {
            $ok = $wpdb->update($table, array('pseudo' => $pseudo, 'updated_at' => $now), array('user_id' => $user_id), array('%s', '%s'), array('%d'));
        } else {
            $ok = $wpdb->insert($table, array(
                'user_id'       => $user_id,
                'pseudo'        => $pseudo,
                'last_activity' => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ), array('%d', '%s', '%s', '%s', '%s'));
        }

        if ($ok === false) {
            // Course entre deux membres choisissant le même pseudo : l'index unique tranche.
            return new WP_Error('stk_pseudo_taken', __('Ce pseudo est déjà utilisé par un autre membre.', 'stickers'));
        }
        return true;
    }

    /**
     * @return array|WP_Error Adresse nettoyée.
     */
    public static function validate_address($data) {
        $countries = self::allowed_countries();

        $clean = array(
            'destinataire' => trim(sanitize_text_field(wp_unslash($data['destinataire'] ?? ''))),
            'adresse_1'    => trim(sanitize_text_field(wp_unslash($data['adresse_1'] ?? ''))),
            'adresse_2'    => trim(sanitize_text_field(wp_unslash($data['adresse_2'] ?? ''))),
            'code_postal'  => strtoupper(trim(sanitize_text_field(wp_unslash($data['code_postal'] ?? '')))),
            'ville'        => trim(sanitize_text_field(wp_unslash($data['ville'] ?? ''))),
            'pays'         => strtoupper(trim(sanitize_text_field(wp_unslash($data['pays'] ?? '')))),
        );

        if ($clean['destinataire'] === '' || $clean['adresse_1'] === '' || $clean['code_postal'] === '' || $clean['ville'] === '' || $clean['pays'] === '') {
            return new WP_Error('stk_address_required', __('Tous les champs de l\'adresse sont obligatoires, sauf le complément d\'adresse.', 'stickers'));
        }

        if (!isset($countries[$clean['pays']])) {
            return new WP_Error('stk_address_country', __('Ce pays n\'est pas accepté par la bourse.', 'stickers'));
        }

        if (!preg_match($countries[$clean['pays']]['postcode'], $clean['code_postal'])) {
            return new WP_Error('stk_address_postcode', __('Le code postal ne correspond pas au pays choisi.', 'stickers'));
        }

        $limits = array('destinataire' => 100, 'adresse_1' => 190, 'adresse_2' => 190, 'code_postal' => 10, 'ville' => 100);
        foreach ($limits as $field => $max) {
            if (mb_strlen($clean[$field]) > $max) {
                return new WP_Error('stk_address_length', __('Un champ de l\'adresse est trop long.', 'stickers'));
            }
        }

        return $clean;
    }

    /**
     * Enregistre l'adresse. Le pseudo doit avoir été choisi avant.
     *
     * @return true|WP_Error
     */
    public static function save_address($user_id, $data) {
        global $wpdb;

        if (!self::get($user_id)) {
            return new WP_Error('stk_no_pseudo', __('Choisissez d\'abord votre pseudo.', 'stickers'));
        }

        $clean = self::validate_address($data);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $clean['updated_at']    = STK_Database::now();
        $clean['last_activity'] = $clean['updated_at'];

        $ok = $wpdb->update(STK_Database::table('members'), $clean, array('user_id' => $user_id), null, array('%d'));
        return ($ok === false) ? new WP_Error('stk_db', __('Erreur de base de données.', 'stickers')) : true;
    }

    public static function has_address($member) {
        return $member && $member->destinataire !== '' && $member->adresse_1 !== '' && $member->code_postal !== '' && $member->ville !== '' && $member->pays !== '';
    }

    /**
     * Pseudo + adresse complète : condition pour proposer ou accepter un échange.
     */
    public static function is_ready($user_id) {
        return self::has_address(self::get($user_id));
    }

    public static function touch_activity($user_id) {
        global $wpdb;
        $wpdb->update(STK_Database::table('members'), array('last_activity' => STK_Database::now()), array('user_id' => $user_id), array('%s'), array('%d'));
    }

    /**
     * Liste paginée pour l'admin.
     *
     * @return array( 'rows' => object[], 'total' => int )
     */
    public static function admin_list($search, $page, $per_page) {
        global $wpdb;
        $members   = STK_Database::table('members');
        $inventory = STK_Database::table('inventory');
        $stickers  = STK_Database::table('stickers');

        $where  = '1=1';
        $params = array();
        if ($search !== '') {
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $where  = '(m.pseudo LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params = array($like, $like, $like);
        }

        $count_sql = "SELECT COUNT(*) FROM $members m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE $where";
        $total     = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT m.*, u.user_email, u.display_name,
                    (SELECT COUNT(*) FROM $inventory i JOIN $stickers s ON s.id = i.sticker_id AND s.actif = 1
                        WHERE i.user_id = m.user_id AND i.quantite > 0) AS possedes,
                    (SELECT COALESCE(SUM(i.quantite - 1), 0) FROM $inventory i JOIN $stickers s ON s.id = i.sticker_id AND s.actif = 1
                        WHERE i.user_id = m.user_id AND i.quantite > 1) AS doubles
                FROM $members m
                LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
                WHERE $where
                ORDER BY m.pseudo ASC
                LIMIT %d OFFSET %d";

        $params[] = (int) $per_page;
        $params[] = (int) (($page - 1) * $per_page);

        return array(
            'rows'  => (array) $wpdb->get_results($wpdb->prepare($sql, $params)),
            'total' => $total,
        );
    }
}
