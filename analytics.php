<?php
session_start();
$conn = new mysqli(
    "sql209.infinityfree.com",
    "if0_42102472",
    "qfHgzrTdk9BM",
    "if0_42102472_university_timetable"
);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }
function q1($conn, $sql, $default=''){ $res = @$conn->query($sql); if(!$res) return $default; $row = $res->fetch_assoc(); if(!$row) return $default; return array_values($row)[0] ?? $default; }
function table_exists($conn, $table){ $safe = $conn->real_escape_string($table); $res = $conn->query("SHOW TABLES LIKE '$safe'"); return $res && $res->num_rows > 0; }
function column_exists($conn, $table, $column){ $safeTable = $conn->real_escape_string($table); $safeCol = $conn->real_escape_string($column); $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'"); return $res && $res->num_rows > 0; }

$context = $_GET['context'] ?? 'portal';
if(!in_array($context, ['admin','portal'])) $context = 'portal';
$selected_year = $_GET['academic_year'] ?? '2025-26';
$selected_semester = $_GET['semester'] ?? 'Odd';
if($selected_semester === 'Sem-I') $selected_semester = 'Odd';
if($selected_semester === 'Sem-II') $selected_semester = 'Even';
$active_tab = $_GET['tab'] ?? 'overview';

$aySafe  = $conn->real_escape_string($selected_year);
$semSafe = $conn->real_escape_string($selected_semester);

$days = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
$teaching_slots = ["08:45-09:40","09:40-10:35","10:50-11:45","11:45-12:40","01:40-02:35","02:35-03:30","03:40-04:30"];
$all_slots      = ["08:45-09:40","09:40-10:35","10:35-10:50","10:50-11:45","11:45-12:40","12:40-01:40","01:40-02:35","02:35-03:30","03:30-03:40","03:40-04:30"];
$break_slots    = ["10:35-10:50","12:40-01:40","03:30-03:40"];
$badFaculty     = "faculty_code NOT REGEXP '^(D[0-9]+|[0-9]+PM|[0-9]+|[SN][0-9]|ONLINE|NULL|NONE|NAN)$'";

$hasFacultyUid  = column_exists($conn,'faculties','faculty_uid');
$hasSubjectType = column_exists($conn,'subjects','subject_type');
$hasDivDept     = column_exists($conn,'divisions','department');
$hasDivYear     = column_exists($conn,'divisions','year_name');
$hasDivSpec     = column_exists($conn,'divisions','specialization');
$hasClassRT     = column_exists($conn,'classrooms','resource_type');
$hasLabDetails  = table_exists($conn,'lab_details');
$hasWorkload    = table_exists($conn,'faculty_workload_planning');
$hasSigTable    = table_exists($conn,'timetable_signatures');
$hasWefDiv      = column_exists($conn,'divisions','wef_date');
$hasTTSettings  = table_exists($conn,'timetable_settings');
$hasCTAssign    = table_exists($conn,'class_teacher_assignments');

// ── OVERVIEW STATS ──────────────────────────────────────────────────────────
$total_divisions  = (int)q1($conn,"SELECT COUNT(*) FROM divisions",0);
$total_entries    = (int)q1($conn,"SELECT COUNT(*) FROM timetable_entries WHERE academic_year='$aySafe' AND semester='$semSafe'",0);
$total_faculties  = (int)q1($conn,"SELECT COUNT(*) FROM faculties WHERE $badFaculty",0);
$total_rooms      = (int)q1($conn,"SELECT COUNT(*) FROM classrooms WHERE room_code REGEXP '^[NS][0-9]{3,4}$'",0);
$total_labs       = $hasLabDetails ? (int)q1($conn,"SELECT COUNT(*) FROM lab_details",0) : 0;
$total_subjects   = (int)q1($conn,"SELECT COUNT(DISTINCT subject_code) FROM subjects",0);

$div_with_entries = (int)q1($conn,"SELECT COUNT(DISTINCT division_id) FROM timetable_entries WHERE academic_year='$aySafe' AND semester='$semSafe'",0);
$div_no_entries   = $total_divisions - $div_with_entries;

// ── DIVISION FILL RATE ───────────────────────────────────────────────────────
$max_slots_per_div = count($days) * count($teaching_slots); // 6*7=42

$div_fill_res = @$conn->query("
    SELECT d.division_name,
           " . ($hasDivDept ? "d.department," : "'' AS department,") . "
           " . ($hasDivYear ? "d.year_name," : "'' AS year_name,") . "
           COUNT(t.id) AS entry_count
    FROM divisions d
    LEFT JOIN timetable_entries t ON t.division_id=d.id
        AND t.academic_year='$aySafe' AND t.semester='$semSafe'
    GROUP BY d.id, d.division_name
    ORDER BY entry_count DESC, d.division_name ASC
    LIMIT 50
");
$div_fill_rows = [];
if($div_fill_res) while($r=$div_fill_res->fetch_assoc()) $div_fill_rows[] = $r;

// ── FACULTY LOAD ─────────────────────────────────────────────────────────────
$fac_load_res = @$conn->query("
    SELECT f.faculty_code, f.faculty_name,
           " . ($hasFacultyUid ? "f.faculty_uid," : "f.id AS faculty_uid,") . "
           COUNT(t.id) AS total_slots,
           COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS unique_slots
    FROM faculties f
    LEFT JOIN timetable_entries t ON t.faculty_id=f.id
        AND t.academic_year='$aySafe' AND t.semester='$semSafe'
    WHERE $badFaculty
    GROUP BY f.id, f.faculty_code, f.faculty_name
    ORDER BY unique_slots DESC, f.faculty_name ASC
    LIMIT 60
");
$fac_load_rows = [];
if($fac_load_res) while($r=$fac_load_res->fetch_assoc()) $fac_load_rows[] = $r;

// ── FACULTY TYPE BREAKDOWN ───────────────────────────────────────────────────
$fac_type_res = $hasSubjectType ? @$conn->query("
    SELECT f.faculty_code, f.faculty_name,
           SUM(CASE WHEN UPPER(s.subject_type)='PRACTICAL' OR s.subject_code REGEXP '(LAB|DSL|DMSL|PAIL|EEL|PLL)' THEN 1 ELSE 0 END) AS practical_slots,
           SUM(CASE WHEN UPPER(s.subject_type) NOT IN ('PRACTICAL') AND s.subject_code NOT REGEXP '(LAB|DSL|DMSL|PAIL|EEL|PLL|PBL|PAL)' THEN 1 ELSE 0 END) AS theory_slots,
           SUM(CASE WHEN s.subject_code REGEXP '(PBL|PAL|MAJOR|MINI|PROJECT)' THEN 1 ELSE 0 END) AS project_slots
    FROM faculties f
    JOIN timetable_entries t ON t.faculty_id=f.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
    JOIN subjects s ON s.id=t.subject_id
    WHERE $badFaculty
    GROUP BY f.id, f.faculty_code, f.faculty_name
    HAVING theory_slots+practical_slots+project_slots > 0
    ORDER BY f.faculty_name ASC
    LIMIT 50
") : null;
$fac_type_rows = [];
if($fac_type_res) while($r=$fac_type_res->fetch_assoc()) $fac_type_rows[] = $r;

// ── FACULTY OVERLOAD DAYS ────────────────────────────────────────────────────
$fac_overload_res = @$conn->query("
    SELECT f.faculty_code, f.faculty_name, t.day_name,
           COUNT(DISTINCT t.time_slot) AS slots_in_day
    FROM faculties f
    JOIN timetable_entries t ON t.faculty_id=f.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
    WHERE $badFaculty AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    GROUP BY f.id, f.faculty_code, f.faculty_name, t.day_name
    HAVING slots_in_day >= 5
    ORDER BY slots_in_day DESC, f.faculty_name ASC
    LIMIT 40
");
$fac_overload_rows = [];
if($fac_overload_res) while($r=$fac_overload_res->fetch_assoc()) $fac_overload_rows[] = $r;

// ── CONFLICT: FACULTY DOUBLE BOOKING ────────────────────────────────────────
$faculty_conflicts_res = @$conn->query("
    SELECT f.faculty_code, f.faculty_name, t.day_name, t.time_slot,
           GROUP_CONCAT(DISTINCT d.division_name ORDER BY d.division_name SEPARATOR ', ') AS divisions,
           COUNT(DISTINCT t.division_id) AS div_count
    FROM timetable_entries t
    JOIN faculties f ON f.id=t.faculty_id
    JOIN divisions d ON d.id=t.division_id
    WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      AND t.faculty_id IS NOT NULL
      AND $badFaculty
      AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    GROUP BY t.faculty_id, t.day_name, t.time_slot
    HAVING div_count > 1
    ORDER BY div_count DESC, t.day_name, t.time_slot
    LIMIT 50
");
$faculty_conflicts = [];
if($faculty_conflicts_res) while($r=$faculty_conflicts_res->fetch_assoc()) $faculty_conflicts[] = $r;

// ── CONFLICT: CLASSROOM DOUBLE BOOKING ──────────────────────────────────────
$room_conflicts_res = @$conn->query("
    SELECT c.room_code, t.day_name, t.time_slot,
           GROUP_CONCAT(DISTINCT d.division_name ORDER BY d.division_name SEPARATOR ', ') AS divisions,
           GROUP_CONCAT(DISTINCT f.faculty_code ORDER BY f.faculty_code SEPARATOR ', ') AS faculties,
           COUNT(DISTINCT t.division_id) AS div_count
    FROM timetable_entries t
    JOIN classrooms c ON c.id=t.classroom_id
    JOIN divisions d ON d.id=t.division_id
    LEFT JOIN faculties f ON f.id=t.faculty_id
    WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      AND t.classroom_id IS NOT NULL
      AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    GROUP BY t.classroom_id, t.day_name, t.time_slot
    HAVING div_count > 1
    ORDER BY div_count DESC, t.day_name, t.time_slot
    LIMIT 50
");
$room_conflicts = [];
if($room_conflicts_res) while($r=$room_conflicts_res->fetch_assoc()) $room_conflicts[] = $r;

// ── ROOM UTILIZATION ─────────────────────────────────────────────────────────
$util_days  = ["Monday","Tuesday","Wednesday","Thursday","Friday"];
$util_slots = ["08:45-09:40","09:40-10:35","10:50-11:45","11:45-12:40","01:40-02:35","02:35-03:30"];
$total_room_slots = count($util_days) * count($util_slots); // 30
$utilDayList  = "'".implode("','", array_map([$conn,'real_escape_string'], $util_days))."'";
$utilSlotList = "'".implode("','", array_map([$conn,'real_escape_string'], $util_slots))."'";

$room_util_res = @$conn->query("
    SELECT c.room_code,
           COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS used_slots
    FROM classrooms c
    LEFT JOIN timetable_entries t ON t.classroom_id=c.id
        AND t.academic_year='$aySafe' AND t.semester='$semSafe'
        AND t.day_name IN ($utilDayList)
        AND t.time_slot IN ($utilSlotList)
    WHERE c.room_code REGEXP '^[NS][0-9]{3,4}$'
    GROUP BY c.id, c.room_code
    ORDER BY used_slots DESC
");
$room_util_rows = [];
if($room_util_res) while($r=$room_util_res->fetch_assoc()) $room_util_rows[] = $r;

// ── SLOT HEATMAP DATA ────────────────────────────────────────────────────────
$heatmap_data = [];
foreach($days as $d) foreach($teaching_slots as $s) $heatmap_data[$d][$s] = 0;
$heatmap_res = @$conn->query("
    SELECT day_name, time_slot, COUNT(DISTINCT division_id) AS cnt
    FROM timetable_entries
    WHERE academic_year='$aySafe' AND semester='$semSafe'
      AND time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    GROUP BY day_name, time_slot
");
if($heatmap_res) while($r=$heatmap_res->fetch_assoc()){
    if(isset($heatmap_data[$r['day_name']][$r['time_slot']])) $heatmap_data[$r['day_name']][$r['time_slot']] = (int)$r['cnt'];
}
$heatmap_max = 1;
foreach($heatmap_data as $d => $sv) foreach($sv as $s => $cnt) if($cnt > $heatmap_max) $heatmap_max = $cnt;

// ── SUBJECT FREQUENCY ────────────────────────────────────────────────────────
$subj_freq_res = @$conn->query("
    SELECT s.subject_code, s.subject_name,
           COUNT(t.id) AS slot_count,
           COUNT(DISTINCT t.division_id) AS div_count,
           COUNT(DISTINCT t.faculty_id) AS fac_count
    FROM timetable_entries t
    JOIN subjects s ON s.id=t.subject_id
    WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      AND s.subject_code NOT IN ('A','B','ONLINE','NULL')
    GROUP BY s.id, s.subject_code, s.subject_name
    ORDER BY slot_count DESC
    LIMIT 30
");
$subj_freq_rows = [];
if($subj_freq_res) while($r=$subj_freq_res->fetch_assoc()) $subj_freq_rows[] = $r;

// ── ORPHAN ENTRIES (no faculty / no room) ───────────────────────────────────
$orphan_no_fac_res = @$conn->query("
    SELECT d.division_name, t.day_name, t.time_slot, s.subject_code, s.subject_name
    FROM timetable_entries t
    JOIN divisions d ON d.id=t.division_id
    JOIN subjects s ON s.id=t.subject_id
    WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      AND t.faculty_id IS NULL
      AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    ORDER BY d.division_name, t.day_name, t.time_slot
    LIMIT 60
");
$orphan_no_fac = [];
if($orphan_no_fac_res) while($r=$orphan_no_fac_res->fetch_assoc()) $orphan_no_fac[] = $r;

$orphan_no_room_res = @$conn->query("
    SELECT d.division_name, t.day_name, t.time_slot, s.subject_code, s.subject_name
    FROM timetable_entries t
    JOIN divisions d ON d.id=t.division_id
    JOIN subjects s ON s.id=t.subject_id
    WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      AND t.classroom_id IS NULL
      AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
      AND s.subject_code NOT IN ('ONLINE','MOOC','NPTEL','LIBRARY','MENTOR')
    ORDER BY d.division_name, t.day_name, t.time_slot
    LIMIT 60
");
$orphan_no_room = [];
if($orphan_no_room_res) while($r=$orphan_no_room_res->fetch_assoc()) $orphan_no_room[] = $r;

// ── METADATA COMPLETENESS ────────────────────────────────────────────────────
$div_missing_ct = 0;
if($hasCTAssign){
    $div_missing_ct = (int)q1($conn,"
        SELECT COUNT(*) FROM divisions d
        LEFT JOIN class_teacher_assignments cta
            ON cta.division_id=d.id AND cta.academic_year='$aySafe' AND cta.semester='$semSafe'
        WHERE cta.id IS NULL OR cta.class_teacher IS NULL OR cta.class_teacher=''
    ",0);
}

$div_missing_wef = 0;
if($hasTTSettings){
    $div_missing_wef = (int)q1($conn,"
        SELECT COUNT(*) FROM divisions d
        LEFT JOIN timetable_settings ts
            ON ts.division_id=d.id AND ts.academic_year='$aySafe' AND ts.semester='$semSafe'
        WHERE ts.id IS NULL OR ts.wef_date IS NULL OR ts.wef_date=''
    ",0);
}

$div_missing_prepared = 0;
if($hasTTSettings){
    $div_missing_prepared = (int)q1($conn,"
        SELECT COUNT(*) FROM divisions d
        LEFT JOIN timetable_settings ts
            ON ts.division_id=d.id AND ts.academic_year='$aySafe' AND ts.semester='$semSafe'
        WHERE ts.id IS NULL OR ts.prepared_by IS NULL OR ts.prepared_by=''
    ",0);
}

$sig_count = $hasSigTable ? (int)q1($conn,"SELECT COUNT(*) FROM timetable_signatures WHERE academic_year='$aySafe' AND semester='$semSafe'",0) : 0;

// ── WORKLOAD PLANNED vs ACTUAL ───────────────────────────────────────────────
$wl_vs_actual = [];
if($hasWorkload){
    $wl_res = @$conn->query("
        SELECT wp.faculty_abbrev, wp.faculty_name, SUM(wp.total_hours) AS planned_hrs
        FROM faculty_workload_planning wp
        WHERE wp.academic_year='$aySafe' AND wp.semester='$semSafe'
          AND wp.faculty_abbrev IS NOT NULL AND wp.faculty_abbrev!=''
        GROUP BY wp.faculty_abbrev, wp.faculty_name
    ");
    $planned_map = [];
    if($wl_res) while($r=$wl_res->fetch_assoc()) $planned_map[strtoupper(trim($r['faculty_abbrev']))] = $r;

    $actual_res = @$conn->query("
        SELECT f.faculty_code, f.faculty_name,
               COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS actual_slots
        FROM faculties f
        JOIN timetable_entries t ON t.faculty_id=f.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
        WHERE $badFaculty AND t.time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
        GROUP BY f.id, f.faculty_code, f.faculty_name
        ORDER BY f.faculty_name ASC
    ");
    if($actual_res){
        while($r=$actual_res->fetch_assoc()){
            $code = strtoupper(trim($r['faculty_code']));
            $planned = isset($planned_map[$code]) ? (float)$planned_map[$code]['planned_hrs'] : 0;
            $actual  = (int)$r['actual_slots'];
            $diff    = $actual - $planned;
            $wl_vs_actual[] = [
                'faculty_code' => $r['faculty_code'],
                'faculty_name' => $r['faculty_name'],
                'planned'      => $planned,
                'actual'       => $actual,
                'diff'         => $diff
            ];
        }
    }
}

// ── DEPT-WISE SUMMARY ────────────────────────────────────────────────────────
$dept_summary = [];
if($hasDivDept){
    $dept_res = @$conn->query("
        SELECT d.department, COUNT(DISTINCT d.id) AS div_count, COUNT(t.id) AS entry_count
        FROM divisions d
        LEFT JOIN timetable_entries t ON t.division_id=d.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
        WHERE d.department IS NOT NULL AND d.department!=''
        GROUP BY d.department
        ORDER BY entry_count DESC
    ");
    if($dept_res) while($r=$dept_res->fetch_assoc()) $dept_summary[] = $r;
}

// ── YEAR-WISE SUMMARY ────────────────────────────────────────────────────────
$year_summary = [];
if($hasDivYear){
    $yr_res = @$conn->query("
        SELECT d.year_name, COUNT(DISTINCT d.id) AS div_count, COUNT(t.id) AS entry_count
        FROM divisions d
        LEFT JOIN timetable_entries t ON t.division_id=d.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
        WHERE d.year_name IS NOT NULL AND d.year_name!=''
        GROUP BY d.year_name
        ORDER BY FIELD(d.year_name,'FY','SY','TY','LY')
    ");
    if($yr_res) while($r=$yr_res->fetch_assoc()) $year_summary[] = $r;
}

// ── SUBJECTS NOT IN TT ───────────────────────────────────────────────────────
$subjects_not_used_res = @$conn->query("
    SELECT s.subject_code, s.subject_name
    FROM subjects s
    WHERE (s.academic_year='$aySafe' OR s.academic_year IS NULL)
      AND (s.semester='$semSafe' OR s.semester IS NULL)
      AND s.subject_code NOT IN (
          SELECT DISTINCT sub.subject_code FROM timetable_entries t
          JOIN subjects sub ON sub.id=t.subject_id
          WHERE t.academic_year='$aySafe' AND t.semester='$semSafe'
      )
      AND s.subject_code NOT IN ('A','B','NULL','ONLINE','NONE','NAN')
      AND s.subject_code IS NOT NULL AND TRIM(s.subject_code)!=''
    ORDER BY s.subject_code
    LIMIT 50
");
$subjects_not_used = [];
if($subjects_not_used_res) while($r=$subjects_not_used_res->fetch_assoc()) $subjects_not_used[] = $r;

// ── FACULTY WITH NO ASSIGNMENT ───────────────────────────────────────────────
$fac_unassigned_res = @$conn->query("
    SELECT f.faculty_code, f.faculty_name
    FROM faculties f
    WHERE $badFaculty
      AND f.id NOT IN (
          SELECT DISTINCT faculty_id FROM timetable_entries
          WHERE academic_year='$aySafe' AND semester='$semSafe' AND faculty_id IS NOT NULL
      )
    ORDER BY f.faculty_name ASC
    LIMIT 40
");
$fac_unassigned = [];
if($fac_unassigned_res) while($r=$fac_unassigned_res->fetch_assoc()) $fac_unassigned[] = $r;

// ── DAY-WISE LOAD DISTRIBUTION ───────────────────────────────────────────────
$day_load = [];
foreach($days as $d) $day_load[$d] = 0;
$dayload_res = @$conn->query("
    SELECT day_name, COUNT(id) AS cnt
    FROM timetable_entries
    WHERE academic_year='$aySafe' AND semester='$semSafe'
      AND time_slot NOT IN ('10:35-10:50','12:40-01:40','03:30-03:40')
    GROUP BY day_name
");
if($dayload_res) while($r=$dayload_res->fetch_assoc()) if(isset($day_load[$r['day_name']])) $day_load[$r['day_name']] = (int)$r['cnt'];
$max_day_load = max(1, ...array_values($day_load));

// total conflict count
$total_conflicts = count($faculty_conflicts) + count($room_conflicts);

// ── SPECIALIZATION SUMMARY ───────────────────────────────────────────────────
$spec_summary = [];
if($hasDivSpec){
    $spec_res = @$conn->query("
        SELECT d.specialization, COUNT(DISTINCT d.id) AS div_count, COUNT(t.id) AS entry_count
        FROM divisions d
        LEFT JOIN timetable_entries t ON t.division_id=d.id AND t.academic_year='$aySafe' AND t.semester='$semSafe'
        WHERE d.specialization IS NOT NULL AND d.specialization!=''
        GROUP BY d.specialization
        ORDER BY entry_count DESC
        LIMIT 15
    ");
    if($spec_res) while($r=$spec_res->fetch_assoc()) $spec_summary[] = $r;
}

$tabs = [
    'overview'   => ['icon'=>'📊','label'=>'Overview'],
    'faculty'    => ['icon'=>'👨‍🏫','label'=>'Faculty Analytics'],
    'rooms'      => ['icon'=>'🏫','label'=>'Room Utilization'],
    'conflicts'  => ['icon'=>'⚠️','label'=>'Conflict Detection'],
    'completeness'=>['icon'=>'✅','label'=>'Completeness'],
    'subjects'   => ['icon'=>'📘','label'=>'Subject Analysis'],
    'workload'   => ['icon'=>'📈','label'=>'Workload vs Actual'],
    'heatmap'    => ['icon'=>'🔥','label'=>'Slot Heatmap'],
];

$ay_options   = ['2025-26','2026-27'];
$sem_options  = ['Odd','Even'];

// JSON for charts
$div_fill_json   = json_encode(array_map(fn($r)=>['name'=>$r['division_name'],'count'=>(int)$r['entry_count']], array_slice($div_fill_rows,0,20)));
$fac_load_json   = json_encode(array_map(fn($r)=>['name'=>$r['faculty_code'],'slots'=>(int)$r['unique_slots']], array_slice($fac_load_rows,0,20)));
$room_util_json  = json_encode(array_map(fn($r)=>['room'=>$r['room_code'],'used'=>(int)$r['used_slots'],'pct'=>round(((int)$r['used_slots']/$total_room_slots)*100,1)], array_slice($room_util_rows,0,20)));
$dept_json       = json_encode(array_map(fn($r)=>['dept'=>$r['department'],'entries'=>(int)$r['entry_count']], $dept_summary));
$year_json       = json_encode(array_map(fn($r)=>['year'=>$r['year_name'],'entries'=>(int)$r['entry_count']], $year_summary));
$day_json        = json_encode(array_map(fn($d)=>['day'=>substr($d,0,3),'cnt'=>$day_load[$d]], $days));
$subj_json       = json_encode(array_map(fn($r)=>['code'=>$r['subject_code'],'slots'=>(int)$r['slot_count']], array_slice($subj_freq_rows,0,15)));
$heatmap_json    = json_encode($heatmap_data);
$wl_json         = json_encode(array_slice(array_map(fn($r)=>['code'=>$r['faculty_code'],'planned'=>$r['planned'],'actual'=>$r['actual']], $wl_vs_actual),0,20));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — MIT ADT Timetable Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Cinzel:wght@700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{height:100%;scroll-behavior:smooth}
body{font-family:"Poppins","Segoe UI",Arial,sans-serif;background:#f0ebfa;color:#1a0533;font-size:14px;min-height:100%}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:200;background:linear-gradient(90deg,#3d0f6b 0%,#5a1f8c 55%,#7b2fb5 100%);height:56px;display:flex;align-items:center;justify-content:space-between;padding:0 24px;box-shadow:0 3px 18px rgba(90,31,140,.45);border-bottom:3px solid #C1345C}
.brand{display:flex;align-items:center;gap:10px}
.logo-img{height:50px;width:auto;background:#fff;padding:3px;object-fit:contain}
.brand-text .t1{font-family:'Cinzel',serif;font-size:16px;font-weight:800;color:#fff;line-height:1.1}
.brand-text .t2{font-size:12px;color:#dfc8ff;font-weight:600}
.topbar-right{display:flex;align-items:center;gap:12px}
.back-btn{background:#ffffff22;color:#fff;border:1.5px solid #ffffff44;padding:6px 16px;border-radius:8px;font-size:12.5px;font-weight:700;text-decoration:none;transition:.15s}
.back-btn:hover{background:#ffffff33}
.ay-badge{background:#C1345C;color:#fff;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:800;letter-spacing:.3px}

/* ── AY/SEM FILTER BAR ── */
.filter-bar{background:#fff;border-bottom:2px solid #e0cff5;padding:10px 24px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.filter-bar label{font-size:12px;font-weight:800;color:#5a1f8c}
.filter-bar select{padding:6px 10px;border:1.5px solid #c9a0e0;border-radius:8px;font-size:12.5px;font-family:inherit;color:#1a0533;background:#fff;height:32px}
.filter-bar select:focus{outline:none;border-color:#7b2fb5}
.filter-bar button{padding:6px 16px;border-radius:8px;border:none;background:#7b2fb5;color:#fff;font-weight:700;font-size:12.5px;cursor:pointer;height:32px;font-family:inherit}
.filter-bar button:hover{background:#5a1f8c}

/* ── TAB NAV ── */
.tab-nav{background:#fff;border-bottom:2px solid #e0cff5;padding:0 24px;display:flex;gap:0;overflow-x:auto}
.tab-btn{display:flex;align-items:center;gap:6px;padding:12px 18px;font-size:12.5px;font-weight:700;color:#6b4a86;border:none;border-bottom:3px solid transparent;background:none;cursor:pointer;white-space:nowrap;transition:.15s;font-family:inherit}
.tab-btn:hover{color:#5a1f8c;background:#f5effe}
.tab-btn.active{color:#3d0f6b;border-bottom-color:#7b2fb5;background:#f5effe}
.tab-btn .badge{background:#C1345C;color:#fff;border-radius:999px;padding:1px 7px;font-size:10px;font-weight:900;margin-left:4px}

/* ── MAIN ── */
.main{padding:22px 24px 40px;max-width:1600px;margin:0 auto}

/* ── SECTION HEADING ── */
.section-head{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.section-head h2{font-size:19px;font-weight:900;color:#3d0f6b}
.section-head p{font-size:12px;color:#7b5cb0;font-weight:600;margin-top:3px}
.section-icon{font-size:24px;width:42px;height:42px;background:#ede0f7;border-radius:10px;display:flex;align-items:center;justify-content:center}

/* ── STAT CARDS ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-bottom:22px}
.stat-card{background:#fff;border-radius:14px;padding:18px 16px;text-align:center;box-shadow:0 3px 14px rgba(90,31,140,.09);border-top:5px solid #7b2fb5;position:relative;overflow:hidden}
.stat-card::after{content:'';position:absolute;top:-18px;right:-18px;width:70px;height:70px;border-radius:50%;opacity:.07;background:#7b2fb5}
.stat-card.red{border-color:#C1345C}.stat-card.red::after{background:#C1345C}
.stat-card.teal{border-color:#0b8f7a}.stat-card.teal::after{background:#0b8f7a}
.stat-card.orange{border-color:#d97706}.stat-card.orange::after{background:#d97706}
.stat-card.indigo{border-color:#4f46e5}.stat-card.indigo::after{background:#4f46e5}
.stat-card.green{border-color:#15803d}.stat-card.green::after{background:#15803d}
.stat-card.pink{border-color:#db2777}.stat-card.pink::after{background:#db2777}
.stat-num{font-size:30px;font-weight:900;color:#3d0f6b;line-height:1}
.stat-label{font-size:11px;color:#8a6ba8;font-weight:700;margin-top:6px;line-height:1.3}
.stat-sub{font-size:10px;color:#aaa;margin-top:2px}

/* ── PANEL CARD ── */
.panel{background:#fff;border-radius:14px;padding:18px;box-shadow:0 3px 14px rgba(90,31,140,.09);border:1px solid #e8d5f5;margin-bottom:18px}
.panel-title{font-size:14px;font-weight:900;color:#3d0f6b;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.panel-title span.sub{font-size:11px;color:#9b6bc5;font-weight:600;margin-left:auto}

/* ── GRID LAYOUTS ── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
.grid-auto{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
@media(max-width:900px){.grid-2,.grid-3{grid-template-columns:1fr}}

/* ── CHART CONTAINERS ── */
.chart-wrap{position:relative;height:260px;width:100%}
.chart-wrap.tall{height:340px}
.chart-wrap.sm{height:200px}
.chart-wrap.xlg{height:420px}

/* ── HEATMAP ── */
.heatmap-table{width:100%;border-collapse:collapse;font-size:11px}
.heatmap-table th{background:#ede0f7;color:#3d0f6b;font-weight:900;padding:7px 5px;text-align:center;font-size:11px;border:1px solid #d4b8ea}
.heatmap-table td{border:1px solid #ddd;text-align:center;padding:5px 3px;font-size:11px;font-weight:700;min-width:70px;transition:.2s}
.hm-day{background:#f9f4ff;font-weight:900;color:#3d0f6b;text-align:left;padding-left:10px}

/* ── TABLES ── */
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table th{background:#ede0f7;color:#3d0f6b;font-weight:900;padding:8px 10px;text-align:left;border-bottom:2px solid #c9a0e0;white-space:nowrap}
.data-table td{padding:8px 10px;border-bottom:1px solid #f0e8f8;vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:#fdf5ff}
.data-table .num{text-align:right;font-weight:800;font-family:monospace}
.data-table .center{text-align:center}

/* ── CONFLICT BADGE ── */
.conflict-row td{background:#fff8f8}
.conflict-row:hover td{background:#ffefef!important}
.badge-danger{background:#fee2e2;color:#dc2626;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900}
.badge-warn{background:#fef3c7;color:#d97706;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900}
.badge-ok{background:#dcfce7;color:#15803d;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900}
.badge-info{background:#ede0f7;color:#5a1f8c;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900}
.badge-blue{background:#dbeafe;color:#1d4ed8;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:900}

/* ── PROGRESS BAR ── */
.prog-bg{height:10px;background:#e8d9f4;border-radius:999px;overflow:hidden;min-width:80px}
.prog-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#5a1f8c,#9b59c5)}
.prog-fill.red{background:linear-gradient(90deg,#C1345C,#e87a97)}
.prog-fill.green{background:linear-gradient(90deg,#15803d,#4ade80)}
.prog-fill.orange{background:linear-gradient(90deg,#d97706,#fbbf24)}

/* ── CONFLICT ALERT BOX ── */
.alert-box{border-radius:12px;padding:14px 16px;margin-bottom:12px;font-size:13px;font-weight:700;display:flex;gap:10px;align-items:flex-start}
.alert-box.danger{background:#fee2e2;border-left:5px solid #dc2626;color:#7f1d1d}
.alert-box.success{background:#dcfce7;border-left:5px solid #15803d;color:#14532d}
.alert-box.warn{background:#fef3c7;border-left:5px solid #d97706;color:#78350f}
.alert-box.info{background:#ede0f7;border-left:5px solid #7b2fb5;color:#3d0f6b}
.alert-icon{font-size:18px;flex-shrink:0}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:36px 20px;color:#9b6bc5;font-weight:700;font-size:13px}
.empty-state .ei{font-size:36px;margin-bottom:8px}

/* ── WORKLOAD DELTA ── */
.delta-pos{color:#dc2626;font-weight:900}
.delta-neg{color:#15803d;font-weight:900}
.delta-zero{color:#888;font-weight:700}

/* ── SCROLLABLE TABLE WRAP ── */
.table-scroll{overflow-x:auto;overflow-y:auto;max-height:420px;border-radius:10px;border:1px solid #e0cff5}

/* ── TAB PANEL HIDE/SHOW ── */
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── SPEC TAG ── */
.spec-tag{display:inline-block;background:#f0e8fa;color:#5a1f8c;border:1px solid #d4b8ea;border-radius:5px;padding:1px 7px;font-size:11px;font-weight:800;margin:1px}

/* ── PRINT HIDE ── */
@media print{.topbar,.filter-bar,.tab-nav,.back-btn{display:none!important}.tab-panel{display:block!important}.main{padding:0}}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <img src="assets/mitadt_logo.jpg" class="logo-img" onerror="this.style.display='none'">
        <div class="brand-text">
            <div class="t1">MIT ADT University — Analytics</div>
            <div class="t2">School Of Computing · Smart Timetable Analytics Dashboard</div>
        </div>
    </div>
    <div class="topbar-right">
        <span class="ay-badge"><?= e($selected_year) ?> · <?= e($selected_semester) ?></span>
        <a class="back-btn" href="index.php?view=<?= e($context=='admin'?'admin_dashboard':'dashboard') ?>&academic_year=<?= urlencode($selected_year) ?>&semester=<?= urlencode($selected_semester) ?>">← Back to Portal</a>
    </div>
</div>

<!-- FILTER BAR -->
<div class="filter-bar">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="context" value="<?= e($context) ?>">
        <input type="hidden" name="tab" value="<?= e($active_tab) ?>">
        <label>Academic Year</label>
        <select name="academic_year">
            <?php foreach($ay_options as $ay){ ?><option value="<?= e($ay) ?>" <?= $selected_year==$ay?'selected':'' ?>><?= e($ay) ?></option><?php } ?>
        </select>
        <label>Semester</label>
        <select name="semester">
            <?php foreach($sem_options as $s){ ?><option value="<?= e($s) ?>" <?= $selected_semester==$s?'selected':'' ?>><?= e($s) ?></option><?php } ?>
        </select>
        <button type="submit">Apply Filters</button>
    </form>
    <div style="margin-left:auto;font-size:12px;color:#7b5cb0;font-weight:700">
        Data for: <strong><?= e($selected_year) ?> · <?= e($selected_semester) ?> Semester</strong>
    </div>
</div>

<!-- TAB NAV -->
<div class="tab-nav">
    <?php foreach($tabs as $key=>$t){ ?>
    <button class="tab-btn <?= $active_tab==$key?'active':'' ?>" onclick="switchTab('<?= $key ?>')">
        <?= $t['icon'] ?> <?= $t['label'] ?>
        <?php if($key==='conflicts' && $total_conflicts > 0){ ?><span class="badge"><?= $total_conflicts ?></span><?php } ?>
        <?php if($key==='completeness' && ($div_missing_ct+$div_missing_wef+count($orphan_no_fac)+count($orphan_no_room)) > 0){ ?><span class="badge"><?= $div_missing_ct+$div_missing_wef ?></span><?php } ?>
    </button>
    <?php } ?>
</div>

<div class="main">

<!-- ══════════ TAB: OVERVIEW ══════════ -->
<div class="tab-panel <?= $active_tab=='overview'?'active':'' ?>" id="tab-overview">

    <div class="section-head">
        <div class="section-icon">📊</div>
        <div><h2>Timetable Overview</h2><p>High-level summary for <?= e($selected_year) ?> — <?= e($selected_semester) ?> Semester</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $total_divisions ?></div><div class="stat-label">Total Divisions</div></div>
        <div class="stat-card indigo"><div class="stat-num"><?= $div_with_entries ?></div><div class="stat-label">Divisions with Timetable</div><div class="stat-sub"><?= $div_no_entries ?> have no entries</div></div>
        <div class="stat-card"><div class="stat-num"><?= number_format($total_entries) ?></div><div class="stat-label">Total TT Entries</div><div class="stat-sub">This AY &amp; Sem</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= $total_faculties ?></div><div class="stat-label">Faculty Members</div><div class="stat-sub"><?= count($fac_unassigned) ?> unassigned</div></div>
        <div class="stat-card orange"><div class="stat-num"><?= $total_rooms ?></div><div class="stat-label">Classrooms</div></div>
        <div class="stat-card green"><div class="stat-num"><?= $total_labs ?></div><div class="stat-label">Labs</div></div>
        <div class="stat-card pink"><div class="stat-num"><?= $total_subjects ?></div><div class="stat-label">Unique Subject Codes</div></div>
        <div class="stat-card red"><div class="stat-num"><?= $total_conflicts ?></div><div class="stat-label">Scheduling Conflicts</div><div class="stat-sub">Faculty + Room double-bookings</div></div>
    </div>

    <?php if($total_conflicts > 0){ ?>
    <div class="alert-box danger"><div class="alert-icon">🚨</div><div><?= count($faculty_conflicts) ?> faculty double-booking conflict(s) and <?= count($room_conflicts) ?> classroom double-booking conflict(s) detected. Go to the <strong>Conflict Detection</strong> tab for details.</div></div>
    <?php } else { ?>
    <div class="alert-box success"><div class="alert-icon">✅</div><div>No scheduling conflicts detected for <?= e($selected_year) ?> <?= e($selected_semester) ?> semester. All faculty and room assignments appear clean.</div></div>
    <?php } ?>

    <div class="grid-2">
        <!-- Department-wise entries -->
        <div class="panel">
            <div class="panel-title">🏫 Department-wise Entry Distribution <span class="sub"><?= e($selected_year) ?> · <?= e($selected_semester) ?></span></div>
            <?php if(!empty($dept_summary)){ ?>
            <div class="chart-wrap"><canvas id="deptChart"></canvas></div>
            <?php } else { ?>
            <div class="empty-state"><div class="ei">🏫</div>No department data found</div>
            <?php } ?>
        </div>

        <!-- Year-wise entries -->
        <div class="panel">
            <div class="panel-title">🎓 Year-wise Entry Distribution</div>
            <?php if(!empty($year_summary)){ ?>
            <div class="chart-wrap"><canvas id="yearChart"></canvas></div>
            <?php } else { ?>
            <div class="empty-state"><div class="ei">📅</div>No year data found</div>
            <?php } ?>
        </div>
    </div>

    <div class="grid-2">
        <!-- Day-wise load -->
        <div class="panel">
            <div class="panel-title">📅 Day-wise Teaching Load Distribution</div>
            <div class="chart-wrap"><canvas id="dayChart"></canvas></div>
        </div>

        <!-- Specialization summary -->
        <div class="panel">
            <div class="panel-title">🔬 Specialization-wise Distribution</div>
            <?php if(!empty($spec_summary)){ ?>
            <div class="chart-wrap"><canvas id="specChart"></canvas></div>
            <?php } else { ?>
            <div class="empty-state"><div class="ei">🔬</div>No specialization data available</div>
            <?php } ?>
        </div>
    </div>

    <!-- Division fill rate table -->
    <div class="panel">
        <div class="panel-title">📋 Division-wise Fill Rate <span class="sub">Entries per division this AY/Sem</span></div>
        <?php if(!empty($div_fill_rows)){ ?>
        <div class="chart-wrap tall"><canvas id="divFillChart"></canvas></div>
        <div class="table-scroll" style="margin-top:16px;max-height:300px">
            <table class="data-table">
                <tr><th>Division</th><?php if($hasDivDept){ ?><th>Dept</th><?php } ?><?php if($hasDivYear){ ?><th>Year</th><?php } ?><th>Entries</th><th style="min-width:150px">Fill Rate</th></tr>
                <?php foreach($div_fill_rows as $r){
                    $pct = min(100, round(($r['entry_count']/$max_slots_per_div)*100,0));
                    $color = $pct > 70 ? '' : ($pct > 30 ? 'orange' : 'red');
                ?>
                <tr>
                    <td><strong><?= e($r['division_name']) ?></strong></td>
                    <?php if($hasDivDept){ ?><td><span class="badge-info"><?= e($r['department']) ?></span></td><?php } ?>
                    <?php if($hasDivYear){ ?><td><?= e($r['year_name']) ?></td><?php } ?>
                    <td class="num"><?= e($r['entry_count']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div class="prog-bg" style="flex:1"><div class="prog-fill <?= $color ?>" style="width:<?= $pct ?>%"></div></div>
                            <span style="font-size:11px;font-weight:800;color:#555;min-width:35px"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } else { ?>
        <div class="empty-state"><div class="ei">📋</div>No timetable entries found for selected filters</div>
        <?php } ?>
    </div>

</div><!-- /overview -->


<!-- ══════════ TAB: FACULTY ANALYTICS ══════════ -->
<div class="tab-panel <?= $active_tab=='faculty'?'active':'' ?>" id="tab-faculty">

    <div class="section-head">
        <div class="section-icon">👨‍🏫</div>
        <div><h2>Faculty Analytics</h2><p>Workload, coverage and day-wise distribution of all faculty</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $total_faculties ?></div><div class="stat-label">Total Faculty</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= $total_faculties - count($fac_unassigned) ?></div><div class="stat-label">Assigned Faculty</div></div>
        <div class="stat-card red"><div class="stat-num"><?= count($fac_unassigned) ?></div><div class="stat-label">Unassigned Faculty</div><div class="stat-sub">No TT entries this AY/Sem</div></div>
        <div class="stat-card orange"><div class="stat-num"><?= count($fac_overload_rows) ?></div><div class="stat-label">Overloaded Day Instances</div><div class="stat-sub">5+ slots in a single day</div></div>
    </div>

    <!-- Unassigned faculty alert -->
    <?php if(!empty($fac_unassigned)){ ?>
    <div class="alert-box warn"><div class="alert-icon">⚠️</div><div><?= count($fac_unassigned) ?> faculty member(s) have <strong>no timetable assignments</strong> for <?= e($selected_year) ?> <?= e($selected_semester) ?>: <br><span style="margin-top:5px;display:inline-block"><?= implode(', ', array_map(fn($f)=>'<span class="spec-tag">'.e($f['faculty_code']).'</span>', $fac_unassigned)) ?></span></div></div>
    <?php } ?>

    <!-- Overloaded days alert -->
    <?php if(!empty($fac_overload_rows)){ ?>
    <div class="alert-box warn"><div class="alert-icon">🔴</div><div>Faculty teaching <strong>5 or more slots in a single day</strong> detected. Review below.</div></div>
    <?php } ?>

    <div class="grid-2">
        <div class="panel">
            <div class="panel-title">📊 Top 20 Faculty by Unique Slots/Week <span class="sub">distinct day+time combinations</span></div>
            <div class="chart-wrap tall"><canvas id="facLoadChart"></canvas></div>
        </div>
        <div class="panel">
            <div class="panel-title">🥧 Theory vs Practical vs Project Mix</div>
            <?php if(!empty($fac_type_rows)){ ?>
            <div class="chart-wrap"><canvas id="facTypeChart"></canvas></div>
            <?php } else { ?>
            <div class="empty-state"><div class="ei">📊</div>Subject type data unavailable</div>
            <?php } ?>
        </div>
    </div>

    <!-- Faculty load table -->
    <div class="panel">
        <div class="panel-title">📋 Faculty Workload Table <span class="sub">Unique weekly slots assigned</span></div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>#</th><th>Faculty Code</th><th>Faculty Name</th><th>Unique Slots/Week</th><th>Load Level</th><th style="min-width:140px">Load Bar</th></tr>
                <?php foreach($fac_load_rows as $i=>$r){
                    $slots = (int)$r['unique_slots'];
                    $maxSlots = 30;
                    $pct = min(100, round(($slots/$maxSlots)*100));
                    $level = $slots >= 25 ? '<span class="badge-danger">Heavy</span>' : ($slots >= 15 ? '<span class="badge-warn">Moderate</span>' : ($slots > 0 ? '<span class="badge-ok">Light</span>' : '<span class="badge-info">None</span>'));
                    $barColor = $slots >= 25 ? 'red' : ($slots >= 15 ? 'orange' : 'green');
                ?>
                <tr>
                    <td class="center"><?= $i+1 ?></td>
                    <td><strong><?= e($r['faculty_code']) ?></strong></td>
                    <td><?= e($r['faculty_name']) ?></td>
                    <td class="num"><?= $slots ?></td>
                    <td><?= $level ?></td>
                    <td><div style="display:flex;align-items:center;gap:6px"><div class="prog-bg" style="flex:1"><div class="prog-fill <?= $barColor ?>" style="width:<?= $pct ?>%"></div></div><span style="font-size:10px;font-weight:800;color:#555;min-width:30px"><?= $pct ?>%</span></div></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- Overload table -->
    <?php if(!empty($fac_overload_rows)){ ?>
    <div class="panel">
        <div class="panel-title">🔴 Overloaded Days (5+ slots in a single day)</div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>Faculty Code</th><th>Faculty Name</th><th>Day</th><th>Slots in Day</th><th>Severity</th></tr>
                <?php foreach($fac_overload_rows as $r){
                    $severity = $r['slots_in_day'] >= 7 ? '<span class="badge-danger">Critical</span>' : '<span class="badge-warn">High</span>';
                ?>
                <tr class="conflict-row">
                    <td><strong><?= e($r['faculty_code']) ?></strong></td>
                    <td><?= e($r['faculty_name']) ?></td>
                    <td><?= e($r['day_name']) ?></td>
                    <td class="num"><strong><?= e($r['slots_in_day']) ?></strong></td>
                    <td><?= $severity ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
    <?php } ?>

    <!-- Theory/Practical breakdown table -->
    <?php if(!empty($fac_type_rows)){ ?>
    <div class="panel">
        <div class="panel-title">📊 Faculty Theory / Practical / Project Breakdown</div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>Faculty</th><th>Theory</th><th>Practical</th><th>Project</th><th>Theory %</th></tr>
                <?php foreach($fac_type_rows as $r){
                    $total = $r['theory_slots']+$r['practical_slots']+$r['project_slots'];
                    $thPct = $total > 0 ? round(($r['theory_slots']/$total)*100) : 0;
                ?>
                <tr>
                    <td><strong><?= e($r['faculty_code']) ?></strong> — <?= e($r['faculty_name']) ?></td>
                    <td class="num"><?= e($r['theory_slots']) ?></td>
                    <td class="num"><?= e($r['practical_slots']) ?></td>
                    <td class="num"><?= e($r['project_slots']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div class="prog-bg" style="flex:1"><div class="prog-fill" style="width:<?= $thPct ?>%"></div></div>
                            <span style="font-size:10px;font-weight:800;min-width:30px"><?= $thPct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
    <?php } ?>

</div><!-- /faculty -->


<!-- ══════════ TAB: ROOM UTILIZATION ══════════ -->
<div class="tab-panel <?= $active_tab=='rooms'?'active':'' ?>" id="tab-rooms">

    <div class="section-head">
        <div class="section-icon">🏫</div>
        <div><h2>Room Utilization</h2><p>Classroom usage across Mon–Fri, 6 teaching slots (max 30/week)</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $total_rooms ?></div><div class="stat-label">Classrooms</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= $total_labs ?></div><div class="stat-label">Labs</div></div>
        <?php
        $avg_util = 0;
        if(!empty($room_util_rows)){
            $avg_util = round(array_sum(array_column($room_util_rows,'used_slots')) / (count($room_util_rows)*$total_room_slots) * 100, 1);
        }
        $highly_used = count(array_filter($room_util_rows, fn($r)=>$r['used_slots']/$total_room_slots > 0.7));
        $unused = count(array_filter($room_util_rows, fn($r)=>$r['used_slots'] == 0));
        ?>
        <div class="stat-card indigo"><div class="stat-num"><?= $avg_util ?>%</div><div class="stat-label">Avg Classroom Utilization</div></div>
        <div class="stat-card orange"><div class="stat-num"><?= $highly_used ?></div><div class="stat-label">Highly Used Rooms</div><div class="stat-sub">&gt;70% utilization</div></div>
        <div class="stat-card red"><div class="stat-num"><?= $unused ?></div><div class="stat-label">Unused Rooms</div><div class="stat-sub">0 slots assigned</div></div>
    </div>

    <div class="grid-2">
        <div class="panel">
            <div class="panel-title">📊 Top 20 Room Utilization %</div>
            <div class="chart-wrap tall"><canvas id="roomUtilChart"></canvas></div>
        </div>
        <div class="panel">
            <div class="panel-title">🥧 Utilization Spread</div>
            <div class="chart-wrap"><canvas id="roomSpreadChart"></canvas></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">📋 All Classrooms Utilization Detail <span class="sub">Max 30 slots/week (Mon–Fri, 6 slots)</span></div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>#</th><th>Room No.</th><th>Used Slots</th><th>Free Slots</th><th>Utilization</th><th style="min-width:160px">Bar</th></tr>
                <?php foreach($room_util_rows as $i=>$r){
                    $used = (int)$r['used_slots'];
                    $free = $total_room_slots - $used;
                    $pct = round(($used/$total_room_slots)*100,1);
                    $color = $pct > 70 ? 'red' : ($pct > 40 ? 'orange' : 'green');
                    $badge = $pct > 70 ? '<span class="badge-danger">High</span>' : ($pct > 40 ? '<span class="badge-warn">Med</span>' : ($pct > 0 ? '<span class="badge-ok">Low</span>' : '<span class="badge-info">Unused</span>'));
                ?>
                <tr>
                    <td class="center"><?= $i+1 ?></td>
                    <td><strong><?= e($r['room_code']) ?></strong></td>
                    <td class="num"><?= $used ?></td>
                    <td class="num"><?= $free ?></td>
                    <td><?= $badge ?> <span style="font-size:11px;font-weight:800"><?= $pct ?>%</span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <div class="prog-bg" style="flex:1"><div class="prog-fill <?= $color ?>" style="width:<?= $pct ?>%"></div></div>
                            <span style="font-size:10px;font-weight:800;color:#555;min-width:35px"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>

</div><!-- /rooms -->


<!-- ══════════ TAB: CONFLICT DETECTION ══════════ -->
<div class="tab-panel <?= $active_tab=='conflicts'?'active':'' ?>" id="tab-conflicts">

    <div class="section-head">
        <div class="section-icon">⚠️</div>
        <div><h2>Conflict Detection</h2><p>Faculty double-bookings, room double-bookings, and batch overlaps</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card red"><div class="stat-num"><?= count($faculty_conflicts) ?></div><div class="stat-label">Faculty Conflicts</div><div class="stat-sub">Same faculty, 2+ divisions, same slot</div></div>
        <div class="stat-card red"><div class="stat-num"><?= count($room_conflicts) ?></div><div class="stat-label">Room Conflicts</div><div class="stat-sub">Same room, 2+ divisions, same slot</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= $total_conflicts == 0 ? '✓' : $total_conflicts ?></div><div class="stat-label">Total Conflicts</div></div>
    </div>

    <?php if($total_conflicts === 0){ ?>
    <div class="alert-box success"><div class="alert-icon">✅</div><div><strong>Zero scheduling conflicts detected!</strong> All faculty and classroom assignments for <?= e($selected_year) ?> <?= e($selected_semester) ?> are clean with no double-bookings.</div></div>
    <?php } else { ?>
    <div class="alert-box danger"><div class="alert-icon">🚨</div><div><strong><?= $total_conflicts ?> scheduling conflict(s) found.</strong> These must be resolved before publishing the timetable. Each conflict means a faculty or room is assigned to two different divisions in the same time slot.</div></div>
    <?php } ?>

    <!-- Faculty conflicts -->
    <div class="panel">
        <div class="panel-title">👨‍🏫 Faculty Double-Booking Conflicts <?php if(count($faculty_conflicts)>0) echo '<span class="badge-danger" style="margin-left:8px">'.count($faculty_conflicts).'</span>'; ?></div>
        <?php if(empty($faculty_conflicts)){ ?>
        <div class="alert-box success"><div class="alert-icon">✅</div><div>No faculty double-booking conflicts detected for this AY/Sem.</div></div>
        <?php } else { ?>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>#</th><th>Faculty</th><th>Day</th><th>Slot</th><th>Conflicting Divisions</th><th>Count</th></tr>
                <?php foreach($faculty_conflicts as $i=>$r){ ?>
                <tr class="conflict-row">
                    <td class="center"><?= $i+1 ?></td>
                    <td><strong><?= e($r['faculty_code']) ?></strong><br><span style="font-size:11px;color:#666"><?= e($r['faculty_name']) ?></span></td>
                    <td><?= e($r['day_name']) ?></td>
                    <td><span class="badge-warn"><?= e($r['time_slot']) ?></span></td>
                    <td><?= e($r['divisions']) ?></td>
                    <td><span class="badge-danger"><?= e($r['div_count']) ?> divisions</span></td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } ?>
    </div>

    <!-- Room conflicts -->
    <div class="panel">
        <div class="panel-title">🏫 Classroom Double-Booking Conflicts <?php if(count($room_conflicts)>0) echo '<span class="badge-danger" style="margin-left:8px">'.count($room_conflicts).'</span>'; ?></div>
        <?php if(empty($room_conflicts)){ ?>
        <div class="alert-box success"><div class="alert-icon">✅</div><div>No classroom double-booking conflicts detected for this AY/Sem.</div></div>
        <?php } else { ?>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>#</th><th>Room</th><th>Day</th><th>Slot</th><th>Divisions</th><th>Faculty</th><th>Count</th></tr>
                <?php foreach($room_conflicts as $i=>$r){ ?>
                <tr class="conflict-row">
                    <td class="center"><?= $i+1 ?></td>
                    <td><strong><?= e($r['room_code']) ?></strong></td>
                    <td><?= e($r['day_name']) ?></td>
                    <td><span class="badge-warn"><?= e($r['time_slot']) ?></span></td>
                    <td><?= e($r['divisions']) ?></td>
                    <td><?= e($r['faculties']) ?></td>
                    <td><span class="badge-danger"><?= e($r['div_count']) ?> divisions</span></td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } ?>
    </div>

</div><!-- /conflicts -->


<!-- ══════════ TAB: COMPLETENESS ══════════ -->
<div class="tab-panel <?= $active_tab=='completeness'?'active':'' ?>" id="tab-completeness">

    <div class="section-head">
        <div class="section-icon">✅</div>
        <div><h2>Completeness Check</h2><p>Missing metadata, orphan entries, and data gaps across timetables</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card <?= $div_no_entries>0?'red':'' ?>"><div class="stat-num"><?= $div_no_entries ?></div><div class="stat-label">Divisions with No Entries</div></div>
        <div class="stat-card <?= $div_missing_ct>0?'orange':'' ?>"><div class="stat-num"><?= $div_missing_ct ?></div><div class="stat-label">Missing Class Teachers</div></div>
        <div class="stat-card <?= $div_missing_wef>0?'orange':'' ?>"><div class="stat-num"><?= $div_missing_wef ?></div><div class="stat-label">Missing W.E.F. Dates</div></div>
        <div class="stat-card <?= $div_missing_prepared>0?'orange':'' ?>"><div class="stat-num"><?= $div_missing_prepared ?></div><div class="stat-label">Missing Prepared By</div></div>
        <div class="stat-card <?= count($orphan_no_fac)>0?'red':'' ?>"><div class="stat-num"><?= count($orphan_no_fac) ?></div><div class="stat-label">Entries Without Faculty</div></div>
        <div class="stat-card <?= count($orphan_no_room)>0?'orange':'' ?>"><div class="stat-num"><?= count($orphan_no_room) ?></div><div class="stat-label">Entries Without Room</div></div>
        <div class="stat-card <?= $sig_count==0?'red':'' ?>"><div class="stat-num"><?= $sig_count ?></div><div class="stat-label">Signatory Records</div><div class="stat-sub">For this AY/Sem</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= count($subjects_not_used) ?></div><div class="stat-label">Unused Subject Codes</div><div class="stat-sub">In master, not in TT</div></div>
    </div>

    <!-- Orphan: no faculty -->
    <div class="panel">
        <div class="panel-title">❌ Entries Without Faculty Assigned <?php if(count($orphan_no_fac)>0) echo '<span class="badge-danger" style="margin-left:8px">'.count($orphan_no_fac).'</span>'; ?></div>
        <?php if(empty($orphan_no_fac)){ ?>
        <div class="alert-box success"><div class="alert-icon">✅</div><div>All timetable entries have faculty assigned.</div></div>
        <?php } else { ?>
        <div class="alert-box warn"><div class="alert-icon">⚠️</div><div><?= count($orphan_no_fac) ?> timetable entries are missing faculty assignment. These slots show as empty in Faculty TT.</div></div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>Division</th><th>Day</th><th>Slot</th><th>Subject Code</th><th>Subject Name</th></tr>
                <?php foreach($orphan_no_fac as $r){ ?>
                <tr class="conflict-row">
                    <td><strong><?= e($r['division_name']) ?></strong></td>
                    <td><?= e($r['day_name']) ?></td>
                    <td><span class="badge-warn"><?= e($r['time_slot']) ?></span></td>
                    <td><strong><?= e($r['subject_code']) ?></strong></td>
                    <td><?= e($r['subject_name']) ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } ?>
    </div>

    <!-- Orphan: no room -->
    <div class="panel">
        <div class="panel-title">🏫 Entries Without Classroom Assigned <?php if(count($orphan_no_room)>0) echo '<span class="badge-warn" style="margin-left:8px">'.count($orphan_no_room).'</span>'; ?></div>
        <?php if(empty($orphan_no_room)){ ?>
        <div class="alert-box success"><div class="alert-icon">✅</div><div>All timetable entries have a classroom or lab assigned.</div></div>
        <?php } else { ?>
        <div class="alert-box warn"><div class="alert-icon">⚠️</div><div><?= count($orphan_no_room) ?> entries are missing a classroom/lab assignment.</div></div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>Division</th><th>Day</th><th>Slot</th><th>Subject Code</th><th>Subject Name</th></tr>
                <?php foreach($orphan_no_room as $r){ ?>
                <tr>
                    <td><strong><?= e($r['division_name']) ?></strong></td>
                    <td><?= e($r['day_name']) ?></td>
                    <td><span class="badge-warn"><?= e($r['time_slot']) ?></span></td>
                    <td><strong><?= e($r['subject_code']) ?></strong></td>
                    <td><?= e($r['subject_name']) ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } ?>
    </div>

    <!-- Subjects in master, not in TT -->
    <?php if(!empty($subjects_not_used)){ ?>
    <div class="panel">
        <div class="panel-title">📘 Subject Codes in Master But Not Used in Timetable</div>
        <div class="alert-box info"><div class="alert-icon">ℹ️</div><div><?= count($subjects_not_used) ?> subject codes exist in Course Master for this AY/Sem but have not been assigned to any timetable slot.</div></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
            <?php foreach($subjects_not_used as $s){ ?>
            <span class="spec-tag" title="<?= e($s['subject_name']) ?>"><?= e($s['subject_code']) ?></span>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <!-- Divisions with no entries -->
    <?php
    $no_entry_divs = array_filter($div_fill_rows, fn($r)=>(int)$r['entry_count']===0);
    if(!empty($no_entry_divs)){ ?>
    <div class="panel">
        <div class="panel-title">📋 Divisions With Zero Timetable Entries</div>
        <div class="alert-box danger"><div class="alert-icon">🚨</div><div><?= count($no_entry_divs) ?> division(s) have no timetable entries for <?= e($selected_year) ?> <?= e($selected_semester) ?>.</div></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
            <?php foreach($no_entry_divs as $r){ ?>
            <span class="spec-tag" style="background:#fee2e2;color:#dc2626;border-color:#fca5a5"><?= e($r['division_name']) ?></span>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

</div><!-- /completeness -->


<!-- ══════════ TAB: SUBJECT ANALYSIS ══════════ -->
<div class="tab-panel <?= $active_tab=='subjects'?'active':'' ?>" id="tab-subjects">

    <div class="section-head">
        <div class="section-icon">📘</div>
        <div><h2>Subject Analysis</h2><p>Most-taught courses, coverage and division distribution</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $total_subjects ?></div><div class="stat-label">Unique Subject Codes</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= count($subj_freq_rows) ?></div><div class="stat-label">Subjects This AY/Sem</div></div>
        <div class="stat-card orange"><div class="stat-num"><?= count($subjects_not_used) ?></div><div class="stat-label">Unused in TT</div></div>
    </div>

    <div class="grid-2">
        <div class="panel">
            <div class="panel-title">📊 Top 15 Most-Assigned Subjects (by slot count)</div>
            <div class="chart-wrap tall"><canvas id="subjChart"></canvas></div>
        </div>
        <div class="panel">
            <div class="panel-title">📋 Subject Detail Table</div>
            <div class="table-scroll" style="max-height:340px">
                <table class="data-table">
                    <tr><th>#</th><th>Code</th><th>Subject Name</th><th>Slots</th><th>Divisions</th><th>Faculty</th></tr>
                    <?php foreach($subj_freq_rows as $i=>$r){ ?>
                    <tr>
                        <td class="center"><?= $i+1 ?></td>
                        <td><strong><?= e($r['subject_code']) ?></strong></td>
                        <td><?= e(mb_strimwidth($r['subject_name'],0,40,'…')) ?></td>
                        <td class="num"><?= e($r['slot_count']) ?></td>
                        <td class="num"><?= e($r['div_count']) ?></td>
                        <td class="num"><?= e($r['fac_count']) ?></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>

</div><!-- /subjects -->


<!-- ══════════ TAB: WORKLOAD VS ACTUAL ══════════ -->
<div class="tab-panel <?= $active_tab=='workload'?'active':'' ?>" id="tab-workload">

    <div class="section-head">
        <div class="section-icon">📈</div>
        <div><h2>Workload: Planned vs Actual</h2><p>Compare faculty_workload_planning master against real timetable assignment counts</p></div>
    </div>

    <?php if(!$hasWorkload){ ?>
    <div class="alert-box info"><div class="alert-icon">ℹ️</div><div>Faculty Workload Planning master table is not available. Upload faculty workload data via Common Information → Faculty Workload Master to enable this analysis.</div></div>
    <?php } elseif(empty($wl_vs_actual)){ ?>
    <div class="alert-box warn"><div class="alert-icon">⚠️</div><div>No faculty timetable data found for <?= e($selected_year) ?> <?= e($selected_semester) ?> to compare against workload planning. Ensure timetable entries and workload planning rows both exist.</div></div>
    <?php } else { ?>

    <div class="stat-grid">
        <?php
        $over  = count(array_filter($wl_vs_actual, fn($r)=>$r['diff'] > 3));
        $under = count(array_filter($wl_vs_actual, fn($r)=>$r['diff'] < -3));
        $match = count($wl_vs_actual) - $over - $under;
        ?>
        <div class="stat-card red"><div class="stat-num"><?= $over ?></div><div class="stat-label">Overloaded vs Plan</div><div class="stat-sub">Actual &gt; Planned by 3+</div></div>
        <div class="stat-card orange"><div class="stat-num"><?= $under ?></div><div class="stat-label">Underloaded vs Plan</div><div class="stat-sub">Actual &lt; Planned by 3+</div></div>
        <div class="stat-card teal"><div class="stat-num"><?= $match ?></div><div class="stat-label">Within Target</div></div>
    </div>

    <div class="panel">
        <div class="panel-title">📊 Planned vs Actual Workload — Top 20 Faculty</div>
        <div class="chart-wrap xlg"><canvas id="wlChart"></canvas></div>
    </div>

    <div class="panel">
        <div class="panel-title">📋 Full Workload Comparison Table</div>
        <div class="table-scroll">
            <table class="data-table">
                <tr><th>#</th><th>Faculty Code</th><th>Faculty Name</th><th>Planned Hrs</th><th>Actual Slots</th><th>Δ Delta</th><th>Status</th></tr>
                <?php foreach($wl_vs_actual as $i=>$r){
                    $diff = $r['diff'];
                    $diffClass = $diff > 3 ? 'delta-pos' : ($diff < -3 ? 'delta-neg' : 'delta-zero');
                    $diffStr = ($diff > 0 ? '+' : '').$diff;
                    $status = abs($diff) <= 3 ? '<span class="badge-ok">On Track</span>' : ($diff > 3 ? '<span class="badge-danger">Over</span>' : '<span class="badge-warn">Under</span>');
                ?>
                <tr>
                    <td class="center"><?= $i+1 ?></td>
                    <td><strong><?= e($r['faculty_code']) ?></strong></td>
                    <td><?= e($r['faculty_name']) ?></td>
                    <td class="num"><?= $r['planned'] > 0 ? e($r['planned']) : '<span style="color:#ccc">—</span>' ?></td>
                    <td class="num"><?= e($r['actual']) ?></td>
                    <td class="num <?= $diffClass ?>"><?= $diffStr ?></td>
                    <td><?= $status ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
    <?php } ?>

</div><!-- /workload -->


<!-- ══════════ TAB: SLOT HEATMAP ══════════ -->
<div class="tab-panel <?= $active_tab=='heatmap'?'active':'' ?>" id="tab-heatmap">

    <div class="section-head">
        <div class="section-icon">🔥</div>
        <div><h2>Slot Usage Heatmap</h2><p>How many divisions have classes in each day + time slot combination</p></div>
    </div>

    <div class="panel">
        <div class="panel-title">🔥 Timetable Heatmap — Divisions per Slot <span class="sub">Darker = more divisions teaching simultaneously</span></div>

        <div style="overflow-x:auto">
            <table class="heatmap-table" style="min-width:700px">
                <tr>
                    <th style="min-width:90px">Day / Slot</th>
                    <?php foreach($teaching_slots as $sl){ ?>
                    <th><?= e(str_replace('-',' - ',$sl)) ?></th>
                    <?php } ?>
                </tr>
                <?php foreach($days as $day){ ?>
                <tr>
                    <td class="hm-day"><?= e($day) ?></td>
                    <?php foreach($teaching_slots as $sl){
                        $cnt = $heatmap_data[$day][$sl] ?? 0;
                        $intensity = $heatmap_max > 0 ? $cnt/$heatmap_max : 0;
                        $r = 90 + round(165*$intensity); // purple to deep purple
                        $g = 31 + round(20*(1-$intensity));
                        $b = 107 + round(148*(1-$intensity));
                        $bg = "rgb($r,$g,$b)";
                        $textColor = $intensity > 0.5 ? '#fff' : '#3d0f6b';
                    ?>
                    <td style="background:<?= $bg ?>;color:<?= $textColor ?>;font-weight:<?= $cnt>0?'900':'400' ?>">
                        <?= $cnt > 0 ? $cnt : '' ?>
                    </td>
                    <?php } ?>
                </tr>
                <?php } ?>
            </table>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-top:14px;font-size:12px;font-weight:700;color:#555">
            <span>Low</span>
            <div style="width:200px;height:14px;border-radius:999px;background:linear-gradient(90deg,rgb(186,180,219),rgb(90,31,140));border:1px solid #ccc"></div>
            <span>High (<?= $heatmap_max ?> divisions)</span>
            <span style="margin-left:20px;color:#9b6bc5">Numbers = count of divisions having class in that slot</span>
        </div>
    </div>

    <!-- Busiest slots -->
    <div class="grid-2">
        <div class="panel">
            <div class="panel-title">🔥 Busiest Slots (most divisions simultaneously)</div>
            <?php
            $flat = [];
            foreach($heatmap_data as $d=>$sv) foreach($sv as $s=>$c) if($c>0) $flat[] = ['day'=>$d,'slot'=>$s,'cnt'=>$c];
            usort($flat, fn($a,$b)=>$b['cnt']-$a['cnt']);
            $busiest = array_slice($flat, 0, 10);
            ?>
            <div class="table-scroll">
                <table class="data-table">
                    <tr><th>Day</th><th>Slot</th><th>Divisions Teaching</th></tr>
                    <?php foreach($busiest as $r){ ?>
                    <tr>
                        <td><?= e($r['day']) ?></td>
                        <td><span class="badge-warn"><?= e($r['slot']) ?></span></td>
                        <td><strong><?= e($r['cnt']) ?></strong> divisions <div class="prog-bg" style="margin-top:3px"><div class="prog-fill red" style="width:<?= round($r['cnt']/$heatmap_max*100) ?>%"></div></div></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <div class="panel">
            <div class="panel-title">❄️ Lightest Slots (fewest classes)</div>
            <?php
            usort($flat, fn($a,$b)=>$a['cnt']-$b['cnt']);
            $lightest = array_slice($flat, 0, 10);
            ?>
            <div class="table-scroll">
                <table class="data-table">
                    <tr><th>Day</th><th>Slot</th><th>Divisions Teaching</th></tr>
                    <?php foreach($lightest as $r){ ?>
                    <tr>
                        <td><?= e($r['day']) ?></td>
                        <td><span class="badge-blue"><?= e($r['slot']) ?></span></td>
                        <td><strong><?= e($r['cnt']) ?></strong> divisions</td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>

</div><!-- /heatmap -->

</div><!-- /.main -->

<!-- ══════════ CHART.JS SCRIPTS ══════════ -->
<script>
const PURPLE  = '#7b2fb5';
const PURPLE2 = '#5a1f8c';
const PINK    = '#C1345C';
const TEAL    = '#0b8f7a';
const ORANGE  = '#d97706';
const INDIGO  = '#4f46e5';
const PURPLES = ['#3d0f6b','#5a1f8c','#7b2fb5','#9b59c5','#b57ed8','#c9a0e0','#dfc5f0','#ede0f7'];
const GREEN   = '#15803d';
const RED     = '#dc2626';

Chart.defaults.font.family = "'Poppins', sans-serif";
Chart.defaults.color       = '#6b4a86';
Chart.defaults.plugins.legend.labels.boxWidth = 12;

// ── DEPT CHART ──
<?php if(!empty($dept_summary)){ ?>
(function(){
    const data = <?= $dept_json ?>;
    const ctx  = document.getElementById('deptChart');
    if(!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(d=>d.dept),
            datasets:[{data:data.map(d=>d.entries),backgroundColor:PURPLES,borderWidth:2,borderColor:'#fff'}]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'},tooltip:{callbacks:{label:c=>c.label+': '+c.raw+' entries'}}}}
    });
})();
<?php } ?>

// ── YEAR CHART ──
<?php if(!empty($year_summary)){ ?>
(function(){
    const data = <?= $year_json ?>;
    const ctx  = document.getElementById('yearChart');
    if(!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data:{labels:data.map(d=>d.year),datasets:[{label:'Entries',data:data.map(d=>d.entries),backgroundColor:[PURPLE,PURPLE2,PINK,TEAL,ORANGE],borderRadius:6,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'}},x:{grid:{display:false}}}}
    });
})();
<?php } ?>

// ── DAY CHART ──
(function(){
    const data = <?= $day_json ?>;
    const ctx  = document.getElementById('dayChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{labels:data.map(d=>d.day),datasets:[{label:'Entries',data:data.map(d=>d.cnt),backgroundColor:data.map((_,i)=>`hsl(${270+i*10},60%,45%)`),borderRadius:6,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'}},x:{grid:{display:false}}}}
    });
})();

// ── SPEC CHART ──
<?php if(!empty($spec_summary)){ ?>
(function(){
    const data = <?= json_encode(array_map(fn($r)=>['spec'=>$r['specialization'],'entries'=>(int)$r['entry_count']], $spec_summary)) ?>;
    const ctx  = document.getElementById('specChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'doughnut',
        data:{labels:data.map(d=>d.spec),datasets:[{data:data.map(d=>d.entries),backgroundColor:[...PURPLES,...PURPLES].slice(0,data.length),borderWidth:2,borderColor:'#fff'}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'},tooltip:{callbacks:{label:c=>c.label+': '+c.raw+' entries'}}}}
    });
})();
<?php } ?>

// ── DIVISION FILL CHART ──
<?php if(!empty($div_fill_rows)){ ?>
(function(){
    const data = <?= $div_fill_json ?>;
    const ctx  = document.getElementById('divFillChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{labels:data.map(d=>d.name),datasets:[{label:'Entries',data:data.map(d=>d.count),backgroundColor:data.map(d=>{const pct=d.count/42;return pct>0.7?'#5a1f8c':pct>0.3?'#d97706':'#dc2626';}),borderRadius:4,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'}},x:{grid:{display:false},ticks:{font:{size:10}}}},indexAxis:'y'}
    });
})();
<?php } ?>

// ── FACULTY LOAD CHART ──
<?php if(!empty($fac_load_rows)){ ?>
(function(){
    const data = <?= $fac_load_json ?>;
    const ctx  = document.getElementById('facLoadChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{labels:data.map(d=>d.name),datasets:[{label:'Unique Slots/Week',data:data.map(d=>d.slots),backgroundColor:data.map(d=>d.slots>=25?RED:d.slots>=15?ORANGE:TEAL),borderRadius:4,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'},max:32},x:{grid:{display:false},ticks:{font:{size:9}}}},indexAxis:'y'}
    });
})();
<?php } ?>

// ── FACULTY TYPE PIE ──
<?php if(!empty($fac_type_rows)){ ?>
(function(){
    const rows  = <?= json_encode($fac_type_rows) ?>;
    const totTh = rows.reduce((a,r)=>a+(+r.theory_slots),0);
    const totPr = rows.reduce((a,r)=>a+(+r.practical_slots),0);
    const totPj = rows.reduce((a,r)=>a+(+r.project_slots),0);
    const ctx   = document.getElementById('facTypeChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'doughnut',
        data:{labels:['Theory','Practical','Project / Other'],datasets:[{data:[totTh,totPr,totPj],backgroundColor:[PURPLE,TEAL,ORANGE],borderWidth:2,borderColor:'#fff'}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    });
})();
<?php } ?>

// ── ROOM UTIL CHART ──
<?php if(!empty($room_util_rows)){ ?>
(function(){
    const data = <?= $room_util_json ?>;
    const ctx  = document.getElementById('roomUtilChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{labels:data.map(d=>d.room),datasets:[{label:'Utilization %',data:data.map(d=>d.pct),backgroundColor:data.map(d=>d.pct>70?RED:d.pct>40?ORANGE:TEAL),borderRadius:4,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100,grid:{color:'#f0e8f8'},ticks:{callback:v=>v+'%'}},x:{grid:{display:false},ticks:{font:{size:9}}}},indexAxis:'y'}
    });
})();

(function(){
    const rows   = <?= $room_util_json ?>;
    const high   = rows.filter(r=>r.pct>70).length;
    const med    = rows.filter(r=>r.pct>40&&r.pct<=70).length;
    const low    = rows.filter(r=>r.pct>0&&r.pct<=40).length;
    const unused = rows.filter(r=>r.pct==0).length;
    const ctx    = document.getElementById('roomSpreadChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'doughnut',
        data:{labels:['High (>70%)','Medium (40-70%)','Low (<40%)','Unused'],datasets:[{data:[high,med,low,unused],backgroundColor:[RED,ORANGE,TEAL,'#ccc'],borderWidth:2,borderColor:'#fff'}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}
    });
})();
<?php } ?>

// ── SUBJECT CHART ──
<?php if(!empty($subj_freq_rows)){ ?>
(function(){
    const data = <?= $subj_json ?>;
    const ctx  = document.getElementById('subjChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{labels:data.map(d=>d.code),datasets:[{label:'Total Slots',data:data.map(d=>d.slots),backgroundColor:data.map((_,i)=>PURPLES[i%PURPLES.length]),borderRadius:4,borderSkipped:false}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'}},x:{grid:{display:false},ticks:{font:{size:9}}}},indexAxis:'y'}
    });
})();
<?php } ?>

// ── WORKLOAD VS ACTUAL CHART ──
<?php if($hasWorkload && !empty($wl_vs_actual)){ ?>
(function(){
    const data = <?= $wl_json ?>;
    const ctx  = document.getElementById('wlChart');
    if(!ctx) return;
    new Chart(ctx, {
        type:'bar',
        data:{
            labels:data.map(d=>d.code),
            datasets:[
                {label:'Planned (hrs)',data:data.map(d=>d.planned),backgroundColor:PURPLE+'cc',borderRadius:4,borderSkipped:false},
                {label:'Actual (slots)',data:data.map(d=>d.actual),backgroundColor:PINK+'cc',borderRadius:4,borderSkipped:false}
            ]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,grid:{color:'#f0e8f8'}},x:{grid:{display:false},ticks:{font:{size:9}}}}}
    });
})();
<?php } ?>

// ── TAB SWITCHER ──
function switchTab(id){
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    const panel = document.getElementById('tab-'+id);
    if(panel) panel.classList.add('active');
    const btn = document.querySelector(`.tab-btn[onclick="switchTab('${id}')"]`);
    if(btn) btn.classList.add('active');
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    history.replaceState(null,'',url);
}
</script>
</body>
</html>
