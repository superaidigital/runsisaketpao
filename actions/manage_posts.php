<?php
// actions/manage_posts.php
// สคริปต์สำหรับจัดการ (เพิ่ม/แก้ไข/ลบ) ข่าวสาร (เวอร์ชันอัปเกรด + ตรวจสอบ Error)

require_once '../config.php';
require_once '../functions.php';

// --- Session Check & Super Admin Permission ---
if (!isset($_SESSION['staff_id']) || $_SESSION['staff_info']['role'] !== 'admin') {
    header('Location: ../admin/login.php'); 
    exit;
}
$author_id = $_SESSION['staff_id']; // ผู้เขียนคือ staff ที่ login อยู่

// --- File Upload Function (With Error Handling) ---
function handle_post_upload($file_input_name, $sub_folder) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] !== UPLOAD_ERR_NO_FILE) {
        
        $file = $_FILES[$file_input_name];

        // [NEW] Detailed Error Checking
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE   => "ไฟล์มีขนาดใหญ่เกินกว่าที่เซิร์ฟเวอร์ (php.ini) กำหนด",
                UPLOAD_ERR_FORM_SIZE  => "ไฟล์มีขนาดใหญ่เกินกว่าที่ฟอร์มกำหนด",
                UPLOAD_ERR_PARTIAL    => "ไฟล์ถูกอัปโหลดเพียงบางส่วน",
                UPLOAD_ERR_NO_TMP_DIR => "ไม่พบโฟลเดอร์ชั่วคราว",
                UPLOAD_ERR_CANT_WRITE => "ไม่สามารถเขียนไฟล์ลงดิสก์ได้",
                UPLOAD_ERR_EXTENSION  => "PHP extension หยุดการอัปโหลดไฟล์",
            ];
            $error_message = $upload_errors[$file['error']] ?? "เกิดข้อผิดพลาดในการอัปโหลดที่ไม่รู้จัก";
            throw new Exception($error_message);
        }

        $upload_dir = "../uploads/" . $sub_folder . "/";
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0775, true)) {
                throw new Exception("ไม่สามารถสร้างโฟลเดอร์สำหรับอัปโหลดได้: " . $upload_dir);
            }
        }
        
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($file_ext, $allowed)) {
            throw new Exception("ไฟล์รูปภาพต้องเป็น .jpg, .jpeg, .png, หรือ .webp เท่านั้น");
        }

        // Check file size (e.g., 5MB limit)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception("ไฟล์มีขนาดใหญ่เกิน 5MB");
        }

        $new_filename = 'post_' . uniqid() . '.' . $file_ext;
        $destination = $upload_dir . $new_filename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            return "uploads/" . $sub_folder . "/" . $new_filename; // Return path relative to root
        } else {
            throw new Exception("ไม่สามารถย้ายไฟล์ที่อัปโหลดไปยัง: " . $destination);
        }
    }
    return null; // No file uploaded
}

// --- Multi-file Upload Function (for Gallery) ---
function handle_gallery_uploads($file_input_key, $post_id, $start_order) {
    $uploaded_paths = [];
    if (isset($_FILES[$file_input_key])) {
        $gallery_files = $_FILES[$file_input_key];
        $upload_dir = "../uploads/posts/gallery/";
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0775, true)) {
                throw new Exception("ไม่สามารถสร้างโฟลเดอร์แกลเลอรีได้");
            }
        }

        foreach ($gallery_files['name'] as $key => $name) {
            if ($gallery_files['error'][$key] === UPLOAD_ERR_OK) {
                
                $tmp_name = $gallery_files['tmp_name'][$key];
                $file_size = $gallery_files['size'][$key];
                $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                if (!in_array($file_ext, $allowed)) {
                    throw new Exception("ไฟล์ในแกลเลอรี ($name) ไม่ใช่รูปภาพที่รองรับ");
                }
                if ($file_size > 5 * 1024 * 1024) { // 5MB limit per file
                    throw new Exception("ไฟล์ในแกลเลอรี ($name) มีขนาดใหญ่เกิน 5MB");
                }

                $new_filename = $post_id . '_' . uniqid() . '.' . $file_ext;
                $destination = $upload_dir . $new_filename;
                
                if (move_uploaded_file($tmp_name, $destination)) {
                    $db_path = "uploads/posts/gallery/" . $new_filename;
                    $uploaded_paths[] = [
                        'path' => $db_path,
                        'order' => $start_order + $key
                    ];
                } else {
                    throw new Exception("ไม่สามารถย้ายไฟล์แกลเลอรี ($name) ได้");
                }
            } elseif ($gallery_files['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                // Handle errors for individual files if they weren't just "no file"
                $upload_errors = [
                    UPLOAD_ERR_INI_SIZE   => "ไฟล์ ($name) มีขนาดใหญ่เกินกว่าที่เซิร์ฟเวอร์ (php.ini) กำหนด",
                    UPLOAD_ERR_FORM_SIZE  => "ไฟล์ ($name) มีขนาดใหญ่เกินกว่าที่ฟอร์มกำหนด",
                    UPLOAD_ERR_PARTIAL    => "ไฟล์ ($name) ถูกอัปโหลดเพียงบางส่วน",
                ];
                $error_message = $upload_errors[$gallery_files['error'][$key]] ?? "เกิดข้อผิดพลาดในการอัปโหลดไฟล์: " . $name;
                throw new Exception($error_message);
            }
        }
    }
    return $uploaded_paths;
}


