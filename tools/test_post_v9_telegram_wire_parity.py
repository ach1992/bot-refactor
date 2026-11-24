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

import hashlib
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
# Intentional parse-only changes to source-owned, markup-free legacy catalog
# sends. All other pre-refactor wire arguments retain exact comparison.
LEGACY_HTML_TRANSITIONS = 74
LEGACY_HTML_AUDIT = "16eed331aeb2e2fd8c4133fe03d3c0d8318761cd371ebf24d6a073852136009b"
# Wallet exception operator callbacks form an additive exact-hash candidate
# for independent review; this source audit does not grant approval.
# Every preexisting wire call retains its baseline arguments, order and modes.
WALLET_UI_PATH = "src/Finance/Routes/AutomaticGatewayReview.php"
WALLET_UI_START = 6
WALLET_UI_NEW_CALLS = 25
WALLET_UI_SHA256 = "73bc45620d974f28e58ddbc4a94be6abfd7ffac6df8ffe6d0b7801cf932ead4b"
# The original-Wallet Archive callback adds five more source-owned
# callsites AFTER the existing 25-call review section. Pin its exact
# normalized arguments separately; never turn the historical parity
# assertion into a wildcard or a new unreviewed Telegram route.
WALLET_ARCHIVE_UI_START = 14
WALLET_ARCHIVE_UI_NEW_CALLS = 5
WALLET_ARCHIVE_UI_SHA256 = "66fa7313965c06b22e278136a88def18b19cefa9feb6ee55380a189653dfeade"
NEW_RUNTIME_WITHOUT_TELEGRAM = frozenset({
    "src/Finance/WalletRenewalRecovery.php",
    "src/Finance/WalletOriginalRecovery.php",
    "src/Wallet/Ledger.php",
    "src/Finance/ManualReceiptIntent.php",
    "src/Finance/PaymentCredit.php",
    "src/Finance/ReceiptMethods.php",
    "src/Finance/HostedMethods.php",
    "src/Finance/HostedReceipt.php",
    "src/Finance/ManualReceipt.php",
    "src/Finance/ServiceDelivery.php",
    "src/Finance/DeliveryParts.php",
    "src/Presentation/MainMenu.php",
    "src/Finance/ServicePurchaseReferral.php",
    "src/Finance/ServiceReports.php",
    "src/Finance/ReceiptReports.php",
    "src/Finance/FollowupAttempts.php",
    "src/Finance/FundingCheckout.php",
    "src/Services/Funding.php",
    "src/Services/Execution.php",
    "src/Panel/PasarGuardServiceExecution.php",
    "src/Panel/PasarGuardServiceEdit.php",
    "src/Services/EditFinalization.php",
    "src/Services/AutomaticRenewal.php",
    "src/Services/Ownership.php",
    "src/Panel/AccountNaming.php",
    "src/Panel/PasarGuardServiceTarget.php",
})
LEGACY_DIRECT = re.compile(
    r"""\\achbotMessage\(['"]legacy_messages\.([a-z0-9_]+)['"]\)\Z"""
)
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


def intended_legacy_html_change(
    path: str, index: int, previous_args: list[str], args: list[str],
    catalog: dict,
) -> list | None:
    """Admit only a formerly implicit Markdown -> HTML literal-catalog send.

    The PHP-resolved catalog text must contain no meaningful Markdown/HTML
    delimiters or parameters. Original text/keyboard expressions and recipient
    stay byte-equivalent after canonical catalog-lookup relocation. Any new
    dynamic template, Markdown syntax, changed keyboard or parse mode fails.
    """
    if len(previous_args) not in (1, 2) or len(args) != 3:
        return None
    first = php_args.canonical_expression(args[0])
    match = LEGACY_DIRECT.fullmatch(first)
    if not match or php_args.canonical_expression(args[2]) not in ("'HTML'", '"HTML"'):
        return None
    key = match.group(1)
    catalog_key = 'legacy_messages.' + key
    if catalog_key not in catalog or not php_args.eligible(*catalog[catalog_key]):
        return None
    expected = [php_args.canonical_expression(arg) for arg in previous_args]
    actual = [original_argument(arg) for arg in args]
    if expected[0] != "$mainValues['" + key + "']":
        return None
    if len(previous_args) == 1:
        if actual[:2] != [expected[0], 'null']:
            return None
    elif actual[:2] != expected:
        return None
    return [path, index, key, len(previous_args)]


