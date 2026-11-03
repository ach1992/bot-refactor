# RC21 → RC22: scoped add-ons, Cron and safe source/schema pairing

RC22 is a **source-only prerelease**, not an installer or an authorization
to modify any actual bot. Its authoritative assets are on the
[v0.10.0-rc.22 release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.22).
Use the runtime ZIP, matching per-file manifest, SHA256SUMS, SQL 015 sidecar
and **exact tagged source checkout** together. Do not use the auto-generated
GitHub source archive as the runtime ZIP.

## Changes since published RC21

| Area | RC22 |
| --- | --- |
| Day and volume add-ons | Administrator-defined Global, exact logical Server (Marzban/PasarGuard) or PasarGuard native Group-set offers. Selection uses verified current subscription identity and Groups, with Group-set > Server > Global precedence per unit count. |
| Existing imported PasarGuard subscriptions | An authenticated imported, planless account (including native Groups=[]) can buy eligible Server/Global extensions; a Group-specific offer requires matching nonempty current native Groups. Original owner, physical account, original quote, remote readback and financial journaling remain protected. |
| Payments | Source-real PHP 8.5 tests cover scoped Wallet, manual approval, hosted and API routes with replay, review/hold and uncertain remote-effect protection. No live merchant/provider/payment was contacted. |
| Cron | The top-level CLI coordinator initializes a missing *trusted external* lock directory as the current Cron OS user, mode 0700, when permitted. Existing valid locks/inodes are preserved; invalid paths fail closed. RC21 required manual lock provisioning. |
| Validation | Current PHP 8.5 acceptance uses eight native CI families and a strict aggregate; safe source-tree reuse avoids duplicate full validation on merged main. This is not installed-host/PHP compatibility certification. |

The paired runtime contains **121 files, 120 PHP**. It is not a database
backup, an updater, or a migration executor. RC21 runtime and published
metadata remain immutable.

## Database boundary: new optional migration 015

The shipped [015_scoped_addons.sql](../../migrations/post-v9/015_scoped_addons.sql)
is **separate** from the runtime ZIP. It adds **no permanent table**:

| Existing table | Added columns | Added index |
| --- | --- | --- |
| increase_day | server_id (nullable), group_ids (nullable), scope_hash | uq_addon_day_units_scope, unique (volume,scope_hash) |
| increase_plan | server_id (nullable), group_ids (nullable), scope_hash | uq_addon_volume_units_scope, unique (volume,scope_hash) |
| pays | addon_scope_snapshot (nullable) | None |

Each scope_hash is an application-verified SHA-256 scope key; the two
unique indexes prohibit duplicate quantity+scope prices. Legacy Global rows
are preserved with their original IDs, units and amounts. Pre-015 payment
snapshots remain NULL and previously issued quotes retain their original
pinned prices/identity. No invoice backfill is performed.

015 performs a preflight using a temporary stored procedure which the
script drops before its ALTER statements. This procedure is **not**
a permanent application dependency. The preflight rejects any existing
new columns, duplicate legacy quantities, non-positive/invalid units
or negative prices. Do not silently deduplicate or rewrite old prices
to make an install pass. Once the preflight succeeds, three ALTER TABLE
statements add seven columns and two indexes. **MariaDB DDL can commit
partially**: an error after any ALTER is not automatically rolled back.

The new source recognizes **fully absent 015** and can continue the prior
Global-only catalogue. A partial migration (some but not all seven columns)
or missing/wrong indexes/InnoDB is incompatible and fails closed. To
**enable scoped offers**, fully apply and audit 015 in an authorized
installation; before that, do not create scoped offers or assume they
are available. Migration 014 remains a separate inherited prerequisite
for optional SMS/gift API modes. Never replay completed 012, 013 or 014.

## Operator sequence for an existing RC21 installation

These are **instructions for a separately authorized change window**,
not steps performed by Release publication.

1. Identify the *actual* installed bot directory, active web PHP/CLI,
   current manifest/version, owned MariaDB tenant, webhook/Cron and
   native/payment operations. Verify you are upgrading RC21 or plan an
   explicit, version-matched earlier-to-RC21 migration first.
2. Keep the previous release package and private configuration; verify a
   full tenant-specific backup by a **successful isolated restore**, not
   just by file existence. Identify active accounts, pending invoices,
   uncertain native edits, wallet/manual/API/hosted claims and required
   reconciliation before a pause. A timed-out external effect must not
   be replayed or refunded blindly.
