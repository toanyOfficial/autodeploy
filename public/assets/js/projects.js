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

  root.querySelectorAll('[data-reboot-restore-form]:not([data-bound])').forEach((form) => {
    form.dataset.bound = 'true';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const message = '서버를 실제로 재부팅합니다.\n\n재부팅 후 아래 작업이 자동 수행됩니다.\n\n1. DB 실행\n2. Auto Deploy 실행\n3. 등록된 활성 프로젝트 전체 안정화버전 배포\n\n약 1~3분 동안 서비스 접속이 중단될 수 있습니다.\n\n계속 진행하시겠습니까?';
      if (!window.confirm(message)) return;

      await runRebootRestore(form);
    });
  });

  root.querySelectorAll('[data-reboot-status-button]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
      await loadRebootAutomationStatus(button);
    });
  });

  root.querySelectorAll('[data-reboot-log-button]:not([data-bound])').forEach((button) => {
    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
      await loadRebootDeployLog(button);
    });
  });

  if (root.querySelector('[data-reboot-install-status]')) {
    loadRebootAutomationStatus();
  }


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
      await copyReportToClipboard(button);
    });
  });
}



async function loadRebootAutomationStatus(button = null) {
  const statusBox = document.querySelector('[data-reboot-install-status]');
  const runButton = document.querySelector('[data-reboot-restore-button]');
  if (button) button.disabled = true;
  if (statusBox) {
    statusBox.dataset.status = 'checking';
    statusBox.innerHTML = '<strong>설치 상태 확인 중...</strong><p class="muted">필수 서버 파일과 sudo 권한을 확인하고 있습니다.</p>';
  }

  try {
    const response = await fetch('/api/system/reboot-and-restore/status', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const status = await response.json();
    renderRebootAutomationStatus(status);
    if (runButton) runButton.disabled = !status.installed;
    return status;
  } catch (error) {
    const fallback = {
      installed: false,
      missing: ['설치 상태 확인 API 호출에 실패했습니다.'],
      checks: [{ label: 'status api', ok: false, message: error?.message || String(error) }],
      guide: '/docs/reboot-automation.md',
    };
    renderRebootAutomationStatus(fallback);
    if (runButton) runButton.disabled = true;
    return fallback;
  } finally {
    if (button) button.disabled = false;
  }
}

function renderRebootAutomationStatus(status) {
  const statusBox = document.querySelector('[data-reboot-install-status]');
  if (!statusBox) return;

  const installed = Boolean(status?.installed);
  const checks = Array.isArray(status?.checks) ? status.checks : [];
  const missing = Array.isArray(status?.missing) ? status.missing : [];
  statusBox.dataset.status = installed ? 'installed' : 'missing';

  const checkItems = checks.map((check) => {
    const mark = check.ok ? '✅' : '❌';
    const detail = check.ok ? 'OK' : (check.message || check.path || '확인이 필요합니다.');
    const command = check.command ? `<small>검증 명령: ${escapeHtml(check.command)}</small>` : '';
    const stderr = check.stderr ? `<small>stderr: ${escapeHtml(check.stderr)}</small>` : '';
    return `<li><span>${mark}</span><div><strong>${escapeHtml(check.label || check.key || 'check')}</strong><small>${escapeHtml(detail)}</small>${command}${stderr}</div></li>`;
  }).join('');

  if (installed) {
    statusBox.innerHTML = `
      <strong>재부팅 자동화 설치 완료</strong>
      <p class="muted">필수 서버 파일, 로그 경로, sudo 권한이 확인되었습니다. 실행 전 Confirm 후 재부팅 자동화를 시작할 수 있습니다.</p>
      <ul class="system-check-list">${checkItems}</ul>
    `;
    return;
  }

  statusBox.innerHTML = `
    <strong>재부팅 자동화 기능이 아직 설치되지 않았습니다.</strong>
    <p class="muted">누락 항목을 설치한 뒤 다시 확인해 주세요. 설치 전에는 서버 재부팅을 실행하지 않습니다.</p>
    ${missing.length ? `<div class="missing-list"><span>누락 항목</span><ul>${missing.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul></div>` : ''}
    <ul class="system-check-list">${checkItems}</ul>
    <a class="secondary-button link-button" href="${escapeAttribute(status?.guide || '/docs/reboot-automation.md')}" target="_blank" rel="noopener">설치 가이드 열기</a>
  `;
}

function renderInstallRequiredMessage(status) {
  const missing = Array.isArray(status?.missing) ? status.missing : [];
  const missingItems = missing.length ? `<ul>${missing.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '';
  return `재부팅 자동화 기능이 아직 설치되지 않았습니다.<br>설치 가이드를 확인해 주세요.${missingItems}`;
}

async function runRebootRestore(form) {
  const feedback = document.querySelector('[data-reboot-restore-feedback]');

  setOperationLock(true);
  showDeployFeedback(feedback, 'running', '서버 재부팅 및 기본설정 자동화를 요청하고 있습니다. 요청이 성공하면 곧 접속이 끊길 수 있습니다.');

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const result = await response.json();

    if (response.ok && result.success) {
      showDeployProgress('전체 활성 프로젝트', []);
      showDeployFeedback(feedback, 'success', escapeHtml(result.message || '서버 재부팅 및 자동 안정화버전 배포가 예약되었습니다.'));
      return;
    }

    showDeployFeedback(feedback, 'failed', formatRebootRestoreFailure(result));
    await loadRebootDeployLog();
  } catch (error) {
    showDeployFeedback(feedback, 'failed', `서버 재부팅 자동화 요청 중 오류가 발생했습니다.<pre class="operation-log">${escapeHtml(error?.message || String(error))}</pre>`);
    await loadRebootDeployLog();
  } finally {
    if (document.body.dataset.deployProgress !== 'running') {
      setOperationLock(false);
    }
  }
}

async function loadRebootDeployLog(button = null) {
  const logBox = document.querySelector('[data-reboot-log]');
  if (!logBox) return;

  if (button) button.disabled = true;
  logBox.hidden = false;
  logBox.textContent = '로그를 불러오는 중입니다...';

  try {
    const response = await fetch('/api/system/reboot-and-restore/log', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const result = await response.json();
    logBox.textContent = result.log || result.message || '표시할 로그가 없습니다.';
  } catch (error) {
    logBox.textContent = '로그 조회에 실패했습니다.';
  } finally {
    if (button) button.disabled = false;
  }
}

function formatRebootRestoreFailure(result) {
  const message = escapeHtml(result?.message || '서버 재부팅 자동화 요청에 실패했습니다.');
  const details = [
    result?.exit_code !== undefined ? `exit code: ${result.exit_code}` : '',
    result?.command ? `command: ${result.command}` : '',
    result?.stdout ? `stdout:\n${result.stdout}` : '',
    result?.stderr ? `stderr:\n${result.stderr}` : '',
    result?.detail ? `detail:\n${result.detail}` : '',
  ].filter(Boolean).join('\n\n');

  if (!details) return message;

  return `${message}<pre class="operation-log">${escapeHtml(details)}</pre>`;
}

async function copyReportToClipboard(button) {
  const report = document.querySelector('[data-report-content]');
  if (!report) return;

  const copied = await writeClipboard(report.textContent);
  button.textContent = copied ? '복사 완료' : '복사 실패';
}

async function writeClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (error) {
      // Fall back to execCommand below for HTTP/internal deployments.
    }
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', 'readonly');
  textarea.style.position = 'fixed';
  textarea.style.top = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();
  const copied = document.execCommand('copy');
  textarea.remove();
  return copied;
}

function getDeployProjectName(form) {
  const card = form.closest('[data-project-card]');
  return card?.querySelector('.project-summary-title h2')?.textContent?.trim()
    || card?.querySelector('h2')?.textContent?.trim()
    || '선택한 프로젝트';
}

function showDeployProgress(projectName, projects = []) {
  const overlay = deployProgressOverlay();
  overlay.querySelector('[data-deploy-progress-project]').textContent = projectName;
  renderDeployProgressStatuses(projects);
  overlay.hidden = false;
  document.body.dataset.deployProgress = 'running';
  document.body.classList.add('is-deploying');
  setOperationLock(true);
}

function hideDeployProgress() {
  const overlay = document.querySelector('[data-deploy-progress-overlay]');
  if (overlay) overlay.hidden = true;
  delete document.body.dataset.deployProgress;
  document.body.classList.remove('is-deploying');
  setOperationLock(false);
}

function normalizeDeployProgressOverlay(overlay) {
  if (!overlay) return null;

  if (overlay.parentElement !== document.body || overlay.nextElementSibling !== null) {
    document.body.appendChild(overlay);
  }

  return overlay;
}

function deployProgressOverlay() {
  let overlay = normalizeDeployProgressOverlay(document.querySelector('[data-deploy-progress-overlay]'));
  if (overlay) return overlay;

  overlay = document.createElement('div');
  overlay.className = 'deploy-progress-overlay';
  overlay.dataset.deployProgressOverlay = 'true';
  overlay.hidden = true;
  overlay.setAttribute('aria-live', 'polite');
  overlay.innerHTML = `
    <div class="deploy-progress-modal" role="dialog" aria-modal="true" aria-label="배포 진행 상황">
      <span class="deploy-progress-spinner" aria-hidden="true"></span>
      <div class="deploy-progress-content">
        <p class="eyebrow">배포 진행중</p>
        <strong><span data-deploy-progress-project></span> 프로젝트가 빌드중입니다.</strong>
        <small>작업이 끝날 때까지 다른 오퍼레이션은 잠시 비활성화됩니다.</small>
        <div class="deploy-progress-status-list" data-deploy-progress-status-list></div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  return normalizeDeployProgressOverlay(overlay);
}

function renderDeployProgressStatuses(projects) {
  const list = document.querySelector('[data-deploy-progress-status-list]');
  if (!list) return;

  const items = Array.isArray(projects) ? projects : [];
  if (items.length === 0) {
    list.innerHTML = '';
    return;
  }

  const labels = { success: '배포성공', running: '배포중', pending: '배포대기' };
  const order = ['success', 'running', 'pending'];
  list.innerHTML = order.map((state) => {
    const stateItems = items.filter((item) => item?.state === state);
    if (stateItems.length === 0) return '';

    return `
      <div class="deploy-progress-status-group" data-state="${state}">
        <span>${labels[state]}</span>
        <ul>${stateItems.map((item) => `<li>${escapeHtml(item.project_name || item.project_key || '프로젝트')}</li>`).join('')}</ul>
      </div>
    `;
  }).join('');
}

async function refreshDeployProgressStatus() {
  const overlay = document.querySelector('[data-deploy-progress-overlay]');
  if (!overlay || overlay.hidden) return;

  try {
    const response = await fetch('/api/deploy/status', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });
    const status = await response.json();
    const projects = Array.isArray(status?.projects) ? status.projects : [];
    const running = projects.find((item) => item?.state === 'running');

    if (running?.project_name) {
      overlay.querySelector('[data-deploy-progress-project]').textContent = running.project_name;
    }
    renderDeployProgressStatuses(projects);

    if (!status.deploying && overlay.dataset.serverDeploying === 'true') {
      hideDeployProgress();
      await refreshDashboardContent();
    }
  } catch (error) {
    // 상태 폴링 실패는 다음 주기에서 복구될 수 있으므로 화면 상태를 유지합니다.
  }
}

function setOperationLock(locked) {
  document.body.dataset.operationLocked = locked ? 'true' : 'false';
  document.querySelectorAll('button, input, textarea, select').forEach((control) => {
    if (locked) {
      if (!Object.prototype.hasOwnProperty.call(control.dataset, 'wasDisabled')) {
        control.dataset.wasDisabled = control.disabled ? 'true' : 'false';
      }
      control.disabled = true;
      return;
    }

    if (Object.prototype.hasOwnProperty.call(control.dataset, 'wasDisabled')) {
      control.disabled = control.dataset.wasDisabled === 'true';
      delete control.dataset.wasDisabled;
    }
  });

  document.querySelectorAll('main a, main summary').forEach((element) => {
    if (locked) {
      if (!Object.prototype.hasOwnProperty.call(element.dataset, 'wasTabIndex')) {
        element.dataset.wasTabIndex = element.getAttribute('tabindex') ?? '';
      }
      element.setAttribute('tabindex', '-1');
      element.setAttribute('aria-disabled', 'true');
      return;
    }

    if (Object.prototype.hasOwnProperty.call(element.dataset, 'wasTabIndex')) {
      if (element.dataset.wasTabIndex === '') {
        element.removeAttribute('tabindex');
      } else {
        element.setAttribute('tabindex', element.dataset.wasTabIndex);
      }
      delete element.dataset.wasTabIndex;
    }
    element.removeAttribute('aria-disabled');
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
  const projectName = getDeployProjectName(form);
  setOperationLock(true);
  setDeployButtonsDisabled(true);
  showDeployProgress(projectName, [{ project_name: projectName, state: 'running' }]);

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
      showDeployFeedback(feedback, 'success', `${escapeHtml(projectName)} 배포가 완료되었습니다.`);
      showDeployFeedback(cardStatus, 'success', `상태: ${escapeHtml(projectName)} 배포 성공`);
    } else {
      showDeployFeedback(feedback, 'failed', `${escapeHtml(projectName)} ${escapeHtml(result.message || '배포에 실패했습니다. 리포트를 확인해주세요.')}${reportLink}`);
      showDeployFeedback(cardStatus, 'failed', `상태: ${escapeHtml(projectName)} 배포 실패`);
    }

    await refreshDashboardContent();
  } catch (error) {
    showDeployFeedback(feedback, 'failed', `${escapeHtml(projectName)} 배포에 실패했습니다. 리포트를 확인해주세요.`);
    showDeployFeedback(cardStatus, 'failed', `상태: ${escapeHtml(projectName)} 배포 실패`);
  } finally {
    hideDeployProgress();
    setOperationLock(false);
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
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(window.location.href, {
      credentials: 'same-origin',
      signal: controller.signal,
    });
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
      bindProjectInteractions(nextGlobalTools);
    }
  } catch (error) {
    // 새로고침 실패 시 현재 화면을 유지하고 다음 사용자 동작에 맡깁니다.
  } finally {
    window.clearTimeout(timeoutId);
  }
}

bindProjectInteractions();
const initialDeployProgressOverlay = normalizeDeployProgressOverlay(document.querySelector('[data-deploy-progress-overlay]'));
if (initialDeployProgressOverlay) {
  document.body.dataset.deployProgress = 'running';
  document.body.classList.add('is-deploying');
  setOperationLock(true);
  window.setInterval(refreshDeployProgressStatus, 5000);
  refreshDeployProgressStatus();
}

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

    layer.appendChild(particle);
  }

  document.body.appendChild(layer);
})();
