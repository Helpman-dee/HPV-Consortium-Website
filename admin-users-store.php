<?php

const ADMIN_USERS_PATH = __DIR__ . '/admin-users-data.php';
const ADMIN_USERS_PREFIX = '<?php http_response_code(404); exit; ?>';

function admin_users_decode($contents)
{
    if (!is_string($contents) || strpos($contents, ADMIN_USERS_PREFIX) !== 0) {
        return [];
    }

    $lineEnding = strpos($contents, "\n");
    if ($lineEnding === false) {
        return [];
    }

    $users = json_decode(substr($contents, $lineEnding + 1), true);
    return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
}

function admin_users_read()
{
    $handle = @fopen(ADMIN_USERS_PATH, 'rb');
    if (!$handle) {
        return [];
    }

    flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return admin_users_decode($contents);
}

function admin_users_modify($modifier)
{
    $handle = @fopen(ADMIN_USERS_PATH, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) {
            fclose($handle);
        }
        return false;
    }

    rewind($handle);
    $users = admin_users_decode(stream_get_contents($handle));

    try {
        $updatedUsers = $modifier($users);
        $encoded = json_encode(array_values($updatedUsers), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_array($updatedUsers) || $encoded === false) {
            throw new RuntimeException('Could not encode admin accounts.');
        }

        $payload = ADMIN_USERS_PREFIX . "\n" . $encoded;
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