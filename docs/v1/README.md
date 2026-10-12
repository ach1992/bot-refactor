# V1: presentation helpers behind stable legacy entrypoints

This is the first runtime extraction after accepted V0. It is not a whole-bot rewrite, a behavior change, or production acceptance. Live acceptance is tracked in Issue #4 and its PR; independent review and operator testing remain mandatory before this increment is accepted.

## Delivered boundary

Twenty deterministic presentation/payload functions move into five small namespaces under `src/`. The legacy functions remain at their original declaration sites in `config.php` and `buy_helpers.php`, with exactly the original public signatures, parameter names, defaults and type declarations. Their bodies lazily `require_once` a module using `__DIR__` and return its result. No Composer loader, framework, PHP extension, global startup loader, new database driver or configuration migration is introduced.

| Module | Internal responsibilities | Legacy compatibility names |
|---|---|---|
| `src/Telegram/Response.php` | Retry hint, success flag, message ID, forwarded-message detection, photo ID | `tgRetryAfter`, `apiOk`, `getApiMessageId`, `isForwardedMessageObj`, `extractPhotoFileId` |
| `src/Telegram/Markup.php` | Existing JSON keyboard encoding and receipt/cancel presentation | `normalizeReplyMarkup`, `inlineKeyboard`, `kbSendReceiptAndCancel`, `kbOnlyCancel` |
| `src/Presentation/Text.php` | Recursive placeholder substitution and support URL normalization | `replaceAllPlaceholdersInMainValues`, `getSupportUrl` |
| `src/Presentation/Labels.php` | Audience/status/payment labels and card-payment message-key selection | `groupFa`, `stateFa`, `payTypeToDescription`, `getCartToCartMessageKey` |
| `src/Subscription/Presentation.php` | Existing link markup, backup links and traffic formatting | `buildConfigLinks`, `buildSubLink`, `buildSubBackupSection`, `sumerize`, `sumerize2` |

The receipt-keyboard helper previously read `lastCartToCartMessageType` from `$GLOBALS`. The public wrapper still reads that same value with the same fallback; the internal function takes it as an explicit argument. Recursive placeholders and the local keyboard helpers call their internal siblings. The new modules can be loaded and tested without opening a database, parsing a Telegram update or running the effectful legacy bootstrap.

## Invariants and deliberate non-changes

- All application bytes outside the 20 selected declarations remain identical to the imported baseline. Seventeen complete runtime/reference files remain byte-identical, including the DDL and example configuration.
- The original `baseInfo.php` remains local-only and untouched. Neither a test nor a release package copies a real configuration back.
- SQL, payment policy, wallet transactions, prices, routing, subscription endpoint logic, remote clients, authorization, Cron paths, old callback strings and user-facing text are not changed.
- The greater-than-one-GiB formatting threshold, loose state comparisons, unescaped legacy link markup, JSON encoding flags, malformed-input behavior and legacy payment labels are preserved, not modernized incidentally.
- Modules use ordinary weak-typing PHP, as did the original files. No new scalar type restrictions are added. Public named arguments still refer to the old parameter names.
- This is code organization and testability work. No measured speed or memory improvement is claimed; lazy wrappers add small call/include-check overhead that is not benchmarked here.
- The V0 risk register and naming Issue #2 remain applicable. A new namespace does not fix pre-existing product or publication risks.

## Why this boundary

V0 established that `config.php` performs effects while loading. Reordering its includes would be a different and riskier change. Keeping wrappers in place preserves function availability and bootstrap sequencing, while independent modules make later use-case/persistence separation easier. The future database refactor is not implemented now; these modules have no database dependency to migrate.

PHP's namespace rules allow unqualified built-in functions/constants to resolve globally, while sibling functions resolve within the module. No classes requiring global qualification are introduced. Primary reference: https://www.php.net/manual/en/language.namespaces.fallback.php . Runtime tests remain the evidence for this particular extraction.

Read [TESTING.md](TESTING.md) for the immutable-reference strategy and limitations, and [OPERATIONS.md](OPERATIONS.md) before any staging file swap.
