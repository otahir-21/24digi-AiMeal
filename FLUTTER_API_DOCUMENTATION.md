# C-by-AI Meal API – Flutter Developer Documentation

**Audience:** Flutter / mobile frontend developer  
**API:** Laravel backend – AI meal plan generation. All responses are **JSON only** (no HTML).

---

## 1. Configuration

### Base URL
Get the live API base URL from the backend team, then set it once in your app:

```dart
class ApiConfig {
  static const String baseUrl = 'https://YOUR-API-DOMAIN.com/api/v1/mobile';
}
```

### Headers (every request)
```dart
headers: {
  'Content-Type': 'application/json',
  'Accept': 'application/json',
}
```

### Authentication
**None.** No token or login. User is identified by body metrics and optional `device_id`.

---

## 2. Recommended flow: Polling (no 504)

Use this flow so the app never waits on a long HTTP request. All responses return in under a few seconds.

| Step | Action |
|------|--------|
| 1 | User fills form (age, height, weight, gender, activity, circumferences). |
| 2 | **POST** `/generate-meals/start` with body → get `session_id` (response in **&lt;2 seconds**). |
| 3 | Poll **POST** `/generate-meals/status` every **2–3 seconds** with `session_id`. |
| 4 | On each response, update UI from `progress` and `meal_data` (pure JSON). |
| 5 | When `completed == true`, stop polling. Full plan is in `meal_data`. |
| 6 | Optionally **POST** `/meals/{session_id}/approve` when user approves. |
| 7 | Later: **GET** `/meal-plan/{user_identifier}` to view plan, **GET** `.../pdf` to download PDF. |

---

## 3. Endpoints

### 3.1 Start meal generation (polling flow)

**POST** `$baseUrl/generate-meals/start`

Starts AI meal generation in the background. Returns immediately with a `session_id`. No long timeout needed.

**Request body (JSON):**
```json
{
  "device_id": "optional-unique-device-id",
  "age": 25,
  "height": 190,
  "weight": 90,
  "gender": "male",
  "activity_level": "Lightly active (1-3 days/week)",
  "waist_circumference": 40,
  "neck_circumference": 16,
  "hip_circumference": 38,
  "plan_period": 7
}
```

**Required fields:** `age`, `height`, `weight`, `gender`, `activity_level`, `neck_circumference`, `waist_circumference`.  
**Female:** include `hip_circumference`.  
**plan_period:** `7`, `14`, `21`, or `28` (default `7`).

**activity_level** – use exactly one of:
- `"Sedentary (little or no exercise)"`
- `"Lightly active (1-3 days/week)"`
- `"Moderately active (3-5 days/week)"`
- `"Very active (6-7 days/week)"`
- `"Super active (twice/day or physical job)"`

**Success (200):**
```json
{
  "success": true,
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "message": "Generation started in the background."
}
```

**Use `session_id`** for all subsequent calls (status, approve, etc.).

**Validation error (422):**
```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "age": ["The age field is required."],
    "weight": ["The weight must be between 20 and 500."]
  }
}
```

---

### 3.2 Poll generation status (polling flow)

**POST** `$baseUrl/generate-meals/status`

Call every 2–3 seconds with the `session_id` from start. Response is **pure JSON** (no HTML). Use `meal_data` to build your native UI (lists, calendar, etc.).

