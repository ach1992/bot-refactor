# Subscription presentation and delivery

Source contract for the next-release candidate, Issue #81. This document does
not establish installed PasarGuard acceptance or authorize private configuration,
tenant data, schema, deployment, or live provider changes.

## Entry points and data

- Pure renderer: `AchBot\Subscription\Presentation\present($provider, $native, $botState, $options = [], $context = 'view')`.
- Runtime wrapper: `buildSubscriptionPresentation(...)` reads the current bot's
  optional `$subscriptionPresentation[$provider]` and existing legacy settings.
- Delivery: `buildLinksAndSendQr(...)` consumes the same renderer in
  `delivery` context. Existing tuple positions remain unchanged.
- `sendQrFromLink(...)` confirms required photo and account-text effects
  independently; an intentionally empty QR needs only the text acknowledgement.
  It never retries an ambiguous transport effect automatically.

`$native` contains native `links`, `subscription_url` and optional
`backup_urls`. Links may be a native list, newline text, or native
`links_base64` text. Optional individual records have
`{uri, name?, tags?}`. The URI scheme supplies protocol metadata. When no
explicit name is supplied, a URI fragment or native VMess `ps` supplies its
name. URI values remain opaque and unchanged; the renderer never creates
protocol, host, transport, SNI, token, or Group values.

The pinned PasarGuard client returns `links: list<string>` and the native
absolute `subscription_url`. It supplies no link tags. Name selectors can
match names derived from native URIs; tag selectors require explicit upstream
metadata and must not infer tags from names or panel Groups. Delivery accepts
native objects and arrays. It persists the native links, leaves the legacy token
empty, and preserves the caller's account identifier. The provider account
journal owns stable remote identity. PasarGuard never receives a synthesized
Marzban `/sub/` path or backup-domain substitution.

The return value contains:

| Field | Meaning |
| --- | --- |
| `links`, `config_html` | Selected inline configs and escaped Telegram HTML |
| `subscription_url`, `subscription_html` | Visible native HTTP(S) subscription URL and escaped HTML |
| `backup_html` | Visible, valid distinct backup subscription URLs |
| `qr_link` | Single QR candidate for delivery; legacy view context uses the visible subscription URL |
| `qr_config_links` | Config-QR action data, including the explicit legacy exception below |
| `missing_links`, `selection_missing` | Native data absent or explicit selection unmatched |
| `reason` | Internal bounded diagnostic; never insert it into customer copy |
| `notice_html` | Safe Persian next step when no usable links are available |

A subscription-QR action must encode `subscription_url`, rather than a
delivery fallback from `qr_link`. A config-QR action consumes
`qr_config_links`. This keeps their button meanings truthful.

## Per-bot/provider policy

The setting is optional and belongs to each bot's existing private
configuration. No private file is changed by this source work, and the frozen
`baseInfo.example.php` is not extended. Example values below are synthetic:

```php
$subscriptionPresentation = [
    'pasarguard' => [
        'selectors' => [
            ['protocol' => 'vless', 'name' => 'Fast'],
            ['protocol' => 'trojan'],
        ],
        'show_configs' => true,
        'show_subscription' => true,
        'max_links' => 4,
        'hide_if_more_than' => 20,
        'qr' => 'auto',
    ],
    // An explicit Marzban policy opts out of all positional legacy selection:
    // 'marzban' => ['selectors' => [['protocol' => 'vless']]],
];
```

All selector fields in a record must match; records are alternatives. Selectors
run in configured order, with native order inside each match. Duplicate URIs
are removed before applying the maximum. There is no fallback to unselected
configs when a selector misses. An empty selector list deliberately selects no
configs. Omitted selectors select all native configs.

| Option | Values and effect |
| --- | --- |
| `selectors` | List of selectors with exact `protocol`, `name`, `tag`; protocol comparison ignores case |
| `show_configs` | Boolean; default true, combined with the bot config-link switch |
| `show_subscription` | Boolean; default true, combined with the bot subscription-link switch |
| `max_links` | Nonnegative integer maximum after selection; zero selects none |
| `hide_if_more_than` | Nonnegative integer threshold on total valid native configs |
| `qr` | `auto` (visible subscription, then first selected visible config), `subscription`, `first_config`, or `none` |

The `qr` option controls the single delivery QR. Config-QR actions remain
available for selected visible configs; use `show_configs => false` to hide
both inline config text and config-QR data. Explicit policies use the same
selection for view, delivery, and config QR. Invalid policy types, keys, or
selector shapes fail closed with `invalid_policy` and safe customer copy.
Unset/null Marzban policy enables compatibility; an explicit empty array opts
into the new native policy with all configs.

These settings control presentation only. They cannot constrain what a full
subscription URL returns or replace provider-side Group/Host access controls.
A bot that must limit entitlement must configure native provider Groups. Do not
promise that hiding inline links restricts upstream subscription contents.

## Marzban compatibility when no explicit policy is set

The renderer preserves the real current Marzban distinctions:

| Surface | Existing behavior |
| --- | --- |
| View with at most three links | All native links in native order; ignore `$showIndexes` |
| View with more than three links | `$showIndexes` is **one-based** membership; preserve native order |
| Delivery inline configs | `$linkIndices` is **zero-based**, in configured order; missing positions use the first link; empty setting uses first |
| Delivery with no native configs | Use the visible subscription URL as the legacy config fallback |
| Inline hiding | Bot `configLinkState=off` hides inline text; `$hideConnectLinkForMarzban` additionally hides it in view; `$hideConfigIfMany` hides delivery inline configs above ten |
| Single delivery QR | Second native link, or first when only one, for at most three links; above three use visible subscription URL, then first-link fallback |
| Explicit config-QR action | **All raw native links**, preserving order and duplicates, even when inline text is hidden |
| Subscription visibility | Bot `subLinkState=on` controls subscription text, backup text, and named subscription QR |

The old positional settings never select PasarGuard configs. Genuine legacy
fallbacks remain confined to compatibility mode; explicit provider policies do
not silently borrow indices, select a different config, or invent a URL.

## Evidence and limits

`php tools/test_subscription_presentation.php` runs real renderer,
extracted runtime wrappers, and real delivery helpers with local synthetic
transport. It checks native formats, selectors, policy failures, both Marzban
index bases and QR contracts, malformed/empty data, customer escaping,
independent Telegram acknowledgements, and QR-file cleanup. It uses no database,
network, real account, credential, or customer information.

The existing `tools/test_post_v9_catalogue_qr.py` and
`tools/test_post_v9_standard_integrity.py` retain native source/isolated
database coverage for Marzban QR and financial delivery behaviour. Master owns
aggregate call-site verification, exact-candidate PHP 8.1/8.4 CI, package gates,
and installed/operator acceptance.
