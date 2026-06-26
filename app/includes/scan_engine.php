<?php
/**
 * scan_engine.php
 * Wraps `match` (PWM scanner), parses output, joins with the slim
 * motif->family lookup. Since Step 24 (PLACE integration) the engine
 * runs match TWICE in pp_scan_to_files() / pp_scan_promoters() — once
 * against the PWM library, once against the PLACE pattern library —
 * and merges the rows. Each row carries `source` ("PWM" | "PLACE") and
 * `place_name` (only set for PLACE rows). PLACE rows use the synthetic
 * family label PLACE_FAMILY_LABEL so the family donut/treemap can show
 * them as one slice.
 *
 * Output schema mirrors the live site's .allTFBS.txt format. TF Name
 * and TF ID intentionally LEFT BLANK because the offline build does
 * NOT ship gene/locus mappings.
 */

declare(strict_types=1);

const PLANTPAN_BIN   = '/opt/plantpan/bin';
const PLANTPAN_DATA  = '/opt/plantpan/data';
const MATCH_BIN      = PLANTPAN_BIN . '/match';
const MATRIX_FILE    = PLANTPAN_DATA . '/PlantPAN3_v2_matrix_with_TF.dat';
const PROFILE_FILE   = PLANTPAN_DATA . '/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf';
const FAMILY_JSON    = PLANTPAN_DATA . '/motif_family.json';
const PLACE_FILE     = PLANTPAN_DATA . '/pattern_seq_uniprot_place.dat';
const PLACE_PROFILE  = PLANTPAN_DATA . '/pattern_seq_uniprot_place.dat.minFP.prf';
const PLACE_META     = PLANTPAN_DATA . '/place_meta.json';

const PWM_FAMILY_FALLBACK     = '(unannotated)';
const PLACE_FAMILY_LABEL      = '(Motif sequence only)';
const UNCHARACTERIZED_SPECIES = '(uncharacterized species)';

function pp_load_motif_family(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!is_file(FAMILY_JSON)) { $cache = []; return $cache; }
    $j = json_decode((string) file_get_contents(FAMILY_JSON), true);
    $cache = $j['motifs'] ?? [];
    return $cache;
}

function pp_load_place_meta(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!is_file(PLACE_META)) { $cache = []; return $cache; }
    $j = json_decode((string) file_get_contents(PLACE_META), true);
    $cache = $j['motifs'] ?? [];
    return $cache;
}

function pp_write_input_fasta(string $raw, string $tmpdir): string
{
    $raw = trim($raw);
    if ($raw === '') throw new InvalidArgumentException('Empty input.');
    if (!str_starts_with($raw, '>')) $raw = ">Input_sequence\n" . $raw;

    $entries = preg_split('/\n(?=>)/', $raw);
    if (count($entries) === 0) throw new InvalidArgumentException('No FASTA entries.');

    $clean = [];
    $seen = [];
    foreach ($entries as $i => $entry) {
        $lines = explode("\n", $entry);
        $hdr   = trim(array_shift($lines) ?: '');
        if ($hdr === '' || $hdr[0] !== '>') $hdr = '>seq_' . ($i + 1);
        $name = ltrim($hdr, '>');
        $name = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $name) ?: ('seq_' . ($i + 1));
        if (isset($seen[$name])) $name .= '_' . ($i + 1);
        $seen[$name] = true;

        $seq = strtoupper(preg_replace('/\s+/', '', implode('', $lines)) ?: '');
        if (!preg_match('/^[ACGTRYSWKMBDHVN]+$/', $seq)) {
            throw new InvalidArgumentException("Sequence '$name' contains non-IUPAC characters.");
        }
        if (strlen($seq) < 6)     throw new InvalidArgumentException("Sequence '$name' is too short (<6 bp).");
        if (strlen($seq) > 50000) throw new InvalidArgumentException("Sequence '$name' is too long (>50000 bp).");
        $clean[] = ">$name\n" . chunk_split($seq, 60, "\n");
    }
    // Use PP_MAX_SEQUENCES from job_utils.php when present (multi-promoter
    // path); otherwise fall back to the historical limit (single-promoter
    // path which doesn't include job_utils).
    $max = defined('PP_MAX_SEQUENCES') ? PP_MAX_SEQUENCES : 50;
    if (count($clean) > $max) throw new InvalidArgumentException("Maximum $max sequences per submission.");

    $path = tempnam($tmpdir, 'pp_seq_');
    file_put_contents($path, implode("\n", $clean) . "\n");
    return $path;
}

