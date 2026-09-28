import {
  SwissEphemeris,
  Planet,
  LunarPoint,
  Asteroid,
  HouseSystem,
  CalculationFlag,
} from './vendor/swisseph-browser.js';

const ENGINE_VERSION = '2.0.0';
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
  ['aries', 'Aries', '♈', 'fuego', 'cardinal'], ['tauro', 'Tauro', '♉', 'tierra', 'fijo'], ['geminis', 'Géminis', '♊', 'aire', 'mutable'],
  ['cancer', 'Cáncer', '♋', 'agua', 'cardinal'], ['leo', 'Leo', '♌', 'fuego', 'fijo'], ['virgo', 'Virgo', '♍', 'tierra', 'mutable'],
  ['libra', 'Libra', '♎', 'aire', 'cardinal'], ['escorpio', 'Escorpio', '♏', 'agua', 'fijo'], ['sagitario', 'Sagitario', '♐', 'fuego', 'mutable'],
  ['capricornio', 'Capricornio', '♑', 'tierra', 'cardinal'], ['acuario', 'Acuario', '♒', 'aire', 'fijo'], ['piscis', 'Piscis', '♓', 'agua', 'mutable'],
];

const bodies = [
  ['sun', 'Sol', '☉', Planet.Sun, 'luminar'],
  ['moon', 'Luna', '☽', Planet.Moon, 'luminar'],
  ['mercury', 'Mercurio', '☿', Planet.Mercury, 'personal'],
  ['venus', 'Venus', '♀', Planet.Venus, 'personal'],
  ['mars', 'Marte', '♂', Planet.Mars, 'personal'],
  ['jupiter', 'Júpiter', '♃', Planet.Jupiter, 'social'],
  ['saturn', 'Saturno', '♄', Planet.Saturn, 'social'],
  ['uranus', 'Urano', '♅', Planet.Uranus, 'transpersonal'],
  ['neptune', 'Neptuno', '♆', Planet.Neptune, 'transpersonal'],
  ['pluto', 'Plutón', '♇', Planet.Pluto, 'transpersonal'],
  ['chiron', 'Quirón', '⚷', Asteroid.Chiron, 'integración'],
  ['mean_lilith', 'Lilith media', '⚸', LunarPoint.MeanApogee, 'punto'],
  ['mean_north_node', 'Nodo Norte medio', '☊', LunarPoint.MeanNode, 'nodo'],
  ['true_north_node', 'Nodo Norte verdadero', '☊', LunarPoint.TrueNode, 'nodo'],
];

const aspectDefinitions = [
  ['conjunction', 'Conjunción', 0, 8, '#d8be77', 'mayor'],
  ['sextile', 'Sextil', 60, 5, '#79b8c9', 'mayor'],
  ['square', 'Cuadratura', 90, 7, '#cc7187', 'mayor'],
  ['trine', 'Trígono', 120, 7, '#7cc69c', 'mayor'],
  ['quincunx', 'Quincuncio', 150, 3, '#aa8bd4', 'menor'],
  ['opposition', 'Oposición', 180, 8, '#cc7187', 'mayor'],
];

const swe = new SwissEphemeris();
let ready = false;
let calculationFlags = CalculationFlag.MoshierEphemeris | CalculationFlag.Speed;

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
  const [key, name, symbol, element, modality] = zodiac[index];
  return { key, name, symbol, element, modality, index, degree, minute, second };
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

function calculateAspects(planets, futureLongitudes = new Map()) {
  const aspects = [];
  for (let first = 0; first < planets.length; first += 1) {
    for (let second = first + 1; second < planets.length; second += 1) {
      const distance = angularDistance(planets[first].longitude, planets[second].longitude);
      for (const [key, name, angle, orbLimit, color, family] of aspectDefinitions) {
        const orb = Math.abs(distance - angle);
        if (orb <= orbLimit) {
          const futureFirst = futureLongitudes.get(planets[first].key);
          const futureSecond = futureLongitudes.get(planets[second].key);
          const futureOrb = Number.isFinite(futureFirst) && Number.isFinite(futureSecond)
            ? Math.abs(angularDistance(futureFirst, futureSecond) - angle)
            : orb;
          aspects.push({
            key, name, family, angle, orb: round(orb, 3), color,
            first: planets[first].key,
            first_name: planets[first].name,
            second: planets[second].key,
            second_name: planets[second].name,
            movement: futureOrb < orb ? 'aplicativo' : 'separativo',
          });
          break;
        }
      }
    }
  }
  return aspects;
}

