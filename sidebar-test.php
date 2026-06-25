<!-- NÚT MENU -->  <div class="menu-toggle" onclick="toggleSidebar()">  
    ☰  
</div>  <!-- LỚP NỀN MỜ -->  <div id="sidebar-overlay" class="sidebar-overlay" onclick="closeSidebar()"></div>  <!-- SIDEBAR -->  <div id="sidebar" class="sidebar">  <!-- HEADER -->  
<div class="sidebar-header">  
    MENU  
</div> 
   <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>  
<!-- Nút này chỉ Admin mới thấy -->  
<div class="admin-only-section">
    <a href="quan-ly-thanh-vien.php" class="btn-admin">  
        👥 Quản lý thành viên  
    </a>  
    <a href="xem-lich-su.php"  
   class="<?= (basename($_SERVER['SCRIPT_NAME']) == 'xem-lich-su.php') ? 'active' : '' ?>">  
    <i class="fas fa-user-circle"></i> Lịch sử Users  
</a>  
     </a>  
    <a href="su-kien-chung.php"  
   class="<?= (basename($_SERVER['SCRIPT_NAME']) == 'su-kien-chung.php') ? 'active' : '' ?>">  
    <i class="fas fa-user-circle"></i> Sự kiện chung  
</a>  
</div>  
     <?php endif; ?>

<?php if (isset($_SESSION['user_id'])): ?>  <a href="profile.php"  
   class="<?= (basename($_SERVER['SCRIPT_NAME']) == 'profile.php') ? 'active' : '' ?>">  
    <i class="fas fa-user-circle"></i> Hồ sơ cá nhân  
</a>  
   
<a href="quanlycong.php"  
   class="<?= (basename($_SERVER['SCRIPT_NAME']) == 'quanlycong.php') ? 'active' : '' ?>">  
    <i class="fas fa-calendar-check"></i> Quản lý công  
</a>  
<a href="my-job.php"  
   class="<?= (basename($_SERVER['SCRIPT_NAME']) == 'my-job.php') ? 'active' : '' ?>">  
    <i class="fas fa-calendar-check"></i> Note cá nhân  
</a>  
<a href="logout.php" style="color: #dc3545;">  
    <i class="fas fa-sign-out-alt"></i> Đăng xuất  
</a>

<?php endif; ?>  <!-- NHÓM CHÍNH -->  
<div class="sidebar-title">PLAN</div>  

<a href="index.php">🏠 Trang chính</a>  
<a href="quan-ly-tau.php">🚢 Quản lý tàu</a>  
<a href="calendar.php">📅 Kế hoạch tháng </a>  
<a href="du-lieu.php">📊 Nhập công </a>  
<a href="su-kien-tau.php">🕒 Sự kiện</a>  
 

<!-- NGHIỆP VỤ -->  
<div class="sidebar-title">PRODUCTION</div>  
<a href="quan-ly-tien-do.php">📈 Tiến độ tàu</a> 
<a href="quan-ly-muc-kiem-tra.php">✅ Mục kiểm tra</a>  
<a href="vat-tu-ghi-chu.php">📦 Vật tư - Ghi chú</a>  
<a href="remain-jobs.php">⏳ Remain Jobs</a> 
 

<a href="bao-cao-tong-hop.php">📊 Báo cáo tổng hợp </a>
  

<!-- HỆ THỐNG -->  
  
<div class="sidebar-title">M.I.S SYSTEM</div>  
<a href="3d-block.php">🧊 3D Block</a>  
<a href="thoi-tiet.php">🌦️ Thời tiết</a>  

<a href="https://hr.hd-hvs.com/#/v10a2000/" target="_blank">  
    📋 Human  
</a>  

<a href="https://pp.hd-hvs.com/#/v20Menu/" target="_blank">  
    ⚙️ Production  
</a>  

<a href="https://tqm.hd-hvs.com/#/v80Menu/" target="_blank">  
    🛡️ TQM  
</a>

</div>  <style>  
  
/* NÚT MENU */  
  
.menu-toggle {  
    position: fixed;  
    top: 15px;  
    left: 15px;  
  
    width: 44px;  
    height: 44px;  
  
    background: #28a745;  
    color: white;  
  
    font-size: 24px;  
    font-weight: bold;  
  
    border-radius: 12px;  
  
    display: flex;  
    align-items: center;  
    justify-content: center;  
  
    cursor: pointer;  
  
    z-index: 2001;  
  
    box-shadow: 0 4px 12px rgba(0,0,0,0.18);  
  
    transition:  
        transform 0.2s ease,  
        box-shadow 0.2s ease,  
        background 0.2s ease;  
}  
  
.menu-toggle:hover {  
    background: #23913d;  
    transform: translateY(-2px);  
    box-shadow: 0 8px 18px rgba(0,0,0,0.25);  
}  
  
.menu-toggle:active {  
    transform: scale(0.92);  
}  
  
/* OVERLAY */  
  
.sidebar-overlay {  
    position: fixed;  
    inset: 0;  
  
    background: rgba(0,0,0,0.45);  
  
    opacity: 0;  
    visibility: hidden;  
  
    transition: 0.25s ease;  
  
    z-index: 1998;  
}  
  
.sidebar-overlay.active {  
    opacity: 1;  
    visibility: visible;  
}  
  
/* SIDEBAR */  
  
.sidebar {  
    position: fixed;  
  
    top: 0;  
    left: -280px;  
  
    width: 260px;  
    height: 100%;  
  
    background: #ffffff;  
  
    z-index: 2000;  
  
    overflow-y: auto;  
  
    box-shadow: 4px 0 18px rgba(0,0,0,0.18);  
  
    padding-bottom: 30px;  
  
    transition:  
        left 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);  
}  
  
.sidebar.active {  
    left: 0;  
}  
  
/* HEADER */  
  
.sidebar-header {  
    padding: 20px;  
  
    background: #28a745;  
    color: white;  
  
    font-size: 22px;  
    font-weight: bold;  
  
    text-align: center;  
    letter-spacing: 1px;  
  
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);  
}  
  
/* TIÊU ĐỀ NHÓM */  
  
.sidebar-title {  
    padding: 10px 18px;  
  
    background: #f1f3f5;  
  
    color: #666;  
  
    font-size: 12px;  
    font-weight: bold;  
  
    letter-spacing: 0.5px;  
  
    border-top: 1px solid #e5e5e5;  
    border-bottom: 1px solid #e5e5e5;  
}  
  
/* MENU ITEM */  
  
.sidebar a {  
    display: flex;  
    align-items: center;  
  
    gap: 10px;  
  
    padding: 14px 20px;  
  
    color: #333;  
    text-decoration: none;  
  
    font-size: 15px;  
  
    border-bottom: 1px solid #f2f2f2;  
  
    transition:  
        background 0.2s ease,  
        padding-left 0.2s ease,  
        color 0.2s ease;  
}  
  
.sidebar a:hover {  
    background: #f5fff5;  
    padding-left: 28px;  
    color: #28a745;  
}  
  
/* MOBILE */  
  
@media (max-width: 600px) {  
  
    .sidebar {  
        width: 240px;  
    }  
  
    .sidebar a {  
        font-size: 14px;  
    }  
  
}  
  
</style>  <script>  
  
function toggleSidebar() {  
  
    document.getElementById('sidebar')  
        .classList.add('active');  
  
    document.getElementById('sidebar-overlay')  
        .classList.add('active');  
}  
  
function closeSidebar() {  
  
    document.getElementById('sidebar')  
        .classList.remove('active');  
  
    document.getElementById('sidebar-overlay')  
        .classList.remove('active');  
}  
  
</script>
