<?php
/**
 * Inventaire : grille cliquable des stickers.
 *
 * @var array $args { stickers, inventory, reserved, stats, collection }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$stickers  = $args['stickers'];
$inventory = $args['inventory'];
$reserved  = $args['reserved'];
$stats     = $args['stats'];
?>
<div class="stk-inventory" id="stk-inventory">

    <?php if ($args['collection'] !== '') : ?>
        <h3 class="stk-collection-name"><?php echo esc_html($args['collection']); ?></h3>
    <?php endif; ?>

    <?php if (!$stickers) : ?>
        <p><?php esc_html_e('Le catalogue des stickers n\'est pas encore disponible.', 'stickers'); ?></p>
    <?php else : ?>

        <p class="stk-stats" aria-live="polite">
            <?php
            printf(
                /* translators: 1: possédés, 2: total, 3: manquants, 4: doubles */
                esc_html__('%1$s / %2$s stickers possédés · %3$s manquants · %4$s doubles', 'stickers'),
                '<strong data-stk-stat="owned">' . esc_html($stats['owned']) . '</strong>',
                '<span data-stk-stat="total">' . esc_html($stats['total']) . '</span>',
                '<strong data-stk-stat="missing">' . esc_html($stats['missing']) . '</strong>',
                '<strong data-stk-stat="doubles">' . esc_html($stats['doubles']) . '</strong>'
            );
            ?>
        </p>

        <p class="stk-help">
            <?php
            printf(
                /* translators: %d: quantité maximale */
                esc_html__('Indiquez pour chaque numéro le nombre d\'exemplaires que vous possédez (0 à %d) avec les boutons « − » et « + », ou en saisissant le nombre. Cliquez sur un visuel pour l\'agrandir.', 'stickers'),
                (int) STK_MAX_QTY
            );
            ?>
        </p>

        <div class="stk-filters" role="group" aria-label="<?php esc_attr_e('Filtrer la grille', 'stickers'); ?>" hidden>
            <button type="button" class="button stk-filter is-active" data-stk-filter="all"><?php esc_html_e('Tous', 'stickers'); ?></button>
            <button type="button" class="button stk-filter" data-stk-filter="manquant"><?php esc_html_e('Manquants', 'stickers'); ?></button>
            <button type="button" class="button stk-filter" data-stk-filter="double"><?php esc_html_e('Doubles', 'stickers'); ?></button>
        </div>

        <form method="post" class="stk-inventory-form" action="<?php echo esc_url(STK_Frontend::url('inventaire')); ?>">
            <input type="hidden" name="stk_action" value="save_inventory">
            <?php wp_nonce_field('stk_save_inventory', '_stk_nonce'); ?>

            <ul class="stk-grid">
                <?php foreach ($stickers as $id => $sticker) :
                    $qty   = (int) ($inventory[$id] ?? 0);
                    $res   = (int) ($reserved[$id] ?? 0);
                    $min   = STK_Inventory::min_quantity($res);
                    $state = STK_Inventory::state($qty);
                    $field = 'stk-qty-' . $id;
                    ?>
                    <li class="stk-card stk-state-<?php echo esc_attr($state); ?>"
                        data-stk-id="<?php echo esc_attr($id); ?>"
                        data-stk-min="<?php echo esc_attr($min); ?>"
                        data-stk-reserved="<?php echo esc_attr($res); ?>">
                        <?php $image_url = STK_Watermark::url($sticker); // Copie filigranée, jamais l'original. ?>
                        <?php if ($image_url) : ?>
                            <a class="stk-card-zoom" href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener"
                                data-stk-caption="<?php echo esc_attr(STK_Catalog::label($sticker)); ?>"
                                aria-label="<?php echo esc_attr(sprintf(
                                    /* translators: %s: libellé du sticker */
                                    __('Agrandir le visuel : %s', 'stickers'),
                                    STK_Catalog::label($sticker)
                                )); ?>">
                                <img class="stk-card-img" src="<?php echo esc_url($image_url); ?>" alt="" loading="lazy" draggable="false">
                            </a>
                        <?php else : ?>
                            <span class="stk-card-noimg" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="stk-card-num"><?php echo esc_html($sticker->numero); ?></span>
                        <span class="stk-card-name"><?php echo esc_html($sticker->nom); ?></span>
                        <span class="stk-card-qty">
                            <button type="button" class="stk-qty-btn stk-card-minus" aria-label="<?php echo esc_attr(sprintf(
                                /* translators: %s: libellé du sticker */
                                __('Retirer un exemplaire : %s', 'stickers'),
                                STK_Catalog::label($sticker)
                            )); ?>">−</button>
                            <label class="screen-reader-text" for="<?php echo esc_attr($field); ?>"><?php echo esc_html(sprintf(
                                /* translators: %s: libellé du sticker */
                                __('Exemplaires : %s', 'stickers'),
                                STK_Catalog::label($sticker)
                            )); ?></label>
                            <input type="number" id="<?php echo esc_attr($field); ?>" name="qty[<?php echo esc_attr($id); ?>]"
                                value="<?php echo esc_attr($qty); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr(STK_MAX_QTY); ?>" step="1" inputmode="numeric">
                            <button type="button" class="stk-qty-btn stk-card-plus" aria-label="<?php echo esc_attr(sprintf(
                                /* translators: %s: libellé du sticker */
                                __('Ajouter un exemplaire : %s', 'stickers'),
                                STK_Catalog::label($sticker)
                            )); ?>">+</button>
                        </span>
                        <span class="stk-card-reserved"<?php echo $res ? '' : ' hidden'; ?>>
                            <?php echo esc_html(sprintf(
                                /* translators: %d: nombre d'exemplaires promis */
                                __('dont %d promis', 'stickers'),
                                $res
                            )); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="stk-inventory-submit">
                <button type="submit" class="button"><?php esc_html_e('Enregistrer l\'inventaire', 'stickers'); ?></button>
            </p>
            <p class="stk-inventory-status" aria-live="polite"></p>
        </form>
    <?php endif; ?>
</div>
