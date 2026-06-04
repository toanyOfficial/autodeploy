<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Deploy Dashboard</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="princess-page dashboard-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Auto Deploy</p>
            <h1>프로젝트 관리</h1>
        </div>
        <form method="post" action="/logout">
            <button type="submit" class="ghost-button">로그아웃</button>
        </form>
    </header>

    <main class="dashboard-layout">
        <section class="panel add-panel">
            <h2>새 프로젝트 추가</h2>
            <form method="post" action="/projects" class="project-form">
                <label><span>프로젝트명</span><input name="project_name" required></label>
                <label><span>project_key</span><input name="project_key" maxlength="50" required></label>
                <label><span>서버 경로</span><input name="server_path" required></label>
                <label><span>포트</span><input name="port" type="number" min="1" max="65535" required></label>
                <label><span>runtime_type</span><input name="runtime_type" maxlength="50" required></label>
                <label><span>branch_name</span><input value="main" disabled></label>
                <button type="submit" class="primary-button">프로젝트 추가</button>
            </form>
        </section>

        <section class="project-grid" aria-label="프로젝트 목록">
            <?php if (empty($projects)): ?>
                <article class="project-card empty-card">
                    <h2>아직 등록된 프로젝트가 없어요</h2>
                    <p>왼쪽 카드에서 첫 프로젝트를 귀엽게 등록해 주세요.</p>
                </article>
            <?php endif; ?>

            <?php foreach ($projects as $project): ?>
                <article class="project-card" data-project-card>
                    <div class="card-head">
                        <div>
                            <p class="project-key"><?= htmlspecialchars($project['project_key'], ENT_QUOTES, 'UTF-8') ?></p>
                            <h2><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <span class="badge">main</span>
                    </div>

                    <dl class="project-meta">
                        <div><dt>서버 경로</dt><dd><?= htmlspecialchars($project['server_path'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>포트</dt><dd><?= (int) $project['port'] ?></dd></div>
                        <div><dt>runtime_type</dt><dd><?= htmlspecialchars($project['runtime_type'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt>branch_name</dt><dd><?= htmlspecialchars($project['branch_name'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                    </dl>

                    <section class="deploy-placeholder">
                        <div>
                            <span>현재 운영중 버전</span>
                            <strong><?= htmlspecialchars($project['current_deploy']['version_name'] ?? '아직 없음', ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div>
                            <span>마지막 배포일시</span>
                            <strong><?= htmlspecialchars($project['current_deploy']['ended_at'] ?? '아직 없음', ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="deploy-actions">
                            <button disabled>최신버전 빌드</button>
                            <button disabled>안정화버전 빌드</button>
                        </div>
                    </section>

                    <section class="recent-versions">
                        <h3>최근 버전 3개</h3>
                        <?php if (empty($project['recent_versions'])): ?>
                            <p class="muted">등록된 버전이 아직 없어요.</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($project['recent_versions'] as $version): ?>
                                    <li>
                                        <strong><?= htmlspecialchars($version['version_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars($version['git_commit_hash'] ?? 'commit 미등록', ENT_QUOTES, 'UTF-8') ?></span>
                                        <small><?= htmlspecialchars($version['last_deployed_at'] ?? '배포 이력 없음', ENT_QUOTES, 'UTF-8') ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <div class="card-actions">
                        <button type="button" class="secondary-button" data-edit-project>수정</button>
                        <form method="post" action="/projects/<?= (int) $project['id'] ?>/deactivate">
                            <button type="submit" class="danger-button">비활성화</button>
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
                        <button type="submit" class="primary-button">수정 저장</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <script src="/assets/js/projects.js"></script>
</body>
</html>
