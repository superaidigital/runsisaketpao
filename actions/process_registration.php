<?php
// actions/process_registration.php
// Script to process data from the multi-step registration form (Upgraded for Shipping & Pay Later)

// --- 1. Load necessary files and set headers ---
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json; charset=utf-8');

function json_response($success, $message, $redirect_url = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($redirect_url) {
        $response['redirect_url'] = $redirect_url;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($mysqli) || $mysqli->connect_error) {
    error_log("MySQLi connection error in process_registration.php: " . ($mysqli->connect_error ?? "mysqli object not found"));
    json_response(false, 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล กรุณาติดต่อผู้ดูแลระบบ');
}

// --- 2. Verify that the request is a POST request ---
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(false, 'Invalid request method.');
}

// --- 3. Retrieve and sanitize form data ---
try {
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $distance_id = isset($_POST['distance_id']) ? intval($_POST['distance_id']) : 0;
    $distance_name = isset($_POST['distance_name']) ? e($_POST['distance_name']) : '';
    $shirt_size = isset($_POST['shirt_size']) ? e($_POST['shirt_size']) : '';
    $title = isset($_POST['title']) ? e($_POST['title']) : '';
    $first_name = isset($_POST['first_name']) ? e($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? e($_POST['last_name']) : '';
    $gender = isset($_POST['gender']) ? e($_POST['gender']) : '';
    $birth_date = isset($_POST['birth_date']) ? e($_POST['birth_date']) : '';
    $thai_id = isset($_POST['thai_id']) ? e($_POST['thai_id']) : '';
    $email = isset($_POST['email']) ? e($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? e($_POST['phone']) : '';
    $line_id = isset($_POST['line_id']) ? e($_POST['line_id']) : null;
    $disease = isset($_POST['disease']) ? e($_POST['disease']) : 'ไม่มีโรคประจำตัว';
    $disease_detail = ($disease === 'มีโรคประจำตัว' && isset($_POST['disease_detail'])) ? e($_POST['disease_detail']) : null;
    $emergency_contact_name = isset($_POST['emergency_contact_name']) ? e($_POST['emergency_contact_name']) : null;
    $emergency_contact_phone = isset($_POST['emergency_contact_phone']) ? e($_POST['emergency_contact_phone']) : null;

    // [NEW] Shipping and Total
    $shipping_option = isset($_POST['shipping_option']) ? e($_POST['shipping_option']) : 'รับเอง';
    $shipping_address = ($shipping_option === 'จัดส่ง' && isset($_POST['shipping_address'])) ? e($_POST['shipping_address']) : null;
    $total_amount_client = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;

    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

    // Check base required fields
    if (empty($event_id) || empty($distance_id) || empty($shirt_size) || empty($first_name) || empty($last_name) || empty($gender) || empty($birth_date) || empty($thai_id) || empty($email) || empty($phone)) {
        json_response(false, 'ข้อมูลที่จำเป็นไม่ครบถ้วน กรุณากรอกข้อมูลให้สมบูรณ์');
    }
    // [NEW] Check shipping required fields
    if ($shipping_option === 'จัดส่ง' && empty($shipping_address)) {
        json_response(false, 'กรุณาระบุที่อยู่สำหรับจัดส่ง');
    }

    if (!validateThaiID($thai_id)) {
        json_response(false, 'รูปแบบหมายเลขบัตรประชาชนไม่ถูกต้อง');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, 'รูปแบบอีเมลไม่ถูกต้อง');
    }
    $birthDateObj = new DateTime($birth_date);

} catch (Exception $e) {
     error_log("Data Retrieval/Validation Error: ".$e->getMessage());
     json_response(false, 'ข้อมูลที่ส่งมาไม่ถูกต้อง: ' . $e->getMessage());
}

// --- [NEW] 4. Server-side Validation of Total Amount ---
try {
    // Get event and distance prices from DB
    $stmt_prices = $mysqli->prepare("
        SELECT 
            e.enable_shipping, e.shipping_cost, e.payment_deadline,
            e.start_date, -- Need this for age calculation
            d.price AS distance_price
        FROM events e
        JOIN distances d ON d.event_id = e.id
        WHERE e.id = ? AND d.id = ?
    ");
    if (!$stmt_prices) throw new Exception("Prepare price statement failed: " . $mysqli->error);
    $stmt_prices->bind_param("ii", $event_id, $distance_id);
    $stmt_prices->execute();
    $result_prices = $stmt_prices->get_result();
    if ($result_prices->num_rows === 0) {
        throw new Exception("ไม่พบข้อมูลกิจกรรมหรือระยะทาง");
    }
    $price_data = $result_prices->fetch_assoc();
    $stmt_prices->close();

    $race_price = floatval($price_data['distance_price']);
    $shipping_cost = ($price_data['enable_shipping'] == 1 && $shipping_option === 'จัดส่ง') ? floatval($price_data['shipping_cost']) : 0;
    
    $expected_total_amount = $race_price + $shipping_cost;

    // Compare server-calculated total with client-sent total
    if (abs($expected_total_amount - $total_amount_client) > 0.01) { // Use float comparison
        error_log("Total amount mismatch. Client: $total_amount_client, Server: $expected_total_amount");
        json_response(false, "ยอดรวมที่ต้องชำระไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง");
    }
    
    // Check if Pay Later is enabled for this event
    $payLaterEnabled = $price_data['payment_deadline'] != null;

} catch (Exception $e) {
    error_log("Price Validation Error: ".$e->getMessage());
    json_response(false, "เกิดข้อผิดพลาดในการตรวจสอบราคาสมัคร: " . $e->getMessage());
}

// --- 5. Check for Duplicate Registration ---
try {
    $stmt_check = $mysqli->prepare("SELECT id FROM registrations WHERE event_id = ? AND thai_id = ?");
    if ($stmt_check === false) throw new Exception("Prepare statement failed for duplicate check: " . $mysqli->error);
    $stmt_check->bind_param("is", $event_id, $thai_id);
    $stmt_check->execute();
    $stmt_check->store_result();
    if ($stmt_check->num_rows > 0) {
        $stmt_check->close();
        json_response(false, 'หมายเลขบัตรประชาชนนี้ได้ลงทะเบียนเข้าร่วมกิจกรรมนี้ไปแล้ว');
    }
    $stmt_check->close();
} catch (Exception $e) {
    error_log("Duplicate Check Error: ".$e->getMessage());
    json_response(false, "เกิดข้อผิดพลาดในการตรวจสอบข้อมูลซ้ำ");
}

// --- 6. Handle Payment Slip Upload (if Pay Later is DISABLED) ---
$payment_slip_url = null;
$registration_status = 'รอชำระเงิน'; // Default for Pay Later

if (!$payLaterEnabled) {
    // If Pay Later is OFF, slip is required NOW.
    $registration_status = 'รอตรวจสอบ'; // Status will be pending
    try {
        if (isset($_FILES['payment_slip']) && $_FILES['payment_slip']['error'] == 0) {
            $upload_dir = '../uploads/';
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0775, true)) {
                     throw new Exception('ไม่สามารถสร้างโฟลเดอร์สำหรับอัปโหลดได้');
                }
            }
            $file = $_FILES['payment_slip'];
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
            if (in_array($file_ext, $allowed_exts) && $file['size'] <= 5 * 1024 * 1024) { // 5 MB
                $new_filename = 'slip_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $payment_slip_url = 'uploads/' . $new_filename;
                } else {
                     throw new Exception('ไม่สามารถบันทึกไฟล์สลิปได้');
                }
            } else {
                 throw new Exception('ไฟล์สลิปไม่ถูกต้อง (ต้องเป็น JPG, PNG, PDF และขนาดไม่เกิน 5MB)');
            }
        } else {
            throw new Exception('กรุณาอัปโหลดหลักฐานการชำระเงิน');
        }
    } catch (Exception $e) {
        error_log("File Upload Error: ".$e->getMessage());
        json_response(false, "เกิดข้อผิดพลาดในการอัปโหลดไฟล์: " . $e->getMessage());
    }
}
// If Pay Later IS enabled, $payment_slip_url remains null and $registration_status remains 'รอชำระเงิน'

