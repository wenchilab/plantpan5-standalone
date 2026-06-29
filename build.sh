#!/usr/bin/env bash
# Builds the Docker image from the payload already present in bin/ + data/.
#
# How to get the payload:
#   - Users:       it's already inside the self-contained release zip.
#   - Maintainers: run ./scripts/_dev_make_payload.sh against a PlantPAN source tree.
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

for f in "${PAYLOAD[@]}"; do
  if [ ! -s "$HERE/$f" ]; then
    echo "ERROR: build payload missing: $f"
    echo "  Users:       unzip a self-contained release, or use the ready-to-run image."
    echo "  Maintainers: run ./scripts/_dev_make_payload.sh first."
    exit 1
  fi
done

# Ensure the extension-library dir exists so the Dockerfile COPY never fails,
# even when no bundled libraries are present (Step 26).
mkdir -p "$HERE/data/libraries"
[ -e "$HERE/data/libraries/.gitkeep" ] || : > "$HERE/data/libraries/.gitkeep"

echo "=== payload ==="
ls -lh "$HERE/bin/match" "$HERE/data/"*.dat "$HERE/data/"*.prf "$HERE/data/motif_family.json" "$HERE/data/place_meta.json"
if ls -d "$HERE/data/libraries/"*/ >/dev/null 2>&1; then
  echo "--- bundled extension libraries ---"
  for d in "$HERE/data/libraries/"*/; do echo "  $(basename "$d")"; done
fi

echo
echo "=== docker build $TAG ==="
docker build -t "$TAG" "$HERE"

echo
echo "Built $TAG"
echo "Next:  ./scripts/verify_image.sh $TAG"
echo "Run :  docker run --rm -p 8080:80 $TAG"
