#!/usr/bin/env python3
"""Guard audited static Telegram HTML producers without contacting Telegram.

A source-level contract, not a PHP/Telegram parser: only calls whose first
argument is a direct zero-parameter achbotMessage('catalog.key') are in scope.
Other dynamic text, persisted broadcasts, templates and legacy Markdown
messages deliberately retain their existing parse modes.
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

# Audited source/keyboard/copy contract, independent of source line numbers.
# To update for an intentional change, review the effective source diff first,
# then use --print-audit to propose the new digest for an independent review.
# A changed count, key order, parsed argument, or literal catalog text fails CI.
AUDITED = {
    "src/Finance/Routes/DiscountAdministration.php": (53, "612fe86bd57176ecb30d7b55f0cd4779494784c6c581572f03ef63cf76256b83"),
    "src/Plans/Intervals.php": (23, "004ed26d62dec84082559c719b95ff54df07c93afef063603f3f3929b47ac0ac"),
    "src/Plans/Administration.php": (27, "fe9cb621f2bec4c6192b91d2fd72b9fd42383ad7ef51eb8e81afe9f386562348"),
    "src/Plans/AddonAdmin.php": (6, "0ab447c2b7a203de01b329a00a0b78e5dddcfdf1f20fe7ba20c537713fbffadc"),
    "src/Plans/Categories.php": (3, "f5e5f232dab62ecb5bd39adf2a808bf03672d109e5d58f9a8eff2ab1b387f82e"),
    "bot.php": (69, "696ddd1dfcde9e07c2166dd900dc0d30aea72d2994375418c24f61190624df6e"),
    "config.php": (15, "71f4e61ba9ffd3e4cf7b789a1cbca6a46033add9ae00407f5495cee2986ac920"),
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
    return "".join(normalized)


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
    for file in TARGETS:
        records = list(inventory((ROOT / file).read_text(), data))
        test_contract_sensitivity(records, data)
        counts: dict[str, int | str] = {
            "eligible": 0, "html": 0, "legacy": 0, "dynamic_or_literal": 0,
        }
        for name, _, arguments, line in records:
            counts["eligible"] += 1
            if len(arguments) >= 3 and HTML.fullmatch(arguments[2]):
                counts["html"] += 1
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
        "audited_exact": True,
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
