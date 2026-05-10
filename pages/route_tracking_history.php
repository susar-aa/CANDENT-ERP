<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor', 'rep']); // Added 'rep' so reps can view their own history if linked

// Fetch reps for dropdown (If rep, they only see themselves. If admin, they see all)
if (hasRole(['admin', 'supervisor'])) {
    $repsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'rep' ORDER BY name ASC");
    $reps = $repsStmt->fetchAll();
} else {
    $reps = [['id' => $_SESSION['user_id'], 'name' => $_SESSION['user_name']]];
}

// Set defaults
$selected_rep = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : (hasRole('rep') ? $_SESSION['user_id'] : '');
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$routeData = [];
$bills = [];
$unproductive_visits = [];

if ($selected_rep && $selected_date) {
    // Get path from rep_location_logs
    $locStmt = $pdo->prepare("SELECT latitude, longitude, activity_type, timestamp FROM rep_location_logs WHERE user_id = ? AND DATE(timestamp) = ? ORDER BY timestamp ASC");
    $locStmt->execute([$selected_rep, $selected_date]);
    $routeData = $locStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get bills for that day (Productive)
    $billStmt = $pdo->prepare("SELECT o.id, o.total_amount, o.created_at, o.latitude, o.longitude, c.name as customer_name FROM orders o LEFT JOIN customers c ON o.customer_id = c.id WHERE o.rep_id = ? AND DATE(o.created_at) = ? AND o.latitude IS NOT NULL AND o.longitude IS NOT NULL");
    $billStmt->execute([$selected_rep, $selected_date]);
    $bills = $billStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unproductive visits for that day
    $unprodStmt = $pdo->prepare("SELECT u.id, u.reason, u.created_at, u.latitude, u.longitude, c.name as customer_name FROM unproductive_visits u LEFT JOIN customers c ON u.customer_id = c.id WHERE u.rep_id = ? AND DATE(u.created_at) = ? AND u.latitude IS NOT NULL AND u.longitude IS NOT NULL");
    $unprodStmt->execute([$selected_rep, $selected_date]);
    $unproductive_visits = $unprodStmt->fetchAll(PDO::FETCH_ASSOC);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* --- Specific Page Styles (Candent Theme) --- */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        padding: 24px 0 16px;
        border-bottom: 1px solid var(--ios-separator);
        margin-bottom: 24px;
    }
    .page-title {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.8px;
        color: var(--ios-label);
        margin: 0;
    }
    .page-subtitle {
        font-size: 0.85rem;
        color: var(--ios-label-2);
        margin-top: 4px;
    }

    /* iOS Inputs & Labels */
    .ios-input, .form-select {
        background: var(--ios-surface) !important;
        border: 1px solid var(--ios-separator) !important;
        border-radius: 10px !important;
        padding: 10px 14px !important;
        font-size: 0.95rem !important;
        color: var(--ios-label) !important;
        transition: all 0.2s ease;
        box-shadow: none !important;
        width: 100%;
        min-height: 42px;
    }
    .ios-input:focus, .form-select:focus {
        background: #fff !important;
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 3px rgba(48,200,138,0.15) !important;
        outline: none;
    }
    .ios-label-sm {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ios-label-2);
        margin-bottom: 6px;
        padding-left: 4px;
    }

    /* Custom Leaflet Marker Styling */
    .custom-div-icon {
        background: transparent;
        border: none;
    }
    .bill-pin {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: #ffffff;
        display: flex; align-items: center; justify-content: center;
        color: #1A9A3A;
        border: 2px solid #1A9A3A;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        font-size: 1.1rem;
        transition: transform 0.2s;
    }
    .bill-pin:hover { transform: scale(1.15); z-index: 1000 !important; }
    
    .unprod-pin {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: #ffffff;
        display: flex; align-items: center; justify-content: center;
        color: #CC2200;
        border: 2px solid #CC2200;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        font-size: 1rem;
        transition: transform 0.2s;
    }
    .unprod-pin:hover { transform: scale(1.15); z-index: 1000 !important; }
    
    .start-pin {
        width: 16px; height: 16px;
        border-radius: 50%;
        background: #34C759;
        border: 3px solid #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    }
    .end-pin {
        width: 16px; height: 16px;
        border-radius: 50%;
        background: #007AFF;
        border: 3px solid #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.25);
    }

    /* Map Controls Override */
    .leaflet-control-zoom a {
        background: rgba(255,255,255,0.9) !important;
        color: var(--ios-label) !important;
        border: 1px solid var(--ios-separator) !important;
        backdrop-filter: blur(10px);
    }

    /* Leaflet Popup iOS overrides */
    .ios-popup .leaflet-popup-content-wrapper {
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        border: 1px solid var(--ios-separator);
        padding: 4px;
    }
    .ios-popup .leaflet-popup-tip {
        box-shadow: none;
        border-bottom: 1px solid var(--ios-separator);
        border-right: 1px solid var(--ios-separator);
    }

    /* Leaflet Tooltip overrides */
    .ios-tooltip-success {
        background: rgba(52,199,89,0.95);
        border: 1px solid #1A9A3A;
        border-radius: 50px;
        color: white;
        box-shadow: 0 2px 8px rgba(52,199,89,0.3);
        padding: 4px 10px;
        backdrop-filter: blur(4px);
    }
    .ios-tooltip-success::before { border-top-color: rgba(52,199,89,0.95) !important; }
    
    .ios-tooltip-danger {
        background: rgba(255,59,48,0.95);
        border: 1px solid #CC2200;
        border-radius: 50px;
        color: white;
        box-shadow: 0 2px 8px rgba(255,59,48,0.3);
        padding: 4px 10px;
        backdrop-filter: blur(4px);
    }
    .ios-tooltip-danger::before { border-top-color: rgba(255,59,48,0.95) !important; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Route History</h1>
        <div class="page-subtitle">Playback and analyze historical GPS tracking, invoices, and unproductive visits.</div>
    </div>
</div>

<div class="dash-card mb-4" style="background: var(--ios-surface-2);">
    <div class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="ios-label-sm">Sales Representative</label>
                <select name="rep_id" class="form-select fw-bold" required <?php echo hasRole('rep') ? 'disabled' : ''; ?>>
                    <option value="">-- Choose Rep --</option>
                    <?php foreach($reps as $rep): ?>
                        <option value="<?php echo $rep['id']; ?>" <?php echo $selected_rep == $rep['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- Hidden input to carry value if select is disabled for reps -->
                <?php if(hasRole('rep')): ?>
                    <input type="hidden" name="rep_id" value="<?php echo $_SESSION['user_id']; ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="ios-label-sm">Playback Date</label>
                <input type="date" name="date" class="ios-input fw-bold text-primary" value="<?php echo htmlspecialchars($selected_date); ?>" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="quick-btn quick-btn-primary w-100" style="min-height: 42px;">
                    <i class="bi bi-search me-1"></i> Load Route
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_rep && $selected_date): ?>
    <?php if (empty($routeData)): ?>
        <div class="empty-state py-5 dash-card">
            <i class="bi bi-map" style="font-size: 3rem; color: var(--ios-label-4);"></i>
            <h4 class="mt-3 fw-bold">No Route Data Found</h4>
            <p class="text-muted">No GPS coordinates were recorded for this representative on the selected date.</p>
        </div>
    <?php else: ?>
        <div class="dash-card h-100 d-flex flex-column mb-4">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-geo-alt-fill"></i>
                    </span>
                    Historical Map View
                </span>
                <div class="d-flex gap-2">
                    <span class="ios-badge green outline fw-normal"><i class="bi bi-receipt-cutoff"></i> <?php echo count($bills); ?> Sales</span>
                    <span class="ios-badge red outline fw-normal"><i class="bi bi-x-lg"></i> <?php echo count($unproductive_visits); ?> Misses</span>
                    <span class="ios-badge gray outline fw-normal"><?php echo date('M d, Y', strtotime($selected_date)); ?></span>
                </div>
            </div>
            <div class="p-3" style="background: var(--ios-surface-2);">
                <div id="routeMap" style="height: 600px; width: 100%; border-radius: 16px; border: 1px solid var(--ios-separator); box-shadow: inset 0 2px 10px rgba(0,0,0,0.02); z-index: 1;"></div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
<?php if ($selected_rep && $selected_date && !empty($routeData)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const map = L.map('routeMap');
        
        // Clean, modern map tiles (CartoDB Positron) for an iOS feel
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 20
        }).addTo(map);

        const routePoints = <?php echo json_encode($routeData); ?>;
        const bills = <?php echo json_encode($bills); ?>;
        const unproductives = <?php echo json_encode($unproductive_visits); ?>;
        
        const latLngs = [];
        routePoints.forEach(pt => {
            if(pt.latitude && pt.longitude) {
                latLngs.push([pt.latitude, pt.longitude]);
            }
        });

        // Draw Polyline (Candent iOS Blue)
        if (latLngs.length > 0) {
            const polyline = L.polyline(latLngs, {color: '#007AFF', weight: 4, opacity: 0.8, lineJoin: 'round'}).addTo(map);
            map.fitBounds(polyline.getBounds(), { padding: [30, 30] });
            
            // Format time for popups
            const formatTime = (ts) => {
                return new Date(ts.replace(' ', 'T')).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            };

            // Start Marker (Green Dot)
            const startIcon = L.divIcon({ className: 'custom-div-icon', html: '<div class="start-pin"></div>', iconSize: [16, 16], iconAnchor: [8, 8] });
            L.marker(latLngs[0], {icon: startIcon}).addTo(map)
             .bindPopup(`<div style="text-align:center;font-family:-apple-system,sans-serif;"><div style="font-weight:800;color:#1c1c1e;">Day Started</div><div style="font-size:0.75rem;color:#8e8e93;">${formatTime(routePoints[0].timestamp)}</div></div>`, {className: 'ios-popup', closeButton: false});
            
            // End Marker (Blue Dot)
            if (latLngs.length > 1) {
                const endIcon = L.divIcon({ className: 'custom-div-icon', html: '<div class="end-pin"></div>', iconSize: [16, 16], iconAnchor: [8, 8] });
                L.marker(latLngs[latLngs.length - 1], {icon: endIcon}).addTo(map)
                 .bindPopup(`<div style="text-align:center;font-family:-apple-system,sans-serif;"><div style="font-weight:800;color:#1c1c1e;">Last Known Location</div><div style="font-size:0.75rem;color:#8e8e93;">${formatTime(routePoints[routePoints.length - 1].timestamp)}</div></div>`, {className: 'ios-popup', closeButton: false});
            }
        }

        // Add Productive Bill Markers
        bills.forEach(bill => {
            if(bill.latitude && bill.longitude) {
                const billIcon = L.divIcon({
                    html: '<div class="bill-pin"><i class="bi bi-receipt-cutoff"></i></div>',
                    className: 'custom-div-icon',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16],
                    popupAnchor: [0, -16]
                });
                
                let shopName = bill.customer_name || 'Unknown Shop';
                let timeStr = new Date(bill.created_at.replace(' ', 'T')).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                let marker = L.marker([bill.latitude, bill.longitude], {icon: billIcon}).addTo(map);
                
                marker.bindTooltip(`<div style="font-weight:700;font-size:0.75rem;">${shopName}</div>`, {
                    permanent: true, 
                    direction: 'top', 
                    offset: [0, -20],
                    className: 'ios-tooltip-success'
                });
                
                const popupContent = `
                    <div style="font-family: -apple-system, sans-serif; text-align: center; padding: 6px;">
                        <div style="font-size: 0.75rem; color: #8e8e93; font-weight: 600; text-transform: uppercase;">Productive Visit</div>
                        <div style="font-weight: 800; font-size: 1.1rem; color: #1A9A3A; display: block; margin: 4px 0;">Rs ${parseFloat(bill.total_amount).toFixed(2)}</div>
                        <div style="font-size: 0.75rem; color: #8e8e93; margin-top: 4px; margin-bottom: 12px;"><i class="bi bi-clock me-1"></i>${timeStr}</div>
                        <a href="view_invoice.php?id=${bill.id}" target="_blank" class="quick-btn quick-btn-ghost w-100" style="font-size: 0.75rem; padding: 6px;">View Invoice #${bill.id.toString().padStart(6, '0')}</a>
                    </div>
                `;
                marker.bindPopup(popupContent, { className: 'ios-popup', closeButton: false });
            }
        });

        // Add Unproductive Visit Markers
        unproductives.forEach(visit => {
            if(visit.latitude && visit.longitude) {
                const unprodIcon = L.divIcon({
                    html: '<div class="unprod-pin"><i class="bi bi-x-lg"></i></div>',
                    className: 'custom-div-icon',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16],
                    popupAnchor: [0, -16]
                });
                
                let shopName = visit.customer_name || 'Unknown Shop';
                let timeStr = new Date(visit.created_at.replace(' ', 'T')).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                let marker = L.marker([visit.latitude, visit.longitude], {icon: unprodIcon}).addTo(map);
                
                marker.bindTooltip(`<div style="font-weight:700;font-size:0.75rem;">${shopName}</div>`, {
                    permanent: true, 
                    direction: 'top', 
                    offset: [0, -20],
                    className: 'ios-tooltip-danger'
                });
                
                const popupContent = `
                    <div style="font-family: -apple-system, sans-serif; text-align: center; padding: 6px;">
                        <div style="font-size: 0.75rem; color: #8e8e93; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Unproductive Visit</div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: #CC2200; margin: 4px 0;">${visit.reason}</div>
                        <div style="font-size: 0.75rem; color: #8e8e93; margin-top: 4px;"><i class="bi bi-clock me-1"></i>${timeStr}</div>
                    </div>
                `;
                marker.bindPopup(popupContent, { className: 'ios-popup', closeButton: false });
            }
        });
    });
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>