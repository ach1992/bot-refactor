<?php
/** Real SQL-engine checks only against the private socket created by tools/test_schema.py. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=realpath(__DIR__.'/..');
$dir=realpath($argv[1] ?? '');
if (!$dir || !is_file($dir.'/.achbot-disposable-db') || trim(file_get_contents($dir.'/.achbot-disposable-db'))!=='achbot-v0-fixture-only') {
    throw new RuntimeException('Refusing a non-disposable database target');
}
$socket=$dir.'/mysql.sock';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli('localhost','root','','achbot_v0_fixture',0,$socket);
$settings=$db->query('SELECT @@socket AS socket, @@datadir AS datadir, @@version AS version, @@sql_mode AS sql_mode, @@skip_networking AS skip_networking')->fetch_assoc();
if ($settings['socket']!==$socket || realpath($settings['datadir'])!==realpath($dir.'/data')) throw new RuntimeException('Database target mismatch');
if (!(int)$settings['skip_networking']) throw new RuntimeException('Fixture server has networking enabled');
$db->set_charset('utf8mb4');
function schemaFingerprint(mysqli $db): string {
    $tables=$db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM); $result=[];
    foreach($tables as [$name]) $result[$name]=$db->query('SHOW CREATE TABLE `'.$name.'`')->fetch_row()[1];
    ksort($result);
    // AUTO_INCREMENT counters are runtime data, not schema; omit only the table-option counter.
    foreach($result as &$ddl) $ddl=preg_replace('/ AUTO_INCREMENT=\d+\b/','',$ddl);
    return hash('sha256',json_encode($result));
}
$before=schemaFingerprint($db);
$inventory=json_decode(file_get_contents($root.'/docs/v0/inventory.json'),true,512,JSON_THROW_ON_ERROR);
$seen=[]; $ok=0; $errors=[]; $skipped=0;
foreach($inventory['sql'] as $site) {
    if($site['dynamic'] || !in_array($site['operation'],['SELECT','INSERT','UPDATE','DELETE','REPLACE'],true)) { $skipped++; continue; }
    $sql=$site['source'];
    if(isset($seen[$sql])) continue; $seen[$sql]=true;
    try { $stmt=$db->prepare($sql); $stmt->close(); $ok++; }
    catch(mysqli_sql_exception $e) { $errors[]=['file'=>$site['file'],'line'=>$site['line'],'code'=>$e->getCode(),'message'=>$e->getMessage(),'sql_sha256'=>hash('sha256',$sql),'classification'=>preg_match('/^UPDATE\s+`server_info`\s+SET\s+`[a-z_]+`$/',$sql)?'construction_fragment':'statement_error']; }
}
require $root.'/tools/source_scan.php';
// Load only declarations, never config.php/bootstrap; no Telegram/VPN clients are loaded.
foreach(['processWalletPayment','insertOrder','createIncreaseOrder','duplicateReceiptGuard'] as $name) eval(achbotExtractFunction($root.'/buy_helpers.php',$name));
eval(achbotExtractFunction($root.'/config.php','applyDiscountToPay'));
function alert(...$args) { return null; }
function sendMessage(...$args) { return null; }
function must($ok,string $message): void { if(!$ok) throw new RuntimeException('SQL fixture assertion: '.$message); }
$from_id=1001; $admin=9001;
function resetWallet(mysqli $db,int $balance=50000): void {
    $db->query('DELETE FROM `increase_order`'); $db->query('DELETE FROM `pays`'); $db->query('DELETE FROM `users`');
    $db->query("INSERT INTO users (userid,name,username,refcode,wallet,date) VALUES (1001,'Fixture','fixture_user','0',$balance,'1700000000')");
    $db->query("INSERT INTO pays (hash_id,user_id,type,plan_id,price,request_date,state) VALUES ('fixturehash1',1001,'BUY_SUB',101,12000,1700000000,'pending')");
}
function balances(mysqli $db): array {
    return [(int)$db->query('SELECT wallet FROM users WHERE userid=1001')->fetch_row()[0],$db->query("SELECT state FROM pays WHERE hash_id='fixturehash1'")->fetch_row()[0]];
}
$tests=[];
resetWallet($db);
$r=processWalletPayment($db,'fixturehash1',fn()=>true);
must($r['status']==='ok' && balances($db)===[38000,'paid_with_wallet'],'wallet commits debit and terminal state'); $tests[]='wallet.commit';
$r=processWalletPayment($db,'fixturehash1',fn()=>throw new RuntimeException('must not run twice'));
must($r['status']==='already_paid' && balances($db)===[38000,'paid_with_wallet'],'wallet repeat does not debit'); $tests[]='wallet.repeat';
resetWallet($db);
$r=processWalletPayment($db,'fixturehash1',function() use($db) {
    createIncreaseOrder($db,1001,42,9,'fixture',12000,1700000000,10,31); return false;
});
must($r['status']==='business_failed' && balances($db)===[50000,'pending'] && (int)$db->query('SELECT COUNT(*) FROM increase_order')->fetch_row()[0]===0,'rollback includes handler writes'); $tests[]='wallet.rollback_handler_data';
resetWallet($db);
$r=processWalletPayment($db,'fixturehash1',fn()=>throw new Exception('fixture-failure'));
must($r['status']==='error' && balances($db)===[50000,'pending'],'exception rollback'); $tests[]='wallet.exception';
resetWallet($db,100);
$r=processWalletPayment($db,'fixturehash1',fn()=>throw new RuntimeException('must not debit'));
must($r['status']==='low_wallet' && balances($db)===[100,'low_wallet'],'insufficient balance commits status only'); $tests[]='wallet.low_balance';
$db->query('DELETE FROM orders_list');
insertOrder($db,1001,'fixture-sub-token',101,42,9,'fixture','fixture-uuid','vless',1802678400,'["fixture"]',12000,1700000000,0,1);
$order=$db->query('SELECT userid,status,notif,agent_bought,amount,link FROM orders_list')->fetch_assoc();
must((int)$order['userid']===1001 && (int)$order['status']===1 && (int)$order['notif']===0 && (int)$order['agent_bought']===1 && (int)$order['amount']===12000 && $order['link']==='["fixture"]','order column and bind mapping'); $tests[]='order.bind_mapping';
$db->query('DELETE FROM increase_order');
createIncreaseOrder($db,1001,42,9,'fixture',12000,1700000000,null,31);
$row=$db->query('SELECT volume,day FROM increase_order')->fetch_assoc();
must($row['volume']===null && (int)$row['day']===31,'nullable increase volume remains null'); $tests[]='increase.nullability';
resetWallet($db);
$pay=$db->query("SELECT * FROM pays WHERE hash_id='fixturehash1'")->fetch_assoc();
$db->query("INSERT INTO pays (hash_id,user_id,type,plan_id,price,request_date,state) VALUES ('other-fixture',1001,'BUY_SUB',101,12000,1700000010,'approved')");
must(duplicateReceiptGuard($db,$pay,1700000609)!==null,'receipt below 600-second boundary'); $tests[]='receipt.inside_window';
must(duplicateReceiptGuard($db,$pay,1700000610)===null,'receipt exactly 600-second boundary'); $tests[]='receipt.at_boundary';
$db->query('DELETE FROM discounts');
$db->query("INSERT INTO discounts (hash_id,type,amount,expire_date,expire_count,can_use) VALUES ('FIXTURE20','percent',20,0,10,1)");
$mainValues=['not_valid_discount_code'=>'invalid','valid_discount_code'=>'discount AMOUNT','used_discount_code'=>'USERID DISCOUNTCODE AMOUNT'];
$r=applyDiscountToPay($db,(int)$pay['id'],'FIXTURE20',1001,'fixture_user','Fixture',9001,$mainValues,['rewardChannel'=>'']);
$disc=$db->query('SELECT expire_count,used_by FROM discounts')->fetch_assoc();
must($r['success'] && (int)$r['price']===9600 && (int)$disc['expire_count']===9 && json_decode($disc['used_by'],true)===[1001],'discount writes existing rows'); $tests[]='discount.persist';
$r=applyDiscountToPay($db,(int)$pay['id'],'FIXTURE20',1001,'fixture_user','Fixture',9001,$mainValues,['rewardChannel'=>'']);
must(!$r['success'] && (int)$db->query('SELECT expire_count FROM discounts')->fetch_row()[0]===9,'discount repeated-user limit'); $tests[]='discount.usage_limit';
// These four literals are prefixes, not complete queries. Verify both actual suffixes found in bot.php.
$fragments=array_values(array_filter($inventory['sql'],fn($s)=>preg_match('/^UPDATE\s+`server_info`\s+SET\s+`[a-z_]+`$/',$s['source'])));
must(count($fragments)===4,'dynamic clear-field prefix inventory');
foreach($fragments as $site) {
    $stmt=$db->prepare($site['source'].'=? WHERE `id`=?'); $stmt->close();
    try { $db->prepare($site['source'].' IS NULL WHERE `id`=?'); throw new RuntimeException('Expected legacy clear-field syntax error'); }
    catch(mysqli_sql_exception $e) { must($e->getCode()===1064,'unexpected clear-field error'); }
}
$tests[]='server_edit.composed_value_queries'; $tests[]='server_edit.legacy_clear_queries_rejected';
$after=schemaFingerprint($db);
must($before===$after,'schema definition changed during fixture tests');
$report=['engine_version'=>$settings['version'],'sql_mode'=>$settings['sql_mode'],'tables'=>count($inventory['tables']),
 'prepared_unique_ok'=>$ok,'prepared_unique_failed'=>count($errors),'known_statement_errors'=>count(array_filter($errors,fn($e)=>$e['classification']==='statement_error')),'construction_fragments'=>count($fragments),'skipped_dynamic_or_other_sites'=>$skipped,
 'prepare_failures'=>$errors,'real_engine_tests'=>$tests,'schema_unchanged'=>true,'schema_sha256'=>$before,
 'network_listener'=>false,'production_used'=>false];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
