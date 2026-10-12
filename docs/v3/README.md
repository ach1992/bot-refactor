# V3: a complete support/ticket lifecycle boundary

V3 builds on integrated, independently reviewed V2 `893032ef217fe89fa1d46b06a5c3368b8f4fb63e`, tree `92a42beae3be056af0968bdfb3e0462b712fa5de`. The owner authorized V2 source integration and starting this increment. That progression is not a claim that deployment-specific/manual tests occurred. [Issue #8](https://github.com/ach1992/bot-refactor/issues/8) owns V3 implementation/review/acceptance; earlier operator evidence remains in #4 and #6.

## Delivered boundary

The complete existing support capability is separated from `bot.php`: customer entry, category selection, persisted title/body wizard, text/photo creation, listing, replying, closing and rating; administrator categories, queues, pagination, replies and closure; shared history and attachment retrieval. This is 22 top-level route branches in six cohesive files, moving 712 original source lines. It is not a rewrite of unrelated menus, purchases, payments or every customer wizard.

| Module | Original `bot.php` lines at the pinned V2 commit | Branches | Responsibility |
|---|---|---:|---|
| `src/Support/Entry.php` | 6247-6287 | 2 | Support menu and ticket categories |
| `src/Support/CreateTicket.php` | 6679-6737 | 3 | Category/title/body steps and text/photo creation |
| `src/Support/CustomerConversation.php` | 6738-6974 | 5 | Customer lists, closure, reply selection/send and rating |
| `src/Support/Categories.php` | 6975-7082 | 5 | Administrator ticket menu and category management |
| `src/Support/QueueAndHistory.php` | 7083-7290 | 5 | Administrator queues/pagination/closure, history and images |
| `src/Support/AdminReply.php` | 7403-7461 | 2 | Administrator reply selection and text/photo response |

Only `bot.php` changes among existing runtime/reference files. Each original region is replaced at its old position by `require __DIR__ . '/src/Support/...';`. The original body bytes, all eight earlier `src` modules, `config.php`, `buy_helpers.php`, payments, Cron, settings, bundled libraries, example configuration and reference DDL remain unchanged. There are 26 complete unchanged runtime/reference files and 45 unchanged prior evidence files. The complete runtime package contains 32 files.

## Why these handlers deliberately retain caller scope

The current router is an ordered, effectful program, not a first-match dispatcher. Support branches can change `$text`, `$from_id`, `$admin`, `$match`, statement/results and other variables later branches read. Some paths fall through; an empty customer ticket list exits the process. A callback can coexist with a persisted wizard step. Moving the code into independent function scopes or returning a single result would change those semantics, including partial state after errors.

These six files are therefore **scoped compatibility handlers**, not pure libraries or independently callable services. They do not create a new global registry, load an ORM/framework, reset a conversation, add a transaction, cache settings or normalize data. Use `require`, not `require_once`: an existing jump/reentry must execute the same route code again. There are no moved `goto`, `return`, function declarations or physical path references in the original regions; the guard checks these token categories.

Direct HTTP or CLI execution of an individual new handler returns 404 and exits with no response body. Its `SCRIPT_FILENAME` guard does not create new caller variables or change the ordinary inclusion path. This closes the newly introduced direct-entry surface only; it is not a repair of historical router authorization.

## Persistence and future extension

The existing SQL, binds and ordering are retained. Tickets live in `chats`, messages in `chats_info`, categories in `setting` under `TICKETS_CATEGORY`, and wizard state in `users.step` / `users.temp`. State updates still use the existing `setUser`; it changes storage without refreshing the current request's `userInfo`. Message text escaping, JSON image representation, state/rate integers and historical regex/pagination quirks remain contracts.

This step gives the support feature a bounded owner and executable old/new lifecycle evidence. **It does not yet separate business logic from persistence.** The next deeper support change can introduce narrow load/save conversation operations and explicit request context once their shared-variable/notification-order contracts are covered. Do not add an interface per SQL statement or redesign the schema now. Any authorization, rating, pagination or notification-timing correction needs separately accepted behavior tests; do not hide it in extraction.

## Reading and validation

- [Testing and evidence limits](TESTING.md)
- [Staging, source replacement and rollback](OPERATIONS.md)
- [Preserved legacy behavior and known risks](LEGACY-BEHAVIOR.md)

`tools/v3_contract.py` reconstructs the allowed source transformation from immutable V2. This protects scope; it is not a substitute for independent review. Normal tests compare immutable old execution against new execution without recording replacement goldens. No performance improvement, whole-bot completion, production rollout or manual acceptance is claimed.
