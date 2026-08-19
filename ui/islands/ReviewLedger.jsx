import {
	hydrateReview,
	loadMoreMutations,
	restoreMutation,
	restoreSession,
	useConfigOpsState,
} from '../data/store.js';
import Hint from '../components/Hint.jsx';
import { fileSizeParts, formatValue } from '../format.js';

const fieldKindLabel = (kind, referenceType, __) => {
	switch (kind) {
		case 'portable': return __('Reusable', 'configops');
		case 'environment': return __('Check per website', 'configops');
		case 'secret': return __('Secret', 'configops');
		case 'reference': return referenceType === 'media'
			? __('Media', 'configops')
			: referenceType === 'content'
				? __('Content', 'configops')
				: referenceType === 'user'
					? __('User', 'configops')
					: __('Website link', 'configops');
		case 'runtime': return __('Technical', 'configops');
		case 'unsupported': return __('Outside scope', 'configops');
		default: return __('Needs review', 'configops');
	}
};

const formatFileSize = (bytes) => {
	const size = fileSizeParts(bytes);
	if (!size) return '';

	const { __, sprintf } = window.wp.i18n;
	const value = new Intl.NumberFormat(document.documentElement.lang || undefined, {
		maximumFractionDigits: 1,
	}).format(size.value);
	if (size.unit === 'bytes') {
		/* translators: %s: file size in bytes. */
		return sprintf(__('%s B', 'configops'), value);
	}
	if (size.unit === 'kilobytes') {
		/* translators: %s: file size in kilobytes. */
		return sprintf(__('%s KB', 'configops'), value);
	}

	/* translators: %s: file size in megabytes. */
	return sprintf(__('%s MB', 'configops'), value);
};

const referenceState = (snapshot) => {
	const id = Number(snapshot?.id || 0);
	const status = snapshot?.current_status || snapshot?.status || (id > 0 ? 'missing' : 'unset');

	return { id, missing: status === 'missing', unset: id <= 0 || status === 'unset' };
};

const UnsetReferenceValue = ({ dataLabel }) => (
	<div className="configops-reference-value is-unset" data-label={dataLabel}>
		<span>{window.wp.i18n.__('Not set', 'configops')}</span>
	</div>
);

const ReferenceIdentity = ({ name, details = [], referenceLabel, missing }) => (
	<div className="configops-reference-identity">
		<strong>{name}</strong>
		{details.filter(Boolean).map((detail) => <span key={detail}>{detail}</span>)}
		<span className="configops-reference-id">
			{referenceLabel}
			{missing && <em>{window.wp.i18n.__('Missing', 'configops')}</em>}
		</span>
	</div>
);

const MediaReferenceValue = ({ dataLabel, snapshot }) => {
	const { __, sprintf } = window.wp.i18n;
	const { id, missing, unset } = referenceState(snapshot);
	if (unset) return <UnsetReferenceValue dataLabel={dataLabel} />;

	const attachmentLabel = sprintf(__('Attachment #%d', 'configops'), id);
	const name = snapshot.title || snapshot.filename || attachmentLabel;
	const metadata = [
		snapshot.mime,
		Number.isFinite(snapshot.width) && Number.isFinite(snapshot.height)
			? `${snapshot.width} × ${snapshot.height} px`
			: '',
		formatFileSize(snapshot.filesize),
	].filter(Boolean);

	return (
		<div className={`configops-reference-value ${missing ? 'is-missing' : ''}`} data-label={dataLabel}>
			<div className="configops-reference-mark" aria-hidden="true">
				{snapshot.preview_url
					? <img src={snapshot.preview_url} alt="" loading="lazy" decoding="async" />
					: <span>{missing ? '×' : __('File', 'configops')}</span>}
			</div>
			<ReferenceIdentity
				name={name}
				details={[snapshot.title && snapshot.filename ? snapshot.filename : '', metadata.join(' · ')]}
				referenceLabel={attachmentLabel}
				missing={missing}
			/>
		</div>
	);
};

const ContentReferenceValue = ({ dataLabel, snapshot }) => {
	const { __, sprintf } = window.wp.i18n;
	const { id, missing, unset } = referenceState(snapshot);
	if (unset) return <UnsetReferenceValue dataLabel={dataLabel} />;

	const contentLabel = sprintf(__('Content #%d', 'configops'), id);
	const name = snapshot.title || contentLabel;
	const typeLabel = snapshot.type_label || snapshot.post_type || __('Content', 'configops');
	const metadata = [typeLabel, snapshot.post_status].filter(Boolean).join(' · ');

	return (
		<div className={`configops-reference-value ${missing ? 'is-missing' : ''}`} data-label={dataLabel}>
			<div className="configops-reference-mark configops-content-mark" aria-hidden="true">
				<span>{missing ? '×' : typeLabel}</span>
			</div>
			<ReferenceIdentity name={name} details={[metadata]} referenceLabel={contentLabel} missing={missing} />
		</div>
	);
};

