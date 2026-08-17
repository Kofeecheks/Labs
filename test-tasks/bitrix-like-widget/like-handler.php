<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

const LIKES_IBLOCK_ID = 7;
const LIKES_PROPERTY_CODE = 'LIKES';
const LIKE_SESSION_KEY = 'like_widget';

/**
 * Sends a JSON response and stops request processing.
 */
function respondJson(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Reads the element and its current likes count via the Bitrix API.
 * Returns null when the element does not exist in the expected iblock.
 */
function getLikeState(int $elementId): ?array
{
    $result = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => LIKES_IBLOCK_ID,
            'ID' => $elementId,
            'ACTIVE' => 'Y',
        ],
        false,
        ['nTopCount' => 1],
        ['ID', 'IBLOCK_ID', 'PROPERTY_' . LIKES_PROPERTY_CODE]
    );

    $row = $result->Fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['ID'],
        'count' => max(0, (int) ($row['PROPERTY_' . LIKES_PROPERTY_CODE . '_VALUE'] ?? 0)),
    ];
}

$request = Application::getInstance()->getContext()->getRequest();

if (!$request->isPost()) {
    respondJson(405, [
        'success' => false,
        'error' => 'Method not allowed',
    ]);
}

// CSRF protection. The frontend sends BX.bitrix_sessid().
if (!check_bitrix_sessid()) {
    respondJson(403, [
        'success' => false,
        'error' => 'Invalid session token',
    ]);
}

if (!Loader::includeModule('iblock')) {
    respondJson(500, [
        'success' => false,
        'error' => 'Iblock module is unavailable',
    ]);
}

$idRaw = $request->getPost('id');
$action = trim((string) $request->getPost('action'));

$elementId = filter_var(
    $idRaw,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($elementId === false) {
    respondJson(422, [
        'success' => false,
        'error' => 'Invalid element ID',
    ]);
}

if (!in_array($action, ['like', 'unlike'], true)) {
    respondJson(422, [
        'success' => false,
        'error' => 'Invalid action',
    ]);
}

$state = getLikeState((int) $elementId);

if ($state === null) {
    respondJson(404, [
        'success' => false,
        'error' => 'Element not found',
    ]);
}

if (!isset($_SESSION[LIKE_SESSION_KEY]) || !is_array($_SESSION[LIKE_SESSION_KEY])) {
    $_SESSION[LIKE_SESSION_KEY] = [];
}

if (!isset($_SESSION[LIKE_SESSION_KEY]['liked']) || !is_array($_SESSION[LIKE_SESSION_KEY]['liked'])) {
    $_SESSION[LIKE_SESSION_KEY]['liked'] = [];
}

$sessionLikes = &$_SESSION[LIKE_SESSION_KEY]['liked'];
$alreadyLiked = !empty($sessionLikes[(int) $elementId]);
$currentCount = $state['count'];

// Make the operation idempotent for a guest session.
// Repeating "like" cannot increment the counter twice, and "unlike"
// cannot decrement a counter when this session has no active like.
if ($action === 'like' && $alreadyLiked) {
    respondJson(200, [
        'success' => true,
        'changed' => false,
        'liked' => true,
        'count' => $currentCount,
    ]);
}

if ($action === 'unlike' && !$alreadyLiked) {
    respondJson(200, [
        'success' => true,
        'changed' => false,
        'liked' => false,
        'count' => $currentCount,
    ]);
}

$newCount = $action === 'like'
    ? $currentCount + 1
    : max(0, $currentCount - 1);

// No raw SQL: update the iblock property through the standard Bitrix API.
CIBlockElement::SetPropertyValuesEx(
    (int) $elementId,
    LIKES_IBLOCK_ID,
    [LIKES_PROPERTY_CODE => $newCount]
);

$updatedState = getLikeState((int) $elementId);

if ($updatedState === null) {
    respondJson(500, [
        'success' => false,
        'error' => 'Failed to read the updated element',
    ]);
}

if ($action === 'like') {
    $sessionLikes[(int) $elementId] = true;
} else {
    unset($sessionLikes[(int) $elementId]);
}

respondJson(200, [
    'success' => true,
    'changed' => true,
    'liked' => $action === 'like',
    'count' => $updatedState['count'],
]);
