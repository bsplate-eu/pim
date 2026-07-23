#!/bin/bash
# Auto-deploy PIM — wzorzec wdroz-XX.sh ze sklepow bsp (patrz DEPLOY.md w repo bsp-sk).
# Uruchamiany z crona co 5 min; bez zmiany kodu konczy sie natychmiast.
# Kopia robocza: /home/admin/bin/wdroz-pim.sh (kopiowana z repo przy instalacji).

WEB=/home/admin/domains/pim.bsplate.eu/PIM
PHP=/usr/local/php83/bin/php

cd "$WEB" || exit 1
OLD=$(/usr/bin/git rev-parse HEAD 2>/dev/null) || exit 1
/usr/bin/git pull --quiet --ff-only || exit 1
NEW=$(/usr/bin/git rev-parse HEAD)
[ "$OLD" = "$NEW" ] && exit 0

echo "[$(date '+%F %T')] deploy $OLD -> $NEW"

# Kontrola skladni zmienionych PHP — parse error na prodzie klad kiedys CALA strone
# (route cache maskuje bledy do pierwszego optimize:clear), wiec: blad => rollback.
BAD=0
for f in $(/usr/bin/git diff --name-only "$OLD" "$NEW" -- '*.php'); do
    [ -f "$f" ] || continue
    if ! $PHP -l "$f" >/dev/null 2>&1; then
        echo "PARSE ERROR: $f"
        BAD=1
    fi
done
if [ "$BAD" = "1" ]; then
    echo "Wycofuje deploy, wracam do $OLD"
    /usr/bin/git reset --hard "$OLD" --quiet
    exit 1
fi

$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "[$(date '+%F %T')] deploy OK ($NEW)"
