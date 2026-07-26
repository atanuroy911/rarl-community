# RARL Community Platform

Plain PHP + MySQL community platform for the Robotics & Automation Research Lab (RARL):
member registration (individuals and labs), a Discord-linked community feed, a learning
resource hub, newsletters, event management, and verifiable PDF certificates with
QR-code validation. No build step — Tailwind is loaded from a CDN at runtime.

📖 **[Full project documentation](docs/index.html)** — architecture, deployment
pipeline, first-run database setup, and admin security.

## Stack

- PHP 8.1+, MySQL 5.7+/MariaDB 10.3+, Apache
- No framework, no Composer — bundled libraries only (`fpdf`, `phpqrcode`, `Parsedown`)
- Hosted on cPanel shared hosting (Namecheap), deployed as `community.rarl-lab.com`

## Local setup

1. Copy `.env.sample` to `.env` and fill in local DB credentials.
2. Point PHP at this directory (XAMPP, or `php -S localhost:8000`) with a local MySQL server.
3. Log into `/admin/` — if the database is empty, you'll land on a first-run setup screen
   that creates all tables and seeds defaults with one click.

## Deployment

Every push to `main` deploys automatically to cPanel via GitHub Actions
(`.github/workflows/deploy.yml`) — see [DEPLOY.md](DEPLOY.md) for the full guide
(database setup, required secrets, SSL, mail deliverability) and
[docs/index.html](docs/index.html) for how the deploy pipeline itself works.