function calculateCrossAspects(natalPlanets, transitPlanets) {
  const aspects = [];
  for (const transit of transitPlanets) {
    for (const natal of natalPlanets) {
      const distance = angularDistance(transit.longitude, natal.longitude);
      for (const [key, name, angle, orbLimit, , family] of aspectDefinitions) {
        const personalLimit = Math.min(orbLimit, family === 'mayor' ? 4 : 2);
        const orb = Math.abs(distance - angle);
        if (orb <= personalLimit) {
          aspects.push({
            key, name, family, angle, orb: round(orb, 3),
            transit: transit.key, transit_name: transit.name,
            natal: natal.key, natal_name: natal.name,
          });
          break;
        }
      }
    }
  }
  return aspects.sort((a, b) => a.orb - b.orb);
}

function distribution(points) {
  const elements = { fuego: 0, tierra: 0, aire: 0, agua: 0 };
  const modalities = { cardinal: 0, fijo: 0, mutable: 0 };
  let total = 0;
  for (const point of points) {
    const weight = ['sun', 'moon', 'ascendant'].includes(point.key) ? 2 : 1;
    if (point.sign?.element) elements[point.sign.element] += weight;
    if (point.sign?.modality) modalities[point.sign.modality] += weight;
    total += weight;
  }
  const percentages = (values) => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, round(total ? value * 100 / total : 0, 1)]));
  return {
    elements: { counts: elements, percentages: percentages(elements) },
    modalities: { counts: modalities, percentages: percentages(modalities) },
  };
}

function lunarPhase(sunLongitude, moonLongitude) {
  const angle = normalize(moonLongitude - sunLongitude);
  const phases = [
    ['Luna nueva', 22.5], ['Creciente inicial', 67.5], ['Cuarto creciente', 112.5],
    ['Gibosa creciente', 157.5], ['Luna llena', 202.5], ['Gibosa menguante', 247.5],
    ['Cuarto menguante', 292.5], ['Menguante final', 337.5], ['Luna nueva', 360],
  ];
  const name = phases.find(([, limit]) => angle < limit)?.[0] || 'Luna nueva';
  return {
    name,
    angle: round(angle, 4),
    illumination_percentage: round((1 - Math.cos(angle * Math.PI / 180)) * 50, 2),
    cycle: angle < 180 ? 'creciente' : 'menguante',
  };
}

function polar(longitude, ascendant, radius, center = 360) {
  // Orientación astrológica tradicional: ASC a las 9, casas 2–3 hacia abajo,
  // IC a las 6, DSC a las 3 y MC a las 12.
  const angle = (180 + normalize(longitude - ascendant)) * Math.PI / 180;
  return [center + Math.cos(angle) * radius, center - Math.sin(angle) * radius];
}

function escapeXml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[character]);
}