/**
 * Run a single `match` pass and parse the output into a
 *   [seq_id => list<{motif_id, position, strand, score, hit}>]
 * map. Used by both the CLI scan path and the multi-promoter file
 * writer; called twice per scan (once per matrix file).
 *
 * Annotation, source-tagging and family mapping are NOT applied here —
 * that's the caller's job, because the lookup tables differ between
 * PWM and PLACE.
 */
function pp_run_match_pass(string $matrix_file, string $profile_file, string $fasta_path, string $tmpdir): array
{
    if (!is_executable(MATCH_BIN)) {
        throw new RuntimeException('match binary missing at ' . MATCH_BIN);
    }
    if (!is_file($matrix_file) || !is_file($profile_file)) {
        throw new RuntimeException('match library missing: ' . $matrix_file . ' or ' . $profile_file);
    }
    $out = tempnam($tmpdir, 'pp_match_out_');
    $cmd = sprintf('%s %s %s %s %s 2>&1',
        escapeshellarg(MATCH_BIN), escapeshellarg($matrix_file),
        escapeshellarg($fasta_path), escapeshellarg($out),
        escapeshellarg($profile_file));
    exec($cmd, $stderr, $rc);
    if ($rc !== 0) {
        @unlink($out);
        throw new RuntimeException('match exited with code ' . $rc . ': ' . implode("\n", $stderr));
    }
    $raw = file_get_contents($out) ?: '';
    @unlink($out);

    $result = [];
    $blocks = explode("Inspecting sequence ID   ", $raw);
    array_shift($blocks);
    foreach ($blocks as $block) {
        $lines  = explode("\n", $block);
        $seq_id = trim(array_shift($lines) ?: '');
        if ($seq_id === '') continue;
        if (!isset($result[$seq_id])) $result[$seq_id] = [];
        foreach ($lines as $ln) {
            $parts = explode('|', $ln);
            if (count($parts) < 5) continue;
            $motif_id = trim($parts[0]);
            if ($motif_id === '') continue;
            if (!preg_match('/(\d+)\s*\(([-+])\)/', $parts[1], $m)) continue;
            // match output: motif_id | pos(strand) | core_score | matrix_score | hit
            // Matrix similarity score (parts[3]) is the "Score" column PlantPAN displays.
            $result[$seq_id][] = [
                'motif_id' => $motif_id,
                'position' => (int) $m[1],
                'strand'   => $m[2],
                'score'    => isset($parts[3]) ? trim($parts[3]) : '',
                'hit'      => trim($parts[4]),
            ];
        }
    }
    return $result;
}

/**
 * CLI-facing scan: runs both PWM + PLACE passes, returns a flat list
 * of rows (each annotated with source/place_name/species/family) plus
 * stats. Used by app/bin/plantpan-scan to emit TSV to stdout.
 *
 * Species are stored per row (not via lookup table) because CLI output
 * has no separate "header" channel to put a lookup map in.
 */
