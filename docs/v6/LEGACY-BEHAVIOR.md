# V6 inherited legacy behavior intentionally preserved

The V6 boundary is **parity**, not the authorization to repair historical defects. Keep these accepted risks visible and subject to separate behavior/security owner decision:

- Some original X-UI cURL operations disable `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST`, and implement ad-hoc login/cookie management. New V6 preserves these exact upstream calls, values, timeout, header and error semantics, rather than promising upgraded TLS/security.
- Marzban token/login, response shape and repeated polling have existing partial/network failure behaviors. Provider-vs-MariaDB writes can be non-atomic: a remote change can succeed before SQL write and vice versa. Do not add retries/transactions or infer idempotency without a separate contract.
- `settings/subLink.php` legacy token gate checks an **unanchored** 30-alphanumeric substring regex and then matches the **entire** token via SQL; the endpoint mutates panel remark and order row as a GET side effect, may show warnings on missing order/plan, and emits a base64 body or legacy error text. This is compatibility evidence, **not a secure token recommendation**. Do not alter public GET, tokens, endpoint errors or encoding during V6.
- Existing Telegram user/admin/agent permission checks are not standardized; account deletion callbacks include broad legacy actor behavior and irreversible provider/SQL deletion. Do not silently enforce new ownership rules or correct business behavior in a parity refactor.
- Source includes duplicate `switchLocation` handling and a linked `giftServerVolume` → `elseif(deleteMyConfig/yesDelete...)` chain. The V6 extraction preserves route order, duplicate evaluations, original `exit` and ancestor conditional evaluation. Do not simplify duplicated handlers just for style.
- V5 broadcast's historical 429 offset advancement, Issue #12 and legacy SQL edge cases are unrelated and untouched. Payment/wallet/auto-renew/plan price policy remains owned by later phases (V7/V8), not refactored here.

Synthetic differential tests compare current behavior; operator still needs staging compatibility evidence against consented real target versions. Any required fix needs its own explicit authorization, test coverage, and review of changed external semantics.
