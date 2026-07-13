#!/usr/bin/env python3
"""Validate nextjs_bun deploy flow keeps production untouched until candidate build is verified."""

from __future__ import annotations

from pathlib import Path

source = Path('app/Services/DeployService.php').read_text(encoding='utf-8')

def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(message)

runtime_idx = source.index("if ($runtime === 'nextjs_bun')")
fetch_idx = source.index("[STEP] fetch repository")
build_idx = source.index("[STEP] build candidate")
verify_idx = source.index("[STEP] verify candidate artifacts")
copy_idx = source.index("copyCandidateNext")
stop_idx = source.index("[STEP] stop existing service")
switch_idx = source.index("[STEP] switch production commit")
install_idx = source.index("[STEP] install candidate build artifacts")
start_idx = source.index("[STEP] start new service")

require(runtime_idx < fetch_idx < build_idx < verify_idx < copy_idx < stop_idx < switch_idx < install_idx < start_idx,
        'nextjs_bun safe deployment stages are not in the expected order')

pre_nextjs = source[source.index('private function runRuntimeFlow'):runtime_idx]
require("git', 'reset', '--hard'" not in pre_nextjs,
        'runRuntimeFlow resets production before entering nextjs_bun safe flow')

safe_flow = source[source.index('private function runNextjsBunSafeFlow'):source.index('private function resolveGitCommit')]
for marker in [
    '[SAFE] existing production service was not stopped',
    '[SAFE] existing production .next was not modified',
    '[ROLLBACK] restore previous commit',
    '[ROLLBACK] restore previous .next',
    '[ROLLBACK SUCCESS] previous service healthy',
    '[CRITICAL] deployment failed and rollback failed',
]:
    require(marker in source, f'missing required log marker: {marker}')

require("'rm -rf .next'" not in safe_flow, 'safe flow must not delete production .next before build')
require("git', 'worktree', 'add', '--detach'" in source, 'safe flow must create detached git worktree')
require('bun install --frozen-lockfile' in source, 'safe flow must install dependencies with frozen lockfile when possible')
require('bun run build' in source and '$buildPath' in safe_flow, 'candidate build must run in the worktree path')

print('nextjs_bun_safe_flow=ok')
