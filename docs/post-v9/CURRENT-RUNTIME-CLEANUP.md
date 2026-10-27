# Current runtime cleanup (optional sidecar 010)

This audit is pinned to source 621307be775163f0b85ae9fa5b21b1d0b654cfed.
It changes no runtime PHP, public endpoint, Cron registration, installed file,
tenant schema, published package, or frozen V0–V9 evidence.

## Source dependency findings

CLEANUP-DEPENDENCIES.json records each of the 104 packaged members, its SHA256,
and incoming include sites or explicit public/template role. It also records
original and additive table query sites, grouped by source file and operation.
Regenerate from the checked-out current source with:

    python3 tools/audit_post_v9_dependencies.py --output /outside-repo/dependencies.json

All 104 members have a current role. The root loaders are current facades;
settings entrypoints remain scheduled/public contracts. The root index.html is
an existing public landing page. phpqrcode/qrlib.php:6 loads the actual merged
encoder phpqrcode/phpqrcode.php. No additional whole-file deletion is justified. Every tracked runtime PHP/HTML
file outside tests/tools/docs is already in this inventory; there is no
unpackaged extra runtime implementation. No retired automatic TRON/WeSwap
file name or integration reference remains in these current runtime members.

Twenty of the 23 original tables have current literal SQL readers/writers:

| Tables | Current use |
| --- | --- |
| users, setting | identity, admin/ban/agent fields and configuration |
| server_info, server_categories, server_plans, server_config | current panels, plans and accounts |
| orders_list, pays | subscription and financial evidence |
| increase_day, increase_plan, increase_order | extension plans and applied extensions |
| discounts | discount administration and redemption |
| chats, chats_info | support threads and messages |
| add_link | external/manual account ownership links |
| gift_list | gift queue and processing |
| needed_sofwares | customer software/download screen |
| bc_jobs, bc_messages, send_list | broadcast jobs, persisted messages and checkpoints |

The eight additive tables in migrations 003–009 also have current consumers;
their existence on a particular tenant is not inferred from source. Original
admins, black_list and servers have zero current literal SQL table references.
Their functions have not moved to equally named replacement tables: current
administrator/ban behavior uses users, and current panel behavior uses
server_info. They remain optional retirement candidates, not certified tenant
deletions.

Manual review of every dynamic table target found:

| Source | Bounded targets |
| --- | --- |
| src/Jobs/Checkpoint.php and all callers | bc_jobs and send_list |
| src/Finance/ReceiptHelpers.php, achbotManualLedgerMode | payment_attempts and payment_transactions |
| src/Purchase/Core.php, extension support query | increase_day or increase_plan |
| config.php, fetchData and constructed reporting queries | literal current users/orders/pays/plans/panel tables; value/condition fragments do not introduce another table target |
| config.php, checkStep | explicit server_plans/server_categories branches |

This corrects the preliminary Issue #29 milestone's sended_notifications name:
the actual broadcast-send checkpoint target is send_list. A lexical reference
inventory is not proof of tenant emptiness, metadata visibility, generic
external clients, old integrations, retention requirements, or active schedules.

## Optional empty-table retirement

migrations/post-v9/010_cleanup_manifest.json pins the three original CREATE
TABLE fingerprints and exact known retired-source variants. There is
deliberately no unconditional DROP script or automatic migration marker.
010 is never a prerequisite for readiness, startup, or financial migrations.
Keep all three tables when their disposition is unknown.

tools/cleanup_post_v9.php is an explicit socket-only maintenance CLI. It never
loads baseInfo.php. Requirements: PHP CLI with mysqli and posix, a local MariaDB
socket, and a private JSON config owned by the invoking user with mode 0600:

    {"socket":"/canonical/private/mysql.sock","database":"identified_database","user":"maintenance_user","password":"private"}

The default command only inspects:

    php tools/cleanup_post_v9.php --config=/private/cleanup-connection.json --table=admins

It accepts only admins, black_list or servers, as an exact BASE TABLE. It
requires the original normalized SHOW CREATE TABLE fingerprint, including
columns, indexes, engine and collation. Only whitespace and the table-level
AUTO_INCREMENT allocation counter are ignored. Other server-version formatting
differences may be refused and require review; do not relax a fingerprint
against an unknown tenant table.

Global SELECT, SHOW VIEW, TRIGGER and EVENT visibility must be proven from
direct global grants (ALL PRIVILEGES also qualifies). Role-only/limited
visibility fails closed. Any incoming or outgoing foreign key is refused.
Because text search cannot prove dynamic or transitive stored dependencies,
the small tool conservatively refuses when ANY non-system schema contains a
view, routine, trigger or event, including unrelated ones. It does not attempt
a general dependency graph. A table with even one row is preserved without
printing its contents. SQL/connection failures print a sanitized refusal.

