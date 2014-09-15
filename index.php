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
Quick start:
1. Adjust FILES_DIR and DATABASE_FILE. Make sure they aren't accessable
   over the internet!
2. Call script/webpage to create FILES_DIR and DATABASE_FILE.
3. Adjust LEGAL_NOTICE.

Requirements:
PHP >= 5.3.0
PHP extension SQLite3

Version: 0.2.2-beta-3 (2013-10-14)

How does Quicksand work?
Users can upload images which expire after the given time or earlier. A
gallery is created when uploading several images at once. This script
deletes expired files when it is called. If the storage size that is
shared by all users is exceeded, old images are deleted. The script
provides a download link for its source to spread easily.
*/

/* Charset of this file. */
define('CHARSET', 'UTF-8');

/* The legal notice displayed at the bottom of the page. */
define('LEGAL_NOTICE', '');

/* The Quicksand class. Make your settings here. */
class Quicksand {
	
	/* You have to define a directory in which the files are stored in. This directory should never be accessable through the web. Because that would be a bad privacy problem. */
	const FILES_DIR = './quicksand_files';
	
	/* Metadata of the files is stored in a SQLite database. Define its location here. Make sure that this file is not accessable through the web. You can place it in the files directory.  */
	const DATABASE_FILE = './quicksand_files/_images.db';
	
	/* The user can choose how long the image should be online max. Add options here. */
	protected static $expireOptions = array(
		60		=> "1 minute",
		600		=> "10 minutes",
		3600	=> "1 hour",
		7200	=> "2 hours",
		86400	=> "1 day",
		172800	=> "2 days",
		604800	=> "1 week",
		1209600	=> "2 weeks",
		2592000	=> "1 month"
	);
	/* Which option should be selected by default? Set to the expiration time of one of the above options. */
	const EXPIRE_DEFAULT = 7200;
	
	/* Maximum bytes to be stored. If someone uploads a file and there is no space left, the oldest files are deleted. Set to 0 if you do not want to limit the storage size. */
	const MAX_STORAGE_SIZE = 67108864; // 64 MiB
	
	/* Maximum file size in bytes or 0 for unlimited. */
	const MAX_FILE_SIZE = 0;
	
	/* Id length. The minimum is used for short urls, the regular for private urls, but the id can be longer if there are many files. The maximum is the upper limit, which will not be exceeded. If you raise the maximum, delete the database because it will need to be recreated. */
	const ID_LENGTH_MIN = 3;
	const ID_LENGTH_REG = 9;
	const ID_LENGTH_MAX = 10;
	
	/* Maximum number of files you allow to upload at once. Set to 0 if you do not care. */
	const MAX_NUMBER_FILES = 0;
	
	/* cookies */
	const COOKIES_ENABLED = true;
	const COOKIE_PREFIX = 'quicksand_';
	
	/* Fancy urls may require rewrite urls. Go with the compatible urls if you don't know how to configure your webserver accordingly. Also you can configure own patterns. You can use {id} for the image or gallery id and {#filename} for the image filename prepended with a '#'. You should always start with either '?' or '/'. */
	/* compatible: */
	const URLPATTERN_IMAGE = '?img={id}{#filename}';
	const URLPATTERN_GALLERY = '?gallery={id}#gallery';
	/* fancy 1: */
	// const URLPATTERN_IMAGE = '/image/{id}{#filename}';
	// const URLPATTERN_GALLERY = '/gallery/{id}';
	/* You can use this nginx rules:
	rewrite ^/image/([0-9a-zA-Z]+)$ /?img=$1 last;
	rewrite ^/gallery/([0-9a-zA-Z]+)$ /?gallery=$1 last; */
	/* fancy 2: */
	// const URLPATTERN_IMAGE = '/{id}{#filename}';
	// const URLPATTERN_GALLERY = '/~{id}';
	/* You can use this nginx rules:
	rewrite ^/([0-9a-zA-Z]+)$ /?img=$1 last;
	rewrite ^/~([0-9a-zA-Z]+)$ /?gallery=$1 last; */
	
	/* allowed image types, see http://php.net/manual/function.image-type-to-mime-type.php */
	protected $supportedImages = array(
		IMAGETYPE_GIF,
		IMAGETYPE_JPEG,
		IMAGETYPE_PNG,
		IMAGETYPE_BMP,
		IMAGETYPE_ICO,
	);
	
