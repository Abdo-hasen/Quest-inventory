# User Stories — Warehouse Inventory Reservation Engine

## Overview

This document contains user stories for the **Warehouse Inventory Reservation Engine**, the core Laravel API module built for The system manages inventory across multiple warehouses through a full reservation lifecycle (Available → Reserved → Picked → Packed → Shipped) while guaranteeing correctness under concurrency, retries, and duplicate external events.

**Scope note:** This is an **API-only** deliverable (no Blade views). All "actors" below interact through authenticated API endpoints, Artisan commands, or automated background jobs — there is no end-user UI in this MVP.

---

## Confirmed Decisions Reference

These decisions were confirmed with the client before design work began, and drive several stories below:

| # | Decision | Choice |
|---|----------|--------|
| Q1 | Reservation expiry | Auto-expire via scheduled job, 30 min TTL (configurable) |
| Q2 | Partial shipment effect | Split — shipped qty deducts from reserved; remainder stays reserved |
| Q3 | Transfer while reserved | Only *available* (unreserved) stock can transfer between warehouses |
| Q4 | Locking strategy | Hard reservation — `lockForUpdate()` inside a DB transaction |
| Q5 | Oversell prevention | App-level row locking + DB `CHECK (quantity_available >= 0)` constraint |
| Q6 | Test framework | Pest |
| Q7 | API auth | Laravel Sanctum (bearer tokens) |
| Q8 | Cross-warehouse split | Not supported — one order line reserves from exactly one warehouse |
| Q9 | Queue driver | `database` (inspectable, supports realistic retry/crash demos) |
| Q10 | Mock provider randomness | Weighted random by default, with a forced-scenario override for demos/tests |

---

## Actors

- **Admin** — configures products, warehouses, and users; full oversight.
- **Order Creator** — authenticated API client role that creates sales orders and triggers reservations (e.g. sales/ops staff or an upstream system).
- **Warehouse Operator** — manages physical inventory: releases reservations, picks/packs/ships, initiates transfers.
- **System (Scheduler & Queue Worker)** — background actor: expires stale reservations, processes shipment jobs, retries failures.
- **Shipping Provider (Webhook Caller)** — external system (mocked in this project) posting shipment status callbacks, sometimes duplicated or delayed.

---

## 1. Authentication & Authorization

### US-1.1: API Token Issuance
**As an** Order Creator or Warehouse Operator
**I want** to authenticate via email/password and receive an API token
**So that** I can securely call protected inventory endpoints

**Acceptance Criteria:**
- `POST /api/login` accepts email + password
- Valid credentials return a Sanctum bearer token + user role
- Invalid credentials return `401` with a generic error (no user enumeration)
- All protected endpoints require `Authorization: Bearer <token>`
- `POST /api/logout` revokes the current token

**Expected Result:** Authenticated clients receive a role-scoped token for all subsequent requests.

### US-1.2: Role-Based Authorization
**As the** System
**I want** to restrict each endpoint to the roles allowed to use it
**So that** only authorized actors can mutate inventory state

**Acceptance Criteria:**
- Roles: `admin`, `order_creator`, `warehouse_operator`
- Unauthorized role access returns `403` via Policies/Gates (not just route middleware)
- Admin-only: product/warehouse CRUD, user management, manual stock adjustments
- Order Creator: create/view own orders
- Warehouse Operator: reservation release, pick/pack/ship actions, transfers
- All roles: read-only inventory/reporting endpoints

**Expected Result:** Every endpoint enforces authorization; unauthorized calls never mutate state.

---

## 2. Catalog & Warehouse Setup (Admin)

### US-2.1: Manage Products
**As an** Admin
**I want** to create and update products
**So that** inventory can be tracked per SKU

**Acceptance Criteria:**
- `POST` / `PUT /api/products` with name, unique SKU, description
- SKU uniqueness enforced at DB and validation layer
- Products are soft-deleted (never hard-deleted) to preserve historical order/movement references
- Validation failures return `422` with field-level messages

**Expected Result:** Products exist as the base unit inventory is tracked against.

### US-2.2: Manage Warehouses
**As an** Admin
**I want** to create and update warehouses
**So that** stock can be tracked per physical location

**Acceptance Criteria:**
- `POST` / `PUT /api/warehouses` with name, unique code, address
- Warehouses can be marked active/inactive
- Inactive warehouses are excluded from new reservations but remain visible historically

**Expected Result:** Warehouses exist as the location dimension for all inventory records.

### US-2.3: Seed or Adjust Baseline Stock
**As an** Admin
**I want** to set or adjust a product's available quantity in a warehouse
**So that** the engine starts from an accurate baseline

