# Payment checkout and message lifecycle

The shared Wallet-first [architecture](WALLET-FIRST-ARCHITECTURE.md)
owns receipt, funding, service and delivery finality. This guide owns
checkout/message projection and the merchant configuration workflow.

## Customer journey and projection

- All registered methods use the same original owned draft, current
  eligibility and immutable issued quote. Checkout, recharge and renewal
  do not depend on a second executor. The common keyboard preserves the
  approved labels and order without amount suffixes. Show the amount in
  the checkout body; recharge never offers Wallet self-payment.
  A legitimately zero-priced commercial command has its own free
  confirmation and needs no monetary method or external invoice.
- Initial and status receipt actions share the original checkout family,
  callback mapping and admitted receipt type. The pinned invoice evidence
  profile, not later method settings, determines gift image versus API gift
  code input. Bank and crypto retain their own approved labels; closed
  invoices offer original-payment review without a new payment link.
  Starting a manual receipt flow must not delete the original invoice.
  A durable funding admission receives a non-modal acceptance notice,
  without implying service or delivery completion.
- Owned gateway initiation URLs re-check the original payment and
  authoritative current draft; public Return and authenticated merchant
  evidence are separate. Raw historical external links cannot be revoked
  from Telegram; do not claim provider cancellation from a local expiry.
- Issued receipt, Wallet credit, funded-service outcome and customer
  delivery are distinct facts. Current registered message references and
  projection scheduling edit actual original invoice/staff messages to
  show truthful next actions. Failed edits do not reverse funds or service,
  and never trigger a second invoice or Native action. Known
  `message is not modified` acknowledges the intended edit, not a new send.
- Submitted evidence can survive supersession of the original service.
  Genuine late verified funds credit Wallet once, without reviving the
  retired command. No archive action or state, old callback recovery or
  legacy pending-workflow replay. Use the [operator inbox](AUTOMATIC-GATEWAY-REVIEW.md).
- Current manual receipt cooldowns use admitted receipts and actual
  manual approval basis; automatic settlements and historical states do
  not create new restrictions. See [API evidence](API-PAYMENT-EVIDENCE.md)
  for API evidence and original-receipt recovery. Keep user-facing changes
  within the approved product contract.

## Invoice deadlines and late payment

The administrator's payment policy controls new invoices. The default
unissued draft is 30 minutes; manual invoices (including API-assisted
bank and gift receipts) and hosted initiation links are six hours.
The draft deadline is pinned at draft creation, and the original invoice
expiry is pinned at issuance rather than recalculated from subsequent
configuration. A first receipt click before expiry can grant
15 minutes of upload time from that click, without shortening the
original window, extending the transfer window or renewing the grace on
later clicks. The grace and post-expiry SMS search duration are configurable.
A local deadline closes only an unfunded request without recorded evidence;
it never cancels an external payment or declares it unsuccessful. Issued
hosted links use the original guarded checkout route and expose the pinned
deadline. An expired or superseded link cannot initiate another merchant
invoice; the original authenticated reconciliation and staff review remain
available. A closed current draft positively proven never admitted into
payment or service execution is different: show a closed selection notice
and Home, not order review. Missing or inconsistent evidence cannot prove
nonadmission. Bank instructions use the persisted invoice deadline once,
not a hardcoded duration. The API invoice owns its own first registered projection; other
tracked references use the common lifecycle status.

Late evidence is admitted against the same immutable issued invoice.
Verified funds for an expired or superseded service credit Wallet once;
the closed command is not revived. Already recorded evidence and funded
service work retain their original recovery path. Time alone cannot
approve a bank receipt: authenticated API evidence or an authorized
operator decision is required. Historical unsealed invoices are not
reissued. See [API evidence](API-PAYMENT-EVIDENCE.md) for the separate
transfer-time and SMS-search windows.

## Merchant credential and destination editor

The administrator `PAYMENT_KEYS` editor configures methods; it does not settle payments.
From `درگاه‌ها`, select the desired credential, bank-account/holder, gift
seller HTTPS URL, or independent USDT BEP20/TRC20/ERC20 and TRX TRON
destination or exchange rate. An authenticated **private Telegram callback**
opens a field-specific wizard; a new signed private text message saves the
input, and **Cancel** leaves the stored value unchanged. Invalid URLs,
network-incompatible addresses and rates show Persian correction hints
without overwriting the active setting. `/clear` is supported for the
manual crypto destination and rate fields already permitting it.

The editor checks current primary/delegated administrator identity, role,
ban status, exact owned wizard step, source identity and monotonic Telegram
`update_id` on every action. It never trusts cached `userInfo` or
`paymentKeys` to authorize a write. The historical `setting.type` has
no unique constraint: the writer takes a named lock, verifies exactly one
`PAYMENT_KEYS` document (or creates one), locks the current administrator
PK **before** setting PKs, and commits the field-scoped settings JSON,
`users.step` and the separate
`ACHBOT_ADMIN_PAYMENT_KEYS_SOURCE` watermark together. A source marker
stores per-field revision numbers, and a webhook-secret-keyed HMAC
of the previewed value and revision is stored in the wizard; a stale editor
cannot overwrite a concurrent modification, even if another administrator
restores the original value (ABA). Different-field edits can both succeed.
Malformed, duplicate, revoked, unsigned, replayed or unknown-commit
operations return **no success confirmation**, with reason-code-only
administrative logging. No private field value or submitted credential is
echoed into logs, Telegram management menus or success feedback.

Only `configured` / `missing` indicators are shown for
**NOWPayments, Zarinpal and NextPay hosted merchant credentials**.
The public bank account, holder and valid manual-chain destinations/rates
remain displayed for the operator where needed. The settings menu itself
requires a current signed private admin role check; prior forwarded or
cached-role callbacks cannot expose its public destinations to a revoked
administrator. The chat message containing a newly *typed* secret is
still held by Telegram according to its own service behavior: the source
fix cannot erase previous historic Telegram messages containing exposed
merchant credentials. Any installed historical exposure assessment or
credential rotation is a separate Owner/operator action; no rotation
or production webhook was performed in source acceptance.

## Exchange rates and destinations

`manualUsdtRateToman` is the common USD/Toman rate for every customer,
all three configured USDT networks and hosted NOWPayments. Its accepted
whole-Toman range is 100–1,000,000,000. `manualTrxRateToman` is independent.
Clearing/invalidating a rate prevents only the affected new quotes, not
other usable methods. No agent-only USDT rate, Tetherland/private-global
fallback or inferred 1:1 conversion. Issued attempts keep their exact
original price, rate, asset, network, destination and evidence.

See [gateway rules](GATEWAY-ELIGIBILITY-RULES.md) for audience policy and
[readiness preflight](PAYMENT-READINESS-PREFLIGHT.md) for installed checks.
Use [CI validation](CI-VALIDATION.md) for current route, hosted, manual,
eligibility and editor regressions. Future visible changes need Owner
approval.
