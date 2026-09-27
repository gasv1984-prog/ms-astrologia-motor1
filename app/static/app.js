const locationPicker = document.querySelector('[data-location-picker]');
if (locationPicker) {
  const country = locationPicker.querySelector('.country-select');
  const admin1 = locationPicker.querySelector('.admin1-select');
  const city = locationPicker.querySelector('.city-select');
  const toggle = locationPicker.querySelector('.manual-toggle');
  const status = locationPicker.querySelector('.location-status');
  const form = locationPicker.closest('form');
  const birthplace = form.querySelector('[name="birthplace"]');
  const latitude = form.querySelector('[name="latitude"]');
  const longitude = form.querySelector('[name="longitude"]');
  const timezone = form.querySelector('[name="timezone"]');
  const desired = {
    country: locationPicker.dataset.country || 'CO',
    admin1: locationPicker.dataset.admin1 || '',
    city: locationPicker.dataset.city || '',
  };
  let places = new Map();
  let manual = locationPicker.dataset.geoReady !== 'true';

  const setOptions = (select, items, placeholder, valueOf, labelOf) => {
    select.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = placeholder;
    select.append(empty);
    items.forEach((item) => {
      const option = document.createElement('option');
      option.value = valueOf(item);
      option.textContent = labelOf(item);
      select.append(option);
    });
    select.disabled = items.length === 0;
  };

  const fetchItems = async (url) => {
    const response = await fetch(url);
    if (!response.ok) throw new Error('No fue posible consultar la base de ubicaciones.');
    return (await response.json()).items;
  };

  const setManual = (enabled) => {
    manual = enabled;
    locationPicker.classList.toggle('manual-mode', enabled);
    [country, admin1, city].forEach((field) => {
      field.disabled = enabled;
      field.required = !enabled;
    });
    [birthplace, latitude, longitude, timezone].forEach((field) => field.readOnly = !enabled);
    city.value = enabled ? '' : city.value;
    toggle.textContent = enabled ? 'Usar buscador de lugares' : 'Ingresar manualmente';
    status.textContent = enabled
      ? 'Modo manual activo. Verifica cuidadosamente coordenadas y zona horaria.'
      : 'Selecciona país, departamento y municipio.';
  };

  const loadPlaces = async (selected = '') => {
    places = new Map();
    setOptions(city, [], 'Cargando municipios…', () => '', () => '');
    const items = await fetchItems(`/api/ubicaciones/municipios?country=${encodeURIComponent(country.value)}&admin1=${encodeURIComponent(admin1.value)}`);
    items.forEach((item) => places.set(String(item.id), item));
    setOptions(city, items, 'Selecciona un municipio', (item) => item.id, (item) => item.name);
    if (selected && places.has(String(selected))) {
      city.value = String(selected);
      city.dispatchEvent(new Event('change'));
    }
  };

  const loadAdmin1 = async (selected = '', selectedCity = '') => {
    setOptions(admin1, [], 'Cargando departamentos…', () => '', () => '');
    setOptions(city, [], 'Selecciona un departamento', () => '', () => '');
    const items = await fetchItems(`/api/ubicaciones/departamentos?country=${encodeURIComponent(country.value)}`);
    setOptions(admin1, items, 'Selecciona un departamento', (item) => item.code, (item) => item.name);
    if (selected && items.some((item) => item.code === selected)) {
      admin1.value = selected;
      await loadPlaces(selectedCity);
    }
  };

  country.addEventListener('change', async () => {
    birthplace.value = latitude.value = longitude.value = timezone.value = '';
    if (!country.value) return;
    try { await loadAdmin1(); } catch (error) { status.textContent = error.message; }
  });
  admin1.addEventListener('change', async () => {
    birthplace.value = latitude.value = longitude.value = timezone.value = '';
    if (!admin1.value) return;
    try { await loadPlaces(); } catch (error) { status.textContent = error.message; }
  });
  city.addEventListener('change', () => {
    const place = places.get(city.value);
    if (!place) return;
    const countryName = country.options[country.selectedIndex].textContent.replace(/\s+\(\d+\)$/, '');
    const adminName = admin1.options[admin1.selectedIndex].textContent.replace(/\s+\(\d+\)$/, '');
    birthplace.value = `${place.name}, ${adminName}, ${countryName}`;
    latitude.value = Number(place.latitude).toFixed(6);
    longitude.value = Number(place.longitude).toFixed(6);
    timezone.value = place.timezone;
    status.textContent = `Coordenadas y zona horaria cargadas para ${place.name}.`;
  });
  toggle.addEventListener('click', () => setManual(!manual));

  (async () => {
    if (manual) {
      setManual(true);
      country.innerHTML = '<option value="">Base local no instalada</option>';
      return;
    }
    try {
      const items = await fetchItems('/api/ubicaciones/paises');
      setOptions(country, items, 'Selecciona un país', (item) => item.code, (item) => `${item.name} (${item.city_count})`);
      if (items.some((item) => item.code === desired.country)) country.value = desired.country;
      await loadAdmin1(desired.admin1, desired.city);
      setManual(false);
    } catch (error) {
      setManual(true);
      status.textContent = `${error.message} Puedes ingresar el lugar manualmente.`;
    }
  })();
}

