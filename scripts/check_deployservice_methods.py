#!/usr/bin/env python3
"""Fail when DeployService.php has duplicate methods or undefined $this calls."""

from __future__ import annotations

import re
from collections import Counter
from pathlib import Path

path = Path('app/Services/DeployService.php')
source = path.read_text(encoding='utf-8')
defs = re.findall(r'(?:public|private|protected)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', source)
calls = re.findall(r'\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(', source)

def_set = set(defs)
call_set = set(calls)
duplicates = [(name, count) for name, count in Counter(defs).items() if count > 1]
undefined = sorted([name for name in call_set if name not in def_set])

print('defined_methods=', len(defs))
print('duplicate_methods=', duplicates)
print('undefined_this_calls=', undefined)
if duplicates or undefined:
    raise SystemExit(1)
