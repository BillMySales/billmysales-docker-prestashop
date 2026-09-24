<?php
/**
 * Stack overrides for config/defines.inc.php, copied into config/ by setup.sh
 * on every run. Values come from environment variables set in compose.yaml.
 */

// Debug mode (shows errors, disables caches). Never enable it in production.
if (filter_var(getenv('PS_DEV_MODE'), FILTER_VALIDATE_BOOLEAN)) {
    define('_PS_MODE_DEV_', true);
}