function pp_scan_promoters(string $fasta_path, string $tmpdir): array
{
    $family_lookup = pp_load_motif_family();
    $place_lookup  = pp_load_place_meta();

    $pwm_by_seq   = pp_run_match_pass(MATRIX_FILE, PROFILE_FILE, $fasta_path, $tmpdir);
    $place_by_seq = pp_run_match_pass(PLACE_FILE, PLACE_PROFILE, $fasta_path, $tmpdir);

    // Preserve input seq order: PWM blocks first, then any seq seen only in PLACE
    $all_seq_ids = [];
    foreach (array_keys($pwm_by_seq) as $s) $all_seq_ids[$s] = true;
    foreach (array_keys($place_by_seq) as $s) $all_seq_ids[$s] = true;

    $rows = [];
    $motifs_seen = [];
    foreach (array_keys($all_seq_ids) as $sid) {
        foreach (($pwm_by_seq[$sid] ?? []) as $r) {
            $meta    = $family_lookup[$r['motif_id']] ?? null;
            $species = $meta['species'] ?? [];
            if (!$species) $species = [UNCHARACTERIZED_SPECIES];
            $rows[] = [
                'seq_id'        => $sid,
                'motif_id'      => $r['motif_id'],
                'position'      => $r['position'],
                'strand'        => $r['strand'],
                'score'         => $r['score'],
                'hit'           => $r['hit'],
                'family'        => $meta['family'] ?? PWM_FAMILY_FALLBACK,
                'species_count' => count($species),
                'source'        => 'PWM',
                'place_name'    => null,
                'species'       => $species,
            ];
            $motifs_seen[$r['motif_id']] = true;
        }
        foreach (($place_by_seq[$sid] ?? []) as $r) {
            $meta    = $place_lookup[$r['motif_id']] ?? null;
            $species = $meta['species'] ?? [];
            if (!$species) $species = [UNCHARACTERIZED_SPECIES];
            $rows[] = [
                'seq_id'        => $sid,
                'motif_id'      => $r['motif_id'],
                'position'      => $r['position'],
                'strand'        => $r['strand'],
                'score'         => $r['score'],
                'hit'           => $r['hit'],
                'family'        => PLACE_FAMILY_LABEL,
                'species_count' => count($species),
                'source'        => 'PLACE',
                'place_name'    => $meta['place_name'] ?? null,
                'species'       => $species,
            ];
            $motifs_seen[$r['motif_id']] = true;
        }
    }
    return [
        'rows'  => $rows,
        'stats' => [
            'total_hits'      => count($rows),
            'sequences'       => count($all_seq_ids),
            'distinct_motifs' => count($motifs_seen),
        ],
    ];
}

/**
 * Returns the canonical TFBS info URL on the online PlantPAN site.
 *
 * NOTE: TFBS_info.php only reads $_POST['matrix']; the URL itself takes no
 * query string. Callers that want to open this page in a browser must
 * POST a form (see window.ppPostToOnline in promoter_multiple_result.php).
 * The $motif_id parameter is accepted for backward compatibility but
 * intentionally ignored.
 */
function pp_online_annotation_url(string $motif_id = ''): string
{
    return 'https://plantpan.itps.ncku.edu.tw/plantpan5/TFBS_info.php';
}

/**
 * Parse a sanitized multi-FASTA file into a [seq_id => length_bp] map.
 * The FASTA at $path is expected to have been written by pp_write_input_fasta(),
 * so headers are already in the safe character set.
 */
function pp_parse_fasta_lengths(string $path): array
{
    $out = [];
    foreach (pp_parse_fasta_seqs($path) as $sid => $seq) {
        $out[$sid] = strlen($seq);
    }
    return $out;
}

/**
 * Parse a sanitized multi-FASTA file into a [seq_id => seq_string] map.
 * The seq_string is the cleaned, uppercase nucleotide sequence (no whitespace).
 * Used by the per-sequence Sequence Map module to render the actual bases.
 */
