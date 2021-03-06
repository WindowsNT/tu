<?php

define('TU_DATABASE', "tu.db");
define('TU_ADMIN_USERNAME','root');
define('TU_ADMIN_PASSWORD','muhahah');

if (session_status() == PHP_SESSION_NONE) { session_start();}
set_time_limit(120);

function subfile($fil,$from,$le)
{
	$fp = fopen($fil, "rb");
	if (!$fp)
		return "";
	fseek($fp,$from,SEEK_SET);

//	printf("$from $to $le\r\n");

	$zn = tempnam(sys_get_temp_dir(),"partblow");
	$fo = fopen($zn,"wb");
	if (!$fo)
		return "";

	$fx = 0;
	for(;;)
	{
		if ($fx == $le)
			break;
		$rem = $le - $fx;
		if ($rem >= 1024*1024)
			$rem = 1024*1024;
		if ($rem == 0)
			break;
		$d = fread($fp,$rem);
		fwrite($fo,$d);
		$fx += strlen($d);
	}

	fclose($fp);
	fclose($fo);
	return $zn;
}

//ini_set('display_errors', 1); error_reporting(E_ALL);
class TU
{
	public $db = null;
	public $lastRowID = 0;

	public function guidv4()
	{
		if (function_exists('com_create_guid') === true)
			return trim(com_create_guid(), '{}');

		$data = openssl_random_pseudo_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}


	public function cmpr($x,$comp = 0)
	{
		if ($comp == 0)
			return $x;
//		return gzcompress($x);
		return gzencode($x);
	}
	public function uncmpr($x,$comp = 0)
	{
		if ($comp == 0)
			return $x;
		//return gzuncompress($x);
		return gzdecode($x);
	}


	public function gzUncompressFile($source,$dest){ 
		$error = false; 
		if ($fp_out = fopen($dest, "wb")) 
		{
			if ($fp_in = gzopen($source,'rb')) 
			{ 
				for(;;)
				{
					$ax = gzread($fp_in, 1024 * 512);
					if (strlen($ax) === 0)
						break;
					fwrite($fp_out, $ax); 
				}
				gzclose($fp_in); 
			} 
			else 
			{
				$error = true; 
			}
			fclose($fp_out); 
		} 
		else 
		{
			$error = true; 
		}

		if ($error)
			return false; 
		else
			return $dest; 
	} 

	public function BlobRowToTemp($e,$raw = 0)
	{
		$split = $this->Config("SPLIT","0");
		if ($split == 1)
		{
			$blob = $e['CLSID'];
			if ($raw == 0)
			{
				if ($e['COMPRESSED'] == 1)
				{
					$zn = tempnam(sys_get_temp_dir(),"blobrow");
					$this->gzUncompressFile($blob,$zn);
					$blob = $zn;
				}
			}
			return $blob;
		}
	}

	function BlobRow($e,$raw = 0)
	{
		$split = $this->Config("SPLIT","0");
		if ($split == 1)
		{
			$blob = file_get_contents($e['CLSID']);
			if ($raw == 0)
				$blob = $this->uncmpr($blob,$e['COMPRESSED']);
			return $blob;
		}

		$stream = $this->db->openBlob('TU', 'FILEX', $e['ID']);
		$blob = stream_get_contents($stream);
		fclose($stream);
		if ($raw == 0)
			$blob = $this->uncmpr($blob,$e['COMPRESSED']);
		return $blob;
	}

	public function Query($q,$arr = array())
	{
		global $lastRowID;
		global $laste;

		$stmt = $this->db->prepare($q);
		if (!$stmt)
			return null;
		$i = 1;
		foreach($arr as $a)
		{
			$stmt->bindValue($i,$a);
			$i++;
		}
		$a = $stmt->execute();

		$this->lastRowID = $this->db->lastInsertRowID();
		return $a;
	}

	public function Config($name,$v = "")
	{
		$row = $this->Query("SELECT * FROM CONFIG WHERE NAME = ?",array($name))->fetchArray();
		if (!$row)
			return $v;
		return $row['VALUE'];
	}

	public function SetConfig($name,$v)
	{
		$this->Query("DELETE FROM CONFIG WHERE NAME = ?",array($name));
		$this->Query("INSERT INTO CONFIG (NAME,VALUE) VALUES (?,?)",array($name,$v));
	}

