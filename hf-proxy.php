<?php
// hf-proxy-chat.php
header('Content-Type: application/json');

$query_logfile = '_hf-query-logs.log';
$logfile = 'hf-proxy-debug.log';
$DEBUG_LOGGING = true; // false = OFF
$QUERY_LOGGING = true; // false = OFF

function dbg($msg) {
    global $logfile;
    @file_put_contents($logfile, date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
}

function dbgq($msg) {
    global $query_logfile;
    @file_put_contents($query_logfile, date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['error' => 'No input']);
    exit;
}

$data = json_decode($raw, true);
if ($QUERY_LOGGING){
    dbgq("Instruction: " . ($data['instruction'] ?? '[none]') . " | Content: " . substr(($data['content'] ?? ''), 0, 300));
}
$instruction = $data['instruction'] ?? '';
$content = $data['content'] ?? '';

if (empty($instruction) && empty($content)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty instruction and content']);
    exit;
}

// Free models that work with chat completions - CHOOSE ONE:
$model = "deepseek-ai/DeepSeek-V3.2-Exp:novita"; // Good balance of performance and speed
// $model = "Qwen/Qwen2.5-7B-Instruct"; // More capable but slightly slower
// $model = "google/flan-t5-large"; // Excellent for instruction following
// $model = "microsoft/DialoGPT-large"; // Good for conversational tasks

$hf_api_key = "hf_fe____%PUT YOUR API KEY HERE%____"; // https://huggingface.co/settings/tokens

// Build proper chat messages format
$messages = [
    [
        'role' => 'system',
        'content' => "You are an expert web developer. Edit and improve HTML/CSS for Bootstrap v4.5.3 and jQuery v3.5.1 integrations. If the user ask about icons he mean Font-awesome icons. (Free 6.6.0)Return only the modified HTML/CSS code without any explanations, comments, or additional text and do not include <html> and <head> tags or anything similar - only the inside of the <body> - if some additional styles or scripts are neccassary do not place them at the top of the code, and never include or import any additional libraries. Always return the entire response inside a single code block marked exactly as ```html ```"
    ],
    [
        'role' => 'user', 
        'content' => "Instruction: $instruction\n\nCurrent HTML/CSS content:\n$content"
    ]
];

// Use the unified chat completions endpoint :cite[3]
$endpoint = "https://router.huggingface.co/v1/chat/completions";

$payload = json_encode([
    'model' => $model,
    'messages' => $messages,
    'max_tokens' => 512,
    'temperature' => 0.3,
    'stream' => false
]);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $hf_api_key,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 90
]);

$response = curl_exec($ch);
$info = curl_getinfo($ch);
$code = $info['http_code'];
$err = curl_error($ch);
curl_close($ch);

if ($DEBUG_LOGGING){
    dbg("REQUEST model=$model HTTP_CODE=$code ENDPOINT=$endpoint");
}


if ($err) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL error: ' . $err]);
    exit;
}

if ($code === 200) {
    $decoded = json_decode($response, true);
    
    // Extract response from chat completion format
    $generated_text = $decoded['choices'][0]['message']['content'] ?? 'No response generated';
    
    // Clean up the response
    $clean_response = trim($generated_text);
    
    echo json_encode(['modifiedContent' => $clean_response]);
} else {
    http_response_code($code);
    echo json_encode([
        'error' => "API returned HTTP $code",
        'details' => $response,
        'suggestion' => 'Check if the model is available and your API key has permissions'
    ]);
}
?>