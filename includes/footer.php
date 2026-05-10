<?php
$current_page = basename($_SERVER['PHP_SELF']);
// Hide the visual footer on the POS page to maintain the strict 100vh no-scroll layout
if ($current_page !== 'create_order.php'): 
?>
    <!-- Candent Global Footer -->
    <footer class="mt-5 py-4 text-center w-100" style="border-top: 1px solid var(--ios-separator); color: var(--ios-label-2);">
        <div style="font-size: 0.85rem; font-weight: 500;">
            &copy; <?php echo date('Y'); ?> <strong>Candent</strong>. All rights reserved.
        </div>
        <div style="font-size: 0.75rem; margin-top: 6px; color: var(--ios-label-3);">
            System Developed & Maintained by 
            <a href="https://suzxlabs.com" target="_blank" style="color: var(--accent-dark); text-decoration: none; font-weight: 700; transition: opacity 0.2s;">
                Suzxlabs
            </a>
        </div>
    </footer>
<?php endif; ?>

</main> <!-- End of Main Content Area (Opened in sidebar.php) -->
</div> <!-- End of App Wrapper (Opened in header.php) -->

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom App JS -->
<script src="../assets/js/app.js"></script>

<!-- Candent Global iOS Interaction Effects -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Add subtle iOS-style click animations to all standard buttons globally
    const interactionButtons = document.querySelectorAll('.quick-btn, .act-btn, .nav-icon-btn');
    interactionButtons.forEach(btn => {
        btn.addEventListener('mousedown', () => btn.style.transform = 'scale(0.96)');
        btn.addEventListener('mouseup', () => btn.style.transform = '');
        btn.addEventListener('mouseleave', () => btn.style.transform = '');
    });
});
</script>

</body>
</html>