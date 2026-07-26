# Database Schema & Structure — Warehouse Inventory Reservation Engine

## Overview

This document defines the complete database structure, tables, columns, indexes, foreign keys, and backed PHP enums for the Warehouse Inventory Reservation Engine.

---

## PHP Enums (Backed String Enums)

- `UserRole`: `admin`, `order_creator`, `warehouse_operator`
- `OrderStatus`: `open`, `partially_fulfilled`, `fulfilled`, `cancelled`
- `ReservationStatus`: `open`, `picked`, `packed`, `partially_fulfilled`, `fulfilled`, `released`, `expired`
- `MovementType`: `reserve`, `release`, `pick`, `pack`, `ship`, `transfer_in`, `transfer_out`, `adjustment`
- `ShipmentStatus`: `pending`, `in_transit`, `shipped`, `failed`, `timeout`
- `ShipmentAttemptStatus`: `success`, `permanent_failure`, `timeout`, `delayed_success`

---

## Tables & Schema Definitions

### 1. Existing Table Modification: `users`

Added column for role assignment:

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `role` | `string` | default `'order_creator'` | User role (`admin`, `order_creator`, `warehouse_operator`) |

---

### 2. `products`

Catalog items tracked by unique SKU. Uses soft deletes.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `name` | `string` | required | Product display name |
| `sku` | `string` | unique | Stock Keeping Unit |
| `description` | `text` | nullable | Optional product details |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `deleted_at` | `timestamp` | nullable | Soft delete timestamp |

---

### 3. `warehouses`

Physical warehouse locations. Active/inactive toggle instead of soft deletes.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `name` | `string` | required | Warehouse name |
| `code` | `string` | unique | Short unique warehouse identifier |
| `address` | `text` | nullable | Physical address |
| `is_active` | `boolean` | default `true` | Inactive warehouses are excluded from new reservations |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

---

### 4. `inventory`

Per product × warehouse materialized stock snapshot. Enforces `CHECK (quantity_available >= 0)` in MySQL.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `product_id` | `foreignId` | FK → `products.id`, cascade delete | Referenced product |
| `warehouse_id` | `foreignId` | FK → `warehouses.id`, cascade delete | Referenced warehouse |
| `quantity_available` | `unsignedInteger` | default `0`, `CHECK (quantity_available >= 0)` | Unreserved stock available for new orders |
| `quantity_reserved` | `unsignedInteger` | default `0` | Stock currently held in active reservations |
| `quantity_picked` | `unsignedInteger` | default `0` | Stock picked from physical shelves |
| `quantity_packed` | `unsignedInteger` | default `0` | Stock packed and ready for shipment |
| `quantity_shipped` | `unsignedInteger` | default `0` | Stock confirmed as shipped |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

**Indexes & Constraints:**
- `UNIQUE (product_id, warehouse_id)`

---

### 5. `sales_orders`

Demand origin for inventory reservations.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `user_id` | `foreignId` | FK → `users.id`, restrict/cascade | Order creator |
| `status` | `string` | default `'open'` | Order lifecycle status (`OrderStatus` enum) |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

---

### 6. `order_lines`

Individual item lines within a sales order.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `sales_order_id` | `foreignId` | FK → `sales_orders.id`, cascade delete | Parent order |
| `product_id` | `foreignId` | FK → `products.id` (supports soft-deleted) | Target product |
| `warehouse_id` | `foreignId` | FK → `warehouses.id` | Source warehouse |
| `quantity` | `unsignedInteger` | required | Requested quantity |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

---

### 7. `reservations`

