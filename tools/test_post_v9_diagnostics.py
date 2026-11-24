#!/usr/bin/env python3
"""Private structured logging: correlation, typed context and secret exclusion.

Pure PHP 8.5 CLI; no SQL, network, real customer data or external services.
"""
from __future__ import annotations
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
DRIVER = r"""
require_once $argv[1].'/src/Diagnostics/EventLog.php';
achbotDiagnosticEvent('achbot.finance.evidence_check',[
    'pay_id'=>112749,'reason'=>'claim_lease_held',
    'stage'=>'review','state'=>'have_sent',
    'secret'=>'SENSITIVE_TOKEN_DO_NOT_LOG',
    'sms_text'=>'SENSITIVE_SMS_DO_NOT_LOG',
    'raw_text'=>'SENSITIVE_RAW_DO_NOT_LOG']);
achbotDiagnosticEvent('achbot.finance.evidence_http',[
    'pay_id'=>112749,'provider'=>'bank_transfer','http_status'=>503,
    'curl_errno'=>28,'upstream_request_id'=>'abcdef0123456789abcdef01',
    'http_reason'=>'ach_pay_storage_unavailable',
    'reason'=>'http_unexpected','request_attempt'=>1]);
achbotDiagnosticEvent('achbot.finance.wallet_held_or_failed',[
    'reason'=>'sk_private_token','class'=>'RuntimeException',
    'operation'=>'INCREASE_DAY_10_1']);
achbotDiagnosticEvent('achbot.finance.evidence_check',[
    'pay_id'=>112749,'reason'=>'awaiting_receipt']);
achbotDiagnosticEvent('achbot.delivery.dispatch_deferred',[
    'pay_id'=>112749,'ordinal'=>1,'reason'=>'lease_busy']);
achbotDiagnosticEvent('achbot.finance.evidence_http',[
    'provider'=>'bank_transfer','http_reason'=>'SENSITIVE_TOKEN_DO_NOT_LOG',
    'upstream_request_id'=>'SENSITIVE_REQUEST_ID_DO_NOT_LOG',
    'reason'=>'http_unexpected']);
achbotDiagnosticEvent('achbot.finance.report_channel_invalid',[
    'wallet_entry_id'=>987,'stage'=>'wallet_movement','reason'=>'followup_channel_invalid',
    'raw_channel'=>'SENSITIVE_RAW_DO_NOT_LOG'],'warning');
achbotDiagnosticEvent('BAD name',['reason'=>'leak']);
"""
def run() -> dict:
    proc = subprocess.run(
        ["php","-n","-d","log_errors=1","-d","error_log=/dev/stderr",
         "-r",DRIVER,str(ROOT)],
        capture_output=True,text=True,timeout=12)
    if proc.returncode!=0 or proc.stdout:
        raise RuntimeError(f'Unexpected PHP execution: {proc.returncode} {proc.stderr[-800:]}')
    messages=[]
    for raw in proc.stderr.splitlines():
        index=raw.find("[AChBot] ")
        if index<0:
            raise RuntimeError('Unstructured PHP stderr: '+raw[:180])
        message=json.loads(raw[index+len("[AChBot] "):])
        messages.append(message)
    if len(messages)!=7:
        raise AssertionError(('expected_seven_events',messages))
    if any(word in proc.stderr for word in (
        "SENSITIVE_TOKEN","SENSITIVE_REQUEST_ID","SENSITIVE_SMS","SENSITIVE_RAW","sk_private_token")):
        raise AssertionError('Sensitive values emitted')
    if len({m.get('trace_id') for m in messages})!=1 or not re.fullmatch(
        r'[a-f0-9]{16}',messages[0]['trace_id']):
        raise AssertionError('Missing/repeated trace correlation')
    for event in messages:
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z',
                            event.get('time_utc','')):
            raise AssertionError('Missing microsecond UTC timestamp')
        if 'password' in event or 'raw_text' in event:
            raise AssertionError('Untrusted field emitted')
    if messages[0]['pay_id']!=112749 or messages[1]['pay_id']!=112749 or (
        messages[0]['reason']!='claim_lease_held' or
        messages[1]['http_status']!=503 or
        messages[1]['http_reason']!='ach_pay_storage_unavailable' or
        messages[1]['upstream_request_id']!='abcdef0123456789abcdef01' or
        messages[2]['class']!='RuntimeException' or
        messages[3].get('reason')!='awaiting_receipt' or
        messages[4].get('reason')!='lease_busy' or
        'http_reason' in messages[5] or 'upstream_request_id' in messages[5] or
        messages[6].get('wallet_entry_id')!=987 or messages[6].get('reason')!='followup_channel_invalid' or
        messages[6].get('stage')!='wallet_movement'):
        raise AssertionError(('Log context was not preserved',messages))
    return {'complete':True,'cases':14,'private_log_format':True,
            'same_trace':True,'secrets_redacted':True,'live_calls':0}


if __name__=='__main__':
    print(json.dumps(run()))
