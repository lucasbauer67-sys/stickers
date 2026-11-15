# Bourse aux stickers — v0.1.0

Plugin WordPress/WooCommerce des éditions Black and White : bourse d'échange de stickers entre membres connectés, dans l'esprit des albums Panini. Indépendant du plugin « Abonnements Éditions Limitées ».

- WordPress ≥ 7.1, PHP ≥ 8.3, WooCommerce ≥ 11.2 (requis). Compatible HPOS (le plugin ne lit ni n'écrit aucune commande).
- Tables : `wp_stk_*` (InnoDB). Text domain : `stickers`.

## Étape 1 (v0.1.0) — contenu

**Mon compte → Stickers** (après « Mes abonnements », sinon après « Commandes ») :
- **Mon inventaire** : grille cliquable (clic = +1, « − » = −1, de 0 à 9 exemplaires), enregistrement automatique ; filtres Tous / Manquants / Doubles ; compteurs possédés / manquants / doubles. Fonctionne aussi sans JavaScript (bouton « Enregistrer »).
- **Pseudo et adresse** : pseudo unique (3 à 20 caractères : lettres sans accent, chiffres, `.` `-` `_`, casse ignorée), adresse propre à la bourse (France, Belgique, Suisse ; code postal contrôlé selon le pays), indépendante des adresses WooCommerce.

**Admin → Stickers** :
- **Catalogue** : numéro (numéros spéciaux acceptés, ex. 12a), nom, image facultative (médiathèque), ordre d'affichage, actif. Le total de la collection = nombre de stickers actifs. Un sticker déjà déclaré par un membre ne peut qu'être désactivé.
- **Membres** : liste, recherche, fiche (adresse, inventaire), modification du pseudo par l'admin.
- **Réglages** (administrateurs) : nom de la collection, token GitHub, suppression des données à la désinstallation.

## Règles de stock

Doubles = exemplaires − 1. Manquants = numéros à 0. Un membre ne peut pas descendre sous 1 + exemplaires promis dans un échange en cours (réservations, actives à partir de l'étape 2). Les modifications d'inventaire se font en transaction avec verrouillage des lignes du membre.

## Mises à jour

Comme le plugin Abonnements : WordPress interroge les Releases du dépôt privé `lucasbauer67-sys/stickers` (token dans les Réglages).
Pour livrer : changer `Version:` dans l'en-tête **et** `STK_VERSION`, pousser sur `main`, puis créer une Release GitHub avec le tag `vX.Y.Z` identique. Créer la Release = déclencher la mise à jour sur les sites.

## Désinstallation

Supprimer le plugin ne supprime les données que si la case « Supprimer toutes les données de la bourse » est cochée dans les Réglages.

## Feuille de route

- Étape 2 : bourse, propositions, contre-propositions, échanges (expédié / reçu), réservations, clôture, fil de discussion par échange.
- Étape 3 : suggestions automatiques.
- Étape 4 : emails, rappels et expiration (Action Scheduler), litiges et arbitrage, RGPD (export / effacement), purge facultative.
