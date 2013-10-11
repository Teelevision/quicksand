<?php
/*
Quicksand Image Hoster
visit http://code.teele.eu/quicksand

Copyright (C) 2013 Marius "Teelevision" Neugebauer

Permission is hereby granted, free of charge, to any person obtaining a
copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be included
in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

/*
Quicksand includes the Teele Image Uploader:

Teele Image Uploader

Copyright (c) 2011 Marius "Teelevision" Neugebauer

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to
the following conditions:

 * The above copyright notice and this permission notice shall be included in all copies or substantial
   portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

/*
Requirements:
PHP >= 5.0.0
PHP extension SQLite

Version: 0.2.0 (2013-06-18)

How does Quicksand work?

Users can upload images and set a expiration time. The image is only
accessable through this script within this expiration time. You define a
maximum storage size. The oldest images are deleted when a new uplod
would exceed the limit. Everytime the script is called it checks for
expired images and deletes them. The script provides the functionality
to download itself. That means that anyone can download this whole file
unless you remove the responsible code.
*/

/* show all errors */
// ini_set('display_errors', 1)
// error_reporting(E_ALL);

/* Charset of this file. */
define('CHARSET', "UTF-8");

/* The legal notice displayed at the bottom of the page. */
define('LEGAL_NOTICE', "Marius Neugebauer • Steinkaulstraße 52 • 52070 Aachen • Germany • +49 (0) 1578 738 9019 • img@teele.eu");

/* The Quicksand class. Make your settings here. */
class Quicksand {
	
	/* You have to define a directory in which the files are stored. This directory should never be accessable through the web. Because that would be a bad privacy problem. Do not add a trailing slash. */
	const FILES_DIR = '../quicksand_files';
	
	/* This script needs to store information and therefore uses SQLite. Define here where to store the database file. Make sure that this file is not accessable through the web. You can place it in the files directory */
	const DATABASE_FILE = '../quicksand_files/images.db';
	
	/* The user can choose how long the image should be online. Add options here. */
	protected static $expireOptions = array(
		1		=> "1 minute",
		10		=> "10 minutes",
		60		=> "1 hour",
		120		=> "2 hours",
		1440	=> "1 day",
		2880	=> "2 days",
		10080	=> "1 week",
		20160	=> "2 weeks",
		43200	=> "1 month"
	);
	/* Which option should be selected by default? Set to the expiration time of one of the above options. */
	const EXPIRE_DEFAULT = 120;
	
	/* Maximum MiB to be stored. If someone uploads a file and there is no space left, the oldest files are deleted. Set to 0 if you do not want to limit the storage size. */
	const MAX_STORAGE_SIZE = 50;
	
	/* Maximum file size in MiB. Set to 0 if you do not care. Must be lower than the MAX_STORAGE_SIZE. */
	const MAX_FILE_SIZE = 0;
	
	/* Minimum, regular and maximum id length. If you increase the maximum you need to adjust the database by hand. Or simply delete it and it will be recreated. The id length can be lower than the regular length (down to min length) if the user wants to receive a tinyer url. Otherwise the url will be between regual and max length. */
	const MIN_ID_LENGTH = 3;
	const REGULAR_ID_LENGTH = 16;
	const MAX_ID_LENGTH = 16;
	
	/* Maximum number of files you allow to upload at once. Set to 0 if you do not care. */
	const MAX_NUMBER_FILES = 0;
	
	/* The name of the upload field. Do not use an array. */
	const UPLOAD_NAME = 'image';
	/* The names of the expire and submit form elements. */
	const UPLOAD_EXPIRE_NAME = 'expire';
	const UPLOAD_SUBMIT_NAME = 'upload';
	
	/* Enable cookies? */
	const COOKIES_ENABLED = true;
	/* The prefix for cookies. */
	const COOKIE_PREFIX = 'quicksand_';
	
	/* TeeleImageUploader image types that you want to allow */
	protected $supportedImages = array(
		TeeleImageUploader::TYPE_GIF,
		TeeleImageUploader::TYPE_JPEG,
		TeeleImageUploader::TYPE_PNG,
		TeeleImageUploader::TYPE_BMP,
		TeeleImageUploader::TYPE_ICO
	);
	
	/* SQLite database object */
	protected $db = null;
	
	/* TeeleImageUploader object */
	protected $uploader = null;
	
	/* stored files */
	protected $files = array();
	
	/* true = SQLite3, false = SQLiteDatabase, null = auto */
	protected $useSQLite3 = null;
	
	
	function __construct() {
		/* check files directory */
		$this->checkFilesDir();
		
		/* load SQLite database */
		$this->connectDatabase();
		
		/* load stored files */
		$this->loadFiles();
		
		/* delete expired files */
		$this->deleteExpiredFiles();
		
		/* tidy up (orphaned entries) sometimes */
		if (mt_rand(1, 20) === 10) {
			$this->tidyUpFiles();
		}
	}
	
	function __destruct() {
		/* close SQLite database */
		$this->closeDatabase();
	}
	
