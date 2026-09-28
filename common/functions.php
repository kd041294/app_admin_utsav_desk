<?php

declare(strict_types=1);

function udSetToast(string $type, string $title, string $message, int $duration = 4000): void
{
    $normalizedType = strtolower($type);
    if (!in_array($normalizedType, ['success', 'error', 'warning', 'info'], true)) {
        $normalizedType = 'info';
    }

    $_SESSION['UD_TOAST'] = [
        'type' => $normalizedType,
        'title' => $title,
        'message' => $message,
        'duration' => max(1500, $duration),
    ];
}

function udGetToast(): ?array
{
    if (!isset($_SESSION['UD_TOAST']) || !is_array($_SESSION['UD_TOAST'])) {
        return null;
    }

    $toast = $_SESSION['UD_TOAST'];
    unset($_SESSION['UD_TOAST']);

    return $toast;
}

function udToastScript(array $toast): string
{
    if ($toast === []) {
        return '';
    }

    $payload = json_encode($toast, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return '';
    }

    return '<script>window.__UD_TOAST__ = ' . $payload . ';</script>';
}
