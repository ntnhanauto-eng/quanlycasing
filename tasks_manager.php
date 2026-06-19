<?php
session_start();
require_once 'db_connect.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');
$today = date('Y-m-d');

// Giả lập phân quyền (Bạn thay bằng $_SESSION thực tế của bạn)
$is_logged_in = isset($_SESSION['user']);
$user_role    = $_SESSION['role'] ?? 'leader'; // 'manager' (Nhóm trưởng) hoặc 'leader' (Đội trưởng)
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

    // 1. NHÓM TRƯỞNG CHIA VIỆC HOẶC GIAO TIẾP CHO NGÀY MAI
    if ($_POST['action'] === 'assign_task' && $user_role === 'manager') {
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

    // 2. ĐỘI TRƯỞNG BÁO CÁO TIẾN ĐỘ BUỔI CHIỀU
    if ($_POST['action'] === 'submit_report' && $user_role === 'leader') {
        $task_id          = (int)$_POST['task_id'];
        $pending_progress = (int)$_POST['progress'];
        $leader_note      = trim($_POST['leader_note']);

        $stmt = $conn->prepare("UPDATE worker_tasks SET pending_progress = ?, leader_note = ?, status = 'Chờ duyệt' WHERE id = ? AND leader_user = ?");
        $success = $stmt->execute([$pending_progress, $leader_note, $task_id, $username]);
        echo json_encode(['success' => $success]);
        exit;
    }

    // 3. NHÓM TRƯỞNG PHÊ DUYỆT TIẾN ĐỘ
    if ($_POST['action'] === 'approve_task' && $user_role === 'manager') {
        $task_id = (int)$_POST['task_id'];
        $decision = $_POST['decision']; // 'approve' hoặc 'reject'

        if ($decision === 'approve') {
            // Lấy pending_progress đè lên current_progress, nếu đạt 100% thì Hoàn thành
            $stmt = $conn->prepare("UPDATE worker_tasks SET current_progress = pending_progress, status = IF(pending_progress = 100, 'Hoàn thành', 'Đang làm'), pending_progress = NULL WHERE id = ?");
        } else {
            // Từ chối phê duyệt, trả về trạng thái cũ cho Đội trưởng sửa
            $stmt = $conn->prepare("UPDATE worker_tasks SET status = 'Bị từ chối', pending_progress = NULL WHERE id = ?");
        }
        $success = $stmt->execute([$task_id]);
        echo json_encode(['success' => $success]);
        exit;
    }
}

include 'header.php';
include 'sidebar.php';

// Lấy danh sách tàu để đổ vào bộ chọn dữ liệu
$ships = $conn->query("SELECT id, project_name FROM ships WHERE status IN ('Chưa thi công', 'Đang thi công')")->fetchAll(PDO::FETCH_ASSOC);
?>

