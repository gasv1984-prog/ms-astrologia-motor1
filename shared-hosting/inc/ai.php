<?php
declare(strict_types=1);

function ai_http(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Activa la extension cURL de PHP en Hostinger.');
    }
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error) {
        throw new RuntimeException('No fue posible conectar con el proveedor de IA: ' . $error);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('El proveedor de IA devolvio una respuesta no valida.');
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? $data['message'] ?? 'La clave o la solicitud fue rechazada.';
        throw new RuntimeException((string)$message);
    }
    return $data;
}

function ai_models(string $provider, string $apiKey): array
{
    if ($apiKey === '') {
        throw new RuntimeException('Ingresa una clave API.');
    }
    if ($provider === 'openai') {
        $data = ai_http('GET', 'https://api.openai.com/v1/models', ['Authorization: Bearer ' . $apiKey]);
        $models = [];
        foreach ($data['data'] ?? [] as $item) {
            $id = (string)($item['id'] ?? '');
            if ($id !== '' && preg_match('/^(gpt-|o[1345](?:-|$)|chatgpt-)/', $id)) {
                $models[$id] = $id;
            }
        }
        ksort($models);
        return array_values($models);
    }
    if ($provider === 'gemini') {
        $data = ai_http('GET', 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey));
        $models = [];
        foreach ($data['models'] ?? [] as $item) {
            if (!in_array('generateContent', $item['supportedGenerationMethods'] ?? [], true)) {
                continue;
            }
            $id = preg_replace('#^models/#', '', (string)($item['name'] ?? ''));
            if ($id !== '') { $models[$id] = $id; }
        }
        ksort($models);
        return array_values($models);
    }
    throw new RuntimeException('Proveedor no compatible.');
}

function ai_generate(string $provider, string $apiKey, string $model, string $prompt): string
{
    if ($provider === 'openai') {
        $data = ai_http('POST', 'https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $apiKey], [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Responde siempre en español claro, cálido y responsable. No hagas diagnósticos médicos, legales ni financieros.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        $text = $data['choices'][0]['message']['content'] ?? '';
    } elseif ($provider === 'gemini') {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $data = ai_http('POST', $endpoint, [], [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.65],
        ]);
        $text = '';
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= (string)($part['text'] ?? '');
        }
    } else {
        throw new RuntimeException('Proveedor no compatible.');
    }
    if (trim($text) === '') {
        throw new RuntimeException('La IA no genero contenido.');
    }
    return trim($text);
}

function active_ai_config(?string $provider = null): ?array
{
    if ($provider) {
        $stmt = db()->prepare('SELECT * FROM ai_configs WHERE provider = ?');
        $stmt->execute([$provider]);
    } else {
        $stmt = db()->query('SELECT * FROM ai_configs ORDER BY updated_at DESC LIMIT 1');
    }
    return $stmt->fetch() ?: null;
}
