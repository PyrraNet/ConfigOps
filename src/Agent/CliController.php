<?php
/**
 * Machine-readable JSON WP-CLI transport for ConfigOps abilities.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Agent;

use ConfigOps\Admin\AdminPayloadFactory;
use WP_Error;

final class CliController
{
	public function register(): void
	{
		if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
			return;
		}

		\WP_CLI::add_command(
			'configops state',
			fn (array $args, array $assocArgs): null => $this->invoke('configops/get-state'),
			array('shortdesc' => 'Return current ConfigOps state as JSON.')
		);
		\WP_CLI::add_command(
			'configops captures list',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/list-captures',
				array('limit' => $this->integerArg($assocArgs, 'limit', 20))
			),
			array(
				'shortdesc' => 'List ConfigOps captures as JSON.',
				'synopsis'  => array($this->optionalAssoc('limit')),
			)
		);
		\WP_CLI::add_command(
			'configops capture get',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/get-capture',
				array('id' => $this->integerArg($assocArgs, 'id'))
			),
			array(
				'shortdesc' => 'Read one ConfigOps capture as JSON.',
				'synopsis'  => array($this->requiredAssoc('id')),
			)
		);
		\WP_CLI::add_command(
			'configops mutations list',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/list-mutations',
				array(
					'captureId' => $this->integerArg($assocArgs, 'capture'),
					'after'     => $this->integerArg($assocArgs, 'after', 0),
					'limit'     => $this->integerArg($assocArgs, 'limit', AdminPayloadFactory::PAGE_SIZE),
				)
			),
			array(
				'shortdesc' => 'List one page of ConfigOps mutation evidence as JSON.',
				'synopsis'  => array(
					$this->requiredAssoc('capture'),
					$this->optionalAssoc('after'),
					$this->optionalAssoc('limit'),
				),
			)
		);
		\WP_CLI::add_command(
			'configops mutation inspect',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/inspect-mutation',
				array('id' => $this->integerArg($assocArgs, 'id'))
			),
			array(
				'shortdesc' => 'Inspect one ConfigOps mutation as JSON.',
				'synopsis'  => array($this->requiredAssoc('id')),
			)
		);
		\WP_CLI::add_command(
			'configops restore plan',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/plan-restore',
				array('mutationId' => $this->integerArg($assocArgs, 'mutation'))
			),
			array(
				'shortdesc' => 'Validate a mutation restore without writing and return JSON.',
				'synopsis'  => array($this->requiredAssoc('mutation')),
			)
		);
		\WP_CLI::add_command(
			'configops restore apply',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/apply-restore',
				array(
					'mutationId'        => $this->integerArg($assocArgs, 'mutation'),
					'dangerouslyRunUndo' => true === ($assocArgs['dangerously-run-undo'] ?? false),
				)
			),
			array(
				'shortdesc' => 'Dangerously undo one mutation after all ConfigOps safety checks pass.',
				'synopsis'  => array(
					$this->requiredAssoc('mutation'),
					$this->requiredFlag('dangerously-run-undo'),
				),
			)
		);
		\WP_CLI::add_command(
			'configops capture start',
			fn (array $args, array $assocArgs): null => $this->invoke(
				'configops/start-capture',
				array('name' => (string) ($assocArgs['name'] ?? ''))
			),
			array(
				'shortdesc' => 'Start a named ConfigOps capture and return JSON.',
				'synopsis'  => array($this->optionalAssoc('name')),
			)
		);
		\WP_CLI::add_command(
			'configops capture stop',
			fn (array $args, array $assocArgs): null => $this->invoke('configops/stop-capture'),
			array('shortdesc' => 'Stop the active ConfigOps capture and return JSON.')
		);
	}

	/**
	 * @param array<string, mixed>|null $input Ability input.
	 */
	private function invoke(string $abilityName, ?array $input = null): null
	{
		$ability = function_exists('wp_get_ability') ? wp_get_ability($abilityName) : null;
		if (! $ability) {
			$this->error('ability_unavailable', 'The requested ConfigOps ability is unavailable.');
		}

		$result = null === $input ? $ability->execute() : $ability->execute($input);
		if ($result instanceof WP_Error) {
			$data = $result->get_error_data();
			$this->error(
				$result->get_error_code() ?: 'operation_failed',
				$result->get_error_message(),
				is_array($data) && true === ($data['retryable'] ?? false)
			);
		}

		$encoded = wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($encoded)) {
			$this->error('encoding_failed', 'ConfigOps could not encode the ability result.');
		}
		\WP_CLI::line($encoded);

		return null;
	}

	private function error(string $code, string $message, bool $retryable = false): never
	{
		$encoded = wp_json_encode(
			array(
				'schemaVersion' => AgentService::SCHEMA_VERSION,
				'ok'            => false,
				'error'         => array(
					'code'      => sanitize_key($code),
					'message'   => $message,
					'retryable' => $retryable,
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		\WP_CLI::error(is_string($encoded) ? $encoded : '{"schemaVersion":"2","ok":false}');

		throw new \RuntimeException('WP-CLI did not terminate after a ConfigOps command error.');
	}

	/**
	 * @param array<string, mixed> $assocArgs Parsed arguments.
	 */
	private function integerArg(array $assocArgs, string $name, int $default = 0): int
	{
		return isset($assocArgs[$name]) ? (int) $assocArgs[$name] : $default;
	}

	/**
	 * @return array<string, string|bool>
	 */
	private function requiredAssoc(string $name): array
	{
		return array('type' => 'assoc', 'name' => $name, 'optional' => false);
	}

	/**
	 * @return array<string, string|bool>
	 */
	private function optionalAssoc(string $name): array
	{
		return array('type' => 'assoc', 'name' => $name, 'optional' => true);
	}

	/**
	 * @return array<string, string|bool>
	 */
	private function requiredFlag(string $name): array
	{
		return array('type' => 'flag', 'name' => $name, 'optional' => false);
	}
}
