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
function can_edit_comment($conn, $ship_id, $is_admin, $user_fullname) {
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

    if ($_POST['action'] == 'update_full' && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM ship_comments WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $target_ship_id = $stmt->fetchColumn();
    }

    if (can_edit_comment($conn, $target_ship_id, $is_admin, $user_fullname)) {
        if ($_POST['action'] == 'add') {
            $sql = "INSERT INTO ship_comments (ship_id, comment_no, location, content, notes, status, completion_date) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $target_ship_id, $_POST['comment_no'], $_POST['location'], 
                $_POST['content'], $_POST['notes'], $_POST['status'], $_POST['completion_date'] ?: null
            ]);
        } 
        elseif ($_POST['action'] == 'update_full') {
            $sql = "UPDATE ship_comments SET comment_no=?, location=?, content=?, notes=?, status=?, completion_date=? WHERE id=?";
            $conn->prepare($sql)->execute([
                $_POST['comment_no'], $_POST['location'], $_POST['content'], 
                $_POST['notes'], $_POST['status'], $_POST['completion_date'] ?: null, $_POST['id']
            ]);
        }
    }
    header("Location: comment.php" . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

if (isset($_GET['del'])) {
    $stmt = $conn->prepare("SELECT ship_id FROM ship_comments WHERE id = ?");
    $stmt->execute([$_GET['del']]);
    $target_ship_id = $stmt->fetchColumn();

    if (can_edit_comment($conn, $target_ship_id, $is_admin, $user_fullname)) {
        $conn->prepare("DELETE FROM ship_comments WHERE id = ?")->execute([$_GET['del']]);
    }
    header("Location: comment.php");
    exit();
}

// --- 2. XỬ LÝ TÌM KIẾM ---
$f_ship = $_GET['f_ship'] ?? '';
$f_status = $_GET['f_status'] ?? '';

$where = ["1=1"]; $params = [];
if ($f_ship != '') { $where[] = "sc.ship_id = ?"; $params[] = $f_ship; }
if ($f_status != '') { $where[] = "sc.status = ?"; $params[] = $f_status; }
$where_sql = implode(" AND ", $where);

// --- 3. PHÂN TRANG ---
$limit = 50;
$page = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;
$start = ($page - 1) * $limit;

$total_rows = $conn->prepare("SELECT COUNT(*) FROM ship_comments sc WHERE $where_sql");
$total_rows->execute($params);
$total_pages = ceil($total_rows->fetchColumn() / $limit);

// --- 4. TRUY VẤN DỮ LIỆU ---
$sql = "SELECT sc.*, s.project_name, s.pic FROM ship_comments sc 
        JOIN ships s ON sc.ship_id = s.id 
        WHERE $where_sql ORDER BY sc.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql); $stmt->execute($params);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ships_list = $conn->query("SELECT id, project_name, pic FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 10px; font-size: 13px; }
    .container { max-width: 1650px; margin: auto; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .header-flex { border-bottom: 2px solid #673ab7; margin-bottom: 20px; padding-bottom: 10px; }
    .admin-box { background: #f3e5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #d1c4e9; }
    .search-box { background: #e8eaf6; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .form-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; flex: 1; min-width: 110px; }
    .form-group label { font-size: 11px; font-weight: bold; margin-bottom: 3px; }
    input, select, textarea { padding: 7px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; font-family: inherit; }
    textarea { resize: vertical; }
    .full-width { flex: 0 0 100% !important; margin-top: 5px; }
    .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 11px; display: inline-block; text-align: center; white-space: nowrap; }
    .btn-save { background: #2e7d32; color: white; height: 32px; padding: 0 15px; }
    .btn-edit { background: #ffc107; }
    .btn-del { background: #c62828; color: white; }
    .btn-refresh { background: #757575; color: white; text-decoration: none; height: 18px; line-height: 18px; padding: 7px 10px; font-size: 10px; }
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; background: white; min-width: 1100px; } 
    th { background: #673ab7; color: white; padding: 10px; border: 1px solid #ddd; font-size: 12px; }
    td { padding: 8px; border: 1px solid #ddd; text-align: center; word-wrap: break-word; vertical-align: middle; } 
    
    .status-chua-lam { background-color: #ffcdd2 !important; } 
    .status-dang-lam { background-color: #fff9c4 !important; } 
    .status-da-xong { background-color: #c8e6c9 !important; }  
    .view-mode { display: block; }
    .edit-mode { display: none; width: 100%; box-sizing: border-box; }
    .active-page { background: #673ab7; color: white; border-color: #673ab7; }
    
    .col-stt { width: 40px; }
    .col-tau { width: 80px; }
    .col-no { width: 80px; }
    .col-vitri { width: 100px; }
    .col-content, .col-notes { width: 25%; min-width: 280px; text-align: left; }
    .col-status { width: 100px; }
    .col-date { width: 90px; }
    .col-action { width: 100px; }
    
    @media screen and (max-width: 650px) {
        .form-group { flex: 0 0 calc(50% - 4px); min-width: 0; }
    }
</style>

<div class="container">
    <div class="header-flex">
        <h2 style="font-size: 17px; margin:0">💬 QUẢN LÝ COMMENT</h2>
    </div>
    <?php 
    $user_ships = array_filter($ships_list, function($s) use ($is_admin, $user_fullname) {
        return $is_admin || $s['pic'] === $user_fullname;
    });
    if (!empty($user_ships)): 
    ?>
    <div class="admin-box">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group"><label>Chọn tàu (PIC)</label>
                    <select name="ship_id" required><option value="">--</option>
                        <?php foreach($user_ships as $s): ?><option value="<?=$s['id']?>"><?=$s['project_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>COM. NO.</label><input type="text" name="comment_no"></div>
                <div class="form-group"><label>VỊ TRÍ</label><input type="text" name="location"></div>
                <div class="form-group"><label>Trạng thái</label>
                    <select name="status">
                        <option value="Chưa làm">Chưa làm</option>
                        <option value="Đang làm">Đang làm</option>
                        <option value="Đã xong">Đã xong</option>
                    </select>
                </div>
                <div class="form-group"><label>Ngày xong</label><input type="date" name="completion_date"></div>
                <div class="form-group" style="flex:3"><label>NỘI DUNG COMMENT</label>
                    <textarea name="content" rows="3" required></textarea>
                </div>
                <div class="form-group" id="notes_container_add" class="form-group full-width"><label>GHI CHÚ</label><input type="text" name="notes"></div>
                <div style="width: 100%; display: flex; justify-content: flex-start;">
                    <button type="submit" class="btn btn-save">LƯU MỚI</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="search-box">
        <form method="GET">
            <div class="form-row">
                <div class="form-group"><label>Lọc tàu</label>
                    <select name="f_ship"><option value="">Tất cả</option>
                        <?php foreach($ships_list as $s): ?><option value="<?=$s['id']?>" <?=($f_ship==$s['id']?'selected':'')?>><?=$s['project_name']?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Trạng thái</label>
                    <select name="f_status"><option value="">Tất cả</option>
                        <option value="Chưa làm" <?=($f_status=='Chưa làm'?'selected':'')?>>Chưa làm</option>
                        <option value="Đang làm" <?=($f_status=='Đang làm'?'selected':'')?>>Đang làm</option>
                        <option value="Đã xong" <?=($f_status=='Đã xong'?'selected':'')?>>Đã xong</option>
                    </select>
                </div>
                <div class="search-btn-group">
                    <button type="submit" class="btn" style="background:#673ab7; color:white; height:32px">TÌM KIẾM</button>
                    <a href="comment.php" class="btn btn-refresh">LÀM MỚI</a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="col-stt">STT</th>
                    <th class="col-tau">TÀU</th>
                    <th class="col-no">COM. NO.</th>
                    <th class="col-vitri">VỊ TRÍ</th>
                    <th class="col-content">NỘI DUNG COMMENT</th>
                    <th class="col-notes">GHI CHÚ</th>
                    <th class="col-status">TRẠNG THÁI</th>
                    <th class="col-date">NGÀY XONG</th>
                    <th class="col-action">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php $stt = $start + 1; foreach($comments as $c): 
                    $row_class = ($c['status'] == 'Chưa làm') ? 'status-chua-lam' : (($c['status'] == 'Đang làm') ? 'status-dang-lam' : 'status-da-xong');
                    $can_edit_row = ($is_admin || $c['pic'] === $user_fullname);
                ?>
                <tr id="row-<?=$c['id']?>" class="<?=$row_class?>">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_full">
                        <input type="hidden" name="id" value="<?=$c['id']?>">
                        <td><?=$stt++?></td>
                        <td><b><?=$c['project_name']?></b></td>
                        <td><span class="view-mode"><?=h($c['comment_no'])?></span><input type="text" name="comment_no" class="edit-mode" value="<?=h($c['comment_no'])?>"></td>
                        <td><span class="view-mode"><?=h($c['location'])?></span><input type="text" name="location" class="edit-mode" value="<?=h($c['location'])?>"></td>
                        
                        <td class="col-content">
                            <div class="view-mode" style="font-weight:bold; white-space: pre-wrap; text-align: left;">
                                <?=h($c['content'])?>
                            </div>
                            <textarea name="content" class="edit-mode" rows="4"><?=h($c['content'])?></textarea>
                        </td>
                        
                        <td class="col-notes">
                            <div class="view-mode" style="font-style:italic; white-space: pre-wrap; text-align: left;">
                                <?=h($c['notes'])?>
                            </div>
                            <textarea name="notes" class="edit-mode" rows="4"><?=h($c['notes'])?></textarea>
                        </td>

                        <td>
                            <span class="view-mode"><b><?=$c['status']?></b></span>
                            <select name="status" class="edit-mode">
                                <option value="Chưa làm" <?=($c['status']=='Chưa làm'?'selected':'')?>>Chưa làm</option>
                                <option value="Đang làm" <?=($c['status']=='Đang làm'?'selected':'')?>>Đang làm</option>
                                <option value="Đã xong" <?=($c['status']=='Đã xong'?'selected':'')?>>Đã xong</option>
                            </select>
                        </td>
                        <td>
                            <span class="view-mode"><?=($c['completion_date']?date('d/m/Y', strtotime($c['completion_date'])):'-')?></span>
                            <input type="date" name="completion_date" class="edit-mode" value="<?=$c['completion_date']?>">
                        </td>
                        <td>
                            <?php if($can_edit_row): ?>
                            <div style="display:flex; gap:3px; justify-content:center">
                                <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(<?=$c['id']?>)">Sửa</button>
                                <button type="submit" class="btn btn-save edit-mode" style="height:auto">Lưu</button>
                                <button type="button" class="btn edit-mode" style="background:#757575; color:white" onclick="cancelEdit(<?=$c['id']?>)">Hủy</button>
                                <button type="button" class="btn btn-del view-mode" onclick="if(confirm('Xóa?')) { window.location.href='?del=<?=$c['id']?>'; }">Xóa</button>
                            </div>
                            <?php else: ?>
                                <span style="color:#999; font-size:10px;">Chỉ xem</span>
                            <?php endif; ?>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="text-align:center; margin-top:20px;">
        <?php for($i=1; $i<=$total_pages; $i++): ?>
            <a href="?page=<?=$i?>&f_ship=<?=$f_ship?>&f_status=<?=$f_status?>" style="padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; margin: 0 2px; border-radius: 4px; display:inline-block;" class="<?=($page==$i?'active-page':'')?>"><?=$i?></a>
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
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'block');
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'none');
}
</script>

</body>
</html>
<?php include 'footer.php'; ?>
