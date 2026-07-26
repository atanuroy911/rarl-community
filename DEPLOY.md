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
3. Leave the tables empty — you don't need to import `schema.sql` by hand. The first time
   you visit `/admin/` (or `/admin/login.php`) against an empty database, the app
   detects there are no tables yet and redirects to a first-run setup screen
   (`admin/install.php`, WordPress-style) with a single "Set Up Database" button that
   runs `sql/schema.sql` and `sql/002_v2_features.sql` for you, then sends you to login.
   You can still import them manually via phpMyAdmin if you prefer — the setup screen
   just won't appear once the `members` table exists.

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

### Automated upload via GitHub Actions

Once the initial upload/config (steps 1–3) is done once by hand, ongoing deploys can
happen automatically on every push instead of re-zipping and re-uploading manually.
`.github/workflows/deploy.yml` is already set up for this. Rather than FTP-uploading
every individual file (600+ of them, mostly from the bundled `fpdf`/`phpqrcode`
libraries) — which was tripping connection resets on some cPanel FTP servers — the
workflow zips the whole app into a single `deploy.zip`, FTPS-uploads just that one
file, then calls a small PHP endpoint (`deploy-extract.php`) on the server that unzips
it in place and deletes the zip. One file over the wire instead of hundreds.

1. In cPanel, find your FTP credentials: **FTP Accounts** (or reuse the main account) —
   note the host, username, password, and the server directory the app lives in (e.g.
   `public_html/membership`).
2. Generate a deploy token the same way as `SECRET_SALT`:
   `php -r "echo bin2hex(random_bytes(32));"` (or `openssl rand -hex 32`).
3. In the GitHub repo → **Settings → Secrets and variables → Actions**, add:
   - `CPANEL_FTP_SERVER` — FTP host (e.g. `ftp.rarl-lab.com`)
   - `CPANEL_FTP_USERNAME`
   - `CPANEL_FTP_PASSWORD`
   - `CPANEL_FTP_SERVER_DIR` — the remote path, e.g. `public_html/membership/`
   - `CPANEL_SITE_URL` — the public URL the app is served at, e.g.
     `https://rarl-lab.com/membership` (used to call `deploy-extract.php` after upload)
   - `DEPLOY_TOKEN` — the value generated in step 2
4. Add the **same** `DEPLOY_TOKEN` value to the server's `.env` (see step 3 below) —
   `deploy-extract.php` compares the token in the request against this before
   extracting anything, so the two must match.
5. Push to `main` (or trigger the workflow manually via the Actions tab). GitHub
   Actions checks out the repo, zips it (excluding `.git`, `.github`, `node_modules`,
   `.env*`, the `phpqrcode` mask cache, and the `uploads/` subfolders that hold
   user-generated files — avatars, certificates, CVs, ID cards, popups, templates,
   people photos, so those are never clobbered), uploads only `deploy.zip`, then hits
   `deploy-extract.php?token=...` which unzips it into place and removes the zip.
6. **`.env` is never touched by this workflow** — it's excluded from the zip entirely,
   so whatever you create on the server in step 3 below stays in place across every
   future deploy. Same goes for real server environment variables instead of a `.env`
   file (see step 3).
