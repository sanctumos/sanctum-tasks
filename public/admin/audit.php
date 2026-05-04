<?php
/**
 * Legacy redirect: /admin/audit.php -> /admin/settings.php?tab=audit
 */
header('Location: /admin/settings.php?tab=audit', true, 302);
exit;
