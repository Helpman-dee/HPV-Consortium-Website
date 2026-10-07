<?php

const NEWS_STORE_PATH = __DIR__ . '/news-content.php';
const NEWS_STORE_PREFIX = "<?php http_response_code(404); exit; ?>";

function news_store_decode($contents)
{
    if (!is_string($contents) || strpos($contents, NEWS_STORE_PREFIX) !== 0) {
        return [];
    }

    $lineEnding = strpos($contents, "\n");
    if ($lineEnding === false) {
        return [];
    }

    $posts = json_decode(substr($contents, $lineEnding + 1), true);
    return is_array($posts) ? $posts : [];
}

function news_store_read()
{
    $handle = @fopen(NEWS_STORE_PATH, 'rb');
    if (!$handle) {
        return [];
    }

    flock($handle, LOCK_SH);
    rewind($handle);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return news_store_decode($contents);
}

function news_store_modify($modifier)
{
    $handle = @fopen(NEWS_STORE_PATH, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return false;
    }

    rewind($handle);
    $contents = stream_get_contents($handle);
    $posts = news_store_decode($contents);

    try {
        $updatedPosts = $modifier($posts);
        $encoded = json_encode(array_values($updatedPosts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode news posts.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        $payload = NEWS_STORE_PREFIX . "\n" . $encoded;
        $written = fwrite($handle, $payload);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $written === strlen($payload);
    } catch (Throwable $error) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }
}

function news_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function news_slug($title)
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    return $slug !== '' ? $slug : 'story-' . bin2hex(random_bytes(4));
}

function news_youtube_id($url)
{
    if (!is_string($url) || strlen($url) > 500) {
        return '';
    }

    $parts = parse_url(trim($url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return '';
    }

    $host = strtolower($parts['host'] ?? '');
    $path = trim($parts['path'] ?? '', '/');
    $videoId = '';

    if ($host === 'youtu.be') {
        $videoId = explode('/', $path)[0] ?? '';
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
        if ($path === 'watch') {
            parse_str($parts['query'] ?? '', $query);
            $videoId = $query['v'] ?? '';
        } elseif (preg_match('#^(?:embed|shorts|live)/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $matches)) {
            $videoId = $matches[1];
        }
    }

    return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : '';
}