	/* processes an upload */
	public function saveUpload() {
		if (!isset($_FILES[self::UPLOAD_NAME])) {
			/* nothing was uploaded */
			return;
		}
		
		/* prepare uploads */
		$uploads = array();
		if (is_array($_FILES[self::UPLOAD_NAME]['tmp_name'])) {
			/* multi upload */
			foreach (array_keys($_FILES[self::UPLOAD_NAME]['tmp_name']) as $key) {
				foreach ($_FILES[self::UPLOAD_NAME] as $option => $uploadArray) {
					$uploads[$key][$option] = $uploadArray[$key];
				}
				/* abort if maximal number of files is reached */
				if (self::MAX_NUMBER_FILES && count($uploads) >= self::MAX_NUMBER_FILES) {
					break;
				}
			}
		} else {
			/* single file */
			$uploads[] = $_FILES[self::UPLOAD_NAME];
		}
		
		/* general info */
		
		/* expire */
		$expire = empty($_POST[self::UPLOAD_EXPIRE_NAME]) ? self::EXPIRE_DEFAULT : (int)$_POST[self::UPLOAD_EXPIRE_NAME];
		if (!isset(self::$expireOptions[$expire])) {
			$expire = self::EXPIRE_DEFAULT;
		}
		$expire *= 60; // minutes to seconds
		
		/* time and deletion time */
		$time = time();
		$delTime = $time + $expire;
		
		/* tinyer url */
		$tinyerID = !empty($_POST[self::UPLOAD_SUBMIT_NAME]);
		
		/* whether this is a multi upload (gallery) */
		$isGallery = count($uploads) > 1;
		
		/* process all uploads */
		$newFiles = array();
		foreach ($uploads as $upload) {
			
			/* file size */
			$size = filesize($upload['tmp_name']);
			if ($size === false) {
				throw new QuicksandException("Cannot calculate file size.");
			}
			if (self::getMaxFileSize() && $size > self::getMaxFileSize()) {
				throw new QuicksandException("File is too big.");
			}
			
			/* clear needed space */
			$this->requireStorageSize($size);
			
			/* id */
			/* tinyer url only if first file because it could be a single file */
			$id = $this->createNewID(!$isGallery && $tinyerID);
			
			/* delete code */
			$delCode = $this->createNewDelCode();
			
			/* save file */
			$uploadResult = $this->getUploader()->upload($upload, $this->getFileDirectory($id), false);
			
			if ($uploadResult == TeeleImageUploader::ERROR_NOERROR) {
				/* success */
			
				/* insert into database */
				$mime = $this->getUploader()->mime;
				$this->execQuery("INSERT INTO files (id, time, del, size, mime, delcode) VALUES ('".$id."', ".$time.", ".$delTime.", ".$size.", '".$mime."', '".$delCode."');");
				
				/* set delete cookie */
				if (self::COOKIES_ENABLED) {
					setcookie(self::COOKIE_PREFIX.'del_'.$id, $delCode, $delTime);
				}
				
				/* add to files array */
				$this->files[$id] = array('id' => $id, 'time' => $time, 'del' => $delTime, 'size' => $size, 'mime' => $mime);
				
				/* add to the array of uploaded files */
				$newFiles[$id] = $this->files[$id];
				$newFiles[$id]['name'] = $upload['name'];
				
			} else {
				/* errors */
				
				switch ($uploadResult) {
					case TeeleImageUploader::ERROR_EMPTY:
						throw new QuicksandException("You uploaded an empty file! Try again!");
					case TeeleImageUploader::ERROR_INVALIDFILE:
						throw new QuicksandException("This file type is not supported!");
					case TeeleImageUploader::ERROR_NOTREADABLE:
					case TeeleImageUploader::ERROR_MORETHANONEFILE:
					case TeeleImageUploader::ERROR_NOTUPLOADEDFILE:
					case TeeleImageUploader::ERROR_UPLOADERROR:
						throw new QuicksandException("Somehow, something went wrong. You may try again.");
					case TeeleImageUploader::ERROR_SAVE:
						throw new QuicksandException("Oh dear, the system is definitely broken. This could indicate that earth will be destroyed soon.");
					/* unknown error */
					default:
						throw new QuicksandException("Somehow, something went wrong. You may try again.");
				}
			}
			
		}
		
		
		/* more than one file was uploaded successfully */
		if (count($newFiles) > 1) {
			/* create HTML view */
			
			/* id */
			$id = $this->createNewID($tinyerID);
			
			/* delete code */
			$delCode = $this->createNewDelCode();
			
			/* create HTML */
			$url = self::getURL(false);
			$content = "<!DOCTYPE html><html><head><meta http-equiv=\"content-type\" content=\"text/html; charset=".CHARSET."\"><link rel=\"shortcut icon\" type=\"image/x-icon\" href=\"".$url."?action=favicon\"><title>Gallery ".$id."</title><style type=\"text/css\"><!-- body { background-image: url(data:image/gif;base64,R0lGODlhEAAQAKECAGZmZpmZmf///////yH5BAEKAAIALAAAAAAQABAAAAIfjG+gq4jM3IFLJgpswNly/XkcBpIiVaInlLJr9FZWAQA7); } a { display: inline-block; float: left; margin: 5px; } img { max-width: 600px; max-height: 600px; border: 1px solid white; } --></style></head><body><div>";
			foreach ($newFiles as $fileID => $file) {
				$iurl = $url."?".$fileID.(empty($file['name']) ? "" : "#".$file['name']);
				$content .= "<a href=\"".$iurl."\"><img src=\"".$iurl."\" alt=\"".$fileID."\"></a>";
			}
			$content .= "<a href=\"".$url."\"><img alt=\"\" src=\"".$url."?action=favicon\"></a></div></body></html>";
			
			/* save file */
			$file = $this->getFileDirectory($id);
			file_put_contents($file, $content);
			$size = filesize($file);
			
			/* add entry */
			$this->execQuery("INSERT INTO files (id, time, del, size, mime, delcode) VALUES ('".$id."', ".$time.", ".$delTime.", ".$size.", 'text/html', '".$delCode."');");
			
			/* set delete cookie */
			if (self::COOKIES_ENABLED) {
				setcookie(self::COOKIE_PREFIX.'del_'.$id, $delCode, $delTime);
			}
			
			/* redirect to gallery */
			header("Location: ".self::getURL(true)."?".$id."#gallery", true, 303);
			exit;
			
		}
		
		/* one file was uploaded successfully */
		else {
			$img = reset($newFiles);
			/* redirect to file */
			header("Location: ".self::getURL(true)."?".$img['id'].(empty($img['name']) ? "" : "#".$img['name']), true, 303);
			exit;
		}
		
	}
	
