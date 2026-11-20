#!/usr/bin/env python3
"""Current-only PHP message ownership audit; no bot/bootstrap/DB/network.

The standalone browser-login copy and source-owned Telegram/admin/financial
text must live in the two existing audience catalogs. An isolated PHP CLI
reads only declarative payment-field metadata and catalog values; runtime
business flows, stored user content and native provider effects are not run.
"""
from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
import re
import subprocess

import package_current

ROOT = Path(__file__).resolve().parents[1]
AUDIENCES = ("messages/fa/user.php", "messages/fa/admin.php")
DYNAMIC_PREFIXES = {
    "manual_payment.api_payment.code_",
    "manual_payment.api_payment.status_",
    "payment_settings.api.",
    "payment_settings.api.field.",
    "payment_settings.api.hint.",
}
RETIRED = (
    "editServerNames", "editDestName", "editSpiderX", "editFlow", "editPFlow",
    "wizwizplanslimit", "wizwizplansinobundid",
)
RETIRED_KEYS = (
    "admin_settings.config.get_plan_details_keys.server_names.649d37195b",
    "admin_settings.config.get_plan_details_keys.dest.1d5e6a1edd",
    "admin_settings.config.get_plan_details_keys.spider_x.3bb7e32507",
    "admin_settings.config.get_plan_details_keys.flow.3b21278178",
    "plans.administration.edit_custom_plan.message.7b314b58c9",
)
KNOWN_NONCOPY = {
    ("settings/jdf.php", "'٫'"),  # PHP date locale decimal separator
    ("src/Finance/GatewayRuleAdmin.php", r"'/[\\s,،]+/u'"),  # separator regex
}
LITERAL = re.compile(r"""(?<![A-Za-z0-9_])\\?achbotMessage(?:Html)?\s*\(\s*(['"])([^'"]+)\1""")
MAIN_KEY = re.compile(r"""\$mainValues\[['"]([^'"]+)['"]\]""")
BUTTON_KEY = re.compile(r"""\$buttonValues\[['"]([^'"]+)['"]\]""")
PERSIAN = re.compile(r"[\u0600-\u06ff]")


def check(value: bool, name: str) -> None:
    if not value:
        raise AssertionError(name)


def loaded_catalog() -> dict[str, dict]:
    raw = subprocess.check_output(
        ["php", "-n", "-r",
         'require "messages/loader.php";echo json_encode(achbotMessageCatalog(),'
         "JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"],
        cwd=ROOT,
    )
    return json.loads(raw)


def assert_no_duplicate_source_keys(path: Path) -> None:
    source = path.read_text()
    topics = re.finditer(
        r"(?ms)^    '([^']+)' => \[\n(.*?)^    \],\n", source
    )
    for topic in topics:
        keys = re.findall(r"^        '([^']+)'\s*=>", topic.group(2), re.M)
        check(len(keys) == len(set(keys)),
              "duplicate_topic_key:" + path.name + ":" + topic.group(1))


def classify_keys(source: str) -> tuple[set[str], set[str]]:
    literal = set()
    dynamic = set()
    for match in LITERAL.finditer(source):
        key = match.group(2)
        if source[match.end():].lstrip().startswith("."):
            dynamic.add(key)
        else:
            literal.add(key)
    return literal, dynamic


def classify_unowned_copy(tokens: list[dict]) -> tuple[list[str], int]:
    violations = []
    standalone_web = 0
    for item in tokens:
        if item.get("kind") not in ("T_CONSTANT_ENCAPSED_STRING", "interpolated"):
            continue
        raw = item.get("raw", "")
        if not PERSIAN.search(raw):
            continue
        path = item["path"]
        if path == "index.php":
            standalone_web += 1
        elif (path, raw) not in KNOWN_NONCOPY:
            violations.append(f"{path}:{item['line']}:{raw[:120]}")
    return violations, standalone_web


def assert_retired_absent(sources: dict[str, str]) -> None:
    for path in ("src/Plans/Administration.php", "src/Admin/Access.php"):
        content = sources[path]
        for marker in RETIRED:
            check(marker not in content, "retired_handler_reintroduced:" + marker)


