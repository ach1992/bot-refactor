# V4: Admin access and agency lifecycle over the original database

V4 follows the independently reviewed and merged V3 baseline
283165f19777ad6ab62cfcb03de329c5bb0f8b74 (tree
b172f48120a6febf3ee654de2b50acb45ace6a1c).

This is Phase 1 source-only refactoring, NOT a production release,
database migration, security-policy redesign or claim of performance gains.

## Moved ownership

| Scoped handler | V3 bot.php lines | Responsibility |
|---|---|---|
| `src/Admin/Roles.php` | 495-526 | List, grant and revoke administrators |
| `src/Agent/Discounts.php` | 608-801 | Agent listing, details, plan/server/default discounts |
| `src/Agent/Applications.php` | 5773-5827 | Agency request, approval, decline wizard |
| `src/Admin/Bans.php` | 6643-6680 | User ban/unban requests and ban state |
| `src/Admin/Unban.php` | 6727-6754 | Unban completion and messages |
| `src/Agent/Rejected.php` | 7020-7038 | Rejected agents and release |

24 top-level routes / 366 original lines are moved byte-for-byte.
Each handler is directly required at its former route position:
`require`, never `require_once`. This preserves reentry,
caller variable scope, partial state, alias mutations, route order,
fall-through and exits. Previous eight plus six V3 modules remain untouched.

Only the earlier `bot.php` changes among preexisting runtime
members. The other 31 previous runtime files and 62 frozen historical
evidence files remain byte-identical. SQL strings, binding order, JSON
storage, old callback bytes, Cron paths, webhook/payment/renewal behavior,
MariaDB 23-table DDL and local `baseInfo.php` remain unchanged.
No framework, Composer dependency or config format is introduced.

These are transitional scoped compatibility handlers, **not yet**
fully independent domain models, authorization services or storage APIs.
Direct access to a new handler returns a bodyless HTTP 404.

## Guides

- [Testing and evidence](TESTING.md)
- [Staging and rollback](OPERATIONS.md)
- [Legacy behavior intentionally retained](LEGACY-BEHAVIOR.md)
- [Issue #10](https://github.com/ach1992/bot-refactor/issues/10)
- [Phase 1 contract](../PROJECT-SPEC.md)

V4 needs fresh author-separated independent review before merge, and
separate owner staging/production decisions. Issues #4/#6/#8 retain
deployment-specific evidence, and Issue #2 tracks legacy naming.
