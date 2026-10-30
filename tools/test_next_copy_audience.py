#!/usr/bin/env python3
"""Source-native presentation/audience/date proof; no DB, HTTP or provider calls."""
from __future__ import annotations
import io,json,re,subprocess,tarfile,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='7faac9b067f8a171e5e26d6aea5aaf9f4088a3e7'
MANAGEMENT_BASE='2c0e728dcd71c38e20d176fe2577bc264d3faa1a'
INBOUND='INBOUND_PRIVATE_CANARY_987'
TITLE='PRIVATE_PANEL_TITLE_CANARY'
def check(ok,label):
    if not ok:raise AssertionError(label)
def php(root,code):
    p=subprocess.run(['php','-n','-d','allow_url_fopen=0','-d','display_errors=1','-r',code],cwd=root,capture_output=True,text=True,timeout=5)
    check(p.returncode==0 and not p.stderr,'isolated PHP failed: '+p.stderr[:400])
    try:return json.loads(p.stdout)
    except json.JSONDecodeError as exc:raise AssertionError('invalid source-native JSON: '+p.stdout[:400]) from exc
def order_failure(root,throw=False,success=False):
    router=''
    if 'achbotNotifyAdmins(' in (root/'src/Purchase/OrderWrites.php').read_text():
        # This pure audience fixture has one configured Owner and no staff DB.
        # Execute the actual current router; only sendMessage is a transport spy.
        router=subprocess.check_output(['php','-r',
            "require $argv[1]; echo achbotExtractFunction($argv[2], 'achbotNotifyAdmins');",
            str(ROOT/'tools/source_scan.php'),str(root/'config.php')],cwd=root,text=True)
        router+="\nrequire_once 'src/Admin/Access.php'; $admin=999;$connection=null;$botState=[];\n"
    return php(root,router+"""$r=['customer'=>[],'admin'=>[]];
function alert($text){$GLOBALS['r']['customer'][]=$text;}
function sendMessage($text,$keys=null,$parse=null,$chat=null){$GLOBALS['r']['admin'][]=['text'=>$text,'chat'=>$chat,'parse'=>$parse];}
register_shutdown_function(function(){echo json_encode($GLOBALS['r'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);});
require 'src/Purchase/OrderWrites.php';
try {ensureAccountCreationSucceeded("""+('((object)[\'success\'=>true])' if success else json.dumps('inbound not Found'))+",['title'=>"+json.dumps(TITLE)+"],"+json.dumps(INBOUND)+",999,"+('true' if throw else 'false')+");} catch(Throwable $e){$r['exception']=$e->getMessage();}")
def gateway_fragment(root):
    source=(root/'src/Finance/GatewayReturn.php').read_text()
    key='gateway.gateway_return.perform_payment_effect.message.3768e94d51'
    line=next(l.strip() for l in source.splitlines() if "showForm(\\achbotMessage('"+key+"'" in l)
    check(line.endswith(';'),'gateway presentation boundary')
    return php(root,"require 'messages/loader.php';function showForm($text,$description,$ok,$state){echo json_encode(['customer'=>$text,'ok'=>$ok,'state'=>$state],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}$inbound_id="+json.dumps(INBOUND)+";$amount=123456;$payDescription=[];"+line)
def admin_suffix(root):
    source=(root/'src/Finance/GatewayReturn.php').read_text()
    key='gateway.gateway_return.perform_payment_effect.text.ed588b3bc9'
    line=next(l.strip() for l in source.splitlines() if "\\achbotMessage('"+key+"'" in l)
    check(line.endswith(','),'admin suffix boundary')
    return php(root,"require 'messages/loader.php';$inbound_id="+json.dumps(INBOUND)+";echo json_encode("+line[:-1]+",JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);")
def parser_tests(root):
    source=(root/'config.php').read_text()
    start=source.index("if (!function_exists('convertPersianDigitsToEnglishForStats'))")
    end=source.index('function calculateSalesBetweenDates(',start)
    chunk=source[start:end]
    # Only the three actual pure parser definitions are evaluated, never bootstrap.
    check(chunk.count('function ')==3,'pure stats helper boundary')
    code="require 'messages/loader.php';\n"+chunk+"""
$p='';$a='';for($i=0;$i<10;$i++){$p.=json_decode('"\\u06f'.$i.'"');$a.=json_decode('"\\u066'.$i.'"');}
$digits=convertPersianDigitsToEnglishForStats($p.$a);
$latin='2026-05-21 2026-05-22';$results=[];
foreach(["\\u{2013}","\\u{2014}","\\u{2212}"] as $dash){
 $input=str_replace('-', $dash, $latin);
 $input=strtr($input,array_combine(str_split('0123456789'),preg_split('//u',$p,-1,PREG_SPLIT_NO_EMPTY)));
 $results[]=parseStatsDateRange($input);
}
echo json_encode(['digits'=>$digits,'ranges'=>$results,'invalid'=>parseStatsDateRange('2026-02-31 2026-03-01'),'normalized'=>normalizeStatsDate('2026/5/9')],JSON_THROW_ON_ERROR);
"""
    return php(root,code)
def public_retirement(root):
    return php(root,"ob_start();register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'body'=>ob_get_clean()],JSON_THROW_ON_ERROR);});$_SERVER['REQUEST_METHOD']='GET';$_GET['token']=str_repeat('a',30);require 'settings/subLink.php';")