**Acceptance Criteria:**
- `POST /api/inventory/adjust` with product_id, warehouse_id, quantity, reason
- Every adjustment writes an `inventory_movements` record (`type: adjustment`)
- An adjustment can never bring available stock below zero
- Requires `admin` role

**Expected Result:** All baseline stock and manual corrections are traceable via movement history.

---

## 3. Inventory Visibility & Reporting

### US-3.1: View Current Stock Levels
**As** any authenticated user
**I want** to query available, reserved, picked, packed, and shipped quantities
**So that** I know what can be safely promised to a new order

**Acceptance Criteria:**
- `GET /api/inventory?product_id=&warehouse_id=` returns all quantity states
- Values always reconcile (available + reserved + picked + packed + shipped = total on hand, derived from movements)
- Omitting `warehouse_id` returns a per-warehouse breakdown

**Expected Result:** Stock state is queryable in real time and is always internally consistent.

### US-3.2: View Inventory Movement History
**As a** Warehouse Operator or Admin
**I want** a chronological ledger of every stock change for a product/warehouse
**So that** I can audit how stock reached its current state

**Acceptance Criteria:**
- `GET /api/inventory/{product_id}/movements` (paginated)
- Each entry: type (`reserve`, `release`, `pick`, `pack`, `ship`, `transfer_in`, `transfer_out`, `adjustment`), quantity delta, related order/reservation ID, actor, timestamp
- Movements are append-only — never updated or deleted

**Expected Result:** Every stock change is independently reconstructable and auditable.

### US-3.3: View Open Reservations
**As a** Warehouse Operator
**I want** to list all currently active (non-expired, non-released, non-fulfilled) reservations
**So that** I understand outstanding demand against available stock

**Acceptance Criteria:**
- `GET /api/reservations?status=open`
- Returns product, warehouse, quantity, order reference, `created_at`, `expires_at`
- Filterable by warehouse or product

**Expected Result:** Operators can see exactly what stock is currently promised but not yet shipped.

### US-3.4: View Orders That Consumed Inventory
**As an** Order Creator or Admin
**I want** to see which orders have reserved, picked, packed, or shipped stock
**So that** I can trace inventory consumption back to demand

**Acceptance Criteria:**
- `GET /api/orders/{id}` includes per-line reservation status
- `GET /api/orders?consumed=true` lists orders with at least one non-cancelled reservation
- Each line shows its current stage

**Expected Result:** Full traceability exists from order → reservation → shipment.

---

## 4. Reservation Lifecycle

### US-4.1: Create Order & Reserve Inventory
**As an** Order Creator
**I want** to submit an order and have inventory reserved atomically
**So that** stock is guaranteed available before I promise it to a customer

**Acceptance Criteria:**
- `POST /api/orders` with lines: `[{product_id, warehouse_id, quantity}]`
- Each line reserves from exactly **one** warehouse (no auto-split across warehouses)
- Reservation + inventory decrement occur in a single DB transaction using `lockForUpdate()` on the inventory row
- If any line's requested quantity exceeds available stock, the **entire** request fails (`422`) with **no partial reservation**
- On success: `available -= qty`, `reserved += qty`; a reservation row and movement row are created
- Response includes reservation IDs and an `expires_at` timestamp

**Expected Result:** Orders either reserve fully and safely, or fail cleanly with zero side effects.

### US-4.2: Prevent Overselling Under Concurrency
**As the** System
**I want** two simultaneous reservation requests for the last unit of stock to resolve safely
**So that** inventory is never oversold

**Acceptance Criteria:**
- Pessimistic row locking ensures only one transaction decrements a given inventory row at a time
- The second concurrent request re-reads the post-lock quantity and correctly fails if insufficient stock remains
- A DB-level `CHECK (quantity_available >= 0)` constraint acts as a final safety net
- A concurrency test simulates two parallel requests for the last item; exactly one succeeds

**Expected Result:** Available stock never goes negative and is never double-reserved, under any timing.

### US-4.3: Idempotent Reservation Requests
**As an** Order Creator (or a retrying client/queue)
**I want** to safely resubmit the same reservation command
**So that** network retries or duplicate submissions don't create duplicate reservations

**Acceptance Criteria:**
- `POST /api/orders` accepts an optional `Idempotency-Key` header
- A repeated request with the same key returns the original result instead of re-executing
- Idempotency keys are stored with their result for a defined retention window
- Verified by a test that sends the identical request twice

**Expected Result:** Re-running the same reservation command any number of times produces exactly one reservation.

