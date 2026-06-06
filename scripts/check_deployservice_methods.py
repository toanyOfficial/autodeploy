#!/usr/bin/env python3
"""Fail when DeployService.php contains duplicate class method declarations."""

from __future__ import annotations

import re
from collections import Counter
from pathlib import Path

path = Path('app/Services/DeployService.php')
source = path.read_text(encoding='utf-8')
names = re.findall(r'(?:public|private|protected)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', source)
duplicates = [(name, count) for name, count in Counter(names).items() if count > 1]

print('method_count=', len(names))
print('duplicates=', duplicates)
if duplicates:
    raise SystemExit(1)
