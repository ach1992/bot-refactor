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
escaping. Catalog HTML, spaces, line endings, zero-width characters, emoji
and percent/braced literals preserve the previous output bytes. Repeated
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
