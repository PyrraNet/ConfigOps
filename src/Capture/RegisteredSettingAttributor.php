<?php
/**
 * Request-local ownership evidence from WordPress Settings API registration.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class RegisteredSettingAttributor
{
	private const MAX_REGISTERED_SETTINGS = 1000;

	/** @var array<string, array{type: string, component: string, file: string, line: int, basis: string}> */
	private array $owners = array();

	public function __construct(private readonly SourceAttributor $source)
	{
	}

	public function register(): void
	{
		add_action('register_setting', array($this, 'remember'), PHP_INT_MAX, 3);
		add_action('unregister_setting', array($this, 'forget'), PHP_INT_MAX, 2);
	}

	/**
	 * @param mixed $args Normalized WordPress registration arguments; values are intentionally ignored.
	 */
	public function remember(mixed $optionGroup, mixed $optionName, mixed $args): void
	{
		unset($optionGroup, $args);
		if (! is_string($optionName)) {
			return;
		}
		$optionName = $this->boundedOptionName($optionName);
		if ('' === $optionName) {
			return;
		}
		if (! isset($this->owners[$optionName]) && count($this->owners) >= self::MAX_REGISTERED_SETTINGS) {
			return;
		}

		$source = $this->source->capture();
		if (! in_array($source['type'], array('plugin', 'mu-plugin', 'theme'), true)) {
			unset($this->owners[$optionName]);

			return;
		}

		$source['basis'] = 'registered-setting';
		$this->owners[$optionName] = $source;
	}

	public function forget(mixed $optionGroup, mixed $optionName): void
	{
		unset($optionGroup);
		if (! is_string($optionName)) {
			return;
		}
		unset($this->owners[$this->boundedOptionName($optionName)]);
	}

	/**
	 * Prefer the causal caller. Use registered ownership only when WordPress Core
	 * or an exhausted trace performed the final Options API write.
	 *
	 * @param array{type: string, component: string, file: string, line: int, basis?: string} $caller
	 * @return array{type: string, component: string, file: string, line: int, basis: string}
	 */
	public function resolve(string $optionName, array $caller): array
	{
		$caller['basis'] = is_string($caller['basis'] ?? null) ? $caller['basis'] : 'caller';
		$owner = $this->owners[$this->boundedOptionName($optionName)] ?? null;
		if (null === $owner || ! in_array($caller['type'], array('core', 'unknown'), true)) {
			return $caller;
		}

		return $owner;
	}

	private function boundedOptionName(string $optionName): string
	{
		$optionName = trim($optionName);

		return function_exists('mb_substr')
			? mb_substr($optionName, 0, 191, 'UTF-8')
			: substr($optionName, 0, 191);
	}
}
