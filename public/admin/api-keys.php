<?php
/**
 * Legacy redirect: /admin/api-keys.php -> /admin/settings.php?tab=api-keys
 */
header('Location: /admin/settings.php?tab=api-keys', true, 302);
exit;
