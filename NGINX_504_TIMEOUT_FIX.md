# Fix 504 Gateway Time-out for C-by-AI Meal API

The Flutter app now uses a **10-minute** HTTP timeout for `POST /api/v1/mobile/generate-meals`.  
The **504** you see is from **Nginx** (and/or PHP-FPM) closing the connection before the Laravel API finishes.  
Apply these changes **on the server** where the API is hosted.

---

## 1. Nginx – longer timeouts

Edit your site config (e.g. `/etc/nginx/sites-available/your-app` or the `server { }` block that serves the Laravel `public` folder).

### Option A: Single block for all PHP (simplest)

Find:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    # ... other directives
}
```

**Add inside that block** (adjust PHP version if needed, e.g. `php8.1-fpm.sock`):

```nginx
# Prevent 504 for long-running API (meal generation can take 1–3+ min)
fastcgi_read_timeout 1800;
fastcgi_send_timeout 1800;
fastcgi_connect_timeout 60;
# For SSE stream, disable buffering (helps both generate-meals and stream)
fastcgi_buffering off;
```

### Option B: Dedicated block for the stream (SSE)

If the **stream** also times out, add a **separate** location **before** the general `location ~ \.php$`:

```nginx
# Long-lived SSE stream (up to 1 hour)
location ~ ^/api/v1/mobile/stream/.+ {
    try_files $uri $uri/ /index.php?$query_string;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
        fastcgi_connect_timeout 60;
    }
}
```

**Note:** If the stream still gets 504, use Option A only (single block with `fastcgi_read_timeout 1800` and `fastcgi_buffering off`) — that usually fixes both generate-meals and stream.

Then **reload Nginx**:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## 2. PHP-FPM – allow long requests

Otherwise PHP may kill the request before Nginx.

**Edit pool config** (e.g. `/etc/php/8.2/fpm/pool.d/www.conf`):

```ini
; Allow a single request to run up to 30 minutes
request_terminate_timeout = 1800
```

Or in **php.ini** (e.g. `/etc/php/8.2/fpm/php.ini`):

```ini
max_execution_time = 1800
```

**Restart PHP-FPM**:

```bash
sudo systemctl restart php8.2-fpm
```

(Use your PHP version: `php8.1-fpm`, etc.)

---

## 3. Summary

| Where    | Setting                      | Value                          |
|----------|------------------------------|--------------------------------|
| Nginx    | `fastcgi_read_timeout`       | 1800 (30 min) for API          |
| Nginx    | `fastcgi_send_timeout`       | 1800                           |
| Nginx    | Stream only: `fastcgi_read_timeout` | 3600 (1 hour)           |
| Nginx    | Stream: `fastcgi_buffering`   | off                            |
| PHP-FPM  | `request_terminate_timeout`  | 1800                           |

After this, **POST generate-meals** and **GET stream/{id}** should stop returning 504 from the server.  
The Flutter app is already configured to wait long enough; the remaining fix is **only on the server**.
