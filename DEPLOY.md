# Deploying to Namecheap Shared Hosting (cPanel)

This app is plain PHP + MySQL with no build step (Tailwind is loaded from a CDN at
runtime), so it deploys the same way any classic PHP site does on shared hosting.

## Requirements

- PHP 8.1+ (the code uses the `never` return type and other 8.1 features). In cPanel,
  set this under **MultiPHP Manager** for the domain.
- MySQL 5.7+/MariaDB 10.3+.
- PHP extensions: `pdo_mysql`, `gd` (already standard on Namecheap's stack).

## 1. Create the database

In cPanel → **MySQL Databases**:
1. Create a database (e.g. `yourcpaneluser_rarl`).
2. Create a database user with a strong password, add it to the database with **All Privileges**.
3. Open **phpMyAdmin**, select the new database, go to **Import**, and upload
   [`sql/schema.sql`](sql/schema.sql). This creates all 11 tables and seeds default settings,
   resource categories, and the starter membership/partnership tiers.

## 2. Upload the files

Decide where this app lives relative to `rarl-lab.com`:
- **Subfolder** (matches the current default config, `https://rarl-lab.com/membership`):
  upload everything into `public_html/membership/`.
- **Subdomain** (e.g. `community.rarl-lab.com`): create the subdomain in cPanel, then
  upload into its document root, and change `SITE_URL`/`CERT_VERIFY_URL` in `config.php`
  accordingly (see step 3).

Upload via cPanel **File Manager** (zip the repo, upload, extract) or **Git Version
Control** if you'd rather `git pull` directly on the server. Do not upload
`node_modules`, `.git`, or anything Next.js-related — this repo should only contain the
PHP app now.

## 3. Configure

Config values come from environment variables — `config.php` itself has no secrets and
is safe to commit; only `.env` (gitignored) or real server env vars hold real values.

1. Copy `.env.sample` to `.env` (in the same directory as `config.php`) and fill in:
   - `DB_HOST` (usually `localhost` on Namecheap), `DB_NAME`, `DB_USER`, `DB_PASS` from step 1.
   - `SITE_URL` / `MAIN_SITE_URL` to match wherever you deployed it (step 2).
   - `MAIL_FROM_EMAIL` / `ADMIN_EMAIL` — use a real mailbox on your domain (see mail note below).
   - `ADMIN_PASSWORD_HASH` — generate your own via
     `php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"` (SSH access, or run
     locally once you have PHP installed) and paste the result in. **Do not deploy with
     the sample hash.**
   - `SECRET_SALT` — generate a random value via `php -r "echo bin2hex(random_bytes(32));"`.
2. On shared hosting, `.env` is a plain file read directly by `env.php` — no extra setup
   needed beyond making sure it sits next to `config.php` and isn't web-accessible
   (`.htaccess` already blocks dotfiles). If you'd rather set real environment variables
   instead of a file (e.g. via cPanel's "Environment Variables" feature where available,
   or any other host), that works too — `.env` is optional, `getenv()` is checked either way.
3. Set folder permissions: `uploads/avatars`, `uploads/certificates`, `uploads/templates`
   need to be writable by PHP — `755` is normally sufficient on Namecheap (owner-writable
   via suexec/CloudLinux, which is how most cPanel PHP hosting runs).

## 4. SSL

Enable **AutoSSL** in cPanel (free, one click) for the domain, then uncomment the
HTTPS-redirect block at the bottom of `.htaccess`.

## 5. Mail deliverability

`sendEmail()` uses PHP's built-in `mail()`. On shared hosting this works but can land in
spam if SPF/DKIM aren't set up for the domain. In cPanel, check **Email Deliverability**
for `rarl-lab.com` and fix any flagged SPF/DKIM records. If deliverability is still poor
after that, consider swapping `sendEmail()` in `functions.php` for an SMTP-based sender
(e.g. PHPMailer via your mailbox's SMTP credentials) — not required to get the site
running, but worth revisiting if welcome/certificate emails aren't arriving.

## 6. Verify

- Visit the site root — landing page should load with the RARL logo/branding.
- Register a test account, confirm the welcome email arrives.
- Log into `/admin/` with the username/password you hashed in step 3, approve the test
  member, issue a test certificate, confirm the PDF (with QR code) generates and the
  verify page finds it.
- Visit `/partners.php` (also linked as "Partner With Us" in the main nav) and confirm
  the seeded tiers and benefit lists appear; edit one from **Admin → Partnerships** and
  confirm the change shows up.

## 7. Managing the "Partner With Us" page

The `partners.php` page (linked from the main nav) and its tier cards are fully
editable from **Admin → Partnerships** — nothing here requires touching code or the
database directly:

- **Tiers**: add/edit/delete membership and partnership tiers (name, description, fee +
  currency + billing period, category). Leave the fee blank to show "Contact us" instead
  of a price. Check **"Show as separate add-on card"** for upsells like a "High
  Visibility" tier that should stand out from the main pricing grid. Uncheck
  **"Published"** to hide a tier without deleting it.
- **Page content**: the intro paragraph, the two benefit lists shown in the
  "Why become a member? / Why become a partner?" toggle (one bullet per line), the
  ethics note callout, and the contact name/email used for the "Get in touch" links —
  all editable in the same **Partnerships** admin page.
- The starter tiers seeded by `schema.sql` (Membership, Private Partnership,
  Institutional Partnership, High Visibility) are just defaults — rename, reprice, or
  delete them freely; none of this is hardcoded in the PHP.

If you're adding this feature to a database that was already deployed from an older
copy of `schema.sql` (i.e. you imported it before this feature existed), re-import
`sql/schema.sql` — every statement uses `CREATE TABLE IF NOT EXISTS` / `ON DUPLICATE KEY
UPDATE`, so it's safe to re-run without wiping existing members, certificates, etc.

## 8. Testing locally with Docker / Dokploy

A `Dockerfile` and `docker-compose.yml` are included for spinning up the app + a MySQL
database on your home server (or any machine with Docker) without installing PHP/MySQL
natively.

```bash
docker compose up -d --build
```

This builds the app image (`php:8.2-apache` with `pdo_mysql`/`gd` enabled and
`.htaccess` support turned on), starts a MySQL 8 container, and auto-imports
`sql/schema.sql` into it on first run (only happens on an empty database volume — safe
to `docker compose down` and back `up` without re-seeding). The app is then reachable at
`http://localhost:8080`, with `docker-compose.yml` already setting the environment
variables it needs (DB host/creds, site URL, a default admin login) — no `.env` file is
required for the Docker path, though one still works if you add it.

Default admin login for the Docker stack is `admin` / `admin123` — change
`ADMIN_PASSWORD_HASH` in `docker-compose.yml` before using this for anything beyond a
quick local check.

To stop and wipe the database (start fresh): `docker compose down -v`.

**On Dokploy itself**: point it at this repo's `Dockerfile` directly (Dokploy builds
and runs it the same way `docker compose up --build` does locally) and configure the
same environment variables — `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`,
`SITE_URL`/`MAIN_SITE_URL`, `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH`, `SECRET_SALT` — via
Dokploy's environment variable UI rather than a committed `.env`. Dokploy can also run a
MySQL service alongside it (or you point `DB_HOST` at an existing MySQL instance);
either way, import `sql/schema.sql` once via whatever DB admin tool Dokploy exposes
(phpMyAdmin/Adminer) the same way you would in cPanel.

## Local PHP + MySQL testing (no Docker)

Alternatively, run this on a local PHP + MySQL stack (XAMPP, or PHP's built-in server
pointed at a local MySQL) to catch issues early — ask for a hand with that setup
whenever you're ready. The same `.env` process applies (`.env.sample` → `.env` with
local DB credentials).

