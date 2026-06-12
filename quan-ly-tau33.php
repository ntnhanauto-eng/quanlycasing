<?php
session_start();
require_once 'db_connect.php';

// --- 1. KIỂM TRA QUYỀN ---
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Lấy URL hiện tại để chuyển hướng sau khi đăng nhập/đăng xuất
$current_page = basename($_SERVER['PHP_SELF']);
if (!empty($_SERVER['QUERY_STRING'])) {
    $current_page .= '?' . $_SERVER['QUERY_STRING'];
}

$error_message = "";

// CẬP NHẬT: Lấy fullname thay vì username
$users_list = $conn->query("SELECT fullname FROM users ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- 2. XỬ LÝ DỮ LIỆU (CHỈ ADMIN MỚI ĐƯỢC THÊM/SỬA/XÓA) ---
if ($is_admin && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] == 'add') {
        $project = trim($_POST['project_name']);
        $fac = trim($_POST['fac']); // Thêm Fac
        $type = $_POST['ship_type'];
        $pic = $_POST['pic'];
        $group = $_POST['group_name'];
        $hours = $_POST['man_hours'];
        $status = $_POST['status'];
        $date = ($status == 'Đã bàn giao') ? $_POST['delivery_date'] : null;

        $check = $conn->prepare("SELECT COUNT(*) FROM ships WHERE project_name = ?");
        $check->execute([$project]);

        if ($check->fetchColumn() > 0) {
            $error_message = "Lỗi: Tên tàu '$project' đã tồn tại, không thể thêm trùng!";
        } else {
            $sql = "INSERT INTO ships (project_name, fac, ship_type, pic, group_name, man_hours, status, delivery_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([$project, $fac, $type, $pic, $group, $hours, $status, $date]);
            header("Location: quan-ly-tau.php");
            exit();
        }
    }

    elseif ($_POST['action'] == 'update_full') {
        $id = $_POST['id'];
        $project = trim($_POST['project_name']);
        $fac = trim($_POST['fac']); // Thêm Fac
        $type = $_POST['ship_type'];
        $pic = $_POST['pic'];
        $group = $_POST['group_name'];
        $hours = $_POST['man_hours'];
        $status = $_POST['status'];
        $date = ($status == 'Đã bàn giao' && !empty($_POST['delivery_date'])) ? $_POST['delivery_date'] : null;

        $check = $conn->prepare("SELECT COUNT(*) FROM ships WHERE project_name = ? AND id != ?");
        $check->execute([$project, $id]);

        if ($check->fetchColumn() > 0) {
            $error_message = "Lỗi: Tên tàu '$project' đã được sử dụng bởi một dự án khác!";
        } else {
            $sql = "UPDATE ships SET project_name = ?, fac = ?, ship_type = ?, pic = ?, group_name = ?, man_hours = ?, status = ?, delivery_date = ? WHERE id = ?";
            $conn->prepare($sql)->execute([$project, $fac, $type, $pic, $group, $hours, $status, $date, $id]);
            header("Location: quan-ly-tau.php");
            exit();
        }
    }

    elseif ($_POST['action'] == 'delete') {
        $id = $_POST['id'];
        $conn->prepare("DELETE FROM ships WHERE id = ?")->execute([$id]);
        header("Location: quan-ly-tau.php");
        exit();
    }
}

// --- 3. PHÂN TRANG VÀ TÌM KIẾM ---
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$search_group = isset($_GET['search_group']) ? trim($_GET['search_group']) : '';

$where_clauses = [];
$params = [];

if ($search_name !== '') {
    $where_clauses[] = "s.project_name LIKE ?";
    $params[] = "%$search_name%";
}
if ($search_group !== '') {
    $where_clauses[] = "s.group_name = ?";
    $params[] = $search_group;
}
$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

$groups_list = $conn->query("SELECT DISTINCT group_name FROM ships ORDER BY group_name ASC")->fetchAll(PDO::FETCH_COLUMN);

$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_sql = "SELECT COUNT(*) FROM ships s $where_sql";
$stmt_total = $conn->prepare($total_sql);
$stmt_total->execute($params);
$total_rows = $stmt_total->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// TRUY VẤN LẤY DỮ LIỆU TỔNG HỢP (Thêm Khóa tàu vào status_order)
$query = "
SELECT s.*, 
        (SELECT COALESCE(SUM(dr.worker_count), 0) * 8 FROM daily_reports dr WHERE dr.ship_id = s.id) as actual_hours,
        (SELECT MIN(dr2.report_date) FROM daily_reports dr2 WHERE dr2.ship_id = s.id AND dr2.job_content LIKE '%(EVENT)%') as start_event_date,
        sp.progress_percent as check_percent,
        ii.item_count as inspection_count,
