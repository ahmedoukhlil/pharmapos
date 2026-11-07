#!/usr/bin/env bash
# Mise à jour du serveur depuis GitHub (lancé par .github/workflows/deploy.yml à chaque push sur main).
# Le serveur suit main à l'identique : .env, storage/ et la base ne sont pas touchés.
set -euo pipefail
cd "$(dirname "$0")"

git fetch --quiet origin main
git reset --hard origin/main

composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund
npm run build

php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart || true

# Vide la mémoire de PHP (OPcache) : sinon PHP peut servir les anciennes routes et vues
for svc in php8.3-fpm php8.4-fpm php-fpm; do
    if systemctl list-units --type=service --all 2>/dev/null | grep -q "$svc"; then
        sudo -n systemctl reload "$svc" 2>/dev/null || systemctl reload "$svc" 2>/dev/null || echo "Rechargez $svc à la main"
        break
    fi
done

echo "Déployé : $(git log --oneline -1)"
