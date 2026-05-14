<?php
declare(strict_types=1);

/**
 * Single isolated feature card — expects $landingFeature with kicker, title, body,
 * optional id (element id anchor).
 */

if (!isset($landingFeature['title'], $landingFeature['body'])) {
    return;
}

$lid = isset($landingFeature['id']) && $landingFeature['id'] !== ''
    ? htmlspecialchars((string) $landingFeature['id'], ENT_QUOTES, 'UTF-8')
    : null;
$kicker = htmlspecialchars((string) ($landingFeature['kicker'] ?? ''), ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars((string) $landingFeature['title'], ENT_QUOTES, 'UTF-8');
$body = htmlspecialchars((string) $landingFeature['body'], ENT_QUOTES, 'UTF-8');
?>
<article class="content-card mechanix-feature-card"<?= $lid !== null ? ' id="' . $lid . '"' : ''; ?>>
    <span class="feature-kicker"><?= $kicker ?></span>
    <h3><?= $title ?></h3>
    <p><?= $body ?></p>
</article>
