# System Design Task: Lightweight SE2026 Daily Progress Dashboard

Act as a senior software architect and full-stack engineer.

I want you to design a **lightweight, fast, maintainable interactive monitoring dashboard** for SE2026 daily progress.

The application already uses / will use:

* **Backend:** Laravel
* **Frontend:** Svelte
* **Database:** use the existing relational database in the project; prefer PostgreSQL if the project is not committed to another DB
* **Data ingestion:** Excel upload from an admin backend
* **Update frequency:** normally once per day
* **Primary users:** internal monitoring users and administrators
* **Data scale:** currently small enough for a normal relational database; do not introduce OLAP, Elasticsearch, Redis, Kafka, or other infrastructure unless there is a demonstrated need

The design should prioritize:

1. simplicity,
2. dashboard responsiveness,
3. historical tracking,
4. correctness of daily-vs-cumulative metrics,
5. maintainability,
6. easy deployment,
7. minimal infrastructure.

Do **not start implementing application code yet**.

First inspect the repository, existing Laravel conventions, existing Svelte structure, database configuration, authentication, styling system, and available dependencies.

Then produce the system design.

---

# 1. Reference Dashboard

Use this existing dashboard as a **functional and information-hierarchy reference**, not something to blindly copy:

`https://dashboard-se2026-1300.vercel.app/`

Important patterns worth taking inspiration from:

* summary KPI cards,
* cumulative progress visualization,
* daily progress visualization,
* monitoring by worker,
* searchable/sortable performance table,
* filters,
* clear distinction between current status and progress over time.

Do not copy its implementation or branding.

Design a cleaner and more focused version for the dataset described below.

---

# 2. Source Data

The daily Excel template is:

`Daftar_Capaian_Harian_KUPANG.xlsx`

Locate and inspect the actual file if it is available in the workspace.

The current workbook has a worksheet named:

`Daftar Capaian_Harian`

Observed columns are:

* `No`
* `Kode SubSLS`
* `Nama SLS`
* `Nama PPL`
* `Email PPL`
* `ID PPL`
* `Nama PML`
* `Email PML`
* `Capaian PPL`
* `Capaian PML`
* `Target`
* `Status Produktivitas`
* `Status PPL Sobat`
* `Status PML Sobat`
* `Kategori Mitra`
* `Link Assignment PPL`
* `Jenis Mitra`

Some fields contain `-` instead of a value.

Treat these as null where appropriate.

Numeric-looking columns such as:

* `Capaian PPL`
* `Capaian PML`
* `Target`

must be normalized to integers/null instead of being stored as strings.

Do not depend on Excel row ordering.

---

# 3. Critical Data Semantics

The uploaded Excel file should be treated as a **snapshot of cumulative progress at a particular date**.

For example:

Snapshot August 10:

```text
PPL A cumulative progress = 100
```

Snapshot August 11:

```text
PPL A cumulative progress = 125
```

Then:

```text
Cumulative progress August 11 = 125
Daily progress August 11 = 125 - 100 = 25
```

DO NOT calculate cumulative progress by summing every historical Excel upload.

That would double-count data.

Use:

```text
cumulative(date D) = value contained in snapshot D
daily_change(date D) = cumulative(D) - cumulative(previous available snapshot)
```

The previous snapshot means the immediately preceding successfully imported snapshot, not necessarily yesterday.

If August 11 has no upload and the next upload is August 12:

```text
daily/net change on August 12
= snapshot August 12 - snapshot August 10
```

The UI should make this clear.

The first imported snapshot should be considered a **baseline**. Do not misleadingly report the entire baseline as "progress today".

---

# 4. Snapshot Architecture

Prefer an immutable snapshot model.

I expect something conceptually similar to:

```text
progress_uploads
    id
    snapshot_date
    version
    original_filename
    file_checksum
    uploaded_by
    import_status
    row_count
    validation_error_count
    imported_at
    superseded_at
    created_at
    updated_at
```

and:

