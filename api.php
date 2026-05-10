<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(
        [
            'success' => false,
            'error' => 'Method not allowed. Use POST.',
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

try {
    $request = getRequestData();

    $username = sanitizeUsername((string)($request['username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Missing username parameter.');
    }

    $includeVideos = toBool($request['include_videos'] ?? 1);
    $videoLimit = clampInt((int)($request['video_limit'] ?? 20), 1, 100);
    $saveDebug = toBool($request['save_debug'] ?? 1);
    $videoSort = normalizeVideoSort((string)($request['video_sort'] ?? 'latest'));
    $useSavedCapture = toBool($request['use_saved_capture'] ?? 1);
    $saveCaptureTemplate = toBool($request['save_capture_template'] ?? 1);
    $useHarSnapshot = toBool($request['use_har_snapshot'] ?? 0);
    $useBrowserBridge = toBool($request['use_browser_bridge'] ?? 0);
    $useRequestMirrorFallback = toBool($request['use_request_mirror_fallback'] ?? 1);

    $capturedCurl = trim((string)($request['captured_curl'] ?? ''));
    $capturedCurlBase64 = trim((string)($request['captured_curl_base64'] ?? ''));
    $capturedRequestUrl = trim((string)($request['captured_request_url'] ?? ''));
    $capturedCookie = trim((string)($request['captured_cookie'] ?? ''));
    $capturedUserAgent = trim((string)($request['captured_user_agent'] ?? ''));
    $capturedReferer = trim((string)($request['captured_referer'] ?? ''));
    $capturedHeaders = parseCustomHeaders($request['captured_headers'] ?? []);
    $manualVideoUrls = parseVideoUrls($request['video_urls'] ?? []);

    $timestamp = gmdate('Ymd_His');
    $debugErrors = [];
    $savedFiles = [];
    $captureTemplateMeta = [
        'loaded' => false,
        'saved' => false,
        'file' => relativeProjectPath(getCaptureTemplateFilePath()),
    ];
    $harSnapshotMeta = [
        'loaded' => false,
        'file' => relativeProjectPath(getHarSnapshotFilePath()),
        'itemList_count' => 0,
        'error' => '',
    ];
    $browserBridgeMeta = [
        'attempted' => false,
        'available' => false,
        'used' => false,
        'itemList_count' => 0,
        'error' => '',
        'timeout_ms' => 0,
        'auto_install' => false,
    ];
    $requestMirrorMeta = [
        'attempted' => false,
        'used' => false,
        'source' => '',
        'videos_found' => 0,
        'playlists_found' => 0,
        'error' => '',
    ];
    if ($captureTemplateMeta['file'] !== '') {
        $savedFiles['capture_template_file'] = $captureTemplateMeta['file'];
    }

    $profile = fetchProfileData($username, $saveDebug, $timestamp);
    collectSavedFiles($savedFiles, $profile['saved_files'], 'profile');

    $profileItemList = is_array($profile['user_info']['itemList'] ?? null) ? $profile['user_info']['itemList'] : [];
    $profileVideos = normalizeTikTokItems($profileItemList, $profile['unique_id'], $videoLimit);
    $profileVideoUrls = array_values(array_unique(array_filter(array_map(
        static fn(array $v): string => (string)($v['url'] ?? ''),
        $profileVideos
    ))));

    $videos = [];
    $videoUrls = [];
    $source = 'profile_only';
    $postListDebug = [
        'attempted' => false,
        'status_code' => 0,
        'content_type' => '',
        'body_length' => 0,
        'first_500_chars' => '',
        'json_decoded' => false,
        'itemList_count' => 0,
        'final_url' => '',
        'attempt_source' => '',
        'error' => '',
    ];

    if ($includeVideos) {
        if ($profileVideos !== []) {
            $videos = array_slice($profileVideos, 0, $videoLimit);
            $videoUrls = array_slice($profileVideoUrls, 0, $videoLimit);
            $source = 'html';
        } else {
            $videoResult = fetchVideosFromItemListEndpoint(
                username: $profile['unique_id'],
                secUid: $profile['sec_uid'],
                limit: $videoLimit,
                saveDebug: $saveDebug,
                timestamp: $timestamp,
                capturedCurl: $capturedCurl,
                capturedRequestUrl: $capturedRequestUrl,
                capturedCookie: $capturedCookie,
                capturedUserAgent: $capturedUserAgent,
                capturedReferer: $capturedReferer,
                capturedHeaders: $capturedHeaders,
                capturedCurlBase64: $capturedCurlBase64,
                useSavedCapture: $useSavedCapture,
                saveCaptureTemplate: $saveCaptureTemplate,
                captureTemplateMeta: $captureTemplateMeta
            );

            collectSavedFiles($savedFiles, $videoResult['saved_files'], 'post_list');
            $postListDebug = $videoResult['debug'];
            $debugErrors = array_merge($debugErrors, $videoResult['errors']);

            if (!empty($videoResult['videos'])) {
                $videos = array_slice($videoResult['videos'], 0, $videoLimit);
                $videoUrls = array_slice($videoResult['video_urls'], 0, $videoLimit);
                $source = $videoResult['source'];
            } elseif (($videoResult['source'] ?? '') !== '') {
                $source = (string)$videoResult['source'];
            }

            if ($videos === [] && $useRequestMirrorFallback) {
                $mirrorResult = fetchVideosViaRequestMirrors(
                    username: $profile['unique_id'],
                    limit: $videoLimit,
                    saveDebug: $saveDebug,
                    timestamp: $timestamp
                );
                $requestMirrorMeta = $mirrorResult['meta'] ?? $requestMirrorMeta;
                collectSavedFiles($savedFiles, $mirrorResult['saved_files'], 'request_mirror');

                if (!empty($mirrorResult['errors']) && is_array($mirrorResult['errors'])) {
                    $debugErrors = array_merge($debugErrors, $mirrorResult['errors']);
                }

                if (!empty($mirrorResult['success']) && !empty($mirrorResult['videos'])) {
                    $videos = array_slice($mirrorResult['videos'], 0, $videoLimit);
                    $videoUrls = array_slice($mirrorResult['video_urls'], 0, $videoLimit);
                    $source = (string)($mirrorResult['source'] ?? 'request_mirror');
                    $postListDebug = [
                        'attempted' => true,
                        'status_code' => 200,
                        'content_type' => 'text/plain',
                        'body_length' => 0,
                        'first_500_chars' => '',
                        'json_decoded' => false,
                        'itemList_count' => toInt($requestMirrorMeta['videos_found'] ?? 0),
                        'final_url' => (string)($requestMirrorMeta['source'] ?? ''),
                        'attempt_source' => (string)($mirrorResult['source'] ?? 'request_mirror'),
                        'error' => '',
                    ];
                }
            }

            if ($videos === [] && $useBrowserBridge) {
                $bridgeResult = fetchVideosViaBrowserBridge(
                    username: $profile['unique_id'],
                    secUid: $profile['sec_uid'],
                    limit: $videoLimit,
                    timeoutMs: 0,
                    saveDebug: $saveDebug,
                    autoInstall: false
                );
                $browserBridgeMeta = $bridgeResult['meta'] ?? $browserBridgeMeta;

                if (!empty($bridgeResult['saved_files']) && is_array($bridgeResult['saved_files'])) {
                    foreach ($bridgeResult['saved_files'] as $key => $value) {
                        $savedFiles[(string)$key] = (string)$value;
                    }
                }

                if (!empty($bridgeResult['errors']) && is_array($bridgeResult['errors'])) {
                    $debugErrors = array_merge($debugErrors, $bridgeResult['errors']);
                }

                if (!empty($bridgeResult['success']) && !empty($bridgeResult['videos'])) {
                    $videos = array_slice($bridgeResult['videos'], 0, $videoLimit);
                    $videoUrls = array_slice($bridgeResult['video_urls'], 0, $videoLimit);
                    $source = 'browser_runtime_capture';
                    $postListDebug = [
                        'attempted' => true,
                        'status_code' => (int)($bridgeResult['status_code'] ?? 200),
                        'content_type' => 'application/json',
                        'body_length' => toInt($browserBridgeMeta['body_length'] ?? 0),
                        'first_500_chars' => (string)($browserBridgeMeta['first_500_chars'] ?? ''),
                        'json_decoded' => true,
                        'itemList_count' => toInt($browserBridgeMeta['itemList_count'] ?? 0),
                        'final_url' => (string)($browserBridgeMeta['request_url'] ?? ''),
                        'attempt_source' => 'browser_runtime_capture',
                        'error' => '',
                    ];
                }
            }

            if ($videos === [] && $useHarSnapshot) {
                $harResult = loadVideosFromHarSnapshot(
                    username: $profile['unique_id'],
                    secUid: $profile['sec_uid'],
                    limit: $videoLimit,
                    saveCaptureTemplate: $saveCaptureTemplate
                );
                $harSnapshotMeta = $harResult['meta'] ?? $harSnapshotMeta;

                if (!empty($harResult['saved_files']) && is_array($harResult['saved_files'])) {
                    foreach ($harResult['saved_files'] as $key => $value) {
                        $savedFiles[(string)$key] = (string)$value;
                    }
                }

                if (!empty($harResult['errors']) && is_array($harResult['errors'])) {
                    $debugErrors = array_merge($debugErrors, $harResult['errors']);
                }

                if (!empty($harResult['success']) && !empty($harResult['videos'])) {
                    $videos = array_slice($harResult['videos'], 0, $videoLimit);
                    $videoUrls = array_slice($harResult['video_urls'], 0, $videoLimit);
                    $source = 'har_snapshot';
                    $postListDebug = [
                        'attempted' => true,
                        'status_code' => 200,
                        'content_type' => 'application/json',
                        'body_length' => toInt($harSnapshotMeta['body_length'] ?? 0),
                        'first_500_chars' => (string)($harSnapshotMeta['first_500_chars'] ?? ''),
                        'json_decoded' => true,
                        'itemList_count' => toInt($harSnapshotMeta['itemList_count'] ?? 0),
                        'final_url' => (string)($harSnapshotMeta['request_url'] ?? ''),
                        'attempt_source' => 'har_snapshot',
                        'error' => '',
                    ];
                }
            }
        }
    }

    if ($manualVideoUrls !== []) {
        foreach ($manualVideoUrls as $url) {
            if (!in_array($url, $videoUrls, true)) {
                $videoUrls[] = $url;
            }
        }

        if ($videos === []) {
            foreach (array_slice($manualVideoUrls, 0, $videoLimit) as $url) {
                $videoId = extractVideoIdFromUrl($url) ?? '';
                $videos[] = [
                    'success' => $videoId !== '',
                    'id' => $videoId,
                    'url' => $url,
                    'desc' => '',
                    'created_at' => '',
                    'created_unix' => 0,
                    'stats' => [
                        'views' => 0,
                        'likes' => 0,
                        'comments' => 0,
                        'shares' => 0,
                        'collects' => 0,
                        'reposts' => 0,
                    ],
                    'video' => [
                        'duration' => 0,
                        'width' => 0,
                        'height' => 0,
                        'ratio' => '',
                        'cover' => '',
                        'dynamic_cover' => '',
                        'play_urls' => [],
                    ],
                    'author' => [
                        'id' => '',
                        'username' => $profile['unique_id'],
                        'nickname' => $profile['nickname'],
                        'verified' => $profile['verified'],
                    ],
                    'hashtags' => [],
                    'music' => [],
                ];
            }

            $source = 'manual_video_urls';
        }
    }

    $videoUrls = array_values(array_slice(array_unique(array_filter($videoUrls)), 0, $videoLimit));
    if ($videos !== []) {
        $videos = sortVideos($videos, $videoSort);
        $videos = array_slice($videos, 0, $videoLimit);
    }

    $debugSummary = [
        'timestamp' => gmdate('c'),
        'username' => $username,
        'profile_url' => $profile['profile_url'],
        'profile_http_status' => $profile['http_status'],
        'profile_html_length' => strlen($profile['html']),
        'profile_has_user_payload' => $profile['has_payload'],
        'profile_item_list_count' => count($profileItemList),
        'html_contains_video_word' => stripos($profile['html'], 'video') !== false,
        'post_list_attempted' => $postListDebug['attempted'],
        'post_list_status' => $postListDebug['status_code'],
        'post_list_content_type' => $postListDebug['content_type'],
        'post_list_body_length' => $postListDebug['body_length'],
        'post_list_first_500_chars' => $postListDebug['first_500_chars'],
        'post_list_json_decoded' => $postListDebug['json_decoded'],
        'post_list_itemList_count' => $postListDebug['itemList_count'],
        'post_list_final_url' => $postListDebug['final_url'],
        'post_list_attempt_source' => $postListDebug['attempt_source'],
        'har_snapshot_loaded' => $harSnapshotMeta['loaded'],
        'har_snapshot_file' => $harSnapshotMeta['file'],
        'har_snapshot_itemList_count' => $harSnapshotMeta['itemList_count'],
        'har_snapshot_error' => $harSnapshotMeta['error'],
        'browser_bridge_enabled' => $useBrowserBridge,
        'browser_bridge_attempted' => $browserBridgeMeta['attempted'],
        'browser_bridge_available' => $browserBridgeMeta['available'],
        'browser_bridge_used' => $browserBridgeMeta['used'],
        'browser_bridge_itemList_count' => $browserBridgeMeta['itemList_count'],
        'browser_bridge_error' => $browserBridgeMeta['error'],
        'browser_bridge_timeout_ms' => $browserBridgeMeta['timeout_ms'],
        'browser_bridge_auto_install' => $browserBridgeMeta['auto_install'],
        'request_mirror_enabled' => $useRequestMirrorFallback,
        'request_mirror_attempted' => $requestMirrorMeta['attempted'],
        'request_mirror_used' => $requestMirrorMeta['used'],
        'request_mirror_source' => $requestMirrorMeta['source'],
        'request_mirror_videos_found' => $requestMirrorMeta['videos_found'],
        'request_mirror_playlists_found' => $requestMirrorMeta['playlists_found'],
        'request_mirror_error' => $requestMirrorMeta['error'],
        'capture_template_loaded' => $captureTemplateMeta['loaded'],
        'capture_template_saved' => $captureTemplateMeta['saved'],
        'capture_template_file' => $captureTemplateMeta['file'],
        'final_videos_found' => count($videos),
        'errors' => $debugErrors,
    ];

    if ($saveDebug) {
        $summarySave = saveDebugContent(
            content: json_encode($debugSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
            baseName: 'debug_summary',
            username: $username,
            timestamp: $timestamp,
            extension: 'json'
        );
        collectSavedFiles($savedFiles, $summarySave, 'debug_summary');
    }

    echo json_encode(
        [
            'success' => true,
            'source' => $source,
            'username' => $username,
            'unique_id' => $profile['unique_id'],
            'nickname' => $profile['nickname'],
            'bio' => $profile['bio'],
            'region' => $profile['region'],
            'user_id' => $profile['user_id'],
            'create_time' => $profile['create_time'],
            'verified' => $profile['verified'],
            'private_account' => $profile['private_account'],
            'sec_uid' => $profile['sec_uid'],
            'profile_pic' => $profile['profile_pic'],
            'profile_url' => $profile['profile_url'],
            'stats' => $profile['stats'],
            'raw_stats' => $profile['raw_stats'],
            'videos_found' => count($videos),
            'video_urls' => $videoUrls,
            'top_5_video_urls' => array_slice($videoUrls, 0, 5),
            'videos' => $videos,
            'errors' => $debugErrors,
            'debug' => [
                'profile_html_saved' => $profile['saved_files']['latest'] ?? '',
                'profile_item_list_count' => count($profileItemList),
                'html_contains_video_word' => stripos($profile['html'], 'video') !== false,
                'post_list_attempted' => $postListDebug['attempted'],
                'post_list_status' => $postListDebug['status_code'],
                'post_list_json_decoded' => $postListDebug['json_decoded'],
                'post_list_itemList_count' => $postListDebug['itemList_count'],
                'attempt_source' => $postListDebug['attempt_source'],
                'har_snapshot_loaded' => $harSnapshotMeta['loaded'],
                'har_snapshot_file' => $harSnapshotMeta['file'],
                'har_snapshot_itemList_count' => $harSnapshotMeta['itemList_count'],
                'har_snapshot_error' => $harSnapshotMeta['error'],
                'browser_bridge_enabled' => $useBrowserBridge,
                'browser_bridge_attempted' => $browserBridgeMeta['attempted'],
                'browser_bridge_available' => $browserBridgeMeta['available'],
                'browser_bridge_used' => $browserBridgeMeta['used'],
                'browser_bridge_itemList_count' => $browserBridgeMeta['itemList_count'],
                'browser_bridge_error' => $browserBridgeMeta['error'],
                'browser_bridge_timeout_ms' => $browserBridgeMeta['timeout_ms'],
                'browser_bridge_auto_install' => $browserBridgeMeta['auto_install'],
                'request_mirror_enabled' => $useRequestMirrorFallback,
                'request_mirror_attempted' => $requestMirrorMeta['attempted'],
                'request_mirror_used' => $requestMirrorMeta['used'],
                'request_mirror_source' => $requestMirrorMeta['source'],
                'request_mirror_videos_found' => $requestMirrorMeta['videos_found'],
                'request_mirror_playlists_found' => $requestMirrorMeta['playlists_found'],
                'request_mirror_error' => $requestMirrorMeta['error'],
                'capture_template_loaded' => $captureTemplateMeta['loaded'],
                'capture_template_saved' => $captureTemplateMeta['saved'],
                'capture_template_file' => $captureTemplateMeta['file'],
                'errors' => $debugErrors,
            ],
            'saved_files' => $savedFiles,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(
        [
            'success' => false,
            'error' => $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
}

function getRequestData(): array
{
    $data = [];

    foreach ($_POST as $key => $value) {
        $data[$key] = $value;
    }

    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $data[$key] = $value;
            }
        }
    }

    return $data;
}

function sanitizeUsername(string $username): string
{
    $username = ltrim(trim($username), '@');
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $username) ?? '';
}

function toBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string)$value));
    return !in_array($value, ['0', 'false', 'no', 'off', ''], true);
}