	/* SQLite database object */
	protected $db;
	
	/* holds the singleton */
	private static $model;
	
	/* url data */
	public $url; // relative root url
	public $entityUrl; // relative root url that must be followed by a '/' or '?' when used
	public $server; // sheme, host and port
	
	
	private function __construct() {
		/* check files directory */
		$this->checkFilesDir();
		
		/* load SQLite database */
		$this->connectDatabase();
		
		/* delete expired files */
		$this->deleteExpired();
		
		/* tidy up (orphaned entries) sometimes */
		if (mt_rand(1, 20) === 10) {
			$this->tidyUpFiles();
		}
		
		/* create url data */
		$this->url = $_SERVER['SCRIPT_NAME'];
		while ($this->url !== '/' && strpos($_SERVER['REQUEST_URI'], $this->url) === false) {
			$this->url = dirname($this->url);
		}
		if (substr($this->url, -1) != '/' && $this->url != $_SERVER['SCRIPT_NAME']) {
			$this->url .= '/';
		}
		$this->entityUrl = $this->url === '/' ? '' : $this->url;
		$this->server = 'http'.(empty($_SERVER['HTTPS']) ? '' : 's').'://' // scheme
			.$_SERVER['SERVER_NAME'] // domain
			.((($_SERVER['SERVER_PORT'] == 80 && empty($_SERVER['HTTPS'])) || ($_SERVER['SERVER_PORT'] == 443 && !empty($_SERVER['HTTPS']))) ? '' : ':'.$_SERVER['SERVER_PORT']);
	}
	
	/* returns the singleton object and inits it if necessary */
	public static function core() {
		if (!self::$model) {
			self::$model = new static;
		}
		return self::$model;
	}
	
