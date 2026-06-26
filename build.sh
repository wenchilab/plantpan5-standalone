#!/usr/bin/env bash
# Auto-detects: self-contained (payload already in bin/+data/) or
# source-tree (pull from ../program_base/). See OFFLINE_BUILD.md.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
TAG="${IMAGE_TAG:-plantpan5-offline:latest}"

PAYLOAD=( "bin/match" \
          "data/PlantPAN3_v2_matrix_with_TF.dat" \
          "data/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf" \
          "data/motif_family.json" \
          "data/pattern_seq_uniprot_place.dat" \
          "data/pattern_seq_uniprot_place.dat.minFP.prf" \
          "data/place_meta.json" )

have_payload() {
  local f
  for f in "${PAYLOAD[@]}"; do
    [ -s "$HERE/$f" ] || return 1
  done
}

if have_payload; then
  echo "[build] self-contained mode (payload present)"
else
  SRC="${PLANTPAN_SRC:-$(cd "$HERE/.." && pwd)}"
  echo "[build] source-tree mode (SRC=$SRC)"

  for f in match \
           PlantPAN3_v2_matrix_with_TF.dat \
           PlantPAN3_v2_matrix_with_TF.dat.minFP.prf \
           Plantpan3_TF_HQ_motif2species \
           pattern_seq_uniprot_place.dat \
           pattern_seq_uniprot_place.dat.minFP.prf \
           TF_LQ_motif2species
  do
    [ -s "$SRC/program_base/$f" ] || {
      echo "ERROR: missing $SRC/program_base/$f"
      echo "Set PLANTPAN_SRC=/path/to/PlantPAN5 or unzip a self-contained release."
      exit 1
    }
  done

  mkdir -p "$HERE/bin" "$HERE/data"
  cp -f "$SRC/program_base/match"                                     "$HERE/bin/match"
  cp -f "$SRC/program_base/PlantPAN3_v2_matrix_with_TF.dat"           "$HERE/data/"
  cp -f "$SRC/program_base/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf" "$HERE/data/"
  cp -f "$SRC/program_base/pattern_seq_uniprot_place.dat"             "$HERE/data/"
  cp -f "$SRC/program_base/pattern_seq_uniprot_place.dat.minFP.prf"   "$HERE/data/"

  if command -v php >/dev/null 2>&1; then
    php    "$HERE/scripts/extract_motif_family.php" \
           "$SRC/program_base/Plantpan3_TF_HQ_motif2species" \
           "$HERE/data/motif_family.json"
    php    "$HERE/scripts/extract_place_meta.php" \
           "$SRC/program_base/TF_LQ_motif2species" \
           "$HERE/data/place_meta.json"
  elif command -v python3 >/dev/null 2>&1; then
    python3 "$HERE/scripts/extract_motif_family.py" \
           "$SRC/program_base/Plantpan3_TF_HQ_motif2species" \
           "$HERE/data/motif_family.json"
    python3 "$HERE/scripts/extract_place_meta.py" \
           "$SRC/program_base/TF_LQ_motif2species" \
           "$HERE/data/place_meta.json"
  else
    echo "ERROR: need php or python3 to extract slim metadata"; exit 1
  fi
fi

echo
echo "=== payload ==="
ls -lh "$HERE/bin/match" "$HERE/data/"*.dat "$HERE/data/"*.prf "$HERE/data/motif_family.json" "$HERE/data/place_meta.json"

echo
echo "=== docker build $TAG ==="
docker build -t "$TAG" "$HERE"

echo
echo "Built $TAG"
echo "Next:  ./scripts/verify_image.sh $TAG"
echo "Run :  docker run --rm -p 8080:80 $TAG"
