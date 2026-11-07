# PharmaPOS Mauritanie - Product Requirements Document (PRD)

**Version :** 1.1  
**Date :** 31 octobre 2026  
**Statut :** Spécification de référence à valider avec des pharmaciens et un conseil juridique local  
**Marché initial :** petites pharmacies indépendantes en Mauritanie  
**Architecture :** hybride, serveur local prioritaire + synchronisation cloud  
**Stack cible :** Laravel 12, Livewire 3, Tailwind CSS, MySQL, Redis, PWA mobile

> **Exclusions fermes :** aucun OCR Bankily ; aucune gestion CNAM, assurance, tiers payant ou remboursement d'assurance. Bankily et Masrvi peuvent rester de simples modes de paiement déclaratifs, sans lecture automatique de justificatifs ni confirmation bancaire.

## 1. Résumé exécutif

PharmaPOS est une application de gestion d'officine permettant d'effectuer les ventes, les achats, les inventaires et le suivi des stocks de médicaments par lot et date de péremption. Une PWA mobile transforme un smartphone en lecteur de codes-barres pour la caisse PC, les inventaires et les réceptions. Le serveur local garantit la continuité des opérations essentielles sans Internet ; le cloud apporte sauvegardes secondaires, supervision et consultation distante après synchronisation.

Le logiciel soutient les professionnels de pharmacie et ne remplace ni leur jugement ni leurs obligations de délivrance. Les contrôles de conformité devront être validés avant mise en production.

## 2. Objectifs et indicateurs de réussite

### Objectifs
1. Encaisser rapidement et de façon fiable.
2. Connaître le stock vendable par médicament, présentation, lot et emplacement.
3. Empêcher la vente de lots expirés, retirés ou en quarantaine.
4. Réduire les pertes par péremption et les ruptures.
5. Simplifier commandes, réceptions et inventaires.
6. Assurer la continuité des ventes sans Internet.
7. Fournir un audit complet des mouvements et opérations sensibles.
8. Permettre au propriétaire de consulter les indicateurs à distance, avec horodatage de dernière synchronisation.

### Cibles de recette (à mesurer, non garanties)
| Indicateur | Cible |
|---|---|
| Recherche produit sur LAN | p95 < 300 ms |
| Ajout par lecteur USB | p95 < 500 ms |
| Scan caméra dans conditions de test | p95 < 2 s |
| Validation de vente locale hors impression | p95 < 1 s |
| Double comptabilisation après retransmission | 0 |
| Transactions sensibles auditées | 100 % |
| Catalogue testé | au moins 30 000 présentations |
| Caisses simultanées pilote | jusqu'à 5 |

## 3. Périmètre et priorisation

**P0 - Première version pilote :** utilisateurs et permissions ; catalogue médicaments et parapharmacie ; conditionnements ; codes-barres ; caisse ; paiement espèces et mobile déclaratif ; tickets ; sessions de caisse ; achats ; fournisseurs ; réceptions ; lots ; péremptions ; quarantaines ; stocks ; inventaires ; scanner smartphone ; serveur local ; synchronisation cloud ; audit ; sauvegardes ; rapports essentiels.

**P1 - Après validation pilote :** gestion avancée des retours fournisseurs ; transferts entre emplacements ; rapports analytiques avancés ; promotions compatibles avec les règles de prix ; gestion avancée des alertes ; tableaux de bord mobiles ; rapprochement manuel des moyens de paiement à partir de données saisies ; export comptable.

**P2 - Évolutions éventuelles :** multi-officines, référentiel central validé, intégrations de matériels spécialisés, lecture assistée de factures fournisseurs uniquement si décidée dans un projet séparé.

**Hors périmètre :** OCR de captures Bankily ; reconnaissance de justificatifs de paiement ; assurance ; CNAM ; tiers payant ; ordonnances numérisées dans le MVP ; comptabilité générale complète ; diagnostic médical automatisé ; vente en ligne de médicaments ; confirmation bancaire automatique.

