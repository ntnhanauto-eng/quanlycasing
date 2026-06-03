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
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once 'header_app.php'; ?>
    <title>Quản lý Casing</title>
    <style>
        /* Reset CSS cơ bản */
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
        }

        /* TẦNG 1: Thanh tiêu đề chính màu xanh lá */
        .main-header {
            background-color: #28a745;
            color: white;
            padding: 15px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header-top-row {
            display: flex;
            justify-content: center; /* Căn giữa thương hiệu */
            align-items: center;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
        }

        .brand-logo {
            text-decoration: none; 
            color: white; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-size: 24px; 
            font-weight: bold;
        }

        /* TẦNG 2: Thanh phụ chứa lời chào User đặt dưới cùng header */
        .header-bottom-bar {
            background-color: #1e7e34; /* Màu xanh lá đậm hơn để phân tách tầng rõ rệt */
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 8px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .header-bottom-wrapper {
            display: flex;
            justify-content: center; /* ĐÃ SỬA: Đổi từ flex-end sang center để canh giữa */
            align-items: center;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
        }

        /* Cấu trúc khối chữ lời chào */
        .header-user-bar {
            font-size: 14px; /* Tăng nhẹ kích thước cho dễ đọc */
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap; /* TỰ ĐỘNG XUỐNG HÀNG TRÊN DI ĐỘNG - CHỐNG VỠ LAYOUT */
            text-align: center;
        }
        
        .header-user-bar strong {
            color: #ffffff;
        }
        
        /* Nút đăng nhập / đăng xuất */
        .header-user-bar a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            background: #ef4444; /* Màu đỏ nút logout */
            padding: 6px 14px; /* Tăng nhẹ padding cho cân đối */
            border-radius: 6px;
            font-size: 13px;
            transition: background 0.2s;
            white-space: nowrap; /* Giữ chữ trên nút không bị bẻ đôi */
        }

        .header-user-bar a.login-link {
            background: #2563eb; /* Màu xanh nút login */
        }

        .header-user-bar a:hover {
            opacity: 0.9;
        }

        /* Phần nội dung chính (main) */
        main {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
    </style>
</head>
<body>

    <header>
        <div class="main-header">
            <div class="header-top-row">
                <a href="index.php" class="brand-logo">
                    <span style="font-size: 26px;">🚢</span>
                    E/R CASING
                </a>
                
                <?php 
                // Giữ nguyên file share.php nếu bạn đang dùng để chèn cấu trúc phụ
                if (file_exists('share.php')) { include 'share.php'; } 
                ?>
            </div>
        </div>

        <div class="header-bottom-bar">
            <div class="header-bottom-wrapper">
                <div class="header-user-bar">
                    <?php if ($header_is_logged_in): ?>
                        <span> Chào, <strong><?= htmlspecialchars($header_display_name); ?></strong></span>
                        <a href="logout.php">Đăng xuất</a>
                    <?php else: ?>
                        <span> Bạn chưa đăng nhập</span>
                        <a href="login.php" class="login-link">Đăng nhập</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <?php
        $messages = [
            "ĐỪNG CHỜ ĐỢI SỰ MAY MẮN, HÃY NỔ LỰC ĐỂ AN TOÀN!",
            "HÃY TRỞ VỀ NHÀ AN TOÀN NHƯ LÚC CHÚNG TA ĐI LÀM!",
            "HÃY CHÚ Ý CẨN THẬN TRƯỚC KHI LÀM VIỆC, KHÔNG AI BẢO VỆ BẠN TỐT HƠN CHÍNH BẠN!"
        ];
        $random_msg = $messages[array_rand($messages)];
    ?>
    <div style="background: #fff; border-bottom: 1px solid #eee;">
        <marquee behavior="scroll" direction="left" style="color: red; font-weight: bold; padding: 6px 0; margin: 0;">
            <?= $random_msg; ?>
        </marquee>
    </div>
