import { SwissEphemeris, Planet, LunarPoint, HouseSystem } from './vendor/swisseph-browser.js';

const ENGINE_VERSION = '1.0.0';
const statusNode = document.getElementById('status');
const allowedOrigins = new Set([
  'https://msastrologia.xyz',
  'https://www.msastrologia.xyz',
  'http://127.0.0.1:8000',
  'http://localhost:8000',
]);
const parentOrigin = new URLSearchParams(location.search).get('parent_origin');
if (parentOrigin && /^(https:\/\/([a-z0-9-]+\.)?msastrologia\.xyz|http:\/\/(127\.0\.0\.1|localhost)(:\d+)?)$/i.test(parentOrigin)) {
  allowedOrigins.add(parentOrigin);
}

const zodiac = [
  ['aries', 'Aries', '♈'], ['tauro', 'Tauro', '♉'], ['geminis', 'Géminis', '♊'],
  ['cancer', 'Cáncer', '♋'], ['leo', 'Leo', '♌'], ['virgo', 'Virgo', '♍'],
  ['libra', 'Libra', '♎'], ['escorpio', 'Escorpio', '♏'], ['sagitario', 'Sagitario', '♐'],
  ['capricornio', 'Capricornio', '♑'], ['acuario', 'Acuario', '♒'], ['piscis', 'Piscis', '♓'],
];

const bodies = [
  ['sun', 'Sol', '☉', Planet.Sun],
  ['moon', 'Luna', '☽', Planet.Moon],
  ['mercury', 'Mercurio', '☿', Planet.Mercury],
  ['venus', 'Venus', '♀', Planet.Venus],
  ['mars', 'Marte', '♂', Planet.Mars],
  ['jupiter', 'Júpiter', '♃', Planet.Jupiter],
  ['saturn', 'Saturno', '♄', Planet.Saturn],
  ['uranus', 'Urano', '♅', Planet.Uranus],
  ['neptune', 'Neptuno', '♆', Planet.Neptune],
  ['pluto', 'Plutón', '♇', Planet.Pluto],
  ['true_node', 'Nodo Norte', '☊', LunarPoint.TrueNode],
];

const aspectDefinitions = [
  ['conjunction', 'Conjunción', 0, 8, '#d8be77'],
  ['sextile', 'Sextil', 60, 5, '#79b8c9'],
  ['square', 'Cuadratura', 90, 7, '#cc7187'],
  ['trine', 'Trígono', 120, 7, '#7cc69c'],
  ['opposition', 'Oposición', 180, 8, '#cc7187'],
];

const swe = new SwissEphemeris();
let ready = false;

function normalize(value) {
  return ((Number(value) % 360) + 360) % 360;
}

function angularDistance(a, b) {
  const distance = Math.abs(normalize(a) - normalize(b));
  return Math.min(distance, 360 - distance);
}

function round(value, precision = 6) {
  const factor = 10 ** precision;
  return Math.round(Number(value) * factor) / factor;
}

function timeZoneOffsetMs(date, timeZone) {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    hourCycle: 'h23',
  });
  const parts = Object.fromEntries(formatter.formatToParts(date).filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));
  const representedAsUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second);
  return representedAsUtc - date.getTime();
}

function localBirthToUtc(subject) {
  const [year, month, day] = String(subject.birth_date).split('-').map(Number);
  const [hour, minute, second = 0] = String(subject.birth_time).split(':').map(Number);
  if (![year, month, day, hour, minute, second].every(Number.isFinite)) throw new Error('Fecha u hora de nacimiento no válida.');
  const localAsUtc = Date.UTC(year, month - 1, day, hour, minute, second);
  let candidate = new Date(localAsUtc);
  let offset = timeZoneOffsetMs(candidate, subject.timezone);
  candidate = new Date(localAsUtc - offset);
  const correctedOffset = timeZoneOffsetMs(candidate, subject.timezone);
  if (correctedOffset !== offset) candidate = new Date(localAsUtc - correctedOffset);
  return candidate;
}

function zodiacPosition(longitude) {
  const absolute = normalize(longitude);
  const index = Math.floor(absolute / 30);
  const within = absolute - index * 30;
  const degree = Math.floor(within);
  const minuteFloat = (within - degree) * 60;
  const minute = Math.floor(minuteFloat);
  const second = Math.round((minuteFloat - minute) * 60);
  const [key, name, symbol] = zodiac[index];
  return { key, name, symbol, index, degree, minute, second };
}

function houseForLongitude(longitude, cusps) {
  for (let house = 1; house <= 12; house += 1) {
    const start = normalize(cusps[house]);
    const end = normalize(cusps[house === 12 ? 1 : house + 1]);
    const span = normalize(end - start);
    const position = normalize(longitude - start);
    if (position < span || (house === 12 && position === span)) return house;
  }
  return null;
}

