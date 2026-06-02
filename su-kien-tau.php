<?php
session_start();
require_once 'db_connect.php';

// --- 1. XỬ LÝ ĐĂNG NHẬP / PHÂN QUYỀN ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$actual_link = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$display_name = '';
$is_logged_in = false;
$is_admin = false;
$user_fullname = '';

if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
    $is_logged_in = true;
    $user_fullname = isset($_SESSION['fullname']) ? $_SESSION['fullname'] : '';
    $display_name = !empty($user_fullname) ? $user_fullname : $_SESSION['user'];
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
        $is_admin = true;
    }
}

// --- 2. LẤY DANH SÁCH TÀU (Để kiểm tra quyền PIC) ---
$ships_query = $conn->query("SELECT id, project_name, status, pic FROM ships WHERE status IN ('Đang thi công', 'Chưa thi công') ORDER BY status DESC, project_name ASC");
$ships_list = $ships_query->fetchAll(PDO::FETCH_ASSOC);

// --- 3. XỬ LÝ CHỌN TÀU & KIỂM TRA QUYỀN PIC ---
$selected_ship = isset($_GET['ship_id']) ? $_GET['ship_id'] : '';
$can_edit = false; // Mặc định không có quyền sửa
$selected_ship_name = '';

if ($is_admin) {
    $can_edit = true; // Admin luôn có quyền
}

foreach ($ships_list as $s) {
    if ($s['id'] == $selected_ship) {
        $selected_ship_name = $s['project_name'];
        // Nếu user hiện tại là người phụ trách tàu này
        if ($is_logged_in && $user_fullname === $s['pic']) {
            $can_edit = true;
        }
    }
}

// --- 4. XỬ LÝ AJAX (LƯU MỚI / CẬP NHẬT / XÓA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ship_id_post = $_POST['ship_id'] ?? '';
    
    // Kiểm tra lại quyền một lần nữa trước khi thực thi SQL
    $auth_check = false;
    if ($is_admin) $auth_check = true;
    else {
        $stmt_check = $conn->prepare("SELECT pic FROM ships WHERE id = ?");
        $stmt_check->execute([$ship_id_post]);
        $ship_data = $stmt_check->fetch();
        if ($ship_data && $is_logged_in && $user_fullname === $ship_data['pic']) {
            $auth_check = true;
        }
    }

    if (!$auth_check) {
        echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thao tác trên tàu này.']);
        exit;
    }

    $report_date = $_POST['report_date'] ?? ''; 
    
    // XỬ LÝ XÓA
    if ($_POST['action'] === 'delete_event') {
        $event_id = $_POST['event_id'] ?? '';
        if (!empty($event_id)) {
            $sql = "DELETE FROM daily_reports WHERE id = ? AND ship_id = ? AND job_content LIKE '%(EVENT)%'";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$event_id, $ship_id_post]);
        } else {
            $sql = "DELETE FROM daily_reports WHERE ship_id = ? AND report_date = ? AND job_content LIKE '%(EVENT)%'";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$ship_id_post, $report_date]);
        }
        echo json_encode(['success' => $result]);
        exit;
    }

    $content = trim($_POST['job_content'] ?? '');
    if (strpos($content, '(EVENT)') === false) { $content .= " (EVENT)"; }

    // XỬ LÝ THÊM MỚI
    if ($_POST['action'] === 'add_event') {
        $sql = "INSERT INTO daily_reports (ship_id, report_date, job_content) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([$ship_id_post, $report_date, $content]);
        
        echo json_encode(['success' => $result]);
        exit;
    }

    // XỬ LÝ CẬP NHẬT
    if ($_POST['action'] === 'update_event') {
        $event_id = $_POST['event_id'] ?? '';
        $new_date = $_POST['new_date'] ?? $report_date;
        
        if (!empty($event_id)) {
            $sql = "UPDATE daily_reports SET job_content = ?, report_date = ? 
                    WHERE id = ? AND ship_id = ? AND job_content LIKE '%(EVENT)%'";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$content, $new_date, $event_id, $ship_id_post]);
        } else {
            $sql = "UPDATE daily_reports SET job_content = ?, report_date = ? 
                    WHERE ship_id = ? AND report_date = ? AND job_content LIKE '%(EVENT)%'";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$content, $new_date, $ship_id_post, $report_date]);
        }
        echo json_encode(['success' => $result]);
        exit;
    }
}

// --- 5. LẤY DỮ LIỆU SỰ KIỆN ---
$events = [];
if ($selected_ship != '') {
    $sql = "SELECT dr.id, dr.report_date, dr.job_content, s.project_name, dr.ship_id 
            FROM daily_reports dr 
            JOIN ships s ON dr.ship_id = s.id 
            WHERE dr.ship_id = ? AND dr.job_content LIKE '%(EVENT)%'
            ORDER BY dr.report_date DESC, dr.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$selected_ship]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 6. LOGIC HIGHLIGHT ---