### US-4.4: Release / Cancel a Reservation
**As a** Warehouse Operator
**I want** to manually release an open reservation
**So that** stock becomes available again if the order is cancelled or changed

**Acceptance Criteria:**
- `POST /api/reservations/{id}/release`
- Only `open` reservations can be released this way (picked/packed portions use partial cancellation, US-4.6)
- On release: `reserved -= qty`, `available += qty`, status → `released`, movement record created
- Releasing an already-released/expired reservation returns `409` as an idempotent no-op

**Expected Result:** Released stock is immediately available for new reservations, with a full audit trail.

### US-4.5: Automatic Reservation Expiry
**As the** System (Scheduler)
**I want** open reservations to expire automatically after a configurable TTL (default 30 minutes)
**So that** inventory isn't held indefinitely by abandoned or stalled orders

**Acceptance Criteria:**
- Scheduled Artisan command (`reservations:expire`) runs every minute via the Laravel scheduler
- Any reservation with status `open` and `expires_at < now()` is released using US-4.4's logic
- The job is idempotent and safe if run concurrently or twice (row locking + status guard)
- Expired reservations are marked `expired` (distinct from manually `released`) for reporting

**Expected Result:** Stale reservations self-heal without manual intervention, and re-running the job never double-releases stock.

### US-4.6: Partial Order Cancellation After Reservation
**As a** Warehouse Operator
**I want** to cancel part of an order's reserved quantity
**So that** customers can reduce quantities without cancelling the whole order

**Acceptance Criteria:**
- `PATCH /api/orders/{id}/lines/{line_id}` with a reduced quantity
- Only the unconsumed delta is released back to available stock
- Cannot reduce below the quantity already picked/packed/shipped for that line
- Fully cancelling a line before any picking behaves like US-4.4 for that line

**Expected Result:** Partial cancellations adjust exactly the unconsumed portion of a reservation.

---

## 5. Picking & Packing

### US-5.1: Mark Reservation as Picked
**As a** Warehouse Operator
**I want** to mark reserved stock as picked
**So that** the system reflects physical warehouse progress before shipment

**Acceptance Criteria:**
- `POST /api/reservations/{id}/pick` with quantity (defaults to full reserved quantity)
- `reserved -= qty`, `picked += qty`
- Cannot pick more than currently reserved for that reservation
- Movement record created; reservation transitions partially or fully to `picked`

**Expected Result:** Picked stock is tracked distinctly from reserved stock for accurate stage reporting.

### US-5.2: Mark Picked Stock as Packed
**As a** Warehouse Operator
**I want** to mark picked stock as packed
**So that** it's ready to enter the shipment process

**Acceptance Criteria:**
- `POST /api/reservations/{id}/pack` with quantity
- `picked -= qty`, `packed += qty`
- Cannot pack more than currently picked

**Expected Result:** Only packed stock is eligible to enter the shipment queue.

---

## 6. Shipment Processing

### US-6.1: Process Pending Shipments
**As the** System (Queue Worker)
**I want** a queued job to process each ready-to-ship reservation
**So that** shipment confirmation and inventory deduction happen reliably and asynchronously

**Acceptance Criteria:**
- Artisan command `shipments:process` dispatches a `ProcessShipmentJob` per packed shipment
- Job calls the mock shipping provider and awaits a result
- Jobs run on the `database` queue driver so retries/failures are inspectable
- Each attempt is logged with status: `pending`, `in_transit`, `shipped`, `failed`, `timeout`

**Expected Result:** Shipments are processed asynchronously with full visibility into every attempt.

### US-6.2: Confirm Inventory Deduction on Shipment Success
**As the** System
**I want** successful shipment confirmation to move stock from packed to shipped
**So that** inventory reflects reality once goods leave the warehouse

**Acceptance Criteria:**
- On success callback: `packed -= qty`, `shipped += qty`
- Reservation status → `fulfilled` once fully shipped (or stays partially fulfilled per US-6.? partial logic)
- Movement record created (`type: ship`)
- Operation wrapped in a DB transaction so a mid-process crash cannot leave inventory half-updated

**Expected Result:** Shipped stock is deducted exactly once, only on true confirmation.

### US-6.3: Handle Partial Shipment
**As the** System
**I want** a shipment covering less than the full packed quantity to only affect that portion
**So that** the remainder stays correctly tracked for a later shipment

**Acceptance Criteria:**
- Shipment confirmation can specify a shipped quantity less than the packed quantity
- Only the shipped portion moves `packed → shipped`; the remainder stays in `packed` awaiting its own shipment event
- The reservation is not marked `fulfilled` until all quantity has shipped

**Expected Result:** Partial shipments are fully supported without losing track of the unshipped remainder.

