# Wallet adjustment journal — Issue #26

The generic `wallet_adjustments` journal records administrator adjustments
and paired user transfers. Migration **005_wallet_adjustments.sql** creates
the new table for the owner's **RC9 -> RC11** upgrade. It is independent of
shared payment migration 003 and automatic renewal migration 004. There is
no RC10-to-RC11 rename/import path: RC10 will not be installed by this owner,
and its published artifacts remain immutable. If either old or new 005 table
already exists on another installation, stop and audit it separately.

Historical V0–V9 schema/tests remain frozen. This scoped journal does not
invent historical movement rows or replace pays, payment_attempts,
payment_transactions, service_effects or referral_awards. Those retain their
existing payment/business-effect proof. This is not a full historical wallet
statement across all routes.

## Contract

- Authorized primary/delegated administrator uses the two existing controls:
  **increase** and **decrease**. Decrease that would create/deepen debt saves
  a locked before/after preview after the reason, then requires a separate
  explicit private confirmation with a fresh random nonce. No money moves at
  preview. A changed balance invalidates that consent and requires restart.
  Old debt buttons restart the safe Decrease flow; old saved debt reasons
  cannot bypass consent. Deliberate privileged negative balances remain valid.
- **Private operator conversation only**: start credit/debit/debt from an
  authenticated inline callback in the administrator's own Telegram private
  chat (`chat.type=private`, `chat.id=actor_userid`). The user selection,
  positive integer Toman amount and explicit reason must be **real fresh text
  messages in that same private conversation**. Group/supergroup messages,
  other users' private chats and group callbacks cannot advance or complete
  any of these stages. The source does not treat a match against the current
  update's own chat bindings alone as proof of conversation ownership.
- Nothing is adjusted until an explicit reason arrives as a new genuine
  Telegram message, never callback-associated old bot text. Sender,
  source chat, message ID, message type/text and update ID are matched to
  actual `UpdateBindings.php` values. The amount-stage `users.step` also
  carries the originating **amount message ID and update ID**: the reason
  must have **both IDs strictly greater**. Delayed/out-of-order older
  private-chat messages cannot authorize a pending financial adjustment.
  Steps without this freshness marker fail closed and must be restarted.
- **Navigation and concurrency contract**: the finance module never
  interprets a callback as a reason. In the actual `bot.php` route, an
  authorized `managePanel` navigation attempts to cancel the exact cached
  pending credit, debit or debt step **under the same InnoDB operator-row
  lock** used by financial commitment. A conditional `users.step` update,
  single matching operator identity, fresh role check and confirmed commit
  must all succeed before the operator sees **"canceled; no funds from this
  request were applied"**. If a concurrent financial worker has consumed
  the step, or a SQL/COMMIT result is uncertain, the navigation **must not
  declare cancellation or non-execution**: it instead displays an operator
  status/review message, asks for wallet/journal reconciliation and does
  not automatically retry, refund or change the newer operator step.
  Non-financial panel navigation retains its former `setUser()` behavior.
  A callback within the finance module alone does not cancel a pending
  reason; the later real `managePanel` branch owns navigation outcomes.
  This is verified against source-extracted real route code in isolated tests.
- One transaction locks administrator and target user rows, rechecks privileged
  identity and the expected flow step, inserts the immutable audit row,
  changes the signed users.wallet balance, and atomically consumes the step.
  The unique `(source_type, source_id, entry_no)` prevents redelivery/replay,
  including
  from competing PHP workers. Exact operator, target, operation, amount,
  reason, source chat/message and before/after balances are retained.
  The historical users.userid index is nonunique; duplicated target or
  operator identities are rejected before any adjustment.
- Nonpositive, fractional, scientific, overflowing and unsigned/debt-breaking
  routine amounts are refused. Unavailable/incompatible journal schema must
  fail closed before money changes. No fallback to legacy unjournaled writes.
- An uncertain database outcome is **review**, never a reason to automatically
  repeat, compensate, or claim refund. Query the protected adjustment journal
  by exact Telegram update ID and reconcile the target balance and any
  preceding operator action before a separately authorized repair.
  Failed Telegram delivery after commit must never reverse the committed
  financial effect. Do not leak SQL errors or privileged details to customers.

