<?php
/**
 * Mise à jour automatique depuis les Releases du dépôt GitHub privé « stickers ».
 * Adapté du module du plugin Abonnements Éditions Limitées.
 *
 * Pour livrer une version : changer la version dans l'en-tête ET STK_VERSION,
 * pousser sur main, puis créer une Release avec un tag vX.Y.Z identique.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_GitHub_Updater {

    const GITHUB_USER = 'lucasbauer67-sys';
    const GITHUB_REPO = 'stickers';
    const CACHE_KEY   = 'stk_github_release';

    private $plugin_slug = 'stickers';
    private $plugin_basename;
    private $current_version;
    private $access_token;
    private $release = null;

    public function __construct() {
        $this->plugin_basename = STK_PLUGIN_BASENAME;
        $this->current_version = STK_VERSION;
        $this->access_token    = (string) get_option('stk_github_token', '');

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_filter('http_request_args', array($this, 'inject_auth_header'), 10, 2);

        // « Vérifier à nouveau » dans Tableau de bord > Mises à jour.
        if (is_admin() && isset($_GET['force-check']) && $_GET['force-check'] === '1') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            delete_transient(self::CACHE_KEY);
        }
    }

    /**
     * Le token n'est envoyé qu'aux URL GitHub de ce dépôt.
     */
    public function inject_auth_header($args, $url) {
        if ($this->access_token === '') {
            return $args;
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $repo = '/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/';

        if (!in_array($host, array('api.github.com', 'github.com', 'codeload.github.com'), true) || strpos($path . '/', $repo) === false) {
            return $args;
        }
        $args['headers']['Authorization'] = 'token ' . $this->access_token;
        return $args;
    }

    private function get_release() {
        if ($this->release !== null) {
            return $this->release;
        }

        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            $this->release = $cached;
            return $this->release;
        }

        $args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ),
            'timeout' => 10,
        );
        $response = wp_remote_get(sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_USER, self::GITHUB_REPO), $args);

        $release = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $release = json_decode(wp_remote_retrieve_body($response));
        }

        if (empty($release) || !isset($release->tag_name)) {
            // Valeur « 0 » (et non false) pour mettre l'échec en cache une heure.
            set_transient(self::CACHE_KEY, 0, HOUR_IN_SECONDS);
            $this->release = 0;
            return 0;
        }

        set_transient(self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);
        $this->release = $release;
        return $release;
    }

    private function version_from_tag($tag) {
        return ltrim((string) $tag, 'vV');
    }

    private function download_url($release) {
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        $release = $this->get_release();
        if (!$release) {
            return $transient;
        }

        $latest = $this->version_from_tag($release->tag_name);
        if (version_compare($latest, $this->current_version, '>')) {
            $update               = new stdClass();
            $update->slug         = $this->plugin_slug;
            $update->plugin       = $this->plugin_basename;
            $update->new_version  = $latest;
            $update->url          = $release->html_url;
            $update->package      = $this->download_url($release);
            $update->tested       = get_bloginfo('version');
            $update->requires     = '7.1';
            $update->requires_php = '8.3';
            $transient->response[$this->plugin_basename] = $update;
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        $release = $this->get_release();
        if (!$release) {
            return $result;
        }

        $info                = new stdClass();
        $info->name          = 'Bourse aux stickers';
        $info->slug          = $this->plugin_slug;
        $info->version       = $this->version_from_tag($release->tag_name);
        $info->author        = 'Xylene14';
        $info->homepage      = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $info->requires      = '7.1';
        $info->requires_php  = '8.3';
        $info->tested        = get_bloginfo('version');
        $info->last_updated  = $release->published_at;
        $info->download_link = $this->download_url($release);
        $info->sections      = array(
            'description' => esc_html__('Bourse d\'échange de stickers entre membres.', 'stickers'),
            'changelog'   => !empty($release->body) ? nl2br(esc_html($release->body)) : esc_html__('Aucune note de version.', 'stickers'),
        );
        return $info;
    }

    /**
     * Le zip GitHub contient un dossier « proprietaire-depot-hash » : on le renomme en « stickers ».
     */
    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }
        global $wp_filesystem;

        $destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
        $wp_filesystem->move($result['destination'], $destination);
        $result['destination'] = $destination;

        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');

        if (is_plugin_active($this->plugin_basename)) {
            activate_plugin($this->plugin_basename);
        }
        return $result;
    }

    public function plugin_row_meta($links, $file) {
        if ($file === $this->plugin_basename) {
            $links[] = '<a href="' . esc_url('https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases') . '" target="_blank" rel="noopener">' . esc_html__('Releases GitHub', 'stickers') . '</a>';
        }
        return $links;
    }
}
