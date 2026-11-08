<?php
/** Retained pre-style byte proof and exact current audience-catalog acceptance. */
require_once dirname(__DIR__).'/messages/loader.php';
$inventory=json_decode(file_get_contents(__DIR__.'/text_catalog_inventory.json'),true,512,JSON_THROW_ON_ERROR);
const CATALOG_STYLE_BASELINE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7';
const CATALOG_MANAGEMENT_BASE='2c0e728dcd71c38e20d176fe2577bc264d3faa1a';
function catalogImmutableTopics(): array {
    static $topics=null;
    if ($topics!==null) return $topics;
    $topics=[];
    $root=dirname(__DIR__);
    $paths=shell_exec('git -C '.escapeshellarg($root).' ls-tree -r --name-only '.
        escapeshellarg(CATALOG_STYLE_BASELINE).' -- messages/fa/');
    if (!is_string($paths)) throw new RuntimeException('pre_style_source_missing');
    foreach (array_filter(explode("\n",trim($paths))) as $path) {
        if (!str_ends_with($path,'.php')) continue;
        $source=shell_exec('git -C '.escapeshellarg($root).' show '.
            escapeshellarg(CATALOG_STYLE_BASELINE.':'.$path));
        $begin=is_string($source)?strpos($source,'return ['):false;
        if ($begin===false) throw new RuntimeException('pre_style_definition_missing');
        // Only the inert array expression from an immutable Git object runs.
        $entries=eval(substr($source,$begin));
        if (!is_array($entries)) throw new RuntimeException('pre_style_definition_invalid');
        $topics[basename($path,'.php')]=$entries;
    }
    if (count($topics)!==25) throw new RuntimeException('pre_style_topic_scope_drift');
    return $topics;
}
function catalogPreStyleMessage(string $key,array $values=[]): string {
    [$topic,$name]=explode('.',$key,2);
    $entry=catalogImmutableTopics()[$topic][$name]??null;
    if ($entry===null) throw new RuntimeException('pre_style_key_missing:'.$key);
    $text=is_array($entry)?$entry['text']:$entry;
    $parameters=is_array($entry)?$entry['parameters']:[];
    if (count($values)!==count($parameters)||array_diff($parameters,array_keys($values)))
        throw new RuntimeException('pre_style_parameters_mismatch');
    $replace=[];
    foreach ($parameters as $parameter) $replace['{'.$parameter.'}']=(string)$values[$parameter];
    return $replace?strtr($text,$replace):$text;
}
function catalogEvaluate(string $_catalogExpression,array $_catalogBindings): string {
    extract($_catalogBindings,EXTR_SKIP);
    // Preserve the original relocation proof against the immutable pre-style
    // definitions. Current presentation is checked separately below.
    $_catalogExpression=str_replace('achbotMessage(','catalogPreStyleMessage(',$_catalogExpression);
    return eval('return '.$_catalogExpression.';');
}
final class CatalogStringProbe {
    private int $calls=0;
    public function __toString(): string {return '<probe '.(++$this->calls).' {time} %s>';}
    public function conversions(): int {return $this->calls;}
}
function catalogBindings(array $expressions,$sample): array {
    $bindings=[];$values=[];
    foreach ($expressions as $expression) {
        if (preg_match('/\\A\'[a-z][a-z0-9_]*\'\\z/D',$expression)) continue; // fixed diagnostic code, not a caller variable

        if (!preg_match('/\A\$([A-Za-z_][A-Za-z0-9_]*)(?:\[(?:([0-9]+)|[\'"]([A-Za-z0-9_]+)[\'"])\])?\z/D',$expression,$m)) {
            throw new RuntimeException('unsupported_fixture_expression:'.$expression);
        }
        if (!array_key_exists($expression,$values)) $values[$expression]=is_object($sample)?clone $sample:$sample;
        $value=$values[$expression];
        if (isset($m[2]) && $m[2]!=='' || isset($m[3]) && $m[3]!=='') {
            $key=isset($m[3])&&$m[3]!==''?$m[3]:(int)$m[2];
            $bindings[$m[1]][$key]=$value;
        } else $bindings[$m[1]]=$value;
    }
    return $bindings;
}
$amendments=json_decode(file_get_contents(__DIR__.'/text_catalog_amendments.json'),true,512,JSON_THROW_ON_ERROR);
$approvedSeen=[];
$checks=0;$templates=0;$interpolated=0;
$samples=[0,42,-77,null,true,"نام <&>\"' {time} %s $ \\\r\n",new CatalogStringProbe()];
foreach ($inventory['files'] as $path=>$file) {
    foreach ($file['edits'] as $edit) {
        if (!empty($edit['formatting'])) continue;
        $templates++;
        if (!empty($edit['inline_html'])) {
            if (catalogPreStyleMessage($edit['key'])!==$edit['original']) throw new RuntimeException('inline_html_mismatch:'.$path);
            $checks++;continue;
        }
        if (isset($amendments['approved_copy'][$edit['key']])) {
            $approved=$amendments['approved_copy'][$edit['key']];
            if ($edit['expressions'] || catalogEvaluate($edit['original'],[])!==$approved['before'] ||
                catalogEvaluate($edit['replacement'],[])!==$approved['after'])
                throw new RuntimeException('unapproved_copy_change:'.$edit['key']);
            $approvedSeen[$edit['key']]=true;
            continue; // Intentional owner-approved wording change, not byte parity.
        }
        $expressions=$edit['expressions'];
        if ($expressions) $interpolated++;
        foreach ($expressions?$samples:[null] as $sample) {
            $expected=catalogEvaluate($edit['original'],catalogBindings($expressions,$sample));
            $actual=catalogEvaluate($edit['replacement'],catalogBindings($expressions,$sample));
            if ($expected!==$actual) throw new RuntimeException('byte_mismatch:'.$path.':'.$edit['line']);
            $checks++;
        }
    }
}
if (count($approvedSeen)!==3 || count($amendments['approved_copy'])!==3)
    throw new RuntimeException('approved_copy_coverage_missing');
// Execute the actual traffic helper against the immutable pre-followup helper.
// Only its provider-read SPI is synthetic; every returned field must match.
function achbotSupportsAccount($type,$inbound): bool {return in_array($type,['marzban','pasarguard'],true)&&$inbound===0;}
function achbotProviderUser($server,$remark,$withLinks=true) {
    $GLOBALS['catalogTrafficReads']++;$GLOBALS['catalogTrafficWithLinks']=$withLinks;
    return $GLOBALS['catalogTrafficInfo'];
}
require_once dirname(__DIR__).'/src/Subscription/AccountDetails.php';
$trafficSource=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($amendments['assignment_base'].':src/Subscription/AccountDetails.php'));
$begin=strpos($trafficSource,'function getOrderTrafficInfo(');
$end=strpos($trafficSource,'function getOrderStatusText(',$begin);
if ($begin===false||$end===false) throw new RuntimeException('traffic_fixture_boundary_drift');
$originalHelper=substr($trafficSource,$begin,$end-$begin);
if (substr_count($originalHelper,'round(($total - $used) / 1073741824, 2)')!==1)
    throw new RuntimeException('traffic_round_expression_drift');
eval('namespace CatalogTrafficBaseline;'.$originalHelper);
// The old relocation proof still compares the actual two immutable source
// versions. The current helper deliberately has a new lifecycle contract.
$relocatedSource=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.
    escapeshellarg(CATALOG_MANAGEMENT_BASE.':src/Subscription/AccountDetails.php'));
$begin=strpos($relocatedSource,'function getOrderTrafficInfo(');
$end=strpos($relocatedSource,'function getOrderStatusText(',$begin);
if ($begin===false||$end===false) throw new RuntimeException('relocated_traffic_boundary_drift');
eval('namespace CatalogTrafficRelocated;'.substr($relocatedSource,$begin,$end-$begin));

$trafficCases=0;
foreach (['marzban','pasarguard'] as $provider) {
    foreach ([
        (object)['username'=>'fixture','status'=>'active','data_limit'=>10*1073741824,'used_traffic'=>3*1073741824],
        (object)['username'=>'fixture','status'=>'disabled','data_limit'=>1073741824,'used_traffic'=>123456789],
        (object)['username'=>'fixture','status'=>'limited','data_limit'=>1073741824,'used_traffic'=>2*1073741824],
        (object)['username'=>'fixture','status'=>'expired','data_limit'=>0,'used_traffic'=>0],
        (object)['username'=>'fixture','status'=>'active','data_limit'=>0.0,'used_traffic'=>123],
        (object)['username'=>'fixture','status'=>'active','data_limit'=>0.005*1073741824,'used_traffic'=>0],
        (object)['username'=>'fixture','status'=>'active','data_limit'=>0.015*1073741824,'used_traffic'=>0],
        (object)['username'=>'fixture','status'=>'unknown'],
        null,
    ] as $info) {
        $GLOBALS['catalogTrafficInfo']=$info;$GLOBALS['catalogTrafficReads']=0;
        $expected=\CatalogTrafficBaseline\getOrderTrafficInfo($provider,2,'fixture','',0);
        $actual=\CatalogTrafficRelocated\getOrderTrafficInfo($provider,2,'fixture','',0);
        if ($actual!==$expected||$GLOBALS['catalogTrafficReads']!==2)
            throw new RuntimeException('traffic_full_helper_byte_parity_failed');
        $trafficCases++;$checks++;
    }
}
// Current customer presentation uses the same confirmed observation for both
// providers. These fixtures are independent of the old byte-parity contract.
$currentTrafficCases=0;$providerTraffic=[];
$finite=(object)['username'=>'fixture','status'=>'active','data_limit'=>10*1073741824,
    'used_traffic'=>3*1073741824,'expire'=>1800000000];
