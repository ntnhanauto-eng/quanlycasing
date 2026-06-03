<?php
session_start();
require_once 'db_connect.php';

// --- 0. XỬ LÝ REDIRECT & USER TRANG HIỆN TẠI ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$actual_link = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$is_logged_in = isset($_SESSION['user']) && !empty($_SESSION['user']);
$is_admin = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$display_name = $is_logged_in ? ((isset($_SESSION['fullname']) && !empty($_SESSION['fullname'])) ? $_SESSION['fullname'] : $_SESSION['user']) : 'Khách';
$user_fullname = $_SESSION['fullname'] ?? '';

// Lấy tham số lọc để tái định hướng không mất bộ lọc
$f_ship = $_GET['f_ship'] ?? '';
$f_mistake = $_GET['f_mistake'] ?? '';
$f_status = $_GET['f_status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$query_string = "page=$page&f_ship=" . urlencode($f_ship) . "&f_mistake=" . urlencode($f_mistake) . "&f_status=" . urlencode($f_status);

// Hàm bảo mật XSS
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// Hàm kiểm tra quyền thao tác dựa trên PIC
function can_edit_job($conn, $ship_id, $is_admin, $user_fullname) {
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
    
    // Nếu là update, lấy ship_id từ bản ghi hiện tại
    if ($_POST['action'] == 'update_full' && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM remain_jobs WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $target_ship_id = $stmt->fetchColumn();
    }

    if (can_edit_job($conn, $target_ship_id, $is_admin, $user_fullname)) {
        if ($_POST['action'] == 'add') {
            $sql = "INSERT INTO remain_jobs (ship_id, block_info, por_info, part_no, material_name, quantity, mistake_type, material_arrival_date, completion_date, notes, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $_POST['ship_id'], $_POST['block_info'], $_POST['por_info'], $_POST['part_no'], 
                $_POST['material_name'], $_POST['quantity'], $_POST['mistake_type'], 
                $_POST['m_date'] ?: null, $_POST['c_date'] ?: null, $_POST['notes'], $_POST['status']
            ]);
        } 
        elseif ($_POST['action'] == 'update_full' && isset($_POST['id'])) {
            $sql = "UPDATE remain_jobs SET block_info=?, por_info=?, part_no=?, material_name=?, quantity=?, mistake_type=?, material_arrival_date=?, completion_date=?, notes=?, status=? WHERE id=?";
            $conn->prepare($sql)->execute([
                $_POST['block_info'], $_POST['por_info'], $_POST['part_no'], $_POST['material_name'], 
                $_POST['quantity'], $_POST['mistake_type'], $_POST['m_date'] ?: null, 
                $_POST['c_date'] ?: null, $_POST['notes'], $_POST['status'], $_POST['id']
            ]);
        }
    }
    header("Location: remain-jobs.php?" . $query_string); exit();
}

if (isset($_GET['del'])) {
    $stmt = $conn->prepare("SELECT ship_id FROM remain_jobs WHERE id = ?");
    $stmt->execute([$_GET['del']]);
    $target_ship_id = $stmt->fetchColumn();

    if (can_edit_job($conn, $target_ship_id, $is_admin, $user_fullname)) {
        $conn->prepare("DELETE FROM remain_jobs WHERE id = ?")->execute([$_GET['del']]);
    }
    header("Location: remain-jobs.php?" . $query_string); exit();
}

// --- 2. TÌM KIẾM & PHÂN TRANG ---
$where = ["1=1"]; $params = [];
if ($f_ship != '') { $where[] = "rj.ship_id = ?"; $params[] = $f_ship; }
if ($f_mistake != '') { $where[] = "rj.mistake_type = ?"; $params[] = $f_mistake; }
if ($f_status != '') { $where[] = "rj.status = ?"; $params[] = $f_status; }
$where_sql = implode(" AND ", $where);

$limit = 50;
$start = ($page - 1) * $limit;
$total_rows = $conn->prepare("SELECT COUNT(*) FROM remain_jobs rj WHERE $where_sql");
$total_rows->execute($params);
$total_pages = ceil($total_rows->fetchColumn() / $limit);

