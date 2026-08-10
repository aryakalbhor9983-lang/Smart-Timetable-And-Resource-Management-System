<?php
session_start();

$conn = new mysqli(
    "sql209.infinityfree.com",
    "if0_42102472",
    "qfHgzrTdk9BM",
    "if0_42102472_university_timetable"
);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);
if(!isset($_SESSION['admin'])) die("Please login as admin first.");

function e($v){
    return htmlspecialchars($v ?? '', ENT_QUOTES);
}

function column_exists($conn, $table, $column){
    $safeTable = $conn->real_escape_string($table);
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}

function table_exists($conn, $table){
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

function ensure_col($conn, $table, $name, $ddl){
    if(table_exists($conn, $table) && !column_exists($conn, $table, $name)){
        @$conn->query("ALTER TABLE `$table` ADD COLUMN $ddl");
    }
}

function remove_subject_duplicate_constraints($conn){
    if(!table_exists($conn, 'subjects')) return;

    $res = @$conn->query("SHOW INDEX FROM subjects WHERE Non_unique=0");
    if(!$res) return;

    $indexes = [];
    while($row = $res->fetch_assoc()){
        $keyName = $row['Key_name'] ?? '';
        if($keyName === '' || strtoupper($keyName) === 'PRIMARY') continue;
        if(!isset($indexes[$keyName])) $indexes[$keyName] = [];
        $indexes[$keyName][] = $row['Column_name'] ?? '';
    }

    foreach($indexes as $keyName => $cols){
        $colsLower = array_map('strtolower', $cols);
        $related = array_intersect($colsLower, [
            'subject_code',
            'subject_name',
            'course_code',
            'course_full_name',
            'program',
            'year_name',
            'specialization',
            'academic_year',
            'semester'
        ]);

        if(!empty($related)){
            $safeKey = str_replace('`','',$keyName);
            @$conn->query("ALTER TABLE subjects DROP INDEX `$safeKey`");
        }
    }
}

function normalize_semester($sem){
    $sem = trim((string)$sem);
    if($sem === "Sem-I") return "Odd";
    if($sem === "Sem-II") return "Even";
    if(strcasecmp($sem, "odd") === 0) return "Odd";
    if(strcasecmp($sem, "even") === 0) return "Even";
    return $sem ?: "Odd";
}

/* Ensure Course Master columns exist */
ensure_col($conn, 'subjects', 'academic_year', "academic_year VARCHAR(20) NULL AFTER id");
ensure_col($conn, 'subjects', 'semester', "semester VARCHAR(20) NULL AFTER academic_year");
ensure_col($conn, 'subjects', 'program', "program VARCHAR(100) NULL AFTER semester");
ensure_col($conn, 'subjects', 'year_name', "year_name VARCHAR(20) NULL AFTER program");
ensure_col($conn, 'subjects', 'specialization', "specialization VARCHAR(100) NULL AFTER year_name");
ensure_col($conn, 'subjects', 'course_code', "course_code VARCHAR(80) NULL AFTER specialization");
ensure_col($conn, 'subjects', 'course_full_name', "course_full_name VARCHAR(255) NULL AFTER course_code");

if(!column_exists($conn, 'subjects', 'subject_code')){
    @$conn->query("ALTER TABLE subjects ADD COLUMN subject_code VARCHAR(80) NULL AFTER course_full_name");
}
if(!column_exists($conn, 'subjects', 'subject_name')){
    @$conn->query("ALTER TABLE subjects ADD COLUMN subject_name VARCHAR(255) NULL AFTER subject_code");
}
if(!column_exists($conn, 'subjects', 'subject_type')){
    @$conn->query("ALTER TABLE subjects ADD COLUMN subject_type VARCHAR(50) DEFAULT 'Theory'");
}

ensure_col($conn, 'subjects', 'credits', "credits DECIMAL(5,2) NULL AFTER subject_type");
ensure_col($conn, 'subjects', 'th_hours', "th_hours DECIMAL(5,2) DEFAULT 0 AFTER credits");
ensure_col($conn, 'subjects', 'pr_hours', "pr_hours DECIMAL(5,2) DEFAULT 0 AFTER th_hours");
ensure_col($conn, 'subjects', 'tut_hours', "tut_hours DECIMAL(5,2) DEFAULT 0 AFTER pr_hours");
ensure_col($conn, 'subjects', 'th_hours_week', "th_hours_week DECIMAL(5,2) NULL AFTER tut_hours");
ensure_col($conn, 'subjects', 'pr_hours_week', "pr_hours_week DECIMAL(5,2) NULL AFTER th_hours_week");
ensure_col($conn, 'subjects', 'tut_hours_week', "tut_hours_week DECIMAL(5,2) NULL AFTER pr_hours_week");

/* Existing old records belong to 2025-26 Odd only */
@$conn->query("UPDATE subjects SET semester='Odd' WHERE semester=CONCAT('Sem','-I')");
@$conn->query("UPDATE subjects SET semester='Even' WHERE semester=CONCAT('Sem','-II')");
@$conn->query("UPDATE subjects SET academic_year='2025-26' WHERE academic_year IS NULL OR academic_year=''");
@$conn->query("UPDATE subjects SET semester='Odd' WHERE semester IS NULL OR semester=''");

/* Duplicate Course Codes / Abbreviations must be allowed across AY/Sem/Specialization */
remove_subject_duplicate_constraints($conn);

$message = "";
$errors = [];
$imported = 0;
$inserted = 0;
$skipped = 0;

$selected_year = $_POST['academic_year'] ?? $_GET['academic_year'] ?? '2026-27';
$selected_semester = normalize_semester($_POST['semester'] ?? $_GET['semester'] ?? 'Odd');

function normalize_header($s){
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s;
}

function excel_col_to_index($letters){
    $letters = strtoupper($letters);
    $num = 0;
    for($i=0; $i<strlen($letters); $i++){
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return $num - 1;
}

function read_xlsx_first_sheet($file){
    if(!class_exists('ZipArchive')) throw new Exception("ZipArchive is not enabled on this server. Please upload CSV instead.");

    $zip = new ZipArchive();
    if($zip->open($file) !== TRUE) throw new Exception("Cannot open xlsx file.");

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

    if($sharedXml !== false){
        $sx = simplexml_load_string($sharedXml);
        if($sx){
            foreach($sx->si as $si){
                $txt = '';
                if(isset($si->t)){
                    $txt = (string)$si->t;
                } else {
                    foreach($si->r as $r){
                        $txt .= (string)$r->t;
                    }
                }
                $shared[] = $txt;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if($sheetXml === false) throw new Exception("Cannot read first worksheet.");

    $xml = simplexml_load_string($sheetXml);
    if(!$xml) throw new Exception("Invalid worksheet XML.");

    $rows = [];

    foreach($xml->sheetData->row as $row){
        $rIndex = intval($row['r']);
        $out = [];

        foreach($row->c as $c){
            $ref = (string)$c['r'];
            preg_match('/([A-Z]+)/', $ref, $m);
            $colIndex = excel_col_to_index($m[1] ?? 'A');

            $type = (string)$c['t'];
            $val = '';

            if($type === 's'){
                $idx = intval($c->v);
                $val = $shared[$idx] ?? '';
            } elseif($type === 'inlineStr'){
                if(isset($c->is->t)){
                    $val = (string)$c->is->t;
                } else {
                    foreach($c->is->r as $r){
                        $val .= (string)$r->t;
                    }
                }
            } else {
                $val = isset($c->v) ? (string)$c->v : '';
            }

            $out[$colIndex] = trim($val);
        }

        if(count($out) > 0){
            $max = max(array_keys($out));
            $line = [];
            for($i=0; $i<=$max; $i++){
                $line[] = $out[$i] ?? '';
            }
            $rows[$rIndex] = $line;
        }
    }

    $zip->close();
    ksort($rows);

    return array_values($rows);
}

function read_csv_file($file){
    $rows = [];

    if(($h = fopen($file, 'r')) !== false){
        while(($data = fgetcsv($h)) !== false){
            $rows[] = $data;
        }
        fclose($h);
    }

    return $rows;
}

function val($row, $map, $key){
    $idx = $map[$key] ?? null;
    return $idx === null ? '' : trim((string)($row[$idx] ?? ''));
}

function numval_clean($v){
    $v = trim((string)$v);
    if($v === '') return 0;
    return floatval(preg_replace('/[^0-9.\-]/', '', $v));
}

if(isset($_POST['upload_subject_master'])){
    $selected_year = trim($_POST['academic_year'] ?? '2026-27');
    $selected_semester = normalize_semester($_POST['semester'] ?? 'Odd');

    if(!isset($_FILES['subject_file']) || $_FILES['subject_file']['error'] !== UPLOAD_ERR_OK){
        $errors[] = "Please choose a valid .xlsx or .csv file.";
    } else {
        $tmp = $_FILES['subject_file']['tmp_name'];
        $name = $_FILES['subject_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        try{
            if($ext === 'xlsx'){
                $rows = read_xlsx_first_sheet($tmp);
            } elseif($ext === 'csv'){
                $rows = read_csv_file($tmp);
            } else {
                throw new Exception("Only .xlsx and .csv files are supported.");
            }

            $headerRowIndex = -1;
            $headerMap = [];

            foreach($rows as $i => $row){
                $norms = array_map('normalize_header', $row);

                if(
                    in_array('srno', $norms) &&
                    (
                        in_array('program', $norms) ||
                        in_array('coursecode', $norms) ||
                        in_array('fullcoursename', $norms)
                    )
                ){
                    $headerRowIndex = $i;

                    foreach($norms as $ci => $h){
                        if($h === 'srno') $headerMap['sr_no'] = $ci;
                        if($h === 'program') $headerMap['program'] = $ci;
                        if($h === 'year') $headerMap['year_name'] = $ci;
                        if($h === 'specialization') $headerMap['specialization'] = $ci;

                        if($h === 'coursecode') $headerMap['course_code'] = $ci;

                        if(
                            $h === 'fullcoursename' ||
                            $h === 'coursefullname' ||
                            $h === 'fullname' ||
                            $h === 'nameofcoursefullname'
                        ){
                            $headerMap['course_full_name'] = $ci;
                        }

                        if(
                            $h === 'abbreviation' ||
                            $h === 'courseabbreviation' ||
                            $h === 'nameofcourseabbreviation'
                        ){
                            $headerMap['course_abbreviation'] = $ci;
                        }

                        if($h === 'type' || $h === 'coursetype') $headerMap['type'] = $ci;
                        if($h === 'noofcredits' || $h === 'credits') $headerMap['credits'] = $ci;
                        if($h === 'thhrsweek' || $h === 'thhrswk' || $h === 'theoryhoursperweek') $headerMap['th_hours'] = $ci;
                        if($h === 'prhrsweek' || $h === 'prhrswk' || $h === 'practicalhoursperweek') $headerMap['pr_hours'] = $ci;
                        if($h === 'tuthrsweek' || $h === 'tuthrswk' || $h === 'tutorialhoursperweek') $headerMap['tut_hours'] = $ci;
                    }

                    break;
                }
            }

            if($headerRowIndex < 0) throw new Exception("Header row not found. Do not change template headers.");

            foreach(['program','year_name','specialization','course_code','course_full_name','course_abbreviation','type'] as $required){
                if(!array_key_exists($required, $headerMap)){
                    throw new Exception("Missing required column: $required");
                }
            }

            /*
                IMPORTANT MAPPING:
                Template Course Code          -> subjects.course_code
                Template Full Course Name     -> subjects.course_full_name + subjects.subject_name
                Template Abbreviation         -> subjects.subject_code

                No UPDATE / duplicate check here.
                Every upload inserts fresh rows for selected Academic Year + Semester.
            */
            $stmtIns = $conn->prepare("
                INSERT INTO subjects
                (
                    academic_year,
                    semester,
                    program,
                    year_name,
                    specialization,
                    course_code,
                    course_full_name,
                    subject_code,
                    subject_name,
                    subject_type,
                    credits,
                    th_hours,
                    pr_hours,
                    tut_hours,
                    th_hours_week,
                    pr_hours_week,
                    tut_hours_week
                )
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            for($i = $headerRowIndex + 1; $i < count($rows); $i++){
                $row = $rows[$i];

                $program = trim(val($row, $headerMap, 'program'));
                $year_name = strtoupper(trim(val($row, $headerMap, 'year_name')));
                $specialization = strtoupper(trim(val($row, $headerMap, 'specialization')));
                if($specialization === 'NULL') $specialization = '';

                $course_code = strtoupper(trim(val($row, $headerMap, 'course_code')));
                $full_name = trim(val($row, $headerMap, 'course_full_name'));
                $abbr = strtoupper(trim(val($row, $headerMap, 'course_abbreviation')));
                $type = trim(val($row, $headerMap, 'type'));

                $credits = numval_clean(val($row, $headerMap, 'credits'));
                $th = numval_clean(val($row, $headerMap, 'th_hours'));
                $pr = numval_clean(val($row, $headerMap, 'pr_hours'));
                $tut = numval_clean(val($row, $headerMap, 'tut_hours'));

                if($course_code === '' && $abbr === '' && $full_name === ''){
                    continue;
                }

                if($abbr === '' || $full_name === ''){
                    $errors[] = "Row ".($i+1).": Full Course Name and Abbreviation are required.";
                    $skipped++;
                    continue;
                }

                if($course_code === ''){
                    $course_code = $abbr;
                }

                if($type === '') $type = 'Theory';
                $type = ucfirst(strtolower($type));
                if(!in_array($type, ['Theory','Practical','Other','Mini project','Major project'])){
                    $type = 'Theory';
                }

                $subject_name = $full_name;

                $stmtIns->bind_param(
                    'ssssssssssddddddd',
                    $selected_year,
                    $selected_semester,
                    $program,
                    $year_name,
                    $specialization,
                    $course_code,
                    $full_name,
                    $abbr,
                    $subject_name,
                    $type,
                    $credits,
                    $th,
                    $pr,
                    $tut,
                    $th,
                    $pr,
                    $tut
                );

                if($stmtIns->execute()){
                    $inserted++;
                    $imported++;
                } else {
                    $errors[] = "Row ".($i+1).": Could not insert. ".$stmtIns->error;
                    $skipped++;
                }
            }

            $message = "Course Master import completed for $selected_year $selected_semester. Inserted: $inserted, Skipped: $skipped, Total processed: $imported.";
        } catch(Exception $ex){
            $errors[] = $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Course Master Import</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
body{
    font-family:Poppins,Arial,sans-serif;
    background:#f5f0fa;
    color:#1a0533;
    margin:0;
    padding:30px;
}
.card{
    max-width:920px;
    margin:auto;
    background:#fff;
    border:1px solid #d4b8ea;
    border-radius:14px;
    padding:24px;
    box-shadow:0 4px 18px rgba(90,31,140,.12);
}
h1{
    color:#3d0f6b;
    margin-top:0;
}
.note{
    background:#ede0f7;
    border-left:5px solid #7b2fb5;
    padding:12px;
    border-radius:8px;
    margin-bottom:16px;
    line-height:1.6;
}
.form-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
    margin:12px 0;
}
label{
    font-weight:800;
    color:#3d0f6b;
    display:block;
    margin-bottom:6px;
}
select,
input[type=file]{
    padding:10px;
    border:1px solid #c9a0e0;
    border-radius:8px;
    background:white;
    width:100%;
    font-family:Poppins,Arial,sans-serif;
    box-sizing:border-box;
}
.btn,
button{
    background:#7b2fb5;
    color:white;
    border:0;
    border-radius:8px;
    padding:10px 16px;
    font-weight:800;
    text-decoration:none;
    cursor:pointer;
    display:inline-block;
    margin:4px 4px 4px 0;
}
.btn2{
    background:#C1345C;
}
.success{
    background:#dcfce7;
    color:#166534;
    padding:12px;
    border-radius:8px;
    margin:12px 0;
    font-weight:700;
}
.error{
    background:#fee2e2;
    color:#991b1b;
    padding:12px;
    border-radius:8px;
    margin:12px 0;
    font-weight:700;
}
.small{
    font-size:12px;
    color:#6b4a86;
    font-weight:700;
}
</style>
</head>
<body>
<div class="card">
    <h1>📘 Course Master Import</h1>

    <p class="note">
        Select the <b>Academic Year</b> and <b>Semester</b>, then upload the Course Master template.
        <br>
        Every uploaded row will be inserted fresh for the selected year/semester.
        Duplicate course codes are allowed across different programs, years and specializations.
        <br>
        Correct mapping:
        <b>Course Code → course_code</b>,
        <b>Abbreviation → subject_code</b>,
        <b>Full Course Name → subject_name</b>.
    </p>

    <?php if($message){ ?><div class="success"><?= e($message) ?></div><?php } ?>
    <?php foreach($errors as $er){ ?><div class="error"><?= e($er) ?></div><?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <div>
                <label>Academic Year</label>
                <select name="academic_year" required>
                    <?php foreach(['2025-26','2026-27','2027-28','2028-29'] as $yr){ ?>
                        <option value="<?= e($yr) ?>" <?= $selected_year==$yr?'selected':'' ?>><?= e($yr) ?></option>
                    <?php } ?>
                </select>
            </div>

            <div>
                <label>Semester</label>
                <select name="semester" required>
                    <?php foreach(['Odd','Even'] as $sem){ ?>
                        <option value="<?= e($sem) ?>" <?= $selected_semester==$sem?'selected':'' ?>><?= e($sem) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <label>Upload Course Master File</label>
        <input type="file" name="subject_file" accept=".xlsx,.csv" required>

        <button type="submit" name="upload_subject_master">⬆ Upload Course Master</button>
        <a class="btn btn2" href="Subject_Master_Template.xlsx" download>⬇ Download Template</a>
        <a class="btn" href="index.php?view=common_info&section=subject&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">Back to Course Master</a>
    </form>

    <p class="small">
        Note: If you accidentally imported wrong 2026-27 Odd rows earlier, delete those rows from subjects where academic_year='2026-27' and semester='Odd', then upload again.
    </p>
</div>
</body>
</html>