	public function Split($v)
	{
		if ($v == 0)
		{
			// Test run
			$q = $this->Query("SELECT * FROM TU");
			while ($e = $q->fetchArray())
			{
				$r = file_get_contents($e['CLSID']);
				if ($r === FALSE)
					die("Failed to get content for {$e['CLSID']}");
			}

			$q = $this->Query("SELECT * FROM TU");
			while ($e = $q->fetchArray())
			{
				$r = file_get_contents($e['CLSID']);
				if ($r === FALSE)
					die("Failed to get content for {$e['CLSID']}");

				$this->Query("UPDATE TU SET FILEX = ? WHERE CLSID = ?",array($r,$e['CLSID']));
				unlink($e['CLSID']);
			}
			$this->SetConfig("SPLIT","0");
		}
		else
		{
			$q = $this->Query("SELECT * FROM TU");
			while ($e = $q->fetchArray())
			{
				$blob = $this->BlobRow($e,true);
				file_put_contents($e['CLSID'],$blob);
			}

			$this->SetConfig("SPLIT","1");
			$this->Query("UPDATE TU SET FILEX = ''");
			$this->Query("VACUUM");
		}

	}

	public function CreateSQL()
	{
		$this->db = new SQLite3(TU_DATABASE);
		$this->Query("CREATE TABLE IF NOT EXISTS CONFIG (ID INTEGER PRIMARY KEY,NAME TEXT,VALUE TEXT)");
		$this->Query("CREATE TABLE IF NOT EXISTS PROJECT (ID INTEGER PRIMARY KEY,CLSID TEXT,NAME TEXT,PWD TEXT)");
		$this->Query("CREATE TABLE IF NOT EXISTS TU (ID INTEGER PRIMARY KEY,PID INTEGER,CLSID TEXT,SIGNX BLOB,FILEX BLOB,HASH TEXT,NAME TEXT,DOWNLOADS INTEGER,CHECKS INTEGER,UPDATES INTEGER,BW REAL,COMPRESSED INTEGER)");
	}

	function __construct() 
	{
		$this->CreateSQL();
	}
}

$tu = new TU;
$function = "";
$prj = "";


if (array_key_exists('p',$_GET) && array_key_exists('f',$_GET))
{
	// Download this file
	$prjrow = $tu->Query("SELECT * FROM PROJECT WHERE CLSID = ?",array($_GET['p']))->fetchArray();
	if (!$prjrow)
		die;
		
	$frow = $tu->Query("SELECT * FROM TU WHERE CLSID = ? AND PID = ?",array($_GET['f'],$prjrow['ID']))->fetchArray();
	if (!$frow)
		die;

	$bw = $frow['BW'];

	$blob = $tu->BlobRow($frow);

	$bw += (float)(strlen($blob)/(1024*1024));
	$tu->Query("UPDATE TU SET DOWNLOADS = ?,BW = ? WHERE CLSID = ?",array($frow['DOWNLOADS'] + 1,$bw,$frow['CLSID']));


	header("Content-Type: application/octet-stream");
	header(sprintf("Content-Length: %s",strlen($blob)));
	header(sprintf("Content-Disposition: attachment; filename=\"%s\"",$frow['NAME']));
	echo $blob;
	die;
	}

