# RC40 → RC41 — safe API payment staff-digest lock order

## Scope

RC41 contains the independently reviewed
[PR #178](https://github.com/ach1992/bot-refactor/pull/178), focused on the
[Issue #26](https://github.com/ach1992/bot-refactor/issues/26) MariaDB
1213 deadlock observed in the RC40 API payment staff-notification path.

Before RC41, a multi-table UPDATE on payment_attempts JOIN pays could
lock the payment attempt before the payment row, while manual and API
settlement lock pays before payment_attempts. Under concurrent payment
settlement and staff-keyboard synchronization, the opposing lock orders
could form an InnoDB deadlock.

RC41 instead:
- Opens a short SQL-only transaction after the existing Telegram edit.
- Locks pays by its ID with SELECT ... FOR UPDATE.
- Checks current state and nullable approved_by against the previously
  observed financial state, rejecting stale staff acknowledgment.
- Updates only payment_attempts.evidence_staff_digest using its unique
  pay_row_id key, then commits.
- On SQL error, rolls back its own optional digest transaction, records
  pay ID, failure phase, exception type and SQL error code, and leaves the
  digest eligible for the existing ordinary maintenance projection.
- Refuses a nested caller transaction without committing or rolling it back.

There are no retries of original payment settlement, bank/gift verification,
native CREATE, wallet balance, Telegram editing or customer delivery. Normal
payment authorization, evidence, historical rows and native idempotency are
unchanged. The correction requires **no new SQL migration** or runtime
dependency. There is no new operator setting or user-visible action.

## Verification and limitations

Independent review: COMPLETE / APPROVE for runtime candidate
25952f309572beb553f5266d2c307a25df924309, against
main@006654219281c40421d6097f9e7b905d66aed62d.
Dev Server PHP 8.5.12: 9/9 new source-real MariaDB/InnoDB tests, 99/99
existing API-flow scenarios, and the full API-family shard including
native preflight passed. Dev Server's disposable MariaDB was started
with UTC for the historical Evidence fixture; host timezone/configuration
was not changed. These are source and synthetic/disposable validations,
not installed production acceptance. Official GitHub CI/PR integration
evidence belongs to the current PR checks and Release record.

The change addresses the specific inverse-order digest update.
It does not prove no other database operation can ever deadlock.

## Official ZIP transfer (archive-only)

Verify the attached official runtime ZIP, its source-bound manifest and
SHA256SUMS. Do not use GitHub's automatically generated source archive
as the runtime package. Stage the **unchanged ZIP only** in:

- /www/acdomains/host.hellservice.top/vivan/
- /www/acdomains/host.hellservice.top/hell/
- /www/acdomains/host.hellservice.top/chili/

Compare SHA256 at the source and each destination. ZIP presence does not
install, extract or enable RC41. Do not modify private baseInfo.php,
running bot PHP, SQL, provider API, payment records, Telegram webhooks,
Cron, OPcache or other live state as part of the staging operation.

## Recovery

If a later separately authorized runtime installation experiences
payment or notification problems, preserve financial/provider journals,
payment evidence, transaction IDs and relevant logs for diagnosis.
Recover with a separately approved forward fix or compatible version;
blind ZIP extraction or schema rollback is not a safe financial recovery.
Keep installed payment acceptance in Issue #26 open until real authorized
checks establish that behavior.
