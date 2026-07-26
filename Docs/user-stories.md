# Warehouse Inventory Reservation Engine — User Stories & Architectural Specifications

This document outlines the architectural choices (10 Core Questions & Rationale), user stories, acceptance criteria, and explicit business logic/technical specifications for the **Warehouse Inventory Reservation Engine API**.

---

## 1. Architectural & Business Logic Decisions (Q&A with Rationale)

### Q1. Should reservations expire automatically?
- **Decision**: Yes — auto-expire via scheduled job (30 min TTL)
- **Why & Rationale**: TTL expiry is standard in reservation engines to prevent inventory hoarding and free unfulfilled stock. It also demonstrates a scheduled Artisan command + background job retry mechanism.

---

### Q2. How should partial shipments affect reservations?
- **Decision**: Split it — shipped qty deducts from reserved stock, remaining qty stays reserved for later shipment
- **Why & Rationale**: "Partial shipment" is an explicitly required failure-scenario demo. Splitting matches real WMS behavior and provides a clean, auditable lifecycle.

---

### Q3. Can inventory be transferred between warehouses while reserved?
- **Decision**: No — only unreserved (available) stock can transfer; reserved units are locked to their warehouse
- **Why & Rationale**: Simplest rule that preserves the "never double-reserve" invariant without requiring complex reservation-following logic across warehouses.

---

### Q4. Hard lock or soft/optimistic reservation?
- **Decision**: Hard reservation — decrement available→reserved inside the same DB transaction, using `lockForUpdate()`
- **Why & Rationale**: Pessimistic row locking (`lockForUpdate()`) is the most direct way to guarantee correctness for parallel reservations — meeting the core requirement for concurrency & race-condition prevention.

---

### Q5. Overselling prevention — defense in depth at the DB layer?
- **Decision**: MySQL `CHECK (quantity_available >= 0)` constraint + app-level row locking
- **Why & Rationale**: A `CHECK` constraint acts as a zero-overhead safety net that guarantees negative inventory is structurally impossible at the database level even if application logic has an edge case.

---

### Q6. Testing framework?
- **Decision**: Pest
- **Why & Rationale**: Satisfies framework requirements while being fast and expressive to write under time pressure, particularly for concurrent API feature tests.

---

### Q7. API authentication?
- **Decision**: Laravel Sanctum token auth on all endpoints
- **Why & Rationale**: Demonstrates OWASP standards and role-based access control (Admin, Order Creator, Warehouse Operator) with minimal setup overhead for an API-only application.

---

### Q8. Can one order line be split across multiple warehouses?
- **Decision**: No — one line reserves from exactly one warehouse
- **Why & Rationale**: Single-warehouse-per-line keeps core allocation logic deterministic and avoids disproportionate cross-warehouse splitting complexity.

---

### Q9. Queue driver for shipment/expiry jobs?
- **Decision**: `database` driver
- **Why & Rationale**: Requires zero extra infrastructure, is inspectable via database tables, and allows demonstrating async job processing, retries, and failure handling.

---

### Q10. How should the mock shipping provider produce its random outcomes?
- **Decision**: Weighted random by default, with an override parameter (`?force_scenario=...`)
- **Why & Rationale**: Guarantees that all 5 specific shipping provider response scenarios (success, failure, timeout, duplicate, rate limit) can be deterministically demonstrated on demand while behaving realistically by default.

---

## 2. Technical Stack & Infrastructure Scope

- **Framework**: Laravel 12 (API-only, no Blade views)
- **Authentication & AuthZ**: Laravel Sanctum bearer tokens with 3 `UserRole` enums (`admin`, `order_creator`, `warehouse_operator`).
- **Testing**: Pest PHP feature & unit test suite.
- **Queue Driver**: `database` queue driver for async background jobs (`ShipmentProcessingJob`, `ExpireReservationsJob`).
- **Audit Ledger**: `inventory_movements` append-only ledger tracking every stock transition.

---

## 3. Epics & User Stories

### Epic 1: Authentication & Access Control

#### US-1.1: Token Authentication & Role Management
- **As an** API Consumer (Admin, Order Creator, Warehouse Operator)
- **I want to** authenticate via `/api/login` and receive a Sanctum bearer token with my associated role
- **So that** I can access role-protected endpoints securely.

##### Acceptance Criteria:
1. `POST /api/login` validates credentials and returns a Sanctum bearer token and `role` string.
2. `POST /api/logout` revokes current access token (requires `auth:sanctum`).
3. Unauthorized requests return `401 Unauthorized` adhering to standard `InteractWithResponse` shape.
4. Access to domain actions is governed by Gates (`auth:sanctum` + role permissions).

