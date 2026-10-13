# Refactoring increments (proposal — re-order by dependency evidence)

| Increment | Proposed target | Acceptance focus |
|---|---|---|
| V0 | Baseline, characterization tests, deployment/entrypoint inventory | Source and DDL references; capture actual paths/callbacks, critical flows |
| V1 | Twenty presentation/payload helpers in five modules; legacy wrappers | Eager bootstrap order unchanged; immutable-reference differential checks |
| V2 | Sixteen Telegram transport/message facades and scoped update bindings | Immutable V1/V0 comparison, loopback wire tests, unchanged callbacks/scope and file-swap rehearsal |
| V3 | Complete customer/admin support-ticket lifecycle in six scoped modules | Original route/scope/SQL/message contracts; real synthetic wizard continuity and rollback |
| V4 | Administrator roles/ban and agent lifecycle | 24 route branches, SQL/state/role/callback parity plus rollback |
| V5 | Broadcasts, Cron and background jobs | Offsets, retries, checkpointing, schedules, old file paths |
| V6 | VPN panels, account links and subscriptions | External requests and generated link parity |
| V7 | Product plans and service provisioning | Prices and account side effects |
| V8 | Payments, wallet, discounts, renewal | Exactly-once/idempotency, amounts, same database writes |
| V9 | Integration cleanup; `baseInfo.php` review LAST | Global equivalence, production-like full regression, swap/rollback readiness |

Do not blindly execute stages in a fixed order. Inspect actual dependencies and choose safer boundaries; every stage is one deployable/reversible candidate, reviewed then approved by the operator before continuing.

V3 selects the complete support capability as the next coherent boundary. Broader customer navigation and non-support wizards from the original proposal remain future work; they are not declared refactored by V3. The six support handlers still use legacy persistence and caller scope. Later domain/storage separation needs its own tested contract, not a blanket claim of architectural completion.


V4 is specifically administrator access (roles/ban) and agent
lifecycle (applications, approvals/discounts/rejections). Other
admin settings and customer navigation remain future work.
Scoped extraction is a compatibility seam, not a completed
domain/authorization/storage redesign.

V5 is the single cohesive broadcast-and-worker package controlled by Issue #14.
It extracts the complete existing broadcast administration lifecycle, both
`send_list` and `bc_jobs` processors, and the warning/notification segment
of `warnusers.php`. It preserves all three legacy entrypoint filenames, 429
checkpoint/offset behavior, current callback bytes, and the original MariaDB
schema; later automatic renewal, wallets, financial settlement and VPN account
provisioning remain byte-unchanged and outside the V5 extraction. See
`docs/v5/README.md`, `docs/v5/CRON-INVENTORY.md`,
`docs/v5/LEGACY-BEHAVIOR.md` and `docs/v5/TESTING.md`.
V5 and V6 were independently reviewed, merged and published as test-only
pre-releases. The corresponding **full operator acceptance** still remains unverified
in Issues #14 and #16; source integration is not production certification.

**V6.1 incident maintenance:** independent backup compatibility/security fix
(Issue #18, `docs/backup-hotfix/`) integrated and released as `v0.6.1-rc.2`.
The first fix addressed aaPanel Cron's restricted dump executable PATH;
the RC2 fix accepts MariaDB's legitimate space-padded single-digit dump
completion hour. The owner installed RC2 on the affected bot and reported
a real backup ZIP arriving in its Telegram channel. **Isolated database
restoration was not tested, by explicit owner decision.** Do not claim complete
backup recovery or full V5/V6 operator acceptance from the single upload.
An unrelated older working bot must remain untouched.

**Next approved isolated-development increment:** V7 product-plan/purchase
and provisioning lifecycle, controlled by [Issue #21](https://github.com/ach1992/bot-refactor/issues/21).
The owner authorized beginning V7 source development without repeating the
declined restore experiment or treating V5/V6 pending gates as PASS. That is
a **scoped development waiver only**, not permission to merge, release, deploy,
change real payment/financial policy, touch the database or operate on live bots.
Retain the full V6.1 native MariaDB/footer regression and historical suites
when building V7; financial/wallet/settlement rework remains in V8.
