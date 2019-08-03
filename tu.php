<?php

//ini_set('display_errors', 1); error_reporting(E_ALL);
class TU
{
	public $db = null;
	public $lastRowID = 0;

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
		$this->db = new SQLite3("tu.db");
		$this->Query("CREATE TABLE IF NOT EXISTS PROJECT (ID INTEGER PRIMARY KEY,CLSID TEXT,NAME TEXT)");
		$this->Query("CREATE TABLE IF NOT EXISTS TU (ID INTEGER PRIMARY KEY,PID INTEGER,CLSID TEXT,SIGNX BLOB,FILEX BLOB,HASH TEXT)");
	}

	function __construct() 
	{
		$this->CreateSQL();
	}
}

$tu = new TU;
$function = "";
$prj = "";
if (array_key_exists('HTTP_X_PROJECT',$_SERVER))
	$prj = $_SERVER['HTTP_X_PROJECT'];
if (array_key_exists('HTTP_X_FUNCTION',$_SERVER))
	$function = $_SERVER['HTTP_X_FUNCTION'];

if ($prj == "")
	die("404 - No project GUID specified");

$prjrow = $tu->Query("SELECT * FROM PROJECT WHERE CLSID = ?",array($prj))->fetchArray();
if (!$prjrow)
	die("404 - No project GUID specified");


if ($function == "upload")
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
		$tu->Query("UPDATE TU SET FILEX = ?,SIGNX = ?,HASH = ? WHERE CLSID = ? ",array($f1,$f2,$hs,$guid));
	}
//	$tu->Query("VACUUM");
	die("200");
}


// check/download
if ($function == "check" || $function == "checkandsig" ||$function == "download" )
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

	if($function == "download" || $function == "checkandsig")
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
			
					$za2->addFromString($guid,$blob);
				}
				if ($function == "checkandsig")
				{
					$stream = $tu->db->openBlob('TU', 'SIGNX', $e['ID']);
					$blob = stream_get_contents($stream);
					fclose($stream);
			
					$za2->addFromString($guid,$blob);
				}
				$em .= "331";
			}
		else
			$em .= "220";
	}

	if($function == "download" || $function == "checkandsig")
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