## 4. Profils et habilitations

| Profil | Droits principaux |
|---|---|
| Propriétaire | Paramètres commerciaux, finances, supervision |
| Pharmacien responsable | Supervision des opérations pharmaceutiques, blocages et validations |
| Pharmacien adjoint | Opérations autorisées selon son habilitation |
| Préparateur | Opérations autorisées et supervision applicable |
| Caissier | Ventes, paiements, tickets, consultation limitée |
| Magasinier | Réception, rangement, comptages, transferts autorisés |
| Comptable | Rapports, clôtures, export, rapprochements manuels |
| Administrateur technique | Maintenance sans accès aux données sensibles par défaut |

RBAC avec permissions par action, officine et emplacement. MFA recommandé pour profils sensibles ; réauthentification pour annulations, ajustements, changements de prix et opérations de quarantaine. Journal d'audit non modifiable par les utilisateurs ordinaires.

## 5. Référentiel médicaments et produits

### Données
- UUID interne, SKU, codes-barres EAN/UPC/Code128/GTIN selon disponibilité.
- DCI, nom commercial, dosage, forme, voie si nécessaire, conditionnement, laboratoire/fabricant.
- Catégorie, type (médicament/parapharmacie), statut réglementaire, conditions de conservation.
- Unité de stock, unité de vente, conversions autorisées (boîte, plaquette, unité) et règles de fractionnement.
- Prix d'achat, prix de vente, régime tarifaire applicable, seuil de réapprovisionnement, statut actif/suspendu/retiré.

### Exigences
- **MED-01 P0** : CRUD sécurisé avec archivage plutôt que suppression des produits utilisés.
- **MED-02 P0** : recherche par DCI, nom commercial, code-barres et SKU.
- **MED-03 P0** : plusieurs codes-barres pour une présentation ; unicité des codes actifs dans un catalogue.
- **MED-04 P0** : gestion explicite des unités et conversions, sans perte d'arrondi.
- **MED-05 P0** : import Excel/CSV avec prévisualisation, erreurs par ligne et détection de doublons.
- **MED-06 P0** : historique des prix et contrôle des modifications.
- **MED-07 P0** : séparation médicament/parapharmacie et contrôles adaptés.
- **MED-08 P1** : photos et recherche avancée.

Les codes-barres sont des chaînes, jamais des entiers, pour préserver les zéros initiaux. Un code-barres de présentation n'identifie pas nécessairement le lot.

## 6. Lots, péremptions et traçabilité

### Modèle
Un produit peut posséder plusieurs lots avec numéros, dates de péremption, quantités, coûts d'entrée, fournisseur, date de réception, emplacement et statut. Les statuts incluent : disponible, réservé, quarantaine, rappelé, expiré, détruit et retourné.

### Exigences
- **LOT-01 P0** : créer les lots à la réception ; vérifier numéro et péremption.
- **LOT-02 P0** : stock par produit/lot/emplacement.
- **LOT-03 P0** : proposer FEFO (First Expired, First Out) à la vente.
- **LOT-04 P0** : bloquer la vente des lots expirés, rappelés ou en quarantaine côté serveur.
- **LOT-05 P0** : alertes configurables à 30, 60, 90 et 180 jours.
- **LOT-06 P0** : journal complet des mouvements par lot.
- **LOT-07 P0** : mise en quarantaine, rappel, sortie pour destruction avec motif et autorisation.
- **LOT-08 P0** : rapport quantités et valeur des lots proches de péremption.
- **LOT-09 P1** : workflow de retour fournisseur.

La date de péremption exprimée en mois/année doit suivre une règle métier explicite, validée avec le pharmacien et le cadre applicable. FEFO détermine une proposition de sortie physique, pas la méthode comptable de valorisation.

## 7. Caisse et ventes

