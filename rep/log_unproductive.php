<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['rep']); // Strictly for Sales Reps

$rep_id = $_SESSION['user_id'];

// Check for active route today
$routeStmt = $pdo->prepare("SELECT id, route_id, status FROM rep_routes WHERE rep_id = ? AND assign_date = CURDATE() AND status = 'accepted' AND start_meter IS NOT NULL ORDER BY id DESC LIMIT 1");
$routeStmt->execute([$rep_id]);
$routeInfo = $routeStmt->fetch();

$route_id = $routeInfo ? $routeInfo['route_id'] : null;

if (!$routeInfo) {
    die('<div style="font-family: sans-serif; text-align: center; padding: 50px; color: #DC3545;"><h3>Access Denied</h3><p>You must start your route before logging unproductive visits.</p><a href="dashboard.php">Return to Dashboard</a></div>');
}

// Fetch Customers
$my_customers = [];
$whereSql = "1=1";
$params = [];

if ($route_id) {
    $whereSql = "c.route_id = ?";
    $params = [$route_id];
}

$customers = $pdo->prepare("
    SELECT c.id, c.name, c.address, c.phone
    FROM customers c WHERE $whereSql ORDER BY c.name ASC
");
$customers->execute($params);
$my_customers = $customers->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Log Unproductive Visit - Rep App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; padding-bottom: 90px; }
        .top-nav { background: #dc3545; color: white; padding: 15px 20px; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 15px rgba(220,53,69,0.2); }
        .back-btn { color: white; font-size: 1.2rem; text-decoration: none; margin-right: 15px; cursor: pointer; }
        .list-card { background: white; border-radius: 16px; padding: 15px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #f8f9fa; cursor: pointer; transition: transform 0.1s; }
        .list-card:active { transform: scale(0.98); background-color: #f8f9fa; }
    </style>
</head>
<body>

    <div class="top-nav d-flex align-items-center">
        <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h5 class="mb-0 fw-bold">Log Unproductive Visit</h5>
            <small class="text-white text-opacity-75">Select Customer</small>
        </div>
    </div>

    <div class="container px-3 mt-3">
        <div class="d-flex shadow-sm rounded-pill overflow-hidden bg-white mb-4 border">
            <span class="ps-3 pe-2 py-2 text-muted d-flex align-items-center"><i class="bi bi-search"></i></span>
            <input type="text" id="custSearchInput" class="form-control border-0 shadow-none ps-1 py-2" placeholder="Search customers...">
        </div>

        <div id="customersList">
            <?php foreach($my_customers as $c): ?>
            <div class="list-card cust-card" onclick="openUnproductiveModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>')">
                <div class="d-flex align-items-start">
                    <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width: 45px; height: 45px; font-size: 1.2rem;">
                        <i class="bi bi-shop"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-1 text-dark cust-name"><?php echo htmlspecialchars($c['name']); ?></h6>
                        <div class="small text-muted mb-1 cust-address text-truncate" style="max-width: 200px;"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($c['address'] ?: 'No Address'); ?></div>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted opacity-50"></i>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($my_customers)): ?>
                <div class="alert alert-light text-center p-4 rounded-4 shadow-sm">
                    <i class="bi bi-info-circle fs-2 text-muted d-block mb-2"></i>
                    <h6 class="fw-bold text-muted">No customers found.</h6>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Unproductive Visit Modal -->
    <div class="modal fade" id="unproductiveModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header bg-danger text-white border-bottom-0 pb-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Log Visit</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3 text-center border-bottom pb-3">
                        <span class="badge bg-danger mb-1">Customer</span>
                        <h5 class="fw-bold text-dark mb-0" id="modalCustName">Shop Name</h5>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Reason for no sale</label>
                        <select id="unproductiveReason" class="form-select form-select-lg fw-bold">
                            <option value="">-- Select Reason --</option>
                            <option value="Shop Closed">Shop Closed</option>
                            <option value="Owner/Manager Unavailable">Owner/Manager Unavailable</option>
                            <option value="Overstocked / No Requirement">Overstocked / No Requirement</option>
                            <option value="Outstanding Payments Due">Outstanding Payments Due</option>
                            <option value="Competitor Product Preference">Competitor Product Preference</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <button type="button" id="btnSubmitUnproductive" class="btn btn-danger w-100 rounded-pill fw-bold py-2 mt-2 shadow-sm">
                        Submit Log & Return
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const unproductiveModal = new bootstrap.Modal(document.getElementById('unproductiveModal'));
        let selectedCustomerId = null;

        function openUnproductiveModal(id, name) {
            selectedCustomerId = id;
            document.getElementById('modalCustName').textContent = name;
            document.getElementById('unproductiveReason').value = '';
            unproductiveModal.show();
        }

        // Search functionality
        document.getElementById('custSearchInput').addEventListener('input', function() {
            let filter = this.value.toLowerCase();
            let cards = document.querySelectorAll('.cust-card');
            
            cards.forEach(card => {
                let name = card.querySelector('.cust-name').textContent.toLowerCase();
                if(name.includes(filter)) {
                    card.classList.remove('d-none');
                } else {
                    card.classList.add('d-none');
                }
            });
        });

        document.getElementById('btnSubmitUnproductive').addEventListener('click', function() {
            const reason = document.getElementById('unproductiveReason').value;
            if (!reason) { alert('Please select a reason.'); return; }
            
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

            // Get Location First
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => submitUnproductive(reason, position.coords.latitude, position.coords.longitude, btn),
                    (error) => submitUnproductive(reason, null, null, btn),
                    { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
                );
            } else {
                submitUnproductive(reason, null, null, btn);
            }
        });

        function submitUnproductive(reason, lat, lng, btn) {
            fetch('../ajax/log_unproductive.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_id: selectedCustomerId,
                    reason: reason,
                    latitude: lat,
                    longitude: lng
                })
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    unproductiveModal.hide();
                    alert('Unproductive visit logged successfully.');
                    window.location.href = 'dashboard.php';
                } else {
                    alert(data.message);
                    btn.disabled = false;
                    btn.innerHTML = 'Submit Log & Return';
                }
            })
            .catch(e => {
                alert('Network error.');
                btn.disabled = false;
                btn.innerHTML = 'Submit Log & Return';
            });
        }
    </script>
</body>
</html>
