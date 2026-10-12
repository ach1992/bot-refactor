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
