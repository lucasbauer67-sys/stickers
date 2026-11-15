<?php
/**
 * Navigation interne de l'onglet Stickers.
 *
 * @var array $args { view, views, badges }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<nav class="stk-nav" aria-label="<?php esc_attr_e('Bourse aux stickers', 'stickers'); ?>">
    <ul>
        <?php foreach ($args['views'] as $key => $label) :
            $badge = (int) ($args['badges'][$key] ?? 0);
            ?>
            <li class="<?php echo $key === $args['view'] ? 'is-active' : ''; ?>">
                <a href="<?php echo esc_url(STK_Frontend::url($key)); ?>"<?php echo $key === $args['view'] ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html($label); ?>
                    <?php if ($badge) : ?>
                        <span class="stk-badge" aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %d: nombre d'éléments en attente */
                            _n('%d en attente', '%d en attente', $badge, 'stickers'),
                            $badge
                        )); ?>"><?php echo esc_html($badge); ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
