<?php

namespace Wagnerwagner\Merx;

use DateTime;
use Kirby\Cms\App;
use Kirby\Filesystem\F;
use Throwable;

/**
 * Logging interface
 *
 * @author Tobias Wolf
 * @copyright Wagnerwagner GmbH
 */
class Logger {
	public static string $appendix = '-merx';

	public static int $randomMaxThreshold = 100;

	/**
	 * Delete old Merx logs
	 *
	 * @param string $threshold relative date string
	 */
	public static function deleteOldLogs(string $threshold = '-1 year'): void
	{
		try {
			$kirby = App::instance();
			$dir = $kirby->root('logs');
			$pattern = $dir . '/*' . static::$appendix . '.log';
			$threshold = new DateTime($threshold);

			foreach (glob($pattern) as $logFile) {
				$filename = basename($logFile);

				// Extract date from filename (YYYY-MM-DD)
				if (!preg_match('/^(\d{4}-\d{2}-\d{2})-merx\.log$/', $filename, $matches)) {
					continue;
				}

				try {
					$dateOfLogFile = new DateTime($matches[1]);
				} catch (Throwable) {
					continue;
				}

				if ($dateOfLogFile < $threshold) {
					unlink($logFile);
					self::log([
						'message' => 'Deleted old log file.',
						'filename' => $filename,
					]);
				}
			}
		} catch (Throwable $ex) {
			if ($kirby->option('debug') === true) {
				throw $ex;
			}
		}
	}

	/**
	 * Create log in Kirby’s log directory
	 *
	 * @param string|int|array $content
	 * @param string $info should be one of the eight RFC 5424 levels
	 */
	public static function log(string|int|array $content, $level = 'info'): void
	{
		try {
			$kirby = App::instance();

			$level = strtoupper($level);

			if (is_array($content) || is_object($content)) {
				$content = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
			}

			$dir = $kirby->root('logs');
			$filename = date('Y-m-d') . self::$appendix . '.log';

			// delete old logs every 100th method call
			if (rand(1, static::$randomMaxThreshold) === 1) {
				self::deleteOldLogs();
			}

			F::append(
				$dir . '/' . $filename,
				'[' . date('c') . '] ' . $level . ' ' . $content . "\n"
			);
		} catch (Throwable $ex) {
			if ($kirby->option('debug') === true) {
				throw $ex;
			}
		}
	}

}
