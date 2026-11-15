<?php
/**
 * Propositions reçues, envoyées et historique.
 *
 * @var array $args { user_id, exchanges, items, pseudos, stickers }
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
