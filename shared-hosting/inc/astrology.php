<?php
declare(strict_types=1);

function astrology_config(): ?array
{
    $row = db()->query('SELECT * FROM astrology_configs WHERE id=1')->fetch();
    return $row ?: null;
}

function astrology_request(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Activa la extensión cURL de PHP en Hostinger.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '') {
        throw new RuntimeException('No fue posible conectar con el motor astrológico: ' . $error);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('El motor astrológico devolvió una respuesta no válida.');
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['detail'] ?? $data['message'] ?? $data['error']['message'] ?? 'La solicitud fue rechazada.';
        throw new RuntimeException('Motor astrológico: ' . (is_string($message) ? $message : 'error HTTP ' . $status));
    }
    return $data;
}

function sanitize_chart_svg(string $svg): string
{
    $svg = trim($svg);
    if ($svg === '' || stripos($svg, '<svg') === false) {
        throw new RuntimeException('El motor no devolvió la carta en formato SVG.');
    }
    $svg = preg_replace('/<\?(?:xml|xml-stylesheet)[^>]*\?>/i', '', $svg) ?? $svg;
    $svg = preg_replace('#<(script|foreignObject|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $svg) ?? $svg;
    $svg = preg_replace('#<(script|foreignObject|iframe|object|embed)\b[^>]*/?>#is', '', $svg) ?? $svg;
    $svg = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
    $svg = preg_replace('/(?:javascript|data\s*:\s*text\/html)\s*:/i', '', $svg) ?? $svg;
    if (strlen($svg) > 4_000_000) {
        throw new RuntimeException('La carta SVG supera el tamaño permitido.');
    }
    return $svg;
}

function calculate_hosted_natal_chart(array $item): array
{
    $config = astrology_config();
    if (!$config) {
        throw new RuntimeException('Configura primero el motor astrológico desde el menú de administración.');
    }
    $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$item['birth_date']);
    $birthTime = DateTimeImmutable::createFromFormat('!H:i:s', (string)$item['birth_time'])
        ?: DateTimeImmutable::createFromFormat('!H:i', substr((string)$item['birth_time'], 0, 5));
    if (!$birthDate || !$birthTime) {
        throw new RuntimeException('La fecha u hora de nacimiento no es válida.');
    }
    $payload = [
        'subject' => [
            'name' => (string)$item['full_name'],
            'year' => (int)$birthDate->format('Y'),
            'month' => (int)$birthDate->format('n'),
            'day' => (int)$birthDate->format('j'),
            'hour' => (int)$birthTime->format('G'),
            'minute' => (int)$birthTime->format('i'),
            'longitude' => (float)$item['longitude'],
            'latitude' => (float)$item['latitude'],
            'timezone' => (string)$item['timezone'],
        ],
        'theme' => 'dark',
        'language' => 'ES',
        'style' => 'modern',
        'transparent_background' => true,
        'split_chart' => false,
    ];
    $mode = (string)$config['mode'];
    $key = !empty($config['encrypted_api_key']) ? decrypt_secret((string)$config['encrypted_api_key']) : '';
    if ($mode === 'github_pages') {
        throw new RuntimeException('El motor de GitHub se ejecuta en el navegador. Recarga la página y usa el botón de cálculo web.');
    } elseif ($mode === 'rapidapi') {
        if ($key === '') {
            throw new RuntimeException('Falta la clave de RapidAPI del motor astrológico.');
        }
        $url = 'https://astrologer.p.rapidapi.com/api/v5/chart/birth-chart';
        $headers = ['X-RapidAPI-Host: astrologer.p.rapidapi.com', 'X-RapidAPI-Key: ' . $key];
    } else {
        $base = rtrim((string)$config['base_url'], '/');
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('La URL del motor astrológico no es válida.');
        }
        $url = $base . '/api/v5/chart/birth-chart';
        $headers = $key !== '' ? ['X-MS-Astrology-Key: ' . $key] : [];
    }
    $response = astrology_request($url, $headers, $payload);
    $svg = sanitize_chart_svg((string)($response['chart'] ?? ''));
    $chartData = $response['chart_data'] ?? null;
    if (!is_array($chartData)) {
        throw new RuntimeException('El motor no devolvió los datos técnicos de la carta.');
    }
    return [
        'svg' => $svg,
        'data_json' => json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'context' => chart_context_for_ai($chartData),
        'engine' => $mode,
    ];
}