$cases=[
 ['user'=>$finite,'found'=>true,'status'=>'active','remaining'=>7,'actionable'=>true],
 ['user'=>(object)['username'=>'fixture','status'=>'disabled','data_limit'=>1073741824,'used_traffic'=>123456789,'expire'=>0],
  'found'=>true,'status'=>'disabled','remaining'=>round((1073741824-123456789)/1073741824,2),'actionable'=>true],
 ['user'=>(object)['username'=>'fixture','status'=>'limited','data_limit'=>1073741824,'used_traffic'=>2*1073741824,'expire'=>1800000000],
  'found'=>true,'status'=>'limited','remaining'=>0,'actionable'=>true],
 ['user'=>(object)['username'=>'fixture','status'=>'expired','data_limit'=>0,'used_traffic'=>0,'expire'=>1],
  'found'=>true,'status'=>'expired','remaining'=>null,'actionable'=>true],
 ['user'=>(object)['username'=>'fixture','status'=>'active','data_limit'=>null,'used_traffic'=>123,'expire'=>null],
  'found'=>true,'status'=>'active','remaining'=>null,'actionable'=>true],
 ['user'=>(object)['username'=>'fixture','status'=>'on_hold','data_limit'=>0,'used_traffic'=>0,'expire'=>null],
  'found'=>true,'status'=>'on_hold','remaining'=>null,'actionable'=>false],
 ['user'=>(object)array_merge((array)$finite,['username'=>'different']),'found'=>false],
 ['user'=>(object)array_merge((array)$finite,['status'=>'unknown']),'found'=>false],
 ['user'=>(object)['username'=>'fixture','status'=>'active','data_limit'=>0,'used_traffic'=>0],'found'=>false],
 ['user'=>(object)array_merge((array)$finite,['used_traffic'=>-1]),'found'=>false],
 ['user'=>(object)array_merge((array)$finite,['data_limit'=>0.005*1073741824]),'found'=>false],
 ['user'=>(object)['username'=>'fixture','status'=>'active','data_limit'=>0,'expire'=>0],'found'=>false],
 ['user'=>(object)array_merge((array)$finite,['expire'=>-1]),'found'=>false],
 ['user'=>null,'found'=>false],
];
foreach (['marzban','pasarguard']as$provider) {
    foreach ($cases as $index=>$case) {
        $GLOBALS['catalogTrafficInfo']=$case['user'];$GLOBALS['catalogTrafficReads']=0;
        $actual=\AchBot\Subscription\AccountDetails\getOrderTrafficInfo($provider,2,'fixture','',0);
        if ($GLOBALS['catalogTrafficReads']!==1||$GLOBALS['catalogTrafficWithLinks']!==false||
            $actual['found']!==$case['found']||$actual['uncertain']!==!$case['found'])
            throw new RuntimeException('current_traffic_observation_boundary_failed:'.$provider.':'.$index);
        if ($case['found']) {
            $left=$case['remaining']===null?achbotMessage('subscription.account_deletion.no_dont_delete.text.5e6ee5eaa1'):
                achbotMessage('subscription.account_details.remaining_traffic',['remaining_gib'=>$case['remaining']]);
            if ($actual['leftgb']!==$left||$actual['status']!==$case['status']||
                $actual['enable']!==($case['status']==='active')||$actual['actionable']!==$case['actionable'])
                throw new RuntimeException('current_traffic_confirmed_presentation_failed:'.$provider.':'.$index);
            $status=\AchBot\Subscription\AccountDetails\getOrderStatusText($actual,$provider,['active'=>'ACTIVE','deactive'=>'DISABLED']);
            if ($case['status']==='on_hold'&&$status!==achbotMessage('subscription.account_details.status_on_hold'))
                throw new RuntimeException('current_traffic_on_hold_presentation_failed');
        } elseif ($actual['leftgb']!==achbotMessage('subscription.account_details.get_order_traffic_info.text.f07ac0638d')||
            $actual['actionable']!==false||$actual['status']!==null)
            throw new RuntimeException('current_traffic_unknown_presentation_failed');
        $providerTraffic[$provider][$index]=$actual;$currentTrafficCases++;
    }
}
if ($providerTraffic['marzban']!==$providerTraffic['pasarguard'])
    throw new RuntimeException('current_traffic_provider_presentation_diverged');
$templates++;$interpolated++;
$dynamic=null;
foreach(achbotMessageTopics() as $topic) {
    $group=achbotMessages($topic);
    foreach($group as $key=>$entry) if(is_array($entry)&&$entry['parameters']) {$dynamic=[$topic.'.'.$key,$entry];break 2;}
}
if (!$dynamic) throw new RuntimeException('missing_dynamic_coverage');
[$key,$entry]=$dynamic;
$raw=[];$escaped=[];
foreach ($entry['parameters'] as $name) {
    $raw[$name]="<&>\"' {".$name."} %s";
    $escaped[$name]=htmlspecialchars($raw[$name],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
if (achbotMessageHtml($key,$raw)!==achbotMessage($key,$escaped)) throw new RuntimeException('html_escape_mismatch');
if (achbotMessage($key,$raw)!==strtr($entry['text'],array_combine(
    array_map(fn($n)=>'{'.$n.'}',array_keys($raw)),array_values($raw)))) throw new RuntimeException('recursive_interpolation');
foreach ([[],array_merge($raw,['unexpected'=>'value'])] as $bad) {
    try {achbotMessage($key,$bad);throw new RuntimeException('parameter_validation_missing');}
    catch(InvalidArgumentException $expected) {$checks++;}
}
foreach(['../secret.key','unknown','unknown.key'] as $bad) {
    try {achbotMessage($bad);throw new RuntimeException('key_validation_missing');}
    catch(InvalidArgumentException|OutOfBoundsException $expected) {$checks++;}
}
require_once __DIR__.'/catalog_bindings.php';
$currentBindingProof=catalogAssertCurrentBindings(dirname(__DIR__));
$currentBindingNegatives=catalogBindingNegativeControls();
$mainValues=$currentBindingProof['main'];$buttonValues=$currentBindingProof['buttons'];
// The deleted RC13 facade remains immutable historical source evidence.
$facadeSource=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.
    escapeshellarg(CATALOG_MANAGEMENT_BASE.':settings/values.php'));
$archivedFacade=catalogBindingValues($facadeSource,'/../messages/loader.php');
if ($archivedFacade['main']!==$mainValues||$archivedFacade['buttons']!==$buttonValues)
    throw new RuntimeException('archived_facade_payload_mismatch');
$main=catalogImmutableTopics()['legacy_messages'];
$buttons=catalogImmutableTopics()['legacy_buttons'];
$legacy=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($inventory['baseline'].':settings/values.php'));
if (!is_string($legacy)) throw new RuntimeException('legacy_source_missing');
ob_start();eval('?>'.$legacy);ob_end_clean();
if($main!==$mainValues||$buttons!==$buttonValues) throw new RuntimeException('legacy_facade_mismatch');
$checks+=2;
require_once dirname(__DIR__).'/src/Presentation/Labels.php';
if (\AchBot\Presentation\Labels\paymentType('BUY_SUB')!==achbotMessage('labels.labels.payment_type.label.e6343571a4'))
    throw new RuntimeException('namespaced_standalone_load_failed');
$style=json_decode(file_get_contents(__DIR__.'/text_catalog_style_amendments.json'),true,512,JSON_THROW_ON_ERROR);
if (($style['version']??null)!==1||($style['baseline_commit']??null)!==CATALOG_STYLE_BASELINE)
    throw new RuntimeException('style_baseline_drift');
$baseline=[];
foreach (catalogImmutableTopics() as $topic=>$entries)
    foreach ($entries as $key=>$entry) $baseline[$topic.'.'.$key]=$entry;
$current=[];
foreach (achbotMessageTopics() as $topic)
    foreach (achbotMessages($topic) as $key=>$entry) $current[$topic.'.'.$key]=$entry;
$shipping=$current;
// R5 has explicit current edits, additions and retired editable instance bodies.
// Reconstruct the accepted pre-R5 stage for older amendment proofs; every
// actual shipping template still executes in the adversarial loop below.
$r5=json_decode(file_get_contents(__DIR__.'/text_catalog_owner_r5.json'),true,512,JSON_THROW_ON_ERROR);
if(($r5['version']??null)!==1||($r5['baseline_commit']??null)!=='64feca4b777a442023676122e315c68ad26e902d'||
 ($r5['authorization']??null)!=='https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6486592777')
 throw new RuntimeException('r5_definition_identity');
