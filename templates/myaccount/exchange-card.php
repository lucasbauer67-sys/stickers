<?php
/**
 * Résumé d'un échange dans une liste.
 *
 * @var array $args { exchange, items, user_id, pseudo, stickers, highlight }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$exchange = $args['exchange'];
$lines    = STK_Frontend::items_lines($args['items'], $args['user_id'], $args['stickers']);
$sent     = (int) $exchange->proposer_id === (int) $args['user_id'];
?>
<li class="stk-exchange-card<?php echo $args['highlight'] ? ' is-highlight' : ''; ?>">
    <p class="stk-exchange-head">
        <strong>
            <?php
            echo esc_html(sprintf(
                /* translators: 1: numéro de l'échange, 2: pseudo */
                $sent ? __('Nº %1$d · à %2$s', 'stickers') : __('Nº %1$d · de %2$s', 'stickers'),
                (int) $exchange->id,
                $args['pseudo']
            ));
            ?>
        </strong>
        <span class="stk-status stk-status-<?php echo esc_attr($exchange->statut); ?>"><?php echo esc_html(STK_Exchanges::label($exchange->statut)); ?></span>
        <span class="stk-date"><?php echo esc_html(STK_Frontend::date($exchange->updated_at)); ?></span>
    </p>
    <p class="stk-exchange-lines">
        <span><?php esc_html_e('Vous donnez :', 'stickers'); ?> <?php echo $lines['give'] ? esc_html(implode(', ', $lines['give'])) : '—'; ?></span><br>
        <span><?php esc_html_e('Vous recevez :', 'stickers'); ?> <?php echo $lines['receive'] ? esc_html(implode(', ', $lines['receive'])) : '—'; ?></span>
    </p>
    <?php if ($args['highlight']) : ?>
        <p class="stk-exchange-todo"><?php echo esc_html($args['highlight']); ?></p>
    <?php endif; ?>
    <p><a class="button" href="<?php echo esc_url(STK_Frontend::exchange_url($exchange->id)); ?>"><?php esc_html_e('Voir', 'stickers'); ?></a></p>
</li>
