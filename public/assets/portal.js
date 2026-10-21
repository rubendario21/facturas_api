// Portal: tema, filas clicables, confirmaciones y generador de contraseñas.
(function () {
  const root = document.documentElement;

  // Tema: Sistema → Claro → Oscuro (se recuerda en este navegador).
  const order = ['system', 'light', 'dark'];
  const names = { system: 'Tema: sistema', light: 'Tema: claro', dark: 'Tema: oscuro' };
  const current = () => root.dataset.theme || 'system';
  const label = document.querySelector('[data-theme-label]');
  const paint = () => { if (label) label.textContent = names[current()]; };
  paint();
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    const next = order[(order.indexOf(current()) + 1) % order.length];
    if (next === 'system') delete root.dataset.theme; else root.dataset.theme = next;
    try { next === 'system' ? localStorage.removeItem('theme') : localStorage.setItem('theme', next); } catch (e) {}
    paint();
  });

  // La fila entera abre el detalle (el enlace interior sigue funcionando con teclado).
  document.querySelectorAll('tr[data-href]').forEach((row) => {
    row.addEventListener('click', (ev) => {
      if (ev.target.closest('a, button, form')) return;
      window.location.href = row.dataset.href;
    });
  });

  // Acciones destructivas: confirmación con el detalle de lo que se va a borrar.
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (ev) => {
      if (!window.confirm(form.dataset.confirm)) ev.preventDefault();
    });
  });

  // Contraseña aleatoria legible (sin caracteres que se confunden: 0/O, 1/l).
  document.querySelectorAll('[data-generate]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      const bytes = crypto.getRandomValues(new Uint32Array(10));
      const input = btn.closest('.input-row').querySelector('[data-password]');
      input.value = Array.from(bytes, (b) => chars[b % chars.length]).join('');
      input.focus();
      input.select();
    });
  });
})();

// Selector de negocio: cambia al elegir.
document.querySelectorAll('[data-autosubmit]').forEach((sel) => {
  sel.addEventListener('change', () => sel.form.submit());
});

// Rol administrador: siempre tiene acceso web.
document.querySelectorAll('[data-role]').forEach((sel) => {
  const web = sel.form.querySelector('[data-web]');
  const help = sel.form.querySelector('[data-web-help]');
  if (!web) return;
  sel.addEventListener('change', () => {
    const admin = sel.value === 'admin';
    web.disabled = admin;
    if (admin) web.checked = true;
    if (help) {
      help.textContent = admin
        ? 'Los administradores siempre pueden usar la web.'
        : 'Para quien usa iPhone o prefiere el navegador: podrá facturar y registrar pagos igual que en la app.';
    }
  });
});

// Formulario de factura: filas de conceptos y totales en vivo (como en la app).
document.querySelectorAll('[data-invoice-form]').forEach((form) => {
  const body = form.querySelector('[data-items]');
  const currency = form.dataset.currency || '';
  // Acepta "1.234,5", "1234,5" y "1234.5".
  const parse = (v) => {
    let s = String(v).trim();
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  };
  const money = (n) => currency + ' ' + n.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const renumber = () => {
    body.querySelectorAll('[data-item]').forEach((row, i) => {
      row.querySelectorAll('input').forEach((input) => {
        input.name = input.name.replace(/items\[\d+\]/, 'items[' + i + ']');
      });
    });
  };
  const recalc = () => {
    let subtotal = 0;
    const rows = body.querySelectorAll('[data-item]');
    rows.forEach((row) => {
      const line = parse(row.querySelector('[data-qty]').value) * parse(row.querySelector('[data-price]').value);
      subtotal += line;
      row.querySelector('[data-line]').textContent = money(line);
      row.querySelector('[data-remove]').hidden = rows.length === 1;
    });
    const tax = subtotal * parse(form.querySelector('[data-tax]').value) / 100;
    form.querySelector('[data-subtotal]').textContent = money(subtotal);
    form.querySelector('[data-taxamount]').textContent = money(tax);
    form.querySelector('[data-total]').textContent = money(subtotal + tax);
  };

  form.querySelector('[data-add]').addEventListener('click', () => {
    const row = body.querySelector('[data-item]').cloneNode(true);
    row.querySelectorAll('input').forEach((input) => { input.value = input.hasAttribute('data-qty') ? '1' : ''; });
    body.appendChild(row);
    renumber();
    recalc();
    row.querySelector('input').focus();
  });
  body.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-remove]');
    if (!btn || body.querySelectorAll('[data-item]').length === 1) return;
    btn.closest('[data-item]').remove();
    renumber();
    recalc();
  });
  form.addEventListener('input', recalc);
  recalc();
});
