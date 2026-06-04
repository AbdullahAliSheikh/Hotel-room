<?php
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "hotel_db");

if ($conn->connect_error) {
    die(json_encode(["error" => "DB connection failed"]));
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
        (SELECT COUNT(*) FROM bookings WHERE status='Active') as active_bookings
    ")->fetch_assoc();

    $available_rooms = [];
    $res = $conn->query("
        SELECT r.room_number, r.floor, t.type_name, r.price_per_night
        FROM rooms r
        JOIN room_types t ON r.type_id = t.type_id
        WHERE r.status='Available'
    ");
    while ($row = $res->fetch_assoc()) {
        $available_rooms[] = $row;
    }

    $checked_in = [];
    $res = $conn->query("
        SELECT g.full_name, r.room_number, t.type_name, b.check_in, b.check_out
        FROM bookings b
        JOIN guests g ON b.guest_id = g.guest_id
        JOIN rooms r ON b.room_id = r.room_id
        JOIN room_types t ON r.type_id = t.type_id
        WHERE b.status='Active'
    ");
    while ($row = $res->fetch_assoc()) {
        $checked_in[] = $row;
    }

    echo json_encode([
        "stats" => $stats,
        "available_rooms" => $available_rooms,
        "checked_in" => $checked_in
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

    while ($row = $res->fetch_assoc()) {
        $rooms[] = $row;
    }

    echo json_encode(["rooms" => $rooms]);
    exit;
}

/* ---------------- GUESTS ---------------- */
if ($action == "get_guests") {

    $guests = [];
    $res = $conn->query("SELECT * FROM guests");

    while ($row = $res->fetch_assoc()) {
        $guests[] = $row;
    }

    echo json_encode(["guests" => $guests]);
    exit;
}

if ($action == "add_guest") {

    $name = $input["name"] ?? "";
    $cnic = $input["cnic"] ?? "";
    $phone = $input["phone"] ?? "";
    $email = $input["email"] ?? "";
    $address = $input["address"] ?? "";

    $stmt = $conn->prepare("
        INSERT INTO guests(full_name, cnic, phone, email, address)
        VALUES (?,?,?,?,?)
    ");

    $stmt->bind_param("sssss", $name, $cnic, $phone, $email, $address);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
    exit;
}

/* ---------------- BOOKINGS ---------------- */
if ($action == "get_bookings") {

    $data = [];
    $res = $conn->query("
        SELECT b.*, g.full_name as guest_name, r.room_number
        FROM bookings b
        JOIN guests g ON b.guest_id = g.guest_id
        JOIN rooms r ON b.room_id = r.room_id
    ");

    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode(["bookings" => $data]);
    exit;
}

if ($action == "add_booking") {

    $guest_id = $input["guest_id"] ?? 0;
    $room_id = $input["room_id"] ?? 0;
    $check_in = $input["check_in"] ?? "";
    $check_out = $input["check_out"] ?? "";

    $stmt = $conn->prepare("
        INSERT INTO bookings(guest_id, room_id, check_in, check_out, status)
        VALUES (?,?,?,?, 'Active')
    ");

    $stmt->bind_param("iiss", $guest_id, $room_id, $check_in, $check_out);

    if ($stmt->execute()) {
        $conn->query("UPDATE rooms SET status='Occupied' WHERE room_id=$room_id");
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
    exit;
}

/* ---------------- DEFAULT ---------------- */
echo json_encode(["error" => "Invalid action"]);