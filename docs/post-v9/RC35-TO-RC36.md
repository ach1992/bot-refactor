# RC35 → RC36 — subscription main-menu labels and administrator layout

This guide records the **Owner-approved, source-only** main-menu change
planned for the next AChBot prerelease after RC35. See the published
[GitHub Releases](https://github.com/ach1992/bot-refactor/releases) for the
actual release/artifact identity; a candidate commit, PR or this guide is
**not** evidence that RC36 has been published or installed.

## Exact approved Telegram presentation

Only the shared **main menu** `getMainKeys()` changes its layout.

- For any current administrator (Owner, Manager, Operator or Support), hide
  **📚 آموزش و قوانین** (`reciveApplications`) from this main menu, while
  retaining the underlying customer route and all other existing menus.
- Put **➕ افزودن اشتراک** (`showUUIDLeft`) and **🔍 جستجوی اشتراک**
  (`searchUsersConfig`) **in one row**, in that order. Their distinct
  existing callbacks, permissions, the original import and staff search
  journeys, and any independent search-state eligibility are unchanged.
- Keep **⚙️ مدیریت ربات** on its own row. Preserve the existing account,
  subscription, trial, agent, cooperation, custom `MAIN_BUTTONS`, capacity
  and configured help-channel behavior and their relative order.
- For **ordinary users**, keep **📚 آموزش و قوانین** and its download route
  (along with their existing referral/cooperation behavior). Their optional
  `showUUIDLeft` button has the **same** new label **➕ افزودن اشتراک**;
  it remains hidden if the existing `searchState` gate is OFF. No new
  administrator search button is granted to ordinary users.
- Unify **🔍 جستجوی اشتراک** in the existing agent list/search and
  administrator order-search/return keyboards that use these same actions.
  Do not rename a distinct 'add to user's subscriptions' action just because
  its label mentions subscriptions.

The change updates the two existing Persian audience text catalogs rather
than hardcoding labels in the runtime. The exact 5-key catalog delta is
protected by `tools/text_catalog_main_menu_20261113.json` and
`tools/catalog_owner_r5.py`. It is checked and projected on **copies** for
the earlier RC35/#162 and historical catalog guards; prior frozen manifests,
source fixtures, and Golden Telegram callback identities remain unchanged.

This is **not** a text-based command migration: existing `callback_data`,
including `showUUIDLeft`, `searchUsersConfig`, `searchAgentConfig` and
`reciveApplications`, is preserved. Older already-sent Telegram inline
keyboards may continue to show their original labels until a fresh menu is
sent. No automatic edit/replay of existing messages is intended.

## Verification and rollout boundary

The source-local real `getMainKeys()` regression covers ordinary users,
agent users, Owner, secondary staff, free-trial eligibility, configured
help channel, custom main buttons, and paired search actions. Exact-candidate
PHP 8.5 CI and an author-independent review are required **before** integration
and source publication. The historical source/catalog checks must stay
byte-pinned rather than weakening older snapshots.

Compared with RC35, this change requires **no** SQL migration, new provider
API, additional runtime dependency, financial/Native operation, password or
private configuration update, Cron modification or webhook change. It changes
only Telegram menu presentation and synchronized static labels. Publication
is strictly a **source-only** artifact; it does not authorize deploying onto
the existing Chili, Vivan or Hell installations.

For any *separately authorized* installation, verify the selected exact
runtime ZIP, manifest and checksums; take a restorable bot/config backup;
leave the actual tenant DB and financial/Native journals intact; install
only in the authorized directory and activate the new PHP source without
replaying SQL; inspect a **new** administrator main menu and an ordinary
user's menu. Preserve existing `reciveApplications` functionality for
customers and actual staff-only permission checks behind the renamed search.
Rollback only the source bytes when no new independently persisted operations
make a blind restore unsafe. Do not change Webhook, Cron, payment-return
routes or migration state to publish this release.
