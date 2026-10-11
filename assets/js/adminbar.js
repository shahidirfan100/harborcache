(() => {
  'use strict';
  const ui = window.harborcacheUI || {};
  const flush = document.querySelector('#wp-admin-bar-harborcache-flush > a');
  if (flush) flush.addEventListener('click', async event => {
    event.preventDefault();
    if (flush.dataset.busy || !window.confirm(ui.confirm)) return;
    const form = document.createElement('form');
    form.method = 'POST'; form.action = ui.endpoint;
    Object.entries({action: 'harborcache_action', operation: 'flush', _wpnonce: ui.nonce}).forEach(([name, value]) => {
      const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; form.append(field);
    });
    document.body.append(form); form.submit();
  });
})();
