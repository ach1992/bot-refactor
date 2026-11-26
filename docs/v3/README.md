# Support and ticket lifecycle

Current support routes are included in the authenticated `bot.php` request
context, not independent HTTP/CLI entrypoints. Preserve the actual current
role checks, private message/source ownership, ordered handler behavior,
ticket identity and notification semantics when changing them.

| Module | Responsibility |
| --- | --- |
| `src/Support/Entry.php` | Customer support menu and categories |
| `src/Support/CreateTicket.php` | Category/title/body and ticket creation |
| `src/Support/CustomerConversation.php` | Customer list/reply/close/rating |
| `src/Support/Categories.php` | Administrator categories |
| `src/Support/QueueAndHistory.php` | Staff queue, history and attachments |
| `src/Support/AdminReply.php` | Staff response |

Tickets use `chats`, messages use `chats_info`, categories use
`TICKETS_CATEGORY` settings and current wizard state uses user records.
Support handlers share the existing router context; do not assume they
are pure functions or blindly change include/reentry semantics. Preserve
real conversation content and callback/permission behavior; verify
current support and Telegram rendering when touching it.

The original extraction and V3 evidence are historical
([Issue #8](https://github.com/ach1992/bot-refactor/issues/8), [prior source](https://github.com/ach1992/bot-refactor/blob/c43d671a1f50c582930d15db0c094bdf20ff4f19/docs/v3/README.md)).
Current checks follow [CI validation](../post-v9/CI-VALIDATION.md).
