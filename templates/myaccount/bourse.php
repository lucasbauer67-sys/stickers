<?php
/**
 * Bourse : liste des membres avec qui échanger.
 *
 * @var array $args { rows, total, page, pages, filter, unknown, stickers }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="stk-bourse">
    <p class="stk-help"><?php esc_html_e('Les membres sont classés par intérêt mutuel : en tête, ceux avec qui un échange profite aux deux.', 'stickers'); ?></p>

    <form method="get" class="stk-bourse-filter" action="<?php echo esc_url(wc_get_account_endpoint_url(STK_Frontend::ENDPOINT)); ?>">
        <input type="hidden" name="vue" value="bourse">
        <label for="stk-numero-filter"><?php esc_html_e('Qui a ce numéro en double ?', 'stickers'); ?></label>
        <input type="text" id="stk-numero-filter" name="numero" value="<?php echo esc_attr($args['filter']); ?>" maxlength="<?php echo esc_attr(STK_Catalog::NUMERO_MAX_LENGTH); ?>" placeholder="<?php esc_attr_e('ex. 12', 'stickers'); ?>">
        <button type="submit" class="button"><?php esc_html_e('Filtrer', 'stickers'); ?></button>
        <?php if ($args['filter'] !== '') : ?>
            <a href="<?php echo esc_url(STK_Frontend::url('bourse')); ?>"><?php esc_html_e('Voir tous les membres', 'stickers'); ?></a>
        <?php endif; ?>
    </form>

    <?php if ($args['unknown']) : ?>
        <p><?php esc_html_e('Ce numéro n\'existe pas dans le catalogue.', 'stickers'); ?></p>
    <?php elseif (!$args['rows']) : ?>
        <p><?php echo $args['filter'] !== '' ? esc_html__('Aucun membre n\'a ce numéro en double disponible pour le moment.', 'stickers') : esc_html__('Aucun autre membre n\'est encore prêt à échanger.', 'stickers'); ?></p>
    <?php else : ?>
        <table class="shop_table shop_table_responsive stk-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Membre', 'stickers'); ?></th>
                    <th><?php esc_html_e('Ses doubles qui vous manquent', 'stickers'); ?></th>
                    <th><?php esc_html_e('Vos doubles qui lui manquent', 'stickers'); ?></th>
                    <th><span class="screen-reader-text"><?php esc_html_e('Action', 'stickers'); ?></span></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($args['rows'] as $row) : ?>
                <tr>
                    <td data-title="<?php esc_attr_e('Membre', 'stickers'); ?>"><strong><?php echo esc_html($row['pseudo']); ?></strong></td>
                    <td data-title="<?php esc_attr_e('Ses doubles qui vous manquent', 'stickers'); ?>"><?php echo esc_html($row['theirs']); ?></td>
                    <td data-title="<?php esc_attr_e('Vos doubles qui lui manquent', 'stickers'); ?>"><?php echo esc_html($row['mine']); ?></td>
                    <td><a class="button" href="<?php echo esc_url(STK_Frontend::member_url($row['pseudo'])); ?>"><?php esc_html_e('Voir et proposer', 'stickers'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($args['pages'] > 1) : ?>
            <nav class="stk-pagination" aria-label="<?php esc_attr_e('Pages de la bourse', 'stickers'); ?>">
                <?php for ($p = 1; $p <= $args['pages']; $p++) :
                    $url = STK_Frontend::url('bourse', array_filter(array('page_bourse' => $p, 'numero' => $args['filter'])));
                    ?>
                    <?php if ($p === $args['page']) : ?>
                        <span class="current" aria-current="page"><?php echo esc_html($p); ?></span>
                    <?php else : ?>
                        <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($p); ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
