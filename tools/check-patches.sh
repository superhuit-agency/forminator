#!/usr/bin/env bash
set -euo pipefail

# Report whether every local patch is present in the current tree.
#
# Used on upstream sync PRs after a manual fix. A patch counts as present when
# it reverse-applies cleanly, which only holds when BOTH the code change is in
# the tree AND the patch file was regenerated against the new upstream context:
# a stale patch whose context no longer matches fails the check, so the next
# sync would not be able to apply it either.
#
# Emits the same FORMINATOR_PATCH_* keys as sync-wporg-svn.sh on stdout.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PATCHES_DIR="${REPO_ROOT}/patches"

log() {
  printf '%s\n' "$*" >&2
}

out() {
  # Intentionally to stdout so CI can parse it.
  printf '%s\n' "$*"
}

patch_files=()
for f in "${PATCHES_DIR}"/*.patch; do
  [[ -f "$f" ]] && patch_files+=("$f")
done

if [[ ${#patch_files[@]} -eq 0 ]]; then
  log "No patch files found in ${PATCHES_DIR}/"
  out "FORMINATOR_PATCH_APPLIED=1"
  out "FORMINATOR_PATCH_NOTE=No patches to apply."
  exit 0
fi

ALL_APPLIED="1"
PATCH_NOTES=()

for patch_file in "${patch_files[@]}"; do
  patch_name="$(basename "${patch_file}")"
  log "Checking patch: ${patch_name}"

  if git -C "${REPO_ROOT}" apply --check --reverse "${patch_file}" 2>/dev/null; then
    PATCH_NOTES+=("${patch_name}: applied.")
    log "  -> applied."
  else
    ALL_APPLIED="0"
    PATCH_NOTES+=("${patch_name}: NOT applied (code change missing or patch file not regenerated). Manual action required.")
    log "  -> NOT applied (code change missing or patch file not regenerated)."
  fi
done

# Join notes into a single line separated by " | "
JOINED_NOTES=""
for note in "${PATCH_NOTES[@]}"; do
  if [[ -n "${JOINED_NOTES}" ]]; then
    JOINED_NOTES="${JOINED_NOTES} | ${note}"
  else
    JOINED_NOTES="${note}"
  fi
done

out "FORMINATOR_PATCH_APPLIED=${ALL_APPLIED}"
out "FORMINATOR_PATCH_NOTE=${JOINED_NOTES}"
