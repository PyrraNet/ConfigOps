<?php
/**
 * Import preview and conflict-checked Pack application.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Pack;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Capture\AutomaticRecorder;
use ConfigOps\Capture\InternalOptionPolicy;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Execution\OperationMutex;
use ConfigOps\Multisite\SiteBoundaryGuard;
use RuntimeException;
use Throwable;

final class PackService
{
	private const PLAN_LIFETIME = 600;
	private const MAX_PREVIEW_CHANGES = 25;

	public function __construct(
		private readonly PackValidator $validator,
		private readonly AdapterRegistry $adapters,
		private readonly ValueCodec $codec,
		private readonly NestedDiff $diff,
		private readonly PackPortabilityInspector $portability,
		private readonly InternalOptionPolicy $internalOptions,
		private readonly CaptureRepository $captures,
		private readonly OptionMetadataRepository $optionMetadata,
		private readonly OperationMutex $operationLock,
		private readonly SiteBoundaryGuard $siteBoundary,
		private readonly ?AutomaticRecorder $automatic = null
	) {
	}

	/**
	 * @param array<string, mixed> $untrustedPack Decoded request data.
	 * @return array<string, mixed>
	 */
	public function preview(array $untrustedPack): array
	{
		$pack = $this->validator->validate($untrustedPack);
		$plan = $this->buildPlan($pack);
		$token = '';
		$expiresAt = null;
		if ($plan['canApply']) {
			$token     = bin2hex(random_bytes(24));
			$expiresAt = time() + self::PLAN_LIFETIME;
			$stored    = array(
				'user_id'    => get_current_user_id(),
				'blog_id'    => get_current_blog_id(),
				'pack_hash'  => $this->packHash($pack),
				'baselines'  => $plan['baselines'],
				'expires_at' => $expiresAt,
			);
			if (! set_transient($this->planKey($token), $stored, self::PLAN_LIFETIME)) {
				throw new RuntimeException('ConfigOps could not retain this Apply Preview. Nothing was changed.');
			}
		}

		return $this->publicPlan($pack, $plan, $token, $expiresAt);
	}

	/**
	 * @param array<string, mixed> $untrustedPack Decoded request data.
	 * @return array{sessionId: int, changed: int, packId: string, packName: string}
	 */
	public function apply(array $untrustedPack, string $token): array
	{
		$pack = $this->validator->validate($untrustedPack);
		$token = trim($token);
		if ('' === $token || 1 !== preg_match('/^[a-f0-9]{48}$/', $token)) {
			throw new RuntimeException('Run a fresh Apply Preview before applying this Pack.');
		}
		$key    = $this->planKey($token);
		$stored = get_transient($key);
		delete_transient($key);
		if (! is_array($stored)) {
			throw new RuntimeException('This Apply Preview expired or was already used. Run it again.');
		}
		if (
			(int) ($stored['user_id'] ?? 0) !== get_current_user_id()
			|| (int) ($stored['blog_id'] ?? 0) !== get_current_blog_id()
			|| (int) ($stored['expires_at'] ?? 0) < time()
			|| ! hash_equals((string) ($stored['pack_hash'] ?? ''), $this->packHash($pack))
		) {
			throw new RuntimeException('This Apply Preview does not belong to the current user, website, or Pack.');
		}

		$expectedBaselines = is_array($stored['baselines'] ?? null) ? $stored['baselines'] : array();

		return $this->operationLock->run(
			'pack-apply',
			function () use ($pack, $expectedBaselines): array {
				$this->siteBoundary->assertCurrentSite();
				$plan = $this->buildPlan($pack);
				if (! $plan['canApply']) {
					throw new RuntimeException('The Pack is no longer safe to apply. Run a fresh Apply Preview.');
				}
				if ($expectedBaselines !== $plan['baselines']) {
					throw new RuntimeException('Conflict: at least one setting changed after the Apply Preview. Nothing was changed.');
				}

				return $this->applyPlan($pack, $plan);
			}
		);
	}

	/**
	 * @param array<string, mixed> $pack Validated Pack.
	 * @return array<string, mixed>
	 */
	private function buildPlan(array $pack): array
	{
		$this->siteBoundary->assertCurrentSite();
		$requirements = $this->requirements($pack['requirements']);
		$requirementsCompatible = array() === array_filter(
			$requirements,
			static fn (array $requirement): bool => ! $requirement['compatible']
		);
		$items     = array();
		$baselines = array();
		$counts    = array(
			'total'           => count($pack['settings']),
			'compatible'      => 0,
			'alreadyMatching' => 0,
			'willChange'      => 0,
			'skipped'         => 0,
			'excludedSafety'  => 0,
			'incompatible'    => 0,
			'conflicts'       => 0,
			'withWarnings'    => 0,
		);

		foreach ($pack['settings'] as $index => $setting) {
			$item = $this->planSetting($setting, (int) $index, $pack['requirements']);
			$items[] = $item;
			$status = $item['status'];
			if (in_array($status, array('already_matching', 'will_change'), true)) {
				++$counts['compatible'];
				$baselines[$item['option']] = $item['baseline'];
			}
			if ('already_matching' === $status) {
				++$counts['alreadyMatching'];
				++$counts['skipped'];
			} elseif ('will_change' === $status) {
				++$counts['willChange'];
			} elseif ('excluded_safety' === $status) {
				++$counts['excludedSafety'];
				++$counts['skipped'];
			} elseif ('incompatible' === $status) {
				++$counts['incompatible'];
				++$counts['skipped'];
			} else {
				++$counts['conflicts'];
			}
			if (! empty($item['warnings'])) {
				++$counts['withWarnings'];
			}
		}
		ksort($baselines, SORT_STRING);

		$hasBlockers = $counts['excludedSafety'] > 0
			|| $counts['incompatible'] > 0
			|| $counts['conflicts'] > 0;
		$active = null !== $this->captures->activeId();

		return array(
			'items'        => $items,
			'counts'       => $counts,
			'requirements' => $requirements,
			'baselines'    => $baselines,
			'activeCapture' => $active,
			'canApply'     => $requirementsCompatible
				&& ! $hasBlockers
				&& ! $active
				&& $counts['willChange'] > 0,
		);
	}

	/**
	 * @param array<string, mixed> $setting Validated setting.
	 * @param array<string, mixed> $requirements Validated requirements.
	 * @return array<string, mixed>
	 */
	private function planSetting(array $setting, int $index, array $requirements): array
	{
		$option = (string) $setting['option'];
		$base = array(
			'key'      => hash('sha256', $option),
			'index'    => $index,
			'option'   => $option,
			'label'    => $option,
			'group'    => 'WordPress setting',
			'state'    => (string) $setting['state'],
			'adapter'  => $setting['adapter'],
			'pluginFile' => '',
			'status'   => 'incompatible',
			'reason'   => '',
			'warnings' => array(),
			'before'   => null,
			'after'    => null,
			'beforeState' => 'unknown',
			'afterState'  => (string) $setting['state'],
			'changes'  => array(),
			'changeCount' => 0,
			'baseline' => '',
			'setting'  => $setting,
		);

		if ($this->internalOptions->isInternal($option)) {
			$base['status'] = 'excluded_safety';
			$base['reason'] = 'ConfigOps-owned runtime options can never be imported from a Pack.';

			return $base;
		}
		if (! is_array($setting['adapter'])) {
			$base['reason'] = 'Schema version 1 applies only settings owned by a tested ConfigOps adapter.';

			return $base;
		}

		$adapterId      = (string) $setting['adapter']['id'];
		$adapterSchema  = (int) $setting['adapter']['schema_version'];
		$manifest       = $this->adapters->manifest($adapterId);
		if (null === $manifest || $manifest->schemaVersion !== $adapterSchema) {
			$base['reason'] = 'The required adapter and schema are not available on this website.';

			return $base;
		}
		$base['pluginFile'] = 'wordpress' === $manifest->componentType ? '' : $manifest->pluginFile;
		if (
			'wordpress' !== $manifest->componentType
			&& ! array_key_exists($manifest->pluginFile, $requirements['plugins'])
		) {
			$base['reason'] = 'The Pack does not declare the owning plugin in its requirements.';

			return $base;
		}

		if ($this->filteredOptionRead($option)) {
			$base['status'] = 'conflict';
			$base['reason'] = 'WordPress filters this option at runtime, so ConfigOps cannot establish a trustworthy baseline.';

			return $base;
		}

		$sentinel = new \stdClass();
		$current  = get_option($option, $sentinel);
		$this->siteBoundary->assertCurrentSite();
		$currentAutoload = $this->optionMetadata->autoloadFor($option);
		$this->siteBoundary->assertCurrentSite();
		$currentEncoded = $current === $sentinel
			? $this->codec->missing()
			: $this->codec->encode($current, $option);
		if (! $currentEncoded->restorable || $currentEncoded->redacted) {
			$base['status'] = 'excluded_safety';
			$base['reason'] = 'The current option contains protected or unsupported data. ConfigOps will not overwrite or retain it in an Apply plan.';

			return $base;
		}

		$desiredMissing = 'absent' === $setting['state'];
		$desired = $desiredMissing ? $sentinel : $setting['value'];
		$desiredEncoded = $desiredMissing
			? $this->codec->missing()
			: $this->codec->encode($desired, $option);
		if (! $desiredEncoded->restorable || $desiredEncoded->redacted) {
			$base['status'] = 'excluded_safety';
			$base['reason'] = 'The Pack value looks like a secret or uses a value type ConfigOps refuses to transport.';

			return $base;
		}

		$matches = $desiredMissing
			? $current === $sentinel
			: $current !== $sentinel && $this->codec->semanticallyEqual($current, $desired);
		$changes = $matches
			? array()
			: $this->diff->compare($currentEncoded->display, $desiredEncoded->display);
		$analysis = $this->adapters->analyze($option, $changes);
		if (
			$adapterId !== (string) ($analysis['adapter_id'] ?? '')
			|| $adapterSchema !== (int) ($analysis['adapter_schema_version'] ?? 0)
		) {
			$base['reason'] = 'The destination adapter does not claim this option under the Pack’s schema contract.';

			return $base;
		}

		$decoratedChanges = is_array($analysis['changes'] ?? null) ? $analysis['changes'] : array();
		$unsafeKinds = array_filter(
			$decoratedChanges,
			static fn (array $change): bool => in_array(
				(string) ($change['kind'] ?? 'unknown'),
				array('secret', 'runtime', 'unsupported', 'unknown'),
				true
			)
		);
		if (! $matches && (! $analysis['allows_restore'] || ! empty($unsafeKinds))) {
			$classification = (string) ($analysis['classification'] ?? 'unknown');
			$base['status'] = 'secret' === $classification ? 'excluded_safety' : 'incompatible';
			$base['reason'] = (string) ($analysis['reason'] ?? 'The adapter cannot apply this value safely.');

			return $base;
		}

		try {
			$this->adapters->assertRestorableReferences($decoratedChanges);
		} catch (Throwable $error) {
			$base['reason'] = $error->getMessage();

			return $base;
		}

		$field = $this->adapters->field($adapterId, $adapterSchema, $option, '/');
		$first = $decoratedChanges[0] ?? array();
		$base['label'] = (string) ($first['label'] ?? $field?->label ?? $option);
		$base['group'] = (string) ($first['group'] ?? $field?->group ?? $manifest->name);
		$base['status'] = $matches ? 'already_matching' : 'will_change';
		$base['reason'] = $matches
			? 'The destination already has the desired value.'
			: (string) ($analysis['reason'] ?? 'The adapter accepts this desired state.');
		$base['warnings'] = $this->portability->inspect($desiredMissing ? null : $desired, $decoratedChanges);
		$base['beforeState'] = $current === $sentinel ? 'absent' : 'present';
		$base['before'] = $current === $sentinel ? null : $currentEncoded->display;
		$base['after'] = $desiredMissing ? null : $desiredEncoded->display;
		$base['changeCount'] = count($decoratedChanges);
		$base['changes'] = array_slice($decoratedChanges, 0, self::MAX_PREVIEW_CHANGES);
		$base['baseline'] = $this->stateFingerprint($option, $currentEncoded->payload, $currentAutoload);

		return $base;
	}

	/**
	 * @param array<string, mixed> $requirements Validated requirements.
	 * @return list<array<string, mixed>>
	 */
	private function requirements(array $requirements): array
	{
		global $wp_version;

		$wordpressVersion = is_string($wp_version ?? null) ? trim($wp_version) : '';
		if ('' === $wordpressVersion && function_exists('get_bloginfo')) {
			$wordpressVersion = trim((string) get_bloginfo('version'));
		}
		$result = array(
			array(
				'type'       => 'wordpress',
				'name'       => 'WordPress',
				'constraint' => $requirements['wordpress'],
				'version'    => $wordpressVersion,
				'active'     => '' !== $wordpressVersion,
				'compatible' => '' !== $wordpressVersion
					&& $this->validator->versionMatches($wordpressVersion, $requirements['wordpress']),
			),
		);

		$activePlugins = get_option('active_plugins', array());
		$activePlugins = is_array($activePlugins) ? $activePlugins : array();
		$networkPlugins = function_exists('get_site_option') ? get_site_option('active_sitewide_plugins', array()) : array();
		$networkPlugins = is_array($networkPlugins) ? array_keys($networkPlugins) : array();
		foreach ($requirements['plugins'] as $pluginFile => $constraint) {
			$active  = in_array($pluginFile, $activePlugins, true) || in_array($pluginFile, $networkPlugins, true);
			$version = '';
			$file    = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/' . $pluginFile : '';
			if ('' !== $file && is_readable($file) && function_exists('get_file_data')) {
				$data    = get_file_data($file, array('Version' => 'Version'), false);
				$version = trim((string) ($data['Version'] ?? ''));
			}
			$result[] = array(
				'type'       => 'plugin',
				'name'       => $pluginFile,
				'constraint' => $constraint,
				'version'    => $version,
				'active'     => $active,
				'compatible' => $active && '' !== $version && $this->validator->versionMatches($version, $constraint),
			);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $pack Validated Pack.
	 * @param array<string, mixed> $plan Internal plan.
	 * @return array{sessionId: int, changed: int, packId: string, packName: string}
	 */
	private function applyPlan(array $pack, array $plan): array
	{
		if (null !== $this->captures->activeId()) {
			throw new RuntimeException('Stop the active Change Session before applying a Pack.');
		}
		$this->automatic?->suppress();
		$sessionName = sprintf('Pack: %s', (string) $pack['name']);
		$sessionId = $this->captures->startPack(
			$sessionName,
			get_current_user_id(),
			admin_url('admin.php?page=configops'),
			(string) $pack['id'],
			(string) $pack['pack_version']
		);

		$applied = array();
		try {
			foreach ($plan['items'] as $item) {
				if ('will_change' !== $item['status']) {
					continue;
				}
				$option = (string) $item['option'];
				$current = $this->readState($option);
				if (! hash_equals((string) $item['baseline'], $current['fingerprint'])) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this conflict message.
					throw $this->runtimeFailure("Conflict: {$option} changed while the Pack was being applied.");
				}
				$applied[$option] = $current;
				$this->writeDesired($option, $item['setting']);
				$this->assertDesired($option, $item['setting']);
			}

			$completedId = $this->captures->stop();
			if ($completedId !== $sessionId) {
				throw new RuntimeException('ConfigOps could not finalize the Pack Change Session.');
			}
		} catch (Throwable $error) {
			$compensationFailed = array();
			foreach (array_reverse($applied, true) as $option => $state) {
				try {
					$this->restoreState($option, $state);
				} catch (Throwable) {
					$compensationFailed[] = $option;
				}
			}
			try {
				$this->captures->interruptActive('pack_apply_failed');
			} catch (Throwable) {
				$compensationFailed[] = 'capture-ledger';
			}
			if (! empty($compensationFailed)) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message; the previous throwable is metadata.
				throw $this->runtimeFailure(
					$error->getMessage() . ' ConfigOps could not verify compensation for: ' . implode(', ', $compensationFailed) . '.',
					$error
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message; the previous throwable is metadata.
			throw $this->runtimeFailure($error->getMessage() . ' Earlier Pack writes were compensated.', $error);
		}

		return array(
			'sessionId' => $sessionId,
			'changed'   => (int) $plan['counts']['willChange'],
			'packId'    => (string) $pack['id'],
			'packName'  => (string) $pack['name'],
		);
	}

	/**
	 * @return array{exists: bool, value: mixed, autoload: ?string, fingerprint: string}
	 */
	private function readState(string $option): array
	{
		if ($this->filteredOptionRead($option)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this option-specific message.
			throw $this->runtimeFailure("ConfigOps cannot safely read {$option} because WordPress filters its runtime value.");
		}
		$sentinel = new \stdClass();
		$value    = get_option($option, $sentinel);
		$this->siteBoundary->assertCurrentSite();
		$autoload = $this->optionMetadata->autoloadFor($option);
		$this->siteBoundary->assertCurrentSite();
		$encoded = $value === $sentinel ? $this->codec->missing() : $this->codec->encode($value, $option);
		if (! $encoded->restorable || $encoded->redacted) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this option-specific message.
			throw $this->runtimeFailure("ConfigOps cannot safely retain the current value of {$option} for compensation.");
		}

		return array(
			'exists'      => $value !== $sentinel,
			'value'       => $value === $sentinel ? null : $value,
			'autoload'    => $autoload,
			'fingerprint' => $this->stateFingerprint($option, $encoded->payload, $autoload),
		);
	}

	/**
	 * @param array<string, mixed> $setting Validated setting.
	 */
	private function writeDesired(string $option, array $setting): void
	{
		if ('absent' === $setting['state']) {
			delete_option($option);

			return;
		}
		$exists = null !== $this->optionMetadata->autoloadFor($option);
		if ($exists) {
			update_option($option, $setting['value']);
		} else {
			add_option($option, $setting['value'], '', false);
		}
	}

	/**
	 * @param array<string, mixed> $setting Validated setting.
	 */
	private function assertDesired(string $option, array $setting): void
	{
		$sentinel = new \stdClass();
		$stored   = get_option($option, $sentinel);
		$this->siteBoundary->assertCurrentSite();
		$exists = null !== $this->optionMetadata->autoloadFor($option);
		$this->siteBoundary->assertCurrentSite();
		if ('absent' === $setting['state']) {
			if ($stored !== $sentinel || $exists) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this option-specific message.
				throw $this->runtimeFailure("WordPress did not preserve the desired absence of {$option}.");
			}

			return;
		}
		if ($stored === $sentinel || ! $exists || ! $this->codec->semanticallyEqual($stored, $setting['value'])) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this option-specific message.
			throw $this->runtimeFailure("WordPress did not preserve the desired Pack value for {$option}.");
		}
	}

	/**
	 * @param array{exists: bool, value: mixed, autoload: ?string, fingerprint: string} $state Original state.
	 */
	private function restoreState(string $option, array $state): void
	{
		if (! $state['exists']) {
			delete_option($option);
		} elseif (null === $this->optionMetadata->autoloadFor($option)) {
			add_option($option, $state['value'], '', $this->autoloadFlag($state['autoload']));
		} else {
			// Pack writes preserve an existing row's autoload state. Compensation
			// therefore changes only the value when that row still exists.
			update_option($option, $state['value']);
		}
		$restored = $this->readState($option);
		if (! hash_equals($state['fingerprint'], $restored['fingerprint'])) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes this option-specific message.
			throw $this->runtimeFailure("ConfigOps could not restore {$option} after a failed Pack apply.");
		}
	}

	private function filteredOptionRead(string $option): bool
	{
		$exists = null !== $this->optionMetadata->autoloadFor($option);
		$hooks = array("pre_option_{$option}", 'pre_option');
		if (! $exists) {
			$hooks[] = "default_option_{$option}";
		}
		foreach ($hooks as $hook) {
			if (false !== has_filter($hook)) {
				return true;
			}
		}

		return false;
	}

	private function stateFingerprint(string $option, string $payload, ?string $autoload): string
	{
		return hash_hmac(
			'sha256',
			implode("\n", array($option, $payload, $this->autoloadMode($autoload), (string) get_current_blog_id())),
			wp_salt('auth')
		);
	}

	/**
	 * @param array<string, mixed> $pack Validated Pack.
	 */
	private function packHash(array $pack): string
	{
		$encoded = wp_json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (! is_string($encoded)) {
			throw new RuntimeException('The Pack could not be fingerprinted.');
		}

		return hash('sha256', $encoded);
	}

	private function planKey(string $token): string
	{
		return 'configops_pack_plan_' . hash('sha256', $token);
	}

	private function autoloadFlag(?string $autoload): ?bool
	{
		return match ($this->autoloadMode($autoload)) {
			'on' => true,
			'off' => false,
			default => null,
		};
	}

	private function autoloadMode(?string $autoload): string
	{
		return match (strtolower((string) $autoload)) {
			'on', 'yes', 'auto-on', '1' => 'on',
			'off', 'no', 'auto-off', '0' => 'off',
			'auto' => 'auto',
			default => 'unknown',
		};
	}

	private function runtimeFailure(string $message, ?Throwable $previous = null): RuntimeException
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The human-facing Pack error is escaped; the previous throwable is exception metadata.
		return new RuntimeException(esc_html($message), 0, $previous);
	}

	/**
	 * @param array<string, mixed> $pack Validated Pack.
	 * @param array<string, mixed> $plan Internal plan.
	 * @return array<string, mixed>
	 */
	private function publicPlan(array $pack, array $plan, string $token, ?int $expiresAt): array
	{
		$items = array_map(
			static function (array $item): array {
				unset($item['baseline'], $item['setting']);

				return $item;
			},
			$plan['items']
		);

		return array(
			'pack' => array(
				'id'          => $pack['id'],
				'name'        => $pack['name'],
				'description' => $pack['description'],
				'packVersion' => $pack['pack_version'],
				'createdAt'   => $pack['created_at'],
				'createdWith' => $pack['created_with'],
			),
			'counts'        => $plan['counts'],
			'requirements'  => $plan['requirements'],
			'items'         => $items,
			'activeCapture' => $plan['activeCapture'],
			'canApply'      => $plan['canApply'],
			'planToken'     => $token,
			'expiresAt'     => null === $expiresAt ? null : gmdate('c', $expiresAt),
		);
	}
}
