<?php
require_once __DIR__ . '/news-store.php';
require_once __DIR__ . '/site-content-store.php';
require_once __DIR__ . '/admin-users-store.php';

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
header('Cache-Control: no-store, max-age=0');

$configPath = __DIR__ . '/news-admin-config.php';
$adminConfig = is_file($configPath) ? require $configPath : [];
$passwordHash = is_array($adminConfig) ? ($adminConfig['password_hash'] ?? '') : '';
$adminUsers = admin_users_read();
$legacyPasswordReady = is_string($passwordHash) && $passwordHash !== '' && password_get_info($passwordHash)['algoName'] !== 'unknown';
$bootstrapAdmin = !$adminUsers && $legacyPasswordReady;
$configured = (bool) $adminUsers || $bootstrapAdmin;
$errorMessage = '';
$statusMessage = '';
$peopleErrorMessage = '';

function news_admin_redirect()
{
    header('Location: admin.php');
    exit;
}

function news_admin_remove_image($path)
{
    if (!is_string($path) || !preg_match('#^images/news/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
        return;
    }

    $absolutePath = __DIR__ . '/' . $path;
    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }
}

function news_admin_remove_person_image($path)
{
  if (!is_string($path) || !preg_match('#^images/people/[a-f0-9]{32}\.(jpg|png|webp)$#', $path)) {
    return;
  }

  $absolutePath = __DIR__ . '/' . $path;
  if (is_file($absolutePath)) {
    unlink($absolutePath);
  }
}

function news_admin_store_person_image($upload)
{
  if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ['path' => '', 'error' => ''];
  }
  if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > 5 * 1024 * 1024 || !class_exists('finfo')) {
    return ['path' => '', 'error' => 'Choose a JPG, PNG or WebP photo no larger than 5 MB.'];
  }

  $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  $fileInfo = new finfo(FILEINFO_MIME_TYPE);
  $mimeType = $fileInfo->file($upload['tmp_name']);
  if (!isset($allowedTypes[$mimeType]) || !getimagesize($upload['tmp_name'])) {
    return ['path' => '', 'error' => 'The uploaded file is not a supported image.'];
  }

  $uploadDirectory = __DIR__ . '/images/people';
  if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
    return ['path' => '', 'error' => 'The photo folder could not be created.'];
  }

  $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
  if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . '/' . $filename)) {
    return ['path' => '', 'error' => 'The photo could not be saved. Check folder permissions.'];
  }
  return ['path' => 'images/people/' . $filename, 'error' => ''];
}

$authenticatedUser = null;
$sessionUserId = $_SESSION['site_admin_user_id'] ?? '';
if (is_string($sessionUserId)) {
  foreach ($adminUsers as $adminUser) {
    if (($adminUser['id'] ?? '') === $sessionUserId && !empty($adminUser['active'])) {
      $authenticatedUser = $adminUser;
      break;
    }
  }
}
if (!$authenticatedUser) {
  unset($_SESSION['site_admin_user_id'], $_SESSION['site_admin_role'], $_SESSION['site_admin_username']);
}

