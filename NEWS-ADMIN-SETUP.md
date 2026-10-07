# News Admin Setup

The deployment archive `HPV_Consortium_Web_Deployment_2026-10-07.zip` includes the active site pages, local CSS/JS/vendor assets, images, PHP endpoints, and public news/directory content. It excludes the local admin password config and admin account hashes; create production credentials separately and transfer the config securely.

The news editor requires PHP 7.4 or newer. Upload and extract the site on a PHP-enabled host, then:

1. Do not place `news-admin-config.php` or `admin-users-data.php` in the public deployment archive or source control. The first file contains the production bootstrap password hash; the second contains individual admin password hashes.
2. In PowerShell, from the project folder, run this block to choose a production admin password and create `news-admin-config.php`. The password is entered as a masked prompt and is not included in command history. Transfer that config file separately to the host over a secure channel:

    ```powershell
    $securePassword = Read-Host 'Choose a news admin password (12+ characters)' -AsSecureString
    if ($securePassword.Length -lt 12) { throw 'Use at least 12 characters.' }
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try {
      $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
      if ($phpCommand) {
         $php = $phpCommand.Source
      } else {
         $phpPackage = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Directory -Filter 'PHP.PHP.8.4*' -ErrorAction SilentlyContinue | Select-Object -First 1
         if (-not $phpPackage) { throw 'PHP was not found. Install PHP or open a new terminal and try again.' }
         $php = Join-Path $phpPackage.FullName 'php.exe'
      }
      if (-not (Test-Path $php)) { throw 'Could not find php.exe.' }

      $env:HPV_NEWS_SETUP_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
      $hash = & $php -r 'echo password_hash(getenv($argv[1]), PASSWORD_DEFAULT);' HPV_NEWS_SETUP_PASSWORD
      if ($LASTEXITCODE -ne 0 -or -not $hash) { throw 'PHP could not create the password hash.' }
      $config = "<?php`r`nreturn [`r`n    'password_hash' => '$hash',`r`n];`r`n"
      Set-Content -Path .\news-admin-config.php -Value $config -Encoding ASCII
    } finally {
      Remove-Item Env:HPV_NEWS_SETUP_PASSWORD -ErrorAction SilentlyContinue
      [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
      Remove-Variable securePassword, pointer, hash, phpCommand, phpPackage, php -ErrorAction SilentlyContinue
    }
   ```

3. Ensure the PHP process can write `news-content.php` and `site-content.php`, create files under `images/news/` and `images/people/`, and create `admin-users-data.php` in the site folder. The people-directory import requires PHP's DOM extension; photo uploads require `fileinfo`. Both credential files are ignored by `.gitignore`.
4. Open `admin.php`. The first sign-in asks for a username and the current setup password, then creates the first **Owner** account. After that, sign in with that username and password; the shared setup password is no longer accepted.
5. In the admin, open **Users** to add individual Admin or Owner accounts, reset their passwords, or disable access. Admins can manage News and People; only Owners can manage accounts. The account store is `admin-users-data.php`; back it up with the site's content files.

For local development, open PowerShell in the project folder and run `php -S 127.0.0.1:8000 -t .`, then visit `http://127.0.0.1:8000/admin.php`. Winget adds PHP to the user PATH; open a new terminal if `php` is not recognized. The PHP built-in server is for local development only, not production hosting. Use HTTPS on the production site so admin session cookies are secure.

New stories are private drafts until **Publish this story** is checked. Public stories appear on `news.html` and the three newest are shown on the homepage; each story opens as a full article. News cover photos and directory portraits can be JPG, PNG or WebP up to 5 MB. The host's `upload_max_filesize` must allow 5 MB and `post_max_size` must be slightly larger. The news page only shows stories added through the admin; it does not use gallery photos.

The local workspace uses PHP 8.4.25 for development. Verify the deployed PHP host separately before publishing; the built-in server is not a production server. The HTML news page remains readable when the PHP feed is unavailable.