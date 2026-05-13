<?php
require_once __DIR__ . '/../config/config.php';

header('Location: ' . BASE_URL . '/login.php', true, 302);
exit;