const UserReferenceValue = ({ dataLabel, snapshot }) => {
	const { __, sprintf } = window.wp.i18n;
	const { id, missing, unset } = referenceState(snapshot);
	if (unset) return <UnsetReferenceValue dataLabel={dataLabel} />;

	const userLabel = sprintf(__('User #%d', 'configops'), id);

	return (
		<div className={`configops-reference-value ${missing ? 'is-missing' : ''}`} data-label={dataLabel}>
			<div className="configops-reference-mark" aria-hidden="true"><span>{missing ? '×' : __('User', 'configops')}</span></div>
			<ReferenceIdentity name={snapshot.display_name || userLabel} referenceLabel={userLabel} missing={missing} />
		</div>
	);
};

const DiffValue = ({ change, side, label }) => {
	const { __ } = window.wp.i18n;
	const reference = change[`${side}_reference`];
	if (change.reference_type === 'media' && reference) {
		return <MediaReferenceValue dataLabel={label} snapshot={reference} />;
	}
	if (change.reference_type === 'content' && reference) {
		return <ContentReferenceValue dataLabel={label} snapshot={reference} />;
	}
	if (change.reference_type === 'user' && reference) {
		return <UserReferenceValue dataLabel={label} snapshot={reference} />;
	}

	const hasValue = Object.hasOwn(change, side);
	const value = hasValue ? change[side] : undefined;
	const empty = hasValue && (value === null || value === '');
	const labels = {
		empty: __('Empty', 'configops'),
		booleanTrue: __('On (true)', 'configops'),
		booleanFalse: __('Off (false)', 'configops'),
	};

	return (
		<pre className={empty ? 'is-empty' : ''} data-label={label}>
			{hasValue ? formatValue(value, labels) : '—'}
		</pre>
	);
};

const hasMissingRestoreReference = (changes) => changes.some((change) => (
	['remove', 'replace'].includes(change.op)
	&& Number(change.before_reference?.id || 0) > 0
	&& change.before_reference?.current_status === 'missing'
));