$r5BaseGroups=[];$r5Expected=[];
foreach(['user','admin']as$audience){
 $source=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($r5['baseline_commit'].':messages/fa/'.$audience.'.php'));
 $begin=is_string($source)?strpos($source,'return ['):false;if($begin===false)throw new RuntimeException('r5_baseline_source_missing');
 $r5BaseGroups[$audience]=eval(substr($source,$begin));$r5Expected[$audience]=[];
 foreach($r5BaseGroups[$audience]as$topic=>$entries)foreach($entries as$name=>$value)$r5Expected[$audience][$topic.'.'.$name]=$value;
}
$current=$r5Expected['user']+$r5Expected['admin'];
foreach($r5['changes']as$key=>$record){$audience=$record['audience'];
 if(($r5Expected[$audience][$key]??null)!==$record['before']||empty($record['reason']))throw new RuntimeException('r5_change_before_or_reason');
 $r5Expected[$audience][$key]=$record['after'];
}
foreach($r5['retired_keys']as$key=>$record){$audience=$record['audience'];
 if(($r5Expected[$audience][$key]??null)!==$record['before']||empty($record['reason']))throw new RuntimeException('r5_retirement_before_or_reason');
 unset($r5Expected[$audience][$key]);
}
foreach($r5['added_keys']as$key=>$record){$audience=$record['audience'];
 if(isset($current[$key])||empty($record['source_paths'])||empty($record['reason']))throw new RuntimeException('r5_addition_identity');
 $r5Expected[$audience][$key]=$record['entry'];
}
// Independently verify the exact accepted API additions before projecting back
// to R5. The shipping array above remains complete for every native loader test.
$api=json_decode(file_get_contents(__DIR__.'/text_catalog_owner_r6_api.json'),true,512,JSON_THROW_ON_ERROR);
$apiBase='2d0ad0826d594adac49f5a83f0a9d47f6df5b264';
$apiRefs=['2633ac3c21c539635c228dd31df2e07caee491d0','2c685f5a9621a808c44a4c012b04c8ccd97102af','df00c13da1e38e2ba364ab48e31db66f8705b2c2'];
if(($api['version']??null)!==1||($api['authorization']??null)!=='https://github.com/ach1992/bot-refactor/issues/57#issuecomment-6514754219'||
 ($api['baseline_commit']??null)!==$apiBase||($api['accepted_commits']??null)!==$apiRefs||
 ($api['result_catalog_commit']??null)!==$apiRefs[2]||($api['scope']??null)!=='additions_only')
 throw new RuntimeException('api_catalog_amendment_identity');
$apiSnapshots=[];
foreach([$apiBase,...$apiRefs]as$ref)foreach(['user','admin']as$audience){
 $source=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($ref.':messages/fa/'.$audience.'.php'));
 $begin=is_string($source)?strpos($source,'return ['):false;if($begin===false)throw new RuntimeException('api_catalog_source_missing');
 $groups=eval(substr($source,$begin));$apiSnapshots[$ref][$audience]=[];
 foreach($groups as$topic=>$entries)foreach($entries as$name=>$value)$apiSnapshots[$ref][$audience][$topic.'.'.$name]=$value;
}
$apiDelta=[];
foreach(['user','admin']as$audience){
 $prior=$apiSnapshots[$apiBase][$audience];$accepted=$apiSnapshots[$apiRefs[2]][$audience];
 $expected=$r5Expected[$audience];ksort($prior);ksort($expected);
 if($prior!==$expected)throw new RuntimeException('api_prior_r5_catalog_drift');
 foreach($prior as$key=>$value)if(!array_key_exists($key,$accepted)||$accepted[$key]!==$value)throw new RuntimeException('api_changed_prior_copy');
 foreach(array_diff_key($accepted,$prior)as$key=>$value){
  if(isset($apiDelta[$key]))throw new RuntimeException('api_duplicate_audience_key');
  $apiDelta[$key]=['audience'=>$audience,'entry'=>$value];
 }
}
$apiAdded=$api['added_keys']??[];$apiKeys=array_keys($apiAdded);$apiExpectedKeys=array_keys($apiDelta);sort($apiKeys);sort($apiExpectedKeys);
if(count($apiDelta)!==86||$apiKeys!==$apiExpectedKeys)throw new RuntimeException('api_exact_addition_scope');
$apiUserCount=0;
foreach($apiDelta as$key=>$expected){
 $record=$apiAdded[$key];$audience=$expected['audience'];
 if(($record['audience']??null)!==$audience||($record['entry']??null)!==$expected['entry']||empty($record['reason']))
  throw new RuntimeException('api_accepted_copy_or_audience_drift:'.$key);
 $introduced=null;foreach($apiRefs as$ref)if(array_key_exists($key,$apiSnapshots[$ref][$audience])){$introduced=$ref;break;}
 if(($record['introduced_in']??null)!==$introduced)throw new RuntimeException('api_entry_lineage_drift:'.$key);
 if($audience==='user')$apiUserCount++;
}
if($apiUserCount!==22)throw new RuntimeException('api_audience_counts_drift');
// One explicit current administrator gift report is admitted before restoring
// the unchanged API/R5 stages. Never project arbitrary keys or prefixes away.
$giftKey='gifts.completed_report';
$giftEntry=['text'=>'🎁 هدیهٔ اشتراک اعمال شد'."\n".
 '🔖 شناسه عملیات: <code>{reference}</code>'."\n".
 '👤 اشتراک: <code>{remark}</code>'."\n".
 '📅 زمان هدیه: {days} روز'."\n".
 '📦 حجم هدیه: {volume_mb} مگابایت'."\n".
 '👥 ثبت‌های مرتبط: {reference_count}'."\n".
 '✉️ کاربران مرتبط: {recipient_count}'."\n".
 '⚠️ پیام‌های بدون تأیید ارسال: {notice_unconfirmed_count}',
 'parameters'=>['reference','remark','days','volume_mb','reference_count','recipient_count','notice_unconfirmed_count']];
$shippingSides=[];
foreach(['user','admin']as$audience){$groups=require dirname(__DIR__).'/messages/fa/'.$audience.'.php';
 $shippingSides[$audience]=[];
 foreach($groups as$topic=>$entries)foreach($entries as$name=>$value)$shippingSides[$audience][$topic.'.'.$name]=$value;
}
// #117 adds one 18-key Owner-only recovery topic. Preserve all historical
// native whole-catalogue identity gates: remove ONLY this exact source block
// for their original byte/array proofs. Neither arbitrary new translations,
// missing/reordered placeholders, cross-audience entries nor changes to any
// existing approved catalog can pass this strict overlay.
$deliveryNames=[
 'title','summary','line','warning','check_result','send_result',
 'check_ack','check','request_send','send_confirm','back','back_inbox',
 'next','first','missing','not_eligible','unavailable','open'
];
$sourceAdmin=file_get_contents(dirname(__DIR__).'/messages/fa/admin.php');
$deliveryBlocks=[];
if(!is_string($sourceAdmin) ||
   preg_match_all("~(?ms)^    'delivery_recovery' => \[\n.*?^    \],\n~",
       $sourceAdmin,$deliveryBlocks,PREG_OFFSET_CAPTURE)!==1 ||
   hash('sha256',$deliveryBlocks[0][0][0])!==
       'd10cb333f3576f5386a3f18226f569941a1488de7fae648822c507f8b1e9d4d8')
 throw new RuntimeException('delivery_recovery_exact_additive_source_identity');
$start=$deliveryBlocks[0][0][1];$length=strlen($deliveryBlocks[0][0][0]);
$preDeliveryAdmin=substr($sourceAdmin,0,$start).substr($sourceAdmin,$start+$length);
$deliverySourceCatalog=require dirname(__DIR__).'/messages/fa/admin.php';
if(!isset($deliverySourceCatalog['delivery_recovery']) ||
   array_keys($deliverySourceCatalog['delivery_recovery'])!==$deliveryNames)
 throw new RuntimeException('delivery_recovery_exact_key_inventory');
