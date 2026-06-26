#!/usr/bin/env python3
"""
Build-time helper: read the FULL PLACE annotation file
  TF_LQ_motif2species
and emit a SLIM mapping of motif_id -> place_name + species list.

PLACE (Higo et al, 1999) is a public motif database — names and species
are non-sensitive. Column [3] ("(Motif sequence only)") is intentionally
dropped because it's redundant (all PLACE rows have the same value) and
adds no information.

Species cleaning:
  - Column [2] may contain semicolon-separated multi-species cells, e.g.
      "Arabidopsis thaliana; Oryza sativa; Zea mays"
    These are split on ";" and trimmed; each part becomes its own entry.
  - Bracketed taxonomic-group entries (not binomial Latin names), e.g.
      "(Dicotyledon)", "(Eukaryote)", "(CaMV, virus)", "(Unknown)"
    are kept verbatim (no abbreviation in the UI; tooltip shows raw).
  - Empty species cells fall back to "(uncharacterized species)".

Input columns (tab-separated, one row per motif):
  [0] motif_id       (e.g. "TF_motif_seq_0001")
  [1] motif_id_dup   (identical to [0]; ignored)
  [2] species        (may be semicolon-separated; cleaned as above)
  [3] "(Motif sequence only)"  (constant; ignored)
  [4] place_name     (e.g. "MYBCORE")
  [5] source label   ("PLACE" or "PLACE, UniProt"; ignored)

Usage:
  python3 extract_place_meta.py <input_TF_LQ_motif2species> <output_json>
"""
import json
import os
import sys

if len(sys.argv) < 3:
    sys.stderr.write(
        "Usage: extract_place_meta.py <input_TF_LQ_motif2species> <output_json>\n")
    sys.exit(1)

src, out = sys.argv[1], sys.argv[2]
if not os.path.isfile(src):
    sys.stderr.write(f"ERROR: input not found: {src}\n"); sys.exit(1)


def clean_species_cell(raw: str) -> list:
    """Split semicolon-separated species cells; preserve bracketed group
    entries verbatim. Returns a sorted list of distinct species strings.
    Empty / whitespace-only input returns [].
    """
    if not raw:
        return []
    parts = [p.strip() for p in raw.split(';')]
    return sorted({p for p in parts if p})


motifs = {}
with open(src, errors="replace") as f:
    for line in f:
        line = line.rstrip("\r\n")
        if len(line) < 2:
            continue
        cols = line.split("\t")
        if len(cols) < 5:
            continue
        mid = cols[0].strip()
        if not mid:
            continue
        species = clean_species_cell(cols[2] if len(cols) > 2 else "")
        if not species:
            species = ["(uncharacterized species)"]
        place_name = cols[4].strip() if len(cols) > 4 else ""

        # First occurrence wins for place_name; species are unioned across
        # any duplicate rows (TF_LQ_motif2species is normally one row per
        # motif, but defend against future duplicates).
        if mid in motifs:
            existing = set(motifs[mid]["species"])
            existing.update(species)
            motifs[mid]["species"] = sorted(existing)
        else:
            motifs[mid] = {
                "place_name": place_name,
                "species":    species,
            }

doc = {
    "_meta": {
        "description": (
            "PlantPAN5-offline slim PLACE metadata. motif_id -> "
            "place_name + species list. PLACE is a public database "
            "(Higo et al, 1999) so names + species are non-sensitive."
        ),
        "count": len(motifs),
    },
    "motifs": dict(sorted(motifs.items())),
}

os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
with open(out, "w") as g:
    json.dump(doc, g, indent=2)
sys.stderr.write(
    f"Wrote {len(motifs)} PLACE motifs -> {out} ({os.path.getsize(out)} bytes)\n")
