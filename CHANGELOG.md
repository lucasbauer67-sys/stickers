# Changelog

## 0.1.0 — étape 1

- Socle du plugin (WP 7.1 / PHP 8.3 / WooCommerce 11.2, compatibilité HPOS déclarée).
- Tables `wp_stk_stickers`, `wp_stk_members`, `wp_stk_inventory` ; migrations via `stk_db_version`.
- Catalogue admin : ajout, modification, image, ordre, activation, suppression si inutilisé.
- Onglet Mon compte « Stickers » : inventaire en grille cliquable (0 à 9), pseudo, adresse de la bourse (FR, BE, CH).
- Admin Membres : liste, recherche, fiche, modification du pseudo.
- Réglages : nom de la collection, token GitHub, suppression des données à la désinstallation.
- Mise à jour automatique via les Releases GitHub.
