#!/usr/bin/env bash
#
# Fetch the admin UI fonts and serve them from this server.
#
# The panel previously loaded Be Vietnam Pro and JetBrains Mono from
# fonts.googleapis.com. Three reasons that is the wrong default for an admin
# console:
#
#   * Every admin's IP and User-Agent is sent to a third party on every page.
#   * The CSP has to allow an external style-src and font-src, which widens it
#     for no benefit.
#   * A server on a restricted network, or an admin behind a filtering proxy,
#     gets the fallback font — which is exactly the "fonts look wrong" symptom.
#
# admin.css declares @font-face for both families and falls through to the system
# UI stack if these files are absent, so skipping this script degrades the look
# slightly but never breaks the layout.
#
# Usage:
#   bash deploy/fetch-fonts.sh [public/assets/fonts]
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="${1:-$(cd "$SCRIPT_DIR/.." && pwd)/public/assets/fonts}"

# Version-pinned upstream files keep repeat installs reproducible.
GOOGLE_FONTS_REV="1cfe4cb468a703a247ca2d02410f80841b496218"
BE_VIETNAM_PRO_ROOT="https://raw.githubusercontent.com/google/fonts/${GOOGLE_FONTS_REV}/ofl/bevietnampro"
JETBRAINS_MONO_ROOT="https://raw.githubusercontent.com/JetBrains/JetBrainsMono/v2.304/fonts/webfonts"

if [[ -t 1 ]]; then
  C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_OFF=""
fi

ok()   { printf '%s  ok%s %s\n' "$C_OK" "$C_OFF" "$1"; }
warn() { printf '%swarn%s %s\n' "$C_WARN" "$C_OFF" "$1"; }

command -v curl >/dev/null || { warn "curl not installed; skipping fonts"; exit 0; }

install -d -m 0755 "$DEST"

fetch() {
  local url="$1" out="$2"

  if [[ -s "$out" ]]; then
    ok "$(basename "$out") already present"
    return 0
  fi

  if curl -fsSL --max-time 30 -o "$out.tmp" "$url" 2>/dev/null && [[ -s "$out.tmp" ]]; then
    mv -f "$out.tmp" "$out"
    chmod 0644 "$out"
    ok "$(basename "$out")"
    return 0
  fi

  rm -f "$out.tmp"
  # Not fatal: admin.css falls back to the system font stack.
  warn "could not download $(basename "$out") — the UI will use the system font"
  return 0
}

fetch "$BE_VIETNAM_PRO_ROOT/BeVietnamPro-Regular.ttf"  "$DEST/be-vietnam-pro-regular.ttf"
fetch "$BE_VIETNAM_PRO_ROOT/BeVietnamPro-Medium.ttf"   "$DEST/be-vietnam-pro-medium.ttf"
fetch "$BE_VIETNAM_PRO_ROOT/BeVietnamPro-SemiBold.ttf" "$DEST/be-vietnam-pro-semibold.ttf"
fetch "$BE_VIETNAM_PRO_ROOT/BeVietnamPro-Bold.ttf"     "$DEST/be-vietnam-pro-bold.ttf"
fetch "$BE_VIETNAM_PRO_ROOT/BeVietnamPro-ExtraBold.ttf" "$DEST/be-vietnam-pro-extrabold.ttf"
fetch "$JETBRAINS_MONO_ROOT/JetBrainsMono-Medium.woff2" "$DEST/jetbrains-mono-medium.woff2"
fetch "$JETBRAINS_MONO_ROOT/JetBrainsMono-Bold.woff2"   "$DEST/jetbrains-mono-bold.woff2"

printf '\nFonts in %s:\n' "$DEST"
ls -1sh "$DEST" 2>/dev/null | sed 's/^/  /' || true
printf '\nVietnamese renders correctly in the system fallback too, so this is\n'
printf 'cosmetic. Nothing else needs to change.\n'
