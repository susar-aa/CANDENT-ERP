<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

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

    /* iOS Inset Grouped Lists */
    .ios-list-group {
        background: var(--ios-surface);
        border-radius: 0;
        overflow: hidden;
    }
    .ios-list-item {
        display: flex;
        align-items: center;
        padding: 14px 20px;
        text-decoration: none;
        color: var(--ios-label);
        border-bottom: 1px solid var(--ios-separator);
        transition: background 0.15s;
    }
    .ios-list-item:active { background: #E5E5EA; }
    .ios-list-item:last-child { border-bottom: none; }

    /* Contact Avatar Circle */
    .contact-avatar-circle {
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* Status Indicator */
    .status-indicator {
        width: 10px; height: 10px; border-radius: 50%; display: inline-block;
    }
    .status-indicator.active { background: #34C759; box-shadow: 0 0 0 4px rgba(52,199,89,0.2); }
    .status-indicator.inactive { background: #FF9500; box-shadow: 0 0 0 4px rgba(255,149,0,0.2); }

    /* Custom Leaflet Marker Styling */
    .custom-rep-marker {
        background: transparent;
        border: none;
    }
    .rep-pin {
        width: 38px; height: 38px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 0.85rem; color: #fff;
        border: 3px solid #fff;
        box-shadow: 0 6px 12px rgba(0,0,0,0.25);
        transition: transform 0.2s;
    }
    .rep-pin:hover { transform: scale(1.1); z-index: 1000 !important; }

    /* Map Controls Override */
    .leaflet-control-zoom a {
        background: rgba(255,255,255,0.9) !important;
        color: var(--ios-label) !important;
        border: 1px solid var(--ios-separator) !important;
        backdrop-filter: blur(10px);
    }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Live Fleet Tracking</h1>
        <div class="page-subtitle">Monitor your sales representatives' real-time locations and activity.</div>
    </div>
    <div>
        <span class="ios-badge green px-3 py-2" style="font-size: 0.8rem; box-shadow: 0 4px 10px rgba(52,199,89,0.2);">
            <i class="bi bi-broadcast me-1"></i> Live Tracking Active
        </span>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Map Card -->
    <div class="col-lg-8">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(0,122,255,0.1); color: #0055CC;">
                        <i class="bi bi-geo-alt-fill"></i>
                    </span>
                    Real-time Territory Map
                </span>
            </div>
            <div class="flex-grow-1 p-3" style="background: var(--ios-surface-2); min-height: 500px;">
                <div id="liveMap" style="height: 100%; width: 100%; border-radius: 16px; border: 1px solid var(--ios-separator); box-shadow: inset 0 2px 10px rgba(0,0,0,0.02); z-index: 1;"></div>
            </div>
        </div>
    </div>
    
    <!-- Active Reps Card -->
    <div class="col-lg-4">
        <div class="dash-card h-100 d-flex flex-column">
            <div class="dash-card-header" style="background: var(--ios-surface); padding: 18px 24px; border-bottom: 1px solid var(--ios-separator);">
                <span class="card-title">
                    <span class="card-title-icon" style="background: rgba(52,199,89,0.1); color: #1A9A3A;">
                        <i class="bi bi-people-fill"></i>
                    </span>
                    Active Reps Today
                </span>
            </div>
            <div class="p-0 flex-grow-1" style="max-height: 530px; overflow-y: auto;">
                <div id="activeRepsList" class="ios-list-group m-0" style="border-radius: 0;">
                    <div class="text-center py-5">
                        <div class="spinner-border spinner-border-sm me-2 text-primary"></div> 
                        <span class="text-muted fw-bold" style="font-size: 0.9rem;">Fetching telemetry...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
let map;
let markers = {};
const colors = ['#FF2D55', '#007AFF', '#34C759', '#FF9500', '#AF52DE', '#30B0C7'];

function initMap() {
    map = L.map('liveMap').setView([7.8731, 80.7718], 7); // Default to Sri Lanka
    
    // Clean, modern map tiles (CartoDB Positron) for an iOS feel
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 20
    }).addTo(map);
    
    fetchLiveLocations();
    setInterval(fetchLiveLocations, 30000); // Update every 30 seconds
}

async function fetchLiveLocations() {
    try {
        const response = await fetch('../ajax/get_live_locations.php');
        const rawText = await response.text();
        
        let result;
        try {
            result = JSON.parse(rawText);
        } catch(e) {
            document.getElementById('activeRepsList').innerHTML = '<div class="text-center py-5 text-danger fw-bold"><i class="bi bi-wifi-off fs-1 d-block mb-2"></i>API Parse Error</div>';
            return;
        }
        
        if (result.success) {
            const listEl = document.getElementById('activeRepsList');
            listEl.innerHTML = '';
            
            if (result.data.length === 0) {
                listEl.innerHTML = `
                    <div class="empty-state py-5">
                        <i class="bi bi-moon-stars" style="font-size: 2.5rem; color: var(--ios-label-4);"></i>
                        <p class="mt-2" style="font-weight: 500;">No reps currently active today.</p>
                    </div>`;
                return;
            }

            result.data.forEach(rep => {
                // Time Logic
                let timeAgo = 'No location yet';
                if (rep.timestamp) {
                    const dateStr = rep.timestamp.replace(' ', 'T');
                    const repDate = new Date(dateStr);
                    timeAgo = timeSince(repDate);
                }
                
                // Avatar & Colors
                let words = rep.rep_name.split(' ');
                let initials = (words[0] ? words[0].charAt(0) : '') + (words[1] ? words[1].charAt(0) : '');
                initials = initials.toUpperCase();
                let color = colors[rep.rep_id % colors.length];

                // List UI
                const statusDot = rep.latitude ? '<span class="status-indicator active"></span>' : '<span class="status-indicator inactive"></span>';
                const avatarHtml = `<div class="contact-avatar-circle" style="background: ${color}20; color: ${color}; width: 42px; height: 42px; font-size: 1rem; margin-right: 14px;">${initials}</div>`;
                
                listEl.innerHTML += `
                    <a href="javascript:void(0)" class="ios-list-item" onclick="focusRep(${rep.latitude || null}, ${rep.longitude || null})">
                        ${avatarHtml}
                        <div class="flex-grow-1">
                            <div style="font-weight: 700; font-size: 0.95rem; color: var(--ios-label);">${rep.rep_name}</div>
                            <div style="font-size: 0.75rem; color: var(--ios-label-3); margin-top: 2px;">
                                <i class="bi bi-clock me-1"></i>Last updated: ${timeAgo}
                            </div>
                        </div>
                        <div class="ps-3 pe-2">
                            ${statusDot}
                        </div>
                    </a>
                `;

                // Map Marker UI
                if (rep.latitude && rep.longitude) {
                    const customIcon = L.divIcon({
                        className: 'custom-rep-marker',
                        html: `<div class="rep-pin" style="background: ${color};">${initials}</div>`,
                        iconSize: [38, 38],
                        iconAnchor: [19, 38],
                        popupAnchor: [0, -38]
                    });

                    const popupContent = `
                        <div style="font-family: -apple-system, sans-serif; text-align: center; padding: 4px;">
                            <div style="font-weight: 800; font-size: 0.95rem; color: #1c1c1e;">${rep.rep_name}</div>
                            <div style="font-size: 0.75rem; color: #8e8e93; margin-top: 2px;">${timeAgo}</div>
                        </div>
                    `;

                    if (markers[rep.rep_id]) {
                        markers[rep.rep_id].setLatLng([rep.latitude, rep.longitude]);
                        markers[rep.rep_id].setIcon(customIcon);
                        markers[rep.rep_id].setPopupContent(popupContent);
                    } else {
                        const marker = L.marker([rep.latitude, rep.longitude], {icon: customIcon}).addTo(map);
                        marker.bindPopup(popupContent, { closeButton: false, autoClose: false, className: 'ios-popup' });
                        markers[rep.rep_id] = marker;
                    }
                }
            });
        } else {
            console.error("API Error:", result.message);
            document.getElementById('activeRepsList').innerHTML = `<div class="text-center py-5 text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill fs-1 d-block mb-2"></i>${result.message || 'Error loading data'}</div>`;
        }
    } catch (e) {
        console.error('Failed to fetch live locations', e);
        document.getElementById('activeRepsList').innerHTML = '<div class="text-center py-5 text-danger fw-bold"><i class="bi bi-wifi-off fs-1 d-block mb-2"></i>Network Error</div>';
    }
}

function focusRep(lat, lng) {
    if(lat && lng) {
        map.setView([lat, lng], 15, { animate: true, duration: 1 });
    }
}

function timeSince(date) {
    var seconds = Math.floor((new Date() - date) / 1000);
    if (isNaN(seconds)) return 'Just now';
    
    var interval = seconds / 31536000;
    if (interval > 1) return Math.floor(interval) + " years ago";
    interval = seconds / 2592000;
    if (interval > 1) return Math.floor(interval) + " months ago";
    interval = seconds / 86400;
    if (interval > 1) return Math.floor(interval) + " days ago";
    interval = seconds / 3600;
    if (interval > 1) return Math.floor(interval) + " hours ago";
    interval = seconds / 60;
    if (interval > 1) return Math.floor(interval) + " mins ago";
    return Math.floor(seconds) > 0 ? Math.floor(seconds) + " seconds ago" : "Just now";
}

document.addEventListener('DOMContentLoaded', initMap);
</script>

<style>
/* Leaflet Popup iOS overrides */
.ios-popup .leaflet-popup-content-wrapper {
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    border: 1px solid var(--ios-separator);
}
.ios-popup .leaflet-popup-tip {
    box-shadow: none;
    border-bottom: 1px solid var(--ios-separator);
    border-right: 1px solid var(--ios-separator);
}
</style>

<?php include '../includes/footer.php'; ?>