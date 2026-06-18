<?php
session_start();
require_once 'db_connect.php';

// --- ĐOẠN CẬP NHẬT: LƯU URL ĐỂ QUAY LẠI SAU KHI ĐĂNG NHẬP / ĐĂNG XUẤT ---
$_SESSION['back_url'] = $_SERVER['REQUEST_URI'];

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

// --- 1. LẤY DANH SÁCH TÀU VÀ KIỂM TRA QUYỀN PIC ---
$selected_ship = $_GET['ship_id'] ?? '';
// CẬP NHẬT: Lấy thêm cột status để phục vụ đổ màu nền tiêu chuẩn cho thẻ option
$ships_list = $conn->query("SELECT id, project_name, status, pic FROM ships ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Xác định quyền chỉnh sửa cho tàu đang chọn
$can_edit = false;
if ($is_admin) {
    $can_edit = true;
} elseif ($is_logged_in && $selected_ship) {
    foreach ($ships_list as $s) {
        if ($s['id'] == $selected_ship && $s['pic'] === $user_fullname) {
            $can_edit = true;
            break;
        }
    }
}

// --- 2. XỬ LÝ DỮ LIỆU (ADMIN HOẶC PIC) ---
if ($can_edit && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $ship_id = $_POST['ship_id'];
    $type = $_POST['type'];
    $content = $_POST['content'];

    if ($_POST['action'] == 'save') {
        if (!empty($_POST['id'])) { // Sửa
            $stmt = $conn->prepare("UPDATE materials_notes SET content = ? WHERE id = ?");
            $stmt->execute([$content, $_POST['id']]);
        } else { // Thêm mới
            $stmt = $conn->prepare("INSERT INTO materials_notes (ship_id, type, content) VALUES (?, ?, ?)");
            $stmt->execute([$ship_id, $type, $content]);
        }
    } elseif ($_POST['action'] == 'delete') {
        $stmt = $conn->prepare("DELETE FROM materials_notes WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    }
    header("Location: vat-tu-ghi-chu.php?ship_id=" . $ship_id);
    exit();
}

// --- 3. LẤY DỮ LIỆU HIỂN THỊ ---
$vattu = []; $ghichu = [];
$current_project_name = "";
if ($selected_ship) {
    $stmt = $conn->prepare("SELECT * FROM materials_notes WHERE ship_id = ? ORDER BY id ASC");
    $stmt->execute([$selected_ship]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($data as $row) {
        if ($row['type'] == 'vattu') $vattu[] = $row;
        else $ghichu[] = $row;
    }
    // Lấy tên tàu hiện tại phục vụ tiêu đề file Excel/Bản in
    foreach ($ships_list as $s) {
        if ($s['id'] == $selected_ship) {
            $current_project_name = $s['project_name'];
            break;
        }
    }
}

include 'header.php'; 
include 'sidebar.php';
?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
    :root { --primary-color: #27ae60; --dark-color: #2c3e50; --danger-color: #e74c3c; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; padding: 10px; margin: 0; color: #333; }
    .container { max-width: 1100px; margin: auto; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); box-sizing: border-box; }
    .header { border-bottom: 3px solid var(--primary-color); padding-bottom: 10px; margin-bottom: 15px; }
    .header mt-0 { margin-top: 0; }
    .header h2 { margin: 0; color: var(--dark-color); font-size: 1.5rem; text-align: center; }
    .filter-section { background: #e8f5e9; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    select { padding: 10px; border: 1px solid #ddd; border-radius: 6px; flex: 1; min-width: 200px; }
    #quick-search { width: 100%; padding: 12px; border: 2px solid var(--primary-color); border-radius: 8px; margin-bottom: 20px; box-sizing: border-box; outline: none; }
    .section-title { background: var(--dark-color); color: white; padding: 10px 15px; border-radius: 6px; margin-top: 30px; font-size: 1.1rem; border-left: 5px solid var(--primary-color); }
    .table-responsive { width: 100%; overflow-x: auto; margin-top: 10px; border-radius: 8px; border: 1px solid #dee2e6; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
    th { background: #f8f9fa; color: var(--dark-color); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; }
    tr:hover { background-color: #f9f9f9 !important; cursor: pointer; }
    tr.active-row { background-color: #f1f8ff !important; }
    .highlight { background-color: #ffeb3b; font-weight: bold; }
    .btn { border: none; border-radius: 4px; cursor: pointer; font-weight: 600; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .btn-sm { padding: 6px 15px; font-size: 0.75rem; gap: 3px; }
    .btn-save { background: var(--primary-color); color: white; }
    .btn-del { background: var(--danger-color); color: white; }
    .btn-add { background: var(--primary-color); color: white; margin: 15px 0; padding: 8px 16px; font-size: 0.85rem; }
    
    /* Định dạng cho các nút In và Excel mới thêm vào */
    .btn-print-custom { background: #1976d2; color: white; margin: 15px 0; padding: 8px 16px; font-size: 0.85rem; }
    .btn-excel-custom { background: #2e7d32; color: white; margin: 15px 0; padding: 8px 16px; font-size: 0.85rem; }
    .btn-group-custom { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

    .action-cols { white-space: nowrap; width: 120px; }
    .edit-form-container { display: flex; flex-direction: column; gap: 10px; width: 100%; padding: 5px; }
    .edit-input { width: 100%; padding: 8px; border: 1px solid var(--primary-color); border-radius: 4px; box-sizing: border-box; font-family: inherit; font-size: inherit; resize: vertical; }

    /* Quy định CSS cho cấu trúc IN ẤN Khổ đứng A4 */
    @media print {
        @page { size: A4 portrait; margin: 15mm 10mm; }
        body { background: #fff; color: #000; padding: 0; margin: 0; font-size: 13px; }
        .container { box-shadow: none; padding: 0; max-width: 100% !important; }
        header, footer, .sidebar, .filter-section, #quick-search, .btn, .btn-add, .btn-print-custom, .btn-excel-custom, th:last-child, td:last-child { display: none !important; }
        .table-responsive { overflow: visible; border: none; margin-top: 15px; }
        table { width: 100%; min-width: 100% !important; table-layout: fixed; }
        th, td { border: 1px solid #333 !important; padding: 8px 6px !important; font-size: 12px !important; color: #000 !important; background: transparent !important; word-wrap: break-word; }
        th { font-weight: bold; background-color: #f2f2f2 !important; }
        
        /* Ẩn tiêu đề của phần không liên quan dựa theo lớp chỉ định động bằng JS */
        body.print-vattu-only #section-ghichu, body.print-vattu-only #table-ghichu-wrapper { display: none !important; }
        body.print-ghichu-only #section-vattu, body.print-ghichu-only #table-vattu-wrapper { display: none !important; }
        
        .section-title { background: #333 !important; color: #fff !important; border-left: 5px solid #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin-top: 10px; }
    }
</style>

<div class="container">
    <div class="header">
        <h2>🛠 VẬT TƯ & GHI CHÚ</h2>
    </div>
    <div class="filter-section">
        <label><b>Chọn Dự án:</b></label>
        <?php
        // Tính toán màu nền ban đầu cho thẻ select tổng dựa vào tàu được chọn
        $select_bg = '#fff';
        foreach ($ships_list as $s) {
            if ($selected_ship == $s['id']) {
                if ($s['status'] === 'Chưa thi công') $select_bg = '#ffe6e6';
                elseif ($s['status'] === 'Đang thi công') $select_bg = '#fff3cd';
                elseif ($s['status'] === 'Đã bàn giao') $select_bg = '#d4edda';
                break;
            }
        }
        ?>
        <select onchange="this.style.backgroundColor=this.options[this.selectedIndex].style.backgroundColor; location.href='?ship_id='+this.value" style="background-color: <?= $select_bg ?>;">
            <option value="" style="background-color: #fff;">-- Chọn tàu quản lý --</option>
            <?php foreach($ships_list as $s): 
                $is_your_ship = ($is_logged_in && $s['pic'] === $user_fullname);
                
                // CẬP NHẬT: Phân loại màu nền option theo tiêu chuẩn trạng thái của tàu
                $opt_bg = '#fff';
                if ($s['status'] === 'Chưa thi công') {
                    $opt_bg = '#ffe6e6'; // Hồng
                } elseif ($s['status'] === 'Đang thi công') {
                    $opt_bg = '#fff3cd'; // Vàng
                } elseif ($s['status'] === 'Đã bàn giao') {
                    $opt_bg = '#d4edda'; // Xanh lá
                }
            ?>
                <option value="<?= $s['id'] ?>" <?= ($selected_ship == $s['id']) ? 'selected' : '' ?> style="background-color: <?= $opt_bg ?>;">
                    <?= htmlspecialchars($s['project_name']) ?> <?= $is_your_ship ? ' [Tàu của bạn]' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if($selected_ship): ?>
        <input type="text" id="quick-search" placeholder="🔍 Tìm kiếm nhanh nội dung..." onkeyup="doSearch()">

        <div id="content-area">
            <h3 class="section-title" id="section-vattu">📦 DANH MỤC VẬT TƯ</h3>
            <div class="table-responsive" id="table-vattu-wrapper">
                <table id="table-vattu">
                    <thead>
                        <tr><th width="40">STT</th><th>Nội dung</th><?php if($can_edit): ?><th class="action-cols">Thao tác</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php $stt=1; foreach($vattu as $v): ?>
                        <tr id="row-<?= $v['id'] ?>" onclick="highlightRow(this)">
                            <td><?= $stt++ ?></td>
                            <td class="searchable"><?= nl2br(htmlspecialchars($v['content'])) ?></td>
                            <?php if($can_edit): ?>
                            <td class="action-cols">
                                <button class="btn btn-sm btn-save" onclick="event.stopPropagation(); editRow(<?= $v['id'] ?>, <?= htmlspecialchars(json_encode($v['content'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, 'vattu')">Sửa</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa dòng này?')">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>"><input type="hidden" name="ship_id" value="<?= $selected_ship ?>">
                                    <button type="submit" class="btn btn-sm btn-del" onclick="event.stopPropagation();">Xóa</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="btn-group-custom">
                <?php if($can_edit): ?>
                    <button class="btn btn-add" onclick="addNewRow('vattu')">+ Thêm Vật tư mới</button>
                <?php endif; ?>
                <?php if($is_logged_in): ?>
                    <button class="btn btn-print-custom" onclick="printSection('vattu')">🖨 In danh mục vật tư</button>
                    <button class="btn btn-excel-custom" onclick="exportSectionToExcel('table-vattu', 'Danh_Muc_Vat_Tu')">📊 Xuất Excel vật tư</button>
                <?php endif; ?>
            </div>

            <h3 class="section-title" id="section-ghichu">📝 GHI CHÚ KỸ THUẬT</h3>
            <div class="table-responsive" id="table-ghichu-wrapper">
                <table id="table-ghichu">
                    <thead>
                        <tr><th width="40">STT</th><th>Nội dung</th><?php if($can_edit): ?><th class="action-cols">Thao tác</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php $stt=1; foreach($ghichu as $g): ?>
                        <tr id="row-<?= $g['id'] ?>" onclick="highlightRow(this)">
                            <td><?= $stt++ ?></td>
                            <td class="searchable"><?= nl2br(htmlspecialchars($g['content'])) ?></td>
                            <?php if($can_edit): ?>
                            <td class="action-cols">
                                <button class="btn btn-sm btn-save" onclick="event.stopPropagation(); editRow(<?= $g['id'] ?>, <?= htmlspecialchars(json_encode($g['content'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, 'ghichu')">Sửa</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Xóa dòng này?')">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>"><input type="hidden" name="ship_id" value="<?= $selected_ship ?>">
                                    <button type="submit" class="btn btn-sm btn-del" onclick="event.stopPropagation();">Xóa</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="btn-group-custom" style="margin-bottom: 20px;">
                <?php if($can_edit): ?>
                    <button class="btn btn-add" onclick="addNewRow('ghichu')">+ Thêm Ghi chú mới</button>
                <?php endif; ?>
                <?php if($is_logged_in): ?>
                    <button class="btn btn-print-custom" onclick="printSection('ghichu')">🖨 In ghi chú kỹ thuật</button>
                    <button class="btn btn-excel-custom" onclick="exportSectionToExcel('table-ghichu', 'Ghi_Chu_Ky_Thuat')">📊 Xuất Excel ghi chú</button>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <p style="text-align: center; padding: 40px; color: #95a5a6;">Vui lòng chọn Tàu để xem dữ liệu.</p>
    <?php endif; ?>
</div>

<script>
function highlightRow(row) {
    if(row.hasAttribute('data-editing')) return; // Không kích hoạt highlight khi đang sửa
    document.querySelectorAll('tr').forEach(r => r.classList.remove('active-row'));
    row.classList.add('active-row');
}

function addNewRow(type) {
    let table = document.getElementById('table-' + type).getElementsByTagName('tbody')[0];
    let rowCount = table.rows.length;
    let row = table.insertRow(rowCount);
    row.innerHTML = `
        <td colspan="3">
            <form method="POST" class="edit-form-container" id="form-new-${type}">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="type" value="${type}">
                <input type="hidden" name="ship_id" value="<?= $selected_ship ?>">
                <textarea name="content" class="edit-input" required rows="3" placeholder="Nhập nội dung mới..."></textarea>
                <div style="display:flex; gap:5px; justify-content: flex-end;">
                    <button type="submit" class="btn btn-sm btn-save">Lưu</button>
                    <button type="button" class="btn btn-sm btn-del" onclick="this.closest('tr').remove()">Hủy</button>
                </div>
            </form>
        </td>
    `;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function editRow(id, content, type) {
    let row = document.getElementById('row-' + id);
    row.removeAttribute('onclick'); // Tạm thời xóa sự kiện click dòng
    row.setAttribute('data-editing', 'true');
    row.classList.remove('active-row');
    
    row.innerHTML = `
        <td colspan="3">
            <form method="POST" class="edit-form-container" id="form-edit-${id}">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="type" value="${type}">
                <input type="hidden" name="ship_id" value="<?= $selected_ship ?>">
                <textarea name="content" class="edit-input" rows="4">${content}</textarea>
                <div style="display:flex; gap:5px; justify-content: flex-end;">
                    <button type="submit" class="btn btn-sm btn-save">Cập nhật</button> 
                    <button type="button" class="btn btn-sm btn-del" onclick="location.reload()">Hủy</button>
                </div>
            </form>
        </td>
    `;
}

function doSearch() {
    let input = document.getElementById('quick-search').value.toLowerCase();
    let targets = document.getElementsByClassName('searchable');
    for (let target of targets) {
        let text = target.textContent || target.innerText;
        if (input && text.toLowerCase().includes(input)) {
            let regex = new RegExp(`(${input})`, 'gi');
            target.innerHTML = text.replace(regex, '<span class="highlight">$1</span>');
        } else {
            target.innerHTML = text;
        }
    }
}

// Hàm JavaScript điều khiển chức năng IN riêng biệt từng bảng
function printSection(type) {
    // Thêm class đánh dấu vào thẻ body để CSS `@media print` nhận diện ẩn phần còn lại
    if(type === 'vattu') {
        document.body.classList.add('print-vattu-only');
        document.body.classList.remove('print-ghichu-only');
    } else {
        document.body.classList.add('print-ghichu-only');
        document.body.classList.remove('print-vattu-only');
    }
    
    // Gọi hộp thoại in của trình duyệt
    window.print();
    
    // Xóa class đánh dấu sau khi hoàn thành in hoặc hủy in để giao diện hiển thị lại bình thường
    document.body.classList.remove('print-vattu-only', 'print-ghichu-only');
}

// Hàm JavaScript trích xuất dữ liệu sạch của từng bảng sang tệp Excel độc lập
function exportSectionToExcel(tableId, filenamePrefix) {
    const table = document.getElementById(tableId);
    const rows = table.querySelectorAll("tr");
    const data = [];
    
    // Nhận diện trạng thái có cột Thao tác (can_edit) dựa trên số lượng cột tiêu đề
    const hasActionCol = table.querySelector("thead tr").children.length === 3;

    rows.forEach((row) => {
        const rowData = [];
        // Nếu có cột hành động, loại bỏ ô cuối cùng khi duyệt dữ liệu
        const cells = hasActionCol 
            ? row.querySelectorAll("th:not(:last-child), td:not(:last-child)") 
            : row.querySelectorAll("th, td");
            
        cells.forEach((cell) => {
            rowData.push(cell.innerText.trim());
        });
        
        if (rowData.length > 0) {
            data.push(rowData);
        }
    });

    // Chuyển đổi mảng dữ liệu thuần thành Worksheet của thư viện XLSX
    const ws = XLSX.utils.aoa_to_sheet(data);
    const wb = XLSX.utils.book_new();
    
    // Định danh tên sheet dựa theo bảng dữ liệu cần xuất
    const sheetName = tableId === 'table-vattu' ? 'Vat Tu' : 'Ghi Chu';
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    
    // Đặt tên file đầu ra kết hợp tên tàu đang chọn (đã xóa dấu khoảng trắng nếu có)
    const projectName = "<?= str_replace(' ', '_', $current_project_name) ?>";
    const fullFileName = `${filenamePrefix}_${projectName}.xlsx`;
    
    // Xuất tệp tin
    XLSX.writeFile(wb, fullFileName);
}
</script>

<?php include 'footer.php'; ?>
