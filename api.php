<?php
header("Content-Type: application/json");
 
$conn = new mysqli("localhost", "root", "", "hotel_db");
 
if ($conn->connect_error) {
    die(json_encode(["error" => "DB connection failed: " . $conn->connect_error]));
}
 
$input = json_decode(file_get_contents("php://input"), true);
$action = $input["action"] ?? "";
 
/* ---------------- DASHBOARD ---------------- */
if ($action == "dashboard") {
 
    $stats = $conn->query("
        SELECT 
        (SELECT COUNT(*) FROM rooms) as total_rooms,
        (SELECT COUNT(*) FROM rooms WHERE status='Available') as available_rooms,
        (SELECT COUNT(*) FROM guests) as total_guests,
        (SELECT COUNT(*) FROM bookings WHERE status='Confirmed') as active_bookings
    ")->fetch_assoc();
 
    $available_rooms = [];
    $res = $conn->query("
        SELECT r.room_number, r.floor, t.type_name, r.price_per_night
        FROM rooms r
        JOIN room_types t ON r.type_id = t.type_id
        WHERE r.status='Available'
    ");
    while ($row = $res->fetch_assoc()) $available_rooms[] = $row;
 
    $checked_in = [];
    $res = $conn->query("
        SELECT g.full_name, r.room_number, t.type_name, b.check_in, b.check_out
        FROM bookings b
        JOIN guests g ON b.guest_id = g.guest_id
        JOIN rooms r ON b.room_id = r.room_id
        JOIN room_types t ON r.type_id = t.type_id
        WHERE b.status='Confirmed'
    ");
    while ($row = $res->fetch_assoc()) $checked_in[] = $row;
 
    echo json_encode([
        "stats"           => $stats,
        "available_rooms" => $available_rooms,
        "checked_in"      => $checked_in
    ]);
    exit;
}
 
/* ---------------- ROOMS ---------------- */
if ($action == "get_rooms") {
    $rooms = [];
    $res = $conn->query("
        SELECT r.room_id, r.room_number, r.floor, r.status,
               t.type_name, r.price_per_night
        FROM rooms r
        JOIN room_types t ON r.type_id = t.type_id
    ");
    while ($row = $res->fetch_assoc()) $rooms[] = $row;
    echo json_encode(["rooms" => $rooms]);
    exit;
}
 
/* ---------------- GUESTS ---------------- */
if ($action == "get_guests") {
    $guests = [];
    $res = $conn->query("SELECT * FROM guests ORDER BY guest_id DESC");
    while ($row = $res->fetch_assoc()) $guests[] = $row;
    echo json_encode(["guests" => $guests]);
    exit;
}
 
if ($action == "add_guest") {
    $name    = $input["name"]    ?? "";
    $cnic    = $input["cnic"]    ?? "";
    $phone   = $input["phone"]   ?? "";
    $email   = $input["email"]   ?? "";
    $address = $input["address"] ?? "";
 
    if (!$name || !$cnic || !$phone) {
        echo json_encode(["success" => false, "error" => "Name, CNIC and Phone are required."]);
        exit;
    }
 
    $stmt = $conn->prepare("INSERT INTO guests(full_name, cnic, phone, email, address) VALUES (?,?,?,?,?)");
    $stmt->bind_param("sssss", $name, $cnic, $phone, $email, $address);
 
    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        if ($conn->errno == 1062) {
            echo json_encode(["success" => false, "error" => "A guest with this CNIC already exists."]);
        } else {
            echo json_encode(["success" => false, "error" => $conn->error]);
        }
    }
    exit;
}
 
/* ---------------- BOOKINGS ---------------- */
if ($action == "get_bookings") {
    $data = [];
    $res = $conn->query("
        SELECT b.booking_id, b.check_in, b.check_out, b.status,
               g.full_name as guest_name, r.room_number
        FROM bookings b
        JOIN guests g ON b.guest_id = g.guest_id
        JOIN rooms r  ON b.room_id  = r.room_id
        ORDER BY b.booking_id DESC
    ");
    while ($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(["bookings" => $data]);
    exit;
}
 
if ($action == "add_booking") {
    $guest_id   = (int)($input["guest_id"]  ?? 0);
    $room_id    = (int)($input["room_id"]   ?? 0);
    $check_in   = $input["check_in"]  ?? "";
    $check_out  = $input["check_out"] ?? "";
    $method     = $input["method"]    ?? "Cash";
    $created_by = (int)($input["staff_id"]  ?? 0);
 
    if (!$guest_id || !$room_id || !$check_in || !$check_out) {
        echo json_encode(["success" => false, "error" => "All fields are required."]);
        exit;
    }
 
    // Calculate amount from price x nights
    $price_res = $conn->query("SELECT price_per_night FROM rooms WHERE room_id=$room_id");
    $price_row = $price_res->fetch_assoc();
    $price     = $price_row ? (float)$price_row["price_per_night"] : 0;
    $nights    = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));
    $amount    = $price * $nights;
 
    $stmt = $conn->prepare("
        INSERT INTO bookings(guest_id, room_id, check_in, check_out, status, created_by)
        VALUES (?,?,?,?,'Confirmed',?)
    ");
    $stmt->bind_param("iissi", $guest_id, $room_id, $check_in, $check_out, $created_by);
 
    if ($stmt->execute()) {
        $booking_id     = $conn->insert_id;
        $method_escaped = $conn->real_escape_string($method);
 
        // Mark room occupied
        $conn->query("UPDATE rooms SET status='Occupied' WHERE room_id=$room_id");
 
        // Insert payment record
        $conn->query("
            INSERT INTO payments(booking_id, amount, method, status)
            VALUES ($booking_id, $amount, '$method_escaped', 'Pending')
        ");
 
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
    exit;
}
 
/* ---------------- PAYMENTS ---------------- */
if ($action == "get_pending_payments") {
    $data = [];
    $res = $conn->query("
        SELECT p.payment_id, p.booking_id, p.amount,
               g.full_name as guest_name
        FROM payments p
        JOIN bookings b ON p.booking_id = b.booking_id
        JOIN guests g   ON b.guest_id   = g.guest_id
        WHERE p.status = 'Pending'
        ORDER BY p.payment_id DESC
    ");
    while ($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(["payments" => $data]);
    exit;
}
 
if ($action == "mark_paid") {
    $booking_id = (int)($input["booking_id"] ?? 0);
    $method     = $conn->real_escape_string($input["method"] ?? "Cash");
 
    if (!$booking_id) {
        echo json_encode(["success" => false, "error" => "Booking ID is required."]);
        exit;
    }
 
    // Check if paid_at column exists
    $col_check  = $conn->query("SHOW COLUMNS FROM payments LIKE 'paid_at'");
    $has_paid_at = ($col_check && $col_check->num_rows > 0);
 
    if ($has_paid_at) {
        $conn->query("
            UPDATE payments
            SET status='Paid', method='$method', paid_at=NOW()
            WHERE booking_id=$booking_id AND status='Pending'
        ");
    } else {
        $conn->query("
            UPDATE payments
            SET status='Paid', method='$method'
            WHERE booking_id=$booking_id AND status='Pending'
        ");
    }
 
    if ($conn->affected_rows > 0) {
        // Mark booking checked out
        $conn->query("UPDATE bookings SET status='CheckedOut' WHERE booking_id=$booking_id");
        // Free the room
        $conn->query("
            UPDATE rooms SET status='Available'
            WHERE room_id = (SELECT room_id FROM bookings WHERE booking_id=$booking_id)
        ");
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "No pending payment found for Booking ID $booking_id."]);
    }
    exit;
}
 
/* ---------------- STAFF ---------------- */
if ($action == "get_staff") {
    $data = [];
    $res = $conn->query("SELECT staff_id, full_name FROM staff ORDER BY staff_id");
    if ($res) {
        while ($row = $res->fetch_assoc()) $data[] = $row;
    }
    echo json_encode(["staff" => $data]);
    exit;
}
 
/* ---------------- REPORTS ---------------- */
if ($action == "report_room_types") {
    $data = [];
    $res = $conn->query("
        SELECT t.type_name, COUNT(b.booking_id) as total_bookings
        FROM room_types t
        LEFT JOIN rooms r    ON r.type_id  = t.type_id
        LEFT JOIN bookings b ON b.room_id  = r.room_id
        GROUP BY t.type_id, t.type_name
        ORDER BY total_bookings DESC
    ");
    while ($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(["data" => $data]);
    exit;
}
 
if ($action == "report_revenue") {
    $data = [];
    $col_check   = $conn->query("SHOW COLUMNS FROM payments LIKE 'paid_at'");
    $has_paid_at = ($col_check && $col_check->num_rows > 0);
 
    if ($has_paid_at) {
        $res = $conn->query("
            SELECT DATE_FORMAT(paid_at, '%Y-%m') as month,
                   SUM(amount) as total_revenue
            FROM payments
            WHERE status = 'Paid' AND paid_at IS NOT NULL
            GROUP BY month
            ORDER BY month DESC
            LIMIT 12
        ");
    } else {
        $res = $conn->query("
            SELECT 'All Time' as month, SUM(amount) as total_revenue
            FROM payments WHERE status = 'Paid'
        ");
    }
 
    while ($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(["data" => $data]);
    exit;
}
 
if ($action == "guest_history") {
    $cnic = $conn->real_escape_string($input["cnic"] ?? "");
    $data = [];
 
    if (!$cnic) {
        echo json_encode(["history" => []]);
        exit;
    }
 
    $res = $conn->query("
        SELECT b.booking_id, r.room_number, b.check_in, b.check_out,
               b.status, COALESCE(p.amount, 0) as amount
        FROM bookings b
        JOIN guests g  ON b.guest_id  = g.guest_id
        JOIN rooms r   ON b.room_id   = r.room_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        WHERE g.cnic = '$cnic'
        ORDER BY b.booking_id DESC
    ");
 
    while ($row = $res->fetch_assoc()) $data[] = $row;
    echo json_encode(["history" => $data]);
    exit;
}
 
/* ---------------- DEFAULT ---------------- */
echo json_encode(["error" => "Invalid action: $action"]);
 