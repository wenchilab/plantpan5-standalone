# PlantPAN 5 Offline — Quick Build Guide

You received this folder as a self-contained package. Everything needed
to build the Docker image is already inside; you do **not** need the
PlantPAN source tree on this machine.

## Prerequisites

Just **Docker** (any recent version, on any OS):

- **Windows**: Docker Desktop (with WSL 2 backend)
- **macOS**:  Docker Desktop or Colima
- **Linux**:  `docker` from your distro's package manager

## Build & Run

```bash
cd plantpan5-offline

# Windows / Mac unzip drops the +x bit -- restore it
chmod +x build.sh entrypoint.sh scripts/*.sh app/bin/*

./build.sh                                            # ~3-8 min first time
./scripts/verify_image.sh plantpan5-offline:latest    # optional, recommended
docker run --rm -p 8080:80 plantpan5-offline:latest   # web UI on :8080
```

Open <http://localhost:8080> in a browser.

To stop: `Ctrl+C` in the terminal.

## CLI mode (no browser, good for pipelines)

```bash
docker run --rm -v "$PWD":/work plantpan5-offline:latest \
    scan /work/your_promoters.fa > hits.tsv
```

Output: 10-column TSV (`Sequence ID  Motif ID  Source  PLACE Name  Family  Position  Strand  Score  Hit sequence  Species`).
Source is `PWM`, `PLACE`, or the tag of any custom library you added (see
below); PLACE Name is filled when a library provides a pattern name;
Species is semicolon-separated.

## Adding your own motif libraries

The scanner can run extra motif libraries alongside the built-in PWM + PLACE
ones. A library is a folder containing **at least**:

```
mylib/
├── library.dat                 # matrix file in the same format match expects
└── library.dat.minFP.prf       # the paired score-threshold profile
```

and **optionally**:

```
├── library.json                # display + behaviour overrides
└── meta.json                   # per-motif annotation (family / species / place_name)
```

`library.json` (all fields optional):

```json
{
  "id": "mylab",                 // a-z0-9_, <=32 chars; defaults to folder name
  "label": "MyLab v1",           // shown in the UI + Source pill
  "source_tag": "MYLAB",         // value in the TSV Source column
  "pill_bg": "#eaf7ee",          // #RRGGBB; auto-assigned if omitted
  "pill_fg": "#1d7a3a",
  "family_mode": "annotated",    // or "sequence_only" (forces one family slice, like PLACE)
  "enabled": true
}
```

`meta.json` (every motif and every field optional):

```json
{ "motifs": { "MYLAB_0001": { "family": "bZIP", "species": ["Oryza sativa"] } } }
```

If a motif has no annotation it still scans — it just shows its matrix ID,
family `(unannotated)`, and species `(uncharacterized species)`. Editing
`meta.json` in a mounted library takes effect on the **next scan**, no rebuild.

### Two ways to add a library

**Runtime mount (recommended — private libraries never enter the image):**

```bash
docker run --rm -p 8080:80 \
    -v "$PWD/mylib":/opt/plantpan/data/libraries/50_mylab:ro \
    plantpan5-offline:latest
```

The numeric prefix (`50_`) controls scan/display order.

**Bundled at build time:** drop the folder under `data/libraries/` before
`./build.sh`. It will be packaged into the image. (Use this only for libraries
you intend to ship; keep proprietary ones as runtime mounts.)

### Limits (env-overridable)

Each library is one extra `match` pass, so scan time grows with library count:

- `PP_MAX_LIBRARIES` — max active libraries (default 16)
- `PP_MAX_LIBRARY_DAT_BYTES` — per-library `.dat` cap (default 64 MB)
- `PP_MAX_LIBRARY_TOTAL_BYTES` — combined `.dat` cap (default 256 MB)

Over-limit or malformed libraries are skipped with a warning on the result
page, never silently dropped.

## Common issues

**`bash: ./build.sh: Permission denied`** — restore exec bits:
```bash
chmod +x build.sh entrypoint.sh scripts/*.sh app/bin/*
```

**`/bin/bash^M: bad interpreter`** — line endings got mangled (rare):
```bash
sed -i 's/\r$//' build.sh entrypoint.sh scripts/*.sh
```

**`Cannot connect to the Docker daemon`** — Docker isn't running.

**`port 8080 already allocated`** — pick a different host port:
```bash
docker run --rm -p 9090:80 plantpan5-offline:latest
# then open http://localhost:9090
```

## What this image DOES include

- `match` scanner binary
- PWM library (1,881 motifs) + PLACE pattern library (373 motifs)
- Slim metadata: motif_id → family + species list, and motif_id → place_name + species list
- Web UI: Pattern Search / Position Track / Family (Donut + TreeMap) / Sequence Map
- Cross-Promoter Overview (UpSet + table)
- Per-job species filter that propagates across every tab
- CLI scanner (`plantpan-scan`) emitting unified PWM+PLACE TSV

## What this image does NOT include

By design, the curated TF annotation stays on the live PlantPAN server:
gene-ID / TF-locus mappings, ChIP-seq peaks, cross-species homologs,
PPI tables, full TF metadata. Each motif-ID cell in the web UI submits
a POST form to <https://plantpan.itps.ncku.edu.tw/plantpan5/TFBS_info.php>
which opens the matching annotation page on the live site.
