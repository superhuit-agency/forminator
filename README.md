# Forminator

Fork of WordPress plugin [Forminator by WPMU DEV](https://wordpress.org/plugins/forminator)

## Important

This repos has been created in order to create a modification on the codebase of the original plugin.
The modification is to allow to filter the notifications message body when forms are submitted.

Therefore make sure to keep this repo up to date with the original plugin but to ensure
the modified code is not overriden.

1. `$custom_form->notifications` usage in `library/modules/custom-forms/front/front-mail.php:168` see #075b18a0747850f942568da2328a6eb3f0826bc0
2. Add `$original_message param` in `forminator_email_message` filter in `library/abstracts/abstract-class-mail.php:394` see 94d193c79a5ffa9874a44269546b5e83b6b94d73
3. Add `composer.json`
4. Disable WordPress plugin updates in `forminator.php` - adds `disable_plugin_update()` and `disable_auto_update()` methods to prevent plugin from being updated via WordPress admin, see c30f6bad9dd31a9cba67e3a3074d03890c411b77

## Updating from upstream (automated)

This fork includes an automation pipeline that imports the latest **WordPress.org SVN tag** for Forminator and then re-applies this fork's minimal patch.

- **Patch file**: `patches/custom-changes.patch`
- **Sync script**: `tools/sync-wporg-svn.sh`
- **CI workflow**: `.github/workflows/sync-upstream-forminator.yml`

### CI (recommended)

GitHub Actions runs the sync workflow:

- **Automatically weekly** (Monday 03:00 UTC)
- **Manually on demand** (Actions tab → "Sync upstream Forminator (WP.org SVN)" → Run workflow)

If upstream changed, CI will open a PR.

- If the patch applies (or is already present upstream), the PR is a normal PR.
- If the patch cannot be applied and the required modifications are missing, CI will still open a **draft/WIP PR** clearly indicating that the patch was **not** applied and must be fixed manually before merging.

### Running locally

Requirements: `git`, `svn`, `rsync`

Run:

- `bash tools/sync-wporg-svn.sh` (auto-detects latest upstream tag)
- `FORMINATOR_SVN_TAG=1.49.1 bash tools/sync-wporg-svn.sh` (pin a specific tag)

### If the patch stops applying

Upstream can occasionally refactor the relevant code and the patch may fail. When that happens:

1. Update the affected code manually to restore the fork behavior described above.
2. Update `patches/custom-changes.patch` to match the new upstream context.
3. Re-run the sync to confirm the workflow verification passes.
