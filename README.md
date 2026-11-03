# pharmapos

Logiciel de gestion d'officine pour petites pharmacies indépendantes en Mauritanie : caisse, lots et péremptions (FEFO), stocks, inventaires, achats, scanner smartphone. Architecture hybride, serveur local prioritaire.

Spécification : [PharmaPOS_PRD_Mauritanie_v1.2.md](PharmaPOS_PRD_Mauritanie_v1.2.md).

## Stack

Laravel 12 · Livewire 3 · Tailwind CSS 4 · MySQL 8 (requis : les migrations utilisent des colonnes générées MySQL)

## Installation

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# Renseigner DB_CONNECTION=mysql et DB_DATABASE=pharmapos dans .env
php artisan migrate:fresh --seed
php artisan serve
```

Le seeder crée une officine fictive, un compte de démonstration par profil (identifiant = slug du rôle, voir `database/seeders/PharmacySeeder.php`) et un catalogue de test couvrant les scénarios FEFO, lot expiré et lot en quarantaine. Ne jamais lancer le seeder de démonstration en production.

## Tests

Les tests utilisent une base MySQL séparée `pharmapos_test` (voir `phpunit.xml`) :

```bash
php artisan test
```

## État d'avancement

- [x] Schéma de données (58 tables), modèles, seeders
- [x] Service de vente : validation atomique, FEFO, verrous multi-caisses, idempotence, numérotation des tickets
- [x] Écran de caisse et ticket 58/80 mm
- [x] Clôture de caisse, écarts, validation
- [x] Ventes en attente (réservation de stock, reprise, expiration)
- [x] Retours, avoirs et remboursements (quarantaine par défaut)
- [x] Réceptions fournisseurs (lots, péremptions, quarantaine, coût par lot)
- [x] Inventaires par lot (comptage à l'aveugle, recomptage, approbation)
- [x] Catalogue : produits, présentations, codes-barres, statuts, historique des prix, fournisseurs
- [x] Import catalogue Excel/CSV avec aperçu (MED-05)
- [x] Alertes : péremption par paliers, stock bas, écarts de caisse
- [x] Commandes fournisseurs, réceptions partielles et reliquats (ACH-02)
- [x] Factures et règlements fournisseurs, rapprochement (ACH-05, ACH-06)
- [x] Réauthentification des opérations sensibles (remise, hors FEFO, remboursement, prix, mouvements et validation de caisse, approbation d'inventaire)
- [x] Scanner smartphone de caisse (appairage QR, accusés, déduplication)
- [x] Scanner smartphone : modes inventaire et réception
- [ ] Scanner smartphone : mode consultation (prix, stock)
- [x] Rapports essentiels : ventes, stock, péremptions et pertes, caisse ; export CSV ; tableau de bord (indicateurs comparés, graphiques)
- [x] Sauvegardes locales chiffrées (AES-256-GCM) toutes les 15 min, copie sur support distinct, rétention, test de restauration quotidien, alerte d'échec — configurer `BACKUP_KEY` (`php artisan pharmapos:backup --generate-key`), `BACKUP_SECONDARY_PATH` et lancer `php artisan schedule:work`
- [x] Paramétrage des modes de paiement (ajout, ordre, activation, suppression si jamais utilisé)
- [x] Assistant de premier démarrage (/installation) et gestion des utilisateurs (mot de passe provisoire, changement obligatoire, désactivation, garde-fous propriétaire)
- [x] Paramétrage des caisses et des emplacements de stock (code auto, imprimante 58/80 mm, garde-fous stock et sessions)
- [x] SaaS : console super-administrateur (/admin) — création des pharmacies, abonnement et échéance, suspension, réinitialisation du propriétaire ; connexion par code pharmacie + identifiant ; premier démarrage = compte super-administrateur (ou `php artisan pharmapos:super-admin <identifiant>`)
- [x] Lecture des bordereaux de livraison (photos / PDF multi-pages) par l'API Claude : tableau à contrôler (reconnu / à vérifier / inconnu, alertes prix et péremption, total), ajout aux lignes de réception, mémorisation des désignations par fournisseur — clé API et modèle dans la console admin → Paramètres (chiffrés ; `.env` en secours)
- [x] Création rapide de produit en un écran (produit + présentation + prix + code-barres, options repliées), depuis le catalogue ou depuis une ligne inconnue d'un bordereau (pré-remplie : nom, dosage, forme, conditionnement, prix) ; coefficient de marge par défaut (médicaments / parapharmacie) pour proposer le prix de vente
- [ ] Synchronisation cloud