```text
progress_snapshot_rows
    id
    upload_id

    kode_subsls
    nama_sls

    ppl_id
    ppl_name
    ppl_email

    pml_name
    pml_email

    capaian_ppl
    capaian_pml
    target

    status_produktivitas
    status_ppl_sobat
    status_pml_sobat

    kategori_mitra
    assignment_url
    jenis_mitra

    row_number
    row_fingerprint

    created_at
```

These are suggestions, not mandatory names.

Review the actual repository and propose the best Laravel schema.

Do not over-normalize unless normalization provides a clear benefit.

For this application, keeping the historical snapshot rows relatively denormalized is acceptable and may actually make querying and auditing easier.

---

# 5. Snapshot Versioning

Administrators may accidentally upload the same day twice or upload a corrected file.

Design this properly.

Requirements:

* calculate SHA-256 or equivalent checksum for uploaded files;
* detect exact duplicate uploads;
* allow corrected files for the same `snapshot_date`;
* allow the sole admin to permanently delete an import after simple confirmation;
* preferably version imports for the same date;
* only one version should be considered the active snapshot for dashboard calculations;
* superseded versions remain in history for three days, then are purged when the admin page opens;
* deleted versions are not recoverable and do not reactivate an older version.

Example:

```text
2026-08-13 v1 → superseded
2026-08-13 v2 → active
```

Dashboard calculations use v2.

---

# 6. Excel Import Workflow

Design an admin workflow like:

```text
Upload Excel
    ↓
Validate file structure
    ↓
Validate required columns
    ↓
Normalize values
    ↓
Show preview / validation result
    ↓
Administrator confirms import
    ↓
Database transaction
    ↓
Create snapshot
    ↓
Dashboard automatically uses latest snapshot
```

Validation should include at minimum:

* expected worksheet exists;
* required columns exist;
* numeric columns are valid;
* snapshot date is supplied;
* empty/null handling;
* duplicate rows;
* suspicious duplicate assignment keys;
* malformed URLs should not break import;
* missing PPL/PML values should be handled gracefully;
* any invalid row rejects the entire file and reports all invalid rows;
* suspicious values are warnings that require explicit confirmation before import;
* invalid files must not partially modify production data.

Use a DB transaction.

For the current dataset size, prefer a simple synchronous import unless repository inspection demonstrates that a queue is necessary.

Do not introduce Redis just for this import.

Design it so queue-based importing could be added later if file sizes become substantially larger.

---

# 7. Identity and Duplicate Handling

Do not assume `Kode SubSLS` alone is guaranteed unique.

The current Excel contains cases where the same SubSLS can be associated with different PPL records.

Investigate the dataset and determine a safe logical identity.

A possible assignment identity is:

```text
Kode SubSLS + ID PPL
```

but verify this against the workbook before finalizing the design.

Store a deterministic `row_fingerprint` if useful.

Do not simply enforce:

```text
UNIQUE(kode_subsls)
```

without verifying the data.

---

# 8. Core Dashboard Metrics

Design the dashboard around a small number of meaningful metrics.

## Overall KPI Cards

At minimum consider:

### Total Target

```text
SUM(Target)
```

for the selected/current snapshot.

### Cumulative PPL Progress

```text
SUM(Capaian PPL)
```

### PPL Progress %

```text
SUM(Capaian PPL)
----------------- × 100
SUM(Target)
```

### Remaining Target

```text
SUM(Target) - SUM(Capaian PPL)
```

### Progress Since Previous Snapshot

```text
Current SUM(Capaian PPL)
-
Previous SUM(Capaian PPL)
```

### Cumulative PML Progress

```text
SUM(Capaian PML)
```

### PML Review Coverage

Investigate whether the most meaningful denominator is:

```text
SUM(Capaian PML) / SUM(Capaian PPL)
```

or:

```text
SUM(Capaian PML) / SUM(Target)
```

Do not silently assume the business interpretation.

Document the selected definition.

It may be useful to display both:

```text
PML vs Target
PML vs PPL
```

if they represent different operational questions.

---

# 9. Per-PPL Metrics

The main monitoring unit should be each PPL.

Group primarily using stable `ID PPL`, not only the worker's name.

