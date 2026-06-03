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
            align-items: center;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
            
            /* GIẢI PHÁP CHỐNG SIDEBAR CHE: 
               Nếu Sidebar cố định chiếm chỗ (ví dụ: 250px), bạn hãy mở dòng margin-left dưới đây.
               Nếu Sidebar dạng menu trượt đè lên, padding-left này sẽ đẩy thẳng logo vào vùng an toàn. */
            /* padding-left: 260px; */ 
        }

        /* KHỐI CHỨA CẢ LOGO VÀ SHARE NẰM GIỮA VÙNG CÒN LẠI */
        .logo-share-group {
            margin: 0 auto; /* Cực kỳ quan trọng: Luôn tự đẩy khối này vào CHÍNH GIỮA vùng hiển thị sạch */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px; /* Khoảng cách trực tiếp giữa Logo và Nút Share đứng sau */
            white-space: nowrap; /* Ép toàn bộ cụm này bắt buộc nằm trên một hàng ngang */
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

        /* KHỐI CHỨA NÚT CHIA SẺ ĐỨNG SAU LOGO */
        .header-share-wrapper {
            display: inline-flex;
            align-items: center;
        }

        /* TẦNG 2: Thanh phụ chứa lời chào User đặt dưới cùng header */
        .header-bottom-bar {
            background-color: #1e7e34; 
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 8px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .header-bottom-wrapper {
            display: flex;
            justify-content: center; 
            align-items: center;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
            /* Nếu thanh dưới cũng bị sidebar đè, hãy bật bệ đỡ padding này tương tự tầng 1 */
            /* padding-left: 260px; */
        }

        /* Cấu trúc khối chữ lời chào */
        .header-user-bar {
            font-size: 14px; 
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap; 
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
            background: #ef4444; 
            padding: 6px 14px; 
            border-radius: 6px;
            font-size: 13px;
            transition: background 0.2s;
            white-space: nowrap; 
        }

        .header-user-bar a.login-link {
            background: #2563eb; 
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

        /* Hỗ trợ Responsive khi Sidebar thu nhỏ gọn trên Mobile */
        @media (max-width: 768px) {
            .header-top-row, .header-bottom-wrapper {
                padding-left: 20px !important; /* Hủy bỏ khoảng bù của Sidebar khi về Mobile */
            }
            .brand-logo {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

    <header>
        <div class="main-header">
            <div class="header-top-row">
                
                <div class="logo-share-group">
                    
                    <a href="index.php" class="brand-logo">
                        <span style="font-size: 26px;">🚢</span>
                        E/R CASING
                    </a>
                    
                    <div class="header-share-wrapper">
                        <?php 
                        if (file_exists('share.php')) { 
                            include 'share.php'; 
                        } 
                        ?>
                    </div>

                </div>

            </div>
        </div>

        <div class="header-bottom-bar">
            <div class="header-bottom-wrapper">
                <div class="header-user-bar">
                    <?php 
                    // Lấy chính xác đường dẫn hiện tại của trình duyệt (bao gồm cả các tham số lọc tìm kiếm)
                    $current_page_url = urlencode($_SERVER['REQUEST_URI']);
                    ?>
                    
                    <?php if ($header_is_logged_in): ?>
                        <span> Chào, <strong><?= htmlspecialchars($header_display_name); ?></strong></span>
                        <a href="logout.php?from=<?= $current_page_url; ?>">Đăng xuất</a>
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
