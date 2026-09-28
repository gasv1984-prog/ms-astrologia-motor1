<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require MS_ROOT . '/inc/ai.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $body = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $publicId = (string)($body['public_id'] ?? ''); $token = (string)($body['token'] ?? ''); $question = trim((string)($body['message'] ?? ''));
    $item = request_by_public_id($publicId);
    if (!$item || !verify_result_token($publicId, $token) || !result_ready($item)) { throw new RuntimeException('Acceso no valido.'); }
    if (mb_strlen($question) < 2 || mb_strlen($question) > 1200) { throw new RuntimeException('La pregunta debe tener entre 2 y 1200 caracteres.'); }
    $ai = active_ai_config($item['ai_provider'] ?: null) ?? active_ai_config();
    if (!$ai) { throw new RuntimeException('Aurita aun no tiene un proveedor de IA configurado.'); }
    $historyStmt = db()->prepare('SELECT role,content FROM aurita_messages WHERE request_id=? ORDER BY id DESC LIMIT 8');
    $historyStmt->execute([$item['id']]); $history = array_reverse($historyStmt->fetchAll());
    $historyText = implode("\n", array_map(fn($m) => strtoupper($m['role']) . ': ' . $m['content'], $history));
    $prompt = "Eres Aurita, una orientadora esotérica experta, cálida y responsable. Habla directamente con {$item['full_name']} en español y en segunda persona singular. Responde únicamente a partir de la lectura entregada; no inventes posiciones planetarias ni afirmes destinos inevitables. No uses Markdown, almohadillas ni asteriscos.\n\nLECTURA:\n{$item['ai_interpretation']}\n\nCONVERSACIÓN:\n{$historyText}\n\nPREGUNTA:\n{$question}";
    $answer = clean_ai_text(ai_generate($ai['provider'], decrypt_secret($ai['encrypted_api_key']), $ai['model'], $prompt));
    $stmt = db()->prepare('INSERT INTO aurita_messages (request_id,role,content) VALUES (?,?,?)');
    $stmt->execute([$item['id'],'user',$question]); $stmt->execute([$item['id'],'assistant',$answer]);
    echo json_encode(['answer'=>$answer,'answer_html'=>render_markdown($answer)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
