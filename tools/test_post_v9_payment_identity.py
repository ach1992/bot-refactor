#!/usr/bin/env python3
"""Source-bound payment reference unpredictability and Telegram size regression.

Executes ONLY the extracted, side-effect-free RandomString() definition in a
new local PHP process. Never boots Telegram, private configuration or a DB.
The frozen V0-V9 test fixtures and provider integration are not changed.
"""
from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT / "config.php").read_text(encoding="utf-8")
start = source.index('function RandomString(')
end = source.index('function generateUID(){', start)
definition = source[start:end]
if "random_int(0, $lastIndex)" not in definition or re.search(
        r"\b(?:rand|mt_rand)\s*\(", re.sub(r"//[^\n]*", "", definition)):
    raise RuntimeError("Current payment references must be cryptographically unpredictable")

script = definition + r'''
$all=[];
for ($n=0;$n<500;$n++) {
    $hash=RandomString();
    if (!preg_match('/^[A-Za-z1-9]{20}$/D',$hash)) {
        throw new RuntimeException('Unexpected customer payment ID format');
    }
    $all[$hash]=true;
}
if (count($all)!==500) throw new RuntimeException('Payment reference reuse');
if (!preg_match('/^[a-f1-9]{9}$/D',RandomString(9,'small'))) {
    throw new RuntimeException('Explicit legacy random-string length changed');
}
if (!preg_match('/^[a-z]{12}$/D',RandomString(12,'domain'))) {
    throw new RuntimeException('Domain-specific format changed');
}
foreach ([-1,0,65] as $bad) {
    try { RandomString($bad); throw new RuntimeException('accepted invalid size'); }
    catch (InvalidArgumentException $expected) {}
}
try { RandomString(8,'unrecognized'); throw new RuntimeException('accepted unknown type'); }
catch (InvalidArgumentException $expected) {}
echo json_encode(['complete'=>true,'unique_generated'=>count($all),
    'default_length'=>strlen(array_key_first($all)),
    'crypto_source'=>'random_int'],JSON_THROW_ON_ERROR);
'''
result = subprocess.run(["php", "-n", "-r", script], cwd=ROOT,
                        capture_output=True, text=True, timeout=15, check=True)
output = json.loads(result.stdout)

# All rendered Telegram Callback strings with an original merchant hash must
# fit 64 BYTES, not 64 Unicode characters. The longest supported event is the
# renewal/extension pre-payment selector; the rest have shorter ASCII prefixes.
prefixes = (
    "prePayCheck_INCREASEVOLUME_ManualUsdt_",
    "prePayCheck_INCREASEVOLUME_CartToCart_",
    "prePayCheck_RENEWACCOUNT_nowpayment_",
    "payIncreaseWalletWithManualUsdt",
    "sendIncreaseVolumeUsdtReceipt",
    "payIncreaseDayWithManualUsdt",
)
if max(len((prefix + "A" * output["default_length"]).encode("ascii"))
       for prefix in prefixes) > 64:
    raise RuntimeError("New payment references exceed Telegram callback_data limit")
if output.get("complete") is not True or output["default_length"] != 20:
    raise RuntimeError("Payment reference generation acceptance failed")
output["longest_callback_bytes"] = max(
    len((prefix + "A" * 20).encode("ascii")) for prefix in prefixes)
print(json.dumps(output, ensure_ascii=False))
