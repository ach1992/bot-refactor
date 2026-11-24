<?php
declare(strict_types=1);

/**
 * Source-only migration rehearsal helpers. No bootstrap, provider HTTP,
 * Telegram, legacy payment replay, automatic retirement, or activation.
 * Apply only in a separately approved, quiesced and backup-verified window.
 */
if (PHP_SAPI!=='cli') {http_response_code(404);exit(1);}
require_once __DIR__.'/../src/Wallet/Ledger.php';
require_once __DIR__.'/../src/Panel/ProviderStorage.php';

function walletFirstCutoverRows(mysqli $db,string $sql): array {
    $s=achbotProviderStatement($db,$sql);
    try {return $s->get_result()->fetch_all(MYSQLI_ASSOC);}
    finally {$s->close();}
}

/** Exact, signed existing balances. Never infer balances by replaying old
 * receipts, credits, debits or adjustments. Include zero and negative owners. */
function walletFirstCutoverSnapshot(mysqli $db,bool $lock=false): array {
    if (!achbotWalletLedgerReady($db)) throw new RuntimeException('cutover_ledger_schema');
    $seen=[];$digest=hash_init('sha256');$count=0;$total=0;$zero=0;$negative=0;
    foreach (walletFirstCutoverRows($db,'SELECT id,userid,wallet FROM users ORDER BY id'.($lock?' FOR UPDATE':'')) as $r) {
        $id=(int)$r['id'];$uid=(int)$r['userid'];$balance=(int)$r['wallet'];
        if ($id<=0 || $uid<=0 || isset($seen[$uid]) || $r['wallet']===null ||
            $balance<-2147483648 || $balance>2147483647)
            throw new RuntimeException('cutover_owner_identity_ambiguous');
        $seen[$uid]=true;$count++;$total+=$balance;
        if ($balance===0) $zero++;
        if ($balance<0) $negative++;
        hash_update($digest,$id.':'.$uid.':'.$balance."\n");
    }
    return ['owners'=>$count,'sum_toman'=>$total,'zero'=>$zero,'negative'=>$negative,
        'sha256'=>hash_final($digest)];
}

/** Conservative inventory: candidate classifications are NOT decisions.
 * Original pending/unknown receipts can carry real funds even when archived.
 * The caller must separately reconcile each unresolved original identity. */
function walletFirstCutoverAudit(mysqli $db): array {
    if (!$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT))
        throw new RuntimeException('cutover_audit_begin');
    try {
        $snapshot=walletFirstCutoverSnapshot($db);
        $queries=[
            'historical_receipt_or_payment_claim'=>"
                SELECT p.id FROM pays p LEFT JOIN payment_attempts a ON a.pay_row_id=p.id
                WHERE (a.id IS NULL OR a.wallet_invoice_sha256 IS NULL)
                  AND (p.state='processing'
                    OR (p.state NOT IN ('paid','paid_with_wallet','approved','auto_cart_approved')
                        AND (p.state IN ('have_sent','expired_have_sent') OR a.id IS NOT NULL OR COALESCE(p.payid,'')<>''))
                    OR (p.state='paid' AND (a.id IS NULL OR a.stage<>'done'))
                    OR (p.state IN ('approved','paid_with_wallet','auto_cart_approved')
                        AND a.id IS NOT NULL AND a.stage NOT IN ('done','review','expired')))",
            'legacy_automatic_renewal_claim'=>"SELECT id FROM automatic_renewal_attempts WHERE stage<>'done'",
            'legacy_native_effect'=>"SELECT id FROM service_effects
                WHERE COALESCE(source_kind,'')<>'service_operation'
                  AND (state NOT IN ('local_recorded','consumed') OR provider_state IN ('reserved','dispatching','mutating'))",
            'legacy_referral_claim'=>"SELECT id FROM referral_awards
                WHERE service_operation_id IS NULL AND state IN ('NOT_ATTEMPTED','NEEDS_REVIEW')",
            'legacy_delivery_unresolved'=>"SELECT id FROM payment_delivery_intents
                WHERE service_operation_id IS NULL AND state IN ('pending','dispatching','unknown','rejected')",
            'legacy_followup_unresolved'=>"SELECT id FROM payment_followup_intents
                WHERE service_operation_id IS NULL AND state IN ('pending','dispatching','unknown','rejected')",
            'non_pasarguard_configured_server'=>"SELECT id FROM server_config WHERE type<>'pasarguard'",
            'active_order_without_pasarguard_target'=>"SELECT o.id FROM orders_list o
                LEFT JOIN server_config c ON c.id=o.server_id
                WHERE o.status=1 AND (c.id IS NULL OR c.type<>'pasarguard')",
            'unmapped_external_renewal'=>"SELECT id FROM pays WHERE type='RENEW_SCONFIG'
                AND state NOT IN ('paid','paid_with_wallet','approved','auto_cart_approved','cancelled','canceled','rejected','expired','declined')",
        ];
        $inventory=[];
        foreach ($queries as $key=>$query) {
            $n=(int)achbotProviderRow($db,'SELECT COUNT(*) AS n FROM ('.$query.') q')['n'];
            $sample=walletFirstCutoverRows($db,$query.' ORDER BY id LIMIT 50');
            $inventory[$key]=['count'=>$n,'sample_ids'=>array_map('intval',array_column($sample,'id'))];
        }
        // A successful inventory does not assert source replacement, history
        // migration, or readiness to start any runtime.
        $report=['snapshot'=>$snapshot,'inventory'=>$inventory,
            'legacy_claims_clear'=>array_sum(array_column($inventory,'count'))===0,
            'runtime_activation_authorized'=>false];
        $db->rollback();
        return $report;
    } catch (Throwable $e) {$db->rollback();throw $e;}
}

