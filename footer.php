</main> <?php
// Đảm bảo kết nối database đã sẵn sàng
if (isset($conn)) {
    // 1. Lấy thông tin IP người dùng hiện tại
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $user_ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');

    // Giới hạn: Mỗi IP chỉ tính 1 lượt truy cập trong vòng 1 tiếng để tránh spam reload
    $check_stmt = $conn->prepare("SELECT id FROM web_visitors WHERE ip_address = ? AND visit_date = ? AND visit_time > SUBTIME(?, '01:00:00') LIMIT 1");
    $check_stmt->execute([$user_ip, $current_date, $current_time]);
    
    if (!$check_stmt->fetch()) {
        // Có thể tích hợp API lấy vị trí nếu muốn, tạm thời mặc định hoặc gán 'Việt Nam'
        $location = 'Việt Nam'; 
        
        $ins_stmt = $conn->prepare("INSERT INTO web_visitors (ip_address, visit_date, visit_time, location_info) VALUES (?, ?, ?, ?)");
        $ins_stmt->execute([$user_ip, $current_date, $current_time, $location]);
    }

    // 2. Tính toán các thông số thống kê
    // Hôm nay
    $count_today = $conn->query("SELECT COUNT(*) FROM web_visitors WHERE visit_date = '$current_date'")->fetchColumn();
    
    // Tuần này (Tính từ Thứ 2 đến Chủ nhật)
    $count_week = $conn->query("SELECT COUNT(*) FROM web_visitors WHERE YEARWEEK(visit_date, 1) = YEARWEEK('$current_date', 1)")->fetchColumn();
    
    // Tháng này
    $count_month = $conn->query("SELECT COUNT(*) FROM web_visitors WHERE MONTH(visit_date) = MONTH('$current_date') AND YEAR(visit_date) = YEAR('$current_date')")->fetchColumn();
    
    // Tổng số
    $count_total = $conn->query("SELECT COUNT(*) FROM web_visitors")->fetchColumn();
} else {
    $count_today = $count_week = $count_month = $count_total = 0;
}

// Xử lý Request API lấy dữ liệu IP cho riêng Admin (Bảo mật, không echo trực tiếp ra HTML ban đầu)
if (isset($_GET['action']) && $_GET['action'] === 'get_visitor_logs' && isset($isAdmin) && $isAdmin) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $logs = [];
    if (isset($conn)) {
        $log_stmt = $conn->query("SELECT ip_address, visit_date, visit_time, location_info FROM web_visitors ORDER BY id DESC LIMIT 30");
        $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($logs);
    exit;
}
?>

