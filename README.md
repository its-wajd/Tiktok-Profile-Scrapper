# TikTok Profile Intelligence API

Advanced TikTok profile scraping API built in PHP by [@its-wajd](https://github.com/its-wajd).

This project is a server-side TikTok profile data extractor designed to fetch public profile information, normalize statistics, and attempt video metadata collection using multiple fallback strategies. It is built for PHP hosting environments while still keeping advanced scraping concepts such as browser-like headers, captured request replay, debug snapshots, HAR/request parsing, and response normalization.

> This project focuses on public TikTok profile intelligence, request analysis, and resilient web scraping logic.

---

## Features

- Fetch public TikTok profile data using PHP cURL
- Extract TikTok hydration payloads from profile HTML
- Parse `__UNIVERSAL_DATA_FOR_REHYDRATION__`
- Extract normalized profile fields:
  - username
  - nickname
  - bio
  - region
  - user ID
  - creation time
  - verification status
  - private account status
  - secUid
  - profile picture
  - followers
  - following
  - likes
  - video count
- Attempt video metadata extraction
- Normalize TikTok video objects
- Extract video URLs, descriptions, stats, covers, hashtags, music data, and author info
- Sort videos by latest, views, or likes
- Supports captured cURL replay
- Supports saved request templates
- Supports HAR snapshot parsing
- Supports request mirror fallback logic
- Saves debug HTML / JSON snapshots
- Returns clean JSON API responses
- Built with strict PHP typing
- No database required
- Designed for shared PHP hosting

---

## Why This Project Is Different

TikTok scraping is not only about sending a basic HTTP request. TikTok uses dynamic web payloads, browser-specific headers, cookies, internal API endpoints, and changing response structures.

This project was built with a more advanced scraping approach:

- HTML payload extraction instead of fragile DOM scraping
- Recursive JSON searching for TikTok item lists
- Browser-like cURL requests
- Debug-first development structure
- Captured request replay support
- Fallback endpoint testing
- Clean normalization layer for unstable TikTok responses

The goal is not only to fetch data, but to understand how TikTok responds, debug failures, and keep the scraper adaptable.

---

## API Usage

### Endpoint

```http
POST /api.php
```

### Request Example

```json
{
  "username": "tiktok",
  "include_videos": 1,
  "video_limit": 20,
  "video_sort": "latest",
  "save_debug": 1
}
```

### cURL Example

```bash
curl -X POST https://your-domain.com/api.php \
  -H "Content-Type: application/json" \
  -d '{
    "username": "tiktok",
    "include_videos": 1,
    "video_limit": 20,
    "video_sort": "latest"
  }'
```

---

## Example Response

```json
{
  "success": true,
  "source": "profile_only",
  "username": "tiktok",
  "unique_id": "tiktok",
  "nickname": "TikTok",
  "bio": "Example bio",
  "region": "US",
  "user_id": "123456789",
  "verified": true,
  "private_account": false,
  "sec_uid": "MS4wLjABAAAA...",
  "profile_pic": "https://...",
  "profile_url": "https://www.tiktok.com/@tiktok",
  "stats": {
    "followers": "80m",
    "following": "900",
    "likes": "400m",
    "videos": 1000
  },
  "raw_stats": {
    "followers": 80000000,
    "following": 900,
    "likes": 400000000,
    "videos": 1000
  },
  "videos_found": 0,
  "video_urls": [],
  "videos": [],
  "errors": [],
  "debug": {}
}
```

---

## Request Parameters

| Parameter | Type | Default | Description |
|---|---:|---:|---|
| `username` | string | required | TikTok username without or with `@` |
| `include_videos` | boolean | `1` | Try to fetch public videos |
| `video_limit` | integer | `20` | Number of videos to return, max `100` |
| `video_sort` | string | `latest` | Sort videos by `latest`, `views`, or `likes` |
| `save_debug` | boolean | `1` | Save debug HTML / JSON snapshots |
| `use_saved_capture` | boolean | `1` | Try using saved captured request template |
| `use_har_snapshot` | boolean | `0` | Try loading video data from HAR/debug snapshot |
| `use_request_mirror_fallback` | boolean | `1` | Try mirror fallback strategies |
| `captured_curl` | string | empty | Optional browser-captured cURL command |
| `captured_curl_base64` | string | empty | Optional base64 encoded captured cURL |
| `captured_request_url` | string | empty | Optional captured TikTok API URL |
| `captured_cookie` | string | empty | Optional browser cookie header |
| `captured_user_agent` | string | empty | Optional browser user-agent |
| `captured_referer` | string | empty | Optional referer |
| `captured_headers` | array/string | empty | Optional custom headers |
| `video_urls` | array/string | empty | Optional manual TikTok video URLs fallback |

---

## Core Scraping Logic

The scraper uses multiple layers:

### 1. Profile HTML Fetch

The API requests:

```txt
https://www.tiktok.com/@username
```

It uses browser-like headers and cURL cookie handling to receive the public profile page.

### 2. Hydration Payload Extraction

The scraper extracts TikTok’s embedded JSON payload:

```txt
__UNIVERSAL_DATA_FOR_REHYDRATION__
```

Then it reads:

```txt
__DEFAULT_SCOPE__ -> webapp.user-detail -> userInfo
```

This is where most reliable public profile data is found.

### 3. Video Item Normalization

When video data is available, the scraper normalizes unstable TikTok structures into a clean format:

```json
{
  "id": "video_id",
  "url": "https://www.tiktok.com/@username/video/video_id",
  "desc": "Video description",
  "created_at": "YYYY-MM-DD HH:MM:SS",
  "stats": {
    "views": 0,
    "likes": 0,
    "comments": 0,
    "shares": 0
  },
  "video": {
    "duration": 0,
    "cover": "https://...",
    "play_urls": []
  },
  "author": {
    "username": "username",
    "nickname": "nickname",
    "verified": false
  }
}
```

### 4. Fallback Strategies

The project includes multiple fallback layers:

- direct profile payload
- TikTok item list endpoint
- captured cURL replay
- saved request template replay
- HAR snapshot parsing
- request mirror fallback
- manual video URL fallback

---

## Important Notes

TikTok changes its web structure and API protections frequently. Profile data is usually more reliable than video list extraction.

Video list endpoints may require browser-generated cookies, tokens, or signatures. This API attempts to work around that using captured request replay and fallback strategies, but video extraction is best-effort and may not work for every profile or hosting environment.

For stable production systems, use this as a research/debug-first scraping API and keep debug snapshots enabled during development.

---

## Requirements

- PHP 8.0+
- cURL extension enabled
- JSON extension enabled
- Write permission for debug directory if `save_debug` is enabled

No Node.js is required for the PHP-only scraping flow.

---

## Installation

1. Upload `api.php` to your PHP hosting.
2. Make sure PHP cURL is enabled.
3. Send a POST request with a TikTok username.
4. Read the JSON response.

Example folder:

```txt
/public_html/
  api.php
  debug_tiktok/
```

If debug saving is enabled, make sure the debug folder is writable.

---

## Security Notes

If you expose this API publicly, add:

- rate limiting
- API key protection
- input validation
- debug file access blocking
- request logging
- CORS restrictions
- hosting-level firewall rules

Recommended Apache protection for debug files:

```apache
<Directory "debug_tiktok">
  Require all denied
</Directory>
```

Or place debug files outside the public web root.

---

## Disclaimer

This project is for educational, research, and public web data analysis purposes only. Use responsibly and respect platform terms, privacy, and applicable laws. Do not use this project to collect private data, bypass authentication, or abuse TikTok services.

---

## Author

Built by: **Wajd Dev**  
GitHub: [@its-wajd](https://github.com/its-wajd)

