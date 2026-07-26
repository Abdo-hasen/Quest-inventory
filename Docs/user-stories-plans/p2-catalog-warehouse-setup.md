# Implementation Plan — Catalog & Warehouse Setup (Admin)

This plan covers US-2.1 (Products Management), US-2.2 (Warehouse Setup), and US-2.3 (Baseline Stock Adjustment).
Each phase is a self-contained vertical slice including Database migrations/models, API endpoints & resources, Pest feature tests, and cURL smoke tests.

---

### Phase 1 — Products CRUD (US-2.1)

#### User story

**As an** Admin  
**I want to** create, view, update, and soft-delete products  
**So that** inventory items are defined and tracked by unique SKU.

**Acceptance Criteria:**

- [x] AC-P1-1: `POST /api/v1/products` creates a product and returns `201 Created` with full resource.
- [x] AC-P1-2: `sku` is unique among non-deleted products (`whereNull('deleted_at')`); duplicates return `422`.
- [x] AC-P1-3: `PUT /api/v1/products/{id}` updates `name` and `description` only (`sku` is immutable).
- [x] AC-P1-4: `GET /api/v1/products` returns paginated products (`200 OK`); `GET /api/v1/products/{id}` returns single product.
- [x] AC-P1-5: `DELETE /api/v1/products/{id}` soft-deletes the product; returns `422` if active inventory rows exist.
- [x] AC-P1-6: Endpoints require `manage-products` gate; unauthorized users receive `403 Forbidden`.

**Expected Result:** Admin can manage products with immutable SKUs and soft-deletion protection.

#### Assumptions

- A-P1-1: SKU is immutable post-creation.
- A-P1-2: Product deletion is a soft delete (`deleted_at`).
- A-P1-3: SKU uniqueness check excludes soft-deleted records so SKUs can be reused if needed.

#### Files map

```
app/
  Http/
    Controllers/API/Product/ProductController.php
    Requests/Product/StoreProductRequest.php
    Requests/Product/UpdateProductRequest.php
    Resources/ProductResource.php
  Models/Product.php
  Core/Services/Product/ProductService.php
database/
  migrations/XXXX_XX_XX_XXXXXX_create_products_table.php
  factories/ProductFactory.php
routes/apis/V1/admin.php
lang/en.json
lang/ar.json
```

#### Sub-phase 1.1 — Database & Setup

1. **Migration**: `create_products_table`
   - `id`: bigIncrements
   - `name`: string(255)
   - `sku`: string(100) unique
   - `description`: text nullable
   - `deleted_at`: timestamp nullable (softDeletes)
   - `timestamps()`
2. **Model**: `Product`
   - `$fillable = ['name', 'sku', 'description']`
   - Uses `SoftDeletes` trait.
3. **Factory**: `ProductFactory`
   - `sku` generated via `fake()->unique()->bothify('SKU-####')`.

#### Sub-phase 1.2 — Full-stack API & Logic

1. **`StoreProductRequest`**:
   - `name`: required string max:255
   - `sku`: required string max:100 `Rule::unique('products', 'sku')->whereNull('deleted_at')`
   - `description`: nullable string
   - Implements `messages()` and `attributes()`.
2. **`UpdateProductRequest`**:
   - `name`: sometimes string max:255
   - `description`: nullable string
   - `sku` excluded from rules to enforce immutability.
3. **`ProductService`**:
   - `index()`: `Product::paginate(15)`
   - `findById(int $id)`: `Product::findOrFail($id)`
   - `store(array $data)`: `Product::create($data)`
   - `update(array $data, int $id)`: find + update allowed attributes
   - `destroy(int $id)`: check if inventory exists → throw `ValidationException` (422) if true, else `$product->delete()`
4. **`ProductResource`**:
   - Fields: `id`, `name`, `sku`, `description`, `created_at`, `updated_at`
5. **`ProductController`**:
   - Uses `InteractWithResponse` for `201 Created` and `200 OK` responses.
6. **Routes**:
   - Registered under `manage-products` gate in `routes/apis/V1/admin.php`.
7. **Translations**:
   - Keys in `lang/en.json` and `lang/ar.json`:
     - `"Product created"`, `"Product updated"`, `"Product deleted"`, `"Cannot delete a product with active inventory"`

#### API Contract

```http
POST /api/v1/products
Content-Type: application/json

{
  "name": "Widget A",
  "sku": "WGT-001",
  "description": "Standard Widget"
}
```

```json
// Response 201 Created
{
  "ok": true,
  "code": 201,
  "message": "Product created",
  "direct": null,
  "data": {
    "id": 1,
    "name": "Widget A",
    "sku": "WGT-001",
    "description": "Standard Widget",
    "created_at": "2026-07-26T08:00:00Z"
  }
}
```

