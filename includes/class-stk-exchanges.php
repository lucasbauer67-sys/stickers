<?php
/**
 * Propositions et échanges : machine d'états, réservations, clôture.
 *
 * Statuts : propose → accepte → cloture ; propose → refuse | annule | expire | remplace ;
 * accepte → annule (accord des deux, avant toute expédition) ; accepte → litige (étape 4).
 *
 * Règles de verrouillage (pour éviter les blocages croisés) : toujours la ligne de l'échange
 * d'abord, puis les inventaires (ordre croissant user_id, sticker_id), puis les réservations.
 *
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

class STK_Exchanges {

    const PROPOSE  = 'propose';
    const ACCEPTE  = 'accepte';
    const CLOTURE  = 'cloture';
    const REFUSE   = 'refuse';
    const ANNULE   = 'annule';
    const EXPIRE   = 'expire';
    const REMPLACE = 'remplace';
    const LITIGE   = 'litige';

    const EXPIRY_DAYS = 7;
    const MESSAGE_MAX = 1000;

    public static function labels() {
        return array(
            self::PROPOSE  => __('Proposé', 'stickers'),
            self::ACCEPTE  => __('Accepté', 'stickers'),
            self::CLOTURE  => __('Clôturé', 'stickers'),
            self::REFUSE   => __('Refusé', 'stickers'),
            self::ANNULE   => __('Annulé', 'stickers'),
            self::EXPIRE   => __('Expiré', 'stickers'),
            self::REMPLACE => __('Remplacé par une contre-proposition', 'stickers'),
            self::LITIGE   => __('Litige', 'stickers'),
        );
    }

    public static function label($status) {
        $labels = self::labels();
        return $labels[$status] ?? $status;
    }

    /** Statuts où l'échange est encore vivant (discussion possible). */
    public static function active_statuses() {
        return array(self::PROPOSE, self::ACCEPTE, self::LITIGE);
    }

    /* ------------------------------------------------------------------
     * Lecture
     * ------------------------------------------------------------------ */

    public static function get($id) {
        global $wpdb;
        $table = STK_Database::table('exchanges');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    }

    public static function get_items($exchange_id) {
        global $wpdb;
        $table = STK_Database::table('exchange_items');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE exchange_id = %d ORDER BY id ASC", $exchange_id));
    }

    /**
     * @param int[] $ids
     * @return array exchange_id => lignes
     */
    public static function get_items_map(array $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if (!$ids) {
            return array();
        }
        $table        = STK_Database::table('exchange_items');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows         = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE exchange_id IN ($placeholders) ORDER BY id ASC", $ids));
        $map          = array_fill_keys($ids, array());
        foreach ($rows as $row) {
            $map[(int) $row->exchange_id][] = $row;
        }
        return $map;
    }

    public static function get_log($exchange_id) {
        global $wpdb;
        $table = STK_Database::table('exchange_log');
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE exchange_id = %d ORDER BY id ASC", $exchange_id));
    }

    /**
     * Échanges d'un membre, du plus récent au plus ancien.
     */
    public static function list_for_user($user_id, array $statuses, $limit = 50) {
        global $wpdb;
        $table        = STK_Database::table('exchanges');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params       = array_merge(array($user_id, $user_id), $statuses, array((int) $limit));
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE (proposer_id = %d OR recipient_id = %d) AND statut IN ($placeholders) ORDER BY updated_at DESC, id DESC LIMIT %d",
            $params
        ));
    }

    /**
     * Rôle d'un membre dans un échange : 'proposer', 'recipient' ou null.
     */
    public static function role($exchange, $user_id) {
        if ((int) $exchange->proposer_id === (int) $user_id) {
            return 'proposer';
        }
        if ((int) $exchange->recipient_id === (int) $user_id) {
            return 'recipient';
        }
        return null;
    }

    public static function other_party($exchange, $user_id) {
        return (int) $exchange->proposer_id === (int) $user_id ? (int) $exchange->recipient_id : (int) $exchange->proposer_id;
    }

    public static function gives(array $items, $user_id) {
        foreach ($items as $item) {
            if ((int) $item->giver_id === (int) $user_id) {
                return true;
            }
        }
        return false;
    }

    public static function receives(array $items, $user_id) {
        foreach ($items as $item) {
            if ((int) $item->receiver_id === (int) $user_id) {
                return true;
            }
        }
        return false;
    }

    private static function field($role, $what) {
        return $role . '_' . $what . '_at';
    }

    /**
     * Actions attendues de ce membre sur un échange accepté.
     *
     * @return string[] 'ship', 'receive', 'confirm_cancel'
     */
    public static function pending_actions($exchange, array $items, $user_id) {
        $role = self::role($exchange, $user_id);
        if (!$role || $exchange->statut !== self::ACCEPTE) {
            return array();
        }
        $other   = $role === 'proposer' ? 'recipient' : 'proposer';
        $actions = array();
        if (self::gives($items, $user_id) && empty($exchange->{self::field($role, 'shipped')})) {
            $actions[] = 'ship';
        }
        if (self::receives($items, $user_id) && !empty($exchange->{self::field($other, 'shipped')}) && empty($exchange->{self::field($role, 'received')})) {
            $actions[] = 'receive';
        }
        if ((int) $exchange->cancel_requested_by && (int) $exchange->cancel_requested_by !== (int) $user_id) {
            $actions[] = 'confirm_cancel';
        }
        return $actions;
    }

    /**
     * Compteurs pour la navigation : propositions reçues en attente, échanges où une action est attendue.
     */
    public static function counts_for_user($user_id) {
        $received = 0;
        foreach (self::list_for_user($user_id, array(self::PROPOSE), 500) as $exchange) {
            if ((int) $exchange->recipient_id === (int) $user_id) {
                $received++;
            }
        }
        $accepted = self::list_for_user($user_id, array(self::ACCEPTE), 500);
        $items    = self::get_items_map(wp_list_pluck($accepted, 'id'));
        $action   = 0;
        foreach ($accepted as $exchange) {
            if (self::pending_actions($exchange, $items[(int) $exchange->id] ?? array(), $user_id)) {
                $action++;
            }
        }
        return array('received' => $received, 'action' => $action);
    }

    /**
     * Doubles disponibles d'un membre (hors exemplaires réservés), sur les stickers actifs.
     *
     * @return int[] sticker_id => nombre disponible (>= 1)
     */
    public static function available_doubles($user_id, $inventory = null, $reserved = null) {
        $stickers  = STK_Catalog::get_all(true);
        $inventory = $inventory ?? STK_Inventory::get_user_inventory($user_id);
        $reserved  = $reserved ?? STK_Inventory::get_reserved($user_id);
        $available = array();
        foreach ($inventory as $sticker_id => $qty) {
            if (!isset($stickers[$sticker_id])) {
                continue;
            }
            $free = $qty - 1 - ($reserved[$sticker_id] ?? 0);
            if ($free >= 1) {
                $available[$sticker_id] = $free;
            }
        }
        return $available;
    }

    /* ------------------------------------------------------------------
     * Outils de transaction
     * ------------------------------------------------------------------ */

    private static function lock_exchange($id) {
        global $wpdb;
        $table = STK_Database::table('exchanges');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d FOR UPDATE", $id));
    }

    /**
     * Verrouille les inventaires des membres (ordre stable) et les renvoie.
     *
     * @return array|WP_Error user_id => (sticker_id => quantité)
     */
    private static function lock_inventories(array $user_ids) {
        global $wpdb;
        $table    = STK_Database::table('inventory');
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        sort($user_ids);
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, sticker_id, quantite FROM $table WHERE user_id IN ($placeholders) ORDER BY user_id, sticker_id FOR UPDATE",
            $user_ids
        ));
        if ($rows === null && $wpdb->last_error) {
            return STK_Database::db_error();
        }
        $inventories = array_fill_keys($user_ids, array());
        foreach ((array) $rows as $row) {
            $inventories[(int) $row->user_id][(int) $row->sticker_id] = (int) $row->quantite;
        }
        return $inventories;
    }

    /**
     * Change le statut seulement s'il est encore celui attendu (transition conditionnelle).
     */
    private static function transition($id, $from, array $data) {
        global $wpdb;
        $data['statut']     = $data['statut'] ?? $from;
        $data['updated_at'] = STK_Database::now();
        $result = $wpdb->update(STK_Database::table('exchanges'), $data, array('id' => $id, 'statut' => $from));
        if ($result === false) {
            return STK_Database::db_error();
        }
        if ($result !== 1) {
            return new WP_Error('stk_state', __('Cet échange a changé entre-temps. Rechargez la page.', 'stickers'));
        }
        return true;
    }

    private static function release($exchange_id) {
        global $wpdb;
        $result = $wpdb->update(STK_Database::table('exchange_items'), array('reserve' => 0), array('exchange_id' => $exchange_id), array('%d'), array('%d'));
        return $result === false ? STK_Database::db_error() : true;
    }

    public static function log($exchange_id, $user_id, $action, $details = '') {
        global $wpdb;
        $wpdb->insert(STK_Database::table('exchange_log'), array(
            'exchange_id' => $exchange_id,
            'user_id'     => $user_id,
            'action'      => $action,
            'details'     => $details,
            'created_at'  => STK_Database::now(),
        ), array('%d', '%d', '%s', '%s', '%s'));
    }

    private static function sticker_label($sticker_id) {
        $sticker = STK_Catalog::get($sticker_id);
        return $sticker ? STK_Catalog::label($sticker) : '#' . (int) $sticker_id;
    }

    private static function not_found() {
        return new WP_Error('stk_not_found', __('Échange introuvable.', 'stickers'));
    }

    private static function wrong_state() {
        return new WP_Error('stk_state', __('Cette action n\'est plus possible pour cet échange. Rechargez la page.', 'stickers'));
    }

    /* ------------------------------------------------------------------
     * Proposition
     * ------------------------------------------------------------------ */

    /**
     * Nettoie une liste sticker_id => quantité (on ignore les zéros).
     *
     * @return int[]|WP_Error
     */
    private static function clean_list(array $list, array $stickers) {
        $clean = array();
        foreach ($list as $sticker_id => $qty) {
            $sticker_id = absint($sticker_id);
            if ($qty === '' || $qty === null || (int) $qty === 0) {
                continue;
            }
            if (!is_numeric($qty) || (int) $qty != $qty || (int) $qty < 0 || (int) $qty > STK_MAX_QTY) {
                return new WP_Error('stk_qty', __('Quantité invalide dans la proposition.', 'stickers'));
            }
            if (!isset($stickers[$sticker_id])) {
                return new WP_Error('stk_sticker', __('Sticker inconnu ou désactivé.', 'stickers'));
            }
            $clean[$sticker_id] = (int) $qty;
        }
        return $clean;
    }

    /**
     * Crée une proposition (ou une contre-proposition si $parent_id).
     *
     * @param int[] $give sticker_id => quantité donnée par le proposeur.
     * @param int[] $ask  sticker_id => quantité demandée au destinataire.
     * @return int|WP_Error Identifiant de la nouvelle proposition.
     */
    public static function create($proposer_id, $recipient_id, array $give, array $ask, $message = '', $parent_id = 0, $source = 'manuel') {
        global $wpdb;

        $proposer_id  = (int) $proposer_id;
        $recipient_id = (int) $recipient_id;
        $parent_id    = (int) $parent_id;

        if (!$proposer_id || !$recipient_id || $proposer_id === $recipient_id) {
            return new WP_Error('stk_party', __('Destinataire invalide.', 'stickers'));
        }
        if (!STK_Members::is_ready($proposer_id)) {
            return new WP_Error('stk_not_ready', __('Renseignez votre pseudo et votre adresse avant de proposer un échange.', 'stickers'));
        }
        if (!get_userdata($recipient_id) || !STK_Members::is_ready($recipient_id)) {
            return new WP_Error('stk_recipient', __('Ce membre ne peut pas recevoir de proposition pour le moment.', 'stickers'));
        }

        $stickers = STK_Catalog::get_all(true);
        $give     = self::clean_list($give, $stickers);
        if (is_wp_error($give)) {
            return $give;
        }
        $ask = self::clean_list($ask, $stickers);
        if (is_wp_error($ask)) {
            return $ask;
        }
        if (!$give && !$ask) {
            return new WP_Error('stk_empty', __('La proposition doit contenir au moins un sticker.', 'stickers'));
        }
        if (array_intersect_key($give, $ask)) {
            return new WP_Error('stk_both', __('Un même numéro ne peut pas être à la fois donné et demandé.', 'stickers'));
        }

        $message = trim(sanitize_textarea_field(wp_unslash((string) $message)));
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            return new WP_Error('stk_message', sprintf(
                /* translators: %d: nombre maximal de caractères */
                __('Le message ne doit pas dépasser %d caractères.', 'stickers'),
                self::MESSAGE_MAX
            ));
        }
        $source = in_array($source, array('manuel', 'suggestion'), true) ? $source : 'manuel';

        return STK_Database::transaction(function () use ($wpdb, $proposer_id, $recipient_id, $give, $ask, $message, $parent_id, $source) {
            $exchanges = STK_Database::table('exchanges');
            $items     = STK_Database::table('exchange_items');
            $now       = STK_Database::now();
            $thread_id = 0;

            // Contre-proposition : la proposition reçue est remplacée et ses réservations libérées.
            if ($parent_id) {
                $parent = self::lock_exchange($parent_id);
                if (!$parent || (int) $parent->recipient_id !== $proposer_id || (int) $parent->proposer_id !== $recipient_id) {
                    return self::not_found();
                }
                if ($parent->statut !== self::PROPOSE) {
                    return self::wrong_state();
                }
                $ok = self::transition($parent_id, self::PROPOSE, array('statut' => self::REMPLACE));
                if (is_wp_error($ok)) {
                    return $ok;
                }
                $ok = self::release($parent_id);
                if (is_wp_error($ok)) {
                    return $ok;
                }
                $thread_id = (int) $parent->thread_id;
            }

            // Une seule proposition en attente à la fois entre deux membres.
            $pending = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $exchanges WHERE statut = %s AND ((proposer_id = %d AND recipient_id = %d) OR (proposer_id = %d AND recipient_id = %d)) LIMIT 1",
                self::PROPOSE, $proposer_id, $recipient_id, $recipient_id, $proposer_id
            ));
            if ($pending) {
                return new WP_Error('stk_pending', __('Une proposition est déjà en attente avec ce membre : répondez-y ou annulez-la d\'abord.', 'stickers'));
            }

            $inventories = self::lock_inventories(array($proposer_id, $recipient_id));
            if (is_wp_error($inventories)) {
                return $inventories;
            }
            $inv_p  = $inventories[$proposer_id];
            $inv_r  = $inventories[$recipient_id];
            $res_p  = STK_Inventory::get_reserved($proposer_id, true);
            $res_r  = STK_Inventory::get_reserved($recipient_id, true);

            foreach ($give as $sticker_id => $qty) {
                $free = ($inv_p[$sticker_id] ?? 0) - 1 - ($res_p[$sticker_id] ?? 0);
                if ($free < $qty) {
                    return new WP_Error('stk_unavailable', sprintf(
                        /* translators: %s: libellé du sticker */
                        __('%s : vous n\'avez pas assez de doubles disponibles.', 'stickers'),
                        self::sticker_label($sticker_id)
                    ));
                }
                if (($inv_r[$sticker_id] ?? 0) + $qty > STK_MAX_QTY) {
                    return new WP_Error('stk_cap', sprintf(
                        /* translators: 1: libellé du sticker, 2: quantité maximale */
                        __('%1$s : le destinataire dépasserait %2$d exemplaires.', 'stickers'),
                        self::sticker_label($sticker_id),
                        STK_MAX_QTY
                    ));
                }
            }
            foreach ($ask as $sticker_id => $qty) {
                $free = ($inv_r[$sticker_id] ?? 0) - 1 - ($res_r[$sticker_id] ?? 0);
                if ($free < $qty) {
                    return new WP_Error('stk_unavailable', sprintf(
                        /* translators: %s: libellé du sticker */
                        __('%s : ce membre n\'a plus assez de doubles disponibles.', 'stickers'),
                        self::sticker_label($sticker_id)
                    ));
                }
                if (($inv_p[$sticker_id] ?? 0) + $qty > STK_MAX_QTY) {
                    return new WP_Error('stk_cap', sprintf(
                        /* translators: 1: libellé du sticker, 2: quantité maximale */
                        __('%1$s : vous dépasseriez %2$d exemplaires.', 'stickers'),
                        self::sticker_label($sticker_id),
                        STK_MAX_QTY
                    ));
                }
            }

            $ok = $wpdb->insert($exchanges, array(
                'thread_id'    => $thread_id,
                'parent_id'    => $parent_id,
                'proposer_id'  => $proposer_id,
                'recipient_id' => $recipient_id,
                'statut'       => self::PROPOSE,
                'source'       => $source,
                'expires_at'   => gmdate('Y-m-d H:i:s', time() + self::EXPIRY_DAYS * DAY_IN_SECONDS),
                'created_at'   => $now,
                'updated_at'   => $now,
            ), array('%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'));
            if ($ok === false) {
                return STK_Database::db_error();
            }
            $id = (int) $wpdb->insert_id;
            if (!$thread_id) {
                $thread_id = $id;
                $wpdb->update($exchanges, array('thread_id' => $id), array('id' => $id), array('%d'), array('%d'));
            }

            foreach ($give as $sticker_id => $qty) {
                $ok = $wpdb->insert($items, array('exchange_id' => $id, 'giver_id' => $proposer_id, 'receiver_id' => $recipient_id, 'sticker_id' => $sticker_id, 'quantite' => $qty, 'reserve' => 1), array('%d', '%d', '%d', '%d', '%d', '%d'));
                if ($ok === false) {
                    return STK_Database::db_error();
                }
            }
            foreach ($ask as $sticker_id => $qty) {
                $ok = $wpdb->insert($items, array('exchange_id' => $id, 'giver_id' => $recipient_id, 'receiver_id' => $proposer_id, 'sticker_id' => $sticker_id, 'quantite' => $qty, 'reserve' => 0), array('%d', '%d', '%d', '%d', '%d', '%d'));
                if ($ok === false) {
                    return STK_Database::db_error();
                }
            }

            if ($message !== '') {
                $ok = $wpdb->insert(STK_Database::table('messages'), array(
                    'thread_id'   => $thread_id,
                    'exchange_id' => $id,
                    'user_id'     => $proposer_id,
                    'message'     => $message,
                    'created_at'  => $now,
                ), array('%d', '%d', '%d', '%s', '%s'));
                if ($ok === false) {
                    return STK_Database::db_error();
                }
            }

            if ($parent_id) {
                self::log($parent_id, $proposer_id, 'counter', (string) $id);
            }
            self::log($id, $proposer_id, $parent_id ? 'counter_created' : 'propose', $parent_id ? (string) $parent_id : '');
            return $id;
        });
    }

    /* ------------------------------------------------------------------
     * Réponses à une proposition
     * ------------------------------------------------------------------ */

    public static function accept($id, $user_id) {
        global $wpdb;
        $user_id = (int) $user_id;

        return STK_Database::transaction(function () use ($wpdb, $id, $user_id) {
            $exchange = self::lock_exchange($id);
            if (!$exchange || self::role($exchange, $user_id) !== 'recipient') {
                return self::not_found();
            }
            if ($exchange->statut !== self::PROPOSE) {
                return self::wrong_state();
            }
            if (!STK_Members::is_ready($user_id)) {
                return new WP_Error('stk_not_ready', __('Renseignez votre adresse avant d\'accepter un échange.', 'stickers'));
            }
            $proposer_id = (int) $exchange->proposer_id;
            if (!get_userdata($proposer_id)) {
                return new WP_Error('stk_recipient', __('Le compte de ce membre n\'existe plus.', 'stickers'));
            }

            $inventories = self::lock_inventories(array($proposer_id, $user_id));
            if (is_wp_error($inventories)) {
                return $inventories;
            }
            $reserved = STK_Inventory::get_reserved($user_id, true);
            $stickers = STK_Catalog::get_all(true);
            $items    = self::get_items($id);

            foreach ($items as $item) {
                $sid = (int) $item->sticker_id;
                $qty = (int) $item->quantite;
                if (!isset($stickers[$sid])) {
                    return new WP_Error('stk_sticker', sprintf(
                        /* translators: %s: libellé du sticker */
                        __('%s n\'est plus disponible dans le catalogue.', 'stickers'),
                        self::sticker_label($sid)
                    ));
                }
                $receiver = (int) $item->receiver_id;
                if (($inventories[$receiver][$sid] ?? 0) + $qty > STK_MAX_QTY) {
                    return new WP_Error('stk_cap', sprintf(
                        /* translators: 1: libellé du sticker, 2: quantité maximale */
                        __('%1$s : cet échange ferait dépasser %2$d exemplaires.', 'stickers'),
                        self::sticker_label($sid),
                        STK_MAX_QTY
                    ));
                }
                if ((int) $item->giver_id === $user_id) {
                    $free = ($inventories[$user_id][$sid] ?? 0) - 1 - ($reserved[$sid] ?? 0);
                    if ($free < $qty) {
                        return new WP_Error('stk_unavailable', sprintf(
                            /* translators: %s: libellé du sticker */
                            __('%s : vous n\'avez plus assez de doubles disponibles. Mettez à jour votre inventaire ou faites une contre-proposition.', 'stickers'),
                            self::sticker_label($sid)
                        ));
                    }
                }
            }

            $result = $wpdb->update(STK_Database::table('exchange_items'), array('reserve' => 1), array('exchange_id' => $id, 'giver_id' => $user_id), array('%d'), array('%d', '%d'));
            if ($result === false) {
                return STK_Database::db_error();
            }
            $ok = self::transition($id, self::PROPOSE, array('statut' => self::ACCEPTE, 'accepted_at' => STK_Database::now()));
            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, 'accept');
            return true;
        });
    }

    public static function refuse($id, $user_id) {
        return self::close_pending($id, (int) $user_id, 'recipient', self::REFUSE, 'refuse');
    }

    public static function cancel($id, $user_id) {
        return self::close_pending($id, (int) $user_id, 'proposer', self::ANNULE, 'cancel');
    }

    private static function close_pending($id, $user_id, $role, $status, $action) {
        return STK_Database::transaction(function () use ($id, $user_id, $role, $status, $action) {
            $exchange = self::lock_exchange($id);
            if (!$exchange || self::role($exchange, $user_id) !== $role) {
                return self::not_found();
            }
            if ($exchange->statut !== self::PROPOSE) {
                return self::wrong_state();
            }
            $ok = self::transition($id, self::PROPOSE, array('statut' => $status));
            if (is_wp_error($ok)) {
                return $ok;
            }
            $ok = self::release($id);
            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, $action);
            return true;
        });
    }

    /* ------------------------------------------------------------------
     * Échange accepté : expédition, réception, clôture
     * ------------------------------------------------------------------ */

    public static function ship($id, $user_id) {
        $user_id = (int) $user_id;
        return STK_Database::transaction(function () use ($id, $user_id) {
            $exchange = self::lock_exchange($id);
            $role     = $exchange ? self::role($exchange, $user_id) : null;
            if (!$role) {
                return self::not_found();
            }
            $items = self::get_items($id);
            if ($exchange->statut !== self::ACCEPTE || !self::gives($items, $user_id) || !empty($exchange->{self::field($role, 'shipped')})) {
                return self::wrong_state();
            }
            $data = array(self::field($role, 'shipped') => STK_Database::now());
            if ((int) $exchange->cancel_requested_by) {
                $data['cancel_requested_by'] = 0; // Une expédition rend l'annulation impossible.
                self::log($id, $user_id, 'cancel_void');
            }
            $ok = self::transition($id, self::ACCEPTE, $data);
            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, 'ship');
            return true;
        });
    }

    /**
     * Confirmation de réception. Quand toutes les réceptions attendues sont confirmées,
     * l'échange est clôturé et les inventaires sont mis à jour dans la même transaction.
     *
     * @return string|WP_Error 'received' ou 'closed'
     */
    public static function receive($id, $user_id) {
        $user_id = (int) $user_id;
        return STK_Database::transaction(function () use ($id, $user_id) {
            $exchange = self::lock_exchange($id);
            $role     = $exchange ? self::role($exchange, $user_id) : null;
            if (!$role) {
                return self::not_found();
            }
            $other = $role === 'proposer' ? 'recipient' : 'proposer';
            $items = self::get_items($id);
            if ($exchange->statut !== self::ACCEPTE || !self::receives($items, $user_id) || !empty($exchange->{self::field($role, 'received')})) {
                return self::wrong_state();
            }
            if (empty($exchange->{self::field($other, 'shipped')})) {
                return new WP_Error('stk_not_shipped', __('L\'autre membre n\'a pas encore indiqué avoir expédié ses stickers.', 'stickers'));
            }

            $now = STK_Database::now();
            $ok  = self::transition($id, self::ACCEPTE, array(self::field($role, 'received') => $now));
            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, 'receive');

            $exchange->{self::field($role, 'received')} = $now;
            foreach (array('proposer', 'recipient') as $party) {
                $party_id = (int) $exchange->{$party . '_id'};
                if (self::receives($items, $party_id) && empty($exchange->{self::field($party, 'received')})) {
                    return 'received';
                }
            }

            $closed = self::apply_and_close($exchange, $items);
            return is_wp_error($closed) ? $closed : 'closed';
        });
    }

    /**
     * Met à jour les inventaires et clôture. À appeler dans une transaction,
     * la ligne de l'échange étant déjà verrouillée.
     */
    private static function apply_and_close($exchange, array $items) {
        global $wpdb;
        $table       = STK_Database::table('inventory');
        $id          = (int) $exchange->id;
        $proposer_id = (int) $exchange->proposer_id;
        $now         = STK_Database::now();

        $inventories = self::lock_inventories(array($proposer_id, (int) $exchange->recipient_id));
        if (is_wp_error($inventories)) {
            return $inventories;
        }

        foreach ($items as $item) {
            $sid      = (int) $item->sticker_id;
            $qty      = (int) $item->quantite;
            $giver    = (int) $item->giver_id;
            $receiver = (int) $item->receiver_id;

            $giver_new = max(0, ($inventories[$giver][$sid] ?? 0) - $qty);
            $recv_old  = $inventories[$receiver][$sid] ?? 0;
            $recv_new  = min(STK_MAX_QTY, $recv_old + $qty);
            if ($recv_old + $qty > STK_MAX_QTY) {
                self::log($id, 0, 'cap', sprintf('%d:%d', $sid, $receiver));
            }

            foreach (array($giver => $giver_new, $receiver => $recv_new) as $user => $new_qty) {
                if (array_key_exists($sid, $inventories[$user])) {
                    $ok = $wpdb->update($table, array('quantite' => $new_qty, 'updated_at' => $now), array('user_id' => $user, 'sticker_id' => $sid), array('%d', '%s'), array('%d', '%d'));
                } else {
                    $ok = $wpdb->insert($table, array('user_id' => $user, 'sticker_id' => $sid, 'quantite' => $new_qty, 'updated_at' => $now), array('%d', '%d', '%d', '%s'));
                }
                if ($ok === false) {
                    return STK_Database::db_error();
                }
                $inventories[$user][$sid] = $new_qty;
            }
        }

        $ok = self::release($id);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $ok = self::transition($id, self::ACCEPTE, array('statut' => self::CLOTURE, 'closed_at' => $now, 'cancel_requested_by' => 0));
        if (is_wp_error($ok)) {
            return $ok;
        }
        self::log($id, 0, 'close');
        STK_Members::touch_activity($proposer_id);
        STK_Members::touch_activity((int) $exchange->recipient_id);
        return true;
    }

    /* ------------------------------------------------------------------
     * Annulation d'un échange accepté : accord des deux, avant toute expédition
     * ------------------------------------------------------------------ */

    private static function nobody_shipped($exchange) {
        return empty($exchange->proposer_shipped_at) && empty($exchange->recipient_shipped_at);
    }

    public static function request_cancel($id, $user_id) {
        $user_id = (int) $user_id;
        return STK_Database::transaction(function () use ($id, $user_id) {
            $exchange = self::lock_exchange($id);
            if (!$exchange || !self::role($exchange, $user_id)) {
                return self::not_found();
            }
            if ($exchange->statut !== self::ACCEPTE || (int) $exchange->cancel_requested_by) {
                return self::wrong_state();
            }
            if (!self::nobody_shipped($exchange)) {
                return new WP_Error('stk_shipped', __('Un envoi a déjà eu lieu : l\'échange ne peut plus être annulé.', 'stickers'));
            }
            $ok = self::transition($id, self::ACCEPTE, array('cancel_requested_by' => $user_id));
            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, 'cancel_request');
            return true;
        });
    }

    /**
     * $decision : 'withdraw' (le demandeur retire sa demande), 'decline' (l'autre refuse),
     * 'confirm' (l'autre accepte : l'échange est annulé et les réservations libérées).
     */
    public static function answer_cancel($id, $user_id, $decision) {
        $user_id = (int) $user_id;
        return STK_Database::transaction(function () use ($id, $user_id, $decision) {
            $exchange = self::lock_exchange($id);
            if (!$exchange || !self::role($exchange, $user_id)) {
                return self::not_found();
            }
            $requester = (int) $exchange->cancel_requested_by;
            if ($exchange->statut !== self::ACCEPTE || !$requester) {
                return self::wrong_state();
            }
            $is_requester = $requester === $user_id;

            if ($decision === 'withdraw' && $is_requester) {
                $ok = self::transition($id, self::ACCEPTE, array('cancel_requested_by' => 0));
                $action = 'cancel_withdraw';
            } elseif ($decision === 'decline' && !$is_requester) {
                $ok = self::transition($id, self::ACCEPTE, array('cancel_requested_by' => 0));
                $action = 'cancel_decline';
            } elseif ($decision === 'confirm' && !$is_requester) {
                if (!self::nobody_shipped($exchange)) {
                    return new WP_Error('stk_shipped', __('Un envoi a déjà eu lieu : l\'échange ne peut plus être annulé.', 'stickers'));
                }
                $ok = self::transition($id, self::ACCEPTE, array('statut' => self::ANNULE, 'cancel_requested_by' => 0, 'closed_at' => STK_Database::now()));
                if (!is_wp_error($ok)) {
                    $ok = self::release($id);
                }
                $action = 'cancel_confirm';
            } else {
                return self::wrong_state();
            }

            if (is_wp_error($ok)) {
                return $ok;
            }
            self::log($id, $user_id, $action);
            return true;
        });
    }
}
