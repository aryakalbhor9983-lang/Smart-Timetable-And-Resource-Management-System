<?php
session_start();

$host = "sqlXXX.infinityfree.com";
$user = "u448784079_sanskruti";
$password = "PASSWORD";
$database = "u448784079_university_tt";

$conn = new mysqli($host, $user, $password, $database);

if(!isset($_SESSION['admin'])) die("Access denied. Login as admin first.");

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }

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

/* ===================== DB UPGRADES ===================== */
/* Keep existing old columns also, so old timetable/faculty workload headers do not break. */
ensure_column($conn, 'faculties', 'academic_designation', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'profile_designation', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'department', "VARCHAR(50) NULL");
ensure_column($conn, 'faculties', 'specialization', "VARCHAR(80) NULL");
ensure_column($conn, 'faculties', 'cabin_no', "VARCHAR(100) NULL");
ensure_column($conn, 'faculties', 'email', "VARCHAR(150) NULL");
ensure_column($conn, 'faculties', 'contact_no', "VARCHAR(30) NULL");

/* Keep contact numbers as TEXT, never numeric, to avoid 9.87E+09 format */
if(table_exists($conn, 'faculties') && column_exists($conn, 'faculties', 'contact_no')){
    @$conn->query("ALTER TABLE faculties MODIFY COLUMN contact_no VARCHAR(30) NULL");
}

ensure_column($conn, 'faculties', 'role_type', "VARCHAR(50) NULL");
ensure_column($conn, 'faculties', 'seating_location', "VARCHAR(150) NULL");

