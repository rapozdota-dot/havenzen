<?php
require_once 'auth.php';
$page_title = "Driver Management";
require_once 'header.php';

date_default_timezone_set('Asia/Manila');

function hz_admin_upload_path_exists(?string $path): bool
{
    return hz_upload_path_exists($path);
}

function hz_admin_upload_href(?string $path): string
{
    return hz_upload_href($path);
}

function hz_admin_driver_status_badge(string $status): string
{
    $status = strtolower(trim($status ?: 'approved'));
    $class = $status === 'approved' ? 'status-active' : ($status === 'rejected' ? 'status-cancelled' : 'status-pending');
    return '<span class="status-badge ' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $driver_user_id = intval($_POST['driver_user_id'] ?? 0);
    $approval_notes = $conn->real_escape_string(trim($_POST['approval_notes'] ?? ''));
    $admin_id = intval($_SESSION['user_id'] ?? 0);

    if ($driver_user_id > 0 && in_array($action, ['approve_driver', 'reject_driver'], true)) {
        $newStatus = $action === 'approve_driver' ? 'approved' : 'rejected';
        $approvedAtSql = $newStatus === 'approved' ? 'NOW()' : 'NULL';
        $approvedBySql = $newStatus === 'approved' ? (string) $admin_id : 'NULL';

        if ($conn->query("
            UPDATE drivers
            SET approval_status = '{$newStatus}',
                approval_notes = '{$approval_notes}',
                approved_at = {$approvedAtSql},
                approved_by = {$approvedBySql}
            WHERE user_id = {$driver_user_id}
        ")) {
            $message = $newStatus === 'approved' ? 'Driver application approved.' : 'Driver application rejected.';
            logCRUD($conn, $admin_id, 'UPDATE', 'drivers', $driver_user_id, 'Driver application ' . $newStatus);
        } else {
            $error = 'Unable to update driver application: ' . $conn->error;
        }
    }
}

// handle message/error display (left in place in case other flows set them)
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$monthStart = date('Y-m-01 00:00:00');
$nextMonthStart = date('Y-m-01 00:00:00', strtotime('+1 month'));

$pendingDrivers = $conn->query("
    SELECT
        u.user_id,
        u.username,
        d.full_name,
        d.email,
        d.phone_number,
        d.license_number,
        d.license_expiry,
        d.license_class,
        d.license_front_image,
        d.license_back_image,
        d.years_experience,
        d.emergency_contact,
        d.emergency_phone,
        d.address,
        d.created_at,
        d.approval_status
    FROM users u
    JOIN drivers d ON d.user_id = u.user_id
    WHERE u.role = 'driver'
      AND d.approval_status = 'pending'
    ORDER BY d.created_at ASC
");
?>

<?php if (isset($message)): ?>
    <div style="background: #e8f5e8; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<style>
.driver-application-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 16px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}

.driver-application-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin: 14px 0;
}

.driver-application-grid span {
    display: block;
    color: #64748b;
    font-size: 0.84rem;
}

.driver-approval-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}

.driver-approval-actions textarea {
    min-width: min(360px, 100%);
}

.license-preview-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 3000;
    background: rgba(15, 23, 42, 0.78);
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.license-preview-modal.is-open {
    display: flex;
}

.license-preview-modal img {
    max-width: min(900px, 96vw);
    max-height: 82vh;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 22px 80px rgba(0, 0, 0, 0.28);
}
</style>

<!-- Pending Driver Applications -->
<div class="table-container" style="margin-bottom: 24px;">
    <div class="table-header">
        <h2>Driver Account Approval</h2>
    </div>

    <?php if (!$pendingDrivers || $pendingDrivers->num_rows === 0): ?>
        <div style="padding: 18px; color: #64748b;">No pending driver applications.</div>
    <?php else: ?>
        <div style="padding: 16px;">
            <?php while ($pending = $pendingDrivers->fetch_assoc()): ?>
                <div class="driver-application-card">
                    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0 0 4px;"><?php echo htmlspecialchars($pending['full_name']); ?></h3>
                            <p style="margin:0; color:#64748b;">
                                @<?php echo htmlspecialchars($pending['username']); ?> &bull;
                                Applied <?php echo date('M j, Y g:i A', strtotime($pending['created_at'])); ?>
                            </p>
                        </div>
                        <?php echo hz_admin_driver_status_badge($pending['approval_status'] ?? 'pending'); ?>
                    </div>

                    <div class="driver-application-grid">
                        <div><span>Email</span><strong><?php echo htmlspecialchars($pending['email'] ?: 'N/A'); ?></strong></div>
                        <div><span>Phone</span><strong><?php echo htmlspecialchars($pending['phone_number'] ?: 'N/A'); ?></strong></div>
                        <div><span>License No.</span><strong><?php echo htmlspecialchars($pending['license_number'] ?: 'N/A'); ?></strong></div>
                        <div><span>License Class</span><strong><?php echo htmlspecialchars($pending['license_class'] ?: 'N/A'); ?></strong></div>
                        <div><span>License Expiry</span><strong><?php echo $pending['license_expiry'] ? date('M j, Y', strtotime($pending['license_expiry'])) : 'N/A'; ?></strong></div>
                        <div><span>Experience</span><strong><?php echo intval($pending['years_experience'] ?? 0); ?> year(s)</strong></div>
                        <div><span>Emergency Contact</span><strong><?php echo htmlspecialchars($pending['emergency_contact'] ?: 'N/A'); ?></strong></div>
                        <div><span>Emergency Phone</span><strong><?php echo htmlspecialchars($pending['emergency_phone'] ?: 'N/A'); ?></strong></div>
                        <div><span>Address</span><strong><?php echo htmlspecialchars($pending['address'] ?: 'N/A'); ?></strong></div>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                        <?php if (hz_admin_upload_path_exists($pending['license_front_image'] ?? '')): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openLicensePreview('<?php echo htmlspecialchars(hz_admin_upload_href($pending['license_front_image']), ENT_QUOTES); ?>')">View License Front</button>
                        <?php endif; ?>
                        <?php if (hz_admin_upload_path_exists($pending['license_back_image'] ?? '')): ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openLicensePreview('<?php echo htmlspecialchars(hz_admin_upload_href($pending['license_back_image']), ENT_QUOTES); ?>')">View License Back</button>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="driver-approval-actions">
                        <input type="hidden" name="driver_user_id" value="<?php echo intval($pending['user_id']); ?>">
                        <div class="form-group" style="margin-bottom:0; flex:1;">
                            <label>Approval Notes</label>
                            <textarea name="approval_notes" rows="2" placeholder="Optional note for this review"></textarea>
                        </div>
                        <button type="submit" name="action" value="approve_driver" class="btn btn-primary">Approve</button>
                        <button type="submit" name="action" value="reject_driver" class="btn btn-danger" onclick="return confirm('Reject this driver application?');">Reject</button>
                    </form>
                </div>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Drivers List -->
