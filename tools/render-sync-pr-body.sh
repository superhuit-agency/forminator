#!/usr/bin/env bash
set -euo pipefail

# Print the auto-generated status block of an upstream sync PR description.
#
# Usage: render-sync-pr-body.sh <upstream_tag> <all_patches_applied 0|1> <patch_note>
#
# Shared by the sync workflow (initial PR body) and the PR status workflow
# (refresh after a manual patch fix). The block is wrapped in markers so the
# refresh only replaces this part and keeps any notes added outside of it.

if [[ $# -ne 3 ]]; then
  printf 'Usage: %s <upstream_tag> <all_patches_applied 0|1> <patch_note>\n' "$0" >&2
  exit 1
fi

tag="$1"
applied="$2"
note="$3"

cat <<EOF
<!-- sync-status:start -->
Automated upstream sync from WordPress.org SVN tags.

- Upstream tag: \`${tag}\`
- Local patches re-applied from: \`patches/*.patch\`
- All patches applied: \`${applied}\`
- Patch status: \`${note}\`

If \`All patches applied\` is \`0\`, this PR is intentionally created as WIP/draft and must be fixed manually before merging. Once the fix (code + regenerated \`patches/*.patch\`) is pushed to this branch, this status, the title and the draft state update automatically.
<!-- sync-status:end -->
EOF