For each PPL calculate:

```text
PPL name
PML name
number of assigned SubSLS
total target
current cumulative progress
previous cumulative progress
daily/net progress
remaining target
progress percentage
productivity status
Sobat status
```

Where:

```text
current_progress =
SUM(Capaian PPL for current snapshot and PPL)

previous_progress =
SUM(Capaian PPL for previous snapshot and PPL)

daily_progress =
current_progress - previous_progress
```

Do not sum the PPL's cumulative values across historical dates.

---

# 10. Per-PML Metrics

Also design supervisor-level metrics:

```text
PML name
number of PPL supervised
total target
PPL cumulative progress
PML cumulative progress
PML vs PPL gap
review coverage %
daily PPL increase
daily PML increase
```

Potential operational metric:

```text
pending_review =
MAX(PPL progress - PML progress, 0)
```

but verify the business meaning before treating this as authoritative.

---

# 11. Corrections and Negative Daily Values

Snapshot data can be corrected.

Therefore:

```text
current cumulative < previous cumulative
```

may occasionally happen.

Do NOT blindly convert negative differences to zero.

Store/calculate the signed net difference.

For example:

```text
Yesterday = 120
Today = 115

Net change = -5
```

The dashboard can visually distinguish:

* positive progress,
* no change,
* negative correction.

This is important for auditability.

---

# 12. Recommended Dashboard Layout

Design one primary monitoring page.

## Header

Show:

* dashboard title,
* selected snapshot date,
* previous comparison date,
* "last uploaded" timestamp,
* date selector,
* relevant filters.

---

## Section A — Overall KPIs

Cards such as:

```text
Target
Cumulative PPL
Progress %
Today's / Latest Change
Remaining
Cumulative PML
PML Review %
```

Avoid excessive KPI cards.

---

## Section B — Cumulative Progress

Time-series chart:

```text
X-axis = snapshot date
Y-axis = cumulative progress
```

Possible lines:

```text
Target
PPL
PML
```

Target can remain constant or follow the snapshot if target assignments change.

---

## Section C — Daily Progress

Bar chart:

```text
X-axis = snapshot date
Y-axis = delta from previous snapshot
```

Allow switching between:

```text
PPL
PML
```

or display both if it remains readable.

---

## Section D — PPL Performance

Sortable/searchable table:

```text
PPL
PML
Target
Cumulative
Daily Change
Progress %
Remaining
Assigned SLS
Status
```

Useful sorting options:

```text
highest daily progress
lowest daily progress
highest cumulative progress
lowest completion %
largest remaining target
```

Add pagination if necessary.

Allow searching by worker name.

---

## Section E — PML Performance

Table similar to:

```text
PML
PPL Count
Target
PPL Progress
PML Progress
Pending Review
Review %
Daily Change
```

---

## Section F — Productivity

Consider a small chart/count showing:

```text
Sangat Produktif
Produktif
Kurang Produktif
Belum Tersedia
```

Keep this secondary to progress metrics.

---

# 13. Filters

Support useful filters without making the UI complicated.

Potential filters:

```text
Snapshot Date
PML
PPL
Status Produktivitas
Status Sobat
Jenis Mitra
Nama/Kode SLS
```

Filters must affect all relevant cards/charts/tables consistently.

Do not invent Kecamatan/Kelurahan names from the numeric SubSLS code unless a trusted mapping table exists.

If geographic mappings are added later, design an extension point for them.

---

# 14. Laravel API Design

Prefer a straightforward REST API.

Propose endpoints approximately like:

```text
GET /api/dashboard/summary
GET /api/dashboard/timeseries
GET /api/dashboard/ppl
GET /api/dashboard/pml
GET /api/dashboard/filters
```

Example parameters:

```text
date=
from=
to=
pml=
ppl=
status=
search=
sort=
page=
```

Admin endpoints could be conceptually:

```text
GET  /api/admin/progress-uploads
POST /api/admin/progress-uploads/validate
POST /api/admin/progress-uploads
GET  /api/admin/progress-uploads/{id}
```

Do not follow these names blindly.

