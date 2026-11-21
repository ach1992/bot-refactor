#!/usr/bin/env python3
"""Exact current-source Telegram call/argument parity after canonical message lookup.

Compares every executable send/edit/photo/callback call to the accepted
pre-refactor main commit. Comments and quoted lookalikes are not code;
the only permitted normalization is the proven direct replacement of an
old catalog array lookup by its matching immutable message key, plus a
source-real manual receipt function signature that no longer takes a map.
No Telegram, user database, production connection or provider is loaded.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
import subprocess

import package_current
import test_post_v9_telegram_html_callsite_parity as php_args

ROOT = Path(__file__).resolve().parents[1]
BASE = "4e21e18a3ba50897abc6b393ef0c7f58db39bbf9"
EXPECTED = {"sendMessage": 694, "editText": 130, "sendPhoto": 12,
            "answerCallbackQuery": 62}
PHP_TOKENS = r"""
$files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$methods = ['sendmessage'=>'sendMessage', 'edittext'=>'editText',
            'sendphoto'=>'sendPhoto', 'answercallbackquery'=>'answerCallbackQuery'];
$out = [];
foreach ($files as $path => $source) {
    $offset = 0;
    $tokens = token_get_all($source);
    $previous = null;
    $calls = [];
    foreach ($tokens as $index => $token) {
        $word = is_array($token) ? $token[1] : $token;
        $kind = is_array($token) ? $token[0] : null;
        $name = $kind === T_STRING ? ($methods[strtolower($word)] ?? null) : null;
        if ($name !== null && !in_array($previous,
            [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
             T_DOUBLE_COLON], true)) {
            $next = $index + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) &&
                   in_array($tokens[$next][0],
                            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $next++;
            if (($tokens[$next] ?? null) === '(') $calls[] = [$name, $offset];
        }
        $offset += strlen($word);
        if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
            $previous = $kind ?? $word;
    }
    $out[$path] = $calls;
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""
LEGACY = re.compile(r"""\\achbotMessage\(['"]legacy_(messages|buttons)\.([a-z0-9_]+)['"]\)""")
CONTEXT = re.compile(r"""\\achbotCurrentMessage\(['"]([a-z0-9_]+)['"]\)""")
CONTEXT_KEYS = {
    "start_message", "user_welcome_message", "reached_main_menu", "banned",
    "join_channel_message", "send_config_uuid", "agency_request_declined",
    "new_member_joined", "buy_subscription_detail", "buy_much_subscription_detail",
}


def calls(sources: dict[str, str]) -> dict[str, list[tuple[str, list[str]]]]:
    offsets = json.loads(subprocess.check_output(
        ["php", "-r", PHP_TOKENS], cwd=ROOT,
        input=json.dumps(sources, ensure_ascii=False).encode("utf-8")))
    result = {}
    for path, records in offsets.items():
        source = sources[path]
        encoded = source.encode("utf-8")
        values = []
        for method, byte_start in records:
            try:
                start = len(encoded[:byte_start].decode("utf-8"))
                opening = source.index("(", start)
                _, arguments = php_args.parse_arguments(source, opening)
            except (UnicodeError, ValueError, IndexError) as error:
                raise AssertionError("cannot_resolve_telegram_call:" + path) from error
            values.append((method, arguments))
        result[path] = values
    return result


def original_argument(value: str) -> str:
    """Convert only new catalog resolution syntax to its exact old equivalent."""
    text = php_args.canonical_expression(value)
    text = LEGACY.sub(lambda hit:
        "$" + ("mainValues" if hit.group(1) == "messages" else "buttonValues")
        + "['" + hit.group(2) + "']", text)
    text = CONTEXT.sub(lambda hit: "$mainValues['" + hit.group(1) + "']",
                       text)
    text = text.replace(
        "\\achbotMessage('legacy_messages.'.$prompt)", "$mainValues[$prompt]")
    # Only the old first argument was removed from this now map-free helper.
    text = text.replace(
        "achbotManualEvidencePrompt($connection,",
        "achbotManualEvidencePrompt($mainValues,$connection,")
    return text


def compare(before: dict, after: dict) -> dict[str, int]:
    assert set(before) == set(after), "telegram_source_membership_changed"
    counts = dict.fromkeys(EXPECTED, 0)
    for path in sorted(before):
        previous = before[path]
        current = after[path]
        assert len(previous) == len(current), ("telegram_call_count_drift", path)
        for index, ((previous_method, previous_args), (method, args)) in enumerate(
                zip(previous, current, strict=True)):
            assert method == previous_method, ("telegram_method_changed", path, index)
            counts[method] += 1
            expected = [php_args.canonical_expression(arg) for arg in previous_args]
            actual = [original_argument(arg) for arg in args]
            assert expected == actual, ("telegram_wire_argument_changed",
                                        path, index, expected, actual)
    assert counts == EXPECTED, ("telegram_contract_coverage_changed", counts)
    return counts


def main() -> None:
    names = [p for p in package_current.members()
             if p.endswith(".php") and not p.startswith("messages/fa/")]
    old = {name: subprocess.check_output(
        ["git", "show", BASE + ":" + name], cwd=ROOT).decode("utf-8")
        for name in names}
    now = {name: (ROOT / name).read_text() for name in names}
    previous = calls(old)
    current = calls(now)
    total = compare(previous, current)

    assert CONTEXT_KEYS <= set(re.findall(
        r"""\\achbotCurrentMessage\(['"]([a-z_]+)['"]\)""",
        "\n".join(now.values()))), "source_context_catalog_coverage_missing"
    # The negative mutations are deliberately exercised on the *live* source.
    # No suppression, parse-mode weakening or keyboard editing can pass.
    bot = now["bot.php"]
    html = re.search(r"""sendMessage\([^;\n]+,\s*[^;\n]+,\s*['"]HTML['"]\)""", bot)
    assert html is not None, "negative_html_call_missing"
    original = html.group()
    changed = original.replace("'HTML'", "'Markdown'").replace('"HTML"', '"Markdown"')
    assert changed != original, "negative_parse_mode_mutation_inert"
    mutation = dict(now)
    mutation["bot.php"] = bot.replace(original, changed, 1)
    try:
        compare(previous, calls(mutation))
    except AssertionError:
        pass
    else:
        raise AssertionError("parse_mode_drift_not_detected")

    # A new active code path is not equivalent to an ignored PHP comment.
    mutation = dict(now)
    assert bot.startswith("<?php"), "bot_php_entrypoint_changed"
    mutation["bot.php"] = bot.replace("<?php", "<?php\n// sendMessage('quoted inactive code');", 1)
    compare(previous, calls(mutation))
    mutation["bot.php"] = bot.replace("<?php", "<?php\nsendMessage('unexpected live message');", 1)
    try:
        compare(previous, calls(mutation))
    except AssertionError:
        pass
    else:
        raise AssertionError("new_executable_send_not_detected")

    print(json.dumps({"complete": True, "status": "PASS", "baseline": BASE,
                      "calls": total, "cases": sum(total.values()),
                      "source_argument_bytes_preserved": True,
                      "method_keyboard_parse_mode_preserved": True,
                      "negative_fixtures": 3, "network_calls": 0}))


if __name__ == "__main__":
    main()
