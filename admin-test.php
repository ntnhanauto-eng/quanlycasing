<?php
// Bật Output Buffering để chống lỗi trang trắng (Headers already sent) khi chuyển hướng/lưu dữ liệu
ob_start();
// Khởi động session nếu chưa có để kiểm tra trạng thái đăng nhập

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Đồng bộ biến kiểm tra đăng nhập giống hệt index.php của bạn
$header_is_logged_in = isset($_SESSION['user']);
$header_display_name = $header_is_logged_in ? ($_SESSION['fullname'] ?? $_SESSION['user']) : '';
?>


<div class="header-bottom-bar">
    <div class="header-bottom-wrapper">
        <div class="header-user-bar">
            <?php if ($header_is_logged_in): ?>
                <span>👋 Chào, <strong><?= htmlspecialchars($header_display_name); ?></strong></span>
                <a href="logout.php">Đăng xuất</a>
            <?php else: ?>
                <span>❌ Bạn chưa đăng nhập</span>
                <a href="login.php" class="login-link">Đăng nhập</a>
            <?php endif; ?>
        </div>
    </div>
</div>
