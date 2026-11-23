"""Verify a desk labels release in memory, then write the exact pair to scratch."""

import hashlib
import json
import re
import stat
import sys
from pathlib import Path
from zipfile import BadZipFile, ZipFile


def unique_keys(pairs):
    result = dict(pairs)
    if len(result) != len(pairs):
        raise ValueError("duplicate JSON key")
    return result


def read_json(raw):
    if b"\r" in raw or not raw.endswith(b"\n"):
        raise ValueError("expected LF-terminated catalog JSON")
    return json.loads(raw.decode("utf-8"), object_pairs_hook=unique_keys)


def verify_archive(archive, output, version):
    with ZipFile(archive) as release:
        entries = release.infolist()
        if sorted(entry.orig_filename for entry in entries) != ["help-manifest.json", "labels-index.json"]:
            raise ValueError("expected exactly help-manifest.json and labels-index.json")
        for entry in entries:
            mode = stat.S_IFMT(entry.external_attr >> 16)
            if mode not in (0, stat.S_IFREG) or entry.external_attr & 0x10:
                raise ValueError("labels archive members must be regular files")
        index_raw = release.read("labels-index.json")
        manifest_raw = release.read("help-manifest.json")

    manifest = read_json(manifest_raw)
    expected_file = {"sha256": hashlib.sha256(index_raw).hexdigest(), "bytes": len(index_raw)}
    expected_manifest = {
        "format": "8west-help-manifest-v1",
        "index": "labels",
        "version": version,
        "files": {"labels-index.json": expected_file},
        "hash_format": "SHA-256 of the exact LF file bytes",
    }
    if manifest != expected_manifest or type(manifest["files"]["labels-index.json"]["bytes"]) is not int:
        raise ValueError("labels manifest differs from the selected version or index bytes")

    index = read_json(index_raw)
    if (not isinstance(index, dict) or index.keys() != {"format", "index", "version", "articles"}
            or index["format"] != "8west-help-labels-v1" or index["index"] != "labels"
            or index["version"] != version or not isinstance(index["articles"], list)):
        raise ValueError("invalid desk labels index")
    ids = set()
    for article in index["articles"]:
        if not isinstance(article, dict) or article.keys() != {"id", "app", "ui_labels"}:
            raise ValueError("desk articles may contain only id, app and ui_labels")
        article_id = article["id"]
        if (not isinstance(article_id, str) or len(article_id) > 64
                or not re.fullmatch(r"[a-z][a-z0-9]*(?:-[a-z0-9]+)*", article_id) or article_id in ids):
            raise ValueError("invalid or duplicate desk article ID")
        ids.add(article_id)
        labels = article["ui_labels"]
        if (article["app"] != "safeharbor" or not isinstance(labels, list) or not labels
                or not all(isinstance(label, str) and 1 <= len(label) <= 80 for label in labels)):
            raise ValueError(f"{article_id}: expected Safeharbor desk UI labels")

    (output / "labels-index.json").write_bytes(index_raw)
    (output / "help-manifest.json").write_bytes(manifest_raw)
    return hashlib.sha256(manifest_raw).hexdigest()


if __name__ == "__main__":
    try:
        print(verify_archive(sys.argv[1], Path(sys.argv[2]), sys.argv[3]))
    except (BadZipFile, OSError, ValueError) as error:
        sys.exit(f"Cannot sync desk labels: {error}")