// --- 7. Calculate Age and Find Race Category ---
$race_category_id = null; // Default to null
try {
    $event_start_date_str = $price_data['start_date']; // Use data from price check
    if (!$event_start_date_str) throw new Exception("Could not find event start date.");
    
    $event_date = new DateTime($event_start_date_str);
    $age = $event_date->diff($birthDateObj)->y;

    $check_cat_stmt = $mysqli->prepare("SELECT COUNT(id) as total FROM race_categories WHERE event_id = ? AND distance = ?");
    $check_cat_stmt->bind_param("is", $event_id, $distance_name);
    $check_cat_stmt->execute();
    $total_cats = $check_cat_stmt->get_result()->fetch_assoc()['total'];
    $check_cat_stmt->close();

    if ($total_cats > 0) {
        $cat_stmt = $mysqli->prepare("
            SELECT id
            FROM race_categories
            WHERE event_id = ? AND distance = ? AND gender = ? AND minAge <= ? AND maxAge >= ?
            LIMIT 1
        ");
        if (!$cat_stmt) throw new Exception("Failed to prepare category statement: " . $mysqli->error);
        
        $cat_stmt->bind_param("isssi", $event_id, $distance_name, $gender, $age, $age);
        $cat_stmt->execute();
        $result = $cat_stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $race_category_id = intval($row['id']);
        } else {
            error_log("No matching race category found for event_id={$event_id}, distance='{$distance_name}', gender='{$gender}', age={$age}, even though categories exist.");
        }
        $cat_stmt->close();
    }
} catch (Exception $e) {
    error_log("Age/Category Calculation Error: " . $e->getMessage());
    json_response(false, "เกิดข้อผิดพลาดในการคำนวณรุ่นการแข่งขัน: " . $e->getMessage());
}


