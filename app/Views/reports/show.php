<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>배포 리포트</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="princess-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Deploy Report</p>
            <h1><?= htmlspecialchars($report['history']['project_name'] ?? '배포 리포트', ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="muted"><?= htmlspecialchars($report['history']['report_file'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="ghost-button link-button" href="/dashboard">대시보드로 돌아가기</a>
    </header>

    <main class="report-layout">
        <section class="project-card report-card">
            <?php if (!empty($report['missing'])): ?>
                <p class="alert">리포트 파일을 찾을 수 없거나 읽을 수 없습니다.</p>
            <?php else: ?>
                <button type="button" class="secondary-button" data-copy-report>리포트 전체 복사</button>
                <pre class="report-content" data-report-content><?= htmlspecialchars($report['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></pre>
            <?php endif; ?>
        </section>
    </main>
    <script src="/assets/js/projects.js"></script>
</body>
</html>
