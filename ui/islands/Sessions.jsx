import { selectSession, useConfigOpsState } from '../data/store.js';

export default function Sessions() {
	const { __, sprintf } = window.wp.i18n;
	const state = useConfigOpsState();
	const [query, setQuery] = window.wp.element.useState('');
	const normalizedQuery = query.trim().toLocaleLowerCase();
	const visibleSessions = normalizedQuery
		? state.sessions.filter((session) => (
			session.name.toLocaleLowerCase().includes(normalizedQuery)
			|| String(session.id).includes(normalizedQuery)
		))
		: state.sessions;
	const selected = state.selected;

	return (
		<>
		{state.sessions.length > 0 && (
			<div className="configops-session-picker">
				<label htmlFor="configops-session-select">{__('Selected capture', 'configops')}</label>
				<select
					id="configops-session-select"
					value={selected?.id || ''}
					disabled={Boolean(state.ui.pending)}
					onChange={(event) => selectSession(Number(event.target.value))}
				>
					{state.sessions.map((session) => (
						<option value={session.id} key={session.id}>{`#${session.id} · ${session.name}`}</option>
					))}
				</select>
				{selected && (
					<p>
						{selected.reviewChangeCount === 1 ? __('1 setting', 'configops') : sprintf(__('%d settings', 'configops'), selected.reviewChangeCount)}
						{selected.technicalChangeCount > 0 && ` · ${sprintf(__('%d technical', 'configops'), selected.technicalChangeCount)}`}
						{` · ${sprintf(__('%s ago', 'configops'), selected.startedAtLabel)}`}
					</p>
				)}
			</div>
		)}
			<div className="configops-section-heading">
				<h2>{__('Capture history', 'configops')}</h2>
				<span aria-label={sprintf(__('%d captures', 'configops'), state.sessions.length)}>{state.sessions.length}</span>
			</div>
			{state.sessions.length > 5 && (
				<div className="configops-session-search">
					<label className="screen-reader-text" htmlFor="configops-session-search">{__('Find a capture', 'configops')}</label>
					<input
						id="configops-session-search"
						type="search"
						placeholder={__('Find a capture', 'configops')}
						value={query}
						onChange={(event) => setQuery(event.target.value)}
					/>
				</div>
			)}
			{state.sessions.length === 0 ? (
				<p className="configops-empty-copy">{__('Your captures will appear here.', 'configops')}</p>
			) : visibleSessions.length === 0 ? (
				<p className="configops-empty-copy">{__('No captures match this search.', 'configops')}</p>
			) : (
				<ol className="configops-session-list">
					{visibleSessions.map((session) => {
						const isSelected = selected?.id === session.id;
						return (
							<li key={session.id}>
								<a
									className={isSelected ? 'is-selected' : ''}
									href={`?page=configops&session=${session.id}`}
									aria-current={isSelected ? 'page' : undefined}
									onClick={(event) => {
										event.preventDefault();
										selectSession(session.id);
									}}
								>
									<span className="configops-session-head">
										<span className="configops-session-name">{session.name}</span>
										<time dateTime={session.startedAt}>{sprintf(__('%s ago', 'configops'), session.startedAtLabel)}</time>
									</span>
									<span className="configops-session-meta">
										<span>
											{session.reviewChangeCount === 1 ? __('1 setting', 'configops') : sprintf(__('%d settings', 'configops'), session.reviewChangeCount)}
											{session.technicalChangeCount > 0 && <span>{sprintf(__(' · %d technical', 'configops'), session.technicalChangeCount)}</span>}
											{session.writeSignalCount > 0 && <em>{sprintf(__(' · %d outside API', 'configops'), session.writeSignalCount)}</em>}
											{session.captureErrorCount > 0 && <em>{sprintf(__(' · %d missed', 'configops'), session.captureErrorCount)}</em>}
										</span>
										<code>#{session.id}</code>
									</span>
								</a>
							</li>
						);
					})}
				</ol>
			)}
		</>
	);
}