// --- 8. Save data to the database ---
$mysqli->begin_transaction();
try {
    $registration_code = 'RUN' . date('Y') . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

    $stmt = $mysqli->prepare(
        "INSERT INTO registrations (
            registration_code, user_id, event_id, distance_id, race_category_id,
            shirt_size, title, first_name, last_name, gender,
            birth_date, thai_id, email, phone, line_id,
            disease, disease_detail,
            emergency_contact_name, emergency_contact_phone,
            payment_slip_url, status,
            shipping_option, shipping_address, total_amount 
         )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if ($stmt === false) {
        throw new Exception("Failed to prepare insert statement: " . $mysqli->error);
    }
    
    $stmt->bind_param("siiiissssssssssssssssssd",
        $registration_code,
        $user_id,
        $event_id,
        $distance_id,
        $race_category_id,
        $shirt_size,
        $title,
        $first_name,
        $last_name,
        $gender,
        $birth_date,
        $thai_id,
        $email,
        $phone,
        $line_id,
        $disease,
        $disease_detail,
        $emergency_contact_name,
        $emergency_contact_phone,
        $payment_slip_url,
        $registration_status, // 'รอชำระเงิน' or 'รอตรวจสอบ'
        $shipping_option,
        $shipping_address,
        $expected_total_amount // Use server-calculated total
    );

    if (!$stmt->execute()) {
         throw new Exception("Database execution failed: " . $stmt->error . " (Code: " . $stmt->errno . ")");
    }
    $stmt->close();

    // [NEW] Update user's default address if they are logged in and opted for shipping
    if ($user_id !== null && $shipping_option === 'จัดส่ง' && !empty($shipping_address)) {
        $update_user_stmt = $mysqli->prepare("UPDATE users SET address = ? WHERE id = ?");
        if($update_user_stmt) {
             $update_user_stmt->bind_param("si", $shipping_address, $user_id);
             $update_user_stmt->execute();
             $update_user_stmt->close();
        }
    }

    $mysqli->commit();

    $_SESSION['success_message'] = "การสมัครของคุณเสร็จสมบูรณ์! รหัสสมัครคือ: " . $registration_code;
    $_SESSION['last_registration_code'] = $registration_code;
    json_response(true, 'Registration successful!', 'index.php?page=dashboard');

} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Database Save Error: ".$e->getMessage());
    if ($mysqli->errno === 1062) {
         json_response(false, "เกิดข้อผิดพลาด: ข้อมูลซ้ำซ้อน อาจมีการสมัครด้วยข้อมูลนี้แล้ว");
    } else {
        json_response(false, "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง (" . $e->getMessage() . ")");
    }
} finally {
    if (isset($mysqli) && $mysqli instanceof mysqli && $mysqli->thread_id) {
         $mysqli->close();
    }
}
?>