An operator must independently identify external consumers, retention and an
exclusive maintenance window. A target-specific, private disposition JSON
must copy table, database, identity_sha256 and schema_sha256 from a fresh plan
and explicitly include:

    {
      "table":"admins",
      "database":"identified_database",
      "identity_sha256":"COPY_FROM_PLAN",
      "schema_sha256":"COPY_FROM_PLAN",
      "external_consumers":"none-confirmed",
      "retention":"empty-no-retention",
      "maintenance":"exclusive-no-writers-or-ddl",
      "operator":"identified_operator",
      "approval_reference":"exact_separately_authorized_operation"
    }

Apply is separately authorized maintenance, never part of ZIP extraction:

    php tools/cleanup_post_v9.php --config=/private/cleanup-connection.json --table=admins --apply --disposition=/private/cleanup-disposition.json --restore-ddl=/private/new-admins-empty-restore.sql

The tool validates the disposition, obtains a WRITE table lock, and rechecks
the schema, emptiness, dependencies and server/database/socket identity on the
same connection immediately before the exact DROP. It first writes and syncs
the empty-table CREATE statement to a new mode-0600 restore file; existing
paths are never overwritten. MariaDB DDL is not transactional. A lost response
requires inspection, not a blind repeat. A restore is separately reviewed:
confirm the same tenant and table absence, then run the captured CREATE DDL.
The restore file contains no rows. Do not restore or delete data generically.
Do not apply this tool to any real installation without its separate explicit
authorization and applicable backup/recovery gates.

## Installed retired-source reconciliation

Only these already-retired paths are considered:

- settings/tronChecker.php
- src/Finance/CryptoChecker.php
- src/Finance/Routes/CryptoAndExchange.php

The manifest allows exact SHA256/size variants from original V9
7eb077fd8e6d0a303421626d1500f69e295a6664 and final pre-deletion source
68d0109349e579829ab48f0ee7d872359d1b6306. Unknown/custom variants require
separate review and are never deletion candidates. No wildcard, recursive
cleanup, data/private config, historical evidence or unrelated bot is scanned.

tools/audit_retired_runtime.py is read-only; it has NO deletion or Cron-edit
operation. It first authenticates the complete current release manifest against
a separately trusted SHA256 and expected commit, then verifies every current
installed source member with the existing descriptor-relative no-symlink
checker. The canonical installation path plus explicit installation, operator
and schedule-inspection identities are required. Matching files do not prove
the active webhook/web PHP worker, and the tool does not discover or modify
schedules. The operator must independently establish that no retired worker
registration remains before a removal can be reviewed.

Its disposition JSON is:

    {
      "install_dir":"/canonical/identified/install",
      "installation_id":"explicit_bot_and_install_identity",
      "operator":"identified_operator",
      "schedule_inspection_reference":"specific_current_schedule_evidence",
      "retired_schedule_state":"none"
    }

Run with the current trusted source-only release manifest:

    python3 tools/audit_retired_runtime.py --install-dir /canonical/identified/install --manifest /private/current-release-manifest.json --trusted-manifest-sha256 TRUSTED_SHA256 --expected-commit EXACT_CURRENT_RELEASE_SHA --disposition /private/install-disposition.json

Reports distinguish absent, known-retired-review-removal, and
refused-unknown-or-unsafe. The known status is review evidence, not deletion
authorization. At a separately approved deployment, back up each exact known
source file outside the install, recheck installation/schedule/path/hash
identity, remove only reviewed exact paths, and verify the current manifest
again. Preserve installed data, private configuration and other instances.
An overlay ZIP by itself does not reconcile removed files.

## Focused verification

    python3 tools/test_post_v9_cleanup.py --run-disposable --report /outside-repo/cleanup-report.json

The test owns a private socket-only MariaDB instance and synthetic installation.
It checks all three empty plan/apply/DDL-restore paths; preserves all three
nonempty candidates; refuses schema drift, foreign keys, views, routines,
triggers, events, incomplete metadata visibility, missing/mismatched operator
dispositions and existing restore output; and checks exact retired hashes,
unknown-hash and symlink refusal, absent paths, installation/schedule identity,
and preservation of current/private/unrelated files. No real database, provider,
installation or schedule is used. Master owns aggregate exact-candidate
PHP 8.1/8.4, frozen-history, readiness, packaging and independent review gates.
