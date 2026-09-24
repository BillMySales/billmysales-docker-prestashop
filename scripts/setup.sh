#!/bin/sh
# Installs PrestaShop and applies the stack's environment.
#
# Runs as root on every `docker compose up` and is safe to repeat:
# - Copies PrestaShop from the image to the volume if it is empty.
# - Installs the shop (CLI installer, no demo data) if it isn't installed.
# - Renames the back office directory to PS_FOLDER_ADMIN.
# - Applies domain/SSL/SMTP and one-time initial settings (configure.php).
set -eu

APP=/var/www/html
STACK=/usr/local/share/stack
cd "${APP}"

as_www() { su-exec www-data "$@"; }
changed=0

if [ ! -f index.php ]; then
    echo "==> Copying PrestaShop ${PS_VERSION} files"
    cp -a /usr/src/prestashop/. "${APP}/"
fi
# The directory itself may be root-owned (e.g. a new bind mount).
chown www-data:www-data "${APP}"
cp "${STACK}/prestashop/defines_custom.inc.php" config/defines_custom.inc.php
chown www-data:www-data config/defines_custom.inc.php

if [ ! -f app/config/parameters.php ]; then
    domain="$(echo "${PS_URL}" | sed -E 's#^[a-z]+://([^/]+).*#\1#')"
    ssl=0
    case "${PS_URL}" in https://*) ssl=1 ;; esac
    echo "==> Installing PrestaShop at ${PS_URL}"
    as_www php -d memory_limit=-1 install/index_cli.php \
        --domain="${domain}" --ssl="${ssl}" --rewrite=1 \
        --db_server="${DB_HOST}" --db_name="${DB_NAME}" \
        --db_user="${DB_USER}" --db_password="${DB_PASSWORD}" --prefix=ps_ \
        --name="${PS_SHOP_NAME}" --country="${PS_COUNTRY}" \
        --language="${PS_LANGUAGE}" --timezone="${PS_TIMEZONE}" \
        --firstname="${PS_ADMIN_FIRSTNAME}" --lastname="${PS_ADMIN_LASTNAME}" \
        --email="${PS_ADMIN_EMAIL}" --password="${PS_ADMIN_PASSWORD}" \
        --fixtures=0 --newsletter=0 --send_email=0 --all_languages=0
    changed=1
fi
if [ -d install ]; then
    rm -rf install
fi

# Back office directory: the one with PrestaShop's admin bootstrap files.
current_admin=""
for dir in */; do
    dir="${dir%/}"
    if [ -f "${dir}/init.php" ] && [ -d "${dir}/filemanager" ]; then
        current_admin="${dir}"
        break
    fi
done
if [ -n "${current_admin}" ] && [ "${current_admin}" != "${PS_FOLDER_ADMIN}" ]; then
    echo "==> Renaming back office directory ${current_admin} -> ${PS_FOLDER_ADMIN}"
    mv "${current_admin}" "${PS_FOLDER_ADMIN}"
    changed=1
fi

echo "==> Applying environment (domain, SSL, SMTP)"
set +e
as_www php "${STACK}/scripts/configure.php"
status=$?
set -e
case "${status}" in
    0) ;;
    3) changed=1 ;;
    *) exit "${status}" ;;
esac

if [ "${changed}" = 1 ]; then
    echo "==> Clearing cache"
    rm -rf var/cache/*
fi

echo "==> Done: PrestaShop ${PS_VERSION}"
echo "    Store: ${PS_URL}"
echo "    Admin: ${PS_URL%/}/${PS_FOLDER_ADMIN}/ (${PS_ADMIN_EMAIL})"