$highlight_index = -1;
$today = date('Y-m-d');
if (!empty($events)) {
    $closest_idx = null;
    foreach ($events as $idx => $ev) {
        if ($ev['report_date'] == $today) { $highlight_index = $idx; break; }
        if ($ev['report_date'] < $today && $closest_idx === null) { $closest_idx = $idx; }
    }
    if ($highlight_index === -1) $highlight_index = $closest_idx;
}

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 10px; }
    .container { max-width: 1400px; margin: auto; background: white; padding: 15px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
    .header-section { border-bottom: 2px solid #28a745; margin-bottom: 15px; padding-bottom: 10px; }
    .header-section h2 { margin: 0 0 5px 0; color: #333; font-size: 1.2rem; text-align: center; }

    .add-event-box { background: #fff; padding: 15px; border-radius: 10px; border: 2px solid #28a745; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(40,167,69,0.1); }
    .add-event-box h3 { margin: 0 0 10px 0; font-size: 13px; color: #28a745; text-transform: uppercase; border-bottom: 1px dashed #ddd; padding-bottom: 5px; }
    .flex-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .field { display: flex; flex-direction: column; box-sizing: border-box; }
    .field.ship, .field.date { width: calc(50% - 6px); }
    .field.content { width: 100%; }
    
    @media (min-width: 768px) {
        .field.ship { width: 250px; }
        .field.date { width: 170px; }
        .field.content { flex: 1; }
        .btn-add { width: auto !important; }
    }
    
    .field label { font-size: 11px; font-weight: bold; color: #666; margin-bottom: 4px; }
    input, textarea, select { padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 14px; outline: none; width: 100%; box-sizing: border-box; }
    .btn-add { background: #28a745; color: white; border: none; padding: 12px 25px; cursor: pointer; border-radius: 5px; font-weight: bold; transition: 0.3s; width: 100%; margin-top: 5px; }
    .btn-add:hover { background: #218838; }
    .btn-add:disabled { background: #ccc; cursor: not-allowed; }

    .ship-select-box { background: #f8f9fa; padding: 12px; border-radius: 8px; border: 1px solid #dee2e6; margin-bottom: 20px; }
    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; }
    table { width: 100%; border-collapse: collapse; min-width: 800px; table-layout: fixed; }
    th, td { border-bottom: 1px solid #eee; padding: 10px; font-size: 14px; text-align: center; vertical-align: middle; word-wrap: break-word; }
    th { background-color: #28a745; color: white; font-size: 13px; }
    .col-stt { width: 50px; }
    .col-date { width: 90px; }
    .col-content { text-align: left !important; } 
    .col-action { width: 100px; }

    .row-highlight { background-color: #fffde7 !important; font-weight: bold; border-left: 5px solid #fbc02d; }
    .edit-area { width: 100%; min-height: 80px; display: none; padding: 8px; border: 1px solid #007bff; border-radius: 4px; font-family: inherit; }
    .edit-date-input { display: none; width: 100%; padding: 5px; border: 1px solid #007bff; border-radius: 4px; }
    .btn-edit-trigger { background: none; border: none; color: #007bff; cursor: pointer; font-size: 16px; }
    .btn-delete-trigger { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 16px; margin-left: 10px; }
    .action-group { display: none; gap: 5px; justify-content: center; margin-top: 5px; }
    .btn-save { background: #28a745; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px; }
    .btn-cancel { background: #6c757d; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; font-size: 12px; }
</style>

<div class="container">
    <div class="header-section">
        <h2>🚩 HỆ THỐNG SỰ KIỆN DỰ ÁN</h2>
        </div>

    <?php if ($can_edit && $selected_ship): ?>
    <div class="add-event-box">
        <h3>+ Ghi nhận sự kiện cho: <?= htmlspecialchars($selected_ship_name) ?></h3>
        <form id="addEventForm" class="flex-form">
            <input type="hidden" name="action" value="add_event">
            <input type="hidden" name="ship_id" value="<?= $selected_ship ?>">
            <div class="field date">
                <label>1. CHỌN NGÀY</label>
                <input type="date" name="report_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="field content">
                <label>2. NỘI DUNG SỰ KIỆN</label>
                <textarea name="job_content" placeholder="Nhập nội dung sự kiện quan trọng cho tàu này..." required></textarea>
            </div>
            <button type="button" class="btn-add" onclick="submitAddForm()">LƯU SỰ KIỆN</button>
        </form>
    </div>
    <?php elseif ($selected_ship): ?>
        <div style="background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; border: 1px solid #ffeeba;">
            ℹ️ Bạn đang xem dữ liệu của <b><?= htmlspecialchars($selected_ship_name) ?></b>. Bạn không phải là PIC nên không có quyền thêm/sửa/xóa.
        </div>
    <?php endif; ?>

    <div class="ship-select-box">
        <form method="GET">
            <label style="font-size: 11px; font-weight: bold; color: #555;">CHỌN TÀU ĐỂ XEM:</label>
            <div style="display: flex; gap: 10px; margin-top: 5px;">
                <select name="ship_id" onchange="this.form.submit()" style="flex-grow: 1;">
                    <option value="">-- Chọn tàu --</option>
                    <?php foreach($ships_list as $ship): 
                        $is_pic = ($is_logged_in && $user_fullname === $ship['pic']);
                    ?>
                        <option value="<?= $ship['id']; ?>" <?= ($selected_ship == $ship['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($ship['project_name']); ?> 
                            <?= $is_pic ? ' [Tàu của bạn]' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($selected_ship): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th class="col-stt">STT</th>
                    <th class="col-date">NGÀY</th>
                    <th class="col-content">NỘI DUNG SỰ KIỆN</th>
                    <?php if ($can_edit): ?><th class="col-action">THAO TÁC</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($events)): ?>
                    <?php foreach ($events as $idx => $ev): 
                        $clean_txt = str_replace('(EVENT)', '', $ev['job_content']);
                    ?>
                    <tr id="row-<?= $idx ?>" class="<?= $idx === $highlight_index ? 'row-highlight' : '' ?>">
                        <td class="col-stt"><?= $idx + 1 ?></td>
                        <td class="col-date">
                            <span class="date-val"><b><?= date('Y-m-d', strtotime($ev['report_date'])) ?></b></span>
                            <input type="date" class="edit-date-input" id="date-input-<?= $idx ?>" value="<?= $ev['report_date'] ?>">
                        </td>
                        <td class="col-content">
                            <div class="text-val"><?= nl2br(htmlspecialchars(trim($clean_txt))) ?></div>
                            <textarea class="edit-area" id="input-<?= $idx ?>"><?= htmlspecialchars(trim($clean_txt)) ?></textarea>
                        </td>
                        <?php if ($can_edit): ?>
                        <td class="col-action">
                            <div id="btn-group-<?= $idx ?>">
                                <button class="btn-edit-trigger" onclick="toggleEdit(<?= $idx ?>)">✎</button>
                                <button class="btn-delete-trigger" onclick="deleteEvent('<?= $ev['id'] ?>', '<?= $ev['report_date'] ?>', '<?= $ev['ship_id'] ?>')">🗑</button>
                            </div>
                            <div class="action-group" id="actions-<?= $idx ?>">
                                <button class="btn-save" onclick="saveEdit(<?= $idx ?>, '<?= $ev['id'] ?>', '<?= $ev['report_date'] ?>', <?= $ev['ship_id'] ?>)">Lưu</button>
                                <button class="btn-cancel" onclick="toggleEdit(<?= $idx ?>)">Hủy</button>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= $can_edit ? 4 : 3 ?>" style="padding:40px; color:#999;">Chưa có dữ liệu sự kiện.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div style="text-align: center; padding: 50px; color: #888; border: 2px dashed #ddd; border-radius: 10px;">Vui lòng chọn tàu để xem lịch sử sự kiện.</div>
    <?php endif; ?>
</div>

<script>
async function submitAddForm() {
    const form = document.getElementById('addEventForm');
    const formData = new FormData(form);
    const btn = document.querySelector('.btn-add');
    btn.disabled = true;
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const result = await response.json();
        if(result.success) location.reload();
        else alert(result.message);
    } catch(e) { alert('Lỗi hệ thống!'); } finally { btn.disabled = false; }
}

function toggleEdit(idx) {
    const row = document.getElementById('row-' + idx);
    const textVal = row.querySelector('.text-val'), editArea = row.querySelector('.edit-area');
    const dateVal = row.querySelector('.date-val'), dateInput = row.querySelector('.edit-date-input');
    const btnGroup = document.getElementById('btn-group-' + idx), actions = document.getElementById('actions-' + idx);
    
    const isEditing = editArea.style.display === 'block';
    
    textVal.style.display = isEditing ? 'block' : 'none';
    dateVal.style.display = isEditing ? 'block' : 'none';
    btnGroup.style.display = isEditing ? 'block' : 'none';
    
    editArea.style.display = isEditing ? 'none' : 'block';
    dateInput.style.display = isEditing ? 'none' : 'block';
    actions.style.display = isEditing ? 'none' : 'flex';
}

async function saveEdit(idx, eventId, oldDate, shipId) {
    const content = document.getElementById('input-' + idx).value;
    const newDate = document.getElementById('date-input-' + idx).value;
    
    const formData = new FormData();
    formData.append('action', 'update_event'); 
    formData.append('event_id', eventId);
    formData.append('ship_id', shipId);
    formData.append('report_date', oldDate); 
    formData.append('new_date', newDate);     
    formData.append('job_content', content);
    
    const response = await fetch(window.location.href, { method: 'POST', body: formData });
    const result = await response.json();
    if(result.success) location.reload(); else alert(result.message || 'Lỗi!');
}

async function deleteEvent(eventId, date, shipId) {
    if(!confirm('Bạn có chắc chắn muốn xóa sự kiện này?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_event'); 
    formData.append('event_id', eventId);
    formData.append('ship_id', shipId); 
    formData.append('report_date', date);
    const response = await fetch(window.location.href, { method: 'POST', body: formData });
    const result = await response.json();
    if(result.success) location.reload(); else alert(result.message);
}
</script>

<?php include 'footer.php'; ?>