Holds on inventory allocated to an order line with automatic or manual expiry.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `order_line_id` | `foreignId` | FK → `order_lines.id`, cascade delete | Parent order line |
| `product_id` | `foreignId` | FK → `products.id` (supports soft-deleted) | Denormalized product ID for direct query speed |
| `warehouse_id` | `foreignId` | FK → `warehouses.id` | Denormalized warehouse ID |
| `quantity` | `unsignedInteger` | required | Total reserved quantity |
| `quantity_picked` | `unsignedInteger` | default `0` | Quantity physically picked |
| `quantity_packed` | `unsignedInteger` | default `0` | Quantity packed into shipment |
| `quantity_shipped` | `unsignedInteger` | default `0` | Quantity confirmed shipped |
| `quantity_released` | `unsignedInteger` | default `0` | Quantity returned to available stock |
| `status` | `string` | default `'open'`, index | Reservation stage (`ReservationStatus` enum) |
| `expires_at` | `timestamp` | index | Expiration timestamp (default +30 mins) |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

---

### 8. `reservation_history`

Immutable audit trail of state transitions for each reservation.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `reservation_id` | `foreignId` | FK → `reservations.id`, cascade delete | Target reservation |
| `from_status` | `string` | nullable | Previous status |
| `to_status` | `string` | required | New status |
| `quantity_affected` | `unsignedInteger` | nullable | Quantity transition delta |
| `actor_id` | `foreignId` | FK → `users.id`, nullable | User performing action (null for system/job) |
| `notes` | `text` | nullable | Optional context notes |
| `created_at` | `timestamp` | default current timestamp | Transition timestamp |

---

### 9. `inventory_movements`

Append-only ledger documenting every stock movement across all states.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `product_id` | `foreignId` | FK → `products.id` (supports soft-deleted) | Target product |
| `warehouse_id` | `foreignId` | FK → `warehouses.id` | Target warehouse |
| `type` | `string` | index | Movement classification (`MovementType` enum) |
| `quantity` | `integer` | required | Signed delta (+ or -) |
| `reservation_id` | `foreignId` | FK → `reservations.id`, nullable | Associated reservation |
| `sales_order_id` | `foreignId` | FK → `sales_orders.id`, nullable | Associated sales order |
| `actor_id` | `foreignId` | FK → `users.id`, nullable | Actor ID |
| `reason` | `text` | nullable | Context or adjustment reason |
| `created_at` | `timestamp` | default current timestamp | Movement timestamp |

---

### 10. `shipments`

Tracks delivery/fulfillment operations per reservation.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `reservation_id` | `foreignId` | FK → `reservations.id` | Associated reservation |
| `quantity` | `unsignedInteger` | required | Quantity in shipment |
| `status` | `string` | default `'pending'`, index | Current shipping status (`ShipmentStatus` enum) |
| `created_at` | `timestamp` | nullable | Standard Laravel timestamp |
| `updated_at` | `timestamp` | nullable | Standard Laravel timestamp |

---

### 11. `shipment_attempts`

Individual attempts made by background jobs / shipping provider calls.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `shipment_id` | `foreignId` | FK → `shipments.id`, cascade delete | Parent shipment record |
| `attempt_number` | `unsignedInteger` | required | Monotonic attempt index |
| `status` | `string` | required | Attempt outcome (`ShipmentAttemptStatus` enum) |
| `provider_response` | `json` | nullable | Raw API payload/response |
| `created_at` | `timestamp` | default current timestamp | Attempt timestamp |

---

### 12. `idempotency_keys`

Ensures replay safety for order submission requests.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `key` | `string` | unique | Client request idempotency key |
| `user_id` | `foreignId` | FK → `users.id` | Authenticated client user |
| `response_code` | `unsignedSmallInteger` | required | Cached HTTP status code |
| `response_body` | `json` | required | Cached JSON response body |
| `created_at` | `timestamp` | index | Expiry / cleanup index |

---

### 13. `processed_webhook_events`

Deduplication guard for external shipping provider webhooks.

| Column | Type | Constraints / Attributes | Description |
|---|---|---|---|
| `id` | `bigint` | PK, Auto Increment | Primary key |
| `event_id` | `string` | unique | External provider event ID |
| `processed_at` | `timestamp` | default current timestamp | Processing timestamp |

---

## Relationships & `withTrashed()` Policy

Historical records (`order_lines`, `reservations`, `inventory_movements`) use `belongsTo(Product::class)->withTrashed()` to ensure historical orders remain resolvable even after a product is soft deleted.
