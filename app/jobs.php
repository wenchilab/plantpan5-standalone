<?php
/**
 * jobs.php — history of every Promoter Analysis job in this container.
 *
 * No auto-cleanup: jobs persist until the user deletes them here, or
 * the container is removed. The disk-usage bar at the top makes the
 * accumulation visible so the user can decide when to clear out.
 */
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/job_utils.php';

$err  = null;
$jobs = [];
$disk = null;
try {
    pp_ensure_output_root();
    $jobs = pp_list_jobs();
    $disk = pp_disk_status();
} catch (Throwable $e) {
    $err = $e->getMessage();
}

pp_header('Job History');
?>
<div class="pp-card">
  <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
    <div>
      <h2 class="mb-1"><i class="bi bi-clock-history"></i> Job History</h2>
      <p class="text-muted small mb-0">
        All Promoter Analysis jobs in this container. They live until you
        delete them — there is no automatic cleanup.
      </p>
    </div>
    <div class="mt-2">
      <a class="btn btn-pp-outline btn-sm" href="/promoter_multiple.php">
        <i class="bi bi-plus-circle"></i> New scan
      </a>
      <button class="btn btn-pp btn-sm ms-1" id="delAllBtn"
              <?= empty($jobs) ? 'disabled' : '' ?>>
        <i class="bi bi-trash"></i> Delete all
      </button>
    </div>
  </div>

  <?php if ($disk && $disk['total_bytes']): ?>
    <?php $pct = min(100, ($disk['used_by_jobs_bytes'] / max(1, $disk['total_bytes'])) * 100); ?>
    <div class="mb-3">
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span><i class="bi bi-hdd"></i> Output disk</span>
        <span>
          <?= htmlspecialchars(pp_format_bytes((int) $disk['used_by_jobs_bytes'])) ?> used by jobs ·
          <?= htmlspecialchars(pp_format_bytes((int) $disk['free_bytes'])) ?> free of
          <?= htmlspecialchars(pp_format_bytes((int) $disk['total_bytes'])) ?>
        </span>
      </div>
      <div class="progress" style="height:8px;">
        <div class="progress-bar" style="width:<?= number_format($pct, 2) ?>%; background:var(--pp-blue);"></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php elseif (empty($jobs)): ?>
    <div class="text-muted text-center py-5">
      <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
      <div class="mt-2">No jobs yet.
        <a href="/promoter_multiple.php">Run your first analysis</a>.
      </div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="pp-table">
        <thead>
          <tr>
            <th>Job ID</th>
            <th>Created</th>
            <th class="text-end">Seqs</th>
            <th class="text-end">Hits</th>
            <th class="text-end">Size</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $j):
              $jid     = (string) $j['job_id'];
              $created = (int) ($j['created_unix'] ?? 0);
          ?>
          <tr data-job-id="<?= htmlspecialchars($jid) ?>">
            <td class="small-mono">
              <a href="/promoter_multiple_result.php?job=<?= urlencode($jid) ?>"
                 class="text-decoration-none"><?= htmlspecialchars($jid) ?></a>
            </td>
            <td><?= htmlspecialchars($created ? date('Y-m-d H:i:s', $created) : '—') ?></td>
            <td class="text-end"><?= (int) ($j['sequence_count'] ?? 0) ?></td>
            <td class="text-end"><?= number_format((int) ($j['total_hits'] ?? 0)) ?></td>
            <td class="text-end"><?= htmlspecialchars(pp_format_bytes((int) ($j['dir_size_bytes'] ?? 0))) ?></td>
            <td>
              <a class="btn btn-pp-outline btn-sm" title="View"
                 href="/promoter_multiple_result.php?job=<?= urlencode($jid) ?>">
                <i class="bi bi-eye"></i>
              </a>
              <a class="btn btn-pp-outline btn-sm" title="Download ZIP"
                 href="/api/download_zip.php?job=<?= urlencode($jid) ?>">
                <i class="bi bi-download"></i>
              </a>
              <button class="btn btn-pp-outline btn-sm del-btn" title="Delete">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.del-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const tr  = btn.closest('tr');
    const jid = tr.dataset.jobId;
    if (!confirm('Delete job ' + jid + '?\n\nThis cannot be undone.')) return;
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('job', jid);
      const res = await fetch('/api/delete_job.php', {method:'POST', body: fd});
      const j   = await res.json();
      if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
      tr.style.transition = 'opacity .25s';
      tr.style.opacity = 0.2;
      setTimeout(() => tr.remove(), 260);
    } catch (e) {
      alert('Delete failed: ' + e.message);
      btn.disabled = false;
    }
  });
});

const delAllBtn = document.getElementById('delAllBtn');
if (delAllBtn) delAllBtn.addEventListener('click', async () => {
  if (!confirm('Delete ALL jobs in this container?\n\nThis cannot be undone.')) return;
  delAllBtn.disabled = true;
  try {
    const res = await fetch('/api/delete_all.php', {method:'POST'});
    const j   = await res.json();
    if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
    location.reload();
  } catch (e) {
    alert('Delete all failed: ' + e.message);
    delAllBtn.disabled = false;
  }
});
</script>

<?php pp_footer(); ?>
