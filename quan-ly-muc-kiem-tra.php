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

// Hàm kiểm tra quyền sở hữu tàu (PIC)
function can_modify_inspection($conn, $ship_id, $is_admin, $user_fullname) {
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
    
    // Nếu là update/delete, tìm ship_id từ bảng inspection_items trước
    if (in_array($_POST['action'], ['update', 'delete']) && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM inspection_items WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $target_ship_id = $stmt->fetchColumn();
    }

    if (can_modify_inspection($conn, $target_ship_id, $is_admin, $user_fullname)) {
        // THÊM MỚI
        if ($_POST['action'] == 'add') {
            $content = $_POST['content'];
            $check = $conn->prepare("SELECT id FROM inspection_items WHERE ship_id = ?");
            $check->execute([$target_ship_id]);
            if (!$check->fetch()) {
                $items = preg_split('/[\n,]+/', $content);
                $count = count(array_filter(array_map('trim', $items)));
                $sql = "INSERT INTO inspection_items (ship_id, content, item_count) VALUES (?, ?, ?)";
                $conn->prepare($sql)->execute([$target_ship_id, $content, $count]);
            }
        } 
        // CẬP NHẬT
        elseif ($_POST['action'] == 'update') {
            $id = $_POST['id'];
            $content = $_POST['content'];
            $items = preg_split('/[\n,]+/', $content);
            $count = count(array_filter(array_map('trim', $items)));
            
            $sql = "UPDATE inspection_items SET content = ?, item_count = ? WHERE id = ?";
            $conn->prepare($sql)->execute([$content, $count, $id]);
        }
        // XÓA
        elseif ($_POST['action'] == 'delete') {
            $id = $_POST['id'];
            $conn->prepare("DELETE FROM inspection_items WHERE id = ?")->execute([$id]);
        }
    }
    
    header("Location: quan-ly-muc-kiem-tra.php");
    exit();
}

// --- 2. LẤY DANH SÁCH TÀU CHƯA NHẬP MỤC KIỂM TRA (Lọc theo PIC nếu không phải Admin) ---
$ship_sql = "SELECT id, project_name FROM ships WHERE id NOT IN (SELECT ship_id FROM inspection_items)";
if (!$is_admin && $is_logged_in) {
    $ship_sql .= " AND pic = " . $conn->quote($user_fullname);
} elseif (!$is_logged_in) {
    $ship_sql .= " AND 1=0"; // Khách không thấy gì trong list thêm mới
}
$ship_sql .= " ORDER BY project_name ASC";
$available_ships = $conn->query($ship_sql)->fetchAll(PDO::FETCH_ASSOC);

// --- 3. LẤY DANH SÁCH DỮ LIỆU HIỂN THỊ ---
$sql_list = "SELECT ii.*, s.project_name, s.group_name, s.status, s.pic FROM inspection_items ii 
            JOIN ships s ON ii.ship_id = s.id 
            ORDER BY 
                (CASE 
                    WHEN s.status != 'Finished' AND ii.item_count BETWEEN 1 AND 2 THEN 1 
                    WHEN s.status != 'Finished' THEN 2 
                    ELSE 3 
                END) ASC, 
                s.project_name ASC";
