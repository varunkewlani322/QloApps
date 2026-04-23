# Project Plan: Centralized AI-Friendly Analytics Architecture

## Objective
Refactor the QloApps analytics and dashboard reporting architecture. We are replacing highly fragmented, multi-join SQL queries with centralized "Gateway Functions" located in a new `HotelAnalyticsCore` class. The database has been denormalized to make `qlo_htl_booking_detail` and `qlo_service_product_order_detail` self-sufficient "Fact Tables."

## Tasks

### Phase 1 — Pre-requisites (DONE)
- [x] **Pre-req 1:** Denormalize `qlo_htl_booking_detail` — added `unit_price_tax_incl` and `unit_price_tax_excl` columns.
- [x] **Pre-req 2:** Update `HotelBookingDetail.php` ObjectModel `$definition` to include the two new unit price fields.
- [x] **Pre-req 3:** Override `PaymentModule::validateOrder()` in `classes/PaymentModule.php` to map `unit_price_tax_incl` / `unit_price_tax_excl` from the cart when the booking row is created.
- [x] **Pre-req 4:** Override `AdminOrdersController.php` AJAX handlers (`ajaxProcessAddProductOnOrder`, `ajaxProcessEditProductOnOrder`) to keep unit prices in sync on manual order edits.

### Phase 2 — Core Gateway Class (IN PROGRESS)
- [x] **Task 1:** Create `classes/order/HotelAnalytics.php` containing `class HotelAnalyticsCoreCore extends ObjectModel`.
    - Defined `public static $definition` (minimal — query engine only, no CRUD).
    - Declared all `METRIC_*` and `SVC_METRIC_*` constants.
    - Documented internal routing maps (`$occupationalMetrics`, `$includesCancelledMetrics`, `$includesRefundedMetrics`).
- [x] **Task 2:** Implement `getRoomAnalytics($metricType, $dateFrom, $dateTo, $params = [])` gateway.
    - [x] Base table locked to `qlo_htl_booking_detail hbd`.
    - [x] Rule 1 (Dynamic Joins): `_getRoomDueAmount` JOINs `qlo_orders` for `o.valid=1`; `_getRoomExpenses` JOINs `qlo_order_detail` for `purchase_supplier_price`; revenue/occupancy metrics need no extra joins.
    - [x] Rule 2 (Status Filtering): default `hbd.is_refunded = 0 AND hbd.is_cancelled = 0`; overridden for `refunds` (is_refunded=1) and `cancellations` (is_cancelled=1).
    - [x] Rule 3 (Datewise Breakdown): transactional metrics use SQL `GROUP BY LEFT(date_add,10)`; `occupied_rooms` uses a PHP `while` loop spreading each booking across its nightly span.
    - [x] Implemented initial three demo metrics: `total_revenue`, `occupied_rooms`, `total_due_amount`.
    - [x] Implemented helper metrics: `total_sales`, `refunds`, `expenses`, `arrivals`, `departures`, `cancellations`, `average_length_of_stay`, `average_lead_time`, `average_guests_per_booking`.
    - [x] `_fillDateRange()` utility — fills gap days with zero to avoid chart holes.
    - [x] `_buildHotelFilter()` utility — optional per-hotel WHERE fragment.
    - [x] `_buildTransactionalDateFilter()` utility — reusable `BETWEEN` clause.

### Phase 3 — Service Analytics Gateway
- [ ] **Task 3:** Implement `getServiceAnalytics($metricType, $dateFrom, $dateTo, $params = [])` gateway.
    - [ ] Base table locked to `qlo_service_product_order_detail spod`.
    - [ ] Implement `service_total_revenue` — `SUM(spod.total_price_tax_excl)`; JOIN `hbd` for date and status gate.
    - [ ] Implement `service_total_sales` — `SUM(spod.unit_price_tax_incl * spod.quantity)`; JOIN `hbd`.
    - [ ] Implement `service_quantity_sold` — `SUM(spod.quantity)`; JOIN `hbd`.
    - [ ] Datewise breakdown for all service metrics via `_fillDateRange()`.
    - [ ] Hotel filter via `_buildHotelFilter($params, 'spod')`.
    - [ ] **Note:** `getServiceAnalytics()` skeleton + `SVC_METRIC_*` constants are already written in `HotelAnalytics.php` from Task 2. This task wires up any remaining service metrics and validates them.

### Phase 4 — Integration & Cache Registration
- [ ] **Task 4:** Register `HotelAnalytics` in PrestaShop's class index.
    - [ ] Delete `cache/class_index.php` so PS regenerates the autoloader entry for `HotelAnalyticsCore`.
    - [ ] Confirm `HotelAnalyticsCore` is resolvable via `new HotelAnalyticsCore()` in a test controller.
- [ ] **Task 5:** Create an override stub `override/classes/order/HotelAnalytics.php` containing `class HotelAnalytics extends HotelAnalyticsCoreCore {}` so calling code can instantiate `new HotelAnalyticsCore()` without touching the core file.

### Phase 5 — Migrate Existing Stats Modules
- [ ] **Task 6:** Identify all stats modules that perform room revenue / occupancy queries directly (e.g. `statssales`, `qlostatsserviceproducts`, `statscheckup`).
- [ ] **Task 7:** Refactor `statssales` to call `HotelAnalyticsCore::getRoomAnalytics('total_revenue', ...)` instead of its own SQL.
- [ ] **Task 8:** Refactor `qlostatsserviceproducts` to call `getServiceAnalytics('service_total_revenue', ...)`.
- [ ] **Task 9:** Refactor `statscheckup` / dashboard KPIs to use the gateway for `occupied_rooms`, `arrivals`, `departures`.
- [ ] **Task 10:** Remove dead SQL code from migrated modules and confirm no regressions via manual testing on the Admin Stats page.

### Phase 6 — Validation & Documentation
- [ ] **Task 11:** Spot-check gateway output against legacy query output for a known date range on a staging database.
- [ ] **Task 12:** Document `getRoomAnalytics` and `getServiceAnalytics` usage in a `docs/analytics-gateway.md` reference.
