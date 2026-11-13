# RC39 → RC40 — first-start registration and mandatory channel join

RC40 is the **source-only prerelease** of independently reviewed
[PR #177](https://github.com/ach1992/bot-refactor/pull/177) and
[Issue #176](https://github.com/ach1992/bot-refactor/issues/176).
RC39 remains the last prior published release. The Owner explicitly
requested publishing the RC40 prerelease and staging its ZIP before running
the repository-required GitHub PHP 8.5 validation. The protected-main
merge is a separate step and **must not bypass** `php85-validation`.

## Source changes

- A verified private-chat `/start` registers an actor whose `users` row
  is missing; when mandatory joining is disabled, the existing independent
  phone/authorization gates and main menu continue normally.
- With a mandatory channel, `getChatMember` must verify membership. A
  nonmember sees the channel prompt, selects «عضو شدم» after joining,
  and only then proceeds to registration and the normal menu. Unverified
  membership cannot bypass the join gate. Valid owner-configured exemptions
  still apply.
- Existing user identities, wallets, bans, permissions, subscriptions and
  payments are not overwritten by ordinary start. Inviter rewards follow
  existing current-row semantics with one successful concurrent registration.
  Negative inviter debt accepts a positive credit where the resulting signed
  INT remains in range. A deliberately deleted test user is simply a new
  record for the next valid start; there is no permanent history-based
  referral block.
- The initial welcome/admin notice is claimed only in its real send branch.
  Home-only navigation (`mainMenu`, `evidenceHome`, `ph_*`) does not consume
  this first-start notice. Redundant Persian support-contact headings were
  removed without removing the actual configured support destination.

No new SQL migration or runtime dependency, provider, payment, native
panel operation or private setting is introduced by this release.

## Validation and publication order

The corrected source was independently reviewed at
`9f57db153735f8243e75b821e7cf93bcfb108445` with `APPROVE`.
PHP 8.5.12 Dev Server evidence reports 51 onboarding/referral cases,
289 destination/transport checks, 100 instance/content checks, 19 payment
UX cases and 18 menu scenarios passing. These are synthetic/disposable
tests, not proof of real installed Telegram behavior.

This source-only prerelease was **explicitly requested before** the
exact-HEAD GitHub PHP 8.5 check. The subsequent CI run and any merge are
tracked in PR #177. Do not infer CI success, main integration, or installed
acceptance from the release tag or published ZIP. A failed CI check requires
diagnosis and a subsequently reviewed/validated fix, not a bypass of
branch protection.

**Recorded later:** [GitHub PHP 8.5 Run #43523520445](https://github.com/ach1992/bot-refactor/actions/runs/43523520445) passed
all eight native families and the protected aggregate on attempt 2 for
the published source commit 6fcddf4579ba4ac6a76f44c6891758f6a87879b3.
The first attempt had a MariaDB Deadlock 1213 in the unchanged finance
API flow under concurrent synthetic calls; the bounded retry passed.
Preserve that initial failure as diagnostic evidence, not a repaired
financial bug. Integration still follows the actual protected PR status.

## ZIP artifact and three archive-only staging destinations

Use the **attached release runtime ZIP**, matching
`achbot-v0.10.0-rc.40-runtime.zip.manifest.json` and `SHA256SUMS`.
GitHub's automatic source ZIP is not an installable runtime archive.
The package contains 132 runtime members, without `baseInfo.php`,
secrets, customer rows or schema migrations.

The Owner requested placing the unchanged release ZIP only in:

- `/www/acdomains/host.hellservice.top/vivan/`
- `/www/acdomains/host.hellservice.top/hell/`
- `/www/acdomains/host.hellservice.top/chili/`

Archive presence is **not** installation. Never extract into the live
bot paths, replace PHP, modify database state, change webhook/Cron,
activate payment/provider APIs or alter customer records as part of ZIP
staging.

For a future independently authorized activation, preserve each
installation's private `baseInfo.php`, financial/native journals and
rollback data, then verify actual installed code, PHP-FPM, `health.php`,
Telegram membership/first-start behavior and service-specific effects.
A source rollback must be evaluated against then-current financial
and native state; blindly extracting RC39 into a live bot is not a safe
rollback plan. Keep existing Vivan finance [#157](https://github.com/ach1992/bot-refactor/issues/157)
and completed Hell migration [#161](https://github.com/ach1992/bot-refactor/issues/161)
separate from this archive-only release.
