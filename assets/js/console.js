(() => {
  'use strict';
  const ui = window.harborcacheUI || {};
  document.querySelectorAll('#harborcache, #harborcache_status').forEach(root => {
    root.querySelectorAll('.ch-chart').forEach(chart => {
      chart.classList.add('ch-js');
      const originals = new WeakMap();
      let previousWidth;
      const resize = () => {
        const width = Math.max(180, chart.clientWidth);
        if (width === previousWidth) return;
        previousWidth = width;
        const x = value => value < 100 ? 72 - (100 - value) : 72 + (value - 100) * (width - 96) / 486;
        chart.querySelectorAll('svg').forEach(svg => {
          svg.setAttribute('viewBox', '0 0 ' + width + ' 250');
          svg.querySelectorAll('[x],[x1],[cx],[points]').forEach(element => {
            if (!originals.has(element)) originals.set(element, Object.fromEntries(['x', 'x1', 'x2', 'cx', 'points'].filter(key => element.hasAttribute(key)).map(key => [key, element.getAttribute(key)])));
            Object.entries(originals.get(element)).forEach(([key, value]) => {
              element.setAttribute(key, key === 'points' ? value.trim().split(/\s+/).map(point => { const [px, py] = point.split(','); return x(Number(px)) + ',' + py; }).join(' ') : x(Number(value)));
            });
          });
        });
      };
      if ('ResizeObserver' in window) new ResizeObserver(resize).observe(chart);
      else window.addEventListener('resize', resize);
      resize();
      chart.querySelectorAll('[data-ch-metric]').forEach(button => {
        button.addEventListener('click', () => {
          chart.querySelectorAll('[data-ch-metric]').forEach(control => control.setAttribute('aria-pressed', String(control === button)));
          chart.querySelectorAll('[data-ch-panel]').forEach(panel => { panel.hidden = panel.dataset.chPanel !== button.dataset.chMetric; });
        });
      });
    });
    root.querySelectorAll('[data-ch-confirm]').forEach(button => {
      button.addEventListener('click', event => {
        const text = button.dataset.chConfirm || ui.confirm;
        if (!window.confirm(text)) event.preventDefault();
      });
    });

    const testForm = root.querySelector('#ch-test-form');
    if (testForm && window.fetch && ui.ajax) testForm.addEventListener('submit', async event => {
      event.preventDefault();
      const button = testForm.querySelector('button[type="submit"]');
      const status = root.querySelector('#ch-test-status');
      const target = root.querySelector('#ch-probe-result');
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (status) status.textContent = ui.testing || '';
      try {
        const body = new URLSearchParams({ action: 'harborcache_probe', _wpnonce: ui.probeNonce });
        const response = await fetch(ui.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
        const data = await response.json();
        if (target && data.html) target.innerHTML = data.html;
        if (status) status.textContent = '';
        if (!data.html) throw new Error('empty');
      } catch (error) {
        if (status) status.textContent = ui.probeFailed || '';
      } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
      }
    });

    const copy = root.querySelector('#ch-copy');
    if (copy) copy.addEventListener('click', async () => {
      const report = root.querySelector('#ch-diagnostics');
      const feedback = root.querySelector('#ch-feedback');
      if (!report || !feedback) return;
      try {
        await navigator.clipboard.writeText(report.textContent);
        feedback.textContent = ui.copied;
      } catch (error) {
        const range = document.createRange();
        range.selectNodeContents(report);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        feedback.textContent = ui.selected;
      }
      window.setTimeout(() => { feedback.textContent = ''; }, 4000);
    });
  });
})();