foreach($deliveryNames as$name){
 $key='delivery_recovery.'.$name;
 if(!array_key_exists($key,$shippingSides['admin']) ||
    array_key_exists($key,$shippingSides['user']))
  throw new RuntimeException('delivery_recovery_owner_only_addition');
 unset($shippingSides['admin'][$key]);
}
// #117 finance exceptions now include signed, human-rejected funded PRIMARY
// evidence, not only extra SECONDARY merchant children. The exact revised
// digest below is restricted to the two new owner-private keys and the
// corrected category labels; source identity remains strict.
// #117's owner-private workbench topics remain strictly
// additive. Hash exact source blocks and prove their complete inventories;
// never rebaseline or weaken the older whole-catalogue controls.
$workbench = [
 'manual_receipt_final'=>[
  'digest'=>'3423d7b6837c1db79e0c1a6d8297f663d6244d5134c3522b98b3891b6749f1df',
  'names'=>['day_subject','volume_subject']],
 'finance_exceptions'=>[
  'digest'=>'2b1c0aa5e61b96967b184d5b7b2c090aa6704920c15999d4b72febccb7cbaf66',
  'names'=>['open','primary_rejected','primary_refund_unverified','title','empty','case_label','case_title',
   'child_line','readonly_warning','inspect','more','back',
   'back_finance','next','first','checked','unavailable']],
 'finance_workbench'=>[
  'digest'=>'ae4989180d45ed15f33e668a9d5e7111551fddd6b681bd10da526de670b233ea',
  'names'=>['denied','checking','back','not_safe','challenge_expired','unavailable',
   'migration_required','preview_button','reject_button','commit_approve','commit_reject',
   'preview_approve','preview_reject','rejected_customer','rejected_report','result',
   'status_completed','status_delivery_unconfirmed','status_rejected','status_unknown','status_unavailable',
   'status_stale','status_already','status_unsupported','approve_original',
   'reject_original','manual_evidence_warning','prior_decision']],
 'service_workbench'=>[
  'digest'=>'feda16d173f3d99b91d9544715c170d4c978a5763e02ffd1164a4e7dc75dbaf8',
  'names'=>['title','empty','case_label','case_title','readonly_warning',
   'open','back','back_finance','next','first','checked','unavailable']],
 'service_recovery_action'=>[
  'digest'=>'37c23110bf7b6696eef5b2c8a552d9bccb519ac830a0094aa876adb57a656360',
  'names'=>['resume_purchase','resume_verified_edit','edit_warning','edit_unverified','resume_warning']],
 // New #117 Owner-only explicit FIRST-time original Hosted CREATE copy.
 // Strictly additive; old pre-RC26 catalogue bytes are still verified.
 'hosted_replacement'=>[
  'digest'=>'11ebe4c79ce6e9b12d730ce6e3c5373ecd05076c0d3fa13d8761730fa84dde19',
  'names'=>['inspect','review_note','warning','confirm','reserved','unavailable']],
 'hosted_original_recovery'=>[
  'digest'=>'f442322538c4ebf091f8ca914667a046ad3ba7431a094de4cb794f25d6574394',
  'names'=>['resume_unstarted','unstarted_warning','started']],
 'service_edit_delivery'=>[
  'digest'=>'32355d7bacf05d19b252482f21c2ea382723a63f44ddb7fb3e89c9e24fa9a8fa',
  'names'=>['header','line','more','warning']],
 'delivery_workbench'=>[
  'digest'=>'0ac67e667fe6669be3a8dae1a90de94dc7347dcb6bc129052f61d190bbf144f3',
  'names'=>['inbox_title','inbox_empty','inbox_case','open_inbox','native_inbox',
   'back_finance','back_inbox']],
];
foreach ($workbench as $topic=>$proof) {
 $pattern="~(?ms)^    '".preg_quote($topic,'~')."' => \[\n.*?^    \],\n~";
 $matches=[];
 if(preg_match_all($pattern,$preDeliveryAdmin,$matches,PREG_OFFSET_CAPTURE)!==1 ||
    hash('sha256',$matches[0][0][0])!==$proof['digest'])
    throw new RuntimeException('workbench_exact_additive_source_identity:'.$topic);
 $start=$matches[0][0][1];$length=strlen($matches[0][0][0]);
 $preDeliveryAdmin=substr($preDeliveryAdmin,0,$start).
     substr($preDeliveryAdmin,$start+$length);
 if(!isset($deliverySourceCatalog[$topic]) ||
    array_keys($deliverySourceCatalog[$topic])!==$proof['names'])
    throw new RuntimeException('workbench_exact_owner_only_key_inventory:'.$topic);
 foreach($proof['names'] as $name) {
    $key=$topic.'.'.$name;
    if(!array_key_exists($key,$shippingSides['admin']) ||
       array_key_exists($key,$shippingSides['user']))
       throw new RuntimeException('workbench_owner_only_key:'.$key);
    unset($shippingSides['admin'][$key]);
 }
}
// Exactly the Owner-approved Chili RC25 copy; preserve the old full-catalog
// proofs by undoing ONLY its exact values in a comparison copy. Source PHP
// is not modified; foreign copy or mixed revisions fail before old checks.
$chiliCopyBase='ed0338baa3b006759ef9fdab66ca6e68defdc9ef';
$chiliCopyHead='4c4004d9b85bc6b1c7297788675d0e917c1f26d0';
$chiliCopyHash=[
 'user'=>'ebc2195d535cc3f3b1cd233ac04b2ef7db22dd6c2a4d8d1ac399f085fdf086e1',
 'admin'=>'0c3e8afb70387969bc7cddc286644d8ce6798e65c4116050c77beccbb1c79b62'
];
$chiliCopyBefore=[];$chiliCopyAfter=[];$chiliCopyChanged=[];
$flattenChili=static function(string $source):array {
 $start=strpos($source,'return [');
 if($start===false)throw new RuntimeException('chili_copy_catalog_missing');
 $groups=eval(substr($source,$start));$flat=[];
 foreach($groups as$topic=>$entries)foreach($entries as$key=>$value)$flat[$topic.'.'.$key]=$value;
 return $flat;
};
foreach($chiliCopyHash as$audience=>$digest){
 $path='messages/fa/'.$audience.'.php';
 $source=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.
    escapeshellarg($chiliCopyHead.':'.$path));
 $parent=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.
    escapeshellarg($chiliCopyBase.':'.$path));
 if(!is_string($source)||!is_string($parent)||
    hash('sha256',$source)!==$digest ||
    hash('sha256',$audience==='admin'?$preDeliveryAdmin:
         file_get_contents(dirname(__DIR__).'/'.$path))!==$digest)
    throw new RuntimeException('chili_copy_exact_source_identity:'.$path);
 $chiliCopyBefore[$audience]=$flattenChili($parent);
 $chiliCopyAfter[$audience]=$flattenChili($source);
 if(array_keys($chiliCopyBefore[$audience])!==array_keys($chiliCopyAfter[$audience]))
    throw new RuntimeException('chili_copy_catalog_inventory');
 $chiliCopyChanged[$audience]=[];
 foreach($chiliCopyBefore[$audience] as$key=>$value)
    if($value!==$chiliCopyAfter[$audience][$key])$chiliCopyChanged[$audience][]=$key;
}
if(count($chiliCopyChanged['user'])!==61 || count($chiliCopyChanged['admin'])!==22)
    throw new RuntimeException('chili_copy_exact_value_change_count');
$restoreChiliCopy=static function(array $sides)use($chiliCopyBefore,$chiliCopyAfter,$chiliCopyChanged):array {
 $isCurrent=true;$isBefore=true;
 foreach($chiliCopyChanged as$audience=>$keys)foreach($keys as$key){
    if(($sides[$audience][$key]??null)!==$chiliCopyAfter[$audience][$key])$isCurrent=false;
    if(($sides[$audience][$key]??null)!==$chiliCopyBefore[$audience][$key])$isBefore=false;
 }
 if(!$isCurrent && !$isBefore)throw new RuntimeException('chili_copy_value_or_audience_drift');
 if($isCurrent)foreach($chiliCopyChanged as$audience=>$keys)foreach($keys as$key)
    $sides[$audience][$key]=$chiliCopyBefore[$audience][$key];
 return $sides;
};
// Explicit negative controls: no mixed versions or modified copy values.
$testCopy=$shippingSides;$alterKey=$chiliCopyChanged['user'][0];
$testCopy['user'][$alterKey]='an-unapproved-value';
try{$restoreChiliCopy($testCopy);throw new RuntimeException('chili_copy_negative_control_missed');}
catch(RuntimeException $error){
 if($error->getMessage()!=='chili_copy_value_or_audience_drift')throw $error;
}

// #112 Owner Rev 4: pin each hard-delete, state and plan-visibility message
// against its prior source before reusing the untouched full R5/API proofs.
$removalParent='218319313fdbd9cee8aeea15e1c073997f4485e7';
$removalKeys=[
 'admin_settings.management.server_delete_confirm',
 'admin_settings.management.server_removed',
 'admin_settings.management.server_retired',
 'admin_settings.management.server_state_prompt',
 'admin_settings.management.server_state_full',
 'admin_settings.management.server_state_renew_only',
 'admin_settings.management.server_state_off',
 'admin_settings.management.plan_hide_from_sale',
 'admin_settings.management.plan_offer_for_sale',
 'admin_settings.management.plan_delete_confirm',
 'admin_settings.management.plan_deleted'
];
$removalManifest=json_decode(file_get_contents(__DIR__.'/text_catalog_server_removal_safety.json'),true,512,JSON_THROW_ON_ERROR);
if (($removalManifest['version']??null)!==1 ||
    array_keys($removalManifest)!==['version','owner_issue','previous_candidate','entries','retained_unreferenced_keys'] ||
    ($removalManifest['retained_unreferenced_keys']??null)!==[
      'bot.bot.addserver_name.alert.601504ae47',
      'bot.bot.addserver_name.message.756c3005d3',
      'bot.bot.servers_setting.alert.9f4e68eda7',
      'plans.administration.edit_custom_plan.alert.3064bbd794',
      'plans.administration.edit_custom_plan.message.b059640ccc'] ||
    ($removalManifest['previous_candidate']??null)!==$removalParent ||
    ($removalManifest['owner_issue']??null)!=='https://github.com/ach1992/bot-refactor/issues/112' ||
    !is_array($removalManifest['entries']??null) ||
    array_keys($removalManifest['entries'])!==$removalKeys)
 throw new RuntimeException('server_removal_catalog_identity');
$removalBeforeSource=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).
 ' show '.escapeshellarg($removalParent.':messages/fa/admin.php'));
