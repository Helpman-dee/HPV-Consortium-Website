<?php
require_once __DIR__ . '/news-store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$posts = array_values(array_filter(news_store_read(), function ($post) {
    return !empty($post['published']);
}));
usort($posts, function ($left, $right) {
    return strcmp($right['date'] ?? '', $left['date'] ?? '');
});

echo json_encode($posts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);