#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

SVN_BASE_URL="${FORMINATOR_SVN_BASE_URL:-https://plugins.svn.wordpress.org/forminator}"
SVN_TAGS_URL="${SVN_BASE_URL%/}/tags"
PATCHES_DIR="${REPO_ROOT}/patches"

log() {
  printf '%s\n' "$*" >&2
}

out() {
  # Intentionally to stdout so CI can parse it.
  printf '%s\n' "$*"
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    log "Missing required command: $1"
    exit 1
  }
}

require_cmd svn
require_cmd rsync
require_cmd git

QUIET="${FORMINATOR_SYNC_QUIET:-1}"

svn_export_flags=()
rsync_quiet_flags=()
if [[ "${QUIET}" == "1" ]]; then
  # Note: `svn ls` doesn't support --quiet, but `svn export` does.
  svn_export_flags+=(--quiet)
  rsync_quiet_flags+=(--quiet)
fi

pick_latest_tag() {
  # List tags and pick the latest semver-like folder (e.g. 1.49.1/).
  svn ls "${SVN_TAGS_URL}/" \
    | sed 's#/$##' \
    | grep -E '^[0-9]+(\.[0-9]+)+$' \
    | sort -V \
    | tail -n 1
}

SVN_TAG="${FORMINATOR_SVN_TAG:-}"
if [[ -z "${SVN_TAG}" ]]; then
  log "Detecting latest upstream tag from ${SVN_TAGS_URL}/ ..."
  SVN_TAG="$(pick_latest_tag)"
fi

if [[ -z "${SVN_TAG}" ]]; then
  log "Unable to determine upstream tag."
  exit 1
fi

UPSTREAM_URL="${SVN_TAGS_URL%/}/${SVN_TAG}"

log "Using upstream tag: ${SVN_TAG}"
log "Exporting: ${UPSTREAM_URL}"
out "FORMINATOR_UPSTREAM_TAG=${SVN_TAG}"

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

svn export "${svn_export_flags[@]}" --force "${UPSTREAM_URL}" "${TMP_DIR}/upstream"

log "Syncing upstream files into repo (preserving fork-owned files)..."
rsync -a --delete "${rsync_quiet_flags[@]}" \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude 'patches/' \
  --exclude 'tools/' \
  --exclude 'composer.json' \
  --exclude 'README.md' \
  "${TMP_DIR}/upstream/" "${REPO_ROOT}/"

# --- Apply all patches from patches/ in sorted order ---
patch_files=()
for f in "${PATCHES_DIR}"/*.patch; do
  [[ -f "$f" ]] && patch_files+=("$f")
done

if [[ ${#patch_files[@]} -eq 0 ]]; then
  log "No patch files found in ${PATCHES_DIR}/"
  out "FORMINATOR_PATCH_APPLIED=1"
  out "FORMINATOR_PATCH_NOTE=No patches to apply."
  log "OK. Upstream sync completed for tag ${SVN_TAG}. No patches to apply."
  exit 0
fi

ALL_APPLIED="1"
PATCH_NOTES=()

for patch_file in "${patch_files[@]}"; do
  patch_name="$(basename "${patch_file}")"
  log "Applying patch: ${patch_name}"

  if git -C "${REPO_ROOT}" apply --check "${patch_file}" 2>/dev/null; then
    git -C "${REPO_ROOT}" apply --3way "${patch_file}"
    PATCH_NOTES+=("${patch_name}: applied successfully.")
    log "  -> applied successfully."
  else
    # Patch may already be present (e.g. if upstream incorporated the change).
    if git -C "${REPO_ROOT}" apply --check --reverse "${patch_file}" 2>/dev/null; then
      PATCH_NOTES+=("${patch_name}: already applied; skipped.")
      log "  -> already applied; skipped."
    else
      ALL_APPLIED="0"
      PATCH_NOTES+=("${patch_name}: FAILED to apply. Manual action required.")
      log "  -> FAILED to apply. Manual action required."
      # Do NOT fail: CI should still open a WIP/draft PR so it can be fixed manually.
    fi
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

if [[ "${ALL_APPLIED}" == "1" ]]; then
  log "OK. Upstream sync completed for tag ${SVN_TAG}. All patches applied."
else
  log "WARNING. Upstream sync completed for tag ${SVN_TAG} but one or more patches did NOT apply."
  log "WARNING. ${JOINED_NOTES}"
fi

