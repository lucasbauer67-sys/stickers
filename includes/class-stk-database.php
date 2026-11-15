<?php
/**
 * Schéma des tables, migrations et transactions.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Database {

    /** Version du schéma (indépendante de la version du plugin). */
    const DB_VERSION = '1';

    /** Nombre de tentatives en cas de blocage croisé (deadlock). */
    const TRANSACTION_RETRIES = 3;

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'stk_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $options         = 'ENGINE=InnoDB ' . $charset_collate;

        $stickers  = self::table('stickers');
        $members   = self::table('members');
        $inventory = self::table('inventory');

        dbDelta("CREATE TABLE $stickers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            numero varchar(20) NOT NULL,
            nom varchar(190) NOT NULL DEFAULT '',
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ordre int(11) NOT NULL DEFAULT 0,
            actif tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY numero (numero),
            KEY ordre (ordre)
        ) $options;");

        dbDelta("CREATE TABLE $members (
            user_id bigint(20) unsigned NOT NULL,
            pseudo varchar(20) NOT NULL,
            destinataire varchar(100) NOT NULL DEFAULT '',
            adresse_1 varchar(190) NOT NULL DEFAULT '',
            adresse_2 varchar(190) NOT NULL DEFAULT '',
            code_postal varchar(10) NOT NULL DEFAULT '',
            ville varchar(100) NOT NULL DEFAULT '',
            pays char(2) NOT NULL DEFAULT '',
            last_activity datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            UNIQUE KEY pseudo (pseudo)
        ) $options;");

        dbDelta("CREATE TABLE $inventory (
            user_id bigint(20) unsigned NOT NULL,
            sticker_id bigint(20) unsigned NOT NULL,
            quantite tinyint(3) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id,sticker_id),
            KEY sticker_qte (sticker_id,quantite)
        ) $options;");

        update_option('stk_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade() {
        if (get_option('stk_db_version') !== self::DB_VERSION) {
            self::install();
            update_option('stk_flush_rewrite', '1');
        }
    }

    public static function table_exists($name) {
        global $wpdb;
        static $cache = array();
        $table = self::table($name);
        if (!isset($cache[$table])) {
            $cache[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table);
        }
        return $cache[$table];
    }

    public static function now() {
        return current_time('mysql', true);
    }

    /**
     * Exécute $callback dans une transaction InnoDB.
     *
     * Le callback renvoie un résultat ; un WP_Error (ou false) annule la transaction.
     * Un WP_Error de code « stk_deadlock » relance la transaction (blocage croisé).
     *
     * @return mixed|WP_Error
     */
    public static function transaction(callable $callback) {
        global $wpdb;

        for ($attempt = 1; $attempt <= self::TRANSACTION_RETRIES; $attempt++) {
            $wpdb->query('START TRANSACTION');
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }

            if (is_wp_error($result) || $result === false) {
                $wpdb->query('ROLLBACK');
                if (is_wp_error($result) && $result->get_error_code() === 'stk_deadlock' && $attempt < self::TRANSACTION_RETRIES) {
                    usleep(50000 * $attempt);
                    continue;
                }
                return $result;
            }

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('stk_db', __('Erreur de base de données, veuillez réessayer.', 'stickers'));
            }
            return $result;
        }

        return new WP_Error('stk_db', __('Erreur de base de données, veuillez réessayer.', 'stickers'));
    }

    /**
     * Convertit la dernière erreur SQL en WP_Error (deadlock détecté pour relance).
     */
    public static function db_error() {
        global $wpdb;
        $error = (string) $wpdb->last_error;
        if (stripos($error, 'Deadlock') !== false || stripos($error, 'Lock wait timeout') !== false) {
            return new WP_Error('stk_deadlock', __('Opération concurrente, veuillez réessayer.', 'stickers'));
        }
        return new WP_Error('stk_db', __('Erreur de base de données, veuillez réessayer.', 'stickers'));
    }
}
