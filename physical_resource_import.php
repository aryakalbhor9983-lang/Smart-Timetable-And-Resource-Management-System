<?php
session_start();
$conn = new mysqli(
    "sql209.infinityfree.com",
    "if0_42102472",
    "qfHgzrTdk9BM",
    "if0_42102472_university_timetable"
);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);
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
function yn($v){
    $v = strtoupper(trim((string)$v));
    return in_array($v, ['Y','YES','1','TRUE','AVAILABLE']) ? 'Y' : 'N';
}
function numv($v){
    $v = trim((string)$v);
    if($v === '') return null;
    return is_numeric($v) ? $v : null;
}
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
function sheet_list($zip){
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
    $out = [];
    foreach($workbook->sheets->sheet as $sheet){
        $a = $sheet->attributes();
        $name = (string)$a['name'];
        $rattrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)$rattrs['id'];
        $target = $ridToTarget[$rid] ?? '';
        if($name && $target) $out[] = ['name'=>$name, 'path'=>(strpos($target,'xl/')===0?$target:'xl/'.ltrim($target,'/'))];
    }
    return $out;
}
function read_sheet($zip, $path, $shared){
    $xml = $zip->getFromName($path);
    if($xml === false) return [];
    $sx = @simplexml_load_string($xml);
    if(!$sx) return [];
    $cells = [];
    foreach($sx->sheetData->row as $row){
        foreach($row->c as $c){
            $a = $c->attributes(); $ref = (string)$a['r']; [$r,$col] = cell_ref_parts($ref);
            $type = (string)$a['t']; $val = '';
            if($type === 's'){ $idx = intval((string)$c->v); $val = $shared[$idx] ?? ''; }
            elseif($type === 'inlineStr'){ if(isset($c->is->t)) $val = (string)$c->is->t; else foreach($c->is->r as $rr) $val .= (string)$rr->t; }
            else $val = isset($c->v) ? (string)$c->v : '';
            $cells[$r][$col] = trim(str_replace(["\r\n","\r"], "\n", $val));
        }
    }
    return $cells;
}
function cv($cells,$r,$c){ return trim((string)($cells[$r][$c] ?? '')); }
function ensure_classroom_room($conn, $room, $type='CLASSROOM'){
    if($room==='') return;
    $stmt=$conn->prepare("SELECT id FROM classrooms WHERE room_code=? LIMIT 1");
    $stmt->bind_param("s",$room); $stmt->execute();
    $ex=$stmt->get_result()->fetch_assoc();
    if($ex) return;
    $stmt=$conn->prepare("INSERT INTO classrooms(room_code, resource_type) VALUES(?,?)");
    $stmt->bind_param("ss",$room,$type); $stmt->execute();
}

/* ===================== DB UPGRADES FOR UPDATED PHYSICAL RESOURCE TEMPLATE ===================== */
ensure_column($conn, 'faculty_block_details', 'faculty_block_type', "VARCHAR(120) NULL");
ensure_column($conn, 'faculty_block_details', 'incharge', "VARCHAR(150) NULL");

/* Keep older columns as-is for compatibility: assigned_to, cabin_numbers, capacity, block_name, floor_no, wifi_available, area_sq_meter */

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

$msg = "";

