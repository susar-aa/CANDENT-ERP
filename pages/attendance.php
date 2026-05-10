<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireRole(['admin', 'supervisor']); 

$message = '';

// --- AUTO DB MIGRATION ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        work_date DATE NOT NULL,
        status ENUM('present', 'half_day', 'absent') DEFAULT 'present',
        UNIQUE KEY emp_date (employee_id, work_date),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch(PDOException $e) {}
// -------------------------

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// --- HANDLE SAVING ATTENDANCE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_attendance') {
    $date = $_POST['attendance_date'];
    $statuses = $_POST['status']; // Array: [emp_id => status]

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
        
        foreach ($statuses as $emp_id => $status) {
            $stmt->execute([$emp_id, $date, $status]);
        }
        
        $pdo->commit();
        $message = "<div class='alert alert-success'>Attendance saved successfully for " . date('M d, Y', strtotime($date)) . "!</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='alert alert-danger'>Error saving attendance.</div>";
    }
}

// --- FETCH DATA FOR SELECTED DATE ---
// Fetch active employees, joining with attendance for the selected date to prepopulate if already saved
$query = "
    SELECT e.id, e.emp_code, e.name, e.designation, a.status as att_status 
    FROM employees e 
    LEFT JOIN attendance a ON e.id = a.employee_id AND a.work_date = ?
    WHERE e.status = 'active'
    ORDER BY e.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$selected_date]);
$employees = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2">Daily Attendance Tracker</h1>
</div>

<?php echo $message; ?>

<!-- Date Selector -->
<div class="card shadow-sm mb-4 border-0">
    <div class="card-body bg-light rounded d-flex align-items-center">
        <label class="fw-bold me-3"><i class="bi bi-calendar-event"></i> Select Date:</label>
        <form method="GET" id="dateForm" class="d-flex">
            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="document.getElementById('dateForm').submit();" max="<?php echo date('Y-m-d'); ?>">
        </form>
    </div>
</div>

<!-- Attendance Form -->
<form method="POST">
    <input type="hidden" name="action" value="save_attendance">
    <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
            <span>Marking for: <span class="text-primary"><?php echo date('l, F d, Y', strtotime($selected_date)); ?></span></span>
            <button type="submit" class="btn btn-primary btn-sm fw-bold px-4 shadow-sm"><i class="bi bi-save"></i> Save Attendance</button>
        </div>
        <div class="table-responsive bg-white rounded">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 10%;">Code</th>
                        <th style="width: 40%;">Employee</th>
                        <th class="text-center" style="width: 50%;">Attendance Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employees as $e): 
                        $status = $e['att_status'] ?: 'present'; // Default to present if not marked
                    ?>
                    <tr>
                        <td class="text-muted fw-bold"><?php echo htmlspecialchars($e['emp_code']); ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($e['name']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($e['designation']); ?></div>
                        </td>
                        <td class="text-center">
                            <div class="btn-group shadow-sm" role="group">
                                <input type="radio" class="btn-check" name="status[<?php echo $e['id']; ?>]" id="pres_<?php echo $e['id']; ?>" value="present" <?php echo $status == 'present' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-success fw-bold px-4" for="pres_<?php echo $e['id']; ?>">Present</label>

                                <input type="radio" class="btn-check" name="status[<?php echo $e['id']; ?>]" id="half_<?php echo $e['id']; ?>" value="half_day" <?php echo $status == 'half_day' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-warning fw-bold px-3" for="half_<?php echo $e['id']; ?>">Half Day</label>

                                <input type="radio" class="btn-check" name="status[<?php echo $e['id']; ?>]" id="abs_<?php echo $e['id']; ?>" value="absent" <?php echo $status == 'absent' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-danger fw-bold px-4" for="abs_<?php echo $e['id']; ?>">Absent</label>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($employees)): ?>
                    <tr><td colspan="3" class="text-center py-5 text-muted">No active employees to mark attendance for.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php include '../includes/footer.php'; ?>