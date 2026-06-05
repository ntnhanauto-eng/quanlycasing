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

// Lấy username hiện tại và ép về chữ thường để so sánh chính xác
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

// ============================
// NHÂN LỰC HÔM NAY (Đọc trước để lấy Index cho việc Jump-link)
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
// LOGIC TÌM KIẾM TOÀN BỘ CÁC TRANG TRÊN SIDEBAR & PHÂN TRANG
// ============================
$search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_results = [];
$total_search_rows = 0;
$limit = 10;
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($page - 1) * $limit;
$total_pages = 1;

if ($search_keyword !== '') {
    $search_param = "%$search_keyword%";

    // Sử dụng UNION ALL để tìm kiếm đồng thời trên tất cả các bảng chức năng (tương ứng các trang sidebar)
    $sql_global_search = "
        /* 1. Tìm trong bảng Tàu (Trang Quản lý tàu: ships.php) */
        SELECT 
            'Quản lý Tàu' as page_title, 
            CONCAT('Tàu: ', project_name, ' | Loại: ', ship_type, ' | Trạng thái: ', status, ' | Phụ trách: ', COALESCE(pic, 'Chưa rõ')) as match_content,
            CONCAT('ships.php?search=', :search1) as destination_url
        FROM ships
        WHERE project_name LIKE :search2 OR ship_type LIKE :search3 OR pic LIKE :search4 OR group_name LIKE :search5

        UNION ALL

        /* 2. Tìm trong bảng Nhật ký / Báo cáo hàng ngày (Trang Tiến độ / Nhân lực: daily_reports) */
        SELECT 
            'Nhật ký Tiến độ' as page_title,
            CONCAT('Ngày: ', DATE_FORMAT(dr.report_date, '%d/%m/%Y'), ' | Dự án: ', s.project_name, ' | Số người: ', dr.worker_count, ' | Nội dung: ', dr.job_content) as match_content,
            CONCAT('daily_reports.php?date=', dr.report_date) as destination_url
        FROM daily_reports dr
        JOIN ships s ON dr.ship_id = s.id
        WHERE dr.job_content LIKE :search6 OR s.project_name LIKE :search7

        UNION ALL

        /* 3. Tìm trong bảng Thành viên (Trang Quản lý tài khoản: users.php) */
        SELECT 
            'Quản lý Thành viên' as page_title,
            CONCAT('Tài khoản: ', username, ' | Họ tên: ', fullname, ' | Vai trò: ', role) as match_content,
            CONCAT('users.php?keyword=', :search8) as destination_url
        FROM users
        WHERE username LIKE :search9 OR fullname LIKE :search10 OR role LIKE :search11

        UNION ALL

        /* 4. Tìm trong danh mục hạng mục kiểm tra (Trang Hạng mục kiểm tra: inspection_items) */
        SELECT 
            'Hạng mục Kiểm tra' as page_title,
            CONCAT('Tàu ID: ', ship_id, ' | Số lượng hạng mục ghi nhận: ', item_count) as match_content,
            CONCAT('inspection.php?ship_id=', ship_id) as destination_url
        FROM inspection_items
        WHERE ship_id LIKE :search12
    ";

    // Truy vấn đếm tổng số dòng tìm được trước để phân trang
    $sql_count = "SELECT COUNT(*) FROM ($sql_global_search) as temp_search";
    $stmt_count = $conn->prepare($sql_count);
    
    // Bind các tham số cho câu lệnh count
    for ($i = 1; $i <= 12; $i++) {
        $stmt_count->bindValue(":search$i", $search_param, PDO::PARAM_STR);
    }
    $stmt_count->execute();
    $total_search_rows = $stmt_count->fetchColumn();
    $total_pages = ceil($total_search_rows / $limit);

    // Truy vấn lấy dữ liệu thật giới hạn theo LIMIT, OFFSET
    $sql_data = $sql_global_search . " LIMIT :limit OFFSET :offset";
    $stmt_data = $conn->prepare($sql_data);
    
    // Bind tham số tìm kiếm
    for ($i = 1; $i <= 12; $i++) {
        $stmt_data->bindValue(":search$i", $search_param, PDO::PARAM_STR);
    }
    // Bind tham số phân trang
    $stmt_data->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_data->execute();
    
    $search_results = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
}

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

