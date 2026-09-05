"""Select CI jobs from an exact Git diff; uncertainty always selects every job."""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
from urllib.parse import urlencode
from urllib.request import Request, urlopen

JOBS = ("php", "browser")
DEFAULT_BRANCH = "main"
WORKFLOW_FILE = "validate.yml"


def full_selection(reason: str) -> tuple[dict[str, bool], str]:
    return {job: True for job in JOBS}, reason


def is_documentation(path: str) -> bool:
    return path in {"README.md", "AGENTS.md", "deploy/README.md"} or (
        path.startswith("docs/") and path.endswith(".md")
    )


def classify(paths: list[str] | None) -> tuple[dict[str, bool], str]:
    if not paths:
        return full_selection("No reliable nonempty change list; running every validation job.")
    selected = {job: False for job in JOBS}
    docs_only = True
    for path in paths:
        if not path or "\\" in path or path.startswith("/") or ".." in path.split("/"):
            return full_selection("Unrecognized path; running every validation job.")
        if is_documentation(path):
            continue
        docs_only = False
        if path.startswith(("app/", "brand/", "tools/shots/")):
            selected["php"] = selected["browser"] = True
        elif path.startswith("deploy/"):
            selected["php"] = True
        else:
            return full_selection("Unclassified or CI path; running every validation job.")
    reason = (
        "Documentation-only update; expensive application jobs are unnecessary."
        if docs_only else "Jobs selected from all changed paths, including deletions and renames."
    )
    return selected, reason


def changed_paths(base: str, head: str, repo: str | Path = ".") -> list[str] | None:
    if any(not re.fullmatch(r"[0-9a-f]{40}", sha) or sha == "0" * 40 for sha in (base, head)):
        return None
    try:
        for sha in (base, head):
            subprocess.run(
                ["git", "cat-file", "-e", sha + "^{commit}"],
                cwd=repo, check=True, capture_output=True, timeout=30,
            )
        result = subprocess.run(
            ["git", "diff", "--no-renames", "--name-only", "-z", base, head, "--"],
            cwd=repo, check=True, capture_output=True, timeout=30,
        )
        return [item.decode("utf-8", "surrogateescape") for item in result.stdout.split(b"\0") if item]
    except (OSError, subprocess.SubprocessError):
        return None


def previous_run_is_green(runs: list[dict], base: str) -> bool:
    """Only the latest matching run can establish a validated predecessor."""
    if not runs:
        return False
    run = runs[0]
    return (
        run.get("head_sha") == base
        and run.get("head_branch") == DEFAULT_BRANCH
        and run.get("event") == "push"
        and run.get("path") == ".github/workflows/" + WORKFLOW_FILE
        and run.get("status") == "completed"
        and run.get("conclusion") == "success"
    )


def previous_push_is_green(base: str) -> bool:
    repository = os.environ.get("GITHUB_REPOSITORY", "")
    token = os.environ.get("GH_TOKEN", "")
    if not re.fullmatch(r"[0-9a-f]{40}", base) or not re.fullmatch(r"[\w.-]+/[\w.-]+", repository) or not token:
        return False
    query = urlencode({"head_sha": base, "event": "push", "branch": DEFAULT_BRANCH, "per_page": 1})
    api = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")
    request = Request(
        f"{api}/repos/{repository}/actions/workflows/{WORKFLOW_FILE}/runs?{query}",
        headers={"Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json"},
    )
    try:
        with urlopen(request, timeout=20) as response:
            result = json.load(response)
        return previous_run_is_green(result.get("workflow_runs", []), base)
    except (OSError, ValueError, TypeError, AttributeError):
        return False


def select_for_event(event: str, paths: list[str] | None, previous_green: bool = False):
    if event not in {"push", "pull_request"}:
        return full_selection("Unsupported event; running every validation job.")
    if event == "push" and not previous_green:
        return full_selection("Previous default-branch commit has no verified green push run; running every validation job.")
    return classify(paths)


def main() -> None:
    event = os.environ.get("EVENT_NAME", "")
    base = os.environ.get("BASE_SHA", "")
    paths = changed_paths(base, os.environ.get("HEAD_SHA", ""))
    selection, reason = select_for_event(event, paths, event == "push" and previous_push_is_green(base))
    outputs = "".join(f"{name}={str(enabled).lower()}\n" for name, enabled in selection.items())
    print(reason)
    print(outputs, end="")
    if output := os.environ.get("GITHUB_OUTPUT"):
        with open(output, "a", encoding="utf-8") as stream:
            stream.write(outputs)
    if summary := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(summary, "a", encoding="utf-8") as stream:
            stream.write("### Validation selection\n\n" + reason + "\n\n")
            for name, enabled in selection.items():
                stream.write(f"- {name}: {'run' if enabled else 'skip (unaffected by this update)'}\n")


if __name__ == "__main__":
    main()
