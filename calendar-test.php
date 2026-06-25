<?php
session_start();
require_once 'db_connect.php';

$_SESSION['back_url'] = $_SERVER['REQUEST_URI'];

// =========================================================================
// 1. XỬ LÝ LẤY DỮ LIỆU SỰ KIỆN TỪ DATABASE (Đã thêm DISTINCT để tránh trùng)
// =========================================================================
$sql = "SELECT DISTINCT dr.id as report_id, dr.report_date, dr.job_content, s.project_name 
        FROM daily_reports dr 
        JOIN ships s ON dr.ship_id = s.id 
        WHERE dr.job_content LIKE '%(EVENT)%'
        AND s.is_locked = 0
        ORDER BY dr.report_date ASC";
$stmt = $conn->query($sql);
$events_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$js_events = [];
$ship_keywords = []; 

foreach ($events_raw as $ev) {
    $clean_content = trim(str_replace('(EVENT)', '', $ev['job_content']));
    
    // Nếu là sự kiện chung, chỉ hiển thị nội dung, không cần tiền tố "Tên tàu:"
    if ($ev['project_name'] === 'SỰ KIỆN CHUNG') {
        $formatted_title = $clean_content;
    } else {
        $formatted_title = $ev['project_name'] . ': ' . $clean_content;
    }
    
    $js_events[] = [
        'date'  => $ev['report_date'],
        'title' => $formatted_title,
        'ship'  => $ev['project_name'] 
    ];
    
    if (!in_array($ev['project_name'], $ship_keywords) && $ev['project_name'] !== 'SỰ KIỆN CHUNG') {
        $ship_keywords[] = $ev['project_name'];
    }
}

