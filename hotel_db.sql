DROP DATABASE IF EXISTS hotel_db;
CREATE DATABASE hotel_db;
USE hotel_db;

-- ============================================================
-- SECTION 1: TABLE DEFINITIONS
-- ============================================================

-- TABLE: room_types  (EER Specialization lookup)
-- FD: type_id → type_name, description
CREATE TABLE room_types (
    type_id     INT AUTO_INCREMENT PRIMARY KEY,
    type_name   VARCHAR(30)  NOT NULL UNIQUE,
    description VARCHAR(255)
);

-- TABLE: rooms
-- FD: room_id → room_number, floor, type_id, price_per_night, status
-- EER: room specializes into Single / Double / Suite via type_id
CREATE TABLE rooms (
    room_id         INT AUTO_INCREMENT PRIMARY KEY,
    room_number     VARCHAR(10)   NOT NULL UNIQUE,
    floor           TINYINT       NOT NULL CHECK (floor >= 1),
    type_id         INT           NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL CHECK (price_per_night > 0),
    status          VARCHAR(20)   NOT NULL DEFAULT 'Available'
                        CHECK (status IN ('Available','Occupied','Maintenance')),
    FOREIGN KEY (type_id) REFERENCES room_types(type_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

-- TABLE: guests
-- FD: guest_id → cnic, full_name, phone, email, address
-- 1NF: all fields atomic; 3NF: no transitive deps
CREATE TABLE guests (
    guest_id  INT AUTO_INCREMENT PRIMARY KEY,
    cnic      VARCHAR(15)  NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone     VARCHAR(20)  NOT NULL,
    email     VARCHAR(100),
    address   VARCHAR(255)
);

-- TABLE: staff
-- FD: staff_id → username, full_name, role, phone, hire_date
CREATE TABLE staff (
    staff_id  INT AUTO_INCREMENT PRIMARY KEY,
    username  VARCHAR(50)  NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    role      VARCHAR(20)  NOT NULL DEFAULT 'Receptionist'
                  CHECK (role IN ('Admin','Receptionist')),
    phone     VARCHAR(20),
    hire_date DATE         NOT NULL DEFAULT (CURRENT_DATE)
);

-- TABLE: bookings
-- FD: booking_id → guest_id, room_id, check_in, check_out, status, created_by
-- Overlapping date constraint enforced via Transaction (SECTION 5)
CREATE TABLE bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    guest_id   INT  NOT NULL,
    room_id    INT  NOT NULL,
    check_in   DATE NOT NULL,
    check_out  DATE NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'Confirmed'
                   CHECK (status IN ('Confirmed','Cancelled','CheckedOut')),
    created_by INT  NOT NULL,
    booked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_dates CHECK (check_out > check_in),
    FOREIGN KEY (guest_id)   REFERENCES guests(guest_id)  ON DELETE RESTRICT,
    FOREIGN KEY (room_id)    REFERENCES rooms(room_id)    ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES staff(staff_id)   ON DELETE RESTRICT
);

-- TABLE: payments
-- FD: payment_id → booking_id, amount, method, paid_at, status
-- 3NF: amount is a fact of payment, not derived from booking
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT           NOT NULL UNIQUE,
    amount     DECIMAL(10,2) NOT NULL CHECK (amount > 0),
    method     VARCHAR(10)   NOT NULL CHECK (method IN ('Cash','Card')),
    status     VARCHAR(10)   NOT NULL DEFAULT 'Pending'
                   CHECK (status IN ('Pending','Paid')),
    paid_at    DATETIME,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE
);


-- ============================================================
-- SECTION 2: INDEXING (with justification)
-- ============================================================

-- Speeds up availability queries: "is room X free between date A and B?"
CREATE INDEX idx_bookings_dates
    ON bookings(room_id, check_in, check_out);

-- Fast lookup of a guest's booking history
CREATE INDEX idx_bookings_guest
    ON bookings(guest_id);

-- Quick "show all available rooms" filter
CREATE INDEX idx_rooms_status
    ON rooms(status);

-- Quick pending-payment reports
CREATE INDEX idx_payments_status
    ON payments(status);


-- ============================================================
-- SECTION 3: DML — SEED DATA
-- ============================================================

