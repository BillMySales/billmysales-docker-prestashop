<?php
/**
 * Applies the stack's environment to an installed PrestaShop. Run by setup.sh
 * (as www-data) on every `docker compose up`.
 *
 * - Shop domain and SSL always follow PS_URL (like WP_URL in WordPress).
 * - SMTP settings follow SMTP_* when SMTP_HOST is set.
 * - Initial settings are applied once (marker DOCKER_STACK_INITIALIZED).
 *
 * Exit code: 0 = nothing changed, 3 = something changed (clear the cache).
 */

$url = parse_url((string) getenv('PS_URL'));
if (empty($url['scheme']) || empty($url['host'])) {
    fwrite(STDERR, "PS_URL must be an absolute URL, e.g. https://shop.example.com\n");
    exit(1);
}
$domain = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
$ssl = $url['scheme'] === 'https' ? 1 : 0;

// Bootstrap PrestaShop as a front office request to that domain.
$_SERVER['HTTP_HOST'] = $domain;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/config/config.inc.php';

$changed = false;
$set = static function (string $key, $value) use (&$changed): void {
    if ((string) Configuration::get($key) !== (string) $value) {
        Configuration::updateValue($key, $value);
        echo "    {$key} updated\n";
        $changed = true;
    }
};

// Domain and SSL.
$set('PS_SHOP_DOMAIN', $domain);
$set('PS_SHOP_DOMAIN_SSL', $domain);
$set('PS_SSL_ENABLED', $ssl);
$set('PS_SSL_ENABLED_EVERYWHERE', $ssl);
$shopUrlId = (int) Db::getInstance()->getValue(
    'SELECT id_shop_url FROM ' . _DB_PREFIX_ . 'shop_url WHERE main = 1 AND id_shop = '
    . (int) Configuration::get('PS_SHOP_DEFAULT')
);
$shopUrl = new ShopUrl($shopUrlId);
if ($shopUrl->domain !== $domain || $shopUrl->domain_ssl !== $domain) {
    $shopUrl->domain = $domain;
    $shopUrl->domain_ssl = $domain;
    $shopUrl->save();
    echo "    shop URL updated to {$domain}\n";
    $changed = true;
}

// SMTP. SMTP_SECURE: tls (STARTTLS), ssl (implicit TLS), none or empty.
if (getenv('SMTP_HOST')) {
    $secure = strtolower((string) getenv('SMTP_SECURE'));
    $set('PS_MAIL_METHOD', 2);
    $set('PS_MAIL_SERVER', getenv('SMTP_HOST'));
    $set('PS_MAIL_SMTP_PORT', getenv('SMTP_PORT') ?: 587);
    $set('PS_MAIL_SMTP_ENCRYPTION', in_array($secure, ['tls', 'ssl'], true) ? $secure : 'off');
    $set('PS_MAIL_USER', (string) getenv('SMTP_USER'));
    $set('PS_MAIL_PASSWD', (string) getenv('SMTP_PASSWORD'));
    if (getenv('SMTP_FROM')) {
        $set('PS_SHOP_EMAIL', getenv('SMTP_FROM'));
    }
}

// Initial settings, once. Later changes in the back office are kept.
if (!Configuration::get('DOCKER_STACK_INITIALIZED')) {
    echo "==> Initial settings\n";
    $set('PS_REWRITING_SETTINGS', 1);
    $set('DOCKER_STACK_INITIALIZED', gmdate('Y-m-d\TH:i:s\Z'));
}

exit($changed ? 3 : 0);
