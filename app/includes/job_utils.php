<?php
/**
 * job_utils.php
 *
 * Helpers for the multi-promoter "per-file" job architecture:
 *   - Job ID generation + strict validation (anti path-traversal)
 *   - Safe path construction inside the output root
 *   - Rate limiting (file-based, per-IP)
 *   - Disk status / size estimation
 *   - Atomic file writes
 *   - Job listing + deletion
 *
 * No auto-cleanup: jobs persist for the lifetime of the container.
 * Users delete via the UI (Delete / Delete all) on /jobs.php.
 *
 * NOTE: scan_engine.php pioneered the hard-coded /opt/plantpan/... path
 * convention. We follow the same pattern here, but allow env-var override
 * (PLANTPAN_OUTPUT_ROOT, PLANTPAN_RATELIMIT_DIR) to make local PHP-built-in-
 * server testing tractable when not running inside the container.
 */

declare(strict_types=1);

// ---- Limits -----------------------------------------------------------------

// Sequence-count cap is effectively disabled (100k entries is far
// beyond any realistic FASTA submission); the real gatekeeper is now
// PP_MAX_TOTAL_BP, which counts only the nucleotide content so users
// with thousands of short promoters aren't blocked by an arbitrary count.
const PP_MAX_SEQUENCES               = 100000;             // effectively unlimited
const PP_MAX_SEQ_BP                  = 50000;              // per sequence
const PP_MAX_TOTAL_BP                = 100000000;          // 100M bp aggregate (titles excluded)
const PP_MAX_TOTAL_INPUT_BYTES       = 100 * 1024 * 1024;  // 100 MB raw FASTA text
const PP_MAX_OUTPUT_BYTES_PER_JOB    = 5 * 1024 * 1024 * 1024; // 5 GB (= 100M bp * 50 B/bp, matches PP_MAX_TOTAL_BP)
const PP_MAX_OUTPUT_BYTES_PER_SEQ    = 100 * 1024 * 1024;  // 100 MB per seq cap
const PP_BYTES_PER_BP_ESTIMATE       = 700;                // ~700 B per bp (Step-24+ schema: row + JSON cache + motif_species lookup)
const PP_DISK_FREE_RESERVE_FRACTION  = 0.20;               // refuse if estimate > 80% of free

// ---- Rate limit -------------------------------------------------------------

const PP_RATELIMIT_WINDOW_SEC        = 60;
const PP_RATELIMIT_MAX_JOBS          = 5;

// ---- Path roots (env-overridable for local dev) -----------------------------

function pp_output_root(): string
{
    $env = getenv('PLANTPAN_OUTPUT_ROOT');
    return $env !== false && $env !== '' ? rtrim($env, '/') : '/var/www/html/output';
}

function pp_ratelimit_dir(): string
{
    $env = getenv('PLANTPAN_RATELIMIT_DIR');
    return $env !== false && $env !== '' ? rtrim($env, '/') : '/tmp/plantpan/ratelimit';
}

// ---- Job ID -----------------------------------------------------------------

/**
 * Format: YYYYMMDD_HHMMSS_<6 hex>. Uses cryptographic RNG for the suffix
 * so concurrent submits in the same second get distinct ids.
 */
function pp_make_job_id(): string
{
    $ts  = date('Ymd_His');
    $hex = bin2hex(random_bytes(3)); // 6 lowercase hex chars
    return $ts . '_' . $hex;
}

function pp_validate_job_id(string $id): bool
{
    return (bool) preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/', $id);
}

/**
 * Sequence id whitelist: alnum, dot, underscore, hyphen.
 * Explicitly reject any '..' segment or leading dot (avoid hidden files).
 */
function pp_validate_seq_id(string $id): bool
{
    if ($id === '' || strlen($id) > 200) return false;
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $id)) return false;
    if (str_contains($id, '..')) return false;
    if ($id[0] === '.') return false;
    return true;
}

// ---- Safe path construction -------------------------------------------------

/**
 * Build a path inside <output_root>/<job_id>/<relative>, refusing if any
 * component is malformed or if the resulting absolute path escapes the job
 * directory after canonicalization.
 *
 * Returns the absolute path on success, throws otherwise.
 * The path's parent directory is NOT required to exist; only the segments
 * before the final basename are realpath-checked.
 */
