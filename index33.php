<?php
session_start();
require_once 'db_connect.php';

// ============================
// TIMEZONE
// ============================
date_default_timezone_set('Asia/Ho_Chi_Minh');
$today = date('Y-m-d');
$next_7_days = date('Y-m-d', strtotime('+7 days'));

// ============================
// USER INFO
// ============================
$is_logged_in = isset($_SESSION['user']);
$isAdmin = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$user_fullname = $_SESSION['fullname'] ?? '';
$display_name = $is_logged_in ? $user_fullname : 'Khách';

$current_username = isset($_SESSION['user']) ? strtolower(trim($_SESSION['user'])) : '';
$current_fullname = !empty($user_fullname) ? strtolower(trim($user_fullname)) : '';

// ============================
// CSRF TOKEN
// ============================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================
// AJAX ANNOUNCEMENT
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode([
            'success' => false,
            'message' => 'CSRF TOKEN KHÔNG HỢP LỆ'
        ]);
        exit;
    }

    if ($_POST['action'] === 'update_announcement' && $isAdmin) {
        $new_content = trim($_POST['content'] ?? '');
        $stmt = $conn->prepare("UPDATE system_announcements SET content = ? WHERE id = 1");

        if ($stmt->execute([$new_content])) {
            echo json_encode([
                'success' => true,
                'updated_content' => nl2br(htmlspecialchars($new_content))
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Không thể cập nhật database.'
            ]);
        }
        exit;
    }
}

include 'header.php';
include 'sidebar.php';

// ============================
// ANNOUNCEMENT
// ============================
$announcement_content = "";
if ($is_logged_in) {
    $announcement_content = $conn->query("SELECT content FROM system_announcements WHERE id = 1")->fetchColumn() ?? '';
}

// ============================
// NHÂN LỰC
// ============================
$sql_nhan_luc = "SELECT
                    dr.id as report_id,
                    dr.worker_count,
                    dr.job_content,
                    s.project_name,
                    s.group_name,
                    s.pic
                FROM daily_reports dr
                JOIN ships s ON dr.ship_id = s.id
                WHERE dr.report_date = ?
                AND dr.worker_count > 0";

$stmt_nhan_luc = $conn->prepare($sql_nhan_luc);
$stmt_nhan_luc->execute([$today]);
$list_nhan_luc = $stmt_nhan_luc->fetchAll(PDO::FETCH_ASSOC);

$total_workers = array_sum(array_column($list_nhan_luc, 'worker_count'));

// ============================
// EVENTS
// ============================
$sql_events = "SELECT
                    dr.report_date,
                    dr.job_content,
                    s.project_name
               FROM daily_reports dr
               JOIN ships s ON dr.ship_id = s.id
               WHERE dr.job_content LIKE '%(EVENT)%'
               AND dr.report_date BETWEEN ? AND ?
               ORDER BY dr.report_date ASC";

$stmt_events = $conn->prepare($sql_events);
$stmt_events->execute([$today, $next_7_days]);
$list_events = $stmt_events->fetchAll(PDO::FETCH_ASSOC);

// ============================
// ACTIVE SHIPS
// ============================
$sql_active_ships = "
SELECT
    s.id,
    s.project_name,
    s.status,
    s.ship_type,
    s.pic,
    s.group_name,
    u.fullname as manager_fullname,
    sp.progress_percent,
    MIN(dr.report_date) as start_date,
    ii.item_count as check_count
FROM ships s
LEFT JOIN users u ON s.pic = u.username
LEFT JOIN ship_progress sp ON s.id = sp.ship_id
LEFT JOIN daily_reports dr
    ON s.id = dr.ship_id
    AND dr.job_content LIKE '%(EVENT)%'
LEFT JOIN (
    SELECT ship_id, MAX(item_count) as item_count
    FROM inspection_items
    GROUP BY ship_id
) ii ON ii.ship_id = s.id
WHERE s.status IN ('Chưa thi công', 'Đang thi công')
GROUP BY s.id
ORDER BY
    (CASE WHEN s.status = 'Đang thi công' THEN 1 ELSE 2 END) ASC,
    start_date ASC,
    s.project_name ASC
