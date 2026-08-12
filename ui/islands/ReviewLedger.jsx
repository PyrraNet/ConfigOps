import {
	hydrateReview,
	loadMoreMutations,
	restoreMutation,
	restoreSession,
	useConfigOpsState,
} from '../data/store.js';
import Hint from '../components/Hint.jsx';
import { formatValue } from '../format.js';

const MutationRow = window.wp.element.memo(function MutationRow({ mutation, canRestore, busy }) {
	const { __ } = window.wp.i18n;
	const sourceLabel = mutation.source.file || mutation.source.type;
	const [open, setOpen] = window.wp.element.useState(mutation.classification !== 'derived');
	const classificationDescriptionId = `configops-classification-${mutation.id}`;
	const restoreDescriptionId = `configops-restore-${mutation.id}`;
	const operationLabels = {
		add: __('Added option', 'configops'),
		added: __('Added option', 'configops'),
		update: __('Updated option', 'configops'),
		updated: __('Updated option', 'configops'),
		delete: __('Deleted option', 'configops'),
		deleted: __('Deleted option', 'configops'),
	};
	const operationLabel = operationLabels[mutation.type] || mutation.type;

	return (
		<details
			className={`configops-mutation ${mutation.classification === 'derived' ? 'is-derived' : ''}`}
			open={open}
			onToggle={(event) => setOpen(event.currentTarget.open)}
		>
			<summary aria-describedby={classificationDescriptionId}>
				<span className={`configops-op configops-op--${mutation.type}`} title={operationLabel} aria-hidden="true">{mutation.type.slice(0, 1).toUpperCase()}</span>
				<span className="screen-reader-text">{operationLabel}</span>
				<span className="configops-option">
					<code>{mutation.optionName}</code>
					<span>{mutation.source.component || mutation.source.type}</span>
				</span>
				<span className={`configops-badge configops-badge--${mutation.classification}`} data-tooltip={mutation.classificationReason}>{mutation.classificationLabel}</span>
				<span id={classificationDescriptionId} className="screen-reader-text">{mutation.classificationReason}</span>
				<span className="configops-chevron" aria-hidden="true"></span>
			</summary>
			<div className="configops-mutation-body">
				{mutation.redacted && (
					<p className="configops-secret-note"><span aria-hidden="true">●</span> {__('Secret redacted before storage. Restore is unavailable.', 'configops')}</p>
				)}
				<div className="configops-diff-table" role="table" aria-label={__('Nested value changes', 'configops')}>
					<div className="configops-diff-row configops-diff-head" role="row">
						<span role="columnheader">{__('Path', 'configops')}</span>
						<span role="columnheader">{__('Before', 'configops')}</span>
						<span role="columnheader">{__('After', 'configops')}</span>
					</div>
					{mutation.diff.map((change, index) => (
						<div className="configops-diff-row" role="row" key={`${change.path || '/'}-${change.op || ''}-${index}`}>
							<code role="cell">{change.path || '/'}</code>
							<pre role="cell" data-label={__('Before', 'configops')}>{Object.hasOwn(change, 'before') ? formatValue(change.before) : '—'}</pre>
							<pre role="cell" data-label={__('After', 'configops')}>{Object.hasOwn(change, 'after') ? formatValue(change.after) : '—'}</pre>
						</div>
					))}
				</div>
				<footer className="configops-provenance">
					<div>
						<span>{__('Source', 'configops')}</span>
						<code>{sourceLabel}{mutation.source.line > 0 ? `:${mutation.source.line}` : ''}</code>
					</div>
					{canRestore && mutation.restorable && (
						<span className="configops-action-hint">
							<button
								className="button button-small"
								type="button"
								disabled={busy}
								aria-describedby={restoreDescriptionId}
								onClick={() => {
									if (window.confirm(__('Restore this option to its previous value? A newer value will be treated as a conflict.', 'configops'))) {
										restoreMutation(mutation.id);
									}
								}}
							>
								{busy ? __('Restoring…', 'configops') : __('Restore option', 'configops')}
							</button>
							<span id={restoreDescriptionId} className="configops-action-tooltip" role="tooltip">
								{__('Restore proceeds only if the current option still matches this captured after-state. Files and custom tables are outside this generic rollback.', 'configops')}
							</span>
						</span>
					)}
				</footer>
			</div>
		</details>
	);
});

const RequestGroup = window.wp.element.memo(function RequestGroup({ group, canRestore, pending }) {
	const { __, sprintf } = window.wp.i18n;
	const title = group.head.adminScreen || group.head.requestUri || __('Background request', 'configops');

	return (
		<section className="configops-request-group">
			<header className="configops-request-header">
				<div>
					<span className="configops-request-index">{group.index}</span>
					<div>
						<div className="configops-request-title">
							<h3>{title}</h3>
							<Hint label={__('Why are these changes grouped?', 'configops')}>
								{__('These writes occurred inside the same WordPress request and are reviewed as one causal group.', 'configops')}
							</Hint>
						</div>
						<p>
							<code>{group.head.method}</code> {group.head.requestUri} <span aria-hidden="true">·</span>{' '}{sprintf(__('%d mutations', 'configops'), group.mutations.length)}
						</p>
					</div>
				</div>
				<time dateTime={group.head.occurredAt}>{group.head.timeLabel}</time>
			</header>
			<div className="configops-mutation-list">
				{group.mutations.map((mutation) => (
					<MutationRow
						key={mutation.id}
						mutation={mutation}
						canRestore={canRestore}
						busy={pending === `restore-mutation-${mutation.id}`}
					/>
				))}
			</div>
		</section>
	);
});

