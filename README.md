# Directorist Support Tools

A small, manual repair tool for Directorist Pricing Plans 4.x plans that fail to save with:

```text
The listing_display_priority must be at least 1.
```

Legacy plans can retain priority `0`. The current API requires a minimum of `1`, even when the priority control is hidden in the editor.

## Use

1. Download the installable ZIP from Releases and upload it through WordPress **Plugins → Add Plugin → Upload Plugin**.
2. Activate it and open **Tools → Directorist Support Tools**.
3. Review the preview, select the affected plans, and choose **Repair selected plans**.
4. Reload the plan editor. Save a small test edit, reload to verify persistence, and restore the test content.
5. Deactivate the tool when finished. No persistent runtime workaround is needed.

The tool only changes invalid priorities on selected plans to `1`. It does not run migrations or change packages, prices, quotas, features, or Directorist settings. Activating it makes no data changes.

## Backup and restoration

Each repair saves the previous priorities in a non-autoloaded WordPress option in the same database transaction as the repair. Batches accumulate into that backup.

**Restore previous priorities** restores only this column. It skips plans whose priority is no longer `1` and keeps their backup records for review. Other plan edits are preserved. Restoring invalid values can bring back the save error. It cannot detect a priority changed away from `1` and later changed back to `1`.

Backups survive deactivation and removal, so reinstalling the tool makes them available again.

## Compatibility

- WordPress 6.0+, PHP 7.4+, Directorist Pricing Plans 4.x.
- InnoDB plans and options tables, to keep the repair and backup atomic.
- Up to 200 selected plans per batch.
- Locally tested with WordPress 7.1.3, Directorist 8.10.5, and Pricing Plans 4.2.0.

This tool addresses this exact data condition. It does not fix unrelated `422` responses, migration failures, or listing-quota errors, and is not a permanent Core compatibility fix.

## Local verification

Use a disposable WordPress project with Directorist and Pricing Plans active. The verification script creates and removes synthetic plans and a temporary database trigger to test rollback. It refuses to run when an existing repair backup is present.

```sh
wp --path=/path/to/local/wordpress eval-file wp-content/plugins/directorist-support-tools/tests/verify-local.php
```

Coverage includes the original API save failure, valid-value preservation, selected repair, backup, idempotency, forced database failure and rollback, subsequent API persistence, restoration conflicts, and preservation of other plan fields, packages, and settings. The browser flow is also checked locally.

See [README.txt](README.txt) for operational details and backup storage.
