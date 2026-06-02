<?php
// Bật Output Buffering để chống lỗi trang trắng (Headers already sent) khi chuyển hướng/lưu dữ liệu
ob_start();

// Khởi động session nếu chưa có để kiểm tra trạng thái đăng nhập
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once 'header_app.php'; ?>
    <title>Quản lý Casing</title>
    <style>
        /* Reset CSS cơ bản để các trình duyệt hiển thị giống nhau */
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            background-color: #f0f2f5; /* Màu nền xám nhạt của trang */
        }

        /* 1. Dòng thông báo màu xám trên cùng */
        .top-info-bar {
            background-color: #f1f3f4;
            color: #5f6368;
            font-size: 14px;
            padding: 8px 15px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
        }

        /* Icon ứng dụng nhỏ ở đầu dòng */
        .app-icon {
            margin-right: 10px;
            color: #5f6368;
        }

        /* Phần link màu xanh trong dòng thông báo */
        .top-info-bar a {
            color: #1a73e8;
            text-decoration: none;
            margin: 0 3px;
        }
        .top-info-bar a:hover {
            text-decoration: underline;
        }

        /* TÙY BIẾN CHO USER BAR ĐẸP MẮT TRÊN THANH XANH */
        .header-content-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* 2. Thanh tiêu đề chính màu xanh lá */
        .main-header {
            background-color: #28a745; /* Màu xanh lá cây */
            color: white;
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Khối hiển thị thông tin User đăng nhập */
        .header-user-bar {
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 12px;
            border-radius: 20px;
        }
        
        .header-user-bar a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            background: #d93025;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            transition: background 0.2s;
        }

        .header-user-bar a.login-link {
            background: #1a73e8;
        }

        .header-user-bar a:hover {
            opacity: 0.9;
        }

        /* 3. Khu vực khẩu hiệu và nút Admin (bên dưới thanh xanh) */
        .sub-header-container {
            display: flex;
            justify-content: center; /* Căn giữa toàn bộ cụm này */
            align-items: center;
            padding: 10px 20px;
            background-color: white; /* Nền trắng cho khu vực này */
            position: relative; 
        }

        /* Câu khẩu hiệu màu đỏ */
        .slogan {
            color: #d93025; /* Màu đỏ */
            font-weight: bold;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0; /* Bỏ margin mặc định của p */
        }

        /* Nút Thoát Admin */
        .admin-btn {
            background-color: #e06666; /* Màu đỏ nhạt của nút */
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none; /* Nếu là thẻ a */
            display: flex;
            align-items: center;
            gap: 5px;
            position: absolute; /* Định vị cố định sang bên phải */
            right: 20px;
        }
        
        .admin-btn:hover {
            background-color: #cc0000; /* Màu khi di chuột qua */
        }

        /* Phần nội dung chính (main) sẽ bắt đầu sau header */
        main {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto; /* Căn giữa nội dung chính */
        }
    </style>
</head>
<body>

    <header class="main-header">
        <div class="header-content-wrapper">
            <a href="index.php" style="text-decoration: none; color: white; display: flex; align-items: center; gap: 10px; font-size: 24px; font-weight: bold;">
                <span style="font-size: 26px;">🚢</span> E/R CASING
            </a>

            <div class="header-user-bar">
                <?php if (isset($_SESSION['username']) && !empty($_SESSION['username'])): ?>
                    <span>👋 Chào, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                    <a href="logout.php">Đăng xuất</a>
                <?php else: ?>
                    <span>❌ Bạn chưa đăng nhập</span>
                    <a href="login.php" class="login-link">Đăng nhập</a>
                <?php endif; ?>
            </div>
        </div>

        <?php 
        // Giữ nguyên file share.php nếu có logic riêng bên trong
        include 'share.php'; 
        ?>
    </header>

    <?php
        // Danh sách 5 dòng chữ chạy ngẫu nhiên
        $messages = [
            "ĐỪNG CHỜ ĐỢI SỰ MAY MẮN, HÃY NỔ LỰC ĐỂ AN TOÀN!",
            "HÃY TRỞ VỀ NHÀ AN TOÀN NHƯ LÚC CHÚNG TA ĐI LÀM!",
            "HÃY CHÚ Ý CẨN THẬN TRƯỚC KHI LÀM VIỆC, KHÔNG AI BẢO VỆ BẠN TỐT HƠN CHÍNH BẠN!",
            "ĐỪNG CHỜ ĐỢI SỰ MAY MẮN, HÃY NỔ LỰC ĐỂ AN TOÀN!",
            "HÃY CHÚ Ý CẨN THẬN TRƯỚC KHI LÀM VIỆC, KHÔNG AI BẢO VỆ BẠN TỐT HƠN CHÍNH BẠN!"
        ];
        
        // Lấy ngẫu nhiên 1 trong 5 dòng
        $random_msg = $messages[array_rand($messages)];
    ?>
    
    <div style="background: #fff; border-bottom: 1px solid #eee;">
        <marquee behavior="scroll" direction="left" style="color: red; font-weight: bold; padding: 5px 0;">
            <?php echo $random_msg; ?>
        </marquee>
    </div>