def assert_bound(literal: set[str], all_keys: set[str]) -> None:
    check(literal <= all_keys,
          "missing_literal_copy:" + str(sorted(literal - all_keys)[:12]))



def php_function(source: str, name: str) -> str:
    match = re.search(r"(?ms)^function " + re.escape(name) +
                      r"\([^;]*\)\s*(?::\s*\??[A-Za-z0-9_\\]+)?\s*\{.*?^\}", source)
    check(match is not None, "unresolved_php_function:" + name)
    return match.group(0)


def php_literals(source: str) -> set[str]:
    return set(re.findall(r"""['"]([a-zA-Z0-9_]+)['"]""", source))


def finite_dynamic_keys(sources: dict[str, str]) -> set[str]:
    """Follow source-proven finite producer domains, never prefix wildcards."""
    api = php_function(sources["src/Finance/ApiPayments.php"],
                       "achbotApiPaymentNotice")
    invalid = re.search(
        r"\$invalidCode\s*=\s*in_array\(\$status,\s*\[([^\]]+)\],\s*true\)", api)
    accepted = re.search(
        r"\$status\s*=\s*in_array\(\$status,\s*\[([^\]]+)\],\s*true\)"
        r"\s*\?\s*\$status\s*:\s*'review'", api)
    check(invalid is not None and accepted is not None,
          "manual_status_normalization_changed")
    check("achbotMessage('manual_payment.api_payment.code_'.$status)" in api and
          "achbotMessage('manual_payment.api_payment.status_'.$status)" in api,
          "manual_status_catalog_dispatch_changed")
    invalid_values = php_literals(invalid.group(1))
    accepted_values = php_literals(accepted.group(1))
    check(invalid_values < accepted_values and "review" in accepted_values,
          "manual_status_domain_invalid")
    keys = {"manual_payment.api_payment.code_" + value
            for value in invalid_values}
    keys |= {"manual_payment.api_payment.status_" + value
             for value in accepted_values - invalid_values}

    admin = sources["src/Finance/PaymentApiAdmin.php"]
    rows = php_function(admin, "achbotPaymentApiAdminRows")
    page = php_function(admin, "achbotPaymentApiAdminPage")
    prompt = php_function(admin, "achbotPaymentApiAdminPrompt")
    for fn in (rows, page):
        check(re.search(r"\$suffix\s*=\s*\$sms\s*\?\s*'sms'\s*:\s*'gift'", fn)
              is not None, "payment_api_method_suffix_not_finite")
        check("bank_transfer" in fn and "gift_card" in fn,
              "payment_api_method_guard_changed")
    suffixes = re.findall(
        r"""achbotMessage\(\s*'payment_settings\.api\.'\s*\.\s*\$suffix"""
        r"""\s*\.\s*'_([a-z_]+)'""", rows + page)
    check(len(suffixes) == 4 and len(set(suffixes)) == 4,
          "payment_api_dynamic_suffix_inventory_changed")
    keys |= {"payment_settings.api." + method + "_" + suffix
             for method in ("sms", "gift") for suffix in suffixes}

    # Both functions are source-only declarative/validation code. Read the
    # actual field map and schema-supported fields, not unrelated identifiers.
    check("achbotPaymentApiAdminIsField($field)" in page and
          "achbotPaymentApiAdminIsField($field)" in prompt,
          "payment_api_field_reachability_guard_missing")
    check("achbotMessage('payment_settings.api.field.'.$urlField)" in page and
          "achbotMessage('payment_settings.api.field.'.$field)" in page and
          "achbotMessage('payment_settings.api.field.'.$field)" in prompt and
          "achbotMessage('payment_settings.api.hint.'.$field)" in prompt,
          "payment_api_field_or_hint_dispatch_changed")
    php = ('require "src/Finance/PaymentApiAdmin.php";'
           'echo json_encode([achbotPaymentApiAdminFieldMap(),'
           'achbotPaymentEvidenceSettingFields()],JSON_THROW_ON_ERROR);')
    field_map, eligible = json.loads(subprocess.check_output(
        ["php", "-n", "-r", php], cwd=ROOT
    ))
    fields = set(field_map) & set(eligible)
    check(fields and all(meta[0] in ("bank_transfer", "gift_card")
                         for key, meta in field_map.items() if key in fields),
          "payment_api_field_mapping_invalid")
    keys |= {"payment_settings.api." + prefix + field
             for prefix in ("field.", "hint.") for field in fields}
    return keys


