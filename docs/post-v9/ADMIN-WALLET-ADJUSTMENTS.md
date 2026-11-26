# Wallet adjustment and transfer

The [Wallet-first architecture](WALLET-FIRST-ARCHITECTURE.md) owns
the only monetary ledger and exact replay rules. This guide owns the
administrator adjustment and customer transfer interaction contracts.

## Adjustment

Authorized staff use the existing Increase/Decrease controls in their
authenticated private Telegram conversation. User selection, positive
whole-Toman amount and nonempty reason require fresh genuine text updates
from the actual actor/chat. A callback's stored bot text cannot become
the reason. Check exact source update/message order, actor role, user
identity and owned step. A debit creating or deepening intentional
administrator debt requires explicit fresh confirmation of the locked
before/after preview. Changed balance requires a fresh preview; no
money moves during preview. Customer-origin ordinary debits cannot deepen
debt.

One local transaction checks original source identity, operator/target,
wallet arithmetic and step, writes original adjustment evidence and the
common ledger entry, and consumes the exact interaction. No legacy direct
wallet UPDATE. Back/panel navigation confirms cancellation only if the
exact pending step is actually cancelled; uncertain SQL or a competing
completed action is not evidence of non-execution.

## Transfer

An authenticated private transfer start claims a fresh generation. Actual
recipient selection and amount messages must follow the source update/
message floor. Bind current actor and selected recipient identity, nonce/
generation and exact step; stale/repeated selection cannot acquire a newer
flow. Retain the actor's persisted source cursor across completion/cancel.
Do not reset it to bypass replay checks. If Telegram's documented update-ID
epoch restarts and current monotonic evidence cannot authorize an input,
reconcile that exact instance and actor; no guessed history reset.

Lock affected users deterministically, verify sufficient sender funds and
recipient range. The original transfer has two atomic ledger legs and
original audit evidence; neither can commit without the other. Incoming
credit can reduce existing recipient debt. A duplicate original command
returns the original result, not another movement.

## Finality and recovery

Original source identity, actor, target, reason and before/after are
durable. Unknown COMMIT acknowledgement requires lookup by the original
source, not an invented retry/refund. Customer/admin notices and the actual
human actor/channel reports use committed ledger facts; notification
failure never reverses money. Current schema and permission checks are
mandatory. The [CI contract](CI-VALIDATION.md) covers admin-wallet and
transfer races, current ledger and original reporting.
