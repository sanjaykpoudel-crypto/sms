<?php
require_once 'database/DBConnection.php';
$db = db();

// Get active month and year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Clamp month
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

// Compute dates
$first_day_of_month = strtotime("$year-$month-01");
$days_in_month = (int)date('t', $first_day_of_month);
$start_day_of_week = (int)date('w', $first_day_of_month); // 0 = Sunday, 6 = Saturday

// Navigation parameters
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

// Fetch activities for the current month view
$start_db_range = date('Y-m-d 00:00:00', strtotime("-7 days", $first_day_of_month)); // Fetch slightly wider range for safety
$end_db_range = date('Y-m-d 23:59:59', strtotime("+37 days", $first_day_of_month));

$activities = $db->fetchAll("
    SELECT a.*, u.full_name AS assignee_name
    FROM activities a
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE a.is_deleted = 0
      AND (
        (a.activity_type = 'task' AND a.due_date BETWEEN ? AND ?)
        OR (a.activity_type != 'task' AND (
            a.start_date BETWEEN ? AND ?
            OR a.end_date BETWEEN ? AND ?
            OR (a.start_date <= ? AND a.end_date >= ?)
        ))
      )
", [$start_db_range, $end_db_range,
   $start_db_range, $end_db_range,
   $start_db_range, $end_db_range,
   $start_db_range, $end_db_range]);

// Map types for colors and icons
$type_map = [
    'task' => ['label' => 'Task', 'icon' => 'fa-check-square', 'color' => '#3498db'],
    'event' => ['label' => 'Event', 'icon' => 'fa-calendar-alt', 'color' => '#e67e22'],
    'phone_call' => ['label' => 'Phone Call', 'icon' => 'fa-phone-alt', 'color' => '#2ecc71'],
    'meeting' => ['label' => 'Meeting', 'icon' => 'fa-users', 'color' => '#9b59b6']
];

// Helper to check if an activity occurs on a given date (Y-m-d format)
function getActivitiesForDate($date_str, $activities_list) {
    $results = [];
    $target_time = strtotime($date_str);
    
    foreach ($activities_list as $act) {
        if ($act['activity_type'] === 'task') {
            if ($act['due_date'] && date('Y-m-d', strtotime($act['due_date'])) === $date_str) {
                $results[] = $act;
            }
        } else {
            $start_date = $act['start_date'] ? date('Y-m-d', strtotime($act['start_date'])) : '';
            $end_date = $act['end_date'] ? date('Y-m-d', strtotime($act['end_date'])) : '';
            
            if ($start_date && $end_date) {
                $start_time = strtotime($start_date);
                $end_time = strtotime($end_date);
                if ($target_time >= $start_time && $target_time <= $end_time) {
                    $results[] = $act;
                }
            } elseif ($start_date && $start_date === $date_str) {
                $results[] = $act;
            }
        }
    }
    return $results;
}

