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

$stk_tables = array('stickers', 'members', 'inventory', 'exchanges', 'exchange_items', 'exchange_log', 'messages');
foreach ($stk_tables as $stk_table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($wpdb->prefix . 'stk_' . $stk_table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms fixes.
}

$stk_options = array(
    'stk_db_version',
    'stk_collection_name',
    'stk_github_token',
    'stk_delete_data_on_uninstall',
    'stk_flush_rewrite',
    'stk_settings',
    'stk_emails',
    'stk_last_purge',
    'stk_rules_version',
    'stk_wm_version',
);
// Page des conditions générales créée par le plugin : mise à la corbeille (récupérable).
$stk_terms_page = (int) get_option('stk_terms_page_id', 0);
if ($stk_terms_page) {
    wp_trash_post($stk_terms_page);
}
$stk_options[] = 'stk_terms_page_id';

foreach ($stk_options as $stk_option) {
    delete_option($stk_option);
}

// Acceptations des règles par les membres.
delete_metadata('user', 0, 'stk_rules_version', '', true);
delete_metadata('user', 0, 'stk_rules_accepted_at', '', true);

// Images filigranées générées par le plugin.
$stk_upload = wp_upload_dir(null, false);
$stk_wm_dir = trailingslashit($stk_upload['basedir']) . 'stickers-filigrane';
if (is_dir($stk_wm_dir)) {
    foreach ((array) glob($stk_wm_dir . '/*') as $stk_file) {
        if (is_file($stk_file)) {
            wp_delete_file($stk_file);
        }
    }
    @rmdir($stk_wm_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- dossier éventuellement non vide (fichiers ajoutés à la main).
}

delete_transient('stk_github_release');
delete_transient('stk_scheduled');

// Tâche planifiée horaire (Action Scheduler, groupe « stickers »).
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('stk_hourly', array(), 'stickers');
}

// Transients par utilisateur : notices d'administration, anti-flood des messages.
foreach (array('stk_admin_notice_', 'stk_msg_flood_') as $stk_prefix) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_' . $stk_prefix) . '%',
        $wpdb->esc_like('_transient_timeout_' . $stk_prefix) . '%'
    ));
}
