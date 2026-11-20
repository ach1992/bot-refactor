#!/usr/bin/env python3
"""Prove the audited discount-wizard catalog compositions are HTML-safe.

Only catalog fragments and one integer-coerced userid are eligible. This runs
PHP's real catalog renderer in isolation; it never loads the bot, database or
Telegram transport. The PHP tokenizer recognizes active call sites so disabled
notifications cannot silently satisfy the source contract.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import subprocess

import test_post_v9_telegram_html_callsite_parity as static

SOURCE = Path("src/Finance/Routes/DiscountAdministration.php")
PREFIX = "payment.discount_administration.add_discount_code.message."
FRAGMENT = re.compile(
    r"\\?achbotMessage\('(" + re.escape(PREFIX) + r"[^']+)'\)"
)
NUMERIC = re.compile(
    r"\\?achbotMessage\('(" + re.escape(PREFIX) +
    r"[^']+)',\['uid'=>\$uid\]\)\Z"
)
NUMERIC_KEY = PREFIX + "12c329225f"
AUDITED_COUNT = 10
EXPECTED_SHA256 = "730215138ef69f586673516628986ec4c7104062f59c99c44144aebe50ded30b"
# Independent of the ten sendMessage call-site fingerprints: executable PHP
# tokens from the owning conditional through the send retain reviewed control
# flow. Whitespace/comments are deliberately ignored.
EXPECTED_UID_PATH_SHA256 = "16a7783b85309a54a94c7be82fd44c7e545daf4d7fa5d3f6ecc571cd30657b7f"



# Only the existing integer-derived discount message has source provenance
# requirements. Lexer tokens exclude comments/strings and carry byte offsets.
PHP_CODE_TOKENS = r"""
$src = file_get_contents('php://stdin');
if ($src === false || !function_exists('token_get_all')) {
    throw new RuntimeException('php_tokenizer_required');
}
$tokens = []; $offset = 0;
foreach (token_get_all($src) as $token) {
    $value = is_array($token) ? $token[1] : $token;
    $kind = is_array($token) ? token_name($token[0]) : null;
    if (!in_array($kind, ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true)) {
        $tokens[] = [$offset, $kind, $value];
    }
    $offset += strlen($value);
}
echo json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
"""


def executable_tokens(source: str) -> list[tuple[int, str | None, str]]:
    return json.loads(subprocess.check_output(
        ["php", "-r", PHP_CODE_TOKENS], cwd=static.ROOT,
        input=source.encode("utf-8"),
    ))


def matching_bracket(tokens: list, opening: int, left: str, right: str) -> int:
    if tokens[opening][2] != left:
        raise AssertionError("numeric provenance: malformed branch")
    depth = 0
    for index in range(opening, len(tokens)):
        part = tokens[index][2]
        if part == left:
            depth += 1
        elif part == right:
            depth -= 1
            if depth == 0:
                return index
    raise AssertionError("numeric provenance: unbalanced branch")


def numeric_provenance(source: str, send_start: int) -> str:
    """Validate the one executable cast and its last read in the owning branch.

    A deliberately bounded contract, not general PHP data-flow analysis. Any
    alternate source flow must be intentionally reviewed and accepted.
    """
    tokens = executable_tokens(source)
    send_byte = len(source[:send_start].encode("utf-8"))
    sends = [
        i for i, (offset, kind, part) in enumerate(tokens)
        if offset == send_byte and kind == "T_STRING"
        and part.lower() == "sendmessage"
    ]
    if len(sends) != 1:
        raise AssertionError("numeric provenance: audited send is not executable")
    send_index = sends[0]
    scopes = []
    for index, (offset, kind, part) in enumerate(tokens[:send_index]):
        if kind != "T_IF" or tokens[index + 1][2] != "(":
            continue
        cond_end = matching_bracket(tokens, index + 1, "(", ")")
        if not any(t[1] == "T_CONSTANT_ENCAPSED_STRING" and
                   "editDiscountAudienceUser" in t[2]
                   for t in tokens[index + 2:cond_end]):
            continue
        body = cond_end + 1
        if tokens[body][2] != "{":
            raise AssertionError("numeric provenance: expected braced PHP branch")
        body_end = matching_bracket(tokens, body, "{", "}")
        if body < send_index < body_end:
            scopes.append((index, body))
    if len(scopes) != 1:
        raise AssertionError("numeric provenance: send left its owning branch")
    branch_start, block = scopes[0]
    uid_uses = [
        i for i in range(block + 1, send_index)
        if tokens[i][1] == "T_VARIABLE" and tokens[i][2] == "$uid"
    ]
    # Exactly one active cast and one read (current DB bind). Any added
    # assignment, increment, unset, reference or shadow use must be reviewed.
    if len(uid_uses) != 2:
        raise AssertionError("numeric provenance: uid reads/writes changed")
    assignment, read = uid_uses
    cast = tokens[assignment:assignment + 5]
    if ([t[2] for t in cast] != ["$uid", "=", "(int)", "$text", ";"] or
            [t[1] for t in cast] !=
            ["T_VARIABLE", None, "T_INT_CAST", "T_VARIABLE", None]):
        raise AssertionError("numeric provenance: executable integer cast changed")
    if tokens[read - 1][2] != "," or tokens[read + 1][2] != ",":
        raise AssertionError("numeric provenance: later uid usage changed")
    # The cast must start a top-level statement after the preceding braced
    # numeric-input validation. An unbraced if, while, for, do or
    # alternative syntax guard immediately before the cast is not a
    # statement boundary and must not be accepted as unconditional.
    if tokens[assignment - 1][2] != "}":
        raise AssertionError("numeric provenance: cast gained a statement guard")
    # Pin all executable tokens from this specific reviewed conditional
    # through the send, including conditionals/loops/labels that could skip
    # the cast without adding another uid token. A harmless comment, moved
    # line or spacing change keeps the digest; any changed executable path
    # requires deliberate re-review rather than false assurance.
    guarded_path = [[kind, value] for _, kind, value
                    in tokens[branch_start:send_index]]
    path_digest = hashlib.sha256(json.dumps(
        guarded_path, ensure_ascii=False, separators=(",", ":")
    ).encode("utf-8")).hexdigest()
    if path_digest != EXPECTED_UID_PATH_SHA256:
        raise AssertionError("numeric provenance: reviewed execution path changed: " + path_digest)
    depth = 1
    levels = {}
    for i in range(block + 1, send_index + 1):
        part = tokens[i][2]
        if part == "}":
            depth -= 1
        if i in (assignment, read, send_index):
            levels[i] = depth
        if part == "{":
            depth += 1
    if [levels[i] for i in (assignment, read, send_index)] != [1, 1, 1]:
        raise AssertionError("numeric provenance: cast/read/send path changed")
    encoded = source.encode("utf-8")
    begin = tokens[assignment][0]
    finish = tokens[assignment + 4][0] + 1
    return encoded[begin:finish].decode("utf-8")


def inventory(source: str, catalog: dict) -> list[dict]:
    records = []
    for start in static.executable_send_starts(source):
        end, arguments = static.parse_arguments(source, source.index("(", start))
        expression = static.canonical_expression(arguments[0])
        fragments = FRAGMENT.findall(expression)
        if (len(fragments) > 1 and
                expression == ".".join(
                    "\\achbotMessage('" + key + "')" for key in fragments)):
            kind = "composed"
            keys = fragments
        elif NUMERIC.fullmatch(expression):
            kind = "integer"
            keys = [NUMERIC.fullmatch(expression).group(1)]
            if keys != [NUMERIC_KEY]:
                raise AssertionError("unexpected parameterized discount template")
            # This must be the active final assignment in the owning branch.
            uid_statement = numeric_provenance(source, start)
        else:
            continue
        if len(arguments) != 3 or not static.HTML.fullmatch(arguments[2]):
            raise AssertionError("composed discount prompt lost explicit HTML mode")
        if arguments[1] != ("null" if kind == "integer" else "$cancelKey"):
            raise AssertionError("discount keyboard argument changed")
        for key in keys:
            if key not in catalog:
                raise AssertionError("missing discount catalog fragment " + key)
            text, no_parameters = catalog[key]
            if kind == "integer":
                if no_parameters or text.count("{uid}") != 1:
                    raise AssertionError("expected a single numeric userid slot")
                sample = text.replace("{uid}", "123456789")
            else:
                if not static.eligible(text, no_parameters):
                    raise AssertionError("unsafe dynamic/markup catalog fragment: " + key)
                sample = text
            if static.UNSAFE_HTML_OR_MARKDOWN.search(sample) or (
                    "{" in sample or "}" in sample):
                raise AssertionError("fragment not literal-safe for both parsers: " + key)
        records.append({
            "kind": kind,
            "keys": keys,
            "args": arguments,
            "start": start,
            "end": end,
            "uid_statement": uid_statement if kind == "integer" else None,
        })
    return records


def fingerprint(rows: list[dict], catalog: dict) -> str:
    body = [
        [row["kind"], row["keys"],
         [static.canonical_expression(arg) for arg in row["args"]],
         [catalog[k][0] for k in row["keys"]]]
        for row in rows
    ]
    return hashlib.sha256(
        json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def actual_output(rows: list[dict], raw_uid: str) -> list[str]:
    # Evaluate the actual PHP source assignment extracted by tokenization.
    # Numeric test inputs are transported as data, never interpolated as code.
    statement = next(row["uid_statement"] for row in rows
                     if row["kind"] == "integer")
    encoded_input = base64.b64encode(raw_uid.encode("utf-8")).decode("ascii")
    expressions = ",".join(row["args"][0] for row in rows)
    php = (
        "require 'messages/loader.php';"
        "$text=base64_decode('" + encoded_input + "');"
        + statement +
        "echo json_encode([" + expressions +
        "], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"
    )
    return json.loads(subprocess.check_output(
        ["php", "-n", "-r", php], cwd=static.ROOT, text=True
    ))


def verify_rendered_text(rows: list[dict], catalog: dict) -> int:
    # Test formats that demonstrate the actual integer cast, rather than
    # simply initializing $uid to an integer fixture.
    numeric_samples = {
        "0": "0",
        "-1": "-1",
        "1e2": "100",
        "12.5": "12",
        "0007": "7",
        "123456789": "123456789",
        "2147483647": "2147483647",
        "9223372036854775807": "9223372036854775807",
    }
    for raw, normalized in numeric_samples.items():
        rendered = actual_output(rows, raw)
        if len(rendered) != len(rows):
            raise AssertionError("PHP rendered incomplete composed output")
        for row, actual in zip(rows, rendered):
            expected = "".join(catalog[k][0] for k in row["keys"])
            if row["kind"] == "integer":
                expected = expected.replace("{uid}", normalized)
            if actual != expected or static.UNSAFE_HTML_OR_MARKDOWN.search(actual):
                raise AssertionError("discount output is not literal-equivalent")
    return len(numeric_samples)



def verify_mutation_detection(source: str, rows: list[dict], catalog: dict) -> None:
    baseline = fingerprint(rows, catalog)
    first = rows[0]
    position = first["start"]
    closing = first["end"]

    def expect_rejected(label: str, variant: str, expected_count: int) -> None:
        try:
            updated = inventory(variant, catalog)
        except AssertionError as error:
            # The strict argument shape itself rejects changed keyboard/mode.
            if str(error) in ("discount keyboard argument changed",
                              "composed discount prompt lost explicit HTML mode"):
                return
            raise
        if len(updated) != expected_count or fingerprint(updated, catalog) == baseline:
            raise AssertionError(label + ": change escaped composed-message audit")

    expect_rejected("physical removal",
                    source[:position] + source[closing + 1:], len(rows) - 1)
    expect_rejected("block-commented notification",
                    source[:position] + "/*" + source[position:closing + 1] +
                    "*/" + source[closing + 1:], len(rows) - 1)
    one_line = next(row for row in rows if row["kind"] == "integer")
    expect_rejected("line-commented notification",
                    source[:one_line["start"]] + "// " +
                    source[one_line["start"]:], len(rows) - 1)
    segment = source[position:closing + 1]
    if "$cancelKey" not in segment:
        raise AssertionError("missing expected keyboard source")
    expect_rejected("changed keyboard",
                    source[:position] + segment.replace("$cancelKey", "null", 1) +
                    source[closing + 1:], len(rows))
    # Independently reproduced REQUIRED-1 bypasses: all mutations keep the
    # original sendMessage call, so its previous digest alone stays unchanged.
    original_cast = one_line["uid_statement"]
    cast_start = source.rfind(original_cast, 0, one_line["start"])
    if cast_start < 0:
        raise AssertionError("numeric provenance: cast not found in source")

    def expect_provenance_rejected(label: str, variant: str) -> None:
        try:
            inventory(variant, catalog)
        except AssertionError as error:
            if str(error).startswith("numeric provenance:"):
                return
            raise
        raise AssertionError(label + ": changed uid provenance passed the audit")

    # A. A comment contains the old cast but the executable assignment is raw.
    expect_provenance_rejected(
        "commented cast spoof",
        source[:cast_start] + "$uid = $text; // $uid = (int)$text;" +
        source[cast_start + len(original_cast):],
    )
    # B. The source cast exists but the value is reassigned before the send.
    expect_provenance_rejected(
        "string reassignment after cast",
        source[:cast_start + len(original_cast)] +
        "\n    $uid = (string)$text;" +
        source[cast_start + len(original_cast):],
    )
    # C. Another mutation alters the numeric output without changing the
    # notification argument expression or any catalog fragment.
    expect_provenance_rejected(
        "increment after cast",
        source[:cast_start + len(original_cast)] +
        "\n    $uid++;" +
        source[cast_start + len(original_cast):],
    )
    # D/E. Unbraced guards retain the original sendMessage fingerprint,
    # uid token count and brace depth, but can skip the integer cast entirely.
    expect_provenance_rejected(
        "conditional false cast",
        source[:cast_start] + "if (false) " + source[cast_start:],
    )
    expect_provenance_rejected(
        "conditional numeric equality cast",
        source[:cast_start] + "if ($text === '123456789') " +
        source[cast_start:],
    )
    # An unbraced loop is also conditional statement ownership.
    expect_provenance_rejected(
        "conditional while cast",
        source[:cast_start] + "while (false) " + source[cast_start:],
    )
    expect_provenance_rejected(
        "braced guarded cast",
        source[:cast_start] + "if (false) { " + original_cast +
        " }" + source[cast_start + len(original_cast):],
    )
    # A control-flow jump may bypass the cast without changing any uid token.
    expect_provenance_rejected(
        "goto skips cast",
        source[:cast_start] + "goto skipUidCast;\n    " +
        source[cast_start:one_line["start"]] +
        "skipUidCast:\n    " + source[one_line["start"]:],
    )
    # Whitespace and comments do not change the pinned executable token path.
    benign = source[:cast_start] + "/* harmless comment */\n    " + source[cast_start:]
    if fingerprint(inventory(benign, catalog), catalog) != baseline:
        raise AssertionError("numeric provenance: harmless comment invalidated audit")
    moved = source[:position] + "\n \t" + source[position:]
    if fingerprint(inventory(moved, catalog), catalog) != baseline:
        raise AssertionError("line numbers/whitespace invalidated stable digest")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--print-audit", action="store_true")
    opts = parser.parse_args()
    src = (static.ROOT / SOURCE).read_text()
    catalog = static.catalog()
    rows = inventory(src, catalog)
    counts = {
        "composed": sum(row["kind"] == "composed" for row in rows),
        "integer": sum(row["kind"] == "integer" for row in rows)
    }
    if len(rows) != AUDITED_COUNT or counts != {"composed": 9, "integer": 1}:
        raise AssertionError("discount catalog composition inventory drift")
    sample_count = verify_rendered_text(rows, catalog)
    verify_mutation_detection(src, rows, catalog)
    digest = fingerprint(rows, catalog)
    if opts.print_audit:
        print(digest)
        return
    if digest != EXPECTED_SHA256:
        raise AssertionError("reviewed discount expression/catalog fingerprint changed")
    print(json.dumps({
        "complete": True, "status": "PASS", "cases": AUDITED_COUNT,
        "composed": counts["composed"], "integer": counts["integer"],
        "sample_user_ids": sample_count, "audited_exact": True,
        "network_calls": 0, "executable_integer_provenance": True,
        "provenance_mutation_cases": 8
    }))


if __name__ == "__main__":
    main()
