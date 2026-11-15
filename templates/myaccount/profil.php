<?php
/**
 * Pseudo et adresse postale de la bourse.
 *
 * @var array $args { member, countries }
 * @package Stickers
 */

if (!defined('ABSPATH')) {
    exit;
}

$member    = $args['member'];
$countries = $args['countries'];
$value     = static function ($field) use ($member) {
    return $member ? (string) $member->$field : '';
};
?>
<div class="stk-profile">

    <p class="stk-privacy">
        <?php esc_html_e('Votre pseudo est visible des autres membres de la bourse. Votre nom et votre adresse ne sont communiqués qu\'à la personne avec qui vous avez accepté un échange, et seulement jusqu\'à sa clôture.', 'stickers'); ?>
    </p>

    <form method="post" class="stk-form" action="<?php echo esc_url(STK_Frontend::url('profil')); ?>">
        <h3><?php esc_html_e('Mon pseudo', 'stickers'); ?></h3>
        <input type="hidden" name="stk_action" value="save_pseudo">
        <?php wp_nonce_field('stk_save_pseudo', '_stk_nonce'); ?>
        <p class="form-row form-row-wide">
            <label for="stk-pseudo"><?php esc_html_e('Pseudo', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
            <input type="text" class="input-text" id="stk-pseudo" name="pseudo" required
                minlength="<?php echo esc_attr(STK_Members::PSEUDO_MIN); ?>" maxlength="<?php echo esc_attr(STK_Members::PSEUDO_MAX); ?>"
                pattern="[A-Za-z0-9._\-]+" autocomplete="nickname" value="<?php echo esc_attr($value('pseudo')); ?>"
                aria-describedby="stk-pseudo-help">
            <span id="stk-pseudo-help" class="description">
                <?php
                printf(
                    /* translators: 1: longueur minimale, 2: longueur maximale */
                    esc_html__('%1$d à %2$d caractères : lettres sans accent, chiffres, point, tiret ou tiret bas. Votre pseudo doit être unique.', 'stickers'),
                    (int) STK_Members::PSEUDO_MIN,
                    (int) STK_Members::PSEUDO_MAX
                );
                ?>
            </span>
        </p>
        <p><button type="submit" class="button"><?php esc_html_e('Enregistrer le pseudo', 'stickers'); ?></button></p>
    </form>

    <form method="post" class="stk-form" action="<?php echo esc_url(STK_Frontend::url('profil')); ?>">
        <h3><?php esc_html_e('Mon adresse pour les échanges', 'stickers'); ?></h3>

        <?php if (!$member) : ?>
            <p><?php esc_html_e('Choisissez d\'abord votre pseudo pour pouvoir enregistrer votre adresse.', 'stickers'); ?></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e('Cette adresse est propre à la bourse : elle ne modifie pas vos adresses de facturation et de livraison de la boutique.', 'stickers'); ?></p>
            <input type="hidden" name="stk_action" value="save_address">
            <?php wp_nonce_field('stk_save_address', '_stk_nonce'); ?>

            <p class="form-row form-row-wide">
                <label for="stk-destinataire"><?php esc_html_e('Nom et prénom du destinataire', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
                <input type="text" class="input-text" id="stk-destinataire" name="destinataire" required maxlength="100" autocomplete="name" value="<?php echo esc_attr($value('destinataire')); ?>">
            </p>
            <p class="form-row form-row-wide">
                <label for="stk-adresse-1"><?php esc_html_e('Adresse', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
                <input type="text" class="input-text" id="stk-adresse-1" name="adresse_1" required maxlength="190" autocomplete="address-line1" value="<?php echo esc_attr($value('adresse_1')); ?>">
            </p>
            <p class="form-row form-row-wide">
                <label for="stk-adresse-2"><?php esc_html_e('Complément d\'adresse (facultatif)', 'stickers'); ?></label>
                <input type="text" class="input-text" id="stk-adresse-2" name="adresse_2" maxlength="190" autocomplete="address-line2" value="<?php echo esc_attr($value('adresse_2')); ?>">
            </p>
            <p class="form-row form-row-first">
                <label for="stk-code-postal"><?php esc_html_e('Code postal', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
                <input type="text" class="input-text" id="stk-code-postal" name="code_postal" required maxlength="10" autocomplete="postal-code" value="<?php echo esc_attr($value('code_postal')); ?>">
            </p>
            <p class="form-row form-row-last">
                <label for="stk-ville"><?php esc_html_e('Ville', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
                <input type="text" class="input-text" id="stk-ville" name="ville" required maxlength="100" autocomplete="address-level2" value="<?php echo esc_attr($value('ville')); ?>">
            </p>
            <p class="form-row form-row-wide">
                <label for="stk-pays"><?php esc_html_e('Pays', 'stickers'); ?> <span class="required" aria-hidden="true">*</span></label>
                <select id="stk-pays" name="pays" required autocomplete="country">
                    <option value=""><?php esc_html_e('Choisir…', 'stickers'); ?></option>
                    <?php foreach ($countries as $code => $country) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($value('pays'), $code); ?>><?php echo esc_html($country['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <div class="clear"></div>
            <p><button type="submit" class="button"><?php esc_html_e('Enregistrer l\'adresse', 'stickers'); ?></button></p>
        <?php endif; ?>
    </form>
</div>
