<?php

/*
 * Sauvegardes locales chiffrées (PRD §13.4). La clé n'est jamais dans le code :
 * BACKUP_KEY (base64, 32 octets) est générée par `php artisan pharmapos:backup --generate-key`.
 * Sans la clé, les sauvegardes sont illisibles : la conserver hors de la machine.
 */
return [
    'key' => env('BACKUP_KEY'),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    // Support distinct (disque externe, partage réseau) ; vide = pas de copie
    'secondary_path' => env('BACKUP_SECONDARY_PATH'),
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 7),
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 30),
    // Âge maximal de la dernière sauvegarde réussie avant alerte (RPO 15 min + marge)
    'max_age_minutes' => (int) env('BACKUP_MAX_AGE_MINUTES', 60),
    'timeout' => 600,
];
