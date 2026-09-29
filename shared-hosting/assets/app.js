const basePath = (() => {
  const script = document.currentScript || [...document.scripts].find((item) => item.src.includes('/assets/app.js'));
  if (!script) return '';
  return new URL(script.src).pathname.replace(/\/assets\/app\.js$/, '');
})();
const appUrl = (path) => `${basePath}/${String(path).replace(/^\//, '')}`;

const themeToggle = document.querySelector('[data-theme-toggle]');
if (themeToggle) {
  const refreshThemeButton = () => {
    const light = document.documentElement.dataset.theme === 'light';
    themeToggle.setAttribute('aria-label', light ? 'Activar modo oscuro' : 'Activar modo claro');
    themeToggle.setAttribute('aria-pressed', String(light));
  };
  themeToggle.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('ms-theme', next); } catch (error) { /* El tema sigue funcionando en esta visita. */ }
    refreshThemeButton();
  });
  refreshThemeButton();
}

const serviceRequest = document.querySelector('[data-service-request]');
if (serviceRequest) {
  const fields = serviceRequest.querySelector('[data-personal-horoscope-fields]');
  const radios = [...serviceRequest.querySelectorAll('[name="service_type"]')];
  const refreshService = () => {
    const personalized = radios.some((radio) => radio.checked && radio.value === 'personal_horoscope');
    fields?.classList.toggle('is-visible', personalized);
    fields?.querySelectorAll('select,input').forEach((field) => { field.required = personalized; });
  };
  radios.forEach((radio) => radio.addEventListener('change', refreshService));
  refreshService();
}

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
  const desired = {country: locationPicker.dataset.country || 'CO', admin1: locationPicker.dataset.admin1 || '', city: locationPicker.dataset.city || ''};
  let places = new Map(); let manual = false;
  const setOptions = (select, items, placeholder, valueOf, labelOf) => {
    select.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach((item) => { const option=document.createElement('option');option.value=valueOf(item);option.textContent=labelOf(item);select.append(option); });
    select.disabled = items.length === 0;
  };
  const fetchItems = async (query) => { const response=await fetch(appUrl(`api/locations.php?${query}`));const payload=await response.json();if(!response.ok)throw new Error(payload.error||'No fue posible consultar las ubicaciones.');return payload.items; };
  const setManual = (enabled) => {
    manual=enabled;locationPicker.classList.toggle('manual-mode',enabled);
    [country,admin1,city].forEach((field)=>{field.disabled=enabled;field.required=!enabled;});
    [birthplace,latitude,longitude,timezone].forEach((field)=>field.readOnly=!enabled);
    toggle.textContent=enabled?'Usar buscador de lugares':'Ingresar manualmente';
    status.textContent=enabled?'Modo manual activo. Verifica coordenadas y zona horaria.':'Selecciona pais, departamento y municipio.';
  };
  const loadPlaces = async (selected='') => { places=new Map();setOptions(city,[],'Cargando municipios…',()=>'',()=>'');const items=await fetchItems(`action=places&country=${encodeURIComponent(country.value)}&admin1=${encodeURIComponent(admin1.value)}`);items.forEach((item)=>places.set(String(item.id),item));setOptions(city,items,'Selecciona un municipio',(item)=>item.id,(item)=>item.name);if(selected&&places.has(String(selected))){city.value=String(selected);city.dispatchEvent(new Event('change'));} };
  const loadAdmin1 = async (selected='',selectedCity='') => { setOptions(admin1,[],'Cargando departamentos…',()=>'',()=>'');setOptions(city,[],'Selecciona un departamento',()=>'',()=>'');const items=await fetchItems(`action=admin1&country=${encodeURIComponent(country.value)}`);setOptions(admin1,items,'Selecciona un departamento',(item)=>item.code,(item)=>item.name);if(selected&&items.some((item)=>item.code===selected)){admin1.value=selected;await loadPlaces(selectedCity);} };
  country.addEventListener('change',async()=>{birthplace.value=latitude.value=longitude.value=timezone.value='';if(country.value){try{await loadAdmin1();}catch(error){status.textContent=error.message;}}});
  admin1.addEventListener('change',async()=>{birthplace.value=latitude.value=longitude.value=timezone.value='';if(admin1.value){try{await loadPlaces();}catch(error){status.textContent=error.message;}}});
  city.addEventListener('change',()=>{const place=places.get(city.value);if(!place)return;const countryName=country.options[country.selectedIndex].textContent.replace(/\s+\(\d+\)$/,'');const adminName=admin1.options[admin1.selectedIndex].textContent.replace(/\s+\(\d+\)$/,'');birthplace.value=`${place.name}, ${adminName}, ${countryName}`;latitude.value=Number(place.latitude).toFixed(6);longitude.value=Number(place.longitude).toFixed(6);timezone.value=place.timezone;status.textContent=`Coordenadas cargadas para ${place.name}.`;});
  toggle.addEventListener('click',()=>setManual(!manual));
  (async()=>{try{const items=await fetchItems('action=countries');setOptions(country,items,'Selecciona un pais',(item)=>item.code,(item)=>`${item.name} (${item.city_count})`);if(items.some((item)=>item.code===desired.country))country.value=desired.country;await loadAdmin1(desired.admin1,desired.city);setManual(false);}catch(error){setManual(true);status.textContent=`${error.message} Puedes ingresar el lugar manualmente.`;}})();
}