function pp_parse_fasta_seqs(string $path): array
{
    $text = @file_get_contents($path);
    if ($text === false) throw new RuntimeException('Cannot read FASTA: ' . $path);
    $out = [];
    foreach (preg_split('/^>/m', $text, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        $lines = explode("\n", $entry);
        $sid   = trim(array_shift($lines) ?: '');
        if ($sid === '') continue;
        $sid   = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', $sid);
        $seq   = strtoupper(preg_replace('/\s+/', '', implode('', $lines)) ?: '');
        $out[$sid] = $seq;
    }
    return $out;
}

/**
 * Per-file architecture entry point used by /promoter_multiple_result.php.
 *
 *   $fasta_path : pre-sanitized multi-FASTA (output of pp_write_input_fasta)
 *   $job_id     : validated job id (YYYYMMDD_HHMMSS_hex)
 *   $tmpdir     : scratch directory for match's intermediate output
 *
 * Side effects:
 *   - Creates <output_root>/<job_id>/ if it doesn't exist.
 *   - Atomically writes <seq_id>.tsv and <seq_id>.json for every input
 *     sequence (including ones with zero hits, so the browser shows all).
 *   - Atomically writes cross_promoter.json with matrix-level set
 *     membership (now also carrying species + source per matrix).
 *   - Does NOT write manifest.json; the caller does that.
 *
 * Returns the manifest data structure (caller atomic-writes it as JSON).
 * Manifest now includes `species_universe`: the union of species across
 * every motif that produced at least one hit in this job.
 */
function pp_scan_to_files(string $fasta_path, string $job_id, string $tmpdir): array
{
    if (!function_exists('pp_validate_job_id')) {
        require_once __DIR__ . '/job_utils.php';
    }
    if (!pp_validate_job_id($job_id)) {
        throw new InvalidArgumentException('Invalid job id.');
    }

    $job_dir = pp_safe_job_path($job_id);
    if (!is_dir($job_dir) && !@mkdir($job_dir, 0775, true) && !is_dir($job_dir)) {
        throw new RuntimeException('Cannot create job directory: ' . $job_dir);
    }

    $seq_strings = pp_parse_fasta_seqs($fasta_path);
    $seq_lengths = array_map('strlen', $seq_strings);
    $total_bp    = (int) array_sum($seq_lengths);

    // Step 25 — total nucleotide content cap. Counted from the parsed
    // sequence strings so FASTA headers / whitespace don't count.
    if (defined('PP_MAX_TOTAL_BP') && $total_bp > PP_MAX_TOTAL_BP) {
        throw new RuntimeException(sprintf(
            'Total sequence content is %s bp; the maximum allowed per job is %s bp '
          . '(FASTA headers excluded). Split the submission into smaller batches.',
            number_format($total_bp), number_format(PP_MAX_TOTAL_BP)
        ));
    }

    foreach ($seq_lengths as $sid => $len) {
        if (!pp_validate_seq_id($sid)) {
            throw new RuntimeException("Sequence id '$sid' fails strict validation.");
        }
        if ($len * PP_BYTES_PER_BP_ESTIMATE > PP_MAX_OUTPUT_BYTES_PER_SEQ) {
            throw new RuntimeException(
                "Sequence '$sid' exceeds per-sequence size cap ("
                . pp_format_bytes(PP_MAX_OUTPUT_BYTES_PER_SEQ) . ').'
            );
        }
    }
    $cap = pp_check_capacity($total_bp * PP_BYTES_PER_BP_ESTIMATE);
    if (!$cap['allowed']) throw new RuntimeException($cap['reason']);

    $family_lookup = pp_load_motif_family();
    $place_lookup  = pp_load_place_meta();

    // Two match passes: PWM library + PLACE library.
    $pwm_by_seq   = pp_run_match_pass(MATRIX_FILE,  PROFILE_FILE,  $fasta_path, $tmpdir);
    $place_by_seq = pp_run_match_pass(PLACE_FILE,   PLACE_PROFILE, $fasta_path, $tmpdir);

    $sequences_summary  = [];
    $total_hits         = 0;
    $all_motifs_seen    = [];
    $total_output_bytes = 0;
    $total_tsv_bytes    = 0;
    $total_json_bytes   = 0;
    // Cross-promoter tracking: motif_id => [seq_id => true] (set semantics)
    $motif_to_seqs      = [];
    // motif_id => ['family' => ..., 'species' => [...], 'source' => 'PWM'|'PLACE']
    $motif_meta_seen    = [];

    // Iterate in FASTA input order so the result manifest preserves it.
    foreach ($seq_lengths as $seq_id => $len) {
        $rows              = [];
        $fam_counts        = [];
        $motif_species_seq = [];  // per-seq lookup map: motif_id => [species...]

        foreach (($pwm_by_seq[$seq_id] ?? []) as $r) {
            $meta    = $family_lookup[$r['motif_id']] ?? null;
            $species = $meta['species'] ?? [];
            if (!$species) $species = [UNCHARACTERIZED_SPECIES];
            $family  = $meta['family'] ?? PWM_FAMILY_FALLBACK;
            $rows[] = [
                'seq_id'        => $seq_id,
                'motif_id'      => $r['motif_id'],
                'position'      => $r['position'],
                'strand'        => $r['strand'],
                'score'         => $r['score'],
                'hit'           => $r['hit'],
                'family'        => $family,
                'species_count' => count($species),
                'source'        => 'PWM',
                'place_name'    => null,
            ];
            $fam_counts[$family] = ($fam_counts[$family] ?? 0) + 1;
            $motif_species_seq[$r['motif_id']] = $species;
            $all_motifs_seen[$r['motif_id']] = true;
            $motif_to_seqs[$r['motif_id']][$seq_id] = true;
            if (!isset($motif_meta_seen[$r['motif_id']])) {
                $motif_meta_seen[$r['motif_id']] = [
                    'family'  => $family,
                    'species' => $species,
                    'source'  => 'PWM',
                ];
            }
        }
        foreach (($place_by_seq[$seq_id] ?? []) as $r) {
            $meta    = $place_lookup[$r['motif_id']] ?? null;
            $species = $meta['species'] ?? [];
            if (!$species) $species = [UNCHARACTERIZED_SPECIES];
            $rows[] = [
                'seq_id'        => $seq_id,
                'motif_id'      => $r['motif_id'],
                'position'      => $r['position'],
                'strand'        => $r['strand'],
                'score'         => $r['score'],
                'hit'           => $r['hit'],
                'family'        => PLACE_FAMILY_LABEL,
                'species_count' => count($species),
                'source'        => 'PLACE',
                'place_name'    => $meta['place_name'] ?? null,
            ];
            $fam_counts[PLACE_FAMILY_LABEL] = ($fam_counts[PLACE_FAMILY_LABEL] ?? 0) + 1;
            $motif_species_seq[$r['motif_id']] = $species;
            $all_motifs_seen[$r['motif_id']] = true;
            $motif_to_seqs[$r['motif_id']][$seq_id] = true;
            if (!isset($motif_meta_seen[$r['motif_id']])) {
                $motif_meta_seen[$r['motif_id']] = [
                    'family'  => PLACE_FAMILY_LABEL,
                    'species' => $species,
                    'source'  => 'PLACE',
                ];
            }
        }
        // Stable order in the lookup map (keeps diffs small across re-scans).
        ksort($motif_species_seq);

        $written = pp_write_seq_files(
            $job_id, $seq_id, $rows, $fam_counts,
            $seq_lengths[$seq_id] ?? 0,
            $seq_strings[$seq_id] ?? '',
            $motif_species_seq
        );
        $total_tsv_bytes    += $written['size_bytes_tsv'];
        $total_json_bytes   += $written['size_bytes_json'];
        $total_output_bytes += $written['size_bytes_tsv'] + $written['size_bytes_json'];
        if ($total_output_bytes > PP_MAX_OUTPUT_BYTES_PER_JOB) {
            throw new RuntimeException(
                'Job output exceeded per-job hard cap (' . pp_format_bytes(PP_MAX_OUTPUT_BYTES_PER_JOB) . ').'
            );
        }

        arsort($fam_counts);
        $sequences_summary[] = [
            'seq_id'          => $seq_id,
            'length_bp'       => $seq_lengths[$seq_id] ?? null,
            'hits'            => count($rows),
            'families'        => count($fam_counts),
            'top_family'      => !empty($fam_counts) ? (string) array_key_first($fam_counts) : null,
            'size_bytes_tsv'  => $written['size_bytes_tsv'],
            'size_bytes_json' => $written['size_bytes_json'],
            'file_tsv'        => $seq_id . '.tsv',
            'file_json'       => $seq_id . '.json',
        ];
        $total_hits += count($rows);
    }

    // Cross-promoter overview: matrix-level set membership across all
    // promoters in this job. Annotation-safe: uses only motif_id +
    // family + species + source — all of which are derivable from the
    // bundled slim metadata (no TF name, gene id, ChIP, or homologs).
    // File is always written even when n_promoters<2 so the frontend
    // can decide to skip the section without an extra fetch.
    $promoter_order = array_keys($seq_lengths);
    $cross_matrices = [];
    foreach ($motif_to_seqs as $mid => $seq_set) {
        $promoters_list = array_values(array_filter(
            $promoter_order,
            fn($p) => isset($seq_set[$p])
        ));
        $cross_matrices[] = [
            'id'        => $mid,
            'family'    => $motif_meta_seen[$mid]['family']  ?? PWM_FAMILY_FALLBACK,
            'promoters' => $promoters_list,
            'species'   => $motif_meta_seen[$mid]['species'] ?? [UNCHARACTERIZED_SPECIES],
            'source'    => $motif_meta_seen[$mid]['source']  ?? 'PWM',
        ];
    }
    $cross = [
        'n_promoters'       => count($promoter_order),
        'n_distinct_motifs' => count($motif_to_seqs),
        'promoters'         => $promoter_order,
        'matrices'          => $cross_matrices,
    ];
    pp_atomic_write(
        pp_safe_job_path($job_id, 'cross_promoter.json'),
        json_encode($cross, JSON_UNESCAPED_SLASHES)
    );

    // species_universe: union of species across every motif that hit
    // at least once in this job. Sorted alphabetically, with
    // "(uncharacterized species)" treated like any other entry (the
    // UI pins it to the end of the dropdown for readability).
    $sp_set = [];
    foreach ($motif_meta_seen as $m) {
        foreach ($m['species'] ?? [] as $sp) $sp_set[$sp] = true;
    }
    $species_universe = array_keys($sp_set);
    sort($species_universe);

    $now = time();
    return [
        'job_id'                => $job_id,
        'created_unix'          => $now,
        'created_iso'           => date('c', $now),
        'sequence_count'        => count($sequences_summary),
        'total_input_bp'        => $total_bp,
        'total_hits'            => $total_hits,
        'total_distinct_motifs' => count($all_motifs_seen),
        'total_output_bytes'    => $total_output_bytes,
        'total_tsv_bytes'       => $total_tsv_bytes,
        'total_json_bytes'      => $total_json_bytes,
        'sequences'             => $sequences_summary,
        'species_universe'      => $species_universe,
    ];
}

/**
 * Write the per-sequence .tsv (human/CLI friendly, mirrors download format)
 * and .json (machine-readable, served by /api/get_result.php) files.
 * Atomic rename so AJAX readers never see half-written files.
 *
 * Since Step 24 the TSV gains 3 columns (Source, PLACE Name, Species)
 * and the JSON gains a `motif_species` top-level lookup map of
 *   { motif_id => [species, ...] }
 * covering only motifs that produced at least one hit in this sequence.
 * The browser uses that map for client-side species filtering — keeping
 * it out of every row keeps the .json roughly the size of pre-Step-24
 * jobs even when a motif fires many times.
 */
function pp_write_seq_files(
    string $job_id,
    string $seq_id,
    array  $rows,
    array  $fam_counts,
    int    $length_bp,
    string $seq_string = '',
    array  $motif_species = []
): array {
    $tsv_lines = [
        "Sequence ID\tMotif ID\tSource\tPLACE Name\tFamily\tPosition\tStrand\tScore\tHit sequence\tSpecies"
    ];
    foreach ($rows as $r) {
        $species_arr = $motif_species[$r['motif_id']] ?? [];
        $species_str = empty($species_arr) ? '' : implode('; ', $species_arr);
        $tsv_lines[] = implode("\t", [
            $r['seq_id'],
            $r['motif_id'],
            (string) ($r['source']     ?? ''),
            (string) ($r['place_name'] ?? ''),
            $r['family'],
            (string) $r['position'],
            $r['strand'],
            (string) ($r['score'] ?? ''),
            $r['hit'],
            $species_str,
        ]);
    }
    $tsv = implode("\n", $tsv_lines) . "\n";

    $json_payload = [
        'seq_id'        => $seq_id,
        'length_bp'     => $length_bp,
        'hits'          => count($rows),
        'family_counts' => empty($fam_counts) ? (object) [] : $fam_counts,
        'rows'          => $rows,
        // Used by the Sequence Map (Step 22). Stored once per seq so the AJAX
        // result endpoint can deliver everything the right pane needs in one fetch.
        'seq_string'    => $seq_string,
        // Step 24: motif -> species lookup, only for motifs hit in this seq.
        // Browser uses this for the species filter so per-row payload stays small.
        'motif_species' => empty($motif_species) ? (object) [] : $motif_species,
    ];
    $json = json_encode($json_payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Failed to encode JSON for sequence '$seq_id'.");
    }

    $tsv_size  = strlen($tsv);
    $json_size = strlen($json);
    if ($tsv_size + $json_size > PP_MAX_OUTPUT_BYTES_PER_SEQ) {
        throw new RuntimeException(
            "Sequence '$seq_id' result (" . pp_format_bytes($tsv_size + $json_size)
            . ') exceeds per-sequence size cap.'
        );
    }

    pp_atomic_write(pp_safe_job_path($job_id, $seq_id . '.tsv'),  $tsv);
    pp_atomic_write(pp_safe_job_path($job_id, $seq_id . '.json'), $json);

    return ['size_bytes_tsv' => $tsv_size, 'size_bytes_json' => $json_size];
}
