<?php
require __DIR__ . '/includes/layout.php';
pp_header('PlantPAN 5 — Stand-alone Edition');
?>
<div class="pp-card">
  <h2><i class="bi bi-info-circle"></i> What this is</h2>
  <p>
    A self-contained local edition of PlantPAN 5's <strong>Promoter Analysis</strong>
    tool. Paste one or many promoter sequences in FASTA, we scan them against the
    PlantPAN legacy motif library on this machine, and you get back TFBS hits with their
    motif family, a position track, a family distribution chart, a per-base
    sequence map, and (when N&nbsp;&ge;&nbsp;2) a cross-promoter UpSet overview.
  </p>
  <div class="alert alert-info" style="margin: 14px 0 0;">
    <strong><i class="bi bi-shield-check"></i> Privacy:</strong> the container has no outbound
    network calls. Sequences you paste here never leave your computer. The image ships only
    the public PlantPAN legacy motif library and a slim motif&nbsp;→&nbsp;family lookup. Detailed TF annotation
    (gene IDs, ChIP-seq peaks, cross-species homologs, PPI) lives on the
    <a href="https://plantpan.itps.ncku.edu.tw" target="_blank">online site</a>; each result
    row links there for the full picture.
  </div>
</div>

<div class="pp-card">
  <h2><i class="bi bi-search"></i> Promoter Analysis</h2>
  <p>Submit promoter sequences in (multi-)FASTA format &mdash; there is no
     sequence-count cap, only a 100&nbsp;Mbp aggregate ceiling per job
     (FASTA headers excluded). Each sequence's results are written to a
     separate file and rendered in a browseable file list with Pattern
     Search, Position Track, TF Family (Donut + TreeMap), Sequence Map,
     and a job-wide species filter.</p>
  <a class="btn btn-pp" href="/promoter_multiple.php">Open <i class="bi bi-arrow-right"></i></a>
  <a class="btn btn-pp-outline ms-1" href="/jobs.php"><i class="bi bi-clock-history"></i> Past jobs</a>
</div>

<div class="pp-card">
  <h2><i class="bi bi-terminal"></i> Command-line use</h2>
  <p>The same scan engine is available as a CLI for pipelines:</p>
  <pre class="small-mono" style="background:#0f172a; color:#e6edf3; padding:14px 16px; border-radius:6px; overflow:auto;">
docker run --rm -v "$PWD":/work plantpan5-offline:latest \
    scan /work/input.fa &gt; /work/output.tsv</pre>
</div>
<?php pp_footer(); ?>
