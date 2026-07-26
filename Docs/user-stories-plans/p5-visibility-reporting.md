# Phase 5 — Visibility & Reporting (Read Endpoints)

**Covers:** US-3.1, US-3.2, US-3.3, US-3.4
**Actors:** All authenticated users (role-gated per endpoint)
**Prerequisite phases:** p3 (Orders + Reserve), p4 (Reservation Lifecycle)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Controllers | `InventoryController` has `adjust()` only — add `index()` and `movements()`; `ReservationController` has `release()` + `partialCancel()` — add `index()`; `OrderController` has `store()` — add `show()` and `index()` |
| Resources | `InventoryResource` ✅; `InventoryMovementResource` ✅; `ReservationResource` ✅; `SalesOrderResource` ✅ — reuse all |
| Models | `Inventory`, `InventoryMovement`, `Reservation`, `SalesOrder`, `OrderLine` all exist from prior phases |
| Routes | Need to wire GET routes for each role group |
| N+1 | `InventoryMovementResource` must eager-load `actor`; `ReservationController::index` must eager-load `orderLine.salesOrder`; `OrderController::show` must eager-load `orderLines.reservation` |

No AGENTS.md violations — read paths use same service/controller pattern; no new business logic added.

---

### B. API & Data Structure

#### `GET /api/v1/inventory`

**Query params:** `product_id` (required), `warehouse_id` (optional)

**Response 200 — single warehouse:**
```json
{
  "ok": true, "code": 200, "message": null, "direct": null,
  "data": {
    "id": 5, "product_id": 1, "warehouse_id": 2,
    "quantity_available": 10, "quantity_reserved": 5,
    "quantity_picked": 2, "quantity_packed": 1, "quantity_shipped": 3
  }
}
```

**Response 200 — omitting `warehouse_id` (per-warehouse breakdown):**
```json
{
  "ok": true, "code": 200, "message": null, "direct": null,
  "data": [
    { "id": 5, "product_id": 1, "warehouse_id": 1, "quantity_available": 10, ... },
    { "id": 6, "product_id": 1, "warehouse_id": 2, "quantity_available": 0, ... }
  ]
}
```

---

#### `GET /api/v1/inventory/{product_id}/movements`

**Query params:** `warehouse_id` (optional), `page` (default 1), `per_page` (default 20, max 100)

**Response 200 (paginated):**
```json
{
  "ok": true, "code": 200, "message": "OK", "direct": null,
  "meta": { "current_page": 1, "per_page": 20, "total": 45, "last_page": 3 },
  "data": [
    {
      "id": 1, "product_id": 1, "warehouse_id": 1,
      "type": "reserve", "quantity_delta": 5,
      "reason": null, "actor_id": 3, "actor_name": "John Doe",
      "related_order_id": 42, "related_reservation_id": 12,
      "created_at": "2026-07-26T17:00:00Z"
    }
  ]
}
```

---

#### `GET /api/v1/reservations`

**Query params:** `status` (optional, default `open`), `warehouse_id` (optional), `product_id` (optional), `page`

**Response 200 (paginated):**
```json
{
  "ok": true, "code": 200, "message": "OK", "direct": null,
  "meta": { "current_page": 1, "per_page": 15, "total": 3 },
  "data": [
    {
      "id": 12, "order_line_id": 7, "product_id": 1, "warehouse_id": 2,
      "quantity": 5, "quantity_picked": 0, "quantity_packed": 0,
      "quantity_shipped": 0, "quantity_released": 0,
      "status": "open",
      "order_reference": 42,
      "created_at": "2026-07-26T17:00:00Z",
      "expires_at": "2026-07-26T17:30:00Z"
    }
  ]
}
```

---

#### `GET /api/v1/orders/{id}`

**Response 200:**
```json
{
  "ok": true, "code": 200, "message": null, "direct": null,
  "data": {
    "order_id": 42, "status": "open",
    "lines": [
      {
        "order_line_id": 7, "product_id": 1, "warehouse_id": 2, "quantity": 5,
        "reservation_id": 12, "reservation_status": "open",
        "expires_at": "2026-07-26T17:30:00Z"
      }
    ]
  }
}
```

---

#### `GET /api/v1/orders`

**Query params:** `consumed` (boolean, optional), `page`

**Response 200 (paginated)**

---

## Phase 3 — Implementation Plan

### Phase 3 — Visibility & Reporting (Read Endpoints)

#### User story

**As** any authenticated user (role-gated per endpoint)
**I want to** query current stock levels, movement history, open reservations, and order details
**So that** I can audit inventory state and trace consumption from order through to shipment