const aurita = document.querySelector('[data-aurita]');
if (aurita) {
  const form=aurita.querySelector('.aurita-form');const history=aurita.querySelector('.chat-history');const textarea=form.querySelector('textarea');const button=form.querySelector('button');const feedback=form.querySelector('.form-feedback');
  const addMessage=(role,label,content,isHtml=false)=>{const item=document.createElement('div');item.className=`chat-message ${role}`;const strong=document.createElement('strong');strong.textContent=label;const body=document.createElement('div');body.className='rich-text';if(isHtml)body.innerHTML=content;else body.textContent=content;item.append(strong,body);history.append(item);history.scrollTop=history.scrollHeight;};
  form.addEventListener('submit',async(event)=>{event.preventDefault();const message=textarea.value.trim();if(!message)return;addMessage('user','Tu',message);textarea.value='';button.disabled=true;feedback.textContent='Aurita esta leyendo tu consulta…';try{const response=await fetch(appUrl('api/aurita.php'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({public_id:aurita.dataset.publicId,token:aurita.dataset.token,message})});const payload=await response.json();if(!response.ok)throw new Error(payload.error||'Aurita no pudo responder.');addMessage('assistant','Aurita',payload.answer_html||payload.answer,Boolean(payload.answer_html));feedback.textContent='';}catch(error){feedback.className='form-feedback error-text';feedback.textContent=error.message;}finally{button.disabled=false;}});
}

document.querySelectorAll('[data-chart-expand]').forEach((button) => {
  button.addEventListener('click', () => {
    const container = button.closest('.chart-panel, .chart-summary');
    const chart = container?.querySelector('.chart-svg');
    if (!chart) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'chart-zoom-dialog';
    const toolbar = document.createElement('div');
    toolbar.className = 'chart-zoom-toolbar';
    const title = document.createElement('strong');
    title.textContent = 'Carta natal ampliada';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'secondary-button';
    close.textContent = 'Cerrar ×';
    const content = document.createElement('div');
    content.className = 'chart-zoom-content';
    content.append(chart.querySelector('svg')?.cloneNode(true) || chart.cloneNode(true));
    toolbar.append(title, close);
    dialog.append(toolbar, content);
    document.body.append(dialog);
    const dismiss = () => { dialog.close(); dialog.remove(); };
    close.addEventListener('click', dismiss);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dismiss(); });
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); dismiss(); });
    dialog.showModal();
  });
});

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