#### Tests (this phase)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: Admin creates product | Feature | `tests/Feature/Product/ProductCrudTest.php` | 201, DB has row |
| Sad: Duplicate SKU | Feature | `tests/Feature/Product/ProductCrudTest.php` | 422, field error `sku` |
| Sad: Non-admin user | Feature | `tests/Feature/Product/ProductCrudTest.php` | 403 |
| Happy: Update name/description | Feature | `tests/Feature/Product/ProductCrudTest.php` | 200, SKU unchanged |
| Happy: Soft delete product | Feature | `tests/Feature/Product/ProductCrudTest.php` | 200, `deleted_at` set |

#### cURL Smoke Tests (this phase)

```bash
# Create Product (201 Created)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/products \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"name\":\"Widget A\",\"sku\":\"WGT-001\",\"description\":\"Blue widget\"}"

# List Products (200 OK)
curl.exe -i -X GET http://127.0.0.1:8000/api/v1/products \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"

# Get Single Product (200 OK)
curl.exe -i -X GET http://127.0.0.1:8000/api/v1/products/1 \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"

# Update Product (200 OK)
curl.exe -i -X PUT http://127.0.0.1:8000/api/v1/products/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"name\":\"Widget A Pro\",\"description\":\"Updated description\"}"

# Soft Delete Product (200 OK)
curl.exe -i -X DELETE http://127.0.0.1:8000/api/v1/products/1 \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"
```

#### Edge cases

- E1-P1: `sku` uniqueness ignores soft-deleted rows using `whereNull('deleted_at')`.

---

### Phase 2 — Warehouses CRUD (US-2.2)

#### User story

**As an** Admin  
**I want to** create, view, update, and toggle active status of warehouses  
**So that** inventory is assigned to valid physical warehouse locations.

**Acceptance Criteria:**

- [x] AC-P2-1: `POST /api/v1/warehouses` creates a warehouse (`201 Created`).
- [x] AC-P2-2: `code` is unique across all warehouses; duplicate returns `422`.
- [x] AC-P2-3: `PUT /api/v1/warehouses/{id}` updates `name`, `address`, and `is_active` (`code` is immutable).
- [x] AC-P2-4: `GET /api/v1/warehouses` lists all warehouses (including inactive ones for admin view).
- [x] AC-P2-5: Endpoints require `manage-warehouses` gate; unauthorized returns `403`.

**Expected Result:** Admin can manage physical warehouse locations and deactivate retired sites.

#### Assumptions

- A-P2-1: `code` is immutable post-creation.
- A-P2-2: Warehouses are not soft-deleted; they use `is_active` status toggle.

#### Files map

```
app/
  Http/
    Controllers/API/Warehouse/WarehouseController.php
    Requests/Warehouse/StoreWarehouseRequest.php
    Requests/Warehouse/UpdateWarehouseRequest.php
    Resources/WarehouseResource.php
  Models/Warehouse.php
  Core/Services/Warehouse/WarehouseService.php
database/
  migrations/XXXX_XX_XX_XXXXXX_create_warehouses_table.php
  factories/WarehouseFactory.php
routes/apis/V1/admin.php
lang/en.json
lang/ar.json
```

#### Sub-phase 2.1 — Database & Setup

1. **Migration**: `create_warehouses_table`
   - `id`: bigIncrements
   - `name`: string(255)
   - `code`: string(50) unique
   - `address`: string(255) nullable
   - `is_active`: boolean default `true`
   - `timestamps()`
2. **Model**: `Warehouse`
   - `$fillable = ['name', 'code', 'address', 'is_active']`
   - Cast: `is_active` => `boolean`
3. **Factory**: `WarehouseFactory`

#### Sub-phase 2.2 — Full-stack API & Logic

1. **`StoreWarehouseRequest`**:
   - `name`: required string max:255
   - `code`: required string max:50 `Rule::unique('warehouses', 'code')`
   - `address`: nullable string
2. **`UpdateWarehouseRequest`**:
   - `name`: sometimes string max:255
   - `address`: nullable string
   - `is_active`: sometimes boolean
   - `code` excluded from validation to enforce immutability.
3. **`WarehouseService`**:
   - `index()`, `findById()`, `store()`, `update()`
4. **`WarehouseResource`**:
   - Fields: `id`, `name`, `code`, `address`, `is_active`, `created_at`
5. **`WarehouseController`**:
   - Extends BaseController, delegates to `WarehouseService`, uses `InteractWithResponse`.
6. **Routes**:
   - Registered under `manage-warehouses` gate in `routes/apis/V1/admin.php`.
7. **Translations**:
   - `"Warehouse created"`, `"Warehouse updated"`.

#### API Contract

