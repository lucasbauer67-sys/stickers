# Bourse aux stickers — v0.3.0

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

## Étape 2 (v0.2.0) — bourse et échanges

**Mon compte → Stickers**, nouvelles vues :
- **Bourse** : membres prêts à échanger (pseudo + adresse), classés par intérêt mutuel, avec « ses doubles qui vous manquent » et « vos doubles qui lui manquent » ; filtre « qui a ce numéro en double ? ». Le nom réel et l'adresse ne sont jamais affichés.
- **Fiche membre** : ce qui lui manque, ses doubles disponibles, formulaire de proposition (je demande / je donne, quantités, message facultatif).
- **Propositions** : reçues (accepter, refuser, contre-proposer), envoyées (annuler), historique.
- **Échanges** : en cours (étapes « J'ai expédié » puis « Reçu »), terminés.
- **Détail d'un échange** : contenu, actions, adresse d'envoi (seulement pendant un échange accepté, et seulement pour celui qui doit expédier), fil de discussion.

**Règles appliquées**
- Une seule proposition en attente à la fois entre deux membres.
- Les stickers donnés par le proposeur sont réservés dès l'envoi ; ceux du destinataire à l'acceptation (disponibilité revérifiée). Réservations libérées en cas de refus, d'annulation, de contre-proposition ou d'annulation d'un commun accord.
- Contre-proposition : la proposition reçue passe « remplacée », la nouvelle reprend le même fil de discussion.
- Don à sens unique autorisé (au moins un sticker).
- « J'ai expédié » obligatoire avant « Reçu ». La clôture intervient quand toutes les réceptions attendues sont confirmées (une partie qui ne reçoit rien n'a pas de réception à confirmer) ; les inventaires sont alors mis à jour.
- Annulation d'un échange accepté : demandée par l'un, confirmée par l'autre, tant que rien n'est expédié. Une expédition rend la demande caduque.
- Aucune quantité ne peut dépasser 9 (vérifié à la proposition, à l'acceptation, plafonné et tracé à la clôture).
- Toutes les transitions passent par une transaction : verrou sur l'échange, puis sur les inventaires (ordre fixe), puis sur les réservations ; changement de statut conditionnel.
- Discussion : 1 000 caractères par message, 5 secondes minimum entre deux messages d'un même membre ; fermée une fois l'échange terminé.

**Admin → Stickers → Échanges** : liste filtrable par statut, recherche par pseudo ou numéro, détail (déroulement, contenu, réservations, historique), discussion avec masquage / réaffichage des messages.

## Étape 3 (v0.3.0) — suggestions automatiques

En tête de **Mon compte → Stickers → Propositions** : les membres avec qui l'échange profite aux deux (j'ai un double qui lui manque **et** il a un double qui me manque).
- Calcul à la demande, à l'affichage de la page ; 20 suggestions au maximum, classées par nombre d'échanges un pour un possibles. Symétrique : si A voit B, B voit A.
- Ne tiennent compte que des doubles disponibles (hors exemplaires réservés) et des stickers actifs ; les membres sans adresse et ceux avec qui une proposition est déjà en attente sont exclus.
- « Préparer cette proposition » ouvre la fiche du membre avec un échange équilibré pré-rempli (un exemplaire de chaque numéro, autant de chaque côté), modifiable avant envoi. L'origine « suggestion » est enregistrée et visible dans l'admin.
- Aucun email de suggestion.
- Mesure locale avec 1 000 membres × 100 stickers et 300 propositions en attente : ~35 ms pour le calcul des suggestions, ~30 ms pour la bourse.

## Règles de stock

Doubles = exemplaires − 1. Manquants = numéros à 0. Un membre ne peut pas descendre sous 1 + exemplaires promis dans un échange en cours (réservations). Les modifications d'inventaire se font en transaction avec verrouillage des lignes du membre.

## Mises à jour

Comme le plugin Abonnements : WordPress interroge les Releases du dépôt privé `lucasbauer67-sys/stickers` (token dans les Réglages).
Pour livrer : changer `Version:` dans l'en-tête **et** `STK_VERSION`, pousser sur `main`, puis créer une Release GitHub avec le tag `vX.Y.Z` identique. Créer la Release = déclencher la mise à jour sur les sites.

## Désinstallation

Supprimer le plugin ne supprime les données que si la case « Supprimer toutes les données de la bourse » est cochée dans les Réglages.

## Feuille de route

- Étape 4 : emails, rappels et expiration (Action Scheduler), litiges et arbitrage, RGPD (export / effacement), purge facultative.
