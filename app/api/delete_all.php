<?php
/**
 * api/delete_all.php
 *
 * POST /api/delete_all.php
 *
 * Wipes every job directory under the output root. Used by the
 * "Delete all" button on /jobs.php (with a JS confirm() up front).
 *
 * Returns: { success: true, deleted: <int> }
 */

declare(strict_types=1);

require __DIR__ . '/../includes/job_utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only.']);
    exit;
}

try {
    $n = pp_delete_all_jobs();
    echo json_encode(['success' => true, 'deleted' => $n]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
