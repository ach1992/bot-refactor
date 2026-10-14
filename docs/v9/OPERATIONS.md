# V9 source-only integration and operator boundaries

The candidate's application source is **byte-for-byte identical to
V8 RC1**; the new deliverable strengthens whole-application evidence,
packaging and documented compatibility. No change to existing deployed
bot runtime is necessary to obtain V9's source-level behavior.

## Source-only review / operator staging prerequisites

- Verify the exact Git commit and full 92-member SHA256/Git-blob
  manifest of the release ZIP, not GitHub's generic repository archive.
- Choose a new **disposable, isolated** staging directory with fake
  non-production `baseInfo.php`; never overwrite a real installation,
  actual private configuration, SQL rows or backups under the current
  development authorization.
- All relevant PHP/webhook/payment/health/Cron filenames must be present
  and keep their original names, include semantics and URL paths.
  Existing Cron registration, user callbacks and real financial data
  remain out of scope; source-only package does not register a webhook
  or configure aaPanel/PHP/DB.
- The original MariaDB schema must remain 23 tables. No migration,
  database reset, live-credential import or financial policy update is
  part of the release.
- On a source rollback, compare explicit version manifests and restore
  only approved tracked runtime members; do not delete unknown files,
  local config/state, or roll back the database. V8/V9 both have the
  same 92 member names. If returning to V7, the 27 documented V8-added
  modules must be removed from an explicitly owned target as in V8's
  independently reviewed source-swap test.

**Do not run any real payment-provider callback, blockchain monitor,
wallet transfer, VPN panel command or `settings/rewardReport.php` as a
simple test.** The latter can auto-settle purchases in addition to
handling backups. Keep actual server PHP, Cron, MariaDB, webhook
and Telegram configuration unchanged absent separately authorized
operator action.

## Separate gates / unresolved risk

- **Source-only acceptance:** exact-HEAD PHP8.1/8.4 CI, non-vacuous
  original-schema E2E, frozen historical tests, packaged manifest,
  author-separated review.
- **Merge:** new explicit owner decision after the above.
- **Public pre-release:** new explicit owner decision after merge and
  post-merge identity/CI verification.
- **Installed real operator acceptance:** separately owner-approved
  staging/live plan, rollback path, actual representative user/Admin
  flows and redacted evidence. Still NOT TESTED.
- **Financial correctness:** [Issue #26](https://github.com/ach1992/bot-refactor/issues/26)
  has independently scoped inherited TRON/NextPay/debit/idempotency
  concerns; do not interpret V9 unchanged-source approval as resolving
  them. V5/V6/V7 operator gates and actual V6.1 restore remain open.

See [Issue #27](https://github.com/ach1992/bot-refactor/issues/27)
for the V9 development and final owner-gate state.
