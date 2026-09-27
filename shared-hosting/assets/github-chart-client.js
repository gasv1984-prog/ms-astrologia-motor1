(() => {
  const control = document.querySelector('[data-github-chart]');
  if (!control) return;

  const button = control.querySelector('[data-chart-button]');
  const status = control.querySelector('[data-chart-status]');
  const frame = control.querySelector('[data-chart-frame]');
  const form = control.querySelector('[data-chart-form]');
  const subjectNode = control.querySelector('[data-chart-subject]');
  const svgField = form.querySelector('[name="chart_svg"]');
  const dataField = form.querySelector('[name="chart_data"]');
  const engineUrl = new URL(control.dataset.engineUrl);
  const expectedOrigin = engineUrl.origin;
  engineUrl.searchParams.set('parent_origin', window.location.origin);
  frame.src = engineUrl.href;

  let requestId = '';
  let ready = false;
  let timeoutId = window.setTimeout(() => {
    if (!ready) status.textContent = 'GitHub todavía no responde. Verifica que GitHub Pages esté publicado.';
  }, 20000);

  function setStatus(message, isError = false) {
    status.textContent = message;
    status.classList.toggle('error-text', isError);
  }

  window.addEventListener('message', (event) => {
    if (event.origin !== expectedOrigin || event.source !== frame.contentWindow || !event.data) return;
    const message = event.data;
    if (message.type === 'msastrologia:ready') {
      ready = true;
      window.clearTimeout(timeoutId);
      button.disabled = false;
      setStatus(`Motor preparado: Swiss Ephemeris ${message.engine || ''}.`);
      return;
    }
    if (String(message.requestId || '') !== requestId) return;
    if (message.type === 'msastrologia:error') {
      button.disabled = false;
      setStatus(message.error || 'No fue posible calcular la carta.', true);
      return;
    }
    if (message.type === 'msastrologia:result') {
      if (typeof message.svg !== 'string' || !message.svg.includes('<svg') || !message.data) {
        button.disabled = false;
        setStatus('El motor devolvió una respuesta incompleta.', true);
        return;
      }
      svgField.value = message.svg;
      dataField.value = JSON.stringify(message.data);
      setStatus('Carta calculada. Guardando en Hostinger…');
      form.submit();
    }
  });

  button.addEventListener('click', () => {
    if (!ready) {
      setStatus('Espera a que el motor de GitHub termine de cargar.', true);
      return;
    }
    try {
      const subject = JSON.parse(subjectNode.textContent);
      requestId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      button.disabled = true;
      setStatus('Calculando posiciones, casas y aspectos en tu navegador…');
      frame.contentWindow.postMessage({ type: 'msastrologia:calculate', requestId, subject }, expectedOrigin);
      timeoutId = window.setTimeout(() => {
        if (!button.disabled) return;
        button.disabled = false;
        setStatus('El cálculo tardó demasiado. Intenta nuevamente.', true);
      }, 45000);
    } catch (error) {
      button.disabled = false;
      setStatus(error instanceof Error ? error.message : 'No fue posible iniciar el cálculo.', true);
    }
  });
})();