include 'header.php'; 
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kế Hoạch Tàu - Lịch Tháng Âm Dương</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a73e8;
            --border-color: #dadce0;
            --text-color: #3c4043;
            --bg-today: #e8f0fe;
            --bg-sunday: #f1f3f4;
            --bg-sat-alt: #eef2f7;
            --green-dark: #1b5e20;      
            --green-main: #2e7d32;      
            --green-light: #e8f5e9;     
            --green-light-hover: #c8e6c9;
        }

        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; color: var(--text-color); margin: 0; padding: 10px; }
        .calendar-scroller { width: 100%; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; padding-bottom: 15px; }
        .calendar-container { min-width: 1050px; max-width: 1300px; margin: 0 auto; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px 0 rgba(60,64,67,0.3), 0 4px 8px 3px rgba(60,64,67,0.15); overflow: hidden; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background-color: var(--green-light); border-bottom: 2px solid var(--green-main); }
        .header-center-group { display: flex; align-items: center; gap: 15px; }
        .calendar-header h2 { margin: 0; font-size: 24px; font-weight: 700; color: var(--green-dark); }
        .filter-dropdown, .print-dropdown { position: relative; display: inline-block; }
        .filter-btn, .print-btn { background-color: #ffffff; color: var(--text-color); border: 1px solid var(--border-color); padding: 8px 16px; font-size: 14px; font-weight: 500; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .filter-btn:hover, .print-btn:hover { background-color: #f1f3f4; border-color: #c3c4c7; }
        .print-btn { border-color: var(--green-main); color: var(--green-dark); }
        .print-btn:hover { background-color: var(--green-light); }
        .filter-content, .print-content { display: none; position: absolute; left: 0; top: 100%; margin-top: 5px; background-color: #ffffff; min-width: 220px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border: 1px solid var(--border-color); border-radius: 6px; z-index: 1000; padding: 8px 0; }
        .print-content { min-width: 150px; }
        .filter-content.show, .print-content.show { display: block; }
        .filter-item, .print-item { display: flex; align-items: center; padding: 8px 16px; font-size: 13px; cursor: pointer; user-select: none; transition: background 0.1s ease; }
        .filter-item:hover, .print-item:hover { background-color: #f1f3f4; }
        .filter-item input[type="checkbox"] { margin-right: 10px; width: 16px; height: 16px; cursor: pointer; accent-color: var(--green-main); }
        .filter-divider { height: 1px; background-color: var(--border-color); margin: 6px 0; }
        .btn-nav { background-color: var(--green-light); color: var(--green-dark); border: 1px solid var(--green-main); padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; transition: all 0.2s ease; }
        .btn-nav:hover { background-color: var(--green-light-hover); }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); width: 100%; }
        .weekday-header { text-align: center; font-weight: 600; padding: 14px 0; background-color: var(--green-main); color: #ffffff; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .weekday-header.sun-header { background-color: var(--green-dark); }
        .calendar-day { min-height: 130px; height: auto; border-right: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 8px; box-sizing: border-box; background-color: #ffffff; position: relative; display: flex; flex-direction: column; }
        .calendar-grid .calendar-day:nth-child(7n) { border-right: none; }
        .day-header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .day-number { font-weight: 700; font-size: 16px; color: #202124; width: 28px; height: 28px; line-height: 28px; text-align: center; border-radius: 50%; }
        .lunar-number { font-size: 11px; color: var(--green-dark); font-weight: 600; background-color: var(--green-light); padding: 2px 6px; border-radius: 4px; }
        .sat-off-text { text-align: center; font-size: 10px; font-weight: 700; color: #5f6368; background-color: rgba(0, 0, 0, 0.05); padding: 1px 0; margin-bottom: 6px; border-radius: 3px; letter-spacing: 0.5px; text-transform: uppercase; }
        .calendar-day.sunday-col { background-color: var(--bg-sunday); }
        .calendar-day.saturday-alt { background-color: var(--bg-sat-alt); }
        .calendar-day.today { background-color: var(--bg-today) !important; }
        .calendar-day.today .day-number { background: var(--primary-color); color: #fff; }
        .calendar-day.other-month { opacity: 0.35; }
        .event-list { display: flex; flex-direction: column; gap: 5px; flex-grow: 1; }
        .event-item { font-size: 12px; font-weight: 500; padding: 5px 8px; border-radius: 4px; color: white; white-space: normal; word-break: break-word; line-height: 1.4; text-align: left; box-shadow: 0 1px 2px rgba(0,0,0,0.1); height: auto !important; }
        
        /* Màu các tàu */
        .ship-color-0 { background-color: #d32f2f; }
        .ship-color-1 { background-color: #1976d2; }
        .ship-color-2 { background-color: #388e3c; }
        .ship-color-3 { background-color: #f57c00; }
        .ship-color-4 { background-color: #7b1fa2; }
        .ship-color-5 { background-color: #0097a7; }
        .ship-color-6 { background-color: #e65100; }
        .ship-color-7 { background-color: #c2185b; }
        .ship-default { background-color: #607d8b; }
        
        /* ĐÃ FIX: ÉP NỀN XÁM CHỮ TRẮNG CHO SỰ KIỆN CHUNG CHUẨN XÁC */
        .ship-general-event { 
            background-color: #6b7280 !important; 
            color: #ffffff !important; 
            font-weight: 500 !important;
            box-shadow: none !important; 
            border: none !important;
        }

        @media print {
            body > *:not(.calendar-scroller), header, footer, nav, sidebar, .main-header, .main-sidebar, .sidebar, #sidebar, #header, .btn-nav, .filter-dropdown, .print-dropdown { display: none !important; }
            html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; width: 100vw !important; height: 100vh !important; overflow: hidden !important; }
            .calendar-scroller { display: block !important; width: 100% !important; height: 100% !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; }
            .calendar-container { display: flex !important; flex-direction: column !important; width: 100% !important; height: 100% !important; min-width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; background: #fff !important; }
            .calendar-header { display: flex !important; justify-content: center !important; background: #ffffff !important; border-bottom: 2px solid var(--green-main) !important; padding: 8px 0 !important; flex: 0 0 auto !important; }
            .calendar-grid { flex: 1 1 auto !important; grid-template-rows: auto repeat(6, 1fr) !important; height: 100% !important; background: #fff !important; }
            .weekday-header { padding: 6px 0 !important; }
            .calendar-day { min-height: 0 !important; height: 100% !important; padding: 3px !important; }
            .event-item { padding: 3px 5px !important; font-size: 11px !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
        @media print { .print-size-a4 { size: A4 landscape; } .print-size-a3 { size: A3 landscape; } }
    </style>
</head>
<body>

<div class="calendar-scroller">
    <div class="calendar-container" id="printable-calendar-area">
        <div class="calendar-header">
            <button class="btn-nav" onclick="changeMonth(-1)">Tháng trước</button>
            <div class="header-center-group">
                <h2 id="month-year-display">Tháng -- / ----</h2>
                <div class="filter-dropdown">
                    <button class="filter-btn" id="filterBtn" onclick="toggleFilterDropdown(event)">
                        <span>Lọc theo tàu</span>
                        <small id="filter-counter">(Tất cả)</small>
                        <span>▼</span>
                    </button>
                    <div class="filter-content" id="filterDropdownContent">
                        <label class="filter-item">
                            <input type="checkbox" id="chk-all" checked onchange="handleSelectAllChange(this)">
                            <strong>Tất cả tàu</strong>
                        </label>
                        <div class="filter-divider"></div>
                        <div id="ship-checkboxes-list"></div>
                    </div>
                </div>
                <div class="print-dropdown">
                    <button class="print-btn" id="printBtn" onclick="togglePrintDropdown(event)">
                        <span>🖨️ In lịch</span>
                        <span>▼</span>
                    </button>
                    <div class="print-content" id="printDropdownContent">
                        <div class="print-item" onclick="triggerPrint('a4')">Xuất file / In khổ A4</div>
                        <div class="print-item" onclick="triggerPrint('a3')">Xuất file / In khổ A3</div>
                    </div>
                </div>
            </div>
            <button class="btn-nav" onclick="changeMonth(1)">Tháng sau</button>
        </div>

        <div class="calendar-grid">
            <div class="weekday-header">Thứ 2</div>
            <div class="weekday-header">Thứ 3</div>
            <div class="weekday-header">Thứ 4</div>
            <div class="weekday-header">Thứ 5</div>
            <div class="weekday-header">Thứ 6</div>
            <div class="weekday-header">Thứ 7</div>
            <div class="weekday-header sun-header">Chủ Nhật</div>
            <div id="calendar-days-box" style="display: contents;"></div>
        </div>
    </div>
</div>

<script>
// --- THUẬT TOÁN ĐỔI ÂM LỊCH NỘI BỘ (Hỗ trợ năm 2026) ---
function getLunarDate(dd, mm, yy) {
    if (yy === 2026) {
        var baseDates = [
            {d:1, m:1, ld:13, lm:11}, {d:1, m:2, ld:14, lm:12}, {d:1, m:3, ld:13, lm:1},
            {d:1, m:4, ld:15, lm:2},  {d:1, m:5, ld:15, lm:3},  {d:1, m:6, ld:16, lm:4},
            {d:1, m:7, ld:17, lm:5},  {d:1, m:8, ld:18, lm:6},  {d:1, m:9, ld:19, lm:7},
            {d:1, m:10, ld:19, lm:8}, {d:1, m:11, ld:21, lm:9}, {d:1, m:12, ld:22, lm:10}
        ];
        var lunarMonthDays = [0, 30, 29, 30, 29, 30, 29, 30, 29, 30, 29, 30, 30]; 
        var base = baseDates[mm - 1];
        var diff = dd - base.d;
        var finalDay = base.ld + diff;
        var finalMonth = base.lm;
        var maxDays = lunarMonthDays[finalMonth];
        if (finalDay > maxDays) {
            finalDay -= maxDays;
            finalMonth += 1;
            if (finalMonth > 12) finalMonth = 1;
        }
        return { day: finalDay, month: finalMonth };
    }
    return { day: dd, month: mm };
}

const vesselEvents = <?= json_encode($js_events ?? []); ?>;
const shipKeywords = <?= json_encode($ship_keywords ?? []); ?>;
let selectedShips = [...shipKeywords, 'SỰ KIỆN CHUNG'];

function getShipIndex(eventTitle, shipName) {
    if (shipName === 'SỰ KIỆN CHUNG') return -1;
    if (!eventTitle) return 999;
    for (let i = 0; i < shipKeywords.length; i++) {
        if (shipKeywords[i] && eventTitle.toLowerCase().includes(shipKeywords[i].toLowerCase())) {
            return i; 
        }
    }
    return 999;
}

function getShipColorClass(eventTitle, shipName) {
    if (shipName === 'SỰ KIỆN CHUNG') return 'ship-general-event';
    const index = getShipIndex(eventTitle, shipName);
    return index === 999 ? 'ship-default' : `ship-color-${index % 8}`; 
}

function toggleFilterDropdown(e) { e.stopPropagation(); document.getElementById('printDropdownContent').classList.remove('show'); document.getElementById('filterDropdownContent').classList.toggle('show'); }
function togglePrintDropdown(e) { e.stopPropagation(); document.getElementById('filterDropdownContent').classList.remove('show'); document.getElementById('printDropdownContent').classList.toggle('show'); }

document.addEventListener('click', function(e) {
    const filterDropdown = document.getElementById('filterDropdownContent');
    const filterBtn = document.getElementById('filterBtn');
    const printDropdown = document.getElementById('printDropdownContent');
    const printBtn = document.getElementById('printBtn');
    if (filterDropdown && !filterDropdown.contains(e.target) && filterBtn && !filterBtn.contains(e.target)) filterDropdown.classList.remove('show');
    if (printDropdown && !printDropdown.contains(e.target) && printBtn && !printBtn.contains(e.target)) printDropdown.classList.remove('show');
});

function initFilterCheckboxes() {
    const container = document.getElementById('ship-checkboxes-list');
    if(!container) return;
    container.innerHTML = '';
    shipKeywords.forEach((shipName) => {
        if(!shipName) return;
        const label = document.createElement('label');
        label.classList.add('filter-item');
        label.innerHTML = `
            <input type="checkbox" class="ship-chk-item" value="${shipName}" checked onchange="handleShipItemChange()">
            <span>${shipName}</span>
        `;
        container.appendChild(label);
    });
}

function handleSelectAllChange(allCb) {
    const items = document.querySelectorAll('.ship-chk-item');
    items.forEach(cb => cb.checked = allCb.checked);
    updateSelectedShipsList();
}

function handleShipItemChange() {
    const items = document.querySelectorAll('.ship-chk-item');
    const allCb = document.getElementById('chk-all');
    if(!allCb) return;
    const allChecked = Array.from(items).every(cb => cb.checked);
    const noneChecked = Array.from(items).every(cb => !cb.checked);
    allCb.checked = allChecked;
    allCb.indeterminate = (!allChecked && !noneChecked);
    updateSelectedShipsList();
}

function updateSelectedShipsList() {
    const items = document.querySelectorAll('.ship-chk-item');
    selectedShips = ['SỰ KIỆN CHUNG']; 
    items.forEach(cb => { if (cb.checked) selectedShips.push(cb.value); });
    const counter = document.getElementById('filter-counter');
    const allCb = document.getElementById('chk-all');
    if(counter && allCb) {
        if (allCb.checked) counter.innerText = '(Tất cả)';
        else if (selectedShips.length === 1) counter.innerText = '(Trống)';
        else counter.innerText = `(${selectedShips.length - 1} Tàu)`;
    }
    renderCalendar(currentDate);
}

let currentDate = new Date(); 
const anchorDate = new Date(2026, 4, 23); 

function renderCalendar(date) {
    const year = date.getFullYear();
    const month = date.getMonth();
    
    const monthDisplay = document.getElementById('month-year-display');
    if(monthDisplay) monthDisplay.innerText = `Tháng ${String(month + 1).padStart(2, '0')} / ${year}`;
    
    const daysBox = document.getElementById('calendar-days-box');
    if(!daysBox) return;
    daysBox.innerHTML = ''; 

    const firstDayOfMonth = new Date(year, month, 1);
    let startDayOfWeek = firstDayOfMonth.getDay();
    if (startDayOfWeek === 0) startDayOfWeek = 7; 
    const startOffset = startDayOfWeek - 1; 
    const startDate = new Date(year, month, 1 - startOffset);

    for (let i = 0; i < 42; i++) {
        const currentLoopDate = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate() + i);
        const dayDiv = document.createElement('div');
        dayDiv.classList.add('calendar-day');
        const dayOfWeek = currentLoopDate.getDay(); 
        const dateString = `${currentLoopDate.getFullYear()}-${String(currentLoopDate.getMonth() + 1).padStart(2, '0')}-${String(currentLoopDate.getDate()).padStart(2, '0')}`;

        if (dayOfWeek === 0) dayDiv.classList.add('sunday-col');

        let isSatOff = false;
        if (dayOfWeek === 6) {
            const diffTime = currentLoopDate.getTime() - anchorDate.getTime();
            const diffWeeks = Math.round(diffTime / (1000 * 60 * 60 * 24 * 7));
            if (Math.abs(diffWeeks) % 2 !== 0) {
                dayDiv.classList.add('saturday-alt');
                isSatOff = true; 
            }
        }

        if (currentLoopDate.getMonth() !== month) dayDiv.classList.add('other-month');
        const today = new Date();
        if (currentLoopDate.toDateString() === today.toDateString()) dayDiv.classList.add('today');

        const lunarObj = getLunarDate(currentLoopDate.getDate(), currentLoopDate.getMonth() + 1, currentLoopDate.getFullYear());
        const dayHeaderBox = document.createElement('div');
        dayHeaderBox.classList.add('day-header-box');

        const dayNumberSpan = document.createElement('span');
        dayNumberSpan.classList.add('day-number');
        dayNumberSpan.innerText = currentLoopDate.getDate();

        const lunarNumberSpan = document.createElement('span');
        lunarNumberSpan.classList.add('lunar-number');
        lunarNumberSpan.innerText = `${String(lunarObj.day).padStart(2, '0')}/${String(lunarObj.month).padStart(2, '0')}`; 

        dayHeaderBox.appendChild(dayNumberSpan);
        dayHeaderBox.appendChild(lunarNumberSpan);
        dayDiv.appendChild(dayHeaderBox);

        if (isSatOff) {
            const satOffDiv = document.createElement('div');
            satOffDiv.classList.add('sat-off-text');
            satOffDiv.innerText = 'SAT OFF';
            dayDiv.appendChild(satOffDiv);
        }

        const eventListBox = document.createElement('div');
        eventListBox.classList.add('event-list');

        const dayEvents = vesselEvents.filter(ev => ev.date === dateString);
        dayEvents.sort((a, b) => getShipIndex(a.title, a.ship) - getShipIndex(b.title, b.ship));

        // ĐÃ FIX TRIỆT ĐỂ: Loại bỏ hoàn toàn vòng lặp lồng nhau gây dư ô trống và lặp màu
        dayEvents.forEach(ev => {
            const isShipSelected = selectedShips.includes(ev.ship);
            if (isShipSelected) {
                // Tách lọc làm sạch chuỗi hiển thị
                let displayTitle = ev.title;
                if (ev.ship === 'SỰ KIỆN CHUNG') {
                    displayTitle = displayTitle.replace(/^SỰ KIỆN CHUNG\s*[:-]?\s*/i, '');
                }
                
                // Nếu chuỗi rỗng thì bỏ qua không tạo phần tử lỗi
                if (!displayTitle || displayTitle.trim() === '') return;

                const eventDiv = document.createElement('div');
                const colorClass = getShipColorClass(ev.title, ev.ship);
                eventDiv.classList.add('event-item', colorClass);
                eventDiv.innerText = displayTitle;
                eventListBox.appendChild(eventDiv);
            }
        });

        dayDiv.appendChild(eventListBox);
        daysBox.appendChild(dayDiv);
    }
}

function changeMonth(direction) { currentDate.setMonth(currentDate.getMonth() + direction); renderCalendar(currentDate); }
function triggerPrint(size) {
    document.body.classList.remove('print-size-a4', 'print-size-a3');
    if (size === 'a4') document.body.classList.add('print-size-a4');
    if (size === 'a3') document.body.classList.add('print-size-a3');
    document.getElementById('printDropdownContent').classList.remove('show');
    setTimeout(() => { window.print(); }, 150);
}

// Khởi chạy hệ thống dữ liệu
initFilterCheckboxes();
renderCalendar(currentDate);
</script>
</body>
</html>
<?php include 'footer.php'; ?>
