<?php
/**
 * Detect direct database write intent without persisting SQL or values.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\DatabaseWriteSignalRepository;
use Throwable;
use wpdb;

final class SqlWriteSentry
{
	private const MAX_UNIQUE_SIGNALS = 50;

	/** @var array<string, int> */
	private array $signalIds = array();
	private bool $observing = false;

	public function __construct(
		private readonly wpdb $database,
		private readonly CaptureRepository $captures,
		private readonly DatabaseWriteSignalRepository $signals,
		private readonly SourceAttributor $source,
		private readonly RequestContext $request
	) {
	}

	public function register(): void
	{
		add_filter('query', array($this, 'observe'), PHP_INT_MAX, 1);
	}

	public function observe(string $query): string
	{
		$write = $this->parseWrite($query);
		if (null === $write || $this->observing || $this->isOwnTable($write['table'])) {
			return $query;
		}

		$this->observing = true;
		try {
			$sessionId = $this->captures->activeId();
			if (
				null === $sessionId
				|| $this->isUncorrelatedCoreCronRequest()
				|| $this->isManagedOptionsApiWrite($write['table'])
			) {
				return $query;
			}

			$this->record($sessionId, $write['operation'], $write['table']);
		} catch (Throwable $error) {
			$this->reportCaptureError($error);
		} finally {
			$this->observing = false;
		}

		return $query;
	}

	/**
	 * @return array{operation: string, table: string}|null
	 */
	private function parseWrite(string $query): ?array
	{
		$head = substr(ltrim($query), 0, 512);
		$patterns = array(
			'I' => array('insert', '/^INSERT(?:\s+IGNORE)?\s+INTO\s+`?([A-Za-z0-9_$.\-]+)`?/i'),
			'R' => array('replace', '/^REPLACE\s+INTO\s+`?([A-Za-z0-9_$.\-]+)`?/i'),
			'U' => array('update', '/^UPDATE\s+`?([A-Za-z0-9_$.\-]+)`?/i'),
			'D' => array('delete', '/^DELETE\s+FROM\s+`?([A-Za-z0-9_$.\-]+)`?/i'),
		);
		$pattern = $patterns[strtoupper($head[0] ?? '')] ?? null;
		if (null === $pattern || 1 !== preg_match($pattern[1], $head, $matches)) {
			return null;
		}

		return array(
			'operation' => $pattern[0],
			'table'     => substr((string) $matches[1], 0, 191),
		);
	}

	private function isOwnTable(string $table): bool
	{
		return str_starts_with($table, $this->database->prefix . 'configops_');
	}

	private function isManagedOptionsApiWrite(string $table): bool
	{
		if ($table !== $this->database->options) {
			return false;
		}

		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
			if (in_array((string) ($frame['function'] ?? ''), array('add_option', 'update_option', 'delete_option'), true)) {
				return true;
			}
		}

		return false;
	}

	private function isUncorrelatedCoreCronRequest(): bool
	{
		return 0 === $this->request->actorId() && '/wp-cron.php' === $this->request->uri();
	}

	private function record(int $sessionId, string $operation, string $table): void
	{
		$source = $this->source->capture();
		$signature = implode('|', array((string) $sessionId, $operation, $table, $source['type'], $source['component'], $source['file'], (string) $source['line']));
		if (isset($this->signalIds[$signature])) {
			$this->signals->incrementOccurrence($this->signalIds[$signature]);
			$this->captures->incrementWriteSignalCount($sessionId);

			return;
		}

		if (count($this->signalIds) >= self::MAX_UNIQUE_SIGNALS) {
			$operation = 'overflow';
			$table = '[additional writes omitted]';
			$source = array('type' => 'unknown', 'component' => 'signal-budget-exceeded', 'file' => '', 'line' => 0);
			$signature = 'overflow|' . $sessionId;
			if (isset($this->signalIds[$signature])) {
				$this->signals->incrementOccurrence($this->signalIds[$signature]);
				$this->captures->incrementWriteSignalCount($sessionId);

				return;
			}
		}

		$id = $this->signals->insert(
			array(
				'session_id'       => $sessionId,
				'request_id'        => $this->request->id(),
				'operation'         => $operation,
				'table_name'        => $table,
				'occurrence_count'  => 1,
				'source_type'       => $source['type'],
				'source_component'  => $source['component'],
				'source_file'       => $source['file'],
				'source_line'       => $source['line'],
				'request_method'    => $this->request->method(),
				'request_uri'       => $this->request->uri(),
				'admin_screen'      => $this->request->adminScreen(),
				'actor_id'          => $this->request->actorId(),
				'occurred_at'       => current_time('mysql', true),
			)
		);
		$this->signalIds[$signature] = $id;
		$this->captures->incrementWriteSignalCount($sessionId);
	}

	private function reportCaptureError(Throwable $error): void
	{
		try {
			do_action('configops_capture_error', $error, '[database-write-signal]', $this->captures->activeId() ?? 0);
		} catch (Throwable) {
			// Detection and reporting must never escape into the host query.
		}
	}
}
