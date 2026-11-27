#!/usr/bin/env python3
"""Exact direct Telegram call/argument comparison against accepted Stable.

Only individually enumerated source deltas are allowed; the service execution
suite independently checks the new durable packets at the real dispatch boundary.
This is a candidate contract for independent review, not review approval.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import subprocess
from collections import Counter

import package_current
import test_post_v9_telegram_html_callsite_parity as php_args

ROOT = Path(__file__).resolve().parents[1]
BASE = "484c80e6ee030e8656da96edc3000311d10327b5"
AUDIT = json.loads((ROOT / "tools/wallet_first_wire_contract.json").read_text())
assert AUDIT["baseline"] == BASE

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



def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False,
        separators=(",", ":")).encode()).hexdigest()


def canonical(sources):
    return {name: [[method, [php_args.canonical_expression(arg) for arg in args]]
                   for method, args in rows]
            for name, rows in calls(sources).items()}


def compare_accepted(before, after):
    assert sorted(after.keys() - before.keys()) == sorted(AUDIT["added_without_direct_send"]+AUDIT["added_with_direct_send"])
    assert sorted(before.keys() - after.keys()) == AUDIT["removed"]
    assert all(not after[name] for name in AUDIT["added_without_direct_send"])
    assert all(after[name] for name in AUDIT["added_with_direct_send"])
    changes = set()
    counts = Counter()
    for name in sorted(before.keys() | after.keys()):
        previous, current = before.get(name, []), after.get(name, [])
        counts.update(method for method, _ in current)
        if previous == current:
            continue
        changes.add(name)
        contract = AUDIT["changes"].get(name)
        assert contract is not None, ("unapproved_wire_delta", name)
        assert contract["reason"] and (
            len(previous), len(current), digest(previous), digest(current)
        ) == (contract["before_count"], contract["after_count"],
              contract["before_sha256"], contract["after_sha256"]), (
            "exact_wire_delta_changed", name)
    assert changes == set(AUDIT["changes"]), "missing_declared_wire_delta"
    return dict(counts)


# Keep the integrated #237 proof intact. The hygiene step permits only the
# catalog-equivalent expressions and a direct callback inside a proven
# removed, unreferenced helper. Every other call/argument remains exact.
HYGIENE=json.loads((ROOT / "tools/current_storage_copy_mapping.json").read_text())

def compare_hygiene(before, after):
    assert not before.keys()-after.keys()
    added=after.keys()-before.keys()
    assert added=={"src/Database/Lock.php"},added
    assert all(not after[name] for name in added)
    def mapped(arg):
        for old,new in HYGIENE["equal"].items():
            arg=arg.replace("\\achbotMessage('"+old+"')",
                php_args.canonical_expression("\\achbotMessage('"+new+"')"))
        for old in ("bot.bot.edit_lock_channel.message.949fda751f",
                    "subscription.account_transfers.text.b233883d0d"):
            arg=arg.replace("??\\achbotMessage('"+old+"')","")
        return arg
    expected={name:[[method,[mapped(arg) for arg in args]] for method,args in rows]
              for name,rows in before.items()}
    dead=["answerCallbackQuery",["$callbackId"]]
    assert expected["src/Finance/ReceiptHelpers.php"].count(dead)==1
    expected["src/Finance/ReceiptHelpers.php"].remove(dead)
    for name in added: expected[name]=[]
    assert expected.keys()==after.keys()
    for name,rows in expected.items():
        assert rows==after[name],("unapproved_hygiene_wire_delta",name)
    return dict(Counter(method for rows in after.values() for method,_ in rows))

# #236 changes a bounded set of exact current call producers. Keep both
# earlier proofs, then require the explicit candidate delta against v0.12.0.
UX=json.loads((ROOT / "tools/ux_lifecycle_wire_contract.json").read_text())

def compare_ux(before, after):
    assert before.keys()==after.keys()
    changes=set()
    for name in sorted(before):
        previous,current=before[name],after[name]
        if previous==current:
            continue
        changes.add(name)
        contract=UX["changes"].get(name)
        assert contract is not None,("unapproved_ux_wire_delta",name)
        assert (len(previous),len(current),digest(previous),digest(current))==(
            contract["before_count"],contract["after_count"],
            contract["before_sha256"],contract["after_sha256"]),(
            "exact_ux_wire_delta_changed",name)
    assert changes==set(UX["changes"]),"missing_declared_ux_wire_delta"
    return dict(Counter(method for rows in after.values() for method,_ in rows))

# Owner-approved Chili acceptance changes exactly two bulk prompt keys.
# Preserve all prior contracts through the integrated v0.12.1 baseline.
CHILI_BASE = "2b8e60b7b03396aeb89663698280eada0f536846"

def compare_chili(before, after):
    assert before.keys()==after.keys()
    path="src/Purchase/EntryAndBulk.php"
    replacements={
        "purchase.entry_and_bulk.create_multiple_accounts.message.2f3dfcbe8e":"legacy_messages.buy_sub_select_category",
        "purchase.entry_and_bulk.create_multiple_accounts.message.7f4d98ab9b":"legacy_messages.buy_sub_select_plan",
    }
    # The cap editor uses the existing input and Save/Cancel transport.
    # Pin only the three authorized argument deltas.
    bot_replacements={
        "achbotGatewayAdminExemptPrompt($operation['method'])":
            "$operation['action']==='k'?achbotGatewayAdminAmountPrompt($operation['method']):achbotGatewayAdminExemptPrompt($operation['method'])",
        r"""($operation['action']==='s'?\achbotMessage('bot.bot.gateway_audience_settings.message.fdfbaf2a40'):\achbotMessage('bot.bot.gateway_audience_settings.message.74d707fe02'))."\n".$page['text']""":
            r"""($operation['action']==='s'?($prior&&$prior['phase']==='capconfirm'?\achbotMessage('payment_settings.gateway_rule_admin.cap_saved'):\achbotMessage('bot.bot.gateway_audience_settings.message.fdfbaf2a40')):\achbotMessage('bot.bot.gateway_audience_settings.message.74d707fe02'))."\n".$page['text']""",
        r"\achbotMessage('bot.bot.gateway_audience_settings.message.397d3e91b6').$text.\achbotMessage('bot.bot.gateway_audience_settings.message.c5bdeadc6b')":
            r"\achbotMessage('bot.bot.gateway_audience_settings.message.397d3e91b6').$proposed.\achbotMessage('bot.bot.gateway_audience_settings.message.c5bdeadc6b')"
    }
    seen=Counter()
    for name,rows in before.items():
        expected=[]
        for method,args in rows:
            args=list(args)
            if name==path and method=="editText":
                for i,arg in enumerate(args):
                    for old,new in replacements.items():
                        if arg==php_args.canonical_expression("\\achbotMessage('"+old+"')"):
                            args[i]=php_args.canonical_expression("\\achbotMessage('"+new+"')")
                            seen[old]+=1
            if name=='bot.php' and method in ('sendMessage','editText'):
                for i,arg in enumerate(args):
                    if arg in bot_replacements:
                        args[i]=bot_replacements[arg]
                        seen[arg]+=1
            expected.append([method,args])
        assert expected==after[name],("unapproved_chili_wire_delta",name)
    assert seen==Counter({key:1 for key in (*replacements,*bot_replacements)}),seen
    return dict(Counter(method for rows in after.values() for method,_ in rows))

def main():
    def accepted(name):
        return (name.endswith(".php") and not name.startswith("messages/fa/")
                and (name in package_current.ROOT_FILES or
                     name.startswith(package_current.RUNTIME_DIRS)))
    names = subprocess.check_output(
        ["git", "ls-tree", "-r", "--name-only", BASE], cwd=ROOT, text=True).splitlines()
    old = {name: subprocess.check_output(
        ["git", "show", BASE + ":" + name], cwd=ROOT, text=True)
           for name in names if accepted(name)}
    now = {name: (ROOT / name).read_text() for name in package_current.members()
           if accepted(name)}
    previous = canonical(old)
    current = canonical(now)
    integrated_names = subprocess.check_output(
        ["git", "ls-tree", "-r", "--name-only", HYGIENE["baseline"]],
        cwd=ROOT, text=True).splitlines()
    integrated = canonical({name:subprocess.check_output(
        ["git","show",HYGIENE["baseline"]+":"+name],cwd=ROOT,text=True)
        for name in integrated_names if accepted(name)})
    compare_accepted(previous,integrated)
    hygiene = canonical({name:subprocess.check_output(
        ["git","show",UX["baseline"]+":"+name],cwd=ROOT,text=True)
        for name in current})
    compare_hygiene(integrated,hygiene)
    chili = canonical({name:subprocess.check_output(
        ["git","show",CHILI_BASE+":"+name],cwd=ROOT,text=True)
        for name in current})
    compare_ux(hygiene,chili)
    total = compare_chili(chili,current)

    # Mutate an actual current call, not a hand-written parser fixture.
    bot = now["bot.php"]
    html = re.search(r"""sendMessage\([^;
]+,\s*[^;
]+,\s*['"]HTML['"]\)""", bot)
    assert html is not None
    original = html.group()
    replacements = [
        bot.replace(original, original.replace("'HTML'", "'Markdown'").replace('"HTML"', '"Markdown"'), 1),
        bot.replace(original, "", 1),
        bot.replace("<?php", "<?php\nsendMessage('unexpected live message');", 1),
        bot.replace(original, "sendMessage('unexpected keyboard', null, 'HTML')", 1),
    ]
    for replacement in replacements:
        assert replacement != bot
        mutation = dict(now, **{"bot.php": replacement})
        try:
            compare_chili(chili, canonical(mutation))
        except AssertionError:
            pass
        else:
            raise AssertionError("wire_mutation_not_detected")
    mutation = dict(now, **{"bot.php": bot.replace(
        "<?php", "<?php\n// sendMessage('inactive comment');", 1)})
    assert compare_chili(chili, canonical(mutation)) == total
    print(json.dumps({"complete": True, "status": "PASS", "baseline": BASE,
        "calls": total, "cases": sum(total.values()),
        "exact_unchanged_paths_and_pinned_deltas": True,
        "changed_paths": len(AUDIT["changes"]), "ux_changed_paths":len(UX["changes"]), "negative_fixtures": 4,
        "network_calls": 0}))


if __name__ == "__main__":
    main()
