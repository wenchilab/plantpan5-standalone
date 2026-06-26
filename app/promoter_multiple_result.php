<?php
/**
 * promoter_multiple_result.php — two-stage:
 *
 *   POST /promoter_multiple_result.php
 *     Validates input, builds a job (one .tsv + .json per sequence,
 *     plus manifest.json), then 303-redirects to the GET view.
 *
 *   GET /promoter_multiple_result.php?job=<id>
 *     Reads the manifest and renders the file-browser UI. The right
 *     pane is hydrated by /api/get_result.php (Step 6) when the user
 *     clicks a sequence on the left.
 */
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/scan_engine.php';
require __DIR__ . '/includes/job_utils.php';

$tmpdir   = sys_get_temp_dir();
$err      = null;
$manifest = null;
$cross    = null;
$job_id   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ip = pp_client_ip();
        $rl = pp_check_rate_limit($ip);
        if (!$rl['allowed']) {
            throw new RuntimeException(sprintf(
                'Rate limit reached (%d jobs in the last minute). Try again in %d seconds.',
                $rl['current_count'], $rl['retry_after_sec']
            ));
        }

        $raw = (string) ($_POST['sequence'] ?? '');
        if (strlen($raw) > PP_MAX_TOTAL_INPUT_BYTES) {
            throw new RuntimeException(sprintf(
                'Input too large (%s); limit is %s.',
                pp_format_bytes(strlen($raw)),
                pp_format_bytes(PP_MAX_TOTAL_INPUT_BYTES)
            ));
        }

        pp_ensure_output_root();
        $fasta = pp_write_input_fasta($raw, $tmpdir);
        try {
            $lengths = pp_parse_fasta_lengths($fasta);
            $est     = pp_estimate_output_bytes((int) array_sum($lengths));
            $cap     = pp_check_capacity($est);
            if (!$cap['allowed']) throw new RuntimeException($cap['reason']);

            $job_id   = pp_make_job_id();
            $manifest = pp_scan_to_files($fasta, $job_id, $tmpdir);

            // Augment manifest with submission-context fields the scanner
            // doesn't know about (kept out of pp_scan_to_files so it stays
            // pure / testable).
            $manifest['client_ip_hash']      = substr(hash('sha256', $ip), 0, 16);
            $manifest['original_input_bytes'] = strlen($raw);

            pp_atomic_write(
                pp_safe_job_path($job_id, 'manifest.json'),
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            pp_record_job_start($ip);
        } finally {
            @unlink($fasta);
        }

        header('Location: /promoter_multiple_result.php?job=' . urlencode($job_id), true, 303);
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
} elseif (isset($_GET['job'])) {
    $job_id = (string) $_GET['job'];
    if (!pp_validate_job_id($job_id)) {
        $err = 'Invalid job id.';
    } else {
        try {
            $manifest_path = pp_safe_job_path($job_id, 'manifest.json');
            if (!is_file($manifest_path)) {
                $err = 'Job not found. It may have been deleted.';
            } else {
                $manifest = json_decode((string) file_get_contents($manifest_path), true);
                if (!is_array($manifest)) {
                    $err = 'Job manifest is corrupt.';
                }
                // Cross-promoter is optional: pre-Step-18 jobs won't have it.
                // Frontend hides the section when $cross is null or n_promoters<2.
                $cross_path = pp_safe_job_path($job_id, 'cross_promoter.json');
                if (is_file($cross_path)) {
                    $cross_raw = json_decode((string) file_get_contents($cross_path), true);
                    if (is_array($cross_raw)) $cross = $cross_raw;
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
} else {
    $err = 'No input.';
}

// Detect whether this job has species metadata (Step 24+). Old jobs lack
// species_universe — we hide the species filter UI and Source/PLACE columns.
$hasSpeciesFilter = !empty($manifest['species_universe']) && is_array($manifest['species_universe']);

pp_header('Promoter Analysis — Results', ['datatables', 'upset', 'pmmap']);
?>
<?php if ($err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <a class="btn btn-pp" href="/promoter_multiple.php"><i class="bi bi-arrow-left"></i> Back to input</a>
  <a class="btn btn-pp-outline ms-1" href="/jobs.php"><i class="bi bi-list-ul"></i> Job history</a>
<?php else: ?>

  <div class="pp-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
      <div>
        <h2 class="mb-0"><i class="bi bi-collection"></i> Job
          <span class="small-mono"><?= htmlspecialchars($manifest['job_id']) ?></span>
        </h2>
        <div class="text-muted small mt-1"><?= htmlspecialchars($manifest['created_iso']) ?></div>
      </div>
      <div class="mt-2">
        <a class="btn btn-pp-outline btn-sm" href="/jobs.php"><i class="bi bi-list-ul"></i> All jobs</a>
        <a class="btn btn-pp-outline btn-sm ms-1" href="/promoter_multiple.php"><i class="bi bi-plus-circle"></i> New scan</a>
      </div>
    </div>

    <?php
      // Step 25+: manifest may now split output bytes into TSV (downloadable)
      // and JSON (web-cache). Pre-Step-25 jobs only have total_output_bytes
      // — fall back gracefully so old jobs still display sensibly.
      $tsvBytes   = isset($manifest['total_tsv_bytes'])  ? (int) $manifest['total_tsv_bytes']  : null;
      $jsonBytes  = isset($manifest['total_json_bytes']) ? (int) $manifest['total_json_bytes'] : null;
      $haveSplit  = $tsvBytes !== null && $jsonBytes !== null;
    ?>
    <div class="row g-3 mb-3">
      <div class="col-sm-6 col-md"><div class="pp-stat"><div class="n"><?= (int) $manifest['sequence_count'] ?></div><div class="l">Sequences</div></div></div>
      <div class="col-sm-6 col-md"><div class="pp-stat"><div class="n"><?= (int) $manifest['total_hits'] ?></div><div class="l">Total hits</div></div></div>
      <div class="col-sm-6 col-md"><div class="pp-stat"><div class="n"><?= (int) $manifest['total_distinct_motifs'] ?></div><div class="l">Distinct motifs</div></div></div>
      <?php if ($haveSplit): ?>
        <div class="col-sm-6 col-md">
          <div class="pp-stat" title="Sum of all <seq>.tsv files. This is what ends up in the downloadable ZIP.">
            <div class="n" style="font-size:1.1rem;"><?= htmlspecialchars(pp_format_bytes($tsvBytes)) ?></div>
            <div class="l">TSV size</div>
          </div>
        </div>
        <div class="col-sm-6 col-md">
          <div class="pp-stat" title="Sum of all <seq>.json files. Kept server-side to hydrate the right pane without re-running match. NOT included in the download ZIP.">
            <div class="n" style="font-size:1.1rem;"><?= htmlspecialchars(pp_format_bytes($jsonBytes)) ?></div>
            <div class="l">Web cache size</div>
          </div>
        </div>
      <?php else: ?>
        <div class="col-sm-6 col-md">
          <div class="pp-stat"><div class="n" style="font-size:1.1rem;"><?= htmlspecialchars(pp_format_bytes((int) $manifest['total_output_bytes'])) ?></div><div class="l">Output size</div></div>
        </div>
      <?php endif; ?>
    </div>

    <a class="btn btn-pp" href="/api/download_zip.php?job=<?= urlencode($job_id) ?>">
      <i class="bi bi-download"></i> Download TSV bundle (ZIP)
    </a>
    <span class="text-muted small ms-2">
      <i class="bi bi-info-circle"></i>
      ZIP contains per-sequence TSV + manifest + cross-promoter analysis.
      The web cache (per-seq JSON) and the original sequences are not included
      &mdash; re-scan to recreate the interactive view from the TSV.
    </span>
  </div>

  <?php if ($hasSpeciesFilter): ?>
  <div class="pp-card" id="speciesFilterCard">
    <h2><i class="bi bi-funnel-fill"></i> Filter by species</h2>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <div class="dropdown">
        <button class="btn btn-pp-outline btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="speciesDropdownBtn">
          <i class="bi bi-list-check"></i> Select species
          <span class="badge bg-secondary ms-1"><?= count($manifest['species_universe']) ?></span>
        </button>
        <ul class="dropdown-menu pp-species-dropdown p-2" id="speciesDropdown" aria-labelledby="speciesDropdownBtn"></ul>
      </div>
      <div id="speciesChipBar" class="d-flex flex-wrap gap-1 align-items-center"></div>
      <span id="speciesFilterStatus" class="pp-species-status ms-auto"></span>
    </div>
    <div class="text-muted small mt-2">
      <i class="bi bi-info-circle"></i>
      Filter applies to Cross-Promoter Overview, Pattern Search, Position Track, Family chart, and Sequence Map.
      Clearing all selections turns the filter off (everything shown).
    </div>
  </div>
  <?php endif; ?>

  <?php if ($cross && count($cross['promoters'] ?? []) >= 2):
      $crossN     = count($cross['promoters']);
      $crossM     = count($cross['matrices']);
      $upsetEligible = $crossN <= 10;
  ?>
  <div class="pp-card">
    <h2><i class="bi bi-diagram-3"></i> Cross-Promoter Overview
      <small class="text-muted" style="font-weight:400; font-size:.8rem;">(<?= $crossN ?> promoters, <span id="crossMatrixCount"><?= $crossM ?></span> distinct matrices)</small>
    </h2>
    <?php if ($upsetEligible): ?>
      <div id="crossUpset" style="width:100%; min-height:420px;"></div>
      <div id="crossUpsetEmpty" class="text-muted small d-none">No matrix is shared across two or more promoters.</div>
    <?php else: ?>
      <div class="alert alert-info small mb-3">
        <i class="bi bi-info-circle"></i>
        UpSet plot disabled for &gt; 10 promoters (would have up to 2<sup><?= $crossN ?></sup> intersections).
        Use the table below to explore which motifs are shared.
      </div>
    <?php endif; ?>
    <div class="d-flex gap-2 mb-2 flex-wrap" id="crossFilterChips">
      <button type="button" class="btn btn-sm btn-outline-secondary active" data-cross-filter="all">All</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-cross-filter="common">Common to all</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-cross-filter="unique">Unique to one</button>
    </div>
    <table id="crossSummary" class="table table-striped table-hover table-sm" style="width:100%;">
      <thead><tr>
        <th>Matrix ID</th><th>Family</th>
        <th class="text-end"># Promoters</th><th>Promoters</th>
      </tr></thead>
    </table>
  </div>
  <?php endif; ?>

  <div class="d-flex justify-content-end mb-2">
    <button type="button" id="sidebarToggle" class="btn btn-sm btn-pp-outline"
            title="Hide / show the sequence list">
      <i class="bi bi-layout-sidebar-inset" id="sidebarToggleIcon"></i>
      <span id="sidebarToggleLabel" class="ms-1">Hide sequences</span>
    </button>
  </div>
  <div class="row g-3" id="resultLayout">
    <div class="col-md-4" id="leftCol">
      <div class="pp-card" style="padding:14px; max-height:720px; overflow-y:auto;">
        <h2 style="font-size:.95rem; margin-bottom:10px;"><i class="bi bi-files"></i> Sequences</h2>
        <input type="text" id="fileFilter" class="form-control form-control-sm mb-2"
               placeholder="🔍 Filter by name or family...">
        <div id="fileList"></div>
      </div>
    </div>
    <div class="col-md-8" id="rightCol">
      <div class="pp-card" id="rightPane">
        <div id="rightPlaceholder" class="text-muted text-center py-5">
          <i class="bi bi-arrow-left" style="font-size:2rem;"></i>
          <div class="mt-2">Select a sequence on the left to view its results.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="tooltip-box" id="ttbox"></div>

  <script>
  const MANIFEST = <?= json_encode($manifest, JSON_UNESCAPED_SLASHES) ?>;
  const JOB_ID   = <?= json_encode($job_id) ?>;
  const CROSS    = <?= json_encode($cross, JSON_UNESCAPED_SLASHES) ?>;
  const HAS_SPECIES_FILTER = <?= $hasSpeciesFilter ? 'true' : 'false' ?>;

  // ---- Shared helpers (defined early so speciesFilter can use them) ------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function fmtBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
    return (n/1024/1024).toFixed(1) + ' MB';
  }

  // ---- TFBS_info.php POST helper (Step 26) -------------------------------
  // The online site's TFBS_info.php only reads $_POST['matrix']; a plain
  // <a href="...?matrix=X"> GET returns an empty page. Mirror the online
  // postUrl() helper: build a hidden form, target=_blank, submit, remove.
  const PLANTPAN_TFBS_URL = 'https://plantpan.itps.ncku.edu.tw/plantpan5/TFBS_info.php';
  window.ppPostToOnline = function(matrixId) {
    if (!matrixId) return;
    const f = document.createElement('form');
    f.method = 'post';
    f.action = PLANTPAN_TFBS_URL;
    f.target = '_blank';
    f.rel = 'noopener';
    const i = document.createElement('input');
    i.type = 'hidden';
    i.name = 'matrix';
    i.value = matrixId;
    f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
    setTimeout(function() { try { document.body.removeChild(f); } catch (e) {} }, 0);
  };
  // Delegated handler covers every <a.pp-matrix-link> in the document,
  // including ones DataTables / d3 popups inject after page load.
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a.pp-matrix-link');
    if (!a) return;
    e.preventDefault();
    window.ppPostToOnline(a.dataset.matrix);
  });

  // ---- Species filter module ---------------------------------------------
  // Singleton: holds selection state, persists to localStorage, exposes
  // passes(motifId, motifSpeciesMap) / passesSpecies(speciesArr) predicates
  // for downstream tabs, and dispatches `pp:species-filter-changed` events
  // whenever the selection changes.
  const speciesFilter = (function() {
    const KEY = 'pp_species_filter_' + JOB_ID;
    const universe = (MANIFEST.species_universe || []).slice();
    const hasData = HAS_SPECIES_FILTER && universe.length > 0;
    let selected = new Set();
    let motifCountBySpecies = {};

    function load() {
      if (!hasData) return;
      try {
        const raw = localStorage.getItem(KEY);
        if (raw) {
          const arr = JSON.parse(raw);
          if (Array.isArray(arr)) {
            const set = new Set(universe);
            selected = new Set(arr.filter(s => set.has(s)));
          }
        }
      } catch (e) { /* corrupt storage — start fresh */ }
    }
    function save() {
      if (!hasData) return;
      try { localStorage.setItem(KEY, JSON.stringify(Array.from(selected))); }
      catch (e) { /* over quota — ignore */ }
    }

    // Filter is "active" only when selection is strictly partial.
    // Empty (Clear all) and full (Select all) both mean "show everything".
    function isActive() {
      return hasData && selected.size > 0 && selected.size < universe.length;
    }
    // Per-motif predicate using the per-seq motif_species lookup.
    function passes(motifId, motifSpeciesMap) {
      if (!isActive()) return true;
      if (!motifSpeciesMap) return true;  // graceful on missing data
      const spp = motifSpeciesMap[motifId];
      if (!spp || !spp.length) return false;
      for (const s of spp) if (selected.has(s)) return true;
      return false;
    }
    // Pre-aggregated predicate for cross-promoter matrix rows.
    function passesSpecies(speciesArr) {
      if (!isActive()) return true;
      if (!speciesArr || !speciesArr.length) return false;
      for (const s of speciesArr) if (selected.has(s)) return true;
      return false;
    }

    function computeMotifCounts() {
      motifCountBySpecies = {};
      const matrices = (CROSS && CROSS.matrices) || [];
      for (const m of matrices) {
        const seen = new Set();
        for (const s of (m.species || [])) {
          if (seen.has(s)) continue;
          seen.add(s);
          motifCountBySpecies[s] = (motifCountBySpecies[s] || 0) + 1;
        }
      }
    }

    // "Arabidopsis thaliana" -> "A. thaliana"; bracket-group entries verbatim.
    function abbr(latin) {
      if (!latin) return '';
      if (latin[0] === '(') return latin;
      const parts = latin.split(' ');
      if (parts.length < 2) return latin;
      return parts[0][0] + '. ' + parts.slice(1).join(' ');
    }

    // Sort: regular species alpha, bracket-group entries pinned to the end.
    function sortedUniverse() {
      return universe.slice().sort((a, b) => {
        const ab = a[0] === '(', bb = b[0] === '(';
        if (ab !== bb) return ab ? 1 : -1;
        return a.localeCompare(b);
      });
    }

    function notify() {
      window.dispatchEvent(new CustomEvent('pp:species-filter-changed', {
        detail: {
          selected: Array.from(selected),
          universe: universe.slice(),
          active: isActive(),
        }
      }));
    }

    function renderDropdown() {
      const root = document.getElementById('speciesDropdown');
      if (!root) return;
      const items = sortedUniverse().map(sp => {
        const isOn = selected.has(sp);
        const cnt  = motifCountBySpecies[sp] || 0;
        return '<li><div class="form-check">'
             + '<input class="form-check-input pp-species-cb" type="checkbox"'
             +   ' data-sp="' + esc(sp) + '"' + (isOn ? ' checked' : '') + '>'
             + '<label class="form-check-label" title="' + esc(sp) + '">'
             +   esc(abbr(sp))
             +   '<span class="pp-species-count">(' + cnt + ' motif' + (cnt === 1 ? '' : 's') + ')</span>'
             + '</label></div></li>';
      }).join('');
      const buttons = '<li class="px-1 pb-1 d-flex gap-1 border-bottom mb-1">'
                    + '<button type="button" class="btn btn-sm btn-pp-outline" id="speciesSelectAll">'
                    +   '<i class="bi bi-check-square"></i> Select all</button>'
                    + '<button type="button" class="btn btn-sm btn-pp-outline" id="speciesClearAll">'
                    +   '<i class="bi bi-x-square"></i> Clear all</button></li>';
      root.innerHTML = buttons + items;

      root.querySelectorAll('.pp-species-cb').forEach(cb => {
        cb.addEventListener('change', () => {
          const sp = cb.dataset.sp;
          if (cb.checked) selected.add(sp); else selected.delete(sp);
          save(); notify(); renderChips();
        });
      });
      root.querySelector('#speciesSelectAll')?.addEventListener('click', () => {
        selected = new Set(universe); save(); notify(); renderChips(); renderDropdown();
      });
      root.querySelector('#speciesClearAll')?.addEventListener('click', () => {
        selected = new Set(); save(); notify(); renderChips(); renderDropdown();
      });
    }

    function renderChips() {
      const bar    = document.getElementById('speciesChipBar');
      const status = document.getElementById('speciesFilterStatus');
      if (!bar) return;
      const n = selected.size, total = universe.length;
      if (n === 0) {
        bar.innerHTML = '<span class="pp-species-chip pp-species-chip-all">All species (filter off)</span>';
        if (status) { status.textContent = ''; status.classList.remove('pp-filter-active'); }
        return;
      }
      if (n === total) {
        bar.innerHTML = '<span class="pp-species-chip pp-species-chip-all">All ' + n + ' species (filter off)</span>';
        if (status) { status.textContent = ''; status.classList.remove('pp-filter-active'); }
        return;
      }
      const arr  = Array.from(selected).sort();
      const top3 = arr.slice(0, 3);
      const more = arr.length - 3;
      const chips = top3.map(sp =>
        '<span class="pp-species-chip" title="' + esc(sp) + '">' + esc(abbr(sp))
        + '<span class="pp-chip-close" data-sp="' + esc(sp) + '" title="Remove">×</span></span>'
      ).join(' ');
      const moreChip = more > 0
        ? ' <span class="pp-species-chip pp-species-chip-all">+ ' + more + ' more</span>'
        : '';
      bar.innerHTML = chips + moreChip;
      bar.querySelectorAll('.pp-chip-close').forEach(x => {
        x.addEventListener('click', () => {
          selected.delete(x.dataset.sp);
          save(); notify(); renderChips(); renderDropdown();
        });
      });
      if (status) {
        status.textContent = 'Filtering by ' + n + ' / ' + total + ' species';
        status.classList.add('pp-filter-active');
      }
    }

    function init() {
      if (!hasData) return;
      computeMotifCounts();
      load();
      renderDropdown();
      renderChips();
      // Defer initial notify so listeners attached during the current
      // sync execution (Cross-Promoter, right pane) are ready.
      setTimeout(notify, 0);
    }

    return {
      init, passes, passesSpecies, isActive,
      hasData,
      universe,
      abbr,
      get selected() { return new Set(selected); },
    };
  })();

  // ---- Cross-Promoter Overview (UpSet + summary DataTable) ----------------
  // Renders into the section above the file browser when N>=2.
  // UpSet plot only when N<=10; for N>10 the summary table is the only view.
  (function initCrossPromoter() {
    if (!CROSS || !Array.isArray(CROSS.promoters) || CROSS.promoters.length < 2) return;
    const promoters = CROSS.promoters;
    const matricesAll = CROSS.matrices || [];
    const N = promoters.length;

    function esc2(s) { return String(s == null ? '' : s).replace(/[&<>"']/g,
      c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function trunc(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n-1) + '…' : s; }

    // Currently-visible matrices (species filter narrows this). Initialized
    // to the full set; the pp:species-filter-changed listener swaps it out.
    let matricesView = matricesAll.slice();

    // ---- Summary DataTable -----------------------------------------------
    let crossDt = null;
    function buildTableData(arr) {
      return arr.map(m => [
        '<a href="#" class="pp-matrix-link text-decoration-none small-mono"' +
          ' data-matrix="' + esc2(m.id) + '" title="Open TFBS info on PlantPAN (POST)">' +
          esc2(m.id) + '</a>',
        esc2(m.family || '—'),
        m.promoters.length,
        m.promoters.map(p =>
          '<span class="pill" title="' + esc2(p) + '" style="margin:1px;">' +
          esc2(trunc(p, 20)) + '</span>'
        ).join(' ')
      ]);
    }
    function initOrRefreshTable() {
      if (!crossDt) {
        crossDt = new DataTable('#crossSummary', {
          data: buildTableData(matricesView),
          pageLength: 10,
          lengthMenu: [10, 25, 50, 100],
          order: [[2, 'desc']],
          autoWidth: false,
          columnDefs: [
            { targets: 0 },
            { targets: 2, className: 'text-end' },
            { targets: 3, orderable: false },
          ],
        });
      } else {
        crossDt.clear();
        crossDt.rows.add(buildTableData(matricesView));
        crossDt.draw(false);
      }
      const cnt = document.getElementById('crossMatrixCount');
      if (cnt) cnt.textContent = matricesView.length;
    }
    initOrRefreshTable();

    // Filter chips: All / Common to all / Unique to one
    $.fn.dataTable.ext.search.push(function(settings, data) {
      if (settings.nTable.id !== 'crossSummary') return true;
      const mode = window.__crossFilterMode || 'all';
      const k = parseInt(data[2], 10);
      if (mode === 'common') return k === N;
      if (mode === 'unique') return k === 1;
      return true;
    });
    document.querySelectorAll('#crossFilterChips [data-cross-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        window.__crossFilterMode = btn.dataset.crossFilter;
        document.querySelectorAll('#crossFilterChips [data-cross-filter]')
          .forEach(b => b.classList.toggle('active', b === btn));
        if (crossDt) crossDt.draw();
      });
    });

    // ---- UpSet plot (N<=10 only) -----------------------------------------
    const root = document.getElementById('crossUpset');
    if (N > 10 || !root) {
      // Species-filter listener still needs to update the table even
      // when UpSet is absent; keep going to wire up the listener below.
    }
    let upsetAvailable = (N <= 10 && root && typeof UpSetJS !== 'undefined');
    if (N <= 10 && root && typeof UpSetJS === 'undefined') {
      console.warn('UpSet.js bundle not loaded; cross-promoter plot skipped.');
    }

    const SET_LABEL_LIMIT = 28;
    const displayToFull = new Map();
    const displayNames = promoters.map(p => {
      const d = trunc(p, SET_LABEL_LIMIT);
      displayToFull.set(d, p);
      return d;
    });

    let currentSelection = null;
    let lastClickX = 0, lastClickY = 0;
    let popup = null;

    function ensurePopup() {
      if (popup) return popup;
      popup = document.createElement('div');
      popup.className = 'cross-upset-popup';
      popup.style.cssText = [
        'position:fixed', 'z-index:1080',
        'background:rgba(33,37,41,0.97)', 'color:#fff',
        'padding:10px 12px 12px', 'border-radius:8px',
        'font-size:12px', 'line-height:1.45',
        'width:420px', 'max-width:calc(100vw - 24px)',
        'max-height:60vh', 'overflow-y:auto',
        'box-shadow:0 8px 24px rgba(0,0,0,0.35)',
        'display:none'
      ].join(';');
      document.body.appendChild(popup);
      document.addEventListener('mousedown', e => {
        if (!popup) return;
        if (popup.contains(e.target)) return;
        if (root && root.contains(e.target)) return;
        popup.style.display = 'none';
      });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && popup) popup.style.display = 'none';
      });
      popup.addEventListener('click', e => {
        if (e.target.closest('[data-cross-popup-close]')) popup.style.display = 'none';
      });
      if (root) {
        root.addEventListener('mousedown', e => {
          lastClickX = e.clientX; lastClickY = e.clientY;
        }, true);
      }
      return popup;
    }

    function showPopup(html, x, y) {
      ensurePopup();
      popup.innerHTML = html;
      popup.style.display = 'block';
      popup.scrollTop = 0;
      const r = popup.getBoundingClientRect();
      const vw = window.innerWidth, vh = window.innerHeight;
      const pad = 12;
      let px = x + pad, py = y + pad;
      if (px + r.width  > vw - 8) px = x - r.width  - pad;
      if (py + r.height > vh - 8) py = vh - r.height - pad;
      popup.style.left = Math.max(8, px) + 'px';
      popup.style.top  = Math.max(8, py) + 'px';
    }
    function idListHtml(elems) {
      if (!elems.length) return '';
      const items = elems.map(e =>
        '<code style="background:#343a40;color:#ffd166;padding:1px 5px;border-radius:3px;">' +
        esc2(e.name || e) + '</code>'
      ).join(' ');
      return '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px 4px;">' + items + '</div>';
    }
    function popupForSelection(sel) {
      if (!sel) return null;
      const closeBtn =
        '<button type="button" data-cross-popup-close ' +
        'style="position:sticky;top:0;float:right;background:transparent;border:0;color:#adb5bd;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;margin:-4px -4px 0 6px;" aria-label="Close">×</button>';
      const fullName = n => displayToFull.get(n) || n;
      if (sel.type === 'set') {
        return closeBtn +
          '<div><b>Promoter:</b> ' + esc2(fullName(sel.name)) + '</div>' +
          '<div><b>Matrices in this promoter:</b> ' + sel.cardinality + '</div>' +
          idListHtml(sel.elems || []);
      }
      const setNames = (sel.sets ? Array.from(sel.sets) : [])
        .map(s => (typeof s === 'string') ? s : s.name).map(fullName);
      const chips = setNames.map(n =>
        '<span style="display:inline-block;background:#495057;border-radius:3px;padding:1px 6px;margin:1px 3px 1px 0;">' +
        esc2(n) + '</span>'
      ).join('');
      return closeBtn +
        '<div><b>Shared matrices</b> across <b>' + setNames.length + '</b> promoter(s):</div>' +
        '<div style="margin:2px 0 4px;color:#cfd6dd;">' + chips + '</div>' +
        '<div><b>Cardinality:</b> ' + sel.cardinality + '</div>' +
        idListHtml(sel.elems || []);
    }
    function decorateSetLabels() {
      if (!root) return;
      const svg = root.querySelector('svg');
      if (!svg) return;
      svg.querySelectorAll('text').forEach(t => {
        const raw = (t.textContent || '').trim();
        if (!displayToFull.has(raw)) return;
        if (t.querySelector('title')) return;
        const full = displayToFull.get(raw);
        if (full !== raw) t.style.cursor = 'help';
        const ttl = document.createElementNS('http://www.w3.org/2000/svg', 'title');
        ttl.textContent = full;
        t.appendChild(ttl);
      });
    }

    function renderUpset() {
      if (!upsetAvailable) return;
      const emptyEl = document.getElementById('crossUpsetEmpty');

      if (matricesView.length === 0) {
        // Filter excluded everything — hide chart, show empty hint.
        root.classList.add('d-none');
        if (emptyEl) {
          emptyEl.classList.remove('d-none');
          emptyEl.textContent = 'No matrix matches the current species filter.';
        }
        return;
      }

      const setObjs = promoters.map((p, i) => ({
        name:  displayNames[i],
        elems: matricesView.filter(m => m.promoters.indexOf(p) !== -1).map(m => m.id),
      }));
      const upsetSets = UpSetJS.asSets(setObjs);
      const maxBars = Math.min(Math.pow(2, N) - 1, 200);
      let combinations = UpSetJS.generateCombinations(upsetSets, {
        type: 'distinctIntersection', min: 1, order: 'cardinality:desc', limit: maxBars,
      });
      if (N >= 2) {
        const hasAllN = combinations.some(c => {
          const cs = c.sets ? Array.from(c.sets).map(x => typeof x === 'string' ? x : x.name) : [];
          return cs.length === N;
        });
        if (!hasAllN) {
          const fullCombos = UpSetJS.generateCombinations(upsetSets, {
            type: 'distinctIntersection', min: 1, order: 'degree:desc,cardinality:desc',
          });
          const allRow = fullCombos.find(c => {
            const cs = c.sets ? Array.from(c.sets).map(x => typeof x === 'string' ? x : x.name) : [];
            return cs.length === N;
          });
          if (allRow) combinations = combinations.concat([allRow]);
        }
      }
      if (!combinations.length) {
        root.classList.add('d-none');
        if (emptyEl) {
          emptyEl.classList.remove('d-none');
          emptyEl.textContent = 'No matrix is shared across two or more promoters.';
        }
        return;
      }
      root.classList.remove('d-none');
      if (emptyEl) emptyEl.classList.add('d-none');

      UpSetJS.render(root, {
        sets: upsetSets,
        combinations: combinations,
        width:  root.clientWidth || 600,
        height: 440,
        padding: 8,
        theme: 'light',
        setLabelAlignment: 'center',
        setNameAxisOffset: 18,
        combinationNameAxisOffset: 28,
        setName: 'Total count for each input',
        combinationName: 'Shared matrices',
        color: '#2F2F2F',
        selectionColor: '#E74C3C',
        selection: currentSelection,
        fontSizes: {
          setLabel: '9px', chartLabel: '11px', axisTick: '9px',
          barLabel: combinations.length > 30 ? '0px' : '9px',
          legendLabel: '10px', valueLabel: '10px', exportLabel: '12px',
        },
        onHover: (sel) => { currentSelection = sel; renderUpset(); },
        onClick: (sel) => {
          const html = popupForSelection(sel);
          if (html) showPopup(html, lastClickX, lastClickY);
          else if (popup) popup.style.display = 'none';
        },
      });
      decorateSetLabels();
    }
    renderUpset();

    if (upsetAvailable) {
      let resizeTimer = null, lastWidth = root.clientWidth;
      const ro = new ResizeObserver(entries => {
        for (const ent of entries) {
          const w = Math.round(ent.contentRect.width);
          if (Math.abs(w - lastWidth) < 4) continue;
          lastWidth = w;
          clearTimeout(resizeTimer);
          resizeTimer = setTimeout(renderUpset, 150);
        }
      });
      ro.observe(root);
    }

    // ---- Species filter integration --------------------------------------
    window.addEventListener('pp:species-filter-changed', () => {
      if (speciesFilter.isActive()) {
        matricesView = matricesAll.filter(m => speciesFilter.passesSpecies(m.species));
      } else {
        matricesView = matricesAll.slice();
      }
      initOrRefreshTable();
      renderUpset();
    });
  })();

  // ---- File browser (left pane) ------------------------------------------
  function renderFileList(filterText = '') {
    const q = filterText.trim().toLowerCase();
    const root = document.getElementById('fileList');
    root.innerHTML = '';
    let shown = 0;
    MANIFEST.sequences.forEach((s, idx) => {
      const hay = (s.seq_id + ' ' + (s.top_family || '')).toLowerCase();
      if (q && !hay.includes(q)) return;
      shown++;
      const ord = idx + 1;

      const el = document.createElement('a');
      el.href = '#';
      el.className = 'list-group-item list-group-item-action px-2 py-2 border-bottom';
      el.dataset.seqid = s.seq_id;
      el.style.cursor = 'pointer';
      el.innerHTML =
        '<div class="d-flex justify-content-between align-items-start">' +
          '<div class="small-mono text-truncate" style="max-width:70%;" title="' + esc(s.seq_id) + '">' +
            '<span class="text-muted me-1" style="font-weight:600;">No.' + ord + '</span>' +
            esc(s.seq_id) +
          '</div>' +
          '<span class="pill">' + s.hits + '</span>' +
        '</div>' +
        '<div class="text-muted" style="font-size:.72rem;">' +
          (s.length_bp ? s.length_bp.toLocaleString() + ' bp · ' : '') +
          s.families + ' family' + (s.families === 1 ? '' : 'ies') + ' · ' +
          fmtBytes(s.size_bytes_tsv + s.size_bytes_json) +
          (s.top_family ? ' · top: ' + esc(s.top_family) : '') +
        '</div>';
      el.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#fileList a').forEach(a => a.classList.remove('active-file'));
        el.classList.add('active-file');
        loadSequence(s.seq_id);
      });
      root.appendChild(el);
    });
    if (!shown) root.innerHTML = '<div class="text-muted small p-2">No matches.</div>';
  }

  // ---- Right pane: fetch + render --------------------------------------
  const tt = document.getElementById('ttbox');
  const showTip = (e, html) => { tt.innerHTML = html; tt.style.opacity = 1;
    tt.style.left = (e.pageX + 14) + 'px'; tt.style.top = (e.pageY + 14) + 'px'; };
  const hideTip = () => { tt.style.opacity = 0; };

  // Holder for the current right-pane's species-filter listener so we can
  // detach it when the user switches to another sequence.
  let _paneSpeciesCleanup = null;

  async function loadSequence(seqId) {
    const pane = document.getElementById('rightPane');
    pane.innerHTML = '<div class="text-muted text-center py-5">' +
      '<div class="spinner-border spinner-border-sm me-2"></div>' +
      'Loading <span class="small-mono">' + esc(seqId) + '</span>...</div>';
    try {
      const url = '/api/get_result.php?job=' + encodeURIComponent(JOB_ID)
                + '&seq=' + encodeURIComponent(seqId);
      const res = await fetch(url);
      if (!res.ok) {
        const e = await res.json().catch(() => ({error: 'HTTP ' + res.status}));
        throw new Error(e.error || ('HTTP ' + res.status));
      }
      renderRightPane(await res.json());
    } catch (err) {
      pane.innerHTML = '<div class="alert alert-danger m-3">' +
        '<b>Failed to load:</b> ' + esc(err.message) + '</div>';
    }
  }

  function familyCountsFiltered(rows, motifSpecies) {
    const out = {};
    for (const r of rows) {
      if (!speciesFilter.passes(r.motif_id, motifSpecies)) continue;
      const f = r.family || '(unspecified)';
      out[f] = (out[f] || 0) + 1;
    }
    return out;
  }

  function renderRightPane(d) {
    // Detach previous pane's species listener before tearing down DOM.
    if (_paneSpeciesCleanup) { _paneSpeciesCleanup(); _paneSpeciesCleanup = null; }

    const pane = document.getElementById('rightPane');
    const motifSpecies = d.motif_species || {};

    // Tear down any DataTables left over from the previous sequence so the
    // pane.innerHTML reset doesn't orphan plugin state.
    if (window.jQuery && $.fn && $.fn.DataTable) {
      ['hitsTbl', 'trackTbl'].forEach(id => {
        if ($.fn.DataTable.isDataTable('#' + id)) {
          try { $('#' + id).DataTable().destroy(); } catch (e) {}
        }
      });
    }

    const famN  = Object.keys(d.family_counts || {}).length;
    const noHits = !d.rows.length;
    const maxBp  = d.length_bp || (d.rows.length ? Math.max(...d.rows.map(r => r.position)) + 50 : 1);

    const posFilterHtml = (tableId) =>
      '<div class="pp-pos-filter d-flex flex-wrap align-items-center gap-2 mb-2" data-target-tbl="' + tableId + '">' +
        '<span class="text-muted me-1"><i class="bi bi-funnel"></i> Position range:</span>' +
        '<input type="number" class="form-control form-control-sm pp-pos-min" min="0" max="' + maxBp + '" step="1" placeholder="min">' +
        '<span class="text-muted">to</span>' +
        '<input type="number" class="form-control form-control-sm pp-pos-max" min="0" max="' + maxBp + '" step="1" placeholder="max">' +
        '<span class="text-muted">bp (allowed: 0&ndash;' + maxBp + ')</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary pp-pos-reset"><i class="bi bi-x-circle"></i> Reset</button>' +
        '<span class="pp-pos-warn"></span>' +
      '</div>';

    // Column layout for Pattern Search differs by job era: Step 24+ jobs
    // get Source + PLACE Name columns inserted after Motif ID.
    const hitsThead = HAS_SPECIES_FILTER
      ? '<thead><tr><th>Motif ID</th><th>Source</th><th>PLACE Name</th><th>Family</th><th>Pos</th><th>Strand</th><th>Score</th><th>Hit</th></tr></thead>'
      : '<thead><tr><th>Motif ID</th><th>Family</th><th>Pos</th><th>Strand</th><th>Score</th><th>Hit</th></tr></thead>';
    const hitsPosCol = HAS_SPECIES_FILTER ? 4 : 2;

    pane.innerHTML =
      '<h5 class="mb-2">' + esc(d.seq_id) + ' <small class="text-muted">— ' +
        d.hits + ' hits, ' + famN + ' famil' + (famN === 1 ? 'y' : 'ies') +
        (d.length_bp ? ', ' + d.length_bp.toLocaleString() + ' bp' : '') +
      '</small></h5>' +
      '<ul class="nav nav-tabs" role="tablist">' +
        '<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-search" type="button"><i class="bi bi-list-ul"></i> Pattern Search</button></li>' +
        '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-track" type="button"><i class="bi bi-bezier"></i> Position Track</button></li>' +
        '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-family" type="button"><i class="bi bi-pie-chart"></i> Family</button></li>' +
        '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pmmap" type="button"><i class="bi bi-card-text"></i> Sequence Map</button></li>' +
      '</ul>' +
      '<div class="tab-content pt-3">' +
        '<div class="tab-pane fade show active" id="tab-search">' +
          (noHits
            ? '<div class="text-muted text-center py-5"><i class="bi bi-search" style="font-size:1.5rem;"></i><div class="mt-2">No TFBS hits in this sequence.</div></div>'
            : posFilterHtml('hitsTbl') +
              '<table class="table table-striped table-hover table-sm" id="hitsTbl" style="width:100%;">' +
                hitsThead +
                '<tbody></tbody>' +
              '</table>') +
          '<div class="mt-2 text-muted small">' +
            '<a href="/output/' + encodeURIComponent(JOB_ID) + '/' + encodeURIComponent(d.seq_id) + '.tsv" download>' +
              '<i class="bi bi-download"></i> Download this sequence (TSV)</a></div>' +
        '</div>' +
        '<div class="tab-pane fade" id="tab-track">' +
          (noHits
            ? '<div class="text-muted text-center py-5">No hits to plot.</div>'
            : '<div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">' +
                '<div class="form-check form-switch m-0">' +
                  '<input class="form-check-input" type="checkbox" id="trackShowAll">' +
                  '<label class="form-check-label small" for="trackShowAll"><i class="bi bi-eye"></i> Show all TFBS</label>' +
                '</div>' +
                '<div class="small text-muted">' +
                  '<span id="trackSelCount">0</span> selected · click rows to (de)select · drag on chart to brush a region' +
                  ' <button type="button" class="btn btn-link btn-sm p-0 ms-2 align-baseline" id="trackClear">Clear selection</button>' +
                '</div>' +
              '</div>' +
              '<div id="trackSvg" style="width:100%; min-height:240px;"></div>' +
              '<div id="trackLegend" class="pp-track-legend"><span class="text-muted small">No TFBS shown.</span></div>' +
              '<div id="trackSelOut" class="pp-track-selout"></div>' +
              '<div class="mt-3">' + posFilterHtml('trackTbl') +
                '<table class="table table-sm table-hover" id="trackTbl" style="width:100%;">' +
                  '<thead><tr><th>Pos</th><th>Strand</th><th>Motif ID</th><th>Family</th><th>Score</th><th>Hit</th></tr></thead>' +
                  '<tbody></tbody>' +
                '</table>' +
              '</div>') +
        '</div>' +
        '<div class="tab-pane fade" id="tab-family">' +
          '<div class="pp-family-toolbar d-flex flex-wrap gap-2 align-items-center mb-3">' +
            '<div class="btn-group btn-group-sm" role="group" aria-label="Family view mode" id="familyModeNav">' +
              '<button type="button" class="btn btn-pp-outline active" data-family-mode="donut"><i class="bi bi-pie-chart"></i> Donut</button>' +
              '<button type="button" class="btn btn-pp-outline" data-family-mode="treemap"><i class="bi bi-grid-3x3-gap"></i> TreeMap</button>' +
            '</div>' +
            '<span class="text-muted small ms-2" id="familyFilterNote"></span>' +
          '</div>' +
          '<div id="familyDonutWrap">' +
            '<div id="familyDonut" style="width:100%; min-height:380px;"></div>' +
            '<div id="familyShowAllWrap" class="mt-2"></div>' +
          '</div>' +
          '<div id="familyTreeMapWrap" style="display:none;">' +
            '<div id="familyTreeMap" style="width:100%; min-height:420px;"></div>' +
          '</div>' +
        '</div>' +
        '<div class="tab-pane fade" id="tab-pmmap">' +
          (noHits || !d.seq_string
            ? '<div class="text-muted small p-3"><i class="bi bi-info-circle"></i> ' +
                (noHits
                  ? 'No TFBS hits to plot on the sequence.'
                  : 'The original promoter sequence is not stored for this job (built before Sequence Map was added). Re-scan to enable.') +
              '</div>'
            : '<div class="row g-3">' +
                '<div class="col-lg-8">' +
                  '<div class="d-flex flex-wrap align-items-center gap-2 mb-2" style="font-size:.8rem;">' +
                    '<span class="text-muted" style="font-size:.72rem;">Tick TFBS on the right to colour them &middot; drag along a strand to list TFBS in a region</span>' +
                    '<span style="flex:1 1 auto;"></span>' +
                    '<label class="mb-0" style="cursor:pointer;"><input type="checkbox" id="seq-pmmap-showrev" checked style="vertical-align:middle;"> show reverse strand bases</label>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="seq-pmmap-clear" style="font-size:.75rem;">Clear all selections</button>' +
                  '</div>' +
                  '<div id="seq-pmmap-view"></div>' +
                  '<div id="seq-pmmap-legend" class="mt-2" style="font-size:.74rem; line-height:1.7; max-height:130px; overflow-y:auto;"></div>' +
                '</div>' +
                '<div class="col-lg-4">' +
                  '<h6 class="mb-2" style="font-weight:600;">TFBS list <span class="text-muted" style="font-weight:400;font-size:.8rem;">(' + Object.keys(d.family_counts || {}).length + ' families · ' + d.hits + ' hits)</span></h6>' +
                  '<input type="text" class="form-control form-control-sm mb-2" id="seq-pmmap-filter" placeholder="🔍 Filter by ID / family…">' +
                  '<div id="seq-pmmap-list" class="pmmap-list"></div>' +
                '</div>' +
              '</div>') +
        '</div>' +
      '</div>';

    // ---- Hits table: populate + DataTable + species custom search hook --
    let _hitsRowOrder = [];   // index -> motif_id of row at that dt index
    let hitsDt = null;
    function rebuildHitsTbody() {
      const tbody = pane.querySelector('#hitsTbl tbody');
      if (!tbody) return;
      tbody.innerHTML = '';
      _hitsRowOrder = [];
      d.rows.forEach((r, i) => {
        const tr = document.createElement('tr');
        tr.dataset.motifId = r.motif_id;
        const motifLink = '<a href="#" class="pp-matrix-link text-decoration-none"' +
          ' data-matrix="' + esc(r.motif_id) + '" title="Open TFBS info on PlantPAN (POST)">' +
          esc(r.motif_id) + ' <i class="bi bi-box-arrow-up-right" style="font-size:.7em;"></i></a>';
        const sourceCells = HAS_SPECIES_FILTER
          ? (r.source === 'PLACE'
              ? '<td><span class="pill pill-place">PLACE</span></td>' +
                '<td class="small-mono">' + (r.place_name ? '<code>' + esc(r.place_name) + '</code>' : '<span class="text-muted">—</span>') + '</td>'
              : '<td><span class="pill pill-pwm">PWM</span></td>' +
                '<td class="text-muted">—</td>')
          : '';
        tr.innerHTML =
          '<td class="small-mono">' + motifLink + '</td>' +
          sourceCells +
          '<td><span class="pill">' + esc(r.family) + '</span></td>' +
          '<td>' + r.position + '</td>' +
          '<td>' + (r.strand === '+'
            ? '<span style="color:var(--pp-red);font-weight:700;">+</span>'
            : '<span style="color:var(--pp-blue);font-weight:700;">-</span>') + '</td>' +
          '<td class="small-mono">' + esc(r.score || '') + '</td>' +
          '<td class="small-mono">' + esc(r.hit) + '</td>';
        tbody.appendChild(tr);
        _hitsRowOrder.push(r.motif_id);
      });
    }

    if (!noHits) {
      rebuildHitsTbody();

      // DataTable: defer init slightly so the tab pane has its real width.
      // Otherwise scrollX gets a 0-width measurement and headers misalign.
      setTimeout(() => {
        if (!$.fn.DataTable.isDataTable('#hitsTbl')) {
          const colDefs = HAS_SPECIES_FILTER
            ? [
                { targets: 4, className: 'text-center' },  // Pos
                { targets: 5, className: 'text-center' },  // Strand
                { targets: 6, className: 'text-center' },  // Score
              ]
            : [
                { targets: 2, className: 'text-center' },
                { targets: 3, className: 'text-center' },
                { targets: 4, className: 'text-center' },
              ];
          hitsDt = $('#hitsTbl').DataTable({
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100, 500],
            order: [[hitsPosCol, 'asc']],
            autoWidth: false,
            scrollX: true,           // long Hit column shouldn't push table past .pp-card
            columnDefs: colDefs,
          });
        } else {
          hitsDt = $('#hitsTbl').DataTable();
        }
        const fEl = pane.querySelector('.pp-pos-filter[data-target-tbl="hitsTbl"]');
        if (fEl) wirePosFilter(fEl, 'hitsTbl', hitsPosCol, maxBp);
      }, 50);
    }

    // Species filter hook for Pattern Search DataTable: reads motif_id
    // from the row's <tr data-motif-id="..."> via the index map we built.
    if (HAS_SPECIES_FILTER && !window._hitsTblSearchHookInstalled) {
      window._hitsTblSearchHookInstalled = true;
      $.fn.dataTable.ext.search.push(function(settings, data, idx) {
        if (settings.nTable.id !== 'hitsTbl') return true;
        if (!speciesFilter.isActive()) return true;
        const ctx = window._hitsTblCtx;
        if (!ctx) return true;
        const motifId = ctx.rowOrder[idx];
        if (!motifId) return true;
        return speciesFilter.passes(motifId, ctx.motifSpecies);
      });
    }
    window._hitsTblCtx = { rowOrder: _hitsRowOrder, motifSpecies };

    // Per-render state for the position track + track table.
    const trackState = { selected: new Set(), showAll: false, api: null };
    let trackInit = false, donutDrawn = false, treeMapDrawn = false, pmInit = false;
    let trackTblDt = null;

    pane.querySelectorAll('[data-bs-target]').forEach(btn => {
      btn.addEventListener('shown.bs.tab', () => {
        const t = btn.dataset.bsTarget;
        if (t === '#tab-track' && !trackInit && !noHits) {
          drawTrack(d, trackState, motifSpecies);
          trackTblDt = initTrackTable(d, trackState, maxBp, motifSpecies);
          wireTrackUI(trackState);
          trackInit = true;
        }
        if (t === '#tab-family' && !donutDrawn) {
          drawFamilyDonut(d, motifSpecies);
          donutDrawn = true;
        }
        if (t === '#tab-pmmap' && !pmInit && !noHits && d.seq_string && window.PMmap) {
          PMmap.init('seq', { seq: d.seq_string, hits: filterHitsBySpecies(d.rows, motifSpecies), dbMode: true });
          pmInit = true;
        }
      });
    });

    // Family sub-nav: Donut <-> TreeMap
    pane.querySelectorAll('#familyModeNav [data-family-mode]').forEach(btn => {
      btn.addEventListener('click', () => {
        pane.querySelectorAll('#familyModeNav [data-family-mode]').forEach(b =>
          b.classList.toggle('active', b === btn));
        const mode = btn.dataset.familyMode;
        const donutWrap = pane.querySelector('#familyDonutWrap');
        const treeWrap  = pane.querySelector('#familyTreeMapWrap');
        if (mode === 'treemap') {
          if (donutWrap) donutWrap.style.display = 'none';
          if (treeWrap)  treeWrap.style.display  = '';
          if (!treeMapDrawn) { drawFamilyTreeMap(d, motifSpecies); treeMapDrawn = true; }
        } else {
          if (donutWrap) donutWrap.style.display = '';
          if (treeWrap)  treeWrap.style.display  = 'none';
        }
      });
    });

    // ---- Species filter listener for THIS pane --------------------------
    function onSpeciesChange() {
      // Pattern Search: redraw the DataTable (search hook re-applies filter)
      if (hitsDt) hitsDt.draw(false);
      // Position Track: re-render with updated visible() filter
      if (trackInit && trackState.api) trackState.api.render();
      // Track table: redraw to re-apply filter (use track table's search hook)
      if (trackTblDt) trackTblDt.draw(false);
      // Family donut/treemap: rebuild from filtered family counts
      if (donutDrawn)  drawFamilyDonut(d, motifSpecies);
      if (treeMapDrawn) drawFamilyTreeMap(d, motifSpecies);
      // Sequence Map: re-init with filtered hits
      if (pmInit && d.seq_string && window.PMmap) {
        PMmap.init('seq', { seq: d.seq_string, hits: filterHitsBySpecies(d.rows, motifSpecies), dbMode: true });
      }
      // Family filter-note line
      const note = pane.querySelector('#familyFilterNote');
      if (note) {
        if (speciesFilter.isActive()) {
          const fc = familyCountsFiltered(d.rows, motifSpecies);
          const total = Object.values(fc).reduce((a, b) => a + b, 0);
          note.textContent = '(filtered: ' + total + ' hits across ' + Object.keys(fc).length + ' famil' + (Object.keys(fc).length === 1 ? 'y' : 'ies') + ')';
        } else {
          note.textContent = '';
        }
      }
    }
    window.addEventListener('pp:species-filter-changed', onSpeciesChange);

    // pp:layout-changed (sidebar toggle, future resize hooks): re-measure
    // and redraw every D3 chart that's been instantiated on this pane,
    // and re-adjust DataTable columns. Species filter state is preserved
    // because each draw function reads speciesFilter.isActive() itself.
    function onLayoutChange() {
      // With scrollX, columns.adjust() recalculates the scroll head/body widths
      // so the table fits the new panel width on sidebar collapse / expand.
      if (hitsDt)     { try { hitsDt.columns.adjust().draw(false); } catch (e) {} }
      if (trackTblDt) { try { trackTblDt.columns.adjust(); } catch (e) {} }
      // SVG charts cached W=clientWidth at first render — full redraw.
      if (trackInit)   drawTrack(d, trackState, motifSpecies);
      if (donutDrawn)  drawFamilyDonut(d, motifSpecies);
      if (treeMapDrawn) drawFamilyTreeMap(d, motifSpecies);
      if (pmInit && d.seq_string && window.PMmap) {
        PMmap.init('seq', { seq: d.seq_string, hits: filterHitsBySpecies(d.rows, motifSpecies), dbMode: true });
      }
    }
    window.addEventListener('pp:layout-changed', onLayoutChange);

    _paneSpeciesCleanup = () => {
      window.removeEventListener('pp:species-filter-changed', onSpeciesChange);
      window.removeEventListener('pp:layout-changed', onLayoutChange);
    };
  }

  function filterHitsBySpecies(rows, motifSpecies) {
    if (!speciesFilter.isActive()) return rows;
    return rows.filter(r => speciesFilter.passes(r.motif_id, motifSpecies));
  }

  // ---- D3: position track (selection-driven, with brush + guide) --------
  function drawTrack(d, state, motifSpecies) {
    const div = document.getElementById('trackSvg');
    div.innerHTML = '';
    const rows = d.rows || [], len = d.length_bp || 0;
    if (!rows.length) { div.innerHTML = '<p class="text-muted">No hits.</p>'; return; }
    const W = div.clientWidth || 700, H = 240;
    const M = {top:30, right:30, bottom:40, left:60};
    const yMid = (H - M.bottom + M.top) / 2;
    const maxPos = len || (d3.max(rows, r => r.position) + 50);
    const x = d3.scaleLinear().domain([0, maxPos]).range([M.left, W - M.right]);

    const svg = d3.select(div).append('svg')
      .attr('width', W).attr('height', H).style('cursor', 'crosshair');

    svg.append('g').attr('class', 'axis')
       .attr('transform', `translate(0,${H - M.bottom})`)
       .call(d3.axisBottom(x).ticks(8));
    svg.append('line').attr('x1', M.left).attr('x2', W - M.right)
       .attr('y1', yMid).attr('y2', yMid).attr('stroke', '#bbb');
    svg.append('text').attr('x', M.left - 8).attr('y', yMid - 12)
       .attr('text-anchor', 'end').style('font-size', '11px').text('+ strand');
    svg.append('text').attr('x', M.left - 8).attr('y', yMid + 16)
       .attr('text-anchor', 'end').style('font-size', '11px').text('- strand');

    const brushG = svg.append('g').attr('class', 'pp-track-brush');
    const brush = d3.brushX()
      .extent([[M.left, M.top], [W - M.right, H - M.bottom]])
      .on('brush end', onBrush);
    brushG.call(brush);

    const markers = svg.append('g').attr('class', 'pp-track-markers');
    const hits    = svg.append('g').attr('class', 'pp-track-hits');

    const guideLine = svg.append('line')
      .attr('y1', M.top).attr('y2', H - M.bottom)
      .attr('stroke', 'var(--pp-red)').attr('stroke-dasharray', '3,3')
      .style('opacity', 0).style('pointer-events', 'none');
    const guideText = svg.append('text').attr('y', M.top - 6)
      .style('font-size', '11px').style('font-weight', 'bold')
      .style('fill', 'var(--pp-red)').style('pointer-events', 'none')
      .style('opacity', 0);

    svg.on('mousemove', function(event) {
      const [mx, my] = d3.pointer(event, svg.node());
      if (mx < M.left || mx > W - M.right || my < M.top || my > H - M.bottom) {
        guideLine.style('opacity', 0); guideText.style('opacity', 0); return;
      }
      const bp = Math.round(x.invert(mx));
      const right = mx > (W - M.right - 50);
      guideLine.attr('x1', mx).attr('x2', mx).style('opacity', 1);
      guideText.attr('x', mx)
        .attr('text-anchor', right ? 'end' : 'start')
        .text(bp + ' bp').style('opacity', 1);
    }).on('mouseleave', function() {
      guideLine.style('opacity', 0); guideText.style('opacity', 0);
    });

    const emptyText = svg.append('text').attr('class', 'pp-track-empty')
      .attr('x', (M.left + (W - M.right)) / 2).attr('y', yMid)
      .attr('text-anchor', 'middle').attr('dominant-baseline', 'middle')
      .style('font-size', '12px').style('fill', '#999')
      .text('Click rows below to plot TFBS, or toggle "Show all TFBS"');

    const colour   = d3.scaleOrdinal(d3.schemeTableau10);
    const families = Array.from(new Set(rows.map(r => r.family)));
    const HIT_W    = 10;

    function sourceTagHtml(r) {
      if (r.source === 'PLACE') return ' <span style="color:#ffd166; font-weight:700;">(PLACE)</span>';
      if (r.source === 'PWM')   return ' <span style="color:#a8d4ff; font-weight:700;">(PWM)</span>';
      return '';
    }
    function tooltipHtml(r) {
      return '<b>' + esc(r.motif_id) + '</b>' + sourceTagHtml(r) + '<br>'
           + 'Family: ' + esc(r.family) + '<br>'
           + (r.place_name ? 'PLACE name: ' + esc(r.place_name) + '<br>' : '')
           + 'Position: ' + r.position + ' (' + r.strand + ')<br>'
           + 'Score: ' + esc(r.score || '—') + '<br>'
           + 'Seq: <span style="font-family:monospace">' + esc(r.hit) + '</span>';
    }
    function rowPasses(i) {
      return speciesFilter.passes(rows[i].motif_id, motifSpecies);
    }
    function visible() {
      const indices = state.showAll ? rows.map((_, i) => i) : Array.from(state.selected);
      return indices.filter(rowPasses);
    }
    function renderLegend(indices) {
      const legend = document.getElementById('trackLegend');
      if (!legend) return;
      const used = new Map();
      indices.forEach(i => {
        const fam = rows[i].family || '(unspecified)';
        if (!used.has(fam)) used.set(fam, colour(families.indexOf(rows[i].family)));
      });
      if (used.size === 0) {
        legend.innerHTML = '<span class="text-muted small">No TFBS shown — toggle &ldquo;Show all TFBS&rdquo;, click rows below, or adjust the species filter.</span>';
        return;
      }
      legend.innerHTML = Array.from(used.entries()).sort((a, b) => a[0].localeCompare(b[0]))
        .map(([fam, c]) =>
          '<span class="pp-legend-item"><span class="pp-legend-swatch" style="background:' + c + '"></span><span>' + esc(fam) + '</span></span>'
        ).join('');
    }
    function onBrush(event) {
      const out = document.getElementById('trackSelOut');
      if (!out) return;
      const sel = event && event.selection;
      if (!sel) { out.style.display = 'none'; out.innerHTML = ''; return; }
      const [bp0, bp1] = sel.map(x.invert).map(Math.round);
      const indices = visible();
      const inRange = indices.filter(i => rows[i].position >= bp0 && rows[i].position <= bp1);
      if (!inRange.length) {
        out.style.display = 'block';
        out.innerHTML = '<strong>Range: ' + bp0 + ' - ' + bp1 + ' bp</strong><br>' +
          '<span class="text-muted">No sites in this range' +
          (state.showAll ? '.' : ' (toggle <i>Show all TFBS</i> to scan every hit).') + '</span>';
        return;
      }
      const counts = {};
      inRange.forEach(i => { const t = rows[i].motif_id || '(unknown)'; counts[t] = (counts[t] || 0) + 1; });
      const items = Object.entries(counts).sort((a, b) => b[1] - a[1]);
      out.style.display = 'block';
      out.innerHTML = '<strong>Selected: ' + bp0 + ' - ' + bp1 + ' bp</strong>' +
        '<div class="mt-2 text-muted small">Total: ' + inRange.length + ' sites (' +
          items.length + ' unique motif' + (items.length === 1 ? '' : 's') + ')</div>' +
        '<ul class="mt-2">' +
          items.map(([id, c]) => '<li><b>' + esc(id) + '</b>: <code>' + c + '</code></li>').join('') +
        '</ul>';
    }
    function render() {
      const indices = visible();
      const data = indices.map(i => Object.assign({_idx: i}, rows[i]));
      const mJoin = markers.selectAll('rect').data(data, d => d._idx);
      mJoin.exit().remove();
      mJoin.enter().append('rect')
        .attr('width', 1.6).attr('height', 14).attr('pointer-events', 'none')
        .merge(mJoin)
          .attr('x', d => x(d.position))
          .attr('y', d => d.strand === '+' ? yMid - 18 : yMid + 4)
          .attr('fill', d => colour(families.indexOf(d.family)));
      const hJoin = hits.selectAll('rect').data(data, d => d._idx);
      hJoin.exit().remove();
      hJoin.enter().append('rect')
        .attr('width', HIT_W).attr('height', 18).attr('fill', 'transparent')
        .style('cursor', 'pointer')
        .on('mouseover', (e, d) => showTip(e, tooltipHtml(d)))
        .on('mouseout', hideTip)
        .merge(hJoin)
          .attr('x', d => x(d.position) - HIT_W / 2 + 0.8)
          .attr('y', d => d.strand === '+' ? yMid - 20 : yMid + 2);
      emptyText.style('display', data.length === 0 ? null : 'none');
      const sc = document.getElementById('trackSelCount');
      if (sc) sc.textContent = state.selected.size;
      renderLegend(indices);
      const cur = d3.brushSelection(brushG.node());
      if (cur) onBrush({selection: cur});
    }
    function clearBrush() {
      brushG.call(brush.move, null);
      const out = document.getElementById('trackSelOut');
      if (out) { out.style.display = 'none'; out.innerHTML = ''; }
    }
    state.api = { render, clearBrush };
    render();
  }

  // ---- Family Donut: top-12 slices + legend + Show all toggle -----------
  function drawFamilyDonut(d, motifSpecies) {
    const div = document.getElementById('familyDonut');
    if (!div) return;
    div.innerHTML = '';
    const famSrc = speciesFilter.isActive()
      ? familyCountsFiltered(d.rows, motifSpecies)
      : (d.family_counts || {});
    const total  = Object.values(famSrc).reduce((a, b) => a + b, 0);
    if (!Object.keys(famSrc).length || total === 0) {
      div.innerHTML = '<p class="text-muted">No data' + (speciesFilter.isActive() ? ' under the current species filter' : '') + '.</p>';
      const wrap = document.getElementById('familyShowAllWrap');
      if (wrap) wrap.innerHTML = '';
      return;
    }
    const W = div.clientWidth || 700, H = 380;
    const svg = d3.select(div).append('svg').attr('width', W).attr('height', H);
    const arr = Object.entries(famSrc).map(([n, v]) => ({name: n, value: v}))
                                       .sort((a, b) => b.value - a.value);
    const r = Math.min(W * .45, H * .45) / 2, cx = W * .32, cy = H / 2;
    const color = d3.scaleOrdinal(d3.schemeCategory10.concat(d3.schemeSet3));
    const arc = d3.arc().innerRadius(r * .55).outerRadius(r);
    const g = svg.append('g').attr('transform', `translate(${cx},${cy})`);
    g.selectAll('path')
      .data(d3.pie().value(x => x.value).sort(null)(arr))
      .enter().append('path')
      .attr('d', arc).attr('fill', (p, i) => color(i))
      .attr('stroke', '#fff').attr('stroke-width', 2).style('cursor', 'pointer')
      .on('mouseover', (e, p) => showTip(e,
        '<b>' + esc(p.data.name) + '</b><br>Hits: ' + p.data.value +
        '<br>' + (p.data.value / total * 100).toFixed(1) + '%'))
      .on('mouseout', hideTip);
    g.append('text').attr('text-anchor', 'middle').attr('y', -4)
      .style('font-size', '13px').style('fill', '#666').text('TF Family');
    g.append('text').attr('text-anchor', 'middle').attr('y', 16)
      .style('font-size', '22px').style('font-weight', '700')
      .style('fill', '#0c5198').text(arr.length);
    g.append('text').attr('text-anchor', 'middle').attr('y', 34)
      .style('font-size', '11px').style('fill', '#999')
      .text(arr.length === 1 ? 'family' : 'families');
    const top = arr.slice(0, 12), lx = cx + r + 30;
    top.forEach((p, i) => {
      const ly = cy - r + i * 22 + 8;
      svg.append('rect').attr('x', lx).attr('y', ly - 9)
         .attr('width', 13).attr('height', 13).attr('rx', 2).attr('fill', color(i));
      svg.append('text').attr('x', lx + 20).attr('y', ly + 2)
         .attr('class', 'legend-text').text(p.name + ' (' + p.value + ')');
    });
    if (arr.length > 12) svg.append('text').attr('x', lx).attr('y', cy - r + 12 * 22 + 8)
      .attr('class', 'legend-text').style('fill', '#999')
      .text('+ ' + (arr.length - 12) + ' more...');

    // Show all N families: collapsed legend (swatch + name + count + %).
    renderShowAllLegend(arr, total, color);
  }

  function renderShowAllLegend(arr, total, colorFn) {
    const wrap = document.getElementById('familyShowAllWrap');
    if (!wrap) return;
    const N = arr.length;
    wrap.innerHTML =
      '<button type="button" class="btn btn-sm btn-pp-outline" id="familyShowAllBtn" aria-expanded="false">' +
        '<i class="bi bi-chevron-down" id="familyShowAllChev"></i> ' +
        'Show all ' + N + ' famil' + (N === 1 ? 'y' : 'ies') +
      '</button>' +
      '<div id="familyShowAllBox" class="pp-family-legend-all mt-2" style="display:none;">' +
        arr.map((p, i) =>
          '<span class="pp-family-legend-item" title="' + esc(p.name) + '">' +
            '<span class="pp-family-legend-swatch" style="background:' + colorFn(i) + '"></span>' +
            '<span class="pp-family-legend-name">' + esc(p.name) + '</span>' +
            '<span class="text-muted pp-family-legend-meta">' +
              p.value + ' &middot; ' + (p.value / total * 100).toFixed(1) + '%' +
            '</span>' +
          '</span>'
        ).join('') +
      '</div>';
    const btn = document.getElementById('familyShowAllBtn');
    const box = document.getElementById('familyShowAllBox');
    const chev = document.getElementById('familyShowAllChev');
    if (!btn || !box) return;
    btn.addEventListener('click', () => {
      const open = box.style.display !== 'none';
      box.style.display = open ? 'none' : '';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (chev) chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    });
  }

  // ---- Family TreeMap (D3) ----------------------------------------------
  function drawFamilyTreeMap(d, motifSpecies) {
    const div = document.getElementById('familyTreeMap');
    if (!div) return;
    div.innerHTML = '';
    const famSrc = speciesFilter.isActive()
      ? familyCountsFiltered(d.rows, motifSpecies)
      : (d.family_counts || {});
    const total = Object.values(famSrc).reduce((a, b) => a + b, 0);
    if (!Object.keys(famSrc).length || total === 0) {
      div.innerHTML = '<p class="text-muted">No data' + (speciesFilter.isActive() ? ' under the current species filter' : '') + '.</p>';
      return;
    }
    const arr = Object.entries(famSrc).map(([n, v]) => ({name: n, value: v}))
                                       .sort((a, b) => b.value - a.value);
    const W = div.clientWidth || 700, H = 420;
    const color = d3.scaleOrdinal(d3.schemeCategory10.concat(d3.schemeSet3));
    const root = d3.hierarchy({children: arr}).sum(x => x.value);
    d3.treemap().size([W, H]).padding(2).round(true)(root);
    const svg = d3.select(div).append('svg').attr('width', W).attr('height', H);

    const leaves = svg.selectAll('g').data(root.leaves()).enter().append('g')
      .attr('transform', n => `translate(${n.x0},${n.y0})`)
      .style('cursor', 'pointer')
      .on('mouseover', (e, n) => showTip(e,
        '<b>' + esc(n.data.name) + '</b><br>Hits: ' + n.data.value +
        '<br>' + (n.data.value / total * 100).toFixed(1) + '%'))
      .on('mouseout', hideTip);

    leaves.append('rect')
      .attr('width',  n => n.x1 - n.x0)
      .attr('height', n => n.y1 - n.y0)
      .attr('fill',   (n, i) => color(i))
      .attr('stroke', '#fff');

    leaves.append('text')
      .attr('x', 6).attr('y', 14)
      .style('font-size', '11px')
      .style('font-weight', '600')
      .style('fill', '#fff')
      .style('pointer-events', 'none')
      .each(function(n) {
        const w = n.x1 - n.x0, h = n.y1 - n.y0;
        if (w < 36 || h < 22) { this.textContent = ''; return; }
        const name = n.data.name;
        // Truncate to fit cell width (~6px per char heuristic).
        const maxChars = Math.max(3, Math.floor((w - 10) / 6));
        this.textContent = name.length > maxChars ? name.slice(0, maxChars - 1) + '…' : name;
      });
    leaves.append('text')
      .attr('x', 6).attr('y', 28)
      .style('font-size', '10.5px')
      .style('fill', 'rgba(255,255,255,0.92)')
      .style('pointer-events', 'none')
      .each(function(n) {
        const w = n.x1 - n.x0, h = n.y1 - n.y0;
        if (w < 36 || h < 36) { this.textContent = ''; return; }
        this.textContent = (n.data.value / total * 100).toFixed(1) + '%';
      });
  }

  // ---- DataTables: reusable Position-range filter -----------------------
  if (window.jQuery && $.fn && $.fn.dataTable && !window.ppPosFilterInstalled) {
    window.ppPosFilterInstalled = true;
    window.ppPosFilters = window.ppPosFilters || {};
    $.fn.dataTable.ext.search.push(function(settings, data) {
      const cfg = window.ppPosFilters[settings.nTable.id];
      if (!cfg) return true;
      const pos = parseInt(data[cfg.posCol], 10);
      if (Number.isNaN(pos)) return true;
      if (cfg.min != null && pos < cfg.min) return false;
      if (cfg.max != null && pos > cfg.max) return false;
      return true;
    });
  }

  function wirePosFilter(rootEl, tableId, posCol, maxBp) {
    if (!rootEl || rootEl.dataset.wired === '1') return;
    rootEl.dataset.wired = '1';
    const $min   = $(rootEl).find('.pp-pos-min');
    const $max   = $(rootEl).find('.pp-pos-max');
    const $warn  = $(rootEl).find('.pp-pos-warn');
    const $reset = $(rootEl).find('.pp-pos-reset');
    function readInt($i) {
      const v = $i.val().trim(); if (v === '') return null;
      const n = parseInt(v, 10); return Number.isNaN(n) ? null : n;
    }
    function apply() {
      let mn = readInt($min), mx = readInt($max);
      const warns = [];
      if (mn !== null) {
        if (mn < 0)        { mn = 0;     $min.val(0);     warns.push('Min clamped to 0'); }
        else if (mn > maxBp) { mn = maxBp; $min.val(maxBp); warns.push('Min clamped to ' + maxBp); }
      }
      if (mx !== null) {
        if (mx < 0)        { mx = 0;     $max.val(0);     warns.push('Max clamped to 0'); }
        else if (mx > maxBp) { mx = maxBp; $max.val(maxBp); warns.push('Max clamped to ' + maxBp); }
      }
      if (mn !== null && mx !== null && mn > mx) {
        warns.push('Min > Max — filter not applied');
        $warn.text(warns.join('; '));
        delete window.ppPosFilters[tableId];
        if ($.fn.DataTable.isDataTable('#' + tableId)) $('#' + tableId).DataTable().draw();
        return;
      }
      $warn.text(warns.join('; '));
      if (mn === null && mx === null) delete window.ppPosFilters[tableId];
      else window.ppPosFilters[tableId] = { posCol, min: mn, max: mx };
      if ($.fn.DataTable.isDataTable('#' + tableId)) $('#' + tableId).DataTable().draw();
    }
    $min.on('change', apply);
    $max.on('change', apply);
    $reset.on('click', function() {
      $min.val(''); $max.val(''); $warn.text('');
      delete window.ppPosFilters[tableId];
      if ($.fn.DataTable.isDataTable('#' + tableId)) $('#' + tableId).DataTable().draw();
    });
  }

  // ---- Track table (DataTable + click-to-(de)select + drag) -------------
  function initTrackTable(d, state, maxBp, motifSpecies) {
    const tbl = document.getElementById('trackTbl');
    if (!tbl) return null;
    let dt = null;
    if (!$.fn.DataTable.isDataTable('#trackTbl')) {
      const tbody = tbl.querySelector('tbody');
      const rowOrder = [];
      (d.rows || []).forEach((r, i) => {
        const tr = document.createElement('tr');
        tr.dataset.rowIdx = i;
        tr.dataset.motifId = r.motif_id;
        tr.style.cursor = 'pointer';
        tr.innerHTML =
          '<td>' + r.position + '</td>' +
          '<td>' + (r.strand === '+'
            ? '<span style="color:var(--pp-red);font-weight:700;">+</span>'
            : '<span style="color:var(--pp-blue);font-weight:700;">-</span>') + '</td>' +
          '<td class="small-mono">' + esc(r.motif_id) + '</td>' +
          '<td>' + esc(r.family) + '</td>' +
          '<td class="small-mono">' + esc(r.score || '') + '</td>' +
          '<td class="small-mono">' + esc(r.hit) + '</td>';
        tbody.appendChild(tr);
        rowOrder.push(r.motif_id);
      });
      dt = $('#trackTbl').DataTable({
        pageLength: 10, lengthMenu: [10, 25, 50, 100],
        order: [[0, 'asc']], autoWidth: false,
        columnDefs: [{ targets: [0, 1, 4], className: 'text-center' }],
      });
      const fEl = document.querySelector('.pp-pos-filter[data-target-tbl="trackTbl"]');
      if (fEl) wirePosFilter(fEl, 'trackTbl', 0, maxBp);
      window._trackTblCtx = { rowOrder, motifSpecies };
      if (!window._trackTblSearchHookInstalled) {
        window._trackTblSearchHookInstalled = true;
        $.fn.dataTable.ext.search.push(function(settings, data, idx) {
          if (settings.nTable.id !== 'trackTbl') return true;
          if (!speciesFilter.isActive()) return true;
          const ctx = window._trackTblCtx;
          if (!ctx) return true;
          const motifId = ctx.rowOrder[idx];
          if (!motifId) return true;
          return speciesFilter.passes(motifId, ctx.motifSpecies);
        });
      }
    } else {
      dt = $('#trackTbl').DataTable();
      window._trackTblCtx = { rowOrder: (d.rows || []).map(r => r.motif_id), motifSpecies };
    }

    // Drag-to-(de)select on tbody (unchanged).
    let dragMode = null;
    const $tbody = $('#trackTbl tbody');
    function applyToRow(tr) {
      const idx = parseInt(tr.dataset.rowIdx, 10);
      if (Number.isNaN(idx)) return false;
      if (dragMode === 'select') {
        if (state.selected.has(idx)) return false;
        state.selected.add(idx); tr.classList.add('pp-track-selected'); return true;
      }
      if (dragMode === 'deselect') {
        if (!state.selected.has(idx)) return false;
        state.selected.delete(idx); tr.classList.remove('pp-track-selected'); return true;
      }
      return false;
    }
    $tbody.off('mousedown.ppTrack mouseenter.ppTrack');
    $tbody.on('mousedown.ppTrack', 'tr', function(e) {
      if (e.button !== 0) return;
      const idx = parseInt(this.dataset.rowIdx, 10);
      if (Number.isNaN(idx)) return;
      e.preventDefault();
      dragMode = state.selected.has(idx) ? 'deselect' : 'select';
      if (applyToRow(this) && state.api) state.api.render();
    });
    $tbody.on('mouseenter.ppTrack', 'tr', function() {
      if (dragMode === null) return;
      if (applyToRow(this) && state.api) state.api.render();
    });
    $(document).off('mouseup.ppTrack').on('mouseup.ppTrack', () => { dragMode = null; });
    $(window).off('blur.ppTrack').on('blur.ppTrack', () => { dragMode = null; });
    return dt;
  }

  function wireTrackUI(state) {
    const showAll = document.getElementById('trackShowAll');
    const clear   = document.getElementById('trackClear');
    if (showAll && !showAll.dataset.wired) {
      showAll.dataset.wired = '1';
      showAll.addEventListener('change', function() {
        state.showAll = this.checked;
        if (state.api) state.api.render();
      });
    }
    if (clear && !clear.dataset.wired) {
      clear.dataset.wired = '1';
      clear.addEventListener('click', function() {
        state.selected.clear();
        document.querySelectorAll('#trackTbl tbody tr.pp-track-selected')
          .forEach(tr => tr.classList.remove('pp-track-selected'));
        if (state.api) {
          if (state.api.clearBrush) state.api.clearBrush();
          state.api.render();
        }
      });
    }
  }

  document.getElementById('fileFilter').addEventListener('input',
    e => renderFileList(e.target.value));
  renderFileList();

  // ---- Sidebar collapse toggle (persisted in localStorage) -------------
  function setSidebarCollapsed(collapsed) {
    const left  = document.getElementById('leftCol');
    const right = document.getElementById('rightCol');
    const icon  = document.getElementById('sidebarToggleIcon');
    const label = document.getElementById('sidebarToggleLabel');
    if (!left || !right) return;
    if (collapsed) {
      left.classList.add('d-none');
      right.classList.remove('col-md-8');
      right.classList.add('col-md-12');
      if (icon)  icon.className  = 'bi bi-layout-sidebar';
      if (label) label.textContent = 'Show sequences';
    } else {
      left.classList.remove('d-none');
      right.classList.remove('col-md-12');
      right.classList.add('col-md-8');
      if (icon)  icon.className  = 'bi bi-layout-sidebar-inset';
      if (label) label.textContent = 'Hide sequences';
    }
    try { localStorage.setItem('pp_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    window.dispatchEvent(new Event('resize'));
    if (window.jQuery && $.fn && $.fn.DataTable) {
      try { $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust(); } catch (e) {}
    }
    // Notify D3 charts in the right pane that their container width changed.
    // Defer one frame so the new column width is laid out before we re-measure.
    requestAnimationFrame(() => {
      window.dispatchEvent(new CustomEvent('pp:layout-changed'));
    });
  }
  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    const cur = document.getElementById('leftCol').classList.contains('d-none');
    setSidebarCollapsed(!cur);
  });
  try {
    if (localStorage.getItem('pp_sidebar_collapsed') === '1') {
      setSidebarCollapsed(true);
    }
  } catch (e) {}

  // Initialize the species filter (renders dropdown + chips + notifies).
  speciesFilter.init();

  // Auto-open first sequence on page load.
  const first = document.querySelector('#fileList a');
  if (first) first.click();
  </script>

  <style>
    .active-file { background:#fffafa !important; border-left:3px solid var(--pp-red) !important; }
    #fileList a:hover { background:#f7f9fc; }
    /* Position track + range filter (parity with online's promoter_multiple_result) */
    .pp-track-legend {
      display:flex; flex-wrap:wrap; gap:4px 4px;
      padding:8px 10px; margin-top:8px;
      border-top:1px solid #eceff2; font-size:13px;
      max-height:160px; overflow-y:auto;
    }
    .pp-legend-item { display:inline-flex; align-items:center; margin-right:14px; margin-bottom:4px; line-height:1.2; }
    .pp-legend-swatch { display:inline-block; width:14px; height:10px; margin-right:5px; border:1px solid #888; }
    .pp-track-selout {
      margin-top:15px; padding:12px; background:#fdfdfd; border-radius:6px;
      border-left:5px solid var(--pp-red); font-size:14px;
      max-height:220px; overflow-y:auto;
      box-shadow:inset 0 1px 3px rgba(0,0,0,0.05); display:none;
    }
    .pp-track-selout ul { padding-left:20px; margin-bottom:0; }
    .pp-track-brush .selection {
      fill:var(--pp-red); fill-opacity:0.10;
      stroke:var(--pp-red); stroke-width:1px;
    }
    .pp-pos-filter {
      padding:8px 12px; background:#f7f9fb;
      border:1px solid var(--pp-border); border-radius:6px;
      font-size:.85rem;
    }
    .pp-pos-filter input[type="number"] { width:100px; }
    .pp-pos-warn:not(:empty) {
      display:inline-block; padding:2px 8px;
      background:#fff3cd; border-radius:4px; color:#856404;
    }
    #trackTbl tbody tr.pp-track-selected td { background:#fff3cd !important; font-weight:600; }
    #trackTbl tbody tr:hover td { background:#fdfdfd; }
    /* Pattern Search table: scrollX wrapper keeps overlong rows inside the card.
       The inner scrollbar uses neutral colours; only shows when truly needed. */
    #hitsTbl_wrapper .dataTables_scrollBody {
      border-bottom: 1px solid var(--pp-border);
    }
    #hitsTbl_wrapper .dataTables_scrollHead {
      background: #f0f3f7;
    }
    #hitsTbl_wrapper .dataTables_scrollBody::-webkit-scrollbar { height: 8px; }
    #hitsTbl_wrapper .dataTables_scrollBody::-webkit-scrollbar-thumb {
      background: #c8cdd2; border-radius: 4px;
    }
    #hitsTbl_wrapper .dataTables_scrollBody::-webkit-scrollbar-thumb:hover { background: var(--pp-red); }
    /* Family toolbar (Donut / TreeMap toggle) */
    .pp-family-toolbar .btn-pp-outline { border-color: var(--pp-border); }
    .pp-family-toolbar .btn-pp-outline.active { background: var(--pp-red); color: #fff; border-color: var(--pp-red); }
    #familyShowAllBtn { margin-top: 4px; }
    .pp-family-legend-all {
      display: flex; flex-wrap: wrap; gap: 6px 14px;
      padding: 10px 12px; background: #fafbfd;
      border: 1px solid var(--pp-border); border-radius: 6px;
      max-height: 260px; overflow-y: auto;
      font-size: 0.82rem; line-height: 1.4;
    }
    .pp-family-legend-item { display: inline-flex; align-items: center; gap: 6px; }
    .pp-family-legend-swatch { width: 14px; height: 14px; border-radius: 3px; display: inline-block; flex: 0 0 auto; border: 1px solid rgba(0,0,0,.1); }
    .pp-family-legend-name { font-weight: 500; color: var(--pp-text); }
    .pp-family-legend-meta { font-size: 0.72rem; margin-left: 2px; }
    /* Sequence Map (PMmap) — wrapped sequence viewer + per-TFBS checklist */
    #pm-region-popup, .pm-tip { position: absolute; z-index: 10010; }
    #pm-region-popup { background: #fff; color: #222; padding: 10px 14px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.18); font-size: 12px; max-width: 480px; max-height: 60vh; overflow: auto; display: none; }
    #pm-region-popup .pm-pop-close { position: absolute; top: 4px; right: 8px; cursor: pointer; color: #888; font-weight: bold; }
    #pm-region-popup table { border-collapse: collapse; margin-top: 5px; }
    #pm-region-popup th, #pm-region-popup td { padding: 2px 8px; border-bottom: 1px solid #eee; text-align: left; }
    .pm-tip { padding: 9px 11px; background: rgba(15,23,42,0.95); color: #fff; border-radius: 8px; font-size: 13px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); border: 1px solid #555; max-width: 360px; pointer-events: none; word-wrap: break-word; line-height: 1.5; opacity: 0; transition: opacity .15s; }
    [id$="-pmmap-view"] { width: 100%; min-height: 120px; max-height: 70vh; overflow-y: auto; overflow-x: hidden; background: #fff; border: 1px solid var(--pp-border); border-radius: 8px; padding: 10px 8px; }
    .pm-seq { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: 13px; }
    .pm-seqline { display: flex; align-items: flex-start; }
    .pm-gutter { flex: 0 0 auto; text-align: right; color: #999; font-size: 11px; line-height: 18px; padding-right: 8px; user-select: none; white-space: nowrap; }
    .pm-linebody { position: relative; flex: 1 1 auto; overflow: hidden; cursor: crosshair; user-select: none; -webkit-user-select: none; }
    .pm-seqtext { position: relative; z-index: 2; line-height: 18px; white-space: pre; letter-spacing: 0; color: #222; }
    .pm-revtext { position: relative; z-index: 2; line-height: 16px; white-space: pre; letter-spacing: 0; color: #8a8f98; background: rgba(0,0,0,0.035); }
    .pm-bars { height: 0; }
    .pm-bar { position: absolute; z-index: 1; pointer-events: none; border-radius: 1px; }
    .pm-selrange { position: absolute; z-index: 3; background: rgba(13,110,253,0.18); border-left: 1px solid #0d6efd; border-right: 1px solid #0d6efd; pointer-events: none; }
    .pm-legend-item { display: inline-flex; align-items: center; margin: 0 12px 4px 0; cursor: pointer; user-select: none; }
    .pm-legend-item:hover { text-decoration: underline; }
    .pm-legend-item.pm-dim { opacity: 0.35; }
    .pm-legend-swatch { width: 12px; height: 12px; border-radius: 2px; margin-right: 5px; display: inline-block; flex: 0 0 auto; }
    .pmmap-list { height: 70vh; min-height: 400px; overflow-y: auto; padding: 10px 12px; background: #fcfcfc; border: 1px solid var(--pp-border); border-radius: 8px; }
    .pmmap-list-item { display: block; font-size: 0.78rem; line-height: 1.45; margin-bottom: 7px; word-break: break-all; }
    .pmmap-list-label { display: block; cursor: pointer; }
    .pmmap-list-item input[type="checkbox"] { transform: scale(0.9); vertical-align: middle; cursor: pointer; }
    .pmmap-list-item a { color: var(--pp-blue); text-decoration: none; }
    .pmmap-list-item a:hover { text-decoration: underline; }
    .pmmap-list-item .text-muted { font-size: 0.92em; }
    .pmmap-detail { width: auto; margin: 3px 0 6px 22px; font-size: 0.72rem; border-collapse: collapse; cursor: auto; }
    .pmmap-detail th, .pmmap-detail td { padding: 1px 9px 1px 0; text-align: left; white-space: nowrap; vertical-align: top; }
    .pmmap-detail th { color: #555; border-bottom: 1px solid #ddd; font-weight: 600; }
    .pmmap-detail tr:nth-child(even) td { background: rgba(0,0,0,0.025); }
    .pmmap-detail code { font-size: 0.95em; color: #222; }
    .pm-loading { padding: 24px 12px; text-align: center; color: #888; font-size: 0.85rem; }
  </style>

<?php endif; ?>
<?php pp_footer(); ?>
