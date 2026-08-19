<?php
/**
 * Request-pinned table namespace and row scope for ConfigOps evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use ConfigOps\Multisite\EvidenceScope;
use ConfigOps\Multisite\SiteScope;
use wpdb;

final readonly class StorageContext
{
	private EvidenceScope $evidenceScope;
	private string $tablePrefix;

	public function __construct(
		private wpdb $database,
		?EvidenceScope $evidenceScope = null
	) {
		$this->evidenceScope = $evidenceScope ?? SiteScope::current();
		$this->tablePrefix = (string) ($database->base_prefix ?: $database->prefix);
	}

	public function evidenceScope(): EvidenceScope
	{
		return $this->evidenceScope;
	}

	public function table(string $suffix): string
	{
		return $this->tablePrefix . $suffix;
	}

	public function networkId(): int
	{
		return $this->evidenceScope->networkId();
	}

	public function blogId(): int
	{
		return $this->evidenceScope->siteId();
	}

	/**
	 * @return array{network_id: int, blog_id: int}
	 */
	public function scope(): array
	{
		return array(
			'network_id' => $this->networkId(),
			'blog_id'    => $this->blogId(),
		);
	}

	/**
	 * Scope values always win over caller-provided data.
	 *
	 * @param array<string, mixed> $data Unscoped row data.
	 * @return array<string, mixed>
	 */
	public function row(array $data): array
	{
		unset($data['legacy_id']);

		return array_merge($data, $this->scope());
	}

	/**
	 * @param array<string, mixed> $data Legacy row data.
	 * @return array<string, mixed>
	 */
	public function legacyRow(array $data, int $legacyId): array
	{
		$data['legacy_id'] = $legacyId;

		return array_merge($data, $this->scope());
	}

	/**
	 * @param array<string, mixed> $where Unscoped lookup constraints.
	 * @return array<string, mixed>
	 */
	public function where(array $where): array
	{
		return array_merge($where, $this->scope());
	}

	/**
	 * @param list<string> $formats Existing wpdb value formats.
	 * @return list<string>
	 */
	public function rowFormats(array $formats): array
	{
		return array_merge($formats, array('%d', '%d'));
	}

	/**
	 * @param list<string> $formats Existing wpdb constraint formats.
	 * @return list<string>
	 */
	public function whereFormats(array $formats): array
	{
		return array_merge($formats, array('%d', '%d'));
	}

	public function clause(string $alias = ''): string
	{
		$prefix = '' === $alias ? '' : $alias . '.';

		return sprintf(
			'%snetwork_id = %d AND %sblog_id = %d',
			$prefix,
			$this->networkId(),
			$prefix,
			$this->blogId()
		);
	}

	public function prepare(string $query, mixed ...$args): string
	{
		return (string) $this->database->prepare($query, ...$args);
	}
}