if (!$configured) {
    http_response_code(503);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
  $submittedUsername = isset($_POST['username']) && is_string($_POST['username']) ? strtolower(trim($_POST['username'])) : '';
  $submittedPassword = $_POST['password'] ?? '';
  $usernameValid = preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $submittedUsername) === 1;
  $matchedUser = null;

  if (is_string($submittedPassword) && $usernameValid && $bootstrapAdmin && password_verify($submittedPassword, $passwordHash)) {
    $firstAdmin = [
      'id' => bin2hex(random_bytes(16)),
      'username' => $submittedUsername,
      'password_hash' => password_hash($submittedPassword, PASSWORD_DEFAULT),
      'role' => 'owner',
      'active' => true,
      'created' => date('Y-m-d'),
    ];
    if (admin_users_modify(function ($users) use ($firstAdmin) {
      if ($users) {
        throw new RuntimeException('The owner account has already been created.');
      }
      return [$firstAdmin];
    })) {
      $matchedUser = $firstAdmin;
    }
  } elseif (is_string($submittedPassword) && $usernameValid) {
    foreach ($adminUsers as $adminUser) {
      if (!empty($adminUser['active']) && strtolower($adminUser['username'] ?? '') === $submittedUsername && password_verify($submittedPassword, $adminUser['password_hash'] ?? '')) {
        $matchedUser = $adminUser;
        break;
      }
    }
  }

  if ($matchedUser) {
        session_regenerate_id(true);
    $_SESSION['site_admin_user_id'] = $matchedUser['id'];
    $_SESSION['site_admin_role'] = $matchedUser['role'];
    $_SESSION['site_admin_username'] = $matchedUser['username'];
        $_SESSION['news_admin_csrf'] = bin2hex(random_bytes(32));
        news_admin_redirect();
  } else {
    $errorMessage = 'Username or password was not accepted.';
    }
} elseif ($authenticatedUser && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf'] ?? '';
    $sessionToken = $_SESSION['news_admin_csrf'] ?? '';
    if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        $errorMessage = 'This form has expired. Reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            news_admin_redirect();
        }

        if (in_array($action, ['user-create', 'user-reset', 'user-toggle'], true)) {
          if (($authenticatedUser['role'] ?? '') !== 'owner') {
            $errorMessage = 'Only a site owner can manage admin accounts.';
          } elseif ($action === 'user-create') {
            $username = isset($_POST['new_username']) && is_string($_POST['new_username']) ? strtolower(trim($_POST['new_username'])) : '';
            $password = $_POST['new_password'] ?? '';
            $role = isset($_POST['new_role']) && is_string($_POST['new_role']) ? $_POST['new_role'] : 'admin';
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $username) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72 || !in_array($role, ['admin', 'owner'], true)) {
              $errorMessage = 'Use a valid username and a password between 12 and 72 characters.';
            } else {
              $newUser = [
                'id' => bin2hex(random_bytes(16)),
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'active' => true,
                'created' => date('Y-m-d'),
              ];
              $saved = admin_users_modify(function ($users) use ($newUser) {
                foreach ($users as $user) {
                  if (strtolower($user['username'] ?? '') === $newUser['username']) {
                    throw new RuntimeException('That username is already in use.');
                  }
                }
                $users[] = $newUser;
                return $users;
              });
              if ($saved) {
                        header('Location: admin.php?section=users&user=created');
                exit;
              }
              $errorMessage = 'The account could not be created. Check for a duplicate username and confirm the account store is writable.';
            }
          } elseif ($action === 'user-reset') {
            $targetId = isset($_POST['user_id']) && is_string($_POST['user_id']) ? $_POST['user_id'] : '';
            $password = $_POST['reset_password'] ?? '';
            if ($targetId === '' || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
              $errorMessage = 'Set a password between 12 and 72 characters.';
            } else {
              $newHash = password_hash($password, PASSWORD_DEFAULT);
              $saved = admin_users_modify(function ($users) use ($targetId, $newHash) {
                foreach ($users as $index => $user) {
                  if (($user['id'] ?? '') === $targetId) {
                    $users[$index]['password_hash'] = $newHash;
                    return $users;
                  }
                }
                throw new RuntimeException('Admin account not found.');
              });
              if ($saved) {
                        header('Location: admin.php?section=users&user=password-reset');
                exit;
              }
              $errorMessage = 'The password could not be reset.';
            }
          } else {
            $targetId = isset($_POST['user_id']) && is_string($_POST['user_id']) ? $_POST['user_id'] : '';
            $desiredActive = isset($_POST['active']) && $_POST['active'] === '1';
            $targetUser = null;
            foreach ($adminUsers as $adminUser) {
              if (($adminUser['id'] ?? '') === $targetId) {
                $targetUser = $adminUser;
                break;
              }
            }

            if (!$targetUser) {
              $errorMessage = 'The admin account could not be found.';
            } elseif (!$desiredActive && $targetId === ($authenticatedUser['id'] ?? '')) {
              $errorMessage = 'You cannot disable the account you are currently using.';
            } elseif (!$desiredActive && ($targetUser['role'] ?? '') === 'owner') {
              $activeOwners = count(array_filter($adminUsers, function ($user) {
                return ($user['role'] ?? '') === 'owner' && !empty($user['active']);
              }));
              if ($activeOwners <= 1) {
                $errorMessage = 'At least one active site owner must remain.';
              }
            }

            if ($errorMessage === '') {
              $saved = admin_users_modify(function ($users) use ($targetId, $desiredActive) {
                foreach ($users as $index => $user) {
                  if (($user['id'] ?? '') === $targetId) {
                    $users[$index]['active'] = $desiredActive;
                    return $users;
                  }
                }
                throw new RuntimeException('Admin account not found.');
              });
              if ($saved) {
                        header('Location: admin.php?section=users&user=access-updated');
                exit;
              }
              $errorMessage = 'Account access could not be updated.';
            }
          }
        }

          if ($action === 'people-import') {
            $importedPeople = site_content_import_legacy_people();
            $saved = site_content_modify(function ($content) use ($importedPeople) {
              if (!empty($content['imported'])) {
                throw new RuntimeException('Existing directory data has already been imported.');
              }
              $content['people'] = $importedPeople;
              $content['managed'] = ['investigators' => true, 'staff' => true, 'board' => true];
              $content['imported'] = true;
              return $content;
            });
            if ($saved) {
              header('Location: admin.php?section=people&group=staff&imported=1');
              exit;
            }
            $peopleErrorMessage = 'The existing directories could not be imported. Check that site-content.php is writable.';
          } elseif ($action === 'person-save') {
            $group = isset($_POST['group']) && is_string($_POST['group']) ? $_POST['group'] : '';
            $personId = isset($_POST['person_id']) && is_string($_POST['person_id']) ? trim($_POST['person_id']) : '';
            $name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
            $role = isset($_POST['role']) && is_string($_POST['role']) ? trim($_POST['role']) : '';
            $bio = isset($_POST['bio']) && is_string($_POST['bio']) ? trim($_POST['bio']) : '';
            $socialText = isset($_POST['social']) && is_string($_POST['social']) ? trim($_POST['social']) : '';
            $validGroups = ['investigators', 'staff', 'board'];
            $socialLinks = preg_split('/\r?\n/', $socialText, -1, PREG_SPLIT_NO_EMPTY);
            $socialLinks = array_map('trim', $socialLinks ?: []);

            if (!in_array($group, $validGroups, true) || $name === '' || strlen($name) > 560 || strlen($role) > 800 || strlen($bio) > 30000 || count($socialLinks) > 5) {
              $peopleErrorMessage = 'Check the required name and field length limits, then try saving again.';
            } else {
              $invalidSocialLink = false;
              foreach ($socialLinks as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?: ''), ['http', 'https'], true)) {
                  $invalidSocialLink = true;
                  break;
                }
              }
              $imageResult = news_admin_store_person_image($_FILES['person_image'] ?? null);
              if ($invalidSocialLink) {
                news_admin_remove_person_image($imageResult['path']);
                $peopleErrorMessage = 'Enter valid http or https profile links, one per line.';
              } elseif ($imageResult['error'] !== '') {
                $peopleErrorMessage = $imageResult['error'];
              } else {
                $content = site_content_read();
                $existingPerson = null;
                foreach ($content['people'][$group] ?? [] as $person) {
                  if (($person['id'] ?? '') === $personId && $personId !== '') {
                    $existingPerson = $person;
                    break;
                  }
                }

                if ($personId !== '' && !$existingPerson) {
                  news_admin_remove_person_image($imageResult['path']);
                  $peopleErrorMessage = 'That profile could not be found. Refresh and try again.';
                } elseif (empty($content['managed'][$group])) {
                  news_admin_remove_person_image($imageResult['path']);
                  $peopleErrorMessage = 'Import the current directories before editing profiles.';
                } elseif ($personId === '' && $imageResult['path'] === '') {
                  $peopleErrorMessage = 'Choose a portrait for the new profile.';
                } else {
                  $updatedPerson = [
                    'id' => $existingPerson['id'] ?? ($group . '-' . bin2hex(random_bytes(8))),
                    'name' => $name,
                    'role' => $role,
                    'bio' => $bio,
                    'image' => $imageResult['path'] !== '' ? $imageResult['path'] : ($existingPerson['image'] ?? ''),
                    'social' => $socialLinks,
                    'visible' => isset($_POST['visible']),
                  ];
                  $saved = site_content_modify(function ($siteContent) use ($group, $personId, $updatedPerson) {
                    $siteContent['people'][$group] = $siteContent['people'][$group] ?? [];
                    if ($personId === '') {
                      $siteContent['people'][$group][] = $updatedPerson;
                      return $siteContent;
                    }
                    foreach ($siteContent['people'][$group] as $index => $person) {
                      if (($person['id'] ?? '') === $personId) {
                        $siteContent['people'][$group][$index] = $updatedPerson;
                        return $siteContent;
                      }
                    }
                    throw new RuntimeException('Profile not found.');
                  });

                  if ($saved) {
                    if ($imageResult['path'] !== '' && $existingPerson) {
                      news_admin_remove_person_image($existingPerson['image'] ?? '');
                    }
                    header('Location: admin.php?section=people&group=' . rawurlencode($group) . '&saved=1');
                    exit;
                  }
                  news_admin_remove_person_image($imageResult['path']);
                  $peopleErrorMessage = 'The profile could not be saved. Check that site-content.php is writable.';
                }
              }
            }
          } elseif ($action === 'person-delete') {
            $group = isset($_POST['group']) && is_string($_POST['group']) ? $_POST['group'] : '';
            $personId = isset($_POST['person_id']) && is_string($_POST['person_id']) ? $_POST['person_id'] : '';
            $validGroups = ['investigators', 'staff', 'board'];
            $content = site_content_read();
            $deletedPerson = null;
            if (in_array($group, $validGroups, true)) {
              foreach ($content['people'][$group] ?? [] as $person) {
                if (($person['id'] ?? '') === $personId) {
                  $deletedPerson = $person;
                  break;
                }
              }
            }
            if ($deletedPerson && site_content_modify(function ($siteContent) use ($group, $personId) {
              $siteContent['people'][$group] = array_values(array_filter($siteContent['people'][$group] ?? [], function ($person) use ($personId) {
                return ($person['id'] ?? '') !== $personId;
              }));
              return $siteContent;
            })) {
              news_admin_remove_person_image($deletedPerson['image'] ?? '');
              header('Location: admin.php?section=people&group=' . rawurlencode($group) . '&deleted=1');
              exit;
            }
            $peopleErrorMessage = 'The profile could not be deleted.';
          }

        if ($action === 'delete') {
            $slug = (string) ($_POST['slug'] ?? '');
            $posts = news_store_read();
            $deletedPost = null;
            foreach ($posts as $post) {
                if (($post['slug'] ?? '') === $slug) {
                    $deletedPost = $post;
                    break;
                }
            }

            if ($deletedPost && news_store_modify(function ($currentPosts) use ($slug) {
                return array_values(array_filter($currentPosts, function ($post) use ($slug) {
                    return ($post['slug'] ?? '') !== $slug;
                }));
            })) {
                news_admin_remove_image($deletedPost['image'] ?? '');
                news_admin_redirect();
            }
            $errorMessage = 'The story could not be deleted.';
        } elseif ($action === 'save') {
            $title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
            $category = isset($_POST['category']) && is_string($_POST['category']) ? trim($_POST['category']) : '';
            $date = isset($_POST['date']) && is_string($_POST['date']) ? trim($_POST['date']) : '';
            $author = isset($_POST['author']) && is_string($_POST['author']) ? trim($_POST['author']) : '';
            $excerpt = isset($_POST['excerpt']) && is_string($_POST['excerpt']) ? trim($_POST['excerpt']) : '';
            $body = isset($_POST['body']) && is_string($_POST['body']) ? trim($_POST['body']) : '';
            $videoUrl = isset($_POST['video_url']) && is_string($_POST['video_url']) ? trim($_POST['video_url']) : '';
            $videoId = $videoUrl === '' ? '' : news_youtube_id($videoUrl);
            $editSlug = isset($_POST['edit_slug']) && is_string($_POST['edit_slug']) ? trim($_POST['edit_slug']) : '';
            $validCategories = ['Fieldwork', 'Outreach', 'Advocacy', 'Research', 'Consortium', 'Events', 'Other'];
            $dateValue = DateTime::createFromFormat('!Y-m-d', $date);

            if ($title === '' || strlen($title) > 560 || $excerpt === '' || strlen($excerpt) > 1440 || $body === '' || strlen($body) > 100000 || strlen($author) > 400 || ($videoUrl !== '' && $videoId === '') || !in_array($category, $validCategories, true) || !$dateValue || $dateValue->format('Y-m-d') !== $date) {
              $errorMessage = 'Check the required fields and limits. Video links must be valid YouTube URLs.';
            } else {
                $existingPost = null;
                foreach (news_store_read() as $post) {
                    if (($post['slug'] ?? '') === $editSlug && $editSlug !== '') {
                        $existingPost = $post;
                        break;
                    }
                }

                if ($editSlug !== '' && !$existingPost) {
                    $errorMessage = 'That story could not be found. Refresh and try again.';
                } else {
                    $newImage = '';
                    $upload = $_FILES['image'] ?? null;
                    if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                        $allowedTypes = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/webp' => 'webp',
                        ];
                        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $upload['error'] === UPLOAD_ERR_OK ? $fileInfo->file($upload['tmp_name']) : false;
                        if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024 || !isset($allowedTypes[$mimeType])) {
                            $errorMessage = 'Choose a JPG, PNG or WebP image no larger than 5 MB.';
                        } else {
                            $uploadDirectory = __DIR__ . '/images/news';
                            if (!is_dir($uploadDirectory)) {
                                mkdir($uploadDirectory, 0755, true);
                            }
                            $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
                            if (move_uploaded_file($upload['tmp_name'], $uploadDirectory . '/' . $filename)) {
                                $newImage = 'images/news/' . $filename;
                            } else {
                                $errorMessage = 'The image could not be saved. Check folder permissions.';
                            }
                        }
                    }

                    if ($errorMessage === '' && !$existingPost && $newImage === '') {
                        $errorMessage = 'Choose a cover photo for the new story.';
                    }

                    if ($errorMessage === '') {
                        $slug = $existingPost['slug'] ?? news_slug($title);
                        if (!$existingPost) {
                            foreach (news_store_read() as $post) {
                                if (($post['slug'] ?? '') === $slug) {
                                    $slug .= '-' . bin2hex(random_bytes(3));
                                    break;
                                }
                            }
                        }

                        $updatedPost = [
                            'slug' => $slug,
                            'title' => $title,
                            'category' => $category,
                            'date' => $date,
                            'author' => $author,
                            'excerpt' => $excerpt,
                            'body' => $body,
                            'image' => $newImage !== '' ? $newImage : ($existingPost['image'] ?? ''),
                            'videoId' => $videoId,
                            'published' => isset($_POST['published']),
                        ];
                        $saved = news_store_modify(function ($posts) use ($updatedPost, $editSlug) {
                            if ($editSlug === '') {
                                $posts[] = $updatedPost;
                                return $posts;
                            }
                            foreach ($posts as $index => $post) {
                                if (($post['slug'] ?? '') === $editSlug) {
                                    $posts[$index] = $updatedPost;
                                    return $posts;
                                }
                            }
                            throw new RuntimeException('Story not found.');
                        });

                        if ($saved) {
                            if ($newImage !== '' && $existingPost) {
                                news_admin_remove_image($existingPost['image'] ?? '');
                            }
                          header('Location: admin.php?saved=1');
                          exit;
                        }
                        if ($newImage !== '') {
                            news_admin_remove_image($newImage);
                        }
                        $errorMessage = 'The story could not be saved. Check that the site folder is writable.';
                    }
                }
            }
        }
    }
}

