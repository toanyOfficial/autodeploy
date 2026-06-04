document.querySelectorAll('[data-edit-project]').forEach((button) => {
  button.addEventListener('click', () => {
    const card = button.closest('[data-project-card]');
    const form = card.querySelector('[data-edit-form]');
    form.hidden = !form.hidden;
  });
});

document.querySelectorAll('[data-edit-version]').forEach((button) => {
  button.addEventListener('click', () => {
    const item = button.closest('li');
    const form = item.querySelector('[data-version-edit-form]');
    form.hidden = !form.hidden;
  });
});

document.querySelectorAll('[data-latest-deploy-form]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const message = '개발자가 아니라면 안정화버전으로 빌드하시는 것을 추천드립니다.\n\n최신버전으로 빌드하시는게 정말 맞나요?\n\n이 작업은 현재 운영중인 서비스를 최신 main 브랜치 기준으로 재배포합니다.';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  });
});

document.querySelectorAll('[data-copy-report]').forEach((button) => {
  button.addEventListener('click', async () => {
    const report = document.querySelector('[data-report-content]');
    if (!report) return;

    await navigator.clipboard.writeText(report.textContent);
    button.textContent = '복사 완료';
  });
});