### Parcours
1. Ouvrir une session de caisse avec fonds initial.
2. Rechercher ou scanner un produit.
3. Afficher prix, disponibilité, restrictions et lots autorisés.
4. Proposer un lot FEFO, permettre sa confirmation ou modification autorisée.
5. Modifier quantité et, si permis, remise.
6. Choisir un ou plusieurs modes de paiement.
7. Valider atomiquement vente, affectation des lots, sorties de stock et paiements.
8. Imprimer ticket ou générer une copie numérique.

### Exigences
- **POS-01 P0** : interface optimisée clavier et tactile.
- **POS-02 P0** : scan USB/Bluetooth et smartphone.
- **POS-03 P0** : recherche DCI, nom, code, SKU.
- **POS-04 P0** : affectation de lot et contrôles de délivrance.
- **POS-05 P0** : ventes en attente et reprise contrôlée.
- **POS-06 P0** : espèces, Bankily, Masrvi et autres moyens déclaratifs configurables.
- **POS-07 P0** : paiement fractionné, rendu de monnaie, référence manuelle facultative.
- **POS-08 P0** : ticket 58/80 mm selon imprimante compatible.
- **POS-09 P0** : retours, remboursements, avoirs et annulations traçables.
- **POS-10 P0** : ouverture/clôture, écarts de caisse et validation.
- **POS-11 P0** : idempotence et transactions atomiques.
- **POS-12 P0** : aucune suppression physique d'une vente finalisée.

Les remises et tarifs doivent respecter les dispositions applicables. Une vente finalisée génère des événements compensatoires en cas de correction. Le retour d'un médicament ne réintègre pas automatiquement le stock vendable.

## 8. Smartphone comme scanner de codes-barres

### Modes
**A - Scanner déporté de caisse :** le smartphone est appairé à une session PC et transmet les scans en temps réel via le réseau local.  
**B - Inventaire :** scan, identification du lot, comptage, stockage local temporaire et soumission.  
**C - Réception :** scan, saisie/confirmation du lot, péremption et quantité reçue.  
**D - Consultation :** recherche prix, stock, emplacement et statut.

### Appairage
1. Le PC génère un QR d'appairage temporaire à usage unique.
2. Le téléphone le lit et authentifie son utilisateur.
3. Le serveur local vérifie permissions, pharmacie et caisse.
4. Le caissier confirme l'association.
5. Un canal temps réel authentifié transmet les événements avec accusé de réception.
6. La session peut être révoquée et expire automatiquement.

### Exigences
- **SCN-01 P0** : caméra arrière, flash si disponible, cadrage et retour visuel/sonore.
- **SCN-02 P0** : EAN-13, EAN-8, UPC-A, Code128 ; QR pour appairage.
- **SCN-03 P0** : scan continu avec temporisation contre lectures caméra accidentelles.
- **SCN-04 P0** : scans intentionnels répétés augmentent la quantité.
- **SCN-05 P0** : `scan_event_id` unique, séquence, accusé de réception et déduplication réseau.
- **SCN-06 P0** : saisie manuelle de secours, traitement produit inconnu.
- **SCN-07 P0** : association et fonctionnement sur Wi-Fi local sans Internet.
- **SCN-08 P0** : confirmation du lot si absent du code-barres.
- **SCN-09 P1** : cache IndexedDB pour missions d'inventaire interrompues.

### Contraintes navigateur
La caméra web exige normalement un contexte sécurisé. Sur un téléphone accédant au mini-serveur local, prévoir un nom DNS local et un certificat HTTPS reconnu et testé sur Android/iOS ; `http://192.168.x.x` ne garantit pas l'accès caméra. La bibliothèque de scan doit disposer d'un repli si l'API BarcodeDetector n'est pas disponible.

## 9. Stocks et inventaires

Organisation : **officine → réserve/rayon → emplacement → produit → lot**.

