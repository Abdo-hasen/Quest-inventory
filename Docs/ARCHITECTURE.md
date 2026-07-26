# Warehouse Inventory Reservation Engine — System Architecture & Design

This document details the architectural decisions, domain model, state transitions, concurrency guarantees, security considerations, and system design trade-offs of the Warehouse Inventory Reservation Engine.

> **Supplementary Specifications:**
> - [DB-ARCHITECTURE.md](../Docs/ARCHITECTURE/DB-ARCHITECTURE.md) — Detailed database schema rationale & table breakdown.
> - [Stories-Architect.md](../Docs/ARCHITECTURE/Stories-Architect.md) — User stories & 11 core architectural questions/answers.

---

## 1. System Overview & Separation of Concerns

### OMS vs. WMS Boundaries
- **Order Management System (OMS)**: Manages customer cart, payment processing (`Pending Payment`, `Paid`, `Refunded`), and front-end ordering.
- **Warehouse Management System (WMS / This Engine)**: Manages physical inventory states (`Open`, `Picked`, `Packed`, `Shipped`). The engine is financial-agnostic and relies on the OMS for payment validation prior to reservation requests.

### Operational Flow & Worker Execution
- **30-Minute Reservation TTL**: The 30-minute expiration rule is an automated safety net against abandoned carts or unpaid reservations (auto-release), NOT a waiting window for warehouse workers.
- **Immediate Worker Dispatch**: When an order reservation is placed (`status = open`), it immediately appears on warehouse operators' handheld scanners and tablet queues via `GET /api/reservations?status=open` for instant picking.

---

## 2. Database Design Decisions

### Core Entities & Relationships
- **`products`**: Product catalog (`id`, `sku`, `name`, `description`, `deleted_at`). Soft-deleted to preserve historical order references.
- **`warehouses`**: Physical fulfillment nodes (`id`, `code`, `name`, `is_active`).
- **`inventories`**: Materialized stock buckets per product and warehouse (`quantity_available`, `quantity_reserved`, `quantity_picked`, `quantity_packed`, `quantity_shipped`).
- **`sales_orders` & `order_lines`**: Customer demand records. Domain entity explicitly named `sales_orders` to avoid framework table collisions.
- **`reservations`**: Allocated stock mapping explicitly to individual `order_item_id` lines (`quantity_reserved`, `quantity_picked`, `quantity_packed`, `status`).
- **`shipments` & `shipment_attempts`**: Parent shipment status record separate from granular execution attempt logs for fast queries.
- **`inventory_movements`**: Append-only transactional audit ledger recording every stock movement.
- **`reservation_histories`**: Audit trail tracking status transitions and quantity adjustments.
- **`idempotency_keys` & `processed_webhook_events`**: Dedicated infrastructure tables for request replay protection and webhook deduplication.

### Storage Pattern: Hybrid Snapshot + Append-Only Ledger
1. **Materialized Snapshot (`inventories`)**: Physical database row holding current quantity counters. Enables fast row locking (`lockForUpdate()`) and DB-level safety net constraints (`CHECK (quantity_available >= 0)`).
2. **Append-Only Ledger (`inventory_movements`)**: Immutable transaction record created atomically with every stock change.

---

## 3. Inventory Movement Strategy

### Immutable Audit Ledger
Every inventory mutation—whether reserving stock, picking, packing, shipping, releasing, expiring, or transferring between warehouses—writes an immutable record to `inventory_movements` inside the same database transaction.

### Movement Classification (`MovementType` Enum)
Movements are strictly typed via the `MovementType` enum:
- `RESERVED`: Stock moved from `quantity_available` to `quantity_reserved`.
- `PICKED`: Reserved stock physically picked by operator.
- `PACKED`: Picked stock packed for shipment.
- `SHIPPED`: Stock deducted from inventory and confirmed delivered.
- `RELEASED` / `EXPIRED`: Unfulfilled stock returned from `quantity_reserved` to `quantity_available`.
- `TRANSFERRED_OUT` / `TRANSFERRED_IN`: Inter-warehouse stock transfers.

### Traceability & Auditability
Each movement records `product_id`, `warehouse_id`, `quantity`, `type`, `user_id`, `reference_type` (`Order`, `Reservation`, `Transfer`), `reference_id`, and `created_at`. Inventory movement history is strictly append-only; records are never updated or deleted.

---

## 4. Reservation Lifecycle & State Machine

