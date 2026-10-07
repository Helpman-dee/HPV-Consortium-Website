<?php
require_once __DIR__ . '/site-content-store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$group = $_GET['group'] ?? '';
$allowedGroups = ['investigators', 'staff', 'board'];
if (!is_string($group) || !in_array($group, $allowedGroups, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown directory.']);
    exit;
}

$content = site_content_read();
$people = $content['people'][$group] ?? [];
if (!is_array($people)) {
    $people = [];
}
$people = array_values(array_filter($people, function ($person) {
    return !empty($person['visible']);
}));

echo json_encode([
    'managed' => !empty($content['managed'][$group]),
    'people' => $people,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);