if (array_key_exists('admin',$_GET))
{
	if (array_key_exists('logout',$_GET))
	{
		unset($_SESSION['adminuser']);
		header("Location: tu.php?admin");
		die;
	}
	if (array_key_exists('login',$_GET))
	{
		if ($_GET['u'] == TU_ADMIN_USERNAME)
		{
			if ($_GET['p'] == TU_ADMIN_PASSWORD)
			{
				$_SESSION['adminuser'] = TU_ADMIN_USERNAME;
				header("Location: tu.php?admin");
				die;
			}
		}
		header("Location: tu.php?admin");
		die;
	}

	?>

			<meta charset="utf-8">
			  <meta name="viewport" content="width=device-width, initial-scale=1">
			  <title>Control Panel</title>
		    <script src="https://code.jquery.com/jquery-3.1.1.min.js"></script>
<link href="https://fonts.googleapis.com/css?family=Noto+Serif|Tinos" rel="stylesheet">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.bundle.min.js" integrity="sha384-pjaaA8dDz/5BgdFUPX6M/9SUZv4d12SUPF0axWc+VRZkx5xU3daN+lYb49+Ax+Tl" crossorigin="anonymous"></script>
<?php

	if (array_key_exists('adminuser',$_SESSION) && $_SESSION['adminuser'] == TU_ADMIN_USERNAME)
	{
		if (array_key_exists('split',$_GET))
		{
			$tu->Split(1);
			header("Location: tu.php?admin");
			die;
		}
		if (array_key_exists('join',$_GET))
		{
			$tu->Split(0);
			header("Location: tu.php?admin");
			die;
		}

		if (array_key_exists('vacuum',$_GET))
		{
			$tu->Query("VACUUM");
			header("Location: tu.php?admin");
			die;
		}
		if (array_key_exists('uncompress',$_GET))
		{
			$frow = $tu->Query("SELECT * FROM TU WHERE CLSID = ? AND COMPRESSED = 1",array($_GET['uncompress']))->fetchArray();
			if ($frow)
				{
				$blob = $tu->BlobRow($frow,true);
				$blob = $tu->uncmpr($blob,1);

				$split = $tu->Config("SPLIT","0");
				if ($split == 0)
					$tu->Query("UPDATE TU SET COMPRESSED = 0, FILEX = ? WHERE CLSID = ?",array($blob,$_GET['uncompress']));
				else
					{
					$tu->Query("UPDATE TU SET COMPRESSED = 0 WHERE CLSID = ?",array($_GET['uncompress']));
					file_put_contents($_GET['uncompress'],$blob);
					}
				}

				header("Location: tu.php?admin=1&project={$_GET['project']}");
				die;
		}
		if (array_key_exists('compress',$_GET))
		{
			$frow = $tu->Query("SELECT * FROM TU WHERE CLSID = ? AND COMPRESSED = 0",array($_GET['compress']))->fetchArray();
			if ($frow)
				{
				$blob = $tu->BlobRow($frow,true);
				$blob = $tu->cmpr($blob,1);

				$split = $tu->Config("SPLIT","0");
				if ($split == 0)
					$tu->Query("UPDATE TU SET COMPRESSED = 1, FILEX = ? WHERE CLSID = ?",array($blob,$_GET['compress']));
				else
					{
					$tu->Query("UPDATE TU SET COMPRESSED = 1 WHERE CLSID = ?",array($_GET['compress']));
					file_put_contents($_GET['compress'],$blob);
					}
				}

			header("Location: tu.php?admin=1&project={$_GET['project']}");
			die;
		}
		if (array_key_exists('resetstats',$_GET))
		{
			$prow = $tu->Query("SELECT * FROM PROJECT WHERE ID = ?",array($_GET['resetstats']))->fetchArray();
			if ($prow )
				{
				$tu->Query("UPDATE TU SET DOWNLOADS = 0,CHECKS = 0,UPDATES = 0,BW = 0 WHERE PID = ?",array($_GET['resetstats']))->fetchArray();

				}
			header("Location: tu.php?admin=1&project={$_GET['resetstats']}");
			die;
		}
		if (array_key_exists('createp',$_GET))
		{
//			print_r($_GET); die;
			$g = $tu->guidv4();
			$tu->Query("INSERT INTO PROJECT (CLSID,NAME,PWD) VALUES(?,?,?)",array($g,$_GET['name'],password_hash($_GET['p'],PASSWORD_DEFAULT)));
			header("Location: tu.php?admin");
			die;
		}
		if (array_key_exists('fdel',$_GET))
		{
//			print_r($_GET); die;
			$tu->Query("DELETE FROM TU WHERE CLSID = ?",array($_GET['fdel']));
			unlink($_GET['fdel']);
			$tu->Query("VACUUM");
			header(sprintf("Location: tu.php?admin&project=%s",$_GET['project']));
			die;
		}
		if (array_key_exists('delete',$_GET))
		{
			$tu->Query("DELETE FROM TU WHERE PID = ?",array($_GET['delete']));
			$tu->Query("DELETE FROM PROJECT WHERE ID = ?",array($_GET['delete']));
			$tu->Query("VACUUM");
			header("Location: tu.php?admin");
			die;
		}
		echo '<div style="margin: 20px;"><div id="hdr"><a href="tu.php?admin">Admin Panel Home</a> &mdash; <a href="javascript:np()">Create New Project</a> &mdash; <a href="tu.php?admin=1&logout=1">Logout</a><hr></div>';
		if (!array_key_exists("project",$_GET))
		{
			echo '<div id="lp"><h4>Projects</h4><br><br>';
			printf('<table class="table"><thead><th></th><th>Name</th><th>View</th></thead><tbody>');
			$q = $tu->Query("SELECT * FROM PROJECT");
			while ($r = $q->fetchArray())
			{
				printf('<tr>');
				printf('<td></td>');
				printf('<td>%s<br>',$r['NAME']);
				printf('<td><a href="tu.php?admin=1&project=%s">View</a><br>',$r['ID']);
			}
			printf("</tbody></table>");
			$split = $tu->Config("SPLIT","0");
			if ($split == 0)
				{
				printf('Database size %s KB &mdash; <a href="tu.php?admin=1&vacuum=1">Vacuum</a>',filesize(TU_DATABASE)/1024);
				printf(' &mdash; <a href="tu.php?admin=1&split=1">Split</a>');
				}
			else
				{
				printf('Database size %s KB ',filesize(TU_DATABASE)/1024);
				printf(' &mdash; <a href="tu.php?admin=1&join=1">Join</a>');
				}
			printf("</div>");

		}
		else
		{
			echo '<div id="lp">';
			$r = $tu->Query("SELECT * FROM PROJECT WHERE ID = ?",array($_GET['project']))->fetchArray();
			if (!$r)
				die;

			printf('%s - %s<br><br>',$r['NAME'],$r['CLSID']);


			printf('<table class="table"><thead><th>Name</th><th>Size</th><th>Link</th><th>Compression</th><th>Downloads</th><th>Checks</th><th>Updates</th><th>BW</th></thead><tbody>');
			$split = $tu->Config("SPLIT","0");
			$q2 = $tu->Query("SELECT *,LENGTH(cast(FILEX as blob)) AS LE FROM TU WHERE PID = ?",array($r['ID']));
			while ($r2 = $q2->fetchArray())
			{
				printf("<tr>");
				printf("<td>%s",$r2['NAME']);
//				printf("<br>%s</td>",$r2['CLSID']);
				printf("</td>",$r2['CLSID']);

				if ($split == 1)
					printf("<td>%.1f KB</td>",filesize($r2['CLSID'])/1024);
				else
					printf("<td>%.1f KB</td>",$r2['LE']/1024);
				
				printf('<td><a href="tu.php?p=%s&f=%s">Direct</a> <a href="javascript:AskDeleteI(%s,\'%s\')">Delete</a></td>',$r['CLSID'],$r2['CLSID'],$r['ID'],$r2['CLSID']);
				if ($r2['COMPRESSED'] == 1)
					printf('<td><b>Yes</b> &mdash; <a href="tu.php?admin=1&uncompress=%s&project=%s">No</a></td>',$r2['CLSID'],$r['ID']);
				else
				printf('<td><a href="tu.php?admin=1&compress=%s&project=%s">Yes</a> &mdash; <b>No</b></td>',$r2['CLSID'],$r['ID']);
				printf("<td>%s</td>",(int)$r2['DOWNLOADS']);
				printf("<td>%s</td>",(int)$r2['CHECKS']);
				printf("<td>%s</td>",(int)$r2['UPDATES']);
				printf("<td>%s MB</td>",(int)$r2['BW']);

				printf("</tr>");
//				printf('%s &mdash; <br>',$r2['CLSID'],$r['CLSID'],$r2['CLSID'],$r2['NAME']);
			}
			printf('</tbody></table>');

			printf('<a href="tu.php?admin=1&resetstats=%s">Reset Stats</a>',$r['ID']);
			printf(' &mdash; <a href="javascript:AskDelete(%s)">Delete</a>',$r['ID']);

			printf("</div>");
		}
		?>
		<script>
		function np()
		{
			$("#hdr").hide();
			$("#lp").hide();
			$("#newproj").show();
		}
		function sp()
		{
			$("#hdr").show();
			$("#lp").show();
			$("#newproj").hide();
		}
		function AskDelete(id)
		{
		var r = confirm("Are you sure you want to delete this project?");
		if (r == true) { window.location = 'tu.php?admin=1&delete=' + id;		} 
		else {		}
			// 
		}
		function AskDeleteI(pid,id)
		{
		var r = confirm("Are you sure you want to delete this file?");
		if (r == true) { window.location = 'tu.php?admin=1&fdel=' + id + "&project=" + pid;		} 
		else {		}
			// 
		}
		</script>
		<div id="newproj" style="display:none">
	 <form id="myform2" method="GET" action="tu.php" autocomplete="off">
        <div class="form-group">
            <label for="name">Project Name:</label>
            <input type="text" class="form-control" name="name" autofocus required  autocomplete="off"/>
        </div>
        <div class="form-group">
            <label for="p">Upload Password:</label>
            <input type="password" class="form-control" name="p"  autocomplete="off" required/>
        </div>
            <input type="hidden" name="createp" id="createp" value="1" />
            <input type="hidden" name="admin" id="admin" value="1" />
        <div id="buttondiv"> <span style="opacity: 0.1;"><font color=\"black\" face=\"arial\" size=\"4\">_</font></div>
        <button type="submit" name="submit" id="buttonsubmit" class="btn btn-primary btn-lg" >Create</button> 
		<button type="button" onclick="sp();" class="btn btn-danger btn-lg" >Cancel</button> 
        </form>
		</div>
		<?php
		}
	else
	{
	?>
	<br>
	 <form id="myform" method="GET" action="tu.php" class="col s12">
        <div class="form-group">
            <label for="u">Username:</label>
            <input type="text" class="form-control" name="u" id="u" autofocus required/>
        </div>
        <div class="form-group">
            <label for="p">Password:</label>
            <input type="password" class="form-control" name="p" id="p" required/>
        </div>
            <input type="hidden" name="login" id="login" value="login" />
            <input type="hidden" name="admin" id="admin" value="1" />
        <div id="buttondiv"> <span style="opacity: 0.1;"><font color=\"black\" face=\"arial\" size=\"4\">_</font></div>
        <button type="submit" name="submit" id="buttonsubmit" class="btn btn-primary btn-lg" >Login</button>
        </button></form>
  </div>

   
	<?php
	}
	die;
}

