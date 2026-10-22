# Directorist Support Tools 1.2.0

Manual tools with preview, backup and restoration. Activation does not repair data. There are two separate Tools pages:

- **Directorist Support Tools** repairs invalid plan display priorities.
- **Directorist Migration Recovery** recovers the specific completed-marker / unfinished-listing-progress conflict described below.

## Listing migration recovery

Use this action when `listing-plan-meta-v4-0-2` is already recorded in `directorist_pricing_plans_migrations`, but `directorist_listing_plan_meta_migration_state` still contains valid `running` progress. For example, the notice stays at **250 of 967** and the normal runner skips the listing migration.

The recovery currently accepts **Pricing Plans 4.2.0** with the exact verified `ListingPlanMetaMigration.php` implementation. Other versions, modified migration source, failed progress, missing tables and ordinary unmarked migrations are rejected before recovery.

1. Install or update the ZIP, activate it, then open **Tools → Directorist Migration Recovery**.
2. Review the saved progress, current number of remaining listings, and assignment preview. Expand the listing table to review IDs, existing plan values and eligible package plans.
3. Confirm the reviewed batch and choose **Recover next … listings**. Each click runs at most 250 listings through Pricing Plans' own listing migration.
4. Review and run subsequent batches until the page reports completion and the migration progress notice disappears. For 717 remaining listings, this normally takes three batches: 250, 250 and 217. If an exact-size final batch leaves no listings remaining, choose **Finalize saved migration** to finish the native completion step.
5. Download the recovery backup, verify the affected listings, and deactivate the tool.

The tool deliberately keeps the existing completed marker in place while invoking only the listing migration directly. This prevents the normal runner from taking over the same listing recovery between manual batches. It does not reset the cursor, re-run the full v4 migration, or invoke package/date repairs.

The native migration normalizes `_plan_id` to an eligible package's plan. Valid legacy assignments and listings without an eligible package retain their values. The displayed historical total may differ from the current listing count. The preview includes all post statuses, matching the native migration.

Each committed batch stores its affected listings' prior and expected `_plan_id` values, including duplicates in their original order, alongside the original migration progress. The backup and native changes share one InnoDB transaction. A database error or unexpected assignment/progress rolls back that batch. Stale previews are rejected. Packages, prices, quotas, billing dates and the completed-migration list are not changed by this action.

### Restore recovery

Expand **Restore the original state** and confirm **Restore recovery backup**. This restores only backed-up plan values and the original progress, including the original stalled condition. Other completed migrations and other listing fields are preserved. Restoration stops without partial changes if a backed-up plan assignment or migration progress was edited later. It cannot detect a value changed away from and then back to the expected value. Metadata row IDs are not restored; plan values and their relative order are restored.

The recovery backup lives in the non-autoloaded `directorist_support_tools_listing_recovery_v1` option and survives deactivation/removal. It is limited to 5,000 changed listings and 4 MB. One recovery backup is retained; an existing completed/restored backup blocks a new unrelated recovery so it cannot be silently overwritten. The JSON download is for evidence/manual recovery, not an automatic import action. It contains listing IDs, plan values and migration metadata, not credentials or contact fields.

## Plan priority repair

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
5. Keep the tool active for stale-editor protection, or reload all open plan editors before deactivating after repair. The corrected database values remain saved.

This separate action only changes invalid priorities on selected plans to `1`. It does not run migration recovery or change packages, prices, quotas, features, or Directorist settings.

While active on Pricing Plans 4.x, a narrow admin-update filter also handles stale editor tabs submitting integer/string `0` for `listing_display_priority`. It preserves the current valid priority or substitutes `1` for a legacy invalid value. It does not write on activation, alter other request fields, or bypass other API validation. Invalid non-zero inputs remain subject to the product validator. This companion guard survives updates to the main Pricing Plans plugin; keep this tool active if that protection is needed.

## Backup and restoration

Each repair saves previous priorities in a non-autoloaded WordPress option. Batches accumulate into that backup. On InnoDB tables, the repair and backup commit in one transaction. For MyISAM/Aria, the tool obtains an advisory lock, saves and reads back the backup before changing any plan, and updates each priority only if its original value still matches. A failure can leave a partially repaired batch; the error reports the count and keeps the backup for restoration. It does not convert table engines.

**Restore previous priorities** restores only this column. It skips plans whose priority is no longer `1` and keeps their backup records for review. Other plan edits are preserved. Restoring invalid values can bring back the save error. It cannot detect a priority changed away from `1` and later changed back to `1`.

Backups survive deactivation and removal, so reinstalling the tool makes them available again.

## Compatibility

- WordPress 6.0+, PHP 7.4+, Directorist Pricing Plans 4.x.
- Plan-priority repair: InnoDB, MyISAM or Aria plans/options tables. Unknown storage engines block writes; preview remains available. MyISAM/Aria require database advisory-lock support.
- Listing Migration Recovery still requires InnoDB for all its tables.
- Up to 200 selected plans per batch.
- Locally tested with WordPress 7.1.3, Directorist 8.10.5, and Pricing Plans 4.2.0.

Priority repair addresses only that save-validation condition. Migration Recovery addresses only the supported inconsistent listing-migration state. Neither is a general migration reset or permanent Core compatibility fix.

## Local verification

Use a disposable WordPress project with Directorist and Pricing Plans active. The verification script creates and removes synthetic plans and a temporary database trigger to test rollback. It refuses to run when an existing repair backup is present.

```sh
wp --path=/path/to/local/wordpress eval-file wp-content/plugins/directorist-support-tools/tests/verify-local.php
```

Coverage includes the original API save failure, valid-value preservation, selected repair, backup, idempotency, forced database failure and rollback, subsequent API persistence, restoration conflicts, and preservation of other plan fields, packages, and settings. The browser flow is also checked locally.

For the guarded official API save flow, run `wp eval-file wp-content/plugins/directorist-support-tools/tests/verify-priority-request.php`. This verifies stale-zero handling, preservation of valid ranks, Unlimited Listing persistence and unrelated validation.

For isolated MyISAM and mixed-engine checks, also run `wp eval-file wp-content/plugins/directorist-support-tools/tests/verify-priority-storage.php` on a disposable `.local` or `.test` project. This checks durable backup before mutation, partial failure reporting/restoration, valid-value preservation and NULL restoration.

See [README.txt](README.txt) for operational details and backup storage.

### Migration recovery verification

Run on a disposable `.local` or `.test` site:

```sh
wp --path=/path/to/local/wordpress --skip-plugins --skip-themes eval-file wp-content/plugins/directorist-support-tools/tests/verify-migration-recovery.php
```

The test uses isolated disposable tables with 967 synthetic listings. Coverage includes 250/967 reproduction; preview without writes; active and ending package selection; legacy-plan preservation; other-directory packages; duplicate metadata; stale requests; capability/nonce gates; backup-failure rollback; three-batch completion; restoration conflicts; unrelated data preservation; and fixture cleanup. The real local WordPress browser form is also verified through completion.