```
[Available Stock]
       │
       ▼ (Reserve Stock)
    [ OPEN ] ──(Expire 30m / Cancel)──> [ RELEASED / EXPIRED ] (Stock restored to Available)
       │
       ▼ (Pick)
   [ PICKED ]
       │
       ▼ (Pack)
   [ PACKED ]
       │
       ├─────────────────────────────────┐
       ▼ (Full Shipment Confirmed)        ▼ (Partial Shipment Confirmed)
  [ FULFILLED ]                 [ PARTIALLY_FULFILLED ]
```

### Key Transition Rules
- **Partial Picking & Packing**: Operators can pick and pack incremental quantities.
- **Partial Cancellations**: Cancelling unpicked quantities releases remaining reserved units back to available stock.
- **Partial vs. Full Fulfillment**: If a shipment covers a fraction of the reserved quantity, reservation transitions to `PARTIALLY_FULFILLED` and updates inventory accordingly. Complete shipments transition reservation to `FULFILLED`.
- **Immutability on Fulfillment**: Terminal states (`Fulfilled`, `Expired`, `Released`) are immutable.

---

## 5. Concurrency Handling & Data Integrity

### Race Condition & Over-allocation Protection
- **Pessimistic Row Locking**: All stock allocations execute inside a database transaction with `DB::table('inventories')->where(...)->lockForUpdate()`.
- **Database Safety Net**: MySQL constraint `CHECK (quantity_available >= 0)` guarantees negative stock is structurally impossible.
- **Deadlock Prevention in Transfers**: Inter-warehouse transfers sort and lock inventory rows in ascending `id` order.

### Request Idempotency & Webhook Deduplication
- **Request Idempotency (`idempotency_keys`)**: Parallel or retried client requests containing the `Idempotency-Key` header return cached API responses without re-executing stock reservations.
- **Webhook Deduplication (`processed_webhook_events`)**: Provider webhooks are checked against `event_id` to prevent duplicate delivery processing.

---

## 6. Security Considerations

### Authentication & Authorization
- **Laravel Sanctum Bearer Tokens**: All API endpoints require valid Bearer token authentication.
- **Role-Based Access Control (RBAC)**: Enforced via `UserRole` enum (`admin`, `order_creator`, `warehouse_operator`) with policy authorization gates on every mutating action.

### OWASP & Data Protection Standards
- **Mass Assignment Protection**: OWASP compliance achieved via explicit `$fillable` model definitions.
- **SQL Injection Prevention**: Prepared statements and Eloquent ORM binding on all SQL operations.
- **Input Validation**: Dedicated `FormRequest` validation classes validating parameter types, formats, `Rule::in()` bounds, and `whereNull('deleted_at')` soft-delete constraints.

---

## 7. Async Processing & Mock Shipping Engine

### Background Queue Architecture
- Asynchronous shipment execution is managed via queued jobs (`ProcessShipmentJob`, `CheckShipmentStatusJob`) using the `database` queue driver.

### Mock Shipping Provider Scenarios
- **Success**: Instant delivery confirmation and inventory deduction.
- **Permanent Failure**: Marks shipment failed without modifying stock.
- **Timeout / Pending**: Dispatches asynchronous status polling job (`CheckShipmentStatusJob`). The job queries the carrier API status, applying status updates or scheduling exponential backoff re-checks (up to max retry limits).
- **Duplicate Webhook**: Replayed webhooks are safely ignored.
- **Delayed Success**: Webhook arrival after timeout updates shipment status without double-deducting inventory.

---

## 8. Trade-offs, Scaling & Future Improvements

### Architectural Trade-offs
- **Single-Warehouse-per-Line**: Each order item is reserved from one warehouse to keep allocation deterministic.
- **Pessimistic DB Locks vs. Distributed Locks**: DB row locks (`lockForUpdate()`) were chosen over Redis locks to ensure strict transactional consistency without external infrastructure dependencies.

### Production Scaling Roadmap
1. **Distributed Locks (Redis / Redlock)**: Replace DB pessimistic locks with Redis locks for ultra-high throughput reservation APIs.
2. **Database Sharding**: Partition `inventories` and `reservations` by `warehouse_id`.
3. **Event-Driven Architecture**: Emit domain events (`ReservationCreated`, `ShipmentFulfilled`) to Kafka/RabbitMQ for decoupled downstream ERP integration.