### US-6.4: Handle Shipment Failure (Permanent)
**As the** System
**I want** a permanently failed shipment to land in a resolvable, visible state
**So that** failures don't silently strand inventory

**Acceptance Criteria:**
- On permanent failure: shipment status → `failed`
- Packed stock is **not** auto-released — it's flagged for Warehouse Operator review (failures need human judgment, not silent auto-release)
- Operator can then retry the shipment or explicitly release the stock

**Expected Result:** Permanent failures are visible and actionable, never silently lost or auto-resolved incorrectly.

### US-6.5: Handle Timeout Followed by Late Confirmation
**As the** System
**I want** a timed-out shipment that later confirms success to still process correctly
**So that** slow provider responses never cause double-processing or lost confirmations

**Acceptance Criteria:**
- A timeout marks the attempt `timeout` and schedules a status-check retry (not an automatic failure)
- If the provider later confirms success, the job checks the shipment isn't already `shipped` before applying US-6.2
- If already shipped via another path, the late confirmation is a no-op

**Expected Result:** Timeouts never cause duplicate deduction or a shipment stuck in limbo.

### US-6.6: Receive Duplicate Shipment Webhooks Safely
**As the** System
**I want** repeated delivery-confirmation webhooks for the same shipment to have no additional effect
**So that** provider retries or duplicate events can't double-deduct inventory

**Acceptance Criteria:**
- `POST /api/webhooks/shipping` accepts a provider-supplied event ID
- Event IDs are recorded in a `processed_webhook_events` table with a unique constraint
- A duplicate event ID is acknowledged (`200 OK`) but produces zero inventory/status changes
- Verified by a test sending the identical webhook payload twice

**Expected Result:** Any number of duplicate webhook deliveries yields exactly one inventory update.

### US-6.7: Safe Recovery from Worker Crash Mid-Processing
**As the** System
**I want** a crashed queue worker to leave inventory in a consistent state
**So that** a retried job cannot double-apply a partially completed shipment

**Acceptance Criteria:**
- All multi-step inventory mutations occur inside one DB transaction per job attempt
- A crash before commit rolls back the whole transaction — no partial state persists
- Laravel's queue retry/backoff re-runs the job; it re-checks current state before mutating (status guards, not blind re-application)
- Verified by a test that throws mid-job and asserts inventory is unchanged, then a clean retry succeeds

**Expected Result:** Crashes and retries never leave inventory half-updated or double-updated.

---

## 7. Mock Shipping Provider (Test Harness)

### US-7.1: Simulate Realistic Shipping Provider Outcomes
**As a** Developer/Reviewer
**I want** a fake shipping provider that randomly succeeds, fails, times out, duplicates, or delays
**So that** every real-world failure mode can be demonstrated and tested without a real courier integration

**Acceptance Criteria:**
- `MockShippingProvider` returns one of: `success`, `permanent_failure`, `timeout`, `delayed_success`, `duplicate_confirmation`
- Default mode: weighted-random outcome per call
- Override mode: a forced parameter (e.g. `?force_scenario=duplicate`) to reliably trigger a specific scenario for the demo video and automated tests
- Every outcome is logged with enough detail to reproduce it

**Expected Result:** All required failure scenarios are reliably demonstrable and deterministically testable — not left to chance.

---

## 8. Inventory Transfers

### US-8.1: Transfer Available Stock Between Warehouses
**As a** Warehouse Operator
**I want** to transfer only unreserved stock from one warehouse to another
**So that** warehouse-to-warehouse movement never breaks an existing customer reservation

**Acceptance Criteria:**
- `POST /api/inventory/transfer` with product_id, from_warehouse_id, to_warehouse_id, quantity
- Only currently **available** stock (not reserved/picked/packed) is eligible to transfer
- Atomic operation: decrement at source, increment at destination, in one transaction with row locks on both inventory rows (acquired in a consistent order to avoid deadlock)
- Two linked movement records created (`transfer_out`, `transfer_in`)
- Reserved stock is never affected by a transfer

**Expected Result:** Transfers move only free stock and never invalidate an open reservation.

---

## 9. Auditability

### US-9.1: Full Reservation History Trail
**As an** Admin
**I want** to see every state change a reservation went through
**So that** I can explain any inventory discrepancy during a dispute or audit

**Acceptance Criteria:**
- `GET /api/reservations/{id}/history`
- Shows every transition (`created → picked → packed → shipped/released/expired`) with timestamp and actor
- History is immutable and derived from `reservation_history` + `inventory_movements`

**Expected Result:** Any reservation's full lifecycle can be reconstructed after the fact.

