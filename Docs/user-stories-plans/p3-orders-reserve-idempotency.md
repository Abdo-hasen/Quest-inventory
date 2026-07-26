# Phase 3 — Orders + Reserve + Concurrency + Idempotency

**Covers:** US-4.1, US-4.2, US-4.3
**Actors:** Order Creator, System
**Prerequisite phases:** p1 (Auth), p2 (Catalog & Warehouse Setup)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Routes | `routes/apis/V1/order_creator.php` exists as a stub (empty group with `can:create-orders` middleware) |
| Controllers | `app/Http/Controllers/API/Inventory/InventoryController.php` exists (adjust only); no Order controller yet |
| Models | `Inventory` model has all 5 qty columns + `lockForUpdate` pattern in `InventoryService::adjust()` — reuse same pattern |
| Migrations | `inventory` table ✅ migrated; `jobs` table ✅ (queue driver already `database`) |
| Services | `InventoryService::adjust()` — locking pattern to copy for reserve |
| Enums | `MovementType` has `Reserve` case ✅; `OrderStatus` + `ReservationStatus` missing — must create |
| Lang | `lang/en.json` + `lang/ar.json` present |
| Traits | `InteractWithResponse` ✅ |

No AGENTS.md violations — plan uses thin controller, service layer, FormRequest, `lockForUpdate`, `$fillable`, and `InteractWithResponse`.

---

### B. API & Data Structure

#### `POST /api/v1/orders`

**Request body:**
```json
{
  "lines": [
    { "product_id": 1, "warehouse_id": 2, "quantity": 5 }
  ]
}
```

**Headers:**
```
Authorization: Bearer <token>
Idempotency-Key: uuid-v4  (optional)
Content-Type: application/json
```

**Response 201 Created:**
```json
{
  "ok": true,
  "code": 201,
  "message": "Order created",
  "direct": null,
  "data": {
    "order_id": 42,
    "status": "open",
    "lines": [
      {
        "order_line_id": 7,
        "product_id": 1,
        "warehouse_id": 2,
        "quantity": 5,
        "reservation_id": 12,
        "reservation_status": "open",
        "expires_at": "2026-07-26T18:00:00Z"
      }
    ]
  }
}
```

**Response 422 — insufficient stock (any line):**
```json
{
  "ok": false,
  "code": 422,
  "message": "Insufficient stock",
  "direct": null,
  "data": null
}
```

**Response 401 — unauthenticated:**
```json
{ "ok": false, "code": 401, "message": "Unauthenticated.", "direct": null, "data": null }
```

---

### C. Database & Schema Verification

New tables required (in dependency order):

| Table | Key Columns | Notes |
|---|---|---|
| `sales_orders` | `id`, `user_id FK`, `status` string default `'open'`, timestamps | FK → `users.id` restrict |
| `order_lines` | `id`, `sales_order_id FK`, `product_id FK`, `warehouse_id FK`, `quantity` uint, timestamps | `product_id` → `products.id` restrict; supports `withTrashed()` |
| `reservations` | `id`, `order_line_id FK`, `product_id FK`, `warehouse_id FK`, `quantity` uint, `quantity_picked/packed/shipped/released` uint default 0, `status` string default `'open'` + index, `expires_at` timestamp + index, timestamps | One row per order line |
| `idempotency_keys` | `id`, `key` string unique, `user_id FK`, `response_code` uint16, `response_body` json, `created_at` timestamp + index | No `updated_at`; expires after 24h |

New Enums:

| Enum | File | Cases |
|---|---|---|
| `OrderStatus` | `app/Core/Enums/OrderStatus.php` | `Open = 'open'`, `PartiallyFulfilled = 'partially_fulfilled'`, `Fulfilled = 'fulfilled'`, `Cancelled = 'cancelled'` |
| `ReservationStatus` | `app/Core/Enums/ReservationStatus.php` | `Open = 'open'`, `Picked = 'picked'`, `Packed = 'packed'`, `PartiallyFulfilled = 'partially_fulfilled'`, `Fulfilled = 'fulfilled'`, `Released = 'released'`, `Expired = 'expired'` |

New Config:

- `config/reservations.php` → `['ttl_minutes' => env('RESERVATION_TTL_MINUTES', 30)]`

---

## Phase 3 — Implementation Plan

### Phase 1 — Orders + Reserve + Concurrency + Idempotency

#### User story

**As an** Order Creator
**I want to** submit an order and have inventory reserved atomically, with safe retry support
**So that** stock is guaranteed available before I promise it to a customer, and network retries never create duplicates

**Acceptance Criteria:**

