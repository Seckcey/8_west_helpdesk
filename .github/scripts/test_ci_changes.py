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
                      [".github/scripts/ci_changes.py"], ["docs/../app/code.php"], ["docs\\odd.md"]):
            with self.subTest(paths=paths):
                self.assertTrue(all(ci.classify(paths)[0].values()))

    def test_documentation_update(self):
        selected, _ = ci.classify(["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"])
        self.assertEqual(selected, {"php": False, "browser": False})

    def test_runtime_markdown_is_never_skipped(self):
        self.assertTrue(all(ci.classify(["app/public/operator-guide.md"])[0].values()))

    def test_missing_invalid_and_initial_push_base(self):
        for base in ("", "0" * 40, "HEAD", "-option", "f" * 40):
            self.assertIsNone(ci.changed_paths(base, "a" * 40))

    def test_push_cannot_skip_after_unvalidated_predecessor(self):
        for event, previous_green in (("push", False), ("workflow_dispatch", True)):
            self.assertTrue(all(ci.select_for_event(event, ["README.md"], previous_green)[0].values()))
        self.assertEqual(ci.select_for_event("push", ["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"], True)[0], {"php": False, "browser": False})
        self.assertEqual(ci.select_for_event("pull_request", ["AGENTS.md", "docs/where-things-stand.md", "deploy/README.md"])[0], {"php": False, "browser": False})

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

    def test_every_application_and_transitive_browser_input_keeps_both_jobs(self):
        for path in ("app/lib/business_reports.php", "app/lib/service_goals.php",
                     "app/lib/managed_customer_status.php", "app/lib/portal_data.php",
                     "app/public/assets/js/app.js", "app/public/time.php",
                     "tools/shots/package-lock.json", "brand/svg/favicon.svg"):
            self.assertTrue(all(ci.classify([path])[0].values()))

    def test_deployment_script_keeps_php_but_does_not_launch_browser(self):
        self.assertEqual(ci.classify(["deploy/deploy.sh"])[0], {"php": True, "browser": False})


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
