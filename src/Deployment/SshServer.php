<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * SSH server.
 *
 * It has a dependency on the error handler that converts PHP errors to ServerException.
 */
class SshServer implements Server
{
	public ?int $filePermissions = null;
	public ?int $dirPermissions = null;

	/** @var resource */
	private $connection;

	/** @var resource */
	private $sftp;

	/** see parse_url() */
	private array $url;
	private ?string $publicKey;
	private ?string $privateKey;
	private ?string $passPhrase;


	/**
	 * @param string $url sftp://...
	 * @throws \Exception
	 */
	public function __construct(
		string $url,
		?string $publicKey = null,
		#[\SensitiveParameter]
		?string $privateKey = null,
		#[\SensitiveParameter]
		?string $passPhrase = null,
	) {
		if (!extension_loaded('ssh2')) {
			throw new \Exception('PHP extension SSH2 is not loaded.');
		}
		$this->url = parse_url($url);
		if (!isset($this->url['scheme'], $this->url['user'], $this->url['host']) || $this->url['scheme'] !== 'sftp') {
			throw new \InvalidArgumentException('Invalid URL or missing username');
		}
		$this->publicKey = $publicKey;
		$this->privateKey = $privateKey;
		$this->passPhrase = $passPhrase;
	}


	/**
	 * Connects to FTP server.
	 * @throws ServerException
	 */
	public function connect(): void
	{
		if ($this->connection) { // reconnect?
			@ssh2_disconnect($this->connection); // @ may fail
		}
		$this->connection = Safe::ssh2_connect($this->url['host'], $this->url['port'] ?? 22);
		if (isset($this->url['pass'])) {
			$pass = $this->url['pass'];
			if ($pass === 'STDIN') {
				$pass = Helpers::getHiddenInput("Enter password for {$this->url['user']}: ");
			}
			Safe::ssh2_auth_password($this->connection, urldecode($this->url['user']), urldecode($pass));
		} elseif ($this->publicKey && $this->privateKey) {
			$passPhrase = $this->passPhrase;
			if ($passPhrase === 'STDIN') {
				$passPhrase = Helpers::getHiddenInput("Enter password for private key: ");
			}
			Safe::ssh2_auth_pubkey_file($this->connection, urldecode($this->url['user']), $this->publicKey, $this->privateKey, (string) $passPhrase);
		} else {
			Safe::ssh2_auth_agent($this->connection, urldecode($this->url['user']));
		}
		$this->sftp = Safe::ssh2_sftp($this->connection);
	}


	/**
	 * Reads remote file from FTP server.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		Safe::copy('ssh2.sftp://' . (int) $this->sftp . $remote, $local);
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		$size = max(filesize($local), 1);
		$len = 0;
		$i = Safe::fopen($local, 'rb');
		$o = Safe::fopen('ssh2.sftp://' . (int) $this->sftp . $remote, 'wb');
		while (!feof($i)) {
			$s = Safe::fread($i, 10000);
			if (Safe::fwrite($o, $s, strlen($s)) !== strlen($s)) {
				throw new ServerException('Unable to write to server');
			}
			$len += strlen($s);
			if ($progress) {
				$progress($len * 100 / $size);
			}
		}
		if ($this->filePermissions) {
			Safe::ssh2_sftp_chmod($this->sftp, $remote, $this->filePermissions);
		}
	}


	/**
	 * Removes file from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeFile(string $file): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $file)) {
			Safe::unlink($path);
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @throws ServerException
	 */
	public function renameFile(string $old, string $new): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $new)) {
			$perms = fileperms($path);
			$this->removeFile($new);
		}
		Safe::ssh2_sftp_rename($this->sftp, $old, $new);
		if (!empty($perms)) {
			Safe::ssh2_sftp_chmod($this->sftp, $new, $perms);
		}
	}


	/**
	 * Creates directories on FTP server.
	 * @throws ServerException
	 */
	public function createDir(string $dir): void
	{
		if (trim($dir, '/') !== '' && !file_exists('ssh2.sftp://' . (int) $this->sftp . $dir)) {
			Safe::ssh2_sftp_mkdir($this->sftp, $dir, $this->dirPermissions ?: 0777, true);
		}
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $dir)) {
			Safe::rmdir($path);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @throws ServerException
	 */
	public function purge(string $dir, ?callable $progress = null): void
	{
		if (!file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $dir)) {
			return;
		}

		$dirs = $entries = [];
		$iterator = Safe::dir($path);
		while (($entry = $iterator->read()) !== false) {
			if ($entry !== '.' && $entry !== '..') {
				$entries[] = $entry;
			}
		}

		foreach ($entries as $entry) {
			if (is_dir("$path/$entry")) {
				$dirs[] = $tmp = '.delete' . uniqid() . count($dirs);
				Safe::rename("$path/$entry", "$path/$tmp");
			} else {
				Safe::unlink("$path/$entry");
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge("$dir/$subdir", $progress);
			Safe::rmdir("$path/$subdir");
		}
	}


	/**
	 * Changes file permissions.
	 * @throws ServerException
	 */
	public function chmod(string $path, int $permissions): void
	{
		Safe::ssh2_sftp_chmod($this->sftp, $path, $permissions);
	}


	/**
	 * Returns current directory.
	 */
	public function getDir(): string
	{
		return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		$stream = Safe::ssh2_exec($this->connection, $command);
		Safe::stream_set_blocking($stream, true);
		$out = Safe::stream_get_contents($stream);
		fclose($stream);
		return $out;
	}
}