if (array_key_exists('HTTP_X_PROJECT',$_SERVER))
	$prj = $_SERVER['HTTP_X_PROJECT'];
if (array_key_exists('HTTP_X_FUNCTION',$_SERVER))
	$function = $_SERVER['HTTP_X_FUNCTION'];

if ($function == "")
	die;

if ($prj == "")
	die("404 - No project GUID specified");

$prjrow = $tu->Query("SELECT * FROM PROJECT WHERE CLSID = ?",array($prj))->fetchArray();
if (!$prjrow)
	die("404 - No project GUID specified");


if ($function == "upload")
{
	$password = $_SERVER['HTTP_X_PASSWORD'];
	if (!password_verify($password,$prjrow['PWD']))
		die("403 Password");

	$zipdata = file_get_contents('php://input');
	$zn = tempnam(sys_get_temp_dir(),"zipx");
	unlink($zn);
	
	if (!file_put_contents($zn,$zipdata))
		die("500 Cannot create ZIP file");
	$za = new ZipArchive;
	if (!$za->open($zn))
		die("500 Cannot create ZIP file");

	$guids = array();
	for($i = 0 ; ; $i++)
	{
		$ni = $za->getNameIndex($i);
		if (!$ni)
			break;

		if (substr($ni,0,4) == "fils")
		{
			$guid = substr($ni,5);
			$guids[] = $guid;
		}
	}
	$za->close();
	foreach($guids as $guid)
	{
		$p1 = sprintf("zip://%s#%s", $zn, "fils-".$guid);
		$f1 = file_get_contents($p1);
		$p2 = sprintf("zip://%s#%s", $zn, "sigs-".$guid);
		$f2 = file_get_contents($p2);
		$p3 = sprintf("zip://%s#%s", $zn, "name-".$guid);
		$f3 = file_get_contents($p3);

		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
		{
			$tu->Query("INSERT INTO TU (CLSID,PID,COMPRESSED) VALUES(?,?,?) ",array($guid,$prjrow['ID'],1));
			$e = $tu->Query("SELECT * FROM TU WHERE CLSID = ?",array($guid))->fetchArray();
		}
		if (!$e)
			die("500 Cannot update database");

		$hs = base64_encode(hash("sha256",$f1,true));
		$f1 = $tu->cmpr($f1,$e['COMPRESSED']);

		$split = $tu->Config("SPLIT","0");
		if ($split == 0)
			$tu->Query("UPDATE TU SET BW = 0,CHECKS = 0,DOWNLOADS = 0,UPDATES = 0,NAME = ?,FILEX = ?,SIGNX = ?,HASH = ? WHERE CLSID = ? ",array($f3,$f1,$f2,$hs,$guid));
		else
			{
			file_put_contents($guid,$f1);
			$f1 = "";
			$tu->Query("UPDATE TU SET BW = 0,CHECKS = 0,DOWNLOADS = 0,UPDATES = 0,NAME = ?,FILEX = ?,SIGNX = ?,HASH = ? WHERE CLSID = ? ",array($f3,$f1,$f2,$hs,$guid));
			}
	}
//	$tu->Query("VACUUM");
	die("200");
}