";

$stmt_active_ships = $conn->query($sql_active_ships);
$active_ships = $stmt_active_ships->fetchAll(PDO::FETCH_ASSOC);

// ============================
// STATS
// ============================
$sql_stats = "SELECT
COUNT(*) as total,
SUM(CASE WHEN status = 'Đang thi công' THEN 1 ELSE 0 END) as active,
SUM(CASE WHEN status = 'Đã bàn giao' THEN 1 ELSE 0 END) as delivered
FROM ships";

$stats = $conn->query($sql_stats)->fetch(PDO::FETCH_ASSOC);
?>

<!-- Import Font chữ hiện đại từ Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<input type="hidden" id="global_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    background: #f8fafc; /* Nền xám mờ tinh tế hơn */
    min-height: 100vh;
    color: #0f172a;
    padding: 20px;
    -webkit-overflow-scrolling: touch;
    position: relative;
    overflow-x: hidden;
    letter-spacing: -0.01em;
}

/* BACKGROUND SHAPES */
.bg-shape {
    position: fixed;
    z-index: -1;
    opacity: 0.05;
    pointer-events: none;
    will-change: transform;
    transform: translateZ(0);
    animation: floatAnimation 20s infinite linear;
}

.shape-square {
    border: 3px solid #3b82f6;
    border-radius: 24px;
}

.shape-triangle {
    width: 0;
    height: 0;
    border-left: 40px solid transparent;
    border-right: 40px solid transparent;
    border-bottom: 70px solid #3b82f6;
    background: transparent !important;
}

.shape1 { width: 100px; height: 100px; top: 10%; left: 5%; }
.shape2 { top: 40%; right: 8%; transform: rotate(45deg); animation-duration: 25s; }
.shape3 { width: 70px; height: 70px; bottom: 12%; left: 10%; transform: rotate(15deg); }
.shape4 { top: 75%; right: 20%; animation-duration: 22s; }

@keyframes floatAnimation {
    0% { transform: translate3d(0, 0, 0) rotate(0deg); }
    50% { transform: translate3d(10px, -15px, 0) rotate(45deg); }
    100% { transform: translate3d(0, 0, 0) rotate(90deg); }
}

.main-wrapper {
    width: 100%;
    max-width: 1340px;
    margin: auto;
    position: relative;
    z-index: 1;
}

/* MODERN GLASS CARD */
.glass-card {
    position: relative;
    background: rgba(255, 255, 255, 0.75);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(241, 245, 249, 0.8);
    border-radius: 20px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
    transition: all 0.3s ease;
}

.panel {
    padding: 24px !important;
    display: flex;
    flex-direction: column;
}

.top-announcement-section {
    margin-bottom: 20px;
}

/* SECTION HEADINGS */
.section-title h2, .panel-title {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.02em;
}

/* ANNOUNCEMENT BOX */
.announcement-box {
    background: #fefce8;
    border-radius: 14px;
    padding: 16px 20px;
    border: 1px solid #fef08a;
    color: #713f12;
    line-height: 1.6;
    font-weight: 500;
    margin-top: 14px;
    font-size: 14px;
}

.ann-textarea {
    width: 100%;
    min-height: 100px;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    padding: 12px;
    resize: vertical;
    margin-top: 12px;
    display: none;
    font-family: inherit;
    font-size: 14px;
}

.admin-actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
}

.btn-ann-edit, .btn-ann-save, .btn-save {
    border: none;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-ann-edit {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #475569;
}
.btn-ann-edit:hover {
    background: #f8fafc;
}

.btn-ann-save, .btn-save {
    background: #3b82f6;
    color: white;
}
.btn-ann-save:hover, .btn-save:hover {
    background: #2563eb;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
}
.btn-save:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
    box-shadow: none;
}

/* CONTENT GRID */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 20px;
    width: 100%;
}

/* WORKER LIST (NHÂN LỰC) */
.nhan-luc-container {
    display: flex;
    flex-direction: column;
    width: 100%;
}

.nhan-luc-row {
    padding: 14px 8px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    border-radius: 10px;
    transition: all 0.2s ease;
}

