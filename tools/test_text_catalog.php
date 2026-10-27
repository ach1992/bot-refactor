<?php
/** Retained pre-style byte proof and exact current audience-catalog acceptance. */
require_once dirname(__DIR__).'/messages/loader.php';
$inventory=json_decode(file_get_contents(__DIR__.'/text_catalog_inventory.json'),true,512,JSON_THROW_ON_ERROR);
const CATALOG_STYLE_BASELINE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7';
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
function achbotProviderUser($server,$remark) {
    $GLOBALS['catalogTrafficReads']++;
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
        $actual=\AchBot\Subscription\AccountDetails\getOrderTrafficInfo($provider,2,'fixture','',0);
        if ($actual!==$expected||$GLOBALS['catalogTrafficReads']!==2)
            throw new RuntimeException('traffic_full_helper_byte_parity_failed');
        $trafficCases++;$checks++;
    }
}
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
require dirname(__DIR__).'/settings/values.php';
if ($mainValues!==achbotMessages('legacy_messages')||$buttonValues!==achbotMessages('legacy_buttons'))
    throw new RuntimeException('current_facade_mismatch');
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
$expectedKeys=array_values(array_diff(array_keys($baseline),$retiredKeys));
$expectedKeys[]=$approvedAdded;sort($expectedKeys);$actualKeys=array_keys($current);sort($actualKeys);
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
    if (isset($added[$key])) {
        $record=$added[$key];
        if ($text!==($record['after']??null)||$parameters!==($record['parameters']??null)||
            $parameters!==['title','inbound_id']||empty($record['reasons']))
            throw new RuntimeException('admin_diagnostic_definition_mismatch');
    } else {
        $beforeEntry=$baseline[$key];$before=is_array($beforeEntry)?$beforeEntry['text']:$beforeEntry;
        $beforeParameters=is_array($beforeEntry)?$beforeEntry['parameters']:[];
        if (isset($changes[$key])) {
            $record=$changes[$key];
            if (($record['before']??null)!==$before||($record['after']??null)!==$text||
                ($record['parameters']??null)!==$parameters||empty($record['reasons'])||$before===$text&&$beforeParameters===$parameters)
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
            if ($entry!==$beforeEntry) throw new RuntimeException('unrecorded_current_copy_change:'.$key);
            $preserved++;
        }
    }
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
    'repeated_interpolation_string_conversion'=>'PASS','actual_traffic_helper_parity_cases'=>$trafficCases,
    'editable_catalog_files'=>count($files),'logical_topics'=>count(achbotMessageTopics()),
    'current_catalog_keys'=>count($current),'current_byte_preserved_keys'=>$preserved,
    'current_style_changes'=>count($changes),'current_retired_implementation_keys'=>count($retired),
    'current_added_admin_keys'=>count($added),'current_parameter_privacy_changes'=>$parameterChanges,
    'current_native_template_assertions'=>$currentChecks,'current_non_ascii_digit_templates'=>0,
    'current_object_conversion_templates'=>$currentObjectTemplates,
    'current_shared_multi_parameter_templates'=>$currentMultiParameterObjects,
    'current_one_pass_templates'=>$currentOnePassTemplates,
],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
