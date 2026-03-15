# Fix 504 Gateway Timeout – Nginx & PHP

The frontend gets **504 Gateway Time-out** because meal generation and the SSE stream run longer than the default server timeouts (usually 60 seconds). Extend timeouts for the API.

---

## 1. Nginx – extend timeouts for API and stream

Edit your Laravel site config (e.g. `/etc/nginx/sites-available/laravel` or your app’s server block).

### Option A: Longer timeouts for all API routes

Inside the `server { ... }` block that has `root /var/www/.../public;`, add a **location** block for the API **before** the `location ~ \.php$` block:

```nginx
# Long timeouts for API (meal generation can take several minutes)
location /api/ {
    try_files $uri $uri/ /index.php?$query_string;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Extend timeouts (30 minutes)
        fastcgi_read_timeout 1800;
        fastcgi_send_timeout 1800;
        fastcgi_connect_timeout 60;
    }
}

# SSE stream: no buffering, very long read timeout
location /api/v1/mobile/stream/ {
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

If you use a **single** `location ~ \.php$` and no separate `/api/` block, add the timeout directives into that block and add the stream block as below.

### Option B: Minimal change – only in existing `location ~ \.php$`

Find your existing block:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    # ...
}
```

Add these lines inside it:

```nginx
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;

    # Prevent 504 for long-running API (meal generation, stream)
    fastcgi_read_timeout 1800;
    fastcgi_send_timeout 1800;
    fastcgi_connect_timeout 60;

    # For SSE stream, disable buffering (optional; add only if stream still times out)
    fastcgi_buffering off;
}
```

Then add a **dedicated** block for the stream so the stream can stay open up to 1 hour:

```nginx
# SSE stream – long-lived connection
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
    }
}
```

(Adjust `fastcgi_pass` if you use a different PHP version, e.g. `php8.1-fpm.sock`.)

### If you use Nginx as reverse proxy (e.g. to another server)

Add in the `location` that proxies to Laravel:

```nginx
proxy_connect_timeout 60;
proxy_send_timeout 1800;
proxy_read_timeout 1800;
proxy_buffering off;
```

For the stream URL specifically:

```nginx
proxy_read_timeout 3600s;
proxy_send_timeout 3600s;
proxy_buffering off;
```

---

## 2. PHP-FPM – request timeout

So PHP doesn’t kill the request before Nginx:

**Edit pool config** (e.g. `/etc/php/8.2/fpm/pool.d/www.conf`):

```ini
; Allow a single request to run up to 30 minutes
request_terminate_timeout = 1800
```

Or in **php.ini** (e.g. `/etc/php/8.2/fpm/php.ini`):

```ini
max_execution_time = 1800
```

Then restart PHP-FPM:

```bash
sudo systemctl restart php8.2-fpm
```

---

## 3. Reload Nginx

After editing Nginx config:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## 4. Summary

| Layer        | Setting                     | Value   |
|-------------|-----------------------------|---------|
| Nginx       | `fastcgi_read_timeout`       | 1800 (API), 3600 for stream |
| Nginx       | `fastcgi_send_timeout`       | 1800 / 3600 |
| Nginx       | `fastcgi_buffering` (stream)| off     |
| PHP-FPM     | `request_terminate_timeout` | 1800    |

Laravel already sets `max_execution_time` in the controller; the 504 was from Nginx (and possibly PHP-FPM) closing the connection first. After these changes, long-running `POST /api/v1/mobile/generate-meals` and `GET /api/v1/mobile/stream/{id}` should complete without 504.

---

## 5. Optional: inform Flutter about long requests

In **FLUTTER_DEVELOPER_GUIDE.md** you can add a short note:

- **generate-meals**: the server may take 1–3+ minutes to respond; use a long HTTP client timeout (e.g. 5–10 minutes) and show a “Generating your plan…” state.
- **Stream**: keep the SSE connection open; 504 on the stream is usually fixed by the server timeouts above.

If you want, we can add that note to the Flutter guide next.