---

### Epic 2: Inventory Management & Transfers

#### US-2.1: Stock Level Management & Warehouses
- **As an** Admin
- **I want to** manage warehouses and initialize/update available stock levels
- **So that** inventory is accurately reflected per warehouse.

##### Acceptance Criteria:
1. Warehouses support active/inactive status.
2. `inventory` records store materialized counters: `quantity_available`, `quantity_reserved`, `quantity_picked`, `quantity_packed`, `quantity_shipped`.
3. Stock adjustments write to both `inventory` counters and `inventory_movements` ledger in a single transaction.
4. Database enforces `quantity_available >= 0`.

#### US-2.2: Inter-Warehouse Stock Transfer
- **As a** Warehouse Operator
- **I want to** transfer available stock between two warehouses
- **So that** inventory is rebalanced without compromising existing reservations.

##### Acceptance Criteria:
1. Only `quantity_available` can be transferred.
2. Rows for source and target warehouses are locked in ascending `id` order to prevent deadlocks.
3. If source warehouse `quantity_available < transfer_quantity`, operation fails with `422 Unprocessable Entity`.
4. Movement entries are created for both source (negative delta) and destination (positive delta).

---

### Epic 3: Sales Orders & Reservation Engine

#### US-3.1: Sales Order Creation & Hard Reservation
- **As an** Order Creator
- **I want to** place a sales order specifying line items and fulfillment warehouses
- **So that** stock is atomically locked and reserved.

##### Acceptance Criteria:
1. `POST /api/orders` accepts `customer_name`, `idempotency_key`, and `order_lines`.
2. `lockForUpdate()` locks source inventory rows inside a `DB::transaction()`.
3. Checks if `quantity_available >= line_qty`. If sufficient, moves `line_qty` from `quantity_available` to `quantity_reserved`.
4. Creates a `reservation` record with a 30-minute expiration timestamp (`expires_at`).
5. Supports `Idempotency-Key` header to prevent duplicate orders during client retries.

#### US-3.2: Automated Reservation Expiry
- **As the** System
- **I want to** automatically release reservations older than 30 minutes
- **So that** unfulfilled stock returns to available pool.

##### Acceptance Criteria:
1. Artisan command `reservations:clean-expired` runs on schedule (or manual execution).
2. Expired pending reservations transition to `EXPIRED`.
3. Stock is returned: `quantity_reserved` decremented, `quantity_available` incremented.
4. `inventory_movements` logs `RESERVATION_EXPIRED`.

---

### Epic 4: Order Fulfillment & Shipment Lifecycle

#### US-4.1: Warehouse Pick & Pack Workflow
- **As a** Warehouse Operator
- **I want to** update order status through Picked and Packed stages
- **So that** stock is staged for dispatch.

##### Acceptance Criteria:
1. **Pick**: Moves quantity from `quantity_reserved` to `quantity_picked`.
2. **Pack**: Moves quantity from `quantity_picked` to `quantity_packed`.
3. Status transitions are validated (e.g. cannot Pack before Picked).
4. Each state update logs an `inventory_movement`.

#### US-4.2: Dispatch & Mock Shipping Provider Integration
- **As a** Warehouse Operator / System
- **I want to** dispatch packed orders to external shipping providers
- **So that** shipments are dispatched asynchronously with failure/retry handling.

##### Acceptance Criteria:
1. Dispatch triggers `ShipmentProcessingJob` on the `database` queue.
2. Mock shipping service simulates weighted random outcomes (Success, Failure, Timeout, Duplicate).
3. Optional query parameter `?force_scenario={scenario}` forces explicit test outcomes.
4. **On Success**: `quantity_packed` decremented, `quantity_shipped` incremented; order status `SHIPPED`.
5. **Partial Shipment**: Shipped items update to `SHIPPED`; remaining unfulfilled line items stay reserved.
6. **On Failure/Timeout**: Job retries up to configured max attempts; failed jobs log to `shipment_attempts`.
7. Webhook callback deduplication uses `processed_webhook_events` to ignore duplicate provider notifications.

---

## 4. API Response Contract Baseline

All API endpoints follow the standard `InteractWithResponse` payload structure:

```json
{
  "ok": true,
  "code": 200,
  "message": "Operation completed successfully",
  "direct": null,
  "data": {}
}
```

Error responses:

```json
{
  "ok": false,
  "code": 422,
  "message": "Validation or business rule failure",
  "direct": null,
  "data": null
}
```
