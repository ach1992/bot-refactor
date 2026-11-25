"""Stage the current wallet-transfer runner without loading obsolete test drivers."""
from pathlib import Path
import hashlib
import package_current as current_runtime
import notification_fixture
import php_fixture as panel_functions

ROOT=Path(__file__).resolve().parents[1]

def stage_source(stage:Path)->dict:
    stage.mkdir(parents=True,exist_ok=False)
    members = current_runtime.members()
    for rel in members:
        dest=stage/rel
        dest.parent.mkdir(parents=True,exist_ok=True)
        raw=(ROOT/rel).read_bytes()
        dest.write_bytes(raw)
    notification_fixture.stage_router(stage)
    # Diagnostic events are current-source runtime dependencies. Before
    # the first candidate commit, HEAD inventory cannot see a new file;
    # stage its exact bytes just as package_current will after commit.
    diagnostics=stage/'src/Diagnostics/EventLog.php'
    diagnostics.parent.mkdir(parents=True,exist_ok=True)
    diagnostics.write_bytes((ROOT/'src/Diagnostics/EventLog.php').read_bytes())
    # Fragment fixture executes the real common provider seam too.
    for rel in ['src/Finance/WalletReports.php','src/Panel/ProviderRuntime.php','src/Panel/ProviderImport.php','src/Panel/ProviderAdmin.php','src/Panel/ProviderStorage.php','src/Panel/PasarGuard.php','src/Subscription/Presentation.php']:
        dest=stage/rel;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes((ROOT/rel).read_bytes())
    fixture=(ROOT/'tests/v7/native-purchase.php').read_text()
    fixture=fixture.replace('<?php','<?php\nrequire_once __DIR__ . "/notifications-under-test.php";',1)
    # Adapt only the disposable current-source driver to the retired path.
    # Original V0-V9 PHP fixture and the historical source stage stay exact.
    legacy_import="require __DIR__.'/buy_helpers.php';"
    canonical_import="require_once __DIR__ . '/src/Purchase/Core.php';"
    assert fixture.count(legacy_import)==1
    fixture=fixture.replace(legacy_import,canonical_import,1)
    retired_import="require __DIR__.'/src/Panel/Accounts.php';"
    assert fixture.count(retired_import)==1
    fixture=fixture.replace(retired_import,"",1)
    (stage/'presentation-under-test.php').write_bytes(panel_functions.function_bodies((ROOT/'config.php').read_bytes(),['buildSubscriptionPresentation']))
    # Production config loads the real Jalali/date helpers before admin redraws.
    # Bootstrap that candidate dependency here; the frozen V7 driver stays byte-identical.
    fixture=fixture.replace('<?php','<?php\nrequire_once __DIR__ . "/settings/jdf.php";\nrequire_once __DIR__ . "/presentation-under-test.php";',1)
    # Stage the same explicitly configured, synthetic bank destination for
    # immutable V9 and current source. Real manual checkout now requires it;
    # do not modify the pinned historical fixture or waive event parity.
    bank_anchor="$botUrl='http://localhost.invalid/';$botUsername='';$paymentKeys=[];"
    assert fixture.count(bank_anchor)==1
    fixture=fixture.replace(bank_anchor, "$botUrl='http://localhost.invalid/';"
        "$botUsername='';$paymentKeys=['bankAccount'=>'fixture-bank-account',"
        "'holderName'=>'fixture-bank-holder'];")
    # The actual interval route now verifies a fresh private Telegram
    # callback and the persisted administrator. Supply that context ONLY
    # in this candidate fixture; the archived V7 driver stays untouched.
    interval_anchor='$linkIndices=[0];$hideConfigIfMany=false;$permissions=[];$update=[];'
    assert fixture.count(interval_anchor)==1
    fixture=fixture.replace(interval_anchor,interval_anchor+'''\nif (in_array((string)($spec['data']??''),['dayPlanSettings','volumePlanSettings'],true)) {\n    $update=json_decode(json_encode(['update_id'=>710001,'callback_query'=>[\n        'id'=>'fixture-current-interval','data'=>$data,'from'=>['id'=>$from_id,'is_bot'=>false],\n        'message'=>['message_id'=>$message_id,'chat'=>['id'=>$from_id,'type'=>'private']]\n    ]]));\n}\n''',1)
    needle='function getConnectionLink(...$args)'
    assert fixture.count(needle)==1
    fixture=fixture.replace(needle,"function achbotSupportsPanel($type) { return $type === 'marzban'; }\n"+needle)
    (stage/'native-purchase.php').write_text(fixture)
    (stage/'settings/temp.txt').write_text('3000-0')
    (stage/'baseInfo.php').write_text('<?php // fixture only\n')
    (stage/'private-marker.json').write_text('{"synthetic":"only"}\n')
    return {name:hashlib.sha256((stage/name).read_bytes()).hexdigest()
            for name in ['baseInfo.php','private-marker.json']}