-- Room Types (EER specialization)
INSERT INTO room_types (type_name, description) VALUES
    ('Single', 'One single bed, suitable for solo travellers'),
    ('Double', 'One double or two twin beds, suitable for couples'),
    ('Suite',  'Luxury suite with separate living area');

-- Rooms
INSERT INTO rooms (room_number, floor, type_id, price_per_night, status) VALUES
    ('101', 1, 1, 3500.00,  'Available'),
    ('102', 1, 1, 3500.00,  'Available'),
    ('201', 2, 2, 6000.00,  'Available'),
    ('202', 2, 2, 6000.00,  'Occupied'),
    ('301', 3, 3, 12000.00, 'Available'),
    ('302', 3, 3, 12000.00, 'Maintenance'),
    ('103', 1, 1, 3500.00,  'Available'),
    ('203', 2, 2, 6500.00,  'Available');

-- Staff
INSERT INTO staff (username, full_name, role, phone, hire_date) VALUES
    ('admin01',       'Ahmed Raza',  'Admin',        '0300-1111111', '2022-01-15'),
    ('receptionist1', 'Sara Khan',   'Receptionist', '0311-2222222', '2023-03-10'),
    ('receptionist2', 'Bilal Malik', 'Receptionist', '0322-3333333', '2023-06-01');

-- Guests
INSERT INTO guests (cnic, full_name, phone, email, address) VALUES
    ('42101-1234567-1', 'Talha Mehmood', '0333-4444444', 'talha@mail.com', 'House 5, Block A, Karachi'),
    ('42201-7654321-2', 'Yasir Nawaz',   '0344-5555555', 'yasir@mail.com', 'Flat 3, Gulshan, Karachi'),
    ('35202-9876543-3', 'Hamza Iqbal',   '0355-6666666', NULL,             'Street 7, Lahore'),
    ('42301-1122334-4', 'Ahsan Ali',     '0366-7777777', 'ahsan@mail.com', 'G-10, Islamabad'),
    ('35101-5566778-5', 'Taha Siddiqui', '0377-8888888', NULL,             'Saddar, Karachi');

-- Bookings
INSERT INTO bookings (guest_id, room_id, check_in, check_out, status, created_by) VALUES
    (1, 1, '2025-07-01', '2025-07-04', 'CheckedOut', 2),
    (2, 3, '2025-07-10', '2025-07-15', 'CheckedOut', 2),
    (3, 4, '2025-08-01', '2025-08-03', 'Confirmed',  2),
    (4, 5, '2025-08-05', '2025-08-10', 'Confirmed',  3),
    (5, 2, '2025-08-12', '2025-08-14', 'Cancelled',  2),
    (1, 7, '2025-09-01', '2025-09-05', 'Confirmed',  3);

-- Payments
INSERT INTO payments (booking_id, amount, method, status, paid_at) VALUES
    (1, 10500.00, 'Card', 'Paid',    '2025-07-01 14:00:00'),
    (2, 30000.00, 'Cash', 'Paid',    '2025-07-10 11:30:00'),
    (3, 12000.00, 'Card', 'Pending', NULL),
    (4, 60000.00, 'Cash', 'Pending', NULL),
    (5,  7000.00, 'Card', 'Pending', NULL),
    (6, 14000.00, 'Cash', 'Pending', NULL);


-- ============================================================
-- SECTION 4: TRANSACTIONS — Double-Booking Prevention
-- ============================================================
-- Each block below is a standalone transaction.
-- In a real application, replace the literal values with
-- parameters passed from your application layer (e.g. PHP).
-- ============================================================

-- ── Create a new booking ────────────────────────────────────
-- Replace literal values (guest_id=2, room_id=1, dates, method, staff_id)
-- with application variables before running.

START TRANSACTION;

-- Lock the target room row to prevent race conditions
SELECT price_per_night
FROM   rooms
WHERE  room_id = 1          -- <-- p_room_id
FOR UPDATE;

-- Abort if an overlapping confirmed booking already exists
SET @conflict = (
    SELECT COUNT(*)
    FROM   bookings
    WHERE  room_id   = 1                 -- <-- p_room_id
      AND  status    = 'Confirmed'
      AND  check_in  < '2025-10-04'      -- <-- p_check_out
      AND  check_out > '2025-10-01'      -- <-- p_check_in
);

