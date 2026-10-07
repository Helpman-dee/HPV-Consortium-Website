<?php

const SITE_CONTENT_PATH = __DIR__ . '/site-content.php';
const SITE_CONTENT_PREFIX = '<?php http_response_code(404); exit; ?>';

function site_content_decode($contents)
{
    if (!is_string($contents) || strpos($contents, SITE_CONTENT_PREFIX) !== 0) {
        return [];
    }

    $lineEnding = strpos($contents, "\n");
    if ($lineEnding === false) {
        return [];
    }

    $data = json_decode(substr($contents, $lineEnding + 1), true);
    return is_array($data) ? $data : [];
}

function site_content_read()
{
    $handle = @fopen(SITE_CONTENT_PATH, 'rb');
    if (!$handle) {
        return [];
    }

    flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return site_content_decode($contents);
}

function site_content_modify($modifier)
{
    $handle = @fopen(SITE_CONTENT_PATH, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return false;
    }

    rewind($handle);
    $data = site_content_decode(stream_get_contents($handle));

    try {
        $updatedData = $modifier($data);
        $encoded = json_encode($updatedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_array($updatedData) || $encoded === false) {
            throw new RuntimeException('Could not encode site content.');
        }

        $payload = SITE_CONTENT_PREFIX . "\n" . $encoded;
        rewind($handle);
        ftruncate($handle, 0);
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

function site_content_plain_text($node)
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return $node->nodeValue;
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }
    if ($node->nodeName === 'br') {
        return "\n";
    }

    $text = '';
    foreach ($node->childNodes as $child) {
        $text .= site_content_plain_text($child);
    }
    if (in_array(strtolower($node->nodeName), ['p', 'li', 'div'], true)) {
        $text .= "\n\n";
    }
    return $text;
}

function site_content_import_legacy_people()
{
    $sources = [
        'investigators' => 'team.html',
        'staff' => 'staff.html',
        'board' => 'sab.html',
    ];
    $peopleByGroup = [];
    $previousLibxmlState = libxml_use_internal_errors(true);

    foreach ($sources as $group => $filename) {
        $peopleByGroup[$group] = [];
        $sourcePath = __DIR__ . '/' . $filename;
        $html = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
        if ($html === false) {
            continue;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            continue;
        }
        $xpath = new DOMXPath($document);
        $cards = $xpath->query('//*[@id="team"]//*[contains(concat(" ", normalize-space(@class), " "), " person ")]');
        if (!$cards) {
            continue;
        }

        foreach ($cards as $index => $card) {
            $nameNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " person-contents ")]//*[self::h3 or self::h4]', $card)->item(0);
            $name = $nameNode ? trim(preg_replace('/\s+/', ' ', $nameNode->textContent)) : '';
            if ($name === '') {
                continue;
            }

            $roleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " position ")]', $card)->item(0);
            $role = $roleNode ? trim(preg_replace('/\s+/', ' ', $roleNode->textContent)) : '';
            $imageNode = $xpath->query('.//figure//img[@src]', $card)->item(0);
            $image = $imageNode ? trim($imageNode->getAttribute('src')) : '';
            $bio = '';
            $socialLinks = [];

            foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " social ")]//a[@href]', $card) as $link) {
                $url = trim($link->getAttribute('href'));
                if (preg_match('#^https?://#i', $url)) {
                    $socialLinks[] = $url;
                }
            }

            foreach ($card->getElementsByTagName('*') as $element) {
                $target = $element->getAttribute('data-bs-target');
                if (!preg_match('/^#([A-Za-z0-9_-]+)$/', $target, $matches)) {
                    continue;
                }

                $modal = $xpath->query('//*[@id="' . $matches[1] . '"]')->item(0);
                if (!$modal) {
                    continue;
                }
                $bioNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " modal-body ")]', $modal)->item(0);
                if ($bioNode) {
                    $bio = trim(preg_replace('/[ \t]+/', ' ', site_content_plain_text($bioNode)));
                    $bio = trim(preg_replace('/\n{3,}/', "\n\n", $bio));
                }
                break;
            }

            $peopleByGroup[$group][] = [
                'id' => $group . '-' . substr(hash('sha256', $name . ':' . $index), 0, 12),
                'name' => $name,
                'role' => $role,
                'bio' => $bio,
                'image' => $image,
                'social' => array_values(array_unique($socialLinks)),
                'visible' => true,
            ];
        }
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);
    return $peopleByGroup;
}