function clampInt(int $value, int $min, int $max): int
{
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function parseVideoUrls(mixed $value): array
{
    $urls = [];

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '') {
            $parts = str_contains($value, ',') ? explode(',', $value) : [$value];
            foreach ($parts as $part) {
                $url = normalizeTikTokVideoUrl(trim((string)$part));
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
    } elseif (is_array($value)) {
        foreach ($value as $part) {
            $url = normalizeTikTokVideoUrl(trim((string)$part));
            if ($url !== '') {
                $urls[] = $url;
            }
        }
    }

    return array_values(array_unique($urls));
}

function parseCustomHeaders(mixed $value): array
{
    $headers = [];

    if (is_array($value)) {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            foreach ($value as $k => $v) {
                $key = trim((string)$k);
                $val = trim((string)$v);
                if ($key !== '' && $val !== '' && !str_starts_with($key, ':')) {
                    $headers[$key] = $val;
                }
            }
            return $headers;
        }

        foreach ($value as $line) {
            $line = trim((string)$line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '' && $v !== '' && !str_starts_with($k, ':')) {
                $headers[$k] = $v;
            }
        }
        return $headers;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim((string)$line);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '' && $v !== '' && !str_starts_with($k, ':')) {
            $headers[$k] = $v;
        }
    }

    return $headers;
}

