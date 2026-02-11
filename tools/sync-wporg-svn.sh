#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

SVN_BASE_URL="${FORMINATOR_SVN_BASE_URL:-https://plugins.svn.wordpress.org/forminator}"
SVN_TAGS_URL="${SVN_BASE_URL%/}/tags"
PATCH_FILE="${REPO_ROOT}/patches/custom-changes.patch"

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

verify_fork_patch() {
  grep -Fq "apply_filters( 'forminator_email_message', \$message, \$this->message );" \
    "${REPO_ROOT}/library/abstracts/abstract-class-mail.php"

  grep -Fq "foreach ( \$custom_form->notifications as \$notification )" \
    "${REPO_ROOT}/library/modules/custom-forms/front/front-mail.php"
}

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

if [[ ! -f "${PATCH_FILE}" ]]; then
  log "Patch file missing: ${PATCH_FILE}"
  exit 1
fi

log "Re-applying fork patch: ${PATCH_FILE}"
PATCH_APPLIED="0"
PATCH_NOTE=""

if git -C "${REPO_ROOT}" apply --check "${PATCH_FILE}"; then
  git -C "${REPO_ROOT}" apply --3way "${PATCH_FILE}"
  PATCH_APPLIED="1"
  PATCH_NOTE="Patch applied successfully."
else
  # Patch may already be present (e.g. if upstream incorporated the change).
  if verify_fork_patch; then
    PATCH_APPLIED="1"
    PATCH_NOTE="Patch appears already applied; skipping."
  else
    PATCH_APPLIED="0"
    PATCH_NOTE="Patch did not apply and required fork modifications are not present. Manual action required before merging."
    log "${PATCH_NOTE}"
    # Do NOT fail: CI should still open a WIP/draft PR so it can be fixed manually.
  fi
fi

log "Verifying required fork modifications are present..."
if verify_fork_patch; then
  PATCH_APPLIED="1"
else
  PATCH_APPLIED="0"
  if [[ -z "${PATCH_NOTE}" ]]; then
    PATCH_NOTE="Required fork modifications are missing. Manual action required before merging."
  fi
fi

out "FORMINATOR_PATCH_APPLIED=${PATCH_APPLIED}"
out "FORMINATOR_PATCH_NOTE=${PATCH_NOTE}"

if [[ "${PATCH_APPLIED}" == "1" ]]; then
  log "OK. Upstream sync completed for tag ${SVN_TAG}. Patch is applied."
else
  log "WARNING. Upstream sync completed for tag ${SVN_TAG} but patch is NOT applied."
  log "WARNING. ${PATCH_NOTE}"
fi

