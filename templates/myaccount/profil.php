<?php
/**
 * Profil : fonctionnement de la bourse et acceptation des règles, pseudo, adresse postale.
 *
 * @var array $args { member, countries, accepted, accepted_at, terms_url }
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

    <section class="stk-rules" id="stk-regles">
        <details<?php echo $args['accepted'] ? '' : ' open'; ?>>
            <summary><h3><?php esc_html_e('Comment fonctionne la bourse', 'stickers'); ?></h3></summary>
            <ol class="stk-rules-steps">
                <li><?php esc_html_e('Inventaire : indiquez combien d\'exemplaires vous possédez de chaque sticker. Vos doubles et vos numéros manquants sont calculés automatiquement.', 'stickers'); ?></li>
                <li><?php esc_html_e('Bourse : trouvez les membres qui ont en double les stickers qui vous manquent (des suggestions vous sont aussi proposées dans « Propositions »), puis envoyez-leur une proposition : ce que vous demandez, ce que vous donnez.', 'stickers'); ?></li>
                <li><?php
                    printf(
                        /* translators: %d: nombre de jours avant expiration */
                        esc_html__('Les stickers que vous proposez sont réservés pendant l\'attente de la réponse. L\'autre membre peut accepter, refuser ou faire une contre-proposition ; sans réponse sous %d jours, la proposition expire.', 'stickers'),
                        (int) STK_Settings::get('expiry_days')
                    );
                ?></li>
                <li><?php esc_html_e('Une fois l\'échange accepté, l\'adresse d\'envoi s\'affiche pour celui qui doit expédier. Chacun envoie ses stickers par courrier, à ses frais, puis clique sur « J\'ai expédié ».', 'stickers'); ?></li>
                <li><?php esc_html_e('À réception, cliquez sur « Reçu ». Quand toutes les réceptions sont confirmées, l\'échange est clôturé et les inventaires sont mis à jour automatiquement.', 'stickers'); ?></li>
                <li><?php esc_html_e('Tant que rien n\'est expédié, un échange accepté peut être annulé d\'un commun accord. En cas de problème, utilisez « Signaler un problème » : l\'équipe du site examine la situation et sa décision est définitive.', 'stickers'); ?></li>
                <li><?php esc_html_e('Votre pseudo et vos doubles et manquants sont visibles des autres membres. Votre nom et votre adresse ne sont communiqués qu\'au membre avec qui vous échangez, jusqu\'à la clôture de l\'échange.', 'stickers'); ?></li>
                <li><?php esc_html_e('Les échanges se font uniquement sticker contre sticker (ou en don) : aucune vente ni contrepartie en argent. Restez courtois en toutes circonstances.', 'stickers'); ?></li>
            </ol>
            <?php if ($args['terms_url']) : ?>
                <p><a href="<?php echo esc_url($args['terms_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Lire les conditions générales d\'utilisation de la bourse', 'stickers'); ?></a></p>
            <?php endif; ?>
        </details>

        <?php if ($args['accepted']) : ?>
            <p class="stk-help">
                <?php
                printf(
                    /* translators: %s: date */
                    esc_html__('Vous avez accepté le fonctionnement et les conditions d\'utilisation de la bourse le %s.', 'stickers'),
                    esc_html(STK_Frontend::date($args['accepted_at']))
                );
                ?>
            </p>
        <?php else : ?>
            <form method="post" class="stk-form stk-rules-form" action="<?php echo esc_url(STK_Frontend::url('profil')); ?>">
                <input type="hidden" name="stk_action" value="accept_rules">
                <?php wp_nonce_field('stk_accept_rules', '_stk_nonce'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="accept_rules" value="1" required>
                        <?php
                        if ($args['terms_url']) {
                            printf(
                                /* translators: %s: lien vers les conditions générales d'utilisation */
                                wp_kses(__('J\'ai lu le fonctionnement de la bourse et j\'accepte les %s.', 'stickers'), array()),
                                '<a href="' . esc_url($args['terms_url']) . '" target="_blank" rel="noopener">' . esc_html__('conditions générales d\'utilisation de la bourse aux stickers', 'stickers') . '</a>'
                            );
                        } else {
                            esc_html_e('J\'ai lu le fonctionnement de la bourse et j\'accepte les conditions générales d\'utilisation de la bourse aux stickers.', 'stickers');
                        }
                        ?>
                    </label>
                </p>
                <p><button type="submit" class="button"><?php esc_html_e('Valider', 'stickers'); ?></button></p>
            </form>
        <?php endif; ?>
    </section>

    <?php if (!$args['accepted']) : ?>
        <p class="stk-info"><?php esc_html_e('Une fois le fonctionnement de la bourse accepté, vous pourrez choisir votre pseudo et renseigner votre adresse d\'envoi.', 'stickers'); ?></p>
</div>
        <?php return; ?>
    <?php endif; ?>

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
