# V2: Telegram boundary without changing the bot contract

V2 builds on integrated V1 `a7ac4a86a84ccf781ef44130833dd76e923fdc47` (tree `5adf954667c08fb11936f337c3010a5bcc697c0d`). It isolates the Telegram communications boundary and incoming update bindings. It does not redesign customer/admin interactions, fix legacy business rules, migrate storage, or authorize a production deployment.

## Runtime ownership

| File | Responsibility | Compatibility boundary |
|---|---|---|
| `src/Telegram/Transport.php` | Existing ordinary and file HTTP transports | Explicit token and runtime directory; unchanged cURL options, serialization, error/429 handling and return values |
| `src/Telegram/Messages.php` | Fourteen send/edit/delete/forward/photo/document/pin/unpin/alert/file-link operations | Injected request/read callables and explicit caller context; same payload, fallbacks and null/default behavior |
| `src/Telegram/UpdateBindings.php` | Original incoming-message/callback assignments | A **scoped include**, not a passive function library; same variable scope, ordering, references, absent fields and partial assignments on exceptions |
| `config.php` | Existing public facade and bootstrap | Sixteen unchanged public signatures delegate lazily; input read/time/SQL/authorization ordering remains fixed |

All five V1 modules remain unchanged. `buy_helpers.php`, `bot.php`, payment/health/Cron entrypoints, settings, third-party libraries, example configuration and the 23-table DDL are unchanged from V1. Real `baseInfo.php` remains outside Git. Only `config.php` changes among existing runtime files.

No Composer dependency, framework, service locator, global transport setter, new runtime configuration format or database abstraction was introduced. The explicit callable boundary lets later use cases choose a transport without depending on global tokens. Future persistence refactoring remains a separate Phase 2 decision; this boundary does not invent a replacement schema.

## Why incoming bindings are intentionally scoped

An initially considered `extract(fields($update))` implementation delays **all** caller assignments until projection succeeds. The original source assigns incrementally. With malformed `message.from.first_name` (an array), PHP throws after assigning the user/chat/message fields; the map-returning version would lose those partial assignments. It also obscures caller reference aliases.

V2 therefore moves the assignment block byte-for-byte into a scoped include at its original position. No user-supplied key is extracted into the symbol table. The 135-case differential suite observes success, warnings, exception identity, retained variables and partial caller scope, including reference aliases. A future request-context redesign must explicitly preserve or separately approve these semantics rather than treating the old block as a pure parser.

## Intentionally retained behavior

- Ordinary transport returns decoded objects/scalars or `null`; file transport decodes arrays and uses `false` for its existing failure cases.
- The existing form/multipart distinction and file-specific markup encoding are preserved.
- Ordinary/file timeouts remain 10/5 and 20/8 seconds (total/connect), not newly selected values. Existing DNS/keepalive/IPv4/HTTP options remain unchanged.
- No new retry or sleeping policy is introduced; `429` behavior remains the original behavior.
- Parse-entity fallback and unpin fallback retain their current order and request count.
- Existing `answerCallbackQuery()` constructs a GET from the configured `botUrl`; it is not silently changed to the `alert()` Bot API path.
- Existing log location/rotation/message content remain preserved, including their known privacy limitations. This is not log-redaction or TLS remediation.
- Legacy callback bytes and deployed paths remain contracts; naming Issue #2 is not closed by this extraction.

Read [TESTING.md](TESTING.md) for evidence limits and [OPERATIONS.md](OPERATIONS.md) for file replacement/rollback. [Issue #6](https://github.com/ach1992/bot-refactor/issues/6) owns V2 acceptance; [Issue #4](https://github.com/ach1992/bot-refactor/issues/4) retains deployment-specific V1 operator evidence. The owner's subsequent instruction explicitly authorized delegated checks and starting V2; it did not assert that manual tests or a production rollout occurred.