<input type="hidden" id="global_csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

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
    <p style="margin-bottom: 20px; color: #64748b;">Quyền hạn hiện tại: <strong><?= $user_role === 'manager' ? '🧳 Nhóm Trưởng' : '🛠️ Đội Trưởng'; ?></strong> (User: <?= htmlspecialchars($username); ?>)</p>

    <div class="grid-2">
        <?php if ($user_role === 'manager'): ?>
        <div>
            <div class="glass-card">
                <h3 style="margin-top:0; font-weight:800; color: #0f172a;" id="form-title">➕ Giao Việc Sáng Nay / Việc Ngày Mai</h3>
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
                        <input type="text" id="task_name" class="form-control" placeholder="Ví dụ: Hàn vách hông hầm hàng số 2" required>
                    </div>
                    <div class="form-group">
                        <label>Tổ / Đội nhận việc:</label>
                        <input type="text" id="group_name" class="form-control" placeholder="Ví dụ: Đội Hàn Boong 1" required>
                    </div>
                    <div class="form-group">
                        <label>Username Đội trưởng chịu trách nhiệm:</label>
                        <input type="text" id="leader_user" class="form-control" placeholder="Nhập username đội trưởng" required>
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
                <h3 style="margin-top:0; font-weight:800; color: #b45309;">📥 Danh Sách Đội Trưởng Báo Cáo Chờ Duyệt</h3>
                <div id="manager-approval-list">
                    <?php
                    $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id WHERE t.status = 'Chờ duyệt' AND t.manager_user = ?");
                    $stmt->execute([$username]);
                    $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if(count($approvals) == 0) echo '<p style="color:#64748b; font-size:14px;">Hiện tại chưa có báo cáo nào gửi lên.</p>';
                    foreach($approvals as $task):
                    ?>
                        <div class="task-item" style="background: #fffbeb; padding: 12px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #fef08a;">
                            <strong>[<?= htmlspecialchars($task['project_name']); ?>] - <?= htmlspecialchars($task['task_name']); ?></strong><br>
                            <span style="font-size:13px; color:#475569;">Đội phụ trách: <?= htmlspecialchars($task['group_name']); ?> | Số quân: <?= $task['worker_count']; ?> người</span><br>
                            <div style="margin: 6px 0; font-size:14px;">
                                Tiến độ hiện tại: <strong style="color:var(--primary);"><?= $task['current_progress']; ?>%</strong> 
                                ➔ Đội trưởng báo đạt: <strong style="color:var(--success);"><?= $task['pending_progress']; ?>%</strong>
                            </div>
                            <p style="font-size:13px; background:#fff; padding:6px; border-radius:4px; margin: 5px 0;">💬 <i>Báo cáo thực tế: <?= htmlspecialchars($task['leader_note']); ?></i></p>
                            <div style="display:flex; gap:8px; margin-top:8px;">
                                <button class="btn btn-success" style="padding:5px 12px; font-size:13px;" onclick="approveDecision(<?= $task['id']; ?>, 'approve')">✔ DUYỆT ĐỒNG Ý</button>
                                <button class="btn btn-danger" style="padding:5px 12px; font-size:13px;" onclick="approveDecision(<?= $task['id']; ?>, 'reject')">❌ TỪ CHỐI</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($user_role === 'leader'): ?>
        <div class="glass-card">
            <h3 style="margin-top:0; font-weight:800; color: var(--primary);">📝 Nhiệm Vụ Được Giao & Báo Cáo Kết Quả</h3>
            <div id="leader-task-list">
                <?php
                $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id WHERE t.leader_user = ? AND t.status IN ('Đang làm', 'Bị từ chối')");
                $stmt->execute([$username]);
                $my_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if(count($my_tasks) == 0) echo '<p style="color:#64748b; font-size:14px;">Hôm nay bạn chưa có nhiệm vụ nào được giao.</p>';
                foreach($my_tasks as $task):
                ?>
                    <div class="task-item" style="background:#f8fafc; padding:12px; border-radius:8px; margin-bottom:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:start;">
                            <strong>[<?= htmlspecialchars($task['project_name']); ?>] <?= htmlspecialchars($task['task_name']); ?></strong>
                            <span class="task-badge bg-<?= strtolower(str_replace(' ', '', $task['status'] == 'Bị từ chối' ? 'tuchoi' : 'danglam')); ?>"><?= $task['status']; ?></span>
                        </div>
                        <span style="font-size:13px; color:#64748b;">Quân số nhóm bạn đi làm hôm nay: <strong><?= $task['worker_count']; ?></strong> người.</span>
                        
                        <div style="margin-top: 8px;">
                            <span style="font-size:13px;">Tiến độ tích lũy hiện tại: <strong><?= $task['current_progress']; ?>%</strong></span>
                            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= $task['current_progress']; ?>%;"></div></div>
                        </div>

                        <div style="margin-top:12px; background:#fff; padding:10px; border-radius:6px; border:1px solid #cbd5e1;">
                            <h4 style="margin:0 0 8px 0; font-size:13px; font-weight:800;">Báo cáo tiến độ cuối chiều:</h4>
                            <div class="form-group">
                                <label>Tiến độ tích lũy mới ước lượng (%):</label>
                                <input type="number" id="progress-input-<?= $task['id']; ?>" class="form-control" min="<?= $task['current_progress']; ?>" max="100" value="<?= $task['current_progress']; ?>">
                            </div>
                            <div class="form-group">
                                <label>Nội dung chi tiết việc đã làm được hôm nay:</label>
                                <textarea id="note-input-<?= $task['id']; ?>" class="form-control" rows="2" placeholder="Ví dụ: Đã hàn ráp xong hết tấm boong số 3..."></textarea>
                            </div>
                            <button class="btn btn-success" style="padding:6px 12px; font-size:13px;" onclick="submitReport(<?= $task['id']; ?>)">🚀 GỬI BÁO CÁO DUYỆT</button>
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
                // Lấy tất cả công việc chưa hoàn thành hoặc vừa hoàn thành trong ngày để giám sát, sắp xếp việc cũ lên trước để tái chia việc
                $stmt = $conn->prepare("SELECT t.*, s.project_name FROM worker_tasks t JOIN ships s ON t.ship_id = s.id ORDER BY t.current_progress ASC, t.task_date DESC");
                $stmt->execute();
                $all_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach($all_tasks as $task):
                    $badge_class = 'danglam';
                    if($task['status'] === 'Chờ duyệt') $badge_class = 'choduyet';
                    if($task['status'] === 'Hoàn thành') $badge_class = 'hoanthanh';
                    if($task['status'] === 'Bị từ chối') $badge_class = 'tuchoi';
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
                            👷 Tổ: <strong><?= htmlspecialchars($task['group_name']); ?></strong> (<?= $task['worker_count']; ?> người) | Cập nhật gần nhất: <?= date('d/m', strtotime($task['task_date'])); ?>
                        </div>
                        <div style="margin-top:6px;">
                            <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700;">
                                <span>Tiến độ mục tiêu đạt được:</span>
                                <span style="color:var(--success);"><?= $task['current_progress']; ?>%</span>
                            </div>
                            <div class="progress-bar-bg"><div class="progress-bar-fill" style="width: <?= $task['current_progress']; ?>%;"></div></div>
                        </div>

                        <?php if ($user_role === 'manager' && $task['current_progress'] < 100 && $task['status'] !== 'Chờ duyệt'): ?>
                            <div style="margin-top:8px; text-align:right;">
                                <button class="btn" style="background:#0284c7; padding:4px 10px; font-size:12px;" 
                                    onclick="prepareFollowUpTask(<?= htmlspecialchars(json_encode($task)); ?>)">
                                    🔄 Tiếp tục chia việc ngày mai (Còn lại <?= 100 - $task['current_progress']; ?>%)
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

