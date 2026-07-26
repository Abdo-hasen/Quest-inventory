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
