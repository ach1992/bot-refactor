# RC36 → RC37 — main-menu support order and administrator account actions

RC37 is the **source-only** prerelease associated with the independently
approved [PR #167](https://github.com/ach1992/bot-refactor/pull/167).
The implementation was merged as
`927b4f0fef41f4989f96acb019084df0f9ef8bc8` with the **exact reviewed
Git tree** `0baa199a2e8ed966ebf121f97c9deb49905dfa8f`; candidate
`487bd14886f97fd0c234593d1f1c62637e062f43` passed
[PHP 8.5 CI #43364746383](https://github.com/ach1992/bot-refactor/actions/runs/43364746383):
Foundation, eight Native families, source packaging and strict aggregate.

The final release commit/tag and immutable checksums are owned by the
[RC37 GitHub Release](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.37).
Only that published release and its manifest prove artifact identity;
a PR, merge or guide does not constitute an installed version.

## Exact presentation behavior

- **Ordinary users and agent users:** When `searchState=on`, put the
  existing **➕ افزودن اشتراک** (`showUUIDLeft`) directly **above**
  the configured `cooperationUrl` button (often named **💬 پشتیبانی**).
  When `searchState=off`, the import button remains hidden. A configured
  support/cooperation URL, independent `helpChannel`, referrals, customer
  education, account/trial rows and custom `MAIN_BUTTONS` continue using
  their original eligibility and callbacks. No new user permissions.
- **Administrator's individual subscription details:** Where both actions
  are already available, render **🌐 تغییر سرویس**
  (`switchLocation{id}_{server_id}_0_0`) and **🔄 تغییر پلن**
  (`changePlan_{id}`) in the **same inline keyboard row**, in that order.
  The transfer remains visible to authorized staff when Native observation
  is uncertain, without exposing an ineligible change-plan action. Leave all
  order metadata, renew-consent, delete/toggle and return buttons intact.
- **No other presentation or function change:** callback strings, staff
  authorization, feature switches, runtime message text, catalog labels,
  native/provider mutation controllers, finance, webhook and Cron behavior
  remain exactly as in RC36.

This implements two already authorized UI decisions, without adding an
extra menu, hardcoding an instance URL or changing historical text catalogs.
Old Telegram inline keyboards already sent to users may still display old
order until the client receives a fresh menu.

## Verification and distribution

The **actual** `getMainKeys()` fixture now verifies 18 menu scenarios,
including customer/agent enabled and disabled import, cooperation/support
URL, independently configured help channel and custom buttons. The existing
disposable PHP/MariaDB customer-services suite checks both Marzban and
PasarGuard administrator views for paired actions, and the transfer-only
fallback when provider observations are uncertain. The suite's historical
expected total of 596 cases is retained. The approved candidate passed
full exact-head PHP 8.5 validation. A later **README and guide-only** release
sync may use the Owner's explicit no-re-review authorization when no
runtime or test files change after the reviewed candidate; protected
documentation CI must still pass.

**No database or SQL upgrade is introduced by RC37.** No new package
dependencies, `baseInfo.php` settings, webhook, Cron schedule or tenant
storage change. Existing migrations must **not** be replayed. RC36 operators
should keep all private state and apply RC37 only under a separate, explicit,
verified bot-specific installation authorization.

The Owner authorized **placing the published ZIP archive only** in the
three existing NL aaPanel folders `vivan/`, `chili/` and `hell/`.
That is **not an installation**: do not unzip the file, replace running PHP,
reload services, alter live configs/webhooks/Crons, or migrate Hell merely
because an archive is present. Check `SHA256SUMS`, ZIP manifest, exact
member hashes and target-side file checksum. The separate Hell migration
[Issue #161](https://github.com/ach1992/bot-refactor/issues/161) remains
approval-gated and the historical Vivan financial
[Issue #157](https://github.com/ach1992/bot-refactor/issues/157)
remains independently owned.

For an explicitly approved future live installation, first reconcile the
bot's installed runtime files, private config, database migration state,
pending financial/native operations, scheduled tasks and external callbacks,
then take a restorable backup and validate after cutover. Never infer this
acceptance from a staged ZIP or from exact-candidate source CI.
