<?php
/**
 * api/disk_status.php
 *
 * Returns the input page's live "disk free / used / capacity" status, plus
 * the configured limits. Cheap to call; safe to poll on page load.
 *
 *   GET /api/disk_status.php
 *
 * Response shape:
 *   {
 *     free_bytes, total_bytes, used_by_jobs_bytes,
 *     reserve_fraction, max_per_job_bytes,
 *     max_sequences, max_seq_bp,
 *     max_total_input_bytes, bytes_per_bp_estimate
 *   }
 *
 * No authentication; this is a single-user offline app and the values are
 * not sensitive (just disk capacity numbers).
 */

declare(strict_types=1);

require __DIR__ . '/../includes/job_utils.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $status = pp_disk_status() + [
        'max_sequences'         => PP_MAX_SEQUENCES,
        'max_seq_bp'            => PP_MAX_SEQ_BP,
        'max_total_input_bytes' => PP_MAX_TOTAL_INPUT_BYTES,
        'bytes_per_bp_estimate' => PP_BYTES_PER_BP_ESTIMATE,
    ];
    echo json_encode($status);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
