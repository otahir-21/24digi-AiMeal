# Flutter Developer Guide – AI Meal API

**For:** Frontend Flutter developer  
**Backend:** Laravel API (this repo) – already running.

---

## 1. Base URL & headers

- **Base URL:** `https://YOUR-DOMAIN.com/api/v1/mobile`  
  *(Replace `YOUR-DOMAIN.com` with the actual API host, e.g. your AWS URL.)*
- **Auth:** None. No token or login required.
- **Headers for all requests:**
  ```dart
  'Content-Type': 'application/json'
  'Accept': 'application/json'
  ```

### Timeouts & 504

- **POST generate-meals** can take **1–3+ minutes** (AI generation). Set a long HTTP client timeout (e.g. **5–10 minutes**) so the app doesn’t cancel the request. Show a clear “Generating your plan…” state.
- **GET stream/{session_id}** is a long-lived SSE connection. Keep it open; don’t use a short timeout.
- If you get **504 Gateway Time-out**: the server is closing the connection too early. Backend must extend Nginx/PHP timeouts (see **NGINX_504_TIMEOUT_FIX.md** in the repo). After that’s fixed, your long timeout on `generate-meals` will be enough.

---

## 2. APIs you need (in order of use)

### Quick reference

| # | Method | Endpoint | When to use |
|---|--------|----------|-------------|
| 1 | POST | `/generate-meals` | Start AI meal generation (can timeout; prefer polling flow below) |
| 1b | POST | `/generate-meals/start` | **Start generation (returns in &lt;2s)** – use with polling |
| 2b | POST | `/generate-meals/status` | **Poll for progress + JSON meal_data** (no HTML, no 504) |
| 2 | GET | `/stream/{session_id}` | Live progress + meal data (SSE) |
| 3 | GET | `/session/{session_id}/status` | Check/resume session |
| 4 | GET | `/meal-plan/{user_identifier}` | Get full meal plan (after complete) |
| 5 | GET | `/meal-plan/{user_identifier}/pdf` | Download PDF |
| 6 | POST | `/meals/{session_id}/approve` | User approves plan |
| 7 | GET | `/check-meals/{device_id}` | Check if user has existing meals |
| 8 | POST | `/meals/{session_id}/schedule-delivery` | Schedule delivery (optional) |
| 9 | GET | `/meals/{session_id}/delivery-status` | Get delivery status (optional) |
| 10 | POST | `/meals/{session_id}/consumption` | Log meal consumption (optional) |

---

## 3. API details

### 3.1 Start meal generation  
**POST** `$baseUrl/generate-meals`

**Body (JSON):**
```json
{
  "device_id": "optional-unique-device-id",
  "age": 25,
  "height": 175,
  "weight": 70,
  "gender": "male",
  "activity_level": "Moderately active (3–5 days/week)",
  "neck_circumference": 38,
  "waist_circumference": 80,
  "hip_circumference": 95,
  "plan_period": 30
}
```

- **Required:** `age`, `height`, `weight`, `gender`, `activity_level`, `neck_circumference`, `waist_circumference`.  
- **Female:** include `hip_circumference`.  
- **activity_level** must be one of:
  - `"Sedentary (little or no exercise)"`
  - `"Lightly active (1–3 days/week)"`
  - `"Moderately active (3–5 days/week)"`
  - `"Very active (6–7 days/week)"`
  - `"Super active (twice/day or physical job)"`
- **plan_period:** `7` or `30` (default 30).

**Alternative: Polling flow (avoids 504)**  
1. **POST** `$baseUrl/generate-meals/start` – same body as above. Returns in **&lt;2 seconds** with `{ "success": true, "session_id": "uuid", "message": "Generation started in the background." }`.  
2. **POST** `$baseUrl/generate-meals/status` – body `{ "session_id": "uuid" }`. Poll every 2–3 seconds. Response is **pure JSON** (no HTML): `completed`, `day_completed`, `total_days`, `progress`, and `meal_data` keyed by day number (e.g. `"1": { "daily_total_cal", "daily_total_cost", "meals": [...] }`). Use this for native Flutter UI.

**Success (200) for POST generate-meals:**
```json
{
  "success": true,
  "data": {
    "session_id": "uuid-here",
    "stream_url": "/api/v1/mobile/stream/uuid-here",
    "user_id": "usr_xxx",
    "user_metrics": { "bmi": 22.86, "bmr": 1680, "tdee": 2604, "body_fat": 15.2, "goal": "maintain" }
  }
}
```

Use `data.session_id` for the stream and all other session APIs.

---

### 3.2 Stream progress (SSE)  
**GET** `$baseUrl/stream/{session_id}`

