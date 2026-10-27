# AChBot message catalog

All current bot-facing Persian/English copy lives in fa/*.php, grouped by
product topic. settings/values.php remains a compatibility facade for the
existing $mainValues and $buttonValues keys, customization and replacement
rules. Those keys and their legacy placeholders are unchanged.

Load messages/loader.php with require_once from an isolated module or test.
It needs no private configuration, database or bootstrap. Internal PHP files
retain direct HTTP guards; namespace declarations precede loader imports.

Use achbotMessage('topic.stable_key', ['name' => $displayValue]). Each
interpolated entry declares its exact parameter names. Substitution is
one-pass; replacement values cannot recursively substitute other parameters.
Missing/extra parameters and unknown keys are programming errors.

Existing call sites retain their original dynamic expressions and escaping.
The loader does not implicitly escape or normalize them. New HTML callers
can use achbotMessageHtml when every supplied parameter is untrusted;
already escaped display values must use achbotMessage to avoid double
escaping. The byte-preserved relocation retains catalog HTML, spaces, line endings,
zero-width characters, emoji and percent/braced literals. Master-authorized
privacy wording corrections under the Owner policy are separately enumerated
in tools/text_catalog_amendments.json; they are not reported as byte parity. Repeated
interpolation occurrences have separate slots so their original string
conversion count and order remain intact. Fixed diagnostic reason/state codes
remain explicit call-site parameters rather than editable catalog prose.

Keys describe the original module, function/route and display purpose. A short
content fingerprint disambiguates distinct fragments without relying on source
line numbers or sequence numbers. Treat keys as stable identifiers when editing
wording; change the catalog value in place. The source inventory locates every
migrated occurrence and preserves its exact original expression for review.

Run python3 tools/test_next_text_catalog.py for:

- exact reverse-source reconstruction of the characterized runtime, including
  unchanged SQL, callback/state/protocol literals and financial expressions;
- native PHP byte comparison of all static and interpolated expressions, with
  null, integer, signed, boolean, Unicode, HTML and placeholder-shaped values;
- legacy facade equality, explicit escaping and one-pass substitution checks;
- Persian literal inventory and ASCII UI sink/variable/interpolation tracing,
  including selected array fields through same-file named function returns,
  unknown/orphan catalog references and direct HTTP guards;
- ASCII button/caption/indirect-message and poisoned-catalog privacy negative
  controls, retaining the separate native audience/privacy fixtures.

The source audit intentionally excludes third-party QR implementation,
redacted transport diagnostics, comments, frozen historical evidence and the
numeric decimal separator used by JDF transliteration. The unrelated static
root index.html remains the existing landing page; its branding is outside the
bot/PHP payment-page copy migration. SQL, callback data, persisted enum/state
identifiers and diagnostic reason codes remain runtime implementation data.
Display labels that spell technical option names are catalog text; callback,
provider payload, selection and persistence values remain implementation data.
Pure dynamic values, punctuation and markup remain rendering expressions.

The inventory is characterization evidence against its recorded Git source
identity, not a golden file to update merely to bless changed business behavior.
After an independently authorized runtime change, review and re-characterize
only the affected source dependency. Frozen V0–V9 checks remain untouched.

Current bounded followups retain the original inventory verbatim. The
amendments manifest records the exact accepted admin-read authorization diff
for three source files, the remaining-traffic template relocation, the
bootstrap connection/charset privacy correction, and three explicit public
checkout wording corrections. The source proof reverses only those named
edits and verifies each immutable dependency before applying the original
per-file reconstruction. No broad baseline refresh is permitted.

The native catalog proof also compares the actual remaining-traffic helper
with its immutable previous helper over signed, fractional, rounding,
unlimited and uncertain results. Run python3 tools/test_next_bootstrap_privacy.py
for source-native connection/charset CLI and HTTP canaries (no real database),
and python3 tools/test_next_gateway_privacy.py --run-disposable for actual
public checkout denial pages over a socket-only synthetic database and owned
loopback HTTP. Those two suites are separate from test_next_text_catalog.py.
The ASCII scanner is a bounded audit aid; it does not interpret dynamic PHP
dispatch or arbitrary cross-file data flow.
