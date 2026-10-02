#!/usr/bin/env bash
#
# Primus v1.0.0 — remove hook
# Runs during `blueprint -r primus`. Blueprint removes the extension files;
# this script makes a best-effort pass at dropping Primus' settings/cache
# artifacts. It must never fail the uninstall.

set -uo pipefail

echo "Primus v1.0.0"

if [ -n "${PTERODACTYL_DIRECTORY:-}" ] && [ -f "${PTERODACTYL_DIRECTORY}/artisan" ]; then
  (cd "${PTERODACTYL_DIRECTORY}" && php artisan view:clear) || true
  (cd "${PTERODACTYL_DIRECTORY}" && php artisan route:clear) || true
fi

PANEL_DIR="${PTERODACTYL_DIRECTORY:-}"
if [ -n "$PANEL_DIR" ] && [ -d "$PANEL_DIR/public/extensions" ]; then
  # Remove the public symlink Blueprint created (never the target data).
  if [ -L "$PANEL_DIR/public/extensions/primus" ]; then
    unlink "$PANEL_DIR/public/extensions/primus" || true
  fi
fi

echo "Primus removed."
echo "Note: primus_* database tables are left intact (settings, AI logs, presets)."
echo "Drop them manually if desired: primus_theme_settings, primus_ai_request_logs, primus_theme_presets."
exit 0
