#!/usr/bin/env bash
# Bundle plantpan5-offline/ into a single .zip + sha256.
# Recipient unzips, runs ./build.sh, no PlantPAN source needed.
set -euo pipefail

HERE=$(cd "$(dirname "$0")/.." && pwd)
NAME="$(basename "$HERE")"
VERSION="${VERSION:-1.0}"
DATE="$(date -u +%Y%m%d)"
OUT="${OUT_DIR:-$(cd "$HERE/.." && pwd)}"
ZIP="$OUT/plantpan5-offline-v${VERSION}-${DATE}.zip"

PAYLOAD=( "bin/match" \
          "data/PlantPAN3_v2_matrix_with_TF.dat" \
          "data/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf" \
          "data/motif_family.json" \
          "data/pattern_seq_uniprot_place.dat" \
          "data/pattern_seq_uniprot_place.dat.minFP.prf" \
          "data/place_meta.json" )
for f in "${PAYLOAD[@]}"; do
  if [ ! -s "$HERE/$f" ]; then
    echo "ERROR: payload missing: $f"; echo "Run ./build.sh first."; exit 1
  fi
done

echo "[1/3] sensitive-pattern leak scan..."
LEAKS=$(find "$HERE" \
  \( -path '*/.git' -o -path '*/.github' \) -prune -o \
  -type f -print 2>/dev/null \
  | grep -E 'motif2species|cross_species|homo_TF_TF_list|TFlocus_with_PPI|TF_tomtom_database|ChIPseq|chip_seq|\.sql$' \
  || true)
if [ -n "$LEAKS" ]; then
  echo "REFUSING -- sensitive matches:"; echo "$LEAKS" | sed 's/^/  /'; exit 1
fi
echo "  clean"

echo "[2/3] purging build clutter..."
rm -rf "$HERE/scripts/__pycache__" 2>/dev/null || true

echo "[3/3] creating $ZIP"
mkdir -p "$OUT"; rm -f "$ZIP"
( cd "$(dirname "$HERE")" && zip -r9q "$ZIP" "$NAME" \
    -x "$NAME/.git/*" \
       "$NAME/.github/*" \
       "$NAME/node_modules/*" \
       "$NAME/.DS_Store" \
       "$NAME/*.swp" \
       "$NAME/scripts/__pycache__/*" \
       "$NAME/**/__pycache__/*" \
       "$NAME/GUIDE.md" \
       "$NAME/NEXT_FEATURE_PROMPT.md" \
       "$NAME/NEXT_FEATURE_PROMPT_v2.md" \
       "$NAME/REPACKAGE_INSTRUCTIONS.md" \
       "$NAME/DESIGN_extensible_motif_library.md" \
       "$NAME/RELEASE.md" \
       "$NAME/scripts/_dev_make_payload.sh" \
       "$NAME/scripts/extract_motif_family.php" \
       "$NAME/scripts/extract_motif_family.py" \
       "$NAME/scripts/extract_place_meta.php" \
       "$NAME/scripts/extract_place_meta.py" \
)
( cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )

echo
echo "Done."
echo "  Package : $ZIP"
echo "  Size    : $(du -h "$ZIP" | cut -f1)"
echo "  SHA256  : $(cut -d' ' -f1 < "${ZIP}.sha256")"
echo
echo "On the destination machine:"
echo "  1) copy the zip over"
echo "  2) (optional) verify: sha256sum -c $(basename "$ZIP").sha256"
echo "  3) unzip"
echo "  4) cd plantpan5-offline"
echo "  5) chmod +x build.sh entrypoint.sh scripts/*.sh app/bin/*"
echo "  6) ./build.sh"
echo "  7) docker run --rm -p 8080:80 plantpan5-offline:latest"
