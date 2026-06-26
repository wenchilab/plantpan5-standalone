<?php
/**
 * extract_motif_family.php (PHP equivalent of the Python version).
 * Read FULL annotation file Plantpan3_TF_HQ_motif2species and emit
 * a SLIM mapping: matrix_id -> family + species list. Build-time
 * helper only; never run this inside the published image.
 *
 * Usage:
 *   php extract_motif_family.php <input_motif2species> <output_json>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php extract_motif_family.php <input> <output>\n");
    exit(1);
}
$in  = $argv[1];
$out = $argv[2];
if (!is_file($in)) { fwrite(STDERR, "ERROR: input not found: $in\n"); exit(1); }

$fc = []; $sc = [];
$fp = fopen($in, 'r');
while (($line = fgets($fp)) !== false) {
    $line = rtrim($line, "\r\n");
    if (strlen($line) < 2) continue;
    $cols = explode("\t", $line);
    if (count($cols) < 4) continue;
    $m  = trim($cols[0]);
    $sp = trim($cols[2] ?? '');
    $fa = trim($cols[3] ?? '');
    if ($m === '') continue;
    if (!isset($sc[$m])) $sc[$m] = [];
    if ($sp !== '') $sc[$m][$sp] = true;
    if ($fa !== '') {
        if (!isset($fc[$m])) $fc[$m] = [];
        $fc[$m][$fa] = ($fc[$m][$fa] ?? 0) + 1;
    }
}
fclose($fp);

$slim = [];
foreach (array_unique(array_merge(array_keys($fc), array_keys($sc))) as $m) {
    $fams = $fc[$m] ?? [];
    arsort($fams);
    $best = $fams ? array_key_first($fams) : '(uncharacterized)';
    $spc_list = array_keys($sc[$m] ?? []);
    sort($spc_list);
    $slim[$m] = [
        'family'        => $best,
        'species_count' => count($spc_list),
        'species'       => $spc_list,
    ];
}
ksort($slim);

$doc = [
    '_meta' => [
        'description' => 'PlantPAN5-offline slim motif metadata. matrix_id -> family + species list.',
        'count'       => count($slim),
    ],
    'motifs' => $slim,
];
file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fprintf(STDERR, "Wrote %d motifs -> %s (%d bytes)\n", count($slim), $out, filesize($out));
