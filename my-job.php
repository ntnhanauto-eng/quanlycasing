<?php
session_start();
require_once 'db_connect.php';

// --- 1. KIỂM TRA ĐĂNG NHẬP ---
if (!isset($_SESSION['user']) || empty($_SESSION['fullname'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$user_fullname = $_SESSION['fullname'];
$current_url = $_SERVER['REQUEST_URI'];
$display_name = $_SESSION['fullname'];

// Lấy tham số trang hiện tại để giữ phân trang sau khi gửi dữ liệu
$page = (int)($_GET['page'] ?? 1); if ($page < 1) $page = 1;

// --- 2. XỬ LÝ HÀNH ĐỘNG (Thêm / Sửa / Xóa) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // THÊM MỚI CÔNG VIỆC
    if ($_POST['action'] == 'add') {
        $ship_id = $_POST['ship_id'];
        $job_content = $_POST['job_content'];
        $por = $_POST['por'];
        $plan_date = $_POST['plan_date'];
        $priority = $_POST['priority'] ?? 'Bình thường';
        $notes = $_POST['notes'] ?? '';
        $status = $_POST['status'] ?? 'Chưa làm';
        $finish_date = ($status === 'Đã xong') ? $_POST['finish_date'] : null;

        $sql = "INSERT INTO my_jobs (user_fullname, ship_id, job_content, por, plan_date, priority, notes, status, finish_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $conn->prepare($sql)->execute([$user_fullname, $ship_id, $job_content, $por, $plan_date, $priority, $notes, $status, $finish_date]);
    }
    
    // CẬP NHẬT CÔNG VIỆC (Chỉ sửa được công việc của chính mình)
    elseif ($_POST['action'] == 'update' && isset($_POST['id'])) {
        $id = $_POST['id'];
        $ship_id = $_POST['ship_id'];
        $job_content = $_POST['job_content'];
        $por = $_POST['por'];
        $plan_date = $_POST['plan_date'];
        $priority = $_POST['priority'];
        $notes = $_POST['notes'];
        $status = $_POST['status'];
        $finish_date = ($status === 'Đã xong') ? $_POST['finish_date'] : null;

        $sql = "UPDATE my_jobs SET ship_id = ?, job_content = ?, por = ?, plan_date = ?, priority = ?, notes = ?, status = ?, finish_date = ? WHERE id = ? AND user_fullname = ?";
        $conn->prepare($sql)->execute([$ship_id, $job_content, $por, $plan_date, $priority, $notes, $status, $finish_date, $id, $user_fullname]);
    }
    
    // XÓA CÔNG VIỆC (Chỉ xóa được công việc của chính mình)
    elseif ($_POST['action'] == 'delete' && isset($_POST['id'])) {
        $id = $_POST['id'];
        $conn->prepare("DELETE FROM my_jobs WHERE id = ? AND user_fullname = ?")->execute([$id, $user_fullname]);
    }

    header("Location: my-job.php?page=" . $page);
    exit();
}

// --- 3. PHÂN TRANG (Giới hạn hiển thị 15 dòng) ---
$limit = 15;
$start = ($page - 1) * $limit;

// Đếm tổng số dòng công việc của riêng User này
$count_stmt = $conn->prepare("SELECT COUNT(*) as total_rows FROM my_jobs WHERE user_fullname = ?");
$count_stmt->execute([$user_fullname]);
$count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_pages = ceil($count_data['total_rows'] / $limit);

// Thiết lập cấu hình hiển thị 3 trang gần nhất
$range = 1; 
$initial_num = $page - $range;
$condition_limit_num = ($page + $range) + 1;

// --- 4. TRUY VẤN DỮ LIỆU HIỂN THỊ ---
$sql = "SELECT mj.*, s.project_name, s.status as ship_status 
        FROM my_jobs mj
        JOIN ships s ON mj.ship_id = s.id
        WHERE mj.user_fullname = ?
        ORDER BY mj.id DESC LIMIT $start, $limit";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_fullname]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách tất cả các tàu để hiển thị trong thẻ <select> ô nhập liệu
$ships_list = $conn->query("SELECT id, project_name, status FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php'; 
include 'sidebar.php';
?>

<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 15px; }
    .container { max-width: 100%; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); box-sizing: border-box; }
    .header-section { border-bottom: 2px solid #007bff; margin-bottom: 20px; padding-bottom: 10px; }
    .header-section h2 { text-align: center; margin: 0 0 5px 0; color: #333; }
    
    .form-box { background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; flex: 1; min-width: 130px; }
    
    .col-ship { flex: 1 1 180px; }
    .col-job { flex: 2 1 250px; }
    .col-por { flex: 1 1 100px; }
    .col-date { flex: 1 1 130px; }
    .col-priority { flex: 1 1 110px; }
    .col-status { flex: 1 1 120px; }
    .col-finish-date { flex: 1 1 130px; }
    .col-notes { flex: 1 1 200px; }

    .form-group label { font-size: 11px; font-weight: bold; color: #555; margin-bottom: 3px; text-transform: uppercase; }
    input, select, textarea { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; box-sizing: border-box; }
    textarea { font-family: inherit; resize: vertical; min-height: 34px; }
    
    .btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: 0.3s; font-size: 12px; }
    .btn-add { background-color: #007bff; padding: 8px 15px; }
    .btn-add:hover { background-color: #0056b3; }
    .btn-edit { background-color: #ffc107; color: #000; }
    .btn-save { background-color: #28a745; color: white; }
    .btn-del { background-color: #dc3545; }
    
    /* CẤU HÌNH BẢNG CUỘN NGANG */
    .table-wrapper { width: 100%; overflow-x: auto; border-radius: 8px; border: 1px solid #dee2e6; margin-top: 10px; background: #fff; }
    table { width: 100%; border-collapse: collapse; min-width: 1350px; table-layout: fixed; }
    
    th, td { padding: 6px 10px; border: 1px solid #eef0f3; font-size: 13px; text-align: center; vertical-align: middle; box-sizing: border-box; }
    th { background-color: #007bff; color: white; font-weight: bold; white-space: nowrap; border-color: #0069d9; }
    
    /* ĐỘ RỘNG CỐ ĐỊNH CỦA CÁC CỘT */
    .col-w-stt { width: 45px; }
    .col-w-ship { width: 60px; }
    .col-w-job { width: 340px; } 
    .col-w-por { width: 90px; }
    .col-w-date { width: 110px; }
    .col-w-priority { width: 110px; }
    .col-w-status { width: 110px; }
    .col-w-fdate { width: 110px; }
    .col-w-notes { width: 240px; }
    .col-w-action { width: 130px; }

    /* THIẾT LẬP TỰ ĐỘNG XUỐNG DÒNG CHẾ ĐỘ XEM */
    .job-content-text, .notes-text { 
        white-space: pre-wrap; 
        word-break: break-word; 
        text-align: left; 
        display: block; 
        line-height: 1.4;
    }

    /* ĐIỀU CHỈNH CÁC Ô TEXTAREA TRONG BẢNG KHI BẤM SỬA (CAO ĐÚNG 5 DÒNG CHỮ) */
    table textarea.edit-mode {
        font-size: 13px;
        line-height: 1.4em;
        height: calc(1.4em * 5 + 14px);
        resize: vertical;
    }
    
    /* Màu nền theo Trạng thái */
    .status-chua-lam { background-color: #fff0f2 !important; }
    .status-dang-lam { background-color: #fff9e6 !important; }
    .status-da-xong { background-color: #e6f7ed !important; }
    
    /* Định dạng cho dòng có Mức độ GẤP */
    .row-urgent { color: #dc3545 !important; font-weight: bold !important; }
    .row-urgent td, .row-urgent td span, .row-urgent td b { color: #dc3545 !important; font-weight: bold !important; }
    
    .row-highlight { background-color: #e2e3e5 !important; }
    tbody tr:hover { filter: brightness(0.97); cursor: pointer; }
    
    .edit-mode { display: none; }
    .edit-mode-input { width: 100%; box-sizing: border-box; }
    
    .pagination { text-align: center; margin-top: 20px; }
    .pagination a { padding: 6px 12px; border: 1px solid #ddd; text-decoration: none; margin: 0 3px; border-radius: 4px; color: #333; font-size: 13px; }
    .pagination a.active { background: #007bff; color: white; border-color: #007bff; }
    .pagination a.disabled { color: #ccc; cursor: not-allowed; pointer-events: none; }
    
    @media (max-width: 768px) {
        .form-group { flex: 1 1 100% !important; }
    }
</style>

<div class="container">
    <div class="header-section">
        <h2>📋 NOTES    (CÁ NHÂN)</h2>
        </div>

    <div class="form-box">
        <form method="POST" id="main-add-form">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group col-ship">
                    <label>Chọn Tàu</label>
                    <select name="ship_id" required>
                        <option value="">-- Chọn tàu dự án --</option>
                        <?php foreach($ships_list as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo htmlspecialchars($s['project_name']); ?> (<?php echo $s['status']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group col-job">
                    <label>Nội dung công việc</label>
                    <textarea name="job_content" required rows="3" placeholder="Nhập nội dung kế hoạch công việc..."></textarea>
                </div>
                
                <div class="form-group col-por">
                    <label>POR</label>
                    <input type="text" name="por" required placeholder="Nhập POR...">
                </div>
                
                <div class="form-group col-date">
                    <label>Ngày Plan hoàn thành</label>
                    <input type="date" name="plan_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group col-priority">
                    <label>Mức độ</label>
                    <select name="priority">
                        <option value="Bình thường">Bình thường</option>
                        <option value="Gấp">Gấp 🔥</option>
                    </select>
                </div>

                <div class="form-group col-status">
                    <label>Trạng thái</label>
                    <select name="status" id="add-status" onchange="verifyFinishDate('add')">
                        <option value="Chưa làm">Chưa làm</option>
                        <option value="Đang làm">Đang làm</option>
                        <option value="Đã xong">Đã xong ✅</option>
                    </select>
                </div>

                <div class="form-group col-finish-date">
                    <label>Ngày xong</label>
                    <input type="date" name="finish_date" id="add-finish-date" disabled>
                </div>

                <div class="form-group col-notes">
                    <label>Ghi chú</label>
                    <input type="text" name="notes" placeholder="Nhập ghi chú thêm (nếu có)...">
                </div>
                
                <button type="submit" class="btn btn-add">LƯU KẾ HOẠCH</button>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th class="col-w-stt">STT</th>
                    <th class="col-w-ship">TÀU DỰ ÁN</th>
                    <th class="col-w-job">NỘI DUNG CÔNG VIỆC</th>
                    <th class="col-w-por">POR</th>
                    <th class="col-w-date">NGÀY PLAN</th>
                    <th class="col-w-priority">MỨC ĐỘ</th>
                    <th class="col-w-status">TRẠNG THÁI</th>
                    <th class="col-w-fdate">NGÀY XONG</th>
                    <th class="col-w-notes">GHI CHÚ</th>
                    <th class="col-w-action">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $stt = $start + 1; 
                foreach($jobs as $j): 
                    $is_urgent = ($j['priority'] === 'Gấp');
                    $urgent_class = $is_urgent ? 'row-urgent' : '';

                    $status_class = '';
                    if (($j['status'] ?? 'Chưa làm') === 'Chưa làm') {
                        $status_class = 'status-chua-lam';
                    } elseif ($j['status'] === 'Đang làm') {
                        $status_class = 'status-dang-lam';
                    } elseif ($j['status'] === 'Đã xong') {
                        $status_class = 'status-da-xong';
                    }

                    $final_classes = trim("$status_class $urgent_class");
                ?>
                <tr id="row-<?php echo $j['id']; ?>" onclick="highlightRow(this)" class="<?php echo $final_classes; ?>">
                    <td><?php echo $stt++; ?></td>
                    <td>
                        <span class="view-mode"><b><?php echo htmlspecialchars($j['project_name']); ?></b></span>
                        <select form="form-update-<?php echo $j['id']; ?>" name="ship_id" class="edit-mode edit-mode-input">
                            <?php foreach($ships_list as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo ($s['id'] == $j['ship_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <span class="view-mode job-content-text"><?php echo htmlspecialchars($j['job_content']); ?></span>
                        <textarea form="form-update-<?php echo $j['id']; ?>" name="job_content" class="edit-mode edit-mode-input" rows="5" required><?php echo htmlspecialchars($j['job_content']); ?></textarea>
                    </td>
                    <td>
                        <span class="view-mode"><?php echo htmlspecialchars($j['por']); ?></span>
                        <input type="text" form="form-update-<?php echo $j['id']; ?>" name="por" class="edit-mode edit-mode-input" value="<?php echo htmlspecialchars($j['por']); ?>" required>
                    </td>
                    <td>
                        <span class="view-mode"><?php echo $j['plan_date']; ?></span>
                        <input type="date" form="form-update-<?php echo $j['id']; ?>" name="plan_date" class="edit-mode edit-mode-input" value="<?php echo $j['plan_date']; ?>" required>
                    </td>
                    <td>
                        <span class="view-mode"><?php echo htmlspecialchars($j['priority']); ?></span>
                        <select form="form-update-<?php echo $j['id']; ?>" name="priority" class="edit-mode edit-mode-input">
                            <option value="Bình thường" <?php echo ($j['priority']=='Bình thường'?'selected':''); ?>>Bình thường</option>
                            <option value="Gấp" <?php echo ($j['priority']=='Gấp'?'selected':''); ?>>Gấp</option>
                        </select>
                    </td>
                    <td>
                        <span class="view-mode"><?php echo htmlspecialchars($j['status'] ?? 'Chưa làm'); ?></span>
                        <select form="form-update-<?php echo $j['id']; ?>" name="status" class="edit-mode edit-mode-input" id="update-status-<?php echo $j['id']; ?>" onchange="verifyFinishDate(<?php echo $j['id']; ?>)">
                            <option value="Chưa làm" <?php echo (($j['status'] ?? 'Chưa làm')=='Chưa làm'?'selected':''); ?>>Chưa làm</option>
                            <option value="Đang làm" <?php echo ($j['status']=='Đang làm'?'selected':''); ?>>Đang làm</option>
                            <option value="Đã xong" <?php echo ($j['status']=='Đã xong'?'selected':''); ?>>Đã xong</option>
                        </select>
                    </td>
                    <td>
                        <span class="view-mode"><?php echo !empty($j['finish_date']) ? $j['finish_date'] : '---'; ?></span>
                        <input type="date" form="form-update-<?php echo $j['id']; ?>" name="finish_date" class="edit-mode edit-mode-input" id="update-finish-date-<?php echo $j['id']; ?>" value="<?php echo $j['finish_date']; ?>" <?php echo (($j['status'] ?? 'Chưa làm') !== 'Đã xong' ? 'disabled' : 'required'); ?>>
                    </td>
                    <td>
                        <span class="view-mode notes-text"><?php echo htmlspecialchars($j['notes']); ?></span>
                        <textarea form="form-update-<?php echo $j['id']; ?>" name="notes" class="edit-mode edit-mode-input" rows="5"><?php echo htmlspecialchars($j['notes']); ?></textarea>
                    </td>
                    <td>
                        <form id="form-update-<?php echo $j['id']; ?>" method="POST" style="display:none;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $j['id']; ?>">
                        </form>
                        
                        <button type="button" class="btn btn-edit view-mode" onclick="toggleEdit(event, <?php echo $j['id']; ?>)">Sửa</button>
                        <button type="submit" form="form-update-<?php echo $j['id']; ?>" class="btn btn-save edit-mode" style="display:none;">Lưu</button>
                        <button type="button" class="btn edit-mode" style="background:#6c757d; display:none;" onclick="location.reload()">Hủy</button>
                       
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa kế hoạch này?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $j['id']; ?>">
                            <button type="submit" class="btn btn-del view-mode">Xóa</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; if(count($jobs) == 0): ?>
                <tr>
                    <td colspan="10" style="color: #999; padding: 20px;">Bạn chưa tạo kế hoạch công việc nào. Hãy nhập thông tin phía trên!</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination">
        <?php
        $base_url = "?";
        if ($total_pages > 1) {
            if ($page > 1) {
                echo "<a href='{$base_url}page=1'>« Đầu</a>";
                echo "<a href='{$base_url}page=" . ($page - 1) . "'>‹</a>";
            } else {
                echo "<a class='disabled'>« Đầu</a>";
                echo "<a class='disabled'>‹</a>";
            }

            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i >= $initial_num && $i < $condition_limit_num) {
                    $active = ($page == $i) ? 'active' : '';
                    echo "<a href='{$base_url}page={$i}' class='{$active}'>{$i}</a>";
                }
            }

            if ($page < $total_pages) {
                echo "<a href='{$base_url}page=" . ($page + 1) . "'>›</a>";
                echo "<a href='{$base_url}page={$total_pages}'>Cuối »</a>";
            } else {
                echo "<a class='disabled'>›</a>";
                echo "<a class='disabled'>Cuối »</a>";
            }
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

function verifyFinishDate(context) {
    let statusId, finishDateId;
    if (context === 'add') {
        statusId = 'add-status';
        finishDateId = 'add-finish-date';
    } else {
        statusId = 'update-status-' + context;
        finishDateId = 'update-finish-date-' + context;
    }
    
    const statusSelect = document.getElementById(statusId);
    const finishDateInput = document.getElementById(finishDateId);
    
    if (statusSelect && finishDateInput) {
        if (statusSelect.value === 'Đã xong') {
            finishDateInput.disabled = false;
            finishDateInput.required = true;
            if (!finishDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                finishDateInput.value = today;
            }
        } else {
            finishDateInput.disabled = true;
            finishDateInput.required = false;
            finishDateInput.value = '';
        }
    }
}
</script>
<?php include 'footer.php'; ?>
