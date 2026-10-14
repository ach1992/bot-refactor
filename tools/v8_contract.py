#!/usr/bin/env python3
"""AChBot V8: reproducible legacy financial extraction from exact integrated V7.

The legacy PHP byte spans are immutable source authority. Generated modules
retain original statements, SQL, HTTP arguments and callback strings. The only
transformations are explicit source-path and cross-include goto compatibility.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import subprocess
from pathlib import Path
import v7_contract as v7

ROOT = Path(__file__).resolve().parents[1]
BASE = "26ba693242241c248b9f53a33153a2bb3c2d4bd0"
BASE_TREE = "3ab1cc5632846de1f1b1d1ccdc480255a6d4e78a"
BRANCH = "feature/v8-financial-lifecycle"
SIGNAL = b"__ACHBOT_V8_GOTO_START__"
GUARD = (b"if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {\n"
         b"    http_response_code(404);\n    exit;\n}\n\n")
# Each segment includes its begin token and stops immediately before its end.
# Script-end spans use None for a byte-for-byte move of the entire remaining body.
ROUTES = [
    ("src/Finance/Routes/PrePayment.php", b"// Pre Pay Check By ACh", b"// END Pre Pay Check By ACh"),
    ("src/Finance/Routes/WalletTransfer.php", b'if($data=="transferMyWallet"){', b'if($data == "increaseMyWallet") {'),
    ("src/Finance/Routes/WalletRecharge.php", b'if($data == "increaseMyWallet") {', b"if (preg_match('/^approvePayment(.*)/'"),
    ("src/Finance/Routes/ManualReceipts.php", b"if (preg_match('/^approvePayment(.*)/'", b'if($data=="increaseUserWallet"'),
    ("src/Finance/Routes/AdminWallet.php", b'if($data=="increaseUserWallet"', b'if($data=="editRewardChannel"'),
    ("src/Finance/Routes/CryptoAndExchange.php", b"if(preg_match('/payWithTronWallet", b'if($data=="messageToSpeceficUser"'),
    ("src/Finance/Routes/DiscountEntry.php", b"if(preg_match('/^haveDiscount", b"if ((require __DIR__ . '/src/Purchase/TrialCatalogue.php')"),
    ("src/Finance/Routes/Checkout.php", b"if (preg_match('/payCustomWithWallet", b'if($data=="availableServers"){'),
    ("src/Finance/Routes/ManualApproval.php", b"if (\n    preg_match('/accept(.*)/'", b"require __DIR__ . '/src/Support/Entry.php';"),
    ("src/Finance/Routes/RenewalSelection.php", b"if(preg_match('/sConfigRenew(\\d+)/', $data,$match)){", b"if ((require __DIR__ . '/src/Subscription/ManualAccounts.php')"),
    ("src/Finance/Routes/RenewalPlan.php", b"if(preg_match('/sConfigRenewPlan", b"if ((require __DIR__ . '/src/Subscription/ConfigRefresh.php')"),
    ("src/Finance/Routes/RenewalCheckout.php", b"if (preg_match('/^renewAccountAsk", b"if ((require __DIR__ . '/src/Subscription/AccountTransfers.php')"),
    ("src/Finance/Routes/Extensions.php", b"if(preg_match('/increaseADay", b"if($data == 'cantEditTrojan')"),
    ("src/Finance/Routes/DiscountAdministration.php", b"// BEGIN Manage discount", b'if($data == "managePanel"'),
]
CONFIG = [
    ("src/Finance/WalletStart.php", b"function startWalletPayment(", b"function replaceAllPlaceholdersInMainValues("),
    ("src/Finance/AgentDiscounts.php", b"function getAgentDiscounts(", b"function NOWPayments("),
    ("src/Finance/GatewayClient.php", b"function NOWPayments(", b"function getServerConfigKeys("),
    ("src/Finance/Discounts.php", b"function applyDiscountToPay(", b"function getMainMenuButtonsKeys("),
]
HELPERS = [
    ("src/Finance/ReferralReward.php", b"if (!function_exists('giveReferralReward'))", b"if (!function_exists('renewConfigOnServer'))"),
    ("src/Finance/RenewalHelper.php", b"if (!function_exists('renewConfigOnServer'))", b"if (!function_exists('processWalletPayment'))"),
    ("src/Finance/WalletSettlement.php", b"if (!function_exists('processWalletPayment'))", b"require_once __DIR__ . '/src/Purchase/AgentPricing.php';"),
    ("src/Finance/ReceiptHelpers.php", b"if (!function_exists('payTypeToDescription'))", b"?>"),
]
SCRIPTS = {
    "pay/index.php": [("src/Finance/GatewayInitiation.php", b"//====================//  Get  //==============================", None)],
    "pay/back.php": [("src/Finance/GatewayReturn.php", b"if (isset($_GET['nowpayment'])) {", None)],
    "settings/tronChecker.php": [("src/Finance/CryptoChecker.php", b'$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` IS NOT NULL', None)],
    "settings/warnusers.php": [("src/Finance/AutomaticRenewal.php", b"global $connection, $botState;", None)],
    "settings/rewardReport.php": [("src/Finance/AutomaticReceipts.php", b'if($botState[\'cartToCartAutoAcceptState\']=="on"){', None)],
}
GROUPS = {"bot.php": ROUTES, "config.php": CONFIG, "buy_helpers.php": HELPERS, **SCRIPTS}
MODULES = sorted(x[0] for group in GROUPS.values() for x in group)
EXACT_GOTO = b"goto GOTOSTART;"
PATH_REWRITE = b"__DIR__ . '/src/"
PATH_ROOT = b"dirname(__DIR__, 2) . '/src/"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *args])


def old(name: str) -> bytes:
    return git("show", BASE + ":" + name)


def material(name: str, module: str, body: bytes) -> tuple[bytes, int]:
    # __DIR__ retains its original filesystem meaning when finance modules move.
    if name == "buy_helpers.php" and module.endswith("/ReceiptHelpers.php"):
        if PATH_REWRITE not in body:
            raise RuntimeError("Receipt helper path compatibility lost")
        body = body.replace(PATH_REWRITE, PATH_ROOT)
    elif name == "pay/back.php":
        if body.count(b"__DIR__") != 1:
            raise RuntimeError("Unexpected pay/back magic directory use")
        body = body.replace(b"__DIR__", b"dirname(__DIR__, 2) . '/pay'")
    elif b"__DIR__" in body or b"__FILE__" in body or b"__LINE__" in body:
        raise RuntimeError("Unexpected relocating PHP magic path: " + module)
    if b"goto " in body and name != "bot.php":
        raise RuntimeError("Unexpected goto in non-bot module: " + module)
    gotos = body.count(EXACT_GOTO)
    if name == "bot.php":
        body = body.replace(EXACT_GOTO, b"return '" + SIGNAL + b"';")
        if b"goto " in body:
            raise RuntimeError("Unknown goto in extracted route: " + module)
    elif gotos:
        raise RuntimeError("Unknown cross-file goto: " + module)
    return body, gotos


def reconstruct() -> tuple[dict[str, bytes], dict[str, dict]]:
    if git("rev-parse", BASE + "^{tree}").decode().strip() != BASE_TREE:
        raise RuntimeError("Pinned V7 Git tree mismatch")
    result: dict[str, bytes] = {}
    evidence: dict[str, dict] = {}
    for file, specs in GROUPS.items():
        data = old(file)
        original = data
        regions = []
        for module, start, end in specs:
            if data.count(start) != 1:
                raise RuntimeError("Ambiguous financial start in " + file + " for " + module)
            a = data.index(start)
            if end is None:
                b = len(data)
            else:
                if data.count(end) != 1:
                    raise RuntimeError("Ambiguous financial end in " + file + " for " + module)
                b = data.index(end, a + len(start))
            if b <= a:
                raise RuntimeError("Reversed financial region: " + module)
            regions.append((a, b, module))
        regions.sort()
        if any(y[0] < x[1] for x, y in zip(regions, regions[1:])):
            raise RuntimeError("Overlapping extracted regions: " + file)
        endings = b"\r\n" if b"\r\n" in data[:512] else b"\n"
        for a, b, module in reversed(regions):
            original_body = data[a:b]
            moved, gotos = material(file, module, original_body)
            filename = module.encode()
            if file == "bot.php":
                hook = b"if (($achbotV8Result = require __DIR__ . '/" + filename + b"') === null) return;"
                if gotos:
                    hook += endings + b"if ($achbotV8Result === '" + SIGNAL + b"') goto GOTOSTART;"
                hook += endings
            elif file in ("config.php", "buy_helpers.php"):
                hook = b"require_once __DIR__ . '/" + filename + b"';" + endings
            else:
                relative = os.path.relpath(module, Path(file).parent).replace(os.sep, "/")
                hook = b"require __DIR__ . '/" + relative.encode() + b"';" + endings
            data = data[:a] + hook + data[b:]
            result[module] = b"<?php\n/** AChBot V8: source-pinned financial compatibility module. */\n" + GUARD + moved
            evidence[module] = {
                "source": file, "original_body_sha256": hashlib.sha256(original_body).hexdigest(),
                "source_bytes": len(original_body), "goto_bridge_count": gotos,
                "path_mapping": "legacy directory preserved" if moved != original_body.replace(EXACT_GOTO, b"return '" + SIGNAL + b"';") else "none",
            }
        result[file] = data
        if original == data:
            raise RuntimeError("No extraction from " + file)
    return result, evidence


def members() -> list[str]:
    return sorted(v7.members() + MODULES)


def verify() -> dict:
    expected, evidence = reconstruct()
    prev = v7.members()
    if len(prev) != 65 or len(MODULES) != len(set(MODULES)):
        raise RuntimeError("Invalid source allowlist")
    for name in prev:
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError("V8 legacy member missing or symlink: " + name)
        truth = expected.get(name, old(name))
        if path.read_bytes() != truth:
            raise RuntimeError("V8 legacy source drift: " + name)
    for name in MODULES:
        path = ROOT / name
        if not path.is_file() or path.is_symlink() or path.read_bytes() != expected[name]:
            raise RuntimeError("V8 relocated module drift: " + name)
    php = {x.relative_to(ROOT).as_posix() for x in ROOT.rglob("*.php")
           if x.relative_to(ROOT).parts[0] not in ("tools", "tests", ".git")
           and x.name != "baseInfo.php"}
    required = {name for name in members() if name.endswith(".php")}
    if php != required:
        raise RuntimeError("Unexpected runtime member drift: " + repr(sorted(php ^ required)))
    for name in ("database/legacy-schema.sql",):
        if (ROOT / name).read_bytes() != old(name):
            raise RuntimeError("Original schema changed")
    return {"base": BASE, "base_tree": BASE_TREE, "old_runtime_members": len(prev),
            "new_runtime_members": len(members()), "financial_modules": len(MODULES),
            "routed_gotos": sum(x["goto_bridge_count"] for x in evidence.values()),
            "module_source_provenance": evidence, "ddl_unchanged": True}


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--materialize", action="store_true")
    a = p.parse_args()
    if a.materialize:
        if git("branch", "--show-current").decode().strip() != BRANCH:
            raise RuntimeError("Refusing to mutate another branch")
        if git("rev-parse", "HEAD").decode().strip() != BASE:
            raise RuntimeError("Expected pinned V7 branch start")
        if git("status", "--porcelain").strip() != b"?? tools/v8_contract.py":
            raise RuntimeError("Worktree must be clean except the new generator")
        generated, _ = reconstruct()
        for name, content in generated.items():
            path = ROOT / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
    report = verify() if a.materialize else {
        "base": BASE, "proposed_modules": len(MODULES),
        "paths": sorted(reconstruct()[0])
    }
    if "module_source_provenance" in report:
        report["body_provenance_count"] = len(report.pop("module_source_provenance"))
    print(json.dumps(report, ensure_ascii=False))


if __name__ == "__main__":
    main()