- **STK-01 P0** : solde par produit, lot, emplacement et statut.
- **STK-02 P0** : mouvements d'entrée, sortie, transfert, ajustement, quarantaine et destruction.
- **STK-03 P0** : inventaire complet, partiel et tournant.
- **STK-04 P0** : comptage par smartphone et saisie manuelle.
- **STK-05 P0** : stock théorique figé ou ajusté des mouvements pendant comptage.
- **STK-06 P0** : écarts, recomptage et approbation avant ajustement.
- **STK-07 P0** : seuils de rupture et propositions de réapprovisionnement.
- **STK-08 P0** : stock négatif interdit par défaut.
- **STK-09 P1** : transferts et suivi des emplacements avancés.

Les ajustements créent des mouvements auditables. Valorisation selon méthode comptable explicitement configurée et validée ; FEFO n'est pas une méthode de valorisation.

## 10. Achats et fournisseurs

Workflow : besoin → commande → réception partielle/totale → contrôle lot/péremption → facture fournisseur → dette → paiement → retour/avoir éventuel.

- **ACH-01 P0** : fiches fournisseurs et historique.
- **ACH-02 P0** : commandes avec lignes, quantités et prix.
- **ACH-03 P0** : réception avec scan, lot, péremption et anomalies.
- **ACH-04 P0** : refus ou quarantaine des produits non conformes.
- **ACH-05 P0** : factures et règlements fournisseurs, paiements partiels.
- **ACH-06 P0** : rapprochement commande/réception/facture au niveau des lignes.
- **ACH-07 P1** : retours et avoirs avancés.

## 11. Paiements et sessions de caisse

Modes : espèces, Bankily, Masrvi, carte/virement si activés. Les paiements mobiles sont **déclaratifs**, sans OCR et sans API de confirmation bancaire. Le caissier peut saisir manuellement une référence, mais celle-ci n'est pas une preuve de réception.

Chaque session conserve fonds initial, ventes encaissées par mode, entrées/sorties autorisées, remboursements, fonds comptés, écart, agent et validation. Les clôtures validées sont verrouillées ; les corrections sont de nouvelles écritures.

## 12. Rapports

- Ventes quotidiennes, hebdomadaires et mensuelles ; panier moyen.
- Ventes par produit, catégorie et caissier.
- Stocks disponibles, stock en quarantaine, valorisation et ruptures.
- Péremptions à 30/60/90/180 jours et pertes.
- Achats, fournisseurs, dettes et règlements.
- Inventaires, écarts, ajustements et mouvements par lot.
- Sessions de caisse, écarts, encaissements par mode.
- Journal d'audit et opérations sensibles.

Filtres par période, officine, emplacement, produit, lot et utilisateur selon droits. Export CSV, Excel et PDF. Les tableaux cloud affichent leur dernière date de synchronisation.

## 13. Architecture hybride

### 13.1 Principes
Le **serveur local est l'autorité** pour les ventes, lots, mouvements de stock et sessions de caisse. Le cloud reçoit une réplication logique des événements pour consultation distante, sauvegardes secondaires, supervision et configuration centralisée contrôlée.

```text
[PC caisse]     [Smartphone PWA]     [Imprimante]
     \                |                /
          [LAN / Wi-Fi local]
                   |
         [Serveur PharmaPOS local]
      Laravel + MySQL + Redis + API
                   |
          HTTPS / synchronisation
                   |
             [Cloud PharmaPOS]
      API + base + sauvegardes + dashboard
```

**Sans Internet :** la caisse et les fonctions P0 continuent sur LAN. **Sans serveur local :** la caisse n'est pas automatiquement basculée sur cloud dans le MVP ; prévoir procédure de reprise. **Sans Wi-Fi :** lecteur USB sur PC comme solution de secours.

### 13.2 Dimensionnement indicatif pilote
| Composant | Proposition |
|---|---|
| Mini-PC serveur local | 4 cœurs CPU, 8-16 Go RAM |
| Stockage local | SSD 256 Go minimum |
| Réseau | Routeur LAN/Wi-Fi stable |
| Électricité | Onduleur recommandé |
| Caisse | PC Windows/Linux avec navigateur moderne |
| Mobile | Android/iPhone, navigateur compatible caméra |
| Cloud | VPS Linux dimensionné selon nombre de pharmacies |