First inspect existing Laravel route/API conventions.

Use Laravel:

* Form Requests,
* Policies/Gates where appropriate,
* service classes for import/metrics logic,
* database transactions,
* API Resources if the project already uses them.

Do not put all dashboard calculation logic inside controllers.

---

# 15. Query and Performance Strategy

This is a lightweight dashboard.

Avoid premature optimization.

Current order of magnitude is only thousands of rows per snapshot and hundreds of workers.

A normal indexed relational database should be sufficient.

Consider indexes such as:

```text
upload_id
snapshot_date
ppl_id
pml identifier
kode_subsls
status_produktivitas
```

Explain the indexes you recommend.

Prefer SQL aggregation to loading all snapshot rows into PHP and calculating everything in memory.

Avoid N+1 queries.

Do not add a separate analytics database.

Do not add Redis unless measurement later demonstrates a need.

If aggregation endpoints later become expensive, propose caching or pre-aggregated tables as an optional second-stage optimization, not as the initial architecture.

---

# 16. Svelte Architecture

Inspect whether the project uses:

* plain Svelte,
* SvelteKit,
* an existing router,
* an existing state management approach,
* Tailwind or another CSS framework,
* an existing chart library.

Reuse existing conventions.

Do not rewrite the frontend simply to introduce new libraries.

Suggested component decomposition:

```text
DashboardPage
├── DashboardHeader
├── DashboardFilters
├── SummaryCards
├── CumulativeProgressChart
├── DailyProgressChart
├── ProductivitySummary
├── PplPerformanceTable
└── PmlPerformanceTable
```

Keep business calculations in the backend wherever reasonable.

The Svelte frontend should mainly:

```text
fetch → filter/query → render
```

rather than reimplementing metric definitions independently.

Use URL query parameters for filters when practical so views can be bookmarked/shared.

---

# 17. Frontend UX Requirements

The application should feel like a monitoring tool, not a generic admin template.

Prioritize:

* fast first load,
* clear visual hierarchy,
* responsive desktop layout,
* usable tablet/mobile layout,
* compact tables,
* clear percentage formatting,
* Indonesian number formatting where appropriate,
* tooltips explaining ambiguous metrics,
* loading states,
* empty states,
* error states,
* no excessive animation.

Use charts selectively.

Do not create a chart when a number or table communicates the information better.

---

# 18. Admin Interface

The administrator needs a simple upload-history screen.

Display:

```text
Snapshot Date
Filename
Version
Uploaded By
Uploaded At
Rows
Status
Active/Superseded
```

Admin should be able to inspect:

* import validation errors,
* file metadata,
* whether it superseded another snapshot.

Keep original uploaded Excel files in **private storage** for audit purposes if practical.

Do not expose raw files publicly.

---

# 19. Authentication and Data Privacy

Reuse the existing Laravel authentication mechanism if one exists.

At minimum:

```text
Admin
    can upload/manage snapshots

Viewer
    can view dashboard
```

Do not expose emails, internal UUIDs, or assignment URLs in the public-facing dashboard unless there is a specific operational reason.

The dashboard usually only needs worker names and performance metrics.

Protect admin endpoints with authorization.

Validate uploaded file:

* MIME/type,
* extension,
* size,
* worksheet,
* schema,
* content.

Treat Excel content as untrusted input.

---

# 20. Data Consistency Rules

Define a single metric service/query layer so the following always reconcile.

For a selected snapshot:

```text
Overall PPL cumulative
=
SUM(PPL cumulative grouped by PPL)
```

and:

```text
Overall target
=
SUM(target grouped by the appropriate assignment rows)
```

Time-series values, KPI cards, and worker tables must all use the same metric definitions.

Avoid having separate formulas in separate controllers.

---

# 21. Edge Cases

Explicitly design and test:

