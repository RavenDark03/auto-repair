<?php
$password = $_POST['password'] ?? '';
$existingHash = $_POST['existing_hash'] ?? '';
$generatedHash = '';
$verifyMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($password !== '') {
        $generatedHash = password_hash($password, PASSWORD_DEFAULT);
    }

    if ($password !== '' && $existingHash !== '') {
        $verifyMessage = password_verify($password, $existingHash)
            ? 'Password matches this hash.'
            : 'Password does not match this hash.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Hash Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            line-height: 1.5;
        }
        label {
            display: block;
            margin-top: 1rem;
            font-weight: bold;
        }
        input, textarea, button {
            width: 100%;
            max-width: 720px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            box-sizing: border-box;
        }
        textarea {
            min-height: 110px;
            resize: vertical;
        }
        button {
            max-width: 220px;
            cursor: pointer;
        }
        .panel {
            margin-top: 1.5rem;
            padding: 1rem;
            border: 1px solid #ccc;
            max-width: 720px;
        }
        code {
            word-break: break-all;
        }
    </style>
</head>
<body>
    <h1>Password Hash Tool</h1>
    <p>Use this page to generate a MySQL-ready password hash and optionally test an existing hash.</p>

    <form method="POST">
        <label for="password">Plain Password</label>
        <input type="text" id="password" name="password" value="<?= htmlspecialchars($password, ENT_QUOTES, 'UTF-8') ?>">

        <label for="existing_hash">Existing Hash to Verify</label>
        <textarea id="existing_hash" name="existing_hash"><?= htmlspecialchars($existingHash, ENT_QUOTES, 'UTF-8') ?></textarea>

        <button type="submit">Generate / Verify</button>
    </form>

    <?php if ($generatedHash !== ''): ?>
        <div class="panel">
            <strong>Generated Hash</strong>
            <p><code><?= htmlspecialchars($generatedHash, ENT_QUOTES, 'UTF-8') ?></code></p>
        </div>
    <?php endif; ?>

    <?php if ($verifyMessage !== null): ?>
        <div class="panel">
            <strong>Verification Result</strong>
            <p><?= htmlspecialchars($verifyMessage, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>
</body>
</html>
