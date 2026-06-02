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

// Lấy URL hiện tại cho nút đăng nhập/đăng xuất
$current_url = basename($_SERVER['PHP_SELF']);
if (!empty($_SERVER['QUERY_STRING'])) {
    $current_url .= '?' . $_SERVER['QUERY_STRING'];
}

$error_message = "";

// Hàm kiểm tra quyền thao tác trên tiến độ tàu (PIC)
function can_modify_progress($conn, $ship_id, $is_admin, $user_fullname) {
    if ($is_admin) return true;
    if (empty($user_fullname) || empty($ship_id)) return false;
    $stmt = $conn->prepare("SELECT pic FROM ships WHERE id = ?");
    $stmt->execute([$ship_id]);
    $ship = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($ship && $ship['pic'] === $user_fullname);
}

// --- 2. XỬ LÝ DỮ LIỆU ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $target_ship_id = $_POST['ship_id'] ?? null;
    
    // Nếu là update/delete, tìm ship_id từ progress_id trước
    if (in_array($_POST['action'], ['update_progress', 'delete_progress']) && isset($_POST['id'])) {
        $stmt = $conn->prepare("SELECT ship_id FROM ship_progress WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $target_ship_id = $stmt->fetchColumn();
    }

    if (can_modify_progress($conn, $target_ship_id, $is_admin, $user_fullname)) {
        if ($_POST['action'] == 'add_progress') {
            $percent = $_POST['progress_percent'];

            $check = $conn->prepare("SELECT COUNT(*) FROM ship_progress WHERE ship_id = ?");
            $check->execute([$target_ship_id]);
            if ($check->fetchColumn() > 0) {
                $error_message = "Lỗi: Tàu này đã được nhập tiến độ rồi!";
            } else {
                $sql = "INSERT INTO ship_progress (ship_id, progress_percent) VALUES (?, ?)";
                $conn->prepare($sql)->execute([$target_ship_id, $percent]);
                header("Location: quan-ly-tien-do.php");
                exit();
            }
        } 
        elseif ($_POST['action'] == 'update_progress') {
            $id = $_POST['id'];
            $percent = $_POST['progress_percent'];
            $sql = "UPDATE ship_progress SET progress_percent = ? WHERE id = ?";
            $conn->prepare($sql)->execute([$percent, $id]);
            header("Location: quan-ly-tien-do.php");
            exit();
        }
        elseif ($_POST['action'] == 'delete_progress') {
            $id = $_POST['id'];
            $conn->prepare("DELETE FROM ship_progress WHERE id = ?")->execute([$id]);
            header("Location: quan-ly-tien-do.php");
            exit();
        }
    }
}

// --- 3. LẤY DANH SÁCH TÀU CHƯA NHẬP TIẾN ĐỘ (Lọc theo PIC nếu không phải Admin) ---
$ship_sql = "SELECT id, project_name FROM ships 
             WHERE status = 'Đang thi công' 
             AND id NOT IN (SELECT ship_id FROM ship_progress)";
if (!$is_admin && $is_logged_in) {
    $ship_sql .= " AND pic = " . $conn->quote($user_fullname);
} elseif (!$is_logged_in) {
    $ship_sql .= " AND 1=0"; // Khách không thấy list thêm mới
}
$ship_sql .= " ORDER BY project_name ASC";
$available_ships = $conn->query($ship_sql)->fetchAll(PDO::FETCH_ASSOC);

// --- 4. PHÂN TRANG ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$total_rows = $conn->query("SELECT COUNT(*) FROM ship_progress")->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// LẤY DANH SÁCH TIẾN ĐỘ CÓ PHÂN TRANG
$sql_list = "SELECT sp.*, s.project_name, s.status, s.pic FROM ship_progress sp 
             JOIN ships s ON sp.ship_id = s.id 
             ORDER BY CASE WHEN s.status = 'Đang thi công' THEN 1 ELSE 2 END ASC, s.project_name ASC 
             LIMIT $limit OFFSET $offset";
