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

const MutationRow = window.wp.element.memo(function MutationRow({ mutation, canRestore, busy, filter }) {
	const { __ } = window.wp.i18n;
	const sourceLabel = mutation.source.file || mutation.source.type;
	const sourceOwner = mutation.adapter?.name || mutation.source.component || __('WordPress', 'configops');
	const [open, setOpen] = window.wp.element.useState(filter !== 'noise');
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
	const visibleCount = mutation.diff.length;
	const visibleLabel = filter === 'noise'
		? (visibleCount === 1 ? __('1 technical change', 'configops') : `${visibleCount} ${__('technical changes', 'configops')}`)
		: (visibleCount === 1 ? __('1 setting', 'configops') : `${visibleCount} ${__('settings', 'configops')}`);
	const patchRestore = mutation.restoreMode === 'patch';
	const undoSucceeded = mutation.lastRestore?.status === 'succeeded';
	const undoUncertain = ['running', 'compensation_failed'].includes(mutation.lastRestore?.status);
	const undoLabel = patchRestore
		? (!mutation.redacted
			? __('Undo this change', 'configops')
			: mutation.changeCounts.safeUndo === 1
			? __('Undo 1 safe setting', 'configops')
			: `${__('Undo', 'configops')} ${mutation.changeCounts.safeUndo} ${__('safe settings', 'configops')}`)
		: __('Undo this setting', 'configops');

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
				<span className={`configops-badge configops-badge--${filter === 'noise' ? 'derived' : mutation.classification}`} data-tooltip={mutation.classificationReason}>{visibleLabel}</span>
				<span id={classificationDescriptionId} className="screen-reader-text">{mutation.classificationReason}</span>
				<span className="configops-chevron" aria-hidden="true"></span>
			</summary>
			<div className="configops-mutation-body">
				{mutation.redacted && (
					<p className="configops-secret-note"><span aria-hidden="true">●</span>{' '}
						{patchRestore
							? __('A secret changed and was removed before storage. ConfigOps can undo the other supported settings without reading or replacing that secret.', 'configops')
							: __('A secret changed and was removed before storage. ConfigOps cannot reconstruct it for undo.', 'configops')}
					</p>
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
						<span>{__('Changed through', 'configops')}</span>
						<strong>{sourceOwner}</strong>
						<code>{sourceLabel}{mutation.source.line > 0 ? `:${mutation.source.line}` : ''}</code>
					</div>
					{undoSucceeded && (
						<span className="configops-restore-state is-succeeded">
							<strong>{__('Undone', 'configops')}</strong>
							<span>{mutation.lastRestore.actorName} · {mutation.lastRestore.finishedAtLabel}</span>
						</span>
					)}
					{undoUncertain && (
						<Hint label={__('Previous undo needs inspection', 'configops')} align="end" trigger={__('Inspect undo', 'configops')}>
							{__('A previous undo and its compensation did not both complete. Inspect the current plugin setting before attempting another change.', 'configops')}
						</Hint>
					)}
					{!mutation.restorable && !mutation.redacted && filter !== 'noise' && (
						<Hint label={__('Why can’t this be undone?', 'configops')} align="end" trigger={__('Undo unavailable', 'configops')}>
							{__('The adapter marks this as technical, unsupported, or outside its tested version range. ConfigOps keeps the evidence but will not guess during rollback.', 'configops')}
						</Hint>
					)}
					{canRestore && mutation.restorable && !undoSucceeded && !undoUncertain && filter !== 'noise' && (
						<span className="configops-action-hint">
							<button
								className="button button-small"
								type="button"
								disabled={busy}
								aria-describedby={restoreDescriptionId}
								onClick={() => {
									const question = patchRestore
										? __('Undo only the supported, non-secret settings shown here? ConfigOps will preserve protected and technical values and stop if a visible setting changed again.', 'configops')
										: __('Undo this setting? ConfigOps will stop if it has changed again since the capture.', 'configops');
									if (window.confirm(question)) {
										restoreMutation(mutation.id);
									}
								}}
							>
								{busy ? __('Undoing…', 'configops') : undoLabel}
							</button>
							<span id={restoreDescriptionId} className="configops-action-tooltip" role="tooltip">
								{patchRestore
									? __('Only adapter-backed fields are reversed. Existing secrets, plugin housekeeping, files, and custom tables stay untouched.', 'configops')
									: __('ConfigOps first checks that the setting still has the value shown here. Files and custom database tables are not part of this undo.', 'configops')}
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

const RequestGroup = window.wp.element.memo(function RequestGroup({ group, canRestore, pending, filter }) {
	const { __, sprintf } = window.wp.i18n;
	const screenLabels = {
		options: __('Saved WordPress settings', 'configops'),
		'options-general': __('General settings', 'configops'),
	};
	const title = group.title || screenLabels[group.head.adminScreen] || group.head.adminScreen || group.head.requestUri || __('Background request', 'configops');
	const writeSignals = group.writeSignals || [];
	const unmanagedWriteCount = writeSignals.reduce((total, signal) => total + signal.occurrenceCount, 0);
	const visibleChangeCount = group.mutations.reduce((total, mutation) => total + mutation.diff.length, 0);

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
							<code>{group.head.method}</code> {group.head.requestUri} <span aria-hidden="true">·</span>{' '}{sprintf(__('%d visible changes', 'configops'), visibleChangeCount)}
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
						filter={filter}
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
	const selectedStatus = selected?.status === 'active'
		? { className: 'is-live', label: __('Recording', 'configops') }
		: ['interrupted', 'stopping'].includes(selected?.status)
			? { className: 'is-incomplete', label: __('Interrupted', 'configops') }
			: { className: 'is-recorded', label: __('Recorded', 'configops') };
	const sessionUndo = review.summary.lastSessionRestore;
	const sessionUndoSucceeded = sessionUndo?.status === 'succeeded';
	const sessionUndoUncertain = ['running', 'compensation_failed'].includes(sessionUndo?.status);
	const canRestore = !state.active && state.capabilities.rollback && !sessionUndoSucceeded && !sessionUndoUncertain;
	const [filter, setFilter] = window.wp.element.useState('review');
	const filteredGroups = window.wp.element.useMemo(() => {
		const selectChanges = (mutation) => mutation.diff.filter((change) => {
			const technical = mutation.classification === 'derived' || change.kind === 'runtime';
			return filter === 'all' || (filter === 'review' && !technical) || (filter === 'noise' && technical);
		});

		return review.groups
			.map((group) => {
				const mutations = group.mutations
					.map((mutation) => ({ ...mutation, diff: selectChanges(mutation) }))
					.filter((mutation) => mutation.diff.length > 0);

				return {
					...group,
					mutations,
					writeSignals: filter === 'noise' ? [] : (group.writeSignals || []),
				};
			})
			.filter((group) => group.mutations.length > 0 || group.writeSignals.length > 0)
			.map((group, index) => ({ ...group, index: String(index + 1).padStart(2, '0') }));
	}, [filter, review.groups]);

	window.wp.element.useEffect(() => {
		if (review.deferred) {
			hydrateReview();
		}
	}, [selected?.id, review.deferred, state.ui.pending]);

	window.wp.element.useEffect(() => {
		setFilter('review');
	}, [selected?.id]);

	window.wp.element.useEffect(() => {
		const visibleMutations = filteredGroups.reduce((total, group) => total + group.mutations.length, 0);
		if (
			filter === 'review'
			&& !review.deferred
			&& visibleMutations === 0
			&& review.pageInfo.hasNext
			&& !state.ui.pending
		) {
			loadMoreMutations();
		}
	}, [filter, filteredGroups, review.deferred, review.pageInfo.hasNext, state.ui.pending]);

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
						<span className={selectedStatus.className}>
							{selectedStatus.label}
						</span>
					</div>
					<div className="configops-review-title">
						<h2>{selected.name}</h2>
					</div>
					<p>{selected.actorName}<span aria-hidden="true"> · </span><time dateTime={selected.startedAt}>{selected.startedDisplay}</time></p>
				</div>
				{sessionUndoSucceeded && (
					<span className="configops-restore-state configops-restore-state--session is-succeeded">
						<strong>{__('Capture undone', 'configops')}</strong>
						<span>{sessionUndo.actorName} · {sessionUndo.finishedAtLabel}</span>
					</span>
				)}
				{sessionUndoUncertain && (
					<span className="configops-restore-state configops-restore-state--session is-uncertain">
						<strong>{__('Undo needs inspection', 'configops')}</strong>
						<span>{__('Check the current settings before continuing.', 'configops')}</span>
					</span>
				)}
				{canRestore && review.summary.total > 0 && review.summary.allRestorable && !sessionUndoSucceeded && !sessionUndoUncertain && (
					<button
						className="button"
						type="button"
						disabled={Boolean(state.ui.pending)}
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

			{review.summary.captureErrors > 0 && (
				<section className="configops-integrity-warning" role="alert" aria-labelledby="configops-integrity-title">
					<span className="configops-integrity-mark" aria-hidden="true">!</span>
					<div>
						<h3 id="configops-integrity-title">{__('Capture incomplete', 'configops')}</h3>
						<p>
							{__('WordPress saved the setting, but ConfigOps could not record every piece of evidence. Review the visible changes carefully; whole-capture undo is disabled.', 'configops')}
						</p>
					</div>
					<strong>{review.summary.captureErrors}</strong>
					<Hint label={__('What can I do?', 'configops')} align="end">
						{__('You can still inspect the evidence and undo supported settings individually. Start a new capture and repeat the save before turning these changes into a release.', 'configops')}
					</Hint>
				</section>
			)}

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
					{review.summary.individuallyUndone > 0 && (
						<Hint label={__('Why is capture undo unavailable?', 'configops')} align="end" trigger={`${review.summary.individuallyUndone} ${__('already undone', 'configops')}`}>
							{__('At least one setting was already undone individually. The original whole-capture target no longer exists as one safe operation.', 'configops')}
						</Hint>
					)}
					{review.summary.captureErrors > 0 && (
						<Hint label={__('Why is this capture incomplete?', 'configops')} align="end" trigger={`${review.summary.captureErrors} ${__('missed', 'configops')}`}>
							{__('At least one observation failed after WordPress processed a settings change. ConfigOps kept the host save running, marked the evidence incomplete, and disabled whole-capture undo.', 'configops')}
						</Hint>
					)}
					{review.summary.unmanagedWrites > 0 && (
						<Hint label={__('What is an unmanaged write?', 'configops')} align="end" trigger={`${review.summary.unmanagedWrites} ${__('unmanaged DB', 'configops')}`}>
							{__('A plugin wrote outside WordPress settings. ConfigOps kept no query or values, so undoing the whole capture is disabled.', 'configops')}
						</Hint>
					)}
					{review.summary.redacted > 0 && (
						<Hint label={__('What was redacted?', 'configops')} align="end" trigger={`${review.summary.redacted} ${__('redacted', 'configops')}`}>
							{__('Only secrets that actually changed are counted here. Their raw values were removed before ConfigOps stored the capture.', 'configops')}
						</Hint>
					)}
					{review.summary.total > 0 && (
						<Hint label={review.summary.allRestorable ? __('How safe is undo?', 'configops') : __('Why can’t I undo the whole capture?', 'configops')} align="end" trigger={review.summary.allRestorable ? __('Undo checked', 'configops') : __('Capture undo limited', 'configops')}>
							{review.summary.allRestorable
								? __('ConfigOps will undo only when the current value still matches this capture. Files and custom tables remain outside generic rollback.', 'configops')
								: review.summary.captureErrors > 0
									? __('The recording is incomplete, so ConfigOps cannot prove a whole-capture undo is safe. Supported visible changes can still be undone individually below.', 'configops')
									: __('At least one recorded change cannot be reconstructed safely, so whole-capture undo stays off. Supported changes can still be undone individually below.', 'configops')}
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
					<RequestGroup key={`${group.requestId}-${filter}`} group={group} canRestore={canRestore} pending={state.ui.pending} filter={filter} />
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