<style>
    .footer {
        background-color: #f8f9fa; /* Màu nền xám cực nhẹ */
        color: #555;              /* Màu chữ xám đậm cho dịu mắt */
        text-align: center;       /* CĂN GIỮA CHỮ */
        padding: 20px 10px;       /* Tạo khoảng cách trên dưới */
        font-size: 13px;
        border-top: 1px solid #e0e0e0; /* Đường kẻ mỏng ngăn cách với nội dung */
        margin-top: 30px;
    }

    /* Phong cách bộ đếm */
    .visitor-counter {
        margin-top: 10px;
        display: inline-flex;
        gap: 15px;
        background: #ffffff;
        padding: 6px 16px;
        border-radius: 20px;
        border: 1px solid #e2e8f0;
        font-weight: 600;
        color: #475569;
        box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        font-size: 12px;
    }
    
    .visitor-counter span strong {
        color: #1e40af;
    }

    /* Quyền bấm cho admin */
    .admin-clickable {
        cursor: pointer;
        transition: background 0.2s;
    }
    .admin-clickable:hover {
        background: #eff6ff;
        border-color: #bfdbfe;
    }

    /* Modal hiển thị danh sách IP */
    .visitor-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0; top: 0; width: 100%; height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .visitor-modal-content {
        background-color: #fff;
        margin: 10% auto;
        padding: 20px;
        border-radius: 16px;
        width: 90%;
        max-width: 600px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        position: relative;
    }
    .close-modal {
        position: absolute;
        right: 20px; top: 15px;
        font-size: 24px; font-weight: bold; color: #aaa; cursor: pointer;
    }
    .close-modal:hover { color: #000; }
    .table-wrapper { max-height: 350px; overflow-y: auto; margin-top: 15px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .visitor-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
    .visitor-table th, .visitor-table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
    .visitor-table th { background-color: #f1f5f9; color: #1e293b; font-weight: 700; position: sticky; top: 0; }
    .visitor-table tr:hover { background-color: #f8fafc; }
</style>

<div class="footer">
    <div>© 04-2026 Developed by Nguyen Thanh Nhan - E/R Outfitting 1</div>
    
    <div class="visitor-counter <?= ($isAdmin) ? 'admin-clickable' : ''; ?>" id="counterBox" <?= ($isAdmin) ? 'onclick="openVisitorModal()"' : ''; ?> title="<?= ($isAdmin) ? 'Click để xem chi tiết lịch sử IP' : ''; ?>">
        <span>Ngày: <strong><?= number_format($count_today); ?></strong></span> |
        <span>Tuần: <strong><?= number_format($count_week); ?></strong></span> |
        <span>Tháng: <strong><?= number_format($count_month); ?></strong></span> |
        <span>Tổng: <strong><?= number_format($count_total); ?></strong></span>
    </div>
</div>

<?php if ($isAdmin): ?>
<div id="visitorModal" class="visitor-modal" onclick="closeVisitorModal(event)">
    <div class="visitor-modal-content" onclick="event.stopPropagation()">
        <span class="close-modal" onclick="document.getElementById('visitorModal').style.display='none'">&times;</span>
        <h3 style="margin-bottom: 5px; color: #0f172a; font-weight: 800;">🌐 30 lượt truy cập gần nhất</h3>
        <p style="font-size: 12px; color: #64748b; margin: 0;">Thông tin thời gian thực hệ thống ghi nhận</p>
        
        <div class="table-wrapper">
            <table class="visitor-table">
                <thead>
                    <tr>
                        <th>Địa chỉ IP</th>
                        <th>Thời gian</th>
                        <th>Vị trí</th>
                    </tr>
                </thead>
                <tbody id="visitorLogBody">
                    <tr>
                        <td colspan="3" style="text-align:center; color:#94a3b8;">Đang tải dữ liệu...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function openVisitorModal() {
    document.getElementById('visitorModal').style.display = 'block';
    
    // Gọi Ajax lấy dữ liệu thời gian thực mà không làm tải lại trang index
    fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'action=get_visitor_logs')
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('visitorLogBody');
            tbody.innerHTML = '';
            
            if(data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Chưa có dữ liệu.</td></tr>';
                return;
            }
            
            data.forEach(log => {
                // Định dạng lại ngày hiển thị dd/mm/yyyy
                const dateParts = log.visit_date.split('-');
                const formattedDate = dateParts[2] + '/' + dateParts[1] + '/' + dateParts[0];
                
                const row = `<tr>
                    <td style="font-family: monospace; font-weight: bold; color: #2563eb;">\${log.ip_address}</td>
                    <td>\${formattedDate} \${log.visit_time}</td>
                    <td><span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;">\${log.location_info}</span></td>
                </tr>`;
                tbody.innerHTML += row;
            });
        })
        .catch(err => {
            document.getElementById('visitorLogBody').innerHTML = '<tr><td colspan="3" style="text-align:center; color:red;">Lỗi tải dữ liệu.</td></tr>';
        });
}

function closeVisitorModal(e) {
    if(e.target.id === 'visitorModal') {
        document.getElementById('visitorModal').style.display = 'none';
    }
}
</script>
<?php endif; ?>

</body>
</html>
