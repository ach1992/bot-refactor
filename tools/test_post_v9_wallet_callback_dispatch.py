#!/usr/bin/env python3
"""Real ManualReceipts-to-Wallet-review callback routing, without any live DB.

The production incident was a generated button whose top-level dispatcher
never included the real handler. An unconnected mysqli guarantees that these
fixtures cannot perform financial writes, Native I/O or Telegram delivery.
"""
from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOKEN = "0123456789abcdef0123"
PAY = 777777

PHP = r'''
error_reporting(E_ALL & ~E_DEPRECATED);
$_SERVER['SCRIPT_FILENAME'] = 'owned-wallet-route-probe.php';
$root = $argv[1];
$data = $argv[2];
$mode = $argv[3];
$admin = 9001;
$from_id = $mode === 'customer' ? 1001 : $admin;
$chat_id = $mode === 'group' ? 1001 : $from_id;
$callbackId = 'owned-wallet-callback';
$message_id = 50;
$userInfo = ['step' => 'none'];
$update=(object)['update_id'=>12345,'callback_query'=>(object)['id'=>$callbackId,
    'from'=>(object)['id'=>$from_id],'data'=>$data,'message'=>(object)['message_id'=>$message_id,
    'chat'=>(object)['id'=>$chat_id,'type'=>$mode==='group'?'group':'private']]]];
// Do not CONNECT: all queries fail; only response/denial dispatch is tested.
$connection = new mysqli();
$GLOBALS['route_events'] = [];
function answerCallbackQuery(...$args) {
    $GLOBALS['route_events'][] = [
        'kind' => 'callback', 'alert' => (bool)($args[2] ?? false),
        'denied' => in_array((string)($args[1] ?? ''), [
            achbotMessage('admin.roles.denied'),
            achbotMessage('automatic_gateway_review.denied'),
        ], true),
    ];
}
function editText(...$args) {
    $GLOBALS['route_events'][] = ['kind' => 'edit'];
    return (object)['ok' => true];
}
function sendMessage(...$args) {
    $GLOBALS['route_events'][] = ['kind' => 'message'];
    return (object)['ok' => true];
}
require_once $root . '/src/Purchase/Core.php';
$capability = achbotAdminRequestCapability($data);
require $root . '/src/Finance/Routes/ManualReceipts.php';
echo json_encode(['capability' => $capability, 'events' => $GLOBALS['route_events']],
    JSON_THROW_ON_ERROR);
'''


def invoke(callback: str, mode: str) -> dict:
    p = subprocess.run([
        'php', '-d', 'allow_url_fopen=0',
        '-d', 'disable_functions=curl_init,curl_exec,exec,shell_exec,system,passthru,popen,proc_open,mail',
        '-r', PHP, str(ROOT), callback, mode,
    ], cwd=ROOT, capture_output=True, text=True, timeout=12)
    if p.returncode != 0:
        raise RuntimeError(f'{callback}/{mode}: PHP failed: {p.stderr[-1200:]}')
    try:
        return json.loads(p.stdout)
    except ValueError as e:
        raise RuntimeError(f'{callback}/{mode}: no callback result: {p.stdout[-400:]}') from e


def main() -> None:
    # Every button family used by the existing attention-case renderer,
    # including confirmation callbacks, must reach the real top-level route.
    actions = [
        f'attentionReceipt_{PAY}',
        f'attentionService_{PAY}',
        f'attentionServiceResume_{PAY}',
        f'attentionServiceCloseC_{PAY}',
        f'attentionServiceCloseS_{PAY}_{TOKEN}',
        f'attentionServiceRefundC_{PAY}',
        f'attentionServiceRefundS_{PAY}_{TOKEN}',
        f'attentionServicePage_{PAY}_10',
        f'attentionDeliveryC_{PAY}_1',
        f'attentionDeliveryS_{PAY}_1_{TOKEN}',
        f'attentionReportC_{PAY}',
        f'attentionReportS_{PAY}_{TOKEN}',
    ]
    checked = 0
    for callback in actions:
        result = invoke(callback, 'owner')
        kinds = [event['kind'] for event in result['events']]
        if result['capability'] != 'payments' or not kinds:
            raise AssertionError(f'owner callback was swallowed: {callback}: {result}')
        if not any(k in ('callback', 'edit') for k in kinds) or any(
                event.get('denied', False) for event in result['events']):
            raise AssertionError(f'owner handler/authorization failed: {callback}: {result}')
        checked += 1
    # A forged current-user or group callback is not allowed to edit a case;
    # the same real dispatcher must produce an explicit denial callback.
    for callback in actions:
        for mode in ('customer', 'group'):
            result = invoke(callback, mode)
            if result['capability'] != 'payments' or not result['events'] or any(
                    event['kind'] != 'callback' or not event['alert'] or not event['denied']
                    for event in result['events']):
                raise AssertionError(f'untrusted callback not denied: {callback}/{mode}: {result}')
            checked += 1
    for callback in (f'attentionWalletResume_{PAY}',f'attentionWalletArchive_{PAY}',
                     f'attentionWalletInspect_{PAY}',f'apiEvidenceExecute_{PAY}',
                     f'apiEvidenceOverride_{PAY}',f'gatewayReview_H_{PAY}'):
        result=invoke(callback,'owner')
        assert result['capability'] is None and not result['events'],(callback,result)
        checked+=1
    print(json.dumps({'complete': True, 'status': 'PASS', 'cases': checked,
                      'real_manual_receipts_route': True,
                      'native_or_telegram_calls': 0,
                      'database_connections': 0}))


if __name__ == '__main__':
    main()
