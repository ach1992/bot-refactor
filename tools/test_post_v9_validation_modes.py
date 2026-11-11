#!/usr/bin/env python3
"""Selected validation routing and evidence rejection; no PHP/native jobs execute."""
from __future__ import annotations

import argparse
import contextlib
import copy
import io
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import check_post_v9 as checks

# Scenario data retained from the actual 95-case Cron run. This is mock input
# for report-boundary rejection, never native execution or acceptance evidence.
CRON_SCENARIOS = [
    "missing_nested_external_directories_created_private_current_uid",
    "first_directory_creation_runs_exact_four_standins",
    "trusted_existing_parent_inode_mode_owner_preserved",
    "autoprovisioned_directory_uses_private_installation_lock",
    "existing_directories_and_lock_reused_without_chmod_or_inode_repair",
    "missing_directory_with_mkdir_disabled_denied_without_creation",
    "valid_existing_directory_runs_with_mkdir_disabled_without_repair",
    "missing_below_world_writable_parent_denied_unchanged",
    "missing_below_world_writable_parent_denied_unchanged_has_actionable_directory_context",
    "missing_below_group_writable_parent_denied_unchanged",
    "missing_below_group_writable_parent_denied_unchanged_has_actionable_directory_context",
    "missing_below_unwritable_parent_denied_unchanged",
    "missing_below_unwritable_parent_denied_unchanged_has_actionable_directory_context",
    "missing_below_symlink_parent_denied_without_target_creation",
    "missing_below_symlink_parent_denied_without_target_creation_has_actionable_directory_context",
    "missing_below_regular_file_denied_without_mutation",
    "missing_below_regular_file_denied_without_mutation_has_actionable_directory_context",
    "existing_world_writable_leaf_denied_without_chmod_repair",
    "existing_world_writable_leaf_denied_without_chmod_repair_has_actionable_directory_context",
    "existing_nonprivate_leaf_denied_without_chmod_repair",
    "existing_nonprivate_leaf_denied_without_chmod_repair_has_actionable_directory_context",
    "existing_unwritable_leaf_denied_without_chmod_repair",
    "existing_unwritable_leaf_denied_without_chmod_repair_has_actionable_directory_context",
    "missing_inside_installation_denied_without_directory_creation",
    "missing_inside_installation_denied_without_directory_creation_has_actionable_directory_context",
    "relative_missing_directory_denied_without_creation",
    "relative_missing_directory_denied_without_creation_has_actionable_directory_context",
    "dot_component_denied_without_creation",
    "dot_component_denied_without_creation_has_actionable_directory_context",
    "parent_alias_component_denied_without_creation",
    "parent_alias_component_denied_without_creation_has_actionable_directory_context",
    "repeated_separator_denied_without_creation",
    "repeated_separator_denied_without_creation_has_actionable_directory_context",
    "root_path_denied_without_bootstrap",
    "root_path_denied_without_bootstrap_has_actionable_directory_context",
    "child_broadcast_cannot_create_missing_directories",
    "child_services_cannot_create_missing_directories",
    "cli_include_cannot_autoprovision_missing_directory",
    "http_cannot_autoprovision_missing_directory",
    "five_missing_directory_starts_admit_only_one_batch",
    "concurrent_created_components_remain_private",
    "independent_bots_share_autocreated_directory_without_global_exclusion",
    "five_first_directory_starts_finish_one_exact_batch",
    "four_jobs_sequential_exact_order",
    "individual_cli_processes_inherit_cron_cwd",
    "successful_workers_preserve_outputs",
    "api_payment_maintenance_precedes_payux",
    "per_installation_private_lock_created",
    "first_create_then_reuse_same_inode",
    "later_run_reuses_stable_lock_inode",
    "atomic_first_creation_does_not_start_second_batch",
    "five_simultaneous_first_invocations_finish_one_batch",
    "overlapping_tick_skips_without_extra_jobs",
    "different_installation_runs_while_first_locked",
    "first_tick_finishes_full_batch_after_overlap",
    "stale_lock_file_does_not_block_next_tick",
    "orphaned_worker_retains_lock_after_parent_kill",
    "new_run_recovers_after_orphan_worker_exit",
    "orphaned_canonical_worker_retains_lock_after_parent_kill",
    "new_run_recovers_after_orphan_canonical_worker_exit",
    "lock_directory_inside_webroot_fails_closed",
    "symlinked_lock_directory_alias_denied",
    "unavailable_lock_directory_fails_closed",
    "symlink_lock_path_fails_closed",
    "dangling_symlink_rejected_without_target_creation",
    "fifo_preexisting_lock_rejected_without_block",
    "permissive_lock_file_fails_closed",
    "group_writable_unprotected_lock_directory_denied",
    "shared_sticky_lock_directory_and_preempted_name_denied",
    "writable_nonsticky_ancestor_denied",
    "missing_posix_effective_uid_denied",
    "unavailable_php_flock_fails_closed",
    "direct_child_without_inherited_lease_denied",
    "unknown_child_mode_denied",
    "independent_unlocked_fd_is_not_child_admission",
    "shared_read_flock_is_not_exclusive_worker_lease",
    "different_exclusively_locked_inode_denied",
    "missing_kernel_fdinfo_capability_denies_child",
    "canonical_broadcast_child_runs_only_under_inherited_lease",
    "canonical_child_never_unlocks_parent_open_file_description",
    "retired_worker_wrappers_and_values_are_not_staged",
    "child_mode_missing_process_capability_denied",
    "failed_child_does_not_skip_remaining_workers",
    "no_child_exception_details_emitted_by_runner",
    "missing_worker_fails_but_others_continue",
    "unexpected_arguments_denied_without_side_effect",
    "missing_proc_open_fails_closed",
    "accidental_cli_include_has_no_side_effect",
    "anonymous_http_returns_404_without_running_workers",
    "inherited_lease_fdinfo_parser_native_spaces_and_negative_controls",
    "observed_chili_order_bounded_and_explicit",
    "real_gift2all.php_http_denied_before_bootstrap",
    "real_rewardReport.php_http_denied_before_bootstrap",
    "source_has_no_database_or_provider_bootstrap",
    "api_payment_failure_preserves_payux_finally"
]
NEGATIVE_CONTROLS: list[str] = []