$progress_list = $conn->query($sql_list)->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 1400px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 20px; padding-bottom: 10px; }
    .header-section h2 { margin: 0 0 5px 0; color: #333; text-align: center; }
    .alert-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 15px; border: 1px solid #f5c6cb; font-weight: bold; }
    .form-add { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; flex: 1 1 200px; }
    .form-group label { font-size: 11px; font-weight: bold; color: #555; margin-bottom: 3px; text-transform: uppercase; }
    input, select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: 0.3s; }
    .btn-add { background-color: #28a745; min-width: 120px; }
    .btn-edit { background-color: #ffc107; color: #000; }
    .btn-save { background-color: #007bff; display: none; }
    .btn-delete { background-color: #dc3545; }
    .btn-cancel { background-color: #6c757d; display: none; }
    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th { background-color: #28a745; color: white; padding: 12px; text-align: left; font-weight: bold; font-size: 13px; white-space: nowrap; }
    td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; cursor: pointer; }
    .status-delivered { opacity: 0.5; background-color: #f8f9fa !important; }
    .row-highlight { background-color: #e2e3e5 !important; }
    .progress-container { width: 100%; max-width: 200px; background: #eee; border-radius: 10px; height: 10px; position: relative; margin-top: 5px; }
    .progress-fill { background: #28a745; height: 100%; border-radius: 10px; transition: width 0.5s; }
    .view-mode { display: block; }
    .edit-mode { display: none; }
    .pagination { margin-top: 20px; display: flex; justify-content: center; gap: 5px; }
    .pagination a { padding: 8px 12px; border: 1px solid #ddd; text-decoration: none; color: #333; border-radius: 4px; }
    .pagination a.active { background-color: #28a745; color: white; border-color: #28a745; }
    @media (max-width: 600px) { .form-group { min-width: 45%; } .btn-add { width: 100%; } }
</style>

<div class="container">
    <div class="header-section">
        <h2>📈 QUẢN LÝ TIẾN ĐỘ TÀU</h2>
    </div>

    <?php if ($error_message): ?>
        <div class="alert-error"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if (count($available_ships) > 0): ?>
    <div class="form-add">
        <form method="POST">
            <input type="hidden" name="action" value="add_progress">
            <div class="form-row">
                <div class="form-group">
                    <label>Chọn Tàu được phân công</label>
                    <select name="ship_id" required>
                        <option value="">-- Chọn tên tàu --</option>
                        <?php foreach ($available_ships as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['project_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tiến độ (%)</label>
                    <input type="number" name="progress_percent" min="0" max="100" required placeholder="0-100">
                </div>
                <button type="submit" class="btn btn-add">LƯU DỮ LIỆU</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th width="40">STT</th>
                    <th width="200">Tên tàu dự án</th>
                    <th>Tiến độ thực tế</th>
                    <th style="text-align:center" width="180">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($progress_list) > 0): $stt = $offset + 1; ?>
                    <?php foreach ($progress_list as $row): 
                        $deliveredClass = ($row['status'] == 'Đã bàn giao') ? 'status-delivered' : '';
                        $can_edit_row = ($is_admin || ($row['pic'] === $user_fullname));
                    ?>
                    <tr id="row-<?php echo $row['id']; ?>" class="<?php echo $deliveredClass; ?>" onclick="highlightRow(this)">
                        <td><?php echo $stt++; ?></td>
                        <td>
                            <b><?php echo htmlspecialchars($row['project_name']); ?></b>
                            <?php if($row['status'] == 'Đã bàn giao') echo '<br><small style="color:#888;">(Đã bàn giao)</small>'; ?>
                        </td>
                        <td>
                            <div class="view-mode">
                                <strong><?php echo $row['progress_percent']; ?>%</strong>
                                <div class="progress-container">
                                    <div class="progress-fill" style="width: <?php echo $row['progress_percent']; ?>%;"></div>
                                </div>
                            </div>
                            <?php if($can_edit_row): ?>
                            <form method="POST" class="edit-mode" id="form-<?php echo $row['id']; ?>">
                                <input type="hidden" name="action" value="update_progress">
                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <input type="number" name="progress_percent" value="<?php echo $row['progress_percent']; ?>" min="0" max="100" style="width:60px; padding: 5px;"> <b>%</b>
                                </div>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center; white-space: nowrap;">
                            <?php if($can_edit_row): ?>
                                <button type="button" class="btn btn-edit" onclick="enableEdit(event, <?php echo $row['id']; ?>)">Sửa</button>
                                <button type="submit" form="form-<?php echo $row['id']; ?>" class="btn btn-save">Lưu</button>
                                <button type="button" class="btn btn-cancel" onclick="disableEdit(event)">Hủy</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa tiến độ tàu này?')">
                                    <input type="hidden" name="action" value="delete_progress">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn btn-delete">Xóa</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 11px; color:#999;">Chỉ xem</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center">Dữ liệu trống.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>" class="<?php echo ($page == $i) ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function highlightRow(row) {
    document.querySelectorAll('tbody tr').forEach(r => r.classList.remove('row-highlight'));
    row.classList.add('row-highlight');
}
function enableEdit(event, id) {
    event.stopPropagation();
    var row = document.getElementById('row-' + id);
    row.querySelectorAll('.view-mode').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.edit-mode').forEach(el => el.style.display = 'block');
    row.querySelector('.btn-edit').style.display = 'none';
    row.querySelector('.btn-delete').style.display = 'none';
    row.querySelector('.btn-save').style.display = 'inline-block';
    row.querySelector('.btn-cancel').style.display = 'inline-block';
}
function disableEdit(event) {
    event.stopPropagation();
    location.reload();
}
</script>
<?php include 'footer.php'; ?>
