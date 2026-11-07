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
  // Delegado en el documento para que también valga en resultados de la búsqueda.
  document.addEventListener('click', (ev) => {
    const row = ev.target.closest('tr[data-href]');
    if (!row || ev.target.closest('a, button, form')) return;
    window.location.href = row.dataset.href;
  });

  // Avisos: los de éxito se cierran solos; los de error, al pulsar la X.
  document.querySelectorAll('.toast').forEach((el) => bootstrap.Toast.getOrCreateInstance(el).show());

  // Acciones destructivas: pregunta con el detalle de lo que va a pasar.
  // El botón seguro (Cancelar) queda enfocado: Enter no confirma por accidente.
  const modalEl = document.getElementById('confirmar');
  if (modalEl) {
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const ok = modalEl.querySelector('[data-confirm-ok]');
    let pending = null;
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        pending = form;
        modalEl.querySelector('[data-confirm-title]').textContent = form.dataset.confirmTitle || '¿Confirmas esta acción?';
        modalEl.querySelector('[data-confirm-text]').textContent = form.dataset.confirm;
        ok.textContent = form.dataset.confirmOk || 'Confirmar';
        ok.disabled = false;
        modal.show();
      });
    });
    modalEl.addEventListener('shown.bs.modal', () => modalEl.querySelector('[data-confirm-cancel]').focus());
    ok.addEventListener('click', () => {
      ok.disabled = true;
      pending.submit();
    });
  }

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

// Buscador de facturas: filtra mientras se escribe, sin recargar la página
// (así no se pierde el foco). Sin JavaScript sigue funcionando con Enter.
document.querySelectorAll('[data-live-search]').forEach((form) => {
  const input = form.querySelector('input[type="search"]');
  let timer = 0;
  let pending = null;
  const run = async () => {
    const params = new URLSearchParams(new FormData(form));
    if (!params.get('q').trim()) params.delete('q');
    const url = form.action + (params.size ? '?' + params : '');
    pending?.abort();
    pending = new AbortController();
    try {
      const res = await fetch(url, { signal: pending.signal, headers: { Accept: 'text/html' } });
      if (!res.ok) throw new Error(res.status);
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      document.querySelectorAll('[data-live]').forEach((el) => {
        const fresh = doc.querySelector('[data-live="' + el.dataset.live + '"]');
        if (fresh) el.replaceWith(fresh);
      });
      history.replaceState(null, '', url);
    } catch (e) {
      if (e.name !== 'AbortError') form.submit();
    }
  };
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, 250);
  });
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    clearTimeout(timer);
    run();
  });
});

// Selector de negocio: se cierra al tocar fuera o con Esc.
document.querySelectorAll('[data-switcher]').forEach((box) => {
  document.addEventListener('click', (ev) => {
    if (box.open && !box.contains(ev.target)) box.open = false;
  });
  box.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape' || !box.open) return;
    ev.stopPropagation();
    box.open = false;
    box.querySelector('summary').focus();
  });
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

// Rol administrador: siempre puede anular. Si vuelve a miembro, recupera lo que tenía marcado.
document.querySelectorAll('[data-role]').forEach((sel) => {
  const box = sel.form.querySelector('[data-void]');
  const help = sel.form.querySelector('[data-void-help]');
  if (!box) return;
  const memberHelp = 'En la app y en la web, indicando siempre el motivo. Anular una factura anula también todos sus pagos.';
  let granted = sel.value !== 'admin' && box.checked;
  sel.addEventListener('change', () => {
    const admin = sel.value === 'admin';
    if (admin) granted = box.checked;
    box.disabled = admin;
    box.checked = admin || granted;
    if (help) help.textContent = admin ? 'Los administradores siempre pueden anular.' : memberHelp;
  });
});

// Rol administrador: ve todos los informes. Si vuelve a miembro, recupera lo que tenía marcado.
document.querySelectorAll('[data-role]').forEach((sel) => {
  const boxes = Array.from(sel.form.querySelectorAll('[data-report]'));
  const help = sel.form.querySelector('[data-reports-help]');
  if (!boxes.length) return;
  const memberHelp = 'En la app y en la web. Sin ninguno marcado, no verá la sección Informes.';
  const marked = () => boxes.filter((b) => b.checked).map((b) => b.value);
  let granted = sel.value === 'admin' ? [] : marked();
  sel.addEventListener('change', () => {
    const admin = sel.value === 'admin';
    if (admin) granted = marked();
    boxes.forEach((b) => {
      b.disabled = admin;
      b.checked = admin || granted.includes(b.value);
    });
    if (help) help.textContent = admin ? 'Los administradores siempre ven todos los informes.' : memberHelp;
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