.nhan-luc-row:hover {
    background-color: #f8fafc;
}

.nhan-luc-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.ship-name {
    font-size: 15px;
    font-weight: 600;
    color: #1e293b;
}

.group-name {
    color: #64748b;
    font-size: 13px;
    font-weight: 500;
    margin-left: 4px;
}

.worker-num {
    font-size: 18px;
    font-weight: 700;
    color: #3b82f6;
    background: #eff6ff;
    padding: 2px 12px;
    border-radius: 9999px;
}

.job-detail {
    display: none;
    margin-top: 12px;
    background: #ffffff;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 14px; 
}

.job-text {
    line-height: 1.6;
    white-space: pre-line;
    color: #475569;
    font-size: 13.5px;
}

.edit-icon {
    font-size: 15px;
    cursor: pointer;
    opacity: 0.6;
    transition: opacity 0.2s;
}
.edit-icon:hover { opacity: 1; }

.edit-area-wrapper {
    display: none;
    margin-top: 12px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 12px;
}

.edit-input-worker, .edit-textarea {
    width: 100%;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    margin-top: 6px;
    margin-bottom: 12px;
    font-size: 13.5px;
    font-family: inherit;
}

.total-row {
    margin-top: 10px;
    padding: 16px 8px 4px 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}

.total-row strong {
    font-size: 14px;
    color: #475569;
    letter-spacing: 0.05em;
}

.total-row .worker-num {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    background: #3b82f6;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

/* EVENTS LIST */
.events-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: 100%;
}

.event-item {
    background: #fff7ed;
    border: 1px solid #ffedd5;
    border-radius: 12px;
    padding: 14px;
    font-size: 13.5px;
    line-height: 1.6;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 10px;
}

.event-date {
    background: #f97316;
    color: white;
    padding: 3px 10px;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 0.02em;
    flex-shrink: 0;
}

/* SHIP SECTION */
.ship-section {
    padding: 24px;
    margin-bottom: 20px;
}

.ship-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
}

.ship-card {
    position: relative;
    overflow: hidden;
    border-radius: 16px;
    padding: 20px;
    cursor: pointer;
    background: #ffffff;
    border: 1px solid #f1f5f9;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.ship-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
}

/* Màu trạng thái thẻ tinh tế, sang trọng hơn */
.card-status-dang {
    border-left: 4px solid #3b82f6;
}

.card-status-chua {
    border-left: 4px solid #cbd5e1;
    background: #f8fafc;
}

.card-ship-name {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    display: block;
    margin-bottom: 8px;
    max-width: 85%;
}

.card-ship-type {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
}

.progress-container {
    width: 100%;
    height: 6px;
    background: #f1f5f9;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 12px;
}

.progress-fill {
    height: 100%;
    background: #3b82f6;
    border-radius: 9999px;
}

.progress-text {
    margin-top: 8px;
    display: block;
    font-size: 24px;
    font-weight: 800;
    color: #3b82f6;
}

.card-footer {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px dashed #e2e8f0;
    font-size: 12.5px;
    color: #64748b;
    line-height: 1.6;
    font-weight: 500;
}

.check-count-badge {
    position: absolute;
    top: 20px;
    right: 20px;
    background: #0f172a;
    color: white;
    font-size: 11px;
    width: 24px;
    height: 24px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.ship-events-detail {
    display: none;
    margin-top: 20px;
    border-radius: 16px;
    padding: 20px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}

.empty-box {
    padding: 24px;
    border-radius: 12px;
    background: #f8fafc;
    color: #94a3b8;
    text-align: center;
    font-size: 13.5px;
    font-weight: 500;
}

/* HERO / STATS SECTION */
.hero-section {
    margin-top: 20px;
}

.hero-left {
    padding: 24px;
}

.hero-title {
    font-size: 14px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.1em;
    margin-bottom: 16px;
    text-transform: uppercase;
}

.quick-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.quick-box {
    padding: 20px;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #f1f5f9;
}

.quick-text-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
}

.quick-value {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
}

