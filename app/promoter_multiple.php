<?php
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/job_utils.php';
pp_header('Promoter Analysis');
?>
<div class="pp-card">
  <h2><i class="bi bi-search"></i> Promoter Analysis</h2>
  <p>Submit promoter sequences in FASTA / multi-FASTA format (no sequence-count
     limit; the cap is on total nucleotide content). Each sequence's results are
     written to a separate file and rendered in a browseable file list. Results
     live inside the container — use <i>Download all (ZIP)</i> on the results
     page to keep them.</p>

  <form method="post" action="/promoter_multiple_result.php" id="scanForm">
    <label for="sequence" class="form-label fw-bold">Multi-FASTA input</label>
    <textarea id="sequence" name="sequence" class="form-control small-mono" rows="11"
              style="font-size:.9rem;"
              placeholder=">seq1
ACGT...
>seq2
ACGT..."></textarea>

    <div class="row g-2 mt-3">
      <div class="col-sm-3"><div class="pp-stat py-2">
        <div class="n" id="estSeqs" style="font-size:1.2rem;">0</div>
        <div class="l">Sequences</div></div></div>
      <div class="col-sm-3"><div class="pp-stat py-2">
        <div class="n" id="estBp" style="font-size:1.2rem;">0</div>
        <div class="l">Total bp (max <?= number_format(PP_MAX_TOTAL_BP) ?>)</div></div></div>
      <div class="col-sm-3"><div class="pp-stat py-2">
        <div class="n" id="estOut" style="font-size:1.2rem;">0 B</div>
        <div class="l">Estimated output</div></div></div>
      <div class="col-sm-3"><div class="pp-stat py-2">
        <div class="n" id="diskFree" style="font-size:1.2rem;">—</div>
        <div class="l">Disk free</div></div></div>
    </div>

    <div id="warnBox" class="alert alert-warning mt-3 d-none small mb-0"></div>

    <div class="mt-3">
      <button type="submit" class="btn btn-pp" id="submitBtn" disabled>
        <i class="bi bi-play-fill"></i> Run Multi-Scan
      </button>
      <a href="/jobs.php" class="btn btn-pp-outline ms-1">
        <i class="bi bi-list-ul"></i> Job history
      </a>
      <span class="text-muted small ms-2">
        Limits: <?= number_format(PP_MAX_SEQ_BP) ?> bp per sequence,
        <?= number_format(PP_MAX_TOTAL_BP) ?> bp total;
        input text up to <?= htmlspecialchars(pp_format_bytes(PP_MAX_TOTAL_INPUT_BYTES)) ?>;
        per-job output cap <?= htmlspecialchars(pp_format_bytes(PP_MAX_OUTPUT_BYTES_PER_JOB)) ?>.
      </span>
    </div>
  </form>
</div>

<script>
const LIMITS = {
  maxSeqs:         <?= PP_MAX_SEQUENCES ?>,
  maxSeqBp:        <?= PP_MAX_SEQ_BP ?>,
  maxTotalBp:      <?= PP_MAX_TOTAL_BP ?>,
  maxInputBytes:   <?= PP_MAX_TOTAL_INPUT_BYTES ?>,
  maxOutputBytes:  <?= PP_MAX_OUTPUT_BYTES_PER_JOB ?>,
  bytesPerBp:      <?= PP_BYTES_PER_BP_ESTIMATE ?>,
  reserveFraction: <?= PP_DISK_FREE_RESERVE_FRACTION ?>,
};
let DISK = null;

function fmtBytes(n) {
  if (n == null) return '—';
  if (n < 1024) return n + ' B';
  if (n < 1024*1024) return (n/1024).toFixed(1) + ' KB';
  if (n < 1024*1024*1024) return (n/1024/1024).toFixed(1) + ' MB';
  return (n/1024/1024/1024).toFixed(2) + ' GB';
}

