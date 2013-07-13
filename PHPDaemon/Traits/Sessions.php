<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\FS\File;
use PHPDaemon\FS\FileSystem;

/**
 * Sessions
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait Sessions {
	/**
	 * Session ID
	 * @var boolean
	 */
	protected $sessionId;

	/** @var int */
	protected $sessionStartTimeout = 10;

	/** @var bool */
	protected $sessionStarted = false;
	/** @var bool */
	protected $sessionFlushing = false;
	/** @var */
	protected $sessionFp;

	/**
	 * Is session started?
	 * @return bool
	 */
	public function sessionStarted() {
		return $this->sessionStarted;
	}

	/**
	 * Deferred event 'onSessionStart'
	 * @return callable
	 */
	public function onSessionStartEvent() {
		return function ($sessionStartEvent) {
			/** @var DeferredEventCmp $sessionStartEvent */
			$name = ini_get('session.name');
			$sid  = static::getString($this->attrs->cookie[$name]);
			if ($sid === '') {
				$this->sessionStartNew(function () use ($sessionStartEvent) {
					$sessionStartEvent->setResult();
				});
				return;
			}

			$this->onSessionRead(function ($session) use ($sessionStartEvent) {
				if ($this->attrs->session === false) {
					$this->sessionStartNew(function () use ($sessionStartEvent) {
						$sessionStartEvent->setResult();
					});
				}
				$sessionStartEvent->setResult(true);
			});
		};
	}

	/**
	 * Deferred event 'onSessionRead'
	 * @return callable
	 */
	public function onSessionReadEvent() {

		return function ($sessionEvent) {
			/** @var DeferredEventCmp $sessionEvent */
			$name = ini_get('session.name');
			$sid  = static::getString($this->attrs->cookie[$name]);
			if ($sid === '') {
				$sessionEvent->setResult();
				return;
			}
			if ($this->attrs->session) {
				$sessionEvent->setResult();
				return;
			}

			$this->sessionRead($sid, function ($data) use ($sessionEvent) {
				if ($data === false) {
					$this->sessionStartNew(function () use ($sessionEvent) {
						$sessionEvent->setResult();
					});
					return;
				}
				$this->sessionDecode($data);
				$sessionEvent->setResult();
			});
		};
	}

	/**
	 * Reads session data
	 * @param $sid
	 * @param callable $cb
	 * @return void
	 */
	public function sessionRead($sid, $cb = null) {
		FileSystem::open(FileSystem::genRndTempnamPrefix(session_save_path(), 'php') . basename($sid), 'r+!', function ($fp) use ($cb) {
			if (!$fp) {
				call_user_func($cb, false);
				return;
			}
			$fp->readAll(function ($fp, $data) use ($cb) {
				$this->sessionFp = $fp;
				call_user_func($cb, $data);
			});
		});
	}

	/**
	 * Commmit session data
	 * @param callable $cb
	 * @return void
	 */
	public function sessionCommit($cb = null) {
		if (!$this->sessionFp || $this->sessionFlushing) {
			if ($cb) {
				call_user_func(false);
			}
			return;
		}
		$this->sessionFlushing = true;
		$data                  = $this->sessionEncode();
		$l                     = strlen($data);
		$this->sessionFp->write($data, function ($file, $result) use ($l, $cb) {
			$file->truncate($l, function ($file, $result) use ($cb) {
				$this->sessionFlushing = false;
				if ($cb) {
					call_user_func($cb, true);
				}
			});
		});
	}

	/**
	 * Session start
	 * @param bool $force_start
	 * @return void
	 */
	protected function sessionStart($force_start = true) {
		if ($this->sessionStarted) {
			return;
		}
		$this->sessionStarted = true;

		$f = true; // hack to avoid a sort of "race condition"
		$this->onSessionStart(function ($event) use (&$f) {
			$f = false;
			$this->wakeup();
		});
		if ($f) {
			$this->sleep($this->sessionStartTimeout);
		}
	}

	/**
	 * Start new session
	 * @param callable $cb
	 */
	protected function sessionStartNew($cb = null) {
		FileSystem::tempnam(session_save_path(), 'php', function ($fp) use ($cb) {
			if (!$fp) {
				call_user_func($cb, false);
				return;
			}
			$this->sessionFp = $fp;
			$this->sessionId = substr(basename($fp->path), 3);
			$this->setcookie(
				ini_get('session.name')
				, $this->sessionId
				, ini_get('session.cookie_lifetime')
				, ini_get('session.cookie_path')
				, ini_get('session.cookie_domain')
				, ini_get('session.cookie_secure')
				, ini_get('session.cookie_httponly')
			);
			call_user_func($cb, true);
		});
	}

	/**
	 * Encodes session data
	 * @return bool|string
	 */
	protected function sessionEncode() {
		$type = ini_get('session.serialize_handler');
		if ($type === 'php') {
			return serialize($this->attrs->session);
		}
		if ($type === 'php_binary') {
			return igbinary_serialize($this->attrs->session);
		}
		return false;
	}

	/**
	 * Decodes session data
	 * @param $str
	 * @return bool
	 */
	protected function sessionDecode($str) {
		$type = ini_get('session.serialize_handler');
		if ($type === 'php') {
			$this->attrs->session = unserialize($str);
			return true;
		}
		if ($type === 'php_binary') {
			$this->attrs->session = igbinary_unserialize($str);
			return true;
		}
		return false;
	}
}