5. Database migrations (importing `sql/schema.sql` / `sql/002_v2_features.sql`) are
   **not** part of this workflow — it only syncs PHP/asset files. Run migrations from
   **Admin → Migrations** in the app itself after a deploy that adds new tables/columns
   (see step 3's SQL import, or that admin page for the automated version).

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

## 5. Mail deliverability (SMTP via your cPanel mailbox)

`sendEmail()` (in `functions.php`) sends over real authenticated SMTP when `SMTP_HOST`
is set in `.env`, using the bundled `libs/SmtpMailer.php` (no Composer/PHPMailer
dependency). If `SMTP_HOST` is left blank it falls back to PHP's `mail()`, which works
but is far more likely to land in spam since it doesn't authenticate as the sending
mailbox. **Setting up SMTP is the recommended path in production.**

Namecheap/cPanel mailboxes (the ones you read via **Roundcube** webmail) are regular
mailboxes with their own SMTP credentials — Roundcube is just a webmail *client* on top
of the same mail server, not a separate mailer. You don't do anything inside Roundcube
itself; you just need the mailbox's SMTP host/port/credentials.

1. In cPanel → **Email Accounts**, create (or reuse) a mailbox on your domain, e.g.
   `community@rarl-lab.com`. This is the same mailbox you can open in Roundcube at
   `rarl-lab.com/webmail` to read replies sent to `MAIL_REPLY_TO`/`MAIL_FROM_EMAIL`.
2. Click **Connect Devices** (or **Set Up Mail Client**) next to that mailbox in cPanel —
   it shows the exact **Outgoing Server (SMTP)** hostname for your account (commonly
   `mail.rarl-lab.com`, sometimes a shared Namecheap mail cluster hostname instead).
   Note the SMTP host and the two port options it lists (usually 587 and 465).
3. In `.env`, set:
   ```
   SMTP_HOST=mail.rarl-lab.com
   SMTP_PORT=587
   SMTP_USER=community@rarl-lab.com
   SMTP_PASS=the mailbox's real password
   SMTP_SECURE=tls
   ```
   If port 587 (`tls`, STARTTLS) doesn't connect from your host, try port 465 with
   `SMTP_SECURE=ssl` (implicit TLS) instead — cPanel's Connect Devices page lists both.
4. Also check **Email Deliverability** in cPanel for `rarl-lab.com` and fix any flagged
   SPF/DKIM records — SMTP auth alone helps, but correct SPF/DKIM is still what stops
   mail from landing in spam.
5. Test: register a test account and confirm the welcome email arrives; check
   `error_log` (cPanel → **Errors**) for `SMTP send failed: ...` if it doesn't — the
   message will say exactly which SMTP step failed (auth, connection, etc).

**Attachments (e.g. emailing certificate PDFs directly, not just a download link):**
`sendEmail()` already accepts an optional `$attachments` array —
`sendEmail($to, $name, $subject, $html, [['path' => $absolutePath, 'filename' => 'Certificate.pdf']])`
— and both the SMTP path and the `mail()` fallback build a proper
`multipart/mixed` MIME message for it. Nothing else needs to change if this feature
gets used later; the mailer was built with it in mind from the start.

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

## 8. Testing locally

Run this on a local PHP + MySQL stack (XAMPP, or PHP's built-in server pointed at a
local MySQL) to catch issues early — ask for a hand with that setup whenever you're
ready. The same `.env` process applies (`.env.sample` → `.env` with local DB
credentials).

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
   the same `sendEmail()`/`mail()` path every other email on this site already uses.
5. **PDF/QR for ID cards** — reuses the same `libs/fpdf` + `libs/phpqrcode` already
   bundled for certificates; no new PHP extensions or Composer packages required.
6. **Verify** — register a test account and confirm the OTP-verification step gates
   login, post to the community feed and confirm a second test account gets a comment
   notification email, and activate a member with a photo in Admin → Members to confirm
   an ID card PDF is generated and its QR code resolves on `id-card-verify.php`.

## 10. Community feed rich-text + photo upload

`sql/003_community_richtext.sql` adds two columns to `community_posts`:
`body_format` (`'markdown'` for pre-existing posts, `'html'` for new ones) and
`image_path` (single photo per post). Run it via **Admin → Migrations** (or
phpMyAdmin) after `002_v2_features.sql` — same idempotent, safe-to-re-run style.

- The composer now uses **Quill.js** (loaded from a CDN, no Composer/build step)
  for rich text — bold/italic/underline, lists, links, blockquotes.
- Whatever Quill produces client-side is **never trusted as-is**: every submitted
  post is run through `sanitizeRichHtml()` in `functions.php`, which parses it with
  `DOMDocument` and strips anything outside a small tag/attribute allow-list before
  it touches the database. This is the one place in the app where a `TEXT` column
  is allowed to hold raw HTML instead of markdown.
- `uploads/community/` is created automatically on first image upload (same
  `mkdir()`-on-demand behavior as the other upload folders) and inherits the shared
  `uploads/.htaccess` protection — no manual folder setup needed.
- Older posts (`body_format = 'markdown'`) keep rendering through the existing
  Parsedown path (`markdownToHtml()`) — nothing about existing content changes.
