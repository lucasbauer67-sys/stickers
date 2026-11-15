<?php
/**
 * Suggestions automatiques, propositions reçues, envoyées et historique.
 *
 * @var array $args { suggestions, user_id, exchanges, items, pseudos, stickers }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$groups = array('received' => array(), 'sent' => array(), 'history' => array());
foreach ($args['exchanges'] as $exchange) {
    if ($exchange->statut !== STK_Exchanges::PROPOSE) {
        $groups['history'][] = $exchange;
    } elseif ((int) $exchange->recipient_id === (int) $args['user_id']) {
        $groups['received'][] = $exchange;
    } else {
        $groups['sent'][] = $exchange;
    }
}
$titles = array(
    'received' => __('Reçues, en attente de votre réponse', 'stickers'),
    'sent'     => __('Envoyées, en attente de réponse', 'stickers'),
    'history'  => __('Historique', 'stickers'),
);
$empty = array(
    'received' => __('Aucune proposition reçue en attente.', 'stickers'),
    'sent'     => __('Aucune proposition envoyée en attente.', 'stickers'),
    'history'  => __('Aucune proposition terminée.', 'stickers'),
);
?>
<div class="stk-propositions">
    <section class="stk-suggestions">
        <h3><?php esc_html_e('Suggestions d\'échanges', 'stickers'); ?></h3>
        <p class="stk-help"><?php esc_html_e('Membres avec qui l\'échange profite aux deux : vous avez des doubles qui leur manquent, et ils ont des doubles qui vous manquent.', 'stickers'); ?></p>
        <?php if (!$args['suggestions']) : ?>
            <p><?php esc_html_e('Aucune correspondance mutuelle pour le moment. Pensez à tenir votre inventaire à jour.', 'stickers'); ?></p>
        <?php else : ?>
            <ul class="stk-exchange-list">
                <?php foreach ($args['suggestions'] as $suggestion) :
                    $names = static function (array $ids) use ($args) {
                        $out = array();
                        foreach ($ids as $sid) {
                            $sticker = $args['stickers'][$sid] ?? null;
                            $out[]   = $sticker ? sprintf(
                                /* translators: 1: numéro, 2: nom */
                                __('nº %1$s %2$s', 'stickers'),
                                $sticker->numero,
                                $sticker->nom
                            ) : '#' . (int) $sid;
                        }
                        return implode(', ', $out);
                    };
                    $more = max($suggestion['more_give'], $suggestion['more_ask']);
                    ?>
                    <li class="stk-exchange-card stk-suggestion">
                        <p class="stk-exchange-head">
                            <strong><?php echo esc_html($suggestion['pseudo']); ?></strong>
                            <span class="stk-status"><?php echo esc_html(sprintf(
                                /* translators: %d: nombre d'échanges un pour un */
                                _n('%d échange un pour un', '%d échanges un pour un', $suggestion['pairs'], 'stickers'),
                                $suggestion['pairs']
                            )); ?></span>
                        </p>
                        <p class="stk-exchange-lines">
                            <span><?php esc_html_e('Vous recevriez :', 'stickers'); ?> <?php echo esc_html($names($suggestion['ask'])); ?></span><br>
                            <span><?php esc_html_e('Vous donneriez :', 'stickers'); ?> <?php echo esc_html($names($suggestion['give'])); ?></span>
                        </p>
                        <?php if ($more) : ?>
                            <p class="stk-help"><?php echo esc_html(sprintf(
                                /* translators: %d: nombre de numéros supplémentaires */
                                _n('Et %d autre numéro possible avec ce membre (voir sa fiche).', 'Et %d autres numéros possibles avec ce membre (voir sa fiche).', $more, 'stickers'),
                                $more
                            )); ?></p>
                        <?php endif; ?>
                        <p>
                            <a class="button" href="<?php echo esc_url(STK_Frontend::member_url($suggestion['pseudo'], array('suggestion' => 1))); ?>"><?php esc_html_e('Préparer cette proposition', 'stickers'); ?></a>
                            <a href="<?php echo esc_url(STK_Frontend::member_url($suggestion['pseudo'])); ?>"><?php esc_html_e('Voir sa fiche', 'stickers'); ?></a>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php foreach ($groups as $key => $list) : ?>
        <h3><?php echo esc_html($titles[$key]); ?></h3>
        <?php if (!$list) : ?>
            <p><?php echo esc_html($empty[$key]); ?></p>
        <?php else : ?>
            <ul class="stk-exchange-list">
                <?php foreach ($list as $exchange) {
                    STK_Frontend::template('exchange-card', array(
                        'exchange'  => $exchange,
                        'items'     => $args['items'][(int) $exchange->id] ?? array(),
                        'user_id'   => $args['user_id'],
                        'pseudo'    => $args['pseudos'][STK_Exchanges::other_party($exchange, $args['user_id'])] ?? '',
                        'stickers'  => $args['stickers'],
                        'highlight' => $key === 'received' ? __('À vous de répondre : accepter, refuser ou contre-proposer.', 'stickers') : '',
                    ));
                } ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
