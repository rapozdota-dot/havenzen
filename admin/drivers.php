<?php
require_once 'auth.php';
$page_title = "Driver Management";
require_once 'header.php';

function hz_admin_upload_path_exists(?string $path): bool
{
    return hz_upload_path_exists($path);
}

function hz_admin_upload_href(?string $path): string
{
    return hz_upload_href($path);
}

// handle message/error display (left in place in case other flows set them)
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
                <th>License Images</th>
                <th>Assigned Vehicle</th>
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
                    d.license_front_image,
                    d.license_back_image,
                    d.created_at,
                    v.vehicle_name
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
                <td>
                    <?php $hasFrontImage = hz_admin_upload_path_exists($driver['license_front_image'] ?? ''); ?>
                    <?php $hasBackImage = hz_admin_upload_path_exists($driver['license_back_image'] ?? ''); ?>
                    <?php if ($hasFrontImage): ?>
                        <a href="<?php echo htmlspecialchars(hz_admin_upload_href($driver['license_front_image'])); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Front</a>
                    <?php endif; ?>
                    <?php if ($hasBackImage): ?>
                        <a href="<?php echo htmlspecialchars(hz_admin_upload_href($driver['license_back_image'])); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Back</a>
                    <?php endif; ?>
                    <?php if (!$hasFrontImage && !$hasBackImage): ?>
                        <span style="color:#777;">Not uploaded</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $driver['vehicle_name'] ? htmlspecialchars($driver['vehicle_name']) : 'Not Assigned'; ?></td>
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

<?php require_once 'footer.php'; ?>
