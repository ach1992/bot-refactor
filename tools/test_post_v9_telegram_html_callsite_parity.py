#!/usr/bin/env python3
"""Guard audited static Telegram HTML producers without contacting Telegram.

A source-level contract, not a PHP/Telegram parser: only calls whose first
argument is a direct zero-parameter achbotMessage('catalog.key') are in scope.
Other dynamic text, persisted broadcasts, templates and legacy Markdown
messages deliberately retain their existing parse modes.
"""
from __future__ import annotations

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
)
DIRECT_SEND = re.compile(
    r"""(?<![A-Za-z0-9_])sendMessage\s*\(\s*\\?achbotMessage\s*\(\s*(['"])([^'"]+)\1\s*\)""",
)
DIRECT_FIRST_ARG = re.compile(r"""\\?achbotMessage\s*\(\s*(['"])([^'"]+)\1\s*\)\Z""")
UNSAFE_HTML_OR_MARKDOWN = re.compile(r"[<>&*_`[\\]")
HTML = re.compile(r"""\A(['"])HTML\1\Z""", re.I)
MARKDOWN = re.compile(r"""\A(['"])MarkDown\1\Z""", re.I)


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


def inventory(source: str, data: dict[str, tuple[str, bool]]):
    for hit in DIRECT_SEND.finditer(source):
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


def main() -> None:
    data = catalog()
    statistics: dict[str, dict[str, int]] = {}
    failures: list[str] = []
    for file in TARGETS:
        counts = {"eligible": 0, "html": 0, "legacy": 0, "dynamic_or_literal": 0}
        for name, _, arguments, line in inventory((ROOT / file).read_text(), data):
            counts["eligible"] += 1
            if len(arguments) >= 3 and HTML.fullmatch(arguments[2]):
                counts["html"] += 1
            elif len(arguments) <= 2 or (len(arguments) >= 3 and MARKDOWN.fullmatch(arguments[2])):
                counts["legacy"] += 1
                failures.append(f"{file}:{line}: {name}: no explicit HTML mode")
            else:
                counts["dynamic_or_literal"] += 1
                # An explicit non-HTML parse mode is a caller-owned contract.
        statistics[file] = counts
    if failures:
        print("\n".join(failures[:40]), file=sys.stderr)
        raise AssertionError(f"{len(failures)} plain catalog sends still use legacy Markdown")
    if sum(c["html"] for c in statistics.values()) < 100:
        raise AssertionError("audited admin/plan HTML producer coverage unexpectedly shrank")
    print(json.dumps({
        "complete": True,
        "status": "PASS",
        "files": statistics,
        "html_verified": sum(c["html"] for c in statistics.values()),
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