function pp_safe_job_path(string $job_id, string $relative = ''): string
{
    if (!pp_validate_job_id($job_id)) {
        throw new InvalidArgumentException('Invalid job id.');
    }

    $job_dir = pp_output_root() . '/' . $job_id;

    if ($relative === '') {
        return $job_dir;
    }

    // Reject absolute paths and any traversal segments outright.
    if ($relative[0] === '/' || str_contains($relative, '..')) {
        throw new InvalidArgumentException('Invalid relative path.');
    }

    // Each segment must be a valid seq-id-style token (or extension thereof).
    foreach (explode('/', $relative) as $seg) {
        if ($seg === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $seg) || $seg[0] === '.') {
            throw new InvalidArgumentException('Invalid path segment: ' . $seg);
        }
    }

    $full = $job_dir . '/' . $relative;

    // After construction, sanity check via realpath where possible.
    // (parent dir might not exist yet during write; only check what does.)
    $parent = dirname($full);
    if (is_dir($parent)) {
        $real_parent = realpath($parent);
        $real_root   = realpath(pp_output_root());
        if ($real_parent === false || $real_root === false
            || !str_starts_with($real_parent . '/', $real_root . '/')) {
            throw new RuntimeException('Path escapes output root.');
        }
    }

    return $full;
}

// ---- Output root preparation ------------------------------------------------

function pp_ensure_output_root(): void
{
    $root = pp_output_root();
    if (!is_dir($root)) {
        if (!@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create output root: ' . $root);
        }
    }
    if (!is_writable($root)) {
        throw new RuntimeException('Output root not writable: ' . $root);
    }
}

function pp_ensure_ratelimit_dir(): void
{
    $dir = pp_ratelimit_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// ---- Atomic write -----------------------------------------------------------

/**
 * Write to <path>.tmp then rename, so readers never see partial files.
 */
function pp_atomic_write(string $path, string $content): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temp file: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to atomically rename to: ' . $path);
    }
}

// ---- Rate limit -------------------------------------------------------------

/**
 * Returns [allowed: bool, retry_after_sec: int, current_count: int].
 * Uses a per-IP file holding newline-separated unix timestamps.
 * Prunes expired entries on every check.
 */
function pp_check_rate_limit(string $client_ip): array
{
    pp_ensure_ratelimit_dir();
    $key  = hash('sha256', $client_ip);
    $file = pp_ratelimit_dir() . '/' . $key . '.txt';

    $now    = time();
    $cutoff = $now - PP_RATELIMIT_WINDOW_SEC;

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Fail open if we can't even open the file; better than blocking users.
        return ['allowed' => true, 'retry_after_sec' => 0, 'current_count' => 0];
    }
    flock($fp, LOCK_EX);

    $raw   = stream_get_contents($fp);
    $stamps = $raw === false || $raw === '' ? [] : array_filter(
        array_map('intval', explode("\n", trim($raw))),
        fn($t) => $t > $cutoff
    );

    $allowed = count($stamps) < PP_RATELIMIT_MAX_JOBS;
    $retry   = 0;
    if (!$allowed && !empty($stamps)) {
        sort($stamps);
        $oldest = $stamps[0];
        $retry  = max(1, ($oldest + PP_RATELIMIT_WINDOW_SEC) - $now);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return [
        'allowed'         => $allowed,
        'retry_after_sec' => $retry,
        'current_count'   => count($stamps),
    ];
}

/**
 * Record that this IP just started a job. Call AFTER pp_check_rate_limit
 * said allowed=true.
 */
function pp_record_job_start(string $client_ip): void
{
    pp_ensure_ratelimit_dir();
    $key  = hash('sha256', $client_ip);
    $file = pp_ratelimit_dir() . '/' . $key . '.txt';
    $now  = time();

    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);

    $raw    = stream_get_contents($fp);
    $stamps = $raw === false || $raw === '' ? [] : array_filter(
        array_map('intval', explode("\n", trim($raw))),
        fn($t) => $t > $now - PP_RATELIMIT_WINDOW_SEC
    );
    $stamps[] = $now;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, implode("\n", $stamps) . "\n");
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---- Disk status ------------------------------------------------------------

function pp_disk_status(): array
{
    pp_ensure_output_root();
    $root  = pp_output_root();
    $free  = @disk_free_space($root);
    $total = @disk_total_space($root);
    $used_by_jobs = pp_dir_size($root);

    return [
        'free_bytes'         => $free === false ? null : (int) $free,
        'total_bytes'        => $total === false ? null : (int) $total,
        'used_by_jobs_bytes' => $used_by_jobs,
        'reserve_fraction'   => PP_DISK_FREE_RESERVE_FRACTION,
        'max_per_job_bytes'  => PP_MAX_OUTPUT_BYTES_PER_JOB,
    ];
}

/**
 * Recursive size; safe to call on a directory that might not exist.
 */