def main():
    checks=[]
    def require(ok,name):check(ok,name);checks.append(name)
    with tempfile.TemporaryDirectory(prefix='achbot-copy-audience-') as temp:
        old=Path(temp)
        raw=subprocess.check_output(['git','archive',BASE,'messages','config.php','settings/jdf.php','settings/subLink.php','src/Purchase/OrderWrites.php','src/Finance/GatewayReturn.php'],cwd=ROOT)
        with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
            for m in archive.getmembers():
                require(not m.name.startswith('/') and '..' not in Path(m.name).parts and not(m.issym() or m.islnk()),'safe_archive_member_'+m.name)
            archive.extractall(old,filter='data')
        # Archive safety checks are not counted as product scenarios.
        checks.clear()
        before=order_failure(old)
        require(INBOUND in before['customer'][0] and not before['admin'],'archived_order_inbound_disclosure_RED')
        current=order_failure(ROOT)
        require(len(current['customer'])==1 and all(s not in current['customer'][0] for s in [INBOUND,TITLE,'inbound','SQLSTATE']),'actual_order_customer_privacy_GREEN')
        require(len(current['admin'])==1 and current['admin'][0]['chat']==999 and INBOUND in current['admin'][0]['text'] and TITLE in current['admin'][0]['text'] and 'inbound_not_found' in current['admin'][0]['text'],'actual_order_admin_retains_diagnostics')
        require(current['admin'][0]['parse'] is None,'actual_order_plaintext_parse_boundary')
        thrown=order_failure(ROOT,throw=True)
        require(not thrown['customer'] and not thrown['admin'] and INBOUND in thrown.get('exception','') and 'inbound_not_found' in thrown['exception'],'internal_throw_retains_diagnostics_without_customer_send')
        succeeded=order_failure(ROOT,success=True)
        require(not succeeded['customer'] and not succeeded['admin'] and 'exception' not in succeeded,'successful_order_presentation_unchanged')
        before=gateway_fragment(old)
        require(INBOUND in before['customer'],'archived_gateway_inbound_disclosure_RED')
        current=gateway_fragment(ROOT)
        require(all(s not in current['customer'] for s in [INBOUND,TITLE,'inbound','SQLSTATE']),'actual_gateway_presentation_privacy_GREEN')
        require('123,456' in current['customer'] and 'کیف پول' in current['customer'] and not current['ok'] and current['state']=='review','actual_gateway_amount_refund_review_semantics_preserved')
        require(INBOUND in admin_suffix(ROOT),'actual_gateway_admin_suffix_retains_inbound_id')
        parsed=parser_tests(ROOT)
        require(parsed['digits']=='01234567890123456789','actual_stats_persian_arabic_input_conversion')
        require(parsed['ranges']==[['2026-05-21','2026-05-22']]*3,'actual_stats_unicode_delimiters_preserved')
        require(parsed['invalid'] is False and parsed['normalized']=='2026-05-09','actual_stats_invalid_date_and_normalization')
        require(parsed==parser_tests(old),'actual_stats_helpers_byte_equivalent_outputs')
        dates=php(ROOT,"require 'settings/jdf.php';echo json_encode([jdate('Y-m-d H:i:s',1800000000),jstrftime('%Y-%m-%d %H:%M:%S',1800000000),jgetdate(1800000000)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);")
        old_dates=php(old,"require 'settings/jdf.php';echo json_encode([jdate('Y-m-d H:i:s',1800000000),jstrftime('%Y-%m-%d %H:%M:%S',1800000000),jgetdate(1800000000)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);")
        require(dates==old_dates and not re.search('[\u0660-\u0669\u06f0-\u06f9]',json.dumps(dates,ensure_ascii=False)),'actual_jdf_ascii_dates_and_calendar_unchanged')
        # Preserve the last shipped retirement response as immutable evidence;
        # the fully retired X-UI-only endpoint no longer belongs in runtime.
        archived=old/'retirement-proof';archived.mkdir()
        raw=subprocess.check_output(['git','archive',MANAGEMENT_BASE,'messages','settings/subLink.php'],cwd=ROOT)
        with tarfile.open(fileobj=io.BytesIO(raw))as archive:
            for member in archive.getmembers():
                check(not member.name.startswith('/')and'..'not in Path(member.name).parts and not(member.issym()or member.islnk()),'unsafe retirement proof member')
            archive.extractall(archived,filter='data')
        retired=public_retirement(archived)
        import package_post_v9
        active_includes=[]
        for path in package_post_v9.members():
            if not path.endswith('.php'):continue
            source=(ROOT/path).read_text()
            if re.search(r'''(?:require|include)(?:_once)?\s*(?:\([^;]*)?['"][^'"]*subLink\.php['"]''',source):
                active_includes.append(path)
        require(retired['status']==410 and 'Marzban'not in retired['body']and'<b>'not in retired['body']
                and not(ROOT/'settings/subLink.php').exists()and'settings/subLink.php'not in package_post_v9.members()
                and not active_includes,'archived_public_retirement_safe_and_current_endpoint_absent')
    print(json.dumps({'complete':True,'cases':len(checks),'scenarios':checks,'archived_disclosure_RED':2,'actual_customer_admin_separation':True,'actual_stats_parser_equivalence':True,'actual_ascii_date_output':True,'database_provider_network_calls':0,'presentation_only':True,'retired_public_stub_archived_reference':MANAGEMENT_BASE,'retired_public_stub_current_absent':True},ensure_ascii=False))
if __name__=='__main__':main()
