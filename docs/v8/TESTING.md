# V8 acceptance and evidence

**Only authorized disposable development/test infrastructure. Never execute
these commands in a deployed bot directory, on production MariaDB, with real
payment/panel/Telegram endpoints or real scheduled Cron.**
`settings/rewardReport.php` performs financial processing; the fixture
executes an isolated copy of its extracted financial segment only.

## Commands

```bash
# Fast current-source feedback (no historical acceptance; PHP 8.4 or 8.1):
python3 tools/check_v8.py --focused --report /tmp/v8-focused.json

# Full official-style PHP 8.4 gate, including immutable source history:
python3 tools/check_v8.py --report /tmp/v8-full.json --evidence-dir /tmp/v8-evidence

# PHP 8.1 compatibility; run under a real PHP 8.1 CLI environment:
python3 tools/check_v8.py --php81 --report /tmp/v8-php81.json

# Source-only archive ONLY after a clean committed exact HEAD:
python3 tools/package_v8.py --output /tmp/achbot-v8-runtime.zip
python3 tools/verify_v8_package.py --zip /tmp/achbot-v8-runtime.zip --manifest /tmp/achbot-v8-runtime.zip.manifest.json
```

`check_v8.py` verifies the full pinned 92-member runtime source,
PHP syntax, 24 legacy purchase/refund facades plus five financial config
facades, 47 V7 purchase/provision steps over 18 scenarios, 68 additional
finance steps over 52 scenarios, eight deliberate finance mutations,
two concurrent real mysqli wallet-transfer races (including insufficient
funds), four reversible V7/V8 source overlay phases and 54 GET/POST
404 checks for 27 internal finance modules.

The native financial fixture uses *two* disposable MariaDB databases
with the original 23-table schema, actual `mysqli`, a locally owned
synthetic Telegram trace, a local HTTP VPN provider and a separate local
HTTP gateway. It compares ordered provider requests, Telegram/warnings,
payments, wallet balances, discounts, orders, rewards and database state
after **each** request. It verifies before/after normalized DDL for
**both** the original and refactored database. Payment flows include
local NowPayments invoice, error/malformed reply, successful/replayed,
partial, canceled and failed callbacks, receipt auto-settlement and
Cron safe branches, real local TRON transfers including a failed contract
flag inherited and falsely accepted by the source. Serialized callback replay is **not** a proof of
concurrent exactly-once settlement.

Eight deliberate mutations on **temporary staged candidate copies**
demonstrate the harness detects changed debits, admin credits,
invoice expiry, coupon amounts, referral amounts, card settlement state,
gateway status and provider payment ID persistence. No frozen expected
fixture, original schema or historical test source is modified.

Full `check_v8.py` additionally extracts the exact integrated V7 Git
commit in a temporary working tree, then executes its unchanged
`tools/check_v7.py` — including V7 native acceptance and all historical
V0–V6.1 tests. On PHP 8.1 it exercises the pinned V7 cross-version
compatibility/backup controls. CI always checks out the **exact PR HEAD**
and produces an artifact bound to that SHA/TREE. A subsequent commit
invalidates earlier acceptance and review.

**Limitations:** synthetic services do not certify payment-provider
production settlement, real blockchain finality, live VPN/Telegram
delivery, production Cron concurrency, aaPanel, real ZIP backup restore
or installed V7 operator approval. No source release, schema migration,
production change or financial-policy correction is authorized.