- [x] AC-P1-1: `POST /api/v1/orders` with `lines: [{product_id, warehouse_id, quantity}]` creates a `sales_order` + `order_lines` + one `reservation` per line in a single DB transaction
- [x] AC-P1-2: Each reservation decrements `inventory.quantity_available` and increments `inventory.quantity_reserved` using `lockForUpdate()` on the inventory row
- [x] AC-P1-3: If any line's quantity exceeds `quantity_available`, the entire request fails `422` — zero partial reservations, zero inventory mutations
- [x] AC-P1-4: Response includes `order_id`, `status`, and per-line `reservation_id` + `expires_at` (default +30 minutes via `config('reservations.ttl_minutes', 30)`)
- [x] AC-P1-5: Submitting with an `Idempotency-Key` header already used returns the original cached `201` response with no re-execution
- [x] AC-P1-6: Two simultaneous requests for the last unit of stock — exactly one succeeds; the other receives `422`; `quantity_available` never goes below zero
- [x] AC-P1-7: An `inventory_movements` row of type `reserve` (`quantity_delta = qty`) is created per line on success
- [x] AC-P1-8: Only users with `can:create-orders` (role `order_creator` or `admin`) may call this endpoint

**Expected Result:** Orders either reserve fully and safely, or fail cleanly with zero side effects; re-runs of the same idempotency key are no-ops.

#### Assumptions

- A-P1-1: `expires_at` = `now()->addMinutes(config('reservations.ttl_minutes', 30))`; configurable via env `RESERVATION_TTL_MINUTES`
- A-P1-2: Idempotency key scope is per-user (same key from a different user = new request)
- A-P1-3: Idempotency keys expire after 24 hours (cleanup is a separate concern, not this slice)
- A-P1-4: Inactive warehouses (`is_active = false`) are rejected at the FormRequest validation level
- A-P1-5: Soft-deleted products are rejected at the FormRequest validation level (`Rule::exists` with `whereNull('deleted_at')`)

#### Edge cases

- E1-P1: All-or-nothing multi-line — if line 2 fails the stock check, line 1's `lockForUpdate` hold is released on transaction rollback. No partial state persists.
- E2-P1: Concurrent same-key idempotency — `idempotency_keys.key` UNIQUE constraint ensures only one row is inserted; the losing concurrent request returns the cached response
- E3-P1: `quantity = 0` rejected by FormRequest validation (`min:1`)
- E4-P1: `CHECK (quantity_available >= 0)` DB constraint is the final safety net if a locking bug slips through

#### Files map

```
app/Core/Enums/
  OrderStatus.php                              [NEW]
  ReservationStatus.php                        [NEW]
app/Models/
  SalesOrder.php                               [NEW]
  OrderLine.php                                [NEW]
  Reservation.php                              [NEW]
  IdempotencyKey.php                           [NEW]
app/Core/Services/Order/
  OrderService.php                             [NEW]
app/Http/Requests/Order/
  CreateOrderRequest.php                       [NEW]
app/Http/Controllers/API/Order/
  OrderController.php                          [NEW]
app/Http/Resources/
  SalesOrderResource.php                       [NEW]
  OrderLineResource.php                        [NEW]
  ReservationResource.php                      [NEW]
app/Http/Middleware/
  IdempotencyMiddleware.php                    [NEW]
database/migrations/
  ..._create_sales_orders_table.php            [NEW]
  ..._create_order_lines_table.php             [NEW]
  ..._create_reservations_table.php            [NEW]
  ..._create_idempotency_keys_table.php        [NEW]
database/factories/
  SalesOrderFactory.php                        [NEW]
  OrderLineFactory.php                         [NEW]
  ReservationFactory.php                       [NEW]
config/
  reservations.php                             [NEW]
routes/apis/V1/order_creator.php               [MODIFY — add POST orders route]
bootstrap/app.php                              [MODIFY — register IdempotencyMiddleware alias]
lang/en.json                                   [MODIFY]
lang/ar.json                                   [MODIFY]
tests/Feature/Order/
  CreateOrderTest.php                          [NEW]
  IdempotencyTest.php                          [NEW]
  ConcurrencyTest.php                          [NEW]
```

#### Sub-phase 1.1 — Database & Setup

1. **Migration** `create_sales_orders_table`:
   - `$table->id()`
   - `$table->foreignId('user_id')->constrained('users')->restrictOnDelete()`
   - `$table->string('status')->default(OrderStatus::Open->value)`
   - `$table->timestamps()`

2. **Migration** `create_order_lines_table`:
   - `$table->id()`
   - `$table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete()`
   - `$table->foreignId('product_id')->constrained('products')->restrictOnDelete()`
   - `$table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`
   - `$table->unsignedInteger('quantity')`
   - `$table->timestamps()`