<input type="hidden" id="global_csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family: 'Segoe UI', Tahoma, sans-serif;
    background: #f4f8fc;
    min-height:100vh;
    color:#1f2937;
    padding:12px;
    -webkit-overflow-scrolling: touch;
    position: relative;
    overflow-x: hidden;
}

/* BACKGROUND SHAPES EFFECT */
.bg-shape {
    position: fixed;
    z-index: -1;
    opacity: 0.04;
    pointer-events: none;
    will-change: transform;
    transform: translateZ(0);
    animation: floatAnimation 24s infinite linear;
}
.shape-square { border: 4px solid #1e40af; border-radius: 12px; }
.shape-triangle { width: 0; height: 0; border-left: 50px solid transparent; border-right: 50px solid transparent; border-bottom: 86.6px solid #1e40af; background: transparent !important; }
.shape1 { width: 120px; height: 120px; top: 15%; left: 8%; animation-duration: 25s; }
.shape2 { top: 45%; right: 10%; animation-duration: 30s; transform: rotate(45deg); }
.shape3 { width: 80px; height: 80px; bottom: 15%; left: 12%; animation-duration: 22s; transform: rotate(15deg); }
.shape4 { top: 70%; right: 25%; animation-duration: 28s; }
.shape5 { width: 100px; height: 100px; top: 5%; right: 30%; animation-duration: 35s; transform: rotate(115deg); }

@keyframes floatAnimation {
    0% { transform: translate3d(0, 0, 0) rotate(0deg); }
    50% { transform: translate3d(0, -20px, 0) rotate(90deg); }
    100% { transform: translate3d(0, 0, 0) rotate(180deg); }
}

.main-wrapper{
    width:100%;
    max-width:1400px;
    margin:auto;
    position: relative;
    z-index: 1;
}

/* GLASS CARD */
.glass-card{
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(248, 250, 252, 0.94) 100%);
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 24px;
    box-shadow: 0 10px 25px rgba(15,23,42,0.02), 0 2px 5px rgba(15,23,42,0.01);
    will-change: transform;
    transform: translateZ(0);
}

.panel {
    padding: 14px 24px 24px 24px !important;
    position: relative;
    display: flex;
    flex-direction: column;
}

.topbar > *, .top-announcement-section > *, .content-grid > *, .panel > *, .ship-section > * {
    position: relative;
    z-index: 1;
}

.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding:18px 24px;
    margin-bottom:18px;
}