function fetchProfileData(string $username, bool $saveDebug, string $timestamp): array
{
    $profileUrl = 'https://www.tiktok.com/@' . rawurlencode($username);
    $headers = [
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'accept-language' => 'en-US,en;q=0.9',
        'cache-control' => 'no-cache',
        'pragma' => 'no-cache',
        'upgrade-insecure-requests' => '1',
        'user-agent' => 'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Mobile Safari/537.36',
    ];

    $result = httpRequest($profileUrl, $headers);
    $html = (string)$result['body'];

    if ($saveDebug) {
        $saved = saveDebugContent(
            content: $html,
            baseName: 'profile',
            username: $username,
            timestamp: $timestamp,
            extension: 'html'
        );
    } else {
        $saved = ['latest' => '', 'timestamped' => ''];
    }

    $payload = extractUniversalDataPayload($html);
    $userInfo = $payload['__DEFAULT_SCOPE__']['webapp.user-detail']['userInfo'] ?? null;
    if (!is_array($userInfo)) {
        throw new RuntimeException('Could not parse userInfo from TikTok profile payload.');
    }

    $user = is_array($userInfo['user'] ?? null) ? $userInfo['user'] : [];
    $stats = is_array($userInfo['stats'] ?? null) ? $userInfo['stats'] : [];
    if ($user === []) {
        throw new RuntimeException('TikTok profile user data is missing.');
    }

    $uniqueId = (string)($user['uniqueId'] ?? $username);
    $followers = toInt($stats['followerCount'] ?? 0);
    $following = toInt($stats['followingCount'] ?? 0);
    $likes = toInt($stats['heart'] ?? ($stats['heartCount'] ?? 0));
    $videos = toInt($stats['videoCount'] ?? 0);
    $createTimeUnix = toInt($user['createTime'] ?? 0);

    return [
        'profile_url' => $profileUrl,
        'http_status' => (int)$result['status_code'],
        'html' => $html,
        'has_payload' => !empty($payload),
        'saved_files' => $saved,
        'user_info' => $userInfo,
        'unique_id' => $uniqueId,
        'nickname' => (string)($user['nickname'] ?? ''),
        'bio' => (string)($user['signature'] ?? ''),
        'region' => (string)($user['region'] ?? ''),
        'user_id' => (string)($user['id'] ?? ''),
        'create_time' => $createTimeUnix > 0 ? gmdate('Y-m-d H:i:s', $createTimeUnix) : '',
        'verified' => toBool($user['verified'] ?? false),
        'private_account' => toBool($user['privateAccount'] ?? false),
        'sec_uid' => (string)($user['secUid'] ?? ''),
        'profile_pic' => (string)($user['avatarLarger'] ?? ($user['avatarMedium'] ?? ($user['avatarThumb'] ?? ''))),
        'stats' => [
            'followers' => formatCompactNumber($followers),
            'following' => formatCompactNumber($following),
            'likes' => formatCompactNumber($likes),
            'videos' => $videos,
        ],
        'raw_stats' => [
            'followers' => $followers,
            'following' => $following,
            'likes' => $likes,
            'videos' => $videos,
        ],
    ];
}

