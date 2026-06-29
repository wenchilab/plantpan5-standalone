#!/usr/bin/env bash
# Static check: scan a built image for files / patterns that MUST NOT be
# shipped (the curated TF annotation assets). Run AFTER `docker build`.
#
# Usage: scripts/verify_image.sh plantpan5-offline:latest

set -euo pipefail

IMAGE="${1:-plantpan5-offline:latest}"

BLACKLIST=(
  'cross_species'
  'homo_TF_TF_list'
  'motif2species'
  'TFlocus_with_PPI'
  'ChIPseq'
  'chip_seq'
  'TFBS.TF_tomtom_database'
  'TF_tomtom_database'
  '\.sql$'
  'plantpan_db'
  '\.env'
)

echo "Listing files in $IMAGE ..."
TMP=$(mktemp)
docker run --rm --entrypoint sh "$IMAGE" -c \
  'find / -xdev -type f 2>/dev/null | grep -Ev "^/proc|^/sys|^/dev|^/run"' > "$TMP"
echo "  total files: $(wc -l < "$TMP")"

FAIL=0
echo ""
echo "Checking blacklist patterns..."
for pat in "${BLACKLIST[@]}"; do
  HIT=$(mktemp)
  if grep -E "$pat" "$TMP" > "$HIT"; then
    echo "  FAIL  pattern '$pat' matched:"
    sed 's/^/    /' "$HIT"; FAIL=1
  fi
  rm -f "$HIT"
done

# Content scan: only inside /opt/plantpan/data, NOT the PHP UI under
# /var/www/html (the UI intentionally mentions feature names that are
# not bundled, e.g. "we do not ship ChIP-seq peak data").
echo ""
echo "Sampling /opt/plantpan/data for annotation signatures..."
GREP_OUT=$(mktemp)
docker run --rm --entrypoint sh "$IMAGE" -c '
  d=/opt/plantpan/data
  [ -d "$d" ] || exit 0
  find "$d" -type f \
       ! -name "motif_family.json" \
       ! -name "place_meta.json" \
       -print0 \
    | xargs -0 -r grep -Il "TFlocus_with_PPI\|cross_species\|ChIP-seq peak\|gene_ids\|homologs" 2>/dev/null \
    || true
' > "$GREP_OUT" || true

if [ -s "$GREP_OUT" ]; then
  echo "  FAIL  forbidden content signature in:"
  sed 's/^/    /' "$GREP_OUT"; FAIL=1
fi
rm -f "$GREP_OUT" "$TMP"

if [ "$FAIL" -ne 0 ]; then
  echo ""; echo "VERIFY FAILED -- do not push this image."; exit 1
fi
echo ""
echo "VERIFY OK -- no blacklisted patterns found."
