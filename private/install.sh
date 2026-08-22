#!/usr/bin/env bash
#
# Primus v1.0.0 — install hook
# Runs during `blueprint -i primus`. Blueprint places all extension files
# itself; this hook sanity-checks the environment, clears compiled caches so
# the freshly copied extension is picked up and applies the default preset.
# Safe to re-run.

set -euo pipefail

echo "Primus v1.0.0"

# Non-fatal PHP version check: the panel (and this extension) require PHP 8.2+.
if command -v php >/dev/null 2>&1; then
  PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "0.0")"
  PHP_MAJOR="${PHP_VERSION%%.*}"
  PHP_MINOR="${PHP_VERSION#*.}"
  if [ "${PHP_MAJOR}" -lt 8 ] 2>/dev/null || { [ "${PHP_MAJOR}" -eq 8 ] 2>/dev/null && [ "${PHP_MINOR}" -lt 2 ] 2>/dev/null; }; then
    echo "WARNING: Primus targets PHP 8.2+ (detected ${PHP_VERSION}). The theme may fail to load." >&2
  fi
fi

PANEL_DIR="${PTERODACTYL_DIRECTORY:-}"
if [ -n "$PANEL_DIR" ] && [ -f "$PANEL_DIR/artisan" ]; then
  # Clear compiled views/routes so wrappers and controllers resolve immediately.
  (cd "$PANEL_DIR" && php artisan view:clear) || true
  (cd "$PANEL_DIR" && php artisan route:clear) || true

  # Publish + run Primus migrations (tables are namespaced primus_*).
  (cd "$PANEL_DIR" && php artisan migrate --force) || true

  # Seed the default preset on first install.
  (cd "$PANEL_DIR" && php artisan tinker --execute=\\
    "app(\Pterodactyl\BlueprintFramework\Extensions\primus\Services\ThemePresetManager::class)->apply('midnight');" 2>/dev/null) || true
fi

# Prime the public symlink/connection if Blueprint did not wire it yet.
if [ -n "$PANEL_DIR" ] && [ -d "$PANEL_DIR/.blueprint/extensions/primus/public" ] && [ -d "$PANEL_DIR/public/extensions" ]; then
  LINK_TARGET="$PANEL_DIR/public/extensions/primus"
  LINK_SOURCE="$PANEL_DIR/.blueprint/extensions/primus/public"
  if [ ! -e "$LINK_TARGET" ]; then
    ln -s "$LINK_SOURCE" "$LINK_TARGET" || true
  fi
fi

echo "Primus installed. Open Admin → Extensions → Primus to configure the theme."
echo "AI diagnostics require an API key from https://monkeycode-ai.net (stored server-side only)."
exit 0