function calculateAspects(planets) {
  const aspects = [];
  for (let first = 0; first < planets.length; first += 1) {
    for (let second = first + 1; second < planets.length; second += 1) {
      const distance = angularDistance(planets[first].longitude, planets[second].longitude);
      for (const [key, name, angle, orbLimit, color] of aspectDefinitions) {
        const orb = Math.abs(distance - angle);
        if (orb <= orbLimit) {
          aspects.push({ key, name, angle, orb: round(orb, 3), color, first: planets[first].key, second: planets[second].key });
          break;
        }
      }
    }
  }
  return aspects;
}

function polar(longitude, ascendant, radius, center = 360) {
  const angle = (180 - normalize(longitude - ascendant)) * Math.PI / 180;
  return [center + Math.cos(angle) * radius, center - Math.sin(angle) * radius];
}

function escapeXml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[character]);
}

function renderChartSvg(subject, planets, houses, aspects) {
  const center = 360;
  const ascendant = houses.ascendant;
  const planetMap = new Map(planets.map((planet) => [planet.key, planet]));
  const parts = [
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 720" role="img" aria-labelledby="chart-title chart-desc">',
    `<title id="chart-title">Carta natal de ${escapeXml(subject.name)}</title>`,
    '<desc id="chart-desc">Carta tropical calculada con Swiss Ephemeris WebAssembly y casas Placidus.</desc>',
    '<defs><radialGradient id="bg" cx="50%" cy="45%"><stop offset="0" stop-color="#242039"/><stop offset="1" stop-color="#0d0c18"/></radialGradient><filter id="glow"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>',
    '<circle cx="360" cy="360" r="350" fill="url(#bg)" stroke="#d8be77" stroke-width="2"/>',
    '<circle cx="360" cy="360" r="300" fill="none" stroke="#514867"/>',
    '<circle cx="360" cy="360" r="220" fill="none" stroke="#514867"/>',
    '<circle cx="360" cy="360" r="150" fill="#100f1c" stroke="#3d374d"/>',
  ];

  for (let index = 0; index < 12; index += 1) {
    const longitude = index * 30;
    const [outerX, outerY] = polar(longitude, ascendant, 350, center);
    const [innerX, innerY] = polar(longitude, ascendant, 300, center);
    const [labelX, labelY] = polar(longitude + 15, ascendant, 325, center);
    parts.push(`<line x1="${round(innerX, 2)}" y1="${round(innerY, 2)}" x2="${round(outerX, 2)}" y2="${round(outerY, 2)}" stroke="#726581"/>`);
    parts.push(`<text x="${round(labelX, 2)}" y="${round(labelY + 9, 2)}" fill="#d8be77" font-size="27" text-anchor="middle">${zodiac[index][2]}</text>`);
  }

  for (let house = 1; house <= 12; house += 1) {
    const [outerX, outerY] = polar(houses.cusps[house], ascendant, 300, center);
    const [innerX, innerY] = polar(houses.cusps[house], ascendant, 150, center);
    const nextCusp = houses.cusps[house === 12 ? 1 : house + 1];
    const span = normalize(nextCusp - houses.cusps[house]);
    const [numberX, numberY] = polar(houses.cusps[house] + span / 2, ascendant, 173, center);
    parts.push(`<line x1="${round(innerX, 2)}" y1="${round(innerY, 2)}" x2="${round(outerX, 2)}" y2="${round(outerY, 2)}" stroke="${house === 1 || house === 10 ? '#d8be77' : '#494158'}" stroke-width="${house === 1 || house === 10 ? 2.5 : 1}"/>`);
    parts.push(`<text x="${round(numberX, 2)}" y="${round(numberY + 5, 2)}" fill="#8f879c" font-size="13" text-anchor="middle">${house}</text>`);
  }

  for (const aspect of aspects) {
    const first = planetMap.get(aspect.first);
    const second = planetMap.get(aspect.second);
    if (!first || !second) continue;
    const [x1, y1] = polar(first.longitude, ascendant, 138, center);
    const [x2, y2] = polar(second.longitude, ascendant, 138, center);
    parts.push(`<line x1="${round(x1, 2)}" y1="${round(y1, 2)}" x2="${round(x2, 2)}" y2="${round(y2, 2)}" stroke="${aspect.color}" stroke-width="1.2" opacity=".58"/>`);
  }

  const occupied = [];
  for (const planet of planets) {
    let displayLongitude = planet.longitude;
    for (const used of occupied) {
      if (angularDistance(displayLongitude, used) < 5.2) displayLongitude += 5.2;
    }
    occupied.push(normalize(displayLongitude));
    const [lineX, lineY] = polar(planet.longitude, ascendant, 220, center);
    const [symbolX, symbolY] = polar(displayLongitude, ascendant, 258, center);
    parts.push(`<line x1="${round(lineX, 2)}" y1="${round(lineY, 2)}" x2="${round(symbolX, 2)}" y2="${round(symbolY, 2)}" stroke="#8c7b9d" opacity=".65"/>`);
    parts.push(`<circle cx="${round(symbolX, 2)}" cy="${round(symbolY, 2)}" r="17" fill="#171425" stroke="#d8be77"/>`);
    parts.push(`<text x="${round(symbolX, 2)}" y="${round(symbolY + 8, 2)}" fill="#f6f0e5" font-size="23" text-anchor="middle" filter="url(#glow)">${planet.symbol}</text>`);
  }

  parts.push('<text x="360" y="338" fill="#d8be77" font-family="Georgia,serif" font-size="34" text-anchor="middle">MS</text>');
  parts.push(`<text x="360" y="371" fill="#f6f0e5" font-family="Georgia,serif" font-size="22" text-anchor="middle">${escapeXml(subject.name)}</text>`);
  parts.push(`<text x="360" y="398" fill="#a9a1b5" font-size="12" text-anchor="middle">${escapeXml(subject.birth_date)} · ${escapeXml(String(subject.birth_time).slice(0, 5))}</text>`);
  parts.push('<text x="360" y="420" fill="#756d80" font-size="10" text-anchor="middle">TROPICAL · PLACIDUS · SWISS EPHEMERIS WASM</text>');
  parts.push('</svg>');
  return parts.join('');
}

