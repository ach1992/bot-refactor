# AChBot product contract

AChBot is a Persian-language Telegram bot for VPN subscriptions, Native
account management, wallets, payments, staff operations, scheduled maintenance
and support. The current Wallet-first runtime uses the consolidated empty
schema and the PasarGuard adapter. Adding a future provider or method must
reuse the shared engines; no obsolete runtime or legacy workflow replay is
a compatibility target. Earlier releases have their own tag-matched
contracts.

Preserve actual customers: existing subscription/config URLs and credentials,
verified account incarnations, active entitlements, identity, signed Wallet
balances, original invoices and financial/Native evidence. Use existing
authorized provider APIs only, without modifying provider internals. The
[architecture](post-v9/WALLET-FIRST-ARCHITECTURE.md) owns shared technical
invariants and controlled manual transfer. Historical Marzban-targeted
unfinished work may be retired without replay, never by discarding
authentic funds/evidence or treating a migrated PasarGuard account as retired.

## Service and Native behavior

- Each local order is a customer's reference to a physical subscription.
  Several customers may register the same verified physical account; local
  permissions/notices remain distinct. Import does not create an account,
  charge money, reserve capacity or rewrite entitlement. Native current
  observations determine display and allowed mutations.
- PasarGuard access uses plan-specific Native Group IDs, not inherited
  server defaults or retired transport metadata. An administrator-declared
  physical Panel Group can span logical servers with different credentials;
  it does not waive actual identity/authority. Transfer and plan selection
  synchronize the affected active references after appropriate verified
  readback. An already-matching access set does not require a PUT.
- Preserve approved five-digit generated names. Choose an unused suffix
  only before the first durable CREATE, under shared physical occupancy
  (including imported accounts and aliases). Explicit names and committed
  effect identity never change to work around an uncertain effect.
- Current scoped offers, renewal, import, gifts and lifecycle follow
  [service management](post-v9/UNIFIED-SERVICE-MANAGEMENT.md),
  [plan/transfer](post-v9/SHARED-SERVICE-TRANSFER.md) and
  [scoped add-ons](post-v9/ADDON-SCOPES.md). Gifts are independent of
  original plan and apply once to the verified physical account, with a
  notice for each eligible linked customer.
- Keep the actual ten-day expiry/deletion grace independent of the
  approved customer warning wording (three days). Change neither timing
  nor copy without approval. Unknown Native evidence never authorizes
  deletion. Native subscription/config output is literal and unchanged.

## Sales, pricing and customer eligibility

- Positive base-price plans are commercial offerings; zero-base-price
  plans are dedicated to trials. Ordinary, custom, bulk and representative
  commercial offerings use the actual eligible current catalogue.
  Admin noncommercial provisioning has its own permission/entitlement.
  The service-availability view shows remaining server capacity, saleable
  plan and server, not retired inbound availability.
- Sales, trial and automatic-renewal switches are independent. Closing
  sales denies new ordinary commercial purchases, manual renewal and
  time/volume add-ons at draft and first method/confirmation admission,
  including legitimate zero-effective prices and stale unissued drafts.
  Main menu, welcome, direct entry and catalogue share the existing
  primary-owner-only ordinary-purchase exception. Trial and automatic
  renewal retain their own switches; authorized administrative provisioning
  is separate. Issued evidence and funded work retain their original
  recovery contract.
- During open sales, payment-method availability cannot hide eligible
  standard/custom/representative catalogues or the existing discount
  route. A valid redeemed discount producing zero, or another legitimately
  authorized zero-effective commercial command, offers the existing free
  confirmation/navigation without Wallet debit, external invoice or
  monetary-method audience gate. It is not a free trial.
- A positive residual needs a current enabled, configured, audience-
  eligible monetary option at the locked discount-redemption boundary.
  Without one, retain the exact original draft and usage counters and
  show the approved discount-only explanation. Shared current method
  eligibility and one payment keyboard govern checkout, add-ons, renewal,
  manual crypto and recharge; Wallet cannot fund its own recharge. Show the
  amount in the message, not in payment-method labels.
  First invoice issuance revalidates actual persisted configuration and
  local quote rules, without a merchant probe. Issued quotes retain their
  original terms. Eligibility is not a guarantee of funds or external
  merchant acceptance.
- Agency pricing requires `agencyState=on` and `users.is_agent=1`.
  Apply one configured percentage policy, with the accepted plan/server
  overrides, to ordinary/single/bulk/custom purchase, manual/automatic
  renewal and time/volume. Stored `agent_bought` is a classification, not
  current discount entitlement. Categories referenced by any plan,
  including inactive plans, cannot be deleted.

## Payments and Wallet

- One owned customer message registry projects manual/API and hosted
  invoices and status. Promote a selector in place, retain receipt evidence,
  retire obsolete navigation before editing, and never blindly resend on
  an unknown transport outcome. Service progress and delivery use the
  original durable evidence, not a payment state alone. See
  [payment message lifecycle](post-v9/PAYMENT-UX-LIFECYCLE.md).