function fetchVideosFromItemListEndpoint(
    string $username,
    string $secUid,
    int $limit,
    bool $saveDebug,
    string $timestamp,
    string $capturedCurl,
    string $capturedRequestUrl,
    string $capturedCookie,
    string $capturedUserAgent,
    string $capturedReferer,
    array $capturedHeaders,
    string $capturedCurlBase64 = '',
    bool $useSavedCapture = true,
    bool $saveCaptureTemplate = true,
    array &$captureTemplateMeta = []
): array {
    $errors = [];
    $savedFiles = [];
    $captureTemplateMeta['loaded'] = false;
    $captureTemplateMeta['saved'] = false;
    if (!isset($captureTemplateMeta['file'])) {
        $captureTemplateMeta['file'] = relativeProjectPath(getCaptureTemplateFilePath());
    }
    $lastDebug = [
        'attempted' => false,
        'status_code' => 0,
        'content_type' => '',
        'body_length' => 0,
        'first_500_chars' => '',
        'json_decoded' => false,
        'itemList_count' => 0,
        'final_url' => '',
        'attempt_source' => '',
        'error' => '',
    ];

    $attempts = [];

    if ($capturedCurl === '' && $capturedCurlBase64 !== '') {
        $decodedCurl = base64_decode($capturedCurlBase64, true);
        if (is_string($decodedCurl) && trim($decodedCurl) !== '') {
            $capturedCurl = $decodedCurl;
        } else {
            $errors[] = 'captured_curl_base64 was provided but could not be decoded.';
        }
    }

    if ($capturedCurl !== '') {
        $parsed = parseCapturedCurlCommand($capturedCurl);
        if ($parsed['url'] !== '') {
            $attempts[] = [
                'source' => 'captured_curl',
                'url' => $parsed['url'],
                'headers' => $parsed['headers'],
                'method' => $parsed['method'],
            ];
            if ($saveCaptureTemplate) {
                saveCaptureTemplate($username, $parsed);
                $captureTemplateMeta['saved'] = true;
            }
        } else {
            $errors[] = 'captured_curl was provided but URL could not be parsed.';
        }
    }

    if ($capturedRequestUrl !== '') {
        $headers = array_merge(
            buildItemListDefaultHeaders($username, $capturedUserAgent, $capturedReferer),
            $capturedHeaders
        );

        if ($capturedCookie !== '') {
            $headers['cookie'] = $capturedCookie;
        }

        $attempts[] = [
            'source' => 'captured_request_url',
            'url' => $capturedRequestUrl,
            'headers' => $headers,
            'method' => 'GET',
        ];
        if ($saveCaptureTemplate) {
            saveCaptureTemplate($username, [
                'url' => $capturedRequestUrl,
                'method' => 'GET',
                'headers' => $headers,
            ]);
            $captureTemplateMeta['saved'] = true;
        }
    }

    if ($useSavedCapture && $capturedCurl === '' && $capturedRequestUrl === '') {
        $savedTemplate = loadCaptureTemplate();
        if ($savedTemplate !== null && ($savedTemplate['url'] ?? '') !== '') {
            $captureTemplateMeta['loaded'] = true;

            $savedHeaders = is_array($savedTemplate['headers'] ?? null) ? $savedTemplate['headers'] : [];
            if (!isset($savedHeaders['referer']) || trim((string)$savedHeaders['referer']) === '') {
                $savedHeaders['referer'] = 'https://www.tiktok.com/@' . rawurlencode($username);
            }

            $attempts[] = [
                'source' => 'saved_capture_template',
                'url' => (string)$savedTemplate['url'],
                'headers' => $savedHeaders,
                'method' => (string)($savedTemplate['method'] ?? 'GET'),
            ];

            $unsignedFromTemplate = buildUnsignedUrlFromCaptured(
                (string)$savedTemplate['url'],
                $secUid,
                $limit
            );
            if ($unsignedFromTemplate !== '') {
                $attempts[] = [
                    'source' => 'saved_capture_template_unsigned',
                    'url' => $unsignedFromTemplate,
                    'headers' => $savedHeaders,
                    'method' => 'GET',
                ];
            }
        }
    }

    $fallbackUrl = buildFallbackItemListUrl($secUid, $limit, $username);
    $attempts[] = [
        'source' => 'post_list_curl',
        'url' => $fallbackUrl,
        'headers' => buildItemListDefaultHeaders($username, $capturedUserAgent, $capturedReferer),
        'method' => 'GET',
    ];

    foreach ($attempts as $attempt) {
        $request = httpRequest(
            url: (string)$attempt['url'],
            headers: (array)$attempt['headers'],
            method: (string)$attempt['method']
        );

        $body = (string)$request['body'];
        $contentType = strtolower((string)$request['content_type']);
        $decoded = json_decode($body, true);
        $isJson = is_array($decoded);

        $extension = ($isJson || str_contains($contentType, 'json')) ? 'json' : 'html';
        if ($saveDebug) {
            $saved = saveDebugContent(
                content: $body,
                baseName: 'post_list',
                username: $username,
                timestamp: $timestamp . '_' . $attempt['source'],
                extension: $extension
            );
            $savedFiles = array_merge($savedFiles, $saved);
        }

        $itemList = $isJson ? extractItemListRecursive($decoded) : [];
        $videos = normalizeTikTokItems($itemList, $username, $limit);
        $videoUrls = array_values(array_unique(array_filter(array_map(
            static fn(array $v): string => (string)($v['url'] ?? ''),
            $videos
        ))));

        $debug = [
            'attempted' => true,
            'status_code' => (int)$request['status_code'],
            'content_type' => (string)$request['content_type'],
            'body_length' => strlen($body),
            'first_500_chars' => substr($body, 0, 500),
            'json_decoded' => $isJson,
            'itemList_count' => count($itemList),
            'final_url' => (string)$request['final_url'],
            'attempt_source' => (string)$attempt['source'],
            'error' => '',
        ];
        $lastDebug = $debug;

        if ($videos !== []) {
            if ($saveCaptureTemplate && str_starts_with((string)$attempt['source'], 'saved_capture_template')) {
                maybeRefreshCaptureTemplateFromResponse($request);
            }
            return [
                'source' => (string)$attempt['source'],
                'videos' => array_slice($videos, 0, $limit),
                'video_urls' => array_slice($videoUrls, 0, $limit),
                'saved_files' => $savedFiles,
                'errors' => $errors,
                'debug' => $debug,
            ];
        }

        $err = 'No videos found in itemList response for source "' . $attempt['source'] . '".';
        if ((int)$request['status_code'] !== 200) {
            $err = 'HTTP ' . $request['status_code'] . ' from source "' . $attempt['source'] . '".';
        } elseif (strlen($body) === 0) {
            $err = 'Empty body from source "' . $attempt['source'] . '".';
        }

        if ($err !== '') {
            $errors[] = $err;
            $debug['error'] = $err;
            $lastDebug = $debug;
        }

        if ($saveCaptureTemplate && in_array((string)$attempt['source'], ['captured_curl', 'captured_request_url', 'saved_capture_template'], true)) {
            maybeRefreshCaptureTemplateFromResponse($request);
        }
    }

    return [
        'source' => ($lastDebug['attempt_source'] ?? 'post_list_curl') !== '' ? (string)$lastDebug['attempt_source'] : 'post_list_curl',
        'videos' => [],
        'video_urls' => [],
        'saved_files' => $savedFiles,
        'errors' => $errors,
        'debug' => $lastDebug + ['error' => implode(' | ', $errors)],
    ];
}

function parseCapturedCurlCommand(string $curlCommand): array
{
    $normalized = str_replace(["\\\r\n", "\\\n"], "\n", $curlCommand);

    $url = '';
    if (preg_match('/curl\s+([\'"])(.*?)\1/s', $normalized, $m)) {
        $url = trim((string)($m[2] ?? ''));
    }

    $method = 'GET';
    if (preg_match('/-X\s+([A-Z]+)/i', $normalized, $m)) {
        $method = strtoupper((string)($m[1] ?? 'GET'));
    }

    $headers = [];
    if (preg_match_all('/-H\s+([\'"])(.*?)\1/s', $normalized, $matches)) {
        foreach ($matches[2] as $headerLine) {
            $line = trim((string)$headerLine);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '' && $v !== '' && !str_starts_with($k, ':')) {
                $headers[$k] = $v;
            }
        }
    }

    if (preg_match('/-b\s+([\'"])(.*?)\1/s', $normalized, $m)) {
        $cookieValue = trim((string)($m[2] ?? ''));
        if ($cookieValue !== '') {
            $headers['cookie'] = $cookieValue;
        }
    }

    return [
        'url' => $url,
        'method' => $method,
        'headers' => $headers,
    ];
}

function buildItemListDefaultHeaders(string $username, string $userAgent = '', string $referer = ''): array
{
    $ua = trim($userAgent);
    if ($ua === '') {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36';
    }

    $ref = trim($referer);
    if ($ref === '') {
        $ref = 'https://www.tiktok.com/@' . rawurlencode($username);
    }

    return [
        'accept' => '*/*',
        'accept-language' => 'en-US,en;q=0.9',
        'referer' => $ref,
        'sec-fetch-dest' => 'empty',
        'sec-fetch-mode' => 'cors',
        'sec-fetch-site' => 'same-origin',
        'user-agent' => $ua,
    ];
}

