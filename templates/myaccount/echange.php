<?php
/**
 * Détail d'une proposition ou d'un échange, actions et discussion.
 *
 * @var array $args { user_id, exchange, items, other_pseudo, other, stickers, messages, pseudos, actions }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$exchange = $args['exchange'];
$user_id  = (int) $args['user_id'];
$items    = $args['items'];
$role     = STK_Exchanges::role($exchange, $user_id);
$other_role = $role === 'proposer' ? 'recipient' : 'proposer';
$lines    = STK_Frontend::items_lines($items, $user_id, $args['stickers']);
$gives    = STK_Exchanges::gives($items, $user_id);
$other_gives = STK_Exchanges::receives($items, $user_id); // l'autre donne ce que je reçois
$status   = $exchange->statut;

$my_shipped     = $exchange->{$role . '_shipped_at'};
$my_received    = $exchange->{$role . '_received_at'};
$other_shipped  = $exchange->{$other_role . '_shipped_at'};
$other_received = $exchange->{$other_role . '_received_at'};
$cancel_by      = (int) $exchange->cancel_requested_by;
$nobody_shipped = empty($exchange->proposer_shipped_at) && empty($exchange->recipient_shipped_at);

/**
 * Bouton d'action (formulaire POST avec nonce lié à l'échange).
 */
