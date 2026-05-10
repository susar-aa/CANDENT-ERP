<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']);

// --- FETCH METRICS ---
$month = date('Y-m');
$today = date('Y-m-d');

$active_employees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
$present_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE work_date = '$today' AND status IN ('present', 'half_day')")->fetchColumn();
$absent_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE work_date = '$today' AND status = 'absent'")->fetchColumn();

// Fetch monthly payroll cost
$payroll_cost = $pdo->query("SELECT COALESCE(SUM(net_pay), 0) FROM payroll WHERE month = '$month'")->fetchColumn();

// Designation Distribution
$desStmt = $pdo->query("SELECT designation, COUNT(id) as count FROM employees WHERE status = 'active' GROUP BY designation");
$desLabels = [];
$desData = [];
while($row = $desStmt->fetch()) {
    $desLabels[] = $row['designation'] ?: 'Unassigned';
    $desData[] = $row['count'];
}

// Fetch Today's Attendance List
$attendance = $pdo->query("
    SELECT e.name, e.emp_code, e.designation, a.status 
    FROM employees e 
    LEFT JOIN attendance a ON e.id = a.employee_id AND a.work_date = '$today'
    WHERE e.status = 'active'
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="bi bi-person-lines-fill text-info text-dark"></i> HR & Team Dashboard</h1>
    <div class="btn-group shadow-sm">
        <a href="attendance.php" class="btn btn-info fw-bold text-white"><i class="bi bi-calendar-check"></i> Mark Attendance</a>
        <a href="payroll.php" class="btn btn-outline-info text-dark fw-bold"><i class="bi bi-cash-stack"></i> Payroll</a>
        <a href="employees.php" class="btn btn-outline-info text-dark fw-bold"><i class="bi bi-people"></i> Staff List</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card bg-info text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Active Employees</div>
                <h3 class="mb-0 fw-bold"><?php echo $active_employees; ?> Staff</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Present Today</div>
                <h3 class="mb-0 fw-bold"><?php echo $present_today; ?> Present</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Absent Today</div>
                <h3 class="mb-0 fw-bold"><?php echo $absent_today; ?> Absent</h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark text-white border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-uppercase small fw-bold opacity-75 mb-1">Est. Payroll (MTD)</div>
                <h3 class="mb-0 fw-bold">Rs <?php echo number_format($payroll_cost, 2); ?></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold"><i class="bi bi-pie-chart"></i> Team Breakdown by Role</div>
            <div class="card-body d-flex justify-content-center" style="position: relative; height: 300px;">
                <canvas id="hrChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Table -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span><i class="bi bi-clock"></i> Today's Attendance List</span>
                <a href="attendance.php" class="btn btn-sm btn-outline-secondary">Update</a>
            </div>
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Employee</th>
                            <th>Role</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($attendance as $a): ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($a['name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($a['emp_code']); ?></small></td>
                            <td class="text-muted small"><?php echo htmlspecialchars($a['designation']); ?></td>
                            <td class="text-center">
                                <?php if($a['status'] == 'present'): ?>
                                    <span class="badge bg-success">Present</span>
                                <?php elseif($a['status'] == 'half_day'): ?>
                                    <span class="badge bg-warning text-dark">Half Day</span>
                                <?php elseif($a['status'] == 'absent'): ?>
                                    <span class="badge bg-danger">Absent</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unmarked</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($attendance)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No active employees found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new Chart(document.getElementById('hrChart').getContext('2d'), {
        type: 'pie',
        data: {
            labels: <?php echo json_encode($desLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($desData); ?>,
                backgroundColor: ['#0dcaf0', '#0d6efd', '#6610f2', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754'],
                borderWidth: 1
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });
});
</script>

<?php include '../includes/footer.php'; ?>