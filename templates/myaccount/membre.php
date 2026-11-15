<?php
/**
 * Fiche d'un membre : ses doubles, ses manquants, formulaire de proposition.
 *
 * @var array $args { other, detail, parent, prefill, ready }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$other   = $args['other'];
$detail  = $args['detail'];
$parent  = $args['parent'];
$prefill = $args['prefill'];

$lost = array_diff_key($prefill['ask'], $detail['can_ask']) + array_diff_key($prefill['give'], $detail['can_give']);

$label = static function ($sticker) {
    return sprintf(
        /* translators: 1: numéro, 2: nom */
        __('nº %1$s — %2$s', 'stickers'),
        $sticker->numero,
        $sticker->nom
    );
};
?>
<div class="stk-member">
    <p><a href="<?php echo esc_url(STK_Frontend::url('bourse')); ?>">← <?php esc_html_e('Retour à la bourse', 'stickers'); ?></a></p>
    <h3><?php echo esc_html($other->pseudo); ?></h3>

    <?php if ($parent) : ?>
        <p class="stk-info">
            <?php
            printf(
                /* translators: %d: numéro de la proposition */
                esc_html__('Contre-proposition à la proposition nº %d : elle sera remplacée par celle-ci. Ajustez les quantités puis envoyez.', 'stickers'),
                (int) $parent->id
            );
            ?>
        </p>
        <?php if ($lost) : ?>
            <p class="stk-info"><?php esc_html_e('Certains stickers de la proposition reçue ne sont plus disponibles et ont été retirés.', 'stickers'); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="stk-member-missing">
        <h4><?php esc_html_e('Ce qui lui manque', 'stickers'); ?></h4>
        <?php if (!$detail['their_missing']) : ?>
            <p><?php esc_html_e('Ce membre a complété sa collection.', 'stickers'); ?></p>
        <?php else : ?>
            <ul class="stk-chips">
                <?php foreach ($detail['their_missing'] as $row) : ?>
                    <li class="<?php echo $row['i_have'] ? 'is-match' : ''; ?>" title="<?php echo esc_attr($row['sticker']->nom); ?>"><?php echo esc_html($row['sticker']->numero); ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="stk-help"><?php esc_html_e('En gras : les numéros que vous avez en double disponible.', 'stickers'); ?></p>
        <?php endif; ?>
    </div>

    <form method="post" class="stk-proposal" action="<?php echo esc_url(STK_Frontend::member_url($other->pseudo)); ?>">
        <input type="hidden" name="stk_action" value="propose">
        <input type="hidden" name="recipient" value="<?php echo esc_attr($other->pseudo); ?>">
        <input type="hidden" name="parent_id" value="<?php echo esc_attr($parent ? $parent->id : 0); ?>">
        <?php wp_nonce_field('stk_propose', '_stk_nonce'); ?>

        <fieldset>
            <legend><?php esc_html_e('Je demande (ses doubles disponibles)', 'stickers'); ?></legend>
            <?php if (!$detail['can_ask']) : ?>
                <p><?php esc_html_e('Ce membre n\'a aucun double disponible pour vous.', 'stickers'); ?></p>
            <?php else : ?>
                <ul class="stk-pick">
                    <?php foreach ($detail['can_ask'] as $sid => $row) :
                        $field = 'stk-ask-' . $sid;
                        ?>
                        <li class="<?php echo $row['useful'] ? 'is-useful' : ''; ?>">
                            <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($label($row['sticker'])); ?>
                                <?php if ($row['useful']) : ?><span class="stk-tag"><?php esc_html_e('il vous manque', 'stickers'); ?></span><?php endif; ?>
                            </label>
                            <input type="number" id="<?php echo esc_attr($field); ?>" name="ask[<?php echo esc_attr($sid); ?>]" min="0" max="<?php echo esc_attr($row['max']); ?>" step="1"
                                value="<?php echo esc_attr(min($row['max'], $prefill['ask'][$sid] ?? 0)); ?>" inputmode="numeric">
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend><?php esc_html_e('Je donne (mes doubles disponibles)', 'stickers'); ?></legend>
            <?php if (!$detail['can_give']) : ?>
                <p><?php esc_html_e('Vous n\'avez aucun double disponible à proposer.', 'stickers'); ?></p>
            <?php else : ?>
                <ul class="stk-pick">
                    <?php foreach ($detail['can_give'] as $sid => $row) :
                        $field = 'stk-give-' . $sid;
                        ?>
                        <li class="<?php echo $row['useful'] ? 'is-useful' : ''; ?>">
                            <label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($label($row['sticker'])); ?>
                                <?php if ($row['useful']) : ?><span class="stk-tag"><?php esc_html_e('il lui manque', 'stickers'); ?></span><?php endif; ?>
                            </label>
                            <input type="number" id="<?php echo esc_attr($field); ?>" name="give[<?php echo esc_attr($sid); ?>]" min="0" max="<?php echo esc_attr($row['max']); ?>" step="1"
                                value="<?php echo esc_attr(min($row['max'], $prefill['give'][$sid] ?? 0)); ?>" inputmode="numeric">
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>

        <p class="form-row form-row-wide">
            <label for="stk-proposal-message"><?php esc_html_e('Message (facultatif)', 'stickers'); ?></label>
            <textarea id="stk-proposal-message" name="message" rows="3" maxlength="<?php echo esc_attr(STK_Exchanges::MESSAGE_MAX); ?>"></textarea>
            <span class="description"><?php esc_html_e('Visible par ce membre et par l\'équipe du site. Ne communiquez pas votre adresse : elle est transmise automatiquement après acceptation.', 'stickers'); ?></span>
        </p>

        <p class="stk-help"><?php esc_html_e('Les stickers que vous donnez sont réservés dès l\'envoi : ils ne peuvent pas être promis à quelqu\'un d\'autre tant que la proposition est en attente.', 'stickers'); ?></p>

        <?php if ($args['ready']) : ?>
            <button type="submit" class="button"><?php echo $parent ? esc_html__('Envoyer la contre-proposition', 'stickers') : esc_html__('Envoyer la proposition', 'stickers'); ?></button>
        <?php else : ?>
            <p><a href="<?php echo esc_url(STK_Frontend::url('profil')); ?>"><?php esc_html_e('Renseignez votre adresse pour pouvoir envoyer une proposition.', 'stickers'); ?></a></p>
        <?php endif; ?>
    </form>
</div>