	/* returns the storage size in bytes */
	public static function getStorageSize() {
		return self::MAX_STORAGE_SIZE * 1024 * 1024;
	}
	
	/* returns the maximal file size in bytes */
	public static function getMaxFileSize() {
		return self::MAX_FILE_SIZE * 1024 * 1024;
	}
	
	/* returns the used storage size in bytes */
	public function getUsedStorageSize() {
		$size = 0;
		foreach ($this->files as $file) {
			$size += (int)$file['size'];
		}
		return $size;
	}
	
	/* clears the storage so that the required size gets free */
	protected function requireStorageSize($neededSize) {
		$storageSize = self::getStorageSize();
		$usedSize = $this->getUsedStorageSize();
		
		/* stop here if no limit is set */
		if (!$storageSize) {
			return;
		}
		
		/* check if file exceeds storage size */
		if ($neededSize > $storageSize) {
			throw new QuicksandException("File exceeds storage size.");
		}
		
		/* delete old files as long as there is enough space */
		$usedSize += $neededSize;
		while ($usedSize > $storageSize) {
			/* the files are ordered by the time when they were uploaded */
			/* so the first is the oldest */
			$firstFile = reset($this->files);
			/* delete first file */
			$usedSize -= (int)$firstFile['size'];
			$this->deleteFile($firstFile['id']);
		}
	}
	
	/* returns an unused id */
	protected function createNewID($tinyer = false) {
		$chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
		/* make it longer every 10 tries */
		$startLength = $tinyer ? self::MIN_ID_LENGTH : self::REGULAR_ID_LENGTH;
		for ($length = $startLength; $length <= self::MAX_ID_LENGTH; $length++) {
			/* 10 tries; don't abort if max length is reached */
			for ($i = 0; $i < 10 || $length == self::MAX_ID_LENGTH; $i++) {
				$id = "";
				for ($l = $length; $l; $l--) {
					/* tinyer urls should be easy as well */
					if ($tinyer) {
						$id .= $chars[mt_rand(0, 15)];
					} else {
						$id .= $chars[mt_rand(0, 61)];
					}
				}
				if (!isset($this->files[$id])) {
					return $id;
				}
			}
		}
	}
	
	/* returns a new delete code */
	protected function createNewDelCode() {
		$chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$code = "";
		for ($l = 32; $l; $l--) {
			$code .= $chars[mt_rand(0, 61)];
		}
		return $code;
	}
	
	/* returns the uploader */
	protected function getUploader() {
		if ($this->uploader !== null) {
			return $this->uploader;
		}
		/* load */
		$this->uploader = new TeeleImageUploader($this->supportedImages);
		return $this->uploader;
	}
	
	/* creates the files dir if necessary and check if it is accessable */
	protected function checkFilesDir() {
		$perms = @fileperms(self::FILES_DIR);
		/* create if not exist */
		if ($perms === false && !mkdir(self::FILES_DIR, 0700, true)) {
			throw new QuicksandException("Cannot create files dir.");
		}
		/* check if dir */
		else if (($perms & 0x4000) != 0x4000) {
			throw new QuicksandException("Files dir is not actually a directory.");
		}
		/* check permissions */
		else if (($perms & 0700) != 0700) {
			throw new QuicksandException("Missing permissions. Make sure this script can read, write and enter the files dir.");
		}
	}
	
	/* initializes the SQLite database */
	protected function connectDatabase() {
		if ($this->useSQLite3()) {
			$this->db = new SQLite3(self::DATABASE_FILE);
		} else {
			$this->db = new SQLiteDatabase(self::DATABASE_FILE);
		}
		if (!$this->db) {
			throw new QuicksandException("Initializing SQLite database failed.");
		}
		$this->db->busyTimeout(20000);
	}
	
	/* closes the SQLite database */
	protected function closeDatabase() {
		if ($this->db !== null) {
			$this->db->close();
			$this->db = null;
		}
	}
	
	/* returns true if you can use SQLite3 instead of SQLiteDatabase */
	protected function useSQLite3() {
		return $this->useSQLite3 === true || ($this->useSQLite3 !== false && $this->useSQLite3 = class_exists("SQLite3"));
	}
	
	/* returns true if you can use SQLite3 instead of SQLiteDatabase */
	protected function execQuery($query) {
		if ($this->useSQLite3()) {
			return $this->db->exec($query);
		} else {
			return $this->db->queryExec($query);
		}
	}
	
