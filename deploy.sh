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

echo "Déployé : $(git log --oneline -1)"
