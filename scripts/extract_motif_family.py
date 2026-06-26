#!/usr/bin/env python3
"""
Build-time helper: read the FULL annotation file
  Plantpan3_TF_HQ_motif2species
and emit a SLIM mapping of matrix_id -> family_name + species list.
Everything else (gene IDs, TF locus, ChIP-seq, PPI, homologs) is
intentionally dropped so the result is safe to ship inside a public
Docker image. The species list is bundled because it's already public
(JASPAR / CIS-BP / literature) and enables the species filter UI in
the offline result page.

Usage:
  python3 extract_motif_family.py <input_motif2species> <output_json>
"""
import json
import os
import sys

if len(sys.argv) < 3:
    sys.stderr.write(
        "Usage: extract_motif_family.py <input_motif2species> <output_json>\n")
    sys.exit(1)

src, out = sys.argv[1], sys.argv[2]
if not os.path.isfile(src):
    sys.stderr.write(f"ERROR: input not found: {src}\n"); sys.exit(1)

family_count = {}
species_count = {}

with open(src, errors="replace") as f:
    for line in f:
        line = line.rstrip("\r\n")
        if len(line) < 2: continue
        cols = line.split("\t")
        if len(cols) < 4: continue
        m  = cols[0].strip()
        sp = cols[2].strip() if len(cols) > 2 else ""
        fa = cols[3].strip() if len(cols) > 3 else ""
        if not m: continue
        species_count.setdefault(m, set())
        if sp: species_count[m].add(sp)
        if fa:
            family_count.setdefault(m, {})
            family_count[m][fa] = family_count[m].get(fa, 0) + 1

slim = {}
for m in sorted(set(list(family_count) + list(species_count))):
    fams = family_count.get(m, {})
    best = max(fams, key=fams.get) if fams else "(uncharacterized)"
    spc_list = sorted(species_count.get(m, set()))
    slim[m] = {
        "family": best,
        "species_count": len(spc_list),
        "species": spc_list,
    }

doc = {
    "_meta": {
        "description": "PlantPAN5-offline slim motif metadata. matrix_id -> family + species list. No gene IDs, no homologs, no ChIP-seq, no PPI.",
        "count": len(slim),
    },
    "motifs": slim,
}

os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
with open(out, "w") as g:
    json.dump(doc, g, indent=2)
sys.stderr.write(f"Wrote {len(slim)} motifs -> {out} ({os.path.getsize(out)} bytes)\n")
