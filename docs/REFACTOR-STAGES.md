# AChBot source refactor — historical stages and current continuation

**Status:** V0–V9 Phase 1 source-only extraction/integration is completed and [V9 `v0.9.0-rc.1`](https://github.com/ach1992/bot-refactor/releases/tag/v0.9.0-rc.1) was published. Real installed/operator acceptance and legacy financial hazards remain independently OPEN. The following historical stage descriptions are **frozen scope/evidence**, not current approval for using old panel types. [PR #30](https://github.com/ach1992/bot-refactor/pull/30) was closed without merge; it is not an active gate. The current public [v0.10.0-rc.3](https://github.com/ach1992/bot-refactor/releases/tag/v0.10.0-rc.3) is a Marzban-only **source-only prerelease**; Owner reports its runtime installed with informal successful smoke including agency rejection, while complete finance/operator/restore acceptance remains unverified. RC2 was an earlier config-only security hotfix.

Current acceptance and safe future-recovery details: [Marzban-only notes](post-v9/MARZBAN-ONLY.md).

**Current deployed-product baseline ([#31](https://github.com/ach1992/bot-refactor/issues/31)):** the Owner confirmed no legacy X-UI/Sanaei/Alireza/normal panels **ever existed in operation** and authorized retiring all their functionality, with no legacy-panel migration/backup. Desired Marzban functions, all original 23 MariaDB tables/data and live public bot/payment/Cron paths must be preserved. Owner R4 accepted one additive nullable `server_info.panel_group_id` field for admin-defined shared-panel groups, while the original V9 DDL file remains frozen; the runtime's 23 tables plus one column require separately authorized migration before installation. Keep a narrow future-provider seam without implementing PasarGuard/new X-UI now. Use a **separate post-V9 acceptance contract**; previous golden/history runs only on the pinned old revision. No user/finance/Marzban backup exemption and no automatic release/deploy.

**Next active development increment:** Owner-authorized staged [finance consolidation and NOWPayments finality](post-v9/FINANCIAL-CONSOLIDATION.md) under [Issue #26](https://github.com/ach1992/bot-refactor/issues/26): separate provider verification from a common durable payment/effect claim and Marzban fulfillment; no blanket schema rewrite. No real database migration, merge or rollout is implied by this source-only candidate.

**Roadmap:** [#29](https://github.com/ach1992/bot-refactor/issues/29) (operator acceptance, financial/role defects, Marzban-only source simplification, audited optional DB trimming) and [#26](https://github.com/ach1992/bot-refactor/issues/26) (TRON, gateways, **privileged negative wallet debt** and user non-overdraft). Full database modernization, new panel integrations and comprehensive optimization are **later phases**, not part of the immediate Marzban-only retirement.

## Archived V0–V9 stages (historical planned goals, not live status)


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

**V7 source-only delivery (Issue #21):** eleven mechanically extracted
modules cover the plan catalogue, purchase/agent wizards, provisioning and
unchanged helper facades. The 65-member source-only candidate passed
47-step native original-schema testing, five mutation controls, helper
reflection, HTTP access checks and reversible V6.1/V7 swap. V7 was reviewed,
merged and published as `v0.7.0-rc.1`; its real operator acceptance
remains **NOT TESTED**. Source publication does not waive earlier pending
V5/V6 operator checks or V6.1 full restore.

**V8 reviewed and source-only published (Issue #24):** the 27-module
financial lifecycle extraction was independently approved and merged
in PR #25 with its PHP 8.1/8.4 complete V0-V7 frozen history.
[Public test prerelease v0.8.0-rc.1](https://github.com/ach1992/bot-refactor/releases/tag/v0.8.0-rc.1)
contains exactly 92 runtime members and no private configuration.
Synthetic finance/renewal/payment parity does not prove live financial safety;
Issue #26 tracks the inherited defects and real provider/Cron/installed
operator acceptance remains **NOT TESTED**.

**Current last Phase 1 source-acceptance increment — V9 (Issue #27):**
from integrated V8 commit 9ca6cabe43121a528db8d32c9b4aa1b5d10168ea.
The global acceptance tests full original PHP config bootstrap, 40 legacy
facades across root/foreign CWD, 23-table original synthetic MariaDB,
real loopback HTTP panel responses, physical whole-app source inventory
and V8/V9/V8/V9 source-only rollback. The V9 candidate currently keeps
all 92 runtime files **byte-for-byte equal to V8** and reviews only the
tracked sanitized baseInfo.example.php, never real private baseInfo.php.
Phase 2 schema or money/security fixes are separate owner decisions.
V9 integration, publication and installation require separate gates.