function buildFallbackItemListUrl(string $secUid, int $limit, string $username): string
{
    $query = [
        'aid' => '1988',
        'app_name' => 'tiktok_web',
        'device_platform' => 'web_pc',
        'browser_name' => 'Mozilla',
        'browser_platform' => 'Win32',
        'browser_language' => 'en-US',
        'os' => 'windows',
        'region' => 'LB',
        'language' => 'en',
        'tz_name' => 'Etc/GMT-2',
        'screen_width' => '1280',
        'screen_height' => '720',
        'secUid' => $secUid,
        'cursor' => '0',
        'count' => (string)$limit,
        'msToken' => generateRandomToken(140),
        'referer' => '',
        'from_page' => 'user',
        'post_item_list_request_type' => '0',
    ];

    return 'https://www.tiktok.com/api/post/item_list/?' . http_build_query($query);
}

function httpRequest(string $url, array $headers = [], string $method = 'GET', ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL.');
    }

    $headerLines = [];
    foreach ($headers as $k => $v) {
        $key = trim((string)$k);
        $val = trim((string)$v);
        if ($key === '' || $val === '' || str_starts_with($key, ':')) {
            continue;
        }
        $headerLines[] = $key . ': ' . $val;
    }

    $cookieFile = getCookieFilePath();
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    curl_setopt(
        $ch,
        CURLOPT_HEADERFUNCTION,
        static function ($chHandle, string $headerLine) use (&$responseHeaders): int {
            $len = strlen($headerLine);
            $line = trim($headerLine);
            if ($line === '' || !str_contains($line, ':')) {
                return $len;
            }

            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $val = trim($value);
            if ($key === '' || $val === '') {
                return $len;
            }
            if (!isset($responseHeaders[$key])) {
                $responseHeaders[$key] = [];
            }
            $responseHeaders[$key][] = $val;

            return $len;
        }
    );

    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }

    $responseBody = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        $responseBody = '';
    }

    return [
        'status_code' => $statusCode,
        'final_url' => $finalUrl,
        'content_type' => $contentType,
        'body' => (string)$responseBody,
        'curl_error' => $curlError,
        'response_headers' => $responseHeaders,
    ];
}

function extractUniversalDataPayload(string $html): array
{
    if (!preg_match('/<script[^>]*id=["\']__UNIVERSAL_DATA_FOR_REHYDRATION__["\'][^>]*>(.*?)<\/script>/si', $html, $m)) {
        return [];
    }

    $raw = trim((string)($m[1] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    return is_array($decoded) ? $decoded : [];
}

function extractItemListRecursive(array $payload): array
{
    $queue = [$payload];
    while ($queue !== []) {
        $node = array_shift($queue);
        if (!is_array($node)) {
            continue;
        }

        if (isset($node['itemList']) && is_array($node['itemList'])) {
            return $node['itemList'];
        }
        if (isset($node['item_list']) && is_array($node['item_list'])) {
            return $node['item_list'];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $queue[] = $value;
            }
        }
    }

    return [];
}

function normalizeTikTokItems(array $items, string $fallbackUsername, int $limit): array
{
    $output = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = normalizeTikTokItem($item, $fallbackUsername);
        if (($normalized['id'] ?? '') !== '' && ($normalized['url'] ?? '') !== '') {
            $output[] = $normalized;
        }
        if (count($output) >= $limit) {
            break;
        }
    }
    return $output;
}

function normalizeTikTokItem(array $item, string $fallbackUsername): array
{
    $author = is_array($item['author'] ?? null) ? $item['author'] : [];
    $stats = is_array($item['stats'] ?? null) ? $item['stats'] : [];
    $statsV2 = is_array($item['statsV2'] ?? null) ? $item['statsV2'] : [];
    if ($stats === [] && $statsV2 !== []) {
        $stats = $statsV2;
    }

    $video = is_array($item['video'] ?? null) ? $item['video'] : [];
    $bitrateInfo = is_array($video['bitrateInfo'] ?? null) ? $video['bitrateInfo'] : [];

    $id = (string)($item['id'] ?? ($item['aweme_id'] ?? ($item['itemId'] ?? ($item['item_id'] ?? ''))));
    $authorUsername = (string)($author['uniqueId'] ?? ($author['unique_id'] ?? ($author['username'] ?? $fallbackUsername)));
    $url = ($id !== '' && $authorUsername !== '') ? buildTikTokVideoUrl($authorUsername, $id) : '';

    $playAddr = [];
    if (is_array($video['PlayAddrStruct'] ?? null)) {
        $playAddr = $video['PlayAddrStruct'];
    } elseif (is_array($video['playAddr'] ?? null)) {
        $playAddr = $video['playAddr'];
    } elseif (isset($bitrateInfo[0]['PlayAddr']) && is_array($bitrateInfo[0]['PlayAddr'])) {
        $playAddr = $bitrateInfo[0]['PlayAddr'];
    } elseif (isset($bitrateInfo[0]['playAddr']) && is_array($bitrateInfo[0]['playAddr'])) {
        $playAddr = $bitrateInfo[0]['playAddr'];
    }

    $playUrls = [];
    $urlListCandidate = $playAddr['UrlList'] ?? ($playAddr['urlList'] ?? []);
    if (is_array($urlListCandidate)) {
        foreach ($urlListCandidate as $u) {
            $u = trim((string)$u);
            if ($u !== '') {
                $playUrls[] = $u;
            }
        }
    }
    $playUrls = array_values(array_unique($playUrls));

    $createUnix = toInt($item['createTime'] ?? ($item['create_time'] ?? 0));
    $hashtags = extractHashtags($item);
    $music = is_array($item['music'] ?? null) ? $item['music'] : [];

    $videoWidth = toInt($video['width'] ?? ($playAddr['Width'] ?? ($playAddr['width'] ?? 0)));
    $videoHeight = toInt($video['height'] ?? ($playAddr['Height'] ?? ($playAddr['height'] ?? 0)));
    $ratio = (string)($video['ratio'] ?? '');
    if ($ratio === '' && $videoWidth > 0 && $videoHeight > 0) {
        $ratio = $videoWidth . ':' . $videoHeight;
    }

    return [
        'success' => ($id !== '' && $url !== ''),
        'id' => $id,
        'url' => $url,
        'desc' => (string)($item['desc'] ?? ''),
        'created_at' => $createUnix > 0 ? gmdate('Y-m-d H:i:s', $createUnix) : '',
        'created_unix' => $createUnix,
        'stats' => [
            'views' => toInt($stats['playCount'] ?? ($stats['play_count'] ?? 0)),
            'likes' => toInt($stats['diggCount'] ?? ($stats['digg_count'] ?? 0)),
            'comments' => toInt($stats['commentCount'] ?? ($stats['comment_count'] ?? 0)),
            'shares' => toInt($stats['shareCount'] ?? ($stats['share_count'] ?? 0)),
            'collects' => toInt($stats['collectCount'] ?? ($stats['collect_count'] ?? 0)),
            'reposts' => toInt($stats['repostCount'] ?? ($stats['repost_count'] ?? 0)),
        ],
        'video' => [
            'duration' => toInt($video['duration'] ?? ($item['videoDuration'] ?? 0)),
            'width' => $videoWidth,
            'height' => $videoHeight,
            'ratio' => $ratio,
            'cover' => (string)($video['cover'] ?? ($video['originCover'] ?? '')),
            'dynamic_cover' => (string)($video['dynamicCover'] ?? ''),
            'play_urls' => $playUrls,
        ],
        'author' => [
            'id' => (string)($author['id'] ?? ($author['uid'] ?? '')),
            'username' => $authorUsername,
            'nickname' => (string)($author['nickname'] ?? ''),
            'verified' => toBool($author['verified'] ?? false),
        ],
        'hashtags' => $hashtags,
        'music' => [
            'id' => (string)($music['id'] ?? ''),
            'title' => (string)($music['title'] ?? ''),
            'author' => (string)($music['authorName'] ?? ($music['author'] ?? '')),
            'play_url' => (string)($music['playUrl'] ?? ''),
        ],
    ];
}