1. first-ever upload;
2. no upload on a calendar day;
3. two uploads for the same date;
4. corrected upload;
5. exact duplicate upload;
6. worker appears for first time;
7. worker disappears from next snapshot;
8. assignment moves to another worker;
9. `Capaian PPL = -`;
10. `Capaian PML = -`;
11. missing PML;
12. target changes between snapshots;
13. cumulative progress decreases;
14. duplicate `Kode SubSLS`;
15. malformed row;
16. partial import failure;
17. snapshot with zero rows;
18. renamed PPL but same `ID PPL`.

Explain how each should behave.

---

# 22. Testing Strategy

Design automated tests for at least:

### Import tests

```text
valid Excel
missing column
invalid numeric value
"-" → null
duplicate file
same-date replacement/versioning
transaction rollback
```

### Metric tests

```text
first snapshot
daily delta
cumulative progress
PPL aggregation
PML aggregation
negative correction
missing date
changed target
new worker
removed worker
```

### API tests

```text
filtering
sorting
pagination
authorization
```

Include an invariant test where:

```text
summary cumulative PPL
=
sum of PPL table cumulative values
```

for the same filter set.

---

# 23. Expected System Design Deliverable

Before writing implementation code, produce a document containing these sections:

## A. Existing Repository Assessment

Explain:

* Laravel structure
* Svelte structure
* authentication
* database
* styling
* existing useful dependencies
* conventions that should be preserved

## B. Excel Data Assessment

Describe:

* columns
* types
* nullable values
* cardinality/duplicates
* candidate identifiers
* assumptions discovered from the actual workbook

## C. Architecture

Provide a Mermaid diagram:

```text
Admin
  ↓
Laravel Upload API
  ↓
Excel Validator / Import Service
  ↓
Relational Database
  ↓
Dashboard Query Service
  ↓
REST API
  ↓
Svelte Dashboard
```

Improve this diagram based on repository inspection.

## D. Database Schema

Provide:

* proposed tables,
* columns,
* data types,
* foreign keys,
* indexes,
* unique constraints,
* snapshot/versioning strategy.

## E. Metric Definitions

For every displayed metric give:

```text
Metric name
Business meaning
Formula
Source fields
Aggregation level
Edge cases
```

## F. API Contract

For every required endpoint give:

```text
method
path
parameters
response shape
purpose
```

## G. Frontend Design

Show:

* route structure,
* component hierarchy,
* state/data flow,
* filters,
* charts,
* tables.

## H. Admin Upload Flow

Describe upload → validation → confirmation → import → snapshot activation.

## I. Performance

Explain expected query patterns and indexes.

## J. Security

Explain upload security, authentication and authorization.

## K. Testing

List the critical tests.

## L. Implementation Plan

Break implementation into small phases such as:

```text
Phase 1 — database + import
Phase 2 — metric/query service
Phase 3 — dashboard API
Phase 4 — Svelte dashboard shell
Phase 5 — charts and PPL/PML tables
Phase 6 — admin upload UI
Phase 7 — testing and optimization
```

For each phase identify the likely files/modules that will be modified.

---

# 24. Design Philosophy

Use the **simplest architecture that solves the actual problem**.

For this workload, I expect something close to:

```text
Svelte
    ↓
Laravel REST API
    ↓
Laravel metric/query service
    ↓
PostgreSQL/MySQL
```

with immutable daily snapshot tables.

Do not propose:

```text
microservices
Kafka
ClickHouse
Elasticsearch
Redis
data warehouse
event sourcing
CQRS
```

unless repository/data analysis demonstrates a concrete need.

The system should comfortably support hundreds of thousands or a few million historical snapshot rows without changing the fundamental architecture.

---

# 25. Important Instruction Before Coding

Do not begin implementation immediately.

First:

1. inspect the existing repository;
2. inspect the Excel file;
3. inspect the reference dashboard if network access is available;
4. identify existing conventions;
5. produce the system design;
6. identify assumptions and risks;
7. recommend the simplest architecture.

If something is ambiguous, make a reasonable assumption and clearly document it rather than stopping unnecessarily.

At the end provide:

```text
Recommended Architecture
Key Design Decisions
Database Model
Metric Definitions
API Design
Frontend Structure
Implementation Sequence
Risks / Assumptions
```

Then stop and wait for the implementation phase.