$begin=is_string($removalBeforeSource)?strpos($removalBeforeSource,'return ['):false;
if($begin===false)throw new RuntimeException('server_removal_parent_unavailable');
$removalBeforeGroups=eval(substr($removalBeforeSource,$begin));
$removalBefore=[];
foreach($removalBeforeGroups as$topic=>$entries)foreach($entries as$key=>$value)
 $removalBefore[$topic.'.'.$key]=$value;
$removalSourceLines=static function(string $source):array {
 $topic=null;$lines=[];
 foreach(explode("\n",$source)as$line){
  if(preg_match("~^    '([^']+)'\\s*=>\\s*\\[$~u",$line,$head)){
   $topic=$head[1];continue;
  }
  if($topic!==null && preg_match("~^        '([^']+)'\\s*=>~u",$line,$item)){
   $key=$topic.'.'.$item[1];
   if(isset($lines[$key]))throw new RuntimeException('server_removal_source_duplicate:'.$key);
   $lines[$key]=$line;
  }
 }
 return $lines;
};
$removalOriginalLines=$removalSourceLines($removalBeforeSource);
$removalCurrentLines=$removalSourceLines(file_get_contents(dirname(__DIR__).'/messages/fa/admin.php'));
foreach($removalManifest['entries']as$key=>$item){
 if(!is_array($item) ||
    array_keys($item)!==['audience','before_sha256','after_sha256','reason'] ||
    $item['audience']!=='admin' || empty($item['reason']) ||
    array_key_exists($key,$shippingSides['user']))
  throw new RuntimeException('server_removal_catalog_scope_or_audience:'.$key);
 foreach([[$removalOriginalLines,'before_sha256'],[$removalCurrentLines,'after_sha256']]as[$lines,$field]){
  $sha=isset($lines[$key])?hash('sha256',$lines[$key]):null;
  if($sha!==$item[$field] || ($field==='after_sha256' && $sha===null))
   throw new RuntimeException('server_removal_catalog_source_drift:'.$key);
 }
}
$restoreServerRemoval=static function(array $sides) use($removalManifest,$removalBefore,$shippingSides):array {
 foreach($removalManifest['entries']as$key=>$item){
  if(!array_key_exists($key,$sides['admin']) ||
     $sides['admin'][$key]!==$shippingSides['admin'][$key] ||
     array_key_exists($key,$sides['user']))
   throw new RuntimeException('server_removal_catalog_copy_or_audience_drift:'.$key);
  if(array_key_exists($key,$removalBefore)){
   if($item['before_sha256']===null)
    throw new RuntimeException('server_removal_catalog_parent_identity:'.$key);
   $sides['admin'][$key]=$removalBefore[$key];
  }else{
   if($item['before_sha256']!==null)
    throw new RuntimeException('server_removal_catalog_added_identity:'.$key);
   unset($sides['admin'][$key]);
  }
 }
 return $sides;
};
$reviewSource='16c1558322b578b8ca817f3c4b42818d57dcf161';$reviewEntries=[];
foreach(['user'=>['payment.api_payment.code_acknowledgement','payment.api_payment.code_too_long'],'admin'=>['manual_payment.api_payment.auto_approved','manual_payment.api_payment.code_evidence','manual_payment.api_payment.code_used','manual_payment.api_payment.code_revoked','manual_payment.api_payment.code_expired','manual_payment.api_payment.code_mismatch']]as$audience=>$keys){
 $source=shell_exec('git show '.escapeshellarg($reviewSource.':messages/fa/'.$audience.'.php'));
 $begin=is_string($source)?strpos($source,'return ['):false;if($begin===false)throw new RuntimeException('review_catalog_source_missing');
 $groups=eval(substr($source,$begin));$flat=[];foreach($groups as$topic=>$entries)foreach($entries as$name=>$value)$flat[$topic.'.'.$name]=$value;
 foreach($keys as$key)$reviewEntries[$audience][$key]=$flat[$key];
}
// Issue #107 introduces only 14 explicit current admin templates. Validate
// the entire known addition set before restoring the original frozen proofs.
$addonManifest=json_decode(file_get_contents(__DIR__.'/text_catalog_addon_scopes.json'),true,512,JSON_THROW_ON_ERROR);
if(($addonManifest['version']??null)!==1 ||
   ($addonManifest['project_issue']??null)!=='https://github.com/ach1992/bot-refactor/issues/107' ||
   ($addonManifest['audience']??null)!=='admin' ||
   ($addonManifest['topic']??null)!=='addon_scopes' ||
   !is_array($addonManifest['entries']??null) || count($addonManifest['entries'])!==14)
 throw new RuntimeException('addon_catalog_manifest_identity_drift');
$addonEntries=$addonManifest['entries'];
// Current-only Owner-requested quantity-first labels are a strict 3-key
// overlay, not permission to rewrite frozen R5/API catalog values. Check
// against the exact previously accepted RC22 source before restoration.
$selectionManifest=json_decode(file_get_contents(__DIR__.'/text_catalog_addon_selection_ui.json'),true,512,JSON_THROW_ON_ERROR);
$selectionParent='4d7d1c04f15e5d58eb9c8030fc086b8658ba6c49';
$selectionKeys=['renewal.extensions.button.fbb9f61105',
 'renewal.extensions.button.249377f2b7','renewal.extensions.button.8405a4154f'];
$selectionEntries=$selectionManifest['entries']??null;
if(($selectionManifest['version']??null)!==1 ||
   ($selectionManifest['accepted_parent']??null)!==$selectionParent ||
   ($selectionManifest['owner_decision']??null)!==
    'https://github.com/ach1992/bot-refactor/issues/107#issuecomment-6594462582' ||
   ($selectionManifest['audience']??null)!=='user' ||
   !is_array($selectionEntries) || array_keys($selectionEntries)!==$selectionKeys)
 throw new RuntimeException('addon_selection_manifest_identity');
$oldSelectionSource=shell_exec('git show '.escapeshellarg($selectionParent.':messages/fa/user.php'));
$oldSelectionStart=is_string($oldSelectionSource)?strpos($oldSelectionSource,'return ['):false;
if($oldSelectionStart===false)throw new RuntimeException('addon_selection_parent_source_unavailable');
$oldSelectionGroups=eval(substr($oldSelectionSource,$oldSelectionStart));$oldSelection=[];
foreach($oldSelectionGroups as$topic=>$entries)foreach($entries as$name=>$value)
 $oldSelection[$topic.'.'.$name]=$value;
foreach($selectionEntries as$key=>$item){
 if(!is_array($item) || ($item['source_paths']??null)!==['src/Finance/Routes/Extensions.php'] ||
    ($item['before']??null)!==($oldSelection[$key]??null) ||
    ($item['before']??null)===($item['after']??null))
  throw new RuntimeException('addon_selection_parent_value_or_source_drift:'.$key);
}
// #112: independently pin and restore ONLY the Owner-approved common-panel
// transfer copy. Keep the original whole-catalogue API/R5/Gift checks and
// their negative-control error classifications unchanged.
$transferManifest=json_decode(file_get_contents(__DIR__.'/text_catalog_shared_transfer.json'),true,512,JSON_THROW_ON_ERROR);
$transferBase='b654336d92a7e8e994e24d32144e995b5dba1407';
if(($transferManifest['version']??null)!==1 ||
   ($transferManifest['accepted_source_commit']??null)!==$transferBase ||
   ($transferManifest['owner_issue']??null)!=='https://github.com/ach1992/bot-refactor/issues/112' ||
   !is_array($transferManifest['entries']??null) ||
   count($transferManifest['entries'])!==11)
 throw new RuntimeException('shared_transfer_catalog_identity');