def indirect_legacy_keys(sources: dict[str, str],
                         scans: dict[str, dict] | None = None) -> dict[str, set[str]]:
    """Resolve current dynamic/aliased array consumers from actual callsites."""
    if scans is None:
        scans = source_call_inventory(sources)
    config = php_function(scans["config.php"]["clean"], "getAdminKeys")
    mapping = re.search(r"\$rows\s*=\s*\[(.*?)\];\s*\$keyboard", config, re.S)
    check(mapping is not None and "$buttonValues[$key]" in config,
          "admin_button_menu_key_binding_changed")
    admin_buttons = set(re.findall(
        r"""\[\s*'([a-z_]+)'\s*,\s*'[^']+'\s*\]""", mapping.group(1)))
    admin_buttons -= {"__review", "gateway_rules_settings"}
    check(admin_buttons, "admin_button_menu_empty")

    wallet = sources["src/Finance/Routes/AdminWallet.php"]
    modes = re.search(r"\$achbotWalletModes\s*=\s*\[(.*?)\];", wallet, re.S)
    check(modes is not None and "sendMessage($mainValues[$prompt])" in wallet,
          "admin_wallet_prompt_binding_changed")
    wallet_prompts = set(re.findall(
        r"""'[^']+'\s*=>\s*\[\s*'[^']+'\s*,\s*'[^']+'\s*,\s*'([^']+)'\s*\]""",
        modes.group(1)))
    check(wallet_prompts, "admin_wallet_prompt_set_empty")

    receipts = sources["src/Finance/ReceiptHelpers.php"]
    manual = php_function(receipts, "achbotManualReceiptPrompt")
    match = re.search(r"\$key\s*=\s*match\s*\(\s*\$method\s*\)\s*\{(.*?)\};",
                      manual, re.S)
    check(match is not None and "return $mainValues[$key]" in manual,
          "manual_receipt_prompt_binding_changed")
    receipt_prompts = set(re.findall(
        r"""=>\s*'([a-z_]+)'""", match.group(1)))
    check(receipt_prompts, "manual_receipt_prompt_set_empty")
    templates = re.findall(
        r"""\$mainValues\[\s*\$method\s*===\s*'[^']+'\s*\?\s*'([^']+)'\s*"""
        r""":\s*'([^']+)'\s*\]""", receipts)
    check(len(templates) == 2, "manual_receipt_invoice_templates_changed")
    for pair in templates:
        receipt_prompts.update(pair)

    # Every current dynamic legacy index must be accounted for. The two
    # indexes in Presentation/Text.php are assignments in the renderer,
    # not read consumers of a catalog entry.
    actual = []
    for path, code in sources.items():
        for m in re.finditer(
            r"""\$(mainValues|buttonValues)\[\s*(?!['"])([^\]]+)\]""",
            code, re.S,
        ):
            actual.append((path, m.group(1), " ".join(m.group(2).split())))
    expected = [
        ("config.php", "buttonValues", "$key"),
        ("src/Finance/Routes/AdminWallet.php", "mainValues", "$prompt"),
        ("src/Finance/ReceiptHelpers.php", "mainValues", "$key"),
        ("src/Finance/ReceiptHelpers.php", "mainValues",
         "$method === 'manual_trx' ? 'brw_cart_to_cart_trx' : 'brw_cart_to_cart_usdt'"),
        ("src/Finance/ReceiptHelpers.php", "mainValues",
         "$method === 'gift_card' ? 'brw_cart_to_cart_gift' : 'brw_cart_to_cart_detail'"),
        ("src/Presentation/Text.php", "mainValues", "$key"),
        ("src/Presentation/Text.php", "mainValues", "$key"),
    ]
    check(sorted(actual) == sorted(expected),
          "unclassified_indirect_catalog_indexes:" +
          str(sorted(set(actual) ^ set(expected))[:8]))
    render = sources["src/Presentation/Text.php"]
    check("$mainValues[$key] = $value;" in render and
          "$mainValues[$key] = replacePlaceholders(" in render,
          "presentation_index_is_no_longer_write_only")
    detail = scans["src/Subscription/AccountDetails.php"]["clean"]
    status = php_function(detail, "getOrderStatusText")
    external = scans["src/Subscription/ExternalAccounts.php"]["clean"]
    check("array $buttons" in status and
          detail.count("getOrderStatusText($traffic,$serverType,$buttonValues") == 2 and
          re.search(r"getOrderStatusText\(\s*\$observation.{0,160}?\$buttonValues",
                    external, re.S) is not None,
          "subscription_status_catalog_alias_binding_changed")
    status_buttons = set(re.findall(
        r"""\$buttons\[\s*['"]([a-z_]+)['"]\s*\]""", status))
    check({"active", "deactive"} <= status_buttons,
          "subscription_status_button_alias_lost")
    return {"legacy_buttons": admin_buttons | status_buttons,
            "legacy_messages": wallet_prompts | receipt_prompts}