const MutationRow = window.wp.element.memo(function MutationRow({ mutation, canRestore, busy, filter, scopeType }) {
	const { __ } = window.wp.i18n;
	const sourceLabel = mutation.source.file || mutation.source.type;
	const sourceOwner = mutation.adapter?.name || mutation.source.component || __('WordPress', 'configops');
	const [open, setOpen] = window.wp.element.useState(filter !== 'noise');
	const restoreDescriptionId = `configops-restore-${mutation.id}`;
	const operationLabels = {
		add: __('Added', 'configops'),
		update: __('Updated', 'configops'),
		delete: __('Deleted', 'configops'),
	};
	const operationLabel = operationLabels[mutation.type] || mutation.type;
	const visibleCount = mutation.diff.length;
	const visibleLabel = filter === 'noise'
		? (visibleCount === 1 ? __('1 technical change', 'configops') : `${visibleCount} ${__('technical changes', 'configops')}`)
		: (visibleCount === 1 ? __('1 setting', 'configops') : `${visibleCount} ${__('settings', 'configops')}`);
	const patchRestore = mutation.restoreMode === 'patch';
	const observedFields = [...new Set(
		mutation.diff
			.map((change) => change.intent?.field_name)
			.filter(Boolean),
	)];
	const undoSucceeded = mutation.lastRestore?.status === 'succeeded';
	const undoUncertain = ['running', 'compensation_failed'].includes(mutation.lastRestore?.status);
	const missingRestoreReference = hasMissingRestoreReference(mutation.diff);
	const showReviewActions = filter !== 'noise';
	const undoUnavailableExplanation = !showReviewActions
		? ''
		: scopeType === 'network' && mutation.type === 'delete'
			? __('WordPress reports a network deletion only after the previous value is gone. ConfigOps keeps the evidence but cannot reconstruct that value for undo.', 'configops')
			: mutation.undoUnavailableReason
				? mutation.undoUnavailableReason
			: missingRestoreReference
			? __('The earlier referenced item no longer exists on this website. ConfigOps will not restore a broken local reference.', 'configops')
			: !mutation.restorable && !mutation.redacted
				? __('The adapter marks this as technical, unsupported, or outside its tested version range. ConfigOps keeps the evidence but will not guess during rollback.', 'configops')
				: '';
	const canUndo = canRestore
		&& mutation.restorable
		&& !missingRestoreReference
		&& !undoSucceeded
		&& !undoUncertain
		&& showReviewActions;
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
			<summary>
				<span className={`configops-mutation-kind configops-mutation-kind--${mutation.type}`}>{operationLabel}</span>
				<span className="configops-option">
					<strong>{mutation.adapter?.name || mutation.displayName || mutation.optionName}</strong>
					<span>{sourceOwner}</span>
				</span>
				<span className={`configops-badge configops-badge--${filter === 'noise' ? 'derived' : mutation.classification}`}>{visibleLabel}</span>
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
				<div className="configops-diff-table" role="table" aria-label={__('Setting changes', 'configops')}>
					{mutation.diff.map((change, index) => (
						<div className="configops-diff-row" role="row" key={`${change.path || '/'}-${change.op || ''}-${index}`}>
							<div className="configops-diff-field" role="rowheader">
								<span className="configops-field-context">{change.group || __('Setting', 'configops')}</span>
								<div>
									<strong>{change.label || change.path || '/'}</strong>
								</div>
								{change.kind && <span className="configops-field-kind">{fieldKindLabel(change.kind, change.reference_type, __)}</span>}
								{change.intent && (
									<span className="configops-field-intent">
										{change.intent.confidence === 'high'
											? __('Observed field', 'configops')
											: __('Likely observed field', 'configops')}
									</span>
								)}
							</div>
							<div className="configops-diff-value is-before" role="cell">
								<span className="configops-value-label">{__('Before', 'configops')}</span>
								<DiffValue change={change} side="before" label={__('Before', 'configops')} />
							</div>
							<span className="configops-diff-direction" aria-hidden="true">→</span>
							<div className="configops-diff-value is-after" role="cell">
								<span className="configops-value-label">{__('Now', 'configops')}</span>
								<DiffValue change={change} side="after" label={__('Now', 'configops')} />
							</div>
							{change.explanation && (
								<details className="configops-field-evidence" role="cell">
									<summary>{__('About this field', 'configops')}</summary>
									<div>
										<p>{change.explanation}</p>
									</div>
								</details>
							)}
						</div>
					))}
				</div>
				<footer className="configops-mutation-footer">
					<details className="configops-technical-evidence">
						<summary>{__('Technical evidence', 'configops')}</summary>
						<dl>
							<div><dt>{__('Option', 'configops')}</dt><dd><code>{mutation.optionName}</code></dd></div>
							<div><dt>{__('Changed through', 'configops')}</dt><dd>{sourceOwner}</dd></div>
							<div><dt>{__('Source', 'configops')}</dt><dd><code>{sourceLabel}{mutation.source.line > 0 ? `:${mutation.source.line}` : ''}</code></dd></div>
							{mutation.adapter?.componentVersion && <div><dt>{__('Version', 'configops')}</dt><dd><code>{mutation.adapter.componentVersion}</code></dd></div>}
							{observedFields.length > 0 && (
								<div><dt>{__('Observed form fields', 'configops')}</dt><dd className="configops-evidence-paths">{observedFields.map((field) => <code key={field}>{field}</code>)}</dd></div>
							)}
							<div><dt>{__('Fields', 'configops')}</dt><dd className="configops-evidence-paths">{mutation.diff.map((change, index) => <code key={`${change.path || '/'}-${index}`}>{change.path || '/'}</code>)}</dd></div>
							<div><dt>{__('Why it is here', 'configops')}</dt><dd>{mutation.classificationReason}</dd></div>
						</dl>
					</details>
					<div className="configops-mutation-action">
					{undoSucceeded && (
						<span className="configops-restore-state is-succeeded">
							<strong>{__('Undone', 'configops')}</strong>
							<span>{mutation.lastRestore.actorName} · {mutation.lastRestore.finishedAtLabel}</span>
						</span>
					)}
					{undoUncertain && (
						<span className="configops-undo-unavailable">
							<strong>{__('Undo needs inspection', 'configops')}</strong>
							<span>{__('Check the current plugin setting before continuing.', 'configops')}</span>
						</span>
					)}
					{undoUnavailableExplanation && (
						<span className="configops-undo-unavailable"><strong>{__('Undo unavailable', 'configops')}</strong><span>{undoUnavailableExplanation}</span></span>
					)}
					{canUndo && (
						<span className="configops-undo-ready">
							<span id={restoreDescriptionId}>{__('Current value is checked first.', 'configops')}</span>
							<button
								className="button button-small configops-undo-button"
								type="button"
								disabled={busy}
								aria-describedby={restoreDescriptionId}
								onClick={() => {
									const question = scopeType === 'network'
										? __('Undo this network setting? ConfigOps will stop if its current network value changed after the capture.', 'configops')
										: patchRestore
										? __('Undo only the supported, non-secret settings shown here? ConfigOps will preserve protected and technical values and stop if a visible setting changed again.', 'configops')
										: __('Undo this setting? ConfigOps will stop if it has changed again since the capture.', 'configops');
									if (window.confirm(question)) {
										restoreMutation(mutation.id);
									}
								}}
							>
								{busy ? __('Undoing…', 'configops') : undoLabel}
							</button>
						</span>
					)}
					</div>
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
				<span className="configops-sql-mark" aria-hidden="true">!</span>
				<div className="configops-write-identity">
					<strong>{__('Database change outside standard settings', 'configops')}</strong>
					<code>{operationLabel}</code>
				</div>
				{signal.occurrenceCount > 1 && (
					<strong aria-label={sprintf(__('%d occurrences', 'configops'), signal.occurrenceCount)}>×{signal.occurrenceCount}</strong>
				)}
			</header>
			<p>{__('No value was stored, so automatic undo is unavailable.', 'configops')}</p>
			<details>
				<summary>{__('Technical evidence', 'configops')}</summary>
				<div><span>{signal.source.component || signal.source.type}</span><code>{sourceLabel}{signal.source.line > 0 ? `:${signal.source.line}` : ''}</code></div>
			</details>
		</article>
	);
});

