<?php
session_start();
require_once 'db_connect.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');
$today = date('Y-m-d');

// ==========================================
// CẤU HÌNH PHÂN QUYỀN THEO CHUẨN G/L VÀ P/L
// ==========================================
$is_logged_in = isset($_SESSION['user']);
$user_role    = $_SESSION['role'] ?? 'PL'; // Giá trị quyền nhận diện: 'GL' hoặc 'PL'
$username     = $_SESSION['user'] ?? 'user_test';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// BACKEND LOGIC: XỬ LÝ AJAX YÊU CẦU
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'CSRF Token không hợp lệ']);
        exit;
    }

    // 1. G/L CHIA VIỆC HOẶC GIAO TIẾP CHO NGÀY MAI
    if ($_POST['action'] === 'assign_task' && $user_role === 'GL') {
        $task_id      = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        $ship_id      = (int)$_POST['ship_id'];
        $task_name    = trim($_POST['task_name']);
        $group_name   = trim($_POST['group_name']);
        $leader_user  = trim($_POST['leader_user']);
        $worker_count = (int)$_POST['worker_count'];

        if ($task_id > 0) {
            // Tiếp tục giao việc cũ (Cập nhật quân số và ngày mới cho việc tồn đọng)
            $stmt = $conn->prepare("UPDATE worker_tasks SET worker_count = ?, task_date = ?, status = 'Đang làm' WHERE id = ?");
            $success = $stmt->execute([$worker_count, $today, $task_id]);
        } else {
            // Tạo công việc mới hoàn toàn
            $stmt = $conn->prepare("INSERT INTO worker_tasks (ship_id, task_name, group_name, leader_user, manager_user, worker_count, status, task_date) VALUES (?, ?, ?, ?, ?, ?, 'Đang làm', ?)");
            $success = $stmt->execute([$ship_id, $task_name, $group_name, $leader_user, $username, $worker_count, $today]);
        }
        echo json_encode(['success' => $success]);
        exit;
    }

    // 2. P/L BÁO CÁO TIẾN ĐỘ BUỔI CHIỀU
    if ($_POST['action'] === 'submit_report' && $user_role === 'PL') {
        $task_id          = (int)$_POST['task_id'];
        $pending_progress = (int)$_POST['progress'];
        $leader_note      = trim($_POST['leader_note']);

        $stmt = $conn->prepare("UPDATE worker_tasks SET pending_progress = ?, leader_note = ?, status = 'Chờ duyệt' WHERE id = ? AND leader_user = ?");
        $success = $stmt->execute([$pending_progress, $leader_note, $task_id, $username]);
        echo json_encode(['success' => $success]);
        exit;
    }

    // 3. G/L PHÊ DUYỆT TIẾN ĐỘ THỰC TẾ & TỰ ĐỘNG CẬP NHẬT TIẾN ĐỘ TỔNG
    if ($_POST['action'] === 'approve_task' && $user_role === 'GL') {
        $task_id = (int)$_POST['task_id'];
        $decision = $_POST['decision']; // 'approve' hoặc 'reject'

        if ($decision === 'approve') {
            // Lấy pending_progress đè lên current_progress, nếu đạt 100% thì chuyển Hoàn thành
            $stmt = $conn->prepare("UPDATE worker_tasks SET current_progress = pending_progress, status = IF(pending_progress = 100, 'Hoàn thành', 'Đang làm'), pending_progress = NULL WHERE id = ?");
            $success = $stmt->execute([$task_id]);

            if ($success) {
                // Lấy ship_id của công việc vừa duyệt để tính toán lại tổng thể con tàu
                $stmt_ship = $conn->prepare("SELECT ship_id FROM worker_tasks WHERE id = ?");
                $stmt_ship->execute([$task_id]);
                $ship_id = $stmt_ship->fetchColumn();

                if ($ship_id) {
                    // TỰ ĐỘNG CẬP NHẬT TIẾN ĐỘ TỔNG VÀO BẢNG SHIP_PROGRESS (Tính trung bình cộng các task nhỏ của tàu đó)
                    $sql_update_total = "UPDATE ship_progress sp 
                                         SET sp.progress_percent = (
                                             SELECT ROUND(AVG(current_progress)) 
                                             FROM worker_tasks 
                                             WHERE ship_id = sp.ship_id
                                         )
                                         WHERE sp.ship_id = ?";
                    $stmt_total = $conn->prepare($sql_update_total);
                    $stmt_total->execute([$ship_id]);
                }
            }
        } else {
            // Từ chối phê duyệt, trả về trạng thái cũ cho P/L sửa lại
            $stmt = $conn->prepare("UPDATE worker_tasks SET status = 'Bị từ chối', pending_progress = NULL WHERE id = ?");
            $success = $stmt->execute([$task_id]);
        }
        
        echo json_encode(['success' => $success]);
        exit;
    }
}

