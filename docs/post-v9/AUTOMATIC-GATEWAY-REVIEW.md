# Shared payment and service exception inbox

Current source contract: Wallet-first, [Issue #204](https://github.com/ach1992/bot-refactor/issues/204#issuecomment-7137346243). Historical payment-specific recovery designs remain in Git history, not in the executable contract. Source integration, packaging, production activation and manual database transfer are separately gated.

## One inbox, separate facts

The administrator entry **🛠 عملیات نیازمند رسیدگی** reads original receipt, service operation, delivery and followup records. A merely unpaid or aging invoice does not enter the queue. A submitted receipt with interrupted staff delivery can be recovered from its original stored evidence. Financial approval, Native completion and Telegram acknowledgement are distinct facts. Completed cases leave the queue automatically when no actionable work remains. No archive action, archive state, callback or archive column exists in the current runtime.

## Financial evidence before funding

- All registered manual methods use the same receipt admission and approval/rejection transaction. Only evidence acquisition, destination/quote rules, provider protocol and proof validation differ. Manual rejection records the reason in the original notification packets; uncertain or previously consumed API evidence cannot be discarded by rejection.
- Hosted gateways share the same receipt-credit and unfunded-rejection coordinators. Merchant-specific evidence and verified refund/child status constrain permissible decisions. Staff can recheck a reviewable original with its pinned merchant credential and known reference. Never create another invoice or assert funds solely from elapsed time.
- Verified money credits the original wallet source once. Funding the original service is a separate decision in the same SQL transaction. Subsequent authenticated hosted money for a rejected or superseded service remains in Wallet; never revive the invalidated service. Unfunded superseded manual receipts remain eligible for actual approval or rejection, subject to the same evidence and ownership safeguards.
- A real original receipt remains necessary. Missing, corrupted or uncertain proof is not authorization to consume another item, manufacture credit or claim a refund. All payment-method categories are derived from registered capabilities; the common original session lease serializes financial decisions, API consumption and reconciliation. External transport is outside money transactions.

## Three funded-service decisions

The operation detail is keyed by original service operation, even when no payment invoice exists (scheduled renewals). Payment method is not an input to its execution, retry or disposition.

1. **🔄 ادامه عملیات**: immediately resume the existing funded operation under its lease, without another debit. Observe current Native state before the first possible dispatch; after possible dispatch, reconcile the frozen absolute target rather than repeat a relative increment.
2. **🗂 خاتمه بدون بازگشت وجه**: only for renewal/add-time/add-volume. Record the explicit terminal decision and release owned work. Never claim Native success or refund that did not occur.
3. **💰 لغو و بازگشت وجه**: refund only unfulfilled original value through the wallet ledger. For BUY, keep each completed ordinal and its delivery intent; reconcile any existing unrecorded Native account before refunding. BUY cannot close without compensation. An uncertain remote effect requires explicit external verification, not timeout-as-failure. Automatic-renewal refund disables automatic renewal until deliberately enabled again.

Resolution, refund, capacity and ownership release, and durable reports commit together. An active worker must not be interrupted by a competing decision. Preserve legitimate independent panel changes; a provider lacking atomic conditional writes cannot guarantee remote exactly-once behavior.

## Independent delivery and reporting

A completed operation may still need delivery/report recovery. Reuse original immutable message packets and recipient authorization, not the payment or Native action. Retry known failures; unknown sends require fresh actor-bound confirmation. Human rejection also stages customer/staff notices in the shared outbox within the decision transaction. Notification failure does not undo a committed financial decision. Report actual wallet credit, pending service, completed service and delivery acknowledgement using the common receipt outcome projection. No arbitrary retry cap and no hiding an unresolved payment behind an archive.

Current acceptance: disposable source-real manual, hosted, funding, service execution, callback, fresh-schema and CI suites. No real merchant, Telegram or production Native request is implied by these fixtures. See [architecture](WALLET-FIRST-ARCHITECTURE.md) and [CI](CI-VALIDATION.md).
