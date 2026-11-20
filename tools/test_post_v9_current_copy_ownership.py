#!/usr/bin/env python3
"""Current-only PHP message ownership audit; source code is never executed.

The standalone root index.php web-login page is separately inventoried pending
browser output-equivalence migration. Telegram/customer/admin/financial PHP
copy is required to use the two existing catalog files; protocol literals,
stored user input and native data are not editable copy.
"""
from __future__ import annotations

import json
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



def unresolved_copy_orphans(groups: dict[str, dict], source: str,
                            dynamic_prefixes: set[str]) -> list[str]:
    # A legacy lookup can index its source-owned array by a finite key chosen
    # in a caller; require those key literals to exist somewhere in current
    # runtime. Nonlegacy copy uses full topic.key names. The only permitted
    # generated keys have the five source-proven prefixes below.
    orphans = []
    for topic, entries in groups.items():
        for key in entries:
            complete = topic + "." + key
            if topic in ("legacy_messages", "legacy_buttons"):
                if re.search(r"""['"]""" + re.escape(key) + r"""['"]""", source):
                    continue
            elif (complete in source or
                  any(complete.startswith(prefix) for prefix in dynamic_prefixes)):
                continue
            orphans.append(complete)
    return sorted(orphans)



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
    combined = "\n".join(sources.values())
    literal, dynamic = classify_keys(combined)
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
    main_keys = set(MAIN_KEY.findall(combined))
    button_keys = set(BUTTON_KEY.findall(combined))
    missing_main = main_keys - set(groups["legacy_messages"])
    check(missing_main == {"start_message", "user_welcome_message",
                           "reached_main_menu"},
          "mainValues_unknown_source:" + str(sorted(missing_main)))
    check(button_keys <= set(groups["legacy_buttons"]),
          "buttonValues_unknown_source")
    orphans = unresolved_copy_orphans(groups, combined, DYNAMIC_PREFIXES)
    check(not orphans, "unused_current_catalog_keys:" + str(orphans[:15]))
    fake_groups = {**groups, "fake_topic": {"ghost_message": "کپی قدیمی"}}
    check(unresolved_copy_orphans(fake_groups, combined, DYNAMIC_PREFIXES) ==
          ["fake_topic.ghost_message"], "orphan_copy_negative")
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
        "root_browser_login_php_literals_pending": 0,
        "standalone_browser_login_included": True,
        "network_calls": 0,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
