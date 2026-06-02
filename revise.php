<?php
session_start();
require_once 'db_connect.php';

// --- 0. XỬ LÝ REDIRECT & USER TRANG HIỆN TẠI ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$actual_link = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$is_logged_in = isset($_SESSION['user']) && !empty($_SESSION['user']);
$is_admin = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$display_name = $is_logged_in ? (!empty($_SESSION['fullname']) ? $_SESSION['fullname'] : $_SESSION['user']) : 'Khách';
$user_fullname = $_SESSION['fullname'] ?? '';

// Hàm bảo mật XSS
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// Hàm kiểm tra quyền thao tác dựa trên PIC
function can_edit_revise($conn, $ship_id, $is_admin, $user_fullname) {
    if ($is_admin) return true;
    if (empty($user_fullname) || empty($ship_id)) return false;
    $stmt = $conn->prepare("SELECT pic FROM ships WHERE id = ?");
    $stmt->execute([$ship_id]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($ship && $ship['pic'] === $user_fullname);
}

// Lấy các tham số lọc hiện tại từ URL để tái sử dụng khi Redirect
$f_ship = $_GET['f_ship'] ?? '';
$f_status = $_GET['f_status'] ?? '';
$page = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;

$filter_query = "page=$page&f_ship=" . urlencode($f_ship) . "&f_status=" . urlencode($f_status);

// --- 1. XỬ LÝ DỮ LIỆU ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $target_ship_id = $_POST['ship_id'] ?? null;

    if ($_POST['action'] == 'update_full' && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM ship_revises WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $target_ship_id = $stmt->fetchColumn();
    }

    if (can_edit_revise($conn, $target_ship_id, $is_admin, $user_fullname)) {
        if ($_POST['action'] == 'add') {
            $sql = "INSERT INTO ship_revises (ship_id, revise_no, location, content, notes, status, completion_date) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $target_ship_id, $_POST['revise_no'], $_POST['location'], 
                $_POST['content'], $_POST['notes'], $_POST['status'], $_POST['completion_date'] ?: null
            ]);
        } 
        elseif ($_POST['action'] == 'update_full') {
            $sql = "UPDATE ship_revises SET revise_no=?, location=?, content=?, notes=?, status=?, completion_date=? WHERE id=?";
            $conn->prepare($sql)->execute([
                $_POST['revise_no'], $_POST['location'], $_POST['content'], 
                $_POST['notes'], $_POST['status'], $_POST['completion_date'] ?: null, $_POST['id']
            ]);
        }
    }
    // Chuyển hướng giữ nguyên bộ lọc
    header("Location: revise.php?" . $filter_query);
    exit();
}

// Xử lý Xóa
if (isset($_GET['del'])) {
    $stmt = $conn->prepare("SELECT ship_id FROM ship_revises WHERE id = ?");
    $stmt->execute([$_GET['del']]);
    $target_ship_id = $stmt->fetchColumn();

    if (can_edit_revise($conn, $target_ship_id, $is_admin, $user_fullname)) {
        $conn->prepare("DELETE FROM ship_revises WHERE id = ?")->execute([$_GET['del']]);
    }
    // Chuyển hướng giữ nguyên bộ lọc
    header("Location: revise.php?" . $filter_query);
    exit();
}

// --- 2. XỬ LÝ TÌM KIẾM ---
$where = ["1=1"]; $params = [];
if ($f_ship != '') { $where[] = "sr.ship_id = ?"; $params[] = $f_ship; }
if ($f_status != '') { $where[] = "sr.status = ?"; $params[] = $f_status; }
$where_sql = implode(" AND ", $where);

// --- 3. PHÂN TRANG ---
$limit = 50;
$start = ($page - 1) * $limit;

$total_rows = $conn->prepare("SELECT COUNT(*) FROM ship_revises sr WHERE $where_sql");
$total_rows->execute($params);
$total_pages = ceil($total_rows->fetchColumn() / $limit);

// --- 4. TRUY VẤN DỮ LIỆU ---
$sql = "SELECT sr.*, s.project_name, s.pic FROM ship_revises sr 
        JOIN ships s ON sr.ship_id = s.id 
        WHERE $where_sql ORDER BY sr.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql); $stmt->execute($params);
