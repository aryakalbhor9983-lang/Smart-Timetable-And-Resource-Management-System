<?php
session_start();

$host = "localhost";
$user = "u448784079_sanskruti";
$password = "Mitadt2026";
$database = "u448784079_university_tt";

$conn = new mysqli($host, $user, $password, $database);


function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }
function q1($conn, $sql, $default=''){
    $res = @$conn->query($sql);
    if(!$res) return $default;
    $row = $res->fetch_assoc();
    if(!$row) return $default;
    return array_values($row)[0] ?? $default;
}
function column_exists($conn, $table, $column){
    $safeTable = $conn->real_escape_string($table);
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}

$academic_year = $_GET['academic_year'] ?? '2025-26';
$semester = $_GET['semester'] ?? 'Sem-I';
if($semester === 'Odd') $semester = 'Sem-I';
if($semester === 'Even') $semester = 'Sem-II';

$hasSubjectType = column_exists($conn, 'subjects', 'subject_type');
$hasFacultyUid = column_exists($conn, 'faculties', 'faculty_uid');
$hasFacultyDesignation = column_exists($conn, 'faculties', 'designation');
$badFacultyFilter = "faculty_code NOT REGEXP '^(D[0-9]+|[0-9]+PM|[0-9]+|[SN][0-9]|ONLINE|NULL|NONE|NAN)$'";

/* Current hierarchy corrections for workload report */
if($hasFacultyDesignation){
    @$conn->query("UPDATE faculties SET designation='Dean' WHERE faculty_code='GPAT'");
    @$conn->query("UPDATE faculties SET designation='Associate Dean' WHERE faculty_code='MVN'");
    if(column_exists($conn, 'faculties', 'role_type')){
        @$conn->query("UPDATE faculties SET role_type='Administration' WHERE faculty_code IN ('GPAT','MVN','JRP','NNJ','SPP')");
    }
}


$days = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];
$slots = [
    "08:45-09:40","09:40-10:35","10:35-10:50","10:50-11:45","11:45-12:40",
    "12:40-01:40","01:40-02:35","02:35-03:30","03:30-03:40","03:40-04:30"
];
$break_slots = [
    "10:35-10:50" => "SHORT BREAK",
    "12:40-01:40" => "LUNCH BREAK",
    "03:30-03:40" => "SHORT BREAK"
];

/* For utilization percentage, consider official working load base:
   Monday to Friday, up to 03:30 only = 5 days × 6 teaching slots = 30. */
$util_days = ["Monday","Tuesday","Wednesday","Thursday","Friday"];
$util_slots = [
    "08:45-09:40",
    "09:40-10:35",
    "10:50-11:45",
    "11:45-12:40",
    "01:40-02:35",
    "02:35-03:30"
];
$total_weekly_slots = count($util_days) * count($util_slots); // 30

$breakSlotList = "'".implode("','", array_map([$conn,'real_escape_string'], array_keys($break_slots)))."'";
$aySafe = $conn->real_escape_string($academic_year);
$semSafe = $conn->real_escape_string($semester);
$subjectTypeSql = $hasSubjectType ? "s.subject_type" : "'Theory'";
$facultyUidSql = $hasFacultyUid ? "f.faculty_uid" : "'' AS faculty_uid";
$designationSql = $hasFacultyDesignation ? "f.designation" : "'' AS designation";

/* Correct workload logic:
   One faculty in the same day + same time slot counts as 1 load only,
   even if the same lecture is mapped to multiple divisions/batches. */
$sql = "
SELECT
    x.faculty_uid,
    x.faculty_code,
    x.faculty_name,
    x.designation,
    SUM(CASE WHEN x.category='Theory' THEN 1 ELSE 0 END) AS theory_load,
    SUM(CASE WHEN x.category='Practical' THEN 1 ELSE 0 END) AS practical_load,
    SUM(CASE WHEN x.category='Mini Project' THEN 1 ELSE 0 END) AS mini_project_load,
    SUM(CASE WHEN x.category='Major Project' THEN 1 ELSE 0 END) AS major_project_load,
    SUM(CASE WHEN x.category='Other' THEN 1 ELSE 0 END) AS other_load,
    COUNT(*) AS total_load
