# LVAAS Membership

A WordPress plugin that manages user access using an external membership database (currently a Google Sheet) as the source of truth.

## Installation

### Prerequisites

- WordPress ≥ 6.0
- PHP ≥ 8.0 with the `openssl` extension
- [Composer](https://getcomposer.org/)
- A Google Cloud project with the **Google Sheets API** enabled
- A **Service Account** JSON key for that project
- A Google Sheet shared with the service account's email (read access). The plugin reads the `members` tab. Required column headers (exact, case-sensitive, any order): `last`, `first`, `email`, `mbr_type`, `mbr_cat`, `codes`, `phone`, `username`. See [`docs/spec.md`](docs/spec.md) for the full data model.
- WordPress salts defined in `wp-config.php` (`AUTH_KEY` and `SECURE_AUTH_KEY`) — used to derive the at-rest encryption key for the stored service-account JSON.

### Install

Clone into your WordPress plugins directory and install the PHP dependencies:

```bash
cd path/to/wordpress/wp-content/plugins
git clone https://github.com/lvaas/lvaas-membership-wp-plugin.git
cd lvaas-membership-wp-plugin
composer install --no-dev
```

`vendor/` is gitignored, so `composer install` is required after every clone or major update.

### Activate

```bash
wp plugin activate lvaas-membership-wp-plugin
```

Or in **WP Admin → Plugins**, click **Activate** under "LVAAS Membership".

### Configure

Open **WP Admin → LVAAS → Settings** and:

1. Paste the **Google Sheet ID** (the segment between `/d/` and `/edit` in the sheet URL).
2. Upload the **Service Account JSON** file. The plaintext is encrypted at rest with a key derived from `AUTH_KEY` + `SECURE_AUTH_KEY` and is never echoed back to the browser.
3. Optionally adjust **Provisioned WP Role** (default `subscriber`) and **Stale TTL** (default 24 h).
4. Click **Save Settings**.
5. Click **Test Connection** to confirm auth — it should report the sheet name and column count.
6. Click **Force Refresh** to populate the member cache (15-minute transient).

### Updating

```bash
cd wp-content/plugins/lvaas-membership-wp-plugin
git pull
composer install --no-dev
```

After updating, click **Force Refresh** on the Settings page if the data shape changed.

## What it does

- **Members view** — sortable, filterable, CSV-exportable table of the live membership database (`list_users` capability).
- **Add Users** — provisions WP accounts for LVAAS members not yet in WP and sends each one an HTML invitation email with a password-set link. The first line of the invitation is editable per-batch and supports `{first_name}` / `{last_name}` placeholders (`create_users`).
- **Prune Users** — revokes role or deletes WP accounts whose email is no longer in the LVAAS database; administrators are always skipped (`delete_users`).
- **History** — append-only audit log of every Add and Prune action (`manage_options`).
- **Auth gate** — blocks `registration_errors` and `lostpassword_post` for emails not in the LVAAS database. When the Google API is unreachable, serves last-known-good data within the stale-TTL window, then falls back to a "service temporarily unavailable" message.

See [`docs/spec.md`](docs/spec.md) for the full feature specification.