$authenticated = $configured && $authenticatedUser !== null;
$csrfToken = $authenticated ? ($_SESSION['news_admin_csrf'] ?? '') : '';
$activeSection = isset($_GET['section']) && is_string($_GET['section']) && in_array($_GET['section'], ['news', 'people', 'users'], true) ? $_GET['section'] : 'news';
if ($activeSection === 'users' && ($authenticatedUser['role'] ?? '') !== 'owner') {
  $activeSection = 'news';
}
$validPeopleGroups = ['investigators', 'staff', 'board'];
$selectedPeopleGroup = isset($_POST['group']) && is_string($_POST['group']) ? $_POST['group'] : (isset($_GET['group']) && is_string($_GET['group']) ? $_GET['group'] : 'staff');
if (!in_array($selectedPeopleGroup, $validPeopleGroups, true)) {
  $selectedPeopleGroup = 'staff';
}
$peopleContent = $authenticated ? site_content_read() : [];
$peopleByGroup = $peopleContent['people'] ?? [];
$peopleRecords = $peopleByGroup[$selectedPeopleGroup] ?? [];
$editingPerson = null;
$editPersonId = isset($_GET['edit_person']) && is_string($_GET['edit_person']) ? $_GET['edit_person'] : '';
if ($authenticated && $editPersonId !== '') {
  foreach ($peopleRecords as $person) {
    if (($person['id'] ?? '') === $editPersonId) {
      $editingPerson = $person;
      break;
    }
  }
}
$personFormValues = $editingPerson ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'person-save') {
  foreach (['name', 'role', 'bio', 'person_id'] as $field) {
    if (isset($_POST[$field]) && is_string($_POST[$field])) {
      $personFormValues[$field === 'person_id' ? 'id' : $field] = $_POST[$field];
    }
  }
  $personFormValues['social'] = isset($_POST['social']) && is_string($_POST['social']) ? preg_split('/\r?\n/', trim($_POST['social']), -1, PREG_SPLIT_NO_EMPTY) : [];
  $personFormValues['visible'] = isset($_POST['visible']);
}
  $userFormValues = [];
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'user-create') {
    $userFormValues['username'] = isset($_POST['new_username']) && is_string($_POST['new_username']) ? $_POST['new_username'] : '';
    $userFormValues['role'] = isset($_POST['new_role']) && is_string($_POST['new_role']) ? $_POST['new_role'] : 'admin';
  }