/** Atomic one-opening-per-owner rehearsal. Exact prior snapshot and unique
 * owner constraint are mandatory. A replay is read-only and succeeds only
 * for the identical complete opening set. No partial or live journal rewrite. */
function walletFirstCutoverOpen(mysqli $db,string $expectedSha256): array {
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$expectedSha256)) throw new InvalidArgumentException('cutover_snapshot_required');
    if (!$db->begin_transaction()) throw new RuntimeException('cutover_open_begin');
    try {
        $snap=walletFirstCutoverSnapshot($db,true);
        if (!hash_equals($snap['sha256'],$expectedSha256)) throw new RuntimeException('cutover_snapshot_changed');
        $index=walletFirstCutoverRows($db,"SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='idx_users_userid_v1'");
        if (count($index)!==1 || $index[0]['COLUMN_NAME']!=='userid' ||
            (int)$index[0]['NON_UNIQUE']!==0 || $index[0]['SUB_PART']!==null)
            throw new RuntimeException('cutover_unique_owner_required');
        $total=(int)achbotProviderRow($db,'SELECT COUNT(*) AS n FROM wallet_entries')['n'];
        if ($total===0) {
            $s=achbotProviderStatement($db,"INSERT INTO wallet_entries
                (source_kind,source_ref,leg,user_row_id,userid,delta_toman,balance_before,balance_after,actor_userid,created_at)
                SELECT 'opening',CAST(id AS CHAR),0,id,userid,wallet,0,wallet,0,UTC_TIMESTAMP(6)
                FROM users ORDER BY id");
            $n=$s->affected_rows;$s->close();
            if ($n!==$snap['owners']) throw new RuntimeException('cutover_open_count');
        } elseif ($total!==$snap['owners']) throw new RuntimeException('cutover_existing_journal');
        $match=achbotProviderRow($db,"SELECT COUNT(*) AS n FROM wallet_entries w
            JOIN users u ON u.id=w.user_row_id AND u.userid=w.userid
            WHERE w.source_kind='opening' AND w.source_ref=CAST(u.id AS CHAR)
                AND w.leg=0 AND w.delta_toman=u.wallet AND w.balance_before=0
                AND w.balance_after=u.wallet AND w.actor_userid=0");
        if ((int)$match['n']!==$snap['owners'] || walletFirstCutoverSnapshot($db,true)!==$snap)
            throw new RuntimeException('cutover_open_reconciliation');
        if (!$db->commit()) throw new RuntimeException('cutover_open_commit');
        return ['snapshot'=>$snap,'replayed'=>$total!==0,'runtime_activation_authorized'=>false];
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
