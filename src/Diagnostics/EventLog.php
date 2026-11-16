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
        $numeric=['pay_id','attempt_id','effect_id','order_id','server_id',
            'actor_id','ordinal','http_status','curl_errno','retry_after',
            'page','checked','selected','projected','deferred','due_unchecked',
            'elapsed_ms','batch_ms','message_count','candidate_count',
            'next_check_seconds','total','success','failure','budget_exhausted',
            'request_attempt'];
        $codes=['provider','method','operation','stage','phase','state','reason',
            'result','source','class','worker','evidence_state','fulfillment_state',
            'http_reason','action','mode','upstream_request_id'];
        foreach ($context as $key=>$value) {
            if (in_array($key,$numeric,true) && is_int($value) && $value>=0)
                $fields[$key]=$value;
            elseif (in_array($key,$codes,true) && is_string($value) &&
                preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]{0,95}\z/D',$value)) {
                // Arbitrary exception messages may be account numbers, tokens
                // or raw upstream text. Only machine-shaped known diagnostic
                // reasons can pass; retain class/HTTP/cURL for other failures.
                if ($key==='reason' &&
                    !in_array($value,['archived','pending','unknown','settled',
                        'review','unavailable','monitoring'],true) &&
                    preg_match('/\A(?:achbot|pg|provider|payment|wallet|manual|delivery|addon|native|http|response|claim|check|schema|invoice|original|dispatch|maintenance|worker|batch|source|identity|service|recovery|transport|receipt|upstream|internal|already|operation|remote)_[a-z0-9_]{2,88}\z/D',$value)!==1)
                    continue;
                $fields[$key]=$value;
            }
        }
        error_log('[AChBot] '.json_encode($fields,
            JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR));
    } catch (Throwable $ignored) {
        // Diagnostic sink failure never changes financial/Native decisions.
    }
}
