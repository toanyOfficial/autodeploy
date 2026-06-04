<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Deploy 로그인</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="princess-page auth-page">
    <main class="login-shell">
        <section class="login-card">
            <p class="eyebrow">Auto Deploy</p>
            <h1>공주님 배포실 입장하기</h1>
            <p class="login-copy">안전한 배포를 위해 먼저 반짝이는 열쇠를 확인할게요.</p>

            <?php if (!empty($error)): ?>
                <div class="alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/login" class="stack-form">
                <label>
                    <span>ID</span>
                    <input type="text" name="admin_id" autocomplete="username" required>
                </label>
                <label>
                    <span>PASSWORD</span>
                    <input type="password" name="admin_password" autocomplete="current-password" required>
                </label>
                <button type="submit" class="primary-button">로그인</button>
            </form>
        </section>
    </main>
</body>
</html>
