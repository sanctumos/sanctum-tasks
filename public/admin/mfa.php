<?php
/**
 * Legacy redirect: /admin/mfa.php -> /admin/settings.php?tab=mfa
 */
header('Location: /admin/settings.php?tab=mfa', true, 302);
exit;