Le mini-PC doit disposer de sauvegardes et d'une procédure de remplacement. Docker Compose peut faciliter le déploiement, mais la politique de mises à jour et la supervision restent nécessaires.

### 13.3 Synchronisation
Événements inscrits dans une table **outbox** dans la même transaction que l'opération métier. Envoi par lots, accusé de réception, table **inbox** côté cloud, clé d'idempotence, séquences par agrégat, reprise après interruption. Les mises à jour cloud du référentiel sont versionnées et appliquées localement selon une politique de conflit documentée.

Événement type :
```json
{
  "event_id": "uuid",
  "pharmacy_id": "uuid",
  "aggregate_type": "sale",
  "aggregate_id": "uuid",
  "event_type": "sale.finalized",
  "sequence": 125,
  "occurred_at": "2026-10-31T18:00:00Z",
  "schema_version": 1
}
```

**Invariant :** aucune retransmission ne doit créer une seconde vente, un second paiement ou une seconde sortie de stock.

### 13.4 Sauvegarde et reprise
Sauvegarde locale automatisée, copie chiffrée sur support distinct, copie cloud si connectivité, restauration testée, journal des échecs. Objectifs proposés : RPO 15 minutes et RTO 4 heures, à confirmer par exercices et budget. La réplication cloud ne remplace pas une sauvegarde versionnée.

## 14. Architecture applicative et API

### Stack
- Laravel 12 pour services métier et API REST versionnée.
- Livewire 3 + Tailwind CSS pour administration et caisse ; JavaScript ciblé pour scan et réactivité.
- MySQL local ; MySQL/PostgreSQL cloud selon décision d'exploitation.
- Redis pour files d'attente, cache et événements temps réel.
- PWA avec Service Worker/IndexedDB pour assets et missions mobiles ; le serveur local reste la source de vérité transactionnelle.
- WebSocket authentifié ou équivalent pour scanner PC.
- Impression via navigateur ou agent local selon matériel.

### Routes indicatives
```text
POST /api/v1/scanner-sessions
POST /api/v1/scanner-sessions/{id}/pair
POST /api/v1/scanner-sessions/{id}/events
GET  /api/v1/medicines/lookup
POST /api/v1/sales
POST /api/v1/sales/{id}/payments
POST /api/v1/sales/{id}/returns
POST /api/v1/purchases/receipts
POST /api/v1/inventory-counts
POST /api/v1/inventory-counts/{id}/lines
POST /api/v1/sync/batches
GET  /api/v1/reports/sales
```

Contrôles d'autorisation serveur, validation des entrées, idempotence sur mutations, limites de débit, réponses d'erreurs normalisées.

## 15. Schéma conceptuel de données

| Domaine | Tables proposées |
|---|---|
| Organisation | pharmacies, locations, devices, registers |
| Sécurité | users, roles, permissions, audit_logs |
| Catalogue | medicines, presentations, product_barcodes, units, categories |
| Lots | batches, batch_locations, batch_status_history |
| Stock | stock_movements, stock_balances, stock_reservations |
| Inventaire | inventory_sessions, inventory_lines, inventory_approvals |
| Fournisseurs | suppliers, purchase_orders, goods_receipts, purchase_invoices |
| Vente | sales, sale_items, sale_item_batches, sale_returns |
| Paiement | payments, cash_sessions, cash_movements |
| Synchronisation | outbox_events, inbox_events, sync_checkpoints, sync_errors |
| Paramètres | settings, price_history, alert_rules |

Montants en `DECIMAL`, quantités en type exact adapté à l'unité, identifiants UUID, clés étrangères, index sur pharmacie/produit/lot/date, unicité de transaction et séparation stricte des données d'officines. Transactions ACID sur ventes et stocks. Ne jamais recalculer l'historique en écrasant les écritures finalisées.

## 16. Sécurité et confidentialité

