# Phase 4 — Reservation Lifecycle (Release / Expire / Partial Cancel)

**Covers:** US-4.4, US-4.5, US-4.6
**Actors:** Warehouse Operator, System (Scheduler)
**Prerequisite phases:** p3 (Orders + Reserve)

---

## Phase 2 — Blueprint & Validation

### Step 0 — Codebase Analysis

| Check | Finding |
|---|---|
| Models | `Reservation` model created in p3; `ReservationStatus` enum created in p3 |
| Services | `OrderService` exists; need new `ReservationService` |
| Routes | `warehouse_operator.php` stub present — add release + partial cancel routes |
| Scheduler | `routes/console.php` exists — add `reservations:expire` schedule |
| Commands | No Artisan commands directory yet — create |
| Lang | Keys from p3 present; add new keys for lifecycle operations |

No AGENTS.md violations — scheduler goes in `routes/console.php` as per Laravel 12 convention; service holds all business logic.

---

### B. API & Data Structure

#### `POST /api/v1/reservations/{id}/release`

**Response 200 OK:**
```json
{
  "ok": true,
  "code": 200,
  "message": "Reservation released",
  "direct": null,
  "data": { "reservation_id": 12, "status": "released" }
}
```

**Response 409 — wrong state (already released/expired or picked/packed):**
```json
{
  "ok": false,
  "code": 409,
  "message": "Reservation cannot be released in its current state",
  "direct": null,
  "data": null
}
```

---

#### `PATCH /api/v1/orders/{id}/lines/{line_id}`

**Request body:**
```json
{ "quantity": 2 }
```

**Response 200 OK:**
```json
{
  "ok": true,
  "code": 200,
  "message": "Order line updated",
  "direct": null,
  "data": { "order_line_id": 7, "quantity": 2, "reservation_status": "open" }
}
```

**Response 422 — new quantity below already-consumed:**
```json
{
  "ok": false,
  "code": 422,
  "message": "New quantity is below already-consumed amount",
  "direct": null,
  "data": null
}
```

---

### C. Database & Schema Verification

New table required:

| Table | Key Columns | Notes |
|---|---|---|
| `reservation_history` | `id`, `reservation_id FK`, `from_status` nullable, `to_status` required, `quantity_affected` uint nullable, `actor_id FK` nullable, `notes` text nullable, `created_at` default now | Append-only; no `updated_at`; index on `reservation_id` |

---

## Phase 3 — Implementation Plan

### Phase 2 — Reservation Lifecycle (Release / Expire / Partial Cancel)

#### User story

**As a** Warehouse Operator (or the Scheduler)
**I want to** release open reservations manually, cancel partial quantities, and have stale reservations auto-expire
**So that** stock is never held indefinitely and operators can adjust demand without full-order cancellation

**Acceptance Criteria:**

- [x] AC-P2-1: `POST /api/v1/reservations/{id}/release` on an `open` reservation: `reserved -= qty`, `available += qty`, status → `released`, movement (type `release`) + history row created
- [x] AC-P2-2: Releasing an already `released` or `expired` reservation returns `409` — idempotent no-op
- [x] AC-P2-3: Releasing a `picked` or `packed` reservation returns `409` — those stages require pick/pack reversal (out of scope this slice)
- [x] AC-P2-4: `PATCH /api/v1/orders/{id}/lines/{line_id}` with reduced `quantity` releases only the unconsumed delta; guard: `new_qty >= quantity_picked + quantity_packed + quantity_shipped`
- [x] AC-P2-5: Fully reducing a line to 0 before any picking behaves identically to `POST .../release` for that reservation
- [x] AC-P2-6: Artisan command `reservations:expire` marks all `open` reservations with `expires_at < now()` as `expired`; restores `quantity_available`; idempotent via row lock + status guard
- [x] AC-P2-7: `expired` status is distinct from `released` — both in enum and in `reservation_history` entries
- [x] AC-P2-8: Every status transition writes a `reservation_history` row with `from_status`, `to_status`, `quantity_affected`, `actor_id` (null for scheduler)

**Expected Result:** Released and expired stock is immediately available for new reservations with a full audit trail; partial cancellations adjust exactly the unconsumed portion.

#### Assumptions

