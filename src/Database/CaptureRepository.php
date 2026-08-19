<?php
/**
 * Capture session persistence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use ConfigOps\Multisite\SiteScope;
use RuntimeException;
use wpdb;

final class CaptureRepository
{
	private const ACTIVE_OPTION = 'configops_active_capture_id';
	public const INTEGRITY_FALLBACK_OPTION = 'configops_capture_integrity_fallback';
	private const MAX_FALLBACK_EVENTS = 100;
	private const STOPPING_TIMEOUT = 300;

	private string $table;
	private readonly StorageContext $storage;
	private bool $activeResolved = false;
	private bool $integrityFallbackChecked = false;
	private ?object $activeSession = null;

	public function __construct(private readonly wpdb $database, ?SiteScope $siteScope = null)
	{
		$this->storage = new StorageContext($this->database, $siteScope);
		$this->table   = $this->storage->table('configops_capture_sessions');
	}

	public function start(string $name, int $actorId, string $initialUrl): int
	{
		if (null !== $this->activeSession()) {
			throw new RuntimeException('A capture session is already active.');
		}

		$name = $this->normalizeName($name);

		$inserted = $this->database->insert(
			$this->table,
			$this->storage->row(array(
				'name'        => $name,
				'capture_mode' => 'manual',
				'status'      => 'starting',
				'actor_id'    => $actorId,
				'initial_url' => $initialUrl,
				'started_at'  => current_time('mysql', true),
			)),
			$this->storage->rowFormats(array('%s', '%s', '%s', '%d', '%s', '%s'))
		);

		if (false === $inserted) {
			throw new RuntimeException('The capture session could not be stored.');
		}

		$id = (int) $this->database->insert_id;
		if (! add_option(self::ACTIVE_OPTION, $id, '', false)) {
			$this->markDiscarded($id);
			$this->invalidateActiveSession();

			throw new RuntimeException('Another request started a capture session first.');
		}

		$activated = $this->database->update(
			$this->table,
			array('status' => 'active'),
			$this->storage->where(array('id' => $id)),
			array('%s'),
			$this->storage->whereFormats(array('%d'))
		);
		if (false === $activated) {
			delete_option(self::ACTIVE_OPTION);
			$this->markDiscarded($id);
			$this->invalidateActiveSession();

			throw new RuntimeException('The capture session lock was created, but the session could not be activated.');
		}

		$this->invalidateActiveSession();
		$this->activeSession();

		return $id;
	}

	public function startAutomatic(string $name, int $actorId, string $initialUrl): int
	{
		$name = $this->normalizeName($name);
		$inserted = $this->database->insert(
			$this->table,
			$this->storage->row(array(
				'name'         => $name,
				'capture_mode' => 'automatic',
				'status'       => 'active',
				'actor_id'     => max(0, $actorId),
				'initial_url'  => $initialUrl,
				'started_at'   => current_time('mysql', true),
			)),
			$this->storage->rowFormats(array('%s', '%s', '%s', '%d', '%s', '%s'))
		);
		if (false === $inserted) {
			throw new RuntimeException('The automatic settings observation could not be stored.');
		}

		return (int) $this->database->insert_id;
	}

	public function completeAutomatic(int $id): object
	{
		$finalizing = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table} SET status = 'stopping', ended_at = %s
				WHERE {$this->storage->clause()} AND id = %d AND capture_mode = 'automatic' AND status = 'active'",
				current_time('mysql', true),
				$id
			)
		);
		if (1 !== $finalizing) {
			throw new RuntimeException('The automatic settings observation is no longer open.');
		}

		$summary = $this->verifiedSummary($id);
		$session = $this->find($id);
		if (! $session) {
			throw new RuntimeException('The automatic settings observation could not be verified before finalization.');
		}
		$status = (int) ($session->capture_error_count ?? 0) > 0
			? 'interrupted'
			: ($summary['review_change_count'] > 0 ? 'completed' : 'discarded');
		$updated = $this->database->update(
			$this->table,
			array(
				'status'                 => $status,
				'mutation_count'         => $summary['mutation_count'],
				'review_change_count'    => $summary['review_change_count'],
				'technical_change_count' => $summary['technical_change_count'],
				'write_signal_count'     => $summary['write_signal_count'],
				'ended_at'               => current_time('mysql', true),
			),
			$this->storage->where(array('id' => $id, 'capture_mode' => 'automatic', 'status' => 'stopping')),
			array('%s', '%d', '%d', '%d', '%d', '%s'),
			$this->storage->whereFormats(array('%d', '%s', '%s'))
		);
		if (1 !== $updated) {
			throw new RuntimeException('The automatic settings observation could not be finalized.');
		}

		$session = $this->find($id);
		if (! $session) {
			throw new RuntimeException('The finalized automatic settings observation could not be verified.');
		}

		return $session;
	}

	public function interruptAutomatic(int $id, string $code): void
	{
		$code = substr(sanitize_key($code), 0, 64) ?: 'automatic_capture_interrupted';
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET status = 'interrupted',
					capture_error_count = capture_error_count + 1,
					last_error_code = %s,
					last_error_at = %s,
					ended_at = %s
				WHERE {$this->storage->clause()} AND id = %d AND capture_mode = 'automatic' AND status IN ('active', 'stopping')",
				$code,
				current_time('mysql', true),
				current_time('mysql', true),
				$id
			)
		);
		if (false === $updated) {
			throw new RuntimeException('The automatic settings observation could not be interrupted safely.');
		}
	}

	public function stop(): ?int
	{
		$id = $this->activeId();
		if (null === $id) {
			return null;
		}

		$finalizing = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table} SET status = 'stopping', ended_at = %s WHERE {$this->storage->clause()} AND id = %d AND status IN ('starting', 'active')",
				current_time('mysql', true),
				$id
			)
		);
		if (1 !== $finalizing) {
			throw new RuntimeException('The capture session is already being finalized or is no longer active.');
		}
		if ($this->activeSession && (int) $this->activeSession->id === $id) {
			$this->activeSession->status = 'stopping';
		}

		try {
			$summary = $this->verifiedSummary($id);
			$mutationCount = $summary['mutation_count'];
			$reviewChangeCount = $summary['review_change_count'];
			$technicalChangeCount = $summary['technical_change_count'];
			$writeSignalCount = $summary['write_signal_count'];
		} catch (\Throwable $error) {
			$recovered = $this->recoverFailedStop($id);
			$message = $recovered
				? ' The capture remains active.'
				: ' Recording is no longer active because ConfigOps could not safely resume the capture.';

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message; the previous throwable is metadata.
			throw $this->runtimeFailure($error->getMessage() . $message, $error);
		}

		$updated = $this->database->update(
			$this->table,
			array(
				'status'             => 'completed',
				'mutation_count'     => $mutationCount,
				'review_change_count' => $reviewChangeCount,
				'technical_change_count' => $technicalChangeCount,
				'write_signal_count' => $writeSignalCount,
				'ended_at'           => current_time('mysql', true),
			),
			$this->storage->where(array('id' => $id, 'status' => 'stopping')),
			array('%s', '%d', '%d', '%d', '%d', '%s'),
			$this->storage->whereFormats(array('%d', '%s'))
		);
		if (1 !== $updated) {
			$recovered = $this->recoverFailedStop($id);
			throw new RuntimeException(
				$recovered
					? 'The capture session could not be stopped safely. The capture remains active.'
					: 'The capture session could not be stopped or resumed safely. Recording requires inspection.'
			);
		}

		delete_option(self::ACTIVE_OPTION);
		$this->invalidateActiveSession();

		return $id;
	}

	public function interruptActive(string $code = 'capture_interrupted'): ?int
	{
		$id = (int) get_option(self::ACTIVE_OPTION, 0);
		if ($id <= 0) {
			return null;
		}

		$code = substr(sanitize_key($code), 0, 64);
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET status = 'interrupted',
					capture_error_count = capture_error_count + 1,
					last_error_code = %s,
					last_error_at = %s,
					ended_at = %s
				WHERE {$this->storage->clause()} AND id = %d AND status IN ('starting', 'active', 'stopping')",
				'' === $code ? 'capture_interrupted' : $code,
				current_time('mysql', true),
				current_time('mysql', true),
				$id
			)
		);

		delete_option(self::ACTIVE_OPTION);
		$this->invalidateActiveSession();

		if (false === $updated) {
			throw new RuntimeException('The interrupted capture could not be closed safely.');
		}

		return $updated > 0 ? $id : null;
	}

	public function interruptOpen(string $code = 'capture_interrupted'): int
	{
		$code = substr(sanitize_key($code), 0, 64) ?: 'capture_interrupted';
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET status = 'interrupted',
					capture_error_count = capture_error_count + 1,
					last_error_code = %s,
					last_error_at = %s,
					ended_at = %s
				WHERE {$this->storage->clause()} AND status IN ('starting', 'active', 'stopping')",
				$code,
				current_time('mysql', true),
				current_time('mysql', true)
			)
		);

		delete_option(self::ACTIVE_OPTION);
		$this->invalidateActiveSession();

		if (false === $updated) {
			throw new RuntimeException('Open capture sessions could not be closed safely.');
		}

		return (int) $updated;
	}

	public function activeId(): ?int
	{
		$session = $this->activeSession();

		return $session ? (int) $session->id : null;
	}

	public function hasOpenAutomatic(): bool
	{
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::STOPPING_TIMEOUT);
		$this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET status = 'interrupted',
					capture_error_count = capture_error_count + 1,
					last_error_code = 'automatic_request_timed_out',
					last_error_at = %s,
					ended_at = %s
				WHERE {$this->storage->clause()}
					AND capture_mode = 'automatic'
					AND status IN ('active', 'stopping')
					AND started_at < %s",
				current_time('mysql', true),
				current_time('mysql', true),
				$cutoff
			)
		);

		$count = $this->database->get_var(
			"SELECT COUNT(*) FROM {$this->table}
			WHERE {$this->storage->clause()} AND capture_mode = 'automatic' AND status IN ('active', 'stopping')"
		);

		return null === $count || (int) $count > 0;
	}

	public function activeSession(): ?object
	{
		if (! $this->integrityFallbackChecked) {
			$this->reconcileIntegrityFallback();
		}

		$id = (int) get_option(self::ACTIVE_OPTION, 0);
		if (
			$this->activeResolved
			&& $this->activeSession
			&& $id === (int) $this->activeSession->id
			&& in_array((string) $this->activeSession->status, array('active', 'starting'), true)
		) {
			return $this->activeSession;
		}

		$this->invalidateActiveSession();
		if ($id <= 0) {
			// Do not memoize absence: another integration may start a capture later in this request.
			return null;
		}

		$this->activeResolved = true;

		$session = $this->find($id);
		if ($session && 'stopping' === (string) $session->status) {
			$stoppingAt = strtotime((string) ($session->ended_at ?? '') . ' UTC');
			if (false !== $stoppingAt && $stoppingAt <= time() - self::STOPPING_TIMEOUT) {
				$interrupted = $this->database->query(
					$this->storage->prepare(
						"UPDATE {$this->table}
						SET status = 'interrupted',
							capture_error_count = capture_error_count + 1,
							last_error_code = 'stop_timed_out',
							last_error_at = %s
						WHERE {$this->storage->clause()} AND id = %d AND status = 'stopping'",
						current_time('mysql', true),
						$id
					)
				);
				if (1 === $interrupted) {
					delete_option(self::ACTIVE_OPTION);
				}
			}

			return null;
		}
		if (! $session || ! in_array((string) $session->status, array('active', 'starting'), true)) {
			delete_option(self::ACTIVE_OPTION);

			return null;
		}

		if ('starting' === (string) $session->status) {
			$recovered = $this->database->update(
				$this->table,
				array('status' => 'active'),
				$this->storage->where(array('id' => $id)),
				array('%s'),
				$this->storage->whereFormats(array('%d'))
			);
			if (false === $recovered) {
				delete_option(self::ACTIVE_OPTION);
				$this->markDiscarded($id);

				return null;
			}
			$session->status = 'active';
		}

		$this->activeSession = $session;

		return $this->activeSession;
	}

	public function incrementMutationCount(int $sessionId, int $reviewChanges = 1, int $technicalChanges = 0): void
	{
		$this->adjustMutationCounts($sessionId, 1, max(0, $reviewChanges), max(0, $technicalChanges));
	}

	public function adjustMutationCounts(
		int $sessionId,
		int $mutationDelta,
		int $reviewChangeDelta,
		int $technicalChangeDelta
	): void {
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET mutation_count = mutation_count + %d,
					review_change_count = review_change_count + %d,
					technical_change_count = technical_change_count + %d,
					capture_error_count = capture_error_count + CASE WHEN status IN ('stopping', 'completed') THEN 1 ELSE 0 END,
					last_error_code = CASE WHEN status IN ('stopping', 'completed') THEN 'late_mutation' ELSE last_error_code END,
					last_error_at = CASE WHEN status IN ('stopping', 'completed') THEN %s ELSE last_error_at END
				WHERE {$this->storage->clause()} AND id = %d",
				$mutationDelta,
				$reviewChangeDelta,
				$technicalChangeDelta,
				current_time('mysql', true),
				$sessionId
			)
		);

		if (false === $updated) {
			throw new RuntimeException('The capture mutation counter could not be updated.');
		}

		if ($this->activeSession && (int) $this->activeSession->id === $sessionId) {
			$this->activeSession->mutation_count = max(0, (int) $this->activeSession->mutation_count + $mutationDelta);
			$this->activeSession->review_change_count = max(0, (int) $this->activeSession->review_change_count + $reviewChangeDelta);
			$this->activeSession->technical_change_count = max(0, (int) $this->activeSession->technical_change_count + $technicalChangeDelta);
		}
	}

	public function incrementWriteSignalCount(int $sessionId): void
	{
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET write_signal_count = write_signal_count + 1,
					capture_error_count = capture_error_count + CASE WHEN status IN ('stopping', 'completed') THEN 1 ELSE 0 END,
					last_error_code = CASE WHEN status IN ('stopping', 'completed') THEN 'late_database_write' ELSE last_error_code END,
					last_error_at = CASE WHEN status IN ('stopping', 'completed') THEN %s ELSE last_error_at END
				WHERE {$this->storage->clause()} AND id = %d",
				current_time('mysql', true),
				$sessionId
			)
		);
		if (false === $updated) {
			throw new RuntimeException('The database write signal counter could not be updated.');
		}

		if ($this->activeSession && (int) $this->activeSession->id === $sessionId) {
			++$this->activeSession->write_signal_count;
		}
	}

	public function recordCaptureError(int $sessionId, string $code): void
	{
		if ($sessionId <= 0) {
			return;
		}

		$code = substr(sanitize_key($code), 0, 64);
		if ('' === $code) {
			$code = 'capture_failed';
		}

		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET capture_error_count = capture_error_count + 1,
					last_error_code = %s,
					last_error_at = %s
				WHERE {$this->storage->clause()} AND id = %d AND status IN ('starting', 'active', 'stopping', 'completed')",
				$code,
				current_time('mysql', true),
				$sessionId
			)
		);

		if (1 !== $updated) {
			$this->rememberIntegrityFallback($sessionId, $code);

			throw new RuntimeException('The capture integrity error could not be stored.');
		}

		if ($this->activeSession && (int) $this->activeSession->id === $sessionId && $updated > 0) {
			$this->activeSession->capture_error_count = (int) ($this->activeSession->capture_error_count ?? 0) + 1;
			$this->activeSession->last_error_code = $code;
			$this->activeSession->last_error_at = current_time('mysql', true);
		}
	}

	/**
	 * Move value-free emergency markers back into the canonical session table.
	 *
	 * The WordPress options table is intentionally only a last-resort channel for
	 * the case where a ConfigOps table fails while the host setting still saves.
	 * Reconciliation is idempotent: replaying a marker can never make a capture
	 * look more trustworthy or inflate the warning count.
	 */
	public function reconcileIntegrityFallback(): bool
	{
		$this->integrityFallbackChecked = true;
		$ledger = $this->integrityFallbackLedger();
		if ($ledger['overflow']) {
			// Overflow means older markers lost their session association. Fail all
			// existing evidence closed once canonical storage is writable, then
			// retire the site-wide fallback instead of showing a permanent alert.
			$updated = $this->database->query(
				$this->storage->prepare(
					"UPDATE {$this->table}
					SET last_error_code = CASE WHEN capture_error_count < 1 THEN 'integrity_fallback_overflow' ELSE last_error_code END,
						last_error_at = CASE WHEN capture_error_count < 1 THEN %s ELSE last_error_at END,
						capture_error_count = CASE WHEN capture_error_count < 1 THEN 1 ELSE capture_error_count END
					WHERE {$this->storage->clause()} AND status NOT IN ('discarded', 'deleting')",
					current_time('mysql', true)
				)
			);
			if (false !== $updated) {
				$ledger['overflow'] = false;
			}
		}

		foreach ($ledger['events'] as $sessionKey => $event) {
			$sessionId = (int) $sessionKey;
			if ($sessionId <= 0) {
				unset($ledger['events'][$sessionKey]);
				continue;
			}

			$updated = $this->database->query(
				$this->storage->prepare(
					"UPDATE {$this->table}
					SET capture_error_count = CASE WHEN capture_error_count < 1 THEN 1 ELSE capture_error_count END,
						last_error_code = %s,
						last_error_at = %s
					WHERE {$this->storage->clause()} AND id = %d AND status NOT IN ('discarded', 'deleting')",
					$event['code'],
					$event['at'],
					$sessionId
				)
			);
			if (false === $updated) {
				continue;
			}

			$persistedCount = $this->database->get_var(
				$this->storage->prepare(
					"SELECT capture_error_count FROM {$this->table} WHERE {$this->storage->clause()} AND id = %d",
					$sessionId
				)
			);
			if (null !== $persistedCount && (int) $persistedCount > 0) {
				unset($ledger['events'][$sessionKey]);
			}
		}

		$this->storeIntegrityFallbackLedger($ledger);

		return $ledger['overflow'] || ! empty($ledger['events']);
	}

	public function hasUnresolvedIntegrityFallback(): bool
	{
		$ledger = $this->integrityFallbackLedger();

		return $ledger['overflow'] || ! empty($ledger['events']);
	}

	public function find(int $id): ?object
	{
		$row = $this->database->get_row(
			$this->storage->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND id = %d",
				$id
			)
		);

		return is_object($row) ? $row : null;
	}

	/**
	 * @return list<object>
	 */
	public function recent(int $limit = 20): array
	{
		$limit = max(1, min(100, $limit));
		$rows  = $this->database->get_results(
			$this->storage->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND status NOT IN ('discarded', 'deleting') ORDER BY started_at DESC, id DESC LIMIT %d",
				$limit
			)
		);

		return is_array($rows) ? $rows : array();
	}

	private function markDiscarded(int $id): void
	{
		$this->database->update(
			$this->table,
			array(
				'status'   => 'discarded',
				'ended_at' => current_time('mysql', true),
			),
			$this->storage->where(array('id' => $id)),
			array('%s', '%s'),
			$this->storage->whereFormats(array('%d'))
		);
	}

	private function invalidateActiveSession(): void
	{
		$this->activeResolved = false;
		$this->activeSession  = null;
	}

	private function recoverFailedStop(int $id): bool
	{
		$recovered = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table} SET status = 'active', ended_at = NULL WHERE {$this->storage->clause()} AND id = %d AND status = 'stopping'",
				$id
			)
		);
		if (1 === $recovered) {
			if ($this->activeSession && (int) $this->activeSession->id === $id) {
				$this->activeSession->status = 'active';
				$this->activeSession->ended_at = null;
			}

			return true;
		}

		$interrupted = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table}
				SET status = 'interrupted',
					capture_error_count = capture_error_count + 1,
					last_error_code = 'stop_recovery_failed',
					last_error_at = %s,
					ended_at = %s
				WHERE {$this->storage->clause()} AND id = %d AND status = 'stopping'",
				current_time('mysql', true),
				current_time('mysql', true),
				$id
			)
		);
		if (1 === $interrupted) {
			delete_option(self::ACTIVE_OPTION);
		}
		$this->invalidateActiveSession();

		return false;
	}

	private function rememberIntegrityFallback(int $sessionId, string $code): void
	{
		$this->integrityFallbackChecked = false;
		$ledger = $this->integrityFallbackLedger();
		$ledger['events'][(string) $sessionId] = array(
			'code' => substr(sanitize_key($code), 0, 64) ?: 'capture_failed',
			'at'   => current_time('mysql', true),
		);

		if (count($ledger['events']) > self::MAX_FALLBACK_EVENTS) {
			$ledger['overflow'] = true;
			$ledger['events'] = array_slice($ledger['events'], -self::MAX_FALLBACK_EVENTS, null, true);
		}

		$this->storeIntegrityFallbackLedger($ledger);
	}

	/**
	 * @return array{events: array<string, array{code: string, at: string}>, overflow: bool}
	 */
	private function integrityFallbackLedger(): array
	{
		$stored = get_option(self::INTEGRITY_FALLBACK_OPTION, array());
		$events = is_array($stored) && isset($stored['events']) && is_array($stored['events'])
			? $stored['events']
			: array();
		$normalized = array();
		foreach ($events as $sessionId => $event) {
			if (! is_array($event) || (int) $sessionId <= 0) {
				continue;
			}
			$code = substr(sanitize_key((string) ($event['code'] ?? 'capture_failed')), 0, 64);
			$at = (string) ($event['at'] ?? current_time('mysql', true));
			$normalized[(string) (int) $sessionId] = array(
				'code' => '' === $code ? 'capture_failed' : $code,
				'at'   => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)
					? $at
					: current_time('mysql', true),
			);
		}

		return array(
			'events'   => $normalized,
			'overflow' => is_array($stored) && true === ($stored['overflow'] ?? false),
		);
	}

	/**
	 * @param array{events: array<string, array{code: string, at: string}>, overflow: bool} $ledger Marker ledger.
	 */
	private function storeIntegrityFallbackLedger(array $ledger): void
	{
		if (empty($ledger['events']) && ! $ledger['overflow']) {
			delete_option(self::INTEGRITY_FALLBACK_OPTION);

			return;
		}

		update_option(self::INTEGRITY_FALLBACK_OPTION, $ledger, false);
	}

	private function normalizeName(string $name): string
	{
		$name = trim(sanitize_text_field($name));
		if ('' === $name) {
			$name = 'Capture ' . gmdate('Y-m-d H:i');
		}

		if (function_exists('mb_substr')) {
			return mb_substr($name, 0, 191, 'UTF-8');
		}

		$characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($characters)) {
			return implode('', array_slice($characters, 0, 191));
		}

		return substr($name, 0, 191);
	}

	/**
	 * @return array{mutation_count: int, review_change_count: int, technical_change_count: int, write_signal_count: int}
	 */
	private function verifiedSummary(int $id): array
	{
		$mutationTable = $this->storage->table('configops_mutations');
		$mutationSummary = $this->database->get_row(
			$this->storage->prepare(
				"SELECT
					COUNT(*) AS mutation_count,
					COALESCE(SUM(review_change_count), 0) AS review_change_count,
					COALESCE(SUM(technical_change_count), 0) AS technical_change_count
				FROM {$mutationTable} WHERE {$this->storage->clause()} AND session_id = %d",
				$id
			)
		);
		if (! is_object($mutationSummary)) {
			throw new RuntimeException('The capture mutation summary could not be verified.');
		}

		$mutationCount = (int) $mutationSummary->mutation_count;
		$reviewChangeCount = (int) $mutationSummary->review_change_count;
		$technicalChangeCount = (int) $mutationSummary->technical_change_count;
		if ($mutationCount > 0 && 0 === $reviewChangeCount + $technicalChangeCount) {
			$legacyTechnicalCount = $this->database->get_var(
				$this->storage->prepare(
					"SELECT COUNT(*) FROM {$mutationTable} WHERE {$this->storage->clause()} AND session_id = %d AND classification = 'derived'",
					$id
				)
			);
			if (null === $legacyTechnicalCount) {
				throw new RuntimeException('The legacy capture summary could not be verified.');
			}
			$technicalChangeCount = (int) $legacyTechnicalCount;
			$reviewChangeCount = $mutationCount - $technicalChangeCount;
		}

		$signalTable = $this->storage->table('configops_write_signals');
		$writeSignalSummary = $this->database->get_var(
			$this->storage->prepare(
				"SELECT COALESCE(SUM(occurrence_count), 0) FROM {$signalTable} WHERE {$this->storage->clause()} AND session_id = %d",
				$id
			)
		);
		if (null === $writeSignalSummary) {
			throw new RuntimeException('The unmanaged-write summary could not be verified.');
		}

		return array(
			'mutation_count'         => $mutationCount,
			'review_change_count'    => $reviewChangeCount,
			'technical_change_count' => $technicalChangeCount,
			'write_signal_count'     => (int) $writeSignalSummary,
		);
	}

	private function runtimeFailure(string $message, \Throwable $previous): RuntimeException
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The human-facing message is escaped; the previous throwable is exception metadata.
		return new RuntimeException(esc_html($message), 0, $previous);
	}
}