**Acceptance Criteria:**

- [x] AC-P3-1: `GET /api/v1/inventory?product_id=1&warehouse_id=2` returns all 5 quantity states for that inventory row; if no row exists, return all zeros (not 404)
- [x] AC-P3-2: `GET /api/v1/inventory?product_id=1` (omitting `warehouse_id`) returns a per-warehouse array of all inventory rows for that product
- [x] AC-P3-3: `GET /api/v1/inventory/{product_id}/movements` returns a paginated, chronological ledger (newest first) with `type`, `quantity_delta`, `actor_name`, `related_order_id`, `related_reservation_id`, `created_at`; filterable by `warehouse_id`
- [x] AC-P3-4: `GET /api/v1/reservations?status=open` lists non-expired, non-released, non-fulfilled reservations; filterable by `warehouse_id` and `product_id`
- [x] AC-P3-5: `GET /api/v1/orders/{id}` returns the order with per-line reservation status; `order_creator` sees only own orders; `admin` sees all
- [x] AC-P3-6: `GET /api/v1/orders?consumed=true` lists orders with at least one non-cancelled reservation
- [x] AC-P3-7: Movements endpoint eager-loads `actor` to avoid N+1; reservations endpoint eager-loads `orderLine.salesOrder`

**Expected Result:** All stock state and order consumption is queryable in real time with correct role scoping and filtering.

#### Assumptions

- A-P3-1: No inventory row for a product+warehouse combination → return zeros object (not a 404)
- A-P3-2: Movements paginate at 20/page (default); max 100 per page via `min($perPage ?? 20, 100)`
- A-P3-3: `GET /api/v1/inventory` accessible to all `auth:sanctum` users; movements + reservations require `warehouse_operator` or `admin` gate; orders require `order_creator` or `admin` gate
- A-P3-4: `order_creator` GET /orders → scoped to `where('user_id', auth()->id())`; admin → no scope restriction

#### Edge cases

- E1-P3: No inventory row for product+warehouse → construct a zeroed object (product_id + warehouse_id + all zeros) — do not 404
- E2-P3: `consumed=true` filter uses `whereHas('orderLines.reservation', fn($q) => $q->whereNotIn('status', ['released', 'expired']))`
- E3-P3: `order_creator` requesting another user's order → 404 (not 403 — avoids information leakage)

#### Files map

```
app/Http/Controllers/API/Inventory/
  InventoryController.php                    [MODIFY — add index() and movements()]
app/Http/Controllers/API/Reservation/
  ReservationController.php                  [MODIFY — add index()]
app/Http/Controllers/API/Order/
  OrderController.php                        [MODIFY — add index() and show()]
app/Http/Resources/
  InventoryMovementResource.php              [MODIFY — add actor_name field]
  ReservationResource.php                    [MODIFY — add order_reference field]
routes/apis/V1/admin.php                     [MODIFY — add GET inventory routes (all roles can read)]
routes/apis/V1/order_creator.php             [MODIFY — add GET orders routes]
routes/apis/V1/warehouse_operator.php        [MODIFY — add GET reservations + movements routes]
lang/en.json                                 [MODIFY]
lang/ar.json                                 [MODIFY]
tests/Feature/Inventory/
  InventoryQueryTest.php                     [NEW]
  MovementHistoryTest.php                    [NEW]
tests/Feature/Reservation/
  ListReservationsTest.php                   [NEW]
tests/Feature/Order/
  ListOrdersTest.php                         [NEW]
```

#### Sub-phase 3.1 — Full-stack

1. **`InventoryController::index(Request $request)`**:
   - Validate `product_id` required; `warehouse_id` optional
   - If `warehouse_id` given:
     - `$inv = Inventory::query()->where('product_id', $productId)->where('warehouse_id', $warehouseId)->first()`
     - If null → return zeroed object: `['id' => null, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity_available' => 0, ...]`
     - Else → return `InventoryResource`
   - If `warehouse_id` omitted:
     - `Inventory::query()->where('product_id', $productId)->get()`
     - Return `InventoryResource::collection($rows)`

2. **`InventoryController::movements(Request $request, int $productId)`**:
   - `InventoryMovement::query()->where('product_id', $productId)->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))->orderByDesc('created_at')->with('actor')->paginate(min($request->per_page ?? 20, 100))`
   - Return `$this->sendPaginatedResponse(InventoryMovementResource::collection($paginator))`

3. **`InventoryMovementResource`** (modify):
   - Add `'actor_name' => $this->resource->actor?->name ?? __('System')`

