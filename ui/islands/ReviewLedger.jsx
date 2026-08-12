import {
	hydrateReview,
	loadMoreMutations,
	restoreMutation,
	restoreSession,
	useConfigOpsState,
} from '../data/store.js';
import Hint from '../components/Hint.jsx';
import { formatValue } from '../format.js';

const fieldKindLabel = (kind, __) => {
	switch (kind) {
		case 'portable': return __('Reusable', 'configops');
		case 'environment': return __('Check per website', 'configops');
		case 'secret': return __('Secret', 'configops');
		case 'reference': return __('Website link', 'configops');
		case 'runtime': return __('Technical', 'configops');
		case 'unsupported': return __('Outside scope', 'configops');
		default: return __('Needs review', 'configops');
	}
};

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
					<strong>{mutation.adapter?.name || mutation.displayName || mutation.optionName}</strong>
					<span><code>{mutation.optionName}</code>{mutation.adapter?.componentVersion ? ` · v${mutation.adapter.componentVersion}` : ''}</span>
				</span>
				<span className={`configops-badge configops-badge--${mutation.classification}`} data-tooltip={mutation.classificationReason}>{mutation.classificationLabel}</span>
				<span id={classificationDescriptionId} className="screen-reader-text">{mutation.classificationReason}</span>
				<span className="configops-chevron" aria-hidden="true"></span>
			</summary>
			<div className="configops-mutation-body">
				{mutation.redacted && (
					<p className="configops-secret-note"><span aria-hidden="true">●</span> {__('This option contains a secret. ConfigOps excluded it before storage, so full-value undo is unavailable.', 'configops')}</p>
				)}
				<div className="configops-diff-table" role="table" aria-label={__('Nested value changes', 'configops')}>
					<div className="configops-diff-row configops-diff-head" role="row">
						<span role="columnheader">{__('Setting', 'configops')}</span>
						<span role="columnheader">{__('Before', 'configops')}</span>
						<span role="columnheader">{__('After', 'configops')}</span>
					</div>
					{mutation.diff.map((change, index) => (
						<div className="configops-diff-row" role="row" key={`${change.path || '/'}-${change.op || ''}-${index}`}>
							<div className="configops-diff-field" role="cell">
								<div>
									<strong>{change.label || change.path || '/'}</strong>
									{change.explanation && (
										<Hint label={__('About this setting', 'configops')}>{change.explanation}</Hint>
									)}
								</div>
								{change.group && <span>{change.group}{change.kind ? ` · ${fieldKindLabel(change.kind, __)}` : ''}</span>}
								{change.label && <code>{change.path || '/'}</code>}
							</div>
							<pre role="cell" data-label={__('Before', 'configops')}>{Object.hasOwn(change, 'before') ? formatValue(change.before) : '—'}</pre>
							<pre role="cell" data-label={__('After', 'configops')}>{Object.hasOwn(change, 'after') ? formatValue(change.after) : '—'}</pre>
						</div>
					))}
				</div>
				<footer className="configops-provenance">
					<div>
						<span>{__('Changed by', 'configops')}</span>
						<code>{sourceLabel}{mutation.source.line > 0 ? `:${mutation.source.line}` : ''}</code>
					</div>
					{!mutation.restorable && !mutation.redacted && (
						<Hint label={__('Why can’t this be undone?', 'configops')} align="end" trigger={__('Undo unavailable', 'configops')}>
							{__('The adapter marks this as technical, unsupported, or outside its tested version range. ConfigOps keeps the evidence but will not guess during rollback.', 'configops')}
						</Hint>
					)}
					{canRestore && mutation.restorable && (
						<span className="configops-action-hint">
							<button
								className="button button-small"
								type="button"
								disabled={busy}
								aria-describedby={restoreDescriptionId}
								onClick={() => {
									if (window.confirm(__('Undo this setting? ConfigOps will stop if it has changed again since the capture.', 'configops'))) {
										restoreMutation(mutation.id);
									}
								}}
							>
								{busy ? __('Undoing…', 'configops') : __('Undo this setting', 'configops')}
							</button>
							<span id={restoreDescriptionId} className="configops-action-tooltip" role="tooltip">
								{__('ConfigOps first checks that the setting still has the value shown here. Files and custom database tables are not part of this undo.', 'configops')}
							</span>
						</span>
					)}
				</footer>
			</div>
		</details>
	);
});

const DatabaseWriteSignal = window.wp.element.memo(function DatabaseWriteSignal({ signal }) {
	const { __, sprintf } = window.wp.i18n;
	const sourceLabel = signal.source.file || signal.source.type;
	const operationLabel = `${signal.operation} ${signal.table}`;

	return (
		<article className="configops-write-signal">
			<header>
				<span className="configops-sql-mark" aria-hidden="true">SQL</span>
				<div className="configops-write-identity">
					<code>{operationLabel}</code>
					<span>{signal.source.component || signal.source.type}</span>
				</div>
				{signal.occurrenceCount > 1 && (
					<strong aria-label={sprintf(__('%d occurrences', 'configops'), signal.occurrenceCount)}>×{signal.occurrenceCount}</strong>
				)}
				<Hint label={__('Why is there no comparison or undo?', 'configops')} align="end" trigger={__('Outside standard settings', 'configops')}>
					{__('This plugin wrote directly to the database. ConfigOps kept no query or value; understanding and undoing it safely requires a dedicated adapter.', 'configops')}
				</Hint>
			</header>
			<footer>
				<span>{__('Database write seen · No value stored · No automatic undo', 'configops')}</span>
				<code>{sourceLabel}{signal.source.line > 0 ? `:${signal.source.line}` : ''}</code>
			</footer>
		</article>
	);
});