/* RESPONSIVE */
@media(max-width: 1100px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media(max-width: 768px) {
    body { padding: 10px; }
    .glass-card { border-radius: 16px; }
    .panel { padding: 16px !important; }
    .ship-cards-grid { grid-template-columns: 1fr !important; gap: 12px; }
    .quick-stats { grid-template-columns: 1fr; gap: 10px; }
    .quick-box { padding: 16px; }
}
</style>

<div class="bg-shape shape-square shape1"></div>
<div class="bg-shape shape-triangle shape2"></div>
<div class="bg-shape shape-square shape3"></div>
<div class="bg-shape shape-triangle shape4"></div>

<div class="main-wrapper">

    <?php if ($is_logged_in): ?>
        <div class="top-announcement-section glass-card" style="padding: 24px;">
            <div class="section-title">
                <h2>📢 Thông báo hệ thống</h2>
            </div>
            <div class="announcement-box">
                <div id="annText"><?= nl2br(htmlspecialchars(trim($announcement_content))); ?></div>

                <?php if ($isAdmin): ?>
                    <textarea id="annEditArea" class="ann-textarea"><?= htmlspecialchars(trim($announcement_content)); ?></textarea>
                    <div class="admin-actions">
                        <button id="btnAnnEdit" class="btn-ann-edit" onclick="toggleAnnEdit()">Chỉnh sửa</button>
                        <button id="btnAnnSave" class="btn-ann-save" onclick="saveAnnouncement()" style="display:none;">Lưu lại</button>
                        <button id="btnAnnCancel" class="btn-ann-edit" style="display:none;" onclick="toggleAnnEdit()">Hủy</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="content-grid">
        <div class="panel glass-card">
            <div class="panel-header">
                <div>
                    <div class="panel-title">📋 Nhân lực hôm nay</div>
                    <div style="font-size:13px; color:#64748b; margin-top:4px; font-weight:500;">
                        <?php 
                        $days = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
                        echo $days[date('w')] . ', ' . date('d/m/Y');
                        ?>
                    </div>
                </div>
            </div>

            <div class="nhan-luc-container">
                <?php if (count($list_nhan_luc) > 0): ?>
                    <?php foreach ($list_nhan_luc as $index => $row): 
                        $ship_pic = isset($row['pic']) ? strtolower(trim($row['pic'])) : '';
                        
                        $can_edit_this_ship = false;
                        if ($isAdmin) {
                            $can_edit_this_ship = true;
                        } elseif ($is_logged_in && !empty($ship_pic)) {
                            if ($ship_pic === $current_username || $ship_pic === $current_fullname) {
                                $can_edit_this_ship = true;
                            }
                        }
                    ?>
                        <div class="nhan-luc-row" onclick="toggleJob(<?= $index; ?>)">
                            <div class="nhan-luc-meta">
                                <div>
                                    <span class="ship-name">
                                        <?= htmlspecialchars($row['project_name']); ?> 
                                        <span class="group-name">(<?= htmlspecialchars($row['group_name']); ?>)</span>
                                    </span>
                                </div>
                                <div class="worker-num" id="worker-display-<?= $index; ?>"><?= (int)$row['worker_count']; ?></div>
                            </div>

                            <div id="job-<?= $index; ?>" class="job-detail">
                                <div class="job-content-row">
                                    <div id="text-display-<?= $index; ?>" class="job-text"><?= htmlspecialchars(trim($row['job_content'])); ?></div>
                                    <?php if ($can_edit_this_ship): ?>
                                        <span class="edit-icon" onclick="event.stopPropagation(); showEditForm(<?= $index; ?>)">📝</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($can_edit_this_ship): ?>
                                    <div id="edit-form-<?= $index; ?>" class="edit-area-wrapper" onclick="event.stopPropagation();">
                                        <label style="font-weight: 600; font-size: 12.5px; color: #475569;">Số người</label>
                                        <input type="number" min="0" id="worker-input-<?= $index; ?>" class="edit-input-worker" value="<?= (int)$row['worker_count']; ?>">
                                        <label style="font-weight: 600; font-size: 12.5px; color: #475569;">Nội dung công việc</label>
                                        <textarea id="input-<?= $index; ?>" class="edit-textarea" oninput="autoResizeTextarea(this)"><?= htmlspecialchars(trim($row['job_content'])); ?></textarea>
                                        <button class="btn-save" onclick="updateData(<?= $index; ?>, <?= (int)$row['report_id']; ?>, this)">CẬP NHẬT</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="total-row">
                        <strong>TỔNG NHÂN LỰC</strong>
                        <div class="worker-num" id="total-workers-display"><?= $total_workers; ?></div>
                    </div>
                <?php else: ?>
                    <div class="empty-box">Không có dữ liệu nhân lực hôm nay.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel glass-card">
            <div class="panel-header">
                <div class="panel-title">📌 Sự kiện 7 ngày tới</div>
            </div>
            <div class="events-list">
                <?php if (count($list_events) > 0): ?>
                    <?php foreach ($list_events as $event): 
                        $clean_content = trim(str_replace('(EVENT)', '', $event['job_content']));
                    ?>
                        <div class="event-item">
                            <span class="event-date"><?= date('d/m', strtotime($event['report_date'])); ?></span>
                            <div>
                                <strong style="color: #0f172a;"><?= htmlspecialchars($event['project_name']); ?></strong>
                                <span style="color: #475569; margin-left: 4px;">- <?= htmlspecialchars($clean_content); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-box">Không có sự kiện quan trọng.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="ship-section glass-card">
        <div class="panel-header">
            <div class="panel-title">🚢 Các tàu gần nhất</div>
        </div>

        <div class="ship-cards-grid">
            <?php foreach ($active_ships as $s):
                $status_class = ($s['status'] == 'Chưa thi công') ? 'card-status-chua' : 'card-status-dang';
                $start_label = $s['start_date'] ? date('d/m/Y', strtotime($s['start_date'])) : 'N/A';
                $displayName = !empty($s['manager_fullname']) ? $s['manager_fullname'] : (!empty($s['pic']) ? $s['pic'] : 'Chưa phân công');
                $groupName = !empty($s['group_name']) ? $s['group_name'] : 'N/A';
                $percent = isset($s['progress_percent']) ? $s['progress_percent'] : 0;
            ?>
                <div class="ship-card <?= $status_class; ?>" onclick="toggleShipEvents(<?= $s['id']; ?>)">
                    <?php if ($s['status'] == 'Đang thi công' && !empty($s['check_count'])): ?>
                        <div class="check-count-badge"><?= $s['check_count']; ?></div>
                    <?php endif; ?>

                    <span class="card-ship-name"><?= htmlspecialchars($s['project_name']); ?></span>

                    <?php if ($s['status'] == 'Đang thi công'): ?>
                        <span class="progress-text"><?= $percent; ?>%</span>
                        <div class="progress-container">
                            <div class="progress-fill" style="width:<?= $percent; ?>%;"></div>
                        </div>
                    <?php else: ?>
                        <div class="card-ship-type"><?= htmlspecialchars($s['ship_type']); ?></div>
                        <div style="margin-top:10px; font-size:13px; color:#64748b;">Bắt đầu: <?= $start_label; ?></div>
                    <?php endif; ?>

                    <div class="card-footer">
                        👨‍✈️ <?= htmlspecialchars($displayName); ?>
                        <br>
                        🏗️ <?= htmlspecialchars($groupName); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="ship-events-container" class="ship-events-detail">
            <div id="ship-events-content">Đang tải dữ liệu...</div>
        </div>
    </div>

    <div class="hero-section glass-card">
        <div class="hero-left">
            <div class="hero-title" style="text-align: center;">Thông tin hệ thống tổng quát</div>

            <div class="quick-stats">
                <div class="quick-box">
                    <div class="quick-text-label">Tổng tàu</div>
                    <div class="quick-value" style="color: #64748b;"><?= (int)$stats['total']; ?></div>
                </div>
                <div class="quick-box" style="border-left: 4px solid #f59e0b;">
                    <div class="quick-text-label">Đang thi công</div>
                    <div class="quick-value" style="color: #d97706;"><?= (int)$stats['active']; ?></div>
                </div>
                <div class="quick-box" style="border-left: 4px solid #10b981;">
                    <div class="quick-text-label">Đã bàn giao</div>
                    <div class="quick-value" style="color: #059669;"><?= (int)$stats['delivered']; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentOpenShipId = null;
const csrfToken = document.getElementById('global_csrf_token').value;

function autoResizeTextarea(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

function toggleShipEvents(shipId) {
    const container = document.getElementById('ship-events-container');
    const content = document.getElementById('ship-events-content');

    if (currentOpenShipId === shipId) {
        container.style.display = 'none';
        currentOpenShipId = null;
    } else {
        container.style.display = 'block';
        content.innerHTML = 'Đang tải dữ liệu...';
        currentOpenShipId = shipId;

        container.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });

        fetch('get_main_events.php?ship_id=' + shipId)
            .then(response => response.text())
            .then(data => {
                content.innerHTML = data;
            })
            .catch(err => {
                content.innerHTML = 'Lỗi tải dữ liệu sự kiện';
            });
    }
}

