<?php
/**
 * Shared private operational diagnostics, independent from business success.
 * Only typed identifiers and finite diagnostic codes are accepted. Never log
 * request/response bodies, SMS text, provider URLs, Telegram user content,
 * keys, credentials, hashes or invoice evidence payloads.
 */
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    http_response_code(404);
    exit;
}
/** Finite status/error codes from the two authenticated upstream API contracts.
 * This is a logging policy, never a payment/verification acceptance check.
 */
function achbotDiagnosticUpstreamCodeKnown(string $provider,string $code): bool {
    static $byProvider=[
        'bank_transfer'=>[
            'ach_pay_invalid_filter','ach_pay_ambiguous_ingestion_time',
            'ach_pay_not_found','ach_pay_missing_scope',
            'ach_pay_invalid_idempotency','ach_pay_idempotency_conflict',
            'ach_pay_action_failed','ach_pay_storage_unavailable'
        ],
        'gift_card'=>[
            'card_verified','card_redeemed','card_redeem_idempotent',
            'card_not_found','invalid_card_code','missing_code',
            'payload_mismatch','invalid_content_type','invalid_json_body',
            'unsupported_operation','verification_persistence_failed',
            'card_revoked','card_restored','card_unredeemed','api_error'
        ],
    ];
    return in_array($code,$byProvider[$provider]??[],true);
}
function achbotDiagnosticEvent(string $name,array $context=[],string $level='info'): void {
    try {
        if (!preg_match('/\A[a-z][a-z0-9_.]{1,95}\z/D',$name) ||
            !in_array($level,['debug','info','warning','error'],true)) return;
        // Per PHP request or CLI worker; groups all stages without a DB write.
        static $trace=null;
        $trace??=bin2hex(random_bytes(8));
        $now=microtime(true);
        $fields=[
            'event'=>$name,
            'time_utc'=>gmdate('Y-m-d\TH:i:s',(int)$now).
                sprintf('.%06dZ',(int)(($now-floor($now))*1000000)),
            'level'=>$level,
            'trace_id'=>$trace,
            'instance'=>preg_replace('/[^A-Za-z0-9_-]/','_',
                basename(dirname(__DIR__,2)))
        ];
        $numeric=['pay_id','attempt_id','effect_id','order_id','server_id','service_operation_id',
            'actor_id','ordinal','http_status','curl_errno','retry_after',
            'page','checked','selected','projected','deferred','due_unchecked',
            'elapsed_ms','batch_ms','message_count','candidate_count',
            'next_check_seconds','total','success','failure','budget_exhausted',
            'request_attempt','followup_id','delivery_id','delivery_part','telegram_error_code'];
        $codes=['provider','method','operation','stage','phase','state','reason',
            'result','source','class','worker','evidence_state','fulfillment_state',
            'http_reason','action','mode','upstream_request_id'];
        foreach ($context as $key=>$value) {
            if (in_array($key,$numeric,true) && is_int($value) && $value>=0)
                $fields[$key]=$value;
            elseif ($key==='upstream_request_id' && is_string($value) &&
                ($value==='none' ||
                 preg_match('/\Asha256:[a-f0-9]{64}\z/D',$value)===1 ||
                 (($context['provider']??null)==='bank_transfer' &&
                    preg_match('/\A[a-f0-9]{24}\z/D',$value)===1) ||
                 (($context['provider']??null)==='gift_card' &&
                    preg_match('/\Areq_[a-f0-9]{24}(?:[a-f0-9]{8})?\z/D',$value)===1)))
                $fields[$key]=$value;
            elseif ($key!=='upstream_request_id' && in_array($key,$codes,true) &&
                is_string($value) &&
                preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{0,95}\z/D',$value)) {
                // Arbitrary exception messages may be account numbers, tokens
                // or raw upstream text. Only machine-shaped known diagnostic
                // reasons can pass; retain class/HTTP/cURL for other failures.
                if ($key==='reason' &&
                    !in_array($value,['pending','unknown','settled',
                        'review','unavailable','monitoring'],true) &&
                    preg_match('/\A(?:achbot|pg|provider|payment|wallet|manual|delivery|followup|addon|native|http|response|claim|check|schema|invoice|original|dispatch|maintenance|worker|batch|source|identity|service|recovery|transport|receipt|upstream|internal|already|operation|remote|awaiting|lease)_[a-z0-9_]{2,88}\z/D',$value)!==1)
                    continue;
                // The sink must not trust syntactically valid but arbitrary
                // upstream response content, even from future call sites.
                if ($key==='http_reason' && $value!=='none' &&
                    $value!=='unrecognized_upstream_code' &&
                    !achbotDiagnosticUpstreamCodeKnown(
                        (string)($context['provider']??''),$value)) continue;
                $fields[$key]=$value;
            }
        }
        error_log('[AChBot] '.json_encode($fields,
            JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR));
    } catch (Throwable $ignored) {
        // Diagnostic sink failure never changes financial/Native decisions.
    }
}
