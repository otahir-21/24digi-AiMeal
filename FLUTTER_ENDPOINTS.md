# API Endpoints for Flutter – C-by-AI Meal

**Base URL (your server):**  
`http://16.170.207.64/api/v1/mobile`

**Headers for every request:**  
`Content-Type: application/json`  
`Accept: application/json`  

**No authentication.** Use these exact URLs from the Flutter app.

---

## Endpoints to call (in order)

### 1. Start meal generation (call first)

**POST**  
`http://16.170.207.64/api/v1/mobile/generate-meals/start`

**Body (JSON):**
```json
{
  "device_id": "b3f3cca9-a11d-43f2-b835-50aed568b8bb",
  "age": 25,
  "height": 190,
  "weight": 90,
  "gender": "male",
  "activity_level": "Lightly active (1-3 days/week)",
  "neck_circumference": 38,
  "waist_circumference": 80,
  "hip_circumference": 95,
  "plan_period": 7
}
```

**Important:**  
- `gender` must be **lowercase**: `"male"` or `"female"` (not `"Male"`).  
- `activity_level` must be **exactly** one of:
  - `"Sedentary (little or no exercise)"`
  - `"Lightly active (1-3 days/week)"`
  - `"Moderately active (3-5 days/week)"`
  - `"Very active (6-7 days/week)"`
  - `"Super active (twice/day or physical job)"`  
  (Not `"Lightly Active"` – use the full string with parentheses.)

**Success (200):**  
`{ "success": true, "session_id": "uuid-here", "message": "Generation started in the background." }`  

Use `session_id` for the next calls.

---

### 2. Poll status (call every 2–3 seconds until completed)

**POST**  
`http://16.170.207.64/api/v1/mobile/generate-meals/status`

**Body (JSON):**
```json
{
  "session_id": "paste-session_id-from-step-1"
}
```

**Success (200):**  
JSON with `completed`, `day_completed`, `total_days`, `progress`, `meal_data` (days "1", "2", … with meals). Stop polling when `completed == true`.

---

### 3. Get session status (optional – e.g. after app restart)

**GET**  
`http://16.170.207.64/api/v1/mobile/session/{session_id}/status`

Example: `http://16.170.207.64/api/v1/mobile/session/550e8400-e29b-41d4-a716-446655440000/status`

---

### 4. Check if user has existing meals

**GET**  
`http://16.170.207.64/api/v1/mobile/check-meals/{device_id}`

Example: `http://16.170.207.64/api/v1/mobile/check-meals/b3f3cca9-a11d-43f2-b835-50aed568b8bb`

---

### 5. Get full meal plan (after generation completed)

**GET**  
`http://16.170.207.64/api/v1/mobile/meal-plan/{user_identifier}`

Replace `{user_identifier}` with `user_id` from start response or `device_id`.  
Example: `http://16.170.207.64/api/v1/mobile/meal-plan/b3f3cca9-a11d-43f2-b835-50aed568b8bb`

---

### 6. Download PDF

**GET**  
`http://16.170.207.64/api/v1/mobile/meal-plan/{user_identifier}/pdf`

Same `user_identifier` as above. Response is PDF bytes.

---

### 7. Approve meal plan (when user taps Approve)

**POST**  
`http://16.170.207.64/api/v1/mobile/meals/{session_id}/approve`

**Body (JSON):** `{}` or any JSON.

Example URL: `http://16.170.207.64/api/v1/mobile/meals/550e8400-e29b-41d4-a716-446655440000/approve`

---

## Quick reference table

| What to do              | Method | Full URL |
|-------------------------|--------|----------|
| Start generation        | POST   | `http://16.170.207.64/api/v1/mobile/generate-meals/start` |
| Poll status             | POST   | `http://16.170.207.64/api/v1/mobile/generate-meals/status` |
| Session status          | GET    | `http://16.170.207.64/api/v1/mobile/session/{session_id}/status` |
| Check existing meals    | GET    | `http://16.170.207.64/api/v1/mobile/check-meals/{device_id}` |
| Get full meal plan      | GET    | `http://16.170.207.64/api/v1/mobile/meal-plan/{user_identifier}` |
| Download PDF            | GET    | `http://16.170.207.64/api/v1/mobile/meal-plan/{user_identifier}/pdf` |
| Approve plan            | POST   | `http://16.170.207.64/api/v1/mobile/meals/{session_id}/approve` |

---

## Fix for 404 “route generate-meals could not be found”

The app must call the **full path** including `/api/v1/mobile`.  

**Wrong:**  
`http://16.170.207.64/generate-meals` or `http://16.170.207.64/generate-meals/start`  

**Correct:**  
`http://16.170.207.64/api/v1/mobile/generate-meals/start`  

In Flutter, set base URL to **`http://16.170.207.64/api/v1/mobile`** and then use path **`generate-meals/start`** (so full URL = base + `/generate-meals/start`).

---

## Flutter config example

```dart
class ApiConfig {
  static const String baseUrl = 'http://16.170.207.64/api/v1/mobile';
}

// Start: POST ApiConfig.baseUrl + '/generate-meals/start'
// Status: POST ApiConfig.baseUrl + '/generate-meals/status'
```