const RequestGroup = window.wp.element.memo(function RequestGroup({ group, canRestore, pending, filter, scopeType }) {
	const { __, sprintf } = window.wp.i18n;
	const screenLabels = {
		options: __('Saved WordPress settings', 'configops'),
		'options-general': __('General settings', 'configops'),
	};
	const title = group.title || screenLabels[group.head.adminScreen] || group.head.adminScreen || group.head.requestUri || __('Background request', 'configops');
	const writeSignals = group.writeSignals || [];
	const unmanagedWriteCount = writeSignals.reduce((total, signal) => total + signal.occurrenceCount, 0);
	const visibleChangeCount = group.mutations.reduce((total, mutation) => total + mutation.diff.length, 0);
	const intent = group.intent;
	const intentLabels = Array.isArray(intent?.labels) ? intent.labels.filter(Boolean) : [];
	const intentStatement = intentLabels.length === 1
		? sprintf(__('Changed “%s”', 'configops'), intentLabels[0])
		: intentLabels.length > 1
			? sprintf(__('Changed fields: %s', 'configops'), intentLabels.join(' · '))
			: intent?.action || __('Changed admin field', 'configops');
	const intentEvidence = intent?.confidence === 'high'
		? sprintf(__('Matched %1$d of %2$d saved settings directly', 'configops'), intent.matchedFields, visibleChangeCount)
		: sprintf(__('Matched %1$d of %2$d saved settings by option scope', 'configops'), intent?.matchedFields || 0, visibleChangeCount);

	return (
		<section className="configops-request-group">
			<header className="configops-request-header">
				<div>
					<div>
						<span className="configops-request-index">{sprintf(__('Save action %s', 'configops'), group.index)}</span>
						<h3>{title}</h3>
						<p>
							{visibleChangeCount === 1 ? __('1 visible change', 'configops') : sprintf(__('%d visible changes', 'configops'), visibleChangeCount)}
							{unmanagedWriteCount > 0 && (
								<> <span aria-hidden="true">·</span>{' '}{sprintf(__('%d outside API', 'configops'), unmanagedWriteCount)}</>
							)}
							{' '}<span aria-hidden="true">·</span>{' '}<time dateTime={group.head.occurredAt}>{group.head.timeLabel}</time>
						</p>
						{intent && (
							<div className="configops-intent-summary">
								<span className="configops-intent-mark" aria-hidden="true">↳</span>
								<div>
									<span>{__('Observed intent', 'configops')}</span>
									<strong>{intentStatement}</strong>
									<em>{intentEvidence}</em>
								</div>
							</div>
						)}
					</div>
				</div>
				<details className="configops-request-evidence">
					<summary>{__('Request details', 'configops')}</summary>
					<div><code>{group.head.method}</code><code>{group.head.requestUri}</code></div>
				</details>
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
						scopeType={scopeType}
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
			aria-controls="configops-change-list"
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
			: { className: 'is-recorded', label: selected?.mode === 'automatic' ? __('Observed', 'configops') : __('Recorded', 'configops') };
	const sessionUndo = review.summary.lastSessionRestore;
	const sessionUndoSucceeded = sessionUndo?.status === 'succeeded';
	const sessionUndoUncertain = ['running', 'compensation_failed'].includes(sessionUndo?.status);
	const canRestore = !state.active && state.capabilities.rollback && !sessionUndoSucceeded && !sessionUndoUncertain;
	const visibleMissingRestoreReference = review.groups.some((group) => (
		group.mutations.some((mutation) => hasMissingRestoreReference(mutation.diff))
	));
	const canRestoreSession = canRestore
		&& state.capabilities.sessionRollback !== false
		&& review.summary.total > 0
		&& review.summary.allRestorable;
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
					intent: filter === 'noise' ? null : group.intent,
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
				<p>{__('Save a setting or choose an existing change.', 'configops')}</p>
			</section>
		);
	}

	return (
		<>
			<header className="configops-review-header">
				<div className="configops-review-heading">
					<div className="configops-capture-reference">
						<span className={selectedStatus.className}>
							{selectedStatus.label}
						</span>
						{state.scope?.type === 'network' && (
							<span className="is-network">{__('Network-wide', 'configops')}</span>
						)}
						<span>{selected.mode === 'automatic' ? __('Automatic change', 'configops') : __('Change session', 'configops')} <code>#{selected.id}</code></span>
					</div>
					<h2>{selected.name}</h2>
					<p>{selected.actorName}<span aria-hidden="true"> · </span><time dateTime={selected.startedAt}>{selected.startedDisplay}</time></p>
				</div>
				<div className="configops-capture-action">
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
					{canRestoreSession && !visibleMissingRestoreReference && (
						<>
							<span className="configops-capture-undo-ready"><strong>{__('Capture undo ready', 'configops')}</strong><span>{__('Current values are checked first.', 'configops')}</span></span>
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
								{state.ui.pending === `restore-session-${selected.id}` ? __('Undoing…', 'configops') : __('Undo capture', 'configops')}
							</button>
						</>
					)}
					{canRestoreSession && visibleMissingRestoreReference && (
						<span className="configops-undo-unavailable"><strong>{__('Capture undo unavailable', 'configops')}</strong><span>{__('A previous referenced item is missing. Review settings individually.', 'configops')}</span></span>
					)}
				</div>
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
				<div className="configops-review-toolbar-main">
					<span className="configops-toolbar-label">{__('Show', 'configops')}</span>
					<div className="configops-review-filters" role="group" aria-label={__('Filter changes', 'configops')}>
					<ReviewFilter
						active={filter === 'review'}
						count={review.summary.needsReview + review.summary.unmanagedWrites}
						description={__('Settings worth reading. Technical cache and maintenance values are left out.', 'configops')}
						label={__('Review', 'configops')}
						onSelect={() => setFilter('review')}
					/>
					<ReviewFilter
						active={filter === 'noise'}
						count={review.summary.derived}
						description={__('Cache, migration, timestamp, and maintenance values generated by WordPress or a plugin.', 'configops')}
						label={__('Technical', 'configops')}
						onSelect={() => setFilter('noise')}
					/>
					<ReviewFilter
						active={filter === 'all'}
						count={review.summary.total + review.summary.unmanagedWrites}
						description={__('Every recorded Options API mutation plus any unmanaged database write signal.', 'configops')}
						label={__('All', 'configops')}
						onSelect={() => setFilter('all')}
					/>
					</div>
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
					{review.summary.total > 0 && !review.summary.allRestorable && review.summary.captureErrors === 0 && (
						<Hint label={__('Why can’t I undo the whole capture?', 'configops')} align="end" trigger={__('Capture undo limited', 'configops')}>
							{__('At least one recorded change cannot be reconstructed safely. Supported changes can still be undone individually.', 'configops')}
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
					<RequestGroup
						key={`${group.requestId}-${filter}`}
						group={group}
						canRestore={canRestore}
						pending={state.ui.pending}
						filter={filter}
						scopeType={state.scope?.type || 'site'}
					/>
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