Customer notices state the credited/debited amount and resulting wallet balance.
Administrator notices also state the target numeric user ID, before/after
balances and tracking ID. These facts come from the committed transaction
or its unique reconciled journal row, never a later mutable balance read.
Telegram delivery is best effort and cannot roll back committed money.

## User transfers and identity

Each completed transfer writes two immutable rows under
`source_type='transfer'`, `source_id=<Telegram update ID>`:
entry 1 is `transfer_out` for the sender and entry 2 is `transfer_in` for the
recipient. Both retain the sender as `actor_userid`, each affected wallet as
`target_userid`, positive Toman amount, signed before/after balances and
original update/chat/message IDs. Administrator actions use
`source_type='admin'`, the same source-ID convention and entry 1.

An authenticated private `transferMyWallet` callback starts a versioned
generation under the actor's InnoDB row lock. The selection step is
`achbotTransferRecipient:<start_update_id>:<start_message_floor>`.
The genuine selection message must be strictly newer than the start update
and the retained private-message floor; it atomically claims that exact
generation. The amount step is
`achbotTransferAmount:<recipient_id>:<selection_update_id>:<selection_message_id>:<start_update_id>:<start_message_floor>`.
The amount must have both IDs strictly greater than the selection identity,
and monetary commitment rechecks the entire step and generation under lock.
An old selection cannot acquire a restarted flow, even when that flow has
returned to recipient selection.

One bounded JSON cursor per actor is retained in the existing `setting` table
under `ACHBOT_WALLET_TRANSFER_<userid>`. No new DDL/table is needed beyond
005. It stores the start, selection and last accepted update/message IDs,
phase, actor PK and selected recipient PK. The actor lock serializes absent
cursor creation; only the discovered cursor PK is subsequently locked.
Duplicate/malformed cursors, account incarnation changes and incompatible
storage fail closed. The original `setting.type` is nonunique and unindexed:
discovery uses a nonlocking lookup with LIMIT 2, not a broad locking scan.
This retains one row per actor rather than an append-only workflow history;
it is not a benchmark or a claim of index-backed lookup.

The cursor survives successful completion and generic menu cancellation.
A start callback must be newer than the last accepted flow event; stale or
duplicate starts cannot regress an active or completed generation. Reusing
an old menu in a genuinely new callback never lowers the private-message
floor. Both journal legs, balances, consumed step and cursor completion are
one transaction. Never delete/reset the cursor to work around stale inputs.

All unversioned `transferMyWallet`, old three-field amount markers,
`tranfserUserAmount...` and malformed steps require an authenticated fresh
start. Persian guidance supplies an explicit restart button. Old pending
steps are not migrated into authorization.

