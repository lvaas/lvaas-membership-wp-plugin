## Project Spec: WP Membership Plugin for LVAAS

### 1\. Objective

A WordPress plugin to manage user access based on an external membership database (db), currently a Google Sheet. The db acts as the "Source of Truth" for authorization.

### 2\. Core Functionality

* **Membership View:** interactive direct view of the db  
  * Controlled by 'list\_users' capability  
  * Would be nice to provide in-browser sorting, filtering, search options  
  * Content of relevant columns of entire db may be kept in browser tab  
  * Includes a **CSV export** button that downloads the current (filtered) view.  
  * view should include last name, first name, email, phone, and status  
    * status is formed as `mbr_type` + (space + suffix) where the suffix is derived from `mbr_cat` as follows. If the suffix is empty, the space is also omitted (e.g. `"Full"`, `"Full (Life)"`, `"Assoc (Student)"`):  
      * ‘R’ → empty  
      * ‘L’ → (Life)  
      * ‘H’ → (Honorary)  
      * ‘U’ → (Student)  
      * ‘J’ → (Junior)  
      * ‘F1’, ‘F2’, ‘FD’, ‘FJ’ → (Family)   
* **Authorization Check:** Intercept user registration and password reset actions  
  * ensure the user exists in the db  
  * upon a registration or password reset attempt  
    * check if the submitted email address is in the LVAAS db. If not, inform the user that they must be an LVAAS member to log in to the site, and provide a link to ./membership.  
    * otherwise proceed normally with the requested action.  
* **Endpoints:**  
  * **Membership View** — see above.  
  * **Add LVAAS Users:**  
    * Identify emails in the db not present in WP.  
    * Always shows a preview listing the addresses about to receive invitations and requires an explicit confirmation click before any email is sent.  
    * Send a registration or invitation email.  
    * Controlled by 'create\_users' capability  
  * **Prune Users:**  
    * Identify WP users not present in the db.  
    * Always shows a preview listing the affected accounts (email, last login, role) and requires an explicit confirmation click before any account is revoked or deleted.  
    * Revoke access or delete accounts.  
    * Controlled by 'delete\_users' capability  
  * **LVAAS Member Admin:**  
    * Portal page with links to other endpoints  
* **Modular Data Source:**  
  * Abstract the data retrieval logic so the Google Sheets module can be replaced (e.g., with a SQL module) without rewriting the sync logic.

### 3\. Technical Architecture

* **WP ↔ LVAAS linkage:** when a WP user is provisioned from an LVAAS row, store a copy of the LVAAS email in `user_meta` (key: `lvaas_email`). This preserves the original join key so future tooling can reconcile email changes between the two systems.  
* **Provisioned role:** all members provisioned from the LVAAS db are assigned the same WP role, configurable on the settings page (default: `subscriber`). `mbr_cat` does not affect role assignment.  
* **Email normalization:** all email comparisons apply `strtolower(trim($email))` to both sides before matching.  
* **Request hardening:** every state-changing endpoint verifies a WP nonce via `check_admin_referer()` and the relevant capability via `current_user_can()` before performing any action.  
* **Audit log:** every Add / Prune action appends a record (timestamp, actor WP user ID, action, list of affected WP user IDs) to a lightweight append-only store (custom table or dedicated wp_option). The Member Admin portal exposes a “History” view of this log.  
* **Data class:** `LVAAS_Member (all attributes are strings)`  
  * `username`  
    * used for primary key  
    * If empty when read from db, fill with the content of the email attribute in local representation. If email is also empty, exclude this record as invalid (see validation policy below).  
  * `email`  
  * `first`  
  * `last`  
  * `mbr_cat`  
    * Valid member values: ‘R’, ‘L’, ‘H’, ‘U’, ‘J’, ‘F1’, ‘F2’, ‘FD’, ‘FJ’.  
    * ‘P’ is a recognized non-member value and silently excludes the row (intentional, not an error).  
    * Any other value excludes the row as invalid (see validation policy below).  
  * `mbr_type`  
    * Valid member values: ‘Full’, ‘Assoc’.  
    * ‘Prospect’ is a recognized non-member value and silently excludes the row (intentional, not an error).  
    * Any other value excludes the row as invalid (see validation policy below).  
  * `phone`  
  * `codes` — reserved for future use; read from the sheet and stored on the in-memory object but not yet consumed by any feature.  
* **Validation policy for excluded rows:**  
  * Rows excluded for *intentional* reasons (`mbr_cat='Prospect'`, `mbr_type='P'`) are dropped silently.  
  * Rows excluded for any other reason (unknown `mbr_cat`/`mbr_type`, missing both `username` and `email`, etc.) are dropped *and* recorded: one WP debug-log entry per row (with sheet row number and reason), plus a dismissible admin notice on plugin pages — “N rows excluded — view details” — linking to a list of offending rows.  
* **Interface:** `User_Source_Interface`  
  * `get_members()`: Returns array of `LVAAS_Member`.  
  * `is_email_allowed(string $email)`: Returns boolean.  
* **Sheet layout (read by GDatabase module):**  
  * Data is read from the `members` tab of the configured Google Sheet.  
  * The module locates columns by **header text** in the first row, not by column letter — columns may be reordered without code changes.  
  * Required headers (exact match, case-sensitive): `last`, `first`, `email`, `mbr_type`, `mbr_cat`, `codes`, `phone`, `username`.  
  * `LVAAS_Member` field names are identical to the sheet header names; no mapping layer.  
  * A missing required header is a fatal sync error: cache is not updated and the previous good data continues to be served, with an admin notice naming the missing header.  
* **GDatabase Module:**  
  * Uses **Google Client Library** via Service Account JSON.  
  * Implements **WP Transients** (15 min cache) to respect API quotas.  
  * Settings page exposes a **Force refresh** button that deletes the transient and re-fetches immediately, reporting row count and any validation notices.  
  * Settings page exposes a **Test connection** button that authenticates and reads the first data row, reporting success (with column count and sheet name) or the verbatim Google API error.  
  * **Failure-mode policy:** when the Google API is unreachable or returns 429, serve the last successfully fetched data as stale, up to a configurable stale-TTL (default 24 h). Membership View renders a “stale data — last refreshed at …” banner during this window. After the stale window expires, registration / password-reset deny with a “service temporarily unavailable, try again later” message and an admin notice is raised.

---

### 4\. Implementation Phases

1. **Phase 1:** Test environment setup and basic plugin boilerplate.  
2. **Phase 2:** Implementation of Google Sheets API module with Service Account.  
3. **Phase 3:** UI settings page for Sheet ID and Service Account JSON upload.  
   * Sheet ID stored as a plain `wp_option`.  
   * Service Account JSON uploaded via the settings page and stored in `wp_options` with autoload disabled, encrypted at rest with a key derived from `AUTH_KEY` / `SECURE_AUTH_KEY`. The plaintext is never echoed back to the browser.  
   * The settings page displays only the last 6 characters of the Google Document ID and the Service Account credentials.  
4. **Phase 4:** Implement Membership view endpoint  
5. **Phase 5:** Hook into `registration_errors` to block unauthorized signups, and into `lostpassword_post` to block password-reset requests from non-members.  
6. **Phase 6:** Implement other endpoints

