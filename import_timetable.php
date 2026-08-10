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

if(!isset($_SESSION['admin'])){
    die("Access denied. Please login as admin first from index.php.");
}

function column_exists_import($conn, $table, $column){
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $res && $res->num_rows > 0;
}

function safe_alter_import($conn){
    if(!column_exists_import($conn, 'subjects', 'subject_type')){
        @$conn->query("ALTER TABLE subjects ADD COLUMN subject_type VARCHAR(30) DEFAULT 'Theory'");
    }
    if(!column_exists_import($conn, 'divisions', 'program_level')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN program_level VARCHAR(20) DEFAULT 'UG'");
    }
    if(!column_exists_import($conn, 'divisions', 'department')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN department VARCHAR(50) DEFAULT 'CSE'");
    }
    if(!column_exists_import($conn, 'divisions', 'degree_type')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN degree_type VARCHAR(50) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'year_name')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN year_name VARCHAR(20) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'specialization')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN specialization VARCHAR(100) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'class_teacher')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN class_teacher VARCHAR(255) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'class_teacher_email')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN class_teacher_email VARCHAR(255) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'class_teacher_contact')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN class_teacher_contact VARCHAR(50) NULL");
    }
    if(!column_exists_import($conn, 'divisions', 'wef_date')){
        @$conn->query("ALTER TABLE divisions ADD COLUMN wef_date VARCHAR(100) NULL");
    }
}

safe_alter_import($conn);

function col_to_num($letters){
    $letters = strtoupper($letters);
    $num = 0;
    for($i=0; $i<strlen($letters); $i++){
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
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
        if(isset($si->t)){
            $text = (string)$si->t;
        } else {
            foreach($si->r as $r){
                $text .= (string)$r->t;
            }
        }
        $strings[] = $text;
    }
    return $strings;
}

function xlsx_sheet_list($zip){
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if($workbookXml === false || $relsXml === false) return [];

    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if(!$workbook || !$rels) return [];

    $ridToTarget = [];
    foreach($rels->Relationship as $rel){
        $attrs = $rel->attributes();
        $ridToTarget[(string)$attrs['Id']] = (string)$attrs['Target'];
    }

    $sheets = [];
    foreach($workbook->sheets->sheet as $sheet){
        $attrs = $sheet->attributes();
        $name = (string)$attrs['name'];
        $rattrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)$rattrs['id'];
        if($rid === '' && isset($attrs['id'])) $rid = (string)$attrs['id'];
        $target = $ridToTarget[$rid] ?? '';
        if($name === '' || $target === '') continue;
        $path = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . ltrim($target, '/');
        if(strpos($path, 'xl/worksheets/') === false && strpos($target, 'worksheets/') === 0){
            $path = 'xl/' . $target;
        }
        $sheets[] = ['name'=>$name, 'path'=>$path];
    }
    return $sheets;
}

function xlsx_read_sheet($zip, $path, $shared){
    $xml = $zip->getFromName($path);
    if($xml === false) return [];
    $sx = @simplexml_load_string($xml);
    if(!$sx) return [];

    $cells = [];
    foreach($sx->sheetData->row as $row){
        foreach($row->c as $c){
            $attrs = $c->attributes();
            $ref = (string)$attrs['r'];
            if($ref === '') continue;
            [$r,$col] = cell_ref_parts($ref);
            $type = (string)$attrs['t'];
            $val = '';
            if($type === 's'){
                $idx = intval((string)$c->v);
                $val = $shared[$idx] ?? '';
            } elseif($type === 'inlineStr'){
                if(isset($c->is->t)) $val = (string)$c->is->t;
                else {
                    foreach($c->is->r as $rr) $val .= (string)$rr->t;
                }
            } else {
                $val = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$r][$col] = trim(str_replace(["\r\n", "\r"], "\n", $val));
        }
    }
    return $cells;
}

function cellv($cells, $r, $c){
    return trim((string)($cells[$r][$c] ?? ''));
}