FROM (
    SELECT
        f.id AS faculty_id,
        $facultyUidSql,
        f.faculty_code,
        f.faculty_name,
        $designationSql,
        t.day_name,
        t.time_slot,
        CASE
            WHEN MAX(CASE WHEN UPPER(s.subject_code) LIKE '%MAJOR%' OR UPPER(s.subject_name) LIKE '%MAJOR PROJECT%' THEN 1 ELSE 0 END)=1 THEN 'Major Project'
            WHEN MAX(CASE WHEN UPPER(s.subject_code) LIKE '%PBL%' OR UPPER(s.subject_code) LIKE '%MPP%' OR UPPER(s.subject_name) LIKE '%PROJECT%' OR UPPER(s.subject_name) LIKE '%REVIEW%' THEN 1 ELSE 0 END)=1 THEN 'Mini Project'
            WHEN MAX(CASE WHEN UPPER($subjectTypeSql)='PRACTICAL'
                        OR UPPER(s.subject_name) LIKE '%LAB%'
                        OR UPPER(s.subject_code) REGEXP '(DSL|DMSL|PAIL|EEL|PLL|MCAL|ADAL|BDAL|DLNNL|HPCL|VAPTL|BTL|FSDL|IDSL|IOTAL|ITML|MLL|DCL|CDEL|CFL|CFDL|DLECL|EDAL|AWTL|ABDAL|BTAL|DMAL)$'
                        OR (t.batch IS NOT NULL AND t.batch<>'')
                    THEN 1 ELSE 0 END)=1 THEN 'Practical'
            WHEN MAX(CASE WHEN UPPER(s.subject_code) IN ('PAL','LIBRARY','MOOC','MENTOR','EXPERT','REMEDIAL','NPTEL','SCIL','SCIL-APT','SCIL-PS','TRAINING & PLACEMENT ACTIVITY','DOUBT') THEN 1 ELSE 0 END)=1 THEN 'Other'
            ELSE 'Theory'
        END AS category
    FROM timetable_entries t
    JOIN faculties f ON f.id=t.faculty_id
    JOIN subjects s ON s.id=t.subject_id
    WHERE t.academic_year='$aySafe'
      AND t.semester='$semSafe'
      AND t.faculty_id IS NOT NULL
      AND t.time_slot NOT IN ($breakSlotList)
      AND f.$badFacultyFilter
    GROUP BY f.id, faculty_uid, f.faculty_code, f.faculty_name, designation, t.day_name, t.time_slot
) x
GROUP BY x.faculty_id, x.faculty_uid, x.faculty_code, x.faculty_name, x.designation
ORDER BY
CASE
    WHEN LOWER(TRIM(x.designation)) = 'dean' THEN 1
    WHEN LOWER(TRIM(x.designation)) = 'associate dean' THEN 2
    WHEN LOWER(TRIM(x.designation)) LIKE '%pro vc%' THEN 3
    WHEN LOWER(TRIM(x.designation)) = 'professor & director' THEN 4
    WHEN LOWER(TRIM(x.designation)) LIKE '%hod%' THEN 5
    WHEN LOWER(TRIM(x.designation)) = 'professor' THEN 6
    WHEN LOWER(TRIM(x.designation)) = 'associate professor' THEN 7
    WHEN LOWER(TRIM(x.designation)) = 'assistant professor' THEN 8
    WHEN LOWER(TRIM(x.designation)) LIKE '%teaching asst%' THEN 9
    WHEN LOWER(TRIM(x.designation)) = 'visiting faculty' THEN 10
    WHEN LOWER(TRIM(x.designation)) = 'adjunct faculty' THEN 11
    ELSE 99
END,
x.faculty_name ASC,
x.faculty_code ASC
";