// check/download
if ($function == "check" || $function == "checkandsig" ||$function == "download" || $function == "hashes" )
{
	$zipdata = file_get_contents('php://input');
	$zn = tempnam(sys_get_temp_dir(),"zipx");
	unlink($zn);


	
	if (!file_put_contents($zn,$zipdata))
		die("500 Cannot create ZIP file");
	$za = new ZipArchive;
	if (!$za->open($zn))
		die("500 Cannot create ZIP file");
			
	$guids = array();
	for($i = 0 ; ; $i++)
	{
		$ni = $za->getNameIndex($i);
		if (!$ni)
			break;

		if (substr($ni,0,4) == "hash")
		{
			$guid = substr($ni,5);
			$guids[] = $guid;
		}
	}
	$za->close();

	if($function == "download" || $function == "checkandsig" || $function == "hashes" )
	{
		$zn2 = tempnam(sys_get_temp_dir(),"zipx");
		unlink($zn2);
		$za2 = new ZipArchive;
		if (!$za2->open($zn2,ZipArchive::CREATE | ZipArchive::OVERWRITE))
			die("500 Cannot create ZIP file");
	}


	$em = "200 ";
	$em .= sprintf("%u",count($guids));
	$downloadbw = array();
	$downloadblobs = array();
	foreach($guids as $guid)
	{
		$em .= ",";
		$p1 = sprintf("zip://%s#%s", $zn, "hash-".$guid);
		$f1 = file_get_contents($p1);

		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
			$em .= "404";

		// Update Checks
		if ($function == "check" || $function == "checkandsig")
			$tu->Query("UPDATE TU SET CHECKS = ? WHERE CLSID = ?",array((int)$e['CHECKS'] + 1,$e['CLSID']));

		if ($function == "download" || $function == "hashes")
			{
			$blob = $tu->BlobRow($e);
			$downloadblobs[$guid] = $blob;
			}

		if ($function == "download")
			{
			$downloadbw[$guid] = $e['BW'] + (float)(strlen($downloadblobs[$guid])/(1024*1024));
			$tu->Query("UPDATE TU SET UPDATES = ?,BW = ? WHERE CLSID = ?",array($e['UPDATES'] + 1,$downloadbw[$guid],$e['CLSID']));
			}

		$eh = base64_decode($e['HASH']);

		if ($eh != $f1)
			{
				if ($function == "download")
				{
					$za2->addFromString($guid,$downloadblobs[$guid]);
				}
				if ($function == "checkandsig")
				{
					$stream = $tu->db->openBlob('TU', 'SIGNX', $e['ID']);
					$blob2 = stream_get_contents($stream);
					fclose($stream);
					$za2->addFromString($guid,$blob2);
				}
				if ($function == "hashes")
				{
					$ha = hash("sha256",$downloadblobs[$guid],true);
		
					$za2->addFromString($guid,$ha);
				}
				$em .= "331";
			}
		else
			$em .= "220";


		unset($downloadblobs[$guid]);
	}

	if($function == "download" || $function == "checkandsig"|| $function == "hashes")
	{
		$za2->close();

		header("Content-Type: application/zip");
		header(sprintf("Content-Length: %s",filesize($zn2)));
		readfile($zn2);
		unlink($zn2);
		die;
	}
	die($em);
}

