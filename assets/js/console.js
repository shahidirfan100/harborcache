(() => {
  'use strict';
  const ui = window.harborcacheUI || {};
  document.querySelectorAll('.ch-chart').forEach(chart => {
    const originals = new WeakMap();
    let previousWidth;
    const resize = () => {
      const width = Math.max(180, chart.clientWidth);
      if (width === previousWidth) return;
      previousWidth = width;
      const x = value => value < 100 ? 72 - (100 - value) : 72 + (value - 100) * (width - 96) / 486;
      chart.querySelectorAll('svg').forEach(svg => {
        svg.setAttribute('viewBox', `0 0 ${width} 250`);
        svg.querySelectorAll('[x],[x1],[cx],[points]').forEach(element => {
          if (!originals.has(element)) originals.set(element, Object.fromEntries(['x', 'x1', 'x2', 'cx', 'points'].filter(key => element.hasAttribute(key)).map(key => [key, element.getAttribute(key)])));
          Object.entries(originals.get(element)).forEach(([key, value]) => {
            element.setAttribute(key, key === 'points' ? value.trim().split(/\s+/).map(point => { const [px, py] = point.split(','); return `${x(Number(px))},${py}`; }).join(' ') : x(Number(value)));
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
  document.querySelectorAll('[data-ch-confirm]').forEach(button => {
    button.addEventListener('click', event => {
      if (!window.confirm(ui.confirm)) event.preventDefault();
    });
  });
  const copy = document.getElementById('ch-copy');
  if (copy) copy.addEventListener('click', async () => {
    const report = document.getElementById('ch-diagnostics');
    const feedback = document.getElementById('ch-feedback');
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
  });
})();