	/* initializes the SQLite database */
	protected function loadFiles() {
		$this->files = array();
		
		/* select all files */
		$query = @$this->db->query('SELECT * FROM files ORDER BY time ASC');
		if (!$query) {
			/* table does not exist: create it */
			$this->execQuery("CREATE TABLE files (
						id CHAR(".self::MAX_ID_LENGTH.") PRIMARY KEY,
						time INTEGER,
						del INTEGER,
						size INTEGER,
						mime VARCHAR(255),
						delcode CHAR(32)
					);");
		} else {
			/* get files */
			while ($entry = ($this->useSQLite3() ? $query->fetchArray(SQLITE3_ASSOC) : $query->fetch(SQLITE_ASSOC))) {
				$this->files[$entry['id']] = $entry;
			}
		}
	}
	
	/* finds too old files and deletes them */
	protected function deleteExpiredFiles() {
		$time = time();
		foreach ($this->files as $id => $file) {
			/* check if delete time is in the past */
			if ($file['del'] < $time) {
				/* remove the file */
				$this->deleteFile($id);
			}
		}
	}
	
	/* finds orphaned files in the database and on the disk */
	protected function tidyUpFiles() {
		/* tidy up database */
		foreach ($this->files as $id => $file) {
			/* check if file exists */
			if (!is_file($this->getFileDirectory($id))) {
				/* remove the entry */
				$this->deleteFile($id);
			}
		}
		
		/* tidy up storage */
		if ($dh = opendir(self::FILES_DIR)) {
			while (($file = readdir($dh)) !== false) {
				/* the file name is the id */
				/* delete if not in the database */
				if (preg_match("#^[0-9a-f]{6,10}$#", $file) && !isset($this->files[$file])) {
					$this->deleteFile($file);
				}
			}
			closedir($dh);
		}
	}
	
	/* deletes a file from disk and removes it from the database */
	protected function deleteFile($id) {
		/* delete file */
		$file = $this->getFileDirectory($id);
		if (is_file($file) && !@unlink($file)) {
			/* file exists but deletion failed */
			throw new QuicksandException("Cannot delete file ".$id.".");
		}
		
		/* remove from database */
		@$this->execQuery("DELETE FROM files WHERE id = '".$id."';");
		
		/* remove from array */
		if (isset($this->files[$id])) {
			unset($this->files[$id]);
		}
	}
	
	/* deletes a user's file if providing the right delcode */
	public function deleteUserFile($id, $delcode) {
		if (isset($this->files[$id])) {
			if ($this->files[$id]['delcode'] === $delcode) {
				$this->deleteFile($id);
				/* delete cookie */
				setcookie(self::COOKIE_PREFIX.'del_'.$id, '', time() - 3600);
				/* redirect to main page */
				header("Location: ".self::getURL(true), true, 303);
				exit;
			} else {
				throw new QuicksandException("Delete code is wrong.");
			}
		} else {
			throw new QuicksandException("The file you are looking for does not exist (anymore).");
		}
	}
	
	/* returns an array with the uploads of the user */
	public function getUserUploads() {
		$uploads = array();
		if (!self::COOKIES_ENABLED) {
			return $uploads;
		}
		
		$prefix = self::COOKIE_PREFIX.'del_';
		$prefixLen = strlen($prefix);
		
		/* go through cookies */
		foreach ($_COOKIE as $name => $delcode) {
			if (substr($name, 0, $prefixLen) == $prefix) {
				$id = substr($name, $prefixLen);
				if (isset($this->files[$id]) && $this->files[$id]['delcode'] === $delcode) {
					$uploads[$id] = $this->files[$id];
				} else {
					/* there is no match, probably the image was deleted and the id reused */
					/* delete cookie */
					setcookie($name, '', time() - 3600);
				}
			}
		}
		
		return $uploads;
	}
	
	/* returns the path to the file with the given id */
	protected function getFileDirectory($id) {
		return self::FILES_DIR.DIRECTORY_SEPARATOR.$id;
	}
	
