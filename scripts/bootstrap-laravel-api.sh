#!/bin/bash
# scripts/bootstrap-laravel-api.sh
#
# WHY THIS SCRIPT EXISTS (read this before running it):
#
# This repository intentionally commits only the application code we
# actually wrote for the Research API: app/Http/Controllers,
# app/Http/Middleware, app/Models, database/migrations,
# database/factories, routes/api.php, tests/, composer.json,
# phpunit.xml, and .env.example.
#
# It does NOT commit the standard Laravel 12 skeleton files
# (bootstrap/app.php, bootstrap/providers.php, public/index.php,
# artisan, config/*.php, routes/console.php) — those are framework
# boilerplate that `composer create-project laravel/laravel` generates
# from the actual, currently-published laravel/laravel package. This
# script generates them for real, from the real package, instead of
# us hand-typing framework internals from memory into a Git repo
# where they'd quietly drift out of date with actual Laravel releases.
#
# bootstrap/cache/ is deliberately excluded from the copy too: the
# temp project below installs Laravel's FULL stock skeleton (including
# dev-only packages like laravel/pail), and its package-discovery
# cache (bootstrap/cache/packages.php) would otherwise get copied in
# referencing packages that were never installed into OUR
# composer.json — causing a "Class ... not found" error the first
# time Artisan boots. composer.json's post-autoload-dump hook (below)
# regenerates this cache correctly for our actual dependencies instead.
#
# Run this once, from the laravel-api/ directory, before your first
# `docker build` / `docker compose up`.
set -euo pipefail

cd "$(dirname "$0")/../laravel-api"

if [ -f "artisan" ]; then
  echo "artisan already exists — skeleton looks like it's already bootstrapped. Exiting."
  exit 0
fi

TMP_DIR="$(mktemp -d)"
echo "Scaffolding a fresh Laravel 12 project into ${TMP_DIR}..."
composer create-project laravel/laravel:^12.0 "${TMP_DIR}" --prefer-dist --no-interaction

echo "Copying framework skeleton files into laravel-api/ (without touching our own app/database/routes/tests/composer.json)..."
rsync -a "${TMP_DIR}/" ./ \
  --exclude 'app/' \
  --exclude 'database/migrations/' \
  --exclude 'database/factories/' \
  --exclude 'routes/api.php' \
  --exclude 'tests/' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude '.env.example' \
  --exclude 'phpunit.xml' \
  --exclude 'bootstrap/cache/' \
  --exclude '.git/'

rm -rf "${TMP_DIR}"

echo "Registering our middleware and API routes in bootstrap/app.php..."
php -r '
$path = "bootstrap/app.php";
$content = file_get_contents($path);

if (strpos($content, "TrackRequestMetrics") === false) {
    $content = str_replace(
        "use Illuminate\\Foundation\\Configuration\\Middleware;",
        "use Illuminate\\Foundation\\Configuration\\Middleware;\nuse App\\Http\\Middleware\\TrackRequestMetrics;",
        $content
    );
    $content = str_replace(
        "->withRouting(",
        "->withRouting(\n        api: __DIR__.\"/../routes/api.php\",",
        $content
    );
    $content = str_replace(
        "function (Middleware \$middleware) {",
        "function (Middleware \$middleware) {\n        \$middleware->api(append: [TrackRequestMetrics::class]);",
        $content
    );
    file_put_contents($path, $content);
    echo "bootstrap/app.php updated.\n";
} else {
    echo "bootstrap/app.php already wired up — skipping.\n";
}
'

echo "Done. Run 'composer install' then 'php artisan migrate' (or let docker-entrypoint.sh do it) next."
