<?php
require_once 'auth.php';
require_once 'header.php';

// Get date range from request or default to current month
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$start_date = date('Y-m-d', strtotime($start_date) ?: strtotime(date('Y-m-01')));
$end_date = date('Y-m-d', strtotime($end_date) ?: strtotime(date('Y-m-t')));

// Use the internal driver profile id for earnings (driver_earnings.driver_id)
$earnings_driver_id = $driver_profile_id ?: $driver_id;

// Get earnings summary
$earnings_summary = $conn->query("
    SELECT 
        COUNT(*) as total_bookings,
        COALESCE(SUM(amount), 0) as total_earnings,
        COALESCE(AVG(amount), 0) as avg_earning_per_trip,
        COALESCE(SUM(CASE WHEN DATE(earning_date) = CURDATE() THEN amount ELSE 0 END), 0) as today_earnings
    FROM driver_earnings 
    WHERE driver_id = $earnings_driver_id 
    AND earning_date BETWEEN '$start_date' AND '$end_date'
    AND status = 'pending'
");
$earnings_summary = $earnings_summary ? $earnings_summary->fetch_assoc() : [];
$earnings_summary = array_merge([
    'total_bookings' => 0,
    'total_earnings' => 0,
    'avg_earning_per_trip' => 0,
    'today_earnings' => 0,
], $earnings_summary ?: []);

// Get weekly earnings for chart
$weekly_earnings = $conn->query("
    SELECT 
        YEARWEEK(earning_date, 1) as week,
        SUM(amount) as weekly_total,
        MIN(earning_date) as week_start,
        MAX(earning_date) as week_end
    FROM driver_earnings 
    WHERE driver_id = $earnings_driver_id 
    AND earning_date BETWEEN DATE_SUB('$start_date', INTERVAL 6 WEEK) AND '$end_date'
    AND status = 'pending'
    GROUP BY YEARWEEK(earning_date, 1)
    ORDER BY week_start DESC
    LIMIT 6
");

$weekly_chart_data = [];
if ($weekly_earnings) {
while ($row = $weekly_earnings->fetch_assoc()) {
    $week_label = date('M j', strtotime($row['week_start'])) . ' - ' . date('M j', strtotime($row['week_end']));
    $weekly_chart_data[] = [
        'label' => $week_label,
        'earnings' => (float)$row['weekly_total']
    ];
}
}
$weekly_chart_data = array_reverse($weekly_chart_data);

// Get recent transactions
$recent_earnings = $conn->query("
    SELECT de.*, b.booking_id, b.pickup_location, b.dropoff_location, b.status AS booking_status, c.full_name as passenger_name
    FROM driver_earnings de
    JOIN bookings b ON de.booking_id = b.booking_id
    JOIN customers c ON b.passenger_id = c.user_id
    WHERE de.driver_id = $earnings_driver_id 
    AND de.earning_date BETWEEN '$start_date' AND '$end_date'
    ORDER BY de.earning_date DESC
    LIMIT 20
");

// Calculate growth percentage
$previous_period_earnings = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM driver_earnings 
    WHERE driver_id = $earnings_driver_id 
    AND earning_date BETWEEN DATE_SUB('$start_date', INTERVAL 1 MONTH) AND DATE_SUB('$end_date', INTERVAL 1 MONTH)
    AND status = 'pending'
");
$previous_period_earnings = $previous_period_earnings ? $previous_period_earnings->fetch_assoc() : ['total' => 0];

$growth_percentage = $previous_period_earnings['total'] > 0 
    ? (($earnings_summary['total_earnings'] - $previous_period_earnings['total']) / $previous_period_earnings['total']) * 100 
    : 0;
?>

<div class="dashboard-header">
    <h1>Earnings & Analytics</h1>
    <p>Track your earnings, view analytics, and manage your payments.</p>
</div>

<!-- Date Filter -->
<div class="filter-section">
    <form method="GET" class="date-filter">
        <div class="filter-group">
            <label for="start_date">From Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="filter-group">
            <label for="end_date">To Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <button type="button" class="btn btn-secondary" onclick="resetDateFilter()">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </form>
</div>

<!-- Earnings Overview -->
<div class="earnings-overview">
    <div class="earnings-card primary">
        <div class="earnings-content">
            <div class="earnings-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="earnings-info">
                <div class="earnings-label">Total Earnings</div>
                <div class="earnings-amount">₱<?php echo number_format($earnings_summary['total_earnings'], 2); ?></div>
                <div class="earnings-growth <?php echo $growth_percentage >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-arrow-<?php echo $growth_percentage >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo number_format(abs($growth_percentage), 1); ?>% from last period
                </div>
            </div>
        </div>
    </div>
    
    <div class="earnings-card">
        <div class="earnings-content">
            <div class="earnings-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="earnings-info">
                <div class="earnings-label">Today's Earnings</div>
                <div class="earnings-amount">₱<?php echo number_format($earnings_summary['today_earnings'], 2); ?></div>
                <div class="earnings-subtext">As of <?php echo date('g:i A'); ?></div>
            </div>
        </div>
    </div>
    
    <div class="earnings-card">
        <div class="earnings-content">
            <div class="earnings-icon">
                <i class="fas fa-car"></i>
            </div>
            <div class="earnings-info">
                <div class="earnings-label">Total Bookings</div>
                <div class="earnings-amount"><?php echo $earnings_summary['total_bookings']; ?></div>
                <div class="earnings-subtext">Completed rides</div>
            </div>
        </div>
    </div>
    
    <div class="earnings-card">
        <div class="earnings-content">
            <div class="earnings-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="earnings-info">
                <div class="earnings-label">Avg per Trip</div>
                <div class="earnings-amount">₱<?php echo number_format($earnings_summary['avg_earning_per_trip'], 2); ?></div>
                <div class="earnings-subtext">Average earnings</div>
            </div>
        </div>
    </div>
</div>

<!-- Earnings Chart & Payment Summary -->
<div class="chart-summary-row">
    <div class="chart-section">
        <div class="section-header">
            <h3>Earnings Trend</h3>
            <p>Your earnings over the past 6 weeks</p>
        </div>
        <div class="chart-container">
            <canvas id="earningsChart" width="400" height="200"></canvas>
        </div>
    </div>
    
    <div class="summary-card chart-summary-card">
        <h4>Payment Summary</h4>
        <div class="summary-list">
            <div class="summary-item">
                <span class="summary-label">Available Balance</span>
                <span class="summary-value">₱<?php echo number_format($earnings_summary['total_earnings'], 2); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Next Payout</span>
                <span class="summary-value">₱<?php echo number_format($earnings_summary['total_earnings'], 2); ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Payout Date</span>
                <span class="summary-value"><?php echo date('M j, Y', strtotime('next monday')); ?></span>
            </div>
        </div>
        <button class="btn btn-primary full-width" onclick="requestPayout()">
            <i class="fas fa-wallet"></i> Request Payout
        </button>
    </div>
</div>

<div class="earnings-content">
    <div class="transactions-section">
        <div class="section-header">
            <h3>Recent Transactions</h3>
            <div class="section-actions">
                <button class="btn btn-secondary" onclick="exportEarnings()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </div>
        </div>
        
        <div class="transactions-table">
            <div class="table-responsive">
                <table class="earnings-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Booking ID</th>
                            <th>Passenger</th>
                            <th>Trip Details</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_earnings && $recent_earnings->num_rows > 0): ?>
                            <?php while ($earning = $recent_earnings->fetch_assoc()): ?>
                                <tr>
                                    <td data-label="Date & Time">
                                        <div class="date-time">
                                            <div class="date"><?php echo date('M j, Y', strtotime($earning['earning_date'])); ?></div>
                                            <div class="time"><?php echo date('g:i A', strtotime($earning['earning_date'])); ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Booking ID">#<?php echo $earning['booking_id']; ?></td>
                                    <td data-label="Passenger"><?php echo htmlspecialchars($earning['passenger_name']); ?></td>
                                    <td data-label="Trip Details">
                                        <div class="trip-details">
                                            <div class="trip-from">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?php echo htmlspecialchars($earning['pickup_location']); ?>
                                            </div>
                                            <div class="trip-to">
                                                <i class="fas fa-flag-checkered"></i>
                                                <?php echo htmlspecialchars($earning['dropoff_location']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Amount" class="amount">₱<?php echo number_format($earning['amount'], 2); ?></td>
                                    <td data-label="Status">
                                        <?php $display_status = !empty($earning['booking_status']) ? $earning['booking_status'] : $earning['status']; ?>
                                        <span class="status-badge status-<?php echo htmlspecialchars($display_status); ?>">
                                            <?php echo ucfirst(htmlspecialchars($display_status)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <h4>No earnings found</h4>
                                    <p>You don't have any earnings for the selected period.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="analytics-sidebar">
        <!-- Top Performing Days -->
        <div class="performance-card">
            <h4>Top Performing Days</h4>
            <div class="performance-list">
                <?php
                $top_days = $conn->query("
                    SELECT 
                        DAYNAME(earning_date) as day_name,
                        SUM(amount) as day_earnings,
                        COUNT(*) as day_bookings
                    FROM driver_earnings 
                    WHERE driver_id = $earnings_driver_id
                    AND earning_date BETWEEN DATE_SUB('$start_date', INTERVAL 30 DAY) AND '$end_date'
                    GROUP BY DAYNAME(earning_date)
                    ORDER BY day_earnings DESC
                    LIMIT 3
                ");
                
                if ($top_days && $top_days->num_rows > 0):
                    while ($day = $top_days->fetch_assoc()):
                ?>
                    <div class="performance-item">
                        <span class="day-name"><?php echo $day['day_name']; ?></span>
                        <span class="day-earnings">₱<?php echo number_format($day['day_earnings'], 2); ?></span>
                        <span class="day-trips"><?php echo $day['day_bookings']; ?> bookings</span>
                    </div>
                <?php 
                    endwhile;
                else:
                ?>
                    <div class="empty-performance">
                        <p>No performance data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payout Request Modal -->
<div id="payoutModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Request Payout</h3>
            <button class="modal-close" onclick="closePayoutModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="payout-info">
                <div class="available-balance">
                    <span class="balance-label">Available Balance:</span>
                    <span class="balance-amount">₱<?php echo number_format($earnings_summary['total_earnings'], 2); ?></span>
                </div>
                <div class="payout-note">
                    <p><i class="fas fa-info-circle"></i> Payouts are processed every Monday. Funds will be transferred to your registered bank account within 2-3 business days.</p>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closePayoutModal()">Cancel</button>
            <button class="btn btn-primary" onclick="confirmPayout()">
                <i class="fas fa-check"></i> Confirm Payout Request
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function initEarningsChart() {
    const ctx = document.getElementById('earningsChart').getContext('2d');
    
    const weeklyData = <?php echo json_encode($weekly_chart_data); ?> || [];
    
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: weeklyData.map(w => w.label),
            datasets: [{
                label: 'Weekly Earnings',
                data: weeklyData.map(w => w.earnings),
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.1)'
                    },
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

function resetDateFilter() {
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    document.querySelector('.date-filter').submit();
}

function requestPayout() {
    document.getElementById('payoutModal').style.display = 'flex';
}

function closePayoutModal() {
    document.getElementById('payoutModal').style.display = 'none';
}

function confirmPayout() {
    showNotification('Payout requests are not connected yet. Please coordinate payout manually for now.', 'info');
    closePayoutModal();
}

function exportEarnings() {
    showLoading();
    
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    window.location.href = `export_earnings.php?start_date=${startDate}&end_date=${endDate}`;
    
    setTimeout(() => {
        hideLoading();
    }, 2000);
}

document.addEventListener('DOMContentLoaded', function() {
    initEarningsChart();
});
</script>

<?php require_once 'footer.php'; ?>
