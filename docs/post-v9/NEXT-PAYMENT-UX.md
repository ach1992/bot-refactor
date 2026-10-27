# Next-release payment UX corrections

Contract: Issue #26 comments 6433839924 and 6434034586, assignment
NEXT-PAYMENT-UX-2 (revision 2). This is source development evidence, not an
installed release or permission for live financial/provider operations.

Home sends a new personal menu for `mainMenu`, `evidenceHome`, payment Home
callbacks and the existing start deep link. Generic legacy Home buttons may
belong to untracked invoices, results or support evidence, so their originating
message is deliberately preserved too. Home does not authenticate an invoice,
read somebody else's payment, issue a quote or settle anything. Status and
payment actions retain their separate persisted-owner and state checks.

Invoice/status/manual receipt references include the customer numeric Telegram
ID from the persisted payment. The manual invoice lookup also checks the
requesting owner; callback data never supplies the displayed numeric identity.

Volume entry reads the complete order needed for identity, provider, expiry,
agent and plan pricing. Selection repeats owner/provider/expiry checks before
creating a draft. New volume/day add-on invoices require positive whole GiB/days. Agent plan and
server discounts retain the existing configuration meaning.

The owner-private attention menu collects supported payment subjects from
unresolved shared method evidence, persisted processing/processing-wallet
claims for any supported subject, unresolved CREATE/hold evidence, and the
optional automatic-renewal journal. Historical `paid` purchases without modern
journals, and completed CREATE records alone, do not become unresolved cases.
The specialized NOWPayments evidence screen and exact CREATE readback remain
separate tools. The general inventory and case screens perform local reads
only, with no automatic approval, credit, retry, reissue, refund or status repair.

Payment records, provider observations, and business effects are described
separately. Cases show their own pinned payment quote/transaction observations;
automatic renewals can show the exact cycle/target/reason journal. Wallet
balance today is not proof of a particular top-up. Mutable account expiry and
unlinked `increase_order` rows are not proof of a particular renewal/add-on.
Where no payment-correlated effect journal exists, the UI states the evidence
gap instead of inventing success or failure. Missing optional journals limit
coverage; an empty list is not certification of all payments.

Native regression: `python3 tools/test_next_payment_ux.py --run-disposable`.
It executes the actual staged bot and routes against a disposable socket-only
MariaDB, with synthetic users and fake Telegram, including original-source
RED/current GREEN volume and Home checks. Existing lifecycle, recovery and
dynamic customer/admin privacy regressions remain applicable. Worker validation
used PHP 8.4.24; PHP 8.1 was not installed on this Dev host. Master owns aggregate
matrix, frozen-history/package gates and integration. Real Telegram, installed
operator behavior, tenant migrations and real providers are NOT TESTED here.
