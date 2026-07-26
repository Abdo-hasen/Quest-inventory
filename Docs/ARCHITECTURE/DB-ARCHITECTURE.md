# Architecture & Key Technical Decisions

This document details the architectural decisions, domain design trade-offs, and rationale behind the Warehouse Inventory Reservation Engine.

---

## 1. Authentication & Authorization: Single `User` Model + Enum Role

### Decision
Use the default `users` table with a `role` string column cast to a PHP backed enum (`UserRole`: `admin`, `order_creator`, `warehouse_operator`). Avoid separate `admins` tables and third-party packages like `spatie/laravel-permission`.

### Rationale
- **Fixed Role Set:** The system requires exactly 3 fixed roles. Dynamic permissions are not required.
- **YAGNI & Simplicity:** Spatie permissions adds 5+ tables, complex join queries, and extra cache overhead. A single column with a PHP Enum provides full type safety, easy policy checks (`$user->role === UserRole::Admin`), and zero overhead.
- **Single Auth Guard:** Having a single `sanctum` guard on `User` simplifies API authentication.

---

## 2. Idempotency & Webhook Replay Safety (`idempotency_keys` & `processed_webhook_events`)

### Decision
Create dedicated tables for client request idempotency (`idempotency_keys`) and external webhook deduplication (`processed_webhook_events`).

### Rationale
- **Network Retries (`idempotency_keys`):** Prevents duplicate order creation when network issues cause client retries. Stores the `Idempotency-Key` header alongside the cached HTTP response code and payload.
- **Duplicate Callback Guard (`processed_webhook_events`):** Shipping providers frequently resend delivery webhooks. A unique constraint on `event_id` ensures duplicate callbacks return `200 OK` instantly without re-executing inventory deduction logic.

---

## 3. Shipment Tracking: Parent `shipments` + Child `shipment_attempts` 

### Decision
Separate shipment tracking into a parent `shipments` table (overall status) and a child `shipment_attempts` table (log of every provider execution).

### Rationale
- **State vs. History Separation:** Querying current shipment status stays fast (`shipments.status`), while every background job execution, timeout, or retry is logged in `shipment_attempts` with raw provider payloads.
- **Inspectability & Retries:** Enables auditing worker retries, timeouts, and failures independently without mutating the parent record's creation metadata.

---

## 4. Table Naming: `sales_orders` instead of `orders`

### Decision
Name the demand entity `sales_orders` (and `order_lines`) instead of `orders`.

### Rationale
- **Conflict Prevention:** Avoids conflicts with default package table names, standard framework conventions, or database reserved keywords.
- **Domain Clarity:** In ERP/logistics domains, "Sales Order" explicitly distinguishes customer demand from "Purchase Orders" or internal "Transfer Orders".

---

## 5. Inventory Management: Hybrid Snapshot + Ledger Model 

### Decision
Use a **hybrid model**:
1. `inventory` table: Holds materialized quantity snapshot buckets (`quantity_available`, `quantity_reserved`, `quantity_picked`, `quantity_packed`, `quantity_shipped`).
2. `inventory_movements` table: Append-only transaction ledger recording every delta (+ or -).

### Rationale
- **Pessimistic Locking Support (`lockForUpdate()`):** Row-level locking requires a physical row with snapshot columns. Aggregating movements on the fly cannot lock a single row for atomic decrements.
- **Database Safety Net (`CHECK (quantity_available >= 0)`):** Materialized columns allow DB-level `CHECK` constraints to guarantee stock never drops below zero.
- **Complete Auditability:** The `inventory_movements` table records every transition with actor and order references. Materialized counters and movements are updated together in a single `DB::transaction()`.
