<?php
session_start();
require_once 'db_connect.php';

// Kiểm tra quyền truy cập: Chỉ admin mới có quyền vào kho ảnh và upload
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
if (!$is_admin) {
    die("Bạn không có quyền truy cập vào chức năng này. Chỉ dành cho Admin.");
}

// Cấu hình thư mục lưu trữ ảnh và file text trên host
$upload_dir = 'uploads/';
$text_file_path = $upload_dir . 'shared_clipboard.txt'; // Đường dẫn file lưu text chia sẻ

// Tự động tạo thư mục nếu chưa tồn tại
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$success_message = "";
$error_message = "";

// Hàm hỗ trợ đổi kích thước file từ Byte sang định dạng dễ đọc (KB, MB)
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// ==========================================
// XỬ LÝ CÁC HÀNH ĐỘNG POST (UPLOAD, DELETE, SHARE TEXT)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. XỬ LÝ CHIA SẺ VĂN BẢN (Ghi trực tiếp vào file .txt công cộng)
    if ($_POST['action'] === 'share_text') {
        $shared_content = isset($_POST['shared_content']) ? $_POST['shared_content'] : ''; // Giữ nguyên xuống dòng
        
        // Ghi nội dung vào file tĩnh (Tự động đè nội dung cũ)
        if (file_put_contents($text_file_path, $shared_content) !== false) {
            $success_message = "Đã lưu và đồng bộ văn bản thành công qua file hệ thống!";
        } else {
            $error_message = "Không thể ghi dữ liệu vào file. Hãy kiểm tra quyền (Chmod) của thư mục uploads.";
        }
    }

    // 2. XỬ LÝ UPLOAD HÌNH ẢNH
    if ($_POST['action'] === 'upload_image') {
        if (isset($_FILES['image']) && is_array($_FILES['image']['name'])) {
            $file_count = count($_FILES['image']['name']);
            
            if ($file_count === 1 && $_FILES['image']['error'][0] === UPLOAD_ERR_NO_FILE) {
                $error_message = "Vui lòng chọn ít nhất một file ảnh hợp lệ.";
            } elseif ($file_count > 5) {
                $error_message = "Bạn chỉ được phép tải lên tối đa 5 hình ảnh cùng một lúc.";
            } else {
                $success_count = 0;
                $errors = [];
                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['image']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_tmp = $_FILES['image']['tmp_name'][$i];
                        $original_name = basename($_FILES['image']['name'][$i]);
                        
                        $clean_name = preg_replace("/[^A-Za-z0-9\.\-_]/", "_", $original_name);
                        $file_ext = strtolower(pathinfo($clean_name, PATHINFO_EXTENSION));
                        $file_base = pathinfo($clean_name, PATHINFO_FILENAME);
                        
                        $new_file_name = $file_base . '_' . time() . '_' . $i . '.' . $file_ext;
                        $target_file = $upload_dir . $new_file_name;

                        if (in_array($file_ext, $allowed_types)) {
                            if (move_uploaded_file($file_tmp, $target_file)) {
                                $success_count++;
                            } else {
                                $errors[] = "Không thể lưu tệp: " . htmlspecialchars($original_name);
                            }
                        } else {
                            $errors[] = "Định dạng không hợp lệ (Chỉ nhận JPG, JPEG, PNG, GIF, WEBP): " . htmlspecialchars($original_name);
                        }
                    } else {
                        if ($_FILES['image']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                            $errors[] = "Lỗi khi upload tệp số " . ($i + 1) . " (Mã lỗi: " . $_FILES['image']['error'][$i] . ")";
                        }
                    }
                }

                if ($success_count > 0) {
                    $success_message = "Đã tải lên thành công $success_count hình ảnh!";
                }
                if (!empty($errors)) {
                    $error_message = implode("<br>", $errors);
                }
            }
        } else {
            $error_message = "Dữ liệu tải lên không hợp lệ hoặc vượt quá cấu hình tối đa của hệ thống.";
        }
    }

    // 3. XỬ LÝ XÓA HÌNH ẢNH
    if ($_POST['action'] === 'delete_image') {
        $file_to_delete = $_POST['file_name'];
        $target_delete = $upload_dir . basename($file_to_delete);

        if (file_exists($target_delete)) {
            if (unlink($target_delete)) {
                $success_message = "Đã xóa hình ảnh thành công!";
            } else {
                $error_message = "Không thể xóa file khỏi hệ thống.";
            }
        } else {
            $error_message = "File không tồn tại trên hệ thống.";
        }
    }
}

