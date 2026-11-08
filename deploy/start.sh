#!/bin/sh
set -eu
app_port="${PORT:-10000}"
case "$app_port" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
if [ "$app_port" -lt 1024 ] || [ "$app_port" -gt 65535 ]; then echo 'PORT must be 1024-65535' >&2; exit 1; fi
sed -i "s/^Listen .*/Listen ${app_port}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${app_port}>/" /etc/apache2/sites-available/000-default.conf
mkdir -p /var/www/html/runtime/cache /var/www/html/runtime/logs /var/www/html/runtime/session
chown -R www-data:www-data /var/www/html/runtime
exec apache2-foreground
