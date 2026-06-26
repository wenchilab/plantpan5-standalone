<?php
/**
 * api/delete_job.php
 *
 * POST /api/delete_job.php   body: job=<job_id>
 *
 * Deletes a single job directory recursively. Strict whitelist on job_id;
 * pp_delete_job() additionally realpath-checks that we're not about to
 * recurse outside the configured output root.
 *
 * POST-only on purpose — refuse GET so the URL can't be triggered by an
 * <img src> or a stray click. The offline app has no auth model, so this
 * is the only meaningful CSRF-style mitigation we apply.
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

$job = (string) ($_POST['job'] ?? '');
if (!pp_validate_job_id($job)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid job id.']);
    exit;
}

try {
    $deleted = pp_delete_job($job);
    if (!$deleted) {
        // Either dir wasn't there or rrmdir couldn't remove it.
        http_response_code(404);
        echo json_encode(['error' => 'Job not found or already deleted.']);
        exit;
    }
    echo json_encode(['success' => true, 'job_id' => $job]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
