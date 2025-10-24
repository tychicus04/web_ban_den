<!-- Footer -->
<footer class="footer">
    <div class="footer-container">
        <div class="footer-section">
            <div class="footer-logo">
                <img src="logo.png" alt="<?php echo SITE_NAME; ?>" class="footer-logo-img">
                <p class="footer-description">
                    <?php echo SITE_DESCRIPTION; ?>
                </p>
                <div class="footer-stats">
                    <?php foreach ($site_stats as $stat): ?>
                    <div class="stat-item">
                        <strong><?php echo $stat['value']; ?></strong>
                        <span><?php echo $stat['label']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php foreach ($footer_menus as $menu): ?>
        <div class="footer-section">
            <h4 class="footer-title"><?php echo $menu['title']; ?></h4>
            <ul class="footer-links">
                <?php foreach ($menu['items'] as $item): ?>
                <li><a href="<?php echo $item['url']; ?>"><?php echo $item['title']; ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>

        <div class="footer-section">
            <h4 class="footer-title">Kết nối với chúng tôi</h4>
            <div class="social-links">
                <a href="<?php echo FACEBOOK_URL; ?>" class="social-link" target="_blank">
                    <span class="social-icon">📘</span> Facebook
                </a>
                <a href="<?php echo INSTAGRAM_URL; ?>" class="social-link" target="_blank">
                    <span class="social-icon">📷</span> Instagram
                </a>
                <a href="<?php echo YOUTUBE_URL; ?>" class="social-link" target="_blank">
                    <span class="social-icon">📺</span> YouTube
                </a>
                <a href="<?php echo TIKTOK_URL; ?>" class="social-link" target="_blank">
                    <span class="social-icon">🎵</span> TikTok
                </a>
                <a href="<?php echo ZALO_URL; ?>" class="social-link" target="_blank">
                    <span class="social-icon">💬</span> Zalo
                </a>
            </div>



        </div>
    </div>



</footer>

<style>
/* Enhanced Footer Styles */
.footer-stats {
    display: flex;
    gap: 20px;
    margin-top: 15px;
}

.stat-item {
    text-align: center;
}

.stat-item strong {
    display: block;
    font-size: 18px;
    color: #1877f2;
    margin-bottom: 5px;
}

.stat-item span {
    font-size: 12px;
    color: #666;
}

.social-link {
    display: flex;
    align-items: center;
    gap: 8px;
    transition: transform 0.3s ease;
}

.social-link:hover {
    transform: translateX(5px);
}

.social-icon {
    font-size: 16px;
}

.app-download {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.app-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #666;
    text-decoration: none;
    font-size: 14px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.app-link:hover {
    background: #1877f2;
    color: white;
    transform: translateY(-2px);
}

.footer-middle {
    background: #f8f9fa;
    padding: 30px 0;
    border-top: 1px solid #e1e8ed;
}

.footer-features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 15px;
    text-align: left;
}

.feature-icon {
    font-size: 32px;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 50%;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.feature-content h5 {
    margin-bottom: 5px;
    font-size: 14px;
    color: #333;
}

.feature-content p {
    font-size: 12px;
    color: #666;
    margin: 0;
}

.footer-bottom-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    align-items: start;
}

.footer-copyright {
    text-align: left;
}

.footer-copyright a {
    color: #1877f2;
    text-decoration: none;
}

.footer-copyright a:hover {
    text-decoration: underline;
}

.footer-contact {
    text-align: right;
}

.footer-contact a {
    color: #1877f2;
    text-decoration: none;
}

.footer-contact a:hover {
    text-decoration: underline;
}

/* Responsive footer */
@media (max-width: 768px) {
    .footer-stats {
        flex-wrap: wrap;
        gap: 15px;
    }

    .footer-features {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .footer-bottom-content {
        grid-template-columns: 1fr;
        gap: 20px;
        text-align: center;
    }

    .footer-contact {
        text-align: center;
    }

    .stat-item strong {
        font-size: 16px;
    }
}
</style>

<script>
// Footer JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scroll to top
    const scrollToTop = document.createElement('button');
    scrollToTop.innerHTML = '↑';
    scrollToTop.className = 'scroll-to-top';
    scrollToTop.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 50px;
        height: 50px;
        border: none;
        border-radius: 50%;
        background: #1877f2;
        color: white;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(24, 119, 242, 0.3);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 1000;
    `;

    document.body.appendChild(scrollToTop);

    // Show/hide scroll to top button
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            scrollToTop.style.opacity = '1';
            scrollToTop.style.visibility = 'visible';
        } else {
            scrollToTop.style.opacity = '0';
            scrollToTop.style.visibility = 'hidden';
        }
    });

    // Scroll to top functionality
    scrollToTop.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    // Add hover effect to footer links
    document.querySelectorAll('.footer-links a').forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.paddingLeft = '10px';
        });

        link.addEventListener('mouseleave', function() {
            this.style.paddingLeft = '0';
        });
    });

    // Newsletter subscription (if needed)
    const newsletterForm = document.querySelector('.newsletter-form');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            if (email) {
                // Handle newsletter subscription
                showNotification('Đã đăng ký nhận tin thành công!', 'success');
                this.reset();
            }
        });
    }
});
</script>