<?php
// ── DB Connection ──────────────────────────────────────────
$host = 'sql102.infinityfree.com'; $db = 'if0_42096995_hotel_db'; $user = 'if0_42096995'; $pass = 'KrNYm7b5sJ0';
$pdo  = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
 
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true);
$action = $in['action'] ?? '';
 
// ── Route actions ──────────────────────────────────────────
if ($action === 'dashboard') {
    $stats = $pdo->query("SELECT (SELECT COUNT(*) FROM rooms) total_rooms, (SELECT COUNT(*) FROM rooms WHERE status='Available') available_rooms, (SELECT COUNT(*) FROM guests) total_guests, (SELECT COUNT(*) FROM bookings WHERE status='Confirmed') active_bookings")->fetch(PDO::FETCH_ASSOC);
    $available = $pdo->query("SELECT r.room_number, r.floor, rt.type_name, r.price_per_night FROM rooms r JOIN room_types rt ON r.type_id=rt.type_id WHERE r.status='Available'")->fetchAll(PDO::FETCH_ASSOC);
    $checkedin = $pdo->query("SELECT b.booking_id, g.full_name, r.room_number, rt.type_name, b.check_in, b.check_out FROM bookings b JOIN guests g ON b.guest_id=g.guest_id JOIN rooms r ON b.room_id=r.room_id JOIN room_types rt ON r.type_id=rt.type_id WHERE b.status='Confirmed' AND CURDATE() BETWEEN b.check_in AND b.check_out")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['stats'=>$stats, 'available_rooms'=>$available, 'checked_in'=>$checkedin]);
 
} elseif ($action === 'get_rooms') {
    $rows = $pdo->query("SELECT r.room_id, r.room_number, r.floor, rt.type_name, r.price_per_night, r.status FROM rooms r JOIN room_types rt ON r.type_id=rt.type_id ORDER BY r.price_per_night")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['rooms'=>$rows]);
 
} elseif ($action === 'get_guests') {
    $rows = $pdo->query("SELECT * FROM guests ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['guests'=>$rows]);
 
} elseif ($action === 'add_guest') {
    $s = $pdo->prepare("INSERT INTO guests (cnic,full_name,phone,email,address) VALUES (?,?,?,?,?)");
    try { $s->execute([$in['cnic'],$in['full_name'],$in['phone'],$in['email']??null,$in['address']??null]); echo json_encode(['success'=>true]); }
    catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
 
} elseif ($action === 'get_staff') {
    $rows = $pdo->query("SELECT * FROM staff ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['staff'=>$rows]);
 
} elseif ($action === 'get_bookings') {
    $rows = $pdo->query("SELECT b.booking_id, g.full_name guest_name, r.room_number, b.check_in, b.check_out, b.status FROM bookings b JOIN guests g ON b.guest_id=g.guest_id JOIN rooms r ON b.room_id=r.room_id ORDER BY b.booked_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['bookings'=>$rows]);
 
} elseif ($action === 'add_booking') {
    try {
        $pdo->beginTransaction();
        $price = $pdo->prepare("SELECT price_per_night FROM rooms WHERE room_id=? FOR UPDATE");
        $price->execute([$in['room_id']]); $p = $price->fetchColumn();
        $conflict = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id=? AND status='Confirmed' AND check_in<? AND check_out>?");
        $conflict->execute([$in['room_id'],$in['check_out'],$in['check_in']]);
        if ($conflict->fetchColumn() > 0) { $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'Room already booked for these dates.']); exit; }
        $ins = $pdo->prepare("INSERT INTO bookings (guest_id,room_id,check_in,check_out,status,created_by) VALUES (?,?,?,?,'Confirmed',?)");
        $ins->execute([$in['guest_id'],$in['room_id'],$in['check_in'],$in['check_out'],$in['created_by']]);
        $bid = $pdo->lastInsertId();
        $nights = (strtotime($in['check_out'])-strtotime($in['check_in']))/86400;
        $pdo->prepare("INSERT INTO payments (booking_id,amount,method,status) VALUES (?,?,?,'Pending')")->execute([$bid, $nights*$p, $in['method']]);
        $pdo->prepare("UPDATE rooms SET status='Occupied' WHERE room_id=?")->execute([$in['room_id']]);
        $pdo->commit();
        echo json_encode(['success'=>true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
 
} elseif ($action === 'cancel_booking') {
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE booking_id=? AND status='Confirmed'");
        $s->execute([$in['booking_id']]);
        if ($s->rowCount()) { $rid = $pdo->query("SELECT room_id FROM bookings WHERE booking_id=".(int)$in['booking_id'])->fetchColumn(); $pdo->prepare("UPDATE rooms SET status='Available' WHERE room_id=?")->execute([$rid]); }
        $pdo->commit(); echo json_encode(['success'=>true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
 
} elseif ($action === 'get_pending_payments') {
    $rows = $pdo->query("SELECT b.booking_id, g.full_name guest_name, r.room_number, p.amount, p.method, p.status payment_status FROM bookings b JOIN guests g ON b.guest_id=g.guest_id JOIN rooms r ON b.room_id=r.room_id JOIN payments p ON b.booking_id=p.booking_id WHERE p.status='Pending'")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['payments'=>$rows]);
 
} elseif ($action === 'mark_paid') {
    $s = $pdo->prepare("UPDATE payments SET status='Paid', method=?, paid_at=NOW() WHERE booking_id=? AND status='Pending'");
    $s->execute([$in['method'],$in['booking_id']]);
    echo json_encode(['success'=>$s->rowCount()>0, 'error'=>$s->rowCount()?null:'Payment not found or already paid.']);
 
} elseif ($action === 'report_room_types') {
    $rows = $pdo->query("SELECT rt.type_name, COUNT(b.booking_id) total_bookings FROM bookings b JOIN rooms r ON b.room_id=r.room_id JOIN room_types rt ON r.type_id=rt.type_id WHERE b.status!='Cancelled' GROUP BY rt.type_name ORDER BY total_bookings DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data'=>$rows]);
 
} elseif ($action === 'report_revenue') {
    $rows = $pdo->query("SELECT DATE_FORMAT(paid_at,'%Y-%m') month, COUNT(*) total_payments, SUM(amount) total_revenue FROM payments WHERE status='Paid' GROUP BY DATE_FORMAT(paid_at,'%Y-%m') ORDER BY month DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['data'=>$rows]);
 
} elseif ($action === 'guest_history') {
    $s = $pdo->prepare("SELECT b.booking_id, g.full_name guest_name, r.room_number, rt.type_name room_type, b.check_in, b.check_out, p.amount, p.status payment_status, b.status booking_status FROM bookings b JOIN guests g ON b.guest_id=g.guest_id JOIN rooms r ON b.room_id=r.room_id JOIN room_types rt ON r.type_id=rt.type_id JOIN payments p ON b.booking_id=p.booking_id WHERE g.cnic=?");
    $s->execute([$in['cnic']]); echo json_encode(['history'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
 
} else { echo json_encode(['error'=>'Unknown action']); }
 