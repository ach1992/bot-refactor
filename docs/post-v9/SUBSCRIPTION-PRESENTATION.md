# Subscription presentation and per-server settings

The shipped Native adapter supplies the actual subscription and configs.
Presentation never changes credentials or Native entitlement. Current
approved product policy and instance settings govern display.

## Ordinary administrator journey

Open **selected server → نمایش کانفیگ‌ها و لینک اشتراک**. Use these independent per-server controls:

| Section | Paired rows and actions |
| --- | --- |
| تنظیمات ارسال و نمایش بعد از خرید | Subscription on/off; configs on/off; choose configs when on; QR cycle (subscription / first selected config / off); auxiliary links on/off |
| تنظیمات ارسال و نمایش صفحه سفارش | Subscription on/off; configs on/off; choose configs when on; auxiliary links on/off. No QR selector. |

Toggles edit only the current bot keyboard and refresh its revision. Each
section is independent. The config editor takes one exact native display name
per line, in the desired order, with at most **10** distinct names. Name matches
are exact; native order breaks ties. Duplicate config URIs are removed from
inline text, and at most 10 configs can appear. Missing names never select a
different config. Send /auto to restore automatic first-three selection; cancel
retains the prior selection and returns to this server's display controls.
Incoming administrator messages remain in history, including invalid input.

With no saved settings: subscription text on, available auxiliary links on,
config text on with the first **3** native configs, purchase QR for the native
subscription URL. Auxiliary visibility and subscription-QR availability are
independent of primary subscription text visibility.

The Native adapter supplies unchanged config URIs. Names come from explicit
native metadata, the URI fragment, or vmess ps. No vendor config is generated,
no arbitrary subscription host is fetched, and native Groups/access are not
changed by presentation choices.

## Explicit order-page QR actions

The two existing **Bot Settings** switches remain authoritative:

- qrConfigState enables the order-page config-QR button and its actual action.
  It sends **every valid native config**, individually: native name on the QR
  photo, then that unchanged config in a copyable preformatted message.
- qrSubState independently enables the subscription-QR button and action,
  using the native subscription URL even when inline subscription text is off.

Inline first-three/ten-name selection, text visibility, message size and
purchase-delivery QR mode never filter these explicit config-QR actions.
Large sets are delivered sequentially in separate photo/text pairs. No new
count cap, aggregate QR budget or automatic replay is introduced. Foreign
orders, disabled/stale actions and unsupported/uncertain native identity remain
denied. Every required photo/text acknowledgement is checked; ambiguous
delivery is reported without falsely claiming completion or retrying it.
Opening or refreshing order details does not send QR photos automatically.

## Complete message size

Selection and HTML assembly happen before measuring the **complete final**
purchase/order message, including order facts, template text, notices,
subscription and auxiliary URLs. The renderer removes complete trailing
configs until the message fits Telegram's 4096-character parsed-text limit,
counting supplementary Unicode conservatively as UTF-16 units. It never
truncates a URI, alters a token, or removes a protected subscription/auxiliary
link to fit more configs. The selection prompt explains this automatic limit.

If protected content alone exceeds one message, the delivery/order-output
boundary splits it into bounded HTML parts while preserving entities, balanced
tags and complete native URI code blocks. The final part carries navigation.
Ordinary messages remain byte-identical. This does not change the global
Telegram transport or financial message handling. Native config validation
still omits malformed/overlong (>2048-byte) URI records whole.

## Per-server settings

One existing setting row uses type ACHBOT_SERVER_DISPLAY_<server_id> and the
version1 envelope with exact server_id, provider, updated_source and display:

~~~json
{
  "mode": "native",
  "policy": {
    "delivery": {
      "show_subscription": true,
      "show_configs": true,
      "show_auxiliary": true,
      "names": ["Germany", "Netherlands"],
      "qr": "subscription"
    },
    "view": {
      "show_subscription": true,
      "show_configs": true,
      "show_auxiliary": true,
      "names": null
    }
  }
}
~~~

Controls update only the selected context/field of the current stored
policy. Editing names explicitly replaces that context's selection without
rewriting the other. Missing selection uses the documented automatic mode;
an intentionally empty selection remains empty. No old Marzban positional
workflow is required. Validate actual existing configuration during manual
transfer; do not silently strip valid user choices.

Malformed documents, unknown keys/types/providers, deleted servers and provider
mismatch suppress config/subscription/QR data. Editing requires an authenticated
private webhook frame, current unique actor/capability, fresh source revision,
owned persisted step, unchanged baseline and serialized transaction/lease.
Logs contain no settings, URLs, raw exceptions or customer values.

## Verification

`src/Subscription/Presentation.php`, `ServerDisplaySettings.php`,
`Delivery.php` and the current link/QR routes own these behaviors. Use
[CI validation](CI-VALIDATION.md) for exact catalog/wire, display and delivery
coverage. See [Telegram presentation](TELEGRAM-PRESENTATION.md) for optional
transport decoration; verify installed operator/customer presentation
separately.
