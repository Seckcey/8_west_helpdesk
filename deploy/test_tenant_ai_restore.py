"""Synthetic byte-stream regression tests; no database or production files."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('tenant_ai_restore', Path(__file__).with_name('tenant_ai_restore.py'))
restore = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore)
BASE = (b'CREATE TABLE `records` (\n'
        b'  `id` int NOT NULL,\n'
        b'  `label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,\n'
        b'  PRIMARY KEY (`id`)\n'
        b') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n'
        b"INSERT INTO `records` VALUES (1,'synthetic record');\n"
        b'DELIMITER ;;\nCREATE PROCEDURE `read_records`() SELECT 1;;\nDELIMITER ;\n')


class CompleteRestoreTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='tenant-ai-restore-fixture-')
        self.addCleanup(self.directory.cleanup)
        self.source = Path(self.directory.name) / 'source.sql'
        self.restored = Path(self.directory.name) / 'restored.sql'
        self.source.write_bytes(BASE)
        self.restored.write_bytes(BASE)

    def test_raw_complete_equality(self):
        result = restore.compare_complete_export(self.source, self.restored, restore.make_policy(self.source))
        self.assertEqual(result['unexpected_differences'], 0)
        self.assertTrue(result['all_record_and_program_bytes_unchanged'])

    def test_exact_column_pin_allows_only_redundant_utf8mb4(self):
        policy = restore.make_policy(self.source, [3])
        self.restored.write_bytes(BASE.replace(b'varchar(80) COLLATE', b'varchar(80) CHARACTER SET utf8mb4 COLLATE'))
        result = restore.compare_complete_export(self.source, self.restored, policy)
        self.assertEqual(result['restored_bytes'], len(BASE) + 22)
        self.assertEqual(result['changed_column_lines'], 1)

    def test_raw_policy_does_not_silently_accept_charset_change(self):
        self.restored.write_bytes(BASE.replace(b'varchar(80) COLLATE', b'varchar(80) CHARACTER SET utf8mb4 COLLATE'))
        with self.assertRaises(RuntimeError):
            restore.compare_complete_export(self.source, self.restored, restore.make_policy(self.source))

    def test_record_change_with_equal_counts_is_refused(self):
        self.restored.write_bytes(BASE.replace(b'synthetic record', b'different record'))
        with self.assertRaises(RuntimeError):
            restore.compare_complete_export(self.source, self.restored, restore.make_policy(self.source))

    def test_program_change_is_refused(self):
        self.restored.write_bytes(BASE.replace(b'SELECT 1', b'SELECT 2'))
        with self.assertRaises(RuntimeError):
            restore.compare_complete_export(self.source, self.restored, restore.make_policy(self.source))

    def test_column_pin_cannot_whitelist_an_insert_or_integer(self):
        for line in [2, 6]:
            with self.subTest(line=line), self.assertRaises(RuntimeError):
                restore.make_policy(self.source, [line])

    def test_charset_grammar_inside_program_is_never_allowed(self):
        self.source.write_bytes(b'DELIMITER ;;\nCREATE PROCEDURE `p`() BEGIN\n'+BASE[:BASE.index(b'INSERT INTO')]+b'END;;\nDELIMITER ;\n')
        with self.assertRaises(RuntimeError):
            restore.make_policy(self.source, [5])

    def test_later_backup_cannot_reuse_original_policy(self):
        policy = restore.make_policy(self.source, [3])
        self.source.write_bytes(BASE.replace(b'synthetic record', b'new record'))
        with self.assertRaises(RuntimeError):
            restore.verify_policy(self.source, policy)

    def test_altered_policy_pins_are_refused(self):
        policy = restore.make_policy(self.source, [3])
        for field in ['source_sha256', 'expected_restored_sha256', 'all_other_bytes_sha256']:
            altered = copy.deepcopy(policy)
            altered[field] = '0'*64
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                restore.verify_policy(self.source, altered)
        altered = copy.deepcopy(policy)
        altered['column_lines'][0]['source_sha256'] = '0'*64
        with self.assertRaises(RuntimeError):
            restore.verify_policy(self.source, altered)

    def test_truncated_or_extra_export_is_refused(self):
        policy = restore.make_policy(self.source)
        for data in [BASE[:-1], BASE+b'\n']:
            self.restored.write_bytes(data)
            with self.subTest(size=len(data)), self.assertRaises(RuntimeError):
                restore.compare_complete_export(self.source, self.restored, policy)

    def test_no_duplicate_unknown_or_boolean_line_pins(self):
        for numbers in [[3,3], [99], [True], [0], [-1]]:
            with self.subTest(numbers=numbers), self.assertRaises(RuntimeError):
                restore.make_policy(self.source, numbers)

    def test_oversized_line_fails_before_materializing_dump(self):
        self.source.write_bytes(b'x'*(restore.MAX_LINE_BYTES+1))
        with self.assertRaises(RuntimeError):
            restore.make_policy(self.source)


if __name__ == '__main__':
    unittest.main()
