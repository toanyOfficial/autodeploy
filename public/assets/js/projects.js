function bindProjectInteractions(root = document) {
  root.querySelectorAll('[data-edit-project]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', () => {
      const card = button.closest('[data-project-card]');
      const form = card.querySelector('[data-edit-form]');
      form.hidden = !form.hidden;
    });
  });

  root.querySelectorAll('[data-edit-version]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', () => {
      const item = button.closest('li');
      const form = item.querySelector('[data-version-edit-form]');
      form.hidden = !form.hidden;
    });
  });

  root.querySelectorAll('[data-deploy-form]:not([data-bound])').forEach((form) => {
    form.dataset.bound = 'true';
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (form.matches('[data-latest-deploy-form]')) {
        const message = '개발자가 아니라면 안정화버전으로 빌드하시는 것을 추천드립니다.\n\n최신버전으로 빌드하시는게 정말 맞나요?\n\n이 작업은 현재 운영중인 서비스를 최신 main 브랜치 기준으로 재배포합니다.';
        if (!window.confirm(message)) return;
      }

      await runDeploy(form);
    });
  });


  root.querySelectorAll('[data-report-operation]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
      const confirmMessage = button.dataset.confirmMessage;
      if (confirmMessage && !window.confirm(confirmMessage)) return;

      await runReportOperation(button);
    });
  });

  root.querySelectorAll('[data-copy-report]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
      const report = document.querySelector('[data-report-content]');
      if (!report) return;

      await navigator.clipboard.writeText(report.textContent);
      button.textContent = '복사 완료';
    });
  });
}


async function runReportOperation(button) {
  const card = button.closest('[data-report-operation-card]');
  const resultBox = card?.querySelector('[data-report-operation-result]');
  const operation = button.dataset.reportOperation;
  const historyId = button.dataset.historyId;

  if (!operation || !historyId) return;

  button.disabled = true;
  showDeployFeedback(resultBox, 'running', '작업을 실행 중입니다. 잠시만 기다려주세요.');

  try {
    const response = await fetch(`/api/reports/${encodeURIComponent(historyId)}/operation`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ operation }),
    });
    const result = await response.json();
    const status = result.success ? 'success' : 'failed';
    const log = result.log ? `<pre class="operation-log">${escapeHtml(result.log)}</pre>` : '';
    showDeployFeedback(resultBox, status, `${escapeHtml(result.message || (result.success ? '작업이 완료되었습니다.' : '작업에 실패했습니다. 상세 로그를 확인해주세요.'))}${log}`);

    if (typeof result.content === 'string') {
      const reportContent = document.querySelector('[data-report-content]');
      if (reportContent) reportContent.textContent = result.content;
    }
  } catch (error) {
    showDeployFeedback(resultBox, 'failed', '작업에 실패했습니다. 상세 로그를 확인해주세요.');
  } finally {
    button.disabled = false;
  }
}

