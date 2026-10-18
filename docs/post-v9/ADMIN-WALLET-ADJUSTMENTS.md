# Administrator wallet adjustment journal — Issue #26

**Source-only candidate; not merged, released, deployed or migrated.**
The original historical V0–V9 schema and its tests remain unchanged.
The only new persistence is the additive
**migrations/post-v9/005_admin_wallet_adjustments.sql** table, independent of
shared payment migration 003 and automatic renewal migration 004.

## Contract

- Authorized primary/delegated administrator uses **increase**, **routine
  decrease**, or separately labeled **decrease with permission to create debt**.
  Routine decrease cannot create/deepen negative debt. A deliberate privileged
  negative balance remains supported, not clamped to zero.
- **Private operator conversation only**: start credit/debit/debt from an
  authenticated inline callback in the administrator's own Telegram private
  chat (`chat.type=private`, `chat.id=operator_userid`). The user selection,
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
- **Navigation contract**: the finance module never interprets a callback
  as an operator reason, and does not itself cancel a pending reason merely
  because an unrelated callback arrives. In the actual `bot.php` route,
  selecting `managePanel` intentionally **cancels** any unfinished credit,
  debit or debt step via `setUser()` and explicitly tells the operator that
  the unfinished adjustment was canceled without moving funds. Thus it is
  incorrect to claim all callbacks preserve `users.step`. The real
  `managePanel` branch is exercised from its actual source in isolated tests.
- One transaction locks administrator and target user rows, rechecks privileged
  identity and the expected flow step, inserts the immutable audit row,
  changes the signed users.wallet balance, and atomically consumes the step.
  The unique source telegram_update_id prevents redelivery/replay, including
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

## 005 schema readiness and migration gate

The source checks InnoDB, exact essential 005 columns/nullable/type/length
contracts, binary adjustment kind, auto-increment primary ID, generated
created-at default, unique Telegram-update index and absence of foreign keys.
It additionally checks that the installed original users table is InnoDB
with the signed INT wallet and expected operator userid, isAdmin and step
column contract. The historical V9 schema file is unchanged: the installed
runtime is checked independently rather than assumed compatible.
An incompatible table is a hard stop, not a missing-table fallback.

**Do not install source 005 route and assume wallet adjustments will work
without migration 005.** Until authorized installation, the old deployed
source stays untouched; this source-only candidate intentionally disables the
operator adjustment UI if 005 is absent. The reverse is also dangerous:
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

This executes the actual current PHP route against disposable, Unix-socket-only
MariaDB and synthetic Telegram. Cases cover absent/malformed 005,
strict numeric input, full UI, debt policy, unique update identity,
two competing PHP workers, nonthrowing mysqli duplicate-key behavior,
balance overflow, SQL rollback, duplicated account identities and
notification ambiguity. The F1/F2/R1/R2 regressions execute the actual
`UpdateBindings.php` semantics and actual `AdminWallet.php` against disposable
MariaDB, including cross-chat group messages at each financial stage, late
private updates, and schema-drift fixtures while 005 remains InnoDB.
The focused navigation case **executes the exact `managePanel` handler extracted
from `bot.php`** in its original order after binding and financial routing;
this is a controlled source-only route characterization, NOT full production
bot bootstrap or live Telegram acceptance.
It invokes no real bot, provider, bank account, payments or production SQL.

There is **no installed/live-money acceptance** in this source-only PR.
The other #26 risks remain separate: referral idempotency, manual evidence,
NOWPayments orphan/IPN reconciliation, provider effect recovery and
manual/hosted/automatic renewal overlap.