const ReviewFilter = window.wp.element.memo(function ReviewFilter({ active, count, description, label, onSelect }) {
	return (
		<button
			className={active ? 'is-active' : ''}
			type="button"
			aria-pressed={active}
			data-tooltip={description}
			onClick={onSelect}
		>
			<span>{label}</span>
			<strong>{count}</strong>
			<span className="screen-reader-text">{description}</span>
		</button>
	);
});

export default function ReviewLedger() {
	const { __ } = window.wp.i18n;
	const state = useConfigOpsState();
	const selected = state.selected;
	const review = state.review;
	const canRestore = !state.active && state.capabilities.rollback;
	const [filter, setFilter] = window.wp.element.useState('all');
	const filteredGroups = window.wp.element.useMemo(() => {
		const matches = (mutation) => (
			filter === 'all'
			|| (filter === 'review' && mutation.classification !== 'derived')
			|| (filter === 'noise' && mutation.classification === 'derived')
		);

		return review.groups
			.map((group) => ({ ...group, mutations: group.mutations.filter(matches) }))
			.filter((group) => group.mutations.length > 0);
	}, [filter, review.groups]);

	window.wp.element.useEffect(() => {
		if (review.deferred) {
			hydrateReview();
		}
	}, [selected?.id, review.deferred, state.ui.pending]);

	window.wp.element.useEffect(() => {
		setFilter('all');
	}, [selected?.id]);

	if (review.deferred) {
		return (
			<div className="configops-island-placeholder configops-island-placeholder--review" aria-label={__('Loading capture review', 'configops')}>
				<span></span><span></span><span></span>
			</div>
		);
	}

	if (!selected) {
		return (
			<section className="configops-empty-state">
				<h2>{__('No capture selected', 'configops')}</h2>
				<p>{__('Record changes or choose an existing capture.', 'configops')}</p>
			</section>
		);
	}

	return (
		<>
			<header className="configops-review-header">
				<div>
					<div className="configops-capture-reference">
						<span>{__('Capture', 'configops')} <code>#{selected.id}</code></span>
						<span className={selected.status === 'active' ? 'is-live' : 'is-recorded'}>
							{selected.status === 'active' ? __('Recording', 'configops') : __('Recorded', 'configops')}
						</span>
					</div>
					<div className="configops-review-title">
						<h2>{selected.name}</h2>
					</div>
					<p>{selected.actorName}<span aria-hidden="true"> · </span><time dateTime={selected.startedAt}>{selected.startedDisplay}</time></p>
				</div>
				{canRestore && review.summary.total > 0 && (
					<button
						className="button"
						type="button"
						disabled={!review.summary.allRestorable || Boolean(state.ui.pending)}
						onClick={() => {
							if (window.confirm(__('Restore the baseline for every option in this session? ConfigOps will stop if it detects a newer value.', 'configops'))) {
								restoreSession(selected.id);
							}
						}}
					>
						{state.ui.pending === `restore-session-${selected.id}` ? __('Restoring…', 'configops') : __('Restore session', 'configops')}
					</button>
				)}
			</header>

			<div className="configops-review-toolbar">
				<div className="configops-review-filters" role="group" aria-label={__('Filter changes', 'configops')}>
					<ReviewFilter
						active={filter === 'all'}
						count={review.summary.total}
						description={__('Every recorded mutation in this capture.', 'configops')}
						label={__('Changes', 'configops')}
						onSelect={() => setFilter('all')}
					/>
					<ReviewFilter
						active={filter === 'review'}
						count={review.summary.needsReview}
						description={__('Unknown, secret-bearing, or otherwise non-derived changes that need a decision.', 'configops')}
						label={__('Review', 'configops')}
						onSelect={() => setFilter('review')}
					/>
					<ReviewFilter
						active={filter === 'noise'}
						count={review.summary.derived}
						description={__('Likely cache, transient, or runtime side effects. Verify before excluding them.', 'configops')}
						label={__('Noise', 'configops')}
						onSelect={() => setFilter('noise')}
					/>
				</div>
				<div className="configops-review-safety">
					{review.summary.redacted > 0 && (
						<Hint label={__('What was redacted?', 'configops')} align="end" trigger={`${review.summary.redacted} ${__('redacted', 'configops')}`}>
							{__('Probable secrets were removed before persistence. Their raw values are not available to this review.', 'configops')}
						</Hint>
					)}
					{review.summary.total > 0 && (
						<Hint label={__('What does limited rollback mean?', 'configops')} align="end" trigger={__('Rollback limited', 'configops')}>
							{__('Options API values and autoload intent can be restored with conflict checks. Side effects in files or custom tables may remain.', 'configops')}
						</Hint>
					)}
				</div>
			</div>

			{review.groups.length === 0 && (
				<section className="configops-empty-state configops-empty-state--compact">
					<h3>{__('No changes', 'configops')}</h3>
					<p>{selected.status === 'active' ? __('Change a setting while recording.', 'configops') : __('This capture contains no Options API mutation.', 'configops')}</p>
				</section>
			)}

			{review.groups.length > 0 && filteredGroups.length === 0 && (
				<section className="configops-empty-state configops-empty-state--compact">
					<h3>{__('Nothing in this filter', 'configops')}</h3>
					<p>{__('Choose another change filter to continue the review.', 'configops')}</p>
				</section>
			)}

			<div id="configops-change-list" aria-live="polite">
				{filteredGroups.map((group) => (
					<RequestGroup key={group.requestId} group={group} canRestore={canRestore} pending={state.ui.pending} />
				))}
			</div>

			{review.pageInfo.hasNext && (
				<div className="configops-load-more">
					<button className="button" type="button" disabled={Boolean(state.ui.pending)} onClick={loadMoreMutations}>
						{state.ui.pending === 'load-more' ? __('Loading…', 'configops') : __('Load more changes', 'configops')}
					</button>
				</div>
			)}
		</>
	);
}