3. Obtain an approved maintenance window. Quiesce webhook and Cron
   **without deleting the private Cron lock**, confirm/drain in-flight
   payments and workers, and prevent two code versions writing one DB.
4. With SELECT-only access, inspect the current schema and inherited
   migrations. Confirm 014 as required, all seven 015 columns *absent*
   before first migration, InnoDB table engines, and both extension
   catalogues free of duplicate quantities and invalid rows. Stop for
   any mismatch; do not use blind ALTER retry or `--force`.
5. Rehearse the exact 015 SQL **once** on an isolated restored DB copy
   and verify all legacy IDs/units/prices, original payment rows,
   seven new columns, both unique BTREE indexes (column order,
   uniqueness, no prefix) and transactional InnoDB engines.
6. After **separate migration approval**, apply exactly 015 once to
   the actual tenant in the controlled window. Verify the post-DDL
   schema/row hashes before switching source. If preflight or an ALTER
   fails, leave writers stopped and audit partial DDL. Do not re-run
   the whole script; prepare a reviewed completion or restore plan.
7. Prepare the **entire matching RC22 source-only ZIP** outside the
   live directory, verify every member against its manifest and
   correct retired-file inventory, and preserve private `baseInfo.php`
   and per-tenant data. Activate according to the actual web handler
   and OPcache convergence procedure. Do not perform an arbitrary
   global PHP/LSAPI restart affecting other sites.
8. Verify the served code identity, expected schema, menu/edit
   authorization, Global/Server/Group-set selection, stale callbacks,
   native current-Group checks and controlled payment/replay flows
   under operator-approved isolated accounts. Re-enable webhook/Cron
   only after positive checks and log/health monitoring.
9. Record package SHA, source/tree, 015 schema proof, recovery location,
   exact operator acceptance results and any unresolved held operations.

On a **new empty DB only**, use the ordered [fresh install guide](FRESH-INSTALL.md)
and apply 015 *after* 014. Do not use that baseline import procedure on
an existing RC21 bot or a partly migrated tenant.

## Recovery is not source-only rollback

If 015 has been installed but no scoped invoices have been issued, older
RC21 software still does **not** have an automatically proven compatible
database contract. If scoped invoices or native financial operations have
been issued, dropping 015 or downgrading only the runtime could erase
pinned scope provenance and violate access/financial guarantees. Treat
unexpected effects as held for operator reconciliation; use a
reviewed, tenant-specific **paired database/source restoration** only
when its preconditions and acceptable data loss are established, or
prepare a verified roll-forward. A file-layer ZIP rollback test does not
establish financial/SQL rollback.

## Source and review evidence, not installed acceptance

- Accepted source: independently reviewed and Owner-merged
  [PR #109](https://github.com/ach1992/bot-refactor/pull/109).
  Exact original source HEAD:
  `fe994fb1d1455a0aa6a5969a9c9aec69b42a745b`, tree
  `46b77a67bf52732bb46e5a909c985ecd1c1bddbb`;
  merge commit `dea5d73fe6766e3874ab4104376a8d774c574d2c`
  has the identical tree before RC22 documentation-only updates.
- [Required PHP 8.5 acceptance run 41558467854](https://github.com/ach1992/bot-refactor/actions/runs/41558467854)
  succeeded with all eight families. Native isolated scoped tests reported
  114/114, 61/61 and 28/28 checks across three suites; actual payment/provider
  connections were emulated and no production database was accessed.
- The exact RC22 tag's own manifest/CI and SHA256SUMS are the authority
  for the **published** package (not the earlier merge's zip digest).
- [Detailed add-on contract](ADDON-SCOPES.md),
  [Cron and setup details](FRESH-INSTALL.md),
  [read-only readiness](PAYMENT-READINESS-PREFLIGHT.md) and
  [native PasarGuard constraints](PASARGUARD-CONTRACT.md) remain applicable.
  The wider real installed and financial acceptance stays open in
  [Issue #26](https://github.com/ach1992/bot-refactor/issues/26),
  [Issue #81](https://github.com/ach1992/bot-refactor/issues/81) and
  [Issue #107](https://github.com/ach1992/bot-refactor/issues/107).

**RC22 Release publication authorizes no installed-host contact, migration
execution, live payment, provider mutation, Telegram operation, webhook/Cron
change or payment API activation.**