$transferBaseline=[];
$transferSource=[];
foreach(['admin','user']as$audience){
 $path='messages/fa/'.$audience.'.php';
 $source=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).
    ' show '.escapeshellarg($transferBase.':'.$path));
 $begin=is_string($source)?strpos($source,'return ['):false;
 if($begin===false)throw new RuntimeException('shared_transfer_baseline_unavailable');
 $groups=eval(substr($source,$begin));$transferBaseline[$audience]=[];
 foreach($groups as$topic=>$values)foreach($values as$name=>$value)
  $transferBaseline[$audience][$topic.'.'.$name]=$value;
 $transferSource[$audience]=[
    'before'=>explode("\n",$source),
    'after'=>explode("\n",file_get_contents(dirname(__DIR__).'/'.$path))
 ];
}
foreach($transferManifest['entries']as$key=>$item){
 $audience=$item['audience']??'';
 if(!in_array($audience,['admin','user'],true) ||
    array_keys($item)!==['audience','before_sha256','after_sha256'] ||
    array_key_exists($key,$shippingSides[$audience==='admin'?'user':'admin']))
  throw new RuntimeException('shared_transfer_key_audience_invalid');
 [$sourceTopic,$sourceKey]=explode('.',$key,2);
 foreach(['before','after']as$revision){
  $match=[];$currentTopic=null;
  foreach($transferSource[$audience][$revision]as$line){
   if(preg_match("~^    '([^']+)'\\s*=>\\s*\\[$~u",$line,$heading)){
    $currentTopic=$heading[1];
    continue;
   }
   if($currentTopic===$sourceTopic &&
      preg_match('~^        '.preg_quote("'".$sourceKey."'","~").'\s*=>~u',$line))
    $match[]=$line;
  }
  if(count($match)>1)throw new RuntimeException('shared_transfer_source_duplicate:'.$key);
  $hash=count($match)===1?hash('sha256',$match[0]):null;
  if($hash!==$item[$revision.'_sha256'])
   throw new RuntimeException('shared_transfer_source_sha256_mismatch:'.$key);
 }
}
$restoreSharedTransfer=static function(array $sides) use($transferManifest,$transferBaseline,$shippingSides):array {
 foreach($transferManifest['entries']as$key=>$item){
  $audience=$item['audience'];
  $newExists=$item['after_sha256']!==null;
  if(array_key_exists($key,$sides[$audience])!==$newExists ||
     ($newExists && $sides[$audience][$key]!==$shippingSides[$audience][$key]))
   throw new RuntimeException('shared_transfer_approved_value_drift:'.$key);
  if($item['before_sha256']!==null){
   if(!array_key_exists($key,$transferBaseline[$audience]))
    throw new RuntimeException('shared_transfer_before_baseline_missing:'.$key);
   $sides[$audience][$key]=$transferBaseline[$audience][$key];
  } else unset($sides[$audience][$key]);
 }
 return $sides;
};
// #114: exact, Owner-approved new group administration wording. Validate
// original and shipping line hashes and restore these three values ONLY in
// a local comparison copy, retaining all immutable R5/API negative controls.
$panelGroupManifest=json_decode(file_get_contents(__DIR__.'/text_catalog_panel_group_ownership.json'),true,512,JSON_THROW_ON_ERROR);
$panelGroupKeys=['bot.bot.addserver_name.message.c31e264971',
 'bot.bot.addserver_name.alert.27e6f9c466',
 'bot.bot.addserver_name.alert.9ee9f25355',
 'bot.bot.addserver_name.alert.a5625b915f'];
$panelGroupParent='8c0af0899051ed60253fe5d40301dfdbb1d2e5de';
if(($panelGroupManifest['version']??null)!==1 ||
   ($panelGroupManifest['accepted_parent']??null)!==$panelGroupParent ||
   ($panelGroupManifest['owner_issue']??null)!=='https://github.com/ach1992/bot-refactor/issues/114' ||
   ($panelGroupManifest['audience']??null)!=='admin' ||
   !is_array($panelGroupManifest['entries']??null) ||
   count($panelGroupManifest['entries'])!==4 ||
   array_diff(array_keys($panelGroupManifest['entries']),$panelGroupKeys) ||
   array_diff($panelGroupKeys,array_keys($panelGroupManifest['entries'])))
 throw new RuntimeException('panel_group_catalog_manifest_identity');
$panelGroupOld=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.
    escapeshellarg($panelGroupParent.':messages/fa/admin.php'));
$panelGroupNew=file_get_contents(dirname(__DIR__).'/messages/fa/admin.php');
$groupStart=is_string($panelGroupOld)?strpos($panelGroupOld,'return ['):false;
if($groupStart===false || !is_string($panelGroupNew))
 throw new RuntimeException('panel_group_catalog_baseline_missing');
$priorGroups=eval(substr($panelGroupOld,$groupStart));
$priorPanelCopy=[];
foreach($priorGroups as$topic=>$values)foreach($values as$name=>$value)
 $priorPanelCopy[$topic.'.'.$name]=$value;
$extractPanelLines=static function(string $source):array {
 $topic=null;$lines=[];
 foreach(explode("\n",$source)as$line){
  if(preg_match("~^    '([^']+)'\\s*=>\\s*\\[$~u",$line,$match)){
   $topic=$match[1];continue;
  }
  if($topic!==null && preg_match("~^        '([^']+)'\\s*=>~u",$line,$match)){
   $key=$topic.'.'.$match[1];
   if(isset($lines[$key]))throw new RuntimeException('panel_group_catalog_duplicate_source_key');
   $lines[$key]=$line;
  }
 }
 return $lines;
};
$panelOldLines=$extractPanelLines($panelGroupOld);
$panelNewLines=$extractPanelLines($panelGroupNew);
foreach($panelGroupManifest['entries']as$key=>$item){
 if(!is_array($item) || array_keys($item)!==['before_sha256','after_sha256','reason'] ||
    !is_string($item['reason']) || $item['reason']==='' ||
    !array_key_exists($key,$priorPanelCopy) ||
    !array_key_exists($key,$shippingSides['admin']) ||
    array_key_exists($key,$shippingSides['user']))
  throw new RuntimeException('panel_group_catalog_approved_entry_shape:'.$key);
 $beforeHash=isset($panelOldLines[$key])?hash('sha256',$panelOldLines[$key]):null;
 $afterHash=isset($panelNewLines[$key])?hash('sha256',$panelNewLines[$key]):null;
 if($beforeHash!==$item['before_sha256'] || $afterHash!==$item['after_sha256'] ||
    $beforeHash===$afterHash)
  throw new RuntimeException('panel_group_catalog_approved_line_drift:'.$key);
}
$restorePanelGroup=static function(array $sides) use($panelGroupManifest,$priorPanelCopy,$shippingSides):array {
 foreach($panelGroupManifest['entries']as$key=>$item){
  if(!array_key_exists($key,$sides['admin']) || array_key_exists($key,$sides['user']) ||
     $sides['admin'][$key]!==$shippingSides['admin'][$key])
   throw new RuntimeException('panel_group_catalog_approved_value_drift:'.$key);
  $sides['admin'][$key]=$priorPanelCopy[$key];
 }
 return $sides;
};

$verifyCurrentAudienceCatalogs=static function(array $sides) use($giftKey,$giftEntry,$apiDelta,$r5Expected,$reviewEntries,$addonEntries,$selectionEntries,$restoreSharedTransfer,$restoreServerRemoval,$restorePanelGroup,$restoreChiliCopy): void {
 if(!isset($sides['user'],$sides['admin']) || !is_array($sides['user']) || !is_array($sides['admin']))
  throw new RuntimeException('addon_catalog_audiences_invalid');
 $sides=$restoreChiliCopy($sides);
 $sides=$restoreSharedTransfer($restoreServerRemoval($restorePanelGroup($sides)));
 foreach($sides['user']as$key=>$value)if(str_starts_with($key,'addon_scopes.'))
  throw new RuntimeException('addon_catalog_cross_audience');
 foreach($selectionEntries as$key=>$item){
  if(!array_key_exists($key,$sides['user']) || $sides['user'][$key]!==$item['after'] ||
     array_key_exists($key,$sides['admin']))
   throw new RuntimeException('addon_selection_copy_or_audience_drift:'.$key);
  $sides['user'][$key]=$item['before'];
 }
 $actualAddonKeys=array_values(array_filter(array_keys($sides['admin']),
  static fn(string $key):bool=>str_starts_with($key,'addon_scopes.')));
 $expectedAddonKeys=array_map(static fn(string $name):string=>'addon_scopes.'.$name,array_keys($addonEntries));
 sort($actualAddonKeys,SORT_STRING);sort($expectedAddonKeys,SORT_STRING);
 if($actualAddonKeys!==$expectedAddonKeys)throw new RuntimeException('addon_catalog_exact_key_inventory');
 foreach($addonEntries as$name=>$item) {
  $key='addon_scopes.'.$name;
  if(!is_array($item) || array_keys($item)!==['value','source_paths'] ||
     ($item['source_paths']??null)!==['src/Plans/AddonAdmin.php'] ||
     !array_key_exists($key,$sides['admin']) || $sides['admin'][$key]!==$item['value'])
   throw new RuntimeException('addon_catalog_value_or_source_drift');
  unset($sides['admin'][$key]);
 }
 foreach($reviewEntries as$audience=>$entries)foreach($entries as$key=>$value){
  if(($sides[$audience][$key]??null)!==$value || array_key_exists($key,$sides[$audience==='user'?'admin':'user']))throw new RuntimeException('review_catalog_value_parameters_or_audience_drift');
  unset($sides[$audience][$key]);
 }

 if(array_key_exists($giftKey,$sides['user'])||($sides['admin'][$giftKey]??null)!==$giftEntry)
  throw new RuntimeException('gift_report_value_parameter_or_audience_drift');
 unset($sides['admin'][$giftKey]);
 foreach(['user','admin']as$audience){$actual=$sides[$audience];
  foreach($apiDelta as$key=>$record)if($record['audience']===$audience){
   if(!array_key_exists($key,$actual)||$actual[$key]!==$record['entry'])throw new RuntimeException('api_actual_copy_or_parameters_drift:'.$key);
   unset($actual[$key]);
  }
  ksort($actual);$expected=$r5Expected[$audience];ksort($expected);
  if($actual!==$expected)throw new RuntimeException('r5_undeclared_copy_or_recipient_change');
 }
};
// Positive admission first ensures the new legitimate entry cannot make every
// mutation below pass merely because the unmodified shipping catalog fails.
$verifyCurrentAudienceCatalogs($shippingSides);
$giftControls=[];
$bad=$shippingSides;unset($bad['admin'][$giftKey]);
$giftControls['missing_report']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;$bad['user'][$giftKey]=$bad['admin'][$giftKey];unset($bad['admin'][$giftKey]);
$giftControls['wrong_audience']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;$bad['admin'][$giftKey]['text'].=' changed';
$giftControls['changed_value']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;array_pop($bad['admin'][$giftKey]['parameters']);
$giftControls['missing_parameter']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;$bad['admin'][$giftKey]['parameters']=array_reverse($bad['admin'][$giftKey]['parameters']);
$giftControls['reordered_parameters']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;$bad['admin']['gifts.unapproved']='changed';
$giftControls['unapproved_extra_key']=[$bad,'r5_undeclared_copy_or_recipient_change'];
$bad=$shippingSides;$bad['user'][$giftKey]=$bad['admin'][$giftKey];
$giftControls['duplicate_cross_audience_key']=[$bad,'gift_report_value_parameter_or_audience_drift'];
$bad=$shippingSides;$bad['user']['legacy_messages.banned']='changed';
$giftControls['prior_catalog_mutation']=[$bad,'r5_undeclared_copy_or_recipient_change'];
// Separate current scoped-addon controls preserve the accepted gift proof count.
$addonControls=[];
$bad=$shippingSides;unset($bad['admin']['addon_scopes.offer_saved']);
$addonControls['addon_missing_key']=[$bad,'addon_catalog_exact_key_inventory'];
$bad=$shippingSides;$bad['admin']['addon_scopes.offer_saved'].=' changed';
$addonControls['addon_changed_value']=[$bad,'addon_catalog_value_or_source_drift'];
$bad=$shippingSides;$bad['user']['addon_scopes.offer_saved']=$bad['admin']['addon_scopes.offer_saved'];
$addonControls['addon_cross_audience']=[$bad,'addon_catalog_cross_audience'];
$bad=$shippingSides;$bad['admin']['addon_scopes.unapproved']='undocumented';
$addonControls['addon_extra_key']=[$bad,'addon_catalog_exact_key_inventory'];
foreach($giftControls + $addonControls as$name=>[$candidate,$expectedReason]){
 try {$verifyCurrentAudienceCatalogs($candidate);}
 catch(RuntimeException $error){
  if($error->getMessage()!==$expectedReason)
   throw new RuntimeException('gift_catalog_negative_wrong_reason:'.$name,0,$error);
  continue;
 }
 throw new RuntimeException('gift_catalog_negative_control_missed:'.$name);
}

