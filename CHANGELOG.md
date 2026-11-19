# Changelog

## 1.2.0

- Interrupteur « Ouvrir / Fermer la bourse » (Catalogue et Réglages), fermée par défaut : onglet masqué aux clients, aperçu pour les administrateurs, page des CGU privée, tâche horaire suspendue.

## 1.1.0

- Menu de l'onglet Stickers sur une ligne (libellés raccourcis).
- Profil : explication du fonctionnement et case d'acceptation obligatoire, valant acceptation des CGU ; versionnage des règles et nouvelle acceptation à la demande.
- Page « Conditions générales d'utilisation de la bourse aux stickers » créée automatiquement.
- Filigrane « SPÉCIMEN » sur toutes les images de stickers montrées aux membres (copies réduites générées côté serveur).

## 1.0.0 — étape 4

- Emails transactionnels en texte brut (14 modèles), objets et textes modifiables dans les réglages.
- Tâche horaire Action Scheduler : expiration des propositions, rappels paramétrables (proposition, expédition, réception), purge facultative des inactifs.
- Litiges : signalement par les membres, page admin Litiges, notes internes, arbitrage « clôturer comme réussi » ou « annuler ».
- RGPD : export, effacement, suppression de compte, texte de politique de confidentialité.
- Base v3 (colonnes rappels et litiges, migration automatique).

## 0.3.0 — étape 3

- Suggestions automatiques (correspondances mutuelles) en tête de l'onglet Propositions : 20 maximum, classées par nombre d'échanges un pour un.
- Proposition pré-remplie et équilibrée depuis une suggestion ; origine « suggestion » enregistrée et affichée dans l'admin.

## 0.2.0 — étape 2

- Tables `wp_stk_exchanges`, `wp_stk_exchange_items`, `wp_stk_exchange_log`, `wp_stk_messages` (migration automatique depuis 0.1.0).
- Bourse (membres classés par intérêt mutuel, filtre par numéro), fiche membre et formulaire de proposition.
- Propositions : accepter, refuser, annuler, contre-proposer ; une seule proposition en attente par paire de membres.
- Échanges : « J'ai expédié » puis « Reçu », clôture et mise à jour des inventaires ; annulation d'un commun accord avant expédition.
- Réservations des doubles engagés, transactions et verrous contre les actions simultanées.
- Fil de discussion par échange ; modération (masquer / réafficher) dans l'admin.
- Admin Échanges : liste, filtres, détail, historique.

## 0.1.0 — étape 1

- Socle du plugin (WP 7.1 / PHP 8.3 / WooCommerce 11.2, compatibilité HPOS déclarée).
- Tables `wp_stk_stickers`, `wp_stk_members`, `wp_stk_inventory` ; migrations via `stk_db_version`.
- Catalogue admin : ajout, modification, image, ordre, activation, suppression si inutilisé.
- Onglet Mon compte « Stickers » : inventaire en grille cliquable (0 à 9), pseudo, adresse de la bourse (FR, BE, CH).
- Admin Membres : liste, recherche, fiche, modification du pseudo.
- Réglages : nom de la collection, token GitHub, suppression des données à la désinstallation.
- Mise à jour automatique via les Releases GitHub.
