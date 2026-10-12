# Refactoring increments (proposal — re-order by dependency evidence)

| Increment | Proposed target | Acceptance focus |
|---|---|---|
| V0 | Baseline, characterization tests, deployment/entrypoint inventory | Source and DDL references; capture actual paths/callbacks, critical flows |
| V1 | Twenty presentation/payload helpers in five modules; legacy wrappers | Eager bootstrap order unchanged; immutable-reference differential checks |
| V2 | Telegram transport/input adapters | Old callbacks, polling/webhook contract and response parity |
| V3 | Customer menus, support and stateful flows | Message/callback text and per-user state parity |
| V4 | Admin and agent flows | Permissions, operations, messages, account segregation |
| V5 | Broadcasts, Cron and background jobs | Offsets, retries, checkpointing, schedules, old file paths |
| V6 | VPN panels, account links and subscriptions | External requests and generated link parity |
| V7 | Product plans and service provisioning | Prices and account side effects |
| V8 | Payments, wallet, discounts, renewal | Exactly-once/idempotency, amounts, same database writes |
| V9 | Integration cleanup; `baseInfo.php` review LAST | Global equivalence, production-like full regression, swap/rollback readiness |

Do not blindly execute stages in a fixed order. Inspect actual dependencies and choose safer boundaries; every stage is one deployable/reversible candidate, reviewed then approved by the operator before continuing.