document.querySelectorAll('.provider-card').forEach((card) => {
  const button = card.querySelector('.verify-models');
  if (!button) return;
  button.addEventListener('click', async () => {
    const provider = card.dataset.provider;
    const keyInput = card.querySelector('[name="api_key"]');
    const modelSelect = card.querySelector('[name="model"]');
    const saveButton = card.querySelector('[type="submit"]');
    const feedback = card.querySelector('.form-feedback');
    const csrf = document.querySelector('[data-csrf]')?.dataset.csrf;

    button.disabled = true;
    saveButton.disabled = true;
    feedback.className = 'form-feedback loading';
    feedback.textContent = 'Consultando los modelos disponibles…';
    try {
      const response = await fetch(`/admin/api/ia/${provider}/modelos`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({api_key: keyInput.value}),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No fue posible comprobar la clave.');
      if (!payload.models.length) throw new Error('La clave es válida, pero no tiene modelos de texto disponibles.');
      const previous = modelSelect.value;
      modelSelect.innerHTML = '';
      payload.models.forEach(({id, name}) => {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = name === id ? id : `${name} · ${id}`;
        option.selected = id === previous;
        modelSelect.append(option);
      });
      feedback.className = 'form-feedback success-text';
      feedback.textContent = `${payload.models.length} modelos disponibles. Selecciona uno y guarda.`;
      saveButton.disabled = false;
    } catch (error) {
      feedback.className = 'form-feedback error-text';
      feedback.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
});

const aurita = document.querySelector('[data-aurita]');
if (aurita) {
  const form = aurita.querySelector('.aurita-form');
  if (form) {
    const history = aurita.querySelector('.chat-history');
    const textarea = form.querySelector('textarea');
    const button = form.querySelector('button');
    const feedback = form.querySelector('.form-feedback');
    const publicId = aurita.dataset.publicId;
    const token = aurita.dataset.token;

    const addMessage = (role, label, content, isHtml = false) => {
      const item = document.createElement('div');
      item.className = `chat-message ${role}`;
      const strong = document.createElement('strong');
      strong.textContent = label;
      const body = document.createElement('div');
      body.className = 'rich-text';
      if (isHtml) body.innerHTML = content;
      else body.textContent = content;
      item.append(strong, body);
      history.append(item);
      history.scrollTop = history.scrollHeight;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = textarea.value.trim();
      if (!message) return;
      addMessage('user', 'Tú', message);
      textarea.value = '';
      button.disabled = true;
      feedback.className = 'form-feedback loading';
      feedback.textContent = 'Aurita está leyendo tu carta…';
      try {
        const response = await fetch(`/resultado/${publicId}/aurita`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-Result-Token': token},
          body: JSON.stringify({message}),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'Aurita no pudo responder en este momento.');
        addMessage('assistant', 'Aurita', payload.answer_html || payload.answer, Boolean(payload.answer_html));
        feedback.textContent = '';
      } catch (error) {
        feedback.className = 'form-feedback error-text';
        feedback.textContent = error.message;
      } finally {
        button.disabled = false;
      }
    });
  }
}

(() => {
  const revealItems = [...document.querySelectorAll('.reveal')];
  if (!revealItems.length) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.documentElement.classList.add('motion-ready');
  if (reducedMotion || !('IntersectionObserver' in window)) {
    revealItems.forEach((item) => item.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, {threshold: 0.14, rootMargin: '0px 0px -7%'});
  revealItems.forEach((item) => observer.observe(item));

  const scene = document.querySelector('[data-cinematic]');
  const parallax = scene?.querySelector('[data-parallax]');
  scene?.addEventListener('pointermove', (event) => {
    const rect = scene.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width;
    const y = (event.clientY - rect.top) / rect.height;
    scene.style.setProperty('--scene-x', `${(x * 100).toFixed(1)}%`);
    scene.style.setProperty('--scene-y', `${(y * 100).toFixed(1)}%`);
    if (parallax) parallax.style.transform = `translate3d(${(x - .5) * 20}px, ${(y - .5) * 15}px, 24px)`;
  }, {passive: true});
  scene?.addEventListener('pointerleave', () => {
    if (parallax) parallax.style.transform = '';
  });

  document.querySelectorAll('[data-tilt]').forEach((card) => {
    card.addEventListener('pointermove', (event) => {
      const rect = card.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      card.style.setProperty('--mx', `${(x * 100).toFixed(1)}%`);
      card.style.setProperty('--my', `${(y * 100).toFixed(1)}%`);
      card.style.transform = `perspective(900px) rotateX(${(.5 - y) * 3.5}deg) rotateY(${(x - .5) * 4.5}deg) translateY(-3px)`;
    }, {passive: true});
    card.addEventListener('pointerleave', () => { card.style.transform = ''; });
  });
})();