<div class="table-container">
    <div class="table-header">
        <h2>All Drivers</h2>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Phone Number</th>
                <th>Approval</th>
                <th>License Images</th>
                <th>Assigned Vehicle</th>
                <th>Trips Today</th>
                <th>Trips This Month</th>
                <th>Registered Date</th>
                <th>Availability</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $drivers = $conn->query("
                SELECT 
                    u.user_id,
                    u.username,
                    d.full_name,
                    d.phone_number,
                    d.approval_status,
                    d.license_front_image,
                    d.license_back_image,
                    d.created_at,
                    v.vehicle_id,
                    v.vehicle_name,
                    (
                        SELECT COUNT(*)
                        FROM vehicle_trips vt
                        WHERE vt.vehicle_id = v.vehicle_id
                          AND vt.trip_status = 'completed'
                          AND COALESCE(vt.completed_at, vt.scheduled_departure_at) >= '$todayStart'
                          AND COALESCE(vt.completed_at, vt.scheduled_departure_at) < '$tomorrowStart'
                    ) AS trips_today,
                    (
                        SELECT COUNT(*)
                        FROM vehicle_trips vt
                        WHERE vt.vehicle_id = v.vehicle_id
                          AND vt.trip_status = 'completed'
                          AND COALESCE(vt.completed_at, vt.scheduled_departure_at) >= '$monthStart'
                          AND COALESCE(vt.completed_at, vt.scheduled_departure_at) < '$nextMonthStart'
                    ) AS trips_month
                FROM users u
                JOIN drivers d ON d.user_id = u.user_id
                LEFT JOIN vehicles v ON v.driver_id = u.user_id
                WHERE u.role = 'driver'
                ORDER BY d.created_at DESC
            ");
            
            while ($driver = $drivers->fetch_assoc()):
            ?>
            <tr>
                <td><?php echo $driver['user_id']; ?></td>
                <td><?php echo htmlspecialchars($driver['username']); ?></td>
                <td><?php echo htmlspecialchars($driver['full_name']); ?></td>
                <td><?php echo htmlspecialchars($driver['phone_number']); ?></td>
                <td><?php echo hz_admin_driver_status_badge($driver['approval_status'] ?? 'approved'); ?></td>
                <td>
                    <?php $hasFrontImage = hz_admin_upload_path_exists($driver['license_front_image'] ?? ''); ?>
                    <?php $hasBackImage = hz_admin_upload_path_exists($driver['license_back_image'] ?? ''); ?>
                    <?php if ($hasFrontImage): ?>
                        <button type="button" onclick="openLicensePreview('<?php echo htmlspecialchars(hz_admin_upload_href($driver['license_front_image']), ENT_QUOTES); ?>')" class="btn btn-secondary btn-sm">Front</button>
                    <?php endif; ?>
                    <?php if ($hasBackImage): ?>
                        <button type="button" onclick="openLicensePreview('<?php echo htmlspecialchars(hz_admin_upload_href($driver['license_back_image']), ENT_QUOTES); ?>')" class="btn btn-secondary btn-sm">Back</button>
                    <?php endif; ?>
                    <?php if (!$hasFrontImage && !$hasBackImage): ?>
                        <span style="color:#777;">Not uploaded</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $driver['vehicle_name'] ? htmlspecialchars($driver['vehicle_name']) : 'Not Assigned'; ?></td>
                <td><?php echo intval($driver['trips_today'] ?? 0); ?></td>
                <td><?php echo intval($driver['trips_month'] ?? 0); ?></td>
                <td><?php echo date('M j, Y', strtotime($driver['created_at'])); ?></td>
                <td>
                    <a href="driver_availability.php?driver_id=<?php echo $driver['user_id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-clock"></i> View
                    </a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<div class="license-preview-modal" id="licensePreviewModal" onclick="closeLicensePreview(event)">
    <img src="" alt="Driver license preview" id="licensePreviewImage">
</div>

<script>
function openLicensePreview(src) {
    const modal = document.getElementById('licensePreviewModal');
    const image = document.getElementById('licensePreviewImage');
    image.src = src;
    modal.classList.add('is-open');
}

function closeLicensePreview(event) {
    if (event.target.id === 'licensePreviewModal') {
        event.currentTarget.classList.remove('is-open');
        document.getElementById('licensePreviewImage').src = '';
    }
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.getElementById('licensePreviewModal')?.classList.remove('is-open');
    }
});
</script>

<?php require_once 'footer.php'; ?>
