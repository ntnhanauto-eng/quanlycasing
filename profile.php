<?php
session_start();
require_once 'db_connect.php';

// --- 1. KIỂM TRA ĐĂNG NHẬP ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=profile.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";

// Lấy thông báo từ Session nếu có (Cơ chế Flash Message giúp tránh lỗi F5 trùng lặp dữ liệu)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// --- 2. XỬ LÝ CẬP NHẬT THÔNG TIN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);

    // Kiểm tra tính hợp lệ của Email
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert error'><i class='fas fa-exclamation-triangle'></i> Định dạng email không hợp lệ!</div>";
    } else {
        try {
            $sql = "UPDATE users SET fullname = ?, email = ?, phone = ?, address = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt->execute([$fullname, $email, $phone, $address, $user_id])) {
                $_SESSION['fullname'] = $fullname; // Cập nhật lại tên hiển thị trên hệ thống toàn cục
                
                // Lưu thông báo thành công vào session và chuyển hướng để tránh trùng lặp khi F5
                $_SESSION['flash_message'] = "<div class='alert success'><i class='fas fa-check-circle'></i> Cập nhật hồ sơ thành công!</div>";
                header("Location: profile.php");
                exit();
            }
        } catch (PDOException $e) {
            $message = "<div class='alert error'><i class='fas fa-times-circle'></i> Lỗi hệ thống: " . $e->getMessage() . "</div>";
        }
    }
}

// --- 3. LẤY THÔNG TIN USER ---
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Xử lý ngày đăng ký thành viên
$registration_date = "Chưa cập nhật";
if (!empty($user['created_at'])) {
    $registration_date = date('d/m/Y', strtotime($user['created_at']));
}

// Xử lý hiển thị trạng thái Phân công nhiệm vụ (Control section)
$control_status = "Không control";
if (isset($user['is_control_section']) && $user['is_control_section'] == 1) {
    $control_status = "Control section";
}

// Câu chào theo thời gian thực
date_default_timezone_set('Asia/Ho_Chi_Minh');
$hour = date('H');
if ($hour < 12) $greet = "Chào buổi sáng";
elseif ($hour < 18) $greet = "Chào buổi chiều";
else $greet = "Chào buổi tối";

// Nhúng header và sidebar (Thanh lời chào user-bar tầng 2 từ header.php vẫn sẽ xuất hiện bình thường)
include 'header.php';
include 'sidebar.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    :root { --primary: #27ae60; --dark: #2c3e50; --light: #f4f7f6; }
    
    .profile-container { max-width: 900px; margin: 30px auto; padding: 0 20px; }
    .profile-card { background: white; border-radius: 15px; overflow: hidden; border: 1px solid #e1e4e8; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
    
    /* Banner & Avatar */
    .profile-banner { height: 130px; background: linear-gradient(135deg, var(--primary), #11998e); position: relative; }
    .avatar-box { position: absolute; bottom: -40px; left: 40px; width: 100px; height: 100px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; font-weight: bold; color: var(--primary); border: 4px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

    .profile-body { padding: 60px 40px 40px 40px; }

    /* Thanh điều hướng phụ */
    .top-nav-bar { display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 15px 20px; border-radius: 10px; margin-bottom: 30px; border-left: 5px solid var(--primary); }
    .top-nav-bar h3 { margin: 0; font-size: 16px; color: var(--dark); }
    .nav-actions a { text-decoration: none; font-size: 13px; font-weight: 600; margin-left: 15px; transition: 0.2s; }
    .link-home { color: var(--dark); }
    .link-home:hover { color: var(--primary); }
    .link-logout { color: #e74c3c; }
    .link-logout:hover { color: #c0392b; }

    /* Form */
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .form-group { display: flex; flex-direction: column; margin-bottom: 15px; }
    .form-group label { font-size: 11px; font-weight: bold; color: #888; text-transform: uppercase; margin-bottom: 5px; }
    .form-control { padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background: #fdfdfd; }
    .form-control:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(39,174,96,0.1); }
    .form-control[readonly] { background: #f1f3f5; color: #6c757d; cursor: not-allowed; }

    .btn-save { background: var(--primary); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; margin-top: 10px; }
    .btn-save:hover { background: #219150; transform: translateY(-1px); }

    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
    .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

    @media (max-width: 600px) { .info-grid { grid-template-columns: 1fr; } .top-nav-bar { flex-direction: column; gap: 10px; text-align: center; } }
</style>

<div class="profile-container">
    <div class="profile-card">
        <div class="profile-banner">
            <div class="avatar-box">
                <?= mb_substr($user['fullname'], 0, 1, 'utf-8') ?>
            </div>
        </div>

        <div class="profile-body">
            <div class="top-nav-bar">
                <h3><?= $greet ?>, <b><?= htmlspecialchars($user['fullname']) ?></b> 👋</h3>
                <div class="nav-actions">
                    <a href="index.php" class="link-home"><i class="fas fa-home"></i> Về trang chính</a>
                    <a href="logout.php" class="link-logout" onclick="return confirm('Bạn có chắc chắn muốn đăng xuất?')">
                        <i class="fas fa-sign-out-alt"></i> Đăng xuất
                    </a>
                </div>
            </div>

            <?= $message ?>

            <form method="POST">
                <div class="info-grid">
                    <div class="form-group">
                        <label>Tên đăng nhập (ID)</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Quyền hạn tài khoản</label>
                        <input type="text" class="form-control" value="<?= strtoupper($user['role']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Chức vụ</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['position'] ?? 'ENG') ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Phân công nhiệm vụ</label>
                        <input type="text" class="form-control" value="<?= $control_status ?>" readonly style="font-weight: bold; color: <?= $user['is_control_section'] == 1 ? 'var(--primary)' : '#6c757d' ?>;">
                    </div>
                    <div class="form-group">
                        <label>Họ và Tên</label>
                        <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email liên hệ</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="Chưa cập nhật email">
                    </div>
                    <div class="form-group">
                        <label>Số điện thoại</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="Chưa cập nhật SĐT">
                    </div>
                    <div class="form-group">
                        <label>Địa chỉ công tác</label>
                        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address'] ?? '') ?>" placeholder="Phòng ban / Đơn vị">
                    </div>
                    <div class="form-group">
                        <label>Thành viên từ ngày</label>
                        <input type="text" class="form-control" value="<?= $registration_date ?>" readonly>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="fas fa-save"></i> LƯU THÔNG TIN
                    </button>
                </div>
            </form>

            <div style="margin-top: 30px; text-align: center;">
                <a href="doi-mat-khau.php" style="color: #888; font-size: 13px; text-decoration: none;">
                    <i class="fas fa-lock"></i> Bạn muốn thay đổi mật khẩu?
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
