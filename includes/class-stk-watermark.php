<?php
/**
 * Filigrane « SPÉCIMEN » sur les images de stickers affichées aux membres.
 *
 * Les membres ne voient jamais l'image d'origine : le plugin génère une copie réduite
 * (400 px au plus grand côté, trop petite pour une impression nette) avec le filigrane incrusté,
 * dans wp-content/uploads/stickers-filigrane/. Génération à l'enregistrement d'un sticker,
 * par une tâche de fond après une mise à jour, ou à la volée (10 par page au maximum).
 *
 * Calques fournis : assets/img/filigrane-portrait.png et filigrane-paysage.png (PNG transparents).
 * Nécessite l'extension PHP GD ; sans elle, aucune image n'est affichée aux membres.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Watermark {

    /** À augmenter si les calques changent : toutes les copies sont régénérées. */
    const VERSION  = 1;
    const DIR      = 'stickers-filigrane';
    const MAX_SIZE = 400;
    const QUALITY  = 82;
    const HOOK     = 'stk_regenerate_watermarks';

    private static $budget = 10;

    public function __construct() {
        add_action(self::HOOK, array(__CLASS__, 'regenerate_all'));
        add_action('init', array(__CLASS__, 'maybe_schedule'), 25);
        add_action('admin_post_stk_regenerate_watermarks', array($this, 'handle_regenerate'));
    }

    public static function available() {
        return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring') && function_exists('imagecreatefrompng') && function_exists('imagejpeg');
    }

    private static function base() {
        $upload = wp_upload_dir(null, false);
        return array(
            'dir' => trailingslashit($upload['basedir']) . self::DIR,
            'url' => trailingslashit($upload['baseurl']) . self::DIR,
        );
    }

    /**
     * Nom du fichier filigrané : change quand l'image d'origine ou les calques changent.
     */
    private static function filename($sticker) {
        $image_id = (int) $sticker->image_id;
        $path     = $image_id ? get_attached_file($image_id) : '';
        if (!$path || !is_readable($path)) {
            return '';
        }
        $hash = substr(md5($image_id . '|' . filemtime($path) . '|' . filesize($path) . '|' . self::VERSION), 0, 12);
        return (int) $sticker->id . '-' . $hash . '.jpg';
    }

    /**
     * URL de l'image filigranée d'un sticker ('' si pas d'image ou génération impossible).
     */
    public static function url($sticker) {
        if (empty($sticker->image_id) || !self::available()) {
            return '';
        }
        $file = self::filename($sticker);
        if ($file === '') {
            return '';
        }
        $base = self::base();
        if (!file_exists($base['dir'] . '/' . $file)) {
            if (self::$budget <= 0) {
                return ''; // Les suivantes seront générées au prochain affichage ou par la tâche de fond.
            }
            self::$budget--;
            if (!self::generate($sticker)) {
                return '';
            }
        }
        return $base['url'] . '/' . $file;
    }

    /**
     * Génère (ou régénère) la copie filigranée d'un sticker.
     *
     * @return string|false Chemin du fichier créé.
     */
    public static function generate($sticker) {
        if (!$sticker || empty($sticker->image_id) || !self::available()) {
            return false;
        }
        $file = self::filename($sticker);
        if ($file === '') {
            return false;
        }
        $base = self::base();
        if (!wp_mkdir_p($base['dir'])) {
            return false;
        }
        if (!file_exists($base['dir'] . '/index.php')) {
            file_put_contents($base['dir'] . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        $data = file_get_contents(get_attached_file((int) $sticker->image_id)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        $src  = $data ? @imagecreatefromstring($data) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- format non pris en charge : on renonce proprement.
        if (!$src) {
            return false;
        }

        $w     = imagesx($src);
        $h     = imagesy($src);
        $scale = min(1, self::MAX_SIZE / max($w, $h));
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // Fond blanc pour les PNG transparents.
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $overlay = @imagecreatefrompng(STK_PLUGIN_DIR . 'assets/img/filigrane-' . ($nw >= $nh ? 'paysage' : 'portrait') . '.png'); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$overlay) {
            imagedestroy($dst);
            return false; // Sans calque, on n'affiche pas d'image plutôt qu'une image sans filigrane.
        }
        imagealphablending($dst, true);
        imagecopyresampled($dst, $overlay, 0, 0, 0, 0, $nw, $nh, imagesx($overlay), imagesy($overlay));
        imagedestroy($overlay);

        $path = $base['dir'] . '/' . $file;
        $ok   = imagejpeg($dst, $path, self::QUALITY);
        imagedestroy($dst);
        if (!$ok) {
            return false;
        }

        // Supprime les anciennes copies de ce sticker.
        foreach ((array) glob($base['dir'] . '/' . (int) $sticker->id . '-*.jpg') as $old) {
            if ($old !== $path && is_file($old)) {
                wp_delete_file($old);
            }
        }
        return $path;
    }

    public static function delete_for($sticker_id) {
        $base = self::base();
        foreach ((array) glob($base['dir'] . '/' . (int) $sticker_id . '-*.jpg') as $old) {
            if (is_file($old)) {
                wp_delete_file($old);
            }
        }
    }

    /**
     * @return array( 'done' => int, 'failed' => int )
     */
    public static function regenerate_all() {
        $stats = array('done' => 0, 'failed' => 0);
        foreach (STK_Catalog::get_all(false) as $sticker) {
            if (empty($sticker->image_id)) {
                continue;
            }
            if (self::generate($sticker)) {
                $stats['done']++;
            } else {
                $stats['failed']++;
            }
        }
        update_option('stk_wm_version', self::VERSION, false);
        return $stats;
    }

    /**
     * Après une installation ou un changement de calques : régénération en tâche de fond (Action Scheduler).
     */
    public static function maybe_schedule() {
        if ((int) get_option('stk_wm_version', 0) === self::VERSION || !function_exists('as_enqueue_async_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK, array(), STK_Scheduler::GROUP)) {
            as_enqueue_async_action(self::HOOK, array(), STK_Scheduler::GROUP);
        }
    }

    public function handle_regenerate() {
        if (!current_user_can(STK_Admin::CAP)) {
            wp_die(esc_html__('Action non autorisée.', 'stickers'), 403);
        }
        check_admin_referer('stk_regenerate_watermarks');
        if (!self::available()) {
            $message = __('L\'extension PHP GD n\'est pas disponible sur le serveur : impossible de générer les images filigranées.', 'stickers');
            $type    = 'error';
        } else {
            $stats   = self::regenerate_all();
            $message = sprintf(
                /* translators: 1: nombre d'images générées, 2: nombre d'échecs */
                __('Images filigranées régénérées : %1$d, échecs : %2$d.', 'stickers'),
                $stats['done'],
                $stats['failed']
            );
            $type = $stats['failed'] ? 'warning' : 'success';
        }
        set_transient('stk_admin_notice_' . get_current_user_id(), array('message' => $message, 'type' => $type), 60);
        wp_safe_redirect(admin_url('admin.php?page=stk-catalogue'));
        exit;
    }
}
