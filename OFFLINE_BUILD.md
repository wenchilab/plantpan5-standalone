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
Source is `PWM` or `PLACE`; PLACE Name is filled only on PLACE rows;
Species is semicolon-separated.

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