function normalize_division($text, $sheetName=''){
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);
    if(preg_match('/Class\s*:\s*([A-Z]+)\s*[- ]?\s*([0-9]+)/i', $text, $m)){
        return strtoupper($m[1]) . intval($m[2]);
    }
    if(preg_match('/\b(FY|SY|TY|LY)\s*[- ]?\s*([0-9]+)\b/i', $text, $m)){
        return strtoupper($m[1]) . intval($m[2]);
    }
    if(preg_match('/\b(FY|SY|TY|LY)\s*[- ]?\s*([0-9]+)\b/i', $sheetName, $m)){
        return strtoupper($m[1]) . intval($m[2]);
    }
    return '';
}

function extract_after_colon($text){
    $pos = strpos($text, ':');
    if($pos === false) return trim($text);
    return trim(substr($text, $pos+1));
}

function parse_year_sem($text){
    $year = '';
    $sem = '';
    if(preg_match('/(20[0-9]{2}\s*-\s*[0-9]{2})/', $text, $m)) $year = str_replace(' ', '', $m[1]);
    if(preg_match('/Sem\s*[-:]?\s*([IVX]+|I{1,3}|[0-9]+)/i', $text, $m)){
        $sem = 'Sem-' . strtoupper($m[1]);
    }
    return [$year, $sem];
}

function parse_teacher_line($text){
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $text = preg_replace('/Name\s+of\s+the\s+Class\s+Teacher\s*:/i', '', $text);
    $email = '';
    $phone = '';
    if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)){
        $email = $m[0];
        $text = str_replace($email, '', $text);
    }
    if(preg_match('/\b[6-9][0-9]{9}\b/', $text, $m)){
        $phone = $m[0];
        $text = str_replace($phone, '', $text);
    }
    $name = trim($text);
    return [$name, $email, $phone];
}

function infer_year_name($division){
    if(preg_match('/^(FY|SY|TY|LY)/i', $division, $m)) return strtoupper($m[1]);
    return '';
}

function get_id_by_code($conn, $table, $codeCol, $code){
    if($code === '') return null;
    $sql = "SELECT id FROM `$table` WHERE `$codeCol`=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r['id'] ?? null;
}