$res = $conn->query($sql);
$rows = [];
$totals = ['theory'=>0,'practical'=>0,'mini'=>0,'major'=>0,'other'=>0,'total'=>0];
while($res && $r=$res->fetch_assoc()){
    $rows[] = $r;
    $totals['theory'] += intval($r['theory_load']);
    $totals['practical'] += intval($r['practical_load']);
    $totals['mini'] += intval($r['mini_project_load']);
    $totals['major'] += intval($r['major_project_load']);
    $totals['other'] += intval($r['other_load']);
    $totals['total'] += intval($r['total_load']);
}

$total_faculty = count($rows);
$avg_load = $total_faculty ? round($totals['total'] / $total_faculty, 1) : 0;
$max_load = $total_faculty ? max(array_map(fn($r)=>intval($r['total_load']), $rows)) : 0;
$avg_util = $total_faculty && $total_weekly_slots > 0 ? round(($avg_load / $total_weekly_slots) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty Workload Summary Report</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}
body{margin:0;font-family:Poppins,Arial,sans-serif;background:#f5f0fa;color:#1a0533;}
.no-print{background:#fff;padding:18px 24px;border-bottom:3px solid #C1345C;box-shadow:0 2px 10px rgba(90,31,140,.12);}
.no-print form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
select,button,a.btn{height:36px;padding:7px 14px;border-radius:8px;border:1.5px solid #c9a0e0;background:#fff;font-family:Poppins,sans-serif;font-weight:700;text-decoration:none;color:#3d0f6b;}
button,a.btn{background:#7b2fb5;color:#fff;border:none;cursor:pointer;}
button:hover,a.btn:hover{background:#5a1f8c;}
.report-page{width:96%;max-width:1240px;margin:20px auto;background:#fff;border:1px solid #d4b8ea;border-radius:12px;overflow:hidden;box-shadow:0 4px 18px rgba(90,31,140,.12);}
.report-header{background:linear-gradient(90deg,#3d0f6b,#5a1f8c,#7b2fb5);color:#fff;padding:18px 22px;text-align:center;border-bottom:4px solid #C1345C;}
.report-header h1{margin:0;font-size:23px;font-weight:900;text-transform:uppercase;letter-spacing:.4px;}
.report-header h2{margin:4px 0 0;font-size:15px;font-weight:700;color:#f1d9ff;}
.report-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:16px;background:#f9f4ff;border-bottom:1px solid #e0c8f0;}
.meta-card{background:#fff;border:1px solid #e0c8f0;border-radius:10px;padding:12px;text-align:center;}
.meta-card h3{margin:0;color:#3d0f6b;font-size:24px;line-height:1.1;}
.meta-card span{font-size:11px;font-weight:800;color:#6b4a86;}
.summary-box{padding:16px 18px 4px;}
.summary-box h3{margin:0 0 8px;color:#3d0f6b;font-size:17px;}
.workload-table{width:100%;border-collapse:collapse;margin-top:10px;}
.workload-table th,.workload-table td{border:1px solid #000;padding:7px 8px;font-size:12px;text-align:center;}
.workload-table th{background:#ede0f7;color:#1a0533;font-weight:900;}
.workload-table td:nth-child(3){text-align:left;}
.workload-table tfoot td{font-weight:900;background:#f9f4ff;}
.note{padding:0 18px 18px;font-size:11px;color:#6b4a86;font-weight:600;}
@media print{
    @page{size:A4 portrait;margin:8mm;}
    html,body{background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .no-print{display:none!important;}
    .report-page{width:194mm!important;max-width:none!important;margin:0 auto!important;border:1.5pt solid #000!important;border-radius:0!important;box-shadow:none!important;}
    .report-header{padding:9pt 10pt!important;border-bottom:2pt solid #C1345C!important;}
    .report-header h1{font-size:15pt!important;}
    .report-header h2{font-size:9pt!important;}
    .report-meta{grid-template-columns:repeat(4,1fr)!important;gap:5pt!important;padding:7pt!important;}
    .meta-card{padding:6pt!important;border-radius:0!important;}
    .meta-card h3{font-size:16pt!important;}
    .meta-card span{font-size:7pt!important;}
    .summary-box{padding:8pt 7pt 2pt!important;}
    .summary-box h3{font-size:9.5pt!important;margin-bottom:5pt!important;}
    .workload-table th,.workload-table td{font-size:5.8pt!important;padding:2.3pt 2.4pt!important;line-height:1.08!important;}
    .note{font-size:6.3pt!important;padding:0 7pt 7pt!important;}
}
</style>
</head>
<body>
<div class="no-print">
    <form method="GET">
        <b>Faculty Workload Summary Report</b>
        <select name="academic_year">
            <option value="2025-26" <?= $academic_year=='2025-26'?'selected':'' ?>>2025-26</option>
            <option value="2026-27" <?= $academic_year=='2026-27'?'selected':'' ?>>2026-27</option>
        </select>
        <select name="semester">
            <option value="Sem-I" <?= $semester=='Sem-I'?'selected':'' ?>>Odd / Sem-I</option>
            <option value="Sem-II" <?= $semester=='Sem-II'?'selected':'' ?>>Even / Sem-II</option>
        </select>
        <button type="submit">Generate</button>
        <button type="button" onclick="window.print()">Print / Save PDF</button>
        <a href="index.php?view=faculty&academic_year=<?= e($academic_year) ?>&semester=<?= e($semester) ?>" class="btn">Back</a>
    </form>
</div>

<section class="report-page">
    <div class="report-header">
        <h1>MIT Art, Design & Technology University</h1>
        <h2>School of Computing — Faculty Workload Summary Report</h2>
    </div>

    <div class="report-meta">
        <div class="meta-card"><h3><?= e($total_faculty) ?></h3><span>Total Faculty</span></div>
        <div class="meta-card"><h3><?= e($totals['total']) ?></h3><span>Total Weekly Load</span></div>
        <div class="meta-card"><h3><?= e($avg_load) ?></h3><span>Average Load</span></div>
        <div class="meta-card"><h3><?= e($max_load) ?></h3><span>Maximum Load</span></div>
    </div>

    <div class="summary-box">
        <h3>Academic Year: <?= e($academic_year) ?> | Semester: <?= $semester=='Sem-I'?'Odd':($semester=='Sem-II'?'Even':e($semester)) ?></h3>
        <table class="workload-table">
            <thead>
                <tr>
                    <th>Sr.</th>
                    <th>Faculty ID</th>
                    <th>Faculty Name</th>
                    <th>Designation</th>
                    <th>Abbrev.</th>
                    <th>Theory</th>
                    <th>Practical</th>
                    <th>Mini Project</th>
                    <th>Major Project</th>
                    <th>Other</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; foreach($rows as $r){ ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= e($r['faculty_uid'] ?? '') ?></td>
                    <td><?= e($r['faculty_name']) ?></td>
                    <td><?= e($r['designation'] ?? '') ?></td>
                    <td><b><?= e($r['faculty_code']) ?></b></td>
                    <td><?= intval($r['theory_load']) ?></td>
                    <td><?= intval($r['practical_load']) ?></td>
                    <td><?= intval($r['mini_project_load']) ?></td>
                    <td><?= intval($r['major_project_load']) ?></td>
                    <td><?= intval($r['other_load']) ?></td>
                    <td><b><?= intval($r['total_load']) ?></b></td>
                </tr>
                <?php } ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">Grand Total</td>
                    <td><?= $totals['theory'] ?></td>
                    <td><?= $totals['practical'] ?></td>
                    <td><?= $totals['mini'] ?></td>
                    <td><?= $totals['major'] ?></td>
                    <td><?= $totals['other'] ?></td>
                    <td><?= $totals['total'] ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="note">
        Note: Workload is calculated using distinct faculty + day + time slot. Combined lectures for multiple divisions in the same slot are counted as one workload slot only.
    </div>
</section>
</body>
</html>
