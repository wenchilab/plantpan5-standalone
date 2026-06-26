<?php
/**
 * api/get_result.php
 *
 * AJAX endpoint that serves a single sequence's pre-computed result JSON
 * for the right pane of /promoter_multiple_result.php.
 *
 *   GET /api/get_result.php?job=<job_id>&seq=<seq_id>
 *
 * Both parameters are validated against strict whitelists before any
 * filesystem access. The actual file read uses pp_safe_job_path(), which
 * additionally realpath-checks that the resolved path is still inside
 * the configured output root — defense-in-depth against any path-traversal
 * vector we may have missed at the validation layer.
 *
 * On success: 200 + the raw <seq>.json that pp_scan_to_files() wrote.
 * On error:   4xx + a JSON envelope { error: "..." }.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/job_utils.php';

header('Content-Type: application/json; charset=utf-8');
// Each call should re-read the file: fresh deletes/edits must be visible.
header('Cache-Control: no-store, must-revalidate');

function fail(int $status, string $msg): void
{
    http_response_code($status);
    echo json_encode(['error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail(405, 'Method not allowed.');
}

$job = (string) ($_GET['job'] ?? '');
$seq = (string) ($_GET['seq'] ?? '');

if (!pp_validate_job_id($job)) fail(400, 'Invalid job id.');
if (!pp_validate_seq_id($seq)) fail(400, 'Invalid seq id.');

try {
    $path = pp_safe_job_path($job, $seq . '.json');
} catch (Throwable $e) {
    fail(400, 'Bad path.');
}

if (!is_file($path)) {
    fail(404, 'Result not found. Job may have been deleted.');
}

// Stream the raw file contents — avoids parsing then re-encoding, and
// keeps memory flat for large per-sequence results.
$fp = @fopen($path, 'rb');
if (!$fp) fail(500, 'Failed to open result file.');
fpassthru($fp);
fclose($fp);