- A-P2-1: `reservations:expire` uses `chunkById(100)` to avoid lock timeout on large tables
- A-P2-2: Partial cancel only reduces quantity — increasing requires a new order
- A-P2-3: `reservation_history` rows are never updated or deleted (append-only ledger)
- A-P2-4: Command registered in `routes/console.php` with `->everyMinute()->withoutOverlapping()`

#### Edge cases

- E1-P2: `PATCH` reducing to 0 before any picking → full release path (same inventory mutation as `POST .../release`)
- E2-P2: Concurrent `reservations:expire` executions → `lockForUpdate()` + status guard (`WHERE status = 'open'`) ensures each row is processed exactly once
- E3-P2: Releasing a `picked`/`packed` reservation → `409` — those stages need pick/pack reversal, not a simple release

#### Files map

```
app/Models/
  ReservationHistory.php                             [NEW]
app/Core/Services/Reservation/
  ReservationService.php                             [NEW]
app/Http/Requests/Reservation/
  PartialCancelRequest.php                           [NEW]
app/Http/Controllers/API/Reservation/
  ReservationController.php                          [NEW]
database/migrations/
  ..._create_reservation_history_table.php           [NEW]
database/factories/
  ReservationHistoryFactory.php                      [NEW]
app/Console/Commands/
  ExpireReservationsCommand.php                      [NEW]
routes/apis/V1/warehouse_operator.php                [MODIFY — add release + partial cancel routes]
routes/console.php                                   [MODIFY — register scheduler]
lang/en.json                                         [MODIFY]
lang/ar.json                                         [MODIFY]
tests/Feature/Reservation/
  ReleaseReservationTest.php                         [NEW]
  ExpireReservationsTest.php                         [NEW]
  PartialCancelTest.php                              [NEW]
```

#### Sub-phase 2.1 — Database & Setup

1. **Migration** `create_reservation_history_table`:
   - `$table->id()`
   - `$table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete()`
   - `$table->string('from_status')->nullable()`
   - `$table->string('to_status')`
   - `$table->unsignedInteger('quantity_affected')->nullable()`
   - `$table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete()`
   - `$table->text('notes')->nullable()`
   - `$table->timestamp('created_at')->useCurrent()`
   - `$table->index('reservation_id')`
   - *(no `updated_at`)*

#### Sub-phase 2.2 — Full-stack

1. **Model** `ReservationHistory`:
   - `$fillable = ['reservation_id', 'from_status', 'to_status', 'quantity_affected', 'actor_id', 'notes']`
   - Cast `from_status` → `ReservationStatus` nullable, `to_status` → `ReservationStatus`
   - `public $timestamps = false`; manually set `created_at = now()`
   - `belongsTo(Reservation::class)`, `belongsTo(User::class, 'actor_id')->withDefault(['name' => __('System')])`

2. **Service** `ReservationService`:

   `release(int $reservationId, ?int $actorId): Reservation`:
   - `DB::transaction()`
   - Load reservation with `lockForUpdate()`; guard status === `ReservationStatus::Open` — else throw with HTTP 409
   - Load inventory with `lockForUpdate()` (same product+warehouse)
   - `inventory->quantity_reserved -= reservation->quantity`
   - `inventory->quantity_available += reservation->quantity`
   - Save inventory; set `reservation->status = ReservationStatus::Released`; save reservation
   - Create `InventoryMovement` (type `MovementType::Release`, `quantity_delta = -reservation->quantity`, `actor_id`, `related_reservation_id`)
   - Create `ReservationHistory` (`from_status = Open`, `to_status = Released`, `quantity_affected`, `actor_id`)
   - Return updated reservation

   `expire(Reservation $reservation): void`:
   - Same logic as `release()` but sets status `ReservationStatus::Expired`; `actor_id = null`
   - Called from `ExpireReservationsCommand` — reservation is already loaded; use `lockForUpdate()` on re-fetch inside transaction

   `partialCancel(int $orderId, int $lineId, int $newQty, ?int $actorId): OrderLine`:
   - Load order line + reservation; guard `$newQty >= quantity_picked + quantity_packed + quantity_shipped` else throw 422
   - Compute `$delta = reservation->quantity - $newQty` (amount to release)
   - If `$delta === 0` → no-op; return line
   - `DB::transaction()`: lock inventory; `quantity_reserved -= delta`; `quantity_available += delta`; save inventory
   - `reservation->quantity -= delta`; `reservation->quantity_released += delta`; save reservation
   - Create `InventoryMovement` (type `MovementType::Release`, `quantity_delta = -delta`)
   - Create `ReservationHistory`
   - If `$newQty === 0` → also set `reservation->status = Released`
   - Return updated order line

