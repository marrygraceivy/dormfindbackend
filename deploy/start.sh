
#!/bin/sh
set -eu

# Railway port configuration
app_port="${PORT:-10000}"

case "$app_port" in
  ''|*[!0-9]*)
    echo 'Invalid PORT' >&2
    exit 1
    ;;
esac

if [ "$app_port" -lt 1024 ] || [ "$app_port" -gt 65535 ]; then
  echo 'PORT must be 1024-65535' >&2
  exit 1
fi

# Ensure only Apache prefork MPM is enabled
a2dismod -f mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork

# Configure Apache port
sed -i "s/^Listen .*/Listen ${app_port}/" /etc/apache2/ports.conf

sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${app_port}>/" \
  /etc/apache2/sites-available/000-default.conf

# Create Aiven MySQL CA certificate from Railway variable
if [ -n "${DB_SSL_CA_PEM:-}" ]; then
  mkdir -p /etc/dormfind
  printf '%s\n' "$DB_SSL_CA_PEM" > /etc/dormfind/ca.pem
  chmod 600 /etc/dormfind/ca.pem
  export DB_SSL_CA=/etc/dormfind/ca.pem
fi

# Prepare writable runtime directories
mkdir -p \
  /var/www/html/runtime/cache \
  /var/www/html/runtime/logs \
  /var/www/html/runtime/session

chown -R www-data:www-data /var/www/html/runtime

# Validate Apache configuration
apache2ctl configtest

# Start Apache
exec apache2-foreground