// 1. Nhóm trưởng gửi lệnh giao việc (Mới hoặc Tồn đọng)
function submitAssignForm() {
    const task_id = document.getElementById('edit_task_id').value;
    const ship_id = document.getElementById('ship_id').value;
    const task_name = document.getElementById('task_name').value.trim();
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
            alert('Lệnh giao việc đã được phát xuống công trường thành công!');
            location.reload();
        }
    });
}

// 2. Điền thông tin việc tồn đọng lên form để giao tiếp cho ngày mai
function prepareFollowUpTask(task) {
    document.getElementById('edit_task_id').value = task.id;
    document.getElementById('ship_id').value = task.ship_id;
    document.getElementById('task_name').value = task.task_name;
    document.getElementById('task_name').disabled = true; // Khóa tên việc để tránh làm gãy tiến độ cũ
    document.getElementById('group_name').value = task.group_name;
    document.getElementById('leader_user').value = task.leader_user;
    document.getElementById('worker_count').value = task.worker_count;
    
    document.getElementById('form-title').innerText = `🔄 Tái Bố Trí Việc Tồn Đọng (Đang đạt ${task.current_progress}%)`;
    document.getElementById('btnCancelEdit').style.display = 'inline-block';
    document.getElementById('form-title').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('edit_task_id').value = "0";
    document.getElementById('task_name').disabled = false;
    document.getElementById('assignForm').reset();
    document.getElementById('form-title').innerText = "➕ Giao Việc Sáng Nay / Việc Ngày Mai";
    document.getElementById('btnCancelEdit').style.display = 'none';
}

// 3. Đội trưởng nộp báo cáo ca chiều
function submitReport(taskId) {
    const progress = document.getElementById('progress-input-' + taskId).value;
    const note = document.getElementById('note-input-' + taskId).value.trim();

    if(!note) {
        alert('Vui lòng nhập mô tả chi tiết khối lượng thực tế làm được chiều nay.');
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
            alert('Đã gửi báo cáo tiến độ ca chiều lên Nhóm trưởng chờ phê duyệt!');
            location.reload();
        }
    });
}

// 4. Nhóm trưởng Phê duyệt hay Từ chối báo cáo
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
            alert(decision === 'approve' ? 'Đã duyệt cập nhật phần trăm tiến độ!' : 'Đã từ chối và gửi trả yêu cầu cho đội trưởng.');
            location.reload();
        }
    });
}
</script>

<?php include 'footer.php'; ?>
