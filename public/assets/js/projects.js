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

(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const month = new Date().getMonth() + 1;
  const season = month >= 3 && month <= 5 ? 'spring'
    : month >= 6 && month <= 8 ? 'summer'
      : month >= 9 && month <= 11 ? 'autumn'
        : 'winter';

  document.body.dataset.season = season;

  const layer = document.createElement('div');
  layer.className = 'seasonal-layer';
  layer.setAttribute('aria-hidden', 'true');

  const symbols = {
    spring: ['🌸', '🌸', '✨'],
    summer: ['🛟', '🛟', '✨'],
    autumn: ['🍂', '🍁', '✨'],
    winter: ['❄️', '❅', '✨'],
  };

  const count = window.innerWidth < 640 ? 14 : 26;
  for (let index = 0; index < count; index += 1) {
    const particle = document.createElement('span');
    particle.textContent = symbols[season][index % symbols[season].length];
    particle.style.setProperty('--x', `${Math.random() * 100}vw`);
    particle.style.setProperty('--delay', `${Math.random() * -18}s`);
    particle.style.setProperty('--duration', `${12 + Math.random() * 10}s`);
    particle.style.setProperty('--size', `${0.72 + Math.random() * 0.95}rem`);
    particle.style.setProperty('--drift', `${-40 + Math.random() * 80}px`);
    particle.style.setProperty('--spin', `${180 + Math.random() * 420}deg`);
    layer.appendChild(particle);
  }

  document.body.appendChild(layer);
})();
