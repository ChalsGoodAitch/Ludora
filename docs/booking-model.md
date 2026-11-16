# Booking model and operations

One installation owns one venue. Each room is exclusive. Room editorial data, extras and packages use native CPT/meta; reservations, claims, resource locks, audit events and mail outbox use indexed InnoDB tables. Theme changes do not move data.

## One domain and inventory

`Domain/Engine::quote` validates local calendar/time/rules and calculates integer minor-unit money. `Store::quote` resolves trusted packages and checks the returned resources. All write operations acquire the catalogue row first and resource keys in lexical order within a transaction. This intentionally serializes single-venue writes. Nested domain transactions are rejected before MySQL can implicitly commit an outer transaction.

Intervals are `[start,end)` UTC instants; snapshots retain the IANA timezone. Preparation/cleanup expand conflict intervals for rooms and limited extras, without multiplying price duration. Hourly, shift and full business day all claim the same `room:<id>` resource. A full day spans the first opening to the last closing, including breaks. Fixed local DST gaps/folds are rejected; duration is elapsed real minutes. Start times use the configured minute grid.

Precedence: explicit closed date → opening date exception → weekly opening; absolute/local manual blocks are additional exclusions. Date price exception → weekday price → base mode price. Minimum notice is elapsed minutes; horizon uses business-local calendar dates. Hourly/shift must fit one open interval. Active holds preserve their agreed snapshot. An expired hold/request must validate current closures, notice, buffers and shared quotas before reacquisition, without changing its financial snapshot or original promised interval.

## Money and packages

Rates are integer minor units in the configured currency precision. Hourly charge is rounded half up once per component from rate×elapsed-minutes/60. Fixed/hour/attendee extras are a single selection; attendee pricing defaults to children, with adults/all configurable. Quantity extras multiply only their selected quantity. Shared resource consumption remains separate from monetary units. Required extras and package inclusions cannot be removed by browser manipulation. Packages resolve configured room/mode/shift/duration/included extras through the same Engine, with no parallel tariff table.

Without WooCommerce, net component prices receive the configured tax basis points, shown separately. With WooCommerce, its currency/precision/standard base-location tax rates and rounding control the single venue-service order item. Component lines are net price breakdowns; WooCommerce assesses the reservation as one standard-class taxable service. Separate extra tax classes, customer-location digital taxes, coupons/fees, multi-booking carts and deposits are outside this version. Changing currency/precision reinterprets configured integers; review rates before doing so.

A quote signature binds schedule, components, taxes, total and currency/precision. The server recalculates within the reservation transaction and rejects changed quotes. Existing booking amounts remain immutable; paid rescheduling must preserve total, tax, currency, precision and WC fiscal treatment. Failure rolls back times, snapshot and claims.

## State and expiration

| Reservation state | Availability |
|---|---|
| hold | Blocks only until configured expiry (demo15min). |
| pending | Blocks only until its separate manual expiry (demo48h). |
| confirmed | Blocks without temporary expiry. |
| cancelled / rejected / expired / completed | Does not block. Completion requires end time reached. |

Payment state is independent: pending, paid, failed, refunded. Administrative confirmation can remain unpaid. WooCommerce paid events are idempotent; late failure cannot regress paid, and refunded cannot be resurrected by old paid callbacks. Late receipt after cancellation or unavailable expired inventory is recorded as paid with venue-review audit/notification, never as a second confirmed booking. Partial refunds do not cancel or mark full refund. Refund operations belong to WooCommerce, not reservation cancellation.

Guest sessions use HttpOnly/SameSite cookies and separate cookie-bound CSRF, same-origin checks, salted IP rate counters and secure retry keys. Private lookup uses a256-bit HMAC token stored only as a hash. Public availability has no customer identity. Catalogue writes require manage_ludora, including native REST; nonces never replace capabilities.

Mail uses a serialized transactional outbox with per-recipient hashed acknowledgements. Successful recipients are skipped on retries; obsolete queued confirmations are suppressed. SMTP cannot guarantee exactly-once delivery across a transport/process crash; five delivery attempts are bounded. Reliable cron/mail hosting must be configured. Privacy erasure and retention reread current state under the same catalogue transaction to avoid racing confirmation; WooCommerce records have separate retention.

CSV uses bounded pages inside a repeatable consistent read snapshot, without inventory row locks or a1000-row export truncation. Administrative results paginate; calendar uses actual business-local start dates and explicitly does not imply free inventory on empty days. Formula-like fields are neutralized on export.

Deactivation keeps records, clears scheduled maintenance, and drafts the hidden native carrier so stale carts cannot buy it without booking validation. A subsequent authorized checkout restores the carrier. Explicit uninstall purging removes plugin tables/CPTs/settings and its own carrier; existing native WC orders and ordinary pages are retained.