if ($function == "patch2")
{
	//ini_set('display_errors', 1); error_reporting(E_ALL);

	$zn2 = tempnam(sys_get_temp_dir(),"zipx");
	$za2 = new ZipArchive;
	if (!$za2->open($zn2,ZipArchive::CREATE | ZipArchive::OVERWRITE))
		die("500 Cannot create ZIP file");

	$r = explode("|",file_get_contents('php://input'));
	$downs = array();
	$bws = array();
	foreach($r as $rr)
	{
		$d = explode(",",$rr);
		if (count($d) != 4)
			die("500 Invalid Count");

		$guid = $d[0];
		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
			die("500 Invalid file");	

		// Load file
		if (!array_key_exists($e['CLSID'],$downs))
		{
			$blob = $tu->BlobRowToTemp($e);
			$tu->Query("UPDATE TU SET UPDATES = ? WHERE CLSID = ?",array($e['UPDATES'] + 1,$e['CLSID']));
			$downs[$e['CLSID']] = $blob;
			$bws[$e['CLSID']] = $e['BW'];
		}

		if ($d[1] == "F")
		{
			// Full 
			$bws[$e['CLSID']] += (float)(strlen($downs[$e['CLSID']])/(1024*1024));
			$za2->addFile($downs[$e['CLSID']],$guid);
		}
		else
		{
			$po = subfile($downs[$e['CLSID']],$d[2],$d[3]);
			$bws[$e['CLSID']] += (float)(filesize($po)/(1024*1024));
			$za2->addFile($po,$d[1]);
		}

	}		

	$za2->close();
	header("Content-Type: application/zip");
	header(sprintf("Content-Length: %s",filesize($zn2)));
	readfile($zn2);
	unlink($zn2);
	die;
	
}


