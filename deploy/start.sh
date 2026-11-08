
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
sed -i "s/^Listen .*/Listen ${app_port}/" \
  /etc/apache2/ports.conf

sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${app_port}>/" \
  /etc/apache2/sites-available/000-default.conf

# Create Aiven MySQL CA certificate from Railway variable
if [ -n "${DB_SSL_CA_PEM:-}" ]; then
  mkdir -p /etc/dormfind

  printf '%s\n' "$DB_SSL_CA_PEM" > /etc/dormfind/ca.pem

  chmod 644 /etc/dormfind/ca.pem

  export DB_SSL_CA=/etc/dormfind/ca.pem
fi

# Create Firebase service account JSON securely
if [ -n "${FIREBASE_SERVICE_ACCOUNT_JSON:-}" ]; then
  firebase_path="/tmp/dormfind-firebase.json"

  umask 027

  printf '%s' "$FIREBASE_SERVICE_ACCOUNT_JSON" > "$firebase_path"

  # Validate JSON without displaying private credentials
  if ! php -r '
    $path = $argv[1];
    $data = json_decode(file_get_contents($path), true);

    if (!is_array($data) ||
        ($data["type"] ?? "") !== "service_account" ||
        empty($data["client_email"]) ||
        empty($data["private_key"])) {
        exit(1);
    }
  ' "$firebase_path"; then
    echo "Invalid Firebase service account JSON." >&2
    exit 1
  fi

  # Allow Apache/PHP to read the file securely
  chown root:www-data "$firebase_path"
  chmod 640 "$firebase_path"

  export FIREBASE_SERVICE_ACCOUNT="$firebase_path"
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
