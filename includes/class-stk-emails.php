<?php
/**
 * Emails transactionnels (texte brut via wp_mail, comme le plugin Abonnements).
 * Textes modifiables dans Stickers → Réglages ; un champ vide reprend le texte par défaut.
 * Aucune adresse postale n'est jamais insérée dans un email.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Emails {

    const OPTION = 'stk_emails';

    public function __construct() {
        add_action('stk_exchange_event', array($this, 'on_event'), 10, 4);
    }

    /**
     * Modèles par défaut : clé => (libellé, destinataire, objet, texte).
     */
    public static function templates() {
        $sign = "\n\n" . __('L\'équipe [site]', 'stickers');
        return array(
            'proposal_received' => array(
                'label'   => __('Proposition reçue', 'stickers'),
                'to'      => __('au destinataire d\'une proposition ou d\'une contre-proposition', 'stickers'),
                'subject' => __('[site] : nouvelle proposition d\'échange de stickers', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] vous propose un échange de stickers (proposition nº [numero]).\n\nVous recevriez : [vous_recevez]\nVous donneriez : [vous_donnez]\n\nSans réponse, la proposition expirera le [date_expiration].\n\nPour accepter, refuser ou faire une contre-proposition : [lien]", 'stickers') . $sign,
            ),
            'accepted' => array(
                'label'   => __('Proposition acceptée', 'stickers'),
                'to'      => __('au proposeur', 'stickers'),
                'subject' => __('[site] : votre proposition d\'échange a été acceptée', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] a accepté votre proposition nº [numero].\n\nVous donnez : [vous_donnez]\nVous recevez : [vous_recevez]\n\nL'adresse d'envoi et les étapes de l'échange sont sur cette page : [lien]", 'stickers') . $sign,
            ),
            'refused' => array(
                'label'   => __('Proposition refusée', 'stickers'),
                'to'      => __('au proposeur', 'stickers'),
                'subject' => __('[site] : proposition d\'échange refusée', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] a refusé votre proposition nº [numero]. Les stickers que vous aviez réservés sont de nouveau disponibles.\n\n[lien]", 'stickers') . $sign,
            ),
            'cancelled' => array(
                'label'   => __('Annulation', 'stickers'),
                'to'      => __('à l\'autre membre (proposition annulée par le proposeur, ou échange annulé d\'un commun accord)', 'stickers'),
                'subject' => __('[site] : échange de stickers annulé', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\nL'échange nº [numero] avec [autre_pseudo] a été annulé. Les stickers réservés sont de nouveau disponibles.\n\n[lien]", 'stickers') . $sign,
            ),
            'cancel_requested' => array(
                'label'   => __('Demande d\'annulation', 'stickers'),
                'to'      => __('à l\'autre membre, qui doit confirmer ou refuser', 'stickers'),
                'subject' => __('[site] : demande d\'annulation d\'un échange', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] souhaite annuler l'échange nº [numero]. L'annulation n'aura lieu que si vous la confirmez.\n\nConfirmer ou refuser : [lien]", 'stickers') . $sign,
            ),
            'expired' => array(
                'label'   => __('Proposition expirée', 'stickers'),
                'to'      => __('au proposeur', 'stickers'),
                'subject' => __('[site] : proposition d\'échange expirée', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\nVotre proposition nº [numero] à [autre_pseudo] a expiré faute de réponse. Les stickers que vous aviez réservés sont de nouveau disponibles.\n\n[lien]", 'stickers') . $sign,
            ),
            'reminder_proposal' => array(
                'label'   => __('Rappel : proposition en attente', 'stickers'),
                'to'      => __('au destinataire d\'une proposition sans réponse', 'stickers'),
                'subject' => __('[site] : une proposition d\'échange attend votre réponse', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] attend votre réponse à sa proposition nº [numero].\n\nVous recevriez : [vous_recevez]\nVous donneriez : [vous_donnez]\n\nSans réponse, elle expirera le [date_expiration].\n\nRépondre : [lien]", 'stickers') . $sign,
            ),
            'reminder_ship' => array(
                'label'   => __('Rappel : expédition', 'stickers'),
                'to'      => __('au membre qui n\'a pas encore indiqué son envoi', 'stickers'),
                'subject' => __('[site] : pensez à expédier vos stickers', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\nDans l'échange nº [numero] avec [autre_pseudo], vous devez envoyer : [vous_donnez].\n\nUne fois le courrier posté, cliquez sur « J'ai expédié » : [lien]", 'stickers') . $sign,
            ),
            'reminder_receive' => array(
                'label'   => __('Rappel : confirmation de réception', 'stickers'),
                'to'      => __('au membre qui doit confirmer la réception', 'stickers'),
                'subject' => __('[site] : avez-vous reçu vos stickers ?', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] a indiqué avoir expédié vos stickers (échange nº [numero]) : [vous_recevez].\n\nDès réception, confirmez-le pour que l'échange soit clôturé : [lien]\n\nEn cas de problème, utilisez le bouton « Signaler un problème » sur la même page.", 'stickers') . $sign,
            ),
            'closed' => array(
                'label'   => __('Échange clôturé', 'stickers'),
                'to'      => __('aux deux membres', 'stickers'),
                'subject' => __('[site] : échange de stickers terminé', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\nL'échange nº [numero] avec [autre_pseudo] est terminé. Votre inventaire a été mis à jour.\n\n[lien]", 'stickers') . $sign,
            ),
            'dispute_admin' => array(
                'label'   => __('Litige (administrateur)', 'stickers'),
                'to'      => __('à l\'adresse email d\'administration de WordPress', 'stickers'),
                'subject' => __('[site] : litige signalé sur l\'échange de stickers nº [numero]', 'stickers'),
                'body'    => __("Bonjour,\n\n[auteur] a signalé un problème sur l'échange nº [numero] ([proposeur] → [destinataire]).\n\nMotif :\n[motif]\n\nArbitrer : [lien_admin]", 'stickers'),
            ),
            'dispute_member' => array(
                'label'   => __('Litige (autre membre)', 'stickers'),
                'to'      => __('au membre qui n\'a pas signalé le problème', 'stickers'),
                'subject' => __('[site] : un problème a été signalé sur un échange', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] a signalé un problème sur l'échange nº [numero]. L'équipe du site va examiner la situation et reviendra vers vous si nécessaire.\n\n[lien]", 'stickers') . $sign,
            ),
            'resolved' => array(
                'label'   => __('Litige arbitré', 'stickers'),
                'to'      => __('aux deux membres', 'stickers'),
                'subject' => __('[site] : litige arbitré', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\nLe litige sur l'échange nº [numero] avec [autre_pseudo] a été arbitré : [decision].\n\n[lien]", 'stickers') . $sign,
            ),
            'new_message' => array(
                'label'   => __('Nouveau message', 'stickers'),
                'to'      => __('à l\'autre membre de l\'échange', 'stickers'),
                'subject' => __('[site] : nouveau message de [autre_pseudo]', 'stickers'),
                'body'    => __("Bonjour [pseudo],\n\n[autre_pseudo] vous a écrit au sujet de l'échange nº [numero].\n\nLire et répondre : [lien]", 'stickers') . $sign,
            ),
        );
    }

    public static function placeholders_help() {
        return __('Balises : [site], [pseudo] (destinataire de l\'email), [autre_pseudo], [numero], [lien], [vous_donnez], [vous_recevez], [date_expiration] ; litiges : [motif], [auteur], [proposeur], [destinataire], [lien_admin], [decision].', 'stickers');
    }

    /**
     * Texte effectif d'un modèle (personnalisé, sinon par défaut).
     */
    public static function get($key, $field) {
        $templates = self::templates();
        $custom    = get_option(self::OPTION, array());
        $value     = is_array($custom) ? trim((string) ($custom[$key][$field] ?? '')) : '';
        return $value !== '' ? $value : $templates[$key][$field];
    }

    public static function sanitize($input) {
        $clean = array();
        foreach (array_keys(self::templates()) as $key) {
            $subject = isset($input[$key]['subject']) ? sanitize_text_field((string) $input[$key]['subject']) : '';
            $body    = isset($input[$key]['body']) ? sanitize_textarea_field((string) $input[$key]['body']) : '';
            // On ne stocke que ce qui diffère du texte par défaut : les améliorations futures des textes par défaut s'appliquent.
            $default = self::templates()[$key];
            $clean[$key] = array(
                'subject' => $subject === $default['subject'] ? '' : $subject,
                'body'    => str_replace("\r\n", "\n", $body) === $default['body'] ? '' : $body,
            );
        }
        return $clean;
    }

    /**
     * Envoie le modèle $key à une adresse.
     */
    public static function send($key, $to, array $vars) {
        if (!is_email($to)) {
            return false;
        }
        $vars['site'] = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $replace      = array();
        foreach ($vars as $name => $value) {
            $replace['[' . $name . ']'] = (string) $value;
        }
        $subject = str_replace(array("\r", "\n"), ' ', strtr(self::get($key, 'subject'), $replace));
        $body    = strtr(self::get($key, 'body'), $replace);
        return wp_mail($to, $subject, $body);
    }

    /**
     * Variables d'un échange, du point de vue de $user_id.
     */
    public static function exchange_vars($exchange, $user_id) {
        $other_id = STK_Exchanges::other_party($exchange, $user_id);
        $pseudos  = STK_Members::pseudos(array($user_id, $other_id));
        $lines    = STK_Frontend::items_lines(STK_Exchanges::get_items((int) $exchange->id), $user_id, STK_Catalog::get_all(false));
        $nothing  = __('rien', 'stickers');
        $expiry   = strtotime($exchange->created_at . ' UTC') + STK_Settings::get('expiry_days') * DAY_IN_SECONDS;

        return array(
            'pseudo'          => $pseudos[$user_id],
            'autre_pseudo'    => $pseudos[$other_id],
            'numero'          => (int) $exchange->id,
            'lien'            => STK_Frontend::exchange_url((int) $exchange->id),
            'vous_donnez'     => $lines['give'] ? implode(', ', $lines['give']) : $nothing,
            'vous_recevez'    => $lines['receive'] ? implode(', ', $lines['receive']) : $nothing,
            'date_expiration' => wp_date(get_option('date_format'), $expiry),
        );
    }

    /**
     * Envoie le modèle $key à un membre d'un échange.
     */
    public static function send_to_member($key, $exchange, $user_id, array $extra = array()) {
        $user = get_userdata((int) $user_id);
        if (!$user || !STK_Members::get((int) $user_id)) {
            return false;
        }
        return self::send($key, $user->user_email, array_merge(self::exchange_vars($exchange, (int) $user_id), $extra));
    }

    /**
     * Événements de STK_Exchanges et STK_Messages → emails.
     */
    public function on_event($event, $exchange_id, $actor_id, $extra) {
        $exchange = STK_Exchanges::get($exchange_id);
        if (!$exchange) {
            return;
        }
        $proposer  = (int) $exchange->proposer_id;
        $recipient = (int) $exchange->recipient_id;
        $other     = STK_Exchanges::other_party($exchange, $actor_id);

        switch ($event) {
            case 'proposal_created':
                self::send_to_member('proposal_received', $exchange, $recipient);
                break;
            case 'accepted':
                self::send_to_member('accepted', $exchange, $proposer);
                break;
            case 'refused':
                self::send_to_member('refused', $exchange, $proposer);
                break;
            case 'cancelled':
                self::send_to_member('cancelled', $exchange, $other);
                break;
            case 'cancel_requested':
                self::send_to_member('cancel_requested', $exchange, $other);
                break;
            case 'expired':
                self::send_to_member('expired', $exchange, $proposer);
                break;
            case 'closed':
                self::send_to_member('closed', $exchange, $proposer);
                self::send_to_member('closed', $exchange, $recipient);
                break;
            case 'disputed':
                $pseudos = STK_Members::pseudos(array($proposer, $recipient, (int) $actor_id));
                self::send('dispute_admin', get_option('admin_email'), array(
                    'numero'       => (int) $exchange->id,
                    'auteur'       => $pseudos[(int) $actor_id],
                    'proposeur'    => $pseudos[$proposer],
                    'destinataire' => $pseudos[$recipient],
                    'motif'        => (string) ($extra['reason'] ?? $exchange->dispute_reason),
                    'lien_admin'   => add_query_arg(array('page' => 'stk-echanges', 'echange' => (int) $exchange->id), admin_url('admin.php')),
                ));
                self::send_to_member('dispute_member', $exchange, $other);
                break;
            case 'resolved':
                $decision = ($extra['decision'] ?? '') === 'success'
                    ? __('échange clôturé comme réussi, les inventaires ont été mis à jour', 'stickers')
                    : __('échange annulé, aucun inventaire n\'a été modifié', 'stickers');
                self::send_to_member('resolved', $exchange, $proposer, array('decision' => $decision));
                self::send_to_member('resolved', $exchange, $recipient, array('decision' => $decision));
                break;
            case 'message_posted':
                self::send_to_member('new_message', $exchange, $other);
                break;
        }
    }
}
