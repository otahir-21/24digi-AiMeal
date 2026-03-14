# Hosting & Flutter App Integration Guide

## Do you need to host it?

**Yes.** Your Flutter app runs on the user’s device and needs to call your API over the internet. That means the Laravel backend must be deployed and reachable at a **public HTTPS URL** (e.g. `https://ai-meals.yourdomain.com`).

---

## Step 1: Host the Laravel API

### Option A: Simple / cost-effective
- **Laravel Forge + DigitalOcean/Linode/Vultr** – managed Laravel on a VPS (~$5–12/mo server + Forge if you use it).
- **Railway / Render / Fly.io** – deploy from GitHub; good for quick staging/small production.
- **Shared hosting (e.g. cPanel)** – only if it supports PHP 8.1+, Composer, and long-running requests (needed for SSE streaming).

### Option B: Scalable / production
- **AWS / GCP / Azure** – EC2, Cloud Run, or similar with PHP runtime.
- **Ploi.io** – Laravel-focused hosting (alternative to Forge).

### What you must do on the server
1. **PHP 8.1+**, Composer, MySQL/MariaDB (or your DB from `config/database.php`).
2. **Environment**: Copy `.env.example` to `.env`, set:
   - `APP_URL` = your API base URL (e.g. `https://ai-meals.24digi.ae`)
   - `DB_*`, `OPENAI_API_KEY`, and any NestJS/API keys from `DEPLOYMENT_GUIDE.md`.
3. **Run**:
   ```bash
   composer install --no-dev
   php artisan key:generate
   php artisan migrate
   php artisan config:cache
   ```
4. **Web server**: Point document root to `public/`. Enable HTTPS (e.g. Let’s Encrypt).
5. **Streaming**: Ensure the server/nginx doesn’t buffer responses (needed for SSE). For nginx, you may need:
   ```nginx
   proxy_buffering off;
   proxy_read_timeout 86400s;
   proxy_send_timeout 86400s;
   ```
   for the stream route.

Your API base URL will be something like:  
`https://your-domain.com`  
and the mobile API base will be:  
`https://your-domain.com/api/v1/mobile`.

---

## Step 2: Use the API from Flutter

### 2.1 Base URL in the app

Create a config (e.g. `lib/config/api_config.dart` or env):

```dart
class ApiConfig {
  static const String baseUrl = 'https://your-domain.com/api/v1/mobile';
  // For local dev: 'http://10.0.2.2:8000/api/v1/mobile' (Android emulator)
  // Or your machine IP for a real device, e.g. 'http://192.168.1.x:8000/api/v1/mobile'
}
```

Use this for all API calls. No auth is required for the mobile API (user is identified by body metrics).

### 2.2 Main flow from Flutter

1. **Generate meals**  
   - `POST $baseUrl/generate-meals`  
   - Body: `device_id`, `age`, `height`, `weight`, `gender`, `activity_level`, `neck_circumference`, `waist_circumference`, `hip_circumference` (required for female), optional `plan_period` (7 or 30).  
   - Response: `session_id`, `stream_url`, `user_metrics`, etc.

2. **Stream progress (SSE)**  
   - `GET $baseUrl/stream/{session_id}` (full URL: `https://your-domain.com/api/v1/mobile/stream/{session_id}`).  
   - Use an SSE client in Flutter (e.g. `sse` or `eventsource` package, or `http` with stream).  
   - Parse events: `connected`, `status`, `day_progress`, `meal_data`, `complete`, `error`.

3. **Other endpoints** (see `API_DOCUMENTATION.md`):  
   - Get session status, download PDF, schedule delivery, consumption, etc.  
   - All under the same base URL; no auth headers unless you add them later.

### 2.3 Example: POST generate-meals (Dart)

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';

Future<Map<String, dynamic>> generateMeals({
  required int age,
  required double height,
  required double weight,
  required String gender,
  required String activityLevel,
  required double neckCircumference,
  required double waistCircumference,
  double? hipCircumference,
  int planPeriod = 30,
  String? deviceId,
}) async {
  final url = Uri.parse('$baseUrl/generate-meals');
  final body = {
    'age': age,
    'height': height,
    'weight': weight,
    'gender': gender,
    'activity_level': activityLevel,
    'neck_circumference': neckCircumference,
    'waist_circumference': waistCircumference,
    if (hipCircumference != null) 'hip_circumference': hipCircumference,
    'plan_period': planPeriod,
    if (deviceId != null) 'device_id': deviceId,
  };
  final response = await http.post(
    url,
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: jsonEncode(body),
  );
  return jsonDecode(response.body) as Map<String, dynamic>;
}
```

### 2.4 Example: SSE stream (Dart)

Use a package like `sse` or handle a streaming `http.Client()` request and parse `data:` lines and `event:` types. Match event names to the API docs (`status`, `meal_data`, `complete`, etc.).

---

## Step 3: Optional – NestJS (delivery) backend

If you use the **delivery/scheduling** features, the deployment guide expects:

1. Laravel deployed first (e.g. `https://ai-meals.24digi.ae`).
2. NestJS deployed second (e.g. `https://api.24digi.ae`).
3. Configure Laravel `.env`: `NESTJS_API_BASE_URL`, `NESTJS_API_KEY`, etc.
4. Configure NestJS `.env`: `LARAVEL_BASE_URL`, `LARAVEL_WEBHOOK_URL`, etc.

Your Flutter app then uses the **Laravel** base URL for meal generation and streaming; delivery endpoints are also under the same Laravel API (`/api/v1/mobile/...`).

---

## Quick checklist

- [ ] Server with PHP 8.1+, Composer, DB, HTTPS.
- [ ] Laravel deployed, `.env` set, `migrate` run, document root = `public/`.
- [ ] SSE route not buffered (long timeouts, `proxy_buffering off` if using nginx).
- [ ] Flutter: `ApiConfig.baseUrl` = your production URL.
- [ ] Flutter: POST `generate-meals` → then GET `stream/{session_id}` for live updates.
- [ ] (Optional) NestJS deployed and env vars set if you use delivery features.

For full request/response shapes and all endpoints, use `API_DOCUMENTATION.md`. For server/env details, use `DEPLOYMENT_GUIDE.md`.