Telegram documents that update IDs may restart at a random value after at
least a week without updates ([official Update contract](https://core.telegram.org/bots/api#update)).
This implementation deliberately refuses IDs below its retained cursor;
it does not infer a safe new epoch from a lower ID or automatically erase
replay history. If that availability boundary is encountered, transfers for
affected actors stay blocked pending separately authorized reconciliation
of the exact bot, retained cursor, journal and incoming Telegram identities.
A routine retry/restart cannot bypass it. Recipient/actor deletion and
recreation similarly require reconciliation rather than silent rebinding.

Stable user locks, sufficient sender balance, recipient overflow checks,
both wallet updates, step consumption and both journal INSERTs share one
InnoDB transaction. Failure of either INSERT rolls everything back.
Missing/incompatible 005 blocks transfers as well as admin adjustments.
The generic unique `(source_type,source_id,entry_no)` is supplemented by
`uq_wallet_telegram_entry (telegram_update_id,entry_no)`, independent of
operation type. Admin and transfer operations both own entry 1; only a
transfer adds entry 2. The insert helper enforces those leg/kind contracts.
An update already consumed by an admin adjustment cannot authorize a
transfer, and vice versa, while an ordinary transfer still has two legs.
Intentional admin debt is retained; incoming credit can reduce recipient
debt but an ordinary transfer cannot deepen sender debt.

A lost COMMIT acknowledgement remains under review, even if a later rollback
API call returns success. Inspect the two legs and balances by source ID;
never auto-repeat or compensate an uncertain outcome.

## 005 schema readiness and migration gate

The source checks InnoDB, exact essential 005 columns/nullable/type/length
contracts, binary adjustment kind, auto-increment primary ID, generated
created-at default, binary source type/ID, unsigned leg number,
unique source/leg and cross-operation Telegram update/leg indexes, and absence of foreign keys.
It additionally checks that the installed original users table is InnoDB
with the signed INT wallet and expected operator userid, isAdmin and step
column contract. The historical V9 schema file is unchanged: the installed
runtime is checked independently rather than assumed compatible.
An incompatible table is a hard stop, not a missing-table fallback.

**Do not install source 005 route and assume wallet adjustments will work
without migration 005.** Until authorized installation, the old deployed
source stays untouched; this source-only candidate intentionally disables the
operator adjustment UI and user transfer commit if 005 is absent. The reverse is also dangerous:
deploying migration 005 then reverting to an older source with legacy direct
wallet UPDATEs bypasses the audit. Before any real rollout:

1. Obtain separate Owner authorization specifying source commit, tenant,
   timing and database. Verify installed source/schema/version first.
2. Take verified access-controlled file and SQL backups and rehearse a
   successful restore in an isolated environment. Preserve existing wallet
   balances, all pays/payment attempts and financial journals.
3. Rehearse additive migration 005 against the actual schema shape and
   check the exact 005 readiness contract. Do not mutate 001/002/003/004 or
   original tables.
4. Stage the matching source and SQL in a coordinated maintenance plan,
   verify new admin credit, routine debit, explicit debt, replay, concurrent
   attempts, safe operator diagnostics and user notifications on staging.
5. Verify backup and restore evidence and obtain independent review plus
   separate Owner go/no-go for real installation. Production, releases,
   migrations and real balances are expressly outside source-only acceptance.

**Rollback:** stop the financial adjustment entrypoints, reconcile any
uncertain operation IDs, and restore a source version that still enforces
journal-backed adjustments (or an explicitly approved compatible guard).
Never roll back to a direct-wallet legacy route while new audited actions
are active. Never DROP 005 or remove audit rows to simplify rollback.

## Reproducible source-only proof

Run:

    python3 tools/test_post_v9_admin_wallet.py --run-disposable
    python3 tools/test_post_v9_admin_wallet_ux.py --run-disposable
    python3 tools/test_post_v9_wallet_transfer.py --run-disposable
    python3 tools/test_post_v9_wallet_replay.py --run-disposable
    python3 tools/test_post_v9_transfer_generation.py --run-disposable

This executes the actual current PHP route against disposable, Unix-socket-only
MariaDB and synthetic Telegram. Cases cover absent/malformed 005,
strict numeric input, full UI, debt policy, unique update identity,
two competing PHP workers, nonthrowing mysqli duplicate-key behavior,
balance overflow, SQL rollback, duplicated account identities and
notification ambiguity. The F1/F2/R1/R2/R3 regressions execute the actual
`UpdateBindings.php` semantics and actual `AdminWallet.php` against disposable
MariaDB, including cross-chat group messages at each financial stage, late
private updates, schema drift and nonthrowing SQL errors.
The focused navigation cases **execute the exact `managePanel` handler
extracted from `bot.php`** in its original order after binding and financial
routing. Real independent PHP processes pause after loading the operator
snapshot to deterministically prove both race orders (finance commit first,
navigation cancellation first), duplicate navigation, stale next-step
preservation, delegated-role revocation and a forced SQL failure.
This is controlled source-only route characterization, NOT a full production
bot bootstrap or live Telegram acceptance.
It invokes no real bot, provider, bank account, payments or production SQL.

The R3 generation suite reproduces the wrong-recipient ABA defect on exact
reviewed source `18aa3629290db60a1a6e7c9be9c9f47464dcf333`, then verifies
the current source using genuine start callbacks, selections and amounts.
It covers completed A -> restarted B -> redelivered A selection; unchanged
B state/money/journal; stale/duplicate/reordered starts; fresh selection of
recipient Y; old-menu reuse; two-worker start races; malformed/duplicate
cursor storage; cursor-write rollback and recipient incarnation changes.
Generic navigation is modeled by clearing `users.step` while retaining the
cursor; this suite does not claim execution of the full cancellation route.

There is **no installed/live-money acceptance** in this source-only PR.
The other #26 risks remain separate: referral idempotency, manual evidence,
NOWPayments orphan/IPN reconciliation, provider effect recovery and
manual/hosted/automatic renewal overlap.