- HTTPS/TLS entre appareils et serveur local, ainsi qu'entre local et cloud.
- Authentification, permissions RBAC, sessions limitées, révocation des appareils.
- Audit de prix, délivrance, lots, retours, annulations, ajustements et permissions.
- Protection CSRF, XSS, injections, accès non autorisés et abus d'API.
- Chiffrement des sauvegardes et secrets hors du code source.
- Journalisation sans exposer de données sensibles inutiles.
- Politique de conservation, purge et restauration documentée.
- Contrôle d'accès aux données de pharmacie ; pas de dossier patient ni d'assurance dans le MVP.

## 17. Conformité réglementaire et garde-fous

Le développement doit intégrer une **phase obligatoire de validation locale** avec pharmacien responsable et juriste. Références de départ : loi mauritanienne n° 2010-022 du 10 février 2010 relative à la pharmacie et modifications ultérieures, dont la loi n° 2025-042 mentionnée dans les communications officielles. Vérifier les textes consolidés et d'application en vigueur avant commercialisation.

Points à valider : catégories de médicaments, prescription, habilitations de délivrance, produits réglementés, tarification, registres obligatoires, conservation, traçabilité, rappels, retours et destruction. Le système ne doit pas présumer qu'une règle technique remplace une obligation professionnelle.

## 18. Exigences non fonctionnelles

- Utilisation simple sur écran 1366×768, tactile et clavier ; français/arabe et RTL.
- Pagination serveur, indexation et recherche performante.
- Journaux techniques, métriques et alertes de synchronisation/sauvegarde.
- Support d'une pharmacie avec 1-5 caisses, plusieurs mobiles et au moins 30 000 présentations testées.
- Accessibilité de base : contrastes, focus clavier, messages d'erreur explicites.
- Migrations versionnées, déploiement reproductible, rollback planifié.
- Test de compatibilité sur imprimantes thermiques et navigateurs Android/iOS réels.

## 19. Scénarios de recette obligatoires

| ID | Scénario | Résultat attendu |
|---|---|---|
| QA-01 | Vente d'un lot valide | Vente et stock cohérents |
| QA-02 | Vente d'un lot expiré | Refus serveur |
| QA-03 | Vente d'un lot en quarantaine | Refus serveur |
| QA-04 | Plusieurs lots | FEFO proposé, lot confirmé |
| QA-05 | Scan mobile vers PC | Ajout unique par événement |
| QA-06 | Scan intentionnel répété | Quantité augmentée |
| QA-07 | Perte Internet | Caisse LAN maintenue |
| QA-08 | Retour Internet | Synchronisation sans doublon |
| QA-09 | Coupure pendant validation | Vente atomique ou reprise contrôlée |
| QA-10 | Inventaire multi-lots | Écarts exacts par lot |
| QA-11 | Réception partielle | Quantités et facture cohérentes |
| QA-12 | Retour médicament | Quarantaine ou traitement autorisé |
| QA-13 | Modification prix | Historique et permissions respectés |
| QA-14 | Clôture caisse | Écart exact et session verrouillée |
| QA-15 | Appareil non autorisé | Appairage refusé |
| QA-16 | QR d'appairage expiré | Appairage refusé |
| QA-17 | Restauration sauvegarde | Cohérence transactions et séquences |
| QA-18 | Utilisateur d'une autre officine | Accès refusé |
| QA-19 | Caméra sur LAN HTTPS | Scan opérationnel Android/iPhone testés |
| QA-20 | Paiement Bankily déclaratif | Saisie manuelle uniquement, aucun OCR |

## 20. Planning indicatif

| Phase | Livrables | Durée estimée |
|---|---|---|
| 0 - Cadrage | Entretiens et conformité | 2-3 semaines |
| 1 - Catalogue/stocks | Produits, lots, péremptions | 4-5 semaines |
| 2 - Caisse | Ventes, paiements, clôtures | 4-5 semaines |
| 3 - Mobile | Scanner PC, réception, inventaire | 3-4 semaines |
| 4 - Hybride | Serveur local, cloud, synchronisation, reprise | 4-6 semaines |
| 5 - Pilote | Tests terrain, formation, corrections | 4-6 semaines |