function extractHashtags(array $item): array
{
    $tags = [];
    if (is_array($item['challenges'] ?? null)) {
        foreach ($item['challenges'] as $challenge) {
            if (!is_array($challenge)) {
                continue;
            }
            $title = trim((string)($challenge['title'] ?? ''));
            if ($title !== '') {
                $tags[] = $title;
            }
        }
    }

    if ($tags === [] && is_array($item['textExtra'] ?? null)) {
        foreach ($item['textExtra'] as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $tag = trim((string)($extra['hashtagName'] ?? ''));
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
    }

    return array_values(array_unique($tags));
}

function extractVideoIdFromUrl(string $url): ?string
{
    if (preg_match('~/video/(\d+)~', $url, $m)) {
        return (string)$m[1];
    }
    return null;
}

function normalizeTikTokVideoUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('~https?://(?:www\.)?tiktok\.com/@[^/]+/video/\d+~i', $url, $m)) {
        return $m[0];
    }

    if (preg_match('~/@([^/]+)/video/(\d+)~', $url, $m)) {
        return 'https://www.tiktok.com/@' . $m[1] . '/video/' . $m[2];
    }

    return '';
}

function buildTikTokVideoUrl(string $username, string $videoId): string
{
    return 'https://www.tiktok.com/@' . ltrim($username, '@') . '/video/' . $videoId;
}

function toInt(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int)round($value);
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)round((float)$value);
        }
    }
    return 0;
}

function formatCompactNumber(int $number): string
{
    $abs = abs($number);
    if ($abs >= 1_000_000_000) {
        return rtrim(rtrim(number_format($number / 1_000_000_000, 1, '.', ''), '0'), '.') . 'b';
    }
    if ($abs >= 1_000_000) {
        return rtrim(rtrim(number_format($number / 1_000_000, 1, '.', ''), '0'), '.') . 'm';
    }
    if ($abs >= 1_000) {
        return rtrim(rtrim(number_format($number / 1_000, 1, '.', ''), '0'), '.') . 'k';
    }
    return (string)$number;
}

function normalizeVideoSort(string $sort): string
{
    $sort = strtolower(trim($sort));
    if (in_array($sort, ['views', 'likes', 'latest'], true)) {
        return $sort;
    }
    return 'latest';
}

function sortVideos(array $videos, string $sort): array
{
    if ($videos === []) {
        return $videos;
    }

    if ($sort === 'latest') {
        usort(
            $videos,
            static fn(array $a, array $b): int => toInt($b['created_unix'] ?? 0) <=> toInt($a['created_unix'] ?? 0)
        );
        return $videos;
    }

    if ($sort === 'views') {
        usort(
            $videos,
            static fn(array $a, array $b): int => toInt($b['stats']['views'] ?? 0) <=> toInt($a['stats']['views'] ?? 0)
        );
        return $videos;
    }

    if ($sort === 'likes') {
        usort(
            $videos,
            static fn(array $a, array $b): int => toInt($b['stats']['likes'] ?? 0) <=> toInt($a['stats']['likes'] ?? 0)
        );
        return $videos;
    }

    return $videos;
}

function generateRandomToken(int $length): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';
    $max = strlen($alphabet) - 1;
    $token = '';
    for ($i = 0; $i < $length; $i++) {
        $token .= $alphabet[random_int(0, $max)];
    }
    return $token;
}

function getCaptureTemplateFilePath(): string
{
    $dir = ensureDebugDirectory();
    return $dir . DIRECTORY_SEPARATOR . 'capture_template_latest.json';
}

function getHarSnapshotFilePath(): string
{
    $dir = ensureDebugDirectory();
    return $dir . DIRECTORY_SEPARATOR . 'har.txt';
}