function pp_dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $total = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isFile()) $total += $f->getSize();
    }
    return $total;
}

// ---- Estimation -------------------------------------------------------------

/**
 * Upper-bound estimate of output size for a given total input bp.
 * Rule of thumb (measured): ~50 bytes of TSV+JSON per bp scanned.
 * This is intentionally generous so the warning fires early.
 */
function pp_estimate_output_bytes(int $total_bp): int
{
    return max(0, $total_bp) * PP_BYTES_PER_BP_ESTIMATE;
}

/**
 * Decide whether a job of the given estimated size should be allowed.
 * Returns [allowed: bool, reason: string].
 */
function pp_check_capacity(int $estimated_output_bytes): array
{
    if ($estimated_output_bytes > PP_MAX_OUTPUT_BYTES_PER_JOB) {
        return ['allowed' => false, 'reason' => sprintf(
            'Estimated output (%s) exceeds per-job hard cap (%s).',
            pp_format_bytes($estimated_output_bytes),
            pp_format_bytes(PP_MAX_OUTPUT_BYTES_PER_JOB)
        )];
    }

    $disk = pp_disk_status();
    $free = $disk['free_bytes'];
    if ($free !== null) {
        $threshold = (int) ($free * (1.0 - PP_DISK_FREE_RESERVE_FRACTION));
        if ($estimated_output_bytes > $threshold) {
            return ['allowed' => false, 'reason' => sprintf(
                'Not enough free disk: estimate %s, %s free (need to keep %d%% reserve).',
                pp_format_bytes($estimated_output_bytes),
                pp_format_bytes($free),
                (int) (PP_DISK_FREE_RESERVE_FRACTION * 100)
            )];
        }
    }

    return ['allowed' => true, 'reason' => ''];
}

function pp_format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $u = -1;
    $val = $bytes;
    do { $val /= 1024; $u++; } while ($val >= 1024 && $u < count($units) - 1);
    return sprintf('%.1f %s', $val, $units[$u]);
}

// ---- Job listing & deletion -------------------------------------------------

/**
 * List all jobs under the output root, newest first.
 * Each entry includes the raw manifest plus computed `dir_size_bytes`.
 * Jobs whose manifest is missing/corrupt are skipped (not surfaced as errors).
 */
function pp_list_jobs(): array
{
    $root = pp_output_root();
    if (!is_dir($root)) return [];

    $jobs = [];
    foreach (scandir($root) ?: [] as $entry) {
        if (!pp_validate_job_id($entry)) continue; // also filters '.', '..'
        $job_dir = $root . '/' . $entry;
        if (!is_dir($job_dir)) continue;
        $manifest_path = $job_dir . '/manifest.json';
        if (!is_file($manifest_path)) continue;

        $raw = @file_get_contents($manifest_path);
        if ($raw === false) continue;
        $m = json_decode($raw, true);
        if (!is_array($m)) continue;

        $m['job_id']         = $entry;
        $m['dir_size_bytes'] = pp_dir_size($job_dir);
        $jobs[] = $m;
    }

    usort($jobs, function ($a, $b) {
        return ($b['created_unix'] ?? 0) <=> ($a['created_unix'] ?? 0);
    });

    return $jobs;
}

/**
 * Recursively delete a single job directory. Strict validation up front;
 * realpath check after construction to refuse anything outside the root.
 * Returns true on success.
 */
function pp_delete_job(string $job_id): bool
{
    if (!pp_validate_job_id($job_id)) {
        throw new InvalidArgumentException('Invalid job id.');
    }
    $job_dir = pp_output_root() . '/' . $job_id;
    if (!is_dir($job_dir)) return false;

    $real     = realpath($job_dir);
    $realRoot = realpath(pp_output_root());
    if ($real === false || $realRoot === false
        || !str_starts_with($real . '/', $realRoot . '/')
        || $real === $realRoot) {
        throw new RuntimeException('Refusing to delete: path outside output root.');
    }

    pp_rrmdir($real);
    return !is_dir($real);
}

/**
 * Delete every job directory under the output root. Returns count deleted.
 */
function pp_delete_all_jobs(): int
{
    $n = 0;
    foreach (pp_list_jobs() as $j) {
        if (pp_delete_job($j['job_id'])) $n++;
    }
    return $n;
}

function pp_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else              @unlink($f->getPathname());
    }
    @rmdir($dir);
}

// ---- Client IP --------------------------------------------------------------

/**
 * Best-effort client IP. We do NOT trust X-Forwarded-For by default since
 * the offline container is not behind a reverse proxy in normal deployment.
 */
function pp_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