3. **Command** `ExpireReservationsCommand` (`reservations:expire`):
   ```
   Reservation::query()
       ->where('status', ReservationStatus::Open->value)
       ->where('expires_at', '<', now())
       ->chunkById(100, function ($chunk) {
           $chunk->each(fn($r) => $this->reservationService->expire($r));
       });
   ```

4. **Scheduler** in `routes/console.php`:
   ```php
   Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping();
   ```

5. **Controller** `ReservationController`:
   - `release(int $id)`: call `$this->reservationService->release($id, auth()->id())`; on success `sendSuccessResponse([...], 'Reservation released')`; catch 409 condition → `sendFailedResponse('Reservation cannot be released in its current state', 409)`
   - `partialCancel(PartialCancelRequest $request, int $orderId, int $lineId)`: call service; return `sendSuccessResponse`

6. **FormRequest** `PartialCancelRequest`:
   - `rules()`: `quantity` → `['required', 'integer', 'min:0']`
   - `messages()` + `attributes()` with `__()`

7. **Routes** in `warehouse_operator.php` (add clean routes without the stub prefix):
   ```php
   Route::post('reservations/{reservation}/release', [ReservationController::class, 'release'])->name('reservations.release');
   Route::patch('orders/{order}/lines/{line}', [ReservationController::class, 'partialCancel'])->name('orders.lines.update');
   ```

8. **lang keys** — add to both `lang/en.json` and `lang/ar.json`:
   - `"Reservation released": "Reservation released"` / `"تم إطلاق الحجز"`
   - `"Order line updated": "Order line updated"` / `"تم تحديث سطر الطلب"`
   - `"Reservation cannot be released in its current state": "Reservation cannot be released in its current state"` / `"لا يمكن إطلاق الحجز في حالته الحالية"`
   - `"New quantity is below already-consumed amount": "New quantity is below already-consumed amount"` / `"الكمية الجديدة أقل من الكمية المستهلكة"`
   - `"New quantity": "New quantity"` / `"الكمية الجديدة"`
   - `"System": "System"` / `"النظام"`

9. Run `vendor/bin/pint --dirty` before commit

#### Tests (Phase 2)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: release open reservation | Feature | tests/Feature/Reservation/ReleaseReservationTest.php | 200; status=released; inventory restored (available +qty, reserved -qty); movement + history rows created |
| Sad: release already-released → 409 | Feature | tests/Feature/Reservation/ReleaseReservationTest.php | 409; inventory unchanged; no duplicate movement |
| Sad: release picked reservation → 409 | Feature | tests/Feature/Reservation/ReleaseReservationTest.php | 409 |
| Sad: release expired reservation → 409 | Feature | tests/Feature/Reservation/ReleaseReservationTest.php | 409 |
| Happy: expire command releases stale open reservations | Feature | tests/Feature/Reservation/ExpireReservationsTest.php | status=expired; inventory restored; history row created |
| Happy: expire command is idempotent (run twice) | Feature | tests/Feature/Reservation/ExpireReservationsTest.php | exactly one movement row; inventory not double-released |
| Happy: expire does not touch non-expired reservations | Feature | tests/Feature/Reservation/ExpireReservationsTest.php | future expires_at untouched |
| Happy: partial cancel reduces quantity | Feature | tests/Feature/Reservation/PartialCancelTest.php | 200; delta returned to available; history written |
| Happy: partial cancel to 0 → full release | Feature | tests/Feature/Reservation/PartialCancelTest.php | 200; status=released |
| Sad: partial cancel below picked qty → 422 | Feature | tests/Feature/Reservation/PartialCancelTest.php | 422; inventory unchanged |

#### cURL Smoke Tests (Phase 2)

```bash
# Release open reservation
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/1/release \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>"

# Release already-released (expect 409)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/reservations/1/release \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>"

# Partial cancel (reduce line qty from 5 to 2)
curl.exe -i -X PATCH http://127.0.0.1:8000/api/v1/orders/1/lines/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <wh-operator-token>" \
  -d "{\"quantity\": 2}"

# Run expire command manually
php artisan reservations:expire
```
