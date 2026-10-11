# AchBot — project contract (Phase 1)

## Outcome

Incrementally refactor the existing PHP Telegram VPN bot so the final operator can replace the bot application source on its existing deployment without changing its existing MariaDB database, business rules or user-visible/admin-visible behavior. Improving readability, maintainability, testability and future extension is the purpose of the change.

## Non-negotiable compatibility

1. **Database:** No DDL, migration, table/index/column changes, writes for repair/migration, or data re-encoding as part of Phase 1. Ordinary application data writes must continue with equivalent semantics.
2. **Observed behavior:** Maintain commands, menus, labels, callback handlers (including in-flight legacy callback strings), state transitions, price/calculation rules, purchase, extension, renewal, discounts, wallet accounting, bot/admin/agent permissions and notification behavior.
3. **Integrations:** Maintain Telegram webhook entrypoint, return URLs, active subscription endpoints, remote VPN panel adapters, gateway/payment return behavior, Cron file paths and timing assumptions until replacements are proven compatible.
4. **Operator experience:** Every refactor increment must be complete and independently deployable with a documented file swap, readiness checks, human-tested acceptance and rollback.
5. **`baseInfo.php`:** Keep actual local file and values untouched. Do not attempt a breaking config migration. Consider any structural change only in the last increment.
6. **Bugs:** Changes that might alter external behavior are separately scoped, evidence-backed, testable and explicitly accepted; when uncertain, preserve old behavior behind a seam for later repair.
7. **Phase 2:** Future schema migration is outside Phase 1. Add application boundaries allowing future storage refactoring; do not over-engineer abstractions.

## Acceptance gates per increment

- Recorded initial baseline, integration/entrypoint inventory and explicit scenario-by-scenario behavior checks.
- Targeted automated characterization/regression tests **and** preserved system-wide smoke tests; manual operator test over representative non-production state.
- SQL/DDL fingerprint unchanged and verified against reference; no migration runner/change to production DB.
- Every old entrypoint, callback, URL and Cron path required for compatibility continues to work, or an equivalent verified forwarding compatibility adapter exists.
- Exact-candidate independent review; compare code behavior plus database side effects and external requests, not only PHP syntax.
- Operator approves the validated increment before it becomes the base for the next; rollback remains viable.

## Security and publication constraints

Never place original SQL with customer/financial/integration data into a public repository. This public bootstrap intentionally omits actual rows, live runtime config, backups and logs. Search for additional embedded secrets before every push. Rotate any real credentials that were included in shared source/dump. Do not add deployment secrets to CI logs.

## Future naming

New code and docs use **AchBot** / `achbot`. Legacy `wizwiz` callback payloads and script paths are compatibility contracts, not just branding: replace them only with backward-compatible decoding/aliases, and do not break previously sent Telegram inline keyboards. Internal names can be simplified where not observable externally.
