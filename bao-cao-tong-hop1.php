<?php
session_start();
require_once 'db_connect.php';

// --- ĐOẠN CẬP NHẬT: LƯU URL ĐỂ QUAY LẠI SAU KHI ĐĂNG NHẬP / ĐĂNG XUẤT ---
$_SESSION['back_url'] = $_SERVER['REQUEST_URI'];

// --- 0. XỬ LÝ REDIRECT & USER TRANG HIỆN TẠI ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$actual_link = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$is_logged_in = isset($_SESSION['user']) && !empty($_SESSION['user']);
$is_admin = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$display_name = $is_logged_in ? (!empty($_SESSION['fullname']) ? $_SESSION['fullname'] : $_SESSION['user']) : 'Khách';
$user_fullname = $_SESSION['fullname'] ?? '';

// Lấy URL hiện tại cho nút đăng nhập/đăng xuất
$current_url = basename($_SERVER['PHP_SELF']);
if (!empty($_SERVER['QUERY_STRING'])) {
    $current_url .= '?' . $_SERVER['QUERY_STRING'];
}

// CẬP NHẬT SQL: Lấy thêm cột status để phục vụ đổ màu nền tiêu chuẩn cho thẻ option
$ships_list = $conn->query("SELECT id, project_name, ship_type, status FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selected_ship_id = $_GET['ship_id'] ?? '';

// Khởi tạo các biến chứa dữ liệu báo cáo
$ship = null;
$compare_ships_data = [];
$stats = [
    'remain_chua_lam' => 0,
    'remain_dang_lam' => 0,
    'remain_da_xong' => 0,
    'comment_chua_lam' => 0,
    'comment_dang_lam' => 0,
    'comment_da_xong' => 0,
    'revise_chua_lam' => 0,
    'revise_dang_lam' => 0,
    'revise_da_xong' => 0,
];

if ($selected_ship_id) {
    // A. Lấy thông tin cơ bản và tổng hợp giờ công của tàu được chọn
    $ship_stmt = $conn->prepare("
        SELECT s.*, 
            (SELECT COALESCE(SUM(dr.worker_count), 0) * 8 FROM daily_reports dr WHERE dr.ship_id = s.id) as actual_hours,
            (SELECT MIN(dr2.report_date) FROM daily_reports dr2 WHERE dr2.ship_id = s.id AND dr2.job_content LIKE '%(EVENT)%') as start_date,
            sp.progress_percent
        FROM ships s
        LEFT JOIN ship_progress sp ON s.id = sp.ship_id
        WHERE s.id = ?
    ");
    $ship_stmt->execute([$selected_ship_id]);
    $ship = $ship_stmt->fetch(PDO::FETCH_ASSOC);

    if ($ship) {
        $current_name = $ship['project_name'];
        $current_type = $ship['ship_type'];

        // 1. Thuật toán tách chuỗi lấy ký hiệu đứng trước dấu gạch dưới (Ví dụ: EC_50K -> lấy EC)
        $current_prefix = '';
        if (strpos($current_name, '_') !== false) {
            $parts = explode('_', $current_name);
            $current_prefix = trim($parts[0]);
        } else {
            $current_prefix = substr($current_name, 0, 2);
        }

        // 2. Thuật toán tự động tìm từ khóa tải trọng cốt lõi (50K, 115K,...) trong ship_type
        $type_keyword = '';
        if (preg_match('/(50K|115K)/i', $current_type, $matches)) {
            $type_keyword = $matches[1]; // Trích xuất ra chính xác chuỗi "50K" hoặc "115K"
        } else {
            // Trường hợp loại tàu đặc thù khác, lấy 3 ký tự đầu tiên để làm từ khóa tìm kiếm tương đối
            $type_keyword = substr(trim($current_type), 0, 3);
        }

        // B. TRUY VẤN ĐỒ THỊ: Lọc so sánh tương đối theo từ khóa tải trọng (bất kể hậu tố phía sau là gì)
        if (!empty($type_keyword)) {
            $compare_sql = "
                SELECT s.id, s.project_name, s.man_hours, s.ship_type,
                    (SELECT COALESCE(SUM(dr.worker_count), 0) * 8 FROM daily_reports dr WHERE dr.ship_id = s.id) as actual_hours
                FROM ships s
                WHERE s.ship_type LIKE ? AND s.project_name LIKE ?
                ORDER BY s.project_name ASC
            ";
            $type_stmt = $conn->prepare($compare_sql);
            // Tìm kiếm dạng %50K% hoặc %115K% để bốc toàn bộ tàu cùng phân khúc tải trọng
            $type_stmt->execute(['%' . $type_keyword . '%', $current_prefix . '%']);
            $compare_ships_data = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // C. Thống kê trạng thái hạng mục tồn đọng (Remain Jobs)
        $remain_stmt = $conn->prepare("SELECT status, COUNT(*) as qty FROM remain_jobs WHERE ship_id = ? GROUP BY status");
        $remain_stmt->execute([$selected_ship_id]);
        while ($row = $remain_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'Chưa làm') $stats['remain_chua_lam'] = $row['qty'];
            if ($row['status'] == 'Đang làm') $stats['remain_dang_lam'] = $row['qty'];
            if ($row['status'] == 'Đã xong') $stats['remain_da_xong'] = $row['qty'];
        }

        // D. Thống kê trạng thái hạng mục sửa đổi (Comments)
        $comment_stmt = $conn->prepare("SELECT status, COUNT(*) as qty FROM ship_comments WHERE ship_id = ? GROUP BY status");
        $comment_stmt->execute([$selected_ship_id]);
        while ($row = $comment_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'Chưa làm') $stats['comment_chua_lam'] = $row['qty'];
            if ($row['status'] == 'Đang làm') $stats['comment_dang_lam'] = $row['qty'];
            if ($row['status'] == 'Đã xong') $stats['comment_da_xong'] = $row['qty'];
        }

        // E. Thống kê trạng thái hạng mục hiệu chỉnh (Revises)
        $revise_stmt = $conn->prepare("SELECT status, COUNT(*) as qty FROM ship_revises WHERE ship_id = ? GROUP BY status");
        $revise_stmt->execute([$selected_ship_id]);
        while ($row = $revise_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'Chưa làm') $stats['revise_chua_lam'] = $row['qty'];
            if ($row['status'] == 'Đang làm') $stats['revise_dang_lam'] = $row['qty'];
            if ($row['status'] == 'Đã xong') $stats['revise_da_xong'] = $row['qty'];
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    /* --- CSS HỆ THỐNG ĐÃ ĐƯỢC TỐI ƯU HIỆN ĐẠI (UI/UX) --- */
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #f4f6f9; margin: 0; padding: 10px; }
    .container { max-width: 1600px; margin: auto; background: #ffffff; padding: 20px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); box-sizing: border-box; }
    
    /* Thiết kế lại Thanh lọc dự án */
    .filter-box { background: #ffffff; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 24px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.01), 0 2px 4px -1px rgba(0,0,0,0.01); }
    .filter-box label { color: #4a5568; font-size: 14px; }
    .filter-box select { padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; width: 100%; outline: none; box-sizing: border-box; transition: all 0.2s ease; font-weight: 500; color: #1e293b; }
    .filter-box select:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
    
    /* Nâng cấp các thẻ thống kê - Stat Cards */
    .dashboard-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 24px; }
    .stat-card { background: #ffffff; padding: 16px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02); display: flex; align-items: center; gap: 16px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
    .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: white; flex-shrink: 0; }
    .stat-info { display: flex; flex-direction: column; overflow: hidden; }
    .stat-label { font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .stat-value { font-size: 18px; font-weight: 700; color: #0f172a; }

    .report-layout { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 24px; }
    
    /* Panel chứa biểu đồ và cấu trúc thông tin */
    .chart-panel, .detail-panel { background: #ffffff; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.01); box-sizing: border-box; width: 100% !important; }
    .panel-title { font-size: 14px; font-weight: 700; color: #1e293b; margin-top: 0; margin-bottom: 16px; border-left: 4px solid #10b981; padding-left: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
    
    .chart-scroll-wrapper { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .chart-container { position: relative; height: 320px; width: 100%; min-width: 520px; }
    
    /* Thiết kế lại Thanh Tiến độ có hiệu ứng chuyển động sinh động */
    .progress-section { background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 16px; text-align: center; }
    .progress-num { font-size: 36px; font-weight: 800; color: #10b981; line-height: 1; font-family: monospace, sans-serif; }
    .progress-bar-bg { background: #e2e8f0; border-radius: 20px; height: 12px; overflow: hidden; margin-top: 10px; position: relative; }
    .progress-bar-fill { 
        background: linear-gradient(90deg, #10b981, #059669); 
        height: 100%; 
        border-radius: 20px; 
        background-size: 1rem 1rem;
        background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent);
        animation: progress-bar-stripes 1s linear infinite;
    }
    @keyframes progress-bar-stripes {
        from { background-position: 1rem 0; }
        to { background-position: 0 0; }
    }

    /* Thiết kế lại Bảng biểu cao cấp */
    .table-wrapper { width: 100% !important; overflow-x: auto !important; border: 1px solid #e2e8f0 !important; border-radius: 12px !important; background: #ffffff !important; box-sizing: border-box !important; }
    .table-spec { width: 100% !important; min-width: 650px !important; border-collapse: collapse !important; font-size: 13px !important; table-layout: fixed !important; }
    
    .table-spec th:nth-child(1), .table-spec td:nth-child(1) { width: 42% !important; text-align: left !important; padding-left: 16px !important; }
    .table-spec th:nth-child(2), .table-spec td:nth-child(2) { width: 14% !important; text-align: center !important; }
    .table-spec th:nth-child(3), .table-spec td:nth-child(3) { width: 14% !important; text-align: center !important; }
    .table-spec th:nth-child(4), .table-spec td:nth-child(4) { width: 14% !important; text-align: center !important; }
    .table-spec th:nth-child(5), .table-spec td:nth-child(5) { width: 14% !important; text-align: center !important; }

    .table-spec th { background: #f8fafc !important; color: #475569 !important; font-weight: 700 !important; padding: 14px 8px !important; border-bottom: 2px solid #e2e8f0 !important; white-space: nowrap !important; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
    .table-spec td { padding: 14px 8px !important; border-bottom: 1px solid #f1f5f9 !important; white-space: nowrap !important; color: #334155; }
    .table-spec tbody tr:hover td { background-color: #f8fafc !important; color: #0f172a !important; }

    /* Quy chuẩn Badges phẳng hiện đại */
    .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; color: white; display: inline-block; min-width: 24px; text-align: center; }
    .bg-danger { background-color: #ef4444; }
    .bg-warning { background-color: #f59e0b; color: #ffffff !important; }
    .bg-success { background-color: #10b981; }
    .bg-info { background-color: #06b6d4; }
    .bg-secondary { background-color: #64748b; }
    .bg-dark { background-color: #1e293b; }

    @media (min-width: 600px) {
        body { padding: 20px; }
        .container { padding: 24px; border-radius: 16px; }
        .filter-box { flex-direction: row; padding: 16px; align-items: center; gap: 16px; }
        .filter-box select { width: auto; min-width: 320px; }
        .dashboard-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .stat-card { padding: 20px; gap: 16px; }
        .stat-icon { width: 54px; height: 54px; font-size: 20px; border-radius: 12px; }
        .stat-value { font-size: 22px; }
        .chart-container { min-width: 100%; height: 350px; }
        
        .table-spec { min-width: 100% !important; table-layout: auto !important; }
        .table-spec th, .table-spec td { width: auto !important; padding: 14px 12px !important; }
        .table-spec th:nth-child(1), .table-spec td:nth-child(1) { padding-left: 20px !important; }
    }

    @media (min-width: 1100px) {
        .report-layout { grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px; }
        .chart-panel, .detail-panel { padding: 24px; }
    }
</style>

<div class="container">
    <div class="filter-box">
        <label for="ship_select"><b><i class="fa-solid fa-ship"></i> Chọn dự án tàu phân tích:</b></label>
        <?php
        // Tính toán màu nền ban đầu cho thẻ select tổng dựa trên tàu đang chọn
        $select_bg = '#ffffff';
        foreach ($ships_list as $s) {
            if ($selected_ship_id == $s['id']) {
                if ($s['status'] === 'Chưa thi công') $select_bg = '#fef2f2'; // Đỏ nhạt hiện đại
                elseif ($s['status'] === 'Đang thi công') $select_bg = '#fef3c7'; // Vàng nhạt hiện đại
                elseif ($s['status'] === 'Đã bàn giao') $select_bg = '#ecfdf5'; // Xanh nhạt hiện đại
                break;
            }
        }
        ?>
        <select id="ship_select" onchange="this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor; location.href='?ship_id='+this.value" style="background-color: <?= $select_bg ?>;">
            <option value="" style="background-color: #fff;">-- Chọn dự án tàu dữ liệu --</option>
            <?php foreach ($ships_list as $s): 
                $opt_bg = '#ffffff';
                if ($s['status'] === 'Chưa thi công') {
                    $opt_bg = '#fef2f2'; 
                } elseif ($s['status'] === 'Đang thi công') {
                    $opt_bg = '#fef3c7'; 
                } elseif ($s['status'] === 'Đã bàn giao') {
                    $opt_bg = '#ecfdf5'; 
                }
            ?>
                <option value="<?= $s['id'] ?>" <?= ($selected_ship_id == $s['id']) ? 'selected' : '' ?> style="background-color: <?= $opt_bg ?>;">
                    <?= htmlspecialchars($s['project_name']) ?> (Loại: <?= htmlspecialchars($s['ship_type'] ?? 'N/A') ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($ship): 
        $mh_pp = (float)$ship['man_hours'];
        $mh_act = (float)$ship['actual_hours'];
        $efficiency = ($mh_act > 0) ? round(($mh_pp / $mh_act) * 100, 1) : 0;
        $pre_execution = $mh_act - ((($ship['progress_percent'] ?? 0) / 100) * $mh_pp);
    ?>
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #3b82f6;"><i class="fa-solid fa-clock"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Kế hoạch (PP)</span>
                    <span class="stat-value"><?= number_format($mh_pp) ?> h</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #ec4899;"><i class="fa-solid fa-business-time"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Thực tế (TT)</span>
                    <span class="stat-value"><?= number_format($mh_act) ?> h</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #8b5cf6;"><i class="fa-solid fa-chart-line"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Pre-execution</span>
                    <span class="stat-value"><?= number_format($pre_execution, 1) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: <?= ($efficiency > 100) ? '#ef4444' : '#10b981'; ?>;"><i class="fa-solid fa-gauge-high"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Chỉ số Hiệu quả</span>
                    <span class="stat-value"><?= $efficiency ?>%</span>
                </div>
            </div>
        </div>

        <div class="report-layout">
            <div class="chart-panel">
                <h3 class="panel-title"><i class="fa-solid fa-chart-bar"></i> Biểu đồ sê-ri nhóm tải trọng tương đối (Từ khóa: <?= htmlspecialchars($type_keyword) ?>)</h3>
                <?php if (count($compare_ships_data) > 1): ?>
                    <div class="chart-scroll-wrapper">
                        <div class="chart-container">
                            <canvas id="seriesCompareChart"></canvas>
                        </div>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #94a3b8; padding-top: 100px; font-size: 13px;">Không tìm thấy tàu khác có cùng phân khúc từ khóa sê-ri để vẽ biểu đồ so sánh.</p>
                <?php endif; ?>
            </div>

            <div class="detail-panel">
                <h3 class="panel-title"><i class="fa-solid fa-spinner"></i> Tiến độ & Thông tin - <?= htmlspecialchars($ship['project_name']) ?></h3>
                <div class="progress-section">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">Tiến độ thực tế</span>
                    <div class="progress-num"><?= (int)($ship['progress_percent'] ?? 0) ?>%</div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= (int)($ship['progress_percent'] ?? 0) ?>%"></div>
                    </div>
                </div>
                <div class="table-wrapper" style="border: none;">
                    <table class="table-spec" style="min-width: 100%; table-layout: auto;">
                        <tr><td><b>Quyền PIC phụ trách:</b></td><td><span class="badge bg-info"><i class="fa-solid fa-user-gear"></i> <?= htmlspecialchars($ship['pic']) ?></span></td></tr>
                        <tr><td><b>Phân nhóm tổ đội:</b></td><td><span class="badge bg-secondary"><?= htmlspecialchars($ship['group_name']) ?></span></td></tr>
                        <tr><td><b>Phân loại tàu:</b></td><td><span class="badge bg-dark"><?= htmlspecialchars($ship['ship_type']) ?></span></td></tr>
                        <tr><td><b>Nhà máy (Fac):</b></td><td><?= htmlspecialchars($ship['fac'] ?? 'N/A') ?></td></tr>
                        <tr><td><b>Trạng thái tàu:</b></td><td><?= htmlspecialchars($ship['status']) ?></td></tr>
                        <tr><td><b>Ngày Event bắt đầu:</b></td><td><i class="fa-solid fa-calendar-day"></i> <?= $ship['start_date'] ? htmlspecialchars($ship['start_date']) : '---' ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="detail-panel" style="margin-bottom: 15px;">
            <h3 class="panel-title"><i class="fa-solid fa-list-check"></i> Bảng tổng hợp chi tiết trạng thái các đầu mục nghiệp vụ</h3>
            <div class="table-wrapper">
                <table class="table-spec">
                    <thead>
                        <tr>
                            <th>HẠNG MỤC NGHIỆP VỤ</th>
                            <th style="text-align: center;">TỔNG SỐ</th>
                            <th style="text-align: center;">CHƯA LÀM</th>
                            <th style="text-align: center;">ĐANG LÀM</th>
                            <th style="text-align: center;">ĐÃ XONG</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><b>🚧 Công việc tồn động (Remain Jobs)</b></td>
                            <td style="text-align: center;"><span class="badge bg-secondary" style="background:#475569;"><?= array_sum([$stats['remain_chua_lam'], $stats['remain_dang_lam'], $stats['remain_da_xong']]) ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['remain_chua_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['remain_dang_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-success"><?= $stats['remain_da_xong'] ?></span></td>
                        </tr>
                        <tr>
                            <td><b>💬 Quản lý ý kiến (Comments)</b></td>
                            <td style="text-align: center;"><span class="badge bg-secondary" style="background:#475569;"><?= array_sum([$stats['comment_chua_lam'], $stats['comment_dang_lam'], $stats['comment_da_xong']]) ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['comment_chua_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['comment_dang_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-success"><?= $stats['comment_da_xong'] ?></span></td>
                        </tr>
                        <tr>
                            <td><b>🔄 Hiệu chỉnh cấu trúc (Revises)</b></td>
                            <td style="text-align: center;"><span class="badge bg-secondary" style="background:#475569;"><?= array_sum([$stats['revise_chua_lam'], $stats['revise_dang_lam'], $stats['revise_da_xong']]) ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['revise_chua_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['revise_dang_lam'] ?></span></td>
                            <td style="text-align: center;"><span class="badge bg-success"><?= $stats['revise_da_xong'] ?></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (count($compare_ships_data) > 1): ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                var ctx = document.getElementById('seriesCompareChart').getContext('2d');
                var compareChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_column($compare_ships_data, 'project_name')); ?>,
                        datasets: [
                            {
                                label: 'Giờ thực tế (h)',
                                data: <?php echo json_encode(array_map('floatval', array_column($compare_ships_data, 'actual_hours'))); ?>,
                                backgroundColor: 'rgba(236, 72, 153, 0.75)',
                                borderColor: 'rgba(236, 72, 153, 1)',
                                borderWidth: 1,
                                borderRadius: 6, // Làm bo góc cột biểu đồ mượt mà
                                yAxisID: 'y_hours',
                                order: 2
                            },
                            {
                                label: 'Hiệu quả (%)',
                                data: <?php 
                                    $eff_data = [];
                                    foreach ($compare_ships_data as $c_ship) {
                                        $eff_data[] = ((float)$c_ship['actual_hours'] > 0) ? round(((float)$c_ship['man_hours'] / (float)$c_ship['actual_hours']) * 100, 1) : 0;
                                    }
                                    echo json_encode($eff_data);
                                ?>,
                                type: 'line',
                                borderColor: '#10b981',
                                backgroundColor: '#10b981',
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                fill: false,
                                tension: 0.3, // Đường cong line mượt mà hơn
                                yAxisID: 'y_percent',
                                order: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            y_hours: {
                                type: 'linear',
                                position: 'left',
                                title: { display: true, text: 'Giờ công thực tế (Giờ)', font: { size: 11, weight: 'bold' } },
                                grid: { color: '#f1f5f9' }
                            },
                            y_percent: {
                                type: 'linear',
                                position: 'right',
                                title: { display: true, text: 'Hiệu quả (%)', font: { size: 11, weight: 'bold' } },
                                min: 0,
                                grid: { drawOnChartArea: false }
                            }
                        },
                        plugins: {
                            legend: { labels: { font: { size: 11, weight: '500' } } }
                        }
                    }
                });
            });
        </script>
        <?php endif; ?>

    <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; color: #94a3b8; border: 2px dashed #cbd5e1; border-radius: 16px; background: #fafafa;">
            <i class="fa-solid fa-chart-pie" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px;"></i>
            <p style="font-size: 15px; font-weight: 600; margin: 0; line-height: 1.4; color: #64748b;">Vui lòng lựa chọn một Dự án Tàu ở menu phía trên để xuất dữ liệu báo cáo tổng hợp hệ thống.</p>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