.brand-title{ font-size:28px; font-weight:800; color:#0f172a; }
.brand-sub{ font-size:13px; color:#64748b; margin-top:4px; }
.user-area{ display:flex; align-items:center; gap:14px; }
.user-chip{ background:linear-gradient(135deg,#2563eb,#1d4ed8); color:white; padding:10px 16px; border-radius: 99px; font-size:14px; font-weight:700; box-shadow:0 6px 18px rgba(37,99,235,0.25); }

/* GLOBAL SEARCH UI & PAGINATION */
.search-container { margin-bottom: 18px; padding: 16px 24px; }
.search-form { display: flex; gap: 12px; }
.search-input { flex: 1; padding: 14px 20px; border-radius: 16px; border: 1px solid #cbd5e1; font-size: 15px; font-weight: 600; outline: none; background: rgba(255, 255, 255, 0.8); transition: all 0.2s; }
.search-input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15); background: #fff; }
.search-btn { padding: 0 26px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 16px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(37,99,235,0.2); }
.search-results-panel { margin-bottom: 18px; padding: 20px 24px; border: 1px solid #93c5fd; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); }
.search-result-item { background: white; padding: 14px 18px; border-radius: 14px; margin-top: 10px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; gap: 15px; }
.result-meta-tag { font-size: 11px; background: #3b82f6; color: white; padding: 4px 10px; border-radius: 6px; font-weight: 700; text-transform: uppercase; }
.result-link { display: inline-block; background: #2563eb; color: white !important; text-decoration: none; font-weight: 700; font-size: 13px; padding: 8px 14px; border-radius: 10px; box-shadow: 0 2px 6px rgba(37,99,235,0.15); transition: 0.2s; }
.result-link:hover { background: #1d4ed8; transform: translateY(-1px); }
.pagination-wrapper { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 18px; }
.page-link-btn { text-decoration: none; padding: 8px 14px; border-radius: 10px; background: white; border: 1px solid #cbd5e1; color: #334155; font-size: 13px; font-weight: 700; transition: all 0.2s; }
.page-link-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
.page-link-btn:hover:not(.active) { background: #f1f5f9; }

.auth-btn{ text-decoration:none; padding:10px 18px; border-radius:14px; font-size:14px; font-weight:700; transition: transform 0.2s; will-change: transform; }
.login-btn{ background:#2563eb; color:white; }
.logout-btn{ background:#ef4444; color:white; }
.auth-btn:hover{ transform:translateY(-2px); }

.top-announcement-section { margin-bottom: 18px; }
.hero-section{ display:grid; grid-template-columns: 1fr; gap:18px; margin-top:18px; }
.hero-left{ padding:28px; position:relative; overflow:hidden; }
.hero-left::before{ content:''; position:absolute; width:240px; height:240px; border-radius:50%; background:rgba(37,99,235,0.05); right:-80px; top:-80px; }
.hero-title{ font-size:34px; font-weight:900; color:#0f172a; margin-bottom:20px; }
.quick-stats{ display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
.quick-box{ padding:18px; border-radius:20px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; }
.bg-gray { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); border: 1px solid #cbd5e1; color: #334155; }
.bg-yellow { background: linear-gradient(135deg, #fef9c3, #fef08a); border: 1px solid #fef08a; color: #854d0e; }
.bg-green { background: linear-gradient(135deg, #dcfce7, #bbf7d0); border: 1px solid #bbf7d0; color: #166534; }
.quick-text-label { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.quick-value{ font-size:32px; font-weight:900; }

.section-title{ display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.section-title h2{ font-size:18px; font-weight:800; color:#0f172a; }
.announcement-box{ background: rgba(255, 251, 235, 0.9); border-radius:20px; padding:20px; border:1px solid #fde68a; color:#78350f; line-height:1.7; font-weight:600; }
.ann-textarea{ width:100%; min-height:120px; border-radius:16px; border:1px solid #ddd; padding:15px; resize:vertical; margin-top:12px; display:none; }
.admin-actions{ margin-top:14px; display:flex; gap:10px; }
.btn-ann-edit, .btn-ann-save, .btn-save{ border:none; padding:11px 18px; border-radius:14px; font-weight:700; cursor:pointer; transition:0.2s; }
.btn-ann-edit{ background:#fff; border:1px solid #d6b53c; color:#7c5800; }
.btn-ann-save, .btn-save{ background:linear-gradient(135deg,#16a34a,#15803d); color:white; }

.content-grid{ display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
.panel-header{ display:flex; align-items: flex-start; justify-content:space-between; margin-top: 0px; margin-bottom:16px; padding-top: 0px; width: 100%; }
.panel-title{ font-size:20px; font-weight:800; color:#0f172a; }

.nhan-luc-container { display: flex; flex-direction: column; width: 100%; }
.nhan-luc-row { padding: 14px 4px; cursor: pointer; transition: background-color 0.2s; }
.nhan-luc-row:hover { background-color: rgba(248, 250, 252, 0.6); }
.nhan-luc-meta { display: flex; justify-content: space-between; align-items: center; gap: 15px; }
.ship-name{ font-size:16px; font-weight:700; color:#1f2937; }
.group-name{ color:#64748b; font-size:13px; font-weight:600; }
.worker-num{ font-size:20px; font-weight:800; color:#2563eb; }
.job-detail{ display:none; margin-top:10px; background: rgba(248, 251, 255, 0.9); border-radius:12px; border:1px solid #e2e8f0; padding:12px;  }
.job-content-row{ display:flex; justify-content:space-between; align-items: flex-start; gap:10px; }
.job-text{ line-height:1.6; white-space: pre-line; color:#4b5563; font-size:14px; }
.edit-icon{ font-size:16px; cursor:pointer; flex-shrink: 0; }
.edit-area-wrapper{ display:none; margin-top:12px; border-top:1px dashed #cbd5e1; padding-top:12px; }
.edit-input-worker, .edit-textarea{ width:100%; padding:10px; border-radius:10px; border:1px solid #cbd5e1; margin-top:6px; margin-bottom:12px; font-size:14px; }
.edit-textarea { overflow-y: hidden; resize: none; }
.total-row { margin-top: 4px; padding: 16px 4px 4px 4px; border-top: 2px solid #94a3b8; display: flex; justify-content: space-between; align-items: center; width: 100%; }
.total-row strong { font-size: 16px; color: #0f172a; letter-spacing: 0.5px; }
.total-row .worker-num { font-size: 24px; font-weight: 900; }

.events-list{ display:flex; flex-direction:column; gap:12px; width: 100%; }
.event-item{ background: rgba(255, 247, 237, 0.9); border:1px solid #fed7aa; border-radius:16px; padding:14px; font-size:14px; line-height:1.6; }
.event-date{ display:inline-block; background:#ea580c; color:white; padding:4px 10px; border-radius:999px; margin-right:8px; font-weight:700; }

.ship-section{ padding:24px; margin-bottom: 18px; }
.ship-cards-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px; }
.ship-card{ position:relative; overflow:hidden; border-radius:22px; padding:22px; cursor:pointer; transition: transform 0.2s; will-change: transform; border:1px solid rgba(0,0,0,0.05); }
.ship-card:hover{ transform:translateY(-5px); }
.card-status-dang{ background:linear-gradient(135deg, rgba(255,251,235,0.95), rgba(254,240,138,0.95)); }
.card-status-chua{ background:linear-gradient(135deg, rgba(253,242,248,0.95), rgba(251,207,232,0.95)); }
.card-ship-name{ font-size:20px; font-weight:900; display:block; margin-bottom:10px; }
.card-ship-type{ font-size:13px; color:#475569; }
.progress-container{ width:100%; height:10px; background:#ffffff; border-radius:999px; overflow:hidden; margin-top:10px; }
.progress-fill{ height:100%; background:linear-gradient(90deg,#16a34a,#22c55e); }
.progress-text{ margin-top:10px; display:block; font-size:28px; font-weight:900; color:#166534; }
.card-footer{ margin-top:16px; padding-top:12px; border-top:1px dashed rgba(0,0,0,0.1); font-size:12px; color:#475569; font-weight:700; }
.check-count-badge{ position:absolute; top:14px; right:16px; background:#0f172a; color:white; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; }
.ship-events-detail{ display:none; margin-top:20px; border-radius:20px; padding:20px; background: rgba(248, 251, 255, 0.9); border:1px solid #dbeafe; }
.empty-box{ padding:18px; border-radius:18px; background: rgba(248, 250, 252, 0.8); color:#64748b; text-align:center; }

@media(max-width:1100px){ .content-grid{ grid-template-columns:1fr; } }
@media(max-width:768px){
    body { padding: 6px; }
    .glass-card { border-radius: 16px; }
    .topbar { flex-direction: column; align-items: flex-start; padding: 10px 14px; gap: 10px; margin-bottom: 10px; }
    .brand-title { font-size: 22px; }
    .user-area { width: 100%; justify-content: space-between; gap: 8px; }
    .search-container { padding: 10px 14px; }
    .search-input { padding: 10px; font-size: 14px; }
    .search-btn { padding: 0 16px; }
    .content-grid { gap: 10px; margin-bottom: 10px; }
    .panel { padding: 10px 14px 14px 14px !important; }
    .ship-cards-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 8px; }
    .hero-section { gap: 10px; margin-top: 10px; }
    .quick-stats { grid-template-columns: 1fr; gap: 8px; }
}
</style>

<div class="bg-shape shape-square shape1"></div>
<div class="bg-shape shape-triangle shape2"></div>
<div class="bg-shape shape-square shape3"></div>
<div class="bg-shape shape-triangle shape4"></div>
<div class="bg-shape shape-square shape5"></div>

<div class="main-wrapper">

    <div class="topbar glass-card">
        <div>
            <div class="brand-title">🚢 DASHBOARD</div>
            <div class="brand-sub">Hệ thống quản lý tiến độ & nhân lực công trường</div>
        </div>

        <div class="user-area">
            <?php if ($is_logged_in): ?>
                <div class="user-chip">
                    👋 <?= htmlspecialchars($_SESSION['user']); ?>
                </div>
                <a href="logout.php" class="auth-btn logout-btn">Đăng xuất</a>
            <?php else: ?>
                <a href="login.php" class="auth-btn login-btn">Đăng nhập</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="search-container glass-card">
        <form method="GET" action="index.php" class="search-form">
            <input type="text" name="search" class="search-input" placeholder="Tìm kiếm nội dung trên toàn trang và mọi mục trong hệ thống (Tàu, Thành viên, Nhật ký...)" value="<?= htmlspecialchars($search_keyword); ?>" required>
            <button type="submit" class="search-btn">TÌM KIẾM</button>
            <?php if($search_keyword !== ''): ?>
                <a href="index.php" class="btn-ann-edit" style="text-decoration: none; display: flex; align-items: center;">XÓA X</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($search_keyword !== ''): ?>
        <div class="search-results-panel glass-card" id="search-results-block">
            <div class="panel-title" style="color: #1e3a8a;">🔍 Kết quả tìm kiếm cho: "<?= htmlspecialchars($search_keyword); ?>" (Tìm thấy <?= $total_search_rows; ?> bản ghi)</div>
            <div style="margin-top: 12px;">
                <?php if (count($search_results) > 0): ?>
                    <?php foreach ($search_results as $res): ?>
                        <div class="search-result-item">
                            <div>
                                <span class="result-meta-tag">Trang: <?= $res['page_title']; ?></span>
                                <p style="margin-top: 8px; font-size: 14px; font-weight: 600; color: #374151; line-height: 1.5;"><?= htmlspecialchars($res['match_content']); ?></p>
                            </div>
                            <a href="<?= $res['destination_url']; ?>" class="result-link">Đi đến trang ➔</a>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrapper">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="index.php?search=<?= urlencode($search_keyword); ?>&p=<?= $i; ?>#search-results-block" class="page-link-btn <?= $page === $i ? 'active' : ''; ?>"><?= $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-box" style="background: white;">Không tìm thấy dữ liệu khớp với từ khóa trên toàn bộ hệ thống.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

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
        <div class="panel glass-card" id="today-manpower-panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">📋 Nhân lực hôm nay</div>
                    <div style="font-size:13px;color:#64748b;margin-top:6px;font-weight:600;">
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
                        <div class="nhan-luc-row" id="job-row-<?= $index; ?>" onclick="toggleJob(<?= $index; ?>)">
                            <div class="nhan-luc-meta">
                                <div>
                                    <span class="ship-name">
                                        <?= htmlspecialchars($row['project_name']); ?> 
                                        <span class="group-name">(<?= htmlspecialchars($row['group_name']); ?>)</span>
                                    </span>
                                </div>
                                <div class="worker-num" id="worker-display-<?= $index; ?>"><?= $row['worker_count']; ?></div>
                            </div>

                            <div id="job-<?= $index; ?>" class="job-detail">
                                <div class="job-content-row">
                                    <div id="text-display-<?= $index; ?>" class="job-text"><?= htmlspecialchars(trim($row['job_content'])); ?></div>
                                    <?php if ($can_edit_this_ship): ?>
                                        <span class="edit-icon" onclick="event.stopPropagation();showEditForm(<?= $index; ?>)">📝</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($can_edit_this_ship): ?>
                                    <div id="edit-form-<?= $index; ?>" class="edit-area-wrapper" onclick="event.stopPropagation();">
                                        <label>Số người</label>
                                        <input type="number" id="worker-input-<?= $index; ?>" class="edit-input-worker" value="<?= $row['worker_count']; ?>">
                                        <label>Nội dung công việc</label>
                                        <textarea id="input-<?= $index; ?>" class="edit-textarea"><?= htmlspecialchars(trim($row['job_content'])); ?></textarea>
                                        <button class="btn-save" onclick="updateData(<?= $index; ?>, <?= $row['report_id']; ?>)">CẬP NHẬT</button>
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
                            <strong><?= htmlspecialchars($event['project_name']); ?></strong>
                            - <?= htmlspecialchars($clean_content); ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-box">Không có sự kiện quan trọng.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="ship-section glass-card" id="all-ships-block">
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
                <div class="ship-card <?= $status_class; ?>" id="ship-card-<?= $s['id']; ?>" onclick="toggleShipEvents(<?= $s['id']; ?>)">
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
                        <div style="margin-top:10px;font-size:13px;color:#475569;">Bắt đầu: <?= $start_label; ?></div>
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

    <div class="hero-section">
        <div class="hero-left glass-card">
            <div class="hero-title" style="text-align: center;">THÔNG TIN CHUNG</div>
            <div class="quick-stats">
                <div class="quick-box bg-gray">
                    <div class="quick-text-label">Tổng tàu</div>
                    <div class="quick-value"><?= $stats['total']; ?></div>
                </div>
                <div class="quick-box bg-yellow">
                    <div class="quick-text-label">Đang thi công</div>
                    <div class="quick-value"><?= $stats['active']; ?></div>
                </div>
                <div class="quick-box bg-green">
                    <div class="quick-text-label">Đã bàn giao</div>
                    <div class="quick-value"><?= $stats['delivered']; ?></div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
let currentOpenShipId = null;
const csrfToken = document.getElementById('global_csrf_token').value;

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

        container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        fetch('get_main_events.php?ship_id=' + shipId)
            .then(response => response.text())
            .then(data => { content.innerHTML = data; })
            .catch(err => { content.innerHTML = 'Lỗi tải dữ liệu sự kiện'; });
    }
}

function toggleJob(index) {
    const detail = document.getElementById('job-' + index);
    detail.style.display = detail.style.display === 'block' ? 'none' : 'block';
}

function showEditForm(index) {
    const form = document.getElementById('edit-form-' + index);
    const textarea = document.getElementById('input-' + index);
    
    if (form.style.display === 'block') {
        form.style.display = 'none';
    } else {
        form.style.display = 'block';
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight + 24) + 'px';
    }
}

function updateData(index, reportId) {
    const newContent = document.getElementById('input-' + index).value;
    const newWorkerCount = document.getElementById('worker-input-' + index).value;
    const btn = event.currentTarget;

    btn.innerText = 'ĐANG LƯU...';
    btn.disabled = true;

    const params = new URLSearchParams();
    params.append('id', reportId);
    params.append('content', newContent);
    params.append('worker_count', newWorkerCount);
    params.append('csrf_token', csrfToken);

    fetch('update_job.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('text-display-' + index).innerText = newContent.trim();
            document.getElementById('worker-display-' + index).innerText = newWorkerCount;
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
    .catch(err => { alert('Lỗi kết nối máy chủ'); })
    .finally(() => {
        btn.innerText = 'CẬP NHẬT';
        btn.disabled = false;
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
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const result = await response.json();

        if (result.success) {
            document.getElementById('annText').innerHTML = result.updated_content;
            toggleAnnEdit();
        } else {
            alert(result.message || 'Lỗi khi lưu thông báo');
        }
    } catch (e) { alert('Lỗi kết nối hệ thống'); }
}
</script>

<?php include 'footer.php'; ?>