// Negative controls prove the new bounded R1 copy layer is not a blanket
// exception for removed keys, changed values, or cross-audience injection.
$removalControls=[];
$changed=$shippingSides;unset($changed['admin']['admin_settings.management.server_retired']);
$removalControls['missing_retire_confirmation']=$changed;
$changed=$shippingSides;$changed['admin']['admin_settings.management.server_delete_confirm'].=' unexpected';
$removalControls['changed_delete_warning']=$changed;
$changed=$shippingSides;
$changed['user']['admin_settings.management.server_retired']=$changed['admin']['admin_settings.management.server_retired'];
unset($changed['admin']['admin_settings.management.server_retired']);
$removalControls['wrong_audience']=$changed;
$changed=$shippingSides;
$changed['user']['admin_settings.management.server_removed']=$changed['admin']['admin_settings.management.server_removed'];
$removalControls['duplicate_audience']=$changed;
foreach($removalControls as$name=>$candidate){
 try{$verifyCurrentAudienceCatalogs($candidate);}
 catch(RuntimeException $error){
  if(str_starts_with($error->getMessage(),'server_removal_catalog_copy_or_audience_drift:'))
   continue;
  throw new RuntimeException('server_removal_negative_wrong_reason:'.$name,0,$error);
 }
 throw new RuntimeException('server_removal_negative_control_missed:'.$name);
}

$management=json_decode(file_get_contents(__DIR__.'/text_catalog_management_amendment.json'),true,512,JSON_THROW_ON_ERROR);
if (($management['version']??null)!==1||($management['baseline_commit']??null)!==CATALOG_MANAGEMENT_BASE||
    ($management['authorization']??null)!=='docs/post-v9/UNIFIED-SERVICE-MANAGEMENT.md')
    throw new RuntimeException('management_catalog_identity_drift');
$managementAdded=$management['added_keys']??[];
foreach ($managementAdded as $key=>$record) {
    if (!str_starts_with($key,'admin_settings.management.')&&!in_array($key,['subscription.account_details.auto_renew_update_unverified','subscription.account_deletion.shared_effect_notice','subscription.account_details.status_on_hold','subscription.manual_accounts.import_unverified','subscription.manual_accounts.import_under_review','subscription.manual_accounts.import_completed','renewal.extensions.plan_selection_unavailable','renewal.extensions.plan_selection_review','subscription.presentation.config_qr_delivery_unconfirmed','subscription.presentation.config_qr_name_suffix','subscription.manual_accounts.customer_not_found','subscription.account_deletion.report'],true))
        throw new RuntimeException('management_catalog_addition_scope_drift');
    $audience=in_array($key,['subscription.account_details.auto_renew_update_unverified','subscription.account_deletion.shared_effect_notice','subscription.account_details.status_on_hold','subscription.manual_accounts.import_unverified','subscription.manual_accounts.import_under_review','subscription.manual_accounts.import_completed','renewal.extensions.plan_selection_unavailable','renewal.extensions.plan_selection_review','subscription.presentation.config_qr_delivery_unconfirmed','subscription.presentation.config_qr_name_suffix'],true)?'user':'admin';
    $sides=$r5BaseGroups[$audience];
    [$topic,$name]=explode('.',$key,2);
    if (($record['audience']??null)!==$audience||($sides[$topic][$name]??null)!==($record['entry']??null)||empty($record['reason']))
        throw new RuntimeException('management_catalog_recipient_or_payload_drift:'.$key);
}

// Owner follow-up is an exact immutable before/after amendment, independent
// of the preserved historical style proof. Every actual final template below
// still executes through the real shipping loader.
$owner=json_decode(file_get_contents(__DIR__.'/text_catalog_owner_followup.json'),true,512,JSON_THROW_ON_ERROR);
if (($owner['version']??null)!==1||($owner['baseline_commit']??null)!=='c9959811e264b5a8728f6a44376cb69c9a89b7f4'||
    ($owner['result_commit']??null)!=='faa9aa6502a4e24152f1fa6faad7f0ab7921652e'||
    ($owner['authorization']??null)!=='docs/post-v9/UNIFIED-SERVICE-MANAGEMENT.md')
    throw new RuntimeException('owner_followup_identity_drift');
$ownerSides=[];
foreach (['baseline_commit','result_commit']as$side) {
    $ownerSides[$side]=[];
    foreach (['user','admin']as$audience) {
        $source=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($owner[$side].':messages/fa/'.$audience.'.php'));
        $begin=is_string($source)?strpos($source,'return ['):false;
        if ($begin===false)throw new RuntimeException('owner_followup_immutable_source_missing');
        $entries=eval(substr($source,$begin));
        foreach ($entries as$topic=>$definitions)foreach($definitions as$name=>$entry)
            $ownerSides[$side][$topic.'.'.$name]=['audience'=>$audience,'entry'=>$entry];
    }
}
$ownerChanges=$owner['changes']??[];$ownerExpected=[];
foreach ($ownerSides['baseline_commit']as$key=>$record) {
    $expected=$ownerSides['result_commit'][$key]??null;
    if (!is_array($expected)||$record['audience']!==$expected['audience']||($current[$key]??null)!==$expected['entry'])
        throw new RuntimeException('owner_followup_actual_result_drift:'.$key);
    if($record['entry']!==$expected['entry']) {
        $ownerExpected[]=$key;$change=$ownerChanges[$key]??null;
        if(!is_array($change)||($change['before']??null)!==$record['entry']||($change['after']??null)!==$expected['entry']||
            ($change['audience']??null)!==$record['audience']||empty($change['reason']))
            throw new RuntimeException('owner_followup_exact_definition_drift:'.$key);
        $declared=$r5BaseGroups[$record['audience']];
        [$topic,$name]=explode('.',$key,2);
        if(($declared[$topic][$name]??null)!==$expected['entry'])
            throw new RuntimeException('owner_followup_recipient_drift:'.$key);
    }
}
$ownerKeys=array_keys($ownerChanges);sort($ownerKeys);sort($ownerExpected);
if(count($ownerExpected)!==7||$ownerKeys!==$ownerExpected)throw new RuntimeException('owner_followup_scope_drift');

$changes=$style['changes']??[];
$retired=$style['retired_keys']??[];
$added=$style['added_keys']??[];
$expectedRetired=array_filter(array_keys($baseline),static fn($key)=>
    str_starts_with($key,'admin_settings.config.convert_persian_digits_to_english_for_stats.')||
    str_starts_with($key,'admin_settings.config.parse_stats_date_range.text.'));
sort($expectedRetired);$retiredKeys=array_keys($retired);sort($retiredKeys);
if (count($expectedRetired)!==23||$retiredKeys!==array_values($expectedRetired))
    throw new RuntimeException('implementation_token_retirement_scope_drift');
$approvedAdded='purchase.order_writes.ensure_account_creation_succeeded.admin_message.inbound_missing';
if (array_keys($added)!==[$approvedAdded])
    throw new RuntimeException('admin_diagnostic_addition_scope_drift');
