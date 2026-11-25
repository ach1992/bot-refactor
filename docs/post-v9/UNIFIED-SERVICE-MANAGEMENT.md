# Server, plan and subscription lifecycle

This is the Owner-approved management and user-visible lifecycle contract
([original management decisions](https://github.com/ach1992/bot-refactor/issues/37),
[physical transfer #112](https://github.com/ach1992/bot-refactor/issues/112)).
The Wallet-first implementation ships PasarGuard only; source-owned provider
registration keeps common server/plan/account workflows extensible.

## Management

- Keep the familiar unified server and plan menus. Provider-specific
  entitlement inputs come only from the selected adapter. Validate actual
  read-only Native capability before admitting new supported offerings.
  Server/plan wizards are actor-isolated and revision-bound; cancel/back
  recover the current flow. Group selection, deselection and pagination
  edit the existing bot message; fresh typed input gets the next prompt.
- Preserve incoming administrator-authored Telegram messages (including
  passwords/keys, invalid input, cancellation text and media). Bot-authored
  menu and scheduled outgoing-message cleanup remain distinct. Keep
  credentials out of feedback and general logs.
- Mutation checks current unique actor, role, owned draft and actual
  target under lock; stale or previous-flow callbacks cannot overwrite a
  newer draft. A fresh cancel from an older revision of the same flow
  closes that flow, not a different nonce. Server deletion is not an
  authorization to overwrite a conflicting financial/wizard step.
- Confirmed server removal can retain dependent historical records. Do
  not invent a compatibility route, reassign capacity or recreate removed
  server data. Root categories referenced by plans cannot be deleted.
- Physical Panel Groups, linked-reference transfer and verified plan
  assignment follow [shared service transfers](SHARED-SERVICE-TRANSFER.md).

## Import and subscription views

Keep the supplied-link → preview → confirm → local order journey. A
customer or authorized administrator-selected recipient can add a verified
existing subscription; independent customer references to the same
physical account are allowed. Do not allow a second active registration
for the same recipient and exact physical incarnation, including after
logical transfer. A supplied URL/token is not proof by itself; see
[Native import proof](PASARGUARD-CONTRACT.md). No extra user step, proxy
rewrite, account creation, payment or capacity reservation.

Record actual remote incarnation, authentic import/adoption evidence,
zero paid amount, planless reference and automatic renewal off. Native
NULL expiry maps to the local zero sentinel; the Native observation stays
NULL. Later permitted plan selection, link refresh, regeneration and
deletion do not require a fabricated initial plan. A verified import
does not acquire trial eligibility or reserved capacity.

Use one fresh normalized Native observation per page for status, usage
and expiry; no cross-request cache or stale local fallback authorizes
payments or actions. Known unlimited quota/time is «نامحدود»; unknown
or malformed evidence is not unlimited, and finite zero remaining quota
is not unknown. Tehran Jalali dates retain ASCII digits. Display native
subscription/config bytes exactly; see [presentation](SUBSCRIPTION-PRESENTATION.md).

Planless imports can use supported management actions and a later plan
selection; new automatic renewal requires an eligible paid plan. Existing
consent remains withdrawable when a plan/global setting or provider
observation no longer permits new consent. Relative time extensions need
a supported finite baseline; volume cannot be added to unlimited total
quota. Full renewal may replace unlimited expiry with a valid finite
whole-days plan, preserving other entitlement rules.

## Scheduled lifecycle and capacity

Current quota/expiry warning thresholds are 36 hours or 0.5 GiB; automatic
renewal uses the configured independent policy with 2 hours or 0.2 GiB
trigger values. Genuine imported subscriptions receive normal warnings,
including while planless; true free trials retain their established silent
warning policy. Failed warning delivery does not prevent a verified expiry/
exhaustion from starting the full 10-day deletion grace. The existing
customer warning wording is governed by the product contract; do not
silently change it. A positive deletion deadline must be valid and after
the original order creation; unknown observations or unresolved ownership
defer. Scheduled traversal rotates deferred rows so they cannot starve
other eligible work. Never turn a notification flag into permission to
charge or delete. Administrator stage/deadline is read-only.

Automatic renewal uses one consenting funded claimant for a physical cycle,
the same service engine and current agency pricing. The actual readback,
ownership, original journal and reservation decide all effects; see
[automatic renewal](AUTOMATIC-RENEWAL-SAFETY.md) and [Native API](PASARGUARD-CONTRACT.md).
DELETE removes the actual verified remote account when permitted, not a
silent transfer/detachment; preserve other references' evidence and
capacity provenance. Import does not reserve capacity and cannot restore
an unreserved slot. Nonpaid provisioning retains its own actor/entitlement
and capacity rules without fictitious invoices.

## Verification

The current [CI contract](CI-VALIDATION.md) covers onboarding,
provider/account identity, management permissions, stale updates, import
aliases, ownership, lifecycle, scoped plans and presentation. The [installed
acceptance work item](https://github.com/ach1992/bot-refactor/issues/183)
owns actual operator/Native/Telegram proof.