4. **`ReservationController::index(Request $request)`**:
   - `Reservation::query()->when($status, fn($q) => $q->where('status', $status), fn($q) => $q->whereNotIn('status', [ReservationStatus::Released->value, ReservationStatus::Expired->value, ReservationStatus::Fulfilled->value]))->when($warehouseId, ...)->when($productId, ...)->with(['orderLine.salesOrder'])->paginate(15)`
   - Return `$this->sendPaginatedResponse(ReservationResource::collection($paginator))`

5. **`ReservationResource`** (modify):
   - Add `'order_reference' => $this->resource->orderLine?->salesOrder?->id`

6. **`OrderController::show(int $id)`**:
   - `SalesOrder::with(['orderLines.reservation'])->when(!isAdmin, fn($q) => $q->where('user_id', auth()->id()))->findOrFail($id)`
   - Return `$this->sendSuccessResponse(new SalesOrderResource($order))`

7. **`OrderController::index(Request $request)`**:
   - `SalesOrder::query()->when($consumed, fn($q) => $q->whereHas('orderLines.reservation', fn($q2) => $q2->whereNotIn('status', ['released', 'expired'])))->when(!isAdmin, fn($q) => $q->where('user_id', auth()->id()))->paginate(15)`
   - Return `$this->sendPaginatedResponse(SalesOrderResource::collection($paginator))`

8. **Routes**:
   - In `admin.php` (or separate shared group — accessible to all auth roles):
     ```php
     Route::middleware('auth:sanctum')->group(function () {
         Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
     });
     ```
   - In `warehouse_operator.php`:
     ```php
     Route::get('inventory/{product}/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
     Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
     ```
   - In `order_creator.php`:
     ```php
     Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
     Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
     ```

9. **lang keys** — add to both JSON files:
   - `"Movements": "Movements"` / `"الحركات"`
   - `"Reservations": "Reservations"` / `"الحجوزات"`
   - `"Orders": "Orders"` / `"الطلبات"`
   - `"Per page": "Per page"` / `"لكل صفحة"`
   - `"Status": "Status"` / `"الحالة"`

10. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 3)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: query single product+warehouse | Feature | tests/Feature/Inventory/InventoryQueryTest.php | 200; correct quantities |
| Happy: omit warehouse_id → per-warehouse array | Feature | tests/Feature/Inventory/InventoryQueryTest.php | 200; array with multiple entries |
| Happy: no inventory row → zeros returned (not 404) | Feature | tests/Feature/Inventory/InventoryQueryTest.php | 200; all quantities = 0 |
| Sad: unauthenticated → 401 | Feature | tests/Feature/Inventory/InventoryQueryTest.php | 401 |
| Happy: movements paginated, newest-first, actor_name populated | Feature | tests/Feature/Inventory/MovementHistoryTest.php | 200; paginator meta present; actor_name = "John Doe" |
| Happy: movements filtered by warehouse_id | Feature | tests/Feature/Inventory/MovementHistoryTest.php | 200; only movements for that warehouse |
| Happy: list open reservations with filters | Feature | tests/Feature/Reservation/ListReservationsTest.php | 200; released/expired/fulfilled excluded |
| Happy: GET /orders/{id} with per-line reservation_status | Feature | tests/Feature/Order/ListOrdersTest.php | 200; lines include reservation_status + expires_at |
| Sad: order_creator cannot see another user's order → 404 | Feature | tests/Feature/Order/ListOrdersTest.php | 404 (not 403) |
| Happy: GET /orders?consumed=true filters correctly | Feature | tests/Feature/Order/ListOrdersTest.php | 200; only orders with active reservations |

#### cURL Smoke Tests (Phase 3)

```bash
# Query inventory — single warehouse
curl.exe -i "http://127.0.0.1:8000/api/v1/inventory?product_id=1&warehouse_id=1" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <token>"

# Query inventory — per-warehouse breakdown
curl.exe -i "http://127.0.0.1:8000/api/v1/inventory?product_id=1" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <token>"

# Movement history (paginated)
curl.exe -i "http://127.0.0.1:8000/api/v1/inventory/1/movements?page=1&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>"

# Open reservations filtered by warehouse
curl.exe -i "http://127.0.0.1:8000/api/v1/reservations?status=open&warehouse_id=1" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>"

# Order detail
curl.exe -i "http://127.0.0.1:8000/api/v1/orders/1" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <oc-token>"

# Orders consumed filter
curl.exe -i "http://127.0.0.1:8000/api/v1/orders?consumed=true" \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <oc-token>"
```