$sql = "SELECT rj.*, s.project_name, s.pic FROM remain_jobs rj 
        JOIN ships s ON rj.ship_id = s.id 
        WHERE $where_sql ORDER BY rj.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql); $stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ships_list = $conn->query("SELECT id, project_name, pic FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$mistake_list = ["Design mistake", "Material mistake", "Material delay", "Maker mistake", "Maker delay", "Pre mistake", "Production mistake", "Khác"];

include 'header.php'; 
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remain Jobs - Quản lý tồn động</title>
    <style>
        :root { --primary: #2e7d32; --secondary: #4caf50; --light-bg: #f1f8e9; --border: #c8e6c9; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f4f7f6; margin: 0; padding: 15px; color: #333; font-size: 13px; }
        .container { max-width: 100%; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid var(--primary); padding-bottom: 10px; flex-wrap: wrap; gap: 10px; }
        .header-flex h2 { margin: 0; color: var(--primary); font-size: 18px; text-transform: uppercase; }
        .user-bar { display: flex; justify-content: space-between; align-items: center; font-size: 13px; background: #f8fff9; padding: 8px 15px; border-radius: 8px; border: 1px solid #d4edda; margin-bottom: 15px; width: 100%; box-sizing: border-box; }
        .user-info b { color: var(--primary); }
        .nav-links { display: flex; gap: 15px; align-items: center; }
        .nav-links a { text-decoration: none; font-weight: bold; }
        .login-btn { color: #fff; background: #007bff; padding: 4px 10px; border-radius: 4px; }
        .logout-btn { color: #d32f2f; }
        .home-btn { color: var(--primary); }
        .admin-box, .search-box { padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid var(--border); }
        .admin-box { background: var(--light-bg); }
        .form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; flex: 1; min-width: 140px; }
        @media (max-width: 600px) { .form-group { flex: 1 1 100%; } .header-flex { flex-direction: column; text-align: center; } .user-bar { flex-direction: column; gap: 10px; text-align: center; } }
        .form-group label { font-size: 11px; font-weight: bold; color: var(--primary); margin-bottom: 4px; }
        input, select, textarea { padding: 8px; border: 1px solid #ccc; border-radius: 4px; outline: none; font-family: inherit; font-size: inherit; }
        
        /* CẤU TRÚC ĐỊNH DẠNG BẢNG & FIXED STICKY COLUMNS */
        .table-responsive { overflow-x: auto; margin-top: 10px; border: 1px solid var(--border); border-radius: 4px; position: relative; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1800px; table-layout: fixed; }
        th, td { padding: 8px; border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); text-align: center; transition: 0.2s; vertical-align: middle; box-sizing: border-box; word-wrap: break-word; white-space: normal; }
        th { background: var(--primary); color: #fff; border-bottom: 2px solid #388e3c; font-size: 11px; position: sticky; top: 0; z-index: 10; }
        
        /* Cố định cột STT bên trái (Rộng 80px) */
        th:nth-child(1), td:nth-child(1) { position: sticky; left: 0; width: 80px; min-width: 80px; max-width: 80px; z-index: 5; }
        /* Cố định cột DỰ ÁN TÀU bên trái (Rộng 120px, dịch qua một khoảng left 80px của cột STT) */
        th:nth-child(2), td:nth-child(2) { position: sticky; left: 80px; width: 120px; min-width: 120px; max-width: 120px; z-index: 5; border-right: 2px solid var(--primary); }
        /* Cố định cột THAO TÁC bên phải cuối cùng (Rộng 100px) */
        th:last-child, td:last-child { position: sticky; right: 0; width: 100px; min-width: 100px; max-width: 100px; z-index: 5; border-left: 2px solid var(--primary); }
        
        /* Tạo nền vững chắc cho các ô Sticky để tránh lộ chữ khi cuộn */
        th:nth-child(1), th:nth-child(2), th:last-child { background: var(--primary); z-index: 12; }
        
        /* Đổ màu nền cho dòng dựa vào Trạng thái hoạt động tốt với Sticky */
        .status-chua-lam td { background-color: #ffc1cc; }
        .status-dang-lam td { background-color: #fff3cd; }
        .status-da-xong td { background-color: #d4edda; }
        
        /* Hiệu ứng di chuột và chọn dòng */
        tr:hover td { background-color: #e8f5e9 !important; cursor: pointer; }
        tr.selected td { background-color: #eceff1 !important; }
        
        /* Inline Edit mode styles */
        .edit-mode { display: none; width: 100%; box-sizing: border-box; }
        tr.editing .view-mode { display: none; }
        tr.editing .edit-mode { display: block; }
        tr.editing select.edit-mode { display: inline-block; padding: 4px; }
        tr.editing input.edit-mode { padding: 6px; }
        tr.editing textarea.edit-mode { display: block; width: 100%; box-sizing: border-box; resize: vertical; min-height: 65px; font-family: inherit; }
        
        .btn { padding: 6px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; color: #fff; text-decoration: none; text-align: center; display: inline-block; margin: 2px 0; }
        .btn-add { background: var(--primary); }
        .btn-edit { background: #ffa000; color: #000; }
        .btn-save { background: var(--primary); }
        .btn-del { background: #d32f2f; }
        .btn-cancel { background: #90a4ae; }
        .btn-search { background: var(--secondary); }
        .pagination { margin-top: 20px; text-align: center; }
        .pagination a { padding: 7px 12px; border: 1px solid var(--border); text-decoration: none; color: var(--primary); border-radius: 4px; margin: 0 2px; }
        .pagination a.active-page { background: var(--primary); color: #fff; border-color: var(--primary); }
        .action-flex { display: flex; flex-direction: column; gap: 4px; justify-content: center; align-items: center; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-flex">
        <h2>🚧 QUẢN LÝ TỒN ĐỘNG (REMAIN JOBS)</h2>
    </div>

    <div class="user-bar">
        <div class="user-info">Chào, <b><?=h($display_name)?></b></div>
        <div class="nav-links">
            <?php if ($is_logged_in): ?>
                <a href="logout.php?redirect=<?= urlencode($actual_link); ?>" class="logout-btn">Đăng xuất</a>
            <?php else: ?>
                <a href="login.php?redirect=<?= urlencode($actual_link); ?>" class="login-btn">Đăng nhập</a>
            <?php endif; ?>
            <a href="index.php" class="home-btn">🏠 Trang chủ</a>
        </div>
    </div>

    <?php 
    $user_ships = array_filter($ships_list, function($s) use ($is_admin, $user_fullname) {
        return $is_admin || $s['pic'] === $user_fullname;
    });
    if (!empty($user_ships)): 
    ?>
    <div class="admin-box">
        <form method="POST" action="remain-jobs.php?<?=$query_string?>">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group"><label>Dự án tàu (PIC)</label>
                    <select name="ship_id" required>
                        <option value="">--</option>
                        <?php foreach($user_ships as $s): ?>
                            <option value="<?=$s['id']?>"><?=$s['project_name']?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>BLOCK</label><input type="text" name="block_info"></div>
                <div class="form-group"><label>POR</label><input type="text" name="por_info"></div>
                <div class="form-group"><label>PART NO.</label><input type="text" name="part_no"></div>
                <div class="form-group" style="flex:2"><label>TÊN VẬT TƯ</label><input type="text" name="material_name" required></div>
                <div class="form-group"><label>S.L</label><input type="number" name="quantity" required></div>
            </div>
            <div class="form-row" style="margin-top:10px;">
                <div class="form-group"><label>Loại lỗi</label><select name="mistake_type"><?php foreach($mistake_list as $m): ?><option value="<?=$m?>"><?=$m?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Ngày vật tư về</label><input type="date" name="m_date"></div>
                <div class="form-group"><label>Ngày hoàn thành</label><input type="date" name="c_date"></div>
                <div class="form-group" style="flex:2"><label>Ghi chú</label><input type="text" name="notes"></div>
                <div class="form-group"><label>Trạng thái</label><select name="status"><option value="Chưa làm">Chưa làm</option><option value="Đang làm">Đang làm</option><option value="Đã xong">Đã xong</option></select></div>
                <button type="submit" class="btn btn-add" style="height:35px;">LƯU MỚI</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="search-box">
        <form method="GET" action="remain-jobs.php">
            <div class="form-row">
                <div class="form-group"><label>Lọc Tàu</label><select name="f_ship"><option value="">Tất cả</option><?php foreach($ships_list as $s): ?><option value="<?=$s['id']?>" <?=($f_ship==$s['id']?'selected':'')?>><?=$s['project_name']?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Lọc Lỗi</label><select name="f_mistake"><option value="">Tất cả</option><?php foreach($mistake_list as $m): ?><option value="<?=$m?>" <?=($f_mistake==$m?'selected':'')?>><?=$m?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Trạng thái</label><select name="f_status"><option value="">Tất cả</option><option value="Chưa làm" <?=($f_status=='Chưa làm'?'selected':'')?>>Chưa làm</option><option value="Đang làm" <?=($f_status=='Đang làm'?'selected':'')?>>Đang làm</option><option value="Đã xong" <?=($f_status=='Đã xong'?'selected':'')?>>Đã xong</option></select></div>
                <button type="submit" class="btn btn-search" style="height:35px;">TÌM KIẾM</button>
                <a href="remain-jobs.php" class="btn btn-cancel" style="line-height:19px;">LÀM MỚI</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table id="dataTable">
            <thead>
                <tr>
                    <th style="width: 80px;">STT</th>
                    <th style="width: 120px;">DỰ ÁN TÀU</th>
                    <th style="width: 80px;">BLOCK</th>
                    <th style="width: 80px;">POR</th>
                    <th style="width: 80px;">PART NO.</th>
                    <th style="width: 400px; text-align: left;">TÊN VẬT TƯ</th>
                    <th style="width: 80px;">S.L</th>
                    <th style="width: 100px;">LỖI DO</th>
                    <th style="width: 100px;">VẬT TƯ VỀ</th>
                    <th style="width: 100px;">NGÀY XONG</th>
                    <th style="width: 400px; text-align: left;">GHI CHÚ</th>
                    <th style="width: 100px;">TRẠNG THÁI</th>
                    <th style="width: 100px;">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php $stt = $start + 1; foreach($jobs as $j): 
                    $row_class = ($j['status'] == 'Chưa làm') ? 'status-chua-lam' : (($j['status'] == 'Đang làm') ? 'status-dang-lam' : 'status-da-xong');
                    $can_edit_row = ($is_admin || $j['pic'] === $user_fullname);
                ?>
                <tr id="row-<?=$j['id']?>" class="<?=$row_class?>" onclick="selectRow(this)">
                    <form method="POST" action="remain-jobs.php?<?=$query_string?>">
                        <input type="hidden" name="action" value="update_full">
                        <input type="hidden" name="id" value="<?=$j['id']?>">
                        
                        <td><?=$stt++?></td>
                        <td><?=h($j['project_name'])?></td>
                        <td><span class="view-mode"><?=h($j['block_info'])?></span><input type="text" name="block_info" class="edit-mode" value="<?=h($j['block_info'])?>"></td>
                        <td><span class="view-mode"><?=h($j['por_info'])?></span><input type="text" name="por_info" class="edit-mode" value="<?=h($j['por_info'])?>"></td>
                        <td><span class="view-mode"><?=h($j['part_no'])?></span><input type="text" name="part_no" class="edit-mode" value="<?=h($j['part_no'])?>"></td>
                        
                        <td style="text-align:left">
                            <span class="view-mode"><?=nl2br(h($j['material_name']))?></span>
                            <textarea name="material_name" class="edit-mode" rows="3" required><?=h($j['material_name'])?></textarea>
                        </td>
                        
                        <td><span class="view-mode"><?=$j['quantity']?></span><input type="number" name="quantity" class="edit-mode" value="<?=$j['quantity']?>"></td>
                        <td><span class="view-mode"><?=h($j['mistake_type'])?></span><select name="mistake_type" class="edit-mode"><?php foreach($mistake_list as $m): ?><option value="<?=$m?>" <?=($j['mistake_type']==$m?'selected':'')?>><?=$m?></option><?php endforeach; ?></select></td>
                        <td><span class="view-mode"><?=($j['material_arrival_date']?date('d/m/Y',strtotime($j['material_arrival_date'])):'-')?></span><input type="date" name="m_date" class="edit-mode" value="<?=$j['material_arrival_date']?>"></td>
                        <td><span class="view-mode"><?=($j['completion_date']?date('d/m/Y',strtotime($j['completion_date'])):'-')?></span><input type="date" name="c_date" class="edit-mode" value="<?=$j['completion_date']?>"></td>
                        
                        <td style="text-align:left">
                            <span class="view-mode"><?=nl2br(h($j['notes']))?></span>
                            <textarea name="notes" class="edit-mode" rows="3"><?=h($j['notes'])?></textarea>
                        </td>
                        
                        <td><span class="view-mode"><b><?=h($j['status'])?></b></span><select name="status" class="edit-mode"><option value="Chưa làm" <?=($j['status']=='Chưa làm'?'selected':'')?>>Chưa làm</option><option value="Đang làm" <?=($j['status']=='Đang làm'?'selected':'')?>>Đang làm</option><option value="Đã xong" <?=($j['status']=='Đã xong'?'selected':'')?>>Đã xong</option></select></td>
                        <td>
                            <div class="action-flex">
                                <?php if($can_edit_row): ?>
                                <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(<?=$j['id']?>, true)">Sửa</button>
                                <button type="submit" class="btn btn-save edit-mode">Lưu</button>
                                <button type="button" class="btn btn-cancel edit-mode" onclick="toggleEdit(<?=$j['id']?>, false)">Hủy</button>
                                <button type="button" class="btn btn-del view-mode" onclick="if(confirm('Xóa dữ liệu này?')) window.location.href='?del=<?=$j['id']?>&<?=$query_string?>'">Xóa</button>
                                <?php else: ?><span style="color:#999;font-size:10px;">Chỉ xem</span><?php endif; ?>
                            </div>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php for($i=1; $i<=$total_pages; $i++): ?>
            <a href="?page=<?=$i?>&f_ship=<?=$f_ship?>&f_mistake=<?=$f_mistake?>&f_status=<?=$f_status?>" class="<?=($page==$i?'active-page':'')?>"><?=$i?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function selectRow(row) {
    let rows = document.querySelectorAll('#dataTable tbody tr');
    rows.forEach(r => r.classList.remove('selected'));
    row.classList.add('selected');
}

function toggleEdit(id, isEdit) {
    const row = document.getElementById('row-' + id);
    if (isEdit) { row.classList.add('editing'); } 
    else { row.classList.remove('editing'); }
}
</script>
</body>
</html>
<?php include 'footer.php'; ?>
