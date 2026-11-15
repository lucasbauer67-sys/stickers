<?php
/**
 * Navigation interne de l'onglet Stickers.
 *
 * @var array $args { view, views }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<nav class="stk-nav" aria-label="<?php esc_attr_e('Bourse aux stickers', 'stickers'); ?>">
    <ul>
        <?php foreach ($args['views'] as $key => $label) : ?>
            <li class="<?php echo $key === $args['view'] ? 'is-active' : ''; ?>">
                <a href="<?php echo esc_url(STK_Frontend::url($key)); ?>"<?php echo $key === $args['view'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