3. **Migration** `create_reservations_table`:
   - `$table->id()`
   - `$table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete()`
   - `$table->foreignId('product_id')->constrained('products')->restrictOnDelete()`
   - `$table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete()`
   - `$table->unsignedInteger('quantity')`
   - `$table->unsignedInteger('quantity_picked')->default(0)`
   - `$table->unsignedInteger('quantity_packed')->default(0)`
   - `$table->unsignedInteger('quantity_shipped')->default(0)`
   - `$table->unsignedInteger('quantity_released')->default(0)`
   - `$table->string('status')->default(ReservationStatus::Open->value)->index()`
   - `$table->timestamp('expires_at')->index()`
   - `$table->timestamps()`

4. **Migration** `create_idempotency_keys_table`:
   - `$table->id()`
   - `$table->string('key')->unique()`
   - `$table->foreignId('user_id')->constrained('users')->cascadeOnDelete()`
   - `$table->unsignedSmallInteger('response_code')`
   - `$table->json('response_body')`
   - `$table->timestamp('created_at')->index()`
   - *(no `updated_at`)*

5. **Enum** `OrderStatus`: `Open = 'open'`, `PartiallyFulfilled = 'partially_fulfilled'`, `Fulfilled = 'fulfilled'`, `Cancelled = 'cancelled'`

6. **Enum** `ReservationStatus`: `Open = 'open'`, `Picked = 'picked'`, `Packed = 'packed'`, `PartiallyFulfilled = 'partially_fulfilled'`, `Fulfilled = 'fulfilled'`, `Released = 'released'`, `Expired = 'expired'`

7. **Config** `config/reservations.php`:
   ```php
   return ['ttl_minutes' => env('RESERVATION_TTL_MINUTES', 30)];
   ```

#### Sub-phase 1.2 — Full-stack

1. **Models**:
   - `SalesOrder`: `$fillable = ['user_id', 'status']`; cast `status → OrderStatus`; `hasMany(OrderLine::class)`, `belongsTo(User::class)`
   - `OrderLine`: `$fillable = ['sales_order_id', 'product_id', 'warehouse_id', 'quantity']`; `belongsTo(SalesOrder::class)`, `belongsTo(Product::class)->withTrashed()`, `belongsTo(Warehouse::class)`, `hasOne(Reservation::class)`
   - `Reservation`: `$fillable = ['order_line_id', 'product_id', 'warehouse_id', 'quantity', 'quantity_picked', 'quantity_packed', 'quantity_shipped', 'quantity_released', 'status', 'expires_at']`; cast `status → ReservationStatus`, `expires_at → 'datetime'`; `belongsTo(OrderLine::class)`, `belongsTo(Product::class)->withTrashed()`, `belongsTo(Warehouse::class)`
   - `IdempotencyKey`: `$fillable = ['key', 'user_id', 'response_code', 'response_body']`; cast `response_body → 'array'`; `public $timestamps = false`; manually set `created_at`

2. **FormRequest** `CreateOrderRequest`:
   - `rules()`:
     - `lines` → `['required', 'array', 'min:1']`
     - `lines.*.product_id` → `['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')]`
     - `lines.*.warehouse_id` → `['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)]`
     - `lines.*.quantity` → `['required', 'integer', 'min:1']`
   - `messages()` and `attributes()` for all dotted keys using `__()`

3. **Service** `OrderService::create(array $validatedData, int $actorId): array`:
   - Open `DB::transaction()`
   - For each line: `Inventory::query()->where('product_id', ...)->where('warehouse_id', ...)->lockForUpdate()->firstOrFail()`
   - Guard: if `quantity_available < requested_qty` → throw `ValidationException::withMessages(['lines.N.quantity' => __('Insufficient stock')])`
   - After **all** lines pass validation: for each line: decrement `quantity_available`, increment `quantity_reserved`, save; create `Reservation`; create `InventoryMovement` (type `MovementType::Reserve`, `quantity_delta = qty`, `actor_id = $actorId`, `related_order_id` deferred until after order creation, `related_reservation_id`)
   - Create `SalesOrder` (status `OrderStatus::Open`); create `OrderLine` rows linked to order and reservations
   - Update `InventoryMovement.related_order_id` for each movement
   - Return array: `['order' => $order, 'lines' => $lines]` for `SalesOrderResource`

4. **Middleware** `IdempotencyMiddleware`:
   - Read `Idempotency-Key` header; if absent → pass through
   - Check `IdempotencyKey::query()->where('key', $key)->where('user_id', auth()->id())->first()`
   - If found and `created_at > now()->subHours(24)` → return cached `JsonResponse` immediately (reconstruct from `response_body` + `response_code`)
   - If not found → pass to controller; after response, store: `IdempotencyKey::create([...])` — UNIQUE constraint handles concurrent race
   - Register as named middleware alias `'idempotency'` in `bootstrap/app.php`; apply to `POST orders` route only

