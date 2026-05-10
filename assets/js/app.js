/**
 * Fintrix DMS - Main Application Scripts
 * Handles global UI interactions, tooltips, and mobile responsiveness.
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Initialize all Bootstrap Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // 2. Initialize all Bootstrap Popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // 3. Auto-close Mobile Sidebar on Link Click
    const sidebarMenu = document.getElementById('sidebarMenu');
    if (sidebarMenu) {
        const navLinks = sidebarMenu.querySelectorAll('.nav-link:not([data-bs-toggle="collapse"])');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) { // Mobile breakpoint
                    const bsOffcanvas = bootstrap.Offcanvas.getInstance(sidebarMenu);
                    if (bsOffcanvas) {
                        bsOffcanvas.hide();
                    }
                }
            });
        });
    }

    // 4. Desktop Sidebar Minimize Feature
    const sidebarToggleBtn = document.getElementById('sidebarMinimizeBtn');
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-minimized');
            // Save state to localStorage for persistence across page reloads
            if (document.body.classList.contains('sidebar-minimized')) {
                localStorage.setItem('sidebar_minimized', 'true');
            } else {
                localStorage.setItem('sidebar_minimized', 'false');
            }
        });
    }

    // 5. Auto-dismiss Alert Messages after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(alert => {
        setTimeout(() => {
            // Check if bootstrap is available to close it smoothly
            if (typeof bootstrap !== 'undefined') {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            } else {
                alert.style.display = 'none';
            }
        }, 5000); // 5000ms = 5 seconds
    });
});