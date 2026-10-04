#!/usr/bin/env python3
"""Build exact additive guards from reviewed disposable MySQL schema evidence.

This does not connect to a database or authorize a release. Review the generated
SQL/catalog diff and run the protected migration fixtures before using it.
"""
import hashlib
import json
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
capture = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
sql_path = root / "app/db/migrations/20261004_tenant_ai.sql"
sql = sql_path.read_text(encoding="utf-8")
sql = re.sub(r"-- BEGIN EXACT TENANT AI GUARD\n.*?-- END EXACT TENANT AI GUARD\n", "", sql, flags=re.S)
metadata = capture["metadata"]
states = capture["states"]
# Capture only DDL checkpoints: session guard statements do not create states.
states = [v for i, v in enumerate(states) if i == 0 or v != states[i - 1]]
bits = {}
for table, entry in metadata.items():
    bits[("table", table)] = len(bits)
for entry in metadata.values():
    for trigger in entry["triggers"]:
        bits[("trigger", trigger["TRIGGER_NAME"])] = len(bits)
assert len(bits) < 30

def exists(kind, name):
    catalog, column = ("TABLES", "TABLE_NAME") if kind == "table" else ("TRIGGERS", "TRIGGER_NAME")
    scope = "TABLE_SCHEMA" if kind == "table" else "TRIGGER_SCHEMA"
    return f"EXISTS(SELECT 1 FROM information_schema.{catalog} WHERE {scope}=DATABASE() AND {column}='{name}')"

shape = []
for table, entry in metadata.items():
    checks = []
    for kind, part in entry.items():
        if kind == "triggers":
            continue
        query, value = part["query"], part["value"]
        query = query.replace("COUNT(*) AS count", f"COUNT(*)={value['count']}")
        query = query.replace(",SHA2", " AND SHA2", 1)
        query = query.replace(" AS sha256", f"='{value['sha256']}'")
        checks.append("(" + query + ")")
    shape.append(f"(NOT {exists('table', table)} OR (" + " AND ".join(checks) + "))")
    for trigger in entry["triggers"]:
        name = trigger["TRIGGER_NAME"]
        digest = hashlib.sha256(trigger["ACTION_STATEMENT"].encode()).hexdigest()
        shape.append("(SELECT COUNT(*)=0 OR (COUNT(*)=1 AND SUM("
                     f"EVENT_OBJECT_TABLE='{table}' AND EVENT_MANIPULATION='{trigger['EVENT_MANIPULATION']}' "
                     f"AND ACTION_TIMING='{trigger['ACTION_TIMING']}' "
                     f"AND SHA2(ACTION_STATEMENT,256)='{digest}')=1) "
                     f"FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='{name}')")
    names = [t["TRIGGER_NAME"] for t in entry["triggers"]]
    allowed = " AND TRIGGER_NAME NOT IN (" + ",".join("'" + n + "'" for n in names) + ")" if names else ""
    shape.append(f"NOT EXISTS(SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='{table}'{allowed})")

masks = []
for state in states:
    masks.append(sum(1 << bit for (kind, name), bit in bits.items()
                     if (state["tables"].get(name) is not None if kind == "table" else name in state["triggers"])))
mask_sql = " + ".join(f"IF({exists(kind, name)},{1 << bit},0)" for (kind, name), bit in bits.items())
guard = ["-- BEGIN EXACT TENANT AI GUARD",
         "-- Refuse drift, unrecognised partial order, or populated incomplete guards before DDL.",
         "SET @tai_previous_group_concat=@@SESSION.group_concat_max_len;",
         "SET SESSION group_concat_max_len=1048576;",
         "SET @tai_shape_ok=\n " + "\n AND ".join(shape) + ";",
         "SET @tai_schema_mask=" + mask_sql + ";",
         "SET @tai_guard_sql=IF(COALESCE(@tai_shape_ok,0)=1 AND @tai_schema_mask IN (" + ",".join(map(str, masks)) + "),'DO 0','SELECT * FROM TENANT_AI_SCHEMA_MISMATCH_STOP');",
         "PREPARE tai_guard FROM @tai_guard_sql;", "EXECUTE tai_guard;", "DEALLOCATE PREPARE tai_guard;",
         "SET @tai_existing_rows=0;"]
for table in metadata:
    guard.extend([f"SET @tai_guard_sql=IF({exists('table', table)},'SELECT @tai_existing_rows+COUNT(*) INTO @tai_existing_rows FROM {table}','DO 0');",
                  "PREPARE tai_guard FROM @tai_guard_sql;", "EXECUTE tai_guard;", "DEALLOCATE PREPARE tai_guard;"])
guard.extend([f"SET @tai_guard_sql=IF(@tai_schema_mask={masks[-1]} OR @tai_existing_rows=0,'DO 0','SELECT * FROM TENANT_AI_POPULATED_PARTIAL_STOP');",
              "PREPARE tai_guard FROM @tai_guard_sql;", "EXECUTE tai_guard;", "DEALLOCATE PREPARE tai_guard;",
              "SET SESSION group_concat_max_len=@tai_previous_group_concat;", "-- END EXACT TENANT AI GUARD"])
sql = "\n".join(guard) + "\n" + sql
sql_path.write_text(sql, encoding="utf-8", newline="\n")
schema_path = root / "app/db/schema.sql"
schema = schema_path.read_text(encoding="utf-8")
schema, count = re.subn(r"(-- BEGIN TENANT AI[^\n]*\n).*?(-- END TENANT AI[^\n]*(?:\n|$))", lambda m: m[1] + sql + m[2], schema, count=1, flags=re.S)
assert count == 1
schema_path.write_text(schema, encoding="utf-8", newline="\n")
catalog = json.dumps(states, indent=4, ensure_ascii=False) + "\n"
(root / "deploy/tenant_ai_migration_catalog.json").write_text(catalog, encoding="utf-8", newline="\n")
lib_path = root / "deploy/tenant_ai_migration.php"
lib = lib_path.read_text(encoding="utf-8")
for key, value in [("TAI_MIGRATION_SHA256", "'" + hashlib.sha256(sql.encode()).hexdigest() + "'"),
                   ("TAI_MIGRATION_SIZE_BYTES", str(len(sql.encode()))),
                   ("TAI_CATALOG_SHA256", "'" + hashlib.sha256(catalog.encode()).hexdigest() + "'")]:
    lib = re.sub(r"const " + key + r" = .*?;", "const " + key + " = " + value + ";", lib)
lib_path.write_text(lib, encoding="utf-8", newline="\n")
print(f"Pinned {len(states)-1} DDL checkpoints; review and validate before release")
