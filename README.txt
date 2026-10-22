Directorist Support Tools 1.1.0
==============================

Listing migration recovery
--------------------------
Tools > Directorist Migration Recovery supports Pricing Plans 4.2.0 with the
verified native listing migration implementation. Use it only when unfinished
running progress remains despite listing-plan-meta-v4-0-2 being recorded complete.

Review the preview, confirm the batch and click Recover next ... listings.
Repeat until complete, download the backup, verify listings and deactivate.
Each click executes only the native listing migration for up to 250 listings.
The existing completed marker remains as a guard against automatic continuation.
The cursor is preserved. No full v4, package or billing-date repair is invoked.

The action can change _plan_id on eligible listings. Valid legacy assignments
and listings without an eligible package are preserved. All post statuses are
included, as in the native migration. Packages, prices, quotas, settings and
the completed-migration list are not modified by the recovery action.

Prior _plan_id values, expected values and original progress are saved in the
non-autoloaded directorist_support_tools_listing_recovery_v1 option. Each batch
and its cumulative backup commit in one transaction; failures roll back the
batch. InnoDB is required for posts, postmeta, options and user packages.
The backup is limited to 5,000 changed listings and 4 MB and survives removal.
An existing completed/restored recovery backup prevents another recovery from
silently overwriting it. Downloaded JSON has no automatic import action.

Restore recovery backup returns backed-up plan values and original progress,
including the stalled condition. It rejects later assignment/progress changes
and preserves other migrations. Values changed away and back cannot be detected.
Original metadata row IDs are not reused; duplicate-value order is preserved.
Modified migration source, other versions, failed state, invalid counts and
ordinary unmarked migrations require investigation rather than this action.

Plan priority repair purpose
-------
Repair Pricing Plans 4.x saves that fail with this exact API validation message:
"The listing_display_priority must be at least 1."

Legacy plans can have priority 0 while the current API requires at least 1.
When display-priority sorting is disabled, that field is hidden in the editor
but is still submitted. This tool repairs that data condition on demand.
It is not a general fix for every 422 error or a replacement for a Core fix.

Use on a client site
--------------------
1. Confirm that the failed save response names listing_display_priority.
2. Upload the ZIP using Plugins > Add Plugin > Upload Plugin, then activate.
3. Open Tools > Directorist Support Tools and review the preview.
4. Select only the affected plans, then click Repair selected plans.
5. Reload the plan editor, save a small test change, reload to verify, then
   restore the original test content and save again.
6. Deactivate the tool when finished. The repair and backup remain saved.

Scope
-----
Only selected records in the current site's directorist_plans table with an
integer priority below 1, or NULL, are changed to 1. Valid priorities are skipped.
No activation-time repair, runtime request filter, automatic migration, package
update, quota change, feature change, or pricing change is included.
The UI handles up to 200 plans per batch. Backups accumulate across batches.

Restore
-------
Tools > Directorist Support Tools > Restore previous priorities restores only
the priority column. A plan whose priority has since changed from 1 is skipped
and its previous value stays in the backup. Other plan edits are preserved.
Restoring invalid old priorities may bring the original save error back.
Restoration cannot detect an edit that changes a priority away from 1 and then
back to 1; review later edits before choosing Restore.

Compatibility and storage
-------------------------
Requires WordPress 6.0+, PHP 7.4+, and active Directorist Pricing Plans 4.x.
Tested locally with WordPress 7.1.3, Directorist 8.10.5, Pricing Plans 4.2.0.
InnoDB is required for the plans and options tables to keep data and backup
changes in one transaction. An unsupported environment stops without repair.
The backup stores plan IDs, previous priorities, and a UTC repair timestamp in
the current site's non-autoloaded directorist_support_tools_priority_backup_v1
option. It is retained when the tool is deactivated or removed; reinstalling
this tool makes it available again. No credentials or customer contact data
are collected, and no external service is called.