```http
POST /api/v1/warehouses
Content-Type: application/json

{
  "name": "Central Warehouse",
  "code": "WH-CENTRAL",
  "address": "789 Main Rd"
}
```

```json
// Response 201 Created
{
  "ok": true,
  "code": 201,
  "message": "Warehouse created",
  "direct": null,
  "data": {
    "id": 1,
    "name": "Central Warehouse",
    "code": "WH-CENTRAL",
    "address": "789 Main Rd",
    "is_active": true,
    "created_at": "2026-07-26T08:00:00Z"
  }
}
```

#### Tests (this phase)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: Admin creates warehouse | Feature | `tests/Feature/Warehouse/WarehouseCrudTest.php` | 201, DB has row |
| Sad: Duplicate warehouse code | Feature | `tests/Feature/Warehouse/WarehouseCrudTest.php` | 422, field error `code` |
| Sad: Non-admin user | Feature | `tests/Feature/Warehouse/WarehouseCrudTest.php` | 403 |
| Happy: Deactivate warehouse | Feature | `tests/Feature/Warehouse/WarehouseCrudTest.php` | 200, `is_active = false` |

#### cURL Smoke Tests (this phase)

```bash
# Create Warehouse (201 Created)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/warehouses \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"name\":\"North Hub\",\"code\":\"NHB\",\"address\":\"123 Industrial St\"}"

# List Warehouses (200 OK)
curl.exe -i -X GET http://127.0.0.1:8000/api/v1/warehouses \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"

# Get Single Warehouse (200 OK)
curl.exe -i -X GET http://127.0.0.1:8000/api/v1/warehouses/1 \
  -H "Accept: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>"

# Update / Deactivate Warehouse (200 OK)
curl.exe -i -X PUT http://127.0.0.1:8000/api/v1/warehouses/1 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"name\":\"North Hub B\",\"address\":\"456 New Rd\",\"is_active\":false}"
```

#### Edge cases

- E1-P2: Deactivated warehouses are returned in admin listings, but excluded from reservation logic in later phases.

---

### Phase 3 — Baseline Stock Adjustment (US-2.3)

#### User story

**As an** Admin  
**I want to** perform signed quantity adjustments on product inventory in a specific warehouse  
**So that** stock baseline levels are accurately seeded and audited without allowing negative stock.

**Acceptance Criteria:**

- [x] AC-P3-1: `POST /api/v1/inventory/adjust` accepts `product_id`, `warehouse_id`, `quantity` (signed int), and `reason`.
- [x] AC-P3-2: Upserts the `inventory` row (creates if non-existent, updates if existing).
- [x] AC-P3-3: If `quantity_available + delta < 0`, operation is rejected with `422` and stock remains unchanged.
- [x] AC-P3-4: Every successful adjustment creates an `inventory_movements` record (`type = adjustment`, `quantity_delta`, `actor_id`).
- [x] AC-P3-5: Executes atomically inside `DB::transaction()` with `lockForUpdate()` on the `inventory` row.
- [x] AC-P3-6: Requires `adjust-stock` gate; unauthorized returns `403`.
- [x] AC-P3-7: Attempting to delete a product with existing `inventory` records returns `422`.

**Expected Result:** Baseline inventory adjustments are atomic, audited, and strictly non-negative.

#### Assumptions

- A-P3-1: `quantity` parameter is a signed delta (positive adds, negative subtracts).
- A-P3-2: Database includes `CHECK (quantity_available >= 0)` constraint as a safety net.

#### Files map

```
app/
  Http/
    Controllers/API/Inventory/InventoryController.php
    Requests/Inventory/AdjustInventoryRequest.php
    Resources/InventoryResource.php
    Resources/InventoryMovementResource.php
  Models/Inventory.php
  Models/InventoryMovement.php
  Core/
    Enums/MovementType.php
    Services/Inventory/InventoryService.php
database/
  migrations/XXXX_XX_XX_XXXXXX_create_inventory_table.php
  migrations/XXXX_XX_XX_XXXXXX_create_inventory_movements_table.php
  factories/InventoryFactory.php
routes/apis/V1/admin.php
lang/en.json
lang/ar.json
```

#### Sub-phase 3.1 — Database & Setup

1. **Migration**: `create_inventory_table`
   - `id`: bigIncrements
   - `product_id`: unsignedBigInteger FK `products.id`
   - `warehouse_id`: unsignedBigInteger FK `warehouses.id`
   - `quantity_available`: integer unsigned default 0
   - `quantity_reserved`: integer unsigned default 0
   - `quantity_picked`: integer unsigned default 0
   - `quantity_packed`: integer unsigned default 0
   - `quantity_shipped`: integer unsigned default 0
   - `timestamps()`
   - Unique key on (`product_id`, `warehouse_id`)
   - Constraint: `ALTER TABLE inventory ADD CONSTRAINT chk_qty_available CHECK (quantity_available >= 0)`
