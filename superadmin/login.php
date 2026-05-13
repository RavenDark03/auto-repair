<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mechanix_urls.php';

header('Location: ' . mechanix_url_path('/login.php'), true, 302);
exit;