$revises = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ships_list = $conn->query("SELECT id, project_name, pic FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    :root { --primary-color: #2e7d32; --light-bg: #e8f5e9; --border-color: #c8e6c9; }
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f4f0; margin: 0; padding: 10px; font-size: 13px; color: #333; }
    .container { max-width: 100%; margin: auto; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .header-flex { border-bottom: 2px solid var(--primary-color); margin-bottom: 20px; padding-bottom: 10px; }
    .header-flex h2 { margin: 0; color: var(--primary-color); font-size: 18px; text-transform: uppercase; text-align: center; }
    .admin-box { background: var(--light-bg); padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid var(--border-color); }
    .search-box { background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #eee; }
    .form-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
    .form-row .btn { margin-bottom: 2px; }
    .form-group { display: flex; flex-direction: column; flex: 1; min-width: 120px; }
    @media (max-width: 600px) { .form-group { min-width: 100% !important; } }
    .form-group label { font-size: 11px; font-weight: bold; margin-bottom: 4px; color: #555; }
    input, select, textarea { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; font-family: inherit; }
    
    /* Cấu hình bảng */
    .table-responsive { width: 100%; overflow-x: auto; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px; }
    table { width: 100%; border-collapse: collapse; min-width: 1200px; background: white; table-layout: fixed; }
    th { background: var(--primary-color); color: white; padding: 12px 8px; border: 1px solid #ddd; font-size: 12px; }
    td { padding: 10px 8px; border: 1px solid #ddd; text-align: center; word-wrap: break-word; vertical-align: middle; }
    
    /* Độ rộng cột */
    .col-stt { width: 45px; } 
    .col-tau { width: 110px; } 
    .col-no { width: 80px; } 
    .col-vitri { width: 110px; } 
    .col-content { width: auto; } 
    .col-notes { width: auto; }    
    .col-status { width: 110px; } 
    .col-date { width: 100px; } 
    .col-action { width: 130px; }

    /* Style cho Textarea trong bảng */
    textarea.edit-mode { 
        resize: vertical; 
        min-height: 40px;
        line-height: 1.4;
    }

    /* Đáp ứng số dòng theo màn hình */
    @media (min-width: 1025px) {
        textarea.edit-mode { height: 50px; }
    }
    @media (max-width: 1024px) {
        textarea.edit-mode { height: 75px; }
    }

    .status-chua-lam { background-color: #ffc1cc; } 
    .status-dang-lam { background-color: #fff3cd; } 
    .status-da-xong { background-color: #d4edda; }  
    .view-mode { display: block; }
    .edit-mode { display: none; }
    .btn { padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; }
    .btn-save { background: var(--primary-color); color: white; }
    .btn-edit { background: #ffa000; color: white; }
    .btn-del { background: #d32f2f; color: white; }
    .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; margin: 0 2px; border-radius: 4px; display: inline-block; color: #333; }
    .active-page { background: var(--primary-color) !important; color: white !important; }
    tr.selected { background-color: #f1f1f1 !important; }
</style>

<div class="container">
    <div class="header-flex">
        <h2>🔄 HỆ THỐNG QUẢN LÝ REVISE</h2>
    </div>
    <?php 
    $user_ships = array_filter($ships_list, function($s) use ($is_admin, $user_fullname) {
        return $is_admin || $s['pic'] === $user_fullname;
    });
    if (!empty($user_ships)): 
    ?>
    <div class="admin-box">
        <form method="POST" action="revise.php?<?= htmlspecialchars($filter_query) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group" style="flex: 1.5;"><label>CHỌN TÀU (PIC)</label>
                    <select name="ship_id" required><option value="">--</option>
                        <?php foreach($user_ships as $s): ?><option value="<?=$s['id']?>"><?=$s['project_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>REVISE NO.</label><input type="text" name="revise_no"></div>
                <div class="form-group"><label>VỊ TRÍ</label><input type="text" name="location"></div>
                <div class="form-group" style="flex: 3;"><label>NỘI DUNG REVISE</label><textarea name="content" required rows="2"></textarea></div>
                <div class="form-group" style="flex: 1.5;"><label>GHI CHÚ</label><textarea name="notes" rows="2"></textarea></div>
                <div class="form-group"><label>TRẠNG THÁI</label>
                    <select name="status">
                        <option value="Chưa làm">Chưa làm</option>
                        <option value="Đang làm">Đang làm</option>
                        <option value="Đã xong">Đã xong</option>
                    </select>
                </div>
                <div class="form-group"><label>NGÀY XONG</label><input type="date" name="completion_date"></div>
                <button type="submit" class="btn btn-save" style="height:35px">LƯU MỚI</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="search-box">
        <form method="GET" action="revise.php">
            <div class="form-row">
                <div class="form-group"><label>LỌC THEO TÀU</label>
                    <select name="f_ship"><option value="">Tất cả tàu</option>
                        <?php foreach($ships_list as $s): ?><option value="<?=$s['id']?>" <?=($f_ship==$s['id']?'selected':'')?>><?=$s['project_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>TRẠNG THÁI</label>
                    <select name="f_status"><option value="">Tất cả</option>
                        <option value="Chưa làm" <?=($f_status=='Chưa làm'?'selected':'')?>>Chưa làm</option>
                        <option value="Đang làm" <?=($f_status=='Đang làm'?'selected':'')?>>Đang làm</option>
                        <option value="Đã xong" <?=($f_status=='Đã xong'?'selected':'')?>>Đã xong</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-save" style="height:35px; background:#555">TÌM KIẾM</button>
                <a href="revise.php" class="btn" style="background:#bbb; color:white; text-decoration:none; height:19px; line-height:19px; display:inline-block; padding: 8px 12px; border-radius: 4px;">LÀM MỚI</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="col-stt">STT</th>
                    <th class="col-tau">TÀU</th>
                    <th class="col-no">REV. NO.</th>
                    <th class="col-vitri">VỊ TRÍ</th>
                    <th class="col-content">NỘI DUNG REVISE</th>
                    <th class="col-notes">GHI CHÚ</th>
                    <th class="col-status">TRẠNG THÁI</th>
                    <th class="col-date">NGÀY XONG</th>
                    <th class="col-action">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php $stt = $start + 1; foreach($revises as $r): 
                    $row_class = ($r['status'] == 'Chưa làm') ? 'status-chua-lam' : (($r['status'] == 'Đang làm') ? 'status-dang-lam' : 'status-da-xong');
                    $can_edit_row = ($is_admin || $r['pic'] === $user_fullname);
                ?>
                <tr id="row-<?=$r['id']?>" class="<?=$row_class?>" onclick="selectRow(this)">
                    <form method="POST" action="revise.php?<?= htmlspecialchars($filter_query) ?>">
                        <input type="hidden" name="action" value="update_full">
                        <input type="hidden" name="id" value="<?=$r['id']?>">
                        <td><?=$stt++?></td>
                        <td style="font-weight:bold"><?=$r['project_name']?></td>
                        <td><span class="view-mode"><?=$r['revise_no']?></span><input type="text" name="revise_no" class="edit-mode" value="<?=$r['revise_no']?>"></td>
                        <td><span class="view-mode"><?=$r['location']?></span><input type="text" name="location" class="edit-mode" value="<?=$r['location']?>"></td>
                        <td style="text-align:left">
                            <span class="view-mode"><?=nl2br(htmlspecialchars($r['content']))?></span>
                            <textarea name="content" class="edit-mode" required><?=htmlspecialchars($r['content'])?></textarea>
                        </td>
                        <td style="text-align:left">
                            <span class="view-mode"><i><?=nl2br(htmlspecialchars($r['notes']))?></i></span>
                            <textarea name="notes" class="edit-mode"><?=htmlspecialchars($r['notes'])?></textarea>
                        </td>
                        <td>
                            <span class="view-mode"><b><?=$r['status']?></b></span>
                            <select name="status" class="edit-mode">
                                <option value="Chưa làm" <?=($r['status']=='Chưa làm'?'selected':'')?>>Chưa làm</option>
                                <option value="Đang làm" <?=($r['status']=='Đang làm'?'selected':'')?>>Đang làm</option>
                                <option value="Đã xong" <?=($r['status']=='Đã xong'?'selected':'')?>>Đã xong</option>
                            </select>
                        </td>
                        <td><span class="view-mode"><?=($r['completion_date']?date('d/m/Y', strtotime($r['completion_date'])):'-')?></span><input type="date" name="completion_date" class="edit-mode" value="<?=$r['completion_date']?>"></td>
                        <td>
                            <?php if($can_edit_row): ?>
                            <div style="display:flex; gap:4px; justify-content:center">
                                <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(<?=$r['id']?>)">Sửa</button>
                                <button type="submit" class="btn btn-save edit-mode">Lưu</button>
                                <button type="button" class="btn edit-mode" style="background:#999; color:white" onclick="cancelEdit(<?=$r['id']?>)">Hủy</button>
                                <button type="button" class="btn btn-del view-mode" onclick="if(confirm('Xóa bản ghi này?')) { window.location.href='?del=<?=$r['id']?>&<?=htmlspecialchars($filter_query)?>'; }">Xóa</button>
                            </div>
                            <?php else: ?><span style="color:#999; font-size:10px;">Chỉ xem</span><?php endif; ?>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination" style="text-align:center; margin-top:20px;">
        <?php for($i=1; $i<=$total_pages; $i++): ?>
            <a href="?page=<?=$i?>&f_ship=<?=urlencode($f_ship)?>&f_status=<?=urlencode($f_status)?>" class="<?=($page==$i?'active-page':'')?>"><?=$i?></a>
        <?php endfor; ?>
    </div>
</div>

<script>
function toggleEdit(id) {
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'block');
}
function cancelEdit(id) {
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => {
        if (!el.classList.contains('btn-edit') && !el.classList.contains('btn-del')) el.style.display = 'block';
        else el.style.display = 'inline-block';
    });
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'none');
}
function selectRow(row) {
    document.querySelectorAll('tbody tr').forEach(r => r.classList.remove('selected'));
    row.classList.add('selected');
}
</script>
<?php include 'footer.php'; ?>
