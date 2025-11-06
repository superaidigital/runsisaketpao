<?php
// actions/export_registrants.php
// Script to export registrant data to a CSV file (Updated for Shipping + Thai Headers)

// --- CORE BOOTSTRAP ---
require_once '../config.php';
require_once '../functions.php';

// --- Session Check & Permission ---
if (!isset($_SESSION['staff_id'])) {
    header('Location: ../admin/login.php'); 
    exit;
}
$staff_info = $_SESSION['staff_info'];
$is_super_admin = ($staff_info['role'] === 'admin');

// --- Get Event ID and verify access ---
if (!isset($_GET['event_id']) || !is_numeric($_GET['event_id'])) {
    die("Invalid Event ID.");
}
$event_id = intval($_GET['event_id']);

// Security Check
if (!$is_super_admin && $event_id !== $staff_info['assigned_event_id']) {
    die("You do not have permission to access this data.");
}

// Get search term if provided
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// --- Fetch Data ---
$base_query = "FROM registrations r JOIN distances d ON r.distance_id = d.id JOIN events e ON r.event_id = e.id WHERE r.event_id = ?";
$where_clause = '';
$params = [$event_id];
$types = 'i';

if (!empty($search_term)) {
    // [MODIFIED] Added search for registration_code and bib_number
    $where_clause = " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ? OR r.registration_code LIKE ? OR r.bib_number LIKE ?)";
    $like_term = "%{$search_term}%";
    $params[] = $like_term;
    $params[] = $like_term;
    $params[] = $like_term;
    $params[] = $like_term;
    $params[] = $like_term;
    $types .= 'sssss';
}

// [MODIFIED] Added shipping fields and total_amount
$select_fields = "SELECT 
    r.registration_code, r.bib_number, r.status, 
    r.title, r.first_name, r.last_name, 
    r.email, r.phone, r.line_id, 
    r.thai_id, r.shirt_size, 
    d.name as distance_name, 
    r.shipping_option, r.shipping_address, r.total_amount,
    r.disease, r.disease_detail,
    r.registered_at
";

$query = $select_fields . $base_query . $where_clause . " ORDER BY r.registered_at ASC";
$stmt = $mysqli->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// --- Generate CSV ---
$filename = "registrants_event_" . $event_id . "_" . date('Ymd') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Prepend BOM to force Excel to read UTF-8 correctly for Thai characters
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// [MODIFIED] Add new header columns in Thai
$header = [
    'รหัสสมัคร', 'BIB', 'สถานะ', 'คำนำหน้า', 'ชื่อจริง', 'นามสกุล', 
    'อีเมล', 'โทรศัพท์', 'Line ID', 'เลขบัตรประชาชน', 'ไซส์เสื้อ', 'ระยะทาง',
    'วิธีรับ', 'ที่อยู่จัดส่ง', 'ยอดรวม (บาท)',
    'โรคประจำตัว', 'รายละเอียดโรค', 'วันที่สมัคร'
];
fputcsv($output, $header);

// Add data rows from database
while ($row = $result->fetch_assoc()) {
    
    // [NEW] Translate shipping_option to Thai
    if ($row['shipping_option'] === 'delivery') {
        $row['shipping_option'] = 'จัดส่งทางไปรษณีย์';
    } else {
        $row['shipping_option'] = 'รับเองหน้างาน';
    }
    
    // [NEW] Clean up address field (remove newlines for CSV)
    if ($row['shipping_address']) {
         $row['shipping_address'] = str_replace(["\r\n", "\r", "\n"], " ", $row['shipping_address']);
    }

    fputcsv($output, $row);
}

fclose($output);
$stmt->close();
$mysqli->close();
exit;
?>