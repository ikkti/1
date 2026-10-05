# UNITED — Fly.io deployment

## Included
- PHP 8.4 + Apache deployment files (`Dockerfile`, `fly.toml`, `fly-entrypoint.sh`).
- Persistent Fly volume for `users.json`, `posts.json`, `storage/`, and `uploads/`.
- Existing project credentials/files are retained; no credential files are deleted.
- 4-digit email OTP for registration and password recovery.
- ZainCash monthly verification payment: 2,000 IQD.
- Admin-editable verification email subject and HTML template.
- UNITED dark red/black responsive visual theme and cybersecurity logo.

## Recommended Fly secrets
Set these to the same working credentials already present in the project when deploying:

- `ZAINCASH_CLIENT_ID`
- `ZAINCASH_CLIENT_SECRET`
- `ZAINCASH_MSISDN`
- `ZAINCASH_API_URL`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_FROM_EMAIL`
- `SMTP_FROM_NAME`

The existing legacy credential values remain in the original integration files as requested; the new runtime prefers environment variables when supplied.

## Deploy

```bash
fly launch --no-deploy
fly volumes create united_data --region fra --size 1
fly secrets set ZAINCASH_CLIENT_ID="..." ZAINCASH_CLIENT_SECRET="..." ZAINCASH_MSISDN="..." ZAINCASH_API_URL="https://pg-api.zaincash.iq"
fly secrets set SMTP_HOST="..." SMTP_PORT="465" SMTP_USERNAME="..." SMTP_PASSWORD="..." SMTP_FROM_EMAIL="..." SMTP_FROM_NAME="UNITED"
fly deploy
```

Change `app = "united-cyber"` and `primary_region` in `fly.toml` if those names/regions are not available for the account.
