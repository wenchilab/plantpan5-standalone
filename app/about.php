<?php
require __DIR__ . '/includes/layout.php';
pp_header('About / Citation');
?>
<div class="pp-card">
  <h2><i class="bi bi-info-circle"></i> About PlantPAN 5 &mdash; Stand-alone Edition</h2>
  <p>
    A stand-alone Docker edition of PlantPAN promoter analysis tools, intended
    for use cases where higher sequence size needed or uploading sequences to a public server is not desirable
    (proprietary cultivars, embargoed data, air-gapped environments, automated pipelines).
  </p>
  <p>
    <strong>What is bundled:</strong>
  </p>
  <ul>
    <li>The <code>match</code> scanner binary.</li>
    <li>The <strong>PlantPAN legacy motif library</strong> (2,254 motifs) together with
        its slim motif&nbsp;&rarr;&nbsp;family and species metadata.</li>
  </ul>
</div>
<?php /* Citation card hidden until the companion publication is available.
<div class="pp-card">
  <h2><i class="bi bi-bookmark"></i> Citation</h2>
  <p>If this tool contributed to your work, please cite:</p>
  <blockquote style="border-left: 3px solid var(--pp-blue); padding-left: 14px; color: #444;">
    Chow, C.-N. <em>et al.</em> (2024). PlantPAN 5: an updated regulatory transcription
    factor database. <em>Nucleic Acids Research</em>.
  </blockquote>
  <p class="text-muted small mt-3" style="margin-bottom:0;">
    <i class="bi bi-info-circle"></i>
    If you used the stand-alone edition specifically, a separate citation will be
    added once the companion publication is available.
  </p>
</div>
*/ ?>

<div class="pp-card">
  <h2><i class="bi bi-people"></i> Maintained by</h2>
  <p>
    <strong>PBMB Lab</strong> &mdash; Plant Bioinformatics and Molecular Biology Laboratory<br>
    Principal Investigator: <strong>Prof. Wen-Chi Chang</strong><br>
    Institute of Tropical Plant Sciences and Microbiology, College of Biosciences and
    Biotechnology, National Cheng Kung University, Tainan, Taiwan (R.O.C.)
  </p>
  <p class="text-muted small" style="margin-bottom:0;">
    <i class="bi bi-envelope"></i> Prof. Wen-Chi Chang:
      <a href="mailto:sarah321@mail.ncku.edu.tw">sarah321@mail.ncku.edu.tw</a><br>
    <i class="bi bi-envelope"></i> PBMB Lab:
      <a href="mailto:wenchilab@gmail.com">wenchilab@gmail.com</a><br>
    <i class="bi bi-telephone"></i> Tel: +886-6-2757575 ext.&nbsp;58311<br>
    <i class="bi bi-geo-alt"></i> Address: (70101) No.&nbsp;1, University Road, Tainan City, Taiwan (R.O.C.)<br>
    <i class="bi bi-github"></i> Source:
      <a href="https://github.com/wenchilab/plantpan5-standalone" target="_blank">github.com/wenchilab/plantpan5-standalone</a>
  </p>
</div>
<?php pp_footer(); ?>
