<?php
session_start();
require_once 'db_connect.php';

// Lưu URL hiện tại để quay lại sau khi login/logout
$current_url = $_SERVER['REQUEST_URI'];
$_SESSION['back_url'] = $current_url; // Lưu vào session để trang login sử dụng điều hướng ngược lại

// Kiểm tra quyền Admin
$is_admin = (isset($_SESSION['user']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$user_fullname = $_SESSION['fullname'] ?? '';

// Xác định tên hiển thị
$display_name = 'Khách';
if (isset($_SESSION['fullname']) && !empty($_SESSION['fullname'])) {
    $display_name = $_SESSION['fullname'];
} elseif (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    $display_name = $_SESSION['user'];
}

// Lấy các tham số lọc để tái sử dụng
$query_params = [
    'page' => $_GET['page'] ?? 1,
    'ship_id' => $_GET['ship_id'] ?? '',
    'from_date' => $_GET['from_date'] ?? '',
    'to_date' => $_GET['to_date'] ?? '',
    'job_keyword' => $_GET['job_keyword'] ?? '',
    'group_name' => $_GET['group_name'] ?? '',
    'report_type' => $_GET['report_type'] ?? '',
    'order_by' => $_GET['order_by'] ?? 'DESC'
];
$query_string = http_build_query($query_params);

// Hàm kiểm tra quyền sở hữu tàu (PIC)
function can_modify_ship($conn, $ship_id, $is_admin, $user_fullname) {
    if ($is_admin) return true;
    if (empty($user_fullname) || empty($ship_id)) return false;
    $stmt = $conn->prepare("SELECT pic FROM ships WHERE id = ?");
    $stmt->execute([$ship_id]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($ship && $ship['pic'] === $user_fullname);
}

// --- 1. XỬ LÝ DỮ LIỆU ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $target_ship_id = $_POST['ship_id'] ?? null;
    
    // Nếu là update/delete, tìm ship_id từ report_id trước
    if (in_array($_POST['action'], ['update', 'delete']) && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM daily_reports WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        $target_ship_id = $report['ship_id'] ?? null;
    }

    // Kiểm tra quyền PIC hoặc Admin
    if (can_modify_ship($conn, $target_ship_id, $is_admin, $user_fullname)) {
        if ($_POST['action'] == 'add') {
            $date = $_POST['report_date'];
            $workers = $_POST['worker_count'];
            $content = $_POST['job_content'];
            if (isset($_POST['report_type']) && $_POST['report_type'] === 'EVENT') { $content .= " (EVENT)"; }

            $sql = "INSERT INTO daily_reports (report_date, ship_id, worker_count, job_content) VALUES (?, ?, ?, ?)";
            $conn->prepare($sql)->execute([$date, $target_ship_id, $workers, $content]);
        }
        elseif ($_POST['action'] == 'update') {
            $id = $_POST['id'];
            $date = $_POST['report_date'];
            $workers = $_POST['worker_count'];
            $content = $_POST['job_content'];
            
            $sql = "UPDATE daily_reports SET report_date = ?, worker_count = ?, job_content = ? WHERE id = ?";
            $conn->prepare($sql)->execute([$date, $workers, $content, $id]);
        }
        elseif ($_POST['action'] == 'delete') {
            $id = $_POST['id'];
            $conn->prepare("DELETE FROM daily_reports WHERE id = ?")->execute([$id]);
        }
    }
    header("Location: du-lieu.php?" . $query_string);
    exit();
}

// --- 2. XỬ LÝ LỌC & TÌM KIẾM ---
$search_ship = $_GET['ship_id'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$search_job = $_GET['job_keyword'] ?? '';
$search_group = $_GET['group_name'] ?? '';
$search_type = $_GET['report_type'] ?? '';
$order_by = $_GET['order_by'] ?? 'DESC';

$where = ["1=1"]; $params = [];
if ($search_ship != '') { $where[] = "dr.ship_id = ?"; $params[] = $search_ship; }
if ($from_date != '') { $where[] = "dr.report_date >= ?"; $params[] = $from_date; }
if ($to_date != '') { $where[] = "dr.report_date <= ?"; $params[] = $to_date; }
if ($search_job != '') { $where[] = "dr.job_content LIKE ?"; $params[] = "%$search_job%"; }
if ($search_group != '') { $where[] = "s.group_name = ?"; $params[] = $search_group; }

// Xử lý lọc theo Loại hình (EVENT / Bình thường)
if ($search_type == 'EVENT') {
    $where[] = "dr.job_content LIKE '%(EVENT)%'";
} elseif ($search_type == 'NORMAL') {
    $where[] = "dr.job_content NOT LIKE '%(EVENT)%'";
}

$where_sql = implode(" AND ", $where);

// --- 3. XỬ LÝ XUẤT EXCEL (CHỈ ADMIN) ---
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    if (!$is_admin) {
        die("Bạn không có quyền thực hiện chức năng này.");
    }
    $sql_export = "SELECT dr.*, s.project_name, s.group_name
                   FROM daily_reports dr
                   JOIN ships s ON dr.ship_id = s.id
                   WHERE $where_sql
                   ORDER BY dr.report_date $order_by";
    $stmt_export = $conn->prepare($sql_export);
    $stmt_export->execute($params);
    $data_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);

    $filename = "Bao-cao-cong-viec-" . date('Ymd-His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=$filename");
    
    echo '<table border="1">';
    echo '<tr><th colspan="6" style="font-size:20px;">BÁO CÁO DỮ LIỆU HÀNG NGÀY</th></tr>';
    echo '<tr style="background:#28a745; color:white;">
            <th>STT</th><th>Tàu Dự Án</th><th>Nhóm</th><th>Ngày</th><th>Số Người</th><th>Nội Dung Công Việc</th>
          </tr>';
    $stt_ex = 1;
    $total_ex = 0;
    foreach ($data_export as $row) {
        echo '<tr>';
        echo '<td>' . $stt_ex++ . '</td>';
        echo '<td>' . $row['project_name'] . '</td>';
        echo '<td>' . $row['group_name'] . '</td>';
        echo '<td>' . $row['report_date'] . '</td>';
        echo '<td>' . $row['worker_count'] . '</td>';
        echo '<td>' . $row['job_content'] . '</td>';
        echo '</tr>';
        $total_ex += $row['worker_count'];
    }
    echo '<tr style="font-weight:bold;"><td colspan="4">TỔNG CỘNG</td><td>' . $total_ex . '</td><td>Người</td></tr>';
    echo '</table>';
    exit();
}

// --- 4. PHÂN TRANG & TÍNH TỔNG CỘNG + THỐNG KÊ SỐ TÀU THEO BỘ LỌC ---
$limit = 13;
$page = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;
$start = ($page - 1) * $limit;

// Thêm tính toán số lượng tàu duy nhất (COUNT(DISTINCT dr.ship_id)) dựa trên bộ lọc
$count_stmt = $conn->prepare("SELECT COUNT(*) as total_rows, 
                                     SUM(dr.worker_count) as total_workers,
                                     COUNT(DISTINCT dr.ship_id) as total_ships
                             FROM daily_reports dr
                             JOIN ships s ON dr.ship_id = s.id
                             WHERE $where_sql");
$count_stmt->execute($params);
$count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);

$total_pages = ceil($count_data['total_rows'] / $limit);
$grand_total_workers = $count_data['total_workers'] ?? 0;
$grand_total_ships = $count_data['total_ships'] ?? 0;

$range = 1;
$initial_num = $page - $range;
$condition_limit_num = ($page + $range) + 1;

// --- 5. TRUY VẤN DỮ LIỆU HIỂN THỊ ---
$sql = "SELECT dr.*, s.project_name, s.group_name, s.pic, sp.progress_percent
        FROM daily_reports dr
        JOIN ships s ON dr.ship_id = s.id
        LEFT JOIN ship_progress sp ON s.id = sp.ship_id
        WHERE $where_sql
        ORDER BY dr.report_date $order_by, dr.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql); $stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ships_filter_list = $conn->query("SELECT id, project_name FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ships_active_list = $conn->query("SELECT id, project_name, status, pic FROM ships
                                   WHERE status IN ('Đang thi công', 'Chưa thi công')
                                   ORDER BY status DESC, project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$groups_list = $conn->query("SELECT DISTINCT group_name FROM ships")->fetchAll(PDO::FETCH_ASSOC);

// Lấy tên tàu phục vụ hiển thị thống kê bộ lọc
$selected_ship_name = 'Tất cả các tàu';
if ($search_ship != '') {
    foreach ($ships_filter_list as $s) {
        if ($s['id'] == $search_ship) {
            $selected_ship_name = $s['project_name'];
            break;
        }
    }
}

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 1400px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 20px; padding-bottom: 10px; }
    .header-section h2 { text-align: center; margin: 0 0 5px 0; color: #333; }
   
    .form-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; flex: 1; }
    .col-date { flex: 0 0 140px; }
    .col-ship { flex: 0 0 200px; }
    .col-worker { flex: 0 0 80px; }
    .col-job { flex: 3 1 300px; }
    .col-type { flex: 0 0 130px; }
    .form-group label { font-size: 11px; font-weight: bold; color: #555; margin-bottom: 3px; text-transform: uppercase; }
    
    /* Giữ nguyên 100% thuộc tính ô nhập liệu nguyên bản của bạn */
    input, select, textarea { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    textarea { font-family: inherit; resize: vertical; min-height: 38px; }
    
    .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: 0.3s; font-size: 12px; }
    .btn-add { background-color: #28a745; }
    .btn-edit { background-color: #ffc107; color: #000; }
    .btn-save { background-color: #28a745; color: white; }
    .btn-del { background-color: #dc3545; }
    .btn-search { background-color: #2c3e50; min-width: 100px; }
    .btn-excel { background-color: #1d6f42; }
    
    /* CSS cho khu vực Thống kê kết quả tìm kiếm */
    .filter-stats-box { background: #e8f5e9; border-left: 5px solid #28a745; padding: 15px 20px; border-radius: 4px 10px 10px 4px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(40,167,69,0.15); }
    .filter-stats-title { font-size: 13px; font-weight: bold; color: #1b5e20; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 5px; }
    .filter-stats-container { display: flex; flex-wrap: wrap; gap: 30px; }
    .filter-stats-item { font-size: 16px; color: #333; font-weight: 500; }
    .filter-stats-item span.label { color: #555; font-size: 14px; font-weight: normal; }
    .filter-stats-item span.value { color: #0d47a1; font-weight: bold; font-size: 16px; }
    .filter-stats-item span.highlight-value { color: #d32f2f; font-weight: bold; font-size: 19px; }
    .filter-stats-item span.hours-value { color: #2e7d32; font-weight: bold; font-size: 19px; }

    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; }
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    th { background-color: #28a745; color: white; padding: 12px; text-align: center; font-weight: bold; font-size: 13px; white-space: nowrap; }
    td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; text-align: center; }
    .row-highlight { background-color: #e2e3e5 !important; }
    .completed-ship { opacity: 0.5; filter: grayscale(12%); }
    tbody tr:nth-child(even) { background-color: #f9f9f9; }
    tbody tr:hover { background-color: #f1f8f1; cursor: pointer; }
    .edit-mode { display: none; }
    .pagination { text-align: center; margin-top: 20px; }
    .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; margin: 0 3px; border-radius: 4px; color: #333; font-size: 13px; }
    .pagination a.active { background: #28a745; color: white; border-color: #28a745; }
    .pagination a.disabled { color: #ccc; cursor: not-allowed; pointer-events: none; }
    
    .job-content-text { white-space: pre-wrap; word-break: break-word; text-align: left; display: block; }
   
    @media (max-width: 768px) {
        .form-group { flex: 1 1 100% !important; }
        .filter-stats-container { flex-direction: column; gap: 10px; }
    }
</style>

<div class="container">
    <div class="header-section">
        <h2>📊 DỮ LIỆU HÀNG NGÀY</h2>
    </div>

    <?php 
    // Chỉ hiện form thêm nếu user đăng nhập và là Admin hoặc phụ trách ít nhất 1 tàu
    $can_add_any = $is_admin;
    if(!$is_admin) {
        foreach($ships_active_list as $s) {
            if($s['pic'] === $user_fullname) { $can_add_any = true; break; }
        }
    }
    ?>

    <?php if ($can_add_any): ?>
    <div class="form-box">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group col-date"><label>Ngày</label><input type="date" name="report_date" required value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group col-ship"><label>Tàu được phân công</label>
                    <select name="ship_id" required onchange="this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor">
                        <option value="" style="background-color: #fff;">-- Chọn tàu --</option>
                        <?php foreach($ships_active_list as $s): 
                            // Thiết lập màu nền theo trạng thái tàu
                            $bg_color = '#fff';
                            if ($s['status'] === 'Chưa thi công') {
                                $bg_color = '#ffe6e6'; // Màu hồng
                            } elseif ($s['status'] === 'Đang thi công') {
                                $bg_color = '#fff3cd'; // Màu vàng
                            }
                        ?>
                            <?php if ($is_admin || $s['pic'] === $user_fullname): ?>
                            <option value="<?php echo $s['id']; ?>" style="background-color: <?php echo $bg_color; ?>;">
                                <?php echo htmlspecialchars($s['project_name']); ?> (<?php echo $s['status']; ?>)
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-worker"><label>Số người</label><input type="number" name="worker_count" required></div>
                
                <div class="form-group col-job">
                    <label>Nội dung công việc</label>
                    <textarea name="job_content" required rows="3" placeholder="Nhập hạng mục công việc thực hiện..."></textarea>
                </div>
                
                <div class="form-group col-type"><label>Loại hình</label>
                    <select name="report_type"><option value="None">Bình thường</option><option value="EVENT">EVENT</option></select>
                </div>
                <button type="submit" class="btn btn-add">LƯU DỮ LIỆU</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="form-box" style="background: #f8f9fa;">
        <form method="GET">
            <div class="form-row">
                <div class="form-group col-ship"><label>Lọc theo tàu</label>
                    <select name="ship_id"><option value="">Tất cả các tàu</option>
                        <?php foreach($ships_filter_list as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo ($search_ship == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['project_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-search">TÌM KIẾM</button>
                
                <?php if ($is_admin): ?>
                <a href="du-lieu.php?<?php echo $query_string; ?>&export=excel" class="btn btn-excel" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">XUẤT EXCEL</a>
                <?php endif; ?>

                <span style="color:#28a745; cursor:pointer; font-size:12px; font-weight:bold;" onclick="document.getElementById('adv').style.display=(document.getElementById('adv').style.display==='none'?'flex':'none')">TÌM KIẾM NÂNG CAO ▼</span>
            </div>
            <div id="adv" class="form-row" style="margin-top:15px; display:none;">
                <div class="form-group col-date"><label>Từ ngày</label><input type="date" name="from_date" value="<?php echo $from_date; ?>"></div>
                <div class="form-group col-date"><label>Đến ngày</label><input type="date" name="to_date" value="<?php echo $to_date; ?>"></div>
                <div class="form-group col-job"><label>Từ khóa</label><input type="text" name="job_keyword" value="<?php echo $search_job; ?>"></div>
                <div class="form-group col-ship"><label>Nhóm</label>
                    <select name="group_name"><option value="">Tất cả nhóm</option>
                        <?php foreach($groups_list as $g): ?><option value="<?php echo $g['group_name']; ?>" <?php echo ($search_group == $g['group_name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['group_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-type"><label>Loại hình</label>
                    <select name="report_type">
                        <option value="">Tất cả loại hình</option>
                        <option value="NORMAL" <?php echo ($search_type == 'NORMAL' ? 'selected' : ''); ?>>Bình thường</option>
                        <option value="EVENT" <?php echo ($search_type == 'EVENT' ? 'selected' : ''); ?>>EVENT</option>
                    </select>
                </div>
                <div class="form-group col-type"><label>Sắp xếp</label>
                    <select name="order_by"><option value="DESC" <?php echo ($order_by=='DESC'?'selected':''); ?>>Mới nhất</option><option value="ASC" <?php echo ($order_by=='ASC'?'selected':''); ?>>Cũ nhất</option></select>
                </div>
            </div>
        </form>
    </div>

    <div class="filter-stats-box">
        <div class="filter-stats-title">🔍 Thống kê theo bộ lọc hiện tại</div>
        <div class="filter-stats-container">
            <div class="filter-stats-item">
                <span class="label">Tàu dự án:</span> 
                <span class="value"><?php echo htmlspecialchars($selected_ship_name); ?></span>
            </div>
            <div class="filter-stats-item">
                <span class="label">Nhóm:</span> 
                <span class="value"><?php echo $search_group != '' ? htmlspecialchars($search_group) : 'Tất cả các nhóm'; ?></span>
            </div>
            <div class="filter-stats-item">
                <span class="label">Số lượng tàu:</span> 
                <span class="value" style="color: #6a1b9a; font-size: 18px;"><?php echo number_format($grand_total_ships); ?></span> tàu
            </div>
            <div class="filter-stats-item">
                <span class="label">Tổng số lượt người:</span> 
                <span class="highlight-value"><?php echo number_format($grand_total_workers); ?></span> lượt
            </div>
            <div class="filter-stats-item">
                <span class="label">Giờ công thực tế:</span> 
                <span class="hours-value"><?php echo number_format($grand_total_workers * 8); ?></span> giờ
            </div>
        </div>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th width="12">STT</th>
                    <th>TÀU DỰ ÁN</th>
                    <th>NHÓM</th>
                    <th>NGÀY</th>
                    <th width="50">NGƯỜI</th>
                    <th>NỘI DUNG CÔNG VIỆC</th>
                    <th>THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php $total_w = 0; $stt = $start + 1; foreach($reports as $r): $total_w += $r['worker_count']; 
                    // Kiểm tra quyền sửa/xóa dòng này
                    $can_edit_row = ($is_admin || ($r['pic'] === $user_fullname));
                ?>
                <tr id="row-<?php echo $r['id']; ?>" onclick="highlightRow(this)" class="<?php echo ($r['progress_percent'] >= 100) ? 'completed-ship' : ''; ?>">
                    <td><?php echo $stt++; ?></td>
                    <td><b><?php echo htmlspecialchars($r['project_name']); ?></b></td>
                    <td><?php echo htmlspecialchars($r['group_name']); ?></td>
                    <td>
                        <span class="view-mode"><?php echo $r['report_date']; ?></span>
                        <input type="date" form="form-update-<?php echo $r['id']; ?>" name="report_date" class="edit-mode" value="<?php echo $r['report_date']; ?>">
                    </td>
                    <td>
                        <span class="view-mode"><?php echo $r['worker_count']; ?></span>
                        <input type="number" form="form-update-<?php echo $r['id']; ?>" name="worker_count" class="edit-mode" value="<?php echo $r['worker_count']; ?>" style="width:60px">
                    </td>
                    <td style="text-align:left;">
                        <span class="view-mode job-content-text"><?php echo htmlspecialchars($r['r_job_content'] ?? $r['job_content']); ?></span>
                        <textarea form="form-update-<?php echo $r['id']; ?>" name="job_content" class="edit-mode" rows="3" style="width:95%"><?php echo htmlspecialchars($r['job_content']); ?></textarea>
                    </td>
                    <td>
                        <?php if($can_edit_row): ?>
                        <form id="form-update-<?php echo $r['id']; ?>" method="POST" style="display:none;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="ship_id" value="<?php echo $r['ship_id']; ?>">
                        </form>
                        <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(event, <?php echo $r['id']; ?>)">Sửa</button>
                        <button type="submit" form="form-update-<?php echo $r['id']; ?>" class="btn btn-save edit-mode" style="display:none;">Lưu</button>
                        <button type="button" class="btn edit-mode" style="background:#6c757d; display:none;" onclick="location.reload()">Hủy</button>
                       
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" class="btn btn-del view-mode">Xóa</button>
                        </form>
                        <?php else: ?>
                            <span style="font-size:11px; color:#999;">Chỉ xem</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f1f8f1; font-weight:bold; color: #28a745;">
                    <td colspan="4" style="text-align:right">TỔNG CỘNG TRÊN TRANG NÀY:</td>
                    <td><?php echo number_format($total_w); ?></td>
                    <td colspan="2" style="text-align:left">
                        Người. &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        <span style="color: #333;">Tổng các trang theo điều kiện lọc:</span>
                        <span style="color: #dc3545; font-size: 16px;"><?php echo number_format($grand_total_workers); ?></span> người
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php
        $base_url = "?ship_id=$search_ship&from_date=$from_date&to_date=$to_date&job_keyword=$search_job&group_name=$search_group&report_type=$search_type&order_by=$order_by";
       
        if ($page > 1) {
            echo "<a href='$base_url&page=1'>« Đầu</a>";
            echo "<a href='$base_url&page=".($page-1)."'>‹</a>";
        }

        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i >= $initial_num && $i < $condition_limit_num) {
                $active = ($page == $i) ? 'active' : '';
                echo "<a href='$base_url&page=$i' class='$active'>$i</a>";
            }
        }

        if ($page < $total_pages) {
            echo "<a href='$base_url&page=".($page+1)."'>›</a>";
            echo "<a href='$base_url&page=$total_pages'>Cuối »</a>";
        }
        ?>
    </div>
</div>

<script>
function highlightRow(row) {
    var rows = document.querySelectorAll('tbody tr');
    rows.forEach(r => r.classList.remove('row-highlight'));
    row.classList.add('row-highlight');
}

function toggleEdit(event, id) {
    event.stopPropagation();
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'inline-block');
}
</script>
</body>
</html>
<?php include 'footer.php'; ?>