// --- POST Request Check ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {

    $action = $_POST['action'];
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    $mysqli->begin_transaction(); // Start transaction for safety

    try {
        // --- ACTION: CREATE ---
        if ($action === 'create') {
            $title = isset($_POST['title']) ? e($_POST['title']) : '';
            $content = isset($_POST['content']) ? $_POST['content'] : '';
            $is_published = isset($_POST['is_published']) ? intval($_POST['is_published']) : 0;
            
            if (empty($title)) {
                throw new Exception("กรุณาระบุหัวข้อข่าว");
            }

            $image_path = handle_post_upload('cover_image', 'posts/covers'); // Use 'posts/covers' subfolder
            
            $stmt = $mysqli->prepare("INSERT INTO posts (title, content, cover_image_url, is_published, author_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssii", $title, $content, $image_path, $is_published, $author_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Database error: " . $stmt->error);
            }
            $new_post_id = $mysqli->insert_id;
            $stmt->close();
            
            // Handle Gallery Upload on Create
            $gallery_uploads = handle_gallery_uploads('gallery_images', $new_post_id, 1);
            if (!empty($gallery_uploads)) {
                $stmt_gallery = $mysqli->prepare("INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)");
                foreach ($gallery_uploads as $img) {
                    $stmt_gallery->bind_param("isi", $new_post_id, $img['path'], $img['order']);
                    $stmt_gallery->execute();
                }
                $stmt_gallery->close();
            }
            
            $_SESSION['update_success'] = "สร้างข่าวสารใหม่สำเร็จ";
            // Redirect to the edit page of the new post
            $mysqli->commit();
            header('Location: ../admin/edit_post.php?id=' . $new_post_id);
            exit;
        }
        
        // --- ACTION: UPDATE ---
        if ($action === 'update') {
            if ($post_id === 0) throw new Exception("Invalid Post ID.");

            $title = isset($_POST['title']) ? e($_POST['title']) : '';
            $content = isset($_POST['content']) ? $_POST['content'] : '';
            $is_published = isset($_POST['is_published']) ? intval($_POST['is_published']) : 0;
            
            $image_path = handle_post_upload('cover_image', 'posts/covers');
            
            if ($image_path !== null) {
                // New cover image uploaded, delete old one
                $stmt_old = $mysqli->prepare("SELECT cover_image_url FROM posts WHERE id = ?");
                $stmt_old->bind_param("i", $post_id);
                $stmt_old->execute();
                if ($old = $stmt_old->get_result()->fetch_assoc()) {
                    if (!empty($old['cover_image_url']) && file_exists('../' . $old['cover_image_url'])) {
                        @unlink('../' . $old['cover_image_url']);
                    }
                }
                $stmt_old->close();
                
                $stmt = $mysqli->prepare("UPDATE posts SET title = ?, content = ?, is_published = ?, cover_image_url = ? WHERE id = ?");
                $stmt->bind_param("ssisi", $title, $content, $is_published, $image_path, $post_id);
            } else {
                // Update without changing cover image
                $stmt = $mysqli->prepare("UPDATE posts SET title = ?, content = ?, is_published = ? WHERE id = ?");
                $stmt->bind_param("ssii", $title, $content, $is_published, $post_id);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Database error: " . $stmt->error);
            }
            $stmt->close();

            // Handle Gallery Deletion
            $images_to_delete = isset($_POST['delete_images']) ? $_POST['delete_images'] : [];
            if (!empty($images_to_delete)) {
                $delete_ids_safe = array_map('intval', $images_to_delete);
                $placeholders = implode(',', array_fill(0, count($delete_ids_safe), '?'));
                
                $stmt_select_paths = $mysqli->prepare("SELECT image_url FROM post_images WHERE id IN ($placeholders) AND post_id = ?");
                $types_del = str_repeat('i', count($delete_ids_safe)) . 'i';
                $params_del = array_merge($delete_ids_safe, [$post_id]);
                $stmt_select_paths->bind_param($types_del, ...$params_del);
                $stmt_select_paths->execute();
                $paths_to_delete = $stmt_select_paths->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_select_paths->close();

                $stmt_delete_img = $mysqli->prepare("DELETE FROM post_images WHERE id IN ($placeholders) AND post_id = ?");
                $stmt_delete_img->bind_param($types_del, ...$params_del);
                $stmt_delete_img->execute();
                $stmt_delete_img->close();

                foreach ($paths_to_delete as $path_row) {
                    if (file_exists('../' . $path_row['image_url'])) {
                        @unlink('../' . $path_row['image_url']);
                    }
                }
            }

            // Handle Gallery Upload on Update
            // Get current max sort order
            $max_sort_res = $mysqli->query("SELECT COALESCE(MAX(sort_order), 0) as max_order FROM post_images WHERE post_id = $post_id");
            $sort_order_start = $max_sort_res->fetch_assoc()['max_order'];
            
            $gallery_uploads = handle_gallery_uploads('gallery_images', $post_id, $sort_order_start + 1);

            if (!empty($gallery_uploads)) {
                $stmt_gallery = $mysqli->prepare("INSERT INTO post_images (post_id, image_url, sort_order) VALUES (?, ?, ?)");
                foreach ($gallery_uploads as $img) {
                    $stmt_gallery->bind_param("isi", $post_id, $img['path'], $img['order']);
                    $stmt_gallery->execute();
                }
                $stmt_gallery->close();
            }
            
            // Handle Gallery Re-ordering
            $image_order = isset($_POST['image_order']) ? $_POST['image_order'] : [];
            if (!empty($image_order)) {
                $stmt_order = $mysqli->prepare("UPDATE post_images SET sort_order = ? WHERE id = ? AND post_id = ?");
                foreach ($image_order as $index => $image_id) {
                    $new_order = $index + 1;
                    $safe_image_id = intval($image_id);
                    $stmt_order->bind_param("iii", $new_order, $safe_image_id, $post_id);
                    $stmt_order->execute();
                }
                $stmt_order->close();
            }

            $_SESSION['update_success'] = "อัปเดตข่าวสารสำเร็จ";
        }

        // --- ACTION: DELETE ---
        if ($action === 'delete') {
            if ($post_id === 0) throw new Exception("Invalid Post ID.");

            // Get all images (cover and gallery) before deleting DB record
            $stmt_old = $mysqli->prepare("SELECT cover_image_url FROM posts WHERE id = ?");
            $stmt_old->bind_param("i", $post_id);
            $stmt_old->execute();
            $cover_image_url = $stmt_old->get_result()->fetch_assoc()['cover_image_url'] ?? null;
            $stmt_old->close();
            
            $img_stmt = $mysqli->prepare("SELECT image_url FROM post_images WHERE post_id = ?");
            $img_stmt->bind_param("i", $post_id);
            $img_stmt->execute();
            $gallery_images = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $img_stmt->close();

            // Delete from DB (cascading delete should handle post_images, but we do it explicitly just in case)
            $stmt_del_gallery = $mysqli->prepare("DELETE FROM post_images WHERE post_id = ?");
            $stmt_del_gallery->bind_param("i", $post_id);
            $stmt_del_gallery->execute();
            $stmt_del_gallery->close();

            $stmt_del_post = $mysqli->prepare("DELETE FROM posts WHERE id = ?");
            $stmt_del_post->bind_param("i", $post_id);
            
            if ($stmt_del_post->execute()) {
                // Delete files from server
                if ($cover_image_url && file_exists('../' . $cover_image_url)) {
                    @unlink('../' . $cover_image_url);
                }
                foreach ($gallery_images as $img) {
                    if (file_exists('../' . $img['image_url'])) {
                        @unlink('../' . $img['image_url']);
                    }
                }
                $_SESSION['update_success'] = "ลบข่าวสารสำเร็จ";
            } else {
                throw new Exception("Database error: " . $stmt_del_post->error);
            }
            $stmt_del_post->close();
        }
        
        $mysqli->commit(); // Commit transaction if all good
    
    } catch (Exception $e) {
        $mysqli->rollback(); // Rollback on error
        $_SESSION['update_error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
    
    // Redirect back
    if ($action === 'update') {
        header('Location: ../admin/edit_post.php?id=' . $post_id);
    } else {
        header('Location: ../admin/manage_posts.php');
    }
    exit;

} else {
    header('Location: ../admin/index.php');
    exit;
}
?>