function ensure_division_import($conn, $division, $academicYear, $semester, $teacherName, $teacherEmail, $teacherPhone, $wef){
    if($division === '') return null;
    $id = get_id_by_code($conn, 'divisions', 'division_name', $division);
    $program = 'UG';
    $dept = 'CSE';
    $degree = '';
    $yearName = infer_year_name($division);
    if($id){
        $stmt = $conn->prepare("UPDATE divisions SET program_level=?, department=?, degree_type=?, year_name=?, class_teacher=?, class_teacher_email=?, class_teacher_contact=?, wef_date=? WHERE id=?");
        $stmt->bind_param('ssssssssi', $program, $dept, $degree, $yearName, $teacherName, $teacherEmail, $teacherPhone, $wef, $id);
        $stmt->execute();
        return $id;
    }
    $stmt = $conn->prepare("INSERT INTO divisions(division_name, program_level, department, degree_type, year_name, class_teacher, class_teacher_email, class_teacher_contact, wef_date) VALUES(?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssss', $division, $program, $dept, $degree, $yearName, $teacherName, $teacherEmail, $teacherPhone, $wef);
    $stmt->execute();
    return $conn->insert_id;
}

function ensure_subject_import($conn, $code, $name, $type='Theory'){
    $code = trim($code);
    if($code === '') return null;
    $name = trim($name) ?: $code;
    $id = get_id_by_code($conn, 'subjects', 'subject_code', $code);
    if($id){
        /*
           Auto-footer system:
           The timetable template now contains only cell entries.
           Do NOT overwrite subject full names from Subject Master when upload has only subject code.
        */
        if(strtoupper($name) === strtoupper($code)){
            $stmt = $conn->prepare("UPDATE subjects SET subject_type=? WHERE id=?");
            $stmt->bind_param('si', $type, $id);
        } else {
            $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, subject_type=? WHERE id=?");
            $stmt->bind_param('ssi', $name, $type, $id);
        }
        $stmt->execute();
        return $id;
    }
    $stmt = $conn->prepare("INSERT INTO subjects(subject_code, subject_name, subject_type) VALUES(?,?,?)");
    $stmt->bind_param('sss', $code, $name, $type);
    $stmt->execute();
    return $conn->insert_id;
}

function ensure_faculty_import($conn, $code, $name=''){
    $code = trim($code);
    if($code === '') return null;
    $name = trim($name) ?: $code;
    $id = get_id_by_code($conn, 'faculties', 'faculty_code', $code);
    if($id){
        if($name !== $code){
            $stmt = $conn->prepare("UPDATE faculties SET faculty_name=? WHERE id=?");
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
        }
        return $id;
    }
    $stmt = $conn->prepare("INSERT INTO faculties(faculty_code, faculty_name) VALUES(?,?)");
    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    return $conn->insert_id;
}

function ensure_classroom_import($conn, $room){
    $room = trim($room);
    if($room === '' || strtoupper($room) === 'ONLINE') return null;
    $id = get_id_by_code($conn, 'classrooms', 'room_code', $room);
    if($id) return $id;
    $type = 'Classroom';
    $stmt = $conn->prepare("INSERT INTO classrooms(room_code, room_type) VALUES(?,?)");
    $stmt->bind_param('ss', $room, $type);
    $stmt->execute();
    return $conn->insert_id;
}

function subject_type_from_code_import($code){
    $u = strtoupper($code);
    if(strpos($u, 'PBL') !== false) return 'Mini Project';
    if(strpos($u, 'MPP') !== false || strpos($u, 'MAJOR') !== false) return 'Major Project';
    if(preg_match('/(L$|DSL|DMSL|PAIL|EEL|PLL|PAL|LAB|MCAL|ADAL|DSSL|AIMLL|MLL|IDSL|DMAL|CISL|CFL|CFDL|DCL|CDEL|ADL|DEL|BDTL|IOTAL|AWTL|WTL|HPCL|BDAL|ABDAL|DLNNL|BITL|EDAL|VAPTL|SOSL|BTL|DAPPDL|DLECL|ITML|FSDL|JP|PP|APP)/', $u)) return 'Practical';
    if(preg_match('/(LIBRARY|MOOC|MENTOR|EXPERT|REMEDIAL|SCIL|NPTEL)/', $u)) return 'Other';
    return 'Theory';
}

function parse_cell_entries($text){
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if($text === '') return [];
    if(preg_match('/SHORT\s+BREAK|LUNCH\s+BREAK/i', $text)) return [];

    $lines = preg_split('/\n+/', $text);
    $out = [];
    foreach($lines as $line){
        $line = trim($line);
        if($line === '') continue;
        $parts = array_map('trim', explode(':', $line));
        $parts = array_values(array_filter($parts, function($x){ return $x !== ''; }));
        if(count($parts) >= 4){
            $out[] = ['batch'=>$parts[0], 'subject'=>$parts[1], 'faculty'=>$parts[2], 'room'=>$parts[3]];
        } elseif(count($parts) == 3){
            $out[] = ['batch'=>null, 'subject'=>$parts[0], 'faculty'=>$parts[1], 'room'=>$parts[2]];
        } elseif(count($parts) == 2){
            $out[] = ['batch'=>null, 'subject'=>$parts[0], 'faculty'=>$parts[1], 'room'=>''];
        } elseif(count($parts) == 1){
            $out[] = ['batch'=>null, 'subject'=>$parts[0], 'faculty'=>'', 'room'=>''];
        }
    }
    return $out;
}

function extract_mappings($cells){
    /*
       Footer, subject legend, faculty legend and signatories are no longer entered
       in the timetable upload template.

       The portal auto-generates footer using:
       - Subject Master for subject full names
       - Faculty Master for faculty full names
       - Signatories Info for Prepared/Checked/Recommended/Approved By
       - Division master for class teacher details
    */
    return [[], []];
}

function detect_sheet_info($cells, $sheetName){
    $row3 = trim(implode(' ', [cellv($cells,3,1), cellv($cells,3,2), cellv($cells,3,3), cellv($cells,3,4), cellv($cells,3,5), cellv($cells,3,6), cellv($cells,3,7), cellv($cells,3,8), cellv($cells,3,9), cellv($cells,3,10), cellv($cells,3,11)]));
    $row4 = trim(implode(' ', [cellv($cells,4,1), cellv($cells,4,2), cellv($cells,4,3), cellv($cells,4,4), cellv($cells,4,5), cellv($cells,4,6), cellv($cells,4,7), cellv($cells,4,8), cellv($cells,4,9), cellv($cells,4,10), cellv($cells,4,11)]));

    $division = normalize_division($row3, $sheetName);
    [$academicYear, $semester] = parse_year_sem($row3);
    $wef = '';
    if(preg_match('/W\.E\.F\.\s*(.+)$/i', $row3, $m)) $wef = trim($m[1]);
    if($wef === '') $wef = '1st August 2026';
    [$teacherName, $teacherEmail, $teacherPhone] = parse_teacher_line($row4);

    return [$division, $academicYear, $semester, $wef, $teacherName, $teacherEmail, $teacherPhone];
}

function import_sheet_to_db($conn, $cells, $sheetName, $replace=true){
    [$division, $academicYear, $semester, $wef, $teacherName, $teacherEmail, $teacherPhone] = detect_sheet_info($cells, $sheetName);

    if($division === '' || stripos($division, 'YEAR') !== false){
        return ['status'=>'skip', 'reason'=>'No valid division found'];
    }
    if($academicYear === '') $academicYear = '2026-27';
    if($semester === '') $semester = 'Sem-I';

    [$subjectMap, $facultyMap] = extract_mappings($cells);

    $divisionId = ensure_division_import($conn, $division, $academicYear, $semester, $teacherName, $teacherEmail, $teacherPhone, $wef);

    if($replace){
        $stmt = $conn->prepare("DELETE FROM timetable_entries WHERE division_id=? AND academic_year=? AND semester=?");
        $stmt->bind_param('iss', $divisionId, $academicYear, $semester);
        $stmt->execute();
    }

    $dayRows = [6=>'Monday', 7=>'Tuesday', 8=>'Wednesday', 9=>'Thursday', 10=>'Friday', 11=>'Saturday'];
    $slotCols = [
        2=>'08:45-09:40',
        3=>'09:40-10:35',
        5=>'10:50-11:45',
        6=>'11:45-12:40',
        8=>'01:40-02:35',
        9=>'02:35-03:30',
        11=>'03:40-04:30'
    ];

    $inserted = 0;
    $skipped = 0;

    foreach($dayRows as $r=>$day){
        foreach($slotCols as $c=>$slot){
            $txt = cellv($cells, $r, $c);
            $entries = parse_cell_entries($txt);
            foreach($entries as $en){
                $subCode = trim($en['subject']);
                if($subCode === '') { $skipped++; continue; }
                $subjectName = $subjectMap[$subCode] ?? $subCode;
                $subjectId = ensure_subject_import($conn, $subCode, $subjectName, subject_type_from_code_import($subCode));
                $facultyId = null;
                if(trim($en['faculty']) !== ''){
                    $facultyId = ensure_faculty_import($conn, trim($en['faculty']), $facultyMap[trim($en['faculty'])] ?? trim($en['faculty']));
                }
                $classroomId = null;
                if(trim($en['room']) !== ''){
                    $classroomId = ensure_classroom_import($conn, trim($en['room']));
                }
                $batch = $en['batch'];
                $stmt = $conn->prepare("INSERT INTO timetable_entries(division_id, day_name, time_slot, subject_id, faculty_id, classroom_id, batch, academic_year, semester) VALUES(?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issiissss', $divisionId, $day, $slot, $subjectId, $facultyId, $classroomId, $batch, $academicYear, $semester);
                if($stmt->execute()) $inserted++; else $skipped++;
            }
        }
    }

    return ['status'=>'ok', 'division'=>$division, 'inserted'=>$inserted, 'skipped'=>$skipped, 'year'=>$academicYear, 'semester'=>$semester];
}

$message = '';
$details = [];

if(isset($_GET['download'])){
    $template = __DIR__ . '/MIT_Timetable_Template.xlsx';
    if(!file_exists($template)) die('Template file not found. Upload MIT_Timetable_Template.xlsx into htdocs.');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="MIT_Timetable_Template.xlsx"');
    header('Content-Length: ' . filesize($template));
    readfile($template);
    exit;
}

if(isset($_POST['upload_xlsx'])){
    if(!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== 0){
        $message = 'Please upload a valid .xlsx file.';
    } elseif(!class_exists('ZipArchive')){
        $message = 'Server error: ZipArchive is not enabled, so XLSX cannot be read.';
    } else {
        $replace = (($_POST['mode'] ?? 'replace') === 'replace');
        $zip = new ZipArchive;
        $open = $zip->open($_FILES['xlsx_file']['tmp_name']);
        if($open !== TRUE){
            $message = 'Could not open XLSX file. Please upload a real .xlsx workbook.';
        } else {
            $shared = xlsx_shared_strings($zip);
            $sheets = xlsx_sheet_list($zip);
            $totalInserted = 0;
            $totalSkipped = 0;
            $processedSheets = 0;
            foreach($sheets as $sheet){
                $name = $sheet['name'];
                if(preg_match('/teacher|instruction/i', $name)) continue;
                $cells = xlsx_read_sheet($zip, $sheet['path'], $shared);
                $result = import_sheet_to_db($conn, $cells, $name, $replace);
                if($result['status'] === 'ok'){
                    $processedSheets++;
                    $totalInserted += $result['inserted'];
                    $totalSkipped += $result['skipped'];
                    $details[] = $result;
                }
            }
            $zip->close();
            $message = "Import completed. Sheets processed: $processedSheets | Entries inserted: $totalInserted | Skipped: $totalSkipped";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Timetable Template Upload</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f3f8ff;padding:28px;color:#172033}.card{max-width:900px;margin:auto;background:#fff;border-radius:18px;padding:25px;box-shadow:0 10px 28px rgba(0,0,0,.12)}h1{margin-top:0;color:#1e3a8a}.btn{display:inline-block;background:#2563eb;color:#fff;padding:11px 18px;border-radius:10px;text-decoration:none;border:none;font-weight:800;cursor:pointer}.green{background:#16a34a}.note{background:#eff6ff;border-left:5px solid #2563eb;padding:14px;border-radius:10px;margin:15px 0}.msg{background:#dcfce7;border-left:5px solid #16a34a;padding:14px;border-radius:10px;margin:15px 0;font-weight:800}input,select{padding:10px;border:1px solid #bfdbfe;border-radius:8px;margin:8px 0}.small{font-size:13px;color:#475569}table{width:100%;border-collapse:collapse;margin-top:15px}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left}th{background:#eff6ff}
</style>
</head>
<body>
<div class="card">
<h1>Admin Timetable Template Upload</h1>

<?php if($message){ ?><div class="msg"><?= e($message) ?></div><?php } ?>

<p><a class="btn" href="import_timetable.php?download=1">Download Official Timetable Template</a></p>

<div class="note">
<b>Fill only timetable cells.</b><br>
Theory: <b>DSA : MMK : N507</b><br>
Practical: <b>A : DSL : MMK : S514</b><br>
Multiple batches: put each batch on a new line in the same cell.<br><br>
<b>No footer required in Excel.</b> Subject legend, faculty legend and signatories will be generated automatically by the portal from Subject Master, Faculty Master and Signatories Info.<br>
One workbook may contain many sheets. One sheet = one division timetable.
</div>

<form method="POST" enctype="multipart/form-data">
<label><b>Upload filled .xlsx workbook:</b></label><br>
<input type="file" name="xlsx_file" accept=".xlsx" required><br>

<button class="btn green" name="upload_xlsx">Upload & Import</button>
</form>

<?php if(!empty($details)){ ?>
<table>
<tr><th>Division</th><th>Academic Year</th><th>Semester</th><th>Inserted</th><th>Skipped</th></tr>
<?php foreach($details as $d){ ?>
<tr><td><?= e($d['division']) ?></td><td><?= e($d['year']) ?></td><td><?= e($d['semester']) ?></td><td><?= e($d['inserted']) ?></td><td><?= e($d['skipped']) ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<p class="small"><a href="index.php?view=manage">Back to Admin Panel</a></p>
</div>
</body>
</html>
