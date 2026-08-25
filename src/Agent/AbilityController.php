<?php
/**
 * Native WordPress Abilities API registration for ConfigOps operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Agent;

use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Multisite\SiteBoundaryGuard;
use Throwable;
use WP_Error;

final readonly class AbilityController
{
	private const CATEGORY = 'configops-operations';

	public function __construct(
		private AgentService $service,
		private SiteBoundaryGuard $siteBoundary
	) {
	}

	public function register(): void
	{
		add_action('wp_abilities_api_categories_init', array($this, 'registerCategory'));
		add_action('wp_abilities_api_init', array($this, 'registerAbilities'));
	}

	public function registerCategory(): void
	{
		if (! function_exists('wp_register_ability_category')) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __('ConfigOps operations', 'configops'),
				'description' => __('Read recorded setting changes, control named captures, and validate an undo without writing.', 'configops'),
			)
		);
	}

	public function registerAbilities(): void
	{
		if (! function_exists('wp_register_ability')) {
			return;
		}

		$this->registerAbility(
			'configops/get-state',
			__('Get ConfigOps state', 'configops'),
			__('Return the current site ID, active named capture, permitted operations, and response limits. Mutation values are excluded.', 'configops'),
			fn (): array|WP_Error => $this->execute($this->service->state(...)),
			'configops_view',
			null,
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/list-captures',
			__('List ConfigOps captures', 'configops'),
			__('List up to 50 value-free capture summaries for the current WordPress site.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->captures($this->arrayInput($input))
			),
			'configops_view',
			$this->objectInput(
				array(
					'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50),
				)
			),
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/get-capture',
			__('Get a ConfigOps capture', 'configops'),
			__('Return one value-free capture summary and its mutation counts for the current site.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->capture($this->arrayInput($input))
			),
			'configops_view',
			$this->idInput('id', __('Capture ID.', 'configops')),
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/list-mutations',
			__('List ConfigOps mutations', 'configops'),
			__('Return one page of redacted mutation diffs for a capture. The response may include configuration values visible to the current user.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->mutations($this->arrayInput($input))
			),
			'configops_view',
			$this->objectInput(
				array(
					'captureId' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('Capture ID.', 'configops'),
					),
					'after' => array('type' => 'integer', 'minimum' => 0),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => AdminPayloadFactory::PAGE_SIZE,
					),
				),
				array('captureId')
			),
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/inspect-mutation',
			__('Inspect a ConfigOps mutation', 'configops'),
			__('Return one redacted mutation diff, its classification, and current undo eligibility.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->mutation($this->arrayInput($input))
			),
			'configops_view',
			$this->idInput('id', __('Mutation ID.', 'configops')),
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/plan-restore',
			__('Plan a ConfigOps restore', 'configops'),
			__('Check current state, references, site scope, and restore policy for one mutation. This ability never writes.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->planRestore($this->arrayInput($input))
			),
			'configops_plan',
			$this->idInput('mutationId', __('Mutation ID to validate for restore.', 'configops')),
			true,
			false,
			true
		);
		$this->registerAbility(
			'configops/apply-restore',
			__('Dangerously apply a ConfigOps restore', 'configops'),
			__('Undo one mutation after explicitly acknowledging that automation is replacing human confirmation. All restore safety checks remain active.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->applyRestore($this->arrayInput($input))
			),
			'configops_apply',
			$this->objectInput(
				array(
					'mutationId' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('Mutation ID to undo.', 'configops'),
					),
					'dangerouslyRunUndo' => array(
						'type'        => 'boolean',
						'enum'        => array(true),
						'description' => __('Explicit acknowledgement that automation may execute the undo without human confirmation.', 'configops'),
					),
				),
				array('mutationId', 'dangerouslyRunUndo')
			),
			false,
			true,
			false
		);
		$this->registerAbility(
			'configops/start-capture',
			__('Start a ConfigOps capture', 'configops'),
			__('Start a named capture that groups later settings saves on the current site. Requires configops_capture.', 'configops'),
			fn (mixed $input): array|WP_Error => $this->execute(
				fn (): array => $this->service->startCapture($this->arrayInput($input))
			),
			'configops_capture',
			$this->objectInput(
				array(
					'name' => array('type' => 'string', 'maxLength' => 191),
				)
			),
			false,
			false,
			false
		);
		$this->registerAbility(
			'configops/stop-capture',
			__('Stop the active ConfigOps capture', 'configops'),
			__('Stop the active named capture and finalize its recorded mutation counts.', 'configops'),
			fn (): array|WP_Error => $this->execute($this->service->stopCapture(...)),
			'configops_capture',
			null,
			false,
			false,
			true
		);
	}

	/**
	 * @param callable(): array<string, mixed> $operation Bounded operation.
	 * @return array<string, mixed>|WP_Error
	 */
	private function execute(callable $operation): array|WP_Error
	{
		try {
			return $operation();
		} catch (AgentException $error) {
			return new WP_Error(
				'configops_' . sanitize_key($error->errorCode),
				$error->getMessage(),
				array('status' => $error->status, 'retryable' => $error->retryable)
			);
		} catch (Throwable $error) {
			return new WP_Error(
				'configops_operation_failed',
				$error->getMessage(),
				array('status' => 409, 'retryable' => false)
			);
		}
	}

	/**
	 * @param callable $callback Ability callback.
	 * @param array<string, mixed>|null $inputSchema Input schema.
	 */
	private function registerAbility(
		string $name,
		string $label,
		string $description,
		callable $callback,
		string $capability,
		?array $inputSchema,
		bool $readonly,
		bool $destructive,
		bool $idempotent
	): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $this->outputSchema(),
			'execute_callback'    => $callback,
			'permission_callback' => fn (mixed $input = null): bool => $this->siteBoundary->isCurrentSite()
				&& current_user_can($capability),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => $readonly,
					'destructive' => $destructive,
					'idempotent'  => $idempotent,
				),
				'public'       => true,
				'show_in_rest' => true,
			),
		);
		if (null !== $inputSchema) {
			$args['input_schema'] = $inputSchema;
		}

		wp_register_ability($name, $args);
	}

	/**
	 * @param array<string, array<string, mixed>> $properties Object properties.
	 * @param list<string>                        $required Required property names.
	 * @return array<string, mixed>
	 */
	private function objectInput(array $properties, array $required = array()): array
	{
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		if (empty($required)) {
			$schema['default'] = array();
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function idInput(string $name, string $description): array
	{
		return $this->objectInput(
			array(
				$name => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => $description,
				),
			),
			array($name)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function outputSchema(): array
	{
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schemaVersion' => array('type' => 'string', 'enum' => array(AgentService::SCHEMA_VERSION)),
				'ok'            => array('type' => 'boolean', 'enum' => array(true)),
				'requestId'     => array('type' => 'string', 'format' => 'uuid'),
				'scope'         => array(
					'type'                 => 'object',
					'properties'           => array(
						'type'      => array('type' => 'string', 'enum' => array('site')),
						'siteId'    => array('type' => 'integer', 'minimum' => 1),
						'networkId' => array('type' => 'integer', 'minimum' => 0),
					),
					'required'             => array('type', 'siteId', 'networkId'),
					'additionalProperties' => false,
				),
				'data'          => array('type' => 'object', 'additionalProperties' => true),
			),
			'required'             => array('schemaVersion', 'ok', 'requestId', 'scope', 'data'),
			'additionalProperties' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function arrayInput(mixed $input): array
	{
		return is_array($input) ? $input : array();
	}
}