function loadVideosFromHarSnapshot(
    string $username,
    string $secUid,
    int $limit,
    bool $saveCaptureTemplate = true
): array {
    $path = getHarSnapshotFilePath();
    $meta = [
        'loaded' => false,
        'file' => relativeProjectPath($path),
        'itemList_count' => 0,
        'error' => '',
        'body_length' => 0,
        'first_500_chars' => '',
        'request_url' => '',
    ];
    $errors = [];
    $savedFiles = [];

    if (!is_file($path)) {
        $meta['error'] = 'har.txt was not found.';
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => ['har.txt not found.'],
            'saved_files' => [],
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        $meta['error'] = 'har.txt is empty.';
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => ['har.txt is empty.'],
            'saved_files' => [],
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    $parsedHar = parseHarSnapshotText($raw);
    if (!empty($parsedHar['error'])) {
        $meta['error'] = (string)$parsedHar['error'];
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => [$meta['error']],
            'saved_files' => [],
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    $responseJsonText = (string)($parsedHar['response_json_text'] ?? '');
    $decoded = is_array($parsedHar['response_json'] ?? null) ? $parsedHar['response_json'] : [];
    $meta['loaded'] = true;
    $meta['body_length'] = strlen($responseJsonText);
    $meta['first_500_chars'] = substr($responseJsonText, 0, 500);

    $curlText = trim((string)($parsedHar['curl_text'] ?? ''));
    if ($curlText !== '') {
        $parsedCurl = parseCapturedCurlCommand($curlText);
        if (($parsedCurl['url'] ?? '') !== '') {
            $meta['request_url'] = (string)$parsedCurl['url'];
            if ($saveCaptureTemplate) {
                saveCaptureTemplate($username, $parsedCurl);
            }
            $requestDump = [
                'captured_at' => gmdate('c'),
                'source' => 'har.txt',
                'method' => (string)($parsedCurl['method'] ?? 'GET'),
                'url' => (string)($parsedCurl['url'] ?? ''),
                'headers' => is_array($parsedCurl['headers'] ?? null) ? $parsedCurl['headers'] : [],
            ];
            $requestFile = ensureDebugDirectory() . DIRECTORY_SEPARATOR . 'browser_item_list_request_latest.json';
            file_put_contents(
                $requestFile,
                json_encode($requestDump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );
            $savedFiles['browser_item_list_request_latest'] = relativeProjectPath($requestFile);
        }
    }

    $decodedForExtraction = $decoded;
    if (isset($decodedForExtraction['body']) && is_string($decodedForExtraction['body'])) {
        $innerBody = json_decode($decodedForExtraction['body'], true);
        if (is_array($innerBody)) {
            $decodedForExtraction['body_decoded'] = $innerBody;
        }
    }
    if (isset($decodedForExtraction['response_json']) && is_string($decodedForExtraction['response_json'])) {
        $innerResponse = json_decode($decodedForExtraction['response_json'], true);
        if (is_array($innerResponse)) {
            $decodedForExtraction['response_json_decoded'] = $innerResponse;
        }
    }

    $itemList = extractItemListRecursive($decodedForExtraction);
    $meta['itemList_count'] = count($itemList);

    $responseFile = ensureDebugDirectory() . DIRECTORY_SEPARATOR . 'browser_item_list_response_latest.json';
    file_put_contents(
        $responseFile,
        json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    $savedFiles['browser_item_list_response_latest'] = relativeProjectPath($responseFile);

    $summary = [
        'timestamp' => gmdate('c'),
        'username_requested' => $username,
        'sec_uid_requested' => $secUid,
        'har_file' => $meta['file'],
        'request_url' => $meta['request_url'],
        'json_decoded' => true,
        'itemList_count' => $meta['itemList_count'],
        'body_length' => $meta['body_length'],
    ];
    $summaryFile = ensureDebugDirectory() . DIRECTORY_SEPARATOR . 'browser_capture_summary_latest.json';
    file_put_contents(
        $summaryFile,
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
    $savedFiles['browser_capture_summary_latest'] = relativeProjectPath($summaryFile);

    if ($itemList === []) {
        $meta['error'] = 'har.txt response JSON decoded but itemList was empty.';
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => [$meta['error']],
            'saved_files' => $savedFiles,
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    $profileMatchOk = false;
    foreach (array_slice($itemList, 0, 5) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $itemAuthor = is_array($item['author'] ?? null) ? $item['author'] : [];
        $itemUsername = strtolower(trim((string)($itemAuthor['uniqueId'] ?? '')));
        $itemSecUid = trim((string)($itemAuthor['secUid'] ?? ''));
        if (($itemUsername !== '' && strtolower($username) === $itemUsername) || ($secUid !== '' && $itemSecUid === $secUid)) {
            $profileMatchOk = true;
            break;
        }
    }

    if (!$profileMatchOk) {
        $meta['error'] = 'har.txt itemList does not appear to match requested profile.';
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => [$meta['error']],
            'saved_files' => $savedFiles,
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    $videos = normalizeTikTokItems($itemList, $username, $limit);
    $videoUrls = array_values(array_unique(array_filter(array_map(
        static fn(array $v): string => (string)($v['url'] ?? ''),
        $videos
    ))));

    if ($videos === []) {
        $meta['error'] = 'itemList found in har.txt but video normalization returned no URLs.';
        return [
            'success' => false,
            'videos' => [],
            'video_urls' => [],
            'errors' => [$meta['error']],
            'saved_files' => $savedFiles,
            'meta' => $meta,
            'source' => 'har_snapshot',
        ];
    }

    return [
        'success' => true,
        'videos' => array_slice($videos, 0, $limit),
        'video_urls' => array_slice($videoUrls, 0, $limit),
        'errors' => $errors,
        'saved_files' => $savedFiles,
        'meta' => $meta,
        'source' => 'har_snapshot',
    ];
}

function parseHarSnapshotText(string $raw): array
{
    $result = [
        'response_json' => [],
        'response_json_text' => '',
        'curl_text' => '',
        'error' => '',
    ];

    $trimmed = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
    $decodedRaw = json_decode($trimmed, true);
    if (is_array($decodedRaw)) {
        $result['response_json'] = $decodedRaw;
        $result['response_json_text'] = $trimmed;
        return $result;
    }

    $markerPos = stripos($raw, 'response:');
    $jsonText = '';
    if ($markerPos !== false) {
        $result['curl_text'] = trim(substr($raw, 0, $markerPos));
        $jsonText = trim(substr($raw, $markerPos + strlen('response:')));
    } else {
        $firstBrace = strpos($raw, '{');
        $lastBrace = strrpos($raw, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonText = trim(substr($raw, $firstBrace, $lastBrace - $firstBrace + 1));
        }
    }

    if ($jsonText === '') {
        $result['error'] = 'Could not locate response JSON inside har.txt.';
        return $result;
    }

    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        $firstBrace = strpos($jsonText, '{');
        $lastBrace = strrpos($jsonText, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonTextCandidate = trim(substr($jsonText, $firstBrace, $lastBrace - $firstBrace + 1));
            $decoded = json_decode($jsonTextCandidate, true);
            if (is_array($decoded)) {
                $jsonText = $jsonTextCandidate;
            }
        }
    }

    if (!is_array($decoded)) {
        $result['error'] = 'Failed to decode response JSON from har.txt: ' . json_last_error_msg();
        return $result;
    }

    $result['response_json'] = $decoded;
    $result['response_json_text'] = $jsonText;
    return $result;
}

function fetchVideosViaRequestMirrors(
    string $username,
    int $limit,
    bool $saveDebug,
    string $timestamp
): array {
    $errors = [];
    $savedFiles = [];
    $meta = [
        'attempted' => true,
        'used' => false,
        'source' => '',
        'videos_found' => 0,
        'playlists_found' => 0,
        'error' => '',
    ];

    $normalizedUsername = ltrim(trim($username), '@');
    if ($normalizedUsername === '') {
        $msg = 'Request mirror fallback: missing username.';
        $meta['error'] = $msg;
        return [
            'success' => false,
            'source' => 'request_mirror',
            'videos' => [],
            'video_urls' => [],
            'errors' => [$msg],
            'saved_files' => [],
            'meta' => $meta,
        ];
    }

    $mirrorHeaders = [
        'accept' => 'text/plain,*/*',
        'accept-language' => 'en-US,en;q=0.9',
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
    ];

    $profileMirrorUrl = 'https://r.jina.ai/http://www.tiktok.com/@' . rawurlencode($normalizedUsername) . '?tab=videos';
    $profileMirrorRequest = httpRequest($profileMirrorUrl, $mirrorHeaders);
    $profileMirrorBody = (string)($profileMirrorRequest['body'] ?? '');
    if ($saveDebug) {
        $saved = saveDebugContent(
            content: $profileMirrorBody,
            baseName: 'request_mirror_profile',
            username: $normalizedUsername,
            timestamp: $timestamp . '_request_mirror_profile',
            extension: 'txt'
        );
        $savedFiles = array_merge($savedFiles, $saved);
    }

    $playlistIds = [];
    if ($profileMirrorBody !== '') {
        preg_match_all(
            '~https://www\\.tiktok\\.com/@[^/\\s\\)]+/playlist/[^\\s\\)]*-(\\d{15,22})~i',
            $profileMirrorBody,
            $playlistMatches
        );
        $playlistIds = array_values(array_unique($playlistMatches[1] ?? []));
    }
    $meta['playlists_found'] = count($playlistIds);

    if ($playlistIds !== []) {
        $mixHeaders = buildItemListDefaultHeaders($normalizedUsername);
        $mixHeaders['accept'] = 'application/json,text/plain,*/*';

        $allItems = [];
        $seenIds = [];
        $mixCount = clampInt(max($limit * 3, 20), 20, 100);

        foreach ($playlistIds as $mixId) {
            $mixUrl = 'https://www.tiktok.com/api/mix/item_list/?aid=1988&mixId='
                . rawurlencode((string)$mixId)
                . '&count=' . $mixCount
                . '&cursor=0';
            $mixRequest = httpRequest($mixUrl, $mixHeaders);
            $mixBody = (string)($mixRequest['body'] ?? '');

            if ($saveDebug) {
                $saved = saveDebugContent(
                    content: $mixBody,
                    baseName: 'request_mirror_mix',
                    username: $normalizedUsername,
                    timestamp: $timestamp . '_mix_' . (string)$mixId,
                    extension: 'json'
                );
                $savedFiles = array_merge($savedFiles, $saved);
            }

            if ($mixBody === '') {
                $errors[] = 'Request mirror mix "' . $mixId . '" returned empty body.';
                continue;
            }

            $mixJson = json_decode($mixBody, true);
            if (!is_array($mixJson)) {
                $errors[] = 'Request mirror mix "' . $mixId . '" returned invalid JSON.';
                continue;
            }

            $itemList = extractItemListRecursive($mixJson);
            if ($itemList === []) {
                continue;
            }

            foreach ($itemList as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemId = (string)($item['id'] ?? ($item['aweme_id'] ?? ($item['awemeId'] ?? '')));
                if ($itemId !== '') {
                    if (isset($seenIds[$itemId])) {
                        continue;
                    }
                    $seenIds[$itemId] = true;
                }

                $allItems[] = $item;
            }
        }

        if ($allItems !== []) {
            $videos = normalizeTikTokItems($allItems, $normalizedUsername, $limit);
            $videoUrls = array_values(array_unique(array_filter(array_map(
                static fn(array $v): string => (string)($v['url'] ?? ''),
                $videos
            ))));

            if ($videos !== []) {
                $meta['used'] = true;
                $meta['source'] = 'request_mirror_mix';
                $meta['videos_found'] = count($videos);

                return [
                    'success' => true,
                    'source' => 'request_mirror_mix',
                    'videos' => array_slice($videos, 0, $limit),
                    'video_urls' => array_slice($videoUrls, 0, $limit),
                    'errors' => $errors,
                    'saved_files' => $savedFiles,
                    'meta' => $meta,
                ];
            }
        }
    }

    $urlebirdUrl = 'https://r.jina.ai/http://urlebird.com/user/' . rawurlencode($normalizedUsername) . '/';
    $urlebirdRequest = httpRequest($urlebirdUrl, $mirrorHeaders);
    $urlebirdBody = (string)($urlebirdRequest['body'] ?? '');
    if ($saveDebug) {
        $saved = saveDebugContent(
            content: $urlebirdBody,
            baseName: 'request_mirror_urlebird',
            username: $normalizedUsername,
            timestamp: $timestamp . '_request_mirror_urlebird',
            extension: 'txt'
        );
        $savedFiles = array_merge($savedFiles, $saved);
    }

    if ($urlebirdBody === '') {
        $msg = 'Request mirror fallback returned empty response from urlebird mirror.';
        $meta['error'] = $msg;
        $errors[] = $msg;
        return [
            'success' => false,
            'source' => 'request_mirror',
            'videos' => [],
            'video_urls' => [],
            'errors' => $errors,
            'saved_files' => $savedFiles,
            'meta' => $meta,
        ];
    }

    preg_match_all(
        '~https?://urlebird\\.com/video/([A-Za-z0-9%._-]+?)-(\\d{15,22})/~i',
        $urlebirdBody,
        $urlebirdMatches,
        PREG_SET_ORDER
    );

    $videos = [];
    $videoUrls = [];
    $seenVideoIds = [];
    foreach ($urlebirdMatches as $match) {
        $slug = (string)($match[1] ?? '');
        $videoId = (string)($match[2] ?? '');
        if ($videoId === '' || isset($seenVideoIds[$videoId])) {
            continue;
        }
        $seenVideoIds[$videoId] = true;

        $url = buildTikTokVideoUrl($normalizedUsername, $videoId);
        $videoUrls[] = $url;
        $desc = trim(preg_replace('/\\s+/', ' ', str_replace(['-', '_'], ' ', rawurldecode($slug))) ?? '');

        $videos[] = [
            'success' => true,
            'id' => $videoId,
            'url' => $url,
            'desc' => $desc,
            'created_at' => '',
            'created_unix' => 0,
            'stats' => [
                'views' => 0,
                'likes' => 0,
                'comments' => 0,
                'shares' => 0,
                'collects' => 0,
                'reposts' => 0,
            ],
            'video' => [
                'duration' => 0,
                'width' => 0,
                'height' => 0,
                'ratio' => '',
                'cover' => '',
                'dynamic_cover' => '',
                'play_urls' => [],
            ],
            'author' => [
                'id' => '',
                'username' => $normalizedUsername,
                'nickname' => '',
                'verified' => false,
            ],
            'hashtags' => [],
            'music' => [],
        ];

        if (count($videos) >= $limit) {
            break;
        }
    }

    if ($videos === []) {
        $msg = 'Request mirror fallback could not extract video IDs.';
        $meta['error'] = $msg;
        $errors[] = $msg;
        return [
            'success' => false,
            'source' => 'request_mirror',
            'videos' => [],
            'video_urls' => [],
            'errors' => $errors,
            'saved_files' => $savedFiles,
            'meta' => $meta,
        ];
    }

    $meta['used'] = true;
    $meta['source'] = 'request_mirror_urlebird';
    $meta['videos_found'] = count($videos);

    return [
        'success' => true,
        'source' => 'request_mirror_urlebird',
        'videos' => $videos,
        'video_urls' => $videoUrls,
        'errors' => $errors,
        'saved_files' => $savedFiles,
        'meta' => $meta,
    ];
}

function fetchVideosViaBrowserBridge(
    string $username,
    string $secUid,
    int $limit,
    int $timeoutMs,
    bool $saveDebug,
    bool $autoInstall = true
): array {
    $msg = 'Browser bridge fallback is disabled in PHP-only mode. Video extraction uses PHP item_list + HAR/manual fallbacks only.';
    $meta = [
        'attempted' => true,
        'available' => false,
        'used' => false,
        'itemList_count' => 0,
        'error' => $msg,
        'timeout_ms' => 0,
        'auto_install' => false,
        'request_url' => '',
        'body_length' => 0,
        'first_500_chars' => '',
    ];

    return [
        'success' => false,
        'videos' => [],
        'video_urls' => [],
        'errors' => [$msg],
        'saved_files' => [],
        'meta' => $meta,
        'status_code' => 0,
    ];
}

function saveCaptureTemplate(string $username, array $parsed): void
{
    $template = [
        'saved_at' => gmdate('c'),
        'username' => $username,
        'url' => (string)($parsed['url'] ?? ''),
        'method' => strtoupper((string)($parsed['method'] ?? 'GET')),
        'headers' => is_array($parsed['headers'] ?? null) ? $parsed['headers'] : [],
    ];

    file_put_contents(
        getCaptureTemplateFilePath(),
        json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function loadCaptureTemplate(): ?array
{
    $path = getCaptureTemplateFilePath();
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function buildUnsignedUrlFromCaptured(string $capturedUrl, string $secUid, int $limit): string
{
    $parts = parse_url($capturedUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
    }
    if ($query === []) {
        return '';
    }

    unset($query['X-Bogus'], $query['X-Gnarly'], $query['x-bogus'], $query['x-gnarly']);
    if ($secUid !== '') {
        $query['secUid'] = $secUid;
    }
    $query['cursor'] = '0';
    $query['count'] = (string)$limit;

    if (!isset($query['msToken']) || trim((string)$query['msToken']) === '') {
        $query['msToken'] = generateRandomToken(120);
    }

    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $path = (string)($parts['path'] ?? '/api/post/item_list/');
    $rebuilt = $scheme . '://' . $host . $path . '?' . http_build_query($query);
    return $rebuilt;
}

function maybeRefreshCaptureTemplateFromResponse(array $request): void
{
    $template = loadCaptureTemplate();
    if ($template === null) {
        return;
    }

    $headers = is_array($template['headers'] ?? null) ? $template['headers'] : [];
    $cookie = (string)($headers['cookie'] ?? '');
    if ($cookie === '') {
        return;
    }

    $responseHeaders = is_array($request['response_headers'] ?? null) ? $request['response_headers'] : [];
    $xMsTokenValues = is_array($responseHeaders['x-ms-token'] ?? null) ? $responseHeaders['x-ms-token'] : [];
    if ($xMsTokenValues === []) {
        return;
    }

    $latestMsToken = trim((string)end($xMsTokenValues));
    if ($latestMsToken === '') {
        return;
    }

    $headers['cookie'] = replaceCookieValue($cookie, 'msToken', $latestMsToken);
    $template['headers'] = $headers;
    $template['updated_at'] = gmdate('c');

    file_put_contents(
        getCaptureTemplateFilePath(),
        json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function replaceCookieValue(string $cookieHeader, string $cookieName, string $newValue): string
{
    $pairs = array_values(array_filter(array_map('trim', explode(';', $cookieHeader))));
    $found = false;
    foreach ($pairs as &$pair) {
        if (str_starts_with($pair, $cookieName . '=')) {
            $pair = $cookieName . '=' . $newValue;
            $found = true;
        }
    }
    unset($pair);
    if (!$found) {
        $pairs[] = $cookieName . '=' . $newValue;
    }
    return implode('; ', $pairs);
}

function saveDebugContent(string $content, string $baseName, string $username, string $timestamp, string $extension): array
{
    $dir = ensureDebugDirectory();
    $extension = strtolower(trim($extension));
    if ($extension === '') {
        $extension = 'txt';
    }

    $latestPath = $dir . DIRECTORY_SEPARATOR . $baseName . '_latest.' . $extension;
    $timestampedPath = $dir . DIRECTORY_SEPARATOR . $baseName . '_' . $username . '_' . $timestamp . '.' . $extension;

    file_put_contents($latestPath, $content);
    file_put_contents($timestampedPath, $content);

    return [
        'latest' => relativeProjectPath($latestPath),
        'timestamped' => relativeProjectPath($timestampedPath),
    ];
}

function ensureDebugDirectory(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'debug_tiktok_api';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function getCookieFilePath(): string
{
    $dir = ensureDebugDirectory();
    return $dir . DIRECTORY_SEPARATOR . 'tiktok_cookies.txt';
}

function relativeProjectPath(string $path): string
{
    return ltrim(str_replace(__DIR__, '', $path), DIRECTORY_SEPARATOR);
}

function collectSavedFiles(array &$collector, array $saved, string $label): void
{
    if (($saved['latest'] ?? '') !== '') {
        $collector[$label . '_latest'] = (string)$saved['latest'];
    }
    if (($saved['timestamped'] ?? '') !== '') {
        $collector[$label . '_timestamped'] = (string)$saved['timestamped'];
    }
}