	/* outputs a stored file */
	public function displayFile($id) {
		/* check entry */
		if (!isset($this->files[$id])) {
			throw new QuicksandException("The file you are looking for does not exist (anymore).");
		}
		
		/* check file */
		$file = $this->getFileDirectory($id);
		if (!is_file($file)) {
			/* delete file from database */
			$this->deleteFile($id);
			throw new QuicksandException("Cannot find file ".$id.".");
		}
		
		/* caching */
		if ($lastmodified = filemtime($file)) {
			/* check if client gives a time of its cached file and if it is up to date */
			if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) > $lastmodified) {
				header("HTTP/1.1 304 Not Modified", true, 304);
				header("Expires: ".date(DATE_RFC1123, $this->files[$id]['del']));
				header("Cache-Control: max-age=".($this->files[$id]['del'] - time()).", public");
				exit;
			}
			header("Last-Modified: ".date(DATE_RFC1123, $lastmodified)); // for caching (see above)
		}
		
		/* output the file */
		header("Content-Type: ".$this->files[$id]['mime']);
		header("Content-Length: ".$this->files[$id]['size']);
		$extension = self::getFileExtensionFromMime($this->files[$id]['mime']);
		header("Content-Disposition: filename=\"".$id.($extension === "" ? "" : ".".$extension)."\";");
		header("Expires: ".date(DATE_RFC1123, $this->files[$id]['del'])); // for caching (browser)
		header("Cache-Control: max-age=".($this->files[$id]['del'] - time()).", public"); // for caching (browser)
		readfile($file);
		exit;
	}
	
	/* returns a file extension that fits to the given mime type */
	public static function getFileExtensionFromMime($mime) {
		switch ($mime) {
			case "image/gif": return "gif";
			case "image/jpeg": return "jpg";
			case "image/png": return "png";
			case "image/x-icon": case "image/vnd.microsoft.icon": return "ico";
			case "image/bmp": case "image/x-ms-bmp": return "bmp";
			case "image/swf": return "swf";
			case "text/html": return "htm";
			default: return "";
		}
	}
	
	/* outputs the favicon */
	public static function displayEmbeddedFile($file = "") {
		/* caching */
		if ($lastmodified = filemtime(__FILE__)) {
			/* check if client gives a time of its cached file and if it is up to date */
			if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) > $lastmodified) {
				header("HTTP/1.1 304 Not Modified", true, 304);
				exit;
			}
			header("Last-Modified: ".date(DATE_RFC1123, $lastmodified)); // for caching
		}
		
		/* output the file */
		switch ($file) {
			case "favicon":
				header("Content-Type: image/png");
				header("Content-Length: 439");
				echo base64_decode("iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABfklEQVQ4y63TP2uTURQG8N8JBVvUqrT+mWqwCOKgq5sO7kU3F2c/wFuiH0GNbh108wtkdhVExEFiEcFBfLFSES3EdmkIzXHIm5hG08lnuRfOc859nnPODWNotEqTuH+z7iDOjH9gMukgxPCSTYltvMV3vEMTvSimF/ijIBHmk2vSWoQHmEMfe9MsTCqAr7iCM1iPQi+bFbmYYiGb5vELMl0Q2sHpKr6CN/iCiyij8HNYoFadc6OK4VvwEbfRSR7jemX3Kp73m241WqVGq/xLwQ+cRyfTVoSFMbXHcTjZCLo4EYVurfK2jV0sJItSOZ6cg9hKFDaDrUrxqUarHFmAO5nWgxvCJaxl2pGeBR/Qrngnq6qz+6YA/YeWIyzhPbrSrDCDz8kRLAabFX3p7rlyY1yBe8vlJ7xI9pJd4Sxe4VjQk16P7c3O+BRGKxyFvtSJgbp2FOpVfx4ZqBsu3fS/UFvVN+i0bJKsBvXgJS4LRw/8TPuQDglP8LR6OfxP/AYFioEnuJlWhAAAAABJRU5ErkJggg==");
				break;
		}
		exit;
	}
	
	/* outputs this file */
	public static function displaySource() {
		/* get time when this file was modified */
		if ($lastmodified = filemtime(__FILE__)) {
			header("Last-Modified: ".date(DATE_RFC1123, $lastmodified));
		}
		header("Content-Type: text/plain; charset=".CHARSET);
		header("Content-Length: ".filesize(__FILE__));
		readfile(__FILE__);
		exit;
	}
	
	/* returns the relative or absolute URL */
	public static function getURL($absolute = false) {
		/* remove parameters */
		$cleanURI = ($pos = strpos($_SERVER['REQUEST_URI'], '?')) === false ? $_SERVER['REQUEST_URI'] : substr($_SERVER['REQUEST_URI'], 0, $pos);
		
		/* build absolute URL */
		if ($absolute) {
			$url = (empty($_SERVER['HTTPS']) ? "http://" : "https://").$_SERVER['HTTP_HOST'].$cleanURI;
		}
		/* build relative URL */
		else {
			$url = "./".(substr($cleanURI, -1) === "/" ? "" : basename($cleanURI));
		}
		
		return $url;
	}
	
	/* makes the bytes better readable */
	public static function getReadableSize($bytes) {
		$units = array("B", "KiB", "MiB", "GiB");
		for ($i = (count($units) - 1) * 10; $i >= 0; $i -= 10) {
			if (!$i || $bytes >> $i) {
				return round($bytes / (1 << $i), 2)." ".$units[$i/10];
			}
		}
	}
	
	/* detects maximal upload file size */
	public static function getMaxUploadFileSize() {
		$units = array('' => 0, 'k' => 10, 'm' => 20, 'g' => 30);
		
		/* max file size */
		$min = self::getMaxFileSize();
		
		/* upload_max_filesize */
		if (preg_match("/^([0-9]+)(|k|m|g)$/i", ini_get("upload_max_filesize"), $matches)) {
			$upload_max_filesize = intval($matches[1]) << $units[strtolower($matches[2])];
			$min = $min ? min($min, $upload_max_filesize) : $upload_max_filesize;
		}
		
		/* max upload size */
		$uploadsize = self::getMaxUploadSize();
		if ($uploadsize) {
			$min = $min ? min($min, $uploadsize) : $uploadsize;
		}
		
		return $min;
	}
	
	/* detects maximal upload size */
	public static function getMaxUploadSize() {
		$units = array('' => 0, 'k' => 10, 'm' => 20, 'g' => 30);
		
		/* storage size */
		$min = self::getStorageSize();
		
		/* post_max_size */
		if (preg_match("/^([0-9]+)(|k|m|g)$/i", ini_get("post_max_size"), $matches)) {
			$post_max_size = intval($matches[1]) << $units[strtolower($matches[2])];
			$min = $min ? min($min, $post_max_size) : $post_max_size;
		}
		
		return $min;
	}
	
	/* detects maximal upload file number */
	public static function getMaxUploadFileNumber() {
		$min = self::MAX_NUMBER_FILES;
		
		/* max_file_uploads */
		$max_file_uploads = (int)ini_get("max_file_uploads");
		if ($max_file_uploads) {
			$min = $min ? min($min, $max_file_uploads) : $max_file_uploads;
		}
		
		/* suhosin.upload.max_uploads */
		$suhosin = (int)ini_get("suhosin.upload.max_uploads");
		if ($suhosin) {
			$min = $min ? min($min, $suhosin) : $suhosin;
		}
		
		return $min;
	}
	
	/* returns the expire options */
	public static function getExpireOptions() {
		return self::$expireOptions;
	}
}

