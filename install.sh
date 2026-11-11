#!/usr/bin/env bash
#
# Sets up the project on a fresh clone.
#
# The repository holds only this project's own files. The first time it runs,
# this script pulls the standard Laravel skeleton and adds every skeleton file
# that is not already here, so nothing in this repo is overwritten.

set -euo pipefail
cd "$(dirname "$0")"

command -v php >/dev/null || { echo "PHP 8.2+ is required."; exit 1; }
command -v composer >/dev/null || { echo "Composer is required: https://getcomposer.org"; exit 1; }

if [ ! -f artisan ]; then
    echo "Downloading the Laravel skeleton..."
    tmp="$(mktemp -d)"
    composer create-project laravel/laravel "$tmp/skeleton" "^12.0" --no-interaction --no-scripts --quiet
    rm -f "$tmp/skeleton/.env" "$tmp/skeleton/database/database.sqlite"
    cp -Rn "$tmp/skeleton/." .
    rm -rf "$tmp"
fi

composer install --no-interaction

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --no-interaction
    secret="$(php -r 'echo bin2hex(random_bytes(32));')"
    php -r '$f=".env"; file_put_contents($f, preg_replace("/^CALL_WEBHOOK_SECRET=.*$/m", "CALL_WEBHOOK_SECRET=".$argv[1], file_get_contents($f)));' "$secret"
fi

touch database/database.sqlite
php artisan migrate --seed --force --no-interaction

echo
echo "Done. Start it with:"
echo "  php artisan serve      # dashboard at http://localhost:8000"
echo "  php artisan queue:work # processes incoming calls (in a second terminal)"
