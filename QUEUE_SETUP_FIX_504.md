# Fix 504 on POST /generate-meals/start – Use a Real Queue

## Why you get 504 on “start”

With **`QUEUE_CONNECTION=sync`** (Laravel default), **the job runs in the same HTTP request**. So when the app calls:

`POST /api/v1/mobile/generate-meals/start`

Laravel dispatches the meal generation job and **waits for it to finish** (all 7 or 28 days of AI generation) before sending a response. That can take many minutes, so Nginx returns **504 Gateway Time-out** after ~60 seconds.

To get a response in **under 2 seconds**, the job must run **in the background**. For that you need a **non-sync queue** and a **worker**.

---

## Fix (on the server where the API runs)

### 1. Use the database queue

On the server, in your **`.env`** file, set:

```env
QUEUE_CONNECTION=database
```

(If the key is missing, add it. Then clear config cache: `php artisan config:clear`.)

### 2. Create the `jobs` table (if not already)

```bash
cd /var/www/24digi-AiMeal
php artisan migrate --force
```

This runs all migrations, including `create_jobs_table`. If you already ran migrations before, skip or run only if the `jobs` table is missing.

### 3. Run the queue worker

The worker must be running so that jobs are processed in the background:

```bash
php artisan queue:work
```

Leave this running (or run it in the background / under a process manager). While it runs, when a request hits **POST /generate-meals/start**, Laravel will push a job to the `jobs` table and return immediately with `session_id`. The worker will pick up the job and generate meals in the background.

**Run in background (example):**

```bash
nohup php artisan queue:work > storage/logs/queue.log 2>&1 &
```

**Or use Supervisor (recommended on production)** so the worker restarts if it crashes. Example config `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/24digi-AiMeal/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/24digi-AiMeal/storage/logs/worker.log
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker
```

### 4. Reload your app config (after changing .env)

```bash
php artisan config:clear
php artisan config:cache
```

---

## Summary

| Step | Action |
|------|--------|
| 1 | In `.env` set **`QUEUE_CONNECTION=database`** |
| 2 | Run **`php artisan migrate --force`** (if `jobs` table not present) |
| 3 | Start worker: **`php artisan queue:work`** (keep it running or use Supervisor) |
| 4 | **`php artisan config:clear`** (and optionally **`config:cache`**) |

After this, **POST /api/v1/mobile/generate-meals/start** should return in under 2 seconds with `session_id`, and meal generation will run in the background. The Flutter app can then poll **POST /generate-meals/status** with that `session_id`.
