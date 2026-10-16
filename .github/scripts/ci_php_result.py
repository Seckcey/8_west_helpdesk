"""Keep the public php status fail-closed across the isolated PHP jobs."""
from __future__ import annotations

import json
import os
import sys

SHARDS = ("php-core", "php-westy-control", "php-westy-portal", "php-time", "php-customers")


def validate(needs: dict) -> str:
    if not isinstance(needs, dict) or set(needs) != {"changes", *SHARDS}:
        raise ValueError("Missing or unexpected PHP dependencies")
    changes = needs["changes"]
    if not isinstance(changes, dict) or changes.get("result") != "success":
        raise ValueError("Change selection did not succeed")
    outputs = changes.get("outputs")
    selected = outputs.get("php") if isinstance(outputs, dict) else None
    if selected not in ("true", "false"):
        raise ValueError("Change selection has no explicit PHP decision")
    expected = "success" if selected == "true" else "skipped"
    for name in SHARDS:
        shard = needs[name]
        if not isinstance(shard, dict) or shard.get("result") != expected:
            raise ValueError(f"{name} must be {expected}; received {shard!r}")
    return (
        "Every selected PHP shard passed."
        if selected == "true" else
        "PHP intentionally not selected; every PHP shard was skipped. No PHP tests ran."
    )


def main() -> int:
    try:
        message = validate(json.loads(os.environ["PHP_NEEDS"]))
    except (KeyError, ValueError, TypeError) as error:
        print(f"PHP validation failed: {error}", file=sys.stderr)
        return 1
    print(message)
    if summary := os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(summary, "a", encoding="utf-8") as stream:
            stream.write("### PHP validation\n\n" + message + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
