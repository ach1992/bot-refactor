#!/usr/bin/env python3
"""Current source-bound read-only readiness on the actual fresh schema."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import zipfile
import io

import package_current
import database_fixture as fixture

ROOT = package_current.ROOT
DB = "fresh_readiness"
SECRET = "SYNTHETIC_LOCAL_PAYMENT_SECRET_NEVER_REPORT"
PASSWORD = "synthetic_ro_password_never_report"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-disposable", action="store_true", required=True)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    cases = []
    zipped, metadata = package_current.build()
    raw = (json.dumps(metadata, ensure_ascii=False, indent=2)+"\n").encode()
    trusted = hashlib.sha256(raw).hexdigest()
    with fixture.owned_database() as (work, env):
        cli = ["mariadb","--no-defaults","--protocol=SOCKET",
               "--socket="+str(work/"mysql.sock"),"-uroot","--batch","--skip-column-names"]
        def sql(statement, database=DB):
            return subprocess.run(cli+([database] if database else []), input=statement,
                text=True, env=env, capture_output=True, check=True, timeout=30).stdout.strip()
        sql("CREATE DATABASE "+DB+" CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", None)
        sql((ROOT/"database/current-schema.sql").read_text())
        sql("CREATE USER 'readiness_ro'@'localhost' IDENTIFIED BY '"+PASSWORD+"';"
            "GRANT SELECT ON "+DB+".* TO 'readiness_ro'@'localhost';",None)
        runtime=work/"runtime";runtime.mkdir()
        with zipfile.ZipFile(io.BytesIO(zipped)) as archive: archive.extractall(runtime)
        manifest=work/"manifest.json";manifest.write_bytes(raw)
        (runtime/"baseInfo.php").write_text("<?php throw new RuntimeException('"+SECRET+"');")
        database_env=dict(env, ACHBOT_PREFLIGHT_DB_NAME=DB,
            ACHBOT_PREFLIGHT_DB_USER="readiness_ro", ACHBOT_PREFLIGHT_DB_PASSWORD=PASSWORD,
            ACHBOT_PREFLIGHT_DB_SOCKET=str(work/"mysql.sock"))
        def invoke(expected, digest=trusted, candidate=metadata["candidate"], overrides=None):
            call=["python3",str(ROOT/"tools/payment_readiness_preflight.py"),
                "--manifest",str(manifest),"--runtime-dir",str(runtime),
                "--expect-commit",candidate,"--trusted-manifest-sha256",digest]
            result=subprocess.run(call,env=database_env| (overrides or {}),
                text=True,capture_output=True,timeout=30)
            assert not result.stderr and SECRET not in result.stdout and PASSWORD not in result.stdout, result.stderr
            report=json.loads(result.stdout)
            assert result.returncode==expected,(result.returncode,report)
            assert report["completed"] and report["structure_ready"]==(expected!=2),report
            assert report["payment_configuration_ready"]==(expected==0),report
            assert report["ready_for_isolated_operator_smoke"]==(expected==0),report
            return report
        def seed(state,keys):
            def literal(x): return json.dumps(x,separators=(",",":")).replace("'","''")
            sql("DELETE FROM setting; INSERT INTO setting(type,value) VALUES "
                "('BOT_STATES','"+literal(state)+"'),('PAYMENT_KEYS','"+literal(keys)+"')")
        def has(report, check, status):
            return any(c["id"]==check and c["status"]==status for c in report["checks"])
        before=sql("SELECT COUNT(*) FROM setting")
        empty=invoke(3)
        assert before=="0" and sql("SELECT COUNT(*) FROM setting")=="0"
        assert has(empty,"consolidated_schema","PASS") and has(empty,"method_selection","BLOCK")
        assert empty["source_file_count"]==len(metadata["files"])
        cases.append("fresh_empty_structure_ready_not_payment_configured")
        # Actual SELECT-only account cannot write; do not depend on the
        # diagnostic's transaction alone for the fixture safety boundary.
        denied=subprocess.run(["mariadb","--no-defaults","--protocol=SOCKET",
             "--socket="+str(work/"mysql.sock"),"-ureadiness_ro",DB],
             input="INSERT INTO setting(type,value) VALUES ('unexpected','write')",
             text=True,env=env|{"MYSQL_PWD":PASSWORD},capture_output=True)
        assert denied.returncode!=0 and "denied" in denied.stderr.lower()
        cases.append("select_only_account_cannot_write")
        states={"walletState":"on","cartToCartState":"on","giftCardState":"on",
                "nextpay":"on","zarinpal":"on","nowPaymentWallet":"on","manualUsdtState":"on"}
        keys={"bankAccount":"1234567890","holderName":"Fixture","giftCardUrl":"https://example.com/gift",
              "nextpay":SECRET,"zarinpal":SECRET,"nowpayment":SECRET,
              "manualUsdtWalletBEP20":"0x"+"a"*40,"manualUsdtRateToman":"1000",
              "manualTrxWallet":"TJRabPrwbZy45sbavfcjinPJC18kjpRTv8","manualTrxRateToman":"1000"}
        seed(states,keys)
        stored=sql("SELECT type,value FROM setting ORDER BY id")
        configured=invoke(0)
        assert len(configured["methods"])==8
        assert all(m["offerability"]=="potentially_offerable" for m in configured["methods"].values()),configured
        assert stored==sql("SELECT type,value FROM setting ORDER BY id")
        assert "1234567890" not in json.dumps(configured)
        cases.append("configured_current_methods_and_no_private_output_or_database_writes")
        seed({"cartToCartState":"on"},{"bankAccount":"1234567890"})
        assert has(invoke(3),"method_bank_transfer","BLOCK")
        cases.append("missing_required_payment_setting_not_schema_failure")
        seed({"walletState":"on","gatewayAudience":{"wallet":"off"}},{})
        assert has(invoke(3),"method_selection","BLOCK")
        seed({"walletState":"on","gatewayRules":{"version":1,"methods":{"wallet":[]}}},{})
        assert invoke(3)["methods"]["wallet"]["offerability"]=="configured_but_policy_denied"
        seed({"walletState":"on","gatewayRules":{"version":2,"methods":{}}},{})
        assert has(invoke(3),"gateway_policies","BLOCK")
        cases.append("current_gateway_rule_precedence_and_invalid_policy_denied")
        seed({"walletState":"on","gatewayRules":{"version":1,"methods":{"wallet":[]}},
              "gatewayRuleExemptUsers":{"wallet":[123]}},{})
        assert invoke(0)["methods"]["wallet"]["offerability"]=="potentially_offerable"
        for invalid in (None,{"wallet":[0]},{"wallet":[123,123]},{"retired":[123]}):
            seed({"walletState":"on","gatewayRuleExemptUsers":invalid},{})
            assert has(invoke(3),"gateway_policies","BLOCK")
        seed({"walletState":"on","gatewayAudience":None},{})
        assert has(invoke(3),"gateway_policies","BLOCK")
        cases.append("explicit_exemptions_are_potential_not_actual_authorization")
        fallback={"walletState":"on","gatewayRules":{"version":1,"methods":{"wallet":[]}},
                  "gatewayAmountMode":{"wallet":"fallback"},"gatewayMaxAmountToman":{"wallet":100}}
        seed(fallback,{})
        assert invoke(0)["methods"]["wallet"]["offerability"]=="potentially_offerable"
        for invalid in (0,-1,None):
            seed({**fallback,"gatewayMaxAmountToman":{"wallet":invalid}},{})
            assert invoke(3)["methods"]["wallet"]["offerability"]=="configured_but_policy_denied"
        seed({**fallback,"gatewayAmountMode":{"wallet":"unknown"}},{})
        assert invoke(3)["methods"]["wallet"]["offerability"]=="configured_but_policy_denied"
        cases.append("explicit_positive_amount_alternative_is_potential_not_actual_authorization")
        for mode in ("cap","fallback"):
            for bad in (["off"],{"0":"off","wallet":"all"}):
                seed({"walletState":"on","gatewayAudience":bad,
                      "gatewayAmountMode":{"wallet":mode},"gatewayMaxAmountToman":{"wallet":100},
                      "gatewayRuleExemptUsers":{"wallet":[123]}},{})
                assert has(invoke(3),"gateway_policies","BLOCK")
        cases.append("malformed_legacy_map_readiness_rejects_numeric_keys")
        seed({"manualUsdtState":"on"},{"manualUsdtWalletBEP20":"0x"+"a"*40,"manualUsdtRateToman":"1000"})
        r=invoke(0)
        assert r["methods"]["manual_trx"]["offerability"]=="not_offered"
        seed({"manualUsdtState":"on"},{"manualUsdtWallet":"0x"+"a"*40,"manualUsdtRateToman":"1000"})
        assert has(invoke(3),"method_selection","BLOCK")
        seed({"manualUsdtState":"on"},{"manualTrxWallet":keys["manualTrxWallet"],"manualTrxRateToman":"1000"})
        assert invoke(0)["methods"]["manual_usdt"]["offerability"]=="not_offered"
        cases.append("crypto_network_independence_and_no_retired_address_fallback")
        seed({"walletState":"on","smsApiVerifyState":"on"},{})
        assert has(invoke(3),"api_bank_transfer","BLOCK")
        seed({"walletState":"on","smsApiVerifyState":"on"},{"smsApiUrl":"https://example.com/wp-json/ach-pay-manager/v1",
             "smsApiToken":"achpe1:"+__import__("base64").b64encode(b"synthetic"*5).decode()})
        assert invoke(0)["payment_apis"]["bank_transfer"]["local_configuration"]=="present_not_decrypted"
        cases.append("api_envelope_presence_not_decryption_or_authentication")
        seed({"walletState":"on"},{})
        sql("INSERT INTO setting(type,value) VALUES ('BOT_STATES','{}')")
        assert has(invoke(3),"setting_bot_states","BLOCK")
        seed({"walletState":"on"},{})
        sql("UPDATE setting SET value='[]' WHERE type='BOT_STATES'")
        assert has(invoke(3),"setting_bot_states","BLOCK")
        cases.append("duplicate_and_malformed_settings_reported")
        seed({"walletState":"on"},{})
        sql("ALTER TABLE payment_delivery_intents DROP CONSTRAINT ck_delivery_source_v2")
        r=invoke(2)
        assert "constraint:ck_delivery_source_v2" in r["schema"]["payment_delivery_intents"]
        sql("ALTER TABLE payment_delivery_intents ADD CONSTRAINT ck_delivery_source_v2 CHECK ((service_operation_id IS NOT NULL OR effect_id IS NOT NULL) AND part_count>0 AND order_id>0)")
        sql("ALTER TABLE service_operations DROP INDEX uq_service_command, ADD INDEX uq_service_command(command_kind,command_ref)")
        r=invoke(2)
        assert "index:uq_service_command" in r["schema"]["service_operations"]
        sql("ALTER TABLE service_operations DROP INDEX uq_service_command, ADD UNIQUE INDEX uq_service_command(command_kind,command_ref)")
        sql("ALTER TABLE payment_delivery_attempts DROP FOREIGN KEY fk_delivery_attempt_part")
        assert "constraint:fk_delivery_attempt_part" in invoke(2)["schema"]["payment_delivery_attempts"]
        sql("ALTER TABLE payment_delivery_attempts ADD CONSTRAINT fk_delivery_attempt_part FOREIGN KEY (intent_id,part_no) REFERENCES payment_delivery_parts(intent_id,part_no)")
        assert invoke(0)["structure_ready"]
        cases.append("missing_check_nonunique_command_and_missing_foreign_key_block")
        # No DB connection is allowed after an identity failure. A deliberately
        # invalid account proves this is the source gate, not a DB failure.
        bad_env={"ACHBOT_PREFLIGHT_DB_NAME":"absent"}
        def source_failure(digest=trusted,candidate=metadata["candidate"]):
            report=invoke(2,digest,candidate,bad_env)
            assert has(report,"source_identity","BLOCK")
            assert not any(c["id"]=="database_read_only" for c in report["checks"])
        source_failure("0"*64)
        source_failure(candidate="0"*40)
        changed=dict(metadata,files=dict(metadata["files"]))
        changed["files"]["baseInfo.php"]={"size":1,"sha256":"0"*64}
        changed["file_count"]+=1
        malicious=json.dumps(changed).encode();manifest.write_bytes(malicious)
        source_failure(hashlib.sha256(malicious).hexdigest())
        manifest.write_bytes(raw)
        cases.append("untrusted_manifest_commit_or_private_member_rejected_before_db")
        member=runtime/"bot.php";original=member.read_bytes()
        member.write_bytes(original+b"\n")
        source_failure()
        member.unlink();member.symlink_to(runtime/"baseInfo.php")
        source_failure()
        member.unlink();member.write_bytes(original)
        assert invoke(0)["structure_ready"]
        cases.append("tampered_and_symlinked_runtime_member_blocked")
        for extra in ("index.html","settings/warnusers.php","pay/index.php","old.php"):
            stale=runtime/extra
            stale.write_text(SECRET)
            source_failure()
            stale.unlink()
        extra_link=runtime/"settings"/"retired"
        extra_link.symlink_to(work, target_is_directory=True)
        source_failure()
        extra_link.unlink()
        assert invoke(0)["structure_ready"]
        cases.append("unexpected_executables_and_index_shadow_rejected_without_reading_contents")
        assert sql("SELECT COUNT(*) FROM users")=="0" and sql("SELECT COUNT(*) FROM wallet_entries")=="0"
        assert sql("SELECT COUNT(*) FROM service_operations")=="0" and sql("SELECT COUNT(*) FROM pays")=="0"
        cases.append("no_financial_or_service_effects")
    report={"status":"PASS","complete":True,"cases":len(cases),"scenarios":cases,
            "production_mutations":0,"candidate":metadata["candidate"]}
    if args.report:args.report.write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report))


if __name__=="__main__":main()
