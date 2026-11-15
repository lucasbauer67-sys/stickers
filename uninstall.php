<?php
/**
 * Désinstallation : les données ne sont supprimées que si l'option
 * « Supprimer toutes les données… » a été cochée dans les réglages.
 *
 * Listes explicites (et non des motifs) pour ne jamais toucher aux données d'un autre plugin.
 * À tenir à jour à chaque nouvelle table ou option.
 *
 * @package Stickers
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (get_option('stk_delete_data_on_uninstall', '0') !== '1') {
    return;
}

global $wpdb;

$stk_tables = array('stickers', 'members', 'inventory');
foreach ($stk_tables as $stk_table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'stk_' . $stk_table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms fixes.
}

$stk_options = array(
    'stk_db_version',
    'stk_collection_name',
    'stk_github_token',
    'stk_delete_data_on_uninstall',
    'stk_flush_rewrite',
);
foreach ($stk_options as $stk_option) {
    delete_option($stk_option);
}

delete_transient('stk_github_release');

// Notices d'administration temporaires (une par utilisateur).
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_stk_admin_notice_') . '%',
    $wpdb->esc_like('_transient_timeout_stk_admin_notice_') . '%'
));