// ==========================================
// ĐỌC DỮ LIỆU ĐỂ HIỂN THỊ TRANG
// ==========================================

// Lấy nội dung văn bản đang được chia sẻ từ file tĩnh (.txt)
$current_shared_text = "";
if (file_exists($text_file_path)) {
    $current_shared_text = file_get_contents($text_file_path);
}

// LẤY DANH SÁCH FILE VÀ THỐNG KÊ KHO ẢNH (Bỏ qua file txt chia sẻ)
$images = [];
$total_bytes = 0;

if (is_dir($upload_dir)) {
    $files = scandir($upload_dir);
    foreach ($files as $file) {
        $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_types)) {
            $file_path = $upload_dir . $file;
            if (file_exists($file_path)) {
                $file_size = filesize($file_path);
                $total_bytes += $file_size;
                
                $images[] = [
                    'name' => $file,
                    'path' => $file_path,
                    'size' => formatFileSize($file_size),
                    'time' => filemtime($file_path)
                ];
            }
        }
    }
    usort($images, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

$total_count = count($images);
$total_mb = number_format($total_bytes / 1048576, 2);

include 'header.php';
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Kho Lưu Trữ Hình Ảnh Admin</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f0f2f5; padding: 15px; margin: 0; }
        .store-container { max-width: 1000px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .store-header { border-bottom: 2px solid #6c757d; padding-bottom: 10px; margin-bottom: 20px; }
        .msg-success { background-color: #d1e7dd; color: #0f5132; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .msg-error { background-color: #f8d7da; color: #842029; padding: 12px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        
        .stats-bar { display: flex; gap: 20px; margin-bottom: 20px; }
        .stats-item { flex: 1; background: #f8f9fa; border: 1px solid #e5e7eb; padding: 15px; border-radius: 8px; display: flex; align-items: center; gap: 15px; }
        .stats-icon { font-size: 28px; }
        .stats-info h4 { margin: 0; color: #4b5563; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stats-info p { margin: 5px 0 0 0; font-size: 20px; font-weight: bold; color: #1f2937; }

        .upload-form, .text-share-form { background-color: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; border-radius: 8px; margin-bottom: 25px; }
        .form-row { display: flex; flex-direction: column; gap: 15px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 13px; font-weight: bold; margin-bottom: 5px; color: #495057; }
        input[type="file"], textarea { padding: 10px; background: #fff; border: 1px solid #ced4da; border-radius: 5px; font-size: 14px; }
        textarea { resize: vertical; min-height: 120px; font-family: monospace; font-size: 14px; line-height: 1.5; }
        
        .btn-group { display: flex; gap: 10px; }
        .btn-submit { background-color: #4b5563; color: white; border: none; padding: 12px 20px; font-weight: bold; border-radius: 5px; cursor: pointer; transition: 0.2s; width: fit-content; }
        .btn-submit:hover { background-color: #374151; }
        .btn-copy { background-color: #10b981; color: white; border: none; padding: 12px 20px; font-weight: bold; border-radius: 5px; cursor: pointer; transition: 0.2s; }
        .btn-copy:hover { background-color: #059669; }
        
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; margin-top: 15px; }
        .image-item { background: #fdfdfd; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: space-between; }
        .image-wrapper { width: 100%; height: 130px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #f3f4f6; border-radius: 6px; margin-bottom: 8px; }
        .image-wrapper img { max-width: 100%; max-height: 100%; object-fit: contain; }
        
        .image-info { text-align: left; margin-bottom: 10px; background: #f8f9fa; padding: 6px; border-radius: 4px; border: 1px solid #f1f5f9; }
        .image-name { font-size: 11px; color: #1f2937; font-weight: bold; word-break: break-all; line-height: 1.3; height: 30px; overflow: hidden; margin-bottom: 4px; }
        .image-meta { font-size: 10px; color: #6b7280; display: flex; flex-direction: column; gap: 2px; }
        
        .btn-del { background-color: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; width: 100%; }
        .btn-del:hover { background-color: #dc2626; }
    </style>
</head>
<body>

<div class="store-container">
    <div class="store-header">
        <h2>🖼️ KHO LƯU TRỮ & CHIA SẺ DỮ LIỆU ADMIN</h2>
        <p style="color: #6b7280; margin: 0; font-size: 14px;">Đồng bộ nhanh hình ảnh và văn bản tạm thời giữa Máy tính & Điện thoại.</p>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="msg-success"><?= $success_message; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="msg-error"><?= $error_message; ?></div>
    <?php endif; ?>

    <div class="text-share-form">
        <form method="POST">
            <input type="hidden" name="action" value="share_text">
            <div class="form-row">
                <div class="form-group">
                    <label>📋 BỘ NHỚ TẠM CHUNG (Nhập text từ thiết bị này -> Click Lưu -> Mở thiết bị kia để Copy)</label>
                    <textarea name="shared_content" id="sharedContent" placeholder="Dán đoạn văn bản hoặc liên kết cần chia sẻ vào đây..."><?= htmlspecialchars($current_shared_text); ?></textarea>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn-submit">💾 LƯU VÀ CHIA SẺ</button>
                    <button type="button" class="btn-copy" onclick="copyToClipboard()">✂️ COPY NHANH TEXT</button>
                </div>
            </div>
        </form>
    </div>

    <div class="stats-bar">
        <div class="stats-item">
            <span class="stats-icon">📸</span>
            <div class="stats-info">
                <h4>Tổng số hình ảnh</h4>
                <p><?= $total_count; ?> ảnh</p>
            </div>
        </div>
        <div class="stats-item">
            <span class="stats-icon">💾</span>
            <div class="stats-info">
                <h4>Tổng dung lượng lưu trữ</h4>
                <p><?= $total_mb; ?> MB</p>
            </div>
        </div>
    </div>

    <div class="upload-form">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="action" value="upload_image">
            <div class="form-row">
                <div class="form-group">
                    <label>Chọn hình ảnh từ thiết bị (Tối đa 5 ảnh. Định dạng: JPG, PNG, GIF, WEBP)</label>
                    <input type="file" name="image[]" id="imageInput" accept="image/*" multiple required>
                </div>
                <button type="submit" class="btn-submit">TẢI ẢNH LÊN HỆ THỐNG</button>
            </div>
        </form>
    </div>

    <h3>Danh sách hình ảnh trong kho</h3>
    
    <?php if ($total_count > 0): ?>
        <div class="image-grid">
            <?php foreach ($images as $img): ?>
                <div class="image-item">
                    <div class="image-wrapper">
                        <a href="<?= $img['path']; ?>" target="_blank" title="Xem ảnh gốc lớn">
                            <img src="<?= $img['path']; ?>?t=<?= $img['time']; ?>" alt="<?= htmlspecialchars($img['name']); ?>">
                        </a>
                    </div>
                    
                    <div class="image-info">
                        <div class="image-name" title="<?= htmlspecialchars($img['name']); ?>">
                            <?= htmlspecialchars($img['name']); ?>
                        </div>
                        <div class="image-meta">
                            <span>📦 <b>Dung lượng:</b> <?= $img['size']; ?></span>
                            <span>📅 <b>Ngày up:</b> <?= date('d/m/Y H:i', $img['time']); ?></span>
                        </div>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn xóa hình ảnh này vĩnh viễn?');">
                        <input type="hidden" name="action" value="delete_image">
                        <input type="hidden" name="file_name" value="<?= htmlspecialchars($img['name']); ?>">
                        <button type="submit" class="btn-del">Xóa ảnh</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; color: #9ca3af; padding: 40px 0; border: 1px dashed #dee2e6; border-radius: 8px;">
            Chưa có hình ảnh nào trong thư mục lưu trữ.
        </div>
    <?php endif; ?>
</div>

<script>
// Chặn submit file quá số lượng cho phép
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    var files = document.getElementById('imageInput').files;
    if (files.length > 5) {
        alert('Bạn chỉ được chọn tối đa 5 hình ảnh cho mỗi lần tải lên.');
        e.preventDefault();
    }
});

// Hàm hỗ trợ copy nhanh hoạt động mượt mà trên cả PC và điện thoại
function copyToClipboard() {
    var copyText = document.getElementById("sharedContent");
    copyText.select();
    copyText.setSelectionRange(0, 99999); // Dành cho thiết bị di động

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(copyText.value).then(function() {
            alert("Đã sao chép văn bản thành công!");
        }).catch(function() {
            alert("Lỗi sao chép tự động. Hãy bôi đen thủ công.");
        });
    } else {
        try {
            var successful = document.execCommand('copy');
            if(successful) alert("Đã sao chép văn bản!");
            else alert("Trình duyệt không hỗ trợ sao chép tự động.");
        } catch (err) {
            alert("Không thể sao chép tự động.");
        }
    }
}
</script>

</body>
</html>
<?php include 'footer.php'; ?>