## 9. v2 rollout (free plans, CMS, community feed, OTP auth, ID cards)

If you're deploying the free-plans/CMS/community/OTP/ID-card feature set on top of an
existing install:

1. **Import the migration** — in cPanel → phpMyAdmin, run `sql/002_v2_features.sql`
   *after* `sql/schema.sql` (same idempotent `CREATE TABLE IF NOT EXISTS` /
   `ON DUPLICATE KEY UPDATE` style as the base schema, safe to re-run). This adds the
   new tables (`membership_plans`, `otp_codes`, `community_posts`, `community_comments`,
   `community_likes`, `regional_sections`, `people`) and new `members` columns
   (`plan_id`, `section_id`, `community_notify`, the expanded registration fields,
   `member_code`/`id_card_*`), and seeds the default plans + regional leadership data.
2. **Create upload folders** — `uploads/cv/`, `uploads/id-cards/`, `uploads/popups/`,
   `uploads/people/` need to exist and be writable (`755`), same as `uploads/avatars`
   already is. The shared `uploads/.htaccess` (blocks PHP execution in any
   subdirectory) already covers all of these — no per-folder `.htaccess` needed.
3. **Signature images for ID cards** — upload real signature PNGs to `assets/` (the
   placeholders referenced by `idcard_signer1_name`/`idcard_signer2_name` in
   Admin → Settings) if you want signatures on generated member ID cards; the card
   still generates without them, just without a signature graphic.
4. **Mail** — no change needed. OTP codes and community-comment notifications reuse
   the same `sendEmail()`/`mail()` path every other email on this site already uses;
   there is nothing Docker- or WSL-specific in the mailed code paths. (Mailpit, used to
   preview these emails during local development, is a dev-only tool and is never part
   of the deployed app.)
5. **PDF/QR for ID cards** — reuses the same `libs/fpdf` + `libs/phpqrcode` already
   bundled for certificates; no new PHP extensions or Composer packages required.
6. **Verify** — register a test account and confirm the OTP-verification step gates
   login, post to the community feed and confirm a second test account gets a comment
   notification email, and activate a member with a photo in Admin → Members to confirm
   an ID card PDF is generated and its QR code resolves on `id-card-verify.php`.
