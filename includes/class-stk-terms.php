<?php
/**
 * Règles de la bourse : page de conditions générales d'utilisation (page WordPress modifiable)
 * et acceptation par les membres (case à cocher dans le Profil, qui vaut acceptation des CGU).
 *
 * L'acceptation est stockée en métadonnées utilisateur, avec le numéro de version des règles :
 * augmenter la version (Stickers → Réglages) redemande l'acceptation à tous les membres.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Terms {

    const OPTION_PAGE    = 'stk_terms_page_id';
    const OPTION_VERSION = 'stk_rules_version';
    const META_VERSION   = 'stk_rules_version';
    const META_DATE      = 'stk_rules_accepted_at';

    public function __construct() {
        add_action('init', array(__CLASS__, 'maybe_create_page'), 30);
        add_action('admin_post_stk_rules_bump', array($this, 'handle_bump'));
    }

    public static function version() {
        return max(1, (int) get_option(self::OPTION_VERSION, 1));
    }

    public static function has_accepted($user_id) {
        return (int) get_user_meta((int) $user_id, self::META_VERSION, true) >= self::version();
    }

    public static function accepted_at($user_id) {
        return (string) get_user_meta((int) $user_id, self::META_DATE, true);
    }

    /**
     * @return true|WP_Error
     */
    public static function accept($user_id, $checked) {
        if (!$checked) {
            return new WP_Error('stk_rules', __('Cochez la case pour confirmer que vous avez lu le fonctionnement de la bourse et accepté les conditions générales d\'utilisation.', 'stickers'));
        }
        update_user_meta((int) $user_id, self::META_VERSION, self::version());
        update_user_meta((int) $user_id, self::META_DATE, STK_Database::now());
        if (STK_Members::get((int) $user_id)) {
            STK_Members::touch_activity((int) $user_id);
        }
        return true;
    }

    public static function forget($user_id) {
        delete_user_meta((int) $user_id, self::META_VERSION);
        delete_user_meta((int) $user_id, self::META_DATE);
    }

    /**
     * Adresse de la page des CGU (vide si la page n'est pas publiée, sauf aperçu administrateur).
     */
    public static function page_url() {
        $page_id = (int) get_option(self::OPTION_PAGE, 0);
        $status  = $page_id ? get_post_status($page_id) : '';
        // Page privée pendant la fermeture de la bourse : lien affiché aux administrateurs (aperçu).
        if ($status !== 'publish' && !($status === 'private' && current_user_can('read_private_pages'))) {
            return '';
        }
        return (string) get_permalink($page_id);
    }

    /**
     * Crée la page des CGU une seule fois (première installation ou mise à jour vers 1.1.0).
     * Si la page est ensuite supprimée, elle n'est pas recréée : choisir une autre page dans les réglages.
     */
    public static function maybe_create_page() {
        if (get_option(self::OPTION_PAGE, false) !== false) {
            return;
        }
        $page_id = wp_insert_post(array(
            'post_type'      => 'page',
            'post_status'    => STK_Status::is_open() ? 'publish' : 'private',
            'post_title'     => __('Conditions générales d\'utilisation de la bourse aux stickers', 'stickers'),
            'post_name'      => 'conditions-bourse-stickers',
            'post_content'   => self::default_content(),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);
        update_option(self::OPTION_PAGE, is_wp_error($page_id) ? 0 : (int) $page_id);
    }

    public static function default_content() {
        $sections = array(
            __('1. Objet', 'stickers') => array(
                __('La bourse aux stickers est un service gratuit proposé par les éditions Black and White à leurs clients disposant d\'un compte sur le site. Elle leur permet d\'échanger entre eux les stickers de la collection ALL IN COLOR PHOTONIK afin de compléter leur album.', 'stickers'),
            ),
            __('2. Un service indépendant de la boutique', 'stickers') => array(
                __('La bourse est indépendante de la boutique en ligne : elle n\'est liée à aucun achat ni à aucune commande, et les conditions générales de vente de la boutique ne s\'appliquent pas aux échanges entre membres.', 'stickers'),
                __('Les éditions Black and White mettent uniquement la plateforme à disposition. Elles ne sont pas partie aux échanges, n\'expédient aucun sticker et ne garantissent ni la disponibilité, ni l\'état, ni l\'acheminement des stickers échangés.', 'stickers'),
            ),
            __('3. Accès et acceptation des conditions', 'stickers') => array(
                __('La bourse est réservée aux clients connectés à leur compte qui ont choisi un pseudo, renseigné une adresse d\'envoi et accepté les présentes conditions.', 'stickers'),
                __('Cocher la case confirmant la lecture du fonctionnement de la bourse, dans l\'onglet Stickers → Profil de « Mon compte », vaut acceptation des présentes conditions générales d\'utilisation. En cas de modification des conditions, une nouvelle acceptation peut être demandée.', 'stickers'),
            ),
            __('4. Des échanges sans argent', 'stickers') => array(
                __('Les échanges se font exclusivement stickers contre stickers, ou sous forme de don. Toute transaction monétisée est interdite : vente, achat, demande ou proposition de paiement, de contrepartie financière ou de tout autre bien que des stickers de la collection.', 'stickers'),
            ),
            __('5. Courtoisie et comportement', 'stickers') => array(
                __('Chaque membre s\'engage à rester courtois en toutes circonstances, dans les messages comme dans le déroulement des échanges, et à honorer les échanges qu\'il a acceptés.', 'stickers'),
                __('Sont strictement interdits les propos insultants, injurieux, racistes, antisémites, homophobes, sexistes, diffamatoires, discriminatoires ou menaçants, ainsi que toute forme de harcèlement.', 'stickers'),
                __('Les messages échangés sont visibles par l\'équipe du site, qui peut les masquer. Les éditions Black and White peuvent retirer l\'accès à la bourse à un membre qui ne respecte pas ces règles.', 'stickers'),
            ),
            __('6. Envois postaux', 'stickers') => array(
                __('Chaque membre envoie lui-même ses stickers par courrier, à l\'adresse qui lui est communiquée une fois l\'échange accepté, et prend en charge les frais de son propre envoi. Les éditions Black and White ne sont pas responsables des pertes, retards ou dégradations liés à l\'acheminement postal.', 'stickers'),
            ),
            __('7. Données personnelles', 'stickers') => array(
                __('Votre pseudo et votre inventaire (doubles et numéros manquants) sont visibles des autres membres connectés. Votre nom et votre adresse ne sont communiqués qu\'au membre avec qui vous avez accepté un échange, jusqu\'à la clôture de cet échange, et uniquement pour l\'envoi des stickers : toute autre utilisation de ces informations est interdite.', 'stickers'),
                __('Vous pouvez demander l\'export ou l\'effacement de vos données de la bourse auprès des éditions Black and White.', 'stickers'),
            ),
            __('8. Litiges entre membres', 'stickers') => array(
                __('En cas de problème sur un échange (stickers non reçus, différents de ceux prévus, absence de réponse…), chaque membre peut utiliser le bouton « Signaler un problème ». L\'échange est alors suspendu.', 'stickers'),
                __('Les éditions Black and White examinent la situation, notamment à partir des messages et du déroulement de l\'échange, et décident de son issue : clôture de l\'échange comme réussi (les inventaires sont mis à jour) ou annulation (aucun inventaire n\'est modifié). Cette décision est définitive dans le cadre de la bourse : elle n\'est pas discutable et ne donne lieu à aucun recours au sein du service.', 'stickers'),
            ),
            __('9. Responsabilité', 'stickers') => array(
                __('Le service est fourni gracieusement, en l\'état, sans garantie de disponibilité. Les échanges se font sous la seule responsabilité des membres concernés : les éditions Black and White n\'y étant pas parties, leur responsabilité ne saurait être engagée au titre de leur exécution (stickers non reçus, non conformes ou abîmés, comportement d\'un membre…).', 'stickers'),
                __('Les propos insultants, racistes, diffamatoires, homophobes ou discriminatoires engagent la responsabilité de leur auteur et peuvent donner lieu à des poursuites, notamment judiciaires.', 'stickers'),
            ),
            __('10. Modification des conditions et droit applicable', 'stickers') => array(
                __('Les éditions Black and White peuvent modifier les présentes conditions à tout moment ; en cas de modification importante, une nouvelle acceptation est demandée aux membres. Les présentes conditions sont soumises au droit français.', 'stickers'),
            ),
        );

        $html = '';
        foreach ($sections as $title => $paragraphs) {
            $html .= '<h2>' . esc_html($title) . "</h2>\n";
            foreach ($paragraphs as $paragraph) {
                $html .= '<p>' . esc_html($paragraph) . "</p>\n";
            }
        }
        return $html;
    }

    /**
     * Réglages : redemander l'acceptation à tous les membres (après modification des CGU).
     */
    public function handle_bump() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Action non autorisée.', 'stickers'), 403);
        }
        check_admin_referer('stk_rules_bump');
        update_option(self::OPTION_VERSION, self::version() + 1);
        set_transient('stk_admin_notice_' . get_current_user_id(), array(
            'message' => __('Nouvelle acceptation demandée : chaque membre devra de nouveau cocher la case dans son Profil avant de pouvoir proposer ou accepter un échange.', 'stickers'),
            'type'    => 'success',
        ), 60);
        wp_safe_redirect(admin_url('admin.php?page=stk-reglages'));
        exit;
    }
}
