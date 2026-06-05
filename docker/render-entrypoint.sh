#!/bin/sh
set -eu

echo "Container initializing..."

PORT="${PORT:-10000}"

# 1. Bind Apache to the dynamic port exposed by Render
cat >/etc/apache2/ports.conf <<EOF
Listen ${PORT}
EOF

cat >/etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
EOF

# 2. Enforce explicit directory partition safety limits
mkdir -p /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 3. Clear application layout config paths
php /var/www/html/artisan config:clear
php /var/www/html/artisan route:clear

# 4. CRITICAL: Execute production database migrations inline on boot!
echo "Executing production database schema migrations..."
php /var/www/html/artisan migrate --force

# 5. Securely generate public symbolic storage linkages
if [ ! -L /var/www/html/public/storage ]; then
    echo "Linking storage partitions..."
    php /var/www/html/artisan storage:link || true
fi

# 6. Pass runtime controls directly back to Apache foreground threads
echo "Booting web server instance..."
exec "$@"