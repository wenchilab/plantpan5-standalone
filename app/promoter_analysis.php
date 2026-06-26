<?php
// Single-promoter analysis was removed in Step 23 — Multiple Promoter Analysis
// now handles 1..N sequences uniformly. Redirect any stale bookmarks.
header('Location: /promoter_multiple.php', true, 301);
exit;
