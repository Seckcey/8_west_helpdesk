#!/usr/bin/env python3
"""Bounded complete-dump comparison; no SQL execution, normalization or data output.

A reviewed per-capture policy may permit only MySQL bug 110825's redundant
utf8mb4 declaration on exact CREATE TABLE column lines. Every other byte,
including all records and stored programs, must equal the original export.
The protected outer operator owns capture, actual restore and root-only files.
"""
import hashlib
import re
from pathlib import Path

MAX_LINE_BYTES = 4 * 1024**2
SQL_STRING = rb"'(?:\\[^\r\n]|''|[^'\\\r\n])*'"
CHARACTER_COLUMN = re.compile(
    rb'(?P<prefix>  `(?:``|[^`\r\n])+` (?:char\([0-9]+\)|varchar\([0-9]+\)|'
    rb'(?:tiny|medium|long)?text|(?:enum|set)\(' + SQL_STRING
    + rb'(?:,' + SQL_STRING + rb')*\)))'
    rb'(?P<suffix> COLLATE utf8mb4_[a-z0-9_]+(?: [^\r\n]*)?,?\n)\Z')
TABLE_START = re.compile(rb'CREATE TABLE `(?:``|[^`\r\n])+` \(\n\Z')


def bounded_lines(stream):
    while True:
        line = stream.readline(MAX_LINE_BYTES + 1)
        if not line:
            return
        if len(line) > MAX_LINE_BYTES:
            raise RuntimeError('dump line exceeds reviewed memory bound')
        yield line


def make_policy(source: Path, column_lines=()):
    """Produce a proposal from explicit line numbers, never infer approval.

    The caller must bind the returned entire policy's digest in its reviewed
    invocation. An empty list requires raw byte equality. A policy is tied to
    one complete captured export and cannot be reused for a later backup.
    """
    numbers = list(column_lines)
    if any(type(n) is not int or n < 1 for n in numbers) or len(set(numbers)) != len(numbers):
        raise RuntimeError('unique positive column line numbers required')
    wanted = set(numbers)
    original = hashlib.sha256()
    predicted = hashlib.sha256()
    untouched = hashlib.sha256()
    pins = []
    size = lines = 0
    in_table = False
    delimiter = b';'
    with source.open('rb') as stream:
        for number, line in enumerate(bounded_lines(stream), 1):
            lines = number
            size += len(line)
            original.update(line)
            if line.startswith(b'DELIMITER '):
                delimiter = line[len(b'DELIMITER '):].strip()
            if TABLE_START.fullmatch(line) and delimiter == b';':
                in_table = True
            expected = line
            if number in wanted:
                match = CHARACTER_COLUMN.fullmatch(line)
                if not in_table or delimiter != b';' or match is None:
                    raise RuntimeError('policy line is not a reviewed table column')
                expected = match['prefix'] + b' CHARACTER SET utf8mb4' + match['suffix']
                pins.append({'line': number, 'source_sha256': hashlib.sha256(line).hexdigest(),
                             'restored_sha256': hashlib.sha256(expected).hexdigest()})
            else:
                untouched.update(line)
            predicted.update(expected)
            if in_table and line.startswith(b') ENGINE='):
                in_table = False
    if size == 0 or len(pins) != len(wanted) or in_table or delimiter != b';':
        raise RuntimeError('incomplete export or unknown policy line')
    return {'contract': 'tenant-ai-full-dump-policy-v1',
            'source_sha256': original.hexdigest(), 'source_bytes': size, 'source_lines': lines,
            'expected_restored_sha256': predicted.hexdigest(), 'added_bytes': len(pins) * 22,
            'column_lines': pins, 'all_other_bytes_sha256': untouched.hexdigest(),
            'line_memory_cap_bytes': MAX_LINE_BYTES}


def verify_policy(source: Path, policy):
    if not isinstance(policy, dict) or not isinstance(policy.get('column_lines'), list):
        raise RuntimeError('complete captured-export policy required')
    try:
        regenerated = make_policy(source, [pin['line'] for pin in policy['column_lines']])
    except (KeyError, TypeError) as error:
        raise RuntimeError('policy line pins malformed') from error
    if policy != regenerated:
        raise RuntimeError('captured export or policy pin mismatch')
    return regenerated


def compare_complete_export(source: Path, restored: Path, policy):
    """Accept only the complete expected byte stream; never print SQL fragments."""
    verify_policy(source, policy)
    pins = {pin['line']: pin for pin in policy['column_lines']}
    source_hash = hashlib.sha256()
    restored_hash = hashlib.sha256()
    source_bytes = restored_bytes = lines = 0
    mismatches = 0
    with source.open('rb') as left_file, restored.open('rb') as right_file:
        left = iter(bounded_lines(left_file))
        right = iter(bounded_lines(right_file))
        while True:
            a = next(left, b'')
            b = next(right, b'')
            if not a and not b:
                break
            lines += 1
            source_hash.update(a)
            restored_hash.update(b)
            source_bytes += len(a)
            restored_bytes += len(b)
            expected = a
            if lines in pins:
                match = CHARACTER_COLUMN.fullmatch(a)
                if match is None or hashlib.sha256(a).hexdigest() != pins[lines]['source_sha256']:
                    raise RuntimeError('captured column changed during comparison')
                expected = match['prefix'] + b' CHARACTER SET utf8mb4' + match['suffix']
            if expected != b:
                mismatches += 1
    if (mismatches or source_hash.hexdigest() != policy['source_sha256']
            or restored_hash.hexdigest() != policy['expected_restored_sha256']
            or source_bytes != policy['source_bytes'] or lines != policy['source_lines']
            or restored_bytes != source_bytes + policy['added_bytes']):
        raise RuntimeError('complete restored export differs; preserve freeze and original backup')
    return {'source_sha256': source_hash.hexdigest(), 'restored_sha256': restored_hash.hexdigest(),
            'source_bytes': source_bytes, 'restored_bytes': restored_bytes, 'lines': lines,
            'changed_column_lines': len(pins), 'unexpected_differences': 0,
            'all_record_and_program_bytes_unchanged': True}