function parseFasta(text) {
  const r = {nSeqs: 0, totalBp: 0, errors: []};
  if (!text.trim()) return r;
  // If no '>' at all, treat as a single anonymous sequence (matches
  // pp_write_input_fasta's "Input_sequence" fallback behaviour).
  const blocks = text.includes('>')
    ? text.split(/^>/m).filter(b => b.trim().length)
    : [text];
  for (const b of blocks) {
    const lines = b.split('\n');
    const name  = text.includes('>') ? lines[0].trim() : '(unnamed)';
    const seq   = (text.includes('>') ? lines.slice(1) : lines).join('').replace(/\s+/g, '');
    const bp    = seq.length;
    r.nSeqs++;
    r.totalBp += bp;
    if (bp > LIMITS.maxSeqBp) {
      r.errors.push("Sequence '" + (name || '(unnamed)') + "' is " +
                    bp.toLocaleString() + ' bp (limit ' +
                    LIMITS.maxSeqBp.toLocaleString() + ').');
    }
  }
  return r;
}

function refreshEstimate() {
  const text = document.getElementById('sequence').value;
  const r = parseFasta(text);
  document.getElementById('estSeqs').textContent = r.nSeqs;
  document.getElementById('estBp').textContent   = r.totalBp.toLocaleString();
  const estOut = r.totalBp * LIMITS.bytesPerBp;
  document.getElementById('estOut').textContent  = fmtBytes(estOut);

  const warns = [];
  if (r.totalBp > LIMITS.maxTotalBp) {
    warns.push('Total content ' + r.totalBp.toLocaleString() +
               ' bp exceeds the per-job limit of ' +
               LIMITS.maxTotalBp.toLocaleString() + ' bp (FASTA headers excluded).');
  }
  if (r.nSeqs > LIMITS.maxSeqs) {
    warns.push(r.nSeqs + ' sequences exceeds limit of ' +
               LIMITS.maxSeqs.toLocaleString() + '.');
  }
  if (text.length > LIMITS.maxInputBytes) {
    warns.push('Input text is ' + fmtBytes(text.length) + ' (limit ' +
               fmtBytes(LIMITS.maxInputBytes) + ').');
  }
  if (estOut > LIMITS.maxOutputBytes) {
    warns.push('Estimated output ' + fmtBytes(estOut) +
               ' exceeds per-job cap of ' + fmtBytes(LIMITS.maxOutputBytes) + '.');
  }
  if (DISK && DISK.free_bytes != null) {
    const threshold = DISK.free_bytes * (1 - LIMITS.reserveFraction);
    if (estOut > threshold) {
      warns.push('Estimated output would consume more than ' +
                 ((1 - LIMITS.reserveFraction) * 100).toFixed(0) +
                 '% of free disk (' + fmtBytes(DISK.free_bytes) + ' free).');
    }
  }
  warns.push(...r.errors);

  const wb = document.getElementById('warnBox');
  const btn = document.getElementById('submitBtn');
  if (warns.length) {
    wb.innerHTML = warns.map(w => '<i class="bi bi-exclamation-triangle"></i> ' + w).join('<br>');
    wb.classList.remove('d-none');
    btn.disabled = true;
  } else {
    wb.classList.add('d-none');
    btn.disabled = !text.trim();
  }
}

async function loadDiskStatus() {
  try {
    const res = await fetch('/api/disk_status.php');
    if (res.ok) {
      DISK = await res.json();
      const el = document.getElementById('diskFree');
      el.textContent = fmtBytes(DISK.free_bytes);
      if (DISK.used_by_jobs_bytes != null) {
        el.title = 'Existing jobs using: ' + fmtBytes(DISK.used_by_jobs_bytes);
      }
    }
  } catch (e) { /* fail silently — server-side caps still enforce */ }
  refreshEstimate();
}

document.getElementById('sequence').addEventListener('input', refreshEstimate);
loadDiskStatus();
</script>
<?php pp_footer(); ?>
