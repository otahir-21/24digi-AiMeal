# AWS RDS MySQL – .env setup

Your Laravel app is configured to use:

- **Database**: `ai_meals`
- **Username**: `admin`
- **Credentials**: stored in AWS Secrets Manager (eu-north-1)

## 1. Get the RDS endpoint hostname

In **AWS Console** → **RDS** → **Databases** → your DB instance:

- Copy the **Endpoint** (e.g. `xxxx.xxxxx.eu-north-1.rds.amazonaws.com`).

Put it in `.env`:

```env
DB_HOST=your-actual-endpoint.eu-north-1.rds.amazonaws.com
```

(Replace `your-rds-endpoint...` in `.env.example` with this value when you create `.env`.)

## 2. Get the database password from Secrets Manager

The master password is in Secrets Manager, not in the ARN. Retrieve it once and put it in `.env`.

**Option A – AWS CLI (recommended)**

```bash
aws secretsmanager get-secret-value \
  --secret-id "rds!db-42794fa9-5436-4dbb-abca-2feaaf62ef8f" \
  --region eu-north-1 \
  --query SecretString \
  --output text | jq -r .password
```

If you don’t have `jq`:

```bash
aws secretsmanager get-secret-value \
  --secret-id "rds!db-42794fa9-5436-4dbb-abca-2feaaf62ef8f" \
  --region eu-north-1 \
  --query SecretString \
  --output text
```

Copy the `password` value from the JSON and set it in `.env` as `DB_PASSWORD=...`.

**Option B – AWS Console**

1. **Secrets Manager** → **Secrets** → open the secret (e.g. `rds!db-42794fa9-...`).
2. **Retrieve secret value** → copy the **password**.
3. In your app server’s `.env`:  
   `DB_PASSWORD=<pasted-password>`  
   (no quotes unless the password contains spaces/special chars and your env loader requires them.)

## 3. Final .env database section

On the server (or locally for DB access), your `.env` should have:

```env
DB_CONNECTION=mysql
DB_HOST=your-actual-rds-endpoint.eu-north-1.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=ai_meals
DB_USERNAME=admin
DB_PASSWORD=the_password_from_secrets_manager
```

Do **not** commit `.env` or the password to git.

## 4. Test connection

From the machine where Laravel runs (e.g. EC2):

```bash
php artisan migrate --force
```

If that runs without errors, the RDS MySQL connection is correct.