5. **Controller** `OrderController::store(CreateOrderRequest $request)`:
   - Calls `$this->orderService->create($request->validated(), $request->user()->id)`
   - Returns `$this->sendSuccessResponse(new SalesOrderResource($result), __('Order created'), 201)`

6. **Route** in `order_creator.php`:
   ```php
   Route::post('orders', [OrderController::class, 'store'])
       ->middleware('idempotency')
       ->name('orders.store');
   ```

7. **Resources**:
   - `SalesOrderResource`: `order_id` (`$this->resource->id`), `status` (`$this->resource->status->value`), `lines` → `OrderLineResource::collection($this->resource->orderLines)`
   - `OrderLineResource`: `order_line_id`, `product_id`, `warehouse_id`, `quantity`, `reservation_id` (`$this->resource->reservation?->id`), `reservation_status` (`$this->resource->reservation?->status->value`), `expires_at` (`$this->resource->reservation?->expires_at?->toISOString()`)
   - `ReservationResource`: `id`, `order_line_id`, `product_id`, `warehouse_id`, `quantity`, `quantity_picked`, `quantity_packed`, `quantity_shipped`, `quantity_released`, `status` (→ value), `expires_at` (→ ISO string)

8. **lang keys** — add to both `lang/en.json` and `lang/ar.json`:
   - `"Order created": "Order created"` / `"الطلب أُنشئ"`
   - `"Insufficient stock": "Insufficient stock"` / `"المخزون غير كافٍ"`
   - `"Warehouse is inactive": "Warehouse is inactive"` / `"المستودع غير نشط"`
   - `"Lines": "Lines"` / `"الأسطر"`
   - `"Product": "Product"` / `"المنتج"`
   - `"Warehouse": "Warehouse"` / `"المستودع"`
   - `"Quantity": "Quantity"` / `"الكمية"`

9. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 1)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: single-line order reserves stock | Feature | tests/Feature/Order/CreateOrderTest.php | 201; `quantity_available` decremented; `quantity_reserved` incremented; reservation row + movement(type=reserve) created |
| Happy: multi-line order all succeed atomically | Feature | tests/Feature/Order/CreateOrderTest.php | 201; all lines reserved; DB fully consistent |
| Sad: one line insufficient stock → full rollback | Feature | tests/Feature/Order/CreateOrderTest.php | 422; `quantity_available` unchanged for **all** lines |
| Sad: inactive warehouse rejected at validation | Feature | tests/Feature/Order/CreateOrderTest.php | 422 validation error |
| Sad: soft-deleted product rejected at validation | Feature | tests/Feature/Order/CreateOrderTest.php | 422 validation error |
| Sad: unauthenticated | Feature | tests/Feature/Order/CreateOrderTest.php | 401 |
| Happy: idempotency key — second call returns cached 201 | Feature | tests/Feature/Order/IdempotencyTest.php | same `order_id`; no new `sales_orders` or `reservations` rows |
| Sad: idempotency key from different user is not shared | Feature | tests/Feature/Order/IdempotencyTest.php | second user gets a fresh 201 with a new order |
| Concurrency: two simultaneous requests for the last unit | Feature | tests/Feature/Order/ConcurrencyTest.php | exactly one 201, one 422; `quantity_available` = 0; no negative quantities |

#### cURL Smoke Tests (Phase 1)

```bash
# Step 1 — get a token (order_creator role)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -d "{\"email\":\"creator@test.com\",\"password\":\"password\"}"

# Step 2 — create order (first call, sets idempotency key)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer 1|token" \
  -H "Idempotency-Key: order-key-abc" \
  -d "{\"lines\":[{\"product_id\":1,\"warehouse_id\":1,\"quantity\":2}]}"

# Step 3 — replay same idempotency key (expect cached 201, no new DB rows)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer 1|token" \
  -H "Idempotency-Key: order-key-abc" \
  -d "{\"lines\":[{\"product_id\":1,\"warehouse_id\":1,\"quantity\":2}]}"

# Step 4 — trigger insufficient stock (adjust stock to 1 first via admin adjust endpoint)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/orders \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer 1|token" \
  -d "{\"lines\":[{\"product_id\":1,\"warehouse_id\":1,\"quantity\":999}]}"
```

#### Complexity tracking

- `ponytail:` `IdempotencyMiddleware` is scoped to `POST /api/v1/orders` only via named middleware applied at the route level — not a global middleware. Generalizing idempotency across all POST endpoints is YAGNI. The UNIQUE constraint on `idempotency_keys.key` is the concurrency safety net for simultaneous replay attempts.