- Server-Sent Events (text/event-stream).  
- Use a package like **`sse`** or **`eventsource`** (or raw `http` stream) and parse `event:` and `data:` lines.

**Events to handle:**

| Event         | When        | Use |
|---------------|-------------|-----|
| `connected`   | On connect  | Show “Connected” |
| `status`      | Progress    | Show message + progress % |
| `day_progress`| Per day     | Update “Day X of Y” |
| `meal_data`   | Per day     | **Meals for one day** – update UI |
| `day_complete`| Day done    | Update progress bar |
| `complete`    | All done    | Save plan, close stream |
| `error`       | Failure     | Show error, close stream |
| `heartbeat`   | Keep-alive  | Optional |

**Example `meal_data` payload:**  
`data` is JSON: `{ "day": 1, "meals": [ {...}, ... ], "daily_total": { "calories": 1850, ... } }`.

**Example `complete` payload:**  
`data` is JSON: `{ "status": "completed", "total_days": 30, "meal_plan_id": "...", "summary": { ... } }`.

---

### 3.3 Session status (resume/recovery)  
**GET** `$baseUrl/session/{session_id}/status`

**Success (200):**
```json
{
  "success": true,
  "data": {
    "session_id": "uuid",
    "status": "pending|processing|completed|failed",
    "current_day": 15,
    "total_days": 30,
    "progress": 50.0,
    "error_message": null
  }
}
```

If `status == "processing"` → reconnect to stream.  
If `status == "completed"` → call meal-plan API.

---

### 3.4 Get full meal plan  
**GET** `$baseUrl/meal-plan/{user_identifier}`

- **user_identifier:** `user_id` from generate-meals response (e.g. `usr_xxx`) or `device_id`.

**Success (200):**  
`data` contains `session_id`, `meal_plan` (array of days, each day array of meals), `daily_totals`, `summary`, `generated_at`.

---

### 3.5 Download PDF  
**GET** `$baseUrl/meal-plan/{user_identifier}/pdf`

- Same `user_identifier` as above.  
- Response: binary PDF (`Content-Type: application/pdf`).  
- In Flutter: get as bytes and save / open with file picker or share.

---

### 3.6 Approve meal plan  
**POST** `$baseUrl/meals/{session_id}/approve`

**Body (JSON):**  
Can be empty `{}` or include any extra fields.  
**Success:** `{ "success": true, ... }`.  
Call after user taps “Approve” on the generated plan.

---

### 3.7 Check if user has meals  
**GET** `$baseUrl/check-meals/{device_id}`

**Success (200):**  
Response indicates whether the user has an existing meal plan (e.g. show “View existing plan” vs “Generate new plan”).

---

### 3.8 Delivery (optional)  
If you integrate delivery:

- **POST** `$baseUrl/meals/{session_id}/schedule-delivery` – body per backend spec.  
- **GET** `$baseUrl/meals/{session_id}/delivery-status` – get status.  
- **POST** `$baseUrl/meals/{session_id}/consumption` – log consumption.

Details: see **API_DOCUMENTATION.md** or ask backend.

---

## 4. Recommended Flutter flow

1. **App start / “Generate plan”**
   - Optionally call **check-meals** with `device_id` to show “You have an existing plan” vs “Generate new”.
2. **User fills form** (age, height, weight, gender, activity, circumferences).
3. **POST generate-meals** with that payload + `device_id` (and optional `plan_period`).
4. **On success:** open **GET stream/{session_id}** (SSE).
5. **On each `meal_data`:** update UI with that day’s meals.
6. **On `complete`:** close stream, optionally call **meal-plan** to get full plan, show “Approve”.
7. **User approves:** **POST meals/{session_id}/approve**.
8. **View plan later:** **GET meal-plan/{user_identifier}**.
9. **Download PDF:** **GET meal-plan/{user_identifier}/pdf**.

---

## 5. Errors

- **422:** Validation error. Response has `errors` map (field → list of messages). Show in form.
- **5xx:** Server error. Show generic “Something went wrong” and retry option.
- **SSE closes unexpectedly:** Call **session status**; if still `processing`, reconnect to stream.

---

## 6. Full API reference

For full request/response shapes, validation rules, and delivery/webhook details:  
**API_DOCUMENTATION.md** in this repo.

---

## 7. Base URL for Flutter

Ask the backend team for the **live base URL**, then in Flutter use a single config, e.g.:

```dart
class ApiConfig {
  static const String baseUrl = 'https://YOUR-API-DOMAIN.com/api/v1/mobile';
}
```

Use `ApiConfig.baseUrl` for every request above (e.g. `$baseUrl/generate-meals` → `ApiConfig.baseUrl + '/generate-meals'`).
