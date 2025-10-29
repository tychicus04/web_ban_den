<?php
// Template mẫu cho các trang khác
// Bạn có thể copy file này để tạo trang mới

// Include constants
require_once 'constants.php';

// Set page-specific variables
$current_page = 'page-name'; // Tên page để highlight navigation
$require_login = false; // Set true nếu trang cần đăng nhập

// Xử lý logic trang ở đây
// ...

?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tên trang - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="Mô tả trang">
    <meta name="keywords" content="từ khóa trang">
    <link rel="stylesheet" href="asset/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <!-- Thêm CSS riêng cho trang nếu cần -->
    <link rel="stylesheet" href="asset/css/pages/page-template.css">
</head>

<body>
    <?php include 'header.php'; ?>

    <!-- Breadcrumb (tùy chọn) -->
    <div class="breadcrumb" style="max-width: 1200px; margin: 0 auto; padding: 15px 20px; font-size: 14px;">
        <a href="index.php">Trang chủ</a> >
        <span>Tên trang</span>
    </div>

    <!-- Page Content -->
    <div class="page-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Tiêu đề trang</h1>
            <p class="page-subtitle">Mô tả ngắn về trang</p>
        </div>

        <!-- Content Sections -->
        <div class="content-section">
            <h2>Section 1</h2>
            <p>Nội dung section 1...</p>
        </div>

        <div class="content-section">
            <h2>Section 2</h2>
            <p>Nội dung section 2...</p>
        </div>

        <!-- Grid Layout Example -->
        <div
            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
            <div class="content-section">
                <h3>Card 1</h3>
                <p>Nội dung card 1</p>
            </div>
            <div class="content-section">
                <h3>Card 2</h3>
                <p>Nội dung card 2</p>
            </div>
            <div class="content-section">
                <h3>Card 3</h3>
                <p>Nội dung card 3</p>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <!-- Page-specific JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // JavaScript cho trang này
        console.log('Page loaded');

        // Example: Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });
    });
    </script>
</body>

</html>