# Fix "Server Error" (500) – Find the real error

When you get `{"message": "Server Error"}` the real exception is in the Laravel log. Do this **on the server** (e.g. SSH into the EC2 instance).

---

## 1. See the real error in the log

```bash
cd /var/www/24digi-AiMeal
tail -100 storage/logs/laravel.log
```

Or watch the log while you call the API (from Postman or Flutter):

```bash
tail -f storage/logs/laravel.log
```

Then send **POST** `/api/v1/mobile/generate-meals/start` again. The log will show the exception message and file/line (e.g. `SQLSTATE`, `env()`, `AI_API_KEY`, etc.). Use that to fix the cause below.

---

## 2. (Optional) Get the error in the API response

**Only for debugging.** In `.env` set:

```env
APP_DEBUG=true
```

Then call the API again. The response may include the exception message and stack trace. **Set `APP_DEBUG=false` again** when you’re done (required in production).

---

## 3. Common causes and fixes

### Database (RDS / MySQL)

- **Error type:** `SQLSTATE`, "Connection refused", "Access denied", "Unknown database"
- **Fix:** In `.env` set:
  - `DB_HOST=` your RDS endpoint
  - `DB_DATABASE=ai_meals`
  - `DB_USERNAME=admin`
  - `DB_PASSWORD=` (from Secrets Manager)
- Then:
  ```bash
  php artisan config:clear
  php artisan migrate --force
  ```

### OpenAI / AI API key

- **Error type:** "OpenAI API request failed", 401, or empty key
- **Fix:** The app uses **`AI_API_KEY`** for the meal generation job. In `.env` set:
  ```env
  AI_API_KEY=sk-your-openai-api-key
  ```
  (Same as your OpenAI API key.) Then `php artisan config:clear`.

### Queue worker and “Server Error”

- If the **start** request returns 200 with `session_id` but the **job** fails, the worker will log the error. Check:
  ```bash
  tail -100 storage/logs/laravel.log
  ```
  and/or the **failed_jobs** table:
  ```bash
  php artisan tinker
  >>> \DB::table('failed_jobs')->latest()->first();
  ```

### Permissions

- **Error type:** "Permission denied" on `storage/` or `bootstrap/cache/`
- **Fix:**
  ```bash
  sudo chown -R www-data:www-data /var/www/24digi-AiMeal/storage /var/www/24digi-AiMeal/bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache
  ```

### Missing APP_KEY

- **Fix:** In `.env` run:
  ```bash
  php artisan key:generate
  ```

---

## 4. Quick checklist on the server

```bash
cd /var/www/24digi-AiMeal
php artisan config:clear
php artisan cache:clear
# Check .env has: APP_KEY, DB_*, QUEUE_CONNECTION=database, AI_API_KEY
tail -50 storage/logs/laravel.log
```

Use the last lines of `laravel.log` after reproducing the "Server Error" to see the exact exception and fix the matching cause above.