**Total indicatif : 21-29 semaines** pour une petite équipe expérimentée, selon réutilisation de composants et validation métier. Ce n'est pas un devis.

## 21. Livrables du projet

1. Code source versionné et documentation d'architecture.
2. Application web locale et dashboard cloud.
3. PWA de scan et d'inventaire.
4. Schéma SQL, migrations, index et jeux de données de démonstration.
5. Documentation API et protocole de synchronisation.
6. Tests automatisés et scénarios de recette terrain.
7. Scripts de déploiement, mise à jour, sauvegarde et restauration.
8. Guide utilisateur (pharmacien, caissier, magasinier).
9. Matrice des permissions et registre des contrôles réglementaires.
10. Procès-verbal de recette du pilote.

## 22. Risques et réponses

| Risque | Réponse |
|---|---|
| Mauvaise identification de lot | Confirmation de lot, scan 2D si disponible |
| Médicament expiré vendu | Contrôle bloquant côté serveur |
| Conflit stock/vente | Transaction ACID, journal des mouvements |
| Panne mini-PC | Sauvegarde, onduleur, procédure de reprise |
| Wi-Fi indisponible | Lecteur USB sur PC et LAN câblé |
| Perte Internet | Autorité transactionnelle locale |
| Double synchronisation | Idempotence, séquences, inbox/outbox |
| Écart réglementaire | Validation par pharmacien et juriste |
| Migration erronée | Import de test, inventaire initial, contrôle des écarts |
| Adoption difficile | Formation courte, interface rapide, support local |

## 23. Stratégie pilote et lancement

1. Entretiens avec 10-15 petites pharmacies indépendantes.
2. Démonstration sur données fictives et validation des parcours réels.
3. Installation pilote dans une pharmacie volontaire, avec plan de retour arrière.
4. Formation du pharmacien, caissier et magasinier.
5. Mesure des temps d'encaissement, écarts de stock, incidents, alertes et besoins de support sur 30 jours.
6. Validation métier et technique avant commercialisation élargie.

Modèle commercial à tester : frais d'installation et de migration + abonnement de support/synchronisation. Les prix ne sont pas fixés dans ce PRD et doivent être validés sur le marché.

## 24. Décisions finales

| Sujet | Décision |
|---|---|
| Cible | Petites pharmacies indépendantes mauritaniennes |
| Architecture | Hybride, local prioritaire |
| Stack | Laravel 12, Livewire 3, Tailwind, MySQL, Redis |
| Scanner smartphone | Oui, appairé au PC ou autonome pour inventaire |
| Gestion des lots et péremptions | Obligatoire dès le MVP |
| Caisse et achats/stocks | Obligatoires dès le MVP |
| Paiements Bankily/Masrvi | Simples modes déclaratifs |
| OCR Bankily | **Exclu** |
| CNAM/assurances/tiers payant | **Exclus** |
| Cloud | Supervision et synchronisation, pas autorité primaire des ventes |
| Délivrance pharmaceutique | Sous contrôle des professionnels habilités |

## 25. Références techniques et réglementaires à vérifier pendant le cadrage

- Loi n° 2010-022 du 10 février 2010 relative à la pharmacie : https://extranet.who.int/cpcd/sites/default/files/public_file_repository/MRT_Mauritania_Loi_No-2010-022-du-10-fevrier-2010-relative-a-la-pharmacie_2010.pdf
- Agence mauritanienne d'information, actualités législatives : https://ami.mr/
- Documentation Laravel : https://laravel.com/docs
- Documentation Livewire : https://livewire.laravel.com/docs
- MDN, accès caméra : https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia
- MDN, Barcode Detection API : https://developer.mozilla.org/en-US/docs/Web/API/Barcode_Detection_API

*Les fonctionnalités, métriques, configurations et délais de ce document sont des propositions de conception. La conformité réglementaire et les performances doivent être vérifiées avant le déploiement.*
