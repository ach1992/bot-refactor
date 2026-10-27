<?php
/** Native byte-equivalence proof. Only inert tracked string expressions run. */
require_once dirname(__DIR__).'/messages/loader.php';
$inventory=json_decode(file_get_contents(__DIR__.'/text_catalog_inventory.json'),true,512,JSON_THROW_ON_ERROR);
function catalogEvaluate(string $_catalogExpression,array $_catalogBindings): string {
    extract($_catalogBindings,EXTR_SKIP);
    return eval('return '.$_catalogExpression.';');
}
final class CatalogStringProbe {
    private int $calls=0;
    public function __toString(): string {return '<probe '.(++$this->calls).' {time} %s>';}
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
            if (achbotMessage($edit['key'])!==$edit['original']) throw new RuntimeException('inline_html_mismatch:'.$path);
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
$dynamic=null;
foreach(glob(dirname(__DIR__).'/messages/fa/*.php') as $path) {
    $topic=basename($path,'.php');$group=achbotMessages($topic);
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
$main=$mainValues;$buttons=$buttonValues;
$legacy=shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' show '.escapeshellarg($inventory['baseline'].':settings/values.php'));
if (!is_string($legacy)) throw new RuntimeException('legacy_source_missing');
ob_start();eval('?>'.$legacy);ob_end_clean();
if($main!==$mainValues||$buttons!==$buttonValues) throw new RuntimeException('legacy_facade_mismatch');
$checks+=2;
require_once dirname(__DIR__).'/src/Presentation/Labels.php';
if (\AchBot\Presentation\Labels\paymentType('BUY_SUB')!== 'خرید اشتراک') throw new RuntimeException('namespaced_standalone_load_failed');
echo json_encode(['status'=>'PASS','templates'=>$templates,'interpolated_templates'=>$interpolated,'byte_assertions'=>$checks,
    'html_escaping'=>'PASS','one_pass_substitution'=>'PASS','legacy_facades'=>'PASS',
    'repeated_interpolation_string_conversion'=>'PASS','byte_preserved_templates'=>$templates-count($approvedSeen),
    'approved_wording_corrections'=>count($approvedSeen)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
