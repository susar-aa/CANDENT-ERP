    </main>
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-tab <?php echo $cur_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="bi bi-grid-fill"></i>
            <span>Dashboard</span>
        </a>
        <a href="catalog.php" class="nav-tab <?php echo $cur_page == 'catalog.php' ? 'active' : ''; ?>">
            <i class="bi bi-box-seam-fill"></i>
            <span>Catalog</span>
        </a>
        <a href="dispatch.php" class="nav-tab <?php echo $cur_page == 'dispatch.php' ? 'active' : ''; ?>">
            <i class="bi bi-truck"></i>
            <span>Dispatch</span>
        </a>
        <a href="routes.php" class="nav-tab <?php echo $cur_page == 'routes.php' ? 'active' : ''; ?>">
            <i class="bi bi-map-fill"></i>
            <span>Routes</span>
        </a>
        <a href="sales.php" class="nav-tab <?php echo $cur_page == 'sales.php' ? 'active' : ''; ?>">
            <i class="bi bi-graph-up-arrow"></i>
            <span>Sales</span>
        </a>
    </nav>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