@$conn->query("
CREATE TABLE IF NOT EXISTS lab_assistants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id VARCHAR(50) NULL,
    staff_name VARCHAR(150) NOT NULL,
    staff_code VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    contact_no VARCHAR(30) NULL,
    cabin_no VARCHAR(100) NULL,
    role_type VARCHAR(50) DEFAULT 'Lab Assistant',
    lab_no VARCHAR(100) NULL,
    lab_name VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

@$conn->query("
CREATE TABLE IF NOT EXISTS peon_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_id VARCHAR(50) NULL,
    staff_name VARCHAR(150) NOT NULL,
    staff_code VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    contact_no VARCHAR(30) NULL,
    cabin_no VARCHAR(100) NULL,
    role_type VARCHAR(50) DEFAULT 'Peon',
    floor_allocated VARCHAR(100) NULL,
    classroom_allocated VARCHAR(150) NULL,
    lab_allocated VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");


if(table_exists($conn, 'lab_assistants') && column_exists($conn, 'lab_assistants', 'contact_no')){
    @$conn->query("ALTER TABLE lab_assistants MODIFY COLUMN contact_no VARCHAR(30) NULL");
}
if(table_exists($conn, 'peon_master') && column_exists($conn, 'peon_master', 'contact_no')){
    @$conn->query("ALTER TABLE peon_master MODIFY COLUMN contact_no VARCHAR(30) NULL");
}

/* ===================== XLSX READER ===================== */
function col_to_num($letters){
    $letters = strtoupper($letters); $num = 0;
    for($i=0; $i<strlen($letters); $i++) $num = $num * 26 + (ord($letters[$i]) - 64);
    return $num;
}
function cell_ref_parts($ref){
    if(!preg_match('/^([A-Z]+)([0-9]+)$/i', $ref, $m)) return [0,0];
    return [intval($m[2]), col_to_num($m[1])];
}
function xlsx_shared_strings($zip){
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if($xml === false) return [];
    $sx = @simplexml_load_string($xml);
    if(!$sx) return [];
    $strings = [];
    foreach($sx->si as $si){
        $text = '';
        if(isset($si->t)) $text = (string)$si->t;
        else foreach($si->r as $r) $text .= (string)$r->t;
        $strings[] = $text;
    }
    return $strings;
}
function xlsx_sheet_paths($zip){
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if($workbookXml === false || $relsXml === false) return [];

    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if(!$workbook || !$rels) return [];

    $ridToTarget = [];
    foreach($rels->Relationship as $rel){
        $a = $rel->attributes();
        $ridToTarget[(string)$a['Id']] = (string)$a['Target'];
    }

    $paths = [];
    foreach($workbook->sheets->sheet as $sheet){
        $attrs = $sheet->attributes();
        $name = trim((string)$attrs['name']);
        $rattrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)$rattrs['id'];
        $target = $ridToTarget[$rid] ?? '';
        if($target === '') continue;
        $path = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . ltrim($target, '/');
        $paths[$name] = $path;
    }
    return $paths;
}
function pick_sheet_path($sheetPaths, $preferredNames, $fallbackIndex=0){
    foreach($sheetPaths as $name=>$path){
        foreach($preferredNames as $pref){
            if(strtolower(trim($name)) === strtolower(trim($pref))) return $path;
        }
    }
    foreach($sheetPaths as $name=>$path){
        foreach($preferredNames as $pref){
            if(strpos(strtolower($name), strtolower($pref)) !== false) return $path;
        }
    }
    $vals = array_values($sheetPaths);
    return $vals[$fallbackIndex] ?? '';
}
function read_sheet($zip, $path, $shared){
    if($path === '') return [];
    $xml = $zip->getFromName($path);
    if($xml === false) return [];
    $sx = @simplexml_load_string($xml);
    if(!$sx) return [];

    $cells = [];
    foreach($sx->sheetData->row as $row){
        foreach($row->c as $c){
            $a = $c->attributes();
            $ref = (string)$a['r'];
            [$r,$col] = cell_ref_parts($ref);
            $type = (string)$a['t'];
            $val = '';
            if($type === 's'){
                $idx = intval((string)$c->v);
                $val = $shared[$idx] ?? '';
            } elseif($type === 'inlineStr'){
                if(isset($c->is->t)) $val = (string)$c->is->t;
                else foreach($c->is->r as $rr) $val .= (string)$rr->t;
            } else {
                $val = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$r][$col] = trim(str_replace(["\r\n","\r"], "\n", $val));
        }
    }
    return $cells;
}
function cv($cells,$r,$c){ return trim((string)($cells[$r][$c] ?? '')); }
function make_code_from_name($name){
    $parts = preg_split('/\s+/', strtoupper(trim($name)));
    $code = '';
    foreach($parts as $p){
        $p = preg_replace('/[^A-Z]/','',$p);
        if($p !== '' && !in_array($p, ['PROF','DR','MR','MRS','MS'])) $code .= $p[0];
    }
    return $code !== '' ? substr($code,0,8) : 'STAFF'.rand(100,999);
}

function normalize_contact_no($v){
    $v = trim((string)$v);
    if($v === '') return '';

    // Remove spaces, hyphens and brackets but keep + if present at start.
    $v = str_replace([" ", "-", "(", ")", "\t"], "", $v);

    // Excel sometimes stores mobile numbers as scientific notation like 9.87654E+09.
    if(preg_match('/^[0-9]+(\.[0-9]+)?[eE]\+?[0-9]+$/', $v)){
        $v = sprintf('%.0f', (float)$v);
    }

    // If decimal was introduced by Excel, remove trailing .0
    if(preg_match('/^[0-9]+\.0+$/', $v)){
        $v = preg_replace('/\.0+$/', '', $v);
    }

    // Keep only digits and optional starting +
    if(strpos($v, '+') === 0){
        $v = '+' . preg_replace('/\D/', '', substr($v, 1));
    } else {
        $v = preg_replace('/\D/', '', $v);
    }

    return $v;
}

$msg = "";
$facultyCount = 0;
$labAssistantCount = 0;
$peonCount = 0;

if(isset($_POST['upload_faculty'])){
    if(!isset($_FILES['faculty_file']) || $_FILES['faculty_file']['error'] !== 0){
        $msg = "Please upload Faculty_Master_Template.xlsx.";
    } elseif(!class_exists('ZipArchive')){
        $msg = "Server error: ZipArchive is not enabled.";
    } else {
        $zip = new ZipArchive;
        if($zip->open($_FILES['faculty_file']['tmp_name']) !== TRUE){
            $msg = "Could not open XLSX file.";
        } else {
            $shared = xlsx_shared_strings($zip);
            $sheetPaths = xlsx_sheet_paths($zip);

            /* ===================== SHEET 1: Faculty_Master =====================
               A Sr No
               B Emp ID / Faculty UID
               C Faculty Name
               D Abbreviation
               E Department
               F Specialization
               G Academic Designation
               H Profile Designation
               I Email
               J Contact No
               K Cabin No / Cubicle No
               L Role Type
            ================================================================== */
            $facultyPath = pick_sheet_path($sheetPaths, ['Faculty_Master','Faculty Master','Teaching Faculty','Faculty'], 0);
            $facultyCells = read_sheet($zip, $facultyPath, $shared);

            for($r=2; $r<=1000; $r++){
                $uid = cv($facultyCells,$r,2);
                $name = cv($facultyCells,$r,3);
                $code = strtoupper(cv($facultyCells,$r,4));
                $department = strtoupper(cv($facultyCells,$r,5));
                $specialization = strtoupper(cv($facultyCells,$r,6));
                $academicDesignation = cv($facultyCells,$r,7);
                $profileDesignation = cv($facultyCells,$r,8);
                $email = cv($facultyCells,$r,9);
                $contact = normalize_contact_no(cv($facultyCells,$r,10));
                $cabinNo = cv($facultyCells,$r,11);
                $role = cv($facultyCells,$r,12);

                if($code === '' && $name === '') continue;
                if($code === '') $code = make_code_from_name($name);
                if($uid === '') $uid = $code;
                if($role === '') $role = 'Teaching';
                if($specialization === 'NULL') $specialization = '';
                if($profileDesignation === 'Null' || strtoupper($profileDesignation)==='NULL') $profileDesignation = '';

                /* Compatibility:
                   - Old code uses designation in headers/workload.
                   - Put profile designation first if available, else academic designation.
                   - Old seating_location gets cabin_no value.
                */
                $displayDesignation = ($profileDesignation !== '') ? $profileDesignation : $academicDesignation;

                $stmt = $conn->prepare("SELECT id FROM faculties WHERE faculty_code=? OR faculty_uid=? LIMIT 1");
                $stmt->bind_param("ss", $code, $uid);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if($existing){
                    $stmt = $conn->prepare("
                        UPDATE faculties
                        SET faculty_uid=?, faculty_code=?, faculty_name=?,
                            designation=?, academic_designation=?, profile_designation=?,
                            department=?, specialization=?,
                            email=?, contact_no=?, seating_location=?, cabin_no=?, role_type=?
                        WHERE id=?
                    ");
                    $stmt->bind_param(
                        "sssssssssssssi",
                        $uid, $code, $name,
                        $displayDesignation, $academicDesignation, $profileDesignation,
                        $department, $specialization,
                        $email, $contact, $cabinNo, $cabinNo, $role,
                        $existing['id']
                    );
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO faculties
                        (faculty_uid, faculty_code, faculty_name,
                         designation, academic_designation, profile_designation,
                         department, specialization,
                         email, contact_no, seating_location, cabin_no, role_type)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->bind_param(
                        "sssssssssssss",
                        $uid, $code, $name,
                        $displayDesignation, $academicDesignation, $profileDesignation,
                        $department, $specialization,
                        $email, $contact, $cabinNo, $cabinNo, $role
                    );
                    $stmt->execute();
                }
                $facultyCount++;
            }

            /* ===================== SHEET 2: Lab_Assistants =====================
               A Sr No
               B Emp ID
               C Name
               D Abbreviation
               E Email
               F Contact No
               G Cabin No / Cubicle No
               H Role Type
               I Lab No
               J Lab Name
            ================================================================== */
            $labPath = pick_sheet_path($sheetPaths, ['Lab_Assistants','Lab Assistants','Lab Assistant'], 1);
            $labCells = read_sheet($zip, $labPath, $shared);

            for($r=2; $r<=1000; $r++){
                $emp = cv($labCells,$r,2);
                $name = cv($labCells,$r,3);
                $code = strtoupper(cv($labCells,$r,4));
                $email = cv($labCells,$r,5);
                $contact = normalize_contact_no(cv($labCells,$r,6));
                $cabinNo = cv($labCells,$r,7);
                $role = cv($labCells,$r,8);
                $labNo = cv($labCells,$r,9);
                $labName = cv($labCells,$r,10);

                if($code === '' && $name === '') continue;
                if($code === '') $code = make_code_from_name($name);
                if($emp === '') $emp = $code;
                if($role === '') $role = 'Lab Assistant';

                $stmt = $conn->prepare("SELECT id FROM lab_assistants WHERE staff_code=? OR emp_id=? LIMIT 1");
                $stmt->bind_param("ss", $code, $emp);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if($existing){
                    $stmt = $conn->prepare("
                        UPDATE lab_assistants
                        SET emp_id=?, staff_name=?, staff_code=?, email=?, contact_no=?, cabin_no=?, role_type=?, lab_no=?, lab_name=?
                        WHERE id=?
                    ");
                    $stmt->bind_param("sssssssssi", $emp, $name, $code, $email, $contact, $cabinNo, $role, $labNo, $labName, $existing['id']);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO lab_assistants
                        (emp_id, staff_name, staff_code, email, contact_no, cabin_no, role_type, lab_no, lab_name)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->bind_param("sssssssss", $emp, $name, $code, $email, $contact, $cabinNo, $role, $labNo, $labName);
                    $stmt->execute();
                }
                $labAssistantCount++;
            }

            /* ===================== SHEET 3: Peon_Master =====================
               A Sr No
               B Emp ID
               C Name
               D Abbreviation
               E Email
               F Contact No
               G Cabin No / Cubicle No
               H Role Type
               I Floor Allocated
               J Classroom Allocated
               K Lab Allocated
            ================================================================== */
            $peonPath = pick_sheet_path($sheetPaths, ['Peon_Master','Peon Master','Peons','Peon'], 2);
            $peonCells = read_sheet($zip, $peonPath, $shared);

            for($r=2; $r<=1000; $r++){
                $emp = cv($peonCells,$r,2);
                $name = cv($peonCells,$r,3);
                $code = strtoupper(cv($peonCells,$r,4));
                $email = cv($peonCells,$r,5);
                $contact = normalize_contact_no(cv($peonCells,$r,6));
                $cabinNo = cv($peonCells,$r,7);
                $role = cv($peonCells,$r,8);
                $floor = cv($peonCells,$r,9);
                $classroom = cv($peonCells,$r,10);
                $lab = cv($peonCells,$r,11);

                if($code === '' && $name === '') continue;
                if($code === '') $code = make_code_from_name($name);
                if($emp === '') $emp = $code;
                if($role === '') $role = 'Peon';

                $stmt = $conn->prepare("SELECT id FROM peon_master WHERE staff_code=? OR emp_id=? LIMIT 1");
                $stmt->bind_param("ss", $code, $emp);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if($existing){
                    $stmt = $conn->prepare("
                        UPDATE peon_master
                        SET emp_id=?, staff_name=?, staff_code=?, email=?, contact_no=?, cabin_no=?, role_type=?, floor_allocated=?, classroom_allocated=?, lab_allocated=?
                        WHERE id=?
                    ");
                    $stmt->bind_param("ssssssssssi", $emp, $name, $code, $email, $contact, $cabinNo, $role, $floor, $classroom, $lab, $existing['id']);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO peon_master
                        (emp_id, staff_name, staff_code, email, contact_no, cabin_no, role_type, floor_allocated, classroom_allocated, lab_allocated)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->bind_param("ssssssssss", $emp, $name, $code, $email, $contact, $cabinNo, $role, $floor, $classroom, $lab);
                    $stmt->execute();
                }
                $peonCount++;
            }

            $zip->close();

            $msg = "Faculty master import completed. Faculty: $facultyCount, Lab Assistants: $labAssistantCount, Peons: $peonCount.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Faculty Master Import</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f5f0fa;padding:28px;color:#1a0533}
.card{max-width:920px;margin:auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 8px 25px rgba(90,31,140,.15)}
h1{margin-top:0;color:#3d0f6b}
.btn,button{display:inline-block;background:#7b2fb5;color:#fff;padding:10px 16px;border-radius:9px;text-decoration:none;border:none;font-weight:800;cursor:pointer}
button{background:#C1345C}
input{padding:10px;border:1px solid #c9a0e0;border-radius:8px}
.msg{background:#f0e8fa;border-left:5px solid #7b2fb5;padding:12px;border-radius:8px;margin:12px 0;font-weight:700}
.note{background:#f9f4ff;border:1px solid #e0c8f0;padding:12px;border-radius:10px;margin:12px 0;line-height:1.55}
ul{margin-top:6px}
</style>
</head>
<body>
<div class="card">
<h1>Faculty Master Bulk Import</h1>

<?php if($msg){ ?><div class="msg"><?= e($msg) ?></div><?php } ?>

<p>
    <a class="btn" href="Faculty_Master_Template.xlsx" download>
        Download Faculty Master Template
    </a>
</p>

<div class="note">
    <b>Workbook sheets supported:</b>
    <ul>
        <li><b>Faculty_Master:</b> Emp ID, Faculty Name, Abbreviation, Department, Specialization, Academic Designation, Profile Designation, Email, Contact No, Cabin/Cubicle No, Role Type. Contact No is saved as text to prevent 9.87E+09 format.</li>
        <li><b>Lab_Assistants:</b> Emp ID, Name, Abbreviation, Email, Contact No, Cabin/Cubicle No, Role Type, Lab No, Lab Name.</li>
        <li><b>Peon_Master:</b> Emp ID, Name, Abbreviation, Email, Contact No, Cabin/Cubicle No, Role Type, Floor Allocated, Classroom Allocated, Lab Allocated.</li>
    </ul>
</div>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="faculty_file" accept=".xlsx" required>
    <button name="upload_faculty">Upload & Import</button>
</form>

<p><a href="index.php?view=common_info">Back to Common Info</a></p>
</div>
</body>
</html>
