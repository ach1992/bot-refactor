#!/usr/bin/env python3
"""Guard audited static Telegram presentation producers without contacting Telegram.

A source-level contract, not a PHP/Telegram parser: only calls whose first
argument is a direct zero-parameter achbotMessage('catalog.key') are in scope.
Other dynamic text, persisted broadcasts and customizable templates retain
their existing parse modes. Explicit plain-text sends remain pinned.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
TARGETS = (
    "src/Finance/Routes/DiscountAdministration.php",
    "src/Plans/Intervals.php",
    "src/Plans/Administration.php",
    "src/Plans/AddonAdmin.php",
    "src/Plans/Categories.php",
    "bot.php",
    "config.php",
    "src/Subscription/CustomerRoutes.php",
    "src/Purchase/Catalogue.php",
    "src/Purchase/EntryAndBulk.php",
    "src/Purchase/SelectPlan.php",
    "src/Admin/Bans.php",
    "src/Admin/Roles.php",
    "src/Admin/Unban.php",
    "src/Agent/Applications.php",
    "src/Agent/Discounts.php",
    "src/Broadcast/Routes.php",
    "src/Finance/Discounts.php",
    "src/Finance/ReceiptHelpers.php",
    "src/Finance/ManualCheckoutPresentation.php",
    "src/Finance/Routes/ManualCheckout.php",
    "src/Finance/Routes/AdminWallet.php",
    "src/Finance/Routes/DiscountEntry.php",
    "src/Finance/Routes/Checkout.php",
    "src/Finance/Routes/Extensions.php",
    "src/Finance/Routes/ManualReceipts.php",
    "src/Finance/Routes/PrePayment.php",
    "src/Finance/Routes/RenewalCheckout.php",
    "src/Finance/Routes/WalletRecharge.php",
    "src/Finance/Routes/WalletTransfer.php",
    "src/Panel/ProviderAdmin.php",
    "src/Subscription/ExternalAccounts.php",
    "src/Subscription/ManualAccounts.php",
    "src/Subscription/ServerDisplaySettings.php",
    "src/Support/Categories.php",
    "src/Support/Routes.php",
    "src/Support/Presentation.php",
    "src/Support/QueueAndHistory.php",
)
DIRECT_SEND = re.compile(
    r"""(?<![A-Za-z0-9_])sendMessage\s*\(\s*\\?achbotMessage\s*\(\s*(['"])([^'"]+)\1\s*\)""",
)
DIRECT_FIRST_ARG = re.compile(r"""\\?achbotMessage\s*\(\s*(['"])([^'"]+)\1\s*\)\Z""")

# PHP's lexer, not a regular expression, determines which sendMessage tokens
# are actual PHP code. T_STRING excludes comments, quoted strings, heredocs
# and non-PHP text. Python still checks the audited direct-call shape below.
# PHP byte offsets are converted to Unicode source indices after tokenizing.
PHP_SEND_TOKENS = r"""
$source = file_get_contents('php://stdin');
if ($source === false) { throw new RuntimeException('php_source_unreadable'); }
if (!function_exists('token_get_all')) {
    throw new RuntimeException('php_tokenizer_extension_required');
}
$offset = 0;
$positions = [];
$previous = null;
$tokens = token_get_all($source);
$trivia = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
foreach ($tokens as $index => $token) {
    $part = is_array($token) ? $token[1] : $token;
    $type = is_array($token) ? $token[0] : null;
    if ($type === T_STRING && strcasecmp($part, 'sendMessage') === 0 &&
        !in_array($previous, [T_FUNCTION, T_OBJECT_OPERATOR,
                              T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) &&
               in_array($tokens[$next][0], $trivia, true)) {
            $next++;
        }
        if (($tokens[$next] ?? null) === '(') {
            $positions[] = $offset;
        }
    }
    $offset += strlen($part);
    if ($type === null || !in_array($type, $trivia, true)) {
        $previous = $type ?? $part;
    }
}
echo json_encode($positions, JSON_THROW_ON_ERROR);
"""
UNSAFE_HTML_OR_MARKDOWN = re.compile(r"[<>&*_`[\\]")
HTML = re.compile(r"""\A(['"])HTML\1\Z""", re.I)
MARKDOWN = re.compile(r"""\A(['"])MarkDown\1\Z""", re.I)
PLAIN = re.compile(r"""\Anull\Z""", re.I)

# Audited source/keyboard/copy contract, independent of source line numbers.
# To update for an intentional change, review the effective source diff first,
# then use --print-audit to propose the new digest for an independent review.
# A changed count, key order, parsed argument, or literal catalog text fails CI.
AUDITED = {
    'src/Finance/Routes/DiscountAdministration.php': (53, '612fe86bd57176ecb30d7b55f0cd4779494784c6c581572f03ef63cf76256b83'),
    'src/Plans/Intervals.php': (13, '57b3a0e239d448b46404fc7af0fb56944d4e902b71962958836ff155dd14a666'),
    'src/Plans/Administration.php': (13, '7bc479ac209460e9f3270644655a881cda730f9ed5e18f529047d36187404587'),
    'src/Plans/AddonAdmin.php': (5, '77eb5d7a03d05ab9da955a61f38be342857f3d08f620ec7d0c07b6f1cac1bc22'),
    'src/Plans/Categories.php': (3, 'f5e5f232dab62ecb5bd39adf2a808bf03672d109e5d58f9a8eff2ab1b387f82e'),
    'bot.php': (84, 'e92926e1dfe734c520387fe55c27a993bca74ea1a7501ef9b340abf4b875d868'),
    'config.php': (10, '21109c7827c494aba3e73602bac6d30ebc27001282c2bf3bb54d582f5de1c2d7'),
    'src/Subscription/CustomerRoutes.php': (11, '9e1c9bc100ed816666f6728b1b24d163daed570554042a75cfb60e6738b9f64b'),
    'src/Purchase/Catalogue.php': (10, 'b913c025e7247e4c27d41b5b17ebf0940c884c6983e786b0f36c84db58d344cd'),
    'src/Purchase/EntryAndBulk.php': (13, 'dc86806c4025cf9eb6d716891167552fcae264dc1b90f8bf3169c047684d1a59'),
    'src/Purchase/SelectPlan.php': (4, '840035bdcc4a375f4fbabe2b3191f237aae348dcb2589f270f77de177e635c9f'),
    'src/Admin/Bans.php': (6, '94e714bce831aef60ac5881763be6fb57917f2c81423dde4e4ecc4b254f22b66'),
    'src/Admin/Roles.php': (3, '41d4da0e04b854679c6207828052bb6a1f019bacc13d8cb429332e179cafa1f0'),
    'src/Admin/Unban.php': (4, 'cb598e3f049d24aa820cb5d587010ebea0a79cb1d7b3f847df1b9764c2cabd91'),
    'src/Agent/Applications.php': (4, '363b4a512e7978aeae27f1c6f4bc592ee7f583130a2d6852a1f02aadf66b1542'),
    'src/Agent/Discounts.php': (3, 'f65c70afe55e9fb16b12cf878ced107ce6647f63e999354421621a94c0ab2a78'),
    'src/Broadcast/Routes.php': (23, '904f1a37f20ff4d4bf3a73eaef6d7fe0458ec6837376d099a33dcd6700d07bdd'),
    'src/Finance/Discounts.php': (11, 'c85401369c00918a99d619226eef481aa9261a14568941ecddad47866aff2e90'),
    'src/Finance/ReceiptHelpers.php': (0, '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'),
    'src/Finance/ManualCheckoutPresentation.php': (4, '9dcbf8e5a5bb99b6a6b6908116c736c518fd33099a0d8c43038395cdb2e05b8b'),
    'src/Finance/Routes/ManualCheckout.php': (0, '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'),
    'src/Finance/Routes/AdminWallet.php': (8, '0658a0ac6b539ad790c23cb504178ad3ca2303a8d2019b5132828ab1b0f7a28b'),
    'src/Finance/Routes/DiscountEntry.php': (3, '62144a9cf55013a13cd3aa3dfb5adf8c9612d7b23c8910b08a5d2a76af6aa472'),
    'src/Finance/Routes/Checkout.php': (0, '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'),
    'src/Finance/Routes/Extensions.php': (2, 'd5bb022fceb345c5d0f44dbc8b8cfd78c9a763ccc9b42109f61050100a223944'),
    'src/Finance/Routes/ManualReceipts.php': (2, '786805fadd4e8234e6475dfd718160b80ce5ec70fb7881ed95b30fd88d4ecf3e'),
    'src/Finance/Routes/PrePayment.php': (0, '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'),
    'src/Finance/Routes/RenewalCheckout.php': (3, '9d873db4993b88b092836c969c076e17954f4891b7add7324c578b121aca376d'),
    'src/Finance/Routes/WalletRecharge.php': (6, 'e744032b77cae5cb419a1eb38bc79a265314e2a3ab1271f0f8c392abe8d637e6'),
    'src/Finance/Routes/WalletTransfer.php': (13, '08b78de4a9ec758301b184ff78384095064c18d2f284557753fce60af6de63e3'),
    'src/Panel/ProviderAdmin.php': (8, '83172b94fa08ca51589c44a38ffcaf4fe458f549a2dde5a9e01f5b26d807102a'),
    'src/Subscription/ExternalAccounts.php': (4, '4f0b987cfd63ac45858aa292c5eaa54faed9f0f722b0315ce4e88b10bd2240bf'),
    'src/Subscription/ManualAccounts.php': (2, '478b9ee6891fa454375d79a200ce0f5309b198e5d6394dbab5dece0e022eb70f'),
    'src/Subscription/ServerDisplaySettings.php': (2, 'd61d0711698c58159189fd9a69146bd35fc1f5687a5326a571406d36a6b398a9'),
    'src/Support/Categories.php': (6, 'dd1b00a6d7ca7ff0c7c24c59b2bb00c7f6114d4bf9337a47c4590bae2d931a5a'),
    'src/Support/Routes.php': (8, '6b905da503adefaceafdb6d7a42b10e5fe155c4a15432fde292c04f03d35ad00'),
    'src/Support/Presentation.php': (0, '4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'),
    'src/Support/QueueAndHistory.php': (1, '4a8518699ba37a3fd0d8492abcfe69137c03a286fb1f121e049e0a45d8c18972'),
}


def canonical_expression(source: str) -> str:
    """Ignore insignificant PHP source whitespace, never quoted string bytes."""
    normalized = []
    quote: str | None = None
    escaped = False
    for char in source:
        if quote is not None:
            normalized.append(char)
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
        elif char in ("'", '"'):
            quote = char
            normalized.append(char)
        elif not char.isspace():
            normalized.append(char)
    if quote is not None:
        raise ValueError("unterminated PHP literal in audited argument")
    expression = "".join(normalized)
    # The original reviewed keyboards read catalog-derived $buttonValues.
    # A direct lookup of the *same immutable catalog key* has identical text.
    # This canonical form retains the established goldens, including each
    # callback/parse-mode argument, instead of accepting new fingerprints.
    return re.sub(
        r"""\\achbotMessage\(['"]legacy_buttons\.([a-z0-9_]+)['"]\)""",
        lambda hit: "$buttonValues['" + hit.group(1) + "']",
        expression,
    )


def audit_digest(records: list, data: dict[str, tuple[str, bool]]) -> str:
    payload = [
        [name, [canonical_expression(arg) for arg in args], data[name][0]]
        for name, _, args, _ in records
    ]
    packed = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
    return hashlib.sha256(packed.encode("utf-8")).hexdigest()


def test_contract_sensitivity(records: list, data: dict[str, tuple[str, bool]]) -> None:
    """A dropped call or changed keyboard must invalidate the audit contract."""
    if not records:
        raise AssertionError("no auditable Telegram producers")
    digest = audit_digest(records, data)
    if audit_digest(records[1:], data) == digest:
        raise AssertionError("audit did not detect a removed send")
    sample = next((i for i, row in enumerate(records) if len(row[2]) >= 2), None)
    if sample is None:
        raise AssertionError("no auditable keyboard argument")
    modified = list(records)
    name, closing, args, line = modified[sample]
    variant = list(args)
    variant[1] = variant[1] + "/*unexpected-keyboard-change*/"
    modified[sample] = (name, closing, variant, line)
    if audit_digest(modified, data) == digest:
        raise AssertionError("audit did not detect changed keyboard expression")



def parse_arguments(source: str, opening: int) -> tuple[int, list[str]]:
    """Parse a PHP function's argument expressions, respecting nested arrays."""
    if source[opening] != "(":
        raise ValueError("not a function opening parenthesis")
    braces: list[str] = []
    quote: str | None = None
    escaped = False
    start = opening + 1
    args: list[str] = []
    matching = {")": "(", "]": "[", "}": "{"}
    for at in range(start, len(source)):
        char = source[at]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in ("'", '"'):
            quote = char
        elif char in "([{":
            braces.append(char)
        elif char in ")]}":
            if braces:
                if braces.pop() != matching[char]:
                    raise ValueError(f"mismatched delimiter at {at}")
            elif char == ")":
                args.append(source[start:at].strip())
                return at, args
            else:
                raise ValueError(f"unexpected delimiter at {at}")
        elif char == "," and not braces:
            args.append(source[start:at].strip())
            start = at + 1
    raise ValueError("unterminated sendMessage call")


def catalog() -> dict[str, tuple[str, bool]]:
    command = (
        "require 'messages/loader.php';"
        "echo json_encode(achbotMessageCatalog(), "
        "JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"
    )
    decoded = json.loads(subprocess.check_output(
        ["php", "-n", "-r", command], cwd=ROOT, text=True,
    ))
    data: dict[str, tuple[str, bool]] = {}
    for group, entries in decoded.items():
        for name, entry in entries.items():
            if isinstance(entry, str):
                data[f"{group}.{name}"] = (entry, True)
            elif isinstance(entry, dict):
                data[f"{group}.{name}"] = (
                    entry.get("text", ""), not bool(entry.get("parameters", [])),
                )
    return data


def eligible(text: str, no_parameters: bool) -> bool:
    return (no_parameters and isinstance(text, str)
            and not UNSAFE_HTML_OR_MARKDOWN.search(text)
            and "{" not in text and "}" not in text)


def executable_send_starts(source: str):
    """Return character offsets only for PHP-tokenized sendMessage names.

    PHP token_get_all() reports UTF-8 byte offsets. Convert incrementally so
    Persian text and other multi-byte source characters do not shift the
    positions used by the Python expression parser. Tokenization failure is
    fatal rather than silently reducing audited coverage.
    """
    encoded = source.encode("utf-8")
    offsets = json.loads(subprocess.check_output(
        ["php", "-r", PHP_SEND_TOKENS],  # requires PHP tokenizer extension
        cwd=ROOT, input=encoded,
    ))
    if not isinstance(offsets, list):
        raise ValueError("PHP token scanner did not return an offset list")
    last_byte = 0
    last_character = 0
    for byte_position in offsets:
        if (type(byte_position) is not int or
                byte_position < last_byte or byte_position > len(encoded)):
            raise ValueError("PHP token scanner returned invalid offsets")
        last_character += len(encoded[last_byte:byte_position].decode("utf-8"))
        last_byte = byte_position
        yield last_character


def inventory(source: str, data: dict[str, tuple[str, bool]]):
    for start in executable_send_starts(source):
        hit = DIRECT_SEND.match(source, start)
        if hit is None:
            continue
        name = hit.group(2)
        if name not in data:
            raise AssertionError(f"unknown catalog key: {name}")
        opening = source.index("(", hit.start())
        closing, arguments = parse_arguments(source, opening)
        if not DIRECT_FIRST_ARG.fullmatch(arguments[0]):
            # Another achbotMessage, user value or HTML may be concatenated
            # into the first argument; no first-fragment safety inference.
            continue
        if not eligible(*data[name]):
            continue
        yield name, closing, arguments, source.count("\n", 0, hit.start()) + 1


def test_source_scanner_regressions(data: dict[str, tuple[str, bool]]) -> None:
    """Prove code-vs-comment detection against a real audited bot send.

    All variants are constructed in memory. No runtime PHP, customer fixture
    or source file is modified. The baseline fingerprints stay unchanged.
    """
    source = (ROOT / "bot.php").read_text()
    baseline = list(inventory(source, data))
    baseline_digest = audit_digest(baseline, data)
    selected = None
    for start in executable_send_starts(source):
        match = DIRECT_SEND.match(source, start)
        if match is None:
            continue
        closing, args = parse_arguments(source, source.index("(", start))
        if not DIRECT_FIRST_ARG.fullmatch(args[0]):
            continue
        name = match.group(2)
        if name.startswith("legacy_messages."):
            continue
        if not eligible(*data[name]):
            continue
        line_start = source.rfind("\n", 0, start) + 1
        line_end = source.find("\n", closing)
        if line_end < 0:
            line_end = len(source)
        # A complete one-line statement permits valid PHP comment/delete
        # mutations without accidentally swallowing neighboring code.
        if (len(args) >= 2 and args[1] != "null" and
                not source[line_start:start].strip() and
                source[closing + 1:line_end].strip() == ";" and
                "\n" not in source[start:closing]):
            selected = (start, closing, line_start, line_end, args)
            break
    if selected is None:
        raise AssertionError("no isolated audited call with a keyboard")
    start, closing, line_start, line_end, args = selected

    def expect_changed(label: str, edited: str, expected_count: int) -> None:
        records = list(inventory(edited, data))
        if len(records) != expected_count or audit_digest(records, data) == baseline_digest:
            raise AssertionError(label + ": disabled or changed call escaped audit")

    def expect_unchanged(label: str, edited: str) -> None:
        records = list(inventory(edited, data))
        if len(records) != len(baseline) or audit_digest(records, data) != baseline_digest:
            raise AssertionError(label + ": harmless source formatting broke audit")

    # Removed statements and both PHP comment forms must *reduce* the
    # executable-call inventory; previously // passed the raw-regex audit.
    expect_changed("physically removed send",
                   source[:line_start] + source[line_end + 1:], len(baseline) - 1)
    expect_changed("single-line commented-out send",
                   source[:start] + "// " + source[start:], len(baseline) - 1)
    expect_changed("block-commented send",
                   source[:start] + "/*" + source[start:closing + 1] + "*/" +
                   source[closing + 1:], len(baseline) - 1)

    call = source[start:closing + 1]
    if args[1] not in call:
        raise AssertionError("audited keyboard not found in original call")
    changed_call = call.replace(args[1], "null", 1)
    if changed_call == call:
        raise AssertionError("keyboard mutation was inert")
    expect_changed("changed keyboard argument",
                   source[:start] + changed_call + source[closing + 1:], len(baseline))

    # Moving a line or changing spacing outside string literals is harmless.
    expect_unchanged("moved source line", source[:line_start] + "\n" + source[line_start:])
    expect_unchanged("insignificant indentation", source[:start] + "  \t" + source[start:])
    expect_unchanged("call whitespace", source[:start] + source[start:].replace(
        "sendMessage(", "sendMessage   (", 1))

    # Non-code lookalikes in PHP comments, a quoted string and inline HTML
    # must never create a phantom executable notification.
    sample = r"sendMessage(\achbotMessage('bot.bootstrap.unavailable'), null, 'HTML')"
    fake = ("<?php\n// " + sample + ";\n# " + sample + ";\n/* " + sample +
            "; */\n$ignored = \"" + sample + "\";\n?>\n" + sample + ";\n")
    if list(inventory(fake, data)):
        raise AssertionError("non-code lookalike counted as executable send")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--print-audit", action="store_true",
                        help="show candidate audit digests without accepting them")
    options = parser.parse_args()
    data = catalog()
    test_source_scanner_regressions(data)
    if tuple(AUDITED) != TARGETS:
        raise AssertionError("audited paths differ from live inventory targets")
    statistics: dict[str, dict[str, int | str]] = {}
    failures: list[str] = []
    total = 0
    plain_total = 0
    for file in TARGETS:
        records = list(inventory((ROOT / file).read_text(), data))
        if records or AUDITED[file][0]!=0:
            test_contract_sensitivity(records, data)
        counts: dict[str, int | str] = {
            "eligible": 0, "html": 0, "explicit_plain": 0,
            "legacy": 0, "dynamic_or_literal": 0,
        }
        for name, _, arguments, line in records:
            counts["eligible"] += 1
            if len(arguments) >= 3 and HTML.fullmatch(arguments[2]):
                counts["html"] += 1
            elif len(arguments) >= 3 and PLAIN.fullmatch(arguments[2]):
                # Existing explicit no-format sends are intentional contracts.
                # Their call signatures and null parse modes are still fingerprinted.
                counts["explicit_plain"] += 1
            elif len(arguments) <= 2 or (len(arguments) >= 3 and MARKDOWN.fullmatch(arguments[2])):
                counts["legacy"] += 1
                failures.append(f"{file}:{line}: {name}: no explicit HTML mode")
            else:
                counts["dynamic_or_literal"] += 1
                failures.append(f"{file}:{line}: {name}: caller-owned parse mode; audit before migration")
        digest = audit_digest(records, data)
        counts["audit_sha256"] = digest
        statistics[file] = counts
        total += int(counts["html"])
        plain_total += int(counts["explicit_plain"])
        expected_count, expected_digest = AUDITED[file]
        if not options.print_audit and (
            int(counts["eligible"]) != expected_count or digest != expected_digest
        ):
            failures.append(f"{file}: audited count/digest mismatch: "
                            f"expected ({expected_count}, {expected_digest}), "
                            f"actual ({counts['eligible']}, {digest})")
    if options.print_audit:
        for file, counts in statistics.items():
            print(f"{file}: ({counts['eligible']}, {counts['audit_sha256']!r}),")
        return
    if failures:
        print("\n".join(failures[:40]), file=sys.stderr)
        raise AssertionError(f"{len(failures)} static send contract violations")
    print(json.dumps({
        "complete": True,
        "status": "PASS",
        "files": statistics,
        "html_verified": total,
        "explicit_plain_verified": plain_total,
        "audited_exact": True,
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
