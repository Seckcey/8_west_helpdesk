"""Exercise the real aggregator entry point with Actions dependency outcomes."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

SCRIPT = Path(__file__).with_name("ci_php_result.py")
SHARDS = ("php-core", "php-westy-control", "php-westy-portal", "php-time", "php-customers")


def dependencies(selected="true", result="success"):
    return {"changes": {"result": "success", "outputs": {"php": selected}},
            **{name: {"result": result} for name in SHARDS}}


class PhpResultTests(unittest.TestCase):
    def run_gate(self, needs=None, raw=None):
        env = os.environ.copy()
        env.pop("GITHUB_STEP_SUMMARY", None)
        env.pop("PHP_NEEDS", None)
        if needs is not None or raw is not None:
            env["PHP_NEEDS"] = raw if raw is not None else json.dumps(needs)
        return subprocess.run([sys.executable, "-B", str(SCRIPT)], env=env,
                              capture_output=True, text=True, timeout=10)

    def test_all_selected_shards_must_succeed(self):
        result = self.run_gate(dependencies())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Every selected PHP shard passed", result.stdout)
        for name in SHARDS:
            for state in ("failure", "cancelled", "skipped", "neutral", "timed_out", "", None):
                with self.subTest(shard=name, state=state):
                    needs = dependencies()
                    needs[name]["result"] = state
                    self.assertNotEqual(self.run_gate(needs).returncode, 0)

    def test_intentional_skip_is_explicit_and_never_claims_test_proof(self):
        result = self.run_gate(dependencies("false", "skipped"))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("No PHP tests ran", result.stdout)
        for name in SHARDS:
            for state in ("success", "failure", "cancelled", None):
                with self.subTest(shard=name, state=state):
                    needs = dependencies("false", "skipped")
                    needs[name]["result"] = state
                    self.assertNotEqual(self.run_gate(needs).returncode, 0)

    def test_selector_failure_cancellation_skip_or_unknown_cannot_go_green(self):
        for state in ("failure", "cancelled", "skipped", None):
            for selected, shard in (("true", "success"), ("false", "skipped")):
                with self.subTest(state=state, selected=selected):
                    needs = dependencies(selected, shard)
                    needs["changes"]["result"] = state
                    self.assertNotEqual(self.run_gate(needs).returncode, 0)
        for output in (None, {}, {"php": True}, {"php": ""}, {"php": "maybe"}):
            needs = dependencies()
            needs["changes"]["outputs"] = output
            self.assertNotEqual(self.run_gate(needs).returncode, 0)

    def test_missing_dependencies_or_results_fail_closed(self):
        for name in ("changes", *SHARDS):
            for selected, shard in (("true", "success"), ("false", "skipped")):
                with self.subTest(name=name, selected=selected):
                    needs = dependencies(selected, shard)
                    del needs[name]
                    self.assertNotEqual(self.run_gate(needs).returncode, 0)
                    needs = dependencies(selected, shard)
                    needs[name] = {}
                    self.assertNotEqual(self.run_gate(needs).returncode, 0)
        needs = dependencies()
        needs["untracked-shard"] = {"result": "success"}
        self.assertNotEqual(self.run_gate(needs).returncode, 0)

    def test_absent_malformed_or_wrong_shape_input_fails_closed(self):
        self.assertNotEqual(self.run_gate().returncode, 0)
        for raw in ("not json", "null", "[]", '"success"', "{}"):
            with self.subTest(raw=raw):
                self.assertNotEqual(self.run_gate(raw=raw).returncode, 0)


if __name__ == "__main__":
    unittest.main()