$button = static function ($action, $label, $class = 'button') use ($exchange) {
    ?>
    <form method="post" class="stk-inline-form" action="<?php echo esc_url(STK_Frontend::exchange_url($exchange->id)); ?>">
        <input type="hidden" name="stk_action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="exchange_id" value="<?php echo esc_attr($exchange->id); ?>">
        <?php wp_nonce_field('stk_' . $action . '_' . $exchange->id, '_stk_nonce'); ?>
        <button type="submit" class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
};
?>
<div class="stk-exchange">
    <p><a href="<?php echo esc_url(STK_Frontend::url($exchange->accepted_at ? 'echanges' : 'propositions')); ?>">← <?php esc_html_e('Retour', 'stickers'); ?></a></p>

    <h3>
        <?php
        echo esc_html(sprintf(
            /* translators: 1: numéro, 2: pseudo */
            __('Nº %1$d avec %2$s', 'stickers'),
            (int) $exchange->id,
            $args['other_pseudo']
        ));
        ?>
        <span class="stk-status stk-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(STK_Exchanges::label($status)); ?></span>
    </h3>
    <p class="stk-date">
        <?php
        printf(
            /* translators: 1: pseudo du proposeur, 2: date */
            esc_html__('Proposé par %1$s le %2$s', 'stickers'),
            esc_html($args['pseudos'][(int) $exchange->proposer_id] ?? ''),
            esc_html(STK_Frontend::date($exchange->created_at))
        );
        ?>
    </p>

    <table class="shop_table stk-exchange-content">
        <tbody>
            <tr><th><?php esc_html_e('Vous donnez', 'stickers'); ?></th><td><?php echo $lines['give'] ? esc_html(implode(', ', $lines['give'])) : esc_html__('rien', 'stickers'); ?></td></tr>
            <tr><th><?php esc_html_e('Vous recevez', 'stickers'); ?></th><td><?php echo $lines['receive'] ? esc_html(implode(', ', $lines['receive'])) : esc_html__('rien', 'stickers'); ?></td></tr>
        </tbody>
    </table>

    <?php if ($status === STK_Exchanges::PROPOSE) : ?>
        <div class="stk-actions-box">
            <?php if ($role === 'recipient') : ?>
                <p><?php esc_html_e('Si vous acceptez, vos stickers donnés seront réservés et l\'adresse d\'envoi sera communiquée à celui qui doit expédier.', 'stickers'); ?></p>
                <?php
                $button('accept', __('Accepter', 'stickers'));
                $button('refuse', __('Refuser', 'stickers'), 'button stk-button-secondary');
                ?>
                <a class="button stk-button-secondary" href="<?php echo esc_url(STK_Frontend::member_url($args['other_pseudo'], array('reponse' => (int) $exchange->id))); ?>"><?php esc_html_e('Contre-proposer', 'stickers'); ?></a>
            <?php else : ?>
                <p><?php esc_html_e('En attente de la réponse de l\'autre membre. Vos stickers donnés sont réservés.', 'stickers'); ?></p>
                <?php $button('cancel', __('Annuler ma proposition', 'stickers'), 'button stk-button-secondary'); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($status === STK_Exchanges::ACCEPTE) : ?>
        <?php if ($args['other']) :
            $other = $args['other'];
            ?>
            <div class="stk-address">
                <h4><?php esc_html_e('Adresse d\'envoi', 'stickers'); ?></h4>
                <address>
                    <?php echo esc_html($other->destinataire); ?><br>
                    <?php echo esc_html($other->adresse_1); ?><br>
                    <?php if ($other->adresse_2 !== '') : ?><?php echo esc_html($other->adresse_2); ?><br><?php endif; ?>
                    <?php echo esc_html($other->code_postal . ' ' . $other->ville); ?><br>
                    <?php echo esc_html(STK_Members::country_label($other->pays)); ?>
                </address>
                <p class="stk-help"><?php esc_html_e('Cette adresse ne sera plus visible après la clôture de l\'échange.', 'stickers'); ?></p>
            </div>
        <?php endif; ?>

        <ol class="stk-steps">
            <?php if ($gives) : ?>
                <li class="<?php echo $my_shipped ? 'is-done' : ''; ?>">
                    <?php if ($my_shipped) : ?>
                        <?php
                        printf(
                            /* translators: %s: date */
                            esc_html__('Vous avez expédié vos stickers le %s.', 'stickers'),
                            esc_html(STK_Frontend::date($my_shipped))
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Envoyez vos stickers par courrier, puis confirmez :', 'stickers'); ?>
                        <?php $button('ship', __('J\'ai expédié', 'stickers')); ?>
                    <?php endif; ?>
                </li>
            <?php endif; ?>

            <?php if ($other_gives) : ?>
                <li class="<?php echo $other_shipped ? 'is-done' : ''; ?>">
                    <?php if ($other_shipped) : ?>
                        <?php
                        printf(
                            /* translators: 1: pseudo, 2: date */
                            esc_html__('%1$s a expédié ses stickers le %2$s.', 'stickers'),
                            esc_html($args['other_pseudo']),
                            esc_html(STK_Frontend::date($other_shipped))
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: pseudo */
                            esc_html__('En attente de l\'expédition par %s.', 'stickers'),
                            esc_html($args['other_pseudo'])
                        );
                        ?>
                    <?php endif; ?>
                </li>
                <li class="<?php echo $my_received ? 'is-done' : ''; ?>">
                    <?php if ($my_received) : ?>
                        <?php
                        printf(
                            /* translators: %s: date */
                            esc_html__('Vous avez confirmé la réception le %s.', 'stickers'),
                            esc_html(STK_Frontend::date($my_received))
                        );
                        ?>
                    <?php elseif ($other_shipped) : ?>
                        <?php esc_html_e('Quand vous avez reçu les stickers, confirmez :', 'stickers'); ?>
                        <?php $button('receive', __('Reçu', 'stickers')); ?>
                    <?php else : ?>
                        <?php esc_html_e('Vous pourrez confirmer la réception une fois l\'envoi indiqué.', 'stickers'); ?>
                    <?php endif; ?>
                </li>
            <?php endif; ?>

            <?php if ($gives) : ?>
                <li class="<?php echo $other_received ? 'is-done' : ''; ?>">
                    <?php if ($other_received) : ?>
                        <?php
                        printf(
                            /* translators: %s: pseudo */
                            esc_html__('%s a confirmé la réception.', 'stickers'),
                            esc_html($args['other_pseudo'])
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: pseudo */
                            esc_html__('En attente de la confirmation de réception par %s.', 'stickers'),
                            esc_html($args['other_pseudo'])
                        );
                        ?>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        </ol>
        <p class="stk-help"><?php esc_html_e('L\'échange est clôturé, et les inventaires mis à jour, quand toutes les réceptions sont confirmées.', 'stickers'); ?></p>

        <?php if ($nobody_shipped) : ?>
            <div class="stk-cancel-box">
                <?php if (!$cancel_by) : ?>
                    <p><?php esc_html_e('Tant que rien n\'est expédié, l\'échange peut être annulé si vous êtes tous les deux d\'accord.', 'stickers'); ?></p>
                    <?php $button('request_cancel', __('Demander l\'annulation', 'stickers'), 'button stk-button-secondary'); ?>
                <?php elseif ($cancel_by === $user_id) : ?>
                    <p><?php esc_html_e('Vous avez demandé l\'annulation : en attente de la confirmation de l\'autre membre.', 'stickers'); ?></p>
                    <?php $button('withdraw_cancel', __('Retirer ma demande', 'stickers'), 'button stk-button-secondary'); ?>
                <?php else : ?>
                    <p><strong><?php
                        printf(
                            /* translators: %s: pseudo */
                            esc_html__('%s demande l\'annulation de cet échange.', 'stickers'),
                            esc_html($args['other_pseudo'])
                        );
                    ?></strong></p>
                    <?php
                    $button('confirm_cancel', __('Confirmer l\'annulation', 'stickers'));
                    $button('decline_cancel', __('Refuser et poursuivre l\'échange', 'stickers'), 'button stk-button-secondary');
                    ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($status === STK_Exchanges::CLOTURE) : ?>
        <p class="stk-info">
            <?php
            printf(
                /* translators: %s: date */
                esc_html__('Échange clôturé le %s. Les inventaires ont été mis à jour.', 'stickers'),
                esc_html(STK_Frontend::date($exchange->closed_at))
            );
            ?>
        </p>
    <?php endif; ?>

    <section class="stk-discussion" id="stk-discussion">
        <h4><?php esc_html_e('Discussion', 'stickers'); ?></h4>
        <?php if (!$args['messages']) : ?>
            <p class="stk-help"><?php esc_html_e('Aucun message.', 'stickers'); ?></p>
        <?php else : ?>
            <ul class="stk-messages">
                <?php foreach ($args['messages'] as $message) :
                    $mine = (int) $message->user_id === $user_id;
                    ?>
                    <li class="stk-message<?php echo $mine ? ' is-mine' : ''; ?>">
                        <p class="stk-message-meta">
                            <strong><?php echo $mine ? esc_html__('Vous', 'stickers') : esc_html($args['pseudos'][(int) $message->user_id] ?? $args['other_pseudo']); ?></strong>
                            · <?php echo esc_html(STK_Frontend::date($message->created_at)); ?>
                        </p>
                        <?php if ((int) $message->hidden) : ?>
                            <p class="stk-message-hidden"><?php esc_html_e('Message masqué par la modération.', 'stickers'); ?></p>
                        <?php else : ?>
                            <p><?php echo nl2br(esc_html($message->message)); ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (in_array($status, STK_Exchanges::active_statuses(), true)) : ?>
            <form method="post" class="stk-message-form" action="<?php echo esc_url(STK_Frontend::exchange_url($exchange->id)); ?>">
                <input type="hidden" name="stk_action" value="post_message">
                <input type="hidden" name="exchange_id" value="<?php echo esc_attr($exchange->id); ?>">
                <?php wp_nonce_field('stk_post_message_' . $exchange->id, '_stk_nonce'); ?>
                <label for="stk-message" class="screen-reader-text"><?php esc_html_e('Votre message', 'stickers'); ?></label>
                <textarea id="stk-message" name="message" rows="3" required maxlength="<?php echo esc_attr(STK_Exchanges::MESSAGE_MAX); ?>"></textarea>
                <span class="description"><?php esc_html_e('Visible par l\'autre membre et par l\'équipe du site.', 'stickers'); ?></span>
                <p><button type="submit" class="button"><?php esc_html_e('Envoyer le message', 'stickers'); ?></button></p>
            </form>
        <?php else : ?>
            <p class="stk-help"><?php esc_html_e('La discussion est fermée.', 'stickers'); ?></p>
        <?php endif; ?>
    </section>
</div>
