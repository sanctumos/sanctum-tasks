<?php
/**
 * Legacy redirect: /admin/change-password.php -> /admin/settings.php?tab=password
 * Preserves bookmarks and the must-change-password flow.
 */
header('Location: /admin/settings.php?tab=password', true, 302);
exit;
