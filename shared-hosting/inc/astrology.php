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
    if ($mode === 'rapidapi') {
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
        'context' => json_encode($chartData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'engine' => $mode,
    ];
}
