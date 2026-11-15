<?php
/**
 * Échanges acceptés (en cours) et terminés.
 *
 * @var array $args { user_id, exchanges, items, pseudos, stickers }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$todo_labels = array(
    'ship'           => __('À faire : expédier vos stickers puis cliquer sur « J\'ai expédié ».', 'stickers'),
    'receive'        => __('À faire : confirmer la réception quand vous avez reçu les stickers.', 'stickers'),
    'confirm_cancel' => __('À faire : l\'autre membre demande l\'annulation.', 'stickers'),
);

$current = array();
$done    = array();
foreach ($args['exchanges'] as $exchange) {
    if (in_array($exchange->statut, array(STK_Exchanges::ACCEPTE, STK_Exchanges::LITIGE), true)) {
        $current[] = $exchange;
    } else {
        $done[] = $exchange;
    }
}
?>
<div class="stk-echanges">
    <?php foreach (array('current' => $current, 'done' => $done) as $key => $list) : ?>
        <h3><?php echo $key === 'current' ? esc_html__('En cours', 'stickers') : esc_html__('Terminés', 'stickers'); ?></h3>
        <?php if (!$list) : ?>
            <p><?php echo $key === 'current' ? esc_html__('Aucun échange en cours.', 'stickers') : esc_html__('Aucun échange terminé.', 'stickers'); ?></p>
        <?php else : ?>
            <ul class="stk-exchange-list">
                <?php foreach ($list as $exchange) {
                    $items   = $args['items'][(int) $exchange->id] ?? array();
                    $actions = STK_Exchanges::pending_actions($exchange, $items, $args['user_id']);
                    $todo    = array();
                    foreach ($actions as $action) {
                        $todo[] = $todo_labels[$action];
                    }
                    STK_Frontend::template('exchange-card', array(
                        'exchange'  => $exchange,
                        'items'     => $items,
                        'user_id'   => $args['user_id'],
                        'pseudo'    => $args['pseudos'][STK_Exchanges::other_party($exchange, $args['user_id'])] ?? '',
                        'stickers'  => $args['stickers'],
                        'highlight' => implode(' ', $todo),
                    ));
                } ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