function calculateChart(subject) {
  if (!subject || typeof subject !== 'object') throw new Error('Faltan los datos de nacimiento.');
  if (!subject.name || !subject.birth_date || !subject.birth_time || !subject.timezone) throw new Error('Los datos de nacimiento están incompletos.');
  const latitude = Number(subject.latitude);
  const longitude = Number(subject.longitude);
  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90) throw new Error('La latitud no es válida.');
  if (!Number.isFinite(longitude) || longitude < -180 || longitude > 180) throw new Error('La longitud no es válida.');

  const utcDate = localBirthToUtc(subject);
  const julianDay = swe.dateToJulianDay(utcDate);
  const houseResult = swe.calculateHouses(julianDay, latitude, longitude, HouseSystem.Placidus);
  const houses = {
    system: 'Placidus',
    ascendant: round(houseResult.ascendant),
    mc: round(houseResult.mc),
    armc: round(houseResult.armc),
    vertex: round(houseResult.vertex),
    cusps: Array.from({ length: 13 }, (_, index) => index === 0 ? null : round(houseResult.cusps[index])),
  };

  const planets = bodies.map(([key, name, symbol, body]) => {
    const position = swe.calculatePosition(julianDay, body);
    const longitudeValue = round(position.longitude);
    return {
      key, name, symbol,
      longitude: longitudeValue,
      latitude: round(position.latitude),
      distance: round(position.distance),
      longitude_speed: round(position.longitudeSpeed),
      retrograde: Number(position.longitudeSpeed) < 0,
      sign: zodiacPosition(longitudeValue),
      house: houseForLongitude(longitudeValue, houses.cusps),
    };
  });
  const aspects = calculateAspects(planets);
  const normalizedSubject = {
    name: String(subject.name),
    birth_date: String(subject.birth_date),
    birth_time: String(subject.birth_time),
    birthplace: String(subject.birthplace || ''),
    timezone: String(subject.timezone),
    latitude, longitude,
    utc: utcDate.toISOString(),
    julian_day: round(julianDay, 8),
  };
  const data = {
    metadata: {
      engine: 'Swiss Ephemeris WebAssembly',
      engine_version: swe.version(),
      bridge_version: ENGINE_VERSION,
      ephemeris: 'Moshier incorporada',
      zodiac: 'Tropical',
      house_system: 'Placidus',
      calculation_location: 'GitHub Pages / navegador',
      generated_at: new Date().toISOString(),
    },
    subject: normalizedSubject,
    planets,
    houses,
    aspects: aspects.map(({ color, ...aspect }) => aspect),
  };
  return { data, svg: renderChartSvg(normalizedSubject, planets, houses, aspects) };
}

function respond(target, origin, payload) {
  target?.postMessage(payload, origin);
}

window.addEventListener('message', (event) => {
  if (!allowedOrigins.has(event.origin) || event.source !== window.parent) return;
  const message = event.data;
  if (!message || message.type !== 'msastrologia:calculate') return;
  const requestId = String(message.requestId || '');
  if (!ready) {
    respond(event.source, event.origin, { type: 'msastrologia:error', requestId, error: 'El motor todavía se está inicializando.' });
    return;
  }
  try {
    statusNode.textContent = 'Calculando posiciones, casas y aspectos…';
    const result = calculateChart(message.subject);
    respond(event.source, event.origin, { type: 'msastrologia:result', requestId, ...result });
    statusNode.textContent = 'Carta calculada correctamente.';
  } catch (error) {
    respond(event.source, event.origin, { type: 'msastrologia:error', requestId, error: error instanceof Error ? error.message : 'No fue posible calcular la carta.' });
    statusNode.textContent = 'No fue posible completar el cálculo.';
  }
});

try {
  await swe.init('./vendor/swisseph.wasm');
  ready = true;
  statusNode.textContent = 'Motor preparado.';
  for (const origin of allowedOrigins) {
    if (window.parent !== window) window.parent.postMessage({ type: 'msastrologia:ready', version: ENGINE_VERSION, engine: swe.version() }, origin);
  }
} catch (error) {
  statusNode.textContent = 'Error al iniciar Swiss Ephemeris.';
  console.error(error);
}
