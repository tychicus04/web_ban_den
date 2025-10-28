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