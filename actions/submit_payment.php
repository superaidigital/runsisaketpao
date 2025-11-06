<?php
// actions/submit_payment.php
// สคริปต์สำหรับรับการแจ้งโอนเงิน (Pay Later)

require_once '../config.php';
require_once '../functions.php';

// ฟังก์ชันสำหรับจัดการข้อผิดพลาดและ Redirect
function handle_payment_error($message) {
    $_SESSION['error_message'] = $message;
    header('Location: ../index.php?page=dashboard');
    exit;
}

// --- 1. ตรวจสอบว่าเป็น POST Request และมีข้อมูลครบถ้วน ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $registration_id = isset($_POST['registration_id']) ? intval($_POST['registration_id']) : 0;

    if ($registration_id === 0) {
        handle_payment_error("รหัสการสมัครไม่ถูกต้อง");
    }

    // --- 2. ตรวจสอบไฟล์ที่อัปโหลด ---
    $payment_slip_url = null;
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
        handle_payment_error("เกิดข้อผิดพลาดในการอัปโหลดไฟล์: " . $e->getMessage());
    }

    // --- 3. อัปเดตฐานข้อมูล ---
    if ($payment_slip_url !== null) {
        $mysqli->begin_transaction();
        try {
            // ดึงข้อมูลสถานะปัจจุบันก่อน
            $stmt_check = $mysqli->prepare("SELECT status FROM registrations WHERE id = ?");
            $stmt_check->bind_param("i", $registration_id);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("ไม่พบรหัสการสมัครนี้");
            }
            
            $current_status = $result->fetch_assoc()['status'];
            $stmt_check->close();

            // อนุญาตให้อัปเดตเฉพาะเมื่อสถานะเป็น 'รอชำระเงิน'
            if ($current_status !== 'รอชำระเงิน') {
                throw new Exception("ไม่สามารถแจ้งโอนซ้ำได้ สถานะปัจจุบันคือ: " . $current_status);
            }

            // อัปเดตสถานะเป็น 'รอตรวจสอบ' และบันทึก URL ของสลิป
            $stmt_update = $mysqli->prepare("UPDATE registrations SET status = 'รอตรวจสอบ', payment_slip_url = ? WHERE id = ? AND status = 'รอชำระเงิน'");
            $stmt_update->bind_param("si", $payment_slip_url, $registration_id);
            
            if (!$stmt_update->execute()) {
                throw new Exception("Database update failed: " . $stmt_update->error);
            }
            
            if ($stmt_update->affected_rows === 0) {
                 throw new Exception("ไม่สามารถอัปเดตข้อมูลได้ อาจมีการเปลี่ยนแปลงสถานะไปแล้ว");
            }
            
            $stmt_update->close();
            $mysqli->commit();

            $_SESSION['success_message'] = "แจ้งชำระเงินสำเร็จ! รหัสการสมัคร " . $registration_id . " ขณะนี้อยู่ในสถานะ 'รอตรวจสอบ'";
            header('Location: ../index.php?page=dashboard');
            exit;

        } catch (Exception $e) {
            $mysqli->rollback();
            handle_payment_error("เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage());
        }
    }

} else {
    handle_payment_error("Invalid request method.");
}
?>