const RequestGroup = window.wp.element.memo(function RequestGroup({ group, canRestore, pending }) {
	const { __, sprintf } = window.wp.i18n;
	const screenLabels = {
		options: __('Saved WordPress settings', 'configops'),
		'options-general': __('General settings', 'configops'),
	};
	const title = group.title || screenLabels[group.head.adminScreen] || group.head.adminScreen || group.head.requestUri || __('Background request', 'configops');
	const writeSignals = group.writeSignals || [];
	const unmanagedWriteCount = writeSignals.reduce((total, signal) => total + signal.occurrenceCount, 0);

	return (
		<section className="configops-request-group">
			<header className="configops-request-header">
				<div>
					<span className="configops-request-index">{group.index}</span>
					<div>
						<div className="configops-request-title">
							<h3>{title}</h3>
							<Hint label={__('Why are these changes grouped?', 'configops')}>
								{__('These changes happened after the same Save action, so ConfigOps keeps them together.', 'configops')}
							</Hint>
						</div>
						<p>
							<code>{group.head.method}</code> {group.head.requestUri} <span aria-hidden="true">·</span>{' '}{sprintf(__('%d recorded changes', 'configops'), group.mutations.length)}
							{unmanagedWriteCount > 0 && (
								<> <span aria-hidden="true">·</span>{' '}{sprintf(__('%d unmanaged DB writes', 'configops'), unmanagedWriteCount)}</>
							)}
						</p>
					</div>
				</div>
				<time dateTime={group.head.occurredAt}>{group.head.timeLabel}</time>
			</header>
			<div className="configops-mutation-list">
				{writeSignals.map((signal) => (
					<DatabaseWriteSignal key={signal.id} signal={signal} />
				))}
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
	const [filter, setFilter] = window.wp.element.useState('review');
	const filteredGroups = window.wp.element.useMemo(() => {
		const matches = (mutation) => (
			filter === 'all'
			|| (filter === 'review' && mutation.classification !== 'derived')
			|| (filter === 'noise' && mutation.classification === 'derived')
		);

		return review.groups
			.map((group) => ({
				...group,
				mutations: group.mutations.filter(matches),
				writeSignals: filter === 'noise' ? [] : (group.writeSignals || []),
			}))
			.filter((group) => group.mutations.length > 0 || group.writeSignals.length > 0);
	}, [filter, review.groups]);

	window.wp.element.useEffect(() => {
		if (review.deferred) {
			hydrateReview();
		}
	}, [selected?.id, review.deferred, state.ui.pending]);

	window.wp.element.useEffect(() => {
		setFilter('review');
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
							if (window.confirm(__('Undo every safe setting in this capture? ConfigOps will stop before making changes if anything changed again.', 'configops'))) {
								restoreSession(selected.id);
							}
						}}
					>
						{state.ui.pending === `restore-session-${selected.id}` ? __('Undoing…', 'configops') : __('Undo this capture', 'configops')}
					</button>
				)}
			</header>

			<div className="configops-review-toolbar">
				<div className="configops-review-filters" role="group" aria-label={__('Filter changes', 'configops')}>
					<ReviewFilter
						active={filter === 'all'}
						count={review.summary.total + review.summary.unmanagedWrites}
						description={__('Every recorded Options API mutation plus any unmanaged database write signal.', 'configops')}
						label={__('All', 'configops')}
						onSelect={() => setFilter('all')}
					/>
					<ReviewFilter
						active={filter === 'review'}
						count={review.summary.needsReview + review.summary.unmanagedWrites}
						description={__('Settings worth reading. Technical cache and maintenance values are left out.', 'configops')}
						label={__('Settings', 'configops')}
						onSelect={() => setFilter('review')}
					/>
					<ReviewFilter
						active={filter === 'noise'}
						count={review.summary.derived}
						description={__('Cache, migration, timestamp, and maintenance values generated by WordPress or a plugin.', 'configops')}
						label={__('Technical', 'configops')}
						onSelect={() => setFilter('noise')}
					/>
				</div>
				<div className="configops-review-safety">
					{review.summary.unmanagedWrites > 0 && (
						<Hint label={__('What is an unmanaged write?', 'configops')} align="end" trigger={`${review.summary.unmanagedWrites} ${__('unmanaged DB', 'configops')}`}>
							{__('A plugin wrote outside WordPress settings. ConfigOps kept no query or values, so undoing the whole capture is disabled.', 'configops')}
						</Hint>
					)}
					{review.summary.redacted > 0 && (
						<Hint label={__('What was redacted?', 'configops')} align="end" trigger={`${review.summary.redacted} ${__('redacted', 'configops')}`}>
							{__('Probable secrets were removed before persistence. Their raw values are not available to this review.', 'configops')}
						</Hint>
					)}
					{review.summary.total > 0 && (
						<Hint label={__('How safe is undo?', 'configops')} align="end" trigger={review.summary.allRestorable ? __('Undo checked', 'configops') : __('Undo has limits', 'configops')}>
							{review.summary.allRestorable
								? __('ConfigOps will undo only when the current value still matches this capture. Files and custom tables remain outside generic rollback.', 'configops')
								: __('At least one setting cannot be reconstructed safely. Open the change to see why; full-session undo stays disabled.', 'configops')}
						</Hint>
					)}
				</div>
			</div>

			{review.groups.length === 0 && (
				<section className="configops-empty-state configops-empty-state--compact">
					<h3>{__('No changes', 'configops')}</h3>
					<p>{selected.status === 'active' ? __('Change a setting while recording.', 'configops') : __('This capture contains no supported mutation or unmanaged database write signal.', 'configops')}</p>
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
