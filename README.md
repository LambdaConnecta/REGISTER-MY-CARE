# Register My Care

> **A complete care management platform for domiciliary & residential care providers.**
> Created and designed by **Dr. Andrew Ebhoma**

---

## Features

| Module | Description |
|---|---|
| **Dashboard** | Live visit schedule, key metrics, unread messages |
| **Service Users** | Full profiles, medical history, documents, care plans |
| **Staff Management** | Staff profiles, DBS tracking, documents, job categories |
| **Rota & Assignments** | Weekly rota, assign carers to visits, care logs |
| **My Visits** | Staff view of assigned visits, log care, upload documents |
| **My Day Summary** | Daily summary, care logs, standalone log form |
| **MAR Chart** | Medication Administration Record — monthly chart per SU |
| **Messages** | Internal inbox, sent folder, broadcast to all staff |
| **Handover** | Shift handover notes to specific staff or all |
| **Incidents** | Incident reporting with severity, categories, file upload |
| **Holiday Requests** | Staff holiday applications, admin approve/decline |
| **Policies** | Admin uploads policies; staff read-only access |
| **Invoices** | Auto-generate invoices from visit data, PDF export |
| **Reports** | Visit, staff, SU reports with company logo |
| **Audit Log** | Full action trail — who did what, when |
| **Subscription** | Tiered plans (Free / Basic / Standard / Unlimited) |
| **Settings** | Company logo, organisation details |
| **Super Admin** | Multi-organisation management panel |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1 or higher |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| Apache | 2.4+ with `mod_rewrite` |
| PHP Extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `gd` |

---

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/registermycare.git
cd registermycare
```

### 2. Create the database

```sql
CREATE DATABASE regmycar_rmc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'regmycar_rmcuser'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON regmycar_rmc.* TO 'regmycar_rmcuser'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Run the schema

In phpMyAdmin or MySQL CLI:

```bash
mysql -u regmycar_rmcuser -p regmycar_rmc < sql/schema.sql
```

### 4. Configure the application

```bash
cp includes/config.example.php includes/config.php
nano includes/config.php   # Fill in DB credentials and other settings
```

### 5. Set folder permissions

```bash
chmod 755 uploads/
chmod 755 uploads/staff_docs/ uploads/policy_docs/ uploads/holiday_docs/
chmod 755 uploads/incident_docs/ uploads/su_docs/ uploads/logos/
```

### 6. Create your first organisation & admin user

Run this SQL (update values as needed):

```sql
-- Create organisation
INSERT INTO organisations (name, email, subscription_plan, su_limit)
VALUES ('My Care Company', 'admin@mycare.com', 'free', 2);

-- Create admin user (password: Admin1234!)
INSERT INTO users (organisation_id, first_name, last_name, email, password, role, is_active)
VALUES (1, 'Admin', 'User', 'admin@mycare.com',
        '$2y$10$TKh8H1.PfuAi36lUKSCoP.Z1H9I5lEYlqKx.D.K/p4XqZL6jF0nde', 'Admin', 1);
```

> **Note:** The default password hash above is for `Admin1234!` — change it immediately after first login.

### 7. Access the application

- **Main app:** `https://yourdomain.com/`  
- **Super admin:** `https://yourdomain.com/super_admin/`  
  Default credentials: `admin@registermycare.org` / `changeme123` — **change immediately**

---

## Migration Files

If upgrading from an older version, run migration files in order:

```
sql/migration_v14_v16.sql   ← Messages, handover, holiday, incidents, policies
sql/migration_v17.sql       ← Care logs, SU documents, medical history
sql/migration_v19.sql       ← Fix funding_type ENUM → VARCHAR
sql/migration_v24.sql       ← Invoices, subscription payments
sql/migration_v25.sql       ← Reports, logo support
sql/migration_v27.sql       ← Staff documents
sql/migration_v28.sql       ← Super admin tables
sql/migration_v31.sql       ← Subscription tiers
sql/migration_v32.sql       ← Settings, further fixes
sql/migration_fix_enums.sql ← Fix ENUM columns
sql/migration_fix_subscription.sql ← Subscription column fixes
```

---

## Directory Structure

```
registermycare/
├── index.php                  # Login page
├── dashboard.php              # Main dashboard
├── .htaccess                  # Apache config & security
├── .gitignore
│
├── auth/
│   ├── logout.php
│   ├── google_login.php       # Google OAuth redirect
│   └── google_callback.php    # Google OAuth callback
│
├── includes/
│   ├── config.example.php     # ← Copy to config.php and fill in
│   ├── config.php             # ← Created by you (gitignored)
│   ├── db.php                 # PDO connection
│   ├── auth.php               # Login, session, CSRF helpers
│   ├── functions.php          # Utility functions
│   ├── header.php             # Navigation + page wrapper
│   ├── footer.php             # Closing tags
│   └── subscription.php      # Plan/limit checking
│
├── pages/
│   ├── service_users.php
│   ├── su_profile.php         # Full SU profile (medical, documents, care plan)
│   ├── staff.php              # Staff management + profile
│   ├── rota.php               # Weekly rota
│   ├── visits.php             # All visits list
│   ├── my_visits.php          # Staff: my assigned visits
│   ├── my_day.php             # Staff: my day summary
│   ├── medications.php        # Medication list
│   ├── mar_chart.php          # MAR chart
│   ├── messages.php
│   ├── handover.php
│   ├── incidents.php
│   ├── holiday.php
│   ├── policies.php
│   ├── invoices.php
│   ├── reports.php
│   ├── audit_log.php
│   ├── subscription.php
│   └── settings.php
│
├── super_admin/
│   ├── login.php
│   ├── logout.php
│   ├── index.php              # Dashboard for all organisations
│   └── company.php           # Manage individual organisation
│
├── sql/
│   ├── schema.sql             # ← Run first on a fresh database
│   ├── migration_v14_v16.sql
│   ├── migration_v17.sql
│   └── ...
│
└── uploads/                   # User-uploaded files (gitignored)
    ├── staff_docs/
    ├── policy_docs/
    ├── holiday_docs/
    ├── incident_docs/
    ├── su_docs/
    └── logos/
```

---

## Google OAuth Setup (Optional)

1. Go to [Google Cloud Console](https://console.developers.google.com/)
2. Create a new project → **APIs & Services** → **Credentials**
3. Create **OAuth 2.0 Client ID** → Application type: **Web application**
4. Add Authorised redirect URI: `https://yourdomain.com/auth/google_callback.php`
5. Copy Client ID and Secret into `includes/config.php`

---

## Security Notes

- `includes/config.php` is **gitignored** — never commit credentials
- The `sql/` and `includes/` directories are blocked from web access via `.htaccess`
- The `uploads/` directory blocks PHP execution via its own `.htaccess`
- All user input is parameterised (PDO prepared statements)
- Passwords are hashed with `password_hash()` (bcrypt)
- CSRF tokens protect all POST forms
- Session IDs are regenerated on login

---

## Subscription Plans

| Plan | Service Users | Price |
|---|---|---|
| Free | 2 | £0/month |
| Basic | 10 | £100/month |
| Standard | 20 | £200/month |
| Unlimited | Unlimited | £400/month |

---

## License

Proprietary software. All rights reserved.  
Created and designed by **Dr. Andrew Ebhoma** — Register My Care v2.0  
Contact: info@registermycare.org
