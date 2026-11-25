# Automatic renewal — current Wallet-first contract

Scope: the Wallet-first replacement in Issue #204 / PR #234. This is the
current source contract, not authorization to change production schedules,
reconcile historical claims or perform a live renewal. For older installed
releases use their exact tagged documentation. Earlier separate renewal
journal and payment-executor designs are retained in Git history only.

## Admission and scheduling

Use the single existing settings/cronRunner.php coordinator and its services
child (see [fresh installation](FRESH-INSTALL.md)). Do not schedule the renewal
module independently. src/Finance/AutomaticRenewal.php scans active orders
with per-order autoRenew enabled and a nonzero warning marker, when the
global renewAutoState switch is on. Its keyset cursor advances across the
examined rows and wraps, including temporarily unavailable accounts.

src/Services/AutomaticRenewal.php admits the original renewal through the
common service engine. A managed Native account, current owner, plan,
server-commerce policy and supported adapter capabilities must match.
The current shipped Native adapter is PasarGuard. Authenticated current
Native observations determine eligibility and the expiry-based cycle;
the existing policy in src/Panel/ServiceLifecycle.php considers renewal
when finite expiry is less than two hours away or finite remaining quota
is below 0.2 GiB. Unverified/invalid observations do not authorize a write.

## Funding and execution

Under the original physical account lease, revalidate owner, order, current
plan, account identity and the cycle. Freeze the original quote (including
the applicable agent discount), and use the same service_operations and
wallet_entries as manual funded renewals. The cycle has one current command
identity. Issue, fund and reserve the physical account together in a local
transaction. A positive price gets exactly one original wallet debit; a
zero-price approved quote has no fictitious debit. There is no additional
payment invoice, separate renewal journal or legacy execution mode.

If funds are insufficient, commit an unfunded cancelled refusal, disable
automatic renewal only on that subscription and stage its customer low-wallet
notice in the same transaction. No debit, capacity or Native effect is
created. The customer can top up and deliberately re-enable renewal; the
refusal does not consume a funded cycle.

## Recovery and finality

The common service maintenance worker resumes the same funded operation.
Keep the original account incarnation, quote, debit and frozen absolute
target. Reobserve before possible dispatch, reconcile a possible Native
write with authenticated readback, and apply the existing operation-specific
retry semantics. Do not repeat a relative increment or charge again.
A provider error or timeout is not proof of failure. The local lease does
not guarantee cancellation of a delayed remote write: see the documented
existing-API limitation in [architecture](WALLET-FIRST-ARCHITECTURE.md).

After verified Native success, the common local completion records order
expiry, audit and durable customer delivery plus original staff/channel
reports. Notification failure never rolls back committed financial or
Native finality. The shared delivery and followup journals retry definite
failures and require explicit current actor-bound confirmation for unknown
acknowledgements; no payment or renewal is repeated merely to resend a
message. Original wallet before/after values and service reference come
from the frozen command and its ledger entry.

The [shared exception inbox](AUTOMATIC-GATEWAY-REVIEW.md) permits the approved
continue, close without refund (where eligible), or cancel/refund decisions.
A refund releases the original owned work, uses the common wallet journal
and disables automatic renewal until deliberately enabled again. Do not
treat uncertainty as permission to refund or start a second renewal.

## Validation and operation

Use the single current-schema.sql and the [read-only preflight](PAYMENT-READINESS-PREFLIGHT.md).
No automatic_renewal_attempts table, old migration sequence, or Marzban
executor is required by this runtime. Historical unresolved claims and real
money are reconciled separately before a new installation is activated.

The required wallet_first_service_execution suite exercises actual scheduled
admission, same-account aliases, one debit, low-wallet notice, original
absolute target and retry, lost acknowledgements, local completion, durable
delivery/report, and automatic-renewal refund/re-enable. Source CI does not
establish real installed provider, payment, scheduler or Telegram acceptance.
