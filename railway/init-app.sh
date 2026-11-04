#!/bin/sh
# Commande de pré-déploiement Railway (Settings > Deploy > Pre-Deploy Command) :
#   sh ./railway/init-app.sh
# Exécutée avant chaque mise en ligne. Si une étape échoue, le déploiement
# est annulé et l'ancienne version reste en ligne.
set -e

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
