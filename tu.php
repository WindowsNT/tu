<?php

define('TU_DATABASE', "tu.db");
define('TU_ADMIN_USERNAME','root');
define('TU_ADMIN_PASSWORD','muhahah');
if (session_status() == PHP_SESSION_NONE) { session_start();}

//ini_set('display_errors', 1); error_reporting(E_ALL);
class TU
{
	public $db = null;
	public $lastRowID = 0;
	public $Compression = 0;

	public function guidv4()
	{
		if (function_exists('com_create_guid') === true)
			return trim(com_create_guid(), '{}');

		$data = openssl_random_pseudo_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}


	public function cmpr($x)
	{
		if ($this->Compression == 0)
			return $x;
		return gzcompress($x);
	}
	public function uncmpr($x)
	{
		if ($this->Compression == 0)
			return $x;
		return gzuncompress($x);
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

	public function CreateSQL()
	{
		$this->db = new SQLite3(TU_DATABASE);
		$this->Query("CREATE TABLE IF NOT EXISTS PROJECT (ID INTEGER PRIMARY KEY,CLSID TEXT,NAME TEXT,PWD TEXT)");
		$this->Query("CREATE TABLE IF NOT EXISTS TU (ID INTEGER PRIMARY KEY,PID INTEGER,CLSID TEXT,SIGNX BLOB,FILEX BLOB,HASH TEXT)");
	}

	function __construct() 
	{
		$this->CreateSQL();
	}
}

$tu = new TU;
$tu->Compression = 1;
$function = "";
$prj = "";


if (array_key_exists('p',$_GET) && array_key_exists('f',$_GET) && array_key_exists('n',$_GET))
{
	// Download this file
	$prjrow = $tu->Query("SELECT * FROM PROJECT WHERE CLSID = ?",array($_GET['p']))->fetchArray();
	if (!$prjrow)
		die;
		
	$frow = $tu->Query("SELECT * FROM TU WHERE CLSID = ? AND PID = ?",array($_GET['f'],$prjrow['ID']))->fetchArray();
	if (!$frow)
		die;

	$stream = $tu->db->openBlob('TU', 'FILEX', $frow['ID']);
	$blob = stream_get_contents($stream);
	fclose($stream);
	$blob = $tu->uncmpr($blob);

	header("Content-Type: application/octet-stream");
	header(sprintf("Content-Disposition: attachment; filename=\"%s\"",$_GET['n']));
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
		    <script src="https://code.jquery.com/jquery-3.1.1.min.js"></script>
<link href="https://fonts.googleapis.com/css?family=Noto+Serif|Tinos" rel="stylesheet">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.bundle.min.js" integrity="sha384-pjaaA8dDz/5BgdFUPX6M/9SUZv4d12SUPF0axWc+VRZkx5xU3daN+lYb49+Ax+Tl" crossorigin="anonymous"></script>
<?php

	if (array_key_exists('adminuser',$_SESSION) && $_SESSION['adminuser'] == TU_ADMIN_USERNAME)
	{
		if (array_key_exists('createp',$_GET))
		{
//			print_r($_GET); die;
			$g = $tu->guidv4();
			$tu->Query("INSERT INTO PROJECT (CLSID,NAME,PWD) VALUES(?,?,?)",array($g,$_GET['name'],password_hash($_GET['p'],PASSWORD_DEFAULT)));
			header("Location: tu.php?admin");
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
		echo '<div style="margin: 20px;">Admin Panel &mdash; <a href="tu.php?admin=1&logout=1">Logout</a><hr>';
		echo 'Projects<br>';
		printf("<ul>");
		$q = $tu->Query("SELECT * FROM PROJECT");
		while ($r = $q->fetchArray())
		{
			printf('<li><a href="tu.php?admin=1&delete=%s">Delete</a> &mdash; %s &mdash; %s</li>',$r['ID'],$r['CLSID'],$r['NAME']);
		}
		printf("</ul>");

		printf("<br><br><hr>Create new project<br><br>");
		?>
	 <form id="myform2" method="GET" action="tu.php" class="col s12">
        <div class="form-group">
            <label for="name">Name:</label>
            <input type="text" class="form-control" name="name" id="name" autofocus required/>
        </div>
        <div class="form-group">
            <label for="p">Upload Password:</label>
            <input type="password" class="form-control" name="p" id="p" required/>
        </div>
            <input type="hidden" name="createp" id="createp" value="1" />
            <input type="hidden" name="admin" id="admin" value="1" />
        <div id="buttondiv"> <span style="opacity: 0.1;"><font color=\"black\" face=\"arial\" size=\"4\">_</font></div>
        <button type="submit" name="submit" id="buttonsubmit" class="btn btn-primary btn-lg" >Create</button>
        </button></form>
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

		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
		{
			$tu->Query("INSERT INTO TU (CLSID,PID) VALUES(?,?) ",array($guid,$prjrow['ID']));
			$e = $tu->Query("SELECT * FROM TU WHERE CLSID = ?",array($guid))->fetchArray();
		}
		if (!$e)
			die("500 Cannot update database");

		$hs = base64_encode(hash("sha256",$f1,true));
		$f1 = $tu->cmpr($f1);
		$tu->Query("UPDATE TU SET FILEX = ?,SIGNX = ?,HASH = ? WHERE CLSID = ? ",array($f1,$f2,$hs,$guid));
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
	foreach($guids as $guid)
	{
		$em .= ",";
		$p1 = sprintf("zip://%s#%s", $zn, "hash-".$guid);
		$f1 = file_get_contents($p1);

		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
			$em .= "404";

		$eh = base64_decode($e['HASH']);


		if ($eh != $f1)
			{
				if ($function == "download")
				{
					$stream = $tu->db->openBlob('TU', 'FILEX', $e['ID']);
					$blob = stream_get_contents($stream);
					fclose($stream);
					$blob = $tu->uncmpr($blob);
			
					$za2->addFromString($guid,$blob);
				}
				if ($function == "checkandsig")
				{
					$stream = $tu->db->openBlob('TU', 'SIGNX', $e['ID']);
					$blob = stream_get_contents($stream);
					fclose($stream);
			
					$za2->addFromString($guid,$blob);
				}
				if ($function == "hashes")
				{
					$stream = $tu->db->openBlob('TU', 'FILEX', $e['ID']);
					$blob = stream_get_contents($stream);
					fclose($stream);
					$blob = $tu->uncmpr($blob);
					$ha = hash("sha256",$blob,true);
			
					$za2->addFromString($guid,$ha);
				}
				$em .= "331";
			}
		else
			$em .= "220";
	}

	if($function == "download" || $function == "checkandsig"|| $function == "hashes")
	{
		$za2->close();

		header("Content-Type: application/zip");
		readfile($zn2);
		unlink($zn2);
		die;
	}
	die($em);
}

if ($function == "patch")
{
	$zn2 = tempnam(sys_get_temp_dir(),"zipx");
	unlink($zn2);
	$za2 = new ZipArchive;
	if (!$za2->open($zn2,ZipArchive::CREATE | ZipArchive::OVERWRITE))
		die("500 Cannot create ZIP file");

	$r = explode("|",file_get_contents('php://input'));
	foreach($r as $rr)
	{
		$d = explode(",",$rr);
		if (count($d) != 4)
			die("500 Invalid Count");

		$guid = $d[0];
		$e = $tu->Query("SELECT * FROM TU WHERE PID = ? AND CLSID = ?",array($prjrow['ID'],$guid))->fetchArray();
		if (!$e)
			die("500 Invalid file");
	
		$stream = $tu->db->openBlob('TU', 'FILEX', $e['ID']);
		$blob = stream_get_contents($stream);
		fclose($stream);
		$blob = $tu->uncmpr($blob);

		if ($d[1] == "F")
		{
			// Full 
			$za2->addFromString($guid,$blob);
		}
		else
		{
			$po = substr($blob,$d[2],$d[3]);
			$za2->addFromString($d[1],$po);
		}
	}

	$za2->close();
	header("Content-Type: application/zip");
	readfile($zn2);
	unlink($zn2);
	die;
}


die("400 Command not understood");