if(isset($_POST['upload_resources'])){
    if(!isset($_FILES['resource_file']) || $_FILES['resource_file']['error'] !== 0){
        $msg = "Please upload Physical_Resources_Master_Template.xlsx.";
    } elseif(!class_exists('ZipArchive')){
        $msg = "Server error: ZipArchive is not enabled.";
    } else {
        $zip = new ZipArchive;
        if($zip->open($_FILES['resource_file']['tmp_name']) !== TRUE){
            $msg = "Could not open XLSX file.";
        } else {
            $shared = xlsx_shared_strings($zip);
            $sheets = sheet_list($zip);
            $count = 0;

            foreach($sheets as $sheet){
                $name = strtolower(trim($sheet['name']));
                $cells = read_sheet($zip, $sheet['path'], $shared);

                if(strpos($name,'classroom') !== false){
                    for($r=2;$r<=300;$r++){
                        $room=strtoupper(cv($cells,$r,1)); if($room==='') continue;
                        $incharge=cv($cells,$r,2); $capacity=numv(cv($cells,$r,3)); $benches=numv(cv($cells,$r,4));
                        $smart=yn(cv($cells,$r,5)); $lcd=yn(cv($cells,$r,6)); $wifi=yn(cv($cells,$r,7));
                        $block=cv($cells,$r,8); $floor=cv($cells,$r,9); $area=numv(cv($cells,$r,10));
                        ensure_classroom_room($conn,$room,'CLASSROOM');
                        $stmt=$conn->prepare("UPDATE classrooms SET resource_type='CLASSROOM', classroom_incharge=?, capacity=?, no_of_benches=?, smart_board=?, lcd_projector=?, wifi_available=?, block_name=?, floor_no=?, area_sq_meter=? WHERE room_code=?");
                        $stmt->bind_param("siisssssds",$incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area,$room);
                        $stmt->execute(); $count++;
                    }
                }

                if(strpos($name,'lab') !== false){
                    for($r=2;$r<=300;$r++){
                        $room=strtoupper(cv($cells,$r,1)); if($room==='') continue;
                        $labName=cv($cells,$r,2); $incharge=cv($cells,$r,3); $assistant=cv($cells,$r,4);
                        $capacity=numv(cv($cells,$r,5)); $pcs=numv(cv($cells,$r,6));
                        $block=cv($cells,$r,7); $floor=cv($cells,$r,8); $area=numv(cv($cells,$r,9));
                        ensure_classroom_room($conn,$room,'LAB');
                        $stmt=$conn->prepare("INSERT INTO lab_details(room_code,lab_name,lab_incharge,lab_assistant,lab_capacity,no_of_pcs,block_name,floor_no,area_sq_meter)
                            VALUES(?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE lab_name=VALUES(lab_name), lab_incharge=VALUES(lab_incharge), lab_assistant=VALUES(lab_assistant), lab_capacity=VALUES(lab_capacity), no_of_pcs=VALUES(no_of_pcs), block_name=VALUES(block_name), floor_no=VALUES(floor_no), area_sq_meter=VALUES(area_sq_meter)");
                        $stmt->bind_param("ssssiissd",$room,$labName,$incharge,$assistant,$capacity,$pcs,$block,$floor,$area);
                        $stmt->execute(); $count++;
                    }
                }

                if(strpos($name,'tutorial') !== false){
                    for($r=2;$r<=300;$r++){
                        $room=strtoupper(cv($cells,$r,1)); if($room==='') continue;
                        $incharge=cv($cells,$r,2); $capacity=numv(cv($cells,$r,3)); $benches=numv(cv($cells,$r,4));
                        $smart=yn(cv($cells,$r,5)); $lcd=yn(cv($cells,$r,6)); $wifi=yn(cv($cells,$r,7));
                        $block=cv($cells,$r,8); $floor=cv($cells,$r,9); $area=numv(cv($cells,$r,10));
                        ensure_classroom_room($conn,$room,'TUTORIAL');
                        $stmt=$conn->prepare("INSERT INTO tutorial_room_details(room_code,tutorial_incharge,capacity,no_of_benches,smart_board,lcd_projector,wifi_available,block_name,floor_no,area_sq_meter)
                            VALUES(?,?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE tutorial_incharge=VALUES(tutorial_incharge), capacity=VALUES(capacity), no_of_benches=VALUES(no_of_benches), smart_board=VALUES(smart_board), lcd_projector=VALUES(lcd_projector), wifi_available=VALUES(wifi_available), block_name=VALUES(block_name), floor_no=VALUES(floor_no), area_sq_meter=VALUES(area_sq_meter)");
                        $stmt->bind_param("ssiisssssd",$room,$incharge,$capacity,$benches,$smart,$lcd,$wifi,$block,$floor,$area);
                        $stmt->execute(); $count++;
                    }
                }

                if(strpos($name,'faculty') !== false){
                    for($r=2;$r<=300;$r++){
                        // Faculty_Blocks columns:
                        // A Sr No, B Faculty Block Type, C Department, D Room No, E Incharge,
                        // F Cabin Nos, G Block, H Floor No, I WiFi, J Area Sq. Meter
                        $facultyBlockType = cv($cells,$r,2);
                        $department = cv($cells,$r,3);
                        $room = strtoupper(cv($cells,$r,4));
                        if($room==='') continue;
                        $incharge = cv($cells,$r,5);
                        $cabins = cv($cells,$r,6);
                        $capacity = numv($cabins);
                        $block = cv($cells,$r,7);
                        $floor = cv($cells,$r,8);
                        $wifi = yn(cv($cells,$r,9));
                        $area = numv(cv($cells,$r,10));

                        ensure_classroom_room($conn,$room,'FACULTY_BLOCK');

                        $stmt=$conn->prepare("INSERT INTO faculty_block_details
                            (room_code, faculty_block_type, assigned_to, incharge, cabin_numbers, capacity, block_name, floor_no, wifi_available, area_sq_meter)
                            VALUES(?,?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                faculty_block_type=VALUES(faculty_block_type),
                                assigned_to=VALUES(assigned_to),
                                incharge=VALUES(incharge),
                                cabin_numbers=VALUES(cabin_numbers),
                                capacity=VALUES(capacity),
                                block_name=VALUES(block_name),
                                floor_no=VALUES(floor_no),
                                wifi_available=VALUES(wifi_available),
                                area_sq_meter=VALUES(area_sq_meter)");
                        $stmt->bind_param("ssssssissd",$room,$facultyBlockType,$department,$incharge,$cabins,$capacity,$block,$floor,$wifi,$area);
                        $stmt->execute(); $count++;
                    }
                }


                if(strpos($name,'admin') !== false){
                    for($r=2;$r<=300;$r++){
                        // Admin_Blocks columns:
                        // A Sr No, B Admin Block Name, C Location, D Incharge,
                        // E Block, F Floor No, G WiFi, H Area Sq. Meter
                        $adminBlockName = cv($cells,$r,2);
                        $location = strtoupper(cv($cells,$r,3));
                        if($location==='') continue;
                        $incharge = cv($cells,$r,4);
                        $block = cv($cells,$r,5);
                        $floor = cv($cells,$r,6);
                        $wifi = yn(cv($cells,$r,7));
                        $area = numv(cv($cells,$r,8));

                        ensure_classroom_room($conn,$location,'ADMIN_BLOCK');

                        $stmt=$conn->prepare("INSERT INTO admin_block_details
                            (admin_block_name, location, incharge, block_name, floor_no, wifi_available, area_sq_meter)
                            VALUES(?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE
                                admin_block_name=VALUES(admin_block_name),
                                incharge=VALUES(incharge),
                                block_name=VALUES(block_name),
                                floor_no=VALUES(floor_no),
                                wifi_available=VALUES(wifi_available),
                                area_sq_meter=VALUES(area_sq_meter)");
                        $stmt->bind_param("ssssssd",$adminBlockName,$location,$incharge,$block,$floor,$wifi,$area);
                        $stmt->execute(); $count++;
                    }
                }

                if(strpos($name,'seminar') !== false){
                    for($r=2;$r<=300;$r++){
                        $room=strtoupper(cv($cells,$r,1)); if($room==='') continue;
                        $hall=cv($cells,$r,2); $capacity=numv(cv($cells,$r,3)); $smart=yn(cv($cells,$r,4)); $lcd=yn(cv($cells,$r,5)); $wifi=yn(cv($cells,$r,6));
                        $block=cv($cells,$r,7); $floor=cv($cells,$r,8); $area=numv(cv($cells,$r,9));
                        ensure_classroom_room($conn,$room,'SEMINAR_HALL');
                        $stmt=$conn->prepare("INSERT INTO seminar_hall_details(room_code,seminar_hall_name,capacity,smart_board,lcd_projector,wifi_available,block_name,floor_no,area_sq_meter)
                            VALUES(?,?,?,?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE seminar_hall_name=VALUES(seminar_hall_name), capacity=VALUES(capacity), smart_board=VALUES(smart_board), lcd_projector=VALUES(lcd_projector), wifi_available=VALUES(wifi_available), block_name=VALUES(block_name), floor_no=VALUES(floor_no), area_sq_meter=VALUES(area_sq_meter)");
                        $stmt->bind_param("ssisssssd",$room,$hall,$capacity,$smart,$lcd,$wifi,$block,$floor,$area);
                        $stmt->execute(); $count++;
                    }
                }
            }
            $zip->close();
            $msg = "Physical resources import completed. Records processed: ".$count;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Physical Resources Import</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#f5f0fa;padding:28px;color:#1a0533}
.card{max-width:850px;margin:auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 8px 25px rgba(90,31,140,.15)}
h1{margin-top:0;color:#3d0f6b}
.btn,button{display:inline-block;background:#7b2fb5;color:#fff;padding:10px 16px;border-radius:9px;text-decoration:none;border:none;font-weight:800;cursor:pointer}
button{background:#C1345C}
input{padding:10px;border:1px solid #c9a0e0;border-radius:8px}
.msg{background:#f0e8fa;border-left:5px solid #7b2fb5;padding:12px;border-radius:8px;margin:12px 0;font-weight:700}
.note{background:#f9f4ff;border:1px solid #e0c8f0;padding:12px;border-radius:10px;margin:12px 0}
</style>
</head>
<body>
<div class="card">
<h1>Physical Resources Bulk Import</h1>
<?php if($msg){ ?><div class="msg"><?= e($msg) ?></div><?php } ?>
<p><a class="btn" href="Physical_Resources_Master_Template.xlsx" download>Download Physical Resources Template</a></p>
<div class="note">
Workbook sheets supported: Classrooms, Labs, Tutorial Rooms, Faculty Blocks, Admin Blocks, Seminar Halls.
</div>
<form method="POST" enctype="multipart/form-data">
<input type="file" name="resource_file" accept=".xlsx" required>
<button name="upload_resources">Upload & Import</button>
</form>
<p><a href="index.php?view=manage">Back to Common Info</a></p>
</div>
</body>
</html>
