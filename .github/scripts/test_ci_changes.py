"""Regression tests for CI selection, including real Git rename/delete diffs."""
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("ci_changes", Path(__file__).with_name("ci_changes.py"))
ci = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ci)


class SelectionTests(unittest.TestCase):
    def test_unknown_or_missing_diff_runs_every_job(self):
        for paths in (None, [], ["future-runtime/file"], [".github/workflows/validate.yml"],
                      [".github/scripts/ci_changes.py"], ["docs/../app/code.php"], ["docs\\odd.md"],
                      ["app/new-runtime/input"], ["app/tests/fixtures/customer_workspace/operations.json"]):
            with self.subTest(paths=paths):
                self.assertTrue(all(ci.classify(paths)[0].values()))

    def test_documentation_update(self):
        selected, _ = ci.classify(["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"])
        self.assertEqual(selected, {"php": False, "browser": False, "protected_cli": False})

    def test_runtime_markdown_is_never_skipped(self):
        self.assertTrue(all(ci.classify(["app/public/operator-guide.md"])[0].values()))

    def test_missing_invalid_and_initial_push_base(self):
        for base in ("", "0" * 40, "HEAD", "-option", "f" * 40):
            self.assertIsNone(ci.changed_paths(base, "a" * 40))

    def test_push_cannot_skip_after_unvalidated_predecessor(self):
        for event, previous_green in (("push", False), ("workflow_dispatch", True)):
            self.assertTrue(all(ci.select_for_event(event, ["README.md"], previous_green)[0].values()))
        self.assertEqual(ci.select_for_event("push", ["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"], True)[0], {"php": False, "browser": False, "protected_cli": False})
        self.assertEqual(ci.select_for_event("pull_request", ["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"])[0], {"php": False, "browser": False, "protected_cli": False})

    def test_predecessor_requires_latest_exact_green_push(self):
        base = "a" * 40
        green = {"head_sha": base, "head_branch": ci.DEFAULT_BRANCH, "event": "push",
                 "path": ".github/workflows/" + ci.WORKFLOW_FILE,
                 "status": "completed", "conclusion": "success"}
        self.assertTrue(ci.previous_run_is_green([green], base))
        self.assertFalse(ci.previous_run_is_green([], base))
        for override in ({"conclusion": "failure"}, {"conclusion": "cancelled"},
                         {"status": "in_progress", "conclusion": None},
                         {"head_sha": "b" * 40}, {"head_branch": "feature"},
                         {"event": "pull_request"}, {"path": ".github/workflows/other.yml"}):
            failed = green | override
            self.assertFalse(ci.previous_run_is_green([failed, green], base))

    def test_api_failure_cannot_enable_partial_push_validation(self):
        with patch.dict(os.environ, {"GITHUB_REPOSITORY": "owner/repo", "GH_TOKEN": "test-only"}):
            with patch.object(ci, "urlopen", side_effect=OSError("unavailable")):
                self.assertFalse(ci.previous_push_is_green("a" * 40))
            with patch.object(ci, "urlopen", return_value=io.BytesIO(b"not json")):
                self.assertFalse(ci.previous_push_is_green("a" * 40))

    def test_api_query_and_response_are_bound_to_exact_predecessor(self):
        base = "a" * 40
        run = {"head_sha": base, "head_branch": ci.DEFAULT_BRANCH, "event": "push",
               "path": ".github/workflows/" + ci.WORKFLOW_FILE,
               "status": "completed", "conclusion": "success"}
        response = io.BytesIO(json.dumps({"workflow_runs": [run]}).encode())
        with patch.dict(os.environ, {"GITHUB_REPOSITORY": "owner/repo", "GH_TOKEN": "test-only"}):
            with patch.object(ci, "urlopen", return_value=response) as request:
                self.assertTrue(ci.previous_push_is_green(base))
                url = request.call_args.args[0].full_url
                self.assertIn("head_sha=" + base, url)
                self.assertIn("event=push", url)
                self.assertIn("branch=" + ci.DEFAULT_BRANCH, url)
                self.assertIn("per_page=1", url)

    def test_actual_browser_roots_and_transitive_dependencies_keep_browser(self):
        for path in ("app/lib/business_reports.php", "app/lib/service_goals.php",
                     "app/lib/managed_customer_status.php", "app/lib/portal_data.php",
                     "app/lib/suite_managed_provider.php", "app/lib/portal_access.php",
                     "app/lib/portal_shell.php", "app/lib/portal_desktop_controls.php",
                     "app/lib/portal_devices.php", "app/lib/portal_devices_render.php",
                     "app/lib/portal_device_operations.php", "app/lib/portal_device_operations_render.php",
                     "app/lib/portal_mobile.php", "app/lib/portal_mobile_render.php",
                     "app/lib/portal_security_orders.php", "app/lib/portal_security_orders_render.php",
                     "app/lib/portal_computer_time.php", "app/lib/portal_westy_device_instructions.php",
                     "app/lib/westy_workflow.php", "app/lib/render.php",
                     "app/tests/portal_computer_time_test.php", "app/tests/westy_queue_owner_test.php",
                     "app/public/portal/westy.php", "app/public/api/time_entry_adjustment.php",
                     "app/public/assets/js/app.js", "app/public/time.php",
                     "tools/shots/package-lock.json", "brand/svg/favicon.svg"):
            with self.subTest(path=path):
                self.assertTrue(ci.classify([path])[0]["browser"])

    def test_backend_only_changes_keep_php_without_chromium(self):
        for path in ("app/lib/portal_westy.php", "app/lib/portal_westy_control.php",
                     "app/lib/portal_westy_desktop.php", "app/lib/portal_westy_runs.php",
                     "app/lib/portal_westy_tenant_ai.php"):
            with self.subTest(path=path):
                self.assertEqual(ci.classify([path])[0], {"php": True, "browser": False, "protected_cli": True})
        # New/other library consumers remain conservative, rather than relying on
        # an exhaustive, easily stale dependency allowlist.
        self.assertTrue(all(ci.classify(["app/lib/new_shared_consumer.php"])[0].values()))

    def test_independent_php_tests_do_not_start_unrelated_runners(self):
        for path in ("app/tests/portal_westy_desktop_continuation_test.php",
                     "app/tests/portal_westy_desktop_diagnostics_test.php",
                     "app/tests/portal_westy_feedback_mysql_test.php",
                     "app/tests/portal_employee_device_access_mysql_test.php",
                     "app/tests/trusted_jwks_https_test.php", "app/tests/private_file_cache_test.php",
                     "app/tests/portal_mobile_mysql_test.php"):
            self.assertEqual(ci.classify([path])[0], {"php": True, "browser": False, "protected_cli": False})

    def test_protected_cli_keeps_real_fixtures_schema_and_migration_dependencies(self):
        for path in ("app/tests/portal_devices_mysql_test.php", "app/tests/portal_westy_fixture.php",
                     "app/tests/portal_device_operations_scenarios.php", "app/tests/portal_security_orders_scenarios.php",
                     "app/tests/endpoint_tool_runs_cli_mysql_test.php", "app/tests/endpoint_tool_runs_v2_cli_mysql_test.php",
                     "app/tests/endpoint_tool_runs_migration_mysql_test.php", "app/lib/eightwestid/eightwestid.php",
                     "app/config/config.sample.php", "app/db/schema.sql", "app/db/migrations/endpoint_tool_runs_v2.sql",
                     "deploy/endpoint_tool_runs_v2_migration.php", "deploy/endpoint_tool_runs_migration_catalog.json",
                     "deploy/managed_provider_migration.php"):
            with self.subTest(path=path):
                self.assertTrue(ci.classify([path])[0]["protected_cli"])

    def test_browser_tools_assets_and_mixed_changes_select_consumers(self):
        self.assertEqual(ci.classify(["tools/shots/portal-contract.test.mjs"])[0],
                         {"php": False, "browser": True, "protected_cli": False})
        self.assertEqual(ci.classify(["brand/svg/favicon.svg", "app/public/assets/js/portal-westy.js"])[0],
                         {"php": True, "browser": True, "protected_cli": False})
        self.assertTrue(all(ci.classify(["app/lib/portal_westy_desktop.php", "tools/shots/portal-contract.test.mjs"])[0].values()))

    def test_deployment_script_keeps_php_but_does_not_launch_browser(self):
        self.assertEqual(ci.classify(["deploy/deploy.sh"])[0], {"php": True, "browser": False, "protected_cli": False})


class GitDiffTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="ci-selection-test-")
        self.repo = Path(self.temp.name)
        self.git("init", "-q")
        self.git("config", "user.name", "CI selection test")
        self.git("config", "user.email", "ci-selection@example.invalid")
        self.git("config", "core.autocrlf", "false")
        (self.repo / "app").mkdir()
        (self.repo / "docs").mkdir()
        (self.repo / "app" / "sample.php").write_text("<?php echo 1;\n", encoding="utf-8")
        self.git("add", ".")
        self.git("commit", "-qm", "base")
        self.base = self.git("rev-parse", "HEAD")

    def tearDown(self):
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.repo, stderr=subprocess.PIPE).decode().strip()

    def commit(self):
        self.git("add", "-A")
        self.git("commit", "-qm", "change")
        return self.git("rev-parse", "HEAD")

    def test_runtime_rename_to_documentation_keeps_deleted_source(self):
        (self.repo / "app" / "sample.php").rename(self.repo / "docs" / "sample.md")
        paths = ci.changed_paths(self.base, self.commit(), self.repo)
        self.assertEqual(set(paths), {"app/sample.php", "docs/sample.md"})
        self.assertTrue(ci.classify(paths)[0]["php"])

    def test_deleted_runtime_file_runs_application_checks(self):
        (self.repo / "app" / "sample.php").unlink()
        paths = ci.changed_paths(self.base, self.commit(), self.repo)
        self.assertEqual(paths, ["app/sample.php"])
        self.assertTrue(ci.classify(paths)[0]["php"])

    def test_backend_rename_into_browser_source_keeps_both_consumers(self):
        (self.repo / "app" / "lib").mkdir()
        backend = self.repo / "app" / "lib" / "portal_westy_desktop.php"
        backend.write_text("<?php echo 1;\n", encoding="utf-8")
        base = self.commit()
        backend.rename(backend.with_name("portal_render.php"))
        paths = ci.changed_paths(base, self.commit(), self.repo)
        self.assertEqual(set(paths), {"app/lib/portal_westy_desktop.php", "app/lib/portal_render.php"})
        self.assertTrue(all(ci.classify(paths)[0].values()))

    def test_deleted_protected_fixture_keeps_executed_cli(self):
        (self.repo / "app" / "tests").mkdir()
        fixture = self.repo / "app" / "tests" / "portal_westy_fixture.php"
        fixture.write_text("<?php echo 1;\n", encoding="utf-8")
        base = self.commit()
        fixture.unlink()
        paths = ci.changed_paths(base, self.commit(), self.repo)
        self.assertTrue(ci.classify(paths)[0]["protected_cli"])

    def test_spaces_unicode_and_multiple_commit_push(self):
        (self.repo / "docs" / "release notes café.md").write_text("Notes\n", encoding="utf-8")
        self.commit()
        (self.repo / "app" / "sample.php").write_text("<?php echo 2;\n", encoding="utf-8")
        paths = ci.changed_paths(self.base, self.commit(), self.repo)
        self.assertEqual(set(paths), {"docs/release notes café.md", "app/sample.php"})
        self.assertTrue(ci.classify(paths)[0]["php"])

    def test_equal_commits_fall_back_to_full_validation(self):
        self.assertTrue(all(ci.classify(ci.changed_paths(self.base, self.base, self.repo))[0].values()))

    def test_pull_request_merge_diff_excludes_existing_base_changes(self):
        default_branch = self.git("branch", "--show-current")
        self.git("checkout", "-qb", "documentation")
        (self.repo / "docs" / "notes.md").write_text("Notes\n", encoding="utf-8")
        self.commit()
        self.git("checkout", "-q", default_branch)
        (self.repo / "app" / "sample.php").write_text("<?php echo 2;\n", encoding="utf-8")
        latest_base = self.commit()
        self.git("merge", "--no-ff", "-qm", "test merge", "documentation")
        paths = ci.changed_paths(latest_base, self.git("rev-parse", "HEAD"), self.repo)
        self.assertEqual(paths, ["docs/notes.md"])


if __name__ == "__main__":
    unittest.main()
