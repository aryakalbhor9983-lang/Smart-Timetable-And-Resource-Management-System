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
function q1($conn, $sql, $default=''){
    $res = @$conn->query($sql);
    if(!$res) return $default;
    $row = $res->fetch_assoc();
    if(!$row) return $default;
    return array_values($row)[0] ?? $default;
}
function table_exists($conn, $table){
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}
function column_exists($conn, $table, $column){
    $safeTable = $conn->real_escape_string($table);
    $safeCol = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeCol'");
    return $res && $res->num_rows > 0;
}
function ensure_column($conn, $table, $column, $definition){
    if(table_exists($conn, $table) && !column_exists($conn, $table, $column)){
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
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
        /* Drop subject/course uniqueness because same course may exist across programs/specializations */
        $related = array_intersect($colsLower, ['subject_code','subject_name','course_code','course_full_name','program','year_name','specialization']);
        if(!empty($related)){
            $safeKey = str_replace('`','',$keyName);
            @$conn->query("ALTER TABLE subjects DROP INDEX `$safeKey`");
        }
    }
}
function subject_type_from_code($code, $name=''){
    $s = strtoupper(trim(($code ?? '').' '.($name ?? '')));
    if(strpos($s,'PBL') !== false || strpos($s,'PROJECT') !== false) return 'Mini Project';
    if(strpos($s,'MAJOR') !== false) return 'Major Project';
    if(strpos($s,'LAB') !== false || preg_match('/\b(DSL|DMSL|PAIL|EEL|PLL|PAL)\b/', $s)) return 'Practical';
    if(strpos($s,'LIBRARY') !== false || strpos($s,'MOOC') !== false || strpos($s,'MENTOR') !== false || strpos($s,'EXPERT SESSION') !== false || strpos($s,'REMEDIAL') !== false) return 'Other';
    return 'Theory';
}

function is_clean_subject_for_legend($code, $name=''){
    $code = trim((string)$code);
    $name = trim((string)$name);
    $uCode = strtoupper($code);
    $uName = strtoupper($name);
    if($code === '' || $name === '') return false;
    if($uCode === $uName) return false;
    if(in_array($uCode, ['A','B','ONLINE','NULL','NONE','NAN','PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY'])) return false;
    if(preg_match('/^[NS][0-9]{3,4}$/', $uCode)) return false;
    if(preg_match('/^[0-9]+(:[0-9]+)?(AM|PM)?(\s*TO\s*[0-9]+)?$/', $uCode)) return false;
    if(strpos($uCode, 'WEEK') !== false || strpos($uCode, 'PM TO') !== false || strpos($uCode, 'PM') === 0) return false;
    if(strpos($uName, 'WEEK') !== false || strpos($uName, 'PM TO') !== false) return false;
    return true;
}

function is_clean_faculty_for_legend($code, $name=''){
    $code = trim((string)$code);
    $name = trim((string)$name);
    $uCode = strtoupper($code);
    $uName = strtoupper($name);
    if($code === '' || $name === '') return false;
    if($uCode === $uName) return false;
    if(in_array($uCode, ['ONLINE','NULL','NONE','NAN','PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY'])) return false;
    if(preg_match('/^[NS][0-9]{3,4}$/', $uCode)) return false;
    if(strpos($uCode, 'PM') !== false || strpos($uCode, 'WEEK') !== false) return false;
    return true;
}


function format_wef_date_display($dateValue){
    $dateValue = trim((string)$dateValue);
    if($dateValue === '') return '';
    $ts = strtotime($dateValue);
    if($ts === false) return $dateValue;
    return date('jS F Y', $ts);
}

function timetable_entries_merge_key($entries){
    if(!is_array($entries) || count($entries) == 0) return ['allowed'=>false, 'key'=>''];

    $allowed = false;
    $parts = [];

    foreach($entries as $entry){
        $subject = strtoupper(trim((string)($entry['subject_code'] ?? '')));
        $subjectName = strtoupper(trim((string)($entry['subject_name'] ?? '')));
        $subjectType = strtoupper(trim((string)($entry['subject_type'] ?? '')));
        $faculty = strtoupper(trim((string)($entry['faculty_code'] ?? '')));
        $room = strtoupper(trim((string)($entry['room_code'] ?? '')));
        $batch = strtoupper(trim((string)($entry['batch'] ?? '')));
        $combo = $subject.' '.$subjectName.' '.$subjectType;

        if(
            $subjectType === 'PRACTICAL' ||
            $batch !== '' ||
            strpos($combo,'LAB') !== false ||
            strpos($combo,'TUT') !== false ||
            strpos($subject,'PBL') !== false ||
            strpos($subject,'PAL') !== false ||
            strpos($subject,'SHD') !== false ||
            strpos($subject,'SISM') !== false ||
            strpos($subject,'MDM') !== false ||
            in_array($subject, ['EXPERT SESSION','LIBRARY'])
        ){
            $allowed = true;
        }

        /* Merge key should not depend on faculty_code because some continuation rows
           may have faculty_id/faculty_code missing after relinking. Rendering below
           copies faculty display from adjacent matching practical slot. */
        $parts[] = $subject.'|'.$room.'|'.$batch;
    }

    sort($parts);
    return ['allowed'=>$allowed, 'key'=>implode('##', $parts)];
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

$message = "";
if(isset($_POST['login'])){
    $username = $_POST['username'] ?? '';
    $password = hash("sha256", $_POST['password'] ?? '');
    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username=? AND password_hash=?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    if($stmt->get_result()->num_rows > 0){
        $_SESSION['admin'] = $username;
        $message = "Admin login successful.";
    } else {
        $message = "Invalid login.";
    }
}
if(isset($_GET['logout'])){ session_destroy(); header("Location:index.php"); exit; }

$view = $_GET['view'] ?? '';
$analytics_context = $_GET['analytics_context'] ?? '';
if(!in_array($analytics_context, ['admin','portal'])) $analytics_context = '';

/* Old in-page analytics has been replaced by separate analytics.php */
if($view === 'analytics'){
    $qs = $_GET;
    unset($qs['view']);
    $qs['context'] = $analytics_context ?: (isset($_SESSION['admin']) ? 'admin' : 'portal');
    header('Location: analytics.php?' . http_build_query($qs));
    exit;
}
/* Removed duplicate admin pages */
if(in_array($view, ['bulk_import','bulk_export','resources'])){
    $view = 'admin_dashboard';
}

$selected_division = $_GET['division'] ?? '';
$selected_department = $_GET['department'] ?? '';
$selected_program = $_GET['program_level'] ?? '';
$selected_degree = $_GET['degree_type'] ?? '';
$selected_year_name = $_GET['year_name'] ?? '';
$selected_specialization = $_GET['specialization'] ?? '';
$selected_faculty = $_GET['faculty'] ?? '';
$selected_classroom = $_GET['classroom'] ?? '';
$selected_resource_type = $_GET['resource_type'] ?? 'classroom';
if(!in_array($selected_resource_type, ['classroom','lab'])) $selected_resource_type = 'classroom';

/* Free Physical Resources filter: classroom / lab / tutorial */
$selected_free_resource_type = $_GET['free_resource_type'] ?? 'classroom';
if(!in_array($selected_free_resource_type, ['classroom','lab','tutorial'])) $selected_free_resource_type = 'classroom';
$selected_day = $_GET['day'] ?? '';
$selected_slot = $_GET['slot'] ?? '';
$selected_free_day = $_GET['free_day'] ?? '';
$selected_free_slot = $_GET['free_slot'] ?? '';
$selected_year = $_GET['academic_year'] ?? '';
$selected_semester = $_GET['semester'] ?? '';

/* Semester is now stored and displayed as Odd / Even everywhere */
if($selected_semester === ('Sem'.'-I')) $selected_semester = 'Odd';
if($selected_semester === ('Sem'.'-II')) $selected_semester = 'Even';


/* Convert any old stored semester values to Odd / Even */
foreach(['timetable_entries','timetable_signatures','class_teacher_assignments'] as $semTable){
    if(table_exists($conn, $semTable) && column_exists($conn, $semTable, 'semester')){
        @$conn->query("UPDATE `$semTable` SET semester='Odd' WHERE semester=CONCAT('Sem','-I')");
        @$conn->query("UPDATE `$semTable` SET semester='Even' WHERE semester=CONCAT('Sem','-II')");
    }
}

if(table_exists($conn, 'divisions')){
    if(!column_exists($conn, 'divisions', 'program_level')) $conn->query("ALTER TABLE divisions ADD COLUMN program_level VARCHAR(20) DEFAULT 'UG'");
    if(!column_exists($conn, 'divisions', 'department')) $conn->query("ALTER TABLE divisions ADD COLUMN department VARCHAR(50) DEFAULT 'CSE'");
    if(!column_exists($conn, 'divisions', 'degree_type')) $conn->query("ALTER TABLE divisions ADD COLUMN degree_type VARCHAR(50) NULL");
    if(!column_exists($conn, 'divisions', 'year_name')) $conn->query("ALTER TABLE divisions ADD COLUMN year_name VARCHAR(20) NULL");
    if(!column_exists($conn, 'divisions', 'specialization')) $conn->query("ALTER TABLE divisions ADD COLUMN specialization VARCHAR(80) NULL");
    $conn->query("UPDATE divisions SET program_level='UG' WHERE program_level IS NULL OR program_level=''");
    $conn->query("UPDATE divisions SET department='CSE' WHERE department IS NULL OR department=''");
    $conn->query("UPDATE divisions SET year_name='FY' WHERE (year_name IS NULL OR year_name='') AND division_name LIKE 'FY%'");
    $conn->query("UPDATE divisions SET year_name='SY' WHERE (year_name IS NULL OR year_name='') AND division_name LIKE 'SY%'");
    $conn->query("UPDATE divisions SET year_name='TY' WHERE (year_name IS NULL OR year_name='') AND division_name LIKE 'TY%'");
    $conn->query("UPDATE divisions SET year_name='LY' WHERE (year_name IS NULL OR year_name='') AND division_name LIKE 'LY%'");
    $conn->query("UPDATE divisions SET specialization='AIA' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%AIA%'");
    $conn->query("UPDATE divisions SET specialization='AIEC' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%AIEC%'");
    $conn->query("UPDATE divisions SET specialization='CORE' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%CORE%'");
    $conn->query("UPDATE divisions SET specialization='CC' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%CC%'");
    $conn->query("UPDATE divisions SET specialization='BDCE' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%BDCE%'");
    $conn->query("UPDATE divisions SET specialization='CSF' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%CSF%'");
    $conn->query("UPDATE divisions SET specialization='BT' WHERE (specialization IS NULL OR specialization='') AND UPPER(division_name) LIKE '%BT%'");
}

$hasFacultyUid = column_exists($conn, 'faculties', 'faculty_uid');
$hasSubjectType = column_exists($conn, 'subjects', 'subject_type');
$hasDivDept = column_exists($conn, 'divisions', 'department');
$hasDivProgram = column_exists($conn, 'divisions', 'program_level');
$hasDivDegree = column_exists($conn, 'divisions', 'degree_type');
$hasDivYear = column_exists($conn, 'divisions', 'year_name');
$hasDivSpec = column_exists($conn, 'divisions', 'specialization');
$hasClassTeacher = column_exists($conn, 'divisions', 'class_teacher');
$hasClassTeacherEmail = column_exists($conn, 'divisions', 'class_teacher_email');
$hasClassTeacherContact = column_exists($conn, 'divisions', 'class_teacher_contact');
$hasWef = column_exists($conn, 'divisions', 'wef_date');
$hasSignatures = table_exists($conn, 'timetable_signatures');
$hasFacultyDesignation = column_exists($conn, 'faculties', 'designation');
$hasFacultyEmail = column_exists($conn, 'faculties', 'email');
$hasFacultyContact = column_exists($conn, 'faculties', 'contact_no');
$hasFacultySeating = column_exists($conn, 'faculties', 'seating_location');
$hasClassResourceType = column_exists($conn, 'classrooms', 'resource_type');
$hasClassIncharge = column_exists($conn, 'classrooms', 'classroom_incharge');
$hasClassCapacity = column_exists($conn, 'classrooms', 'capacity');
$hasClassBenches = column_exists($conn, 'classrooms', 'no_of_benches');
$hasClassSmartBoard = column_exists($conn, 'classrooms', 'smart_board');
$hasClassLcd = column_exists($conn, 'classrooms', 'lcd_projector');
$hasLabDetails = table_exists($conn, 'lab_details');

/* ===================== MASTER TABLE UPGRADES FOR INLINE EDIT + NEW TEMPLATES ===================== */
/* Faculty Master upgraded columns */
/* Class Teacher List columns */
ensure_column($conn, 'divisions', 'class_teacher', "VARCHAR(255) NULL");
ensure_column($conn, 'divisions', 'class_teacher_abbrev', "VARCHAR(50) NULL");
ensure_column($conn, 'divisions', 'class_teacher_emp_id', "VARCHAR(50) NULL");
ensure_column($conn, 'divisions', 'class_teacher_email', "VARCHAR(255) NULL");
ensure_column($conn, 'divisions', 'class_teacher_contact', "VARCHAR(50) NULL");
ensure_column($conn, 'divisions', 'wef_date', "VARCHAR(50) NULL");

/* AY/Sem wise W.E.F date for each division timetable */
@$conn->query("
CREATE TABLE IF NOT EXISTS timetable_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_id INT NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    wef_date VARCHAR(50) NULL,
    prepared_by VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_timetable_settings (division_id, academic_year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
ensure_column($conn, 'timetable_settings', 'prepared_by', "VARCHAR(150) NULL");


ensure_column($conn, 'faculties', 'academic_designation', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'profile_designation', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'department', "VARCHAR(50) NULL");
ensure_column($conn, 'faculties', 'specialization', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'cabin_no', "VARCHAR(100) NULL");
ensure_column($conn, 'faculties', 'role_type', "VARCHAR(50) NULL");
ensure_column($conn, 'faculties', 'email', "VARCHAR(150) NULL");
ensure_column($conn, 'faculties', 'contact_no', "VARCHAR(30) NULL");
ensure_column($conn, 'faculties', 'seating_location', "VARCHAR(150) NULL");
ensure_column($conn, 'faculties', 'is_active', "CHAR(1) NOT NULL DEFAULT 'Y'");
@$conn->query("UPDATE faculties SET is_active='Y' WHERE is_active IS NULL OR is_active=''");


/* Course Master template columns */
ensure_column($conn, 'subjects', 'program', "VARCHAR(100) NULL");
ensure_column($conn, 'subjects', 'year_name', "VARCHAR(20) NULL");
ensure_column($conn, 'subjects', 'specialization', "VARCHAR(100) NULL");
ensure_column($conn, 'subjects', 'course_code', "VARCHAR(80) NULL");
ensure_column($conn, 'subjects', 'course_full_name', "VARCHAR(255) NULL");
ensure_column($conn, 'subjects', 'subject_code', "VARCHAR(50) NULL");
ensure_column($conn, 'subjects', 'credits', "DECIMAL(4,1) NULL");
ensure_column($conn, 'subjects', 'th_hours_week', "DECIMAL(4,1) NULL");
ensure_column($conn, 'subjects', 'pr_hours_week', "DECIMAL(4,1) NULL");
ensure_column($conn, 'subjects', 'tut_hours_week', "DECIMAL(4,1) NULL");
ensure_column($conn, 'subjects', 'academic_year', "VARCHAR(20) NULL");
ensure_column($conn, 'subjects', 'semester', "VARCHAR(20) NULL");

/* Course Master is Academic Year + Semester wise.
   Existing/current courses are treated as 2025-26 Odd only.
   Therefore 2026-27 Odd remains blank until fresh upload/add. */
if(table_exists($conn, 'subjects')){
    @$conn->query("UPDATE subjects SET semester='Odd' WHERE semester=CONCAT('Sem','-I')");
    @$conn->query("UPDATE subjects SET semester='Even' WHERE semester=CONCAT('Sem','-II')");
    @$conn->query("UPDATE subjects SET academic_year='2025-26' WHERE academic_year IS NULL OR academic_year=''");
    @$conn->query("UPDATE subjects SET semester='Odd' WHERE semester IS NULL OR semester=''");
}

/* Allow duplicate course entries across programs / years / specializations */
remove_subject_duplicate_constraints($conn);


$hasClassTeacher = column_exists($conn, 'divisions', 'class_teacher');
$hasClassTeacherAbbrev = column_exists($conn, 'divisions', 'class_teacher_abbrev');
$hasClassTeacherEmpId = column_exists($conn, 'divisions', 'class_teacher_emp_id');
$hasClassTeacherEmail = column_exists($conn, 'divisions', 'class_teacher_email');
$hasClassTeacherContact = column_exists($conn, 'divisions', 'class_teacher_contact');

/* Physical Resources Master upgraded columns */
ensure_column($conn, 'classrooms', 'wifi_available', "ENUM('Y','N') DEFAULT 'Y'");
ensure_column($conn, 'classrooms', 'block_name', "VARCHAR(100) NULL");
ensure_column($conn, 'classrooms', 'floor_no', "VARCHAR(20) NULL");
ensure_column($conn, 'classrooms', 'area_sq_meter', "DECIMAL(10,2) NULL");

ensure_column($conn, 'faculty_block_details', 'faculty_block_type', "VARCHAR(120) NULL");
ensure_column($conn, 'faculty_block_details', 'incharge', "VARCHAR(150) NULL");

@$conn->query("
CREATE TABLE IF NOT EXISTS admin_block_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_block_name VARCHAR(150) NOT NULL,
    location VARCHAR(100) NOT NULL UNIQUE,
    incharge VARCHAR(150) NULL,
    block_name VARCHAR(100) NULL,
    floor_no VARCHAR(20) NULL,
    wifi_available ENUM('Y','N') DEFAULT 'Y',
    area_sq_meter DECIMAL(10,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");


/* ===================== DEPARTMENT INFO MASTER TABLES ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS leadership_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    designation VARCHAR(150) NOT NULL,
    person_name VARCHAR(150) NULL,
    email VARCHAR(150) NULL,
    contact_no VARCHAR(30) NULL,
    display_order INT DEFAULT 99,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

@$conn->query("
CREATE TABLE IF NOT EXISTS department_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL,
    department_code VARCHAR(50) NULL,
    hod_name VARCHAR(150) NULL,
    programs_offered VARCHAR(250) NULL,
    years_offered VARCHAR(100) NULL,
    specializations VARCHAR(250) NULL,
    division_details VARCHAR(250) NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

@$conn->query("
CREATE TABLE IF NOT EXISTS program_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_name VARCHAR(150) NOT NULL,
    duration VARCHAR(50) NULL,
    department_name VARCHAR(100) NULL,
    specialization VARCHAR(100) NULL,
    years_offered VARCHAR(100) NULL,
    display_order INT DEFAULT 99,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

@$conn->query("
CREATE TABLE IF NOT EXISTS intake_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL,
    program_name VARCHAR(150) NULL,
    year_name VARCHAR(20) NULL,
    specialization VARCHAR(100) NULL,
    no_of_divisions VARCHAR(50) NULL,
    division_strength VARCHAR(50) NULL,
    batch_a_strength VARCHAR(50) NULL,
    batch_b_strength VARCHAR(50) NULL,
    display_order INT DEFAULT 99,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Seed once with known structure. Unknown numbers are intentionally kept blank for admin edit. */
if(table_exists($conn, 'leadership_master') && intval(q1($conn, "SELECT COUNT(*) FROM leadership_master", 0)) == 0){
    @$conn->query("
        INSERT INTO leadership_master (designation, person_name) VALUES
        ('Pro Vice Chancellor', 'Dr. Ramachandra Pujeri'),
        ('Dean, School of Computing', 'Prof. Dr. Ganesh R. Pathak'),
        ('Associate Dean (Academics)', 'Prof. Dr. Shraddha P. Phansalkar'),
        ('Associate Dean - Applied Sciences & Humanities (First Year)', 'Prof. Dr. Shalini Garg'),
        ('Associate Dean - Research', 'Prof. Dr. Ramesh Mali'),
        ('Associate Dean - Administration', 'Dr. Madhukar Nimbalkar'),
        ('Associate Dean - Research', 'Dr. Jayashree Prasad')
    ");
}

if(table_exists($conn, 'department_master') && intval(q1($conn, "SELECT COUNT(*) FROM department_master", 0)) == 0){
    @$conn->query("
        INSERT INTO department_master
        (department_name, department_code, hod_name, programs_offered, years_offered, specializations, division_details) VALUES
        ('ASH Department', 'ASH', 'Prof. Dr. Shalini Vineet Garg', 'First Year Engineering', 'FY', 'NULL', 'FY Divisions'),
        ('CSE - Artificial Intelligence & Analytics', 'CSE AIA', 'Prof. Dr. Jayashree Prasad', 'UG BTech CSE, PG MSc CSE', 'TY, LY, PG', 'AIA, AIML', 'AIA Divisions'),
        ('CSE - Cloud Computing', 'CSE CC', 'Prof. Dr. Nandkumar Kulkarni', 'UG BTech CSE', 'TY, LY', 'CC', 'CC Divisions'),
        ('CSE - Core', 'CSE CORE', 'Prof. Dr. Suvarna Pawar', 'UG BTech CSE', 'SY, TY, LY', 'CORE', 'SY/TY/LY CORE Divisions'),
        ('CSE - Cyber Security & Forensics', 'CSE CSF', 'Prof. Dr. Naagesh Jadhav', 'UG BTech CSE', 'TY, LY', 'CSF', 'CSF Divisions'),
        ('CSE - First Year & PG', 'CSE FY PG', 'Prof. Dr. Prasenjeet Patil', 'UG BTech CSE, PG MTech CSE, PG MSc CSE', 'FY, PG', 'ISA, AIML', 'FY/PG Divisions'),
        ('EEE Department', 'EEE', 'Prof. Dr. Ramesh Mali', 'UG BTech EEE', 'SY, TY, LY', 'EEE', 'EEE Divisions'),
        ('IT Department', 'IT', 'Dr. Prashant Dhotre', 'UG BTech IT, PG MTech IT', 'SY, TY, LY, PG', 'DA, SMAD, IT, CS', 'IT Divisions')
    ");
}

if(table_exists($conn, 'program_master') && intval(q1($conn, "SELECT COUNT(*) FROM program_master", 0)) == 0){
    @$conn->query("
        INSERT INTO program_master
        (program_name, duration, department_name, specialization, years_offered) VALUES
        ('UG BTech CSE', '4 Years', 'CSE', 'CORE, AIA, AIEC, CC, BDCE, CSF, BT, DE', 'SY, TY, LY'),
        ('UG BTech IT', '4 Years', 'IT', 'DA, SMAD, IT', 'SY, TY, LY'),
        ('PG MTech CSE', '2 Years', 'CSE', 'ISA', 'FY, SY'),
        ('PG MTech IT', '2 Years', 'IT', 'CS', 'FY, SY'),
        ('PG MSc CSE', '2 Years', 'CSE', 'AIML', 'FY, SY')
    ");
}

if(table_exists($conn, 'intake_master') && intval(q1($conn, "SELECT COUNT(*) FROM intake_master", 0)) == 0){
    @$conn->query("
        INSERT INTO intake_master
        (department_name, program_name, year_name, specialization, no_of_divisions, division_strength, batch_a_strength, batch_b_strength) VALUES
        ('ASH', 'UG BTech CSE / UG BTech IT', 'FY', 'NULL', '', '', '', ''),
        ('CSE CORE', 'UG BTech CSE', 'SY', 'CORE', '', '', '', ''),
        ('CSE CORE', 'UG BTech CSE', 'TY', 'CORE', '', '', '', ''),
        ('CSE CORE', 'UG BTech CSE', 'LY', 'CORE', '', '', '', ''),
        ('CSE AIA', 'UG BTech CSE', 'TY', 'AIA', '', '', '', ''),
        ('CSE AIA', 'UG BTech CSE', 'LY', 'AIA', '', '', '', ''),
        ('CSE CC', 'UG BTech CSE', 'TY', 'CC', '', '', '', ''),
        ('CSE CC', 'UG BTech CSE', 'LY', 'CC', '', '', '', ''),
        ('CSE CSF', 'UG BTech CSE', 'TY', 'CSF', '', '', '', ''),
        ('CSE CSF', 'UG BTech CSE', 'LY', 'CSF', '', '', '', ''),
        ('IT', 'UG BTech IT', 'SY', 'IT', '', '', '', ''),
        ('IT', 'UG BTech IT', 'TY', 'IT', '', '', '', ''),
        ('IT', 'UG BTech IT', 'LY', 'IT', '', '', '', ''),
        ('CSE', 'PG MTech CSE', 'PG', 'ISA', '', '', '', ''),
        ('IT', 'PG MTech IT', 'PG', 'CS', '', '', '', ''),
        ('CSE', 'PG MSc CSE', 'PG', 'AIML', '', '', '', '')
    ");
}



/* ===================== UPDATED DEPARTMENT INFO STRUCTURE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS year_division_structure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(50) NOT NULL,
    program_name VARCHAR(150) NOT NULL,
    year_name VARCHAR(30) NOT NULL,
    specialization VARCHAR(80) NULL,
    no_of_divisions VARCHAR(50) NULL,
    practical_batches VARCHAR(30) NULL,
    batch_strength VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Ensure clean Department Master rows for CSE, IT, ASH without disturbing other database tables */
if(table_exists($conn, 'department_master')){
    @$conn->query("
        INSERT INTO department_master (department_name, department_code, hod_name, programs_offered)
        SELECT 'CSE', 'CSE', '', 'UG BTech CSE, PG MTech CSE, PG MSc CSE'
        WHERE NOT EXISTS (SELECT 1 FROM department_master WHERE department_code='CSE' OR department_name='CSE')
    ");
    @$conn->query("
        INSERT INTO department_master (department_name, department_code, hod_name, programs_offered)
        SELECT 'IT', 'IT', '', 'UG BTech IT, PG MTech IT'
        WHERE NOT EXISTS (SELECT 1 FROM department_master WHERE department_code='IT' OR department_name='IT')
    ");
    @$conn->query("
        INSERT INTO department_master (department_name, department_code, hod_name, programs_offered)
        SELECT 'ASH', 'ASH', '', 'First Year Engineering'
        WHERE NOT EXISTS (SELECT 1 FROM department_master WHERE department_code='ASH' OR department_name='ASH')
    ");
}

/* Seed program structure rows if missing */
if(table_exists($conn, 'program_master')){
    @$conn->query("INSERT INTO program_master (program_name, duration, department_name, years_offered)
        SELECT 'UG BTech CSE','4 Years','CSE','SY, TY, LY'
        WHERE NOT EXISTS (SELECT 1 FROM program_master WHERE program_name='UG BTech CSE')");
    @$conn->query("INSERT INTO program_master (program_name, duration, department_name, years_offered)
        SELECT 'UG BTech IT','4 Years','IT','SY, TY, LY'
        WHERE NOT EXISTS (SELECT 1 FROM program_master WHERE program_name='UG BTech IT')");
    @$conn->query("INSERT INTO program_master (program_name, duration, department_name, years_offered)
        SELECT 'PG MTech CSE','2 Years','CSE','FY, SY'
        WHERE NOT EXISTS (SELECT 1 FROM program_master WHERE program_name='PG MTech CSE')");
    @$conn->query("INSERT INTO program_master (program_name, duration, department_name, years_offered)
        SELECT 'PG MTech IT','2 Years','IT','FY, SY'
        WHERE NOT EXISTS (SELECT 1 FROM program_master WHERE program_name='PG MTech IT')");
    @$conn->query("INSERT INTO program_master (program_name, duration, department_name, years_offered)
        SELECT 'PG MSc CSE','2 Years','CSE','FY, SY'
        WHERE NOT EXISTS (SELECT 1 FROM program_master WHERE program_name='PG MSc CSE')");
}


/* Keep ASH program row correct */
if(table_exists($conn, 'program_master')){
    @$conn->query("UPDATE program_master SET program_name='First Year Engineering', duration='1 Year', years_offered='FY' WHERE department_name='ASH'");
}

/* Keep Program Structure years clean as per current requirement */
if(table_exists($conn, 'program_master')){
    @$conn->query("UPDATE program_master SET years_offered='FY' WHERE department_name='ASH' OR program_name='First Year Engineering'");
    @$conn->query("UPDATE program_master SET years_offered='SY, TY, LY' WHERE program_name IN ('UG BTech CSE','UG BTech IT')");
    @$conn->query("UPDATE program_master SET years_offered='FY, SY' WHERE program_name IN ('PG MTech CSE','PG MTech IT','PG MSc CSE')");
}

/* Seed year-wise division structure once. Numbers are blank for admin edit. */
if(table_exists($conn, 'year_division_structure') && intval(q1($conn, "SELECT COUNT(*) FROM year_division_structure", 0)) == 0){
    @$conn->query("
        INSERT INTO year_division_structure
        (department_name, program_name, year_name, specialization, no_of_divisions, practical_batches, batch_strength) VALUES
        ('CSE','UG BTech CSE','FY','NULL','','A, B',''),
        ('CSE','UG BTech CSE','SY','CORE','','A, B',''),
        ('CSE','UG BTech CSE','TY','CORE','','A, B',''),
        ('CSE','UG BTech CSE','TY','AIA','','A, B',''),
        ('CSE','UG BTech CSE','TY','AIEC','','A, B',''),
        ('CSE','UG BTech CSE','TY','CC','','A, B',''),
        ('CSE','UG BTech CSE','TY','BDCE','','A, B',''),
        ('CSE','UG BTech CSE','TY','CSF','','A, B',''),
        ('CSE','UG BTech CSE','TY','BT','','A, B',''),
        ('CSE','UG BTech CSE','TY','DE','','A, B',''),
        ('CSE','UG BTech CSE','LY','CORE','','A, B',''),
        ('CSE','UG BTech CSE','LY','AIA','','A, B',''),
        ('CSE','UG BTech CSE','LY','AIEC','','A, B',''),
        ('CSE','UG BTech CSE','LY','CC','','A, B',''),
        ('CSE','UG BTech CSE','LY','BDCE','','A, B',''),
        ('CSE','UG BTech CSE','LY','CSF','','A, B',''),
        ('CSE','UG BTech CSE','LY','BT','','A, B',''),
        ('CSE','UG BTech CSE','LY','DE','','A, B',''),
        ('IT','UG BTech IT','FY','NULL','','A, B',''),
        ('IT','UG BTech IT','SY','IT','','A, B',''),
        ('IT','UG BTech IT','TY','DA','','A, B',''),
        ('IT','UG BTech IT','TY','SMAD','','A, B',''),
        ('IT','UG BTech IT','TY','IT','','A, B',''),
        ('IT','UG BTech IT','LY','DA','','A, B',''),
        ('IT','UG BTech IT','LY','SMAD','','A, B',''),
        ('IT','UG BTech IT','LY','IT','','A, B',''),
        ('ASH','First Year Engineering','FY','NULL','','A, B',''),
        ('CSE','PG MTech CSE','PG Year 1','ISA','','A, B',''),
        ('CSE','PG MTech CSE','PG Year 2','ISA','','A, B',''),
        ('IT','PG MTech IT','PG Year 1','CS','','A, B',''),
        ('IT','PG MTech IT','PG Year 2','CS','','A, B',''),
        ('CSE','PG MSc CSE','PG Year 1','AIML','','A, B',''),
        ('CSE','PG MSc CSE','PG Year 2','AIML','','A, B','')
    ");
}


/* ===================== SIGNATORIES INFO MASTER TABLE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS timetable_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    department_name VARCHAR(100) NULL,
    specialization VARCHAR(100) NULL,
    role_name VARCHAR(80) NOT NULL,
    person_name VARCHAR(150) NULL,
    designation VARCHAR(150) NULL,
    display_order INT DEFAULT 99,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

ensure_column($conn, 'timetable_signatures', 'academic_year', "VARCHAR(20) NULL");
ensure_column($conn, 'timetable_signatures', 'semester', "VARCHAR(20) NULL");
ensure_column($conn, 'timetable_signatures', 'department_name', "VARCHAR(100) NULL");
ensure_column($conn, 'timetable_signatures', 'specialization', "VARCHAR(100) NULL");
ensure_column($conn, 'timetable_signatures', 'display_order', "INT DEFAULT 99");
ensure_column($conn, 'timetable_signatures', 'digital_signature_path', "VARCHAR(255) NULL");

/* Seed blank signature roles once. No hardcoded names are used.
   Prepared By is division-wise and comes from Manual TT Import grid. */
if(table_exists($conn, 'timetable_signatures') && intval(q1($conn, "SELECT COUNT(*) FROM timetable_signatures", 0)) == 0){
    @$conn->query("
        INSERT INTO timetable_signatures
        (academic_year, semester, department_name, specialization, role_name, person_name, designation) VALUES
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'PREPARED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'CHECKED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'RECOMMENDED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'APPROVED BY', '', '')
    ");
}



/* Re-seed signatures if accidentally empty */
if(table_exists($conn, 'timetable_signatures') && intval(q1($conn, "SELECT COUNT(*) FROM timetable_signatures", 0)) == 0){
    @$conn->query("
        INSERT INTO timetable_signatures
        (academic_year, semester, department_name, specialization, role_name, person_name, designation) VALUES
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'PREPARED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'CHECKED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'RECOMMENDED BY', '', ''),
        ('2025-26', 'Odd', 'GENERAL', 'GENERAL', 'APPROVED BY', '', '')
    ");
}


/* ===================== CLASS TEACHER AY/SEM-WISE MASTER TABLE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS class_teacher_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    division_id INT NOT NULL,
    class_teacher VARCHAR(255) NULL,
    class_teacher_abbrev VARCHAR(50) NULL,
    class_teacher_emp_id VARCHAR(50) NULL,
    class_teacher_email VARCHAR(255) NULL,
    class_teacher_contact VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_teacher_assignment (academic_year, semester, division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* One-time migration: keep old existing division class teachers as 2025-26 Odd only */
if(table_exists($conn, 'class_teacher_assignments') && table_exists($conn, 'divisions')){
    $ctCount = intval(q1($conn, "SELECT COUNT(*) FROM class_teacher_assignments", 0));
    if($ctCount == 0 && column_exists($conn, 'divisions', 'class_teacher')){
        @$conn->query("
            INSERT INTO class_teacher_assignments
            (academic_year, semester, division_id, class_teacher, class_teacher_abbrev, class_teacher_emp_id, class_teacher_email, class_teacher_contact)
            SELECT
                '2025-26',
                'Odd',
                id,
                class_teacher,
                ".(column_exists($conn, 'divisions', 'class_teacher_abbrev') ? "class_teacher_abbrev" : "''").",
                ".(column_exists($conn, 'divisions', 'class_teacher_emp_id') ? "class_teacher_emp_id" : "''").",
                ".(column_exists($conn, 'divisions', 'class_teacher_email') ? "class_teacher_email" : "''").",
                ".(column_exists($conn, 'divisions', 'class_teacher_contact') ? "class_teacher_contact" : "''")."
            FROM divisions
            WHERE class_teacher IS NOT NULL AND class_teacher!=''
        ");
    }
}


/* ===================== FACULTY WORKLOAD PLANNING MASTER TABLE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS faculty_workload_planning (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,
    sr_no VARCHAR(20) NULL,
    faculty_name VARCHAR(255) NULL,
    designation VARCHAR(150) NULL,
    department VARCHAR(100) NULL,
    faculty_abbrev VARCHAR(50) NULL,
    program_name VARCHAR(150) NULL,
    subject_name VARCHAR(255) NULL,
    th_hours DECIMAL(6,2) DEFAULT 0,
    tut_hours DECIMAL(6,2) DEFAULT 0,
    pr_hours DECIMAL(6,2) DEFAULT 0,
    mini_project_hours DECIMAL(6,2) DEFAULT 0,
    major_project_hours DECIMAL(6,2) DEFAULT 0,
    total_hours DECIMAL(6,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function normalize_workload_number($v){
    $v = trim((string)$v);
    if($v === '') return 0;
    return floatval(preg_replace('/[^0-9.\-]/', '', $v));
}

function read_faculty_workload_template_rows($filePath, $originalName=''){
    $rowsOut = [];
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));

    if($ext === 'xlsx' && class_exists('ZipArchive')){
        $zip = new ZipArchive();
        if($zip->open($filePath) === TRUE){
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

            if($sharedXml !== false){
                $shared = @simplexml_load_string($sharedXml);
                if($shared){
                    foreach($shared->si as $si){
                        $txt = '';
                        if(isset($si->t)){
                            $txt = (string)$si->t;
                        } else {
                            foreach($si->r as $r){
                                $txt .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $txt;
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if($sheetXml !== false){
                $sheet = @simplexml_load_string($sheetXml);
                if($sheet && isset($sheet->sheetData)){
                    foreach($sheet->sheetData->row as $row){
                        $values = array_fill(0, 13, '');
                        foreach($row->c as $cell){
                            $ref = (string)$cell['r'];
                            $colLetters = preg_replace('/[0-9]/', '', $ref);
                            $idx = class_teacher_xlsx_col_to_index($colLetters);
                            if($idx >= 0 && $idx < 13){
                                $values[$idx] = trim(class_teacher_xlsx_cell_value($cell, $sharedStrings));
                            }
                        }
                        if(trim(implode('', $values)) !== ''){
                            $rowsOut[] = $values;
                        }
                    }
                }
            }
            $zip->close();
        }
        return $rowsOut;
    }

    $handle = fopen($filePath, 'r');
    if($handle){
        while(($row = fgetcsv($handle)) !== false){
            $row = array_pad($row, 13, '');
            if(trim(implode('', $row)) === '') continue;
            $rowsOut[] = array_slice($row, 0, 13);
        }
        fclose($handle);
    }
    return $rowsOut;
}


/* ===================== TIME SLOTS INFO MASTER TABLE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS timeslot_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_label VARCHAR(30) NOT NULL UNIQUE,
    start_time VARCHAR(20) NULL,
    end_time VARCHAR(20) NULL,
    slot_type ENUM('Lecture','Break') DEFAULT 'Lecture',
    break_name VARCHAR(80) NULL,
    is_active ENUM('Y','N') DEFAULT 'Y',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Seed once with current timetable slots */
if(table_exists($conn, 'timeslot_master') && intval(q1($conn, "SELECT COUNT(*) FROM timeslot_master", 0)) == 0){
    @$conn->query("
        INSERT INTO timeslot_master
        (slot_label, start_time, end_time, slot_type, break_name, is_active) VALUES
        ('08:45-09:40', '08:45', '09:40', 'Lecture', '', 'Y'),
        ('09:40-10:35', '09:40', '10:35', 'Lecture', '', 'Y'),
        ('10:35-10:50', '10:35', '10:50', 'Break', 'SHORT BREAK', 'Y'),
        ('10:50-11:45', '10:50', '11:45', 'Lecture', '', 'Y'),
        ('11:45-12:40', '11:45', '12:40', 'Lecture', '', 'Y'),
        ('12:40-01:40', '12:40', '01:40', 'Break', 'LUNCH BREAK', 'Y'),
        ('01:40-02:35', '01:40', '02:35', 'Lecture', '', 'Y'),
        ('02:35-03:30', '02:35', '03:30', 'Lecture', '', 'Y'),
        ('03:30-03:40', '03:30', '03:40', 'Break', 'SHORT BREAK', 'Y'),
        ('03:40-04:30', '03:40', '04:30', 'Lecture', '', 'Y')
    ");
}



/* Practical Time Slots Master */
@$conn->query("
CREATE TABLE IF NOT EXISTS practical_timeslot_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_label VARCHAR(40) NOT NULL UNIQUE,
    start_time VARCHAR(20) NULL,
    end_time VARCHAR(20) NULL,
    slot_type ENUM('Practical','Break') DEFAULT 'Practical',
    is_active ENUM('Y','N') DEFAULT 'Y',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Seed practical slots once.
   03:40-04:30 is NOT a practical slot, so the extra final practical slot is not added. */
if(table_exists($conn, 'practical_timeslot_master') && intval(q1($conn, "SELECT COUNT(*) FROM practical_timeslot_master", 0)) == 0){
    @$conn->query("
        INSERT INTO practical_timeslot_master
        (slot_label, start_time, end_time, slot_type, is_active) VALUES
        ('Practical Slot 1', '08:45', '10:35', 'Practical', 'Y'),
        ('SHORT BREAK', '10:35', '10:50', 'Break', 'Y'),
        ('Practical Slot 2', '10:50', '12:40', 'Practical', 'Y'),
        ('LUNCH BREAK', '12:40', '01:40', 'Break', 'Y'),
        ('Practical Slot 3', '01:40', '03:30', 'Practical', 'Y'),
        ('SHORT BREAK 2', '03:30', '03:40', 'Break', 'Y')
    ");
}

/* Safety cleanup if wrong 03:40-04:30 practical slot was previously created */
if(table_exists($conn, 'practical_timeslot_master')){
    @$conn->query("DELETE FROM practical_timeslot_master WHERE start_time='03:40' AND end_time='04:30'");
}


/* ===================== COLLEGE INFORMATION MASTER TABLE ===================== */
@$conn->query("
CREATE TABLE IF NOT EXISTS college_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    college_name VARCHAR(200) NOT NULL,
    institute_name VARCHAR(200) NULL,
    pro_vc_name VARCHAR(150) NULL,
    dean_name VARCHAR(150) NULL,
    associate_dean_academics VARCHAR(150) NULL,
    associate_dean_administration VARCHAR(150) NULL,
    college_tt_coordinator VARCHAR(150) NULL,
    department_tt_coordinator VARCHAR(150) NULL,
    cse_tt_coordinator VARCHAR(150) NULL,
    it_tt_coordinator VARCHAR(150) NULL,
    ash_tt_coordinator VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if(table_exists($conn, 'college_info') && intval(q1($conn, "SELECT COUNT(*) FROM college_info", 0)) == 0){
    @$conn->query("
        INSERT INTO college_info
        (college_name, institute_name, pro_vc_name, dean_name, associate_dean_academics, associate_dean_administration, college_tt_coordinator, department_tt_coordinator, cse_tt_coordinator, it_tt_coordinator, ash_tt_coordinator)
        VALUES
        ('MIT ADT University', 'School of Computing', 'Dr. Ramachandra Pujeri', 'Prof. Dr. Ganesh R. Pathak', 'Prof. Dr. Shraddha P. Phansalkar', 'Dr. Madhukar Nimbalkar', '', '', '', '', '')
    ");
}


ensure_column($conn, 'college_info', 'institute_name', "VARCHAR(200) NULL");
@$conn->query("UPDATE college_info SET institute_name='School of Computing' WHERE institute_name IS NULL OR institute_name=''");
@$conn->query("UPDATE college_info SET college_name='MIT ADT University' WHERE college_name='School of Computing' OR college_name IS NULL OR college_name=''");

$hasFacultyAcademicDesignation = column_exists($conn, 'faculties', 'academic_designation');
$hasFacultyProfileDesignation = column_exists($conn, 'faculties', 'profile_designation');
$hasFacultyDepartment = column_exists($conn, 'faculties', 'department');
$hasFacultySpecialization = column_exists($conn, 'faculties', 'specialization');
$hasFacultyCabinNo = column_exists($conn, 'faculties', 'cabin_no');
$hasFacultyActive = column_exists($conn, 'faculties', 'is_active');
$hasSubjectProgram = column_exists($conn, 'subjects', 'program');
$hasSubjectYear = column_exists($conn, 'subjects', 'year_name');
$hasSubjectSpec = column_exists($conn, 'subjects', 'specialization');
$hasSubjectRealCourseCode = column_exists($conn, 'subjects', 'course_code');
$hasSubjectCourseFullName = column_exists($conn, 'subjects', 'course_full_name');
$hasSubjectCourseCode = column_exists($conn, 'subjects', 'subject_code');
$hasSubjectCredits = column_exists($conn, 'subjects', 'credits');
$hasSubjectTh = column_exists($conn, 'subjects', 'th_hours_week');
$hasSubjectPr = column_exists($conn, 'subjects', 'pr_hours_week');
$hasSubjectTut = column_exists($conn, 'subjects', 'tut_hours_week');
$hasSubjectAcademicYear = column_exists($conn, 'subjects', 'academic_year');
$hasSubjectSemester = column_exists($conn, 'subjects', 'semester');


$badFacultyFilter = "faculty_code NOT REGEXP '^(D[0-9]+|[0-9]+PM|[0-9]+|[SN][0-9]|ONLINE|NULL|NONE|NAN)$'";
/* Correct current administrative designations */
if($hasFacultyDesignation){
    @$conn->query("UPDATE faculties SET designation='Dean' WHERE faculty_code='GPAT'");
    @$conn->query("UPDATE faculties SET designation='Associate Dean' WHERE faculty_code='MVN'");
    if(column_exists($conn, 'faculties', 'role_type')){
        @$conn->query("UPDATE faculties SET role_type='Administration' WHERE faculty_code IN ('GPAT','MVN')");
    }
}


if(isset($_POST['add_entry']) && isset($_SESSION['admin'])){
    $faculty = $_POST['faculty'] ?: null;
    $room = $_POST['classroom'] ?: null;
    $batch = $_POST['batch'] ?: null;
    $stmt = $conn->prepare("INSERT INTO timetable_entries
        (division_id, day_name, time_slot, subject_id, faculty_id, classroom_id, batch, academic_year, semester)
        VALUES ((SELECT id FROM divisions WHERE division_name=?), ?, ?, (SELECT id FROM subjects WHERE subject_code=?),
                (SELECT id FROM faculties WHERE faculty_code=?), (SELECT id FROM classrooms WHERE room_code=?), ?, ?, ?)");
    $stmt->bind_param("sssssssss", $_POST['division'], $_POST['day'], $_POST['slot'], $_POST['subject'], $faculty, $room, $batch, $_POST['academic_year'], $_POST['semester']);
    $stmt->execute();
    $message = "Entry added successfully.";
}
if(isset($_POST['delete_entry']) && isset($_SESSION['admin'])){
    $id = intval($_POST['entry_id']);
    $conn->query("DELETE FROM timetable_entries WHERE id=$id");
    $message = "Entry deleted successfully.";
}



/* ===================== MANUAL TIMETABLE GRID HELPERS ===================== */
function ensure_subject_manual($conn, $code){
    $code = strtoupper(trim((string)$code));
    if($code === '') return null;

    $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_code=? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if($row) return intval($row['id']);

    $name = $code;
    $type = subject_type_from_code($code, $name);
    if(column_exists($conn, 'subjects', 'subject_type')){
        $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, subject_type) VALUES (?,?,?)");
        $stmt->bind_param("sss", $code, $name, $type);
    } else {
        $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name) VALUES (?,?)");
        $stmt->bind_param("ss", $code, $name);
    }
    $stmt->execute();
    return intval($conn->insert_id);
}

function ensure_faculty_manual($conn, $code){
    $code = strtoupper(trim((string)$code));
    if($code === '') return null;

    $stmt = $conn->prepare("SELECT id FROM faculties WHERE faculty_code=? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if($row) return intval($row['id']);

    $name = $code;
    if(column_exists($conn, 'faculties', 'faculty_uid')){
        $uid = $code;
        $stmt = $conn->prepare("INSERT INTO faculties (faculty_uid, faculty_code, faculty_name) VALUES (?,?,?)");
        $stmt->bind_param("sss", $uid, $code, $name);
    } else {
        $stmt = $conn->prepare("INSERT INTO faculties (faculty_code, faculty_name) VALUES (?,?)");
        $stmt->bind_param("ss", $code, $name);
    }
    $stmt->execute();
    return intval($conn->insert_id);
}

function ensure_classroom_manual($conn, $room){
    $room = strtoupper(trim((string)$room));
    if($room === '') return null;

    $stmt = $conn->prepare("SELECT id FROM classrooms WHERE room_code=? LIMIT 1");
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if($row) return intval($row['id']);

    $stmt = $conn->prepare("INSERT INTO classrooms (room_code) VALUES (?)");
    $stmt->bind_param("s", $room);
    $stmt->execute();
    return intval($conn->insert_id);
}

function parse_manual_tt_cell($cellText){
    $out = [];
    $lines = preg_split('/\r\n|\r|\n/', (string)$cellText);
    foreach($lines as $line){
        $line = trim($line);
        if($line === '') continue;

        $parts = array_map('trim', explode(':', $line));
        $parts = array_values(array_filter($parts, function($v){ return trim($v) !== ''; }));

        if(count($parts) == 0) continue;

        $batch = '';
        if(count($parts) >= 4 && in_array(strtoupper($parts[0]), ['A','B','C','D'])){
            $batch = strtoupper($parts[0]);
            $subject = strtoupper($parts[1] ?? '');
            $faculty = strtoupper($parts[2] ?? '');
            $room = strtoupper($parts[3] ?? '');
        } else {
            $subject = strtoupper($parts[0] ?? '');
            $faculty = strtoupper($parts[1] ?? '');
            $room = strtoupper($parts[2] ?? '');
        }

        if($subject === '') continue;

        $out[] = [
            'batch' => $batch,
            'subject' => $subject,
            'faculty' => $faculty,
            'room' => $room
        ];
    }
    return $out;
}

function autofill_class_teacher_from_faculty($conn, $teacherName, $teacherAbbrev='', $teacherEmpId='', $teacherEmail='', $teacherContact=''){
    $teacherName = trim((string)$teacherName);
    $teacherAbbrev = strtoupper(trim((string)$teacherAbbrev));
    $teacherEmpId = trim((string)$teacherEmpId);
    $teacherEmail = trim((string)$teacherEmail);
    $teacherContact = trim((string)$teacherContact);

    if($teacherName !== '' && table_exists($conn, 'faculties')){
        $facCols = "faculty_code, faculty_name";
        $facCols .= column_exists($conn, 'faculties', 'faculty_uid') ? ", faculty_uid" : ", '' AS faculty_uid";
        $facCols .= column_exists($conn, 'faculties', 'email') ? ", email" : ", '' AS email";
        $facCols .= column_exists($conn, 'faculties', 'contact_no') ? ", contact_no" : ", '' AS contact_no";

        $stmt = $conn->prepare("
            SELECT $facCols
            FROM faculties
            WHERE TRIM(LOWER(faculty_name)) = TRIM(LOWER(?))
               OR TRIM(LOWER(faculty_code)) = TRIM(LOWER(?))
            LIMIT 1
        ");
        $stmt->bind_param("ss", $teacherName, $teacherName);
        $stmt->execute();
        $fac = $stmt->get_result()->fetch_assoc();

        if(!$fac){
            $likeName = "%".$teacherName."%";
            $stmt = $conn->prepare("SELECT $facCols FROM faculties WHERE faculty_name LIKE ? LIMIT 1");
            $stmt->bind_param("s", $likeName);
            $stmt->execute();
            $fac = $stmt->get_result()->fetch_assoc();
        }

        if($fac){
            if($teacherAbbrev === '') $teacherAbbrev = strtoupper(trim($fac['faculty_code'] ?? ''));
            if($teacherEmpId === '') $teacherEmpId = trim($fac['faculty_uid'] ?? '');
            if($teacherEmail === '') $teacherEmail = trim($fac['email'] ?? '');
            if($teacherContact === '') $teacherContact = trim($fac['contact_no'] ?? '');
        }
    }

    return [
        'class_teacher' => $teacherName,
        'class_teacher_abbrev' => $teacherAbbrev,
        'class_teacher_emp_id' => $teacherEmpId,
        'class_teacher_email' => $teacherEmail,
        'class_teacher_contact' => $teacherContact
    ];
}




function class_teacher_xlsx_col_to_index($letters){
    $letters = preg_replace('/[^A-Z]/', '', (string)$letters);
    $num = 0;
    for($i=0; $i<strlen($letters); $i++){
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return $num - 1;
}

function class_teacher_xlsx_cell_value($cell, $sharedStrings){
    $attrs = $cell->attributes();
    $type = isset($attrs['t']) ? (string)$attrs['t'] : '';

    if($type === 's'){
        $idx = intval((string)$cell->v);
        return $sharedStrings[$idx] ?? '';
    }

    if($type === 'inlineStr'){
        if(isset($cell->is->t)) return (string)$cell->is->t;
        $txt = '';
        if(isset($cell->is)){
            foreach($cell->is->children() as $child){
                if($child->getName() === 't') $txt .= (string)$child;
            }
        }
        return $txt;
    }

    if(isset($cell->v)) return (string)$cell->v;
    return '';
}

function read_class_teacher_template_rows($filePath, $originalName=''){
    $rowsOut = [];
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));

    if($ext === 'xlsx' && class_exists('ZipArchive')){
        $zip = new ZipArchive();
        if($zip->open($filePath) === TRUE){
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if($sharedXml !== false){
                $shared = @simplexml_load_string($sharedXml);
                if($shared){
                    foreach($shared->si as $si){
                        $txt = '';
                        if(isset($si->t)){
                            $txt = (string)$si->t;
                        } else {
                            foreach($si->r as $r){
                                $txt .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $txt;
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if($sheetXml !== false){
                $sheet = @simplexml_load_string($sheetXml);
                if($sheet && isset($sheet->sheetData)){
                    foreach($sheet->sheetData->row as $row){
                        $rowNum = intval((string)$row['r']);
                        if($rowNum <= 1) continue;

                        $values = array_fill(0, 8, '');
                        foreach($row->c as $cell){
                            $ref = (string)$cell['r'];
                            $colLetters = preg_replace('/[0-9]/', '', $ref);
                            $idx = class_teacher_xlsx_col_to_index($colLetters);
                            if($idx >= 0 && $idx < 8){
                                $values[$idx] = trim(class_teacher_xlsx_cell_value($cell, $sharedStrings));
                            }
                        }

                        if(implode('', $values) !== ''){
                            $rowsOut[] = $values;
                        }
                    }
                }
            }
            $zip->close();
        }
        return $rowsOut;
    }

    /* CSV fallback */
    $handle = fopen($filePath, 'r');
    if($handle){
        $header = fgetcsv($handle);
        while(($row = fgetcsv($handle)) !== false){
            $row = array_pad($row, 8, '');
            if(trim(implode('', $row)) === '') continue;
            $rowsOut[] = array_slice($row, 0, 8);
        }
        fclose($handle);
    }

    return $rowsOut;
}

function xlsx_inline_cell($cellRef, $value, $styleId=0){
    $value = htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    $style = $styleId > 0 ? ' s="'.$styleId.'"' : '';
    return '<c r="'.$cellRef.'" t="inlineStr"'.$style.'><is><t>'.$value.'</t></is></c>';
}

function xlsx_empty_cell($cellRef){
    return '<c r="'.$cellRef.'"/>';
}



function read_course_master_template_rows($filePath, $originalName=''){
    $rowsOut = [];
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));

    if($ext === 'xlsx' && class_exists('ZipArchive')){
        $zip = new ZipArchive();
        if($zip->open($filePath) === TRUE){
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

            if($sharedXml !== false){
                $shared = @simplexml_load_string($sharedXml);
                if($shared){
                    foreach($shared->si as $si){
                        $txt = '';
                        if(isset($si->t)){
                            $txt = (string)$si->t;
                        } else {
                            foreach($si->r as $r){
                                $txt .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $txt;
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if($sheetXml !== false){
                $sheet = @simplexml_load_string($sheetXml);
                if($sheet && isset($sheet->sheetData)){
                    foreach($sheet->sheetData->row as $row){
                        $rowNum = intval((string)$row['r']);
                        if($rowNum <= 1) continue;

                        $values = array_fill(0, 12, '');
                        foreach($row->c as $cell){
                            $ref = (string)$cell['r'];
                            $colLetters = preg_replace('/[0-9]/', '', $ref);
                            $idx = class_teacher_xlsx_col_to_index($colLetters);
                            if($idx >= 0 && $idx < 12){
                                $values[$idx] = trim(class_teacher_xlsx_cell_value($cell, $sharedStrings));
                            }
                        }

                        if(trim(implode('', $values)) !== ''){
                            $rowsOut[] = $values;
                        }
                    }
                }
            }

            $zip->close();
        }
        return $rowsOut;
    }

    /* CSV fallback */
    $handle = fopen($filePath, 'r');
    if($handle){
        $header = fgetcsv($handle);
        while(($row = fgetcsv($handle)) !== false){
            $row = array_pad($row, 12, '');
            if(trim(implode('', $row)) === '') continue;
            $rowsOut[] = array_slice($row, 0, 12);
        }
        fclose($handle);
    }

    return $rowsOut;
}



function normalize_course_master_header($s){
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s;
}
function cm_val($row, $map, $key){
    $idx = $map[$key] ?? null;
    return $idx === null ? '' : trim((string)($row[$idx] ?? ''));
}
function cm_num($v){
    $v = trim((string)$v);
    if($v === '') return '';
    return preg_replace('/[^0-9.\-]/', '', $v);
}

/* Download Class Teacher XLSX Template with dropdowns */
if(isset($_GET['download_class_teacher_template']) && isset($_SESSION['admin'])){
    $templateYear = $selected_year ?: '2026-27';
    $templateSem = ($selected_semester === 'Even') ? 'Even' : 'Odd';

    if(class_exists('ZipArchive')){
        $tmp = tempnam(sys_get_temp_dir(), 'ct_template_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $headers = ['Academic Year','Semester','Division','Class Teacher Name','Abbrev.','Emp ID','Email','Contact No.'];
        $colLetters = ['A','B','C','D','E','F','G','H'];

        $sheetData = '<row r="1">';
        foreach($headers as $i=>$h){
            $sheetData .= xlsx_inline_cell($colLetters[$i].'1', $h, 1);
        }
        $sheetData .= '</row>';

        for($r=2; $r<=200; $r++){
            $sheetData .= '<row r="'.$r.'">';
            for($c=0; $c<8; $c++){
                $ref = $colLetters[$c].$r;
                if($c==0) $sheetData .= xlsx_inline_cell($ref, '', 0);
                else if($c==1) $sheetData .= xlsx_inline_cell($ref, '', 0);
                else $sheetData .= xlsx_empty_cell($ref);
            }
            $sheetData .= '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <dimension ref="A1:H200"/>
    <sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
    <sheetFormatPr defaultRowHeight="18"/>
    <cols>
        <col min="1" max="1" width="16" customWidth="1"/>
        <col min="2" max="2" width="14" customWidth="1"/>
        <col min="3" max="3" width="18" customWidth="1"/>
        <col min="4" max="4" width="30" customWidth="1"/>
        <col min="5" max="5" width="14" customWidth="1"/>
        <col min="6" max="6" width="16" customWidth="1"/>
        <col min="7" max="7" width="32" customWidth="1"/>
        <col min="8" max="8" width="18" customWidth="1"/>
    </cols>
    <sheetData>'.$sheetData.'</sheetData>
    <dataValidations count="2">
        <dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="A2:A200">
            <formula1>"2025-26,2026-27,2027-28,2028-29"</formula1>
        </dataValidation>
        <dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="B2:B200">
            <formula1>"Odd,Even"</formula1>
        </dataValidation>
    </dataValidations>
</worksheet>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Class Teacher Template" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="2">
        <font><sz val="11"/><name val="Calibri"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    </fonts>
    <fills count="3">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF5B1A8E"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="2">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Class_Teacher_Template.xlsx"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /* Fallback only if ZipArchive is unavailable */
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Class_Teacher_Template.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Academic Year','Semester','Division','Class Teacher Name','Abbrev.','Emp ID','Email','Contact No.']);
    fclose($out);
    exit;
}

/* ===================== ADMIN: COMMON INFORMATION TABLES ===================== */
if(isset($_SESSION['admin'])){


    /* Universal Common Info Delete Button Handler */
    if(isset($_POST['common_info_delete_record'])){
        $table = trim($_POST['common_delete_table'] ?? '');
        $id = intval($_POST['common_delete_id'] ?? 0);
        $code = trim($_POST['common_delete_code'] ?? '');

        $allowedTablesById = [
            'faculties',
            'subjects',
            'classrooms',
            'leadership_master',
            'department_master',
            'program_master',
            'year_division_structure',
            'timetable_signatures',
            'class_teacher_assignments',
            'timeslot_master',
            'practical_timeslot_master',
            'college_info',
            'admin_block_details',
            'faculty_workload_planning'
        ];

        $allowedTablesByCode = [
            'classrooms' => 'room_code'
        ];

        if($table !== '' && $id > 0 && in_array($table, $allowedTablesById)){
            $safeTable = str_replace('`', '', $table);
            $stmt = $conn->prepare("DELETE FROM `$safeTable` WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $message = "Record deleted successfully.";
        } elseif($table !== '' && $code !== '' && isset($allowedTablesByCode[$table])){
            $safeTable = str_replace('`', '', $table);
            $safeCol = $allowedTablesByCode[$table];
            $stmt = $conn->prepare("DELETE FROM `$safeTable` WHERE `$safeCol`=? LIMIT 1");
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $message = "Record deleted successfully.";
        }
    }

    /* Faculty Workload Planning Master - Add Row */
    if(isset($_POST['save_new_faculty_workload'])){
        $wlAy = trim($_POST['wl_academic_year'] ?? $selected_year ?? '2025-26');
        $wlSem = trim($_POST['wl_semester'] ?? $selected_semester ?? 'Odd');
        if($wlSem === ('Sem'.'-I')) $wlSem = 'Odd';
        if($wlSem === ('Sem'.'-II')) $wlSem = 'Even';

        $srNo = trim($_POST['sr_no'] ?? '');
        $facultyName = trim($_POST['faculty_name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department = strtoupper(trim($_POST['department'] ?? ''));
        $facultyAbbrev = strtoupper(trim($_POST['faculty_abbrev'] ?? ''));
        $programName = trim($_POST['program_name'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $th = normalize_workload_number($_POST['th_hours'] ?? 0);
        $tut = normalize_workload_number($_POST['tut_hours'] ?? 0);
        $pr = normalize_workload_number($_POST['pr_hours'] ?? 0);
        $mini = normalize_workload_number($_POST['mini_project_hours'] ?? 0);
        $major = normalize_workload_number($_POST['major_project_hours'] ?? 0);
        $total = $th + $tut + $pr + $mini + $major;

        if($facultyName !== '' || $facultyAbbrev !== '' || $programName !== '' || $subjectName !== ''){
            $stmt = $conn->prepare("
                INSERT INTO faculty_workload_planning
                (academic_year, semester, sr_no, faculty_name, designation, department, faculty_abbrev, program_name, subject_name, th_hours, tut_hours, pr_hours, mini_project_hours, major_project_hours, total_hours)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param("sssssssssdddddd", $wlAy,$wlSem,$srNo,$facultyName,$designation,$department,$facultyAbbrev,$programName,$subjectName,$th,$tut,$pr,$mini,$major,$total);
            $stmt->execute();
            $message = "Faculty workload row added for ".$wlAy." ".$wlSem.".";
        } else {
            $message = "Please enter faculty workload details.";
        }
    }

    /* Faculty Workload Planning Master - Save Row */
    if(isset($_POST['save_faculty_workload'])){
        $id = intval($_POST['workload_id'] ?? 0);
        $wlAy = trim($_POST['wl_academic_year'] ?? $selected_year ?? '2025-26');
        $wlSem = trim($_POST['wl_semester'] ?? $selected_semester ?? 'Odd');
        if($wlSem === ('Sem'.'-I')) $wlSem = 'Odd';
        if($wlSem === ('Sem'.'-II')) $wlSem = 'Even';

        $srNo = trim($_POST['sr_no'] ?? '');
        $facultyName = trim($_POST['faculty_name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $department = strtoupper(trim($_POST['department'] ?? ''));
        $facultyAbbrev = strtoupper(trim($_POST['faculty_abbrev'] ?? ''));
        $programName = trim($_POST['program_name'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $th = normalize_workload_number($_POST['th_hours'] ?? 0);
        $tut = normalize_workload_number($_POST['tut_hours'] ?? 0);
        $pr = normalize_workload_number($_POST['pr_hours'] ?? 0);
        $mini = normalize_workload_number($_POST['mini_project_hours'] ?? 0);
        $major = normalize_workload_number($_POST['major_project_hours'] ?? 0);
        $total = $th + $tut + $pr + $mini + $major;

        if($id > 0){
            $stmt = $conn->prepare("
                UPDATE faculty_workload_planning
                SET academic_year=?, semester=?, sr_no=?, faculty_name=?, designation=?, department=?, faculty_abbrev=?, program_name=?, subject_name=?, th_hours=?, tut_hours=?, pr_hours=?, mini_project_hours=?, major_project_hours=?, total_hours=?
                WHERE id=?
            ");
            $stmt->bind_param("sssssssssddddddi", $wlAy,$wlSem,$srNo,$facultyName,$designation,$department,$facultyAbbrev,$programName,$subjectName,$th,$tut,$pr,$mini,$major,$total,$id);
            $stmt->execute();
            $message = "Faculty workload row saved.";
        }
    }

    /* Faculty Workload Planning Master - Delete Row */
    if(isset($_POST['delete_faculty_workload'])){
        $id = intval($_POST['workload_id'] ?? 0);
        if($id > 0){
            $stmt = $conn->prepare("DELETE FROM faculty_workload_planning WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $message = "Faculty workload row deleted.";
        }
    }

    /* Faculty Workload Planning Master - Bulk Upload */
    if(isset($_POST['upload_faculty_workload_template']) && isset($_FILES['faculty_workload_file'])){
        $wlAy = trim($_POST['wl_upload_academic_year'] ?? $selected_year ?? '2025-26');
        $wlSem = trim($_POST['wl_upload_semester'] ?? $selected_semester ?? 'Odd');
        if($wlSem === ('Sem'.'-I')) $wlSem = 'Odd';
        if($wlSem === ('Sem'.'-II')) $wlSem = 'Even';

        $fileTmp = $_FILES['faculty_workload_file']['tmp_name'] ?? '';
        $fileName = $_FILES['faculty_workload_file']['name'] ?? '';
        $inserted = 0;
        $skipped = 0;

        if($fileTmp && is_uploaded_file($fileTmp)){
            $rows = read_faculty_workload_template_rows($fileTmp, $fileName);
            $headerIndex = -1;

            foreach($rows as $i=>$row){
                $joined = strtolower(preg_replace('/[^a-z0-9]+/', '', implode(' ', $row)));
                if(strpos($joined, 'srno') !== false && strpos($joined, 'nameofthefaculty') !== false && strpos($joined, 'nameofthesubject') !== false){
                    $headerIndex = $i;
                    break;
                }
            }

            if($headerIndex < 0){
                $message = "Faculty Workload template upload failed. Header row not found.";
            } else {
                if(isset($_POST['replace_faculty_workload']) && $_POST['replace_faculty_workload']=='1'){
                    $stmtDel = $conn->prepare("DELETE FROM faculty_workload_planning WHERE academic_year=? AND semester=?");
                    $stmtDel->bind_param("ss", $wlAy, $wlSem);
                    $stmtDel->execute();
                }

                $lastSr = $lastFaculty = $lastDesignation = $lastDept = $lastAbbrev = '';

                for($i=$headerIndex+1; $i<count($rows); $i++){
                    $row = array_pad($rows[$i], 13, '');

                    $srNo = trim($row[0] ?? '');
                    $facultyName = trim($row[1] ?? '');
                    $designation = trim($row[2] ?? '');
                    $department = strtoupper(trim($row[3] ?? ''));
                    $facultyAbbrev = strtoupper(trim($row[4] ?? ''));
                    $programName = trim($row[5] ?? '');
                    $subjectName = trim($row[6] ?? '');

                    if($srNo !== '') $lastSr = $srNo; else $srNo = $lastSr;
                    if($facultyName !== '') $lastFaculty = $facultyName; else $facultyName = $lastFaculty;
                    if($designation !== '') $lastDesignation = $designation; else $designation = $lastDesignation;
                    if($department !== '') $lastDept = $department; else $department = $lastDept;
                    if($facultyAbbrev !== '') $lastAbbrev = $facultyAbbrev; else $facultyAbbrev = $lastAbbrev;

                    $th = normalize_workload_number($row[7] ?? 0);
                    $tut = normalize_workload_number($row[8] ?? 0);
                    $pr = normalize_workload_number($row[9] ?? 0);
                    $mini = normalize_workload_number($row[10] ?? 0);
                    $major = normalize_workload_number($row[11] ?? 0);
                    $total = normalize_workload_number($row[12] ?? 0);
                    if($total == 0) $total = $th + $tut + $pr + $mini + $major;

                    if($facultyName === '' && $facultyAbbrev === '' && $programName === '' && $subjectName === ''){
                        continue;
                    }
                    if($programName === '' && $subjectName === ''){
                        $skipped++;
                        continue;
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO faculty_workload_planning
                        (academic_year, semester, sr_no, faculty_name, designation, department, faculty_abbrev, program_name, subject_name, th_hours, tut_hours, pr_hours, mini_project_hours, major_project_hours, total_hours)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->bind_param("sssssssssdddddd", $wlAy,$wlSem,$srNo,$facultyName,$designation,$department,$facultyAbbrev,$programName,$subjectName,$th,$tut,$pr,$mini,$major,$total);
                    if($stmt->execute()) $inserted++; else $skipped++;
                }
                $message = "Faculty workload template uploaded for ".$wlAy." ".$wlSem.". Rows inserted: ".$inserted.". Skipped: ".$skipped.".";
            }
        } else {
            $message = "Please select a valid Faculty Workload template file.";
        }
    }


    /* Save Manual Division Timetable Grid */
    if(isset($_POST['save_manual_timetable_grid'])){
        $divisionName = trim($_POST['manual_division'] ?? '');
        $typedDivision = trim($_POST['manual_division_new'] ?? '');
        if($typedDivision !== '') $divisionName = $typedDivision;

        $manualDept = strtoupper(trim($_POST['manual_department'] ?? 'CSE'));
        $manualProgramLevel = strtoupper(trim($_POST['manual_program_level'] ?? 'UG'));
        $manualDegree = trim($_POST['manual_degree_type'] ?? '');
        $manualYearName = strtoupper(trim($_POST['manual_year_name'] ?? ''));
        $manualSpec = strtoupper(trim($_POST['manual_specialization'] ?? ''));

        $ay = trim($_POST['manual_academic_year'] ?? $selected_year);
        $sem = trim($_POST['manual_semester'] ?? $selected_semester);
        $manualWefDate = trim($_POST['manual_wef_date'] ?? '');
        $manualPreparedBy = trim($_POST['manual_prepared_by'] ?? '');
        $grid = $_POST['manual_tt'] ?? [];

        if($divisionName === '' || $ay === '' || $sem === ''){
            $message = "Please select or type division, academic year and semester before saving timetable.";
        } else {
            $stmt = $conn->prepare("SELECT id FROM divisions WHERE division_name=? LIMIT 1");
            $stmt->bind_param("s", $divisionName);
            $stmt->execute();
            $divRow = $stmt->get_result()->fetch_assoc();

            if(!$divRow){
                $cols = ['division_name'];
                $vals = [$divisionName];
                $types = 's';

                if(column_exists($conn, 'divisions', 'department')){
                    $cols[] = 'department'; $vals[] = $manualDept; $types .= 's';
                }
                if(column_exists($conn, 'divisions', 'program_level')){
                    $cols[] = 'program_level'; $vals[] = $manualProgramLevel; $types .= 's';
                }
                if(column_exists($conn, 'divisions', 'degree_type')){
                    $cols[] = 'degree_type'; $vals[] = $manualDegree; $types .= 's';
                }
                if(column_exists($conn, 'divisions', 'year_name')){
                    $cols[] = 'year_name'; $vals[] = $manualYearName; $types .= 's';
                }
                if(column_exists($conn, 'divisions', 'specialization')){
                    $cols[] = 'specialization'; $vals[] = $manualSpec; $types .= 's';
                }

                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO divisions (`".implode('`,`', $cols)."`) VALUES ($placeholders)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$vals);
                $stmt->execute();

                $divisionId = intval($conn->insert_id);
            } else {
                $divisionId = intval($divRow['id']);
            }

            /* Save W.E.F date and Prepared By for selected division + academic year + semester */
            if($manualWefDate !== '' || $manualPreparedBy !== ''){
                if($manualWefDate !== '' && column_exists($conn, 'divisions', 'wef_date')){
                    $stmtWefDiv = $conn->prepare("UPDATE divisions SET wef_date=? WHERE id=?");
                    $stmtWefDiv->bind_param("si", $manualWefDate, $divisionId);
                    $stmtWefDiv->execute();
                }

                if(table_exists($conn, 'timetable_settings')){
                    $stmtWef = $conn->prepare("
                        INSERT INTO timetable_settings (division_id, academic_year, semester, wef_date, prepared_by)
                        VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE
                            wef_date=IF(VALUES(wef_date)='', wef_date, VALUES(wef_date)),
                            prepared_by=VALUES(prepared_by)
                    ");
                    $stmtWef->bind_param("issss", $divisionId, $ay, $sem, $manualWefDate, $manualPreparedBy);
                    $stmtWef->execute();
                }
            }

                $stmt = $conn->prepare("DELETE FROM timetable_entries WHERE division_id=? AND academic_year=? AND semester=?");
                $stmt->bind_param("iss", $divisionId, $ay, $sem);
                $stmt->execute();

                $inserted = 0;

                foreach($grid as $dayName => $slotCells){
                    $dayName = trim($dayName);
                    if(!is_array($slotCells)) continue;

                    foreach($slotCells as $timeSlot => $cellText){
                        $timeSlot = trim($timeSlot);
                        if(isset($break_slots[$timeSlot])) continue;

                        $items = parse_manual_tt_cell($cellText);
                        foreach($items as $item){
                            $subjectId = ensure_subject_manual($conn, $item['subject']);
                            $facultyId = ensure_faculty_manual($conn, $item['faculty']);
                            $classroomId = ensure_classroom_manual($conn, $item['room']);
                            $batch = $item['batch'] !== '' ? $item['batch'] : null;

                            if(!$subjectId) continue;

                            $stmt = $conn->prepare("
                                INSERT INTO timetable_entries
                                (division_id, day_name, time_slot, subject_id, faculty_id, classroom_id, batch, academic_year, semester)
                                VALUES (?,?,?,?,?,?,?,?,?)
                            ");
                            $stmt->bind_param("issiiisss", $divisionId, $dayName, $timeSlot, $subjectId, $facultyId, $classroomId, $batch, $ay, $sem);
                            $stmt->execute();
                            $inserted++;
                        }
                    }
                }

                $selected_division = $divisionName;
                $selected_year = $ay;
                $selected_semester = $sem;
                $message = "Manual timetable saved successfully. Entries inserted: ".$inserted;
                if($manualWefDate !== '') $message .= " W.E.F date saved: ".format_wef_date_display($manualWefDate).".";
                if($manualPreparedBy !== '') $message .= " Prepared By saved: ".$manualPreparedBy.".";
        }
    }

    /* Generic Delete Handlers for Common Info Tables */
    if(isset($_POST['delete_department_master'])){
        $id=intval($_POST['department_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM department_master WHERE id=$id"); $message="Department row deleted."; }
    }
    if(isset($_POST['delete_program_master'])){
        $id=intval($_POST['program_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM program_master WHERE id=$id"); $message="Program row deleted."; }
    }
    if(isset($_POST['delete_year_division_structure'])){
        $id=intval($_POST['year_division_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM year_division_structure WHERE id=$id"); $message="Year/division structure row deleted."; }
    }
    if(isset($_POST['delete_leadership_master'])){
        $id=intval($_POST['leadership_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM leadership_master WHERE id=$id"); $message="Leadership row deleted."; }
    }
    if(isset($_POST['delete_college_info'])){
        $id=intval($_POST['college_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM college_info WHERE id=$id"); $message="College info row deleted."; }
    }
    if(isset($_POST['delete_signature_master'])){
        $id=intval($_POST['signature_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM timetable_signatures WHERE id=$id"); $message="Signatory row deleted."; }
    }
    if(isset($_POST['delete_timeslot_master'])){
        $id=intval($_POST['timeslot_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM timeslot_master WHERE id=$id"); $message="Theory time slot row deleted."; }
    }
    if(isset($_POST['delete_practical_timeslot_master'])){
        $id=intval($_POST['practical_timeslot_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM practical_timeslot_master WHERE id=$id"); $message="Practical time slot row deleted."; }
    }
    if(isset($_POST['delete_master_lab'])){
        $room=$conn->real_escape_string(strtoupper(trim($_POST['room_code'] ?? '')));
        if($room!==''){ $conn->query("DELETE FROM lab_details WHERE room_code='$room'"); $message="Lab resource deleted."; }
    }
    if(isset($_POST['delete_master_tutorial'])){
        $room=$conn->real_escape_string(strtoupper(trim($_POST['room_code'] ?? '')));
        if($room!==''){ $conn->query("DELETE FROM tutorial_room_details WHERE room_code='$room'"); $message="Tutorial room deleted."; }
    }
    if(isset($_POST['delete_master_faculty_block'])){
        $room=$conn->real_escape_string(strtoupper(trim($_POST['room_code'] ?? '')));
        if($room!==''){ $conn->query("DELETE FROM faculty_block_details WHERE room_code='$room'"); $message="Faculty block deleted."; }
    }
    if(isset($_POST['delete_master_admin_block'])){
        $id=intval($_POST['admin_block_id'] ?? 0);
        if($id>0){ $conn->query("DELETE FROM admin_block_details WHERE id=$id"); $message="Admin block deleted."; }
    }
    if(isset($_POST['delete_master_seminar'])){
        $room=$conn->real_escape_string(strtoupper(trim($_POST['room_code'] ?? '')));
        if($room!==''){ $conn->query("DELETE FROM seminar_hall_details WHERE room_code='$room'"); $message="Seminar hall deleted."; }
    }

    /* Add faculty */
    if(isset($_POST['add_master_faculty'])){
        $faculty_uid = trim($_POST['faculty_uid'] ?? '');
        $faculty_code = strtoupper(trim($_POST['faculty_code'] ?? ''));
        $faculty_name = trim($_POST['faculty_name'] ?? '');

        if($faculty_code !== '' && $faculty_name !== ''){
            if($hasFacultyUid){
                $stmt = $conn->prepare("SELECT id FROM faculties WHERE faculty_code=? OR faculty_uid=? LIMIT 1");
                $stmt->bind_param("ss", $faculty_code, $faculty_uid);
            } else {
                $stmt = $conn->prepare("SELECT id FROM faculties WHERE faculty_code=? LIMIT 1");
                $stmt->bind_param("s", $faculty_code);
            }
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if($existing){
                if($hasFacultyUid){
                    $stmt = $conn->prepare("UPDATE faculties SET faculty_uid=?, faculty_name=? WHERE id=?");
                    $stmt->bind_param("ssi", $faculty_uid, $faculty_name, $existing['id']);
                } else {
                    $stmt = $conn->prepare("UPDATE faculties SET faculty_name=? WHERE id=?");
                    $stmt->bind_param("si", $faculty_name, $existing['id']);
                }
                $stmt->execute();
                $message = "Faculty updated successfully.";
            } else {
                if($hasFacultyUid){
                    $stmt = $conn->prepare("INSERT INTO faculties (faculty_uid, faculty_code, faculty_name) VALUES (?,?,?)");
                    $stmt->bind_param("sss", $faculty_uid, $faculty_code, $faculty_name);
                } else {
                    $stmt = $conn->prepare("INSERT INTO faculties (faculty_code, faculty_name) VALUES (?,?)");
                    $stmt->bind_param("ss", $faculty_code, $faculty_name);
                }
                $stmt->execute();
                $message = "Faculty added successfully.";
            }
        } else {
            $message = "Faculty abbreviation and name are required.";
        }
    }

    /* Delete faculty */
    if(isset($_POST['delete_master_faculty'])){
        $id = intval($_POST['faculty_id'] ?? 0);
        if($id > 0){
            $conn->query("UPDATE timetable_entries SET faculty_id=NULL WHERE faculty_id=$id");
            $conn->query("DELETE FROM faculties WHERE id=$id");
            $message = "Faculty deleted successfully.";
        }
    }

    /* Add course - AY/Sem wise and duplicates allowed across programs/specializations */
    if(isset($_POST['add_master_subject'])){
        $courseAy = trim($_POST['course_academic_year'] ?? $selected_year ?? '2025-26');
        $courseSem = trim($_POST['course_semester'] ?? $selected_semester ?? 'Odd');
        if($courseSem === ('Sem'.'-I')) $courseSem = 'Odd';
        if($courseSem === ('Sem'.'-II')) $courseSem = 'Even';

        $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
        $subject_name = trim($_POST['subject_name'] ?? '');
        $subject_type = trim($_POST['subject_type'] ?? 'Theory');

        if($subject_code !== '' && $subject_name !== ''){
            remove_subject_duplicate_constraints($conn);

            if($hasSubjectType){
                $stmt = $conn->prepare("INSERT INTO subjects (academic_year, semester, subject_code, subject_name, subject_type) VALUES (?,?,?,?,?)");
                $stmt->bind_param("sssss", $courseAy, $courseSem, $subject_code, $subject_name, $subject_type);
            } else {
                $stmt = $conn->prepare("INSERT INTO subjects (academic_year, semester, subject_code, subject_name) VALUES (?,?,?,?)");
                $stmt->bind_param("ssss", $courseAy, $courseSem, $subject_code, $subject_name);
            }

            if(@$stmt->execute()){
                $message = "Course added successfully for ".$courseAy." ".$courseSem.". Duplicate course codes are allowed.";
            } else {
                $message = "Course could not be added. Please check database constraints.";
            }
        } else {
            $message = "Course abbreviation and name are required.";
        }
    }

    /* Delete course - also removes timetable entries using that course */
    if(isset($_POST['delete_master_subject'])){
        $id = intval($_POST['subject_id'] ?? 0);
        if($id > 0){
            $conn->query("DELETE FROM timetable_entries WHERE subject_id=$id");
            $conn->query("DELETE FROM subjects WHERE id=$id");
            $message = "Course and related timetable entries deleted successfully.";
        }
    }

    /* Add classroom */
    if(isset($_POST['add_master_classroom'])){
        $room_code = strtoupper(trim($_POST['room_code'] ?? ''));
        if($room_code !== ''){
            $stmt = $conn->prepare("SELECT id FROM classrooms WHERE room_code=? LIMIT 1");
            $stmt->bind_param("s", $room_code);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if($existing){
                $message = "Classroom already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO classrooms (room_code) VALUES (?)");
                $stmt->bind_param("s", $room_code);
                $stmt->execute();
                $message = "Classroom added successfully.";
            }
        } else {
            $message = "Classroom code is required.";
        }
    }

    /* Delete classroom */
    if(isset($_POST['delete_master_classroom'])){
        $id = intval($_POST['classroom_id'] ?? 0);
        if($id > 0){
            $conn->query("UPDATE timetable_entries SET classroom_id=NULL WHERE classroom_id=$id");
            $conn->query("DELETE FROM classrooms WHERE id=$id");
            $message = "Classroom deleted successfully.";
        }
    }

    /* Inline Save: Faculty Master */
    if(isset($_POST['save_master_faculty'])){
        $id = intval($_POST['faculty_id'] ?? 0);
        $uid = trim($_POST['faculty_uid'] ?? '');
        $code = strtoupper(trim($_POST['faculty_code'] ?? ''));
        $name = trim($_POST['faculty_name'] ?? '');
        $academic = trim($_POST['academic_designation'] ?? '');
        $profile = trim($_POST['profile_designation'] ?? '');
        if(strtoupper($profile)==='NULL') $profile = '';
        $designation = ($profile !== '') ? $profile : $academic;
        $department = strtoupper(trim($_POST['department'] ?? ''));
        $specialization = strtoupper(trim($_POST['specialization'] ?? ''));
        if(strtoupper($specialization)==='NULL') $specialization = '';
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_no'] ?? '');
        $cabin = trim($_POST['cabin_no'] ?? '');
        $role = trim($_POST['role_type'] ?? '');
        $active = strtoupper(trim($_POST['is_active'] ?? 'Y'));
        if(!in_array($active, ['Y','N'])) $active = 'Y';

        if($id>0 && $code!=='' && $name!==''){
            $stmt = $conn->prepare("UPDATE faculties SET faculty_uid=?, faculty_code=?, faculty_name=?, designation=?, academic_designation=?, profile_designation=?, department=?, specialization=?, email=?, contact_no=?, seating_location=?, cabin_no=?, role_type=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssssssssssssssi", $uid,$code,$name,$designation,$academic,$profile,$department,$specialization,$email,$contact,$cabin,$cabin,$role,$active,$id);
            $stmt->execute();
            $message = "Faculty saved successfully.";
        }
    }

    /* Inline Save: Course Master - exact template columns */
    if(isset($_POST['save_master_subject'])){
        $id = intval($_POST['subject_id'] ?? 0);
        $courseAy = trim($_POST['course_academic_year'] ?? $selected_year ?? '2025-26');
        $courseSem = trim($_POST['course_semester'] ?? $selected_semester ?? 'Odd');
        if($courseSem === ('Sem'.'-I')) $courseSem = 'Odd';
        if($courseSem === ('Sem'.'-II')) $courseSem = 'Even';

        $program = trim($_POST['program'] ?? '');
        $year = strtoupper(trim($_POST['year_name'] ?? ''));
        $spec = strtoupper(trim($_POST['specialization'] ?? ''));
        if(strtoupper($spec)==='NULL') $spec = '';

        $courseCode = strtoupper(trim($_POST['course_code'] ?? $_POST['subject_code'] ?? ''));
        $courseFullName = trim($_POST['course_full_name'] ?? $_POST['subject_name'] ?? '');
        $courseAbbrev = strtoupper(trim($_POST['course_abbreviation'] ?? $_POST['subject_abbrev'] ?? $courseCode));
        if($courseAbbrev === '') $courseAbbrev = $courseCode;

        $subjectType = trim($_POST['subject_type'] ?? 'Theory');
        $credits = trim($_POST['credits'] ?? '');
        $th = trim($_POST['th_hours_week'] ?? '');
        $pr = trim($_POST['pr_hours_week'] ?? '');
        $tut = trim($_POST['tut_hours_week'] ?? '');

        if($id>0 && $courseCode!=='' && $courseFullName!==''){
            $stmt = $conn->prepare("UPDATE subjects SET academic_year=?, semester=?, program=?, year_name=?, specialization=?, course_code=?, course_full_name=?, subject_code=?, subject_name=?, subject_type=?, credits=?, th_hours_week=?, pr_hours_week=?, tut_hours_week=? WHERE id=?");
            $stmt->bind_param("ssssssssssssssi", $courseAy,$courseSem,$program,$year,$spec,$courseCode,$courseFullName,$courseAbbrev,$courseFullName,$subjectType,$credits,$th,$pr,$tut,$id);
            $stmt->execute();
            $message = "Course saved successfully for ".$courseAy." ".$courseSem.".";
        }
    }

    /* Inline Save: Classroom Resource */
    if(isset($_POST['save_master_classroom'])){
        $id = intval($_POST['classroom_id'] ?? 0);
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $incharge = trim($_POST['classroom_incharge'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $benches = trim($_POST['no_of_benches'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($id>0 && $room!==''){
            $stmt = $conn->prepare("UPDATE classrooms SET room_code=?, classroom_incharge=?, capacity=?, no_of_benches=?, smart_board=?, lcd_projector=?, wifi_available=?, block_name=?, floor_no=?, area_sq_meter=? WHERE id=?");
            $stmt->bind_param("ssssssssssi", $room,$incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area,$id);
            $stmt->execute();
            $message = "Classroom resource saved successfully.";
        }
    }

    /* Inline Save: Lab Resource */
    if(isset($_POST['save_master_lab'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $labName = trim($_POST['lab_name'] ?? '');
        $incharge = trim($_POST['lab_incharge'] ?? '');
        $assistant = trim($_POST['lab_assistant'] ?? '');
        $capacity = trim($_POST['lab_capacity'] ?? '');
        $pcs = trim($_POST['no_of_pcs'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($room!==''){
            $stmt = $conn->prepare("UPDATE lab_details SET lab_name=?, lab_incharge=?, lab_assistant=?, lab_capacity=?, no_of_pcs=?, block_name=?, floor_no=?, area_sq_meter=? WHERE room_code=?");
            $stmt->bind_param("sssssssss", $labName,$incharge,$assistant,$capacity,$pcs,$block,$floor,$area,$room);
            $stmt->execute();
            $message = "Lab resource saved successfully.";
        }
    }

    /* Inline Save: Tutorial Room */
    if(isset($_POST['save_master_tutorial'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $incharge = trim($_POST['tutorial_incharge'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $benches = trim($_POST['no_of_benches'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($room!==''){
            $stmt = $conn->prepare("UPDATE tutorial_room_details SET tutorial_incharge=?, capacity=?, no_of_benches=?, smart_board=?, lcd_projector=?, wifi_available=?, block_name=?, floor_no=?, area_sq_meter=? WHERE room_code=?");
            $stmt->bind_param("ssssssssss", $incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area,$room);
            $stmt->execute();
            $message = "Tutorial room saved successfully.";
        }
    }

    /* Inline Save: Faculty Block */
    if(isset($_POST['save_master_faculty_block'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $type = trim($_POST['faculty_block_type'] ?? '');
        $assigned = trim($_POST['assigned_to'] ?? '');
        $incharge = trim($_POST['incharge'] ?? '');
        $cabins = trim($_POST['cabin_numbers'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($room!==''){
            $stmt = $conn->prepare("UPDATE faculty_block_details SET faculty_block_type=?, assigned_to=?, incharge=?, cabin_numbers=?, capacity=?, block_name=?, floor_no=?, wifi_available=?, area_sq_meter=? WHERE room_code=?");
            $stmt->bind_param("ssssssssss", $type,$assigned,$incharge,$cabins,$capacity,$block,$floor,$wifi,$area,$room);
            $stmt->execute();
            $message = "Faculty block saved successfully.";
        }
    }

    /* Inline Save: Admin Block */
    if(isset($_POST['save_master_admin_block'])){
        $id = intval($_POST['admin_block_id'] ?? 0);
        $name = trim($_POST['admin_block_name'] ?? '');
        $location = strtoupper(trim($_POST['location'] ?? ''));
        $incharge = trim($_POST['incharge'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($id>0 && $location!==''){
            $stmt = $conn->prepare("UPDATE admin_block_details SET admin_block_name=?, location=?, incharge=?, block_name=?, floor_no=?, wifi_available=?, area_sq_meter=? WHERE id=?");
            $stmt->bind_param("sssssssi", $name,$location,$incharge,$block,$floor,$wifi,$area,$id);
            $stmt->execute();
            $message = "Admin block saved successfully.";
        }
    }

    /* Inline Save: Seminar Hall */
    if(isset($_POST['save_master_seminar'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $hall = trim($_POST['seminar_hall_name'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');

        if($room!==''){
            $stmt = $conn->prepare("UPDATE seminar_hall_details SET seminar_hall_name=?, capacity=?, smart_board=?, lcd_projector=?, wifi_available=?, block_name=?, floor_no=?, area_sq_meter=? WHERE room_code=?");
            $stmt->bind_param("sssssssss", $hall,$capacity,$smart,$lcd,$wifi,$block,$floor,$area,$room);
            $stmt->execute();
            $message = "Seminar hall saved successfully.";
        }
    }

    /* Add School Leadership row */
    if(isset($_POST['add_leadership_master'])){
        $conn->query("INSERT INTO leadership_master (designation, person_name, email, contact_no) VALUES ('New Designation','','','')");
        $message = "Leadership row added.";
    }

    /* Inline Save: School Leadership */
    if(isset($_POST['save_leadership_master'])){
        $id = intval($_POST['leadership_id'] ?? 0);
        $designation = trim($_POST['designation'] ?? '');
        $person = trim($_POST['person_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_no'] ?? '');
        if($id > 0 && $designation !== ''){
            $stmt = $conn->prepare("UPDATE leadership_master SET designation=?, person_name=?, email=?, contact_no=? WHERE id=?");
            $stmt->bind_param("ssssi", $designation, $person, $email, $contact, $id);
            $stmt->execute();
            $message = "Leadership information saved.";
        }
    }

    /* Inline Save: Class Teacher List - AY/Sem wise */
    if(isset($_POST['save_class_teacher_list'])){
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        $divisionId = intval($_POST['division_id'] ?? 0);
        $teacherName = trim($_POST['class_teacher'] ?? '');
        $teacherAbbrev = strtoupper(trim($_POST['class_teacher_abbrev'] ?? ''));
        $teacherEmpId = trim($_POST['class_teacher_emp_id'] ?? '');
        $teacherEmail = trim($_POST['class_teacher_email'] ?? '');
        $teacherContact = trim($_POST['class_teacher_contact'] ?? '');
        $ctAy = trim($_POST['ct_academic_year'] ?? $selected_year ?? '2025-26');
        $ctSem = trim($_POST['ct_semester'] ?? $selected_semester ?? 'Odd');
        if($ctSem === 'Odd') $ctSem = 'Odd';
        if($ctSem === 'Even') $ctSem = 'Even';

        $auto = autofill_class_teacher_from_faculty($conn, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
        $teacherName = $auto['class_teacher'];
        $teacherAbbrev = $auto['class_teacher_abbrev'];
        $teacherEmpId = $auto['class_teacher_emp_id'];
        $teacherEmail = $auto['class_teacher_email'];
        $teacherContact = $auto['class_teacher_contact'];

        if($divisionId > 0){
            $stmt = $conn->prepare("
                INSERT INTO class_teacher_assignments
                (id, academic_year, semester, division_id, class_teacher, class_teacher_abbrev, class_teacher_emp_id, class_teacher_email, class_teacher_contact)
                VALUES (NULLIF(?,0),?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    class_teacher=VALUES(class_teacher),
                    class_teacher_abbrev=VALUES(class_teacher_abbrev),
                    class_teacher_emp_id=VALUES(class_teacher_emp_id),
                    class_teacher_email=VALUES(class_teacher_email),
                    class_teacher_contact=VALUES(class_teacher_contact)
            ");
            $stmt->bind_param("ississsss", $assignmentId, $ctAy, $ctSem, $divisionId, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
            $stmt->execute();
            $message = "Class teacher information saved for ".$ctAy." ".$ctSem.".";
        }
    }

    /* Delete Class Teacher Assignment */
    if(isset($_POST['delete_class_teacher_list'])){
        $assignmentId = intval($_POST['assignment_id'] ?? 0);
        if($assignmentId > 0){
            $stmt = $conn->prepare("DELETE FROM class_teacher_assignments WHERE id=?");
            $stmt->bind_param("i", $assignmentId);
            $stmt->execute();
            $message = "Class teacher assignment deleted.";
        } else {
            $message = "No class teacher assignment exists for this row/year/semester.";
        }
    }

    /* Add New College Info Row from inline form */
    if(isset($_POST['save_new_college_info'])){
        $college = trim($_POST['college_name'] ?? '');
        $institute = trim($_POST['institute_name'] ?? '');
        if($college !== ''){
            $stmt = $conn->prepare("INSERT INTO college_info (college_name, institute_name) VALUES (?,?)");
            $stmt->bind_param("ss", $college, $institute);
            $stmt->execute();
            $message = "College information row added.";
        } else {
            $message = "College name is required.";
        }
    }

    /* Add New Leadership Row from inline form */
    if(isset($_POST['save_new_leadership_master'])){
        $designation = trim($_POST['designation'] ?? '');
        $person = trim($_POST['person_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_no'] ?? '');
        if($designation !== ''){
            $stmt = $conn->prepare("INSERT INTO leadership_master (designation, person_name, email, contact_no) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $designation, $person, $email, $contact);
            $stmt->execute();
            $message = "Leadership row added.";
        } else {
            $message = "Designation is required.";
        }
    }

    /* Add New Faculty Row from inline form */
    if(isset($_POST['save_new_master_faculty'])){
        $uid = trim($_POST['faculty_uid'] ?? '');
        $code = strtoupper(trim($_POST['faculty_code'] ?? ''));
        $name = trim($_POST['faculty_name'] ?? '');
        $academic = trim($_POST['academic_designation'] ?? '');
        $profile = trim($_POST['profile_designation'] ?? '');
        if(strtoupper($profile)==='NULL') $profile = '';
        $designation = ($profile !== '') ? $profile : $academic;
        $department = strtoupper(trim($_POST['department'] ?? ''));
        $specialization = strtoupper(trim($_POST['specialization'] ?? ''));
        if(strtoupper($specialization)==='NULL') $specialization = '';
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_no'] ?? '');
        $cabin = trim($_POST['cabin_no'] ?? '');
        $role = trim($_POST['role_type'] ?? 'Teaching');
        $active = strtoupper(trim($_POST['is_active'] ?? 'Y'));
        if(!in_array($active, ['Y','N'])) $active = 'Y';

        if($code !== '' && $name !== ''){
            if($uid === '') $uid = $code;
            $stmt = $conn->prepare("SELECT id FROM faculties WHERE faculty_code=? OR faculty_uid=? LIMIT 1");
            $stmt->bind_param("ss", $code, $uid);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if($existing){
                $stmt = $conn->prepare("UPDATE faculties SET faculty_uid=?, faculty_code=?, faculty_name=?, designation=?, academic_designation=?, profile_designation=?, department=?, specialization=?, email=?, contact_no=?, seating_location=?, cabin_no=?, role_type=?, is_active=? WHERE id=?");
                $stmt->bind_param("ssssssssssssssi", $uid,$code,$name,$designation,$academic,$profile,$department,$specialization,$email,$contact,$cabin,$cabin,$role,$active,$existing['id']);
                $stmt->execute();
                $message = "Faculty row updated successfully.";
            } else {
                $stmt = $conn->prepare("INSERT INTO faculties (faculty_uid, faculty_code, faculty_name, designation, academic_designation, profile_designation, department, specialization, email, contact_no, seating_location, cabin_no, role_type, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("ssssssssssssss", $uid,$code,$name,$designation,$academic,$profile,$department,$specialization,$email,$contact,$cabin,$cabin,$role,$active);
                $stmt->execute();
                $message = "Faculty row added successfully.";
            }
        } else {
            $message = "Faculty abbreviation and name are required.";
        }
    }

    /* Add New Course Row from inline form - exact Course Master template mapping */
    if(isset($_POST['save_new_master_subject'])){
        $courseAy = trim($_POST['course_academic_year'] ?? $selected_year ?? '2025-26');
        $courseSem = trim($_POST['course_semester'] ?? $selected_semester ?? 'Odd');
        if($courseSem === ('Sem'.'-I')) $courseSem = 'Odd';
        if($courseSem === ('Sem'.'-II')) $courseSem = 'Even';

        $program = trim($_POST['program'] ?? '');
        $year = strtoupper(trim($_POST['year_name'] ?? ''));
        $spec = strtoupper(trim($_POST['specialization'] ?? ''));
        if(strtoupper($spec)==='NULL') $spec = '';

        $courseCode = strtoupper(trim($_POST['course_code'] ?? $_POST['subject_code'] ?? ''));
        $courseFullName = trim($_POST['course_full_name'] ?? $_POST['subject_name'] ?? '');
        $courseAbbrev = strtoupper(trim($_POST['course_abbreviation'] ?? $_POST['subject_abbrev'] ?? ''));
        if($courseAbbrev === '') $courseAbbrev = $courseCode;

        $subjectType = trim($_POST['subject_type'] ?? 'Theory');
        $credits = trim($_POST['credits'] ?? '');
        $th = trim($_POST['th_hours_week'] ?? '');
        $pr = trim($_POST['pr_hours_week'] ?? '');
        $tut = trim($_POST['tut_hours_week'] ?? '');

        if($courseCode !== '' && $courseFullName !== ''){
            remove_subject_duplicate_constraints($conn);

            $stmt = $conn->prepare("INSERT INTO subjects (academic_year, semester, program, year_name, specialization, course_code, course_full_name, subject_code, subject_name, subject_type, credits, th_hours_week, pr_hours_week, tut_hours_week) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssssssss", $courseAy,$courseSem,$program,$year,$spec,$courseCode,$courseFullName,$courseAbbrev,$courseFullName,$subjectType,$credits,$th,$pr,$tut);

            if(@$stmt->execute()){
                $message = "Course row added successfully for ".$courseAy." ".$courseSem.".";
            } else {
                $message = "Course row could not be added. Please check database constraints.";
            }
        } else {
            $message = "Course Code and Course Full Name are required.";
        }
    }

    /* Bulk Upload Course Master Template - header based mapping exactly like uploaded template */
    if(isset($_POST['upload_course_master_template']) && isset($_FILES['course_master_file'])){
        remove_subject_duplicate_constraints($conn);

        $courseAy = trim($_POST['course_upload_academic_year'] ?? $selected_year ?? '2025-26');
        $courseSem = trim($_POST['course_upload_semester'] ?? $selected_semester ?? 'Odd');
        if($courseSem === ('Sem'.'-I')) $courseSem = 'Odd';
        if($courseSem === ('Sem'.'-II')) $courseSem = 'Even';

        $courseFile = $_FILES['course_master_file']['tmp_name'] ?? '';
        $courseName = $_FILES['course_master_file']['name'] ?? '';
        $uploaded = 0;
        $skipped = 0;

        if($courseFile && is_uploaded_file($courseFile)){
            $courseRows = read_course_master_template_rows($courseFile, $courseName);

            $headerIndex = -1;
            $headerMap = [];

            foreach($courseRows as $ri => $row){
                $norms = array_map('normalize_course_master_header', $row);
                if(in_array('srno', $norms) && in_array('program', $norms) && in_array('coursecode', $norms)){
                    $headerIndex = $ri;
                    foreach($norms as $ci => $h){
                        if($h === 'srno') $headerMap['sr_no'] = $ci;
                        if($h === 'program') $headerMap['program'] = $ci;
                        if($h === 'year') $headerMap['year_name'] = $ci;
                        if($h === 'specialization') $headerMap['specialization'] = $ci;
                        if($h === 'coursecode') $headerMap['course_code'] = $ci;
                        if($h === 'coursefullname' || $h === 'fullcoursename' || $h === 'fullname') $headerMap['course_full_name'] = $ci;
                        if($h === 'courseabbreviation' || $h === 'abbreviation') $headerMap['course_abbreviation'] = $ci;
                        if($h === 'type' || $h === 'coursetype') $headerMap['subject_type'] = $ci;
                        if($h === 'noofcredits' || $h === 'credits') $headerMap['credits'] = $ci;
                        if($h === 'thhrsweek' || $h === 'thhrswk') $headerMap['th_hours_week'] = $ci;
                        if($h === 'prhrsweek' || $h === 'prhrswk') $headerMap['pr_hours_week'] = $ci;
                        if($h === 'tuthrsweek' || $h === 'tuthrswk') $headerMap['tut_hours_week'] = $ci;
                    }
                    break;
                }
            }

            if($headerIndex < 0){
                $message = "Course template upload failed. Header row not found. Please use the standard template.";
            } else {
                foreach($courseRows as $ri => $row){
                    if($ri <= $headerIndex) continue;

                    $program = trim(cm_val($row, $headerMap, 'program'));
                    $year = strtoupper(trim(cm_val($row, $headerMap, 'year_name')));
                    $spec = strtoupper(trim(cm_val($row, $headerMap, 'specialization')));
                    if(strtoupper($spec)==='NULL') $spec = '';

                    $courseCode = strtoupper(trim(cm_val($row, $headerMap, 'course_code')));
                    $courseFullName = trim(cm_val($row, $headerMap, 'course_full_name'));
                    $courseAbbrev = strtoupper(trim(cm_val($row, $headerMap, 'course_abbreviation')));
                    $subjectType = trim(cm_val($row, $headerMap, 'subject_type'));

                    $credits = cm_num(cm_val($row, $headerMap, 'credits'));
                    $th = cm_num(cm_val($row, $headerMap, 'th_hours_week'));
                    $pr = cm_num(cm_val($row, $headerMap, 'pr_hours_week'));
                    $tut = cm_num(cm_val($row, $headerMap, 'tut_hours_week'));

                    if($courseCode === '' && $courseFullName === '' && $courseAbbrev === '') continue;
                    if($courseCode === '' || $courseFullName === ''){
                        $skipped++;
                        continue;
                    }
                    if($courseAbbrev === '') $courseAbbrev = $courseCode;
                    if($subjectType === '') $subjectType = 'Theory';

                    $stmt = $conn->prepare("INSERT INTO subjects (academic_year, semester, program, year_name, specialization, course_code, course_full_name, subject_code, subject_name, subject_type, credits, th_hours_week, pr_hours_week, tut_hours_week) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("ssssssssssssss", $courseAy,$courseSem,$program,$year,$spec,$courseCode,$courseFullName,$courseAbbrev,$courseFullName,$subjectType,$credits,$th,$pr,$tut);

                    if(@$stmt->execute()) $uploaded++;
                    else $skipped++;
                }
                $message = "Course template uploaded for ".$courseAy." ".$courseSem.". Rows inserted: ".$uploaded.". Skipped: ".$skipped.".";
            }
        }
    }

    /* Add New Department Row from inline form */
    if(isset($_POST['save_new_department_master'])){
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $hod = trim($_POST['hod_name'] ?? '');
        $programs = trim($_POST['programs_offered'] ?? '');
        if($department !== ''){
            $stmt = $conn->prepare("INSERT INTO department_master (department_name, department_code, hod_name, programs_offered) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $department, $department, $hod, $programs);
            $stmt->execute();
            $message = "Department row added.";
        } else {
            $message = "Department is required.";
        }
    }

    /* Add New Program Structure Row from inline form */
    if(isset($_POST['save_new_program_master'])){
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $program = trim($_POST['program_name'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $years = trim($_POST['years_offered'] ?? '');
        if($program !== ''){
            $stmt = $conn->prepare("INSERT INTO program_master (program_name, duration, department_name, years_offered) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $program, $duration, $department, $years);
            $stmt->execute();
            $message = "Program row added.";
        } else {
            $message = "Program name is required.";
        }
    }

    /* Add New Year & Division Structure Row from inline form */
    if(isset($_POST['save_new_year_division_structure'])){
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $program = trim($_POST['program_name'] ?? '');
        $year = trim($_POST['year_name'] ?? '');
        $spec = strtoupper(trim($_POST['specialization'] ?? 'NULL'));
        $divs = trim($_POST['no_of_divisions'] ?? '');
        $batches = trim($_POST['practical_batches'] ?? '');
        $strength = trim($_POST['batch_strength'] ?? '');
        if($department !== '' && $program !== '' && $year !== ''){
            $stmt = $conn->prepare("INSERT INTO year_division_structure (department_name, program_name, year_name, specialization, no_of_divisions, practical_batches, batch_strength) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssss", $department, $program, $year, $spec, $divs, $batches, $strength);
            $stmt->execute();
            $message = "Year & division row added.";
        } else {
            $message = "Department, program and year are required.";
        }
    }

    /* Add New Signatory Row from inline form */
    if(isset($_POST['save_new_signature_master'])){
        $ay = trim($_POST['academic_year'] ?? '');
        $sem = trim($_POST['semester'] ?? '');
        $department = trim($_POST['department_name'] ?? 'GENERAL');
        $role = strtoupper(trim($_POST['role_name'] ?? ''));
        $person = trim($_POST['person_name'] ?? '');
        $desig = trim($_POST['designation'] ?? '');
        if($ay !== '' && $sem !== '' && $role !== ''){
            $stmt = $conn->prepare("INSERT INTO timetable_signatures (academic_year, semester, department_name, specialization, role_name, person_name, designation) VALUES (?,?,?,?,?,?,?)");
            $general = 'GENERAL';
            $stmt->bind_param("sssssss", $ay, $sem, $department, $general, $role, $person, $desig);
            $stmt->execute();
            $message = "Signatory row added.";
        } else {
            $message = "Academic year, semester and role are required.";
        }
    }

    /* Add New Theory Time Slot Row from inline form */
    if(isset($_POST['save_new_timeslot_master'])){
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $slotType = trim($_POST['slot_type'] ?? 'Lecture');
        $breakName = trim($_POST['break_name'] ?? '');
        $active = trim($_POST['is_active'] ?? 'Y');
        if($slotType !== 'Break') $breakName = '';
        if(!in_array($active, ['Y','N'])) $active = 'Y';
        if($slotLabel !== ''){
            $stmt = $conn->prepare("INSERT INTO timeslot_master (slot_label, start_time, end_time, slot_type, break_name, is_active) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("ssssss", $slotLabel, $startTime, $endTime, $slotType, $breakName, $active);
            @$stmt->execute();
            $message = "Theory time slot row added.";
        } else {
            $message = "Slot label is required.";
        }
    }

    /* Add New Practical Time Slot Row from inline form */
    if(isset($_POST['save_new_practical_timeslot_master'])){
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $slotType = trim($_POST['slot_type'] ?? 'Practical');
        $active = trim($_POST['is_active'] ?? 'Y');
        if(!in_array($active, ['Y','N'])) $active = 'Y';
        if($slotLabel !== ''){
            $stmt = $conn->prepare("INSERT INTO practical_timeslot_master (slot_label, start_time, end_time, slot_type, is_active) VALUES (?,?,?,?,?)");
            $stmt->bind_param("sssss", $slotLabel, $startTime, $endTime, $slotType, $active);
            @$stmt->execute();
            $message = "Practical time slot row added.";
        } else {
            $message = "Slot label is required.";
        }
    }

    /* Add New Class Teacher Row from inline form - AY/Sem wise */
    if(isset($_POST['save_new_class_teacher_list'])){
        $divisionName = strtoupper(trim($_POST['division_name'] ?? ''));
        $teacherName = trim($_POST['class_teacher'] ?? '');
        $teacherAbbrev = strtoupper(trim($_POST['class_teacher_abbrev'] ?? ''));
        $teacherEmpId = trim($_POST['class_teacher_emp_id'] ?? '');
        $teacherEmail = trim($_POST['class_teacher_email'] ?? '');
        $teacherContact = trim($_POST['class_teacher_contact'] ?? '');
        $ctAy = trim($_POST['ct_academic_year'] ?? $selected_year ?? '2025-26');
        $ctSem = trim($_POST['ct_semester'] ?? $selected_semester ?? 'Odd');
        if($ctSem === 'Odd') $ctSem = 'Odd';
        if($ctSem === 'Even') $ctSem = 'Even';

        $auto = autofill_class_teacher_from_faculty($conn, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
        $teacherName = $auto['class_teacher'];
        $teacherAbbrev = $auto['class_teacher_abbrev'];
        $teacherEmpId = $auto['class_teacher_emp_id'];
        $teacherEmail = $auto['class_teacher_email'];
        $teacherContact = $auto['class_teacher_contact'];

        if($divisionName !== ''){
            $stmt = $conn->prepare("SELECT id FROM divisions WHERE division_name=? LIMIT 1");
            $stmt->bind_param("s", $divisionName);
            $stmt->execute();
            $existingDiv = $stmt->get_result()->fetch_assoc();

            if(!$existingDiv){
                $stmt = $conn->prepare("INSERT INTO divisions (division_name) VALUES (?)");
                $stmt->bind_param("s", $divisionName);
                $stmt->execute();
                $divisionId = intval($conn->insert_id);
            } else {
                $divisionId = intval($existingDiv['id']);
            }

            $stmt = $conn->prepare("
                INSERT INTO class_teacher_assignments
                (academic_year, semester, division_id, class_teacher, class_teacher_abbrev, class_teacher_emp_id, class_teacher_email, class_teacher_contact)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    class_teacher=VALUES(class_teacher),
                    class_teacher_abbrev=VALUES(class_teacher_abbrev),
                    class_teacher_emp_id=VALUES(class_teacher_emp_id),
                    class_teacher_email=VALUES(class_teacher_email),
                    class_teacher_contact=VALUES(class_teacher_contact)
            ");
            $stmt->bind_param("ssisssss", $ctAy, $ctSem, $divisionId, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
            $stmt->execute();

            $message = "Class teacher row saved for ".$ctAy." ".$ctSem.".";
        } else {
            $message = "Division is required.";
        }
    }

    /* Bulk Upload Class Teacher Template (.xlsx / .csv) */
    if(isset($_POST['upload_class_teacher_template']) && isset($_FILES['class_teacher_file'])){
        $ctFile = $_FILES['class_teacher_file']['tmp_name'] ?? '';
        $ctName = $_FILES['class_teacher_file']['name'] ?? '';
        $uploaded = 0;

        if($ctFile && is_uploaded_file($ctFile)){
            $templateRows = read_class_teacher_template_rows($ctFile, $ctName);

            foreach($templateRows as $row){
                $row = array_pad($row, 8, '');
                [$ctAy,$ctSem,$divisionName,$teacherName,$teacherAbbrev,$teacherEmpId,$teacherEmail,$teacherContact] = $row;

                $ctAy = trim($ctAy ?: ($selected_year ?: '2025-26'));
                $ctSem = trim($ctSem ?: ($selected_semester ?: 'Odd'));

                if($ctSem === 'Odd') $ctSem = 'Odd';
                if($ctSem === 'Even') $ctSem = 'Even';

                $divisionName = strtoupper(trim($divisionName));
                if($divisionName === '') continue;

                $auto = autofill_class_teacher_from_faculty($conn, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
                $teacherName = $auto['class_teacher'];
                $teacherAbbrev = $auto['class_teacher_abbrev'];
                $teacherEmpId = $auto['class_teacher_emp_id'];
                $teacherEmail = $auto['class_teacher_email'];
                $teacherContact = $auto['class_teacher_contact'];

                $stmt = $conn->prepare("SELECT id FROM divisions WHERE division_name=? LIMIT 1");
                $stmt->bind_param("s", $divisionName);
                $stmt->execute();
                $div = $stmt->get_result()->fetch_assoc();

                if(!$div){
                    $stmt = $conn->prepare("INSERT INTO divisions (division_name) VALUES (?)");
                    $stmt->bind_param("s", $divisionName);
                    $stmt->execute();
                    $divisionId = intval($conn->insert_id);
                } else {
                    $divisionId = intval($div['id']);
                }

                $stmt = $conn->prepare("
                    INSERT INTO class_teacher_assignments
                    (academic_year, semester, division_id, class_teacher, class_teacher_abbrev, class_teacher_emp_id, class_teacher_email, class_teacher_contact)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        class_teacher=VALUES(class_teacher),
                        class_teacher_abbrev=VALUES(class_teacher_abbrev),
                        class_teacher_emp_id=VALUES(class_teacher_emp_id),
                        class_teacher_email=VALUES(class_teacher_email),
                        class_teacher_contact=VALUES(class_teacher_contact)
                ");
                $stmt->bind_param("ssisssss", $ctAy, $ctSem, $divisionId, $teacherName, $teacherAbbrev, $teacherEmpId, $teacherEmail, $teacherContact);
                $stmt->execute();
                $uploaded++;
            }
        }

        $message = "Class teacher template uploaded. Rows processed: ".$uploaded;
    }

    /* Add New Physical Resource Rows from inline forms */
    if(isset($_POST['save_new_master_classroom'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $incharge = trim($_POST['classroom_incharge'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $benches = trim($_POST['no_of_benches'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($room !== ''){
            $stmt = $conn->prepare("INSERT INTO classrooms (room_code, classroom_incharge, capacity, no_of_benches, smart_board, lcd_projector, wifi_available, block_name, floor_no, area_sq_meter, resource_type) VALUES (?,?,?,?,?,?,?,?,?,?,'CLASSROOM')");
            $stmt->bind_param("ssssssssss", $room,$incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area);
            @$stmt->execute();
            $message = "Classroom row added.";
        } else $message = "Room no. is required.";
    }

    if(isset($_POST['save_new_master_lab'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $labName = trim($_POST['lab_name'] ?? '');
        $incharge = trim($_POST['lab_incharge'] ?? '');
        $assistant = trim($_POST['lab_assistant'] ?? '');
        $capacity = trim($_POST['lab_capacity'] ?? '');
        $pcs = trim($_POST['no_of_pcs'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($room !== '' && table_exists($conn, 'lab_details')){
            $stmt = $conn->prepare("INSERT INTO lab_details (room_code, lab_name, lab_incharge, lab_assistant, lab_capacity, no_of_pcs, block_name, floor_no, area_sq_meter) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssssss", $room,$labName,$incharge,$assistant,$capacity,$pcs,$block,$floor,$area);
            @$stmt->execute();
            $message = "Lab row added.";
        } else $message = "Lab no. is required.";
    }

    if(isset($_POST['save_new_master_tutorial'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $incharge = trim($_POST['tutorial_incharge'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $benches = trim($_POST['no_of_benches'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($room !== '' && table_exists($conn, 'tutorial_room_details')){
            $stmt = $conn->prepare("INSERT INTO tutorial_room_details (room_code, tutorial_incharge, capacity, no_of_benches, smart_board, lcd_projector, wifi_available, block_name, floor_no, area_sq_meter) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssss", $room,$incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area);
            @$stmt->execute();
            $message = "Tutorial room row added.";
        } else $message = "Tutorial room no. is required.";
    }

    if(isset($_POST['save_new_master_faculty_block'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $type = trim($_POST['faculty_block_type'] ?? '');
        $assigned = trim($_POST['assigned_to'] ?? '');
        $incharge = trim($_POST['incharge'] ?? '');
        $cabins = trim($_POST['cabin_numbers'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($room !== '' && table_exists($conn, 'faculty_block_details')){
            $stmt = $conn->prepare("INSERT INTO faculty_block_details (room_code, faculty_block_type, assigned_to, incharge, cabin_numbers, capacity, block_name, floor_no, wifi_available, area_sq_meter) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("ssssssssss", $room,$type,$assigned,$incharge,$cabins,$capacity,$block,$floor,$wifi,$area);
            @$stmt->execute();
            $message = "Faculty block row added.";
        } else $message = "Faculty block no. is required.";
    }

    if(isset($_POST['save_new_master_admin_block'])){
        $name = trim($_POST['admin_block_name'] ?? '');
        $location = strtoupper(trim($_POST['location'] ?? ''));
        $incharge = trim($_POST['incharge'] ?? '');
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($location !== ''){
            $stmt = $conn->prepare("INSERT INTO admin_block_details (admin_block_name, location, incharge, block_name, floor_no, wifi_available, area_sq_meter) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssss", $name,$location,$incharge,$block,$floor,$wifi,$area);
            @$stmt->execute();
            $message = "Admin block row added.";
        } else $message = "Admin block location is required.";
    }

    if(isset($_POST['save_new_master_seminar'])){
        $room = strtoupper(trim($_POST['room_code'] ?? ''));
        $hall = trim($_POST['seminar_hall_name'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $smart = strtoupper(trim($_POST['smart_board'] ?? 'N'));
        $lcd = strtoupper(trim($_POST['lcd_projector'] ?? 'N'));
        $wifi = strtoupper(trim($_POST['wifi_available'] ?? 'Y'));
        $block = trim($_POST['block_name'] ?? '');
        $floor = trim($_POST['floor_no'] ?? '');
        $area = trim($_POST['area_sq_meter'] ?? '');
        if($room !== '' && table_exists($conn, 'seminar_hall_details')){
            $stmt = $conn->prepare("INSERT INTO seminar_hall_details (room_code, seminar_hall_name, capacity, smart_board, lcd_projector, wifi_available, block_name, floor_no, area_sq_meter) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("sssssssss", $room,$hall,$capacity,$smart,$lcd,$wifi,$block,$floor,$area);
            @$stmt->execute();
            $message = "Seminar hall row added.";
        } else $message = "Seminar hall no. is required.";
    }


    /* Add Department Master row */
    if(isset($_POST['add_department_master'])){
        $conn->query("INSERT INTO department_master (department_name, department_code, hod_name, programs_offered) VALUES ('CSE','CSE','','')");
        $message = "Department row added.";
    }

    /* Inline Save: Department Master */
    if(isset($_POST['save_department_master'])){
        $id = intval($_POST['department_id'] ?? 0);
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $hod = trim($_POST['hod_name'] ?? '');
        $programs = trim($_POST['programs_offered'] ?? '');
        if($id > 0 && $department !== ''){
            $stmt = $conn->prepare("UPDATE department_master SET department_name=?, department_code=?, hod_name=?, programs_offered=? WHERE id=?");
            $stmt->bind_param("ssssi", $department, $department, $hod, $programs, $id);
            $stmt->execute();
            $message = "Department master saved.";
        }
    }

    /* Add Program Structure row */
    if(isset($_POST['add_program_master'])){
        $conn->query("INSERT INTO program_master (department_name, program_name, duration, specialization, years_offered) VALUES ('CSE','UG BTech CSE','4 Years','','FY, SY, TY, LY')");
        $message = "Program row added.";
    }

    /* Inline Save: Program Structure */
    if(isset($_POST['save_program_master'])){
        $id = intval($_POST['program_id'] ?? 0);
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $program = trim($_POST['program_name'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $years = trim($_POST['years_offered'] ?? '');
        if($id > 0 && $program !== ''){
            $stmt = $conn->prepare("UPDATE program_master SET department_name=?, program_name=?, duration=?, specialization='', years_offered=? WHERE id=?");
            $stmt->bind_param("ssssi", $department, $program, $duration, $years, $id);
            $stmt->execute();
            $message = "Program structure saved.";
        }
    }

    /* Delete Program Structure row */
    if(isset($_POST['delete_program_master'])){
        $id = intval($_POST['program_id'] ?? 0);
        if($id > 0){
            $stmt = $conn->prepare("DELETE FROM program_master WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $message = "Program row deleted successfully.";
        }
    }

    /* Add Year & Division Structure row */
    if(isset($_POST['add_year_division_structure'])){
        $conn->query("INSERT INTO year_division_structure (department_name, program_name, year_name, specialization, no_of_divisions, practical_batches, batch_strength) VALUES ('CSE','UG BTech CSE','SY','CORE','','A, B','')");
        $message = "Year & division structure row added.";
    }

    /* Inline Save: Year & Division Structure */
    if(isset($_POST['save_year_division_structure'])){
        $id = intval($_POST['year_structure_id'] ?? 0);
        $department = strtoupper(trim($_POST['department_name'] ?? ''));
        $program = trim($_POST['program_name'] ?? '');
        $year = trim($_POST['year_name'] ?? '');
        $specialization = strtoupper(trim($_POST['specialization'] ?? 'NULL'));
        $divCount = trim($_POST['no_of_divisions'] ?? '');
        $batches = trim($_POST['practical_batches'] ?? '');
        $batchStrength = trim($_POST['batch_strength'] ?? '');

        if($specialization === '') $specialization = 'NULL';

        if($id > 0 && $department !== '' && $program !== '' && $year !== ''){
            $stmt = $conn->prepare("
                UPDATE year_division_structure
                SET department_name=?, program_name=?, year_name=?, specialization=?, no_of_divisions=?, practical_batches=?, batch_strength=?
                WHERE id=?
            ");
            $stmt->bind_param("sssssssi", $department, $program, $year, $specialization, $divCount, $batches, $batchStrength, $id);
            $stmt->execute();
            $message = "Year & division structure saved.";
        }
    }


    /* Add Signatories row */
    if(isset($_POST['add_signature_master'])){
        $ay = trim($_POST['sig_academic_year'] ?? $selected_year);
        $sem = trim($_POST['sig_semester'] ?? $selected_semester);
        if($ay === '') $ay = '2025-26';
        if($sem === '') $sem = 'Odd';

        $stmt = $conn->prepare("
            INSERT INTO timetable_signatures
            (academic_year, semester, department_name, role_name, person_name, designation)
            VALUES (?, ?, 'GENERAL', 'PREPARED BY', '', '')
        ");
        $stmt->bind_param("ss", $ay, $sem);
        $stmt->execute();
        $message = "Signatory row added.";
    }

    /* Inline Save: Signatories Info */
    if(isset($_POST['save_signature_master'])){
        $id = intval($_POST['signature_id'] ?? 0);
        $ay = trim($_POST['academic_year'] ?? '');
        $sem = trim($_POST['semester'] ?? '');
        $department = trim($_POST['department_name'] ?? 'GENERAL');
        $specialization = 'GENERAL';
        $role = strtoupper(trim($_POST['role_name'] ?? ''));
        $person = trim($_POST['person_name'] ?? '');
        $desig = trim($_POST['designation'] ?? '');
        if($department === '') $department = 'GENERAL';

        $digitalSignaturePath = trim($_POST['existing_digital_signature_path'] ?? '');

        if($id > 0 && isset($_FILES['digital_signature']) && $_FILES['digital_signature']['error'] === UPLOAD_ERR_OK){
            $allowedExt = ['png','jpg','jpeg','webp'];
            $originalName = $_FILES['digital_signature']['name'] ?? '';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if(in_array($ext, $allowedExt)){
                $uploadDir = __DIR__ . "/uploads/signatures";
                if(!is_dir($uploadDir)){
                    @mkdir($uploadDir, 0775, true);
                }

                $safeRole = preg_replace('/[^A-Z0-9]+/i', '_', $role ?: 'SIGN');
                $fileName = "signature_" . $id . "_" . $safeRole . "_" . time() . "." . $ext;
                $targetPath = $uploadDir . "/" . $fileName;

                if(move_uploaded_file($_FILES['digital_signature']['tmp_name'], $targetPath)){
                    $digitalSignaturePath = "uploads/signatures/" . $fileName;
                } else {
                    $message = "Signature details saved, but digital signature upload failed.";
                }
            } else {
                $message = "Only PNG, JPG, JPEG or WEBP digital signature files are allowed.";
            }
        }

        if($id > 0 && $ay !== '' && $sem !== '' && $role !== ''){
            $stmt = $conn->prepare("
                UPDATE timetable_signatures
                SET academic_year=?, semester=?, department_name=?, role_name=?, person_name=?, designation=?, digital_signature_path=?
                WHERE id=?
            ");
            $stmt->bind_param("sssssssi", $ay, $sem, $department, $role, $person, $desig, $digitalSignaturePath, $id);
            $stmt->execute();

            if($message === '' || strpos($message, 'upload failed') === false){
                $message = "Signatory information saved.";
            }
        }
    }


    /* Add Time Slot row */
    if(isset($_POST['add_timeslot_master'])){
        $conn->query("INSERT INTO timeslot_master (slot_label, start_time, end_time, slot_type, break_name, is_active) VALUES ('New Slot','','','Lecture','','Y')");
        $message = "Time slot row added.";
    }

    /* Inline Save: Time Slots Info */
    if(isset($_POST['save_timeslot_master'])){
        $id = intval($_POST['timeslot_id'] ?? 0);
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $slotType = trim($_POST['slot_type'] ?? 'Lecture');
        $breakName = trim($_POST['break_name'] ?? '');
        $active = trim($_POST['is_active'] ?? 'Y');

        if($slotType !== 'Break') $breakName = '';
        if(!in_array($active, ['Y','N'])) $active = 'Y';

        if($id > 0 && $slotLabel !== ''){
            $stmt = $conn->prepare("
                UPDATE timeslot_master
                SET slot_label=?, start_time=?, end_time=?, slot_type=?, break_name=?, is_active=?
                WHERE id=?
            ");
            $stmt->bind_param("ssssssi", $slotLabel, $startTime, $endTime, $slotType, $breakName, $active, $id);
            $stmt->execute();
            $message = "Time slot information saved.";
        }
    }



    /* Add Practical Time Slot row */
    if(isset($_POST['add_practical_timeslot_master'])){
        $conn->query("INSERT INTO practical_timeslot_master (slot_label, start_time, end_time, slot_type, is_active) VALUES ('New Practical Slot','','','Practical','Y')");
        $message = "Practical time slot row added.";
    }

    /* Inline Save: Practical Time Slots Info */
    if(isset($_POST['save_practical_timeslot_master'])){
        $id = intval($_POST['practical_timeslot_id'] ?? 0);
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $slotType = trim($_POST['slot_type'] ?? 'Practical');
        $active = trim($_POST['is_active'] ?? 'Y');

        if(!in_array($active, ['Y','N'])) $active = 'Y';

        if($id > 0 && $slotLabel !== ''){
            $stmt = $conn->prepare("
                UPDATE practical_timeslot_master
                SET slot_label=?, start_time=?, end_time=?, slot_type=?, is_active=?
                WHERE id=?
            ");
            $stmt->bind_param("sssssi", $slotLabel, $startTime, $endTime, $slotType, $active, $id);
            $stmt->execute();
            $message = "Practical time slot information saved.";
        }
    }


    /* Add College Information row */
    if(isset($_POST['add_college_info'])){
        $conn->query("
            INSERT INTO college_info
            (college_name, institute_name, pro_vc_name, dean_name, associate_dean_academics, associate_dean_administration, college_tt_coordinator, department_tt_coordinator, cse_tt_coordinator, it_tt_coordinator, ash_tt_coordinator)
            VALUES ('MIT ADT University', 'School of Computing', '', '', '', '', '', '', '', '', '')
        ");
        $message = "College information row added.";
    }

    /* Inline Save: College Information */
    if(isset($_POST['save_college_info'])){
        $id = intval($_POST['college_id'] ?? 0);
        $collegeName = trim($_POST['college_name'] ?? '');
        $instituteName = trim($_POST['institute_name'] ?? '');

        if($id > 0 && $collegeName !== ''){
            $stmt = $conn->prepare("
                UPDATE college_info
                SET college_name=?, institute_name=?
                WHERE id=?
            ");
            $stmt->bind_param("ssi", $collegeName, $instituteName, $id);
            $stmt->execute();
            $message = "College information saved successfully.";
        }
    }

}

$program_options = ['UG','PG'];
$ug_department_options = ['CSE','IT','ASH'];
$pg_degree_options = ['MTech','MSc'];
$year_options = ['FY','SY','TY','LY'];
$specialization_options = ['CORE','AIA','AIEC','CC','BDCE','CSF','BT'];

function db_distinct_values($conn, $sql, $col){
    $out = [];
    $res = @$conn->query($sql);
    if($res){ while($r = $res->fetch_assoc()){ $v = trim((string)($r[$col] ?? '')); if($v !== '' && !in_array($v, $out)) $out[] = $v; } }
    return $out;
}
function merge_options_keep_order($base, $extra){
    foreach($extra as $v){ if($v !== '' && !in_array($v, $base)) $base[] = $v; }
    return $base;
}

if($hasDivProgram) $program_options = merge_options_keep_order($program_options, db_distinct_values($conn, "SELECT DISTINCT program_level FROM divisions WHERE program_level IS NOT NULL AND program_level!='' ORDER BY FIELD(program_level,'UG','PG'), program_level", "program_level"));
if($hasDivDept) $ug_department_options = merge_options_keep_order($ug_department_options, db_distinct_values($conn, "SELECT DISTINCT department FROM divisions WHERE department IS NOT NULL AND department!='' ORDER BY FIELD(department,'CSE','IT','ASH'), department", "department"));
if($hasDivDegree) $pg_degree_options = merge_options_keep_order($pg_degree_options, db_distinct_values($conn, "SELECT DISTINCT degree_type FROM divisions WHERE program_level='PG' AND degree_type IS NOT NULL AND degree_type!='' ORDER BY FIELD(degree_type,'MTech','MSc'), degree_type", "degree_type"));
if($hasDivYear) $year_options = merge_options_keep_order($year_options, db_distinct_values($conn, "SELECT DISTINCT year_name FROM divisions WHERE program_level='UG' AND year_name IS NOT NULL AND year_name!='' ORDER BY FIELD(year_name,'FY','SY','TY','LY'), year_name", "year_name"));
if($hasDivSpec) $specialization_options = merge_options_keep_order($specialization_options, db_distinct_values($conn, "SELECT DISTINCT specialization FROM divisions WHERE specialization IS NOT NULL AND specialization!='' ORDER BY FIELD(specialization,'CORE','AIA','AIEC','CC','BDCE','CSF','BT'), specialization", "specialization"));

if($selected_program !== '' && !in_array($selected_program, $program_options)) $selected_program = '';
if($selected_program == 'UG' && $selected_department !== '' && !in_array($selected_department, $ug_department_options)) $selected_department = '';
if($selected_program == 'PG' && $selected_degree !== '' && !in_array($selected_degree, $pg_degree_options)) $selected_degree = '';
// Rule 1: ASH department only allows FY
if($selected_department === 'ASH') $selected_year_name = 'FY';

if($hasDivDept && $hasDivProgram && $hasDivYear){
    $division_sql = "SELECT division_name FROM divisions WHERE 1=1";
    if($selected_program !== '') $division_sql .= " AND program_level='".$conn->real_escape_string($selected_program)."'";
    if($selected_program == 'UG' || $selected_program == ''){
        if($selected_department !== '') $division_sql .= " AND department='".$conn->real_escape_string($selected_department)."'";
        if($selected_year_name !== '') $division_sql .= " AND year_name='".$conn->real_escape_string($selected_year_name)."'";
        if($hasDivSpec && in_array($selected_year_name, ['TY','LY']) && $selected_specialization !== '') $division_sql .= " AND specialization LIKE '%".$conn->real_escape_string($selected_specialization)."%'";
    } elseif($selected_program == 'PG') {
        if($hasDivDegree && $selected_degree !== '') $division_sql .= " AND degree_type='".$conn->real_escape_string($selected_degree)."'";
    }
$division_sql .= "
ORDER BY
CASE
    WHEN division_name LIKE 'FY%' THEN 1
    WHEN division_name LIKE 'SY%' THEN 2
    WHEN division_name LIKE 'TY%' THEN 3
    WHEN division_name LIKE 'LY%' THEN 4
    ELSE 5
END,
CAST(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(division_name,'FY',''),
            'SY',''),
        'TY',''),
    'LY','')
AS UNSIGNED)";
}
else {
    $division_sql = "
SELECT division_name
FROM divisions
ORDER BY
CASE
    WHEN division_name LIKE 'FY%' THEN 1
    WHEN division_name LIKE 'SY%' THEN 2
    WHEN division_name LIKE 'TY%' THEN 3
    WHEN division_name LIKE 'LY%' THEN 4
    ELSE 5
END,
CAST(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(division_name,'FY',''),
            'SY',''),
        'TY',''),
    'LY','')
AS UNSIGNED)";
}
$division_list = $conn->query($division_sql);
$faculty_name_select = $hasFacultyUid ? "faculty_uid, faculty_code, faculty_name, designation" : "id AS faculty_uid, faculty_code, faculty_name, designation";
$faculty_list = $conn->query("SELECT $faculty_name_select FROM faculties WHERE $badFacultyFilter
                    ORDER BY
                    CASE
                        WHEN designation='Dean' THEN 1
                        WHEN designation='Associate Dean' THEN 2
                        WHEN designation LIKE '%Pro VC%' THEN 3
                        WHEN designation='Professor & Director' THEN 4
                        WHEN designation LIKE '%HOD%' OR designation LIKE '%HoD%' THEN 5
                        WHEN designation='Professor' THEN 6
                        WHEN designation='Associate Professor' THEN 7
                        WHEN designation='Assistant Professor' THEN 8
                        WHEN designation='Teaching Asst.' THEN 9
                        WHEN designation='Visiting Faculty' THEN 10
                        WHEN designation='Adjunct Faculty' THEN 11
                        ELSE 99
                    END,
                    faculty_name ASC");

if($selected_resource_type === 'lab' && $hasLabDetails){
    $classroom_list = $conn->query(
        "SELECT room_code
         FROM lab_details
         WHERE room_code IS NOT NULL AND room_code!=''
         ORDER BY room_code"
    );
} else {
    $classroom_where = "room_code REGEXP '^[NS][0-9]{3,4}$'";
    if($hasClassResourceType){
        $classroom_where .= " AND (resource_type IS NULL OR resource_type='' OR resource_type='CLASSROOM')";
    }
    $classroom_list = $conn->query(
        "SELECT room_code
         FROM classrooms
         WHERE $classroom_where
         ORDER BY room_code"
    );
}

$total_divisions = q1($conn, "SELECT COUNT(*) c FROM divisions", 0);
$total_entries = q1($conn, "SELECT COUNT(*) c FROM timetable_entries WHERE academic_year='".$conn->real_escape_string($selected_year)."' AND semester='".$conn->real_escape_string($selected_semester)."'", 0);
$total_faculties = q1($conn, "SELECT COUNT(*) c FROM faculties WHERE $badFacultyFilter", 0);
$total_rooms = q1($conn, "SELECT COUNT(*) c FROM classrooms", 0);
$sy_divisions = q1($conn, "SELECT COUNT(*) c FROM divisions WHERE division_name LIKE 'SY%'", 0);
$ty_divisions = q1($conn, "SELECT COUNT(*) c FROM divisions WHERE division_name LIKE 'TY%'", 0);
$ly_divisions = q1($conn, "SELECT COUNT(*) c FROM divisions WHERE division_name LIKE 'LY%'", 0);
$sy_entries = q1($conn, "SELECT COUNT(*) c FROM timetable_entries t JOIN divisions d ON t.division_id=d.id WHERE d.division_name LIKE 'SY%' AND t.academic_year='".$conn->real_escape_string($selected_year)."' AND t.semester='".$conn->real_escape_string($selected_semester)."'", 0);
$ty_entries = q1($conn, "SELECT COUNT(*) c FROM timetable_entries t JOIN divisions d ON t.division_id=d.id WHERE d.division_name LIKE 'TY%' AND t.academic_year='".$conn->real_escape_string($selected_year)."' AND t.semester='".$conn->real_escape_string($selected_semester)."'", 0);
$ly_entries = q1($conn, "SELECT COUNT(*) c FROM timetable_entries t JOIN divisions d ON t.division_id=d.id WHERE d.division_name LIKE 'LY%' AND t.academic_year='".$conn->real_escape_string($selected_year)."' AND t.semester='".$conn->real_escape_string($selected_semester)."'", 0);
$max_year_count = max(1, $sy_entries, $ty_entries, $ly_entries);


/* ===================== RESOURCE UTILIZATION =====================
   Classroom utilization = distinct occupied teaching slots / total weekly teaching slots.
   Break slots are excluded. Multiple batches in same room+slot count as one occupied slot.
   =============================================================== */
/* Utilization should consider only Monday-Friday slots up to 03:30 PM.
   Valid slots = 5 days × 6 teaching slots = 30 slots per room/faculty. */
$util_days = ["Monday","Tuesday","Wednesday","Thursday","Friday"];
$util_slots = [
    "08:45-09:40",
    "09:40-10:35",
    "10:50-11:45",
    "11:45-12:40",
    "01:40-02:35",
    "02:35-03:30"
];
$teaching_slot_count = count($util_slots);
$total_room_slots = count($util_days) * count($util_slots);
$breakSlotList = "'".implode("','", array_map([$conn,'real_escape_string'], array_keys($break_slots)))."'";
$utilDayList = "'".implode("','", array_map([$conn,'real_escape_string'], $util_days))."'";
$utilSlotList = "'".implode("','", array_map([$conn,'real_escape_string'], $util_slots))."'";
$aySafe = $conn->real_escape_string($selected_year);
$semSafe = $conn->real_escape_string($selected_semester);

$room_util_top = @$conn->query("SELECT c.room_code, COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS used_slots
    FROM classrooms c
    LEFT JOIN timetable_entries t ON t.classroom_id=c.id
        AND t.academic_year='$aySafe'
        AND t.semester='$semSafe'
        AND t.day_name IN ($utilDayList)
        AND t.time_slot IN ($utilSlotList)
    WHERE c.room_code REGEXP '^[NS][0-9]{3,4}$'
    GROUP BY c.id, c.room_code
    ORDER BY used_slots DESC, c.room_code ASC
    LIMIT 5");

$room_util_low = @$conn->query("SELECT c.room_code, COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS used_slots
    FROM classrooms c
    LEFT JOIN timetable_entries t ON t.classroom_id=c.id
        AND t.academic_year='$aySafe'
        AND t.semester='$semSafe'
        AND t.day_name IN ($utilDayList)
        AND t.time_slot IN ($utilSlotList)
    WHERE c.room_code REGEXP '^[NS][0-9]{3,4}$'
    GROUP BY c.id, c.room_code
    ORDER BY used_slots ASC, c.room_code ASC
    LIMIT 5");

$classroom_used_slots = 0;
$classroom_free_slots = $total_room_slots;
$classroom_util_percent = 0;
if($view == 'classroom' && $selected_classroom !== ''){
    $roomSafe = $conn->real_escape_string($selected_classroom);
    $classroom_used_slots = intval(q1($conn, "SELECT COUNT(DISTINCT CONCAT(t.day_name,'|',t.time_slot)) AS c
        FROM timetable_entries t
        JOIN classrooms c ON c.id=t.classroom_id
        WHERE c.room_code='$roomSafe'
          AND t.academic_year='$aySafe'
          AND t.semester='$semSafe'
          AND t.day_name IN ($utilDayList)
          AND t.time_slot IN ($utilSlotList)", 0));
    $classroom_free_slots = max(0, $total_room_slots - $classroom_used_slots);
    $classroom_util_percent = round(($classroom_used_slots / $total_room_slots) * 100, 1);
}


/* Analytics moved to separate analytics.php. Old in-page analytics queries removed. */

$division_meta_cols = "division_name";
if($hasDivDept) $division_meta_cols .= ", department";
if($hasDivSpec) $division_meta_cols .= ", specialization";
if($hasClassTeacher) $division_meta_cols .= ", class_teacher";
if($hasClassTeacherEmail) $division_meta_cols .= ", class_teacher_email";
if($hasClassTeacherContact) $division_meta_cols .= ", class_teacher_contact";
if($hasWef) $division_meta_cols .= ", wef_date";
$division_meta = [];
$stmt = $conn->prepare("SELECT $division_meta_cols FROM divisions WHERE division_name=? LIMIT 1");
$stmt->bind_param("s", $selected_division);
$stmt->execute();
$division_meta = $stmt->get_result()->fetch_assoc() ?: [];
$class_teacher = $division_meta['class_teacher'] ?? '';
$class_teacher_email = $division_meta['class_teacher_email'] ?? '';
$class_teacher_contact = $division_meta['class_teacher_contact'] ?? '';
$wef_date = $division_meta['wef_date'] ?? '';

/* Prefer AY/Sem-wise W.E.F date from manual timetable settings */
if($selected_division !== '' && $selected_year !== '' && $selected_semester !== '' && table_exists($conn, 'timetable_settings')){
    $stmtWefMeta = $conn->prepare("
        SELECT ts.wef_date
        FROM timetable_settings ts
        JOIN divisions d ON d.id=ts.division_id
        WHERE d.division_name=? AND ts.academic_year=? AND ts.semester=?
        LIMIT 1
    ");
    $stmtWefMeta->bind_param("sss", $selected_division, $selected_year, $selected_semester);
    $stmtWefMeta->execute();
    $wefMetaRow = $stmtWefMeta->get_result()->fetch_assoc();
    if($wefMetaRow && trim($wefMetaRow['wef_date'] ?? '') !== ''){
        $wef_date = $wefMetaRow['wef_date'];
    }
}
if($wef_date === '') $wef_date = '1st August 2025';
$wef_date = format_wef_date_display($wef_date);

$department_print = $division_meta['department'] ?? 'Computer Science & Engineering';
$specialization_print = $division_meta['specialization'] ?? '';
if($department_print === 'CSE') $department_print = 'Computer Science & Engineering';

/* ===================== FACULTY / RESOURCE HEADER META ===================== */
$faculty_meta = [];
if($view == 'faculty' && $selected_faculty !== ''){
    $facCols = "faculty_code, faculty_name";
    if($hasFacultyUid) $facCols .= ", faculty_uid"; else $facCols .= ", id AS faculty_uid";
    $facCols .= $hasFacultyDesignation ? ", designation" : ", '' AS designation";
    $facCols .= $hasFacultyEmail ? ", email" : ", '' AS email";
    $facCols .= $hasFacultyContact ? ", contact_no" : ", '' AS contact_no";
    $facCols .= $hasFacultySeating ? ", seating_location" : ", '' AS seating_location";
    $faculty_where_meta = $hasFacultyUid ? "(faculty_code=? OR faculty_uid=?)" : "faculty_code=?";
    $stmt = $conn->prepare("SELECT $facCols FROM faculties WHERE $faculty_where_meta LIMIT 1");
    if($hasFacultyUid) $stmt->bind_param("ss", $selected_faculty, $selected_faculty);
    else $stmt->bind_param("s", $selected_faculty);
    $stmt->execute();
    $faculty_meta = $stmt->get_result()->fetch_assoc() ?: [];
}

$resource_meta = [];
if($view == 'classroom' && $selected_classroom !== ''){
    $roomSafeForMeta = $conn->real_escape_string($selected_classroom);
    if($selected_resource_type === 'lab' && $hasLabDetails){
        $res = @$conn->query("SELECT room_code, lab_name, lab_incharge, lab_assistant, lab_capacity, no_of_pcs, lcd_projector, wifi_available, smart_board, block_name, floor_no, area_sq_meter FROM lab_details WHERE room_code='$roomSafeForMeta' LIMIT 1");
        if($res) $resource_meta = $res->fetch_assoc() ?: [];
    } else {
        $classMetaCols = "room_code";
        $classMetaCols .= $hasClassIncharge ? ", classroom_incharge" : ", '' AS classroom_incharge";
        $classMetaCols .= $hasClassCapacity ? ", capacity" : ", '' AS capacity";
        $classMetaCols .= $hasClassBenches ? ", no_of_benches" : ", '' AS no_of_benches";
        $classMetaCols .= $hasClassSmartBoard ? ", smart_board" : ", '' AS smart_board";
        $classMetaCols .= $hasClassLcd ? ", lcd_projector" : ", '' AS lcd_projector";
        $res = @$conn->query("SELECT $classMetaCols FROM classrooms WHERE room_code='$roomSafeForMeta' LIMIT 1");
        if($res) $resource_meta = $res->fetch_assoc() ?: [];
    }
}

$timetable = [];
if($view=="division"){
    $stmt = $conn->prepare("SELECT t.id,d.division_name,t.day_name,t.time_slot,s.subject_code,s.subject_name,".($hasSubjectType ? "s.subject_type," : "'Theory' AS subject_type,")." f.faculty_code,f.faculty_name,c.room_code,t.batch FROM timetable_entries t JOIN divisions d ON t.division_id=d.id JOIN subjects s ON t.subject_id=s.id LEFT JOIN faculties f ON t.faculty_id=f.id LEFT JOIN classrooms c ON t.classroom_id=c.id WHERE d.division_name=? AND t.academic_year=? AND t.semester=? ORDER BY FIELD(t.day_name,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), t.time_slot");
    $stmt->bind_param("sss", $selected_division, $selected_year, $selected_semester);
} elseif($view=="faculty"){
    $faculty_where = $hasFacultyUid ? "(f.faculty_code=? OR f.faculty_uid=?)" : "f.faculty_code=?";
    $sql = "SELECT t.id,d.division_name,t.day_name,t.time_slot,s.subject_code,s.subject_name,".($hasSubjectType ? "s.subject_type," : "'Theory' AS subject_type,")." f.faculty_code,f.faculty_name,c.room_code,t.batch FROM timetable_entries t JOIN faculties f ON t.faculty_id=f.id JOIN divisions d ON t.division_id=d.id JOIN subjects s ON t.subject_id=s.id LEFT JOIN classrooms c ON t.classroom_id=c.id WHERE $faculty_where AND t.academic_year=? AND t.semester=? ORDER BY FIELD(t.day_name,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), t.time_slot";
    $stmt = $conn->prepare($sql);
    if($hasFacultyUid) $stmt->bind_param("ssss", $selected_faculty, $selected_faculty, $selected_year, $selected_semester);
    else $stmt->bind_param("sss", $selected_faculty, $selected_year, $selected_semester);
} elseif($view=="classroom"){
    $stmt = $conn->prepare("SELECT MIN(t.id) AS id, GROUP_CONCAT(DISTINCT d.division_name ORDER BY d.division_name SEPARATOR ', ') AS division_name, t.day_name, t.time_slot, s.subject_code, s.subject_name, ".($hasSubjectType ? "s.subject_type," : "'Theory' AS subject_type,")." f.faculty_code, f.faculty_name, c.room_code, GROUP_CONCAT(DISTINCT t.batch ORDER BY t.batch SEPARATOR ', ') AS batch FROM timetable_entries t JOIN classrooms c ON t.classroom_id=c.id JOIN divisions d ON t.division_id=d.id JOIN subjects s ON t.subject_id=s.id LEFT JOIN faculties f ON t.faculty_id=f.id WHERE c.room_code=? AND t.academic_year=? AND t.semester=? GROUP BY t.day_name, t.time_slot, s.subject_code, s.subject_name, ".($hasSubjectType ? "s.subject_type," : "")." f.faculty_code, f.faculty_name, c.room_code ORDER BY FIELD(t.day_name,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), t.time_slot");
    $stmt->bind_param("sss", $selected_classroom, $selected_year, $selected_semester);
}
if(in_array($view,["division","faculty","classroom"])){
    $stmt->execute();
    $res = $stmt->get_result();
    while($row=$res->fetch_assoc()){ $timetable[$row['day_name']][$row['time_slot']][] = $row; }
}

$free_classrooms = [];
$free_resource_title = "Free Classrooms";

if($view=="free_classrooms"){
    if($selected_free_resource_type === 'lab'){
        $free_resource_title = "Free Labs";

        if(table_exists($conn, 'lab_details')){
            $stmt = $conn->prepare("
                SELECT l.room_code
                FROM lab_details l
                WHERE l.room_code IS NOT NULL
                  AND l.room_code!=''
                  AND l.room_code NOT IN (
                      SELECT c.room_code
                      FROM timetable_entries t
                      JOIN classrooms c ON t.classroom_id=c.id
                      WHERE t.day_name=?
                        AND t.time_slot=?
                        AND t.academic_year=?
                        AND t.semester=?
                        AND t.classroom_id IS NOT NULL
                  )
                ORDER BY l.room_code
            ");
            $stmt->bind_param("ssss", $selected_day, $selected_slot, $selected_year, $selected_semester);
            $stmt->execute();
            $free_classrooms = $stmt->get_result();
        }
    }
    elseif($selected_free_resource_type === 'tutorial'){
        $free_resource_title = "Free Tutorial Rooms";

        if(table_exists($conn, 'tutorial_room_details')){
            $stmt = $conn->prepare("
                SELECT tr.room_code
                FROM tutorial_room_details tr
                WHERE tr.room_code IS NOT NULL
                  AND tr.room_code!=''
                  AND tr.room_code NOT IN (
                      SELECT c.room_code
                      FROM timetable_entries t
                      JOIN classrooms c ON t.classroom_id=c.id
                      WHERE t.day_name=?
                        AND t.time_slot=?
                        AND t.academic_year=?
                        AND t.semester=?
                        AND t.classroom_id IS NOT NULL
                  )
                ORDER BY tr.room_code
            ");
            $stmt->bind_param("ssss", $selected_day, $selected_slot, $selected_year, $selected_semester);
            $stmt->execute();
            $free_classrooms = $stmt->get_result();
        }
    }
    else{
        $free_resource_title = "Free Classrooms";

        $stmt = $conn->prepare("
            SELECT room_code
            FROM classrooms
            WHERE room_code REGEXP '^[NS][0-9]{3,4}$'
              AND id NOT IN (
                  SELECT classroom_id
                  FROM timetable_entries
                  WHERE day_name=?
                    AND time_slot=?
                    AND academic_year=?
                    AND semester=?
                    AND classroom_id IS NOT NULL
              )
            ORDER BY room_code
        ");
        $stmt->bind_param("ssss", $selected_day, $selected_slot, $selected_year, $selected_semester);
        $stmt->execute();
        $free_classrooms = $stmt->get_result();
    }
}
$busy_slots = [];
if($view=="faculty_free"){
    $faculty_where = $hasFacultyUid ? "(f.faculty_code=? OR f.faculty_uid=?)" : "f.faculty_code=?";
    $sql = "SELECT day_name,time_slot FROM timetable_entries t JOIN faculties f ON t.faculty_id=f.id WHERE $faculty_where AND t.academic_year=? AND t.semester=?";
    $stmt = $conn->prepare($sql);
    if($hasFacultyUid) $stmt->bind_param("ssss", $selected_faculty, $selected_faculty, $selected_year, $selected_semester);
    else $stmt->bind_param("sss", $selected_faculty, $selected_year, $selected_semester);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r=$res->fetch_assoc()) $busy_slots[$r['day_name']][$r['time_slot']] = true;
}

$available_faculties = [];
if($view=="faculty_free" && $selected_free_day !== '' && $selected_free_slot !== ''){
    $sql = "SELECT f.faculty_code, f.faculty_name" . ($hasFacultyUid ? ", f.faculty_uid" : ", f.id AS faculty_uid") . "
            FROM faculties f
            WHERE $badFacultyFilter
            AND f.id NOT IN (
                SELECT faculty_id
                FROM timetable_entries
                WHERE day_name=?
                  AND time_slot=?
                  AND academic_year=?
                  AND semester=?
                  AND faculty_id IS NOT NULL
            )
            ORDER BY f.faculty_code";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $selected_free_day, $selected_free_slot, $selected_year, $selected_semester);
    $stmt->execute();
    $available_faculties = $stmt->get_result();
}

$legend_rows = [];
if(in_array($view,["division","faculty","classroom"])){
    foreach($timetable as $d => $slotArr){
        foreach($slotArr as $s => $entries){
            foreach($entries as $entry){
                $sc = trim($entry['subject_code'] ?? '');
                $sn = trim($entry['subject_name'] ?? '');
                $fc = trim($entry['faculty_code'] ?? '');
                $fn = trim($entry['faculty_name'] ?? '');
                if($sc === '' || $fc === '') continue;
                if(!is_clean_subject_for_legend($sc, $sn)) continue;
                if(!is_clean_faculty_for_legend($fc, $fn)) continue;
                if($sn === '') $sn = $sc;
                if($fn === '') $fn = $fc;
                if(!isset($legend_rows[$sc])){ $legend_rows[$sc] = ['subject_code'=>$sc,'subject_name'=>$sn,'faculty_names'=>[],'faculty_codes'=>[]]; }
                $legend_rows[$sc]['faculty_names'][$fc] = $fn;
                $legend_rows[$sc]['faculty_codes'][$fc] = $fc;
            }
        }
    }
    ksort($legend_rows);
}

$online_check_slots = ["08:45-09:40","09:40-10:35","10:50-11:45","11:45-12:40","01:40-02:35","02:35-03:30"];
$online_days = [];
if($view == 'division'){
    foreach($days as $day){
        $hasAnyEntry = false; $hasAnyRoom = false;
        foreach($online_check_slots as $slot){
            if(isset($timetable[$day][$slot])){
                foreach($timetable[$day][$slot] as $entry){
                    $hasAnyEntry = true;
                    if(trim((string)($entry['room_code'] ?? '')) !== '') $hasAnyRoom = true;
                }
            }
        }
        $online_days[$day] = ($hasAnyEntry && !$hasAnyRoom);
    }
}

$faculty_load = ['Theory'=>0,'Practical'=>0,'Mini Project'=>0,'Major Project'=>0,'Other'=>0,'Total'=>0];

if($view=='faculty'){

    $countedSlots = [];

    foreach($days as $day){
        foreach($slots as $slot){

            if(isset($break_slots[$slot])) continue;
            if(!isset($timetable[$day][$slot])) continue;

            $entries = $timetable[$day][$slot];

            $slotKey = $day.'|'.$slot;

            if(isset($countedSlots[$slotKey])) continue;

            $first = $entries[0];

            $subjectCode = strtoupper(trim($first['subject_code'] ?? ''));
            $subjectName = strtoupper(trim($first['subject_name'] ?? ''));
            $subjectType = strtoupper(trim($first['subject_type'] ?? ''));

            if($subjectCode === '') continue;

            $theoryOverrideSubjects = ['ML'];
            $practicalPattern = '/^(DSL|DMSL|PAIL|EEL|EEL-III|PLL|PLL-III|MCAL|ADAL|BDAL|DLNNL|HPCL|VAPTL|BTL|FSDL|IDSL|IOTAL|ITML|MLL|DCL|CDEL|CFL|CFDL|DLECL|EDAL|AWTL|ABDAL|BTAL|DMAL|DSSL|SOSL|DAPPDL|AIMLL|APP|PP|JP)$/';

            if(in_array($subjectCode, $theoryOverrideSubjects)){
                $faculty_load['Theory']++;
            }
            elseif(
                $subjectType === 'PRACTICAL' ||
                strpos($subjectName,'LAB') !== false ||
                preg_match($practicalPattern, $subjectCode)
            ){
                $faculty_load['Practical']++;
            }
            elseif(
                strpos($subjectCode,'PBL') !== false ||
                strpos($subjectCode,'MPP') !== false ||
                strpos($subjectName,'PROJECT') !== false
            ){
                $faculty_load['Mini Project']++;
            }
            elseif(
                in_array($subjectCode, ['PAL','LIBRARY','MOOC','MENTOR','EXPERT SESSION','REMEDIAL','NPTEL','SCIL','SCIL-APT','SCIL-PS'])
            ){
                $faculty_load['Other']++;
            }
            else{
                $faculty_load['Theory']++;
            }

            $faculty_load['Total']++;
            $countedSlots[$slotKey] = true;
        }
    }
}
$signatures = [
    'PREPARED BY' => ['person_name'=>'','designation'=>'','digital_signature_path'=>''],
    'CHECKED BY' => ['person_name'=>'','designation'=>'','digital_signature_path'=>''],
    'RECOMMENDED BY' => ['person_name'=>'','designation'=>'','digital_signature_path'=>''],
    'APPROVED BY' => ['person_name'=>'','designation'=>'','digital_signature_path'=>'']
];

if(table_exists($conn, 'timetable_signatures')){
    $sigDeptScope = 'GENERAL';
    if($view == 'division'){
        $rawDept = strtoupper(trim($division_meta['department'] ?? ''));

        if($rawDept === 'CSE'){
            $sigDeptScope = 'CSE';
        } elseif($rawDept === 'IT'){
            $sigDeptScope = 'IT';
        } elseif($rawDept === 'ASH'){
            $sigDeptScope = 'ASH';
        }
    }

    $aySig = $selected_year ?: '2025-26';
    $semSig = $selected_semester ?: 'Odd';

    /* Load only CHECKED / RECOMMENDED / APPROVED from Signatories Info.
       PREPARED BY is division-wise and comes only from Manual TT Import grid. */
    $stmt = $conn->prepare("
        SELECT role_name, person_name, designation, department_name, digital_signature_path
        FROM timetable_signatures
        WHERE academic_year=?
          AND semester=?
          AND department_name IN ('GENERAL', ?)
          AND UPPER(role_name) IN ('CHECKED BY','RECOMMENDED BY','APPROVED BY')
        ORDER BY
            CASE WHEN department_name='GENERAL' THEN 1 ELSE 2 END,
            FIELD(role_name,'CHECKED BY','RECOMMENDED BY','APPROVED BY')
    ");
    $stmt->bind_param("sss", $aySig, $semSig, $sigDeptScope);
    $stmt->execute();
    $rs = $stmt->get_result();

    while($r=$rs->fetch_assoc()){
        $role = strtoupper(trim($r['role_name']));
        if($role !== '' && isset($signatures[$role])){
            $signatures[$role] = [
                'person_name'=>$r['person_name'] ?? '',
                'designation'=>$r['designation'] ?? '',
                'digital_signature_path'=>$r['digital_signature_path'] ?? ''
            ];
        }
    }
}

/* Division-wise Prepared By from Manual Timetable Grid.
   If it is blank/not saved, the Prepared By footer remains blank. */
if($view == 'division' && $selected_division !== '' && $selected_year !== '' && $selected_semester !== '' && table_exists($conn, 'timetable_settings')){
    $stmtPrep = $conn->prepare("
        SELECT ts.prepared_by
        FROM timetable_settings ts
        JOIN divisions d ON d.id = ts.division_id
        WHERE d.division_name=? AND ts.academic_year=? AND ts.semester=?
        LIMIT 1
    ");
    $stmtPrep->bind_param("sss", $selected_division, $selected_year, $selected_semester);
    $stmtPrep->execute();
    $prepRow = $stmtPrep->get_result()->fetch_assoc();
    $divisionPreparedBy = trim($prepRow['prepared_by'] ?? '');
    if($divisionPreparedBy !== ''){
        $signatures['PREPARED BY']['person_name'] = $divisionPreparedBy;
        $signatures['PREPARED BY']['designation'] = '';
        $signatures['PREPARED BY']['digital_signature_path'] = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MIT ADT Timetable Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">
<style>
/* =========================================================
   MIT-ADT UNIVERSITY THEME
   Primary: Deep Purple #5a1f8c / #7b2fb5
   Accent: Gold #e8a020
   Background: #f5f0fa (light purple tint)
   ========================================================= */
*, *::before, *::after { box-sizing: border-box; }
html { height: 100%; }
body {
    margin: 0; padding: 0; height: 100%;
    font-family: "Poppins", "Segoe UI", Arial, sans-serif;
    background: #f5f0fa;
    color: #1a0533;
    font-size: 14px;
    overflow: hidden;
}

/* =========================================================
   TOP BAR — MIT-ADT purple gradient
   ========================================================= */
.topbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 200;
    background: linear-gradient(90deg, #3d0f6b 0%, #5a1f8c 50%, #7b2fb5 100%);
    height: 58px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
    box-shadow: 0 3px 16px rgba(90,31,140,.45);
    border-bottom: 3px solid #C1345C;
}
.brand { display: flex; align-items: center; gap: 12px; }
.logo-img {
    width: auto; height: 58px; object-fit: contain;
    background: #fff;  padding: 3px;
}
.brand-title {font-family: 'Cinzel', serif; font-size: 18px; font-weight: 900; color: #fff; line-height: 1.12; letter-spacing: .2px; text-transform: uppercase; }
.brand-subtitle {font-family: 'Cinzel', serif; font-size: 15px; color: #f1d9ff; font-weight: 700; }
.badge {
    background: #C1345C;
    color: #fff;
    border: none;
    padding: 5px 14px; border-radius: 999px;
    font-weight: 800; font-size: 12.5px;
    letter-spacing: .3px;
    box-shadow: 0 2px 8px rgba(193,52,92,.4);
}

/* =========================================================
   LAYOUT
   ========================================================= */
.layout {
    display: flex;
    height: calc(100vh - 58px);
    margin-top: 58px;
    overflow: hidden;
}

/* =========================================================
   SIDEBAR — light purple tint with purple accents
   ========================================================= */
.sidebar {
    width: 210px;
    flex-shrink: 0;
    background: #fff;
    border-right: 2px solid #d4b8ea;
    padding: 14px 10px 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.side-title {
    font-size: 9.5px; text-transform: uppercase;
    color: #9b59c5; font-weight: 800; letter-spacing: 1.2px;
    margin: 10px 4px 5px;
}
.side-card {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; border-radius: 9px;
    font-size: 12.5px; font-weight: 600;
    text-decoration: none; color: #3d0f6b;
    transition: background .15s, color .15s, transform .15s;
    white-space: nowrap;
}
.side-card:hover { background: #ede0f7; color: #5a1f8c; transform: translateX(3px); }
.side-card.active { background: #d4b8ea; color: #3d0f6b; font-weight: 700; }

/* =========================================================
   MAIN CONTENT
   ========================================================= */
.main {
    flex: 1;
    overflow-y: auto;
    padding: 18px 22px 32px;
    min-width: 0;
}

/* =========================================================
   CONTROL BAR PANEL
   ========================================================= */
.panel {
    background: #fff;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: 0 3px 14px rgba(90,31,140,.10);
    margin-bottom: 16px;
    border: 1px solid #e8d5f5;
}
.controls {
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
}
select, input[type="text"], input[type="password"] {
    padding: 7px 10px; border-radius: 8px;
    border: 1.5px solid #c9a0e0;
    background: #fff; color: #1a0533;
    font-size: 12.5px; height: 34px;
    font-family: "Poppins", sans-serif;
    transition: border-color .15s, box-shadow .15s;
}
select:focus, input:focus {
    outline: none; border-color: #7b2fb5;
    box-shadow: 0 0 0 3px rgba(123,47,181,.15);
}
button {
    padding: 7px 14px; border-radius: 8px; border: none;
    background: #7b2fb5; color: #fff;
    cursor: pointer; font-weight: 700; font-size: 12.5px; height: 34px;
    font-family: "Poppins", sans-serif;
    transition: background .15s, transform .1s;
}
button:hover { background: #5a1f8c; transform: translateY(-1px); }
.print-btn { background: #C1345C; }
.print-btn:hover { background: #A82649; }
.logout-link { color: #c0392b; font-weight: 700; text-decoration: none; font-size: 12.5px; }
.quick-search { min-width: 180px; }


/* ADMIN TOP BAR + MODULE FILTERS */
.admin-actions-spacer{ margin-left:auto; }
.module-filters{
    display:flex;
    align-items:center;
    gap:10px;
    margin:10px 0 16px;
}
.module-filters form{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    margin:0;
}
.module-filters select{
    min-width:170px;
}

/* =========================================================
   DASHBOARD
   ========================================================= */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 16px;
}
.stat-card {
    background: #fff; border-radius: 12px; padding: 16px 14px;
    text-align: center;
    box-shadow: 0 3px 14px rgba(90,31,140,.09);
    border-top: 5px solid #7b2fb5;
}
.stat-card h2 { margin: 0 0 4px; font-size: 28px; font-weight: 800; color: #3d0f6b; }
.stat-card span { font-size: 11px; color: #8a6ba8; font-weight: 600; }
.blue  { border-color: #7b2fb5; }
.green { border-color: #C1345C; }
.orange{ border-color: #9b59c5; }
.purple{ border-color: #3d0f6b; }

.dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
.chart-row { margin: 10px 0; }
.chart-label { display: flex; justify-content: space-between; font-weight: 700; font-size: 12px; margin-bottom: 4px; color: #3d0f6b; }
.bar-bg { height: 12px; background: #ede0f7; border-radius: 999px; overflow: hidden; }
.bar       { height: 100%; border-radius: 999px; background: linear-gradient(90deg,#5a1f8c,#9b59c5); }
.greenbar {
    background: linear-gradient(90deg,#C1345C,#D84D73);
}
.orangebar { background: linear-gradient(90deg,#7b2fb5,#c9a0e0); }
.util-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
.util-mini-card { background:#f9f4ff; border:1px solid #c9a0e0; border-radius:10px; padding:12px; text-align:center; }
.util-mini-card h3 { margin:0; color:#3d0f6b; font-size:24px; }
.util-mini-card span { color:#6b4a86; font-size:11px; font-weight:700; }
.util-table { width:100%; border-collapse:collapse; }
.util-table th, .util-table td { border:1px solid #e0c8f0; padding:7px 9px; font-size:12px; text-align:left; }
.util-table th { background:#ede0f7; color:#3d0f6b; font-weight:900; }
.util-percent-bar { height:9px; background:#e8d9f4; border-radius:999px; overflow:hidden; min-width:90px; }
.util-percent-fill { height:100%; background:linear-gradient(90deg,#6a1b9a,#8e24aa,#C1345C); border-radius:999px; }
.page-heading { font-size: 18px; font-weight: 800; color: #3d0f6b; margin-bottom: 12px; }
.login-card-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.login-card { border-radius: 10px; padding: 16px; background: #f9f4ff; border: 1px solid #c9a0e0; }
.login-card h3 { margin-top: 0; color: #5a1f8c; font-size: 14px; }


/* =========================================================
   FREE ROOMS / FACULTY FREE
   ========================================================= */
.grid-list { display: grid; grid-template-columns: repeat(auto-fill,minmax(120px,1fr)); gap: 10px; }
.free-box { background: #f0e8fa; color: #5a1f8c; border: 1px solid #c9a0e0; padding: 11px; border-radius: 9px; text-align: center; font-weight: 800; font-size: 12px; }
.busy-box { background: #fdf0f0; color: #a93226; border: 1px solid #f5b7b1; padding: 9px; border-radius: 9px; text-align: center; font-weight: 800; font-size: 11px; }

/* =========================================================
   MESSAGES
   ========================================================= */
.msg { background: #f0e8fa; color: #5a1f8c; border-left: 5px solid #7b2fb5; padding: 11px 14px; border-radius: 9px; margin-bottom: 14px; font-weight: 700; font-size: 13px; }

/* =========================================================
   SCREEN TIMETABLE
   ========================================================= */
.tt-page {
    background: #fff;
    border: 1px solid #c9a0e0;
    border-radius: 10px;
    overflow: hidden;
    font-family: Arial, sans-serif;
    color: #000;
    width: 100%;
}
.tt-header { border-bottom: 1px solid #000; }
.tt-header-row { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid #000; }
.tt-school, .tt-title { font-weight: 900; padding: 8px 10px; font-size: 16px; text-align: center; }
.tt-school { border-right: 1px solid #000; background: #f9f4ff; }
.tt-dept { color: #5a1f8c; font-weight: 900; font-size: 17px; padding: 6px 10px; border-bottom: 1px solid #000; text-align: center; }
.tt-info { display: grid; grid-template-columns: 1fr 2fr 1.2fr; background: #ede0f7; border-bottom: 1px solid #000; font-size: 13px; font-weight: 900; }
.tt-info div { padding: 4px 8px; }
.faculty-info-grid, .resource-info-grid { grid-template-columns: 1.2fr 1.4fr 1.2fr; }
.tt-teacher { background: #3d0f6b; color: #fff; padding: 5px 10px; font-size: 12.5px; font-weight: 900; }
.table-wrap { overflow-x: auto; }
.tt-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.tt-table th, .tt-table td { border: 1px solid #000; text-align: center; vertical-align: middle; padding: 2px 2px; font-size: 11px; }
.tt-table th { font-weight: 900; background: #f9f4ff; }
.tt-table td { height: 55px; overflow: visible; }
.tt-table td.merged-slot {
    background: #fbf8ff !important;
}
.day { background: #ede0f7; font-weight: 900; width: 80px; font-size: 11.5px; }
.online-day { background: #ff00ff !important; color: #000; }

/* Fixed timetable column widths: break columns are narrow, lecture/practical columns get more space */
.tt-col-day { width: 8%; }
.tt-col-main { width: 11.35%; }
.tt-col-break { width: 3.7%; }
.tt-col-lunch { width: 4.6%; }

/* Compact entry format: Course/Batch line + Faculty | Room line */
.tt-card {
    padding: 1px 2px;
    font-size: 10px;
    line-height: 1.08;
    margin: 0 auto 1px;
    width: 100%;
    page-break-inside: avoid;
    break-inside: avoid;
}
.tt-card + .tt-card { margin-top: 2px; }
.tt-subject { font-weight: 900; font-size: 10.5px; color: #3d0f6b; line-height: 1.08; }
.tt-meta { font-size: 8.7px; color: #5a1f8c; font-weight: 700; line-height: 1.08; white-space: nowrap; }
.tt-room-inline { color: #666; font-weight: 600; }

/* Practical / Lab / Tutorial entries: different text color in UI */
.tt-card.practical .tt-subject { color: #0b6b35 !important; }
.tt-card.practical .tt-meta { color: #0f7a3b !important; }
.tt-card.project .tt-subject { color: #6b21a8 !important; }
.tt-card.project .tt-meta { color: #7e22ce !important; }
.break-common { background: #f9f4ff !important; font-weight: 900; writing-mode: vertical-rl; text-orientation: upright; letter-spacing: 1px; font-size: 9px !important; padding: 1px !important; }
.lunch-common { background: #f9f4ff !important; font-weight: 900; writing-mode: vertical-rl; text-orientation: upright; letter-spacing: 1px; font-size: 9px !important; padding: 1px !important; }
.yellow { background: #fef9e7 !important; }
.grey   { background: #f5effe !important; }
.pink   { background: #fdf0ff !important; }

/* Legend / load / signatures */
.legend-table, .sign-table, .load-table { width: 100%; border-collapse: collapse; background: #fff; }
.legend-table th, .legend-table td,
.sign-table th, .sign-table td,
.load-table th, .load-table td { border: 1px solid #000; padding: 4px 5px; font-size: 11px; color: #000; }
.legend-table th, .sign-table th, .load-table th { text-align: center; font-weight: 900; background: #ede0f7; }
.legend-code { text-align: center; font-weight: 900; }
.sign-table { text-align: center; }
.load-box { margin: 0 auto 10px; max-width: 850px; }
.load-table td { text-align: center; font-weight: 800; }

/* FOOTER */
.footer { text-align: center; padding: 16px; color: #8a6ba8; font-size: 11px; font-weight: 500; }

/* =========================================================
   PRINT / PDF — A4 Portrait
   ========================================================= */
@media print {
    @page {
        size: A4 portrait;
        margin: 4mm 8mm 4mm 8mm;
    }
    html, body {
        overflow: visible !important;
        height: auto !important;
        width: 100% !important;
        background: #fff !important;
        font-size: 8pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .topbar, .sidebar, .controls,
    .no-print, .footer,
    .stats-row, .dashboard-grid,
    .load-box { display: none !important; }
    .layout { display: block !important; height: auto !important; overflow: visible !important; }
    .main   { padding: 0 !important; overflow: visible !important; height: auto !important; width: 100% !important; }
    .panel  { box-shadow: none !important; margin: 0 !important; padding: 0 !important; border-radius: 0 !important; border: none !important; }
    .tt-page { width: 194mm !important; margin: 0 auto !important; border: 1.5pt solid #000 !important; border-radius: 0 !important; overflow: visible !important; page-break-inside: avoid !important; break-inside: avoid !important; display: block !important; }
    .tt-header-row { display: grid !important; grid-template-columns: 1fr 1fr !important; border-bottom: 1pt solid #000 !important; }
    .tt-school, .tt-title { font-size: 9pt !important; font-weight: 900 !important; padding: 3pt 5pt !important; line-height: 1.3 !important; text-align: center !important; }
    .tt-school { border-right: 1pt solid #000 !important; background: #f9f4ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tt-dept { font-size: 10pt !important; font-weight: 900 !important; color: #5a1f8c !important; padding: 3pt 5pt !important; border-bottom: 1pt solid #000 !important; text-align: center !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tt-info { display: grid !important; grid-template-columns: 1fr 2fr 1.2fr !important; background: #ede0f7 !important; border-bottom: 1pt solid #000 !important; font-size: 8pt !important; font-weight: 900 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tt-info div { padding: 3pt 5pt !important; }
    .faculty-info-grid, .resource-info-grid { grid-template-columns: 1.2fr 1.4fr 1.2fr !important; }
    .tt-teacher { background: #3d0f6b !important; color: #fff !important; font-size: 7.5pt !important; font-weight: 900 !important; padding: 3pt 5pt !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .table-wrap { overflow: visible !important; }
    .tt-table { width: 100% !important; table-layout: fixed !important; border-collapse: collapse !important; }
    .tt-table th {
    font-size: 6.2pt !important;
    font-weight: 900 !important;
    padding: 2pt 1pt !important;
    line-height: 1 !important;
    background: #f9f4ff !important;
    border: 1pt solid #000 !important;
    text-align: center !important;
    vertical-align: middle !important;
    height: 15pt !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
    .tt-table td { border: 1pt solid #000 !important; text-align: center !important; vertical-align: middle !important; padding: 2pt 1pt !important; height: 24mm !important; }
    .day { width: 14mm !important; font-size: 7.5pt !important; font-weight: 900 !important; background: #ede0f7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .break-common {
    background: #fff !important;
    font-weight: 900 !important;
    writing-mode: vertical-rl !important;
    text-orientation: upright !important;
    letter-spacing: .5px !important;
    font-size: 5.5pt !important;
    width: 8mm !important;
    min-width: 8mm !important;
    max-width: 8mm !important;
}

.lunch-common {
    background: #fff !important;
    font-weight: 900 !important;
    writing-mode: vertical-rl !important;
    text-orientation: upright !important;
    letter-spacing: .5px !important;
    font-size: 5.5pt !important;
    width: 8.5mm !important;
    min-width: 8.5mm !important;
    max-width: 8.5mm !important;
}
    .tt-card { font-size: 7pt !important; line-height: 1.05 !important; padding: 0 !important; margin: 0 0 1pt 0 !important; }
    .tt-card + .tt-card { margin-top: 1pt !important; }
    .tt-subject { font-size: 7.2pt !important; font-weight: 900 !important; color: #3d0f6b !important; line-height: 1.05 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tt-meta { font-size: 6.2pt !important; color: #5a1f8c !important; font-weight: 700 !important; line-height: 1.05 !important; white-space: nowrap !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tt-room-inline { color: #666 !important; }

    /* Practical / Lab / Tutorial entries: different text color in print/PDF */
    .tt-card.practical .tt-subject { color: #0b6b35 !important; }
    .tt-card.practical .tt-meta { color: #0f7a3b !important; }
    .tt-card.project .tt-subject { color: #6b21a8 !important; }
    .tt-card.project .tt-meta { color: #7e22ce !important; }
    .online-day { background: #ff00ff !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .yellow { background: #fef9e7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .grey { background: #f5effe !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .pink { background: #fdf0ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .legend-table { width: 100% !important; border-collapse: collapse !important; margin-top: 0 !important; }
    .legend-table th { font-size: 7.5pt !important; font-weight: 900 !important; padding: 3pt 4pt !important; border: 1pt solid #000 !important; background: #ede0f7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .legend-table td { font-size: 7pt !important; padding: 2.5pt 4pt !important; border: 1pt solid #000 !important; }
    .sign-table { width: 100% !important; border-collapse: collapse !important; text-align: center !important; }
    .sign-table th { font-size: 7.5pt !important; font-weight: 900 !important; padding: 4pt 3pt !important; border: 1pt solid #000 !important; background: #ede0f7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sign-table td { font-size: 7pt !important; padding: 5pt 3pt !important; border: 1pt solid #000 !important; vertical-align: bottom !important; }
    .tt-page, .tt-table, .legend-table, .sign-table { page-break-inside: avoid !important; break-inside: avoid !important; }
}
    .tt-col-main {
    width: 10.7% !important;
}

.tt-col-break {
    width: 4.4% !important;
}

.tt-col-lunch {
    width: 4.9% !important;
}

/* COMMON INFO CLEAN SECTION UI */
.common-info-home{
    width:100%;
}
.common-info-title{
    font-size:20px;
    font-weight:900;
    color:#3d0f6b;
    margin:0 0 16px;
}
.common-info-grid-clean{
    display:grid;
    grid-template-columns:repeat(3, minmax(230px,1fr));
    gap:18px;
    width:100%;
}
.common-info-card-clean{
    display:flex;
    flex-direction:column;
    justify-content:center;
    min-height:150px;
    text-decoration:none;
    background:#fff;
    border:1.5px solid #d9b8ef;
    border-radius:16px;
    padding:22px;
    color:#1a0533;
    box-shadow:0 3px 14px rgba(90,31,140,.08);
    transition:.18s ease;
}
.common-info-card-clean:hover{
    transform:translateY(-3px);
    border-color:#7b2fb5;
    box-shadow:0 8px 24px rgba(90,31,140,.16);
}
.common-info-card-clean .ci-icon{
    font-size:30px;
    margin-bottom:10px;
}
.common-info-card-clean h3{
    margin:0 0 8px;
    color:#3d0f6b;
    font-size:17px;
    font-weight:900;
}
.common-info-card-clean p{
    margin:0;
    color:#6b4a86;
    font-size:12px;
    font-weight:600;
    line-height:1.45;
}
.common-info-section{
    width:100%;
}
.common-info-section-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}
.common-info-section-head h2{
    margin:0;
    color:#3d0f6b;
    font-size:20px;
    font-weight:900;
}
.common-info-back-btn{
    display:inline-flex;
    align-items:center;
    gap:6px;
    height:34px;
    padding:7px 13px;
    border-radius:8px;
    background:#ede0f7;
    color:#3d0f6b;
    text-decoration:none;
    font-size:12.5px;
    font-weight:900;
}
.common-info-template-bar{
    background:#f9f4ff;
    border:1px solid #d9b8ef;
    border-radius:12px;
    padding:12px 14px;
    margin-bottom:14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.common-info-template-bar h3{
    margin:0;
    color:#5a1f8c;
    font-size:14px;
    font-weight:900;
}
.common-info-template-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.common-info-section .table-wrap{
    width:100% !important;
    max-width:none !important;
    overflow:auto !important;
}
.common-info-section .legend-table{
    width:max-content;
    min-width:100%;
}
.common-info-section input,
.common-info-section select{
    max-width:none;
}
@media(max-width:900px){
    .common-info-grid-clean{ grid-template-columns:1fr; }
}


/* ACADEMIC STRUCTURE / DEPARTMENT INFO UI */
.department-structure-grid{
    display:grid;
    grid-template-columns:1fr;
    gap:16px;
}
.department-structure-card{
    border:1px solid #d9b8ef;
    border-radius:14px;
    background:#fff;
    padding:14px;
    box-shadow:0 3px 14px rgba(90,31,140,.06);
}
.department-structure-card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:10px;
}
.department-structure-card-head h3{
    margin:0;
    font-size:16px;
    color:#3d0f6b;
    font-weight:900;
}
.department-structure-card-head p{
    margin:4px 0 0;
    color:#6b4a86;
    font-size:12px;
    font-weight:700;
}
.department-structure-card .table-wrap{
    width:100% !important;
    max-width:none !important;
    overflow:auto !important;
}
.department-structure-table{
    width:max-content;
    min-width:100%;
}
.department-structure-table th{
    white-space:nowrap;
}
.department-structure-table input,
.department-structure-table select{
    width:100%;
    min-width:90px;
}
.department-structure-table .w-sm{ min-width:70px; }
.department-structure-table .w-md{ min-width:125px; }
.department-structure-table .w-lg{ min-width:180px; }
.department-structure-table .w-xl{ min-width:240px; }


/* SIGNATORIES INFO UI */
.signatures-master-note{
    margin:0;
    color:#6b4a86;
    font-size:12px;
    font-weight:700;
    line-height:1.45;
}
.signatures-master-table{
    width:max-content;
    min-width:100%;
}
.signatures-master-table th{
    white-space:nowrap;
}
.signatures-master-table input,
.signatures-master-table select{
    width:100%;
    min-width:100px;
}
.signatures-master-table .sig-role{ min-width:135px; }
.signatures-master-table .sig-name{ min-width:180px; }
.signatures-master-table .sig-desig{ min-width:210px; }
.signatures-master-table .sig-scope{ min-width:130px; }


/* TIME SLOTS INFO UI */
.timeslot-master-note{
    margin:0;
    color:#6b4a86;
    font-size:12px;
    font-weight:700;
    line-height:1.45;
}
.timeslot-master-table{
    width:max-content;
    min-width:100%;
}
.timeslot-master-table th{
    white-space:nowrap;
}
.timeslot-master-table input,
.timeslot-master-table select{
    width:100%;
    min-width:95px;
}
.timeslot-master-table .slot-label-input{ min-width:125px; font-weight:800; }
.timeslot-master-table .break-name-input{ min-width:150px; }


/* COLLEGE INFORMATION UI */
.college-info-note{
    margin:0;
    color:#6b4a86;
    font-size:12px;
    font-weight:700;
    line-height:1.45;
}
.college-info-table{
    width:max-content;
    min-width:100%;
}
.college-info-table th{
    white-space:nowrap;
}
.college-info-table input{
    width:100%;
    min-width:150px;
}
.college-info-table .college-name-input{
    min-width:200px;
    font-weight:800;
}


/* COLLEGE INFO VERTICAL FORM UI */
.college-info-form-card{
    background:#fff;
    border:1px solid #d9b8ef;
    border-radius:16px;
    padding:18px;
    box-shadow:0 4px 16px rgba(90,31,140,.08);
    max-width:980px;
}
.college-info-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(240px,1fr));
    gap:14px 18px;
}
.college-info-field label{
    display:block;
    font-size:12px;
    font-weight:900;
    color:#5a1f8c;
    margin-bottom:6px;
}
.college-info-field input{
    width:100%;
    height:40px;
    border:1px solid #c997ee;
    border-radius:10px;
    padding:8px 10px;
    font-size:13px;
    font-weight:700;
    color:#1a0533;
    background:#fff;
}
.college-info-actions{
    margin-top:16px;
    display:flex;
    justify-content:flex-end;
}
.college-info-actions button{
    min-width:180px;
}
@media(max-width:850px){
    .college-info-form-grid{ grid-template-columns:1fr; }
}


/* DEPARTMENT INFO UPDATED GROUPED TABLE */
.department-structure-table select{
    border:1px solid #c997ee;
    border-radius:8px;
    padding:6px 8px;
    background:#fff;
    color:#1a0533;
    font-weight:700;
}


/* COLLEGE INFO COMPACT TOP FORM */
.college-info-form-card .college-info-form-grid{
    grid-template-columns:repeat(2,minmax(260px,1fr));
}


/* MANUAL TIMETABLE ENTRY GRID */
.manual-tt-card{
    margin-top:16px;
    background:#fff;
    border:1px solid #d9b8ef;
    border-radius:14px;
    padding:14px;
    box-shadow:0 3px 14px rgba(90,31,140,.07);
}
.manual-tt-toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:end;
    margin-bottom:12px;
}
.manual-tt-toolbar label{
    display:block;
    font-size:11px;
    font-weight:900;
    color:#5a1f8c;
    margin-bottom:4px;
}
.manual-tt-toolbar select,
.manual-tt-toolbar input{
    min-width:150px;
    height:38px;
    border:1px solid #c997ee;
    border-radius:9px;
    padding:7px 9px;
    font-weight:700;
}
.manual-tt-table{
    width:max-content;
    min-width:100%;
    border-collapse:collapse;
}
.manual-tt-table th,
.manual-tt-table td{
    border:1px solid #c9a0e0;
    padding:6px;
    vertical-align:middle;
    text-align:center;
}
.manual-tt-table th{
    background:#eadcf6;
    color:#1a0533;
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
}
.manual-tt-table .day-col{
    min-width:105px;
    font-weight:900;
    color:#3d0f6b;
    background:#f9f4ff;
}
.manual-tt-table textarea{
    width:145px;
    min-height:72px;
    resize:vertical;
    border:1px solid #d4afea;
    border-radius:8px;
    padding:6px;
    font-size:11px;
    line-height:1.25;
    font-family:inherit;
}
.manual-tt-break{
    background:#f0e8fa;
    color:#5a1f8c;
    font-size:11px;
    font-weight:900;
    writing-mode:vertical-rl;
    min-width:36px;
}
.manual-tt-help{
    background:#f0e8fa;
    border-left:4px solid #7b2fb5;
    color:#3d0f6b;
    padding:10px 12px;
    border-radius:10px;
    font-size:12px;
    margin-bottom:12px;
}


/* MANUAL TT FULL-WIDTH GRID FIT */
.manual-tt-card .table-wrap{
    overflow-x:visible !important;
    max-width:100% !important;
}
.manual-tt-table{
    width:100% !important;
    min-width:0 !important;
    table-layout:fixed !important;
}
.manual-tt-table th,
.manual-tt-table td{
    padding:4px !important;
}
.manual-tt-table th{
    font-size:10.5px !important;
    white-space:normal !important;
    line-height:1.15 !important;
}
.manual-tt-table .day-col{
    width:75px !important;
    min-width:75px !important;
    font-size:11px !important;
}
.manual-tt-table textarea{
    width:100% !important;
    min-width:0 !important;
    min-height:62px !important;
    font-size:10px !important;
    line-height:1.18 !important;
    padding:5px !important;
    box-sizing:border-box !important;
}
.manual-tt-break{
    width:32px !important;
    min-width:32px !important;
    max-width:32px !important;
    font-size:9px !important;
    padding:3px !important;
}
.manual-tt-summary{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    margin:10px 0;
    font-weight:900;
    color:#3d0f6b;
}
.manual-tt-summary .summary-label{
    color:#3d0f6b;
}
.manual-tt-summary .summary-pill{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    font-weight:900;
    border:1px solid #d9b8ef;
    background:#f9f4ff;
}
.manual-tt-summary .pill-division{color:#7b2fb5;background:#f2e6ff;}
.manual-tt-summary .pill-year{color:#c1345c;background:#ffe8f0;}
.manual-tt-summary .pill-sem{color:#0b6b63;background:#e7fbf8;}
.manual-tt-summary .pill-program{color:#1e5bb8;background:#eaf1ff;}
.manual-tt-summary .pill-dept{color:#9a5a00;background:#fff4df;}
.manual-tt-summary .pill-specialization{color:#146c2e;background:#e9fbea;}


.signatures-master-table input[type="file"]{
    border:1px solid #d9b8ef;
    border-radius:8px;
    padding:5px;
    background:#fff;
}


.class-value-highlight{
    color:#c1345c;
    background:#ffe8f0;
    border:1px solid #f3b7ca;
    border-radius:999px;
    padding:3px 10px;
    font-weight:900;
    display:inline-block;
}

.division-value-highlight{
    color:#c1345c !important;
    background:#ffe8f0;
    border:1px solid #f3b7ca;
    border-radius:999px;
    padding:2px 9px;
    font-weight:900;
    display:inline-block;
}
@media print{
    .division-value-highlight{
        color:#c1345c !important;
        background:#fff !important;
        border:1px solid #c1345c !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}


.common-add-row-panel{
    margin:10px 0 12px;
    padding:12px;
    border:2px dashed #d5a93b;
    border-radius:14px;
    background:#fff8e8;
}
.common-add-row-title{
    font-weight:900;
    color:#3d0f6b;
    margin-bottom:9px;
    font-size:13px;
}
.common-add-row-grid{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:end;
}
.common-add-row-grid input,
.common-add-row-grid select{
    height:32px;
    border:1px solid #d9b8ef;
    border-radius:8px;
    padding:5px 8px;
    background:#fff;
    font-size:12px;
}
.common-add-row-grid label{
    display:block;
    font-size:10px;
    font-weight:800;
    color:#5b327a;
    margin-bottom:3px;
}
.common-add-row-grid button{
    height:32px;
    padding:5px 12px;
}


/* TIMETABLE META VALUE HIGHLIGHTS */
.tt-meta-value-highlight{
    color:#c1345c;
    background:#ffe8f0;
    border:1px solid #f3b7ca;
    border-radius:999px;
    padding:2px 9px;
    font-weight:900;
    display:inline-block;
    line-height:1.2;
}
.tt-meta-year-highlight{
    color:#0b6b63;
    background:#e7fbf8;
    border-color:#a8e6dd;
}
.tt-meta-sem-highlight{
    color:#7b2fb5;
    background:#f2e6ff;
    border-color:#d9b8ef;
}
.tt-meta-wef-highlight{
    color:#9a5a00;
    background:#fff4df;
    border-color:#f0d38d;
}


/* SIGNATURE FOOTER: role, signature, name, designation */
.sign-table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
}
.sign-table th,
.sign-table td{
    border:1px solid #111;
    text-align:center;
    vertical-align:middle;
}
.sign-table th{
    background:#eadcf5;
    font-weight:900;
    height:24px;
}
.sign-table .sign-space{
    height:44px;
    padding:2px 4px;
    background:#fff;
}
.sign-table .sign-space img{
    max-height:38px;
    max-width:150px;
    object-fit:contain;
    display:inline-block;
}
.sign-table .sign-name{
    height:21px;
    font-weight:800;
    font-size:11px;
    padding:2px 4px;
}
.sign-table .sign-designation{
    height:21px;
    font-weight:900;
    font-size:10px;
    padding:2px 4px;
}

/* Print compression to create footer/signature space */
@media print{
    .tt-grid th{
        padding:2px 2px !important;
        height:18px !important;
        font-size:8.5px !important;
        line-height:1.05 !important;
    }
    .tt-grid td{
        padding:1px 2px !important;
        height:34px !important;
        min-height:34px !important;
        font-size:8px !important;
        line-height:1.05 !important;
    }
    .tt-card{
        padding:1px 2px !important;
        margin:0 !important;
        min-height:22px !important;
        line-height:1.05 !important;
    }
    .tt-subject{
        font-size:8.4px !important;
        line-height:1.05 !important;
        margin-bottom:0 !important;
    }
    .tt-meta{
        font-size:7.6px !important;
        line-height:1.05 !important;
        margin-top:0 !important;
    }
    .legend-table th,
    .legend-table td{
        padding:1px 3px !important;
        font-size:7.8px !important;
        line-height:1.05 !important;
    }
    .sign-table th{
        height:18px !important;
        padding:1px 3px !important;
        font-size:8px !important;
        line-height:1.05 !important;
    }
    .sign-table .sign-space{
        height:34px !important;
        padding:1px 3px !important;
    }
    .sign-table .sign-space img{
        max-height:30px !important;
        max-width:120px !important;
    }
    .sign-table .sign-name{
        height:16px !important;
        padding:1px 3px !important;
        font-size:7.8px !important;
        line-height:1.05 !important;
    }
    .sign-table .sign-designation{
        height:16px !important;
        padding:1px 3px !important;
        font-size:7.4px !important;
        line-height:1.05 !important;
    }
}





/* PRINT FIX: REDUCE EMPTY SPACE INSIDE TIMETABLE CELLS, NOT FONT SIZE */
@media print{

    /* actual day rows height */
    .tt-grid tbody tr{
        height:42px !important;
        max-height:42px !important;
    }

    /* actual timetable grid cells */
    .tt-grid tbody td{
        height:42px !important;
        max-height:42px !important;
        min-height:0 !important;
        padding:0 !important;
        vertical-align:middle !important;
        overflow:hidden !important;
    }

    /* day column cells */
    .tt-grid tbody td:first-child{
        height:42px !important;
        max-height:42px !important;
        padding:2px !important;
        vertical-align:middle !important;
    }

    /* remove stretched empty area inside subject cards */
    .tt-card{
        height:auto !important;
        min-height:0 !important;
        max-height:40px !important;
        padding:1px 2px !important;
        margin:0 !important;
        display:block !important;
        overflow:hidden !important;
        line-height:1.05 !important;
    }

    .tt-card.practical,
    .tt-card.project,
    .tt-card.yellow,
    .tt-card.grey{
        height:auto !important;
        min-height:0 !important;
        max-height:40px !important;
        padding-top:1px !important;
        padding-bottom:1px !important;
    }

    /* keep readable font, only remove extra spacing */
    .tt-subject{
        margin:0 !important;
        padding:0 !important;
        line-height:1.05 !important;
    }

    .tt-meta{
        margin:0 !important;
        padding:0 !important;
        line-height:1.05 !important;
    }

    /* break columns also compact */
    .break-cell,
    .tt-grid .break-cell{
        width:22px !important;
        min-width:22px !important;
        max-width:22px !important;
        padding:0 !important;
    }

    .break-text{
        line-height:1 !important;
        margin:0 !important;
        padding:0 !important;
    }
}


/* =========================================================
   FINAL PRINT OVERRIDE - TT CELL EMPTY SPACE FIX
   Put last so it overrides all previous print CSS.
   This reduces the blank vertical space inside timetable grid cells.
   ========================================================= */
@media print{

    /* Main timetable grid actual row height */
    table.tt-grid tr{
        height:38px !important;
        max-height:38px !important;
    }

    table.tt-grid td{
        height:38px !important;
        max-height:38px !important;
        min-height:0 !important;
        padding:0 !important;
        vertical-align:middle !important;
        overflow:hidden !important;
    }

    /* Header row should remain small */
    table.tt-grid th{
        height:18px !important;
        max-height:18px !important;
        padding:1px 2px !important;
        vertical-align:middle !important;
    }

    /* Day column cells like Monday, Tuesday */
    table.tt-grid td.day-cell,
    table.tt-grid td:first-child{
        height:38px !important;
        max-height:38px !important;
        padding:1px !important;
        vertical-align:middle !important;
    }

    /* Subject card inside each cell - this was creating the empty blank space */
    table.tt-grid .tt-card{
        height:auto !important;
        min-height:0 !important;
        max-height:36px !important;
        padding:1px 2px !important;
        margin:0 !important;
        display:block !important;
        overflow:hidden !important;
        line-height:1.05 !important;
    }

    table.tt-grid .tt-card.practical,
    table.tt-grid .tt-card.project,
    table.tt-grid .tt-card.yellow,
    table.tt-grid .tt-card.grey{
        height:auto !important;
        min-height:0 !important;
        max-height:36px !important;
        padding-top:1px !important;
        padding-bottom:1px !important;
    }

    table.tt-grid .tt-subject{
        margin:0 !important;
        padding:0 !important;
        line-height:1.05 !important;
    }

    table.tt-grid .tt-meta{
        margin:0 !important;
        padding:0 !important;
        line-height:1.05 !important;
    }

    /* Break columns */
    table.tt-grid .break-cell{
        height:38px !important;
        max-height:38px !important;
        padding:0 !important;
        width:22px !important;
        min-width:22px !important;
        max-width:22px !important;
    }

    table.tt-grid .break-text{
        margin:0 !important;
        padding:0 !important;
        line-height:1 !important;
    }
}


.delete-btn{
    background:#c1345c !important;
    color:#fff !important;
    border:none !important;
    border-radius:8px !important;
    font-weight:800 !important;
    cursor:pointer !important;
}
.delete-btn:hover{ opacity:.9; }

.pill-wef{background:#fff2cc;color:#8a5a00;border:1px solid #ffd966;}

.faculty-workload-master-table{
    width:max-content;
    min-width:100%;
}
.faculty-workload-master-table th{
    white-space:nowrap;
}
.faculty-workload-master-table input{
    min-width:55px;
}
.common-inline-delete-btn{
    background:#dc2626 !important;
    color:#fff !important;
    border:0 !important;
    border-radius:7px !important;
    height:28px !important;
    padding:4px 10px !important;
    margin-left:5px !important;
    font-weight:800 !important;
    cursor:pointer !important;
}

.pill-prepared{background:#e8f4ff;color:#084b83;border:1px solid #9fd1ff;}


/* =========================================================
   FINAL PRINT TUNING — compact official A4 portrait print
   Only print layout is affected. Screen/UI design remains unchanged.
   ========================================================= */

@media print {

    @page {
        size: A4 portrait;
        margin: 5mm;
    }

    html, body {
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        background: #ffffff !important;
        font-family: Arial, Helvetica, sans-serif !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .topbar,
    .sidebar,
    .controls,
    .no-print,
    .footer,
    .stats-row,
    .dashboard-grid,
    .load-box,
    .module-filters,
    .page-heading,
    .admin-sidebar,
    .admin-topbar {
        display: none !important;
    }

    .layout,
    .main,
    .panel,
    .table-wrap {
        display: block !important;
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        box-shadow: none !important;
        border: 0 !important;
        background: #ffffff !important;
    }

    .tt-page {
        width: 285mm !important;
        max-width: 285mm !important;
        margin: 0 auto !important;
        padding: 0 !important;
        border: 0.7pt solid #333 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #ffffff !important;
        overflow: hidden !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .tt-header {
        border-bottom: 0.7pt solid #333 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .tt-header-row {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        border-bottom: 0.7pt solid #333 !important;
        min-height: 7.2mm !important;
        height: 7.2mm !important;
        background: #ffffff !important;
    }

    .tt-school,
    .tt-title {
        font-size: 10pt !important;
        line-height: 7.2mm !important;
        height: 7.2mm !important;
        padding: 0 3mm !important;
        font-weight: 700 !important;
        text-align: center !important;
        vertical-align: middle !important;
        color: #000000 !important;
        letter-spacing: 0.1px !important;
    }

    .tt-school {
        border-right: 0.7pt solid #333 !important;
        background: #faf7ff !important;
    }

    .tt-dept {
        font-size: 10.5pt !important;
        line-height: 7mm !important;
        min-height: 7mm !important;
        height: 7mm !important;
        padding: 0 3mm !important;
        font-weight: 700 !important;
        text-align: center !important;
        color: #c00000 !important;
        border-bottom: 0.7pt solid #333 !important;
        background: #ffffff !important;
        letter-spacing: 0.1px !important;
    }

    .tt-info {
        display: grid !important;
        grid-template-columns: 1.25fr 2fr 1.25fr !important;
        min-height: 7mm !important;
        height: 7mm !important;
        font-size: 8.7pt !important;
        line-height: 7mm !important;
        font-weight: 700 !important;
        background: #dbeeff !important;
        border-bottom: 0.7pt solid #333 !important;
        color: #000000 !important;
    }

    .tt-info div {
        padding: 0 2.2mm !important;
        line-height: 7mm !important;
        height: 7mm !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        font-weight: 700 !important;
    }

    .division-value-highlight,
    .tt-meta-value-highlight,
    .tt-meta-year-highlight,
    .tt-meta-sem-highlight,
    .tt-meta-wef-highlight {
        display: inline-block !important;
        padding: 0.5mm 2.2mm !important;
        border-radius: 8mm !important;
        line-height: 1.15 !important;
        font-weight: 700 !important;
        border-width: 0.6pt !important;
        vertical-align: middle !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-teacher {
        min-height: 6mm !important;
        height: 6mm !important;
        padding: 0 2mm !important;
        font-size: 8.3pt !important;
        line-height: 6mm !important;
        font-weight: 700 !important;
        background: #111111 !important;
        color: #ffffff !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        border-bottom: 0.7pt solid #333 !important;
    }

    .tt-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        border-spacing: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
    }

    .tt-table th {
        border: 0.55pt solid #555 !important;
        height: 8mm !important;
        max-height: 8mm !important;
        padding: 0.8mm 1mm !important;
        font-size: 7.6pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
        text-align: center !important;
        vertical-align: middle !important;
        background: #ffffff !important;
        color: #000000 !important;
        white-space: normal !important;
        overflow: hidden !important;
    }

    .tt-table td {
        border: 0.55pt solid #555 !important;
        height: 16mm !important;
        max-height: 16mm !important;
        min-height: 0 !important;
        padding: 0.8mm 1mm !important;
        font-size: 7.4pt !important;
        line-height: 1.08 !important;
        text-align: center !important;
        vertical-align: middle !important;
        overflow: hidden !important;
        background: #ffffff !important;
        color: #000000 !important;
        font-weight: 500 !important;
    }

    .tt-table td[colspan="2"] {
        background: #ffffff !important;
    }

    .tt-col-day {
        width: 23mm !important;
    }

    .tt-col-main {
        width: auto !important;
    }

    .tt-col-break {
        width: 8.5mm !important;
        min-width: 8.5mm !important;
        max-width: 8.5mm !important;
    }

    .tt-col-lunch {
        width: 9mm !important;
        min-width: 9mm !important;
        max-width: 9mm !important;
    }

    .day,
    .day-cell {
        width: 23mm !important;
        font-size: 8pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
        padding: 1mm !important;
        background: #ffffff !important;
        color: #000000 !important;
    }

    .online-day {
        background: #ff00ff !important;
        color: #000000 !important;
        font-weight: 700 !important;
    }

    .break-common,
    .lunch-common,
    .break-cell {
        width: 8.5mm !important;
        min-width: 8.5mm !important;
        max-width: 8.5mm !important;
        padding: 0 !important;
        font-size: 6.2pt !important;
        line-height: 1 !important;
        font-weight: 700 !important;
        letter-spacing: 0.3px !important;
        writing-mode: vertical-rl !important;
        text-orientation: upright !important;
        background: #ffffff !important;
        color: #000000 !important;
        overflow: hidden !important;
    }

    .break-text {
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1 !important;
        font-weight: 700 !important;
    }

    .tt-card {
        display: block !important;
        min-height: 0 !important;
        height: auto !important;
        max-height: 14.5mm !important;
        margin: 0 !important;
        padding: 0 !important;
        font-size: 7.3pt !important;
        line-height: 1.08 !important;
        overflow: hidden !important;
        background: transparent !important;
        font-weight: 500 !important;
    }

    .tt-card + .tt-card {
        margin-top: 0.6mm !important;
    }

    .tt-subject {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 7.6pt !important;
        line-height: 1.08 !important;
        font-weight: 700 !important;
        color: #111111 !important;
        background: transparent !important;
        white-space: normal !important;
        overflow: hidden !important;
    }

    .tt-meta,
    .tt-room-inline {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 6.9pt !important;
        line-height: 1.08 !important;
        font-weight: 500 !important;
        color: #222222 !important;
        white-space: normal !important;
        overflow: hidden !important;
    }

    .tt-subject.practical,
    .tt-meta.practical {
        color: #08733a !important;
        font-weight: 650 !important;
    }

    .tt-subject.project,
    .tt-meta.project {
        color: #6b21a8 !important;
        font-weight: 650 !important;
    }

    .tt-subject.other,
    .tt-meta.other {
        color: #7a4b00 !important;
        font-weight: 650 !important;
    }

    .yellow,
    .grey,
    .pink {
        background: #ffffff !important;
    }

    .legend-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        margin: 0 !important;
        background: #ffffff !important;
    }

    .legend-table th,
    .legend-table td {
        border: 0.55pt solid #555 !important;
        padding: 0.7mm 1.2mm !important;
        font-size: 7.3pt !important;
        line-height: 1.12 !important;
        min-height: 4.6mm !important;
        max-height: 5.2mm !important;
        color: #000000 !important;
        overflow: hidden !important;
        vertical-align: middle !important;
        font-weight: 500 !important;
    }

    .legend-table th {
        background: #eadcf5 !important;
        font-weight: 700 !important;
        text-align: center !important;
    }

    .legend-code {
        text-align: center !important;
        font-weight: 700 !important;
    }

    .sign-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        margin: 0 !important;
        background: #ffffff !important;
        text-align: center !important;
    }

    .sign-table th,
    .sign-table td {
        border: 0.55pt solid #555 !important;
        color: #000000 !important;
        vertical-align: middle !important;
        overflow: hidden !important;
    }

    .sign-table th {
        background: #eadcf5 !important;
        height: 6mm !important;
        max-height: 6mm !important;
        padding: 0.8mm 1mm !important;
        font-size: 7.4pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
    }

    .sign-table .sign-space {
        height: 13mm !important;
        max-height: 13mm !important;
        padding: 0.8mm 1mm !important;
        background: #ffffff !important;
    }

    .sign-table .sign-space img {
        max-height: 11mm !important;
        max-width: 42mm !important;
        object-fit: contain !important;
    }

    .sign-table .sign-name {
        height: 5mm !important;
        max-height: 5mm !important;
        padding: 0.6mm 1mm !important;
        font-size: 7.3pt !important;
        line-height: 1.1 !important;
        font-weight: 600 !important;
    }

    .sign-table .sign-designation {
        height: 6mm !important;
        max-height: 6mm !important;
        padding: 0.6mm 1mm !important;
        font-size: 7pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
    }

    .tt-page,
    .tt-table,
    .legend-table,
    .sign-table {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
}



/* ===================== FINAL NORMAL PRINT = SAME AS CURRENT BULK EXPORT PRINT ===================== */
@media print {
    @page {
        size: A4 portrait;
        margin: 4mm 8mm 4mm 8mm;
    }

    html, body {
        overflow: visible !important;
        height: auto !important;
        width: 100% !important;
        background: #fff !important;
        font-size: 8pt !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .topbar, .sidebar, .controls,
    .no-print, .footer,
    .stats-row, .dashboard-grid,
    .load-box {
        display: none !important;
    }

    .layout {
        display: block !important;
        height: auto !important;
        overflow: visible !important;
    }

    .main {
        padding: 0 !important;
        overflow: visible !important;
        height: auto !important;
        width: 100% !important;
    }

    .panel {
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border-radius: 0 !important;
        border: none !important;
    }

    .table-wrap {
        overflow: visible !important;
    }

    .tt-page {
        width: 194mm !important;
        max-width: none !important;
        margin: 0 auto !important;
        border: 1.1pt solid #222 !important;
        border-radius: 0 !important;
        overflow: visible !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        display: block !important;
        background: #fff !important;
    }

    .tt-header {
        border-bottom: 0.7pt solid #555 !important;
        text-align: center !important;
    }

    .tt-header-row {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        border-bottom: 0.7pt solid #555 !important;
        min-height: 13mm !important;
    }

    .tt-school,
    .tt-title {
        font-size: 10.5pt !important;
        font-weight: 700 !important;
        padding: 4.5mm 2mm !important;
        line-height: 1.25 !important;
        text-align: center !important;
        background: #f8f1ff !important;
        color: #111 !important;
        min-height: 13mm !important;
        box-sizing: border-box !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-school {
        border-right: 0.7pt solid #555 !important;
    }

    .tt-dept {
        font-size: 11pt !important;
        font-weight: 700 !important;
        color: #5a1f8c !important;
        padding: 3.5mm 2mm !important;
        min-height: 11mm !important;
        line-height: 1.25 !important;
        border-bottom: 0.7pt solid #555 !important;
        text-align: center !important;
        box-sizing: border-box !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-info,
    .faculty-info-grid,
    .resource-info-grid {
        display: grid !important;
        grid-template-columns: 1fr 2fr 1.2fr !important;
        background: #ede0f7 !important;
        border-bottom: 0.7pt solid #555 !important;
        font-size: 8.8pt !important;
        font-weight: 600 !important;
        min-height: 12mm !important;
        color: #111 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-info div,
    .faculty-info-grid div,
    .resource-info-grid div {
        padding: 3mm 2mm !important;
        line-height: 1.25 !important;
        box-sizing: border-box !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex-wrap: wrap !important;
        gap: 1mm !important;
    }

    .tt-info b,
    .faculty-info-grid b,
    .resource-info-grid b {
        font-weight: 700 !important;
    }

    .highlight-pill,
    .highlight-pill.teal,
    .highlight-pill.pink,
    .highlight-pill.yellow,
    .highlight-text,
    .division-value-highlight,
    .tt-meta-value-highlight,
    .tt-meta-year-highlight,
    .tt-meta-sem-highlight,
    .tt-meta-wef-highlight {
        border-radius: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        font-weight: 700 !important;
        color: #111 !important;
        box-shadow: none !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-teacher {
        background: #3d0f6b !important;
        color: #fff !important;
        font-size: 8pt !important;
        font-weight: 650 !important;
        padding: 1.8mm 2mm !important;
        line-height: 1.2 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        margin: 0 !important;
    }

    .tt-table th,
    .tt-table td {
        border: 0.55pt solid #555 !important;
        text-align: center !important;
        vertical-align: middle !important;
        color: #111 !important;
        box-sizing: border-box !important;
    }

    .tt-table th {
        font-size: 6.7pt !important;
        font-weight: 650 !important;
        padding: 1.1mm 0.5mm !important;
        line-height: 1.05 !important;
        background: #f8f1ff !important;
        height: 6.5mm !important;
        white-space: normal !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .tt-table td {
        padding: 0.6mm 0.5mm !important;
        height: 19.5mm !important;
        min-height: 19.5mm !important;
        max-height: 19.5mm !important;
        overflow: hidden !important;
        font-size: 7.2pt !important;
        line-height: 1.08 !important;
        font-weight: 500 !important;
    }

    .tt-table td[colspan="2"] {
        background: #fff !important;
    }

    .tt-col-day {
        width: 14mm !important;
    }

    .tt-col-main {
        width: 10.7% !important;
    }

    .tt-col-break {
        width: 4.1% !important;
    }

    .tt-col-lunch {
        width: 4.6% !important;
    }

    .day {
        width: 14mm !important;
        font-size: 7.5pt !important;
        font-weight: 650 !important;
        background: #ede0f7 !important;
        color: #111 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .break-common,
    .lunch-common {
        background: #fff !important;
        font-weight: 650 !important;
        writing-mode: vertical-rl !important;
        text-orientation: upright !important;
        letter-spacing: 0.4px !important;
        font-size: 5.6pt !important;
        line-height: 1 !important;
        padding: 0 !important;
        width: 7.5mm !important;
        min-width: 7.5mm !important;
        max-width: 7.5mm !important;
        color: #111 !important;
    }

    .tt-card {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 7pt !important;
        line-height: 1.08 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        overflow: hidden !important;
        background: transparent !important;
        font-weight: 500 !important;
    }

    .tt-card + .tt-card {
        margin-top: 0.6mm !important;
    }

    .tt-subject {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 7.5pt !important;
        line-height: 1.08 !important;
        font-weight: 700 !important;
        color: #111 !important;
        background: transparent !important;
        white-space: normal !important;
        overflow: hidden !important;
    }

    .tt-meta {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 6.8pt !important;
        line-height: 1.08 !important;
        font-weight: 500 !important;
        color: #222 !important;
        white-space: normal !important;
        overflow: hidden !important;
    }

    .tt-subject.practical,
    .tt-meta.practical {
        color: #08733a !important;
        font-weight: 650 !important;
    }

    .tt-subject.project,
    .tt-meta.project {
        color: #6b21a8 !important;
        font-weight: 650 !important;
    }

    .tt-subject.other,
    .tt-meta.other {
        color: #7a4b00 !important;
        font-weight: 650 !important;
    }

    .online-day {
        background: #ff00ff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .legend-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        margin: 0 !important;
        background: #fff !important;
    }

    .legend-table th,
    .legend-table td {
        border: 0.55pt solid #555 !important;
        padding: 0.7mm 1.2mm !important;
        font-size: 7.1pt !important;
        line-height: 1.1 !important;
        color: #000 !important;
        overflow: hidden !important;
        vertical-align: middle !important;
        font-weight: 500 !important;
    }

    .legend-table th {
        background: #eadcf5 !important;
        font-weight: 700 !important;
        text-align: center !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .legend-code {
        text-align: center !important;
        font-weight: 700 !important;
    }

    .sign-table {
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        margin: 0 !important;
        background: #fff !important;
        text-align: center !important;
    }

    .sign-table th,
    .sign-table td {
        border: 0.55pt solid #555 !important;
        color: #000 !important;
        vertical-align: middle !important;
        overflow: hidden !important;
    }

    .sign-table th {
        background: #eadcf5 !important;
        height: 6mm !important;
        padding: 0.8mm 1mm !important;
        font-size: 7.3pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .sign-space,
    .sign-table .sign-space {
        height: 13mm !important;
        max-height: 13mm !important;
        padding: 0.8mm 1mm !important;
        background: #fff !important;
    }

    .signature-img,
    .sign-table .sign-space img {
        max-height: 11mm !important;
        max-width: 42mm !important;
        object-fit: contain !important;
    }

    .sign-name-row td,
    .sign-table .sign-name {
        height: 5mm !important;
        max-height: 5mm !important;
        padding: 0.6mm 1mm !important;
        font-size: 7.2pt !important;
        line-height: 1.1 !important;
        font-weight: 600 !important;
    }

    .sign-desig-row td,
    .sign-table .sign-designation {
        height: 6mm !important;
        max-height: 6mm !important;
        padding: 0.6mm 1mm !important;
        font-size: 6.9pt !important;
        line-height: 1.1 !important;
        font-weight: 700 !important;
    }

    .tt-page,
    .tt-table,
    .legend-table,
    .sign-table {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
}
/* ===================== END FINAL NORMAL PRINT = SAME AS CURRENT BULK EXPORT PRINT ===================== */

</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
    <div class="brand">
        <img src="assets/mitadt_logo.jpg" class="logo-img" onerror="this.style.display='none'">
        <div>
            <div class="brand-title">MIT Art, Design &amp; Technology University</div>
            <div class="brand-subtitle">School Of Computing — Smart Timetable &amp; Resource Management System</div>
        </div>
    </div>
    <div class="badge"><?= $selected_year ? e($selected_year) : 'Academic Year' ?> &middot; <?= $selected_semester=='Odd' ? 'Odd' : ($selected_semester=='Even' ? 'Even' : 'Semester') ?></div>
</div>

<div class="layout">
<!-- SIDEBAR -->
<div class="sidebar no-print">

    <?php if(isset($_SESSION['admin']) && in_array($view, ['login','admin_dashboard','manage','common_info','faculty_workload'])){ ?>

        <div class="side-title">Admin Panel</div>

        <a class="side-card <?= $view=='admin_dashboard'?'active':'' ?>"
           href="?view=admin_dashboard&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            📊 Admin Dashboard
        </a>

        <a class="side-card <?= $view=='common_info'?'active':'' ?>"
           href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            📚 Common Info
        </a>

        <a class="side-card <?= $view=='manage'?'active':'' ?>"
           href="?view=manage&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            📥 Timetable Import
        </a>

        <a class="side-card <?= $view=='faculty_workload'?'active':'' ?>"
           href="?view=faculty_workload&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            👨‍🏫 Faculty Workload
        </a>

        <a class="side-card"
           href="analytics.php?context=admin&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            📊 Analytics
        </a>

        <a class="side-card" href="?logout=1">
            🚪 Logout
        </a>

        <a class="side-card"
           href="?view=dashboard&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            🏠 Main Dashboard
        </a>

    <?php } else { ?>

        <div class="side-title">Portal</div>

        <a class="side-card <?= ($view=='dashboard' || $view=='')?'active':'' ?>"
           href="?view=dashboard&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            🏠 Dashboard
        </a>

        <a class="side-card <?= $view=='division'?'active':'' ?>"
           href="?view=division&division=<?= e($selected_division) ?>&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            📅 Division TT
        </a>

        <a class="side-card <?= $view=='faculty'?'active':'' ?>"
           href="?view=faculty&faculty=<?= e($selected_faculty) ?>&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            👨‍🏫 Faculty TT
        </a>

        <a class="side-card <?= $view=='classroom'?'active':'' ?>"
           href="?view=classroom&resource_type=<?= e($selected_resource_type) ?>&classroom=<?= e($selected_classroom) ?>&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            🏫 Physical Resources TT
        </a>

        <a class="side-card <?= $view=='free_classrooms'?'active':'' ?>"
           href="?view=free_classrooms&free_resource_type=<?= e($selected_free_resource_type) ?>&day=<?= e($selected_day) ?>&slot=<?= e($selected_slot) ?>&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            ✅ Free Physical Resources
        </a>

        <a class="side-card <?= $view=='faculty_free'?'active':'' ?>"
           href="?view=faculty_free&faculty=<?= e($selected_faculty) ?>&free_day=<?= e($selected_free_day) ?>&free_slot=<?= e($selected_free_slot) ?>&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            🕒 Faculty Free Timeslots
        </a>

        <a class="side-card"
           href="analytics.php?context=portal&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>&division=<?= e($selected_division) ?>&faculty=<?= e($selected_faculty) ?>&classroom=<?= e($selected_classroom) ?>">
            📊 Analytics
        </a>

        <a class="side-card <?= $view=='login'?'active':'' ?>"
           href="?view=login&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
            🔐 Admin Login
        </a>

    <?php } ?>

</div>
<!-- MAIN -->
<div class="main">

<?php if($message){ ?><div class="msg no-print"><?= e($message) ?></div><?php } ?>

<!-- CONTROLS BAR -->
<div class="panel no-print">
<form method="GET" class="controls">
    <!-- 1. View Options -->
    <?php
    $adminDropdownViews = ['login','admin_dashboard','common_info','manage','faculty_workload'];
    $showAdminDropdown = isset($_SESSION['admin']) && in_array($view, $adminDropdownViews);
    ?>
    <?php if($showAdminDropdown){ ?>
        <input type="hidden" name="view" value="<?= e($view ?: 'admin_dashboard') ?>">
    <?php } else { ?>
        <select name="view" onchange="this.form.submit()">
            <option value="dashboard" <?= ($view=='dashboard' || $view=='')?'selected':'' ?>>Dashboard</option>
            <option value="division" <?= $view=='division'?'selected':'' ?>>Division TT</option>
            <option value="faculty" <?= $view=='faculty'?'selected':'' ?>>Faculty TT</option>
            <option value="classroom" <?= $view=='classroom'?'selected':'' ?>>Physical Resources</option>
            <option value="free_classrooms" <?= $view=='free_classrooms'?'selected':'' ?>>Free Rooms</option>
            <option value="faculty_free" <?= $view=='faculty_free'?'selected':'' ?>>Faculty Free</option>
            <option value="login" <?= $view=='login'?'selected':'' ?>>Admin Login</option>
        </select>
    <?php } ?>

    <?php if(!$showAdminDropdown){ ?>
    <!-- 2. Academic Year -->
    <select name="academic_year" onchange="this.form.submit()">
        <option value="" <?= $selected_year==''?'selected':'' ?>>Academic Year</option>
        <option value="2025-26" <?= $selected_year=='2025-26'?'selected':'' ?>>2025-26</option>
        <option value="2026-27" <?= $selected_year=='2026-27'?'selected':'' ?>>2026-27</option>
    </select>

    <!-- 3. Semester -->
    <select name="semester" onchange="this.form.submit()">
        <option value="" <?= $selected_semester==''?'selected':'' ?>>Odd / Even Sem</option>
        <option value="Odd" <?= $selected_semester=='Odd'?'selected':'' ?>>Odd</option>
        <option value="Even" <?= $selected_semester=='Even'?'selected':'' ?>>Even</option>
    </select>
    <?php } ?>

    <?php if($view=="division"){ ?>

        <!-- 4. Department -->
        <?php if($selected_program=="UG" || $selected_program==""){ ?>
            <select name="department" onchange="cascadeDivisionFilter(this,'department')">
                <option value="" <?= $selected_department==''?'selected':'' ?>>Department</option>
                <?php foreach($ug_department_options as $dept){ ?>
                    <option value="<?= e($dept) ?>" <?= $selected_department==$dept?'selected':'' ?>>
                        <?= e($dept) ?>
                    </option>
                <?php } ?>
            </select>
        <?php } ?>

        <?php if($selected_program=="PG"){ ?>
            <input type="hidden" name="department" value="">
        <?php } ?>

        <!-- 5. Program -->
        <select name="program_level" onchange="cascadeDivisionFilter(this,'program')">
            <option value="" <?= $selected_program==''?'selected':'' ?>>UG / PG</option>
            <?php foreach($program_options as $p){ ?>
                <option value="<?= e($p) ?>" <?= $selected_program==$p?'selected':'' ?>>
                    <?= e($p) ?>
                </option>
            <?php } ?>
        </select>

        <!-- 6. Year -->
        <?php if($selected_program=="UG" || $selected_program==""){ ?>
            <select name="year_name" id="year_name_select" onchange="cascadeDivisionFilter(this,'year')">
                <option value="" <?= $selected_year_name==''?'selected':'' ?>>Year</option>
                <?php
                $filtered_year_options = $year_options;
                if($selected_department === 'ASH') $filtered_year_options = ['FY'];
                foreach($filtered_year_options as $yr){
                ?>
                    <option value="<?= e($yr) ?>" <?= $selected_year_name==$yr?'selected':'' ?>>
                        <?= e($yr) ?>
                    </option>
                <?php } ?>
            </select>

            <?php if(in_array($selected_year_name,["TY","LY"])){ ?>
                <select name="specialization" onchange="cascadeDivisionFilter(this,'specialization')">
                    <option value="" <?= $selected_specialization==''?'selected':'' ?>>Specialization</option>
                    <?php foreach($specialization_options as $sp){ ?>
                        <option value="<?= e($sp) ?>" <?= $selected_specialization==$sp?'selected':'' ?>>
                            <?= e($sp) ?>
                        </option>
                    <?php } ?>
                </select>
            <?php } else { ?>
                <input type="hidden" name="specialization" value="">
            <?php } ?>
        <?php } ?>

        <?php if($selected_program=="PG"){ ?>
            <input type="hidden" name="year_name" value="">
            <input type="hidden" name="specialization" value="">

            <select name="degree_type" onchange="cascadeDivisionFilter(this,'degree')">
                <option value="" <?= $selected_degree==''?'selected':'' ?>>Degree</option>
                <?php foreach($pg_degree_options as $deg){ ?>
                    <option value="<?= e($deg) ?>" <?= $selected_degree==$deg?'selected':'' ?>>
                        <?= e($deg) ?>
                    </option>
                <?php } ?>
            </select>
        <?php } else { ?>
            <input type="hidden" name="degree_type" value="">
        <?php } ?>

        <!-- 7. Division -->
        <select name="division" class="searchable-select" onchange="this.form.submit()">
            <option value="" <?= $selected_division==''?'selected':'' ?>>Division</option>
            <?php 
            if($division_list && $division_list->num_rows > 0){ 
                while($d=$division_list->fetch_assoc()){ 
            ?>
                    <option value="<?= e($d['division_name']) ?>" <?= $selected_division==$d['division_name']?'selected':'' ?>>
                        <?= e($d['division_name']) ?>
                    </option>
            <?php 
                } 
            } else { 
            ?>
                <option value="">No divisions added yet</option>
            <?php } ?>
        </select>

    <?php } ?>

    <?php if($view=="faculty"){ ?>
        <select name="faculty" class="searchable-select" onchange="this.form.submit()">
            <option value="" <?= $selected_faculty==''?'selected':'' ?>>Faculty</option>
            <?php while($f=$faculty_list->fetch_assoc()){ 
                $uid=$f['faculty_uid']??''; 
            ?>
                <option value="<?= e($f['faculty_code']) ?>" <?= $selected_faculty==$f['faculty_code']||$selected_faculty==$uid?'selected':'' ?>>
                    <?= e($uid) ?> | <?= e($f['faculty_code']) ?> - <?= e($f['faculty_name']) ?>
                </option>
            <?php } ?>
        </select>
    <?php } ?>

    <?php if($view=="classroom"){ ?>
        <select name="resource_type" onchange="this.form.submit()">
            <option value="classroom" <?= $selected_resource_type=='classroom'?'selected':'' ?>>Classroom</option>
            <option value="lab" <?= $selected_resource_type=='lab'?'selected':'' ?>>Lab</option>
        </select>
        <select name="classroom" class="searchable-select" onchange="this.form.submit()">
            <option value="" <?= $selected_classroom==''?'selected':'' ?>><?= $selected_resource_type=='lab' ? 'Lab No.' : 'Classroom No.' ?></option>
            <?php while($c=$classroom_list->fetch_assoc()){ ?>
                <option value="<?= e($c['room_code']) ?>" <?= $selected_classroom==$c['room_code']?'selected':'' ?>>
                    <?= e($c['room_code']) ?>
                </option>
            <?php } ?>
        </select>
    <?php } ?>

    <?php if($view=="free_classrooms"){ ?>
        <select name="free_resource_type" onchange="this.form.submit()">
            <option value="classroom" <?= $selected_free_resource_type=='classroom'?'selected':'' ?>>Free Classrooms</option>
            <option value="lab" <?= $selected_free_resource_type=='lab'?'selected':'' ?>>Free Labs</option>
            <option value="tutorial" <?= $selected_free_resource_type=='tutorial'?'selected':'' ?>>Free Tutorial Rooms</option>
        </select>

        <select name="day" onchange="this.form.submit()">
            <option value="" <?= $selected_day==''?'selected':'' ?>>Day</option>
            <?php foreach($days as $d){ ?>
                <option value="<?= e($d) ?>" <?= $selected_day==$d?'selected':'' ?>>
                    <?= e($d) ?>
                </option>
            <?php } ?>
        </select>

        <select name="slot" onchange="this.form.submit()">
            <option value="" <?= $selected_slot==''?'selected':'' ?>>Time Slot</option>
            <?php foreach($slots as $s){ 
                if(!isset($break_slots[$s])){ 
            ?>
                <option value="<?= e($s) ?>" <?= $selected_slot==$s?'selected':'' ?>>
                    <?= e($s) ?>
                </option>
            <?php }} ?>
        </select>
    <?php } ?>

    <!-- 8. Search bar -->
    <?php if(in_array($view,["division","faculty","classroom","faculty_free"])){ ?>
    <input type="text" id="quickSearch" class="quick-search" placeholder="Search..." onkeyup="filterDropdownOptions()" autocomplete="off">
    <?php } ?>

    <!-- 9. Export PDF -->
    <?php if($showAdminDropdown){ ?><span class="admin-actions-spacer"></span><?php } ?>
    <button type="button" class="print-btn" onclick="window.print()">📄 Export PDF</button>
    <?php if(isset($_SESSION['admin'])){ ?><a class="logout-link" href="?logout=1">Logout</a><?php } ?>
</form>
</div>

<!-- ===================== DASHBOARD ===================== -->
<?php if($view=="dashboard"){ ?>
<div class="stats-row no-print">
    <div class="stat-card blue"><h2><?= e($total_divisions) ?></h2><span>Total Divisions</span></div>
    <div class="stat-card green"><h2><?= e($total_entries) ?></h2><span>Total Entries</span></div>
    <div class="stat-card orange"><h2><?= e($total_faculties) ?></h2><span>Faculty Members</span></div>
    <div class="stat-card purple"><h2><?= e($total_rooms) ?></h2><span>Classrooms / Labs</span></div>
</div>
<div class="dashboard-grid">
    <div class="panel">
        <div class="page-heading">Year-wise Division &amp; Timetable Summary</div>
        <div class="chart-row"><div class="chart-label"><span>SY — <?= e($sy_divisions) ?> divisions</span><span><?= e($sy_entries) ?> entries</span></div><div class="bar-bg"><div class="bar" style="width:<?= ($sy_entries/$max_year_count)*100 ?>%"></div></div></div>
        <div class="chart-row"><div class="chart-label"><span>TY — <?= e($ty_divisions) ?> divisions</span><span><?= e($ty_entries) ?> entries</span></div><div class="bar-bg"><div class="bar greenbar" style="width:<?= ($ty_entries/$max_year_count)*100 ?>%"></div></div></div>
        <div class="chart-row"><div class="chart-label"><span>LY — <?= e($ly_divisions) ?> divisions</span><span><?= e($ly_entries) ?> entries</span></div><div class="bar-bg"><div class="bar orangebar" style="width:<?= ($ly_entries/$max_year_count)*100 ?>%"></div></div></div>
    </div>
    <div class="panel">
        <div class="page-heading">Portal Access</div>
        <div class="login-card-row">
            <div class="login-card"><h3>Admin Login</h3><p style="font-size:12px;margin:0 0 10px;">Manage timetable entries and master data.</p><a href="?view=login"><button type="button">Open Admin Login</button></a></div>
            <div class="login-card"><h3>Faculty TT</h3><p style="font-size:12px;margin:0 0 10px;">View faculty timetable and workload distribution.</p><a href="?view=faculty"><button type="button">View Faculty TT</button></a></div>
        </div>
    </div>
</div>
<?php } ?>

<!-- ============= DIVISION / FACULTY / CLASSROOM TIMETABLE ============= -->
<?php if(in_array($view,["division","faculty","classroom"])){ ?>
<div class="panel print-panel">
    <div class="page-heading no-print">
        <?php
        if($view=='division')   echo 'Division Timetable: <span class="division-value-highlight">'.e($selected_division).'</span>';
        elseif($view=='faculty') echo 'Faculty Timetable: '.e($selected_faculty);
        else                     echo ($selected_resource_type=='lab' ? 'Lab Timetable: ' : 'Physical Resources: ').e($selected_classroom);
        ?>
    </div>

    <?php if($view=='faculty'){ ?>
    <div class="load-box no-print">
        <table class="load-table">
            <tr><th colspan="6">Faculty Load Distribution (Weekly)</th></tr>
            <tr><th>Theory</th><th>Practical</th><th>Mini Project</th><th>Major Project</th><th>Other</th><th>Total</th></tr>
            <tr>
                <td><?= e($faculty_load['Theory']) ?></td>
                <td><?= e($faculty_load['Practical']) ?></td>
                <td><?= e($faculty_load['Mini Project']) ?></td>
                <td><?= e($faculty_load['Major Project']) ?></td>
                <td><?= e($faculty_load['Other']) ?></td>
                <td><?= e($faculty_load['Total']) ?></td>
            </tr>
        </table>
    </div>
    <?php } ?>
    <?php if($view=='faculty'){ ?>
<div style="text-align:center;margin:12px 0 18px;">
    <a href="faculty_workload_summary.php?academic_year=<?= urlencode($selected_year) ?>&semester=<?= urlencode($selected_semester) ?>"
       target="_blank"
       style="
            display:inline-block;
            background:#5a1f8c;
            color:#fff;
            padding:10px 18px;
            border-radius:8px;
            text-decoration:none;
            font-weight:700;
            box-shadow:0 2px 8px rgba(90,31,140,.25);
       ">
        📊 Download All Faculty Workload Report
    </a>
</div>
<?php } ?>

    <?php if($view=='classroom' && $selected_classroom !== ''){ ?>
    <div class="util-grid no-print">
        <div class="util-mini-card">
            <h3><?= e($classroom_util_percent) ?>%</h3>
            <span><?= e($selected_classroom) ?> Utilization</span>
        </div>
        <div class="util-mini-card">
            <h3><?= e($classroom_used_slots) ?></h3>
            <span>Used Slots / Week</span>
        </div>
        <div class="util-mini-card">
            <h3><?= e($classroom_free_slots) ?></h3>
            <span>Free Slots / Week</span>
        </div>
    </div>
    <?php } ?>

    <!-- THE TIMETABLE PAGE (print target) -->
    <div class="tt-page">
        <!-- Header -->
        <div class="tt-header">
            <div class="tt-header-row">
                <div class="tt-school">MIT SCHOOL OF COMPUTING</div>
                <div class="tt-title">
                    <?php
                    if($view=='faculty') echo 'FACULTY TIME TABLE';
                    elseif($view=='classroom' && $selected_resource_type=='lab') echo 'LABORATORY TIME TABLE';
                    elseif($view=='classroom') echo 'CLASSROOM TIME TABLE';
                    else echo 'DEPARTMENTAL TIME TABLE';
                    ?>
                </div>
            </div>
            <div class="tt-dept"><?= e($department_print) ?></div>

            <?php if($view=='division'){ ?>
                <div class="tt-info">
                    <div>
                        <b>Class :</b> <span class="division-value-highlight"><?= e($selected_division) ?></span>
                        <?php if(!empty($specialization_print)){ echo "<br><span style='font-size:11px;'>".e($specialization_print)."</span>"; } ?>
                    </div>
                    <div><b>Academic Year :</b> <span class="tt-meta-value-highlight tt-meta-year-highlight"><?= e($selected_year) ?></span>, <span class="tt-meta-value-highlight tt-meta-sem-highlight"><?= $selected_semester=='Odd' ? 'Odd' : ($selected_semester=='Even' ? 'Even' : e($selected_semester)) ?></span></div>
                    <div><b>W.E.F.</b> <span class="tt-meta-value-highlight tt-meta-wef-highlight"><?= e($wef_date) ?></span></div>
                </div>
                <div class="tt-teacher">Name of the Class Teacher : <?= e(trim(($class_teacher ?: '________________') . ' ' . $class_teacher_email . ' ' . $class_teacher_contact)) ?></div>

            <?php } elseif($view=='faculty'){ ?>
                <div class="tt-info faculty-info-grid">
                    <div><b>Faculty Name :</b> <?= e($faculty_meta['faculty_name'] ?? $selected_faculty) ?><br><b>Abbreviation :</b> <?= e($faculty_meta['faculty_code'] ?? $selected_faculty) ?></div>
                    <div><b>Designation :</b> <?= e($faculty_meta['designation'] ?? '') ?><br><b>Email :</b> <?= e($faculty_meta['email'] ?? '') ?></div>
                    <div><b>Contact :</b> <?= e($faculty_meta['contact_no'] ?? '') ?><br><b>Seating :</b> <?= e($faculty_meta['seating_location'] ?? '') ?></div>
                </div>
                <div class="tt-teacher">Academic Year : <?= e($selected_year) ?>, <?= $selected_semester=='Odd' ? 'Odd' : ($selected_semester=='Even' ? 'Even' : e($selected_semester)) ?></div>

            <?php } elseif($view=='classroom' && $selected_resource_type=='lab'){ ?>
                <div class="tt-info resource-info-grid">
                    <div><b>Lab No. :</b> <?= e($selected_classroom) ?><br><b>Lab Name :</b> <?= e($resource_meta['lab_name'] ?? '') ?></div>
                    <div><b>Lab Incharge :</b> <?= e($resource_meta['lab_incharge'] ?? '') ?><br><b>Lab Assistant :</b> <?= e($resource_meta['lab_assistant'] ?? '') ?></div>
                    <div><b>Capacity :</b> <?= e($resource_meta['lab_capacity'] ?? '') ?><br><b>No. of PCs :</b> <?= e($resource_meta['no_of_pcs'] ?? '') ?></div>
                </div>
                <div class="tt-teacher">Academic Year : <?= e($selected_year) ?>, <?= $selected_semester=='Odd' ? 'Odd' : ($selected_semester=='Even' ? 'Even' : e($selected_semester)) ?> | Block : <?= e($resource_meta['block_name'] ?? '') ?> | Floor : <?= e($resource_meta['floor_no'] ?? '') ?></div>

            <?php } elseif($view=='classroom'){ ?>
                <div class="tt-info resource-info-grid">
                    <div><b>Classroom No. :</b> <?= e($selected_classroom) ?><br><b>Classroom Incharge :</b> <?= e($resource_meta['classroom_incharge'] ?? '') ?></div>
                    <div><b>Capacity :</b> <?= e($resource_meta['capacity'] ?? '') ?><br><b>No. of Benches :</b> <?= e($resource_meta['no_of_benches'] ?? '') ?></div>
                    <div><b>Smart Board :</b> <?= e($resource_meta['smart_board'] ?? '') ?><br><b>LCD :</b> <?= e($resource_meta['lcd_projector'] ?? '') ?></div>
                </div>
                <div class="tt-teacher">Academic Year : <?= e($selected_year) ?>, <?= $selected_semester=='Odd' ? 'Odd' : ($selected_semester=='Even' ? 'Even' : e($selected_semester)) ?></div>
            <?php } ?>
        </div>

        <!-- Grid -->
        <div class="table-wrap">
        <table class="tt-table">
            <colgroup>
                <col class="tt-col-day">
                <?php foreach($slots as $slot){ ?>
                    <col class="<?= isset($break_slots[$slot]) ? ($slot=='12:40-01:40' ? 'tt-col-lunch' : 'tt-col-break') : 'tt-col-main' ?>">
                <?php } ?>
            </colgroup>
            <tr>
                <th>Day ↓ / Time →</th>
                <?php foreach($slots as $slot){ ?><th><?= e(str_replace('-',' - ',$slot)) ?></th><?php } ?>
            </tr>
            <?php foreach($days as $di=>$day){
                $isOnlineDay = ($view=='division' && !empty($online_days[$day]));
            ?>
            <tr>
                <td class="day <?= $isOnlineDay ? 'online-day' : '' ?>"><?= e($day) ?><?php if($isOnlineDay) echo '<br><span style="font-size:9px">(Online)</span>'; ?></td>
                <?php
                $skipSlotIndex = [];
                foreach($slots as $slotIndex => $slot){
                    if(isset($skipSlotIndex[$slotIndex])) continue;

                    if(isset($break_slots[$slot])){
                        if($di==0){ ?><td rowspan="<?= count($days) ?>" class="<?= $slot=='12:40-01:40'?'lunch-common':'break-common' ?>"><?= e($break_slots[$slot]) ?></td><?php }
                    } else {
                        $colspan = 1;

                        /* Visual-only merge for all timetable views:
                           Same practical/project/2-hour activity in two continuous teaching slots
                           is shown as one wider cell in Division, Faculty, Classroom and Lab TT.
                           This does NOT change DB, faculty load, classroom usage, free slots, or analytics. */
                        if(isset($timetable[$day][$slot])){
                            $nextIndex = $slotIndex + 1;
                            $nextSlot = $slots[$nextIndex] ?? '';

                            if(
                                $nextSlot !== '' &&
                                !isset($break_slots[$nextSlot]) &&
                                isset($timetable[$day][$nextSlot])
                            ){
                                $currentMerge = timetable_entries_merge_key($timetable[$day][$slot]);
                                $nextMerge = timetable_entries_merge_key($timetable[$day][$nextSlot]);

                                if($currentMerge['allowed'] && $currentMerge['key'] !== '' && $currentMerge['key'] === $nextMerge['key']){
                                    $colspan = 2;
                                    $skipSlotIndex[$nextIndex] = true;
                                }
                            }
                        }
                    ?>
                <td colspan="<?= $colspan ?>" class="<?= $colspan > 1 ? 'merged-slot' : '' ?>"><?php if(isset($timetable[$day][$slot])){
                    /*
                       Clean grouped cell display:
                       - For theory: Course on first line, Faculty | Room on second line.
                       - For practical/tutorial batches: Course shown once, then A: Faculty | Room and B: Faculty | Room.
                       - This prevents repeated RI/N506 and ensures A/B batches are not lost.
                    */
                    $cellGroups = [];
                    foreach($timetable[$day][$slot] as $entry){
                        $subject = trim((string)($entry['subject_code'] ?? ''));
                        $subjectName = trim((string)($entry['subject_name'] ?? ''));
                        $batch = trim((string)($entry['batch'] ?? ''));
                        $faculty = trim((string)($entry['faculty_code'] ?? ''));
                        $room = trim((string)($entry['room_code'] ?? ''));
                        $divisionCell = trim((string)($entry['division_name'] ?? ''));
                        $subjectType = trim((string)($entry['subject_type'] ?? ''));

                        /*
                           Continuation practical fix:
                           Some existing 2-hour practical continuation rows have subject/batch/room
                           but missing faculty_id after relinking. In the visible timetable, copy the
                           faculty abbreviation from the adjacent matching practical slot only for display.
                           This does not change the database.
                        */
                        if($faculty === ''){
                            $adjacentSlotsForFaculty = [];
                            $prevSlotForFaculty = $slots[$slotIndex - 1] ?? '';
                            $nextSlotForFaculty = $slots[$slotIndex + 1] ?? '';
                            if($prevSlotForFaculty !== '' && !isset($break_slots[$prevSlotForFaculty])) $adjacentSlotsForFaculty[] = $prevSlotForFaculty;
                            if($nextSlotForFaculty !== '' && !isset($break_slots[$nextSlotForFaculty])) $adjacentSlotsForFaculty[] = $nextSlotForFaculty;

                            foreach($adjacentSlotsForFaculty as $adjSlotForFaculty){
                                if(empty($timetable[$day][$adjSlotForFaculty])) continue;

                                foreach($timetable[$day][$adjSlotForFaculty] as $adjEntryForFaculty){
                                    $adjSubject = trim((string)($adjEntryForFaculty['subject_code'] ?? ''));
                                    $adjSubjectName = trim((string)($adjEntryForFaculty['subject_name'] ?? ''));
                                    $adjBatch = trim((string)($adjEntryForFaculty['batch'] ?? ''));
                                    $adjRoom = trim((string)($adjEntryForFaculty['room_code'] ?? ''));
                                    $adjFaculty = trim((string)($adjEntryForFaculty['faculty_code'] ?? ''));

                                    if(in_array(strtoupper($adjSubject), ['A','B']) && $adjBatch === ''){
                                        $adjBatch = strtoupper($adjSubject);
                                        $adjSubject = $adjSubjectName;
                                    }

                                    $sameSubject = strtoupper($adjSubject) === strtoupper($subject);
                                    $sameBatch = strtoupper($adjBatch) === strtoupper($batch);
                                    $sameRoom = strtoupper($adjRoom) === strtoupper($room);

                                    if($sameSubject && $sameBatch && $sameRoom && $adjFaculty !== ''){
                                        $faculty = $adjFaculty;
                                        break 2;
                                    }
                                }
                            }
                        }

                        /* If old garbage A/B subject still appears, treat it as batch and use subject name */
                        if(in_array(strtoupper($subject), ['A','B']) && $batch === ''){
                            $batch = strtoupper($subject);
                            $subject = $subjectName;
                        }

                        if($subject === '' && $subjectName !== '') $subject = $subjectName;
                        if($subject === '') continue;

                        $key = strtoupper($subject);
                        if(!isset($cellGroups[$key])){
                            $cellGroups[$key] = [
                                'subject' => $subject,
                                'entries' => [],
                                'is_special' => false,
                                'is_practical' => false,
                                'is_project' => false
                            ];
                        }

                        $uSubject = strtoupper($subject.' '.$subjectName.' '.$subjectType);
                        $isPracticalEntry = false;
                        if(
                            strtoupper($subjectType) === 'PRACTICAL' ||
                            $batch !== '' ||
                            strpos($uSubject,'LAB') !== false ||
                            strpos($uSubject,'TUT') !== false ||
                            preg_match('/\b(DSL|DMSL|PAIL|EEL|PLL|PP|APP|JP|MCAL|ADAL|BDAL|DLNNL|HPCL|VAPTL|BTL|FSDL|IDSL|IOTAL|ITML|MLL|DCL|CDEL|CFL|CFDL|DLECL|EDAL|AWTL|ABDAL|BTAL)\b/', $uSubject)
                        ){
                            $isPracticalEntry = true;
                            $cellGroups[$key]['is_special'] = true;
                            $cellGroups[$key]['is_practical'] = true;
                        }
                        if(strpos($uSubject,'PBL')!==false || strpos($uSubject,'PROJECT')!==false || strpos($uSubject,'REVIEW')!==false || strpos($uSubject,'PAL')!==false || strpos($uSubject,'EXPERT SESSION')!==false){
                            $cellGroups[$key]['is_special'] = 'grey';
                            $cellGroups[$key]['is_project'] = true;
                        }

                        $entryKey = ($batch !== '' ? $batch : '-') . '|' . $divisionCell . '|' . $faculty . '|' . $room;
                        $cellGroups[$key]['entries'][$entryKey] = [
                            'batch' => $batch,
                            'faculty' => $faculty,
                            'room' => $room,
                            'division' => $divisionCell
                        ];
                    }

                    foreach($cellGroups as $group){
                        $entries = array_values($group['entries']);
                        usort($entries, function($a,$b){
                            $order = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,''=>9];
                            $oa = $order[strtoupper($a['batch'])] ?? 8;
                            $ob = $order[strtoupper($b['batch'])] ?? 8;
                            if($oa == $ob) return strcmp($a['faculty'], $b['faculty']);
                            return $oa <=> $ob;
                        });

                        $cellClassParts = [];
                        if($group['is_special'] === 'grey') $cellClassParts[] = 'grey';
                        elseif($group['is_special']) $cellClassParts[] = 'yellow';
                        if(!empty($group['is_practical'])) $cellClassParts[] = 'practical';
                        if(!empty($group['is_project'])) $cellClassParts[] = 'project';
                        $cellClass = implode(' ', $cellClassParts);
                    ?>
                    <div class="tt-card <?= $cellClass ?>">
                        <div class="tt-subject"><?= e($group['subject']) ?></div>
                        <?php foreach($entries as $meta){
                            $metaLine = '';
                            if($meta['batch'] !== '') $metaLine .= $meta['batch'].': ';

                            if($view == 'division'){
                                if($meta['faculty'] !== '') $metaLine .= $meta['faculty'];
                                if($meta['room'] !== '') $metaLine .= ($meta['faculty'] !== '' ? ' | ' : '') . $meta['room'];
                            } elseif($view == 'faculty'){
                                if($meta['division'] !== '') $metaLine .= $meta['division'];
                                if($meta['room'] !== '') $metaLine .= ($meta['division'] !== '' ? ' | ' : '') . $meta['room'];
                            } elseif($view == 'classroom'){
                                if($meta['division'] !== '') $metaLine .= $meta['division'];
                                if($meta['faculty'] !== '') $metaLine .= ($meta['division'] !== '' ? ' | ' : '') . $meta['faculty'];
                            }
                        ?>
                            <?php if(trim($metaLine) !== ''){ ?><div class="tt-meta"><?= e($metaLine) ?></div><?php } ?>
                        <?php } ?>
                    </div>
                    <?php }
                } else { ?><div class="tt-card">-</div><?php } ?></td>
                    <?php }
                } ?>
            </tr>
            <?php } ?>
        </table>
        </div>

        <!-- Legend for Division TT only -->
        <?php if($view=='division'){ ?>
        <table class="legend-table">
            <tr><th>Name of the Course</th><th>Abbreviation</th><th>Name of the Faculty</th><th>Abbreviation</th></tr>
            <?php foreach($legend_rows as $row){ ?>
            <tr>
                <td><?= e($row['subject_name']) ?></td>
                <td class="legend-code"><?= e($row['subject_code']) ?></td>
                <td><?= e(implode(', ', array_values($row['faculty_names']))) ?></td>
                <td class="legend-code"><?= e(implode('', array_values($row['faculty_codes']))) ?></td>
            </tr>
            <?php } ?>
        </table>
        <?php } ?>

        <!-- Signature footer for every timetable view -->
        <?php $signatureRoles = ['PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY']; ?>
        <table class="sign-table">
            <tr>
                <?php foreach($signatureRoles as $role){ ?>
                    <th><?= e($role) ?></th>
                <?php } ?>
            </tr>
            <tr>
                <?php foreach($signatureRoles as $role){ ?>
                    <td class="sign-space">
                        <?php if(!empty($signatures[$role]['digital_signature_path'])){ ?>
                            <img src="<?= e($signatures[$role]['digital_signature_path']) ?>" alt="<?= e($role) ?> Signature">
                        <?php } ?>
                    </td>
                <?php } ?>
            </tr>
            <tr>
                <?php foreach($signatureRoles as $role){ ?>
                    <td class="sign-name"><?= e($signatures[$role]['person_name'] ?? '') ?></td>
                <?php } ?>
            </tr>
            <tr>
                <?php foreach($signatureRoles as $role){ ?>
                    <td class="sign-designation"><?= e($signatures[$role]['designation'] ?? '') ?></td>
                <?php } ?>
            </tr>
        </table>
    </div><!-- /.tt-page -->
</div><!-- /.panel -->
<?php } ?>


<!-- Analytics moved to analytics.php -->

<!-- ============= FREE CLASSROOMS ============= -->
<?php if($view=="free_classrooms"){ ?>
<div class="panel">
    <div class="page-heading"><?= e($free_resource_title) ?> — <?= e($selected_day) ?> at <?= e($selected_slot) ?></div>
    <div class="grid-list">
        <?php if($free_classrooms && $free_classrooms->num_rows > 0){ ?>
            <?php while($r=$free_classrooms->fetch_assoc()){ ?><div class="free-box"><?= e($r['room_code']) ?></div><?php } ?>
        <?php } else { ?>
            <div class="free-box">No free resource found</div>
        <?php } ?>
    </div>
</div>

<div class="panel">
    <div class="page-heading">Classroom Resource Utilization</div>
    <div class="dashboard-grid">
        <div>
            <h3 style="margin:0 0 8px;color:#3d0f6b;font-size:14px;">Most Utilized Classrooms</h3>
            <table class="util-table">
                <tr><th>Classroom</th><th>Used / Total</th><th>Utilization</th></tr>
                <?php if($room_util_top){ while($r=$room_util_top->fetch_assoc()){ $used=intval($r['used_slots']); $pct=round(($used/$total_room_slots)*100,1); ?>
                <tr>
                    <td><b><?= e($r['room_code']) ?></b></td>
                    <td><?= e($used) ?> / <?= e($total_room_slots) ?></td>
                    <td><div style="display:flex;align-items:center;gap:8px;"><div class="util-percent-bar"><div class="util-percent-fill" style="width:<?= e($pct) ?>%"></div></div><b><?= e($pct) ?>%</b></div></td>
                </tr>
                <?php }} ?>
            </table>
        </div>
        <div>
            <h3 style="margin:0 0 8px;color:#3d0f6b;font-size:14px;">Least Utilized Classrooms</h3>
            <table class="util-table">
                <tr><th>Classroom</th><th>Used / Total</th><th>Utilization</th></tr>
                <?php if($room_util_low){ while($r=$room_util_low->fetch_assoc()){ $used=intval($r['used_slots']); $pct=round(($used/$total_room_slots)*100,1); ?>
                <tr>
                    <td><b><?= e($r['room_code']) ?></b></td>
                    <td><?= e($used) ?> / <?= e($total_room_slots) ?></td>
                    <td><div style="display:flex;align-items:center;gap:8px;"><div class="util-percent-bar"><div class="util-percent-fill" style="width:<?= e($pct) ?>%"></div></div><b><?= e($pct) ?>%</b></div></td>
                </tr>
                <?php }} ?>
            </table>
        </div>
    </div>
</div>
<?php } ?>

<!-- ============= FACULTY FREE SLOTS ============= -->
<?php if($view=="faculty_free"){ ?>

<!-- Section 1: Faculty-wise free slots -->
<div class="panel">
    <div class="page-heading">Faculty-wise Free Slots</div>
    <form method="GET" class="controls" style="margin-bottom:12px;">
        <input type="hidden" name="view" value="faculty_free">
        <input type="hidden" name="academic_year" value="<?= e($selected_year) ?>">
        <input type="hidden" name="semester" value="<?= e($selected_semester) ?>">
        <input type="hidden" name="free_day" value="<?= e($selected_free_day) ?>">
        <input type="hidden" name="free_slot" value="<?= e($selected_free_slot) ?>">

        <select name="faculty" class="searchable-select" onchange="this.form.submit()">
            <option value="" <?= $selected_faculty==''?'selected':'' ?>>Select Faculty</option>
            <?php
            $faculty_list_free_1 = $conn->query("SELECT $faculty_name_select FROM faculties WHERE $badFacultyFilter ORDER BY faculty_code");
            while($f=$faculty_list_free_1->fetch_assoc()){
                $uid=$f['faculty_uid']??'';
            ?>
                <option value="<?= e($f['faculty_code']) ?>" <?= $selected_faculty==$f['faculty_code']||$selected_faculty==$uid?'selected':'' ?>>
                    <?= e($uid) ?> | <?= e($f['faculty_code']) ?> - <?= e($f['faculty_name']) ?>
                </option>
            <?php } ?>
        </select>
    </form>

    <?php if($selected_faculty !== ''){ ?>
    <div class="table-wrap">
        <table class="tt-table">
            <tr><th>Day / Time</th><?php foreach($slots as $slot){ ?><th><?= e($slot) ?></th><?php } ?></tr>
            <?php foreach($days as $di=>$day){ ?>
            <tr>
                <td class="day"><?= e($day) ?></td>
                <?php foreach($slots as $slot){
                    if(isset($break_slots[$slot])){
                        if($di==0){ ?><td rowspan="<?= count($days) ?>" class="<?= $slot=='12:40-01:40'?'lunch-common':'break-common' ?>"><?= e($break_slots[$slot]) ?></td><?php }
                    } else { ?>
                        <td><?php if(isset($busy_slots[$day][$slot])){ ?><div class="busy-box">Busy</div><?php } else { ?><div class="free-box">Free</div><?php } ?></td>
                    <?php }
                } ?>
            </tr>
            <?php } ?>
        </table>
    </div>
    <?php } else { ?>
        <div class="free-box" style="text-align:left;">Select a faculty above to view that faculty's weekly free slots.</div>
    <?php } ?>
</div>

<!-- Section 2: Slot-wise available faculty -->
<div class="panel">
    <div class="page-heading">Slot-wise Faculty Availability</div>
    <form method="GET" class="controls" style="margin-bottom:12px;">
        <input type="hidden" name="view" value="faculty_free">
        <input type="hidden" name="academic_year" value="<?= e($selected_year) ?>">
        <input type="hidden" name="semester" value="<?= e($selected_semester) ?>">
        <input type="hidden" name="faculty" value="<?= e($selected_faculty) ?>">

        <select name="free_day" onchange="this.form.submit()">
            <option value="" <?= $selected_free_day==''?'selected':'' ?>>Select Day</option>
            <?php foreach($days as $d){ ?>
                <option value="<?= e($d) ?>" <?= $selected_free_day==$d?'selected':'' ?>><?= e($d) ?></option>
            <?php } ?>
        </select>

        <select name="free_slot" onchange="this.form.submit()">
            <option value="" <?= $selected_free_slot==''?'selected':'' ?>>Select Time Slot</option>
            <?php foreach($slots as $s){ if(!isset($break_slots[$s])){ ?>
                <option value="<?= e($s) ?>" <?= $selected_free_slot==$s?'selected':'' ?>><?= e($s) ?></option>
            <?php }} ?>
        </select>
    </form>

    <?php if($selected_free_day !== '' && $selected_free_slot !== ''){ ?>
        <div class="page-heading" style="font-size:15px;margin-top:6px;">Available Faculty — <?= e($selected_free_day) ?> at <?= e($selected_free_slot) ?></div>
        <div class="grid-list">
            <?php if($available_faculties && $available_faculties->num_rows > 0){ ?>
                <?php while($af=$available_faculties->fetch_assoc()){ ?>
                    <div class="free-box"><strong><?= e($af['faculty_code']) ?></strong><br><?= e($af['faculty_name']) ?></div>
                <?php } ?>
            <?php } else { ?>
                <div class="busy-box">No faculty available for this slot.</div>
            <?php } ?>
        </div>
    <?php } else { ?>
        <div class="free-box" style="text-align:left;">Select a day and time slot above to see all faculty available at that exact time.</div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= LOGIN ============= -->
<?php if(in_array($view,["login","admin_dashboard"])){ ?>
<div class="panel">
    <div class="page-heading"><?= isset($_SESSION['admin']) ? 'Admin Dashboard' : 'Admin Login' ?></div>

    <?php if(!isset($_SESSION['admin'])){ ?>
        <form method="POST" class="controls">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button name="login">Login</button>
        </form>
    <?php } else { ?>
        <div style="background:#f0e8fa;padding:12px 14px;border-radius:10px;border-left:5px solid #7b2fb5;color:#3d0f6b;font-weight:700;margin-bottom:14px;">
            You are logged in as Admin. Choose an admin action below.
        </div>

        <div class="login-card-row">
            <a href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>" style="text-decoration:none;">
                <div class="login-card" style="height:100%;">
                    <h3>📚 Common Information Tables</h3>
                    <p style="font-size:12px;margin:0 0 10px;color:#4b2a6b;">Manage college info, department info, time slots, faculty, resources, subjects and signatories.</p>
                    <button type="button">Open Common Tables</button>
                </div>
            </a>

            <a href="?view=manage&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>" style="text-decoration:none;">
                <div class="login-card" style="height:100%;">
                    <h3>🛠 Timetable Import Data</h3>
                    <p style="font-size:12px;margin:0 0 10px;color:#4b2a6b;">Add, edit, delete and upload timetable data after master records are ready.</p>
                    <button type="button">Open Timetable Import</button>
                </div>
            </a>

            <a href="bulk_pdf_export.php" style="text-decoration:none;">
                <div class="login-card" style="height:100%;">
                    <h3>📄 Bulk Export</h3>
                    <p style="font-size:12px;margin:0 0 10px;color:#4b2a6b;">Export multiple division/faculty/physical resource timetables together.</p>
                    <button type="button" class="print-btn">Open Bulk Export</button>
                </div>
            </a>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= COMMON INFORMATION TABLES ============= -->
<?php if($view=="common_info"){ ?>
<div class="panel">
    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>
        <?php $common_section = $_GET['section'] ?? ''; ?>

    <div class="module-filters no-print">
        <form method="GET">
            <input type="hidden" name="view" value="common_info">
        <?php if(isset($common_section)){ ?><input type="hidden" name="section" value="<?= e($common_section) ?>"><?php } ?>
            <select name="academic_year" onchange="this.form.submit()">
                <option value="" <?= $selected_year==''?'selected':'' ?>>Academic Year</option>
                <option value="2025-26" <?= $selected_year=='2025-26'?'selected':'' ?>>2025-26</option>
                <option value="2026-27" <?= $selected_year=='2026-27'?'selected':'' ?>>2026-27</option>
            </select>
            <select name="semester" onchange="this.form.submit()">
                <option value="" <?= $selected_semester==''?'selected':'' ?>>Odd / Even Sem</option>
                <option value="Odd" <?= $selected_semester=='Odd'?'selected':'' ?>>Odd</option>
                <option value="Even" <?= $selected_semester=='Even'?'selected':'' ?>>Even</option>
            </select>
        </form>
    </div>

        <?php if($common_section === ''){ ?>
            <div class="common-info-home">
                <div class="common-info-title">Common Information Management</div>

                <div class="common-info-grid-clean">


                    <a class="common-info-card-clean" href="?view=common_info&section=college_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">🏛️</div>
                        <h3>College Information</h3>
                        <p>Manage university, institute leadership and timetable coordination details.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=department&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">🏫</div>
                        <h3>Department Info</h3>
                        <p>School leadership, departments, programs and intake/division structure.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=class_teachers&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">👥</div>
                        <h3>Class Teacher List</h3>
                        <p>Manage division-wise class teacher name, abbreviation, employee ID, email and contact details.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=timeslots&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">🕒</div>
                        <h3>Time Slots Info</h3>
                        <p>Manage timetable slots, lecture slots, break slots and break names.</p>
                    </a>

                    <a class="common-info-card-clean" href="?view=common_info&section=faculty&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">👨‍🏫</div>
                        <h3>Faculty Master</h3>
                        <p>Manage faculty, academic/profile designation, department, specialization, lab assistants and peons.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=resources&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">🏢</div>
                        <h3>Physical Resources Master</h3>
                        <p>Manage classrooms, labs, tutorial rooms, faculty blocks, admin blocks and seminar halls.</p>
                    </a>

                    <a class="common-info-card-clean" href="?view=common_info&section=faculty_workload&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">📊</div>
                        <h3>Faculty Workload Master</h3>
                        <p>Plan faculty-wise teaching workload manually by academic year and semester.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=subject&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">📘</div>
                        <h3>Course Master</h3>
                        <p>Manage course code, course name, abbreviation, type, credits and teaching scheme.</p>
                    </a>


                    <a class="common-info-card-clean" href="?view=common_info&section=signatures&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">
                        <div class="ci-icon">✍️</div>
                        <h3>Signatories Info</h3>
                        <p>Manage timetable footer signatures by academic year, semester, department and specialization.</p>
                    </a>
                </div>
        <?php } ?>

        <?php if($common_section === 'faculty'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Faculty Master</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <h3>👨‍🏫 Faculty Master Template</h3>
                    <div class="common-info-template-actions">
                        <a href="Faculty_Master_Template.xlsx" download style="text-decoration:none;">
                            <button type="button">⬇ Download Template</button>
                        </a>
                        <a href="faculty_master_import.php" style="text-decoration:none;">
                            <button type="button" class="print-btn">⬆ Upload Faculty Data</button>
                        </a>
                    </div>
                </div>

<!-- Faculty Master Table -->
        <div class="page-heading" style="font-size:15px;margin-top:8px;">Faculty Master</div>
        
        <div class="common-add-row-panel">
            <div class="common-add-row-title">+ Add Faculty Row</div>
            <form method="POST" class="common-add-row-grid">
                <?php if($hasFacultyUid){ ?><div><label>Emp ID</label><input type="text" name="faculty_uid" placeholder="Emp ID" style="width:95px;"></div><?php } ?>
                <div><label>Name</label><input type="text" name="faculty_name" placeholder="Faculty Name" style="width:170px;"></div>
                <div><label>Abbrev.</label><input type="text" name="faculty_code" placeholder="ABC" style="width:75px;text-transform:uppercase;"></div>
                <div><label>Dept</label><select name="department" style="width:85px;"><?php foreach(['CSE','IT','ASH'] as $opt){ ?><option value="<?= $opt ?>"><?= $opt ?></option><?php } ?></select></div>
                <div><label>Spec</label><input type="text" name="specialization" placeholder="CORE/AIA" style="width:105px;"></div>
                <div><label>Academic Designation</label><select name="academic_designation" style="width:145px;"><?php foreach(['Professor','Associate Prof','Assistant Prof','Teaching Assistant','Adjunct Faculty'] as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                <div><label>Profile Designation</label><select name="profile_designation" style="width:135px;"><?php foreach(['','Dean','Associate Dean','HoD','Program Head'] as $opt){ ?><option value="<?= e($opt) ?>"><?= $opt==''?'Null':e($opt) ?></option><?php } ?></select></div>
                <div><label>Email</label><input type="text" name="email" placeholder="Email" style="width:165px;"></div>
                <div><label>Contact</label><input type="text" name="contact_no" placeholder="Contact" style="width:105px;"></div>
                <div><label>Cabin</label><input type="text" name="cabin_no" placeholder="Cabin" style="width:95px;"></div>
                <div><label>Role</label><select name="role_type" style="width:105px;"><?php foreach(['Teaching','Admin','Research','T+A','T+R','A+R'] as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                <div><label>Active</label><select name="is_active" style="width:70px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                <button type="submit" name="save_new_master_faculty">Save</button>
            </form>
        </div>
<div class="table-wrap" style="max-height:420px;overflow:auto;margin-bottom:18px;">
            <table class="legend-table">
                <tr>
                    <th>Sr No</th>
                    <?php if($hasFacultyUid){ ?><th>Emp ID</th><?php } ?>
                    <th>Name</th>
                    <th>Abbrev.</th>
                    <th>Department</th>
                    <th>Specialization</th>
                    <th>Academic Designation</th>
                    <th>Profile Designation</th>
                    <th>Email</th>
                    <th>Contact No.</th>
                    <th>Cabin / Cubicle No.</th>
                    <th>Role Type</th>
                    <th>Active</th>
                    <th>Save</th>
                </tr>
                <?php
                $hasFacultyRoleType = column_exists($conn, 'faculties', 'role_type');
                $facCols = "id, faculty_code, faculty_name";
                $facCols .= $hasFacultyUid ? ", faculty_uid" : ", id AS faculty_uid";
                $facCols .= $hasFacultyDesignation ? ", designation" : ", '' AS designation";
                $facCols .= $hasFacultyAcademicDesignation ? ", academic_designation" : ", '' AS academic_designation";
                $facCols .= $hasFacultyProfileDesignation ? ", profile_designation" : ", '' AS profile_designation";
                $facCols .= $hasFacultyDepartment ? ", department" : ", '' AS department";
                $facCols .= $hasFacultySpecialization ? ", specialization" : ", '' AS specialization";
                $facCols .= $hasFacultyEmail ? ", email" : ", '' AS email";
                $facCols .= $hasFacultyContact ? ", contact_no" : ", '' AS contact_no";
                $facCols .= $hasFacultyCabinNo ? ", cabin_no" : ($hasFacultySeating ? ", seating_location AS cabin_no" : ", '' AS cabin_no");
                $facCols .= $hasFacultyRoleType ? ", role_type" : ", '' AS role_type";
                $facCols .= $hasFacultyActive ? ", is_active" : ", 'Y' AS is_active";
                $facRes = $conn->query("SELECT $facCols FROM faculties WHERE $badFacultyFilter ORDER BY
                    CASE
                        WHEN designation='Dean' THEN 1
                        WHEN designation='Associate Dean' THEN 2
                        WHEN designation LIKE '%Pro VC%' THEN 3
                        WHEN designation='Professor & Director' THEN 4
                        WHEN designation LIKE '%HOD%' OR designation LIKE '%HoD%' THEN 5
                        WHEN designation='Professor' THEN 6
                        WHEN designation='Associate Professor' THEN 7
                        WHEN designation='Assistant Professor' THEN 8
                        WHEN designation='Teaching Asst.' THEN 9
                        WHEN designation='Visiting Faculty' THEN 10
                        WHEN designation='Adjunct Faculty' THEN 11
                        ELSE 99
                    END,
                    faculty_name ASC");
                $sr=1;
                while($f=$facRes->fetch_assoc()){
                    $acad = $f['academic_designation'] ?: $f['designation'];
                    $profile = $f['profile_designation'];
                ?>
                <tr>
                    <form method="POST" style="margin:0;">
                        <td style="text-align:center;"><?= $sr++ ?></td>
                        <?php if($hasFacultyUid){ ?><td><input type="text" name="faculty_uid" value="<?= e($f['faculty_uid']) ?>" style="width:95px;"></td><?php } ?>
                        <td><input type="text" name="faculty_name" value="<?= e($f['faculty_name']) ?>" style="width:170px;"></td>
                        <td><input type="text" name="faculty_code" value="<?= e($f['faculty_code']) ?>" style="width:70px;font-weight:700;"></td>
                        <td>
                            <select name="department" style="width:85px;">
                                <?php foreach(['CSE','IT','ASH'] as $opt){ ?>
                                    <option value="<?= $opt ?>" <?= strtoupper($f['department'])==$opt?'selected':'' ?>><?= $opt ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td><input type="text" name="specialization" value="<?= e($f['specialization']) ?>" placeholder="CORE/AIA/NULL" style="width:105px;"></td>
                        <td>
                            <select name="academic_designation" style="width:145px;">
                                <?php foreach(['Professor','Associate Prof','Assistant Prof','Teaching Assistant','Adjunct Faculty'] as $opt){ ?>
                                    <option value="<?= e($opt) ?>" <?= strcasecmp($acad,$opt)==0?'selected':'' ?>><?= e($opt) ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td>
                            <select name="profile_designation" style="width:135px;">
                                <?php foreach(['','Dean','Associate Dean','HoD','Program Head'] as $opt){ ?>
                                    <option value="<?= e($opt) ?>" <?= strcasecmp($profile,$opt)==0?'selected':'' ?>><?= $opt==''?'Null':e($opt) ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td><input type="text" name="email" value="<?= e($f['email']) ?>" style="width:165px;"></td>
                        <td><input type="text" name="contact_no" value="<?= e($f['contact_no']) ?>" style="width:105px;"></td>
                        <td><input type="text" name="cabin_no" value="<?= e($f['cabin_no']) ?>" style="width:110px;"></td>
                        <td>
                            <select name="role_type" style="width:105px;">
                                <?php foreach(['Teaching','Admin','Research','T+A','T+R','A+R'] as $opt){ ?>
                                    <option value="<?= e($opt) ?>" <?= strcasecmp($f['role_type'],$opt)==0?'selected':'' ?>><?= e($opt) ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td>
                            <select name="is_active" style="width:70px;">
                                <option value="Y" <?= (($f['is_active'] ?? 'Y')=='Y')?'selected':'' ?>>Y</option>
                                <option value="N" <?= (($f['is_active'] ?? 'Y')=='N')?'selected':'' ?>>N</option>
                            </select>
                        </td>
                        <td style="text-align:center;">
                            <input type="hidden" name="faculty_id" value="<?= intval($f['id']) ?>">
                            <button name="save_master_faculty" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_faculty" type="submit" onclick="return confirm('Delete this faculty row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                        </td>
                    </form>
                </tr>
                <?php } ?>
            </table>
        </div>

            </div>
        <?php } ?>


        <?php if($common_section === 'faculty_workload'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Faculty Workload Master</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <div>
                        <h3>📊 Faculty Workload Planning Template</h3>
                        <p style="margin:4px 0 0;color:#6b4a86;font-size:12px;font-weight:700;">
                            This is manually planned workload, separate from generated Faculty Workload Report. Faculty details may be merged in Excel, and Program/Subject rows can repeat below.
                        </p>
                    </div>
                    <div class="common-info-template-actions">
                        <a href="Faculty_Workload_Planning_Template.xlsx" download style="text-decoration:none;">
                            <button type="button">⬇ Download Template</button>
                        </a>
                        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">
                            <input type="hidden" name="wl_upload_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                            <input type="hidden" name="wl_upload_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                            <label style="font-size:12px;font-weight:800;color:#5a1f8c;display:flex;align-items:center;gap:5px;">
                                <input type="checkbox" name="replace_faculty_workload" value="1">
                                Replace selected AY/Sem data
                            </label>
                            <input type="file" name="faculty_workload_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required style="font-size:12px;">
                            <button type="submit" name="upload_faculty_workload_template" class="print-btn">⬆ Upload Workload</button>
                        </form>
                    </div>
                </div>

                <div style="margin:10px 0 8px;color:#5a1f8c;font-weight:900;">
                    Showing Faculty Workload Master for:
                    <span class="summary-pill pill-year"><?= e($selected_year ?: '2025-26') ?></span>
                    <span class="summary-pill pill-sem"><?= e($selected_semester ?: 'Odd') ?></span>
                </div>

                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add Faculty Workload Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <input type="hidden" name="wl_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                        <input type="hidden" name="wl_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                        <div><label>Sr No</label><input type="text" name="sr_no" style="width:60px;"></div>
                        <div><label>Name of Faculty</label><input type="text" name="faculty_name" placeholder="Faculty Name" style="width:180px;"></div>
                        <div><label>Designation</label><input type="text" name="designation" placeholder="Assistant Professor" style="width:150px;"></div>
                        <div><label>Department</label><input type="text" name="department" placeholder="CSE" style="width:90px;text-transform:uppercase;"></div>
                        <div><label>Abbrev</label><input type="text" name="faculty_abbrev" placeholder="ABC" style="width:80px;text-transform:uppercase;"></div>
                        <div><label>Name of Program</label><input type="text" name="program_name" placeholder="UG BTech CSE" style="width:160px;"></div>
                        <div><label>Name of Subject</label><input type="text" name="subject_name" placeholder="DSA" style="width:180px;"></div>
                        <div><label>TH</label><input type="text" name="th_hours" style="width:55px;"></div>
                        <div><label>TUT</label><input type="text" name="tut_hours" style="width:55px;"></div>
                        <div><label>PR</label><input type="text" name="pr_hours" style="width:55px;"></div>
                        <div><label>Mini Proj</label><input type="text" name="mini_project_hours" style="width:70px;"></div>
                        <div><label>Major Proj</label><input type="text" name="major_project_hours" style="width:75px;"></div>
                        <button type="submit" name="save_new_faculty_workload">Save</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table class="legend-table faculty-workload-master-table">
                        <tr>
                            <th>Sr No</th>
                            <th>Name of the Faculty</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Abbreviation</th>
                            <th>Name of the Program</th>
                            <th>Name of the Subject</th>
                            <th>TH</th>
                            <th>TUT</th>
                            <th>PR</th>
                            <th>Mini Proj</th>
                            <th>Major Proj</th>
                            <th>Total</th>
                            <th>Save</th>
                            <th>Delete</th>
                        </tr>
                        <?php
                        $wlAy = $conn->real_escape_string($selected_year ?: '2025-26');
                        $wlSem = $conn->real_escape_string($selected_semester ?: 'Odd');
                        $wlRes = $conn->query("
                            SELECT *
                            FROM faculty_workload_planning
                            WHERE academic_year='$wlAy' AND semester='$wlSem'
                            ORDER BY CAST(sr_no AS UNSIGNED), faculty_name, id
                        ");
                        if(!$wlRes || $wlRes->num_rows == 0){
                        ?>
                            <tr>
                                <td colspan="15" style="text-align:center;font-weight:900;color:#7b2fb5;padding:18px;">
                                    No faculty workload planning rows found for selected Academic Year and Semester.
                                </td>
                            </tr>
                        <?php
                        }
                        while($wlRes && $wl = $wlRes->fetch_assoc()){
                        ?>
                            <tr>
                                <form method="POST">
                                    <td><input type="text" name="sr_no" value="<?= e($wl['sr_no'] ?? '') ?>" style="width:55px;"></td>
                                    <td><input type="text" name="faculty_name" value="<?= e($wl['faculty_name'] ?? '') ?>" style="width:190px;"></td>
                                    <td><input type="text" name="designation" value="<?= e($wl['designation'] ?? '') ?>" style="width:150px;"></td>
                                    <td><input type="text" name="department" value="<?= e($wl['department'] ?? '') ?>" style="width:90px;text-transform:uppercase;"></td>
                                    <td><input type="text" name="faculty_abbrev" value="<?= e($wl['faculty_abbrev'] ?? '') ?>" style="width:80px;text-transform:uppercase;font-weight:800;"></td>
                                    <td><input type="text" name="program_name" value="<?= e($wl['program_name'] ?? '') ?>" style="width:170px;"></td>
                                    <td><input type="text" name="subject_name" value="<?= e($wl['subject_name'] ?? '') ?>" style="width:190px;"></td>
                                    <td><input type="text" name="th_hours" value="<?= e($wl['th_hours'] ?? '0') ?>" style="width:55px;"></td>
                                    <td><input type="text" name="tut_hours" value="<?= e($wl['tut_hours'] ?? '0') ?>" style="width:55px;"></td>
                                    <td><input type="text" name="pr_hours" value="<?= e($wl['pr_hours'] ?? '0') ?>" style="width:55px;"></td>
                                    <td><input type="text" name="mini_project_hours" value="<?= e($wl['mini_project_hours'] ?? '0') ?>" style="width:70px;"></td>
                                    <td><input type="text" name="major_project_hours" value="<?= e($wl['major_project_hours'] ?? '0') ?>" style="width:75px;"></td>
                                    <td style="font-weight:900;text-align:center;"><?= e($wl['total_hours'] ?? '0') ?></td>
                                    <td style="text-align:center;">
                                        <input type="hidden" name="workload_id" value="<?= intval($wl['id']) ?>">
                                        <input type="hidden" name="wl_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                                        <input type="hidden" name="wl_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                                        <button type="submit" name="save_faculty_workload" style="height:28px;padding:4px 10px;">Save</button>
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="submit" name="delete_faculty_workload" onclick="return confirm('Delete this workload row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                    </td>
                                </form>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>
        <?php } ?>


        <?php if($common_section === 'subject'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Course Master</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <h3>📘 Course Master Template <span style="font-size:12px;color:#6b4a86;">(AY/Sem wise, duplicate course codes allowed)</span></h3>
                    <div class="common-info-template-actions">
                        <a href="Subject_Master_Template.xlsx" download style="text-decoration:none;">
                            <button type="button">⬇ Download Template</button>
                        </a>
                        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;margin:0;flex-wrap:wrap;">
                            <input type="hidden" name="course_upload_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                            <input type="hidden" name="course_upload_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                            <input type="file" name="course_master_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required style="font-size:12px;">
                            <button type="submit" name="upload_course_master_template" class="print-btn">⬆ Upload Course Data</button>
                        </form>
                    </div>
                </div>

<!-- Course List -->
        <div style="margin:10px 0 8px;color:#5a1f8c;font-weight:900;">
            Showing Course Master for:
            <span class="summary-pill pill-year"><?= e($selected_year ?: '2025-26') ?></span>
            <span class="summary-pill pill-sem"><?= e($selected_semester ?: 'Odd') ?></span>
        </div>
        <div class="page-heading" style="font-size:15px;margin-top:8px;">Course Master</div>
        
        <div class="common-add-row-panel">
            <div class="common-add-row-title">+ Add Course Row</div>
            <form method="POST" class="common-add-row-grid">
                <input type="hidden" name="course_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                <input type="hidden" name="course_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                <div><label>Program</label><input type="text" name="program" placeholder="UG BTech CSE" style="width:120px;"></div>
                <div><label>Year</label><select name="year_name" style="width:70px;"><?php foreach(['FY','SY','TY','LY'] as $opt){ ?><option value="<?= $opt ?>"><?= $opt ?></option><?php } ?></select></div>
                <div><label>Specialization</label><input type="text" name="specialization" placeholder="CORE/AIA" style="width:110px;"></div>
                <div><label>Course Code</label><input type="text" name="course_code" placeholder="23CSE2001" style="width:105px;text-transform:uppercase;"></div>
                <div><label>Course Full Name</label><input type="text" name="course_full_name" placeholder="Course Full Name" style="width:190px;"></div>
                <div><label>Course Abbreviation</label><input type="text" name="course_abbreviation" placeholder="DSA" style="width:115px;text-transform:uppercase;"></div>
                <?php if($hasSubjectType){ ?><div><label>Type</label><select name="subject_type" style="width:100px;"><?php foreach(['Theory','Practical','Other'] as $opt){ ?><option value="<?= $opt ?>"><?= $opt ?></option><?php } ?></select></div><?php } ?>
                <div><label>No. of Credits</label><input type="text" name="credits" style="width:75px;"></div>
                <div><label>TH hrs/week</label><input type="text" name="th_hours_week" style="width:75px;"></div>
                <div><label>PR hrs/week</label><input type="text" name="pr_hours_week" style="width:75px;"></div>
                <div><label>TUT hrs/week</label><input type="text" name="tut_hours_week" style="width:85px;"></div>
                <button type="submit" name="save_new_master_subject">Save</button>
            </form>
        </div>
<div class="table-wrap" style="max-height:420px;overflow:auto;margin-bottom:18px;">
            <table class="legend-table">
                <tr>
                    <th>Sr No</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th>Specialization</th>
                    <th>Course Code</th>
                    <th>Course Full Name</th>
                    <th>Course Abbreviation</th>
                    <?php if($hasSubjectType){ ?><th>Course Type</th><?php } ?>
                    <th>No. of Credits</th>
                    <th>TH hrs/week</th>
                    <th>PR hrs/week</th>
                    <th>TUT hrs/week</th>
                    <th>Save</th>
                </tr>
                <?php
                $subCols = "id";
                $subCols .= $hasSubjectProgram ? ", program" : ", '' AS program";
                $subCols .= $hasSubjectYear ? ", year_name" : ", '' AS year_name";
                $subCols .= $hasSubjectSpec ? ", specialization" : ", '' AS specialization";
                $subCols .= $hasSubjectRealCourseCode ? ", course_code" : ", subject_code AS course_code";
                $subCols .= $hasSubjectCourseFullName ? ", course_full_name" : ", subject_name AS course_full_name";
                $subCols .= $hasSubjectCourseCode ? ", subject_code" : ", '' AS subject_code";
                $subCols .= ", subject_name";
                $subCols .= $hasSubjectType ? ", subject_type" : ", '' AS subject_type";
                $subCols .= $hasSubjectCredits ? ", credits" : ", '' AS credits";
                $subCols .= $hasSubjectTh ? ", th_hours_week" : ", '' AS th_hours_week";
                $subCols .= $hasSubjectPr ? ", pr_hours_week" : ", '' AS pr_hours_week";
                $subCols .= $hasSubjectTut ? ", tut_hours_week" : ", '' AS tut_hours_week";
                $subCols .= $hasSubjectAcademicYear ? ", academic_year" : ", '' AS academic_year";
                $subCols .= $hasSubjectSemester ? ", semester" : ", '' AS semester";

                $courseAyFilter = $conn->real_escape_string($selected_year ?: '2025-26');
                $courseSemFilter = $conn->real_escape_string($selected_semester ?: 'Odd');

                $subRes = $conn->query("
                    SELECT $subCols
                    FROM subjects
                    WHERE COALESCE(academic_year,'2025-26')='$courseAyFilter'
                      AND COALESCE(semester,'Odd')='$courseSemFilter'
                      AND course_code IS NOT NULL
                      AND TRIM(course_code) <> ''
                    ORDER BY program, FIELD(year_name,'FY','SY','TY','LY'), specialization, course_code, subject_code
                ");
                $sr = 1;
                if(!$subRes || $subRes->num_rows == 0){
                ?>
                <tr>
                    <td colspan="13" style="text-align:center;font-weight:900;color:#7b2fb5;padding:18px;">
                        No course records found for selected Academic Year and Semester.
                    </td>
                </tr>
                <?php
                }
                while($subRes && $srow = $subRes->fetch_assoc()){
                ?>
                <tr>
                    <form method="POST" style="margin:0;">
                        <td style="text-align:center;"><?= $sr++ ?></td>
                        <td><input type="text" name="program" value="<?= e($srow['program'] ?? '') ?>" style="width:105px;"></td>
                        <td>
                            <select name="year_name" style="width:70px;">
                                <?php foreach(['FY','SY','TY','LY'] as $opt){ ?>
                                    <option value="<?= $opt ?>" <?= strtoupper($srow['year_name'] ?? '')==$opt?'selected':'' ?>><?= $opt ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <td><input type="text" name="specialization" value="<?= e($srow['specialization'] ?? '') ?>" style="width:105px;"></td>
                        <td><input type="text" name="course_code" value="<?= e($srow['course_code'] ?? '') ?>" style="width:105px;"></td>
                        <td><input type="text" name="course_full_name" value="<?= e(($srow['course_full_name'] ?? '') ?: ($srow['subject_name'] ?? '')) ?>" style="width:190px;"></td>
                        <td><input type="text" name="course_abbreviation" value="<?= e($srow['subject_code'] ?? '') ?>" style="width:115px;font-weight:700;"></td>
                        <?php if($hasSubjectType){ ?>
                        <td>
                            <select name="subject_type" style="width:100px;">
                                <?php foreach(['Theory','Practical'] as $opt){ ?>
                                    <option value="<?= $opt ?>" <?= strcasecmp($srow['subject_type'] ?? '',$opt)==0?'selected':'' ?>><?= $opt ?></option>
                                <?php } ?>
                            </select>
                        </td>
                        <?php } ?>
                        <td><input type="text" name="credits" value="<?= e($srow['credits'] ?? '') ?>" style="width:60px;"></td>
                        <td><input type="text" name="th_hours_week" value="<?= e($srow['th_hours_week'] ?? '') ?>" style="width:60px;"></td>
                        <td><input type="text" name="pr_hours_week" value="<?= e($srow['pr_hours_week'] ?? '') ?>" style="width:60px;"></td>
                        <td><input type="text" name="tut_hours_week" value="<?= e($srow['tut_hours_week'] ?? '') ?>" style="width:60px;"></td>
                        <td style="text-align:center;">
                            <input type="hidden" name="subject_id" value="<?= intval($srow['id']) ?>">
                            <input type="hidden" name="course_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                            <input type="hidden" name="course_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                            <button name="save_master_subject" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_subject" type="submit" onclick="return confirm('Delete this course row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                        </td>
                    </form>
                </tr>
                <?php } ?>
            </table>
        </div>

            </div>
        <?php } ?>


        <?php if($common_section === 'department'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Department Info</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <?php
                    $deptOptions = ['CSE','IT','ASH'];
                    $programOptionsByDept = [
                        'CSE' => ['UG BTech CSE','PG MTech CSE','PG MSc CSE'],
                        'IT'  => ['UG BTech IT','PG MTech IT'],
                        'ASH' => ['First Year Engineering']
                    ];
                    $allProgramOptions = ['UG BTech CSE','UG BTech IT','PG MTech CSE','PG MTech IT','PG MSc CSE','First Year Engineering'];
                    $durationOptions = ['1 Year','2 Years','4 Years'];
                    $yearsCoveredOptions = ['FY','SY, TY, LY','FY, SY','FY, SY, TY, LY'];
                    $yearOptionsDept = ['FY','SY','TY','LY','PG Year 1','PG Year 2'];
                    $specOptions = ['NULL','CORE','AIA','AIEC','CC','BDCE','CSF','BT','DE','DA','SMAD','IT','ISA','CS','AIML'];
                    $batchOptions = ['A, B','A only','B only','None'];
                ?>

                <div class="department-structure-grid">

                    <!-- Department Master -->
                    <div class="department-structure-card">
                        <div class="department-structure-card-head">
                            <div>
                                <h3>🏫 Department Master</h3>
                                <p>Maintain department name, HoD and programs offered.</p>
                            </div>
                            <form method="POST" style="margin:0;">
                                <button type="submit" name="add_department_master">+ Add Department Row</button>
                            </form>
                        </div>

                        
                        <div class="common-add-row-panel">
                            <div class="common-add-row-title">+ Add Department Row</div>
                            <form method="POST" class="common-add-row-grid">
                                <div><label>Department</label><select name="department_name" class="w-md"><?php foreach($deptOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>HoD / Head</label><input class="w-lg" type="text" name="hod_name" placeholder="HoD / Head Name"></div>
                                <div><label>Programs Offered</label><input class="w-xl" type="text" name="programs_offered" placeholder="UG BTech CSE, PG MTech CSE"></div>
                                <button type="submit" name="save_new_department_master">Save</button>
                            </form>
                        </div>
<div class="table-wrap" style="max-height:360px;overflow:auto;">
                            <table class="legend-table department-structure-table">
                                <tr>
                                    <th>Sr No</th>
                                    <th>Department</th>
                                    <th>HoD / Head</th>
                                    <th>Programs Offered</th>
                                    <th>Save</th>
                                </tr>
                                <?php
                                $deptRes = $conn->query("
                                    SELECT *
                                    FROM department_master
                                    WHERE department_code IN ('CSE','IT','ASH')
                                       OR department_name IN ('CSE','IT','ASH')
                                    ORDER BY FIELD(COALESCE(NULLIF(department_code,''), department_name),'CSE','IT','ASH'), department_name ASC
                                ");
                                $sr = 1;
                                while($dm = $deptRes->fetch_assoc()){
                                    $deptVal = strtoupper(trim($dm['department_code'] ?: $dm['department_name']));
                                ?>
                                <tr>
                                    <form method="POST" style="margin:0;">
                                        <td style="text-align:center;"><?= $sr++ ?></td>
                                        <td>
                                            <select class="w-md" name="department_name">
                                                <?php foreach($deptOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= ($deptVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td><input class="w-lg" type="text" name="hod_name" value="<?= e($dm['hod_name']) ?>" placeholder="HoD / Head Name"></td>
                                        <td><input class="w-xl" type="text" name="programs_offered" value="<?= e($dm['programs_offered']) ?>" placeholder="UG BTech CSE, PG MTech CSE"></td>
                                        <td style="text-align:center;">
                                            <input type="hidden" name="department_id" value="<?= intval($dm['id']) ?>">
                                            <button type="submit" name="save_department_master" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_department_master" onclick="return confirm('Delete this department row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                    <!-- Program Structure -->
                    <div class="department-structure-card">
                        <div class="department-structure-card-head">
                            <div>
                                <h3>🎓 Program Structure</h3>
                                <p>Maintain each program, duration and years covered.</p>
                            </div>
                            <form method="POST" style="margin:0;">
                                <button type="submit" name="add_program_master">+ Add Program Row</button>
                            </form>
                        </div>

                        
                        <div class="common-add-row-panel">
                            <div class="common-add-row-title">+ Add Program Structure Row</div>
                            <form method="POST" class="common-add-row-grid">
                                <div><label>Department</label><select class="w-md" name="department_name"><?php foreach($deptOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Program</label><select class="w-xl" name="program_name"><?php foreach($allProgramOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Duration</label><select class="w-md" name="duration"><?php foreach($durationOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Years Covered</label><select class="w-lg" name="years_offered"><?php foreach($yearsCoveredOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <button type="submit" name="save_new_program_master">Save</button>
                            </form>
                        </div>
<div class="table-wrap" style="max-height:360px;overflow:auto;">
                            <table class="legend-table department-structure-table">
                                <tr>
                                    <th>Sr No</th>
                                    <th>Department</th>
                                    <th>Program</th>
                                    <th>Duration</th>
                                    <th>Years Covered</th>
                                    <th>Save</th>
                                    <th>Delete</th>
                                </tr>
                                <?php
                                $progRes = $conn->query("SELECT * FROM program_master
                                ORDER BY
                                FIELD(department_name,'CSE','IT','ASH'),
                                CASE
                                    WHEN program_name='UG BTech CSE' THEN 1
                                    WHEN program_name='UG BTech IT' THEN 2
                                    WHEN program_name='PG MTech CSE' THEN 3
                                    WHEN program_name='PG MTech IT' THEN 4
                                    WHEN program_name='PG MSc CSE' THEN 5
                                    WHEN program_name='First Year Engineering' THEN 6
                                    ELSE 99
                                END,
                                program_name ASC");
                                $sr = 1;
                                while($pm = $progRes->fetch_assoc()){
                                    $deptVal = strtoupper(trim($pm['department_name'] ?? ''));
                                ?>
                                <tr>
                                    <form method="POST" style="margin:0;">
                                        <td style="text-align:center;"><?= $sr++ ?></td>
                                        <td>
                                            <select class="w-md" name="department_name">
                                                <?php foreach($deptOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= ($deptVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="w-xl" name="program_name">
                                                <?php foreach($allProgramOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= (($pm['program_name'] ?? '')==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="w-md" name="duration">
                                                <?php foreach($durationOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= (($pm['duration'] ?? '')==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="w-lg" name="years_offered">
                                                <?php foreach($yearsCoveredOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= (($pm['years_offered'] ?? '')==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="hidden" name="program_id" value="<?= intval($pm['id']) ?>">
                                            <button type="submit" name="save_program_master" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_program_master" onclick="return confirm('Delete this program row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                    <!-- Year & Division Structure -->
                    <div class="department-structure-card">
                        <div class="department-structure-card-head">
                            <div>
                                <h3>👥 Year & Division Structure</h3>
                                <p>Maintain year-wise divisions, specializations, practical batches and batch strength. Division strength is intentionally removed.</p>
                            </div>
                            <form method="POST" style="margin:0;">
                                <button type="submit" name="add_year_division_structure">+ Add Row</button>
                            </form>
                        </div>

                        
                        <div class="common-add-row-panel">
                            <div class="common-add-row-title">+ Add Year & Division Structure Row</div>
                            <form method="POST" class="common-add-row-grid">
                                <div><label>Department</label><select name="department_name" class="w-sm"><?php foreach($deptOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Program</label><select name="program_name" class="w-xl"><?php foreach($allProgramOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Year</label><select name="year_name" class="w-md"><?php foreach($yearOptionsDept as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Specialization</label><select class="w-md" name="specialization"><?php foreach($specOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>No. of Divisions</label><input class="w-md" type="text" name="no_of_divisions"></div>
                                <div><label>Practical Batches</label><select class="w-md" name="practical_batches"><?php foreach($batchOptions as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                                <div><label>Batch Strength</label><input class="w-md" type="text" name="batch_strength"></div>
                                <button type="submit" name="save_new_year_division_structure">Save</button>
                            </form>
                        </div>
<div class="table-wrap" style="max-height:560px;overflow:auto;">
                            <table class="legend-table department-structure-table">
                                <tr>
                                    <th>Sr No</th>
                                    <th>Specialization</th>
                                    <th>No. of Divisions</th>
                                    <th>Practical Batches</th>
                                    <th>Batch Strength</th>
                                    <th>Save</th>
                                </tr>
                                <?php
                                $ydRes = $conn->query("
                                    SELECT *
                                    FROM year_division_structure
                                    ORDER BY
                                        FIELD(department_name,'CSE','IT','ASH'),
                                        CASE
                                            WHEN program_name='UG BTech CSE' THEN 1
                                            WHEN program_name='UG BTech IT' THEN 2
                                            WHEN program_name='First Year Engineering' THEN 3
                                            WHEN program_name='PG MTech CSE' THEN 4
                                            WHEN program_name='PG MTech IT' THEN 5
                                            WHEN program_name='PG MSc CSE' THEN 6
                                            ELSE 99
                                        END,
                                        CASE
                                            WHEN year_name='FY' THEN 1
                                            WHEN year_name='SY' THEN 2
                                            WHEN year_name='TY' THEN 3
                                            WHEN year_name='LY' THEN 4
                                            WHEN year_name='PG Year 1' THEN 5
                                            WHEN year_name='PG Year 2' THEN 6
                                            ELSE 99
                                        END,
                                        FIELD(specialization,'NULL','CORE','AIA','AIEC','CC','BDCE','CSF','BT','DE','DA','SMAD','IT','ISA','CS','AIML'),
                                        specialization ASC
                                ");
                                $sr = 1;
                                $lastGroup = '';
                                while($ys = $ydRes->fetch_assoc()){
                                    $deptVal = strtoupper(trim($ys['department_name'] ?? ''));
                                    $programVal = trim($ys['program_name'] ?? '');
                                    $yearVal = trim($ys['year_name'] ?? '');
                                    $groupKey = $deptVal.'|'.$programVal.'|'.$yearVal;
                                    if($groupKey !== $lastGroup){
                                        $lastGroup = $groupKey;
                                ?>
                                <tr style="background:#efe3ff;">
                                    <td colspan="6" style="font-weight:900;color:#3d0f6b;">
                                        <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">
                                            <span>Group:</span>
                                            <select name="department_name" class="w-sm">
                                                <?php foreach($deptOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= ($deptVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                            <select name="program_name" class="w-xl">
                                                <?php foreach($allProgramOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= ($programVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                            <select name="year_name" class="w-md">
                                                <?php foreach($yearOptionsDept as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= ($yearVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                            <span style="font-size:12px;color:#6b4a86;">Edit these dropdowns in individual specialization rows below before saving.</span>
                                        </form>
                                    </td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <form method="POST" style="margin:0;">
                                        <td style="text-align:center;"><?= $sr++ ?></td>

                                        <td>
                                            <input type="hidden" name="department_name" value="<?= e($deptVal) ?>">
                                            <input type="hidden" name="program_name" value="<?= e($programVal) ?>">
                                            <input type="hidden" name="year_name" value="<?= e($yearVal) ?>">
                                            <select class="w-md" name="specialization">
                                                <?php foreach($specOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= (strtoupper($ys['specialization'] ?? 'NULL')==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>

                                        <td><input class="w-md" type="text" name="no_of_divisions" value="<?= e($ys['no_of_divisions']) ?>"></td>

                                        <td>
                                            <select class="w-md" name="practical_batches">
                                                <?php foreach($batchOptions as $opt){ ?>
                                                    <option value="<?= e($opt) ?>" <?= (($ys['practical_batches'] ?? '')==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>

                                        <td><input class="w-md" type="text" name="batch_strength" value="<?= e($ys['batch_strength']) ?>"></td>

                                        <td style="text-align:center;">
                                            <input type="hidden" name="year_structure_id" value="<?= intval($ys['id']) ?>">
                                            <button type="submit" name="save_year_division_structure" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_year_division_structure" onclick="return confirm('Delete this structure row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        <?php } ?>



        
        <?php if($common_section === 'signatures'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Signatories Info</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <div>
                        <h3>✍️ Timetable Footer Signatories</h3>
                        <p class="signatures-master-note">
                            Manage Prepared By, Checked By, Recommended By and Approved By footer details with optional digital signature upload. Use GENERAL for default footer signatures.
                        </p>
                    </div>
                    <form method="POST" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <input type="text" name="sig_academic_year" value="<?= e($selected_year ?: '2025-26') ?>" placeholder="Academic Year" style="width:120px;">
                        <select name="sig_semester" style="width:110px;">
                            <option value="Odd" <?= ($selected_semester=='Odd')?'selected':'' ?>>Odd</option>
                            <option value="Even" <?= ($selected_semester=='Even')?'selected':'' ?>>Even</option>
                        </select>
                        <button type="submit" name="add_signature_master">+ Add Signatory Row</button>
                    </form>
                </div>

                
                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add Signatory Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <div><label>Academic Year</label><input type="text" name="academic_year" value="<?= e($selected_year ?: '2025-26') ?>" style="width:120px;"></div>
                        <div><label>Semester</label><select name="semester" style="width:110px;"><option value="Odd" <?= ($selected_semester=='Odd')?'selected':'' ?>>Odd</option><option value="Even" <?= ($selected_semester=='Even')?'selected':'' ?>>Even</option></select></div>
                        <div><label>Department Scope</label><select name="department_name" style="width:120px;"><?php foreach(['GENERAL','CSE','ASH','IT'] as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                        <div><label>Role</label><select name="role_name" style="width:160px;"><?php foreach(['PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY'] as $opt){ ?><option value="<?= e($opt) ?>"><?= e($opt) ?></option><?php } ?></select></div>
                        <div><label>Name</label><input type="text" name="person_name" style="width:180px;"></div>
                        <div><label>Designation</label><input type="text" name="designation" style="width:180px;"></div>
                        <button type="submit" name="save_new_signature_master">Save</button>
                    </form>
                </div>
<div class="table-wrap" style="max-height:520px;overflow:auto;margin-bottom:18px;">
                    <table class="legend-table signatures-master-table">
                        <tr>
                            <th>Sr No</th>
                            <th>Academic Year</th>
                            <th>Semester</th>
                            <th>Department Scope</th>
                            <th>Role</th>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Digital Signature</th>
                            <th>Save</th>
                            <th>Delete</th>
                        </tr>

                        <?php
                        $deptScopeOptions = ['GENERAL','CSE','ASH','IT'];
                        $roleOptions = ['PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY'];

                        $sigRes = $conn->query("
                            SELECT *
                            FROM timetable_signatures
                            ORDER BY
                                academic_year DESC,
                                FIELD(semester,'Odd','Even'),
                                CASE WHEN department_name='GENERAL' THEN 1 ELSE 2 END,
                                department_name ASC,
                                FIELD(role_name,'PREPARED BY','CHECKED BY','RECOMMENDED BY','APPROVED BY'),
                                id ASC
                        ");
                        $sr = 1;
                        while($sig = $sigRes->fetch_assoc()){
                            $deptVal = trim($sig['department_name'] ?? 'GENERAL');
                            if($deptVal === '') $deptVal = 'GENERAL';
                        ?>
                        <tr>
                            <form method="POST" enctype="multipart/form-data" style="margin:0;">
                                <td style="text-align:center;"><?= $sr++ ?></td>

                                <td><input type="text" name="academic_year" value="<?= e($sig['academic_year']) ?>" style="min-width:105px;"></td>

                                <td>
                                    <select name="semester" style="min-width:95px;">
                                        <option value="Odd" <?= ($sig['semester']=='Odd')?'selected':'' ?>>Odd</option>
                                        <option value="Even" <?= ($sig['semester']=='Even')?'selected':'' ?>>Even</option>
                                    </select>
                                </td>

                                <td>
                                    <select class="sig-scope" name="department_name">
                                        <?php foreach($deptScopeOptions as $opt){ ?>
                                            <option value="<?= e($opt) ?>" <?= ($deptVal==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                        <?php } ?>
                                    </select>
                                </td>

                                <td>
                                    <select class="sig-role" name="role_name">
                                        <?php foreach($roleOptions as $opt){ ?>
                                            <option value="<?= e($opt) ?>" <?= (strtoupper($sig['role_name'])==$opt)?'selected':'' ?>><?= e($opt) ?></option>
                                        <?php } ?>
                                    </select>
                                </td>

                                <td><input class="sig-name" type="text" name="person_name" value="<?= e($sig['person_name']) ?>"></td>

                                <td><input class="sig-desig" type="text" name="designation" value="<?= e($sig['designation']) ?>"></td>

                                <td style="min-width:190px;">
                                    <?php if(!empty($sig['digital_signature_path'])){ ?>
                                        <div style="margin-bottom:5px;">
                                            <img src="<?= e($sig['digital_signature_path']) ?>" alt="Digital Signature" style="max-width:120px;max-height:42px;border:1px solid #d9b8ef;border-radius:6px;background:#fff;padding:3px;">
                                        </div>
                                    <?php } ?>
                                    <input type="hidden" name="existing_digital_signature_path" value="<?= e($sig['digital_signature_path'] ?? '') ?>">
                                    <input type="file" name="digital_signature" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" style="min-width:180px;font-size:11px;">
                                </td>

                                <td style="text-align:center;">
                                    <input type="hidden" name="signature_id" value="<?= intval($sig['id']) ?>">
                                    <button type="submit" name="save_signature_master" style="height:28px;padding:4px 10px;">Save</button>
                                </td>
                                <td style="text-align:center;">
                                    <button type="submit" name="delete_signature_master" onclick="return confirm('Delete this signatory row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                </td>
                            </form>
                        </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>
        <?php } ?>



<?php if($common_section === 'class_teachers'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Class Teacher List</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <div>
                        <h3>👥 Division-wise Class Teacher Details</h3>
                        <p style="margin:4px 0 0;color:#6b4a86;font-size:12px;font-weight:700;">
                            Class teachers are maintained separately for each Academic Year and Semester. Enter Class Teacher Name and click Save. Abbrev, Emp ID, Email and Contact auto-fill from Faculty Master when found, but all fields remain editable.
                        </p>
                    </div>
                    <div class="common-info-template-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <a href="?view=common_info&common_section=class_teachers&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>&download_class_teacher_template=1" style="text-decoration:none;">
                            <button type="button">⬇ Download Template</button>
                        </a>
                        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;margin:0;">
                            <input type="file" name="class_teacher_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required style="font-size:12px;">
                            <button type="submit" name="upload_class_teacher_template" class="print-btn">⬆ Upload Class Teacher Template</button>
                        </form>
                    </div>
                </div>

                
                <div style="margin:10px 0 8px;color:#5a1f8c;font-weight:900;">Showing class teachers for: <span class="summary-pill pill-year"><?= e($selected_year ?: "2025-26") ?></span> <span class="summary-pill pill-sem"><?= e($selected_semester=="Odd"?"Odd":($selected_semester=="Even"?"Even":$selected_semester)) ?></span></div>
                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add Class Teacher Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <input type="hidden" name="ct_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                        <input type="hidden" name="ct_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                        <div><label>Division</label><input type="text" name="division_name" placeholder="SY1" style="width:100px;text-transform:uppercase;"></div>
                        <div><label>Class Teacher Name</label><input type="text" name="class_teacher" style="width:210px;"></div>
                        <div><label>Abbrev.</label><input type="text" name="class_teacher_abbrev" style="width:90px;text-transform:uppercase;"></div>
                        <div><label>Emp ID</label><input type="text" name="class_teacher_emp_id" style="width:110px;"></div>
                        <div><label>Email</label><input type="email" name="class_teacher_email" style="width:220px;"></div>
                        <div><label>Contact No.</label><input type="text" name="class_teacher_contact" style="width:130px;"></div>
                        <button type="submit" name="save_new_class_teacher_list">Save</button>
                    </form>
                </div>
<div class="table-wrap" style="max-height:560px;overflow:auto;margin-bottom:18px;">
                    <table class="legend-table">
                        <tr>
                            <th>Sr No</th>
                            <th>Division</th>
                            <th>Class Teacher Name</th>
                            <th>Abbrev.</th>
                            <th>Emp ID</th>
                            <th>Email</th>
                            <th>Contact No.</th>
                            <th>Save</th>
                            <th>Delete</th>
                        </tr>
                        <?php
                        $ctAyFilter = $conn->real_escape_string($selected_year ?: '2025-26');
                        $ctSemFilter = $conn->real_escape_string($selected_semester ?: 'Odd');
                        $ctRes = $conn->query("
                            SELECT
                                d.id AS division_id,
                                d.division_name,
                                cta.id AS assignment_id,
                                COALESCE(cta.class_teacher,'') AS class_teacher,
                                COALESCE(cta.class_teacher_abbrev,'') AS class_teacher_abbrev,
                                COALESCE(cta.class_teacher_emp_id,'') AS class_teacher_emp_id,
                                COALESCE(cta.class_teacher_email,'') AS class_teacher_email,
                                COALESCE(cta.class_teacher_contact,'') AS class_teacher_contact
                            FROM divisions d
                            LEFT JOIN class_teacher_assignments cta
                                ON cta.division_id=d.id
                               AND cta.academic_year='$ctAyFilter'
                               AND cta.semester='$ctSemFilter'
                            ORDER BY
                                CASE
                                    WHEN d.division_name LIKE 'FY%' THEN 1
                                    WHEN d.division_name LIKE 'SY%' THEN 2
                                    WHEN d.division_name LIKE 'TY%' THEN 3
                                    WHEN d.division_name LIKE 'LY%' THEN 4
                                    ELSE 5
                                END,
                                CAST(REPLACE(REPLACE(REPLACE(REPLACE(d.division_name,'FY',''),'SY',''),'TY',''),'LY','') AS UNSIGNED),
                                d.division_name ASC
                        ");
                        $sr = 1;
                        while($ct = $ctRes->fetch_assoc()){
                        ?>
                            <tr>
                                <form method="POST" style="margin:0;">
                                    <td style="text-align:center;"><?= $sr++ ?></td>
                                    <td style="font-weight:900;color:#3d0f6b;white-space:nowrap;"><?= e($ct['division_name']) ?></td>
                                    <td><input type="text" name="class_teacher" value="<?= e($ct['class_teacher']) ?>" style="min-width:210px;"></td>
                                    <td><input type="text" name="class_teacher_abbrev" value="<?= e($ct['class_teacher_abbrev']) ?>" style="min-width:90px;text-transform:uppercase;"></td>
                                    <td><input type="text" name="class_teacher_emp_id" value="<?= e($ct['class_teacher_emp_id']) ?>" style="min-width:110px;"></td>
                                    <td><input type="email" name="class_teacher_email" value="<?= e($ct['class_teacher_email']) ?>" style="min-width:220px;"></td>
                                    <td><input type="text" name="class_teacher_contact" value="<?= e($ct['class_teacher_contact']) ?>" style="min-width:130px;"></td>
                                    <td style="text-align:center;">
                                        <input type="hidden" name="assignment_id" value="<?= intval($ct['assignment_id'] ?? 0) ?>">
                                        <input type="hidden" name="division_id" value="<?= intval($ct['division_id']) ?>">
                                        <input type="hidden" name="ct_academic_year" value="<?= e($selected_year ?: '2025-26') ?>">
                                        <input type="hidden" name="ct_semester" value="<?= e($selected_semester ?: 'Odd') ?>">
                                        <button type="submit" name="save_class_teacher_list" style="height:28px;padding:4px 10px;">Save</button>
                                    </td>
                                    <td style="text-align:center;">
                                        <button type="submit" name="delete_class_teacher_list" onclick="return confirm('Delete this class teacher assignment for selected year/semester?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                    </td>
                                </form>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>
        <?php } ?>



        <?php if($common_section === 'timeslots'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Time Slots Info</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <!-- Theory / Lecture Slots -->
                <div class="common-info-template-bar">
                    <div>
                        <h3>🕒 Theory / Lecture Time Slots</h3>
                        <p class="timeslot-master-note">
                            Maintain exact lecture timings. Break name is written through Slot Label / Break Name for display.
                        </p>
                    </div>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="add_timeslot_master">+ Add Theory Slot Row</button>
                    </form>
                </div>

                
                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add Theory / Lecture Time Slot Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <div><label>Slot Label</label><input type="text" name="slot_label" placeholder="Slot 1" style="width:120px;"></div>
                        <div><label>Start Time</label><input type="text" name="start_time" placeholder="08:45" style="width:90px;"></div>
                        <div><label>End Time</label><input type="text" name="end_time" placeholder="09:40" style="width:90px;"></div>
                        <div><label>Slot Type</label><select name="slot_type" style="width:100px;"><option value="Lecture">Lecture</option><option value="Break">Break</option></select></div>
                        <div><label>Break Name</label><input type="text" name="break_name" placeholder="SHORT BREAK" style="width:140px;"></div>
                        <div><label>Active</label><select name="is_active" style="width:70px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                        <button type="submit" name="save_new_timeslot_master">Save</button>
                    </form>
                </div>
<div class="table-wrap" style="max-height:420px;overflow:auto;margin-bottom:24px;">
                    <table class="legend-table timeslot-master-table">
                        <tr>
                            <th>Sr No</th>
                            <th>Slot Label</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Slot Type</th>
                            <th>Break Name</th>
                            <th>Active</th>
                            <th>Save</th>
                        </tr>

                        <?php
                        $tsRes = $conn->query("
                            SELECT *
                            FROM timeslot_master
                            ORDER BY
                                CASE
                                    WHEN start_time='08:45' THEN 1
                                    WHEN start_time='09:40' THEN 2
                                    WHEN start_time='10:35' THEN 3
                                    WHEN start_time='10:50' THEN 4
                                    WHEN start_time='11:45' THEN 5
                                    WHEN start_time='12:40' THEN 6
                                    WHEN start_time='01:40' THEN 7
                                    WHEN start_time='02:35' THEN 8
                                    WHEN start_time='03:30' THEN 9
                                    WHEN start_time='03:40' THEN 10
                                    ELSE 99
                                END,
                                id ASC
                        ");
                        $sr = 1;
                        while($ts = $tsRes->fetch_assoc()){
                        ?>
                        <tr>
                            <form method="POST" style="margin:0;">
                                <td style="text-align:center;"><?= $sr++ ?></td>

                                <td>
                                    <input class="slot-label-input" type="text" name="slot_label" value="<?= e($ts['slot_label']) ?>" placeholder="08:45-09:40 / SHORT BREAK">
                                </td>

                                <td>
                                    <input type="text" name="start_time" value="<?= e($ts['start_time']) ?>" placeholder="08:45">
                                </td>

                                <td>
                                    <input type="text" name="end_time" value="<?= e($ts['end_time']) ?>" placeholder="09:40">
                                </td>

                                <td>
                                    <select name="slot_type">
                                        <option value="Lecture" <?= ($ts['slot_type']=='Lecture')?'selected':'' ?>>Lecture</option>
                                        <option value="Break" <?= ($ts['slot_type']=='Break')?'selected':'' ?>>Break</option>
                                    </select>
                                </td>

                                <td>
                                    <input class="break-name-input" type="text" name="break_name" value="<?= e($ts['break_name']) ?>" placeholder="SHORT BREAK / LUNCH BREAK">
                                </td>

                                <td>
                                    <select name="is_active">
                                        <option value="Y" <?= ($ts['is_active']=='Y')?'selected':'' ?>>Y</option>
                                        <option value="N" <?= ($ts['is_active']=='N')?'selected':'' ?>>N</option>
                                    </select>
                                </td>

                                <td style="text-align:center;">
                                    <input type="hidden" name="timeslot_id" value="<?= intval($ts['id']) ?>">
                                    <button type="submit" name="save_timeslot_master" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_timeslot_master" onclick="return confirm('Delete this time slot row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                </td>
                            </form>
                        </tr>
                        <?php } ?>
                    </table>
                </div>

                <!-- Practical Slots -->
                <div class="common-info-template-bar">
                    <div>
                        <h3>🧪 Practical Time Slots</h3>
                        <p class="timeslot-master-note">
                            Practical slots are combined two-hour slots. 03:40-04:30 is intentionally not included as a practical slot.
                        </p>
                    </div>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="add_practical_timeslot_master">+ Add Practical Slot Row</button>
                    </form>
                </div>

                
                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add Practical Time Slot Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <div><label>Slot Label</label><input type="text" name="slot_label" placeholder="Practical Slot 1" style="width:140px;"></div>
                        <div><label>Start Time</label><input type="text" name="start_time" placeholder="08:45" style="width:90px;"></div>
                        <div><label>End Time</label><input type="text" name="end_time" placeholder="10:35" style="width:90px;"></div>
                        <div><label>Slot Type</label><select name="slot_type" style="width:100px;"><option value="Practical">Practical</option><option value="Break">Break</option></select></div>
                        <div><label>Active</label><select name="is_active" style="width:70px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                        <button type="submit" name="save_new_practical_timeslot_master">Save</button>
                    </form>
                </div>
<div class="table-wrap" style="max-height:420px;overflow:auto;margin-bottom:18px;">
                    <table class="legend-table timeslot-master-table">
                        <tr>
                            <th>Sr No</th>
                            <th>Slot Label</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Slot Type</th>
                            <th>Active</th>
                            <th>Save</th>
                        </tr>

                        <?php
                        $ptsRes = $conn->query("
                            SELECT *
                            FROM practical_timeslot_master
                            ORDER BY
                                CASE
                                    WHEN start_time='08:45' THEN 1
                                    WHEN start_time='10:35' THEN 2
                                    WHEN start_time='10:50' THEN 3
                                    WHEN start_time='12:40' THEN 4
                                    WHEN start_time='01:40' THEN 5
                                    WHEN start_time='03:30' THEN 6
                                    ELSE 99
                                END,
                                id ASC
                        ");
                        $psr = 1;
                        while($pts = $ptsRes->fetch_assoc()){
                        ?>
                        <tr>
                            <form method="POST" style="margin:0;">
                                <td style="text-align:center;"><?= $psr++ ?></td>

                                <td>
                                    <input class="slot-label-input" type="text" name="slot_label" value="<?= e($pts['slot_label']) ?>" placeholder="Practical Slot 1 / SHORT BREAK">
                                </td>

                                <td>
                                    <input type="text" name="start_time" value="<?= e($pts['start_time']) ?>" placeholder="08:45">
                                </td>

                                <td>
                                    <input type="text" name="end_time" value="<?= e($pts['end_time']) ?>" placeholder="10:35">
                                </td>

                                <td>
                                    <select name="slot_type">
                                        <option value="Practical" <?= ($pts['slot_type']=='Practical')?'selected':'' ?>>Practical</option>
                                        <option value="Break" <?= ($pts['slot_type']=='Break')?'selected':'' ?>>Break</option>
                                    </select>
                                </td>

                                <td>
                                    <select name="is_active">
                                        <option value="Y" <?= ($pts['is_active']=='Y')?'selected':'' ?>>Y</option>
                                        <option value="N" <?= ($pts['is_active']=='N')?'selected':'' ?>>N</option>
                                    </select>
                                </td>

                                <td style="text-align:center;">
                                    <input type="hidden" name="practical_timeslot_id" value="<?= intval($pts['id']) ?>">
                                    <button type="submit" name="save_practical_timeslot_master" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_practical_timeslot_master" onclick="return confirm('Delete this practical slot row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                </td>
                            </form>
                        </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>
        <?php } ?>



        <?php if($common_section === 'college_info'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>College Information</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <div>
                        <h3>🏛️ College / Institute Information</h3>
                        <p class="college-info-note">
                            Maintain university and institute name used for official timetable records.
                        </p>
                    </div>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="add_college_info">+ Add Row</button>
                    </form>
                </div>

                
                <div class="common-add-row-panel">
                    <div class="common-add-row-title">+ Add College / Institute Row</div>
                    <form method="POST" class="common-add-row-grid">
                        <div><label>College Name</label><input type="text" name="college_name" placeholder="MIT ADT University" style="width:220px;"></div>
                        <div><label>Institute</label><input type="text" name="institute_name" placeholder="School of Computing" style="width:220px;"></div>
                        <button type="submit" name="save_new_college_info">Save</button>
                    </form>
                </div>
<?php
                $collegeRes = $conn->query("SELECT * FROM college_info ORDER BY id ASC");
                $sr = 1;
                while($ci = $collegeRes->fetch_assoc()){
                ?>
                    <form method="POST" class="college-info-form-card" style="margin-bottom:18px;">
                        <div style="font-weight:900;color:#3d0f6b;margin-bottom:12px;">
                            Sr No: <?= $sr++ ?>
                        </div>

                        <div class="college-info-form-grid">
                            <div class="college-info-field">
                                <label>College Name</label>
                                <input type="text" name="college_name" value="<?= e($ci['college_name']) ?>" placeholder="MIT ADT University">
                            </div>

                            <div class="college-info-field">
                                <label>Institute</label>
                                <input type="text" name="institute_name" value="<?= e($ci['institute_name'] ?? 'School of Computing') ?>" placeholder="School of Computing">
                            </div>
                        </div>

                        <div class="college-info-actions">
                            <input type="hidden" name="college_id" value="<?= intval($ci['id']) ?>">
                            <button type="submit" name="save_college_info">Save</button> <button type="submit" name="delete_college_info" onclick="return confirm('Delete this college info row?')" class="delete-btn">Delete</button>
                        </div>
                    </form>
                <?php } ?>

                <div class="department-structure-grid" style="margin-top:18px;">
<!-- School Leadership -->
                    <div class="department-structure-card">
                        <div class="department-structure-card-head">
                            <div>
                                <h3>🏛 School of Computing Leadership</h3>
                                <p>Maintain Pro VC, Dean, Associate Deans and key academic leadership details for the institute.</p>
                            </div>
                            <form method="POST" style="margin:0;">
                                <button type="submit" name="add_leadership_master">+ Add Leadership Row</button>
                            </form>
                        </div>

                        
                            <div class="common-add-row-panel">
                                <div class="common-add-row-title">+ Add Leadership Row</div>
                                <form method="POST" class="common-add-row-grid">
                                    <div><label>Designation</label><input class="w-xl" type="text" name="designation"></div>
                                    <div><label>Name</label><input class="w-lg" type="text" name="person_name"></div>
                                    <div><label>Email</label><input class="w-lg" type="text" name="email"></div>
                                    <div><label>Contact</label><input class="w-md" type="text" name="contact_no"></div>
                                    <button type="submit" name="save_new_leadership_master">Save</button>
                                </form>
                            </div>
<div class="table-wrap" style="max-height:360px;overflow:auto;">
                            <table class="legend-table department-structure-table">
                                <tr>
                                    <th>Sr No</th>
                                    <th>Designation</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Contact No.</th>
                                    
                                    <th>Save</th>
                                </tr>
                                <?php
                                $leadRes = $conn->query("SELECT * FROM leadership_master
                                ORDER BY
                                CASE
                                    WHEN designation LIKE '%Pro Vice Chancellor%' THEN 1
                                    WHEN designation LIKE '%Dean%' AND designation NOT LIKE '%Associate%' THEN 2
                                    WHEN designation LIKE '%Associate Dean%' THEN 3
                                    ELSE 99
                                END,
                                person_name ASC");
                                $sr = 1;
                                while($lm = $leadRes->fetch_assoc()){
                                ?>
                                <tr>
                                    <form method="POST" style="margin:0;">
                                        <td style="text-align:center;"><?= $sr++ ?></td>
                                        <td><input class="w-xl" type="text" name="designation" value="<?= e($lm['designation']) ?>"></td>
                                        <td><input class="w-lg" type="text" name="person_name" value="<?= e($lm['person_name']) ?>"></td>
                                        <td><input class="w-lg" type="text" name="email" value="<?= e($lm['email']) ?>"></td>
                                        <td><input class="w-md" type="text" name="contact_no" value="<?= e($lm['contact_no']) ?>"></td>
                                        <td style="text-align:center;">
                                            <input type="hidden" name="leadership_id" value="<?= intval($lm['id']) ?>">
                                            <button type="submit" name="save_leadership_master" style="height:28px;padding:4px 10px;">Save</button> <button type="submit" name="delete_leadership_master" onclick="return confirm('Delete this leadership row?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button>
                                        </td>
                                    </form>
                                </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                                    </div>

            </div>
        <?php } ?>


<?php if($common_section === 'resources'){ ?>
            <div class="common-info-section">
                <div class="common-info-section-head">
                    <h2>Physical Resources</h2>
                    <a class="common-info-back-btn" href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>">← Back</a>
                </div>

                <div class="common-info-template-bar">
                    <h3>🏢 Physical Resources Template</h3>
                    <div class="common-info-template-actions">
                        <a href="Physical_Resources_Master_Template.xlsx" download style="text-decoration:none;">
                            <button type="button">⬇ Download Template</button>
                        </a>
                        <a href="physical_resources_import.php" style="text-decoration:none;">
                            <button type="button" class="print-btn">⬆ Upload Resource Data</button>
                        </a>
                    </div>
                </div>

<!-- Physical Resources -->
        <div class="page-heading" style="font-size:15px;margin-top:8px;">Physical Resources</div>

        <div class="login-card" style="margin-bottom:14px;">
            <h3>🏫 Classrooms</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Classroom Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Room No</label><input type="text" name="room_code" style="width:80px;"></div>
                    <div><label>Incharge</label><input type="text" name="classroom_incharge" style="width:150px;"></div>
                    <div><label>Capacity</label><input type="text" name="capacity" style="width:60px;"></div>
                    <div><label>Benches</label><input type="text" name="no_of_benches" style="width:60px;"></div>
                    <div><label>Smart</label><select name="smart_board" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                    <div><label>LCD</label><select name="lcd_projector" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                    <div><label>WiFi</label><select name="wifi_available" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div>
                    <div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div>
                    <div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div>
                    <div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div>
                    <button type="submit" name="save_new_master_classroom">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:260px;overflow:auto;">
                <table class="legend-table">
                    <tr>
                        <th>Room No</th><th>Incharge</th><th>Capacity</th><th>Benches</th><th>Smart Board</th><th>LCD</th><th>WiFi</th><th>Block</th><th>Floor</th><th>Area</th><th>Save</th>
                    </tr>
                    <?php
                    $classCols = "id, room_code";
                    $classCols .= $hasClassIncharge ? ", classroom_incharge" : ", '' AS classroom_incharge";
                    $classCols .= $hasClassCapacity ? ", capacity" : ", '' AS capacity";
                    $classCols .= $hasClassBenches ? ", no_of_benches" : ", '' AS no_of_benches";
                    $classCols .= $hasClassSmartBoard ? ", smart_board" : ", '' AS smart_board";
                    $classCols .= $hasClassLcd ? ", lcd_projector" : ", '' AS lcd_projector";
                    $classCols .= column_exists($conn,'classrooms','wifi_available') ? ", wifi_available" : ", '' AS wifi_available";
                    $classCols .= column_exists($conn,'classrooms','block_name') ? ", block_name" : ", '' AS block_name";
                    $classCols .= column_exists($conn,'classrooms','floor_no') ? ", floor_no" : ", '' AS floor_no";
                    $classCols .= column_exists($conn,'classrooms','area_sq_meter') ? ", area_sq_meter" : ", '' AS area_sq_meter";
                    $classWhere = "room_code REGEXP '^[NS][0-9]{3,4}$'";
                    if($hasClassResourceType) $classWhere .= " AND (resource_type IS NULL OR resource_type='' OR resource_type='CLASSROOM')";
                    $roomRes = $conn->query("SELECT $classCols FROM classrooms WHERE $classWhere ORDER BY room_code");
                    while($c=$roomRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="room_code" value="<?= e($c['room_code']) ?>" style="width:80px;font-weight:700;"></td>
                            <td><input type="text" name="classroom_incharge" value="<?= e($c['classroom_incharge']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="capacity" value="<?= e($c['capacity']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="no_of_benches" value="<?= e($c['no_of_benches']) ?>" style="width:60px;"></td>
                            <td><select name="smart_board" style="width:60px;"><option value="Y" <?= $c['smart_board']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $c['smart_board']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="lcd_projector" style="width:60px;"><option value="Y" <?= $c['lcd_projector']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $c['lcd_projector']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="wifi_available" style="width:60px;"><option value="Y" <?= $c['wifi_available']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $c['wifi_available']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><input type="text" name="block_name" value="<?= e($c['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($c['floor_no']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($c['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td style="text-align:center;"><input type="hidden" name="classroom_id" value="<?= intval($c['id']) ?>"><button name="save_master_classroom" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_classroom" type="submit" onclick="return confirm('Delete this classroom?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>

        <?php if(table_exists($conn,'lab_details')){ ?>
        <div class="login-card" style="margin-bottom:14px;">
            <h3>🖥 Laboratories</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Laboratory Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Lab No</label><input type="text" name="room_code" style="width:80px;"></div><div><label>Lab Name</label><input type="text" name="lab_name" style="width:190px;"></div><div><label>Incharge</label><input type="text" name="lab_incharge" style="width:150px;"></div><div><label>Assistant</label><input type="text" name="lab_assistant" style="width:150px;"></div><div><label>Capacity</label><input type="text" name="lab_capacity" style="width:60px;"></div><div><label>PCs</label><input type="text" name="no_of_pcs" style="width:60px;"></div><div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div><div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div><div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div><button type="submit" name="save_new_master_lab">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:260px;overflow:auto;">
                <table class="legend-table">
                    <tr><th>Lab No</th><th>Lab Name</th><th>Lab Incharge</th><th>Lab Assistant</th><th>Capacity</th><th>No. of PCs</th><th>Block</th><th>Floor</th><th>Area</th><th>Save / Delete</th></tr>
                    <?php
                    $labRes = $conn->query("SELECT room_code, lab_name, lab_incharge, lab_assistant, lab_capacity, no_of_pcs, block_name, floor_no, area_sq_meter FROM lab_details ORDER BY room_code");
                    while($lab=$labRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="room_code" value="<?= e($lab['room_code']) ?>" style="width:80px;font-weight:700;"></td>
                            <td><input type="text" name="lab_name" value="<?= e($lab['lab_name']) ?>" style="width:190px;"></td>
                            <td><input type="text" name="lab_incharge" value="<?= e($lab['lab_incharge']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="lab_assistant" value="<?= e($lab['lab_assistant']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="lab_capacity" value="<?= e($lab['lab_capacity']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="no_of_pcs" value="<?= e($lab['no_of_pcs']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="block_name" value="<?= e($lab['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($lab['floor_no']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($lab['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td><button name="save_master_lab" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_lab" type="submit" onclick="return confirm('Delete this lab?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <?php } ?>

        <?php if(table_exists($conn,'tutorial_room_details')){ ?>
        <div class="login-card" style="margin-bottom:14px;">
            <h3>📘 Tutorial Rooms</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Tutorial Room Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Room No</label><input type="text" name="room_code" style="width:80px;"></div><div><label>Incharge</label><input type="text" name="tutorial_incharge" style="width:150px;"></div><div><label>Capacity</label><input type="text" name="capacity" style="width:60px;"></div><div><label>Benches</label><input type="text" name="no_of_benches" style="width:60px;"></div><div><label>Smart</label><select name="smart_board" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>LCD</label><select name="lcd_projector" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>WiFi</label><select name="wifi_available" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div><div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div><div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div><button type="submit" name="save_new_master_tutorial">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:240px;overflow:auto;">
                <table class="legend-table">
                    <tr><th>Room No</th><th>Incharge</th><th>Capacity</th><th>Benches</th><th>Smart Board</th><th>LCD</th><th>WiFi</th><th>Block</th><th>Floor</th><th>Area</th><th>Save / Delete</th></tr>
                    <?php
                    $trRes = $conn->query("SELECT room_code, tutorial_incharge, capacity, no_of_benches, smart_board, lcd_projector, wifi_available, block_name, floor_no, area_sq_meter FROM tutorial_room_details ORDER BY room_code");
                    while($tr=$trRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="room_code" value="<?= e($tr['room_code']) ?>" style="width:80px;font-weight:700;"></td>
                            <td><input type="text" name="tutorial_incharge" value="<?= e($tr['tutorial_incharge']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="capacity" value="<?= e($tr['capacity']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="no_of_benches" value="<?= e($tr['no_of_benches']) ?>" style="width:60px;"></td>
                            <td><select name="smart_board" style="width:60px;"><option value="Y" <?= $tr['smart_board']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $tr['smart_board']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="lcd_projector" style="width:60px;"><option value="Y" <?= $tr['lcd_projector']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $tr['lcd_projector']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="wifi_available" style="width:60px;"><option value="Y" <?= $tr['wifi_available']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $tr['wifi_available']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><input type="text" name="block_name" value="<?= e($tr['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($tr['floor_no']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($tr['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td><button name="save_master_tutorial" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_tutorial" type="submit" onclick="return confirm('Delete this tutorial room?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <?php } ?>

        <?php if(table_exists($conn,'faculty_block_details')){ ?>
        <div class="login-card" style="margin-bottom:14px;">
            <h3>👥 Faculty Blocks</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Faculty Block Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Block / Room</label><input type="text" name="room_code" style="width:90px;"></div><div><label>Type</label><select name="faculty_block_type" style="width:220px;"><option>Faculty block with cabins</option><option>Faculty block with cubicles</option><option>Faculty block with both cabins and cubicles</option></select></div><div><label>Assigned To</label><input type="text" name="assigned_to" style="width:150px;"></div><div><label>Incharge</label><input type="text" name="incharge" style="width:150px;"></div><div><label>Cabin Nos.</label><input type="text" name="cabin_numbers" style="width:100px;"></div><div><label>Capacity</label><input type="text" name="capacity" style="width:70px;"></div><div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div><div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div><div><label>WiFi</label><select name="wifi_available" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div><button type="submit" name="save_new_master_faculty_block">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:300px;overflow:auto;">
                <table class="legend-table">
                    <tr><th>Room No</th><th>Faculty Block Type</th><th>Department</th><th>Incharge</th><th>Cabin Nos</th><th>Capacity</th><th>Block</th><th>Floor</th><th>WiFi</th><th>Area</th><th>Save / Delete</th></tr>
                    <?php
                    $fbRes = $conn->query("SELECT room_code, faculty_block_type, assigned_to, incharge, cabin_numbers, capacity, block_name, floor_no, wifi_available, area_sq_meter FROM faculty_block_details ORDER BY room_code");
                    if($fbRes && $fbRes->num_rows > 0){
                        while($fb=$fbRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="room_code" value="<?= e($fb['room_code']) ?>" style="width:80px;font-weight:700;"></td>
                            <td>
                                <select name="faculty_block_type" style="width:220px;">
                                    <?php foreach(['Faculty Block with Cabins','Faculty Block with Cubicles','Faculty Block with Both Cabins and Cubicles'] as $opt){ ?>
                                        <option value="<?= e($opt) ?>" <?= $fb['faculty_block_type']==$opt?'selected':'' ?>><?= e($opt) ?></option>
                                    <?php } ?>
                                </select>
                            </td>
                            <td><input type="text" name="assigned_to" value="<?= e($fb['assigned_to']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="incharge" value="<?= e($fb['incharge']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="cabin_numbers" value="<?= e($fb['cabin_numbers']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="capacity" value="<?= e($fb['capacity']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="block_name" value="<?= e($fb['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($fb['floor_no']) ?>" style="width:60px;"></td>
                            <td><select name="wifi_available" style="width:60px;"><option value="Y" <?= $fb['wifi_available']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $fb['wifi_available']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($fb['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td><button name="save_master_faculty_block" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_faculty_block" type="submit" onclick="return confirm('Delete this faculty block?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } } else { ?>
                    <tr><td colspan="11" style="text-align:center;font-weight:700;color:#777;">No faculty block records found.</td></tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <?php } ?>

        <?php if(table_exists($conn,'admin_block_details')){ ?>
        <div class="login-card" style="margin-bottom:14px;">
            <h3>🏢 Admin Blocks</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Admin Block Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Admin Block Name</label><input type="text" name="admin_block_name" style="width:170px;"></div><div><label>Location</label><input type="text" name="location" style="width:120px;"></div><div><label>Incharge</label><input type="text" name="incharge" style="width:150px;"></div><div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div><div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div><div><label>WiFi</label><select name="wifi_available" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div><button type="submit" name="save_new_master_admin_block">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:240px;overflow:auto;">
                <table class="legend-table">
                    <tr><th>Admin Block Name</th><th>Location</th><th>Incharge</th><th>Block</th><th>Floor</th><th>WiFi</th><th>Area</th><th>Save / Delete</th></tr>
                    <?php
                    $abRes = $conn->query("SELECT id, admin_block_name, location, incharge, block_name, floor_no, wifi_available, area_sq_meter FROM admin_block_details ORDER BY location");
                    while($ab=$abRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="admin_block_name" value="<?= e($ab['admin_block_name']) ?>" style="width:180px;"></td>
                            <td><input type="text" name="location" value="<?= e($ab['location']) ?>" style="width:85px;font-weight:700;"></td>
                            <td><input type="text" name="incharge" value="<?= e($ab['incharge']) ?>" style="width:150px;"></td>
                            <td><input type="text" name="block_name" value="<?= e($ab['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($ab['floor_no']) ?>" style="width:60px;"></td>
                            <td><select name="wifi_available" style="width:60px;"><option value="Y" <?= $ab['wifi_available']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $ab['wifi_available']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($ab['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td><input type="hidden" name="admin_block_id" value="<?= intval($ab['id']) ?>"><button name="save_master_admin_block" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_admin_block" type="submit" onclick="return confirm('Delete this admin block?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <?php } ?>

        <?php if(table_exists($conn,'seminar_hall_details')){ ?>
        <div class="login-card" style="margin-bottom:14px;">
            <h3>🎤 Seminar Halls</h3>
            <div class="common-add-row-panel">
                <div class="common-add-row-title">+ Add Seminar Hall Row</div>
                <form method="POST" class="common-add-row-grid">
                    <div><label>Hall No</label><input type="text" name="room_code" style="width:80px;"></div><div><label>Hall Name</label><input type="text" name="seminar_hall_name" style="width:190px;"></div><div><label>Capacity</label><input type="text" name="capacity" style="width:60px;"></div><div><label>Smart</label><select name="smart_board" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>LCD</label><select name="lcd_projector" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>WiFi</label><select name="wifi_available" style="width:60px;"><option value="Y">Y</option><option value="N">N</option></select></div><div><label>Block</label><input type="text" name="block_name" style="width:105px;"></div><div><label>Floor</label><input type="text" name="floor_no" style="width:60px;"></div><div><label>Area</label><input type="text" name="area_sq_meter" style="width:70px;"></div><button type="submit" name="save_new_master_seminar">Save</button>
                </form>
            </div>

            <div class="table-wrap" style="max-height:220px;overflow:auto;">
                <table class="legend-table">
                    <tr><th>Hall No</th><th>Hall Name</th><th>Capacity</th><th>Smart Board</th><th>LCD</th><th>WiFi</th><th>Block</th><th>Floor</th><th>Area</th><th>Save / Delete</th></tr>
                    <?php
                    $semRes = $conn->query("SELECT room_code, seminar_hall_name, capacity, smart_board, lcd_projector, wifi_available, block_name, floor_no, area_sq_meter FROM seminar_hall_details ORDER BY room_code");
                    while($sh=$semRes->fetch_assoc()){
                    ?>
                    <tr>
                        <form method="POST" style="margin:0;">
                            <td><input type="text" name="room_code" value="<?= e($sh['room_code']) ?>" style="width:80px;font-weight:700;"></td>
                            <td><input type="text" name="seminar_hall_name" value="<?= e($sh['seminar_hall_name']) ?>" style="width:180px;"></td>
                            <td><input type="text" name="capacity" value="<?= e($sh['capacity']) ?>" style="width:60px;"></td>
                            <td><select name="smart_board" style="width:60px;"><option value="Y" <?= $sh['smart_board']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $sh['smart_board']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="lcd_projector" style="width:60px;"><option value="Y" <?= $sh['lcd_projector']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $sh['lcd_projector']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><select name="wifi_available" style="width:60px;"><option value="Y" <?= $sh['wifi_available']=='Y'?'selected':'' ?>>Y</option><option value="N" <?= $sh['wifi_available']=='N'?'selected':'' ?>>N</option></select></td>
                            <td><input type="text" name="block_name" value="<?= e($sh['block_name']) ?>" style="width:105px;"></td>
                            <td><input type="text" name="floor_no" value="<?= e($sh['floor_no']) ?>" style="width:60px;"></td>
                            <td><input type="text" name="area_sq_meter" value="<?= e($sh['area_sq_meter']) ?>" style="width:70px;"></td>
                            <td><button name="save_master_seminar" type="submit" style="height:28px;padding:4px 10px;">Save</button> <button name="delete_master_seminar" type="submit" onclick="return confirm('Delete this seminar hall?')" class="delete-btn" style="height:28px;padding:4px 10px;">Delete</button></td>
                        </form>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
        <?php } ?>

            </div>
        <?php } ?>

    <?php } ?>
</div>
<?php } ?>


<!-- ============= BULK IMPORT ============= -->
<?php if($view=="bulk_import"){ ?>
<div class="panel">
    <div class="page-heading">Bulk Import Center</div>
    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
            <div class="login-card">
                <h3>📅 Timetable Bulk Import</h3>
                <p style="font-size:12px;color:#6b4a86;">Download timetable template and upload one or many division timetables.</p>
                <a href="import_timetable.php" style="text-decoration:none;"><button type="button">Open Timetable Import</button></a>
            </div>
            <div class="login-card">
                <h3>👨‍🏫 Faculty Master Import</h3>
                <p style="font-size:12px;color:#6b4a86;">Import faculty, non-teaching staff and administration details.</p>
                <a href="Faculty_Master_Template.xlsx" download style="text-decoration:none;"><button type="button">⬇ Template</button></a>
                <a href="faculty_master_import.php" style="text-decoration:none;"><button type="button" class="print-btn">⬆ Upload</button></a>
            </div>
            <div class="login-card">
                <h3>🏢 Physical Resource Import</h3>
                <p style="font-size:12px;color:#6b4a86;">Import classrooms, labs, tutorial rooms, faculty blocks and seminar halls.</p>
                <a href="Physical_Resources_Master_Template.xlsx" download style="text-decoration:none;"><button type="button">⬇ Template</button></a>
                <a href="physical_resources_import.php" style="text-decoration:none;"><button type="button" class="print-btn">⬆ Upload</button></a>
            </div>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= BULK EXPORT ============= -->
<?php if($view=="bulk_export"){ ?>
<div class="panel">
    <div class="page-heading">Bulk Export Center</div>
    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>
        <div class="login-card">
            <h3>📤 Bulk Timetable PDF Export</h3>
            <p style="font-size:12px;color:#6b4a86;">Export multiple Division, Faculty or Classroom timetables together.</p>
            <a href="bulk_pdf_export.php" style="text-decoration:none;"><button type="button" class="print-btn">Open Bulk Export</button></a>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= FACULTY WORKLOAD REPORT ============= -->
<?php if($view=="faculty_workload"){ ?>
<div class="panel">
    <div class="page-heading">Faculty Workload Report</div>
    <div class="module-filters no-print">
        <form method="GET">
            <input type="hidden" name="view" value="faculty_workload">
            <select name="academic_year" onchange="this.form.submit()">
                <option value="" <?= $selected_year==''?'selected':'' ?>>Academic Year</option>
                <option value="2025-26" <?= $selected_year=='2025-26'?'selected':'' ?>>2025-26</option>
                <option value="2026-27" <?= $selected_year=='2026-27'?'selected':'' ?>>2026-27</option>
            </select>
            <select name="semester" onchange="this.form.submit()">
                <option value="" <?= $selected_semester==''?'selected':'' ?>>Odd / Even Sem</option>
                <option value="Odd" <?= $selected_semester=='Odd'?'selected':'' ?>>Odd</option>
                <option value="Even" <?= $selected_semester=='Even'?'selected':'' ?>>Even</option>
            </select>
        </form>
    </div>

    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>
        <div class="login-card">
            <h3>👨‍🏫 Faculty Workload &amp; Utilization Report</h3>
            <p style="font-size:12px;color:#6b4a86;">Download complete faculty workload summary sorted by designation hierarchy.</p>
            <a href="faculty_workload_summary.php?academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>" target="_blank" style="text-decoration:none;">
                <button type="button" class="print-btn">Open Workload Report</button>
            </a>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= RESOURCE MANAGEMENT ============= -->
<?php if($view=="resources"){ ?>
<div class="panel">
    <div class="page-heading">Resource Management</div>
    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;">
            <a href="?view=common_info&academic_year=<?= e($selected_year) ?>&semester=<?= e($selected_semester) ?>#physical-resources" style="text-decoration:none;">
                <div class="login-card" style="height:100%;"><h3>🏢 View Physical Resources</h3><p style="font-size:12px;color:#6b4a86;">Classrooms, laboratories, tutorial rooms, faculty blocks and seminar halls.</p></div>
            </a>
            <a href="physical_resources_import.php" style="text-decoration:none;">
                <div class="login-card" style="height:100%;"><h3>⬆ Upload Resource Data</h3><p style="font-size:12px;color:#6b4a86;">Bulk update physical resources using Excel template.</p></div>
            </a>
            <a href="Physical_Resources_Master_Template.xlsx" download style="text-decoration:none;">
                <div class="login-card" style="height:100%;"><h3>⬇ Download Template</h3><p style="font-size:12px;color:#6b4a86;">Get the official physical resource master template.</p></div>
            </a>
        </div>
    <?php } ?>
</div>
<?php } ?>

<!-- ============= MANAGE ============= -->
<?php if($view=="manage"){ ?>
<div class="panel">
    <div class="page-heading">Timetable Import</div>
    <div class="module-filters no-print">
        <form method="GET">
            <input type="hidden" name="view" value="manage">
            <select name="academic_year" onchange="this.form.submit()">
                <option value="" <?= $selected_year==''?'selected':'' ?>>Academic Year</option>
                <option value="2025-26" <?= $selected_year=='2025-26'?'selected':'' ?>>2025-26</option>
                <option value="2026-27" <?= $selected_year=='2026-27'?'selected':'' ?>>2026-27</option>
            </select>
            <select name="semester" onchange="this.form.submit()">
                <option value="" <?= $selected_semester==''?'selected':'' ?>>Odd / Even Sem</option>
                <option value="Odd" <?= $selected_semester=='Odd'?'selected':'' ?>>Odd</option>
                <option value="Even" <?= $selected_semester=='Even'?'selected':'' ?>>Even</option>
            </select>
        </form>
    </div>

    <?php if(!isset($_SESSION['admin'])){ ?>
        <p>Please login first from Admin Login.</p>
    <?php } else { ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:16px;">
            <a href="import_timetable.php" style="text-decoration:none;background:#f5effe;border-left:5px solid #7b2fb5;padding:16px;border-radius:10px;color:#3d0f6b;font-weight:800;font-size:13px;">
                📥 Timetable Upload Module<br>
                <small style="font-weight:500;color:#6b4fa0;">Download template &amp; import timetable files</small>
            </a>
        </div>

        <div class="manual-tt-card">
            <div class="page-heading" style="font-size:17px;margin-bottom:6px;">📝 Manual Division Timetable Entry</div>

            <div class="manual-tt-help">
                Select filters/division, fill cells directly and click <b>Save Timetable</b>. Existing timetable entries for the selected division, academic year and semester will be replaced. Break columns appear once for all days.<br>
                Cell format: <b>DSA : MMK : N507</b> or practical lines like <b>A : DSL : VVK : S514</b>.
            </div>

            <form method="GET" class="manual-tt-toolbar">
                <input type="hidden" name="view" value="manage">

                <div>
                    <label>Academic Year</label>
                    <select name="academic_year" onchange="this.form.submit()">
                        <option value="" <?= $selected_year==''?'selected':'' ?>>Academic Year</option>
                        <option value="2025-26" <?= $selected_year=='2025-26'?'selected':'' ?>>2025-26</option>
                        <option value="2026-27" <?= $selected_year=='2026-27'?'selected':'' ?>>2026-27</option>
                    </select>
                </div>

                <div>
                    <label>Semester</label>
                    <select name="semester" onchange="this.form.submit()">
                        <option value="" <?= $selected_semester==''?'selected':'' ?>>Odd / Even</option>
                        <option value="Odd" <?= ($selected_semester=='Odd')?'selected':'' ?>>Odd</option>
                        <option value="Even" <?= ($selected_semester=='Even')?'selected':'' ?>>Even</option>
                    </select>
                </div>

                <div>
                    <label>UG / PG</label>
                    <select name="program_level" onchange="cascadeDivisionFilter(this,'program')">
                        <option value="" <?= $selected_program==''?'selected':'' ?>>UG / PG</option>
                        <?php foreach($program_options as $p){ ?>
                            <option value="<?= e($p) ?>" <?= $selected_program==$p?'selected':'' ?>><?= e($p) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <?php if($selected_program=="UG" || $selected_program==""){ ?>
                    <div>
                        <label>Program</label>
                        <select name="degree_type" onchange="cascadeDivisionFilter(this,'degree')">
                            <option value="BTech" <?= ($selected_degree=='BTech' || $selected_degree=='')?'selected':'' ?>>BTech</option>
                        </select>
                    </div>

                    <div>
                        <label>Department</label>
                        <select name="department" onchange="cascadeDivisionFilter(this,'department')">
                            <option value="" <?= $selected_department==''?'selected':'' ?>>Department</option>
                            <?php foreach($ug_department_options as $dept){ ?>
                                <option value="<?= e($dept) ?>" <?= $selected_department==$dept?'selected':'' ?>><?= e($dept) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div>
                        <label>Year</label>
                        <select name="year_name" onchange="cascadeDivisionFilter(this,'year')">
                            <option value="" <?= $selected_year_name==''?'selected':'' ?>>Year</option>
                            <?php
                            $filtered_year_options = $year_options;
                            if($selected_department === 'ASH') $filtered_year_options = ['FY'];
                            foreach($filtered_year_options as $yr){
                            ?>
                                <option value="<?= e($yr) ?>" <?= $selected_year_name==$yr?'selected':'' ?>><?= e($yr) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div>
                        <label>Specialization</label>
                        <select name="specialization" onchange="cascadeDivisionFilter(this,'specialization')">
                            <option value="" <?= $selected_specialization==''?'selected':'' ?>>Specialization / NULL</option>
                            <?php
                            if($selected_department === 'IT'){
                                $manualSpecs = ['DA','SMAD','IT'];
                            } elseif($selected_department === 'ASH'){
                                $manualSpecs = ['NULL'];
                            } else {
                                $manualSpecs = ['CORE','AIA','AIEC','CC','BDCE','CSF','BT','DE'];
                            }
                            foreach($manualSpecs as $sp){
                            ?>
                                <option value="<?= e($sp) ?>" <?= $selected_specialization==$sp?'selected':'' ?>><?= e($sp) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>

                <?php if($selected_program=="PG"){ ?>
                    <div>
                        <label>Program</label>
                        <select name="degree_type" onchange="cascadeDivisionFilter(this,'degree')">
                            <option value="" <?= $selected_degree==''?'selected':'' ?>>Program</option>
                            <option value="MTech" <?= $selected_degree=='MTech'?'selected':'' ?>>MTech</option>
                            <option value="MSc" <?= $selected_degree=='MSc'?'selected':'' ?>>MSc</option>
                        </select>
                    </div>

                    <div>
                        <label>Department</label>
                        <select name="department" onchange="cascadeDivisionFilter(this,'department')">
                            <option value="" <?= $selected_department==''?'selected':'' ?>>Department</option>
                            <?php
                            $pgDeptOptions = ($selected_degree === 'MSc') ? ['CSE'] : ['CSE','IT'];
                            foreach($pgDeptOptions as $dept){
                            ?>
                                <option value="<?= e($dept) ?>" <?= $selected_department==$dept?'selected':'' ?>><?= e($dept) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div>
                        <label>Year</label>
                        <select name="year_name" onchange="cascadeDivisionFilter(this,'year')">
                            <option value="" <?= $selected_year_name==''?'selected':'' ?>>Year</option>
                            <option value="FY" <?= $selected_year_name=='FY'?'selected':'' ?>>FY</option>
                            <option value="SY" <?= $selected_year_name=='SY'?'selected':'' ?>>SY</option>
                        </select>
                    </div>

                    <div>
                        <label>Specialization</label>
                        <select name="specialization" onchange="cascadeDivisionFilter(this,'specialization')">
                            <option value="" <?= $selected_specialization==''?'selected':'' ?>>Specialization</option>
                            <?php
                            if($selected_degree === 'MSc'){
                                $pgSpecs = ['AIML'];
                            } elseif($selected_department === 'IT'){
                                $pgSpecs = ['CS'];
                            } else {
                                $pgSpecs = ['ISA'];
                            }
                            foreach($pgSpecs as $sp){
                            ?>
                                <option value="<?= e($sp) ?>" <?= $selected_specialization==$sp?'selected':'' ?>><?= e($sp) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>

                <div>
                    <label>Existing Division</label>
                    <select name="division" class="searchable-select">
                        <option value="" <?= $selected_division==''?'selected':'' ?>>Select Existing Division</option>
                        <?php
                        /* Filtered division list for Manual Timetable Entry */
                        if($hasDivDept && $hasDivProgram && $hasDivYear){
                            $manual_division_sql = "SELECT division_name FROM divisions WHERE 1=1";
                            if($selected_program !== '') $manual_division_sql .= " AND program_level='".$conn->real_escape_string($selected_program)."'";

                            if($selected_program == 'UG' || $selected_program == ''){
                                if($hasDivDegree && ($selected_degree === 'BTech' || $selected_degree === '')){
                                    $manual_division_sql .= " AND (degree_type='BTech' OR degree_type='' OR degree_type IS NULL)";
                                }
                                if($selected_department !== '') $manual_division_sql .= " AND department='".$conn->real_escape_string($selected_department)."'";
                                if($selected_year_name !== '') $manual_division_sql .= " AND year_name='".$conn->real_escape_string($selected_year_name)."'";
                                if($hasDivSpec && $selected_specialization !== '' && $selected_specialization !== 'NULL'){
                                    $manual_division_sql .= " AND specialization LIKE '%".$conn->real_escape_string($selected_specialization)."%'";
                                }
                            } elseif($selected_program == 'PG') {
                                if($hasDivDegree && $selected_degree !== '') $manual_division_sql .= " AND degree_type='".$conn->real_escape_string($selected_degree)."'";
                                if($hasDivDept && $selected_department !== '') $manual_division_sql .= " AND department='".$conn->real_escape_string($selected_department)."'";
                                if($hasDivYear && $selected_year_name !== '') $manual_division_sql .= " AND year_name='".$conn->real_escape_string($selected_year_name)."'";
                                if($hasDivSpec && $selected_specialization !== '') $manual_division_sql .= " AND specialization LIKE '%".$conn->real_escape_string($selected_specialization)."%'";
                            }

                            $manual_division_sql .= "
                                ORDER BY
                                CASE
                                    WHEN division_name LIKE 'FY%' THEN 1
                                    WHEN division_name LIKE 'SY%' THEN 2
                                    WHEN division_name LIKE 'TY%' THEN 3
                                    WHEN division_name LIKE 'LY%' THEN 4
                                    ELSE 5
                                END,
                                division_name ASC";
                        } else {
                            $manual_division_sql = "SELECT division_name FROM divisions ORDER BY division_name ASC";
                        }

                        $manualDivRes = $conn->query($manual_division_sql);
                        if($manualDivRes && $manualDivRes->num_rows > 0){
                            while($md = $manualDivRes->fetch_assoc()){
                                $dv = $md['division_name'];
                        ?>
                                <option value="<?= e($dv) ?>" <?= ($selected_division==$dv)?'selected':'' ?>><?= e($dv) ?></option>
                        <?php
                            }
                        } else {
                        ?>
                            <option value="">No existing division found</option>
                        <?php } ?>
                    </select>
                </div>

                <div>
                    <label>Create New Division</label>
                    <input type="text" name="manual_division_new" value="<?= e($_GET['manual_division_new'] ?? '') ?>" placeholder="Example: SY1 / TY AIA 1 / MTech CSE ISA FY / MSc AIML SY" style="min-width:320px;">
                </div>

                <button type="submit">Load Timetable Grid</button>
            </form>

            <?php
            $manualGrid = [];
            $manualGridDivision = trim($_GET['manual_division_new'] ?? '');
            if($manualGridDivision === '') $manualGridDivision = $selected_division;

            $manualWefDateValue = '';
            $manualPreparedByValue = '';
            if($manualGridDivision !== '' && $selected_year !== '' && $selected_semester !== ''){
                $dvSafeForWef = $conn->real_escape_string($manualGridDivision);
                $aySafeForWef = $conn->real_escape_string($selected_year);
                $semSafeForWef = $conn->real_escape_string($selected_semester);

                if(table_exists($conn, 'timetable_settings')){
                    $wefRes = $conn->query("
                        SELECT ts.wef_date, ts.prepared_by
                        FROM timetable_settings ts
                        JOIN divisions d ON d.id=ts.division_id
                        WHERE d.division_name='$dvSafeForWef'
                          AND ts.academic_year='$aySafeForWef'
                          AND ts.semester='$semSafeForWef'
                        LIMIT 1
                    ");
                    if($wefRes && $wefRes->num_rows > 0){
                        $wefRow = $wefRes->fetch_assoc();
                        $manualWefDateValue = trim($wefRow['wef_date'] ?? '');
                        $manualPreparedByValue = trim($wefRow['prepared_by'] ?? '');
                    }
                }

                if($manualWefDateValue === '' && column_exists($conn, 'divisions', 'wef_date')){
                    $wefRes = $conn->query("SELECT wef_date FROM divisions WHERE division_name='$dvSafeForWef' LIMIT 1");
                    if($wefRes && $wefRes->num_rows > 0){
                        $wefRow = $wefRes->fetch_assoc();
                        $manualWefDateValue = trim($wefRow['wef_date'] ?? '');
                    }
                }

                $dvSafe = $conn->real_escape_string($manualGridDivision);
                $aySafeManual = $conn->real_escape_string($selected_year);
                $semSafeManual = $conn->real_escape_string($selected_semester);

                $gridRes = $conn->query("
                    SELECT
                        t.day_name,
                        t.time_slot,
                        s.subject_code,
                        f.faculty_code,
                        c.room_code,
                        t.batch
                    FROM timetable_entries t
                    JOIN divisions d ON d.id=t.division_id
                    LEFT JOIN subjects s ON s.id=t.subject_id
                    LEFT JOIN faculties f ON f.id=t.faculty_id
                    LEFT JOIN classrooms c ON c.id=t.classroom_id
                    WHERE d.division_name='$dvSafe'
                      AND t.academic_year='$aySafeManual'
                      AND t.semester='$semSafeManual'
                    ORDER BY FIELD(t.day_name,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), t.time_slot, t.batch
                ");

                if($gridRes){
                    while($gr = $gridRes->fetch_assoc()){
                        $line = '';
                        $batch = trim($gr['batch'] ?? '');
                        if($batch !== '') $line .= strtoupper($batch).' : ';
                        $line .= strtoupper(trim($gr['subject_code'] ?? ''));
                        if(trim($gr['faculty_code'] ?? '') !== '') $line .= ' : '.strtoupper(trim($gr['faculty_code']));
                        if(trim($gr['room_code'] ?? '') !== '') $line .= ' : '.strtoupper(trim($gr['room_code']));

                        $d = $gr['day_name'];
                        $slt = $gr['time_slot'];
                        if(!isset($manualGrid[$d])) $manualGrid[$d] = [];
                        if(!isset($manualGrid[$d][$slt])) $manualGrid[$d][$slt] = [];
                        $manualGrid[$d][$slt][] = $line;
                    }
                }
            }
            ?>

            <?php if($manualGridDivision !== '' && $selected_year !== '' && $selected_semester !== ''){ ?>
                <form method="POST">
                    <input type="hidden" name="manual_division" value="<?= e($manualGridDivision) ?>">
                    <input type="hidden" name="manual_department" value="<?= e($selected_department ?: 'CSE') ?>">
                    <input type="hidden" name="manual_program_level" value="<?= e($selected_program ?: 'UG') ?>">
                    <input type="hidden" name="manual_degree_type" value="<?= e($selected_degree) ?>">
                    <input type="hidden" name="manual_year_name" value="<?= e($selected_year_name) ?>">
                    <input type="hidden" name="manual_specialization" value="<?= e($selected_specialization) ?>">
                    <input type="hidden" name="manual_academic_year" value="<?= e($selected_year) ?>">
                    <input type="hidden" name="manual_semester" value="<?= e($selected_semester) ?>">

                    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end;margin:12px 0;background:#fff7e8;border:1px dashed #f0a400;border-radius:10px;padding:12px;">
                        <div>
                            <label style="font-weight:900;color:#5a1f8c;display:block;margin-bottom:6px;">W.E.F Date</label>
                            <input type="date" name="manual_wef_date" value="<?= e($manualWefDateValue) ?>" style="min-width:180px;padding:10px 12px;border:1px solid #c78cff;border-radius:10px;font-weight:800;">
                        </div>
                        <div style="color:#6b4a86;font-size:12px;font-weight:700;line-height:1.45;">
                            This date will appear in Division Timetable header as <b>W.E.F.</b> for the selected Academic Year and Semester.
                        </div>
                    </div>

                    <div class="manual-tt-help" style="margin-top:8px;">
                        Editing division: <b><?= e($manualGridDivision) ?></b>.
                        If this name does not exist in the database, it will be created automatically while saving.
                    </div>

                    <?php
                        $manualProgramName = '';
                        if($selected_program === 'UG'){
                            $manualProgramName = trim(($selected_degree ?: 'BTech').' '.($selected_department ?: ''));
                        } elseif($selected_program === 'PG'){
                            $manualProgramName = trim(($selected_degree ?: '').' '.($selected_department ?: ''));
                        }
                        $manualSpecLabel = trim($selected_specialization ?: '');
                    ?>
                    <div class="manual-tt-summary">
                        <span class="summary-label">Division:</span>
                        <span class="summary-pill pill-division"><?= e($manualGridDivision) ?></span>

                        <span class="summary-label">Academic Year:</span>
                        <span class="summary-pill pill-year"><?= e($selected_year) ?></span>

                        <span class="summary-label">Semester:</span>
                        <span class="summary-pill pill-sem"><?= e($selected_semester=='Odd'?'Odd':'Even') ?></span>

                        <span class="summary-label">W.E.F:</span>
                        <span class="summary-pill pill-wef"><?= e($manualWefDateValue !== '' ? format_wef_date_display($manualWefDateValue) : '-') ?></span>

                        <span class="summary-label">Prepared By:</span>
                        <span class="summary-pill pill-prepared"><?= e($manualPreparedByValue !== '' ? $manualPreparedByValue : '-') ?></span>

                        <span class="summary-label">Program:</span>
                        <span class="summary-pill pill-program"><?= e($manualProgramName ?: '-') ?></span>

                        <?php if($selected_department !== ''){ ?>
                            <span class="summary-label">Department:</span>
                            <span class="summary-pill pill-dept"><?= e($selected_department) ?></span>
                        <?php } ?>

                        <?php if($manualSpecLabel !== ''){ ?>
                            <span class="summary-label">Specialization:</span>
                            <span class="summary-pill pill-specialization"><?= e($manualSpecLabel) ?></span>
                        <?php } ?>
                    </div>

                    <div class="table-wrap" style="overflow:auto;max-height:620px;">
                        <table class="manual-tt-table">
                            <tr>
                                <th>Day / Time</th>
                                <?php foreach($slots as $slot){ ?>
                                    <th><?= e($slot) ?></th>
                                <?php } ?>
                            </tr>

                            <?php foreach($days as $dayIndex => $day){ ?>
                                <tr>
                                    <td class="day-col"><?= e($day) ?></td>
                                    <?php foreach($slots as $slot){ ?>
                                        <?php if(isset($break_slots[$slot])){ ?>
                                            <?php if($dayIndex === 0){ ?>
                                                <td class="manual-tt-break" rowspan="<?= count($days) ?>"><?= e($break_slots[$slot]) ?></td>
                                            <?php } ?>
                                        <?php } else {
                                            $value = '';
                                            if(isset($manualGrid[$day][$slot])) $value = implode("\n", $manualGrid[$day][$slot]);
                                        ?>
                                            <td>
                                                <textarea name="manual_tt[<?= e($day) ?>][<?= e($slot) ?>]" placeholder="DSA : MMK : N507"><?= e($value) ?></textarea>
                                            </td>
                                        <?php } ?>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </table>
                    </div>

                    <div style="margin-top:14px;background:#f9f4ff;border:1px solid #d9b8ef;border-radius:12px;padding:14px;display:flex;gap:14px;align-items:end;justify-content:space-between;flex-wrap:wrap;">
                        <div style="flex:1;min-width:260px;">
                            <label style="font-weight:900;color:#5a1f8c;display:block;margin-bottom:6px;">Prepared By</label>
                            <input type="text"
                                   name="manual_prepared_by"
                                   value="<?= e($manualPreparedByValue) ?>"
                                   placeholder="Enter name of person preparing this timetable"
                                   style="width:100%;min-width:260px;padding:10px 12px;border:1px solid #c78cff;border-radius:10px;font-weight:800;">
                            <div style="font-size:12px;color:#6b4a86;font-weight:700;margin-top:5px;line-height:1.45;">
                                This Prepared By name is saved only for this selected Division + Academic Year + Semester and will appear in the Division Timetable footer.
                            </div>
                        </div>
                        <div style="text-align:right;min-width:190px;">
                            <button type="submit" name="save_manual_timetable_grid" onclick="return confirm('Save this timetable? Existing entries for this division/year/semester will be replaced.');">Save Timetable</button>
                        </div>
                    </div>
                </form>
            <?php } else { ?>
                <div style="background:#f9f4ff;border:1px solid #d9b8ef;border-radius:10px;padding:14px;color:#5a1f8c;font-weight:800;">
                    Please select Academic Year, Semester and an existing division, or type a new division name to load the editable timetable grid.
                </div>
            <?php } ?>
        </div>

    <?php } ?>
</div>
<?php } ?>

<div class="footer">
    <b>MIT Art, Design &amp; Technology University</b> &nbsp;|&nbsp; School Of Computing &nbsp;|&nbsp; Smart Timetable Automation &amp; Resource Management System
</div>

</div><!-- /.main -->
</div><!-- /.layout -->

<script>
function filterDropdownOptions(){
    const input = document.getElementById('quickSearch');
    if(!input) return;
    const filter = input.value.toLowerCase().trim();
    const select = document.querySelector('.searchable-select');
    if(!select) return;
    let first = -1;
    for(let i = 0; i < select.options.length; i++){
        const o = select.options[i];
        const match = o.text.toLowerCase().includes(filter) || o.value.toLowerCase().includes(filter);
        o.hidden = !match;
        if(match && first === -1) first = i;
    }
    if(first !== -1 && filter.length > 0) select.selectedIndex = first;
}

function cascadeDivisionFilter(el, level){
    const form = el.form;
    if(!form) return;
    const div = form.querySelector('select[name="division"]');
    if(div) div.value = '';
    if(level === 'program'){
        const degree = form.querySelector('[name="degree_type"]');
        const dept   = form.querySelector('[name="department"]');
        const year   = form.querySelector('[name="year_name"]');
        const spec   = form.querySelector('[name="specialization"]');
        if(degree) degree.value = '';
        if(dept)   dept.value   = 'CSE';
        if(year)   year.value   = 'SY';
        if(spec)   spec.value   = '';
    }
    if(level === 'department'){
        const year = form.querySelector('[name="year_name"]');
        const spec = form.querySelector('[name="specialization"]');
        if(el.value === 'ASH'){
            // Rule 1: ASH → only FY allowed
            if(year){
                // Remove all options except FY, then set FY
                for(let i = year.options.length - 1; i >= 0; i--){
                    if(year.options[i].value !== 'FY') year.remove(i);
                }
                if(year.options.length === 0){
                    const opt = document.createElement('option');
                    opt.value = 'FY'; opt.text = 'FY';
                    year.appendChild(opt);
                }
                year.value = 'FY';
            }
        } else {
            // Rule 1: CSE/IT → restore all year options
            if(year){
                const allYears = ['FY','SY','TY','LY'];
                const existingVals = Array.from(year.options).map(o => o.value);
                allYears.forEach(yr => {
                    if(!existingVals.includes(yr)){
                        const opt = document.createElement('option');
                        opt.value = yr; opt.text = yr;
                        year.appendChild(opt);
                    }
                });
                if(year.value === 'FY') year.value = 'SY';
            }
        }
        if(spec) spec.value = '';
    }
    if(level === 'year'){
        const spec = form.querySelector('[name="specialization"]');
        if(spec) spec.value = '';
    }
    form.submit();
}

document.addEventListener('DOMContentLoaded', function(){
    const input = document.getElementById('quickSearch');
    if(input){
        input.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                const select = document.querySelector('.searchable-select');
                if(select) select.form.submit();
            }
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const commonPanel = document.querySelector('.common-info-section');
    if(!commonPanel) return;

    const idTableMap = {
        faculty_id: 'faculties',
        subject_id: 'subjects',
        classroom_id: 'classrooms',
        leadership_id: 'leadership_master',
        department_id: 'department_master',
        program_id: 'program_master',
        year_structure_id: 'year_division_structure',
        year_division_id: 'year_division_structure',
        signature_id: 'timetable_signatures',
        assignment_id: 'class_teacher_assignments',
        timeslot_id: 'timeslot_master',
        practical_timeslot_id: 'practical_timeslot_master',
        college_id: 'college_info',
        admin_block_id: 'admin_block_details',
        workload_id: 'faculty_workload_planning'
    };

    const saveNamesToClassroom = [
        'save_master_classroom',
        'save_master_lab',
        'save_master_tutorial',
        'save_master_seminar',
        'save_master_faculty_block'
    ];

    commonPanel.querySelectorAll('form').forEach(function(form){
        if(form.classList.contains('common-add-row-grid')) return;
        if(form.querySelector('[name="common_info_delete_record"], [name^="delete_"]')) return;

        const saveBtn = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'))
            .find(btn => {
                const nm = btn.getAttribute('name') || '';
                return nm.indexOf('save_') === 0 && nm.indexOf('save_new_') !== 0;
            });

        if(!saveBtn) return;

        // Do not add another delete button where the row already has its own delete button.
        const alreadyHasDelete = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'))
            .some(btn => {
                const nm = btn.getAttribute('name') || '';
                return nm === 'common_info_delete_record' || nm.indexOf('delete_') === 0;
            });
        if(alreadyHasDelete) return;

        let table = '';
        let idValue = '';
        let codeValue = '';

        Object.keys(idTableMap).some(function(idName){
            const input = form.querySelector('[name="'+idName+'"]');
            if(input && input.value){
                table = idTableMap[idName];
                idValue = input.value;
                return true;
            }
            return false;
        });

        if(!table){
            const saveName = saveBtn.getAttribute('name') || '';
            if(saveNamesToClassroom.indexOf(saveName) !== -1){
                const roomInput = form.querySelector('[name="room_code"]');
                if(roomInput && roomInput.value){
                    table = 'classrooms';
                    codeValue = roomInput.value;
                }
            }
        }

        if(!table) return;

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'submit';
        deleteBtn.name = 'common_info_delete_record';
        deleteBtn.value = '1';
        deleteBtn.className = 'common-inline-delete-btn';
        deleteBtn.textContent = 'Delete';
        deleteBtn.onclick = function(){
            return confirm('Delete this record?');
        };

        const hiddenTable = document.createElement('input');
        hiddenTable.type = 'hidden';
        hiddenTable.name = 'common_delete_table';
        hiddenTable.value = table;

        const hiddenId = document.createElement('input');
        hiddenId.type = 'hidden';
        hiddenId.name = 'common_delete_id';
        hiddenId.value = idValue;

        const hiddenCode = document.createElement('input');
        hiddenCode.type = 'hidden';
        hiddenCode.name = 'common_delete_code';
        hiddenCode.value = codeValue;

        form.appendChild(hiddenTable);
        form.appendChild(hiddenId);
        form.appendChild(hiddenCode);

        saveBtn.insertAdjacentElement('afterend', deleteBtn);
    });
});
</script>

</body>
</html>
