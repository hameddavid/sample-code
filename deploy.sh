#!/bin/bash
# Automated deploy script — triggered by GitHub webhook via cron
# Designed for cPanel shared hosting with CloudLinux/LSAPI

REPO="/home/user/public_html/app"
TRIGGER="$REPO/storage/.deploy-trigger"
PHP="/opt/cpanel/ea-php83/root/usr/bin/php"

if [ ! -f "$TRIGGER" ]; then
    exit 0
fi

cd "$REPO" || exit 1
rm -f "$TRIGGER"

# Back up cPanel-managed .htaccess blocks before git overwrites them
HTACCESS="$REPO/public/.htaccess"
HTBAK="$REPO/storage/.htaccess-cpanel-bak"
if [ -f "$HTACCESS" ]; then
    sed -n '/# php -- BEGIN/,/# php -- END/p' "$HTACCESS" > "$HTBAK" 2>/dev/null
    sed -n '/# BEGIN cPanel-generated php ini/,/# END cPanel-generated php ini/p' "$HTACCESS" >> "$HTBAK" 2>/dev/null
fi

git fetch origin 2>&1
git reset --hard origin/main 2>&1

# Re-append cPanel-managed blocks that git removed
if [ -s "$HTBAK" ]; then
    echo "" >> "$HTACCESS"
    cat "$HTBAK" >> "$HTACCESS"
    rm -f "$HTBAK"
fi

# Remove group-write bits — cPanel/CloudLinux rejects group-writable PHP files
find . -type f -perm /g+w -exec chmod g-w {} +
find . -type d -perm /g+w -exec chmod g-w {} +

chmod -R 755 storage bootstrap/cache
chmod +x artisan deploy.sh

$PHP artisan config:cache 2>&1
$PHP artisan route:cache 2>&1
$PHP artisan view:cache 2>&1

echo "$(date '+%Y-%m-%d %H:%M:%S') | Deployed $(git rev-parse --short HEAD)" >> "$REPO/storage/logs/deploy.log"