-- Only proceed when there is no conflict
-- (In application code this would be an IF/ELSE; here we use a
--  guard: the INSERT is wrapped in a SELECT that returns 0 rows
--  when @conflict > 0, keeping the script runnable in plain SQL.)
INSERT INTO bookings (guest_id, room_id, check_in, check_out, status, created_by)
SELECT 2, 1, '2025-10-01', '2025-10-04', 'Confirmed', 2
WHERE  @conflict = 0;

-- Calculate and record the payment only if the booking was inserted
SET @new_booking_id = LAST_INSERT_ID();
SET @nights  = DATEDIFF('2025-10-04', '2025-10-01');           -- check_out - check_in
SET @price   = (SELECT price_per_night FROM rooms WHERE room_id = 1);
SET @total   = @nights * @price;

INSERT INTO payments (booking_id, amount, method, status)
SELECT @new_booking_id, @total, 'Cash', 'Pending'
WHERE  @conflict = 0 AND @new_booking_id > 0;

-- Update room status
UPDATE rooms
SET    status = 'Occupied'
WHERE  room_id  = 1
  AND  @conflict = 0;

COMMIT;


-- ── Cancel a booking ────────────────────────────────────────

START TRANSACTION;

-- Lock the booking row
SELECT room_id, status
FROM   bookings
WHERE  booking_id = 3       -- <-- p_booking_id
FOR UPDATE;

-- Cancel only if currently Confirmed
UPDATE bookings
SET    status = 'Cancelled'
WHERE  booking_id = 3
  AND  status     = 'Confirmed';

-- Free the room only if the cancellation took effect
UPDATE rooms
SET    status = 'Available'
WHERE  room_id = (SELECT room_id FROM bookings WHERE booking_id = 3)
  AND  (SELECT ROW_COUNT()) > 0;

COMMIT;


-- ── Record a payment ────────────────────────────────────────

START TRANSACTION;

UPDATE payments
SET    status  = 'Paid',
       method  = 'Cash',         -- <-- p_method
       paid_at = NOW()
WHERE  booking_id = 3            -- <-- p_booking_id
  AND  status     = 'Pending';

-- ROLLBACK if nothing was updated (already paid or not found)
-- In application code check ROW_COUNT() after the UPDATE.

COMMIT;


-- ============================================================
-- SECTION 5: REPORT QUERIES
-- (Replacing the previous VIEW definitions — same results,
--  written as inline SELECT statements)
-- ============================================================

-- R1: All currently available rooms
SELECT r.room_id, r.room_number, r.floor,
       rt.type_name, r.price_per_night
FROM   rooms r
JOIN   room_types rt ON r.type_id = rt.type_id
WHERE  r.status = 'Available';

-- R2: Currently checked-in guests
--     (Confirmed bookings where today falls between check_in and check_out)
SELECT b.booking_id, g.full_name, g.cnic, g.phone,
       r.room_number, rt.type_name,
       b.check_in, b.check_out
FROM   bookings b
JOIN   guests     g  ON b.guest_id = g.guest_id
JOIN   rooms      r  ON b.room_id  = r.room_id
JOIN   room_types rt ON r.type_id  = rt.type_id
WHERE  b.status  = 'Confirmed'
  AND  CURDATE() BETWEEN b.check_in AND b.check_out;

-- R3: Most booked room types
SELECT rt.type_name,
       COUNT(b.booking_id) AS total_bookings
FROM   bookings   b
JOIN   rooms      r  ON b.room_id = r.room_id
JOIN   room_types rt ON r.type_id = rt.type_id
WHERE  b.status != 'Cancelled'
GROUP  BY rt.type_name
ORDER  BY total_bookings DESC;

-- R4: Revenue per month
SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month,
       COUNT(*)                       AS total_payments,
       SUM(amount)                    AS total_revenue
FROM   payments
WHERE  status = 'Paid'
GROUP  BY DATE_FORMAT(paid_at, '%Y-%m')
ORDER  BY month DESC;

-- R5: Total revenue in a custom date range
SELECT SUM(amount) AS revenue_in_range
FROM   payments
WHERE  status  = 'Paid'
  AND  paid_at BETWEEN '2025-07-01' AND '2025-07-31';