	/* processes an upload */
	public function upload($files, $expire = 0, $tiny = false) {
		
		/* trim files if too many */
		if (self::MAX_NUMBER_FILES && count($files['name']) >= self::MAX_NUMBER_FILES) {
			$files['name'] = array_slice($files['name'], 0, self::MAX_NUMBER_FILES, true);
		}
		
		/* check for errors */
		foreach ($files['error'] as $k => $error) {
			if (!isset($files['name'][$k])) continue; // skip needless files
			if ($error != UPLOAD_ERR_OK) {
				switch ($error) {
					case UPLOAD_ERR_INI_SIZE:
					case UPLOAD_ERR_FORM_SIZE:
						$msg = 'File is too big.'; break;
					case UPLOAD_ERR_PARTIAL:
						$msg = 'File was not uploaded completely. Try again.'; break;
					case UPLOAD_ERR_NO_FILE:
						$msg = 'Please select a file.'; break;
					case UPLOAD_ERR_NO_TMP_DIR:
						$msg = 'Tell the admin there is no temp directory.'; break;
					case UPLOAD_ERR_CANT_WRITE:
						$msg = 'No write permissions. Tell the admin.'; break;
					case UPLOAD_ERR_EXTENSION:
					default:
						$msg = ''; break;
				}
				throw new QuicksandException('Error during upload! '.$msg);
			}
		}
		
		/* check size and type */
		$totalSize = 0;
		foreach ($files['size'] as $k => $error) {
			if (!isset($files['name'][$k])) continue; // skip needless files
			
			/* is is secure to rely on the size? */
			$totalSize += $files['size'][$k] = filesize($files['tmp_name'][$k]);
			if (self::MAX_FILE_SIZE && $files['size'][$k] > self::MAX_FILE_SIZE) {
				throw new QuicksandException("File is too big.");
			}
			
			/* load image info */
			$info = @getimagesize($files['tmp_name'][$k]);
			if (!$info) {
				throw new QuicksandException('File is not an image: '.htmlentities($files['name'][$k], ENT_QUOTES, CHARSET));
			}
			$files['type'][$k] = $info[2];
		}
		
		/* clear needed space */
		$this->requireStorageSize($totalSize);
		
		/* prepare */
		$expire = isset(self::$expireOptions[$expire]) ? $expire : self::EXPIRE_DEFAULT;
		$delete_time = time() + $expire;
		$delete_code = self::getDeleteCode($delete_time);
		
		/* start */
		$this->db->exec("BEGIN TRANSACTION");
		
		/* gallery */
		$galleryId = count($files['name']) > 1 ? $this->createNewID(true, $tiny) : '';
		
		$savedFiles = array();
		try {
		
			/* process files */
			foreach ($files['name'] as $k => $name) {
				$imageId = $this->createNewID(false, $tiny && !$galleryId);
				
				/* save file */
				$path = self::getPath($imageId);
				if (!move_uploaded_file($files['tmp_name'][$k], $path)) {
					throw new QuicksandException('Could not save file! Tell the admin.');
				}
				$savedFiles[] = $path;
				
				/* save */
				$this->db->exec("INSERT INTO image (id, size, type, delete_time, delete_code, gallery_id)
					VALUES ('".$imageId."', ".$files['size'][$k].", ".$files['type'][$k].", ".$delete_time.", '".$delete_code."', '".$galleryId."')");
				
			}
		
		} catch (Exception $e) {
			/* rollback all saved files */
			foreach ($savedFiles as $path) {
				@unlink($path);
			}
			throw $e;
		}
		
		/* finish */
		$this->db->exec("COMMIT TRANSACTION");
		
		/* redirect */
		if ($galleryId) {
			header('Location: '.$this->entityUrl.self::galleryUrl($galleryId), true, 303);
		} else {
			header('Location: '.$this->entityUrl.self::imageUrl($imageId, reset($files['name'])), true, 303);
		}
		exit;
	}
	
	/* returns the used storage size in bytes */
	public function getUsedStorageSize() {
		return (int)$this->db->querySingle('SELECT SUM(size) FROM image');
	}
	
	/* clears the storage so that the required size gets free */
	protected function requireStorageSize($needed) {
		
		/* no limit */
		if (!self::MAX_STORAGE_SIZE) {
			return;
		}
		/* check if file exceeds storage size */
		if ($needed > self::MAX_STORAGE_SIZE) {
			throw new QuicksandException("File exceeds storage size.");
		}
		
		/* delete until we have enough space */
		$this->db->exec("BEGIN TRANSACTION");
		$used = $this->getUsedStorageSize() + $needed;
		for ($n = 1; $used > self::MAX_STORAGE_SIZE; $n *= 2) {
			/* delete up to n files, double each round */
			$result = $this->db->query('SELECT id, size FROM image ORDER BY ROWID LIMIT '.$n);
			if ($result) {
				while ($entry = $result->fetchArray(SQLITE3_ASSOC)) {
					$used -= $entry['size'];
					$this->delete($entry['id']);
					if ($used <= self::MAX_STORAGE_SIZE) {
						break 2;
					}
				}
			}
		}
		$this->db->exec("COMMIT TRANSACTION");
	}
	
	/* returns an unused id */
	protected function createNewID($gallery = false, $tiny = false) {
		/* make it longer every 3 tries */
		for ($l = $tiny ? self::ID_LENGTH_MIN : self::ID_LENGTH_REG; $l <= self::ID_LENGTH_MAX; $l++) {
			/* 3 tries; don't abort if max length is reached */
			for ($i = 3; $i || $l == self::ID_LENGTH_MAX; --$i) {
				$id = random_string($l, $tiny ? 16 : 62);
				if (($gallery && !$this->galleryExists($id)) || (!$gallery && !$this->fileExists($id))) {
					return $id;
				}
			}
		}
	}
	
	/* returns the delete code of the current user */
	public static function getDeleteCode($time = 0) {
		/* check if user has one or create new */
		if (self::COOKIES_ENABLED && !empty($_COOKIE[self::COOKIE_PREFIX.'delete_code'])) {
			list($code, $oldTime) = explode('-', $_COOKIE[self::COOKIE_PREFIX.'delete_code']);
		} else {
			$code = random_string(32);
			$oldTime = 0;
		}
		if (self::COOKIES_ENABLED && $oldTime < $time) {
			setcookie(self::COOKIE_PREFIX.'delete_code', $code.'-'.$time, $time);
		}
		return $code;
	}
	
	/* creates the files dir if necessary and check if it is accessable */
	protected function checkFilesDir() {
		$perms = @fileperms(self::FILES_DIR);
		/* create if not exist */
		if ($perms === false) {
			if (!mkdir(self::FILES_DIR, 0700, true)) {
				throw new QuicksandException("Cannot create files dir.");
			}
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
		
		/* check if tables need to be created */
		$createTables = !is_file(self::DATABASE_FILE);
		
		/* connect */
		$this->db = new SQLite3(self::DATABASE_FILE);
		if (!$this->db) {
			throw new QuicksandException("Initializing SQLite database failed. Tell the admin.");
		}
		
		/* create tables */
		if ($createTables) {
			$this->db->exec("CREATE TABLE image(
				id CHAR(".self::ID_LENGTH_MAX.") PRIMARY KEY,
				size INTEGER,
				type INTEGER,
				delete_time INTEGER,
				delete_code CHAR(32),
				gallery_id CHAR(".self::ID_LENGTH_MAX.")
			)");
		}
		
		/* wait up to 20 seconds if another instance is using the db */
		$this->db->busyTimeout(20000);
	}
	
	/* removes too old images */
	protected function deleteExpired() {
		$result = $this->db->query('SELECT id FROM image WHERE delete_time < '.time());
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_NUM)) {
				$this->delete($entry[0]);
			}
		}
	}
	
	/* finds orphaned files in the database and on the disk */
	protected function tidyUpFiles() {
		/* load valid ids */
		$validIds = array();
		$result = $this->db->query('SELECT id FROM image');
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_NUM)) {
				$validIds[] = $entry[0];
			}
		}
		
		/* remove entries with missing file */
		foreach ($validIds as $id) {
			if (!is_file(self::getPath($id))) {
				$this->delete($id);
			}
		}
		
		/* delete from list what is not in the database */
		if ($dh = opendir(self::FILES_DIR)) {
			while (($id = readdir($dh)) !== false) {
				if (preg_match('#^[0-9a-zA-Z]+$#', $id) && !in_array($id, $validIds) && $id != self::DATABASE_FILE) {
					$this->delete($id);
				}
			}
			closedir($dh);
		}
	}
	
	/* deletes a file from disk and removes it from the database */
	protected function delete($id) {
		@unlink(self::getPath($id));
		$this->db->exec("DELETE FROM image WHERE id = '".SQLite3::escapeString($id)."'");
	}
	
	/* removes a gallery */
	protected function deleteGallery($id) {
		$result = $this->db->query('SELECT id FROM image WHERE gallery_id = "'.SQLite3::escapeString($id).'"');
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_NUM)) {
				$this->delete($entry[0]);
			}
		}
	}
	
	/* removes the users delete code if not needed */
	protected function checkUserDelcode($delete_code) {
		if (self::COOKIES_ENABLED && !$this->db->querySingle('SELECT COUNT(size) FROM image WHERE delete_code = "'.SQLite3::escapeString($delete_code).'"')) {
			setcookie(self::COOKIE_PREFIX.'delete_code', '', 1);
		}
	}
	
	/* deletes a user's file if providing the right delete code */
	public function deleteUserFile($id, $delete_code) {
		$num = $this->db->querySingle('SELECT COUNT(*) FROM image
			WHERE id = "'.SQLite3::escapeString($id).'"
				AND delete_code = "'.SQLite3::escapeString($delete_code).'"');
		if (!$num) {
			throw new QuicksandException("Found nothing to delete.");
		}
		$this->delete($id);
		$this->checkUserDelcode($delete_code);
		header("Location: ".$this->server.$this->url, true, 303);
		exit;
	}
	
	/* deletes a user's gallery if providing the right delete code */
	public function deleteUserGallery($id, $delete_code) {
		$num = $this->db->querySingle('SELECT COUNT(*) FROM image
			WHERE gallery_id = "'.SQLite3::escapeString($id).'"
				AND delete_code = "'.SQLite3::escapeString($delete_code).'"');
		if (!$num) {
			throw new QuicksandException("Found nothing to delete.");
		}
		$this->deleteGallery($id);
		$this->checkUserDelcode($delete_code);
		header("Location: ".$this->server.$this->url, true, 303);
		exit;
	}
	
	/* returns an array with the uploads of the user */
	public function getUserUploads() {
		if (!self::COOKIES_ENABLED) {
			return array();
		}
		
		/* load form database */
		$uploads = array();
		$delete_code = $this->getDeleteCode();
		$result = $this->db->query('
			SELECT *
			FROM image
			WHERE delete_code = "'.SQLite3::escapeString($delete_code).'"
				AND gallery_id = ""
			ORDER BY ROWID DESC');
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_ASSOC)) {
				$uploads[$entry['id']] = $entry;
			}
		}
		return $uploads;
	}
	
	/* returns an array with the galleries of the user */
	public function getUserGalleries() {
		if (!self::COOKIES_ENABLED) {
			return array();
		}
		
		/* load form database */
		$galleries = array();
		$delete_code = $this->getDeleteCode();
		$result = $this->db->query('
			SELECT gallery_id, delete_time, delete_code, COUNT(*) as num_images
			FROM image
			WHERE delete_code = "'.SQLite3::escapeString($delete_code).'"
				AND gallery_id != ""
			GROUP BY gallery_id
			ORDER BY ROWID DESC');
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_ASSOC)) {
				$galleries[$entry['gallery_id']] = $entry;
			}
		}
		return $galleries;
	}
	
	/* returns the path to the file with the given id */
	protected static function getPath($id) {
		return self::FILES_DIR.DIRECTORY_SEPARATOR.$id;
	}
	
	/* returns the data of a file and does some checks */
	protected function getFile($id) {
		/* load from database */
		$data = $this->db->querySingle('SELECT * FROM image WHERE id = "'.SQLite3::escapeString($id).'"', true);
		$path = self::getPath($id);
		if (!$data || !is_file($path)) {
			/* delete leftovers */
			$this->delete($id);
			throw new QuicksandException("The file you are looking for does not exist (anymore).");
		}
		return $data + array('path' => $path);
	}
	
	/* checks if the file exists */
	protected function fileExists($id) {
		return !!$this->db->querySingle('SELECT COUNT(*) FROM image WHERE id = "'.SQLite3::escapeString($id).'"');
	}
	
	/* checks if the gallery exists */
	protected function galleryExists($id) {
		return !!$this->db->querySingle('SELECT COUNT(*) FROM image WHERE gallery_id = "'.SQLite3::escapeString($id).'"');
	}
	
	/* outputs a stored file */
	public function displayFile($id) {
		$data = $this->getFile($id);
		
		/* caching */
		if ($modified = filemtime($data['path'])) {
			/* check if client gives a time of its cached file and if it is up to date */
			if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) > $modified) {
				header('HTTP/1.1 304 Not Modified', true, 304);
				header('Cache-Control: max-age='.($data['delete_time'] - time()).', public');
				exit;
			}
			header('Last-Modified: '.date(DATE_RFC1123, $modified));
		}
		
		/* output the file */
		header('Content-Type: '.image_type_to_mime_type($data['type']));
		header('Content-Length: '.$data['size']);
		header('Content-Disposition: filename="'.$id.image_type_to_extension($data['type']).'";');
		header('Cache-Control: max-age='.($data['delete_time'] - time()).', public');
		readfile(self::getPath($id));
		exit;
	}
	
	/* outputs a stored file */
	public function displayGallery($id) {
		/* load from database */
		$result = $this->db->query('SELECT * FROM image
			WHERE gallery_id = "'.SQLite3::escapeString($id).'"
				AND gallery_id != ""');
		$files = array();
		if ($result) {
			while ($entry = $result->fetchArray(SQLITE3_ASSOC)) {
				$files[] = $entry;
			}
		}
		if (!count($files)) {
			throw new QuicksandException("The gallery you are looking for does not exist (anymore).");
		}
		
		?><!DOCTYPE html>
<html>
<head>
	<meta http-equiv="content-type" content="text/html; charset=<?php echo CHARSET; ?>">
	<link rel="shortcut icon" type="image/x-icon" href="<?php echo $this->url; ?>?action=favicon">
	<title>Gallery <?php echo $id; ?></title>
	<style type="text/css">
	<!--
	body { background-image: url(data:image/gif;base64,R0lGODlhEAAQAKECAGZmZpmZmf///////yH5BAEKAAIALAAAAAAQABAAAAIfjG+gq4jM3IFLJgpswNly/XkcBpIiVaInlLJr9FZWAQA7); }
	a { display: inline-block; float: left; margin: 5px; }
	img { max-width: 600px; max-height: 600px; border: 1px solid white; }
	-->
	</style>
</head>
<body>
	<div>
		<?php foreach ($files as $file) {
			$imageUrl = $this->entityUrl.self::imageUrl($file['id']);
			?><a href="<?php echo $imageUrl; ?>">
				<img alt="<?php echo $file['id']; ?>" src="<?php echo $imageUrl; ?>">
			</a><?php
		} ?>
		<a href="<?php echo $this->url; ?>">
			<img alt="" src="<?php echo $this->url; ?>?action=favicon">
		</a>
	</div>
</body>
</html><?php
	exit;
	}
	
	/* actions */
	public function action($action) {
		switch ($action) {
			case 'download':
				self::displaySource();
			case 'favicon':
				self::displayEmbeddedFile("favicon");
			default:
				throw new QuicksandException("Action is unknown.");
		}
	}
	
	/* outputs the favicon */
	public static function displayEmbeddedFile($file = "") {
		/* caching */
		if ($lastmodified = filemtime(__FILE__)) {
			/* check if client gives a time of its cached file and if it is up to date */
			if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) > $lastmodified) {
				header('HTTP/1.1 304 Not Modified', true, 304);
				exit;
			}
			header('Last-Modified: '.date(DATE_RFC1123, $lastmodified)); // for caching
		}
		
		/* output the file */
		switch ($file) {
			case 'favicon':
				header('Content-Type: image/png');
				header('Content-Length: 439');
				echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAABfklEQVQ4y63TP2uTURQG8N8JBVvUqrT+mWqwCOKgq5sO7kU3F2c/wFuiH0GNbh108wtkdhVExEFiEcFBfLFSES3EdmkIzXHIm5hG08lnuRfOc859nnPODWNotEqTuH+z7iDOjH9gMukgxPCSTYltvMV3vEMTvSimF/ijIBHmk2vSWoQHmEMfe9MsTCqAr7iCM1iPQi+bFbmYYiGb5vELMl0Q2sHpKr6CN/iCiyij8HNYoFadc6OK4VvwEbfRSR7jemX3Kp73m241WqVGq/xLwQ+cRyfTVoSFMbXHcTjZCLo4EYVurfK2jV0sJItSOZ6cg9hKFDaDrUrxqUarHFmAO5nWgxvCJaxl2pGeBR/Qrngnq6qz+6YA/YeWIyzhPbrSrDCDz8kRLAabFX3p7rlyY1yBe8vlJ7xI9pJd4Sxe4VjQk16P7c3O+BRGKxyFvtSJgbp2FOpVfx4ZqBsu3fS/UFvVN+i0bJKsBvXgJS4LRw/8TPuQDglP8LR6OfxP/AYFioEnuJlWhAAAAABJRU5ErkJggg==');
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
	
	/* returns the relative url for a image */
	public static function imageUrl($id, $filename = '') {
		return strtr(self::URLPATTERN_IMAGE, array(
			'{id}' => $id,
			'{#filename}' => $filename === '' ? '' : '#'.$filename,
		));
	}
	
	/* returns the relative url for a gallery */
	public static function galleryUrl($id) {
		return strtr(self::URLPATTERN_GALLERY, array('{id}' => $id));
	}
	
	/* detects maximal upload file size */
	public static function getMaxUploadFileSize() {
		return min_not_null(array(self::MAX_FILE_SIZE, ini_get_bytes('upload_max_filesize'), self::getMaxUploadSize()));
	}
	
	/* detects maximal upload size */
	public static function getMaxUploadSize() {
		return min_not_null(array(self::MAX_STORAGE_SIZE, ini_get_bytes('post_max_size')));
	}
	
	/* detects maximal upload file number */
	public static function getMaxUploadFileNumber() {
		return min_not_null(array(self::MAX_NUMBER_FILES, ini_get("suhosin.upload.max_uploads"), ini_get("max_file_uploads")));
	}
	
	/* returns the expire options */
	public static function getExpireOptions() {
		return self::$expireOptions;
	}
}

class QuicksandException extends Exception {
	/* you can catch this class without catching Exception */
}

/* help functions */

/* generates a random string [0-9a-zA-Z] */
function random_string($length, $base = 62) {
	$str = '';
	while ($length--) {
		$x = mt_rand(1, min($base, 62));
		$str .= chr($x + ($x > 10 ? $x > 36 ? 28 : 86 : 47));
	}
	return $str;
}

/* like min(), but casts to int and ignores 0 */
function min_not_null(Array $values) {
	return min(array_diff(array_map('intval', $values), array(0)));
}

/* like ini_get for returning bytes */
function ini_get_bytes($option) {
	if (preg_match("/^([0-9]+)(|k|m|g)$/i", ini_get($option), $match)) {
		$u = array(''=>0, 'k'=>10, 'm'=>20, 'g'=>30);
		return (int)$match[1] << $u[strtolower(substr($match[2], 0, 1))];
	}
}

/* makes the bytes better readable */
function readable_bytes($bytes) {
	$units = array("B", "KiB", "MiB", "GiB");
	for ($i = (count($units) - 1) * 10; $i >= 0; $i -= 10) {
		if (!$i || $bytes >> $i) {
			return round($bytes / (1 << $i), 2)." ".$units[$i/10];
		}
	}
}

/* start Quicksand */
$uploads = $galleries = array();
try {
	
	/* create instance; Quicksand will already delete expired files, etc. */
	$quicksand = Quicksand::core();
	
	/* actions */
	if (isset($_GET['action'])) {
		$quicksand->action($_GET['action']);
	}
	
	/* delete image */
	if (isset($_GET['delimage'], $_GET['delcode'])) {
		$quicksand->deleteUserFile($_GET['delimage'], $_GET['delcode']);
	}
	
	/* delete gallery */
	if (isset($_GET['delgallery'], $_GET['delcode'])) {
		$quicksand->deleteUserGallery($_GET['delgallery'], $_GET['delcode']);
	}
	
	/* display gallery */
	if (isset($_GET['gallery'])) {
		$quicksand->displayGallery($_GET['gallery']);
	}
	
	/* display or delete image */
	if (isset($_GET['img'])) {
		$quicksand->displayFile($_GET['img']);
	}
	
	/* save file */
	if (isset($_FILES['image'])) {
		$quicksand->upload($_FILES['image'], (int)$_POST['expire'], !!$_POST['short']);
	}
	
	$uploads = $quicksand->getUserUploads();
	$galleries = $quicksand->getUserGalleries();

} catch (QuicksandException $e) {
	$errorMessage = $e->getMessage();
}

?><!DOCTYPE html>
<html>
<head>
	<meta http-equiv="content-type" content="text/html; charset=<?php echo CHARSET; ?>">
	<meta name="description" lang="en" content="a short time image hosting tool">
	<meta name="keywords" lang="en" content="quicksand,image,upload,hosting,share,temporary">
	<link rel="shortcut icon" type="image/png" href="<?php echo $quicksand->url; ?>?action=favicon">
	<title>Quicksand Image Hosting Tool</title>
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
	<?php if (Quicksand::COOKIES_ENABLED): ?>
	<script type="text/javascript">
	function saveExpire(value) {
		var expire = new Date();
		expire.setTime(expire.getTime() + 2592000000);
		document.cookie = "<?php echo Quicksand::COOKIE_PREFIX; ?>expire=" + value + "; expires=" + expire.toGMTString();
	}
	function loadExpire() {
		value = document.cookie.match(/<?php echo Quicksand::COOKIE_PREFIX; ?>expire=[0-9]+/);
		if (value != null) {
			document.forms[0].expire.value = value[0].substr(17);
		}
	}
	</script>
	<?php endif; ?>
</head>
<body<?php if (Quicksand::COOKIES_ENABLED): ?> onload="loadExpire()"<?php endif; ?>>
	<div id="main">
		
		<header>
			<h1><a href="<?php echo $quicksand->url; ?>">Quicksand</a></h1>
			<h2>Share images with your friends</h2>
		</header>
		
		<p title="Quicksand is a tool, not a service.">Providing people with slow internet connection and many friends with an image sharing tool.</p>
		<p>There is no guaranty at all! Don't do anything that is illegal! You're responsible for your uploaded files!</p>
		<?php
		$maxFiles = Quicksand::getMaxUploadFileNumber();
		$maxUp = Quicksand::getMaxUploadSize();
		$maxUpFile = Quicksand::getMaxUploadFileSize();
		$storageSize = Quicksand::MAX_STORAGE_SIZE;
		?><p>You can select <?php echo $maxFiles && $maxFiles < 40 ? $maxFiles == 1 ? "one file" : $maxFiles." files" : "several files"; ?> up to <?php echo readable_bytes($maxUp); if ($maxUpFile < $maxUp): ?> in total and <?php echo readable_bytes($maxUpFile); ?> per file<?php endif; ?> (png/jpg/gif/bmp/ico).</p>
		
		<?php if (!empty($errorMessage)): ?><p class="error"><?php echo $errorMessage; ?></p><?php endif; ?>

		<form id="upload" action="<?php echo $quicksand->url; ?>" method="post" enctype="multipart/form-data">
			<p<?php if (!$maxFiles || $maxFiles > 1): ?> title="You can select several files at once."<?php endif; ?>>
				1 Select <input name="image[]" type="file"<?php if (!$maxFiles || $maxFiles > 1): ?> multiple<?php endif; ?> required>
			</p>
			<p title="After a maximum of this time the image link will stop providing your image. Images are deleted when this web page is called.">
				2 Expire in ...
				<select name="expire" onchange="saveExpire(this.value)">
					<?php foreach (Quicksand::getExpireOptions() as $expire => $label) { ?><option value="<?php echo $expire; ?>"<?php echo ($expire == Quicksand::EXPIRE_DEFAULT) ? " selected" : ""; ?>><?php echo $label; ?></option><?php } ?>

				</select>
				<?php if (Quicksand::COOKIES_ENABLED): ?><span class="notice">Your selection is saved in a browser cookie via JavaScript for 30 days. It is only used to remember your selection.</span><?php endif; ?>
			</p>
			<p>3 Upload:
				<button name="short" value="0" type="submit">Private url</button>
				or
				<button name="short" value="1" type="submit">Short url</button>
				<span class="notice">A short url is easy to guess and has a high chance to be reused. A private url is much longer to prevent it from being guessed.</span>
			</p>
		</form>
		
		<p title="This is the total storage size that is shared by all users. If it exceeds, new images edge out old ones.">Shared storage: <?php echo readable_bytes($quicksand->getUsedStorageSize()); echo $storageSize ? " / ".readable_bytes($storageSize) : ""; ?></p>
		
		<?php if (Quicksand::COOKIES_ENABLED): ?>
			<?php if (count($uploads) || count($galleries)): ?>
			<div id="uploads">
				Your uploads:
				<ul>
				<?php foreach ($galleries as $id => $data): ?>
					<li>
						<a href="?delgallery=<?php echo $id; ?>&amp;delcode=<?php echo $data['delete_code']; ?>"><span class="delete"></span></a>
						<a href="<?php echo $quicksand->entityUrl, Quicksand::galleryUrl($id); ?>">gallery</a>,
						<?php echo $data['num_images']; ?> images,
						<?php echo date(DATE_ATOM, $data['delete_time']); ?>
					</li>
				<?php endforeach; ?>
				<?php foreach ($uploads as $id => $data): ?>
					<li>
						<a href="?delimage=<?php echo $id; ?>&amp;delcode=<?php echo $data['delete_code']; ?>"><span class="delete"></span></a>
						<a href="<?php echo $quicksand->entityUrl, Quicksand::imageUrl($id); ?>"><?php echo image_type_to_extension($data['type'], false); ?></a>,
						<?php echo readable_bytes($data['size']); ?>,
						<?php echo date(DATE_ATOM, $data['delete_time']); ?>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
			<span class="notice">You are identified via browser cookie to provide you with a list of your uploads.</span>
		<?php endif; ?>
		
		<footer>
			<p id="by">
				<a href="http://code.teele.eu/quicksand">Quicksand was coded by Teelevision</a> • <a href="<?php echo $quicksand->url; ?>?action=download" title="Download an exact copy of this script. You are free to host it yourself. See the license.">Download source</a>
			</p>
			<?php if (LEGAL_NOTICE != ''): ?>
			<p id="legal_notice">
				<?php echo LEGAL_NOTICE; ?>
			</p>
			<?php endif; ?>
		</footer>
		
	</div>
</body>
</html>