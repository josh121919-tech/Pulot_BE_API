# PickApp API

The backend for [PickApp](https://github.com/josh121919-tech/Pulot) (repository name: Pulot), a campus lost and found app.
A PHP 8 REST endpoint (PDO) with a MariaDB/MySQL database. The Ionic app calls it with
Angular `HttpClient`.

```
pulot-api/
├── api/
│   ├── config.php                  DB connection (PDO), CORS, JSON helper
│   ├── config.local.example.php    template for real credentials on a server
│   └── items.php                   GET list / POST report (+ photo upload) / PUT status
├── sql/lost_found.sql              creates the campus_lost_found database and items table (XAMPP)
├── sql/lost_found_hosted.sql       items table only, for shared hosting (no CREATE DATABASE)
├── sql/migrate_add_status.sql      adds the Resolved status to an older items table
├── sql/migrate_add_found_notice.sql  adds the "I found this" notice to an older items table
└── uploads/                        saved photos (.htaccess blocks scripts and listing)
```

## Setup (XAMPP)

1. Put this folder in `C:\xampp\htdocs\` so the API is at
   `http://localhost/pulot-api/api/items.php`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Import `sql/lost_found.sql` in phpMyAdmin (or run `mysql -u root < sql/lost_found.sql`).
   A database created before the Resolved status existed needs `sql/migrate_add_status.sql`
   once (it adds the `status`, `resolved_at` and `owner_token_hash` columns).
4. Open `http://localhost/pulot-api/api/items.php` in a browser. You should see
   `{"success":true,"data":[]}`.

Default credentials are XAMPP's (`root`, no password).

## Setup (hosted server)

1. In the control panel, create a database and a user with all privileges on it.
2. In phpMyAdmin, select that database and import `sql/lost_found_hosted.sql`
   (shared hosts do not allow the CREATE DATABASE in `sql/lost_found.sql`).
3. Upload this folder (for example to `public_html/pulot-api/`).
4. Copy `api/config.local.example.php` to `api/config.local.php` and fill in the database
   name, user and password. That file is git-ignored, so the password never reaches the repository.
5. Make sure `uploads/` is writable by PHP, then open `https://<your-domain>/pulot-api/api/items.php`.

### Live copy: alwaysdata

The PickApp APK uses the copy running at `https://pulot.alwaysdata.net/api/items.php`
(alwaysdata's free plan). It was set up like this:

- Database `pulot_api` on `mysql-pulot.alwaysdata.net`, with `sql/lost_found_hosted.sql` imported.
- `api/` and `uploads/` uploaded into `/home/pulot/www/` with WinSCP. alwaysdata's FTP only
  accepts encrypted connections, so use **TLS/SSL Explicit encryption**.
- The database login lives only on the server, never in this repository.

Free hosts that put a browser check in front of the site (InfinityFree answers with an
`aes.js` page) don't work for the app, because the app cannot pass that check.

## API

| Method | URL                          | Body                                    | Result |
| ------ | ---------------------------- | --------------------------------------- | ------ |
| GET    | `/api/items.php`             | –                                       | 200, all reports, newest first |
| GET    | `/api/items.php?type=lost`   | –                                       | 200, only `lost` (or `found`) |
| GET    | `/api/items.php?q=umbrella&category=Bags&status=open&from=2026-10-25T00:00:00%2B08:00` | – | 200, filters combined with AND (any can be left out) |
| POST   | `/api/items.php`             | multipart: `title`, `description`, `type`, `category`, `latitude`, `longitude`, `contact_info`, optional `photo` file | 201 with the saved report and its `owner_token` |
| PUT    | `/api/items.php?id=5`        | JSON: `{ "status": "resolved" or "open", "owner_token": "..." }` | 200 with the updated report; 403 for a wrong token |
| PUT    | `/api/items.php?id=5`        | JSON: `{ "found_contact": "Phone: 0917..." }` (no token) | 200 with the report; 409 if it is not an open Lost report or already has a notice |

GET filters: `type` (lost/found), `status` (open/resolved), `category`, `q` (keyword in the
title or description, up to 100 characters), `from` / `to` (ISO 8601 date-times, `to` exclusive).

Errors come back as `{ "success": false, "message": "...", "errors": { field: "..." } }`
with status 400, 403, 404, 405, 413 (photo over 5 MB), 415 (not a JPEG/PNG/WebP), 422 (validation) or 500.
Categories: Electronics, ID/Cards, Keys, Bags, Personal Belongings, Others.

**Who can resolve a report.** There is no login, so POST creates a random `owner_token`,
returns it once, and stores only its SHA-256 hash. The app keeps the token on the phone that
submitted the report, so only that phone can mark it resolved.

## Security notes

This is a class prototype with no login. Reports, including contact info, are public to
anyone who can reach the API. Queries use PDO prepared statements; uploads are checked by
their real content type, renamed to random names and stored in a folder that will not run
scripts. Do not put real passwords or API keys in the repository.
