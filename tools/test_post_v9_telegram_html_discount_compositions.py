#!/usr/bin/env python3
"""Prove the audited discount-wizard catalog compositions are HTML-safe.

Only catalog fragments and one integer-coerced userid are eligible. This runs
PHP's real catalog renderer in isolation; it never loads the bot, database or
Telegram transport. The PHP tokenizer recognizes active call sites so disabled
notifications cannot silently satisfy the source contract.
"""
from __future__ import annotations

import argparse
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
            # The replacement is a typed integer, not user-authored Markdown.
            if not re.search(r"\$uid\s*=\s*\(int\)\s*\$text\s*;",
                             source[max(0, start - 850):start]):
                raise AssertionError("userid source lost its integer coercion")
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


def actual_output(rows: list[dict], uid: int) -> list[str]:
    # Only the strict, previously validated catalog expressions enter PHP.
    expressions = ",".join(row["args"][0] for row in rows)
    php = (
        "require 'messages/loader.php';"
        "$uid=" + str(uid) + ";"
        "echo json_encode([" + expressions +
        "], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"
    )
    return json.loads(subprocess.check_output(
        ["php", "-n", "-r", php], cwd=static.ROOT, text=True
    ))


def verify_rendered_text(rows: list[dict], catalog: dict) -> None:
    # In these messages Markdown and HTML are both literal text: there is no
    # Markdown formatting, HTML tag, HTML entity or URL entity to reinterpret.
    for uid in (0, 123456789, 2147483647):
        rendered = actual_output(rows, uid)
        if len(rendered) != len(rows):
            raise AssertionError("PHP rendered incomplete composed output")
        for row, actual in zip(rows, rendered):
            expected = "".join(catalog[k][0] for k in row["keys"])
            if row["kind"] == "integer":
                expected = expected.replace("{uid}", str(uid))
            if actual != expected or static.UNSAFE_HTML_OR_MARKDOWN.search(actual):
                raise AssertionError("discount output is not literal-equivalent")


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
    verify_rendered_text(rows, catalog)
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
        "sample_user_ids": 3, "audited_exact": True, "network_calls": 0
    }))


if __name__ == "__main__":
    main()
