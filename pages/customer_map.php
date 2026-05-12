<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

include '../includes/header.php';
include '../includes/sidebar.php';

// Fetch all routes for filtering
$routes = $pdo->query("SELECT id, name FROM routes ORDER BY name ASC")->fetchAll();
?>

<style>
    :root {
        --ios-blue: #007AFF;
        --ios-green: #34C759;
        --ios-gray: #8E8E93;
        --ios-bg: #F2F2F7;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 24px 0 16px;
        margin-bottom: 24px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .page-title {
        font-size: 1.8rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #1c1c1e;
        margin: 0;
    }

    .map-container {
        height: calc(100vh - 250px);
        min-height: 500px;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.04);
        border: 1px solid rgba(0,0,0,0.05);
        overflow: hidden;
        position: relative;
    }

    #customerMap {
        height: 100%;
        width: 100%;
        z-index: 1;
    }

    .filter-panel {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 1000;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        padding: 15px;
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        border: 1px solid rgba(255,255,255,0.5);
        width: 250px;
    }

    .custom-customer-marker {
        background: var(--ios-blue);
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid white;
        box-shadow: 0 0 10px rgba(0,122,255,0.4);
    }

    .ios-select {
        border-radius: 10px;
        border: 1px solid #E5E5EA;
        padding: 8px 12px;
        font-size: 0.9rem;
        font-weight: 600;
        width: 100%;
        background-color: #fff;
    }

    .ios-select:focus {
        outline: none;
        border-color: var(--ios-blue);
        box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
    }

    .marker-popup {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        padding: 5px;
    }
    
    .marker-popup h6 {
        margin: 0 0 5px 0;
        font-weight: 700;
        color: #1c1c1e;
    }

    .marker-popup p {
        margin: 0;
        font-size: 0.8rem;
        color: #8e8e93;
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Customer Map</h1>
        <p class="text-muted mb-0">Visualize your customer distribution across all routes.</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="loadCustomers()" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm">
            <i class="bi bi-arrow-clockwise me-2"></i>Refresh
        </button>
    </div>
</div>

<div class="map-container">
    <div id="customerMap"></div>
    
    <div class="filter-panel">
        <label class="form-label small fw-bold text-muted text-uppercase mb-2">Filter by Route</label>
        <select id="routeFilter" class="ios-select" onchange="loadCustomers()">
            <option value="0">All Routes</option>
            <?php foreach($routes as $route): ?>
                <option value="<?php echo $route['id']; ?>"><?php echo htmlspecialchars($route['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <div id="stats" class="mt-3 small text-muted fw-bold">
            <span id="customerCount">0</span> Customers Found
        </div>
    </div>
</div>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let map;
let markerLayer;

function initMap() {
    map = L.map('customerMap', {
        zoomControl: false
    }).setView([7.8731, 80.7718], 8); // Center on Sri Lanka

    L.control.zoom({ position: 'bottomleft' }).addTo(map);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 20
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);
    loadCustomers();
}

async function loadCustomers() {
    const routeId = document.getElementById('routeFilter').value;
    const statsEl = document.getElementById('customerCount');
    
    markerLayer.clearLayers();
    statsEl.innerText = "...";

    try {
        const response = await fetch(`../ajax/get_customers_map.php?route_id=${routeId}`);
        const result = await response.json();

        if (result.success) {
            const customers = result.data;
            statsEl.innerText = customers.length;

            if (customers.length === 0) return;

            const bounds = L.latLngBounds();

            customers.forEach(customer => {
                if (customer.latitude && customer.longitude) {
                    const marker = L.circleMarker([customer.latitude, customer.longitude], {
                        radius: 8,
                        fillColor: "#007AFF",
                        color: "#fff",
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.8
                    });

                    const popupContent = `
                        <div class="marker-popup">
                            <h6>${customer.name}</h6>
                            <p><i class="bi bi-geo-alt-fill me-1"></i>${customer.address || 'No address provided'}</p>
                            <a href="customers.php?id=${customer.id}" class="btn btn-primary btn-sm w-100 mt-2 rounded-pill" style="font-size:0.7rem; font-weight:700;">View Profile</a>
                        </div>
                    `;

                    marker.bindPopup(popupContent);
                    marker.addTo(markerLayer);
                    bounds.extend([customer.latitude, customer.longitude]);
                }
            });

            if (routeId != "0" && customers.length > 0) {
                map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
        }
    } catch (e) {
        console.error('Failed to load customers', e);
    }
}

document.addEventListener('DOMContentLoaded', initMap);
</script>

<?php include '../includes/footer.php'; ?>