def compare(before: dict, after: dict) -> dict[str, int]:
    assert set(before) == set(after), "telegram_source_membership_changed"
    counts = dict.fromkeys(EXPECTED, 0)
    catalog = php_args.catalog()
    transitions = []
    wallet_ui_audited = False
    for path in sorted(before):
        previous = before[path]
        current = after[path]
        if path == WALLET_UI_PATH:
            assert len(current) == (len(previous) + WALLET_UI_NEW_CALLS
                                    + WALLET_ARCHIVE_UI_NEW_CALLS), (
                "wallet_operator_telegram_call_count_drift", len(current), len(previous))
            introduced = current[WALLET_UI_START:WALLET_UI_START+WALLET_UI_NEW_CALLS]
            audit = [[method, [original_argument(arg) for arg in args]]
                     for method, args in introduced]
            actual_hash = hashlib.sha256(json.dumps(audit, ensure_ascii=False,
                separators=(',', ':')).encode('utf-8')).hexdigest()
            assert actual_hash == WALLET_UI_SHA256, (
                "wallet_operator_telegram_calls_unreviewed", actual_hash)
            current = (current[:WALLET_UI_START] +
                       current[WALLET_UI_START+WALLET_UI_NEW_CALLS:])
            archive = current[WALLET_ARCHIVE_UI_START:
                              WALLET_ARCHIVE_UI_START+WALLET_ARCHIVE_UI_NEW_CALLS]
            archive_audit = [[method, [original_argument(arg) for arg in args]]
                             for method,args in archive]
            archive_hash = hashlib.sha256(json.dumps(archive_audit, ensure_ascii=False,
                separators=(',', ':')).encode('utf-8')).hexdigest()
            assert archive_hash == WALLET_ARCHIVE_UI_SHA256, (
                "wallet_archive_telegram_calls_unreviewed", archive_hash)
            current = (current[:WALLET_ARCHIVE_UI_START] +
                       current[WALLET_ARCHIVE_UI_START+WALLET_ARCHIVE_UI_NEW_CALLS:])
            wallet_ui_audited = True
        assert len(previous) == len(current), ("telegram_call_count_drift", path)
        for index, ((previous_method, previous_args), (method, args)) in enumerate(
                zip(previous, current, strict=True)):
            assert method == previous_method, ("telegram_method_changed", path, index)
            counts[method] += 1
            expected = [php_args.canonical_expression(arg) for arg in previous_args]
            actual = [original_argument(arg) for arg in args]
            if expected != actual:
                # The old QR helper's text loop now freezes the same text,
                # last-part keyboard and HTML mode into the original packet.
                # Source-real multipart tests assert the exact emitted wire;
                # keep this exception strictly on this one extracted call.
                if (path == "src/Subscription/Delivery.php" and method == "sendMessage"
                    and expected == ['$part', '$index===count($parts)-1?$keys:null', '"HTML"', '$uid']
                    and actual == ['$text', '$keys', '$mode', '$uid']):
                    continue
                change = (intended_legacy_html_change(path, index, previous_args, args, catalog)
                          if method == 'sendMessage' else None)
                assert change is not None, ("telegram_wire_argument_changed",
                                            path, index, expected, actual)
                transitions.append(change)
    assert wallet_ui_audited, "wallet_operator_additive_wire_audit_not_exercised"
    assert counts == EXPECTED, ("telegram_contract_coverage_changed", counts)
    digest = hashlib.sha256(json.dumps(transitions, ensure_ascii=False,
        separators=(',', ':')).encode('utf-8')).hexdigest()
    assert len(transitions) == LEGACY_HTML_TRANSITIONS and digest == LEGACY_HTML_AUDIT, (
        'scoped_legacy_html_audit', len(transitions), digest)
    return counts


def main() -> None:
    names = [p for p in package_current.members()
             if p.endswith(".php") and not p.startswith("messages/fa/")]
    original_names = set(subprocess.check_output(
        ["git", "ls-tree", "-r", "--name-only", BASE, "--"], cwd=ROOT,
        text=True).splitlines())
    new_names = set(names) - original_names
    assert new_names == NEW_RUNTIME_WITHOUT_TELEGRAM, (
        "unreviewed_new_runtime_file", sorted(new_names))
    new_source = {name: (ROOT / name).read_text() for name in new_names}
    assert all(not values for values in calls(new_source).values()), (
        "new_financial_recovery_module_must_not_send_telegram")
    common_names = [name for name in names if name in original_names]
    old = {name: subprocess.check_output(
        ["git", "show", BASE + ":" + name], cwd=ROOT).decode("utf-8")
        for name in common_names}
    now = {name: (ROOT / name).read_text() for name in common_names}
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

    # A scoped legacy-catalog HTML conversion must fail if its explicit mode
    # is removed or changed, even when its text and keyboard remain identical.
    legacy = "sendMessage(\\achbotMessage('legacy_messages.bot_is_updating'), null, 'HTML')"
    assert legacy in bot, "scoped_legacy_html_negative_fixture_missing"
    mutation = dict(now)
    mutation["bot.php"] = bot.replace(legacy, legacy.replace("'HTML'", "'MarkDown'"), 1)
    try:
        compare(previous, calls(mutation))
    except AssertionError:
        pass
    else:
        raise AssertionError("scoped_legacy_html_mode_drift_not_detected")

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
                      "message_keyboard_arguments_preserved": True,
                      "unchanged_parse_modes_outside_scoped_html": True,
                      "scoped_legacy_html_transitions": LEGACY_HTML_TRANSITIONS,
                      "scoped_legacy_html_audit": LEGACY_HTML_AUDIT,
                      "negative_fixtures": 4, "network_calls": 0}))


if __name__ == "__main__":
    main()
