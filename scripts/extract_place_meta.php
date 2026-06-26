<?php
/**
 * extract_place_meta.php (PHP equivalent of the Python version).
 * Read FULL PLACE annotation file TF_LQ_motif2species and emit a SLIM
 * mapping: motif_id -> place_name + species list. Build-time helper
 * only; never run this inside the published image.
 *
 * PLACE (Higo et al, 1999) is a public motif database. Column [3]
 * "(Motif sequence only)" is intentionally dropped (constant value).
 *
 * Species cleaning:
 *   - Column [2] may contain semicolon-separated multi-species cells;
 *     split on ";" and trim each part.
 *   - Bracketed taxonomic-group entries (e.g. "(Dicotyledon)") are kept
 *     verbatim — no abbreviation in the UI.
 *   - Empty species cells fall back to "(uncharacterized species)".
 *
 * Usage:
 *   php extract_place_meta.php <input_TF_LQ_motif2species> <output_json>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php extract_place_meta.php <input> <output>\n");
    exit(1);
}
$in  = $argv[1];
$out = $argv[2];
if (!is_file($in)) { fwrite(STDERR, "ERROR: input not found: $in\n"); exit(1); }

function pp_clean_species_cell(string $raw): array {
    if ($raw === '') return [];
    $parts = array_map('trim', explode(';', $raw));
    $parts = array_values(array_unique(array_filter($parts, fn($p) => $p !== '')));
    sort($parts);
    return $parts;
}

$motifs = [];
$fp = fopen($in, 'r');
while (($line = fgets($fp)) !== false) {
    $line = rtrim($line, "\r\n");
    if (strlen($line) < 2) continue;
    $cols = explode("\t", $line);
    if (count($cols) < 5) continue;
    $mid = trim($cols[0]);
    if ($mid === '') continue;
    $species = pp_clean_species_cell(trim($cols[2] ?? ''));
    if (!$species) $species = ['(uncharacterized species)'];
    $place_name = trim($cols[4] ?? '');

    if (isset($motifs[$mid])) {
        $merged = array_unique(array_merge($motifs[$mid]['species'], $species));
        sort($merged);
        $motifs[$mid]['species'] = array_values($merged);
    } else {
        $motifs[$mid] = [
            'place_name' => $place_name,
            'species'    => $species,
        ];
    }
}
fclose($fp);
ksort($motifs);

$doc = [
    '_meta' => [
        'description' => 'PlantPAN5-offline slim PLACE metadata. motif_id -> place_name + species list. PLACE (Higo et al, 1999) is a public database.',
        'count'       => count($motifs),
    ],
    'motifs' => $motifs,
];
file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fprintf(STDERR, "Wrote %d PLACE motifs -> %s (%d bytes)\n", count($motifs), $out, filesize($out));