@lru_cache(maxsize=4)
def _scan_php_calls(payload: bytes) -> dict[str, dict]:
    # PHP's tokenizer distinguishes executable calls from comments and strings.
    return json.loads(subprocess.check_output(
        ["php", "tools/catalog_message_calls.php"], input=payload, cwd=ROOT))


def source_call_inventory(sources: dict[str, str]) -> dict[str, dict]:
    payload = json.dumps(sources, ensure_ascii=False, sort_keys=True).encode()
    scans = _scan_php_calls(payload)
    check(set(scans) == set(sources), "source_call_inventory_incomplete")
    expected_indirect = {
        ("bot.php", "$label"),
        ("messages/loader.php", "$key"),
        ("src/Finance/ReceiptHelpers.php", "$codeKey"),
        ("src/Finance/ReceiptHelpers.php", "$key"),
        ("src/Finance/Routes/AutomaticGatewayReview.php", "$key"),
        ("src/Finance/WalletAddonNotices.php", "$button"),
        ("src/Subscription/AccountDeletion.php", "$key"),
        ("src/Subscription/OrderManagement.php", "$key"),
    }
    actual_indirect = {(path, var) for path, row in scans.items()
                       for var in row["variables"]}
    check(actual_indirect == expected_indirect,
          "untraced_variable_message_sinks:" +
          str(sorted(actual_indirect ^ expected_indirect)))
    return scans


QUALIFIED = re.compile(r"""['"]([a-z][a-z0-9_]*\.[A-Za-z0-9_.]+)['"]""")