2. **Migration**: `create_inventory_movements_table`
   - `id`: bigIncrements
   - `product_id`: unsignedBigInteger FK `products.id`
   - `warehouse_id`: unsignedBigInteger FK `warehouses.id`
   - `type`: string (`MovementType` enum)
   - `quantity_delta`: integer (signed)
   - `reason`: string(500) nullable
   - `actor_id`: unsignedBigInteger nullable FK `users.id`
   - `related_order_id`: unsignedBigInteger nullable
   - `related_reservation_id`: unsignedBigInteger nullable
   - `created_at`: timestamp
3. **Enum**: `MovementType`
   - Cases: `Adjustment`, `Reserve`, `Release`, `Pick`, `Pack`, `Ship`, `TransferIn`, `TransferOut`.
4. **Models**:
   - `Inventory`: `$fillable = ['product_id', 'warehouse_id', 'quantity_available', ...]`; casts for integers.
   - `InventoryMovement`: `$fillable = [...]`; cast `type` => `MovementType`; `$timestamps = false`.

#### Sub-phase 3.2 — Full-stack API & Logic

1. **`AdjustInventoryRequest`**:
   - `product_id`: required exists `products,id` (whereNull `deleted_at`)
   - `warehouse_id`: required exists `warehouses,id`
   - `quantity`: required integer not 0
   - `reason`: required string max:500
2. **`InventoryService`**:
   - `adjust(array $data, int $actorId)`: runs in `DB::transaction()` + `lockForUpdate()`. Checks if `$newQty < 0` → throws `ValidationException` (422). Updates `inventory` + creates `inventory_movements` row.
3. **`InventoryController`**:
   - Action `adjust()`. Uses `AdjustInventoryRequest` and returns `sendSuccessResponse` with `InventoryResource` and `InventoryMovementResource`.
4. **Routes**:
   - `POST /api/v1/inventory/adjust` under `adjust-stock` gate in `routes/apis/V1/admin.php`.
5. **Translations**:
   - `"Stock adjusted"`, `"Adjustment would bring available stock below zero"`.

#### API Contract

```http
POST /api/v1/inventory/adjust
Content-Type: application/json

{
  "product_id": 1,
  "warehouse_id": 1,
  "quantity": 500,
  "reason": "Initial stock seeding"
}
```

```json
// Response 200 OK
{
  "ok": true,
  "code": 200,
  "message": "Stock adjusted",
  "direct": null,
  "data": {
    "inventory": {
      "id": 1,
      "product_id": 1,
      "warehouse_id": 1,
      "quantity_available": 500
    },
    "movement": {
      "id": 1,
      "type": "adjustment",
      "quantity_delta": 500,
      "reason": "Initial stock seeding",
      "actor_id": 1,
      "created_at": "2026-07-26T08:00:00Z"
    }
  }
}
```

#### Tests (this phase)

| Case | Type | File | Assert |
|------|------|------|--------|
| Happy: Adjust creates new inventory row | Feature | `tests/Feature/Inventory/InventoryAdjustTest.php` | 200, inventory row created |
| Happy: Positive adjustment delta | Feature | `tests/Feature/Inventory/InventoryAdjustTest.php` | 200, quantity_available updated |
| Sad: Adjustment bringing stock below zero | Feature | `tests/Feature/Inventory/InventoryAdjustTest.php` | 422, quantity error |
| Sad: Non-admin caller | Feature | `tests/Feature/Inventory/InventoryAdjustTest.php` | 403 |
| Concurrency: Concurrent adjustments | Feature | `tests/Feature/Inventory/InventoryConcurrencyTest.php` | Final stock equals sum of deltas |

#### cURL Smoke Tests (this phase)

```bash
# Seed / Add Stock (200 OK)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/adjust \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"product_id\":1,\"warehouse_id\":1,\"quantity\":500,\"reason\":\"Initial stock seeding\"}"

# Negative Adjustment / Correction (200 OK)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/adjust \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"product_id\":1,\"warehouse_id\":1,\"quantity\":-50,\"reason\":\"Stock audit correction\"}"

# Invalid Deduction Below Zero (422 Unprocessable Entity)
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/inventory/adjust \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: test-api-key" \
  -H "Authorization: Bearer <admin-token>" \
  -d "{\"product_id\":1,\"warehouse_id\":1,\"quantity\":-1000,\"reason\":\"Excessive deduction attempt\"}"
```

#### Edge cases

- E1-P3: Adjustments on non-existent inventory rows check negative delta first before creation.
- E2-P3: MySQL `CHECK` constraint acts as secondary safety net behind application-level validation.