- Receipt proof and Wallet credit are separate from funded service
  completion and message delivery. All methods enter the same original
  settlement transaction and shared service engine. Recover original
  current work automatically where safe; no blind replay of uncertain
  external writes and no assertion of universal remote exactly-once.
- NOWPayments `finished` uses authenticated original invoice/order,
  exact USD invoice quote/local Toman amount and collision/merchant proof,
  not an extra estimated received/payout threshold. Nonfinal or partial
  settlement never auto-fulfills. Browser/Telegram status is not proof.
- Bank and gift-card receipt methods retain optional authenticated
  SMS/gift evidence and legitimate staff review. Manual USDT
  BEP20/TRC20/ERC20 and TRX TRON remain supported. One shared USDT/
  NOWPayments rate and independent TRX rate govern new quotes;
  invalid settings disable the affected option, not other methods.
  See [payment configuration](post-v9/PAYMENT-UX-LIFECYCLE.md) and
  [gateway rules](post-v9/GATEWAY-ELIGIBILITY-RULES.md).
- Only an authorized administrator may deliberately create/deepen
  signed Wallet debt through the explicit audited confirmation. Ordinary
  debit cannot deepen debt. Protect original balance/amount/actor and
  never fabricate historical money, refund or settlement.
- Unrelated stale drafts do not block new eligible work. Verified
  subject change retires only affected unfunded current commands;
  authenticated later funds credit the original Wallet without reviving
  the retired service. Conflicting funded/uncertain work keeps its actual
  ownership boundary. The single [operations inbox](post-v9/AUTOMATIC-GATEWAY-REVIEW.md)
  exposes actionable receipt, service and delivery work; no Archive
  action/state. Failed Telegram edits/sends do not change financial or
  Native finality.

## Staff, text and reporting

- Owner, Manager, Operator and Support have distinct established
  capabilities. Only primary Owner ID belongs in private configuration;
  manage other staff in the bot. Report receiving does not grant authority.
  All genuinely authorized staff retain approved manual receipt review.
  Preserve incoming human-authored Telegram messages, regardless of role,
  and retain issued invoices, receipts, reports and valuable history.
- Two [audience catalogs](../messages/README.md) own static Persian copy;
  per-instance branding, welcome, purchase text, channels and settings
  remain persisted. First-start welcome and menu are one message when they
  fit Telegram's limit; otherwise retain both texts and only one menu.
  Preserve literal/copyable subscription URLs, IDs
  and credentials. Per-server purchase and order display/QR policies are
  independent; see [subscription presentation](post-v9/SUBSCRIPTION-PRESENTATION.md).
- Every actual Wallet movement and completed service report identifies
  the original effect/entry. Primary Owner, configured report channel
  and actual human actor receive the applicable auditable report without
  duplicate destinations; optional notification preferences do not suppress
  the acting operator. Automatic confirmation has no invented actor.
  Each service/destination report uses the latest eligible immutable revision,
  updating its proven prior message rather than sending separate funding,
  completion and delivery notices. Standalone Wallet credit, original receipt
  records and necessary continuation pages remain distinct. Delivery recovery
  reuses original packets and never repeats money or Native changes. Known
  nonapplication and exact idempotent edits recover automatically with
  rate-aware backoff; uncertain first sends are isolated. Transport leases
  do not hold financial or service-execution locks during report I/O.
  See the architecture for durable reporting and sales metric ownership.
- Broadcast/forward processing continues past individual recipient failures
  and resumes after interruption using the original durable cursor. Do not
  replay uncertain sends or treat processing completion as universal Telegram
  acceptance. Report recorded acknowledgements only and keep the shared Cron
  available for other jobs; see [Telegram presentation](post-v9/TELEGRAM-PRESENTATION.md).
- New user-/admin-visible text, menu, interaction, naming, timing and
  reporting changes require explicit prior Owner approval.

## Installation, engineering and acceptance

Use the [fresh installation](post-v9/FRESH-INSTALL.md) requirements and
[CI contract](post-v9/CI-VALIDATION.md). One per-bot Cron coordinator owns
its four ordered child jobs and private lock. No live-effect smoke by
accident. The first-install schema is not an automatic upgrade. Existing
instances need separately approved manual transfer and reconciliation;
never rerun the old Hell/Vivan PasarGuard cutover.

Source, review, integration, publication, file transfer, database
preparation, installation, Cron/webhook activation and real acceptance
are distinct gates. Preserve actual financial/Native state, verify
backup/restore and source identity before any authorized change. Historical
version tests and documentation remain evidence, not current requirements.
The [contributor rules](../AGENTS.md), [security](SECURITY.md),
[Issues](https://github.com/ach1992/bot-refactor/issues) and
[Releases](https://github.com/ach1992/bot-refactor/releases) own engineering,
unresolved work and publication respectively.