**Request body (JSON):**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "current_day": 1
}
```

`current_day` is optional (backend can ignore it; progress is server-driven).

**Success (200):**
```json
{
  "success": true,
  "completed": false,
  "day_completed": 3,
  "total_days": 7,
  "progress": 42.9,
  "status": "processing",
  "meal_data": {
    "1": {
      "daily_total_cal": 1834,
      "daily_total_cost": 49,
      "meals": [
        {
          "type": "morning drink",
          "name": "Black Coffee",
          "time": "06:30",
          "total_cal": 2,
          "total_price": 1,
          "ingredients": [
            {
              "name": "Black Coffee",
              "amount": "1 cup",
              "price": 1,
              "cal": 2,
              "protein": 0,
              "carbs": 0,
              "fat": 0
            }
          ]
        },
        {
          "type": "breakfast",
          "name": "Protein Pancakes",
          "time": "07:00",
          "total_cal": 420,
          "total_price": 5,
          "ingredients": [
            {
              "name": "Oats",
              "amount": "100g",
              "price": 2,
              "cal": 389,
              "protein": 16.9,
              "carbs": 66.3,
              "fat": 6.9
            }
          ]
        }
      ]
    },
    "2": { "daily_total_cal": 1850, "daily_total_cost": 52, "meals": [ ... ] },
    "3": { "daily_total_cal": 1820, "daily_total_cost": 48, "meals": [ ... ] }
  }
}
```

- **completed** – `true` when generation is fully done; then stop polling.
- **day_completed** – last day number completed (1-based).
- **total_days** – plan length (e.g. 7 or 28).
- **progress** – percentage (0–100).
- **status** – `"pending"`, `"processing"`, `"completed"`, or `"failed"`.
- **meal_data** – object keyed by day number (`"1"`, `"2"`, …). Each day has:
  - **daily_total_cal** – total calories for the day.
  - **daily_total_cost** – total price for the day.
  - **meals** – array of meals, each with:
    - **type** – e.g. `"breakfast"`, `"lunch"`, `"dinner"`, `"snack"`, `"morning drink"`.
    - **name** – meal name.
    - **time** – e.g. `"07:00"`.
    - **total_cal**, **total_price** – for the meal.
    - **ingredients** – array of `{ name, amount, price, cal, protein, carbs, fat }`.

**Session not found (404):**
```json
{
  "success": false,
  "message": "Session not found"
}
```

---

### 3.3 Get session status (optional)

**GET** `$baseUrl/session/{session_id}/status`

Use for resume/recovery (e.g. after app restart). Same `session_id` as above.

**Success (200):**
```json
{
  "success": true,
  "data": {
    "session_id": "550e8400-...",
    "status": "processing",
    "current_day": 3,
    "total_days": 7,
    "progress": 42.9,
    "error_message": null
  }
}
```

If `status == "processing"` you can resume polling `/generate-meals/status`. If `status == "completed"` you can load the full plan from the last status response or from `/meal-plan/{user_identifier}`.

---

### 3.4 Check if user has meals

**GET** `$baseUrl/check-meals/{device_id}`

Use to show “View existing plan” vs “Generate new plan”.

**Success (200):** JSON indicating whether the user has an existing meal plan (structure may vary; check `success` and any `has_meals` / `meal_plan` flags).

---

### 3.5 Get full meal plan (after completion)

**GET** `$baseUrl/meal-plan/{user_identifier}`

**user_identifier:** `user_id` from the start response (if returned) or `device_id`.

**Success (200):** JSON with full plan (e.g. `meal_plan`, `daily_totals`, `summary`, `generated_at`). Use to show the full plan screen or cache locally.

---

### 3.6 Download meal plan PDF

**GET** `$baseUrl/meal-plan/{user_identifier}/pdf`

Same `user_identifier` as above. Response is binary PDF (`Content-Type: application/pdf`). In Flutter, read as bytes and save or share (e.g. via share_plus / file picker).

---

### 3.7 Approve meal plan

**POST** `$baseUrl/meals/{session_id}/approve`

**Body:** `{}` or any JSON. Call when the user taps “Approve” on the generated plan.

**Success (200):** `{ "success": true, ... }`

---

### 3.8 Delivery (optional)

- **POST** `$baseUrl/meals/{session_id}/schedule-delivery` – schedule delivery.
- **GET** `$baseUrl/meals/{session_id}/delivery-status` – get delivery status.
- **POST** `$baseUrl/meals/{session_id}/consumption` – log consumption.

Request/response shapes: ask backend or see main API docs.

---

## 4. Quick reference

| Method | Endpoint | Purpose |
|--------|----------|--------|
| POST | `/generate-meals/start` | Start generation → get `session_id` (&lt;2s) |
| POST | `/generate-meals/status` | Poll progress + JSON `meal_data` |
| GET | `/session/{session_id}/status` | Session status (resume/recovery) |
| GET | `/check-meals/{device_id}` | Has user got an existing plan? |
| GET | `/meal-plan/{user_identifier}` | Full meal plan (after complete) |
| GET | `/meal-plan/{user_identifier}/pdf` | Download PDF |
| POST | `/meals/{session_id}/approve` | User approved plan |

---

## 5. Errors

| Code | Meaning | Action in Flutter |
|------|--------|--------------------|
| 422 | Validation error | Show `errors` map on the form (field → list of messages). |
| 404 | Session / user not found | Show “Session expired” or “Not found”, offer to start again. |
| 5xx | Server error | Show “Something went wrong” and retry. |

All error responses are JSON, e.g. `{ "success": false, "message": "...", "errors": { ... } }`.

---

## 6. Dart examples

### Start generation and start polling
```dart
import 'package:http/http.dart' as http;
import 'dart:convert';

Future<String?> startMealGeneration(Map<String, dynamic> body) async {
  final url = Uri.parse('${ApiConfig.baseUrl}/generate-meals/start');
  final response = await http.post(
    url,
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: jsonEncode(body),
  ).timeout(const Duration(seconds: 10));

  final data = jsonDecode(response.body) as Map<String, dynamic>;
  if (data['success'] == true) {
    return data['session_id'] as String?;
  }
  return null;
}
```

### Poll status
```dart
Future<Map<String, dynamic>?> pollStatus(String sessionId) async {
  final url = Uri.parse('${ApiConfig.baseUrl}/generate-meals/status');
  final response = await http.post(
    url,
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: jsonEncode({'session_id': sessionId}),
  ).timeout(const Duration(seconds: 10));

  final data = jsonDecode(response.body) as Map<String, dynamic>;
  if (data['success'] == true) {
    return data;
  }
  return null;
}
```

### Polling loop (e.g. in your provider)
```dart
// After startMealGeneration() returns sessionId:
Timer? pollTimer;
pollTimer = Timer.periodic(const Duration(seconds: 2), (t) async {
  final status = await pollStatus(sessionId);
  if (status == null) return;
  updateProgress(status['progress']);
  updateMealData(status['meal_data']); // Map<String, dynamic> keyed by "1", "2", ...
  if (status['completed'] == true) {
    pollTimer?.cancel();
    onPlanComplete();
  }
  if (status['status'] == 'failed') {
    pollTimer?.cancel();
    showError(status['message'] ?? 'Generation failed');
  }
});
```

---

## 7. Timeouts

- **POST /generate-meals/start** and **POST /generate-meals/status** both return quickly. A **10-second** client timeout per request is enough.
- No long-running HTTP request is needed for the polling flow, so **504 Gateway Time-out** from the server is avoided.
- If you use the older **POST /generate-meals** or **GET /stream/{session_id}** (SSE) instead of polling, you would need longer client timeouts and server (Nginx/PHP) timeout fixes; prefer the polling flow above.

---

**Backend repo:** This document is generated from the Laravel API (C-by-AI Meal). For more detail (e.g. delivery, webhooks), see **API_DOCUMENTATION.md** in the backend repo.