function toggleJob(index) {
    const detail = document.getElementById('job-' + index);
    if(event.target.closest('.edit-area-wrapper') || event.target.classList.contains('edit-icon')) return;
    
    detail.style.display = detail.style.display === 'block' ? 'none' : 'block';
}

function showEditForm(index) {
    const form = document.getElementById('edit-form-' + index);
    const textarea = document.getElementById('input-' + index);
    
    if (form.style.display === 'block') {
        form.style.display = 'none';
    } else {
        form.style.display = 'block';
        autoResizeTextarea(textarea);
    }
}

function updateData(index, reportId, buttonElement) {
    const newContent = document.getElementById('input-' + index).value;
    const newWorkerCount = document.getElementById('worker-input-' + index).value;

    if(newWorkerCount < 0 || newWorkerCount === '') {
        alert('Số lượng nhân lực không hợp lệ!');
        return;
    }

    buttonElement.innerText = 'ĐANG LƯU...';
    buttonElement.disabled = true;

    const params = new URLSearchParams();
    params.append('id', reportId);
    params.append('content', newContent);
    params.append('worker_count', newWorkerCount);
    params.append('csrf_token', csrfToken);

    fetch('update_job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: params
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('text-display-' + index).innerText = newContent.trim();
            document.getElementById('worker-display-' + index).innerText = parseInt(newWorkerCount);
            document.getElementById('edit-form-' + index).style.display = 'none';

            let total = 0;
            document.querySelectorAll('.nhan-luc-row .worker-num').forEach(span => {
                total += parseInt(span.innerText) || 0;
            });
            document.getElementById('total-workers-display').innerText = total;

            alert('Đã cập nhật thành công!');
        } else {
            alert('Lỗi: ' + data.message);
        }
    })
    .catch(err => {
        alert('Lỗi kết nối máy chủ');
    })
    .finally(() => {
        buttonElement.innerText = 'CẬP NHẬT';
        buttonElement.disabled = false;
    });
}

function toggleAnnEdit() {
    const textDiv = document.getElementById('annText');
    const textarea = document.getElementById('annEditArea');
    const btnEdit = document.getElementById('btnAnnEdit');
    const btnSave = document.getElementById('btnAnnSave');
    const btnCancel = document.getElementById('btnAnnCancel');

    const isEditing = textarea.style.display === 'block';

    textDiv.style.display = isEditing ? 'block' : 'none';
    textarea.style.display = isEditing ? 'none' : 'block';

    btnEdit.style.display = isEditing ? 'inline-block' : 'none';
    btnSave.style.display = isEditing ? 'none' : 'inline-block';
    btnCancel.style.display = isEditing ? 'none' : 'inline-block';
}

async function saveAnnouncement() {
    const newContent = document.getElementById('annEditArea').value;
    const formData = new FormData();

    formData.append('action', 'update_announcement');
    formData.append('content', newContent);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            document.getElementById('annText').innerHTML = result.updated_content;
            toggleAnnEdit();
        } else {
            alert(result.message || 'Lỗi khi lưu thông báo');
        }
    } catch (e) {
        alert('Lỗi kết nối hệ thống');
    }
}
</script>

<?php include 'footer.php'; ?>