/* error exception class for Quicksand */
class QuicksandException extends Exception {
	
	public function __construct($message) {
		parent::__construct($message);
	}
	
	public function __toString() {
		return __CLASS__ . ": ".$this->message."\n";
	}
}


/* start Quicksand */
try {
	
	/* create object */
	/* Quicksand will already load the stored files and delete expired ones */
	$quicksand = new Quicksand();
	
	/* actions */
	if (isset($_GET['action'])) {
		switch ($_GET['action']) {
			/* display source */
			case "download":
				$quicksand->displaySource();
			/* display favicon */
			case "favicon":
				$quicksand->displayEmbeddedFile("favicon");
		}
	}
	
	/* display image */
	foreach ($_GET as $param => $value) {
		if (preg_match("#^[0-9a-zA-Z]{".Quicksand::MIN_ID_LENGTH.",".Quicksand::MAX_ID_LENGTH."}$#", $param)) {
			if (empty($_GET['del'])) {
				$quicksand->displayFile($param);
			} else {
				$quicksand->deleteUserFile($param, $_GET['del']);
			}
		}
	}
	
	/* uploaded file */
	if (isset($_FILES[Quicksand::UPLOAD_NAME])) {
		$quicksand->saveUpload();
	}
	
	$uploads = $quicksand->getUserUploads();

} catch (QuicksandException $e) {
	$errorMessage = $e->getMessage();
}