function renderChartSvg(subject, planets, houses, aspects) {
  const center = 360;
  const ascendant = houses.ascendant;
  const planetMap = new Map(planets.map((planet) => [planet.key, planet]));
  const ascendantPoint = { key: 'ascendant', sign: zodiacPosition(ascendant) };
  const chartBalance = distribution([...planets.filter((planet) => !planet.key.includes('south_node')), ascendantPoint]);
  const sun = planetMap.get('sun');
  const moon = planetMap.get('moon');
  const phase = lunarPhase(sun.longitude, moon.longitude);
  const formatPosition = (longitude) => {
    const position = zodiacPosition(longitude);
    return `${String(position.degree).padStart(2, '0')}°${String(position.minute).padStart(2, '0')}′ ${position.symbol}`;
  };
  const parts = [
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1120 720" role="img" aria-labelledby="chart-title chart-desc">',
    `<title id="chart-title">Carta natal de ${escapeXml(subject.name)}</title>`,
    '<desc id="chart-desc">Carta tropical calculada con Swiss Ephemeris WebAssembly y casas Placidus.</desc>',
    '<defs><radialGradient id="bg" cx="50%" cy="45%"><stop offset="0" stop-color="#242039"/><stop offset="1" stop-color="#0d0c18"/></radialGradient><filter id="glow"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>',
    '<rect width="1120" height="720" rx="20" fill="#0d0c18"/>',
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
  const drawablePlanets = planets.filter((planet) => !['mean_north_node', 'mean_south_node'].includes(planet.key));
  for (const planet of drawablePlanets) {
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

  const [ascX, ascY] = polar(houses.ascendant, ascendant, 315, center);
  const [mcX, mcY] = polar(houses.mc, ascendant, 315, center);
  parts.push(`<text x="${round(ascX, 2)}" y="${round(ascY - 8, 2)}" fill="#f6f0e5" font-size="13" font-weight="700" text-anchor="middle">ASC</text>`);
  parts.push(`<text x="${round(mcX, 2)}" y="${round(mcY - 8, 2)}" fill="#f6f0e5" font-size="13" font-weight="700" text-anchor="middle">MC</text>`);

  parts.push('<text x="360" y="338" fill="#d8be77" font-family="Georgia,serif" font-size="34" text-anchor="middle">MS</text>');
  parts.push(`<text x="360" y="371" fill="#f6f0e5" font-family="Georgia,serif" font-size="22" text-anchor="middle">${escapeXml(subject.name)}</text>`);
  parts.push(`<text x="360" y="398" fill="#a9a1b5" font-size="12" text-anchor="middle">${escapeXml(subject.birth_date)} · ${escapeXml(String(subject.birth_time).slice(0, 5))}</text>`);
  parts.push('<text x="360" y="420" fill="#756d80" font-size="10" text-anchor="middle">TROPICAL · PLACIDUS · SWISS EPHEMERIS WASM</text>');

  // Panel técnico inspirado en la carta profesional original, sin perder la
  // legibilidad de la rueda en pantallas pequeñas.
  parts.push('<line x1="730" y1="24" x2="730" y2="696" stroke="#393347"/>');
  parts.push(`<text x="758" y="48" fill="#f6f0e5" font-family="Georgia,serif" font-size="25">${escapeXml(subject.name)} · CARTA NATAL</text>`);
  parts.push(`<text x="758" y="72" fill="#8f879c" font-size="12">${escapeXml(subject.birthplace || '')} · ${escapeXml(subject.timezone)}</text>`);
  parts.push('<text x="758" y="101" fill="#d8be77" font-size="12" font-weight="700" letter-spacing="2">POSICIONES</text>');
  const tablePlanets = planets.filter((planet) => !['mean_north_node', 'mean_south_node'].includes(planet.key));
  tablePlanets.forEach((planet, index) => {
    const y = 126 + index * 22;
    parts.push(`<text x="758" y="${y}" fill="#d8be77" font-size="17">${planet.symbol}</text>`);
    parts.push(`<text x="783" y="${y}" fill="#e7e0ed" font-size="12">${escapeXml(planet.name)}</text>`);
    parts.push(`<text x="902" y="${y}" fill="#afa7bb" font-size="11" text-anchor="end">${formatPosition(planet.longitude)}${planet.retrograde ? ' ℞' : ''}</text>`);
    parts.push(`<text x="922" y="${y}" fill="#756d80" font-size="10">C${planet.house ?? '—'}</text>`);
  });

  parts.push('<text x="958" y="101" fill="#d8be77" font-size="12" font-weight="700" letter-spacing="2">CÚSPIDES</text>');
  for (let house = 1; house <= 12; house += 1) {
    const y = 126 + (house - 1) * 22;
    parts.push(`<text x="958" y="${y}" fill="#8f879c" font-size="11">Casa ${house}</text>`);
    parts.push(`<text x="1086" y="${y}" fill="#e7e0ed" font-size="11" text-anchor="end">${formatPosition(houses.cusps[house])}</text>`);
  }

  const elements = chartBalance.elements.percentages;
  const modalities = chartBalance.modalities.percentages;
  parts.push('<line x1="758" y1="444" x2="1088" y2="444" stroke="#393347"/>');
  parts.push('<text x="758" y="470" fill="#d8be77" font-size="12" font-weight="700" letter-spacing="2">SÍNTESIS TÉCNICA</text>');
  parts.push(`<text x="758" y="495" fill="#afa7bb" font-size="11">Fase lunar</text><text x="1088" y="495" fill="#e7e0ed" font-size="11" text-anchor="end">${escapeXml(phase.name)} · ${phase.illumination_percentage}%</text>`);
  parts.push(`<text x="758" y="516" fill="#afa7bb" font-size="11">Elementos</text><text x="1088" y="516" fill="#e7e0ed" font-size="10" text-anchor="end">F ${elements.fuego}% · T ${elements.tierra}% · A ${elements.aire}% · Ag ${elements.agua}%</text>`);
  parts.push(`<text x="758" y="537" fill="#afa7bb" font-size="11">Modalidades</text><text x="1088" y="537" fill="#e7e0ed" font-size="10" text-anchor="end">C ${modalities.cardinal}% · F ${modalities.fijo}% · M ${modalities.mutable}%</text>`);
  parts.push(`<text x="758" y="558" fill="#afa7bb" font-size="11">Eje nodal verdadero</text><text x="1088" y="558" fill="#e7e0ed" font-size="10" text-anchor="end">${formatPosition(planetMap.get('true_north_node').longitude)} / ${formatPosition(planetMap.get('true_south_node').longitude)}</text>`);
  parts.push('<text x="758" y="589" fill="#d8be77" font-size="12" font-weight="700" letter-spacing="2">ASPECTOS MÁS EXACTOS</text>');
  aspects.slice().sort((a, b) => a.orb - b.orb).slice(0, 6).forEach((aspect, index) => {
    const y = 614 + index * 17;
    parts.push(`<circle cx="763" cy="${y - 4}" r="3" fill="${aspect.color}"/>`);
    parts.push(`<text x="774" y="${y}" fill="#c7bfce" font-size="10">${escapeXml(aspect.first_name)} ${escapeXml(aspect.name)} ${escapeXml(aspect.second_name)}</text>`);
    parts.push(`<text x="1088" y="${y}" fill="#8f879c" font-size="10" text-anchor="end">${aspect.orb.toFixed(2)}° · ${aspect.movement === 'aplicativo' ? 'A' : 'S'}</text>`);
  });
  parts.push('</svg>');
  return parts.join('');
}

function calculateBodySet(julianDay, cusps) {
  const calculated = bodies.map(([key, name, symbol, body, category]) => {
    const position = swe.calculatePosition(julianDay, body, calculationFlags);
    const longitudeValue = round(position.longitude);
    return {
      key, name, symbol, category,
      longitude: longitudeValue,
      latitude: round(position.latitude),
      distance: round(position.distance),
      longitude_speed: round(position.longitudeSpeed),
      retrograde: Number(position.longitudeSpeed) < 0,
      sign: zodiacPosition(longitudeValue),
      house: cusps ? houseForLongitude(longitudeValue, cusps) : null,
    };
  });
  for (const northKey of ['mean_north_node', 'true_north_node']) {
    const north = calculated.find((point) => point.key === northKey);
    if (!north) continue;
    const trueNode = northKey.startsWith('true');
    const southLongitude = normalize(north.longitude + 180);
    calculated.push({
      ...north,
      key: trueNode ? 'true_south_node' : 'mean_south_node',
      name: trueNode ? 'Nodo Sur verdadero' : 'Nodo Sur medio',
      symbol: '☋',
      longitude: round(southLongitude),
      sign: zodiacPosition(southLongitude),
      house: cusps ? houseForLongitude(southLongitude, cusps) : null,
    });
  }
  return calculated;
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
  const cusps = Array.from({ length: 13 }, (_, index) => index === 0 ? null : round(houseResult.cusps[index]));
  const houses = {
    system: 'Placidus',
    ascendant: round(houseResult.ascendant),
    mc: round(houseResult.mc),
    armc: round(houseResult.armc),
    vertex: round(houseResult.vertex),
    cusps,
    details: Array.from({ length: 12 }, (_, index) => {
      const house = index + 1;
      return { house, longitude: cusps[house], sign: zodiacPosition(cusps[house]) };
    }),
  };

  const planets = calculateBodySet(julianDay, cusps);
  const futurePlanets = calculateBodySet(julianDay + 1 / 24, null);
  const futureLongitudes = new Map(futurePlanets.map((planet) => [planet.key, planet.longitude]));
  const aspectPlanets = planets.filter((planet) => !planet.key.startsWith('mean_') && !planet.key.endsWith('south_node'));
  const aspects = calculateAspects(aspectPlanets, futureLongitudes);
  const sun = planets.find((planet) => planet.key === 'sun');
  const moon = planets.find((planet) => planet.key === 'moon');
  const ascendant = { key: 'ascendant', name: 'Ascendente', longitude: houses.ascendant, sign: zodiacPosition(houses.ascendant), house: 1 };
  const mediumCoeli = { key: 'medium_coeli', name: 'Medio Cielo', longitude: houses.mc, sign: zodiacPosition(houses.mc), house: 10 };
  const angles = [
    ascendant,
    { key: 'descendant', name: 'Descendente', longitude: round(normalize(houses.ascendant + 180)), sign: zodiacPosition(houses.ascendant + 180), house: 7 },
    mediumCoeli,
    { key: 'imum_coeli', name: 'Fondo del Cielo', longitude: round(normalize(houses.mc + 180)), sign: zodiacPosition(houses.mc + 180), house: 4 },
    { key: 'vertex', name: 'Vértice', longitude: houses.vertex, sign: zodiacPosition(houses.vertex), house: houseForLongitude(houses.vertex, cusps) },
  ];
  const isDiurnal = (sun?.house ?? 0) >= 7 && (sun?.house ?? 0) <= 12;
  const fortuneLongitude = normalize(houses.ascendant + (isDiurnal ? moon.longitude - sun.longitude : sun.longitude - moon.longitude));
  const spiritLongitude = normalize(houses.ascendant + (isDiurnal ? sun.longitude - moon.longitude : moon.longitude - sun.longitude));
  const lots = [
    { key: 'part_of_fortune', name: 'Parte de la Fortuna', symbol: '⊗', longitude: round(fortuneLongitude), sign: zodiacPosition(fortuneLongitude), house: houseForLongitude(fortuneLongitude, cusps) },
    { key: 'part_of_spirit', name: 'Parte del Espíritu', symbol: '⊕', longitude: round(spiritLongitude), sign: zodiacPosition(spiritLongitude), house: houseForLongitude(spiritLongitude, cusps) },
  ];
  const balance = distribution([...planets.filter((planet) => !planet.key.includes('south_node')), ascendant]);
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
  let transits = null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(subject.transit_date || ''))) {
    const transitDate = new Date(`${subject.transit_date}T12:00:00.000Z`);
    const transitJulianDay = swe.dateToJulianDay(transitDate);
    const transitPlanets = calculateBodySet(transitJulianDay, cusps);
    const meaningfulNatalPoints = planets.filter((planet) => !planet.key.startsWith('mean_') && !planet.key.endsWith('south_node'));
    const meaningfulTransitPoints = transitPlanets.filter((planet) => !planet.key.startsWith('mean_') && !planet.key.endsWith('south_node'));
    transits = {
      reference_date: String(subject.transit_date),
      period_type: String(subject.horoscope_period || 'monthly'),
      focus: String(subject.horoscope_focus || ''),
      utc: transitDate.toISOString(),
      julian_day: round(transitJulianDay, 8),
      planets: transitPlanets,
      natal_aspects: calculateCrossAspects(meaningfulNatalPoints, meaningfulTransitPoints),
    };
  }
  const data = {
    chart_type: 'Natal',
    metadata: {
      engine: 'Swiss Ephemeris WebAssembly',
      engine_version: swe.version(),
      bridge_version: ENGINE_VERSION,
      ephemeris: 'Swiss Ephemeris oficial (archivos sepl/semo/seas 1800–2399)',
      precision: 'efemérides Swiss con velocidad; grados conservados a 6 decimales',
      zodiac: 'Tropical',
      zodiac_direction: 'contrario a las manecillas del reloj',
      house_system: 'Placidus',
      calculation_location: 'GitHub Pages / navegador',
      generated_at: new Date().toISOString(),
    },
    subject: normalizedSubject,
    planets,
    angles,
    lots,
    houses,
    aspects: aspects.map(({ color, ...aspect }) => aspect),
    lunar_phase: lunarPhase(sun.longitude, moon.longitude),
    distribution: balance,
    transits,
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
  statusNode.textContent = 'Cargando efemérides profesionales…';
  await swe.loadEphemerisFiles([
    { name: 'sepl_18.se1', url: './ephe/sepl_18.se1' },
    { name: 'semo_18.se1', url: './ephe/semo_18.se1' },
    { name: 'seas_18.se1', url: './ephe/seas_18.se1' },
  ]);
  calculationFlags = CalculationFlag.SwissEphemeris | CalculationFlag.Speed;
  ready = true;
  statusNode.textContent = 'Motor profesional preparado.';
  for (const origin of allowedOrigins) {
    if (window.parent !== window) window.parent.postMessage({ type: 'msastrologia:ready', version: ENGINE_VERSION, engine: swe.version() }, origin);
  }
} catch (error) {
  statusNode.textContent = 'Error al iniciar Swiss Ephemeris.';
  console.error(error);
}
