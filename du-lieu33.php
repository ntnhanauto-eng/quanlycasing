<?php
session_start();
require_once 'db_connect.php';

// Lưu URL hiện tại để quay lại sau khi login/logout
$current_url = $_SERVER['REQUEST_URI'];
$_SESSION['back_url'] = $current_url;

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

// --- 1. XỬ LÝ DỮ LIỆU (HỖ TRỢ AJAX) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $target_ship_id = $_POST['ship_id'] ?? null;
    
    if (in_array($_POST['action'], ['update', 'delete']) && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM daily_reports WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        $target_ship_id = $report['ship_id'] ?? null;
    }

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

            if (isset($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Cập nhật thành công']);
                exit();
            }
        }
        elseif ($_POST['action'] == 'delete') {
            $id = $_POST['id'];
            $conn->prepare("DELETE FROM daily_reports WHERE id = ?")->execute([$id]);
            
            if (isset($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Xóa thành công']);
                exit();
            }
        }
    } else {
        if (isset($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền thực hiện']);
            exit();
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
$order_by = $_GET['order_by'] ?? 'DESC';

$where = ["1=1"]; $params = [];
if ($search_ship != '') { $where[] = "dr.ship_id = ?"; $params[] = $search_ship; }
if ($from_date != '') { $where[] = "dr.report_date >= ?"; $params[] = $from_date; }
if ($to_date != '') { $where[] = "dr.report_date <= ?"; $params[] = $to_date; }
if ($search_job != '') { $where[] = "dr.job_content LIKE ?"; $params[] = "%$search_job%"; }
if ($search_group != '') { $where[] = "s.group_name = ?"; $params[] = $search_group; }
$where_sql = implode(" AND ", $where);

// --- 3. XỬ LÝ XUẤT EXCEL (CHỈ ADMIN) ---
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    if (!$is_admin) { die("Bạn không có quyền thực hiện chức năng này."); }
    $sql_export = "SELECT dr.*, s.project_name, s.group_name FROM daily_reports dr JOIN ships s ON dr.ship_id = s.id WHERE $where_sql ORDER BY dr.report_date $order_by";
    $stmt_export = $conn->prepare($sql_export); $stmt_export->execute($params); $data_export = $stmt_export->fetchAll(PDO::FETCH_ASSOC);
    $filename = "Bao-cao-cong-viec-" . date('Ymd-His') . ".xls";
    header("Content-Type: application/vnd.ms-excel; charset=utf-8"); header("Content-Disposition: attachment; filename=$filename");
    echo '<table border="1"><tr><th colspan="6" style="font-size:20px;">BÁO CÁO DỮ LIỆU HÀNG NGÀY</th></tr><tr style="background:#28a745; color:white;"><th>STT</th><th>Tàu Dự Án</th><th>Nhóm</th><th>Ngày</th><th>Số Người</th><th>Nội Dung Công Việc</th></tr>';
    $stt_ex = 1; $total_ex = 0;
    foreach ($data_export as $row) {
        echo '<tr><td>' . $stt_ex++ . '</td><td>' . $row['project_name'] . '</td><td>' . $row['group_name'] . '</td><td>' . $row['report_date'] . '</td><td>' . $row['worker_count'] . '</td><td>' . $row['job_content'] . '</td></tr>';
        $total_ex += $row['worker_count'];
    }
    echo '<tr style="font-weight:bold;"><td colspan="4">TỔNG CỘNG</td><td>' . $total_ex . '</td><td>Người</td></tr></table>';
    exit();
}

// --- 4. PHÂN TRANG & TÍNH TỔNG ---
$limit = 13;
$page = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;
$start = ($page - 1) * $limit;

$count_stmt = $conn->prepare("SELECT COUNT(*) as total_rows, SUM(dr.worker_count) as total_workers, COUNT(DISTINCT dr.ship_id) as total_active_ships FROM daily_reports dr JOIN ships s ON dr.ship_id = s.id WHERE $where_sql");
$count_stmt->execute($params); $count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_pages = ceil($count_data['total_rows'] / $limit);
$grand_total_workers = $count_data['total_workers'] ?? 0;
$total_active_ships = $count_data['total_active_ships'] ?? 0;

$range = 1; $initial_num = $page - $range; $condition_limit_num = ($page + $range) + 1;

// --- 5. TRUY VẤN DỮ LIỆU HIỂN THỊ ---
$sql = "SELECT dr.*, s.project_name, s.group_name, s.pic, s.status as ship_status, sp.progress_percent
        FROM daily_reports dr
        JOIN ships s ON dr.ship_id = s.id
        LEFT JOIN ship_progress sp ON s.id = sp.ship_id
        WHERE $where_sql
        ORDER BY dr.report_date $order_by, dr.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql); $stmt->execute($params); $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ships_filter_list = $conn->query("SELECT id, project_name FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$ships_active_list = $conn->query("SELECT id, project_name, status, pic FROM ships WHERE status IN ('Đang thi công', 'Chưa thi công') ORDER BY status DESC, project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$groups_list = $conn->query("SELECT DISTINCT group_name FROM ships")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 1400px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 20px; padding-bottom: 10px; }
    .header-section h2 { text-align: center; margin: 0 0 5px 0; color: #333; }
   
    /* Thẻ thống kê KPI bắt mắt phía trên */
    .kpi-container { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
    .kpi-card { flex: 1; min-width: 220px; background: #fff; padding: 15px 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: flex; flex-direction: column; border-left: 5px solid #6c757d; }
    .kpi-card.kpi-workers { border-left-color: #28a745; background: #f4fbf6; }
    .kpi-card.kpi-ships { border-left-color: #007bff; background: #f4f8fd; }
    .kpi-title { font-size: 11px; text-transform: uppercase; color: #666; font-weight: bold; margin-bottom: 5px; }
    .kpi-value { font-size: 24px; font-weight: 800; color: #222; }

    /* Thiết kế Form theo yêu cầu */
    .form-box-add { background: #eef9f0; padding: 20px; border-radius: 10px; border: 2.5px solid #28a745; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .form-box-search { background: #eef5fc; padding: 20px; border-radius: 10px; border: 2.5px solid #007bff; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }

    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; flex: 1; }
    .col-date { flex: 0 0 140px; }
    .col-ship { flex: 0 0 240px; }
    .col-worker { flex: 0 0 80px; }
    .col-job { flex: 3 1 300px; }
    .col-type { flex: 0 0 130px; }
    .form-group label { font-size: 11px; font-weight: bold; color: #555; margin-bottom: 3px; text-transform: uppercase; }
    input, select, textarea { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    textarea { font-family: inherit; resize: vertical; min-height: 38px; }
    
    .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: 0.3s; font-size: 12px; }
    .btn-add { background-color: #28a745; }
    .btn-add:hover { background-color: #218838; }
    .btn-edit { background-color: #ffc107; color: #000; }
    .btn-save { background-color: #28a745; color: white; }
    .btn-del { background-color: #dc3545; }
    .btn-search { background-color: #007bff; min-width: 100px; }
    .btn-excel { background-color: #1d6f42; }
    
    /* Cải tiến bảng dữ liệu chuyên nghiệp */
    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    th { background-color: #28a745; color: white; padding: 14px 12px; text-align: center; font-weight: bold; font-size: 13px; white-space: nowrap; }
    td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; text-align: center; transition: background 0.3s; }
    
    /* Trạng thái và Badge */
    .badge { padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; }
    .badge-event { background: #e9ecef; color: #dc3545; border: 1px solid #dc3545; margin-left: 5px; animation: pulse 2s infinite; }
    .badge-status-working { background: #fff3cd; color: #856404; }
    .badge-status-pending { background: #f8d7da; color: #721c24; }
    
    /* Thanh tiến độ lồng trong bảng */
    .progress-bar-container { width: 100%; background: #e9ecef; border-radius: 10px; height: 6px; margin-top: 4px; overflow: hidden; }
    .progress-bar-fill { background: #28a745; height: 100%; border-radius: 10px; }

    .row-highlight { background-color: #e2e3e5 !important; }
    .row-updated-flash { background-color: #c3e6cb !important; } /* Hiệu ứng flash khi lưu thành công */
    .completed-ship { opacity: 0.6; filter: grayscale(15%); }
    tbody tr:nth-child(even) { background-color: #f9f9f9; }
    tbody tr:hover { background-color: #f1f8f1; cursor: pointer; }
    .edit-mode { display: none; }
    
    .pagination { text-align: center; margin-top: 20px; }
    .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; margin: 0 3px; border-radius: 4px; color: #333; font-size: 13px; }
    .pagination a.active { background: #28a745; color: white; border-color: #28a745; }
    
    .job-content-text { white-space: pre-wrap; word-break: break-word; text-align: left; display: block; }
    
    .select2-container .select2-selection--single { height: 37px !important; border: 1px solid #ccc !important; border-radius: 4px !important; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 35px !important; font-size: 13px !important; color: #333 !important; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 35px !important; }
   
    @keyframes pulse { 0% { opacity: 0.8; } 50% { opacity: 1; } 100% { opacity: 0.8; } }
    @media (max-width: 768px) { .form-group { flex: 1 1 100% !important; } }
</style>

<div class="container">
    <div class="header-section">
        <h2>📊 QUẢN LÝ DỮ LIỆU HÀNG NGÀY</h2>
    </div>

    <div class="kpi-container">
        <div class="kpi-card kpi-workers">
            <span class="kpi-title">Tổng công thợ huy động</span>
            <span class="kpi-value"><span id="kpi-total-workers"><?php echo number_format($grand_total_workers); ?></span> người</span>
        </div>
        <div class="kpi-card kpi-ships">
            <span class="kpi-title">Số tàu đang chạy dự án</span>
            <span class="kpi-value"><?php echo number_format($total_active_ships); ?> tàu</span>
        </div>
    </div>

    <?php 
    $can_add_any = $is_admin;
    if(!$is_admin) {
        foreach($ships_active_list as $s) { if($s['pic'] === $user_fullname) { $can_add_any = true; break; } }
    }
    ?>

    <?php if ($can_add_any): ?>
    <div class="form-box-add">
        <form method="POST" onsubmit="disableSubmitButton(this)">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group col-date"><label>Ngày</label><input type="date" name="report_date" required value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group col-ship"><label>Tàu được phân công</label>
                    <select name="ship_id" class="select2-enable" required>
                        <option value="">-- Chọn tàu --</option>
                        <?php foreach($ships_active_list as $s): 
                            $bg_color = $s['status'] === 'Chưa thi công' ? '#ffe6e6' : '#fff3cd';
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
                <button type="submit" class="btn btn-add" id="btn-submit-add">LƯU DỮ LIỆU</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="form-box-search">
        <form method="GET">
            <div class="form-row">
                <div class="form-group col-ship"><label>Lọc theo tàu</label>
                    <select name="ship_id" class="select2-enable"><option value="">Tất cả các tàu</option>
                        <?php foreach($ships_filter_list as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo ($search_ship == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['project_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-search">TÌM KIẾM</button>
                <?php if ($is_admin): ?>
                <a href="du-lieu.php?<?php echo $query_string; ?>&export=excel" class="btn btn-excel" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">XUẤT EXCEL</a>
                <?php endif; ?>
                <span style="color:#007bff; cursor:pointer; font-size:12px; font-weight:bold;" onclick="document.getElementById('adv').style.display=(document.getElementById('adv').style.display==='none'?'flex':'none')">TÌM KIẾM NÂNG CAO ▼</span>
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
                <div class="form-group col-type"><label>Sắp xếp</label>
                    <select name="order_by"><option value="DESC" <?php echo ($order_by=='DESC'?'selected':''); ?>>Mới nhất</option><option value="ASC" <?php echo ($order_by=='ASC'?'selected':''); ?>>Cũ nhất</option></select>
                </div>
            </div>
        </form>
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
                    $can_edit_row = ($is_admin || ($r['pic'] === $user_fullname));
                    $prog = (int)($r['progress_percent'] ?? 0);
                    $is_event = (strpos($r['job_content'], '(EVENT)') !== false);
                    $clean_content = str_replace(" (EVENT)", "", $r['job_content']);
                ?>
                <tr id="row-<?php echo $r['id']; ?>" onclick="highlightRow(this)" class="<?php echo ($prog >= 100) ? 'completed-ship' : ''; ?>">
                    <td><?php echo $stt++; ?></td>
                    <td style="text-align:left; padding-left: 15px;">
                        <b><?php echo htmlspecialchars($r['project_name']); ?></b>
                        <div class="progress-bar-container" title="Tiến độ: <?php echo $prog; ?>%">
                            <div class="progress-bar-fill" style="width: <?php echo min($prog, 100); ?>%"></div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo $r['ship_status'] === 'Đang thi công' ? 'badge-status-working' : 'badge-status-pending'; ?>">
                            <?php echo htmlspecialchars($r['group_name']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="view-mode date-text"><?php echo $r['report_date']; ?></span>
                        <input type="date" name="report_date" class="edit-mode" value="<?php echo $r['report_date']; ?>">
                    </td>
                    <td>
                        <span class="view-mode worker-text" style="font-weight:bold; color:#2c3e50;"><?php echo $r['worker_count']; ?></span>
                        <input type="number" name="worker_count" class="edit-mode" value="<?php echo $r['worker_count']; ?>" style="width:65px">
                    </td>
                    <td style="text-align:left;">
                        <span class="view-mode job-content-text">
                            <?php echo htmlspecialchars($clean_content); ?>
                            <?php if($is_event): ?><span class="badge badge-event">EVENT</span><?php endif; ?>
                        </span>
                        <textarea name="job_content" class="edit-mode" rows="3" style="width:95%"><?php echo htmlspecialchars($r['job_content']); ?></textarea>
                    </td>
                    <td>
                        <?php if($can_edit_row): ?>
                        <input type="hidden" name="ship_id" value="<?php echo $r['ship_id']; ?>">
                        <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(event, <?php echo $r['id']; ?>)">Sửa</button>
                        <button type="button" class="btn btn-save edit-mode" style="display:none;" onclick="saveRowAjax(event, <?php echo $r['id']; ?>)">Lưu</button>
                        <button type="button" class="btn edit-mode" style="background:#6c757d; display:none;" onclick="cancelEdit(event, <?php echo $r['id']; ?>)">Hủy</button>
                        <button type="button" class="btn btn-del view-mode" onclick="deleteRowAjax(event, <?php echo $r['id']; ?>)">Xóa</button>
                        <?php else: ?>
                            <span style="font-size:11px; color:#999;">Chỉ xem</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#f1f8f1; font-weight:bold; color: #28a745;">
                    <td colspan="4" style="text-align:right">TỔNG CỘNG TRÊN TRANG NÀY:</td>
                    <td><span id="page-total-workers"><?php echo number_format($total_w); ?></span></td>
                    <td colspan="2" style="text-align:left">
                        Người. &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                        <span style="color: #333;">Tổng các trang theo điều kiện lọc:</span>
                        <span id="grand-total-workers" style="color: #dc3545; font-size: 16px;"><?php echo number_format($grand_total_workers); ?></span> người
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php
        $base_url = "?ship_id=$search_ship&from_date=$from_date&to_date=$to_date&job_keyword=$search_job&group_name=$search_group&order_by=$order_by";
        if ($page > 1) { echo "<a href='$base_url&page=1'>« Đầu</a><a href='$base_url&page=".($page-1)."'>‹</a>"; }
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i >= $initial_num && $i < $condition_limit_num) {
                $active = ($page == $i) ? 'active' : ''; echo "<a href='$base_url&page=$i' class='$active'>$i</a>";
            }
        }
        if ($page < $total_pages) { echo "<a href='$base_url&page=".($page+1)."'>›</a><a href='$base_url&page=$total_pages'>Cuối »</a>"; }
        ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2-enable').select2({ width: '100%', allowClear: true });
});

// Ngăn chặn bấm nút Lưu nhiều lần liên tục khi tạo dòng mới
function disableSubmitButton(form) {
    var btn = document.getElementById('btn-submit-add');
    btn.disabled = true;
    btn.innerText = 'ĐANG LƯU...';
    btn.style.backgroundColor = '#6c757d';
}

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

function cancelEdit(event, id) {
    event.stopPropagation();
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'inline-block');
}

// XỬ LÝ SỬA DÒNG QUA AJAX + HIỆU ỨNG CHỚP MÀU XANH LÁ LƯU THÀNH CÔNG
function saveRowAjax(event, id) {
    event.stopPropagation();
    var row = document.getElementById('row-' + id);
    
    var newDate = row.querySelector('input[name="report_date"]').value;
    var newWorkerCount = row.querySelector('input[name="worker_count"]').value;
    var newJobContent = row.querySelector('textarea[name="job_content"]').value;
    var shipId = row.querySelector('input[name="ship_id"]').value;

    var formData = new FormData();
    formData.append('action', 'update');
    formData.append('id', id);
    formData.append('ship_id', shipId);
    formData.append('report_date', newDate);
    formData.append('worker_count', newWorkerCount);
    formData.append('job_content', newJobContent);
    formData.append('is_ajax', '1');

    fetch('du-lieu.php', { method: 'POST', body: formData })
    .then(res => { if (res.ok) return res.json(); throw new Error(); })
    .then(data => {
        if (data.status === 'success') {
            var oldWorker = parseInt(row.querySelector('.worker-text').innerText) || 0;
            var diff = parseInt(newWorkerCount) - oldWorker;

            row.querySelector('.date-text').innerText = newDate;
            row.querySelector('.worker-text').innerText = newWorkerCount;
            
            // Xử lý kiểm tra chuỗi xem có chứa từ khóa (EVENT) không để vẽ Badge
            var isEvent = newJobContent.includes('(EVENT)');
            var cleanText = newJobContent.replace(' (EVENT)', '');
            var escapedText = cleanText.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
            
            if(isEvent) {
                row.querySelector('.job-content-text').innerHTML = escapedText + ' <span class="badge badge-event">EVENT</span>';
            } else {
                row.querySelector('.job-content-text').innerHTML = escapedText;
            }

            updateTotalsOnInterface(diff);

            row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'inline-block');
            
            // Tạo hiệu ứng Chớp Đèn Xanh thông báo lưu thành công cực đẹp
            row.classList.add('row-updated-flash');
            setTimeout(() => { row.classList.remove('row-updated-flash'); }, 1200);
        }
    }).catch(() => alert('Có lỗi xảy ra khi lưu dữ liệu.'));
}

// XỬ LÝ XÓA DÒNG QUA AJAX + HIỆU ỨNG BIẾN MẤT MƯỢT MÀ
function deleteRowAjax(event, id) {
    event.stopPropagation();
    if (!confirm('Bạn có chắc chắn muốn xóa dòng báo cáo này không?')) return;

    var row = document.getElementById('row-' + id);
    var formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    formData.append('is_ajax', '1');

    fetch('du-lieu.php', { method: 'POST', body: formData })
    .then(res => { if (res.ok) return res.json(); throw new Error(); })
    .then(data => {
        if (data.status === 'success') {
            var oldWorker = parseInt(row.querySelector('.worker-text').innerText) || 0;
            updateTotalsOnInterface(-oldWorker);
            
            // Hiệu ứng Fade Out và Co dòng lại trước khi xóa hẳn khỏi giao diện
            row.style.transition = 'all 0.5s ease';
            row.style.opacity = '0';
            setTimeout(() => { row.remove(); }, 500);
        }
    }).catch(() => alert('Không thể xóa. Có lỗi hệ thống xảy ra.'));
}

function updateTotalsOnInterface(diff) {
    if (diff === 0) return;
    var pageTotalEl = document.getElementById('page-total-workers');
    var grandTotalEl = document.getElementById('grand-total-workers');
    var kpiWorkersEl = document.getElementById('kpi-total-workers');
    
    if (pageTotalEl) {
        var val = parseInt(pageTotalEl.innerText.replace(/,/g, '')) || 0;
        pageTotalEl.innerText = (val + diff).toLocaleString('en-US');
    }
    if (grandTotalEl) {
        var val = parseInt(grandTotalEl.innerText.replace(/,/g, '')) || 0;
        grandTotalEl.innerText = (val + diff).toLocaleString('en-US');
    }
    if (kpiWorkersEl) {
        var val = parseInt(kpiWorkersEl.innerText.replace(/,/g, '')) || 0;
        kpiWorkersEl.innerText = (val + diff).toLocaleString('en-US');
    }
}
</script>
</body>
</html>
<?php include 'footer.php'; ?>