include 'header.php';
include 'sidebar.php';

// Lấy danh sách tàu để đổ vào bộ chọn dữ liệu
$ships = $conn->query("SELECT id, project_name FROM ships WHERE status IN ('Chưa thi công', 'Đang thi công')")->fetchAll(PDO::FETCH_ASSOC);
?>

<input type="hidden" id="global_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">

<style>
:root { --primary: #2563eb; --success: #16a34a; --warning: #eab308; --danger: #dc2626; }
body { font-family: 'Segoe UI', sans-serif; background: #f4f8fc; color: #1f2937; padding: 15px; }
.glass-card { background: rgba(255, 255, 255, 0.95); border: 1px solid rgba(226, 232, 240, 0.8); border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); padding: 20px; margin-bottom: 20px; }
.grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; }
.task-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; color: #fff; }
.bg-chualam { background: #94a3b8; } .bg-danglam { background: var(--primary); } .bg-choduyet { background: var(--warning); color: #854d0e; } .bg-hoanthanh { background: var(--success); } .bg-tuchoi { background: var(--danger); }
.form-group { margin-bottom: 12px; display: flex; flex-direction: column; }
.form-group label { font-size: 13px; font-weight: 700; margin-bottom: 4px; color: #4b5563; }
.form-control { padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; width: 100%; box-sizing: border-box; }
.btn { border: none; padding: 10px 15px; border-radius: 8px; font-weight: 700; cursor: pointer; color: #fff; transition: 0.2s; }
.btn-primary { background: var(--primary); } .btn-success { background: var(--success); } .btn-danger { background: var(--danger); }
.task-item { border-bottom: 1px solid #e2e8f0; padding: 12px 0; }
.task-item:last-child { border-bottom: none; }
.progress-bar-bg { background: #e2e8f0; border-radius: 999px; height: 8px; width: 100%; overflow: hidden; margin-top: 5px; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.4s; }
</style>

<div style="max-width: 1300px; margin: auto;">
    <h2 style="margin-bottom: 15px; font-weight: 800;">🚢 HỆ THỐNG ĐIỀU PHỐI VÀ PHÂN CHIA VIỆC CÔNG TRƯỜNG</h2>
    <p style="margin-bottom: 20px; color: #64748b;">Quyền hạn hiện tại: <strong><?= $user_role === 'GL' ? '🧳 Nhóm Trưởng (G/L)' : '🛠️ Đội Trưởng (P/L)'; ?></strong> (User: <?= htmlspecialchars($username); ?>)</p>

    <div class="glass-card" style="background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1px solid #bfdbfe;">
        <h3 style="margin-top:0; font-weight:800; color:#1e40af; display: flex; align-items: center; gap: 8px;">📈 TIẾN ĐỘ TỔNG THỂ CÁC TÀU THI CÔNG</h3>
        <?php
        // Lấy thông tin dự án phối hợp với phần trăm tổng hợp từ bảng ship_progress
        $stmt_main_progress = $conn->query("SELECT s.project_name, COALESCE(sp.progress_percent, 0) as progress_percent FROM ships s LEFT JOIN ship_progress sp ON s.id = sp.ship_id WHERE s.status = 'Đang thi công'");
        $main_ships = $stmt_main_progress->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($main_ships) == 0):
            echo '<p style="color:#1e40af; font-size:14px; margin:0;">Hiện tại không có tàu nào trong trạng thái Đang thi công.</p>';
        endif;
        foreach($main_ships as $ms):
        ?>
            <div style="margin-bottom: 12px; background: rgba(255,255,255,0.5); padding: 10px; border-radius: 10px;">
                <div style="display:flex; justify-content:space-between; font-weight:700; font-size:14px; margin-bottom: 4px;">
                    <span>🚢 <?= htmlspecialchars($ms['project_name']); ?></span>
                    <span style="color:#2563eb;"><?= (int)$ms['progress_percent']; ?>% Hoàn thành</span>
                </div>
                <div class="progress-bar-bg" style="background:#fff; height:12px; margin-top:0;">
                    <div class="progress-bar-fill" style="width: <?= (int)$ms['progress_percent']; ?>%; height:100%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="grid-2">
        <?php if ($user_role === 'GL'): ?>
        <div>
            <div class="glass-card">
                <h3 style="margin-top:0; font-weight:800; color: #0f172a;" id="form-title">➕ Giao Việc Sáng Nay / Việc Ngày Mai (G/L)</h3>
                <form id="assignForm">
                    <input type="hidden" id="edit_task_id" value="0">
                    <div class="form-group">
                        <label>Chọn tàu thi công:</label>
                        <select id="ship_id" class="form-control" required>
                            <?php foreach($ships as $s): ?>
                                <option value="<?= $s['id']; ?>"><?= htmlspecialchars($s['project_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Hạng mục công việc:</label>
                        <input type="hidden" id="task_name_hidden">
                        <input type="text" id="task_name" class="form-control" placeholder="Ví dụ: Hàn vách hông hầm hàng số 2" required>
                    </div>
                    <div class="form-group">
                        <label>Tổ / Đội nhận việc:</label>
                        <input type="text" id="group_name" class="form-control" placeholder="Ví dụ: Đội Hàn Boong 1" required>
                    </div>
                    <div class="form-group">
                        <label>Username Đội trưởng (P/L) chịu trách nhiệm:</label>
                        <input type="text" id="leader_user" class="form-control" placeholder="Nhập tên tài khoản P/L" required>
                    </div>
                    <div class="form-group">
                        <label>Số lượng nhân lực bố trí (Người):</label>
                        <input type="number" id="worker_count" class="form-control" min="1" required>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="submitAssignForm()">PHÁT LỆNH THI CÔNG</button>
                    <button type="button" id="btnCancelEdit" class="btn" style="background:#64748b; display:none;" onclick="resetForm()">HỦY TIẾP TỤC</button>
                </form>
            </div>

            <div class="glass-card">
                <h3 style="margin-top:0; font-weight:800; color: #b45309;">📥 Danh Sách Báo Cáo Chờ G/L Phê Duyệt</h3>
                <div id="manager-approval-list">
                    <?php
                    $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id WHERE t.status = 'Chờ duyệt' AND t.manager_user = ?");
                    $stmt->execute([$username]);
                    $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if(count($approvals) == 0) echo '<p style="color:#64748b; font-size:14px;">Hiện tại chưa có báo cáo P/L nào gửi lên.</p>';
                    foreach($approvals as $task):
                    ?>
                        <div class="task-item" style="background: #fffbeb; padding: 12px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #fef08a;">
                            <strong>[<?= htmlspecialchars($task['project_name']); ?>] - <?= htmlspecialchars($task['task_name']); ?></strong><br>
                            <span style="font-size:13px; color:#475569;">Đội phụ trách: <?= htmlspecialchars($task['group_name']); ?> | Số quân thực tế: <?= (int)$task['worker_count']; ?> người</span><br>
                            <div style="margin: 6px 0; font-size:14px;">
                                Tiến độ đã duyệt cũ: <strong style="color:var(--primary);"><?= (int)$task['current_progress']; ?>%</strong> 
                                ➔ Đội trưởng (P/L) báo đạt: <strong style="color:var(--success);"><?= (int)$task['pending_progress']; ?>%</strong>
                            </div>
                            <p style="font-size:13px; background:#fff; padding:6px; border-radius:4px; margin: 5px 0;">💬 <i>Khối lượng thực địa: <?= htmlspecialchars($task['leader_note']); ?></i></p>
                            <div style="display:flex; gap:8px; margin-top:8px;">
                                <button class="btn btn-success" style="padding:5px 12px; font-size:13px;" onclick="approveDecision(<?= (int)$task['id']; ?>, 'approve')">✔ DUYỆT ĐỒNG Ý</button>
                                <button class="btn btn-danger" style="padding:5px 12px; font-size:13px;" onclick="approveDecision(<?= (int)$task['id']; ?>, 'reject')">❌ TỪ CHỐI</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($user_role === 'PL'): ?>
        <div class="glass-card">
            <h3 style="margin-top:0; font-weight:800; color: var(--primary);">📝 Nhiệm Vụ Được Giao & Báo Cáo Ca Chiều (P/L)</h3>
            <div id="leader-task-list">
                <?php
                $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id WHERE t.leader_user = ? AND t.status IN ('Đang làm', 'Bị từ chối')");
                $stmt->execute([$username]);
                $my_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if(count($my_tasks) == 0) echo '<p style="color:#64748b; font-size:14px;">Hôm nay P/L chưa có nhiệm vụ nào được điều động.</p>';
                foreach($my_tasks as $task):
                ?>
                    <div class="task-item" style="background:#f8fafc; padding:12px; border-radius:8px; margin-bottom:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <strong>[<?= htmlspecialchars($task['project_name']); ?>] <?= htmlspecialchars($task['task_name']); ?></strong>
                            <span class="task-badge bg-<?= $task['status'] == 'Bị từ chối' ? 'tuchoi' : 'danglam'; ?>"><?= $task['status']; ?></span>
                        </div>
                        <span style="font-size:13px; color:#64748b;">Quân số tổ thi công hôm nay: <strong><?= (int)$task['worker_count']; ?></strong> thợ.</span>
                        
                        <div style="margin-top: 8px;">
                            <span style="font-size:13px;">Tiến độ tích lũy hiện tại: <strong><?= (int)$task['current_progress']; ?>%</strong></span>
                            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= (int)$task['current_progress']; ?>%;"></div></div>
                        </div>

                        <div style="margin-top:12px; background:#fff; padding:10px; border-radius:6px; border:1px solid #cbd5e1;">
                            <h4 style="margin:0 0 8px 0; font-size:13px; font-weight:800;">Nhập số liệu báo cáo kết thúc ca:</h4>
                            <div class="form-group">
                                <label>Ước lượng tiến độ tích lũy mới (%):</label>
                                <input type="number" id="progress-input-<?= $task['id']; ?>" class="form-control" min="<?= (int)$task['current_progress']; ?>" max="100" value="<?= (int)$task['current_progress']; ?>">
                            </div>
                            <div class="form-group">
                                <label>Chi tiết khối lượng hạng mục hoàn thành:</label>
                                <textarea id="note-input-<?= $task['id']; ?>" class="form-control" rows="2" placeholder="Ví dụ: Đã gá ráp đính định hình thành công 4 vách ngăn dọc..."></textarea>
                            </div>
                            <button class="btn btn-success" style="padding:6px 12px; font-size:13px;" onclick="submitReport(<?= (int)$task['id']; ?>)">🚀 GỬI BÁO CÁO CHO G/L DUYỆT</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="glass-card">
            <h3 style="margin-top:0; font-weight:800; color: #1e293b;">📊 Giám Sát Tiến Độ Thực Tế Toàn Tàu</h3>
            <div id="general-monitor-list">
                <?php
                $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id ORDER BY t.current_progress ASC, t.task_date DESC");
                $stmt->execute();
                $all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach($all_tasks as $task):
                    $badge_class = 'danglam';
                    if($task['status'] === 'Chờ duyệt')  $badge_class = 'choduyet';
                    if($task['status'] === 'Hoàn thành') $badge_class = 'hoanthanh';
                    if($task['status'] === 'Bị từ chối')  $badge_class = 'tuchoi';
                ?>
                    <div class="task-item">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <span style="font-weight: 800; color:#1e40af;">[<?= htmlspecialchars($task['project_name']); ?>]</span>
                                <strong style="font-size:15px; color:#1f2937;"><?= htmlspecialchars($task['task_name']); ?></strong>
                            </div>
                            <span class="task-badge bg-<?= $badge_class; ?>"><?= $task['status']; ?></span>
                        </div>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            👷 Tổ: <strong><?= htmlspecialchars($task['group_name']); ?></strong> (<?= (int)$task['worker_count']; ?> người) | Ngày cập nhật gần nhất: <?= date('d/m/Y', strtotime($task['task_date'])); ?>
                        </div>
                        <div style="margin-top:6px;">
                            <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700;">
                                <span>Tiến độ thực tế hiện tại:</span>
                                <span style="color:var(--success);"><?= (int)$task['current_progress']; ?>%</span>
                            </div>
                            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= (int)$task['current_progress']; ?>%;"></div></div>
                        </div>

                        <?php if ($user_role === 'GL' && $task['current_progress'] < 100 && $task['status'] !== 'Chờ duyệt'): ?>
                            <div style="margin-top:8px; text-align:right;">
                                <button class="btn" style="background:#0284c7; padding:4px 10px; font-size:12px;" 
                                    onclick="prepareFollowUpTask(<?= htmlspecialchars(json_encode($task)); ?>)">
                                    🔄 Giao việc tiếp ngày mai (Còn lại <?= 100 - (int)$task['current_progress']; ?>%)
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = document.getElementById('global_csrf_token').value;
const apiURL = window.location.href;

// 1. G/L gửi lệnh giao việc mới hoặc tiếp tục việc tồn đọng ngày mai
function submitAssignForm() {
    const task_id = document.getElementById('edit_task_id').value;
    const ship_id = document.getElementById('ship_id').value;
    
    const task_name_input = document.getElementById('task_name');
    const task_name = task_name_input.disabled ? document.getElementById('task_name_hidden').value : task_name_input.value.trim();
    
    const group_name = document.getElementById('group_name').value.trim();
    const leader_user = document.getElementById('leader_user').value.trim();
    const worker_count = document.getElementById('worker_count').value;

    if(!task_name || !group_name || !leader_user || !worker_count) {
        alert('Vui lòng điền đầy đủ dữ liệu phân công công việc!');
        return;
    }

    const params = new URLSearchParams({
        action: 'assign_task',
        task_id: task_id,
        ship_id: ship_id,
        task_name: task_name,
        group_name: group_name,
        leader_user: leader_user,
        worker_count: worker_count,
        csrf_token: csrfToken
    });

    fetch(apiURL, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: params })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            alert('Lệnh phân chia việc của G/L đã được phát xuống công trường!');
            location.reload();
        } else {
            alert('Lỗi hệ thống không thể lưu thông tin.');
        }
    }).catch(() => alert('Lỗi kết nối máy chủ.'));
}

// 2. Chuyển dữ liệu việc tồn đọng lên form để G/L phân bổ quân số ngày mai
function prepareFollowUpTask(task) {
    document.getElementById('edit_task_id').value = task.id;
    document.getElementById('ship_id').value = task.ship_id;
    
    document.getElementById('task_name').value = task.task_name;
    document.getElementById('task_name_hidden').value = task.task_name;
    document.getElementById('task_name').disabled = true; // Khóa trường text để tránh làm đổi tên gốc của việc cũ
    
    document.getElementById('group_name').value = task.group_name;
    document.getElementById('leader_user').value = task.leader_user;
    document.getElementById('worker_count').value = task.worker_count;
    
    document.getElementById('form-title').innerText = `🔄 Điều Phối Việc Tồn Đọng (Tích lũy đạt ${task.current_progress}%)`;
    document.getElementById('btnCancelEdit').style.display = 'inline-block';
    document.getElementById('form-title').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('edit_task_id').value = "0";
    document.getElementById('task_name').disabled = false;
    document.getElementById('assignForm').reset();
    document.getElementById('form-title').innerText = "➕ Giao Việc Sáng Nay / Việc Ngày Mai (G/L)";
    document.getElementById('btnCancelEdit').style.display = 'none';
}

// 3. P/L gửi báo cáo ca chiều
function submitReport(taskId) {
    const progress = document.getElementById('progress-input-' + taskId).value;
    const note = document.getElementById('note-input-' + taskId).value.trim();

    if(!note) {
        alert('Vui lòng nhập mô tả chi tiết khối lượng thực tế làm được ngày hôm nay.');
        return;
    }

    const params = new URLSearchParams({
        action: 'submit_report',
        task_id: taskId,
        progress: progress,
        leader_note: note,
        csrf_token: csrfToken
    });

    fetch(apiURL, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: params })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            alert('Đã gửi dữ liệu tiến độ lên hệ thống. Đang chờ G/L phê duyệt!');
            location.reload();
        } else {
            alert('Có lỗi xảy ra khi nộp báo cáo.');
        }
    }).catch(() => alert('Mất kết nối Internet mạng nội bộ.'));
}

// 4. G/L Duyệt / Từ chối tiến độ báo cáo
function approveDecision(taskId, decision) {
    const params = new URLSearchParams({
        action: 'approve_task',
        task_id: taskId,
        decision: decision,
        csrf_token: csrfToken
    });

    fetch(apiURL, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: params })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            alert(decision === 'approve' ? 'Hệ thống đã phê duyệt và tự động cập nhật lũy kế tiến độ tổng thành công!' : 'Đã trả về trạng thái từ chối yêu cầu.');
            location.reload();
        } else {
            alert('Không thể cập nhật quyết định.');
        }
    }).catch(() => alert('Lỗi xử lý luồng mạng.'));
}
</script>

<?php include 'footer.php'; ?>