$month_names = ["", "January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
$current_date_str = date('Y-m-d');
?>

<style>
    .calendar-container {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        padding: 20px;
        margin-bottom: 20px;
    }
    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 15px;
    }
    .calendar-title {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .calendar-nav {
        display: flex;
        gap: 8px;
    }
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 1px;
        background: #cbd5e1;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
    }
    .calendar-day-header {
        background: #f8fafc;
        padding: 10px;
        text-align: center;
        font-weight: 700;
        font-size: 11px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .calendar-day-cell {
        background: #fff;
        min-height: 110px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        position: relative;
    }
    .calendar-day-cell.today {
        background: #f0fdf4;
    }
    .calendar-day-cell.today .day-number {
        color: #15803d;
        background: #dcfce7;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .calendar-day-cell.other-month {
        background: #f8fafc;
    }
    .calendar-day-cell.other-month .day-number {
        color: #94a3b8;
    }
    .day-number {
        font-size: 12px;
        font-weight: 700;
        color: #475569;
        margin-bottom: 4px;
    }
    .calendar-event-chips {
        display: flex;
        flex-direction: column;
        gap: 3px;
        overflow-y: auto;
        max-height: 85px;
    }
    .calendar-event-chip {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        padding: 2px 5px;
        border-radius: 3px;
        color: #fff;
        text-decoration: none;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        transition: opacity 0.2s;
    }
    .calendar-event-chip:hover {
        opacity: 0.9;
    }
    .calendar-event-chip.completed {
        opacity: 0.65;
        text-decoration: line-through;
    }
</style>

<div class="ns-page-header">
    <h1 class="ns-page-title">
        Activity Calendar
        <a href="?page=activity/manage" class="ns-btn ns-btn-primary" style="margin-left: 10px;">New Activity</a>
        <a href="?page=activity" class="ns-btn" style="margin-left: 5px;"><i class="fas fa-list"></i> List View</a>
    </h1>
</div>

<div class="calendar-container">
    <div class="calendar-header">
        <div class="calendar-title">
            <i class="far fa-calendar-alt" style="color: var(--ns-primary);"></i>
            <span><?php echo $month_names[$month] . " " . $year; ?></span>
        </div>
        <div class="calendar-nav">
            <a href="?page=activity/calendar&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="ns-btn" title="Previous Month"><i class="fas fa-chevron-left"></i></a>
            <a href="?page=activity/calendar&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="ns-btn">Today</a>
            <a href="?page=activity/calendar&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="ns-btn" title="Next Month"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>

    <div class="calendar-grid">
        <!-- Day Names Header -->
        <div class="calendar-day-header">Sun</div>
        <div class="calendar-day-header">Mon</div>
        <div class="calendar-day-header">Tue</div>
        <div class="calendar-day-header">Wed</div>
        <div class="calendar-day-header">Thu</div>
        <div class="calendar-day-header">Fri</div>
        <div class="calendar-day-header">Sat</div>

        <?php
        // 1. Render Padding Days from Previous Month
        $prev_month_days = (int)date('t', strtotime("-1 month", $first_day_of_month));
        $padding_start = $prev_month_days - $start_day_of_week + 1;
        
        for ($i = 0; $i < $start_day_of_week; $i++) {
            $day_num = $padding_start + $i;
            $padded_month = $month - 1;
            $padded_year = $year;
            if ($padded_month < 1) { $padded_month = 12; $padded_year--; }
            $padded_date = sprintf('%04d-%02d-%02d', $padded_year, $padded_month, $day_num);
            $day_activities = getActivitiesForDate($padded_date, $activities);
            
            echo '<div class="calendar-day-cell other-month">';
            echo '  <span class="day-number">' . $day_num . '</span>';
            echo '  <div class="calendar-event-chips">';
            foreach ($day_activities as $act) {
                $type_info = $type_map[$act['activity_type']];
                $completed_class = ($act['status'] === 'completed') ? 'completed' : '';
                echo '    <a href="?page=activity/view&id=' . $act['id'] . '" class="calendar-event-chip ' . $completed_class . '" style="background-color: ' . $type_info['color'] . ';" title="' . htmlspecialchars($act['title']) . '">';
                echo '      <i class="fas ' . $type_info['icon'] . '"></i> ' . htmlspecialchars($act['title']);
                echo '    </a>';
            }
            echo '  </div>';
            echo '</div>';
        }

        // 2. Render Current Month Days
        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_cell_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $is_today = ($current_cell_date === $current_date_str);
            $day_activities = getActivitiesForDate($current_cell_date, $activities);
            
            $cell_class = $is_today ? 'calendar-day-cell today' : 'calendar-day-cell';
            
            echo '<div class="' . $cell_class . '">';
            echo '  <div><span class="day-number">' . $day . '</span></div>';
            echo '  <div class="calendar-event-chips">';
            foreach ($day_activities as $act) {
                $type_info = $type_map[$act['activity_type']];
                $completed_class = ($act['status'] === 'completed') ? 'completed' : '';
                echo '    <a href="?page=activity/view&id=' . $act['id'] . '" class="calendar-event-chip ' . $completed_class . '" style="background-color: ' . $type_info['color'] . ';" title="' . htmlspecialchars($act['title']) . '">';
                echo '      <i class="fas ' . $type_info['icon'] . '"></i> ' . htmlspecialchars($act['title']);
                echo '    </a>';
            }
            echo '  </div>';
            echo '</div>';
        }

        // 3. Render Padding Days for Next Month (to complete 42 cells grid if needed, or just standard wrapping)
        $total_cells = $start_day_of_week + $days_in_month;
        $remaining_cells = 42 - $total_cells;
        if ($remaining_cells > 0 && $remaining_cells < 7) {
            // we already filled at least 5 rows (35 cells), if we need to complete the 6th row
        } else if ($remaining_cells >= 7) {
            // standard 6 rows grid
        }
        
        // Loop to fill exactly up to 42 cells total (6 full rows)
        $next_day_counter = 1;
        for ($i = 0; $i < $remaining_cells; $i++) {
            $padded_month = $month + 1;
            $padded_year = $year;
            if ($padded_month > 12) { $padded_month = 1; $padded_year++; }
            $padded_date = sprintf('%04d-%02d-%02d', $padded_year, $padded_month, $next_day_counter);
            $day_activities = getActivitiesForDate($padded_date, $activities);
            
            echo '<div class="calendar-day-cell other-month">';
            echo '  <span class="day-number">' . $next_day_counter . '</span>';
            echo '  <div class="calendar-event-chips">';
            foreach ($day_activities as $act) {
                $type_info = $type_map[$act['activity_type']];
                $completed_class = ($act['status'] === 'completed') ? 'completed' : '';
                echo '    <a href="?page=activity/view&id=' . $act['id'] . '" class="calendar-event-chip ' . $completed_class . '" style="background-color: ' . $type_info['color'] . ';" title="' . htmlspecialchars($act['title']) . '">';
                echo '      <i class="fas ' . $type_info['icon'] . '"></i> ' . htmlspecialchars($act['title']);
                echo '    </a>';
            }
            echo '  </div>';
            echo '</div>';
            $next_day_counter++;
        }
        ?>
    </div>
</div>
