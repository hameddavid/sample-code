# Multi-Gateway Payment System — Code Sample

> **Note:** This is a sanitized extract from a private production repository I built and maintain for an organization. The full application is live at [https://nrecords.run.edu.ng](https://nrecords.run.edu.ng/). The source repository is private as it belongs to the institution, so I've extracted the most architecturally representative files here — with all credentials, API keys, and institution-specific identifiers removed — to serve as a public code sample.

Extracted from a production **Transcript & Records Management System** built for a Nigerian university. The full application handles transcript requests, multi-destination payments, tiered admin approvals, and degree verification — serving students, registrar staff, and external institutions.

## Architecture Highlights

### Multi-Gateway Payment Service (`app/Services/PaymentService.php`)
- Supports **Interswitch WebPay** and **Remita** payment gateways behind a unified interface
- Idempotent transaction handling: detects pending/unused payments before creating duplicates
- Webhook receivers for both gateways with HMAC signature verification (Interswitch)
- Re-query support for verifying stale transactions against gateway APIs

### Admin-Configurable Settings (`app/Models/AppSetting.php`)
- Database-backed key/value settings with 5-minute cache layer
- Allows operations staff to rotate API keys, merchant IDs, and service type mappings without code deploys
- Falls back to `.env` values when no DB override exists — zero-downtime migration from static config to admin-managed

### PHP 8.1 Enums
- `AdminRole` — role-based access control (Recommender → Approver → Super Admin)
- `PaymentStatus` — typed status codes with labels and UI colors
- `TranscriptDestination` — per-destination Remita service type IDs, resolved from DB at runtime
- `TranscriptType` — application types with DB-backed dynamic pricing

### Vue 3 Admin Settings UI (`resources/js/pages/admin/SettingsPage.vue`)
- Role-gated gateway configuration forms (Approver/Super Admin only)
- Sensitive field masking with toggle reveal
- Per-gateway group loading and batch save

### Automated Deploy Pipeline (`deploy.sh`)
- GitHub webhook triggers cron-based deploy on cPanel shared hosting
- Backs up and restores cPanel-managed Apache config blocks across `git reset --hard`
- Fixes file permissions for CloudLinux security compliance (group-writable PHP rejection)
- Laravel cache rebuild (config, routes, views)

## Stack

- **Backend:** Laravel 10, PHP 8.3
- **Frontend:** Vue 3 (Composition API), Vite
- **Database:** MySQL
- **Hosting:** cPanel shared hosting (CloudLinux/LSAPI)
- **Deployment:** GitHub webhook + cron, zero-downtime

## Files in This Sample

```
app/
├── Services/
│   └── PaymentService.php        # Multi-gateway payment orchestration
├── Models/
│   └── AppSetting.php            # Cached DB-backed settings
├── Enums/
│   ├── AdminRole.php             # RBAC enum
│   ├── PaymentStatus.php         # Payment state machine
│   ├── TranscriptDestination.php # Per-destination gateway config
│   └── TranscriptType.php        # Application types + pricing
└── Http/Controllers/Api/V1/
    ├── Admin/
    │   └── AppSettingController.php  # Settings CRUD API
    └── Applicant/
        └── PaymentController.php     # Payment flow + webhooks
resources/js/pages/admin/
└── SettingsPage.vue              # Admin gateway config UI
deploy.sh                         # Automated deploy pipeline
```

## Live Application

The full system is deployed and running at **[https://nrecords.run.edu.ng](https://nrecords.run.edu.ng/)**.

## Notes

- All credentials, API keys, and institution-specific identifiers have been replaced with placeholders
- The full application includes additional modules (transcript workflow, PDF generation, degree verification, result upload, student import) not shown here
- OpenAPI/Swagger annotations are included on controller methods
- The production repository is private — this sample is provided for code review purposes
