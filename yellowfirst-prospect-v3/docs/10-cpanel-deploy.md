# cPanel Deployment Guide

Step-by-step deployment to GoDaddy shared cPanel hosting. Read the whole document before
starting — there are gotchas that trip up first-time Laravel-on-cPanel deploys.

## Prerequisites

| Requirement | Why | How to check |
|---|---|---|
| **PHP 8.2+** | Laravel 11 requires it | cPanel → "Select PHP Version" |
| **MySQL 5.7+ or 8.0+** | Schema features | cPanel → "MySQL Databases" |
| **Python 3.10+** | Agent worker | cPanel → "Setup Python App" or SSH `python3 --version` |
| **SSH access** | Composer + git deploy | cPanel → "SSH Access" — request from GoDaddy support if not enabled |
| **Cron jobs** | Daily agent + scheduler | cPanel → "Cron Jobs" |
| **At least 1GB MySQL quota** | Multi-tenant data grows | cPanel → "MySQL Databases" |
| **At least 5GB disk** | CSV uploads, logs | cPanel → top-right resource panel |

If GoDaddy's basic plan doesn't include Python or SSH, you'll need to upgrade to "Deluxe" or
higher. Confirm with GoDaddy support **before** starting the deploy.

## Critical pre-flight check

Run this in cPanel SSH **before** anything else:

```bash
# Confirm versions
php -v             # Must show 8.2+
mysql --version    # Must show 5.7+ or 8.0+
python3 --version  # Must show 3.10+
composer --version # If missing, install: see step below
git --version      # Required for code pulls
```

If `composer` is missing, install it user-locally:

```bash
cd ~
curl -sS https://getcomposer.org/installer | php
mv composer.phar ~/bin/composer  # ~/bin should be in PATH on most cPanel hosts
chmod +x ~/bin/composer
```

## Step 1: Database setup

In cPanel **MySQL Databases** screen:

1. Create database: `cpaneluser_yellowfirst` (cPanel will prefix with your account name).
2. Create user: `cpaneluser_yfapp` with strong password.
3. **Add user to database with ALL PRIVILEGES** (the screen has a checkbox).
4. Note down the FULL database name, username, password, and host (usually `localhost`).

You'll put these in `.env` shortly.

## Step 2: Code deployment

```bash
# SSH into your cPanel host
ssh cpaneluser@yellowfirst.com

# Clone repo into a directory ABOVE public_html (security: code shouldn't be web-accessible)
cd ~
git clone https://github.com/Karnashukla/Saletomation.git app
cd app

# Install PHP deps - production mode, no dev tools
composer install --no-dev --optimize-autoloader

# Install Node deps + build frontend
# If `node` not available on your cPanel, build LOCALLY and commit `public/build/` to git
# (less ideal but works on minimal hosts)
npm ci
npm run build

# Copy .env template
cp .env.example .env

# Edit .env with your DB credentials, app URL, etc.
nano .env
```

`.env` minimum required values:

```ini
APP_NAME=Yellowfirst
APP_ENV=production
APP_KEY=                          # Generated next step
APP_DEBUG=false
APP_URL=https://yellowfirst.com   # Or https://app.yellowfirst.com if subdomain

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=cpaneluser_yellowfirst
DB_USERNAME=cpaneluser_yfapp
DB_PASSWORD=your-strong-password-here

QUEUE_CONNECTION=database         # Critical - works on cPanel
SESSION_DRIVER=database
CACHE_STORE=database

MAIL_MAILER=smtp                  # Or use Gmail OAuth - configured in app
MAIL_HOST=mail.yellowfirst.com    # cPanel mail server
MAIL_PORT=587
MAIL_USERNAME=karna@yellowfirst.com
MAIL_PASSWORD=mail-password
MAIL_ENCRYPTION=tls

ANTHROPIC_API_KEY=sk-ant-xxxxx    # Karna's API key

AGENT_PYTHON_PATH=/usr/bin/python3
AGENT_LOG_PATH=/home/cpaneluser/app/storage/logs
```

```bash
# Generate app encryption key
php artisan key:generate

# Run migrations - creates all tables documented in 11-database-schema.md
php artisan migrate --force

# Seed default data (rejection reason taxonomy, default roles, default pipeline stages)
php artisan db:seed --force

# Cache config + routes for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set storage permissions
chmod -R 775 storage bootstrap/cache
```

## Step 3: Public web root

cPanel serves files from `~/public_html`. We **don't** want our entire app there — only the
`public/` directory should be web-accessible.

**Option A: Symlink (preferred, if your cPanel allows)**

```bash
# Backup whatever's in public_html first
mv ~/public_html ~/public_html.backup

# Symlink Laravel's public/ as the new public_html
ln -s ~/app/public ~/public_html
```

**Option B: Subdomain (cleaner, recommended)**

In cPanel **Subdomains**:

1. Create subdomain `app.yellowfirst.com`
2. Set its document root to `/home/cpaneluser/app/public`

This is cleaner because `yellowfirst.com` (marketing site) and `app.yellowfirst.com` (this app)
are separate. Most professional SaaS deploys do this.

**Option C: Subdirectory `yellowfirst.com/sales`**

Per your earlier message you wanted this option. To make it work:

1. Keep your marketing site at `~/public_html/`
2. Move the app into `~/public_html/sales/`:
   ```bash
   ln -s ~/app/public ~/public_html/sales
   ```
3. In `.env`: `APP_URL=https://yellowfirst.com/sales`
4. In `~/app/public/.htaccess`, add `RewriteBase /sales/` near the top

This works but slightly fragile — Laravel apps in subdirectories occasionally have asset URL
quirks. I'd recommend Option B (`app.yellowfirst.com`) instead. Same domain, cleaner separation.

## Step 4: Python agent setup

```bash
cd ~/app/agent

# Create virtual env
python3 -m venv venv

# Activate and install deps
source venv/bin/activate
pip install -r requirements.txt

# Test agent runs (against test tenant)
python3 daily_agent.py --tenant=test --dry-run
# Should output: "Dry run complete. Would have processed N candidates..."
```

If `python3 -m venv` fails (some shared cPanel hosts disable it), use `virtualenv`:

```bash
pip install --user virtualenv
python3 -m virtualenv venv
```

## Step 5: Cron jobs

In cPanel **Cron Jobs**:

| Schedule | Command |
|---|---|
| `0 8 * * *` | `cd /home/cpaneluser/app && /home/cpaneluser/app/agent/venv/bin/python agent/daily_agent.py >> storage/logs/agent.log 2>&1` |
| `*/5 * * * *` | `cd /home/cpaneluser/app && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1` |
| `*/30 * * * *` | `cd /home/cpaneluser/app && /home/cpaneluser/app/agent/venv/bin/python agent/check_replies.py >> storage/logs/replies.log 2>&1` |
| `0 3 * * *` | `cd /home/cpaneluser/app && /usr/local/bin/php artisan summary:daily >> storage/logs/summary.log 2>&1` |
| `0 4 * * *` | `find /home/cpaneluser/app/storage/logs -name "*.log" -mtime +30 -delete` |

**The `*/5` Laravel scheduler is critical** — Laravel's queue, follow-up sends, reminder
emails, all run via this. If it doesn't fire, follow-ups don't send.

## Step 6: SSL / HTTPS

cPanel **SSL/TLS Status** → "Run AutoSSL". Enables Let's Encrypt for your domain. Should
complete in a few minutes. Verify by visiting `https://yellowfirst.com` in browser.

## Step 7: Smoke test

```bash
# From your laptop:
curl -I https://yellowfirst.com
# Should return: HTTP/2 200, plus Laravel cookies in Set-Cookie

# Visit the app in browser - you should see login screen
# Default admin (created by seeder): karna@yellowfirst.com / change-me-immediately
```

Log in, change password, create your first ICP profile, run the agent manually:

```bash
ssh cpaneluser@yellowfirst.com
cd ~/app
/home/cpaneluser/app/agent/venv/bin/python agent/daily_agent.py --tenant=yellowfirst
```

Watch `storage/logs/agent.log` to see what it does.

## Known cPanel issues + workarounds

### Issue: PHP execution timeout
cPanel often caps PHP at 30s. Long migrations or queue jobs may die mid-run.
**Workaround:** Run `migrate` and `db:seed` from CLI (not web) — CLI doesn't have the timeout.
Long agent runs are Python (cron-controlled), not PHP.

### Issue: Process killed for memory
cPanel kills processes that exceed memory limits (often 512MB).
**Workaround:** The Python agent processes one tenant at a time, with explicit `gc.collect()`
between tenants. If you still hit limits, reduce `daily_quota_*` in `01-signals.md` temporarily.

### Issue: Outbound HTTPS blocked on certain ports
Some shared cPanel hosts block outbound on non-standard ports. Anthropic API uses 443 (always
open). External SMTP may be blocked — use cPanel's mail server instead.

### Issue: Cron silently failing
cPanel cron failures don't bubble up by default. Always pipe to a log file (`>> log 2>&1`)
and check `storage/logs/agent.log` daily for the first week.

### Issue: Python venv missing modules after deploy
If you `git pull` new code with new `requirements.txt`, the venv doesn't auto-update.
Add this to your deploy script:

```bash
cd ~/app
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
source agent/venv/bin/activate && pip install -r agent/requirements.txt
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

### Issue: Disk full
Logs grow. The `0 4 * * *` cron above deletes logs older than 30 days. CSV uploads also
consume disk — `csv_imports.storage_path` files should be archived to deep storage after
90 days (TODO: write this command in phase 3).

## When (not if) cPanel becomes a bottleneck

Symptoms that indicate it's time to migrate off shared hosting:

- Agent taking >5 minutes per tenant (cron timeout risk)
- More than 5 tenants (memory pressure)
- Outbound API rate limits being hit because everything runs in one window
- Customers complaining about latency (>2s page loads)
- Backup/restore taking hours

**Migration path:**

1. Spin up a $20/mo Hetzner or DigitalOcean VPS (same Laravel + MySQL + Python stack — no code
   changes needed).
2. Use `mysqldump` to migrate database.
3. Rsync `storage/app/` for CSV uploads.
4. Update DNS to point `app.yellowfirst.com` to new VPS.
5. Decommission cPanel app.

Estimated downtime: 30 minutes if done carefully.
