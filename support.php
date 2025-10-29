<?php
session_start();
require_once 'config.php';

// Set page-specific variables
$current_page = 'support';
$require_login = false; // Allow guests to view support page

// Get user info if logged in
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : null;
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : null;

// Get some statistics from database for the landing page
try {
    // Get total products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE published = 1 AND approved = 1");
    $stmt->execute();
    $total_products = $stmt->fetchColumn();

    // Get total users
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type = 'customer'");
    $stmt->execute();
    $total_customers = $stmt->fetchColumn();

    // Get total sellers
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type = 'seller'");
    $stmt->execute();
    $total_sellers = $stmt->fetchColumn();

    // Get total orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders");
    $stmt->execute();
    $total_orders = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_products = 1250000;
    $total_customers = 85000;
    $total_sellers = 12500;
    $total_orders = 250000;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Về TikTok Shop - Nền tảng thương mại điện tử hàng đầu Việt Nam</title>
    <meta name="description"
        content="TikTok Shop - Kết nối hàng triệu người mua và người bán trên toàn quốc. Mua sắm thông minh, bán hàng hiệu quả với TikTok Shop.">
    <meta name="keywords" content="TikTok Shop, thương mại điện tử, mua sắm online, bán hàng online, marketplace">
    <!-- CSS Files -->
    <link rel="stylesheet" href="asset/css/global.css">
    <link rel="stylesheet" href="asset/css/components.css">
    <link rel="stylesheet" href="asset/css/base.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="asset/css/pages/support.css">
</head>

<body>
    <?php include 'header.php'; ?>

    <!-- Floating Background Shapes -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <!-- Scroll Progress Bar -->
    <div class="scroll-progress" id="scrollProgress"></div>

    <!-- Enhanced Hero Section -->
    <section class="hero-landing">
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-crown"></i> Nền tảng thương mại điện tử #1 Việt Nam
            </div>
            <h1 class="hero-title">Chào mừng đến với<br>TikTok Shop</h1>
            <p class="hero-subtitle">
                Trải nghiệm mua sắm thông minh và bán hàng hiệu quả với công nghệ AI tiên tiến.
                Kết nối hàng triệu người mua và người bán trên toàn quốc với sự tin cậy tuyệt đối.
            </p>
            <div class="hero-cta">
                <a href="<?php echo $user_id ? 'index.php' : 'register.php'; ?>" class="cta-btn cta-primary">
                    <i class="fas fa-rocket"></i>
                    <span><?php echo $user_id ? 'Về trang chủ' : 'Bắt đầu ngay'; ?></span>
                </a>
                <a href="#features" class="cta-btn cta-secondary">
                    <i class="fas fa-play"></i>
                    <span>Khám phá tính năng</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Enhanced Statistics Section -->
    <section class="stats-section">
        <div class="stats-container">
            <div class="stats-grid">
                <div class="stat-item" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-number" data-count="<?php echo $total_products; ?>">0</div>
                    <div class="stat-label">Sản phẩm chính hãng</div>
                </div>
                <div class="stat-item" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number" data-count="<?php echo $total_customers; ?>">0</div>
                    <div class="stat-label">Khách hàng tin tưởng</div>
                </div>
                <div class="stat-item" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="stat-number" data-count="<?php echo $total_sellers; ?>">0</div>
                    <div class="stat-label">Nhà bán hàng</div>
                </div>
                <div class="stat-item" data-aos="fade-up" data-aos-delay="400">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-number" data-count="<?php echo $total_orders; ?>">0</div>
                    <div class="stat-label">Đơn hàng thành công</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Enhanced Features Section -->
    <section id="features" class="features-section">
        <div class="section-header">
            <h2 class="section-title">Tại sao chọn TikTok Shop?</h2>
            <p class="section-subtitle">
                Những tính năng vượt trội và công nghệ tiên tiến giúp bạn có trải nghiệm mua sắm và bán hàng tốt nhất
            </p>
        </div>

        <div class="features-grid">
            <div class="feature-card" data-aos="fade-up" data-aos-delay="100">
                <div class="feature-icon">
                    <i class="fas fa-brain"></i>
                </div>
                <h3 class="feature-title">AI Shopping Assistant</h3>
                <p class="feature-description">
                    Trí tuệ nhân tạo giúp tìm kiếm sản phẩm chính xác, gợi ý thông minh và so sánh giá tự động
                    từ hàng triệu sản phẩm chính hãng.
                </p>
            </div>

            <div class="feature-card" data-aos="fade-up" data-aos-delay="200">
                <div class="feature-icon">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <h3 class="feature-title">Giao hàng siêu tốc</h3>
                <p class="feature-description">
                    Mạng lưới logistics thông minh với giao hàng trong 2 giờ nội thành, 24h toàn quốc.
                    Theo dõi real-time với GPS.
                </p>
            </div>

            <div class="feature-card" data-aos="fade-up" data-aos-delay="300">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3 class="feature-title">Bảo mật tuyệt đối</h3>
                <p class="feature-description">
                    Công nghệ blockchain bảo vệ thông tin, thanh toán an toàn với chuẩn PCI DSS.
                    Bảo hiểm 100% cho mọi giao dịch.
                </p>
            </div>

            <div class="feature-card" data-aos="fade-up" data-aos-delay="400">
                <div class="feature-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3 class="feature-title">Trải nghiệm đa nền tảng</h3>
                <p class="feature-description">
                    Giao diện responsive hoàn hảo trên mọi thiết bị. PWA cho trải nghiệm native app
                    mượt mà như ứng dụng gốc.
                </p>
            </div>

            <div class="feature-card" data-aos="fade-up" data-aos-delay="500">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3 class="feature-title">Analytics thông minh</h3>
                <p class="feature-description">
                    Dashboard phân tích dữ liệu chi tiết cho seller, insights thị trường real-time
                    và dự đoán xu hướng bằng AI.
                </p>
            </div>

            <div class="feature-card" data-aos="fade-up" data-aos-delay="600">
                <div class="feature-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <h3 class="feature-title">Hỗ trợ 24/7</h3>
                <p class="feature-description">
                    Chatbot AI kết hợp chuyên viên con người, giải quyết 99% vấn đề trong 3 phút.
                    Hỗ trợ đa ngôn ngữ và đa kênh.
                </p>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonials-section">
        <div class="section-header">
            <h2 class="section-title">Khách hàng nói gì về chúng tôi</h2>
            <p class="section-subtitle">
                Hàng nghìn đánh giá 5 sao từ khách hàng và đối tác tin tưởng
            </p>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card" data-aos="fade-up" data-aos-delay="100">
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p class="testimonial-text">
                    "TikTok Shop đã thay đổi hoàn toàn cách tôi mua sắm online. Giao diện thân thiện,
                    giao hàng nhanh chóng và dịch vụ chăm sóc khách hàng tuyệt vời!"
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">NL</div>
                    <div class="author-info">
                        <h4>Nguyễn Lan</h4>
                        <p>Khách hàng thân thiết</p>
                    </div>
                </div>
            </div>

            <div class="testimonial-card" data-aos="fade-up" data-aos-delay="200">
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p class="testimonial-text">
                    "Từ khi chuyển sang bán hàng trên TikTok Shop, doanh thu của shop tôi tăng 300%.
                    Công cụ quản lý và hỗ trợ marketing rất hiệu quả."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">TH</div>
                    <div class="author-info">
                        <h4>Trần Hùng</h4>
                        <p>Chủ shop thời trang</p>
                    </div>
                </div>
            </div>

            <div class="testimonial-card" data-aos="fade-up" data-aos-delay="300">
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                </div>
                <p class="testimonial-text">
                    "Tính năng AI gợi ý sản phẩm rất chính xác, giúp tôi tìm được những món đồ ưng ý.
                    Thanh toán an toàn, giao hàng đúng hẹn."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">MP</div>
                    <div class="author-info">
                        <h4>Mai Phương</h4>
                        <p>Blogger thời trang</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Enhanced FAQ Section -->
    <section class="faq-section">
        <div class="faq-container">
            <div class="section-header">
                <h2 class="section-title">Câu hỏi thường gặp</h2>
                <p class="section-subtitle">
                    Tìm hiểu thêm về TikTok Shop qua những câu hỏi phổ biến nhất
                </p>
            </div>

            <div class="faq-item" data-aos="fade-up" data-aos-delay="100">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <span>TikTok Shop có những ưu điểm vượt trội gì so với các nền tảng khác?</span>
                    <span class="faq-toggle">+</span>
                </div>
                <div class="faq-answer">
                    TikTok Shop tích hợp công nghệ AI tiên tiến, mạng lưới logistics thông minh và hệ thống
                    bảo mật blockchain. Chúng tôi cam kết mang đến trải nghiệm mua sắm và bán hàng vượt trội
                    với chi phí tối ưu và hiệu quả cao nhất thị trường.
                </div>
            </div>

            <div class="faq-item" data-aos="fade-up" data-aos-delay="200">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <span>Quy trình đăng ký tài khoản và xác thực như thế nào?</span>
                    <span class="faq-toggle">+</span>
                </div>
                <div class="faq-answer">
                    Đăng ký chỉ mất 2 phút với email hoặc số điện thoại. Xác thực qua OTP và eKYC tự động.
                    Đối với seller, chúng tôi hỗ trợ xác thực doanh nghiệp và cấp chứng chỉ uy tín trong 24h.
                </div>
            </div>

            <div class="faq-item" data-aos="fade-up" data-aos-delay="300">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <span>Chính sách giao hàng và hoàn trả ra sao?</span>
                    <span class="faq-toggle">+</span>
                </div>
                <div class="faq-answer">
                    Giao hàng miễn phí toàn quốc cho đơn từ 99K. Giao nhanh 2h nội thành, 24h toàn quốc.
                    Hoàn trả miễn phí trong 30 ngày, bồi thường 200% nếu hàng giả. Bảo hiểm vận chuyển 100%.
                </div>
            </div>

            <div class="faq-item" data-aos="fade-up" data-aos-delay="400">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <span>Có những phương thức thanh toán nào và độ bảo mật như thế nào?</span>
                    <span class="faq-toggle">+</span>
                </div>
                <div class="faq-answer">
                    Hỗ trợ 15+ phương thức thanh toán: thẻ quốc tế, ví điện tử, QR Pay, trả góp 0%.
                    Bảo mật chuẩn PCI DSS Level 1, mã hóa AES-256, xác thực 2FA và bảo hiểm giao dịch.
                </div>
            </div>

            <div class="faq-item" data-aos="fade-up" data-aos-delay="500">
                <div class="faq-question" onclick="toggleFAQ(this)">
                    <span>Làm thế nào để trở thành seller trên TikTok Shop?</span>
                    <span class="faq-toggle">+</span>
                </div>
                <div class="faq-answer">
                    Đăng ký seller miễn phí, hoàn thành eKYC và upload sản phẩm. Hoa hồng cạnh tranh từ 1-5%,
                    công cụ marketing AI miễn phí, và được hỗ trợ 1:1 từ đội ngũ chuyên gia.
                </div>
            </div>
        </div>
    </section>

    <!-- Enhanced Contact Section -->
    <section class="contact-section">
        <div class="contact-container">
            <div class="contact-info">
                <h2>Liên hệ hỗ trợ chuyên nghiệp</h2>
                <p>
                    Đội ngũ chuyên gia Customer Success luôn sẵn sàng hỗ trợ bạn 24/7 với
                    thời gian phản hồi trung bình dưới 30 giây.
                </p>

                <div class="contact-methods">
                    <div class="contact-method">
                        <div class="contact-method-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div>
                            <h4>Hotline VIP</h4>
                            <p>1900 1234 (24/7 - Miễn phí)</p>
                        </div>
                    </div>

                    <div class="contact-method">
                        <div class="contact-method-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <h4>Email hỗ trợ</h4>
                            <p>support@tiktokshop.vn</p>
                        </div>
                    </div>

                    <div class="contact-method">
                        <div class="contact-method-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <div>
                            <h4>Live Chat AI</h4>
                            <p>Hỗ trợ thông minh 24/7</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="contact-form">
                <h3>Gửi yêu cầu hỗ trợ</h3>
                <form id="contactForm">
                    <div class="form-group">
                        <label class="form-label">Họ và tên <span style="color: #f56565;">*</span></label>
                        <input type="text" class="form-input" name="name" required placeholder="Nhập họ và tên của bạn">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email <span style="color: #f56565;">*</span></label>
                        <input type="email" class="form-input" name="email" required placeholder="example@email.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Số điện thoại</label>
                        <input type="tel" class="form-input" name="phone" placeholder="0123 456 789">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Nội dung <span style="color: #f56565;">*</span></label>
                        <textarea class="form-textarea" name="message" required
                            placeholder="Vui lòng mô tả chi tiết vấn đề bạn gặp phải để chúng tôi hỗ trợ tốt nhất..."></textarea>
                    </div>

                    <button type="submit" class="form-submit">
                        <i class="fas fa-paper-plane"></i>
                        Gửi yêu cầu hỗ trợ
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Floating Action Button -->
    <div class="fab" onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </div>

    <?php include 'footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>
    // Initialize AOS (Animate On Scroll)
    AOS.init({
        duration: 800,
        easing: 'ease-in-out',
        once: true,
        offset: 100
    });

    // Scroll Progress Bar
    function updateScrollProgress() {
        const scrollProgress = document.getElementById('scrollProgress');
        const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const progress = (scrollTop / scrollHeight) * 100;
        scrollProgress.style.width = progress + '%';
    }

    window.addEventListener('scroll', updateScrollProgress);

    // Enhanced FAQ Toggle
    function toggleFAQ(element) {
        const faqItem = element.parentElement;
        const isActive = faqItem.classList.contains('active');

        // Close all FAQ items with animation
        document.querySelectorAll('.faq-item.active').forEach(item => {
            if (item !== faqItem) {
                item.classList.remove('active');
            }
        });

        // Toggle clicked item
        if (!isActive) {
            faqItem.classList.add('active');
        } else {
            faqItem.classList.remove('active');
        }
    }

    // Enhanced Contact Form
    document.getElementById('contactForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const submitBtn = this.querySelector('.form-submit');
        const originalHTML = submitBtn.innerHTML;

        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang gửi...';
        submitBtn.disabled = true;

        // Simulate API call
        setTimeout(() => {
            showNotification(
                '✅ Yêu cầu hỗ trợ đã được gửi thành công! Chúng tôi sẽ phản hồi trong vòng 15 phút.',
                'success');

            // Reset form with animation
            this.reset();
            this.classList.add('loading-skeleton');

            setTimeout(() => {
                this.classList.remove('loading-skeleton');
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled = false;
            }, 500);
        }, 2000);
    });

    // Enhanced Counter Animation
    function animateCounters() {
        const counters = document.querySelectorAll('.stat-number');

        counters.forEach(counter => {
            const target = parseInt(counter.getAttribute('data-count'));
            const increment = target / 100;
            let current = 0;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    counter.textContent = target.toLocaleString() + '+';
                    clearInterval(timer);
                } else {
                    counter.textContent = Math.floor(current).toLocaleString() + '+';
                }
            }, 20);
        });
    }

    // Intersection Observer for Animations
    const observerOptions = {
        threshold: 0.3,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                if (entry.target.classList.contains('stats-section')) {
                    animateCounters();
                    observer.unobserve(entry.target);
                }
            }
        });
    }, observerOptions);

    // Observe elements
    const statsSection = document.querySelector('.stats-section');
    if (statsSection) observer.observe(statsSection);

    // Smooth Scrolling
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Scroll to Top Function
    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Enhanced Parallax Effect
    let ticking = false;

    function updateParallax() {
        const scrolled = window.pageYOffset;
        const hero = document.querySelector('.hero-landing');
        const shapes = document.querySelectorAll('.shape');

        if (hero) {
            hero.style.transform = `translateY(${scrolled * 0.3}px)`;
        }

        shapes.forEach((shape, index) => {
            const speed = 0.5 + (index * 0.1);
            shape.style.transform = `translateY(${scrolled * speed}px) rotate(${scrolled * 0.1}deg)`;
        });

        ticking = false;
    }

    function requestParallaxUpdate() {
        if (!ticking) {
            requestAnimationFrame(updateParallax);
            ticking = true;
        }
    }

    window.addEventListener('scroll', requestParallaxUpdate);

    // Enhanced Notification System
    function showNotification(message, type = 'info', duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer; margin-left: 10px;">&times;</button>
        `;

        document.body.appendChild(notification);

        // Auto remove
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => notification.remove(), 300);
            }
        }, duration);
    }

    // Auto-hide FAQ after reading
    let faqTimeouts = {};

    document.querySelectorAll('.faq-question').forEach((question, index) => {
        question.addEventListener('click', function() {
            // Clear existing timeout
            if (faqTimeouts[index]) {
                clearTimeout(faqTimeouts[index]);
            }

            // Set new timeout for auto-close
            faqTimeouts[index] = setTimeout(() => {
                const faqItem = this.parentElement;
                if (faqItem.classList.contains('active')) {
                    faqItem.classList.remove('active');
                }
            }, 45000); // 45 seconds
        });
    });

    // Keyboard Navigation Support
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Close all FAQs
            document.querySelectorAll('.faq-item.active').forEach(item => {
                item.classList.remove('active');
            });
        }
    });

    // Performance Optimization
    const debouncedScrollHandler = debounce(updateScrollProgress, 10);
    window.addEventListener('scroll', debouncedScrollHandler);

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Loading State Management
    window.addEventListener('load', function() {
        document.body.classList.add('loaded');

        // Remove any loading skeletons
        document.querySelectorAll('.loading-skeleton').forEach(el => {
            el.classList.remove('loading-skeleton');
        });
    });

    // Form Validation Enhancement
    const formInputs = document.querySelectorAll('.form-input, .form-textarea');
    formInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.value.trim() !== '') {
                this.classList.add('has-value');
            } else {
                this.classList.remove('has-value');
            }
        });
    });
    </script>
    <!-- JavaScript Files -->
    <script src="asset/js/global.js"></script>
    <script src="asset/js/components.js"></script>
</body>

</html>