async function runDeploy(form) {
  const card = form.closest('[data-project-card]');
  const feedback = document.querySelector('[data-deploy-feedback]');
  const cardStatus = card?.querySelector('[data-card-deploy-status]');

  setDeployButtonsDisabled(true);
  showDeployFeedback(feedback, 'running', '배포 요청이 접수되었습니다. 배포가 진행 중입니다. 잠시만 기다려주세요.');
  showDeployFeedback(cardStatus, 'running', '상태: 배포 진행중');

  try {
    const response = await fetch(toApiDeployUrl(form.action), {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const result = await response.json();
    const success = Boolean(result.success);
    const reportLink = result.report_url ? ` <a class="link-button secondary-button" href="${escapeAttribute(result.report_url)}">리포트 보기</a>` : '';

    if (success) {
      showDeployFeedback(feedback, 'success', escapeHtml(result.message || '배포가 완료되었습니다.'));
      showDeployFeedback(cardStatus, 'success', '상태: 배포 성공');
    } else {
      showDeployFeedback(feedback, 'failed', `${escapeHtml(result.message || '배포에 실패했습니다. 리포트를 확인해주세요.')}${reportLink}`);
      showDeployFeedback(cardStatus, 'failed', '상태: 배포 실패');
    }

    await refreshDashboardContent();
  } catch (error) {
    showDeployFeedback(feedback, 'failed', '배포에 실패했습니다. 리포트를 확인해주세요.');
    showDeployFeedback(cardStatus, 'failed', '상태: 배포 실패');
  } finally {
    setDeployButtonsDisabled(false);
  }
}

function toApiDeployUrl(action) {
  const url = new URL(action, window.location.origin);
  if (!url.pathname.startsWith('/api/')) {
    url.pathname = `/api${url.pathname}`;
  }
  return url.toString();
}

function showDeployFeedback(element, status, html) {
  if (!element) return;
  element.hidden = false;
  element.dataset.status = status;
  element.innerHTML = html;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttribute(value) {
  const url = new URL(value, window.location.origin);
  return escapeHtml(url.pathname + url.search + url.hash);
}

function setDeployButtonsDisabled(disabled) {
  document.querySelectorAll('form[action*="/deploy/"] button, .deploy-actions > button').forEach((button) => {
    button.disabled = disabled || button.closest('form') === null;
  });
}

async function refreshDashboardContent() {
  const response = await fetch(window.location.href, { credentials: 'same-origin' });
  const html = await response.text();
  const next = new DOMParser().parseFromString(html, 'text/html');
  const currentGrid = document.querySelector('.project-grid');
  const nextGrid = next.querySelector('.project-grid');
  const currentGlobalTools = document.querySelector('.global-developer-tools');
  const nextGlobalTools = next.querySelector('.global-developer-tools');

  if (currentGrid && nextGrid) {
    currentGrid.replaceWith(nextGrid);
    bindProjectInteractions(nextGrid);
  }
  if (currentGlobalTools && nextGlobalTools) {
    currentGlobalTools.replaceWith(nextGlobalTools);
  }
}

bindProjectInteractions();

(() => {
  const month = new Date().getMonth() + 1;
  const season = month >= 3 && month <= 5 ? 'spring'
    : month >= 6 && month <= 8 ? 'summer'
      : month >= 9 && month <= 11 ? 'autumn'
        : 'winter';

  document.body.dataset.season = season;

  const layer = document.createElement('div');
  layer.className = 'seasonal-particles';
  layer.setAttribute('aria-hidden', 'true');

  const symbols = {
    spring: ['🌸', '🌸', '✨'],
    summer: ['🛟', '🛟', '✨'],
    autumn: ['🍂', '🍁', '✨'],
    winter: ['❄️', '❅', '✨'],
  };

  const count = Math.max(24, window.innerWidth < 640 ? 26 : 34);
  const fallWindow = window.innerWidth < 640 ? 22 : 28;

  for (let index = 0; index < count; index += 1) {
    const particle = document.createElement('span');
    particle.className = 'seasonal-particle';
    particle.textContent = symbols[season][index % symbols[season].length];

    if (index === 0) {
      particle.classList.add('seasonal-particle-debug');
      particle.style.setProperty('--x', '50vw');
      particle.style.setProperty('--delay', '0s');
      particle.style.setProperty('--duration', '28s');
      particle.style.setProperty('--size', '24px');
      particle.style.setProperty('--drift', '54px');
      particle.style.setProperty('--curve-a', '-28px');
      particle.style.setProperty('--curve-b', '42px');
      particle.style.setProperty('--curve-c', '14px');
      particle.style.setProperty('--spin', '210deg');
    } else {
      const evenlySpacedDelay = (index / count) * fallWindow;
      const smallJitter = Math.random() * 0.45;
      const drift = -88 + Math.random() * 176;

      particle.style.setProperty('--x', `${Math.random() * 100}vw`);
      particle.style.setProperty('--delay', `${evenlySpacedDelay + smallJitter}s`);
      particle.style.setProperty('--duration', `${24 + Math.random() * 12}s`);
      particle.style.setProperty('--size', `${13 + Math.random() * 10}px`);
      particle.style.setProperty('--drift', `${drift}px`);
      particle.style.setProperty('--curve-a', `${-36 + Math.random() * 72}px`);
      particle.style.setProperty('--curve-b', `${-52 + Math.random() * 104}px`);
      particle.style.setProperty('--curve-c', `${drift * 0.35 + (-24 + Math.random() * 48)}px`);
      particle.style.setProperty('--spin', `${80 + Math.random() * 220}deg`);
    }

    layer.appendChild(particle);
  }

  document.body.appendChild(layer);
})();