-- R6: Full booking history of a specific guest (identified by CNIC)
SELECT b.booking_id,
       g.full_name      AS guest_name,
       g.cnic,
       r.room_number,
       rt.type_name     AS room_type,
       b.check_in, b.check_out,
       DATEDIFF(b.check_out, b.check_in) AS nights,
       p.amount,
       p.method,
       p.status         AS payment_status,
       b.status         AS booking_status,
       s.full_name      AS booked_by
FROM   bookings   b
JOIN   guests     g  ON b.guest_id   = g.guest_id
JOIN   rooms      r  ON b.room_id    = r.room_id
JOIN   room_types rt ON r.type_id    = rt.type_id
JOIN   payments   p  ON b.booking_id = p.booking_id
JOIN   staff      s  ON b.created_by = s.staff_id
WHERE  g.cnic = '42101-1234567-1';

-- R7: Pending payments
SELECT b.booking_id,
       g.full_name  AS guest_name,
       r.room_number,
       p.amount,
       p.method,
       p.status     AS payment_status,
       b.status     AS booking_status
FROM   bookings b
JOIN   guests   g  ON b.guest_id   = g.guest_id
JOIN   rooms    r  ON b.room_id    = r.room_id
JOIN   payments p  ON b.booking_id = p.booking_id
WHERE  p.status = 'Pending';

-- R8: Rooms available for a specific date range
SELECT r.room_number, rt.type_name, r.floor, r.price_per_night
FROM   rooms r
JOIN   room_types rt ON r.type_id = rt.type_id
WHERE  r.status = 'Available'
  AND  r.room_id NOT IN (
           SELECT room_id FROM bookings
           WHERE  status    = 'Confirmed'
             AND  check_in  < '2025-09-05'
             AND  check_out > '2025-09-01'
       );

-- R9: Filter rooms by type
SELECT r.room_number, r.floor, r.price_per_night, r.status
FROM   rooms r
JOIN   room_types rt ON r.type_id = rt.type_id
WHERE  rt.type_name = 'Double';

-- R10: All rooms with type, ordered by price
SELECT r.room_number, rt.type_name, r.floor,
       r.price_per_night, r.status
FROM   rooms r
JOIN   room_types rt ON r.type_id = rt.type_id
ORDER  BY r.price_per_night ASC;




-- ============================================================
-- SECTION 7: NORMALIZATION DOCUMENTATION
-- ============================================================
/*
  TABLE: guests
  ─────────────
  1NF : All attributes are atomic. No repeating groups.
  2NF : guest_id is the sole PK → no partial deps possible.
  3NF : No transitive deps. address does not determine any other column.

  TABLE: rooms
  ────────────
  1NF : All attributes atomic.
  2NF : Single-column PK (room_id) → no partial deps.
  3NF : type_id references room_types; type_name moved out to avoid
        transitive dependency (room_id → type_id → type_name).

  TABLE: bookings
  ───────────────
  1NF : Atomic fields; one row = one booking period.
  2NF : Single-column PK.
  3NF : price_per_night NOT stored here (lives in rooms);
        guest name NOT stored here (lives in guests).
        No transitive deps remain.

  TABLE: payments
  ───────────────
  1NF : Atomic. One payment per booking (UNIQUE constraint).
  2NF : Single-column PK.
  3NF : amount is a fact of this payment, not derived from bookings;
        no transitive deps.

  TABLE: room_types
  ─────────────────
  Introduced to remove transitive dependency in rooms:
      room_id → type_id → type_name, description
  Extracting room_types achieves 3NF for the rooms table.
*/

-- ============================================================
-- SECTION 8: TEST QUERIES
-- ============================================================

-- Test 1: Create booking — verify it appears in bookings table
SELECT * FROM bookings WHERE room_id = 1 AND check_in = '2025-10-01';

-- Test 2: Verify double-booking was blocked
--         (only one row should exist for room_id=1 on overlapping dates)
SELECT COUNT(*) AS booking_count
FROM   bookings
WHERE  room_id  = 1
  AND  status   = 'Confirmed'
  AND  check_in < '2025-10-04'
  AND  check_out > '2025-10-01';

-- Test 3: Verify cancellation took effect
SELECT booking_id, status FROM bookings WHERE booking_id = 3;

-- Test 4: Verify payment was recorded
SELECT booking_id, status, paid_at FROM payments WHERE booking_id = 3;

-- ============================================================
-- END OF SCRIPT
-- ============================================================