CASE
    WHEN s.status = 'Chưa thi công' THEN 1
    WHEN s.status = 'Đang thi công' THEN 2
    WHEN s.status = 'Đã bàn giao' THEN 3
    WHEN s.status = 'Khóa tàu' THEN 4
    ELSE 5
END as status_order
FROM ships s
LEFT JOIN ship_progress sp ON s.id = sp.ship_id
LEFT JOIN inspection_items ii ON s.id = ii.ship_id
$where_sql
ORDER BY status_order ASC, s.project_name ASC
LIMIT $limit OFFSET $offset
";

$stmt_ships = $conn->prepare($query);
$stmt_ships->execute($params);
$ships = $stmt_ships->fetchAll(PDO::FETCH_ASSOC);


include 'header.php'; 
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản Lý Tàu - Hệ thống E/R Casing</title>
<style>
body{ font-family:'Segoe UI', Arial, sans-serif; background-color:#f0f2f5; margin:0; padding:15px; }
.container{ max-width:1650px; margin:auto; background:white; padding:20px; border-radius:15px; box-shadow:0 5px 20px rgba(0,0,0,0.1); }
.header-section{ border-bottom:2px solid #28a745; margin-bottom:20px; padding-bottom:10px; }
.user-bar{ display:flex; justify-content:space-between; align-items:center; font-size:15px; color:#555; background:#f8fff9; padding:10px; border-radius:8px; border:1px solid #d4edda; }
.user-bar b{ color:#28a745; }
.alert-error{ background:#f8d7da; color:#721c24; padding:12px; border-radius:8px; border:1px solid #f5c6cb; margin-bottom:20px; font-weight:bold; }
.form-add, .form-search{ background:#fff; padding:20px; border-radius:10px; border:1px solid #eee; margin-bottom:20px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
.form-search{ background:#e9ecef; border-color:#dee2e6; }
.form-row{ display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; }
.form-group{ display:flex; flex-direction:column; flex:1 1 150px; }
.form-group label{ font-size:11px; font-weight:bold; color:#555; margin-bottom:3px; text-transform:uppercase; }
input, select{ padding:8px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:100%; box-sizing:border-box; }
.btn{ padding:8px 15px; border:none; border-radius:4px; cursor:pointer; font-weight:bold; color:white; transition:0.3s; }
.btn-add{ background-color:#28a745; }
.btn-search{ background-color:#007bff; }
.btn-excel{ background-color:#1d6f42; }
.btn-clear{ background-color:#6c757d; text-decoration:none; display:inline-block; text-align:center; font-size:13px; }
.btn-edit{ background-color:#ffc107; color:#000; }
.btn-save{ background-color:#007bff; display:none; }
.btn-delete{ background-color:#dc3545; }
.btn-cancel{ background-color:#6c757d; display:none; }
.table-wrapper{ width:100%; overflow-x:auto; border-radius:8px; border:1px solid #dee2e6; }

table{ width:100%; border-collapse:collapse; min-width:1550px; table-layout: fixed; }
th, td{ border: 1px solid #dee2e6; word-wrap: break-word; overflow: hidden; text-overflow: ellipsis; }

th{ background-color:#28a745; color:white; padding:12px; text-align:left; font-size:13px; }
td{ padding:10px; font-size:14px; }

/* Định nghĩa chiều rộng cụ thể cho từng cột */
.col-stt { width: 35px; text-align: right;}
.col-name { width: 50px; text-align: center; }
.col-fac { width: 40px; text-align: center; } /* Cột Fac */
.col-type { width: 140px; }
.col-pic { width: 100px; }
.col-group { width: 40px; text-align: center;}
.col-hours-pp { width: 50px; text-align: right; }
.col-hours-act { width: 50px; text-align: right; }
.col-pre { width: 50px; text-align: right; }
.col-eff { width: 40px; text-align: right; }
.col-prog { width: 40px; text-align: right; }
.col-insp { width: 40px; text-align: center; }
.col-status { width: 70px; }
.col-start-date { width: 80px; text-align: center; } 
.col-date { width: 80px; text-align: center; }
.col-action { width: 100px; text-align: center; }

.row-chua-thi-cong{ background-color:#ffc1cc !important; }
.row-dang-thi-cong{ background-color:#fff3cd !important; }
.row-da-ban-giao{ background-color:#d4edda !important; }
.row-khoa-tau{ background-color:#e2e3e5 !important; color:#6c757d !important; } /* CSS riêng cho hàng bị khóa */
.row-highlight{ outline:2px solid #6c757d; }
.view-mode{ display:block; }
.edit-mode{ display:none; width:100%; }
#date_container_add{ display:none; }
.pagination{ margin-top:20px; display:flex; justify-content:center; gap:5px; }
.pagination a{ padding:8px 12px; border:1px solid #ddd; text-decoration:none; color:#28a745; border-radius:4px; }
.pagination a.active{ background:#28a745; color:white; border-color:#28a745; }
.badge-info { background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 10px; font-weight: bold; font-size: 12px; border: 1px solid #c8e6c9; }

@media (max-width: 600px) {
    .form-row { gap: 10px; }
    .form-group { flex: 1 1 calc(50% - 10px); }
    .btn-search, .btn-excel, .btn-clear { flex: 1; text-align: center; padding: 10px 5px; font-size: 11px; }
    .form-search .form-row { flex-direction: row; flex-wrap: wrap; }
}
</style>
</head>
<body>

<div class="container">
    <div class="header-section">
        <h2>🚢 QUẢN LÝ TÀU & DỰ ÁN</h2>
        <div class="user-bar">
            <span>
                <?php if(isset($_SESSION['user'])): ?>
                    Chào, <b><?php echo htmlspecialchars($_SESSION['fullname']); ?><?php if($is_admin): ?> (Admin)<?php endif; ?></b>
                    | <a href="logout.php?redirect=<?php echo urlencode($current_page); ?>" style="color:#dc3545; text-decoration:none; font-weight:bold;"> Đăng xuất</a>
                <?php else: ?>
                    Chào, <b>Khách</b>
                    | <a href="login.php?redirect=<?php echo urlencode($current_page); ?>" style="color:#007bff; text-decoration:none; font-weight:bold;">🔑 Đăng nhập</a>
                <?php endif; ?>
            </span>
            <a href="index.php" style="color:#28a745;text-decoration:none;font-weight:bold;">🏠 Về Trang chủ</a>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert-error"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if($is_admin): ?>
    <div class="form-add">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group"><label>Tên tàu</label><input type="text" name="project_name" required></div>
                <div class="form-group"><label>Fac</label><input type="text" name="fac" placeholder="Nhà máy..." required></div>
                <div class="form-group"><label>Loại tàu</label><input type="text" name="ship_type" required></div>
                <div class="form-group"><label>Người phụ trách</label>
                    <select name="pic" required><option value="">-- Chọn nhân viên --</option>
                        <?php foreach($users_list as $u): ?><option value="<?php echo htmlspecialchars($u['fullname']); ?>"><?php echo htmlspecialchars($u['fullname']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Nhóm</label><input type="text" name="group_name" required></div>
                <div class="form-group"><label>Giờ công</label><input type="number" name="man_hours" required></div>
                <div class="form-group"><label>Trạng thái</label>
                    <select name="status" id="status_add" onchange="toggleDateAdd()">
                        <option value="Chưa thi công">Chưa thi công</option>
                        <option value="Đang thi công">Đang thi công</option>
                        <option value="Đã bàn giao">Đã bàn giao</option>
                        <option value="Khóa tàu">Khóa tàu</option>
                    </select>
                </div>
                <div class="form-group" id="date_container_add"><label>Ngày bàn giao</label><input type="date" name="delivery_date"></div>
                <button type="submit" class="btn btn-add">THÊM MỚI</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="form-search">
        <form method="GET" id="searchForm">
            <div class="form-row">
                <div class="form-group"><label>🔍 Tìm Tên tàu</label><input type="text" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Nhập tên tàu..."></div>
                <div class="form-group"><label>👥 Lọc theo Nhóm</label>
                    <select name="search_group"><option value="">-- Tất cả --</option>
                        <?php foreach($groups_list as $g): ?><option value="<?php echo htmlspecialchars($g); ?>" <?php echo ($search_group == $g) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-search">TÌM KIẾM</button>
                <button type="button" onclick="exportExcel()" class="btn btn-excel">XUẤT EXCEL</button>
                <a href="quan-ly-tau.php" class="btn btn-clear">XÓA BỘ LỌC</a>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th class="col-stt">STT</th>
                    <th class="col-name">Tên tàu</th>
                    <th class="col-fac">Fac</th>
                    <th class="col-type">Loại tàu</th>
                    <th class="col-pic">Người phụ trách</th>
                    <th class="col-group">Nhóm</th>
                    <th class="col-hours-pp">Giờ công PP</th>
                    <th class="col-hours-act">Giờ công TT</th>
                    <th class="col-pre">Pre-execution</th>
                    <th class="col-eff">Hiệu quả</th>
                    <th class="col-prog">Tiến độ</th>
                    <th class="col-insp">Mục KT</th>
                    <th class="col-status">Trạng thái</th>
                    <th class="col-start-date">Ngày bắt đầu</th>
                    <th class="col-date">Ngày bàn giao</th>
                    <?php if($is_admin): ?><th class="col-action">Thao tác</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (count($ships) > 0): $stt = $offset + 1; ?>
                <?php foreach ($ships as $ship):
                    $status_class = '';
                    if ($ship['status'] == 'Chưa thi công') $status_class = 'row-chua-thi-cong';
                    elseif ($ship['status'] == 'Đang thi công') $status_class = 'row-dang-thi-cong';
                    elseif ($ship['status'] == 'Đã bàn giao') $status_class = 'row-da-ban-giao';
                    elseif ($ship['status'] == 'Khóa tàu') $status_class = 'row-khoa-tau';

                    $man_hours_pp = $ship['man_hours'];
                    $actual_hours = $ship['actual_hours'];
                    $percent_eff = ($actual_hours > 0) ? ($man_hours_pp / $actual_hours) * 100 : 0;
                    $prog_val = $ship['check_percent'] !== null ? $ship['check_percent'] : 0;
                    
                    $pre_execution = $actual_hours - (($prog_val / 100) * $man_hours_pp);
                    $inspection_count = $ship['inspection_count'] !== null ? $ship['inspection_count'] : 0;
                ?>
                <tr id="row-<?php echo $ship['id']; ?>" class="<?php echo $status_class; ?>" onclick="selectRow(this)">
                    <?php if($is_admin): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_full">
                        <input type="hidden" name="id" value="<?php echo $ship['id']; ?>">
                    <?php endif; ?>
                        <td class="col-stt"><?php echo $stt++; ?></td>
                        <td class="col-name">
                            <span class="view-mode"><b><?php echo htmlspecialchars($ship['project_name']); ?></b></span>
                            <?php if($is_admin): ?><input type="text" name="project_name" class="edit-mode" value="<?php echo htmlspecialchars($ship['project_name']); ?>" required><?php endif; ?>
                        </td>
                        <td class="col-fac">
                            <span class="view-mode"><?php echo htmlspecialchars($ship['fac'] ?? ''); ?></span>
                            <?php if($is_admin): ?><input type="text" name="fac" class="edit-mode" value="<?php echo htmlspecialchars($ship['fac'] ?? ''); ?>" required><?php endif; ?>
                        </td>
                        <td class="col-type">
                            <span class="view-mode"><?php echo htmlspecialchars($ship['ship_type']); ?></span>
                            <?php if($is_admin): ?><input type="text" name="ship_type" class="edit-mode" value="<?php echo htmlspecialchars($ship['ship_type']); ?>" required><?php endif; ?>
                        </td>
                        <td class="col-pic">
                            <span class="view-mode"><?php echo htmlspecialchars($ship['pic']); ?></span>
                            <?php if($is_admin): ?>
                            <select name="pic" class="edit-mode" required>
                                <?php foreach($users_list as $u): ?><option value="<?php echo htmlspecialchars($u['fullname']); ?>" <?php echo ($ship['pic'] == $u['fullname']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['fullname']); ?></option><?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td class="col-group">
                            <span class="view-mode"><?php echo htmlspecialchars($ship['group_name']); ?></span>
                            <?php if($is_admin): ?><input type="text" name="group_name" class="edit-mode" value="<?php echo htmlspecialchars($ship['group_name']); ?>" required><?php endif; ?>
                        </td>
                        <td class="col-hours-pp">
                            <span class="view-mode"><?php echo number_format($ship['man_hours']); ?></span>
                            <?php if($is_admin): ?><input type="number" name="man_hours" class="edit-mode" value="<?php echo $ship['man_hours']; ?>" required><?php endif; ?>
                        </td>
                        <td class="col-hours-act" style="font-weight: bold; color: #d63384;"><?php echo number_format($actual_hours); ?></td>
                        <td class="col-pre" style="font-weight: bold; color: #6610f2;"><?php echo number_format($pre_execution, 1); ?></td>
                        <td class="col-eff" style="font-weight: bold; color: <?php echo ($percent_eff > 100) ? '#dc3545' : '#0d6efd'; ?>;"><?php echo number_format($percent_eff, 1); ?>%</td>
                        <td class="col-prog" style="font-weight: bold; color: #198754;"><?php echo $prog_val; ?>%</td>
                        <td class="col-insp"><span class="badge-info"><?php echo $inspection_count; ?></span></td>
                        <td class="col-status">
                            <span class="view-mode"><?php echo $ship['status']; ?></span>
                            <?php if($is_admin): ?>
                            <select name="status" class="edit-mode">
                                <option value="Chưa thi công" <?php echo ($ship['status'] == 'Chưa thi công') ? 'selected' : ''; ?>>Chưa thi công</option>
                                <option value="Đang thi công" <?php echo ($ship['status'] == 'Đang thi công') ? 'selected' : ''; ?>>Đang thi công</option>
                                <option value="Đã bàn giao" <?php echo ($ship['status'] == 'Đã bàn giao') ? 'selected' : ''; ?>>Đã bàn giao</option>
                                <option value="Khóa tàu" <?php echo ($ship['status'] == 'Khóa tàu') ? 'selected' : ''; ?>>Khóa tàu</option>
                            </select>
                            <?php endif; ?>
                        </td>
                        <td class="col-start-date">
                            <b><?php echo ($ship['start_event_date']) ? date('Y-m-d', strtotime($ship['start_event_date'])) : '---'; ?></b>
                        </td>
                        <td class="col-date">
                            <span class="view-mode"><?php echo ($ship['delivery_date']) ? date('Y-m-d', strtotime($ship['delivery_date'])) : '---'; ?></span>
                            <?php if($is_admin): ?><input type="date" name="delivery_date" class="edit-mode" value="<?php echo $ship['delivery_date']; ?>"><?php endif; ?>
                        </td>
                        <?php if($is_admin): ?>
                        <td class="col-action">
                            <button type="button" class="btn btn-edit" onclick="enableEdit(event, <?php echo $ship['id']; ?>)">Sửa</button>
                            <button type="submit" class="btn btn-save">Lưu</button>
                            <button type="button" class="btn btn-cancel" onclick="disableEdit(event)">Hủy</button>
                    </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa dự án này?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $ship['id']; ?>">
                                <button type="submit" class="btn btn-delete">Xóa</button>
                            </form>
                        </td>
                        <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?php echo $is_admin ? '16' : '15'; ?>" style="text-align:center;">Dữ liệu trống hoặc không tìm thấy kết quả phù hợp.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for($i = 1; $i <= $total_pages; $i++): $query_string = http_build_query(array_merge($_GET, ['page' => $i])); ?>
            <a href="?<?php echo $query_string; ?>" class="<?php echo ($page == $i) ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function selectRow(row){
    document.querySelectorAll('tbody tr').forEach(r => r.classList.remove('row-highlight'));
    row.classList.add('row-highlight');
}
function enableEdit(event, id){
    event.stopPropagation();
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'block');
    row.querySelector('.btn-edit').style.display = 'none';
    row.querySelector('.btn-delete').style.display = 'none';
    row.querySelector('.btn-save').style.display = 'inline-block';
    row.querySelector('.btn-cancel').style.display = 'inline-block';
}
function disableEdit(event){ event.stopPropagation(); location.reload(); }
function toggleDateAdd(){
    var status = document.getElementById("status_add").value;
    document.getElementById("date_container_add").style.display = (status === "Đã bàn giao") ? "flex" : "none";
}

function exportExcel(){
    const searchName = document.querySelector('input[name="search_name"]').value;
    const searchGroup = document.querySelector('select[name="search_group"]').value;
    let url = 'export_excel.php?action=export_ships';
    url += '&search_name=' + encodeURIComponent(searchName);
    url += '&search_group=' + encodeURIComponent(searchGroup);
    window.location.href = url;
}
</script>
</body>
</html>
<?php include 'footer.php'; ?>