def finite_indirect_qualified_keys(scans: dict[str, dict]) -> set[str]:
    """Only values produced for actual achbotMessage($variable) consumers."""
    result = set()

    def assigned(code: str, variable: str, expected: int = 1) -> set[str]:
        expressions = re.findall(
            r"\$" + re.escape(variable) + r"\s*=\s*(.*?);", code, re.S)
        check(len(expressions) == expected,
              "qualified_key_producer_changed:" + variable)
        values = set().union(*(set(QUALIFIED.findall(expr))
                               for expr in expressions))
        check(bool(values), "qualified_key_producer_empty:" + variable)
        return values

    receipts = scans["src/Finance/ReceiptHelpers.php"]["clean"]
    for fn, var, consumer in (
        ("achbotManualGiftReviewText", "codeKey", "achbotMessageHtml($codeKey,"),
        ("achbotManualGiftRetryMessage", "key", "achbotMessage($key)"),
    ):
        body = php_function(receipts, fn)
        check(consumer in body, "qualified_receipt_key_sink_changed:" + fn)
        result.update(assigned(body, var))

    deletion = scans["src/Subscription/AccountDeletion.php"]["clean"]
    check(deletion.count("alert(\\achbotMessage($key),true)") == 2,
          "qualified_delete_key_sink_changed")
    matches = re.findall(
        r"\$key\s*=\s*match\(\$deleteOutcome\)\s*\{.*?\}\s*;",
        deletion, re.S)
    check(len(matches) == 2, "qualified_delete_producers_changed")
    for expression in matches:
        result.update(QUALIFIED.findall(expression))

    management = scans["src/Subscription/OrderManagement.php"]["clean"]
    check("alert(\\achbotMessage($key),true)" in management,
          "qualified_management_key_sink_changed")
    producer = re.search(r"\$key\s*=\s*\$correct\s*&&.*?;", management, re.S)
    check(producer is not None, "qualified_management_producer_changed")
    result.update(QUALIFIED.findall(producer.group()))

    wallet = scans["src/Finance/WalletAddonNotices.php"]["clean"]
    check("achbotMessage($button)" in wallet,
          "qualified_wallet_button_sink_changed")
    producer = re.search(r"\$button\s*=\s*\$kind\s*===.*?;", wallet, re.S)
    check(producer is not None, "qualified_wallet_button_producer_changed")
    result.update(QUALIFIED.findall(producer.group()))

    gateway = scans["src/Finance/Routes/AutomaticGatewayReview.php"]["clean"]
    check("foreach ($optional as [$key,$param,$value])" in gateway and
          "achbotMessageHtml($key," in gateway and
          "$gatewayFitCase($body,$optional,$footer)" in gateway,
          "qualified_gateway_optional_sink_changed")
    optional = re.search(r"\$optional\s*=\s*\[(.*?)\];", gateway, re.S)
    check(optional is not None, "qualified_gateway_optional_producer_changed")
    result.update(re.findall(
        r"""\[\s*['"]([a-z][a-z0-9_]*\.[A-Za-z0-9_.]+)['"]\s*,""",
        optional.group(1)))
    check({"automatic_gateway_review.plan",
           "payment.api_payment.code_used_retry",
           "subscription.account_deletion.no_dont_delete.alert.9347ccc8b0",
           "subscription.service_plan_correction.get_only"} <= result,
          "qualified_indirect_source_reachability_lost")
    return result


def unresolved_copy_orphans(groups: dict[str, dict],
                            sources: dict[str, str]) -> list[str]:
    scans = source_call_inventory(sources)
    finite = finite_dynamic_keys(sources)
    indirect = indirect_legacy_keys(sources, scans)
    qualified = set().union(*(set(row["literal"]) for row in scans.values()))
    qualified.update(finite_indirect_qualified_keys(scans))
    direct = {
        "legacy_messages": set().union(*(set(row["main_indexes"]) for row in scans.values())),
        "legacy_buttons": set().union(*(set(row["button_indexes"]) for row in scans.values())),
    }
    orphans = []
    for topic, entries in groups.items():
        for key in entries:
            if topic in direct:
                used = key in direct[topic] or key in indirect[topic]
            else:
                name = topic + "." + key
                used = name in qualified or name in finite
            if not used:
                orphans.append(topic + "." + key)
    return sorted(orphans)


def verify_live_subscription_status_labels(sources: dict[str, str]) -> int:
    """Run the real status renderer with the loaded catalog; no config/DB/HTTP."""
    detail = sources["src/Subscription/AccountDetails.php"]
    check(detail.count("getOrderStatusText($traffic,$serverType,$buttonValues") == 2,
          "customer_admin_status_callsite_changed")
    script = r"""
    error_reporting(E_ALL);
    set_error_handler(static function(int $errno,string $message): never {
        throw new ErrorException($message,0,$errno);
    });
    require "messages/loader.php";
    require "src/Subscription/AccountDetails.php";
    $buttons=achbotMessages('legacy_buttons');
    $expected=['active'=>'🟢 فعال','disabled'=>'🔴 غیرفعال'];
    $passes=0;
    foreach (['marzban','pasarguard'] as $provider) {
        foreach ($expected as $status=>$label) {
            if (!array_key_exists($status==='active'?'active':'deactive',$buttons))
                throw new RuntimeException('catalog_subscription_label_missing:'.$status);
            $traffic=['found'=>true,'uncertain'=>false,'status'=>$status];
            $actual=\AchBot\Subscription\AccountDetails\getOrderStatusText(
                $traffic,$provider,$buttons);
            if ($actual!==$label)
                throw new RuntimeException('catalog_subscription_label_changed:'.$status);
            $passes++;
        }
    }
    echo json_encode(['passes'=>$passes],JSON_THROW_ON_ERROR);
    """
    proof=json.loads(subprocess.check_output(
        ["php", "-n", "-r", script], cwd=ROOT))
    check(proof["passes"] == 4, "source_real_subscription_status_labels_failed")
    return proof["passes"]


