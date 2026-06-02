<?php
session_start();
require_once 'db_connect.php';

// --- 0. KIỂM TRA ĐĂNG NHẬP ---
$is_logged_in = isset($_SESSION['user']) && !empty($_SESSION['user']);
$is_admin = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$user_fullname = $_SESSION['fullname'] ?? '';

// --- 1. LẤY DANH SÁCH TÀU ĐỂ LỌC ---
$ships_list = $conn->query("SELECT id, project_name, ship_type FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selected_ship_id = $_GET['ship_id'] ?? '';

// Khởi tạo các biến chứa dữ liệu báo cáo
$ship = null;
$compare_ships_data = [];
$stats = [
    'inspection_count' => 0, // Số mục kiểm tra
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
        $current_type = $ship['ship_type']; // Loại tàu (Ví dụ: 50K, 115K...)

        // Thuật toán tách chuỗi lấy ký hiệu đứng trước dấu gạch dưới (Ví dụ: EC_50K -> lấy EC)
        $current_prefix = '';
        if (strpos($current_name, '_') !== false) {
            $parts = explode('_', $current_name);
            $current_prefix = trim($parts[0]);
        } else {
            // Nếu tên tàu không có dấu gạch dưới, lấy 2 ký tự đầu tiên làm tiền tố mặc định
            $current_prefix = substr($current_name, 0, 2);
        }

        // B. TRUY VẤN ĐỒ THỊ: Lọc các tàu thỏa cả 2 tiêu chí (Cùng tiền tố tên tàu VÀ Cùng loại tải trọng tàu)
        if (!empty($current_type)) {
            $compare_sql = "
                SELECT s.id, s.project_name, s.man_hours,
                    (SELECT COALESCE(SUM(dr.worker_count), 0) * 8 FROM daily_reports dr WHERE dr.ship_id = s.id) as actual_hours
                FROM ships s
                WHERE s.ship_type = ? AND s.project_name LIKE ?
                ORDER BY s.project_name ASC
            ";
            $type_stmt = $conn->prepare($compare_sql);
            // Ký tự % ở sau đại diện cho việc tìm kiếm chuỗi bắt đầu bằng tiền tố vừa tách
            $type_stmt->execute([$current_type, $current_prefix . '%']);
            $compare_ships_data = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // C. Lấy tổng số lượng Hạng mục kiểm tra (bảng inspection_items)
        $insp_stmt = $conn->prepare("SELECT item_count FROM inspection_items WHERE ship_id = ?");
        $insp_stmt->execute([$selected_ship_id]);
        $stats['inspection_count'] = (int)($insp_stmt->fetchColumn() ?: 0);

        // D. Thống kê trạng thái hạng mục tồn đọng (Remain Jobs)
        $remain_stmt = $conn->prepare("SELECT status, COUNT(*) as qty FROM remain_jobs WHERE ship_id = ? GROUP BY status");
        $remain_stmt->execute([$selected_ship_id]);
        while ($row = $remain_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'Chưa làm') $stats['remain_chua_lam'] = $row['qty'];
            if ($row['status'] == 'Đang làm') $stats['remain_dang_lam'] = $row['qty'];
            if ($row['status'] == 'Đã xong') $stats['remain_da_xong'] = $row['qty'];
        }

        // E. Thống kê trạng thái hạng mục sửa đổi (Comments)
        $comment_stmt = $conn->prepare("SELECT status, COUNT(*) as qty FROM ship_comments WHERE ship_id = ? GROUP BY status");
        $comment_stmt->execute([$selected_ship_id]);
        while ($row = $comment_stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'Chưa làm') $stats['comment_chua_lam'] = $row['qty'];
            if ($row['status'] == 'Đang làm') $stats['comment_dang_lam'] = $row['qty'];
            if ($row['status'] == 'Đã xong') $stats['comment_da_xong'] = $row['qty'];
        }

        // F. Thống kê trạng thái hạng mục hiệu chỉnh (Revises)
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
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 1500px; margin: auto; background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); box-sizing: border-box; }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 25px; padding-bottom: 10px; text-align: center; }
    .header-section h2 { margin: 0; color: #212529; font-size: 24px; text-transform: uppercase; letter-spacing: 0.5px; }
    
    .filter-box { background: #f8f9fa; padding: 15px; border-radius: 10px; border: 1px solid #dee2e6; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
    .filter-box select { padding: 10px 15px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; min-width: 280px; outline: none; }
    
    .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #eef2f5; box-shadow: 0 4px 10px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 15px; }
    .stat-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; }
    .stat-info { display: flex; flex-direction: column; }
    .stat-label { font-size: 12px; color: #6c757d; font-weight: bold; text-transform: uppercase; }
    .stat-value { font-size: 20px; font-weight: 800; color: #212529; margin-top: 2px; }

    .report-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-bottom: 30px; }
    .chart-panel { background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eef2f5; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .chart-container { position: relative; height: 320px; width: 100%; }
    .panel-title { font-size: 16px; font-weight: bold; color: #2c3e50; margin-top: 0; margin-bottom: 20px; border-left: 4px solid #28a745; padding-left: 10px; text-transform: uppercase; }
    
    .detail-panel { background: #fff; padding: 20px; border-radius: 15px; border: 1px solid #eef2f5; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .progress-section { background: #f8f9fa; padding: 15px; border-radius: 10px; border: 1px solid #ced4da; margin-bottom: 15px; text-align: center; }
    .progress-num { font-size: 40px; font-weight: 900; color: #28a745; line-height: 1; }
    .progress-bar-bg { background: #e9ecef; border-radius: 20px; height: 12px; overflow: hidden; margin-top: 10px; }
    .progress-bar-fill { background: linear-gradient(90deg, #28a745, #20c997); height: 100%; border-radius: 20px; }

    .table-spec { width: 100%; border-collapse: collapse; font-size: 13px; }
    .table-spec th { background: #f1f3f5; color: #495057; font-weight: bold; padding: 10px; text-align: left; border-bottom: 2px solid #dee2e6; }
    .table-spec td { padding: 10px; border-bottom: 1px solid #eceff1; }
    .badge { padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; color: white; display: inline-block; }
    .bg-danger { background-color: #dc3545; }
    .bg-warning { background-color: #ffc107; color: #212529 !important; }
    .bg-success { background-color: #28a745; }
    .bg-info { background-color: #17a2b8; }
    .bg-secondary { background-color: #6c757d; }

    @media (max-width: 1100px) {
        .report-layout { grid-template-columns: 1fr; }
    }
</style>

<div class="container">
    <div class="header-section">
        <h2>📊 BÁO CÁO TỔNG HỢP & PHÂN TÍCH DỰ ÁN</h2>
    </div>

    <div class="filter-box">
        <label for="ship_select"><b><i class="fa-solid fa-ship"></i> Chọn dự án tàu phân tích:</b></label>
        <select id="ship_select" onchange="location.href='?ship_id='+this.value">
            <option value="">-- Chọn dự án tàu dữ liệu --</option>
            <?php foreach ($ships_list as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($selected_ship_id == $s['id']) ? 'selected' : '' ?>>
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
                <div class="stat-icon" style="background: #2563eb;"><i class="fa-solid fa-clock"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Giờ công kế hoạch (PP)</span>
                    <span class="stat-value"><?= number_format($mh_pp) ?> h</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #d63384;"><i class="fa-solid fa-business-time"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Giờ công thực tế (TT)</span>
                    <span class="stat-value"><?= number_format($mh_act) ?> h</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: #6610f2;"><i class="fa-solid fa-chart-line"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Pre-execution</span>
                    <span class="stat-value"><?= number_format($pre_execution, 1) ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: <?= ($efficiency > 100) ? '#dc3545' : '#198754'; ?>;"><i class="fa-solid fa-gauge-high"></i></div>
                <div class="stat-info">
                    <span class="stat-label">Chỉ số Hiệu quả</span>
                    <span class="stat-value"><?= $efficiency ?>%</span>
                </div>
            </div>
        </div>

        <div class="report-layout">
            <div class="chart-panel">
                <h3 class="panel-title"><i class="fa-solid fa-chart-bar"></i> Biểu đồ sê-ri đồng nhóm dữ liệu (Ký hiệu: <?= htmlspecialchars($current_prefix) ?>_ | Phân loại: <?= htmlspecialchars($ship['ship_type']) ?>)</h3>
                <?php if (count($compare_ships_data) > 1): ?>
                    <div class="chart-container">
                        <canvas id="seriesCompareChart"></canvas>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #888; padding-top: 100px;">Không tìm thấy tàu khác có cùng ký hiệu tiền tố và phân loại tải trọng để vẽ biểu đồ so sánh.</p>
                <?php endif; ?>
            </div>

            <div class="detail-panel">
                <h3 class="panel-title"><i class="fa-solid fa-spinner"></i> Tiến độ & Thông tin</h3>
                <div class="progress-section">
                    <span style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 5px; text-transform: uppercase;">Tiến độ thực tế</span>
                    <div class="progress-num"><?= (int)($ship['progress_percent'] ?? 0) ?>%</div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= (int)($ship['progress_percent'] ?? 0) ?>%"></div>
                    </div>
                </div>
                <table class="table-spec">
                    <tr><td><b>Quyền PIC phụ trách:</b></td><td><span class="badge bg-info"><i class="fa-solid fa-user-gear"></i> <?= htmlspecialchars($ship['pic']) ?></span></td></tr>
                    <tr><td><b>Phân nhóm tổ đội:</b></td><td><span class="badge bg-secondary"><?= htmlspecialchars($ship['group_name']) ?></span></td></tr>
                    
                    <tr><td><b>Phân loại tàu:</b></td><td><span class="badge bg-dark"><?= htmlspecialchars($ship['ship_type']) ?></span></td></tr>
                    
                    <tr><td><b>Nhà máy (Fac):</b></td><td><?= htmlspecialchars($ship['fac'] ?? 'N/A') ?></td></tr>
                    <tr><td><b>Trạng thái tàu:</b></td><td><?= htmlspecialchars($ship['status']) ?></td></tr>
                    <tr><td><b>Ngày Event bắt đầu:</b></td><td><i class="fa-solid fa-calendar-day"></i> <?= $ship['start_date'] ? htmlspecialchars($ship['start_date']) : '---' ?></td></tr>
                </table>
            </div>
        </div>

        <div class="detail-panel" style="margin-bottom: 15px;">
            <h3 class="panel-title"><i class="fa-solid fa-list-check"></i> Bảng tổng hợp chi tiết trạng thái các đầu mục nghiệp vụ</h3>
            <table class="table-spec" style="min-width: 100%;">
                <thead>
                    <tr>
                        <th>HẠNG MỤC NGHIỆP VỤ</th>
                        <th style="text-align: center;">TỔNG SỐ LƯỢNG</th>
                        <th style="text-align: center;">CHƯA LÀM / THIẾT LẬP</th>
                        <th style="text-align: center;">ĐANG TRIỂN KHAI</th>
                        <th style="text-align: center;">ĐÃ HOÀN THÀNH</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><b>📋 Hạng mục kiểm tra (Inspection Items)</b></td>
                        <td style="text-align: center;"><span class="badge bg-info"><?= $stats['inspection_count'] ?></span></td>
                        <td style="text-align: center; color: #999;">---</td>
                        <td style="text-align: center; color: #999;">---</td>
                        <td style="text-align: center; color: #999;">---</td>
                    </tr>
                    <tr>
                        <td><b>🚧 Công việc tồn động (Remain Jobs)</b></td>
                        <td style="text-align: center; font-weight: bold;"><?= array_sum([$stats['remain_chua_lam'], $stats['remain_dang_lam'], $stats['remain_da_xong']]) ?></td>
                        <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['remain_chua_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['remain_dang_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-success"><?= $stats['remain_da_xong'] ?></span></td>
                    </tr>
                    <tr>
                        <td><b>💬 Quản lý ý kiến (Comments)</b></td>
                        <td style="text-align: center; font-weight: bold;"><?= array_sum([$stats['comment_chua_lam'], $stats['comment_dang_lam'], $stats['comment_da_xong']]) ?></td>
                        <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['comment_chua_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['comment_dang_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-success"><?= $stats['comment_da_xong'] ?></span></td>
                    </tr>
                    <tr>
                        <td><b>🔄 Hiệu chỉnh cấu trúc (Revises)</b></td>
                        <td style="text-align: center; font-weight: bold;"><?= array_sum([$stats['revise_chua_lam'], $stats['revise_dang_lam'], $stats['revise_da_xong']]) ?></td>
                        <td style="text-align: center;"><span class="badge bg-danger"><?= $stats['revise_chua_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-warning"><?= $stats['revise_dang_lam'] ?></span></td>
                        <td style="text-align: center;"><span class="badge bg-success"><?= $stats['revise_da_xong'] ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if (count($compare_ships_data) > 1): 
            $labels = []; $actual_hours_dataset = []; $efficiency_dataset = [];
            foreach ($compare_ships_data as $c_ship) {
                $labels[] = $c_ship['project_name'];
                $actual_hours_dataset[] = (float)$c_ship['actual_hours'];
                
                $eff_val = ((float)$c_ship['actual_hours'] > 0) ? round(((float)$c_ship['man_hours'] / (float)$c_ship['actual_hours']) * 100, 1) : 0;
                $efficiency_dataset[] = $eff_val;
            }
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                var ctx = document.getElementById('seriesCompareChart').getContext('2d');
                var compareChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($labels); ?>,
                        datasets: [
                            {
                                label: 'Giờ công thực tế (h)',
                                data: <?php echo json_encode($actual_hours_dataset); ?>,
                                backgroundColor: 'rgba(214, 51, 132, 0.75)',
                                borderColor: 'rgba(214, 51, 132, 1)',
                                borderWidth: 1,
                                yAxisID: 'y_hours',
                                order: 2
                            },
                            {
                                label: 'Hiệu quả (%)',
                                data: <?php echo json_encode($efficiency_dataset); ?>,
                                type: 'line',
                                borderColor: '#198754',
                                backgroundColor: '#198754',
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                fill: false,
                                tension: 0.2,
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
                                title: { display: true, text: 'Giờ công thực tế (Giờ)' }
                            },
                            y_percent: {
                                type: 'linear',
                                position: 'right',
                                title: { display: true, text: 'Hiệu quả (%)' },
                                min: 0,
                                grid: { drawOnChartArea: false }
                            }
                        }
                    }
                });
            });
        </script>
        <?php endif; ?>

    <?php else: ?>
        <div style="text-align: center; padding: 60px; color: #7f8c8d; border: 2px dashed #bdc3c7; border-radius: 12px; background: #fafafa;">
            <i class="fa-solid fa-chart-pie" style="font-size: 45px; color: #bdc3c7; margin-bottom: 15px;"></i>
            <p style="font-size: 15px; font-weight: bold; margin: 0;">Vui lòng lựa chọn một Dự án Tàu ở menu phía trên để xuất dữ liệu báo cáo tổng hợp hệ thống.</p>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
