#!/bin/sh
set -eu

DATA_ROOT="/data"
mkdir -p "$DATA_ROOT/storage" "$DATA_ROOT/uploads"

# Seed only missing files. Never overwrite existing user data.
[ -f "$DATA_ROOT/users.json" ] || cp /opt/united-seed/users.json "$DATA_ROOT/users.json"
[ -f "$DATA_ROOT/posts.json" ] || cp /opt/united-seed/posts.json "$DATA_ROOT/posts.json"
if [ ! -f "$DATA_ROOT/storage/settings.json" ]; then
  cp -a /opt/united-seed/storage/. "$DATA_ROOT/storage/"
fi
if [ -z "$(find "$DATA_ROOT/uploads" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  cp -a /opt/united-seed/uploads/. "$DATA_ROOT/uploads/" 2>/dev/null || true
fi

# Point the application at persistent Fly.io storage while preserving the original layout.
rm -f /var/www/html/users.json /var/www/html/posts.json
rm -rf /var/www/html/storage /var/www/html/uploads
ln -s "$DATA_ROOT/users.json" /var/www/html/users.json
ln -s "$DATA_ROOT/posts.json" /var/www/html/posts.json
ln -s "$DATA_ROOT/storage" /var/www/html/storage
ln -s "$DATA_ROOT/uploads" /var/www/html/uploads

chown -R www-data:www-data "$DATA_ROOT" /var/www/html
exec apache2-foreground
