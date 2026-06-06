<?php
$formatSeoulDateTime = static function (?string $value): string {
    if (empty($value)) {
        return '마지막 배포일시 없음';
    }

    $utc = new DateTimeZone('UTC');
    $seoul = new DateTimeZone('Asia/Seoul');
    $date = new DateTimeImmutable($value, $utc);

    return $date->setTimezone($seoul)->format('Y-m-d H:i:s') . ' KST';
};

$formatRunningDuration = static function (?string $value): string {
    if (empty($value)) {
        return '실행 이력 없음';
    }

    $utc = new DateTimeZone('UTC');
    $date = new DateTimeImmutable($value, $utc);
    $seconds = max(1, time() - $date->getTimestamp());

    if ($seconds >= 86400) {
        return floor($seconds / 86400) . '일째 실행중';
    }

    if ($seconds >= 3600) {
        return floor($seconds / 3600) . '시간째 실행중';
    }

    if ($seconds >= 60) {
        return floor($seconds / 60) . '분째 실행중';
    }

    return $seconds . '초째 실행중';
};

$formatDeployTime = static function (?string $value) use ($formatSeoulDateTime, $formatRunningDuration): string {
    if (empty($value)) {
        return '마지막 배포일시 없음';
    }

    return $formatSeoulDateTime($value) . ' · ' . $formatRunningDuration($value);
};
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Deploy Dashboard</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="princess-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Auto Deploy</p>
            <h1>공주님의 배포 대시보드</h1>
            <p class="muted">실무자는 프로젝트를 확인하고, 필요한 배포 버튼만 누르면 돼요.</p>
        </div>
    </header>

    <?php if (!empty($flashError)): ?>
        <div class="page-alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($isDeploying)): ?>
        <div class="deploy-banner">배포가 진행 중이에요. 최신버전 빌드, 안정화버전 빌드, 재시작, 특정버전 배포 버튼이 잠시 쉬어갑니다.</div>
    <?php endif; ?>

    <div class="deploy-feedback" data-deploy-feedback hidden></div>

    <main class="dashboard-layout operator-dashboard">
        <section class="project-grid" aria-label="프로젝트 배포 카드 목록">
            <?php if (empty($projects)): ?>
                <article class="project-card empty-card">
                    <h2>아직 등록된 프로젝트가 없어요</h2>
                    <p>개발자 설정에서 첫 프로젝트를 등록한 뒤 실무자용 배포 카드가 표시됩니다.</p>
                </article>
            <?php endif; ?>

            <?php foreach ($projects as $project): ?>
                <?php $disabled = !empty($isDeploying) ? 'disabled' : ''; ?>
                <details class="project-card project-fold" data-project-card>
                    <summary class="project-summary">
                        <div class="project-summary-top">
                            <div class="project-summary-title">
                                <p class="project-key"><?= htmlspecialchars($project['project_key'], ENT_QUOTES, 'UTF-8') ?></p>
                                <h2><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            </div>
                            <div class="summary-deploy-actions" aria-label="빠른 배포 실행">
                                <form method="post" action="/projects/<?= (int) $project['id'] ?>/deploy/stable" data-deploy-form>
                                    <button type="submit" <?= $disabled ?>>안정화버전 빌드</button>
                                </form>
                                <form method="post" action="/projects/<?= (int) $project['id'] ?>/deploy/latest" data-latest-deploy-form data-deploy-form>
                                    <button type="submit" <?= $disabled ?> title="최신버전 빌드" aria-label="최신버전 빌드">최신</button>
                                </form>
                            </div>
                        </div>
                        <div class="project-summary-status">
                            <span>현재 운영중인 버전은..</span>
                            <strong><?= htmlspecialchars($project['current_deploy']['version_name'] ?? '최신 main 또는 아직 없음', ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars($formatDeployTime($project['current_deploy']['ended_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <span class="fold-hint" aria-label="프로젝트 카드 펼치기"></span>
                    </summary>

                    <section class="deploy-placeholder" aria-label="현재 운영중 버전과 배포 실행">
                        <div class="card-deploy-status" data-card-deploy-status hidden></div>
                        <div>
                            <span>현재 운영중인 버전은..</span>
                            <strong><?= htmlspecialchars($project['current_deploy']['version_name'] ?? '최신 main 또는 아직 없음', ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div>
                            <span>현재 운영중 Commit Hash</span>
                            <strong><?= htmlspecialchars($project['current_deploy']['current_commit_hash'] ?? $project['current_deploy']['deployed_commit_hash'] ?? '아직 없음', ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div>
                            <span>마지막 배포일시</span>
                            <strong><?= htmlspecialchars($formatDeployTime($project['current_deploy']['ended_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="deploy-actions secondary-deploy-actions">
                            <button type="button" disabled>재시작</button>
                        </div>
                    </section>

                    <section class="recent-versions">
                        <h3>최근 버전 5개</h3>
                        <?php if (empty($project['recent_versions'])): ?>
                            <p class="muted">등록된 버전이 아직 없어요.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($project['recent_versions'] as $version): ?>
                                    <li>
                                        <div class="version-line recent-version-title">
                                            <div class="version-title-text">
                                                <strong><?= htmlspecialchars($version['version_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php if ((int) $version['is_stable'] === 1): ?><span class="stable-badge">안정화</span><?php endif; ?>
                                            </div>
                                            <form method="post" action="/projects/<?= (int) $project['id'] ?>/deploy/versions/<?= (int) $version['id'] ?>" class="recent-version-deploy-form" data-deploy-form>
                                                <button type="submit" class="secondary-button" <?= $disabled ?>>배포하기</button>
                                            </form>
                                        </div>
                                        <span><?= htmlspecialchars($version['git_commit_hash'] ?? 'commit 미등록', ENT_QUOTES, 'UTF-8') ?></span>
                                        <small>마지막 배포일시: <?= htmlspecialchars(!empty($version['last_deployed_at']) ? $formatDeployTime($version['last_deployed_at']) : '배포 이력 없음', ENT_QUOTES, 'UTF-8') ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <section class="recent-histories">
                        <h3>최근 배포 이력 3건</h3>
                        <?php if (empty($project['recent_histories'])): ?>
                            <p class="muted">배포 이력이 아직 없어요.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($project['recent_histories'] as $history): ?>
                                    <li>
                                        <strong><?= htmlspecialchars($history['version_name'] ?? '최신 main', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($history['deploy_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <small><?= htmlspecialchars($formatSeoulDateTime($history['ended_at'] ?? $history['started_at'] ?? $history['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></small>
                                        <?php if (!empty($history['report_file'])): ?>
                                            <a class="secondary-button link-button" href="/reports/<?= (int) $history['id'] ?>">리포트 상세 조회</a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <details class="developer-tools">
                        <summary>개발자 설정 열기</summary>

                        <dl class="project-meta">
                            <div><dt>서버 경로</dt><dd><?= htmlspecialchars($project['server_path'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>포트</dt><dd><?= (int) $project['port'] ?></dd></div>
                            <div><dt>runtime_type</dt><dd><?= htmlspecialchars($project['runtime_type'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt>branch_name</dt><dd><?= htmlspecialchars($project['branch_name'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        </dl>

                        <section class="version-panel">
                            <div class="section-head">
                                <h3>버전 등록</h3>
                                <span class="mini-badge">deploy_version</span>
                            </div>
                            <form method="post" action="/projects/<?= (int) $project['id'] ?>/versions" class="project-form compact-form">
                                <label><span>버전명</span><input name="version_name" maxlength="200" required></label>
                                <label><span>Commit Hash</span><input name="git_commit_hash" maxlength="100"></label>
                                <label><span>메모</span><textarea name="memo" rows="3"></textarea></label>
                                <input type="hidden" name="is_stable" value="0">
                                <label class="check-row"><input type="checkbox" name="is_stable" value="1"><span>안정화 버전으로 저장</span></label>
                                <button type="submit" class="primary-button">버전 등록</button>
                            </form>
                        </section>

                        <?php if (!empty($project['recent_versions'])): ?>
                            <section class="version-panel">
                                <div class="section-head">
                                    <h3>버전 고급 관리</h3>
                                    <span class="mini-badge">advanced</span>
                                </div>
                                <ul class="developer-version-list">
                                    <?php foreach ($project['recent_versions'] as $version): ?>
                                        <li>
                                            <div class="version-line">
                                                <strong><?= htmlspecialchars($version['version_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php if ((int) $version['is_stable'] === 1): ?><span class="stable-badge">안정화</span><?php endif; ?>
                                            </div>
                                            <div class="version-actions">
                                                <form method="post" action="/projects/<?= (int) $project['id'] ?>/deploy/versions/<?= (int) $version['id'] ?>" data-deploy-form>
                                                    <button type="submit" class="secondary-button" <?= $disabled ?>>특정버전 배포</button>
                                                </form>
                                                <button type="button" class="secondary-button" data-edit-version>수정</button>
                                                <form method="post" action="/versions/<?= (int) $version['id'] ?>/deactivate">
                                                    <button type="submit" class="danger-button">비활성화</button>
                                                </form>
                                            </div>
                                            <form method="post" action="/versions/<?= (int) $version['id'] ?>" class="project-form compact-form version-edit-form" data-version-edit-form hidden>
                                                <input type="hidden" name="_method" value="put">
                                                <label><span>버전명</span><input name="version_name" maxlength="200" value="<?= htmlspecialchars($version['version_name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                                                <label><span>Commit Hash</span><input name="git_commit_hash" maxlength="100" value="<?= htmlspecialchars($version['git_commit_hash'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                                                <label><span>메모</span><textarea name="memo" rows="3"><?= htmlspecialchars($version['memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></label>
                                                <input type="hidden" name="is_stable" value="0">
                                                <label class="check-row"><input type="checkbox" name="is_stable" value="1" <?= (int) $version['is_stable'] === 1 ? 'checked' : '' ?>><span>안정화 버전</span></label>
                                                <button type="submit" class="primary-button">버전 수정 저장</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endif; ?>

                        <section class="version-panel">
                            <div class="card-actions">
                                <button type="button" class="secondary-button" data-edit-project>프로젝트 수정</button>
                                <form method="post" action="/projects/<?= (int) $project['id'] ?>/deactivate">
                                    <button type="submit" class="danger-button">프로젝트 비활성화</button>
                                </form>
                            </div>
                            <form method="post" action="/projects/<?= (int) $project['id'] ?>" class="project-form edit-form" data-edit-form hidden>
                                <input type="hidden" name="_method" value="put">
                                <label><span>프로젝트명</span><input name="project_name" value="<?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                                <label><span>project_key</span><input name="project_key" value="<?= htmlspecialchars($project['project_key'], ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required></label>
                                <label><span>서버 경로</span><input name="server_path" value="<?= htmlspecialchars($project['server_path'], ENT_QUOTES, 'UTF-8') ?>" required></label>
                                <label><span>포트</span><input name="port" type="number" min="1" max="65535" value="<?= (int) $project['port'] ?>" required></label>
                                <label><span>runtime_type</span><input name="runtime_type" value="<?= htmlspecialchars($project['runtime_type'], ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required></label>
                                <label><span>branch_name</span><input value="main" disabled></label>
                                <button type="submit" class="primary-button">프로젝트 수정 저장</button>
                            </form>
                        </section>
                    </details>
                </details>
            <?php endforeach; ?>
        </section>

        <details class="panel add-panel developer-tools global-developer-tools">
            <summary>프로젝트 등록 / 개발자 설정</summary>
            <p class="muted">프로젝트 등록은 드물게 사용하는 개발자용 기능이라 접어두었어요.</p>

            <section class="system-restore-panel" aria-label="서버 재부팅 자동화">
                <div>
                    <p class="eyebrow">서버 기본설정 자동화</p>
                    <h2>서버 재부팅 + 기본설정</h2>
                    <p class="muted">일반 프로젝트 배포와 분리된 개발자 전용 작업입니다. 재부팅 후 DB, Auto Deploy, 전체 활성 프로젝트 안정화버전 배포를 순서대로 실행합니다.</p>
                </div>
                <form method="post" action="/api/system/reboot-and-restore" data-reboot-restore-form>
                    <button type="submit" class="danger-button">서버 재부팅 + 기본설정</button>
                </form>
                <div class="deploy-feedback" data-reboot-restore-feedback hidden></div>
                <div class="system-log-actions">
                    <button type="button" class="secondary-button" data-reboot-log-button>최근 로그 조회</button>
                </div>
                <pre class="system-log" data-reboot-log hidden></pre>
            </section>

            <form method="post" action="/projects" class="project-form">
                <label><span>프로젝트명</span><input name="project_name" maxlength="100" required></label>
                <label><span>project_key</span><input name="project_key" maxlength="50" required></label>
                <label><span>서버 경로</span><input name="server_path" maxlength="255" required></label>
                <label><span>포트</span><input name="port" type="number" min="1" max="65535" required></label>
                <label><span>runtime_type</span><input name="runtime_type" maxlength="50" placeholder="python_static 또는 nextjs_bun" required></label>
                <label><span>branch_name</span><input value="main" disabled></label>
                <button type="submit" class="primary-button">프로젝트 등록</button>
            </form>
        </details>
    </main>
    <script src="/assets/js/projects.js"></script>
</body>
</html>