$allPosts = $authenticated ? news_store_read() : [];
usort($allPosts, function ($left, $right) {
    return strcmp($right['date'] ?? '', $left['date'] ?? '');
});
$editingPost = null;
if ($authenticated && isset($_GET['edit'])) {
    foreach ($allPosts as $post) {
        if (($post['slug'] ?? '') === $_GET['edit']) {
            $editingPost = $post;
            break;
        }
    }
}
$formValues = $editingPost ?? [];
if (!empty($editingPost['videoId'])) {
  $formValues['video_url'] = 'https://www.youtube.com/watch?v=' . $editingPost['videoId'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
  foreach (['title', 'category', 'date', 'author', 'excerpt', 'body', 'video_url'] as $field) {
    if (isset($_POST[$field]) && is_string($_POST[$field])) {
      $formValues[$field] = $_POST[$field];
    }
  }
  if (isset($_POST['edit_slug']) && is_string($_POST['edit_slug'])) {
    $formValues['slug'] = $_POST['edit_slug'];
  }
  $formValues['published'] = isset($_POST['published']);
}
if (isset($_GET['saved'])) {
  $statusMessage = $activeSection === 'people' ? 'Profile saved.' : 'Story saved.';
} elseif (isset($_GET['imported'])) {
  $statusMessage = 'Existing directory profiles imported. Review and edit them below.';
} elseif (isset($_GET['deleted'])) {
  $statusMessage = 'Profile deleted.';
} elseif (isset($_GET['user'])) {
  $statusMessage = [
    'created' => 'Admin account created.',
    'password-reset' => 'Admin password reset.',
    'access-updated' => 'Admin access updated.',
  ][$_GET['user']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>News Admin | HPV Consortium</title>
  <link href="assets/img/HPV consortium logo.png" rel="icon">
  <link href="assets/img/HPV consortium logo.png" rel="apple-touch-icon">
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&family=Marcellus&display=swap" rel="stylesheet">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="assets/css/main.css" rel="stylesheet">
  <link href="assets/css/news-admin.css" rel="stylesheet">
</head>
<body class="news-admin-page">
  <header id="header" class="header d-flex align-items-center position-relative">
    <div class="container-fluid container-xl position-relative d-flex align-items-center justify-content-between">
      <a href="index.html" class="logo d-flex align-items-center">
        <img src="assets/img/HPV consortium logo 2.png" alt="HPV Consortium" class="img-fluid">
        <h4 class="sitename"><strong>HPV Consortium</strong></h4>
      </a>
      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="index.html">Home</a></li>
          <li><a href="Project.html">Our Projects</a></li>
          <li><a href="Services.html">Our Services</a></li>
          <li class="dropdown"><a href="#"><span>Our Team</span> <i class="bi bi-chevron-down toggle-dropdown"></i></a>
            <ul>
              <li><a href="team.html">Investigators</a></li>
              <li><a href="sab.html">Advisory Board</a></li>
              <li><a href="staff.html">Project Staff</a></li>
            </ul>
          </li>
          <li><a href="gallery.html">Gallery</a></li>
          <li><a href="news.html" class="active">News</a></li>
          <li class="dropdown"><a href="#"><span>Resources</span> <i class="bi bi-chevron-down toggle-dropdown"></i></a>
            <ul>
              <li><a href="presentations.html">Presentations</a></li>
              <li><a href="publications.html">Publications</a></li>
            </ul>
          </li>
          <li><a href="about.html">About Us</a></li>
          <li><a href="contact.html">Contact Us</a></li>
        </ul>
        <i class="mobile-nav-toggle d-xl-none bi bi-list"></i>
      </nav>
    </div>
  </header>

  <main class="main admin-main">
    <?php if (!$configured): ?>
      <section class="admin-notice">
        <p class="admin-eyebrow">SETUP REQUIRED</p>
        <h1>Configure news admin</h1>
        <p>Create <code>news-admin-config.php</code> from <code>news-admin-config.example.php</code> and set its <code>password_hash</code>. Generate a hash on a PHP-enabled machine with:</p>
        <pre><code>php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"</code></pre>
        <p>News admin will remain unavailable until a password hash is configured.</p>
      </section>
    <?php elseif (!$authenticated): ?>
      <div class="admin-auth-layout">
        <section class="admin-auth-intro" aria-labelledby="admin-auth-title">
          <p class="admin-auth-kicker"><span></span> HPV CONSORTIUM / ADMINISTRATION</p>
          <h2 id="admin-auth-title">Consortium<br>workspace</h2>
          <p>News, people, and site access in one place.</p>
          <a href="index.html"><i class="bi bi-arrow-left"></i> Return to the public site</a>
        </section>
        <section class="admin-login" aria-labelledby="admin-login-title">
          <p class="admin-eyebrow">SECURE SIGN IN</p>
          <h1 id="admin-login-title">Welcome back</h1>
          <?php if ($errorMessage !== ''): ?><p class="admin-alert" role="alert"><?= news_escape($errorMessage) ?></p><?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="login">
            <?php if ($bootstrapAdmin): ?><p class="admin-login-note">First sign-in creates your owner account using the current setup password.</p><?php endif; ?>
            <label for="admin-username">Username</label>
            <input id="admin-username" type="text" name="username" autocomplete="username" minlength="3" maxlength="40" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}" required>
            <label for="admin-password">Password</label>
            <input id="admin-password" type="password" name="password" autocomplete="current-password" required>
            <button type="submit" class="admin-primary">Sign in <i class="bi bi-arrow-right"></i></button>
          </form>
        </section>
      </div>
    <?php else: ?>
      <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Admin panes">
          <p class="admin-sidebar-label">WEBSITE ADMIN</p>
          <nav class="admin-sidebar-nav" aria-label="Content sections">
            <a href="admin.php?section=news" <?= $activeSection === 'news' ? 'aria-current="page"' : '' ?>><i class="bi bi-newspaper"></i> News</a>
            <a href="admin.php?section=people" <?= $activeSection === 'people' ? 'aria-current="page"' : '' ?>><i class="bi bi-people"></i> People</a>
            <?php if (($authenticatedUser['role'] ?? '') === 'owner'): ?><a href="admin.php?section=users" <?= $activeSection === 'users' ? 'aria-current="page"' : '' ?>><i class="bi bi-person-gear"></i> Users</a><?php endif; ?>
          </nav>
          <div class="admin-sidebar-account">
            <small>Signed in as</small>
            <strong><?= news_escape($authenticatedUser['username']) ?></strong>
            <small><?= ($authenticatedUser['role'] ?? '') === 'owner' ? 'Owner' : 'Admin' ?></small>
            <form method="post" class="admin-logout">
              <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
              <button type="submit" name="action" value="logout"><i class="bi bi-box-arrow-right"></i> Sign out</button>
            </form>
          </div>
        </aside>
        <div class="admin-workspace">
      <?php if ($activeSection === 'users'): ?>
        <div class="admin-heading">
          <div>
            <p class="admin-eyebrow">SITE ACCESS</p>
            <h1>Admin accounts</h1>
          </div>
          <span class="admin-account-count"><?= count($adminUsers) ?> accounts</span>
        </div>
        <?php if ($errorMessage !== ''): ?><p class="admin-alert" role="alert"><?= news_escape($errorMessage) ?></p><?php endif; ?>
        <?php if ($statusMessage !== ''): ?><p class="admin-success" role="status"><?= news_escape($statusMessage) ?></p><?php endif; ?>
        <div class="admin-layout admin-users-layout">
          <section class="admin-editor" aria-labelledby="new-admin-title">
            <h2 id="new-admin-title">Add an admin</h2>
            <form method="post" class="admin-story-form">
              <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
              <input type="hidden" name="action" value="user-create">
              <label for="new-admin-username">Username</label>
              <input id="new-admin-username" name="new_username" minlength="3" maxlength="40" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}" value="<?= news_escape($userFormValues['username'] ?? '') ?>" required>
              <label for="new-admin-password">Temporary password <span>12 to 72 characters</span></label>
              <input id="new-admin-password" type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required>
              <label for="new-admin-role">Access level</label>
              <select id="new-admin-role" name="new_role">
                <option value="admin" <?= ($userFormValues['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>Admin: manage news and people</option>
                <option value="owner" <?= ($userFormValues['role'] ?? '') === 'owner' ? 'selected' : '' ?>>Owner: manage content and admin accounts</option>
              </select>
              <div class="admin-form-actions"><button type="submit" class="admin-primary">Create account <i class="bi bi-arrow-right"></i></button></div>
            </form>
          </section>
          <section class="admin-posts" aria-labelledby="admin-account-list-title">
            <div class="admin-posts-heading"><h2 id="admin-account-list-title">Accounts</h2><span><?= count($adminUsers) ?></span></div>
            <ul class="admin-post-list">
              <?php foreach ($adminUsers as $adminUser): ?>
                <li class="admin-user-item">
                  <div class="admin-user-heading">
                    <div>
                      <p><?= ($adminUser['role'] ?? 'admin') === 'owner' ? 'Owner' : 'Admin' ?> | <?= !empty($adminUser['active']) ? 'Active' : 'Disabled' ?><?= ($adminUser['id'] ?? '') === ($authenticatedUser['id'] ?? '') ? ' | You' : '' ?></p>
                      <h3><?= news_escape($adminUser['username'] ?? '') ?></h3>
                    </div>
                    <small>Added <?= news_escape($adminUser['created'] ?? '') ?></small>
                  </div>
                  <form method="post" class="admin-user-reset">
                    <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
                    <input type="hidden" name="user_id" value="<?= news_escape($adminUser['id'] ?? '') ?>">
                    <label for="reset-<?= news_escape($adminUser['id'] ?? '') ?>">Set a new password</label>
                    <div class="admin-user-reset-row">
                      <input id="reset-<?= news_escape($adminUser['id'] ?? '') ?>" type="password" name="reset_password" minlength="12" maxlength="72" autocomplete="new-password" required>
                      <button type="submit" name="action" value="user-reset" class="admin-secondary">Reset</button>
                    </div>
                  </form>
                  <form method="post" class="admin-user-access">
                    <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
                    <input type="hidden" name="user_id" value="<?= news_escape($adminUser['id'] ?? '') ?>">
                    <input type="hidden" name="active" value="<?= !empty($adminUser['active']) ? '0' : '1' ?>">
                    <button type="submit" name="action" value="user-toggle" <?= ($adminUser['id'] ?? '') === ($authenticatedUser['id'] ?? '') && !empty($adminUser['active']) ? 'disabled' : '' ?>><?= !empty($adminUser['active']) ? 'Disable account' : 'Enable account' ?></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        </div>
      <?php elseif ($activeSection === 'people'): ?>
        <?php
          $peopleGroupLabels = ['staff' => 'Project staff', 'investigators' => 'Investigators', 'board' => 'Advisory board'];
          $groupManaged = !empty($peopleContent['managed'][$selectedPeopleGroup]);
        ?>
        <div class="admin-heading">
          <div>
            <p class="admin-eyebrow">WEBSITE DIRECTORY</p>
            <h1>Manage people</h1>
          </div>
          <a href="<?= $selectedPeopleGroup === 'staff' ? 'staff.html' : ($selectedPeopleGroup === 'board' ? 'sab.html' : 'team.html') ?>" class="admin-public-link"><i class="bi bi-box-arrow-up-right"></i> View directory</a>
        </div>
        <?php if ($peopleErrorMessage !== ''): ?><p class="admin-alert" role="alert"><?= news_escape($peopleErrorMessage) ?></p><?php endif; ?>
        <?php if ($statusMessage !== ''): ?><p class="admin-success" role="status"><?= news_escape($statusMessage) ?></p><?php endif; ?>

        <nav class="admin-directory-nav" aria-label="People directories">
          <?php foreach ($peopleGroupLabels as $groupKey => $groupLabel): ?>
            <a href="admin.php?section=people&amp;group=<?= rawurlencode($groupKey) ?>" <?= $selectedPeopleGroup === $groupKey ? 'aria-current="page"' : '' ?>><?= news_escape($groupLabel) ?></a>
          <?php endforeach; ?>
        </nav>

        <?php if (empty($peopleContent['imported'])): ?>
          <section class="admin-import-notice">
            <div>
              <p class="admin-eyebrow">ONE-TIME SETUP</p>
              <h2>Bring the current directories into the editor</h2>
              <p>This imports the current staff, investigator, and advisory-board profiles, including their portraits and available biographies. It does not change the public pages until the import succeeds.</p>
            </div>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
              <button type="submit" name="action" value="people-import" class="admin-primary">Import current profiles <i class="bi bi-arrow-right"></i></button>
            </form>
          </section>
        <?php else: ?>
          <div class="admin-heading admin-people-heading">
            <div>
              <p class="admin-eyebrow"><?= news_escape(strtoupper($peopleGroupLabels[$selectedPeopleGroup])) ?></p>
              <h2><?= count($peopleRecords) ?> profiles</h2>
            </div>
            <a class="admin-primary admin-add-person" href="admin.php?section=people&amp;group=<?= rawurlencode($selectedPeopleGroup) ?>"> <i class="bi bi-plus-lg"></i> Add profile</a>
          </div>
          <div class="admin-layout admin-people-layout">
            <section class="admin-editor" aria-labelledby="person-form-title">
              <h2 id="person-form-title"><?= $editingPerson ? 'Edit profile' : 'New profile' ?></h2>
              <form method="post" enctype="multipart/form-data" class="admin-story-form">
                <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
                <input type="hidden" name="action" value="person-save">
                <input type="hidden" name="group" value="<?= news_escape($selectedPeopleGroup) ?>">
                <input type="hidden" name="person_id" value="<?= news_escape($personFormValues['id'] ?? '') ?>">
                <label for="person-name">Name</label>
                <input id="person-name" name="name" maxlength="140" value="<?= news_escape($personFormValues['name'] ?? '') ?>" required>
                <label for="person-role">Role or title</label>
                <input id="person-role" name="role" maxlength="200" value="<?= news_escape($personFormValues['role'] ?? '') ?>">
                <label for="person-bio">Biography</label>
                <textarea id="person-bio" name="bio" rows="7" maxlength="30000"><?= news_escape($personFormValues['bio'] ?? '') ?></textarea>
                <label for="person-image">Portrait <span><?= $editingPerson ? 'Optional when keeping the current portrait' : 'JPG, PNG or WebP, up to 5 MB' ?></span></label>
                <input id="person-image" type="file" name="person_image" accept="image/jpeg,image/png,image/webp" <?= $editingPerson ? '' : 'required' ?>>
                <?php if ($editingPerson): ?><img class="admin-current-image" src="<?= news_escape($editingPerson['image'] ?? '') ?>" alt="Current portrait"><?php endif; ?>
                <label for="person-social">Profile links <span>Optional, one URL per line</span></label>
                <textarea id="person-social" name="social" rows="2"><?= news_escape(implode("\n", $personFormValues['social'] ?? [])) ?></textarea>
                <label class="admin-publish-toggle"><input type="checkbox" name="visible" value="1" <?= !array_key_exists('visible', $personFormValues) || $personFormValues['visible'] ? 'checked' : '' ?>> Show on public directory</label>
                <div class="admin-form-actions">
                  <button type="submit" class="admin-primary"><?= $editingPerson ? 'Save profile' : 'Add profile' ?> <i class="bi bi-arrow-right"></i></button>
                  <?php if ($editingPerson): ?><a href="admin.php?section=people&amp;group=<?= rawurlencode($selectedPeopleGroup) ?>" class="admin-cancel">Cancel</a><?php endif; ?>
                </div>
              </form>
            </section>
            <section class="admin-posts" aria-labelledby="people-list-title">
              <div class="admin-posts-heading"><h2 id="people-list-title"><?= news_escape($peopleGroupLabels[$selectedPeopleGroup]) ?></h2><span><?= count($peopleRecords) ?></span></div>
              <?php if (!$peopleRecords): ?>
                <p class="admin-empty">No profiles in this directory yet.</p>
              <?php else: ?>
                <ul class="admin-post-list">
                  <?php foreach ($peopleRecords as $person): ?>
                    <li class="admin-post-item">
                      <img src="<?= news_escape($person['image'] ?? '') ?>" alt="" loading="lazy">
                      <div class="admin-post-copy">
                        <p><?= !empty($person['visible']) ? 'Visible' : 'Hidden' ?><?= !empty($person['role']) ? ' | ' . news_escape($person['role']) : '' ?></p>
                        <h3><?= news_escape($person['name'] ?? '') ?></h3>
                        <div class="admin-post-actions">
                          <a href="admin.php?section=people&amp;group=<?= rawurlencode($selectedPeopleGroup) ?>&amp;edit_person=<?= rawurlencode($person['id']) ?>"><i class="bi bi-pencil"></i> Edit</a>
                          <form method="post" onsubmit="return confirm('Delete this profile?')">
                            <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
                            <input type="hidden" name="group" value="<?= news_escape($selectedPeopleGroup) ?>">
                            <input type="hidden" name="person_id" value="<?= news_escape($person['id']) ?>">
                            <button type="submit" name="action" value="person-delete"><i class="bi bi-trash3"></i> Delete</button>
                          </form>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </section>
          </div>
        <?php endif; ?>
      <?php else: ?>
      <div class="admin-heading">
        <div>
          <p class="admin-eyebrow">NEWSROOM</p>
          <h1><?= $editingPost ? 'Edit story' : 'Add a story' ?></h1>
        </div>
        <a href="news.html" class="admin-public-link"><i class="bi bi-box-arrow-up-right"></i> View news page</a>
      </div>
      <?php if ($errorMessage !== ''): ?><p class="admin-alert" role="alert"><?= news_escape($errorMessage) ?></p><?php endif; ?>
      <?php if ($statusMessage !== ''): ?><p class="admin-success" role="status"><?= news_escape($statusMessage) ?></p><?php endif; ?>

      <div class="admin-layout">
        <section class="admin-editor" aria-labelledby="story-form-title">
          <h2 id="story-form-title">Story details</h2>
          <form method="post" enctype="multipart/form-data" class="admin-story-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
            <input type="hidden" name="edit_slug" value="<?= news_escape($formValues['slug'] ?? '') ?>">
            <label for="story-title">Title</label>
            <input id="story-title" name="title" maxlength="140" value="<?= news_escape($formValues['title'] ?? '') ?>" required>

            <div class="admin-form-row">
              <div>
                <label for="story-category">Category</label>
                <select id="story-category" name="category" required>
                  <?php foreach (['Fieldwork', 'Outreach', 'Advocacy', 'Research', 'Consortium', 'Events', 'Other'] as $category): ?>
                    <option value="<?= news_escape($category) ?>" <?= ($formValues['category'] ?? '') === $category ? 'selected' : '' ?>><?= news_escape($category) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="story-date">Activity date</label>
                <input id="story-date" type="date" name="date" value="<?= news_escape($formValues['date'] ?? date('Y-m-d')) ?>" required>
              </div>
            </div>

            <label for="story-author">Byline <span>Optional</span></label>
            <input id="story-author" name="author" maxlength="100" value="<?= news_escape($formValues['author'] ?? '') ?>">

            <label for="story-excerpt">Short summary</label>
            <textarea id="story-excerpt" name="excerpt" rows="3" maxlength="360" required><?= news_escape($formValues['excerpt'] ?? '') ?></textarea>

            <label for="story-body">Full story</label>
            <textarea id="story-body" name="body" rows="10" required><?= news_escape($formValues['body'] ?? '') ?></textarea>

            <label for="story-image">Cover photo <span><?= $editingPost ? 'Optional when keeping the current photo' : 'JPG, PNG or WebP, up to 5 MB' ?></span></label>
            <input id="story-image" type="file" name="image" accept="image/jpeg,image/png,image/webp" <?= $editingPost ? '' : 'required' ?>>
            <?php if ($editingPost): ?><img class="admin-current-image" src="<?= news_escape($editingPost['image']) ?>" alt="Current cover photo"><?php endif; ?>

            <label for="story-video">YouTube video link <span>Optional; paste a standard YouTube URL</span></label>
            <input id="story-video" type="url" name="video_url" maxlength="500" value="<?= news_escape($formValues['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/watch?v=...">

            <label class="admin-publish-toggle"><input type="checkbox" name="published" value="1" <?= !array_key_exists('published', $formValues) || $formValues['published'] ? 'checked' : '' ?>> Publish this story</label>
            <div class="admin-form-actions">
              <button type="submit" class="admin-primary"><?= $editingPost ? 'Save changes' : 'Add story' ?> <i class="bi bi-arrow-right"></i></button>
              <?php if ($editingPost): ?><a href="admin.php" class="admin-cancel">Cancel edit</a><?php endif; ?>
            </div>
          </form>
        </section>

        <section class="admin-posts" aria-labelledby="stories-title">
          <div class="admin-posts-heading"><h2 id="stories-title">Stories</h2><span><?= count($allPosts) ?></span></div>
          <?php if (!$allPosts): ?>
            <p class="admin-empty">No stories have been added yet.</p>
          <?php else: ?>
            <ul class="admin-post-list">
              <?php foreach ($allPosts as $post): ?>
                <li class="admin-post-item">
                  <img src="<?= news_escape($post['image'] ?? '') ?>" alt="" loading="lazy">
                  <div class="admin-post-copy">
                    <p><?= news_escape($post['category'] ?? '') ?> | <?= news_escape($post['date'] ?? '') ?> | <?= !empty($post['published']) ? 'Published' : 'Draft' ?></p>
                    <h3><?= news_escape($post['title'] ?? '') ?></h3>
                    <div class="admin-post-actions">
                      <a href="admin.php?edit=<?= rawurlencode($post['slug']) ?>"><i class="bi bi-pencil"></i> Edit</a>
                      <form method="post" onsubmit="return confirm('Delete this story?')">
                        <input type="hidden" name="csrf" value="<?= news_escape($csrfToken) ?>">
                        <input type="hidden" name="slug" value="<?= news_escape($post['slug']) ?>">
                        <button type="submit" name="action" value="delete"><i class="bi bi-trash3"></i> Delete</button>
                      </form>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>
      <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
  <footer id="footer" class="footer dark-background">
    <div class="footer-top">
      <div class="container">
        <div class="row gy-4">
          <div class="col-lg-5 col-md-6 footer-about">
            <a href="index.html" class="logo d-flex align-items-center"><span class="sitename">HPV Consortium</span></a>
            <div class="footer-contact pt-3">
              <p>Research Office, Infectious Disease Institute</p>
              <p>College of Medicine, University of Ibadan</p>
              <p class="mt-3"><strong>Phone:</strong> <span>+234 704 700 0531 / +234 704 700 0532</span></p>
              <p><strong>Email:</strong> <a href="mailto:hpvconsortiumsocial@gmail.com">hpvconsortiumsocial@gmail.com</a></p>
            </div>
          </div>
          <div class="col-lg-3 col-md-6 footer-links">
            <h4>Explore</h4>
            <ul>
              <li><a href="about.html">About us</a></li>
              <li><a href="Project.html">Our projects</a></li>
              <li><a href="gallery.html">Gallery</a></li>
              <li><a href="news.html">News &amp; stories</a></li>
            </ul>
          </div>
          <div class="col-lg-4 col-md-6 footer-links">
            <h4>Resources</h4>
            <ul>
              <li><a href="presentations.html">Presentations</a></li>
              <li><a href="publications.html">Publications</a></li>
              <li><a href="Services.html">Our services</a></li>
              <li><a href="contact.html">Contact the Consortium</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <div class="copyright text-center"><div class="container">© HPV Consortium. All Rights Reserved.</div></div>
  </footer>
  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center" aria-label="Scroll to top"><i class="bi bi-arrow-up-short"></i></a>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/aos/aos.js"></script>
  <script src="assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="assets/js/main.js"></script>
  <script>AOS.init();</script>
</body>
</html>