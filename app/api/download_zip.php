<?php
/**
 * api/download_zip.php
 *
 * Streams an entire job directory as a single .zip:
 *   GET /api/download_zip.php?job=<job_id>
 *
 * Contents: per-sequence <seq_id>.tsv + manifest.json + cross_promoter.json,
 * arranged under a top-level <job_id>/ folder so unzipping doesn't dump
 * loose files into the user's CWD.
 *
 * Per-sequence <seq_id>.json files (the right-pane web cache) are NOT
 * shipped — they would inflate the ZIP without giving the user any data
 * they can't reconstruct from the TSVs or the manifest. Users who want
 * to re-render the interactive view should re-scan their input.
 *
 * Build strategy: PHP's ZipArchive only writes to a real filesystem path,
 * so we build a temp zip, send it, then unlink. For our caps (500 MB / job)
 * this is fine; a true streaming build would need ZipStream-PHP which we
 * don't ship.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/job_utils.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405); exit('Method not allowed.');
}

$job = (string) ($_GET['job'] ?? '');
if (!pp_validate_job_id($job)) {
    http_response_code(400); exit('Invalid job id.');
}

try {
    $jobDir = pp_safe_job_path($job);
} catch (Throwable $e) {
    http_response_code(400); exit('Bad path.');
}
if (!is_dir($jobDir)) {
    http_response_code(404); exit('Job not found.');
}

$tmp = tempnam(sys_get_temp_dir(), 'pp_zip_');
@unlink($tmp); // ZipArchive::CREATE refuses to overwrite an existing file
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
    http_response_code(500); exit('Cannot create zip.');
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($jobDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    // Skip stale .tmp files from interrupted atomic writes (defense).
    $base = $f->getBasename();
    if (str_ends_with($base, '.tmp') || strpos($base, '.tmp.') !== false) continue;

    // TSV-only bundle: keep <seq_id>.tsv + manifest.json + cross_promoter.json,
    // skip every other .json (the per-seq web cache).
    if (str_ends_with($base, '.json')
        && $base !== 'manifest.json'
        && $base !== 'cross_promoter.json') {
        continue;
    }

    $rel = substr($f->getPathname(), strlen($jobDir) + 1);
    // Forward slashes inside the zip (Windows compat for unzip tools).
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    $zip->addFile($f->getPathname(), $job . '/' . $rel);
}
$zip->close();

$size = filesize($tmp);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $job . '.zip"');
if ($size !== false) header('Content-Length: ' . $size);
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