def main() -> None:
    check(sorted(path.relative_to(ROOT).as_posix() for path in
                 (ROOT / "messages/fa").glob("*.php")) == sorted(AUDIENCES),
          "two_editable_audience_files")
    for file in AUDIENCES:
        assert_no_duplicate_source_keys(ROOT / file)
    groups = loaded_catalog()
    all_keys = {topic + "." + key for topic, entries in groups.items()
                for key in entries}
    sources = {
        file: (ROOT / file).read_text()
        for file in package_current.members()
        if file.endswith(".php") and file not in AUDIENCES
    }
    scans = source_call_inventory(sources)
    literal = set().union(*(set(row["literal"]) for row in scans.values()))
    dynamic = set().union(*(set(row["dynamic"]) for row in scans.values()))
    assert_bound(literal, all_keys)
    # These source-owned administrative topics are fully statically bound;
    # a new orphan here should be treated as retired rather than stockpiled.
    for topic in ("admin_settings", "plans"):
        orphans = {topic + "." + key for key in groups[topic]} - literal
        check(not orphans, "orphan_static_catalog:" + str(sorted(orphans)[:12]))
    check(dynamic == DYNAMIC_PREFIXES, "unexpected_dynamic_copy_key:" +
          str(sorted(dynamic ^ DYNAMIC_PREFIXES)))
    check(all(any(key.startswith(prefix) for key in all_keys)
              for prefix in DYNAMIC_PREFIXES), "dynamic_prefix_no_catalog_entries")
    main_keys = set().union(*(set(row["main_indexes"]) for row in scans.values()))
    button_keys = set().union(*(set(row["button_indexes"]) for row in scans.values()))
    missing_main = main_keys - set(groups["legacy_messages"])
    check(missing_main == {"start_message", "user_welcome_message",
                           "reached_main_menu"},
          "mainValues_unknown_source:" + str(sorted(missing_main)))
    check(button_keys <= set(groups["legacy_buttons"]),
          "buttonValues_unknown_source")
    finite_proof = finite_dynamic_keys(sources)
    check({"manual_payment.api_payment.status_review",
           "manual_payment.api_payment.code_used",
           "payment_settings.api.sms_verify",
           "payment_settings.api.gift_guidance",
           "payment_settings.api.field.smsApiUrl",
           "payment_settings.api.hint.giftApiSecret"} <= finite_proof,
          "legitimate_dynamic_source_provenance_lost")
    legacy_proof = indirect_legacy_keys(sources, scans)
    check({"increase_wallet", "create_account", "active", "deactive"} <=
          legacy_proof["legacy_buttons"] and
          {"enter_increase_amount", "please_send_usdt_image",
           "brw_cart_to_cart_trx"} <= legacy_proof["legacy_messages"],
          "legitimate_indirect_source_provenance_lost")
    orphans = unresolved_copy_orphans(groups, sources)
    check(not orphans, "unused_current_catalog_keys:" + str(orphans[:15]))
    real_status_cases = verify_live_subscription_status_labels(sources)
    fake_groups = {**groups, "fake_topic": {"ghost_message": "کپی قدیمی"}}
    check(unresolved_copy_orphans(fake_groups, sources) ==
          ["fake_topic.ghost_message"], "orphan_copy_negative")
    # The previous auditor treated arbitrary keys under accepted prefixes as
    # used and confused unrelated payment-method/SQL field names with UI copy.
    prefix_probe = {**groups, "payment_settings": {
        **groups["payment_settings"],
        "api.zz_fake_never_referenced_9271": "موجود نیست"}}
    check(unresolved_copy_orphans(prefix_probe, sources) ==
          ["payment_settings.api.zz_fake_never_referenced_9271"],
          "dynamic_prefix_orphan_negative")
    alias_probe = {**groups, "legacy_buttons": {
        **groups["legacy_buttons"], "spam_info": "موجود نیست"}}
    check(unresolved_copy_orphans(alias_probe, sources) ==
          ["legacy_buttons.spam_info"], "unrelated_alias_orphan_negative")
    restored_monitoring = {**groups, "manual_payment": {
        **groups["manual_payment"],
        "api_payment.status_monitoring": "تست"}}
    expected_orphan = ["manual_payment.api_payment.status_monitoring"]
    check(unresolved_copy_orphans(restored_monitoring, sources) == expected_orphan,
          "qualified_orphan_current_negative")
    for injected in (
        "// unrelated: 'manual_payment.api_payment.status_monitoring'\n",
        "$unused = 'manual_payment.api_payment.status_monitoring';\n",
    ):
        unrelated = dict(sources)
        unrelated["src/Finance/ReceiptHelpers.php"] += "\n" + injected
        check(unresolved_copy_orphans(restored_monitoring, unrelated) == expected_orphan,
              "unrelated_qualified_reference_false_negative")
    assert_retired_absent(sources)
    check(not (set(RETIRED_KEYS) & all_keys), "retired_text_reintroduced")
    plan_menu = sources["config.php"].split("function getPlanDetailsKeys(", 1)[1].split(
        "// BEGIN create user and admin get order detail", 1
    )[0]
    check("marzbanHostSettings" in plan_menu and "pgPlanGroups" in plan_menu,
          "native_plan_selector_missing")
    paths = sorted(sources)
    tokens = json.loads(subprocess.check_output(
        ["php", "tools/catalog_tokens.php", "--all"], cwd=ROOT,
        input=json.dumps(paths).encode(),
    ))
    exceptions, web_literal_count = classify_unowned_copy(tokens)
    check(not exceptions, "uncatalogued_owned_PHP_copy:" + str(exceptions[:8]))
    # Verify the gate is sensitive to introduced copy/callback/missing-key.
    fake = [{"kind": "T_CONSTANT_ENCAPSED_STRING", "path": "src/Plans/Administration.php",
             "line": 1, "raw": "'متن خارج از کاتالوگ'"}]
    check(len(classify_unowned_copy(fake)[0]) == 1, "hardcoded_copy_negative")
    try:
        assert_bound(literal | {"bot.nonexistent_test_key"}, all_keys)
    except AssertionError:
        pass
    else:
        raise AssertionError("missing_key_negative_not_rejected")
    mutated = dict(sources)
    mutated["src/Plans/Administration.php"] += "\neditServerNames42\n"
    try:
        assert_retired_absent(mutated)
    except AssertionError:
        pass
    else:
        raise AssertionError("retired_handler_negative_not_rejected")
    check(web_literal_count == 0, "uncatalogued_standalone_web_copy")
    check(not PERSIAN.search(sources["index.php"]),
          "index_inline_HTML_or_JS_copy_outside_catalog")
    check('placeholder="example@email.com"' not in sources["index.php"] and
          '<div class="logo">A</div>' not in sources["index.php"],
          "browser_ASCII_copy_not_catalogued")
    check({"web_login.page_title", "web_login.action_unknown",
           "web_login.email_example", "web_login.logo"} <= literal,
          "web_page_catalog_binding_missing")
    print(json.dumps({
        "complete": True, "status": "PASS",
        "scope": "current_bot_and_financial_php_source_owned_copy",
        "source_runtime_files": len(paths),
        "catalog_topics": len(groups),
        "catalog_entries": len(all_keys),
        "resolved_static_calls": len(literal),
        "dynamic_prefixes": len(dynamic),
        "legacy_main_keys": len(main_keys),
        "legacy_button_keys": len(button_keys),
        "retired_route_markers": len(RETIRED),
        "uncatalogued_owned_php_literals": len(exceptions),
        "unreferenced_current_catalog_keys": len(orphans),
        "source_real_subscription_status_cases": real_status_cases,
        "root_browser_login_php_literals_pending": 0,
        "standalone_browser_login_included": True,
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