function chart_context_for_ai(array $chart): string
{
    if (!is_array($chart['planets'] ?? null)) {
        return json_encode($chart, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $lines = [];
    $metadata = $chart['metadata'] ?? [];
    $subject = $chart['subject'] ?? [];
    $lines[] = 'CÁLCULO ASTRONÓMICO VERIFICADO';
    $lines[] = 'Motor: ' . ($metadata['engine'] ?? 'Swiss Ephemeris') . ' ' . ($metadata['engine_version'] ?? '');
    $lines[] = 'Efemérides: ' . ($metadata['ephemeris'] ?? 'Swiss Ephemeris');
    $lines[] = 'Zodiaco: ' . ($metadata['zodiac'] ?? 'Tropical') . '; casas: ' . ($metadata['house_system'] ?? 'Placidus') . '.';
    $lines[] = 'UTC de nacimiento: ' . ($subject['utc'] ?? '') . '; día juliano: ' . ($subject['julian_day'] ?? '') . '.';
    $lines[] = '';
    $lines[] = 'POSICIONES NATALES';
    foreach ($chart['planets'] as $planet) {
        $sign = $planet['sign'] ?? [];
        $position = sprintf('%02d° %02d′ %02d″ de %s', (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), (int)($sign['second'] ?? 0), (string)($sign['name'] ?? ''));
        $motion = !empty($planet['retrograde']) ? ' retrógrado' : ' directo';
        $lines[] = sprintf('%s: %s; casa %s;%s; longitud %.6f°; velocidad %.6f°/día.', $planet['name'] ?? $planet['key'], $position, $planet['house'] ?? '—', $motion, (float)($planet['longitude'] ?? 0), (float)($planet['longitude_speed'] ?? 0));
    }
    if (is_array($chart['angles'] ?? null)) {
        $lines[] = '';
        $lines[] = 'ÁNGULOS';
        foreach ($chart['angles'] as $point) {
            $sign = $point['sign'] ?? [];
            $lines[] = sprintf('%s: %02d° %02d′ %02d″ de %s (%.6f°).', $point['name'] ?? $point['key'], (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), (int)($sign['second'] ?? 0), $sign['name'] ?? '', (float)($point['longitude'] ?? 0));
        }
    }
    if (is_array($chart['lots'] ?? null)) {
        $lines[] = '';
        $lines[] = 'PARTES ARÁBIGAS';
        foreach ($chart['lots'] as $point) {
            $sign = $point['sign'] ?? [];
            $lines[] = sprintf('%s: %02d° %02d′ de %s, casa %s.', $point['name'] ?? $point['key'], (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), $sign['name'] ?? '', $point['house'] ?? '—');
        }
    }
    $lines[] = '';
    $lines[] = 'CÚSPIDES DE CASAS';
    foreach (($chart['houses']['details'] ?? []) as $house) {
        $sign = $house['sign'] ?? [];
        $lines[] = sprintf('Casa %d: %02d° %02d′ %02d″ de %s (%.6f°).', (int)($house['house'] ?? 0), (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), (int)($sign['second'] ?? 0), $sign['name'] ?? '', (float)($house['longitude'] ?? 0));
    }
    $lines[] = '';
    $lines[] = 'ASPECTOS NATALES';
    foreach (($chart['aspects'] ?? []) as $aspect) {
        $lines[] = sprintf('%s %s %s; orbe %.3f°; %s; aspecto %s.', $aspect['first_name'] ?? $aspect['first'] ?? '', $aspect['name'] ?? '', $aspect['second_name'] ?? $aspect['second'] ?? '', (float)($aspect['orb'] ?? 0), $aspect['movement'] ?? '', $aspect['family'] ?? '');
    }
    if (is_array($chart['lunar_phase'] ?? null)) {
        $phase = $chart['lunar_phase'];
        $lines[] = '';
        $lines[] = sprintf('FASE LUNAR: %s, ángulo %.4f°, iluminación %.2f%%, ciclo %s.', $phase['name'] ?? '', (float)($phase['angle'] ?? 0), (float)($phase['illumination_percentage'] ?? 0), $phase['cycle'] ?? '');
    }
    if (is_array($chart['distribution'] ?? null)) {
        $lines[] = 'DISTRIBUCIÓN DE ELEMENTOS: ' . json_encode($chart['distribution']['elements']['percentages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '.';
        $lines[] = 'DISTRIBUCIÓN DE MODALIDADES: ' . json_encode($chart['distribution']['modalities']['percentages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '.';
    }
    if (is_array($chart['transits'] ?? null)) {
        $transits = $chart['transits'];
        $lines[] = '';
        $lines[] = 'TRÁNSITOS PERSONALIZADOS PARA ' . ($transits['reference_date'] ?? '') . ' (' . ($transits['period_type'] ?? '') . ')';
        foreach (($transits['planets'] ?? []) as $planet) {
            if (str_contains((string)($planet['key'] ?? ''), 'south_node')) { continue; }
            $sign = $planet['sign'] ?? [];
            $lines[] = sprintf('%s en tránsito: %02d° %02d′ de %s, recorriendo la casa natal %s%s.', $planet['name'] ?? $planet['key'], (int)($sign['degree'] ?? 0), (int)($sign['minute'] ?? 0), $sign['name'] ?? '', $planet['house'] ?? '—', !empty($planet['retrograde']) ? ', retrógrado' : '');
        }
        $lines[] = 'ASPECTOS DE TRÁNSITO A LA CARTA NATAL';
        foreach (($transits['natal_aspects'] ?? []) as $aspect) {
            $lines[] = sprintf('%s en tránsito %s %s natal; orbe %.3f°.', $aspect['transit_name'] ?? $aspect['transit'] ?? '', $aspect['name'] ?? '', $aspect['natal_name'] ?? $aspect['natal'] ?? '', (float)($aspect['orb'] ?? 0));
        }
    }
    return implode("\n", $lines);
}

function service_interpretation_prompt(array $item, string $context): string
{
    $name = trim((string)$item['full_name']);
    $common = "Usa exclusivamente los datos astronómicos verificados que aparecen al final. No inventes posiciones, casas ni aspectos. "
        . "Escribe en español natural y dirígete directamente a {$name} en segunda persona singular (tú), mencionando su nombre de forma cálida y natural. "
        . "Cada interpretación debe conectar planeta, signo, casa y aspectos concretos; evita frases genéricas que servirían para cualquier persona. "
        . "Distingue hechos calculados de interpretación simbólica. No hagas afirmaciones deterministas ni diagnósticos o consejos médicos, legales o financieros. "
        . "No uses Markdown: no escribas almohadillas, asteriscos, guiones decorativos, tablas ni bloques de código. "
        . "Pon cada título de sección en una línea independiente y en MAYÚSCULAS, seguido por párrafos completos. ";
    if (($item['service_type'] ?? 'natal_chart') === 'personal_horoscope') {
        $periods = ['daily' => 'diario', 'weekly' => 'semanal', 'monthly' => 'mensual'];
        $period = $periods[$item['horoscope_period'] ?? 'monthly'] ?? 'mensual';
        return "Redacta un horóscopo PERSONALIZADO {$period} para {$name}, con fecha de referencia {$item['horoscope_date']}. "
            . $common
            . "No redactes un horóscopo general por signo solar: interpreta los tránsitos calculados sobre su carta natal y prioriza los aspectos de menor orbe. "
            . "Enfoque solicitado por la persona: " . ((string)($item['horoscope_focus'] ?? '') ?: 'visión integral del período') . ". "
            . "Extensión: 900 a 1400 palabras. Secciones obligatorias: CLIMA PERSONAL DEL PERÍODO, TRÁNSITOS CENTRALES, VÍNCULOS, TRABAJO Y RECURSOS, BIENESTAR Y RITMO, FECHAS Y DECISIONES A OBSERVAR, ORIENTACIÓN PARA INTEGRAR EL CICLO.\n\n"
            . "DATOS DE {$name}: {$item['birth_date']} {$item['birth_time']}, {$item['birthplace']} ({$item['timezone']}).\n\nDATOS TÉCNICOS VERIFICADOS:\n{$context}";
    }
    return "Redacta una lectura natal profesional, profunda y personalizada para {$name}. "
        . $common
        . "Extensión: 1400 a 2200 palabras. Integra contradicciones y repeticiones del mapa; no describas cada posición de forma aislada. "
        . "Secciones obligatorias: RETRATO CENTRAL, SOL LUNA Y ASCENDENTE, MENTE DESEO Y ACCIÓN, VÍNCULOS Y AFECTIVIDAD, VOCACIÓN Y DIRECCIÓN, RECURSOS Y FORTALEZAS, TENSIONES Y APRENDIZAJES, NODOS LILITH Y QUIRÓN, INTEGRACIÓN PERSONAL, PREGUNTAS PARA TU PROCESO.\n\n"
        . "DATOS DE {$name}: {$item['birth_date']} {$item['birth_time']}, {$item['birthplace']} ({$item['latitude']}, {$item['longitude']}, {$item['timezone']}).\n"
        . "NOTAS APORTADAS: " . ((string)($item['notes'] ?? '') ?: 'ninguna') . ".\n\nDATOS TÉCNICOS VERIFICADOS:\n{$context}";
}