$list = $conn->query($sql_list)->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 1400px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 20px; padding-bottom: 10px; }
    .header-section h2 { text-align: center; margin: 0 0 5px 0; color: #333; }
    .form-add { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .form-row { display: flex; flex-direction: column; gap: 15px; }
    .form-group { display: flex; flex-direction: column; flex: 1; }
    .form-group label { font-size: 11px; font-weight: bold; color: #555; margin-bottom: 5px; text-transform: uppercase; }
    textarea, select { padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; width: 100%; box-sizing: border-box; }
    .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: 0.3s; font-size: 12px; }
    .btn-add { background-color: #28a745; width: fit-content; align-self: flex-start; }
    .btn-edit { background-color: #ffc107; color: #000; }
    .btn-save { background-color: #28a745; color: white; }
    .btn-del { background-color: #dc3545; }
    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
    th { background-color: #28a745; color: white; padding: 12px; text-align: left; font-weight: bold; font-size: 13px; white-space: nowrap; }
    td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
    .row-yellow { background-color: #fff9c4 !important; }
    .row-pink { background-color: #ffc0cb !important; }
    .row-dimmed { opacity: 0.45; filter: grayscale(80%); }
    .row-highlight { background-color: #e2e3e5 !important; }
    .badge-count { background: rgba(0,0,0,0.05); padding: 4px 10px; border-radius: 12px; font-weight: bold; border: 1px solid rgba(0,0,0,0.1); }
    .edit-mode { display: none; }
</style>

<div class="container">
    <div class="header-section">
        <h2>✅ MỤC KIỂM TRA DỰ ÁN</h2>
        </div>

    <?php if (count($available_ships) > 0): ?>
    <div class="form-add">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div style="display:flex; gap: 20px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 250px;">
                        <label>Chọn Tàu được phân công (Chưa thiết lập):</label>
                        <select name="ship_id" required>
                            <option value="">-- Chọn tàu --</option>
                            <?php foreach ($available_ships as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['project_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 2; min-width: 300px;">
                        <label>Hạng mục kiểm tra (Dấu phẩy hoặc xuống dòng):</label>
                        <textarea name="content" rows="2" required placeholder="Ví dụ: Expansion joint aligment, Scubber..."></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-add">LƯU HẠNG MỤC MỚI</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th width="50" style="text-align:center">STT</th>
                    <th width="150">TÊN TÀU DỰ ÁN</th>
                    <th width="80">NHÓM</th>
                    <th>NỘI DUNG CHI TIẾT</th>
                    <th style="text-align:center" width="80">SỐ MỤC</th>
                    <th style="text-align:center" width="180">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($list) > 0): $stt = 1; ?>
                    <?php foreach ($list as $row): 
                        $trClass = '';
                        $isFinished = ($row['status'] == 'Finished');
                        if ($isFinished) { $trClass = 'row-pink row-dimmed'; } 
                        else {
                            if ($row['item_count'] >= 3) { $trClass = 'row-pink'; } 
                            elseif ($row['item_count'] >= 1 && $row['item_count'] <= 2) { $trClass = 'row-yellow'; }
                        }
                        $can_edit_row = ($is_admin || ($row['pic'] === $user_fullname));
                    ?>
                    <tr id="row-<?php echo $row['id']; ?>" class="<?php echo $trClass; ?>" onclick="highlightRow(this)">
                        <td style="text-align:center"><?php echo $stt++; ?></td>
                        <td><b><?php echo htmlspecialchars($row['project_name']); ?></b></td>
                        <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                        <td>
                            <span class="view-mode"><?php echo nl2br(htmlspecialchars($row['content'])); ?></span>
                            <?php if($can_edit_row): ?>
                            <textarea name="content" form="form-update-<?php echo $row['id']; ?>" class="edit-mode" rows="5" style="width:98%"><?php echo htmlspecialchars($row['content']); ?></textarea>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <span class="badge-count"><?php echo $row['item_count']; ?></span>
                        </td>
                        <td style="text-align:center">
                            <?php if($can_edit_row): ?>
                                <form id="form-update-<?php echo $row['id']; ?>" method="POST" style="display:none;">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                </form>

                                <?php if (!$isFinished): ?>
                                    <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(event, <?php echo $row['id']; ?>)">Sửa</button>
                                    <button type="submit" form="form-update-<?php echo $row['id']; ?>" class="btn btn-save edit-mode">Lưu</button>
                                    <button type="button" class="btn edit-mode" style="background:#6c757d;" onclick="location.reload()">Hủy</button>
                                <?php endif; ?>
                                
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xác nhận xóa?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn btn-del view-mode">Xóa</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 11px; color:#999;">Chỉ xem</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center">Chưa có dữ liệu.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
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

<?php include 'footer.php'; ?>