---

## 10. Non-Functional Requirements

### US-10.1: Concurrency-Safe Inventory Writes
**As the** System
**I want** all inventory-mutating operations to use consistent locking and transactions
**So that** correctness holds regardless of load or timing

**Acceptance Criteria:**
- Every write path (reserve, release, pick, pack, ship, transfer, adjust) wraps its critical section in `DB::transaction()` with `lockForUpdate()`
- No inventory mutation ever happens outside a transaction
- Automated concurrency tests simulate parallel/racing requests (e.g. Pest test firing simultaneous reservation attempts)

**Expected Result:** Concurrency correctness is enforced by design and proven by tests, not assumed.

### US-10.2: API Security Baseline (OWASP)
**As the** System
**I want** every endpoint to validate input, authorize the caller, and avoid common vulnerabilities
**So that** the API is safe to expose to real clients

**Acceptance Criteria:**
- All input validated via Form Requests (whitelisted, typed rules)
- All queries use Eloquent/query builder parameter binding — no raw-SQL injection surface
- Mass assignment protected via `$fillable`
- Rate limiting applied to auth and webhook endpoints
- Authorization enforced via Policies/Gates on every mutating endpoint
- No sensitive data (tokens, passwords) ever logged

**Expected Result:** The API meets baseline OWASP Top 10 expectations for auth, authorization, and injection risks.

### US-10.3: Automated Test Coverage for Business-Critical Paths
**As the** Development Team
**I want** Pest tests covering the highest-risk scenarios named in the brief
**So that** correctness is provable, not just claimed

**Acceptance Criteria:**
- Feature test: reserve → pick → pack → ship happy path
- Concurrency test: two simultaneous reservations for last-unit stock
- Idempotency tests: duplicate reservation command, duplicate shipment webhook
- Failure tests: permanent failure, timeout-then-success, worker crash mid-job (simulated exception + retry)
- Unit tests: expiry logic, partial shipment math, transfer validation (available-only)
- Test run produces a passing-suite screenshot for submission evidence

**Expected Result:** Every real-world condition listed in the brief has at least one corresponding automated test.

---

## Out of Scope (MVP)

- No frontend/UI — API only, no Blade views (per project direction)
- No email/SMS notifications (not required by the brief)
- No cross-warehouse auto-split fulfillment for a single order line
- No real courier integration — mock provider only
- No customer-facing portal — API consumers are internal actors (Order Creator, Warehouse Operator, Admin)

---

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | API Token Issuance | High | Pending |
| US-1.2 | Role-Based Authorization | High | Pending |
| US-2.1 | Manage Products | Medium | Pending |
| US-2.2 | Manage Warehouses | Medium | Pending |
| US-2.3 | Seed/Adjust Baseline Stock | Medium | Pending |
| US-3.1 | View Current Stock Levels | High | Pending |
| US-3.2 | View Inventory Movement History | High | Pending |
| US-3.3 | View Open Reservations | Medium | Pending |
| US-3.4 | View Orders That Consumed Inventory | Medium | Pending |
| US-4.1 | Create Order & Reserve Inventory | High | Pending |
| US-4.2 | Prevent Overselling Under Concurrency | High | Pending |
| US-4.3 | Idempotent Reservation Requests | High | Pending |
| US-4.4 | Release/Cancel a Reservation | High | Pending |
| US-4.5 | Automatic Reservation Expiry | High | Pending |
| US-4.6 | Partial Order Cancellation | Medium | Pending |
| US-5.1 | Mark Reservation as Picked | Medium | Pending |
| US-5.2 | Mark Picked Stock as Packed | Medium | Pending |
| US-6.1 | Process Pending Shipments | High | Pending |
| US-6.2 | Confirm Inventory Deduction on Success | High | Pending |
| US-6.3 | Handle Partial Shipment | High | Pending |
| US-6.4 | Handle Shipment Failure (Permanent) | High | Pending |
| US-6.5 | Handle Timeout + Late Confirmation | High | Pending |
| US-6.6 | Receive Duplicate Shipment Webhooks | High | Pending |
| US-6.7 | Safe Recovery from Worker Crash | High | Pending |
| US-7.1 | Simulate Shipping Provider Outcomes | High | Pending |
| US-8.1 | Transfer Available Stock Between Warehouses | Medium | Pending |
| US-9.1 | Full Reservation History Trail | Low | Pending |
| US-10.1 | Concurrency-Safe Inventory Writes | High | Pending |
| US-10.2 | API Security Baseline (OWASP) | Medium | Pending |
| US-10.3 | Automated Test Coverage | Medium | Pending |
