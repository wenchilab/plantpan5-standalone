<?php
/**
 * Shared layout — Bootstrap 5 + PlantPAN 5 brand palette.
 * Pages get loaded inside <main class="container">.
 *
 * $extras: array of optional libs to include in <head>. Values:
 *   'datatables' — jQuery 3.7.1 + DataTables 2.1.8 (with Bootstrap 5 styling)
 *   'upset'      — UpSet.js bundle 1.11.0 (cross-promoter chart)
 * Loaded synchronously in head so inline IIFEs further down can use them
 * (matches how the live PlantPAN 5 result page loads them).
 */

// Released version, stamped in the footer + About page.
const PP_VERSION = '1.0';

function pp_header(string $title = 'PlantPAN 5 — Stand-alone Edition', array $extras = []): void
{
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Raleway:wght@500;600;700&display=swap" rel="stylesheet">
<?php if (in_array('datatables', $extras, true)): ?>
<link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.js"></script>
<?php endif; ?>
<?php if (in_array('upset', $extras, true)): ?>
<script src="https://unpkg.com/@upsetjs/bundle@1.11.0"></script>
<?php endif; ?>
<?php if (in_array('d3', $extras, true)): ?>
<script src="https://d3js.org/d3.v7.min.js"></script>
<?php endif; ?>
<?php if (in_array('pmmap', $extras, true)):
  // Cache-buster: file mtime so any pmmap.js edit forces browsers to re-fetch
  // (avoids stale "old behaviour" after a redeploy). __DIR__ is includes/,
  // pmmap lives at ../assets/js/.
  $__pm = __DIR__ . '/../assets/js/pmmap.js';
  $__pmV = is_file($__pm) ? filemtime($__pm) : '0';
?>
<script src="/assets/js/pmmap.js?v=<?= $__pmV ?>"></script>
<?php endif; ?>
<style>
  :root {
    --pp-red:   #E74C3C;  --pp-red-d: #c0392b;
    --pp-blue:  #0c5198;  --pp-blue-l:#1c6cb8;
    --pp-bg:    #f7f8fa;  --pp-card:  #ffffff;
    --pp-dark:  #292929;  --pp-dark-l: #464545;
    --pp-border:#e3e6ea;  --pp-text:  #2c3e50;
  }
  html, body { height: 100%; }
  body {
    font-family: "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--pp-bg); color: var(--pp-text);
    display: flex; flex-direction: column;
  }
  h1, h2, h3, h4 { font-family: "Raleway", sans-serif; font-weight: 700; color: var(--pp-text); }
  .pp-header {
    background: linear-gradient(90deg, var(--pp-dark) 0%, var(--pp-dark-l) 100%);
    color: #fff; padding: 22px 32px 18px; box-shadow: 0 2px 8px rgba(0,0,0,.12);
  }
  .pp-header h1 { color: #fff; font-size: 1.45rem; margin: 0; letter-spacing: .3px; }
  .pp-header .subtitle { font-size: .82rem; opacity: .92; margin-top: 2px; }
  .pp-header .subtitle a { color: #fff; text-decoration: underline; }
  .pp-nav {
    background: #fff; border-bottom: 2px solid var(--pp-red); padding: 0 32px;
    box-shadow: 0 1px 0 var(--pp-border);
  }
  .pp-nav a {
    display: inline-block; color: #34495e; padding: 14px 18px;
    text-decoration: none; font-weight: 600; font-size: .92rem;
    border-bottom: 3px solid transparent; transition: all .15s ease;
  }
  .pp-nav a:hover { color: var(--pp-red); }
  .pp-nav a.active { color: var(--pp-red); border-bottom-color: var(--pp-red); background: #fffafa; }
  main.container { max-width: 1180px; padding: 28px 24px; flex: 1; }
  .pp-card {
    background: var(--pp-card); border: 1px solid var(--pp-border);
    border-radius: 10px; padding: 22px 26px; margin-bottom: 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
  }
  .pp-card h2 {
    margin: 0 0 16px; font-size: 1.15rem; color: var(--pp-dark);
    border-left: 4px solid var(--pp-red); padding-left: 10px;
  }
  .btn-pp { background: var(--pp-red); border-color: var(--pp-red); color: #fff; font-weight: 600; padding: 9px 22px; border-radius: 6px; }
  .btn-pp:hover { background: var(--pp-red-d); border-color: var(--pp-red-d); color: #fff; }
  .btn-pp-outline { background: #fff; border: 1px solid var(--pp-dark); color: var(--pp-dark); font-weight: 600; padding: 8px 16px; border-radius: 6px; }
  .btn-pp-outline:hover { background: var(--pp-dark); color: #fff; }
  .pp-stat { background: #f7f9fc; border: 1px solid var(--pp-border); border-radius: 8px; padding: 12px 18px; }
  .pp-stat .n { font-size: 1.5rem; font-weight: 700; color: var(--pp-dark); }
  .pp-stat .l { font-size: .76rem; color: #777; text-transform: uppercase; letter-spacing: .5px; }
  .pill { display: inline-block; background: #eef3fb; color: var(--pp-dark); padding: 2px 9px; border-radius: 10px; font-size: .76rem; font-weight: 600; border: 1px solid #d8e3f3; }
  /* Step 24 — Source pill colours (PWM / PLACE) and Species filter chips. */
  .pill-pwm   { background: #eef3fb; color: #0c5198; border-color: #d8e3f3; }
  .pill-place { background: #fef3e7; color: #c05a1c; border-color: #f3d6b8; }
  .pp-species-chip {
    display: inline-flex; align-items: center; gap: 4px;
    background: #eef3fb; color: var(--pp-dark);
    padding: 3px 8px 3px 10px; border-radius: 12px;
    border: 1px solid #d8e3f3; font-size: .78rem; font-weight: 600;
    line-height: 1.1;
  }
  .pp-species-chip.pp-species-chip-all { background: #f0f3f7; color: #555; }
  .pp-species-chip .pp-chip-close {
    cursor: pointer; opacity: .55; font-weight: 700;
    margin-left: 2px; padding: 0 2px; border-radius: 50%;
  }
  .pp-species-chip .pp-chip-close:hover { opacity: 1; background: rgba(0,0,0,.08); }
  .pp-species-dropdown { min-width: 320px; max-width: 460px; max-height: 360px; overflow-y: auto; }
  .pp-species-dropdown .form-check { padding-left: 1.7rem; margin: 1px 0; }
  .pp-species-dropdown .form-check-label { font-size: .82rem; cursor: pointer; }
  .pp-species-dropdown .form-check-label .pp-species-count { color: #888; font-size: .72rem; margin-left: 4px; }
  .pp-species-status { font-size: .75rem; color: #777; }
  .pp-species-status.pp-filter-active { color: #c05a1c; font-weight: 600; }
  /* DataTables (Bootstrap 5) — re-skin pagination in PlantPAN red.
   * Selectors cover DataTables 2.x (.dt-container / .dt-paging) and
   * legacy (.dataTables_wrapper); also scoped via .pp-card so the rule
   * wins over the Bootstrap default page-link colour without needing
   * !important. The bare .pp-card .pagination selector catches every
   * DataTable in this app since all of them live inside .pp-card. */
  .pp-card .pagination .page-link,
  .dt-container .pagination .page-link,
  .dataTables_wrapper .pagination .page-link {
    color: var(--pp-red); border-color: var(--pp-border);
  }
  .pp-card .pagination .page-link:hover,
  .pp-card .pagination .page-link:focus,
  .dt-container .pagination .page-link:hover,
  .dataTables_wrapper .pagination .page-link:hover {
    color: var(--pp-red-d); background-color: #fff5f5;
    border-color: #f1c6c1; box-shadow: none;
  }
  .pp-card .pagination .page-item.active .page-link,
  .dt-container .pagination .page-item.active .page-link,
  .dataTables_wrapper .pagination .page-item.active .page-link {
    background-color: var(--pp-red); border-color: var(--pp-red); color: #fff;
  }
  .pp-card .pagination .page-item.disabled .page-link,
  .dataTables_wrapper .pagination .page-item.disabled .page-link { color: #adb5bd; }
  .pp-card .dataTables_length select:focus,
  .pp-card .dataTables_filter input:focus,
  .dt-container .dt-length select:focus,
  .dt-container .dt-search input:focus,
  .dataTables_wrapper .dataTables_length select:focus,
  .dataTables_wrapper .dataTables_filter input:focus {
    border-color: var(--pp-red); box-shadow: 0 0 0 .2rem rgba(231,76,60,.18);
  }
  table.pp-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  table.pp-table th, table.pp-table td { padding: 8px 12px; border-bottom: 1px solid #eef0f3; text-align: left; }
  table.pp-table th { background: #f0f3f7; color: #444; font-weight: 600; font-family: "Raleway", sans-serif; }
  table.pp-table tr:hover td { background: #fafbfd; }
  .small-mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .85rem; }
  .nav-tabs { border-bottom: 2px solid var(--pp-border); }
  .nav-tabs .nav-link { color: #495057; border: none; font-weight: 600; padding: 10px 18px; border-bottom: 3px solid transparent; border-radius: 0; }
  .nav-tabs .nav-link.active { color: var(--pp-red); border-bottom-color: var(--pp-red); background: transparent; }
  .nav-tabs .nav-link:hover { border-color: transparent transparent var(--pp-red) transparent; color: var(--pp-red); }
  footer.pp-footer { text-align: center; color: #888; font-size: .82rem; padding: 20px; border-top: 1px solid var(--pp-border); background: #fff; margin-top: auto; }
  footer.pp-footer a { color: var(--pp-dark); }
  .axis path, .axis line { stroke: #aaa; }
  .axis text { font-size: 11px; fill: #666; }
  .strand-fwd { fill: var(--pp-red); }
  .strand-rev { fill: var(--pp-dark); }
  .legend-text { font-size: 12px; }
  .tooltip-box {
    position: absolute; pointer-events: none; background: rgba(15,23,42,.96);
    color: #fff; padding: 8px 10px; border-radius: 6px; font-size: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,.3); max-width: 260px; z-index: 9999;
    opacity: 0; transition: opacity .15s;
  }
</style>
</head>
<body>
<header class="pp-header">
  <h1><i class="bi bi-flower3"></i> &nbsp;PlantPAN 5 — Stand-alone Edition</h1>
  <div class="subtitle">Promoter analysis, run locally. For full functionality, please visit the PlantPAN 5.0 website.
    <a href="https://plantpan.itps.ncku.edu.tw" target="_blank">plantpan.itps.ncku.edu.tw</a>.
  </div>
</header>
<nav class="pp-nav">
  <a href="/index.php"<?= pp_nav_active('/index.php') ?>><i class="bi bi-house"></i> Home</a>
  <a href="/promoter_multiple.php"<?= pp_nav_active('/promoter_multiple.php') ?>><i class="bi bi-search"></i> Promoter Analysis</a>
  <a href="/jobs.php"<?= pp_nav_active('/jobs.php') ?>><i class="bi bi-clock-history"></i> Jobs</a>
  <a href="/about.php"<?= pp_nav_active('/about.php') ?>><i class="bi bi-info-circle"></i> About</a>
</nav>
<main class="container">
<?php
}

function pp_nav_active(string $path): string
{
    $cur = $_SERVER['SCRIPT_NAME'] ?? '';
    return ($cur === $path) ? ' class="active"' : '';
}

function pp_footer(): void
{
?>
</main>
<footer class="pp-footer">
  PlantPAN 5 Stand-alone Edition <span class="text-muted">v<?= htmlspecialchars(PP_VERSION) ?></span> &mdash; scanning runs entirely on your machine. No data leaves the container.
  <br>
  <!-- Citation hidden until the companion publication is available.
  Citation: Yang C-W. <em>et al.</em> (2024) PlantPAN 5: an updated regulatory transcription factor database. <em>Nucleic Acids Research</em>.
  -->
  Maintained by <strong>PBMB Lab</strong> &middot; <strong>Institute of Tropical Plant Sciences and Microbiology, National Cheng Kung University</strong>

</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://d3js.org/d3.v7.min.js"></script>
</body>
</html>
<?php
}