if ($function == "patch")
{
	$zn2 = tempnam(sys_get_temp_dir(),"zipx");
	unlink($zn2);
	$za2 = new ZipArchive;
	if (!$za2->open($zn2,ZipArchive::CREATE | ZipArchive::OVERWRITE))
		die("500 Cannot create ZIP file");

	$r = explode("|",file_get_contents('php://input'));
	$downs = array();
	$bws = array();
	foreach($r as $rr)
	{
		$d = explode(",",$rr);
		if (count($d) != 4)
			die("500 Invalid Count");

		$guid = $d[0];
		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
			die("500 Invalid file");

		// Update updates
		if (!array_key_exists($e['CLSID'],$downs))
		{
			$blob = $tu->BlobRow($e);
			$tu->Query("UPDATE TU SET UPDATES = ? WHERE CLSID = ?",array($e['UPDATES'] + 1,$e['CLSID']));
			$downs[$e['CLSID']] = $blob;
			$bws[$e['CLSID']] = $e['BW'];
		}


		if ($d[1] == "F")
		{
			// Full 
			$bws[$e['CLSID']] += (float)(strlen($downs[$e['CLSID']])/(1024*1024));
			$za2->addFromString($guid,$downs[$e['CLSID']]);
		}
		else
		{
			$po = substr($downs[$e['CLSID']],$d[2],$d[3]);
			$bws[$e['CLSID']] += (float)(strlen($po)/(1024*1024));
			$za2->addFromString($d[1],$po);
		}
	}

	// Update new bandwidths
	foreach($bws as $clsid => $bw)
	{
			$tu->Query("UPDATE TU SET BW = ? WHERE CLSID = ?",array($bw,$clsid));
	}

	$za2->close();
	header("Content-Type: application/zip");
	header(sprintf("Content-Length: %s",filesize($zn2)));
	readfile($zn2);
	unlink($zn2);
	die;
}


die("400 Command not understood");