$featureRetired=$style['retired_feature_keys']??[];
$expectedFeatureRetired=[
    'bot.bot.change_config_remark_type.gate.ce14a45981',
    'admin_settings.config.get_gate_ways_keys.text.84a6dfb6ae',
    'admin_settings.config.get_gate_ways_keys.text.f419959ea3',
];
if (array_keys($featureRetired)!==$expectedFeatureRetired ||
    ($style['feature_retirements_authorization']??null)!=='https://github.com/ach1992/bot-refactor/issues/26#issuecomment-6453867569')
    throw new RuntimeException('feature_retirement_scope_drift');
foreach ($featureRetired as $key=>$record) {
    if (($record['before']??null)!==$baseline[$key]||empty($record['reason']))
        throw new RuntimeException('feature_retirement_baseline_mismatch:'.$key);
}
$expectedKeys=array_values(array_diff(array_keys($baseline),$retiredKeys,$expectedFeatureRetired));
$expectedKeys[]=$approvedAdded;$expectedKeys=array_merge($expectedKeys,array_keys($managementAdded));sort($expectedKeys);$actualKeys=array_keys($current);sort($actualKeys);
if ($actualKeys!==$expectedKeys) throw new RuntimeException('current_catalog_key_scope_drift');
foreach ($retired as $key=>$record) {
    if (($record['before']??null)!==$baseline[$key]||empty($record['reason']))
        throw new RuntimeException('implementation_token_baseline_mismatch:'.$key);
}
$parameterExceptions=[
    'purchase.order_writes.ensure_account_creation_succeeded.user_message.7a64d65208'=>[],
    'gateway.gateway_return.perform_payment_effect.message.3768e94d51'=>[],
    'gateway.gateway_return.perform_payment_effect.text.ed588b3bc9'=>['inbound_id'],
];
$seenChanges=[];$preserved=0;$currentChecks=0;$parameterChanges=0;
$currentObjectTemplates=0;$currentMultiParameterObjects=0;$currentOnePassTemplates=0;
foreach ($current as $key=>$entry) {
    $text=is_array($entry)?$entry['text']:$entry;
    $parameters=is_array($entry)?$entry['parameters']:[];
    if (!is_string($text)||!is_array($parameters)||preg_match('/[۰-۹٠-٩]/u',$text))
        throw new RuntimeException('current_template_or_digits_invalid:'.$key);
    if (isset($managementAdded[$key])) {
        if ($entry!==$managementAdded[$key]['entry'])
            throw new RuntimeException('management_catalog_definition_drift:'.$key);
    } elseif (isset($added[$key])) {
        $record=$added[$key];
        if ($text!==($record['after']??null)||$parameters!==($record['parameters']??null)||
            $parameters!==['title','inbound_id']||empty($record['reasons']))
            throw new RuntimeException('admin_diagnostic_definition_mismatch');
    } else {
        $styleEntry=$ownerChanges[$key]['before']??$entry;
        $styleText=is_array($styleEntry)?$styleEntry['text']:$styleEntry;
        $beforeEntry=$baseline[$key];$before=is_array($beforeEntry)?$beforeEntry['text']:$beforeEntry;
        $beforeParameters=is_array($beforeEntry)?$beforeEntry['parameters']:[];
        if (isset($changes[$key])) {
            $record=$changes[$key];
            if (($record['before']??null)!==$before||($record['after']??null)!==$styleText||
                ($record['parameters']??null)!==$parameters||empty($record['reasons'])||$before===$styleText&&$beforeParameters===$parameters)
                throw new RuntimeException('style_definition_mismatch:'.$key);
            if ($parameters!==$beforeParameters) {
                if (!array_key_exists($key,$parameterExceptions)||$parameters!==$parameterExceptions[$key]||
                    ($record['before_parameters']??null)!==$beforeParameters)
                    throw new RuntimeException('unapproved_style_parameters:'.$key);
                $parameterChanges++;
            } elseif (isset($record['before_parameters'])&&$record['before_parameters']!==$beforeParameters) {
                throw new RuntimeException('style_before_parameters_mismatch:'.$key);
            }
            $seenChanges[$key]=true;
        } else {
            if ($styleEntry!==$beforeEntry) throw new RuntimeException('unrecorded_current_copy_change:'.$key);
            $preserved++;
        }
    }
}
// Full current native loader coverage after all independently declared R5 changes.
foreach ($shipping as $key=>$entry) {
    $text=is_array($entry)?$entry['text']:$entry;
    $parameters=is_array($entry)?$entry['parameters']:[];
    // Every shipping template executes through the real loader with inert,
    // adversarial display values; substitutions remain one-pass and exact.
    $slotPayload='<&> '.implode(' ',array_map(static fn($name)=>'{'.$name.'}',$parameters));
    foreach ($parameters?[null,0,-77,"نام <&>\"' {time} %s",$slotPayload,new CatalogStringProbe()]:[null] as $sample) {
        $values=[];$replace=[];
        $actualObject=$sample instanceof CatalogStringProbe?clone $sample:null;
        $expectedObject=$sample instanceof CatalogStringProbe?clone $sample:null;
        foreach ($parameters as $parameter) {
            // All parameters share one object per side, so each conversion's
            // order and exact count are observable without sharing test state.
            $values[$parameter]=$actualObject??$sample;
            $replace['{'.$parameter.'}']=(string)($expectedObject??$sample);
        }
        $expected=$replace?strtr($text,$replace):$text;
        // The caller may bind names in any array order. Only declaration order
        // controls conversions; replacement values never become new slots.
        if (achbotMessage($key,array_reverse($values,true))!==$expected)
            throw new RuntimeException('current_native_template_failed:'.$key);
        if ($actualObject!==null) {
            if ($actualObject->conversions()!==count($parameters)||
                $expectedObject->conversions()!==count($parameters))
                throw new RuntimeException('current_object_conversion_count_failed:'.$key);
            $currentObjectTemplates++;
            if (count($parameters)>1) $currentMultiParameterObjects++;
        }
        if ($parameters&&$sample===$slotPayload) $currentOnePassTemplates++;
        $currentChecks++;
    }
}
if (count($seenChanges)!==count($changes)||$parameterChanges!==3)
    throw new RuntimeException('style_change_coverage_missing');
$files=glob(dirname(__DIR__).'/messages/fa/*.php');sort($files);
if (array_map('basename',$files)!==['admin.php','user.php'])
    throw new RuntimeException('editable_catalog_file_count_drift');
echo json_encode(['status'=>'PASS',
    'pre_style_templates'=>$templates,'pre_style_interpolated_templates'=>$interpolated,
    'pre_style_byte_assertions'=>$checks,'pre_style_byte_preserved_templates'=>$templates-count($approvedSeen),
    'pre_style_privacy_corrections'=>count($approvedSeen),
    'html_escaping'=>'PASS','one_pass_substitution'=>'PASS','legacy_facades'=>'PASS',
    'archived_facade_source_ref'=>CATALOG_MANAGEMENT_BASE,'current_catalog_binding_paths'=>count($currentBindingProof['paths']),
    'current_catalog_binding_entries'=>$currentBindingProof['entries'],'current_catalog_binding_negative_controls'=>$currentBindingNegatives,'retired_current_facade_absent'=>$currentBindingProof['retired_facade_absent'],
    'repeated_interpolation_string_conversion'=>'PASS','actual_traffic_helper_parity_cases'=>$trafficCases,
    'traffic_relocation_source_ref'=>CATALOG_MANAGEMENT_BASE,'current_traffic_shared_observation_cases'=>$currentTrafficCases,
    'editable_catalog_files'=>count($files),'logical_topics'=>count(achbotMessageTopics()),
    'current_catalog_keys'=>count($shipping),'current_gift_admin_additions'=>1,'current_gift_catalog_negative_controls'=>count($giftControls),'current_gift_catalog_negative_scenarios'=>array_keys($giftControls),'current_addon_catalog_additions'=>count($addonEntries),'current_addon_catalog_negative_controls'=>count($addonControls),'current_addon_catalog_negative_scenarios'=>array_keys($addonControls),'current_api_additions'=>count($apiDelta),'current_api_user_additions'=>$apiUserCount,'current_api_admin_additions'=>count($apiDelta)-$apiUserCount,'current_r5_catalog_keys'=>2685,'current_byte_preserved_keys'=>$preserved,
    'current_style_changes'=>count($changes),'current_retired_implementation_keys'=>count($retired),'current_retired_feature_keys'=>count($featureRetired),
    'current_added_admin_keys'=>count($added),'current_management_added_keys'=>count($managementAdded),'current_owner_followup_changes'=>count($ownerChanges),'current_parameter_privacy_changes'=>$parameterChanges,
    'current_r5_changes'=>count($r5['changes']),'current_r5_retired_keys'=>count($r5['retired_keys']),'current_r5_added_keys'=>count($r5['added_keys']),
    'current_native_template_assertions'=>$currentChecks,'current_non_ascii_digit_templates'=>0,
    'current_object_conversion_templates'=>$currentObjectTemplates,
    'current_shared_multi_parameter_templates'=>$currentMultiParameterObjects,
    'current_one_pass_templates'=>$currentOnePassTemplates,
],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