?><!DOCTYPE html>
<html>
<head>
	<meta http-equiv="content-type" content="text/html; charset=<?php echo CHARSET; ?>">
	<meta name="description" lang="en" content="A short time image hoster.">
	<meta name="keywords" lang="en" content="quicksand,image,upload,hoster,share,temporary">
	<meta name="robots" content="index,follow">
	<link rel="shortcut icon" type="image/png" href="<?php echo $url = Quicksand::getURL(); ?>?action=favicon">
	<title>Quicksand Image Hoster</title>
	<style type="text/css">
	<!--
	body { background-color: #fa6; background: -moz-linear-gradient(top, #ffd, #fff 120px, #fa6 400px) no-repeat #fa6; background: -o-linear-gradient(top, #ffd, #fff 120px, #fa6 400px) no-repeat #fa6; background: -webkit-linear-gradient(top, #ffd, #fff 120px, #fa6 400px) no-repeat #fa6; background: -ie-linear-gradient(top, #ffd, #fff 120px, #fa6 400px) no-repeat #fa6; background: linear-gradient(top, #ffd, #fff 120px, #fa6 400px) no-repeat #fa6; }
	#main { background-color: #fc5; font-family: Arial,Helvetica,sans-serif; font-size: 14px; width: 400px; margin: 30px auto; padding: 8px 12px; border: 5px solid white; border-radius: 10px; box-shadow: 0 5px 10px rgba(0,0,0,.4); }
	h1, h2 { display: inline; }
	h1 a { text-decoration: none; }
	h2 { font-size: 14px; }
	a { color: black; }
	p { margin: 8px 0; }
	.error { background-color: #f85; border: 2px solid #f40; border-radius: 5px; padding: 5px 10px; }
	#main > p { text-align: justify; }
	#upload p { margin: 0; }
	#upload p:first-letter { font-size: 40px; font-weight: bold; color: white; vertical-align: sub; }
	button, select { border: 0; border-radius: 4px; background-color: #fff; padding: 5px 10px; cursor: pointer; }
	button:hover { color: #fa6; }
	select option { padding: 0 10px; }
	footer { font-size: 80%; }
	.notice { font-size: 80%; display: block; }
	#uploads ul { list-style: none; margin: 0; padding: 5px 10px; max-height: 100px; overflow: auto; border: 5px solid white; border-radius: 4px; background-color: white; }
	#uploads .delete { width: 16px; height: 16px; display: inline-block; vertical-align: middle; background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAAXNSR0IArs4c6QAAAAZiS0dEAAAAAAAA+UO7fwAAAAlwSFlzAAALEwAACxMBAJqcGAAAAAd0SU1FB9oMEhUOCMokI9sAAAAZdEVYdENvbW1lbnQAQ3JlYXRlZCB3aXRoIEdJTVBXgQ4XAAABgElEQVQ4y63TMWtTURjG8d+bphI76BLqmqU4HL+CkG/kIqXbPdkEQXDxGzi49SO0YBHB0aJ0UFHBwQpSa0zbJMfhJvUmpov4wnPh3HOf/+G873P5n3WYonqTorpqf7ur2u5avf8+RXWSovxIUT6vgDxNUb24HWWvpzQha/ApRdUlr89ebNC/txkefrUPL1NUW+Rpi7MLtq7pb7Y5GNpvQ4doo4VAwQ3ycQqnuE4eYR3tdhiPi1b9af2AUYq8TjUHFIxxgTOMcIrvE3aPy+DBsWx2KOgcloxBNKjR0Hz9nEvzAgDWDkueMpivS0PwmsH9tyU3Pa3lbk8oU6zS2R+WhSnM6yRF1SZPa5BJow/ndWP7N4WDYT2dBcDHFNUGeTI7bTzT+UwjDKf0olyO8BLwapaD6ZL5A4Mv7HfoD/FzWN/grxx8I24t5eAdg7v1ZDxJ4c6vxQi3GhEAz1LkoxTlKEXZTZGXm7XTlfd6yl5P2enKK/+HRyny4xXmJuRK87/Wb3MTloU75rlxAAAAAElFTkSuQmCC'); }
	-->
	</style>
	<?php if (Quicksand::COOKIES_ENABLED) { ?>
	<script type="text/javascript">
	function saveExpireInCookie(value) {
		var expire = new Date();
		expire.setTime(expire.getTime() + 2592000000);
		document.cookie = "<?php echo Quicksand::COOKIE_PREFIX; ?>expire=" + value + "; expires=" + expire.toGMTString();
	}
	function setExpireFromCookie() {
		value = document.cookie.match(/<?php echo Quicksand::COOKIE_PREFIX; ?>expire=[0-9]+/);
		if (value != null) {
			document.forms[0].expire.value = value[0].substr(17);
		}
	}
	</script>
	<?php } ?>
</head>
<body<?php if (Quicksand::COOKIES_ENABLED) { ?> onload="setExpireFromCookie()"<?php } ?>>
	<div id="main">
		
		<header>
			<h1><a href="<?php echo $url; ?>">Quicksand</a></h1>
			<h2>Share images with your friends</h2>
		</header>
		
		<p title="Quicksand is a tool, not a service.">Providing people with slow internet connection and many friends with an image sharing tool.</p>
		<p>There is no guaranty at all! Don't do anything that is illegal! You're responsible for your uploaded files!</p>
		<?php
		$maxFiles = Quicksand::getMaxUploadFileNumber();
		$maxUp = Quicksand::getMaxUploadSize();
		$maxUpFile = Quicksand::getMaxUploadFileSize();
		$storageSize = Quicksand::getStorageSize();
		?><p>You can select <?php echo $maxFiles && $maxFiles < 40 ? $maxFiles == 1 ? "one file" : $maxFiles." files" : "several files"; ?> up to <?php echo Quicksand::getReadableSize($maxUp); if ($maxUpFile < $maxUp) { ?> in total and <?php echo Quicksand::getReadableSize($maxUpFile); ?> per file<?php } ?> (png/jpg/gif/bmp/ico).</p>
		
		<?php if (!empty($errorMessage)) { ?><p class="error"><?php echo $errorMessage; ?></p><?php } ?>

		<form id="upload" action="<?php echo $url; ?>" method="post" enctype="multipart/form-data">
			<p<?php if (!$maxFiles || $maxFiles > 1) { ?> title="You can select several files at once."<?php } ?>>
				1 Select <input name="<?php echo Quicksand::UPLOAD_NAME; ?>[]" type="file"<?php if (!$maxFiles || $maxFiles > 1) { ?> multiple<?php } ?> required>
			</p>
			<p title="After a maximum of this time the image link will stop providing your image. Images are deleted when this web page is called.">
				2 Expire in ...
				<select name="<?php echo Quicksand::UPLOAD_EXPIRE_NAME; ?>" onchange="saveExpireInCookie(this.value)">
					<?php foreach (Quicksand::getExpireOptions() as $expire => $label) { ?><option value="<?php echo $expire; ?>"<?php echo ($expire == Quicksand::EXPIRE_DEFAULT) ? " selected" : ""; ?>><?php echo $label; ?></option><?php } ?>

				</select>
				<span class="notice">Your selection is saved in a browser cookie via JavaScript for 30 days. It is only used to remember your selection.</span>
			</p>
			<p>3 Upload:
				<button name="<?php echo Quicksand::UPLOAD_SUBMIT_NAME; ?>" value="0" type="submit">Private url</button>
				or
				<button name="<?php echo Quicksand::UPLOAD_SUBMIT_NAME; ?>" value="1" type="submit">Short url</button>
				<span class="notice">A short url is easy to guess and has a high chance to be reused. A private url is much longer to prevent it from being guessed.</span>
			</p>
		</form>
		
		<p title="This is the total storage size that is shared by all users. If it exceeds, new images edge out old ones.">Shared storage: <?php echo Quicksand::getReadableSize($quicksand->getUsedStorageSize()); echo $storageSize ? " / ".Quicksand::getReadableSize($storageSize) : ""; ?></p>
		
		<?php if (count($uploads)) { ?>
		<div id="uploads">
			Your uploads:
			<ul><?php foreach ($uploads as $id => $data) { ?>
				<li>
					<a href="?<?php echo $id; ?>&amp;del=<?php echo $data['delcode']; ?>"><span class="delete"></span></a>
					<a href="?<?php echo $id; ?>"><?php echo Quicksand::getFileExtensionFromMime($data['mime']); ?></a>,
					<?php echo Quicksand::getReadableSize($data['size']); ?>,
					<?php echo date(DATE_ATOM, $data['del']); ?>
				</li>
			<?php } ?></ul>
		</div>
		<?php } ?>
		<span class="notice">Your uploads are saved in cookies and then displayed here.</span>
		
		<footer>
			<p id="by">
				<a href="http://code.teele.eu/quicksand">Quicksand was coded by Teelevision</a> • <a href="<?php echo $url; ?>?action=download" title="Download an exact copy of this script. You are free to host it yourself. See the license.">Download source</a>
			</p>
			<p id="legal_notice">
				<?php echo LEGAL_NOTICE; ?>
			</p>
		</footer>
		
	</div>
</body>
</html><?php

class TeeleImageUploader {
	// types
	const TYPE_NONE = 0; // every file
	const TYPE_GIF = 1; // GIF
	const TYPE_JPEG = 2; // JPEG
	const TYPE_PNG = 3; // PNG
	const TYPE_SWF = 4; // SWF
	const TYPE_PSD = 5; // PSD
	const TYPE_BMP = 6; // BMP
	const TYPE_TIFF_II = 7; // TIFF (intel byte order)
	const TYPE_TIFF_MM = 8; // TIFF (motorola byte order) 
	const TYPE_JPC = 9; // JPC
	const TYPE_JP2 = 10; // JP2
	const TYPE_JPX = 11; // JPX
	const TYPE_JB2 = 12; // JB2
	const TYPE_SWC = 13; // SWC
	const TYPE_IFF = 14; // IFF
	const TYPE_WBMP = 15; // WBMP
	const TYPE_XBM = 16; // XBM
	const TYPE_ICO = 17; // ICO
	
	// errors
	const ERROR_NOERROR = 0; // no error
	const ERROR_NOTREADABLE = 1; // file is not readable
	const ERROR_EMPTY = 2; // the file is empty
	const ERROR_MORETHANONEFILE = 3; // more than one file was uploaded (array of files)
	const ERROR_INVALIDFILE = 4; // invalid file type
	const ERROR_NOTUPLOADEDFILE = 5; // the file is not a uploaded file
	const ERROR_UPLOADERROR = 6; // error during upload, exact error in $_FILES['image']['error']
	const ERROR_SAVE = 7; // could not save file
	
	
	protected $validTypes = array();
	public $type = 0;
	public $mime = 0;
	public $filename = "";
	public $width = 0;
	public $height = 0;
	
	public static $extensions = array(
			1 => array('gif'),
			2 => array('jpg', 'jpeg'),
			3 => array('png'),
			4 => array('swf'),
			5 => array('psd'),
			6 => array('bmp'),
			7 => array('tiff'),
			8 => array('tiff'),
			9 => array(),
			10 => array(),
			11 => array(),
			12 => array(),
			13 => array(),
			14 => array(),
			15 => array(),
			16 => array(),
			17 => array('ico')
		);
	
	
	public function __construct($validtypes = array()) {
		$this->addValidTypes((array)$validtypes);
	}
	
	/* declare a type to be valid */
	public function addValidType($type) {
		$this->addValidTypes((array)$type);
	}
	
	/* declare array of types to be valid */
	public function addValidTypes($types) {
		foreach ($types as $t) {
			$this->validTypes[] = $t;
		}
	}
	
	/* returns a vaild extension for the type */
	public static function getExtension($type) {
		return (isset(self::$extensions[$type][0])) ? self::$extensions[$type][0] : "";
	}
	
	/* checks and saves a uploaded file */
	public function upload($file, $newfile, $rightExtension = true) {
		// check if is an array
		if (is_array($file['tmp_name'])) {
			return self::ERROR_MORETHANONEFILE;
		}
		
		// check for upload errors
		if ($file['error'] != UPLOAD_ERR_OK) {
			return self::ERROR_UPLOADERROR;
		}
		
		// check if uploaded file is available
		if (!is_file($file['tmp_name']) || !is_readable($file['tmp_name'])) {
			return self::ERROR_NOTREADABLE;
		}
		
		// check if file is a uploaded file
		if (!is_uploaded_file($file['tmp_name'])) {
			return self::ERROR_NOTUPLOADEDFILE;
		}
		
		// check if file is empty
		if (!filesize($file['tmp_name'])) {
			return self::ERROR_EMPTY;
		}
		
		// get infos
		$infos = getimagesize($file['tmp_name']);
		$this->width = $infos[0];
		$this->height = $infos[1];
		$this->type = $infos[2];
		$this->mime = $infos['mime'];
		
		// check if valid type
		if (!in_array($this->type, $this->validTypes)) {
			return self::ERROR_INVALIDFILE;
		}
		
		// new filename
		if ($rightExtension) {
			// add correct extension if needed
			$extension = strtolower(pathinfo($newfile, PATHINFO_EXTENSION));
			if (!empty(self::$extensions[$this->type]) && !in_array($extension, self::$extensions[$this->type])) {
				$newfile .= '.'.$this->getExtension($this->type);
			}
		}
		$this->filename = basename($newfile);
		
		// save file
		if (!move_uploaded_file($file['tmp_name'], $newfile)) {
			return self::ERROR_SAVE;
		}
		
		return self::ERROR_NOERROR;
	}
}
?>