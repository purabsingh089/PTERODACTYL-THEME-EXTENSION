#!/usr/bin/env bash
#
# Primus — package build
# Replicates Blueprint's `bpd:export primus` packaging step for workflows
# without a live panel + developer install: stages the extension tree and
# zips it into primus.blueprint (contents at the archive root, same layout
# scripts/commands/developer/export.sh produces).
#
# Usage: ./build.sh            → dist/primus.blueprint

set -euo pipefail

cd "$(dirname "$0")"

DIST="dist"
STAGE="$(mktemp -d /tmp/primus-stage.XXXXXX)"

echo "Staging extension files.."
for item in *; do
  case "$item" in
    .git|"$DIST"|build.sh|PROMPT.TEXT|*.blueprint) continue ;;
  esac
  cp -r "$item" "$STAGE/"
done

# Drop .gitkeep artifacts from the staged copy so they never reach the zip.
find "$STAGE" -name ".gitkeep" -exec cp /dev/null {} + 2>/dev/null || true

echo "Packaging.."
mkdir -p "$DIST"
(
  cd "$STAGE"
  zip -r -q "$OLDPWD/$DIST/primus.blueprint" ./* -x ".dist/*" ".git/*" ".gitkeep" "*/.gitkeep"
)

echo "Validating archive layout.."
unzip -l "$DIST/primus.blueprint" | grep -E "conf\.yml" >/dev/null
if ! unzip -p "$DIST/primus.blueprint" conf.yml | head -1 | grep -q "Primus\|info\|#"; then
  echo "ERROR: conf.yml missing or malformed in archive" >&2
  exit 1
fi

SIZE=$(du -h "$DIST/primus.blueprint" | cut -f1)
echo "Built dist/primus.blueprint ($SIZE)"