class ValidationModes(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="achbot-validation-modes-")
        self.addCleanup(self.temporary.cleanup)
        self.work = Path(self.temporary.name)
        self.identity = {
            "head":"a"*40, "tree":"b"*40, "dirty":True,
            "runtime_sha256":{"settings/cronRunner.php":"c"*64},
            "validation_sha256":{"tools/check_post_v9.py":"d"*64},
        }

    def proof(self):
        return {
            "complete":True, "cases":95, "scenarios":list(CRON_SCENARIOS),
            "runner_sha256":checks.hashlib.sha256(
                (checks.ROOT/"settings/cronRunner.php").read_bytes()).hexdigest(),
            "actual_current_runner_executed":True,
            "actual_directory_autoprovisioning":True,
            "isolated_child_php_processes":True,
            "actual_api_maintenance_boundary":True,
            "api_before_payux":True, "api_failure_payux_finally":True,
            "actual_cron_or_bot_job_executed":False,
            "production_database_or_service_modified":False,
        }

    def cron_actor_proof(self):
        return {'complete':True,'cases':30,
                'native_marzban_bad_token_no_get_list_create':True,
                'native_marzban_auth_root_cause_classified':True,
                'native_marzban_subscription_single_login':True,
                'native_panel_no_empty_auth_request':True,
                'native_panel_auth_diagnostic_once':True,
                'actual_config_section':True,
                'cli_no_telegram_or_php_warnings':True,
                'real_verified_webhook_membership':True,
                'malformed_membership_no_warning':True,
                'unknown_actor_fails_closed':True,
                'external_network_calls':0,'live_database_mutations':0}

    def cron_call(self, proof=None, *, raw=None, missing=False, failed=False,
                  actor=None, actor_failed=False):
        def run(command, **kwargs):
            if command[:2]==['python3',str(checks.ROOT/'tools/test_post_v9_cron_config_bootstrap.py')]:
                self.assertEqual(kwargs['timeout'],25)
                self.assertTrue(kwargs['check'])
                if actor_failed:
                    raise subprocess.CalledProcessError(19,command,stderr=b'controlled config fixture failure')
                chosen=self.cron_actor_proof() if actor is None else actor
                return subprocess.CompletedProcess(command,0,stdout=json.dumps(chosen))
            self.assertEqual(command[:2],
                             ["python3",str(checks.ROOT/"tools/test_post_v9_cron_runner.py")])
            self.assertEqual(kwargs["timeout"],65)
            self.assertTrue(kwargs["check"])
            if failed:
                raise subprocess.CalledProcessError(23,command,stderr=b"controlled failure")
            if not missing:
                path=Path(command[-1])
                path.write_text(raw if raw is not None else json.dumps(proof))
            return subprocess.CompletedProcess(command,0)
        with mock.patch.object(checks.subprocess,"run",side_effect=run):
            return checks.cron_checks(self.work)

    def reject(self, name, proof):
        with self.assertRaises((RuntimeError,TypeError,AttributeError)):
            self.cron_call(proof)
        NEGATIVE_CONTROLS.append(name)

    def test_valid_complete_cron_report_is_accepted(self):
        proof=self.proof()
        self.assertEqual(self.cron_call(proof),dict(proof,
            config_actor_bootstrap=self.cron_actor_proof()))

    def test_failed_config_actor_fixture_blocks_cron_acceptance(self):
        with self.assertRaises(subprocess.CalledProcessError):
            self.cron_call(self.proof(),actor_failed=True)
        NEGATIVE_CONTROLS.append('failed_config_actor_fixture')

    def test_false_config_actor_proof_blocks_cron_acceptance(self):
        bad=self.cron_actor_proof();bad['cli_no_telegram_or_php_warnings']=False
        with self.assertRaises(RuntimeError):
            self.cron_call(self.proof(),actor=bad)
        NEGATIVE_CONTROLS.append('false_config_actor_proof')

    def test_missing_cron_report_is_not_success(self):
        with self.assertRaises(FileNotFoundError):
            self.cron_call(missing=True)
        NEGATIVE_CONTROLS.append("missing_report")

    def test_failed_subprocess_cannot_reuse_stale_success(self):
        (self.work/"post-v9-cron-runner.json").write_text(json.dumps(self.proof()))
        with self.assertRaises(subprocess.CalledProcessError):
            self.cron_call(failed=True)
        NEGATIVE_CONTROLS.append("failed_process_with_stale_report")

    def test_invalid_json_is_not_success(self):
        with self.assertRaises(ValueError):
            self.cron_call(raw="{truncated")
        NEGATIVE_CONTROLS.append("malformed_json")

    def test_wrong_report_container_is_not_success(self):
        self.reject("list_instead_of_report",[])

    def test_incomplete_cron_report_is_not_success(self):
        proof=self.proof();proof["complete"]=False
        self.reject("incomplete",proof)

    def test_insufficient_case_count_is_not_success(self):
        proof=self.proof();proof["cases"]=94
        self.reject("insufficient_cases",proof)

    def test_wrong_case_type_is_not_success(self):
        proof=self.proof();proof["cases"]="95"
        self.reject("wrong_case_type",proof)

    def test_missing_required_scenario_is_not_success(self):
        proof=self.proof()
        proof["scenarios"].remove("five_first_directory_starts_finish_one_exact_batch")
        self.reject("missing_required_scenario",proof)

    def test_missing_scenario_field_is_not_success(self):
        proof=self.proof();del proof["scenarios"]
        self.reject("missing_scenario_field",proof)

    def test_wrong_runner_hash_is_not_success(self):
        proof=self.proof();proof["runner_sha256"]="0"*64
        self.reject("wrong_runner_hash",proof)

    def test_missing_runner_hash_is_not_success(self):
        proof=self.proof();del proof["runner_sha256"]
        self.reject("missing_runner_hash",proof)

    def test_missing_or_false_required_execution_flags_are_not_success(self):
        for field in ("actual_current_runner_executed","actual_directory_autoprovisioning",
                      "isolated_child_php_processes","actual_api_maintenance_boundary",
                      "api_before_payux","api_failure_payux_finally"):
            for missing in (False,True):
                with self.subTest(field=field,missing=missing):
                    proof=self.proof()
                    if missing:del proof[field]
                    else:proof[field]=False
                    self.reject(("missing_" if missing else "false_")+field,proof)

    def test_live_or_production_claims_are_not_success(self):
        for field in ("actual_cron_or_bot_job_executed",
                      "production_database_or_service_modified"):
            with self.subTest(field=field):
                proof=self.proof();proof[field]=True
                self.reject("unsafe_"+field,proof)

    def call_mode(self, args, *, source=None, failure=None):
        output=self.work/"selected.json"
        with contextlib.ExitStack() as stack:
            full=stack.enter_context(mock.patch.object(checks,"full_checks"))
            cron=stack.enter_context(mock.patch.object(checks,"cron_checks",
                return_value=self.proof(),side_effect=failure))
            static=stack.enter_context(mock.patch.object(checks,"source_checks",
                return_value=source or {"current_additive_migration_separately_tested":False}))
            stack.enter_context(mock.patch.object(checks,"current_identity",
                                                  return_value=copy.deepcopy(self.identity)))
            captured=stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
            checks.main([*args,"--report",str(output)])
        return output,full,cron,static,captured.getvalue()

    def test_default_still_routes_full_acceptance(self):
        output,full,cron,static,stdout=self.call_mode([])
        full.assert_called_once();static.assert_called_once();cron.assert_not_called()
        self.assertEqual(full.call_args.args[0].suite,"full")
        self.assertFalse(output.exists())
        self.assertEqual(stdout,"")

    def test_explicit_full_routes_the_same_acceptance_path(self):
        _,full,cron,static,_=self.call_mode(["--suite","full"])
        full.assert_called_once();static.assert_called_once();cron.assert_not_called()

    def test_source_mode_is_truthful_partial_feedback(self):
        output,full,cron,static,stdout=self.call_mode(["--suite","source"])
        full.assert_not_called();cron.assert_not_called();static.assert_called_once()
        report=json.loads(output.read_text())
        self.assertEqual(report,json.loads(stdout))
        self.assertIs(report["complete"],False)
        self.assertIs(report["targeted_complete"],True)
        self.assertEqual(report["scope"],"source")
        self.assertEqual(report["executed"],["source"])
        self.assertEqual(report["identity"],self.identity)
        self.assertIn("optional_cron_runner",report["omitted"])
        self.assertNotIn("optional_cron_runner",report)
        self.assertIs(report["source"]["current_additive_migration_separately_tested"],False)
        self.assertIs(report["historical_suite_executed_by_this_script"],False)
        for missing in ("full_current_acceptance","current_domain_native_suites",
                        "additive_migration_execution","source_overlay_execution",
                        "frozen_historical_execution"):
            self.assertIn(missing,report["omitted"])

    def test_cron_mode_executes_static_and_cron_without_claiming_full(self):
        output,full,cron,static,_=self.call_mode(["--suite","cron"])
        full.assert_not_called();cron.assert_called_once();static.assert_called_once()
        report=json.loads(output.read_text())
        self.assertIs(report["complete"],False)
        self.assertIs(report["targeted_complete"],True)
        self.assertEqual(report["scope"],"cron")
        self.assertEqual(report["executed"],["source","cron"])
        self.assertEqual(report["optional_cron_runner"],self.proof())
        self.assertNotIn("optional_cron_runner",report["omitted"])
        self.assertIn("additive_migration_execution",report["omitted"])

    def test_selected_mode_cannot_inherit_an_unexecuted_migration_claim(self):
        output,_,_,_,_=self.call_mode(["--suite","source"],
            source={"current_additive_migration_separately_tested":True})
        self.assertIs(json.loads(output.read_text())["source"]
                      ["current_additive_migration_separately_tested"],False)

    def test_failed_cron_selection_never_writes_success_report(self):
        output=self.work/"selected.json"
        with self.assertRaises(RuntimeError):
            self.call_mode(["--suite","cron"],failure=RuntimeError("controlled failure"))
        self.assertFalse(output.exists())
        NEGATIVE_CONTROLS.append("selected_cron_failure")

    def test_changed_inputs_invalidate_selected_result(self):
        after=copy.deepcopy(self.identity);after["runtime_sha256"]["settings/cronRunner.php"]="e"*64
        with mock.patch.object(checks,"current_identity",side_effect=[self.identity,after]), \
             mock.patch.object(checks,"source_checks",return_value={}):
            with self.assertRaisesRegex(RuntimeError,"inputs changed"):
                checks.selected_checks("source")
        NEGATIVE_CONTROLS.append("input_changed_during_selection")

    def test_unknown_selected_suite_is_rejected_before_work(self):
        with mock.patch.object(checks,"source_checks") as static:
            with self.assertRaises(ValueError):
                checks.selected_checks("payments")
            static.assert_not_called()
        NEGATIVE_CONTROLS.append("unknown_selected_suite")

    def test_unknown_cli_suite_is_rejected_before_work(self):
        with mock.patch.object(checks,"source_checks") as static, \
             contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as caught:
                checks.main(["--suite","payments"])
            self.assertEqual(caught.exception.code,2)
            static.assert_not_called()
        NEGATIVE_CONTROLS.append("unknown_cli_suite")

    def test_current_identity_hashes_actual_bytes_and_keeps_dirty_state(self):
        (self.work/"settings").mkdir()
        (self.work/"settings/cronRunner.php").write_bytes(b"owned runtime bytes")
        (self.work/"tools").mkdir()
        for name in ("check_post_v9.py","test_post_v9_cron_runner.py"):
            (self.work/"tools"/name).write_bytes(name.encode())
        outputs={("rev-parse","HEAD"):b"a"*40,("rev-parse","HEAD^{tree}"):b"b"*40,
                 ("status","--porcelain"):b" M settings/cronRunner.php\n"}
        with mock.patch.object(checks,"ROOT",self.work), \
             mock.patch.object(checks,"cmd",side_effect=lambda *args:outputs[args]), \
             mock.patch.object(checks.package,"members",return_value=["settings/cronRunner.php"]):
            result=checks.current_identity()
        self.assertEqual(result["head"],"a"*40)
        self.assertEqual(result["tree"],"b"*40)
        self.assertIs(result["dirty"],True)
        self.assertEqual(result["runtime_sha256"]["settings/cronRunner.php"],
                         checks.hashlib.sha256(b"owned runtime bytes").hexdigest())


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--report",type=Path)
    args=parser.parse_args()
    suite=unittest.defaultTestLoader.loadTestsFromTestCase(ValidationModes)
    with mock.patch.object(subprocess,"Popen",side_effect=AssertionError("native subprocess forbidden in mode controls")):
        result=unittest.TextTestRunner(verbosity=2).run(suite)
    report={"complete":result.wasSuccessful(),"cases":result.testsRun,
            "negative_controls":NEGATIVE_CONTROLS,
            "failures":len(result.failures),"errors":len(result.errors),
            "native_php_executed":False,"full_acceptance_executed":False}
    if args.report:
        args.report.write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report))
    if not result.wasSuccessful():
        raise SystemExit(1)


if __name__=="__main__":
    main()
