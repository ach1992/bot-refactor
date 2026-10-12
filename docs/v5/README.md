# AChBot V5 — complete broadcast administration and scheduled processing

**Scope:** One reversible, source-only, Phase 1 refactor of the complete broadcast management lifecycle in `bot.php`, both background processors of `settings/messagewizwiz.php`, and the warning/expiry-notification **portion** of `settings/warnusers.php`. The auto-renewal function and financial/entitlement processing remain in the original file, byte-unchanged. This is **not** a feature redesign, migration, production-certified release, or authorization for unattended rollout. [Issue #14](https://github.com/ach1992/bot-refactor/issues/14) controls this increment.

## Executable boundaries

| Existing entrypoint | New internal module | Exact behavior-preserving seam |
|---|---|---|
| `bot.php` | `src/Broadcast/Routes.php` | 892 old broadcast/pin/job administration lines remain in the original order and caller scope. A nested `require` that returns `null` propagates old route-level bare `return;` to the webhook. Successful include completes with `1`. No new first-match router or callback encoding. |
| `settings/messagewizwiz.php` | `src/Jobs/Checkpoint.php` | Original functions for heartbeat, paused, cancelled, finished, and current timestamp. Executed at the original declaration position. |
| `settings/messagewizwiz.php` | `src/Jobs/MessageActions.php` | Original `bc_jobs` lifecycle including 30-minute stale requeue, first queued claim, 200-row fetch, 20-item/10-second heartbeat, job cancellation, shutdown handler and pin/unpin/edit/delete Telegram calls. Three `goto` branches become `return;` from this included section only; the original cron continues with the broadcast processor. |
| `settings/messagewizwiz.php` | `src/Jobs/BroadcastSend.php` | Original `send_list` 15-minute stale claim, 400-row cursor batch, recipient/audience filters, 10-recipient/10-second heartbeat, bulk sending, recorded message IDs, 429 behavior, partial errors and shutdown checkpoint. Its error log remains at `settings/error_log.txt` using `dirname(__DIR__, 2)` after extraction. |
| `settings/warnusers.php` | `src/Notifications/Warnings.php` | Original expiry warnings and expired-subscription removal checks, `warnOffset` and notifications. Auto-renewal `AutoRenewCheck` and `handleAutoRenewCallback()` stay byte-identical in the old entrypoint; see [scope limitations](LEGACY-BEHAVIOR.md). |

All new modules have direct-execution 404 guards. No schema, storage format, public callback, Telegram payload, text/keyboard or legacy cron filename changes. The previous **38 runtime files** plus **5 internal modules** yield **43 source-only runtime files**. Exactly 35 old runtime files remain unchanged; only `bot.php`, `messagewizwiz.php` and `warnusers.php` change their include structure.

## Real source provenance and verification

- Pinned V4 source reference: `ff81d1b8d8832ec17fea7b07dabebc37614d29f2`, tree `80a84c8dff6edd0754acd2d4f17d1211c2112267`. These are intentionally pinned *review baselines*, never assumptions about future `main`.
- `tools/v5_contract.py` reconstructs all changed runtime files from that immutable reference using anchored regions and compares the complete byte sequence, excluding **only** three deliberate `goto`→`return` transforms, the preserved error-log path, original route-return compatibility adapter, and the extra module guards. It also rejects runtime file additions/removals, modified historical evidence, symlinks and DDL changes.
- [TESTING.md](TESTING.md) documents the complete frozen V0–V4 and V5 synthetic test matrix. [CRON-INVENTORY.md](CRON-INVENTORY.md) records non-scoped scheduler dependencies. [OPERATIONS.md](OPERATIONS.md) covers staging, file switch and rollback.

**Official visible name:** AChBot. Historical `wizwiz` strings remain valid protocol/cron compatibility contracts. An independent HIGH_ASSURANCE review and real operator approval are still required before V5 merge or deployment. V4 operator feedback was a development go-ahead, not production acceptance of V5.
